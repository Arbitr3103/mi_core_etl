<?php
/**
 * Скрипт для исправления проблем API и базы данных
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
    // Подключение с правами администратора для исправления структуры
    $pdo = new PDO(
        "mysql:host=" . ($_ENV['DB_HOST'] ?? 'localhost') . ";dbname=" . ($_ENV['DB_NAME'] ?? 'mi_core') . ";charset=utf8mb4",
        $_ENV['DB_USER'] ?? 'v_admin',
        $_ENV['DB_PASSWORD'] ?? '',
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
        ]
    );
    
    echo "✓ Подключение к БД успешно\n";
    
    // 1. Проверяем и создаем таблицу ozon_warehouses если нужно
    echo "\n1. Проверка таблицы ozon_warehouses...\n";
    
    $stmt = $pdo->query("SHOW TABLES LIKE 'ozon_warehouses'");
    if ($stmt->rowCount() == 0) {
        echo "Создаем таблицу ozon_warehouses...\n";
        $pdo->exec("
            CREATE TABLE ozon_warehouses (
                id INT AUTO_INCREMENT PRIMARY KEY,
                warehouse_id VARCHAR(50) NOT NULL UNIQUE,
                name VARCHAR(255) NOT NULL,
                is_rfbs BOOLEAN DEFAULT FALSE,
                has_entrusted_acceptance BOOLEAN DEFAULT FALSE,
                can_print_act_in_advance BOOLEAN DEFAULT FALSE,
                status VARCHAR(50) DEFAULT 'active',
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                INDEX idx_warehouse_id (warehouse_id),
                INDEX idx_status (status)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
        echo "✓ Таблица ozon_warehouses создана\n";
    } else {
        echo "✓ Таблица ozon_warehouses уже существует\n";
    }
    
    // 2. Исправляем тип данных для product_id в inventory
    echo "\n2. Исправление типа данных product_id...\n";
    
    try {
        // Проверяем текущий тип колонки
        $stmt = $pdo->query("DESCRIBE inventory product_id");
        $column_info = $stmt->fetch();
        
        if (strpos($column_info['Type'], 'bigint') === false) {
            echo "Изменяем тип product_id на BIGINT...\n";
            $pdo->exec("ALTER TABLE inventory MODIFY COLUMN product_id BIGINT");
            echo "✓ Тип product_id изменен на BIGINT\n";
        } else {
            echo "✓ Тип product_id уже BIGINT\n";
        }
    } catch (Exception $e) {
        echo "Ошибка изменения типа product_id: " . $e->getMessage() . "\n";
    }
    
    // 3. Предоставляем права пользователю ingest_user
    echo "\n3. Настройка прав доступа...\n";
    
    try {
        // Проверяем существование пользователя ingest_user
        $stmt = $pdo->query("SELECT User FROM mysql.user WHERE User = 'ingest_user'");
        if ($stmt->rowCount() > 0) {
            echo "Предоставляем права пользователю ingest_user...\n";
            
            $db_name = $_ENV['DB_NAME'] ?? 'mi_core';
            
            // Предоставляем права на создание таблиц и вставку данных
            $pdo->exec("GRANT CREATE, INSERT, UPDATE, DELETE, SELECT ON {$db_name}.* TO 'ingest_user'@'localhost'");
            $pdo->exec("FLUSH PRIVILEGES");
            
            echo "✓ Права предоставлены пользователю ingest_user\n";
        } else {
            echo "Пользователь ingest_user не найден, создаем...\n";
            
            $ingest_password = $_ENV['INGEST_PASSWORD'] ?? 'ingest_pass_2024';
            $db_name = $_ENV['DB_NAME'] ?? 'mi_core';
            
            $pdo->exec("CREATE USER 'ingest_user'@'localhost' IDENTIFIED BY '{$ingest_password}'");
            $pdo->exec("GRANT CREATE, INSERT, UPDATE, DELETE, SELECT ON {$db_name}.* TO 'ingest_user'@'localhost'");
            $pdo->exec("FLUSH PRIVILEGES");
            
            echo "✓ Пользователь ingest_user создан и права предоставлены\n";
        }
    } catch (Exception $e) {
        echo "Ошибка настройки прав: " . $e->getMessage() . "\n";
    }
    
    // 4. Загружаем склады Ozon если таблица пустая
    echo "\n4. Проверка складов Ozon...\n";
    
    $stmt = $pdo->query("SELECT COUNT(*) as count FROM ozon_warehouses");
    $warehouse_count = $stmt->fetch()['count'];
    
    if ($warehouse_count == 0) {
        echo "Загружаем склады Ozon из скрипта...\n";
        
        // Запускаем скрипт загрузки складов
        $output = shell_exec('php ' . __DIR__ . '/scripts/load-real-ozon-warehouses.php 2>&1');
        echo "Результат загрузки складов:\n" . $output . "\n";
        
        // Проверяем результат
        $stmt = $pdo->query("SELECT COUNT(*) as count FROM ozon_warehouses");
        $new_count = $stmt->fetch()['count'];
        echo "✓ Загружено складов: {$new_count}\n";
    } else {
        echo "✓ В таблице уже есть {$warehouse_count} складов\n";
    }
    
    // 5. Проверяем статистику inventory
    echo "\n5. Статистика inventory...\n";
    
    $stmt = $pdo->query("
        SELECT 
            COUNT(*) as total_records,
            COUNT(DISTINCT product_id) as unique_products,
            SUM(quantity_present) as total_stock,
            MAX(updated_at) as last_update
        FROM inventory 
        WHERE quantity_present > 0
    ");
    $stats = $stmt->fetch();
    
    echo "Всего записей: {$stats['total_records']}\n";
    echo "Уникальных товаров: {$stats['unique_products']}\n";
    echo "Общий остаток: {$stats['total_stock']}\n";
    echo "Последнее обновление: {$stats['last_update']}\n";
    
    // 6. Тестируем API
    echo "\n6. Тестирование API...\n";
    
    $api_url = "http://api.zavodprostavok.ru/api/inventory-v4.php";
    
    $test_endpoints = [
        'stats' => 'Общая статистика',
        'products' => 'Список товаров',
        'critical' => 'Критические остатки',
        'test' => 'Тест API'
    ];
    
    foreach ($test_endpoints as $action => $description) {
        $url = $api_url . "?action=" . $action;
        $response = @file_get_contents($url);
        
        if ($response) {
            $data = json_decode($response, true);
            if ($data && $data['success']) {
                echo "✓ {$description}: OK\n";
            } else {
                echo "✗ {$description}: Ошибка в ответе\n";
            }
        } else {
            echo "✗ {$description}: Не удалось подключиться\n";
        }
    }
    
    echo "\n=== ИСПРАВЛЕНИЯ ЗАВЕРШЕНЫ ===\n";
    echo "Теперь можно тестировать API:\n";
    echo "curl \"http://api.zavodprostavok.ru/api/inventory-v4.php?action=stats\"\n";
    echo "curl \"http://api.zavodprostavok.ru/api/inventory-v4.php?action=products&limit=5\"\n";
    echo "curl \"http://api.zavodprostavok.ru/api/inventory-v4.php?action=critical&threshold=10\"\n";
    
} catch (PDOException $e) {
    echo "Ошибка БД: " . $e->getMessage() . "\n";
} catch (Exception $e) {
    echo "Общая ошибка: " . $e->getMessage() . "\n";
}
?>