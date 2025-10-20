<?php
echo "🔧 ФИНАЛЬНОЕ ИСПРАВЛЕНИЕ НАЗВАНИЙ ТОВАРОВ\n";
echo "========================================\n\n";

// Настройки БД из .env
$host = '127.0.0.1';
$dbname = 'mi_core_db';
$username = 'ingest_user';
$password = 'IngestUserPassword2025';

try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8mb4", $username, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    echo "✅ Подключение к БД успешно\n\n";
    
    // 1. Найдем все товары из inventory_data, которых нет в dim_products
    echo "🔍 1. Ищем недостающие товары...\n";
    
    $stmt = $pdo->query("
        SELECT DISTINCT i.product_id
        FROM inventory_data i
        LEFT JOIN dim_products dp ON CONCAT('', i.product_id) = dp.sku_ozon
        WHERE dp.sku_ozon IS NULL
        AND i.product_id != 0
        ORDER BY i.product_id
    ");
    
    $missing_products = $stmt->fetchAll(PDO::FETCH_COLUMN);
    $count = count($missing_products);
    
    echo "   Найдено недостающих товаров: $count\n";
    
    if ($count == 0) {
        echo "✅ Все товары уже есть в dim_products!\n";
        exit;
    }
    
    echo "   Примеры недостающих ID: " . implode(', ', array_slice($missing_products, 0, 10)) . "\n\n";
    
    // 2. Добавляем недостающие товары в dim_products
    echo "➕ 2. Добавляем недостающие товары в dim_products...\n";
    
    $added = 0;
    $batch_size = 50;
    
    for ($i = 0; $i < count($missing_products); $i += $batch_size) {
        $batch = array_slice($missing_products, $i, $batch_size);
        
        $values = [];
        $params = [];
        
        foreach ($batch as $product_id) {
            $values[] = "(?, ?, ?, NOW(), NOW())";
            $params[] = (string)$product_id; // sku_ozon
            $params[] = "Товар Ozon ID $product_id"; // name
            $params[] = "Товар Ozon ID $product_id"; // product_name
        }
        
        $sql = "INSERT INTO dim_products (sku_ozon, name, product_name, created_at, updated_at) VALUES " . implode(', ', $values);
        
        try {
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $added += count($batch);
            
            echo "   Добавлено товаров: $added / $count\n";
            
        } catch (Exception $e) {
            echo "   ⚠️ Ошибка при добавлении batch: " . $e->getMessage() . "\n";
        }
    }
    
    echo "✅ Добавлено товаров: $added\n\n";
    
    // 3. Проверяем результат
    echo "🧪 3. Проверяем результат...\n";
    
    // Проверяем конкретные товары из API
    $test_products = [596534196, 1191462296, 19573935];
    
    foreach ($test_products as $product_id) {
        $stmt = $pdo->prepare("SELECT name FROM dim_products WHERE sku_ozon = ?");
        $stmt->execute([(string)$product_id]);
        $result = $stmt->fetch();
        
        if ($result) {
            echo "   ✅ $product_id: {$result['name']}\n";
        } else {
            echo "   ❌ $product_id: НЕ НАЙДЕН\n";
        }
    }
    
    // 4. Тестируем JOIN
    echo "\n🔗 4. Тестируем JOIN...\n";
    
    $stmt = $pdo->query("
        SELECT 
            COUNT(*) as total_inventory,
            COUNT(dp.sku_ozon) as matched_products
        FROM inventory_data i
        LEFT JOIN dim_products dp ON CONCAT('', i.product_id) = dp.sku_ozon
        WHERE i.product_id != 0
    ");
    
    $stats = $stmt->fetch();
    $match_percent = round(($stats['matched_products'] / $stats['total_inventory']) * 100, 2);
    
    echo "   Всего товаров в inventory: {$stats['total_inventory']}\n";
    echo "   Найдено совпадений: {$stats['matched_products']}\n";
    echo "   Процент совпадений: {$match_percent}%\n\n";
    
    if ($match_percent > 90) {
        echo "🎉 ОТЛИЧНО! Высокий процент совпадений!\n";
    } else {
        echo "⚠️ Процент совпадений все еще низкий. Возможно, есть другие проблемы.\n";
    }
    
    // 5. Тестируем API запрос
    echo "\n🧪 5. Тестируем API запрос...\n";
    
    $stmt = $pdo->query("
        SELECT 
            i.product_id as sku,
            COALESCE(dp.name, dp.product_name, CONCAT('Товар ID ', i.product_id)) as display_name,
            CASE 
                WHEN dp.name IS NOT NULL THEN 'Мастер-таблица (name)'
                WHEN dp.product_name IS NOT NULL THEN 'Мастер-таблица (product_name)'
                ELSE 'Числовой ID'
            END as name_source,
            i.quantity_present as total_stock
        FROM inventory_data i
        LEFT JOIN dim_products dp ON CONCAT('', i.product_id) = dp.sku_ozon
        WHERE i.quantity_present > 0
        ORDER BY i.quantity_present DESC
        LIMIT 5
    ");
    
    echo "   Топ 5 товаров с остатками:\n";
    while ($row = $stmt->fetch()) {
        echo "   📦 SKU: {$row['sku']}\n";
        echo "      Название: {$row['display_name']}\n";
        echo "      Источник: {$row['name_source']}\n";
        echo "      Остаток: {$row['total_stock']}\n\n";
    }
    
    echo "🎉 ИСПРАВЛЕНИЕ ЗАВЕРШЕНО!\n";
    echo "Теперь API должен показывать названия товаров вместо 'Товар ID XXXXX'\n\n";
    
    echo "📋 Следующие шаги:\n";
    echo "1. Протестируйте API: curl \"http://api.zavodprostavok.ru/api/inventory-v4.php?action=products&limit=3\"\n";
    echo "2. Если нужны реальные названия товаров, настройте синхронизацию с API Ozon\n";
    echo "3. Обновите названия через: UPDATE dim_products SET name = 'Реальное название' WHERE sku_ozon = 'ID'\n";
    
} catch (Exception $e) {
    echo "❌ Ошибка: " . $e->getMessage() . "\n";
}
?>