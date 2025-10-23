<?php
/**
 * Warehouse Service
 * 
 * Main service for warehouse dashboard functionality.
 * Orchestrates sales analytics and replenishment calculations
 * to provide comprehensive warehouse inventory data.
 * 
 * Requirements: 1, 2, 9, 10
 */

require_once __DIR__ . '/WarehouseSalesAnalyticsService.php';
require_once __DIR__ . '/ReplenishmentCalculator.php';

class WarehouseService {
    
    private $pdo;
    private $salesAnalytics;
    private $replenishmentCalc;
    
    /**
     * Constructor
     * @param PDO $pdo Database connection
     */
    public function __construct($pdo) {
        $this->pdo = $pdo;
        $this->salesAnalytics = new WarehouseSalesAnalyticsService($pdo);
        $this->replenishmentCalc = new ReplenishmentCalculator();
    }
    
    /**
     * Get dashboard data with filters
     * 
     * Returns comprehensive warehouse dashboard data including inventory,
     * sales metrics, and replenishment calculations. Supports filtering
     * by warehouse, cluster, liquidity status, and other criteria.
     * 
     * Requirements: 1.1, 1.2, 1.3, 2.1, 2.2, 2.3, 2.4, 9.1, 9.2, 9.3
     * 
     * @param array $filters Filter parameters
     * @return array Dashboard data
     */
    public function getDashboardData($filters = []) {
        try {
            // Extract and validate filters
            $warehouse = $filters['warehouse'] ?? null;
            $cluster = $filters['cluster'] ?? null;
            $liquidityStatus = $filters['liquidity_status'] ?? null;
            $activeOnly = $filters['active_only'] ?? true;
            $hasReplenishmentNeed = $filters['has_replenishment_need'] ?? null;
            $sortBy = $filters['sort_by'] ?? 'replenishment_need';
            $sortOrder = $filters['sort_order'] ?? 'desc';
            $limit = isset($filters['limit']) ? min(1000, max(1, (int)$filters['limit'])) : 100;
            $offset = isset($filters['offset']) ? max(0, (int)$filters['offset']) : 0;
            
            // Build WHERE conditions
            $conditions = [];
            $params = [];
            
            if ($warehouse) {
                $conditions[] = "i.warehouse_name = :warehouse";
                $params['warehouse'] = $warehouse;
            }
            
            if ($cluster) {
                $conditions[] = "i.cluster = :cluster";
                $params['cluster'] = $cluster;
            }
            
            if ($liquidityStatus) {
                $conditions[] = "wsm.liquidity_status = :liquidity_status";
                $params['liquidity_status'] = $liquidityStatus;
            }
            
            if ($activeOnly) {
                // Active products: have sales in last 30 days OR have stock > 0
                $conditions[] = "(wsm.sales_last_28_days > 0 OR i.quantity_present > 0)";
            }
            
            if ($hasReplenishmentNeed) {
                $conditions[] = "wsm.replenishment_need > 0";
            }
            
            $whereClause = !empty($conditions) ? 'WHERE ' . implode(' AND ', $conditions) : '';
            
            // Build ORDER BY clause
            $allowedSortFields = [
                'product_name' => 'dp.product_name',
                'warehouse_name' => 'i.warehouse_name',
                'available' => 'i.quantity_present',
                'daily_sales_avg' => 'wsm.daily_sales_avg',
                'days_of_stock' => 'wsm.days_of_stock',
                'replenishment_need' => 'wsm.replenishment_need',
                'days_without_sales' => 'wsm.days_without_sales'
            ];
            
            $sortField = $allowedSortFields[$sortBy] ?? 'wsm.replenishment_need';
            $sortDirection = strtoupper($sortOrder) === 'ASC' ? 'ASC' : 'DESC';
            
            // Handle NULL values in days_of_stock (put them last)
            if ($sortBy === 'days_of_stock') {
                $orderByClause = "ORDER BY wsm.days_of_stock IS NULL, $sortField $sortDirection";
            } else {
                $orderByClause = "ORDER BY $sortField $sortDirection";
            }
            
            // Get total count first (without pagination)
            $countSql = "
                SELECT COUNT(*) as total
                FROM inventory i
                INNER JOIN dim_products dp ON i.product_id = dp.id
                LEFT JOIN warehouse_sales_metrics wsm ON 
                    wsm.product_id = i.product_id 
                    AND wsm.warehouse_name = i.warehouse_name
                    AND wsm.source::text = i.source::text
                $whereClause
            ";
            
            $countStmt = $this->pdo->prepare($countSql);
            $countStmt->execute($params);
            $totalCount = (int)$countStmt->fetchColumn();
            
            // Main query to get warehouse items
            $sql = "
                SELECT 
                    -- Product info
                    dp.id as product_id,
                    dp.sku_ozon as sku,
                    dp.product_name as name,
                    
                    -- Warehouse info
                    i.warehouse_name,
                    i.cluster,
                    
                    -- Current stock
                    i.quantity_present as available,
                    i.quantity_reserved as reserved,
                    
                    -- Ozon metrics
                    COALESCE(i.preparing_for_sale, 0) as preparing_for_sale,
                    COALESCE(i.in_supply_requests, 0) as in_supply_requests,
                    COALESCE(i.in_transit, 0) as in_transit,
                    COALESCE(i.in_inspection, 0) as in_inspection,
                    COALESCE(i.returning_from_customers, 0) as returning_from_customers,
                    COALESCE(i.expiring_soon, 0) as expiring_soon,
                    COALESCE(i.defective, 0) as defective,
                    COALESCE(i.excess_from_supply, 0) as excess_from_supply,
                    COALESCE(i.awaiting_upd, 0) as awaiting_upd,
                    COALESCE(i.preparing_for_removal, 0) as preparing_for_removal,
                    
                    -- Sales metrics from cache
                    COALESCE(wsm.daily_sales_avg, 0) as daily_sales_avg,
                    COALESCE(wsm.sales_last_28_days, 0) as sales_last_28_days,
                    COALESCE(wsm.days_without_sales, 0) as days_without_sales,
                    
                    -- Liquidity metrics from cache
                    wsm.days_of_stock,
                    COALESCE(wsm.liquidity_status, 'normal') as liquidity_status,
                    
                    -- Replenishment from cache
                    COALESCE(wsm.target_stock, 0) as target_stock,
                    COALESCE(wsm.replenishment_need, 0) as replenishment_need,
                    
                    -- Metadata
                    i.updated_at as last_updated,
                    wsm.calculated_at as metrics_calculated_at
                    
                FROM inventory i
                INNER JOIN dim_products dp ON i.product_id = dp.id
                LEFT JOIN warehouse_sales_metrics wsm ON 
                    wsm.product_id = i.product_id 
                    AND wsm.warehouse_name = i.warehouse_name
                    AND wsm.source::text = i.source::text
                $whereClause
                $orderByClause
                LIMIT :limit OFFSET :offset
            ";
            
            $params['limit'] = $limit;
            $params['offset'] = $offset;
            
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($params);
            $items = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Format items
            $formattedItems = [];
            foreach ($items as $item) {
                $formattedItems[] = $this->formatWarehouseItem($item);
            }
            
            // Group by warehouse
            $warehouses = $this->groupByWarehouse($formattedItems);
            
            // Get summary statistics
            $summary = $this->calculateSummary($filters);
            
            return [
                'success' => true,
                'data' => [
                    'warehouses' => $warehouses,
                    'summary' => $summary,
                    'filters_applied' => [
                        'warehouse' => $warehouse,
                        'cluster' => $cluster,
                        'liquidity_status' => $liquidityStatus,
                        'active_only' => $activeOnly,
                        'has_replenishment_need' => $hasReplenishmentNeed,
                        'sort_by' => $sortBy,
                        'sort_order' => $sortOrder
                    ],
                    'pagination' => [
                        'limit' => $limit,
                        'offset' => $offset,
                        'total' => $totalCount,
                        'current_page' => floor($offset / $limit) + 1,
                        'total_pages' => ceil($totalCount / $limit),
                        'has_next' => ($offset + $limit) < $totalCount,
                        'has_prev' => $offset > 0
                    ],
                    'last_updated' => date('c')
                ]
            ];
            
        } catch (Exception $e) {
            error_log("Error in getDashboardData: " . $e->getMessage());
            return [
                'success' => false,
                'error' => 'Failed to retrieve dashboard data',
                'message' => $e->getMessage()
            ];
        }
    }
    
    /**
     * Get list of all warehouses
     * 
     * Returns a list of all unique warehouse names in the system.
     * 
     * Requirements: 2.3, 9.1
     * 
     * @return array List of warehouses
     */
    public function getWarehouseList() {
        try {
            $sql = "
                SELECT DISTINCT 
                    warehouse_name,
                    cluster,
                    COUNT(*) as product_count
                FROM inventory
                WHERE warehouse_name IS NOT NULL
                GROUP BY warehouse_name, cluster
                ORDER BY warehouse_name
            ";
            
            $stmt = $this->pdo->query($sql);
            $warehouses = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            return [
                'success' => true,
                'data' => $warehouses
            ];
            
        } catch (Exception $e) {
            error_log("Error in getWarehouseList: " . $e->getMessage());
            return [
                'success' => false,
                'error' => 'Failed to retrieve warehouse list'
            ];
        }
    }
    
    /**
     * Get list of all clusters
     * 
     * Returns a list of all unique warehouse clusters in the system.
     * 
     * Requirements: 2.4, 9.1
     * 
     * @return array List of clusters
     */
    public function getClusterList() {
        try {
            $sql = "
                SELECT DISTINCT 
                    cluster,
                    COUNT(DISTINCT warehouse_name) as warehouse_count,
                    COUNT(*) as product_count
                FROM inventory
                WHERE cluster IS NOT NULL
                GROUP BY cluster
                ORDER BY cluster
            ";
            
            $stmt = $this->pdo->query($sql);
            $clusters = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            return [
                'success' => true,
                'data' => $clusters
            ];
            
        } catch (Exception $e) {
            error_log("Error in getClusterList: " . $e->getMessage());
            return [
                'success' => false,
                'error' => 'Failed to retrieve cluster list'
            ];
        }
    }
    
    /**
     * Export dashboard data to CSV
     * 
     * Generates a CSV file with warehouse dashboard data based on filters.
     * 
     * Requirements: 10.1, 10.2, 10.3, 10.4
     * 
     * @param array $filters Filter parameters
     * @return string CSV content
     */
    public function exportToCSV($filters = []) {
        try {
            // Get data without pagination
            $filters['limit'] = 10000; // Max export limit
            $filters['offset'] = 0;
            
            $result = $this->getDashboardData($filters);
            
            if (!$result['success']) {
                throw new Exception('Failed to retrieve data for export');
            }
            
            $warehouses = $result['data']['warehouses'];
            
            // Build CSV
            $csv = [];
            
            // Header row
            $csv[] = [
                'Товар',
                'SKU',
                'Склад',
                'Кластер',
                'Доступно',
                'Зарезервировано',
                'Готовим к продаже',
                'В заявках',
                'В пути',
                'На проверке',
                'Возвраты',
                'Истекает срок',
                'Брак',
                'Продажи/день',
                'Продаж за 28 дней',
                'Дней без продаж',
                'Дней запаса',
                'Статус ликвидности',
                'Целевой запас',
                'Нужно заказать'
            ];
            
            // Data rows
            foreach ($warehouses as $warehouse) {
                foreach ($warehouse['items'] as $item) {
                    $csv[] = [
                        $item['name'],
                        $item['sku'],
                        $item['warehouse_name'],
                        $item['cluster'],
                        $item['available'],
                        $item['reserved'],
                        $item['preparing_for_sale'],
                        $item['in_supply_requests'],
                        $item['in_transit'],
                        $item['in_inspection'],
                        $item['returning_from_customers'],
                        $item['expiring_soon'],
                        $item['defective'],
                        $item['daily_sales_avg'],
                        $item['sales_last_28_days'],
                        $item['days_without_sales'],
                        $item['days_of_stock'] ?? '∞',
                        $item['liquidity_status'],
                        $item['target_stock'],
                        $item['replenishment_need']
                    ];
                }
            }
            
            // Convert to CSV string
            $output = fopen('php://temp', 'r+');
            foreach ($csv as $row) {
                fputcsv($output, $row, ',', '"', '\\');
            }
            rewind($output);
            $csvContent = stream_get_contents($output);
            fclose($output);
            
            return $csvContent;
            
        } catch (Exception $e) {
            error_log("Error in exportToCSV: " . $e->getMessage());
            throw $e;
        }
    }
    
    /**
     * Format warehouse item for output
     * 
     * @param array $item Raw item data
     * @return array Formatted item
     */
    private function formatWarehouseItem($item) {
        return [
            'product_id' => (int)$item['product_id'],
            'sku' => $item['sku'],
            'name' => $item['name'],
            'warehouse_name' => $item['warehouse_name'],
            'cluster' => $item['cluster'],
            'available' => (int)$item['available'],
            'reserved' => (int)$item['reserved'],
            'preparing_for_sale' => (int)$item['preparing_for_sale'],
            'in_supply_requests' => (int)$item['in_supply_requests'],
            'in_transit' => (int)$item['in_transit'],
            'in_inspection' => (int)$item['in_inspection'],
            'returning_from_customers' => (int)$item['returning_from_customers'],
            'expiring_soon' => (int)$item['expiring_soon'],
            'defective' => (int)$item['defective'],
            'excess_from_supply' => (int)$item['excess_from_supply'],
            'awaiting_upd' => (int)$item['awaiting_upd'],
            'preparing_for_removal' => (int)$item['preparing_for_removal'],
            'daily_sales_avg' => (float)$item['daily_sales_avg'],
            'sales_last_28_days' => (int)$item['sales_last_28_days'],
            'days_without_sales' => (int)$item['days_without_sales'],
            'days_of_stock' => $item['days_of_stock'] !== null ? (float)$item['days_of_stock'] : null,
            'liquidity_status' => $item['liquidity_status'],
            'target_stock' => (int)$item['target_stock'],
            'replenishment_need' => (int)$item['replenishment_need'],
            'last_updated' => $item['last_updated']
        ];
    }
    
    /**
     * Group items by warehouse
     * 
     * @param array $items Formatted items
     * @return array Grouped by warehouse
     */
    private function groupByWarehouse($items) {
        $grouped = [];
        
        foreach ($items as $item) {
            $warehouseName = $item['warehouse_name'];
            
            if (!isset($grouped[$warehouseName])) {
                $grouped[$warehouseName] = [
                    'warehouse_name' => $warehouseName,
                    'cluster' => $item['cluster'],
                    'items' => [],
                    'totals' => [
                        'total_items' => 0,
                        'total_available' => 0,
                        'total_replenishment_need' => 0
                    ]
                ];
            }
            
            $grouped[$warehouseName]['items'][] = $item;
            $grouped[$warehouseName]['totals']['total_items']++;
            $grouped[$warehouseName]['totals']['total_available'] += $item['available'];
            $grouped[$warehouseName]['totals']['total_replenishment_need'] += $item['replenishment_need'];
        }
        
        return array_values($grouped);
    }
    
    /**
     * Calculate summary statistics
     * 
     * @param array $filters Applied filters
     * @return array Summary data
     */
    private function calculateSummary($filters) {
        try {
            // Build WHERE conditions (same as main query)
            $conditions = [];
            $params = [];
            
            if (isset($filters['warehouse'])) {
                $conditions[] = "i.warehouse_name = :warehouse";
                $params['warehouse'] = $filters['warehouse'];
            }
            
            if (isset($filters['cluster'])) {
                $conditions[] = "i.cluster = :cluster";
                $params['cluster'] = $filters['cluster'];
            }
            
            if (isset($filters['liquidity_status'])) {
                $conditions[] = "wsm.liquidity_status = :liquidity_status";
                $params['liquidity_status'] = $filters['liquidity_status'];
            }
            
            $activeOnly = $filters['active_only'] ?? true;
            if ($activeOnly) {
                $conditions[] = "(wsm.sales_last_28_days > 0 OR i.quantity_present > 0)";
            }
            
            if (isset($filters['has_replenishment_need']) && $filters['has_replenishment_need']) {
                $conditions[] = "wsm.replenishment_need > 0";
            }
            
            $whereClause = !empty($conditions) ? 'WHERE ' . implode(' AND ', $conditions) : '';
            
            $sql = "
                SELECT 
                    COUNT(*) as total_products,
                    SUM(CASE WHEN wsm.sales_last_28_days > 0 OR i.quantity_present > 0 THEN 1 ELSE 0 END) as active_products,
                    SUM(COALESCE(wsm.replenishment_need, 0)) as total_replenishment_need,
                    SUM(CASE WHEN wsm.liquidity_status = 'critical' THEN 1 ELSE 0 END) as critical_count,
                    SUM(CASE WHEN wsm.liquidity_status = 'low' THEN 1 ELSE 0 END) as low_count,
                    SUM(CASE WHEN wsm.liquidity_status = 'normal' THEN 1 ELSE 0 END) as normal_count,
                    SUM(CASE WHEN wsm.liquidity_status = 'excess' THEN 1 ELSE 0 END) as excess_count
                FROM inventory i
                INNER JOIN dim_products dp ON i.product_id = dp.id
                LEFT JOIN warehouse_sales_metrics wsm ON 
                    wsm.product_id = i.product_id 
                    AND wsm.warehouse_name = i.warehouse_name
                    AND wsm.source::text = i.source::text
                $whereClause
            ";
            
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($params);
            $summary = $stmt->fetch(PDO::FETCH_ASSOC);
            
            return [
                'total_products' => (int)$summary['total_products'],
                'active_products' => (int)$summary['active_products'],
                'total_replenishment_need' => (int)$summary['total_replenishment_need'],
                'by_liquidity' => [
                    'critical' => (int)$summary['critical_count'],
                    'low' => (int)$summary['low_count'],
                    'normal' => (int)$summary['normal_count'],
                    'excess' => (int)$summary['excess_count']
                ]
            ];
            
        } catch (Exception $e) {
            error_log("Error in calculateSummary: " . $e->getMessage());
            return [
                'total_products' => 0,
                'active_products' => 0,
                'total_replenishment_need' => 0,
                'by_liquidity' => [
                    'critical' => 0,
                    'low' => 0,
                    'normal' => 0,
                    'excess' => 0
                ]
            ];
        }
    }
}
?>
