<?php
/**
 * Отладка текущей логики отображения
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
    
    echo "=== АНАЛИЗ ТЕКУЩЕЙ ЛОГИКИ ===\n\n";
    
    // Получаем критические остатки как в API
    $stmt = $pdo->query("
        SELECT 
            i.product_id,
            i.sku,
            i.source,
            i.current_stock,
            p.product_name,
            CASE 
                WHEN i.sku REGEXP '^[0-9]+$' THEN CONCAT('Артикул: ', i.sku)
                ELSE COALESCE(p.product_name, i.sku)
            END as display_name,
            CASE 
                WHEN i.sku REGEXP '^[0-9]+$' THEN i.sku
                ELSE COALESCE(NULLIF(i.product_id, 0), 'N/A')
            END as display_id,
            i.sku as display_sku
        FROM inventory_data i
        LEFT JOIN product_names p ON i.product_id = p.product_id AND i.sku = p.sku
        WHERE i.current_stock > 0 AND i.current_stock < 5
        ORDER BY i.current_stock ASC
        LIMIT 10
    ");
    
    echo "Текущая логика API:\n";
    echo str_pad("Source", 15) . str_pad("Product_ID", 12) . str_pad("SKU", 30) . str_pad("Display_ID", 12) . str_pad("Display_Name", 50) . "\n";
    echo str_repeat("-", 120) . "\n";
    
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        echo str_pad($row['source'], 15) . 
             str_pad($row['product_id'], 12) . 
             str_pad(substr($row['sku'], 0, 28), 30) . 
             str_pad($row['display_id'], 12) . 
             substr($row['display_name'], 0, 48) . "\n";
    }
    
    echo "\n=== ПРАВИЛЬНАЯ ЛОГИКА (как должно быть) ===\n";
    echo "Для ВСЕХ товаров:\n";
    echo "- ID товара: Product_ID (если есть) или уникальный номер\n";
    echo "- SKU: оригинальный SKU из базы (артикул)\n";
    echo "- Название: полное название товара\n\n";
    
    // Показываем как должно быть
    $stmt = $pdo->query("
        SELECT 
            i.product_id,
            i.sku,
            i.source,
            i.current_stock,
            p.product_name,
            -- ПРАВИЛЬНАЯ ЛОГИКА:
            COALESCE(NULLIF(i.product_id, 0), CONCAT('AUTO_', i.id)) as correct_id,
            i.sku as correct_sku,
            COALESCE(p.product_name, 
                CASE 
                    WHEN i.sku REGEXP '^[0-9]+$' THEN CONCAT('Товар артикул ', i.sku)
                    ELSE i.sku
                END
            ) as correct_name
        FROM inventory_data i
        LEFT JOIN product_names p ON i.product_id = p.product_id AND i.sku = p.sku
        WHERE i.current_stock > 0 AND i.current_stock < 5
        ORDER BY i.current_stock ASC
        LIMIT 10
    ");
    
    echo "Правильная логика:\n";
    echo str_pad("Source", 15) . str_pad("ID", 12) . str_pad("SKU", 30) . str_pad("Название", 50) . "\n";
    echo str_repeat("-", 110) . "\n";
    
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        echo str_pad($row['source'], 15) . 
             str_pad($row['correct_id'], 12) . 
             str_pad(substr($row['correct_sku'], 0, 28), 30) . 
             substr($row['correct_name'], 0, 48) . "\n";
    }
    
} catch (Exception $e) {
    echo "Ошибка: " . $e->getMessage() . "\n";
}
?>