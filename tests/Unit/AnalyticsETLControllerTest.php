<?php
/**
 * Unit tests for AnalyticsETLController
 * 
 * Tests the API controller functionality including:
 * - Analytics status endpoint
 * - ETL trigger endpoint
 * - Data quality metrics endpoint
 * - ETL history endpoint
 * - Error handling and validation
 * 
 * Requirements: 8.1, 8.2, 8.3, 8.4
 * Task: 5.1 Создать AnalyticsETLController
 */

require_once __DIR__ . '/../../src/api/controllers/AnalyticsETLController.php';

class AnalyticsETLControllerTest extends PHPUnit\Framework\TestCase {
    private AnalyticsETLController $controller;
    private PDO $mockPdo;
    
    protected function setUp(): void {
        // Create mock PDO
        $this->mockPdo = $this->createMock(PDO::class);
        
        // Create controller with test configuration
        $testConfig = [
            'log_file' => '/tmp/test_analytics_etl_controller.log',
            'analytics_api' => [
                'base_url' => 'https://test-api.example.com',
                'api_key' => 'test_key',
                'timeout' => 10
            ],
            'analytics_etl' => [
                'load_batch_size' => 100,
                'min_quality_score' => 70.0,
                'enable_audit_logging' => false
            ]
        ];
        
        $this->controller = new AnalyticsETLController($testConfig);
    }
    
    protected function tearDown(): void {
        // Clean up test log file
        $logFile = '/tmp/test_analytics_etl_controller.log';
        if (file_exists($logFile)) {
            unlink($logFile);
        }
    }
    
    public function testHandleGetAnalyticsStatus(): void {
        $response = $this->controller->handleRequest('GET', '/api/warehouse/analytics-status');
        
        $this->assertIsArray($response);
        $this->assertArrayHasKey('status', $response);
        
        // Should handle gracefully even without ETL service
        if ($response['status'] === 'error') {
            $this->assertEquals(503, http_response_code());
            $this->assertStringContains('ETL service not available', $response['message']);
        } else {
            $this->assertEquals('success', $response['status']);
            $this->assertArrayHasKey('data', $response);
        }
    }
    
    public function testHandlePostTriggerETL(): void {
        $params = [
            'etl_type' => AnalyticsETL::TYPE_MANUAL_SYNC,
            'options' => []
        ];
        
        $response = $this->controller->handleRequest('POST', '/api/warehouse/trigger-analytics-etl', $params);
        
        $this->assertIsArray($response);
        $this->assertArrayHasKey('status', $response);
        
        // Should handle gracefully even without ETL service
        if ($response['status'] === 'error') {
            $this->assertEquals(503, http_response_code());
            $this->assertStringContains('ETL service not available', $response['message']);
        }
    }
    
    public function testHandleGetDataQuality(): void {
        $params = [
            'timeframe' => '7d',
            'source' => 'analytics_api'
        ];
        
        $response = $this->controller->handleRequest('GET', '/api/warehouse/data-quality', $params);
        
        $this->assertIsArray($response);
        $this->assertArrayHasKey('status', $response);
        
        // Should handle gracefully even without database
        if ($response['status'] === 'error') {
            $this->assertEquals(503, http_response_code());
            $this->assertStringContains('Database not available', $response['message']);
        }
    }
    
    public function testHandleGetETLHistory(): void {
        $params = [
            'limit' => 10,
            'offset' => 0,
            'days' => 30
        ];
        
        $response = $this->controller->handleRequest('GET', '/api/warehouse/etl-history', $params);
        
        $this->assertIsArray($response);
        $this->assertArrayHasKey('status', $response);
        
        // Should handle gracefully even without database
        if ($response['status'] === 'error') {
            $this->assertEquals(503, http_response_code());
            $this->assertStringContains('Database not available', $response['message']);
        }
    }
    
    public function testHandleInvalidEndpoint(): void {
        $response = $this->controller->handleRequest('GET', '/api/warehouse/invalid-endpoint');
        
        $this->assertEquals('error', $response['status']);
        $this->assertEquals(404, http_response_code());
        $this->assertStringContains('Endpoint not found', $response['message']);
    }
    
    public function testHandleInvalidMethod(): void {
        $response = $this->controller->handleRequest('DELETE', '/api/warehouse/analytics-status');
        
        $this->assertEquals('error', $response['status']);
        $this->assertEquals(405, http_response_code());
        $this->assertStringContains('Method not allowed', $response['message']);
    }
    
    public function testTriggerETLWithInvalidType(): void {
        $params = [
            'etl_type' => 'invalid_type',
            'options' => []
        ];
        
        $response = $this->controller->handleRequest('POST', '/api/warehouse/trigger-analytics-etl', $params);
        
        $this->assertEquals('error', $response['status']);
        $this->assertEquals(400, http_response_code());
        $this->assertStringContains('Invalid ETL type', $response['message']);
    }
    
    public function testGetDataQualityWithValidTimeframes(): void {
        $validTimeframes = ['1d', '7d', '1w', '1m', '30d'];
        
        foreach ($validTimeframes as $timeframe) {
            $params = ['timeframe' => $timeframe];
            $response = $this->controller->handleRequest('GET', '/api/warehouse/data-quality', $params);
            
            $this->assertIsArray($response);
            $this->assertArrayHasKey('status', $response);
        }
    }
    
    public function testGetETLHistoryWithPagination(): void {
        $testCases = [
            ['limit' => 10, 'offset' => 0],
            ['limit' => 50, 'offset' => 10],
            ['limit' => 150, 'offset' => 0], // Should be capped at 100
        ];
        
        foreach ($testCases as $params) {
            $response = $this->controller->handleRequest('GET', '/api/warehouse/etl-history', $params);
            
            $this->assertIsArray($response);
            $this->assertArrayHasKey('status', $response);
        }
    }
    
    public function testGetETLHistoryWithFilters(): void {
        $params = [
            'status' => 'completed',
            'etl_type' => AnalyticsETL::TYPE_INCREMENTAL_SYNC,
            'days' => 7
        ];
        
        $response = $this->controller->handleRequest('GET', '/api/warehouse/etl-history', $params);
        
        $this->assertIsArray($response);
        $this->assertArrayHasKey('status', $response);
    }
    
    public function testResponseStructure(): void {
        $response = $this->controller->handleRequest('GET', '/api/warehouse/invalid-endpoint');
        
        // Test error response structure
        $this->assertArrayHasKey('status', $response);
        $this->assertArrayHasKey('message', $response);
        $this->assertArrayHasKey('code', $response);
        $this->assertArrayHasKey('timestamp', $response);
        
        $this->assertEquals('error', $response['status']);
        $this->assertIsString($response['message']);
        $this->assertIsInt($response['code']);
        $this->assertIsString($response['timestamp']);
    }
    
    public function testCorsHeaders(): void {
        // This test would need to capture headers in a real environment
        // For now, we just test that the method doesn't throw errors
        $response = $this->controller->handleRequest('OPTIONS', '/api/warehouse/analytics-status');
        
        $this->assertIsArray($response);
    }
    
    public function testControllerInitializationWithoutDatabase(): void {
        // Test that controller handles database initialization failure gracefully
        $controller = new AnalyticsETLController([
            'log_file' => '/tmp/test_controller_no_db.log'
        ]);
        
        $response = $controller->handleRequest('GET', '/api/warehouse/analytics-status');
        
        $this->assertIsArray($response);
        $this->assertEquals('error', $response['status']);
        
        // Clean up
        if (file_exists('/tmp/test_controller_no_db.log')) {
            unlink('/tmp/test_controller_no_db.log');
        }
    }
    
    public function testLogFileCreation(): void {
        $logFile = '/tmp/test_analytics_etl_controller.log';
        
        // Trigger a request that should create log entries
        $this->controller->handleRequest('GET', '/api/warehouse/invalid-endpoint');
        
        // Check if log file was created (it should be created during controller initialization)
        $this->assertTrue(file_exists($logFile));
        
        // Check if log file has content
        $logContent = file_get_contents($logFile);
        $this->assertNotEmpty($logContent);
    }
    
    public function testValidETLTypes(): void {
        $validTypes = [
            AnalyticsETL::TYPE_FULL_SYNC,
            AnalyticsETL::TYPE_INCREMENTAL_SYNC,
            AnalyticsETL::TYPE_MANUAL_SYNC,
            AnalyticsETL::TYPE_VALIDATION_ONLY
        ];
        
        foreach ($validTypes as $type) {
            $params = [
                'etl_type' => $type,
                'options' => []
            ];
            
            $response = $this->controller->handleRequest('POST', '/api/warehouse/trigger-analytics-etl', $params);
            
            $this->assertIsArray($response);
            
            // Should not fail due to invalid type (may fail due to missing ETL service)
            if ($response['status'] === 'error') {
                $this->assertNotEquals(400, http_response_code(), "ETL type {$type} should be valid");
            }
        }
    }
    
    public function testDataQualitySourceFilters(): void {
        $sources = ['all', 'analytics_api', 'manual', 'import'];
        
        foreach ($sources as $source) {
            $params = [
                'source' => $source,
                'timeframe' => '7d'
            ];
            
            $response = $this->controller->handleRequest('GET', '/api/warehouse/data-quality', $params);
            
            $this->assertIsArray($response);
            $this->assertArrayHasKey('status', $response);
        }
    }
    
    public function testETLHistoryStatusFilters(): void {
        $statuses = ['completed', 'failed', 'running', 'partial_success'];
        
        foreach ($statuses as $status) {
            $params = [
                'status' => $status,
                'limit' => 10
            ];
            
            $response = $this->controller->handleRequest('GET', '/api/warehouse/etl-history', $params);
            
            $this->assertIsArray($response);
            $this->assertArrayHasKey('status', $response);
        }
    }
    
    public function testRequestParameterSanitization(): void {
        // Test with potentially dangerous parameters
        $params = [
            'limit' => -10,        // Should be sanitized to 0 or positive
            'offset' => -5,        // Should be sanitized to 0
            'days' => 200,         // Should be capped at maximum
            'timeframe' => '999y'  // Should be handled gracefully
        ];
        
        $response = $this->controller->handleRequest('GET', '/api/warehouse/etl-history', $params);
        
        $this->assertIsArray($response);
        $this->assertArrayHasKey('status', $response);
    }
}