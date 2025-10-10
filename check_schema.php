<?php
require_once 'config.php';

$pdo = new PDO('mysql:host=' . DB_HOST . ';dbname=' . DB_NAME, DB_USER, DB_PASSWORD);

echo "Tables in database:\n";
$tables = $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
foreach ($tables as $table) {
    echo "  - $table\n";
}

echo "\nChecking product_cross_reference structure:\n";
try {
    $cols = $pdo->query('DESCRIBE product_cross_reference')->fetchAll(PDO::FETCH_ASSOC);
    foreach ($cols as $col) {
        echo "  {$col['Field']}: {$col['Type']}\n";
    }
} catch (Exception $e) {
    echo "  Table does not exist: " . $e->getMessage() . "\n";
}

echo "\nChecking dim_products structure:\n";
try {
    $cols = $pdo->query('DESCRIBE dim_products')->fetchAll(PDO::FETCH_ASSOC);
    foreach ($cols as $col) {
        echo "  {$col['Field']}: {$col['Type']}\n";
    }
} catch (Exception $e) {
    echo "  Table does not exist: " . $e->getMessage() . "\n";
}

echo "\nChecking inventory_data structure:\n";
try {
    $cols = $pdo->query('DESCRIBE inventory_data')->fetchAll(PDO::FETCH_ASSOC);
    foreach ($cols as $col) {
        echo "  {$col['Field']}: {$col['Type']}\n";
    }
} catch (Exception $e) {
    echo "  Table does not exist: " . $e->getMessage() . "\n";
}
