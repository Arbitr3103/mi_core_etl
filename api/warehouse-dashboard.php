<?php
/**
 * Warehouse Dashboard API
 * 
 * API endpoints for warehouse dashboard functionality.
 * Provides data for warehouse inventory management with replenishment calculations.
 * 
 * Requirements: 1, 2, 9, 10
 * 
 * Endpoints:
 * - GET /api/warehouse-dashboard.php?action=dashboard - Get dashboard data
 * - GET /api/warehouse-dashboard.php?action=export - Export to CSV
 * - GET /api/warehouse-dashboard.php?action=warehouses - Get warehouse list
 * - GET /api/warehouse-dashboard.php?action=clusters - Get cluster list
 */

// Set headers
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-API-Key');

// Handle OPTIONS request for CORS
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

// Only allow GET requests
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode([
        'success' => false,
        'error' => 'Method not allowed. Only GET requests are supported.'
    ]);
    exit();
}

// Load database configuration
require_once __DIR__ . '/../config/database_postgresql.php';

// Load controller
require_once __DIR__ . '/classes/WarehouseController.php';

try {
    // Get database connection
    $pdo = getDatabaseConnection();
    
    // Create controller instance
    $controller = new WarehouseController($pdo);
    
    // Get action from query parameter
    $action = $_GET['action'] ?? 'dashboard';
    
    // Route to appropriate controller method
    switch ($action) {
        case 'dashboard':
            // GET /api/warehouse-dashboard.php?action=dashboard
            // Returns warehouse dashboard data with filters
            $controller->getDashboard();
            break;
            
        case 'export':
            // GET /api/warehouse-dashboard.php?action=export
            // Exports dashboard data to CSV
            $controller->export();
            break;
            
        case 'warehouses':
            // GET /api/warehouse-dashboard.php?action=warehouses
            // Returns list of all warehouses
            $controller->getWarehouses();
            break;
            
        case 'clusters':
            // GET /api/warehouse-dashboard.php?action=clusters
            // Returns list of all warehouse clusters
            $controller->getClusters();
            break;
            
        default:
            // Unknown action
            http_response_code(400);
            echo json_encode([
                'success' => false,
                'error' => 'Invalid action. Supported actions: dashboard, export, warehouses, clusters'
            ]);
            break;
    }
    
} catch (Exception $e) {
    // Log error
    error_log("Warehouse Dashboard API Error: " . $e->getMessage());
    error_log("Stack trace: " . $e->getTraceAsString());
    
    // Return error response
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Internal server error',
        'message' => $e->getMessage()
    ]);
}

?>
