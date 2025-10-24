<?php
/**
 * Integration Tests for Ozon Stock Reports ETL Workflow
 * 
 * Tests the complete ETL workflow with mock API responses and database operations
 * for the Ozon warehouse stock reports system.
 */

require_once __DIR__ . '/../../src/classes/CSVReportProcessor.php';
require_once __DIR__ . '/../../src/classes/InventoryDataUpdater.php';
require_once __DIR__ . '/../../src/classes/StockAlertManager.php';

class OzonStockReportsETLIntegrationTest extends PHPUnit\Framework\TestCase {
    
    private $testPdo;
    private $csvProcessor;
    private $inventoryUpdater;
    private $alertManager;
    private $testDatabaseName = 'test_ozon_stock_reports';
    
    protected function setUp(): void {
        // Create test database connection
        $this->createTestDatabase();
        $this->setupTestTables();
        $this->insertTestData();
        
        // Initialize components
        $this->csvProcessor = new CSVReportProcessor($this->testPdo);
        $this->inventoryUpdater = new InventoryDataUpdater($this->testPdo, 50); // Small batch for testing
        $this->alertManager = new StockAlertManager($this->testPdo);
    }
    
    protected function tearDown(): void {
        if ($this->testPdo) {
            $this->testPdo->exec("DROP DATABASE IF EXISTS {$this->testDatabaseName}");
            $this->testPdo = null;
        }
    }
    
    /**
     * Create test database and connection
     */
    private function createTestDatabase(): void {
        try {
            // Connect to MySQL without database
            $pdo = new PDO(
                'mysql:host=localhost',
                'root',
                '',
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
            );
            
            // Create test database
            $pdo->exec("DROP DATABASE IF EXISTS {$this->testDatabaseName}");
            $pdo->exec("CREATE DATABASE {$this->testDatabaseName} CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
            
            // Connect to test database
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
    
    /**
     * Set up test database tables
     */
    private function setupTestTables(): void {
        // Create dim_products table
        $this->testPdo->exec("
            CREATE TABLE dim_products (
                id INT AUTO_INCREMENT PRIMARY KEY,
                sku VARCHAR(255) NOT NULL UNIQUE,
                sku_ozon VARCHAR(255),
                sku_wb VARCHAR(255),
                product_name VARCHAR(500),
                cost_price DECIMAL(10,2),
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            ) ENGINE=InnoDB
        ");
        
        // Create inventory table
        $this->testPdo->exec("
            CREATE TABLE inventory (
                id INT AUTO_INCREMENT PRIMARY KEY,
                product_id INT NOT NULL,
                warehouse_name VARCHAR(255) NOT NULL,
                source ENUM('Ozon', 'Wildberries') NOT NULL,
                quantity_present INT DEFAULT 0,
                quantity_reserved INT DEFAULT 0,
                stock_type VARCHAR(50) DEFAULT 'fbo',
                report_source ENUM('API_DIRECT', 'API_REPORTS') DEFAULT 'API_DIRECT',
                last_report_update TIMESTAMP NULL,
                report_code VARCHAR(255) NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                
                UNIQUE KEY unique_inventory (product_id, warehouse_name, source),
                FOREIGN KEY (product_id) REFERENCES dim_products(id) ON DELETE CASCADE,
                INDEX idx_warehouse_name (warehouse_name),
                INDEX idx_source (source),
                INDEX idx_report_source (report_source)
            ) ENGINE=InnoDB
        ");
        
        // Create stock_movements table for sales data
        $this->testPdo->exec("
            CREATE TABLE stock_movements (
                id INT AUTO_INCREMENT PRIMARY KEY,
                product_id INT NOT NULL,
                warehouse_name VARCHAR(255),
                movement_type ENUM('sale', 'order', 'return', 'adjustment') NOT NULL,
                quantity INT NOT NULL,
                movement_date TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                
                FOREIGN KEY (product_id) REFERENCES dim_products(id) ON DELETE CASCADE,
                INDEX idx_product_movement (product_id, movement_date),
                INDEX idx_movement_type (movement_type)
            ) ENGINE=InnoDB
        ");
        
        // Create replenishment_alerts table
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
                days_until_stockout DECIMAL(5,1),
                recommended_action TEXT,
                status ENUM('NEW', 'ACKNOWLEDGED', 'RESOLVED', 'IGNORED') DEFAULT 'NEW',
                acknowledged_by VARCHAR(255),
                acknowledged_at TIMESTAMP NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                
                FOREIGN KEY (product_id) REFERENCES dim_products(id) ON DELETE CASCADE,
                INDEX idx_alert_type (alert_type),
                INDEX idx_alert_level (alert_level),
                INDEX idx_status (status),
                INDEX idx_created_at (created_at)
            ) ENGINE=InnoDB
        ");
        
        // Create replenishment_settings table
        $this->testPdo->exec("
            CREATE TABLE replenishment_settings (
                id INT AUTO_INCREMENT PRIMARY KEY,
                category ENUM('ANALYSIS', 'NOTIFICATIONS', 'GENERAL') NOT NULL,
                setting_key VARCHAR(255) NOT NULL,
                setting_value TEXT,
                setting_type ENUM('STRING', 'INTEGER', 'DECIMAL', 'BOOLEAN', 'JSON') NOT NULL,
                is_active BOOLEAN DEFAULT TRUE,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                
                UNIQUE KEY unique_setting (category, setting_key)
            ) ENGINE=InnoDB
        ");
    }
    
    /**
     * Insert test data
     */
    private function insertTestData(): void {
        // Insert test products
        $products = [
            ['sku' => '1234567890', 'sku_ozon' => '1234567890', 'product_name' => 'Test Product 1', 'cost_price' => 100.00],
            ['sku' => '1234567891', 'sku_ozon' => '1234567891', 'product_name' => 'Test Product 2', 'cost_price' => 150.00],
            ['sku' => '1234567892', 'sku_ozon' => '1234567892', 'product_name' => 'Test Product 3', 'cost_price' => 200.00],
            ['sku' => '1234567893', 'sku_ozon' => '1234567893', 'product_name' => 'Critical Stock Product', 'cost_price' => 75.00],
            ['sku' => '1234567894', 'sku_ozon' => '1234567894', 'product_name' => 'No Sales Product', 'cost_price' => 50.00]
        ];
        
        $stmt = $this->testPdo->prepare("
            INSERT INTO dim_products (sku, sku_ozon, product_name, cost_price) 
            VALUES (:sku, :sku_ozon, :product_name, :cost_price)
        ");
        
        foreach ($products as $product) {
            $stmt->execute($product);
        }
        
        // Insert test settings
        $settings = [
            ['category' => 'ANALYSIS', 'setting_key' => 'critical_stockout_threshold', 'setting_value' => '3', 'setting_type' => 'INTEGER'],
            ['category' => 'ANALYSIS', 'setting_key' => 'high_priority_threshold', 'setting_value' => '7', 'setting_type' => 'INTEGER'],
            ['category' => 'ANALYSIS', 'setting_key' => 'slow_moving_threshold_days', 'setting_value' => '30', 'setting_type' => 'INTEGER'],
            ['category' => 'NOTIFICATIONS', 'setting_key' => 'email_enabled', 'setting_value' => 'true', 'setting_type' => 'BOOLEAN'],
            ['category' => 'NOTIFICATIONS', 'setting_key' => 'email_recipients', 'setting_value' => '["test@example.com"]', 'setting_type' => 'JSON']
        ];
        
        $stmt = $this->testPdo->prepare("
            INSERT INTO replenishment_settings (category, setting_key, setting_value, setting_type) 
            VALUES (:category, :setting_key, :setting_value, :setting_type)
        ");
        
        foreach ($settings as $setting) {
            $stmt->execute($setting);
        }
        
        // Insert test stock movements (sales data)
        $movements = [
            ['product_id' => 1, 'warehouse_name' => 'Хоругвино', 'movement_type' => 'sale', 'quantity' => -5, 'movement_date' => '2025-10-20 10:00:00'],
            ['product_id' => 1, 'warehouse_name' => 'Хоругвино', 'movement_type' => 'sale', 'quantity' => -3, 'movement_date' => '2025-10-21 14:00:00'],
            ['product_id' => 1, 'warehouse_name' => 'Хоругвино', 'movement_type' => 'sale', 'quantity' => -2, 'movement_date' => '2025-10-22 16:00:00'],
            ['product_id' => 2, 'warehouse_name' => 'Тверь', 'movement_type' => 'sale', 'quantity' => -1, 'movement_date' => '2025-10-22 12:00:00'],
            ['product_id' => 4, 'warehouse_name' => 'Хоругвино', 'movement_type' => 'sale', 'quantity' => -10, 'movement_date' => '2025-10-20 09:00:00'],
            ['product_id' => 4, 'warehouse_name' => 'Хоругвино', 'movement_type' => 'sale', 'quantity' => -8, 'movement_date' => '2025-10-21 11:00:00']
        ];
        
        $stmt = $this->testPdo->prepare("
            INSERT INTO stock_movements (product_id, warehouse_name, movement_type, quantity, movement_date) 
            VALUES (:product_id, :warehouse_name, :movement_type, :quantity, :movement_date)
        ");
        
        foreach ($movements as $movement) {
            $stmt->execute($movement);
        }
    }
    
    /**
     * Test complete ETL workflow with CSV processing and inventory updates
     */
    public function testCompleteETLWorkflowWithCSVProcessing(): void {
        // Create mock CSV content
        $csvContent = "SKU,Warehouse_Name,Current_Stock,Reserved_Stock,Available_Stock,Last_Updated\n" .
                     "1234567890,Хоругвино,150,25,125,2025-10-23 10:30:00\n" .
                     "1234567891,Тверь,89,12,77,2025-10-23 10:30:00\n" .
                     "1234567892,Екатеринбург,75,10,65,2025-10-23 10:30:00\n" .
                     "1234567893,Хоругвино,2,0,2,2025-10-23 10:30:00\n" .
                     "1234567894,Тверь,50,5,45,2025-10-23 10:30:00";
        
        // Step 1: Parse CSV
        $parsedData = $this->csvProcessor->parseWarehouseStockCSV($csvContent);
        $this->assertCount(5, $parsedData);
        
        // Step 2: Normalize warehouse names
        $normalizedData = $this->csvProcessor->normalizeWarehouseNames($parsedData);
        foreach ($normalizedData as $record) {
            $this->assertArrayHasKey('warehouse_normalized', $record);
        }
        
        // Step 3: Map SKUs to product IDs
        $mappedData = $this->csvProcessor->mapProductSKUs($normalizedData);
        
        // Verify SKU mapping
        $mappedCount = 0;
        foreach ($mappedData as $record) {
            if ($record['sku_mapped']) {
                $mappedCount++;
                $this->assertNotNull($record['product_id']);
            }
        }
        $this->assertEquals(5, $mappedCount); // All test SKUs should be mapped
        
        // Step 4: Prepare data for inventory update
        $inventoryData = [];
        foreach ($mappedData as $record) {
            if ($record['sku_mapped']) {
                $inventoryData[] = [
                    'product_id' => $record['product_id'],
                    'warehouse_name' => $record['Warehouse_Name'],
                    'source' => 'Ozon',
                    'quantity_present' => $record['Current_Stock'],
                    'quantity_reserved' => $record['Reserved_Stock']
                ];
            }
        }
        
        // Step 5: Update inventory
        $updateResult = $this->inventoryUpdater->updateInventoryFromReport($inventoryData, 'TEST_REPORT_001');
        
        $this->assertTrue($updateResult['success']);
        $this->assertEquals(5, $updateResult['processed_count']);
        $this->assertEquals(5, $updateResult['inserted_count']);
        $this->assertEquals(0, $updateResult['errors_count']);
        
        // Step 6: Verify data in database
        $stmt = $this->testPdo->prepare("
            SELECT i.*, dp.product_name 
            FROM inventory i 
            JOIN dim_products dp ON i.product_id = dp.id 
            WHERE i.report_code = 'TEST_REPORT_001'
            ORDER BY i.product_id
        ");
        $stmt->execute();
        $inventoryRecords = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        $this->assertCount(5, $inventoryRecords);
        
        // Verify specific records
        $this->assertEquals(150, $inventoryRecords[0]['quantity_present']);
        $this->assertEquals(25, $inventoryRecords[0]['quantity_reserved']);
        $this->assertEquals('Хоругвино', $inventoryRecords[0]['warehouse_name']);
        $this->assertEquals('API_REPORTS', $inventoryRecords[0]['report_source']);
        
        // Critical stock product
        $criticalRecord = array_filter($inventoryRecords, function($r) { return $r['quantity_present'] == 2; });
        $this->assertCount(1, $criticalRecord);
    }
    
    /**
     * Test ETL workflow with error handling and recovery
     */
    public function testETLWorkflowWithErrorHandlingAndRecovery(): void {
        // Create CSV with some invalid data
        $csvContent = "SKU,Warehouse_Name,Current_Stock,Reserved_Stock,Available_Stock,Last_Updated\n" .
                     "1234567890,Хоругвино,150,25,125,2025-10-23 10:30:00\n" .
                     "INVALID_SKU,Тверь,89,12,77,2025-10-23 10:30:00\n" .
                     "1234567892,Екатеринбург,-10,10,65,2025-10-23 10:30:00\n" .
                     "1234567893,Хоругвино,2,0,2,2025-10-23 10:30:00";
        
        // Parse CSV - should handle invalid data gracefully
        $parsedData = $this->csvProcessor->parseWarehouseStockCSV($csvContent);
        
        // Should skip invalid SKU and negative stock
        $this->assertCount(2, $parsedData); // Only valid records
        
        // Map SKUs
        $mappedData = $this->csvProcessor->mapProductSKUs($parsedData);
        
        // Prepare inventory data
        $inventoryData = [];
        foreach ($mappedData as $record) {
            if ($record['sku_mapped']) {
                $inventoryData[] = [
                    'product_id' => $record['product_id'],
                    'warehouse_name' => $record['Warehouse_Name'],
                    'source' => 'Ozon',
                    'quantity_present' => $record['Current_Stock'],
                    'quantity_reserved' => $record['Reserved_Stock']
                ];
            }
        }
        
        // Update inventory
        $updateResult = $this->inventoryUpdater->updateInventoryFromReport($inventoryData, 'TEST_REPORT_002');
        
        $this->assertTrue($updateResult['success']);
        $this->assertEquals(2, $updateResult['processed_count']);
        $this->assertEquals(0, $updateResult['errors_count']);
        
        // Verify only valid records were inserted
        $stmt = $this->testPdo->prepare("SELECT COUNT(*) as count FROM inventory WHERE report_code = 'TEST_REPORT_002'");
        $stmt->execute();
        $count = $stmt->fetch(PDO::FETCH_ASSOC)['count'];
        $this->assertEquals(2, $count);
    }
    
    /**
     * Test stock alert generation after inventory update
     */
    public function testStockAlertGenerationAfterInventoryUpdate(): void {
        // First, populate inventory with test data
        $inventoryData = [
            // Critical stock - will run out soon
            [
                'product_id' => 4, // Critical Stock Product
                'warehouse_name' => 'Хоругвино',
                'source' => 'Ozon',
                'quantity_present' => 2,
                'quantity_reserved' => 0
            ],
            // High priority - needs attention
            [
                'product_id' => 1, // Test Product 1
                'warehouse_name' => 'Хоругвино',
                'source' => 'Ozon',
                'quantity_present' => 6,
                'quantity_reserved' => 1
            ],
            // No sales product
            [
                'product_id' => 5, // No Sales Product
                'warehouse_name' => 'Тверь',
                'source' => 'Ozon',
                'quantity_present' => 50,
                'quantity_reserved' => 5
            ],
            // Normal stock
            [
                'product_id' => 2, // Test Product 2
                'warehouse_name' => 'Тверь',
                'source' => 'Ozon',
                'quantity_present' => 100,
                'quantity_reserved' => 10
            ]
        ];
        
        // Update inventory
        $updateResult = $this->inventoryUpdater->updateInventoryFromReport($inventoryData, 'TEST_REPORT_003');
        $this->assertTrue($updateResult['success']);
        
        // Generate stock alerts
        $alertResult = $this->alertManager->generateCriticalStockAlerts();
        
        $this->assertArrayHasKey('total_alerts', $alertResult);
        $this->assertGreaterThan(0, $alertResult['total_alerts']);
        
        // Verify critical alert was generated
        $criticalAlerts = array_filter($alertResult['alerts'], function($alert) {
            return $alert['alert_level'] === 'CRITICAL';
        });
        $this->assertGreaterThan(0, count($criticalAlerts));
        
        // Verify alert details
        $criticalAlert = reset($criticalAlerts);
        $this->assertEquals(4, $criticalAlert['product_id']);
        $this->assertEquals('Хоругвино', $criticalAlert['warehouse_name']);
        $this->assertEquals('STOCKOUT_CRITICAL', $criticalAlert['alert_type']);
        $this->assertEquals(2, $criticalAlert['current_stock']);
        
        // Verify alerts were saved to database
        $stmt = $this->testPdo->prepare("SELECT COUNT(*) as count FROM replenishment_alerts WHERE alert_level = 'CRITICAL'");
        $stmt->execute();
        $alertCount = $stmt->fetch(PDO::FETCH_ASSOC)['count'];
        $this->assertGreaterThan(0, $alertCount);
    }
    
    /**
     * Test inventory update with upsert functionality
     */
    public function testInventoryUpdateWithUpsertFunctionality(): void {
        // Initial inventory data
        $initialData = [
            [
                'product_id' => 1,
                'warehouse_name' => 'Хоругвино',
                'source' => 'Ozon',
                'quantity_present' => 100,
                'quantity_reserved' => 10
            ]
        ];
        
        // First update - should insert
        $result1 = $this->inventoryUpdater->updateInventoryFromReport($initialData, 'TEST_REPORT_004');
        $this->assertTrue($result1['success']);
        $this->assertEquals(1, $result1['inserted_count']);
        $this->assertEquals(0, $result1['updated_count']);
        
        // Updated inventory data for same product/warehouse/source
        $updatedData = [
            [
                'product_id' => 1,
                'warehouse_name' => 'Хоругвино',
                'source' => 'Ozon',
                'quantity_present' => 80,
                'quantity_reserved' => 15
            ]
        ];
        
        // Second update - should update existing record
        $result2 = $this->inventoryUpdater->updateInventoryFromReport($updatedData, 'TEST_REPORT_005');
        $this->assertTrue($result2['success']);
        $this->assertEquals(0, $result2['inserted_count']);
        $this->assertEquals(1, $result2['updated_count']);
        
        // Verify final state
        $stmt = $this->testPdo->prepare("
            SELECT * FROM inventory 
            WHERE product_id = 1 AND warehouse_name = 'Хоругвино' AND source = 'Ozon'
        ");
        $stmt->execute();
        $record = $stmt->fetch(PDO::FETCH_ASSOC);
        
        $this->assertEquals(80, $record['quantity_present']);
        $this->assertEquals(15, $record['quantity_reserved']);
        $this->assertEquals('TEST_REPORT_005', $record['report_code']);
    }
    
    /**
     * Test batch processing with large datasets
     */
    public function testBatchProcessingWithLargeDatasets(): void {
        // Create large dataset (200 records)
        $largeDataset = [];
        for ($i = 1; $i <= 200; $i++) {
            $largeDataset[] = [
                'product_id' => ($i % 5) + 1, // Cycle through 5 products
                'warehouse_name' => 'Warehouse_' . (($i % 10) + 1), // 10 different warehouses
                'source' => 'Ozon',
                'quantity_present' => rand(0, 1000),
                'quantity_reserved' => rand(0, 100)
            ];
        }
        
        // Set small batch size for testing
        $this->inventoryUpdater->setBatchSize(25);
        
        // Track progress
        $progressCalls = 0;
        $this->inventoryUpdater->setProgressCallback(function($progress) use (&$progressCalls) {
            $progressCalls++;
            $this->assertArrayHasKey('processed_records', $progress);
            $this->assertArrayHasKey('memory_usage', $progress);
        });
        
        // Process large dataset
        $result = $this->inventoryUpdater->updateInventoryFromReport($largeDataset, 'TEST_REPORT_LARGE');
        
        $this->assertTrue($result['success']);
        $this->assertEquals(200, $result['processed_count']);
        $this->assertGreaterThan(0, $progressCalls); // Progress callback should be called
        
        // Verify all records were processed
        $stmt = $this->testPdo->prepare("SELECT COUNT(*) as count FROM inventory WHERE report_code = 'TEST_REPORT_LARGE'");
        $stmt->execute();
        $count = $stmt->fetch(PDO::FETCH_ASSOC)['count'];
        $this->assertEquals(200, $count);
    }
    
    /**
     * Test data quality and reconciliation reporting
     */
    public function testDataQualityAndReconciliationReporting(): void {
        // Insert some problematic data for testing
        $problematicData = [
            // Normal record
            [
                'product_id' => 1,
                'warehouse_name' => 'Хоругвино',
                'source' => 'Ozon',
                'quantity_present' => 50,
                'quantity_reserved' => 10
            ],
            // Zero stock record
            [
                'product_id' => 2,
                'warehouse_name' => 'Тверь',
                'source' => 'Ozon',
                'quantity_present' => 0,
                'quantity_reserved' => 0
            ]
        ];
        
        $this->inventoryUpdater->updateInventoryFromReport($problematicData, 'TEST_REPORT_QUALITY');
        
        // Generate reconciliation report
        $report = $this->inventoryUpdater->generateReconciliationReport('TEST_REPORT_QUALITY');
        
        $this->assertArrayHasKey('generated_at', $report);
        $this->assertArrayHasKey('summary', $report);
        $this->assertArrayHasKey('issues', $report);
        $this->assertArrayHasKey('recommendations', $report);
        $this->assertEquals('TEST_REPORT_QUALITY', $report['report_code']);
        
        // Check for detected issues
        $this->assertIsArray($report['issues']);
        
        // Check recommendations
        $this->assertIsArray($report['recommendations']);
    }
    
    /**
     * Test alert notification workflow
     */
    public function testAlertNotificationWorkflow(): void {
        // Create critical stock situation
        $criticalData = [
            [
                'product_id' => 4,
                'warehouse_name' => 'Хоругвино',
                'source' => 'Ozon',
                'quantity_present' => 1,
                'quantity_reserved' => 0
            ]
        ];
        
        $this->inventoryUpdater->updateInventoryFromReport($criticalData, 'TEST_REPORT_NOTIFICATIONS');
        
        // Generate alerts
        $alertResult = $this->alertManager->generateCriticalStockAlerts();
        $this->assertGreaterThan(0, $alertResult['total_alerts']);
        
        // Test notification sending (will be simulated)
        $notificationResult = $this->alertManager->sendStockAlertNotifications($alertResult['alerts']);
        $this->assertTrue($notificationResult);
        
        // Verify alert history
        $history = $this->alertManager->getStockAlertHistory(1);
        $this->assertGreaterThan(0, $history['total_alerts']);
        
        // Test alert acknowledgment
        $alertId = $history['alerts'][0]['id'];
        $ackResult = $this->alertManager->acknowledgeAlert($alertId, 'test_user', 'Test acknowledgment');
        $this->assertTrue($ackResult);
        
        // Verify acknowledgment
        $stmt = $this->testPdo->prepare("SELECT status, acknowledged_by FROM replenishment_alerts WHERE id = ?");
        $stmt->execute([$alertId]);
        $alert = $stmt->fetch(PDO::FETCH_ASSOC);
        $this->assertEquals('ACKNOWLEDGED', $alert['status']);
        $this->assertEquals('test_user', $alert['acknowledged_by']);
    }
    
    /**
     * Test performance with realistic data volumes
     */
    public function testPerformanceWithRealisticDataVolumes(): void {
        $startTime = microtime(true);
        
        // Create realistic dataset (1000 records across multiple warehouses)
        $realisticDataset = [];
        $warehouses = ['Хоругвино', 'Тверь', 'Екатеринбург', 'Новосибирск', 'Казань'];
        
        for ($i = 1; $i <= 1000; $i++) {
            $realisticDataset[] = [
                'product_id' => ($i % 5) + 1,
                'warehouse_name' => $warehouses[$i % 5],
                'source' => 'Ozon',
                'quantity_present' => rand(0, 500),
                'quantity_reserved' => rand(0, 50)
            ];
        }
        
        // Process dataset
        $result = $this->inventoryUpdater->updateInventoryFromReport($realisticDataset, 'TEST_REPORT_PERFORMANCE');
        
        $endTime = microtime(true);
        $processingTime = $endTime - $startTime;
        
        // Verify results
        $this->assertTrue($result['success']);
        $this->assertEquals(1000, $result['processed_count']);
        
        // Performance assertions (should process 1000 records in reasonable time)
        $this->assertLessThan(30, $processingTime, 'Processing should complete within 30 seconds');
        
        // Verify memory usage was reasonable
        $stats = $this->inventoryUpdater->getCurrentStats();
        $this->assertArrayHasKey('duration', $stats);
        
        echo "\nPerformance Test Results:\n";
        echo "- Processed {$result['processed_count']} records in " . round($processingTime, 2) . " seconds\n";
        echo "- Average: " . round($result['processed_count'] / $processingTime, 2) . " records/second\n";
        echo "- Inserted: {$result['inserted_count']}, Updated: {$result['updated_count']}, Errors: {$result['errors_count']}\n";
    }
}