<?php
/**
 * Enhanced Cache Service
 * 
 * Advanced caching functionality for the detailed inventory API with
 * performance optimizations, intelligent cache warming, and monitoring.
 * 
 * Requirements: 7.1, 7.2
 * Task: 4.1 Database performance optimization - caching improvements
 */

require_once __DIR__ . '/CacheService.php';

class EnhancedCacheService extends CacheService {
    
    private $pdo;
    private $hitCount = 0;
    private $missCount = 0;
    private $queryCount = 0;
    
    /**
     * Enhanced cache TTL constants (in seconds)
     */
    const TTL_INVENTORY_DATA_SHORT = 180;    // 3 minutes for frequently changing data
    const TTL_INVENTORY_DATA_MEDIUM = 600;   // 10 minutes for moderately changing data
    const TTL_INVENTORY_DATA_LONG = 1800;    // 30 minutes for stable data
    const TTL_WAREHOUSE_LIST = 7200;         // 2 hours (rarely changes)
    const TTL_SEARCH_RESULTS = 900;          // 15 minutes
    const TTL_SUMMARY_STATS = 300;           // 5 minutes
    const TTL_PERFORMANCE_STATS = 60;        // 1 minute
    
    /**
     * Cache key prefixes for different data types
     */
    const PREFIX_INVENTORY = 'inv:v2:';
    const PREFIX_WAREHOUSE = 'wh:v2:';
    const PREFIX_SEARCH = 'search:v2:';
    const PREFIX_SUMMARY = 'summary:v2:';
    const PREFIX_PERFORMANCE = 'perf:v2:';
    
    /**
     * Constructor
     * 
     * @param PDO $pdo Database connection for cache warming
     * @param string $cacheDir Directory for file-based cache
     * @param int $defaultTtl Default TTL in seconds
     */
    public function __construct($pdo = null, $cacheDir = null, $defaultTtl = 300) {
        parent::__construct($cacheDir, $defaultTtl);
        $this->pdo = $pdo;
    }
    
    /**
     * Get cached data with performance tracking
     * 
     * @param string $key Cache key
     * @return mixed|null Cached data or null if not found/expired
     */
    public function get($key) {
        $this->queryCount++;
        $data = parent::get($key);
        
        if ($data !== null) {
            $this->hitCount++;
        } else {
            $this->missCount++;
        }
        
        return $data;
    }
    
    /**
     * Get inventory data with intelligent caching
     * 
     * @param array $filters Filter parameters
     * @return mixed|null Cached data or null if not found
     */
    public function getInventoryData($filters) {
        $key = $this->getEnhancedInventoryKey($filters);
        return $this->get($key);
    }
    
    /**
     * Set inventory data with intelligent TTL selection
     * 
     * @param array $filters Filter parameters
     * @param mixed $data Data to cache
     * @return bool Success status
     */
    public function setInventoryData($filters, $data) {
        $key = $this->getEnhancedInventoryKey($filters);
        $ttl = $this->getOptimalTtl($filters);
        return $this->set($key, $data, $ttl);
    }
    
    /**
     * Generate enhanced cache key for inventory data
     * 
     * @param array $filters Filter parameters
     * @return string Cache key
     */
    private function getEnhancedInventoryKey($filters) {
        // Sort filters to ensure consistent key generation
        ksort($filters);
        
        // Create a more specific hash that considers filter complexity
        $complexity = $this->calculateFilterComplexity($filters);
        $filterHash = md5(json_encode($filters));
        
        return self::PREFIX_INVENTORY . "c{$complexity}:{$filterHash}";
    }
    
    /**
     * Calculate filter complexity to determine optimal caching strategy
     * 
     * @param array $filters Filter parameters
     * @return int Complexity score (0-10)
     */
    private function calculateFilterComplexity($filters) {
        $complexity = 0;
        
        // Base complexity
        if (!empty($filters['warehouses']) || !empty($filters['warehouse'])) {
            $complexity += 1;
        }
        
        if (!empty($filters['statuses']) || !empty($filters['status'])) {
            $complexity += 1;
        }
        
        if (!empty($filters['search'])) {
            $complexity += 3; // Search is expensive
        }
        
        if (isset($filters['min_days_of_stock']) || isset($filters['max_days_of_stock'])) {
            $complexity += 2;
        }
        
        if (isset($filters['min_urgency_score'])) {
            $complexity += 2;
        }
        
        if (!empty($filters['has_replenishment_need'])) {
            $complexity += 2;
        }
        
        if (!empty($filters['sort_by'])) {
            $complexity += 1;
        }
        
        return min(10, $complexity);
    }
    
    /**
     * Get optimal TTL based on filter complexity and data volatility
     * 
     * @param array $filters Filter parameters
     * @return int TTL in seconds
     */
    private function getOptimalTtl($filters) {
        $complexity = $this->calculateFilterComplexity($filters);
        
        // High complexity queries (expensive) get longer cache times
        if ($complexity >= 7) {
            return self::TTL_INVENTORY_DATA_LONG;
        } elseif ($complexity >= 4) {
            return self::TTL_INVENTORY_DATA_MEDIUM;
        } else {
            return self::TTL_INVENTORY_DATA_SHORT;
        }
    }
    
    /**
     * Warm up cache with frequently accessed data
     * 
     * @return array Results of cache warming operations
     */
    public function warmCache() {
        if (!$this->pdo) {
            return ['error' => 'Database connection required for cache warming'];
        }
        
        $results = [];
        
        try {
            // Warm up warehouse list
            $results['warehouses'] = $this->warmWarehouseList();
            
            // Warm up summary statistics
            $results['summary'] = $this->warmSummaryStats();
            
            // Warm up critical inventory items
            $results['critical_items'] = $this->warmCriticalItems();
            
            // Warm up high-turnover products
            $results['high_turnover'] = $this->warmHighTurnoverProducts();
            
            // Warm up common filter combinations
            $results['common_filters'] = $this->warmCommonFilters();
            
        } catch (Exception $e) {
            $results['error'] = 'Cache warming failed: ' . $e->getMessage();
        }
        
        return $results;
    }
    
    /**
     * Warm warehouse list cache
     * 
     * @return array Result
     */
    private function warmWarehouseList() {
        try {
            $stmt = $this->pdo->query("
                SELECT warehouse_name, COUNT(*) as product_count
                FROM v_detailed_inventory 
                GROUP BY warehouse_name 
                ORDER BY product_count DESC
            ");
            
            $warehouses = $stmt->fetchAll(PDO::FETCH_ASSOC);
            $key = self::PREFIX_WAREHOUSE . 'list';
            
            $success = $this->set($key, $warehouses, self::TTL_WAREHOUSE_LIST);
            
            return [
                'success' => $success,
                'count' => count($warehouses),
                'key' => $key
            ];
        } catch (Exception $e) {
            return ['error' => $e->getMessage()];
        }
    }
    
    /**
     * Warm summary statistics cache
     * 
     * @return array Result
     */
    private function warmSummaryStats() {
        try {
            $stmt = $this->pdo->query("
                SELECT 
                    COUNT(*) as total_products,
                    COUNT(CASE WHEN current_stock > 0 THEN 1 END) as products_with_stock,
                    COUNT(CASE WHEN stock_status = 'critical' THEN 1 END) as critical_products,
                    COUNT(CASE WHEN stock_status = 'low' THEN 1 END) as low_products,
                    COUNT(CASE WHEN recommended_qty > 0 THEN 1 END) as products_needing_replenishment,
                    SUM(current_stock_value) as total_stock_value,
                    AVG(days_of_stock) as avg_days_of_stock
                FROM v_detailed_inventory
            ");
            
            $stats = $stmt->fetch(PDO::FETCH_ASSOC);
            $key = self::PREFIX_SUMMARY . 'stats';
            
            $success = $this->set($key, $stats, self::TTL_SUMMARY_STATS);
            
            return [
                'success' => $success,
                'stats' => $stats,
                'key' => $key
            ];
        } catch (Exception $e) {
            return ['error' => $e->getMessage()];
        }
    }
    
    /**
     * Warm critical items cache
     * 
     * @return array Result
     */
    private function warmCriticalItems() {
        try {
            $stmt = $this->pdo->prepare("
                SELECT * FROM v_detailed_inventory 
                WHERE stock_status IN ('critical', 'low') 
                  AND daily_sales_avg > 0
                ORDER BY urgency_score DESC, days_of_stock ASC 
                LIMIT 500
            ");
            
            $stmt->execute();
            $items = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            $filters = ['statuses' => ['critical', 'low'], 'active_only' => true];
            $key = $this->getEnhancedInventoryKey($filters);
            
            $success = $this->set($key, [
                'success' => true,
                'data' => $items,
                'metadata' => [
                    'totalCount' => count($items),
                    'filteredCount' => count($items),
                    'timestamp' => date('c'),
                    'cached' => true
                ]
            ], self::TTL_INVENTORY_DATA_MEDIUM);
            
            return [
                'success' => $success,
                'count' => count($items),
                'key' => $key
            ];
        } catch (Exception $e) {
            return ['error' => $e->getMessage()];
        }
    }
    
    /**
     * Warm high-turnover products cache
     * 
     * @return array Result
     */
    private function warmHighTurnoverProducts() {
        try {
            $stmt = $this->pdo->prepare("
                SELECT * FROM v_detailed_inventory 
                WHERE daily_sales_avg > 1 
                  AND current_stock > 0
                ORDER BY daily_sales_avg DESC 
                LIMIT 500
            ");
            
            $stmt->execute();
            $items = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            $filters = ['sort_by' => 'daily_sales_avg', 'sort_order' => 'desc', 'active_only' => true];
            $key = $this->getEnhancedInventoryKey($filters);
            
            $success = $this->set($key, [
                'success' => true,
                'data' => $items,
                'metadata' => [
                    'totalCount' => count($items),
                    'filteredCount' => count($items),
                    'timestamp' => date('c'),
                    'cached' => true
                ]
            ], self::TTL_INVENTORY_DATA_MEDIUM);
            
            return [
                'success' => $success,
                'count' => count($items),
                'key' => $key
            ];
        } catch (Exception $e) {
            return ['error' => $e->getMessage()];
        }
    }
    
    /**
     * Warm common filter combinations
     * 
     * @return array Results
     */
    private function warmCommonFilters() {
        $commonFilters = [
            ['statuses' => ['critical']],
            ['statuses' => ['low']],
            ['has_replenishment_need' => true],
            ['sort_by' => 'urgency_score', 'sort_order' => 'desc'],
            ['sort_by' => 'days_of_stock', 'sort_order' => 'asc']
        ];
        
        $results = [];
        
        foreach ($commonFilters as $index => $filters) {
            try {
                $whereConditions = [];
                $params = [];
                
                if (!empty($filters['statuses'])) {
                    $placeholders = str_repeat('?,', count($filters['statuses']) - 1) . '?';
                    $whereConditions[] = "stock_status IN ($placeholders)";
                    $params = array_merge($params, $filters['statuses']);
                }
                
                if (!empty($filters['has_replenishment_need'])) {
                    $whereConditions[] = "recommended_qty > 0";
                }
                
                $whereClause = !empty($whereConditions) ? 'WHERE ' . implode(' AND ', $whereConditions) : '';
                
                $orderBy = '';
                if (!empty($filters['sort_by'])) {
                    $sortOrder = $filters['sort_order'] ?? 'asc';
                    $orderBy = "ORDER BY {$filters['sort_by']} $sortOrder";
                }
                
                $sql = "
                    SELECT * FROM v_detailed_inventory 
                    $whereClause
                    $orderBy
                    LIMIT 500
                ";
                
                $stmt = $this->pdo->prepare($sql);
                $stmt->execute($params);
                $items = $stmt->fetchAll(PDO::FETCH_ASSOC);
                
                $key = $this->getEnhancedInventoryKey($filters);
                
                $success = $this->set($key, [
                    'success' => true,
                    'data' => $items,
                    'metadata' => [
                        'totalCount' => count($items),
                        'filteredCount' => count($items),
                        'timestamp' => date('c'),
                        'cached' => true
                    ]
                ], $this->getOptimalTtl($filters));
                
                $results["filter_$index"] = [
                    'success' => $success,
                    'count' => count($items),
                    'filters' => $filters,
                    'key' => $key
                ];
                
            } catch (Exception $e) {
                $results["filter_$index"] = ['error' => $e->getMessage()];
            }
        }
        
        return $results;
    }
    
    /**
     * Get enhanced cache statistics
     * 
     * @return array Enhanced cache statistics
     */
    public function getEnhancedStats() {
        $baseStats = parent::getStats();
        
        $enhancedStats = array_merge($baseStats, [
            'performance' => [
                'hit_count' => $this->hitCount,
                'miss_count' => $this->missCount,
                'query_count' => $this->queryCount,
                'hit_ratio' => $this->queryCount > 0 ? round(($this->hitCount / $this->queryCount) * 100, 2) : 0
            ],
            'ttl_settings' => [
                'inventory_short' => self::TTL_INVENTORY_DATA_SHORT,
                'inventory_medium' => self::TTL_INVENTORY_DATA_MEDIUM,
                'inventory_long' => self::TTL_INVENTORY_DATA_LONG,
                'warehouse_list' => self::TTL_WAREHOUSE_LIST,
                'search_results' => self::TTL_SEARCH_RESULTS,
                'summary_stats' => self::TTL_SUMMARY_STATS
            ]
        ]);
        
        return $enhancedStats;
    }
    
    /**
     * Clear cache by pattern
     * 
     * @param string $pattern Cache key pattern (e.g., 'inv:v2:*')
     * @return bool Success status
     */
    public function clearByPattern($pattern) {
        if ($this->useRedis) {
            try {
                $keys = $this->redis->keys($pattern);
                if (!empty($keys)) {
                    return $this->redis->del($keys) > 0;
                }
                return true;
            } catch (Exception $e) {
                error_log("EnhancedCacheService: Redis pattern clear error: " . $e->getMessage());
                return false;
            }
        } else {
            // For file cache, we need to scan directory
            $pattern = str_replace(['*', ':'], ['.*', '_'], $pattern);
            $files = glob($this->cacheDir . '/*.cache');
            $success = true;
            
            foreach ($files as $file) {
                $filename = basename($file, '.cache');
                if (preg_match("/$pattern/", $filename)) {
                    if (!unlink($file)) {
                        $success = false;
                    }
                }
            }
            
            return $success;
        }
    }
    
    /**
     * Clear inventory cache
     * 
     * @return bool Success status
     */
    public function clearInventoryCache() {
        return $this->clearByPattern(self::PREFIX_INVENTORY . '*');
    }
    
    /**
     * Clear warehouse cache
     * 
     * @return bool Success status
     */
    public function clearWarehouseCache() {
        return $this->clearByPattern(self::PREFIX_WAREHOUSE . '*');
    }
    
    /**
     * Reset performance counters
     */
    public function resetPerformanceCounters() {
        $this->hitCount = 0;
        $this->missCount = 0;
        $this->queryCount = 0;
    }
}

?>