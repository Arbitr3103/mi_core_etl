<?php
/**
 * Test script for MarginDashboardAPI marketplace-specific methods
 */

require_once 'MarginDashboardAPI.php';

// Test configuration - replace with your actual database credentials
$testConfig = [
    'host' => 'localhost',
    'dbname' => 'manhattan_test',
    'username' => 'test_user',
    'password' => 'test_password'
];

try {
    echo "=== Testing MarginDashboardAPI Marketplace Methods ===\n\n";
    
    // Initialize API
    $api = new MarginDashboardAPI(
        $testConfig['host'],
        $testConfig['dbname'],
        $testConfig['username'],
        $testConfig['password']
    );
    
    $startDate = '2025-01-01';
    $endDate = '2025-01-31';
    
    echo "Testing period: $startDate to $endDate\n\n";
    
    // Test 1: getMarginSummaryByMarketplace
    echo "1. Testing getMarginSummaryByMarketplace...\n";
    
    // Test for all marketplaces
    $allMarketplaces = $api->getMarginSummaryByMarketplace($startDate, $endDate);
    echo "   All marketplaces: " . json_encode($allMarketplaces, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
    
    // Test for Ozon
    $ozonSummary = $api->getMarginSummaryByMarketplace($startDate, $endDate, 'ozon');
    echo "   Ozon only: " . json_encode($ozonSummary, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
    
    // Test for Wildberries
    $wbSummary = $api->getMarginSummaryByMarketplace($startDate, $endDate, 'wildberries');
    echo "   Wildberries only: " . json_encode($wbSummary, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
    
    echo "\n";
    
    // Test 2: getTopProductsByMarketplace
    echo "2. Testing getTopProductsByMarketplace...\n";
    
    $ozonTopProducts = $api->getTopProductsByMarketplace('ozon', 5, $startDate, $endDate);
    echo "   Ozon top 5 products: " . count($ozonTopProducts) . " products found\n";
    
    $wbTopProducts = $api->getTopProductsByMarketplace('wildberries', 5, $startDate, $endDate);
    echo "   Wildberries top 5 products: " . count($wbTopProducts) . " products found\n";
    
    echo "\n";
    
    // Test 3: getDailyMarginChartByMarketplace
    echo "3. Testing getDailyMarginChartByMarketplace...\n";
    
    $ozonChart = $api->getDailyMarginChartByMarketplace($startDate, $endDate, 'ozon');
    echo "   Ozon daily chart: " . count($ozonChart) . " data points\n";
    
    $wbChart = $api->getDailyMarginChartByMarketplace($startDate, $endDate, 'wildberries');
    echo "   Wildberries daily chart: " . count($wbChart) . " data points\n";
    
    echo "\n";
    
    // Test 4: getMarketplaceComparison
    echo "4. Testing getMarketplaceComparison...\n";
    
    $comparison = $api->getMarketplaceComparison($startDate, $endDate);
    echo "   Comparison data structure:\n";
    echo "   - Total revenue: " . ($comparison['total']['revenue'] ?? 'N/A') . "\n";
    echo "   - Ozon revenue: " . ($comparison['marketplaces']['ozon']['revenue'] ?? 'N/A') . "\n";
    echo "   - Wildberries revenue: " . ($comparison['marketplaces']['wildberries']['revenue'] ?? 'N/A') . "\n";
    echo "   - Revenue leader: " . ($comparison['leaders']['revenue'] ?? 'N/A') . "\n";
    
    echo "\n";
    
    // Test 5: Error handling
    echo "5. Testing error handling...\n";
    
    try {
        $api->getMarginSummaryByMarketplace($startDate, $endDate, 'invalid_marketplace');
        echo "   ERROR: Should have thrown exception for invalid marketplace\n";
    } catch (InvalidArgumentException $e) {
        echo "   ✓ Correctly caught invalid marketplace exception: " . $e->getMessage() . "\n";
    }
    
    echo "\n=== All tests completed successfully! ===\n";
    
} catch (Exception $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
    echo "Note: This test requires a valid database connection. Update the test configuration above.\n";
}
?>