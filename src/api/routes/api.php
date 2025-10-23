<?php
/**
 * API Routes for mi_core_etl
 * Defines all API endpoints and their handlers
 */

require_once __DIR__ . '/../InventoryController.php';
require_once __DIR__ . '/../middleware/AuthMiddleware.php';
require_once __DIR__ . '/../middleware/CorsMiddleware.php';
require_once __DIR__ . '/../middleware/RateLimitMiddleware.php';

class ApiRouter {
    private $routes = [];
    private $middleware = [];
    private $logger;
    
    public function __construct() {
        require_once __DIR__ . '/../../utils/Logger.php';
        $this->logger = Logger::getInstance();
        $this->setupMiddleware();
        $this->setupRoutes();
    }
    
    /**
     * Setup middleware stack
     */
    private function setupMiddleware() {
        $this->middleware = [
            new CorsMiddleware(),
            new RateLimitMiddleware(),
            new AuthMiddleware()
        ];
    }
    
    /**
     * Setup API routes
     */
    private function setupRoutes() {
        $inventoryController = new InventoryController();
        
        // Inventory routes
        $this->get('/api/inventory/dashboard', [$inventoryController, 'getDashboardData']);
        $this->get('/api/inventory/product/{sku}', [$inventoryController, 'getProductBySku']);
        $this->get('/api/inventory/warehouses', [$inventoryController, 'getWarehouses']);
        $this->get('/api/inventory/warehouse/{id}', [$inventoryController, 'getInventoryByWarehouse']);
        $this->get('/api/inventory/search', [$inventoryController, 'searchProducts']);
        $this->get('/api/inventory/alerts/low-stock', [$inventoryController, 'getLowStockAlerts']);
        $this->get('/api/inventory/analytics', [$inventoryController, 'getInventoryAnalytics']);
        $this->get('/api/inventory/statistics', [$inventoryController, 'getInventoryStatistics']);
        $this->get('/api/inventory/movements/{productId}', [$inventoryController, 'getStockMovements']);
        
        // Inventory management routes
        $this->post('/api/inventory/update', [$inventoryController, 'updateInventoryQuantity']);
        $this->post('/api/inventory/bulk-update', [$inventoryController, 'bulkUpdateInventory']);
        $this->delete('/api/inventory/cache', [$inventoryController, 'clearCache']);
        
        // Warehouse dashboard routes
        require_once __DIR__ . '/../../api/classes/WarehouseController.php';
        $warehouseController = new WarehouseController($this->getDbConnection());
        $this->get('/api/warehouse/dashboard', [$warehouseController, 'getDashboard']);
        $this->get('/api/warehouse/export', [$warehouseController, 'export']);
        $this->get('/api/warehouse/warehouses', [$warehouseController, 'getWarehouses']);
        $this->get('/api/warehouse/clusters', [$warehouseController, 'getClusters']);
        
        // Health check routes (no auth required)
        $this->get('/api/health', [$inventoryController, 'healthCheck']);
        $this->get('/api/status', [$this, 'getSystemStatus']);
        
        // API documentation
        $this->get('/api/docs', [$this, 'getApiDocumentation']);
    }
    
    /**
     * Register GET route
     */
    private function get($path, $handler) {
        $this->routes['GET'][$path] = $handler;
    }
    
    /**
     * Register POST route
     */
    private function post($path, $handler) {
        $this->routes['POST'][$path] = $handler;
    }
    
    /**
     * Register PUT route
     */
    private function put($path, $handler) {
        $this->routes['PUT'][$path] = $handler;
    }
    
    /**
     * Register DELETE route
     */
    private function delete($path, $handler) {
        $this->routes['DELETE'][$path] = $handler;
    }
    
    /**
     * Handle incoming request
     */
    public function handleRequest() {
        try {
            $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
            $path = $this->getRequestPath();
            
            $this->logger->info('API request received', [
                'method' => $method,
                'path' => $path,
                'client_ip' => $this->getClientIp(),
                'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? 'unknown'
            ]);
            
            // Apply middleware
            $this->applyMiddleware();
            
            // Find and execute route
            $handler = $this->findRoute($method, $path);
            if ($handler) {
                $this->executeHandler($handler, $path);
            } else {
                $this->notFound();
            }
            
        } catch (Exception $e) {
            $this->handleError($e);
        }
    }
    
    /**
     * Get request path
     */
    private function getRequestPath() {
        $path = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH);
        return rtrim($path, '/') ?: '/';
    }
    
    /**
     * Apply middleware stack
     */
    private function applyMiddleware() {
        foreach ($this->middleware as $middleware) {
            $middleware->handle($_REQUEST, function($request) {
                // Continue to next middleware
                return $request;
            });
        }
    }
    
    /**
     * Find route handler
     */
    private function findRoute($method, $path) {
        if (!isset($this->routes[$method])) {
            return null;
        }
        
        foreach ($this->routes[$method] as $routePath => $handler) {
            if ($this->matchRoute($routePath, $path)) {
                return $handler;
            }
        }
        
        return null;
    }
    
    /**
     * Match route with parameters
     */
    private function matchRoute($routePath, $requestPath) {
        // Convert route parameters to regex
        $pattern = preg_replace('/\{([^}]+)\}/', '([^/]+)', $routePath);
        $pattern = str_replace('/', '\/', $pattern);
        
        return preg_match('/^' . $pattern . '$/', $requestPath);
    }
    
    /**
     * Execute route handler
     */
    private function executeHandler($handler, $path) {
        $startTime = microtime(true);
        
        if (is_array($handler) && count($handler) === 2) {
            list($controller, $method) = $handler;
            
            // Extract route parameters
            $params = $this->extractRouteParams($path);
            
            // Call controller method with parameters
            if (method_exists($controller, $method)) {
                $result = $this->callControllerMethod($controller, $method, $params);
                $this->sendJsonResponse($result);
            } else {
                throw new Exception("Method {$method} not found in controller");
            }
        } else {
            throw new Exception("Invalid route handler");
        }
        
        $duration = microtime(true) - $startTime;
        $this->logger->performance('API request completed', $duration, [
            'path' => $path,
            'method' => $_SERVER['REQUEST_METHOD'] ?? 'GET'
        ]);
    }
    
    /**
     * Extract route parameters from path
     */
    private function extractRouteParams($path) {
        $params = [];
        
        // This is a simplified parameter extraction
        // In a real router, you'd match against the route pattern
        $pathParts = explode('/', trim($path, '/'));
        
        // Extract common parameters
        if (count($pathParts) >= 4 && $pathParts[2] === 'product') {
            $params['sku'] = $pathParts[3];
        }
        
        if (count($pathParts) >= 4 && $pathParts[2] === 'warehouse') {
            $params['id'] = $pathParts[3];
        }
        
        if (count($pathParts) >= 4 && $pathParts[2] === 'movements') {
            $params['productId'] = $pathParts[3];
        }
        
        return $params;
    }
    
    /**
     * Call controller method with appropriate parameters
     */
    private function callControllerMethod($controller, $method, $params) {
        switch ($method) {
            case 'getDashboardData':
                $limit = $_GET['limit'] ?? null;
                return $controller->getDashboardData($limit);
                
            case 'getProductBySku':
                return $controller->getProductBySku($params['sku'] ?? '');
                
            case 'getInventoryByWarehouse':
                $limit = $_GET['limit'] ?? null;
                return $controller->getInventoryByWarehouse($params['id'] ?? 0, $limit);
                
            case 'searchProducts':
                $query = $_GET['q'] ?? $_GET['query'] ?? '';
                $limit = $_GET['limit'] ?? 20;
                return $controller->searchProducts($query, $limit);
                
            case 'getLowStockAlerts':
                $threshold = $_GET['threshold'] ?? 20;
                return $controller->getLowStockAlerts($threshold);
                
            case 'getStockMovements':
                $limit = $_GET['limit'] ?? 50;
                return $controller->getStockMovements($params['productId'] ?? 0, $limit);
                
            case 'updateInventoryQuantity':
                $data = $this->getJsonInput();
                return $controller->updateInventoryQuantity(
                    $data['product_id'] ?? 0,
                    $data['warehouse_name'] ?? '',
                    $data['quantity'] ?? 0,
                    $data['source'] ?? 'api'
                );
                
            case 'bulkUpdateInventory':
                $data = $this->getJsonInput();
                return $controller->bulkUpdateInventory($data['updates'] ?? []);
                
            default:
                return $controller->$method();
        }
    }
    
    /**
     * Get JSON input from request body
     */
    private function getJsonInput() {
        $input = file_get_contents('php://input');
        $data = json_decode($input, true);
        
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new Exception('Invalid JSON input: ' . json_last_error_msg());
        }
        
        return $data ?: [];
    }
    
    /**
     * Send JSON response
     */
    private function sendJsonResponse($data, $statusCode = 200) {
        http_response_code($statusCode);
        header('Content-Type: application/json');
        
        $response = [
            'success' => true,
            'data' => $data,
            'timestamp' => date('Y-m-d H:i:s')
        ];
        
        echo json_encode($response, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
    
    /**
     * Handle 404 Not Found
     */
    private function notFound() {
        http_response_code(404);
        header('Content-Type: application/json');
        
        $response = [
            'success' => false,
            'error' => 'Endpoint not found',
            'path' => $this->getRequestPath(),
            'method' => $_SERVER['REQUEST_METHOD'] ?? 'GET',
            'timestamp' => date('Y-m-d H:i:s')
        ];
        
        echo json_encode($response, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
    
    /**
     * Handle errors
     */
    private function handleError($exception) {
        $statusCode = 500;
        
        // Determine status code based on exception type
        if (strpos($exception->getMessage(), 'not found') !== false) {
            $statusCode = 404;
        } elseif (strpos($exception->getMessage(), 'Invalid') !== false) {
            $statusCode = 400;
        }
        
        http_response_code($statusCode);
        header('Content-Type: application/json');
        
        $response = [
            'success' => false,
            'error' => $exception->getMessage(),
            'timestamp' => date('Y-m-d H:i:s')
        ];
        
        // Add debug info in development
        if (($_ENV['APP_DEBUG'] ?? false) === 'true') {
            $response['debug'] = [
                'file' => $exception->getFile(),
                'line' => $exception->getLine(),
                'trace' => $exception->getTraceAsString()
            ];
        }
        
        $this->logger->error('API error', [
            'error' => $exception->getMessage(),
            'file' => $exception->getFile(),
            'line' => $exception->getLine(),
            'path' => $this->getRequestPath(),
            'method' => $_SERVER['REQUEST_METHOD'] ?? 'GET'
        ]);
        
        echo json_encode($response, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
    
    /**
     * Get system status
     */
    public function getSystemStatus() {
        require_once __DIR__ . '/../../utils/Database.php';
        require_once __DIR__ . '/../../services/CacheService.php';
        
        $db = Database::getInstance();
        $cache = CacheService::getInstance();
        
        return [
            'status' => 'ok',
            'version' => '1.0.0',
            'timestamp' => date('Y-m-d H:i:s'),
            'database' => $db->getDatabaseInfo(),
            'cache' => $cache->getStats(),
            'memory_usage' => memory_get_usage(true),
            'memory_peak' => memory_get_peak_usage(true)
        ];
    }
    
    /**
     * Get API documentation
     */
    public function getApiDocumentation() {
        return [
            'title' => 'mi_core_etl API',
            'version' => '1.0.0',
            'description' => 'Inventory management API for mi_core_etl system',
            'endpoints' => [
                'GET /api/inventory/dashboard' => 'Get dashboard data with stock status',
                'GET /api/inventory/product/{sku}' => 'Get product details by SKU',
                'GET /api/inventory/warehouses' => 'Get list of warehouses',
                'GET /api/inventory/warehouse/{id}' => 'Get inventory for specific warehouse',
                'GET /api/inventory/search?q={query}' => 'Search products by name or SKU',
                'GET /api/inventory/alerts/low-stock' => 'Get low stock alerts',
                'GET /api/inventory/analytics' => 'Get inventory analytics',
                'GET /api/inventory/statistics' => 'Get inventory statistics',
                'POST /api/inventory/update' => 'Update inventory quantity',
                'POST /api/inventory/bulk-update' => 'Bulk update inventory',
                'DELETE /api/inventory/cache' => 'Clear inventory caches',
                'GET /api/warehouse/dashboard' => 'Get warehouse dashboard with replenishment calculations',
                'GET /api/warehouse/export' => 'Export warehouse dashboard data to CSV',
                'GET /api/warehouse/warehouses' => 'Get list of all warehouses with product counts',
                'GET /api/warehouse/clusters' => 'Get list of all warehouse clusters',
                'GET /api/health' => 'Health check endpoint',
                'GET /api/status' => 'System status endpoint'
            ],
            'authentication' => [
                'api_key' => 'Use X-API-Key header',
                'basic_auth' => 'Use Authorization: Basic header'
            ]
        ];
    }
    
    /**
     * Get client IP address
     */
    private function getClientIp() {
        $ipKeys = ['HTTP_X_FORWARDED_FOR', 'HTTP_X_REAL_IP', 'HTTP_CLIENT_IP', 'REMOTE_ADDR'];
        
        foreach ($ipKeys as $key) {
            if (!empty($_SERVER[$key])) {
                $ips = explode(',', $_SERVER[$key]);
                return trim($ips[0]);
            }
        }
        
        return 'unknown';
    }
    
    /**
     * Get database connection
     */
    private function getDbConnection() {
        require_once __DIR__ . '/../../utils/Database.php';
        $db = Database::getInstance();
        return $db->getConnection();
    }
}