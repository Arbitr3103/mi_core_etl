<?php
/**
 * OzonDataCache Class - Advanced caching system for Ozon analytics data
 * 
 * Provides TTL-based caching with automatic cleanup and performance optimization
 * Supports multiple cache backends and intelligent cache invalidation
 * 
 * @version 1.0
 * @author Manhattan System
 */

class OzonDataCache {
    // Cache configuration
    const DEFAULT_TTL = 3600; // 1 hour
    const CLEANUP_PROBABILITY = 0.01; // 1% chance to run cleanup on each operation
    const MAX_CACHE_SIZE = 1000; // Maximum number of cached items
    
    private $pdo;
    private $memoryCache;
    private $cacheStats;
    
    /**
     * Constructor
     * 
     * @param PDO|null $pdo - Database connection
     */
    public function __construct(PDO $pdo = null) {
        $this->pdo = $pdo;
        $this->memoryCache = [];
        $this->cacheStats = [
            'hits' => 0,
            'misses' => 0,
            'sets' => 0,
            'deletes' => 0,
            'cleanups' => 0
        ];
        
        // Create cache table if it doesn't exist
        $this->initializeCacheTable();
        
        // Probabilistic cleanup
        if (mt_rand() / mt_getrandmax() < self::CLEANUP_PROBABILITY) {
            $this->cleanup();
        }
    }
    
    /**
     * Get cached data
     * 
     * @param string $key - Cache key
     * @param mixed $default - Default value if not found
     * @return mixed Cached data or default value
     */
    public function get($key, $default = null) {
        // Check memory cache first (fastest)
        if (isset($this->memoryCache[$key])) {
            $item = $this->memoryCache[$key];
            if ($this->isItemValid($item)) {
                $this->cacheStats['hits']++;
                return $item['data'];
            } else {
                unset($this->memoryCache[$key]);
            }
        }
        
        // Check database cache
        if ($this->pdo) {
            $item = $this->getFromDatabase($key);
            if ($item && $this->isItemValid($item)) {
                // Store in memory cache for faster access
                $this->memoryCache[$key] = $item;
                $this->cacheStats['hits']++;
                return $item['data'];
            }
        }
        
        $this->cacheStats['misses']++;
        return $default;
    }
    
    /**
     * Set cached data with TTL
     * 
     * @param string $key - Cache key
     * @param mixed $data - Data to cache
     * @param int $ttl - Time to live in seconds (optional)
     * @return bool Success status
     */
    public function set($key, $data, $ttl = self::DEFAULT_TTL) {
        $expiresAt = time() + $ttl;
        $item = [
            'data' => $data,
            'expires_at' => $expiresAt,
            'created_at' => time(),
            'size' => $this->calculateDataSize($data)
        ];
        
        // Store in memory cache
        $this->memoryCache[$key] = $item;
        
        // Limit memory cache size
        $this->limitMemoryCacheSize();
        
        // Store in database cache
        if ($this->pdo) {
            $this->setInDatabase($key, $item);
        }
        
        $this->cacheStats['sets']++;
        return true;
    }
    
    /**
     * Delete cached data
     * 
     * @param string $key - Cache key
     * @return bool Success status
     */
    public function delete($key) {
        // Remove from memory cache
        unset($this->memoryCache[$key]);
        
        // Remove from database cache
        if ($this->pdo) {
            try {
                $sql = "DELETE FROM ozon_cache WHERE cache_key = :key";
                $stmt = $this->pdo->prepare($sql);
                $stmt->execute(['key' => $key]);
            } catch (PDOException $e) {
                error_log('Cache delete error: ' . $e->getMessage());
                return false;
            }
        }
        
        $this->cacheStats['deletes']++;
        return true;
    }
    
    /**
     * Check if cache key exists and is valid
     * 
     * @param string $key - Cache key
     * @return bool True if exists and valid
     */
    public function has($key) {
        return $this->get($key) !== null;
    }
    
    /**
     * Clear all cache data
     * 
     * @return bool Success status
     */
    public function clear() {
        // Clear memory cache
        $this->memoryCache = [];
        
        // Clear database cache
        if ($this->pdo) {
            try {
                $sql = "DELETE FROM ozon_cache";
                $this->pdo->exec($sql);
            } catch (PDOException $e) {
                error_log('Cache clear error: ' . $e->getMessage());
                return false;
            }
        }
        
        return true;
    }
    
    /**
     * Get cache statistics
     * 
     * @return array Cache statistics
     */
    public function getStats() {
        $hitRate = $this->cacheStats['hits'] + $this->cacheStats['misses'] > 0 
            ? round(($this->cacheStats['hits'] / ($this->cacheStats['hits'] + $this->cacheStats['misses'])) * 100, 2)
            : 0;
            
        return [
            'hits' => $this->cacheStats['hits'],
            'misses' => $this->cacheStats['misses'],
            'sets' => $this->cacheStats['sets'],
            'deletes' => $this->cacheStats['deletes'],
            'cleanups' => $this->cacheStats['cleanups'],
            'hit_rate' => $hitRate,
            'memory_items' => count($this->memoryCache),
            'memory_size' => $this->getMemoryCacheSize()
        ];
    }
    
    /**
     * Get cached funnel data with intelligent key generation
     * 
     * @param string $dateFrom - Start date
     * @param string $dateTo - End date
     * @param array $filters - Additional filters
     * @return array|null Cached data or null
     */
    public function getFunnelData($dateFrom, $dateTo, $filters = []) {
        $key = $this->generateFunnelCacheKey($dateFrom, $dateTo, $filters);
        return $this->get($key);
    }
    
    /**
     * Set cached funnel data
     * 
     * @param string $dateFrom - Start date
     * @param string $dateTo - End date
     * @param array $filters - Additional filters
     * @param array $data - Data to cache
     * @param int $ttl - Time to live
     * @return bool Success status
     */
    public function setFunnelData($dateFrom, $dateTo, $filters, $data, $ttl = self::DEFAULT_TTL) {
        $key = $this->generateFunnelCacheKey($dateFrom, $dateTo, $filters);
        return $this->set($key, $data, $ttl);
    }
    
    /**
     * Get cached demographics data
     * 
     * @param string $dateFrom - Start date
     * @param string $dateTo - End date
     * @param array $filters - Additional filters
     * @return array|null Cached data or null
     */
    public function getDemographicsData($dateFrom, $dateTo, $filters = []) {
        $key = $this->generateDemographicsCacheKey($dateFrom, $dateTo, $filters);
        return $this->get($key);
    }
    
    /**
     * Set cached demographics data
     * 
     * @param string $dateFrom - Start date
     * @param string $dateTo - End date
     * @param array $filters - Additional filters
     * @param array $data - Data to cache
     * @param int $ttl - Time to live
     * @return bool Success status
     */
    public function setDemographicsData($dateFrom, $dateTo, $filters, $data, $ttl = self::DEFAULT_TTL) {
        $key = $this->generateDemographicsCacheKey($dateFrom, $dateTo, $filters);
        return $this->set($key, $data, $ttl);
    }
    
    /**
     * Invalidate cache by pattern
     * 
     * @param string $pattern - Cache key pattern (supports wildcards)
     * @return int Number of invalidated items
     */
    public function invalidateByPattern($pattern) {
        $count = 0;
        
        // Convert pattern to regex
        $regex = '/^' . str_replace(['*', '?'], ['.*', '.'], preg_quote($pattern, '/')) . '$/';
        
        // Invalidate memory cache
        foreach (array_keys($this->memoryCache) as $key) {
            if (preg_match($regex, $key)) {
                unset($this->memoryCache[$key]);
                $count++;
            }
        }
        
        // Invalidate database cache
        if ($this->pdo) {
            try {
                $sql = "DELETE FROM ozon_cache WHERE cache_key REGEXP :pattern";
                $stmt = $this->pdo->prepare($sql);
                $stmt->execute(['pattern' => $pattern]);
                $count += $stmt->rowCount();
            } catch (PDOException $e) {
                error_log('Cache invalidation error: ' . $e->getMessage());
            }
        }
        
        return $count;
    }
    
    /**
     * Warm up cache with commonly requested data
     * 
     * @param array $warmupData - Data to pre-cache
     * @return bool Success status
     */
    public function warmUp($warmupData) {
        foreach ($warmupData as $item) {
            $this->set($item['key'], $item['data'], $item['ttl'] ?? self::DEFAULT_TTL);
        }
        
        return true;
    }
    
    /**
     * Initialize cache table in database
     */
    private function initializeCacheTable() {
        if (!$this->pdo) {
            return;
        }
        
        try {
            $sql = "CREATE TABLE IF NOT EXISTS ozon_cache (
                id INT PRIMARY KEY AUTO_INCREMENT,
                cache_key VARCHAR(255) NOT NULL,
                cache_data LONGTEXT NOT NULL,
                expires_at INT NOT NULL,
                created_at INT NOT NULL,
                data_size INT DEFAULT 0,
                access_count INT DEFAULT 0,
                last_accessed_at INT DEFAULT 0,
                
                UNIQUE KEY unique_key (cache_key),
                INDEX idx_expires (expires_at),
                INDEX idx_created (created_at),
                INDEX idx_access (last_accessed_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            COMMENT='Cache storage for Ozon analytics data'";
            
            $this->pdo->exec($sql);
        } catch (PDOException $e) {
            error_log('Cache table initialization error: ' . $e->getMessage());
        }
    }
    
    /**
     * Get item from database cache
     * 
     * @param string $key - Cache key
     * @return array|null Cache item or null
     */
    private function getFromDatabase($key) {
        try {
            $sql = "SELECT cache_data, expires_at, created_at, data_size, access_count 
                    FROM ozon_cache 
                    WHERE cache_key = :key AND expires_at > :now";
            
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute([
                'key' => $key,
                'now' => time()
            ]);
            
            $row = $stmt->fetch();
            if ($row) {
                // Update access statistics
                $this->updateAccessStats($key);
                
                return [
                    'data' => json_decode($row['cache_data'], true),
                    'expires_at' => $row['expires_at'],
                    'created_at' => $row['created_at'],
                    'size' => $row['data_size']
                ];
            }
        } catch (PDOException $e) {
            error_log('Cache get error: ' . $e->getMessage());
        }
        
        return null;
    }
    
    /**
     * Set item in database cache
     * 
     * @param string $key - Cache key
     * @param array $item - Cache item
     * @return bool Success status
     */
    private function setInDatabase($key, $item) {
        try {
            $sql = "INSERT INTO ozon_cache 
                    (cache_key, cache_data, expires_at, created_at, data_size) 
                    VALUES (:key, :data, :expires, :created, :size)
                    ON DUPLICATE KEY UPDATE 
                    cache_data = VALUES(cache_data),
                    expires_at = VALUES(expires_at),
                    created_at = VALUES(created_at),
                    data_size = VALUES(data_size)";
            
            $stmt = $this->pdo->prepare($sql);
            return $stmt->execute([
                'key' => $key,
                'data' => json_encode($item['data']),
                'expires' => $item['expires_at'],
                'created' => $item['created_at'],
                'size' => $item['size']
            ]);
        } catch (PDOException $e) {
            error_log('Cache set error: ' . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Update access statistics for cache item
     * 
     * @param string $key - Cache key
     */
    private function updateAccessStats($key) {
        try {
            $sql = "UPDATE ozon_cache 
                    SET access_count = access_count + 1, 
                        last_accessed_at = :now 
                    WHERE cache_key = :key";
            
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute([
                'key' => $key,
                'now' => time()
            ]);
        } catch (PDOException $e) {
            error_log('Cache access stats update error: ' . $e->getMessage());
        }
    }
    
    /**
     * Check if cache item is valid (not expired)
     * 
     * @param array $item - Cache item
     * @return bool True if valid
     */
    private function isItemValid($item) {
        return isset($item['expires_at']) && $item['expires_at'] > time();
    }
    
    /**
     * Calculate data size for cache item
     * 
     * @param mixed $data - Data to measure
     * @return int Size in bytes
     */
    private function calculateDataSize($data) {
        return strlen(json_encode($data));
    }
    
    /**
     * Limit memory cache size to prevent memory issues
     */
    private function limitMemoryCacheSize() {
        if (count($this->memoryCache) <= self::MAX_CACHE_SIZE) {
            return;
        }
        
        // Sort by access time and remove oldest items
        uasort($this->memoryCache, function($a, $b) {
            return ($a['created_at'] ?? 0) - ($b['created_at'] ?? 0);
        });
        
        $itemsToRemove = count($this->memoryCache) - self::MAX_CACHE_SIZE;
        $keys = array_keys($this->memoryCache);
        
        for ($i = 0; $i < $itemsToRemove; $i++) {
            unset($this->memoryCache[$keys[$i]]);
        }
    }
    
    /**
     * Get memory cache size in bytes
     * 
     * @return int Size in bytes
     */
    private function getMemoryCacheSize() {
        $size = 0;
        foreach ($this->memoryCache as $item) {
            $size += $item['size'] ?? 0;
        }
        return $size;
    }
    
    /**
     * Generate cache key for funnel data
     * 
     * @param string $dateFrom - Start date
     * @param string $dateTo - End date
     * @param array $filters - Filters
     * @return string Cache key
     */
    private function generateFunnelCacheKey($dateFrom, $dateTo, $filters) {
        $keyData = [
            'type' => 'funnel',
            'date_from' => $dateFrom,
            'date_to' => $dateTo,
            'product_id' => $filters['product_id'] ?? null,
            'campaign_id' => $filters['campaign_id'] ?? null
        ];
        
        return 'ozon_funnel_' . md5(json_encode($keyData));
    }
    
    /**
     * Generate cache key for demographics data
     * 
     * @param string $dateFrom - Start date
     * @param string $dateTo - End date
     * @param array $filters - Filters
     * @return string Cache key
     */
    private function generateDemographicsCacheKey($dateFrom, $dateTo, $filters) {
        $keyData = [
            'type' => 'demographics',
            'date_from' => $dateFrom,
            'date_to' => $dateTo,
            'region' => $filters['region'] ?? null,
            'age_group' => $filters['age_group'] ?? null,
            'gender' => $filters['gender'] ?? null
        ];
        
        return 'ozon_demographics_' . md5(json_encode($keyData));
    }
    
    /**
     * Clean up expired cache items
     * 
     * @return int Number of cleaned items
     */
    public function cleanup() {
        $cleanedCount = 0;
        
        // Clean memory cache
        foreach ($this->memoryCache as $key => $item) {
            if (!$this->isItemValid($item)) {
                unset($this->memoryCache[$key]);
                $cleanedCount++;
            }
        }
        
        // Clean database cache
        if ($this->pdo) {
            try {
                $sql = "DELETE FROM ozon_cache WHERE expires_at <= :now";
                $stmt = $this->pdo->prepare($sql);
                $stmt->execute(['now' => time()]);
                $cleanedCount += $stmt->rowCount();
            } catch (PDOException $e) {
                error_log('Cache cleanup error: ' . $e->getMessage());
            }
        }
        
        $this->cacheStats['cleanups']++;
        return $cleanedCount;
    }
}