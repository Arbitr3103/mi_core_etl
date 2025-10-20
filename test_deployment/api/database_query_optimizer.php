<?php
/**
 * Database Query Optimizer for Active Product Filtering
 * Task 7.1: Optimize database queries for active product filtering
 * 
 * This class provides query optimization, index management, and performance monitoring
 * for the inventory analytics system with active product filtering.
 */

class DatabaseQueryOptimizer {
    private $pdo;
    private $logger;
    private $performance_metrics = [];
    
    public function __construct($pdo, $logger = null) {
        $this->pdo = $pdo;
        $this->logger = $logger;
    }
    
    /**
     * Create optimized indexes for active product filtering
     */
    public function createOptimizedIndexes() {
        $indexes = [
            // Primary indexes for active product filtering
            [
                'table' => 'dim_products',
                'name' => 'idx_is_active',
                'columns' => 'is_active',
                'description' => 'Index for active product filtering'
            ],
            [
                'table' => 'dim_products', 
                'name' => 'idx_active_updated',
                'columns' => 'is_active, updated_at',
                'description' => 'Composite index for active products with update time'
            ],
            [
                'table' => 'dim_products',
                'name' => 'idx_activity_checked',
                'columns' => 'activity_checked_at',
                'description' => 'Index for activity check timestamps'
            ],
            
            // Inventory data indexes
            [
                'table' => 'inventory_data',
                'name' => 'idx_sku_warehouse',
                'columns' => 'sku, warehouse_name',
                'description' => 'Composite index for SKU and warehouse lookups'
            ],
            [
                'table' => 'inventory_data',
                'name' => 'idx_current_stock',
                'columns' => 'current_stock',
                'description' => 'Index for stock level filtering'
            ],
            [
                'table' => 'inventory_data',
                'name' => 'idx_last_sync',
                'columns' => 'last_sync_at',
                'description' => 'Index for sync timestamp queries'
            ],
            
            // Cross-reference indexes
            [
                'table' => 'sku_cross_reference',
                'name' => 'idx_text_sku',
                'columns' => 'text_sku',
                'description' => 'Index for text SKU lookups'
            ],
            [
                'table' => 'sku_cross_reference',
                'name' => 'idx_numeric_sku',
                'columns' => 'numeric_sku',
                'description' => 'Index for numeric SKU lookups'
            ],
            
            // Activity log indexes
            [
                'table' => 'product_activity_log',
                'name' => 'idx_product_changed_at',
                'columns' => 'product_id, changed_at',
                'description' => 'Composite index for activity log queries'
            ],
            [
                'table' => 'product_activity_log',
                'name' => 'idx_external_sku',
                'columns' => 'external_sku',
                'description' => 'Index for external SKU lookups in activity log'
            ]
        ];
        
        $created_indexes = [];
        $errors = [];
        
        foreach ($indexes as $index) {
            try {
                if (!$this->indexExists($index['table'], $index['name'])) {
                    $sql = "CREATE INDEX {$index['name']} ON {$index['table']} ({$index['columns']})";
                    $this->pdo->exec($sql);
                    $created_indexes[] = $index;
                    $this->log("Created index: {$index['name']} on {$index['table']}");
                } else {
                    $this->log("Index already exists: {$index['name']} on {$index['table']}");
                }
            } catch (PDOException $e) {
                $errors[] = [
                    'index' => $index,
                    'error' => $e->getMessage()
                ];
                $this->log("Failed to create index {$index['name']}: " . $e->getMessage(), 'error');
            }
        }
        
        return [
            'created_indexes' => $created_indexes,
            'errors' => $errors,
            'total_created' => count($created_indexes)
        ];
    }
    
    /**
     * Check if an index exists
     */
    private function indexExists($table, $index_name) {
        $stmt = $this->pdo->prepare("
            SELECT COUNT(*) 
            FROM INFORMATION_SCHEMA.STATISTICS 
            WHERE TABLE_SCHEMA = DATABASE() 
            AND TABLE_NAME = ? 
            AND INDEX_NAME = ?
        ");
        $stmt->execute([$table, $index_name]);
        return $stmt->fetchColumn() > 0;
    }
    
    /**
     * Analyze query performance for active product filtering
     */
    public function analyzeQueryPerformance() {
        $queries = [
            'dashboard_active_products' => "
                SELECT COUNT(*) as count, AVG(current_stock) as avg_stock
                FROM inventory_data i
                LEFT JOIN dim_products dp ON (i.sku = dp.sku_ozon OR i.sku = dp.sku_wb)
                WHERE i.current_stock IS NOT NULL AND dp.is_active = 1
            ",
            'critical_products_active' => "
                SELECT COUNT(*) as count
                FROM inventory_data i
                LEFT JOIN dim_products dp ON (i.sku = dp.sku_ozon OR i.sku = dp.sku_wb)
                WHERE i.current_stock IS NOT NULL AND dp.is_active = 1 AND i.current_stock <= 5
            ",
            'warehouse_summary_active' => "
                SELECT i.warehouse_name, COUNT(DISTINCT i.sku) as product_count
                FROM inventory_data i
                LEFT JOIN dim_products dp ON (i.sku = dp.sku_ozon OR i.sku = dp.sku_wb)
                WHERE i.current_stock IS NOT NULL AND dp.is_active = 1
                GROUP BY i.warehouse_name
            ",
            'activity_stats' => "
                SELECT 
                    COUNT(*) as total_products,
                    COUNT(CASE WHEN is_active = 1 THEN 1 END) as active_products,
                    COUNT(CASE WHEN activity_checked_at IS NOT NULL THEN 1 END) as checked_products
                FROM dim_products
            "
        ];
        
        $performance_results = [];
        
        foreach ($queries as $query_name => $sql) {
            $start_time = microtime(true);
            
            try {
                // Execute EXPLAIN to get query plan
                $explain_stmt = $this->pdo->prepare("EXPLAIN " . $sql);
                $explain_stmt->execute();
                $explain_result = $explain_stmt->fetchAll(PDO::FETCH_ASSOC);
                
                // Execute actual query
                $stmt = $this->pdo->prepare($sql);
                $stmt->execute();
                $result = $stmt->fetchAll(PDO::FETCH_ASSOC);
                
                $execution_time = microtime(true) - $start_time;
                
                $performance_results[$query_name] = [
                    'execution_time_ms' => round($execution_time * 1000, 2),
                    'explain_plan' => $explain_result,
                    'result_count' => count($result),
                    'uses_index' => $this->analyzesUsesIndex($explain_result),
                    'performance_rating' => $this->rateQueryPerformance($execution_time, $explain_result)
                ];
                
            } catch (PDOException $e) {
                $performance_results[$query_name] = [
                    'error' => $e->getMessage(),
                    'execution_time_ms' => null,
                    'performance_rating' => 'error'
                ];
            }
        }
        
        return $performance_results;
    }
    
    /**
     * Check if query uses indexes effectively
     */
    private function analyzesUsesIndex($explain_result) {
        foreach ($explain_result as $row) {
            if (isset($row['key']) && $row['key'] !== null && $row['key'] !== '') {
                return true;
            }
        }
        return false;
    }
    
    /**
     * Rate query performance
     */
    private function rateQueryPerformance($execution_time, $explain_result) {
        // Check for table scans
        $has_table_scan = false;
        foreach ($explain_result as $row) {
            if (isset($row['type']) && $row['type'] === 'ALL') {
                $has_table_scan = true;
                break;
            }
        }
        
        if ($has_table_scan) {
            return 'poor';
        } elseif ($execution_time > 0.1) {
            return 'needs_improvement';
        } elseif ($execution_time > 0.05) {
            return 'good';
        } else {
            return 'excellent';
        }
    }
    
    /**
     * Get database statistics for optimization
     */
    public function getDatabaseStatistics() {
        $stats = [];
        
        try {
            // Table sizes
            $table_stats = $this->pdo->query("
                SELECT 
                    TABLE_NAME,
                    TABLE_ROWS,
                    DATA_LENGTH,
                    INDEX_LENGTH,
                    (DATA_LENGTH + INDEX_LENGTH) as TOTAL_SIZE
                FROM INFORMATION_SCHEMA.TABLES 
                WHERE TABLE_SCHEMA = DATABASE()
                AND TABLE_NAME IN ('dim_products', 'inventory_data', 'product_activity_log', 'sku_cross_reference')
                ORDER BY TOTAL_SIZE DESC
            ")->fetchAll(PDO::FETCH_ASSOC);
            
            $stats['table_statistics'] = $table_stats;
            
            // Index usage statistics
            $index_stats = $this->pdo->query("
                SELECT 
                    TABLE_NAME,
                    INDEX_NAME,
                    COLUMN_NAME,
                    CARDINALITY,
                    SUB_PART,
                    NULLABLE
                FROM INFORMATION_SCHEMA.STATISTICS 
                WHERE TABLE_SCHEMA = DATABASE()
                AND TABLE_NAME IN ('dim_products', 'inventory_data', 'product_activity_log', 'sku_cross_reference')
                ORDER BY TABLE_NAME, INDEX_NAME, SEQ_IN_INDEX
            ")->fetchAll(PDO::FETCH_ASSOC);
            
            $stats['index_statistics'] = $index_stats;
            
            // Active products statistics
            $active_stats = $this->pdo->query("
                SELECT 
                    COUNT(*) as total_products,
                    COUNT(CASE WHEN is_active = 1 THEN 1 END) as active_products,
                    COUNT(CASE WHEN is_active = 0 THEN 1 END) as inactive_products,
                    COUNT(CASE WHEN is_active IS NULL THEN 1 END) as unchecked_products,
                    COUNT(CASE WHEN activity_checked_at IS NOT NULL THEN 1 END) as checked_products
                FROM dim_products
            ")->fetch(PDO::FETCH_ASSOC);
            
            $stats['active_products_statistics'] = $active_stats;
            
            // Inventory data statistics
            $inventory_stats = $this->pdo->query("
                SELECT 
                    COUNT(*) as total_records,
                    COUNT(DISTINCT sku) as unique_skus,
                    COUNT(DISTINCT warehouse_name) as unique_warehouses,
                    AVG(current_stock) as avg_stock,
                    MAX(last_sync_at) as last_sync
                FROM inventory_data
                WHERE current_stock IS NOT NULL
            ")->fetch(PDO::FETCH_ASSOC);
            
            $stats['inventory_statistics'] = $inventory_stats;
            
        } catch (PDOException $e) {
            $stats['error'] = $e->getMessage();
        }
        
        return $stats;
    }
    
    /**
     * Optimize table statistics
     */
    public function optimizeTableStatistics() {
        $tables = ['dim_products', 'inventory_data', 'product_activity_log', 'sku_cross_reference'];
        $results = [];
        
        foreach ($tables as $table) {
            try {
                $start_time = microtime(true);
                $this->pdo->exec("ANALYZE TABLE $table");
                $execution_time = microtime(true) - $start_time;
                
                $results[$table] = [
                    'status' => 'success',
                    'execution_time_ms' => round($execution_time * 1000, 2)
                ];
                
                $this->log("Analyzed table: $table");
                
            } catch (PDOException $e) {
                $results[$table] = [
                    'status' => 'error',
                    'error' => $e->getMessage()
                ];
                $this->log("Failed to analyze table $table: " . $e->getMessage(), 'error');
            }
        }
        
        return $results;
    }
    
    /**
     * Generate optimization recommendations
     */
    public function generateOptimizationRecommendations() {
        $recommendations = [];
        
        // Analyze current performance
        $performance = $this->analyzeQueryPerformance();
        $stats = $this->getDatabaseStatistics();
        
        // Check for missing indexes
        foreach ($performance as $query_name => $result) {
            if (isset($result['performance_rating']) && in_array($result['performance_rating'], ['poor', 'needs_improvement'])) {
                $recommendations[] = [
                    'type' => 'index_optimization',
                    'priority' => 'high',
                    'query' => $query_name,
                    'issue' => 'Poor query performance detected',
                    'recommendation' => 'Consider adding or optimizing indexes for this query',
                    'execution_time_ms' => $result['execution_time_ms'] ?? null
                ];
            }
        }
        
        // Check table sizes
        if (isset($stats['table_statistics'])) {
            foreach ($stats['table_statistics'] as $table) {
                $size_mb = ($table['TOTAL_SIZE'] ?? 0) / 1024 / 1024;
                if ($size_mb > 100) { // Tables larger than 100MB
                    $recommendations[] = [
                        'type' => 'table_optimization',
                        'priority' => 'medium',
                        'table' => $table['TABLE_NAME'],
                        'issue' => "Large table size: {$size_mb}MB",
                        'recommendation' => 'Consider partitioning or archiving old data'
                    ];
                }
            }
        }
        
        // Check active products ratio
        if (isset($stats['active_products_statistics'])) {
            $active_ratio = $stats['active_products_statistics']['total_products'] > 0 ? 
                ($stats['active_products_statistics']['active_products'] / $stats['active_products_statistics']['total_products']) * 100 : 0;
            
            if ($active_ratio < 30) {
                $recommendations[] = [
                    'type' => 'data_quality',
                    'priority' => 'medium',
                    'issue' => "Low active products ratio: {$active_ratio}%",
                    'recommendation' => 'Review product activity criteria and update inactive products'
                ];
            }
        }
        
        return [
            'recommendations' => $recommendations,
            'total_recommendations' => count($recommendations),
            'high_priority_count' => count(array_filter($recommendations, function($r) { return $r['priority'] === 'high'; })),
            'generated_at' => date('Y-m-d H:i:s')
        ];
    }
    
    /**
     * Log message
     */
    private function log($message, $level = 'info') {
        if ($this->logger) {
            $this->logger->log($level, $message);
        } else {
            echo "[" . date('Y-m-d H:i:s') . "] [$level] $message\n";
        }
    }
    
    /**
     * Get performance metrics
     */
    public function getPerformanceMetrics() {
        return $this->performance_metrics;
    }
    
    /**
     * Clear performance metrics
     */
    public function clearPerformanceMetrics() {
        $this->performance_metrics = [];
    }
}

/**
 * Query Result Cache for frequently accessed data
 */
class QueryResultCache {
    private $cache_dir;
    private $default_ttl;
    
    public function __construct($cache_dir = null, $default_ttl = 300) {
        $this->cache_dir = $cache_dir ?: __DIR__ . '/../cache/query_results';
        $this->default_ttl = $default_ttl;
        
        if (!is_dir($this->cache_dir)) {
            mkdir($this->cache_dir, 0755, true);
        }
    }
    
    /**
     * Cache query result
     */
    public function cacheQueryResult($query_hash, $result, $ttl = null) {
        $ttl = $ttl ?: $this->default_ttl;
        $cache_file = $this->cache_dir . '/' . $query_hash . '.cache';
        
        $cache_data = [
            'result' => $result,
            'cached_at' => time(),
            'expires_at' => time() + $ttl,
            'query_hash' => $query_hash
        ];
        
        return file_put_contents($cache_file, json_encode($cache_data), LOCK_EX) !== false;
    }
    
    /**
     * Get cached query result
     */
    public function getCachedQueryResult($query_hash) {
        $cache_file = $this->cache_dir . '/' . $query_hash . '.cache';
        
        if (!file_exists($cache_file)) {
            return null;
        }
        
        $cache_data = json_decode(file_get_contents($cache_file), true);
        
        if (!$cache_data || time() > $cache_data['expires_at']) {
            unlink($cache_file);
            return null;
        }
        
        return $cache_data['result'];
    }
    
    /**
     * Generate query hash
     */
    public function generateQueryHash($sql, $params = []) {
        return md5($sql . serialize($params));
    }
}
?>