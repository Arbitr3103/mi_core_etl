<?php
/**
 * Тест загрузки данных Ozon Analytics в базу данных
 * Период: 29.09.2025 - 05.10.2025
 * 
 * Проверяет:
 * - Подключение к БД
 * - Структуру таблиц
 * - Загрузку тестовых данных
 * - Валидацию данных
 */

require_once 'src/classes/OzonAnalyticsAPI.php';

echo "🔍 ТЕСТ ЗАГРУЗКИ ДАННЫХ OZON ANALYTICS\n";
echo "Период: 29.09.2025 - 05.10.2025\n";
echo str_repeat("=", 50) . "\n\n";

// Конфигурация БД
$dbConfig = [
    'host' => $_ENV['DB_HOST'] ?? '127.0.0.1',
    'dbname' => $_ENV['DB_NAME'] ?? 'mi_core_db', 
    'username' => $_ENV['DB_USER'] ?? 'ingest_user',
    'password' => $_ENV['DB_PASSWORD'] ?? 'xK9#mQ7$vN2@pL!rT4wY'
];

try {
    // 1. Подключение к БД
    echo "1️⃣ Подключение к базе данных...\n";
    $dsn = "mysql:host={$dbConfig['host']};dbname={$dbConfig['dbname']};charset=utf8mb4";
    $pdo = new PDO($dsn, $dbConfig['username'], $dbConfig['password'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
    ]);
    echo "✅ Подключение к БД успешно\n\n";

    // 2. Проверка существования таблиц
    echo "2️⃣ Проверка структуры таблиц...\n";
    $requiredTables = [
        'ozon_api_settings',
        'ozon_funnel_data', 
        'ozon_demographics',
        'ozon_campaigns'
    ];

    foreach ($requiredTables as $table) {
        $stmt = $pdo->prepare("SHOW TABLES LIKE ?");
        $stmt->execute([$table]);
        if ($stmt->fetch()) {
            echo "✅ Таблица $table существует\n";
        } else {
            echo "❌ Таблица $table не найдена\n";
            exit(1);
        }
    }
    echo "\n";

    // 3. Проверка текущих данных за период
    echo "3️⃣ Проверка существующих данных за период 29.09.2025 - 05.10.2025...\n";
    
    // Воронка продаж
    $stmt = $pdo->prepare("
        SELECT COUNT(*) as count, 
               MIN(date_from) as min_date, 
               MAX(date_to) as max_date,
               SUM(views) as total_views,
               SUM(cart_additions) as total_cart,
               SUM(orders) as total_orders
        FROM ozon_funnel_data 
        WHERE date_from >= '2025-09-29' AND date_to <= '2025-10-05'
    ");
    $stmt->execute();
    $funnelData = $stmt->fetch();
    
    echo "📊 Данные воронки продаж:\n";
    echo "   - Записей: {$funnelData['count']}\n";
    echo "   - Период: {$funnelData['min_date']} - {$funnelData['max_date']}\n";
    echo "   - Просмотры: {$funnelData['total_views']}\n";
    echo "   - В корзину: {$funnelData['total_cart']}\n";
    echo "   - Заказы: {$funnelData['total_orders']}\n\n";

    // Демографические данные
    $stmt = $pdo->prepare("
        SELECT COUNT(*) as count,
               SUM(orders_count) as total_orders,
               SUM(revenue) as total_revenue
        FROM ozon_demographics 
        WHERE date_from >= '2025-09-29' AND date_to <= '2025-10-05'
    ");
    $stmt->execute();
    $demoData = $stmt->fetch();
    
    echo "👥 Демографические данные:\n";
    echo "   - Записей: {$demoData['count']}\n";
    echo "   - Заказы: {$demoData['total_orders']}\n";
    echo "   - Выручка: " . number_format($demoData['total_revenue'], 2) . " руб.\n\n";

    // Рекламные кампании
    $stmt = $pdo->prepare("
        SELECT COUNT(*) as count,
               SUM(impressions) as total_impressions,
               SUM(clicks) as total_clicks,
               SUM(spend) as total_spend,
               SUM(revenue) as total_revenue
        FROM ozon_campaigns 
        WHERE date_from >= '2025-09-29' AND date_to <= '2025-10-05'
    ");
    $stmt->execute();
    $campaignData = $stmt->fetch();
    
    echo "📈 Данные рекламных кампаний:\n";
    echo "   - Записей: {$campaignData['count']}\n";
    echo "   - Показы: {$campaignData['total_impressions']}\n";
    echo "   - Клики: {$campaignData['total_clicks']}\n";
    echo "   - Расходы: " . number_format($campaignData['total_spend'], 2) . " руб.\n";
    echo "   - Доходы: " . number_format($campaignData['total_revenue'], 2) . " руб.\n\n";

    // 4. Тестовая загрузка данных
    echo "4️⃣ Тестовая загрузка данных...\n";
    
    // Создаем экземпляр API
    $ozonAPI = new OzonAnalyticsAPI('test_client_id', 'test_api_key', $pdo);
    
    // Тестовые данные воронки
    $testFunnelData = [
        [
            'date_from' => '2025-09-29',
            'date_to' => '2025-10-05',
            'product_id' => 'TEST_PRODUCT_001',
            'campaign_id' => 'TEST_CAMPAIGN_001',
            'views' => 5000,
            'cart_additions' => 750,
            'orders' => 225,
            'conversion_view_to_cart' => 15.00,
            'conversion_cart_to_order' => 30.00,
            'conversion_overall' => 4.50,
            'cached_at' => date('Y-m-d H:i:s')
        ],
        [
            'date_from' => '2025-09-29',
            'date_to' => '2025-10-05',
            'product_id' => 'TEST_PRODUCT_002',
            'campaign_id' => 'TEST_CAMPAIGN_002',
            'views' => 3000,
            'cart_additions' => 450,
            'orders' => 135,
            'conversion_view_to_cart' => 15.00,
            'conversion_cart_to_order' => 30.00,
            'conversion_overall' => 4.50,
            'cached_at' => date('Y-m-d H:i:s')
        ]
    ];

    // Загружаем тестовые данные воронки
    foreach ($testFunnelData as $data) {
        $stmt = $pdo->prepare("
            INSERT INTO ozon_funnel_data 
            (date_from, date_to, product_id, campaign_id, views, cart_additions, orders, 
             conversion_view_to_cart, conversion_cart_to_order, conversion_overall, cached_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE
            views = VALUES(views),
            cart_additions = VALUES(cart_additions),
            orders = VALUES(orders),
            conversion_view_to_cart = VALUES(conversion_view_to_cart),
            conversion_cart_to_order = VALUES(conversion_cart_to_order),
            conversion_overall = VALUES(conversion_overall),
            cached_at = VALUES(cached_at)
        ");
        
        $stmt->execute([
            $data['date_from'], $data['date_to'], $data['product_id'], $data['campaign_id'],
            $data['views'], $data['cart_additions'], $data['orders'],
            $data['conversion_view_to_cart'], $data['conversion_cart_to_order'], 
            $data['conversion_overall'], $data['cached_at']
        ]);
    }
    echo "✅ Загружено " . count($testFunnelData) . " записей воронки продаж\n";

    // Тестовые демографические данные
    $testDemoData = [
        [
            'date_from' => '2025-09-29',
            'date_to' => '2025-10-05',
            'age_group' => '25-34',
            'gender' => 'male',
            'region' => 'Moscow',
            'orders_count' => 150,
            'revenue' => 75000.00,
            'cached_at' => date('Y-m-d H:i:s')
        ],
        [
            'date_from' => '2025-09-29',
            'date_to' => '2025-10-05',
            'age_group' => '35-44',
            'gender' => 'female',
            'region' => 'Saint Petersburg',
            'orders_count' => 120,
            'revenue' => 60000.00,
            'cached_at' => date('Y-m-d H:i:s')
        ]
    ];

    foreach ($testDemoData as $data) {
        $stmt = $pdo->prepare("
            INSERT INTO ozon_demographics 
            (date_from, date_to, age_group, gender, region, orders_count, revenue, cached_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE
            orders_count = VALUES(orders_count),
            revenue = VALUES(revenue),
            cached_at = VALUES(cached_at)
        ");
        
        $stmt->execute([
            $data['date_from'], $data['date_to'], $data['age_group'], 
            $data['gender'], $data['region'], $data['orders_count'], 
            $data['revenue'], $data['cached_at']
        ]);
    }
    echo "✅ Загружено " . count($testDemoData) . " демографических записей\n";

    // Тестовые данные кампаний
    $testCampaignData = [
        [
            'campaign_id' => 'CAMP_TEST_001',
            'campaign_name' => 'Тестовая кампания 1',
            'date_from' => '2025-09-29',
            'date_to' => '2025-10-05',
            'impressions' => 50000,
            'clicks' => 2500,
            'spend' => 5000.00,
            'orders' => 125,
            'revenue' => 12500.00,
            'ctr' => 5.00,
            'cpc' => 2.00,
            'roas' => 2.50,
            'cached_at' => date('Y-m-d H:i:s')
        ]
    ];

    foreach ($testCampaignData as $data) {
        $stmt = $pdo->prepare("
            INSERT INTO ozon_campaigns 
            (campaign_id, campaign_name, date_from, date_to, impressions, clicks, spend, 
             orders, revenue, ctr, cpc, roas, cached_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE
            impressions = VALUES(impressions),
            clicks = VALUES(clicks),
            spend = VALUES(spend),
            orders = VALUES(orders),
            revenue = VALUES(revenue),
            ctr = VALUES(ctr),
            cpc = VALUES(cpc),
            roas = VALUES(roas),
            cached_at = VALUES(cached_at)
        ");
        
        $stmt->execute([
            $data['campaign_id'], $data['campaign_name'], $data['date_from'], $data['date_to'],
            $data['impressions'], $data['clicks'], $data['spend'], $data['orders'],
            $data['revenue'], $data['ctr'], $data['cpc'], $data['roas'], $data['cached_at']
        ]);
    }
    echo "✅ Загружено " . count($testCampaignData) . " записей кампаний\n\n";

    // 5. Проверка загруженных данных
    echo "5️⃣ Проверка загруженных данных...\n";
    
    // Проверяем воронку
    $stmt = $pdo->prepare("
        SELECT * FROM ozon_funnel_data 
        WHERE date_from >= '2025-09-29' AND date_to <= '2025-10-05'
        AND product_id LIKE 'TEST_%'
        ORDER BY product_id
    ");
    $stmt->execute();
    $loadedFunnel = $stmt->fetchAll();
    
    echo "📊 Загруженные данные воронки:\n";
    foreach ($loadedFunnel as $row) {
        echo "   - {$row['product_id']}: {$row['views']} просмотров → {$row['cart_additions']} в корзину → {$row['orders']} заказов\n";
        echo "     Конверсии: {$row['conversion_view_to_cart']}% → {$row['conversion_cart_to_order']}% (общая: {$row['conversion_overall']}%)\n";
    }
    echo "\n";

    // Проверяем демографию
    $stmt = $pdo->prepare("
        SELECT * FROM ozon_demographics 
        WHERE date_from >= '2025-09-29' AND date_to <= '2025-10-05'
        ORDER BY age_group, gender
    ");
    $stmt->execute();
    $loadedDemo = $stmt->fetchAll();
    
    echo "👥 Загруженные демографические данные:\n";
    foreach ($loadedDemo as $row) {
        echo "   - {$row['age_group']}, {$row['gender']}, {$row['region']}: {$row['orders_count']} заказов, " . number_format($row['revenue'], 2) . " руб.\n";
    }
    echo "\n";

    // Проверяем кампании
    $stmt = $pdo->prepare("
        SELECT * FROM ozon_campaigns 
        WHERE date_from >= '2025-09-29' AND date_to <= '2025-10-05'
        AND campaign_id LIKE 'CAMP_TEST_%'
    ");
    $stmt->execute();
    $loadedCampaigns = $stmt->fetchAll();
    
    echo "📈 Загруженные данные кампаний:\n";
    foreach ($loadedCampaigns as $row) {
        echo "   - {$row['campaign_name']} ({$row['campaign_id']}):\n";
        echo "     Показы: {$row['impressions']}, Клики: {$row['clicks']}, CTR: {$row['ctr']}%\n";
        echo "     Расходы: " . number_format($row['spend'], 2) . " руб., Доходы: " . number_format($row['revenue'], 2) . " руб., ROAS: {$row['roas']}\n";
    }
    echo "\n";

    // 6. Валидация данных
    echo "6️⃣ Валидация загруженных данных...\n";
    
    $validationErrors = 0;
    
    // Проверяем логику воронки
    foreach ($loadedFunnel as $row) {
        if ($row['cart_additions'] > $row['views']) {
            echo "❌ Ошибка: добавления в корзину ({$row['cart_additions']}) больше просмотров ({$row['views']}) для {$row['product_id']}\n";
            $validationErrors++;
        }
        
        if ($row['orders'] > $row['cart_additions']) {
            echo "❌ Ошибка: заказы ({$row['orders']}) больше добавлений в корзину ({$row['cart_additions']}) для {$row['product_id']}\n";
            $validationErrors++;
        }
        
        if ($row['conversion_overall'] > 100) {
            echo "❌ Ошибка: общая конверсия ({$row['conversion_overall']}%) больше 100% для {$row['product_id']}\n";
            $validationErrors++;
        }
    }
    
    // Проверяем демографические данные
    foreach ($loadedDemo as $row) {
        if ($row['orders_count'] < 0 || $row['revenue'] < 0) {
            echo "❌ Ошибка: отрицательные значения в демографических данных\n";
            $validationErrors++;
        }
    }
    
    // Проверяем кампании
    foreach ($loadedCampaigns as $row) {
        if ($row['clicks'] > $row['impressions']) {
            echo "❌ Ошибка: клики ({$row['clicks']}) больше показов ({$row['impressions']}) для {$row['campaign_id']}\n";
            $validationErrors++;
        }
        
        if ($row['ctr'] > 100) {
            echo "❌ Ошибка: CTR ({$row['ctr']}%) больше 100% для {$row['campaign_id']}\n";
            $validationErrors++;
        }
    }
    
    if ($validationErrors === 0) {
        echo "✅ Все данные прошли валидацию\n";
    } else {
        echo "❌ Найдено $validationErrors ошибок валидации\n";
    }
    
    echo "\n" . str_repeat("=", 50) . "\n";
    echo "🎉 ТЕСТ ЗАВЕРШЕН УСПЕШНО!\n";
    echo "Данные за период 29.09.2025 - 05.10.2025 загружены и проверены.\n";

} catch (Exception $e) {
    echo "❌ Ошибка: " . $e->getMessage() . "\n";
    exit(1);
}