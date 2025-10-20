#!/bin/bash

echo "🔍 ПРОВЕРКА ДОСТУПА К MYSQL"

echo "1️⃣ Проверка Python импортера (он же работал!):"
cd /var/www/mi_core_api
python3 -c "
import sys
sys.path.append('.')
try:
    from ozon_importer import connect_to_db
    conn = connect_to_db()
    cursor = conn.cursor()
    cursor.execute('SELECT COUNT(*) FROM inventory WHERE source=\"Ozon\"')
    result = cursor.fetchone()
    print(f'✅ Python подключение работает! Записей Ozon: {result[0]}')
    cursor.close()
    conn.close()
except Exception as e:
    print(f'❌ Python ошибка: {e}')
"

echo ""
echo "2️⃣ Проверка config.py:"
python3 -c "
import sys
sys.path.append('.')
import config
print(f'DB_HOST: {config.DB_HOST}')
print(f'DB_USER: {config.DB_USER}')
print(f'DB_NAME: {config.DB_NAME}')
print(f'DB_PORT: {config.DB_PORT}')
"

echo ""
echo "3️⃣ Создание дашборда с теми же параметрами что у Python:"
sudo tee /var/www/html/dashboard_marketplace_enhanced.php << 'EOF'
<?php
header('Content-Type: text/html; charset=utf-8');

// Используем те же параметры что и Python импортер
$host = 'localhost';
$dbname = 'mi_core_db';

// Читаем .env файл как Python
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

$username = $env_vars['DB_USER'] ?? 'root';
$password = $env_vars['DB_PASSWORD'] ?? '';

try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8", $username, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    $stmt = $pdo->query("SELECT COUNT(*) as count FROM inventory WHERE source = 'Ozon'");
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    
    echo "<h1>✅ ДАШБОРД РАБОТАЕТ!</h1>";
    echo "<p><strong>Подключение:</strong> {$username}@{$host}</p>";
    echo "<p><strong>Записей Ozon:</strong> {$result['count']}</p>";
    echo "<p><strong>Время:</strong> " . date('Y-m-d H:i:s') . "</p>";
    
} catch(PDOException $e) {
    echo "<h1>❌ Ошибка БД</h1>";
    echo "<p>User: $username</p>";
    echo "<p>Error: " . $e->getMessage() . "</p>";
}
?>
EOF

sudo chown www-data:www-data /var/www/html/dashboard_marketplace_enhanced.php

echo "✅ Готово! Проверьте дашборд"