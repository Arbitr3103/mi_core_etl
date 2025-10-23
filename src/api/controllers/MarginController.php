<?php
/**
 * MarginAPI - PHP класс для работы с системой маржинальности
 * 
 * Предоставляет удобные методы для получения данных о маржинальности
 * из базы данных mi_core_db
 * 
 * @version 1.0
 * @author ETL System
 */

class MarginDatabase {
    private $host = '178.72.129.61';
    private $dbname = 'mi_core_db';
    private $username = 'your_username';  // Замените на реальные данные
    private $password = 'your_password';  // Замените на реальные данные
    private $pdo;
    
    public function __construct() {
        try {
            $dsn = "mysql:host={$this->host};dbname={$this->dbname};charset=utf8mb4";
            $this->pdo = new PDO($dsn, $this->username, $this->password, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4"
            ]);
        } catch (PDOException $e) {
            throw new Exception("Ошибка подключения к БД: " . $e->getMessage());
        }
    }
    
    public function getConnection() {
        return $this->pdo;
    }
}

class MarginAPI {
    private $db;
    
    public function __construct() {
        $this->db = new MarginDatabase();
    }
    
    /**
     * Получить сводную маржинальность за период
     * 
     * @param string $startDate - дата начала (YYYY-MM-DD)
     * @param string $endDate - дата окончания (YYYY-MM-DD)
     * @param int|null $clientId - ID клиента (null для всех)
     * @return array
     */
    public function getSummaryMargin($startDate, $endDate, $clientId = null) {
        $sql = "
            SELECT 
                SUM(orders_cnt) as total_orders,
                SUM(revenue_sum) as total_revenue,
                SUM(COALESCE(cogs_sum, 0)) as total_cogs,
                SUM(COALESCE(profit_sum, 0)) as total_profit,
                CASE 
                    WHEN SUM(revenue_sum) > 0 THEN 
                        ROUND((SUM(COALESCE(profit_sum, 0)) / SUM(revenue_sum)) * 100, 2)
                    ELSE 0 
                END as margin_percent,
                COUNT(DISTINCT metric_date) as days_count,
                MIN(metric_date) as first_date,
                MAX(metric_date) as last_date
            FROM metrics_daily 
            WHERE metric_date BETWEEN :start_date AND :end_date
        ";
        
        $params = [
            'start_date' => $startDate,
            'end_date' => $endDate
        ];
        
        if ($clientId !== null) {
            $sql .= " AND client_id = :client_id";
            $params['client_id'] = $clientId;
        }
        
        $stmt = $this->db->getConnection()->prepare($sql);
        $stmt->execute($params);
        
        $result = $stmt->fetch();
        
        return [
            'period' => [
                'start_date' => $startDate,
                'end_date' => $endDate,
                'days_count' => (int)$result['days_count'],
                'first_date' => $result['first_date'],
                'last_date' => $result['last_date']
            ],
            'totals' => [
                'orders' => (int)$result['total_orders'],
                'revenue' => (float)$result['total_revenue'],
                'cogs' => (float)$result['total_cogs'],
                'profit' => (float)$result['total_profit'],
                'margin_percent' => (float)$result['margin_percent']
            ],
            'averages' => [
                'daily_revenue' => $result['days_count'] > 0 ? 
                    round($result['total_revenue'] / $result['days_count'], 2) : 0,
                'daily_profit' => $result['days_count'] > 0 ? 
                    round($result['total_profit'] / $result['days_count'], 2) : 0,
                'daily_orders' => $result['days_count'] > 0 ? 
                    round($result['total_orders'] / $result['days_count'], 0) : 0
            ]
        ];
    }
    
    /**
     * Получить маржинальность по дням
     * 
     * @param string $startDate
     * @param string $endDate
     * @param int|null $clientId
     * @return array
     */
    public function getDailyMargins($startDate, $endDate, $clientId = null) {
        $sql = "
            SELECT 
                metric_date,
                SUM(orders_cnt) as orders,
                SUM(revenue_sum) as revenue,
                SUM(COALESCE(cogs_sum, 0)) as cogs,
                SUM(COALESCE(profit_sum, 0)) as profit,
                CASE 
                    WHEN SUM(revenue_sum) > 0 THEN 
                        ROUND((SUM(COALESCE(profit_sum, 0)) / SUM(revenue_sum)) * 100, 2)
                    ELSE 0 
                END as margin_percent
            FROM metrics_daily 
            WHERE metric_date BETWEEN :start_date AND :end_date
        ";
        
        $params = [
            'start_date' => $startDate,
            'end_date' => $endDate
        ];
        
        if ($clientId !== null) {
            $sql .= " AND client_id = :client_id";
            $params['client_id'] = $clientId;
        }
        
        $sql .= " GROUP BY metric_date ORDER BY metric_date DESC";
        
        $stmt = $this->db->getConnection()->prepare($sql);
        $stmt->execute($params);
        
        $results = $stmt->fetchAll();
        
        return array_map(function($row) {
            return [
                'date' => $row['metric_date'],
                'orders' => (int)$row['orders'],
                'revenue' => (float)$row['revenue'],
                'cogs' => (float)$row['cogs'],
                'profit' => (float)$row['profit'],
                'margin_percent' => (float)$row['margin_percent']
            ];
        }, $results);
    }
    
    /**
     * Получить топ товаров по маржинальности
     * 
     * @param int $limit
     * @param string|null $startDate
     * @param string|null $endDate
     * @param float $minRevenue - минимальная выручка для фильтрации
     * @return array
     */
    public function getTopProductsByMargin($limit = 20, $startDate = null, $endDate = null, $minRevenue = 0) {
        $sql = "
            SELECT 
                dp.sku_ozon,
                dp.sku_wb,
                dp.product_name,
                dp.cost_price,
                SUM(fo.qty) as total_qty,
                SUM(CASE WHEN fo.transaction_type = 'продажа' THEN fo.qty * fo.price ELSE 0 END) as revenue,
                SUM(CASE WHEN fo.transaction_type = 'продажа' THEN fo.qty * COALESCE(dp.cost_price, 0) ELSE 0 END) as cogs,
                SUM(CASE WHEN fo.transaction_type = 'продажа' THEN fo.qty * fo.price ELSE 0 END) - 
                SUM(CASE WHEN fo.transaction_type = 'продажа' THEN fo.qty * COALESCE(dp.cost_price, 0) ELSE 0 END) as profit,
                CASE 
                    WHEN SUM(CASE WHEN fo.transaction_type = 'продажа' THEN fo.qty * fo.price ELSE 0 END) > 0 THEN
                        ROUND(((SUM(CASE WHEN fo.transaction_type = 'продажа' THEN fo.qty * fo.price ELSE 0 END) - 
                               SUM(CASE WHEN fo.transaction_type = 'продажа' THEN fo.qty * COALESCE(dp.cost_price, 0) ELSE 0 END)) / 
                               SUM(CASE WHEN fo.transaction_type = 'продажа' THEN fo.qty * fo.price ELSE 0 END)) * 100, 2)
                    ELSE 0
                END as margin_percent
            FROM fact_orders fo
            LEFT JOIN dim_products dp ON fo.sku = dp.sku_ozon OR fo.sku = dp.sku_wb
            WHERE fo.transaction_type = 'продажа'
        ";
        
        $params = [];
        
        if ($startDate && $endDate) {
            $sql .= " AND fo.order_date BETWEEN :start_date AND :end_date";
            $params['start_date'] = $startDate;
            $params['end_date'] = $endDate;
        }
        
        $sql .= "
            GROUP BY dp.sku_ozon, dp.sku_wb, dp.product_name, dp.cost_price
            HAVING revenue > :min_revenue
            ORDER BY margin_percent DESC, profit DESC
            LIMIT :limit
        ";
        
        $stmt = $this->db->getConnection()->prepare($sql);
        
        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value);
        }
        $stmt->bindValue('min_revenue', $minRevenue);
        $stmt->bindValue('limit', $limit, PDO::PARAM_INT);
        
        $stmt->execute();
        
        return array_map(function($row) {
            return [
                'sku_ozon' => $row['sku_ozon'],
                'sku_wb' => $row['sku_wb'],
                'product_name' => $row['product_name'],
                'cost_price' => (float)$row['cost_price'],
                'quantity' => (int)$row['total_qty'],
                'revenue' => (float)$row['revenue'],
                'cogs' => (float)$row['cogs'],
                'profit' => (float)$row['profit'],
                'margin_percent' => (float)$row['margin_percent']
            ];
        }, $stmt->fetchAll());
    }
    
    /**
     * Получить товары с низкой маржинальностью
     * 
     * @param float $marginThreshold - порог маржинальности (%)
     * @param float $minRevenue - минимальная выручка для фильтрации
     * @param int $limit
     * @return array
     */
    public function getLowMarginProducts($marginThreshold = 15.0, $minRevenue = 1000.0, $limit = 20) {
        $sql = "
            SELECT 
                dp.sku_ozon,
                dp.sku_wb,
                dp.product_name,
                dp.cost_price,
                SUM(fo.qty) as total_qty,
                SUM(CASE WHEN fo.transaction_type = 'продажа' THEN fo.qty * fo.price ELSE 0 END) as revenue,
                SUM(CASE WHEN fo.transaction_type = 'продажа' THEN fo.qty * COALESCE(dp.cost_price, 0) ELSE 0 END) as cogs,
                SUM(CASE WHEN fo.transaction_type = 'продажа' THEN fo.qty * fo.price ELSE 0 END) - 
                SUM(CASE WHEN fo.transaction_type = 'продажа' THEN fo.qty * COALESCE(dp.cost_price, 0) ELSE 0 END) as profit,
                CASE 
                    WHEN SUM(CASE WHEN fo.transaction_type = 'продажа' THEN fo.qty * fo.price ELSE 0 END) > 0 THEN
                        ROUND(((SUM(CASE WHEN fo.transaction_type = 'продажа' THEN fo.qty * fo.price ELSE 0 END) - 
                               SUM(CASE WHEN fo.transaction_type = 'продажа' THEN fo.qty * COALESCE(dp.cost_price, 0) ELSE 0 END)) / 
                               SUM(CASE WHEN fo.transaction_type = 'продажа' THEN fo.qty * fo.price ELSE 0 END)) * 100, 2)
                    ELSE 0
                END as margin_percent
            FROM fact_orders fo
            LEFT JOIN dim_products dp ON fo.sku = dp.sku_ozon OR fo.sku = dp.sku_wb
            WHERE fo.transaction_type = 'продажа' AND dp.cost_price IS NOT NULL
            GROUP BY dp.sku_ozon, dp.sku_wb, dp.product_name, dp.cost_price
            HAVING revenue > :min_revenue AND margin_percent < :margin_threshold
            ORDER BY margin_percent ASC, revenue DESC
            LIMIT :limit
        ";
        
        $stmt = $this->db->getConnection()->prepare($sql);
        $stmt->bindValue('margin_threshold', $marginThreshold);
        $stmt->bindValue('min_revenue', $minRevenue);
        $stmt->bindValue('limit', $limit, PDO::PARAM_INT);
        
        $stmt->execute();
        
        return array_map(function($row) {
            return [
                'sku_ozon' => $row['sku_ozon'],
                'sku_wb' => $row['sku_wb'],
                'product_name' => $row['product_name'],
                'cost_price' => (float)$row['cost_price'],
                'quantity' => (int)$row['total_qty'],
                'revenue' => (float)$row['revenue'],
                'cogs' => (float)$row['cogs'],
                'profit' => (float)$row['profit'],
                'margin_percent' => (float)$row['margin_percent'],
                'warning' => 'Низкая маржинальность'
            ];
        }, $stmt->fetchAll());
    }
    
    /**
     * Получить статистику покрытия товаров себестоимостью
     * 
     * @return array
     */
    public function getCostCoverageStats() {
        $sql = "
            SELECT 
                COUNT(*) as total_products,
                COUNT(CASE WHEN cost_price IS NOT NULL AND cost_price > 0 THEN 1 END) as products_with_cost,
                ROUND((COUNT(CASE WHEN cost_price IS NOT NULL AND cost_price > 0 THEN 1 END) / COUNT(*)) * 100, 2) as coverage_percent,
                AVG(CASE WHEN cost_price IS NOT NULL AND cost_price > 0 THEN cost_price END) as avg_cost_price,
                MIN(CASE WHEN cost_price IS NOT NULL AND cost_price > 0 THEN cost_price END) as min_cost_price,
                MAX(CASE WHEN cost_price IS NOT NULL AND cost_price > 0 THEN cost_price END) as max_cost_price
            FROM dim_products
        ";
        
        $stmt = $this->db->getConnection()->prepare($sql);
        $stmt->execute();
        
        $result = $stmt->fetch();
        
        return [
            'total_products' => (int)$result['total_products'],
            'products_with_cost' => (int)$result['products_with_cost'],
            'products_without_cost' => (int)$result['total_products'] - (int)$result['products_with_cost'],
            'coverage_percent' => (float)$result['coverage_percent'],
            'cost_price_stats' => [
                'average' => (float)$result['avg_cost_price'],
                'minimum' => (float)$result['min_cost_price'],
                'maximum' => (float)$result['max_cost_price']
            ]
        ];
    }
    
    /**
     * Получить маржинальность по клиентам
     * 
     * @param string $startDate
     * @param string $endDate
     * @return array
     */
    public function getMarginsByClient($startDate, $endDate) {
        $sql = "
            SELECT 
                c.name as client_name,
                c.id as client_id,
                SUM(m.orders_cnt) as total_orders,
                SUM(m.revenue_sum) as total_revenue,
                SUM(COALESCE(m.cogs_sum, 0)) as total_cogs,
                SUM(COALESCE(m.profit_sum, 0)) as total_profit,
                CASE 
                    WHEN SUM(m.revenue_sum) > 0 THEN 
                        ROUND((SUM(COALESCE(m.profit_sum, 0)) / SUM(m.revenue_sum)) * 100, 2)
                    ELSE 0 
                END as margin_percent
            FROM metrics_daily m
            JOIN clients c ON m.client_id = c.id
            WHERE m.metric_date BETWEEN :start_date AND :end_date
            GROUP BY c.id, c.name
            ORDER BY total_revenue DESC
        ";
        
        $stmt = $this->db->getConnection()->prepare($sql);
        $stmt->execute([
            'start_date' => $startDate,
            'end_date' => $endDate
        ]);
        
        return array_map(function($row) {
            return [
                'client_id' => (int)$row['client_id'],
                'client_name' => $row['client_name'],
                'orders' => (int)$row['total_orders'],
                'revenue' => (float)$row['total_revenue'],
                'cogs' => (float)$row['total_cogs'],
                'profit' => (float)$row['total_profit'],
                'margin_percent' => (float)$row['margin_percent']
            ];
        }, $stmt->fetchAll());
    }
    
    /**
     * Форматировать число как валюту
     * 
     * @param float $amount
     * @param string $currency
     * @return string
     */
    public function formatCurrency($amount, $currency = 'руб.') {
        return number_format($amount, 2, ',', ' ') . ' ' . $currency;
    }
    
    /**
     * Форматировать процент
     * 
     * @param float $percent
     * @return string
     */
    public function formatPercent($percent) {
        return number_format($percent, 1, ',', ' ') . '%';
    }
}

// Пример использования:
/*
try {
    $api = new MarginAPI();
    
    // Получить сводную маржинальность за текущий месяц
    $summary = $api->getSummaryMargin(date('Y-m-01'), date('Y-m-d'));
    echo "Общая маржинальность: " . $api->formatPercent($summary['totals']['margin_percent']) . "\n";
    echo "Прибыль: " . $api->formatCurrency($summary['totals']['profit']) . "\n";
    
    // Получить топ-10 товаров по марже
    $topProducts = $api->getTopProductsByMargin(10);
    foreach ($topProducts as $product) {
        echo $product['sku_ozon'] . ": " . $api->formatPercent($product['margin_percent']) . "\n";
    }
    
} catch (Exception $e) {
    echo "Ошибка: " . $e->getMessage() . "\n";
}
*/
?>
