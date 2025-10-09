<?php
/**
 * Проверка структуры базы данных
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
        "mysql:host=" . ($_ENV['DB_HOST'] ?? 'localhost') . ";dbname=" . ($_ENV['DB_NAME'] ?? 'mi_core') . ";charset=utf8mb4",
        $_ENV['DB_USER'] ?? 'v_admin',
        $_ENV['DB_PASSWORD'] ?? '',
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
        ]
    );
    
    echo "✓ Подключение к БД: " . ($_ENV['DB_NAME'] ?? 'mi_core') . "\n\n";
    
    // Показываем все таблицы
    echo "=== ТАБЛИЦЫ В БАЗЕ ДАННЫХ ===\n";
    $stmt = $pdo->query("SHOW TABLES");
    $tables = $stmt->fetchAll(PDO::FETCH_COLUMN);
    
    if (empty($tables)) {
        echo "❌ Таблицы не найдены!\n";
    } else {
        foreach ($tables as $table) {
            echo "✓ {$table}\n";
        }
    }
    
    echo "\n=== ПРОВЕРКА ОСНОВНЫХ ТАБЛИЦ ===\n";
    
    $required_tables = [
        'inventory' => 'Основная таблица остатков',
        'sync_logs' => 'Логи синхронизации',
        'ozon_warehouses' => 'Склады Ozon',
        'dim_products' => 'Справочник товаров'
    ];
    
    foreach ($required_tables as $table => $description) {
        if (in_array($table, $tables)) {
            echo "✓ {$table} - {$description}\n";
            
            // Показываем количество записей
            try {
                $stmt = $pdo->query("SELECT COUNT(*) as count FROM {$table}");
                $count = $stmt->fetch()['count'];
                echo "  Записей: {$count}\n";
                
                // Показываем структуру таблицы
                if ($table === 'inventory') {
                    echo "  Структура:\n";
                    $stmt = $pdo->query("DESCRIBE {$table}");
                    $columns = $stmt->fetchAll();
                    foreach ($columns as $column) {
                        echo "    - {$column['Field']} ({$column['Type']})\n";
                    }
                }
            } catch (Exception $e) {
                echo "  Ошибка чтения: " . $e->getMessage() . "\n";
            }
        } else {
            echo "❌ {$table} - {$description} - НЕ НАЙДЕНА\n";
        }
        echo "\n";
    }
    
    // Если таблица inventory не найдена, ищем альтернативы
    if (!in_array('inventory', $tables)) {
        echo "=== ПОИСК АЛЬТЕРНАТИВНЫХ ТАБЛИЦ ===\n";
        
        $inventory_like = array_filter($tables, function($table) {
            return strpos(strtolower($table), 'inventory') !== false || 
                   strpos(strtolower($table), 'stock') !== false ||
                   strpos(strtolower($table), 'остат') !== false;
        });
        
        if (!empty($inventory_like)) {
            echo "Найдены похожие таблицы:\n";
            foreach ($inventory_like as $table) {
                echo "- {$table}\n";
            }
        }
        
        // Проверяем таблицы с данными
        echo "\nТаблицы с данными:\n";
        foreach ($tables as $table) {
            try {
                $stmt = $pdo->query("SELECT COUNT(*) as count FROM {$table}");
                $count = $stmt->fetch()['count'];
                if ($count > 0) {
                    echo "- {$table}: {$count} записей\n";
                }
            } catch (Exception $e) {
                // Игнорируем ошибки
            }
        }
    }
    
} catch (PDOException $e) {
    echo "Ошибка БД: " . $e->getMessage() . "\n";
}
?>