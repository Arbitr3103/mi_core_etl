<?php
/**
 * Детальный анализ структуры данных товаров в базе
 */

// Конфигурация БД
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

define('DB_HOST', $_ENV['DB_HOST'] ?? 'localhost');
define('DB_NAME', $_ENV['DB_NAME'] ?? 'mi_core');
define('DB_USER', $_ENV['DB_USER'] ?? 'v_admin');
define('DB_PASS', $_ENV['DB_PASSWORD'] ?? 'Arbitr09102022!');

try {
    $pdo = new PDO(
        "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4",
        DB_USER,
        DB_PASS,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
    
    echo "=== ДЕТАЛЬНЫЙ АНАЛИЗ СТРУКТУРЫ ДАННЫХ ===\n\n";
    
    // 1. Анализ по источникам данных
    echo "1. АНАЛИЗ ПО ИСТОЧНИКАМ:\n";
    echo str_repeat("-", 60) . "\n";
    
    $stmt = $pdo->query("
        SELECT 
            source,
            COUNT(*) as total_records,
            COUNT(DISTINCT product_id) as unique_product_ids,
            COUNT(DISTINCT sku) as unique_skus,
            COUNT(CASE WHEN product_id > 0 THEN 1 END) as records_with_product_id,
            COUNT(CASE WHEN sku REGEXP '^[0-9]+$' THEN 1 END) as numeric_skus,
            COUNT(CASE WHEN sku NOT REGEXP '^[0-9]+$' THEN 1 END) as text_skus
        FROM inventory_data 
        GROUP BY source
        ORDER BY source
    ");
    
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        echo "Источник: {$row['source']}\n";
        echo "  - Всего записей: {$row['total_records']}\n";
        echo "  - Уникальных Product ID: {$row['unique_product_ids']}\n";
        echo "  - Уникальных SKU: {$row['unique_skus']}\n";
        echo "  - Записей с Product ID > 0: {$row['records_with_product_id']}\n";
        echo "  - Числовых SKU: {$row['numeric_skus']}\n";
        echo "  - Текстовых SKU: {$row['text_skus']}\n\n";
    }
    
    // 2. Примеры данных из источника Ozon (основные товары)
    echo "2. ПРИМЕРЫ ДАННЫХ ИЗ ИСТОЧНИКА 'Ozon' (основные товары):\n";
    echo str_repeat("-", 80) . "\n";
    echo str_pad("Product ID", 12) . str_pad("SKU", 50) . str_pad("Остаток", 10) . "Склад\n";
    echo str_repeat("-", 80) . "\n";
    
    $stmt = $pdo->query("
        SELECT product_id, sku, current_stock, warehouse_name
        FROM inventory_data 
        WHERE source = 'Ozon' 
        AND current_stock > 0
        ORDER BY current_stock DESC
        LIMIT 10
    ");
    
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        echo str_pad($row['product_id'], 12) . 
             str_pad(substr($row['sku'], 0, 48), 50) . 
             str_pad($row['current_stock'], 10) . 
             $row['warehouse_name'] . "\n";
    }
    
    // 3. Примеры данных из источника Ozon_Analytics (аналитические данные)
    echo "\n3. ПРИМЕРЫ ДАННЫХ ИЗ ИСТОЧНИКА 'Ozon_Analytics' (аналитические данные):\n";
    echo str_repeat("-", 80) . "\n";
    echo str_pad("Product ID", 12) . str_pad("SKU", 50) . str_pad("Остаток", 10) . "Склад\n";
    echo str_repeat("-", 80) . "\n";
    
    $stmt = $pdo->query("
        SELECT product_id, sku, current_stock, warehouse_name
        FROM inventory_data 
        WHERE source = 'Ozon_Analytics' 
        AND current_stock > 0
        ORDER BY current_stock DESC
        LIMIT 10
    ");
    
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        echo str_pad($row['product_id'], 12) . 
             str_pad(substr($row['sku'], 0, 48), 50) . 
             str_pad($row['current_stock'], 10) . 
             $row['warehouse_name'] . "\n";
    }
    
    // 4. Анализ связи между Product ID и SKU
    echo "\n4. АНАЛИЗ СВЯЗИ МЕЖДУ PRODUCT_ID И SKU:\n";
    echo str_repeat("-", 60) . "\n";
    
    // Товары с одинаковым Product ID но разными SKU
    $stmt = $pdo->query("
        SELECT 
            product_id,
            GROUP_CONCAT(DISTINCT sku ORDER BY sku SEPARATOR ' | ') as all_skus,
            COUNT(DISTINCT sku) as sku_count,
            SUM(current_stock) as total_stock
        FROM inventory_data 
        WHERE product_id > 0
        GROUP BY product_id
        HAVING sku_count > 1
        ORDER BY sku_count DESC, total_stock DESC
        LIMIT 5
    ");
    
    echo "Товары с одним Product ID но разными SKU:\n";
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        echo "Product ID: {$row['product_id']}\n";
        echo "  SKU: {$row['all_skus']}\n";
        echo "  Количество SKU: {$row['sku_count']}\n";
        echo "  Общий остаток: {$row['total_stock']}\n\n";
    }
    
    // 5. Товары с одинаковым SKU но разными Product ID
    $stmt = $pdo->query("
        SELECT 
            sku,
            GROUP_CONCAT(DISTINCT product_id ORDER BY product_id SEPARATOR ', ') as all_product_ids,
            COUNT(DISTINCT product_id) as product_id_count,
            SUM(current_stock) as total_stock
        FROM inventory_data 
        WHERE sku NOT REGEXP '^[0-9]+$'
        AND product_id > 0
        GROUP BY sku
        HAVING product_id_count > 1
        ORDER BY product_id_count DESC, total_stock DESC
        LIMIT 5
    ");
    
    echo "Товары с одним SKU но разными Product ID:\n";
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        echo "SKU: {$row['sku']}\n";
        echo "  Product IDs: {$row['all_product_ids']}\n";
        echo "  Количество Product ID: {$row['product_id_count']}\n";
        echo "  Общий остаток: {$row['total_stock']}\n\n";
    }
    
    // 6. Выводы и рекомендации
    echo "6. ВЫВОДЫ О СТРУКТУРЕ ДАННЫХ:\n";
    echo str_repeat("-", 60) . "\n";
    
    $stmt = $pdo->query("
        SELECT 
            'Ozon' as source_type,
            'Основные товары с названиями' as description,
            COUNT(*) as count,
            'SKU = Название товара, Product_ID = Уникальный ID' as structure
        FROM inventory_data 
        WHERE source = 'Ozon' AND sku NOT REGEXP '^[0-9]+$'
        
        UNION ALL
        
        SELECT 
            'Ozon_Analytics' as source_type,
            'Аналитические данные с артикулами' as description,
            COUNT(*) as count,
            'SKU = Артикул товара, Product_ID = 0 (нет)' as structure
        FROM inventory_data 
        WHERE source = 'Ozon_Analytics' AND sku REGEXP '^[0-9]+$'
    ");
    
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        echo "Тип: {$row['source_type']}\n";
        echo "  Описание: {$row['description']}\n";
        echo "  Количество: {$row['count']}\n";
        echo "  Структура: {$row['structure']}\n\n";
    }
    
} catch (Exception $e) {
    echo "Ошибка: " . $e->getMessage() . "\n";
}
?>