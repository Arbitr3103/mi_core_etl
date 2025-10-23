<?php
/**
 * Unit Tests for ReplenishmentCalculator
 * 
 * Tests replenishment calculations and liquidity status determination.
 * 
 * Requirements: 3, 5, 7
 */

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../../api/classes/ReplenishmentCalculator.php';

use PHPUnit\Framework\TestCase;

class ReplenishmentCalculatorTest extends TestCase {
    
    private $calculator;
    
    protected function setUp(): void {
        $this->calculator = new ReplenishmentCalculator();
    }
    
    /**
     * Test calculateTargetStock with normal sales
     * Requirements: 3.1
     */
    public function testCalculateTargetStockWithNormalSales() {
        $result = $this->calculator->calculateTargetStock(10.0, 30);
        
        // 10 units/day * 30 days = 300 units
        $this->assertEquals(300, $result);
    }
    
    /**
     * Test calculateTargetStock with fractional sales
     * Requirements: 3.1
     */
    public function testCalculateTargetStockWithFractionalSales() {
        $result = $this->calculator->calculateTargetStock(3.57, 30);
        
        // 3.57 * 30 = 107.1, should round up to 108
        $this->assertEquals(108, $result);
    }
    
    /**
     * Test calculateTargetStock with zero sales
     * Requirements: 3.1
     */
    public function testCalculateTargetStockWithZeroSales() {
        $result = $this->calculator->calculateTargetStock(0, 30);
        
        // No sales means no target stock needed
        $this->assertEquals(0, $result);
    }
    
    /**
     * Test calculateTargetStock with negative sales (edge case)
     * Requirements: 3.1
     */
    public function testCalculateTargetStockWithNegativeSales() {
        $result = $this->calculator->calculateTargetStock(-5.0, 30);
        
        // Negative sales should return 0
        $this->assertEquals(0, $result);
    }
    
    /**
     * Test calculateTargetStock with different supply periods
     * Requirements: 3.1
     */
    public function testCalculateTargetStockWithDifferentPeriods() {
        // 7 days supply
        $result7 = $this->calculator->calculateTargetStock(10.0, 7);
        $this->assertEquals(70, $result7);
        
        // 14 days supply
        $result14 = $this->calculator->calculateTargetStock(10.0, 14);
        $this->assertEquals(140, $result14);
        
        // 60 days supply
        $result60 = $this->calculator->calculateTargetStock(10.0, 60);
        $this->assertEquals(600, $result60);
    }
    
    /**
     * Test calculateReplenishmentNeed with deficit
     * Requirements: 3.1, 3.2
     */
    public function testCalculateReplenishmentNeedWithDeficit() {
        $result = $this->calculator->calculateReplenishmentNeed(300, 50, 20, 30);
        
        // Target: 300, Available: 50, In transit: 20, In requests: 30
        // Total stock: 50 + 20 + 30 = 100
        // Need: 300 - 100 = 200
        $this->assertEquals(200, $result);
    }
    
    /**
     * Test calculateReplenishmentNeed with sufficient stock
     * Requirements: 3.3
     */
    public function testCalculateReplenishmentNeedWithSufficientStock() {
        $result = $this->calculator->calculateReplenishmentNeed(300, 200, 100, 50);
        
        // Total stock: 200 + 100 + 50 = 350 (exceeds target of 300)
        // Need: 0 (never negative)
        $this->assertEquals(0, $result);
    }
    
    /**
     * Test calculateReplenishmentNeed with exact target
     * Requirements: 3.3
     */
    public function testCalculateReplenishmentNeedWithExactTarget() {
        $result = $this->calculator->calculateReplenishmentNeed(300, 150, 100, 50);
        
        // Total stock: 150 + 100 + 50 = 300 (exactly at target)
        // Need: 0
        $this->assertEquals(0, $result);
    }
    
    /**
     * Test calculateReplenishmentNeed with no incoming stock
     * Requirements: 3.1
     */
    public function testCalculateReplenishmentNeedWithNoIncomingStock() {
        $result = $this->calculator->calculateReplenishmentNeed(300, 100, 0, 0);
        
        // Only available stock counts
        // Need: 300 - 100 = 200
        $this->assertEquals(200, $result);
    }
    
    /**
     * Test calculateReplenishmentNeed with zero target
     * Requirements: 3.2
     */
    public function testCalculateReplenishmentNeedWithZeroTarget() {
        $result = $this->calculator->calculateReplenishmentNeed(0, 50, 20, 10);
        
        // No target means no need
        $this->assertEquals(0, $result);
    }
    
    /**
     * Test calculateDaysOfStock with normal sales
     * Requirements: 5.1
     */
    public function testCalculateDaysOfStockWithNormalSales() {
        $result = $this->calculator->calculateDaysOfStock(280, 10.0);
        
        // 280 units / 10 units per day = 28 days
        $this->assertEquals(28.00, $result);
    }
    
    /**
     * Test calculateDaysOfStock with fractional result
     * Requirements: 5.1
     */
    public function testCalculateDaysOfStockWithFractionalResult() {
        $result = $this->calculator->calculateDaysOfStock(100, 3.57);
        
        // 100 / 3.57 = 28.01... should round to 28.01
        $this->assertEquals(28.01, $result);
    }
    
    /**
     * Test calculateDaysOfStock with zero sales (infinite stock)
     * Requirements: 5.2
     */
    public function testCalculateDaysOfStockWithZeroSales() {
        $result = $this->calculator->calculateDaysOfStock(100, 0);
        
        // No sales means infinite stock (null)
        $this->assertNull($result);
    }
    
    /**
     * Test calculateDaysOfStock with zero available
     * Requirements: 5.1
     */
    public function testCalculateDaysOfStockWithZeroAvailable() {
        $result = $this->calculator->calculateDaysOfStock(0, 10.0);
        
        // 0 / 10 = 0 days
        $this->assertEquals(0.00, $result);
    }
    
    /**
     * Test determineLiquidityStatus - critical (< 7 days)
     * Requirements: 5.3, 7.2
     */
    public function testDetermineLiquidityStatusCritical() {
        $this->assertEquals('critical', $this->calculator->determineLiquidityStatus(0));
        $this->assertEquals('critical', $this->calculator->determineLiquidityStatus(3.5));
        $this->assertEquals('critical', $this->calculator->determineLiquidityStatus(6.99));
    }
    
    /**
     * Test determineLiquidityStatus - low (7-14 days)
     * Requirements: 5.4, 7.3
     */
    public function testDetermineLiquidityStatusLow() {
        $this->assertEquals('low', $this->calculator->determineLiquidityStatus(7));
        $this->assertEquals('low', $this->calculator->determineLiquidityStatus(10));
        $this->assertEquals('low', $this->calculator->determineLiquidityStatus(14.99));
    }
    
    /**
     * Test determineLiquidityStatus - normal (15-45 days)
     * Requirements: 5.5, 7.4
     */
    public function testDetermineLiquidityStatusNormal() {
        $this->assertEquals('normal', $this->calculator->determineLiquidityStatus(15));
        $this->assertEquals('normal', $this->calculator->determineLiquidityStatus(30));
        $this->assertEquals('normal', $this->calculator->determineLiquidityStatus(45));
    }
    
    /**
     * Test determineLiquidityStatus - excess (> 45 days)
     * Requirements: 5.6, 7.5
     */
    public function testDetermineLiquidityStatusExcess() {
        $this->assertEquals('excess', $this->calculator->determineLiquidityStatus(45.01));
        $this->assertEquals('excess', $this->calculator->determineLiquidityStatus(60));
        $this->assertEquals('excess', $this->calculator->determineLiquidityStatus(100));
    }
    
    /**
     * Test determineLiquidityStatus with null (infinite stock)
     * Requirements: 5.2, 7.5
     */
    public function testDetermineLiquidityStatusWithNull() {
        $result = $this->calculator->determineLiquidityStatus(null);
        
        // Null (infinite stock) should be categorized as excess
        $this->assertEquals('excess', $result);
    }
    
    /**
     * Test getLiquidityStatusInfo for all statuses
     */
    public function testGetLiquidityStatusInfo() {
        $critical = $this->calculator->getLiquidityStatusInfo('critical');
        $this->assertEquals('Дефицит', $critical['label']);
        $this->assertEquals('red', $critical['color']);
        $this->assertEquals(1, $critical['priority']);
        
        $low = $this->calculator->getLiquidityStatusInfo('low');
        $this->assertEquals('Низкий запас', $low['label']);
        $this->assertEquals('yellow', $low['color']);
        $this->assertEquals(2, $low['priority']);
        
        $normal = $this->calculator->getLiquidityStatusInfo('normal');
        $this->assertEquals('Норма', $normal['label']);
        $this->assertEquals('green', $normal['color']);
        $this->assertEquals(3, $normal['priority']);
        
        $excess = $this->calculator->getLiquidityStatusInfo('excess');
        $this->assertEquals('Избыток', $excess['label']);
        $this->assertEquals('blue', $excess['color']);
        $this->assertEquals(4, $excess['priority']);
    }
    
    /**
     * Test isReplenishmentUrgent with high need (> 50%)
     * Requirements: 3.5
     */
    public function testIsReplenishmentUrgentWithHighNeed() {
        // 200 need / 300 target = 66.67% (urgent)
        $result = $this->calculator->isReplenishmentUrgent(200, 300);
        $this->assertTrue($result);
        
        // 151 need / 300 target = 50.33% (urgent)
        $result2 = $this->calculator->isReplenishmentUrgent(151, 300);
        $this->assertTrue($result2);
    }
    
    /**
     * Test isReplenishmentUrgent with low need (<= 50%)
     * Requirements: 3.5
     */
    public function testIsReplenishmentUrgentWithLowNeed() {
        // 150 need / 300 target = 50% (not urgent)
        $result = $this->calculator->isReplenishmentUrgent(150, 300);
        $this->assertFalse($result);
        
        // 100 need / 300 target = 33.33% (not urgent)
        $result2 = $this->calculator->isReplenishmentUrgent(100, 300);
        $this->assertFalse($result2);
    }
    
    /**
     * Test isReplenishmentUrgent with zero target
     * Requirements: 3.5
     */
    public function testIsReplenishmentUrgentWithZeroTarget() {
        $result = $this->calculator->isReplenishmentUrgent(100, 0);
        
        // Zero target means not urgent (no baseline to compare)
        $this->assertFalse($result);
    }
    
    /**
     * Test isReplenishmentUrgent with zero need
     * Requirements: 3.5
     */
    public function testIsReplenishmentUrgentWithZeroNeed() {
        $result = $this->calculator->isReplenishmentUrgent(0, 300);
        
        // No need means not urgent
        $this->assertFalse($result);
    }
}
