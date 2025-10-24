<?php
/**
 * Unit Tests for Warehouse Analysis Logic
 * Task 6.2: Тестирование анализа по складам
 * 
 * Tests warehouse grouping, critical level calculations, and replenishment priorities
 */

// Only include database config to avoid function conflicts
if (!function_exists('getDatabaseConnection')) {
    require_once __DIR__ . '/../../config/database_postgresql.php';
}

class WarehouseAnalysisTest
{
    private $pdo;
    private $testInventoryTable = 'test_inventory_warehouse';
    private $testSalesTable = 'test_warehouse_sales_metrics';
    
    public function setUp()
    {
        $this->pdo = getDatabaseConnection();
        $this->createTestTables();
        $this->insertTestData();
    }
    
    public function tearDown()
    {
        $this->pdo->exec("DROP TABLE IF EXISTS {$this->testInventoryTable}");
        $this->pdo->exec("DROP TABLE IF EXISTS {$this->testSalesTable}");
    }
    
    private function createTestTables()
    {
        // Create test inventory table
        $inventorySql = "
            CREATE TABLE {$this->testInventoryTable} (
                id SERIAL PRIMARY KEY,
                product_id INTEGER NOT NULL,
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
        
        // Create test sales metrics table
        $salesSql = "
            CREATE TABLE {$this->testSalesTable} (
                id SERIAL PRIMARY KEY,
                product_id INTEGER NOT NULL,
                warehouse_name VARCHAR(100) NOT NULL,
                sales_last_28_days INTEGER DEFAULT 0,
                daily_sales_avg DECIMAL(10,2) DEFAULT 0,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            )
        ";
        
        $this->pdo->exec($inventorySql);
        $this->pdo->exec($salesSql);
    }
    
    private function insertTestData()
    {
        // Test data for different warehouse scenarios
        $inventoryData = [
            // Warehouse A - Critical situation
            ['product_id' => 1, 'sku' => 'SKU001', 'warehouse_name' => 'Warehouse_A', 'quantity_present' => 2, 'in_transit' => 1], // 3 total - critical
            ['product_id' => 2, 'sku' => 'SKU002', 'warehouse_name' => 'Warehouse_A', 'quantity_present' => 5, 'preparing_for_sale' => 2], // 7 total - critical
            ['product_id' => 3, 'sku' => 'SKU003', 'warehouse_name' => 'Warehouse_A', 'quantity_present' => 15], // 15 total - low
            
            // Warehouse B - Normal situation
            ['product_id' => 4, 'sku' => 'SKU004', 'warehouse_name' => 'Warehouse_B', 'quantity_present' => 50], // 50 total - normal
            ['product_id' => 5, 'sku' => 'SKU005', 'warehouse_name' => 'Warehouse_B', 'quantity_present' => 30, 'in_transit' => 20], // 50 total - normal
            ['product_id' => 6, 'sku' => 'SKU006', 'warehouse_name' => 'Warehouse_B', 'quantity_present' => 25], // 25 total - normal
            
            // Warehouse C - Excess situation
            ['product_id' => 7, 'sku' => 'SKU007', 'warehouse_name' => 'Warehouse_C', 'quantity_present' => 150], // 150 total - excess
            ['product_id' => 8, 'sku' => 'SKU008', 'warehouse_name' => 'Warehouse_C', 'quantity_present' => 200, 'in_transit' => 50], // 250 total - excess
            ['product_id' => 9, 'sku' => 'SKU009', 'warehouse_name' => 'Warehouse_C', 'quantity_present' => 80], // 80 total - normal
            
            // Warehouse D - Mixed situation
            ['product_id' => 10, 'sku' => 'SKU010', 'warehouse_name' => 'Warehouse_D', 'quantity_present' => 0], // 0 total - inactive
            ['product_id' => 11, 'sku' => 'SKU011', 'warehouse_name' => 'Warehouse_D', 'quantity_present' => 3], // 3 total - critical
            ['product_id' => 12, 'sku' => 'SKU012', 'warehouse_name' => 'Warehouse_D', 'quantity_present' => 120], // 120 total - excess
        ];
        
        // Sales data for replenishment calculations
        $salesData = [
            // High sales products (need frequent replenishment)
            ['product_id' => 1, 'warehouse_name' => 'Warehouse_A', 'sales_last_28_days' => 56, 'daily_sales_avg' => 2.0], // 3 stock / 2 daily = 1.5 days
            ['product_id' => 2, 'warehouse_name' => 'Warehouse_A', 'sales_last_28_days' => 42, 'daily_sales_avg' => 1.5], // 7 stock / 1.5 daily = 4.7 days
            ['product_id' => 3, 'warehouse_name' => 'Warehouse_A', 'sales_last_28_days' => 28, 'daily_sales_avg' => 1.0], // 15 stock / 1 daily = 15 days
            
            // Medium sales products
            ['product_id' => 4, 'warehouse_name' => 'Warehouse_B', 'sales_last_28_days' => 28, 'daily_sales_avg' => 1.0], // 50 stock / 1 daily = 50 days
            ['product_id' => 5, 'warehouse_name' => 'Warehouse_B', 'sales_last_28_days' => 14, 'daily_sales_avg' => 0.5], // 50 stock / 0.5 daily = 100 days
            ['product_id' => 6, 'warehouse_name' => 'Warehouse_B', 'sales_last_28_days' => 21, 'daily_sales_avg' => 0.75], // 25 stock / 0.75 daily = 33 days
            
            // Low sales products (excess stock)
            ['product_id' => 7, 'warehouse_name' => 'Warehouse_C', 'sales_last_28_days' => 7, 'daily_sales_avg' => 0.25], // 150 stock / 0.25 daily = 600 days
            ['product_id' => 8, 'warehouse_name' => 'Warehouse_C', 'sales_last_28_days' => 14, 'daily_sales_avg' => 0.5], // 250 stock / 0.5 daily = 500 days
            ['product_id' => 9, 'warehouse_name' => 'Warehouse_C', 'sales_last_28_days' => 28, 'daily_sales_avg' => 1.0], // 80 stock / 1 daily = 80 days
            
            // Mixed sales
            ['product_id' => 10, 'warehouse_name' => 'Warehouse_D', 'sales_last_28_days' => 14, 'daily_sales_avg' => 0.5], // 0 stock - out of stock
            ['product_id' => 11, 'warehouse_name' => 'Warehouse_D', 'sales_last_28_days' => 42, 'daily_sales_avg' => 1.5], // 3 stock / 1.5 daily = 2 days
            ['product_id' => 12, 'warehouse_name' => 'Warehouse_D', 'sales_last_28_days' => 7, 'daily_sales_avg' => 0.25], // 120 stock / 0.25 daily = 480 days
        ];
        
        // Insert inventory data
        foreach ($inventoryData as $item) {
            $columns = array_keys($item);
            $placeholders = ':' . implode(', :', $columns);
            $columnsList = implode(', ', $columns);
            
            $sql = "INSERT INTO {$this->testInventoryTable} ({$columnsList}) VALUES ({$placeholders})";
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($item);
        }
        
        // Insert sales data
        foreach ($salesData as $item) {
            $columns = array_keys($item);
            $placeholders = ':' . implode(', :', $columns);
            $columnsList = implode(', ', $columns);
            
            $sql = "INSERT INTO {$this->testSalesTable} ({$columnsList}) VALUES ({$placeholders})";
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($item);
        }
    }
    
    /**
     * Test warehouse data grouping
     * Requirements: 2.1 - Проанализировать структуру данных продаж и создать базовые запросы
     */
    public function testWarehouseDataGrouping()
    {
        $sql = "
            SELECT 
                i.warehouse_name,
                COUNT(DISTINCT i.product_id) as total_products,
                SUM(COALESCE(i.quantity_present, 0) + 
                    COALESCE(i.quantity_reserved, 0) + 
                    COALESCE(i.preparing_for_sale, 0) + 
                    COALESCE(i.in_supply_requests, 0) + 
                    COALESCE(i.in_transit, 0)) as total_stock,
                COUNT(CASE WHEN (COALESCE(i.quantity_present, 0) + 
                                COALESCE(i.quantity_reserved, 0) + 
                                COALESCE(i.preparing_for_sale, 0) + 
                                COALESCE(i.in_supply_requests, 0) + 
                                COALESCE(i.in_transit, 0)) > 0 THEN 1 END) as active_products,
                COUNT(CASE WHEN (COALESCE(i.quantity_present, 0) + 
                                COALESCE(i.quantity_reserved, 0) + 
                                COALESCE(i.preparing_for_sale, 0) + 
                                COALESCE(i.in_supply_requests, 0) + 
                                COALESCE(i.in_transit, 0)) = 0 THEN 1 END) as inactive_products
            FROM {$this->testInventoryTable} i
            GROUP BY i.warehouse_name
            ORDER BY i.warehouse_name
        ";
        
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute();
        $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        assertCount(4, $results, 'Should have 4 warehouses');
        
        // Verify Warehouse_A data
        $warehouseA = array_filter($results, function($w) { return $w['warehouse_name'] === 'Warehouse_A'; });
        $warehouseA = reset($warehouseA);
        
        assertEquals(3, $warehouseA['total_products'], 'Warehouse_A should have 3 products');
        assertEquals(25, $warehouseA['total_stock'], 'Warehouse_A should have total stock of 25 (3+7+15)');
        assertEquals(3, $warehouseA['active_products'], 'Warehouse_A should have 3 active products');
        assertEquals(0, $warehouseA['inactive_products'], 'Warehouse_A should have 0 inactive products');
    }
    
    /**
     * Test critical level calculations
     * Requirements: 2.2, 2.3 - Реализовать анализ продаж и определение критических складов
     */
    public function testCriticalLevelCalculations()
    {
        $sql = "
            SELECT 
                i.warehouse_name,
                i.product_id,
                (COALESCE(i.quantity_present, 0) + 
                 COALESCE(i.quantity_reserved, 0) + 
                 COALESCE(i.preparing_for_sale, 0) + 
                 COALESCE(i.in_supply_requests, 0) + 
                 COALESCE(i.in_transit, 0)) as current_stock,
                wsm.daily_sales_avg,
                CASE 
                    WHEN wsm.daily_sales_avg > 0 THEN 
                        (COALESCE(i.quantity_present, 0) + 
                         COALESCE(i.quantity_reserved, 0) + 
                         COALESCE(i.preparing_for_sale, 0) + 
                         COALESCE(i.in_supply_requests, 0) + 
                         COALESCE(i.in_transit, 0)) / wsm.daily_sales_avg
                    ELSE NULL
                END as days_of_stock,
                CASE 
                    WHEN wsm.daily_sales_avg = 0 THEN 'no_sales'
                    WHEN (COALESCE(i.quantity_present, 0) + 
                          COALESCE(i.quantity_reserved, 0) + 
                          COALESCE(i.preparing_for_sale, 0) + 
                          COALESCE(i.in_supply_requests, 0) + 
                          COALESCE(i.in_transit, 0)) = 0 THEN 'out_of_stock'
                    WHEN (COALESCE(i.quantity_present, 0) + 
                          COALESCE(i.quantity_reserved, 0) + 
                          COALESCE(i.preparing_for_sale, 0) + 
                          COALESCE(i.in_supply_requests, 0) + 
                          COALESCE(i.in_transit, 0)) < wsm.daily_sales_avg * 7 THEN 'critical'
                    WHEN (COALESCE(i.quantity_present, 0) + 
                          COALESCE(i.quantity_reserved, 0) + 
                          COALESCE(i.preparing_for_sale, 0) + 
                          COALESCE(i.in_supply_requests, 0) + 
                          COALESCE(i.in_transit, 0)) < wsm.daily_sales_avg * 30 THEN 'low'
                    WHEN (COALESCE(i.quantity_present, 0) + 
                          COALESCE(i.quantity_reserved, 0) + 
                          COALESCE(i.preparing_for_sale, 0) + 
                          COALESCE(i.in_supply_requests, 0) + 
                          COALESCE(i.in_transit, 0)) > wsm.daily_sales_avg * 60 THEN 'excess'
                    ELSE 'normal'
                END as stock_level
            FROM {$this->testInventoryTable} i
            LEFT JOIN {$this->testSalesTable} wsm ON i.product_id = wsm.product_id 
                AND i.warehouse_name = wsm.warehouse_name
            ORDER BY i.warehouse_name, i.product_id
        ";
        
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute();
        $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Test specific critical cases
        $criticalProducts = array_filter($results, function($r) { return $r['stock_level'] === 'critical'; });
        assertGreaterThan(0, count($criticalProducts), 'Should have critical products');
        
        // Test product 1 (3 stock, 2 daily sales = 1.5 days - critical)
        $product1 = array_filter($results, function($r) { return $r['product_id'] == 1; });
        $product1 = reset($product1);
        assertEquals('critical', $product1['stock_level'], 'Product 1 should be critical (1.5 days of stock)');
        assertEquals(1.5, round($product1['days_of_stock'], 1), 'Product 1 should have 1.5 days of stock');
        
        // Test excess products
        $excessProducts = array_filter($results, function($r) { return $r['stock_level'] === 'excess'; });
        assertGreaterThan(0, count($excessProducts), 'Should have excess products');
        
        // Test out of stock
        $outOfStock = array_filter($results, function($r) { return $r['stock_level'] === 'out_of_stock'; });
        assertGreaterThan(0, count($outOfStock), 'Should have out of stock products');
    }
    
    /**
     * Test replenishment priority calculations
     * Requirements: 2.4 - Создать систему рекомендаций по пополнению
     */
    public function testReplenishmentPriorityCalculations()
    {
        $sql = "
            SELECT 
                i.warehouse_name,
                i.product_id,
                (COALESCE(i.quantity_present, 0) + 
                 COALESCE(i.quantity_reserved, 0) + 
                 COALESCE(i.preparing_for_sale, 0) + 
                 COALESCE(i.in_supply_requests, 0) + 
                 COALESCE(i.in_transit, 0)) as current_stock,
                wsm.daily_sales_avg,
                wsm.sales_last_28_days,
                -- Recommended order quantity (60 days of sales minus current stock)
                GREATEST(0, ROUND(wsm.daily_sales_avg * 60, 0) - (
                    COALESCE(i.quantity_present, 0) + 
                    COALESCE(i.quantity_reserved, 0) + 
                    COALESCE(i.preparing_for_sale, 0) + 
                    COALESCE(i.in_supply_requests, 0) + 
                    COALESCE(i.in_transit, 0)
                )) as recommended_qty,
                -- Priority score based on urgency
                CASE 
                    WHEN wsm.daily_sales_avg = 0 THEN 0
                    WHEN (COALESCE(i.quantity_present, 0) + 
                          COALESCE(i.quantity_reserved, 0) + 
                          COALESCE(i.preparing_for_sale, 0) + 
                          COALESCE(i.in_supply_requests, 0) + 
                          COALESCE(i.in_transit, 0)) = 0 THEN 100
                    WHEN (COALESCE(i.quantity_present, 0) + 
                          COALESCE(i.quantity_reserved, 0) + 
                          COALESCE(i.preparing_for_sale, 0) + 
                          COALESCE(i.in_supply_requests, 0) + 
                          COALESCE(i.in_transit, 0)) < wsm.daily_sales_avg * 3 THEN 90
                    WHEN (COALESCE(i.quantity_present, 0) + 
                          COALESCE(i.quantity_reserved, 0) + 
                          COALESCE(i.preparing_for_sale, 0) + 
                          COALESCE(i.in_supply_requests, 0) + 
                          COALESCE(i.in_transit, 0)) < wsm.daily_sales_avg * 7 THEN 80
                    WHEN (COALESCE(i.quantity_present, 0) + 
                          COALESCE(i.quantity_reserved, 0) + 
                          COALESCE(i.preparing_for_sale, 0) + 
                          COALESCE(i.in_supply_requests, 0) + 
                          COALESCE(i.in_transit, 0)) < wsm.daily_sales_avg * 14 THEN 60
                    ELSE 20
                END as priority_score
            FROM {$this->testInventoryTable} i
            INNER JOIN {$this->testSalesTable} wsm ON i.product_id = wsm.product_id 
                AND i.warehouse_name = wsm.warehouse_name
            WHERE wsm.daily_sales_avg > 0
            ORDER BY priority_score DESC, wsm.daily_sales_avg DESC
        ";
        
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute();
        $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Test that out of stock has highest priority
        $outOfStock = array_filter($results, function($r) { return $r['current_stock'] == 0; });
        if (!empty($outOfStock)) {
            $outOfStock = reset($outOfStock);
            assertEquals(100, $outOfStock['priority_score'], 'Out of stock products should have highest priority (100)');
        }
        
        // Test that critical products have high priority
        $criticalProducts = array_filter($results, function($r) { 
            return $r['priority_score'] >= 80 && $r['current_stock'] > 0; 
        });
        assertGreaterThan(0, count($criticalProducts), 'Should have critical products with high priority');
        
        // Test recommended quantities calculation
        foreach ($results as $result) {
            $expectedRecommended = max(0, round($result['daily_sales_avg'] * 60) - $result['current_stock']);
            assertEquals(
                $expectedRecommended, 
                $result['recommended_qty'],
                "Recommended quantity should be calculated correctly for product {$result['product_id']}"
            );
        }
        
        // Test that results are ordered by priority
        $priorities = array_column($results, 'priority_score');
        $sortedPriorities = $priorities;
        rsort($sortedPriorities);
        assertEquals($sortedPriorities, $priorities, 'Results should be ordered by priority score descending');
    }
    
    /**
     * Test warehouse status determination
     * Requirements: 2.3 - Реализовать определение критических складов на основе продаж
     */
    public function testWarehouseStatusDetermination()
    {
        $sql = "
            SELECT 
                i.warehouse_name,
                COUNT(DISTINCT i.product_id) as total_products,
                COUNT(CASE WHEN (
                    COALESCE(i.quantity_present, 0) + 
                    COALESCE(i.quantity_reserved, 0) + 
                    COALESCE(i.preparing_for_sale, 0) + 
                    COALESCE(i.in_supply_requests, 0) + 
                    COALESCE(i.in_transit, 0)
                ) < wsm.daily_sales_avg * 7 THEN 1 END) as urgent_replenishment,
                COUNT(CASE WHEN (
                    COALESCE(i.quantity_present, 0) + 
                    COALESCE(i.quantity_reserved, 0) + 
                    COALESCE(i.preparing_for_sale, 0) + 
                    COALESCE(i.in_supply_requests, 0) + 
                    COALESCE(i.in_transit, 0)
                ) BETWEEN wsm.daily_sales_avg * 7 AND wsm.daily_sales_avg * 30 THEN 1 END) as planned_replenishment,
                COUNT(CASE WHEN (
                    COALESCE(i.quantity_present, 0) + 
                    COALESCE(i.quantity_reserved, 0) + 
                    COALESCE(i.preparing_for_sale, 0) + 
                    COALESCE(i.in_supply_requests, 0) + 
                    COALESCE(i.in_transit, 0)
                ) > wsm.daily_sales_avg * 60 THEN 1 END) as excess_products,
                CASE 
                    WHEN COUNT(CASE WHEN (
                        COALESCE(i.quantity_present, 0) + 
                        COALESCE(i.quantity_reserved, 0) + 
                        COALESCE(i.preparing_for_sale, 0) + 
                        COALESCE(i.in_supply_requests, 0) + 
                        COALESCE(i.in_transit, 0)
                    ) < wsm.daily_sales_avg * 7 THEN 1 END) > 0 THEN 'critical'
                    WHEN COUNT(CASE WHEN (
                        COALESCE(i.quantity_present, 0) + 
                        COALESCE(i.quantity_reserved, 0) + 
                        COALESCE(i.preparing_for_sale, 0) + 
                        COALESCE(i.in_supply_requests, 0) + 
                        COALESCE(i.in_transit, 0)
                    ) < wsm.daily_sales_avg * 14 THEN 1 END) > 2 THEN 'warning'
                    WHEN COUNT(CASE WHEN (
                        COALESCE(i.quantity_present, 0) + 
                        COALESCE(i.quantity_reserved, 0) + 
                        COALESCE(i.preparing_for_sale, 0) + 
                        COALESCE(i.in_supply_requests, 0) + 
                        COALESCE(i.in_transit, 0)
                    ) < wsm.daily_sales_avg * 30 THEN 1 END) > 3 THEN 'normal'
                    ELSE 'excess'
                END as warehouse_status
            FROM {$this->testInventoryTable} i
            LEFT JOIN {$this->testSalesTable} wsm ON i.product_id = wsm.product_id 
                AND i.warehouse_name = wsm.warehouse_name
            GROUP BY i.warehouse_name
            ORDER BY 
                CASE 
                    WHEN COUNT(CASE WHEN (
                        COALESCE(i.quantity_present, 0) + 
                        COALESCE(i.quantity_reserved, 0) + 
                        COALESCE(i.preparing_for_sale, 0) + 
                        COALESCE(i.in_supply_requests, 0) + 
                        COALESCE(i.in_transit, 0)
                    ) < wsm.daily_sales_avg * 7 THEN 1 END) > 0 THEN 1
                    ELSE 2
                END,
                urgent_replenishment DESC
        ";
        
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute();
        $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        assertCount(4, $results, 'Should have 4 warehouses');
        
        // Find critical warehouses
        $criticalWarehouses = array_filter($results, function($w) { return $w['warehouse_status'] === 'critical'; });
        assertGreaterThan(0, count($criticalWarehouses), 'Should have at least one critical warehouse');
        
        // Verify that critical warehouses have urgent replenishment needs
        foreach ($criticalWarehouses as $warehouse) {
            assertGreaterThan(0, $warehouse['urgent_replenishment'], 
                "Critical warehouse {$warehouse['warehouse_name']} should have urgent replenishment needs");
        }
        
        // Test that results are ordered by criticality
        $firstWarehouse = $results[0];
        if ($firstWarehouse['warehouse_status'] === 'critical') {
            assertGreaterThan(0, $firstWarehouse['urgent_replenishment'], 
                'First warehouse should be most critical');
        }
    }
    
    /**
     * Test warehouse report accuracy
     * Requirements: 2.5 - Создать отчет по состоянию складов с рекомендациями
     */
    public function testWarehouseReportAccuracy()
    {
        $sql = "
            SELECT 
                i.warehouse_name,
                COUNT(DISTINCT i.product_id) as total_products,
                SUM(COALESCE(i.quantity_present, 0) + 
                    COALESCE(i.quantity_reserved, 0) + 
                    COALESCE(i.preparing_for_sale, 0) + 
                    COALESCE(i.in_supply_requests, 0) + 
                    COALESCE(i.in_transit, 0)) as total_stock,
                COALESCE(SUM(wsm.sales_last_28_days), 0) as total_sales_28d,
                COALESCE(AVG(wsm.daily_sales_avg), 0) as avg_daily_sales,
                SUM(GREATEST(0, ROUND(wsm.daily_sales_avg * 60, 0) - (
                    COALESCE(i.quantity_present, 0) + 
                    COALESCE(i.quantity_reserved, 0) + 
                    COALESCE(i.preparing_for_sale, 0) + 
                    COALESCE(i.in_supply_requests, 0) + 
                    COALESCE(i.in_transit, 0)
                ))) as total_recommended_order,
                -- Days of stock calculation
                CASE 
                    WHEN AVG(wsm.daily_sales_avg) > 0 THEN 
                        SUM(COALESCE(i.quantity_present, 0) + 
                            COALESCE(i.quantity_reserved, 0) + 
                            COALESCE(i.preparing_for_sale, 0) + 
                            COALESCE(i.in_supply_requests, 0) + 
                            COALESCE(i.in_transit, 0)) / AVG(wsm.daily_sales_avg)
                    ELSE NULL
                END as avg_days_of_stock
            FROM {$this->testInventoryTable} i
            LEFT JOIN {$this->testSalesTable} wsm ON i.product_id = wsm.product_id 
                AND i.warehouse_name = wsm.warehouse_name
            GROUP BY i.warehouse_name
            ORDER BY total_recommended_order DESC
        ";
        
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute();
        $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Verify data consistency
        foreach ($results as $warehouse) {
            // Total products should be positive
            assertGreaterThan(0, $warehouse['total_products'], 
                "Warehouse {$warehouse['warehouse_name']} should have products");
            
            // Total stock should be non-negative
            assertGreaterThanOrEqual(0, $warehouse['total_stock'], 
                "Warehouse {$warehouse['warehouse_name']} total stock should be non-negative");
            
            // Sales data should be consistent
            if ($warehouse['total_sales_28d'] > 0) {
                assertGreaterThan(0, $warehouse['avg_daily_sales'], 
                    "Warehouse {$warehouse['warehouse_name']} with sales should have positive daily average");
            }
            
            // Days of stock calculation
            if ($warehouse['avg_daily_sales'] > 0 && $warehouse['total_stock'] > 0) {
                $expectedDays = $warehouse['total_stock'] / $warehouse['avg_daily_sales'];
                assertEquals(
                    round($expectedDays, 2), 
                    round($warehouse['avg_days_of_stock'], 2),
                    "Days of stock calculation should be accurate for {$warehouse['warehouse_name']}"
                );
            }
        }
        
        // Test that warehouses with higher recommended orders appear first
        if (count($results) > 1) {
            assertGreaterThanOrEqual(
                $results[1]['total_recommended_order'],
                $results[0]['total_recommended_order'],
                'Warehouses should be ordered by recommended order quantity descending'
            );
        }
    }
    
    /**
     * Test performance with multiple warehouses
     */
    public function testPerformanceWithMultipleWarehouses()
    {
        // Add more test data for performance testing
        $additionalData = [];
        for ($i = 1; $i <= 100; $i++) {
            $additionalData[] = [
                'product_id' => 1000 + $i,
                'sku' => 'PERF' . str_pad($i, 3, '0', STR_PAD_LEFT),
                'warehouse_name' => 'Warehouse_' . chr(65 + ($i % 10)), // A-J
                'quantity_present' => rand(0, 200),
                'in_transit' => rand(0, 50)
            ];
        }
        
        // Insert additional inventory data
        foreach ($additionalData as $item) {
            $columns = array_keys($item);
            $placeholders = ':' . implode(', :', $columns);
            $columnsList = implode(', ', $columns);
            
            $sql = "INSERT INTO {$this->testInventoryTable} ({$columnsList}) VALUES ({$placeholders})";
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($item);
        }
        
        // Test query performance
        $startTime = microtime(true);
        
        $sql = "
            SELECT 
                i.warehouse_name,
                COUNT(DISTINCT i.product_id) as total_products,
                SUM(COALESCE(i.quantity_present, 0) + COALESCE(i.in_transit, 0)) as total_stock
            FROM {$this->testInventoryTable} i
            GROUP BY i.warehouse_name
            ORDER BY total_stock DESC
        ";
        
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute();
        $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        $executionTime = microtime(true) - $startTime;
        
        assertLessThan(1.0, $executionTime, 'Warehouse grouping query should execute quickly');
        assertGreaterThan(4, count($results), 'Should have multiple warehouses after adding test data');
    }
}