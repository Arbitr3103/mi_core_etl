<?php
/**
 * Integration Tests for Warehouse Dashboard API Endpoints
 * 
 * Tests all warehouse dashboard REST endpoints with real database connections
 * and validates JSON response formats, error handling, and data integrity.
 * 
 * Requirements: 1-12
 */

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../../config/database_postgresql.php';
require_once __DIR__ . '/../../api/classes/WarehouseController.php';

use PHPUnit\Framework\TestCase;

class WarehouseDashboardAPIIntegrationTest extends TestCase {
    
    private $pdo;
    private $baseUrl;
    
    protected function setUp(): void {
        // Get database connection
        $this->pdo = getDatabaseConnection();
        
        // Set up test configuration
        $this->baseUrl = 'http://localhost/api/warehouse-dashboard.php';
        
        // Ensure test data exists
        $this->ensureTestData();
    }
    
    protected function tearDown(): void {
        // Clean up is handled by database transactions
        $this->pdo = null;
    }
    
    /**
     * Ensure test data exists in database
     */
    private function ensureTestData() {
        // Check if we have test data
        $stmt = $this->pdo->query("
            SELECT COUNT(*) FROM inventory 
            WHERE source = 'ozon' 
            LIMIT 1
        ");
        
        $count = $stmt->fetchColumn();
        
        if ($count == 0) {
            $this->markTestSkipped('No test data available in database');
        }
    }
    
    /**
     * Make HTTP request to API endpoint
     */
    private function makeApiRequest($params = []) {
        $url = $this->baseUrl . '?' . http_build_query($params);
        
        $context = stream_context_create([
            'http' => [
                'method' => 'GET',
                'timeout' => 30,
                'header' => [
                    'Accept: application/json',
                    'User-Agent: WarehouseDashboardAPIIntegrationTest/1.0'
                ]
            ]
        ]);
        
        $response = @file_get_contents($url, false, $context);
        
        if ($response === false) {
            $error = error_get_last();
            throw new Exception("HTTP request failed: " . ($error['message'] ?? 'Unknown error'));
        }
        
        return json_decode($response, true);
    }
    
    /**
     * Test dashboard endpoint with default parameters
     * Requirements: 1, 2, 9
     */
    public function testDashboardEndpointDefault() {
        $response = $this->makeApiRequest([
            'action' => 'dashboard'
        ]);
        
        // Validate response structure
        $this->assertIsArray($response);
        $this->assertArrayHasKey('success', $response);
        $this->assertTrue($response['success']);
        $this->assertArrayHasKey('data', $response);
        
        $data = $response['data'];
        
        // Validate main data structure
        $this->assertArrayHasKey('warehouses', $data);
        $this->assertArrayHasKey('summary', $data);
        $this->assertArrayHasKey('filters_applied', $data);
        $this->assertArrayHasKey('pagination', $data);
        $this->assertArrayHasKey('last_updated', $data);
        
        // Validate warehouses array
        $this->assertIsArray($data['warehouses']);
        
        if (count($data['warehouses']) > 0) {
            $warehouse = $data['warehouses'][0];
            $this->assertArrayHasKey('warehouse_name', $warehouse);
            $this->assertArrayHasKey('cluster', $warehouse);
            $this->assertArrayHasKey('items', $warehouse);
            $this->assertArrayHasKey('totals', $warehouse);
            
            // Validate warehouse items
            $this->assertIsArray($warehouse['items']);
            if (count($warehouse['items']) > 0) {
                $this->validateWarehouseItem($warehouse['items'][0]);
            }
            
            // Validate warehouse totals
            $totals = $warehouse['totals'];
            $this->assertArrayHasKey('total_items', $totals);
            $this->assertArrayHasKey('total_available', $totals);
            $this->assertArrayHasKey('total_replenishment_need', $totals);
        }
        
        // Validate summary
        $this->validateSummary($data['summary']);
        
        // Validate pagination
        $pagination = $data['pagination'];
        $this->assertArrayHasKey('limit', $pagination);
        $this->assertArrayHasKey('offset', $pagination);
        $this->assertArrayHasKey('total', $pagination);
        $this->assertArrayHasKey('current_page', $pagination);
        $this->assertArrayHasKey('total_pages', $pagination);
        $this->assertArrayHasKey('has_next', $pagination);
        $this->assertArrayHasKey('has_prev', $pagination);
    }
    
    /**
     * Test dashboard endpoint with warehouse filter
     * Requirements: 2, 9
     */
    public function testDashboardEndpointWithWarehouseFilter() {
        // First get list of warehouses
        $warehousesResponse = $this->makeApiRequest([
            'action' => 'warehouses'
        ]);
        
        $this->assertTrue($warehousesResponse['success']);
        $warehouses = $warehousesResponse['data'];
        
        if (count($warehouses) == 0) {
            $this->markTestSkipped('No warehouses available for testing');
        }
        
        $testWarehouse = $warehouses[0]['warehouse_name'];
        
        // Test with warehouse filter
        $response = $this->makeApiRequest([
            'action' => 'dashboard',
            'warehouse' => $testWarehouse
        ]);
        
        $this->assertTrue($response['success']);
        $data = $response['data'];
        
        // Validate filter was applied
        $this->assertEquals($testWarehouse, $data['filters_applied']['warehouse']);
        
        // Validate all items belong to the filtered warehouse
        foreach ($data['warehouses'] as $warehouse) {
            $this->assertEquals($testWarehouse, $warehouse['warehouse_name']);
        }
    }
    
    /**
     * Test dashboard endpoint with cluster filter
     * Requirements: 2, 9
     */
    public function testDashboardEndpointWithClusterFilter() {
        // First get list of clusters
        $clustersResponse = $this->makeApiRequest([
            'action' => 'clusters'
        ]);
        
        $this->assertTrue($clustersResponse['success']);
        $clusters = $clustersResponse['data'];
        
        if (count($clusters) == 0) {
            $this->markTestSkipped('No clusters available for testing');
        }
        
        $testCluster = $clusters[0]['cluster'];
        
        // Test with cluster filter
        $response = $this->makeApiRequest([
            'action' => 'dashboard',
            'cluster' => $testCluster
        ]);
        
        $this->assertTrue($response['success']);
        $data = $response['data'];
        
        // Validate filter was applied
        $this->assertEquals($testCluster, $data['filters_applied']['cluster']);
        
        // Validate all items belong to the filtered cluster
        foreach ($data['warehouses'] as $warehouse) {
            $this->assertEquals($testCluster, $warehouse['cluster']);
        }
    }
    
    /**
     * Test dashboard endpoint with liquidity status filter
     * Requirements: 5, 7, 9
     */
    public function testDashboardEndpointWithLiquidityFilter() {
        $liquidityStatuses = ['critical', 'low', 'normal', 'excess'];
        
        foreach ($liquidityStatuses as $status) {
            $response = $this->makeApiRequest([
                'action' => 'dashboard',
                'liquidity_status' => $status
            ]);
            
            $this->assertTrue($response['success']);
            $data = $response['data'];
            
            // Validate filter was applied
            $this->assertEquals($status, $data['filters_applied']['liquidity_status']);
            
            // Validate all items have the filtered liquidity status
            foreach ($data['warehouses'] as $warehouse) {
                foreach ($warehouse['items'] as $item) {
                    $this->assertEquals($status, $item['liquidity_status']);
                }
            }
        }
    }
    
    /**
     * Test dashboard endpoint with active_only filter
     * Requirements: 1, 9
     */
    public function testDashboardEndpointWithActiveOnlyFilter() {
        // Test with active_only = true
        $response = $this->makeApiRequest([
            'action' => 'dashboard',
            'active_only' => 'true'
        ]);
        
        $this->assertTrue($response['success']);
        $data = $response['data'];
        
        // Validate all items are active (have sales or stock)
        foreach ($data['warehouses'] as $warehouse) {
            foreach ($warehouse['items'] as $item) {
                $isActive = $item['sales_last_28_days'] > 0 || $item['available'] > 0;
                $this->assertTrue($isActive, 
                    "Item {$item['name']} should be active but has no sales and no stock");
            }
        }
        
        // Test with active_only = false
        $response = $this->makeApiRequest([
            'action' => 'dashboard',
            'active_only' => 'false'
        ]);
        
        $this->assertTrue($response['success']);
    }
    
    /**
     * Test dashboard endpoint with replenishment need filter
     * Requirements: 3, 9
     */
    public function testDashboardEndpointWithReplenishmentNeedFilter() {
        $response = $this->makeApiRequest([
            'action' => 'dashboard',
            'has_replenishment_need' => 'true'
        ]);
        
        $this->assertTrue($response['success']);
        $data = $response['data'];
        
        // Validate all items have replenishment need
        foreach ($data['warehouses'] as $warehouse) {
            foreach ($warehouse['items'] as $item) {
                $this->assertGreaterThan(0, $item['replenishment_need'],
                    "Item {$item['name']} should have replenishment need");
            }
        }
    }
    
    /**
     * Test dashboard endpoint with sorting
     * Requirements: 9
     */
    public function testDashboardEndpointWithSorting() {
        $sortFields = [
            'replenishment_need' => 'desc',
            'daily_sales_avg' => 'desc',
            'days_of_stock' => 'asc',
            'available' => 'desc'
        ];
        
        foreach ($sortFields as $field => $order) {
            $response = $this->makeApiRequest([
                'action' => 'dashboard',
                'sort_by' => $field,
                'sort_order' => $order,
                'limit' => 10
            ]);
            
            $this->assertTrue($response['success']);
            $data = $response['data'];
            
            // Validate sort parameters were applied
            $this->assertEquals($field, $data['filters_applied']['sort_by']);
            $this->assertEquals($order, $data['filters_applied']['sort_order']);
            
            // Validate items are sorted correctly
            $this->validateSorting($data['warehouses'], $field, $order);
        }
    }
    
    /**
     * Test dashboard endpoint with pagination
     * Requirements: 12
     */
    public function testDashboardEndpointWithPagination() {
        // Test first page
        $response1 = $this->makeApiRequest([
            'action' => 'dashboard',
            'limit' => 5,
            'offset' => 0
        ]);
        
        $this->assertTrue($response1['success']);
        $data1 = $response1['data'];
        
        $this->assertEquals(5, $data1['pagination']['limit']);
        $this->assertEquals(0, $data1['pagination']['offset']);
        $this->assertEquals(1, $data1['pagination']['current_page']);
        
        // Test second page
        $response2 = $this->makeApiRequest([
            'action' => 'dashboard',
            'limit' => 5,
            'offset' => 5
        ]);
        
        $this->assertTrue($response2['success']);
        $data2 = $response2['data'];
        
        $this->assertEquals(5, $data2['pagination']['limit']);
        $this->assertEquals(5, $data2['pagination']['offset']);
        $this->assertEquals(2, $data2['pagination']['current_page']);
        
        // Validate pages have different items
        if (count($data1['warehouses']) > 0 && count($data2['warehouses']) > 0) {
            $items1 = $this->flattenWarehouseItems($data1['warehouses']);
            $items2 = $this->flattenWarehouseItems($data2['warehouses']);
            
            if (count($items1) > 0 && count($items2) > 0) {
                $this->assertNotEquals($items1[0]['product_id'], $items2[0]['product_id'],
                    'Different pages should have different items');
            }
        }
    }
    
    /**
     * Test warehouses endpoint
     * Requirements: 2, 9
     */
    public function testWarehousesEndpoint() {
        $response = $this->makeApiRequest([
            'action' => 'warehouses'
        ]);
        
        $this->assertIsArray($response);
        $this->assertArrayHasKey('success', $response);
        $this->assertTrue($response['success']);
        $this->assertArrayHasKey('data', $response);
        
        $warehouses = $response['data'];
        $this->assertIsArray($warehouses);
        
        foreach ($warehouses as $warehouse) {
            $this->assertArrayHasKey('warehouse_name', $warehouse);
            $this->assertArrayHasKey('cluster', $warehouse);
            $this->assertArrayHasKey('product_count', $warehouse);
            
            $this->assertIsString($warehouse['warehouse_name']);
            $this->assertIsInt($warehouse['product_count']);
            $this->assertGreaterThan(0, $warehouse['product_count']);
        }
    }
    
    /**
     * Test clusters endpoint
     * Requirements: 2, 9
     */
    public function testClustersEndpoint() {
        $response = $this->makeApiRequest([
            'action' => 'clusters'
        ]);
        
        $this->assertIsArray($response);
        $this->assertArrayHasKey('success', $response);
        $this->assertTrue($response['success']);
        $this->assertArrayHasKey('data', $response);
        
        $clusters = $response['data'];
        $this->assertIsArray($clusters);
        
        foreach ($clusters as $cluster) {
            $this->assertArrayHasKey('cluster', $cluster);
            $this->assertArrayHasKey('warehouse_count', $cluster);
            $this->assertArrayHasKey('product_count', $cluster);
            
            $this->assertIsString($cluster['cluster']);
            $this->assertIsInt($cluster['warehouse_count']);
            $this->assertIsInt($cluster['product_count']);
            $this->assertGreaterThan(0, $cluster['warehouse_count']);
            $this->assertGreaterThan(0, $cluster['product_count']);
        }
    }
    
    /**
     * Test export endpoint
     * Requirements: 10
     */
    public function testExportEndpoint() {
        $url = $this->baseUrl . '?' . http_build_query([
            'action' => 'export',
            'limit' => 10
        ]);
        
        $context = stream_context_create([
            'http' => [
                'method' => 'GET',
                'timeout' => 30
            ]
        ]);
        
        $response = @file_get_contents($url, false, $context);
        
        $this->assertNotFalse($response, 'Export request should succeed');
        
        // Validate CSV format
        $lines = explode("\n", trim($response));
        $this->assertGreaterThan(0, count($lines), 'CSV should have content');
        
        // Validate header row
        $header = str_getcsv($lines[0]);
        $expectedHeaders = [
            'Товар', 'SKU', 'Склад', 'Кластер', 'Доступно',
            'Зарезервировано', 'Готовим к продаже', 'В заявках',
            'В пути', 'На проверке', 'Возвраты', 'Истекает срок',
            'Брак', 'Продажи/день', 'Продаж за 28 дней',
            'Дней без продаж', 'Дней запаса', 'Статус ликвидности',
            'Целевой запас', 'Нужно заказать'
        ];
        
        $this->assertEquals($expectedHeaders, $header, 'CSV header should match expected format');
    }
    
    /**
     * Test error handling for invalid action
     */
    public function testInvalidActionError() {
        $response = $this->makeApiRequest([
            'action' => 'invalid_action'
        ]);
        
        $this->assertIsArray($response);
        $this->assertArrayHasKey('success', $response);
        $this->assertFalse($response['success']);
        $this->assertArrayHasKey('error', $response);
    }
    
    /**
     * Test error handling for invalid parameters
     */
    public function testInvalidParametersError() {
        $response = $this->makeApiRequest([
            'action' => 'dashboard',
            'limit' => -1
        ]);
        
        // Should handle gracefully (convert to valid value or return error)
        $this->assertIsArray($response);
        $this->assertArrayHasKey('success', $response);
    }
    
    /**
     * Test performance with large dataset
     * Requirements: 12
     */
    public function testPerformanceWithLargeDataset() {
        $startTime = microtime(true);
        
        $response = $this->makeApiRequest([
            'action' => 'dashboard',
            'limit' => 100
        ]);
        
        $endTime = microtime(true);
        $duration = $endTime - $startTime;
        
        $this->assertTrue($response['success']);
        $this->assertLessThan(2.0, $duration, 
            'Dashboard should load within 2 seconds (Requirement 12.1)');
    }
    
    /**
     * Validate warehouse item structure
     */
    private function validateWarehouseItem($item) {
        // Product info
        $this->assertArrayHasKey('product_id', $item);
        $this->assertArrayHasKey('sku', $item);
        $this->assertArrayHasKey('name', $item);
        
        // Warehouse info
        $this->assertArrayHasKey('warehouse_name', $item);
        $this->assertArrayHasKey('cluster', $item);
        
        // Stock info
        $this->assertArrayHasKey('available', $item);
        $this->assertArrayHasKey('reserved', $item);
        
        // Ozon metrics
        $this->assertArrayHasKey('preparing_for_sale', $item);
        $this->assertArrayHasKey('in_transit', $item);
        $this->assertArrayHasKey('in_inspection', $item);
        
        // Sales metrics
        $this->assertArrayHasKey('daily_sales_avg', $item);
        $this->assertArrayHasKey('sales_last_28_days', $item);
        $this->assertArrayHasKey('days_without_sales', $item);
        
        // Liquidity metrics
        $this->assertArrayHasKey('days_of_stock', $item);
        $this->assertArrayHasKey('liquidity_status', $item);
        
        // Replenishment
        $this->assertArrayHasKey('target_stock', $item);
        $this->assertArrayHasKey('replenishment_need', $item);
        
        // Validate data types
        $this->assertIsInt($item['product_id']);
        $this->assertIsString($item['name']);
        $this->assertIsInt($item['available']);
        $this->assertIsNumeric($item['daily_sales_avg']);
        $this->assertIsInt($item['sales_last_28_days']);
        $this->assertIsInt($item['target_stock']);
        $this->assertIsInt($item['replenishment_need']);
        
        // Validate liquidity status values
        $this->assertContains($item['liquidity_status'], 
            ['critical', 'low', 'normal', 'excess']);
    }
    
    /**
     * Validate summary structure
     */
    private function validateSummary($summary) {
        $this->assertArrayHasKey('total_products', $summary);
        $this->assertArrayHasKey('active_products', $summary);
        $this->assertArrayHasKey('total_replenishment_need', $summary);
        $this->assertArrayHasKey('by_liquidity', $summary);
        
        $this->assertIsInt($summary['total_products']);
        $this->assertIsInt($summary['active_products']);
        $this->assertIsInt($summary['total_replenishment_need']);
        
        $byLiquidity = $summary['by_liquidity'];
        $this->assertArrayHasKey('critical', $byLiquidity);
        $this->assertArrayHasKey('low', $byLiquidity);
        $this->assertArrayHasKey('normal', $byLiquidity);
        $this->assertArrayHasKey('excess', $byLiquidity);
        
        $this->assertIsInt($byLiquidity['critical']);
        $this->assertIsInt($byLiquidity['low']);
        $this->assertIsInt($byLiquidity['normal']);
        $this->assertIsInt($byLiquidity['excess']);
    }
    
    /**
     * Validate sorting order
     */
    private function validateSorting($warehouses, $field, $order) {
        $items = $this->flattenWarehouseItems($warehouses);
        
        if (count($items) < 2) {
            return; // Not enough items to validate sorting
        }
        
        for ($i = 0; $i < count($items) - 1; $i++) {
            $current = $items[$i][$field];
            $next = $items[$i + 1][$field];
            
            // Handle null values
            if ($current === null || $next === null) {
                continue;
            }
            
            if ($order === 'asc') {
                $this->assertLessThanOrEqual($next, $current,
                    "Items should be sorted in ascending order by $field");
            } else {
                $this->assertGreaterThanOrEqual($next, $current,
                    "Items should be sorted in descending order by $field");
            }
        }
    }
    
    /**
     * Flatten warehouse items from grouped structure
     */
    private function flattenWarehouseItems($warehouses) {
        $items = [];
        foreach ($warehouses as $warehouse) {
            foreach ($warehouse['items'] as $item) {
                $items[] = $item;
            }
        }
        return $items;
    }
}
