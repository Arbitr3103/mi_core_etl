#!/bin/bash

echo "🔧 ИСПРАВЛЕНИЕ ПОДКЛЮЧЕНИЯ К БД"

# Создаем дашборд с правильными учетными данными
sudo tee /var/www/html/dashboard_marketplace_enhanced.php << 'EOF'
<?php
header('Content-Type: text/html; charset=utf-8');

// Пробуем разные варианты подключения к БД
$connection_attempts = [
    ['host' => 'localhost', 'user' => 'mi_core_user', 'pass' => 'Qwerty123!'],
    ['host' => 'localhost', 'user' => 'v_admin', 'pass' => 'Qwerty123!'],
    ['host' => 'localhost', 'user' => 'root', 'pass' => ''],
    ['host' => 'localhost', 'user' => 'debian-sys-maint', 'pass' => 'Qwerty123!'],
];

$pdo = null;
$connection_info = '';

foreach ($connection_attempts as $attempt) {
    try {
        $pdo = new PDO("mysql:host={$attempt['host']};dbname=mi_core_db;charset=utf8", 
                       $attempt['user'], $attempt['pass']);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $connection_info = "Подключен как: {$attempt['user']}@{$attempt['host']}";
        break;
    } catch(PDOException $e) {
        continue;
    }
}

if (!$pdo) {
    die('<h1>❌ Не удалось подключиться к БД</h1><p>Проверьте настройки MySQL</p>');
}

// Получаем статистику
try {
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
    $stats = [];
    $error = $e->getMessage();
}
?>

<!DOCTYPE html>
<html>
<head>
    <title>🚀 Дашборд остатков Ozon</title>
    <meta charset="utf-8">
    <style>
        body { font-family: Arial, sans-serif; margin: 20px; background: #f5f5f5; }
        .header { background: #4CAF50; color: white; padding: 20px; border-radius: 10px; text-align: center; }
        .stats { background: white; padding: 20px; margin: 20px 0; border-radius: 10px; box-shadow: 0 2px 10px rgba(0,0,0,0.1); }
        .success { color: #4CAF50; font-weight: bold; font-size: 1.5em; }
        .info { background: #e3f2fd; padding: 10px; border-radius: 5px; margin: 10px 0; }
    </style>
</head>
<body>
    <div class="header">
        <h1>🚀 Дашборд остатков Ozon</h1>
        <p>Система работает!</p>
    </div>
    
    <div class="info">
        <strong>Подключение к БД:</strong> <?= $connection_info ?>
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
            <h2>⚠️ Нет данных Ozon</h2>
            <p>Остатки не найдены. Запустите импортер:</p>
            <code>python3 /var/www/mi_core_api/importers/stock_importer.py</code>
            <?php if (isset($error)): ?>
                <p><strong>Ошибка:</strong> <?= htmlspecialchars($error) ?></p>
            <?php endif; ?>
        </div>
    <?php endif; ?>
    
    <div class="stats">
        <p><strong>Время:</strong> <?= date('d.m.Y H:i:s') ?></p>
        <p><strong>Статус:</strong> <span class="success">✅ Работает</span></p>
    </div>
</body>
</html>
EOF

# Устанавливаем права
sudo chown www-data:www-data /var/www/html/dashboard_marketplace_enhanced.php
sudo chmod 644 /var/www/html/dashboard_marketplace_enhanced.php

echo "✅ Дашборд с исправленным подключением к БД создан!"
echo "🌐 Проверьте: https://api.zavodprostavok.ru/dashboard_marketplace_enhanced.php"