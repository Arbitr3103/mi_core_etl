<?php

namespace Replenishment;

use PDO;
use Exception;
use DateTime;
use DateInterval;

/**
 * OptimizedSalesAnalyzer Class
 * 
 * Performance-optimized version of SalesAnalyzer with caching and optimized queries.
 * Uses database indexes and query caching for improved performance.
 */
class OptimizedSalesAnalyzer extends SalesAnalyzer
{
    private QueryCache $queryCache;
    private array $batchCache;
    
    public function __construct(PDO $pdo, array $config = [])
    {
        parent::__construct($pdo, $config);
        
        $this->queryCache = new QueryCache($pdo, [
            'cache_ttl' => $config['cache_ttl'] ?? 1800, // 30 minutes default
            'enable_memory_cache' => $config['enable_memory_cache'] ?? true,
            'enable_db_cache' => $config['enable_db_cache'] ?? true,
            'debug' => $config['debug'] ?? false
        ]);
        
        $this->batchCache = [];
    }
    
    /**
     * Calculate ADS with caching and optimized queries
     * 
     * @param int $productId Product ID
     * @param int|null $days Number of days to analyze
     * @return float ADS value
     * @throws Exception If calculation fails
     */
    public function calculateADS(int $productId, ?int $days = null): float
    {
        $days = $days ?? $this->config['analysis_days'];
        
        // Try to get from cache first
        $cachedADS = $this->queryCache->getCachedADS($productId, $days);
        if ($cachedADS !== null) {
            $this->log("Using cached ADS for product $productId: $cachedADS");
            return $cachedADS;
        }
        
        // Use optimized query with stored procedure
        $ads = $this->queryCache->getOrSet(
            "ads_calculation_{$productId}_{$days}",
            function() use ($productId, $days) {
                return $this->calculateADSOptimized($productId, $days);
            },
            1800 // 30 minutes cache
        );
        
        // Cache the result specifically for ADS lookups
        $this->queryCache->cacheADS($productId, $days, $ads);
        
        return $ads;
    }
    
    /**
     * Optimized ADS calculation using stored procedure and indexes
     * 
     * @param int $productId Product ID
     * @param int $days Analysis days
     * @return float Calculated ADS
     */
    private function calculateADSOptimized(int $productId, int $days): float
    {
        $this->log("Calculating optimized ADS for product $productId over $days days");
        
        try {
            // Use stored procedure for optimized data retrieval
            $stmt = $this->pdo->prepare("CALL GetADSCalculationData(?, ?)");
            $stmt->execute([$productId, $days]);
            
            $salesData = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            if (empty($salesData)) {
                $this->logDataQuality("No sales data available for ADS calculation", $productId, 'warning');
                return 0.0;
            }
            
            // Calculate ADS from filtered data
            $totalSales = 0;
            $validDaysCount = 0;
            
            foreach ($salesData as $sale) {
                if ($sale['had_stock']) {
                    $quantity = max(0, (int)$sale['quantity_sold']);
                    $totalSales += $quantity;
                    $validDaysCount++;
                }
            }
            
            $ads = $validDaysCount > 0 ? $totalSales / $validDaysCount : 0.0;
            
            $this->log("Optimized ADS calculation: Product $productId, Total sales = $totalSales, Valid days = $validDaysCount, ADS = $ads");
            
            return round($ads, 2);
            
        } catch (Exception $e) {
            $this->log("Error in optimized ADS calculation: " . $e->getMessage());
            // Fallback to parent method
            return parent::calculateADS($productId, $days);
        }
    }
    
    /**
     * Get product information with caching
     * 
     * @param int $productId Product ID
     * @return array|null Product information
     */
    public function getProductInfo(int $productId): ?array
    {
        // Try cache first
        $cachedInfo = $this->queryCache->getCachedProductInfo($productId);
        if ($cachedInfo !== null) {
            return $cachedInfo;
        }
        
        // Use optimized query with covering index
        $productInfo = $this->queryCache->getOrSet(
            "product_info_{$productId}",
            function() use ($productId) {
                return $this->getProductInfoOptimized($productId);
            },
            3600 // 1 hour cache
        );
        
        if ($productInfo) {
            $this->queryCache->cacheProductInfo($productId, $productInfo);
        }
        
        return $productInfo;
    }
    
    /**
     * Optimized product information query using covering index
     * 
     * @param int $productId Product ID
     * @return array|null Product information
     */
    private function getProductInfoOptimized(int $productId): ?array
    {
        // Use covering index idx_dim_products_info_covering
        $sql = "
            SELECT id, name, sku_ozon as sku, is_active
            FROM dim_products 
            WHERE id = ?
        ";
        
        try {
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute([$productId]);
            
            $product = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$product) {
                $this->log("Product $productId not found");
                return null;
            }
            
            return $product;
            
        } catch (Exception $e) {
            $this->log("Error getting optimized product info: " . $e->getMessage());
            throw new Exception("Failed to get product info: " . $e->getMessage());
        }
    }
    
    /**
     * Get active products with caching and optimized query
     * 
     * @return array Array of active products
     */
    public function getActiveProducts(): array
    {
        return $this->queryCache->getOrSet(
            'active_products_list',
            function() {
                return $this->getActiveProductsOptimized();
            },
            7200 // 2 hours cache
        );
    }
    
    /**
     * Optimized active products query using covering index
     * 
     * @return array Array of active products
     */
    private function getActiveProductsOptimized(): array
    {
        // Use covering index idx_dim_products_active_lookup
        $sql = "
            SELECT id, name, sku_ozon as sku
            FROM dim_products 
            WHERE is_active = 1
            ORDER BY name
        ";
        
        try {
            $stmt = $this->pdo->query($sql);
            $products = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            $this->log("Found " . count($products) . " active products (optimized query)");
            
            return $products;
            
        } catch (Exception $e) {
            $this->log("Error getting optimized active products: " . $e->getMessage());
            throw new Exception("Failed to get active products: " . $e->getMessage());
        }
    }
    
    /**
     * Batch ADS calculation with optimized queries and caching
     * 
     * @param array $productIds Array of product IDs
     * @param int|null $days Number of days to analyze
     * @return array ADS results for each product
     */
    public function calculateBatchADS(array $productIds, ?int $days = null): array
    {
        $days = $days ?? $this->config['analysis_days'];
        $results = [];
        $uncachedIds = [];
        
        $this->log("Starting optimized batch ADS calculation for " . count($productIds) . " products");
        
        // First, check cache for all products
        foreach ($productIds as $productId) {
            $cachedADS = $this->queryCache->getCachedADS($productId, $days);
            $cachedInfo = $this->queryCache->getCachedProductInfo($productId);
            
            if ($cachedADS !== null && $cachedInfo !== null) {
                $results[] = [
                    'product_id' => $productId,
                    'product_name' => $cachedInfo['name'],
                    'sku' => $cachedInfo['sku'],
                    'ads' => $cachedADS,
                    'is_valid' => $cachedADS >= $this->config['min_ads_threshold'],
                    'calculated_at' => date('Y-m-d H:i:s'),
                    'from_cache' => true
                ];
            } else {
                $uncachedIds[] = $productId;
            }
        }
        
        $this->log("Found " . count($results) . " cached results, calculating " . count($uncachedIds) . " fresh");
        
        // Calculate ADS for uncached products in batches
        if (!empty($uncachedIds)) {
            $batchSize = 50; // Process in smaller batches for better performance
            $batches = array_chunk($uncachedIds, $batchSize);
            
            foreach ($batches as $batchIndex => $batch) {
                $this->log("Processing batch " . ($batchIndex + 1) . "/" . count($batches));
                
                $batchResults = $this->processBatchOptimized($batch, $days);
                $results = array_merge($results, $batchResults);
            }
        }
        
        $this->log("Optimized batch ADS calculation completed. " . count($results) . " results generated");
        
        return $results;
    }
    
    /**
     * Process a batch of products with optimized queries
     * 
     * @param array $productIds Product IDs to process
     * @param int $days Analysis days
     * @return array Batch results
     */
    private function processBatchOptimized(array $productIds, int $days): array
    {
        $results = [];
        
        // Get all product info in one query
        $productInfos = $this->getBatchProductInfo($productIds);
        
        // Calculate ADS for each product
        foreach ($productIds as $productId) {
            try {
                $productInfo = $productInfos[$productId] ?? null;
                if (!$productInfo) {
                    continue;
                }
                
                $ads = $this->calculateADSOptimized($productId, $days);
                
                // Cache the results
                $this->queryCache->cacheADS($productId, $days, $ads);
                $this->queryCache->cacheProductInfo($productId, $productInfo);
                
                $results[] = [
                    'product_id' => $productId,
                    'product_name' => $productInfo['name'],
                    'sku' => $productInfo['sku'],
                    'ads' => $ads,
                    'is_valid' => $ads >= $this->config['min_ads_threshold'],
                    'calculated_at' => date('Y-m-d H:i:s'),
                    'from_cache' => false
                ];
                
            } catch (Exception $e) {
                $this->log("Error calculating ADS for product $productId: " . $e->getMessage());
                
                $results[] = [
                    'product_id' => $productId,
                    'product_name' => 'Unknown',
                    'sku' => null,
                    'ads' => 0.0,
                    'is_valid' => false,
                    'error' => $e->getMessage(),
                    'calculated_at' => date('Y-m-d H:i:s'),
                    'from_cache' => false
                ];
            }
        }
        
        return $results;
    }
    
    /**
     * Get product information for multiple products in one query
     * 
     * @param array $productIds Product IDs
     * @return array Product information indexed by product ID
     */
    private function getBatchProductInfo(array $productIds): array
    {
        if (empty($productIds)) {
            return [];
        }
        
        $placeholders = str_repeat('?,', count($productIds) - 1) . '?';
        
        // Use covering index for batch product info
        $sql = "
            SELECT id, name, sku_ozon as sku, is_active
            FROM dim_products 
            WHERE id IN ($placeholders)
        ";
        
        try {
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($productIds);
            
            $products = [];
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $products[$row['id']] = $row;
            }
            
            return $products;
            
        } catch (Exception $e) {
            $this->log("Error getting batch product info: " . $e->getMessage());
            return [];
        }
    }
    
    /**
     * Get current stock with caching and optimized query
     * 
     * @param int $productId Product ID
     * @return int Current stock
     */
    public function getCurrentStock(int $productId): int
    {
        // Try cache first
        $cachedStock = $this->queryCache->getCachedCurrentStock($productId);
        if ($cachedStock !== null) {
            return $cachedStock;
        }
        
        // Use optimized query with stored procedure
        $stock = $this->queryCache->getOrSet(
            "current_stock_{$productId}",
            function() use ($productId) {
                return $this->getCurrentStockOptimized($productId);
            },
            900 // 15 minutes cache (stock changes frequently)
        );
        
        $this->queryCache->cacheCurrentStock($productId, $stock);
        
        return $stock;
    }
    
    /**
     * Optimized current stock query using stored procedure
     * 
     * @param int $productId Product ID
     * @return int Current stock
     */
    private function getCurrentStockOptimized(int $productId): int
    {
        try {
            $stmt = $this->pdo->prepare("CALL GetCurrentStock(?)");
            $stmt->execute([$productId]);
            
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$result) {
                return 0;
            }
            
            // Use total current stock across all warehouses
            return max(0, (int)$result['total_current_stock']);
            
        } catch (Exception $e) {
            $this->log("Error getting optimized current stock: " . $e->getMessage());
            
            // Fallback to direct query
            $sql = "
                SELECT COALESCE(SUM(current_stock), 0) as total_stock
                FROM inventory_data 
                WHERE product_id = ? AND current_stock > 0
            ";
            
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute([$productId]);
            
            return (int)$stmt->fetchColumn();
        }
    }
    
    /**
     * Invalidate cache for specific product
     * 
     * @param int $productId Product ID
     */
    public function invalidateProductCache(int $productId): void
    {
        $this->queryCache->invalidateByPattern("*_{$productId}");
        $this->queryCache->invalidateByPattern("*_{$productId}_*");
    }
    
    /**
     * Invalidate all ADS cache entries
     */
    public function invalidateADSCache(): void
    {
        $this->queryCache->invalidateByPattern("ads_*");
        $this->queryCache->invalidateByPattern("ads_calculation_*");
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
     * Warm up cache for active products
     * 
     * @param int|null $limit Limit number of products to warm up
     */
    public function warmupCache(?int $limit = null): void
    {
        $this->log("Starting cache warmup");
        
        $products = $this->getActiveProducts();
        
        if ($limit) {
            $products = array_slice($products, 0, $limit);
        }
        
        $productIds = array_column($products, 'id');
        
        // Warm up ADS calculations
        $this->calculateBatchADS($productIds);
        
        // Warm up current stock
        foreach ($productIds as $productId) {
            $this->getCurrentStock($productId);
        }
        
        $this->log("Cache warmup completed for " . count($productIds) . " products");
    }
}
?>