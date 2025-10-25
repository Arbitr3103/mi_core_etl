<?php
/**
 * Unit Tests for DataValidator
 * 
 * Tests for the Data Validator service including validation rules,
 * anomaly detection, quality metrics calculation, and database logging.
 * 
 * Task: 4.2 Создать DataValidator сервис (tests)
 */

require_once __DIR__ . '/../../src/Services/DataValidator.php';

class DataValidatorTest extends PHPUnit\Framework\TestCase {
    private DataValidator $validator;
    private PDO $mockPdo;
    
    protected function setUp(): void {
        // Create mock PDO for testing
        $this->mockPdo = new PDO('sqlite::memory:');
        
        // Create test tables
        $this->mockPdo->exec("
            CREATE TABLE data_quality_log (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                validation_batch_id VARCHAR(255) NOT NULL,
                warehouse_name VARCHAR(255),
                product_id INTEGER,
                sku VARCHAR(255),
                issue_type VARCHAR(100) NOT NULL,
                issue_description TEXT,
                validation_status VARCHAR(50) NOT NULL,
                resolution_action VARCHAR(100),
                quality_score INTEGER,
                validated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                resolved_at DATETIME,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP
            )
        ");
        
        $this->mockPdo->exec("
            CREATE TABLE validation_batches (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                validation_batch_id VARCHAR(255) UNIQUE NOT NULL,
                source_batch_id VARCHAR(255),
                total_records INTEGER DEFAULT 0,
                valid_records INTEGER DEFAULT 0,
                invalid_records INTEGER DEFAULT 0,
                warnings INTEGER DEFAULT 0,
                anomalies INTEGER DEFAULT 0,
                quality_score DECIMAL(5,2) DEFAULT 0,
                execution_time_ms INTEGER DEFAULT 0,
                status VARCHAR(50) DEFAULT 'completed',
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP
            )
        ");
        
        $this->validator = new DataValidator($this->mockPdo);
    }
    
    public function testValidRecordValidation(): void {
        $validRecord = [
            'sku' => 'TEST_SKU_001',
            'warehouse_name' => 'РФЦ Москва',
            'available_stock' => 100,
            'reserved_stock' => 20,
            'total_stock' => 120,
            'product_name' => 'Test Product',
            'category' => 'Electronics',
            'brand' => 'Test Brand',
            'price' => 1999.99,
            'currency' => 'RUB',
            'updated_at' => date('Y-m-d H:i:s')
        ];
        
        $result = $this->validator->validateRecord($validRecord, 0, 'test_batch_001');
        
        $this->assertTrue($result->isValid());
        $this->assertEquals(DataValidator::STATUS_PASSED, $result->getStatus());
        $this->assertEmpty($result->getIssues());
        $this->assertFalse($result->hasAnomalies());
    }
    
    public function testInvalidRecordValidation(): void {
        $invalidRecord = [
            'sku' => '', // Missing required field
            'warehouse_name' => '', // Missing required field
            'available_stock' => -10, // Negative stock
            'reserved_stock' => -5, // Negative stock
            'total_stock' => -15, // Negative stock
            'price' => -100 // Negative price
        ];
        
        $result = $this->validator->validateRecord($invalidRecord, 0, 'test_batch_002');
        
        $this->assertFalse($result->isValid());
        $this->assertEquals(DataValidator::STATUS_FAILED, $result->getStatus());
        $this->assertNotEmpty($result->getIssues());
    }
    
    public function testStockValidationRules(): void {
        $recordWithStockIssues = [
            'sku' => 'TEST_SKU_002',
            'warehouse_name' => 'РФЦ СПб',
            'available_stock' => 50,
            'reserved_stock' => 30,
            'total_stock' => 60 // Available + Reserved (80) > Total (60)
        ];
        
        $result = $this->validator->validateRecord($recordWithStockIssues, 0, 'test_batch_003');
        
        $this->assertTrue($result->isValid()); // Should be valid but with warnings
        $this->assertEquals(DataValidator::STATUS_WARNING, $result->getStatus());
        
        $issues = $result->getIssues();
        $stockIssue = array_filter($issues, fn($issue) => $issue['rule'] === 'stock_values');
        $this->assertNotEmpty($stockIssue);
    }
    
    public function testBatchValidation(): void {
        $testData = [
            [
                'sku' => 'VALID_SKU_001',
                'warehouse_name' => 'РФЦ Москва',
                'available_stock' => 100,
                'reserved_stock' => 20,
                'total_stock' => 120,
                'product_name' => 'Valid Product 1',
                'price' => 1500.00
            ],
            [
                'sku' => 'VALID_SKU_002',
                'warehouse_name' => 'РФЦ СПб',
                'available_stock' => 50,
                'reserved_stock' => 10,
                'total_stock' => 60,
                'product_name' => 'Valid Product 2',
                'price' => 2500.00
            ],
            [
                'sku' => '', // Invalid - missing SKU
                'warehouse_name' => 'РФЦ Екатеринбург',
                'available_stock' => -10, // Invalid - negative stock
                'reserved_stock' => 5,
                'total_stock' => 15
            ]
        ];
        
        $result = $this->validator->validateBatch($testData, 'test_batch_004');
        
        $this->assertInstanceOf(ValidationResult::class, $result);
        
        $results = $result->getResults();
        $this->assertEquals(3, $results['total_records']);
        $this->assertEquals(2, $results['valid_records']);
        $this->assertEquals(1, $results['invalid_records']);
        
        $this->assertGreaterThan(0, $result->getQualityScore());
        $this->assertGreaterThan(0, $result->getExecutionTime());
    }
    
    public function testAnomalyDetection(): void {
        $dataWithAnomalies = [
            [
                'sku' => 'NORMAL_SKU',
                'warehouse_name' => 'РФЦ Москва',
                'available_stock' => 100,
                'price' => 1500.00
            ],
            [
                'sku' => 'EXTREME_STOCK_SKU',
                'warehouse_name' => 'РФЦ СПб',
                'available_stock' => 2000000, // Extreme stock level
                'price' => 2000.00
            ],
            [
                'sku' => 'ZERO_AVAILABLE_SKU',
                'warehouse_name' => 'РФЦ Екатеринбург',
                'available_stock' => 0,
                'reserved_stock' => 50, // Zero available but has reserved
                'price' => 1800.00
            ],
            [
                'sku' => 'SUSPICIOUS_PRICE_SKU',
                'warehouse_name' => 'РФЦ Новосибирск',
                'available_stock' => 75,
                'price' => 0.50 // Suspicious low price
            ]
        ];
        
        $anomalies = $this->validator->detectAnomalies($dataWithAnomalies);
        
        $this->assertNotEmpty($anomalies);
        
        // Check for extreme stock anomaly
        $extremeStockAnomalies = array_filter($anomalies, fn($a) => $a['type'] === 'stock_outlier');
        $this->assertNotEmpty($extremeStockAnomalies);
        
        // Check for zero stock with reserved anomaly
        $zeroStockAnomalies = array_filter($anomalies, fn($a) => $a['sku'] === 'ZERO_AVAILABLE_SKU');
        $this->assertNotEmpty($zeroStockAnomalies);
        
        // Check for suspicious price anomaly
        $priceAnomalies = array_filter($anomalies, fn($a) => $a['sku'] === 'SUSPICIOUS_PRICE_SKU');
        $this->assertNotEmpty($priceAnomalies);
    }
    
    public function testQualityMetricsCalculation(): void {
        $testData = [
            [
                'sku' => 'COMPLETE_RECORD',
                'warehouse_name' => 'РФЦ Москва',
                'available_stock' => 100,
                'reserved_stock' => 20,
                'total_stock' => 120,
                'product_name' => 'Complete Product',
                'category' => 'Electronics',
                'brand' => 'Test Brand',
                'price' => 1999.99,
                'updated_at' => date('Y-m-d H:i:s')
            ],
            [
                'sku' => 'INCOMPLETE_RECORD',
                'warehouse_name' => 'РФЦ СПб',
                'available_stock' => 50,
                // Missing optional fields
                'updated_at' => date('Y-m-d H:i:s', time() - 7200) // 2 hours old
            ]
        ];
        
        $metrics = $this->validator->calculateQualityMetrics($testData);
        
        $this->assertArrayHasKey('completeness', $metrics);
        $this->assertArrayHasKey('accuracy', $metrics);
        $this->assertArrayHasKey('consistency', $metrics);
        $this->assertArrayHasKey('freshness', $metrics);
        $this->assertArrayHasKey('validity', $metrics);
        $this->assertArrayHasKey('overall_score', $metrics);
        
        // Completeness should be between 0 and 100
        $this->assertGreaterThanOrEqual(0, $metrics['completeness']);
        $this->assertLessThanOrEqual(100, $metrics['completeness']);
        
        // API data accuracy should be 100%
        $this->assertEquals(100, $metrics['accuracy']);
        
        // Overall score should be calculated
        $this->assertGreaterThan(0, $metrics['overall_score']);
        $this->assertLessThanOrEqual(100, $metrics['overall_score']);
    }
    
    public function testStatisticalCalculations(): void {
        $reflection = new ReflectionClass($this->validator);
        $method = $reflection->getMethod('calculateStatistics');
        $method->setAccessible(true);
        
        $values = [1, 2, 3, 4, 5, 6, 7, 8, 9, 10];
        $stats = $method->invoke($this->validator, $values);
        
        $this->assertEquals(5.5, $stats['mean']);
        $this->assertEquals(5.5, $stats['median']);
        $this->assertEquals(1, $stats['min']);
        $this->assertEquals(10, $stats['max']);
        $this->assertEquals(10, $stats['count']);
        $this->assertGreaterThan(0, $stats['std']);
    }
    
    public function testOutlierDetection(): void {
        $reflection = new ReflectionClass($this->validator);
        $calculateStatsMethod = $reflection->getMethod('calculateStatistics');
        $isOutlierMethod = $reflection->getMethod('isOutlier');
        $calculateStatsMethod->setAccessible(true);
        $isOutlierMethod->setAccessible(true);
        
        $values = [10, 12, 14, 15, 16, 18, 20, 22, 24, 100]; // 100 is an outlier
        $stats = $calculateStatsMethod->invoke($this->validator, $values);
        
        $this->assertFalse($isOutlierMethod->invoke($this->validator, 15, $stats)); // Normal value
        $this->assertTrue($isOutlierMethod->invoke($this->validator, 100, $stats)); // Outlier
    }
    
    public function testBusinessLogicValidation(): void {
        $reflection = new ReflectionClass($this->validator);
        $method = $reflection->getMethod('getBusinessLogicIssues');
        $method->setAccessible(true);
        
        // Test record with business logic issues
        $problematicRecord = [
            'sku' => 'TEST_SKU',
            'warehouse_name' => 'Test Warehouse',
            'available_stock' => 150, // Available > Total
            'reserved_stock' => 20,
            'total_stock' => 100, // Total < Available
            'product_name' => '123' // Suspicious product name (only numbers)
        ];
        
        $issues = $method->invoke($this->validator, $problematicRecord);
        
        $this->assertNotEmpty($issues);
        $this->assertContains('Available stock exceeds total stock', $issues);
        $this->assertContains('Suspicious product name format', $issues);
    }
    
    public function testValidationLogging(): void {
        $testData = [
            [
                'sku' => 'LOG_TEST_SKU',
                'warehouse_name' => 'РФЦ Москва',
                'available_stock' => -10, // This will cause validation failure
                'reserved_stock' => 5,
                'total_stock' => 15
            ]
        ];
        
        $result = $this->validator->validateBatch($testData, 'log_test_batch');
        
        // Check that validation batch was logged
        $stmt = $this->mockPdo->query("SELECT COUNT(*) FROM validation_batches");
        $batchCount = $stmt->fetchColumn();
        $this->assertGreaterThan(0, $batchCount);
        
        // Check that quality issues were logged
        $stmt = $this->mockPdo->query("SELECT COUNT(*) FROM data_quality_log");
        $logCount = $stmt->fetchColumn();
        $this->assertGreaterThan(0, $logCount);
        
        // Verify logged data
        $stmt = $this->mockPdo->query("
            SELECT validation_batch_id, total_records, valid_records, invalid_records 
            FROM validation_batches 
            ORDER BY created_at DESC 
            LIMIT 1
        ");
        $loggedBatch = $stmt->fetch(PDO::FETCH_ASSOC);
        
        $this->assertEquals(1, $loggedBatch['total_records']);
        $this->assertEquals(0, $loggedBatch['valid_records']);
        $this->assertEquals(1, $loggedBatch['invalid_records']);
    }
    
    public function testValidationStatistics(): void {
        // Insert some test validation data
        $this->mockPdo->exec("
            INSERT INTO validation_batches (
                validation_batch_id, source_batch_id, total_records, 
                valid_records, invalid_records, warnings, anomalies, 
                quality_score, execution_time_ms
            ) VALUES 
            ('test_batch_1', 'source_1', 100, 95, 5, 3, 2, 95.5, 1500),
            ('test_batch_2', 'source_2', 200, 190, 10, 8, 5, 92.3, 2800),
            ('test_batch_3', 'source_3', 150, 145, 5, 2, 1, 97.1, 1200)
        ");
        
        $stats = $this->validator->getValidationStatistics(7);
        
        $this->assertArrayHasKey('total_batches', $stats);
        $this->assertArrayHasKey('avg_quality_score', $stats);
        $this->assertArrayHasKey('total_records_validated', $stats);
        $this->assertArrayHasKey('total_valid_records', $stats);
        $this->assertArrayHasKey('avg_execution_time', $stats);
        
        $this->assertEquals(3, $stats['total_batches']);
        $this->assertEquals(450, $stats['total_records_validated']); // 100 + 200 + 150
        $this->assertEquals(430, $stats['total_valid_records']); // 95 + 190 + 145
        $this->assertGreaterThan(90, $stats['avg_quality_score']);
    }
    
    public function testEmptyDataHandling(): void {
        $result = $this->validator->validateBatch([], 'empty_batch');
        
        $this->assertInstanceOf(ValidationResult::class, $result);
        $this->assertEquals(0, $result->getResults()['total_records']);
        $this->assertEquals(0, $result->getQualityScore());
        $this->assertTrue($result->isValid()); // Empty batch is technically valid
    }
    
    public function testValidationResultMethods(): void {
        $testData = [
            [
                'sku' => 'TEST_SKU_001',
                'warehouse_name' => 'РФЦ Москва',
                'available_stock' => 100,
                'reserved_stock' => 20,
                'total_stock' => 120
            ],
            [
                'sku' => '', // Invalid record
                'warehouse_name' => 'РФЦ СПб',
                'available_stock' => -10
            ]
        ];
        
        $result = $this->validator->validateBatch($testData, 'method_test_batch');
        
        $this->assertFalse($result->isValid()); // Has invalid records
        $this->assertGreaterThan(0, $result->getExecutionTime());
        $this->assertNotEmpty($result->getQualityMetrics());
        
        $validationBatchId = $result->getValidationBatchId();
        $this->assertStringStartsWith('validation_', $validationBatchId);
    }
    
    public function testRecordValidationResultMethods(): void {
        $validRecord = [
            'sku' => 'TEST_SKU_001',
            'warehouse_name' => 'РФЦ Москва',
            'available_stock' => 100,
            'reserved_stock' => 20,
            'total_stock' => 120
        ];
        
        $result = $this->validator->validateRecord($validRecord, 0, 'test_batch');
        
        $this->assertInstanceOf(RecordValidationResult::class, $result);
        $this->assertEquals(0, $result->getIndex());
        $this->assertEquals($validRecord, $result->getRecord());
        $this->assertTrue($result->isValid());
        $this->assertFalse($result->hasAnomalies());
        $this->assertFalse($result->hasQualityIssues());
    }
    
    protected function tearDown(): void {
        $this->mockPdo = null;
        $this->validator = null;
    }
}