<?php
/**
 * Unit Tests for WarehouseSalesAnalyticsService
 * 
 * Tests sales metrics calculations for warehouse dashboard.
 * 
 * Requirements: 4, 8
 */

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../../api/classes/WarehouseSalesAnalyticsService.php';

use PHPUnit\Framework\TestCase;

class WarehouseSalesAnalyticsServiceTest extends TestCase {
    
    private $service;
    private $mockPdo;
    private $mockStmt;
    
    protected function setUp(): void {
        $this->mockPdo = $this->createMock(PDO::class);
        $this->mockStmt = $this->createMock(PDOStatement::class);
        $this->service = new WarehouseSalesAnalyticsService($this->mockPdo);
    }
    
    /**
     * Test calculateDailySalesAvg with normal sales data
     * Requirements: 4.1, 4.2, 4.3, 4.4
     */
    public function testCalculateDailySalesAvgWithSales() {
        // Mock total sales: 280 units sold over 28 days
        $this->mockStmt->expects($this->exactly(2))
            ->method('execute')
            ->willReturn(true);
        
        $this->mockStmt->expects($this->exactly(2))
            ->method('fetch')
            ->willReturnOnConsecutiveCalls(
                ['total_sales' => 280],  // Total sales
                ['days_count' => 28]      // Days with stock
            );
        
        $this->mockPdo->expects($this->exactly(2))
            ->method('prepare')
            ->willReturn($this->mockStmt);
        
        $result = $this->service->calculateDailySalesAvg(1, 'АДЫГЕЙСК_РФЦ', 28);
        
        // 280 / 28 = 10.00
        $this->assertEquals(10.00, $result);
    }
    
    /**
     * Test calculateDailySalesAvg with partial stock availability
     * Requirements: 4.2
     */
    public function testCalculateDailySalesAvgWithPartialStock() {
        // Product was in stock only 14 days out of 28
        $this->mockStmt->expects($this->exactly(2))
            ->method('execute')
            ->willReturn(true);
        
        $this->mockStmt->expects($this->exactly(2))
            ->method('fetch')
            ->willReturnOnConsecutiveCalls(
                ['total_sales' => 140],  // Total sales
                ['days_count' => 14]      // Days with stock
            );
        
        $this->mockPdo->expects($this->exactly(2))
            ->method('prepare')
            ->willReturn($this->mockStmt);
        
        $result = $this->service->calculateDailySalesAvg(1, 'АДЫГЕЙСК_РФЦ', 28);
        
        // 140 / 14 = 10.00 (average per day when in stock)
        $this->assertEquals(10.00, $result);
    }
    
    /**
     * Test calculateDailySalesAvg with no sales
     * Requirements: 4.3
     */
    public function testCalculateDailySalesAvgWithNoSales() {
        $this->mockStmt->expects($this->exactly(2))
            ->method('execute')
            ->willReturn(true);
        
        $this->mockStmt->expects($this->exactly(2))
            ->method('fetch')
            ->willReturnOnConsecutiveCalls(
                ['total_sales' => 0],
                ['days_count' => 28]
            );
        
        $this->mockPdo->expects($this->exactly(2))
            ->method('prepare')
            ->willReturn($this->mockStmt);
        
        $result = $this->service->calculateDailySalesAvg(1, 'АДЫГЕЙСК_РФЦ', 28);
        
        $this->assertEquals(0.00, $result);
    }
    
    /**
     * Test calculateDailySalesAvg with no stock days
     * Requirements: 4.2
     */
    public function testCalculateDailySalesAvgWithNoStockDays() {
        $this->mockStmt->expects($this->exactly(2))
            ->method('execute')
            ->willReturn(true);
        
        $this->mockStmt->expects($this->exactly(2))
            ->method('fetch')
            ->willReturnOnConsecutiveCalls(
                ['total_sales' => 100],
                ['days_count' => 0]  // No days with stock
            );
        
        $this->mockPdo->expects($this->exactly(2))
            ->method('prepare')
            ->willReturn($this->mockStmt);
        
        $result = $this->service->calculateDailySalesAvg(1, 'АДЫГЕЙСК_РФЦ', 28);
        
        // Should return 0 to avoid division by zero
        $this->assertEquals(0.00, $result);
    }
    
    /**
     * Test calculateDailySalesAvg rounds to 2 decimal places
     * Requirements: 4.4
     */
    public function testCalculateDailySalesAvgRounding() {
        $this->mockStmt->expects($this->exactly(2))
            ->method('execute')
            ->willReturn(true);
        
        $this->mockStmt->expects($this->exactly(2))
            ->method('fetch')
            ->willReturnOnConsecutiveCalls(
                ['total_sales' => 100],
                ['days_count' => 28]
            );
        
        $this->mockPdo->expects($this->exactly(2))
            ->method('prepare')
            ->willReturn($this->mockStmt);
        
        $result = $this->service->calculateDailySalesAvg(1, 'АДЫГЕЙСК_РФЦ', 28);
        
        // 100 / 28 = 3.571428... should round to 3.57
        $this->assertEquals(3.57, $result);
    }
    
    /**
     * Test getDaysWithoutSales with recent sales
     * Requirements: 8.1, 8.2
     */
    public function testGetDaysWithoutSalesWithRecentSales() {
        $yesterday = date('Y-m-d', strtotime('-1 day'));
        
        $this->mockStmt->expects($this->once())
            ->method('execute')
            ->willReturn(true);
        
        $this->mockStmt->expects($this->once())
            ->method('fetch')
            ->willReturn(['last_sale_date' => $yesterday]);
        
        $this->mockPdo->expects($this->once())
            ->method('prepare')
            ->willReturn($this->mockStmt);
        
        $result = $this->service->getDaysWithoutSales(1, 'АДЫГЕЙСК_РФЦ');
        
        // Last sale was yesterday, so 1 day without sales
        $this->assertEquals(1, $result);
    }
    
    /**
     * Test getDaysWithoutSales with no sales history
     * Requirements: 8.1
     */
    public function testGetDaysWithoutSalesWithNoHistory() {
        $this->mockStmt->expects($this->once())
            ->method('execute')
            ->willReturn(true);
        
        $this->mockStmt->expects($this->once())
            ->method('fetch')
            ->willReturn(['last_sale_date' => null]);
        
        $this->mockPdo->expects($this->once())
            ->method('prepare')
            ->willReturn($this->mockStmt);
        
        $result = $this->service->getDaysWithoutSales(1, 'АДЫГЕЙСК_РФЦ');
        
        // No sales history returns 0
        $this->assertEquals(0, $result);
    }
    
    /**
     * Test getDaysWithoutSales with sales 7 days ago
     * Requirements: 8.3
     */
    public function testGetDaysWithoutSalesSevenDaysAgo() {
        $sevenDaysAgo = date('Y-m-d', strtotime('-7 days'));
        
        $this->mockStmt->expects($this->once())
            ->method('execute')
            ->willReturn(true);
        
        $this->mockStmt->expects($this->once())
            ->method('fetch')
            ->willReturn(['last_sale_date' => $sevenDaysAgo]);
        
        $this->mockPdo->expects($this->once())
            ->method('prepare')
            ->willReturn($this->mockStmt);
        
        $result = $this->service->getDaysWithoutSales(1, 'АДЫГЕЙСК_РФЦ');
        
        $this->assertEquals(7, $result);
    }
    
    /**
     * Test getSalesLast28Days with sales data
     * Requirements: 4.1
     */
    public function testGetSalesLast28DaysWithSales() {
        $this->mockStmt->expects($this->once())
            ->method('execute')
            ->willReturn(true);
        
        $this->mockStmt->expects($this->once())
            ->method('fetch')
            ->willReturn(['total_sales' => 280]);
        
        $this->mockPdo->expects($this->once())
            ->method('prepare')
            ->willReturn($this->mockStmt);
        
        $result = $this->service->getSalesLast28Days(1, 'АДЫГЕЙСК_РФЦ');
        
        $this->assertEquals(280, $result);
    }
    
    /**
     * Test getSalesLast28Days with no sales
     * Requirements: 4.1
     */
    public function testGetSalesLast28DaysWithNoSales() {
        $this->mockStmt->expects($this->once())
            ->method('execute')
            ->willReturn(true);
        
        $this->mockStmt->expects($this->once())
            ->method('fetch')
            ->willReturn(['total_sales' => 0]);
        
        $this->mockPdo->expects($this->once())
            ->method('prepare')
            ->willReturn($this->mockStmt);
        
        $result = $this->service->getSalesLast28Days(1, 'АДЫГЕЙСК_РФЦ');
        
        $this->assertEquals(0, $result);
    }
    
    /**
     * Test getDaysWithStock with full availability
     * Requirements: 4.2
     */
    public function testGetDaysWithStockFullAvailability() {
        $this->mockStmt->expects($this->once())
            ->method('execute')
            ->willReturn(true);
        
        $this->mockStmt->expects($this->once())
            ->method('fetch')
            ->willReturn(['days_count' => 28]);
        
        $this->mockPdo->expects($this->once())
            ->method('prepare')
            ->willReturn($this->mockStmt);
        
        $result = $this->service->getDaysWithStock(1, 'АДЫГЕЙСК_РФЦ', 28);
        
        $this->assertEquals(28, $result);
    }
    
    /**
     * Test getDaysWithStock with partial availability
     * Requirements: 4.2
     */
    public function testGetDaysWithStockPartialAvailability() {
        $this->mockStmt->expects($this->once())
            ->method('execute')
            ->willReturn(true);
        
        $this->mockStmt->expects($this->once())
            ->method('fetch')
            ->willReturn(['days_count' => 14]);
        
        $this->mockPdo->expects($this->once())
            ->method('prepare')
            ->willReturn($this->mockStmt);
        
        $result = $this->service->getDaysWithStock(1, 'АДЫГЕЙСК_РФЦ', 28);
        
        $this->assertEquals(14, $result);
    }
    
    /**
     * Test getDaysWithStock with no availability
     * Requirements: 4.2
     */
    public function testGetDaysWithStockNoAvailability() {
        $this->mockStmt->expects($this->once())
            ->method('execute')
            ->willReturn(true);
        
        $this->mockStmt->expects($this->once())
            ->method('fetch')
            ->willReturn(['days_count' => 0]);
        
        $this->mockPdo->expects($this->once())
            ->method('prepare')
            ->willReturn($this->mockStmt);
        
        $result = $this->service->getDaysWithStock(1, 'АДЫГЕЙСК_РФЦ', 28);
        
        $this->assertEquals(0, $result);
    }
}
