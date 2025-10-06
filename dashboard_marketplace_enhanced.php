<?php
header('Content-Type: text/html; charset=utf-8');

// Подключение к БД - используем те же параметры что и в Python импортере
$host = 'localhost';
$dbname = 'mi_core_db';

// Попробуем разные варианты подключения
$connection_attempts = [
    ['username' => 'v_admin', 'password' => 'Qwerty123!'],
    ['username' => 'root', 'password' => 'Qwerty123!'],
    ['username' => 'mi_core_user', 'password' => 'Qwerty123!'],
];

$pdo = null;
$connection_error = '';

foreach ($connection_attempts as $attempt) {
    try {
        $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8", $attempt['username'], $attempt['password']);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        break; // Успешное подключение
    } catch(PDOException $e) {
        $connection_error = $e->getMessage();
        continue; // Пробуем следующий вариант
    }
}

if (!$pdo) {
    die("Ошибка подключения к БД: " . $connection_error . "<br><br>Проверьте параметры подключения в config.py");
}

try {
    
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
    
    // Получаем последние остатки
    $stmt = $pdo->query("
        SELECT i.*, p.name as product_name
        FROM inventory i
        LEFT JOIN dim_products p ON i.product_id = p.id
        WHERE i.source = 'Ozon' AND i.quantity_present > 0
        ORDER BY i.quantity_present DESC
        LIMIT 20
    ");
    $inventory = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Получаем товары с нулевыми остатками
    $stmt = $pdo->query("
        SELECT i.*, p.name as product_name
        FROM inventory i
        LEFT JOIN dim_products p ON i.product_id = p.id
        WHERE i.source = 'Ozon' AND i.quantity_present = 0
        ORDER BY i.quantity_reserved DESC
        LIMIT 10
    ");
    $zero_inventory = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
} catch(PDOException $e) {
    die("Ошибка подключения к БД: " . $e->getMessage());
}
?>

<!DOCTYPE html>
<html>
<head>
    <title>Дашборд остатков Ozon</title>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <style>
        body { 
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; 
            margin: 0; 
            padding: 20px; 
            background-color: #f5f5f5;
        }
        .container { max-width: 1200px; margin: 0 auto; }
        .header { 
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white; 
            padding: 20px; 
            border-radius: 10px; 
            margin-bottom: 20px;
            text-align: center;
        }
        .stats { 
            background: white; 
            padding: 20px; 
            margin: 10px 0; 
            border-radius: 10px; 
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 15px;
            margin-top: 15px;
        }
        .stat-card {
            background: #f8f9fa;
            padding: 15px;
            border-radius: 8px;
            text-align: center;
            border-left: 4px solid #4CAF50;
        }
        .stat-number {
            font-size: 2em;
            font-weight: bold;
            color: #4CAF50;
        }
        .stat-label {
            color: #666;
            font-size: 0.9em;
            margin-top: 5px;
        }
        table { 
            border-collapse: collapse; 
            width: 100%; 
            background: white;
            border-radius: 10px;
            overflow: hidden;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }
        th, td { 
            border: 1px solid #ddd; 
            padding: 12px 8px; 
            text-align: left; 
        }
        th { 
            background: linear-gradient(135deg, #4CAF50 0%, #45a049 100%);
            color: white; 
            font-weight: 600;
        }
        tr:nth-child(even) { background-color: #f9f9f9; }
        tr:hover { background-color: #f5f5f5; }
        .success { color: #4CAF50; font-weight: bold; }
        .warning { color: #ff9800; font-weight: bold; }
        .danger { color: #f44336; font-weight: bold; }
        .section { margin: 20px 0; }
        .section h2 {
            color: #333;
            border-bottom: 2px solid #4CAF50;
            padding-bottom: 10px;
        }
        .refresh-info {
            text-align: center;
            color: #666;
            font-style: italic;
            margin-top: 20px;
        }
        .status-badge {
            padding: 4px 8px;
            border-radius: 4px;
            font-size: 0.8em;
            font-weight: bold;
        }
        .status-available { background: #d4edda; color: #155724; }
        .status-reserved { background: #fff3cd; color: #856404; }
        .status-empty { background: #f8d7da; color: #721c24; }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>🚀 Дашборд остатков Ozon</h1>
            <p>Мониторинг товарных остатков в реальном времени</p>
        </div>
        
        <?php if (!empty($stats)): ?>
            <div class="stats">
                <h2>📊 Общая статистика</h2>
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
                    <?php endforeach; ?>
                </div>
            </div>
        <?php else: ?>
            <div class="stats">
                <h2>⚠️ Нет данных</h2>
                <p>Остатки Ozon не найдены. Проверьте работу импортера.</p>
            </div>
        <?php endif; ?>
        
        <div class="section">
            <h2>📦 Топ-20 товаров с остатками</h2>
            <?php if (!empty($inventory)): ?>
                <table>
                    <tr>
                        <th>ID</th>
                        <th>Название товара</th>
                        <th>Склад</th>
                        <th>Тип</th>
                        <th>Доступно</th>
                        <th>Зарезервировано</th>
                        <th>Статус</th>
                    </tr>
                    <?php foreach ($inventory as $item): ?>
                    <tr>
                        <td><?= $item['product_id'] ?></td>
                        <td><?= htmlspecialchars($item['product_name'] ?? 'Товар #' . $item['product_id']) ?></td>
                        <td><?= htmlspecialchars($item['warehouse_name']) ?></td>
                        <td><span class="status-badge status-available"><?= htmlspecialchars($item['stock_type']) ?></span></td>
                        <td><strong class="success"><?= number_format($item['quantity_present']) ?></strong></td>
                        <td class="warning"><?= number_format($item['quantity_reserved']) ?></td>
                        <td>
                            <?php if ($item['quantity_present'] > 10): ?>
                                <span class="status-badge status-available">В наличии</span>
                            <?php elseif ($item['quantity_present'] > 0): ?>
                                <span class="status-badge status-reserved">Мало</span>
                            <?php else: ?>
                                <span class="status-badge status-empty">Нет</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </table>
            <?php else: ?>
                <p>Нет товаров с остатками</p>
            <?php endif; ?>
        </div>

        <?php if (!empty($zero_inventory)): ?>
        <div class="section">
            <h2>⚠️ Товары с нулевыми остатками (но есть резерв)</h2>
            <table>
                <tr>
                    <th>ID</th>
                    <th>Название товара</th>
                    <th>Склад</th>
                    <th>Зарезервировано</th>
                </tr>
                <?php foreach ($zero_inventory as $item): ?>
                <tr>
                    <td><?= $item['product_id'] ?></td>
                    <td><?= htmlspecialchars($item['product_name'] ?? 'Товар #' . $item['product_id']) ?></td>
                    <td><?= htmlspecialchars($item['warehouse_name']) ?></td>
                    <td class="danger"><?= number_format($item['quantity_reserved']) ?></td>
                </tr>
                <?php endforeach; ?>
            </table>
        </div>
        <?php endif; ?>
        
        <div class="refresh-info">
            <p>📅 Последнее обновление: <?= date('d.m.Y H:i:s') ?></p>
            <p>🔄 Данные обновляются автоматически при запуске импортера остатков</p>
        </div>
    </div>
</body>
</html>