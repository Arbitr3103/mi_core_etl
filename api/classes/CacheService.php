<?php
/**
 * Cache Service
 * 
 * Provides caching functionality for the detailed inventory API.
 * Supports both file-based caching (fallback) and Redis caching.
 * 
 * Requirements: 7.1, 7.2, 7.3
 * Task: 1.3 Add caching layer for performance
 */

class CacheService {
    
    private $cacheDir;
    private $defaultTtl;
    private $redis;
    private $useRedis;
    
    /**
     * Cache TTL constants (in seconds)
     */
    const TTL_INVENTORY_DATA = 300;     // 5 minutes
    const TTL_WAREHOUSE_LIST = 3600;    // 1 hour
    const TTL_SEARCH_RESULTS = 1800;    // 30 minutes
    const TTL_SUMMARY_STATS = 600;      // 10 minutes
    
    /**
     * Constructor
     * 
     * @param string $cacheDir Directory for file-based cache
     * @param int $defaultTtl Default TTL in seconds
     */
    public function __construct($cacheDir = null, $defaultTtl = 300) {
        $this->cacheDir = $cacheDir ?: __DIR__ . '/../../cache/inventory';
        $this->defaultTtl = $defaultTtl;
        $this->useRedis = false;
        
        // Try to initialize Redis if available
        $this->initializeRedis();
        
        // Ensure cache directory exists for file-based caching
        if (!$this->useRedis) {
            $this->ensureCacheDirectory();
        }
    }
    
    /**
     * Initialize Redis connection if available
     */
    private function initializeRedis() {
        if (!class_exists('Redis')) {
            return;
        }
        
        try {
            $this->redis = new Redis();
            $connected = $this->redis->connect('127.0.0.1', 6379, 2); // 2 second timeout
            
            if ($connected) {
                // Test the connection
                $this->redis->ping();
                $this->useRedis = true;
                error_log("CacheService: Using Redis for caching");
            }
        } catch (Exception $e) {
            error_log("CacheService: Redis not available, falling back to file cache: " . $e->getMessage());
            $this->useRedis = false;
            $this->redis = null;
        }
    }
    
    /**
     * Ensure cache directory exists
     */
    private function ensureCacheDirectory() {
        if (!is_dir($this->cacheDir)) {
            if (!mkdir($this->cacheDir, 0755, true)) {
                throw new Exception("Failed to create cache directory: " . $this->cacheDir);
            }
        }
        
        if (!is_writable($this->cacheDir)) {
            throw new Exception("Cache directory is not writable: " . $this->cacheDir);
        }
    }
    
    /**
     * Get cached data
     * 
     * @param string $key Cache key
     * @return mixed|null Cached data or null if not found/expired
     */
    public function get($key) {
        if ($this->useRedis) {
            return $this->getFromRedis($key);
        } else {
            return $this->getFromFile($key);
        }
    }
    
    /**
     * Set cached data
     * 
     * @param string $key Cache key
     * @param mixed $data Data to cache
     * @param int|null $ttl Time to live in seconds (null for default)
     * @return bool Success status
     */
    public function set($key, $data, $ttl = null) {
        $ttl = $ttl ?: $this->defaultTtl;
        
        if ($this->useRedis) {
            return $this->setToRedis($key, $data, $ttl);
        } else {
            return $this->setToFile($key, $data, $ttl);
        }
    }
    
    /**
     * Delete cached data
     * 
     * @param string $key Cache key
     * @return bool Success status
     */
    public function delete($key) {
        if ($this->useRedis) {
            return $this->deleteFromRedis($key);
        } else {
            return $this->deleteFromFile($key);
        }
    }
    
    /**
     * Clear all cache
     * 
     * @return bool Success status
     */
    public function clear() {
        if ($this->useRedis) {
            return $this->clearRedis();
        } else {
            return $this->clearFiles();
        }
    }
    
    /**
     * Generate cache key for inventory data
     * 
     * @param array $filters Filter parameters
     * @return string Cache key
     */
    public function getInventoryKey($filters) {
        // Sort filters to ensure consistent key generation
        ksort($filters);
        $filterHash = md5(json_encode($filters));
        return "inventory:detailed:v1:{$filterHash}";
    }
    
    /**
     * Generate cache key for warehouse list
     * 
     * @return string Cache key
     */
    public function getWarehouseListKey() {
        return "warehouses:list:v1";
    }
    
    /**
     * Generate cache key for summary statistics
     * 
     * @return string Cache key
     */
    public function getSummaryStatsKey() {
        return "summary:stats:v1";
    }
    
    /**
     * Generate cache key for search results
     * 
     * @param string $searchTerm Search term
     * @param array $additionalFilters Additional filters
     * @return string Cache key
     */
    public function getSearchKey($searchTerm, $additionalFilters = []) {
        $searchHash = md5(strtolower(trim($searchTerm)));
        $filtersHash = md5(json_encode($additionalFilters));
        return "search:results:v1:{$searchHash}:{$filtersHash}";
    }
    
    // Redis-specific methods
    
    /**
     * Get data from Redis
     * 
     * @param string $key Cache key
     * @return mixed|null Cached data or null if not found/expired
     */
    private function getFromRedis($key) {
        try {
            $data = $this->redis->get($key);
            if ($data === false) {
                return null;
            }
            
            $decoded = json_decode($data, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                error_log("CacheService: JSON decode error for key {$key}: " . json_last_error_msg());
                return null;
            }
            
            return $decoded;
        } catch (Exception $e) {
            error_log("CacheService: Redis get error for key {$key}: " . $e->getMessage());
            return null;
        }
    }
    
    /**
     * Set data to Redis
     * 
     * @param string $key Cache key
     * @param mixed $data Data to cache
     * @param int $ttl Time to live in seconds
     * @return bool Success status
     */
    private function setToRedis($key, $data, $ttl) {
        try {
            $encoded = json_encode($data);
            if (json_last_error() !== JSON_ERROR_NONE) {
                error_log("CacheService: JSON encode error for key {$key}: " . json_last_error_msg());
                return false;
            }
            
            return $this->redis->setex($key, $ttl, $encoded);
        } catch (Exception $e) {
            error_log("CacheService: Redis set error for key {$key}: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Delete data from Redis
     * 
     * @param string $key Cache key
     * @return bool Success status
     */
    private function deleteFromRedis($key) {
        try {
            return $this->redis->del($key) > 0;
        } catch (Exception $e) {
            error_log("CacheService: Redis delete error for key {$key}: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Clear all Redis cache
     * 
     * @return bool Success status
     */
    private function clearRedis() {
        try {
            return $this->redis->flushDB();
        } catch (Exception $e) {
            error_log("CacheService: Redis clear error: " . $e->getMessage());
            return false;
        }
    }
    
    // File-based caching methods
    
    /**
     * Get data from file cache
     * 
     * @param string $key Cache key
     * @return mixed|null Cached data or null if not found/expired
     */
    private function getFromFile($key) {
        $filename = $this->getCacheFilename($key);
        
        if (!file_exists($filename)) {
            return null;
        }
        
        $content = file_get_contents($filename);
        if ($content === false) {
            return null;
        }
        
        $cacheData = json_decode($content, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            // Invalid cache file, delete it
            unlink($filename);
            return null;
        }
        
        // Check if expired
        if (isset($cacheData['expires_at']) && time() > $cacheData['expires_at']) {
            unlink($filename);
            return null;
        }
        
        return $cacheData['data'] ?? null;
    }
    
    /**
     * Set data to file cache
     * 
     * @param string $key Cache key
     * @param mixed $data Data to cache
     * @param int $ttl Time to live in seconds
     * @return bool Success status
     */
    private function setToFile($key, $data, $ttl) {
        $filename = $this->getCacheFilename($key);
        
        $cacheData = [
            'data' => $data,
            'created_at' => time(),
            'expires_at' => time() + $ttl,
            'ttl' => $ttl
        ];
        
        $encoded = json_encode($cacheData);
        if (json_last_error() !== JSON_ERROR_NONE) {
            error_log("CacheService: JSON encode error for key {$key}: " . json_last_error_msg());
            return false;
        }
        
        // Write to temporary file first, then rename for atomic operation
        $tempFile = $filename . '.tmp';
        if (file_put_contents($tempFile, $encoded, LOCK_EX) === false) {
            return false;
        }
        
        return rename($tempFile, $filename);
    }
    
    /**
     * Delete data from file cache
     * 
     * @param string $key Cache key
     * @return bool Success status
     */
    private function deleteFromFile($key) {
        $filename = $this->getCacheFilename($key);
        
        if (file_exists($filename)) {
            return unlink($filename);
        }
        
        return true; // File doesn't exist, consider it deleted
    }
    
    /**
     * Clear all file cache
     * 
     * @return bool Success status
     */
    private function clearFiles() {
        $files = glob($this->cacheDir . '/*.cache');
        $success = true;
        
        foreach ($files as $file) {
            if (!unlink($file)) {
                $success = false;
                error_log("CacheService: Failed to delete cache file: {$file}");
            }
        }
        
        return $success;
    }
    
    /**
     * Get cache filename for a key
     * 
     * @param string $key Cache key
     * @return string Cache filename
     */
    private function getCacheFilename($key) {
        $safeKey = preg_replace('/[^a-zA-Z0-9_\-:]/', '_', $key);
        return $this->cacheDir . '/' . $safeKey . '.cache';
    }
    
    /**
     * Clean up expired cache files
     * 
     * @return int Number of files cleaned up
     */
    public function cleanupExpired() {
        if ($this->useRedis) {
            // Redis handles expiration automatically
            return 0;
        }
        
        $files = glob($this->cacheDir . '/*.cache');
        $cleaned = 0;
        
        foreach ($files as $file) {
            $content = file_get_contents($file);
            if ($content === false) {
                continue;
            }
            
            $cacheData = json_decode($content, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                // Invalid cache file, delete it
                unlink($file);
                $cleaned++;
                continue;
            }
            
            // Check if expired
            if (isset($cacheData['expires_at']) && time() > $cacheData['expires_at']) {
                unlink($file);
                $cleaned++;
            }
        }
        
        return $cleaned;
    }
    
    /**
     * Get cache statistics
     * 
     * @return array Cache statistics
     */
    public function getStats() {
        $stats = [
            'type' => $this->useRedis ? 'redis' : 'file',
            'cache_dir' => $this->cacheDir,
            'default_ttl' => $this->defaultTtl
        ];
        
        if ($this->useRedis) {
            try {
                $info = $this->redis->info();
                $stats['redis_info'] = [
                    'used_memory' => $info['used_memory_human'] ?? 'unknown',
                    'connected_clients' => $info['connected_clients'] ?? 'unknown',
                    'total_commands_processed' => $info['total_commands_processed'] ?? 'unknown'
                ];
            } catch (Exception $e) {
                $stats['redis_error'] = $e->getMessage();
            }
        } else {
            $files = glob($this->cacheDir . '/*.cache');
            $stats['file_count'] = count($files);
            $stats['total_size'] = 0;
            
            foreach ($files as $file) {
                $stats['total_size'] += filesize($file);
            }
            
            $stats['total_size_human'] = $this->formatBytes($stats['total_size']);
        }
        
        return $stats;
    }
    
    /**
     * Format bytes to human readable format
     * 
     * @param int $bytes Number of bytes
     * @return string Formatted string
     */
    private function formatBytes($bytes) {
        $units = ['B', 'KB', 'MB', 'GB'];
        $unitIndex = 0;
        
        while ($bytes >= 1024 && $unitIndex < count($units) - 1) {
            $bytes /= 1024;
            $unitIndex++;
        }
        
        return round($bytes, 2) . ' ' . $units[$unitIndex];
    }
}

?>