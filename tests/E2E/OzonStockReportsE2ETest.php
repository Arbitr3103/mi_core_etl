<?php
/**
 * End-to-End Tests for Ozon Stock Reports System
 * 
 * Tests complete system behavior with realistic data volumes and scenarios,
 * simulating real-world usage patterns and performance requirements.
 */

require_once __DIR__ . '/../../src/classes/CSVReportProcessor.php';
require_once __DIR__ . '/../../src/classes/InventoryDataUpdater.php';
require_once __DIR__ . '/../../src/classes/StockAlertManager.php';

class OzonStockReportsE2ETest extends PHPUnit\Framework\TestCase {
    
    private $testPdo;
    private $csvProcessor;
    private $inventoryUpdater;
    private $alertManager;
    private $testDatabaseName = 'test_ozon_e2e';
    private $performanceMetrics = [];
    
    protected function setUp(): void {
        $this->createTestDatabase();
        $this->setupCompleteSchema();
        $this->insertRealisticTestData();
        
        $this->csvProcessor = new CSVReportProcessor($this->testPdo);
        $this->inventoryUpdater = new InventoryDataUpdater($this->testPdo, 100);
        $this->alertManager = new StockAlertManager($this->testPdo);
        
        $this->performanceMetrics = [];
    }
    
    protected function tearDown(): void {
        if ($this->testPdo) {
            $this->testPdo->exec("DROP DATABASE IF EXISTS {$this->testDatabaseName}");
            $this->testPdo = null;
        }
        
        // Output performance metrics
        if (!empty($this->performanceMetrics)) {
            echo "\n=== Performance Metrics ===\n";
            foreach ($this->performanceMetrics as $metric => $value) {
                echo "{$metric}: {$value}\n";
            }
            echo "===========================\n";
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
    
    private function setupCompleteSchema(): void {
        // Complete production-like schema
        $this->testPdo->exec("
            CREATE TABLE dim_products (
                id INT AUTO_INCREMENT PRIMARY KEY,
                sku VARCHAR(255) NOT NULL UNIQUE,
                sku_ozon VARCHAR(255),
                sku_wb VARCHAR(255),
                product_name VARCHAR(500),
                category VARCHAR(255),
                brand VARCHAR(255),
                cost_price DECIMAL(10,2),
                retail_price DECIMAL(10,2),
                is_active BOOLEAN DEFAULT TRUE,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                
                INDEX idx_sku_ozon (sku_ozon),
                INDEX idx_sku_wb (sku_wb),
                INDEX idx_category (category),
                INDEX idx_is_active (is_active)
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
                INDEX idx_report_source (report_source),
                INDEX idx_updated_at (updated_at),
                INDEX idx_report_code (report_code)
            ) ENGINE=InnoDB
        ");
        
        $this->testPdo->exec("
            CREATE TABLE stock_movements (
                id INT AUTO_INCREMENT PRIMARY KEY,
                product_id INT NOT NULL,
                warehouse_name VARCHAR(255),
                movement_type ENUM('sale', 'order', 'return', 'adjustment', 'transfer') NOT NULL,
                quantity INT NOT NULL,
                price DECIMAL(10,2),
                movement_date TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                reference_id VARCHAR(255),
                notes TEXT,
                
                FOREIGN KEY (product_id) REFERENCES dim_products(id) ON DELETE CASCADE,
                INDEX idx_product_movement (product_id, movement_date),
                INDEX idx_movement_type (movement_type),
                INDEX idx_warehouse_name (warehouse_name),
                INDEX idx_movement_date (movement_date)
            ) ENGINE=InnoDB
        ");
        
        $this->testPdo->exec("
            CREATE TABLE replenishment_alerts (
                id INT AUTO_INCREMENT PRIMARY KEY,
                product_id INT NOT NULL,
                sku VARCHAR(255),
                product_name VARCHAR(500),
                alert_type ENUM('STOCKOUT_CRITICAL', 'STOCKOUT_WARNING', 'NO_SALES', 'SLOW_MOVING', 'OVERSTOCKED') NOT NULL,
                alert_level ENUM('CRITICAL', 'HIGH', 'MEDIUM', 'LOW') NOT NULL,
                message TEXT NOT NULL,
                current_stock INT,
                days_until_stockout DECIMAL(5,1),
                recommended_action TEXT,
                status ENUM('NEW', 'ACKNOWLEDGED', 'RESOLVED', 'IGNORED') DEFAULT 'NEW',
                acknowledged_by VARCHAR(255),
                acknowledged_at TIMESTAMP NULL,
                resolved_by VARCHAR(255),
                resolved_at TIMESTAMP NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                
                FOREIGN KEY (product_id) REFERENCES dim_products(id) ON DELETE CASCADE,
                INDEX idx_alert_type (alert_type),
                INDEX idx_alert_level (alert_level),
                INDEX idx_status (status),
                INDEX idx_created_at (created_at),
                INDEX idx_product_id (product_id)
            ) ENGINE=InnoDB
        ");
        
        $this->testPdo->exec("
            CREATE TABLE replenishment_settings (
                id INT AUTO_INCREMENT PRIMARY KEY,
                category ENUM('ANALYSIS', 'NOTIFICATIONS', 'GENERAL', 'THRESHOLDS') NOT NULL,
                setting_key VARCHAR(255) NOT NULL,
                setting_value TEXT,
                setting_type ENUM('STRING', 'INTEGER', 'DECIMAL', 'BOOLEAN', 'JSON') NOT NULL,
                description TEXT,
                is_active BOOLEAN DEFAULT TRUE,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                
                UNIQUE KEY unique_setting (category, setting_key),
                INDEX idx_category (category),
                INDEX idx_is_active (is_active)
            ) ENGINE=InnoDB
        ");
        
        $this->testPdo->exec("
            CREATE TABLE ozon_stock_reports (
                id INT AUTO_INCREMENT PRIMARY KEY,
                report_code VARCHAR(255) NOT NULL UNIQUE,
                report_type ENUM('warehouse_stock', 'sales_report', 'returns_report') NOT NULL,
                status ENUM('REQUESTED', 'PROCESSING', 'SUCCESS', 'ERROR', 'TIMEOUT') NOT NULL,
                request_parameters JSON,
                download_url VARCHAR(500) NULL,
                file_size INT NULL,
                records_processed INT DEFAULT 0,
                error_message TEXT NULL,
                requested_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                completed_at TIMESTAMP NULL,
                processed_at TIMESTAMP NULL,
                
                INDEX idx_status (status),
                INDEX idx_requested_at (requested_at),
                INDEX idx_report_type (report_type)
            ) ENGINE=InnoDB
        ");
        
        $this->testPdo->exec("
            CREATE TABLE notification_log (
                id INT AUTO_INCREMENT PRIMARY KEY,
                warehouse_name VARCHAR(255),
                alert_count INT,
                critical_count INT,
                high_count INT,
                notification_type ENUM('email', 'sms', 'webhook') NOT NULL,
                status ENUM('SUCCESS', 'FAILED', 'PENDING') NOT NULL,
                message TEXT,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                
                INDEX idx_warehouse_name (warehouse_name),
                INDEX idx_notification_type (notification_type),
                INDEX idx_status (status),
                INDEX idx_created_at (created_at)
            ) ENGINE=InnoDB
        ");
    }
    
    private function insertRealisticTestData(): void {
        // Insert realistic product catalog (1000 products)
        $categories = ['Electronics', 'Clothing', 'Home & Garden', 'Sports', 'Books', 'Toys', 'Beauty', 'Automotive'];
        $brands = ['Brand A', 'Brand B', 'Brand C', 'Brand D', 'Brand E'];
        $warehouses = ['Хоругвино', 'Тверь', 'Екатеринбург', 'Новосибирск', 'Казань', 'Ростов-на-Дону', 'Краснодар', 'Самара'];
        
        $stmt = $this->testPdo->prepare("
            INSERT INTO dim_products (sku, sku_ozon, product_name, category, brand, cost_price, retail_price) 
            VALUES (:sku, :sku_ozon, :product_name, :category, :brand, :cost_price, :retail_price)
        ");
        
        for ($i = 1; $i <= 1000; $i++) {
            $sku = str_pad($i, 10, '0', STR_PAD_LEFT);
            $category = $categories[array_rand($categories)];
            $brand = $brands[array_rand($brands)];
            $costPrice = rand(50, 500);
            $retailPrice = $costPrice * (1 + rand(20, 100) / 100);
            
            $stmt->execute([
                'sku' => $sku,
                'sku_ozon' => $sku,
                'product_name' => "Product {$i} - {$category}",
                'category' => $category,
                'brand' => $brand,
                'cost_price' => $costPrice,
                'retail_price' => $retailPrice
            ]);
        }
        
        // Insert realistic settings
        $settings = [
            ['category' => 'ANALYSIS', 'setting_key' => 'critical_stockout_threshold', 'setting_value' => '3', 'setting_type' => 'INTEGER'],
            ['category' => 'ANALYSIS', 'setting_key' => 'high_priority_threshold', 'setting_value' => '7', 'setting_type' => 'INTEGER'],
            ['category' => 'ANALYSIS', 'setting_key' => 'slow_moving_threshold_days', 'setting_value' => '30', 'setting_type' => 'INTEGER'],
            ['category' => 'ANALYSIS', 'setting_key' => 'overstocked_threshold_days', 'setting_value' => '90', 'setting_type' => 'INTEGER'],
            ['category' => 'NOTIFICATIONS', 'setting_key' => 'email_enabled', 'setting_value' => 'true', 'setting_type' => 'BOOLEAN'],
            ['category' => 'NOTIFICATIONS', 'setting_key' => 'sms_enabled', 'setting_value' => 'false', 'setting_type' => 'BOOLEAN'],
            ['category' => 'NOTIFICATIONS', 'setting_key' => 'email_recipients', 'setting_value' => '["manager@company.com", "warehouse@company.com"]', 'setting_type' => 'JSON']
        ];
        
        $stmt = $this->testPdo->prepare("
            INSERT INTO replenishment_settings (category, setting_key, setting_value, setting_type) 
            VALUES (:category, :setting_key, :setting_value, :setting_type)
        ");
        
        foreach ($settings as $setting) {
            $stmt->execute($setting);
        }
        
        // Insert realistic sales history (last 60 days)
        $stmt = $this->testPdo->prepare("
            INSERT INTO stock_movements (product_id, warehouse_name, movement_type, quantity, price, movement_date) 
            VALUES (:product_id, :warehouse_name, :movement_type, :quantity, :price, :movement_date)
        ");
        
        for ($day = 60; $day >= 1; $day--) {
            $date = date('Y-m-d H:i:s', strtotime("-{$day} days"));
            
            // Generate 50-200 sales per day
            $salesCount = rand(50, 200);
            for ($sale = 0; $sale < $salesCount; $sale++) {
                $productId = rand(1, 1000);
                $warehouse = $warehouses[array_rand($warehouses)];
                $quantity = -rand(1, 5); // Negative for sales
                $price = rand(100, 1000);
                
                $stmt->execute([
                    'product_id' => $productId,
                    'warehouse_name' => $warehouse,
                    'movement_type' => 'sale',
                    'quantity' => $quantity,
                    'price' => $price,
                    'movement_date' => $date
                ]);
            }
        }
    }
    
    /**
     * Test complete daily ETL workflow with realistic data volumes
     */
    public function testCompleteDailyETLWorkflowWithRealisticVolumes(): void {
        $startTime = microtime(true);
        
        // Generate realistic CSV report (5000 records across 8 warehouses)
        $csvContent = $this->generateRealisticCSVReport(5000);
        $this->performanceMetrics['CSV Size (KB)'] = round(strlen($csvContent) / 1024, 2);
        
        // Step 1: Parse CSV
        $parseStart = microtime(true);
        $parsedData = $this->csvProcessor->parseWarehouseStockCSV($csvContent);
        $parseTime = microtime(true) - $parseStart;
        $this->performanceMetrics['CSV Parse Time (s)'] = round($parseTime, 3);
        $this->performanceMetrics['Records Parsed'] = count($parsedData);
        
        $this->assertGreaterThan(4500, count($parsedData)); // Should parse most records
        
        // Step 2: Normalize and map data
        $normalizeStart = microtime(true);
        $normalizedData = $this->csvProcessor->normalizeWarehouseNames($parsedData);
        $mappedData = $this->csvProcessor->mapProductSKUs($normalizedData);
        $normalizeTime = microtime(true) - $normalizeStart;
        $this->performanceMetrics['Data Normalization Time (s)'] = round($normalizeTime, 3);
        
        // Count successfully mapped records
        $mappedCount = count(array_filter($mappedData, function($record) {
            return $record['sku_mapped'] ?? false;
        }));
        $this->performanceMetrics['Records Mapped'] = $mappedCount;
        $this->assertGreaterThan(4000, $mappedCount); // Most should be mapped
        
        // Step 3: Prepare inventory data
        $inventoryData = [];
        foreach ($mappedData as $record) {
            if ($record['sku_mapped'] ?? false) {
                $inventoryData[] = [
                    'product_id' => $record['product_id'],
                    'warehouse_name' => $record['Warehouse_Name'],
                    'source' => 'Ozon',
                    'quantity_present' => $record['Current_Stock'],
                    'quantity_reserved' => $record['Reserved_Stock']
                ];
            }
        }
        
        // Step 4: Update inventory with progress tracking
        $updateStart = microtime(true);
        $progressCalls = 0;
        $this->inventoryUpdater->setProgressCallback(function($progress) use (&$progressCalls) {
            $progressCalls++;
        });
        
        $updateResult = $this->inventoryUpdater->updateInventoryFromReport($inventoryData, 'E2E_DAILY_REPORT_' . date('Ymd'));
        $updateTime = microtime(true) - $updateStart;
        $this->performanceMetrics['Inventory Update Time (s)'] = round($updateTime, 3);
        $this->performanceMetrics['Progress Callbacks'] = $progressCalls;
        
        $this->assertTrue($updateResult['success']);
        $this->assertEquals(count($inventoryData), $updateResult['processed_count']);
        $this->assertLessThan(100, $updateResult['errors_count']); // Less than 2% error rate
        
        // Step 5: Generate stock alerts
        $alertStart = microtime(true);
        $alertResult = $this->alertManager->generateCriticalStockAlerts();
        $alertTime = microtime(true) - $alertStart;
        $this->performanceMetrics['Alert Generation Time (s)'] = round($alertTime, 3);
        $this->performanceMetrics['Alerts Generated'] = $alertResult['total_alerts'];
        
        // Step 6: Send notifications if alerts exist
        if ($alertResult['total_alerts'] > 0) {
            $notificationStart = microtime(true);
            $notificationResult = $this->alertManager->sendStockAlertNotifications($alertResult['alerts']);
            $notificationTime = microtime(true) - $notificationStart;
            $this->performanceMetrics['Notification Time (s)'] = round($notificationTime, 3);
            
            $this->assertTrue($notificationResult);
        }
        
        // Step 7: Generate reconciliation report
        $reconciliationStart = microtime(true);
        $reconciliationReport = $this->inventoryUpdater->generateReconciliationReport('E2E_DAILY_REPORT_' . date('Ymd'));
        $reconciliationTime = microtime(true) - $reconciliationStart;
        $this->performanceMetrics['Reconciliation Time (s)'] = round($reconciliationTime, 3);
        
        $this->assertArrayHasKey('issues', $reconciliationReport);
        $this->assertArrayHasKey('recommendations', $reconciliationReport);
        
        // Overall performance metrics
        $totalTime = microtime(true) - $startTime;
        $this->performanceMetrics['Total ETL Time (s)'] = round($totalTime, 3);
        $this->performanceMetrics['Records/Second'] = round(count($inventoryData) / $totalTime, 2);
        
        // Performance assertions
        $this->assertLessThan(300, $totalTime, 'Complete ETL should finish within 5 minutes');
        $this->assertGreaterThan(10, count($inventoryData) / $totalTime, 'Should process at least 10 records per second');
        
        // Data quality assertions
        $errorRate = ($updateResult['errors_count'] / $updateResult['processed_count']) * 100;
        $this->assertLessThan(5, $errorRate, 'Error rate should be less than 5%');
    }
    
    /**
     * Test system behavior under high load conditions
     */
    public function testSystemBehaviorUnderHighLoadConditions(): void {
        $startTime = microtime(true);
        
        // Simulate high load: multiple concurrent reports
        $reports = [];
        for ($i = 1; $i <= 5; $i++) {
            $csvContent = $this->generateRealisticCSVReport(1000);
            $parsedData = $this->csvProcessor->parseWarehouseStockCSV($csvContent);
            $normalizedData = $this->csvProcessor->normalizeWarehouseNames($parsedData);
            $mappedData = $this->csvProcessor->mapProductSKUs($normalizedData);
            
            $inventoryData = [];
            foreach ($mappedData as $record) {
                if ($record['sku_mapped'] ?? false) {
                    $inventoryData[] = [
                        'product_id' => $record['product_id'],
                        'warehouse_name' => $record['Warehouse_Name'],
                        'source' => 'Ozon',
                        'quantity_present' => $record['Current_Stock'],
                        'quantity_reserved' => $record['Reserved_Stock']
                    ];
                }
            }
            
            $reports[] = $inventoryData;
        }
        
        // Process all reports sequentially (simulating high load)
        $totalProcessed = 0;
        $totalErrors = 0;
        
        foreach ($reports as $index => $inventoryData) {
            $result = $this->inventoryUpdater->updateInventoryFromReport($inventoryData, "E2E_LOAD_TEST_{$index}");
            $this->assertTrue($result['success']);
            $totalProcessed += $result['processed_count'];
            $totalErrors += $result['errors_count'];
        }
        
        $totalTime = microtime(true) - $startTime;
        $this->performanceMetrics['High Load Total Time (s)'] = round($totalTime, 3);
        $this->performanceMetrics['High Load Records Processed'] = $totalProcessed;
        $this->performanceMetrics['High Load Error Rate (%)'] = round(($totalErrors / $totalProcessed) * 100, 2);
        
        // System should handle high load gracefully
        $this->assertLessThan(600, $totalTime, 'High load processing should complete within 10 minutes');
        $this->assertLessThan(10, ($totalErrors / $totalProcessed) * 100, 'Error rate under load should be less than 10%');
        
        // Generate alerts after high load
        $alertResult = $this->alertManager->generateCriticalStockAlerts();
        $this->assertIsArray($alertResult);
    }
    
    /**
     * Test system recovery from various failure scenarios
     */
    public function testSystemRecoveryFromFailureScenarios(): void {
        // Scenario 1: Partial data corruption
        $corruptedCSV = $this->generateCorruptedCSVReport();
        
        try {
            $parsedData = $this->csvProcessor->parseWarehouseStockCSV($corruptedCSV);
            // Should recover by skipping corrupted rows
            $this->assertGreaterThan(0, count($parsedData));
        } catch (Exception $e) {
            // Complete failure is also acceptable for severely corrupted data
            $this->assertStringContainsString('CSV', $e->getMessage());
        }
        
        // Scenario 2: Database constraint violations
        $invalidData = [
            [
                'product_id' => 999999, // Non-existent product
                'warehouse_name' => 'Хоругвино',
                'source' => 'Ozon',
                'quantity_present' => 100,
                'quantity_reserved' => 10
            ]
        ];
        
        try {
            $result = $this->inventoryUpdater->updateInventoryFromReport($invalidData, 'E2E_RECOVERY_TEST');
            $this->fail('Should have thrown exception for invalid data');
        } catch (Exception $e) {
            // Should fail gracefully with proper error message
            $this->assertStringContainsString('update failed', $e->getMessage());
        }
        
        // Scenario 3: Memory pressure simulation
        $this->inventoryUpdater->setBatchSize(10); // Very small batches
        $largeDataset = [];
        for ($i = 1; $i <= 1000; $i++) {
            $largeDataset[] = [
                'product_id' => ($i % 100) + 1,
                'warehouse_name' => 'Хоругвино',
                'source' => 'Ozon',
                'quantity_present' => rand(0, 1000),
                'quantity_reserved' => rand(0, 100)
            ];
        }
        
        $result = $this->inventoryUpdater->updateInventoryFromReport($largeDataset, 'E2E_MEMORY_TEST');
        $this->assertTrue($result['success']);
        $this->assertEquals(1000, $result['processed_count']);
    }
    
    /**
     * Test alert escalation and notification workflows
     */
    public function testAlertEscalationAndNotificationWorkflows(): void {
        // Create critical stock situations
        $criticalStockData = [
            // Stockout situation
            [
                'product_id' => 1,
                'warehouse_name' => 'Хоругвино',
                'source' => 'Ozon',
                'quantity_present' => 0,
                'quantity_reserved' => 0
            ],
            // Critical low stock
            [
                'product_id' => 2,
                'warehouse_name' => 'Тверь',
                'source' => 'Ozon',
                'quantity_present' => 2,
                'quantity_reserved' => 0
            ],
            // High priority stock
            [
                'product_id' => 3,
                'warehouse_name' => 'Екатеринбург',
                'source' => 'Ozon',
                'quantity_present' => 5,
                'quantity_reserved' => 1
            ]
        ];
        
        // Update inventory
        $updateResult = $this->inventoryUpdater->updateInventoryFromReport($criticalStockData, 'E2E_ALERT_TEST');
        $this->assertTrue($updateResult['success']);
        
        // Generate alerts
        $alertResult = $this->alertManager->generateCriticalStockAlerts();
        $this->assertGreaterThan(0, $alertResult['total_alerts']);
        
        // Verify alert levels
        $criticalAlerts = array_filter($alertResult['alerts'], function($alert) {
            return $alert['alert_level'] === 'CRITICAL';
        });
        $this->assertGreaterThan(0, count($criticalAlerts));
        
        // Test notification workflow
        $notificationResult = $this->alertManager->sendStockAlertNotifications($alertResult['alerts']);
        $this->assertTrue($notificationResult);
        
        // Test alert management workflow
        $history = $this->alertManager->getStockAlertHistory(1);
        $this->assertGreaterThan(0, $history['total_alerts']);
        
        // Test alert acknowledgment
        if (!empty($history['alerts'])) {
            $alertId = $history['alerts'][0]['id'];
            $ackResult = $this->alertManager->acknowledgeAlert($alertId, 'e2e_test_user', 'E2E test acknowledgment');
            $this->assertTrue($ackResult);
            
            // Test alert resolution
            $resolveResult = $this->alertManager->resolveAlert($alertId, 'e2e_test_user', 'E2E test resolution');
            $this->assertTrue($resolveResult);
        }
        
        // Test alert metrics
        $metrics = $this->alertManager->getAlertResponseMetrics(1);
        $this->assertArrayHasKey('total_alerts', $metrics);
        $this->assertArrayHasKey('effectiveness_score', $metrics);
    }
    
    /**
     * Test data consistency across multiple update cycles
     */
    public function testDataConsistencyAcrossMultipleUpdateCycles(): void {
        $warehouses = ['Хоругвино', 'Тверь', 'Екатеринбург'];
        $productIds = range(1, 100);
        
        // Cycle 1: Initial data
        $cycle1Data = [];
        foreach ($productIds as $productId) {
            foreach ($warehouses as $warehouse) {
                $cycle1Data[] = [
                    'product_id' => $productId,
                    'warehouse_name' => $warehouse,
                    'source' => 'Ozon',
                    'quantity_present' => rand(50, 200),
                    'quantity_reserved' => rand(5, 20)
                ];
            }
        }
        
        $result1 = $this->inventoryUpdater->updateInventoryFromReport($cycle1Data, 'E2E_CONSISTENCY_CYCLE_1');
        $this->assertTrue($result1['success']);
        
        // Verify initial state
        $stmt = $this->testPdo->prepare("SELECT COUNT(*) as count FROM inventory WHERE report_code = 'E2E_CONSISTENCY_CYCLE_1'");
        $stmt->execute();
        $initialCount = $stmt->fetch(PDO::FETCH_ASSOC)['count'];
        $this->assertEquals(count($cycle1Data), $initialCount);
        
        // Cycle 2: Updated data (some products, some warehouses)
        $cycle2Data = [];
        foreach (array_slice($productIds, 0, 50) as $productId) {
            foreach (array_slice($warehouses, 0, 2) as $warehouse) {
                $cycle2Data[] = [
                    'product_id' => $productId,
                    'warehouse_name' => $warehouse,
                    'source' => 'Ozon',
                    'quantity_present' => rand(20, 150),
                    'quantity_reserved' => rand(2, 15)
                ];
            }
        }
        
        $result2 = $this->inventoryUpdater->updateInventoryFromReport($cycle2Data, 'E2E_CONSISTENCY_CYCLE_2');
        $this->assertTrue($result2['success']);
        
        // Verify updates
        $stmt = $this->testPdo->prepare("
            SELECT COUNT(*) as count 
            FROM inventory 
            WHERE report_code = 'E2E_CONSISTENCY_CYCLE_2'
        ");
        $stmt->execute();
        $updatedCount = $stmt->fetch(PDO::FETCH_ASSOC)['count'];
        $this->assertEquals(count($cycle2Data), $updatedCount);
        
        // Verify data integrity
        $stmt = $this->testPdo->prepare("
            SELECT 
                COUNT(*) as total_records,
                COUNT(DISTINCT product_id) as unique_products,
                COUNT(DISTINCT warehouse_name) as unique_warehouses,
                SUM(CASE WHEN quantity_present < 0 THEN 1 ELSE 0 END) as negative_quantities,
                SUM(CASE WHEN quantity_reserved > quantity_present THEN 1 ELSE 0 END) as invalid_reservations
            FROM inventory
        ");
        $stmt->execute();
        $integrity = $stmt->fetch(PDO::FETCH_ASSOC);
        
        $this->assertEquals(0, $integrity['negative_quantities'], 'No negative quantities should exist');
        $this->assertEquals(0, $integrity['invalid_reservations'], 'Reserved should not exceed present');
        $this->assertGreaterThan(0, $integrity['unique_products']);
        $this->assertGreaterThan(0, $integrity['unique_warehouses']);
    }
    
    /**
     * Test performance with large datasets (stress test)
     */
    public function testPerformanceWithLargeDatasets(): void {
        $startTime = microtime(true);
        $initialMemory = memory_get_usage(true);
        
        // Generate very large CSV (10,000 records)
        $largeCSV = $this->generateRealisticCSVReport(10000);
        $csvSize = strlen($largeCSV);
        
        // Process large dataset
        $parseStart = microtime(true);
        $parsedData = $this->csvProcessor->parseWarehouseStockCSV($largeCSV);
        $parseTime = microtime(true) - $parseStart;
        
        $normalizedData = $this->csvProcessor->normalizeWarehouseNames($parsedData);
        $mappedData = $this->csvProcessor->mapProductSKUs($normalizedData);
        
        $inventoryData = [];
        foreach ($mappedData as $record) {
            if ($record['sku_mapped'] ?? false) {
                $inventoryData[] = [
                    'product_id' => $record['product_id'],
                    'warehouse_name' => $record['Warehouse_Name'],
                    'source' => 'Ozon',
                    'quantity_present' => $record['Current_Stock'],
                    'quantity_reserved' => $record['Reserved_Stock']
                ];
            }
        }
        
        // Update with performance monitoring
        $updateStart = microtime(true);
        $result = $this->inventoryUpdater->updateInventoryFromReport($inventoryData, 'E2E_LARGE_DATASET');
        $updateTime = microtime(true) - $updateStart;
        
        $finalMemory = memory_get_usage(true);
        $totalTime = microtime(true) - $startTime;
        
        // Performance metrics
        $this->performanceMetrics['Large Dataset CSV Size (MB)'] = round($csvSize / 1024 / 1024, 2);
        $this->performanceMetrics['Large Dataset Parse Time (s)'] = round($parseTime, 3);
        $this->performanceMetrics['Large Dataset Update Time (s)'] = round($updateTime, 3);
        $this->performanceMetrics['Large Dataset Total Time (s)'] = round($totalTime, 3);
        $this->performanceMetrics['Large Dataset Memory Usage (MB)'] = round(($finalMemory - $initialMemory) / 1024 / 1024, 2);
        $this->performanceMetrics['Large Dataset Throughput (rec/s)'] = round(count($inventoryData) / $totalTime, 2);
        
        // Performance assertions
        $this->assertTrue($result['success']);
        $this->assertLessThan(600, $totalTime, 'Large dataset processing should complete within 10 minutes');
        $this->assertLessThan(500, ($finalMemory - $initialMemory) / 1024 / 1024, 'Memory usage should be reasonable (<500MB)');
        $this->assertGreaterThan(15, count($inventoryData) / $totalTime, 'Should maintain good throughput (>15 rec/s)');
    }
    
    /**
     * Generate realistic CSV report for testing
     */
    private function generateRealisticCSVReport(int $recordCount): string {
        $warehouses = ['Хоругвино', 'Тверь', 'Екатеринбург', 'Новосибирск', 'Казань', 'Ростов-на-Дону', 'Краснодар', 'Самара'];
        
        $csv = "SKU,Warehouse_Name,Current_Stock,Reserved_Stock,Available_Stock,Last_Updated\n";
        
        for ($i = 1; $i <= $recordCount; $i++) {
            $sku = str_pad(($i % 1000) + 1, 10, '0', STR_PAD_LEFT);
            $warehouse = $warehouses[array_rand($warehouses)];
            $currentStock = rand(0, 500);
            $reservedStock = rand(0, min($currentStock, 50));
            $availableStock = $currentStock - $reservedStock;
            $lastUpdated = date('Y-m-d H:i:s', strtotime('-' . rand(0, 60) . ' minutes'));
            
            $csv .= "{$sku},{$warehouse},{$currentStock},{$reservedStock},{$availableStock},{$lastUpdated}\n";
        }
        
        return $csv;
    }
    
    /**
     * Generate corrupted CSV for error testing
     */
    private function generateCorruptedCSVReport(): string {
        $csv = "SKU,Warehouse_Name,Current_Stock,Reserved_Stock,Available_Stock,Last_Updated\n";
        
        // Add some valid records
        $csv .= "0000000001,Хоругвино,100,10,90,2025-10-23 10:00:00\n";
        $csv .= "0000000002,Тверь,50,5,45,2025-10-23 10:00:00\n";
        
        // Add corrupted records
        $csv .= "INVALID_SKU,Екатеринбург,abc,def,ghi,invalid_date\n";
        $csv .= "0000000003,Новосибирск,-50,10,40,2025-10-23 10:00:00\n"; // Negative stock
        $csv .= "0000000004,,100,10,90,2025-10-23 10:00:00\n"; // Empty warehouse
        $csv .= ",Казань,75,7,68,2025-10-23 10:00:00\n"; // Empty SKU
        
        // Add more valid records
        $csv .= "0000000005,Ростов-на-Дону,200,20,180,2025-10-23 10:00:00\n";
        
        return $csv;
    }
}