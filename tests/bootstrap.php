<?php
/**
 * PHPUnit Bootstrap File
 * 
 * Sets up the testing environment and loads necessary dependencies.
 */

// Set error reporting
error_reporting(E_ALL);
ini_set('display_errors', '1');

// Define test constants if not already defined
if (!defined('DB_HOST')) {
    define('DB_HOST', getenv('DB_HOST') ?: 'localhost');
}
if (!defined('DB_PORT')) {
    define('DB_PORT', getenv('DB_PORT') ?: '3306');
}
if (!defined('DB_NAME')) {
    define('DB_NAME', getenv('DB_NAME') ?: 'test_db');
}
if (!defined('DB_USER')) {
    define('DB_USER', getenv('DB_USER') ?: 'test_user');
}
if (!defined('DB_PASSWORD')) {
    define('DB_PASSWORD', getenv('DB_PASSWORD') ?: 'test_password');
}
if (!defined('LOG_DIR')) {
    define('LOG_DIR', getenv('LOG_DIR') ?: '/tmp');
}
if (!defined('OZON_CLIENT_ID')) {
    define('OZON_CLIENT_ID', getenv('OZON_CLIENT_ID') ?: 'test_client_id');
}
if (!defined('OZON_API_KEY')) {
    define('OZON_API_KEY', getenv('OZON_API_KEY') ?: 'test_api_key');
}
if (!defined('OZON_API_BASE_URL')) {
    define('OZON_API_BASE_URL', getenv('OZON_API_BASE_URL') ?: 'https://api-seller.ozon.ru');
}

// Create log directory if it doesn't exist
if (!is_dir(LOG_DIR)) {
    mkdir(LOG_DIR, 0755, true);
}

// Create test results directory
$testResultsDir = __DIR__ . '/../test-results';
if (!is_dir($testResultsDir)) {
    mkdir($testResultsDir, 0755, true);
}

// Create coverage directory
$coverageDir = __DIR__ . '/../coverage';
if (!is_dir($coverageDir)) {
    mkdir($coverageDir, 0755, true);
}

// Load Composer autoloader if available
$autoloadPaths = [
    __DIR__ . '/../vendor/autoload.php',
    __DIR__ . '/../../vendor/autoload.php',
    __DIR__ . '/../../../vendor/autoload.php',
];

foreach ($autoloadPaths as $autoloadPath) {
    if (file_exists($autoloadPath)) {
        require_once $autoloadPath;
        break;
    }
}

// Load only essential source files for testing
$essentialFiles = [
    __DIR__ . '/../src/SafeSyncEngine.php',
    __DIR__ . '/../src/DataTypeNormalizer.php',
    __DIR__ . '/../src/FallbackDataProvider.php'
];

foreach ($essentialFiles as $file) {
    if (file_exists($file)) {
        require_once $file;
    }
}

// Set timezone
date_default_timezone_set('UTC');

// Display test environment info
echo "\n";
echo "========================================\n";
echo "MDM Sync Engine Test Suite\n";
echo "========================================\n";
echo "PHP Version: " . PHP_VERSION . "\n";
echo "Test Environment: " . (getenv('APP_ENV') ?: 'testing') . "\n";
echo "Log Directory: " . LOG_DIR . "\n";
echo "========================================\n";
echo "\n";
