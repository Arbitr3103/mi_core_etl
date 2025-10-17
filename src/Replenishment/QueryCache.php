<?php

namespace Replenishment;

use PDO;
use Exception;

/**
 * QueryCache Class
 * 
 * Provides query result caching functionality for replenishment system
 * to improve performance by reducing database load.
 */
class QueryCache
{
    private PDO $pdo;
    private array $config;
    private array $memoryCache;
    
    public function __construct(PDO $pdo, array $config = [])
    {
        $this->pdo = $pdo;
        $this->config = array_merge([
            'cache_ttl' => 3600,           // 1 hour default TTL
            'max_memory_items' => 1000,    // Max items in memory cache
            'enable_memory_cache' => true,  // Enable in-memory caching
            'enable_db_cache' => true,     // Enable database caching
            'debug' => false
        ], $config);
        
        $this->memoryCache = [];
        $this->initializeCacheTable();
    }
    
    /**
     * Get cached result or execute query and cache result
     * 
     * @param string $cacheKey Unique cache key
     * @param callable $queryCallback Callback that returns query result
     * @param int|null $ttl Time to live in seconds (null for default)
     * @return mixed Cached or fresh query result
     */
    public function getOrSet(string $cacheKey, callable $queryCallback, ?int $ttl = null): mixed
    {
        $ttl = $ttl ?? $this->config['cache_ttl'];
        
        // Try memory cache first
        if ($this->config['enable_memory_cache']) {
            $memoryResult = $this->getFromMemoryCache($cacheKey);
            if ($memoryResult !== null) {
                $this->log("Cache HIT (memory): $cacheKey");
                return $memoryResult;
            }
        }
        
        // Try database cache
        if ($this->config['enable_db_cache']) {
            $dbResult = $this->getFromDatabaseCache($cacheKey);
            if ($dbResult !== null) {
                $this->log("Cache HIT (database): $cacheKey");
                
                // Store in memory cache for faster subsequent access
                if ($this->config['enable_memory_cache']) {
                    $this->setMemoryCache($cacheKey, $dbResult);
                }
                
                return $dbResult;
            }
        }
        
        // Cache miss - execute query
        $this->log("Cache MISS: $cacheKey - executing query");
        $startTime = microtime(true);
        
        try {
            $result = $queryCallback();
            $executionTime = round((microtime(true) - $startTime) * 1000, 2);
            
            $this->log("Query executed in {$executionTime}ms: $cacheKey");
            
            // Cache the result
            $this->cacheResult($cacheKey, $result, $ttl);
            
            return $result;
            
        } catch (Exception $e) {
            $this->log("Query execution failed for $cacheKey: " . $e->getMessage());
            throw $e;
        }
    }
    
    /**
     * Cache ADS calculation result
     * 
     * @param int $productId Product ID
     * @param int $days Analysis days
     * @param float $ads Calculated ADS
     * @param int $ttl Cache TTL
     */
    public function cacheADS(int $productId, int $days, float $ads, int $ttl = 1800): void
    {
        $cacheKey = "ads_{$productId}_{$days}";
        $this->cacheResult($cacheKey, $ads, $ttl);
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
        $cacheKey = "ads_{$productId}_{$days}";
        
        $result = $this->getFromMemoryCache($cacheKey);
        if ($result !== null) {
            return $result;
        }
        
        return $this->getFromDatabaseCache($cacheKey);
    }
    
    /**
     * Cache configuration parameters
     * 
     * @param array $config Configuration array
     * @param int $ttl Cache TTL
     */
    public function cacheConfig(array $config, int $ttl = 7200): void
    {
        $cacheKey = 'replenishment_config';
        $this->cacheResult($cacheKey, $config, $ttl);
    }
    
    /**
     * Get cached configuration
     * 
     * @return array|null Cached configuration or null if not found
     */
    public function getCachedConfig(): ?array
    {
        $cacheKey = 'replenishment_config';
        
        $result = $this->getFromMemoryCache($cacheKey);
        if ($result !== null) {
            return $result;
        }
        
        return $this->getFromDatabaseCache($cacheKey);
    }
    
    /**
     * Cache product information
     * 
     * @param int $productId Product ID
     * @param array $productInfo Product information
     * @param int $ttl Cache TTL
     */
    public function cacheProductInfo(int $productId, array $productInfo, int $ttl = 3600): void
    {
        $cacheKey = "product_info_{$productId}";
        $this->cacheResult($cacheKey, $productInfo, $ttl);
    }
    
    /**
     * Get cached product information
     * 
     * @param int $productId Product ID
     * @return array|null Cached product info or null if not found
     */
    public function getCachedProductInfo(int $productId): ?array
    {
        $cacheKey = "product_info_{$productId}";
        
        $result = $this->getFromMemoryCache($cacheKey);
        if ($result !== null) {
            return $result;
        }
        
        return $this->getFromDatabaseCache($cacheKey);
    }
    
    /**
     * Cache current stock information
     * 
     * @param int $productId Product ID
     * @param int $stock Current stock
     * @param int $ttl Cache TTL
     */
    public function cacheCurrentStock(int $productId, int $stock, int $ttl = 900): void
    {
        $cacheKey = "current_stock_{$productId}";
        $this->cacheResult($cacheKey, $stock, $ttl);
    }
    
    /**
     * Get cached current stock
     * 
     * @param int $productId Product ID
     * @return int|null Cached stock or null if not found
     */
    public function getCachedCurrentStock(int $productId): ?int
    {
        $cacheKey = "current_stock_{$productId}";
        
        $result = $this->getFromMemoryCache($cacheKey);
        if ($result !== null) {
            return $result;
        }
        
        return $this->getFromDatabaseCache($cacheKey);
    }
    
    /**
     * Invalidate cache entries by pattern
     * 
     * @param string $pattern Cache key pattern (supports wildcards)
     */
    public function invalidateByPattern(string $pattern): void
    {
        $this->log("Invalidating cache entries matching pattern: $pattern");
        
        // Invalidate memory cache
        if ($this->config['enable_memory_cache']) {
            $pattern = str_replace('*', '.*', $pattern);
            foreach (array_keys($this->memoryCache) as $key) {
                if (preg_match("/^$pattern$/", $key)) {
                    unset($this->memoryCache[$key]);
                }
            }
        }
        
        // Invalidate database cache
        if ($this->config['enable_db_cache']) {
            try {
                $sql = "DELETE FROM query_cache WHERE cache_key LIKE ?";
                $stmt = $this->pdo->prepare($sql);
                $stmt->execute([str_replace('*', '%', $pattern)]);
                
                $deletedCount = $stmt->rowCount();
                $this->log("Invalidated $deletedCount database cache entries");
                
            } catch (Exception $e) {
                $this->log("Error invalidating database cache: " . $e->getMessage());
            }
        }
    }
    
    /**
     * Clear all cache entries
     */
    public function clearAll(): void
    {
        $this->log("Clearing all cache entries");
        
        // Clear memory cache
        $this->memoryCache = [];
        
        // Clear database cache
        if ($this->config['enable_db_cache']) {
            try {
                $this->pdo->exec("DELETE FROM query_cache");
                $this->log("Database cache cleared");
            } catch (Exception $e) {
                $this->log("Error clearing database cache: " . $e->getMessage());
            }
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
            'memory_cache_size' => count($this->memoryCache),
            'memory_cache_enabled' => $this->config['enable_memory_cache'],
            'database_cache_enabled' => $this->config['enable_db_cache'],
            'database_cache_size' => 0,
            'cache_ttl' => $this->config['cache_ttl']
        ];
        
        // Get database cache size
        if ($this->config['enable_db_cache']) {
            try {
                $stmt = $this->pdo->query("SELECT COUNT(*) FROM query_cache WHERE expires_at > NOW()");
                $stats['database_cache_size'] = (int)$stmt->fetchColumn();
            } catch (Exception $e) {
                $this->log("Error getting database cache size: " . $e->getMessage());
            }
        }
        
        return $stats;
    }
    
    /**
     * Cleanup expired cache entries
     */
    public function cleanup(): void
    {
        $this->log("Running cache cleanup");
        
        // Cleanup memory cache (check TTL)
        $now = time();
        $cleanedMemory = 0;
        
        foreach ($this->memoryCache as $key => $data) {
            if (isset($data['expires_at']) && $data['expires_at'] < $now) {
                unset($this->memoryCache[$key]);
                $cleanedMemory++;
            }
        }
        
        // Cleanup database cache
        $cleanedDatabase = 0;
        if ($this->config['enable_db_cache']) {
            try {
                $stmt = $this->pdo->prepare("DELETE FROM query_cache WHERE expires_at < NOW()");
                $stmt->execute();
                $cleanedDatabase = $stmt->rowCount();
            } catch (Exception $e) {
                $this->log("Error cleaning database cache: " . $e->getMessage());
            }
        }
        
        $this->log("Cache cleanup completed: $cleanedMemory memory entries, $cleanedDatabase database entries");
    }
    
    /**
     * Cache a result in both memory and database
     * 
     * @param string $cacheKey Cache key
     * @param mixed $result Result to cache
     * @param int $ttl Time to live in seconds
     */
    private function cacheResult(string $cacheKey, mixed $result, int $ttl): void
    {
        // Cache in memory
        if ($this->config['enable_memory_cache']) {
            $this->setMemoryCache($cacheKey, $result, $ttl);
        }
        
        // Cache in database
        if ($this->config['enable_db_cache']) {
            $this->setDatabaseCache($cacheKey, $result, $ttl);
        }
    }
    
    /**
     * Get result from memory cache
     * 
     * @param string $cacheKey Cache key
     * @return mixed|null Cached result or null if not found/expired
     */
    private function getFromMemoryCache(string $cacheKey): mixed
    {
        if (!isset($this->memoryCache[$cacheKey])) {
            return null;
        }
        
        $data = $this->memoryCache[$cacheKey];
        
        // Check expiration
        if (isset($data['expires_at']) && $data['expires_at'] < time()) {
            unset($this->memoryCache[$cacheKey]);
            return null;
        }
        
        return $data['value'];
    }
    
    /**
     * Set result in memory cache
     * 
     * @param string $cacheKey Cache key
     * @param mixed $result Result to cache
     * @param int|null $ttl Time to live in seconds
     */
    private function setMemoryCache(string $cacheKey, mixed $result, ?int $ttl = null): void
    {
        // Enforce memory cache size limit
        if (count($this->memoryCache) >= $this->config['max_memory_items']) {
            // Remove oldest entries (simple FIFO)
            $keysToRemove = array_slice(array_keys($this->memoryCache), 0, 100);
            foreach ($keysToRemove as $key) {
                unset($this->memoryCache[$key]);
            }
        }
        
        $ttl = $ttl ?? $this->config['cache_ttl'];
        
        $this->memoryCache[$cacheKey] = [
            'value' => $result,
            'expires_at' => time() + $ttl,
            'created_at' => time()
        ];
    }
    
    /**
     * Get result from database cache
     * 
     * @param string $cacheKey Cache key
     * @return mixed|null Cached result or null if not found/expired
     */
    private function getFromDatabaseCache(string $cacheKey): mixed
    {
        try {
            $sql = "
                SELECT cache_value 
                FROM query_cache 
                WHERE cache_key = ? AND expires_at > NOW()
            ";
            
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute([$cacheKey]);
            
            $result = $stmt->fetchColumn();
            
            if ($result === false) {
                return null;
            }
            
            // Deserialize the cached value
            return unserialize($result);
            
        } catch (Exception $e) {
            $this->log("Error reading from database cache: " . $e->getMessage());
            return null;
        }
    }
    
    /**
     * Set result in database cache
     * 
     * @param string $cacheKey Cache key
     * @param mixed $result Result to cache
     * @param int $ttl Time to live in seconds
     */
    private function setDatabaseCache(string $cacheKey, mixed $result, int $ttl): void
    {
        try {
            $sql = "
                INSERT INTO query_cache (cache_key, cache_value, expires_at)
                VALUES (?, ?, DATE_ADD(NOW(), INTERVAL ? SECOND))
                ON DUPLICATE KEY UPDATE
                    cache_value = VALUES(cache_value),
                    expires_at = VALUES(expires_at),
                    updated_at = NOW()
            ";
            
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute([
                $cacheKey,
                serialize($result),
                $ttl
            ]);
            
        } catch (Exception $e) {
            $this->log("Error writing to database cache: " . $e->getMessage());
            // Don't throw exception - caching failure shouldn't break functionality
        }
    }
    
    /**
     * Initialize cache table if it doesn't exist
     */
    private function initializeCacheTable(): void
    {
        if (!$this->config['enable_db_cache']) {
            return;
        }
        
        try {
            $sql = "
                CREATE TABLE IF NOT EXISTS query_cache (
                    cache_key VARCHAR(255) PRIMARY KEY,
                    cache_value LONGTEXT NOT NULL,
                    expires_at TIMESTAMP NOT NULL,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    
                    INDEX idx_expires_at (expires_at)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
                COMMENT='Query result cache for performance optimization'
            ";
            
            $this->pdo->exec($sql);
            
        } catch (Exception $e) {
            $this->log("Warning: Could not create cache table: " . $e->getMessage());
            // Disable database caching if table creation fails
            $this->config['enable_db_cache'] = false;
        }
    }
    
    /**
     * Log debug messages
     * 
     * @param string $message Message to log
     */
    private function log(string $message): void
    {
        if ($this->config['debug']) {
            echo "[QueryCache] " . date('Y-m-d H:i:s') . " - $message\n";
        }
    }
}
?>