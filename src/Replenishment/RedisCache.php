<?php

namespace Replenishment;

use Redis;
use Exception;

/**
 * RedisCache Class
 * 
 * Redis-based caching implementation for high-performance caching
 * of replenishment system data.
 */
class RedisCache
{
    private ?Redis $redis;
    private array $config;
    private bool $connected;
    
    public function __construct(array $config = [])
    {
        $this->config = array_merge([
            'host' => '127.0.0.1',
            'port' => 6379,
            'password' => null,
            'database' => 0,
            'timeout' => 2.0,
            'prefix' => 'replenishment:',
            'default_ttl' => 3600,
            'serializer' => Redis::SERIALIZER_JSON,
            'compression' => Redis::COMPRESSION_LZ4,
            'debug' => false,
            'fallback_enabled' => true
        ], $config);
        
        $this->connected = false;
        $this->redis = null;
        
        $this->connect();
    }
    
    /**
     * Connect to Redis server
     */
    private function connect(): void
    {
        try {
            if (!extension_loaded('redis')) {
                throw new Exception("Redis extension not loaded");
            }
            
            $this->redis = new Redis();
            
            $connected = $this->redis->connect(
                $this->config['host'],
                $this->config['port'],
                $this->config['timeout']
            );
            
            if (!$connected) {
                throw new Exception("Could not connect to Redis server");
            }
            
            // Authenticate if password is provided
            if ($this->config['password']) {
                if (!$this->redis->auth($this->config['password'])) {
                    throw new Exception("Redis authentication failed");
                }
            }
            
            // Select database
            $this->redis->select($this->config['database']);
            
            // Set serializer and compression
            if ($this->config['serializer']) {
                $this->redis->setOption(Redis::OPT_SERIALIZER, $this->config['serializer']);
            }
            
            if ($this->config['compression']) {
                $this->redis->setOption(Redis::OPT_COMPRESSION, $this->config['compression']);
            }
            
            // Set key prefix
            if ($this->config['prefix']) {
                $this->redis->setOption(Redis::OPT_PREFIX, $this->config['prefix']);
            }
            
            $this->connected = true;
            $this->log("Connected to Redis server at {$this->config['host']}:{$this->config['port']}");
            
        } catch (Exception $e) {
            $this->connected = false;
            $this->redis = null;
            $this->log("Redis connection failed: " . $e->getMessage(), 'ERROR');
            
            if (!$this->config['fallback_enabled']) {
                throw $e;
            }
        }
    }
    
    /**
     * Check if Redis is connected and available
     * 
     * @return bool True if connected
     */
    public function isConnected(): bool
    {
        if (!$this->connected || !$this->redis) {
            return false;
        }
        
        try {
            $this->redis->ping();
            return true;
        } catch (Exception $e) {
            $this->connected = false;
            $this->log("Redis connection lost: " . $e->getMessage(), 'WARN');
            return false;
        }
    }
    
    /**
     * Get value from cache
     * 
     * @param string $key Cache key
     * @return mixed|null Cached value or null if not found
     */
    public function get(string $key): mixed
    {
        if (!$this->isConnected()) {
            return null;
        }
        
        try {
            $value = $this->redis->get($key);
            
            if ($value === false) {
                $this->log("Cache MISS: $key");
                return null;
            }
            
            $this->log("Cache HIT: $key");
            return $value;
            
        } catch (Exception $e) {
            $this->log("Error getting cache key '$key': " . $e->getMessage(), 'ERROR');
            return null;
        }
    }
    
    /**
     * Set value in cache
     * 
     * @param string $key Cache key
     * @param mixed $value Value to cache
     * @param int|null $ttl Time to live in seconds
     * @return bool True if successful
     */
    public function set(string $key, mixed $value, ?int $ttl = null): bool
    {
        if (!$this->isConnected()) {
            return false;
        }
        
        $ttl = $ttl ?? $this->config['default_ttl'];
        
        try {
            $result = $this->redis->setex($key, $ttl, $value);
            
            if ($result) {
                $this->log("Cache SET: $key (TTL: {$ttl}s)");
            }
            
            return $result;
            
        } catch (Exception $e) {
            $this->log("Error setting cache key '$key': " . $e->getMessage(), 'ERROR');
            return false;
        }
    }
    
    /**
     * Delete key from cache
     * 
     * @param string $key Cache key
     * @return bool True if successful
     */
    public function delete(string $key): bool
    {
        if (!$this->isConnected()) {
            return false;
        }
        
        try {
            $result = $this->redis->del($key) > 0;
            
            if ($result) {
                $this->log("Cache DELETE: $key");
            }
            
            return $result;
            
        } catch (Exception $e) {
            $this->log("Error deleting cache key '$key': " . $e->getMessage(), 'ERROR');
            return false;
        }
    }
    
    /**
     * Check if key exists in cache
     * 
     * @param string $key Cache key
     * @return bool True if exists
     */
    public function exists(string $key): bool
    {
        if (!$this->isConnected()) {
            return false;
        }
        
        try {
            return $this->redis->exists($key) > 0;
        } catch (Exception $e) {
            $this->log("Error checking cache key '$key': " . $e->getMessage(), 'ERROR');
            return false;
        }
    }
    
    /**
     * Get multiple values from cache
     * 
     * @param array $keys Array of cache keys
     * @return array Associative array of key => value pairs
     */
    public function getMultiple(array $keys): array
    {
        if (!$this->isConnected() || empty($keys)) {
            return [];
        }
        
        try {
            $values = $this->redis->mget($keys);
            $result = [];
            
            foreach ($keys as $index => $key) {
                if ($values[$index] !== false) {
                    $result[$key] = $values[$index];
                    $this->log("Cache HIT: $key");
                } else {
                    $this->log("Cache MISS: $key");
                }
            }
            
            return $result;
            
        } catch (Exception $e) {
            $this->log("Error getting multiple cache keys: " . $e->getMessage(), 'ERROR');
            return [];
        }
    }
    
    /**
     * Set multiple values in cache
     * 
     * @param array $data Associative array of key => value pairs
     * @param int|null $ttl Time to live in seconds
     * @return bool True if successful
     */
    public function setMultiple(array $data, ?int $ttl = null): bool
    {
        if (!$this->isConnected() || empty($data)) {
            return false;
        }
        
        $ttl = $ttl ?? $this->config['default_ttl'];
        
        try {
            // Use pipeline for better performance
            $pipe = $this->redis->pipeline();
            
            foreach ($data as $key => $value) {
                $pipe->setex($key, $ttl, $value);
            }
            
            $results = $pipe->exec();
            
            $successCount = array_sum($results);
            $this->log("Cache SET MULTIPLE: $successCount/" . count($data) . " keys (TTL: {$ttl}s)");
            
            return $successCount === count($data);
            
        } catch (Exception $e) {
            $this->log("Error setting multiple cache keys: " . $e->getMessage(), 'ERROR');
            return false;
        }
    }
    
    /**
     * Delete multiple keys from cache
     * 
     * @param array $keys Array of cache keys
     * @return int Number of keys deleted
     */
    public function deleteMultiple(array $keys): int
    {
        if (!$this->isConnected() || empty($keys)) {
            return 0;
        }
        
        try {
            $deleted = $this->redis->del($keys);
            $this->log("Cache DELETE MULTIPLE: $deleted/" . count($keys) . " keys");
            
            return $deleted;
            
        } catch (Exception $e) {
            $this->log("Error deleting multiple cache keys: " . $e->getMessage(), 'ERROR');
            return 0;
        }
    }
    
    /**
     * Delete keys by pattern
     * 
     * @param string $pattern Key pattern (supports wildcards)
     * @return int Number of keys deleted
     */
    public function deleteByPattern(string $pattern): int
    {
        if (!$this->isConnected()) {
            return 0;
        }
        
        try {
            $keys = $this->redis->keys($pattern);
            
            if (empty($keys)) {
                return 0;
            }
            
            $deleted = $this->redis->del($keys);
            $this->log("Cache DELETE PATTERN '$pattern': $deleted keys");
            
            return $deleted;
            
        } catch (Exception $e) {
            $this->log("Error deleting cache pattern '$pattern': " . $e->getMessage(), 'ERROR');
            return 0;
        }
    }
    
    /**
     * Increment a numeric value in cache
     * 
     * @param string $key Cache key
     * @param int $increment Increment value (default 1)
     * @return int|false New value or false on error
     */
    public function increment(string $key, int $increment = 1): int|false
    {
        if (!$this->isConnected()) {
            return false;
        }
        
        try {
            if ($increment === 1) {
                $result = $this->redis->incr($key);
            } else {
                $result = $this->redis->incrBy($key, $increment);
            }
            
            $this->log("Cache INCREMENT: $key by $increment = $result");
            
            return $result;
            
        } catch (Exception $e) {
            $this->log("Error incrementing cache key '$key': " . $e->getMessage(), 'ERROR');
            return false;
        }
    }
    
    /**
     * Set expiration time for a key
     * 
     * @param string $key Cache key
     * @param int $ttl Time to live in seconds
     * @return bool True if successful
     */
    public function expire(string $key, int $ttl): bool
    {
        if (!$this->isConnected()) {
            return false;
        }
        
        try {
            $result = $this->redis->expire($key, $ttl);
            
            if ($result) {
                $this->log("Cache EXPIRE: $key (TTL: {$ttl}s)");
            }
            
            return $result;
            
        } catch (Exception $e) {
            $this->log("Error setting expiration for cache key '$key': " . $e->getMessage(), 'ERROR');
            return false;
        }
    }
    
    /**
     * Get time to live for a key
     * 
     * @param string $key Cache key
     * @return int TTL in seconds, -1 if no expiration, -2 if key doesn't exist
     */
    public function getTTL(string $key): int
    {
        if (!$this->isConnected()) {
            return -2;
        }
        
        try {
            return $this->redis->ttl($key);
        } catch (Exception $e) {
            $this->log("Error getting TTL for cache key '$key': " . $e->getMessage(), 'ERROR');
            return -2;
        }
    }
    
    /**
     * Clear all cache entries
     * 
     * @return bool True if successful
     */
    public function clear(): bool
    {
        if (!$this->isConnected()) {
            return false;
        }
        
        try {
            $result = $this->redis->flushDB();
            
            if ($result) {
                $this->log("Cache CLEAR: All entries deleted");
            }
            
            return $result;
            
        } catch (Exception $e) {
            $this->log("Error clearing cache: " . $e->getMessage(), 'ERROR');
            return false;
        }
    }
    
    /**
     * Get cache statistics
     * 
     * @return array Cache statistics
     */
    public function getStatistics(): array
    {
        $stats = [
            'connected' => $this->isConnected(),
            'host' => $this->config['host'],
            'port' => $this->config['port'],
            'database' => $this->config['database'],
            'prefix' => $this->config['prefix']
        ];
        
        if (!$this->isConnected()) {
            return $stats;
        }
        
        try {
            $info = $this->redis->info();
            
            $stats = array_merge($stats, [
                'redis_version' => $info['redis_version'] ?? 'unknown',
                'used_memory' => $info['used_memory'] ?? 0,
                'used_memory_human' => $info['used_memory_human'] ?? '0B',
                'connected_clients' => $info['connected_clients'] ?? 0,
                'total_commands_processed' => $info['total_commands_processed'] ?? 0,
                'keyspace_hits' => $info['keyspace_hits'] ?? 0,
                'keyspace_misses' => $info['keyspace_misses'] ?? 0,
                'hit_rate' => $this->calculateHitRate($info),
                'db_keys' => $this->getKeyCount()
            ]);
            
        } catch (Exception $e) {
            $this->log("Error getting cache statistics: " . $e->getMessage(), 'ERROR');
        }
        
        return $stats;
    }
    
    /**
     * Calculate cache hit rate
     * 
     * @param array $info Redis info array
     * @return float Hit rate percentage
     */
    private function calculateHitRate(array $info): float
    {
        $hits = (int)($info['keyspace_hits'] ?? 0);
        $misses = (int)($info['keyspace_misses'] ?? 0);
        $total = $hits + $misses;
        
        if ($total === 0) {
            return 0.0;
        }
        
        return round(($hits / $total) * 100, 2);
    }
    
    /**
     * Get number of keys in current database
     * 
     * @return int Number of keys
     */
    private function getKeyCount(): int
    {
        try {
            $info = $this->redis->info('keyspace');
            $dbKey = "db{$this->config['database']}";
            
            if (isset($info[$dbKey])) {
                // Parse "keys=123,expires=45,avg_ttl=678"
                preg_match('/keys=(\d+)/', $info[$dbKey], $matches);
                return (int)($matches[1] ?? 0);
            }
            
            return 0;
            
        } catch (Exception $e) {
            return 0;
        }
    }
    
    /**
     * Cache configuration parameters
     * 
     * @param array $config Configuration array
     * @param int|null $ttl Cache TTL
     * @return bool True if successful
     */
    public function cacheConfig(array $config, ?int $ttl = null): bool
    {
        return $this->set('config:replenishment', $config, $ttl ?? 7200);
    }
    
    /**
     * Get cached configuration
     * 
     * @return array|null Cached configuration or null if not found
     */
    public function getCachedConfig(): ?array
    {
        return $this->get('config:replenishment');
    }
    
    /**
     * Cache ADS result
     * 
     * @param int $productId Product ID
     * @param int $days Analysis days
     * @param float $ads ADS value
     * @param int|null $ttl Cache TTL
     * @return bool True if successful
     */
    public function cacheADS(int $productId, int $days, float $ads, ?int $ttl = null): bool
    {
        $key = "ads:{$productId}:{$days}";
        return $this->set($key, $ads, $ttl ?? 1800);
    }
    
    /**
     * Get cached ADS result
     * 
     * @param int $productId Product ID
     * @param int $days Analysis days
     * @return float|null Cached ADS or null if not found
     */
    public function getCachedADS(int $productId, int $days): ?float
    {
        $key = "ads:{$productId}:{$days}";
        return $this->get($key);
    }
    
    /**
     * Cache product information
     * 
     * @param int $productId Product ID
     * @param array $productInfo Product information
     * @param int|null $ttl Cache TTL
     * @return bool True if successful
     */
    public function cacheProductInfo(int $productId, array $productInfo, ?int $ttl = null): bool
    {
        $key = "product:{$productId}";
        return $this->set($key, $productInfo, $ttl ?? 3600);
    }
    
    /**
     * Get cached product information
     * 
     * @param int $productId Product ID
     * @return array|null Cached product info or null if not found
     */
    public function getCachedProductInfo(int $productId): ?array
    {
        $key = "product:{$productId}";
        return $this->get($key);
    }
    
    /**
     * Cache current stock
     * 
     * @param int $productId Product ID
     * @param int $stock Current stock
     * @param int|null $ttl Cache TTL
     * @return bool True if successful
     */
    public function cacheCurrentStock(int $productId, int $stock, ?int $ttl = null): bool
    {
        $key = "stock:{$productId}";
        return $this->set($key, $stock, $ttl ?? 900);
    }
    
    /**
     * Get cached current stock
     * 
     * @param int $productId Product ID
     * @return int|null Cached stock or null if not found
     */
    public function getCachedCurrentStock(int $productId): ?int
    {
        $key = "stock:{$productId}";
        return $this->get($key);
    }
    
    /**
     * Invalidate cache entries by pattern
     * 
     * @param string $pattern Cache key pattern
     * @return int Number of keys invalidated
     */
    public function invalidateByPattern(string $pattern): int
    {
        return $this->deleteByPattern($pattern);
    }
    
    /**
     * Log debug messages
     * 
     * @param string $message Message to log
     * @param string $level Log level
     */
    private function log(string $message, string $level = 'INFO'): void
    {
        if ($this->config['debug']) {
            echo "[RedisCache] [$level] " . date('Y-m-d H:i:s') . " - $message\n";
        }
    }
    
    /**
     * Close Redis connection
     */
    public function __destruct()
    {
        if ($this->redis && $this->connected) {
            try {
                $this->redis->close();
            } catch (Exception $e) {
                // Ignore errors during cleanup
            }
        }
    }
}
?>