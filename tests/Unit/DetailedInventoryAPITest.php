<?php
/**
 * Detailed Inventory API Test
 * 
 * Unit tests for the new detailed inventory API endpoints.
 * Tests filtering, sorting, pagination, and caching functionality.
 * 
 * Requirements: 6.1, 6.2, 6.3
 * Task: 1.4 Write backend API tests
 */

require_once __DIR__ . '/../../config/database_postgresql.php';
require_once __DIR__ . '/../../api/classes/DetailedInventoryService.php';
require_once __DIR__ . '/../../api/classes/DetailedInventoryController.php';
require_once __DIR__ . '/../../api/classes/CacheService.php';

class DetailedInventoryAPITest extends PHPUnit\Framework\TestCase {
    
    private $pdo;
    private $service;
    private $controller;
    private $cache;
    
    /**
     * Set up test environment
     */
    protected function setUp(): void {
        // Get database connection
        try {
            $this->pdo = getDatabaseConnection();
        } catch (Exception $e) {
            $this->markTestSkipped('Database connection not available: ' . $e->getMessage());
        }
        
        // Create cache service with test directory
        $testCacheDir = __DIR__ . '/../../cache/test_inventory';
        $this->cache = new CacheService($testCacheDir, 60); // 1 minute TTL for tests
        
        // Create service and controller instances
        $this->service = new DetailedInventoryService($this->pdo, $this->cache);
        $this->controller = new DetailedInventoryController($this->pdo);
        
        // Clear test cache
        $this->cache->clear();
    }
    
    /**
     * Clean up after tests
     */
    protected function tearDown(): void {
        if ($this->cache) {
            $this->cache->clear();
        }
    }
    
    /**
     * Test basic detailed inventory retrieval
     */
    public function testGetDetailedInventoryBasic() {
        $result = $this->service->getDetailedInventory();
        
        $this->assertIsArray($result);
        $this->assertTrue($result['success']);
        $this->assertArrayHasKey('data', $result);
        $this->assertArrayHasKey('metadata', $result);
        
        // Check metadata structure
        $metadata = $result['metadata'];
        $this->assertArrayHasKey('totalCount', $metadata);
        $this->assertArrayHasKey('filteredCount', $metadata);
        $this->assertArrayHasKey('timestamp', $metadata);
        $this->assertArrayHasKey('processingTime', $metadata);
        $this->assertArrayHasKey('cached', $metadata);
        
        // First call should not be cached
        $this->assertFalse($metadata['cached']);
        
        // Check data structure if we have data
        if (!empty($result['data'])) {
            $item = $result['data'][0];
            $this->assertArrayHasKey('productId', $item);
            $this->assertArrayHasKey('productName', $item);
            $this->assertArrayHasKey('sku', $item);
            $this->assertArrayHasKey('warehouseName', $item);
            $this->assertArrayHasKey('currentStock', $item);
            $this->assertArrayHasKey('dailySales', $item);
            $this->assertArrayHasKey('daysOfStock', $item);
            $this->assertArrayHasKey('status', $item);
            $this->assertArrayHasKey('recommendedQty', $item);
            $this->assertArrayHasKey('urgencyScore', $item);
        }
    }
    
    /**
     * Test caching functionality
     */
    public function testCachingFunctionality() {
        $filters = ['active_only' => true];
        
        // First call - should not be cached
        $result1 = $this->service->getDetailedInventory($filters);
        $this->assertFalse($result1['metadata']['cached']);
        
        // Second call - should be cached
        $result2 = $this->service->getDetailedInventory($filters);
        $this->assertTrue($result2['metadata']['cached']);
        
        // Processing time should be lower for cached result
        $this->assertLessThan(
            $result1['metadata']['processingTime'],
            $result2['metadata']['processingTime']
        );
        
        // Data should be the same
        $this->assertEquals($result1['data'], $result2['data']);
    }
    
    /**
     * Test warehouse filtering
     */
    public function testWarehouseFiltering() {
        // Get all warehouses first
        $warehousesResult = $this->service->getWarehouses();
        $this->assertTrue($warehousesResult['success']);
        
        if (!empty($warehousesResult['data'])) {
            $firstWarehouse = $warehousesResult['data'][0]['warehouse_name'];
            
            // Filter by specific warehouse
            $result = $this->service->getDetailedInventory([
                'warehouse' => $firstWarehouse
            ]);
            
            $this->assertTrue($result['success']);
            
            // All items should be from the specified warehouse
            foreach ($result['data'] as $item) {
                $this->assertEquals($firstWarehouse, $item['warehouseName']);
            }
        }
    }
    
    /**
     * Test status filtering
     */
    public function testStatusFiltering() {
        $validStatuses = ['critical', 'low', 'normal', 'excess', 'out_of_stock'];
        
        foreach ($validStatuses as $status) {
            $result = $this->service->getDetailedInventory([
                'status' => $status
            ]);
            
            $this->assertTrue($result['success']);
            
            // All items should have the specified status
            foreach ($result['data'] as $item) {
                $this->assertEquals($status, $item['status']);
            }
        }
    }
    
    /**
     * Test multiple status filtering
     */
    public function testMultipleStatusFiltering() {
        $statuses = ['critical', 'low'];
        
        $result = $this->service->getDetailedInventory([
            'statuses' => $statuses
        ]);
        
        $this->assertTrue($result['success']);
        
        // All items should have one of the specified statuses
        foreach ($result['data'] as $item) {
            $this->assertContains($item['status'], $statuses);
        }
    }
    
    /**
     * Test search functionality
     */
    public function testSearchFunctionality() {
        // Get some data first to find a product name to search for
        $allData = $this->service->getDetailedInventory(['limit' => 10]);
        
        if (!empty($allData['data'])) {
            $firstProduct = $allData['data'][0];
            $searchTerm = substr($firstProduct['productName'], 0, 5); // First 5 characters
            
            $result = $this->service->getDetailedInventory([
                'search' => $searchTerm
            ]);
            
            $this->assertTrue($result['success']);
            
            // All items should contain the search term in product name or SKU
            foreach ($result['data'] as $item) {
                $found = stripos($item['productName'], $searchTerm) !== false ||
                        stripos($item['sku'], $searchTerm) !== false ||
                        stripos($item['skuOzon'], $searchTerm) !== false ||
                        stripos($item['skuWb'], $searchTerm) !== false ||
                        stripos($item['skuInternal'], $searchTerm) !== false;
                
                $this->assertTrue($found, "Search term '{$searchTerm}' not found in product data");
            }
        }
    }
    
    /**
     * Test sorting functionality
     */
    public function testSortingFunctionality() {
        $sortFields = [
            'product_name' => 'productName',
            'warehouse_name' => 'warehouseName',
            'current_stock' => 'currentStock',
            'daily_sales_avg' => 'dailySales',
            'urgency_score' => 'urgencyScore'
        ];
        
        foreach ($sortFields as $sortBy => $dataField) {
            // Test ascending order
            $resultAsc = $this->service->getDetailedInventory([
                'sort_by' => $sortBy,
                'sort_order' => 'asc',
                'limit' => 5
            ]);
            
            $this->assertTrue($resultAsc['success']);
            
            // Test descending order
            $resultDesc = $this->service->getDetailedInventory([
                'sort_by' => $sortBy,
                'sort_order' => 'desc',
                'limit' => 5
            ]);
            
            $this->assertTrue($resultDesc['success']);
            
            // Verify sorting (if we have enough data)
            if (count($resultAsc['data']) >= 2) {
                $this->verifySorting($resultAsc['data'], $dataField, 'asc');
            }
            
            if (count($resultDesc['data']) >= 2) {
                $this->verifySorting($resultDesc['data'], $dataField, 'desc');
            }
        }
    }
    
    /**
     * Test pagination functionality
     */
    public function testPaginationFunctionality() {
        // Get first page
        $page1 = $this->service->getDetailedInventory([
            'limit' => 5,
            'offset' => 0
        ]);
        
        $this->assertTrue($page1['success']);
        $this->assertLessThanOrEqual(5, count($page1['data']));
        
        // Get second page
        $page2 = $this->service->getDetailedInventory([
            'limit' => 5,
            'offset' => 5
        ]);
        
        $this->assertTrue($page2['success']);
        
        // Pages should be different (if we have enough data)
        if (count($page1['data']) === 5 && count($page2['data']) > 0) {
            $page1Ids = array_column($page1['data'], 'productId');
            $page2Ids = array_column($page2['data'], 'productId');
            
            // Should have different product IDs
            $this->assertNotEquals($page1Ids, $page2Ids);
        }
    }
    
    /**
     * Test warehouse list retrieval
     */
    public function testGetWarehouses() {
        $result = $this->service->getWarehouses();
        
        $this->assertTrue($result['success']);
        $this->assertArrayHasKey('data', $result);
        
        // Check warehouse data structure
        foreach ($result['data'] as $warehouse) {
            $this->assertArrayHasKey('warehouse_name', $warehouse);
            $this->assertArrayHasKey('product_count', $warehouse);
            $this->assertArrayHasKey('total_stock', $warehouse);
            $this->assertArrayHasKey('critical_count', $warehouse);
            $this->assertArrayHasKey('low_count', $warehouse);
            $this->assertArrayHasKey('replenishment_needed_count', $warehouse);
            
            // Validate data types
            $this->assertIsString($warehouse['warehouse_name']);
            $this->assertIsNumeric($warehouse['product_count']);
            $this->assertIsNumeric($warehouse['total_stock']);
        }
    }
    
    /**
     * Test summary statistics retrieval
     */
    public function testGetSummaryStats() {
        $result = $this->service->getSummaryStats();
        
        $this->assertTrue($result['success']);
        $this->assertArrayHasKey('data', $result);
        
        $stats = $result['data'];
        
        // Check required fields
        $this->assertArrayHasKey('totalProducts', $stats);
        $this->assertArrayHasKey('totalWarehouses', $stats);
        $this->assertArrayHasKey('totalStock', $stats);
        $this->assertArrayHasKey('statusCounts', $stats);
        $this->assertArrayHasKey('replenishmentNeededCount', $stats);
        
        // Validate data types
        $this->assertIsInt($stats['totalProducts']);
        $this->assertIsInt($stats['totalWarehouses']);
        $this->assertIsInt($stats['totalStock']);
        $this->assertIsArray($stats['statusCounts']);
        
        // Check status counts structure
        $statusCounts = $stats['statusCounts'];
        $this->assertArrayHasKey('critical', $statusCounts);
        $this->assertArrayHasKey('low', $statusCounts);
        $this->assertArrayHasKey('normal', $statusCounts);
        $this->assertArrayHasKey('excess', $statusCounts);
        $this->assertArrayHasKey('outOfStock', $statusCounts);
    }
    
    /**
     * Test filter validation
     */
    public function testFilterValidation() {
        // Test invalid status
        $this->expectException(DetailedInventoryValidationException::class);
        
        $controller = new DetailedInventoryController($this->pdo);
        
        // Capture output to test validation
        ob_start();
        
        // Simulate invalid status in $_GET
        $_GET = ['status' => 'invalid_status'];
        
        try {
            $controller->getDetailedStock();
        } catch (Exception $e) {
            // Expected validation exception
        }
        
        ob_end_clean();
        
        // Reset $_GET
        $_GET = [];
    }
    
    /**
     * Test cache key generation
     */
    public function testCacheKeyGeneration() {
        $filters1 = ['warehouse' => 'Test', 'status' => 'critical'];
        $filters2 = ['status' => 'critical', 'warehouse' => 'Test']; // Same filters, different order
        
        $key1 = $this->cache->getInventoryKey($filters1);
        $key2 = $this->cache->getInventoryKey($filters2);
        
        // Keys should be the same regardless of filter order
        $this->assertEquals($key1, $key2);
        
        // Different filters should produce different keys
        $filters3 = ['warehouse' => 'Different', 'status' => 'critical'];
        $key3 = $this->cache->getInventoryKey($filters3);
        
        $this->assertNotEquals($key1, $key3);
    }
    
    /**
     * Test cache statistics
     */
    public function testCacheStatistics() {
        // Generate some cached data
        $this->service->getDetailedInventory(['limit' => 5]);
        $this->service->getWarehouses();
        $this->service->getSummaryStats();
        
        $stats = $this->cache->getStats();
        
        $this->assertArrayHasKey('type', $stats);
        $this->assertArrayHasKey('cache_dir', $stats);
        $this->assertArrayHasKey('default_ttl', $stats);
        
        if ($stats['type'] === 'file') {
            $this->assertArrayHasKey('file_count', $stats);
            $this->assertArrayHasKey('total_size', $stats);
            $this->assertGreaterThan(0, $stats['file_count']);
        }
    }
    
    /**
     * Helper method to verify sorting
     */
    private function verifySorting($data, $field, $order) {
        for ($i = 0; $i < count($data) - 1; $i++) {
            $current = $data[$i][$field];
            $next = $data[$i + 1][$field];
            
            if ($order === 'asc') {
                $this->assertLessThanOrEqual($next, $current, "Ascending sort failed for field {$field}");
            } else {
                $this->assertGreaterThanOrEqual($next, $current, "Descending sort failed for field {$field}");
            }
        }
    }
    
    /**
     * Test performance with large datasets
     */
    public function testPerformanceWithLargeDataset() {
        $startTime = microtime(true);
        
        $result = $this->service->getDetailedInventory([
            'limit' => 1000 // Large limit
        ]);
        
        $endTime = microtime(true);
        $executionTime = ($endTime - $startTime) * 1000; // Convert to milliseconds
        
        $this->assertTrue($result['success']);
        
        // API should respond within reasonable time (adjust threshold as needed)
        $this->assertLessThan(5000, $executionTime, 'API response time exceeded 5 seconds');
        
        // Check that processing time is reported in metadata
        $this->assertArrayHasKey('processingTime', $result['metadata']);
        $this->assertIsNumeric($result['metadata']['processingTime']);
    }
    
    /**
     * Test data consistency
     */
    public function testDataConsistency() {
        $result = $this->service->getDetailedInventory(['limit' => 10]);
        
        if (!empty($result['data'])) {
            foreach ($result['data'] as $item) {
                // Validate urgency score is within expected range
                $this->assertGreaterThanOrEqual(0, $item['urgencyScore']);
                $this->assertLessThanOrEqual(100, $item['urgencyScore']);
                
                // Validate stockout risk is within expected range
                $this->assertGreaterThanOrEqual(0, $item['stockoutRisk']);
                $this->assertLessThanOrEqual(100, $item['stockoutRisk']);
                
                // Validate status is one of expected values
                $validStatuses = ['critical', 'low', 'normal', 'excess', 'out_of_stock', 'no_sales', 'archived_or_hidden'];
                $this->assertContains($item['status'], $validStatuses);
                
                // Validate numeric fields are non-negative
                $this->assertGreaterThanOrEqual(0, $item['currentStock']);
                $this->assertGreaterThanOrEqual(0, $item['recommendedQty']);
                $this->assertGreaterThanOrEqual(0, $item['dailySales']);
            }
        }
    }
    
    // ========================================
    // NEW INTEGRATION TESTS FOR TASK 4.3
    // ========================================
    
    /**
     * Test API response includes new visibility and stock_status fields
     * Requirements: 4.1, 4.2, 4.3
     */
    public function testApiResponseIncludesNewFields() {
        $result = $this->service->getDetailedInventory(['limit' => 5]);
        
        $this->assertTrue($result['success']);
        $this->assertArrayHasKey('data', $result);
        
        if (!empty($result['data'])) {
            $item = $result['data'][0];
            
            // Test that visibility field is present
            $this->assertArrayHasKey('visibility', $item, 'API response should include visibility field');
            
            // Test that status field is present (this is the stock_status)
            $this->assertArrayHasKey('status', $item, 'API response should include status field');
            
            // Test visibility field has valid values
            $validVisibility = ['VISIBLE', 'HIDDEN', 'MODERATION', 'UNKNOWN'];
            $this->assertContains($item['visibility'], $validVisibility, 
                'Visibility field should have valid value');
            
            // Test status field has valid values including new ones
            $validStatuses = ['critical', 'low', 'normal', 'excess', 'out_of_stock', 'archived_or_hidden'];
            $this->assertContains($item['status'], $validStatuses, 
                'Status field should have valid value');
        }
    }
    
    /**
     * Test default filtering excludes archived_or_hidden and out_of_stock items
     * Requirements: 4.1, 4.3, 4.4
     */
    public function testDefaultFilteringBehavior() {
        // Get data with default filters (no include_hidden parameter)
        $result = $this->service->getDetailedInventory(['limit' => 100]);
        
        $this->assertTrue($result['success']);
        
        // Check that no items have archived_or_hidden or out_of_stock status
        foreach ($result['data'] as $item) {
            $this->assertNotEquals('archived_or_hidden', $item['status'], 
                'Default filter should exclude archived_or_hidden items');
            $this->assertNotEquals('out_of_stock', $item['status'], 
                'Default filter should exclude out_of_stock items');
        }
    }
    
    /**
     * Test include_hidden parameter shows all products including hidden ones
     * Requirements: 4.1, 4.2, 4.3
     */
    public function testIncludeHiddenParameter() {
        // Get data with include_hidden=true
        $resultWithHidden = $this->service->getDetailedInventory([
            'include_hidden' => true,
            'limit' => 100
        ]);
        
        // Get data with default filters
        $resultDefault = $this->service->getDetailedInventory(['limit' => 100]);
        
        $this->assertTrue($resultWithHidden['success']);
        $this->assertTrue($resultDefault['success']);
        
        // Count with hidden should be >= count without hidden
        $countWithHidden = count($resultWithHidden['data']);
        $countDefault = count($resultDefault['data']);
        
        $this->assertGreaterThanOrEqual($countDefault, $countWithHidden, 
            'include_hidden=true should return same or more items than default');
        
        // Check if we actually have hidden items in the include_hidden result
        $hasHiddenItems = false;
        foreach ($resultWithHidden['data'] as $item) {
            if (in_array($item['status'], ['archived_or_hidden', 'out_of_stock'])) {
                $hasHiddenItems = true;
                break;
            }
        }
        
        // If we found hidden items, the counts should be different
        if ($hasHiddenItems) {
            $this->assertGreaterThan($countDefault, $countWithHidden, 
                'When hidden items exist, include_hidden=true should return more items');
        }
    }
    
    /**
     * Test stock_status filtering functionality
     * Requirements: 4.1, 4.2, 4.3
     */
    public function testStockStatusFiltering() {
        // Test filtering by archived_or_hidden status (requires include_hidden=true)
        $result = $this->service->getDetailedInventory([
            'status' => 'archived_or_hidden',
            'include_hidden' => true,
            'limit' => 10
        ]);
        
        $this->assertTrue($result['success']);
        
        // All returned items should have archived_or_hidden status
        foreach ($result['data'] as $item) {
            $this->assertEquals('archived_or_hidden', $item['status'], 
                'All items should have archived_or_hidden status when filtered');
        }
        
        // Test filtering by out_of_stock status (requires include_hidden=true)
        $result = $this->service->getDetailedInventory([
            'status' => 'out_of_stock',
            'include_hidden' => true,
            'limit' => 10
        ]);
        
        $this->assertTrue($result['success']);
        
        // All returned items should have out_of_stock status
        foreach ($result['data'] as $item) {
            $this->assertEquals('out_of_stock', $item['status'], 
                'All items should have out_of_stock status when filtered');
        }
        
        // Test filtering by multiple statuses including new ones
        $result = $this->service->getDetailedInventory([
            'statuses' => ['critical', 'archived_or_hidden'],
            'include_hidden' => true,
            'limit' => 20
        ]);
        
        $this->assertTrue($result['success']);
        
        // All returned items should have one of the specified statuses
        foreach ($result['data'] as $item) {
            $this->assertContains($item['status'], ['critical', 'archived_or_hidden'], 
                'All items should have one of the specified statuses');
        }
    }
    
    /**
     * Test visibility filtering functionality
     * Requirements: 4.1, 4.2, 4.3
     */
    public function testVisibilityFiltering() {
        // Test filtering by VISIBLE visibility
        $result = $this->service->getDetailedInventory([
            'visibility' => 'VISIBLE',
            'include_hidden' => true,
            'limit' => 20
        ]);
        
        $this->assertTrue($result['success']);
        
        // All returned items should have VISIBLE visibility
        foreach ($result['data'] as $item) {
            $this->assertEquals('VISIBLE', $item['visibility'], 
                'All items should have VISIBLE visibility when filtered');
        }
        
        // Test filtering by HIDDEN visibility
        $result = $this->service->getDetailedInventory([
            'visibility' => 'HIDDEN',
            'include_hidden' => true,
            'limit' => 20
        ]);
        
        $this->assertTrue($result['success']);
        
        // All returned items should have HIDDEN visibility
        foreach ($result['data'] as $item) {
            $this->assertEquals('HIDDEN', $item['visibility'], 
                'All items should have HIDDEN visibility when filtered');
        }
    }
    
    /**
     * Test combined visibility and stock_status filtering
     * Requirements: 4.1, 4.2, 4.3
     */
    public function testCombinedVisibilityAndStatusFiltering() {
        $result = $this->service->getDetailedInventory([
            'visibility' => 'VISIBLE',
            'status' => 'critical',
            'limit' => 10
        ]);
        
        $this->assertTrue($result['success']);
        
        // All returned items should match both filters
        foreach ($result['data'] as $item) {
            $this->assertEquals('VISIBLE', $item['visibility'], 
                'All items should have VISIBLE visibility');
            $this->assertEquals('critical', $item['status'], 
                'All items should have critical status');
        }
    }
    
    /**
     * Test backward compatibility with existing API consumers
     * Requirements: 4.4
     */
    public function testBackwardCompatibility() {
        // Test that existing field names and structure are preserved
        $result = $this->service->getDetailedInventory(['limit' => 5]);
        
        $this->assertTrue($result['success']);
        $this->assertArrayHasKey('data', $result);
        $this->assertArrayHasKey('metadata', $result);
        
        if (!empty($result['data'])) {
            $item = $result['data'][0];
            
            // Test that all existing fields are still present
            $existingFields = [
                'productId', 'productName', 'sku', 'skuOzon', 'skuWb', 'skuInternal',
                'warehouseName', 'cluster', 'marketplaceSource', 'currentStock', 
                'availableStock', 'dailySales', 'sales28d', 'daysOfStock', 'status',
                'recommendedQty', 'recommendedValue', 'urgencyScore', 'stockoutRisk',
                'costPrice', 'currentStockValue', 'turnoverRate', 'salesTrend',
                'lastUpdated', 'lastSaleDate'
            ];
            
            foreach ($existingFields as $field) {
                $this->assertArrayHasKey($field, $item, 
                    "Existing field '$field' should be preserved for backward compatibility");
            }
            
            // Test that new fields are added without breaking existing structure
            $this->assertArrayHasKey('visibility', $item, 
                'New visibility field should be added');
        }
        
        // Test that metadata structure is preserved
        $metadata = $result['metadata'];
        $existingMetadataFields = [
            'totalCount', 'filteredCount', 'timestamp', 'processingTime', 'cached'
        ];
        
        foreach ($existingMetadataFields as $field) {
            $this->assertArrayHasKey($field, $metadata, 
                "Existing metadata field '$field' should be preserved");
        }
    }
    
    /**
     * Test parameter validation for new filtering options
     * Requirements: 4.1, 4.2, 4.3
     */
    public function testNewParameterValidation() {
        // Test invalid visibility value
        try {
            $controller = new DetailedInventoryController($this->pdo);
            
            // Simulate invalid visibility in $_GET
            $_GET = ['visibility' => 'INVALID_VISIBILITY'];
            
            ob_start();
            $controller->getDetailedStock();
            $output = ob_get_clean();
            
            // Should return error response
            $response = json_decode($output, true);
            $this->assertFalse($response['success']);
            $this->assertStringContainsString('Invalid visibility', $response['error']);
            
        } catch (Exception $e) {
            // Exception is also acceptable for validation failure
            $this->assertStringContainsString('visibility', $e->getMessage());
        } finally {
            $_GET = []; // Reset $_GET
        }
        
        // Test valid visibility values
        $validVisibilities = ['VISIBLE', 'HIDDEN', 'MODERATION', 'UNKNOWN'];
        
        foreach ($validVisibilities as $visibility) {
            $result = $this->service->getDetailedInventory([
                'visibility' => $visibility,
                'include_hidden' => true,
                'limit' => 5
            ]);
            
            $this->assertTrue($result['success'], 
                "Valid visibility '$visibility' should be accepted");
        }
    }
    
    /**
     * Test API performance with new filtering options
     * Requirements: 4.4
     */
    public function testPerformanceWithNewFilters() {
        $startTime = microtime(true);
        
        // Test complex filtering with new options
        $result = $this->service->getDetailedInventory([
            'visibility' => 'VISIBLE',
            'statuses' => ['critical', 'low', 'normal'],
            'include_hidden' => false,
            'limit' => 100
        ]);
        
        $endTime = microtime(true);
        $executionTime = ($endTime - $startTime) * 1000;
        
        $this->assertTrue($result['success']);
        
        // Performance should still be acceptable with new filters
        $this->assertLessThan(3000, $executionTime, 
            'API with new filters should respond within 3 seconds');
        
        // Check processing time in metadata
        $this->assertArrayHasKey('processingTime', $result['metadata']);
        $this->assertLessThan(3000, $result['metadata']['processingTime']);
    }
    
    /**
     * Test that API returns only "in sale" products by default
     * Requirements: 4.1, 4.3, 4.4
     */
    public function testDefaultReturnsOnlyInSaleProducts() {
        $result = $this->service->getDetailedInventory(['limit' => 50]);
        
        $this->assertTrue($result['success']);
        
        // All returned products should be "in sale" (not archived/hidden or out of stock)
        foreach ($result['data'] as $item) {
            // Products should have available stock or be in a sellable status
            $isInSale = !in_array($item['status'], ['archived_or_hidden', 'out_of_stock']);
            
            $this->assertTrue($isInSale, 
                'Default API should return only products that are "in sale"');
        }
    }
    
    /**
     * Test filtering parameter combinations
     * Requirements: 4.1, 4.2, 4.3
     */
    public function testFilteringParameterCombinations() {
        // Test various parameter combinations to ensure they work together
        $testCombinations = [
            [
                'visibility' => 'VISIBLE',
                'warehouse' => null, // Will be set dynamically
                'status' => 'critical'
            ],
            [
                'include_hidden' => true,
                'statuses' => ['out_of_stock', 'archived_or_hidden'],
                'limit' => 10
            ],
            [
                'visibility' => 'VISIBLE',
                'include_hidden' => false,
                'search' => 'test',
                'sort_by' => 'urgency_score'
            ]
        ];
        
        // Get a warehouse name for testing
        $warehousesResult = $this->service->getWarehouses();
        if (!empty($warehousesResult['data'])) {
            $testCombinations[0]['warehouse'] = $warehousesResult['data'][0]['warehouse_name'];
        }
        
        foreach ($testCombinations as $index => $filters) {
            $result = $this->service->getDetailedInventory($filters);
            
            $this->assertTrue($result['success'], 
                "Parameter combination $index should work correctly");
            
            $this->assertArrayHasKey('data', $result);
            $this->assertArrayHasKey('metadata', $result);
        }
    }
}

?>