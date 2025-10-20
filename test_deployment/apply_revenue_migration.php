<?php
/**
 * Скрипт для применения миграции добавления поля revenue
 */

echo "🔄 Применяем миграцию для добавления поля revenue в таблицу ozon_funnel_data\n";
echo "====================================================================\n\n";

try {
    // Подключение к базе данных
    $host = '127.0.0.1';
    $dbname = 'mi_core_db';
    $username = 'mi_core_user';
    $password = 'secure_password_123';
    
    $dsn = "mysql:host={$host};dbname={$dbname};charset=utf8mb4";
    $pdo = new PDO($dsn, $username, $password, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
    ]);
    
    echo "✅ Подключение к базе данных установлено\n\n";
    
    // Читаем и выполняем миграцию
    $migrationSQL = file_get_contents('migrations/add_revenue_to_funnel_data.sql');
    
    if (!$migrationSQL) {
        throw new Exception('Не удалось прочитать файл миграции');
    }
    
    // Разбиваем SQL на отдельные запросы
    $statements = array_filter(array_map('trim', explode(';', $migrationSQL)));
    
    foreach ($statements as $statement) {
        if (empty($statement) || strpos($statement, '--') === 0) {
            continue;
        }
        
        try {
            $result = $pdo->query($statement);
            if ($result) {
                $data = $result->fetchAll();
                if (!empty($data)) {
                    foreach ($data as $row) {
                        if (isset($row['result'])) {
                            echo $row['result'] . "\n";
                        } elseif (isset($row['message'])) {
                            echo $row['message'] . "\n";
                        } elseif (isset($row['column_name'])) {
                            echo "  - " . $row['column_name'] . " (" . $row['data_type'] . ")\n";
                        }
                    }
                }
            }
        } catch (PDOException $e) {
            if (strpos($e->getMessage(), 'Duplicate column name') !== false) {
                echo "ℹ️ Поле revenue уже существует\n";
            } else {
                throw $e;
            }
        }
    }
    
    echo "\n🎉 Миграция успешно применена!\n";
    
    // Проверяем структуру таблицы
    echo "\n📋 Текущая структура таблицы ozon_funnel_data:\n";
    $stmt = $pdo->query("DESCRIBE ozon_funnel_data");
    $columns = $stmt->fetchAll();
    
    foreach ($columns as $column) {
        $nullable = $column['Null'] === 'YES' ? 'NULL' : 'NOT NULL';
        $default = $column['Default'] ? "DEFAULT {$column['Default']}" : '';
        echo "  - {$column['Field']}: {$column['Type']} {$nullable} {$default}\n";
    }
    
} catch (Exception $e) {
    echo "❌ Ошибка: " . $e->getMessage() . "\n";
    echo "Трассировка: " . $e->getTraceAsString() . "\n";
}
?>