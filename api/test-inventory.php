<?php
/**
 * Test API endpoint for inventory data
 */

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

try {
    // Connect to PostgreSQL
    $dsn = "pgsql:host=localhost;port=5432;dbname=mi_core_db";
    $pdo = new PDO($dsn, 'vladimirbragin', '', [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
    ]);
    
    // Get products with inventory
    $query = "
        SELECT 
            dp.id,
            dp.sku_ozon,
            dp.product_name,
            dp.ozon_visibility,
            COUNT(i.id) as warehouse_count,
            SUM(i.quantity_present) as total_present,
            SUM(i.quantity_reserved) as total_reserved,
            SUM(i.quantity_present - i.quantity_reserved) as total_available
        FROM dim_products dp
        LEFT JOIN inventory i ON dp.id = i.product_id
        WHERE dp.sku_ozon ~ '^[0-9]+$'
        GROUP BY dp.id, dp.sku_ozon, dp.product_name, dp.ozon_visibility
        ORDER BY dp.product_name
    ";
    
    $stmt = $pdo->query($query);
    $products = $stmt->fetchAll();
    
    // Get detailed inventory for first product
    $detailedInventory = [];
    if (!empty($products)) {
        $firstProductId = $products[0]['id'];
        $query = "
            SELECT 
                warehouse_name,
                stock_type,
                quantity_present,
                quantity_reserved,
                (quantity_present - quantity_reserved) as available
            FROM inventory
            WHERE product_id = :product_id
            ORDER BY warehouse_name
        ";
        $stmt = $pdo->prepare($query);
        $stmt->execute(['product_id' => $firstProductId]);
        $detailedInventory = $stmt->fetchAll();
    }
    
    echo json_encode([
        'success' => true,
        'data' => [
            'products' => $products,
            'sample_inventory' => $detailedInventory,
            'total_products' => count($products),
            'timestamp' => date('Y-m-d H:i:s')
        ]
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ], JSON_PRETTY_PRINT);
}
