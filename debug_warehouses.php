<?php
/**
 * Отладка складов - проверяем что в базе данных
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
    
    echo "=== АНАЛИЗ СКЛАДОВ В БАЗЕ ДАННЫХ ===\n\n";
    
    // 1. Все уникальные склады
    echo "1. Все уникальные склады:\n";
    $stmt = $pdo->query("
        SELECT DISTINCT 
            warehouse_name, 
            stock_type, 
            source,
            COUNT(*) as products_count,
            SUM(current_stock) as total_stock
        FROM inventory_data 
        WHERE source IN ('Ozon', 'Ozon_Analytics')
        GROUP BY warehouse_name, stock_type, source
        ORDER BY total_stock DESC
    ");
    
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        echo sprintf("  - %s (%s) [%s]: %d товаров, %d остатков\n", 
            $row['warehouse_name'], 
            $row['stock_type'], 
            $row['source'],
            $row['products_count'],
            $row['total_stock']
        );
    }
    
    echo "\n2. Статистика по источникам:\n";
    $stmt = $pdo->query("
        SELECT 
            source,
            COUNT(DISTINCT warehouse_name) as warehouses_count,
            COUNT(*) as total_records,
            SUM(current_stock) as total_stock
        FROM inventory_data 
        WHERE source IN ('Ozon', 'Ozon_Analytics')
        GROUP BY source
    ");
    
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        echo sprintf("  - %s: %d складов, %d записей, %d остатков\n", 
            $row['source'], 
            $row['warehouses_count'],
            $row['total_records'],
            $row['total_stock']
        );
    }
    
    echo "\n3. Проверяем что показывает дашборд (топ 10 складов):\n";
    $stmt = $pdo->query("
        SELECT 
            CASE 
                WHEN source = 'Ozon_Analytics' THEN CONCAT(warehouse_name, ' (', stock_type, ')')
                ELSE CONCAT(warehouse_name, ' (', stock_type, ')')
            END as stock_type,
            COUNT(*) as count,
            SUM(current_stock) as total_stock,
            source
        FROM inventory_data 
        WHERE source IN ('Ozon', 'Ozon_Analytics')
        GROUP BY source, warehouse_name, stock_type
        ORDER BY total_stock DESC
        LIMIT 10
    ");
    
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        echo sprintf("  - %s: %d товаров, %d остатков [%s]\n", 
            $row['stock_type'], 
            $row['count'],
            $row['total_stock'],
            $row['source']
        );
    }
    
} catch (Exception $e) {
    echo "Ошибка: " . $e->getMessage() . "\n";
}
?>