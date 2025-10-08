<?php

/**
 * MDM System Entry Point
 * Routes requests to appropriate controllers
 */

// Enable error reporting for development
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Set timezone
date_default_timezone_set('Europe/Moscow');

// Autoloader for MDM classes
spl_autoload_register(function ($class) {
    // Convert namespace to file path
    $classPath = str_replace(['MDM\\', '\\'], ['', '/'], $class);
    $file = __DIR__ . '/' . $classPath . '.php';
    
    if (file_exists($file)) {
        require_once $file;
    }
});

// Simple router
$requestUri = $_SERVER['REQUEST_URI'];
$requestMethod = $_SERVER['REQUEST_METHOD'];

// Remove query string
$path = parse_url($requestUri, PHP_URL_PATH);

// Remove base path if exists
$basePath = '/mdm';
if (strpos($path, $basePath) === 0) {
    $path = substr($path, strlen($basePath));
}

// Default to dashboard if no path
if (empty($path) || $path === '/') {
    $path = '/dashboard';
}

try {
    switch ($path) {
        case '/dashboard':
            $controller = new MDM\Controllers\DashboardController();
            $controller->index();
            break;
            
        case '/dashboard/data':
            if ($requestMethod === 'GET') {
                $controller = new MDM\Controllers\DashboardController();
                $controller->getDashboardDataAjax();
            } else {
                http_response_code(405);
                echo json_encode(['error' => 'Method not allowed']);
            }
            break;
            
        case '/verification':
            $controller = new MDM\Controllers\VerificationController();
            $controller->index();
            break;
            
        case '/verification/items':
            if ($requestMethod === 'GET') {
                $controller = new MDM\Controllers\VerificationController();
                $controller->getPendingItemsAjax();
            } else {
                http_response_code(405);
                echo json_encode(['error' => 'Method not allowed']);
            }
            break;
            
        case '/verification/details':
            if ($requestMethod === 'GET') {
                $controller = new MDM\Controllers\VerificationController();
                $controller->getProductDetails();
            } else {
                http_response_code(405);
                echo json_encode(['error' => 'Method not allowed']);
            }
            break;
            
        case '/verification/approve':
            if ($requestMethod === 'POST') {
                $controller = new MDM\Controllers\VerificationController();
                $controller->approveMatch();
            } else {
                http_response_code(405);
                echo json_encode(['error' => 'Method not allowed']);
            }
            break;
            
        case '/verification/reject':
            if ($requestMethod === 'POST') {
                $controller = new MDM\Controllers\VerificationController();
                $controller->rejectMatch();
            } else {
                http_response_code(405);
                echo json_encode(['error' => 'Method not allowed']);
            }
            break;
            
        case '/verification/create-master':
            if ($requestMethod === 'POST') {
                $controller = new MDM\Controllers\VerificationController();
                $controller->createNewMaster();
            } else {
                http_response_code(405);
                echo json_encode(['error' => 'Method not allowed']);
            }
            break;
            
        case '/products':
            $controller = new MDM\Controllers\ProductsController();
            $controller->index();
            break;
            
        case '/products/list':
            if ($requestMethod === 'GET') {
                $controller = new MDM\Controllers\ProductsController();
                $controller->getProductsAjax();
            } else {
                http_response_code(405);
                echo json_encode(['error' => 'Method not allowed']);
            }
            break;
            
        case '/products/details':
            if ($requestMethod === 'GET') {
                $controller = new MDM\Controllers\ProductsController();
                $controller->getProductDetails();
            } else {
                http_response_code(405);
                echo json_encode(['error' => 'Method not allowed']);
            }
            break;
            
        case '/products/update':
            if ($requestMethod === 'POST') {
                $controller = new MDM\Controllers\ProductsController();
                $controller->updateProduct();
            } else {
                http_response_code(405);
                echo json_encode(['error' => 'Method not allowed']);
            }
            break;
            
        case '/products/export':
            if ($requestMethod === 'GET') {
                $controller = new MDM\Controllers\ProductsController();
                $controller->exportProducts();
            } else {
                http_response_code(405);
                echo json_encode(['error' => 'Method not allowed']);
            }
            break;
            
        case '/reports':
            $controller = new MDM\Controllers\ReportsController();
            $controller->index();
            break;
            
        case '/reports/coverage':
            if ($requestMethod === 'GET') {
                $controller = new MDM\Controllers\ReportsController();
                $controller->coverageReport();
            } else {
                http_response_code(405);
                echo json_encode(['error' => 'Method not allowed']);
            }
            break;
            
        case '/reports/incomplete':
            if ($requestMethod === 'GET') {
                $controller = new MDM\Controllers\ReportsController();
                $controller->incompleteDataReport();
            } else {
                http_response_code(405);
                echo json_encode(['error' => 'Method not allowed']);
            }
            break;
            
        case '/reports/problematic':
            if ($requestMethod === 'GET') {
                $controller = new MDM\Controllers\ReportsController();
                $controller->problematicProductsReport();
            } else {
                http_response_code(405);
                echo json_encode(['error' => 'Method not allowed']);
            }
            break;
            
        case '/reports/export':
            if ($requestMethod === 'GET') {
                $controller = new MDM\Controllers\ReportsController();
                $controller->exportReport();
            } else {
                http_response_code(405);
                echo json_encode(['error' => 'Method not allowed']);
            }
            break;
            
        case '/etl/run':
            if ($requestMethod === 'POST') {
                // Placeholder for ETL runner
                header('Content-Type: application/json');
                echo json_encode([
                    'success' => true,
                    'message' => 'ETL process started'
                ]);
            } else {
                http_response_code(405);
                echo json_encode(['error' => 'Method not allowed']);
            }
            break;
            
        default:
            http_response_code(404);
            echo "<h1>404 - Page Not Found</h1>";
            break;
    }
} catch (Exception $e) {
    error_log("MDM Error: " . $e->getMessage());
    
    if (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && $_SERVER['HTTP_X_REQUESTED_WITH'] === 'XMLHttpRequest') {
        // AJAX request
        header('Content-Type: application/json');
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'error' => 'Internal server error'
        ]);
    } else {
        // Regular request
        $errorData = [
            'message' => 'Внутренняя ошибка сервера',
            'details' => $e->getMessage()
        ];
        include __DIR__ . '/Views/error.php';
    }
}