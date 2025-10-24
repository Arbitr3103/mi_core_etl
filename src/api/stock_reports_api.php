<?php
/**
 * Stock Reports API Endpoints
 * 
 * Provides access to stock report status and history
 * 
 * @version 1.0
 * @author Manhattan System
 */

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

// Handle preflight OPTIONS requests
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../classes/StockReportsAPI.php';
require_once __DIR__ . '/middleware/AuthenticationMiddleware.php';
require_once __DIR__ . '/middleware/RateLimitMiddleware.php';
require_once __DIR__ . '/../utils/Logger.php';

try {
    // Initialize middleware
    $auth = new AuthenticationMiddleware();
    $rateLimit = new RateLimitMiddleware();
    
    // Authenticate request
    $auth->authenticate();
    
    // Check rate limit
    $clientId = $rateLimit->getClientIdentifier();
    $endpoint = 'stock-reports';
    $rateLimit->checkRateLimit($clientId, $endpoint);
    
    // Load database configuration
    $config = require __DIR__ . '/../config/database.php';
    
    // Create PDO connection
    $dsn = "mysql:host={$config['host']};dbname={$config['database']};charset={$config['charset']}";
    $pdo = new PDO($dsn, $config['username'], $config['password'], $config['options']);
    
    // Initialize API handler
    $api = new StockReportsAPI($pdo);
    
    // Parse request path
    $requestUri = $_SERVER['REQUEST_URI'];
    $path = parse_url($requestUri, PHP_URL_PATH);
    $pathParts = explode('/', trim($path, '/'));
    
    // Remove 'api' and 'stock-reports' from path parts
    $pathParts = array_slice($pathParts, array_search('stock-reports', $pathParts) + 1);
    
    // Route requests based on path
    if (empty($pathParts[0])) {
        // GET /api/stock-reports - Get report history and status
        $response = $api->getStockReports($_GET);
    } elseif (count($pathParts) === 1) {
        // GET /api/stock-reports/{reportCode} - Get specific report details
        $reportCode = urldecode($pathParts[0]);
        $response = $api->getStockReportDetails($reportCode);
    } else {
        throw new Exception('Invalid API endpoint', 404);
    }
    
    // Set appropriate HTTP status code
    http_response_code($response['status'] ?? 200);
    
    // Add rate limit headers
    $rateLimitHeaders = $rateLimit->getRateLimitHeaders($clientId, $endpoint);
    foreach ($rateLimitHeaders as $header => $value) {
        header("$header: $value");
    }
    
    // Return JSON response
    echo json_encode($response, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    
} catch (Exception $e) {
    $statusCode = $e->getCode() ?: 500;
    http_response_code($statusCode);
    
    echo json_encode([
        'success' => false,
        'error' => [
            'message' => $e->getMessage(),
            'code' => $statusCode
        ],
        'timestamp' => date('c')
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
}