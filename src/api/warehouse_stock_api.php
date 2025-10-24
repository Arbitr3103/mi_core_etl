<?php
/**
 * Warehouse Stock API Endpoints
 * 
 * Provides REST API access to warehouse stock data from Ozon reports
 * Supports filtering, pagination, and sorting for large datasets
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
require_once __DIR__ . '/../classes/WarehouseStockAPI.php';
require_once __DIR__ . '/middleware/AuthenticationMiddleware.php';
require_once __DIR__ . '/middleware/RateLimitMiddleware.php';
require_once __DIR__ . '/middleware/ResponseCacheMiddleware.php';
require_once __DIR__ . '/middleware/CompressionMiddleware.php';
require_once __DIR__ . '/../utils/Logger.php';

try {
    // Initialize middleware
    $auth = new AuthenticationMiddleware();
    $rateLimit = new RateLimitMiddleware();
    $cache = new ResponseCacheMiddleware();
    $compression = new CompressionMiddleware();
    
    // Authenticate request
    $auth->authenticate();
    
    // Check rate limit
    $clientId = $rateLimit->getClientIdentifier();
    $endpoint = 'warehouse-stock';
    $rateLimit->checkRateLimit($clientId, $endpoint);
    
    // Check cache first
    $cacheKey = $cache->generateCacheKey($endpoint, $_GET);
    $cachedResponse = $cache->getCachedResponse($cacheKey);
    
    // Load database configuration
    $config = require __DIR__ . '/../config/database.php';
    
    // Create PDO connection
    $dsn = "mysql:host={$config['host']};dbname={$config['database']};charset={$config['charset']}";
    $pdo = new PDO($dsn, $config['username'], $config['password'], $config['options']);
    
    // Initialize API handler
    $api = new WarehouseStockAPI($pdo);
    
    // Parse request path
    $requestUri = $_SERVER['REQUEST_URI'];
    $path = parse_url($requestUri, PHP_URL_PATH);
    $pathParts = explode('/', trim($path, '/'));
    
    // Remove 'api' and 'warehouse-stock' from path parts
    $pathParts = array_slice($pathParts, array_search('warehouse-stock', $pathParts) + 1);
    
    if ($cachedResponse) {
        $response = $cachedResponse;
    } else {
        // Route requests based on path
        if (empty($pathParts[0])) {
            // GET /api/warehouse-stock - Get all warehouse stock data with filtering
            $response = $api->getWarehouseStock($_GET);
        } elseif (count($pathParts) === 1) {
            // GET /api/warehouse-stock/{warehouse} - Get stock for specific warehouse
            $warehouse = urldecode($pathParts[0]);
            $response = $api->getWarehouseStockByWarehouse($warehouse, $_GET);
        } else {
            throw new Exception('Invalid API endpoint', 404);
        }
        
        // Cache successful responses
        if ($cache->shouldCache($endpoint, $_GET, $response)) {
            $cache->cacheResponse($cacheKey, $response);
        }
    }
    
    // Set appropriate HTTP status code
    http_response_code($response['status'] ?? 200);
    
    // Add rate limit headers
    $rateLimitHeaders = $rateLimit->getRateLimitHeaders($clientId, $endpoint);
    foreach ($rateLimitHeaders as $header => $value) {
        header("$header: $value");
    }
    
    // Prepare response content
    $responseContent = json_encode($response, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    
    // Apply compression if appropriate
    $processedResponse = $compression->processResponse($responseContent, [
        'Content-Type' => 'application/json; charset=utf-8'
    ]);
    
    // Set compression headers
    foreach ($processedResponse['headers'] as $header => $value) {
        header("$header: $value");
    }
    
    // Return response
    echo $processedResponse['content'];
    
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