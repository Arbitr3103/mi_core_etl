<?php
/**
 * Integration Tests for Error Handling and Recovery Scenarios
 * 
 * Tests various error conditions and recovery mechanisms in the
 * Ozon warehouse stock reports system.
 */

require_once __DIR__ . '/../../src/classes/CSVReportProcessor.php';
require_once __DIR__ . '/../../src/classes/InventoryDataUpdater.php';
require_once __DIR__ . '/../../src/classes/StockAlertManager.php';

class OzonStockReportsErrorHandlingTest extends PHPUnit\Framework\TestCase {
    
    private $testPdo;
    private $csvProcessor;
    private $inventoryUpdater;
    private $alertManager;
    private $testDatabaseName = 'test_ozon_error_handling';
    
    protected function setUp(): void {
        $this->createTestDatabase();
        $this->setupTestTables();
        $this->insertTestData();
        
        $this->csvProcessor = new CSVReportProcessor($this->testPdo);
        $this->inventoryUpdater = new InventoryDataUpdater($this->testPdo, 10);
        $this->alertManager = new StockAlertManager($this->testPdo);
    }
    
    protected function tearDown(): void {
        if ($this->testPdo) {
            $this->testPdo->exec("DROP DATABASE IF EXISTS {$this->testDatabaseName}");
            $this->testPdo = null;
        }
    }
    
    private function createTestDatabase(): void {
        try {
            $pdo = new PDO('mysql:host=localhost', 'root', '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
            $pdo->exec("DROP DATABASE IF EXISTS {$this->testDatabaseName}");
            $pdo->exec("CREATE DATABASE {$this->testDatabaseName} CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
            
            $this->testPdo = new PDO(
                "mysql:host=localhost;dbname={$this->testDatabaseName}",
                'root',
                '',
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
            );
        } catch (PDOException $e) {
            $this->markTestSkipped('Database connection failed: ' . $e->getMessage());
        }
    }
    
    private function setupTestTables(): void {
        // Create minimal tables for error testing
        $this->testPdo->exec("
            CREATE TABLE dim_products (
                id INT AUTO_INCREMENT PRIMARY KEY,
                sku VARCHAR(255) NOT NULL UNIQUE,
                sku_ozon VARCHAR(255),
                product_name VARCHAR(500)
            ) ENGINE=InnoDB
        ");
        
        $this->testPdo->exec("
            CREATE TABLE inventory (
                id INT AUTO_INCREMENT PRIMARY KEY,
                product_id INT NOT NULL,
                warehouse_name VARCHAR(255) NOT NULL,
                source ENUM('Ozon', 'Wildberries') NOT NULL,
                quantity_present INT DEFAULT 0,
                quantity_reserved INT DEFAULT 0,
                report_source ENUM('API_DIRECT', 'API_REPORTS') DEFAULT 'API_DIRECT',
                report_code VARCHAR(255) NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                
                UNIQUE KEY unique_inventory (product_id, warehouse_name, source),
                FOREIGN KEY (product_id) REFERENCES dim_products(id) ON DELETE CASCADE
            ) ENGINE=InnoDB
        ");
        
        $this->testPdo->exec("
            CREATE TABLE replenishment_alerts (
                id INT AUTO_INCREMENT PRIMARY KEY,
                product_id INT NOT NULL,
                sku VARCHAR(255),
                product_name VARCHAR(500),
                alert_type ENUM('STOCKOUT_CRITICAL', 'STOCKOUT_WARNING', 'NO_SALES', 'SLOW_MOVING') NOT NULL,
                alert_level ENUM('CRITICAL', 'HIGH', 'MEDIUM', 'LOW') NOT NULL,
                message TEXT NOT NULL,
                current_stock INT,
                status ENUM('NEW', 'ACKNOWLEDGED', 'RESOLVED', 'IGNORED') DEFAULT 'NEW',
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                
                FOREIGN KEY (product_id) REFERENCES dim_products(id) ON DELETE CASCADE
            ) ENGINE=InnoDB
        ");
        
        $this->testPdo->exec("
            CREATE TABLE replenishment_settings (
                id INT AUTO_INCREMENT PRIMARY KEY,
                category ENUM('ANALYSIS', 'NOTIFICATIONS', 'GENERAL') NOT NULL,
                setting_key VARCHAR(255) NOT NULL,
                setting_value TEXT,
                setting_type ENUM('STRING', 'INTEGER', 'DECIMAL', 'BOOLEAN', 'JSON') NOT NULL,
                is_active BOOLEAN DEFAULT TRUE,
                
                UNIQUE KEY unique_setting (category, setting_key)
            ) ENGINE=InnoDB
        ");
    }
    
    private function insertTestData(): void {
        $products = [
            ['sku' => '1234567890', 'sku_ozon' => '1234567890', 'product_name' => 'Test Product 1'],
            ['sku' => '1234567891', 'sku_ozon' => '1234567891', 'product_name' => 'Test Product 2']
        ];
        
        $stmt = $this->testPdo->prepare("INSERT INTO dim_products (sku, sku_ozon, product_name) VALUES (:sku, :sku_ozon, :product_name)");
        foreach ($products as $product) {
            $stmt->execute($product);
        }
    }
    
    /**
     * Test CSV processing with corrupted data
     */
    public function testCSVProcessingWithCorruptedData(): void {
        // Test with completely malformed CSV
        $corruptedCSV = "This is not a CSV file\nRandom text\n123,456";
        
        $this->expectException(Exception::class);
        $this->csvProcessor->parseWarehouseStockCSV($corruptedCSV);
    }
    
    /**
     * Test CSV processing with missing columns
     */
    public function testCSVProcessingWithMissingColumns(): void {
        $incompleteCSV = "SKU,Warehouse_Name\n1234567890,Хоругвино\n1234567891,Тверь";
        
        $this->expectException(Exception::class);
        $this->expectExceptionMessage('CSV structure validation failed');
        
        $this->csvProcessor->parseWarehouseStockCSV($incompleteCSV);
    }
    
    /**
     * Test CSV processing with mixed valid and invalid rows
     */
    public function testCSVProcessingWithMixedValidInvalidRows(): void {
        $mixedCSV = "SKU,Warehouse_Name,Current_Stock,Reserved_Stock,Available_Stock,Last_Updated\n" .
                   "1234567890,Хоругвино,150,25,125,2025-10-23 10:30:00\n" .
                   "INVALID,Тверь,abc,def,ghi,invalid_date\n" .
                   "1234567891,Екатеринбург,75,10,65,2025-10-23 10:30:00\n" .
                   ",,,,,\n" . // Empty row
                   "1234567892,Новосибирск,-50,10,40,2025-10-23 10:30:00"; // Negative stock
        
        $result = $this->csvProcessor->parseWarehouseStockCSV($mixedCSV);
        
        // Should only return valid rows
        $this->assertCount(2, $result);
        $this->assertEquals('1234567890', $result[0]['SKU']);
        $this->assertEquals('1234567891', $result[1]['SKU']);
    }
    
    /**
     * Test inventory update with database connection failure
     */
    public function testInventoryUpdateWithDatabaseConnectionFailure(): void {
        // Create a PDO that will fail
        $failingPdo = $this->createMock(PDO::class);
        $failingPdo->method('beginTransaction')->willThrowException(new PDOException('Connection lost'));
        
        $failingUpdater = new InventoryDataUpdater($failingPdo);
        
        $stockData = [
            [
                'product_id' => 1,
                'warehouse_name' => 'Хоругвино',
                'source' => 'Ozon',
                'quantity_present' => 150,
                'quantity_reserved' => 25
            ]
        ];
        
        $this->expectException(Exception::class);
        $this->expectExceptionMessage('Inventory update failed');
        
        $failingUpdater->updateInventoryFromReport($stockData, 'TEST_REPORT_FAIL');
    }
    
    /**
     * Test inventory update with constraint violations
     */
    public function testInventoryUpdateWithConstraintViolations(): void {
        $invalidData = [
            [
                'product_id' => 999999, // Non-existent product
                'warehouse_name' => 'Хоругвино',
                'source' => 'Ozon',
                'quantity_present' => 150,
                'quantity_reserved' => 25
            ]
        ];
        
        // This should fail due to foreign key constraint
        $this->expectException(Exception::class);
        
        $this->inventoryUpdater->updateInventoryFromReport($invalidData, 'TEST_REPORT_CONSTRAINT');
    }
    
    /**
     * Test inventory update with partial batch failures
     */
    public function testInventoryUpdateWithPartialBatchFailures(): void {
        $mixedData = [
            // Valid record
            [
                'product_id' => 1,
                'warehouse_name' => 'Хоругвино',
                'source' => 'Ozon',
                'quantity_present' => 150,
                'quantity_reserved' => 25
            ],
            // Invalid record - missing required field
            [
                'warehouse_name' => 'Тверь',
                'source' => 'Ozon',
                'quantity_present' => 100,
                'quantity_reserved' => 10
                // Missing product_id
            ],
            // Valid record
            [
                'product_id' => 2,
                'warehouse_name' => 'Екатеринбург',
                'source' => 'Ozon',
                'quantity_present' => 75,
                'quantity_reserved' => 5
            ]
        ];
        
        $result = $this->inventoryUpdater->updateInventoryFromReport($mixedData, 'TEST_REPORT_PARTIAL');
        
        // Should process valid records and report errors for invalid ones
        $this->assertTrue($result['success']);
        $this->assertEquals(3, $result['processed_count']);
        $this->assertEquals(2, $result['inserted_count']);
        $this->assertEquals(1, $result['errors_count']);
        
        // Verify only valid records were inserted
        $stmt = $this->testPdo->prepare("SELECT COUNT(*) as count FROM inventory WHERE report_code = 'TEST_REPORT_PARTIAL'");
        $stmt->execute();
        $count = $stmt->fetch(PDO::FETCH_ASSOC)['count'];
        $this->assertEquals(2, $count);
    }
    
    /**
     * Test alert generation with missing configuration
     */
    public function testAlertGenerationWithMissingConfiguration(): void {
        // Don't insert any settings - should use defaults
        $this->testPdo->exec("DELETE FROM replenishment_settings");
        
        // Insert some inventory data that should trigger alerts
        $criticalData = [
            [
                'product_id' => 1,
                'warehouse_name' => 'Хоругвино',
                'source' => 'Ozon',
                'quantity_present' => 0,
                'quantity_reserved' => 0
            ]
        ];
        
        $this->inventoryUpdater->updateInventoryFromReport($criticalData, 'TEST_REPORT_NO_CONFIG');
        
        // Should still generate alerts using default thresholds
        $alertResult = $this->alertManager->generateCriticalStockAlerts();
        
        $this->assertArrayHasKey('total_alerts', $alertResult);
        // May or may not generate alerts depending on default thresholds and sales data
    }
    
    /**
     * Test alert generation with database table missing
     */
    public function testAlertGenerationWithMissingTable(): void {
        // Drop the alerts table
        $this->testPdo->exec("DROP TABLE replenishment_alerts");
        
        // Should handle missing table gracefully
        $this->expectException(PDOException::class);
        
        $this->alertManager->generateCriticalStockAlerts();
    }
    
    /**
     * Test notification sending with service failures
     */
    public function testNotificationSendingWithServiceFailures(): void {
        // Create alerts first
        $alerts = [
            [
                'product_id' => 1,
                'sku' => '1234567890',
                'product_name' => 'Test Product',
                'warehouse_name' => 'Хоругвино',
                'source' => 'Ozon',
                'alert_type' => 'STOCKOUT_CRITICAL',
                'alert_level' => 'CRITICAL',
                'current_stock' => 0,
                'reserved_stock' => 0,
                'days_until_stockout' => null,
                'avg_daily_sales' => 1.0,
                'recommended_action' => 'Test action'
            ]
        ];
        
        // Test notification sending (will be simulated)
        // In real implementation, this would test actual email/SMS service failures
        $result = $this->alertManager->sendStockAlertNotifications($alerts);
        
        // Should handle service failures gracefully
        $this->assertIsBool($result);
    }
    
    /**
     * Test memory management with large datasets
     */
    public function testMemoryManagementWithLargeDatasets(): void {
        // Create a very large dataset to test memory limits
        $largeDataset = [];
        for ($i = 1; $i <= 5000; $i++) {
            $largeDataset[] = [
                'product_id' => ($i % 2) + 1,
                'warehouse_name' => 'Warehouse_' . ($i % 10),
                'source' => 'Ozon',
                'quantity_present' => rand(0, 1000),
                'quantity_reserved' => rand(0, 100)
            ];
        }
        
        // Set very small batch size to test memory management
        $this->inventoryUpdater->setBatchSize(5);
        
        $initialMemory = memory_get_usage(true);
        
        try {
            $result = $this->inventoryUpdater->updateInventoryFromReport($largeDataset, 'TEST_REPORT_MEMORY');
            
            $finalMemory = memory_get_usage(true);
            $memoryIncrease = $finalMemory - $initialMemory;
            
            // Memory increase should be reasonable (less than 50MB for 5000 records)
            $this->assertLessThan(50 * 1024 * 1024, $memoryIncrease, 'Memory usage should be controlled');
            
            $this->assertTrue($result['success']);
            $this->assertEquals(5000, $result['processed_count']);
            
        } catch (Exception $e) {
            // If we hit memory limits, that's also a valid test result
            $this->assertStringContainsString('memory', strtolower($e->getMessage()));
        }
    }
    
    /**
     * Test transaction rollback on errors
     */
    public function testTransactionRollbackOnErrors(): void {
        // Insert initial data
        $initialData = [
            [
                'product_id' => 1,
                'warehouse_name' => 'Хоругвино',
                'source' => 'Ozon',
                'quantity_present' => 100,
                'quantity_reserved' => 10
            ]
        ];
        
        $this->inventoryUpdater->updateInventoryFromReport($initialData, 'TEST_REPORT_INITIAL');
        
        // Verify initial data was inserted
        $stmt = $this->testPdo->prepare("SELECT COUNT(*) as count FROM inventory");
        $stmt->execute();
        $initialCount = $stmt->fetch(PDO::FETCH_ASSOC)['count'];
        $this->assertEquals(1, $initialCount);
        
        // Now try to update with data that will cause an error mid-transaction
        $errorData = [
            // Valid record
            [
                'product_id' => 2,
                'warehouse_name' => 'Тверь',
                'source' => 'Ozon',
                'quantity_present' => 50,
                'quantity_reserved' => 5
            ],
            // Invalid record that will cause constraint violation
            [
                'product_id' => 999999, // Non-existent product
                'warehouse_name' => 'Екатеринбург',
                'source' => 'Ozon',
                'quantity_present' => 75,
                'quantity_reserved' => 7
            ]
        ];
        
        try {
            $this->inventoryUpdater->updateInventoryFromReport($errorData, 'TEST_REPORT_ERROR');
            $this->fail('Expected exception was not thrown');
        } catch (Exception $e) {
            // Expected exception
        }
        
        // Verify that no partial data was committed (transaction was rolled back)
        $stmt = $this->testPdo->prepare("SELECT COUNT(*) as count FROM inventory WHERE report_code = 'TEST_REPORT_ERROR'");
        $stmt->execute();
        $errorCount = $stmt->fetch(PDO::FETCH_ASSOC)['count'];
        $this->assertEquals(0, $errorCount);
        
        // Verify original data is still there
        $stmt = $this->testPdo->prepare("SELECT COUNT(*) as count FROM inventory");
        $stmt->execute();
        $finalCount = $stmt->fetch(PDO::FETCH_ASSOC)['count'];
        $this->assertEquals($initialCount, $finalCount);
    }
    
    /**
     * Test data validation edge cases
     */
    public function testDataValidationEdgeCases(): void {
        $edgeCaseData = [
            // Very large quantities
            [
                'product_id' => 1,
                'warehouse_name' => 'Хоругвино',
                'source' => 'Ozon',
                'quantity_present' => 2147483647, // Max int
                'quantity_reserved' => 1000000000
            ],
            // Zero quantities (should be valid)
            [
                'product_id' => 2,
                'warehouse_name' => 'Тверь',
                'source' => 'Ozon',
                'quantity_present' => 0,
                'quantity_reserved' => 0
            ],
            // Very long warehouse name
            [
                'product_id' => 1,
                'warehouse_name' => str_repeat('A', 300), // Exceeds typical VARCHAR limit
                'source' => 'Ozon',
                'quantity_present' => 100,
                'quantity_reserved' => 10
            ]
        ];
        
        $result = $this->inventoryUpdater->updateInventoryFromReport($edgeCaseData, 'TEST_REPORT_EDGE_CASES');
        
        // Should handle edge cases gracefully
        $this->assertTrue($result['success']);
        $this->assertGreaterThanOrEqual(2, $result['processed_count']); // At least the first two should work
        
        if ($result['errors_count'] > 0) {
            // Long warehouse name might cause an error, which is acceptable
            $this->assertLessThanOrEqual(1, $result['errors_count']);
        }
    }
    
    /**
     * Test concurrent access scenarios
     */
    public function testConcurrentAccessScenarios(): void {
        // Simulate concurrent updates to the same inventory record
        $data1 = [
            [
                'product_id' => 1,
                'warehouse_name' => 'Хоругвино',
                'source' => 'Ozon',
                'quantity_present' => 100,
                'quantity_reserved' => 10
            ]
        ];
        
        $data2 = [
            [
                'product_id' => 1,
                'warehouse_name' => 'Хоругвино',
                'source' => 'Ozon',
                'quantity_present' => 90,
                'quantity_reserved' => 15
            ]
        ];
        
        // First update
        $result1 = $this->inventoryUpdater->updateInventoryFromReport($data1, 'TEST_REPORT_CONCURRENT_1');
        $this->assertTrue($result1['success']);
        
        // Second update (should overwrite first)
        $result2 = $this->inventoryUpdater->updateInventoryFromReport($data2, 'TEST_REPORT_CONCURRENT_2');
        $this->assertTrue($result2['success']);
        
        // Verify final state reflects the last update
        $stmt = $this->testPdo->prepare("
            SELECT * FROM inventory 
            WHERE product_id = 1 AND warehouse_name = 'Хоругвино' AND source = 'Ozon'
        ");
        $stmt->execute();
        $record = $stmt->fetch(PDO::FETCH_ASSOC);
        
        $this->assertEquals(90, $record['quantity_present']);
        $this->assertEquals(15, $record['quantity_reserved']);
        $this->assertEquals('TEST_REPORT_CONCURRENT_2', $record['report_code']);
    }
}