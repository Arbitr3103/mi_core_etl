<?php
/**
 * API класс для работы с данными маржинальности
 * Готовый к использованию класс для PHP разработчиков
 */

class MarginDashboardAPI {
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
     * Получить общую статистику маржинальности за период
     */
    public function getMarginSummary($startDate, $endDate, $clientId = null) {
        $this->log("Getting margin summary: $startDate to $endDate, client: $clientId");
        
        $sql = "
            SELECT 
                COUNT(DISTINCT metric_date) as days_count,
                SUM(orders_cnt) as total_orders,
                ROUND(SUM(revenue_sum), 2) as total_revenue,
                ROUND(SUM(cogs_sum), 2) as total_cogs,
                ROUND(SUM(commission_sum), 2) as total_commission,
                ROUND(SUM(shipping_sum), 2) as total_shipping,
                ROUND(SUM(other_expenses_sum), 2) as total_other_expenses,
                ROUND(SUM(profit_sum), 2) as total_profit,
                CASE 
                    WHEN SUM(revenue_sum) > 0 
                    THEN ROUND(SUM(profit_sum) * 100.0 / SUM(revenue_sum), 2)
                    ELSE NULL 
                END as avg_margin_percent,
                MIN(metric_date) as period_start,
                MAX(metric_date) as period_end
            FROM metrics_daily 
            WHERE metric_date BETWEEN :start_date AND :end_date
        ";
        
        $params = [
            'start_date' => $startDate,
            'end_date' => $endDate
        ];
        
        if ($clientId) {
            $sql .= " AND client_id = :client_id";
            $params['client_id'] = $clientId;
        }
        
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        
        $result = $stmt->fetch();
        $this->log("Margin summary result: " . json_encode($result));
        
        return $result;
    }
    
    /**
     * Получить данные маржинальности по дням для графика
     */
    public function getDailyMarginChart($startDate, $endDate, $clientId = null) {
        $sql = "
            SELECT 
                metric_date,
                ROUND(SUM(revenue_sum), 2) as revenue,
                ROUND(SUM(profit_sum), 2) as profit,
                CASE 
                    WHEN SUM(revenue_sum) > 0 
                    THEN ROUND(SUM(profit_sum) * 100.0 / SUM(revenue_sum), 2)
                    ELSE NULL 
                END as margin_percent,
                SUM(orders_cnt) as orders_count
            FROM metrics_daily 
            WHERE metric_date BETWEEN :start_date AND :end_date
        ";
        
        $params = [
            'start_date' => $startDate,
            'end_date' => $endDate
        ];
        
        if ($clientId) {
            $sql .= " AND client_id = :client_id";
            $params['client_id'] = $clientId;
        }
        
        $sql .= " GROUP BY metric_date ORDER BY metric_date";
        
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        
        return $stmt->fetchAll();
    }
    
    /**
     * Получить топ дней по маржинальности
     */
    public function getTopMarginDays($startDate, $endDate, $limit = 10, $clientId = null) {
        $sql = "
            SELECT 
                metric_date,
                ROUND(SUM(revenue_sum), 2) as revenue,
                ROUND(SUM(profit_sum), 2) as profit,
                ROUND(SUM(profit_sum) * 100.0 / SUM(revenue_sum), 2) as margin_percent,
                SUM(orders_cnt) as orders_count
            FROM metrics_daily 
            WHERE metric_date BETWEEN :start_date AND :end_date
                AND revenue_sum > 0
        ";
        
        $params = [
            'start_date' => $startDate,
            'end_date' => $endDate,
            'limit' => $limit
        ];
        
        if ($clientId) {
            $sql .= " AND client_id = :client_id";
            $params['client_id'] = $clientId;
        }
        
        $sql .= " 
            GROUP BY metric_date 
            ORDER BY margin_percent DESC 
            LIMIT :limit
        ";
        
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        
        return $stmt->fetchAll();
    }
    
    /**
     * Получить структуру расходов (breakdown)
     */
    public function getCostBreakdown($startDate, $endDate, $clientId = null) {
        $sql = "
            SELECT 
                ROUND(SUM(revenue_sum), 2) as revenue,
                ROUND(SUM(cogs_sum), 2) as cogs,
                ROUND(SUM(commission_sum), 2) as commission,
                ROUND(SUM(shipping_sum), 2) as shipping,
                ROUND(SUM(other_expenses_sum), 2) as other_expenses,
                ROUND(SUM(profit_sum), 2) as profit,
                -- Проценты от выручки
                CASE WHEN SUM(revenue_sum) > 0 THEN 
                    ROUND(SUM(cogs_sum) * 100.0 / SUM(revenue_sum), 2) 
                ELSE 0 END as cogs_percent,
                CASE WHEN SUM(revenue_sum) > 0 THEN 
                    ROUND(SUM(commission_sum) * 100.0 / SUM(revenue_sum), 2) 
                ELSE 0 END as commission_percent,
                CASE WHEN SUM(revenue_sum) > 0 THEN 
                    ROUND(SUM(shipping_sum) * 100.0 / SUM(revenue_sum), 2) 
                ELSE 0 END as shipping_percent,
                CASE WHEN SUM(revenue_sum) > 0 THEN 
                    ROUND(SUM(other_expenses_sum) * 100.0 / SUM(revenue_sum), 2) 
                ELSE 0 END as other_expenses_percent,
                CASE WHEN SUM(revenue_sum) > 0 THEN 
                    ROUND(SUM(profit_sum) * 100.0 / SUM(revenue_sum), 2) 
                ELSE 0 END as profit_percent
            FROM metrics_daily 
            WHERE metric_date BETWEEN :start_date AND :end_date
        ";
        
        $params = [
            'start_date' => $startDate,
            'end_date' => $endDate
        ];
        
        if ($clientId) {
            $sql .= " AND client_id = :client_id";
            $params['client_id'] = $clientId;
        }
        
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        
        return $stmt->fetch();
    }
    
    /**
     * Сравнить два периода
     */
    public function comparePeriods($currentStart, $currentEnd, $previousStart, $previousEnd, $clientId = null) {
        $currentData = $this->getMarginSummary($currentStart, $currentEnd, $clientId);
        $previousData = $this->getMarginSummary($previousStart, $previousEnd, $clientId);
        
        $comparison = [
            'current' => $currentData,
            'previous' => $previousData,
            'changes' => []
        ];
        
        // Рассчитываем изменения
        $metrics = ['total_revenue', 'total_profit', 'avg_margin_percent', 'total_orders'];
        
        foreach ($metrics as $metric) {
            $current = $currentData[$metric] ?? 0;
            $previous = $previousData[$metric] ?? 0;
            
            if ($previous > 0) {
                $change = (($current - $previous) / $previous) * 100;
                $comparison['changes'][$metric] = round($change, 2);
            } else {
                $comparison['changes'][$metric] = $current > 0 ? 100 : 0;
            }
        }
        
        return $comparison;
    }
    
    /**
     * Получить данные для таблицы с детализацией по дням
     */
    public function getDailyDetailsTable($startDate, $endDate, $clientId = null, $page = 1, $limit = 20) {
        $offset = ($page - 1) * $limit;
        
        $sql = "
            SELECT 
                md.metric_date,
                c.name as client_name,
                md.orders_cnt,
                ROUND(md.revenue_sum, 2) as revenue,
                ROUND(md.cogs_sum, 2) as cogs,
                ROUND(md.commission_sum, 2) as commission,
                ROUND(md.shipping_sum, 2) as shipping,
                ROUND(md.other_expenses_sum, 2) as other_expenses,
                ROUND(md.profit_sum, 2) as profit,
                ROUND(md.margin_percent, 2) as margin_percent
            FROM metrics_daily md
            JOIN clients c ON md.client_id = c.id
            WHERE md.metric_date BETWEEN :start_date AND :end_date
        ";
        
        $params = [
            'start_date' => $startDate,
            'end_date' => $endDate,
            'limit' => $limit,
            'offset' => $offset
        ];
        
        if ($clientId) {
            $sql .= " AND md.client_id = :client_id";
            $params['client_id'] = $clientId;
        }
        
        $sql .= " ORDER BY md.metric_date DESC LIMIT :limit OFFSET :offset";
        
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        
        return $stmt->fetchAll();
    }
    
    /**
     * Получить KPI метрики
     */
    public function getKPIMetrics($startDate, $endDate, $clientId = null) {
        $summary = $this->getMarginSummary($startDate, $endDate, $clientId);
        
        return [
            'total_revenue' => [
                'value' => number_format($summary['total_revenue'], 2),
                'raw_value' => $summary['total_revenue'],
                'label' => 'Общая выручка',
                'format' => 'currency'
            ],
            'total_profit' => [
                'value' => number_format($summary['total_profit'], 2),
                'raw_value' => $summary['total_profit'],
                'label' => 'Чистая прибыль',
                'format' => 'currency'
            ],
            'avg_margin_percent' => [
                'value' => $summary['avg_margin_percent'],
                'raw_value' => $summary['avg_margin_percent'],
                'label' => 'Средняя маржинальность',
                'format' => 'percent'
            ],
            'total_orders' => [
                'value' => number_format($summary['total_orders']),
                'raw_value' => $summary['total_orders'],
                'label' => 'Всего заказов',
                'format' => 'number'
            ],
            'avg_order_value' => [
                'value' => $summary['total_orders'] > 0 ? 
                    number_format($summary['total_revenue'] / $summary['total_orders'], 2) : '0',
                'raw_value' => $summary['total_orders'] > 0 ? 
                    $summary['total_revenue'] / $summary['total_orders'] : 0,
                'label' => 'Средний чек',
                'format' => 'currency'
            ],
            'profit_per_order' => [
                'value' => $summary['total_orders'] > 0 ? 
                    number_format($summary['total_profit'] / $summary['total_orders'], 2) : '0',
                'raw_value' => $summary['total_orders'] > 0 ? 
                    $summary['total_profit'] / $summary['total_orders'] : 0,
                'label' => 'Прибыль с заказа',
                'format' => 'currency'
            ]
        ];
    }
    
    /**
     * Получить список клиентов
     */
    public function getClients() {
        $sql = "SELECT id, name FROM clients ORDER BY name";
        $stmt = $this->pdo->query($sql);
        return $stmt->fetchAll();
    }
    
    /**
     * Получить тренд маржинальности (рост/падение)
     */
    public function getMarginTrend($startDate, $endDate, $clientId = null) {
        $chartData = $this->getDailyMarginChart($startDate, $endDate, $clientId);
        
        if (count($chartData) < 2) {
            return ['trend' => 'stable', 'change' => 0];
        }
        
        $firstMargin = $chartData[0]['margin_percent'] ?? 0;
        $lastMargin = end($chartData)['margin_percent'] ?? 0;
        
        $change = $lastMargin - $firstMargin;
        
        if ($change > 1) {
            $trend = 'up';
        } elseif ($change < -1) {
            $trend = 'down';
        } else {
            $trend = 'stable';
        }
        
        return [
            'trend' => $trend,
            'change' => round($change, 2),
            'first_margin' => $firstMargin,
            'last_margin' => $lastMargin
        ];
    }
    
    /**
     * Получить статистику по дням недели
     */
    public function getWeekdayStats($startDate, $endDate, $clientId = null) {
        $sql = "
            SELECT 
                DAYOFWEEK(metric_date) as day_of_week,
                CASE DAYOFWEEK(metric_date)
                    WHEN 1 THEN 'Воскресенье'
                    WHEN 2 THEN 'Понедельник'
                    WHEN 3 THEN 'Вторник'
                    WHEN 4 THEN 'Среда'
                    WHEN 5 THEN 'Четверг'
                    WHEN 6 THEN 'Пятница'
                    WHEN 7 THEN 'Суббота'
                END as day_name,
                COUNT(*) as days_count,
                ROUND(AVG(revenue_sum), 2) as avg_revenue,
                ROUND(AVG(profit_sum), 2) as avg_profit,
                ROUND(AVG(margin_percent), 2) as avg_margin_percent
            FROM metrics_daily 
            WHERE metric_date BETWEEN :start_date AND :end_date
                AND revenue_sum > 0
        ";
        
        $params = [
            'start_date' => $startDate,
            'end_date' => $endDate
        ];
        
        if ($clientId) {
            $sql .= " AND client_id = :client_id";
            $params['client_id'] = $clientId;
        }
        
        $sql .= " GROUP BY DAYOFWEEK(metric_date) ORDER BY day_of_week";
        
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        
        return $stmt->fetchAll();
    }
}
?>