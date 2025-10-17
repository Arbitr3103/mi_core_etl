<?php

namespace Replenishment;

use PDO;
use Exception;

/**
 * OptimizedStockCalculator Class
 * 
 * Performance-optimized version of StockCalculator with caching and batch processing.
 */
class OptimizedStockCalculator extends StockCalculator
{
    private QueryCache $queryCache;
    private array $configCache;
    
    public function __construct(PDO $pdo, array $config = [])
    {
        parent::__construct($pdo, $config);
        
        $this->queryCache = new QueryCache($pdo, [
            'cache_ttl' => $config['cache_ttl'] ?? 3600,
            'enable_memory_cache' => $config['enable_memory_cache'] ?? true,
            'enable_db_cache' => $config['enable_db_cache'] ?? true,
            'debug' => $config['debug'] ?? false
        ]);
        
        $this->configCache = [];
    }
    
    /**
     * Get replenishment parameters with caching
     * 
     * @return array Configuration parameters
     */
    public function getReplenishmentParameters(): array
    {
        // Try memory cache first
        if (!empty($this->configCache)) {
            return $this->configCache;
        }
        
        // Try query cache
        $cachedConfig = $this->queryCache->getCachedConfig();
        if ($cachedConfig !== null) {
            $this->configCache = $cachedConfig;
            return $cachedConfig;
        }
        
        // Load from database with optimized query
        $config = $this->queryCache->getOrSet(
            'replenishment_config_parameters',
            function() {
                return $this->loadReplenishmentParametersOptimized();
            },
            7200 // 2 hours cache
        );
        
        $this->configCache = $config;
        $this->queryCache->cacheConfig($config);
        
        return $config;
    }
    
    /**
     * Load replenishment parameters with optimized query
     * 
     * @return array Configuration parameters
     */
    private function loadReplenishmentParametersOptimized(): array
    {
        // Use covering index idx_replenishment_config_active
        $sql = "
            SELECT parameter_name, parameter_value, parameter_type
            FROM replenishment_config 
            WHERE is_active = 1
        ";
        
        try {
            $stmt = $this->pdo->query($sql);
            $rawConfig = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            $config = [];
            foreach ($rawConfig as $param) {
                $value = $param['parameter_value'];
                
                // Convert value based on type
                switch ($param['parameter_type']) {
                    case 'int':
                        $value = (int)$value;
                        break;
                    case 'float':
                        $value = (float)$value;
                        break;
                    case 'boolean':
                        $value = filter_var($value, FILTER_VALIDATE_BOOLEAN);
                        break;
                    default:
                        // Keep as string
                        break;
                }
                
                $config[$param['parameter_name']] = $value;
            }
            
            // Set defaults for missing parameters
            $defaults = [
                'replenishment_days' => 14,
                'safety_days' => 7,
                'analysis_days' => 30,
                'min_ads_threshold' => 0.1
            ];
            
            foreach ($defaults as $key => $defaultValue) {
                if (!isset($config[$key])) {
                    $config[$key] = $defaultValue;
                }
            }
            
            $this->log("Loaded optimized configuration: " . json_encode($config));
            
            return $config;
            
        } catch (Exception $e) {
            $this->log("Error loading optimized configuration: " . $e->getMessage());
            
            // Return defaults on error
            return [
                'replenishment_days' => 14,
                'safety_days' => 7,
                'analysis_days' => 30,
                'min_ads_threshold' => 0.1
            ];
        }
    }
    
    /**
     * Calculate complete recommendation with caching and optimization
     * 
     * @param int $productId Product ID
     * @param float $ads Average Daily Sales
     * @return array Complete recommendation data
     */
    public function calculateCompleteRecommendation(int $productId, float $ads): array
    {
        return $this->queryCache->getOrSet(
            "complete_recommendation_{$productId}_" . md5((string)$ads),
            function() use ($productId, $ads) {
                return $this->calculateCompleteRecommendationOptimized($productId, $ads);
            },
            1800 // 30 minutes cache
        );
    }
    
    /**
     * Optimized complete recommendation calculation
     * 
     * @param int $productId Product ID
     * @param float $ads Average Daily Sales
     * @return array Complete recommendation data
     */
    private function calculateCompleteRecommendationOptimized(int $productId, float $ads): array
    {
        $config = $this->getReplenishmentParameters();
        
        // Get current stock using optimized query
        $currentStock = $this->getCurrentStockOptimized($productId);
        
        // Calculate target stock
        $totalDays = $config['replenishment_days'] + $config['safety_days'];
        $targetStock = $this->calculateTargetStock($ads, $config['replenishment_days'], $config['safety_days']);
        
        // Calculate recommendation
        $recommendedQuantity = $this->calculateReplenishmentRecommendation($targetStock, $currentStock);
        
        return [
            'product_id' => $productId,
            'ads' => $ads,
            'current_stock' => $currentStock,
            'target_stock' => (int)$targetStock,
            'recommended_quantity' => $recommendedQuantity,
            'replenishment_days' => $config['replenishment_days'],
            'safety_days' => $config['safety_days'],
            'total_days' => $totalDays,
            'calculated_at' => date('Y-m-d H:i:s')
        ];
    }
    
    /**
     * Get current stock with optimized query and caching
     * 
     * @param int $productId Product ID
     * @return int Current stock
     */
    private function getCurrentStockOptimized(int $productId): int
    {
        // Try cache first
        $cachedStock = $this->queryCache->getCachedCurrentStock($productId);
        if ($cachedStock !== null) {
            return $cachedStock;
        }
        
        // Use stored procedure if available, otherwise optimized query
        try {
            $stmt = $this->pdo->prepare("CALL GetCurrentStock(?)");
            $stmt->execute([$productId]);
            
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            $stock = $result ? max(0, (int)$result['total_current_stock']) : 0;
            
        } catch (Exception $e) {
            // Fallback to optimized direct query using covering index
            $sql = "
                SELECT COALESCE(SUM(current_stock), 0) as total_stock
                FROM inventory_data 
                WHERE product_id = ? AND current_stock > 0
            ";
            
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute([$productId]);
            $stock = (int)$stmt->fetchColumn();
        }
        
        // Cache the result
        $this->queryCache->cacheCurrentStock($productId, $stock, 900); // 15 minutes
        
        return $stock;
    }
    
    /**
     * Calculate recommendations for multiple products in batch
     * 
     * @param array $adsResults Array of ADS results from SalesAnalyzer
     * @return array Batch recommendations
     */
    public function calculateBatchRecommendations(array $adsResults): array
    {
        $this->log("Starting batch recommendation calculation for " . count($adsResults) . " products");
        
        $config = $this->getReplenishmentParameters();
        $recommendations = [];
        
        // Get current stock for all products in batch
        $productIds = array_column($adsResults, 'product_id');
        $stockData = $this->getBatchCurrentStock($productIds);
        
        foreach ($adsResults as $adsResult) {
            $productId = $adsResult['product_id'];
            $ads = $adsResult['ads'];
            
            // Skip products below ADS threshold
            if ($ads < $config['min_ads_threshold']) {
                continue;
            }
            
            $currentStock = $stockData[$productId] ?? 0;
            
            // Calculate recommendation
            $targetStock = $this->calculateTargetStock($ads, $config['replenishment_days'], $config['safety_days']);
            $recommendedQuantity = $this->calculateReplenishmentRecommendation($targetStock, $currentStock);
            
            $recommendation = [
                'product_id' => $productId,
                'product_name' => $adsResult['product_name'] ?? 'Unknown',
                'sku' => $adsResult['sku'] ?? null,
                'ads' => $ads,
                'current_stock' => $currentStock,
                'target_stock' => (int)$targetStock,
                'recommended_quantity' => $recommendedQuantity,
                'calculated_at' => date('Y-m-d H:i:s')
            ];
            
            $recommendations[] = $recommendation;
            
            // Cache individual recommendation
            $cacheKey = "complete_recommendation_{$productId}_" . md5((string)$ads);
            $this->queryCache->getOrSet($cacheKey, function() use ($recommendation) {
                return $recommendation;
            }, 1800);
        }
        
        $this->log("Batch recommendation calculation completed: " . count($recommendations) . " recommendations");
        
        return $recommendations;
    }
    
    /**
     * Get current stock for multiple products in batch
     * 
     * @param array $productIds Product IDs
     * @return array Stock data indexed by product ID
     */
    private function getBatchCurrentStock(array $productIds): array
    {
        if (empty($productIds)) {
            return [];
        }
        
        $stockData = [];
        $uncachedIds = [];
        
        // Check cache first
        foreach ($productIds as $productId) {
            $cachedStock = $this->queryCache->getCachedCurrentStock($productId);
            if ($cachedStock !== null) {
                $stockData[$productId] = $cachedStock;
            } else {
                $uncachedIds[] = $productId;
            }
        }
        
        // Get uncached stock data in batch
        if (!empty($uncachedIds)) {
            $batchStockData = $this->queryBatchCurrentStock($uncachedIds);
            
            // Cache the results and merge
            foreach ($batchStockData as $productId => $stock) {
                $this->queryCache->cacheCurrentStock($productId, $stock, 900);
                $stockData[$productId] = $stock;
            }
        }
        
        return $stockData;
    }
    
    /**
     * Query current stock for multiple products
     * 
     * @param array $productIds Product IDs
     * @return array Stock data indexed by product ID
     */
    private function queryBatchCurrentStock(array $productIds): array
    {
        $placeholders = str_repeat('?,', count($productIds) - 1) . '?';
        
        // Use covering index for batch stock query
        $sql = "
            SELECT 
                product_id,
                COALESCE(SUM(current_stock), 0) as total_stock
            FROM inventory_data 
            WHERE product_id IN ($placeholders)
                AND current_stock > 0
            GROUP BY product_id
        ";
        
        try {
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($productIds);
            
            $stockData = [];
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $stockData[$row['product_id']] = (int)$row['total_stock'];
            }
            
            // Ensure all requested products have an entry (0 if no stock found)
            foreach ($productIds as $productId) {
                if (!isset($stockData[$productId])) {
                    $stockData[$productId] = 0;
                }
            }
            
            return $stockData;
            
        } catch (Exception $e) {
            $this->log("Error getting batch current stock: " . $e->getMessage());
            
            // Return zeros for all products on error
            return array_fill_keys($productIds, 0);
        }
    }
    
    /**
     * Update configuration parameter and invalidate cache
     * 
     * @param string $parameterName Parameter name
     * @param mixed $value Parameter value
     * @param string $type Parameter type
     * @throws Exception If update fails
     */
    public function updateParameter(string $parameterName, mixed $value, string $type = 'string'): void
    {
        parent::updateParameter($parameterName, $value, $type);
        
        // Invalidate configuration cache
        $this->configCache = [];
        $this->queryCache->invalidateByPattern('replenishment_config*');
        $this->queryCache->invalidateByPattern('complete_recommendation*');
    }
    
    /**
     * Invalidate all stock-related cache entries
     */
    public function invalidateStockCache(): void
    {
        $this->queryCache->invalidateByPattern('current_stock_*');
        $this->queryCache->invalidateByPattern('complete_recommendation_*');
    }
    
    /**
     * Get cache statistics
     * 
     * @return array Cache statistics
     */
    public function getCacheStatistics(): array
    {
        return $this->queryCache->getStatistics();
    }
    
    /**
     * Cleanup expired cache entries
     */
    public function cleanupCache(): void
    {
        $this->queryCache->cleanup();
    }
    
    /**
     * Warm up configuration cache
     */
    public function warmupConfigCache(): void
    {
        $this->log("Warming up configuration cache");
        $this->getReplenishmentParameters();
    }
    
    /**
     * Get performance metrics for stock calculations
     * 
     * @return array Performance metrics
     */
    public function getPerformanceMetrics(): array
    {
        $cacheStats = $this->getCacheStatistics();
        
        return [
            'cache_statistics' => $cacheStats,
            'config_cached' => !empty($this->configCache),
            'memory_usage' => [
                'current' => memory_get_usage(true),
                'peak' => memory_get_peak_usage(true)
            ],
            'timestamp' => date('Y-m-d H:i:s')
        ];
    }
}
?>