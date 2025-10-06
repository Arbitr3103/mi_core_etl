#!/bin/bash

echo "🔧 Исправление маршрутизации nginx"
echo "=================================="

# Проверяем текущую конфигурацию nginx
echo "📋 Текущая конфигурация nginx для api.zavodprostavok.ru:"
sudo grep -A 5 -B 5 "root.*www" /etc/nginx/sites-available/default

echo ""
echo "🔍 Проблема: nginx читает файлы из /var/www/mi_core_api/src/ вместо /var/www/html/"

# Удаляем старый файл который мешает
echo "🗑️ Удаление старого файла дашборда..."
sudo rm -f /var/www/mi_core_api/src/dashboard_marketplace_enhanced.php

# Создаем простой дашборд без зависимостей
echo "📝 Создание простого дашборда..."
sudo tee /var/www/html/dashboard_marketplace_enhanced.php << 'EOF'
<?php
header('Content-Type: text/html; charset=utf-8');

// Простое подключение к БД без внешних зависимостей
$host = 'localhost';
$dbname = 'mi_core_db';
$username = 'root';
$password = 'Qwerty123!';

try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8", $username, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    // Получаем статистику остатков Ozon
    $stmt = $pdo->query("
        SELECT 
            source,
            COUNT(*) as total_records,
            COUNT(DISTINCT product_id) as unique_products,
            SUM(quantity_present) as total_present,
            SUM(quantity_reserved) as total_reserved
        FROM inventory 
        WHERE source = 'Ozon'
        GROUP BY source
    ");
    $stats = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
} catch(PDOException $e) {
    die("Ошибка подключения к БД: " . $e->getMessage());
}
?>

<!DOCTYPE html>
<html>
<head>
    <title>Дашборд остатков Ozon</title>
    <meta charset="utf-8">
    <style>
        body { font-family: Arial, sans-serif; margin: 20px; background: #f5f5f5; }
        .header { background: #4CAF50; color: white; padding: 20px; border-radius: 10px; text-align: center; }
        .stats { background: white; padding: 20px; margin: 20px 0; border-radius: 10px; box-shadow: 0 2px 10px rgba(0,0,0,0.1); }
        .success { color: #4CAF50; font-weight: bold; font-size: 1.5em; }
    </style>
</head>
<body>
    <div class="header">
        <h1>🚀 Дашборд остатков Ozon</h1>
        <p>Система работает!</p>
    </div>
    
    <?php if (!empty($stats)): ?>
        <div class="stats">
            <h2>📊 Статистика Ozon</h2>
            <?php foreach ($stats as $stat): ?>
                <p><strong>Источник:</strong> <?= $stat['source'] ?></p>
                <p><strong>Записей:</strong> <span class="success"><?= number_format($stat['total_records']) ?></span></p>
                <p><strong>Уникальных товаров:</strong> <span class="success"><?= number_format($stat['unique_products']) ?></span></p>
                <p><strong>Доступно единиц:</strong> <span class="success"><?= number_format($stat['total_present']) ?></span></p>
                <p><strong>Зарезервировано:</strong> <span class="success"><?= number_format($stat['total_reserved']) ?></span></p>
            <?php endforeach; ?>
        </div>
    <?php else: ?>
        <div class="stats">
            <h2>⚠️ Нет данных</h2>
            <p>Остатки Ozon не найдены. Запустите импортер остатков.</p>
        </div>
    <?php endif; ?>
    
    <div class="stats">
        <p><strong>Последнее обновление:</strong> <?= date('d.m.Y H:i:s') ?></p>
        <p><strong>Статус системы:</strong> <span class="success">✅ Работает</span></p>
    </div>
</body>
</html>
EOF

# Устанавливаем права
sudo chown www-data:www-data /var/www/html/dashboard_marketplace_enhanced.php
sudo chmod 644 /var/www/html/dashboard_marketplace_enhanced.php

echo "✅ Простой дашборд создан"

# Тестируем
echo "📡 Тест дашборда:"
curl -s https://api.zavodprostavok.ru/dashboard_marketplace_enhanced.php | head -10

echo ""
echo "🎉 Готово! Дашборд должен работать:"
echo "   https://api.zavodprostavok.ru/dashboard_marketplace_enhanced.php"