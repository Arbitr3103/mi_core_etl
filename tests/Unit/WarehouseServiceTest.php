<?php
/**
 * Unit Tests for WarehouseService
 * 
 * Tests warehouse dashboard data retrieval, filtering, and export functionality.
 * 
 * Requirements: 1, 2, 9, 10
 */

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../../api/classes/WarehouseService.php';

use PHPUnit\Framework\TestCase;

class WarehouseServiceTest extends TestCase {
    
    private $service;
    private $mockPdo;
    private $mockStmt;
    
    protected function setUp(): void {
        $this->mockPdo = $this->createMock(PDO::class);
        $this->mockStmt = $this->createMock(PDOStatement::class);
        $this->service = new WarehouseService($this->mockPdo);
    }
    
    /**
     * Test getDashboardData with no filters
     * Requirements: 1.1, 2.1
     */
    public function testGetDashboardDataWithNoFilters() {
        // Mock count query
        $this->mockStmt->expects($this->exactly(3))
            ->method('execute')
            ->willReturn(true);
        
        // Mock fetchColumn for count, fetchAll for items, fetch for summary
        $this->mockStmt->expects($this->once())
            ->method('fetchColumn')
            ->willReturn(10);
        
        $this->mockStmt->expects($this->once())
            ->method('fetchAll')
            ->willReturn([
                [
                    'product_id' => 1,
                    'sku' => 'SKU001',
                    'name' => 'Товар А',
                    'warehouse_name' => 'АДЫГЕЙСК_РФЦ',
                    'cluster' => 'Юг',
                    'available' => 100,
                    'reserved' => 10,
                    'preparing_for_sale' => 5,
                    'in_supply_requests' => 20,
                    'in_transit' => 30,
                    'in_inspection' => 0,
                    'returning_from_customers' => 0,
                    'expiring_soon' => 0,
                    'defective' => 0,
                    'excess_from_supply' => 0,
                    'awaiting_upd' => 0,
                    'preparing_for_removal' => 0,
                    'daily_sales_avg' => 10.00,
                    'sales_last_28_days' => 280,
                    'days_without_sales' => 0,
                    'days_of_stock' => 10.00,
                    'liquidity_status' => 'low',
                    'target_stock' => 300,
                    'replenishment_need' => 150,
                    'last_updated' => '2025-10-22 12:00:00',
                    'metrics_calculated_at' => '2025-10-22 11:00:00'
                ]
            ]);
        
        $this->mockStmt->expects($this->once())
            ->method('fetch')
            ->willReturn([
                'total_products' => 10,
                'active_products' => 8,
                'total_replenishment_need' => 500,
                'critical_count' => 2,
                'low_count' => 3,
                'normal_count' => 4,
                'excess_count' => 1
            ]);
        
        $this->mockPdo->expects($this->exactly(3))
            ->method('prepare')
            ->willReturn($this->mockStmt);
        
        $result = $this->service->getDashboardData();
        
        $this->assertTrue($result['success']);
        $this->assertArrayHasKey('data', $result);
        $this->assertArrayHasKey('warehouses', $result['data']);
        $this->assertArrayHasKey('summary', $result['data']);
        $this->assertArrayHasKey('filters_applied', $result['data']);
        $this->assertArrayHasKey('pagination', $result['data']);
        
        // Check filters applied
        $this->assertTrue($result['data']['filters_applied']['active_only']);
        
        // Check pagination
        $this->assertEquals(100, $result['data']['pagination']['limit']);
        $this->assertEquals(10, $result['data']['pagination']['total']);
    }
    
    /**
     * Test getDashboardData with warehouse filter
     * Requirements: 2.3, 9.1
     */
    public function testGetDashboardDataWithWarehouseFilter() {
        $filters = ['warehouse' => 'АДЫГЕЙСК_РФЦ'];
        
        $this->mockStmt->expects($this->exactly(3))
            ->method('execute')
            ->willReturn(true);
        
        $this->mockStmt->expects($this->once())
            ->method('fetchColumn')
            ->willReturn(5);
        
        $this->mockStmt->expects($this->once())
            ->method('fetchAll')
            ->willReturn([]);
        
        $this->mockStmt->expects($this->once())
            ->method('fetch')
            ->willReturn([
                'total_products' => 5,
                'active_products' => 5,
                'total_replenishment_need' => 200,
                'critical_count' => 1,
                'low_count' => 2,
                'normal_count' => 2,
                'excess_count' => 0
            ]);
        
        $this->mockPdo->expects($this->exactly(3))
            ->method('prepare')
            ->willReturn($this->mockStmt);
        
        $result = $this->service->getDashboardData($filters);
        
        $this->assertTrue($result['success']);
        $this->assertEquals('АДЫГЕЙСК_РФЦ', $result['data']['filters_applied']['warehouse']);
    }
    
    /**
     * Test getDashboardData with cluster filter
     * Requirements: 2.4, 9.1
     */
    public function testGetDashboardDataWithClusterFilter() {
        $filters = ['cluster' => 'Юг'];
        
        $this->mockStmt->expects($this->exactly(3))
            ->method('execute')
            ->willReturn(true);
        
        $this->mockStmt->expects($this->once())
            ->method('fetchColumn')
            ->willReturn(15);
        
        $this->mockStmt->expects($this->once())
            ->method('fetchAll')
            ->willReturn([]);
        
        $this->mockStmt->expects($this->once())
            ->method('fetch')
            ->willReturn([
                'total_products' => 15,
                'active_products' => 12,
                'total_replenishment_need' => 600,
                'critical_count' => 3,
                'low_count' => 4,
                'normal_count' => 7,
                'excess_count' => 1
            ]);
        
        $this->mockPdo->expects($this->exactly(3))
            ->method('prepare')
            ->willReturn($this->mockStmt);
        
        $result = $this->service->getDashboardData($filters);
        
        $this->assertTrue($result['success']);
        $this->assertEquals('Юг', $result['data']['filters_applied']['cluster']);
    }
    
    /**
     * Test getDashboardData with liquidity status filter
     * Requirements: 9.1
     */
    public function testGetDashboardDataWithLiquidityFilter() {
        $filters = ['liquidity_status' => 'critical'];
        
        $this->mockStmt->expects($this->exactly(3))
            ->method('execute')
            ->willReturn(true);
        
        $this->mockStmt->expects($this->once())
            ->method('fetchColumn')
            ->willReturn(3);
        
        $this->mockStmt->expects($this->once())
            ->method('fetchAll')
            ->willReturn([]);
        
        $this->mockStmt->expects($this->once())
            ->method('fetch')
            ->willReturn([
                'total_products' => 3,
                'active_products' => 3,
                'total_replenishment_need' => 450,
                'critical_count' => 3,
                'low_count' => 0,
                'normal_count' => 0,
                'excess_count' => 0
            ]);
        
        $this->mockPdo->expects($this->exactly(3))
            ->method('prepare')
            ->willReturn($this->mockStmt);
        
        $result = $this->service->getDashboardData($filters);
        
        $this->assertTrue($result['success']);
        $this->assertEquals('critical', $result['data']['filters_applied']['liquidity_status']);
    }
    
    /**
     * Test getDashboardData with active_only filter
     * Requirements: 1.1, 1.2, 1.3
     */
    public function testGetDashboardDataWithActiveOnlyFilter() {
        $filters = ['active_only' => true];
        
        $this->mockStmt->expects($this->exactly(3))
            ->method('execute')
            ->willReturn(true);
        
        $this->mockStmt->expects($this->once())
            ->method('fetchColumn')
            ->willReturn(8);
        
        $this->mockStmt->expects($this->once())
            ->method('fetchAll')
            ->willReturn([]);
        
        $this->mockStmt->expects($this->once())
            ->method('fetch')
            ->willReturn([
                'total_products' => 8,
                'active_products' => 8,
                'total_replenishment_need' => 400,
                'critical_count' => 2,
                'low_count' => 3,
                'normal_count' => 3,
                'excess_count' => 0
            ]);
        
        $this->mockPdo->expects($this->exactly(3))
            ->method('prepare')
            ->willReturn($this->mockStmt);
        
        $result = $this->service->getDashboardData($filters);
        
        $this->assertTrue($result['success']);
        $this->assertTrue($result['data']['filters_applied']['active_only']);
    }
    
    /**
     * Test getDashboardData with replenishment need filter
     * Requirements: 9.1
     */
    public function testGetDashboardDataWithReplenishmentNeedFilter() {
        $filters = ['has_replenishment_need' => true];
        
        $this->mockStmt->expects($this->exactly(3))
            ->method('execute')
            ->willReturn(true);
        
        $this->mockStmt->expects($this->once())
            ->method('fetchColumn')
            ->willReturn(6);
        
        $this->mockStmt->expects($this->once())
            ->method('fetchAll')
            ->willReturn([]);
        
        $this->mockStmt->expects($this->once())
            ->method('fetch')
            ->willReturn([
                'total_products' => 6,
                'active_products' => 6,
                'total_replenishment_need' => 900,
                'critical_count' => 2,
                'low_count' => 4,
                'normal_count' => 0,
                'excess_count' => 0
            ]);
        
        $this->mockPdo->expects($this->exactly(3))
            ->method('prepare')
            ->willReturn($this->mockStmt);
        
        $result = $this->service->getDashboardData($filters);
        
        $this->assertTrue($result['success']);
        $this->assertTrue($result['data']['filters_applied']['has_replenishment_need']);
    }
    
    /**
     * Test getDashboardData with pagination
     * Requirements: 9.2
     */
    public function testGetDashboardDataWithPagination() {
        $filters = ['limit' => 50, 'offset' => 100];
        
        $this->mockStmt->expects($this->exactly(3))
            ->method('execute')
            ->willReturn(true);
        
        $this->mockStmt->expects($this->once())
            ->method('fetchColumn')
            ->willReturn(250);
        
        $this->mockStmt->expects($this->once())
            ->method('fetchAll')
            ->willReturn([]);
        
        $this->mockStmt->expects($this->once())
            ->method('fetch')
            ->willReturn([
                'total_products' => 250,
                'active_products' => 200,
                'total_replenishment_need' => 5000,
                'critical_count' => 50,
                'low_count' => 75,
                'normal_count' => 100,
                'excess_count' => 25
            ]);
        
        $this->mockPdo->expects($this->exactly(3))
            ->method('prepare')
            ->willReturn($this->mockStmt);
        
        $result = $this->service->getDashboardData($filters);
        
        $this->assertTrue($result['success']);
        $this->assertEquals(50, $result['data']['pagination']['limit']);
        $this->assertEquals(100, $result['data']['pagination']['offset']);
        $this->assertEquals(250, $result['data']['pagination']['total']);
        $this->assertEquals(3, $result['data']['pagination']['current_page']);
        $this->assertEquals(5, $result['data']['pagination']['total_pages']);
        $this->assertTrue($result['data']['pagination']['has_next']);
        $this->assertTrue($result['data']['pagination']['has_prev']);
    }
    
    /**
     * Test getDashboardData with sorting
     * Requirements: 9.2, 9.3
     */
    public function testGetDashboardDataWithSorting() {
        $filters = ['sort_by' => 'daily_sales_avg', 'sort_order' => 'desc'];
        
        $this->mockStmt->expects($this->exactly(3))
            ->method('execute')
            ->willReturn(true);
        
        $this->mockStmt->expects($this->once())
            ->method('fetchColumn')
            ->willReturn(10);
        
        $this->mockStmt->expects($this->once())
            ->method('fetchAll')
            ->willReturn([]);
        
        $this->mockStmt->expects($this->once())
            ->method('fetch')
            ->willReturn([
                'total_products' => 10,
                'active_products' => 8,
                'total_replenishment_need' => 500,
                'critical_count' => 2,
                'low_count' => 3,
                'normal_count' => 4,
                'excess_count' => 1
            ]);
        
        $this->mockPdo->expects($this->exactly(3))
            ->method('prepare')
            ->willReturn($this->mockStmt);
        
        $result = $this->service->getDashboardData($filters);
        
        $this->assertTrue($result['success']);
        $this->assertEquals('daily_sales_avg', $result['data']['filters_applied']['sort_by']);
        $this->assertEquals('desc', $result['data']['filters_applied']['sort_order']);
    }
    
    /**
     * Test getWarehouseList
     * Requirements: 2.3, 9.1
     */
    public function testGetWarehouseList() {
        $mockWarehouses = [
            ['warehouse_name' => 'АДЫГЕЙСК_РФЦ', 'cluster' => 'Юг', 'product_count' => 50],
            ['warehouse_name' => 'Екатеринбург_РФЦ', 'cluster' => 'Урал', 'product_count' => 75],
            ['warehouse_name' => 'Москва_РФЦ', 'cluster' => 'Центр', 'product_count' => 100]
        ];
        
        $this->mockStmt->expects($this->once())
            ->method('fetchAll')
            ->willReturn($mockWarehouses);
        
        $this->mockPdo->expects($this->once())
            ->method('query')
            ->willReturn($this->mockStmt);
        
        $result = $this->service->getWarehouseList();
        
        $this->assertTrue($result['success']);
        $this->assertCount(3, $result['data']);
        $this->assertEquals('АДЫГЕЙСК_РФЦ', $result['data'][0]['warehouse_name']);
        $this->assertEquals('Юг', $result['data'][0]['cluster']);
    }
    
    /**
     * Test getClusterList
     * Requirements: 2.4, 9.1
     */
    public function testGetClusterList() {
        $mockClusters = [
            ['cluster' => 'Юг', 'warehouse_count' => 5, 'product_count' => 150],
            ['cluster' => 'Урал', 'warehouse_count' => 3, 'product_count' => 100],
            ['cluster' => 'Центр', 'warehouse_count' => 7, 'product_count' => 250]
        ];
        
        $this->mockStmt->expects($this->once())
            ->method('fetchAll')
            ->willReturn($mockClusters);
        
        $this->mockPdo->expects($this->once())
            ->method('query')
            ->willReturn($this->mockStmt);
        
        $result = $this->service->getClusterList();
        
        $this->assertTrue($result['success']);
        $this->assertCount(3, $result['data']);
        $this->assertEquals('Юг', $result['data'][0]['cluster']);
        $this->assertEquals(5, $result['data'][0]['warehouse_count']);
    }
    
    /**
     * Test exportToCSV
     * Requirements: 10.1, 10.2, 10.3, 10.4
     */
    public function testExportToCSV() {
        // Mock the getDashboardData call within exportToCSV
        $this->mockStmt->expects($this->exactly(3))
            ->method('execute')
            ->willReturn(true);
        
        $this->mockStmt->expects($this->once())
            ->method('fetchColumn')
            ->willReturn(1);
        
        $this->mockStmt->expects($this->once())
            ->method('fetchAll')
            ->willReturn([
                [
                    'product_id' => 1,
                    'sku' => 'SKU001',
                    'name' => 'Товар А',
                    'warehouse_name' => 'АДЫГЕЙСК_РФЦ',
                    'cluster' => 'Юг',
                    'available' => 100,
                    'reserved' => 10,
                    'preparing_for_sale' => 5,
                    'in_supply_requests' => 20,
                    'in_transit' => 30,
                    'in_inspection' => 0,
                    'returning_from_customers' => 0,
                    'expiring_soon' => 0,
                    'defective' => 0,
                    'excess_from_supply' => 0,
                    'awaiting_upd' => 0,
                    'preparing_for_removal' => 0,
                    'daily_sales_avg' => 10.00,
                    'sales_last_28_days' => 280,
                    'days_without_sales' => 0,
                    'days_of_stock' => 10.00,
                    'liquidity_status' => 'low',
                    'target_stock' => 300,
                    'replenishment_need' => 150,
                    'last_updated' => '2025-10-22 12:00:00',
                    'metrics_calculated_at' => '2025-10-22 11:00:00'
                ]
            ]);
        
        $this->mockStmt->expects($this->once())
            ->method('fetch')
            ->willReturn([
                'total_products' => 1,
                'active_products' => 1,
                'total_replenishment_need' => 150,
                'critical_count' => 0,
                'low_count' => 1,
                'normal_count' => 0,
                'excess_count' => 0
            ]);
        
        $this->mockPdo->expects($this->exactly(3))
            ->method('prepare')
            ->willReturn($this->mockStmt);
        
        $csv = $this->service->exportToCSV();
        
        $this->assertIsString($csv);
        $this->assertStringContainsString('Товар', $csv); // Header
        $this->assertStringContainsString('SKU001', $csv); // Data
        $this->assertStringContainsString('АДЫГЕЙСК_РФЦ', $csv);
    }
    
    /**
     * Test error handling in getDashboardData
     */
    public function testGetDashboardDataErrorHandling() {
        $this->mockPdo->expects($this->once())
            ->method('prepare')
            ->willThrowException(new Exception('Database error'));
        
        $result = $this->service->getDashboardData();
        
        $this->assertFalse($result['success']);
        $this->assertArrayHasKey('error', $result);
    }
    
    /**
     * Test error handling in getWarehouseList
     */
    public function testGetWarehouseListErrorHandling() {
        $this->mockPdo->expects($this->once())
            ->method('query')
            ->willThrowException(new Exception('Database error'));
        
        $result = $this->service->getWarehouseList();
        
        $this->assertFalse($result['success']);
        $this->assertArrayHasKey('error', $result);
    }
    
    /**
     * Test error handling in getClusterList
     */
    public function testGetClusterListErrorHandling() {
        $this->mockPdo->expects($this->once())
            ->method('query')
            ->willThrowException(new Exception('Database error'));
        
        $result = $this->service->getClusterList();
        
        $this->assertFalse($result['success']);
        $this->assertArrayHasKey('error', $result);
    }
}
