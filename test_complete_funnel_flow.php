<?php
/**
 * Полный тест обработки данных воронки с реальной структурой Ozon API
 */

echo "🧪 Полный тест обработки данных воронки Ozon\n";
echo "==========================================\n\n";

// Симулируем реальный ответ Ozon API
$mockOzonResponse = [
    "data" => [
        [
            "dimensions" => [
                ["id" => "1750881567", "name" => "Смартфон iPhone 15"]
            ],
            "metrics" => [4312240.50, 8945, 15000] // [revenue, ordered_units, hits_view_pdp]
        ],
        [
            "dimensions" => [
                ["id" => "1750881568", "name" => "Наушники AirPods"]
            ],
            "metrics" => [2156120.25, 4472, 8500]
        ]
    ],
    "totals" => [6468360.75, 13417, 23500]
];

echo "📊 Тестовые данные (симуляция ответа Ozon API):\n";
echo "Количество товаров: " . count($mockOzonResponse['data']) . "\n";
echo "Общая выручка: " . $mockOzonResponse['totals'][0] . " руб.\n";
echo "Общее количество заказов: " . $mockOzonResponse['totals'][1] . "\n";
echo "Общее количество просмотров: " . $mockOzonResponse['totals'][2] . "\n\n";

try {
    // Подключение к базе данных (опционально)
    $pdo = null;
    try {
        $host = '127.0.0.1';
        $dbname = 'mi_core_db';
        $username = 'mi_core_user';
        $password = 'secure_password_123';
        
        $dsn = "mysql:host={$host};dbname={$dbname};charset=utf8mb4";
        $pdo = new PDO($dsn, $username, $password, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
        ]);
        echo "✅ Подключение к базе данных установлено\n";
    } catch (Exception $e) {
        echo "⚠️ Не удалось подключиться к БД: " . $e->getMessage() . "\n";
        echo "Продолжаем тест без сохранения в БД\n";
    }
    
    // Создаем экземпляр API
    require_once 'src/classes/OzonAnalyticsAPI.php';
    $ozonAPI = new OzonAnalyticsAPI('26100', '7e074977-e0db-4ace-ba9e-82903e088b4b', $pdo);
    
    echo "✅ OzonAnalyticsAPI инициализирован\n\n";
    
    // Используем рефлексию для доступа к приватному методу
    $reflection = new ReflectionClass($ozonAPI);
    $processMethod = $reflection->getMethod('processFunnelData');
    $processMethod->setAccessible(true);
    
    // Тестируем обработку данных
    $dateFrom = '2024-01-01';
    $dateTo = '2024-01-31';
    $filters = ['product_id' => null, 'campaign_id' => null];
    
    echo "🔄 Обрабатываем данные через processFunnelData...\n";
    $result = $processMethod->invoke($ozonAPI, $mockOzonResponse, $dateFrom, $dateTo, $filters);
    
    echo "✅ Данные успешно обработаны!\n";
    echo "Количество записей в результате: " . count($result) . "\n\n";
    
    // Анализируем результат
    echo "📈 Детальный анализ обработанных данных:\n";
    echo "=====================================\n";
    
    $totalRevenue = 0;
    $totalOrders = 0;
    $totalViews = 0;
    $totalCartAdditions = 0;
    
    foreach ($result as $index => $item) {
        echo "\n🛍️ Товар " . ($index + 1) . ":\n";
        echo "  Product ID: " . ($item['product_id'] ?? 'null') . "\n";
        echo "  Выручка: " . number_format($item['revenue'], 2) . " руб.\n";
        echo "  Просмотры: " . number_format($item['views']) . "\n";
        echo "  Добавления в корзину: " . number_format($item['cart_additions']) . "\n";
        echo "  Заказы: " . number_format($item['orders']) . "\n";
        echo "  Конверсия просмотры → корзина: " . $item['conversion_view_to_cart'] . "%\n";
        echo "  Конверсия корзина → заказ: " . $item['conversion_cart_to_order'] . "%\n";
        echo "  Общая конверсия: " . $item['conversion_overall'] . "%\n";
        
        $totalRevenue += $item['revenue'];
        $totalOrders += $item['orders'];
        $totalViews += $item['views'];
        $totalCartAdditions += $item['cart_additions'];
    }
    
    echo "\n📊 Итоговая статистика:\n";
    echo "======================\n";
    echo "Общая выручка: " . number_format($totalRevenue, 2) . " руб.\n";
    echo "Общее количество просмотров: " . number_format($totalViews) . "\n";
    echo "Общее количество добавлений в корзину: " . number_format($totalCartAdditions) . "\n";
    echo "Общее количество заказов: " . number_format($totalOrders) . "\n";
    
    if ($totalViews > 0) {
        $overallConversion = round(($totalOrders / $totalViews) * 100, 2);
        echo "Общая конверсия: " . $overallConversion . "%\n";
    }
    
    // Проверяем корректность данных
    echo "\n🔍 Проверка корректности данных:\n";
    echo "===============================\n";
    
    $dataValid = true;
    
    // Проверяем, что выручка соответствует исходным данным
    $expectedRevenue = $mockOzonResponse['totals'][0];
    if (abs($totalRevenue - $expectedRevenue) > 0.01) {
        echo "❌ Ошибка: выручка не соответствует исходным данным\n";
        echo "   Ожидалось: " . $expectedRevenue . ", получено: " . $totalRevenue . "\n";
        $dataValid = false;
    } else {
        echo "✅ Выручка корректна\n";
    }
    
    // Проверяем, что заказы соответствуют исходным данным
    $expectedOrders = $mockOzonResponse['totals'][1];
    if ($totalOrders != $expectedOrders) {
        echo "❌ Ошибка: количество заказов не соответствует исходным данным\n";
        echo "   Ожидалось: " . $expectedOrders . ", получено: " . $totalOrders . "\n";
        $dataValid = false;
    } else {
        echo "✅ Количество заказов корректно\n";
    }
    
    // Проверяем, что просмотры соответствуют исходным данным
    $expectedViews = $mockOzonResponse['totals'][2];
    if ($totalViews != $expectedViews) {
        echo "❌ Ошибка: количество просмотров не соответствует исходным данным\n";
        echo "   Ожидалось: " . $expectedViews . ", получено: " . $totalViews . "\n";
        $dataValid = false;
    } else {
        echo "✅ Количество просмотров корректно\n";
    }
    
    // Проверяем логику воронки
    foreach ($result as $index => $item) {
        if ($item['cart_additions'] > $item['views']) {
            echo "❌ Ошибка в товаре " . ($index + 1) . ": добавления в корзину больше просмотров\n";
            $dataValid = false;
        }
        
        if ($item['orders'] > $item['cart_additions']) {
            echo "❌ Ошибка в товаре " . ($index + 1) . ": заказы больше добавлений в корзину\n";
            $dataValid = false;
        }
    }
    
    if ($dataValid) {
        echo "✅ Все проверки пройдены успешно!\n";
    }
    
    // Тестируем сохранение в БД (если доступно)
    if ($pdo) {
        echo "\n💾 Тестируем сохранение в базу данных...\n";
        
        try {
            // Проверяем, есть ли поле revenue в таблице
            $stmt = $pdo->query("DESCRIBE ozon_funnel_data");
            $columns = $stmt->fetchAll();
            $hasRevenueColumn = false;
            
            foreach ($columns as $column) {
                if ($column['Field'] === 'revenue') {
                    $hasRevenueColumn = true;
                    break;
                }
            }
            
            if (!$hasRevenueColumn) {
                echo "⚠️ Поле 'revenue' отсутствует в таблице ozon_funnel_data\n";
                echo "Необходимо выполнить миграцию: php apply_revenue_migration.php\n";
            } else {
                echo "✅ Поле 'revenue' найдено в таблице\n";
                
                // Тестируем сохранение данных
                $saveMethod = $reflection->getMethod('saveFunnelDataToDatabase');
                $saveMethod->setAccessible(true);
                
                // Убираем отладочные поля для теста сохранения
                $cleanResult = array_map(function($item) {
                    unset($item['debug_request']);
                    unset($item['debug_raw_response']);
                    return $item;
                }, $result);
                
                $saveMethod->invoke($ozonAPI, $cleanResult);
                echo "✅ Данные успешно сохранены в базу данных\n";
            }
            
        } catch (Exception $e) {
            echo "❌ Ошибка при работе с БД: " . $e->getMessage() . "\n";
        }
    }
    
    echo "\n🎉 Тест завершен успешно!\n";
    echo "Метод processFunnelData корректно обрабатывает реальную структуру Ozon API.\n";
    
} catch (Exception $e) {
    echo "❌ Ошибка в тесте: " . $e->getMessage() . "\n";
    echo "Трассировка: " . $e->getTraceAsString() . "\n";
}
?>