<?php
/**
 * Unit Tests for SafeSyncEngine
 * 
 * Tests various error scenarios, batch processing, retry logic,
 * and integration with other components.
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../src/SafeSyncEngine.php';
require_once __DIR__ . '/../src/DataTypeNormalizer.php';
require_once __DIR__ . '/../src/FallbackDataProvider.php';

class SafeSyncEngineTest extends TestCase {
    private $mockDb;
    private $mockLogger;
    private $syncEngine;
    
    protected function setUp(): void {
        // Create mock database connection
        $this->mockDb = $this->createMock(PDO::class);
        
        // Create mock logger
        $this->mockLogger = $this->createMock(SimpleLogger::class);
        
        // Create sync engine with mocks
        $this->syncEngine = new SafeSyncEngine($this->mockDb, $this->mockLogger);
    }
    
    protected function tearDown(): void {
        $this->mockDb = null;
        $this->mockLogger = null;
        $this->syncEngine = null;
    }
    
    /**
     * Test: Successful synchronization of product names
     */
    public function testSyncProductNamesSuccess() {
        // Mock products needing sync
        $mockProducts = [
            [
                'cross_ref_id' => 1,
                'inventory_product_id' => '12345',
                'ozon_product_id' => '12345',
                'sku_ozon' => '12345',
                'sync_status' => 'pending',
                'current_name' => 'Товар Ozon ID 12345'
            ]
        ];
        
        // Mock statement for finding products
        $mockStmt = $this->createMock(PDOStatement::class);
        $mockStmt->method('fetchAll')->willReturn($mockProducts);
        $mockStmt->method('execute')->willReturn(true);
        
        $this->mockDb->method('prepare')->willReturn($mockStmt);
        $this->mockDb->method('beginTransaction')->willReturn(true);
        $this->mockDb->method('commit')->willReturn(true);
        
        // Execute sync
        $results = $this->syncEngine->syncProductNames(10);
        
        // Assertions
        $this->assertIsArray($results);
        $this->assertArrayHasKey('total', $results);
        $this->assertArrayHasKey('success', $results);
        $this->assertArrayHasKey('failed', $results);
        $this->assertEquals(1, $results['total']);
    }
    
    /**
     * Test: Handling database connection failure
     */
    public function testDatabaseConnectionFailure() {
        $this->expectException(Exception::class);
        $this->expectExceptionMessage('Failed to connect to database');
        
        // Create engine without valid DB connection
        $invalidDb = null;
        new SafeSyncEngine($invalidDb, $this->mockLogger);
    }
    
    /**
     * Test: Handling empty product list
     */
    public function testSyncWithNoProductsNeedingSync() {
        // Mock empty result
        $mockStmt = $this->createMock(PDOStatement::class);
        $mockStmt->method('fetchAll')->willReturn([]);
        $mockStmt->method('execute')->willReturn(true);
        
        $this->mockDb->method('prepare')->willReturn($mockStmt);
        
        $results = $this->syncEngine->syncProductNames();
        
        $this->assertEquals(0, $results['total']);
        $this->assertEquals(0, $results['success']);
        $this->assertEquals(0, $results['failed']);
    }
    
    /**
     * Test: Batch processing with multiple products
     */
    public function testBatchProcessing() {
        // Create 25 products to test batch processing (default batch size is 10)
        $mockProducts = [];
        for ($i = 1; $i <= 25; $i++) {
            $mockProducts[] = [
                'cross_ref_id' => $i,
                'inventory_product_id' => (string)$i,
                'ozon_product_id' => (string)$i,
                'sku_ozon' => (string)$i,
                'sync_status' => 'pending',
                'current_name' => "Товар Ozon ID {$i}"
            ];
        }
        
        $mockStmt = $this->createMock(PDOStatement::class);
        $mockStmt->method('fetchAll')->willReturn($mockProducts);
        $mockStmt->method('execute')->willReturn(true);
        
        $this->mockDb->method('prepare')->willReturn($mockStmt);
        $this->mockDb->method('beginTransaction')->willReturn(true);
        $this->mockDb->method('commit')->willReturn(true);
        
        $results = $this->syncEngine->syncProductNames();
        
        $this->assertEquals(25, $results['total']);
    }
    
    /**
     * Test: Retry logic on database failure
     */
    public function testRetryLogicOnDatabaseFailure() {
        $mockProducts = [
            [
                'cross_ref_id' => 1,
                'inventory_product_id' => '12345',
                'ozon_product_id' => '12345',
                'sku_ozon' => '12345',
                'sync_status' => 'pending',
                'current_name' => 'Товар Ozon ID 12345'
            ]
        ];
        
        $mockStmt = $this->createMock(PDOStatement::class);
        $mockStmt->method('fetchAll')->willReturn($mockProducts);
        
        // First two attempts fail, third succeeds
        $mockStmt->method('execute')
            ->willReturnOnConsecutiveCalls(
                true, // findProductsNeedingSync
                $this->throwException(new PDOException('Deadlock')),
                $this->throwException(new PDOException('Deadlock')),
                true // Success on third attempt
            );
        
        $this->mockDb->method('prepare')->willReturn($mockStmt);
        $this->mockDb->method('beginTransaction')->willReturn(true);
        $this->mockDb->method('commit')->willReturn(true);
        $this->mockDb->method('rollBack')->willReturn(true);
        
        // Set max retries to 3
        $this->syncEngine->setMaxRetries(3);
        
        $results = $this->syncEngine->syncProductNames();
        
        // Should eventually succeed or fail gracefully
        $this->assertIsArray($results);
    }
    
    /**
     * Test: Handling invalid product data
     */
    public function testHandlingInvalidProductData() {
        $mockProducts = [
            [
                'cross_ref_id' => 1,
                'inventory_product_id' => '', // Invalid: empty ID
                'ozon_product_id' => null,
                'sku_ozon' => null,
                'sync_status' => 'pending',
                'current_name' => null
            ]
        ];
        
        $mockStmt = $this->createMock(PDOStatement::class);
        $mockStmt->method('fetchAll')->willReturn($mockProducts);
        $mockStmt->method('execute')->willReturn(true);
        
        $this->mockDb->method('prepare')->willReturn($mockStmt);
        
        $results = $this->syncEngine->syncProductNames();
        
        // Should skip invalid products
        $this->assertEquals(1, $results['total']);
        $this->assertEquals(1, $results['skipped']);
    }
    
    /**
     * Test: Transaction rollback on error
     */
    public function testTransactionRollbackOnError() {
        $mockProducts = [
            [
                'cross_ref_id' => 1,
                'inventory_product_id' => '12345',
                'ozon_product_id' => '12345',
                'sku_ozon' => '12345',
                'sync_status' => 'pending',
                'current_name' => 'Товар Ozon ID 12345'
            ]
        ];
        
        $mockStmt = $this->createMock(PDOStatement::class);
        $mockStmt->method('fetchAll')->willReturn($mockProducts);
        $mockStmt->method('execute')
            ->willReturnOnConsecutiveCalls(
                true, // findProductsNeedingSync
                $this->throwException(new PDOException('Constraint violation'))
            );
        
        $this->mockDb->method('prepare')->willReturn($mockStmt);
        $this->mockDb->method('beginTransaction')->willReturn(true);
        
        // Expect rollback to be called
        $this->mockDb->expects($this->atLeastOnce())
            ->method('rollBack');
        
        $results = $this->syncEngine->syncProductNames();
        
        $this->assertGreaterThan(0, $results['failed']);
    }
    
    /**
     * Test: Custom batch size configuration
     */
    public function testCustomBatchSize() {
        $this->syncEngine->setBatchSize(5);
        
        // Create 12 products
        $mockProducts = [];
        for ($i = 1; $i <= 12; $i++) {
            $mockProducts[] = [
                'cross_ref_id' => $i,
                'inventory_product_id' => (string)$i,
                'ozon_product_id' => (string)$i,
                'sku_ozon' => (string)$i,
                'sync_status' => 'pending',
                'current_name' => "Товар Ozon ID {$i}"
            ];
        }
        
        $mockStmt = $this->createMock(PDOStatement::class);
        $mockStmt->method('fetchAll')->willReturn($mockProducts);
        $mockStmt->method('execute')->willReturn(true);
        
        $this->mockDb->method('prepare')->willReturn($mockStmt);
        $this->mockDb->method('beginTransaction')->willReturn(true);
        $this->mockDb->method('commit')->willReturn(true);
        
        $results = $this->syncEngine->syncProductNames();
        
        // Should process all 12 products in batches of 5
        $this->assertEquals(12, $results['total']);
    }
    
    /**
     * Test: Get sync statistics
     */
    public function testGetSyncStatistics() {
        $mockStats = [
            'total' => 100,
            'synced' => 75,
            'pending' => 20,
            'failed' => 5,
            'last_sync_time' => '2025-10-10 12:00:00'
        ];
        
        $mockStmt = $this->createMock(PDOStatement::class);
        $mockStmt->method('fetch')->willReturn($mockStats);
        
        $this->mockDb->method('query')->willReturn($mockStmt);
        
        $stats = $this->syncEngine->getSyncStatistics();
        
        $this->assertIsArray($stats);
        $this->assertEquals(100, $stats['total_products']);
        $this->assertEquals(75, $stats['synced']);
        $this->assertEquals(20, $stats['pending']);
        $this->assertEquals(5, $stats['failed']);
        $this->assertEquals(75.0, $stats['sync_percentage']);
    }
    
    /**
     * Test: Handling SQL query errors
     */
    public function testHandlingSQLQueryErrors() {
        $this->expectException(Exception::class);
        
        $mockStmt = $this->createMock(PDOStatement::class);
        $mockStmt->method('execute')
            ->willThrowException(new PDOException('SQL syntax error'));
        
        $this->mockDb->method('prepare')->willReturn($mockStmt);
        
        $this->syncEngine->syncProductNames();
    }
    
    /**
     * Test: Limit parameter functionality
     */
    public function testLimitParameter() {
        $mockProducts = [];
        for ($i = 1; $i <= 5; $i++) {
            $mockProducts[] = [
                'cross_ref_id' => $i,
                'inventory_product_id' => (string)$i,
                'ozon_product_id' => (string)$i,
                'sku_ozon' => (string)$i,
                'sync_status' => 'pending',
                'current_name' => "Товар Ozon ID {$i}"
            ];
        }
        
        $mockStmt = $this->createMock(PDOStatement::class);
        $mockStmt->method('fetchAll')->willReturn($mockProducts);
        $mockStmt->method('execute')->willReturn(true);
        
        // Verify that bindValue is called with the limit
        $mockStmt->expects($this->once())
            ->method('bindValue')
            ->with(':limit', 5, PDO::PARAM_INT);
        
        $this->mockDb->method('prepare')->willReturn($mockStmt);
        $this->mockDb->method('beginTransaction')->willReturn(true);
        $this->mockDb->method('commit')->willReturn(true);
        
        $results = $this->syncEngine->syncProductNames(5);
        
        $this->assertEquals(5, $results['total']);
    }
}
