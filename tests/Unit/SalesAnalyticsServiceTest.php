<?php
/**
 * Unit Tests for SalesAnalyticsService
 * 
 * Tests marketplace comparison calculations, top products ranking logic,
 * and sales dynamics aggregation functionality.
 */

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../../api/analytics/SalesAnalyticsService.php';

use PHPUnit\Framework\TestCase;

class SalesAnalyticsServiceTest extends TestCase {
    
    private $service;
    private $mockPdo;
    private $mockStmt;
    
    protected function setUp(): void {
        // Create mock PDO and statement objects
        $this->mockPdo = $this->createMock(PDO::class);
        $this->mockStmt = $this->createMock(PDOStatement::class);
        
        // Create service instance
        $this->service = new SalesAnalyticsService(1);
        
        // Use reflection to inject mock PDO
        $reflection = new ReflectionClass($this->service);
        $pdoProperty = $reflection->getProperty('pdo');
        $pdoProperty->setAccessible(true);
        $pdoProperty->setValue($this->service, $this->mockPdo);
    }
    
    /**
     * Test marketplace comparison calculations
     * Requirements: 1.1, 2.1, 3.1
     */
    public function testGetMarketplaceComparison() {
        // Mock data representing Ozon and WB marketplace results
        $mockData = [
            [
                'marketplace' => 'OZON',
                'marketplace_name' => 'Ozon',
                'total_orders' => 150,
                'total_quantity' => 300,
                'total_revenue' => 75000.00,
                'average_check' => 500.00,
                'unique_products' => 25
            ],
            [
                'marketplace' => 'WB',
                'marketplace_name' => 'Wildberries',
                'total_orders' => 100,
                'total_quantity' => 200,
                'total_revenue' => 50000.00,
                'average_check' => 500.00,
                'unique_products' => 20
            ]
        ];
        
        // Configure mock statement
        $this->mockStmt->expects($this->once())
            ->method('execute')
            ->willReturn(true);
        
        $this->mockStmt->expects($this->once())
            ->method('fetchAll')
            ->willReturn($mockData);
        
        // Configure mock PDO
        $this->mockPdo->expects($this->once())
            ->method('prepare')
            ->willReturn($this->mockStmt);
        
        // Execute test
        $result = $this->service->getMarketplaceComparison('2024-01-01', '2024-01-31');
        
        // Assertions
        $this->assertIsArray($result);
        $this->assertArrayHasKey('period', $result);
        $this->assertArrayHasKey('summary', $result);
        $this->assertArrayHasKey('marketplaces', $result);
        
        // Check period
        $this->assertEquals('2024-01-01', $result['period']['date_from']);
        $this->assertEquals('2024-01-31', $result['period']['date_to']);
        
        // Check summary calculations
        $this->assertEquals(125000.00, $result['summary']['total_revenue']);
        $this->assertEquals(250, $result['summary']['total_orders']);
        $this->assertEquals(2, $result['summary']['marketplaces_count']);
        
        // Check marketplace data and percentage calculations
        $this->assertCount(2, $result['marketplaces']);
        
        $ozonData = $result['marketplaces'][0];
        $this->assertEquals('ozon', $ozonData['marketplace']);
        $this->assertEquals(150, $ozonData['total_orders']);
        $this->assertEquals(75000.00, $ozonData['total_revenue']);
        $this->assertEquals(60.0, $ozonData['revenue_share']); // 75000/125000 * 100
        $this->assertEquals(60.0, $ozonData['orders_share']); // 150/250 * 100
        
        $wbData = $result['marketplaces'][1];
        $this->assertEquals('wb', $wbData['marketplace']);
        $this->assertEquals(100, $wbData['total_orders']);
        $this->assertEquals(50000.00, $wbData['total_revenue']);
        $this->assertEquals(40.0, $wbData['revenue_share']); // 50000/125000 * 100
        $this->assertEquals(40.0, $wbData['orders_share']); // 100/250 * 100
    }
    
    /**
     * Test top products ranking logic
     * Requirements: 1.1, 2.1, 3.1
     */
    public function testGetTopProductsByMarketplace() {
        // Mock data for top products
        $mockData = [
            [
                'product_id' => 1,
                'product_name' => 'Товар А',
                'sku_ozon' => 'SKU001',
                'category' => 'Электроника',
                'cost_price' => 100.00,
                'total_orders' => 50,
                'total_quantity' => 75,
                'total_revenue' => 15000.00,
                'average_order_value' => 300.00,
                'average_price' => 200.00,
                'marketplaces' => 'Ozon,Wildberries'
            ],
            [
                'product_id' => 2,
                'product_name' => 'Товар Б',
                'sku_ozon' => 'SKU002',
                'category' => 'Одежда',
                'cost_price' => 50.00,
                'total_orders' => 30,
                'total_quantity' => 45,
                'total_revenue' => 9000.00,
                'average_order_value' => 300.00,
                'average_price' => 200.00,
                'marketplaces' => 'Ozon'
            ]
        ];
        
        // Configure mock statement
        $this->mockStmt->expects($this->once())
            ->method('execute')
            ->willReturn(true);
        
        $this->mockStmt->expects($this->once())
            ->method('fetchAll')
            ->willReturn($mockData);
        
        // Configure mock PDO
        $this->mockPdo->expects($this->once())
            ->method('prepare')
            ->willReturn($this->mockStmt);
        
        // Execute test
        $result = $this->service->getTopProductsByMarketplace('all', 10, '2024-01-01', '2024-01-31');
        
        // Assertions
        $this->assertIsArray($result);
        $this->assertArrayHasKey('period', $result);
        $this->assertArrayHasKey('filters', $result);
        $this->assertArrayHasKey('products', $result);
        
        // Check filters
        $this->assertEquals('all', $result['filters']['marketplace']);
        $this->assertEquals(10, $result['filters']['limit']);
        
        // Check products ranking (should be ordered by revenue DESC)
        $this->assertCount(2, $result['products']);
        
        $topProduct = $result['products'][0];
        $this->assertEquals(1, $topProduct['product_id']);
        $this->assertEquals('Товар А', $topProduct['product_name']);
        $this->assertEquals(15000.00, $topProduct['total_revenue']);
        $this->assertEquals(75, $topProduct['total_quantity']);
        
        // Check margin calculations
        $expectedMargin = 15000.00 - (100.00 * 75); // revenue - (cost_price * quantity)
        $expectedMarginPercent = ($expectedMargin / 15000.00) * 100;
        $this->assertEquals($expectedMargin, $topProduct['margin']);
        $this->assertEquals(round($expectedMarginPercent, 2), $topProduct['margin_percent']);
        
        $secondProduct = $result['products'][1];
        $this->assertEquals(2, $secondProduct['product_id']);
        $this->assertEquals(9000.00, $secondProduct['total_revenue']);
    }
    
    /**
     * Test sales dynamics aggregation
     * Requirements: 1.1, 2.1, 3.1
     */
    public function testGetSalesDynamics() {
        // Mock data for sales dynamics (monthly aggregation)
        $mockData = [
            [
                'period' => '2024-01',
                'marketplace' => 'OZON',
                'marketplace_name' => 'Ozon',
                'orders_count' => 100,
                'quantity_sold' => 200,
                'revenue' => 50000.00,
                'avg_order_value' => 500.00,
                'unique_products' => 15
            ],
            [
                'period' => '2024-01',
                'marketplace' => 'WB',
                'marketplace_name' => 'Wildberries',
                'orders_count' => 80,
                'quantity_sold' => 160,
                'revenue' => 40000.00,
                'avg_order_value' => 500.00,
                'unique_products' => 12
            ],
            [
                'period' => '2024-02',
                'marketplace' => 'OZON',
                'marketplace_name' => 'Ozon',
                'orders_count' => 120,
                'quantity_sold' => 240,
                'revenue' => 60000.00,
                'avg_order_value' => 500.00,
                'unique_products' => 18
            ],
            [
                'period' => '2024-02',
                'marketplace' => 'WB',
                'marketplace_name' => 'Wildberries',
                'orders_count' => 90,
                'quantity_sold' => 180,
                'revenue' => 45000.00,
                'avg_order_value' => 500.00,
                'unique_products' => 14
            ]
        ];
        
        // Configure mock statement
        $this->mockStmt->expects($this->once())
            ->method('execute')
            ->willReturn(true);
        
        $this->mockStmt->expects($this->once())
            ->method('fetchAll')
            ->willReturn($mockData);
        
        // Configure mock PDO
        $this->mockPdo->expects($this->once())
            ->method('prepare')
            ->willReturn($this->mockStmt);
        
        // Execute test
        $result = $this->service->getSalesDynamics('month', '2024-01-01', '2024-02-29', 'all');
        
        // Assertions
        $this->assertIsArray($result);
        $this->assertArrayHasKey('period_type', $result);
        $this->assertArrayHasKey('date_range', $result);
        $this->assertArrayHasKey('dynamics', $result);
        
        // Check period type
        $this->assertEquals('month', $result['period_type']);
        
        // Check dynamics data aggregation
        $this->assertCount(2, $result['dynamics']); // 2 periods: 2024-01, 2024-02
        
        // Check first period (2024-01)
        $firstPeriod = $result['dynamics'][0];
        $this->assertEquals('2024-01', $firstPeriod['period']);
        $this->assertEquals(180, $firstPeriod['total_orders']); // 100 + 80
        $this->assertEquals(90000.00, $firstPeriod['total_revenue']); // 50000 + 40000
        $this->assertEquals(360, $firstPeriod['total_quantity']); // 200 + 160
        $this->assertCount(2, $firstPeriod['marketplaces']); // OZON + WB
        
        // Check growth rates for second period (2024-02)
        $secondPeriod = $result['dynamics'][1];
        $this->assertEquals('2024-02', $secondPeriod['period']);
        $this->assertEquals(210, $secondPeriod['total_orders']); // 120 + 90
        $this->assertEquals(105000.00, $secondPeriod['total_revenue']); // 60000 + 45000
        
        // Check growth rate calculations
        $expectedOrdersGrowth = ((210 - 180) / 180) * 100; // 16.67%
        $expectedRevenueGrowth = ((105000 - 90000) / 90000) * 100; // 16.67%
        
        $this->assertEquals(round($expectedOrdersGrowth, 2), $secondPeriod['growth_rates']['orders_growth']);
        $this->assertEquals(round($expectedRevenueGrowth, 2), $secondPeriod['growth_rates']['revenue_growth']);
    }
    
    /**
     * Test date validation logic
     * Requirements: 1.1, 2.1, 3.1
     */
    public function testDateValidation() {
        // Test invalid date format
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid date range provided');
        
        $this->service->getMarketplaceComparison('invalid-date', '2024-01-31');
    }
    
    /**
     * Test marketplace filter validation
     * Requirements: 1.1, 2.1, 3.1
     */
    public function testMarketplaceFilterValidation() {
        // Mock empty result for valid call
        $this->mockStmt->expects($this->never())
            ->method('execute');
        
        $this->mockPdo->expects($this->never())
            ->method('prepare');
        
        // Test invalid marketplace filter
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid marketplace filter');
        
        $this->service->getTopProductsByMarketplace('invalid_marketplace', 10, '2024-01-01', '2024-01-31');
    }
    
    /**
     * Test limit validation and boundary conditions
     * Requirements: 1.1, 2.1, 3.1
     */
    public function testLimitValidation() {
        // Mock data for limit test
        $mockData = [];
        
        $this->mockStmt->expects($this->once())
            ->method('execute')
            ->willReturn(true);
        
        $this->mockStmt->expects($this->once())
            ->method('fetchAll')
            ->willReturn($mockData);
        
        $this->mockPdo->expects($this->once())
            ->method('prepare')
            ->willReturn($this->mockStmt);
        
        // Test limit boundary (should be clamped to 1-100 range)
        $result = $this->service->getTopProductsByMarketplace('all', 150, '2024-01-01', '2024-01-31');
        
        // Should clamp limit to 100
        $this->assertEquals(100, $result['filters']['limit']);
    }
    
    /**
     * Test dashboard summary aggregation
     * Requirements: 1.1, 2.1, 3.1
     */
    public function testGetDashboardSummary() {
        // Mock data for dashboard summary
        $mockSummaryData = [
            'total_orders' => 250,
            'total_quantity' => 500,
            'total_revenue' => 125000.00,
            'average_order_value' => 500.00,
            'unique_products' => 45,
            'active_marketplaces' => 2,
            'first_order_date' => '2024-01-01',
            'last_order_date' => '2024-01-31'
        ];
        
        // Configure mock statement for main query
        $this->mockStmt->expects($this->exactly(2)) // Main query + top regions query
            ->method('execute')
            ->willReturn(true);
        
        $this->mockStmt->expects($this->exactly(2))
            ->method('fetch')
            ->willReturnOnConsecutiveCalls($mockSummaryData, null);
        
        $this->mockStmt->expects($this->once())
            ->method('fetchAll')
            ->willReturn([]); // Empty top regions for simplicity
        
        // Configure mock PDO
        $this->mockPdo->expects($this->exactly(2))
            ->method('prepare')
            ->willReturn($this->mockStmt);
        
        // Execute test
        $result = $this->service->getDashboardSummary('2024-01-01', '2024-01-31', 'all');
        
        // Assertions
        $this->assertIsArray($result);
        $this->assertEquals(125000.00, $result['total_revenue']);
        $this->assertEquals(250, $result['total_orders']);
        $this->assertEquals(500, $result['total_quantity']);
        $this->assertEquals(500.00, $result['average_order_value']);
        $this->assertEquals(45, $result['unique_products']);
        $this->assertEquals(2, $result['active_marketplaces']);
        
        // Check period info
        $this->assertArrayHasKey('period_info', $result);
        $this->assertEquals('2024-01-01', $result['period_info']['date_from']);
        $this->assertEquals('2024-01-31', $result['period_info']['date_to']);
    }
}