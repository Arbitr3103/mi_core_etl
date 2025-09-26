<?php
/**
 * Диагностика структуры базы данных
 * Помогает понять, какие таблицы и колонки доступны
 */

// Подключение к базе данных
try {
    $host = 'localhost';
    $dbname = 'mi_core_db';
    $username = 'mi_core_user';
    $password = 'your_password_here';
    
    $dsn = "mysql:host={$host};dbname={$dbname};charset=utf8mb4";
    $pdo = new PDO($dsn, $username, $password, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
    ]);
    
    echo "=== ДИАГНОСТИКА СТРУКТУРЫ БАЗЫ ДАННЫХ ===\n\n";
    
    // 1. Показать все таблицы
    echo "1. ВСЕ ТАБЛИЦЫ В БАЗЕ:\n";
    $stmt = $pdo->query("SHOW TABLES");
    $tables = $stmt->fetchAll();
    foreach ($tables as $table) {
        echo "- " . array_values($table)[0] . "\n";
    }
    echo "\n";
    
    // 2. Показать все представления
    echo "2. ВСЕ ПРЕДСТАВЛЕНИЯ (VIEWS):\n";
    $stmt = $pdo->query("SHOW TABLES WHERE Table_type = 'VIEW'");
    $views = $stmt->fetchAll();
    foreach ($views as $view) {
        echo "- " . array_values($view)[0] . "\n";
    }
    echo "\n";
    
    // 3. Проверить структуру v_car_applicability
    echo "3. СТРУКТУРА v_car_applicability:\n";
    try {
        $stmt = $pdo->query("DESCRIBE v_car_applicability");
        $columns = $stmt->fetchAll();
        foreach ($columns as $column) {
            echo "- {$column['Field']} ({$column['Type']})\n";
        }
        
        // Показать несколько записей
        echo "\nПервые 3 записи:\n";
        $stmt = $pdo->query("SELECT * FROM v_car_applicability LIMIT 3");
        $rows = $stmt->fetchAll();
        foreach ($rows as $i => $row) {
            echo "Запись " . ($i + 1) . ":\n";
            foreach ($row as $key => $value) {
                echo "  {$key}: {$value}\n";
            }
            echo "\n";
        }
    } catch (Exception $e) {
        echo "ОШИБКА: " . $e->getMessage() . "\n";
    }
    echo "\n";
    
    // 4. Проверить таблицы, которые могут содержать страны
    $countryTables = ['regions', 'countries', 'brands', 'car_models'];
    
    foreach ($countryTables as $tableName) {
        echo "4. СТРУКТУРА {$tableName}:\n";
        try {
            $stmt = $pdo->query("DESCRIBE {$tableName}");
            $columns = $stmt->fetchAll();
            foreach ($columns as $column) {
                echo "- {$column['Field']} ({$column['Type']})\n";
            }
            
            // Показать несколько записей
            echo "\nПервые 3 записи:\n";
            $stmt = $pdo->query("SELECT * FROM {$tableName} LIMIT 3");
            $rows = $stmt->fetchAll();
            foreach ($rows as $i => $row) {
                echo "Запись " . ($i + 1) . ":\n";
                foreach ($row as $key => $value) {
                    echo "  {$key}: {$value}\n";
                }
                echo "\n";
            }
        } catch (Exception $e) {
            echo "Таблица {$tableName} не найдена или недоступна: " . $e->getMessage() . "\n";
        }
        echo "\n";
    }
    
} catch (Exception $e) {
    echo "ОШИБКА ПОДКЛЮЧЕНИЯ К БД: " . $e->getMessage() . "\n";
}
?>