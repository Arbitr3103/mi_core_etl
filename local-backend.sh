#!/bin/bash

# Local Backend Server for Warehouse Dashboard
# Runs PHP built-in server on port 8080

set -e

# Colors
GREEN='\033[0;32m'
BLUE='\033[0;34m'
YELLOW='\033[1;33m'
NC='\033[0m'

echo -e "${BLUE}=========================================="
echo "Starting Backend Server (PHP)"
echo -e "==========================================${NC}"
echo ""

# Load environment variables
if [ -f ".env.local" ]; then
    export $(cat .env.local | grep -v '^#' | xargs)
    echo -e "${GREEN}[✓]${NC} Loaded .env.local"
fi

# Check if port 8080 is available
if lsof -Pi :8080 -sTCP:LISTEN -t >/dev/null ; then
    echo -e "${YELLOW}[!]${NC} Port 8080 is already in use"
    echo "    Kill the process or use a different port"
    exit 1
fi

# Create a simple router for the API
cat > api-router.php <<'EOF'
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
EOF

echo -e "${GREEN}[✓]${NC} Created API router"
echo ""
echo -e "${BLUE}Backend server starting...${NC}"
echo "  URL: http://localhost:8080"
echo "  API: http://localhost:8080/api/inventory/detailed-stock"
echo ""
echo -e "${YELLOW}Press Ctrl+C to stop${NC}"
echo ""

# Start PHP built-in server
php -S localhost:8080 api-router.php
