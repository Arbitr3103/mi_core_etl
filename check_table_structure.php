<?php
/**
 * Проверка структуры таблицы inventory_data
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
    
    echo "=== СТРУКТУРА ТАБЛИЦЫ inventory_data ===\n\n";
    
    $stmt = $pdo->query("DESCRIBE inventory_data");
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        echo sprintf("%-20s %-15s %-10s %-10s %-15s %s\n", 
            $row['Field'], 
            $row['Type'], 
            $row['Null'], 
            $row['Key'], 
            $row['Default'], 
            $row['Extra']
        );
    }
    
    echo "\n=== ПРИМЕР ДАННЫХ ===\n\n";
    $stmt = $pdo->query("SELECT * FROM inventory_data LIMIT 3");
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        foreach ($row as $key => $value) {
            echo "$key: $value\n";
        }
        echo "---\n";
    }
    
} catch (Exception $e) {
    echo "Ошибка: " . $e->getMessage() . "\n";
}
?>