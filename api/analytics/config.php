<?php
/**
 * Regional Analytics API Configuration
 * 
 * Configuration file for the regional sales analytics system.
 * Handles database connections, API settings, and security configurations.
 */

// Load main application config
require_once __DIR__ . '/../../config.php';

// ===================================================================
// ANALYTICS API SETTINGS
// ===================================================================

// API Version and Base Settings
define('ANALYTICS_API_VERSION', '1.0.0');
define('ANALYTICS_API_BASE_PATH', '/api/analytics');

// Cache Settings
define('ANALYTICS_CACHE_TTL', 3600); // 1 hour cache for analytics data
define('ANALYTICS_CACHE_PREFIX', 'regional_analytics_');

// Rate Limiting
define('ANALYTICS_RATE_LIMIT_REQUESTS', 100); // requests per hour per IP
define('ANALYTICS_RATE_LIMIT_WINDOW', 3600); // 1 hour window

// Data Processing Settings
define('ANALYTICS_MAX_DATE_RANGE_DAYS', 365); // Maximum date range for queries
define('ANALYTICS_DEFAULT_DATE_RANGE_DAYS', 30); // Default date range
define('ANALYTICS_MIN_DATE', '2024-01-01'); // Minimum allowed date for analytics

// ===================================================================
// OZON API INTEGRATION SETTINGS
// ===================================================================

// Ozon API Configuration
define('OZON_ANALYTICS_API_URL', 'https://api-seller.ozon.ru/v1/analytics/data');
define('OZON_REGIONS_API_URL', 'https://api-seller.ozon.ru/v1/analytics/regions');

// Request Settings
define('OZON_API_TIMEOUT', 30); // seconds
define('OZON_API_MAX_RETRIES', 3);
define('OZON_API_RETRY_DELAY', 1); // seconds between retries

// Data Sync Settings
define('OZON_SYNC_BATCH_SIZE', 1000); // records per batch
define('OZON_SYNC_DAILY_SCHEDULE', '02:00'); // Daily sync time
define('OZON_SYNC_MAX_EXECUTION_TIME', 1800); // 30 minutes max

// ===================================================================
// SECURITY SETTINGS
// ===================================================================

// API Authentication
define('ANALYTICS_API_KEY_LENGTH', 32);
define('ANALYTICS_API_KEY_EXPIRY_DAYS', 90);

// Allowed Origins for CORS
$ANALYTICS_ALLOWED_ORIGINS = [
    'http://www.market-mi.ru',
    'https://www.market-mi.ru',
    'http://localhost:3000', // For development
    'http://127.0.0.1:3000'  // For development
];

// Input Validation Patterns
$ANALYTICS_VALIDATION_PATTERNS = [
    'date' => '/^\d{4}-\d{2}-\d{2}$/',
    'marketplace' => '/^(ozon|wildberries|all)$/',
    'region_code' => '/^[A-Z]{2}-[A-Z]{3}$/',
    'product_id' => '/^\d+$/',
    'limit' => '/^\d{1,3}$/' // Max 999 records
];

// ===================================================================
// DATABASE SETTINGS
// ===================================================================

// Analytics-specific database settings
define('ANALYTICS_DB_CHARSET', 'utf8mb4');
define('ANALYTICS_DB_COLLATION', 'utf8mb4_unicode_ci');

// Query Optimization
define('ANALYTICS_QUERY_TIMEOUT', 30); // seconds
define('ANALYTICS_MAX_RECORDS_PER_QUERY', 10000);

// ===================================================================
// LOGGING SETTINGS
// ===================================================================

// Analytics Logging
define('ANALYTICS_LOG_LEVEL', 'INFO');
define('ANALYTICS_LOG_FILE', __DIR__ . '/../../logs/analytics.log');
define('ANALYTICS_ERROR_LOG_FILE', __DIR__ . '/../../logs/analytics_errors.log');

// Log Rotation
define('ANALYTICS_LOG_MAX_SIZE', 10 * 1024 * 1024); // 10MB
define('ANALYTICS_LOG_MAX_FILES', 5);

// ===================================================================
// HELPER FUNCTIONS
// ===================================================================

/**
 * Get analytics database connection
 * @return PDO
 */
function getAnalyticsDbConnection() {
    static $pdo = null;
    
    if ($pdo === null) {
        try {
            $dsn = sprintf(
                "mysql:host=%s;port=%s;dbname=%s;charset=%s",
                DB_HOST,
                DB_PORT,
                DB_NAME,
                ANALYTICS_DB_CHARSET
            );
            
            $options = [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
                PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES " . ANALYTICS_DB_CHARSET . " COLLATE " . ANALYTICS_DB_COLLATION
            ];
            
            $pdo = new PDO($dsn, DB_USER, DB_PASSWORD, $options);
            
            // Set query timeout
            $pdo->setAttribute(PDO::ATTR_TIMEOUT, ANALYTICS_QUERY_TIMEOUT);
            
        } catch (PDOException $e) {
            error_log("Analytics DB Connection Error: " . $e->getMessage());
            throw new Exception('Database connection failed for analytics');
        }
    }
    
    return $pdo;
}

/**
 * Validate API input parameters
 * @param string $type Parameter type
 * @param string $value Parameter value
 * @return bool
 */
function validateAnalyticsInput($type, $value) {
    global $ANALYTICS_VALIDATION_PATTERNS;
    
    if (!isset($ANALYTICS_VALIDATION_PATTERNS[$type])) {
        return false;
    }
    
    return preg_match($ANALYTICS_VALIDATION_PATTERNS[$type], $value);
}

/**
 * Log analytics activity
 * @param string $level Log level (INFO, WARNING, ERROR)
 * @param string $message Log message
 * @param array $context Additional context data
 */
function logAnalyticsActivity($level, $message, $context = []) {
    $timestamp = date('Y-m-d H:i:s');
    $contextStr = !empty($context) ? ' | Context: ' . json_encode($context) : '';
    $logEntry = "[$timestamp] [$level] $message$contextStr" . PHP_EOL;
    
    $logFile = ($level === 'ERROR') ? ANALYTICS_ERROR_LOG_FILE : ANALYTICS_LOG_FILE;
    
    // Ensure log directory exists
    $logDir = dirname($logFile);
    if (!is_dir($logDir)) {
        mkdir($logDir, 0755, true);
    }
    
    file_put_contents($logFile, $logEntry, FILE_APPEND | LOCK_EX);
}

/**
 * Set CORS headers for analytics API
 */
function setAnalyticsCorsHeaders() {
    global $ANALYTICS_ALLOWED_ORIGINS;
    
    $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
    
    if (in_array($origin, $ANALYTICS_ALLOWED_ORIGINS)) {
        header("Access-Control-Allow-Origin: $origin");
    }
    
    header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type, Authorization, X-API-Key');
    header('Access-Control-Max-Age: 86400'); // 24 hours
}

/**
 * Send JSON response with proper headers
 * @param array $data Response data
 * @param int $statusCode HTTP status code
 */
function sendAnalyticsJsonResponse($data, $statusCode = 200) {
    http_response_code($statusCode);
    header('Content-Type: application/json; charset=utf-8');
    setAnalyticsCorsHeaders();
    
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    exit;
}

/**
 * Send error response
 * @param string $message Error message
 * @param int $statusCode HTTP status code
 * @param string $errorCode Internal error code
 */
function sendAnalyticsErrorResponse($message, $statusCode = 400, $errorCode = null) {
    $response = [
        'error' => true,
        'message' => $message,
        'timestamp' => date('c')
    ];
    
    if ($errorCode) {
        $response['error_code'] = $errorCode;
    }
    
    logAnalyticsActivity('ERROR', $message, ['status_code' => $statusCode, 'error_code' => $errorCode]);
    sendAnalyticsJsonResponse($response, $statusCode);
}

// ===================================================================
// INITIALIZATION
// ===================================================================

// Set timezone
date_default_timezone_set(TIMEZONE);

// Initialize error handling for analytics
set_error_handler(function($severity, $message, $file, $line) {
    if (error_reporting() & $severity) {
        logAnalyticsActivity('ERROR', "PHP Error: $message in $file:$line");
    }
});

// Log analytics API initialization
if (php_sapi_name() !== 'cli') {
    logAnalyticsActivity('INFO', 'Analytics API initialized', [
        'version' => ANALYTICS_API_VERSION,
        'request_uri' => $_SERVER['REQUEST_URI'] ?? '',
        'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? ''
    ]);
}
?>