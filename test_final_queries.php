<?php
/**
 * Финальный тест SQL запросов с улучшенными названиями
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
        $_ENV['DB_PASSWORD'] ?? 'nEw_pAsS_f0r_vAdmin_!2025',
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
        ]
    );
    
    echo "✅ Подключение к БД успешно\n\n";
    
    // Тест запроса для товаров требующих внимания (как в API)
    echo "🎯 Тестирование запроса товаров требующих внимания:\n";
    
    $attention_stmt = $pdo->query("
        SELECT 
            i.sku,
            CASE
                WHEN pm.product_name IS NOT NULL AND pm.product_name NOT LIKE 'Товар артикул %' THEN pm.product_name
                WHEN pn.product_name IS NOT NULL THEN pn.product_name
                WHEN i.sku REGEXP '^[0-9]+$' THEN CONCAT('Товар артикул ', i.sku)
                WHEN LENGTH(i.sku) > 50 THEN CONCAT(SUBSTRING(i.sku, 1, 47), '...')
                ELSE i.sku
            END as product_name,
            COALESCE(pm.brand, 'Без бренда') as brand,
            COALESCE(pm.category, 'Без категории') as category,
            SUM(i.current_stock) as total_stock,
            SUM(i.reserved_stock) as total_reserved,
            CASE 
                WHEN SUM(i.current_stock) > 200 AND SUM(i.reserved_stock) = 0 THEN 'Избыток без спроса'
                WHEN SUM(i.current_stock) < 5 AND SUM(i.reserved_stock) > 0 THEN 'Высокий спрос, мало товара'
                WHEN SUM(i.current_stock) BETWEEN 5 AND 15 AND SUM(i.reserved_stock) > 0 THEN 'Нужно пополнение'
                ELSE 'Требует анализа'
            END as marketing_action
        FROM inventory_data i
        LEFT JOIN product_master pm ON i.sku = pm.sku_ozon
        LEFT JOIN product_names pn ON i.sku = pn.sku
        WHERE i.current_stock > 0
        AND (
            (i.current_stock > 200 AND i.reserved_stock = 0) OR
            (i.current_stock < 15 AND i.reserved_stock > 0) OR
            (i.current_stock < 5)
        )
        GROUP BY i.sku, pm.product_name, pm.brand, pm.category, pn.product_name
        ORDER BY 
            CASE 
                WHEN SUM(i.current_stock) < 5 AND SUM(i.reserved_stock) > 0 THEN 1
                WHEN SUM(i.current_stock) BETWEEN 5 AND 15 AND SUM(i.reserved_stock) > 0 THEN 2
                WHEN SUM(i.current_stock) > 200 AND SUM(i.reserved_stock) = 0 THEN 3
                ELSE 4
            END,
            total_stock DESC
        LIMIT 5
    ");
    
    $attention_products = $attention_stmt->fetchAll();
    
    echo "Найдено товаров требующих внимания: " . count($attention_products) . "\n";
    foreach ($attention_products as $product) {
        echo "  - " . $product['product_name'] . "\n";
        echo "    SKU: " . $product['sku'] . ", Бренд: " . $product['brand'] . "\n";
        echo "    Остаток: " . $product['total_stock'] . ", Резерв: " . $product['total_reserved'] . "\n";
        echo "    Рекомендация: " . $product['marketing_action'] . "\n\n";
    }
    
    // Тест запроса топ товаров
    echo "🏆 Тестирование запроса топ товаров:\n";
    
    $top_stmt = $pdo->query("
        SELECT 
            i.sku,
            CASE
                WHEN pm.product_name IS NOT NULL AND pm.product_name NOT LIKE 'Товар артикул %' THEN pm.product_name
                WHEN pn.product_name IS NOT NULL THEN pn.product_name
                WHEN i.sku REGEXP '^[0-9]+$' THEN CONCAT('Товар артикул ', i.sku)
                WHEN LENGTH(i.sku) > 50 THEN CONCAT(SUBSTRING(i.sku, 1, 47), '...')
                ELSE i.sku
            END as product_name,
            COALESCE(pm.brand, 'Без бренда') as brand,
            SUM(i.current_stock) as total_stock,
            SUM(i.reserved_stock) as total_reserved
        FROM inventory_data i
        LEFT JOIN product_master pm ON i.sku = pm.sku_ozon
        LEFT JOIN product_names pn ON i.sku = pn.sku
        WHERE i.current_stock > 0
        GROUP BY i.sku, pm.product_name, pm.brand, pm.category, pn.product_name
        ORDER BY total_stock DESC
        LIMIT 5
    ");
    
    $top_products = $top_stmt->fetchAll();
    
    echo "Топ товары по остаткам:\n";
    foreach ($top_products as $product) {
        echo "  - " . $product['product_name'] . "\n";
        echo "    SKU: " . $product['sku'] . ", Бренд: " . $product['brand'] . "\n";
        echo "    Остаток: " . $product['total_stock'] . ", Резерв: " . $product['total_reserved'] . "\n\n";
    }
    
    echo "🎉 Все запросы работают корректно!\n";
    
} catch (PDOException $e) {
    echo "❌ Ошибка БД: " . $e->getMessage() . "\n";
} catch (Exception $e) {
    echo "❌ Общая ошибка: " . $e->getMessage() . "\n";
}
?>