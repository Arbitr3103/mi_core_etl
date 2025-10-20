<?php
/**
 * Integration Tests for Regional Analytics API Endpoints
 * 
 * Tests all REST endpoints with real database connections and validates
 * JSON response formats, error handling, and data integrity.
 * 
 * Requirements: 6.1, 6.2, 6.3
 */

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../../api/analytics/SalesAnalyticsService.php';
require_once __DIR__ . '/../../api/analytics/config.php';

use PHPUnit\Framework\TestCase;

class RegionalAnalyticsAPIIntegrationTest extends TestCase {
    
    private $baseUrl;
    private $apiKey;
    private $testDateFrom;
    private $testDateTo;
    
    protected function setUp(): void {
        // Set up test configuration
        $this->baseUrl = 'http://localhost/api/analytics/endpoints';
        $this->apiKey = 'test_api_key_' . uniqid();
        $this->testDateFrom = '2025-09-01';
        $this->testDateTo = '2025-09-30';
        
        // Create test API key for authentication
        $this->createTestApiKey();
    }
    
    protected function tearDown(): void {
        // Clean up test API key
        $this->cleanupTestApiKey();
    }
    
    /**
     * Create test API key for authentication
     */
    private function createTestApiKey() {
        try {
            require_once __DIR__ . '/../../api/analytics/AuthenticationManager.php';
            $authManager = new AuthenticationManager();
            $authManager->createApiKey($this->apiKey, 'integration_test', ['read']);
        } catch (Exception $e) {
            // Skip if authentication system not available
            $this->markTestSkipped('Authentication system not available: ' . $e->getMessage());
        }
    }
    
    /**
     * Clean up test API key
     */
    private function cleanupTestApiKey() {
        try {
            require_once __DIR__ . '/../../api/analytics/AuthenticationManager.php';
            $authManager = new AuthenticationManager();
            $authManager->revokeApiKey($this->apiKey);
        } catch (Exception $e) {
            // Ignore cleanup errors
        }
    }
    
    /**
     * Make HTTP request to API endpoint
     */
    private function makeApiRequest($endpoint, $params = []) {
        $params['api_key'] = $this->apiKey;
        $url = $this->baseUrl . '/' . $endpoint . '.php?' . http_build_query($params);
        
        $context = stream_context_create([
            'http' => [
                'method' => 'GET',
                'timeout' => 30,
                'header' => [
                    'Accept: application/json',
                    'User-Agent: RegionalAnalyticsAPIIntegrationTest/1.0'
                ]
            ]
        ]);
        
        $response = @file_get_contents($url, false, $context);
        
        if ($response === false) {
            $error = error_get_last();
            throw new Exception("HTTP request failed: " . $error['message']);
        }
        
        return json_decode($response, true);
    }
    
    /**
     * Test marketplace comparison endpoint
     * Requirements: 6.1, 6.2
     */
    public function testMarketplaceComparisonEndpoint() {
        $response = $this->makeApiRequest('marketplace-comparison', [
            'date_from' => $this->testDateFrom,
            'date_to' => $this->testDateTo
        ]);
        
        // Validate response structure
        $this->assertIsArray($response);
        $this->assertArrayHasKey('success', $response);
        $this->assertTrue($response['success']);
        $this->assertArrayHasKey('data', $response);
        
        $data = $response['data'];
        $this->assertArrayHasKey('period', $data);
        $this->assertArrayHasKey('summary', $data);
        $this->assertArrayHasKey('marketplaces', $data);
        
        // Validate period data
        $this->assertEquals($this->testDateFrom, $data['period']['date_from']);
        $this->assertEquals($this->testDateTo, $data['period']['date_to']);
        
        // Validate summary data structure
        $summary = $data['summary'];
        $this->assertArrayHasKey('total_revenue', $summary);
        $this->assertArrayHasKey('total_orders', $summary);
        $this->assertArrayHasKey('marketplaces_count', $summary);
        $this->assertIsNumeric($summary['total_revenue']);
        $this->assertIsInt($summary['total_orders']);
        
        // Validate marketplaces data
        $this->assertIsArray($data['marketplaces']);
        foreach ($data['marketplaces'] as $marketplace) {
            $this->assertArrayHasKey('marketplace', $marketplace);
            $this->assertArrayHasKey('total_orders', $marketplace);
            $this->assertArrayHasKey('total_revenue', $marketplace);
            $this->assertArrayHasKey('revenue_share', $marketplace);
            $this->assertArrayHasKey('orders_share', $marketplace);
            
            // Validate data types
            $this->assertIsString($marketplace['marketplace']);
            $this->assertIsInt($marketplace['total_orders']);
            $this->assertIsNumeric($marketplace['total_revenue']);
            $this->assertIsNumeric($marketplace['revenue_share']);
            $this->assertIsNumeric($marketplace['orders_share']);
            
            // Validate percentage ranges
            $this->assertGreaterThanOrEqual(0, $marketplace['revenue_share']);
            $this->assertLessThanOrEqual(100, $marketplace['revenue_share']);
        }
    }
    
    /**
     * Test top products endpoint
     * Requirements: 6.1, 6.2
     */
    public function testTopProductsEndpoint() {
        $response = $this->makeApiRequest('top-products', [
            'marketplace' => 'all',
            'limit' => 10,
            'date_from' => $this->testDateFrom,
            'date_to' => $this->testDateTo
        ]);
        
        // Validate response structure
        $this->assertIsArray($response);
        $this->assertArrayHasKey('success', $response);
        $this->assertTrue($response['success']);
        $this->assertArrayHasKey('data', $response);
        
        $data = $response['data'];
        $this->assertArrayHasKey('period', $data);
        $this->assertArrayHasKey('filters', $data);
        $this->assertArrayHasKey('products', $data);
        
        // Validate filters
        $filters = $data['filters'];
        $this->assertEquals('all', $filters['marketplace']);
        $this->assertEquals(10, $filters['limit']);
        
        // Validate products data
        $this->assertIsArray($data['products']);
        $this->assertLessThanOrEqual(10, count($data['products']));
        
        foreach ($data['products'] as $product) {
            $this->assertArrayHasKey('product_id', $product);
            $this->assertArrayHasKey('product_name', $product);
            $this->assertArrayHasKey('total_orders', $product);
            $this->assertArrayHasKey('total_revenue', $product);
            $this->assertArrayHasKey('margin', $product);
            $this->assertArrayHasKey('margin_percent', $product);
            
            // Validate data types
            $this->assertIsInt($product['product_id']);
            $this->assertIsString($product['product_name']);
            $this->assertIsInt($product['total_orders']);
            $this->assertIsNumeric($product['total_revenue']);
            $this->assertIsNumeric($product['margin']);
            $this->assertIsNumeric($product['margin_percent']);
        }
    }
    
    /**
     * Test sales dynamics endpoint
     * Requirements: 6.1, 6.2
     */
    public function testSalesDynamicsEndpoint() {
        $response = $this->makeApiRequest('sales-dynamics', [
            'period' => 'month',
            'date_from' => $this->testDateFrom,
            'date_to' => $this->testDateTo,
            'marketplace' => 'all'
        ]);
        
        // Validate response structure
        $this->assertIsArray($response);
        $this->assertArrayHasKey('success', $response);
        $this->assertTrue($response['success']);
        $this->assertArrayHasKey('data', $response);
        
        $data = $response['data'];
        $this->assertArrayHasKey('period_type', $data);
        $this->assertArrayHasKey('date_range', $data);
        $this->assertArrayHasKey('dynamics', $data);
        
        // Validate period type
        $this->assertEquals('month', $data['period_type']);
        
        // Validate dynamics data
        $this->assertIsArray($data['dynamics']);
        
        foreach ($data['dynamics'] as $period) {
            $this->assertArrayHasKey('period', $period);
            $this->assertArrayHasKey('total_orders', $period);
            $this->assertArrayHasKey('total_revenue', $period);
            $this->assertArrayHasKey('total_quantity', $period);
            $this->assertArrayHasKey('marketplaces', $period);
            
            // Validate data types
            $this->assertIsString($period['period']);
            $this->assertIsInt($period['total_orders']);
            $this->assertIsNumeric($period['total_revenue']);
            $this->assertIsInt($period['total_quantity']);
            $this->assertIsArray($period['marketplaces']);
            
            // Validate growth rates if present
            if (isset($period['growth_rates'])) {
                $this->assertArrayHasKey('orders_growth', $period['growth_rates']);
                $this->assertArrayHasKey('revenue_growth', $period['growth_rates']);
                $this->assertIsNumeric($period['growth_rates']['orders_growth']);
                $this->assertIsNumeric($period['growth_rates']['revenue_growth']);
            }
        }
    }
    
    /**
     * Test dashboard summary endpoint
     * Requirements: 6.1, 6.2
     */
    public function testDashboardSummaryEndpoint() {
        $response = $this->makeApiRequest('dashboard-summary', [
            'date_from' => $this->testDateFrom,
            'date_to' => $this->testDateTo,
            'marketplace' => 'all'
        ]);
        
        // Validate response structure
        $this->assertIsArray($response);
        $this->assertArrayHasKey('success', $response);
        $this->assertTrue($response['success']);
        $this->assertArrayHasKey('data', $response);
        
        $data = $response['data'];
        $this->assertArrayHasKey('total_revenue', $data);
        $this->assertArrayHasKey('total_orders', $data);
        $this->assertArrayHasKey('total_quantity', $data);
        $this->assertArrayHasKey('average_order_value', $data);
        $this->assertArrayHasKey('unique_products', $data);
        $this->assertArrayHasKey('active_marketplaces', $data);
        $this->assertArrayHasKey('period_info', $data);
        
        // Validate data types
        $this->assertIsNumeric($data['total_revenue']);
        $this->assertIsInt($data['total_orders']);
        $this->assertIsInt($data['total_quantity']);
        $this->assertIsNumeric($data['average_order_value']);
        $this->assertIsInt($data['unique_products']);
        $this->assertIsInt($data['active_marketplaces']);
        
        // Validate period info
        $periodInfo = $data['period_info'];
        $this->assertArrayHasKey('date_from', $periodInfo);
        $this->assertArrayHasKey('date_to', $periodInfo);
        $this->assertEquals($this->testDateFrom, $periodInfo['date_from']);
        $this->assertEquals($this->testDateTo, $periodInfo['date_to']);
    }
    
    /**
     * Test error handling for invalid date range
     * Requirements: 6.2, 6.3
     */
    public function testInvalidDateRangeError() {
        $response = $this->makeApiRequest('marketplace-comparison', [
            'date_from' => 'invalid-date',
            'date_to' => $this->testDateTo
        ]);
        
        // Validate error response
        $this->assertIsArray($response);
        $this->assertArrayHasKey('success', $response);
        $this->assertFalse($response['success']);
        $this->assertArrayHasKey('error', $response);
        
        $error = $response['error'];
        $this->assertArrayHasKey('code', $error);
        $this->assertArrayHasKey('message', $error);
        $this->assertEquals('INVALID_DATE_RANGE', $error['code']);
    }
    
    /**
     * Test error handling for invalid marketplace filter
     * Requirements: 6.2, 6.3
     */
    public function testInvalidMarketplaceFilterError() {
        $response = $this->makeApiRequest('top-products', [
            'marketplace' => 'invalid_marketplace',
            'limit' => 10,
            'date_from' => $this->testDateFrom,
            'date_to' => $this->testDateTo
        ]);
        
        // Validate error response
        $this->assertIsArray($response);
        $this->assertArrayHasKey('success', $response);
        $this->assertFalse($response['success']);
        $this->assertArrayHasKey('error', $response);
        
        $error = $response['error'];
        $this->assertArrayHasKey('code', $error);
        $this->assertArrayHasKey('message', $error);
        $this->assertEquals('INVALID_MARKETPLACE_FILTER', $error['code']);
    }
    
    /**
     * Test authentication with invalid API key
     * Requirements: 6.2, 6.3
     */
    public function testInvalidApiKeyError() {
        $response = $this->makeApiRequest('marketplace-comparison', [
            'date_from' => $this->testDateFrom,
            'date_to' => $this->testDateTo,
            'api_key' => 'invalid_api_key'
        ]);
        
        // Validate authentication error response
        $this->assertIsArray($response);
        $this->assertArrayHasKey('success', $response);
        $this->assertFalse($response['success']);
        $this->assertArrayHasKey('error', $response);
        
        $error = $response['error'];
        $this->assertArrayHasKey('code', $error);
        $this->assertEquals('AUTHENTICATION_FAILED', $error['code']);
    }
    
    /**
     * Test rate limiting functionality
     * Requirements: 6.2, 6.3
     */
    public function testRateLimiting() {
        // Make multiple rapid requests to trigger rate limiting
        $responses = [];
        for ($i = 0; $i < 15; $i++) {
            try {
                $response = $this->makeApiRequest('dashboard-summary', [
                    'date_from' => $this->testDateFrom,
                    'date_to' => $this->testDateTo
                ]);
                $responses[] = $response;
            } catch (Exception $e) {
                // Rate limiting may cause connection failures
                break;
            }
        }
        
        // Check if rate limiting was triggered
        $rateLimitTriggered = false;
        foreach ($responses as $response) {
            if (isset($response['error']) && $response['error']['code'] === 'RATE_LIMIT_EXCEEDED') {
                $rateLimitTriggered = true;
                break;
            }
        }
        
        // Rate limiting should be triggered for excessive requests
        $this->assertTrue($rateLimitTriggered || count($responses) < 15, 
            'Rate limiting should be triggered for excessive requests');
    }
    
    /**
     * Test pagination functionality
     * Requirements: 6.2, 6.3
     */
    public function testPaginationSupport() {
        // Test with large limit to check pagination
        $response = $this->makeApiRequest('top-products', [
            'marketplace' => 'all',
            'limit' => 50,
            'date_from' => $this->testDateFrom,
            'date_to' => $this->testDateTo
        ]);
        
        // Validate response structure
        $this->assertIsArray($response);
        $this->assertArrayHasKey('success', $response);
        $this->assertTrue($response['success']);
        
        $data = $response['data'];
        $this->assertArrayHasKey('products', $data);
        $this->assertLessThanOrEqual(50, count($data['products']));
        
        // Check if pagination info is included for large datasets
        if (count($data['products']) >= 50) {
            $this->assertArrayHasKey('pagination', $data);
            $pagination = $data['pagination'];
            $this->assertArrayHasKey('current_page', $pagination);
            $this->assertArrayHasKey('total_pages', $pagination);
            $this->assertArrayHasKey('total_items', $pagination);
        }
    }
    
    /**
     * Test data consistency across endpoints
     * Requirements: 6.1, 6.2
     */
    public function testDataConsistencyAcrossEndpoints() {
        // Get summary data
        $summaryResponse = $this->makeApiRequest('dashboard-summary', [
            'date_from' => $this->testDateFrom,
            'date_to' => $this->testDateTo,
            'marketplace' => 'all'
        ]);
        
        // Get marketplace comparison data
        $comparisonResponse = $this->makeApiRequest('marketplace-comparison', [
            'date_from' => $this->testDateFrom,
            'date_to' => $this->testDateTo
        ]);
        
        // Validate both responses are successful
        $this->assertTrue($summaryResponse['success']);
        $this->assertTrue($comparisonResponse['success']);
        
        $summaryData = $summaryResponse['data'];
        $comparisonData = $comparisonResponse['data'];
        
        // Check data consistency between endpoints
        $this->assertEquals($summaryData['total_revenue'], $comparisonData['summary']['total_revenue'],
            'Total revenue should be consistent across endpoints');
        
        $this->assertEquals($summaryData['total_orders'], $comparisonData['summary']['total_orders'],
            'Total orders should be consistent across endpoints');
    }
}