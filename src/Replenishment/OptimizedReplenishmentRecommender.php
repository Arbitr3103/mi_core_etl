<?php

namespace Replenishment;

use PDO;
use Exception;

/**
 * OptimizedReplenishmentRecommender Class
 * 
 * Performance-optimized version of ReplenishmentRecommender with caching,
 * performance monitoring, and enhanced batch processing capabilities.
 */
class OptimizedReplenishmentRecommender extends ReplenishmentRecommender
{
    private PerformanceMonitor $performanceMonitor;
    private ?RedisCache $redisCache;
    private OptimizedSalesAnalyzer $optimizedSalesAnalyzer;
    private OptimizedStockCalculator $optimizedStockCalculator;
    
    public function __construct(PDO $pdo, array $config = [])
    {
        // Initialize performance monitoring first
        $this->performanceMonitor = new PerformanceMonitor($pdo, $config);
        
        // Initialize Redis cache if available
        try {
            $this->redisCache = new RedisCache($config['redis'] ?? []);
        } catch (Exception $e) {
            $this->redisCache = null;
            $this->log("Redis cache not available: " . $e->getMessage(), 'WARN');
        }
        
        // Call parent constructor
        parent::__construct($pdo, $config);
        
        // Replace analyzers with optimized versions
        $this->optimizedSalesAnalyzer = new OptimizedSalesAnalyzer($pdo, $config);
        $this->optimizedStockCalculator = new OptimizedStockCalculator($pdo, $config);
    }
    
    /**
     * Generate recommendations with performance monitoring and caching
     * 
     * @param array|null $productIds Array of product IDs (null for all active products)
     * @return array Generated recommendations
     * @throws Exception If generation fails
     */
    public function generateRecommendations(?array $productIds = null): array
    {
        $this->performanceMonitor->startTimer('generate_recommendations', [
            'product_count' => $productIds ? count($productIds) : 'all',
            'cache_enabled' => $this->redisCache !== null
        ]);
        
        try {
            // Check cache first for recent recommendations
            if ($this->redisCache && $productIds === null) {
                $cachedRecommendations = $this->getCachedRecommendations();
                if ($cachedRecommendations !== null) {
                    $this->performanceMonitor->stopTimer('generate_recommendations', [
                        'cache_hit' => true,
                        'recommendations_count' => count($cachedRecommendations)
                    ]);
                    
                    $this->log("Using cached recommendations: " . count($cachedRecommendations) . " items");
                    return $cachedRecommendations;
                }
            }
            
            // Generate fresh recommendations using optimized process
            $recommendations = $this->generateRecommendationsOptimized($productIds);
            
            // Cache the results if full recommendation set
            if ($this->redisCache && $productIds === null && !empty($recommendations)) {
                $this->cacheRecommendations($recommendations);
            }
            
            $metrics = $this->performanceMonitor->stopTimer('generate_recommendations', [
                'cache_hit' => false,
                'recommendations_count' => count($recommendations),
                'products_processed' => count($productIds ?? [])
            ]);
            
            $this->log("Generated {$metrics['additional_data']['recommendations_count']} recommendations in {$metrics['execution_time']}s");
            
            return $recommendations;
            
        } catch (Exception $e) {
            $this->performanceMonitor->stopTimer('generate_recommendations', [
                'error' => $e->getMessage(),
                'cache_hit' => false
            ]);
            
            throw $e;
        }
    }
    
    /**
     * Optimized recommendation generation process
     * 
     * @param array|null $productIds Product IDs to process
     * @return array Generated recommendations
     */
    private function generateRecommendationsOptimized(?array $productIds): array
    {
        $this->performanceMonitor->startTimer('optimized_generation_process');
        
        try {
            // Get products to process
            if ($productIds === null) {
                $products = $this->optimizedSalesAnalyzer->getActiveProducts();
                $productIds = array_column($products, 'id');
                $this->log("Processing all " . count($productIds) . " active products");
            } else {
                $this->log("Processing " . count($productIds) . " specified products");
            }
            
            if (empty($productIds)) {
                throw new Exception("No products to process");
            }
            
            // Calculate ADS for all products in optimized batch
            $this->performanceMonitor->startTimer('batch_ads_calculation');
            $adsResults = $this->optimizedSalesAnalyzer->calculateBatchADS($productIds);
            $this->performanceMonitor->stopTimer('batch_ads_calculation', [
                'products_processed' => count($productIds),
                'ads_results_count' => count($adsResults)
            ]);
            
            // Calculate recommendations in batch
            $this->performanceMonitor->startTimer('batch_recommendation_calculation');
            $recommendations = $this->optimizedStockCalculator->calculateBatchRecommendations($adsResults);
            $this->performanceMonitor->stopTimer('batch_recommendation_calculation', [
                'recommendations_generated' => count($recommendations)
            ]);
            
            // Save recommendations to database
            $this->performanceMonitor->startTimer('save_recommendations');
            $savedCount = $this->saveRecommendations($recommendations);
            $this->performanceMonitor->stopTimer('save_recommendations', [
                'recommendations_saved' => $savedCount
            ]);
            
            $this->performanceMonitor->stopTimer('optimized_generation_process', [
                'total_recommendations' => count($recommendations),
                'saved_count' => $savedCount
            ]);
            
            return $recommendations;
            
        } catch (Exception $e) {
            $this->performanceMonitor->stopTimer('optimized_generation_process', [
                'error' => $e->getMessage()
            ]);
            
            throw $e;
        }
    }
    
    /**
     * Generate weekly report with performance monitoring
     * 
     * @return array Report data
     * @throws Exception If report generation fails
     */
    public function generateWeeklyReport(): array
    {
        $this->performanceMonitor->startTimer('generate_weekly_report');
        
        try {
            $report = parent::generateWeeklyReport();
            
            $this->performanceMonitor->stopTimer('generate_weekly_report', [
                'actionable_recommendations' => count($report['actionable_recommendations']),
                'total_recommendations' => count($report['all_recommendations'])
            ]);
            
            return $report;
            
        } catch (Exception $e) {
            $this->performanceMonitor->stopTimer('generate_weekly_report', [
                'error' => $e->getMessage()
            ]);
            
            throw $e;
        }
    }
    
    /**
     * Get recommendations with caching and performance monitoring
     * 
     * @param array $filters Filtering options
     * @return array Filtered and sorted recommendations
     */
    public function getRecommendations(array $filters = []): array
    {
        $this->performanceMonitor->startTimer('get_recommendations', [
            'filters' => $filters
        ]);
        
        try {
            // Try cache first for common filter combinations
            $cacheKey = $this->generateFilterCacheKey($filters);
            
            if ($this->redisCache && $cacheKey) {
                $cachedResults = $this->redisCache->get($cacheKey);
                if ($cachedResults !== null) {
                    $this->performanceMonitor->stopTimer('get_recommendations', [
                        'cache_hit' => true,
                        'results_count' => count($cachedResults)
                    ]);
                    
                    return $cachedResults;
                }
            }
            
            // Get recommendations using optimized query
            $recommendations = $this->getRecommendationsOptimized($filters);
            
            // Cache the results
            if ($this->redisCache && $cacheKey && !empty($recommendations)) {
                $this->redisCache->set($cacheKey, $recommendations, 1800); // 30 minutes
            }
            
            $this->performanceMonitor->stopTimer('get_recommendations', [
                'cache_hit' => false,
                'results_count' => count($recommendations)
            ]);
            
            return $recommendations;
            
        } catch (Exception $e) {
            $this->performanceMonitor->stopTimer('get_recommendations', [
                'error' => $e->getMessage()
            ]);
            
            throw $e;
        }
    }
    
    /**
     * Optimized recommendations query using stored procedure
     * 
     * @param array $filters Filtering options
     * @return array Recommendations
     */
    private function getRecommendationsOptimized(array $filters): array
    {
        // Use stored procedure for better performance
        try {
            $calculationDate = $filters['calculation_date'] ?? null;
            $limit = $filters['limit'] ?? 100;
            $offset = $filters['offset'] ?? 0;
            
            $stmt = $this->pdo->prepare("CALL GetBatchRecommendations(?, ?, ?)");
            $stmt->execute([$calculationDate, $limit, $offset]);
            
            $recommendations = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Apply additional filters if needed
            if (isset($filters['min_ads']) && $filters['min_ads'] > 0) {
                $recommendations = array_filter($recommendations, function($rec) use ($filters) {
                    return $rec['ads'] >= $filters['min_ads'];
                });
            }
            
            if (isset($filters['actionable_only']) && $filters['actionable_only']) {
                $recommendations = array_filter($recommendations, function($rec) {
                    return $rec['recommended_quantity'] > 0;
                });
            }
            
            return array_values($recommendations);
            
        } catch (Exception $e) {
            // Fallback to parent method
            $this->log("Stored procedure failed, using fallback: " . $e->getMessage());
            return parent::getRecommendations($filters);
        }
    }
    
    /**
     * Get cached recommendations
     * 
     * @return array|null Cached recommendations or null if not found
     */
    private function getCachedRecommendations(): ?array
    {
        if (!$this->redisCache) {
            return null;
        }
        
        $cacheKey = 'recommendations:latest:' . date('Y-m-d');
        return $this->redisCache->get($cacheKey);
    }
    
    /**
     * Cache recommendations
     * 
     * @param array $recommendations Recommendations to cache
     */
    private function cacheRecommendations(array $recommendations): void
    {
        if (!$this->redisCache) {
            return;
        }
        
        $cacheKey = 'recommendations:latest:' . date('Y-m-d');
        $this->redisCache->set($cacheKey, $recommendations, 7200); // 2 hours
        
        // Also cache actionable recommendations separately
        $actionableRecommendations = array_filter($recommendations, function($rec) {
            return $rec['recommended_quantity'] > 0;
        });
        
        $actionableCacheKey = 'recommendations:actionable:' . date('Y-m-d');
        $this->redisCache->set($actionableCacheKey, $actionableRecommendations, 7200);
    }
    
    /**
     * Generate cache key for filter combination
     * 
     * @param array $filters Filter array
     * @return string|null Cache key or null if not cacheable
     */
    private function generateFilterCacheKey(array $filters): ?string
    {
        // Only cache common filter combinations
        $cacheableFilters = ['calculation_date', 'actionable_only', 'sort_by', 'sort_order', 'limit'];
        
        $cacheableData = [];
        foreach ($cacheableFilters as $filter) {
            if (isset($filters[$filter])) {
                $cacheableData[$filter] = $filters[$filter];
            }
        }
        
        if (empty($cacheableData)) {
            return null;
        }
        
        return 'recommendations:filtered:' . md5(json_encode($cacheableData));
    }
    
    /**
     * Invalidate recommendation caches
     */
    public function invalidateRecommendationCache(): void
    {
        if (!$this->redisCache) {
            return;
        }
        
        $this->redisCache->deleteByPattern('recommendations:*');
        $this->log("Recommendation cache invalidated");
    }
    
    /**
     * Warm up caches for better performance
     * 
     * @param int|null $productLimit Limit number of products for warmup
     */
    public function warmupCaches(?int $productLimit = null): void
    {
        $this->performanceMonitor->startTimer('cache_warmup');
        
        try {
            $this->log("Starting cache warmup");
            
            // Warm up sales analyzer cache
            $this->optimizedSalesAnalyzer->warmupCache($productLimit);
            
            // Warm up stock calculator cache
            $this->optimizedStockCalculator->warmupConfigCache();
            
            // Generate and cache latest recommendations if not exists
            if ($this->redisCache && !$this->getCachedRecommendations()) {
                $this->log("Generating recommendations for cache warmup");
                $this->generateRecommendations();
            }
            
            $this->performanceMonitor->stopTimer('cache_warmup', [
                'product_limit' => $productLimit
            ]);
            
            $this->log("Cache warmup completed");
            
        } catch (Exception $e) {
            $this->performanceMonitor->stopTimer('cache_warmup', [
                'error' => $e->getMessage()
            ]);
            
            $this->log("Cache warmup failed: " . $e->getMessage(), 'ERROR');
        }
    }
    
    /**
     * Get performance metrics for this recommender
     * 
     * @return array Performance metrics
     */
    public function getPerformanceMetrics(): array
    {
        $stats = $this->performanceMonitor->getPerformanceStatistics('generate_recommendations', 7);
        
        $metrics = [
            'performance_stats' => $stats,
            'cache_stats' => $this->redisCache ? $this->redisCache->getStatistics() : null,
            'sales_analyzer_cache' => $this->optimizedSalesAnalyzer->getCacheStatistics(),
            'stock_calculator_cache' => $this->optimizedStockCalculator->getCacheStatistics(),
            'realtime_metrics' => $this->performanceMonitor->getRealTimeMetrics()
        ];
        
        return $metrics;
    }
    
    /**
     * Cleanup old performance data and caches
     * 
     * @param int|null $retentionDays Days to retain data
     * @return array Cleanup results
     */
    public function cleanup(?int $retentionDays = null): array
    {
        $this->performanceMonitor->startTimer('cleanup_operation');
        
        try {
            $results = [];
            
            // Cleanup performance monitoring data
            $results['performance_cleanup'] = $this->performanceMonitor->cleanup($retentionDays);
            
            // Cleanup analyzer caches
            $this->optimizedSalesAnalyzer->cleanupCache();
            $this->optimizedStockCalculator->cleanupCache();
            
            // Clear old recommendation caches
            if ($this->redisCache) {
                $deletedKeys = $this->redisCache->deleteByPattern('recommendations:*:' . date('Y-m-d', strtotime('-7 days')));
                $results['cache_cleanup'] = ['deleted_keys' => $deletedKeys];
            }
            
            $this->performanceMonitor->stopTimer('cleanup_operation', $results);
            
            return $results;
            
        } catch (Exception $e) {
            $this->performanceMonitor->stopTimer('cleanup_operation', [
                'error' => $e->getMessage()
            ]);
            
            throw $e;
        }
    }
    
    /**
     * Get comprehensive system status
     * 
     * @return array System status information
     */
    public function getSystemStatus(): array
    {
        return [
            'timestamp' => date('Y-m-d H:i:s'),
            'performance_monitoring' => true,
            'redis_cache_available' => $this->redisCache !== null && $this->redisCache->isConnected(),
            'optimized_analyzers' => true,
            'performance_metrics' => $this->getPerformanceMetrics(),
            'system_health' => [
                'memory_usage' => memory_get_usage(true),
                'memory_peak' => memory_get_peak_usage(true),
                'memory_limit' => ini_get('memory_limit')
            ]
        ];
    }
}
?>