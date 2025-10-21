<?php
require_once 'config.php';

echo "🔍 ДИАГНОСТИКА ПРОБЛЕМЫ С НАЗВАНИЯМИ ТОВАРОВ\n";
echo "==========================================\n\n";

try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8mb4", $username, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    echo "✅ Подключение к БД успешно\n\n";
    
    // 1. Проверяем таблицу inventory_data
    echo "📊 1. Проверяем inventory_data (первые 5 записей):\n";
    $stmt = $pdo->query("SELECT product_id, warehouse_name, quantity_present FROM inventory_data LIMIT 5");
    while ($row = $stmt->fetch()) {
        echo "   product_id: {$row['product_id']} (тип: " . gettype($row['product_id']) . ")\n";
        echo "   warehouse: {$row['warehouse_name']}, stock: {$row['quantity_present']}\n\n";
    }
    
    // 2. Проверяем таблицу dim_products
    echo "📋 2. Проверяем dim_products (первые 5 записей):\n";
    $stmt = $pdo->query("SELECT sku_ozon, name, product_name FROM dim_products LIMIT 5");
    while ($row = $stmt->fetch()) {
        echo "   sku_ozon: '{$row['sku_ozon']}' (тип: " . gettype($row['sku_ozon']) . ")\n";
        echo "   name: {$row['name']}\n";
        echo "   product_name: {$row['product_name']}\n\n";
    }
    
    // 3. Проверяем совпадения напрямую
    echo "🔗 3. Проверяем JOIN между таблицами:\n";
    $stmt = $pdo->query("
        SELECT 
            i.product_id,
            dp.sku_ozon,
            dp.name,
            dp.product_name,
            CONCAT('', i.product_id) as product_id_as_string
        FROM inventory_data i
        LEFT JOIN dim_products dp ON CONCAT('', i.product_id) = dp.sku_ozon
        LIMIT 5
    ");
    
    while ($row = $stmt->fetch()) {
        echo "   product_id: {$row['product_id']} -> string: '{$row['product_id_as_string']}'\n";
        echo "   sku_ozon: '{$row['sku_ozon']}'\n";
        echo "   name: " . ($row['name'] ?: 'NULL') . "\n";
        echo "   product_name: " . ($row['product_name'] ?: 'NULL') . "\n";
        echo "   MATCH: " . ($row['name'] || $row['product_name'] ? 'YES' : 'NO') . "\n\n";
    }
    
    // 4. Проверяем конкретные товары из API
    echo "🎯 4. Проверяем конкретные товары из API:\n";
    $test_products = [596534196, 1191462296, 19573935];
    
    foreach ($test_products as $product_id) {
        echo "   Товар ID: $product_id\n";
        
        // Проверяем есть ли в inventory_data
        $stmt = $pdo->prepare("SELECT COUNT(*) as count FROM inventory_data WHERE product_id = ?");
        $stmt->execute([$product_id]);
        $inventory_count = $stmt->fetch()['count'];
        echo "   В inventory_data: $inventory_count записей\n";
        
        // Проверяем есть ли в dim_products
        $stmt = $pdo->prepare("SELECT name, product_name FROM dim_products WHERE sku_ozon = ?");
        $stmt->execute([(string)$product_id]);
        $product = $stmt->fetch();
        
        if ($product) {
            echo "   В dim_products: НАЙДЕН\n";
            echo "   name: " . ($product['name'] ?: 'NULL') . "\n";
            echo "   product_name: " . ($product['product_name'] ?: 'NULL') . "\n";
        } else {
            echo "   В dim_products: НЕ НАЙДЕН\n";
            
            // Проверяем похожие записи
            $stmt = $pdo->prepare("SELECT sku_ozon, name FROM dim_products WHERE sku_ozon LIKE ? LIMIT 3");
            $stmt->execute(["%$product_id%"]);
            $similar = $stmt->fetchAll();
            
            if ($similar) {
                echo "   Похожие записи:\n";
                foreach ($similar as $sim) {
                    echo "     sku_ozon: '{$sim['sku_ozon']}', name: {$sim['name']}\n";
                }
            }
        }
        echo "\n";
    }
    
    // 5. Статистика совпадений
    echo "📈 5. Статистика совпадений:\n";
    $stmt = $pdo->query("
        SELECT 
            COUNT(*) as total_inventory,
            COUNT(dp.sku_ozon) as matched_products,
            COUNT(dp.name) as with_name,
            COUNT(dp.product_name) as with_product_name
        FROM inventory_data i
        LEFT JOIN dim_products dp ON CONCAT('', i.product_id) = dp.sku_ozon
    ");
    
    $stats = $stmt->fetch();
    echo "   Всего записей в inventory: {$stats['total_inventory']}\n";
    echo "   Найдено совпадений: {$stats['matched_products']}\n";
    echo "   С полем name: {$stats['with_name']}\n";
    echo "   С полем product_name: {$stats['with_product_name']}\n";
    
    $match_percent = round(($stats['matched_products'] / $stats['total_inventory']) * 100, 2);
    echo "   Процент совпадений: {$match_percent}%\n\n";
    
    if ($match_percent < 10) {
        echo "❌ ПРОБЛЕМА: Очень низкий процент совпадений!\n";
        echo "   Возможные причины:\n";
        echo "   - Разные форматы ID в таблицах\n";
        echo "   - Отсутствуют данные в dim_products\n";
        echo "   - Проблема с типами данных\n\n";
    }
    
} catch (Exception $e) {
    echo "❌ Ошибка: " . $e->getMessage() . "\n";
}
?>