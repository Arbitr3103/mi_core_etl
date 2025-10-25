<?php
/**
 * Unit tests for WarehouseController Analytics API enhancements
 * 
 * Tests the enhanced WarehouseController functionality including:
 * - Analytics API data source filtering
 * - Data quality score filtering
 * - Freshness filtering
 * - Enhanced response format with Analytics data
 * 
 * Requirements: 9.1, 9.2, 9.4, 17.3
 * Task: 5.2 Расширить существующий WarehouseController
 */

require_once __DIR__ . '/../../api/classes/WarehouseController.php';

class WarehouseControllerAnalyticsTest extends PHPUnit\Framework\TestCase {
    private WarehouseController $controller;
    private PDO $mockPdo;
    
    protected function setUp(): void {
        // Create mock PDO
        $this->mockPdo = $this->createMock(PDO::class);
        
        // Create controller
        $this->controller = new WarehouseController($this->mockPdo);
    }
    
    public function testValidateDataSourceFilter(): void {
        // Test valid data sources
        $validSources = ['analytics_api', 'manual', 'import', 'all'];
        
        foreach ($validSources as $source) {
            $_GET = ['data_source' => $source];
            
            // Use reflection to access private method
            $reflection = new ReflectionClass($this->controller);
            $method = $reflection->getMethod('validateDashboardFilters');
            $method->setAccessible(true);
            
            $result = $method->invoke($this->controller, $_GET);
            
            $this->assertEquals($source, $result['data_source']);
        }
    }
    
    public function testValidateDataSourceFilterInvalid(): void {
        $_GET = ['data_source' => 'invalid_source'];
        
        $reflection = new ReflectionClass($this->controller);
        $method = $reflection->getMethod('validateDashboardFilters');
        $method->setAccessible(true);
        
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Invalid data_source');
        
        $method->invoke($this->controller, $_GET);
    }
    
    public function testValidateQualityScoreFilter(): void {
        // Test valid quality scores
        $validScores = [0, 50, 80, 100];
        
        foreach ($validScores as $score) {
            $_GET = ['quality_score' => (string)$score];
            
            $reflection = new ReflectionClass($this->controller);
            $method = $reflection->getMethod('validateDashboardFilters');
            $method->setAccessible(true);
            
            $result = $method->invoke($this->controller, $_GET);
            
            $this->assertEquals($score, $result['quality_score']);
        }
    }
    
    public function testValidateQualityScoreFilterInvalid(): void {
        $invalidScores = [-1, 101, 'abc', ''];
        
        foreach ($invalidScores as $score) {
            $_GET = ['quality_score' => $score];
            
            $reflection = new ReflectionClass($this->controller);
            $method = $reflection->getMethod('validateDashboardFilters');
            $method->setAccessible(true);
            
            $this->expectException(ValidationException::class);
            $this->expectExceptionMessage('Invalid quality_score');
            
            $method->invoke($this->controller, $_GET);
        }
    }
    
    public function testValidateFreshnessHoursFilter(): void {
        // Test valid freshness hours
        $validHours = [0, 6, 24, 72, 168];
        
        foreach ($validHours as $hours) {
            $_GET = ['freshness_hours' => (string)$hours];
            
            $reflection = new ReflectionClass($this->controller);
            $method = $reflection->getMethod('validateDashboardFilters');
            $method->setAccessible(true);
            
            $result = $method->invoke($this->controller, $_GET);
            
            $this->assertEquals($hours, $result['freshness_hours']);
        }
    }
    
    public function testValidateFreshnessHoursFilterInvalid(): void {
        $invalidHours = [-1, 'abc', ''];
        
        foreach ($invalidHours as $hours) {
            $_GET = ['freshness_hours' => $hours];
            
            $reflection = new ReflectionClass($this->controller);
            $method = $reflection->getMethod('validateDashboardFilters');
            $method->setAccessible(true);
            
            $this->expectException(ValidationException::class);
            $this->expectExceptionMessage('Invalid freshness_hours');
            
            $method->invoke($this->controller, $_GET);
        }
    }
    
    public function testValidateEnhancedSortFields(): void {
        // Test new Analytics API sort fields
        $newSortFields = ['data_quality_score', 'last_analytics_sync', 'data_source'];
        
        foreach ($newSortFields as $field) {
            $_GET = ['sort_by' => $field];
            
            $reflection = new ReflectionClass($this->controller);
            $method = $reflection->getMethod('validateDashboardFilters');
            $method->setAccessible(true);
            
            $result = $method->invoke($this->controller, $_GET);
            
            $this->assertEquals($field, $result['sort_by']);
        }
    }
    
    public function testValidateCombinedAnalyticsFilters(): void {
        $_GET = [
            'data_source' => 'analytics_api',
            'quality_score' => '80',
            'freshness_hours' => '24',
            'sort_by' => 'data_quality_score',
            'sort_order' => 'desc'
        ];
        
        $reflection = new ReflectionClass($this->controller);
        $method = $reflection->getMethod('validateDashboardFilters');
        $method->setAccessible(true);
        
        $result = $method->invoke($this->controller, $_GET);
        
        $this->assertEquals('analytics_api', $result['data_source']);
        $this->assertEquals(80, $result['quality_score']);
        $this->assertEquals(24, $result['freshness_hours']);
        $this->assertEquals('data_quality_score', $result['sort_by']);
        $this->assertEquals('desc', $result['sort_order']);
    }
    
    public function testValidateBackwardsCompatibility(): void {
        // Test that existing filters still work
        $_GET = [
            'warehouse' => 'Test Warehouse',
            'cluster' => 'Test Cluster',
            'liquidity_status' => 'critical',
            'active_only' => 'true',
            'has_replenishment_need' => 'true',
            'sort_by' => 'replenishment_need',
            'sort_order' => 'desc',
            'limit' => '50',
            'offset' => '10'
        ];
        
        $reflection = new ReflectionClass($this->controller);
        $method = $reflection->getMethod('validateDashboardFilters');
        $method->setAccessible(true);
        
        $result = $method->invoke($this->controller, $_GET);
        
        $this->assertEquals('Test Warehouse', $result['warehouse']);
        $this->assertEquals('Test Cluster', $result['cluster']);
        $this->assertEquals('critical', $result['liquidity_status']);
        $this->assertTrue($result['active_only']);
        $this->assertTrue($result['has_replenishment_need']);
        $this->assertEquals('replenishment_need', $result['sort_by']);
        $this->assertEquals('desc', $result['sort_order']);
        $this->assertEquals(50, $result['limit']);
        $this->assertEquals(10, $result['offset']);
    }
    
    public function testGetDashboardWithAnalyticsFilters(): void {
        // Mock the service to return test data
        $mockService = $this->createMock(WarehouseService::class);
        $mockService->method('getDashboardData')
            ->willReturn([
                'success' => true,
                'data' => [
                    'warehouses' => [
                        [
                            'warehouse_name' => 'Test Warehouse',
                            'items' => [
                                [
                                    'sku' => 'TEST-001',
                                    'name' => 'Test Product',
                                    'data_source' => 'analytics_api',
                                    'data_quality_score' => 95,
                                    'last_analytics_sync' => '2024-01-15 10:00:00',
                                    'hours_since_sync' => 2,
                                    'freshness_status' => 'fresh'
                                ]
                            ]
                        ]
                    ],
                    'summary' => [
                        'total_products' => 1,
                        'by_data_source' => [
                            'analytics_api' => 1,
                            'manual' => 0,
                            'import' => 0
                        ],
                        'data_quality' => [
                            'avg_quality_score' => 95.0
                        ],
                        'freshness' => [
                            'fresh_count' => 1,
                            'stale_count' => 0,
                            'fresh_percentage' => 100.0
                        ]
                    ]
                ]
            ]);
        
        // Use reflection to inject mock service
        $reflection = new ReflectionClass($this->controller);
        $property = $reflection->getProperty('service');
        $property->setAccessible(true);
        $property->setValue($this->controller, $mockService);
        
        // Set up request parameters
        $_GET = [
            'data_source' => 'analytics_api',
            'quality_score' => '80'
        ];
        
        // Capture output
        ob_start();
        $this->controller->getDashboard();
        $output = ob_get_clean();
        
        $response = json_decode($output, true);
        
        $this->assertTrue($response['success']);
        $this->assertArrayHasKey('warehouses', $response['data']);
        $this->assertArrayHasKey('summary', $response['data']);
        
        // Check Analytics API specific data
        $summary = $response['data']['summary'];
        $this->assertArrayHasKey('by_data_source', $summary);
        $this->assertArrayHasKey('data_quality', $summary);
        $this->assertArrayHasKey('freshness', $summary);
        
        $this->assertEquals(1, $summary['by_data_source']['analytics_api']);
        $this->assertEquals(95.0, $summary['data_quality']['avg_quality_score']);
        $this->assertEquals(100.0, $summary['freshness']['fresh_percentage']);
    }
    
    public function testSanitizeStringInput(): void {
        $reflection = new ReflectionClass($this->controller);
        $method = $reflection->getMethod('sanitizeString');
        $method->setAccessible(true);
        
        // Test XSS prevention
        $input = '<script>alert("xss")</script>';
        $result = $method->invoke($this->controller, $input);
        $this->assertStringNotContainsString('<script>', $result);
        
        // Test whitespace trimming
        $input = '  test warehouse  ';
        $result = $method->invoke($this->controller, $input);
        $this->assertEquals('test warehouse', $result);
        
        // Test quote escaping
        $input = 'test "warehouse" name';
        $result = $method->invoke($this->controller, $input);
        $this->assertStringContainsString('&quot;', $result);
    }
    
    public function testErrorHandling(): void {
        // Test that Analytics API filter validation errors are handled properly
        $_GET = ['data_source' => 'invalid'];
        
        ob_start();
        $this->controller->getDashboard();
        $output = ob_get_clean();
        
        $response = json_decode($output, true);
        
        $this->assertFalse($response['success']);
        $this->assertStringContainsString('Invalid data_source', $response['error']);
    }
    
    public function testEmptyFilters(): void {
        // Test that empty Analytics API filters are handled correctly
        $_GET = [
            'data_source' => '',
            'quality_score' => '',
            'freshness_hours' => ''
        ];
        
        $reflection = new ReflectionClass($this->controller);
        $method = $reflection->getMethod('validateDashboardFilters');
        $method->setAccessible(true);
        
        $result = $method->invoke($this->controller, $_GET);
        
        // Empty values should not be set in filters
        $this->assertArrayNotHasKey('data_source', $result);
        $this->assertArrayNotHasKey('quality_score', $result);
        $this->assertArrayNotHasKey('freshness_hours', $result);
    }
    
    public function testFilterParameterTypes(): void {
        // Test that parameters are properly typed
        $_GET = [
            'quality_score' => '85',
            'freshness_hours' => '12',
            'active_only' => 'false'
        ];
        
        $reflection = new ReflectionClass($this->controller);
        $method = $reflection->getMethod('validateDashboardFilters');
        $method->setAccessible(true);
        
        $result = $method->invoke($this->controller, $_GET);
        
        $this->assertIsInt($result['quality_score']);
        $this->assertIsInt($result['freshness_hours']);
        $this->assertIsBool($result['active_only']);
        
        $this->assertEquals(85, $result['quality_score']);
        $this->assertEquals(12, $result['freshness_hours']);
        $this->assertFalse($result['active_only']);
    }
}