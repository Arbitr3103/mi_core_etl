<?php
/**
 * Диагностика базы данных - простая проверка
 */

header('Content-Type: text/html; charset=utf-8');
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <title>Диагностика базы данных</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 20px; }
        .ok { color: green; }
        .error { color: red; }
        .warning { color: orange; }
        table { border-collapse: collapse; width: 100%; }
        th, td { border: 1px solid #ddd; padding: 8px; text-align: left; }
        th { background-color: #f2f2f2; }
        .btn { background: #007bff; color: white; padding: 10px 20px; border: none; border-radius: 5px; cursor: pointer; margin: 5px; }
        .btn:hover { background: #0056b3; }
    </style>
</head>
<body>
    <h1>🔍 Диагностика базы данных</h1>
    
    <?php
    try {
        // Подключение к базе данных
        $pdo = new PDO(
            'mysql:host=localhost;dbname=mi_core_db;charset=utf8mb4',
            'dashboard_user',
            'dashboard_prod_2025',
            [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
            ]
        );
        
        echo "<p class='ok'>✅ Подключение к базе данных: УСПЕШНО</p>";
        
        // Проверяем основные таблицы
        $tables = [
            'inventory_data' => 'Данные остатков',
            'dim_products' => 'Справочник товаров', 
            'replenishment_recommendations' => 'Рекомендации по пополнению',
            'fact_orders' => 'Заказы',
            'sku_cross_reference' => 'Кросс-референсы SKU'
        ];
        
        echo "<h2>📊 Состояние таблиц:</h2>";
        echo "<table>";
        echo "<tr><th>Таблица</th><th>Описание</th><th>Существует</th><th>Записей</th><th>Статус</th></tr>";
        
        foreach ($tables as $table => $description) {
            echo "<tr>";
            echo "<td><strong>$table</strong></td>";
            echo "<td>$description</td>";
            
            try {
                $stmt = $pdo->query("SHOW TABLES LIKE '$table'");
                $exists = $stmt->rowCount() > 0;
                
                if ($exists) {
                    echo "<td class='ok'>✅ Да</td>";
                    
                    try {
                        $stmt = $pdo->query("SELECT COUNT(*) as count FROM $table");
                        $count = $stmt->fetch()['count'];
                        echo "<td>$count</td>";
                        
                        if ($count > 0) {
                            echo "<td class='ok'>✅ OK</td>";
                        } else {
                            echo "<td class='warning'>⚠️ Пустая</td>";
                        }
                    } catch (Exception $e) {
                        echo "<td class='error'>Ошибка</td>";
                        echo "<td class='error'>❌ " . $e->getMessage() . "</td>";
                    }
                } else {
                    echo "<td class='error'>❌ Нет</td>";
                    echo "<td>-</td>";
                    echo "<td class='error'>❌ Не существует</td>";
                }
            } catch (Exception $e) {
                echo "<td class='error'>❌ Ошибка</td>";
                echo "<td>-</td>";
                echo "<td class='error'>❌ " . $e->getMessage() . "</td>";
            }
            
            echo "</tr>";
        }
        echo "</table>";
        
        // Специальная проверка inventory_data
        echo "<h2>🏪 Детальная проверка inventory_data:</h2>";
        try {
            $stmt = $pdo->query("SELECT COUNT(*) as total FROM inventory_data");
            $total = $stmt->fetch()['total'];
            
            $stmt = $pdo->query("SELECT COUNT(*) as with_stock FROM inventory_data WHERE current_stock IS NOT NULL AND current_stock > 0");
            $with_stock = $stmt->fetch()['with_stock'];
            
            echo "<p><strong>Всего записей:</strong> $total</p>";
            echo "<p><strong>Записей с остатками > 0:</strong> $with_stock</p>";
            
            if ($with_stock == 0) {
                echo "<p class='error'>❌ ПРОБЛЕМА: Нет товаров с остатками!</p>";
                echo "<button class='btn' onclick='location.href=\"?action=add_test_data\"'>🔄 Добавить тестовые данные</button>";
            } else {
                echo "<p class='ok'>✅ Данные есть</p>";
                
                // Показываем примеры данных
                $stmt = $pdo->query("SELECT sku, warehouse_name, current_stock FROM inventory_data WHERE current_stock > 0 LIMIT 5");
                $samples = $stmt->fetchAll();
                
                echo "<h3>Примеры данных:</h3>";
                echo "<table>";
                echo "<tr><th>SKU</th><th>Склад</th><th>Остаток</th></tr>";
                foreach ($samples as $sample) {
                    echo "<tr>";
                    echo "<td>{$sample['sku']}</td>";
                    echo "<td>{$sample['warehouse_name']}</td>";
                    echo "<td>{$sample['current_stock']}</td>";
                    echo "</tr>";
                }
                echo "</table>";
            }
            
        } catch (Exception $e) {
            echo "<p class='error'>❌ Ошибка проверки inventory_data: " . $e->getMessage() . "</p>";
        }
        
        // Проверяем отсутствующую таблицу replenishment_recommendations
        echo "<h2>💡 Проверка replenishment_recommendations:</h2>";
        try {
            $stmt = $pdo->query("SHOW TABLES LIKE 'replenishment_recommendations'");
            if ($stmt->rowCount() == 0) {
                echo "<p class='error'>❌ Таблица replenishment_recommendations не существует</p>";
                echo "<button class='btn' onclick='location.href=\"?action=create_replenishment_table\"'>🔧 Создать таблицу</button>";
            }
        } catch (Exception $e) {
            echo "<p class='error'>❌ Ошибка: " . $e->getMessage() . "</p>";
        }
        
    } catch (Exception $e) {
        echo "<p class='error'>❌ Ошибка подключения к базе данных: " . $e->getMessage() . "</p>";
    }
    
    // Обработка действий
    if (isset($_GET['action'])) {
        $action = $_GET['action'];
        
        if ($action === 'add_test_data') {
            echo "<h2>🔄 Добавление тестовых данных...</h2>";
            
            try {
                // Очищаем и добавляем тестовые данные
                $pdo->exec("DELETE FROM inventory_data");
                
                $testData = [
                    ['sku' => 'Разрыхлитель_130г', 'warehouse' => 'Ozon_Main', 'stock' => 1],
                    ['sku' => 'Смесь_Пицца_9шт', 'warehouse' => 'Ozon_Main', 'stock' => 1], 
                    ['sku' => 'CRITICAL_001', 'warehouse' => 'Склад_Основной', 'stock' => 2],
                    ['sku' => 'CRITICAL_002', 'warehouse' => 'Склад_Основной', 'stock' => 3],
                    ['sku' => 'LOW_STOCK_001', 'warehouse' => 'Склад_Основной', 'stock' => 8],
                    ['sku' => 'LOW_STOCK_002', 'warehouse' => 'Склад_Основной', 'stock' => 12],
                    ['sku' => 'LOW_STOCK_003', 'warehouse' => 'Склад_Резервный', 'stock' => 15],
                    ['sku' => 'OVERSTOCK_001', 'warehouse' => 'Склад_Резервный', 'stock' => 120],
                    ['sku' => 'NORMAL_001', 'warehouse' => 'Склад_Основной', 'stock' => 45],
                    ['sku' => 'NORMAL_002', 'warehouse' => 'Склад_Основной', 'stock' => 67],
                    ['sku' => 'NORMAL_003', 'warehouse' => 'Склад_Резервный', 'stock' => 89],
                    ['sku' => 'NORMAL_004', 'warehouse' => 'Склад_Резервный', 'stock' => 34],
                    ['sku' => 'NORMAL_005', 'warehouse' => 'Ozon_Main', 'stock' => 56]
                ];
                
                $insertSql = "INSERT INTO inventory_data (sku, warehouse_name, current_stock, available_stock, reserved_stock, last_sync_at) VALUES (?, ?, ?, ?, ?, NOW())";
                $stmt = $pdo->prepare($insertSql);
                
                $inserted = 0;
                foreach ($testData as $item) {
                    $available = max(0, $item['stock'] - rand(0, 2));
                    $reserved = $item['stock'] - $available;
                    
                    $stmt->execute([
                        $item['sku'],
                        $item['warehouse'],
                        $item['stock'],
                        $available,
                        $reserved
                    ]);
                    $inserted++;
                }
                
                echo "<p class='ok'>✅ Добавлено $inserted записей в inventory_data</p>";
                echo "<p><a href='test_dashboard.html' target='_blank'>🚀 Открыть дашборд</a></p>";
                
            } catch (Exception $e) {
                echo "<p class='error'>❌ Ошибка добавления данных: " . $e->getMessage() . "</p>";
            }
        }
        
        if ($action === 'create_replenishment_table') {
            echo "<h2>🔧 Создание таблицы replenishment_recommendations...</h2>";
            
            try {
                $createSql = "
                    CREATE TABLE IF NOT EXISTS replenishment_recommendations (
                        id INT AUTO_INCREMENT PRIMARY KEY,
                        sku VARCHAR(100) NOT NULL,
                        product_name VARCHAR(255),
                        current_stock INT DEFAULT 0,
                        recommended_quantity INT DEFAULT 0,
                        priority ENUM('High', 'Medium', 'Low') DEFAULT 'Medium',
                        ads DECIMAL(10,2) DEFAULT 0,
                        calculation_date TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                        INDEX idx_sku (sku),
                        INDEX idx_priority (priority)
                    )
                ";
                $pdo->exec($createSql);
                
                echo "<p class='ok'>✅ Таблица replenishment_recommendations создана</p>";
                
                // Добавляем несколько тестовых рекомендаций
                $recommendations = [
                    ['sku' => 'CRITICAL_001', 'name' => 'Критический товар 1', 'current' => 2, 'recommended' => 50, 'priority' => 'High', 'ads' => 5.5],
                    ['sku' => 'CRITICAL_002', 'name' => 'Критический товар 2', 'current' => 3, 'recommended' => 30, 'priority' => 'High', 'ads' => 3.2],
                    ['sku' => 'LOW_STOCK_001', 'name' => 'Товар с низким остатком 1', 'current' => 8, 'recommended' => 40, 'priority' => 'Medium', 'ads' => 8.1],
                ];
                
                $recSql = "INSERT INTO replenishment_recommendations (sku, product_name, current_stock, recommended_quantity, priority, ads) VALUES (?, ?, ?, ?, ?, ?)";
                $recStmt = $pdo->prepare($recSql);
                
                foreach ($recommendations as $rec) {
                    $recStmt->execute([
                        $rec['sku'],
                        $rec['name'],
                        $rec['current'],
                        $rec['recommended'],
                        $rec['priority'],
                        $rec['ads']
                    ]);
                }
                
                echo "<p class='ok'>✅ Добавлено " . count($recommendations) . " тестовых рекомендаций</p>";
                
            } catch (Exception $e) {
                echo "<p class='error'>❌ Ошибка создания таблицы: " . $e->getMessage() . "</p>";
            }
        }
    }
    ?>
    
    <h2>🚀 Действия:</h2>
    <p>
        <a href="test_dashboard.html" target="_blank" class="btn">📊 Открыть дашборд</a>
        <a href="?" class="btn">🔄 Обновить диагностику</a>
    </p>
    
</body>
</html>