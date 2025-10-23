<?php
/**
 * Анализ данных товаров для понимания структуры
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
    
    echo "=== АНАЛИЗ ТОВАРОВ ===\n\n";
    
    // Получаем примеры разных типов товаров
    $stmt = $pdo->query("
        SELECT DISTINCT 
            product_id,
            sku,
            source,
            CASE 
                WHEN sku REGEXP '^[0-9]+$' THEN 'Числовой SKU'
                WHEN LENGTH(sku) > 20 THEN 'Длинное название'
                WHEN sku LIKE '%г%' OR sku LIKE '%шт%' OR sku LIKE '%кг%' THEN 'Название с весом'
                ELSE 'Короткое название'
            END as sku_type
        FROM inventory_data 
        WHERE current_stock > 0
        ORDER BY 
            CASE WHEN sku REGEXP '^[0-9]+$' THEN 1 ELSE 0 END,
            sku_type,
            sku
        LIMIT 20
    ");
    
    echo "Примеры товаров по типам:\n";
    echo str_pad("Product ID", 12) . str_pad("SKU", 40) . str_pad("Тип", 20) . "Источник\n";
    echo str_repeat("-", 80) . "\n";
    
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        echo str_pad($row['product_id'], 12) . 
             str_pad(substr($row['sku'], 0, 38), 40) . 
             str_pad($row['sku_type'], 20) . 
             $row['source'] . "\n";
    }
    
    echo "\n=== СТАТИСТИКА ПО ТИПАМ ===\n";
    
    $stmt = $pdo->query("
        SELECT 
            CASE 
                WHEN sku REGEXP '^[0-9]+$' THEN 'Числовой SKU'
                WHEN LENGTH(sku) > 20 THEN 'Длинное название'
                WHEN sku LIKE '%г%' OR sku LIKE '%шт%' OR sku LIKE '%кг%' THEN 'Название с весом'
                ELSE 'Короткое название'
            END as sku_type,
            COUNT(*) as count,
            COUNT(CASE WHEN product_id > 0 THEN 1 END) as with_product_id
        FROM inventory_data 
        WHERE current_stock > 0
        GROUP BY sku_type
        ORDER BY count DESC
    ");
    
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        echo sprintf("%-20s: %d записей (%d с Product ID)\n", 
            $row['sku_type'], 
            $row['count'], 
            $row['with_product_id']
        );
    }
    
} catch (Exception $e) {
    echo "Ошибка: " . $e->getMessage() . "\n";
}
?>