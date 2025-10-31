<?php
/**
 * Detailed Inventory Service
 * 
 * Service class for handling detailed inventory operations for the redesigned
 * warehouse dashboard. Provides product-warehouse level data with calculated metrics.
 * 
 * Requirements: 6.1, 6.2, 6.3, 6.4, 6.5
 * Task: 1.2 Implement new API endpoint `/api/inventory/detailed-stock`
 */

class DetailedInventoryService {
    
    private $pdo;
    private $cache;
    
    /**
     * Constructor
     * @param PDO $pdo Database connection
     * @param EnhancedCacheService|null $cache Enhanced cache service instance
     */
    public function __construct($pdo, $cache = null) {
        $this->pdo = $pdo;
        $this->cache = $cache ?: new EnhancedCacheService($pdo);
    }
    
    /**
     * Get detailed inventory data with filtering and sorting
     * 
     * @param array $filters Filter parameters
     * @return array Response data with inventory items and metadata
     */
    public function getDetailedInventory($filters = []) {
        $startTime = microtime(true);
        
        // Generate cache key
        $cacheKey = $this->cache->getInventoryKey($filters);
        
        // Try to get from cache first
        $cachedData = $this->cache->get($cacheKey);
        if ($cachedData !== null) {
            // Update metadata with cache info
            $cachedData['metadata']['cached'] = true;
            $cachedData['metadata']['cache_timestamp'] = $cachedData['metadata']['timestamp'];
            $cachedData['metadata']['timestamp'] = date('c');
            $cachedData['metadata']['processingTime'] = round((microtime(true) - $startTime) * 1000, 2);
            
            return $cachedData;
        }
        
        // Build the query
        $query = $this->buildDetailedInventoryQuery($filters);
        $countQuery = $this->buildCountQuery($filters);
        
        try {
            // Get total count for pagination
            $totalCount = $this->executeCountQuery($countQuery, $filters);
            
            // Get the actual data
            $data = $this->executeDataQuery($query, $filters);
            
            $processingTime = round((microtime(true) - $startTime) * 1000, 2);
            
            $result = [
                'success' => true,
                'data' => $data,
                'metadata' => [
                    'totalCount' => $totalCount,
                    'filteredCount' => count($data),
                    'timestamp' => date('c'),
                    'processingTime' => $processingTime,
                    'filters' => $filters,
                    'cached' => false
                ]
            ];
            
            // Cache the result
            $this->cache->set($cacheKey, $result, CacheService::TTL_INVENTORY_DATA);
            
            return $result;
            
        } catch (Exception $e) {
            error_log("Error in DetailedInventoryService::getDetailedInventory: " . $e->getMessage());
            throw new Exception('Failed to retrieve detailed inventory data: ' . $e->getMessage());
        }
    }
    
    /**
     * Build the main query for detailed inventory
     * 
     * @param array $filters Filter parameters
     * @return string SQL query
     */
    private function buildDetailedInventoryQuery($filters) {
        // Choose view based on performance requirements
        $viewName = $this->selectOptimalView($filters);
        
        $query = "
            SELECT 
                product_id,
                product_name,
                sku,
                sku_ozon,
                sku_wb,
                sku_internal,
                warehouse_name,
                cluster,
                marketplace_source,
                visibility,
                current_stock,
                available_stock,
                daily_sales_avg,
                sales_last_28_days,
                days_of_stock,
                stock_status,
                recommended_qty,
                recommended_value,
                urgency_score,
                stockout_risk,
                cost_price,
                current_stock_value,
                turnover_rate,
                sales_trend,
                last_updated,
                last_sale_date
            FROM $viewName
        ";
        
        // Add WHERE clause
        $whereConditions = $this->buildWhereConditions($filters);
        if (!empty($whereConditions)) {
            $query .= " WHERE " . implode(' AND ', $whereConditions);
        }
        
        // Add ORDER BY clause
        $query .= $this->buildOrderByClause($filters);
        
        // Add LIMIT and OFFSET
        $query .= $this->buildLimitClause($filters);
        
        return $query;
    }
    
    /**
     * Build count query for pagination
     * 
     * @param array $filters Filter parameters
     * @return string SQL query
     */
    private function buildCountQuery($filters) {
        $query = "SELECT COUNT(*) as total FROM v_detailed_inventory";
        
        $whereConditions = $this->buildWhereConditions($filters);
        if (!empty($whereConditions)) {
            $query .= " WHERE " . implode(' AND ', $whereConditions);
        }
        
        return $query;
    }
    
    /**
     * Select optimal view based on filter complexity
     * 
     * @param array $filters Filter parameters
     * @return string View name to use
     */
    private function selectOptimalView($filters) {
        // Always use standard view for now since v_detailed_inventory_fast 
        // has incomplete schema and is missing many required columns
        // TODO: Update v_detailed_inventory_fast to include all necessary columns
        // or implement a proper column mapping strategy
        return 'v_detailed_inventory';
        
        // Original logic (disabled until fast view is updated):
        // if (!empty($filters['use_fast_view']) || 
        //     !empty($filters['statuses']) || 
        //     !empty($filters['visibility']) ||
        //     (!empty($filters['sort_by']) && in_array($filters['sort_by'], ['urgency_score', 'stockout_risk']))) {
        //     return 'v_detailed_inventory_fast';
        // }
        // return 'v_detailed_inventory';
    }
    
    /**
     * Build WHERE conditions based on filters
     * 
     * @param array $filters Filter parameters
     * @return array WHERE conditions
     */
    private function buildWhereConditions($filters) {
        $conditions = [];
        
        // Default filtering — exclude archived/hidden items at SQL level
        // Backend guarantees only active (not archived/hidden) items are returned by default
        if (!isset($filters['include_hidden']) || !$filters['include_hidden']) {
            $conditions[] = "stock_status <> 'archived_or_hidden'";
        }
        
        // Visibility filter - allows explicit filtering by visibility status
        if (!empty($filters['visibility'])) {
            if (is_array($filters['visibility'])) {
                $placeholders = str_repeat('?,', count($filters['visibility']) - 1) . '?';
                $conditions[] = "visibility IN ($placeholders)";
            } else {
                $conditions[] = "visibility = ?";
            }
        }
        
        // Warehouse filter
        if (!empty($filters['warehouses']) && is_array($filters['warehouses'])) {
            $placeholders = str_repeat('?,', count($filters['warehouses']) - 1) . '?';
            $conditions[] = "warehouse_name IN ($placeholders)";
        } elseif (!empty($filters['warehouse'])) {
            $conditions[] = "warehouse_name = ?";
        }
        
        // Status filter (enhanced with functional index support)
        if (!empty($filters['statuses']) && is_array($filters['statuses'])) {
            $placeholders = str_repeat('?,', count($filters['statuses']) - 1) . '?';
            $conditions[] = "stock_status IN ($placeholders)";
        } elseif (!empty($filters['status'])) {
            $conditions[] = "stock_status = ?";
        }
        
        // Product search
        if (!empty($filters['search'])) {
            $conditions[] = "(
                product_name ILIKE ? OR 
                sku ILIKE ? OR 
                sku_ozon ILIKE ? OR 
                sku_wb ILIKE ? OR 
                sku_internal ILIKE ?
            )";
        }
        
        // Minimum days of stock
        if (isset($filters['min_days_of_stock']) && is_numeric($filters['min_days_of_stock'])) {
            $conditions[] = "days_of_stock >= ?";
        }
        
        // Maximum days of stock
        if (isset($filters['max_days_of_stock']) && is_numeric($filters['max_days_of_stock'])) {
            $conditions[] = "days_of_stock <= ?";
        }
        
        // Urgency filter
        if (isset($filters['min_urgency_score']) && is_numeric($filters['min_urgency_score'])) {
            $conditions[] = "urgency_score >= ?";
        }
        
        // Only products with replenishment need
        if (!empty($filters['has_replenishment_need'])) {
            $conditions[] = "recommended_qty > 0";
        }
        
        // Only active products (with stock or recent sales)
        if (!empty($filters['active_only'])) {
            $conditions[] = "(current_stock > 0 OR sales_last_28_days > 0)";
        }
        
        // NEW: Filter by available stock
        if (isset($filters['min_available_stock']) && is_numeric($filters['min_available_stock'])) {
            $conditions[] = "available_stock >= ?";
        }
        
        return $conditions;
    }
    
    /**
     * Build ORDER BY clause
     * 
     * @param array $filters Filter parameters
     * @return string ORDER BY clause
     */
    private function buildOrderByClause($filters) {
        // Default sorting: days_of_stock ASC (items that will stockout earlier go first)
        $sortBy = $filters['sort_by'] ?? 'days_of_stock';
        $sortOrder = strtoupper($filters['sort_order'] ?? 'ASC');
        
        // Validate sort order
        if (!in_array($sortOrder, ['ASC', 'DESC'])) {
            $sortOrder = 'DESC';
        }
        
        // Map sort fields to actual column names
        $sortFieldMap = [
            'product_name' => 'product_name',
            'warehouse_name' => 'warehouse_name',
            'current_stock' => 'current_stock',
            'available_stock' => 'available_stock',
            'daily_sales_avg' => 'daily_sales_avg',
            'days_of_stock' => 'days_of_stock',
            'stock_status' => 'stock_status',
            'recommended_qty' => 'recommended_qty',
            'urgency_score' => 'urgency_score',
            'stockout_risk' => 'stockout_risk',
            'turnover_rate' => 'turnover_rate',
            'last_updated' => 'last_updated'
        ];
        
        $sortField = $sortFieldMap[$sortBy] ?? 'days_of_stock';
        
        return " ORDER BY $sortField $sortOrder";
    }
    
    /**
     * Build LIMIT clause for pagination
     * 
     * @param array $filters Filter parameters
     * @return string LIMIT clause
     */
    private function buildLimitClause($filters) {
        $limit = isset($filters['limit']) ? (int)$filters['limit'] : 100;
        $offset = isset($filters['offset']) ? (int)$filters['offset'] : 0;
        
        // Cap limit at 1000 for performance
        $limit = min(1000, max(1, $limit));
        $offset = max(0, $offset);
        
        return " LIMIT $limit OFFSET $offset";
    }
    
    /**
     * Execute count query
     * 
     * @param string $query SQL query
     * @param array $filters Filter parameters
     * @return int Total count
     */
    private function executeCountQuery($query, $filters) {
        $params = $this->buildQueryParameters($filters);
        
        $stmt = $this->pdo->prepare($query);
        $stmt->execute($params);
        
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return (int)$result['total'];
    }
    
    /**
     * Execute data query
     * 
     * @param string $query SQL query
     * @param array $filters Filter parameters
     * @return array Query results
     */
    private function executeDataQuery($query, $filters) {
        $params = $this->buildQueryParameters($filters);
        
        $stmt = $this->pdo->prepare($query);
        $stmt->execute($params);
        
        $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Format the results
        return array_map([$this, 'formatInventoryItem'], $results);
    }
    
    /**
     * Build query parameters array based on filters
     * 
     * @param array $filters Filter parameters
     * @return array Query parameters
     */
    private function buildQueryParameters($filters) {
        $params = [];
        
        // NEW: Visibility filter parameters
        if (!empty($filters['visibility'])) {
            if (is_array($filters['visibility'])) {
                $params = array_merge($params, $filters['visibility']);
            } else {
                $params[] = $filters['visibility'];
            }
        }
        
        // Warehouse filter parameters
        if (!empty($filters['warehouses']) && is_array($filters['warehouses'])) {
            $params = array_merge($params, $filters['warehouses']);
        } elseif (!empty($filters['warehouse'])) {
            $params[] = $filters['warehouse'];
        }
        
        // Status filter parameters
        if (!empty($filters['statuses']) && is_array($filters['statuses'])) {
            $params = array_merge($params, $filters['statuses']);
        } elseif (!empty($filters['status'])) {
            $params[] = $filters['status'];
        }
        
        // Search parameters (5 times for each field)
        if (!empty($filters['search'])) {
            $searchTerm = '%' . $filters['search'] . '%';
            $params = array_merge($params, array_fill(0, 5, $searchTerm));
        }
        
        // Days of stock parameters
        if (isset($filters['min_days_of_stock']) && is_numeric($filters['min_days_of_stock'])) {
            $params[] = (float)$filters['min_days_of_stock'];
        }
        
        if (isset($filters['max_days_of_stock']) && is_numeric($filters['max_days_of_stock'])) {
            $params[] = (float)$filters['max_days_of_stock'];
        }
        
        // Urgency score parameters
        if (isset($filters['min_urgency_score']) && is_numeric($filters['min_urgency_score'])) {
            $params[] = (int)$filters['min_urgency_score'];
        }
        
        // NEW: Available stock parameters
        if (isset($filters['min_available_stock']) && is_numeric($filters['min_available_stock'])) {
            $params[] = (int)$filters['min_available_stock'];
        }
        
        return $params;
    }
    
    /**
     * Format inventory item for API response
     * 
     * @param array $item Raw database row
     * @return array Formatted item
     */
    private function formatInventoryItem($item) {
        return [
            'productId' => (int)$item['product_id'],
            'productName' => $item['product_name'],
            'sku' => $item['sku'],
            'skuOzon' => $item['sku_ozon'],
            'skuWb' => $item['sku_wb'],
            'skuInternal' => $item['sku_internal'],
            'warehouseName' => $item['warehouse_name'],
            'cluster' => $item['cluster'],
            'marketplaceSource' => $item['marketplace_source'],
            'visibility' => $item['visibility'] ?? 'UNKNOWN', // NEW: Include visibility field
            'currentStock' => (int)$item['current_stock'],
            'availableStock' => (int)$item['available_stock'],
            'dailySales' => (float)$item['daily_sales_avg'],
            'sales28d' => (int)$item['sales_last_28_days'],
            'daysOfStock' => $item['days_of_stock'] ? (float)$item['days_of_stock'] : null,
            'status' => $item['stock_status'],
            'recommendedQty' => (int)$item['recommended_qty'],
            'recommendedValue' => $item['recommended_value'] ? (float)$item['recommended_value'] : 0,
            'urgencyScore' => (int)$item['urgency_score'],
            'stockoutRisk' => (int)$item['stockout_risk'],
            'costPrice' => $item['cost_price'] ? (float)$item['cost_price'] : null,
            'currentStockValue' => $item['current_stock_value'] ? (float)$item['current_stock_value'] : 0,
            'turnoverRate' => $item['turnover_rate'] ? (float)$item['turnover_rate'] : 0,
            'salesTrend' => $item['sales_trend'],
            'lastUpdated' => $item['last_updated'],
            'lastSaleDate' => $item['last_sale_date']
        ];
    }
    
    /**
     * Get list of warehouses for filter options
     * 
     * @return array List of warehouses
     */
    public function getWarehouses() {
        // Try to get from cache first
        $cacheKey = $this->cache->getWarehouseListKey();
        $cachedData = $this->cache->get($cacheKey);
        
        if ($cachedData !== null) {
            return $cachedData;
        }
        
        try {
            $query = "
                SELECT 
                    warehouse_name,
                    COUNT(*) as product_count,
                    SUM(current_stock) as total_stock,
                    SUM(CASE WHEN stock_status = 'critical' THEN 1 ELSE 0 END) as critical_count,
                    SUM(CASE WHEN stock_status = 'low' THEN 1 ELSE 0 END) as low_count,
                    SUM(CASE WHEN recommended_qty > 0 THEN 1 ELSE 0 END) as replenishment_needed_count
                FROM v_detailed_inventory 
                WHERE warehouse_name IS NOT NULL
                GROUP BY warehouse_name 
                ORDER BY warehouse_name
            ";
            
            $stmt = $this->pdo->prepare($query);
            $stmt->execute();
            
            $result = [
                'success' => true,
                'data' => $stmt->fetchAll(PDO::FETCH_ASSOC)
            ];
            
            // Cache the result
            $this->cache->set($cacheKey, $result, CacheService::TTL_WAREHOUSE_LIST);
            
            return $result;
            
        } catch (Exception $e) {
            error_log("Error in DetailedInventoryService::getWarehouses: " . $e->getMessage());
            throw new Exception('Failed to retrieve warehouse list: ' . $e->getMessage());
        }
    }
    
    /**
     * Get visibility statistics
     * 
     * @return array Visibility statistics
     */
    public function getVisibilityStats() {
        // Try to get from cache first
        $cacheKey = 'visibility_stats';
        $cachedData = $this->cache->get($cacheKey);
        
        if ($cachedData !== null) {
            return $cachedData;
        }
        
        try {
            $query = "
                SELECT 
                    visibility,
                    COUNT(*) as product_count,
                    SUM(available_stock) as total_available_stock,
                    SUM(CASE WHEN stock_status IN ('critical', 'low') THEN 1 ELSE 0 END) as needs_attention_count
                FROM v_detailed_inventory
                GROUP BY visibility
                ORDER BY product_count DESC
            ";
            
            $stmt = $this->pdo->prepare($query);
            $stmt->execute();
            
            $result = [
                'success' => true,
                'data' => $stmt->fetchAll(PDO::FETCH_ASSOC)
            ];
            
            // Cache the result
            $this->cache->set($cacheKey, $result, CacheService::TTL_SUMMARY_STATS);
            
            return $result;
            
        } catch (Exception $e) {
            error_log("Error in DetailedInventoryService::getVisibilityStats: " . $e->getMessage());
            throw new Exception('Failed to retrieve visibility statistics: ' . $e->getMessage());
        }
    }
    
    /**
     * Get summary statistics for dashboard
     * 
     * @return array Summary statistics
     */
    public function getSummaryStats() {
        // Try to get from cache first
        $cacheKey = $this->cache->getSummaryStatsKey();
        $cachedData = $this->cache->get($cacheKey);
        
        if ($cachedData !== null) {
            return $cachedData;
        }
        
        try {
            $query = "
                SELECT 
                    COUNT(*) as total_products,
                    COUNT(DISTINCT warehouse_name) as total_warehouses,
                    SUM(current_stock) as total_stock,
                    SUM(current_stock_value) as total_stock_value,
                    SUM(CASE WHEN stock_status = 'critical' THEN 1 ELSE 0 END) as critical_count,
                    SUM(CASE WHEN stock_status = 'low' THEN 1 ELSE 0 END) as low_count,
                    SUM(CASE WHEN stock_status = 'normal' THEN 1 ELSE 0 END) as normal_count,
                    SUM(CASE WHEN stock_status = 'excess' THEN 1 ELSE 0 END) as excess_count,
                    SUM(CASE WHEN stock_status = 'out_of_stock' THEN 1 ELSE 0 END) as out_of_stock_count,
                    SUM(CASE WHEN recommended_qty > 0 THEN 1 ELSE 0 END) as replenishment_needed_count,
                    SUM(recommended_value) as total_replenishment_value,
                    AVG(urgency_score) as avg_urgency_score
                FROM v_detailed_inventory
            ";
            
            $stmt = $this->pdo->prepare($query);
            $stmt->execute();
            
            $stats = $stmt->fetch(PDO::FETCH_ASSOC);
            
            // Format the statistics
            $result = [
                'success' => true,
                'data' => [
                    'totalProducts' => (int)$stats['total_products'],
                    'totalWarehouses' => (int)$stats['total_warehouses'],
                    'totalStock' => (int)$stats['total_stock'],
                    'totalStockValue' => (float)$stats['total_stock_value'],
                    'statusCounts' => [
                        'critical' => (int)$stats['critical_count'],
                        'low' => (int)$stats['low_count'],
                        'normal' => (int)$stats['normal_count'],
                        'excess' => (int)$stats['excess_count'],
                        'outOfStock' => (int)$stats['out_of_stock_count']
                    ],
                    'replenishmentNeededCount' => (int)$stats['replenishment_needed_count'],
                    'totalReplenishmentValue' => (float)$stats['total_replenishment_value'],
                    'avgUrgencyScore' => (float)$stats['avg_urgency_score']
                ]
            ];
            
            // Cache the result
            $this->cache->set($cacheKey, $result, CacheService::TTL_SUMMARY_STATS);
            
            return $result;
            
        } catch (Exception $e) {
            error_log("Error in DetailedInventoryService::getSummaryStats: " . $e->getMessage());
            throw new Exception('Failed to retrieve summary statistics: ' . $e->getMessage());
        }
    }
}

/**
 * Custom validation exception for detailed inventory service
 */
class DetailedInventoryValidationException extends Exception {}

?>