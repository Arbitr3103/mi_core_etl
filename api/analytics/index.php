<?php
/**
 * Regional Analytics API Router
 * 
 * Main entry point for the regional sales analytics API.
 * Handles routing, authentication, and request processing.
 */

require_once __DIR__ . '/config.php';

// Handle preflight OPTIONS requests for CORS
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    setAnalyticsCorsHeaders();
    http_response_code(200);
    exit;
}

// Set CORS headers for all requests
setAnalyticsCorsHeaders();

// Get request path and method
$requestUri = $_SERVER['REQUEST_URI'] ?? '';
$requestMethod = $_SERVER['REQUEST_METHOD'] ?? 'GET';

// Remove query string and base path
$path = parse_url($requestUri, PHP_URL_PATH);
$basePath = ANALYTICS_API_BASE_PATH;

// Remove base path from request path
if (strpos($path, $basePath) === 0) {
    $path = substr($path, strlen($basePath));
}

// Remove leading slash
$path = ltrim($path, '/');

// Split path into segments
$pathSegments = array_filter(explode('/', $path));

try {
    // Route requests based on path
    switch (true) {
        case empty($pathSegments):
            // API root - return API information
            handleApiRoot();
            break;
            
        case $pathSegments[0] === 'marketplace-comparison':
            // Marketplace comparison endpoint
            require_once __DIR__ . '/endpoints/marketplace-comparison.php';
            handleMarketplaceComparison();
            break;
            
        case $pathSegments[0] === 'top-products':
            // Top products endpoint
            require_once __DIR__ . '/endpoints/top-products.php';
            handleTopProducts();
            break;
            
        case $pathSegments[0] === 'sales-dynamics':
            // Sales dynamics endpoint
            require_once __DIR__ . '/endpoints/sales-dynamics.php';
            handleSalesDynamics();
            break;
            
        case $pathSegments[0] === 'regions':
            // Regions data endpoint
            require_once __DIR__ . '/endpoints/regions.php';
            handleRegions();
            break;
            
        case $pathSegments[0] === 'health':
            // Health check endpoint
            handleHealthCheck();
            break;
            
        default:
            // Unknown endpoint
            sendAnalyticsErrorResponse('Endpoint not found', 404, 'ENDPOINT_NOT_FOUND');
    }
    
} catch (Exception $e) {
    logAnalyticsActivity('ERROR', 'API Error: ' . $e->getMessage(), [
        'path' => $path,
        'method' => $requestMethod,
        'trace' => $e->getTraceAsString()
    ]);
    
    sendAnalyticsErrorResponse('Internal server error', 500, 'INTERNAL_ERROR');
}

/**
 * Handle API root request - return API information
 */
function handleApiRoot() {
    $response = [
        'name' => 'Regional Sales Analytics API',
        'version' => ANALYTICS_API_VERSION,
        'status' => 'active',
        'timestamp' => date('c'),
        'endpoints' => [
            'marketplace-comparison' => [
                'method' => 'GET',
                'description' => 'Compare sales performance between marketplaces',
                'parameters' => ['date_from', 'date_to', 'marketplace']
            ],
            'top-products' => [
                'method' => 'GET', 
                'description' => 'Get top performing products by marketplace',
                'parameters' => ['date_from', 'date_to', 'marketplace', 'limit']
            ],
            'sales-dynamics' => [
                'method' => 'GET',
                'description' => 'Get sales trends and dynamics over time',
                'parameters' => ['date_from', 'date_to', 'marketplace', 'period']
            ],
            'regions' => [
                'method' => 'GET',
                'description' => 'Get regional sales data and statistics',
                'parameters' => ['date_from', 'date_to', 'region_code']
            ],
            'health' => [
                'method' => 'GET',
                'description' => 'API health check and status'
            ]
        ]
    ];
    
    sendAnalyticsJsonResponse($response);
}

/**
 * Handle health check request
 */
function handleHealthCheck() {
    try {
        // Test database connection
        $pdo = getAnalyticsDbConnection();
        $stmt = $pdo->query('SELECT 1');
        $dbStatus = $stmt ? 'healthy' : 'error';
        
        // Check required tables
        $requiredTables = ['ozon_regional_sales', 'regions', 'regional_analytics_cache'];
        $tablesStatus = [];
        
        foreach ($requiredTables as $table) {
            $stmt = $pdo->query("SHOW TABLES LIKE '$table'");
            $tablesStatus[$table] = $stmt->rowCount() > 0 ? 'exists' : 'missing';
        }
        
        $overallStatus = ($dbStatus === 'healthy' && !in_array('missing', $tablesStatus)) ? 'healthy' : 'degraded';
        
        $response = [
            'status' => $overallStatus,
            'timestamp' => date('c'),
            'version' => ANALYTICS_API_VERSION,
            'database' => $dbStatus,
            'tables' => $tablesStatus,
            'uptime' => sys_getloadavg()[0] // System load as uptime indicator
        ];
        
        $statusCode = ($overallStatus === 'healthy') ? 200 : 503;
        sendAnalyticsJsonResponse($response, $statusCode);
        
    } catch (Exception $e) {
        sendAnalyticsJsonResponse([
            'status' => 'error',
            'timestamp' => date('c'),
            'error' => $e->getMessage()
        ], 503);
    }
}
?>