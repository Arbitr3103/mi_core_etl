<?php
/**
 * Отладка проблемы с названиями товаров
 */

// Подключение к БД
function loadEnvConfig() {
    $envFile = __DIR__ . '/.env';
    if (file_exists($envFile)) {
        $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        foreach ($lines as $line) {
            if (strpos($line, '=') !== false && strpos($line, '#') !== 0) {
                list($key, $value) = explode('=', $line, 2);
                $value = trim($value, '"\'');
                $_ENV[trim($key)] = $value;
            }
        }
    }
}

loadEnvConfig();

try {
    $pdo = new PDO(
        "mysql:host=" . ($_ENV['DB_HOST'] ?? 'localhost') . ";dbname=mi_core;charset=utf8mb4",
        $_ENV['DB_USER'] ?? 'v_admin',
        $_ENV['DB_PASSWORD'] ?? '',
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
        ]
    );
    
    echo "✅ Подключение к БД успешно\n\n";
    
    // Проверяем конкретный SKU 266215809
    echo "🔍 Проверяем SKU 266215809:\n";
    
    // 1. Есть ли этот SKU в inventory_data?
    $inventory_stmt = $pdo->prepare("SELECT sku, current_stock, source FROM inventory_data WHERE sku = ? LIMIT 1");
    $inventory_stmt->execute(['266215809']);
    $inventory_result = $inventory_stmt->fetch();
    
    if ($inventory_result) {
        echo "✅ SKU найден в inventory_data:\n";
        echo "   SKU: " . $inventory_result['sku'] . "\n";
        echo "   Остаток: " . $inventory_result['current_stock'] . "\n";
        echo "   Источник: " . $inventory_result['source'] . "\n\n";
    } else {
        echo "❌ SKU не найден в inventory_data\n\n";
    }
    
    // 2. Есть ли этот SKU в product_master?
    $master_stmt = $pdo->prepare("SELECT sku_ozon, product_name, brand, category FROM product_master WHERE sku_ozon = ? LIMIT 1");
    $master_stmt->execute(['266215809']);
    $master_result = $master_stmt->fetch();
    
    if ($master_result) {
        echo "✅ SKU найден в product_master:\n";
        echo "   SKU Ozon: " . $master_result['sku_ozon'] . "\n";
        echo "   Название: " . $master_result['product_name'] . "\n";
        echo "   Бренд: " . $master_result['brand'] . "\n";
        echo "   Категория: " . $master_result['category'] . "\n\n";
    } else {
        echo "❌ SKU не найден в product_master\n\n";
    }
    
    // 3. Проверяем JOIN
    echo "🔗 Проверяем JOIN между таблицами:\n";
    $join_stmt = $pdo->prepare("
        SELECT 
            i.sku,
            i.current_stock,
            pm.product_name,
            pm.brand,
            CASE
                WHEN pm.product_name IS NOT NULL THEN pm.product_name
                WHEN i.sku REGEXP '^[0-9]+$' THEN CONCAT('Товар артикул ', i.sku)
                WHEN LENGTH(i.sku) > 50 THEN CONCAT(SUBSTRING(i.sku, 1, 47), '...')
                ELSE i.sku
            END as display_name
        FROM inventory_data i
        LEFT JOIN product_master pm ON i.sku = pm.sku_ozon
        WHERE i.sku = ?
        LIMIT 1
    ");
    $join_stmt->execute(['266215809']);
    $join_result = $join_stmt->fetch();
    
    if ($join_result) {
        echo "✅ JOIN результат:\n";
        echo "   SKU: " . $join_result['sku'] . "\n";
        echo "   Остаток: " . $join_result['current_stock'] . "\n";
        echo "   Название из мастер таблицы: " . ($join_result['product_name'] ?? 'NULL') . "\n";
        echo "   Бренд: " . ($join_result['brand'] ?? 'NULL') . "\n";
        echo "   Итоговое отображение: " . $join_result['display_name'] . "\n\n";
    } else {
        echo "❌ JOIN не дал результатов\n\n";
    }
    
    // 4. Общая статистика
    echo "📊 Общая статистика:\n";
    
    $stats_stmt = $pdo->query("
        SELECT 
            COUNT(*) as total_inventory,
            COUNT(CASE WHEN pm.product_name IS NOT NULL THEN 1 END) as with_names,
            COUNT(CASE WHEN pm.product_name IS NULL THEN 1 END) as without_names
        FROM inventory_data i
        LEFT JOIN product_master pm ON i.sku = pm.sku_ozon
        WHERE i.current_stock > 0
    ");
    $stats = $stats_stmt->fetch();
    
    echo "   Всего товаров в остатках: " . $stats['total_inventory'] . "\n";
    echo "   С названиями из мастер таблицы: " . $stats['with_names'] . "\n";
    echo "   Без названий: " . $stats['without_names'] . "\n";
    echo "   Процент покрытия: " . round($stats['with_names'] / $stats['total_inventory'] * 100, 1) . "%\n\n";
    
    // 5. Примеры товаров без названий
    echo "📝 Примеры товаров без названий:\n";
    $examples_stmt = $pdo->query("
        SELECT 
            i.sku,
            i.current_stock,
            pm.product_name
        FROM inventory_data i
        LEFT JOIN product_master pm ON i.sku = pm.sku_ozon
        WHERE i.current_stock > 0 AND pm.product_name IS NULL
        LIMIT 5
    ");
    $examples = $examples_stmt->fetchAll();
    
    foreach ($examples as $example) {
        echo "   SKU: " . $example['sku'] . " (остаток: " . $example['current_stock'] . ")\n";
    }
    
} catch (PDOException $e) {
    echo "❌ Ошибка БД: " . $e->getMessage() . "\n";
} catch (Exception $e) {
    echo "❌ Общая ошибка: " . $e->getMessage() . "\n";
}
?>