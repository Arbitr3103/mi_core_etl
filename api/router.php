<?php
/**
 * Router for PHP built-in server
 * Handles routing for subdirectories like /inventory/detailed-stock
 */

$uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

// Remove leading slash and 'api/' prefix if present
$uri = ltrim($uri, '/');
$uri = preg_replace('#^api/#', '', $uri);

// Map routes to files
$routes = [
    'inventory/detailed-stock' => 'inventory/detailed-stock.php',
    'inventory/summary' => 'inventory/summary.php',
    'inventory/warehouses' => 'inventory/warehouses.php',
    'analytics/overview' => 'analytics/overview.php',
    'analytics/trends' => 'analytics/trends.php',
];

// Check if route exists
if (isset($routes[$uri])) {
    $file = __DIR__ . '/' . $routes[$uri];
    if (file_exists($file)) {
        require $file;
        return true;
    }
}

// Try direct file access
$file = __DIR__ . '/' . $uri;
if (file_exists($file)) {
    // Serve static files or PHP files
    if (pathinfo($file, PATHINFO_EXTENSION) === 'php') {
        require $file;
        return true;
    } else {
        return false; // Let PHP serve static files
    }
}

// If no route found, try adding .php extension
if (file_exists($file . '.php')) {
    require $file . '.php';
    return true;
}

// 404 - Not Found
http_response_code(404);
header('Content-Type: application/json');
echo json_encode([
    'success' => false,
    'error' => 'Endpoint not found',
    'requested_uri' => $uri,
    'timestamp' => date('Y-m-d H:i:s')
]);
return true;
