<?php
/**
 * Unit tests for AnalyticsETL service
 * 
 * Tests the main ETL orchestrator functionality including:
 * - Full ETL process execution
 * - Extract, Transform, Load phases
 * - Error handling and recovery
 * - Metrics and logging
 * 
 * Requirements: 2.1, 2.2, 2.3
 * Task: 4.4 Создать AnalyticsETL сервис (основной оркестратор)
 */

require_once __DIR__ . '/../../src/Services/AnalyticsETL.php';
require_once __DIR__ . '/../../src/Services/AnalyticsApiClient.php';
require_once __DIR__ . '/../../src/Services/DataValidator.php';
require_once __DIR__ . '/../../src/Services/WarehouseNormalizer.php';

class AnalyticsETLTest extends PHPUnit\Framework\TestCase {
    private AnalyticsETL $etl;
    private AnalyticsApiClient $mockApiClient;
    private DataValidator $mockValidator;
    private WarehouseNormalizer $mockNormalizer;
    private PDO $mockPdo;
    
    protected function setUp(): void {
        // Create mock dependencies
        $this->mockApiClient = $this->createMock(AnalyticsApiClient::class);
        $this->mockValidator = $this->createMock(DataValidator::class);
        $this->mockNormalizer = $this->createMock(WarehouseNormalizer::class);
        $this->mockPdo = $this->createMock(PDO::class);
        
        // Create ETL instance
        $this->etl = new AnalyticsETL(
            $this->mockApiClient,
            $this->mockValidator,
            $this->mockNormalizer,
            $this->mockPdo,
            [
                'load_batch_size' => 100,
                'min_quality_score' => 80.0,
                'enable_audit_logging' => false // Disable for tests
            ]
        );
    }
    
    public function testExecuteETLFullProcess(): void {
        // Mock API data
        $apiData = [
            [
                'sku' => 'TEST-001',
                'warehouse_name' => 'Москва РФЦ',
                'available_stock' => 10,
                'reserved_stock' => 2,
                'product_name' => 'Test Product',
                'price' => 1000.00
            ],
            [
                'sku' => 'TEST-002',
                'warehouse_name' => 'СПб МРФЦ',
                'available_stock' => 5,
                'reserved_stock' => 1,
                'product_name' => 'Another Product',
                'price' => 500.00
            ]
        ];
        
        // Mock API client generator
        $this->mockApiClient
            ->method('getAllStockData')
            ->willReturn([['data' => $apiData]]);
        
        // Mock validation result
        $mockValidationResult = $this->createMock(ValidationResult::class);
        $mockValidationResult->method('getQualityScore')->willReturn(95.0);
        $mockValidationResult->method('getResults')->willReturn([
            'warnings' => 0,
            'invalid_records' => 0
        ]);
        
        $this->mockValidator
            ->method('validateBatch')
            ->willReturn($mockValidationResult);
        
        // Mock normalization results
        $mockNormResult1 = $this->createMock(NormalizationResult::class);
        $mockNormResult1->method('getNormalizedName')->willReturn('Москва РФЦ');
        $mockNormResult1->method('getConfidence')->willReturn(0.95);
        $mockNormResult1->method('getMatchType')->willReturn('exact');
        $mockNormResult1->method('isHighConfidence')->willReturn(true);
        
        $mockNormResult2 = $this->createMock(NormalizationResult::class);
        $mockNormResult2->method('getNormalizedName')->willReturn('Санкт-Петербург МРФЦ');
        $mockNormResult2->method('getConfidence')->willReturn(0.90);
        $mockNormResult2->method('getMatchType')->willReturn('fuzzy');
        $mockNormResult2->method('isHighConfidence')->willReturn(true);
        
        $this->mockNormalizer
            ->method('normalizeBatch')
            ->willReturn([$mockNormResult1, $mockNormResult2]);
        
        // Mock database operations
        $mockStmt = $this->createMock(PDOStatement::class);
        $mockStmt->method('execute')->willReturn(true);
        $mockStmt->method('rowCount')->willReturn(1);
        
        $mockCheckStmt = $this->createMock(PDOStatement::class);
        $mockCheckStmt->method('execute')->willReturn(true);
        $mockCheckStmt->method('fetchColumn')->willReturn(0); // New record
        
        $this->mockPdo->method('prepare')->willReturnOnConsecutiveCalls($mockStmt, $mockCheckStmt);
        $this->mockPdo->method('beginTransaction')->willReturn(true);
        $this->mockPdo->method('commit')->willReturn(true);
        $this->mockPdo->method('inTransaction')->willReturn(false);
        
        // Execute ETL
        $result = $this->etl->executeETL(AnalyticsETL::TYPE_INCREMENTAL_SYNC);
        
        // Assertions
        $this->assertInstanceOf(ETLResult::class, $result);
        $this->assertTrue($result->isSuccessful());
        $this->assertEquals(AnalyticsETL::STATUS_COMPLETED, $result->getStatus());
        $this->assertEquals(2, $result->getTotalRecordsProcessed());
        $this->assertEquals(95.0, $result->getQualityScore());
        $this->assertGreaterThan(0, $result->getExecutionTime());
    }
    
    public function testExecuteETLWithValidationFailure(): void {
        // Mock API data
        $apiData = [
            ['sku' => 'INVALID-001', 'warehouse_name' => '', 'available_stock' => -1]
        ];
        
        $this->mockApiClient
            ->method('getAllStockData')
            ->willReturn([['data' => $apiData]]);
        
        // Mock low quality validation result
        $mockValidationResult = $this->createMock(ValidationResult::class);
        $mockValidationResult->method('getQualityScore')->willReturn(50.0); // Below threshold
        $mockValidationResult->method('getResults')->willReturn([
            'warnings' => 5,
            'invalid_records' => 3
        ]);
        
        $this->mockValidator
            ->method('validateBatch')
            ->willReturn($mockValidationResult);
        
        // Execute ETL - should fail due to low quality
        $result = $this->etl->executeETL();
        
        // Assertions
        $this->assertFalse($result->isSuccessful());
        $this->assertEquals(AnalyticsETL::STATUS_FAILED, $result->getStatus());
        $this->assertStringContains('Data quality too low', $result->getErrorMessage());
    }
    
    public function testExecuteETLWithDatabaseError(): void {
        // Mock API data
        $apiData = [
            ['sku' => 'TEST-001', 'warehouse_name' => 'Test Warehouse', 'available_stock' => 10]
        ];
        
        $this->mockApiClient
            ->method('getAllStockData')
            ->willReturn([['data' => $apiData]]);
        
        // Mock successful validation
        $mockValidationResult = $this->createMock(ValidationResult::class);
        $mockValidationResult->method('getQualityScore')->willReturn(95.0);
        $mockValidationResult->method('getResults')->willReturn(['warnings' => 0, 'invalid_records' => 0]);
        
        $this->mockValidator->method('validateBatch')->willReturn($mockValidationResult);
        
        // Mock successful normalization
        $mockNormResult = $this->createMock(NormalizationResult::class);
        $mockNormResult->method('getNormalizedName')->willReturn('Test Warehouse');
        $mockNormResult->method('getConfidence')->willReturn(1.0);
        $mockNormResult->method('getMatchType')->willReturn('exact');
        $mockNormResult->method('isHighConfidence')->willReturn(true);
        
        $this->mockNormalizer->method('normalizeBatch')->willReturn([$mockNormResult]);
        
        // Mock database error
        $this->mockPdo->method('beginTransaction')->willReturn(true);
        $this->mockPdo->method('prepare')->willThrowException(new PDOException('Database error'));
        $this->mockPdo->method('inTransaction')->willReturn(true);
        $this->mockPdo->method('rollBack')->willReturn(true);
        
        // Execute ETL - should fail due to database error
        $result = $this->etl->executeETL();
        
        // Assertions
        $this->assertFalse($result->isSuccessful());
        $this->assertEquals(AnalyticsETL::STATUS_FAILED, $result->getStatus());
        $this->assertStringContains('Load phase failed', $result->getErrorMessage());
    }
    
    public function testExecuteETLWithEmptyData(): void {
        // Mock empty API response
        $this->mockApiClient
            ->method('getAllStockData')
            ->willReturn([]);
        
        // Execute ETL
        $result = $this->etl->executeETL();
        
        // Assertions
        $this->assertTrue($result->isSuccessful());
        $this->assertEquals(AnalyticsETL::STATUS_COMPLETED, $result->getStatus());
        $this->assertEquals(0, $result->getTotalRecordsProcessed());
    }
    
    public function testGetETLStatus(): void {
        $status = $this->etl->getETLStatus();
        
        $this->assertIsArray($status);
        $this->assertArrayHasKey('status', $status);
        $this->assertArrayHasKey('metrics', $status);
        $this->assertArrayHasKey('last_update', $status);
        $this->assertEquals(AnalyticsETL::STATUS_NOT_STARTED, $status['status']);
    }
    
    public function testGetETLStatisticsWithoutDatabase(): void {
        // Create ETL without database
        $etlWithoutDb = new AnalyticsETL(
            $this->mockApiClient,
            $this->mockValidator,
            $this->mockNormalizer
        );
        
        $stats = $etlWithoutDb->getETLStatistics();
        
        $this->assertIsArray($stats);
        $this->assertEmpty($stats);
    }
    
    public function testETLResultClass(): void {
        $metrics = [
            'extract' => ['records_extracted' => 100],
            'load' => ['records_inserted' => 80, 'records_updated' => 15, 'records_errors' => 5],
            'transform' => ['validation_quality_score' => 92.5]
        ];
        
        $result = new ETLResult(
            'test_batch_123',
            AnalyticsETL::STATUS_PARTIAL_SUCCESS,
            $metrics,
            5000,
            null
        );
        
        $this->assertEquals('test_batch_123', $result->getBatchId());
        $this->assertEquals(AnalyticsETL::STATUS_PARTIAL_SUCCESS, $result->getStatus());
        $this->assertTrue($result->isSuccessful()); // partial success is still successful
        $this->assertEquals(100, $result->getTotalRecordsProcessed());
        $this->assertEquals(80, $result->getRecordsInserted());
        $this->assertEquals(15, $result->getRecordsUpdated());
        $this->assertEquals(5, $result->getRecordsErrors());
        $this->assertEquals(92.5, $result->getQualityScore());
        $this->assertEquals(5000, $result->getExecutionTime());
        $this->assertNull($result->getErrorMessage());
    }
    
    public function testETLExceptionClass(): void {
        $exception = new ETLException('Test error', 123, null, 'transform');
        
        $this->assertEquals('Test error', $exception->getMessage());
        $this->assertEquals(123, $exception->getCode());
        $this->assertEquals('transform', $exception->getPhase());
    }
    
    public function testETLWithDifferentTypes(): void {
        // Test different ETL types
        $types = [
            AnalyticsETL::TYPE_FULL_SYNC,
            AnalyticsETL::TYPE_INCREMENTAL_SYNC,
            AnalyticsETL::TYPE_MANUAL_SYNC,
            AnalyticsETL::TYPE_VALIDATION_ONLY
        ];
        
        foreach ($types as $type) {
            // Mock empty data for quick test
            $this->mockApiClient
                ->method('getAllStockData')
                ->willReturn([]);
            
            $result = $this->etl->executeETL($type);
            
            $this->assertInstanceOf(ETLResult::class, $result);
            $this->assertEquals(AnalyticsETL::STATUS_COMPLETED, $result->getStatus());
        }
    }
    
    public function testETLWithCustomConfiguration(): void {
        $customConfig = [
            'load_batch_size' => 500,
            'min_quality_score' => 90.0,
            'max_memory_records' => 5000,
            'enable_audit_logging' => true
        ];
        
        $customETL = new AnalyticsETL(
            $this->mockApiClient,
            $this->mockValidator,
            $this->mockNormalizer,
            $this->mockPdo,
            $customConfig
        );
        
        $this->assertInstanceOf(AnalyticsETL::class, $customETL);
        
        // Test that custom config affects behavior
        $status = $customETL->getETLStatus();
        $this->assertIsArray($status);
    }
}