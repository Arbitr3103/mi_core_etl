<?php
/**
 * Тест проверки складов Ozon
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);

// Загружаем конфигурацию
require_once 'config.php';

echo "🏭 ТЕСТ СКЛАДОВ OZON\n";
echo str_repeat('=', 40) . "\n";

// Подключаемся к базе данных
try {
    $pdo = new PDO(
        "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4",
        DB_USER,
        DB_PASSWORD,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
    echo "✅ Подключение к БД успешно\n";
} catch (PDOException $e) {
    echo "❌ Ошибка БД: " . $e->getMessage() . "\n";
    exit(1);
}

// Проверяем таблицу складов
echo "\n📦 Проверяем таблицу ozon_warehouses:\n";

try {
    $stmt = $pdo->query("SELECT COUNT(*) as count FROM ozon_warehouses");
    $count = $stmt->fetch()['count'];
    echo "Количество складов в БД: $count\n";
    
    if ($count > 0) {
        echo "\nПримеры складов:\n";
        $stmt = $pdo->query("SELECT warehouse_id, name, is_rfbs FROM ozon_warehouses LIMIT 5");
        $warehouses = $stmt->fetchAll();
        
        foreach ($warehouses as $warehouse) {
            echo "- ID: {$warehouse['warehouse_id']}, Название: {$warehouse['name']}, RFBS: " . 
                 ($warehouse['is_rfbs'] ? 'Да' : 'Нет') . "\n";
        }
    } else {
        echo "⚠️ Таблица складов пустая\n";
        
        // Добавляем тестовые данные
        echo "\n🔧 Добавляем тестовые склады...\n";
        $testWarehouses = [
            [1, 'Склад Москва', 0],
            [2, 'Склад СПб', 1],
            [3, 'Склад Екатеринбург', 0]
        ];
        
        $stmt = $pdo->prepare("INSERT INTO ozon_warehouses (warehouse_id, name, is_rfbs) VALUES (?, ?, ?)");
        
        foreach ($testWarehouses as $warehouse) {
            $stmt->execute($warehouse);
            echo "✅ Добавлен склад: {$warehouse[1]} (ID: {$warehouse[0]})\n";
        }
        
        echo "\nТеперь в таблице складов:\n";
        $stmt = $pdo->query("SELECT COUNT(*) as count FROM ozon_warehouses");
        $newCount = $stmt->fetch()['count'];
        echo "Количество складов: $newCount\n";
    }
    
} catch (PDOException $e) {
    echo "❌ Ошибка при работе со складами: " . $e->getMessage() . "\n";
}

// Проверяем dim_products
echo "\n📊 Проверяем таблицу dim_products:\n";
try {
    $stmt = $pdo->query("SELECT COUNT(*) as count FROM dim_products");
    $count = $stmt->fetch()['count'];
    echo "Количество товаров: $count\n";
    
    if ($count > 0) {
        echo "\nПримеры товаров:\n";
        $stmt = $pdo->query("SELECT id, sku_ozon, product_name FROM dim_products LIMIT 3");
        $products = $stmt->fetchAll();
        
        foreach ($products as $product) {
            echo "- ID: {$product['id']}, SKU: {$product['sku_ozon']}, Название: " . 
                 substr($product['product_name'], 0, 50) . "...\n";
        }
    }
    
} catch (PDOException $e) {
    echo "❌ Ошибка при работе с товарами: " . $e->getMessage() . "\n";
}

echo "\n✅ Тест завершен!\n";
?>