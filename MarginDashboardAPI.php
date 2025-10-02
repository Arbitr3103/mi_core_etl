<?php
/**
 * API класс для работы с данными маржинальности
 * Готовый к использованию класс для PHP разработчиков
 */

class MarginDashboardAPI {
    private $pdo;
    private $logFile = 'margin_api.log';
    private $fallbackHandler;
    
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
            
            // Инициализируем обработчик ошибок
            $this->fallbackHandler = new MarketplaceFallbackHandler($this->pdo, 'marketplace_errors.log');
            
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
    
    // ==================== MARKETPLACE-SPECIFIC METHODS ====================
    
    /**
     * Получить общую статистику маржинальности за период с фильтрацией по маркетплейсу
     * 
     * @param string $startDate - начальная дата периода (YYYY-MM-DD)
     * @param string $endDate - конечная дата периода (YYYY-MM-DD)
     * @param string|null $marketplace - маркетплейс для фильтрации ('ozon', 'wildberries' или null для всех)
     * @param int|null $clientId - ID клиента (null для всех клиентов)
     * @return array статистика маржинальности
     */
    public function getMarginSummaryByMarketplace($startDate, $endDate, $marketplace = null, $clientId = null) {
        $this->log("Getting margin summary by marketplace: $startDate to $endDate, marketplace: $marketplace, client: $clientId");
        
        try {
            // Валидация параметра маркетплейса
            if ($marketplace !== null) {
                $validation = MarketplaceDetector::validateMarketplaceParameter($marketplace);
                if (!$validation['valid']) {
                    return $this->fallbackHandler->handleValidationError(
                        'marketplace_parameter',
                        [$validation['error']],
                        ['marketplace' => $marketplace, 'method' => 'getMarginSummaryByMarketplace']
                    );
                }
            }
            
            $sql = "
                SELECT 
                    COUNT(DISTINCT fo.order_id) as total_orders,
                    ROUND(SUM(fo.qty * fo.price), 2) as total_revenue,
                    ROUND(SUM(fo.qty * COALESCE(fo.cost_price, dp.cost_price, 0)), 2) as total_cogs,
                    ROUND(SUM(fo.qty * fo.price * 0.15), 2) as total_commission,
                    ROUND(SUM(fo.qty * fo.price * 0.05), 2) as total_shipping,
                    ROUND(SUM(fo.qty * fo.price * 0.02), 2) as total_other_expenses,
                    ROUND(SUM(fo.qty * fo.price) - SUM(fo.qty * COALESCE(fo.cost_price, dp.cost_price, 0)) - SUM(fo.qty * fo.price * 0.22), 2) as total_profit,
                    CASE 
                        WHEN SUM(fo.qty * fo.price) > 0 
                        THEN ROUND((SUM(fo.qty * fo.price) - SUM(fo.qty * COALESCE(fo.cost_price, dp.cost_price, 0)) - SUM(fo.qty * fo.price * 0.22)) * 100.0 / SUM(fo.qty * fo.price), 2)
                        ELSE NULL 
                    END as avg_margin_percent,
                    COUNT(DISTINCT fo.product_id) as unique_products,
                    MIN(fo.order_date) as period_start,
                    MAX(fo.order_date) as period_end,
                    COUNT(DISTINCT DATE(fo.order_date)) as days_count
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
                $marketplace = strtolower(trim($marketplace));
                switch ($marketplace) {
                    case 'ozon':
                        $sql .= " AND (s.code LIKE :ozon_code OR s.name LIKE :ozon_name OR s.name LIKE :ozon_name_ru OR 
                                 (dp.sku_ozon IS NOT NULL AND fo.sku = dp.sku_ozon))";
                        $params['ozon_code'] = '%ozon%';
                        $params['ozon_name'] = '%ozon%';
                        $params['ozon_name_ru'] = '%озон%';
                        break;
                        
                    case 'wildberries':
                        $sql .= " AND (s.code LIKE :wb_code1 OR s.code LIKE :wb_code2 OR s.name LIKE :wb_name1 OR 
                                 s.name LIKE :wb_name2 OR s.name LIKE :wb_name3 OR 
                                 (dp.sku_wb IS NOT NULL AND fo.sku = dp.sku_wb))";
                        $params['wb_code1'] = '%wb%';
                        $params['wb_code2'] = '%wildberries%';
                        $params['wb_name1'] = '%wildberries%';
                        $params['wb_name2'] = '%вб%';
                        $params['wb_name3'] = '%валдберис%';
                        break;
                        
                    default:
                        return $this->fallbackHandler->handleValidationError(
                            'invalid_marketplace',
                            ["Неподдерживаемый маркетплейс: {$marketplace}"],
                            ['marketplace' => $marketplace, 'valid_values' => ['ozon', 'wildberries']]
                        );
                }
            }
            
            // Добавляем фильтр по клиенту
            if ($clientId) {
                $sql .= " AND fo.client_id = :client_id";
                $params['client_id'] = $clientId;
            }
            
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($params);
            
            $result = $stmt->fetch();
            
            // Проверяем, есть ли данные
            if (!$result || $result['total_orders'] == 0) {
                $period = "$startDate to $endDate";
                return $this->fallbackHandler->handleMissingData(
                    $marketplace ?: 'all',
                    $period,
                    ['client_id' => $clientId, 'method' => 'getMarginSummaryByMarketplace']
                );
            }
            
            $this->log("Margin summary by marketplace result: " . json_encode($result));
            
            // Добавляем метаданные к результату
            $result['success'] = true;
            $result['has_data'] = true;
            $result['marketplace'] = $marketplace;
            $result['marketplace_name'] = $marketplace ? MarketplaceDetector::getMarketplaceName($marketplace) : 'Все маркетплейсы';
            
            return $result;
            
        } catch (PDOException $e) {
            return $this->fallbackHandler->handleDatabaseError($e, [
                'method' => 'getMarginSummaryByMarketplace',
                'params' => compact('startDate', 'endDate', 'marketplace', 'clientId')
            ]);
        } catch (Exception $e) {
            $this->log("Error in getMarginSummaryByMarketplace: " . $e->getMessage());
            return $this->fallbackHandler->createUserFriendlyError(
                MarketplaceFallbackHandler::ERROR_DATABASE_ERROR,
                $marketplace,
                ['error' => $e->getMessage(), 'method' => 'getMarginSummaryByMarketplace']
            );
        }
    }
    
    /**
     * Получить топ товаров по выручке с фильтрацией по маркетплейсу
     * 
     * @param string|null $marketplace - маркетплейс для фильтрации ('ozon', 'wildberries' или null для всех)
     * @param int $limit - количество товаров в топе (по умолчанию 10)
     * @param string|null $startDate - начальная дата периода
     * @param string|null $endDate - конечная дата периода
     * @param float $minRevenue - минимальная выручка для включения в топ
     * @param int|null $clientId - ID клиента
     * @return array массив топ товаров
     */
    public function getTopProductsByMarketplace($marketplace = null, $limit = 10, $startDate = null, $endDate = null, $minRevenue = 0, $clientId = null) {
        $this->log("Getting top products by marketplace: marketplace=$marketplace, limit=$limit, dates=$startDate to $endDate");
        
        try {
            // Валидация параметра маркетплейса
            if ($marketplace !== null) {
                $validation = MarketplaceDetector::validateMarketplaceParameter($marketplace);
                if (!$validation['valid']) {
                    return $this->fallbackHandler->handleValidationError(
                        'marketplace_parameter',
                        [$validation['error']],
                        ['marketplace' => $marketplace, 'method' => 'getTopProductsByMarketplace']
                    );
                }
            }
            
            $sql = "
                SELECT 
                    fo.product_id,
                    dp.product_name,
                    CASE 
                        WHEN :marketplace_param = 'ozon' THEN COALESCE(dp.sku_ozon, fo.sku)
                        WHEN :marketplace_param = 'wildberries' THEN COALESCE(dp.sku_wb, fo.sku)
                        ELSE COALESCE(dp.sku_ozon, dp.sku_wb, fo.sku)
                    END as display_sku,
                    dp.sku_ozon,
                    dp.sku_wb,
                    COUNT(DISTINCT fo.order_id) as orders_count,
                    SUM(fo.qty) as total_qty,
                    ROUND(SUM(fo.qty * fo.price), 2) as total_revenue,
                    ROUND(SUM(fo.qty * COALESCE(fo.cost_price, dp.cost_price, 0)), 2) as total_cogs,
                    ROUND(SUM(fo.qty * fo.price) - SUM(fo.qty * COALESCE(fo.cost_price, dp.cost_price, 0)) - SUM(fo.qty * fo.price * 0.22), 2) as total_profit,
                    CASE 
                        WHEN SUM(fo.qty * fo.price) > 0 
                        THEN ROUND((SUM(fo.qty * fo.price) - SUM(fo.qty * COALESCE(fo.cost_price, dp.cost_price, 0)) - SUM(fo.qty * fo.price * 0.22)) * 100.0 / SUM(fo.qty * fo.price), 2)
                        ELSE NULL 
                    END as margin_percent,
                    ROUND(AVG(fo.price), 2) as avg_price,
                    :marketplace_param as marketplace_filter
                FROM fact_orders fo
                JOIN sources s ON fo.source_id = s.id
                LEFT JOIN dim_products dp ON fo.product_id = dp.id
                WHERE fo.transaction_type IN ('продажа', 'sale', 'order')
            ";
            
            $params = [
                'marketplace_param' => $marketplace,
                'limit' => $limit,
                'min_revenue' => $minRevenue
            ];
            
            // Добавляем фильтр по маркетплейсу
            if ($marketplace !== null) {
                $marketplace = strtolower(trim($marketplace));
                switch ($marketplace) {
                    case 'ozon':
                        $sql .= " AND (s.code LIKE :ozon_code OR s.name LIKE :ozon_name OR s.name LIKE :ozon_name_ru OR 
                                 (dp.sku_ozon IS NOT NULL AND fo.sku = dp.sku_ozon))";
                        $params['ozon_code'] = '%ozon%';
                        $params['ozon_name'] = '%ozon%';
                        $params['ozon_name_ru'] = '%озон%';
                        break;
                        
                    case 'wildberries':
                        $sql .= " AND (s.code LIKE :wb_code1 OR s.code LIKE :wb_code2 OR s.name LIKE :wb_name1 OR 
                                 s.name LIKE :wb_name2 OR s.name LIKE :wb_name3 OR 
                                 (dp.sku_wb IS NOT NULL AND fo.sku = dp.sku_wb))";
                        $params['wb_code1'] = '%wb%';
                        $params['wb_code2'] = '%wildberries%';
                        $params['wb_name1'] = '%wildberries%';
                        $params['wb_name2'] = '%вб%';
                        $params['wb_name3'] = '%валдберис%';
                        break;
                        
                    default:
                        return $this->fallbackHandler->handleValidationError(
                            'invalid_marketplace',
                            ["Неподдерживаемый маркетплейс: {$marketplace}"],
                            ['marketplace' => $marketplace, 'valid_values' => ['ozon', 'wildberries']]
                        );
                }
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
            
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($params);
            
            $result = $stmt->fetchAll();
            
            // Проверяем, есть ли данные
            if (empty($result)) {
                $period = $startDate && $endDate ? "$startDate to $endDate" : "all time";
                return $this->fallbackHandler->handleEmptyResults(
                    $marketplace ?: 'all',
                    [
                        'method' => 'getTopProductsByMarketplace',
                        'limit' => $limit,
                        'min_revenue' => $minRevenue,
                        'period' => $period,
                        'client_id' => $clientId
                    ]
                );
            }
            
            $this->log("Top products by marketplace result count: " . count($result));
            
            return [
                'success' => true,
                'has_data' => true,
                'marketplace' => $marketplace,
                'marketplace_name' => $marketplace ? MarketplaceDetector::getMarketplaceName($marketplace) : 'Все маркетплейсы',
                'data' => $result,
                'count' => count($result),
                'limit' => $limit,
                'min_revenue' => $minRevenue
            ];
            
        } catch (PDOException $e) {
            return $this->fallbackHandler->handleDatabaseError($e, [
                'method' => 'getTopProductsByMarketplace',
                'params' => compact('marketplace', 'limit', 'startDate', 'endDate', 'minRevenue', 'clientId')
            ]);
        } catch (Exception $e) {
            $this->log("Error in getTopProductsByMarketplace: " . $e->getMessage());
            return $this->fallbackHandler->createUserFriendlyError(
                MarketplaceFallbackHandler::ERROR_DATABASE_ERROR,
                $marketplace,
                ['error' => $e->getMessage(), 'method' => 'getTopProductsByMarketplace']
            );
        }
    }
    
    /**
     * Получить данные маржинальности по дням для графика с фильтрацией по маркетплейсу
     * 
     * @param string $startDate - начальная дата периода (YYYY-MM-DD)
     * @param string $endDate - конечная дата периода (YYYY-MM-DD)
     * @param string|null $marketplace - маркетплейс для фильтрации ('ozon', 'wildberries' или null для всех)
     * @param int|null $clientId - ID клиента (null для всех клиентов)
     * @return array данные по дням для графика
     */
    public function getDailyMarginChartByMarketplace($startDate, $endDate, $marketplace = null, $clientId = null) {
        $this->log("Getting daily margin chart by marketplace: $startDate to $endDate, marketplace: $marketplace, client: $clientId");
        
        $sql = "
            SELECT 
                fo.order_date as metric_date,
                COUNT(DISTINCT fo.order_id) as orders_count,
                ROUND(SUM(fo.qty * fo.price), 2) as revenue,
                ROUND(SUM(fo.qty * COALESCE(fo.cost_price, dp.cost_price, 0)), 2) as cogs,
                ROUND(SUM(fo.qty * fo.price * 0.15), 2) as commission,
                ROUND(SUM(fo.qty * fo.price * 0.05), 2) as shipping,
                ROUND(SUM(fo.qty * fo.price * 0.02), 2) as other_expenses,
                ROUND(SUM(fo.qty * fo.price) - SUM(fo.qty * COALESCE(fo.cost_price, dp.cost_price, 0)) - SUM(fo.qty * fo.price * 0.22), 2) as profit,
                CASE 
                    WHEN SUM(fo.qty * fo.price) > 0 
                    THEN ROUND((SUM(fo.qty * fo.price) - SUM(fo.qty * COALESCE(fo.cost_price, dp.cost_price, 0)) - SUM(fo.qty * fo.price * 0.22)) * 100.0 / SUM(fo.qty * fo.price), 2)
                    ELSE NULL 
                END as margin_percent,
                COUNT(DISTINCT fo.product_id) as unique_products
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
            $marketplace = strtolower(trim($marketplace));
            switch ($marketplace) {
                case 'ozon':
                    $sql .= " AND (s.code LIKE :ozon_code OR s.name LIKE :ozon_name OR s.name LIKE :ozon_name_ru OR 
                             (dp.sku_ozon IS NOT NULL AND fo.sku = dp.sku_ozon))";
                    $params['ozon_code'] = '%ozon%';
                    $params['ozon_name'] = '%ozon%';
                    $params['ozon_name_ru'] = '%озон%';
                    break;
                    
                case 'wildberries':
                    $sql .= " AND (s.code LIKE :wb_code1 OR s.code LIKE :wb_code2 OR s.name LIKE :wb_name1 OR 
                             s.name LIKE :wb_name2 OR s.name LIKE :wb_name3 OR 
                             (dp.sku_wb IS NOT NULL AND fo.sku = dp.sku_wb))";
                    $params['wb_code1'] = '%wb%';
                    $params['wb_code2'] = '%wildberries%';
                    $params['wb_name1'] = '%wildberries%';
                    $params['wb_name2'] = '%вб%';
                    $params['wb_name3'] = '%валдберис%';
                    break;
                    
                default:
                    throw new InvalidArgumentException("Неподдерживаемый маркетплейс: {$marketplace}. Допустимые значения: ozon, wildberries");
            }
        }
        
        // Добавляем фильтр по клиенту
        if ($clientId !== null) {
            $sql .= " AND fo.client_id = :client_id";
            $params['client_id'] = $clientId;
        }
        
        $sql .= " GROUP BY fo.order_date ORDER BY fo.order_date";
        
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        
        $result = $stmt->fetchAll();
        $this->log("Daily margin chart by marketplace result count: " . count($result));
        
        // Заполняем пропущенные даты нулевыми значениями
        $result = $this->fillMissingDates($result, $startDate, $endDate);
        
        return $result;
    }
    
    /**
     * Заполнить пропущенные даты в результатах нулевыми значениями
     * 
     * @param array $data - исходные данные
     * @param string $startDate - начальная дата
     * @param string $endDate - конечная дата
     * @return array данные с заполненными пропусками
     */
    private function fillMissingDates($data, $startDate, $endDate) {
        // Создаем индекс по датам
        $dataByDate = [];
        foreach ($data as $row) {
            $dataByDate[$row['metric_date']] = $row;
        }
        
        // Генерируем все даты в периоде
        $result = [];
        $currentDate = new DateTime($startDate);
        $endDateTime = new DateTime($endDate);
        
        while ($currentDate <= $endDateTime) {
            $dateStr = $currentDate->format('Y-m-d');
            
            if (isset($dataByDate[$dateStr])) {
                $result[] = $dataByDate[$dateStr];
            } else {
                // Добавляем пустую запись для отсутствующей даты
                $result[] = [
                    'metric_date' => $dateStr,
                    'orders_count' => 0,
                    'revenue' => 0.00,
                    'cogs' => 0.00,
                    'commission' => 0.00,
                    'shipping' => 0.00,
                    'other_expenses' => 0.00,
                    'profit' => 0.00,
                    'margin_percent' => null,
                    'unique_products' => 0
                ];
            }
            
            $currentDate->add(new DateInterval('P1D'));
        }
        
        return $result;
    }
    
    /**
     * Получить сравнительные данные между маркетплейсами
     * 
     * @param string $startDate - начальная дата периода (YYYY-MM-DD)
     * @param string $endDate - конечная дата периода (YYYY-MM-DD)
     * @param int|null $clientId - ID клиента (null для всех клиентов)
     * @return array сравнительные данные по маркетплейсам
     */
    public function getMarketplaceComparison($startDate, $endDate, $clientId = null) {
        $this->log("Getting marketplace comparison: $startDate to $endDate, client: $clientId");
        
        // Получаем данные по каждому маркетплейсу
        $ozonData = $this->getMarginSummaryByMarketplace($startDate, $endDate, 'ozon', $clientId);
        $wildberriesData = $this->getMarginSummaryByMarketplace($startDate, $endDate, 'wildberries', $clientId);
        $totalData = $this->getMarginSummaryByMarketplace($startDate, $endDate, null, $clientId);
        
        // Получаем топ товары по каждому маркетплейсу
        $ozonTopProducts = $this->getTopProductsByMarketplace('ozon', 5, $startDate, $endDate, 0, $clientId);
        $wildberriesTopProducts = $this->getTopProductsByMarketplace('wildberries', 5, $startDate, $endDate, 0, $clientId);
        
        // Формируем результат сравнения
        $comparison = [
            'period' => [
                'start_date' => $startDate,
                'end_date' => $endDate,
                'days_count' => $totalData['days_count'] ?? 0
            ],
            'total' => [
                'name' => 'Все маркетплейсы',
                'orders_count' => $totalData['total_orders'] ?? 0,
                'revenue' => $totalData['total_revenue'] ?? 0,
                'profit' => $totalData['total_profit'] ?? 0,
                'margin_percent' => $totalData['avg_margin_percent'] ?? 0,
                'unique_products' => $totalData['unique_products'] ?? 0
            ],
            'marketplaces' => [
                'ozon' => [
                    'name' => 'Ozon',
                    'icon' => '📦',
                    'orders_count' => $ozonData['total_orders'] ?? 0,
                    'revenue' => $ozonData['total_revenue'] ?? 0,
                    'profit' => $ozonData['total_profit'] ?? 0,
                    'margin_percent' => $ozonData['avg_margin_percent'] ?? 0,
                    'unique_products' => $ozonData['unique_products'] ?? 0,
                    'top_products' => $ozonTopProducts,
                    'has_data' => ($ozonData['total_orders'] ?? 0) > 0
                ],
                'wildberries' => [
                    'name' => 'Wildberries',
                    'icon' => '🛍️',
                    'orders_count' => $wildberriesData['total_orders'] ?? 0,
                    'revenue' => $wildberriesData['total_revenue'] ?? 0,
                    'profit' => $wildberriesData['total_profit'] ?? 0,
                    'margin_percent' => $wildberriesData['avg_margin_percent'] ?? 0,
                    'unique_products' => $wildberriesData['unique_products'] ?? 0,
                    'top_products' => $wildberriesTopProducts,
                    'has_data' => ($wildberriesData['total_orders'] ?? 0) > 0
                ]
            ],
            'comparison_metrics' => []
        ];
        
        // Рассчитываем сравнительные метрики
        $totalRevenue = $comparison['total']['revenue'];
        $totalOrders = $comparison['total']['orders_count'];
        
        if ($totalRevenue > 0) {
            $comparison['comparison_metrics']['revenue_share'] = [
                'ozon' => round(($comparison['marketplaces']['ozon']['revenue'] / $totalRevenue) * 100, 2),
                'wildberries' => round(($comparison['marketplaces']['wildberries']['revenue'] / $totalRevenue) * 100, 2)
            ];
        }
        
        if ($totalOrders > 0) {
            $comparison['comparison_metrics']['orders_share'] = [
                'ozon' => round(($comparison['marketplaces']['ozon']['orders_count'] / $totalOrders) * 100, 2),
                'wildberries' => round(($comparison['marketplaces']['wildberries']['orders_count'] / $totalOrders) * 100, 2)
            ];
        }
        
        // Средний чек по маркетплейсам
        $comparison['comparison_metrics']['avg_order_value'] = [
            'ozon' => $comparison['marketplaces']['ozon']['orders_count'] > 0 ? 
                round($comparison['marketplaces']['ozon']['revenue'] / $comparison['marketplaces']['ozon']['orders_count'], 2) : 0,
            'wildberries' => $comparison['marketplaces']['wildberries']['orders_count'] > 0 ? 
                round($comparison['marketplaces']['wildberries']['revenue'] / $comparison['marketplaces']['wildberries']['orders_count'], 2) : 0
        ];
        
        // Прибыль с заказа
        $comparison['comparison_metrics']['profit_per_order'] = [
            'ozon' => $comparison['marketplaces']['ozon']['orders_count'] > 0 ? 
                round($comparison['marketplaces']['ozon']['profit'] / $comparison['marketplaces']['ozon']['orders_count'], 2) : 0,
            'wildberries' => $comparison['marketplaces']['wildberries']['orders_count'] > 0 ? 
                round($comparison['marketplaces']['wildberries']['profit'] / $comparison['marketplaces']['wildberries']['orders_count'], 2) : 0
        ];
        
        // Определяем лидера по различным метрикам
        $comparison['leaders'] = [
            'revenue' => $comparison['marketplaces']['ozon']['revenue'] > $comparison['marketplaces']['wildberries']['revenue'] ? 'ozon' : 'wildberries',
            'orders' => $comparison['marketplaces']['ozon']['orders_count'] > $comparison['marketplaces']['wildberries']['orders_count'] ? 'ozon' : 'wildberries',
            'margin' => $comparison['marketplaces']['ozon']['margin_percent'] > $comparison['marketplaces']['wildberries']['margin_percent'] ? 'ozon' : 'wildberries',
            'avg_order_value' => $comparison['comparison_metrics']['avg_order_value']['ozon'] > $comparison['comparison_metrics']['avg_order_value']['wildberries'] ? 'ozon' : 'wildberries'
        ];
        
        $this->log("Marketplace comparison completed successfully");
        
        return $comparison;
    }
}
?>