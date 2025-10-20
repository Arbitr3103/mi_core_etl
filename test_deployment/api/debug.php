<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

echo "=== DEBUG: Проверка подключения к базе данных ===\n\n";

// Загружаем конфигурацию
if (file_exists(__DIR__ . '/../config.php')) {
    require_once __DIR__ . '/../config.php';
    echo "✅ Конфигурация загружена\n";
} else {
    echo "❌ Файл config.php не найден\n";
    exit(1);
}

// Проверяем переменные окружения
echo "\n=== Конфигурация базы данных ===\n";
echo "DB_HOST: " . (defined('DB_HOST') ? DB_HOST : 'НЕ ОПРЕДЕЛЕН') . "\n";
echo "DB_NAME: " . (defined('DB_NAME') ? DB_NAME : 'НЕ ОПРЕДЕЛЕН') . "\n";
echo "DB_USER: " . (defined('DB_USER') ? DB_USER : 'НЕ ОПРЕДЕЛЕН') . "\n";
echo "DB_PASSWORD: " . (defined('DB_PASSWORD') ? '***скрыт***' : 'НЕ ОПРЕДЕЛЕН') . "\n";

// Пытаемся подключиться к базе данных
try {
    $pdo = new PDO(
        "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4",
        DB_USER,
        DB_PASSWORD,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]
    );
    echo "\n✅ Подключение к базе данных успешно!\n";
} catch (PDOException $e) {
    echo "\n❌ Ошибка подключения к базе данных: " . $e->getMessage() . "\n";
    exit(1);
}

// Проверяем таблицы с "product" в названии
echo "\n=== Таблицы с 'product' в названии ===\n";
try {
    $stmt = $pdo->query("SHOW TABLES LIKE '%product%'");
    $tables = $stmt->fetchAll(PDO::FETCH_COLUMN);
    
    if (empty($tables)) {
        echo "❌ Таблицы с 'product' в названии не найдены\n";
    } else {
        foreach ($tables as $table) {
            echo "✅ Найдена таблица: $table\n";
            
            // Проверяем количество записей в таблице
            $countStmt = $pdo->query("SELECT COUNT(*) as count FROM `$table`");
            $count = $countStmt->fetch()['count'];
            echo "   Записей в таблице: $count\n";
            
            // Показываем структуру таблицы
            echo "   Структура таблицы:\n";
            $structStmt = $pdo->query("DESCRIBE `$table`");
            $columns = $structStmt->fetchAll();
            foreach ($columns as $column) {
                echo "   - {$column['Field']} ({$column['Type']})\n";
            }
            echo "\n";
        }
    }
} catch (PDOException $e) {
    echo "❌ Ошибка при получении списка таблиц: " . $e->getMessage() . "\n";
}

// Проверяем все таблицы в базе
echo "\n=== Все таблицы в базе данных ===\n";
try {
    $stmt = $pdo->query("SHOW TABLES");
    $allTables = $stmt->fetchAll(PDO::FETCH_COLUMN);
    
    echo "Всего таблиц: " . count($allTables) . "\n";
    foreach ($allTables as $table) {
        echo "- $table\n";
    }
} catch (PDOException $e) {
    echo "❌ Ошибка при получении списка всех таблиц: " . $e->getMessage() . "\n";
}

// Детальная проверка dim_products
echo "\n=== ДЕТАЛЬНАЯ ПРОВЕРКА dim_products ===\n";
try {
    echo "Структура таблицы dim_products:\n";
    $stmt = $pdo->query("DESCRIBE dim_products");
    $columns = $stmt->fetchAll();
    
    foreach ($columns as $column) {
        echo "- {$column['Field']} ({$column['Type']}) " . 
             ($column['Null'] == 'YES' ? 'NULL' : 'NOT NULL') . 
             ($column['Key'] ? " [{$column['Key']}]" : '') . "\n";
    }

    echo "\nПримеры данных из dim_products (первые 3 записи):\n";
    $stmt = $pdo->query("SELECT * FROM dim_products LIMIT 3");
    $rows = $stmt->fetchAll();
    
    if (empty($rows)) {
        echo "❌ Таблица dim_products пустая!\n";
    } else {
        // Показываем заголовки
        $headers = array_keys($rows[0]);
        echo implode(" | ", $headers) . "\n";
        echo str_repeat("-", 100) . "\n";
        
        // Показываем данные
        foreach ($rows as $row) {
            $values = array_map(function($val) {
                return substr($val ?? 'NULL', 0, 20);
            }, array_values($row));
            echo implode(" | ", $values) . "\n";
        }
    }

    echo "\nКоличество записей в dim_products: ";
    $stmt = $pdo->query("SELECT COUNT(*) as count FROM dim_products");
    $count = $stmt->fetch()['count'];
    echo "$count\n";

} catch (PDOException $e) {
    echo "❌ Ошибка при проверке dim_products: " . $e->getMessage() . "\n";
}

echo "\n=== Конец отладки ===\n";
?>