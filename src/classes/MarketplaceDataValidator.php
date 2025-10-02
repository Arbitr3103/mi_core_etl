<?php
/**
 * MarketplaceDataValidator Class - Валидация и проверка консистентности данных маркетплейсов
 * 
 * Предоставляет методы для валидации данных, проверки консистентности между
 * маркетплейсами и общими показателями, мониторинга качества данных
 * 
 * @version 1.0
 * @author Manhattan System
 */

class MarketplaceDataValidator {
    
    // Типы валидации
    const VALIDATION_TOTALS_CONSISTENCY = 'totals_consistency';
    const VALIDATION_MARKETPLACE_CLASSIFICATION = 'marketplace_classification';
    const VALIDATION_SKU_CONSISTENCY = 'sku_consistency';
    const VALIDATION_DATA_COMPLETENESS = 'data_completeness';
    const VALIDATION_REVENUE_CONSISTENCY = 'revenue_consistency';
    
    // Пороги для предупреждений
    const WARNING_THRESHOLD_PERCENTAGE = 5.0; // 5% расхождение
    const CRITICAL_THRESHOLD_PERCENTAGE = 10.0; // 10% расхождение
    const MIN_CLASSIFICATION_RATE = 85.0; // Минимум 85% данных должно быть классифицировано
    
    private $pdo;
    private $marginAPI;
    private $fallbackHandler;
    private $logFile;
    
    /**
     * Конструктор класса
     * 
     * @param PDO $pdo - подключение к базе данных
     * @param MarginDashboardAPI $marginAPI - API для получения данных
     * @param MarketplaceFallbackHandler $fallbackHandler - обработчик ошибок
     * @param string $logFile - файл для логирования
     */
    public function __construct(PDO $pdo, MarginDashboardAPI $marginAPI, MarketplaceFallbackHandler $fallbackHandler, $logFile = 'marketplace_validation.log') {
        $this->pdo = $pdo;
        $this->marginAPI = $marginAPI;
        $this->fallbackHandler = $fallbackHandler;
        $this->logFile = $logFile;
    }
    
    /**
     * Выполнить полную валидацию данных маркетплейсов
     * 
     * @param string $startDate - начальная дата периода
     * @param string $endDate - конечная дата периода
     * @param int|null $clientId - ID клиента
     * @return array результат валидации
     */
    public function validateMarketplaceData($startDate, $endDate, $clientId = null) {
        $this->log("Starting marketplace data validation for period: $startDate to $endDate, client: $clientId");
        
        $validationResults = [
            'period' => "$startDate to $endDate",
            'client_id' => $clientId,
            'timestamp' => date('Y-m-d H:i:s'),
            'overall_status' => 'unknown',
            'validations' => [],
            'warnings' => [],
            'errors' => [],
            'recommendations' => []
        ];
        
        try {
            // 1. Проверка консистентности общих показателей
            $totalsValidation = $this->validateTotalsConsistency($startDate, $endDate, $clientId);
            $validationResults['validations'][self::VALIDATION_TOTALS_CONSISTENCY] = $totalsValidation;
            
            // 2. Проверка качества классификации маркетплейсов
            $classificationValidation = $this->validateMarketplaceClassification($startDate, $endDate, $clientId);
            $validationResults['validations'][self::VALIDATION_MARKETPLACE_CLASSIFICATION] = $classificationValidation;
            
            // 3. Проверка консистентности SKU
            $skuValidation = $this->validateSKUConsistency($startDate, $endDate, $clientId);
            $validationResults['validations'][self::VALIDATION_SKU_CONSISTENCY] = $skuValidation;
            
            // 4. Проверка полноты данных
            $completenessValidation = $this->validateDataCompleteness($startDate, $endDate, $clientId);
            $validationResults['validations'][self::VALIDATION_DATA_COMPLETENESS] = $completenessValidation;
            
            // 5. Проверка консистентности выручки
            $revenueValidation = $this->validateRevenueConsistency($startDate, $endDate, $clientId);
            $validationResults['validations'][self::VALIDATION_REVENUE_CONSISTENCY] = $revenueValidation;
            
            // Собираем предупреждения и ошибки
            foreach ($validationResults['validations'] as $validation) {
                if (!empty($validation['warnings'])) {
                    $validationResults['warnings'] = array_merge($validationResults['warnings'], $validation['warnings']);
                }
                if (!empty($validation['errors'])) {
                    $validationResults['errors'] = array_merge($validationResults['errors'], $validation['errors']);
                }
            }
            
            // Определяем общий статус
            $validationResults['overall_status'] = $this->determineOverallStatus($validationResults);
            
            // Генерируем рекомендации
            $validationResults['recommendations'] = $this->generateRecommendations($validationResults);
            
            $this->log("Marketplace data validation completed with status: " . $validationResults['overall_status']);
            
            return $validationResults;
            
        } catch (Exception $e) {
            $this->log("Error during validation: " . $e->getMessage());
            return $this->fallbackHandler->handleDatabaseError($e, [
                'method' => 'validateMarketplaceData',
                'params' => compact('startDate', 'endDate', 'clientId')
            ]);
        }
    }
    
    /**
     * Проверить консистентность общих показателей
     * 
     * @param string $startDate - начальная дата
     * @param string $endDate - конечная дата
     * @param int|null $clientId - ID клиента
     * @return array результат проверки
     */
    private function validateTotalsConsistency($startDate, $endDate, $clientId = null) {
        $this->log("Validating totals consistency");
        
        try {
            // Получаем общие показатели
            $totalData = $this->marginAPI->getMarginSummaryByMarketplace($startDate, $endDate, null, $clientId);
            
            // Получаем показатели по Ozon
            $ozonData = $this->marginAPI->getMarginSummaryByMarketplace($startDate, $endDate, 'ozon', $clientId);
            
            // Получаем показатели по Wildberries
            $wbData = $this->marginAPI->getMarginSummaryByMarketplace($startDate, $endDate, 'wildberries', $clientId);
            
            $validation = [
                'status' => 'passed',
                'warnings' => [],
                'errors' => [],
                'details' => []
            ];
            
            // Проверяем, что данные получены успешно
            if (!$totalData['success'] || !$ozonData['success'] || !$wbData['success']) {
                $validation['status'] = 'failed';
                $validation['errors'][] = 'Не удалось получить данные для проверки консистентности';
                return $validation;
            }
            
            // Если нет данных, пропускаем проверку
            if (!$totalData['has_data'] && !$ozonData['has_data'] && !$wbData['has_data']) {
                $validation['status'] = 'skipped';
                $validation['details']['reason'] = 'Нет данных для проверки';
                return $validation;
            }
            
            // Извлекаем числовые значения
            $totalRevenue = $totalData['total_revenue'] ?? 0;
            $totalOrders = $totalData['total_orders'] ?? 0;
            $totalProfit = $totalData['total_profit'] ?? 0;
            
            $ozonRevenue = $ozonData['has_data'] ? ($ozonData['total_revenue'] ?? 0) : 0;
            $ozonOrders = $ozonData['has_data'] ? ($ozonData['total_orders'] ?? 0) : 0;
            $ozonProfit = $ozonData['has_data'] ? ($ozonData['total_profit'] ?? 0) : 0;
            
            $wbRevenue = $wbData['has_data'] ? ($wbData['total_revenue'] ?? 0) : 0;
            $wbOrders = $wbData['has_data'] ? ($wbData['total_orders'] ?? 0) : 0;
            $wbProfit = $wbData['has_data'] ? ($wbData['total_profit'] ?? 0) : 0;
            
            // Суммы по маркетплейсам
            $marketplacesRevenue = $ozonRevenue + $wbRevenue;
            $marketplacesOrders = $ozonOrders + $wbOrders;
            $marketplacesProfit = $ozonProfit + $wbProfit;
            
            // Проверяем выручку
            $revenueDiscrepancy = $this->calculateDiscrepancy($totalRevenue, $marketplacesRevenue);
            if ($revenueDiscrepancy > self::CRITICAL_THRESHOLD_PERCENTAGE) {
                $validation['errors'][] = "Критическое расхождение в выручке: {$revenueDiscrepancy}% (общая: {$totalRevenue}, по маркетплейсам: {$marketplacesRevenue})";
                $validation['status'] = 'failed';
            } elseif ($revenueDiscrepancy > self::WARNING_THRESHOLD_PERCENTAGE) {
                $validation['warnings'][] = "Расхождение в выручке: {$revenueDiscrepancy}% (общая: {$totalRevenue}, по маркетплейсам: {$marketplacesRevenue})";
                if ($validation['status'] === 'passed') $validation['status'] = 'warning';
            }
            
            // Проверяем количество заказов
            $ordersDiscrepancy = $this->calculateDiscrepancy($totalOrders, $marketplacesOrders);
            if ($ordersDiscrepancy > self::CRITICAL_THRESHOLD_PERCENTAGE) {
                $validation['errors'][] = "Критическое расхождение в заказах: {$ordersDiscrepancy}% (общие: {$totalOrders}, по маркетплейсам: {$marketplacesOrders})";
                $validation['status'] = 'failed';
            } elseif ($ordersDiscrepancy > self::WARNING_THRESHOLD_PERCENTAGE) {
                $validation['warnings'][] = "Расхождение в заказах: {$ordersDiscrepancy}% (общие: {$totalOrders}, по маркетплейсам: {$marketplacesOrders})";
                if ($validation['status'] === 'passed') $validation['status'] = 'warning';
            }
            
            // Проверяем прибыль
            $profitDiscrepancy = $this->calculateDiscrepancy($totalProfit, $marketplacesProfit);
            if ($profitDiscrepancy > self::CRITICAL_THRESHOLD_PERCENTAGE) {
                $validation['errors'][] = "Критическое расхождение в прибыли: {$profitDiscrepancy}% (общая: {$totalProfit}, по маркетплейсам: {$marketplacesProfit})";
                $validation['status'] = 'failed';
            } elseif ($profitDiscrepancy > self::WARNING_THRESHOLD_PERCENTAGE) {
                $validation['warnings'][] = "Расхождение в прибыли: {$profitDiscrepancy}% (общая: {$totalProfit}, по маркетплейсам: {$marketplacesProfit})";
                if ($validation['status'] === 'passed') $validation['status'] = 'warning';
            }
            
            // Сохраняем детали для анализа
            $validation['details'] = [
                'total_data' => [
                    'revenue' => $totalRevenue,
                    'orders' => $totalOrders,
                    'profit' => $totalProfit
                ],
                'marketplaces_sum' => [
                    'revenue' => $marketplacesRevenue,
                    'orders' => $marketplacesOrders,
                    'profit' => $marketplacesProfit
                ],
                'discrepancies' => [
                    'revenue_percent' => $revenueDiscrepancy,
                    'orders_percent' => $ordersDiscrepancy,
                    'profit_percent' => $profitDiscrepancy
                ],
                'breakdown' => [
                    'ozon' => [
                        'revenue' => $ozonRevenue,
                        'orders' => $ozonOrders,
                        'profit' => $ozonProfit
                    ],
                    'wildberries' => [
                        'revenue' => $wbRevenue,
                        'orders' => $wbOrders,
                        'profit' => $wbProfit
                    ]
                ]
            ];
            
            return $validation;
            
        } catch (Exception $e) {
            $this->log("Error in validateTotalsConsistency: " . $e->getMessage());
            return [
                'status' => 'error',
                'errors' => ['Ошибка при проверке консистентности: ' . $e->getMessage()],
                'warnings' => [],
                'details' => []
            ];
        }
    }
    
    /**
     * Проверить качество классификации маркетплейсов
     * 
     * @param string $startDate - начальная дата
     * @param string $endDate - конечная дата
     * @param int|null $clientId - ID клиента
     * @return array результат проверки
     */
    private function validateMarketplaceClassification($startDate, $endDate, $clientId = null) {
        $this->log("Validating marketplace classification");
        
        try {
            $detector = new MarketplaceDetector($this->pdo);
            $classificationReport = $detector->validateMarketplaceClassification($startDate, $endDate, $clientId);
            
            $validation = [
                'status' => 'passed',
                'warnings' => [],
                'errors' => [],
                'details' => $classificationReport
            ];
            
            $classificationRate = $classificationReport['classification_rate'];
            
            if ($classificationRate < self::MIN_CLASSIFICATION_RATE) {
                if ($classificationRate < 70) {
                    $validation['status'] = 'failed';
                    $validation['errors'][] = "Критически низкий уровень классификации: {$classificationRate}% (минимум: " . self::MIN_CLASSIFICATION_RATE . "%)";
                } else {
                    $validation['status'] = 'warning';
                    $validation['warnings'][] = "Низкий уровень классификации: {$classificationRate}% (рекомендуется: " . self::MIN_CLASSIFICATION_RATE . "%+)";
                }
            }
            
            // Проверяем количество неклассифицированных записей
            $unknownOrders = $classificationReport['unknown_orders'];
            if ($unknownOrders > 0) {
                $validation['warnings'][] = "Найдено {$unknownOrders} заказов с неопределенным маркетплейсом";
            }
            
            return $validation;
            
        } catch (Exception $e) {
            $this->log("Error in validateMarketplaceClassification: " . $e->getMessage());
            return [
                'status' => 'error',
                'errors' => ['Ошибка при проверке классификации: ' . $e->getMessage()],
                'warnings' => [],
                'details' => []
            ];
        }
    }
    
    /**
     * Проверить консистентность SKU
     * 
     * @param string $startDate - начальная дата
     * @param string $endDate - конечная дата
     * @param int|null $clientId - ID клиента
     * @return array результат проверки
     */
    private function validateSKUConsistency($startDate, $endDate, $clientId = null) {
        $this->log("Validating SKU consistency");
        
        try {
            $sql = "
                SELECT 
                    COUNT(*) as total_products,
                    COUNT(CASE WHEN dp.sku_ozon IS NOT NULL THEN 1 END) as products_with_ozon_sku,
                    COUNT(CASE WHEN dp.sku_wb IS NOT NULL THEN 1 END) as products_with_wb_sku,
                    COUNT(CASE WHEN dp.sku_ozon IS NOT NULL AND dp.sku_wb IS NOT NULL THEN 1 END) as products_with_both_sku,
                    COUNT(CASE WHEN dp.sku_ozon IS NULL AND dp.sku_wb IS NULL THEN 1 END) as products_without_marketplace_sku,
                    COUNT(CASE WHEN dp.sku_ozon = dp.sku_wb AND dp.sku_ozon IS NOT NULL THEN 1 END) as products_with_duplicate_sku
                FROM (
                    SELECT DISTINCT fo.product_id
                    FROM fact_orders fo
                    WHERE fo.order_date BETWEEN :start_date AND :end_date
                        AND fo.transaction_type IN ('продажа', 'sale', 'order')
                        " . ($clientId ? "AND fo.client_id = :client_id" : "") . "
                ) active_products
                JOIN dim_products dp ON active_products.product_id = dp.id
            ";
            
            $params = [
                'start_date' => $startDate,
                'end_date' => $endDate
            ];
            
            if ($clientId) {
                $params['client_id'] = $clientId;
            }
            
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($params);
            $result = $stmt->fetch();
            
            $validation = [
                'status' => 'passed',
                'warnings' => [],
                'errors' => [],
                'details' => $result
            ];
            
            $totalProducts = $result['total_products'];
            $productsWithoutSku = $result['products_without_marketplace_sku'];
            $duplicateSkuProducts = $result['products_with_duplicate_sku'];
            
            if ($totalProducts > 0) {
                $noSkuPercentage = ($productsWithoutSku / $totalProducts) * 100;
                
                if ($noSkuPercentage > 20) {
                    $validation['status'] = 'failed';
                    $validation['errors'][] = "Критически много товаров без SKU маркетплейсов: {$noSkuPercentage}% ({$productsWithoutSku} из {$totalProducts})";
                } elseif ($noSkuPercentage > 10) {
                    $validation['status'] = 'warning';
                    $validation['warnings'][] = "Много товаров без SKU маркетплейсов: {$noSkuPercentage}% ({$productsWithoutSku} из {$totalProducts})";
                }
                
                if ($duplicateSkuProducts > 0) {
                    $validation['warnings'][] = "Найдено {$duplicateSkuProducts} товаров с одинаковыми SKU для разных маркетплейсов";
                }
            }
            
            return $validation;
            
        } catch (Exception $e) {
            $this->log("Error in validateSKUConsistency: " . $e->getMessage());
            return [
                'status' => 'error',
                'errors' => ['Ошибка при проверке SKU: ' . $e->getMessage()],
                'warnings' => [],
                'details' => []
            ];
        }
    }
    
    /**
     * Проверить полноту данных
     * 
     * @param string $startDate - начальная дата
     * @param string $endDate - конечная дата
     * @param int|null $clientId - ID клиента
     * @return array результат проверки
     */
    private function validateDataCompleteness($startDate, $endDate, $clientId = null) {
        $this->log("Validating data completeness");
        
        try {
            $sql = "
                SELECT 
                    COUNT(*) as total_orders,
                    COUNT(CASE WHEN fo.cost_price IS NULL AND dp.cost_price IS NULL THEN 1 END) as orders_without_cost,
                    COUNT(CASE WHEN s.code IS NULL OR s.code = '' THEN 1 END) as orders_without_source_code,
                    COUNT(CASE WHEN s.name IS NULL OR s.name = '' THEN 1 END) as orders_without_source_name,
                    COUNT(CASE WHEN dp.product_name IS NULL OR dp.product_name = '' THEN 1 END) as orders_without_product_name,
                    COUNT(CASE WHEN fo.price IS NULL OR fo.price <= 0 THEN 1 END) as orders_without_valid_price,
                    COUNT(CASE WHEN fo.qty IS NULL OR fo.qty <= 0 THEN 1 END) as orders_without_valid_qty
                FROM fact_orders fo
                JOIN sources s ON fo.source_id = s.id
                LEFT JOIN dim_products dp ON fo.product_id = dp.id
                WHERE fo.order_date BETWEEN :start_date AND :end_date
                    AND fo.transaction_type IN ('продажа', 'sale', 'order')
                    " . ($clientId ? "AND fo.client_id = :client_id" : "") . "
            ";
            
            $params = [
                'start_date' => $startDate,
                'end_date' => $endDate
            ];
            
            if ($clientId) {
                $params['client_id'] = $clientId;
            }
            
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($params);
            $result = $stmt->fetch();
            
            $validation = [
                'status' => 'passed',
                'warnings' => [],
                'errors' => [],
                'details' => $result
            ];
            
            $totalOrders = $result['total_orders'];
            
            if ($totalOrders > 0) {
                $issues = [
                    'orders_without_cost' => 'заказов без себестоимости',
                    'orders_without_source_code' => 'заказов без кода источника',
                    'orders_without_source_name' => 'заказов без названия источника',
                    'orders_without_product_name' => 'заказов без названия товара',
                    'orders_without_valid_price' => 'заказов с некорректной ценой',
                    'orders_without_valid_qty' => 'заказов с некорректным количеством'
                ];
                
                foreach ($issues as $field => $description) {
                    $count = $result[$field];
                    if ($count > 0) {
                        $percentage = ($count / $totalOrders) * 100;
                        
                        if ($percentage > 10) {
                            $validation['status'] = 'failed';
                            $validation['errors'][] = "Критически много {$description}: {$percentage}% ({$count} из {$totalOrders})";
                        } elseif ($percentage > 5) {
                            if ($validation['status'] === 'passed') $validation['status'] = 'warning';
                            $validation['warnings'][] = "Много {$description}: {$percentage}% ({$count} из {$totalOrders})";
                        }
                    }
                }
            }
            
            return $validation;
            
        } catch (Exception $e) {
            $this->log("Error in validateDataCompleteness: " . $e->getMessage());
            return [
                'status' => 'error',
                'errors' => ['Ошибка при проверке полноты данных: ' . $e->getMessage()],
                'warnings' => [],
                'details' => []
            ];
        }
    }
    
    /**
     * Проверить консистентность выручки по дням
     * 
     * @param string $startDate - начальная дата
     * @param string $endDate - конечная дата
     * @param int|null $clientId - ID клиента
     * @return array результат проверки
     */
    private function validateRevenueConsistency($startDate, $endDate, $clientId = null) {
        $this->log("Validating revenue consistency");
        
        try {
            // Получаем данные по дням для всех маркетплейсов
            $totalDaily = $this->marginAPI->getDailyMarginChartByMarketplace($startDate, $endDate, null, $clientId);
            $ozonDaily = $this->marginAPI->getDailyMarginChartByMarketplace($startDate, $endDate, 'ozon', $clientId);
            $wbDaily = $this->marginAPI->getDailyMarginChartByMarketplace($startDate, $endDate, 'wildberries', $clientId);
            
            $validation = [
                'status' => 'passed',
                'warnings' => [],
                'errors' => [],
                'details' => [
                    'days_checked' => 0,
                    'days_with_discrepancies' => 0,
                    'max_discrepancy' => 0,
                    'avg_discrepancy' => 0
                ]
            ];
            
            // Проверяем успешность получения данных
            if (!is_array($totalDaily) || !is_array($ozonDaily) || !is_array($wbDaily)) {
                $validation['status'] = 'error';
                $validation['errors'][] = 'Не удалось получить данные для проверки консистентности выручки';
                return $validation;
            }
            
            // Создаем индексы по датам
            $totalByDate = [];
            $ozonByDate = [];
            $wbByDate = [];
            
            foreach ($totalDaily as $day) {
                $totalByDate[$day['metric_date']] = $day['revenue'];
            }
            
            foreach ($ozonDaily as $day) {
                $ozonByDate[$day['metric_date']] = $day['revenue'];
            }
            
            foreach ($wbDaily as $day) {
                $wbByDate[$day['metric_date']] = $day['revenue'];
            }
            
            $discrepancies = [];
            $daysChecked = 0;
            
            // Проверяем каждый день
            foreach ($totalByDate as $date => $totalRevenue) {
                $ozonRevenue = $ozonByDate[$date] ?? 0;
                $wbRevenue = $wbByDate[$date] ?? 0;
                $marketplacesSum = $ozonRevenue + $wbRevenue;
                
                if ($totalRevenue > 0) {
                    $discrepancy = $this->calculateDiscrepancy($totalRevenue, $marketplacesSum);
                    $discrepancies[] = $discrepancy;
                    $daysChecked++;
                    
                    if ($discrepancy > self::CRITICAL_THRESHOLD_PERCENTAGE) {
                        $validation['errors'][] = "Критическое расхождение выручки на {$date}: {$discrepancy}% (общая: {$totalRevenue}, сумма: {$marketplacesSum})";
                        $validation['status'] = 'failed';
                    } elseif ($discrepancy > self::WARNING_THRESHOLD_PERCENTAGE) {
                        $validation['warnings'][] = "Расхождение выручки на {$date}: {$discrepancy}% (общая: {$totalRevenue}, сумма: {$marketplacesSum})";
                        if ($validation['status'] === 'passed') $validation['status'] = 'warning';
                    }
                }
            }
            
            // Статистика по расхождениям
            if (!empty($discrepancies)) {
                $validation['details']['days_checked'] = $daysChecked;
                $validation['details']['days_with_discrepancies'] = count(array_filter($discrepancies, function($d) { 
                    return $d > self::WARNING_THRESHOLD_PERCENTAGE; 
                }));
                $validation['details']['max_discrepancy'] = round(max($discrepancies), 2);
                $validation['details']['avg_discrepancy'] = round(array_sum($discrepancies) / count($discrepancies), 2);
            }
            
            return $validation;
            
        } catch (Exception $e) {
            $this->log("Error in validateRevenueConsistency: " . $e->getMessage());
            return [
                'status' => 'error',
                'errors' => ['Ошибка при проверке консистентности выручки: ' . $e->getMessage()],
                'warnings' => [],
                'details' => []
            ];
        }
    }
    
    /**
     * Рассчитать процент расхождения между двумя значениями
     * 
     * @param float $value1 - первое значение
     * @param float $value2 - второе значение
     * @return float процент расхождения
     */
    private function calculateDiscrepancy($value1, $value2) {
        if ($value1 == 0 && $value2 == 0) {
            return 0;
        }
        
        if ($value1 == 0) {
            return 100;
        }
        
        return round(abs($value1 - $value2) / $value1 * 100, 2);
    }
    
    /**
     * Определить общий статус валидации
     * 
     * @param array $validationResults - результаты всех проверок
     * @return string общий статус
     */
    private function determineOverallStatus($validationResults) {
        $hasErrors = !empty($validationResults['errors']);
        $hasWarnings = !empty($validationResults['warnings']);
        
        if ($hasErrors) {
            return 'failed';
        } elseif ($hasWarnings) {
            return 'warning';
        } else {
            return 'passed';
        }
    }
    
    /**
     * Сгенерировать рекомендации на основе результатов валидации
     * 
     * @param array $validationResults - результаты валидации
     * @return array список рекомендаций
     */
    private function generateRecommendations($validationResults) {
        $recommendations = [];
        
        // Рекомендации на основе ошибок
        if (!empty($validationResults['errors'])) {
            $recommendations[] = 'Обнаружены критические проблемы с данными - требуется немедленное вмешательство администратора';
            $recommendations[] = 'Проверьте настройки импорта данных и процедуры ETL';
            $recommendations[] = 'Рассмотрите возможность временного отключения проблемных источников данных';
        }
        
        // Рекомендации на основе предупреждений
        if (!empty($validationResults['warnings'])) {
            $recommendations[] = 'Обнаружены проблемы с качеством данных - рекомендуется провести аудит';
            $recommendations[] = 'Улучшите процедуры валидации данных при импорте';
        }
        
        // Специфичные рекомендации
        $validations = $validationResults['validations'];
        
        if (isset($validations[self::VALIDATION_MARKETPLACE_CLASSIFICATION])) {
            $classification = $validations[self::VALIDATION_MARKETPLACE_CLASSIFICATION];
            if ($classification['status'] !== 'passed') {
                $recommendations[] = 'Улучшите алгоритмы определения маркетплейсов';
                $recommendations[] = 'Добавьте дополнительные поля для идентификации источников';
            }
        }
        
        if (isset($validations[self::VALIDATION_SKU_CONSISTENCY])) {
            $sku = $validations[self::VALIDATION_SKU_CONSISTENCY];
            if ($sku['status'] !== 'passed') {
                $recommendations[] = 'Заполните отсутствующие SKU для маркетплейсов';
                $recommendations[] = 'Проверьте уникальность SKU между маркетплейсами';
            }
        }
        
        if (isset($validations[self::VALIDATION_DATA_COMPLETENESS])) {
            $completeness = $validations[self::VALIDATION_DATA_COMPLETENESS];
            if ($completeness['status'] !== 'passed') {
                $recommendations[] = 'Заполните отсутствующие обязательные поля в данных';
                $recommendations[] = 'Добавьте валидацию данных на этапе импорта';
            }
        }
        
        // Общие рекомендации
        if (empty($recommendations)) {
            $recommendations[] = 'Данные соответствуют стандартам качества';
            $recommendations[] = 'Продолжайте регулярный мониторинг качества данных';
        }
        
        return array_unique($recommendations);
    }
    
    /**
     * Получить отчет о качестве данных за период
     * 
     * @param string $startDate - начальная дата
     * @param string $endDate - конечная дата
     * @param int|null $clientId - ID клиента
     * @return array отчет о качестве данных
     */
    public function getDataQualityReport($startDate, $endDate, $clientId = null) {
        $validation = $this->validateMarketplaceData($startDate, $endDate, $clientId);
        
        // Создаем сводный отчет
        $report = [
            'period' => $validation['period'],
            'timestamp' => $validation['timestamp'],
            'overall_score' => $this->calculateQualityScore($validation),
            'status' => $validation['overall_status'],
            'summary' => [
                'total_validations' => count($validation['validations']),
                'passed_validations' => 0,
                'warning_validations' => 0,
                'failed_validations' => 0,
                'error_validations' => 0
            ],
            'issues' => [
                'critical' => count($validation['errors']),
                'warnings' => count($validation['warnings'])
            ],
            'recommendations' => $validation['recommendations'],
            'detailed_results' => $validation['validations']
        ];
        
        // Подсчитываем статистику по валидациям
        foreach ($validation['validations'] as $validationResult) {
            switch ($validationResult['status']) {
                case 'passed':
                    $report['summary']['passed_validations']++;
                    break;
                case 'warning':
                    $report['summary']['warning_validations']++;
                    break;
                case 'failed':
                    $report['summary']['failed_validations']++;
                    break;
                case 'error':
                    $report['summary']['error_validations']++;
                    break;
            }
        }
        
        return $report;
    }
    
    /**
     * Рассчитать общий балл качества данных
     * 
     * @param array $validation - результаты валидации
     * @return int балл от 0 до 100
     */
    private function calculateQualityScore($validation) {
        $totalValidations = count($validation['validations']);
        if ($totalValidations === 0) {
            return 0;
        }
        
        $score = 100;
        
        // Снижаем балл за каждую проблему
        foreach ($validation['validations'] as $validationResult) {
            switch ($validationResult['status']) {
                case 'failed':
                    $score -= 25; // Критические ошибки сильно снижают балл
                    break;
                case 'error':
                    $score -= 20; // Технические ошибки
                    break;
                case 'warning':
                    $score -= 10; // Предупреждения умеренно снижают балл
                    break;
            }
        }
        
        return max(0, $score); // Не может быть меньше 0
    }
    
    /**
     * Логирование
     * 
     * @param string $message - сообщение для логирования
     */
    private function log($message) {
        $timestamp = date('Y-m-d H:i:s');
        $logEntry = "[$timestamp] $message\n";
        file_put_contents($this->logFile, $logEntry, FILE_APPEND | LOCK_EX);
    }
    
    /**
     * Создать задачу мониторинга качества данных
     * 
     * @param string $schedule - расписание проверки (daily, weekly, monthly)
     * @param array $recipients - получатели уведомлений
     * @return array конфигурация задачи мониторинга
     */
    public function createDataQualityMonitoringTask($schedule = 'daily', $recipients = []) {
        return [
            'task_name' => 'marketplace_data_quality_monitoring',
            'schedule' => $schedule,
            'description' => 'Автоматический мониторинг качества данных маркетплейсов',
            'action' => 'validateMarketplaceData',
            'parameters' => [
                'period' => $schedule === 'daily' ? '1 day' : ($schedule === 'weekly' ? '7 days' : '30 days'),
                'alert_on_errors' => true,
                'alert_on_warnings' => true,
                'recipients' => $recipients
            ],
            'created_at' => date('Y-m-d H:i:s')
        ];
    }
}
?>