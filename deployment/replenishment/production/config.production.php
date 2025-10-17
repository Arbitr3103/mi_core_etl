<?php
/**
 * Production Configuration for Replenishment System
 * 
 * This file contains production-specific configuration settings.
 * Copy this file to config.php and update the values for your environment.
 */

// ============================================================================
// DATABASE CONFIGURATION
// ============================================================================

// Primary database connection
define('DB_HOST', 'localhost');
define('DB_NAME', 'mi_core');
define('DB_USER', 'replenishment_user');
define('DB_PASSWORD', 'CHANGE_THIS_PASSWORD');
define('DB_PORT', 3306);
define('DB_CHARSET', 'utf8mb4');

// Database connection options
define('DB_OPTIONS', [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES => false,
    PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci"
]);

// Connection pool settings
define('DB_MAX_CONNECTIONS', 10);
define('DB_CONNECTION_TIMEOUT', 30);
define('DB_QUERY_TIMEOUT', 60);

// ============================================================================
// REPLENISHMENT SYSTEM CONFIGURATION
// ============================================================================

// System settings
define('REPLENISHMENT_ENABLED', true);
define('REPLENISHMENT_DEBUG', false);
define('REPLENISHMENT_LOG_LEVEL', 'info'); // debug, info, warning, error

// Performance settings
define('REPLENISHMENT_MEMORY_LIMIT', '512M');
define('REPLENISHMENT_MAX_EXECUTION_TIME', 300); // 5 minutes
define('REPLENISHMENT_BATCH_SIZE', 100);

// Cache settings
define('CACHE_ENABLED', true);
define('CACHE_TTL', 3600); // 1 hour
define('CACHE_PREFIX', 'replenishment_');

// ============================================================================
// EMAIL CONFIGURATION
// ============================================================================

// SMTP settings for email reports
define('SMTP_ENABLED', true);
define('SMTP_HOST', 'smtp.gmail.com');
define('SMTP_PORT', 587);
define('SMTP_USERNAME', 'your-email@company.com');
define('SMTP_PASSWORD', 'your-app-password');
define('SMTP_ENCRYPTION', 'tls'); // tls or ssl
define('SMTP_FROM_EMAIL', 'noreply@company.com');
define('SMTP_FROM_NAME', 'Replenishment System');

// Email report settings
define('EMAIL_REPORTS_ENABLED', true);
define('EMAIL_REPORT_RECIPIENTS', [
    'manager@company.com',
    'procurement@company.com'
]);

// ============================================================================
// API CONFIGURATION
// ============================================================================

// API settings
define('API_ENABLED', true);
define('API_DEBUG', false);
define('API_RATE_LIMIT', 100); // requests per minute
define('API_RATE_WINDOW', 60); // seconds

// API authentication (set to true for production)
define('API_KEY_REQUIRED', false);
define('API_KEYS', [
    'production-api-key-1',
    'dashboard-api-key-2'
]);

// CORS settings
define('API_CORS_ENABLED', true);
define('API_CORS_ORIGINS', [
    'https://your-domain.com',
    'https://dashboard.your-domain.com'
]);

// ============================================================================
// REDIS CONFIGURATION (OPTIONAL)
// ============================================================================

// Redis cache settings
define('REDIS_ENABLED', false);
define('REDIS_HOST', 'localhost');
define('REDIS_PORT', 6379);
define('REDIS_PASSWORD', '');
define('REDIS_DATABASE', 0);
define('REDIS_TIMEOUT', 5);

// ============================================================================
// LOGGING CONFIGURATION
// ============================================================================

// Log file paths
define('LOG_DIR', __DIR__ . '/logs/replenishment');
define('LOG_FILE_CALCULATION', LOG_DIR . '/calculation.log');
define('LOG_FILE_ERROR', LOG_DIR . '/error.log');
define('LOG_FILE_API', LOG_DIR . '/api.log');
define('LOG_FILE_PERFORMANCE', LOG_DIR . '/performance.log');

// Log rotation settings
define('LOG_MAX_SIZE', 10 * 1024 * 1024); // 10MB
define('LOG_MAX_FILES', 10);

// ============================================================================
// SECURITY CONFIGURATION
// ============================================================================

// Security settings
define('SECURITY_ENABLED', true);
define('SECURITY_IP_WHITELIST', [
    '127.0.0.1',
    '::1',
    // Add your server IPs here
]);

// Session settings
define('SESSION_SECURE', true); // Set to true if using HTTPS
define('SESSION_HTTPONLY', true);
define('SESSION_SAMESITE', 'Strict');

// ============================================================================
// MONITORING CONFIGURATION
// ============================================================================

// Health check settings
define('HEALTH_CHECK_ENABLED', true);
define('HEALTH_CHECK_TIMEOUT', 5);

// Performance monitoring
define('PERFORMANCE_MONITORING', true);
define('PERFORMANCE_SLOW_QUERY_THRESHOLD', 2); // seconds

// Alert settings
define('ALERTS_ENABLED', true);
define('ALERT_EMAIL', 'admin@company.com');
define('ALERT_WEBHOOK_URL', ''); // Slack webhook URL

// ============================================================================
// ENVIRONMENT SPECIFIC SETTINGS
// ============================================================================

// Environment
define('ENVIRONMENT', 'production');
define('DEBUG_MODE', false);

// Error reporting
error_reporting(E_ERROR | E_WARNING | E_PARSE);
ini_set('display_errors', 0);
ini_set('log_errors', 1);
ini_set('error_log', LOG_DIR . '/php_errors.log');

// PHP settings
ini_set('memory_limit', REPLENISHMENT_MEMORY_LIMIT);
ini_set('max_execution_time', REPLENISHMENT_MAX_EXECUTION_TIME);

// Timezone
date_default_timezone_set('Europe/Moscow');

// ============================================================================
// BUSINESS LOGIC CONFIGURATION
// ============================================================================

// Default calculation parameters (can be overridden in database)
define('DEFAULT_REPLENISHMENT_DAYS', 14);
define('DEFAULT_SAFETY_DAYS', 7);
define('DEFAULT_ANALYSIS_DAYS', 30);
define('DEFAULT_MIN_ADS_THRESHOLD', 0.1);
define('DEFAULT_MAX_RECOMMENDATION_QUANTITY', 10000);

// Business rules
define('EXCLUDE_ZERO_STOCK_DAYS', true);
define('MINIMUM_SALES_HISTORY_DAYS', 7);
define('MAXIMUM_ADS_VALUE', 1000);

// ============================================================================
// INTEGRATION SETTINGS
// ============================================================================

// External API settings (if needed)
define('OZON_API_ENABLED', false);
define('OZON_CLIENT_ID', '');
define('OZON_API_KEY', '');

define('WB_API_ENABLED', false);
define('WB_API_KEY', '');

// ============================================================================
// BACKUP CONFIGURATION
// ============================================================================

// Backup settings
define('BACKUP_ENABLED', true);
define('BACKUP_DIR', '/var/backups/replenishment');
define('BACKUP_RETENTION_DAYS', 30);
define('BACKUP_COMPRESS', true);

// ============================================================================
// UTILITY FUNCTIONS
// ============================================================================

/**
 * Get database connection
 */
function getDbConnection() {
    static $pdo = null;
    
    if ($pdo === null) {
        $dsn = sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=%s',
            DB_HOST,
            DB_PORT,
            DB_NAME,
            DB_CHARSET
        );
        
        $pdo = new PDO($dsn, DB_USER, DB_PASSWORD, DB_OPTIONS);
    }
    
    return $pdo;
}

/**
 * Log message to file
 */
function logMessage($message, $level = 'info', $file = null) {
    if (!REPLENISHMENT_DEBUG && $level === 'debug') {
        return;
    }
    
    $file = $file ?: LOG_FILE_CALCULATION;
    $timestamp = date('Y-m-d H:i:s');
    $logEntry = "[$timestamp] [$level] $message" . PHP_EOL;
    
    // Ensure log directory exists
    $logDir = dirname($file);
    if (!is_dir($logDir)) {
        mkdir($logDir, 0755, true);
    }
    
    file_put_contents($file, $logEntry, FILE_APPEND | LOCK_EX);
}

/**
 * Send email notification
 */
function sendEmailNotification($subject, $body, $recipients = null) {
    if (!EMAIL_REPORTS_ENABLED) {
        return false;
    }
    
    $recipients = $recipients ?: EMAIL_REPORT_RECIPIENTS;
    
    // Implementation depends on your email system
    // This is a placeholder - implement according to your needs
    
    return true;
}

/**
 * Get configuration parameter from database
 */
function getConfigParameter($name, $default = null) {
    static $cache = [];
    
    if (isset($cache[$name])) {
        return $cache[$name];
    }
    
    try {
        $pdo = getDbConnection();
        $stmt = $pdo->prepare("
            SELECT parameter_value, parameter_type 
            FROM replenishment_config 
            WHERE parameter_name = ? AND is_active = 1
        ");
        $stmt->execute([$name]);
        $result = $stmt->fetch();
        
        if ($result) {
            $value = $result['parameter_value'];
            
            // Type casting
            switch ($result['parameter_type']) {
                case 'int':
                    $value = (int)$value;
                    break;
                case 'float':
                    $value = (float)$value;
                    break;
                case 'boolean':
                    $value = $value === 'true';
                    break;
            }
            
            $cache[$name] = $value;
            return $value;
        }
    } catch (Exception $e) {
        logMessage("Failed to get config parameter $name: " . $e->getMessage(), 'error');
    }
    
    return $default;
}

/**
 * Check if system is healthy
 */
function checkSystemHealth() {
    $checks = [];
    
    // Database check
    try {
        $pdo = getDbConnection();
        $stmt = $pdo->query("SELECT 1");
        $checks['database'] = ['status' => 'healthy', 'message' => 'Connection successful'];
    } catch (Exception $e) {
        $checks['database'] = ['status' => 'unhealthy', 'message' => $e->getMessage()];
    }
    
    // Disk space check
    $freeSpace = disk_free_space(__DIR__);
    $totalSpace = disk_total_space(__DIR__);
    $freePercent = ($freeSpace / $totalSpace) * 100;
    
    if ($freePercent > 20) {
        $checks['disk_space'] = ['status' => 'healthy', 'free_percent' => round($freePercent, 2)];
    } else {
        $checks['disk_space'] = ['status' => 'warning', 'free_percent' => round($freePercent, 2)];
    }
    
    // Memory check
    $memoryUsage = memory_get_usage(true);
    $memoryLimit = ini_get('memory_limit');
    $checks['memory'] = [
        'status' => 'healthy',
        'usage_mb' => round($memoryUsage / 1024 / 1024, 2),
        'limit' => $memoryLimit
    ];
    
    return $checks;
}

// ============================================================================
// INITIALIZATION
// ============================================================================

// Create log directory if it doesn't exist
if (!is_dir(LOG_DIR)) {
    mkdir(LOG_DIR, 0755, true);
}

// Log configuration load
if (REPLENISHMENT_DEBUG) {
    logMessage("Production configuration loaded", 'debug');
}

// Validate critical settings
if (empty(DB_PASSWORD) || DB_PASSWORD === 'CHANGE_THIS_PASSWORD') {
    logMessage("WARNING: Default database password detected. Please update config.php", 'warning');
}

if (EMAIL_REPORTS_ENABLED && empty(SMTP_PASSWORD)) {
    logMessage("WARNING: Email reports enabled but SMTP password not set", 'warning');
}

?>