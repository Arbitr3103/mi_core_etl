<?php
/**
 * MarketplaceQueryBuilder Class - Построитель SQL запросов с фильтрацией по маркетплейсам
 * 
 * Предоставляет методы для построения оптимизированных SQL запросов
 * с поддержкой фильтрации данных по маркетплейсам
 * 
 * @version 1.0
 * @author Manhattan System
 */

require_once 'MarketplaceDetector.php';

class MarketplaceQueryBuilder {
    private $pdo;
    private $marketplaceDetector;
    
    /**
     * Конструктор класса
     * 
     * @param PDO $pdo - подключение к базе данных
     */
    public function __construct(PDO $pdo) {
        $this->pdo = $pdo;
        $this->marketplaceDetector = new MarketplaceDetector($pdo);
    }
    
    /**
     * Построить базовый SELECT запрос для получения данных заказов с фильтрацией по маркетплейсу
     * 
     * @param string|null $marketplace - маркетплейс для фильтрации
     * @param array $additionalConditions - дополнительные условия WHERE
     * @param array $additionalParams - дополнительные параметры запроса
     * @return array массив с SQL запросом и параметрами
     */
    public function buildOrdersQuery($marketplace = null, $additionalConditions = [], $additionalParams = []) {
        $sql = "
            SELECT 
                fo.id,
                fo.order_id,
                fo.transaction_type,
                fo.sku,
                fo.qty,
                fo.price,
                fo.order_date,
                fo.cost_price,
                fo.client_id,
                fo.source_id,
                s.code as source_code,
                s.name as source_name,
                dp.id as product_id,
                dp.sku_ozon,
                dp.sku_wb,
                dp.product_name,
                c.name as client_name
            FROM fact_orders fo
            JOIN sources s ON fo.source_id = s.id
            JOIN clients c ON fo.client_id = c.id
            LEFT JOIN dim_products dp ON fo.product_id = dp.id
            WHERE 1=1
        ";
        
        $params = [];
        
        // Добавляем фильтр по маркетплейсу
        if ($marketplace !== null) {
            $marketplaceFilter = $this->marketplaceDetector->buildMarketplaceFilter($marketplace, 's', 'dp', 'fo');
            $sql .= " AND ({$marketplaceFilter['condition']})";
            $params = array_merge($params, $marketplaceFilter['params']);
        }
        
        // Добавляем дополнительные условия
        foreach ($additionalConditions as $condition) {
            $sql .= " AND ({$condition})";
        }
        
        // Добавляем дополнительные параметры
        $params = array_merge($params, $additionalParams);
        
        return ['sql' => $sql, 'params' => $params];
    }
    
    /**
     * Построить запрос для получения агрегированных данных по маржинальности с фильтрацией по маркетплейсу
     * 
     * @param string $startDate - начальная дата периода
     * @param string $endDate - конечная дата периода
     * @param string|null $marketplace - маркетплейс для фильтрации
     * @param int|null $clientId - ID клиента
     * @return array массив с SQL запросом и параметрами
     */
    public function buildMarginSummaryQuery($startDate, $endDate, $marketplace = null, $clientId = null) {
        $sql = "
            SELECT 
                COUNT(DISTINCT fo.order_id) as total_orders,
                ROUND(SUM(fo.qty * fo.price), 2) as total_revenue,
                ROUND(SUM(fo.qty * COALESCE(fo.cost_price, dp.cost_price, 0)), 2) as total_cogs,
                ROUND(SUM(fo.qty * fo.price) - SUM(fo.qty * COALESCE(fo.cost_price, dp.cost_price, 0)), 2) as total_profit,
                CASE 
                    WHEN SUM(fo.qty * fo.price) > 0 
                    THEN ROUND((SUM(fo.qty * fo.price) - SUM(fo.qty * COALESCE(fo.cost_price, dp.cost_price, 0))) * 100.0 / SUM(fo.qty * fo.price), 2)
                    ELSE NULL 
                END as avg_margin_percent,
                COUNT(DISTINCT fo.product_id) as unique_products,
                MIN(fo.order_date) as period_start,
                MAX(fo.order_date) as period_end
            FROM fact_orders fo
            JOIN sources s ON fo.source_id = s.id
            LEFT JOIN dim_products dp ON fo.product_id = dp.id
            WHERE fo.order_date BETWEEN :start_date AND :end_date
                AND fo.transaction_type IN ('продажа', 'sale', 'order')
        ";
        
        $params = [
            'start_date' => $startDate,
            'end_date' => $endDate
        ];
        
        // Добавляем фильтр по маркетплейсу
        if ($marketplace !== null) {
            $marketplaceFilter = $this->marketplaceDetector->buildMarketplaceFilter($marketplace, 's', 'dp', 'fo');
            $sql .= " AND ({$marketplaceFilter['condition']})";
            $params = array_merge($params, $marketplaceFilter['params']);
        }
        
        // Добавляем фильтр по клиенту
        if ($clientId !== null) {
            $sql .= " AND fo.client_id = :client_id";
            $params['client_id'] = $clientId;
        }
        
        return ['sql' => $sql, 'params' => $params];
    }
    
    /**
     * Построить запрос для получения данных по дням с фильтрацией по маркетплейсу
     * 
     * @param string $startDate - начальная дата периода
     * @param string $endDate - конечная дата периода
     * @param string|null $marketplace - маркетплейс для фильтрации
     * @param int|null $clientId - ID клиента
     * @return array массив с SQL запросом и параметрами
     */
    public function buildDailyChartQuery($startDate, $endDate, $marketplace = null, $clientId = null) {
        $sql = "
            SELECT 
                fo.order_date as metric_date,
                COUNT(DISTINCT fo.order_id) as orders_count,
                ROUND(SUM(fo.qty * fo.price), 2) as revenue,
                ROUND(SUM(fo.qty * COALESCE(fo.cost_price, dp.cost_price, 0)), 2) as cogs,
                ROUND(SUM(fo.qty * fo.price) - SUM(fo.qty * COALESCE(fo.cost_price, dp.cost_price, 0)), 2) as profit,
                CASE 
                    WHEN SUM(fo.qty * fo.price) > 0 
                    THEN ROUND((SUM(fo.qty * fo.price) - SUM(fo.qty * COALESCE(fo.cost_price, dp.cost_price, 0))) * 100.0 / SUM(fo.qty * fo.price), 2)
                    ELSE NULL 
                END as margin_percent
            FROM fact_orders fo
            JOIN sources s ON fo.source_id = s.id
            LEFT JOIN dim_products dp ON fo.product_id = dp.id
            WHERE fo.order_date BETWEEN :start_date AND :end_date
                AND fo.transaction_type IN ('продажа', 'sale', 'order')
        ";
        
        $params = [
            'start_date' => $startDate,
            'end_date' => $endDate
        ];
        
        // Добавляем фильтр по маркетплейсу
        if ($marketplace !== null) {
            $marketplaceFilter = $this->marketplaceDetector->buildMarketplaceFilter($marketplace, 's', 'dp', 'fo');
            $sql .= " AND ({$marketplaceFilter['condition']})";
            $params = array_merge($params, $marketplaceFilter['params']);
        }
        
        // Добавляем фильтр по клиенту
        if ($clientId !== null) {
            $sql .= " AND fo.client_id = :client_id";
            $params['client_id'] = $clientId;
        }
        
        $sql .= " GROUP BY fo.order_date ORDER BY fo.order_date";
        
        return ['sql' => $sql, 'params' => $params];
    }
    
    /**
     * Построить запрос для получения топ товаров с фильтрацией по маркетплейсу
     * 
     * @param string|null $marketplace - маркетплейс для фильтрации
     * @param int $limit - количество товаров в топе
     * @param string|null $startDate - начальная дата периода
     * @param string|null $endDate - конечная дата периода
     * @param float $minRevenue - минимальная выручка для включения в топ
     * @param int|null $clientId - ID клиента
     * @return array массив с SQL запросом и параметрами
     */
    public function buildTopProductsQuery($marketplace = null, $limit = 10, $startDate = null, $endDate = null, $minRevenue = 0, $clientId = null) {
        $sql = "
            SELECT 
                fo.product_id,
                dp.product_name,
                CASE 
                    WHEN :marketplace = 'ozon' THEN dp.sku_ozon
                    WHEN :marketplace = 'wildberries' THEN dp.sku_wb
                    ELSE COALESCE(dp.sku_ozon, dp.sku_wb, fo.sku)
                END as display_sku,
                dp.sku_ozon,
                dp.sku_wb,
                COUNT(DISTINCT fo.order_id) as orders_count,
                SUM(fo.qty) as total_qty,
                ROUND(SUM(fo.qty * fo.price), 2) as total_revenue,
                ROUND(SUM(fo.qty * COALESCE(fo.cost_price, dp.cost_price, 0)), 2) as total_cogs,
                ROUND(SUM(fo.qty * fo.price) - SUM(fo.qty * COALESCE(fo.cost_price, dp.cost_price, 0)), 2) as total_profit,
                CASE 
                    WHEN SUM(fo.qty * fo.price) > 0 
                    THEN ROUND((SUM(fo.qty * fo.price) - SUM(fo.qty * COALESCE(fo.cost_price, dp.cost_price, 0))) * 100.0 / SUM(fo.qty * fo.price), 2)
                    ELSE NULL 
                END as margin_percent,
                ROUND(AVG(fo.price), 2) as avg_price
            FROM fact_orders fo
            JOIN sources s ON fo.source_id = s.id
            LEFT JOIN dim_products dp ON fo.product_id = dp.id
            WHERE fo.transaction_type IN ('продажа', 'sale', 'order')
        ";
        
        $params = [
            'marketplace' => $marketplace,
            'limit' => $limit,
            'min_revenue' => $minRevenue
        ];
        
        // Добавляем фильтр по маркетплейсу
        if ($marketplace !== null) {
            $marketplaceFilter = $this->marketplaceDetector->buildMarketplaceFilter($marketplace, 's', 'dp', 'fo');
            $sql .= " AND ({$marketplaceFilter['condition']})";
            $params = array_merge($params, $marketplaceFilter['params']);
        }
        
        // Добавляем фильтр по датам
        if ($startDate !== null && $endDate !== null) {
            $sql .= " AND fo.order_date BETWEEN :start_date AND :end_date";
            $params['start_date'] = $startDate;
            $params['end_date'] = $endDate;
        }
        
        // Добавляем фильтр по клиенту
        if ($clientId !== null) {
            $sql .= " AND fo.client_id = :client_id";
            $params['client_id'] = $clientId;
        }
        
        $sql .= "
            GROUP BY fo.product_id, dp.product_name, dp.sku_ozon, dp.sku_wb
            HAVING total_revenue >= :min_revenue
            ORDER BY total_revenue DESC
            LIMIT :limit
        ";
        
        return ['sql' => $sql, 'params' => $params];
    }
    
    /**
     * Построить запрос для сравнения данных между маркетплейсами
     * 
     * @param string $startDate - начальная дата периода
     * @param string $endDate - конечная дата периода
     * @param int|null $clientId - ID клиента
     * @return array массив с SQL запросом и параметрами
     */
    public function buildMarketplaceComparisonQuery($startDate, $endDate, $clientId = null) {
        $sql = "
            SELECT 
                s.code as source_code,
                s.name as source_name,
                COUNT(DISTINCT fo.order_id) as orders_count,
                SUM(fo.qty) as total_qty,
                ROUND(SUM(fo.qty * fo.price), 2) as total_revenue,
                ROUND(SUM(fo.qty * COALESCE(fo.cost_price, dp.cost_price, 0)), 2) as total_cogs,
                ROUND(SUM(fo.qty * fo.price) - SUM(fo.qty * COALESCE(fo.cost_price, dp.cost_price, 0)), 2) as total_profit,
                CASE 
                    WHEN SUM(fo.qty * fo.price) > 0 
                    THEN ROUND((SUM(fo.qty * fo.price) - SUM(fo.qty * COALESCE(fo.cost_price, dp.cost_price, 0))) * 100.0 / SUM(fo.qty * fo.price), 2)
                    ELSE NULL 
                END as margin_percent,
                COUNT(DISTINCT fo.product_id) as unique_products,
                ROUND(AVG(fo.price), 2) as avg_order_value
            FROM fact_orders fo
            JOIN sources s ON fo.source_id = s.id
            LEFT JOIN dim_products dp ON fo.product_id = dp.id
            WHERE fo.order_date BETWEEN :start_date AND :end_date
                AND fo.transaction_type IN ('продажа', 'sale', 'order')
        ";
        
        $params = [
            'start_date' => $startDate,
            'end_date' => $endDate
        ];
        
        // Добавляем фильтр по клиенту
        if ($clientId !== null) {
            $sql .= " AND fo.client_id = :client_id";
            $params['client_id'] = $clientId;
        }
        
        $sql .= " GROUP BY s.id, s.code, s.name ORDER BY total_revenue DESC";
        
        return ['sql' => $sql, 'params' => $params];
    }
    
    /**
     * Выполнить запрос и вернуть результат
     * 
     * @param array $queryData - данные запроса из методов build*Query
     * @param bool $fetchAll - получить все записи (true) или одну (false)
     * @return array результат выполнения запроса
     */
    public function executeQuery($queryData, $fetchAll = true) {
        try {
            $stmt = $this->pdo->prepare($queryData['sql']);
            
            // Привязываем параметры с правильными типами
            foreach ($queryData['params'] as $key => $value) {
                if (is_int($value)) {
                    $stmt->bindValue($key, $value, PDO::PARAM_INT);
                } elseif (is_float($value)) {
                    $stmt->bindValue($key, $value, PDO::PARAM_STR); // Для DECIMAL полей
                } else {
                    $stmt->bindValue($key, $value, PDO::PARAM_STR);
                }
            }
            
            $stmt->execute();
            
            return $fetchAll ? $stmt->fetchAll(PDO::FETCH_ASSOC) : $stmt->fetch(PDO::FETCH_ASSOC);
            
        } catch (PDOException $e) {
            throw new Exception("Ошибка выполнения запроса с фильтрацией по маркетплейсу: " . $e->getMessage());
        }
    }
    
    /**
     * Получить оптимизированный запрос для создания индексов
     * 
     * @return array массив SQL команд для создания индексов
     */
    public function getOptimizationIndexes() {
        return [
            // Индекс для быстрой фильтрации по источнику
            "CREATE INDEX IF NOT EXISTS idx_sources_code ON sources(code)",
            "CREATE INDEX IF NOT EXISTS idx_sources_name ON sources(name)",
            
            // Составные индексы для fact_orders
            "CREATE INDEX IF NOT EXISTS idx_fact_orders_date_type ON fact_orders(order_date, transaction_type)",
            "CREATE INDEX IF NOT EXISTS idx_fact_orders_source_date ON fact_orders(source_id, order_date)",
            "CREATE INDEX IF NOT EXISTS idx_fact_orders_client_date ON fact_orders(client_id, order_date)",
            "CREATE INDEX IF NOT EXISTS idx_fact_orders_product_date ON fact_orders(product_id, order_date)",
            
            // Индексы для dim_products (если еще не созданы)
            "CREATE INDEX IF NOT EXISTS idx_dim_products_sku_ozon ON dim_products(sku_ozon)",
            "CREATE INDEX IF NOT EXISTS idx_dim_products_sku_wb ON dim_products(sku_wb)"
        ];
    }
    
    /**
     * Применить оптимизационные индексы к базе данных
     * 
     * @return array результат выполнения каждого индекса
     */
    public function applyOptimizationIndexes() {
        $indexes = $this->getOptimizationIndexes();
        $results = [];
        
        foreach ($indexes as $indexSql) {
            try {
                $this->pdo->exec($indexSql);
                $results[] = ['sql' => $indexSql, 'status' => 'success'];
            } catch (PDOException $e) {
                $results[] = ['sql' => $indexSql, 'status' => 'error', 'message' => $e->getMessage()];
            }
        }
        
        return $results;
    }
}
?>