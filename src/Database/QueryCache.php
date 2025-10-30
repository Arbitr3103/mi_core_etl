<?php
/**
 * Query Cache Manager
 * 
 * Implements intelligent caching for database queries to improve performance.
 * Supports TTL-based expiration, cache invalidation, and memory-efficient storage.
 */

namespace Database;

class QueryCache
{
    private string $cacheDir;
    private int $defaultTTL;
    private bool $enabled;
    private array $memoryCache;
    private int $maxMemoryCacheSize;
    
    /**
     * Constructor
     * 
     * @param string $cacheDir Directory for cache files
     * @param int $defaultTTL Default TTL in seconds (default: 300 = 5 minutes)
     * @param bool $enabled Enable/disable caching
     */
    public function __construct(
        string $cacheDir = '/tmp/query_cache',
        int $defaultTTL = 300,
        bool $enabled = true
    ) {
        $this->cacheDir = $cacheDir;
        $this->defaultTTL = $defaultTTL;
        $this->enabled = $enabled;
        $this->memoryCache = [];
        $this->maxMemoryCacheSize = 100; // Max items in memory cache
        
        // Create cache directory if it doesn't exist
        if ($this->enabled && !is_dir($this->cacheDir)) {
            mkdir($this->cacheDir, 0755, true);
        }
    }
    
    /**
     * Get cached query result
     * 
     * @param string $key Cache key
     * @return mixed|null Cached data or null if not found/expired
     */
    public function get(string $key)
    {
        if (!$this->enabled) {
            return null;
        }
        
        // Check memory cache first
        if (isset($this->memoryCache[$key])) {
            $cached = $this->memoryCache[$key];
            if ($cached['expires_at'] > time()) {
                return $cached['data'];
            } else {
                unset($this->memoryCache[$key]);
            }
        }
        
        // Check file cache
        $cacheFile = $this->getCacheFilePath($key);
        if (!file_exists($cacheFile)) {
            return null;
        }
        
        $cached = unserialize(file_get_contents($cacheFile));
        
        // Check if expired
        if ($cached['expires_at'] < time()) {
            unlink($cacheFile);
            return null;
        }
        
        // Store in memory cache for faster subsequent access
        $this->addToMemoryCache($key, $cached['data'], $cached['expires_at']);
        
        return $cached['data'];
    }
    
    /**
     * Set cache value
     * 
     * @param string $key Cache key
     * @param mixed $data Data to cache
     * @param int|null $ttl TTL in seconds (null = use default)
     * @return bool Success status
     */
    public function set(string $key, $data, ?int $ttl = null): bool
    {
        if (!$this->enabled) {
            return false;
        }
        
        $ttl = $ttl ?? $this->defaultTTL;
        $expiresAt = time() + $ttl;
        
        $cached = [
            'data' => $data,
            'expires_at' => $expiresAt,
            'created_at' => time()
        ];
        
        // Store in memory cache
        $this->addToMemoryCache($key, $data, $expiresAt);
        
        // Store in file cache
        $cacheFile = $this->getCacheFilePath($key);
        return file_put_contents($cacheFile, serialize($cached)) !== false;
    }
    
    /**
     * Delete cache entry
     * 
     * @param string $key Cache key
     * @return bool Success status
     */
    public function delete(string $key): bool
    {
        if (!$this->enabled) {
            return false;
        }
        
        // Remove from memory cache
        unset($this->memoryCache[$key]);
        
        // Remove from file cache
        $cacheFile = $this->getCacheFilePath($key);
        if (file_exists($cacheFile)) {
            return unlink($cacheFile);
        }
        
        return true;
    }
    
    /**
     * Clear all cache
     * 
     * @return bool Success status
     */
    public function clear(): bool
    {
        if (!$this->enabled) {
            return false;
        }
        
        // Clear memory cache
        $this->memoryCache = [];
        
        // Clear file cache
        $files = glob($this->cacheDir . '/*.cache');
        foreach ($files as $file) {
            unlink($file);
        }
        
        return true;
    }
    
    /**
     * Clear expired cache entries
     * 
     * @return int Number of entries cleared
     */
    public function clearExpired(): int
    {
        if (!$this->enabled) {
            return 0;
        }
        
        $cleared = 0;
        $files = glob($this->cacheDir . '/*.cache');
        
        foreach ($files as $file) {
            $cached = unserialize(file_get_contents($file));
            if ($cached['expires_at'] < time()) {
                unlink($file);
                $cleared++;
            }
        }
        
        return $cleared;
    }
    
    /**
     * Invalidate cache by pattern
     * 
     * @param string $pattern Pattern to match (e.g., 'warehouse_*')
     * @return int Number of entries invalidated
     */
    public function invalidatePattern(string $pattern): int
    {
        if (!$this->enabled) {
            return 0;
        }
        
        $invalidated = 0;
        
        // Clear from memory cache
        foreach (array_keys($this->memoryCache) as $key) {
            if (fnmatch($pattern, $key)) {
                unset($this->memoryCache[$key]);
                $invalidated++;
            }
        }
        
        // Clear from file cache
        $files = glob($this->cacheDir . '/*.cache');
        foreach ($files as $file) {
            $key = basename($file, '.cache');
            if (fnmatch($pattern, $key)) {
                unlink($file);
                $invalidated++;
            }
        }
        
        return $invalidated;
    }
    
    /**
     * Get cache statistics
     * 
     * @return array Cache statistics
     */
    public function getStats(): array
    {
        if (!$this->enabled) {
            return [
                'enabled' => false,
                'memory_cache_size' => 0,
                'file_cache_size' => 0,
                'total_size_bytes' => 0
            ];
        }
        
        $files = glob($this->cacheDir . '/*.cache');
        $totalSize = 0;
        $validEntries = 0;
        $expiredEntries = 0;
        
        foreach ($files as $file) {
            $totalSize += filesize($file);
            $cached = unserialize(file_get_contents($file));
            if ($cached['expires_at'] >= time()) {
                $validEntries++;
            } else {
                $expiredEntries++;
            }
        }
        
        return [
            'enabled' => true,
            'memory_cache_size' => count($this->memoryCache),
            'file_cache_size' => count($files),
            'valid_entries' => $validEntries,
            'expired_entries' => $expiredEntries,
            'total_size_bytes' => $totalSize,
            'total_size_mb' => round($totalSize / 1024 / 1024, 2),
            'cache_directory' => $this->cacheDir
        ];
    }
    
    /**
     * Remember query result (get or execute and cache)
     * 
     * @param string $key Cache key
     * @param callable $callback Function to execute if cache miss
     * @param int|null $ttl TTL in seconds
     * @return mixed Query result
     */
    public function remember(string $key, callable $callback, ?int $ttl = null)
    {
        // Try to get from cache
        $cached = $this->get($key);
        if ($cached !== null) {
            return $cached;
        }
        
        // Execute callback and cache result
        $result = $callback();
        $this->set($key, $result, $ttl);
        
        return $result;
    }
    
    /**
     * Generate cache key from query and parameters
     * 
     * @param string $query SQL query
     * @param array $params Query parameters
     * @return string Cache key
     */
    public static function generateKey(string $query, array $params = []): string
    {
        $normalized = preg_replace('/\s+/', ' ', trim($query));
        $paramsStr = json_encode($params);
        return md5($normalized . $paramsStr);
    }
    
    /**
     * Get cache file path for key
     * 
     * @param string $key Cache key
     * @return string File path
     */
    private function getCacheFilePath(string $key): string
    {
        return $this->cacheDir . '/' . $key . '.cache';
    }
    
    /**
     * Add entry to memory cache
     * 
     * @param string $key Cache key
     * @param mixed $data Data to cache
     * @param int $expiresAt Expiration timestamp
     */
    private function addToMemoryCache(string $key, $data, int $expiresAt): void
    {
        // Limit memory cache size
        if (count($this->memoryCache) >= $this->maxMemoryCacheSize) {
            // Remove oldest entry
            $oldestKey = array_key_first($this->memoryCache);
            unset($this->memoryCache[$oldestKey]);
        }
        
        $this->memoryCache[$key] = [
            'data' => $data,
            'expires_at' => $expiresAt
        ];
    }
}
