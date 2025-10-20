<?php
/**
 * Восстановление данных для дашборда
 */

require_once __DIR__ . '/../config.php';

header('Content-Type: application/json; charset=utf-8');

try {
    $pdo = getDatabaseConnection();
    $action = $_GET['action'] ?? 'check';
    
    if ($action === 'restore_inventory') {
        // Восстанавливаем данные в inventory_data
        echo json_encode(['status' => 'info', 'message' => 'Начинаем восстановление inventory_data...']);
        
        // Очищаем старые данные
        $pdo->exec("DELETE FROM inventory_data");
        
        // Добавляем тестовые данные как они были вчера
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
        
        echo json_encode([
            'status' => 'success',
            'message' => 'Данные inventory_data восстановлены',
            'inserted' => $inserted
        ]);
        
    } elseif ($action === 'create_recommendations_table') {
        // Создаем таблицу replenishment_recommendations
        
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
        
        echo json_encode([
            'status' => 'success',
            'message' => 'Таблица replenishment_recommendations создана',
            'inserted_recommendations' => $inserted
        ]);
        
    } else {
        // Проверяем текущее состояние
        $stmt = $pdo->query("SELECT COUNT(*) as total FROM inventory_data");
        $total = $stmt->fetch()['total'];
        
        $stmt = $pdo->query("SELECT COUNT(*) as with_stock FROM inventory_data WHERE current_stock IS NOT NULL AND current_stock > 0");
        $with_stock = $stmt->fetch()['with_stock'];
        
        // Проверяем таблицу рекомендаций
        $rec_exists = false;
        try {
            $stmt = $pdo->query("SELECT COUNT(*) as count FROM replenishment_recommendations");
            $rec_count = $stmt->fetch()['count'];
            $rec_exists = true;
        } catch (Exception $e) {
            $rec_count = 0;
        }
        
        echo json_encode([
            'status' => 'info',
            'inventory_data' => [
                'total_records' => $total,
                'records_with_stock' => $with_stock,
                'needs_restore' => $with_stock == 0
            ],
            'replenishment_recommendations' => [
                'exists' => $rec_exists,
                'count' => $rec_count,
                'needs_create' => !$rec_exists
            ]
        ]);
    }
    
} catch (Exception $e) {
    echo json_encode([
        'status' => 'error',
        'message' => $e->getMessage()
    ]);
}
?>