<?php
/**
 * Performance Tests for Ozon Stock Reports System
 * 
 * Validates system performance under various load conditions and
 * ensures the system meets performance requirements for production use.
 */

require_once __DIR__ . '/../../src/classes/CSVReportProcessor.php';
require_once __DIR__ . '/../../src/classes/InventoryDataUpdater.php';
require_once __DIR__ . '/../../src/classes/StockAlertManager.php';

class OzonStockReportsPerformanceTest extends PHPUnit\Framework\TestCase {
    
    private $testPdo;
    private $csvProcessor;
    private $inventoryUpdater;
    private $alertManager;
    private $testDatabaseName = 'test_ozon_performance';
    private $performanceResults = [];
    
    protected function setUp(): void {
        $this->createTestDatabase();
        $this->setupPerformanceSchema();
        $this->insertPerformanceTestData();
        
        $this->csvProcessor = new CSVReportProcessor($this->testPdo);
        $this->inventoryUpdater = new InventoryDataUpdater($this->testPdo, 500); // Larger batch for performance
        $this->alertManager = new StockAlertManager($this->testPdo);
        
        $this->performanceResults = [];
    }
    
    protected function tearDown(): void {
        if ($this->testPdo) {
            $this->testPdo->exec("DROP DATABASE IF EXISTS {$this->testDatabaseName}");
            $this->testPdo = null;
        }
        
        // Output performance results
        if (!empty($this->performanceResults)) {
            echo "\n" . str_repeat("=", 60) . "\n";
            echo "PERFORMANCE TEST RESULTS\n";
            echo str_repeat("=", 60) . "\n";
            foreach ($this->performanceResults as $test => $metrics) {
                echo "\n{$test}:\n";
                foreach ($metrics as $metric => $value) {
                    echo "  {$metric}: {$value}\n";
                }
            }
            echo str_repeat("=", 60) . "\n";
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
    
    private function setupPerformanceSchema(): void {
        // Optimized schema for performance testing
        $this->testPdo->exec("
            CREATE TABLE dim_products (
                id INT AUTO_INCREMENT PRIMARY KEY,
                sku VARCHAR(255) NOT NULL,
                sku_ozon VARCHAR(255),
                product_name VARCHAR(500),
                
                UNIQUE KEY idx_sku (sku),
                INDEX idx_sku_ozon (sku_ozon)
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
                report_source ENUM('API_DIRECT', 'API_REPORTS') DEFAULT 'API_REPORTS',
                report_code VARCHAR(255) NULL,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                
                UNIQUE KEY unique_inventory (product_id, warehouse_name, source),
                FOREIGN KEY (product_id) REFERENCES dim_products(id) ON DELETE CASCADE,
                INDEX idx_warehouse_source (warehouse_name, source),
                INDEX idx_report_code (report_code),
                INDEX idx_updated_at (updated_at)
            ) ENGINE=InnoDB
        ");
        
        $this->testPdo->exec("
            CREATE TABLE stock_movements (
                id INT AUTO_INCREMENT PRIMARY KEY,
                product_id INT NOT NULL,
                warehouse_name VARCHAR(255),
                movement_type ENUM('sale', 'order', 'return', 'adjustment') NOT NULL,
                quantity INT NOT NULL,
                movement_date TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                
                FOREIGN KEY (product_id) REFERENCES dim_products(id) ON DELETE CASCADE,
                INDEX idx_product_date (product_id, movement_date),
                INDEX idx_warehouse_date (warehouse_name, movement_date)
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
                
                FOREIGN KEY (product_id) REFERENCES dim_products(id) ON DELETE CASCADE,
                INDEX idx_alert_level_status (alert_level, status),
                INDEX idx_created_at (created_at)
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
    
    private function insertPerformanceTestData(): void {
        // Insert 10,000 products for performance testing
        $stmt = $this->testPdo->prepare("
            INSERT INTO dim_products (sku, sku_ozon, product_name) 
            VALUES (:sku, :sku_ozon, :product_name)
        ");
        
        for ($i = 1; $i <= 10000; $i++) {
            $sku = str_pad($i, 10, '0', STR_PAD_LEFT);
            $stmt->execute([
                'sku' => $sku,
                'sku_ozon' => $sku,
                'product_name' => "Performance Test Product {$i}"
            ]);
        }
        
        // Insert settings
        $settings = [
            ['category' => 'ANALYSIS', 'setting_key' => 'critical_stockout_threshold', 'setting_value' => '3', 'setting_type' => 'INTEGER'],
            ['category' => 'ANALYSIS', 'setting_key' => 'high_priority_threshold', 'setting_value' => '7', 'setting_type' => 'INTEGER']
        ];
        
        $stmt = $this->testPdo->prepare("
            INSERT INTO replenishment_settings (category, setting_key, setting_value, setting_type) 
            VALUES (:category, :setting_key, :setting_value, :setting_type)
        ");
        
        foreach ($settings as $setting) {
            $stmt->execute($setting);
        }
        
        // Insert sales history for performance testing
        $warehouses = ['Хоругвино', 'Тверь', 'Екатеринбург', 'Новосибирск', 'Казань'];
        $stmt = $this->testPdo->prepare("
            INSERT INTO stock_movements (product_id, warehouse_name, movement_type, quantity, movement_date) 
            VALUES (:product_id, :warehouse_name, :movement_type, :quantity, :movement_date)
        ");
        
        // Insert 50,000 movement records
        for ($i = 1; $i <= 50000; $i++) {
            $productId = rand(1, 10000);
            $warehouse = $warehouses[array_rand($warehouses)];
            $quantity = -rand(1, 5);
            $date = date('Y-m-d H:i:s', strtotime('-' . rand(1, 30) . ' days'));
            
            $stmt->execute([
                'product_id' => $productId,
                'warehouse_name' => $warehouse,
                'movement_type' => 'sale',
                'quantity' => $quantity,
                'movement_date' => $date
            ]);
        }
    }
    
    /**
     * Test CSV parsing performance with large files
     */
    public function testCSVParsingPerformanceWithLargeFiles(): void {
        $testName = 'CSV Parsing Performance';
        $metrics = [];
        
        // Test different file sizes
        $fileSizes = [1000, 5000, 10000, 25000];
        
        foreach ($fileSizes as $size) {
            $csvContent = $this->generatePerformanceCSV($size);
            $csvSizeMB = round(strlen($csvContent) / 1024 / 1024, 2);
            
            $startTime = microtime(true);
            $startMemory = memory_get_usage(true);
            
            $parsedData = $this->csvProcessor->parseWarehouseStockCSV($csvContent);
            
            $endTime = microtime(true);
            $endMemory = memory_get_usage(true);
            
            $parseTime = round($endTime - $startTime, 3);
            $memoryUsed = round(($endMemory - $startMemory) / 1024 / 1024, 2);
            $recordsPerSecond = round(count($parsedData) / ($endTime - $startTime), 2);
            
            $metrics["{$size} records"] = [
                'CSV Size (MB)' => $csvSizeMB,
                'Parse Time (s)' => $parseTime,
                'Memory Used (MB)' => $memoryUsed,
                'Records Parsed' => count($parsedData),
                'Records/Second' => $recordsPerSecond
            ];
            
            // Performance assertions
            $this->assertLessThan(30, $parseTime, "Parsing {$size} records should take less than 30 seconds");
            $this->assertLessThan(100, $memoryUsed, "Memory usage should be less than 100MB for {$size} records");
            $this->assertGreaterThan(100, $recordsPerSecond, "Should parse at least 100 records per second");
        }
        
        $this->performanceResults[$testName] = $metrics;
    }
    
    /**
     * Test inventory update performance with batch processing
     */
    public function testInventoryUpdatePerformanceWithBatchProcessing(): void {
        $testName = 'Inventory Update Performance';
        $metrics = [];
        
        // Test different batch sizes
        $batchSizes = [50, 100, 500, 1000];
        $recordCount = 5000;
        
        foreach ($batchSizes as $batchSize) {
            $this->inventoryUpdater->setBatchSize($batchSize);
            
            // Generate test data
            $inventoryData = $this->generateInventoryData($recordCount);
            
            $startTime = microtime(true);
            $startMemory = memory_get_usage(true);
            
            $result = $this->inventoryUpdater->updateInventoryFromReport(
                $inventoryData, 
                "PERF_TEST_BATCH_{$batchSize}"
            );
            
            $endTime = microtime(true);
            $endMemory = memory_get_usage(true);
            
            $updateTime = round($endTime - $startTime, 3);
            $memoryUsed = round(($endMemory - $startMemory) / 1024 / 1024, 2);
            $recordsPerSecond = round($recordCount / ($endTime - $startTime), 2);
            
            $metrics["Batch Size {$batchSize}"] = [
                'Update Time (s)' => $updateTime,
                'Memory Used (MB)' => $memoryUsed,
                'Records Processed' => $result['processed_count'],
                'Records/Second' => $recordsPerSecond,
                'Success Rate (%)' => round(($result['processed_count'] - $result['errors_count']) / $result['processed_count'] * 100, 2)
            ];
            
            // Performance assertions
            $this->assertTrue($result['success']);
            $this->assertLessThan(60, $updateTime, "Update should complete within 60 seconds");
            $this->assertGreaterThan(50, $recordsPerSecond, "Should process at least 50 records per second");
            
            // Clean up for next test
            $this->testPdo->exec("DELETE FROM inventory WHERE report_code = 'PERF_TEST_BATCH_{$batchSize}'");
        }
        
        $this->performanceResults[$testName] = $metrics;
    }
    
    /**
     * Test alert generation performance with large datasets
     */
    public function testAlertGenerationPerformanceWithLargeDatasets(): void {
        $testName = 'Alert Generation Performance';
        $metrics = [];
        
        // Create different inventory scenarios
        $scenarios = [
            'Small Dataset' => 1000,
            'Medium Dataset' => 5000,
            'Large Dataset' => 10000
        ];
        
        foreach ($scenarios as $scenarioName => $recordCount) {
            // Populate inventory with test data
            $inventoryData = $this->generateInventoryDataWithAlerts($recordCount);
            $this->inventoryUpdater->updateInventoryFromReport($inventoryData, "PERF_ALERT_{$recordCount}");
            
            $startTime = microtime(true);
            $startMemory = memory_get_usage(true);
            
            $alertResult = $this->alertManager->generateCriticalStockAlerts();
            
            $endTime = microtime(true);
            $endMemory = memory_get_usage(true);
            
            $analysisTime = round($endTime - $startTime, 3);
            $memoryUsed = round(($endMemory - $startMemory) / 1024 / 1024, 2);
            $recordsAnalyzed = $recordCount;
            $alertsGenerated = $alertResult['total_alerts'];
            
            $metrics[$scenarioName] = [
                'Analysis Time (s)' => $analysisTime,
                'Memory Used (MB)' => $memoryUsed,
                'Records Analyzed' => $recordsAnalyzed,
                'Alerts Generated' => $alertsGenerated,
                'Records/Second' => round($recordsAnalyzed / $analysisTime, 2)
            ];
            
            // Performance assertions
            $this->assertLessThan(30, $analysisTime, "Alert analysis should complete within 30 seconds");
            $this->assertLessThan(50, $memoryUsed, "Memory usage should be reasonable");
            $this->assertGreaterThan(100, $recordsAnalyzed / $analysisTime, "Should analyze at least 100 records per second");
            
            // Clean up
            $this->testPdo->exec("DELETE FROM inventory WHERE report_code = 'PERF_ALERT_{$recordCount}'");
            $this->testPdo->exec("DELETE FROM replenishment_alerts");
        }
        
        $this->performanceResults[$testName] = $metrics;
    }
    
    /**
     * Test database query performance optimization
     */
    public function testDatabaseQueryPerformanceOptimization(): void {
        $testName = 'Database Query Performance';
        $metrics = [];
        
        // Populate with substantial data
        $inventoryData = $this->generateInventoryData(10000);
        $this->inventoryUpdater->updateInventoryFromReport($inventoryData, 'PERF_QUERY_TEST');
        
        // Test various query patterns
        $queries = [
            'Simple Select' => "SELECT COUNT(*) FROM inventory",
            'Warehouse Filter' => "SELECT * FROM inventory WHERE warehouse_name = 'Хоругвино' LIMIT 1000",
            'Product Join' => "SELECT i.*, p.product_name FROM inventory i JOIN dim_products p ON i.product_id = p.id LIMIT 1000",
            'Complex Analysis' => "
                SELECT 
                    warehouse_name,
                    COUNT(*) as product_count,
                    SUM(quantity_present) as total_stock,
                    AVG(quantity_present) as avg_stock
                FROM inventory i 
                JOIN dim_products p ON i.product_id = p.id 
                WHERE i.source = 'Ozon'
                GROUP BY warehouse_name
            ",
            'Alert Analysis Query' => "
                SELECT 
                    i.product_id,
                    i.warehouse_name,
                    i.quantity_present,
                    COALESCE(sm.avg_daily_sales, 0) as avg_daily_sales
                FROM inventory i
                LEFT JOIN (
                    SELECT 
                        product_id,
                        AVG(ABS(quantity)) as avg_daily_sales
                    FROM stock_movements 
                    WHERE movement_date >= DATE_SUB(NOW(), INTERVAL 30 DAY)
                    AND movement_type = 'sale'
                    GROUP BY product_id
                ) sm ON i.product_id = sm.product_id
                WHERE i.quantity_present < 10
                LIMIT 1000
            "
        ];
        
        foreach ($queries as $queryName => $sql) {
            $startTime = microtime(true);
            
            $stmt = $this->testPdo->prepare($sql);
            $stmt->execute();
            $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            $endTime = microtime(true);
            $queryTime = round(($endTime - $startTime) * 1000, 2); // Convert to milliseconds
            
            $metrics[$queryName] = [
                'Query Time (ms)' => $queryTime,
                'Results Count' => count($results)
            ];
            
            // Performance assertions
            $this->assertLessThan(5000, $queryTime, "{$queryName} should complete within 5 seconds");
        }
        
        $this->performanceResults[$testName] = $metrics;
    }
    
    /**
     * Test concurrent processing performance
     */
    public function testConcurrentProcessingPerformance(): void {
        $testName = 'Concurrent Processing Performance';
        $metrics = [];
        
        // Simulate concurrent ETL processes
        $concurrentReports = 3;
        $recordsPerReport = 2000;
        
        $startTime = microtime(true);
        $startMemory = memory_get_usage(true);
        
        $results = [];
        for ($i = 1; $i <= $concurrentReports; $i++) {
            $inventoryData = $this->generateInventoryData($recordsPerReport);
            $result = $this->inventoryUpdater->updateInventoryFromReport($inventoryData, "CONCURRENT_TEST_{$i}");
            $results[] = $result;
        }
        
        $endTime = microtime(true);
        $endMemory = memory_get_usage(true);
        
        $totalTime = round($endTime - $startTime, 3);
        $memoryUsed = round(($endMemory - $startMemory) / 1024 / 1024, 2);
        $totalRecords = $concurrentReports * $recordsPerReport;
        $throughput = round($totalRecords / $totalTime, 2);
        
        $metrics['Concurrent Processing'] = [
            'Total Time (s)' => $totalTime,
            'Memory Used (MB)' => $memoryUsed,
            'Reports Processed' => $concurrentReports,
            'Total Records' => $totalRecords,
            'Throughput (rec/s)' => $throughput,
            'Success Rate (%)' => round(array_sum(array_column($results, 'processed_count')) / $totalRecords * 100, 2)
        ];
        
        // Performance assertions
        $this->assertLessThan(120, $totalTime, "Concurrent processing should complete within 2 minutes");
        $this->assertGreaterThan(30, $throughput, "Should maintain good throughput under concurrent load");
        
        foreach ($results as $result) {
            $this->assertTrue($result['success']);
        }
        
        $this->performanceResults[$testName] = $metrics;
    }
    
    /**
     * Test memory usage optimization
     */
    public function testMemoryUsageOptimization(): void {
        $testName = 'Memory Usage Optimization';
        $metrics = [];
        
        // Test memory usage with different data sizes
        $dataSizes = [1000, 5000, 10000];
        
        foreach ($dataSizes as $size) {
            $initialMemory = memory_get_usage(true);
            $peakMemory = $initialMemory;
            
            // Set up memory monitoring
            $this->inventoryUpdater->setProgressCallback(function($progress) use (&$peakMemory) {
                $currentMemory = memory_get_usage(true);
                if ($currentMemory > $peakMemory) {
                    $peakMemory = $currentMemory;
                }
            });
            
            // Process data
            $inventoryData = $this->generateInventoryData($size);
            $result = $this->inventoryUpdater->updateInventoryFromReport($inventoryData, "MEMORY_TEST_{$size}");
            
            $finalMemory = memory_get_usage(true);
            $memoryIncrease = round(($peakMemory - $initialMemory) / 1024 / 1024, 2);
            $memoryPerRecord = round($memoryIncrease / $size * 1024, 2); // KB per record
            
            $metrics["{$size} records"] = [
                'Memory Increase (MB)' => $memoryIncrease,
                'Memory per Record (KB)' => $memoryPerRecord,
                'Peak Memory (MB)' => round($peakMemory / 1024 / 1024, 2),
                'Final Memory (MB)' => round($finalMemory / 1024 / 1024, 2)
            ];
            
            // Memory efficiency assertions
            $this->assertLessThan(100, $memoryIncrease, "Memory increase should be less than 100MB for {$size} records");
            $this->assertLessThan(10, $memoryPerRecord, "Memory per record should be less than 10KB");
            
            // Clean up
            $this->testPdo->exec("DELETE FROM inventory WHERE report_code = 'MEMORY_TEST_{$size}'");
            gc_collect_cycles(); // Force garbage collection
        }
        
        $this->performanceResults[$testName] = $metrics;
    }
    
    /**
     * Test system scalability limits
     */
    public function testSystemScalabilityLimits(): void {
        $testName = 'System Scalability Limits';
        $metrics = [];
        
        // Test with increasingly large datasets to find limits
        $scalabilityTests = [
            'Baseline' => 5000,
            'Scale 2x' => 10000,
            'Scale 4x' => 20000,
            'Scale 8x' => 40000
        ];
        
        foreach ($scalabilityTests as $testLevel => $recordCount) {
            $startTime = microtime(true);
            $startMemory = memory_get_usage(true);
            
            try {
                // Generate and process data
                $csvContent = $this->generatePerformanceCSV($recordCount);
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
                
                $result = $this->inventoryUpdater->updateInventoryFromReport($inventoryData, "SCALE_TEST_{$recordCount}");
                
                $endTime = microtime(true);
                $endMemory = memory_get_usage(true);
                
                $totalTime = round($endTime - $startTime, 3);
                $memoryUsed = round(($endMemory - $startMemory) / 1024 / 1024, 2);
                $throughput = round(count($inventoryData) / $totalTime, 2);
                
                $metrics[$testLevel] = [
                    'Records' => $recordCount,
                    'Total Time (s)' => $totalTime,
                    'Memory Used (MB)' => $memoryUsed,
                    'Throughput (rec/s)' => $throughput,
                    'Success' => $result['success'] ? 'Yes' : 'No',
                    'Error Rate (%)' => round($result['errors_count'] / $result['processed_count'] * 100, 2)
                ];
                
                // Scalability assertions
                $this->assertTrue($result['success'], "System should handle {$recordCount} records successfully");
                $this->assertLessThan(600, $totalTime, "Processing should complete within 10 minutes");
                $this->assertLessThan(1000, $memoryUsed, "Memory usage should be reasonable (<1GB)");
                
                // Clean up
                $this->testPdo->exec("DELETE FROM inventory WHERE report_code = 'SCALE_TEST_{$recordCount}'");
                
            } catch (Exception $e) {
                $metrics[$testLevel] = [
                    'Records' => $recordCount,
                    'Error' => $e->getMessage(),
                    'Success' => 'No'
                ];
                
                // If we hit limits, that's valuable information
                break;
            }
        }
        
        $this->performanceResults[$testName] = $metrics;
    }
    
    /**
     * Generate performance test CSV
     */
    private function generatePerformanceCSV(int $recordCount): string {
        $warehouses = ['Хоругвино', 'Тверь', 'Екатеринбург', 'Новосибирск', 'Казань'];
        
        $csv = "SKU,Warehouse_Name,Current_Stock,Reserved_Stock,Available_Stock,Last_Updated\n";
        
        for ($i = 1; $i <= $recordCount; $i++) {
            $sku = str_pad(($i % 10000) + 1, 10, '0', STR_PAD_LEFT);
            $warehouse = $warehouses[array_rand($warehouses)];
            $currentStock = rand(0, 1000);
            $reservedStock = rand(0, min($currentStock, 100));
            $availableStock = $currentStock - $reservedStock;
            $lastUpdated = date('Y-m-d H:i:s');
            
            $csv .= "{$sku},{$warehouse},{$currentStock},{$reservedStock},{$availableStock},{$lastUpdated}\n";
        }
        
        return $csv;
    }
    
    /**
     * Generate inventory data for performance testing
     */
    private function generateInventoryData(int $recordCount): array {
        $warehouses = ['Хоругвино', 'Тверь', 'Екатеринбург', 'Новосибирск', 'Казань'];
        $inventoryData = [];
        
        for ($i = 1; $i <= $recordCount; $i++) {
            $inventoryData[] = [
                'product_id' => ($i % 10000) + 1,
                'warehouse_name' => $warehouses[array_rand($warehouses)],
                'source' => 'Ozon',
                'quantity_present' => rand(0, 500),
                'quantity_reserved' => rand(0, 50)
            ];
        }
        
        return $inventoryData;
    }
    
    /**
     * Generate inventory data that will trigger alerts
     */
    private function generateInventoryDataWithAlerts(int $recordCount): array {
        $warehouses = ['Хоругвино', 'Тверь', 'Екатеринбург', 'Новосибирск', 'Казань'];
        $inventoryData = [];
        
        for ($i = 1; $i <= $recordCount; $i++) {
            // 20% chance of critical/low stock to trigger alerts
            $isCritical = (rand(1, 100) <= 20);
            $stock = $isCritical ? rand(0, 5) : rand(10, 500);
            
            $inventoryData[] = [
                'product_id' => ($i % 10000) + 1,
                'warehouse_name' => $warehouses[array_rand($warehouses)],
                'source' => 'Ozon',
                'quantity_present' => $stock,
                'quantity_reserved' => rand(0, min($stock, 10))
            ];
        }
        
        return $inventoryData;
    }
}