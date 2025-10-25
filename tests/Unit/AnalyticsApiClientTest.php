<?php
/**
 * Unit Tests for AnalyticsApiClient
 * 
 * Tests for the Analytics API client service including pagination,
 * retry logic, rate limiting, and caching functionality.
 * 
 * Task: 4.1 Создать AnalyticsApiClient сервис (tests)
 */

require_once __DIR__ . '/../../src/Services/AnalyticsApiClient.php';

class AnalyticsApiClientTest extends PHPUnit\Framework\TestCase {
    private AnalyticsApiClient $client;
    private PDO $mockPdo;
    
    protected function setUp(): void {
        // Create mock PDO for testing
        $this->mockPdo = new PDO('sqlite::memory:');
        $this->mockPdo->exec("
            CREATE TABLE analytics_api_cache (
                cache_key VARCHAR(255) PRIMARY KEY,
                data TEXT NOT NULL,
                expires_at DATETIME NOT NULL,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP
            )
        ");
        
        $this->mockPdo->exec("
            CREATE TABLE analytics_etl_log (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                batch_id VARCHAR(255),
                etl_type VARCHAR(50),
                status VARCHAR(20),
                records_processed INTEGER,
                api_requests_made INTEGER,
                execution_time_ms INTEGER,
                data_source VARCHAR(50),
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP
            )
        ");
        
        $this->client = new AnalyticsApiClient('test_client_id', 'test_api_key', $this->mockPdo);
    }
    
    public function testConstructorValidation(): void {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Client ID cannot be empty');
        
        new AnalyticsApiClient('', 'test_api_key');
    }
    
    public function testConstructorApiKeyValidation(): void {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('API Key cannot be empty');
        
        new AnalyticsApiClient('test_client_id', '');
    }
    
    public function testPaginationValidation(): void {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Offset must be non-negative');
        
        // Use reflection to test private method
        $reflection = new ReflectionClass($this->client);
        $method = $reflection->getMethod('validatePaginationParams');
        $method->setAccessible(true);
        
        $method->invoke($this->client, -1, 100);
    }
    
    public function testPaginationLimitValidation(): void {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Limit must be positive');
        
        $reflection = new ReflectionClass($this->client);
        $method = $reflection->getMethod('validatePaginationParams');
        $method->setAccessible(true);
        
        $method->invoke($this->client, 0, 0);
    }
    
    public function testPaginationMaxLimitValidation(): void {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Limit cannot exceed 1000');
        
        $reflection = new ReflectionClass($this->client);
        $method = $reflection->getMethod('validatePaginationParams');
        $method->setAccessible(true);
        
        $method->invoke($this->client, 0, 1001);
    }
    
    public function testBuildStockWarehousesPayload(): void {
        $reflection = new ReflectionClass($this->client);
        $method = $reflection->getMethod('buildStockWarehousesPayload');
        $method->setAccessible(true);
        
        $filters = [
            'date_from' => '2025-01-01',
            'date_to' => '2025-01-31',
            'warehouse_names' => ['РФЦ Москва', 'РФЦ СПб'],
            'sku_list' => ['SKU001', 'SKU002']
        ];
        
        $payload = $method->invoke($this->client, 100, 500, $filters);
        
        $this->assertEquals(100, $payload['offset']);
        $this->assertEquals(500, $payload['limit']);
        $this->assertEquals('2025-01-01', $payload['date_from']);
        $this->assertEquals('2025-01-31', $payload['date_to']);
        $this->assertEquals(['РФЦ Москва', 'РФЦ СПб'], $payload['warehouse_names']);
        $this->assertEquals(['SKU001', 'SKU002'], $payload['sku_list']);
    }
    
    public function testProcessStockRecord(): void {
        $reflection = new ReflectionClass($this->client);
        $method = $reflection->getMethod('processStockRecord');
        $method->setAccessible(true);
        
        $rawRecord = [
            'sku' => 'TEST_SKU_001',
            'warehouse_name' => '  РФЦ Москва  ',
            'available_stock' => '150',
            'reserved_stock' => '25',
            'total_stock' => '175',
            'product_name' => 'Test Product',
            'category' => 'Electronics',
            'brand' => 'Test Brand',
            'price' => '1999.99',
            'currency' => 'RUB',
            'updated_at' => '2025-01-15 10:30:00'
        ];
        
        $processed = $method->invoke($this->client, $rawRecord);
        
        $this->assertEquals('TEST_SKU_001', $processed['sku']);
        $this->assertEquals('РФЦ Москва', $processed['warehouse_name']); // Trimmed
        $this->assertEquals(150, $processed['available_stock']);
        $this->assertEquals(25, $processed['reserved_stock']);
        $this->assertEquals(175, $processed['total_stock']);
        $this->assertEquals('Test Product', $processed['product_name']);
        $this->assertEquals('api', $processed['data_source']);
        $this->assertEquals(100, $processed['data_quality_score']);
    }
    
    public function testProcessStockRecordInvalidData(): void {
        $reflection = new ReflectionClass($this->client);
        $method = $reflection->getMethod('processStockRecord');
        $method->setAccessible(true);
        
        // Test with missing SKU
        $invalidRecord = [
            'warehouse_name' => 'РФЦ Москва',
            'available_stock' => '150'
        ];
        
        $result = $method->invoke($this->client, $invalidRecord);
        $this->assertNull($result);
        
        // Test with missing warehouse_name
        $invalidRecord2 = [
            'sku' => 'TEST_SKU_001',
            'available_stock' => '150'
        ];
        
        $result2 = $method->invoke($this->client, $invalidRecord2);
        $this->assertNull($result2);
    }
    
    public function testProcessStockRecordNegativeValues(): void {
        $reflection = new ReflectionClass($this->client);
        $method = $reflection->getMethod('processStockRecord');
        $method->setAccessible(true);
        
        $recordWithNegatives = [
            'sku' => 'TEST_SKU_001',
            'warehouse_name' => 'РФЦ Москва',
            'available_stock' => '-10', // Negative value
            'reserved_stock' => '-5',   // Negative value
            'total_stock' => '-15',     // Negative value
            'price' => '-100.50'        // Negative price
        ];
        
        $processed = $method->invoke($this->client, $recordWithNegatives);
        
        // Should convert negative values to 0
        $this->assertEquals(0, $processed['available_stock']);
        $this->assertEquals(0, $processed['reserved_stock']);
        $this->assertEquals(0, $processed['total_stock']);
        $this->assertEquals(0, $processed['price']);
    }
    
    public function testCacheKeyGeneration(): void {
        $reflection = new ReflectionClass($this->client);
        $method = $reflection->getMethod('generateCacheKey');
        $method->setAccessible(true);
        
        $key1 = $method->invoke($this->client, 'stock_warehouses', 0, 100, []);
        $key2 = $method->invoke($this->client, 'stock_warehouses', 0, 100, []);
        $key3 = $method->invoke($this->client, 'stock_warehouses', 100, 100, []);
        
        // Same parameters should generate same key
        $this->assertEquals($key1, $key2);
        
        // Different parameters should generate different keys
        $this->assertNotEquals($key1, $key3);
        
        // Key should start with cache prefix
        $this->assertStringStartsWith('analytics_api_', $key1);
        
        // Key should not exceed 255 characters
        $this->assertLessThanOrEqual(255, strlen($key1));
    }
    
    public function testCacheSetAndGet(): void {
        $reflection = new ReflectionClass($this->client);
        $setCacheMethod = $reflection->getMethod('setCachedData');
        $getCacheMethod = $reflection->getMethod('getCachedData');
        $setCacheMethod->setAccessible(true);
        $getCacheMethod->setAccessible(true);
        
        $testData = [
            'data' => [
                ['sku' => 'TEST001', 'warehouse_name' => 'РФЦ Москва']
            ],
            'total_count' => 1
        ];
        
        $cacheKey = 'test_cache_key';
        
        // Set cache data
        $setCacheMethod->invoke($this->client, $cacheKey, $testData);
        
        // Get cache data
        $cachedData = $getCacheMethod->invoke($this->client, $cacheKey);
        
        $this->assertEquals($testData, $cachedData);
    }
    
    public function testCacheExpiration(): void {
        $reflection = new ReflectionClass($this->client);
        $setCacheMethod = $reflection->getMethod('setCachedData');
        $getCacheMethod = $reflection->getMethod('getCachedData');
        $setCacheMethod->setAccessible(true);
        $getCacheMethod->setAccessible(true);
        
        $testData = ['test' => 'data'];
        $cacheKey = 'expired_test_key';
        
        // Manually insert expired cache entry
        $stmt = $this->mockPdo->prepare(
            "INSERT INTO analytics_api_cache (cache_key, data, expires_at) 
             VALUES (?, ?, datetime('now', '-1 hour'))"
        );
        $stmt->execute([$cacheKey, json_encode($testData)]);
        
        // Should return null for expired cache
        $cachedData = $getCacheMethod->invoke($this->client, $cacheKey);
        $this->assertNull($cachedData);
    }
    
    public function testClearExpiredCache(): void {
        // Insert some expired and valid cache entries
        $stmt = $this->mockPdo->prepare(
            "INSERT INTO analytics_api_cache (cache_key, data, expires_at) VALUES (?, ?, ?)"
        );
        
        // Expired entry
        $stmt->execute(['expired_key', '{"test": "data"}', date('Y-m-d H:i:s', time() - 3600)]);
        
        // Valid entry
        $stmt->execute(['valid_key', '{"test": "data"}', date('Y-m-d H:i:s', time() + 3600)]);
        
        $clearedCount = $this->client->clearExpiredCache();
        
        $this->assertGreaterThanOrEqual(1, $clearedCount);
        
        // Check that valid entry still exists
        $stmt = $this->mockPdo->prepare("SELECT COUNT(*) FROM analytics_api_cache WHERE cache_key = ?");
        $stmt->execute(['valid_key']);
        $this->assertEquals(1, $stmt->fetchColumn());
        
        // Check that expired entry was removed
        $stmt->execute(['expired_key']);
        $this->assertEquals(0, $stmt->fetchColumn());
    }
    
    public function testDetermineErrorType(): void {
        $reflection = new ReflectionClass($this->client);
        $method = $reflection->getMethod('determineErrorType');
        $method->setAccessible(true);
        
        $this->assertEquals('AUTHENTICATION_ERROR', $method->invoke($this->client, 401, []));
        $this->assertEquals('AUTHENTICATION_ERROR', $method->invoke($this->client, 403, []));
        $this->assertEquals('RATE_LIMIT_ERROR', $method->invoke($this->client, 429, []));
        $this->assertEquals('VALIDATION_ERROR', $method->invoke($this->client, 400, []));
        $this->assertEquals('NOT_FOUND_ERROR', $method->invoke($this->client, 404, []));
        $this->assertEquals('SERVER_ERROR', $method->invoke($this->client, 500, []));
        $this->assertEquals('SERVER_ERROR', $method->invoke($this->client, 502, []));
        $this->assertEquals('UNKNOWN_ERROR', $method->invoke($this->client, 418, [])); // I'm a teapot
    }
    
    public function testGetStats(): void {
        $stats = $this->client->getStats();
        
        $this->assertIsArray($stats);
        $this->assertArrayHasKey('client_id', $stats);
        $this->assertArrayHasKey('cache_entries', $stats);
        $this->assertArrayHasKey('request_history_count', $stats);
        $this->assertArrayHasKey('rate_limit_per_minute', $stats);
        $this->assertArrayHasKey('max_retries', $stats);
        $this->assertArrayHasKey('cache_ttl', $stats);
        
        $this->assertEquals('test_client_id', $stats['client_id']);
        $this->assertEquals(30, $stats['rate_limit_per_minute']);
        $this->assertEquals(3, $stats['max_retries']);
        $this->assertEquals(7200, $stats['cache_ttl']);
    }
    
    public function testAnalyticsApiExceptionErrorType(): void {
        $exception = new AnalyticsApiException('Test error', 401, 'AUTHENTICATION_ERROR');
        
        $this->assertEquals('AUTHENTICATION_ERROR', $exception->getErrorType());
        $this->assertTrue($exception->isCritical());
        
        $nonCriticalException = new AnalyticsApiException('Test error', 400, 'VALIDATION_ERROR');
        $this->assertFalse($nonCriticalException->isCritical());
    }
    
    public function testGenerateBatchId(): void {
        $reflection = new ReflectionClass($this->client);
        $method = $reflection->getMethod('generateBatchId');
        $method->setAccessible(true);
        
        $batchId1 = $method->invoke($this->client);
        $batchId2 = $method->invoke($this->client);
        
        // Should start with 'analytics_'
        $this->assertStringStartsWith('analytics_', $batchId1);
        $this->assertStringStartsWith('analytics_', $batchId2);
        
        // Should be unique
        $this->assertNotEquals($batchId1, $batchId2);
        
        // Should contain date
        $this->assertStringContains(date('Ymd'), $batchId1);
    }
    
    protected function tearDown(): void {
        $this->mockPdo = null;
        $this->client = null;
    }
}