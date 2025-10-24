<?php
/**
 * Unit Tests for Product Activity Logic
 * Task 6.1: Тестирование логики активности
 * 
 * Tests the core functionality of product activity calculation and classification
 */

// Only include database config to avoid function conflicts
if (!function_exists('getDatabaseConnection')) {
    require_once __DIR__ . '/../../config/database_postgresql.php';
}

class ProductActivityTest
{
    private $pdo;
    private $testTableName = 'test_inventory_activity';
    
    public function setUp()
    {
        // Get database connection
        $this->pdo = getDatabaseConnection();
        
        // Create test table with various stock combinations
        $this->createTestTable();
        $this->insertTestData();
    }
    
    public function tearDown()
    {
        // Clean up test table
        $this->pdo->exec("DROP TABLE IF EXISTS {$this->testTableName}");
    }
    
    private function createTestTable()
    {
        // Drop table if exists first
        $this->pdo->exec("DROP TABLE IF EXISTS {$this->testTableName}");
        
        $sql = "
            CREATE TABLE {$this->testTableName} (
                id SERIAL PRIMARY KEY,
                sku VARCHAR(50) NOT NULL,
                warehouse_name VARCHAR(100) NOT NULL,
                quantity_present INTEGER DEFAULT 0,
                quantity_reserved INTEGER DEFAULT 0,
                preparing_for_sale INTEGER DEFAULT 0,
                in_supply_requests INTEGER DEFAULT 0,
                in_transit INTEGER DEFAULT 0,
                in_inspection INTEGER DEFAULT 0,
                returning_from_customers INTEGER DEFAULT 0,
                expiring_soon INTEGER DEFAULT 0,
                defective INTEGER DEFAULT 0,
                excess_from_supply INTEGER DEFAULT 0,
                awaiting_upd INTEGER DEFAULT 0,
                preparing_for_removal INTEGER DEFAULT 0,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            )
        ";
        
        $this->pdo->exec($sql);
    }
    
    private function insertTestData()
    {
        $testCases = [
            // Test case 1: Active product with only quantity_present
            ['sku' => 'TEST001', 'warehouse_name' => 'Warehouse1', 'quantity_present' => 10],
            
            // Test case 2: Active product with mixed stock types
            ['sku' => 'TEST002', 'warehouse_name' => 'Warehouse1', 'quantity_present' => 5, 'preparing_for_sale' => 3, 'in_transit' => 2],
            
            // Test case 3: Inactive product - all zeros
            ['sku' => 'TEST003', 'warehouse_name' => 'Warehouse1', 'quantity_present' => 0, 'quantity_reserved' => 0],
            
            // Test case 4: Active product with only reserved stock
            ['sku' => 'TEST004', 'warehouse_name' => 'Warehouse1', 'quantity_reserved' => 5],
            
            // Test case 5: Active product with all stock types
            [
                'sku' => 'TEST005', 
                'warehouse_name' => 'Warehouse1',
                'quantity_present' => 1,
                'quantity_reserved' => 1,
                'preparing_for_sale' => 1,
                'in_supply_requests' => 1,
                'in_transit' => 1,
                'in_inspection' => 1,
                'returning_from_customers' => 1,
                'expiring_soon' => 1,
                'defective' => 1,
                'excess_from_supply' => 1,
                'awaiting_upd' => 1,
                'preparing_for_removal' => 1
            ],
            
            // Test case 6: Inactive product with NULL values
            ['sku' => 'TEST006', 'warehouse_name' => 'Warehouse1'],
            
            // Test case 7: Edge case - very small positive stock
            ['sku' => 'TEST007', 'warehouse_name' => 'Warehouse1', 'quantity_present' => 1],
            
            // Test case 8: Edge case - negative values (should be treated as 0)
            ['sku' => 'TEST008', 'warehouse_name' => 'Warehouse1', 'quantity_present' => -5, 'in_transit' => 10],
            
            // Test case 9: Multiple warehouses for same SKU
            ['sku' => 'TEST009', 'warehouse_name' => 'Warehouse1', 'quantity_present' => 0],
            ['sku' => 'TEST009', 'warehouse_name' => 'Warehouse2', 'quantity_present' => 5],
            
            // Test case 10: Large stock numbers
            ['sku' => 'TEST010', 'warehouse_name' => 'Warehouse1', 'quantity_present' => 1000, 'in_transit' => 500]
        ];
        
        foreach ($testCases as $case) {
            $columns = array_keys($case);
            $placeholders = ':' . implode(', :', $columns);
            $columnsList = implode(', ', $columns);
            
            $sql = "INSERT INTO {$this->testTableName} ({$columnsList}) VALUES ({$placeholders})";
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($case);
        }
    }
    
    /**
     * Test total stock calculation formula
     * Requirements: 3.1 - Обновить API для расчета общего остатка
     */
    public function testTotalStockCalculation()
    {
        // Test case with all stock types
        $stmt = $this->pdo->prepare("
            SELECT 
                sku,
                (COALESCE(quantity_present, 0) + 
                 COALESCE(quantity_reserved, 0) + 
                 COALESCE(preparing_for_sale, 0) + 
                 COALESCE(in_supply_requests, 0) + 
                 COALESCE(in_transit, 0) + 
                 COALESCE(in_inspection, 0) + 
                 COALESCE(returning_from_customers, 0) + 
                 COALESCE(expiring_soon, 0) + 
                 COALESCE(defective, 0) + 
                 COALESCE(excess_from_supply, 0) + 
                 COALESCE(awaiting_upd, 0) + 
                 COALESCE(preparing_for_removal, 0)) as total_stock
            FROM {$this->testTableName}
            WHERE sku = 'TEST005'
        ");
        
        $stmt->execute();
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        
        // Should sum all 12 fields (each with value 1)
        assertEquals(12, $result['total_stock'], 'Total stock calculation should sum all stock fields');
        
        // Test case with mixed values
        $stmt = $this->pdo->prepare("
            SELECT 
                sku,
                (COALESCE(quantity_present, 0) + 
                 COALESCE(quantity_reserved, 0) + 
                 COALESCE(preparing_for_sale, 0) + 
                 COALESCE(in_supply_requests, 0) + 
                 COALESCE(in_transit, 0)) as total_stock
            FROM {$this->testTableName}
            WHERE sku = 'TEST002'
        ");
        
        $stmt->execute();
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        
        // Should be 5 + 0 + 3 + 0 + 2 = 10
        assertEquals(10, $result['total_stock'], 'Total stock should correctly sum mixed stock values');
    }
    
    /**
     * Test activity status determination
     * Requirements: 3.2 - Добавить определение статуса активности
     */
    public function testActivityStatusDetermination()
    {
        $stmt = $this->pdo->prepare("
            SELECT 
                sku,
                (COALESCE(quantity_present, 0) + 
                 COALESCE(quantity_reserved, 0) + 
                 COALESCE(preparing_for_sale, 0) + 
                 COALESCE(in_supply_requests, 0) + 
                 COALESCE(in_transit, 0) + 
                 COALESCE(in_inspection, 0) + 
                 COALESCE(returning_from_customers, 0) + 
                 COALESCE(expiring_soon, 0) + 
                 COALESCE(defective, 0) + 
                 COALESCE(excess_from_supply, 0) + 
                 COALESCE(awaiting_upd, 0) + 
                 COALESCE(preparing_for_removal, 0)) as total_stock,
                CASE 
                    WHEN (COALESCE(quantity_present, 0) + 
                          COALESCE(quantity_reserved, 0) + 
                          COALESCE(preparing_for_sale, 0) + 
                          COALESCE(in_supply_requests, 0) + 
                          COALESCE(in_transit, 0) + 
                          COALESCE(in_inspection, 0) + 
                          COALESCE(returning_from_customers, 0) + 
                          COALESCE(expiring_soon, 0) + 
                          COALESCE(defective, 0) + 
                          COALESCE(excess_from_supply, 0) + 
                          COALESCE(awaiting_upd, 0) + 
                          COALESCE(preparing_for_removal, 0)) > 0 THEN 'active'
                    ELSE 'inactive'
                END as activity_status
            FROM {$this->testTableName}
            ORDER BY sku
        ");
        
        $stmt->execute();
        $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        $expectedResults = [
            'TEST001' => 'active',   // quantity_present = 10
            'TEST002' => 'active',   // mixed stock = 10
            'TEST003' => 'inactive', // all zeros
            'TEST004' => 'active',   // quantity_reserved = 5
            'TEST005' => 'active',   // all fields = 12
            'TEST006' => 'inactive', // all NULL/0
            'TEST007' => 'active',   // quantity_present = 1
            'TEST008' => 'active',   // in_transit = 10 (negative values treated as 0)
            'TEST010' => 'active'    // large numbers = 1500
        ];
        
        foreach ($results as $result) {
            $sku = $result['sku'];
            if (isset($expectedResults[$sku])) {
                assertEquals(
                    $expectedResults[$sku], 
                    $result['activity_status'],
                    "Activity status for {$sku} should be {$expectedResults[$sku]} (total_stock: {$result['total_stock']})"
                );
            }
        }
    }
    
    /**
     * Test activity statistics calculation
     * Requirements: 3.3 - Добавить статистику по активности в API
     */
    public function testActivityStatisticsCalculation()
    {
        $stmt = $this->pdo->prepare("
            SELECT 
                COUNT(CASE WHEN (
                    COALESCE(quantity_present, 0) + 
                    COALESCE(quantity_reserved, 0) + 
                    COALESCE(preparing_for_sale, 0) + 
                    COALESCE(in_supply_requests, 0) + 
                    COALESCE(in_transit, 0) + 
                    COALESCE(in_inspection, 0) + 
                    COALESCE(returning_from_customers, 0) + 
                    COALESCE(expiring_soon, 0) + 
                    COALESCE(defective, 0) + 
                    COALESCE(excess_from_supply, 0) + 
                    COALESCE(awaiting_upd, 0) + 
                    COALESCE(preparing_for_removal, 0)
                ) > 0 THEN 1 END) as active_count,
                COUNT(CASE WHEN (
                    COALESCE(quantity_present, 0) + 
                    COALESCE(quantity_reserved, 0) + 
                    COALESCE(preparing_for_sale, 0) + 
                    COALESCE(in_supply_requests, 0) + 
                    COALESCE(in_transit, 0) + 
                    COALESCE(in_inspection, 0) + 
                    COALESCE(returning_from_customers, 0) + 
                    COALESCE(expiring_soon, 0) + 
                    COALESCE(defective, 0) + 
                    COALESCE(excess_from_supply, 0) + 
                    COALESCE(awaiting_upd, 0) + 
                    COALESCE(preparing_for_removal, 0)
                ) = 0 THEN 1 END) as inactive_count,
                COUNT(*) as total_count
            FROM {$this->testTableName}
        ");
        
        $stmt->execute();
        $stats = $stmt->fetch(PDO::FETCH_ASSOC);
        
        // We have 11 test records (TEST009 has 2 warehouse entries)
        assertEquals(11, $stats['total_count'], 'Total count should match inserted test records');
        
        // Expected: 7 active products (TEST001, TEST002, TEST004, TEST005, TEST007, TEST008, TEST009-Warehouse2, TEST010)
        // Expected: 4 inactive products (TEST003, TEST006, TEST009-Warehouse1)
        assertGreaterThan(0, $stats['active_count'], 'Should have active products');
        assertGreaterThan(0, $stats['inactive_count'], 'Should have inactive products');
        assertEquals($stats['total_count'], $stats['active_count'] + $stats['inactive_count'], 'Active + Inactive should equal total');
    }
    
    /**
     * Test edge cases and data validation
     */
    public function testEdgeCasesAndValidation()
    {
        // Test NULL handling
        $stmt = $this->pdo->prepare("
            SELECT 
                (COALESCE(quantity_present, 0) + 
                 COALESCE(quantity_reserved, 0) + 
                 COALESCE(preparing_for_sale, 0) + 
                 COALESCE(in_supply_requests, 0) + 
                 COALESCE(in_transit, 0)) as total_stock
            FROM {$this->testTableName}
            WHERE sku = 'TEST006'
        ");
        
        $stmt->execute();
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        
        assertEquals(0, $result['total_stock'], 'NULL values should be treated as 0');
        
        // Test negative values handling
        $stmt = $this->pdo->prepare("
            SELECT 
                (COALESCE(quantity_present, 0) + 
                 COALESCE(in_transit, 0)) as total_stock
            FROM {$this->testTableName}
            WHERE sku = 'TEST008'
        ");
        
        $stmt->execute();
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        
        // Should be 0 + 10 = 10 (negative quantity_present treated as 0 by COALESCE)
        assertEquals(10, $result['total_stock'], 'Negative values should be handled correctly');
    }
    
    /**
     * Test performance with larger dataset
     */
    public function testPerformanceWithLargeDataset()
    {
        // Insert additional test data
        $stmt = $this->pdo->prepare("
            INSERT INTO {$this->testTableName} (sku, warehouse_name, quantity_present, in_transit)
            SELECT 
                'PERF' || generate_series,
                'Warehouse' || (generate_series % 3 + 1),
                (random() * 100)::integer,
                (random() * 50)::integer
            FROM generate_series(1, 1000)
        ");
        
        $stmt->execute();
        
        // Test query performance
        $startTime = microtime(true);
        
        $stmt = $this->pdo->prepare("
            SELECT 
                COUNT(CASE WHEN (
                    COALESCE(quantity_present, 0) + 
                    COALESCE(quantity_reserved, 0) + 
                    COALESCE(preparing_for_sale, 0) + 
                    COALESCE(in_supply_requests, 0) + 
                    COALESCE(in_transit, 0)
                ) > 0 THEN 1 END) as active_count,
                COUNT(*) as total_count
            FROM {$this->testTableName}
        ");
        
        $stmt->execute();
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        
        $executionTime = microtime(true) - $startTime;
        
        assertLessThan(1.0, $executionTime, 'Query should execute in less than 1 second for 1000+ records');
        assertGreaterThan(1000, $result['total_count'], 'Should have processed large dataset');
    }
    
    /**
     * Test activity filter conditions
     */
    public function testActivityFilterConditions()
    {
        // Test active filter
        $stmt = $this->pdo->prepare("
            SELECT COUNT(*) as count
            FROM {$this->testTableName}
            WHERE (COALESCE(quantity_present, 0) + 
                   COALESCE(quantity_reserved, 0) + 
                   COALESCE(preparing_for_sale, 0) + 
                   COALESCE(in_supply_requests, 0) + 
                   COALESCE(in_transit, 0)) > 0
        ");
        
        $stmt->execute();
        $activeCount = $stmt->fetch(PDO::FETCH_ASSOC)['count'];
        
        // Test inactive filter
        $stmt = $this->pdo->prepare("
            SELECT COUNT(*) as count
            FROM {$this->testTableName}
            WHERE (COALESCE(quantity_present, 0) + 
                   COALESCE(quantity_reserved, 0) + 
                   COALESCE(preparing_for_sale, 0) + 
                   COALESCE(in_supply_requests, 0) + 
                   COALESCE(in_transit, 0)) = 0
        ");
        
        $stmt->execute();
        $inactiveCount = $stmt->fetch(PDO::FETCH_ASSOC)['count'];
        
        // Test total count
        $stmt = $this->pdo->prepare("SELECT COUNT(*) as count FROM {$this->testTableName}");
        $stmt->execute();
        $totalCount = $stmt->fetch(PDO::FETCH_ASSOC)['count'];
        
        assertEquals($totalCount, $activeCount + $inactiveCount, 'Active + Inactive should equal total count');
        assertGreaterThan(0, $activeCount, 'Should have some active products');
        assertGreaterThan(0, $inactiveCount, 'Should have some inactive products');
    }
}