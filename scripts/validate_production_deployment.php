<?php
/**
 * Production Deployment Validation Script
 * 
 * This script validates that the production environment is correctly configured
 * and all components are working properly after deployment.
 */

echo "🔍 Validating Production Deployment for Warehouse Dashboard\n";
echo str_repeat('=', 60) . "\n";

// Load production configuration
require_once __DIR__ . '/../config/production.php';

$validation_results = [];
$critical_errors = [];
$warnings = [];

// ===================================================================
// VALIDATE ENVIRONMENT VARIABLES
// ===================================================================

echo "🔧 Validating environment variables...\n";

$required_vars = [
    'APP_ENV' => 'production',
    'APP_DEBUG' => 'false',
    'PG_HOST', 'PG_USER', 'PG_PASSWORD', 'PG_NAME', 'PG_PORT',
    'OZON_CLIENT_ID', 'OZON_API_KEY', 'WB_API_KEY',
    'LOG_PATH', 'LOG_LEVEL',
    'JWT_SECRET', 'ENCRYPTION_KEY'
];

foreach ($required_vars as $key => $expected_value) {
    if (is_string($key)) {
        // Check specific value
        if ($_ENV[$key] === $expected_value) {
            echo "  ✅ $key = $expected_value\n";
        } else {
            $critical_errors[] = "$key should be '$expected_value', got '" . ($_ENV[$key] ?? 'not set') . "'";
            echo "  ❌ $key should be '$expected_value'\n";
        }
    } else {
        // Check if variable exists
        if (!empty($_ENV[$expected_value])) {
            echo "  ✅ $expected_value is configured\n";
        } else {
            $critical_errors[] = "$expected_value is not configured";
            echo "  ❌ $expected_value is not configured\n";
        }
    }
}

// ===================================================================
// VALIDATE DATABASE CONNECTIONS
// ===================================================================

echo "\n🗄️ Validating database connections...\n";

// Test PostgreSQL connection
try {
    $pdo = getProductionPgConnection();
    
    // Test a simple query
    $stmt = $pdo->query("SELECT version()");
    $version = $stmt->fetchColumn();
    
    echo "  ✅ PostgreSQL connection successful\n";
    echo "    Version: " . substr($version, 0, 50) . "...\n";
    
    // Test warehouse_sales_metrics table exists
    $stmt = $pdo->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_name = 'warehouse_sales_metrics'");
    $table_exists = $stmt->fetchColumn() > 0;
    
    if ($table_exists) {
        echo "  ✅ warehouse_sales_metrics table exists\n";
    } else {
        $warnings[] = "warehouse_sales_metrics table does not exist";
        echo "  ⚠️ warehouse_sales_metrics table does not exist\n";
    }
    
} catch (Exception $e) {
    $critical_errors[] = "PostgreSQL connection failed: " . $e->getMessage();
    echo "  ❌ PostgreSQL connection failed: " . $e->getMessage() . "\n";
}

// Test MySQL connection (optional)
try {
    $pdo = getProductionMysqlConnection();
    echo "  ✅ MySQL connection successful (legacy support)\n";
} catch (Exception $e) {
    $warnings[] = "MySQL connection failed: " . $e->getMessage();
    echo "  ⚠️ MySQL connection failed: " . $e->getMessage() . "\n";
}

// ===================================================================
// VALIDATE FILE PERMISSIONS
// ===================================================================

echo "\n🔒 Validating file permissions...\n";

$paths_to_check = [
    $_ENV['LOG_PATH'] => ['readable' => true, 'writable' => true],
    __DIR__ . '/../config/production.php' => ['readable' => true, 'writable' => false],
    __DIR__ . '/../api/warehouse-dashboard.php' => ['readable' => true, 'writable' => false],
];

foreach ($paths_to_check as $path => $requirements) {
    if (!file_exists($path) && !is_dir($path)) {
        $critical_errors[] = "Path does not exist: $path";
        echo "  ❌ Path does not exist: $path\n";
        continue;
    }
    
    if ($requirements['readable'] && !is_readable($path)) {
        $critical_errors[] = "Path is not readable: $path";
        echo "  ❌ Path is not readable: $path\n";
    } else {
        echo "  ✅ Path is readable: $path\n";
    }
    
    if ($requirements['writable'] && !is_writable($path)) {
        $critical_errors[] = "Path is not writable: $path";
        echo "  ❌ Path is not writable: $path\n";
    } elseif ($requirements['writable']) {
        echo "  ✅ Path is writable: $path\n";
    }
}

// ===================================================================
// VALIDATE API ENDPOINTS
// ===================================================================

echo "\n🌐 Validating API endpoints...\n";

$api_endpoints = [
    'warehouses' => 'https://www.market-mi.ru/api/warehouse-dashboard.php?action=warehouses',
    'clusters' => 'https://www.market-mi.ru/api/warehouse-dashboard.php?action=clusters',
    'dashboard' => 'https://www.market-mi.ru/api/warehouse-dashboard.php?action=dashboard&limit=1',
];

foreach ($api_endpoints as $name => $url) {
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false); // For testing
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    
    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);
    
    if ($error) {
        $warnings[] = "API endpoint '$name' curl error: $error";
        echo "  ⚠️ API endpoint '$name' curl error: $error\n";
    } elseif ($http_code === 200) {
        $data = json_decode($response, true);
        if ($data && isset($data['success']) && $data['success']) {
            echo "  ✅ API endpoint '$name' is working\n";
        } else {
            $warnings[] = "API endpoint '$name' returned invalid response";
            echo "  ⚠️ API endpoint '$name' returned invalid response\n";
        }
    } else {
        $warnings[] = "API endpoint '$name' returned HTTP $http_code";
        echo "  ⚠️ API endpoint '$name' returned HTTP $http_code\n";
    }
}

// ===================================================================
// VALIDATE LOGGING
// ===================================================================

echo "\n📝 Validating logging system...\n";

try {
    // Test error logging
    require_once __DIR__ . '/../config/error_logging_production.php';
    
    $test_message = "Production deployment validation test - " . date('Y-m-d H:i:s');
    $GLOBALS['error_logger']->info($test_message);
    
    echo "  ✅ Error logging system initialized\n";
    
    // Check if log file was created
    $log_file = $_ENV['LOG_PATH'] . '/warehouse-dashboard-' . date('Y-m-d') . '.log';
    if (file_exists($log_file)) {
        echo "  ✅ Log file created: $log_file\n";
        
        // Check if our test message is in the log
        $log_content = file_get_contents($log_file);
        if (strpos($log_content, $test_message) !== false) {
            echo "  ✅ Log writing is working\n";
        } else {
            $warnings[] = "Log writing may not be working properly";
            echo "  ⚠️ Log writing may not be working properly\n";
        }
    } else {
        $warnings[] = "Log file was not created";
        echo "  ⚠️ Log file was not created\n";
    }
    
} catch (Exception $e) {
    $warnings[] = "Error logging system failed: " . $e->getMessage();
    echo "  ⚠️ Error logging system failed: " . $e->getMessage() . "\n";
}

// ===================================================================
// VALIDATE SECURITY SETTINGS
// ===================================================================

echo "\n🔐 Validating security settings...\n";

// Check if debug mode is disabled
if ($_ENV['APP_DEBUG'] === 'false') {
    echo "  ✅ Debug mode is disabled\n";
} else {
    $critical_errors[] = "Debug mode should be disabled in production";
    echo "  ❌ Debug mode should be disabled in production\n";
}

// Check if error display is disabled
if (!ini_get('display_errors')) {
    echo "  ✅ Error display is disabled\n";
} else {
    $critical_errors[] = "Error display should be disabled in production";
    echo "  ❌ Error display should be disabled in production\n";
}

// Check HTTPS
if (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') {
    echo "  ✅ HTTPS is enabled\n";
} else {
    $warnings[] = "HTTPS may not be properly configured";
    echo "  ⚠️ HTTPS may not be properly configured\n";
}

// ===================================================================
// PERFORMANCE CHECKS
// ===================================================================

echo "\n⚡ Performance checks...\n";

// Check PHP memory limit
$memory_limit = ini_get('memory_limit');
$memory_bytes = $memory_limit === '-1' ? PHP_INT_MAX : (int)$memory_limit * 1024 * 1024;

if ($memory_bytes >= 256 * 1024 * 1024) { // 256MB
    echo "  ✅ PHP memory limit: $memory_limit\n";
} else {
    $warnings[] = "PHP memory limit may be too low: $memory_limit";
    echo "  ⚠️ PHP memory limit may be too low: $memory_limit\n";
}

// Check max execution time
$max_execution_time = ini_get('max_execution_time');
if ($max_execution_time >= 30) {
    echo "  ✅ Max execution time: {$max_execution_time}s\n";
} else {
    $warnings[] = "Max execution time may be too low: {$max_execution_time}s";
    echo "  ⚠️ Max execution time may be too low: {$max_execution_time}s\n";
}

// ===================================================================
// FINAL SUMMARY
// ===================================================================

echo "\n" . str_repeat('=', 60) . "\n";
echo "📊 DEPLOYMENT VALIDATION SUMMARY\n";
echo str_repeat('=', 60) . "\n";

if (empty($critical_errors)) {
    echo "✅ Production deployment validation PASSED!\n";
    echo "🎉 The warehouse dashboard is ready for production use.\n";
} else {
    echo "❌ Production deployment validation FAILED!\n";
    echo "🚨 Critical errors must be fixed before going live:\n";
    foreach ($critical_errors as $error) {
        echo "  - $error\n";
    }
}

if (!empty($warnings)) {
    echo "\n⚠️ Warnings (should be addressed):\n";
    foreach ($warnings as $warning) {
        echo "  - $warning\n";
    }
}

echo "\n📋 Production URLs:\n";
echo "🌐 Dashboard: https://www.market-mi.ru/warehouse-dashboard\n";
echo "🔗 API: https://www.market-mi.ru/api/warehouse-dashboard.php\n";
echo "📊 Monitoring: Run /var/www/market-mi.ru/scripts/monitor_production.sh\n";

echo "\n📝 Log files:\n";
echo "📄 Application logs: " . $_ENV['LOG_PATH'] . "/warehouse-dashboard-" . date('Y-m-d') . ".log\n";
echo "🚨 Error logs: " . $_ENV['LOG_PATH'] . "/errors-" . date('Y-m-d') . ".log\n";
echo "🐘 PHP errors: " . $_ENV['LOG_PATH'] . "/php_errors.log\n";

// Exit with appropriate code
if (!empty($critical_errors)) {
    echo "\n❌ Deployment validation failed with critical errors.\n";
    exit(1);
} else {
    echo "\n✅ Deployment validation completed successfully!\n";
    exit(0);
}