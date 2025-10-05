<?php
/**
 * Пример использования демографических данных OzonAnalyticsAPI
 * 
 * Этот файл демонстрирует как использовать новую функциональность
 * получения и агрегации демографических данных из API Ozon.
 */

require_once '../src/classes/OzonAnalyticsAPI.php';

// Пример настройки подключения к БД
$pdo = new PDO("mysql:host=localhost;dbname=manhattan_analytics;charset=utf8mb4", 
               "username", "password");
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

// Инициализация API с реальными учетными данными
$clientId = 'your_ozon_client_id';
$apiKey = 'your_ozon_api_key';
$ozonAPI = new OzonAnalyticsAPI($clientId, $apiKey, $pdo);

// Определяем период для анализа
$dateFrom = '2024-01-01';
$dateTo = '2024-01-31';

echo "=== Пример использования демографических данных Ozon ===\n\n";

try {
    // 1. Получение базовых демографических данных
    echo "1. Получение демографических данных...\n";
    
    $filters = [
        'use_cache' => true,  // Использовать кэш если доступен
        'region' => 'Москва'  // Фильтр по региону (опционально)
    ];
    
    $demographics = $ozonAPI->getDemographics($dateFrom, $dateTo, $filters);
    
    echo "   Получено записей: " . count($demographics) . "\n";
    echo "   Период: $dateFrom - $dateTo\n\n";
    
    // 2. Получение агрегированных демографических данных
    echo "2. Агрегированные демографические данные...\n";
    
    $aggregatedData = $ozonAPI->getAggregatedDemographicsData($dateFrom, $dateTo, $filters);
    
    echo "   Общее количество заказов: {$aggregatedData['total_orders']}\n";
    echo "   Общая выручка: " . number_format($aggregatedData['total_revenue'], 2) . " руб.\n\n";
    
    // Распределение по возрастным группам
    echo "   📊 Возрастные группы:\n";
    foreach ($aggregatedData['age_groups'] as $ageGroup => $data) {
        echo "      $ageGroup: {$data['orders_count']} заказов ({$data['orders_percentage']}%)\n";
    }
    echo "\n";
    
    // Распределение по полу
    echo "   👥 Распределение по полу:\n";
    foreach ($aggregatedData['gender_distribution'] as $gender => $data) {
        $genderLabel = $gender === 'male' ? 'Мужчины' : 'Женщины';
        echo "      $genderLabel: {$data['orders_count']} заказов ({$data['orders_percentage']}%)\n";
    }
    echo "\n";
    
    // Топ-5 регионов
    echo "   🌍 Топ-5 регионов:\n";
    $topRegions = array_slice($aggregatedData['regional_distribution'], 0, 5, true);
    foreach ($topRegions as $region => $data) {
        echo "      $region: {$data['orders_count']} заказов, " . 
             number_format($data['revenue'], 2) . " руб.\n";
    }
    echo "\n";
    
    // 3. Анализ по временным периодам
    echo "3. Анализ по неделям...\n";
    
    $weeklyData = $ozonAPI->getDemographicsWithTimePeriods($dateFrom, $dateTo, 'week', $filters);
    
    foreach ($weeklyData as $weekData) {
        echo "   {$weekData['period']}:\n";
        echo "      Заказов: {$weekData['demographics']['total_orders']}\n";
        echo "      Выручка: " . number_format($weekData['demographics']['total_revenue'], 2) . " руб.\n";
        
        // Показываем топ возрастную группу для недели
        if (!empty($weekData['demographics']['age_groups'])) {
            $topAgeGroup = array_keys($weekData['demographics']['age_groups'])[0];
            $topAgeData = $weekData['demographics']['age_groups'][$topAgeGroup];
            echo "      Топ возрастная группа: $topAgeGroup ({$topAgeData['orders_percentage']}%)\n";
        }
        echo "\n";
    }
    
    // 4. Экспорт данных
    echo "4. Экспорт демографических данных...\n";
    
    $exportFilters = [
        'date_from' => $dateFrom,
        'date_to' => $dateTo,
        'region' => 'Москва'
    ];
    
    // Экспорт в JSON
    $jsonData = $ozonAPI->exportData('demographics', 'json', $exportFilters);
    echo "   JSON экспорт готов (размер: " . strlen($jsonData) . " байт)\n";
    
    // Экспорт в CSV
    $csvFile = $ozonAPI->exportData('demographics', 'csv', $exportFilters);
    echo "   CSV файл создан: $csvFile\n\n";
    
    // 5. Дополнительные фильтры и анализ
    echo "5. Анализ конкретной демографической группы...\n";
    
    // Анализ молодой аудитории (18-34 года)
    $youngAudienceFilters = [
        'use_cache' => true,
        'age_group' => '25-34'
    ];
    
    $youngAudienceData = $ozonAPI->getAggregatedDemographicsData($dateFrom, $dateTo, $youngAudienceFilters);
    
    echo "   Анализ группы 25-34 года:\n";
    echo "      Заказов: {$youngAudienceData['total_orders']}\n";
    echo "      Выручка: " . number_format($youngAudienceData['total_revenue'], 2) . " руб.\n";
    
    if (!empty($youngAudienceData['gender_distribution'])) {
        echo "      Распределение по полу в этой группе:\n";
        foreach ($youngAudienceData['gender_distribution'] as $gender => $data) {
            $genderLabel = $gender === 'male' ? 'Мужчины' : 'Женщины';
            echo "         $genderLabel: {$data['orders_percentage']}%\n";
        }
    }
    echo "\n";
    
    echo "=== Анализ демографических данных завершен ===\n";
    
} catch (OzonAPIException $e) {
    echo "❌ Ошибка API Ozon: " . $e->getMessage() . "\n";
    echo "   Тип ошибки: " . $e->getErrorType() . "\n";
    echo "   Рекомендация: " . $e->getRecommendation() . "\n";
    
} catch (Exception $e) {
    echo "❌ Общая ошибка: " . $e->getMessage() . "\n";
}

// Дополнительные примеры использования

/**
 * Пример интеграции с дашбордом
 */
function getDemographicsForDashboard($ozonAPI, $dateFrom, $dateTo) {
    try {
        $data = $ozonAPI->getAggregatedDemographicsData($dateFrom, $dateTo);
        
        // Подготавливаем данные для фронтенда
        return [
            'success' => true,
            'data' => [
                'summary' => [
                    'total_orders' => $data['total_orders'],
                    'total_revenue' => $data['total_revenue'],
                    'date_range' => $data['date_range']
                ],
                'age_chart_data' => array_map(function($ageGroup, $data) {
                    return [
                        'label' => $ageGroup,
                        'value' => $data['orders_count'],
                        'percentage' => $data['orders_percentage']
                    ];
                }, array_keys($data['age_groups']), $data['age_groups']),
                'gender_chart_data' => array_map(function($gender, $data) {
                    return [
                        'label' => $gender === 'male' ? 'Мужчины' : 'Женщины',
                        'value' => $data['orders_count'],
                        'percentage' => $data['orders_percentage']
                    ];
                }, array_keys($data['gender_distribution']), $data['gender_distribution']),
                'top_regions' => array_slice($data['regional_distribution'], 0, 10, true)
            ]
        ];
        
    } catch (Exception $e) {
        return [
            'success' => false,
            'error' => $e->getMessage()
        ];
    }
}

/**
 * Пример создания отчета по демографии
 */
function generateDemographicsReport($ozonAPI, $dateFrom, $dateTo) {
    $report = [
        'title' => 'Демографический отчет Ozon',
        'period' => "$dateFrom - $dateTo",
        'generated_at' => date('Y-m-d H:i:s'),
        'sections' => []
    ];
    
    try {
        // Общая статистика
        $aggregatedData = $ozonAPI->getAggregatedDemographicsData($dateFrom, $dateTo);
        
        $report['sections']['summary'] = [
            'title' => 'Общая статистика',
            'total_orders' => $aggregatedData['total_orders'],
            'total_revenue' => $aggregatedData['total_revenue'],
            'avg_order_value' => $aggregatedData['total_orders'] > 0 ? 
                round($aggregatedData['total_revenue'] / $aggregatedData['total_orders'], 2) : 0
        ];
        
        // Анализ по возрастным группам
        $report['sections']['age_analysis'] = [
            'title' => 'Анализ по возрастным группам',
            'groups' => $aggregatedData['age_groups']
        ];
        
        // Анализ по регионам
        $report['sections']['regional_analysis'] = [
            'title' => 'Региональный анализ',
            'top_regions' => array_slice($aggregatedData['regional_distribution'], 0, 10, true)
        ];
        
        // Временной анализ
        $weeklyData = $ozonAPI->getDemographicsWithTimePeriods($dateFrom, $dateTo, 'week');
        $report['sections']['time_analysis'] = [
            'title' => 'Анализ по неделям',
            'periods' => array_map(function($period) {
                return [
                    'period' => $period['period'],
                    'orders' => $period['demographics']['total_orders'],
                    'revenue' => $period['demographics']['total_revenue']
                ];
            }, $weeklyData)
        ];
        
        return $report;
        
    } catch (Exception $e) {
        $report['error'] = $e->getMessage();
        return $report;
    }
}

?>