#!/bin/bash

echo "🔧 ИСПРАВЛЕНИЕ ДАШБОРДА"

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
    
    // Получаем активную вкладку
    $active_tab = $_GET['tab'] ?? 'warehouse_products';
    
    // Фильтры
    $search = $_GET['search'] ?? '';
    $warehouse_filter = $_GET['warehouse'] ?? '';
    $stock_filter = $_GET['stock_status'] ?? '';
    
    // Общая статистика
    $stmt = $pdo->query("
        SELECT 
            COUNT(*) as total_records,
            COUNT(DISTINCT product_id) as unique_products,
            SUM(quantity_present) as total_present,
            SUM(quantity_reserved) as total_reserved,
            COUNT(DISTINCT warehouse_name) as warehouses_count
        FROM inventory 
        WHERE source = 'Ozon'
    ");
    $stats = $stmt->fetch(PDO::FETCH_ASSOC);
    
    // Данные для разных вкладок
    switch($active_tab) {
        case 'warehouse_products':
            $query = "
                SELECT 
                    i.product_id,
                    p.name as product_name,
                    p.sku_ozon,
                    i.warehouse_name,
                    i.stock_type,
                    i.quantity_present,
                    i.quantity_reserved,
                    (i.quantity_present + i.quantity_reserved) as total_quantity,
                    CASE 
                        WHEN i.quantity_present = 0 THEN 'Нет в наличии'
                        WHEN i.quantity_present <= 5 THEN 'Критически мало'
                        WHEN i.quantity_present <= 20 THEN 'Мало'
                        ELSE 'В наличии'
                    END as stock_status,
                    i.updated_at
                FROM inventory i
                LEFT JOIN dim_products p ON i.product_id = p.id
                WHERE i.source = 'Ozon'
            ";
            break;
            
        case 'products':
            $query = "
                SELECT 
                    i.product_id,
                    p.name as product_name,
                    p.sku_ozon,
                    COUNT(DISTINCT i.warehouse_name) as warehouses_count,
                    SUM(i.quantity_present) as total_present,
                    SUM(i.quantity_reserved) as total_reserved,
                    SUM(i.quantity_present + i.quantity_reserved) as total_quantity,
                    AVG(i.quantity_present) as avg_stock,
                    MAX(i.updated_at) as last_updated
                FROM inventory i
                LEFT JOIN dim_products p ON i.product_id = p.id
                WHERE i.source = 'Ozon'
                GROUP BY i.product_id, p.name, p.sku_ozon
            ";
            break;
            
        case 'warehouses':
            $query = "
                SELECT 
                    i.warehouse_name,
                    i.stock_type,
                    COUNT(DISTINCT i.product_id) as products_count,
                    SUM(i.quantity_present) as total_present,
                    SUM(i.quantity_reserved) as total_reserved,
                    SUM(i.quantity_present + i.quantity_reserved) as total_quantity,
                    AVG(i.quantity_present) as avg_stock_per_product,
                    COUNT(CASE WHEN i.quantity_present = 0 THEN 1 END) as zero_stock_count,
                    MAX(i.updated_at) as last_updated
                FROM inventory i
                WHERE i.source = 'Ozon'
                GROUP BY i.warehouse_name, i.stock_type
            ";
            break;
    }
    
    // Применяем фильтры
    $where_conditions = [];
    $params = [];
    
    if ($search) {
        // ИСПРАВЛЕНО: Добавлен поиск по SKU
        $where_conditions[] = "(p.name LIKE ? OR p.sku_ozon LIKE ? OR i.product_id LIKE ? OR CAST(i.product_id AS CHAR) LIKE ?)";
        $params[] = "%$search%";
        $params[] = "%$search%";
        $params[] = "%$search%";
        $params[] = "%$search%";
    }
    
    if ($warehouse_filter) {
        $where_conditions[] = "i.warehouse_name = ?";
        $params[] = $warehouse_filter;
    }
    
    if ($stock_filter) {
        switch($stock_filter) {
            case 'in_stock':
                $where_conditions[] = "i.quantity_present > 0";
                break;
            case 'low_stock':
                $where_conditions[] = "i.quantity_present > 0 AND i.quantity_present <= 20";
                break;
            case 'out_of_stock':
                $where_conditions[] = "i.quantity_present = 0";
                break;
        }
    }
    
    if ($where_conditions) {
        $query .= " AND " . implode(" AND ", $where_conditions);
    }
    
    $query .= " ORDER BY ";
    switch($active_tab) {
        case 'warehouse_products':
            $query .= "i.warehouse_name, i.quantity_present DESC";
            break;
        case 'products':
            $query .= "total_present DESC";
            break;
        case 'warehouses':
            $query .= "total_present DESC";
            break;
    }
    
    $query .= " LIMIT 100";
    
    $stmt = $pdo->prepare($query);
    $stmt->execute($params);
    $data = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Получаем список складов для фильтра
    $warehouses_stmt = $pdo->query("SELECT DISTINCT warehouse_name FROM inventory WHERE source = 'Ozon' ORDER BY warehouse_name");
    $warehouses = $warehouses_stmt->fetchAll(PDO::FETCH_COLUMN);
    
} catch(PDOException $e) {
    die('<h1>❌ Ошибка БД: ' . $e->getMessage() . '</h1>');
}

// Функция для красивого отображения ID + SKU
function formatProductId($id, $sku) {
    if ($sku && $sku !== '-') {
        return "<div><strong>ID:</strong> $id<br><small style='color: #6c757d;'>SKU: $sku</small></div>";
    }
    return "<div><strong>ID:</strong> $id<br><small style='color: #6c757d;'>SKU: не указан</small></div>";
}

// Функция для красивого отображения склада
function formatWarehouse($warehouse_name) {
    // Убираем префикс "Ozon-" если есть
    $clean_name = str_replace(['Ozon-FBO-', 'Ozon-FBS-', 'Ozon-'], '', $warehouse_name);
    
    // Если это просто FBO/FBS, показываем как есть
    if (in_array($clean_name, ['FBO', 'FBS'])) {
        return "<span class='warehouse-badge warehouse-$clean_name'>$clean_name</span>";
    }
    
    // Иначе показываем как город/регион
    return "<span class='warehouse-badge warehouse-city'>$clean_name</span>";
}
?>

<!DOCTYPE html>
<html>
<head>
    <title>📊 Дашборд остатков Ozon - Профессиональная версия</title>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        
        body { 
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: #f8f9fa;
            color: #333;
        }
        
        .header {
            background: white;
            border-bottom: 1px solid #e9ecef;
            padding: 20px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }
        
        .header h1 {
            color: #495057;
            margin-bottom: 10px;
        }
        
        .stats-bar {
            display: flex;
            gap: 30px;
            margin-top: 15px;
        }
        
        .stat-item {
            display: flex;
            flex-direction: column;
        }
        
        .stat-number {
            font-size: 24px;
            font-weight: 600;
            color: #007bff;
        }
        
        .stat-label {
            font-size: 12px;
            color: #6c757d;
            text-transform: uppercase;
        }
        
        .container {
            max-width: 1400px;
            margin: 0 auto;
            padding: 20px;
        }
        
        .controls {
            background: white;
            padding: 20px;
            border-radius: 8px;
            margin-bottom: 20px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }
        
        .tabs {
            display: flex;
            border-bottom: 2px solid #e9ecef;
            margin-bottom: 20px;
        }
        
        .tab {
            padding: 12px 24px;
            background: none;
            border: none;
            cursor: pointer;
            font-size: 14px;
            font-weight: 500;
            color: #6c757d;
            text-decoration: none;
            border-bottom: 3px solid transparent;
            transition: all 0.2s;
        }
        
        .tab.active {
            color: #007bff;
            border-bottom-color: #007bff;
        }
        
        .tab:hover {
            color: #007bff;
            background: #f8f9fa;
        }
        
        .filters {
            display: flex;
            gap: 15px;
            align-items: center;
            flex-wrap: wrap;
        }
        
        .filter-group {
            display: flex;
            flex-direction: column;
            gap: 5px;
        }
        
        .filter-label {
            font-size: 12px;
            color: #6c757d;
            font-weight: 500;
        }
        
        .filter-input, .filter-select {
            padding: 8px 12px;
            border: 1px solid #ced4da;
            border-radius: 4px;
            font-size: 14px;
        }
        
        .filter-input:focus, .filter-select:focus {
            outline: none;
            border-color: #007bff;
            box-shadow: 0 0 0 2px rgba(0,123,255,0.25);
        }
        
        .btn {
            padding: 8px 16px;
            background: #007bff;
            color: white;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            font-size: 14px;
            transition: background 0.2s;
        }
        
        .btn:hover {
            background: #0056b3;
        }
        
        .data-table {
            background: white;
            border-radius: 8px;
            overflow: hidden;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }
        
        table {
            width: 100%;
            border-collapse: collapse;
        }
        
        th {
            background: #f8f9fa;
            padding: 12px;
            text-align: left;
            font-weight: 600;
            font-size: 12px;
            color: #495057;
            border-bottom: 1px solid #dee2e6;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        
        td {
            padding: 12px;
            border-bottom: 1px solid #f1f3f4;
            font-size: 14px;
            vertical-align: top;
        }
        
        tr:hover {
            background: #f8f9fa;
        }
        
        .status-badge {
            padding: 4px 8px;
            border-radius: 12px;
            font-size: 11px;
            font-weight: 500;
            text-transform: uppercase;
        }
        
        .status-in-stock { background: #d4edda; color: #155724; }
        .status-low-stock { background: #fff3cd; color: #856404; }
        .status-critical { background: #f8d7da; color: #721c24; }
        .status-out { background: #f8d7da; color: #721c24; }
        
        .warehouse-badge {
            padding: 4px 8px;
            border-radius: 4px;
            font-size: 11px;
            font-weight: 500;
        }
        
        .warehouse-FBO { background: #e3f2fd; color: #1565c0; }
        .warehouse-FBS { background: #f3e5f5; color: #7b1fa2; }
        .warehouse-city { background: #e8f5e8; color: #2e7d32; }
        
        .number-cell {
            text-align: right;
            font-weight: 500;
        }
        
        .number-positive { color: #28a745; }
        .number-warning { color: #ffc107; }
        .number-danger { color: #dc3545; }
        
        .product-id-cell {
            min-width: 120px;
        }
        
        .refresh-info {
            text-align: center;
            padding: 20px;
            color: #6c757d;
            font-size: 14px;
        }
        
        @media (max-width: 768px) {
            .filters { flex-direction: column; align-items: stretch; }
            .stats-bar { flex-direction: column; gap: 15px; }
            table { font-size: 12px; }
            th, td { padding: 8px; }
        }
    </style>
</head>
<body>
    <div class="header">
        <h1>📊 Дашборд остатков Ozon</h1>
        <div class="stats-bar">
            <div class="stat-item">
                <div class="stat-number"><?= number_format($stats['total_records']) ?></div>
                <div class="stat-label">Всего записей</div>
            </div>
            <div class="stat-item">
                <div class="stat-number"><?= number_format($stats['unique_products']) ?></div>
                <div class="stat-label">Товаров</div>
            </div>
            <div class="stat-item">
                <div class="stat-number"><?= number_format($stats['total_present']) ?></div>
                <div class="stat-label">Доступно</div>
            </div>
            <div class="stat-item">
                <div class="stat-number"><?= number_format($stats['total_reserved']) ?></div>
                <div class="stat-label">Резерв</div>
            </div>
            <div class="stat-item">
                <div class="stat-number"><?= $stats['warehouses_count'] ?></div>
                <div class="stat-label">Складов</div>
            </div>
        </div>
    </div>
    
    <div class="container">
        <div class="controls">
            <div class="tabs">
                <a href="?tab=warehouse_products" class="tab <?= $active_tab === 'warehouse_products' ? 'active' : '' ?>">
                    📦 Товар-склад
                </a>
                <a href="?tab=products" class="tab <?= $active_tab === 'products' ? 'active' : '' ?>">
                    🏷️ Товары
                </a>
                <a href="?tab=warehouses" class="tab <?= $active_tab === 'warehouses' ? 'active' : '' ?>">
                    🏪 Склады
                </a>
            </div>
            
            <form method="GET" class="filters">
                <input type="hidden" name="tab" value="<?= $active_tab ?>">
                
                <div class="filter-group">
                    <label class="filter-label">Поиск по ID, SKU, названию</label>
                    <input type="text" name="search" value="<?= htmlspecialchars($search) ?>" 
                           placeholder="Введите ID, SKU или название..." class="filter-input" style="width: 250px;">
                </div>
                
                <div class="filter-group">
                    <label class="filter-label">Склад</label>
                    <select name="warehouse" class="filter-select">
                        <option value="">Все склады</option>
                        <?php foreach($warehouses as $warehouse): ?>
                            <option value="<?= htmlspecialchars($warehouse) ?>" 
                                    <?= $warehouse_filter === $warehouse ? 'selected' : '' ?>>
                                <?= htmlspecialchars(str_replace(['Ozon-FBO-', 'Ozon-FBS-', 'Ozon-'], '', $warehouse)) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div class="filter-group">
                    <label class="filter-label">Статус остатков</label>
                    <select name="stock_status" class="filter-select">
                        <option value="">Все статусы</option>
                        <option value="in_stock" <?= $stock_filter === 'in_stock' ? 'selected' : '' ?>>В наличии</option>
                        <option value="low_stock" <?= $stock_filter === 'low_stock' ? 'selected' : '' ?>>Мало</option>
                        <option value="out_of_stock" <?= $stock_filter === 'out_of_stock' ? 'selected' : '' ?>>Нет в наличии</option>
                    </select>
                </div>
                
                <button type="submit" class="btn">🔍 Применить</button>
                <a href="?tab=<?= $active_tab ?>" class="btn" style="background: #6c757d; text-decoration: none;">🔄 Сбросить</a>
            </form>
        </div>
        
        <div class="data-table">
            <table>
                <thead>
                    <tr>
                        <?php if($active_tab === 'warehouse_products'): ?>
                            <th>ID товара / SKU</th>
                            <th>Название товара</th>
                            <th>Склад</th>
                            <th>Тип</th>
                            <th>Доступно</th>
                            <th>Резерв</th>
                            <th>Всего</th>
                            <th>Статус</th>
                            <th>Обновлено</th>
                        <?php elseif($active_tab === 'products'): ?>
                            <th>ID товара / SKU</th>
                            <th>Название товара</th>
                            <th>Складов</th>
                            <th>Доступно</th>
                            <th>Резерв</th>
                            <th>Всего</th>
                            <th>Средний остаток</th>
                            <th>Обновлено</th>
                        <?php elseif($active_tab === 'warehouses'): ?>
                            <th>Склад</th>
                            <th>Тип</th>
                            <th>Товаров</th>
                            <th>Доступно</th>
                            <th>Резерв</th>
                            <th>Всего</th>
                            <th>Средний остаток</th>
                            <th>Без остатков</th>
                            <th>Обновлено</th>
                        <?php endif; ?>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach($data as $row): ?>
                        <tr>
                            <?php if($active_tab === 'warehouse_products'): ?>
                                <td class="product-id-cell"><?= formatProductId($row['product_id'], $row['sku_ozon']) ?></td>
                                <td><?= htmlspecialchars(mb_substr($row['product_name'] ?? 'Товар #' . $row['product_id'], 0, 50)) ?></td>
                                <td><?= formatWarehouse($row['warehouse_name']) ?></td>
                                <td><span class="status-badge status-in-stock"><?= $row['stock_type'] ?></span></td>
                                <td class="number-cell <?= $row['quantity_present'] > 0 ? 'number-positive' : 'number-danger' ?>">
                                    <?= number_format($row['quantity_present']) ?>
                                </td>
                                <td class="number-cell <?= $row['quantity_reserved'] > 0 ? 'number-warning' : '' ?>">
                                    <?= number_format($row['quantity_reserved']) ?>
                                </td>
                                <td class="number-cell"><?= number_format($row['total_quantity']) ?></td>
                                <td>
                                    <span class="status-badge <?= 
                                        $row['stock_status'] === 'В наличии' ? 'status-in-stock' : 
                                        ($row['stock_status'] === 'Мало' || $row['stock_status'] === 'Критически мало' ? 'status-low-stock' : 'status-out') 
                                    ?>">
                                        <?= $row['stock_status'] ?>
                                    </span>
                                </td>
                                <td><?= date('d.m.Y H:i', strtotime($row['updated_at'] ?? 'now')) ?></td>
                            <?php elseif($active_tab === 'products'): ?>
                                <td class="product-id-cell"><?= formatProductId($row['product_id'], $row['sku_ozon']) ?></td>
                                <td><?= htmlspecialchars(mb_substr($row['product_name'] ?? 'Товар #' . $row['product_id'], 0, 50)) ?></td>
                                <td class="number-cell"><?= $row['warehouses_count'] ?></td>
                                <td class="number-cell number-positive"><?= number_format($row['total_present']) ?></td>
                                <td class="number-cell number-warning"><?= number_format($row['total_reserved']) ?></td>
                                <td class="number-cell"><?= number_format($row['total_quantity']) ?></td>
                                <td class="number-cell"><?= number_format($row['avg_stock'], 1) ?></td>
                                <td><?= date('d.m.Y H:i', strtotime($row['last_updated'] ?? 'now')) ?></td>
                            <?php elseif($active_tab === 'warehouses'): ?>
                                <td><?= formatWarehouse($row['warehouse_name']) ?></td>
                                <td><span class="status-badge status-in-stock"><?= $row['stock_type'] ?></span></td>
                                <td class="number-cell"><?= number_format($row['products_count']) ?></td>
                                <td class="number-cell number-positive"><?= number_format($row['total_present']) ?></td>
                                <td class="number-cell number-warning"><?= number_format($row['total_reserved']) ?></td>
                                <td class="number-cell"><?= number_format($row['total_quantity']) ?></td>
                                <td class="number-cell"><?= number_format($row['avg_stock_per_product'], 1) ?></td>
                                <td class="number-cell number-danger"><?= number_format($row['zero_stock_count']) ?></td>
                                <td><?= date('d.m.Y H:i', strtotime($row['last_updated'] ?? 'now')) ?></td>
                            <?php endif; ?>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        
        <div class="refresh-info">
            <p>📅 Последнее обновление: <?= date('d.m.Y H:i:s') ?> | 
               📊 Показано записей: <?= count($data) ?> | 
               🔄 <a href="javascript:location.reload()" style="color: #007bff;">Обновить данные</a></p>
        </div>
    </div>
</body>
</html>
EOF

sudo chown www-data:www-data /var/www/html/dashboard_marketplace_enhanced.php

echo "✅ Дашборд исправлен!"
echo "🔍 Теперь поиск работает по ID, SKU и названию"
echo "📋 ID и SKU показываются вместе"
echo "🏪 Склады отображаются без префикса Ozon-"
echo "🌐 https://api.zavodprostavok.ru/dashboard_marketplace_enhanced.php"