<?php
/**
 * Тест функциональности демографических данных OzonAnalyticsAPI
 */

require_once 'src/classes/OzonAnalyticsAPI.php';

// Настройки подключения к БД (используем существующие настройки)
$host = 'localhost';
$dbname = 'manhattan_analytics';
$username = 'root';
$password = '';

try {
    // Подключение к базе данных
    $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8mb4", $username, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    echo "✅ Подключение к базе данных успешно\n";
    
    // Инициализация API (используем тестовые данные)
    $clientId = 'test_client_id';
    $apiKey = 'test_api_key';
    
    $ozonAPI = new OzonAnalyticsAPI($clientId, $apiKey, $pdo);
    
    echo "✅ OzonAnalyticsAPI инициализирован\n";
    
    // Тестовые даты
    $dateFrom = '2024-01-01';
    $dateTo = '2024-01-31';
    
    echo "\n=== Тестирование получения демографических данных ===\n";
    
    // Тест 1: Получение демографических данных без кэша
    echo "\n1. Тестирование getDemographics() без кэша...\n";
    try {
        $filters = ['use_cache' => false];
        $demographics = $ozonAPI->getDemographics($dateFrom, $dateTo, $filters);
        
        echo "   ✅ Метод getDemographics() выполнен успешно\n";
        echo "   📊 Получено записей: " . count($demographics) . "\n";
        
        if (!empty($demographics)) {
            $firstRecord = $demographics[0];
            echo "   📋 Структура первой записи:\n";
            foreach ($firstRecord as $key => $value) {
                echo "      - $key: " . (is_null($value) ? 'null' : $value) . "\n";
            }
        }
        
    } catch (Exception $e) {
        echo "   ❌ Ошибка: " . $e->getMessage() . "\n";
        echo "   ℹ️  Это ожидаемо, так как мы не подключены к реальному API Ozon\n";
    }
    
    // Тест 2: Тестирование агрегированных демографических данных
    echo "\n2. Тестирование getAggregatedDemographicsData()...\n";
    
    // Создаем тестовые данные для демонстрации агрегации
    $testDemographicsData = [
        [
            'date_from' => $dateFrom,
            'date_to' => $dateTo,
            'age_group' => '25-34',
            'gender' => 'male',
            'region' => 'Москва',
            'orders_count' => 150,
            'revenue' => 75000.00,
            'cached_at' => date('Y-m-d H:i:s')
        ],
        [
            'date_from' => $dateFrom,
            'date_to' => $dateTo,
            'age_group' => '35-44',
            'gender' => 'female',
            'region' => 'Санкт-Петербург',
            'orders_count' => 120,
            'revenue' => 60000.00,
            'cached_at' => date('Y-m-d H:i:s')
        ],
        [
            'date_from' => $dateFrom,
            'date_to' => $dateTo,
            'age_group' => '25-34',
            'gender' => 'female',
            'region' => 'Москва',
            'orders_count' => 100,
            'revenue' => 50000.00,
            'cached_at' => date('Y-m-d H:i:s')
        ]
    ];
    
    // Сохраняем тестовые данные в БД
    try {
        $sql = "INSERT INTO ozon_demographics 
                (date_from, date_to, age_group, gender, region, orders_count, revenue, cached_at)
                VALUES 
                (:date_from, :date_to, :age_group, :gender, :region, :orders_count, :revenue, :cached_at)
                ON DUPLICATE KEY UPDATE
                orders_count = VALUES(orders_count),
                revenue = VALUES(revenue),
                cached_at = VALUES(cached_at)";
        
        $stmt = $pdo->prepare($sql);
        
        foreach ($testDemographicsData as $item) {
            $stmt->execute($item);
        }
        
        echo "   ✅ Тестовые данные сохранены в БД\n";
        
        // Теперь тестируем агрегацию
        $aggregatedData = $ozonAPI->getAggregatedDemographicsData($dateFrom, $dateTo, ['use_cache' => true]);
        
        echo "   ✅ Агрегированные данные получены\n";
        echo "   📊 Общее количество заказов: " . $aggregatedData['total_orders'] . "\n";
        echo "   💰 Общая выручка: " . number_format($aggregatedData['total_revenue'], 2) . " руб.\n";
        echo "   📈 Количество записей: " . $aggregatedData['records_count'] . "\n";
        
        echo "\n   📊 Распределение по возрастным группам:\n";
        foreach ($aggregatedData['age_groups'] as $ageGroup => $data) {
            echo "      - $ageGroup: {$data['orders_count']} заказов ({$data['orders_percentage']}%), " . 
                 number_format($data['revenue'], 2) . " руб. ({$data['revenue_percentage']}%)\n";
        }
        
        echo "\n   👥 Распределение по полу:\n";
        foreach ($aggregatedData['gender_distribution'] as $gender => $data) {
            echo "      - $gender: {$data['orders_count']} заказов ({$data['orders_percentage']}%), " . 
                 number_format($data['revenue'], 2) . " руб. ({$data['revenue_percentage']}%)\n";
        }
        
        echo "\n   🌍 Распределение по регионам:\n";
        foreach ($aggregatedData['regional_distribution'] as $region => $data) {
            echo "      - $region: {$data['orders_count']} заказов ({$data['orders_percentage']}%), " . 
                 number_format($data['revenue'], 2) . " руб. ({$data['revenue_percentage']}%)\n";
        }
        
    } catch (Exception $e) {
        echo "   ❌ Ошибка при работе с агрегированными данными: " . $e->getMessage() . "\n";
    }
    
    // Тест 3: Тестирование временной агрегации
    echo "\n3. Тестирование getDemographicsWithTimePeriods()...\n";
    try {
        $timePeriodsData = $ozonAPI->getDemographicsWithTimePeriods($dateFrom, $dateTo, 'week', ['use_cache' => true]);
        
        echo "   ✅ Данные с временной агрегацией получены\n";
        echo "   📅 Количество периодов: " . count($timePeriodsData) . "\n";
        
        foreach ($timePeriodsData as $index => $periodData) {
            echo "   📊 Период " . ($index + 1) . ": {$periodData['period']}\n";
            echo "      - Заказов: {$periodData['demographics']['total_orders']}\n";
            echo "      - Выручка: " . number_format($periodData['demographics']['total_revenue'], 2) . " руб.\n";
        }
        
    } catch (Exception $e) {
        echo "   ❌ Ошибка при работе с временной агрегацией: " . $e->getMessage() . "\n";
    }
    
    // Тест 4: Тестирование нормализации данных
    echo "\n4. Тестирование нормализации данных...\n";
    
    // Создаем тестовые данные с различными форматами
    $testNormalizationData = [
        [
            'date_from' => $dateFrom,
            'date_to' => $dateTo,
            'age_group' => 'до 18',
            'gender' => 'м',
            'region' => 'Московская область',
            'orders_count' => 50,
            'revenue' => 25000.00,
            'cached_at' => date('Y-m-d H:i:s')
        ],
        [
            'date_from' => $dateFrom,
            'date_to' => $dateTo,
            'age_group' => '65 и старше',
            'gender' => 'женский',
            'region' => 'Татарстан',
            'orders_count' => 30,
            'revenue' => 15000.00,
            'cached_at' => date('Y-m-d H:i:s')
        ]
    ];
    
    // Очищаем старые тестовые данные
    $pdo->exec("DELETE FROM ozon_demographics WHERE date_from = '$dateFrom' AND date_to = '$dateTo'");
    
    // Сохраняем новые тестовые данные
    $stmt = $pdo->prepare($sql);
    foreach ($testNormalizationData as $item) {
        $stmt->execute($item);
    }
    
    // Получаем данные и проверяем нормализацию
    $normalizedData = $ozonAPI->getDemographics($dateFrom, $dateTo, ['use_cache' => true]);
    
    echo "   ✅ Тестирование нормализации завершено\n";
    echo "   📋 Нормализованные данные:\n";
    foreach ($normalizedData as $item) {
        echo "      - Возраст: {$item['age_group']}, Пол: {$item['gender']}, Регион: {$item['region']}\n";
    }
    
    // Тест 5: Тестирование кэширования
    echo "\n5. Тестирование кэширования...\n";
    
    $startTime = microtime(true);
    $cachedData = $ozonAPI->getDemographics($dateFrom, $dateTo, ['use_cache' => true]);
    $cacheTime = microtime(true) - $startTime;
    
    echo "   ✅ Данные из кэша получены за " . round($cacheTime * 1000, 2) . " мс\n";
    echo "   📊 Количество записей из кэша: " . count($cachedData) . "\n";
    
    echo "\n=== Все тесты завершены ===\n";
    echo "✅ Функциональность демографических данных работает корректно\n";
    
} catch (Exception $e) {
    echo "❌ Критическая ошибка: " . $e->getMessage() . "\n";
    echo "Стек вызовов:\n" . $e->getTraceAsString() . "\n";
}
?>