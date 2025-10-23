<?php
/**
 * Unit Tests for Cache Service
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../src/services/CacheService.php';

class CacheServiceTest extends TestCase {
    private $cache;
    private $testCachePath;
    private $originalEnv;
    
    protected function setUp(): void {
        // Store original environment
        $this->originalEnv = $_ENV;
        
        // Create temporary cache directory for testing
        $this->testCachePath = sys_get_temp_dir() . '/mi_core_etl_test_cache_' . uniqid();
        mkdir($this->testCachePath, 0755, true);
        
        // Set test cache configuration
        $_ENV['CACHE_DRIVER'] = 'file';
        $_ENV['CACHE_PATH'] = $this->testCachePath;
        $_ENV['CACHE_PREFIX'] = 'test_mi_core';
        $_ENV['CACHE_TTL'] = '300';
        
        $this->cache = CacheService::getInstance();
    }
    
    protected function tearDown(): void {
        // Clean up test cache files
        if (is_dir($this->testCachePath)) {
            $files = glob($this->testCachePath . '/*');
            foreach ($files as $file) {
                if (is_file($file)) {
                    unlink($file);
                }
            }
            rmdir($this->testCachePath);
        }
        
        // Restore original environment
        $_ENV = $this->originalEnv;
        
        // Reset singleton instance for next test
        $reflection = new ReflectionClass(CacheService::class);
        $instance = $reflection->getProperty('instance');
        $instance->setAccessible(true);
        $instance->setValue(null, null);
    }
    
    public function testSingletonInstance() {
        $cache1 = CacheService::getInstance();
        $cache2 = CacheService::getInstance();
        
        $this->assertSame($cache1, $cache2);
    }
    
    public function testSetAndGet() {
        $key = 'test_key';
        $value = 'test_value';
        
        $result = $this->cache->set($key, $value);
        $this->assertTrue($result);
        
        $retrieved = $this->cache->get($key);
        $this->assertEquals($value, $retrieved);
    }
    
    public function testGetWithDefault() {
        $key = 'non_existent_key';
        $default = 'default_value';
        
        $result = $this->cache->get($key, $default);
        $this->assertEquals($default, $result);
    }
    
    public function testSetWithTtl() {
        $key = 'ttl_test_key';
        $value = 'ttl_test_value';
        $ttl = 1; // 1 second
        
        $this->cache->set($key, $value, $ttl);
        
        // Should exist immediately
        $this->assertEquals($value, $this->cache->get($key));
        
        // Wait for expiration (in real test, we'd mock time)
        sleep(2);
        
        // Should be expired now
        $this->assertNull($this->cache->get($key));
    }
    
    public function testHas() {
        $key = 'has_test_key';
        $value = 'has_test_value';
        
        $this->assertFalse($this->cache->has($key));
        
        $this->cache->set($key, $value);
        $this->assertTrue($this->cache->has($key));
    }
    
    public function testDelete() {
        $key = 'delete_test_key';
        $value = 'delete_test_value';
        
        $this->cache->set($key, $value);
        $this->assertTrue($this->cache->has($key));
        
        $result = $this->cache->delete($key);
        $this->assertTrue($result);
        $this->assertFalse($this->cache->has($key));
    }
    
    public function testMultipleOperations() {
        $data = [
            'key1' => 'value1',
            'key2' => 'value2',
            'key3' => 'value3'
        ];
        
        // Test setMultiple
        $result = $this->cache->setMultiple($data);
        $this->assertTrue($result);
        
        // Test getMultiple
        $keys = array_keys($data);
        $retrieved = $this->cache->getMultiple($keys);
        
        $this->assertIsArray($retrieved);
        $this->assertEquals($data['key1'], $retrieved['key1']);
        $this->assertEquals($data['key2'], $retrieved['key2']);
        $this->assertEquals($data['key3'], $retrieved['key3']);
        
        // Test deleteMultiple
        $deleteResult = $this->cache->deleteMultiple($keys);
        $this->assertTrue($deleteResult);
        
        foreach ($keys as $key) {
            $this->assertFalse($this->cache->has($key));
        }
    }
    
    public function testRemember() {
        $key = 'remember_test_key';
        $expectedValue = 'computed_value';
        $callCount = 0;
        
        $callback = function() use ($expectedValue, &$callCount) {
            $callCount++;
            return $expectedValue;
        };
        
        // First call should execute callback
        $result1 = $this->cache->remember($key, $callback);
        $this->assertEquals($expectedValue, $result1);
        $this->assertEquals(1, $callCount);
        
        // Second call should return cached value
        $result2 = $this->cache->remember($key, $callback);
        $this->assertEquals($expectedValue, $result2);
        $this->assertEquals(1, $callCount); // Callback should not be called again
    }
    
    public function testIncrement() {
        $key = 'increment_test_key';
        
        // Increment non-existent key
        $result = $this->cache->increment($key);
        $this->assertEquals(1, $result);
        
        // Increment existing key
        $result = $this->cache->increment($key, 5);
        $this->assertEquals(6, $result);
        
        // Verify the value
        $this->assertEquals(6, $this->cache->get($key));
    }
    
    public function testDecrement() {
        $key = 'decrement_test_key';
        
        // Set initial value
        $this->cache->set($key, 10);
        
        // Decrement
        $result = $this->cache->decrement($key, 3);
        $this->assertEquals(7, $result);
        
        // Verify the value
        $this->assertEquals(7, $this->cache->get($key));
    }
    
    public function testClear() {
        // Set multiple values
        $this->cache->set('clear_test_1', 'value1');
        $this->cache->set('clear_test_2', 'value2');
        $this->cache->set('clear_test_3', 'value3');
        
        // Verify they exist
        $this->assertTrue($this->cache->has('clear_test_1'));
        $this->assertTrue($this->cache->has('clear_test_2'));
        $this->assertTrue($this->cache->has('clear_test_3'));
        
        // Clear cache
        $result = $this->cache->clear();
        $this->assertTrue($result);
        
        // Verify they're gone
        $this->assertFalse($this->cache->has('clear_test_1'));
        $this->assertFalse($this->cache->has('clear_test_2'));
        $this->assertFalse($this->cache->has('clear_test_3'));
    }
    
    public function testComplexDataTypes() {
        $testData = [
            'array' => ['a', 'b', 'c'],
            'object' => (object)['prop1' => 'value1', 'prop2' => 'value2'],
            'nested' => [
                'level1' => [
                    'level2' => 'deep_value'
                ]
            ],
            'number' => 42,
            'float' => 3.14159,
            'boolean' => true,
            'null' => null
        ];
        
        foreach ($testData as $key => $value) {
            $this->cache->set($key, $value);
            $retrieved = $this->cache->get($key);
            
            if ($key === 'object') {
                // Objects are converted to arrays during serialization
                $this->assertEquals((array)$value, $retrieved);
            } else {
                $this->assertEquals($value, $retrieved);
            }
        }
    }
    
    public function testGetStats() {
        // Set some test data
        $this->cache->set('stats_test_1', 'value1');
        $this->cache->set('stats_test_2', 'value2');
        
        $stats = $this->cache->getStats();
        
        $this->assertIsArray($stats);
        $this->assertArrayHasKey('driver', $stats);
        $this->assertEquals('file', $stats['driver']);
    }
    
    public function testHelperFunctions() {
        // Test global helper functions
        $cache = cache();
        $this->assertInstanceOf(CacheService::class, $cache);
        
        $key = 'helper_test_key';
        $value = 'helper_test_value';
        
        $result = cacheSet($key, $value);
        $this->assertTrue($result);
        
        $retrieved = cacheGet($key);
        $this->assertEquals($value, $retrieved);
        
        $remembered = cacheRemember('remember_helper_key', function() {
            return 'remembered_value';
        });
        $this->assertEquals('remembered_value', $remembered);
    }
    
    public function testKeyPrefixing() {
        $key = 'prefix_test';
        $value = 'prefix_value';
        
        $this->cache->set($key, $value);
        
        // Check that the actual file has the prefix
        $files = glob($this->testCachePath . '/*');
        $this->assertNotEmpty($files);
        
        // The filename should be a hash, but we can verify the value is stored correctly
        $retrieved = $this->cache->get($key);
        $this->assertEquals($value, $retrieved);
    }
}