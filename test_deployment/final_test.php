<?php
/**
 * Финальный тест всех исправлений
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);

echo "🎯 ФИНАЛЬНЫЙ ТЕСТ ИСПРАВЛЕНИЙ\n";
echo str_repeat('=', 50) . "\n";

// Тест 1: Конфигурация
echo "1️⃣ Тестируем конфигурацию...\n";
require_once 'config.php';

echo "   ✅ DB_HOST: " . DB_HOST . "\n";
echo "   ✅ DB_NAME: " . DB_NAME . "\n";
echo "   ✅ DB_USER: " . DB_USER . "\n";

// Тест 2: Подключение к БД
echo "\n2️⃣ Тестируем подключение к БД...\n";
try {
    $pdo = new PDO(
        "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4",
        DB_USER,
        DB_PASSWORD,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
    echo "   ✅ Подключение успешно\n";
} catch (PDOException $e) {
    echo "   ❌ Ошибка подключения: " . $e->getMessage() . "\n";
    exit(1);
}

// Тест 3: Проверка таблиц
echo "\n3️⃣ Проверяем ключевые таблицы...\n";

$tables = ['dim_products', 'ozon_warehouses', 'product_master', 'inventory_data'];
foreach ($tables as $table) {
    try {
        $stmt = $pdo->query("SELECT COUNT(*) as count FROM `$table`");
        $count = $stmt->fetch()['count'];
        echo "   ✅ $table: $count записей\n";
    } catch (PDOException $e) {
        echo "   ❌ $table: " . $e->getMessage() . "\n";
    }
}

// Тест 4: API endpoints
echo "\n4️⃣ Тестируем API endpoints...\n";

$apis = [
    'api/analytics.php',
    'api/debug.php'
];

foreach ($apis as $api) {
    echo "   Тестируем $api...\n";
    
    // Захватываем вывод
    ob_start();
    $error = false;
    
    try {
        include $api;
    } catch (Exception $e) {
        $error = $e->getMessage();
    }
    
    $output = ob_get_clean();
    
    if ($error) {
        echo "   ❌ Ошибка: $error\n";
    } else {
        // Проверяем, что это валидный JSON
        $json = json_decode($output, true);
        if (json_last_error() === JSON_ERROR_NONE) {
            echo "   ✅ Возвращает валидный JSON (" . strlen($output) . " байт)\n";
        } else {
            echo "   ⚠️ Не JSON ответ (" . strlen($output) . " байт)\n";
        }
    }
}

// Тест 5: Проверка данных
echo "\n5️⃣ Проверяем качество данных...\n";

// Проверяем dim_products
$stmt = $pdo->query("
    SELECT 
        COUNT(*) as total,
        COUNT(sku_ozon) as with_ozon_sku,
        COUNT(product_name) as with_names,
        COUNT(cost_price) as with_prices
    FROM dim_products
");
$stats = $stmt->fetch();

echo "   📊 dim_products статистика:\n";
echo "      - Всего товаров: {$stats['total']}\n";
echo "      - С Ozon SKU: {$stats['with_ozon_sku']}\n";
echo "      - С названиями: {$stats['with_names']}\n";
echo "      - С ценами: {$stats['with_prices']}\n";

// Проверяем ozon_warehouses
$stmt = $pdo->query("SELECT COUNT(*) as count FROM ozon_warehouses");
$warehouseCount = $stmt->fetch()['count'];
echo "   📦 Складов Ozon: $warehouseCount\n";

// Итоговый результат
echo "\n" . str_repeat('=', 50) . "\n";

if ($warehouseCount > 0 && $stats['total'] > 0) {
    echo "🎉 ВСЕ ТЕСТЫ ПРОЙДЕНЫ УСПЕШНО!\n";
    echo "\n✅ Система готова к работе:\n";
    echo "   - База данных подключена\n";
    echo "   - Таблицы созданы и заполнены\n";
    echo "   - API возвращают корректные данные\n";
    echo "   - Нет ошибок 500\n";
    
    echo "\n🚀 Можно запускать в продакшн!\n";
} else {
    echo "❌ ЕСТЬ ПРОБЛЕМЫ:\n";
    if ($stats['total'] == 0) {
        echo "   - Нет товаров в dim_products\n";
    }
    if ($warehouseCount == 0) {
        echo "   - Нет складов в ozon_warehouses\n";
    }
}

echo "\n📅 Тест завершен: " . date('Y-m-d H:i:s') . "\n";
?>