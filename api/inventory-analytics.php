<?php
/**
 * API для аналитики складских остатков и маркетинговых решений
 */

require_once __DIR__ . '/../config.php';

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

try {
    $pdo = getDatabaseConnection();
    
    $action = $_GET['action'] ?? 'dashboard';
    
    switch ($action) {
        case 'dashboard':
            echo json_encode([
                'status' => 'success',
                'data' => getInventoryDashboardData($pdo)
            ]);
            break;
            
        case 'critical-products':
            echo json_encode([
                'status' => 'success',
                'data' => getCriticalProducts($pdo)
            ]);
            break;
            
        case 'overstock-products':
            echo json_encode([
                'status' => 'success',
                'data' => getOverstockProducts($pdo)
            ]);
            break;
            
        default:
            http_response_code(400);
            echo json_encode([
                'status' => 'error',
                'message' => 'Invalid action'
            ]);
    }
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'status' => 'error',
        'message' => $e->getMessage()
    ]);
}

function getInventoryDashboardData($pdo) {
    // Получаем данные о товарах и остатках
    $stmt = $pdo->query("
        SELECT 
            dp.product_name,
            dp.sku,
            dp.current_stock,
            dp.min_stock_level,
            dp.max_stock_level,
            dp.unit_cost,
            dp.selling_price,
            COALESCE(sales.daily_avg, 0) as daily_sales,
            CASE 
                WHEN dp.current_stock <= dp.min_stock_level THEN 'critical'
                WHEN dp.current_stock <= (dp.min_stock_level * 2) THEN 'low'
                WHEN dp.current_stock >= (dp.max_stock_level * 1.5) THEN 'overstock'
                ELSE 'normal'
            END as stock_status,
            CASE 
                WHEN COALESCE(sales.daily_avg, 0) > 0 
                THEN ROUND(dp.current_stock / sales.daily_avg, 0)
                ELSE 999
            END as days_left
        FROM dim_products dp
        LEFT JOIN (
            SELECT 
                product_id,
                AVG(daily_quantity) as daily_avg
            FROM (
                SELECT 
                    product_id,
                    DATE(created_at) as sale_date,
                    COUNT(*) as daily_quantity
                FROM fact_transactions 
                WHERE created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
                GROUP BY product_id, DATE(created_at)
            ) daily_sales
            GROUP BY product_id
        ) sales ON dp.product_id = sales.product_id
        WHERE dp.current_stock IS NOT NULL
        ORDER BY 
            CASE 
                WHEN dp.current_stock <= dp.min_stock_level THEN 1
                WHEN dp.current_stock <= (dp.min_stock_level * 2) THEN 2
                WHEN dp.current_stock >= (dp.max_stock_level * 1.5) THEN 3
                ELSE 4
            END,
            dp.current_stock ASC
    ");
    
    $products = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Группируем товары по статусу
    $critical_products = [];
    $low_stock_products = [];
    $overstock_products = [];
    $normal_products = [];
    
    $total_inventory_value = 0;
    $potential_lost_sales = 0;
    $overstock_value = 0;
    
    foreach ($products as $product) {
        $total_inventory_value += ($product['current_stock'] * $product['unit_cost']);
        
        switch ($product['stock_status']) {
            case 'critical':
                $critical_products[] = [
                    'name' => $product['product_name'] ?: 'Товар ' . $product['sku'],
                    'sku' => $product['sku'],
                    'stock' => (int)$product['current_stock'],
                    'daily_sales' => round($product['daily_sales'], 1),
                    'days_left' => (int)$product['days_left'],
                    'unit_cost' => (float)$product['unit_cost'],
                    'selling_price' => (float)$product['selling_price']
                ];
                $potential_lost_sales += ($product['daily_sales'] * $product['selling_price']);
                break;
                
            case 'low':
                $low_stock_products[] = [
                    'name' => $product['product_name'] ?: 'Товар ' . $product['sku'],
                    'sku' => $product['sku'],
                    'stock' => (int)$product['current_stock'],
                    'daily_sales' => round($product['daily_sales'], 1),
                    'days_left' => (int)$product['days_left'],
                    'unit_cost' => (float)$product['unit_cost'],
                    'selling_price' => (float)$product['selling_price']
                ];
                break;
                
            case 'overstock':
                $overstock_products[] = [
                    'name' => $product['product_name'] ?: 'Товар ' . $product['sku'],
                    'sku' => $product['sku'],
                    'stock' => (int)$product['current_stock'],
                    'daily_sales' => round($product['daily_sales'], 1),
                    'days_left' => (int)$product['days_left'],
                    'unit_cost' => (float)$product['unit_cost'],
                    'selling_price' => (float)$product['selling_price']
                ];
                $excess_stock = $product['current_stock'] - $product['max_stock_level'];
                $overstock_value += ($excess_stock * $product['unit_cost']);
                break;
                
            default:
                $normal_products[] = [
                    'name' => $product['product_name'] ?: 'Товар ' . $product['sku'],
                    'sku' => $product['sku'],
                    'stock' => (int)$product['current_stock'],
                    'daily_sales' => round($product['daily_sales'], 1),
                    'days_left' => (int)$product['days_left']
                ];
        }
    }
    
    // Ограничиваем количество товаров для отображения
    $critical_products = array_slice($critical_products, 0, 10);
    $low_stock_products = array_slice($low_stock_products, 0, 10);
    $overstock_products = array_slice($overstock_products, 0, 10);
    
    return [
        'critical_stock_count' => count($critical_products),
        'low_stock_count' => count($low_stock_products),
        'overstock_count' => count($overstock_products),
        'normal_count' => count($normal_products),
        'total_inventory_value' => $total_inventory_value,
        'potential_lost_sales' => $potential_lost_sales,
        'overstock_value' => $overstock_value,
        'critical_products' => $critical_products,
        'low_stock_products' => $low_stock_products,
        'overstock_products' => $overstock_products,
        'summary' => [
            'total_products' => count($products),
            'needs_attention' => count($critical_products) + count($low_stock_products),
            'optimization_opportunity' => count($overstock_products)
        ]
    ];
}

function getCriticalProducts($pdo) {
    $stmt = $pdo->query("
        SELECT 
            dp.product_name,
            dp.sku,
            dp.current_stock,
            dp.min_stock_level,
            dp.unit_cost,
            dp.selling_price,
            COALESCE(sales.daily_avg, 0) as daily_sales,
            CASE 
                WHEN COALESCE(sales.daily_avg, 0) > 0 
                THEN ROUND(dp.current_stock / sales.daily_avg, 0)
                ELSE 999
            END as days_left
        FROM dim_products dp
        LEFT JOIN (
            SELECT 
                product_id,
                AVG(daily_quantity) as daily_avg
            FROM (
                SELECT 
                    product_id,
                    DATE(created_at) as sale_date,
                    COUNT(*) as daily_quantity
                FROM fact_transactions 
                WHERE created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
                GROUP BY product_id, DATE(created_at)
            ) daily_sales
            GROUP BY product_id
        ) sales ON dp.product_id = sales.product_id
        WHERE dp.current_stock <= dp.min_stock_level
        ORDER BY 
            CASE 
                WHEN COALESCE(sales.daily_avg, 0) > 0 
                THEN dp.current_stock / sales.daily_avg
                ELSE 999
            END ASC
    ");
    
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function getOverstockProducts($pdo) {
    $stmt = $pdo->query("
        SELECT 
            dp.product_name,
            dp.sku,
            dp.current_stock,
            dp.max_stock_level,
            dp.unit_cost,
            dp.selling_price,
            COALESCE(sales.daily_avg, 0) as daily_sales,
            (dp.current_stock - dp.max_stock_level) as excess_stock,
            ((dp.current_stock - dp.max_stock_level) * dp.unit_cost) as excess_value
        FROM dim_products dp
        LEFT JOIN (
            SELECT 
                product_id,
                AVG(daily_quantity) as daily_avg
            FROM (
                SELECT 
                    product_id,
                    DATE(created_at) as sale_date,
                    COUNT(*) as daily_quantity
                FROM fact_transactions 
                WHERE created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
                GROUP BY product_id, DATE(created_at)
            ) daily_sales
            GROUP BY product_id
        ) sales ON dp.product_id = sales.product_id
        WHERE dp.current_stock >= (dp.max_stock_level * 1.5)
        ORDER BY excess_value DESC
    ");
    
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}
?>