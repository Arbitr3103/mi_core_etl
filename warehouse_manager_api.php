<?php
/**
 * API для управленческого дашборда складов
 * Предоставляет детальную информацию по товарам и складам
 */

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

// Обработка preflight запросов
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

try {
    // Подключение к базе данных
    $pdo = new PDO(
        'pgsql:host=localhost;dbname=mi_core_db;port=5432',
        'mi_core_user',
        'mi_core_2024_secure',
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false
        ]
    );

    // Получение детальных данных по товарам и складам
    $inventoryQuery = "
        SELECT 
            i.id,
            i.warehouse_name,
            i.quantity_present,
            i.quantity_reserved,
            i.updated_at,
            i.data_source,
            i.data_quality_score,
            i.normalized_warehouse_name,
            dp.product_name,
            dp.sku_ozon as sku,
            dp.barcode,
            'Продукты питания' as category
        FROM inventory i
        LEFT JOIN dim_products dp ON i.product_id = dp.id
        WHERE i.quantity_present >= 0
        ORDER BY i.warehouse_name, dp.product_name
    ";
    
    $stmt = $pdo->prepare($inventoryQuery);
    $stmt->execute();
    $inventory = $stmt->fetchAll();

    // Обработка данных для лучшего отображения
    $processedInventory = [];
    foreach ($inventory as $item) {
        $processedInventory[] = [
            'id' => $item['id'],
            'warehouse_name' => $item['normalized_warehouse_name'] ?: $item['warehouse_name'],
            'product_name' => $item['product_name'] ?: 'SKU: ' . $item['sku'],
            'sku' => $item['sku'],
            'barcode' => $item['barcode'],
            'category' => $item['category'],
            'quantity_present' => (int)$item['quantity_present'],
            'quantity_reserved' => (int)$item['quantity_reserved'],
            'quantity_available' => (int)$item['quantity_present'] - (int)$item['quantity_reserved'],
            'updated_at' => $item['updated_at'],
            'data_source' => $item['data_source'],
            'data_quality_score' => $item['data_quality_score']
        ];
    }

    // Расчет сводной статистики
    $summaryQuery = "
        SELECT 
            COUNT(DISTINCT i.warehouse_name) as total_warehouses,
            COUNT(DISTINCT i.product_id) as total_products,
            SUM(i.quantity_present) as total_stock,
            COUNT(CASE WHEN i.quantity_present < 10 THEN 1 END) as low_stock_items,
            COUNT(CASE WHEN i.quantity_present = 0 THEN 1 END) as zero_stock_items,
            AVG(i.quantity_present) as avg_stock_per_item
        FROM inventory i
        WHERE i.quantity_present >= 0
    ";
    
    $stmt = $pdo->prepare($summaryQuery);
    $stmt->execute();
    $summary = $stmt->fetch();

    // Топ складов по остаткам
    $warehousesQuery = "
        SELECT 
            COALESCE(i.normalized_warehouse_name, i.warehouse_name) as warehouse_name,
            COUNT(*) as total_items,
            SUM(i.quantity_present) as total_stock,
            COUNT(CASE WHEN i.quantity_present < 10 THEN 1 END) as low_stock_items,
            COUNT(CASE WHEN i.quantity_present = 0 THEN 1 END) as zero_stock_items,
            AVG(i.quantity_present) as avg_stock
        FROM inventory i
        WHERE i.quantity_present >= 0
        GROUP BY COALESCE(i.normalized_warehouse_name, i.warehouse_name)
        ORDER BY total_stock DESC
    ";
    
    $stmt = $pdo->prepare($warehousesQuery);
    $stmt->execute();
    $warehouses = $stmt->fetchAll();

    // Товары требующие внимания (низкие остатки)
    $attentionQuery = "
        SELECT 
            COALESCE(i.normalized_warehouse_name, i.warehouse_name) as warehouse_name,
            dp.product_name,
            dp.sku_ozon as sku,
            i.quantity_present,
            i.quantity_reserved,
            i.updated_at
        FROM inventory i
        LEFT JOIN dim_products dp ON i.product_id = dp.id
        WHERE i.quantity_present < 10
        ORDER BY i.quantity_present ASC, i.updated_at DESC
        LIMIT 20
    ";
    
    $stmt = $pdo->prepare($attentionQuery);
    $stmt->execute();
    $attentionItems = $stmt->fetchAll();

    // Статистика по категориям товаров
    $categoriesQuery = "
        SELECT 
            'Продукты питания' as category,
            COUNT(*) as items_count,
            SUM(i.quantity_present) as total_stock,
            AVG(i.quantity_present) as avg_stock
        FROM inventory i
        LEFT JOIN dim_products dp ON i.product_id = dp.id
        WHERE i.quantity_present >= 0
    ";
    
    $stmt = $pdo->prepare($categoriesQuery);
    $stmt->execute();
    $categories = $stmt->fetchAll();

    // Формирование ответа
    $response = [
        'success' => true,
        'timestamp' => date('Y-m-d H:i:s'),
        'data' => [
            'inventory' => $processedInventory,
            'summary' => [
                'totalWarehouses' => (int)$summary['total_warehouses'],
                'totalProducts' => (int)$summary['total_products'],
                'totalStock' => (int)$summary['total_stock'],
                'lowStockItems' => (int)$summary['low_stock_items'],
                'zeroStockItems' => (int)$summary['zero_stock_items'],
                'avgStockPerItem' => round($summary['avg_stock_per_item'], 1)
            ],
            'warehouses' => $warehouses,
            'attentionItems' => $attentionItems,
            'categories' => $categories
        ],
        'meta' => [
            'totalRecords' => count($processedInventory),
            'dataSource' => 'Ozon Analytics API',
            'lastETLRun' => getLastETLRun($pdo)
        ]
    ];

    echo json_encode($response, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Database error',
        'message' => 'Ошибка подключения к базе данных',
        'details' => $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Server error',
        'message' => 'Внутренняя ошибка сервера',
        'details' => $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
}

/**
 * Получение времени последнего запуска ETL
 */
function getLastETLRun($pdo) {
    try {
        $stmt = $pdo->prepare("
            SELECT started_at 
            FROM analytics_etl_log 
            WHERE status = 'completed' 
            ORDER BY started_at DESC 
            LIMIT 1
        ");
        $stmt->execute();
        $result = $stmt->fetch();
        
        return $result ? $result['started_at'] : null;
    } catch (Exception $e) {
        return null;
    }
}
?>