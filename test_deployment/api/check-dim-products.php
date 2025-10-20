<?php
require_once __DIR__ . '/../config.php';

try {
    $pdo = new PDO(
        "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4",
        DB_USER,
        DB_PASSWORD,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );

    echo "=== Структура таблицы dim_products ===\n";
    $stmt = $pdo->query("DESCRIBE dim_products");
    $columns = $stmt->fetchAll();
    
    foreach ($columns as $column) {
        echo "- {$column['Field']} ({$column['Type']}) " . 
             ($column['Null'] == 'YES' ? 'NULL' : 'NOT NULL') . 
             ($column['Key'] ? " [{$column['Key']}]" : '') . "\n";
    }

    echo "\n=== Примеры данных из dim_products ===\n";
    $stmt = $pdo->query("SELECT * FROM dim_products LIMIT 5");
    $rows = $stmt->fetchAll();
    
    if (empty($rows)) {
        echo "Таблица пустая\n";
    } else {
        // Показываем заголовки
        $headers = array_keys($rows[0]);
        echo implode(" | ", $headers) . "\n";
        echo str_repeat("-", 80) . "\n";
        
        // Показываем данные
        foreach ($rows as $row) {
            $values = array_map(function($val) {
                return substr($val ?? 'NULL', 0, 15);
            }, array_values($row));
            echo implode(" | ", $values) . "\n";
        }
    }

    echo "\n=== Количество записей ===\n";
    $stmt = $pdo->query("SELECT COUNT(*) as count FROM dim_products");
    $count = $stmt->fetch()['count'];
    echo "Всего записей в dim_products: $count\n";

} catch (PDOException $e) {
    echo "Ошибка: " . $e->getMessage() . "\n";
}
?>