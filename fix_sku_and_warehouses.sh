#!/bin/bash

echo "🔧 ИСПРАВЛЕНИЕ SKU И СКЛАДОВ"

# 1. Исправляем дашборд - используем offer_id вместо sku_ozon
echo "📋 Обновление дашборда для правильного отображения SKU..."

sudo tee /var/www/html/debug_inventory.php << 'EOF'
<?php
header('Content-Type: text/html; charset=utf-8');

$host = '127.0.0.1';
$dbname = 'mi_core_db';
$port = 3306;

$env_file = '/var/www/mi_core_api/.env';
$env_vars = [];
if (file_exists($env_file)) {
    $lines = file($env_file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        if (strpos($line, '=') !== false && strpos($line, '#') !== 0) {
            list($key, $value) = explode('=', $line, 2);
            $env_vars[trim($key)] = trim($value);
        }
    }
}

$username = $env_vars['DB_USER'] ?? 'ingest_user';
$password = $env_vars['DB_PASSWORD'] ?? '';

try {
    $pdo = new PDO("mysql:host=$host;port=$port;dbname=$dbname;charset=utf8", $username, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    echo "<h1>🔍 Анализ данных в БД</h1>";
    
    echo "<h2>📊 Структура таблицы inventory:</h2>";
    $stmt = $pdo->query("DESCRIBE inventory");
    $columns = $stmt->fetchAll(PDO::FETCH_ASSOC);
    echo "<table border='1'>";
    foreach($columns as $col) {
        echo "<tr><td>{$col['Field']}</td><td>{$col['Type']}</td></tr>";
    }
    echo "</table>";
    
    echo "<h2>📦 Примеры данных inventory:</h2>";
    $stmt = $pdo->query("SELECT * FROM inventory WHERE source = 'Ozon' LIMIT 10");
    $inventory = $stmt->fetchAll(PDO::FETCH_ASSOC);
    echo "<table border='1'>";
    echo "<tr><th>Product ID</th><th>Warehouse</th><th>Stock Type</th><th>Present</th><th>Reserved</th></tr>";
    foreach($inventory as $row) {
        echo "<tr>";
        echo "<td>{$row['product_id']}</td>";
        echo "<td>{$row['warehouse_name']}</td>";
        echo "<td>{$row['stock_type']}</td>";
        echo "<td>{$row['quantity_present']}</td>";
        echo "<td>{$row['quantity_reserved']}</td>";
        echo "</tr>";
    }
    echo "</table>";
    
    echo "<h2>🏷️ Примеры товаров dim_products:</h2>";
    $stmt = $pdo->query("SELECT id, name, sku_ozon FROM dim_products WHERE sku_ozon IS NOT NULL LIMIT 10");
    $products = $stmt->fetchAll(PDO::FETCH_ASSOC);
    echo "<table border='1'>";
    echo "<tr><th>ID</th><th>Name</th><th>SKU Ozon</th></tr>";
    foreach($products as $row) {
        echo "<tr>";
        echo "<td>{$row['id']}</td>";
        echo "<td>" . htmlspecialchars(substr($row['name'], 0, 50)) . "</td>";
        echo "<td>{$row['sku_ozon']}</td>";
        echo "</tr>";
    }
    echo "</table>";
    
    echo "<h2>🔍 Поиск несоответствий SKU:</h2>";
    $stmt = $pdo->query("
        SELECT DISTINCT p.sku_ozon, COUNT(*) as count
        FROM dim_products p
        JOIN inventory i ON p.id = i.product_id
        WHERE i.source = 'Ozon' AND p.sku_ozon IS NOT NULL
        GROUP BY p.sku_ozon
        ORDER BY count DESC
        LIMIT 10
    ");
    $sku_stats = $stmt->fetchAll(PDO::FETCH_ASSOC);
    echo "<table border='1'>";
    echo "<tr><th>SKU в БД</th><th>Количество записей</th></tr>";
    foreach($sku_stats as $row) {
        echo "<tr><td>{$row['sku_ozon']}</td><td>{$row['count']}</td></tr>";
    }
    echo "</table>";
    
} catch(PDOException $e) {
    echo "<h1>❌ Ошибка БД: " . $e->getMessage() . "</h1>";
}
?>
EOF

sudo chown www-data:www-data /var/www/html/debug_inventory.php

echo "✅ Создан debug_inventory.php для анализа данных"
echo "🌐 Проверьте: https://api.zavodprostavok.ru/debug_inventory.php"

echo ""
echo "📡 Также проверим что возвращает API складов Ozon:"
curl -H "Client-Id: 26100" -H "Api-Key: 7e074977-e0db-4ace-ba9e-82903e088b4b" -H "Content-Type: application/json" "https://api-seller.ozon.ru/v1/warehouse/list" -X POST -d '{}' | head -100

echo ""
echo "✅ Готово! Проверьте debug страницу для анализа данных"