#!/bin/bash

echo "🎨 УЛУЧШЕНИЕ ДАШБОРДА"

sudo tee /var/www/html/dashboard_marketplace_enhanced.php << 'EOF'
<?php
header('Content-Type: text/html; charset=utf-8');

// Подключение к БД
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
    
    // Общая статистика
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
    
    // Топ товаров с остатками
    $stmt = $pdo->query("
        SELECT 
            i.product_id,
            i.warehouse_name,
            i.stock_type,
            i.quantity_present,
            i.quantity_reserved,
            p.name as product_name
        FROM inventory i
        LEFT JOIN dim_products p ON i.product_id = p.id
        WHERE i.source = 'Ozon' AND i.quantity_present > 0
        ORDER BY i.quantity_present DESC
        LIMIT 15
    ");
    $top_products = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Товары с наименьшими остатками (но не 0)
    $stmt = $pdo->query("
        SELECT 
            i.product_id,
            i.warehouse_name,
            i.stock_type,
            i.quantity_present,
            i.quantity_reserved,
            p.name as product_name
        FROM inventory i
        LEFT JOIN dim_products p ON i.product_id = p.id
        WHERE i.source = 'Ozon' AND i.quantity_present > 0 AND i.quantity_present <= 10
        ORDER BY i.quantity_present ASC
        LIMIT 15
    ");
    $low_stock = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Товары с нулевыми остатками
    $stmt = $pdo->query("
        SELECT 
            i.product_id,
            i.warehouse_name,
            i.stock_type,
            i.quantity_present,
            i.quantity_reserved,
            p.name as product_name
        FROM inventory i
        LEFT JOIN dim_products p ON i.product_id = p.id
        WHERE i.source = 'Ozon' AND i.quantity_present = 0
        ORDER BY i.quantity_reserved DESC
        LIMIT 15
    ");
    $zero_stock_products = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Подсчет нулевых остатков
    $stmt = $pdo->query("
        SELECT COUNT(*) as zero_stock_count
        FROM inventory 
        WHERE source = 'Ozon' AND quantity_present = 0
    ");
    $zero_stock_count = $stmt->fetch(PDO::FETCH_ASSOC);
    
} catch(PDOException $e) {
    die('<h1>❌ Ошибка БД: ' . $e->getMessage() . '</h1>');
}
?>

<!DOCTYPE html>
<html>
<head>
    <title>🚀 Дашборд остатков Ozon</title>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <style>
        body { 
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; 
            margin: 0; 
            padding: 20px; 
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
        }
        .container { max-width: 1400px; margin: 0 auto; }
        .header { 
            background: rgba(255,255,255,0.95);
            color: #333; 
            padding: 30px; 
            border-radius: 15px; 
            margin-bottom: 20px;
            text-align: center;
            box-shadow: 0 8px 32px rgba(0,0,0,0.1);
        }
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 15px;
            margin-bottom: 30px;
        }
        .stat-card {
            background: rgba(255,255,255,0.95);
            padding: 20px;
            border-radius: 15px;
            text-align: center;
            box-shadow: 0 8px 32px rgba(0,0,0,0.1);
            border-left: 5px solid #4CAF50;
        }
        .stat-number {
            font-size: 2.2em;
            font-weight: bold;
            color: #4CAF50;
            margin-bottom: 8px;
        }
        .stat-label {
            color: #666;
            font-size: 0.95em;
        }
        .products-section {
            background: rgba(255,255,255,0.95);
            padding: 25px;
            border-radius: 15px;
            box-shadow: 0 8px 32px rgba(0,0,0,0.1);
            margin-bottom: 20px;
        }
        .sections-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(450px, 1fr));
            gap: 20px;
        }
        table { 
            width: 100%; 
            border-collapse: collapse; 
            margin-top: 15px;
            font-size: 0.9em;
        }
        th, td { 
            padding: 10px 8px; 
            text-align: left; 
            border-bottom: 1px solid #ddd;
        }
        th { 
            background: #4CAF50; 
            color: white; 
            font-weight: 600;
            font-size: 0.85em;
        }
        tr:hover { background-color: #f5f5f5; }
        .success { color: #4CAF50; font-weight: bold; }
        .warning { color: #ff9800; font-weight: bold; }
        .danger { color: #f44336; font-weight: bold; }
        .info { 
            background: rgba(33, 150, 243, 0.1); 
            padding: 15px; 
            border-radius: 10px; 
            margin: 20px 0;
            border-left: 4px solid #2196F3;
        }
        .refresh-btn {
            background: #4CAF50;
            color: white;
            padding: 12px 24px;
            border: none;
            border-radius: 8px;
            cursor: pointer;
            font-size: 16px;
            transition: background 0.3s;
        }
        .refresh-btn:hover { background: #45a049; }
        .section-title {
            color: #333;
            margin-bottom: 15px;
            font-size: 1.3em;
        }
        .zero-stock { background-color: #ffebee !important; }
        .low-stock { background-color: #fff3e0 !important; }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>🚀 Дашборд остатков Ozon</h1>
            <p>Система мониторинга товарных остатков</p>
            <button class="refresh-btn" onclick="window.location.reload()">🔄 Обновить данные</button>
        </div>
        
        <div class="info">
            <strong>📊 Подключение:</strong> <?= $username ?>@<?= $host ?>:<?= $port ?> | 
            <strong>🕒 Обновлено:</strong> <?= date('d.m.Y H:i:s') ?>
        </div>
        
        <?php if (!empty($stats)): ?>
            <div class="stats-grid">
                <?php foreach ($stats as $stat): ?>
                    <div class="stat-card">
                        <div class="stat-number"><?= number_format($stat['total_records']) ?></div>
                        <div class="stat-label">Всего записей</div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-number"><?= number_format($stat['unique_products']) ?></div>
                        <div class="stat-label">Уникальных товаров</div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-number success"><?= number_format($stat['total_present']) ?></div>
                        <div class="stat-label">Доступно единиц</div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-number warning"><?= number_format($stat['total_reserved']) ?></div>
                        <div class="stat-label">Зарезервировано</div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-number danger"><?= number_format($zero_stock_count['zero_stock_count']) ?></div>
                        <div class="stat-label">Нулевые остатки</div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
        
        <div class="sections-grid">
            <!-- Топ товары -->
            <?php if (!empty($top_products)): ?>
                <div class="products-section">
                    <h2 class="section-title">📦 Топ-15 товаров с наибольшими остатками</h2>
                    <table>
                        <tr>
                            <th>ID</th>
                            <th>Название</th>
                            <th>Склад</th>
                            <th>Доступно</th>
                            <th>Резерв</th>
                        </tr>
                        <?php foreach ($top_products as $product): ?>
                        <tr>
                            <td><?= $product['product_id'] ?></td>
                            <td><?= htmlspecialchars(mb_substr($product['product_name'] ?? 'Товар #' . $product['product_id'], 0, 40)) ?></td>
                            <td><?= htmlspecialchars($product['warehouse_name']) ?></td>
                            <td><strong class="success"><?= number_format($product['quantity_present']) ?></strong></td>
                            <td class="warning"><?= number_format($product['quantity_reserved']) ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </table>
                </div>
            <?php endif; ?>
            
            <!-- Товары с низкими остатками -->
            <?php if (!empty($low_stock)): ?>
                <div class="products-section">
                    <h2 class="section-title">⚠️ Товары с низкими остатками (≤10)</h2>
                    <table>
                        <tr>
                            <th>ID</th>
                            <th>Название</th>
                            <th>Склад</th>
                            <th>Доступно</th>
                            <th>Резерв</th>
                        </tr>
                        <?php foreach ($low_stock as $product): ?>
                        <tr class="low-stock">
                            <td><?= $product['product_id'] ?></td>
                            <td><?= htmlspecialchars(mb_substr($product['product_name'] ?? 'Товар #' . $product['product_id'], 0, 40)) ?></td>
                            <td><?= htmlspecialchars($product['warehouse_name']) ?></td>
                            <td><strong class="warning"><?= number_format($product['quantity_present']) ?></strong></td>
                            <td class="warning"><?= number_format($product['quantity_reserved']) ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </table>
                </div>
            <?php endif; ?>
        </div>
        
        <!-- Товары с нулевыми остатками -->
        <?php if (!empty($zero_stock_products)): ?>
            <div class="products-section">
                <h2 class="section-title">❌ Товары с нулевыми остатками</h2>
                <table>
                    <tr>
                        <th>ID товара</th>
                        <th>Название</th>
                        <th>Склад</th>
                        <th>Доступно</th>
                        <th>Зарезервировано</th>
                    </tr>
                    <?php foreach ($zero_stock_products as $product): ?>
                    <tr class="zero-stock">
                        <td><?= $product['product_id'] ?></td>
                        <td><?= htmlspecialchars(mb_substr($product['product_name'] ?? 'Товар #' . $product['product_id'], 0, 50)) ?></td>
                        <td><?= htmlspecialchars($product['warehouse_name']) ?></td>
                        <td><strong class="danger">0</strong></td>
                        <td class="warning"><?= number_format($product['quantity_reserved']) ?></td>
                    </tr>
                    <?php endforeach; ?>
                </table>
            </div>
        <?php endif; ?>
        
        <div class="info">
            <p><strong>🔄 Автообновление:</strong> Данные обновляются при запуске импортера остатков</p>
            <p><strong>📈 Статус системы:</strong> <span class="success">✅ Работает нормально</span></p>
            <p><strong>💡 Совет:</strong> Товары с низкими остатками требуют пополнения</p>
        </div>
    </div>
</body>
</html>
EOF

sudo chown www-data:www-data /var/www/html/dashboard_marketplace_enhanced.php

echo "✅ Дашборд улучшен!"
echo "🌐 https://api.zavodprostavok.ru/dashboard_marketplace_enhanced.php"