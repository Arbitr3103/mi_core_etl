<?php
// Simple router for local development

// Load environment variables
if (file_exists(__DIR__ . '/.env.local')) {
    $lines = file(__DIR__ . '/.env.local', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        if (strpos(trim($line), '#') === 0) continue;
        putenv($line);
        list($key, $value) = explode('=', $line, 2);
        $_ENV[$key] = $value;
        $_SERVER[$key] = $value;
    }
}

// Enable CORS for local development
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// Route to the appropriate API file
$uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

// Log the request
error_log("[" . date('Y-m-d H:i:s') . "] " . $_SERVER['REQUEST_METHOD'] . " " . $uri);

// Route API requests
if (strpos($uri, '/api/inventory/detailed-stock') !== false) {
    require __DIR__ . '/api/inventory/detailed-stock.php';
    exit;
}

// Default: serve static files or return 404
if (file_exists(__DIR__ . $uri)) {
    return false; // Let PHP's built-in server handle it
}

http_response_code(404);
echo json_encode(['error' => 'Not found', 'uri' => $uri]);
?>
