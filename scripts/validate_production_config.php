<?php
/**
 * Validate Production Configuration
 * 
 * This script validates that all required configuration is properly set
 * for the warehouse dashboard production deployment.
 */

// Load production configuration
require_once __DIR__ . '/../config/production.php';

echo "=== Production Configuration Validation ===\n";
echo "Date: " . date('Y-m-d H:i:s') . "\n";
echo "Environment: " . ($_ENV['APP_ENV'] ?? 'unknown') . "\n\n";

$errors = [];
$warnings = [];
$success_count = 0;
$total_checks = 0;

/**
 * Check configuration value
 */
function checkConfig($key, $description, $required = true) {
    global $errors, $warnings, $success_count, $total_checks;
    
    $total_checks++;
    $value = $_ENV[$key] ?? null;
    
    if (empty($value)) {
        if ($required) {
            $errors[] = "$description: $key is not set";
            echo "✗ $description\n";
        } else {
            $warnings[] = "$description: $key is not set (optional)";
            echo "⚠ $description (optional)\n";
        }
    } else {
        $success_count++;
        echo "✓ $description\n";
    }
}

/**
 * Test database connection
 */
function testDatabaseConnection($type, $connection_func) {
    global $errors, $success_count, $total_checks;
    
    $total_checks++;
    
    try {
        $pdo = $connection_func();
        $stmt = $pdo->query("SELECT 1");
        $result = $stmt->fetchColumn();
        
        if ($result == 1) {
            $success_count++;
            echo "✓ $type database connection\n";
            return true;
        } else {
            $errors[] = "$type database connection failed: unexpected result";
            echo "✗ $type database connection\n";
            return false;
        }
    } catch (Exception $e) {
        $errors[] = "$type database connection failed: " . $e->getMessage();
        echo "✗ $type database connection: " . $e->getMessage() . "\n";
        return false;
    }
}

/**
 * Check file permissions
 */
function checkFilePermissions($path, $description, $required_permissions = 'writable') {
    global $errors, $warnings, $success_count, $total_checks;
    
    $total_checks++;
    
    if (!file_exists($path)) {
        // Try to create directory if it doesn't exist
        if ($required_permissions === 'writable' && !is_file($path)) {
            if (@mkdir($path, 0755, true)) {
                $success_count++;
                echo "✓ $description (created)\n";
                return true;
            }
        }
        
        $errors[] = "$description: $path does not exist";
        echo "✗ $description: path does not exist\n";
        return false;
    }
    
    if ($required_permissions === 'writable' && !is_writable($path)) {
        $errors[] = "$description: $path is not writable";
        echo "✗ $description: not writable\n";
        return false;
    }
    
    if ($required_permissions === 'readable' && !is_readable($path)) {
        $errors[] = "$description: $path is not readable";
        echo "✗ $description: not readable\n";
        return false;
    }
    
    $success_count++;
    echo "✓ $description\n";
    return true;
}

// ===================================================================
// APPLICATION SETTINGS
// ===================================================================
echo "=== Application Settings ===\n";
checkConfig('APP_ENV', 'Application environment');
checkConfig('APP_DEBUG', 'Debug mode setting');
checkConfig('VERSION', 'Application version', false);
checkConfig('TIMEZONE', 'Timezone setting', false);
echo "\n";

// ===================================================================
// DATABASE SETTINGS
// ===================================================================
echo "=== Database Settings ===\n";
checkConfig('PG_HOST', 'PostgreSQL host');
checkConfig('PG_USER', 'PostgreSQL user');
checkConfig('PG_PASSWORD', 'PostgreSQL password');
checkConfig('PG_NAME', 'PostgreSQL database name');
checkConfig('PG_PORT', 'PostgreSQL port');

checkConfig('DB_HOST', 'MySQL host', false);
checkConfig('DB_USER', 'MySQL user', false);
checkConfig('DB_PASSWORD', 'MySQL password', false);
checkConfig('DB_NAME', 'MySQL database name', false);
checkConfig('DB_PORT', 'MySQL port', false);
echo "\n";

// ===================================================================
// DATABASE CONNECTIVITY
// ===================================================================
echo "=== Database Connectivity ===\n";
testDatabaseConnection('PostgreSQL', 'getProductionPgConnection');

// Test MySQL if configured
if (!empty($_ENV['DB_USER']) && !empty($_ENV['DB_PASSWORD'])) {
    testDatabaseConnection('MySQL', 'getProductionMysqlConnection');
}
echo "\n";

// ===================================================================
// API KEYS
// ===================================================================
echo "=== API Keys ===\n";
checkConfig('OZON_CLIENT_ID', 'Ozon client ID', false);
checkConfig('OZON_API_KEY', 'Ozon API key', false);
checkConfig('WB_API_KEY', 'Wildberries API key', false);
echo "\n";

// ===================================================================
// SECURITY SETTINGS
// ===================================================================
echo "=== Security Settings ===\n";
checkConfig('JWT_SECRET', 'JWT secret key');
checkConfig('ENCRYPTION_KEY', 'Encryption key');
echo "\n";

// ===================================================================
// LOGGING SETTINGS
// ===================================================================
echo "=== Logging Settings ===\n";
checkConfig('LOG_LEVEL', 'Log level', false);
checkConfig('LOG_PATH', 'Log path');
checkConfig('LOG_MAX_SIZE', 'Log max size', false);
checkConfig('LOG_MAX_FILES', 'Log max files', false);

// Check log directory permissions
if (!empty($_ENV['LOG_PATH'])) {
    checkFilePermissions($_ENV['LOG_PATH'], 'Log directory', 'writable');
}
echo "\n";

// ===================================================================
// API SETTINGS
// ===================================================================
echo "=== API Settings ===\n";
checkConfig('API_BASE_URL', 'API base URL', false);
checkConfig('CORS_ALLOWED_ORIGINS', 'CORS allowed origins', false);
checkConfig('CORS_ENABLED', 'CORS enabled', false);
checkConfig('API_CACHE_ENABLED', 'API cache enabled', false);
checkConfig('API_CACHE_TTL', 'API cache TTL', false);
echo "\n";

// ===================================================================
// PERFORMANCE SETTINGS
// ===================================================================
echo "=== Performance Settings ===\n";
checkConfig('MAX_CONNECTIONS', 'Max connections', false);
checkConfig('QUERY_TIMEOUT', 'Query timeout', false);
checkConfig('REQUEST_TIMEOUT', 'Request timeout', false);
checkConfig('MAX_RETRIES', 'Max retries', false);
echo "\n";

// ===================================================================
// FILE SYSTEM CHECKS
// ===================================================================
echo "=== File System Checks ===\n";

// Check if required directories exist and are writable
$required_paths = [
    '/var/www/market-mi.ru/api' => 'API directory',
    '/var/www/market-mi.ru/config' => 'Config directory',
    '/var/www/market-mi.ru/scripts' => 'Scripts directory',
    '/var/www/market-mi.ru/logs' => 'Logs directory',
];

foreach ($required_paths as $path => $description) {
    if (strpos($path, 'logs') !== false) {
        checkFilePermissions($path, $description, 'writable');
    } else {
        checkFilePermissions($path, $description, 'readable');
    }
}
echo "\n";

// ===================================================================
// WAREHOUSE DASHBOARD SPECIFIC CHECKS
// ===================================================================
echo "=== Warehouse Dashboard Specific Checks ===\n";

// Check if warehouse dashboard API file exists
$api_file = '/var/www/market-mi.ru/api/warehouse-dashboard.php';
checkFilePermissions($api_file, 'Warehouse dashboard API file', 'readable');

// Check if WarehouseController exists
$controller_file = '/var/www/market-mi.ru/api/classes/WarehouseController.php';
checkFilePermissions($controller_file, 'Warehouse controller file', 'readable');

// Check if production config override exists
$config_override = '/var/www/market-mi.ru/config/production_db_override.php';
checkFilePermissions($config_override, 'Production DB override config', 'readable');

echo "\n";

// ===================================================================
// SUMMARY
// ===================================================================
echo "=== Validation Summary ===\n";
echo "Total checks: $total_checks\n";
echo "Passed: $success_count\n";
echo "Failed: " . (count($errors)) . "\n";
echo "Warnings: " . (count($warnings)) . "\n";
echo "Success rate: " . round(($success_count / $total_checks) * 100, 1) . "%\n\n";

if (!empty($errors)) {
    echo "=== ERRORS (Must be fixed) ===\n";
    foreach ($errors as $error) {
        echo "✗ $error\n";
    }
    echo "\n";
}

if (!empty($warnings)) {
    echo "=== WARNINGS (Should be addressed) ===\n";
    foreach ($warnings as $warning) {
        echo "⚠ $warning\n";
    }
    echo "\n";
}

// ===================================================================
// FINAL STATUS
// ===================================================================
if (empty($errors)) {
    echo "✓ CONFIGURATION VALID - Ready for production deployment\n";
    exit(0);
} else {
    echo "✗ CONFIGURATION INVALID - Fix errors before deployment\n";
    exit(1);
}
?>