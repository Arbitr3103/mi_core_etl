<?php
/**
 * Проверка таблиц с информацией о товарах
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
    
    echo "=== ПОИСК ТАБЛИЦ С ТОВАРАМИ ===\n\n";
    
    // Ищем все таблицы
    $stmt = $pdo->query("SHOW TABLES");
    $tables = $stmt->fetchAll(PDO::FETCH_COLUMN);
    
    echo "Все таблицы в базе:\n";
    foreach ($tables as $table) {
        echo "- $table\n";
    }
    
    // Ищем таблицы с product в названии
    $productTables = array_filter($tables, function($table) {
        return stripos($table, 'product') !== false || stripos($table, 'товар') !== false;
    });
    
    echo "\nТаблицы с товарами:\n";
    foreach ($productTables as $table) {
        echo "\n=== $table ===\n";
        try {
            $stmt = $pdo->query("DESCRIBE $table");
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                echo sprintf("%-20s %-15s\n", $row['Field'], $row['Type']);
            }
            
            // Показываем пример данных
            $stmt = $pdo->query("SELECT * FROM $table LIMIT 2");
            echo "\nПример данных:\n";
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                foreach ($row as $key => $value) {
                    echo "$key: $value\n";
                }
                echo "---\n";
            }
        } catch (Exception $e) {
            echo "Ошибка при чтении таблицы $table: " . $e->getMessage() . "\n";
        }
    }
    
    // Проверяем есть ли в inventory_data связанные данные
    echo "\n=== ПРОВЕРКА СВЯЗЕЙ ===\n";
    $stmt = $pdo->query("
        SELECT DISTINCT product_id, sku 
        FROM inventory_data 
        WHERE product_id > 0 
        LIMIT 5
    ");
    
    echo "Товары с product_id > 0:\n";
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        echo "Product ID: {$row['product_id']}, SKU: {$row['sku']}\n";
    }
    
} catch (Exception $e) {
    echo "Ошибка: " . $e->getMessage() . "\n";
}
?>