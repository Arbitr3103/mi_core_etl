<?php
/**
 * PostgreSQL Inventory API Endpoint
 * Updated for PostgreSQL migration
 */

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

// Handle preflight requests
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

require_once __DIR__ . '/InventoryController.php';

/**
 * Send JSON response
 */
function sendResponse($data, $statusCode = 200) {
    http_response_code($statusCode);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    exit();
}

/**
 * Send error response
 */
function sendError($message, $statusCode = 500, $details = null) {
    $response = [
        'success' => false,
        'error' => $message,
        'timestamp' => date('Y-m-d H:i:s')
    ];
    
    if ($details) {
        $response['details'] = $details;
    }
    
    sendResponse($response, $statusCode);
}

try {
    // Initialize controller
    $controller = new InventoryController();
    
    // Get action from URL
    $action = $_GET['action'] ?? 'dashboard';
    
    switch ($action) {
        case 'dashboard':
            // Get dashboard data for React frontend
            $limit = isset($_GET['limit']) ? intval($_GET['limit']) : null;
            
            $data = $controller->getDashboardData($limit);
            
            sendResponse([
                'success' => true,
                'data' => $data,
                'timestamp' => date('Y-m-d H:i:s')
            ]);
            break;
            
        case 'product':
            // Get single product details
            $sku = $_GET['sku'] ?? null;
            
            if (!$sku) {
                sendError('SKU parameter is required', 400);
            }
            
            $product = $controller->getProductBySku($sku);
            
            if (!$product) {
                sendError('Product not found', 404);
            }
            
            sendResponse([
                'success' => true,
                'data' => $product,
                'timestamp' => date('Y-m-d H:i:s')
            ]);
            break;
            
        case 'analytics':
            // Get inventory analytics
            $analytics = $controller->getInventoryAnalytics();
            
            sendResponse([
                'success' => true,
                'data' => $analytics,
                'timestamp' => date('Y-m-d H:i:s')
            ]);
            break;
            
        case 'movements':
            // Get stock movements for a product
            $productId = $_GET['product_id'] ?? null;
            $limit = isset($_GET['limit']) ? intval($_GET['limit']) : 50;
            
            if (!$productId) {
                sendError('Product ID parameter is required', 400);
            }
            
            $movements = $controller->getStockMovements($productId, $limit);
            
            sendResponse([
                'success' => true,
                'data' => $movements,
                'timestamp' => date('Y-m-d H:i:s')
            ]);
            break;
            
        case 'update':
            // Update inventory quantity
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                sendError('POST method required', 405);
            }
            
            $input = json_decode(file_get_contents('php://input'), true);
            
            $productId = $input['product_id'] ?? null;
            $warehouseName = $input['warehouse_name'] ?? null;
            $quantity = $input['quantity'] ?? null;
            $source = $input['source'] ?? 'manual';
            
            if (!$productId || !$warehouseName || $quantity === null) {
                sendError('Missing required parameters: product_id, warehouse_name, quantity', 400);
            }
            
            $result = $controller->updateInventoryQuantity($productId, $warehouseName, $quantity, $source);
            
            sendResponse([
                'success' => true,
                'message' => 'Inventory updated successfully',
                'timestamp' => date('Y-m-d H:i:s')
            ]);
            break;
            
        case 'health':
            // Health check endpoint
            $health = $controller->healthCheck();
            
            $statusCode = $health['status'] === 'error' ? 503 : 200;
            
            sendResponse($health, $statusCode);
            break;
            
        case 'test':
            // Test endpoint for migration verification
            try {
                $db = DatabaseConnection::getInstance();
                
                // Test basic queries
                $tests = [];
                
                // Test products count
                $productsCount = $db->getTableCount('dim_products');
                $tests['products_count'] = $productsCount;
                
                // Test inventory count
                $inventoryCount = $db->getTableCount('inventory');
                $tests['inventory_count'] = $inventoryCount;
                
                // Test stock movements count
                $movementsCount = $db->getTableCount('stock_movements');
                $tests['movements_count'] = $movementsCount;
                
                // Test dashboard view
                $dashboardSql = "SELECT COUNT(*) as count FROM v_dashboard_inventory";
                $dashboardResult = $db->fetchOne($dashboardSql);
                $tests['dashboard_view_count'] = $dashboardResult['count'] ?? 0;
                
                // Test database info
                $dbInfo = $db->getDatabaseInfo();
                $tests['database_info'] = $dbInfo;
                
                sendResponse([
                    'success' => true,
                    'message' => 'PostgreSQL migration test successful',
                    'tests' => $tests,
                    'timestamp' => date('Y-m-d H:i:s')
                ]);
                
            } catch (Exception $e) {
                sendError('Migration test failed: ' . $e->getMessage(), 500);
            }
            break;
            
        default:
            sendError('Invalid action parameter', 400);
    }
    
} catch (Exception $e) {
    error_log('Inventory API Error: ' . $e->getMessage());
    error_log('Stack trace: ' . $e->getTraceAsString());
    
    sendError('Internal server error', 500, [
        'message' => $e->getMessage(),
        'file' => basename($e->getFile()),
        'line' => $e->getLine()
    ]);
}
?>