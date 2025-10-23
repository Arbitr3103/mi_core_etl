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


// Force production environment for this API
$_ENV['APP_ENV'] = 'production';
$_SERVER['APP_ENV'] = 'production';
putenv('APP_ENV=production');

// Force load .env file
$env_file = __DIR__ . '/../.env';
if (file_exists($env_file)) {
    $lines = file($env_file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        if (strpos(trim($line), '#') === 0) continue;
        if (strpos($line, '=') === false) continue;
        
        list($name, $value) = explode('=', $line, 2);
        $name = trim($name);
        $value = trim($value);
        
        if (!array_key_exists($name, $_ENV)) {
            putenv("$name=$value");
            $_ENV[$name] = $value;
            $_SERVER[$name] = $value;
        }
    }
}

// Load production database configuration override
require_once __DIR__ . '/../config/production_db_override.php';

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

// Load production configuration if in production environment
if (isset($_ENV['APP_ENV']) && $_ENV['APP_ENV'] === 'production') {
    try {
        require_once __DIR__ . '/../config/production.php';
        require_once __DIR__ . '/../config/error_logging_production.php';
        $pdo = getProductionPgConnection();
        $logger->info('Warehouse Dashboard API accessed in production mode');
        $error_logger = $GLOBALS['error_logger'];
    } catch (Exception $e) {
        // Fallback to basic configuration if production files are missing
        error_log("Production config failed, falling back to basic config: " . $e->getMessage());
        
        // Try direct connection with hardcoded credentials
        try {
            $dsn = "pgsql:host=localhost;port=5432;dbname=mi_core_db";
            $pdo = new PDO($dsn, 'mi_core_user', 'PostgreSQL_MDM_2025_SecurePass!', [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]);
            $error_logger = null;
        } catch (PDOException $pdo_e) {
            die('PostgreSQL connection error: ' . $pdo_e->getMessage());
        }
    }
} else {
    // Load database configuration for development
    require_once __DIR__ . '/../config/database_postgresql.php';
    $pdo = getDatabaseConnection();
    $error_logger = null;
}

// Load controller
require_once __DIR__ . '/classes/WarehouseController.php';

try {
    
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
    // Log error with production error logger if available
    if ($error_logger) {
        $error_logger->logError('error', 'Warehouse Dashboard API Error: ' . $e->getMessage(), [
            'action' => $action ?? 'unknown',
            'trace' => $e->getTraceAsString(),
            'request_params' => $_GET
        ], $e->getFile(), $e->getLine());
    } else {
        // Fallback to standard error logging
        error_log("Warehouse Dashboard API Error: " . $e->getMessage());
        error_log("Stack trace: " . $e->getTraceAsString());
    }
    
    // Return error response
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Internal server error',
        'message' => isset($_ENV['APP_DEBUG']) && $_ENV['APP_DEBUG'] ? $e->getMessage() : 'An error occurred'
    ]);
}

?>
