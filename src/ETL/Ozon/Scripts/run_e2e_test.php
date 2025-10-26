<?php

/**
 * Script to run End-to-End Integration Test for Ozon ETL System
 * 
 * This script executes comprehensive testing of the complete ETL pipeline
 * and validates data integrity across all components.
 * 
 * Usage: php run_e2e_test.php [--verbose] [--skip-api-test]
 */

require_once __DIR__ . '/../autoload.php';
require_once __DIR__ . '/../Tests/EndToEndIntegrationTest.php';

// Parse command line arguments
$options = getopt('', ['verbose', 'skip-api-test', 'help']);

if (isset($options['help'])) {
    echo "Usage: php run_e2e_test.php [options]\n\n";
    echo "Options:\n";
    echo "  --verbose      Enable verbose output\n";
    echo "  --skip-api-test Skip real API calls (use mock data)\n";
    echo "  --help         Show this help message\n\n";
    exit(0);
}

$verbose = isset($options['verbose']);
$skipApiTest = isset($options['skip-api-test']);

echo "🚀 Ozon ETL System - End-to-End Integration Test\n";
echo "================================================\n\n";

if ($skipApiTest) {
    echo "⚠️  Running in mock mode (no real API calls)\n\n";
}

try {
    // Step 1: Environment validation
    echo "1️⃣  Validating environment...\n";
    validateEnvironment($verbose);
    echo "   ✅ Environment validation passed\n\n";
    
    // Step 2: Database connectivity test
    echo "2️⃣  Testing database connectivity...\n";
    testDatabaseConnectivity($verbose);
    echo "   ✅ Database connectivity test passed\n\n";
    
    // Step 3: API connectivity test (if not skipped)
    if (!$skipApiTest) {
        echo "3️⃣  Testing API connectivity...\n";
        testApiConnectivity($verbose);
        echo "   ✅ API connectivity test passed\n\n";
    } else {
        echo "3️⃣  Skipping API connectivity test (mock mode)\n\n";
    }
    
    // Step 4: Run comprehensive E2E test
    echo "4️⃣  Running comprehensive End-to-End test...\n";
    $test = new EndToEndIntegrationTest();
    
    if ($skipApiTest) {
        // Set mock mode for testing without real API calls
        $test->setMockMode(true);
    }
    
    $report = $test->runFullTest();
    
    // Step 5: Display results
    echo "5️⃣  Test Results Summary\n";
    echo "   ========================\n";
    displayTestResults($report, $verbose);
    
    // Step 6: Generate recommendations
    if (!empty($report['summary']['recommendations'])) {
        echo "\n6️⃣  Recommendations\n";
        echo "   ================\n";
        foreach ($report['summary']['recommendations'] as $recommendation) {
            echo "   • " . $recommendation . "\n";
        }
    }
    
    echo "\n📊 Detailed report: Logs/e2e_test_report_{$report['test_run_id']}.json\n";
    
    if ($report['overall_status'] === 'SUCCESS') {
        echo "\n🎉 End-to-End Integration Test COMPLETED SUCCESSFULLY!\n";
        echo "   The Ozon ETL system is ready for production deployment.\n\n";
        exit(0);
    } else {
        echo "\n❌ End-to-End Integration Test FAILED!\n";
        echo "   Please review the errors and fix issues before deployment.\n\n";
        exit(1);
    }
    
} catch (Exception $e) {
    echo "\n💥 Test execution failed: " . $e->getMessage() . "\n";
    if ($verbose) {
        echo "\nStack trace:\n" . $e->getTraceAsString() . "\n";
    }
    exit(1);
}

/**
 * Validate environment configuration
 */
function validateEnvironment(bool $verbose): void
{
    $requiredEnvVars = [
        'DB_HOST', 'DB_NAME', 'DB_USER', 'DB_PASSWORD',
        'OZON_CLIENT_ID', 'OZON_API_KEY'
    ];
    
    $missingVars = [];
    
    foreach ($requiredEnvVars as $var) {
        if (empty($_ENV[$var])) {
            $missingVars[] = $var;
        } elseif ($verbose) {
            echo "   ✓ {$var} is set\n";
        }
    }
    
    if (!empty($missingVars)) {
        throw new Exception('Missing required environment variables: ' . implode(', ', $missingVars));
    }
    
    // Check PHP extensions
    $requiredExtensions = ['pdo', 'pdo_pgsql', 'curl', 'json'];
    $missingExtensions = [];
    
    foreach ($requiredExtensions as $ext) {
        if (!extension_loaded($ext)) {
            $missingExtensions[] = $ext;
        } elseif ($verbose) {
            echo "   ✓ PHP extension {$ext} is loaded\n";
        }
    }
    
    if (!empty($missingExtensions)) {
        throw new Exception('Missing required PHP extensions: ' . implode(', ', $missingExtensions));
    }
}

/**
 * Test database connectivity and schema
 */
function testDatabaseConnectivity(bool $verbose): void
{
    try {
        // Load configuration
        $config = require __DIR__ . '/../Config/bootstrap.php';
        $db = new MiCore\ETL\Ozon\Core\DatabaseConnection($config['database']['connection']);
        
        // Test basic connectivity
        $result = $db->query("SELECT 1 as test");
        if ($result[0]['test'] != 1) {
            throw new Exception('Database connectivity test failed');
        }
        
        if ($verbose) {
            echo "   ✓ Database connection established\n";
        }
        
        // Test required tables exist
        $requiredTables = ['dim_products', 'fact_orders', 'inventory', 'etl_execution_log'];
        
        foreach ($requiredTables as $table) {
            $result = $db->query("
                SELECT COUNT(*) as count 
                FROM information_schema.tables 
                WHERE table_name = ? AND table_schema = 'public'
            ", [$table]);
            
            if ($result[0]['count'] == 0) {
                throw new Exception("Required table '{$table}' does not exist");
            }
            
            if ($verbose) {
                echo "   ✓ Table {$table} exists\n";
            }
        }
        
    } catch (Exception $e) {
        throw new Exception('Database connectivity test failed: ' . $e->getMessage());
    }
}

/**
 * Test API connectivity
 */
function testApiConnectivity(bool $verbose): void
{
    try {
        $config = require __DIR__ . '/../Config/bootstrap.php';
        $apiClient = MiCore\ETL\Ozon\Api\OzonApiClient::fromConfig($config['api']);
        
        // Test basic API call (get first page of products)
        $response = $apiClient->getProducts(1);
        
        if (!isset($response['result'])) {
            throw new Exception('Invalid API response format');
        }
        
        if ($verbose) {
            echo "   ✓ Ozon API connection established\n";
            echo "   ✓ API response format is valid\n";
        }
        
    } catch (Exception $e) {
        throw new Exception('API connectivity test failed: ' . $e->getMessage());
    }
}

/**
 * Display test results in a formatted way
 */
function displayTestResults(array $report, bool $verbose): void
{
    echo "   Overall Status: " . ($report['overall_status'] === 'SUCCESS' ? '✅ PASSED' : '❌ FAILED') . "\n";
    echo "   Success Rate: {$report['summary']['success_rate']}%\n";
    echo "   Tests Passed: {$report['summary']['passed_tests']}/{$report['summary']['total_tests']}\n";
    
    if ($verbose && isset($report['test_results'])) {
        echo "\n   Detailed Results:\n";
        echo "   -----------------\n";
        
        foreach ($report['test_results'] as $testName => $result) {
            if (is_array($result) && isset($result['status'])) {
                $status = $result['status'] === 'PASSED' ? '✅' : 
                         ($result['status'] === 'WARNING' ? '⚠️' : '❌');
                echo "   {$status} {$testName}: {$result['status']}\n";
                
                // Show additional details for some tests
                if ($testName === 'product_etl' && isset($result['processed_count'])) {
                    echo "      └─ Processed {$result['processed_count']} products in {$result['execution_time']}s\n";
                }
                if ($testName === 'sales_etl' && isset($result['processed_count'])) {
                    echo "      └─ Processed {$result['processed_count']} sales records in {$result['execution_time']}s\n";
                }
                if ($testName === 'inventory_etl' && isset($result['processed_count'])) {
                    echo "      └─ Processed {$result['processed_count']} inventory records in {$result['execution_time']}s\n";
                }
                if ($testName === 'data_linking' && isset($result['complete_chains'])) {
                    echo "      └─ Found {$result['complete_chains']} complete data chains\n";
                }
            }
        }
    }
    
    if (isset($report['test_results']['error'])) {
        echo "\n   Error Details:\n";
        echo "   " . $report['test_results']['error'] . "\n";
    }
}