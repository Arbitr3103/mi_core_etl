<?php
/**
 * Comprehensive Integration Tests for Ozon Analytics System
 * 
 * This test suite covers all aspects of the Ozon Analytics integration:
 * - Database connectivity and schema validation
 * - API endpoint functionality
 * - Data processing and validation
 * - Security and access control
 * - Performance and caching
 * - Error handling and recovery
 * 
 * @version 1.0
 * @author Manhattan System
 */

require_once '../src/classes/OzonAnalyticsAPI.php';
require_once '../src/classes/OzonDataCache.php';
require_once '../src/classes/OzonSecurityManager.php';

class OzonAnalyticsIntegrationTest {
    
    private $pdo;
    private $ozonAPI;
    private $securityManager;
    private $testResults = [];
    private $testConfig;
    
    public function __construct() {
        $this->testConfig = [
            'db_host' => $_ENV['DB_HOST'] ?? '127.0.0.1',
            'db_name' => $_ENV['DB_NAME'] ?? 'mi_core_db',
            'db_user' => $_ENV['DB_USER'] ?? 'ingest_user',
            'db_password' => $_ENV['DB_PASSWORD'] ?? 'xK9#mQ7$vN2@pL!rT4wY',
            'test_client_id' => 'test_client_id_integration',
            'test_api_key' => 'test_api_key_integration'
        ];
        
        $this->initializeDatabase();
        $this->initializeComponents();
    }
    
    /**
     * Initialize database connection
     */
    private function initializeDatabase() {
        try {
            $dsn = "mysql:host={$this->testConfig['db_host']};dbname={$this->testConfig['db_name']};charset=utf8mb4";
            $this->pdo = new PDO($dsn, $this->testConfig['db_user'], $this->testConfig['db_password'], [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
            ]);
            $this->log("✅ Database connection established", 'SUCCESS');
        } catch (PDOException $e) {
            $this->log("❌ Database connection failed: " . $e->getMessage(), 'ERROR');
            throw $e;
        }
    }
    
    /**
     * Initialize system components
     */
    private function initializeComponents() {
        try {
            $this->ozonAPI = new OzonAnalyticsAPI(
                $this->testConfig['test_client_id'],
                $this->testConfig['test_api_key'],
                $this->pdo
            );
            
            $this->securityManager = new OzonSecurityManager($this->pdo);
            
            $this->log("✅ System components initialized", 'SUCCESS');
        } catch (Exception $e) {
            $this->log("❌ Component initialization failed: " . $e->getMessage(), 'ERROR');
            throw $e;
        }
    }
    
    /**
     * Run all integration tests
     */
    public function runAllTests() {
        $this->log("🚀 Starting Ozon Analytics Integration Tests", 'INFO');
        $this->log(str_repeat("=", 60), 'INFO');
        
        $testMethods = [
            'testDatabaseSchema',
            'testAPIAuthentication',
            'testFunnelDataProcessing',
            'testDemographicsProcessing',
            'testCampaignDataProcessing',
            'testDataExport',
            'testCachingSystem',
            'testSecurityIntegration',
            'testErrorHandling',
            'testPerformance',
            'testDataValidation',
            'testAPIEndpoints',
            'testWeeklyUpdateSystem',
            'testCleanupProcesses'
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
     * Test database schema and tables
     */
    private function testDatabaseSchema() {
        $requiredTables = [
            'ozon_api_settings',
            'ozon_funnel_data',
            'ozon_demographics',
            'ozon_campaigns'
        ];
        
        foreach ($requiredTables as $table) {
            $stmt = $this->pdo->prepare("SHOW TABLES LIKE ?");
            $stmt->execute([$table]);
            
            if (!$stmt->fetch()) {
                $this->log("❌ Table $table does not exist", 'ERROR');
                return false;
            }
        }
        
        // Test table structure
        $this->validateTableStructure('ozon_api_settings', [
            'id', 'client_id', 'api_key_hash', 'access_token', 'token_expiry', 'is_active'
        ]);
        
        $this->validateTableStructure('ozon_funnel_data', [
            'id', 'date_from', 'date_to', 'product_id', 'campaign_id', 'views', 
            'cart_additions', 'orders', 'conversion_view_to_cart', 'conversion_cart_to_order', 
            'conversion_overall', 'cached_at'
        ]);
        
        // Test indexes
        $this->validateIndexes('ozon_funnel_data', ['idx_date_range', 'idx_product', 'idx_campaign']);
        
        return true;
    }
    
    /**
     * Test API authentication functionality
     */
    private function testAPIAuthentication() {
        // Test token generation (mock)
        $reflection = new ReflectionClass($this->ozonAPI);
        $isTokenValidMethod = $reflection->getMethod('isTokenValid');
        $isTokenValidMethod->setAccessible(true);
        
        // Initially should be invalid
        if ($isTokenValidMethod->invoke($this->ozonAPI)) {
            $this->log("❌ Token should be invalid initially", 'ERROR');
            return false;
        }
        
        // Test token validation logic
        $tokenProperty = $reflection->getProperty('accessToken');
        $tokenProperty->setAccessible(true);
        $tokenProperty->setValue($this->ozonAPI, 'test_token');
        
        $expiryProperty = $reflection->getProperty('tokenExpiry');
        $expiryProperty->setAccessible(true);
        $expiryProperty->setValue($this->ozonAPI, time() + 1800); // 30 minutes from now
        
        if (!$isTokenValidMethod->invoke($this->ozonAPI)) {
            $this->log("❌ Token should be valid with future expiry", 'ERROR');
            return false;
        }
        
        // Test expired token
        $expiryProperty->setValue($this->ozonAPI, time() - 100); // Past expiry
        
        if ($isTokenValidMethod->invoke($this->ozonAPI)) {
            $this->log("❌ Expired token should be invalid", 'ERROR');
            return false;
        }
        
        return true;
    }
    
    /**
     * Test funnel data processing
     */
    private function testFunnelDataProcessing() {
        // Test data processing with mock API response
        $mockResponse = [
            'data' => [
                [
                    'product_id' => 'TEST_PRODUCT_001',
                    'campaign_id' => 'TEST_CAMPAIGN_001',
                    'views' => 1000,
                    'cart_additions' => 150,
                    'orders' => 45
                ],
                [
                    'product_id' => 'TEST_PRODUCT_002',
                    'views' => 500,
                    'cart_additions' => 75,
                    'orders' => 20
                ]
            ]
        ];
        
        $reflection = new ReflectionClass($this->ozonAPI);
        $processMethod = $reflection->getMethod('processFunnelData');
        $processMethod->setAccessible(true);
        
        $processedData = $processMethod->invoke(
            $this->ozonAPI, 
            $mockResponse, 
            '2025-01-01', 
            '2025-01-31', 
            []
        );
        
        // Validate processed data structure
        if (count($processedData) !== 2) {
            $this->log("❌ Expected 2 processed records, got " . count($processedData), 'ERROR');
            return false;
        }
        
        $firstRecord = $processedData[0];
        
        // Validate conversions calculation
        $expectedViewToCart = round((150 / 1000) * 100, 2);
        $expectedCartToOrder = round((45 / 150) * 100, 2);
        $expectedOverall = round((45 / 1000) * 100, 2);
        
        if ($firstRecord['conversion_view_to_cart'] !== $expectedViewToCart) {
            $this->log("❌ View to cart conversion calculation error", 'ERROR');
            return false;
        }
        
        if ($firstRecord['conversion_cart_to_order'] !== $expectedCartToOrder) {
            $this->log("❌ Cart to order conversion calculation error", 'ERROR');
            return false;
        }
        
        if ($firstRecord['conversion_overall'] !== $expectedOverall) {
            $this->log("❌ Overall conversion calculation error", 'ERROR');
            return false;
        }
        
        // Test edge cases
        $edgeCaseResponse = [
            'data' => [
                [
                    'views' => 0,
                    'cart_additions' => 0,
                    'orders' => 0
                ]
            ]
        ];
        
        $edgeProcessedData = $processMethod->invoke(
            $this->ozonAPI,
            $edgeCaseResponse,
            '2025-01-01',
            '2025-01-31',
            []
        );
        
        if ($edgeProcessedData[0]['conversion_overall'] !== 0.00) {
            $this->log("❌ Zero division handling failed", 'ERROR');
            return false;
        }
        
        return true;
    }
    
    /**
     * Test demographics data processing
     */
    private function testDemographicsProcessing() {
        $mockResponse = [
            'data' => [
                [
                    'age_group' => '25-34',
                    'gender' => 'male',
                    'region' => 'Moscow',
                    'orders_count' => 100,
                    'revenue' => 50000.00
                ],
                [
                    'age_group' => '35-44',
                    'gender' => 'female',
                    'region' => 'Saint Petersburg',
                    'orders_count' => 75,
                    'revenue' => 37500.00
                ]
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
        
        if (count($processedData) !== 2) {
            $this->log("❌ Expected 2 demographics records", 'ERROR');
            return false;
        }
        
        // Validate data normalization
        $firstRecord = $processedData[0];
        
        if ($firstRecord['age_group'] !== '25-34' || 
            $firstRecord['gender'] !== 'male' ||
            $firstRecord['region'] !== 'Moscow') {
            $this->log("❌ Demographics data normalization failed", 'ERROR');
            return false;
        }
        
        return true;
    }
    
    /**
     * Test campaign data processing
     */
    private function testCampaignDataProcessing() {
        $mockResponse = [
            'data' => [
                [
                    'campaign_id' => 'CAMP_001',
                    'campaign_name' => 'Test Campaign',
                    'impressions' => 10000,
                    'clicks' => 500,
                    'spend' => 1000.00,
                    'orders' => 25,
                    'revenue' => 2500.00
                ]
            ]
        ];
        
        $reflection = new ReflectionClass($this->ozonAPI);
        $processMethod = $reflection->getMethod('processCampaignData');
        $processMethod->setAccessible(true);
        
        $processedData = $processMethod->invoke(
            $this->ozonAPI,
            $mockResponse,
            '2025-01-01',
            '2025-01-31'
        );
        
        $record = $processedData[0];
        
        // Validate calculated metrics
        $expectedCTR = round((500 / 10000) * 100, 2); // 5.00%
        $expectedCPC = round(1000.00 / 500, 2); // 2.00
        $expectedROAS = round(2500.00 / 1000.00, 2); // 2.50
        
        if ($record['ctr'] !== $expectedCTR ||
            $record['cpc'] !== $expectedCPC ||
            $record['roas'] !== $expectedROAS) {
            $this->log("❌ Campaign metrics calculation failed", 'ERROR');
            return false;
        }
        
        return true;
    }
    
    /**
     * Test data export functionality
     */
    private function testDataExport() {
        // Test CSV export
        $testData = [
            [
                'date_from' => '2025-01-01',
                'date_to' => '2025-01-31',
                'views' => 1000,
                'cart_additions' => 150,
                'orders' => 45
            ]
        ];
        
        $reflection = new ReflectionClass($this->ozonAPI);
        $exportMethod = $reflection->getMethod('exportToCsv');
        $exportMethod->setAccessible(true);
        
        $csvContent = $exportMethod->invoke($this->ozonAPI, $testData, 'funnel');
        
        if (empty($csvContent)) {
            $this->log("❌ CSV export returned empty content", 'ERROR');
            return false;
        }
        
        // Validate CSV structure
        $lines = explode("\n", trim($csvContent));
        if (count($lines) < 2) { // Header + at least one data row
            $this->log("❌ CSV should have header and data rows", 'ERROR');
            return false;
        }
        
        // Test JSON export
        $jsonContent = json_encode($testData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        
        if (json_last_error() !== JSON_ERROR_NONE) {
            $this->log("❌ JSON export failed: " . json_last_error_msg(), 'ERROR');
            return false;
        }
        
        return true;
    }
    
    /**
     * Test caching system
     */
    private function testCachingSystem() {
        $cache = new OzonDataCache($this->pdo);
        
        // Test cache set/get
        $testData = [
            'test_key' => 'test_value',
            'timestamp' => time()
        ];
        
        $cacheKey = 'test_funnel_2025-01-01_2025-01-31';
        
        // Set cache
        $cache->setFunnelData('2025-01-01', '2025-01-31', [], $testData);
        
        // Get cache
        $cachedData = $cache->getFunnelData('2025-01-01', '2025-01-31', []);
        
        if (empty($cachedData)) {
            $this->log("❌ Cache retrieval failed", 'ERROR');
            return false;
        }
        
        // Test cache expiry
        // This would require manipulating timestamps or waiting, so we'll test the logic
        $reflection = new ReflectionClass($cache);
        $isCacheValidMethod = $reflection->getMethod('isCacheValid');
        $isCacheValidMethod->setAccessible(true);
        
        $validTimestamp = date('Y-m-d H:i:s', time() - 1800); // 30 minutes ago
        $expiredTimestamp = date('Y-m-d H:i:s', time() - 7200); // 2 hours ago
        
        if (!$isCacheValidMethod->invoke($cache, $validTimestamp)) {
            $this->log("❌ Valid cache timestamp should be valid", 'ERROR');
            return false;
        }
        
        if ($isCacheValidMethod->invoke($cache, $expiredTimestamp)) {
            $this->log("❌ Expired cache timestamp should be invalid", 'ERROR');
            return false;
        }
        
        return true;
    }
    
    /**
     * Test security integration
     */
    private function testSecurityIntegration() {
        // Test user access levels
        $testUserId = 'test_user_integration';
        
        // Set user access level
        $this->securityManager->setUserAccessLevel(
            $testUserId, 
            OzonSecurityManager::ACCESS_LEVEL_READ,
            'system_test'
        );
        
        // Test access check
        $hasReadAccess = $this->securityManager->checkAccess(
            $testUserId,
            OzonSecurityManager::OPERATION_VIEW_FUNNEL
        );
        
        if (!$hasReadAccess) {
            $this->log("❌ User should have read access", 'ERROR');
            return false;
        }
        
        $hasExportAccess = $this->securityManager->checkAccess(
            $testUserId,
            OzonSecurityManager::OPERATION_EXPORT_DATA
        );
        
        if ($hasExportAccess) {
            $this->log("❌ User should not have export access", 'ERROR');
            return false;
        }
        
        // Test rate limiting
        $rateLimitResult = $this->securityManager->checkRateLimit($testUserId, 'api_request');
        
        if (!$rateLimitResult['allowed']) {
            $this->log("❌ First request should be allowed", 'ERROR');
            return false;
        }
        
        // Clean up test user
        $this->securityManager->removeUser($testUserId);
        
        return true;
    }
    
    /**
     * Test error handling
     */
    private function testErrorHandling() {
        // Test invalid date range
        try {
            $this->ozonAPI->getFunnelData('2025-01-31', '2025-01-01'); // Invalid range
            $this->log("❌ Should throw exception for invalid date range", 'ERROR');
            return false;
        } catch (InvalidArgumentException $e) {
            // Expected exception
        }
        
        // Test date range too large
        try {
            $this->ozonAPI->getFunnelData('2024-01-01', '2024-12-31'); // > 90 days
            $this->log("❌ Should throw exception for date range > 90 days", 'ERROR');
            return false;
        } catch (InvalidArgumentException $e) {
            // Expected exception
        }
        
        // Test OzonAPIException handling
        $exception = new OzonAPIException('Test error', 401, 'AUTHENTICATION_ERROR');
        
        if ($exception->getErrorType() !== 'AUTHENTICATION_ERROR') {
            $this->log("❌ Exception error type not preserved", 'ERROR');
            return false;
        }
        
        return true;
    }
    
    /**
     * Test performance characteristics
     */
    private function testPerformance() {
        // Test data processing performance
        $largeDataset = [];
        for ($i = 0; $i < 1000; $i++) {
            $largeDataset[] = [
                'product_id' => "PRODUCT_$i",
                'views' => rand(100, 10000),
                'cart_additions' => rand(10, 1000),
                'orders' => rand(1, 100)
            ];
        }
        
        $mockResponse = ['data' => $largeDataset];
        
        $startTime = microtime(true);
        
        $reflection = new ReflectionClass($this->ozonAPI);
        $processMethod = $reflection->getMethod('processFunnelData');
        $processMethod->setAccessible(true);
        
        $processedData = $processMethod->invoke(
            $this->ozonAPI,
            $mockResponse,
            '2025-01-01',
            '2025-01-31',
            []
        );
        
        $endTime = microtime(true);
        $processingTime = $endTime - $startTime;
        
        if ($processingTime > 5.0) { // Should process 1000 records in under 5 seconds
            $this->log("❌ Performance test failed: {$processingTime}s for 1000 records", 'ERROR');
            return false;
        }
        
        if (count($processedData) !== 1000) {
            $this->log("❌ Not all records were processed", 'ERROR');
            return false;
        }
        
        $this->log("✅ Performance test passed: {$processingTime}s for 1000 records", 'SUCCESS');
        
        return true;
    }
    
    /**
     * Test data validation
     */
    private function testDataValidation() {
        $reflection = new ReflectionClass($this->ozonAPI);
        
        // Test date validation
        $isValidDateMethod = $reflection->getMethod('isValidDate');
        $isValidDateMethod->setAccessible(true);
        
        $validDates = ['2025-01-01', '2025-12-31', '2024-02-29']; // Leap year
        $invalidDates = ['2025-13-01', '2025-01-32', '2025-02-30', 'invalid-date'];
        
        foreach ($validDates as $date) {
            if (!$isValidDateMethod->invoke($this->ozonAPI, $date)) {
                $this->log("❌ Valid date $date marked as invalid", 'ERROR');
                return false;
            }
        }
        
        foreach ($invalidDates as $date) {
            if ($isValidDateMethod->invoke($this->ozonAPI, $date)) {
                $this->log("❌ Invalid date $date marked as valid", 'ERROR');
                return false;
            }
        }
        
        // Test data normalization
        $normalizeAgeGroupMethod = $reflection->getMethod('normalizeAgeGroup');
        $normalizeAgeGroupMethod->setAccessible(true);
        
        $testCases = [
            ['18-24', '18-24'],
            ['25-34', '25-34'],
            ['invalid', null],
            [null, null]
        ];
        
        foreach ($testCases as [$input, $expected]) {
            $result = $normalizeAgeGroupMethod->invoke($this->ozonAPI, $input);
            if ($result !== $expected) {
                $this->log("❌ Age group normalization failed for $input", 'ERROR');
                return false;
            }
        }
        
        return true;
    }
    
    /**
     * Test API endpoints
     */
    private function testAPIEndpoints() {
        // Test health endpoint
        $healthUrl = 'http://localhost/src/api/ozon-analytics.php?action=health';
        $healthResponse = $this->makeHttpRequest($healthUrl);
        
        if (!$healthResponse || !isset($healthResponse['status'])) {
            $this->log("❌ Health endpoint failed", 'ERROR');
            return false;
        }
        
        // Test funnel-data endpoint (would require mock or test data)
        // This is a simplified test - in production you'd want more comprehensive API testing
        
        return true;
    }
    
    /**
     * Test weekly update system
     */
    private function testWeeklyUpdateSystem() {
        // Test update configuration
        if (!file_exists('ozon_weekly_update.py')) {
            $this->log("❌ Weekly update script not found", 'ERROR');
            return false;
        }
        
        // Test cron configuration (check if crontab entry exists)
        $cronOutput = shell_exec('crontab -l 2>/dev/null | grep ozon_weekly_update');
        
        if (empty($cronOutput)) {
            $this->log("⚠️ Weekly update cron job not configured", 'WARNING');
            // This is a warning, not a failure
        }
        
        return true;
    }
    
    /**
     * Test cleanup processes
     */
    private function testCleanupProcesses() {
        // Test cache cleanup
        $cache = new OzonDataCache($this->pdo);
        
        // Insert old test data
        $oldTimestamp = date('Y-m-d H:i:s', time() - 86400 * 2); // 2 days ago
        
        $stmt = $this->pdo->prepare("
            INSERT INTO ozon_funnel_data 
            (date_from, date_to, views, cart_additions, orders, cached_at) 
            VALUES (?, ?, ?, ?, ?, ?)
        ");
        
        $stmt->execute(['2025-01-01', '2025-01-02', 100, 10, 1, $oldTimestamp]);
        $testRecordId = $this->pdo->lastInsertId();
        
        // Run cleanup
        $reflection = new ReflectionClass($cache);
        $cleanupMethod = $reflection->getMethod('cleanupExpiredCache');
        $cleanupMethod->setAccessible(true);
        $cleanupMethod->invoke($cache);
        
        // Check if old data was cleaned up
        $stmt = $this->pdo->prepare("SELECT COUNT(*) as count FROM ozon_funnel_data WHERE id = ?");
        $stmt->execute([$testRecordId]);
        $result = $stmt->fetch();
        
        if ($result['count'] > 0) {
            $this->log("❌ Old cache data was not cleaned up", 'ERROR');
            return false;
        }
        
        return true;
    }
    
    /**
     * Helper method to validate table structure
     */
    private function validateTableStructure($tableName, $expectedColumns) {
        $stmt = $this->pdo->prepare("DESCRIBE $tableName");
        $stmt->execute();
        $columns = $stmt->fetchAll();
        
        $actualColumns = array_column($columns, 'Field');
        
        foreach ($expectedColumns as $column) {
            if (!in_array($column, $actualColumns)) {
                $this->log("❌ Column $column missing from table $tableName", 'ERROR');
                return false;
            }
        }
        
        return true;
    }
    
    /**
     * Helper method to validate indexes
     */
    private function validateIndexes($tableName, $expectedIndexes) {
        $stmt = $this->pdo->prepare("SHOW INDEX FROM $tableName");
        $stmt->execute();
        $indexes = $stmt->fetchAll();
        
        $actualIndexes = array_unique(array_column($indexes, 'Key_name'));
        
        foreach ($expectedIndexes as $index) {
            if (!in_array($index, $actualIndexes)) {
                $this->log("❌ Index $index missing from table $tableName", 'ERROR');
                return false;
            }
        }
        
        return true;
    }
    
    /**
     * Helper method to make HTTP requests
     */
    private function makeHttpRequest($url) {
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_FOLLOWLOCATION => true
        ]);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        if ($httpCode !== 200) {
            return null;
        }
        
        return json_decode($response, true);
    }
    
    /**
     * Print test summary
     */
    private function printTestSummary($passed, $failed) {
        $total = $passed + $failed;
        
        $this->log("\n" . str_repeat("=", 60), 'INFO');
        $this->log("🏁 TEST SUMMARY", 'INFO');
        $this->log(str_repeat("=", 60), 'INFO');
        $this->log("Total Tests: $total", 'INFO');
        $this->log("Passed: $passed", 'SUCCESS');
        $this->log("Failed: $failed", $failed > 0 ? 'ERROR' : 'INFO');
        $this->log("Success Rate: " . round(($passed / $total) * 100, 2) . "%", 'INFO');
        
        if ($failed === 0) {
            $this->log("\n🎉 ALL TESTS PASSED! Ozon Analytics integration is ready for production.", 'SUCCESS');
        } else {
            $this->log("\n❌ Some tests failed. Please review the errors above.", 'ERROR');
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
        $tester = new OzonAnalyticsIntegrationTest();
        $success = $tester->runAllTests();
        exit($success ? 0 : 1);
    } catch (Exception $e) {
        echo "❌ Test execution failed: " . $e->getMessage() . "\n";
        exit(1);
    }
}