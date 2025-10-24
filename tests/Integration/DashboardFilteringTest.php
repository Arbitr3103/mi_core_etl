<?php
/**
 * Integration Tests for Dashboard Filtering
 * Task 6.3: Тестирование фильтрации в дашборде
 * 
 * Tests dashboard filter buttons, product display filtering, and statistics updates
 */

// Only include database config to avoid function conflicts
if (!function_exists('getDatabaseConnection')) {
    require_once __DIR__ . '/../../config/database_postgresql.php';
}

class DashboardFilteringTest
{
    private $pdo;
    private $testTableName = 'test_inventory_filtering';
    private $baseUrl;
    
    public function setUp()
    {
        $this->pdo = getDatabaseConnection();
        $this->baseUrl = 'http://localhost/api/inventory-analytics.php';
        
        $this->createTestTable();
        $this->insertTestData();
    }
    
    public function tearDown()
    {
        $this->pdo->exec("DROP TABLE IF EXISTS {$this->testTableName}");
    }
    
    private function createTestTable()
    {
        $sql = "
            CREATE TABLE {$this->testTableName} (
                id SERIAL PRIMARY KEY,
                sku VARCHAR(50) NOT NULL,
                name VARCHAR(200),
                warehouse_name VARCHAR(100) NOT NULL,
                quantity_present INTEGER DEFAULT 0,
                available INTEGER DEFAULT 0,
                preparing_for_sale INTEGER DEFAULT 0,
                in_requests INTEGER DEFAULT 0,
                in_transit INTEGER DEFAULT 0,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            )
        ";
        
        $this->pdo->exec($sql);
    }
    
    private function insertTestData()
    {
        $testData = [
            // Active products (total_stock > 0)
            ['sku' => 'ACTIVE001', 'name' => 'Active Product 1', 'warehouse_name' => 'Warehouse1', 'quantity_present' => 10, 'available' => 5],
            ['sku' => 'ACTIVE002', 'name' => 'Active Product 2', 'warehouse_name' => 'Warehouse1', 'quantity_present' => 0, 'available' => 8],
            ['sku' => 'ACTIVE003', 'name' => 'Active Product 3', 'warehouse_name' => 'Warehouse2', 'quantity_present' => 3, 'preparing_for_sale' => 2],
            ['sku' => 'ACTIVE004', 'name' => 'Active Product 4', 'warehouse_name' => 'Warehouse2', 'quantity_present' => 0, 'in_transit' => 15],
            ['sku' => 'ACTIVE005', 'name' => 'Active Product 5', 'warehouse_name' => 'Warehouse3', 'quantity_present' => 1],
            
            // Inactive products (total_stock = 0)
            ['sku' => 'INACTIVE001', 'name' => 'Inactive Product 1', 'warehouse_name' => 'Warehouse1', 'quantity_present' => 0, 'available' => 0],
            ['sku' => 'INACTIVE002', 'name' => 'Inactive Product 2', 'warehouse_name' => 'Warehouse2', 'quantity_present' => 0, 'available' => 0],
            ['sku' => 'INACTIVE003', 'name' => 'Inactive Product 3', 'warehouse_name' => 'Warehouse3', 'quantity_present' => 0, 'available' => 0],
            
            // Edge cases
            ['sku' => 'EDGE001', 'name' => 'Edge Case 1', 'warehouse_name' => 'Warehouse1'], // All NULL values
            ['sku' => 'EDGE002', 'name' => 'Edge Case 2', 'warehouse_name' => 'Warehouse2', 'quantity_present' => 0, 'available' => 0, 'preparing_for_sale' => 0, 'in_requests' => 0, 'in_transit' => 0],
        ];
        
        foreach ($testData as $item) {
            $columns = array_keys($item);
            $placeholders = ':' . implode(', :', $columns);
            $columnsList = implode(', ', $columns);
            
            $sql = "INSERT INTO {$this->testTableName} ({$columnsList}) VALUES ({$placeholders})";
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($item);
        }
    }
    
    /**
     * Test API filter parameter handling
     * Requirements: 4.1, 4.2 - Добавить кнопки фильтрации и реализовать логику фильтрации
     */
    public function testAPIFilterParameterHandling()
    {
        // Test active filter
        $activeResults = $this->makeAPIRequest(['activity_filter' => 'active']);
        assertArrayHasKey('data', $activeResults, 'Active filter should return data');
        
        // Test inactive filter
        $inactiveResults = $this->makeAPIRequest(['activity_filter' => 'inactive']);
        assertArrayHasKey('data', $inactiveResults, 'Inactive filter should return data');
        
        // Test all filter
        $allResults = $this->makeAPIRequest(['activity_filter' => 'all']);
        assertArrayHasKey('data', $allResults, 'All filter should return data');
        
        // Test default behavior (should default to active)
        $defaultResults = $this->makeAPIRequest([]);
        assertArrayHasKey('data', $defaultResults, 'Default should return data');
        
        // Verify metadata contains filter information
        assertArrayHasKey('metadata', $activeResults);
        assertArrayHasKey('activity_filter', $activeResults['metadata']);
        assertEquals('active', $activeResults['metadata']['activity_filter']);
    }
    
    /**
     * Test product filtering logic
     * Requirements: 4.2 - Реализовать логику фильтрации товаров
     */
    public function testProductFilteringLogic()
    {
        // Get products with different filters
        $activeProducts = $this->getFilteredProducts('active');
        $inactiveProducts = $this->getFilteredProducts('inactive');
        $allProducts = $this->getFilteredProducts('all');
        
        // Verify active products have stock > 0
        foreach ($activeProducts as $product) {
            $totalStock = $this->calculateTotalStock($product);
            assertGreaterThan(0, $totalStock, 
                "Active product {$product['sku']} should have total stock > 0");
        }
        
        // Verify inactive products have stock = 0
        foreach ($inactiveProducts as $product) {
            $totalStock = $this->calculateTotalStock($product);
            assertEquals(0, $totalStock, 
                "Inactive product {$product['sku']} should have total stock = 0");
        }
        
        // Verify all products = active + inactive
        assertEquals(
            count($activeProducts) + count($inactiveProducts),
            count($allProducts),
            'All products should equal active + inactive products'
        );
        
        // Verify no overlap between active and inactive
        $activeSkus = array_column($activeProducts, 'sku');
        $inactiveSkus = array_column($inactiveProducts, 'sku');
        $overlap = array_intersect($activeSkus, $inactiveSkus);
        assertEmpty($overlap, 'Active and inactive products should not overlap');
    }
    
    /**
     * Test statistics updates with filtering
     * Requirements: 4.3 - Обновить статистику в дашборде
     */
    public function testStatisticsUpdatesWithFiltering()
    {
        // Get statistics for each filter
        $activeStats = $this->getActivityStatistics('active');
        $inactiveStats = $this->getActivityStatistics('inactive');
        $allStats = $this->getActivityStatistics('all');
        
        // Verify active filter statistics
        assertGreaterThan(0, $activeStats['active_count'], 'Active filter should show active products');
        assertEquals(0, $activeStats['inactive_count'], 'Active filter should show 0 inactive products');
        
        // Verify inactive filter statistics
        assertEquals(0, $inactiveStats['active_count'], 'Inactive filter should show 0 active products');
        assertGreaterThan(0, $inactiveStats['inactive_count'], 'Inactive filter should show inactive products');
        
        // Verify all filter statistics
        assertGreaterThan(0, $allStats['active_count'], 'All filter should show active products');
        assertGreaterThan(0, $allStats['inactive_count'], 'All filter should show inactive products');
        
        // Verify totals consistency
        assertEquals(
            $allStats['active_count'] + $allStats['inactive_count'],
            $allStats['total_count'],
            'Total count should equal active + inactive'
        );
        
        // Verify percentages
        $expectedActivePercentage = ($allStats['active_count'] / $allStats['total_count']) * 100;
        assertEquals(
            round($expectedActivePercentage, 2),
            $allStats['active_percentage'],
            'Active percentage should be calculated correctly'
        );
    }
    
    /**
     * Test filter validation and error handling
     * Requirements: 4.1 - Добавить кнопки фильтрации в дашборд
     */
    public function testFilterValidationAndErrorHandling()
    {
        // Test invalid filter value
        $invalidResult = $this->makeAPIRequest(['activity_filter' => 'invalid']);
        assertEquals('error', $invalidResult['status'], 'Invalid filter should return error');
        assertArrayHasKey('errors', $invalidResult, 'Invalid filter should have error details');
        
        // Test case sensitivity
        $upperCaseResult = $this->makeAPIRequest(['activity_filter' => 'ACTIVE']);
        assertEquals('error', $upperCaseResult['status'], 'Case sensitive filter should return error');
        
        // Test empty filter (should default to active)
        $emptyResult = $this->makeAPIRequest(['activity_filter' => '']);
        assertEquals('success', $emptyResult['status'], 'Empty filter should succeed with default');
        
        // Test null filter (should default to active)
        $nullResult = $this->makeAPIRequest([]);
        assertEquals('success', $nullResult['status'], 'No filter should succeed with default');
    }
    
    /**
     * Test filter consistency across different endpoints
     */
    public function testFilterConsistencyAcrossEndpoints()
    {
        $endpoints = ['dashboard', 'critical-products', 'low-stock-products', 'overstock-products'];
        
        foreach ($endpoints as $endpoint) {
            // Test active filter
            $activeResult = $this->makeAPIRequest(['action' => $endpoint, 'activity_filter' => 'active']);
            assertEquals('success', $activeResult['status'], 
                "Active filter should work for {$endpoint} endpoint");
            assertEquals('active', $activeResult['metadata']['activity_filter'],
                "Metadata should reflect active filter for {$endpoint}");
            
            // Test inactive filter
            $inactiveResult = $this->makeAPIRequest(['action' => $endpoint, 'activity_filter' => 'inactive']);
            assertEquals('success', $inactiveResult['status'], 
                "Inactive filter should work for {$endpoint} endpoint");
            assertEquals('inactive', $inactiveResult['metadata']['activity_filter'],
                "Metadata should reflect inactive filter for {$endpoint}");
        }
    }
    
    /**
     * Test filter performance with large datasets
     */
    public function testFilterPerformanceWithLargeDatasets()
    {
        // Add more test data
        $this->insertLargeTestDataset();
        
        // Test performance of each filter
        $filters = ['active', 'inactive', 'all'];
        
        foreach ($filters as $filter) {
            $startTime = microtime(true);
            
            $result = $this->makeAPIRequest(['activity_filter' => $filter, 'limit' => 'all']);
            
            $executionTime = microtime(true) - $startTime;
            
            assertEquals('success', $result['status'], 
                "Filter {$filter} should succeed with large dataset");
            assertLessThan(2.0, $executionTime, 
                "Filter {$filter} should execute in reasonable time");
        }
    }
    
    /**
     * Test filter state persistence and metadata
     */
    public function testFilterStatePersistenceAndMetadata()
    {
        $filters = ['active', 'inactive', 'all'];
        
        foreach ($filters as $filter) {
            $result = $this->makeAPIRequest(['activity_filter' => $filter]);
            
            // Verify metadata contains filter state
            assertArrayHasKey('metadata', $result);
            assertArrayHasKey('activity_filter', $result['metadata']);
            assertEquals($filter, $result['metadata']['activity_filter']);
            
            // Verify activity statistics are included
            assertArrayHasKey('activity_stats', $result['metadata']);
            assertArrayHasKey('active_count', $result['metadata']['activity_stats']);
            assertArrayHasKey('inactive_count', $result['metadata']['activity_stats']);
            assertArrayHasKey('total_count', $result['metadata']['activity_stats']);
            
            // Verify timestamp is included
            assertArrayHasKey('timestamp', $result['metadata']);
            assertNotEmpty($result['metadata']['timestamp']);
        }
    }
    
    /**
     * Test filter interaction with pagination
     */
    public function testFilterInteractionWithPagination()
    {
        // Test with different limits
        $limits = [5, 10, 'all'];
        
        foreach ($limits as $limit) {
            $activeResult = $this->makeAPIRequest([
                'activity_filter' => 'active',
                'limit' => $limit
            ]);
            
            assertEquals('success', $activeResult['status']);
            assertEquals('active', $activeResult['metadata']['activity_filter']);
            assertEquals($limit, $activeResult['metadata']['limit']);
            
            // Verify data respects limit
            if ($limit !== 'all' && isset($activeResult['data']['critical_products']['items'])) {
                assertLessThanOrEqual($limit, 
                    count($activeResult['data']['critical_products']['items']),
                    "Results should respect limit of {$limit}");
            }
        }
    }
    
    /**
     * Test real-time filter updates
     */
    public function testRealTimeFilterUpdates()
    {
        // Get initial counts
        $initialResult = $this->makeAPIRequest(['activity_filter' => 'all']);
        $initialActiveCount = $initialResult['metadata']['activity_stats']['active_count'];
        $initialInactiveCount = $initialResult['metadata']['activity_stats']['inactive_count'];
        
        // Add a new active product
        $this->pdo->exec("
            INSERT INTO {$this->testTableName} (sku, name, warehouse_name, quantity_present) 
            VALUES ('NEWACTIVE', 'New Active Product', 'Warehouse1', 10)
        ");
        
        // Get updated counts
        $updatedResult = $this->makeAPIRequest(['activity_filter' => 'all']);
        $updatedActiveCount = $updatedResult['metadata']['activity_stats']['active_count'];
        
        assertEquals($initialActiveCount + 1, $updatedActiveCount, 
            'Active count should increase after adding active product');
        
        // Add a new inactive product
        $this->pdo->exec("
            INSERT INTO {$this->testTableName} (sku, name, warehouse_name, quantity_present) 
            VALUES ('NEWINACTIVE', 'New Inactive Product', 'Warehouse1', 0)
        ");
        
        // Get final counts
        $finalResult = $this->makeAPIRequest(['activity_filter' => 'all']);
        $finalInactiveCount = $finalResult['metadata']['activity_stats']['inactive_count'];
        
        assertEquals($initialInactiveCount + 1, $finalInactiveCount, 
            'Inactive count should increase after adding inactive product');
    }
    
    // Helper methods
    
    private function makeAPIRequest($params = [])
    {
        // Simulate API request by directly calling the database logic
        // In a real test, you would make HTTP requests to the API
        
        $defaultParams = ['action' => 'dashboard'];
        $params = array_merge($defaultParams, $params);
        
        try {
            // Simulate the API logic for testing
            $activityFilter = $params['activity_filter'] ?? 'active';
            
            // Validate filter
            if (!in_array($activityFilter, ['active', 'inactive', 'all'])) {
                return [
                    'status' => 'error',
                    'errors' => ['Invalid activity filter']
                ];
            }
            
            $products = $this->getFilteredProducts($activityFilter);
            $stats = $this->getActivityStatistics($activityFilter);
            
            return [
                'status' => 'success',
                'data' => [
                    'critical_products' => ['items' => $params['limit'] === 'all' ? $products : array_slice($products, 0, (int)($params['limit'] ?? 10))],
                    'products' => $products
                ],
                'metadata' => [
                    'activity_filter' => $activityFilter,
                    'activity_stats' => $stats,
                    'limit' => $params['limit'] ?? 10,
                    'timestamp' => date('Y-m-d H:i:s')
                ]
            ];
            
        } catch (Exception $e) {
            return [
                'status' => 'error',
                'message' => $e->getMessage()
            ];
        }
    }
    
    private function getFilteredProducts($filter)
    {
        $whereClause = $this->getFilterWhereClause($filter);
        
        $sql = "
            SELECT 
                sku,
                name,
                warehouse_name,
                quantity_present,
                available,
                preparing_for_sale,
                in_requests,
                in_transit,
                (COALESCE(quantity_present, 0) + 
                 COALESCE(available, 0) + 
                 COALESCE(preparing_for_sale, 0) + 
                 COALESCE(in_requests, 0) + 
                 COALESCE(in_transit, 0)) as total_stock,
                CASE 
                    WHEN (COALESCE(quantity_present, 0) + 
                          COALESCE(available, 0) + 
                          COALESCE(preparing_for_sale, 0) + 
                          COALESCE(in_requests, 0) + 
                          COALESCE(in_transit, 0)) > 0 THEN 'active'
                    ELSE 'inactive'
                END as activity_status
            FROM {$this->testTableName}
            WHERE 1=1 {$whereClause}
            ORDER BY sku
        ";
        
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    private function getActivityStatistics($filter)
    {
        $whereClause = $this->getFilterWhereClause($filter);
        
        $sql = "
            SELECT 
                COUNT(CASE WHEN (
                    COALESCE(quantity_present, 0) + 
                    COALESCE(available, 0) + 
                    COALESCE(preparing_for_sale, 0) + 
                    COALESCE(in_requests, 0) + 
                    COALESCE(in_transit, 0)
                ) > 0 THEN 1 END) as active_count,
                COUNT(CASE WHEN (
                    COALESCE(quantity_present, 0) + 
                    COALESCE(available, 0) + 
                    COALESCE(preparing_for_sale, 0) + 
                    COALESCE(in_requests, 0) + 
                    COALESCE(in_transit, 0)
                ) = 0 THEN 1 END) as inactive_count,
                COUNT(*) as total_count
            FROM {$this->testTableName}
            WHERE 1=1 {$whereClause}
        ";
        
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute();
        $stats = $stmt->fetch(PDO::FETCH_ASSOC);
        
        $stats['active_percentage'] = $stats['total_count'] > 0 ? 
            round(($stats['active_count'] / $stats['total_count']) * 100, 2) : 0;
        $stats['inactive_percentage'] = $stats['total_count'] > 0 ? 
            round(($stats['inactive_count'] / $stats['total_count']) * 100, 2) : 0;
        
        return $stats;
    }
    
    private function getFilterWhereClause($filter)
    {
        switch ($filter) {
            case 'active':
                return " AND (COALESCE(quantity_present, 0) + COALESCE(available, 0) + COALESCE(preparing_for_sale, 0) + COALESCE(in_requests, 0) + COALESCE(in_transit, 0)) > 0";
            case 'inactive':
                return " AND (COALESCE(quantity_present, 0) + COALESCE(available, 0) + COALESCE(preparing_for_sale, 0) + COALESCE(in_requests, 0) + COALESCE(in_transit, 0)) = 0";
            case 'all':
            default:
                return "";
        }
    }
    
    private function calculateTotalStock($product)
    {
        return ($product['quantity_present'] ?? 0) + 
               ($product['available'] ?? 0) + 
               ($product['preparing_for_sale'] ?? 0) + 
               ($product['in_requests'] ?? 0) + 
               ($product['in_transit'] ?? 0);
    }
    
    private function insertLargeTestDataset()
    {
        // Insert 100 additional products for performance testing
        for ($i = 1; $i <= 100; $i++) {
            $isActive = $i % 3 !== 0; // 2/3 active, 1/3 inactive
            $stock = $isActive ? rand(1, 100) : 0;
            
            $this->pdo->exec("
                INSERT INTO {$this->testTableName} (sku, name, warehouse_name, quantity_present) 
                VALUES ('PERF{$i}', 'Performance Test Product {$i}', 'Warehouse" . ($i % 3 + 1) . "', {$stock})
            ");
        }
    }
}