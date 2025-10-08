<?php
require_once __DIR__ . '/config.php';

try {
    $pdo = new PDO(
        "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4",
        DB_USER,
        DB_PASSWORD,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );

    echo "=== Структура dim_products ===\n";
    $stmt = $pdo->query("DESCRIBE dim_products");
    while ($row = $stmt->fetch()) {
        echo $row['Field'] . " - " . $row['Type'] . "\n";
    }

    echo "\n=== Примеры данных ===\n";
    $stmt = $pdo->query("SELECT * FROM dim_products LIMIT 3");
    while ($row = $stmt->fetch()) {
        print_r($row);
        echo "---\n";
    }

    echo "\n=== Количество записей ===\n";
    $stmt = $pdo->query("SELECT COUNT(*) as count FROM dim_products");
    $count = $stmt->fetch()['count'];
    echo "Всего записей: $count\n";

} catch (Exception $e) {
    echo "Ошибка: " . $e->getMessage() . "\n";
}
?>