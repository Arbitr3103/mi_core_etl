<?php
/**
 * Создание таблицы inventory_sync_log
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
    
    echo "Создание таблицы inventory_sync_log...\n";
    
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS inventory_sync_log (
            id INT AUTO_INCREMENT PRIMARY KEY,
            source VARCHAR(50) NOT NULL,
            status VARCHAR(20) NOT NULL,
            records_processed INT DEFAULT 0,
            records_inserted INT DEFAULT 0,
            records_failed INT DEFAULT 0,
            duration_seconds INT DEFAULT 0,
            api_requests_count INT DEFAULT 0,
            error_message TEXT,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_source_created (source, created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    
    echo "✅ Таблица inventory_sync_log создана успешно!\n";
    
    // Добавим тестовую запись
    $stmt = $pdo->prepare("
        INSERT INTO inventory_sync_log 
        (source, status, records_processed, records_inserted, records_failed, 
         duration_seconds, api_requests_count)
        VALUES (?, ?, ?, ?, ?, ?, ?)
    ");
    
    $stmt->execute([
        'Ozon_v4',
        'success',
        202,
        202,
        0,
        45,
        3
    ]);
    
    echo "✅ Добавлена тестовая запись в лог\n";
    
} catch (Exception $e) {
    echo "❌ Ошибка: " . $e->getMessage() . "\n";
}
?>