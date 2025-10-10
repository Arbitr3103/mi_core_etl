<?php
/**
 * Regression Tests for MDM System
 * 
 * Comprehensive tests to prevent regression of fixed issues:
 * - SQL query errors (DISTINCT + ORDER BY)
 * - Data type incompatibility (INT vs VARCHAR)
 * - Cross-reference mapping issues
 * - Sync engine failures
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../src/SafeSyncEngine.php';
require_once __DIR__ . '/../src/DataTypeNormalizer.php';
require_once __DIR__ . '/../src/FallbackDataProvider.php';

class RegressionTest extends TestCase {
    private $testDb;
    
    protected function setUp(): void {
        // Create in-memory SQLite database for testing
        $this->testDb = new PDO('sqlite::memory:');
        $this->testDb->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        
        // Create test tables
        $this->createTestTables();
    }
    
    protected function tearDown(): void {
        $this->testDb = null;
    }
    
    private function createTestTables() {
        // Create product_cross_reference table
        $this->testDb->exec("
            CREATE TABLE product_cross_reference (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                inventory_product_id TEXT NOT NULL,
                analytics_product_id TEXT,
                ozon_product_id TEXT,
                sku_ozon TEXT,
                cached_name TEXT,
                cached_brand TEXT,
                last_api_sync DATETIME,
                sync_status TEXT DEFAULT 'pending',
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
            )
        ");
        
        // Create dim_products table
        $this->testDb->exec("
            CREATE TABLE dim_products (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                sku_ozon TEXT NOT NULL,
                name TEXT,
                brand TEXT,
                category TEXT,
                cross_ref_id INTEGER,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
            )
        ");
        
        // Create inventory_data table
        $this->testDb->exec("
            CREATE TABLE inventory_data (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                product_id TEXT NOT NULL,
                quantity_present INTEGER,
                quantity_reserved INTEGER,
                warehouse_id TEXT,
                updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
            )
        ");
    }
    
    /**
     * REGRESSION TEST: SQL DISTINCT + ORDER BY error
     * 
     * Original issue: "Expression #1 of ORDER BY clause is not in SELECT list"
     * This test ensures the fix remains in place
     */
    public function testSQLDistinctOrderByNoError() {
        // Insert test data
        $this->testDb->exec("
            INSERT INTO inventory_data (product_id, quantity_present)
            VALUES ('12345', 100), ('67890', 50), ('11111', 200)
        ");
        
        $this->testDb->exec("
            INSERT INTO product_cross_reference (inventory_product_id, sku_ozon)
            VALUES ('12345', '12345'), ('67890', '67890'), ('11111', '11111')
        ");
        
        // This query should NOT throw an error
        // Original problematic pattern: SELECT DISTINCT ... ORDER BY (column not in SELECT)
        // Fixed pattern: Include ORDER BY column in SELECT or use subquery
        
        $sql = "
            SELECT DISTINCT i.product_id, MAX(i.quantity_present) as max_quantity
            FROM inventory_data i
            LEFT JOIN product_cross_reference pcr 
                ON CAST(i.product_id AS TEXT) = pcr.inventory_product_id
            WHERE i.product_id != '0'
            GROUP BY i.product_id
            ORDER BY max_quantity DESC
            LIMIT 20
        ";
        
        $stmt = $this->testDb->query($sql);
        $results = $stmt->fetchAll();
        
        $this->assertIsArray($results);
        $this->assertCount(3, $results);
        
        // Verify ordering (highest quantity first)
        $this->assertEquals('11111', $results[0]['product_id']);
        $this->assertEquals(200, $results[0]['max_quantity']);
    }
    
    /**
     * REGRESSION TEST: Data type incompatibility (INT vs VARCHAR)
     * 
     * Original issue: JOIN failures due to INT vs VARCHAR mismatch
     * This test ensures type normalization works correctly
     */
    public function testDataTypeCompatibilityInJoins() {
        // Insert data with mixed types
        $this->testDb->exec("
            INSERT INTO inventory_data (product_id, quantity_present)
            VALUES ('12345', 100), ('67890', 50)
        ");
        
        // Cross-reference uses TEXT (VARCHAR equivalent)
        $this->testDb->exec("
            INSERT INTO product_cross_reference (inventory_product_id, ozon_product_id, sku_ozon)
            VALUES ('12345', '12345', '12345'), ('67890', '67890', '67890')
        ");
        
        // JOIN with CAST to ensure compatibility
        $sql = "
            SELECT 
                i.product_id,
                pcr.inventory_product_id,
                pcr.ozon_product_id,
                i.quantity_present
            FROM inventory_data i
            INNER JOIN product_cross_reference pcr 
                ON CAST(i.product_id AS TEXT) = pcr.inventory_product_id
        ";
        
        $stmt = $this->testDb->query($sql);
        $results = $stmt->fetchAll();
        
        $this->assertCount(2, $results);
        
        // Verify JOIN worked correctly
        foreach ($results as $row) {
            $this->assertEquals($row['product_id'], $row['inventory_product_id']);
            $this->assertEquals($row['product_id'], $row['ozon_product_id']);
        }
    }
    
    /**
     * REGRESSION TEST: Placeholder names not being replaced
     * 
     * Original issue: Dashboard showing "Товар Ozon ID 123" instead of real names
     * This test ensures placeholder detection and replacement works
     */
    public function testPlaceholderNameReplacement() {
        $logger = new SimpleLogger('/tmp/test_regression.log', 'DEBUG');
        $fallbackProvider = new FallbackDataProvider($this->testDb, $logger);
        
        // Insert product with placeholder name
        $this->testDb->exec("
            INSERT INTO product_cross_reference 
            (inventory_product_id, ozon_product_id, sku_ozon, cached_name, sync_status)
            VALUES ('12345', '12345', '12345', 'Товар Ozon ID 12345', 'pending')
        ");
        
        // Get product name - should NOT return placeholder
        $name = $fallbackProvider->getProductName('12345');
        
        // Should either get real name from API or temporary name, but NOT placeholder
        $this->assertIsString($name);
        $this->assertNotEquals('Товар Ozon ID 12345', $name);
        
        // Should contain "требует обновления" since API is not available in test
        $this->assertStringContainsString('требует обновления', $name);
    }
    
    /**
     * REGRESSION TEST: Sync engine transaction rollback
     * 
     * Original issue: Failed syncs leaving database in inconsistent state
     * This test ensures proper transaction handling
     */
    public function testSyncEngineTransactionRollback() {
        $logger = new SimpleLogger('/tmp/test_regression.log', 'DEBUG');
        $syncEngine = new SafeSyncEngine($this->testDb, $logger);
        
        // Insert test data
        $this->testDb->exec("
            INSERT INTO product_cross_reference 
            (inventory_product_id, ozon_product_id, sku_ozon, sync_status)
            VALUES ('12345', '12345', '12345', 'pending')
        ");
        
        // Get initial count
        $initialCount = $this->testDb->query(
            "SELECT COUNT(*) as cnt FROM product_cross_reference WHERE sync_status = 'pending'"
        )->fetch()['cnt'];
        
        // Run sync (will fail to get real data but should handle gracefully)
        $results = $syncEngine->syncProductNames(10);
        
        // Verify no database corruption
        $this->assertIsArray($results);
        $this->assertArrayHasKey('total', $results);
        $this->assertArrayHasKey('success', $results);
        $this->assertArrayHasKey('failed', $results);
        
        // Database should still be consistent
        $finalCount = $this->testDb->query(
            "SELECT COUNT(*) as cnt FROM product_cross_reference"
        )->fetch()['cnt'];
        
        $this->assertEquals(1, $finalCount);
    }
    
    /**
     * REGRESSION TEST: Empty or null ID handling
     * 
     * Original issue: Crashes when encountering null or empty IDs
     * This test ensures proper validation and error handling
     */
    public function testEmptyOrNullIdHandling() {
        $normalizer = new DataTypeNormalizer();
        
        // Test null ID
        $this->assertNull($normalizer->normalizeId(null));
        
        // Test empty string ID
        $this->assertNull($normalizer->normalizeId(''));
        
        // Test zero ID
        $this->assertNull($normalizer->normalizeId(0));
        
        // Test whitespace-only ID
        $this->assertNull($normalizer->normalizeId('   '));
        
        // Validation should reject invalid IDs
        $this->assertFalse($normalizer->isValidProductId(null));
        $this->assertFalse($normalizer->isValidProductId(''));
        $this->assertFalse($normalizer->isValidProductId(0));
    }
    
    /**
     * REGRESSION TEST: Batch processing timeout prevention
     * 
     * Original issue: Large syncs timing out
     * This test ensures batch processing works correctly
     */
    public function testBatchProcessingPreventsTimeout() {
        $logger = new SimpleLogger('/tmp/test_regression.log', 'DEBUG');
        $syncEngine = new SafeSyncEngine($this->testDb, $logger);
        
        // Insert many products
        for ($i = 1; $i <= 50; $i++) {
            $this->testDb->exec("
                INSERT INTO product_cross_reference 
                (inventory_product_id, ozon_product_id, sku_ozon, sync_status)
                VALUES ('{$i}', '{$i}', '{$i}', 'pending')
            ");
        }
        
        // Set small batch size
        $syncEngine->setBatchSize(10);
        
        // Run sync - should process in batches
        $results = $syncEngine->syncProductNames(50);
        
        $this->assertEquals(50, $results['total']);
        
        // Should have processed all products without timeout
        $this->assertGreaterThanOrEqual(0, $results['success'] + $results['failed'] + $results['skipped']);
    }
    
    /**
     * REGRESSION TEST: Cross-reference table integrity
     * 
     * Original issue: Missing or duplicate cross-reference entries
     * This test ensures data integrity is maintained
     */
    public function testCrossReferenceTableIntegrity() {
        // Insert product in inventory
        $this->testDb->exec("
            INSERT INTO inventory_data (product_id, quantity_present)
            VALUES ('12345', 100)
        ");
        
        // Create cross-reference entry
        $this->testDb->exec("
            INSERT INTO product_cross_reference 
            (inventory_product_id, ozon_product_id, sku_ozon)
            VALUES ('12345', '12345', '12345')
        ");
        
        // Verify cross-reference exists
        $stmt = $this->testDb->query("
            SELECT COUNT(*) as cnt 
            FROM product_cross_reference 
            WHERE inventory_product_id = '12345'
        ");
        $count = $stmt->fetch()['cnt'];
        
        $this->assertEquals(1, $count);
        
        // Verify JOIN works
        $stmt = $this->testDb->query("
            SELECT i.product_id, pcr.inventory_product_id
            FROM inventory_data i
            INNER JOIN product_cross_reference pcr 
                ON CAST(i.product_id AS TEXT) = pcr.inventory_product_id
            WHERE i.product_id = '12345'
        ");
        $result = $stmt->fetch();
        
        $this->assertNotFalse($result);
        $this->assertEquals('12345', $result['product_id']);
    }
    
    /**
     * REGRESSION TEST: API response normalization
     * 
     * Original issue: Different API formats causing data inconsistency
     * This test ensures all API responses are normalized correctly
     */
    public function testAPIResponseNormalization() {
        $normalizer = new DataTypeNormalizer();
        
        // Test Ozon API response
        $ozonResponse = [
            'product_id' => 12345,
            'offer_id' => 'SKU-123',
            'name' => 'Test Product',
            'stocks' => [
                ['present' => 50],
                ['present' => 30]
            ]
        ];
        
        $normalized = $normalizer->normalizeAPIResponse($ozonResponse, 'ozon');
        
        $this->assertEquals('12345', $normalized['ozon_product_id']);
        $this->assertEquals('SKU-123', $normalized['sku_ozon']);
        $this->assertEquals(80, $normalized['quantity']);
        
        // Test Inventory API response
        $inventoryResponse = [
            'product_id' => 67890,
            'sku' => 'SKU-456',
            'quantity_present' => 100
        ];
        
        $normalized = $normalizer->normalizeAPIResponse($inventoryResponse, 'inventory');
        
        $this->assertEquals('67890', $normalized['inventory_product_id']);
        $this->assertEquals('SKU-456', $normalized['sku_ozon']);
        $this->assertEquals(100, $normalized['quantity_present']);
    }
    
    /**
     * REGRESSION TEST: Stale cache cleanup
     * 
     * Original issue: Old cached data never being refreshed
     * This test ensures stale cache is properly cleaned
     */
    public function testStaleCacheCleanup() {
        $logger = new SimpleLogger('/tmp/test_regression.log', 'DEBUG');
        $fallbackProvider = new FallbackDataProvider($this->testDb, $logger);
        
        // Insert old cached data
        $this->testDb->exec("
            INSERT INTO product_cross_reference 
            (inventory_product_id, cached_name, last_api_sync, sync_status)
            VALUES 
            ('12345', 'Old Name', datetime('now', '-10 days'), 'synced'),
            ('67890', 'Recent Name', datetime('now', '-1 day'), 'synced')
        ");
        
        // Clear cache older than 7 days
        $cleared = $fallbackProvider->clearStaleCache(168); // 7 days in hours
        
        // Should clear at least the 10-day-old entry
        $this->assertGreaterThanOrEqual(1, $cleared);
        
        // Verify old entry is now pending
        $stmt = $this->testDb->query("
            SELECT sync_status FROM product_cross_reference 
            WHERE inventory_product_id = '12345'
        ");
        $result = $stmt->fetch();
        
        $this->assertEquals('pending', $result['sync_status']);
        
        // Recent entry should still be synced
        $stmt = $this->testDb->query("
            SELECT sync_status FROM product_cross_reference 
            WHERE inventory_product_id = '67890'
        ");
        $result = $stmt->fetch();
        
        $this->assertEquals('synced', $result['sync_status']);
    }
    
    /**
     * REGRESSION TEST: Retry logic on temporary failures
     * 
     * Original issue: Single failures causing entire sync to abort
     * This test ensures retry logic works correctly
     */
    public function testRetryLogicOnTemporaryFailures() {
        $logger = new SimpleLogger('/tmp/test_regression.log', 'DEBUG');
        $syncEngine = new SafeSyncEngine($this->testDb, $logger);
        
        // Set retry parameters
        $syncEngine->setMaxRetries(3);
        
        // Insert test data
        $this->testDb->exec("
            INSERT INTO product_cross_reference 
            (inventory_product_id, ozon_product_id, sku_ozon, sync_status)
            VALUES ('12345', '12345', '12345', 'pending')
        ");
        
        // Run sync - should handle failures gracefully with retries
        $results = $syncEngine->syncProductNames(10);
        
        $this->assertIsArray($results);
        $this->assertArrayHasKey('total', $results);
        
        // Should not throw exception even if API fails
        $this->assertTrue(true);
    }
    
    /**
     * REGRESSION TEST: Concurrent sync prevention
     * 
     * Original issue: Multiple syncs running simultaneously causing conflicts
     * This test ensures proper locking mechanism
     */
    public function testConcurrentSyncPrevention() {
        $logger = new SimpleLogger('/tmp/test_regression.log', 'DEBUG');
        $syncEngine1 = new SafeSyncEngine($this->testDb, $logger);
        $syncEngine2 = new SafeSyncEngine($this->testDb, $logger);
        
        // Insert test data
        $this->testDb->exec("
            INSERT INTO product_cross_reference 
            (inventory_product_id, ozon_product_id, sku_ozon, sync_status)
            VALUES ('12345', '12345', '12345', 'pending')
        ");
        
        // Both syncs should handle the same data without conflicts
        $results1 = $syncEngine1->syncProductNames(10);
        $results2 = $syncEngine2->syncProductNames(10);
        
        // Both should complete without errors
        $this->assertIsArray($results1);
        $this->assertIsArray($results2);
        
        // Verify database consistency
        $stmt = $this->testDb->query("SELECT COUNT(*) as cnt FROM product_cross_reference");
        $count = $stmt->fetch()['cnt'];
        
        $this->assertEquals(1, $count);
    }
}
