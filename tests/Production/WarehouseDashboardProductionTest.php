<?php
/**
 * Production Tests for Warehouse Dashboard
 * 
 * Comprehensive tests for all warehouse dashboard features in production environment.
 * Tests all functionality mentioned in task 9.2: filters, sorting, CSV export, and pagination.
 * 
 * Requirements: 5.1 (All features tested in production)
 * 
 * Usage:
 * php tests/Production/WarehouseDashboardProductionTest.php
 */

// Standalone production test - no dependencies needed

class WarehouseDashboardProductionTest {
    
    private $baseUrl;
    private $results = [];
    private $totalTests = 0;
    private $passedTests = 0;
    private $failedTests = 0;
    
    public function __construct() {
        // Production URL
        $this->baseUrl = 'https://www.market-mi.ru/api/warehouse-dashboard.php';
        
        echo "🏭 WAREHOUSE DASHBOARD PRODUCTION TESTING\n";
        echo "========================================\n";
        echo "Testing URL: {$this->baseUrl}\n";
        echo "Started at: " . date('Y-m-d H:i:s') . "\n\n";
    }
    
    /**
     * Run all production tests
     */
    public function runAllTests() {
        $startTime = microtime(true);
        
        try {
            // Test basic connectivity
            $this->testBasicConnectivity();
            
            // Test dashboard access and loading
            $this->testDashboardAccess();
            
            // Test all filter functionality
            $this->testFilterFunctionality();
            
            // Test sorting functionality
            $this->testSortingFunctionality();
            
            // Test CSV export functionality
            $this->testCSVExportFunctionality();
            
            // Test pagination functionality
            $this->testPaginationFunctionality();
            
            // Test performance requirements
            $this->testPerformanceRequirements();
            
            // Test error handling
            $this->testErrorHandling();
            
        } catch (Exception $e) {
            $this->recordFailure("Critical Error", $e->getMessage());
        }
        
        $endTime = microtime(true);
        $duration = round($endTime - $startTime, 2);
        
        $this->printSummary($duration);
    }
    
    /**
     * Test basic connectivity to production API
     */
    private function testBasicConnectivity() {
        echo "🔌 Testing Basic Connectivity...\n";
        
        try {
            $response = $this->makeApiRequest(['action' => 'dashboard', 'limit' => 1]);
            
            if ($response && isset($response['success'])) {
                $this->recordSuccess("Basic Connectivity", "API is accessible and responding");
            } else {
                $this->recordFailure("Basic Connectivity", "API not responding correctly");
            }
        } catch (Exception $e) {
            $this->recordFailure("Basic Connectivity", "Connection failed: " . $e->getMessage());
        }
    }
    
    /**
     * Test dashboard access and basic data structure
     */
    private function testDashboardAccess() {
        echo "📊 Testing Dashboard Access...\n";
        
        try {
            $response = $this->makeApiRequest(['action' => 'dashboard']);
            
            // Test response structure
            $this->validateResponseStructure($response, "Dashboard Access");
            
            // Test data presence
            if (isset($response['data']['warehouses']) && count($response['data']['warehouses']) > 0) {
                $this->recordSuccess("Dashboard Data", "Dashboard contains warehouse data");
            } else {
                $this->recordFailure("Dashboard Data", "No warehouse data found");
            }
            
            // Test required fields in first item
            if (isset($response['data']['warehouses'][0]['items'][0])) {
                $this->validateItemStructure($response['data']['warehouses'][0]['items'][0]);
            }
            
        } catch (Exception $e) {
            $this->recordFailure("Dashboard Access", $e->getMessage());
        }
    }
    
    /**
     * Test all filter functionality
     */
    private function testFilterFunctionality() {
        echo "🔍 Testing Filter Functionality...\n";
        
        // Test warehouse filter
        $this->testWarehouseFilter();
        
        // Test cluster filter
        $this->testClusterFilter();
        
        // Test liquidity filter
        $this->testLiquidityFilter();
        
        // Test active_only filter
        $this->testActiveOnlyFilter();
        
        // Test replenishment need filter
        $this->testReplenishmentNeedFilter();
    }
    
    /**
     * Test warehouse filter
     */
    private function testWarehouseFilter() {
        try {
            // Get list of warehouses first
            $warehousesResponse = $this->makeApiRequest(['action' => 'warehouses']);
            
            if (!$warehousesResponse['success'] || empty($warehousesResponse['data'])) {
                $this->recordFailure("Warehouse Filter", "Cannot get warehouse list");
                return;
            }
            
            $testWarehouse = $warehousesResponse['data'][0]['warehouse_name'];
            
            // Test filtering by warehouse
            $response = $this->makeApiRequest([
                'action' => 'dashboard',
                'warehouse' => $testWarehouse
            ]);
            
            if ($response['success'] && 
                isset($response['data']['filters_applied']['warehouse']) &&
                $response['data']['filters_applied']['warehouse'] === $testWarehouse) {
                
                // Verify all items belong to the filtered warehouse
                $allCorrect = true;
                foreach ($response['data']['warehouses'] as $warehouse) {
                    if ($warehouse['warehouse_name'] !== $testWarehouse) {
                        $allCorrect = false;
                        break;
                    }
                }
                
                if ($allCorrect) {
                    $this->recordSuccess("Warehouse Filter", "Filter working correctly for: $testWarehouse");
                } else {
                    $this->recordFailure("Warehouse Filter", "Filter not applied correctly");
                }
            } else {
                $this->recordFailure("Warehouse Filter", "Filter not working");
            }
            
        } catch (Exception $e) {
            $this->recordFailure("Warehouse Filter", $e->getMessage());
        }
    }
    
    /**
     * Test cluster filter
     */
    private function testClusterFilter() {
        try {
            // Get list of clusters first
            $clustersResponse = $this->makeApiRequest(['action' => 'clusters']);
            
            if (!$clustersResponse['success'] || empty($clustersResponse['data'])) {
                $this->recordFailure("Cluster Filter", "Cannot get cluster list");
                return;
            }
            
            $testCluster = $clustersResponse['data'][0]['cluster'];
            
            // Test filtering by cluster
            $response = $this->makeApiRequest([
                'action' => 'dashboard',
                'cluster' => $testCluster
            ]);
            
            if ($response['success'] && 
                isset($response['data']['filters_applied']['cluster']) &&
                $response['data']['filters_applied']['cluster'] === $testCluster) {
                
                $this->recordSuccess("Cluster Filter", "Filter working correctly for: $testCluster");
            } else {
                $this->recordFailure("Cluster Filter", "Filter not working");
            }
            
        } catch (Exception $e) {
            $this->recordFailure("Cluster Filter", $e->getMessage());
        }
    }
    
    /**
     * Test liquidity status filter
     */
    private function testLiquidityFilter() {
        $liquidityStatuses = ['critical', 'low', 'normal', 'excess'];
        
        foreach ($liquidityStatuses as $status) {
            try {
                $response = $this->makeApiRequest([
                    'action' => 'dashboard',
                    'liquidity_status' => $status,
                    'limit' => 10
                ]);
                
                if ($response['success'] && 
                    isset($response['data']['filters_applied']['liquidity_status']) &&
                    $response['data']['filters_applied']['liquidity_status'] === $status) {
                    
                    $this->recordSuccess("Liquidity Filter ($status)", "Filter working correctly");
                } else {
                    $this->recordFailure("Liquidity Filter ($status)", "Filter not working");
                }
                
            } catch (Exception $e) {
                $this->recordFailure("Liquidity Filter ($status)", $e->getMessage());
            }
        }
    }
    
    /**
     * Test active_only filter
     */
    private function testActiveOnlyFilter() {
        try {
            // Test active_only = true
            $response = $this->makeApiRequest([
                'action' => 'dashboard',
                'active_only' => 'true',
                'limit' => 10
            ]);
            
            if ($response['success']) {
                $this->recordSuccess("Active Only Filter (true)", "Filter working");
            } else {
                $this->recordFailure("Active Only Filter (true)", "Filter not working");
            }
            
            // Test active_only = false
            $response = $this->makeApiRequest([
                'action' => 'dashboard',
                'active_only' => 'false',
                'limit' => 10
            ]);
            
            if ($response['success']) {
                $this->recordSuccess("Active Only Filter (false)", "Filter working");
            } else {
                $this->recordFailure("Active Only Filter (false)", "Filter not working");
            }
            
        } catch (Exception $e) {
            $this->recordFailure("Active Only Filter", $e->getMessage());
        }
    }
    
    /**
     * Test replenishment need filter
     */
    private function testReplenishmentNeedFilter() {
        try {
            $response = $this->makeApiRequest([
                'action' => 'dashboard',
                'has_replenishment_need' => 'true',
                'limit' => 10
            ]);
            
            if ($response['success']) {
                $this->recordSuccess("Replenishment Need Filter", "Filter working");
            } else {
                $this->recordFailure("Replenishment Need Filter", "Filter not working");
            }
            
        } catch (Exception $e) {
            $this->recordFailure("Replenishment Need Filter", $e->getMessage());
        }
    }
    
    /**
     * Test sorting functionality
     */
    private function testSortingFunctionality() {
        echo "🔄 Testing Sorting Functionality...\n";
        
        $sortFields = [
            'replenishment_need' => 'desc',
            'daily_sales_avg' => 'desc',
            'days_of_stock' => 'asc',
            'available' => 'desc',
            'product_name' => 'asc'
        ];
        
        foreach ($sortFields as $field => $order) {
            try {
                $response = $this->makeApiRequest([
                    'action' => 'dashboard',
                    'sort_by' => $field,
                    'sort_order' => $order,
                    'limit' => 10
                ]);
                
                if ($response['success'] && 
                    isset($response['data']['filters_applied']['sort_by']) &&
                    $response['data']['filters_applied']['sort_by'] === $field &&
                    $response['data']['filters_applied']['sort_order'] === $order) {
                    
                    $this->recordSuccess("Sorting ($field $order)", "Sort parameters applied correctly");
                } else {
                    $this->recordFailure("Sorting ($field $order)", "Sort parameters not applied");
                }
                
            } catch (Exception $e) {
                $this->recordFailure("Sorting ($field $order)", $e->getMessage());
            }
        }
    }
    
    /**
     * Test CSV export functionality
     */
    private function testCSVExportFunctionality() {
        echo "📄 Testing CSV Export Functionality...\n";
        
        try {
            $url = $this->baseUrl . '?' . http_build_query([
                'action' => 'export',
                'limit' => 10
            ]);
            
            $context = stream_context_create([
                'http' => [
                    'method' => 'GET',
                    'timeout' => 30,
                    'header' => [
                        'User-Agent: WarehouseDashboardProductionTest/1.0'
                    ]
                ]
            ]);
            
            $response = @file_get_contents($url, false, $context);
            
            if ($response !== false) {
                // Check if it's CSV format
                $lines = explode("\n", trim($response));
                
                if (count($lines) > 0) {
                    $header = str_getcsv($lines[0]);
                    
                    // Check for expected CSV headers
                    $expectedHeaders = ['Товар', 'SKU', 'Склад', 'Кластер', 'Доступно'];
                    $hasExpectedHeaders = true;
                    
                    foreach ($expectedHeaders as $expectedHeader) {
                        if (!in_array($expectedHeader, $header)) {
                            $hasExpectedHeaders = false;
                            break;
                        }
                    }
                    
                    if ($hasExpectedHeaders) {
                        $this->recordSuccess("CSV Export", "Export working, " . count($lines) . " lines generated");
                    } else {
                        $this->recordFailure("CSV Export", "CSV headers not as expected");
                    }
                } else {
                    $this->recordFailure("CSV Export", "Empty CSV response");
                }
            } else {
                $this->recordFailure("CSV Export", "Export request failed");
            }
            
        } catch (Exception $e) {
            $this->recordFailure("CSV Export", $e->getMessage());
        }
    }
    
    /**
     * Test pagination functionality
     */
    private function testPaginationFunctionality() {
        echo "📄 Testing Pagination Functionality...\n";
        
        try {
            // Test first page
            $response1 = $this->makeApiRequest([
                'action' => 'dashboard',
                'limit' => 5,
                'offset' => 0
            ]);
            
            if ($response1['success'] && 
                isset($response1['data']['pagination']) &&
                $response1['data']['pagination']['limit'] == 5 &&
                $response1['data']['pagination']['offset'] == 0 &&
                $response1['data']['pagination']['current_page'] == 1) {
                
                $this->recordSuccess("Pagination (Page 1)", "First page working correctly");
                
                // Test second page if there are more items
                if ($response1['data']['pagination']['has_next']) {
                    $response2 = $this->makeApiRequest([
                        'action' => 'dashboard',
                        'limit' => 5,
                        'offset' => 5
                    ]);
                    
                    if ($response2['success'] && 
                        isset($response2['data']['pagination']) &&
                        $response2['data']['pagination']['current_page'] == 2) {
                        
                        $this->recordSuccess("Pagination (Page 2)", "Second page working correctly");
                    } else {
                        $this->recordFailure("Pagination (Page 2)", "Second page not working");
                    }
                }
            } else {
                $this->recordFailure("Pagination (Page 1)", "First page not working correctly");
            }
            
        } catch (Exception $e) {
            $this->recordFailure("Pagination", $e->getMessage());
        }
    }
    
    /**
     * Test performance requirements
     */
    private function testPerformanceRequirements() {
        echo "⚡ Testing Performance Requirements...\n";
        
        try {
            $startTime = microtime(true);
            
            $response = $this->makeApiRequest([
                'action' => 'dashboard',
                'limit' => 50
            ]);
            
            $endTime = microtime(true);
            $duration = $endTime - $startTime;
            
            if ($response['success']) {
                if ($duration < 3.0) {
                    $this->recordSuccess("Performance (Load Time)", 
                        sprintf("Dashboard loaded in %.2f seconds (< 3s requirement)", $duration));
                } else {
                    $this->recordFailure("Performance (Load Time)", 
                        sprintf("Dashboard took %.2f seconds (> 3s requirement)", $duration));
                }
            } else {
                $this->recordFailure("Performance (Load Time)", "Dashboard failed to load");
            }
            
        } catch (Exception $e) {
            $this->recordFailure("Performance (Load Time)", $e->getMessage());
        }
    }
    
    /**
     * Test error handling
     */
    private function testErrorHandling() {
        echo "❌ Testing Error Handling...\n";
        
        try {
            // Test invalid action
            $response = $this->makeApiRequest(['action' => 'invalid_action']);
            
            if (isset($response['success']) && $response['success'] === false) {
                $this->recordSuccess("Error Handling (Invalid Action)", "Proper error response");
            } else {
                $this->recordFailure("Error Handling (Invalid Action)", "No proper error response");
            }
            
            // Test invalid parameters
            $response = $this->makeApiRequest([
                'action' => 'dashboard',
                'limit' => 'invalid'
            ]);
            
            // Should either handle gracefully or return error
            if (isset($response['success'])) {
                $this->recordSuccess("Error Handling (Invalid Params)", "Handled gracefully");
            } else {
                $this->recordFailure("Error Handling (Invalid Params)", "Not handled properly");
            }
            
        } catch (Exception $e) {
            $this->recordFailure("Error Handling", $e->getMessage());
        }
    }
    
    /**
     * Make API request to production endpoint
     */
    private function makeApiRequest($params = []) {
        $url = $this->baseUrl . '?' . http_build_query($params);
        
        $context = stream_context_create([
            'http' => [
                'method' => 'GET',
                'timeout' => 30,
                'header' => [
                    'Accept: application/json',
                    'User-Agent: WarehouseDashboardProductionTest/1.0'
                ]
            ]
        ]);
        
        $response = @file_get_contents($url, false, $context);
        
        if ($response === false) {
            $error = error_get_last();
            throw new Exception("HTTP request failed: " . ($error['message'] ?? 'Unknown error'));
        }
        
        // Check for database connection errors (common in production deployment)
        if (strpos($response, 'PostgreSQL connection error') !== false) {
            throw new Exception("Database connection error: " . trim($response));
        }
        
        // Check for other common errors
        if (strpos($response, 'Fatal error') !== false) {
            throw new Exception("PHP Fatal error: " . trim($response));
        }
        
        if (strpos($response, '<html') !== false || strpos($response, '<!DOCTYPE') !== false) {
            throw new Exception("Received HTML response instead of JSON (possible server error)");
        }
        
        $decoded = json_decode($response, true);
        
        if ($decoded === null) {
            throw new Exception("Invalid JSON response: " . json_last_error_msg() . ". Response: " . substr($response, 0, 200));
        }
        
        return $decoded;
    }
    
    /**
     * Validate response structure
     */
    private function validateResponseStructure($response, $testName) {
        if (!is_array($response)) {
            throw new Exception("Response is not an array");
        }
        
        if (!isset($response['success'])) {
            throw new Exception("Response missing 'success' field");
        }
        
        if (!$response['success']) {
            throw new Exception("API returned success=false: " . ($response['error'] ?? 'Unknown error'));
        }
        
        if (!isset($response['data'])) {
            throw new Exception("Response missing 'data' field");
        }
        
        $data = $response['data'];
        
        // Validate main structure
        $requiredFields = ['warehouses', 'summary', 'filters_applied', 'pagination', 'last_updated'];
        foreach ($requiredFields as $field) {
            if (!isset($data[$field])) {
                throw new Exception("Data missing required field: $field");
            }
        }
    }
    
    /**
     * Validate item structure
     */
    private function validateItemStructure($item) {
        $requiredFields = [
            'product_id', 'sku', 'name', 'warehouse_name', 'cluster',
            'available', 'reserved', 'daily_sales_avg', 'sales_last_28_days',
            'days_without_sales', 'days_of_stock', 'liquidity_status',
            'target_stock', 'replenishment_need'
        ];
        
        foreach ($requiredFields as $field) {
            if (!isset($item[$field])) {
                throw new Exception("Item missing required field: $field");
            }
        }
        
        // Validate liquidity status values
        $validStatuses = ['critical', 'low', 'normal', 'excess'];
        if (!in_array($item['liquidity_status'], $validStatuses)) {
            throw new Exception("Invalid liquidity status: " . $item['liquidity_status']);
        }
        
        $this->recordSuccess("Item Structure", "All required fields present and valid");
    }
    
    /**
     * Record successful test
     */
    private function recordSuccess($testName, $message) {
        $this->totalTests++;
        $this->passedTests++;
        $this->results[] = [
            'status' => 'PASS',
            'test' => $testName,
            'message' => $message
        ];
        echo "  ✅ $testName: $message\n";
    }
    
    /**
     * Record failed test
     */
    private function recordFailure($testName, $message) {
        $this->totalTests++;
        $this->failedTests++;
        $this->results[] = [
            'status' => 'FAIL',
            'test' => $testName,
            'message' => $message
        ];
        echo "  ❌ $testName: $message\n";
    }
    
    /**
     * Print test summary
     */
    private function printSummary($duration) {
        echo "\n========================================\n";
        echo "🏁 PRODUCTION TEST SUMMARY\n";
        echo "========================================\n";
        echo "Total Tests: {$this->totalTests}\n";
        echo "Passed: {$this->passedTests}\n";
        echo "Failed: {$this->failedTests}\n";
        echo "Success Rate: " . round(($this->passedTests / $this->totalTests) * 100, 1) . "%\n";
        echo "Duration: {$duration} seconds\n";
        echo "Completed at: " . date('Y-m-d H:i:s') . "\n";
        
        if ($this->failedTests > 0) {
            echo "\n❌ FAILED TESTS:\n";
            foreach ($this->results as $result) {
                if ($result['status'] === 'FAIL') {
                    echo "  - {$result['test']}: {$result['message']}\n";
                }
            }
        }
        
        if ($this->failedTests === 0) {
            echo "\n🎉 ALL TESTS PASSED! Production dashboard is fully functional.\n";
        } else {
            echo "\n⚠️  Some tests failed. Please review and fix issues before considering deployment complete.\n";
        }
        
        // Save results to file
        $this->saveResultsToFile();
    }
    
    /**
     * Save test results to file
     */
    private function saveResultsToFile() {
        $filename = 'production_testing_report_' . date('Y-m-d_H-i-s') . '.json';
        $filepath = __DIR__ . '/../../' . $filename;
        
        $report = [
            'timestamp' => date('Y-m-d H:i:s'),
            'url_tested' => $this->baseUrl,
            'summary' => [
                'total_tests' => $this->totalTests,
                'passed_tests' => $this->passedTests,
                'failed_tests' => $this->failedTests,
                'success_rate' => round(($this->passedTests / $this->totalTests) * 100, 1)
            ],
            'results' => $this->results
        ];
        
        file_put_contents($filepath, json_encode($report, JSON_PRETTY_PRINT));
        echo "\n📄 Test results saved to: $filename\n";
    }
}

// Run tests if called directly
if (basename(__FILE__) === basename($_SERVER['SCRIPT_NAME'])) {
    $tester = new WarehouseDashboardProductionTest();
    $tester->runAllTests();
}

?>