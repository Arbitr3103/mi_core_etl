<?php
/**
 * Экстренная проверка базы данных
 */

header('Content-Type: application/json; charset=utf-8');

try {
    // Подключение к базе данных (как в рабочем API)
    $pdo = new PDO(
        'mysql:host=localhost;dbname=mi_core_db;charset=utf8mb4',
        'dashboard_user',
        'dashboard_prod_2025',
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
        ]
    );
    
    $results = [];
    
    // 1. Проверяем подключение
    $results['connection'] = 'OK';
    
    // 2. Проверяем таблицу inventory_data
    try {
        $stmt = $pdo->query("SELECT COUNT(*) as total FROM inventory_data");
        $count = $stmt->fetch()['total'];
        $results['inventory_data_total'] = $count;
        
        $stmt = $pdo->query("SELECT COUNT(*) as with_stock FROM inventory_data WHERE current_stock IS NOT NULL AND current_stock > 0");
        $with_stock = $stmt->fetch()['with_stock'];
        $results['inventory_data_with_stock'] = $with_stock;
        
        // Проверяем последние записи
        $stmt = $pdo->query("SELECT sku, warehouse_name, current_stock, last_sync_at FROM inventory_data ORDER BY last_sync_at DESC LIMIT 5");
        $results['latest_records'] = $stmt->fetchAll();
        
    } catch (Exception $e) {
        $results['inventory_data_error'] = $e->getMessage();
    }
    
    // 3. Проверяем таблицу dim_products
    try {
        $stmt = $pdo->query("SELECT COUNT(*) as total FROM dim_products");
        $count = $stmt->fetch()['total'];
        $results['dim_products_total'] = $count;
    } catch (Exception $e) {
        $results['dim_products_error'] = $e->getMessage();
    }
    
    // 4. Проверяем другие важные таблицы
    $tables = ['fact_orders', 'sku_cross_reference', 'replenishment_recommendations'];
    foreach ($tables as $table) {
        try {
            $stmt = $pdo->query("SELECT COUNT(*) as total FROM $table");
            $count = $stmt->fetch()['total'];
            $results[$table . '_total'] = $count;
        } catch (Exception $e) {
            $results[$table . '_error'] = $e->getMessage();
        }
    }
    
    // 5. Проверяем последнюю активность
    try {
        $stmt = $pdo->query("SELECT MAX(last_sync_at) as last_sync FROM inventory_data");
        $last_sync = $stmt->fetch()['last_sync'];
        $results['last_sync'] = $last_sync;
    } catch (Exception $e) {
        $results['last_sync_error'] = $e->getMessage();
    }
    
    echo json_encode([
        'status' => 'success',
        'timestamp' => date('Y-m-d H:i:s'),
        'results' => $results
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    
} catch (Exception $e) {
    echo json_encode([
        'status' => 'error',
        'message' => $e->getMessage(),
        'timestamp' => date('Y-m-d H:i:s')
    ], JSON_UNESCAPED_UNICODE);
}
?>