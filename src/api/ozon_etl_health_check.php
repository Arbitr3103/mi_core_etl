<?php
/**
 * Ozon ETL Health Check API Endpoint
 * 
 * Provides HTTP endpoint for checking the health status of Ozon Stock Reports ETL system.
 * Can be used by monitoring systems, load balancers, or manual health checks.
 * 
 * Usage:
 *   GET /api/ozon_etl_health_check.php
 *   GET /api/ozon_etl_health_check.php?format=json
 *   GET /api/ozon_etl_health_check.php?detailed=true
 * 
 * @version 1.0
 * @author Manhattan System
 */

// Set headers for API response
header('Content-Type: application/json');
header('Cache-Control: no-cache, must-revalidate');
header('Expires: Mon, 26 Jul 1997 05:00:00 GMT');

// Enable error reporting for debugging
error_reporting(E_ALL);
ini_set('display_errors', 0); // Don't display errors in API response

// Define paths
define('ROOT_DIR', dirname(dirname(__DIR__)));
define('SRC_DIR', ROOT_DIR . '/src');

try {
    // Include required files
    require_once SRC_DIR . '/config/database.php';
    require_once SRC_DIR . '/classes/OzonETLMonitor.php';
    
    // Initialize database connection
    $config = include SRC_DIR . '/config/database.php';
    $dsn = "mysql:host={$config['host']};dbname={$config['database']};charset={$config['charset']}";
    $pdo = new PDO($dsn, $config['username'], $config['password'], $config['options']);
    
    // Initialize monitor
    $monitor = new OzonETLMonitor($pdo);
    
    // Get query parameters
    $detailed = isset($_GET['detailed']) && $_GET['detailed'] === 'true';
    $format = $_GET['format'] ?? 'json';
    
    // Perform health check
    if ($detailed) {
        $healthStatus = $monitor->performHealthCheck();
    } else {
        // Quick health check - just get monitoring status
        $healthStatus = $monitor->getMonitoringStatus();
        $healthStatus['quick_check'] = true;
    }
    
    // Determine HTTP status code based on health
    $httpStatusCode = 200; // OK
    
    if (isset($healthStatus['overall_status'])) {
        switch ($healthStatus['overall_status']) {
            case 'healthy':
                $httpStatusCode = 200; // OK
                break;
            case 'warning':
                $httpStatusCode = 200; // OK but with warnings
                break;
            case 'error':
                $httpStatusCode = 503; // Service Unavailable
                break;
            default:
                $httpStatusCode = 200;
        }
    }
    
    // Add API metadata
    $response = [
        'api' => [
            'name' => 'Ozon ETL Health Check',
            'version' => '1.0',
            'timestamp' => date('Y-m-d H:i:s'),
            'response_time_ms' => round((microtime(true) - $_SERVER['REQUEST_TIME_FLOAT']) * 1000, 2)
        ],
        'health' => $healthStatus
    ];
    
    // Set HTTP status code
    http_response_code($httpStatusCode);
    
    // Return JSON response
    echo json_encode($response, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    
} catch (Exception $e) {
    // Handle errors gracefully
    http_response_code(500);
    
    $errorResponse = [
        'api' => [
            'name' => 'Ozon ETL Health Check',
            'version' => '1.0',
            'timestamp' => date('Y-m-d H:i:s')
        ],
        'error' => [
            'message' => 'Health check failed',
            'details' => $e->getMessage(),
            'code' => $e->getCode()
        ],
        'health' => [
            'overall_status' => 'error',
            'available' => false
        ]
    ];
    
    echo json_encode($errorResponse, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    
    // Log the error
    error_log("[Ozon ETL Health Check API] ERROR: " . $e->getMessage());
}