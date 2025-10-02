<?php
/**
 * MarketplaceValidator Class - Валидация и проверка качества данных маркетплейсов
 * 
 * Предоставляет методы для валидации корректности классификации данных по маркетплейсам,
 * проверки консистентности SKU назначений и генерации отчетов о качестве данных
 * 
 * @version 1.0
 * @author Manhattan System
 */

require_once 'MarketplaceDetector.php';

class MarketplaceValidator {
    
    private $pdo;
    private $detector;
    
    /**
     * Конструктор класса
     * 
     * @param PDO $pdo - подключение к базе данных
     */
    public function __construct(PDO $pdo) {
        $this->pdo = $pdo;
        $this->detector = new MarketplaceDetector($pdo);
    }
    
    /**
     * Валидация корректности SKU назначений для товаров
     * Проверяет что товары имеют правильные SKU для соответствующих маркетплейсов
     * 
     * @param int|null $clientId - ID клиента для фильтрации (null для всех)
     * @return array результат валидации
     */
    public function validateSkuAssignments($clientId = null) {
        $sql = "
            SELECT 
                dp.id,
                dp.name,
                dp.sku_ozon,
                dp.sku_wb,
                dp.brand,
                dp.category,
                COUNT(DISTINCT CASE WHEN s.code LIKE '%ozon%' OR s.name LIKE '%ozon%' THEN fo.id END) as ozon_orders,
                COUNT(DISTINCT CASE WHEN s.code LIKE '%wb%' OR s.code LIKE '%wildberries%' THEN fo.id END) as wb_orders,
                COUNT(DISTINCT fo.id) as total_orders
            FROM dim_products dp
            LEFT JOIN fact_orders fo ON dp.id = fo.product_id
            LEFT JOIN sources s ON fo.source_id = s.id
            WHERE 1=1
        ";
        
        $params = [];
        if ($clientId !== null) {
            $sql .= " AND fo.client_id = :client_id";
            $params['client_id'] = $clientId;
        }
        
        $sql .= " GROUP BY dp.id, dp.name, dp.sku_ozon, dp.sku_wb, dp.brand, dp.category";
        
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        $products = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        $validation_results = [
            'total_products' => count($products),
            'valid_products' => 0,
            'issues' => [
                'missing_ozon_sku' => [],
                'missing_wb_sku' => [],
                'unused_ozon_sku' => [],
                'unused_wb_sku' => [],
                'conflicting_sku' => []
            ],
            'statistics' => [
                'products_with_ozon_sku' => 0,
                'products_with_wb_sku' => 0,
                'products_with_both_sku' => 0,
                'products_with_no_sku' => 0,
                'products_with_ozon_orders' => 0,
                'products_with_wb_orders' => 0
            ]
        ];
        
        foreach ($products as $product) {
            $has_ozon_sku = !empty($product['sku_ozon']);
            $has_wb_sku = !empty($product['sku_wb']);
            $has_ozon_orders = $product['ozon_orders'] > 0;
            $has_wb_orders = $product['wb_orders'] > 0;
            
            // Статистика
            if ($has_ozon_sku) $validation_results['statistics']['products_with_ozon_sku']++;
            if ($has_wb_sku) $validation_results['statistics']['products_with_wb_sku']++;
            if ($has_ozon_sku && $has_wb_sku) $validation_results['statistics']['products_with_both_sku']++;
            if (!$has_ozon_sku && !$has_wb_sku) $validation_results['statistics']['products_with_no_sku']++;
            if ($has_ozon_orders) $validation_results['statistics']['products_with_ozon_orders']++;
            if ($has_wb_orders) $validation_results['statistics']['products_with_wb_orders']++;
            
            $is_valid = true;
            
            // Проверка 1: Товар продается на Ozon, но нет sku_ozon
            if ($has_ozon_orders && !$has_ozon_sku) {
                $validation_results['issues']['missing_ozon_sku'][] = [
                    'product_id' => $product['id'],
                    'product_name' => $product['name'],
                    'brand' => $product['brand'],
                    'ozon_orders' => $product['ozon_orders']
                ];
                $is_valid = false;
            }
            
            // Проверка 2: Товар продается на Wildberries, но нет sku_wb
            if ($has_wb_orders && !$has_wb_sku) {
                $validation_results['issues']['missing_wb_sku'][] = [
                    'product_id' => $product['id'],
                    'product_name' => $product['name'],
                    'brand' => $product['brand'],
                    'wb_orders' => $product['wb_orders']
                ];
                $is_valid = false;
            }
            
            // Проверка 3: Есть sku_ozon, но нет заказов с Ozon
            if ($has_ozon_sku && !$has_ozon_orders && $product['total_orders'] > 0) {
                $validation_results['issues']['unused_ozon_sku'][] = [
                    'product_id' => $product['id'],
                    'product_name' => $product['name'],
                    'sku_ozon' => $product['sku_ozon'],
                    'total_orders' => $product['total_orders']
                ];
                // Это предупреждение, не критическая ошибка
            }
            
            // Проверка 4: Есть sku_wb, но нет заказов с Wildberries
            if ($has_wb_sku && !$has_wb_orders && $product['total_orders'] > 0) {
                $validation_results['issues']['unused_wb_sku'][] = [
                    'product_id' => $product['id'],
                    'product_name' => $product['name'],
                    'sku_wb' => $product['sku_wb'],
                    'total_orders' => $product['total_orders']
                ];
                // Это предупреждение, не критическая ошибка
            }
            
            // Проверка 5: Конфликтующие SKU (одинаковые SKU для разных маркетплейсов)
            if ($has_ozon_sku && $has_wb_sku && $product['sku_ozon'] === $product['sku_wb']) {
                $validation_results['issues']['conflicting_sku'][] = [
                    'product_id' => $product['id'],
                    'product_name' => $product['name'],
                    'conflicting_sku' => $product['sku_ozon']
                ];
                $is_valid = false;
            }
            
            if ($is_valid) {
                $validation_results['valid_products']++;
            }
        }
        
        // Рассчитываем общий процент валидности
        $validation_results['validity_percentage'] = $validation_results['total_products'] > 0 
            ? round(($validation_results['valid_products'] / $validation_results['total_products']) * 100, 2)
            : 100;
            
        return $validation_results;
    }
    
    /**
     * Проверка консистентности данных между маркетплейсами
     * Валидирует что сумма данных по маркетплейсам соответствует общим показателям
     * 
     * @param string $startDate - начальная дата периода
     * @param string $endDate - конечная дата периода
     * @param int|null $clientId - ID клиента
     * @return array результат проверки консистентности
     */
    public function validateDataConsistency($startDate, $endDate, $clientId = null) {
        // Получаем общие показатели
        $totalStats = $this->getTotalStats($startDate, $endDate, $clientId);
        
        // Получаем показатели по маркетплейсам
        $ozonStats = $this->getMarketplaceStats($startDate, $endDate, MarketplaceDetector::OZON, $clientId);
        $wbStats = $this->getMarketplaceStats($startDate, $endDate, MarketplaceDetector::WILDBERRIES, $clientId);
        $unknownStats = $this->getMarketplaceStats($startDate, $endDate, MarketplaceDetector::UNKNOWN, $clientId);
        
        $marketplaceSum = [
            'orders_count' => $ozonStats['orders_count'] + $wbStats['orders_count'] + $unknownStats['orders_count'],
            'total_revenue' => $ozonStats['total_revenue'] + $wbStats['total_revenue'] + $unknownStats['total_revenue'],
            'unique_products' => $ozonStats['unique_products'] + $wbStats['unique_products'] + $unknownStats['unique_products']
        ];
        
        $consistency_check = [
            'period' => ['start' => $startDate, 'end' => $endDate],
            'client_id' => $clientId,
            'total_stats' => $totalStats,
            'marketplace_breakdown' => [
                'ozon' => $ozonStats,
                'wildberries' => $wbStats,
                'unknown' => $unknownStats
            ],
            'marketplace_sum' => $marketplaceSum,
            'consistency_issues' => [],
            'is_consistent' => true
        ];
        
        // Проверяем консистентность по количеству заказов
        $orders_diff = abs($totalStats['orders_count'] - $marketplaceSum['orders_count']);
        if ($orders_diff > 0) {
            $consistency_check['consistency_issues'][] = [
                'type' => 'orders_count_mismatch',
                'total' => $totalStats['orders_count'],
                'marketplace_sum' => $marketplaceSum['orders_count'],
                'difference' => $orders_diff,
                'severity' => $orders_diff > ($totalStats['orders_count'] * 0.05) ? 'high' : 'low'
            ];
            $consistency_check['is_consistent'] = false;
        }
        
        // Проверяем консистентность по выручке
        $revenue_diff = abs($totalStats['total_revenue'] - $marketplaceSum['total_revenue']);
        $revenue_threshold = $totalStats['total_revenue'] * 0.01; // 1% допустимое отклонение
        if ($revenue_diff > $revenue_threshold) {
            $consistency_check['consistency_issues'][] = [
                'type' => 'revenue_mismatch',
                'total' => $totalStats['total_revenue'],
                'marketplace_sum' => $marketplaceSum['total_revenue'],
                'difference' => $revenue_diff,
                'percentage' => round(($revenue_diff / $totalStats['total_revenue']) * 100, 2),
                'severity' => $revenue_diff > ($totalStats['total_revenue'] * 0.05) ? 'high' : 'medium'
            ];
            $consistency_check['is_consistent'] = false;
        }
        
        return $consistency_check;
    }
    
    /**
     * Генерация отчета о качестве данных маркетплейсов
     * 
     * @param string $startDate - начальная дата периода
     * @param string $endDate - конечная дата периода
     * @param int|null $clientId - ID клиента
     * @return array полный отчет о качестве данных
     */
    public function generateDataQualityReport($startDate, $endDate, $clientId = null) {
        $report = [
            'generated_at' => date('Y-m-d H:i:s'),
            'period' => ['start' => $startDate, 'end' => $endDate],
            'client_id' => $clientId,
            'overall_quality_score' => 0,
            'quality_rating' => 'unknown'
        ];
        
        // 1. Валидация SKU назначений
        $sku_validation = $this->validateSkuAssignments($clientId);
        $report['sku_validation'] = $sku_validation;
        
        // 2. Проверка консистентности данных
        $consistency_check = $this->validateDataConsistency($startDate, $endDate, $clientId);
        $report['consistency_check'] = $consistency_check;
        
        // 3. Анализ классификации маркетплейсов
        $classification_analysis = $this->detector->validateMarketplaceClassification($startDate, $endDate, $clientId);
        $report['classification_analysis'] = $classification_analysis;
        
        // 4. Проверка дублирующихся заказов
        $duplicate_check = $this->checkDuplicateOrders($startDate, $endDate, $clientId);
        $report['duplicate_check'] = $duplicate_check;
        
        // 5. Анализ источников данных
        $source_analysis = $this->analyzeDataSources($startDate, $endDate, $clientId);
        $report['source_analysis'] = $source_analysis;
        
        // Рассчитываем общий балл качества данных
        $quality_scores = [
            'sku_validity' => $sku_validation['validity_percentage'],
            'data_consistency' => $consistency_check['is_consistent'] ? 100 : 70,
            'classification_rate' => $classification_analysis['classification_rate'],
            'duplicate_rate' => 100 - $duplicate_check['duplicate_percentage']
        ];
        
        $report['quality_scores'] = $quality_scores;
        $report['overall_quality_score'] = round(array_sum($quality_scores) / count($quality_scores), 2);
        
        // Определяем рейтинг качества
        if ($report['overall_quality_score'] >= 95) {
            $report['quality_rating'] = 'excellent';
        } elseif ($report['overall_quality_score'] >= 85) {
            $report['quality_rating'] = 'good';
        } elseif ($report['overall_quality_score'] >= 70) {
            $report['quality_rating'] = 'fair';
        } else {
            $report['quality_rating'] = 'poor';
        }
        
        // Генерируем рекомендации по улучшению
        $report['recommendations'] = $this->generateRecommendations($report);
        
        return $report;
    }
    
    /**
     * Проверка дублирующихся заказов в системе
     * 
     * @param string $startDate - начальная дата периода
     * @param string $endDate - конечная дата периода
     * @param int|null $clientId - ID клиента
     * @return array результат проверки дубликатов
     */
    private function checkDuplicateOrders($startDate, $endDate, $clientId = null) {
        $sql = "
            SELECT 
                order_id,
                sku,
                order_date,
                COUNT(*) as duplicate_count,
                GROUP_CONCAT(id) as order_ids,
                GROUP_CONCAT(DISTINCT source_id) as source_ids
            FROM fact_orders
            WHERE order_date BETWEEN :start_date AND :end_date
        ";
        
        $params = [
            'start_date' => $startDate,
            'end_date' => $endDate
        ];
        
        if ($clientId !== null) {
            $sql .= " AND client_id = :client_id";
            $params['client_id'] = $clientId;
        }
        
        $sql .= " GROUP BY order_id, sku, order_date HAVING COUNT(*) > 1";
        
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        $duplicates = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Подсчитываем общее количество заказов для расчета процента
        $totalOrdersSql = "
            SELECT COUNT(*) as total_orders
            FROM fact_orders
            WHERE order_date BETWEEN :start_date AND :end_date
        ";
        
        if ($clientId !== null) {
            $totalOrdersSql .= " AND client_id = :client_id";
        }
        
        $totalStmt = $this->pdo->prepare($totalOrdersSql);
        $totalStmt->execute($params);
        $totalOrders = $totalStmt->fetchColumn();
        
        $duplicateOrdersCount = array_sum(array_column($duplicates, 'duplicate_count'));
        
        return [
            'total_orders' => $totalOrders,
            'duplicate_groups' => count($duplicates),
            'duplicate_orders_count' => $duplicateOrdersCount,
            'duplicate_percentage' => $totalOrders > 0 ? round(($duplicateOrdersCount / $totalOrders) * 100, 2) : 0,
            'duplicates' => $duplicates
        ];
    }
    
    /**
     * Анализ источников данных
     * 
     * @param string $startDate - начальная дата периода
     * @param string $endDate - конечная дата периода
     * @param int|null $clientId - ID клиента
     * @return array анализ источников данных
     */
    private function analyzeDataSources($startDate, $endDate, $clientId = null) {
        $sql = "
            SELECT 
                s.id,
                s.code,
                s.name,
                COUNT(fo.id) as orders_count,
                SUM(fo.qty * fo.price) as total_revenue,
                MIN(fo.order_date) as first_order_date,
                MAX(fo.order_date) as last_order_date,
                COUNT(DISTINCT fo.product_id) as unique_products
            FROM sources s
            LEFT JOIN fact_orders fo ON s.id = fo.source_id 
                AND fo.order_date BETWEEN :start_date AND :end_date
        ";
        
        $params = [
            'start_date' => $startDate,
            'end_date' => $endDate
        ];
        
        if ($clientId !== null) {
            $sql .= " AND fo.client_id = :client_id";
            $params['client_id'] = $clientId;
        }
        
        $sql .= " GROUP BY s.id, s.code, s.name ORDER BY total_revenue DESC";
        
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        $sources = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        $analysis = [
            'total_sources' => count($sources),
            'active_sources' => 0,
            'inactive_sources' => 0,
            'sources_by_marketplace' => [
                MarketplaceDetector::OZON => [],
                MarketplaceDetector::WILDBERRIES => [],
                MarketplaceDetector::UNKNOWN => []
            ],
            'sources_detail' => []
        ];
        
        foreach ($sources as $source) {
            $marketplace = MarketplaceDetector::detectFromSourceCode($source['code']);
            $isActive = $source['orders_count'] > 0;
            
            if ($isActive) {
                $analysis['active_sources']++;
            } else {
                $analysis['inactive_sources']++;
            }
            
            $sourceDetail = [
                'id' => $source['id'],
                'code' => $source['code'],
                'name' => $source['name'],
                'marketplace' => $marketplace,
                'orders_count' => $source['orders_count'],
                'total_revenue' => $source['total_revenue'],
                'unique_products' => $source['unique_products'],
                'is_active' => $isActive
            ];
            
            $analysis['sources_by_marketplace'][$marketplace][] = $sourceDetail;
            $analysis['sources_detail'][] = $sourceDetail;
        }
        
        return $analysis;
    }
    
    /**
     * Получение общих статистических данных
     * 
     * @param string $startDate - начальная дата
     * @param string $endDate - конечная дата
     * @param int|null $clientId - ID клиента
     * @return array общая статистика
     */
    private function getTotalStats($startDate, $endDate, $clientId = null) {
        $sql = "
            SELECT 
                COUNT(DISTINCT order_id) as orders_count,
                SUM(qty * price) as total_revenue,
                COUNT(DISTINCT product_id) as unique_products
            FROM fact_orders
            WHERE order_date BETWEEN :start_date AND :end_date
                AND transaction_type IN ('продажа', 'sale', 'order')
        ";
        
        $params = [
            'start_date' => $startDate,
            'end_date' => $endDate
        ];
        
        if ($clientId !== null) {
            $sql .= " AND client_id = :client_id";
            $params['client_id'] = $clientId;
        }
        
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }
    
    /**
     * Получение статистики по конкретному маркетплейсу
     * 
     * @param string $startDate - начальная дата
     * @param string $endDate - конечная дата
     * @param string $marketplace - маркетплейс
     * @param int|null $clientId - ID клиента
     * @return array статистика по маркетплейсу
     */
    private function getMarketplaceStats($startDate, $endDate, $marketplace, $clientId = null) {
        $filter = $this->detector->buildMarketplaceFilter($marketplace);
        
        $sql = "
            SELECT 
                COUNT(DISTINCT fo.order_id) as orders_count,
                SUM(fo.qty * fo.price) as total_revenue,
                COUNT(DISTINCT fo.product_id) as unique_products
            FROM fact_orders fo
            JOIN sources s ON fo.source_id = s.id
            LEFT JOIN dim_products dp ON fo.product_id = dp.id
            WHERE fo.order_date BETWEEN :start_date AND :end_date
                AND fo.transaction_type IN ('продажа', 'sale', 'order')
                AND ({$filter['condition']})
        ";
        
        $params = array_merge([
            'start_date' => $startDate,
            'end_date' => $endDate
        ], $filter['params']);
        
        if ($clientId !== null) {
            $sql .= " AND fo.client_id = :client_id";
            $params['client_id'] = $clientId;
        }
        
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        
        // Если результат пустой, возвращаем нули
        return [
            'orders_count' => $result['orders_count'] ?? 0,
            'total_revenue' => $result['total_revenue'] ?? 0,
            'unique_products' => $result['unique_products'] ?? 0
        ];
    }
    
    /**
     * Генерация рекомендаций по улучшению качества данных
     * 
     * @param array $report - отчет о качестве данных
     * @return array список рекомендаций
     */
    private function generateRecommendations($report) {
        $recommendations = [];
        
        // Рекомендации по SKU
        if ($report['sku_validation']['validity_percentage'] < 90) {
            $recommendations[] = [
                'category' => 'sku_management',
                'priority' => 'high',
                'title' => 'Улучшить управление SKU',
                'description' => 'Обнаружены проблемы с назначением SKU для маркетплейсов',
                'actions' => [
                    'Добавить отсутствующие sku_ozon для товаров, продающихся на Ozon',
                    'Добавить отсутствующие sku_wb для товаров, продающихся на Wildberries',
                    'Устранить конфликтующие SKU (одинаковые для разных маркетплейсов)'
                ]
            ];
        }
        
        // Рекомендации по консистентности данных
        if (!$report['consistency_check']['is_consistent']) {
            $recommendations[] = [
                'category' => 'data_consistency',
                'priority' => 'high',
                'title' => 'Исправить несоответствия в данных',
                'description' => 'Обнаружены расхождения между общими показателями и суммой по маркетплейсам',
                'actions' => [
                    'Проверить корректность классификации заказов по маркетплейсам',
                    'Убедиться в отсутствии дублирующихся записей',
                    'Проверить настройки источников данных'
                ]
            ];
        }
        
        // Рекомендации по классификации
        if ($report['classification_analysis']['classification_rate'] < 95) {
            $recommendations[] = [
                'category' => 'classification',
                'priority' => 'medium',
                'title' => 'Улучшить классификацию маркетплейсов',
                'description' => 'Высокий процент заказов с неопределенным маркетплейсом',
                'actions' => [
                    'Обновить правила определения маркетплейсов в MarketplaceDetector',
                    'Добавить новые паттерны для распознавания источников',
                    'Проверить корректность заполнения полей source.code и source.name'
                ]
            ];
        }
        
        // Рекомендации по дубликатам
        if ($report['duplicate_check']['duplicate_percentage'] > 1) {
            $recommendations[] = [
                'category' => 'data_quality',
                'priority' => 'medium',
                'title' => 'Устранить дублирующиеся заказы',
                'description' => 'Обнаружены дублирующиеся записи заказов',
                'actions' => [
                    'Проверить процедуры импорта данных',
                    'Добавить дополнительные проверки уникальности',
                    'Очистить существующие дубликаты'
                ]
            ];
        }
        
        return $recommendations;
    }
    
    /**
     * Экспорт отчета о качестве данных в JSON формат
     * 
     * @param array $report - отчет о качестве данных
     * @param string $filename - имя файла для сохранения
     * @return bool успешность сохранения
     */
    public function exportReportToJson($report, $filename = null) {
        if ($filename === null) {
            $filename = 'marketplace_data_quality_report_' . date('Y-m-d_H-i-s') . '.json';
        }
        
        $json = json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        
        return file_put_contents($filename, $json) !== false;
    }
    
    /**
     * Получение краткой сводки о качестве данных
     * 
     * @param string $startDate - начальная дата периода
     * @param string $endDate - конечная дата периода
     * @param int|null $clientId - ID клиента
     * @return array краткая сводка
     */
    public function getQualitySummary($startDate, $endDate, $clientId = null) {
        $report = $this->generateDataQualityReport($startDate, $endDate, $clientId);
        
        return [
            'overall_quality_score' => $report['overall_quality_score'],
            'quality_rating' => $report['quality_rating'],
            'classification_rate' => $report['classification_analysis']['classification_rate'],
            'sku_validity_rate' => $report['sku_validation']['validity_percentage'],
            'data_consistency' => $report['consistency_check']['is_consistent'],
            'total_orders' => $report['classification_analysis']['total_orders'],
            'recommendations_count' => count($report['recommendations'])
        ];
    }
}
?>