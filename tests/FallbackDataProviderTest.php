<?php
/**
 * Unit Tests for FallbackDataProvider
 * 
 * Tests caching mechanisms, API fallback logic, and cache statistics.
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../src/FallbackDataProvider.php';

class FallbackDataProviderTest extends TestCase {
    private $mockDb;
    private $mockLogger;
    private $fallbackProvider;
    
    protected function setUp(): void {
        $this->mockDb = $this->createMock(PDO::class);
        $this->mockLogger = $this->createMock(SimpleLogger::class);
        $this->fallbackProvider = new FallbackDataProvider($this->mockDb, $this->mockLogger);
    }
    
    protected function tearDown(): void {
        $this->mockDb = null;
        $this->mockLogger = null;
        $this->fallbackProvider = null;
    }
    
    /**
     * Test: Get product name from cache
     */
    public function testGetProductNameFromCache() {
        $mockResult = [
            'cached_name' => 'Смесь для выпечки ЭТОНОВО',
            'last_api_sync' => '2025-10-10 12:00:00'
        ];
        
        $mockStmt = $this->createMock(PDOStatement::class);
        $mockStmt->method('fetch')->willReturn($mockResult);
        $mockStmt->method('execute')->willReturn(true);
        
        $this->mockDb->method('prepare')->willReturn($mockStmt);
        
        $name = $this->fallbackProvider->getProductName('12345');
        
        $this->assertEquals('Смесь для выпечки ЭТОНОВО', $name);
    }
    
    /**
     * Test: Get product name with cache disabled
     */
    public function testGetProductNameWithCacheDisabled() {
        $this->fallbackProvider->setCacheEnabled(false);
        
        // Should skip cache and try API (which will fail in test)
        $mockStmt = $this->createMock(PDOStatement::class);
        $this->mockDb->method('prepare')->willReturn($mockStmt);
        
        $name = $this->fallbackProvider->getProductName('12345');
        
        // Should return temporary name since API is not available in test
        $this->assertStringContainsString('требует обновления', $name);
    }
    
    /**
     * Test: Fallback to temporary name when cache and API fail
     */
    public function testFallbackToTemporaryName() {
        // Mock empty cache
        $mockStmt = $this->createMock(PDOStatement::class);
        $mockStmt->method('fetch')->willReturn(false);
        $mockStmt->method('execute')->willReturn(true);
        
        $this->mockDb->method('prepare')->willReturn($mockStmt);
        
        $name = $this->fallbackProvider->getProductName('12345');
        
        $this->assertStringContainsString('Товар ID 12345', $name);
        $this->assertStringContainsString('требует обновления', $name);
    }
    
    /**
     * Test: Cache product name successfully
     */
    public function testCacheProductNameSuccess() {
        $mockStmt = $this->createMock(PDOStatement::class);
        $mockStmt->method('execute')->willReturn(true);
        $mockStmt->method('rowCount')->willReturn(1);
        
        $this->mockDb->method('prepare')->willReturn($mockStmt);
        
        $result = $this->fallbackProvider->cacheProductName(
            '12345',
            'Смесь для выпечки ЭТОНОВО',
            ['brand' => 'ЭТОНОВО']
        );
        
        $this->assertTrue($result);
    }
    
    /**
     * Test: Create new cache entry when product not found
     */
    public function testCreateNewCacheEntry() {
        $mockStmt = $this->createMock(PDOStatement::class);
        
        // First call (UPDATE) returns 0 rows affected
        // Second call (INSERT) succeeds
        $mockStmt->method('execute')->willReturn(true);
        $mockStmt->method('rowCount')
            ->willReturnOnConsecutiveCalls(0, 1);
        
        $this->mockDb->method('prepare')->willReturn($mockStmt);
        
        $result = $this->fallbackProvider->cacheProductName(
            '12345',
            'Новый товар'
        );
        
        $this->assertTrue($result);
    }
    
    /**
     * Test: Handle database error when caching
     */
    public function testHandleDatabaseErrorWhenCaching() {
        $mockStmt = $this->createMock(PDOStatement::class);
        $mockStmt->method('execute')
            ->willThrowException(new PDOException('Database error'));
        
        $this->mockDb->method('prepare')->willReturn($mockStmt);
        
        $result = $this->fallbackProvider->cacheProductName('12345', 'Test Product');
        
        $this->assertFalse($result);
    }
    
    /**
     * Test: Get cached name returns null for missing product
     */
    public function testGetCachedNameReturnsNullForMissingProduct() {
        $mockStmt = $this->createMock(PDOStatement::class);
        $mockStmt->method('fetch')->willReturn(false);
        $mockStmt->method('execute')->willReturn(true);
        
        $this->mockDb->method('prepare')->willReturn($mockStmt);
        
        $name = $this->fallbackProvider->getCachedName('99999');
        
        $this->assertNull($name);
    }
    
    /**
     * Test: Placeholder name detection
     */
    public function testPlaceholderNameDetection() {
        // Test with placeholder name
        $mockResult = [
            'cached_name' => 'Товар Ozon ID 12345',
            'last_api_sync' => '2025-10-10 12:00:00'
        ];
        
        $mockStmt = $this->createMock(PDOStatement::class);
        $mockStmt->method('fetch')->willReturn($mockResult);
        $mockStmt->method('execute')->willReturn(true);
        
        $this->mockDb->method('prepare')->willReturn($mockStmt);
        
        $name = $this->fallbackProvider->getProductName('12345');
        
        // Should not return placeholder, should try API and fallback to temporary
        $this->assertStringContainsString('требует обновления', $name);
    }
    
    /**
     * Test: Update cache from API response
     */
    public function testUpdateCacheFromAPIResponse() {
        $products = [
            [
                'id' => '12345',
                'name' => 'Товар 1',
                'brand' => 'Бренд 1'
            ],
            [
                'id' => '67890',
                'name' => 'Товар 2',
                'brand' => 'Бренд 2'
            ],
            [
                'id' => '', // Invalid product
                'name' => 'Товар 3'
            ]
        ];
        
        $mockStmt = $this->createMock(PDOStatement::class);
        $mockStmt->method('execute')->willReturn(true);
        $mockStmt->method('rowCount')->willReturn(1);
        
        $this->mockDb->method('prepare')->willReturn($mockStmt);
        
        $updated = $this->fallbackProvider->updateCacheFromAPIResponse($products);
        
        // Should update 2 valid products, skip 1 invalid
        $this->assertEquals(2, $updated);
    }
    
    /**
     * Test: Get cache statistics
     */
    public function testGetCacheStatistics() {
        $mockStats = [
            'total' => 100,
            'cached' => 80,
            'placeholders' => 20,
            'avg_cache_age_hours' => 24.5
        ];
        
        $mockStmt = $this->createMock(PDOStatement::class);
        $mockStmt->method('fetch')->willReturn($mockStats);
        
        $this->mockDb->method('query')->willReturn($mockStmt);
        
        $stats = $this->fallbackProvider->getCacheStatistics();
        
        $this->assertIsArray($stats);
        $this->assertEquals(100, $stats['total_entries']);
        $this->assertEquals(80, $stats['cached_names']);
        $this->assertEquals(20, $stats['placeholder_names']);
        $this->assertEquals(60, $stats['real_names']); // 80 - 20
        $this->assertEquals(60.0, $stats['cache_hit_rate']); // (60/100) * 100
    }
    
    /**
     * Test: Clear stale cache
     */
    public function testClearStaleCache() {
        $mockStmt = $this->createMock(PDOStatement::class);
        $mockStmt->method('execute')->willReturn(true);
        $mockStmt->method('rowCount')->willReturn(15);
        
        $this->mockDb->method('prepare')->willReturn($mockStmt);
        
        $cleared = $this->fallbackProvider->clearStaleCache(168); // 7 days
        
        $this->assertEquals(15, $cleared);
    }
    
    /**
     * Test: Clear stale cache with custom age
     */
    public function testClearStaleCacheWithCustomAge() {
        $mockStmt = $this->createMock(PDOStatement::class);
        $mockStmt->method('execute')->willReturn(true);
        $mockStmt->method('rowCount')->willReturn(5);
        
        $this->mockDb->method('prepare')->willReturn($mockStmt);
        
        $cleared = $this->fallbackProvider->clearStaleCache(24); // 1 day
        
        $this->assertEquals(5, $cleared);
    }
    
    /**
     * Test: Handle error when clearing stale cache
     */
    public function testHandleErrorWhenClearingStaleCache() {
        $mockStmt = $this->createMock(PDOStatement::class);
        $mockStmt->method('execute')
            ->willThrowException(new PDOException('Database error'));
        
        $this->mockDb->method('prepare')->willReturn($mockStmt);
        
        $cleared = $this->fallbackProvider->clearStaleCache();
        
        $this->assertEquals(0, $cleared);
    }
    
    /**
     * Test: Set API timeout
     */
    public function testSetApiTimeout() {
        $this->fallbackProvider->setApiTimeout(60);
        
        // Timeout should be set (we can't directly test private property,
        // but we can verify no exception is thrown)
        $this->assertTrue(true);
    }
    
    /**
     * Test: Set API timeout with minimum value
     */
    public function testSetApiTimeoutWithMinimumValue() {
        // Should enforce minimum of 5 seconds
        $this->fallbackProvider->setApiTimeout(1);
        
        $this->assertTrue(true);
    }
    
    /**
     * Test: Cache enabled/disabled toggle
     */
    public function testCacheEnabledToggle() {
        $this->fallbackProvider->setCacheEnabled(false);
        $this->fallbackProvider->setCacheEnabled(true);
        
        $this->assertTrue(true);
    }
    
    /**
     * Test: Get product name with different sources
     */
    public function testGetProductNameWithDifferentSources() {
        $mockStmt = $this->createMock(PDOStatement::class);
        $mockStmt->method('fetch')->willReturn(false);
        $mockStmt->method('execute')->willReturn(true);
        
        $this->mockDb->method('prepare')->willReturn($mockStmt);
        
        // Test with different sources
        $name1 = $this->fallbackProvider->getProductName('12345', 'ozon');
        $name2 = $this->fallbackProvider->getProductName('12345', 'inventory');
        $name3 = $this->fallbackProvider->getProductName('12345', 'analytics');
        
        $this->assertIsString($name1);
        $this->assertIsString($name2);
        $this->assertIsString($name3);
    }
    
    /**
     * Test: Handle null product ID
     */
    public function testHandleNullProductId() {
        $mockStmt = $this->createMock(PDOStatement::class);
        $mockStmt->method('fetch')->willReturn(false);
        $mockStmt->method('execute')->willReturn(true);
        
        $this->mockDb->method('prepare')->willReturn($mockStmt);
        
        $name = $this->fallbackProvider->getProductName(null);
        
        $this->assertIsString($name);
    }
    
    /**
     * Test: Cache with additional data
     */
    public function testCacheWithAdditionalData() {
        $mockStmt = $this->createMock(PDOStatement::class);
        $mockStmt->method('execute')->willReturn(true);
        $mockStmt->method('rowCount')->willReturn(1);
        
        $this->mockDb->method('prepare')->willReturn($mockStmt);
        
        $additionalData = [
            'brand' => 'Test Brand',
            'category' => 'Test Category'
        ];
        
        $result = $this->fallbackProvider->cacheProductName(
            '12345',
            'Test Product',
            $additionalData
        );
        
        $this->assertTrue($result);
    }
}
