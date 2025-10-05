<?php
/**
 * Ozon Analytics Data Validation Test Suite
 * 
 * This test suite validates the correctness of data processing, calculations,
 * and business logic in the Ozon Analytics integration. It includes:
 * - Funnel conversion calculations
 * - Demographics data normalization
 * - Campaign metrics calculations
 * - Data consistency checks
 * - Edge case handling
 * 
 * @version 1.0
 * @author Manhattan System
 */

require_once '../src/classes/OzonAnalyticsAPI.php';

class OzonDataValidationTest {
    
    private $ozonAPI;
    private $testResults = [];
    
    public function __construct() {
        // Initialize with test credentials
        $this->ozonAPI = new OzonAnalyticsAPI('test_client', 'test_key');
    }
    
    /**
     * Run all data validation tests
     */
    public function runAllTests() {
        $this->log("🔍 Starting Ozon Analytics Data Validation Tests", 'INFO');
        $this->log(str_repeat("=", 60), 'INFO');
        
        $testMethods = [
            'testFunnelConversionCalculations',
            'testFunnelDataConsistency',
            'testFunnelEdgeCases',
            'testDemographicsNormalization',
            'testDemographicsAggregation',
            'testCampaignMetricsCalculation',
            'testCampaignROASCalculation',
            'testDataRangeValidation',
            'testDataTypeValidation',
            'testBusinessLogicValidation',
            'testPerformanceMetrics',
            'testDataIntegrity'
        ];
        
        $passed = 0;
        $failed = 0;
        
        foreach ($testMethods as $method) {
            try {
                $this->log("\n📋 Running: $method", 'INFO');
                $result = $this->$method();
                
                if ($result) {
                    $passed++;
                    $this->testResults[$method] = 'PASSED';
                    $this->log("✅ $method: PASSED", 'SUCCESS');
                } else {
                    $failed++;
                    $this->testResults[$method] = 'FAILED';
                    $this->log("❌ $method: FAILED", 'ERROR');
                }
            } catch (Exception $e) {
                $failed++;
                $this->testResults[$method] = 'ERROR: ' . $e->getMessage();
                $this->log("💥 $method: ERROR - " . $e->getMessage(), 'ERROR');
            }
        }
        
        $this->printTestSummary($passed, $failed);
        return $failed === 0;
    }
    
    /**
     * Test funnel conversion calculations
     */
    private function testFunnelConversionCalculations() {
        $testCases = [
            // [views, cart_additions, orders, expected_view_to_cart, expected_cart_to_order, expected_overall]
            [1000, 150, 45, 15.00, 30.00, 4.50],
            [500, 100, 25, 20.00, 25.00, 5.00],
            [2000, 200, 50, 10.00, 25.00, 2.50],
            [100, 50, 50, 50.00, 100.00, 50.00], // Perfect conversion from cart to order
            [1000, 0, 0, 0.00, 0.00, 0.00], // No conversions
        ];
        
        $reflection = new ReflectionClass($this->ozonAPI);
        $processMethod = $reflection->getMethod('processFunnelData');
        $processMethod->setAccessible(true);
        
        foreach ($testCases as $i => [$views, $cartAdditions, $orders, $expectedViewToCart, $expectedCartToOrder, $expectedOverall]) {
            $mockResponse = [
                'data' => [
                    [
                        'views' => $views,
                        'cart_additions' => $cartAdditions,
                        'orders' => $orders
                    ]
                ]
            ];
            
            $processedData = $processMethod->invoke(
                $this->ozonAPI,
                $mockResponse,
                '2025-01-01',
                '2025-01-31',
                []
            );
            
            $result = $processedData[0];
            
            if ($result['conversion_view_to_cart'] !== $expectedViewToCart) {
                $this->log("❌ Test case $i: View to cart conversion. Expected: $expectedViewToCart, Got: {$result['conversion_view_to_cart']}", 'ERROR');
                return false;
            }
            
            if ($result['conversion_cart_to_order'] !== $expectedCartToOrder) {
                $this->log("❌ Test case $i: Cart to order conversion. Expected: $expectedCartToOrder, Got: {$result['conversion_cart_to_order']}", 'ERROR');
                return false;
            }
            
            if ($result['conversion_overall'] !== $expectedOverall) {
                $this->log("❌ Test case $i: Overall conversion. Expected: $expectedOverall, Got: {$result['conversion_overall']}", 'ERROR');
                return false;
            }
        }
        
        return true;
    }
    
    /**
     * Test funnel data consistency
     */
    private function testFunnelDataConsistency() {
        $testCases = [
            // Test cases where data should be corrected for logical consistency
            [1000, 1500, 500], // Cart additions > views (should be corrected)
            [1000, 150, 200],  // Orders > cart additions (should be corrected)
            [1000, 1200, 1300], // Both corrections needed
        ];
        
        $reflection = new ReflectionClass($this->ozonAPI);
        $processMethod = $reflection->getMethod('processFunnelData');
        $processMethod->setAccessible(true);
        
        foreach ($testCases as $i => [$views, $cartAdditions, $orders]) {
            $mockResponse = [
                'data' => [
                    [
                        'views' => $views,
                        'cart_additions' => $cartAdditions,
                        'orders' => $orders
                    ]
                ]
            ];
            
            $processedData = $processMethod->invoke(
                $this->ozonAPI,
                $mockResponse,
                '2025-01-01',
                '2025-01-31',
                []
            );
            
            $result = $processedData[0];
            
            // Cart additions should not exceed views
            if ($result['cart_additions'] > $result['views']) {
                $this->log("❌ Test case $i: Cart additions ({$result['cart_additions']}) should not exceed views ({$result['views']})", 'ERROR');
                return false;
            }
            
            // Orders should not exceed cart additions
            if ($result['orders'] > $result['cart_additions']) {
                $this->log("❌ Test case $i: Orders ({$result['orders']}) should not exceed cart additions ({$result['cart_additions']})", 'ERROR');
                return false;
            }
            
            // Conversions should not exceed 100%
            if ($result['conversion_view_to_cart'] > 100.00 ||
                $result['conversion_cart_to_order'] > 100.00 ||
                $result['conversion_overall'] > 100.00) {
                $this->log("❌ Test case $i: Conversions should not exceed 100%", 'ERROR');
                return false;
            }
        }
        
        return true;
    }
    
    /**
     * Test funnel edge cases
     */
    private function testFunnelEdgeCases() {
        $edgeCases = [
            // [description, mockResponse, expectedBehavior]
            [
                'Empty data array',
                ['data' => []],
                'Should return default zero record'
            ],
            [
                'Null data',
                ['data' => null],
                'Should return default zero record'
            ],
            [
                'Missing data key',
                [],
                'Should return default zero record'
            ],
            [
                'Negative values',
                ['data' => [['views' => -100, 'cart_additions' => -50, 'orders' => -10]]],
                'Should convert to zero'
            ],
            [
                'String values',
                ['data' => [['views' => '1000', 'cart_additions' => '150', 'orders' => '45']]],
                'Should convert to integers'
            ],
            [
                'Missing fields',
                ['data' => [['views' => 1000]]],
                'Should default missing fields to zero'
            ]
        ];
        
        $reflection = new ReflectionClass($this->ozonAPI);
        $processMethod = $reflection->getMethod('processFunnelData');
        $processMethod->setAccessible(true);
        
        foreach ($edgeCases as [$description, $mockResponse, $expectedBehavior]) {
            $processedData = $processMethod->invoke(
                $this->ozonAPI,
                $mockResponse,
                '2025-01-01',
                '2025-01-31',
                []
            );
            
            // Should always return at least one record
            if (empty($processedData)) {
                $this->log("❌ $description: Should return at least one record", 'ERROR');
                return false;
            }
            
            $result = $processedData[0];
            
            // All numeric fields should be non-negative
            if ($result['views'] < 0 || $result['cart_additions'] < 0 || $result['orders'] < 0) {
                $this->log("❌ $description: Numeric fields should be non-negative", 'ERROR');
                return false;
            }
            
            // All conversion fields should be valid percentages
            if ($result['conversion_view_to_cart'] < 0 || $result['conversion_view_to_cart'] > 100 ||
                $result['conversion_cart_to_order'] < 0 || $result['conversion_cart_to_order'] > 100 ||
                $result['conversion_overall'] < 0 || $result['conversion_overall'] > 100) {
                $this->log("❌ $description: Conversion percentages should be between 0 and 100", 'ERROR');
                return false;
            }
        }
        
        return true;
    }
    
    /**
     * Test demographics data normalization
     */
    private function testDemographicsNormalization() {
        $reflection = new ReflectionClass($this->ozonAPI);
        
        // Test age group normalization
        $normalizeAgeGroupMethod = $reflection->getMethod('normalizeAgeGroup');
        $normalizeAgeGroupMethod->setAccessible(true);
        
        $ageGroupTests = [
            ['18-24', '18-24'],
            ['25-34', '25-34'],
            ['35-44', '35-44'],
            ['45-54', '45-54'],
            ['55+', '55+'],
            ['55-64', '55+'], // Should normalize to 55+
            ['65+', '55+'],   // Should normalize to 55+
            ['invalid', null],
            [null, null],
            ['', null]
        ];
        
        foreach ($ageGroupTests as [$input, $expected]) {
            $result = $normalizeAgeGroupMethod->invoke($this->ozonAPI, $input);
            if ($result !== $expected) {
                $this->log("❌ Age group normalization failed. Input: '$input', Expected: '$expected', Got: '$result'", 'ERROR');
                return false;
            }
        }
        
        // Test gender normalization
        $normalizeGenderMethod = $reflection->getMethod('normalizeGender');
        $normalizeGenderMethod->setAccessible(true);
        
        $genderTests = [
            ['male', 'male'],
            ['female', 'female'],
            ['Male', 'male'],
            ['Female', 'female'],
            ['MALE', 'male'],
            ['FEMALE', 'female'],
            ['m', 'male'],
            ['f', 'female'],
            ['invalid', null],
            [null, null],
            ['', null]
        ];
        
        foreach ($genderTests as [$input, $expected]) {
            $result = $normalizeGenderMethod->invoke($this->ozonAPI, $input);
            if ($result !== $expected) {
                $this->log("❌ Gender normalization failed. Input: '$input', Expected: '$expected', Got: '$result'", 'ERROR');
                return false;
            }
        }
        
        // Test region normalization
        $normalizeRegionMethod = $reflection->getMethod('normalizeRegion');
        $normalizeRegionMethod->setAccessible(true);
        
        $regionTests = [
            ['Moscow', 'Moscow'],
            ['Saint Petersburg', 'Saint Petersburg'],
            ['moscow', 'Moscow'],
            ['MOSCOW', 'Moscow'],
            ['Москва', 'Moscow'],
            ['СПб', 'Saint Petersburg'],
            ['Санкт-Петербург', 'Saint Petersburg'],
            ['Unknown Region', 'Unknown Region'],
            [null, null],
            ['', null]
        ];
        
        foreach ($regionTests as [$input, $expected]) {
            $result = $normalizeRegionMethod->invoke($this->ozonAPI, $input);
            if ($result !== $expected) {
                $this->log("❌ Region normalization failed. Input: '$input', Expected: '$expected', Got: '$result'", 'ERROR');
                return false;
            }
        }
        
        return true;
    }
    
    /**
     * Test demographics data aggregation
     */
    private function testDemographicsAggregation() {
        $mockResponse = [
            'data' => [
                ['age_group' => '25-34', 'gender' => 'male', 'region' => 'Moscow', 'orders_count' => 100, 'revenue' => 50000.00],
                ['age_group' => '25-34', 'gender' => 'female', 'region' => 'Moscow', 'orders_count' => 150, 'revenue' => 75000.00],
                ['age_group' => '35-44', 'gender' => 'male', 'region' => 'Saint Petersburg', 'orders_count' => 80, 'revenue' => 40000.00],
            ]
        ];
        
        $reflection = new ReflectionClass($this->ozonAPI);
        $processMethod = $reflection->getMethod('processDemographicsData');
        $processMethod->setAccessible(true);
        
        $processedData = $processMethod->invoke(
            $this->ozonAPI,
            $mockResponse,
            '2025-01-01',
            '2025-01-31'
        );
        
        if (count($processedData) !== 3) {
            $this->log("❌ Expected 3 demographics records, got " . count($processedData), 'ERROR');
            return false;
        }
        
        // Validate data types and ranges
        foreach ($processedData as $record) {
            if (!is_int($record['orders_count']) || $record['orders_count'] < 0) {
                $this->log("❌ Orders count should be non-negative integer", 'ERROR');
                return false;
            }
            
            if (!is_float($record['revenue']) || $record['revenue'] < 0) {
                $this->log("❌ Revenue should be non-negative float", 'ERROR');
                return false;
            }
            
            if (!in_array($record['age_group'], ['18-24', '25-34', '35-44', '45-54', '55+', null])) {
                $this->log("❌ Invalid age group: {$record['age_group']}", 'ERROR');
                return false;
            }
            
            if (!in_array($record['gender'], ['male', 'female', null])) {
                $this->log("❌ Invalid gender: {$record['gender']}", 'ERROR');
                return false;
            }
        }
        
        return true;
    }
    
    /**
     * Test campaign metrics calculation
     */
    private function testCampaignMetricsCalculation() {
        $testCases = [
            // [impressions, clicks, spend, orders, revenue, expected_ctr, expected_cpc, expected_roas]
            [10000, 500, 1000.00, 25, 2500.00, 5.00, 2.00, 2.50],
            [20000, 1000, 2000.00, 50, 5000.00, 5.00, 2.00, 2.50],
            [5000, 100, 500.00, 10, 1000.00, 2.00, 5.00, 2.00],
            [1000, 0, 0.00, 0, 0.00, 0.00, 0.00, 0.00], // No activity
            [10000, 500, 0.00, 25, 2500.00, 5.00, 0.00, 0.00], // Free traffic
        ];
        
        $reflection = new ReflectionClass($this->ozonAPI);
        $processMethod = $reflection->getMethod('processCampaignData');
        $processMethod->setAccessible(true);
        
        foreach ($testCases as $i => [$impressions, $clicks, $spend, $orders, $revenue, $expectedCTR, $expectedCPC, $expectedROAS]) {
            $mockResponse = [
                'data' => [
                    [
                        'campaign_id' => "TEST_CAMPAIGN_$i",
                        'campaign_name' => "Test Campaign $i",
                        'impressions' => $impressions,
                        'clicks' => $clicks,
                        'spend' => $spend,
                        'orders' => $orders,
                        'revenue' => $revenue
                    ]
                ]
            ];
            
            $processedData = $processMethod->invoke(
                $this->ozonAPI,
                $mockResponse,
                '2025-01-01',
                '2025-01-31'
            );
            
            $result = $processedData[0];
            
            if ($result['ctr'] !== $expectedCTR) {
                $this->log("❌ Test case $i: CTR calculation. Expected: $expectedCTR, Got: {$result['ctr']}", 'ERROR');
                return false;
            }
            
            if ($result['cpc'] !== $expectedCPC) {
                $this->log("❌ Test case $i: CPC calculation. Expected: $expectedCPC, Got: {$result['cpc']}", 'ERROR');
                return false;
            }
            
            if ($result['roas'] !== $expectedROAS) {
                $this->log("❌ Test case $i: ROAS calculation. Expected: $expectedROAS, Got: {$result['roas']}", 'ERROR');
                return false;
            }
        }
        
        return true;
    }
    
    /**
     * Test campaign ROAS calculation edge cases
     */
    private function testCampaignROASCalculation() {
        $edgeCases = [
            // [description, spend, revenue, expected_roas]
            ['Zero spend, positive revenue', 0.00, 1000.00, 0.00],
            ['Positive spend, zero revenue', 1000.00, 0.00, 0.00],
            ['Equal spend and revenue', 1000.00, 1000.00, 1.00],
            ['High ROAS', 100.00, 1000.00, 10.00],
            ['Low ROAS', 1000.00, 100.00, 0.10],
        ];
        
        $reflection = new ReflectionClass($this->ozonAPI);
        $processMethod = $reflection->getMethod('processCampaignData');
        $processMethod->setAccessible(true);
        
        foreach ($edgeCases as [$description, $spend, $revenue, $expectedROAS]) {
            $mockResponse = [
                'data' => [
                    [
                        'campaign_id' => 'TEST_ROAS',
                        'impressions' => 1000,
                        'clicks' => 100,
                        'spend' => $spend,
                        'orders' => 10,
                        'revenue' => $revenue
                    ]
                ]
            ];
            
            $processedData = $processMethod->invoke(
                $this->ozonAPI,
                $mockResponse,
                '2025-01-01',
                '2025-01-31'
            );
            
            $result = $processedData[0];
            
            if ($result['roas'] !== $expectedROAS) {
                $this->log("❌ $description: Expected ROAS $expectedROAS, Got: {$result['roas']}", 'ERROR');
                return false;
            }
        }
        
        return true;
    }
    
    /**
     * Test data range validation
     */
    private function testDataRangeValidation() {
        $reflection = new ReflectionClass($this->ozonAPI);
        $validateMethod = $reflection->getMethod('validateDateRange');
        $validateMethod->setAccessible(true);
        
        // Valid date ranges
        $validRanges = [
            ['2025-01-01', '2025-01-31'], // 30 days
            ['2025-01-01', '2025-03-31'], // 90 days (maximum)
            ['2025-01-01', '2025-01-01'], // Same day
        ];
        
        foreach ($validRanges as [$dateFrom, $dateTo]) {
            try {
                $validateMethod->invoke($this->ozonAPI, $dateFrom, $dateTo);
            } catch (Exception $e) {
                $this->log("❌ Valid date range $dateFrom to $dateTo should not throw exception", 'ERROR');
                return false;
            }
        }
        
        // Invalid date ranges
        $invalidRanges = [
            ['2025-01-31', '2025-01-01'], // End before start
            ['2025-01-01', '2025-12-31'], // More than 90 days
            ['invalid-date', '2025-01-31'], // Invalid start date
            ['2025-01-01', 'invalid-date'], // Invalid end date
        ];
        
        foreach ($invalidRanges as [$dateFrom, $dateTo]) {
            try {
                $validateMethod->invoke($this->ozonAPI, $dateFrom, $dateTo);
                $this->log("❌ Invalid date range $dateFrom to $dateTo should throw exception", 'ERROR');
                return false;
            } catch (InvalidArgumentException $e) {
                // Expected exception
            }
        }
        
        return true;
    }
    
    /**
     * Test data type validation
     */
    private function testDataTypeValidation() {
        // Test various data types in funnel processing
        $testCases = [
            // String numbers should be converted to integers
            ['views' => '1000', 'cart_additions' => '150', 'orders' => '45'],
            // Float numbers should be converted to integers
            ['views' => 1000.5, 'cart_additions' => 150.7, 'orders' => 45.2],
            // Null values should default to 0
            ['views' => null, 'cart_additions' => null, 'orders' => null],
            // Missing values should default to 0
            [],
        ];
        
        $reflection = new ReflectionClass($this->ozonAPI);
        $processMethod = $reflection->getMethod('processFunnelData');
        $processMethod->setAccessible(true);
        
        foreach ($testCases as $i => $testData) {
            $mockResponse = ['data' => [$testData]];
            
            $processedData = $processMethod->invoke(
                $this->ozonAPI,
                $mockResponse,
                '2025-01-01',
                '2025-01-31',
                []
            );
            
            $result = $processedData[0];
            
            // All numeric fields should be integers
            if (!is_int($result['views']) || !is_int($result['cart_additions']) || !is_int($result['orders'])) {
                $this->log("❌ Test case $i: Numeric fields should be integers", 'ERROR');
                return false;
            }
            
            // All conversion fields should be floats
            if (!is_float($result['conversion_view_to_cart']) || 
                !is_float($result['conversion_cart_to_order']) || 
                !is_float($result['conversion_overall'])) {
                $this->log("❌ Test case $i: Conversion fields should be floats", 'ERROR');
                return false;
            }
        }
        
        return true;
    }
    
    /**
     * Test business logic validation
     */
    private function testBusinessLogicValidation() {
        // Test that business rules are enforced
        $testCases = [
            // Funnel should be logically consistent
            ['views' => 1000, 'cart_additions' => 150, 'orders' => 45, 'valid' => true],
            ['views' => 1000, 'cart_additions' => 1500, 'orders' => 45, 'valid' => false], // Cart > Views
            ['views' => 1000, 'cart_additions' => 150, 'orders' => 200, 'valid' => false], // Orders > Cart
        ];
        
        $reflection = new ReflectionClass($this->ozonAPI);
        $processMethod = $reflection->getMethod('processFunnelData');
        $processMethod->setAccessible(true);
        
        foreach ($testCases as $i => $testCase) {
            $mockResponse = [
                'data' => [
                    [
                        'views' => $testCase['views'],
                        'cart_additions' => $testCase['cart_additions'],
                        'orders' => $testCase['orders']
                    ]
                ]
            ];
            
            $processedData = $processMethod->invoke(
                $this->ozonAPI,
                $mockResponse,
                '2025-01-01',
                '2025-01-31',
                []
            );
            
            $result = $processedData[0];
            
            // Check logical consistency after processing
            if ($result['cart_additions'] > $result['views']) {
                $this->log("❌ Test case $i: Cart additions should not exceed views after processing", 'ERROR');
                return false;
            }
            
            if ($result['orders'] > $result['cart_additions']) {
                $this->log("❌ Test case $i: Orders should not exceed cart additions after processing", 'ERROR');
                return false;
            }
        }
        
        return true;
    }
    
    /**
     * Test performance metrics
     */
    private function testPerformanceMetrics() {
        // Test processing time for large datasets
        $largeDataset = [];
        for ($i = 0; $i < 1000; $i++) {
            $largeDataset[] = [
                'views' => rand(100, 10000),
                'cart_additions' => rand(10, 1000),
                'orders' => rand(1, 100)
            ];
        }
        
        $mockResponse = ['data' => $largeDataset];
        
        $reflection = new ReflectionClass($this->ozonAPI);
        $processMethod = $reflection->getMethod('processFunnelData');
        $processMethod->setAccessible(true);
        
        $startTime = microtime(true);
        $processedData = $processMethod->invoke(
            $this->ozonAPI,
            $mockResponse,
            '2025-01-01',
            '2025-01-31',
            []
        );
        $endTime = microtime(true);
        
        $processingTime = $endTime - $startTime;
        
        // Should process 1000 records in under 2 seconds
        if ($processingTime > 2.0) {
            $this->log("❌ Performance test failed: {$processingTime}s for 1000 records (should be < 2s)", 'ERROR');
            return false;
        }
        
        // All records should be processed
        if (count($processedData) !== 1000) {
            $this->log("❌ Not all records were processed: " . count($processedData) . "/1000", 'ERROR');
            return false;
        }
        
        $this->log("✅ Performance test passed: {$processingTime}s for 1000 records", 'SUCCESS');
        
        return true;
    }
    
    /**
     * Test data integrity
     */
    private function testDataIntegrity() {
        // Test that processed data maintains integrity
        $testData = [
            'views' => 1000,
            'cart_additions' => 150,
            'orders' => 45,
            'product_id' => 'TEST_PRODUCT',
            'campaign_id' => 'TEST_CAMPAIGN'
        ];
        
        $mockResponse = ['data' => [$testData]];
        
        $reflection = new ReflectionClass($this->ozonAPI);
        $processMethod = $reflection->getMethod('processFunnelData');
        $processMethod->setAccessible(true);
        
        $processedData = $processMethod->invoke(
            $this->ozonAPI,
            $mockResponse,
            '2025-01-01',
            '2025-01-31',
            ['product_id' => 'TEST_PRODUCT']
        );
        
        $result = $processedData[0];
        
        // Check that all required fields are present
        $requiredFields = [
            'date_from', 'date_to', 'product_id', 'campaign_id',
            'views', 'cart_additions', 'orders',
            'conversion_view_to_cart', 'conversion_cart_to_order', 'conversion_overall',
            'cached_at'
        ];
        
        foreach ($requiredFields as $field) {
            if (!array_key_exists($field, $result)) {
                $this->log("❌ Required field '$field' missing from processed data", 'ERROR');
                return false;
            }
        }
        
        // Check that date fields are properly formatted
        if ($result['date_from'] !== '2025-01-01' || $result['date_to'] !== '2025-01-31') {
            $this->log("❌ Date fields not properly preserved", 'ERROR');
            return false;
        }
        
        // Check that cached_at is a valid timestamp
        if (!strtotime($result['cached_at'])) {
            $this->log("❌ cached_at field is not a valid timestamp", 'ERROR');
            return false;
        }
        
        return true;
    }
    
    /**
     * Print test summary
     */
    private function printTestSummary($passed, $failed) {
        $total = $passed + $failed;
        
        $this->log("\n" . str_repeat("=", 60), 'INFO');
        $this->log("🏁 DATA VALIDATION TEST SUMMARY", 'INFO');
        $this->log(str_repeat("=", 60), 'INFO');
        $this->log("Total Tests: $total", 'INFO');
        $this->log("Passed: $passed", 'SUCCESS');
        $this->log("Failed: $failed", $failed > 0 ? 'ERROR' : 'INFO');
        $this->log("Success Rate: " . round(($passed / $total) * 100, 2) . "%", 'INFO');
        
        if ($failed === 0) {
            $this->log("\n🎉 ALL DATA VALIDATION TESTS PASSED! Data processing is accurate and reliable.", 'SUCCESS');
        } else {
            $this->log("\n❌ Some data validation tests failed. Please review the errors above.", 'ERROR');
        }
        
        $this->log("\nDetailed Results:", 'INFO');
        foreach ($this->testResults as $test => $result) {
            $icon = strpos($result, 'PASSED') !== false ? '✅' : '❌';
            $this->log("$icon $test: $result", 'INFO');
        }
    }
    
    /**
     * Logging helper
     */
    private function log($message, $level = 'INFO') {
        $timestamp = date('Y-m-d H:i:s');
        $colors = [
            'INFO' => "\033[0m",      // Default
            'SUCCESS' => "\033[32m",  // Green
            'WARNING' => "\033[33m",  // Yellow
            'ERROR' => "\033[31m"     // Red
        ];
        
        $color = $colors[$level] ?? $colors['INFO'];
        $reset = "\033[0m";
        
        echo "{$color}[{$timestamp}] {$message}{$reset}\n";
    }
}

// Run the tests if this file is executed directly
if (basename(__FILE__) === basename($_SERVER['SCRIPT_NAME'])) {
    try {
        $tester = new OzonDataValidationTest();
        $success = $tester->runAllTests();
        exit($success ? 0 : 1);
    } catch (Exception $e) {
        echo "❌ Test execution failed: " . $e->getMessage() . "\n";
        exit(1);
    }
}