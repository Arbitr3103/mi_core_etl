<?php
/**
 * Скрипт для заполнения БД тестовыми данными Ozon
 * Используется для немедленного тестирования дашборда
 */

echo "🧪 Заполнение БД тестовыми данными Ozon\n";
echo "======================================\n\n";

try {
    // Подключение к базе данных
    $host = '127.0.0.1';
    $dbname = 'mi_core_db';
    $username = 'mi_core_user';
    $password = 'secure_password_123';
    
    $dsn = "mysql:host={$host};dbname={$dbname};charset=utf8mb4";
    $pdo = new PDO($dsn, $username, $password, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
    ]);
    
    echo "✅ Подключение к БД установлено\n\n";
    
    // Очищаем существующие тестовые данные
    echo "🧹 Очистка существующих данных...\n";
    $pdo->exec("DELETE FROM ozon_funnel_data WHERE product_id LIKE 'TEST_%'");
    $pdo->exec("DELETE FROM ozon_demographics WHERE region = 'TEST_REGION'");
    echo "✅ Старые тестовые данные удалены\n\n";
    
    // Генерируем тестовые данные воронки
    echo "📊 Создание тестовых данных воронки...\n";
    
    $testFunnelData = [
        [
            'date_from' => '2024-01-01',
            'date_to' => '2024-01-07',
            'product_id' => 'TEST_1750881567',
            'campaign_id' => null,
            'views' => 15000,
            'cart_additions' => 6000,
            'orders' => 1200,
            'revenue' => 2400000.50,
            'conversion_view_to_cart' => 40.00,
            'conversion_cart_to_order' => 20.00,
            'conversion_overall' => 8.00,
            'cached_at' => date('Y-m-d H:i:s')
        ],
        [
            'date_from' => '2024-01-01',
            'date_to' => '2024-01-07',
            'product_id' => 'TEST_1750881568',
            'campaign_id' => null,
            'views' => 8500,
            'cart_additions' => 2550,
            'orders' => 765,
            'revenue' => 1530000.25,
            'conversion_view_to_cart' => 30.00,
            'conversion_cart_to_order' => 30.00,
            'conversion_overall' => 9.00,
            'cached_at' => date('Y-m-d H:i:s')
        ],
        [
            'date_from' => '2024-01-08',
            'date_to' => '2024-01-14',
            'product_id' => 'TEST_1750881567',
            'campaign_id' => null,
            'views' => 18000,
            'cart_additions' => 7200,
            'orders' => 1440,
            'revenue' => 2880000.75,
            'conversion_view_to_cart' => 40.00,
            'conversion_cart_to_order' => 20.00,
            'conversion_overall' => 8.00,
            'cached_at' => date('Y-m-d H:i:s')
        ],
        [
            'date_from' => '2024-01-15',
            'date_to' => '2024-01-21',
            'product_id' => 'TEST_1750881569',
            'campaign_id' => 'CAMPAIGN_001',
            'views' => 12000,
            'cart_additions' => 3600,
            'orders' => 900,
            'revenue' => 1800000.00,
            'conversion_view_to_cart' => 30.00,
            'conversion_cart_to_order' => 25.00,
            'conversion_overall' => 7.50,
            'cached_at' => date('Y-m-d H:i:s')
        ],
        [
            'date_from' => date('Y-m-d', strtotime('-7 days')),
            'date_to' => date('Y-m-d'),
            'product_id' => 'TEST_CURRENT_WEEK',
            'campaign_id' => null,
            'views' => 25000,
            'cart_additions' => 10000,
            'orders' => 2000,
            'revenue' => 4000000.00,
            'conversion_view_to_cart' => 40.00,
            'conversion_cart_to_order' => 20.00,
            'conversion_overall' => 8.00,
            'cached_at' => date('Y-m-d H:i:s')
        ]
    ];
    
    $sql = "INSERT INTO ozon_funnel_data 
            (date_from, date_to, product_id, campaign_id, views, cart_additions, orders, revenue,
             conversion_view_to_cart, conversion_cart_to_order, conversion_overall, cached_at)
            VALUES 
            (:date_from, :date_to, :product_id, :campaign_id, :views, :cart_additions, :orders, :revenue,
             :conversion_view_to_cart, :conversion_cart_to_order, :conversion_overall, :cached_at)";
    
    $stmt = $pdo->prepare($sql);
    
    foreach ($testFunnelData as $data) {
        $stmt->execute($data);
    }
    
    echo "✅ Добавлено " . count($testFunnelData) . " записей воронки\n\n";
    
    // Генерируем тестовые демографические данные
    echo "👥 Создание тестовых демографических данных...\n";
    
    $testDemographicsData = [
        [
            'date_from' => '2024-01-01',
            'date_to' => '2024-01-31',
            'age_group' => '18-25',
            'gender' => 'male',
            'region' => 'Москва',
            'orders_count' => 450,
            'revenue' => 900000.00,
            'cached_at' => date('Y-m-d H:i:s')
        ],
        [
            'date_from' => '2024-01-01',
            'date_to' => '2024-01-31',
            'age_group' => '26-35',
            'gender' => 'female',
            'region' => 'Санкт-Петербург',
            'orders_count' => 680,
            'revenue' => 1360000.00,
            'cached_at' => date('Y-m-d H:i:s')
        ],
        [
            'date_from' => '2024-01-01',
            'date_to' => '2024-01-31',
            'age_group' => '36-45',
            'gender' => 'male',
            'region' => 'Екатеринбург',
            'orders_count' => 320,
            'revenue' => 640000.00,
            'cached_at' => date('Y-m-d H:i:s')
        ],
        [
            'date_from' => date('Y-m-d', strtotime('-30 days')),
            'date_to' => date('Y-m-d'),
            'age_group' => '25-35',
            'gender' => 'female',
            'region' => 'TEST_REGION',
            'orders_count' => 1200,
            'revenue' => 2400000.00,
            'cached_at' => date('Y-m-d H:i:s')
        ]
    ];
    
    $sql = "INSERT INTO ozon_demographics 
            (date_from, date_to, age_group, gender, region, orders_count, revenue, cached_at)
            VALUES 
            (:date_from, :date_to, :age_group, :gender, :region, :orders_count, :revenue, :cached_at)";
    
    $stmt = $pdo->prepare($sql);
    
    foreach ($testDemographicsData as $data) {
        $stmt->execute($data);
    }
    
    echo "✅ Добавлено " . count($testDemographicsData) . " демографических записей\n\n";
    
    // Показываем итоговую статистику
    echo "📈 Итоговая статистика:\n";
    echo "======================\n";
    
    $stmt = $pdo->query("SELECT COUNT(*) as count FROM ozon_funnel_data");
    $funnelCount = $stmt->fetchColumn();
    echo "📊 Всего записей воронки в БД: $funnelCount\n";
    
    $stmt = $pdo->query("SELECT COUNT(*) as count FROM ozon_demographics");
    $demographicsCount = $stmt->fetchColumn();
    echo "👥 Всего демографических записей в БД: $demographicsCount\n";
    
    // Показываем суммарную статистику тестовых данных
    $stmt = $pdo->query("
        SELECT 
            SUM(views) as total_views,
            SUM(orders) as total_orders,
            SUM(revenue) as total_revenue
        FROM ozon_funnel_data 
        WHERE product_id LIKE 'TEST_%'
    ");
    $stats = $stmt->fetch();
    
    if ($stats) {
        echo "\n💰 Статистика тестовых данных:\n";
        echo "  👀 Общие просмотры: " . number_format($stats['total_views']) . "\n";
        echo "  📦 Общие заказы: " . number_format($stats['total_orders']) . "\n";
        echo "  💵 Общая выручка: " . number_format($stats['total_revenue'], 2) . " руб.\n";
        
        if ($stats['total_views'] > 0) {
            $conversion = round(($stats['total_orders'] / $stats['total_views']) * 100, 2);
            echo "  📈 Средняя конверсия: $conversion%\n";
        }
    }
    
    echo "\n🎉 Тестовые данные успешно добавлены!\n";
    echo "Теперь можно проверить дашборд - он должен отображать данные.\n\n";
    
    echo "🔗 Для проверки API откройте в браузере:\n";
    echo "http://your-domain/src/api/ozon-analytics.php?action=funnel-data&date_from=2024-01-01&date_to=2024-01-31\n\n";
    
    echo "📋 Для удаления тестовых данных выполните:\n";
    echo "DELETE FROM ozon_funnel_data WHERE product_id LIKE 'TEST_%';\n";
    echo "DELETE FROM ozon_demographics WHERE region = 'TEST_REGION';\n";
    
} catch (Exception $e) {
    echo "❌ Ошибка: " . $e->getMessage() . "\n";
    echo "Трассировка: " . $e->getTraceAsString() . "\n";
}
?>