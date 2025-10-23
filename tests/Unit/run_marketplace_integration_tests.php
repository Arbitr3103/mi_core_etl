<?php
/**
 * Test runner for marketplace API integration tests
 * 
 * This script runs comprehensive integration tests for marketplace-specific API endpoints
 * covering requirements 1.4, 2.4, 3.4, 4.4
 */

require_once __DIR__ . '/tests/MarketplaceAPIIntegrationTest.php';

// Test configuration - Update these values for your environment
$testConfig = [
    'host' => getenv('TEST_DB_HOST') ?: 'localhost',
    'dbname' => getenv('TEST_DB_NAME') ?: 'mi_core_db',
    'username' => getenv('TEST_DB_USER') ?: 'username',
    'password' => getenv('TEST_DB_PASS') ?: 'password'
];

echo "Marketplace API Integration Test Runner\n";
echo "======================================\n\n";

echo "Test Configuration:\n";
echo "- Host: {$testConfig['host']}\n";
echo "- Database: {$testConfig['dbname']}\n";
echo "- User: {$testConfig['username']}\n";
echo "- Password: " . str_repeat('*', strlen($testConfig['password'])) . "\n\n";

// Check if we should use sample data mode
$useSampleData = isset($argv[1]) && $argv[1] === '--sample-data';

if ($useSampleData) {
    echo "Running in SAMPLE DATA mode - tests will use mock data\n\n";
}

try {
    // Initialize and run tests
    $integrationTest = new MarketplaceAPIIntegrationTest($testConfig);
    
    if ($useSampleData) {
        // Run tests with sample data (for environments without real data)
        $integrationTest->runSampleDataTests();
    } else {
        // Run full integration tests
        $integrationTest->runAllTests();
    }
    
} catch (Exception $e) {
    echo "ERROR: Failed to initialize integration tests\n";
    echo "Message: " . $e->getMessage() . "\n\n";
    
    echo "Troubleshooting:\n";
    echo "1. Check database connection settings\n";
    echo "2. Ensure MarginDashboardAPI.php and RecommendationsAPI.php exist\n";
    echo "3. Verify database has required tables and data\n";
    echo "4. Run with --sample-data flag to test without real database\n\n";
    
    echo "Usage:\n";
    echo "  php run_marketplace_integration_tests.php           # Run with real database\n";
    echo "  php run_marketplace_integration_tests.php --sample-data  # Run with mock data\n\n";
    
    exit(1);
}

echo "\nIntegration tests completed.\n";
?>