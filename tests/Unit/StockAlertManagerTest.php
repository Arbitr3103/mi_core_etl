<?php
/**
 * Unit Tests for StockAlertManager Class
 * 
 * Tests stock alert generation, notification delivery, and alert management
 * functionality for critical stock monitoring.
 */

require_once __DIR__ . '/../../src/classes/StockAlertManager.php';

class StockAlertManagerTest extends PHPUnit\Framework\TestCase {
    
    private $alertManager;
    private $mockPdo;
    private $mockLogger;
    
    protected function setUp(): void {
        // Create mock PDO and logger
        $this->mockPdo = $this->createMock(PDO::class);
        $this->mockLogger = $this->createMock(stdClass::class);
        $this->mockLogger->method('log')->willReturn(true);
        
        $this->alertManager = new StockAlertManager($this->mockPdo, $this->mockLogger);
    }
    
    /**
     * Test stock level analysis with various alert levels
     */
    public function testAnalyzeStockLevelsWithVariousAlertLevels(): void {
        $mockStmt = $this->createMock(PDOStatement::class);
        
        // Mock threshold settings query
        $mockThresholdStmt = $this->createMock(PDOStatement::class);
        $mockThresholdStmt->method('execute')->willReturn(true);
        $mockThresholdStmt->method('fetchAll')->willReturn([
            ['setting_key' => 'critical_stockout_threshold', 'setting_value' => '3', 'setting_type' => 'INTEGER'],
            ['setting_key' => 'high_priority_threshold', 'setting_value' => '7', 'setting_type' => 'INTEGER']
        ]);
        
        // Mock main analysis query
        $mockAnalysisStmt = $this->createMock(PDOStatement::class);
        $mockAnalysisStmt->method('bindValue')->willReturn(true);
        $mockAnalysisStmt->method('execute')->willReturn(true);
        $mockAnalysisStmt->method('fetchAll')->willReturn([
            [
                'id' => 1,
                'product_id' => 1,
                'sku_ozon' => '123456',
                'product_name' => 'Test Product 1',
                'warehouse_name' => 'Хоругвино',
                'quantity_present' => 2,
                'quantity_reserved' => 0,
                'source' => 'Ozon',
                'total_sold_30d' => 30,
                'avg_daily_sales' => 1.0,
                'days_until_stockout' => 2.0,
                'alert_level' => 'CRITICAL'
            ],
            [
                'id' => 2,
                'product_id' => 2,
                'sku_ozon' => '789012',
                'product_name' => 'Test Product 2',
                'warehouse_name' => 'Тверь',
                'quantity_present' => 5,
                'quantity_reserved' => 1,
                'source' => 'Ozon',
                'total_sold_30d' => 20,
                'avg_daily_sales' => 0.7,
                'days_until_stockout' => 7.1,
                'alert_level' => 'HIGH'
            ],
            [
                'id' => 3,
                'product_id' => 3,
                'sku_ozon' => '345678',
                'product_name' => 'Test Product 3',
                'warehouse_name' => 'Екатеринбург',
                'quantity_present' => 100,
                'quantity_reserved' => 10,
                'source' => 'Ozon',
                'total_sold_30d' => 5,
                'avg_daily_sales' => 0.2,
                'days_until_stockout' => 500.0,
                'alert_level' => 'NORMAL'
            ]
        ]);
        
        $this->mockPdo->method('prepare')
                      ->willReturnOnConsecutiveCalls($mockThresholdStmt, $mockAnalysisStmt);
        
        $result = $this->alertManager->analyzeStockLevels(['source' => 'Ozon']);
        
        $this->assertArrayHasKey('analysis_timestamp', $result);
        $this->assertArrayHasKey('summary', $result);
        $this->assertArrayHasKey('detailed_results', $result);
        
        $summary = $result['summary'];
        $this->assertEquals(3, $summary['total_analyzed']);
        $this->assertEquals(1, $summary['by_alert_level']['CRITICAL']);
        $this->assertEquals(1, $summary['by_alert_level']['HIGH']);
        
        $this->assertArrayHasKey('Хоругвино', $summary['by_warehouse']);
        $this->assertEquals(1, $summary['by_warehouse']['Хоругвино']['critical']);
        
        $this->assertCount(1, $summary['critical_items']);
        $this->assertEquals('Test Product 1', $summary['critical_items'][0]['product_name']);
    }
    
    /**
     * Test critical stock alert generation
     */
    public function testGenerateCriticalStockAlerts(): void {
        // Mock analysis results
        $mockAnalysisStmt = $this->createMock(PDOStatement::class);
        $mockAnalysisStmt->method('bindValue')->willReturn(true);
        $mockAnalysisStmt->method('execute')->willReturn(true);
        $mockAnalysisStmt->method('fetchAll')->willReturn([
            [
                'id' => 1,
                'product_id' => 1,
                'sku_ozon' => '123456',
                'product_name' => 'Critical Product',
                'warehouse_name' => 'Хоругвино',
                'quantity_present' => 0,
                'quantity_reserved' => 0,
                'source' => 'Ozon',
                'total_sold_30d' => 30,
                'avg_daily_sales' => 1.0,
                'days_until_stockout' => null,
                'alert_level' => 'CRITICAL'
            ]
        ]);
        
        // Mock threshold settings
        $mockThresholdStmt = $this->createMock(PDOStatement::class);
        $mockThresholdStmt->method('execute')->willReturn(true);
        $mockThresholdStmt->method('fetchAll')->willReturn([]);
        
        // Mock duplicate check
        $mockDuplicateStmt = $this->createMock(PDOStatement::class);
        $mockDuplicateStmt->method('bindValue')->willReturn(true);
        $mockDuplicateStmt->method('execute')->willReturn(true);
        $mockDuplicateStmt->method('fetch')->willReturn(['count' => 0]);
        
        // Mock alert saving
        $mockSaveStmt = $this->createMock(PDOStatement::class);
        $mockSaveStmt->method('execute')->willReturn(true);
        
        $this->mockPdo->method('prepare')
                      ->willReturnOnConsecutiveCalls(
                          $mockThresholdStmt, 
                          $mockAnalysisStmt, 
                          $mockDuplicateStmt, 
                          $mockSaveStmt
                      );
        
        $result = $this->alertManager->generateCriticalStockAlerts();
        
        $this->assertArrayHasKey('generation_timestamp', $result);
        $this->assertArrayHasKey('total_alerts', $result);
        $this->assertArrayHasKey('alerts', $result);
        $this->assertArrayHasKey('grouped_by_warehouse', $result);
        
        $this->assertEquals(1, $result['total_alerts']);
        $this->assertCount(1, $result['alerts']);
        
        $alert = $result['alerts'][0];
        $this->assertEquals(1, $alert['product_id']);
        $this->assertEquals('123456', $alert['sku']);
        $this->assertEquals('Critical Product', $alert['product_name']);
        $this->assertEquals('Хоругвино', $alert['warehouse_name']);
        $this->assertEquals('STOCKOUT_CRITICAL', $alert['alert_type']);
        $this->assertEquals('CRITICAL', $alert['alert_level']);
        $this->assertEquals(0, $alert['current_stock']);
    }
    
    /**
     * Test alert generation with different threshold configurations
     */
    public function testGenerateAlertsWithDifferentThresholds(): void {
        // Test with custom thresholds
        $mockThresholdStmt = $this->createMock(PDOStatement::class);
        $mockThresholdStmt->method('execute')->willReturn(true);
        $mockThresholdStmt->method('fetchAll')->willReturn([
            ['setting_key' => 'critical_stockout_threshold', 'setting_value' => '5', 'setting_type' => 'INTEGER'],
            ['setting_key' => 'high_priority_threshold', 'setting_value' => '10', 'setting_type' => 'INTEGER']
        ]);
        
        $mockAnalysisStmt = $this->createMock(PDOStatement::class);
        $mockAnalysisStmt->method('bindValue')->willReturn(true);
        $mockAnalysisStmt->method('execute')->willReturn(true);
        $mockAnalysisStmt->method('fetchAll')->willReturn([
            [
                'id' => 1,
                'product_id' => 1,
                'sku_ozon' => '123456',
                'product_name' => 'Test Product',
                'warehouse_name' => 'Хоругвино',
                'quantity_present' => 4,
                'quantity_reserved' => 0,
                'source' => 'Ozon',
                'total_sold_30d' => 30,
                'avg_daily_sales' => 1.0,
                'days_until_stockout' => 4.0,
                'alert_level' => 'CRITICAL'
            ]
        ]);
        
        $mockDuplicateStmt = $this->createMock(PDOStatement::class);
        $mockDuplicateStmt->method('bindValue')->willReturn(true);
        $mockDuplicateStmt->method('execute')->willReturn(true);
        $mockDuplicateStmt->method('fetch')->willReturn(['count' => 0]);
        
        $mockSaveStmt = $this->createMock(PDOStatement::class);
        $mockSaveStmt->method('execute')->willReturn(true);
        
        $this->mockPdo->method('prepare')
                      ->willReturnOnConsecutiveCalls(
                          $mockThresholdStmt, 
                          $mockAnalysisStmt, 
                          $mockDuplicateStmt, 
                          $mockSaveStmt
                      );
        
        $result = $this->alertManager->generateCriticalStockAlerts();
        
        $this->assertEquals(1, $result['total_alerts']);
        $alert = $result['alerts'][0];
        $this->assertEquals('CRITICAL', $alert['alert_level']);
    }
    
    /**
     * Test stock alert notifications
     */
    public function testSendStockAlertNotifications(): void {
        $alerts = [
            [
                'product_id' => 1,
                'sku' => '123456',
                'product_name' => 'Critical Product',
                'warehouse_name' => 'Хоругвино',
                'source' => 'Ozon',
                'alert_type' => 'STOCKOUT_CRITICAL',
                'alert_level' => 'CRITICAL',
                'current_stock' => 0,
                'reserved_stock' => 0,
                'days_until_stockout' => null,
                'avg_daily_sales' => 1.0,
                'recommended_action' => 'Срочно пополнить запасы'
            ]
        ];
        
        // Mock notification settings
        $mockSettingsStmt = $this->createMock(PDOStatement::class);
        $mockSettingsStmt->method('execute')->willReturn(true);
        $mockSettingsStmt->method('fetchAll')->willReturn([
            ['setting_key' => 'email_enabled', 'setting_value' => 'true', 'setting_type' => 'BOOLEAN'],
            ['setting_key' => 'sms_enabled', 'setting_value' => 'false', 'setting_type' => 'BOOLEAN'],
            ['setting_key' => 'email_recipients', 'setting_value' => '["test@example.com"]', 'setting_type' => 'JSON']
        ]);
        
        // Mock notification logging (table check)
        $mockTableCheckStmt = $this->createMock(PDOStatement::class);
        $mockTableCheckStmt->method('execute')->willReturn(true);
        $mockTableCheckStmt->method('rowCount')->willReturn(1); // Table exists
        
        $mockLogStmt = $this->createMock(PDOStatement::class);
        $mockLogStmt->method('execute')->willReturn(true);
        
        $this->mockPdo->method('prepare')
                      ->willReturnOnConsecutiveCalls($mockSettingsStmt, $mockTableCheckStmt, $mockLogStmt);
        
        $result = $this->alertManager->sendStockAlertNotifications($alerts);
        
        $this->assertTrue($result);
    }
    
    /**
     * Test alert history retrieval
     */
    public function testGetStockAlertHistory(): void {
        $mockStmt = $this->createMock(PDOStatement::class);
        $mockStmt->method('bindValue')->willReturn(true);
        $mockStmt->method('execute')->willReturn(true);
        $mockStmt->method('fetchAll')->willReturn([
            [
                'id' => 1,
                'product_id' => 1,
                'sku' => '123456',
                'product_name' => 'Test Product',
                'alert_type' => 'STOCKOUT_CRITICAL',
                'alert_level' => 'CRITICAL',
                'message' => 'Test alert message',
                'current_stock' => 0,
                'days_until_stockout' => null,
                'recommended_action' => 'Test action',
                'status' => 'NEW',
                'acknowledged_by' => null,
                'acknowledged_at' => null,
                'created_at' => '2025-10-23 10:00:00',
                'updated_at' => '2025-10-23 10:00:00',
                'response_time_minutes' => null,
                'warehouse_name' => 'Хоругвино'
            ]
        ]);
        
        $this->mockPdo->method('prepare')->willReturn($mockStmt);
        
        $result = $this->alertManager->getStockAlertHistory(7, ['warehouse' => 'Хоругвино']);
        
        $this->assertArrayHasKey('query_period', $result);
        $this->assertArrayHasKey('total_alerts', $result);
        $this->assertArrayHasKey('alerts', $result);
        $this->assertArrayHasKey('metrics', $result);
        $this->assertArrayHasKey('grouped_data', $result);
        
        $this->assertEquals(1, $result['total_alerts']);
        $this->assertEquals(7, $result['query_period']['days']);
        
        $alert = $result['alerts'][0];
        $this->assertEquals('Test Product', $alert['product_name']);
        $this->assertEquals('CRITICAL', $alert['alert_level']);
        $this->assertEquals('NEW', $alert['status']);
    }
    
    /**
     * Test alert acknowledgment
     */
    public function testAcknowledgeAlert(): void {
        $mockStmt = $this->createMock(PDOStatement::class);
        $mockStmt->method('execute')->willReturn(true);
        $mockStmt->method('rowCount')->willReturn(1); // One row affected
        
        // Mock alert action logging (table check)
        $mockTableCheckStmt = $this->createMock(PDOStatement::class);
        $mockTableCheckStmt->method('execute')->willReturn(true);
        $mockTableCheckStmt->method('rowCount')->willReturn(0); // Table doesn't exist
        
        $mockCreateTableStmt = $this->createMock(PDOStatement::class);
        $mockCreateTableStmt->method('execute')->willReturn(true);
        
        $mockLogActionStmt = $this->createMock(PDOStatement::class);
        $mockLogActionStmt->method('execute')->willReturn(true);
        
        $this->mockPdo->method('prepare')
                      ->willReturnOnConsecutiveCalls(
                          $mockStmt, 
                          $mockTableCheckStmt, 
                          $mockCreateTableStmt, 
                          $mockLogActionStmt
                      );
        
        $result = $this->alertManager->acknowledgeAlert(1, 'test_user', 'Test acknowledgment');
        
        $this->assertTrue($result);
    }
    
    /**
     * Test alert resolution
     */
    public function testResolveAlert(): void {
        $mockStmt = $this->createMock(PDOStatement::class);
        $mockStmt->method('execute')->willReturn(true);
        $mockStmt->method('rowCount')->willReturn(1); // One row affected
        
        // Mock alert action logging
        $mockTableCheckStmt = $this->createMock(PDOStatement::class);
        $mockTableCheckStmt->method('execute')->willReturn(true);
        $mockTableCheckStmt->method('rowCount')->willReturn(1); // Table exists
        
        $mockLogActionStmt = $this->createMock(PDOStatement::class);
        $mockLogActionStmt->method('execute')->willReturn(true);
        
        $this->mockPdo->method('prepare')
                      ->willReturnOnConsecutiveCalls($mockStmt, $mockTableCheckStmt, $mockLogActionStmt);
        
        $result = $this->alertManager->resolveAlert(1, 'test_user', 'Issue resolved');
        
        $this->assertTrue($result);
    }
    
    /**
     * Test alert response metrics calculation
     */
    public function testGetAlertResponseMetrics(): void {
        $mockStmt = $this->createMock(PDOStatement::class);
        $mockStmt->method('bindValue')->willReturn(true);
        $mockStmt->method('execute')->willReturn(true);
        $mockStmt->method('fetch')->willReturn([
            'total_alerts' => 100,
            'new_alerts' => 20,
            'acknowledged_alerts' => 30,
            'resolved_alerts' => 45,
            'ignored_alerts' => 5,
            'avg_response_time_minutes' => 120.5,
            'min_response_time_minutes' => 15,
            'max_response_time_minutes' => 480,
            'critical_alerts' => 25,
            'high_alerts' => 35,
            'medium_alerts' => 30,
            'low_alerts' => 10,
            'stockout_critical' => 20,
            'stockout_warning' => 40,
            'no_sales_alerts' => 25,
            'slow_moving_alerts' => 15
        ]);
        
        $this->mockPdo->method('prepare')->willReturn($mockStmt);
        
        $result = $this->alertManager->getAlertResponseMetrics(30);
        
        $this->assertArrayHasKey('period_days', $result);
        $this->assertArrayHasKey('total_alerts', $result);
        $this->assertArrayHasKey('status_breakdown', $result);
        $this->assertArrayHasKey('status_percentages', $result);
        $this->assertArrayHasKey('response_time_metrics', $result);
        $this->assertArrayHasKey('effectiveness_score', $result);
        
        $this->assertEquals(30, $result['period_days']);
        $this->assertEquals(100, $result['total_alerts']);
        $this->assertEquals(20, $result['status_breakdown']['new']);
        $this->assertEquals(45, $result['status_breakdown']['resolved']);
        
        // Check percentages
        $this->assertEquals(20.0, $result['status_percentages']['new']);
        $this->assertEquals(45.0, $result['status_percentages']['resolved']);
        
        // Check response time metrics
        $this->assertEquals(120.5, $result['response_time_metrics']['average_minutes']);
        $this->assertEquals(2.0, $result['response_time_metrics']['average_hours']);
        
        // Check effectiveness score (acknowledged + resolved / total * 100)
        $this->assertEquals(75.0, $result['effectiveness_score']);
    }
    
    /**
     * Test alert generation with no sales data
     */
    public function testGenerateAlertsWithNoSalesData(): void {
        $mockThresholdStmt = $this->createMock(PDOStatement::class);
        $mockThresholdStmt->method('execute')->willReturn(true);
        $mockThresholdStmt->method('fetchAll')->willReturn([]);
        
        $mockAnalysisStmt = $this->createMock(PDOStatement::class);
        $mockAnalysisStmt->method('bindValue')->willReturn(true);
        $mockAnalysisStmt->method('execute')->willReturn(true);
        $mockAnalysisStmt->method('fetchAll')->willReturn([
            [
                'id' => 1,
                'product_id' => 1,
                'sku_ozon' => '123456',
                'product_name' => 'No Sales Product',
                'warehouse_name' => 'Хоругвино',
                'quantity_present' => 50,
                'quantity_reserved' => 0,
                'source' => 'Ozon',
                'total_sold_30d' => 0,
                'avg_daily_sales' => 0,
                'days_until_stockout' => null,
                'alert_level' => 'MEDIUM'
            ]
        ]);
        
        $mockDuplicateStmt = $this->createMock(PDOStatement::class);
        $mockDuplicateStmt->method('bindValue')->willReturn(true);
        $mockDuplicateStmt->method('execute')->willReturn(true);
        $mockDuplicateStmt->method('fetch')->willReturn(['count' => 0]);
        
        $mockSaveStmt = $this->createMock(PDOStatement::class);
        $mockSaveStmt->method('execute')->willReturn(true);
        
        $this->mockPdo->method('prepare')
                      ->willReturnOnConsecutiveCalls(
                          $mockThresholdStmt, 
                          $mockAnalysisStmt, 
                          $mockDuplicateStmt, 
                          $mockSaveStmt
                      );
        
        $result = $this->alertManager->generateCriticalStockAlerts();
        
        // Should not generate alerts for MEDIUM level (only CRITICAL and HIGH)
        $this->assertEquals(0, $result['total_alerts']);
    }
    
    /**
     * Test duplicate alert detection
     */
    public function testDuplicateAlertDetection(): void {
        $mockThresholdStmt = $this->createMock(PDOStatement::class);
        $mockThresholdStmt->method('execute')->willReturn(true);
        $mockThresholdStmt->method('fetchAll')->willReturn([]);
        
        $mockAnalysisStmt = $this->createMock(PDOStatement::class);
        $mockAnalysisStmt->method('bindValue')->willReturn(true);
        $mockAnalysisStmt->method('execute')->willReturn(true);
        $mockAnalysisStmt->method('fetchAll')->willReturn([
            [
                'id' => 1,
                'product_id' => 1,
                'sku_ozon' => '123456',
                'product_name' => 'Test Product',
                'warehouse_name' => 'Хоругвино',
                'quantity_present' => 1,
                'quantity_reserved' => 0,
                'source' => 'Ozon',
                'total_sold_30d' => 30,
                'avg_daily_sales' => 1.0,
                'days_until_stockout' => 1.0,
                'alert_level' => 'CRITICAL'
            ]
        ]);
        
        // Mock duplicate check - alert already exists
        $mockDuplicateStmt = $this->createMock(PDOStatement::class);
        $mockDuplicateStmt->method('bindValue')->willReturn(true);
        $mockDuplicateStmt->method('execute')->willReturn(true);
        $mockDuplicateStmt->method('fetch')->willReturn(['count' => 1]); // Duplicate found
        
        $this->mockPdo->method('prepare')
                      ->willReturnOnConsecutiveCalls($mockThresholdStmt, $mockAnalysisStmt, $mockDuplicateStmt);
        
        $result = $this->alertManager->generateCriticalStockAlerts();
        
        // Should not create duplicate alert
        $this->assertEquals(0, $result['total_alerts']);
    }
}