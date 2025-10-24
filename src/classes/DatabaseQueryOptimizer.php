<?php
/**
 * Database Query Optimizer
 * 
 * Optimizes database queries for warehouse stock API endpoints
 * 
 * @version 1.0
 * @author Manhattan System
 */

require_once __DIR__ . '/../utils/Logger.php';

class DatabaseQueryOptimizer {
    
    private $pdo;
    private $logger;
    private $queryCache;
    private $indexHints;
    
    public function __construct(PDO $pdo) {
        $this->pdo = $pdo;
        $this->logger = Logger::getInstance();
        $this->queryCache = [];
        $this->initializeIndexHints();
    }
    
    /**
     * Optimize warehouse stock query
     * 
     * @param array $filters - Query filters
     * @return array Optimized query with SQL and parameters
     */
    public function optimizeWarehouseStockQuery(array $filters): array {
        $cacheKey = 'warehouse_stock_' . md5(serialize($filters));
        
        if (isset($this->queryCache[$cacheKey])) {
            return $this->queryCache[$cacheKey];
        }
        
        $query = $this->buildOptimizedStockQuery($filters);
        $this->queryCache[$cacheKey] = $query;
        
        return $query;
    }
    
    /**
     * Build optimized stock query with proper indexing
     * 
     * @param array $filters - Query filters
     * @return array Query with SQL and parameters
     */
    private function buildOptimizedStockQuery(array $filters): array {
        $selectFields = $this->getOptimizedSelectFields();
        $fromClause = $this->getOptimizedFromClause($filters);
        $whereClause = $this->buildOptimizedWhereClause($filters);
        $orderClause = $this->getOptimizedOrderClause($filters);
        $limitClause = $this->getLimitClause($filters);
        
        $sql = "
            SELECT {$selectFields}
            FROM {$fromClause}
            WHERE {$whereClause['sql']}
            {$orderClause}
            {$limitClause}
        ";
        
        return [
            'sql' => $sql,
            'params' => $whereClause['params'],
            'estimated_rows' => $this->estimateResultRows($filters),
            'optimization_notes' => $this->getOptimizationNotes($filters)
        ];
    }
    
    /**
     * Get optimized SELECT fields
     * 
     * @return string SELECT clause
     */
    private function getOptimizedSelectFields(): string {
        return "
            i.id,
            i.product_id,
            p.sku,
            p.name as product_name,
            i.warehouse_name,
            i.source,
            i.quantity_present,
            i.quantity_reserved,
            (i.quantity_present - i.quantity_reserved) as quantity_available,
            i.stock_type,
            i.report_source,
            i.last_report_update,
            i.report_code,
            i.updated_at
        ";
    }
    
    /**
     * Get optimized FROM clause with index hints
     * 
     * @param array $filters - Query filters
     * @return string FROM clause
     */
    private function getOptimizedFromClause(array $filters): string {
        $indexHint = $this->getIndexHint($filters);
        
        return "inventory i {$indexHint}
                LEFT JOIN dim_products p ON i.product_id = p.id";
    }
    
    /**
     * Get appropriate index hint based on filters
     * 
     * @param array $filters - Query filters
     * @return string Index hint
     */
    private function getIndexHint(array $filters): string {
        // Determine best index based on filter selectivity
        if ($filters['warehouse'] && $filters['source']) {
            return "USE INDEX (idx_warehouse_source)";
        }
        
        if ($filters['product_id']) {
            return "USE INDEX (idx_product_warehouse)";
        }
        
        if ($filters['warehouse']) {
            return "USE INDEX (idx_warehouse_name)";
        }
        
        if ($filters['source']) {
            return "USE INDEX (idx_source)";
        }
        
        if ($filters['date_from'] || $filters['date_to']) {
            return "USE INDEX (idx_updated_at)";
        }
        
        return "";
    }
    
    /**
     * Build optimized WHERE clause
     * 
     * @param array $filters - Query filters
     * @return array WHERE clause with parameters
     */
    private function buildOptimizedWhereClause(array $filters): array {
        $conditions = ["1=1"];
        $params = [];
        
        // Order conditions by selectivity (most selective first)
        $orderedFilters = $this->orderFiltersBySelectivity($filters);
        
        foreach ($orderedFilters as $filter => $value) {
            if ($value === null) {
                continue;
            }
            
            switch ($filter) {
                case 'product_id':
                    $conditions[] = "i.product_id = :product_id";
                    $params['product_id'] = $value;
                    break;
                    
                case 'warehouse':
                    $conditions[] = "i.warehouse_name = :warehouse";
                    $params['warehouse'] = $value;
                    break;
                    
                case 'source':
                    $conditions[] = "i.source = :source";
                    $params['source'] = $value;
                    break;
                    
                case 'sku':
                    // Use LIKE with proper indexing
                    if (strlen($value) >= 3) {
                        $conditions[] = "p.sku LIKE :sku";
                        $params['sku'] = $value . '%'; // Prefix search for better index usage
                    } else {
                        $conditions[] = "p.sku LIKE :sku";
                        $params['sku'] = '%' . $value . '%';
                    }
                    break;
                    
                case 'stock_level':
                    $stockCondition = $this->getStockLevelCondition($value);
                    if ($stockCondition) {
                        $conditions[] = $stockCondition;
                    }
                    break;
                    
                case 'date_from':
                    $conditions[] = "i.updated_at >= :date_from";
                    $params['date_from'] = $value . ' 00:00:00';
                    break;
                    
                case 'date_to':
                    $conditions[] = "i.updated_at <= :date_to";
                    $params['date_to'] = $value . ' 23:59:59';
                    break;
            }
        }
        
        return [
            'sql' => implode(" AND ", $conditions),
            'params' => $params
        ];
    }
    
    /**
     * Order filters by selectivity for optimal query performance
     * 
     * @param array $filters - Query filters
     * @return array Ordered filters
     */
    private function orderFiltersBySelectivity(array $filters): array {
        // Order by estimated selectivity (most selective first)
        $selectivityOrder = [
            'product_id' => 1,      // Most selective
            'warehouse' => 2,
            'source' => 3,
            'stock_level' => 4,
            'sku' => 5,
            'date_from' => 6,
            'date_to' => 7          // Least selective
        ];
        
        $orderedFilters = [];
        foreach ($selectivityOrder as $filter => $priority) {
            if (isset($filters[$filter])) {
                $orderedFilters[$filter] = $filters[$filter];
            }
        }
        
        return $orderedFilters;
    }
    
    /**
     * Get stock level condition
     * 
     * @param string $level - Stock level
     * @return string|null SQL condition
     */
    private function getStockLevelCondition(string $level): ?string {
        switch ($level) {
            case 'zero':
                return "i.quantity_present = 0";
            case 'low':
                return "i.quantity_present > 0 AND i.quantity_present <= 10";
            case 'normal':
                return "i.quantity_present > 10 AND i.quantity_present <= 100";
            case 'high':
                return "i.quantity_present > 100";
            default:
                return null;
        }
    }
    
    /**
     * Get optimized ORDER clause
     * 
     * @param array $filters - Query filters
     * @return string ORDER clause
     */
    private function getOptimizedOrderClause(array $filters): string {
        $sortBy = $filters['sort_by'] ?? 'updated_at';
        $sortOrder = $filters['sort_order'] ?? 'DESC';
        
        // Add secondary sort for consistent pagination
        $secondarySort = $sortBy !== 'id' ? ', i.id DESC' : '';
        
        return "ORDER BY i.{$sortBy} {$sortOrder}{$secondarySort}";
    }
    
    /**
     * Get LIMIT clause
     * 
     * @param array $filters - Query filters
     * @return string LIMIT clause
     */
    private function getLimitClause(array $filters): string {
        $limit = $filters['limit'] ?? 100;
        $offset = $filters['offset'] ?? 0;
        
        return "LIMIT {$limit} OFFSET {$offset}";
    }
    
    /**
     * Estimate number of result rows
     * 
     * @param array $filters - Query filters
     * @return int Estimated row count
     */
    private function estimateResultRows(array $filters): int {
        try {
            // Build a COUNT query with same filters
            $whereClause = $this->buildOptimizedWhereClause($filters);
            
            $sql = "
                SELECT COUNT(*) as estimated_count
                FROM inventory i
                LEFT JOIN dim_products p ON i.product_id = p.id
                WHERE {$whereClause['sql']}
            ";
            
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($whereClause['params']);
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            
            return (int) $result['estimated_count'];
            
        } catch (Exception $e) {
            $this->logger->error('Failed to estimate result rows', [
                'error' => $e->getMessage(),
                'filters' => $filters
            ]);
            
            return 0;
        }
    }
    
    /**
     * Get optimization notes for query
     * 
     * @param array $filters - Query filters
     * @return array Optimization notes
     */
    private function getOptimizationNotes(array $filters): array {
        $notes = [];
        
        if ($filters['product_id']) {
            $notes[] = "Using product_id index for optimal performance";
        }
        
        if ($filters['warehouse'] && $filters['source']) {
            $notes[] = "Using composite warehouse-source index";
        }
        
        if ($filters['sku'] && strlen($filters['sku']) < 3) {
            $notes[] = "Short SKU search may impact performance";
        }
        
        if (($filters['offset'] ?? 0) > 10000) {
            $notes[] = "Large offset may impact performance - consider cursor-based pagination";
        }
        
        return $notes;
    }
    
    /**
     * Initialize index hints configuration
     */
    private function initializeIndexHints(): void {
        $this->indexHints = [
            'inventory' => [
                'primary' => 'PRIMARY',
                'product_warehouse' => 'idx_product_warehouse',
                'warehouse_name' => 'idx_warehouse_name',
                'source' => 'idx_source',
                'updated_at' => 'idx_updated_at',
                'warehouse_source' => 'idx_warehouse_source',
                'report_source' => 'idx_report_source'
            ],
            'dim_products' => [
                'primary' => 'PRIMARY',
                'sku' => 'idx_sku',
                'name' => 'idx_name'
            ]
        ];
    }
    
    /**
     * Analyze query performance
     * 
     * @param string $sql - SQL query
     * @param array $params - Query parameters
     * @return array Performance analysis
     */
    public function analyzeQueryPerformance(string $sql, array $params): array {
        try {
            $startTime = microtime(true);
            
            // Execute EXPLAIN
            $explainSql = "EXPLAIN " . $sql;
            $stmt = $this->pdo->prepare($explainSql);
            $stmt->execute($params);
            $explainResult = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Execute actual query
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($params);
            $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            $executionTime = microtime(true) - $startTime;
            
            return [
                'execution_time_ms' => round($executionTime * 1000, 2),
                'rows_returned' => count($results),
                'explain_plan' => $explainResult,
                'performance_score' => $this->calculatePerformanceScore($explainResult, $executionTime),
                'recommendations' => $this->getPerformanceRecommendations($explainResult)
            ];
            
        } catch (Exception $e) {
            $this->logger->error('Failed to analyze query performance', [
                'error' => $e->getMessage(),
                'sql' => substr($sql, 0, 200)
            ]);
            
            return [
                'error' => $e->getMessage()
            ];
        }
    }
    
    /**
     * Calculate performance score based on explain plan
     * 
     * @param array $explainPlan - EXPLAIN result
     * @param float $executionTime - Execution time in seconds
     * @return int Performance score (0-100)
     */
    private function calculatePerformanceScore(array $explainPlan, float $executionTime): int {
        $score = 100;
        
        foreach ($explainPlan as $row) {
            // Penalize table scans
            if (isset($row['type']) && $row['type'] === 'ALL') {
                $score -= 30;
            }
            
            // Penalize high row counts
            if (isset($row['rows']) && $row['rows'] > 10000) {
                $score -= 20;
            }
            
            // Penalize filesort
            if (isset($row['Extra']) && strpos($row['Extra'], 'Using filesort') !== false) {
                $score -= 15;
            }
            
            // Penalize temporary tables
            if (isset($row['Extra']) && strpos($row['Extra'], 'Using temporary') !== false) {
                $score -= 10;
            }
        }
        
        // Penalize slow execution
        if ($executionTime > 1.0) {
            $score -= 25;
        } elseif ($executionTime > 0.5) {
            $score -= 15;
        } elseif ($executionTime > 0.1) {
            $score -= 5;
        }
        
        return max(0, $score);
    }
    
    /**
     * Get performance recommendations
     * 
     * @param array $explainPlan - EXPLAIN result
     * @return array Recommendations
     */
    private function getPerformanceRecommendations(array $explainPlan): array {
        $recommendations = [];
        
        foreach ($explainPlan as $row) {
            if (isset($row['type']) && $row['type'] === 'ALL') {
                $recommendations[] = "Consider adding an index on table '{$row['table']}' for better performance";
            }
            
            if (isset($row['Extra']) && strpos($row['Extra'], 'Using filesort') !== false) {
                $recommendations[] = "Consider adding an index to avoid filesort on table '{$row['table']}'";
            }
            
            if (isset($row['Extra']) && strpos($row['Extra'], 'Using temporary') !== false) {
                $recommendations[] = "Query uses temporary table - consider optimizing GROUP BY or ORDER BY";
            }
            
            if (isset($row['rows']) && $row['rows'] > 100000) {
                $recommendations[] = "Query examines many rows ({$row['rows']}) - consider adding more selective filters";
            }
        }
        
        return array_unique($recommendations);
    }
    
    /**
     * Get suggested indexes for better performance
     * 
     * @param array $filters - Common query filters
     * @return array Suggested indexes
     */
    public function getSuggestedIndexes(array $filters): array {
        $suggestions = [];
        
        // Analyze filter combinations
        if (isset($filters['warehouse']) && isset($filters['source'])) {
            $suggestions[] = [
                'table' => 'inventory',
                'columns' => ['warehouse_name', 'source'],
                'type' => 'composite',
                'reason' => 'Frequently filtered by warehouse and source together'
            ];
        }
        
        if (isset($filters['product_id']) && isset($filters['warehouse'])) {
            $suggestions[] = [
                'table' => 'inventory',
                'columns' => ['product_id', 'warehouse_name'],
                'type' => 'composite',
                'reason' => 'Frequently filtered by product and warehouse together'
            ];
        }
        
        return $suggestions;
    }
    
    /**
     * Clear query cache
     */
    public function clearQueryCache(): void {
        $this->queryCache = [];
        $this->logger->info('Query cache cleared');
    }
}