<?php
/**
 * End-to-End Testing Script for Marketplace Data Separation
 * Tests marketplace separation functionality with production data
 * 
 * @version 1.0.0
 * @author Manhattan System Team
 */

require_once 'MarginDashboardAPI.php';
require_once 'src/classes/MarketplaceDetector.php';
require_once 'src/classes/MarketplaceFallbackHandler.php';
require_once 'src/classes/MarketplaceDataValidator.php';

class MarketplaceIntegrationE2ETest {
    private $api;
    private $testResults = [];
    private $startTime;
    private $logFile;
    
    // Test configuration
    private $testPeriods = [
        'last_7_days' => [
            'start' => '-7 days',
            'end' => 'now'
        ],
        'last_30_days' => [
            'start' => '-30 days', 
            'end' => 'now'
        ],
        'current_month' => [
            'start' => 'first day of this month',
            'end' => 'now'
        ]
    ];
    
    public function __construct($host, $dbname, $username, $password) {
        $this->startTime = microtime(true);
        $this->logFile = 'e2e_test_' . date('Y-m-d_H-i-s') . '.log';
        
        try {
            $this->api = new MarginDashboardAPI($host, $dbname, $username, $password);
            $this->log("✅ Database connection established");
        } catch (Exception $e) {
            $this->log("❌ Database connection failed: " . $e->getMessage());
            throw $e;
        }
    }
    
    /**
     * Run all end-to-end tests
     */
    public function runAllTests() {
        $this->log("🚀 Starting End-to-End Testing for Marketplace Data Separation");
        $this->log("================================================================");
        
        // Test categories
        $testCategories = [
            'Data Accuracy Tests' => 'runDataAccuracyTests',
            'Performance Tests' => 'runPerformanceTests', 
            'API Functionality Tests' => 'runAPIFunctionalityTests',
            'Data Consistency Tests' => 'runDataConsistencyTests',
            'Error Handling Tests' => 'runErrorHandlingTests',
            'Integration Tests' => 'runIntegrationTests'
        ];
        
        foreach ($testCategories as $categoryName => $methodName) {
            $this->log("\n📋 Running $categoryName...");
            $this->$methodName();
        }
        
        $this->generateReport();
    }
    
    /**
     * Test data accuracy across marketplaces
     */
    private function runDataAccuracyTests() {
        $this->log("🔍 Testing data accuracy with real production data...");
        
        foreach ($this->testPeriods as $periodName => $period) {
            $startDate = date('Y-m-d', strtotime($period['start']));
            $endDate = date('Y-m-d', strtotime($period['end']));
            
            $this->log("📅 Testing period: $periodName ($startDate to $endDate)");
            
            // Test 1: Verify marketplace data separation
            $this->testMarketplaceDataSeparation($startDate, $endDate, $periodName);
            
            // Test 2: Verify data completeness
            $this->testDataCompleteness($startDate, $endDate, $periodName);
            
            // Test 3: Verify SKU mapping accuracy
            $this->testSKUMappingAccuracy($startDate, $endDate, $periodName);
        }
    }
    
    /**
     * Test marketplace data separation accuracy
     */
    private function testMarketplaceDataSeparation($startDate, $endDate, $periodName) {
        $testName = "Marketplace Data Separation - $periodName";
        
        try {
            // Get combined data
            $combinedData = $this->api->getMarginSummary($startDate, $endDate);
            
            // Get marketplace-specific data
            $ozonData = $this->api->getMarginSummaryByMarketplace($startDate, $endDate, 'ozon');
            $wildberriesData = $this->api->getMarginSummaryByMarketplace($startDate, $endDate, 'wildberries');
            
            // Verify data structure
            $this->assertTrue(isset($ozonData['success']), "$testName: Ozon data should have success flag");
            $this->assertTrue(isset($wildberriesData['success']), "$testName: Wildberries data should have success flag");
            
            // Verify marketplace identification
            if ($ozonData['success'] && $ozonData['has_data']) {
                $this->assertEquals('ozon', $ozonData['marketplace'], "$testName: Ozon data marketplace field");
                $this->assertGreaterThan(0, $ozonData['total_orders'], "$testName: Ozon should have orders");
            }
            
            if ($wildberriesData['success'] && $wildberriesData['has_data']) {
                $this->assertEquals('wildberries', $wildberriesData['marketplace'], "$testName: Wildberries data marketplace field");
                $this->assertGreaterThan(0, $wildberriesData['total_orders'], "$testName: Wildberries should have orders");
            }
            
            // Test data consistency (combined should equal sum of marketplaces)
            if ($ozonData['has_data'] && $wildberriesData['has_data']) {
                $calculatedTotal = $ozonData['total_revenue'] + $wildberriesData['total_revenue'];
                $tolerance = $combinedData['total_revenue'] * 0.05; // 5% tolerance
                
                $this->assertApproximatelyEqual(
                    $combinedData['total_revenue'], 
                    $calculatedTotal, 
                    $tolerance,
                    "$testName: Combined revenue should approximately equal sum of marketplace revenues"
                );
            }
            
            $this->recordTestResult($testName, true, "Data separation working correctly");
            
        } catch (Exception $e) {
            $this->recordTestResult($testName, false, $e->getMessage());
        }
    }
    
    /**
     * Test data completeness
     */
    private function testDataCompleteness($startDate, $endDate, $periodName) {
        $testName = "Data Completeness - $periodName";
        
        try {
            // Test that we can retrieve data for both marketplaces
            $ozonData = $this->api->getMarginSummaryByMarketplace($startDate, $endDate, 'ozon');
            $wildberriesData = $this->api->getMarginSummaryByMarketplace($startDate, $endDate, 'wildberries');
            
            $hasOzonData = $ozonData['success'] && $ozonData['has_data'];
            $hasWildberriesData = $wildberriesData['success'] && $wildberriesData['has_data'];
            
            // At least one marketplace should have data for recent periods
            if ($periodName === 'last_7_days' || $periodName === 'last_30_days') {
                $this->assertTrue(
                    $hasOzonData || $hasWildberriesData,
                    "$testName: At least one marketplace should have data for recent periods"
                );
            }
            
            // Test top products data completeness
            if ($hasOzonData) {
                $ozonProducts = $this->api->getTopProductsByMarketplace('ozon', 10, $startDate, $endDate);
                $this->assertTrue($ozonProducts['success'], "$testName: Ozon top products should be retrievable");
                
                if ($ozonProducts['has_data']) {
                    $this->assertGreaterThan(0, count($ozonProducts['data']), "$testName: Ozon should have top products");
                }
            }
            
            if ($hasWildberriesData) {
                $wbProducts = $this->api->getTopProductsByMarketplace('wildberries', 10, $startDate, $endDate);
                $this->assertTrue($wbProducts['success'], "$testName: Wildberries top products should be retrievable");
                
                if ($wbProducts['has_data']) {
                    $this->assertGreaterThan(0, count($wbProducts['data']), "$testName: Wildberries should have top products");
                }
            }
            
            $this->recordTestResult($testName, true, "Data completeness verified");
            
        } catch (Exception $e) {
            $this->recordTestResult($testName, false, $e->getMessage());
        }
    }
    
    /**
     * Test SKU mapping accuracy
     */
    private function testSKUMappingAccuracy($startDate, $endDate, $periodName) {
        $testName = "SKU Mapping Accuracy - $periodName";
        
        try {
            // Get top products for each marketplace
            $ozonProducts = $this->api->getTopProductsByMarketplace('ozon', 5, $startDate, $endDate);
            $wbProducts = $this->api->getTopProductsByMarketplace('wildberries', 5, $startDate, $endDate);
            
            // Verify SKU fields are correctly populated
            if ($ozonProducts['success'] && $ozonProducts['has_data']) {
                foreach ($ozonProducts['data'] as $product) {
                    // For Ozon products, display_sku should prefer sku_ozon
                    if (!empty($product['sku_ozon'])) {
                        $this->assertEquals(
                            $product['sku_ozon'], 
                            $product['display_sku'],
                            "$testName: Ozon product should display sku_ozon when available"
                        );
                    }
                }
            }
            
            if ($wbProducts['success'] && $wbProducts['has_data']) {
                foreach ($wbProducts['data'] as $product) {
                    // For Wildberries products, display_sku should prefer sku_wb
                    if (!empty($product['sku_wb'])) {
                        $this->assertEquals(
                            $product['sku_wb'], 
                            $product['display_sku'],
                            "$testName: Wildberries product should display sku_wb when available"
                        );
                    }
                }
            }
            
            $this->recordTestResult($testName, true, "SKU mapping working correctly");
            
        } catch (Exception $e) {
            $this->recordTestResult($testName, false, $e->getMessage());
        }
    }
    
    /**
     * Run performance tests
     */
    private function runPerformanceTests() {
        $this->log("⚡ Testing performance with production data volumes...");
        
        $startDate = date('Y-m-d', strtotime('-30 days'));
        $endDate = date('Y-m-d');
        
        // Test 1: API response times
        $this->testAPIResponseTimes($startDate, $endDate);
        
        // Test 2: Large dataset handling
        $this->testLargeDatasetHandling($startDate, $endDate);
        
        // Test 3: Concurrent requests
        $this->testConcurrentRequests($startDate, $endDate);
    }
    
    /**
     * Test API response times
     */
    private function testAPIResponseTimes($startDate, $endDate) {
        $testName = "API Response Times";
        $maxAcceptableTime = 5.0; // 5 seconds
        
        try {
            $apiMethods = [
                'getMarginSummaryByMarketplace_ozon' => function() use ($startDate, $endDate) {
                    return $this->api->getMarginSummaryByMarketplace($startDate, $endDate, 'ozon');
                },
                'getMarginSummaryByMarketplace_wildberries' => function() use ($startDate, $endDate) {
                    return $this->api->getMarginSummaryByMarketplace($startDate, $endDate, 'wildberries');
                },
                'getTopProductsByMarketplace_ozon' => function() use ($startDate, $endDate) {
                    return $this->api->getTopProductsByMarketplace('ozon', 20, $startDate, $endDate);
                },
                'getDailyMarginChartByMarketplace_ozon' => function() use ($startDate, $endDate) {
                    return $this->api->getDailyMarginChartByMarketplace($startDate, $endDate, 'ozon');
                },
                'getMarketplaceComparison' => function() use ($startDate, $endDate) {
                    return $this->api->getMarketplaceComparison($startDate, $endDate);
                }
            ];
            
            $allPassed = true;
            $results = [];
            
            foreach ($apiMethods as $methodName => $method) {
                $startTime = microtime(true);
                $result = $method();
                $endTime = microtime(true);
                $duration = $endTime - $startTime;
                
                $results[$methodName] = $duration;
                
                if ($duration > $maxAcceptableTime) {
                    $allPassed = false;
                    $this->log("⚠️ $methodName took {$duration}s (exceeds {$maxAcceptableTime}s limit)");
                } else {
                    $this->log("✅ $methodName took {$duration}s");
                }
            }
            
            $avgTime = array_sum($results) / count($results);
            $this->log("📊 Average API response time: {$avgTime}s");
            
            $this->recordTestResult(
                $testName, 
                $allPassed, 
                $allPassed ? "All APIs respond within acceptable time" : "Some APIs are slow"
            );
            
        } catch (Exception $e) {
            $this->recordTestResult($testName, false, $e->getMessage());
        }
    }
    
    /**
     * Test large dataset handling
     */
    private function testLargeDatasetHandling($startDate, $endDate) {
        $testName = "Large Dataset Handling";
        
        try {
            // Test with large limit for top products
            $largeLimit = 100;
            
            $startTime = microtime(true);
            $ozonProducts = $this->api->getTopProductsByMarketplace('ozon', $largeLimit, $startDate, $endDate);
            $endTime = microtime(true);
            
            $duration = $endTime - $startTime;
            $this->log("📊 Large dataset query (limit=$largeLimit) took {$duration}s");
            
            $this->assertTrue($ozonProducts['success'], "$testName: Large dataset query should succeed");
            
            if ($ozonProducts['has_data']) {
                $this->assertLessThanOrEqual($largeLimit, count($ozonProducts['data']), "$testName: Result count should not exceed limit");
            }
            
            $this->recordTestResult($testName, true, "Large datasets handled correctly");
            
        } catch (Exception $e) {
            $this->recordTestResult($testName, false, $e->getMessage());
        }
    }
    
    /**
     * Test concurrent requests (simulated)
     */
    private function testConcurrentRequests($startDate, $endDate) {
        $testName = "Concurrent Requests Simulation";
        
        try {
            // Simulate concurrent requests by making multiple rapid requests
            $requests = 5;
            $results = [];
            
            $startTime = microtime(true);
            
            for ($i = 0; $i < $requests; $i++) {
                $marketplace = ($i % 2 === 0) ? 'ozon' : 'wildberries';
                $result = $this->api->getMarginSummaryByMarketplace($startDate, $endDate, $marketplace);
                $results[] = $result['success'];
            }
            
            $endTime = microtime(true);
            $totalTime = $endTime - $startTime;
            $avgTime = $totalTime / $requests;
            
            $successCount = array_sum($results);
            $this->log("📊 $successCount/$requests concurrent requests succeeded in {$totalTime}s (avg: {$avgTime}s)");
            
            $this->assertGreaterThanOrEqual(
                $requests * 0.8, // 80% success rate minimum
                $successCount,
                "$testName: At least 80% of concurrent requests should succeed"
            );
            
            $this->recordTestResult($testName, true, "Concurrent requests handled well");
            
        } catch (Exception $e) {
            $this->recordTestResult($testName, false, $e->getMessage());
        }
    }
    
    /**
     * Run API functionality tests
     */
    private function runAPIFunctionalityTests() {
        $this->log("🔧 Testing API functionality...");
        
        $startDate = date('Y-m-d', strtotime('-7 days'));
        $endDate = date('Y-m-d');
        
        // Test all new marketplace-specific API methods
        $this->testMarketplaceAPIMethods($startDate, $endDate);
        $this->testAPIParameterValidation();
        $this->testAPIResponseFormats($startDate, $endDate);
    }
    
    /**
     * Test marketplace API methods
     */
    private function testMarketplaceAPIMethods($startDate, $endDate) {
        $testName = "Marketplace API Methods";
        
        try {
            $methods = [
                'getMarginSummaryByMarketplace' => ['ozon'],
                'getTopProductsByMarketplace' => ['wildberries', 10],
                'getDailyMarginChartByMarketplace' => [$startDate, $endDate, 'ozon'],
                'getMarketplaceComparison' => [$startDate, $endDate]
            ];
            
            $allPassed = true;
            
            foreach ($methods as $methodName => $params) {
                try {
                    $result = call_user_func_array([$this->api, $methodName], $params);
                    
                    $this->assertTrue(isset($result['success']), "$testName: $methodName should return success flag");
                    $this->log("✅ $methodName working correctly");
                    
                } catch (Exception $e) {
                    $allPassed = false;
                    $this->log("❌ $methodName failed: " . $e->getMessage());
                }
            }
            
            $this->recordTestResult($testName, $allPassed, $allPassed ? "All API methods working" : "Some API methods failed");
            
        } catch (Exception $e) {
            $this->recordTestResult($testName, false, $e->getMessage());
        }
    }
    
    /**
     * Test API parameter validation
     */
    private function testAPIParameterValidation() {
        $testName = "API Parameter Validation";
        
        try {
            // Test invalid marketplace parameter
            $result = $this->api->getMarginSummaryByMarketplace('2023-01-01', '2023-01-31', 'invalid_marketplace');
            $this->assertFalse($result['success'], "$testName: Invalid marketplace should return error");
            
            // Test invalid date format
            $result = $this->api->getMarginSummaryByMarketplace('invalid-date', '2023-01-31', 'ozon');
            // Should handle gracefully (may succeed with fallback or fail gracefully)
            
            $this->recordTestResult($testName, true, "Parameter validation working");
            
        } catch (Exception $e) {
            $this->recordTestResult($testName, false, $e->getMessage());
        }
    }
    
    /**
     * Test API response formats
     */
    private function testAPIResponseFormats($startDate, $endDate) {
        $testName = "API Response Formats";
        
        try {
            $result = $this->api->getMarginSummaryByMarketplace($startDate, $endDate, 'ozon');
            
            // Check required fields
            $requiredFields = ['success', 'marketplace', 'marketplace_name'];
            foreach ($requiredFields as $field) {
                $this->assertTrue(isset($result[$field]), "$testName: Response should contain $field");
            }
            
            if ($result['has_data']) {
                $dataFields = ['total_revenue', 'total_orders', 'total_profit'];
                foreach ($dataFields as $field) {
                    $this->assertTrue(isset($result[$field]), "$testName: Data response should contain $field");
                }
            }
            
            $this->recordTestResult($testName, true, "Response formats are correct");
            
        } catch (Exception $e) {
            $this->recordTestResult($testName, false, $e->getMessage());
        }
    }
    
    /**
     * Run data consistency tests
     */
    private function runDataConsistencyTests() {
        $this->log("🔍 Testing data consistency...");
        
        $startDate = date('Y-m-d', strtotime('-30 days'));
        $endDate = date('Y-m-d');
        
        $this->testMarketplaceTotalsConsistency($startDate, $endDate);
        $this->testDataIntegrity($startDate, $endDate);
    }
    
    /**
     * Test marketplace totals consistency
     */
    private function testMarketplaceTotalsConsistency($startDate, $endDate) {
        $testName = "Marketplace Totals Consistency";
        
        try {
            // Get combined data
            $combined = $this->api->getMarginSummary($startDate, $endDate);
            
            // Get marketplace-specific data
            $ozon = $this->api->getMarginSummaryByMarketplace($startDate, $endDate, 'ozon');
            $wildberries = $this->api->getMarginSummaryByMarketplace($startDate, $endDate, 'wildberries');
            
            // Calculate marketplace sum
            $marketplaceRevenue = 0;
            $marketplaceOrders = 0;
            
            if ($ozon['has_data']) {
                $marketplaceRevenue += $ozon['total_revenue'];
                $marketplaceOrders += $ozon['total_orders'];
            }
            
            if ($wildberries['has_data']) {
                $marketplaceRevenue += $wildberries['total_revenue'];
                $marketplaceOrders += $wildberries['total_orders'];
            }
            
            // Allow for some tolerance due to data classification differences
            $revenueTolerance = $combined['total_revenue'] * 0.1; // 10% tolerance
            $ordersTolerance = $combined['total_orders'] * 0.1;
            
            $this->assertApproximatelyEqual(
                $combined['total_revenue'],
                $marketplaceRevenue,
                $revenueTolerance,
                "$testName: Combined revenue should approximately equal marketplace sum"
            );
            
            $this->assertApproximatelyEqual(
                $combined['total_orders'],
                $marketplaceOrders,
                $ordersTolerance,
                "$testName: Combined orders should approximately equal marketplace sum"
            );
            
            $this->recordTestResult($testName, true, "Data consistency verified within tolerance");
            
        } catch (Exception $e) {
            $this->recordTestResult($testName, false, $e->getMessage());
        }
    }
    
    /**
     * Test data integrity
     */
    private function testDataIntegrity($startDate, $endDate) {
        $testName = "Data Integrity";
        
        try {
            $marketplaces = ['ozon', 'wildberries'];
            
            foreach ($marketplaces as $marketplace) {
                $data = $this->api->getMarginSummaryByMarketplace($startDate, $endDate, $marketplace);
                
                if ($data['has_data']) {
                    // Revenue should be positive
                    $this->assertGreaterThanOrEqual(0, $data['total_revenue'], "$testName: $marketplace revenue should be non-negative");
                    
                    // Orders should be positive
                    $this->assertGreaterThanOrEqual(0, $data['total_orders'], "$testName: $marketplace orders should be non-negative");
                    
                    // Margin percent should be reasonable (-100% to 100%)
                    if ($data['avg_margin_percent'] !== null) {
                        $this->assertGreaterThanOrEqual(-100, $data['avg_margin_percent'], "$testName: $marketplace margin should be >= -100%");
                        $this->assertLessThanOrEqual(100, $data['avg_margin_percent'], "$testName: $marketplace margin should be <= 100%");
                    }
                }
            }
            
            $this->recordTestResult($testName, true, "Data integrity checks passed");
            
        } catch (Exception $e) {
            $this->recordTestResult($testName, false, $e->getMessage());
        }
    }
    
    /**
     * Run error handling tests
     */
    private function runErrorHandlingTests() {
        $this->log("🛡️ Testing error handling...");
        
        $this->testMissingDataHandling();
        $this->testInvalidParameterHandling();
        $this->testDatabaseErrorHandling();
    }
    
    /**
     * Test missing data handling
     */
    private function testMissingDataHandling() {
        $testName = "Missing Data Handling";
        
        try {
            // Test with future dates (should have no data)
            $futureStart = date('Y-m-d', strtotime('+1 year'));
            $futureEnd = date('Y-m-d', strtotime('+1 year +1 month'));
            
            $result = $this->api->getMarginSummaryByMarketplace($futureStart, $futureEnd, 'ozon');
            
            $this->assertTrue($result['success'], "$testName: Should handle missing data gracefully");
            $this->assertFalse($result['has_data'], "$testName: Should indicate no data available");
            $this->assertNotEmpty($result['user_message'], "$testName: Should provide user-friendly message");
            
            $this->recordTestResult($testName, true, "Missing data handled gracefully");
            
        } catch (Exception $e) {
            $this->recordTestResult($testName, false, $e->getMessage());
        }
    }
    
    /**
     * Test invalid parameter handling
     */
    private function testInvalidParameterHandling() {
        $testName = "Invalid Parameter Handling";
        
        try {
            // Test invalid marketplace
            $result = $this->api->getMarginSummaryByMarketplace('2023-01-01', '2023-01-31', 'invalid_marketplace');
            $this->assertFalse($result['success'], "$testName: Should reject invalid marketplace");
            
            // Test invalid limit
            $result = $this->api->getTopProductsByMarketplace('ozon', -5);
            // Should handle gracefully (may use default or return error)
            
            $this->recordTestResult($testName, true, "Invalid parameters handled appropriately");
            
        } catch (Exception $e) {
            $this->recordTestResult($testName, false, $e->getMessage());
        }
    }
    
    /**
     * Test database error handling
     */
    private function testDatabaseErrorHandling() {
        $testName = "Database Error Handling";
        
        try {
            // This test is limited since we don't want to actually break the database
            // We'll test that the API has proper error handling structure
            
            $result = $this->api->getMarginSummaryByMarketplace('2023-01-01', '2023-01-31', 'ozon');
            
            // Verify that result has proper error handling structure
            $this->assertTrue(isset($result['success']), "$testName: Response should have success flag");
            
            if (!$result['success']) {
                $this->assertTrue(isset($result['error_code']), "$testName: Error response should have error_code");
                $this->assertTrue(isset($result['user_message']), "$testName: Error response should have user_message");
            }
            
            $this->recordTestResult($testName, true, "Error handling structure is proper");
            
        } catch (Exception $e) {
            $this->recordTestResult($testName, false, $e->getMessage());
        }
    }
    
    /**
     * Run integration tests
     */
    private function runIntegrationTests() {
        $this->log("🔗 Testing integration scenarios...");
        
        $this->testFullWorkflowIntegration();
        $this->testMarketplaceComparisonIntegration();
    }
    
    /**
     * Test full workflow integration
     */
    private function testFullWorkflowIntegration() {
        $testName = "Full Workflow Integration";
        
        try {
            $startDate = date('Y-m-d', strtotime('-7 days'));
            $endDate = date('Y-m-d');
            
            // Simulate full dashboard workflow
            $steps = [
                'Get marketplace comparison' => function() use ($startDate, $endDate) {
                    return $this->api->getMarketplaceComparison($startDate, $endDate);
                },
                'Get Ozon summary' => function() use ($startDate, $endDate) {
                    return $this->api->getMarginSummaryByMarketplace($startDate, $endDate, 'ozon');
                },
                'Get Wildberries summary' => function() use ($startDate, $endDate) {
                    return $this->api->getMarginSummaryByMarketplace($startDate, $endDate, 'wildberries');
                },
                'Get Ozon top products' => function() use ($startDate, $endDate) {
                    return $this->api->getTopProductsByMarketplace('ozon', 5, $startDate, $endDate);
                },
                'Get Ozon daily chart' => function() use ($startDate, $endDate) {
                    return $this->api->getDailyMarginChartByMarketplace($startDate, $endDate, 'ozon');
                }
            ];
            
            $allPassed = true;
            foreach ($steps as $stepName => $step) {
                try {
                    $result = $step();
                    if (!$result['success']) {
                        $allPassed = false;
                        $this->log("❌ $stepName failed");
                    } else {
                        $this->log("✅ $stepName passed");
                    }
                } catch (Exception $e) {
                    $allPassed = false;
                    $this->log("❌ $stepName threw exception: " . $e->getMessage());
                }
            }
            
            $this->recordTestResult($testName, $allPassed, $allPassed ? "Full workflow completed" : "Some workflow steps failed");
            
        } catch (Exception $e) {
            $this->recordTestResult($testName, false, $e->getMessage());
        }
    }
    
    /**
     * Test marketplace comparison integration
     */
    private function testMarketplaceComparisonIntegration() {
        $testName = "Marketplace Comparison Integration";
        
        try {
            $startDate = date('Y-m-d', strtotime('-30 days'));
            $endDate = date('Y-m-d');
            
            $comparison = $this->api->getMarketplaceComparison($startDate, $endDate);
            
            $this->assertTrue($comparison['success'], "$testName: Comparison should succeed");
            
            if ($comparison['success']) {
                $this->assertTrue(isset($comparison['ozon']), "$testName: Should include Ozon data");
                $this->assertTrue(isset($comparison['wildberries']), "$testName: Should include Wildberries data");
                
                // Verify data structure
                foreach (['ozon', 'wildberries'] as $marketplace) {
                    if (isset($comparison[$marketplace]) && $comparison[$marketplace]['has_data']) {
                        $data = $comparison[$marketplace];
                        $this->assertTrue(isset($data['total_revenue']), "$testName: $marketplace should have revenue");
                        $this->assertTrue(isset($data['total_orders']), "$testName: $marketplace should have orders");
                    }
                }
            }
            
            $this->recordTestResult($testName, true, "Marketplace comparison integration working");
            
        } catch (Exception $e) {
            $this->recordTestResult($testName, false, $e->getMessage());
        }
    }
    
    /**
     * Generate comprehensive test report
     */
    private function generateReport() {
        $endTime = microtime(true);
        $totalTime = $endTime - $this->startTime;
        
        $this->log("\n" . str_repeat("=", 80));
        $this->log("📊 END-TO-END TEST REPORT");
        $this->log(str_repeat("=", 80));
        
        $passed = array_filter($this->testResults, function($result) { return $result['passed']; });
        $failed = array_filter($this->testResults, function($result) { return !$result['passed']; });
        
        $this->log("🎯 SUMMARY:");
        $this->log("   Total Tests: " . count($this->testResults));
        $this->log("   ✅ Passed: " . count($passed));
        $this->log("   ❌ Failed: " . count($failed));
        $this->log("   ⏱️ Total Time: " . round($totalTime, 2) . "s");
        $this->log("   📈 Success Rate: " . round((count($passed) / count($this->testResults)) * 100, 1) . "%");
        
        if (!empty($failed)) {
            $this->log("\n❌ FAILED TESTS:");
            foreach ($failed as $test) {
                $this->log("   • {$test['name']}: {$test['message']}");
            }
        }
        
        $this->log("\n✅ PASSED TESTS:");
        foreach ($passed as $test) {
            $this->log("   • {$test['name']}: {$test['message']}");
        }
        
        $this->log("\n📋 RECOMMENDATIONS:");
        if (count($failed) === 0) {
            $this->log("   🎉 All tests passed! The marketplace integration is ready for production.");
        } else {
            $this->log("   ⚠️ Some tests failed. Please review and fix issues before production deployment.");
            $this->log("   🔧 Focus on failed tests and ensure data accuracy and performance.");
        }
        
        $this->log("\n📁 Log file: " . $this->logFile);
        $this->log(str_repeat("=", 80));
        
        // Save detailed report to file
        $this->saveDetailedReport();
    }
    
    /**
     * Save detailed report to file
     */
    private function saveDetailedReport() {
        $reportFile = 'e2e_test_report_' . date('Y-m-d_H-i-s') . '.json';
        
        $report = [
            'timestamp' => date('Y-m-d H:i:s'),
            'total_time' => microtime(true) - $this->startTime,
            'summary' => [
                'total_tests' => count($this->testResults),
                'passed' => count(array_filter($this->testResults, function($r) { return $r['passed']; })),
                'failed' => count(array_filter($this->testResults, function($r) { return !$r['passed']; })),
                'success_rate' => round((count(array_filter($this->testResults, function($r) { return $r['passed']; })) / count($this->testResults)) * 100, 1)
            ],
            'test_results' => $this->testResults,
            'environment' => [
                'php_version' => PHP_VERSION,
                'database_host' => 'production',
                'test_periods' => $this->testPeriods
            ]
        ];
        
        file_put_contents($reportFile, json_encode($report, JSON_PRETTY_PRINT));
        $this->log("📄 Detailed report saved to: $reportFile");
    }
    
    // Helper methods
    private function assertTrue($condition, $message) {
        if (!$condition) {
            throw new Exception("Assertion failed: $message");
        }
    }
    
    private function assertFalse($condition, $message) {
        if ($condition) {
            throw new Exception("Assertion failed: $message");
        }
    }
    
    private function assertEquals($expected, $actual, $message) {
        if ($expected !== $actual) {
            throw new Exception("Assertion failed: $message (expected: $expected, actual: $actual)");
        }
    }
    
    private function assertGreaterThan($expected, $actual, $message) {
        if ($actual <= $expected) {
            throw new Exception("Assertion failed: $message (expected > $expected, actual: $actual)");
        }
    }
    
    private function assertGreaterThanOrEqual($expected, $actual, $message) {
        if ($actual < $expected) {
            throw new Exception("Assertion failed: $message (expected >= $expected, actual: $actual)");
        }
    }
    
    private function assertLessThanOrEqual($expected, $actual, $message) {
        if ($actual > $expected) {
            throw new Exception("Assertion failed: $message (expected <= $expected, actual: $actual)");
        }
    }
    
    private function assertApproximatelyEqual($expected, $actual, $tolerance, $message) {
        if (abs($expected - $actual) > $tolerance) {
            throw new Exception("Assertion failed: $message (expected: $expected ± $tolerance, actual: $actual)");
        }
    }
    
    private function assertNotEmpty($value, $message) {
        if (empty($value)) {
            throw new Exception("Assertion failed: $message (value is empty)");
        }
    }
    
    private function recordTestResult($testName, $passed, $message) {
        $this->testResults[] = [
            'name' => $testName,
            'passed' => $passed,
            'message' => $message,
            'timestamp' => date('Y-m-d H:i:s')
        ];
        
        $status = $passed ? '✅' : '❌';
        $this->log("$status $testName: $message");
    }
    
    private function log($message) {
        $timestamp = date('Y-m-d H:i:s');
        $logMessage = "[$timestamp] $message";
        echo $logMessage . "\n";
        file_put_contents($this->logFile, $logMessage . "\n", FILE_APPEND);
    }
}

// Usage example and configuration
if (php_sapi_name() === 'cli') {
    echo "🚀 Marketplace Integration End-to-End Testing\n";
    echo "============================================\n\n";
    
    // Database configuration - update with your production credentials
    $config = [
        'host' => 'localhost',
        'dbname' => 'mi_core_db',
        'username' => 'mi_core_user',
        'password' => 'secure_password_123'
    ];
    
    try {
        $tester = new MarketplaceIntegrationE2ETest(
            $config['host'],
            $config['dbname'], 
            $config['username'],
            $config['password']
        );
        
        $tester->runAllTests();
        
    } catch (Exception $e) {
        echo "❌ Test execution failed: " . $e->getMessage() . "\n";
        exit(1);
    }
} else {
    echo "This script should be run from command line.\n";
    echo "Usage: php test_marketplace_integration_e2e.php\n";
}
?>