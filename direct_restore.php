<?php
/**
 * Прямое восстановление данных - без JavaScript
 */

require_once __DIR__ . '/config.php';

?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <title>🔄 Прямое восстановление дашборда</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 20px; background: #f5f5f7; }
        .container { max-width: 800px; margin: 0 auto; background: white; padding: 30px; border-radius: 10px; }
        .btn { display: inline-block; background: #007bff; color: white; padding: 15px 30px; text-decoration: none; border-radius: 5px; margin: 10px; }
        .btn:hover { background: #0056b3; }
        .btn-danger { background: #dc3545; }
        .btn-success { background: #28a745; }
        .result { margin: 20px 0; padding: 15px; border-radius: 5px; }
        .success { background: #d4edda; color: #155724; }
        .error { background: #f8d7da; color: #721c24; }
        .step { background: #e9ecef; padding: 20px; margin: 15px 0; border-radius: 5px; }
    </style>
</head>
<body>
    <div class="container">
        <h1>🔄 Прямое восстановление дашборда</h1>
        
        <?php
        $action = $_GET['action'] ?? '';
        
        if ($action === 'restore_inventory') {
            echo "<div class='result success'>";
            echo "<h3>🔄 Восстанавливаем данные inventory_data...</h3>";
            
            try {
                $pdo = getDatabaseConnection();
                
                // Очищаем старые данные
                $pdo->exec("DELETE FROM inventory_data");
                echo "<p>✅ Старые данные очищены</p>";
                
                // Добавляем тестовые данные
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
                
                echo "<p>✅ Добавлено $inserted записей в inventory_data</p>";
                echo "<p><strong>Данные остатков восстановлены!</strong></p>";
                
            } catch (Exception $e) {
                echo "<p>❌ Ошибка: " . $e->getMessage() . "</p>";
            }
            
            echo "</div>";
        }
        
        if ($action === 'create_recommendations') {
            echo "<div class='result success'>";
            echo "<h3>🔧 Создаем таблицу replenishment_recommendations...</h3>";
            
            try {
                $pdo = getDatabaseConnection();
                
                // Создаем таблицу
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
                    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
                ";
                
                $pdo->exec($createSql);
                echo "<p>✅ Таблица replenishment_recommendations создана</p>";
                
                // Добавляем тестовые рекомендации
                $recommendations = [
                    ['sku' => 'Разрыхлитель_130г', 'name' => 'Разрыхлитель для теста ЭТОНОВО', 'current' => 1, 'recommended' => 50, 'priority' => 'High', 'ads' => 5.5],
                    ['sku' => 'Смесь_Пицца_9шт', 'name' => 'Смесь для выпечки Пицца ЭТОНОВО', 'current' => 1, 'recommended' => 30, 'priority' => 'High', 'ads' => 3.2],
                    ['sku' => 'CRITICAL_001', 'name' => 'Критический товар 1', 'current' => 2, 'recommended' => 40, 'priority' => 'High', 'ads' => 8.1],
                    ['sku' => 'LOW_STOCK_001', 'name' => 'Товар с низким остатком 1', 'current' => 8, 'recommended' => 25, 'priority' => 'Medium', 'ads' => 4.7],
                    ['sku' => 'NORMAL_001', 'name' => 'Нормальный товар 1', 'current' => 45, 'recommended' => 20, 'priority' => 'Low', 'ads' => 2.3]
                ];
                
                $insertSql = "INSERT INTO replenishment_recommendations (sku, product_name, current_stock, recommended_quantity, priority, ads) VALUES (?, ?, ?, ?, ?, ?)";
                $stmt = $pdo->prepare($insertSql);
                
                $inserted = 0;
                foreach ($recommendations as $rec) {
                    $stmt->execute([
                        $rec['sku'],
                        $rec['name'],
                        $rec['current'],
                        $rec['recommended'],
                        $rec['priority'],
                        $rec['ads']
                    ]);
                    $inserted++;
                }
                
                echo "<p>✅ Добавлено $inserted тестовых рекомендаций</p>";
                echo "<p><strong>Таблица рекомендаций создана!</strong></p>";
                
            } catch (Exception $e) {
                echo "<p>❌ Ошибка: " . $e->getMessage() . "</p>";
            }
            
            echo "</div>";
        }
        
        if ($action === 'test_dashboard') {
            echo "<div class='result success'>";
            echo "<h3>🧪 Тестируем дашборд...</h3>";
            
            try {
                // Включаем API напрямую
                $_GET['action'] = 'dashboard';
                ob_start();
                include 'api/inventory-analytics.php';
                $output = ob_get_clean();
                
                $data = json_decode($output, true);
                if ($data && $data['status'] === 'success') {
                    $stats = $data['data'];
                    echo "<p>✅ Дашборд работает!</p>";
                    echo "<p>Критические остатки: <strong>" . ($stats['critical_stock_count'] ?? 0) . "</strong></p>";
                    echo "<p>Низкие остатки: <strong>" . ($stats['low_stock_count'] ?? 0) . "</strong></p>";
                    echo "<p>Избыток товаров: <strong>" . ($stats['overstock_count'] ?? 0) . "</strong></p>";
                    echo "<p>Нормальные остатки: <strong>" . ($stats['normal_count'] ?? 0) . "</strong></p>";
                } else {
                    echo "<p>❌ Дашборд все еще не работает</p>";
                    echo "<p>Ответ API: " . htmlspecialchars($output) . "</p>";
                }
                
            } catch (Exception $e) {
                echo "<p>❌ Ошибка тестирования: " . $e->getMessage() . "</p>";
            }
            
            echo "</div>";
        }
        ?>
        
        <div class="step">
            <h3>Шаг 1: Восстановление данных остатков</h3>
            <p>Добавит 13 тестовых товаров с разными остатками в inventory_data</p>
            <a href="?action=restore_inventory" class="btn btn-danger">🔄 Восстановить данные остатков</a>
        </div>
        
        <div class="step">
            <h3>Шаг 2: Создание таблицы рекомендаций</h3>
            <p>Создаст таблицу replenishment_recommendations и добавит тестовые рекомендации</p>
            <a href="?action=create_recommendations" class="btn btn-danger">🔧 Создать таблицу рекомендаций</a>
        </div>
        
        <div class="step">
            <h3>Шаг 3: Тестирование дашборда</h3>
            <p>Протестируем работу дашборда после восстановления</p>
            <a href="?action=test_dashboard" class="btn">🧪 Тестировать дашборд</a>
        </div>
        
        <div class="step">
            <h3>Шаг 4: Открыть дашборд</h3>
            <p>Откроем восстановленный дашборд</p>
            <a href="test_dashboard.html" target="_blank" class="btn btn-success">🚀 Открыть дашборд</a>
        </div>
        
        <div class="step">
            <h3>Прямые ссылки для проверки</h3>
            <a href="api/inventory-analytics.php?action=dashboard" target="_blank" class="btn">🔗 API дашборда</a>
            <a href="replenishment_adapter_fixed.php?action=recommendations" target="_blank" class="btn">🔗 API рекомендаций</a>
        </div>
    </div>
</body>
</html>