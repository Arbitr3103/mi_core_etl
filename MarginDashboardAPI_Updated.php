<?php
/**
 * Обновленный API класс для работы с данными маржинальности с поддержкой dim_sources
 */

class MarginDashboardAPI_Updated {
    private $pdo;
    private $logFile = 'margin_api.log';
    
    public function __construct($host, $dbname, $username, $password) {
        try {
            $this->pdo = new PDO(
                "mysql:host=$host;dbname=$dbname;charset=utf8mb4",
                $username,
                $password,
                [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES => false
                ]
            );
        } catch (PDOException $e) {
            throw new Exception("Ошибка подключения к БД: " . $e->getMessage());
        }
    }
    
    /**
     * Логирование для отладки
     */
    private function log($message) {
        $timestamp = date('Y-m-d H:i:s');
        file_put_contents($this->logFile, "[$timestamp] $message\n", FILE_APPEND);
    }
    
    /**
     * Получить статистику по маркетплейсам
     */
    public function getMarketplaceStats($startDate, $endDate, $clientId = null) {
        $this->log("Getting marketplace stats: $startDate to $endDate, client: $clientId");
        
        $sql = "
            SELECT 
                s.code as marketplace_code,
                s.name as marketplace_name,
                s.description as marketplace_description,
                COUNT(DISTINCT fo.order_id) as total_orders,
                ROUND(SUM(fo.qty * fo.price), 2) as total_revenue,
                ROUND(SUM(fo.qty * COALESCE(fo.cost_price, 0)), 2) as total_cogs,
                ROUND(SUM(fo.qty * fo.price * 0.15), 2) as total_commission,
                ROUND(SUM(fo.qty * fo.price * 0.05), 2) as total_shipping,
                ROUND(SUM(fo.qty * fo.price * 0.02), 2) as total_other_expenses,
                ROUND(SUM(fo.qty * fo.price) - SUM(fo.qty * COALESCE(fo.cost_price, 0)) - SUM(fo.qty * fo.price * 0.22), 2) as total_profit,
                CASE 
                    WHEN SUM(fo.qty * fo.price) > 0 
                    THEN ROUND((SUM(fo.qty * fo.price) - SUM(fo.qty * COALESCE(fo.cost_price, 0)) - SUM(fo.qty * fo.price * 0.22)) * 100.0 / SUM(fo.qty * fo.price), 2)
                    ELSE NULL 
                END as avg_margin_percent,
                ROUND(AVG(fo.price), 2) as avg_price,
                COUNT(DISTINCT fo.product_id) as unique_products
            FROM fact_orders fo
            JOIN dim_sources s ON fo.source_id = s.id
            WHERE fo.order_date BETWEEN :start_date AND :end_date
        ";
        
        $params = [
            'start_date' => $startDate,
            'end_date' => $endDate
        ];
        
        if ($clientId) {
            $sql .= " AND fo.client_id = :client_id";
            $params['client_id'] = $clientId;
        }
        
        $sql .= " GROUP BY s.id, s.code, s.name, s.description ORDER BY total_revenue DESC";
        
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        
        $result = $stmt->fetchAll();
        $this->log("Marketplace stats result: " . json_encode($result));
        
        return $result;
    }
    
    /**
     * Получить статистику по конкретному маркетплейсу
     */
    public function getMarketplaceStatsByCode($marketplaceCode, $startDate, $endDate, $clientId = null) {
        $this->log("Getting stats for marketplace: $marketplaceCode, $startDate to $endDate");
        
        $sql = "
            SELECT 
                s.code as marketplace_code,
                s.name as marketplace_name,
                s.description as marketplace_description,
                COUNT(DISTINCT fo.order_id) as total_orders,
                ROUND(SUM(fo.qty * fo.price), 2) as total_revenue,
                ROUND(SUM(fo.qty * COALESCE(fo.cost_price, 0)), 2) as total_cogs,
                ROUND(SUM(fo.qty * fo.price * 0.15), 2) as total_commission,
                ROUND(SUM(fo.qty * fo.price * 0.05), 2) as total_shipping,
                ROUND(SUM(fo.qty * fo.price * 0.02), 2) as total_other_expenses,
                ROUND(SUM(fo.qty * fo.price) - SUM(fo.qty * COALESCE(fo.cost_price, 0)) - SUM(fo.qty * fo.price * 0.22), 2) as total_profit,
                CASE 
                    WHEN SUM(fo.qty * fo.price) > 0 
                    THEN ROUND((SUM(fo.qty * fo.price) - SUM(fo.qty * COALESCE(fo.cost_price, 0)) - SUM(fo.qty * fo.price * 0.22)) * 100.0 / SUM(fo.qty * fo.price), 2)
                    ELSE NULL 
                END as avg_margin_percent,
                ROUND(AVG(fo.price), 2) as avg_price,
                COUNT(DISTINCT fo.product_id) as unique_products,
                MIN(fo.order_date) as period_start,
                MAX(fo.order_date) as period_end
            FROM fact_orders fo
            JOIN dim_sources s ON fo.source_id = s.id
            WHERE fo.order_date BETWEEN :start_date AND :end_date
                AND s.code = :marketplace_code
        ";
        
        $params = [
            'start_date' => $startDate,
            'end_date' => $endDate,
            'marketplace_code' => strtoupper($marketplaceCode)
        ];
        
        if ($clientId) {
            $sql .= " AND fo.client_id = :client_id";
            $params['client_id'] = $clientId;
        }
        
        $sql .= " GROUP BY s.id, s.code, s.name, s.description";
        
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        
        $result = $stmt->fetch();
        $this->log("Marketplace specific stats result: " . json_encode($result));
        
        return $result;
    }
    
    /**
     * Получить топ товаров по маркетплейсу
     */
    public function getTopProductsByMarketplace($marketplaceCode, $startDate, $endDate, $limit = 10, $clientId = null) {
        $this->log("Getting top products for marketplace: $marketplaceCode");
        
        $sql = "
            SELECT 
                fo.product_id,
                dp.product_name,
                fo.sku,
                COUNT(DISTINCT fo.order_id) as orders_count,
                SUM(fo.qty) as total_qty,
                ROUND(SUM(fo.qty * fo.price), 2) as total_revenue,
                ROUND(SUM(fo.qty * COALESCE(fo.cost_price, 0)), 2) as total_cogs,
                ROUND(SUM(fo.qty * fo.price) - SUM(fo.qty * COALESCE(fo.cost_price, 0)) - SUM(fo.qty * fo.price * 0.22), 2) as total_profit,
                CASE 
                    WHEN SUM(fo.qty * fo.price) > 0 
                    THEN ROUND((SUM(fo.qty * fo.price) - SUM(fo.qty * COALESCE(fo.cost_price, 0)) - SUM(fo.qty * fo.price * 0.22)) * 100.0 / SUM(fo.qty * fo.price), 2)
                    ELSE NULL 
                END as margin_percent,
                ROUND(AVG(fo.price), 2) as avg_price
            FROM fact_orders fo
            JOIN dim_sources s ON fo.source_id = s.id
            LEFT JOIN dim_products dp ON fo.product_id = dp.id
            WHERE fo.order_date BETWEEN :start_date AND :end_date
                AND s.code = :marketplace_code
        ";
        
        $params = [
            'start_date' => $startDate,
            'end_date' => $endDate,
            'marketplace_code' => strtoupper($marketplaceCode),
            'limit' => $limit
        ];
        
        if ($clientId) {
            $sql .= " AND fo.client_id = :client_id";
            $params['client_id'] = $clientId;
        }
        
        $sql .= "
            GROUP BY fo.product_id, dp.product_name, fo.sku
            ORDER BY total_revenue DESC
            LIMIT :limit
        ";
        
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        
        return $stmt->fetchAll();
    }
    
    /**
     * Получить данные по дням для графика по маркетплейсу
     */
    public function getDailyMarginChartByMarketplace($marketplaceCode, $startDate, $endDate, $clientId = null) {
        $sql = "
            SELECT 
                fo.order_date as metric_date,
                ROUND(SUM(fo.qty * fo.price), 2) as revenue,
                ROUND(SUM(fo.qty * fo.price) - SUM(fo.qty * COALESCE(fo.cost_price, 0)) - SUM(fo.qty * fo.price * 0.22), 2) as profit,
                CASE 
                    WHEN SUM(fo.qty * fo.price) > 0 
                    THEN ROUND((SUM(fo.qty * fo.price) - SUM(fo.qty * COALESCE(fo.cost_price, 0)) - SUM(fo.qty * fo.price * 0.22)) * 100.0 / SUM(fo.qty * fo.price), 2)
                    ELSE NULL 
                END as margin_percent,
                COUNT(DISTINCT fo.order_id) as orders_count
            FROM fact_orders fo
            JOIN dim_sources s ON fo.source_id = s.id
            WHERE fo.order_date BETWEEN :start_date AND :end_date
                AND s.code = :marketplace_code
        ";
        
        $params = [
            'start_date' => $startDate,
            'end_date' => $endDate,
            'marketplace_code' => strtoupper($marketplaceCode)
        ];
        
        if ($clientId) {
            $sql .= " AND fo.client_id = :client_id";
            $params['client_id'] = $clientId;
        }
        
        $sql .= " GROUP BY fo.order_date ORDER BY fo.order_date";
        
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        
        return $stmt->fetchAll();
    }
    
    /**
     * Получить список доступных маркетплейсов
     */
    public function getAvailableMarketplaces() {
        $sql = "SELECT id, code, name, description FROM dim_sources WHERE is_active = 1 ORDER BY name";
        $stmt = $this->pdo->query($sql);
        return $stmt->fetchAll();
    }
    
    /**
     * Сравнить маркетплейсы за период
     */
    public function compareMarketplaces($startDate, $endDate, $clientId = null) {
        $marketplaces = $this->getMarketplaceStats($startDate, $endDate, $clientId);
        
        $comparison = [
            'period' => ['start' => $startDate, 'end' => $endDate],
            'marketplaces' => $marketplaces,
            'totals' => [
                'total_orders' => array_sum(array_column($marketplaces, 'total_orders')),
                'total_revenue' => array_sum(array_column($marketplaces, 'total_revenue')),
                'total_profit' => array_sum(array_column($marketplaces, 'total_profit'))
            ]
        ];
        
        // Добавляем проценты от общего
        foreach ($comparison['marketplaces'] as &$marketplace) {
            if ($comparison['totals']['total_revenue'] > 0) {
                $marketplace['revenue_share'] = round(
                    ($marketplace['total_revenue'] / $comparison['totals']['total_revenue']) * 100, 2
                );
            }
            if ($comparison['totals']['total_orders'] > 0) {
                $marketplace['orders_share'] = round(
                    ($marketplace['total_orders'] / $comparison['totals']['total_orders']) * 100, 2
                );
            }
        }
        
        return $comparison;
    }
}