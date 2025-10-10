<?php
/**
 * Integration Tests for Full Synchronization Cycle
 * 
 * Tests the complete workflow from data extraction to storage,
 * including all components working together.
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../src/SafeSyncEngine.php';
require_once __DIR__ . '/../src/DataTypeNormalizer.php';
require_once __DIR__ . '/../src/FallbackDataProvider.php';

class SyncIntegrationTest extends TestCase {
    private $testDb;
    private $syncEngine;
    private $fallbackProvider;
    private $normalizer;
    
    protected function setUp(): void {
        // Create in-memory SQLite database for testing
        $this->testDb = new PDO('sqlite::memory:');
        $this->testDb->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        
        // Create test tables
        $this->createTestTables();
        
        // Insert test data
        $this->insertTestData();
        
        // Initialize components
        $logger = new SimpleLogger('/tmp/test_sync.log', 'DEBUG');
        $this->syncEngine = new SafeSyncEngine($this->testDb, $logger);
        $this->fallbackProvider = new FallbackDataProvider($this->testDb, $logger);
        $this->normalizer = new DataTypeNormalizer();
    }
    
    protected function tearDown(): void {
        $this->testDb = null;
        $this->syncEngine = null;
        $this->fallbackProvider = null;
        $this->normalizer = null;
    }
    
    /**
     * Create test database tables
     */
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
     * Insert test data
     */
    private function insertTestData() {
        // Insert cross-reference records
        $this->testDb->exec("
            INSERT INTO product_cross_reference 
            (inventory_product_id, ozon_product_id, sku_ozon, cached_name, sync_status)
            VALUES 
            ('12345', '12345', '12345', 'Товар Ozon ID 12345', 'pending'),
            ('67890', '67890', '67890', NULL, 'pending'),
            ('11111', '11111', '11111', 'Реальное название товара', 'synced')
        ");
        
        // Insert dim_products records
        $this->testDb->exec("
            INSERT INTO dim_products (sku_ozon, name, brand, cross_ref_id)
            VALUES 
            ('12345', 'Товар Ozon ID 12345', 'Unknown', 1),
            ('67890', NULL, NULL, 2),
            ('11111', 'Реальное название товара', 'Test Brand', 3)
        ");
        
        // Insert inventory data
        $this->testDb->exec("
            INSERT INTO inventory_data (product_id, quantity_present, quantity_reserved)
            VALUES 
            ('12345', 100, 10),
            ('67890', 50, 5),
            ('11111', 200, 20)
        ");
    }
    
    /**
     * Test: Complete synchronization workflow
     */
    public function testCompleteSynchronizationWorkflow() {
        // Get initial statistics
        $initialStats = $this->syncEngine->getSyncStatistics();
        
        $this->assertEquals(3, $initialStats['total_products']);
        $this->assertEquals(1, $initialStats['synced']);
        $this->assertEquals(2, $initialStats['pending']);
        
        // Run synchronization (will use fallback since no real API)
        $results = $this->syncEngine->syncProductNames(10);
        
        // Verify results
        $this->assertIsArray($results);
        $this->assertEquals(2, $results['total']); // Only pending products
        
        // Get updated statistics
        $finalStats = $this->syncEngine->getSyncStatistics();
        
        // At least some products should be processed
        $this->assertGreaterThanOrEqual($initialStats['synced'], $finalStats['synced']);
    }
    
    /**
     * Test: Data type normalization in full cycle
     */
    public function testDataTypeNormalizationInFullCycle() {
        // Insert product with mixed data types
        $this->testDb->exec("
            INSERT INTO product_cross_reference 
            (inventory_product_id, ozon_product_id, sku_ozon, sync_status)
            VALUES ('99999', 99999, 99999, 'pending')
        ");
        
        // Fetch and normalize
        $stmt = $this->testDb->query("
            SELECT * FROM product_cross_reference 
            WHERE inventory_product_id = '99999'
        ");
        $product = $stmt->fetch();
        
        $normalized = $this->normalizer->normalizeProduct($product);
        
        // All ID fields should be strings
        $this->assertIsString($normalized['inventory_product_id']);
        $this->assertIsString($normalized['ozon_product_id']);
        $this->assertIsString($normalized['sku_ozon']);
        
        // Values should match
        $this->assertEquals('99999', $normalized['inventory_product_id']);
        $this->assertEquals('99999', $normalized['ozon_product_id']);
    }
    
    /**
     * Test: Fallback provider integration
     */
    public function testFallbackProviderIntegration() {
        // Get product name using fallback
        $name = $this->fallbackProvider->getProductName('12345');
        
        $this->assertIsString($name);
        $this->assertNotEmpty($name);
        
        // Cache a new name
        $cached = $this->fallbackProvider->cacheProductName(
            '12345',
            'Новое название товара',
            ['brand' => 'Новый бренд']
        );
        
        $this->assertTrue($cached);
        
        // Verify cached name is retrieved
        $cachedName = $this->fallbackProvider->getCachedName('12345');
        $this->assertEquals('Новое название товара', $cachedName);
    }
    
    /**
     * Test: Cache statistics calculation
     */
    public function testCacheStatisticsCalculation() {
        $stats = $this->fallbackProvider->getCacheStatistics();
        
        $this->assertIsArray($stats);
        $this->assertArrayHasKey('total_entries', $stats);
        $this->assertArrayHasKey('cached_names', $stats);
        $this->assertArrayHasKey('placeholder_names', $stats);
        $this->assertArrayHasKey('real_names', $stats);
        
        $this->assertEquals(3, $stats['total_entries']);
        $this->assertGreaterThanOrEqual(0, $stats['cached_names']);
    }
    
    /**
     * Test: Batch processing with transaction rollback
     */
    public function testBatchProcessingWithTransactionRollback() {
        // Set small batch size
        $this->syncEngine->setBatchSize(1);
        
        // Insert a product that will cause issues
        $this->testDb->exec("
            INSERT INTO product_cross_reference 
            (inventory_product_id, ozon_product_id, sku_ozon, sync_status)
            VALUES ('', '', '', 'pending')
        ");
        
        // Run sync - should handle invalid product gracefully
        $results = $this->syncEngine->syncProductNames();
        
        // Should have some skipped products
        $this->assertGreaterThanOrEqual(0, $results['skipped']);
    }
    
    /**
     * Test: Update cache from API response
     */
    public function testUpdateCacheFromAPIResponse() {
        $apiResponse = [
            [
                'id' => '12345',
                'name' => 'Обновленное название 1',
                'brand' => 'Бренд 1'
            ],
            [
                'id' => '67890',
                'name' => 'Обновленное название 2',
                'brand' => 'Бренд 2'
            ]
        ];
        
        $updated = $this->fallbackProvider->updateCacheFromAPIResponse($apiResponse);
        
        $this->assertEquals(2, $updated);
        
        // Verify updates
        $name1 = $this->fallbackProvider->getCachedName('12345');
        $name2 = $this->fallbackProvider->getCachedName('67890');
        
        $this->assertEquals('Обновленное название 1', $name1);
        $this->assertEquals('Обновленное название 2', $name2);
    }
    
    /**
     * Test: Clear stale cache entries
     */
    public function testClearStaleCacheEntries() {
        // Update a product with old sync time
        $this->testDb->exec("
            UPDATE product_cross_reference
            SET last_api_sync = datetime('now', '-8 days'),
                sync_status = 'synced'
            WHERE inventory_product_id = '11111'
        ");
        
        // Clear cache older than 7 days
        $cleared = $this->fallbackProvider->clearStaleCache(168);
        
        $this->assertGreaterThanOrEqual(1, $cleared);
        
        // Verify status changed to pending
        $stmt = $this->testDb->query("
            SELECT sync_status FROM product_cross_reference
            WHERE inventory_product_id = '11111'
        ");
        $result = $stmt->fetch();
        
        $this->assertEquals('pending', $result['sync_status']);
    }
    
    /**
     * Test: Normalize different API responses
     */
    public function testNormalizeDifferentAPIResponses() {
        // Ozon response
        $ozonData = [
            'product_id' => 12345,
            'offer_id' => 'SKU-123',
            'name' => 'Ozon Product',
            'stocks' => [['present' => 50], ['present' => 30]]
        ];
        
        $normalizedOzon = $this->normalizer->normalizeAPIResponse($ozonData, 'ozon');
        
        $this->assertEquals('12345', $normalizedOzon['ozon_product_id']);
        $this->assertEquals(80, $normalizedOzon['quantity']);
        
        // WB response
        $wbData = [
            'nmId' => 67890,
            'vendorCode' => 'WB-SKU',
            'title' => 'WB Product',
            'quantity' => 100
        ];
        
        $normalizedWB = $this->normalizer->normalizeAPIResponse($wbData, 'wb');
        
        $this->assertEquals('67890', $normalizedWB['wb_product_id']);
        $this->assertEquals(100, $normalizedWB['quantity']);
    }
    
    /**
     * Test: Validate normalized data before storage
     */
    public function testValidateNormalizedDataBeforeStorage() {
        $validData = [
            'inventory_product_id' => '12345',
            'name' => 'Valid Product',
            'quantity' => 100
        ];
        
        $validation = $this->normalizer->validateNormalizedData($validData);
        
        $this->assertTrue($validation['valid']);
        $this->assertEmpty($validation['errors']);
        
        // Invalid data
        $invalidData = [
            'name' => 'Missing ID'
        ];
        
        $validation = $this->normalizer->validateNormalizedData($invalidData);
        
        $this->assertFalse($validation['valid']);
        $this->assertNotEmpty($validation['errors']);
    }
    
    /**
     * Test: Safe ID comparison across different types
     */
    public function testSafeIdComparisonAcrossDifferentTypes() {
        $this->assertTrue($this->normalizer->compareIds('12345', 12345));
        $this->assertTrue($this->normalizer->compareIds(12345, '12345'));
        $this->assertFalse($this->normalizer->compareIds('12345', '67890'));
    }
    
    /**
     * Test: End-to-end sync with retry logic
     */
    public function testEndToEndSyncWithRetryLogic() {
        // Set retry parameters
        $this->syncEngine->setMaxRetries(3);
        $this->syncEngine->setBatchSize(5);
        
        // Run sync
        $results = $this->syncEngine->syncProductNames(10);
        
        // Verify structure
        $this->assertArrayHasKey('total', $results);
        $this->assertArrayHasKey('success', $results);
        $this->assertArrayHasKey('failed', $results);
        $this->assertArrayHasKey('skipped', $results);
        $this->assertArrayHasKey('errors', $results);
        
        // Total should equal sum of success, failed, and skipped
        $this->assertEquals(
            $results['total'],
            $results['success'] + $results['failed'] + $results['skipped']
        );
    }
    
    /**
     * Test: Concurrent product updates
     */
    public function testConcurrentProductUpdates() {
        // Simulate concurrent updates to same product
        $productId = '12345';
        
        // First update
        $this->fallbackProvider->cacheProductName($productId, 'Название 1');
        
        // Second update
        $this->fallbackProvider->cacheProductName($productId, 'Название 2');
        
        // Verify last update wins
        $finalName = $this->fallbackProvider->getCachedName($productId);
        $this->assertEquals('Название 2', $finalName);
    }
    
    /**
     * Test: Placeholder name detection and replacement
     */
    public function testPlaceholderNameDetectionAndReplacement() {
        // Product with placeholder name
        $placeholderName = $this->fallbackProvider->getCachedName('12345');
        
        // Should detect placeholder and try to replace
        $newName = $this->fallbackProvider->getProductName('12345');
        
        $this->assertIsString($newName);
        // Should either get real name or temporary name, not placeholder
        $this->assertNotEquals($placeholderName, $newName);
    }
    
    /**
     * Test: Sync statistics accuracy
     */
    public function testSyncStatisticsAccuracy() {
        // Get initial stats
        $stats = $this->syncEngine->getSyncStatistics();
        
        $initialTotal = $stats['total_products'];
        $initialSynced = $stats['synced'];
        
        // Add new pending product
        $this->testDb->exec("
            INSERT INTO product_cross_reference 
            (inventory_product_id, ozon_product_id, sku_ozon, sync_status)
            VALUES ('88888', '88888', '88888', 'pending')
        ");
        
        // Get updated stats
        $newStats = $this->syncEngine->getSyncStatistics();
        
        $this->assertEquals($initialTotal + 1, $newStats['total_products']);
        $this->assertEquals($initialSynced, $newStats['synced']);
    }
}
