<?php
/**
 * Проверка названий товаров в данных
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
    
    echo "=== АНАЛИЗ SKU И НАЗВАНИЙ ===\n\n";
    
    // Получаем разные типы SKU
    $stmt = $pdo->query("
        SELECT DISTINCT product_id, sku, source
        FROM inventory_data 
        WHERE current_stock > 0
        ORDER BY 
            CASE WHEN sku REGEXP '^[0-9]+$' THEN 1 ELSE 0 END,
            LENGTH(sku) DESC
        LIMIT 15
    ");
    
    echo "Примеры SKU и Product ID:\n";
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $skuType = is_numeric($row['sku']) ? 'Числовой' : 'Текстовый';
        echo sprintf("%-30s | ID: %-10s | %s | %s\n", 
            $row['sku'], 
            $row['product_id'], 
            $skuType,
            $row['source']
        );
    }
    
    echo "\n=== ПОИСК ТОВАРОВ С ЧИТАЕМЫМИ НАЗВАНИЯМИ ===\n";
    
    // Ищем товары с читаемыми названиями в SKU
    $stmt = $pdo->query("
        SELECT DISTINCT product_id, sku, source, current_stock
        FROM inventory_data 
        WHERE sku NOT REGEXP '^[0-9]+$'
        AND current_stock > 0
        ORDER BY current_stock DESC
        LIMIT 10
    ");
    
    echo "Товары с названиями в SKU:\n";
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        echo sprintf("%-50s | ID: %-10s | Остаток: %-3s | %s\n", 
            $row['sku'], 
            $row['product_id'], 
            $row['current_stock'],
            $row['source']
        );
    }
    
} catch (Exception $e) {
    echo "Ошибка: " . $e->getMessage() . "\n";
}
?>