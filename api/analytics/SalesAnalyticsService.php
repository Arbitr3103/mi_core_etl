<?php
/**
 * Sales Analytics Service
 * 
 * Core service class for regional sales analytics system.
 * Provides methods for marketplace analysis, product performance tracking,
 * and sales dynamics calculations.
 */

require_once __DIR__ . '/config.php';

class SalesAnalyticsService {
    
    private $pdo;
    private $db;
    private $clientId;
    
    /**
     * Constructor
     * @param int $clientId Client ID (default: 1 for ТД Манхэттен)
     */
    public function __construct($clientId = 1) {
        // Use connection pooling for production
        require_once __DIR__ . '/DatabaseConnectionPool.php';
        $this->db = new DatabaseManager();
        $this->clientId = $clientId;
        
        // Keep PDO for backward compatibility
        $this->pdo = getAnalyticsDbConnection();
    }
    
    /**
     * Get marketplace comparison data
     * 
     * Compares sales performance between Ozon and Wildberries marketplaces
     * for the specified date range.
     * 
     * @param string $dateFrom Start date (YYYY-MM-DD)
     * @param string $dateTo End date (YYYY-MM-DD)
     * @return array Marketplace comparison data
     */
    public function getMarketplaceComparison($dateFrom, $dateTo) {
        try {
            // Validate date inputs
            if (!$this->validateDateRange($dateFrom, $dateTo)) {
                throw new InvalidArgumentException('Invalid date range provided');
            }
            
            $sql = "
                SELECT 
                    s.code as marketplace,
                    s.name as marketplace_name,
                    COUNT(DISTINCT fo.order_id) as total_orders,
                    SUM(fo.qty) as total_quantity,
                    SUM(fo.price * fo.qty) as total_revenue,
                    AVG(fo.price * fo.qty) as average_check,
                    COUNT(DISTINCT fo.product_id) as unique_products
                FROM fact_orders fo
                JOIN dim_sources s ON fo.source_id = s.id
                JOIN dim_products dp ON fo.product_id = dp.id
                    WHERE fo.order_date >= :date_from
                    AND fo.order_date <= :date_to
                    AND dp.brand = 'ЭТОНОВО'
                    AND s.code IN ('OZON', 'WB')
                GROUP BY s.id, s.code, s.name
                ORDER BY total_revenue DESC
            ";
            
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute([
                
                'date_from' => $dateFrom,
                'date_to' => $dateTo
            ]);
            
            $results = $stmt->fetchAll();
            
            // Calculate totals and percentages
            $totalRevenue = array_sum(array_column($results, 'total_revenue'));
            $totalOrders = array_sum(array_column($results, 'total_orders'));
            
            $comparison = [];
            foreach ($results as $row) {
                $comparison[] = [
                    'marketplace' => strtolower($row['marketplace']),
                    'marketplace_name' => $row['marketplace_name'],
                    'total_orders' => (int)$row['total_orders'],
                    'total_quantity' => (int)$row['total_quantity'],
                    'total_revenue' => (float)$row['total_revenue'],
                    'average_check' => (float)$row['average_check'],
                    'unique_products' => (int)$row['unique_products'],
                    'revenue_share' => $totalRevenue > 0 ? round(($row['total_revenue'] / $totalRevenue) * 100, 2) : 0,
                    'orders_share' => $totalOrders > 0 ? round(($row['total_orders'] / $totalOrders) * 100, 2) : 0
                ];
            }
            
            return [
                'period' => [
                    'date_from' => $dateFrom,
                    'date_to' => $dateTo
                ],
                'summary' => [
                    'total_revenue' => $totalRevenue,
                    'total_orders' => $totalOrders,
                    'marketplaces_count' => count($results)
                ],
                'marketplaces' => $comparison
            ];
            
        } catch (Exception $e) {
            logAnalyticsActivity('ERROR', 'Error in getMarketplaceComparison: ' . $e->getMessage());
            throw $e;
        }
    }
    
    /**
     * Get top products by marketplace
     * 
     * Returns the best performing products for a specific marketplace
     * or all marketplaces combined.
     * 
     * @param string $marketplace Marketplace filter ('ozon', 'wildberries', 'all')
     * @param int $limit Number of products to return (default: 10)
     * @param string $dateFrom Start date (optional)
     * @param string $dateTo End date (optional)
     * @return array Top products data
     */
    public function getTopProductsByMarketplace($marketplace = 'all', $limit = 10, $dateFrom = null, $dateTo = null) {
        try {
            // Set default date range if not provided (last 30 days)
            if (!$dateFrom || !$dateTo) {
                $dateTo = date('Y-m-d');
                $dateFrom = date('Y-m-d', strtotime('-30 days'));
            }
            
            // Validate inputs
            if (!$this->validateDateRange($dateFrom, $dateTo)) {
                throw new InvalidArgumentException('Invalid date range provided');
            }
            
            if (!in_array($marketplace, ['ozon', 'wildberries', 'all'])) {
                throw new InvalidArgumentException('Invalid marketplace filter');
            }
            
            $limit = max(1, min(100, (int)$limit)); // Limit between 1 and 100
            
            // Build marketplace filter condition
            $marketplaceCondition = '';
            $params = [
                
                'date_from' => $dateFrom,
                'date_to' => $dateTo,
                'limit' => $limit
            ];
            
            if ($marketplace !== 'all') {
                $marketplaceCode = ($marketplace === 'ozon') ? 'OZON' : 'WB';
                $marketplaceCondition = 'AND s.code = :marketplace_code';
                $params['marketplace_code'] = $marketplaceCode;
            }
            
            $sql = "
                SELECT 
                    dp.id as product_id,
                    dp.product_name,
                    dp.sku_ozon,
                    dp.category,
                    dp.cost_price,
                    COUNT(DISTINCT fo.order_id) as total_orders,
                    SUM(fo.qty) as total_quantity,
                    SUM(fo.price * fo.qty) as total_revenue,
                    AVG(fo.price * fo.qty) as average_order_value,
                    AVG(fo.price) as average_price,
                    GROUP_CONCAT(DISTINCT s.name ORDER BY s.name) as marketplaces
                FROM fact_orders fo
                JOIN dim_products dp ON fo.product_id = dp.id
                JOIN dim_sources s ON fo.source_id = s.id
                    WHERE fo.order_date >= :date_from
                    AND fo.order_date <= :date_to
                    AND dp.brand = 'ЭТОНОВО'
                    AND s.code IN ('OZON', 'WB')
                    $marketplaceCondition
                GROUP BY dp.id, dp.product_name, dp.sku_ozon, dp.category, dp.cost_price
                ORDER BY total_revenue DESC
                LIMIT :limit
            ";
            
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($params);
            $results = $stmt->fetchAll();
            
            // Calculate additional metrics
            $products = [];
            foreach ($results as $row) {
                $revenue = (float)$row['total_revenue'];
                $costPrice = (float)$row['cost_price'];
                $quantity = (int)$row['total_quantity'];
                
                $margin = 0;
                $marginPercent = 0;
                if ($costPrice > 0 && $quantity > 0) {
                    $totalCost = $costPrice * $quantity;
                    $margin = $revenue - $totalCost;
                    $marginPercent = ($margin / $revenue) * 100;
                }
                
                $products[] = [
                    'product_id' => (int)$row['product_id'],
                    'product_name' => $row['product_name'],
                    'sku_ozon' => $row['sku_ozon'],
                    'category' => $row['category'],
                    'total_orders' => (int)$row['total_orders'],
                    'total_quantity' => $quantity,
                    'total_revenue' => $revenue,
                    'average_order_value' => (float)$row['average_order_value'],
                    'average_price' => (float)$row['average_price'],
                    'cost_price' => $costPrice,
                    'margin' => $margin,
                    'margin_percent' => round($marginPercent, 2),
                    'marketplaces' => $row['marketplaces']
                ];
            }
            
            return [
                'period' => [
                    'date_from' => $dateFrom,
                    'date_to' => $dateTo
                ],
                'filters' => [
                    'marketplace' => $marketplace,
                    'limit' => $limit
                ],
                'products' => $products
            ];
            
        } catch (Exception $e) {
            logAnalyticsActivity('ERROR', 'Error in getTopProductsByMarketplace: ' . $e->getMessage());
            throw $e;
        }
    }
    
    /**
     * Get sales dynamics data
     * 
     * Returns sales trends and growth rates over time periods.
     * 
     * @param string $period Aggregation period ('month', 'week', 'day')
     * @param string $dateFrom Start date (optional)
     * @param string $dateTo End date (optional)
     * @param string $marketplace Marketplace filter (optional)
     * @return array Sales dynamics data
     */
    public function getSalesDynamics($period = 'month', $dateFrom = null, $dateTo = null, $marketplace = 'all') {
        try {
            // Set default date range if not provided (last 6 months for monthly, 12 weeks for weekly)
            if (!$dateFrom || !$dateTo) {
                $dateTo = date('Y-m-d');
                if ($period === 'month') {
                    $dateFrom = date('Y-m-d', strtotime('-6 months'));
                } elseif ($period === 'week') {
                    $dateFrom = date('Y-m-d', strtotime('-12 weeks'));
                } else {
                    $dateFrom = date('Y-m-d', strtotime('-30 days'));
                }
            }
            
            // Validate inputs
            if (!$this->validateDateRange($dateFrom, $dateTo)) {
                throw new InvalidArgumentException('Invalid date range provided');
            }
            
            if (!in_array($period, ['month', 'week', 'day'])) {
                throw new InvalidArgumentException('Invalid period. Use: month, week, or day');
            }
            
            // Build date grouping based on period
            $dateGrouping = '';
            switch ($period) {
                case 'month':
                    $dateGrouping = "DATE_FORMAT(fo.order_date, '%Y-%m')";
                    break;
                case 'week':
                    $dateGrouping = "DATE_FORMAT(fo.order_date, '%Y-%u')";
                    break;
                case 'day':
                    $dateGrouping = "DATE(fo.order_date)";
                    break;
            }
            
            // Build marketplace filter
            $marketplaceCondition = '';
            $params = [
                
                'date_from' => $dateFrom,
                'date_to' => $dateTo
            ];
            
            if ($marketplace !== 'all') {
                $marketplaceCode = ($marketplace === 'ozon') ? 'OZON' : 'WB';
                $marketplaceCondition = 'AND s.code = :marketplace_code';
                $params['marketplace_code'] = $marketplaceCode;
            }
            
            $sql = "
                SELECT 
                    $dateGrouping as period,
                    s.code as marketplace,
                    s.name as marketplace_name,
                    COUNT(DISTINCT fo.order_id) as orders_count,
                    SUM(fo.qty) as quantity_sold,
                    SUM(fo.price * fo.qty) as revenue,
                    AVG(fo.price * fo.qty) as avg_order_value,
                    COUNT(DISTINCT fo.product_id) as unique_products
                FROM fact_orders fo
                JOIN dim_sources s ON fo.source_id = s.id
                JOIN dim_products dp ON fo.product_id = dp.id
                    WHERE fo.order_date >= :date_from
                    AND fo.order_date <= :date_to
                    AND dp.brand = 'ЭТОНОВО'
                    AND s.code IN ('OZON', 'WB')
                    $marketplaceCondition
                GROUP BY period, s.id, s.code, s.name
                ORDER BY period ASC, s.code ASC
            ";
            
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($params);
            $results = $stmt->fetchAll();
            
            // Process results and calculate growth rates
            $dynamics = [];
            $periodData = [];
            
            // Group by period first
            foreach ($results as $row) {
                $periodKey = $row['period'];
                if (!isset($periodData[$periodKey])) {
                    $periodData[$periodKey] = [];
                }
                $periodData[$periodKey][] = $row;
            }
            
            // Calculate totals and growth rates
            $previousPeriodTotals = null;
            foreach ($periodData as $periodKey => $marketplaces) {
                $periodTotal = [
                    'period' => $periodKey,
                    'total_orders' => 0,
                    'total_revenue' => 0,
                    'total_quantity' => 0,
                    'marketplaces' => []
                ];
                
                foreach ($marketplaces as $marketplace) {
                    $orders = (int)$marketplace['orders_count'];
                    $revenue = (float)$marketplace['revenue'];
                    $quantity = (int)$marketplace['quantity_sold'];
                    
                    $periodTotal['total_orders'] += $orders;
                    $periodTotal['total_revenue'] += $revenue;
                    $periodTotal['total_quantity'] += $quantity;
                    
                    $periodTotal['marketplaces'][] = [
                        'marketplace' => strtolower($marketplace['marketplace']),
                        'marketplace_name' => $marketplace['marketplace_name'],
                        'orders_count' => $orders,
                        'quantity_sold' => $quantity,
                        'revenue' => $revenue,
                        'avg_order_value' => (float)$marketplace['avg_order_value'],
                        'unique_products' => (int)$marketplace['unique_products']
                    ];
                }
                
                // Calculate growth rates
                $periodTotal['growth_rates'] = [
                    'orders_growth' => 0,
                    'revenue_growth' => 0,
                    'quantity_growth' => 0
                ];
                
                if ($previousPeriodTotals) {
                    if ($previousPeriodTotals['total_orders'] > 0) {
                        $periodTotal['growth_rates']['orders_growth'] = 
                            round((($periodTotal['total_orders'] - $previousPeriodTotals['total_orders']) / $previousPeriodTotals['total_orders']) * 100, 2);
                    }
                    if ($previousPeriodTotals['total_revenue'] > 0) {
                        $periodTotal['growth_rates']['revenue_growth'] = 
                            round((($periodTotal['total_revenue'] - $previousPeriodTotals['total_revenue']) / $previousPeriodTotals['total_revenue']) * 100, 2);
                    }
                    if ($previousPeriodTotals['total_quantity'] > 0) {
                        $periodTotal['growth_rates']['quantity_growth'] = 
                            round((($periodTotal['total_quantity'] - $previousPeriodTotals['total_quantity']) / $previousPeriodTotals['total_quantity']) * 100, 2);
                    }
                }
                
                $dynamics[] = $periodTotal;
                $previousPeriodTotals = $periodTotal;
            }
            
            return [
                'period_type' => $period,
                'date_range' => [
                    'date_from' => $dateFrom,
                    'date_to' => $dateTo
                ],
                'filters' => [
                    'marketplace' => $marketplace
                ],
                'dynamics' => $dynamics
            ];
            
        } catch (Exception $e) {
            logAnalyticsActivity('ERROR', 'Error in getSalesDynamics: ' . $e->getMessage());
            throw $e;
        }
    }
    
    /**
     * Get marketplace share data
     * 
     * Returns overall marketplace share statistics.
     * 
     * @param string $dateFrom Start date (optional)
     * @param string $dateTo End date (optional)
     * @return array Marketplace share data
     */
    public function getMarketplaceShare($dateFrom = null, $dateTo = null) {
        try {
            // Set default date range if not provided (last 30 days)
            if (!$dateFrom || !$dateTo) {
                $dateTo = date('Y-m-d');
                $dateFrom = date('Y-m-d', strtotime('-30 days'));
            }
            
            // Validate date inputs
            if (!$this->validateDateRange($dateFrom, $dateTo)) {
                throw new InvalidArgumentException('Invalid date range provided');
            }
            
            $sql = "
                SELECT 
                    s.code as marketplace,
                    s.name as marketplace_name,
                    COUNT(DISTINCT fo.order_id) as total_orders,
                    SUM(fo.qty) as total_quantity,
                    SUM(fo.price * fo.qty) as total_revenue,
                    COUNT(DISTINCT fo.product_id) as unique_products,
                    COUNT(DISTINCT DATE(fo.order_date)) as active_days
                FROM fact_orders fo
                JOIN dim_sources s ON fo.source_id = s.id
                JOIN dim_products dp ON fo.product_id = dp.id
                    WHERE fo.order_date >= :date_from
                    AND fo.order_date <= :date_to
                    AND dp.brand = 'ЭТОНОВО'
                    AND s.code IN ('OZON', 'WB')
                GROUP BY s.id, s.code, s.name
                ORDER BY total_revenue DESC
            ";
            
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute([
                
                'date_from' => $dateFrom,
                'date_to' => $dateTo
            ]);
            
            $results = $stmt->fetchAll();
            
            // Calculate totals for percentage calculations
            $grandTotals = [
                'orders' => array_sum(array_column($results, 'total_orders')),
                'revenue' => array_sum(array_column($results, 'total_revenue')),
                'quantity' => array_sum(array_column($results, 'total_quantity'))
            ];
            
            $shares = [];
            foreach ($results as $row) {
                $orders = (int)$row['total_orders'];
                $revenue = (float)$row['total_revenue'];
                $quantity = (int)$row['total_quantity'];
                
                $shares[] = [
                    'marketplace' => strtolower($row['marketplace']),
                    'marketplace_name' => $row['marketplace_name'],
                    'metrics' => [
                        'total_orders' => $orders,
                        'total_revenue' => $revenue,
                        'total_quantity' => $quantity,
                        'unique_products' => (int)$row['unique_products'],
                        'active_days' => (int)$row['active_days']
                    ],
                    'shares' => [
                        'orders_share' => $grandTotals['orders'] > 0 ? round(($orders / $grandTotals['orders']) * 100, 2) : 0,
                        'revenue_share' => $grandTotals['revenue'] > 0 ? round(($revenue / $grandTotals['revenue']) * 100, 2) : 0,
                        'quantity_share' => $grandTotals['quantity'] > 0 ? round(($quantity / $grandTotals['quantity']) * 100, 2) : 0
                    ],
                    'performance' => [
                        'avg_order_value' => $orders > 0 ? round($revenue / $orders, 2) : 0,
                        'avg_daily_orders' => $row['active_days'] > 0 ? round($orders / $row['active_days'], 2) : 0,
                        'avg_daily_revenue' => $row['active_days'] > 0 ? round($revenue / $row['active_days'], 2) : 0
                    ]
                ];
            }
            
            return [
                'period' => [
                    'date_from' => $dateFrom,
                    'date_to' => $dateTo
                ],
                'summary' => [
                    'total_orders' => $grandTotals['orders'],
                    'total_revenue' => $grandTotals['revenue'],
                    'total_quantity' => $grandTotals['quantity'],
                    'marketplaces_count' => count($results)
                ],
                'marketplace_shares' => $shares
            ];
            
        } catch (Exception $e) {
            logAnalyticsActivity('ERROR', 'Error in getMarketplaceShare: ' . $e->getMessage());
            throw $e;
        }
    }
    
    /**
     * Get dashboard summary data
     * 
     * Returns aggregated KPI data for dashboard overview including
     * total revenue, orders, regions, and average order value.
     * 
     * @param string $dateFrom Start date
     * @param string $dateTo End date
     * @param string $marketplace Marketplace filter (optional)
     * @return array Dashboard summary data
     */
    public function getDashboardSummary($dateFrom, $dateTo, $marketplace = 'all') {
        try {
            // Validate date inputs
            if (!$this->validateDateRange($dateFrom, $dateTo)) {
                throw new InvalidArgumentException('Invalid date range provided');
            }
            
            // Build marketplace filter
            $marketplaceCondition = '';
            $params = [
                'date_from' => $dateFrom,
                'date_to' => $dateTo
            ];
            
            if ($marketplace !== 'all') {
                $marketplaceCode = ($marketplace === 'ozon') ? 'OZON' : 'WB';
                $marketplaceCondition = 'AND s.code = :marketplace_code';
                $params['marketplace_code'] = $marketplaceCode;
            }
            
            // Get main KPI data
            $sql = "
                SELECT 
                    COUNT(DISTINCT fo.order_id) as total_orders,
                    SUM(fo.qty) as total_quantity,
                    SUM(fo.price * fo.qty) as total_revenue,
                    AVG(fo.price * fo.qty) as average_order_value,
                    COUNT(DISTINCT fo.product_id) as unique_products,
                    COUNT(DISTINCT s.id) as active_marketplaces,
                    MIN(fo.order_date) as first_order_date,
                    MAX(fo.order_date) as last_order_date
                FROM fact_orders fo
                JOIN dim_sources s ON fo.source_id = s.id
                JOIN dim_products dp ON fo.product_id = dp.id
                WHERE fo.order_date >= :date_from
                    AND fo.order_date <= :date_to
                    AND dp.brand = 'ЭТОНОВО'
                    AND s.code IN ('OZON', 'WB')
                    $marketplaceCondition
            ";
            
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($params);
            $summary = $stmt->fetch();
            
            // Get top regions (placeholder for now - will be enhanced in Phase 2)
            $topRegions = $this->getTopRegions($dateFrom, $dateTo, 5);
            
            // Calculate additional metrics
            $totalRevenue = (float)$summary['total_revenue'];
            $totalOrders = (int)$summary['total_orders'];
            $activeRegions = count($topRegions); // Placeholder - will be actual regions in Phase 2
            
            return [
                'total_revenue' => $totalRevenue,
                'total_orders' => $totalOrders,
                'total_quantity' => (int)$summary['total_quantity'],
                'average_order_value' => (float)$summary['average_order_value'],
                'unique_products' => (int)$summary['unique_products'],
                'active_marketplaces' => (int)$summary['active_marketplaces'],
                'active_regions' => $activeRegions,
                'period_info' => [
                    'date_from' => $dateFrom,
                    'date_to' => $dateTo,
                    'first_order_date' => $summary['first_order_date'],
                    'last_order_date' => $summary['last_order_date']
                ],
                'top_regions' => $topRegions
            ];
            
        } catch (Exception $e) {
            logAnalyticsActivity('ERROR', 'Error in getDashboardSummary: ' . $e->getMessage());
            throw $e;
        }
    }
    
    /**
     * Get top regions by sales performance
     * 
     * Returns the best performing regions. In Phase 1, this returns
     * placeholder data based on marketplace performance.
     * 
     * @param string $dateFrom Start date
     * @param string $dateTo End date
     * @param int $limit Number of regions to return
     * @return array Top regions data
     */
    public function getTopRegions($dateFrom, $dateTo, $limit = 10) {
        try {
            // Validate inputs
            if (!$this->validateDateRange($dateFrom, $dateTo)) {
                throw new InvalidArgumentException('Invalid date range provided');
            }
            
            $limit = max(1, min(50, (int)$limit));
            
            // Phase 1: Return marketplace-based placeholder data
            // In Phase 2, this will query actual regional data from ozon_regional_sales table
            $sql = "
                SELECT 
                    s.name as region_name,
                    'Центральный ФО' as federal_district,
                    COUNT(DISTINCT fo.order_id) as total_orders,
                    SUM(fo.price * fo.qty) as total_revenue,
                    AVG(fo.price * fo.qty) as average_order_value,
                    COUNT(DISTINCT fo.product_id) as unique_products
                FROM fact_orders fo
                JOIN dim_sources s ON fo.source_id = s.id
                JOIN dim_products dp ON fo.product_id = dp.id
                WHERE fo.order_date >= :date_from
                    AND fo.order_date <= :date_to
                    AND dp.brand = 'ЭТОНОВО'
                    AND s.code IN ('OZON', 'WB')
                GROUP BY s.id, s.name
                ORDER BY total_revenue DESC
                LIMIT :limit
            ";
            
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute([
                'date_from' => $dateFrom,
                'date_to' => $dateTo,
                'limit' => $limit
            ]);
            
            $results = $stmt->fetchAll();
            
            $regions = [];
            foreach ($results as $row) {
                $regions[] = [
                    'region_name' => $row['region_name'] . ' (маркетплейс)',
                    'federal_district' => $row['federal_district'],
                    'total_orders' => (int)$row['total_orders'],
                    'total_revenue' => (float)$row['total_revenue'],
                    'average_order_value' => (float)$row['average_order_value'],
                    'unique_products' => (int)$row['unique_products']
                ];
            }
            
            return $regions;
            
        } catch (Exception $e) {
            logAnalyticsActivity('ERROR', 'Error in getTopRegions: ' . $e->getMessage());
            throw $e;
        }
    }
    
    /**
     * Get detailed data for a specific region
     * 
     * Returns comprehensive analytics for a single region.
     * Phase 1 implementation returns placeholder data.
     * 
     * @param string $regionCode Region code
     * @param string $dateFrom Start date
     * @param string $dateTo End date
     * @return array Region details
     */
    public function getRegionDetails($regionCode, $dateFrom, $dateTo) {
        try {
            // Validate inputs
            if (!$this->validateDateRange($dateFrom, $dateTo)) {
                throw new InvalidArgumentException('Invalid date range provided');
            }
            
            // Phase 1: Return placeholder data
            // In Phase 2, this will query actual regional data
            return [
                'region_code' => $regionCode,
                'region_name' => 'Регион ' . $regionCode,
                'federal_district' => 'Центральный ФО',
                'period' => [
                    'date_from' => $dateFrom,
                    'date_to' => $dateTo
                ],
                'metrics' => [
                    'total_orders' => 0,
                    'total_revenue' => 0,
                    'average_order_value' => 0,
                    'unique_products' => 0
                ],
                'marketplace_breakdown' => [],
                'top_products' => [],
                'note' => 'Региональные данные будут доступны в Phase 2 после интеграции с Ozon API'
            ];
            
        } catch (Exception $e) {
            logAnalyticsActivity('ERROR', 'Error in getRegionDetails: ' . $e->getMessage());
            throw $e;
        }
    }
    
    /**
     * Validate date range
     * 
     * @param string $dateFrom Start date
     * @param string $dateTo End date
     * @return bool True if valid, false otherwise
     */
    private function validateDateRange($dateFrom, $dateTo) {
        // Check date format
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateFrom) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateTo)) {
            return false;
        }
        
        // Check if dates are valid
        $fromTime = strtotime($dateFrom);
        $toTime = strtotime($dateTo);
        
        if ($fromTime === false || $toTime === false) {
            return false;
        }
        
        // Check if from date is not after to date
        if ($fromTime > $toTime) {
            return false;
        }
        
        // Check if date range is not too large (max 1 year)
        $daysDiff = ($toTime - $fromTime) / (24 * 60 * 60);
        if ($daysDiff > ANALYTICS_MAX_DATE_RANGE_DAYS) {
            return false;
        }
        
        // Check if dates are not too far in the past
        $minTime = strtotime(ANALYTICS_MIN_DATE);
        if ($fromTime < $minTime) {
            return false;
        }
        
        return true;
    }
}
?>