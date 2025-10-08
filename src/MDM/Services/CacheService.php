<?php

namespace MDM\Services;

/**
 * Cache Service for MDM Reports
 * Handles caching and invalidation of report data
 */
class CacheService {
    private $cacheDir;
    private $defaultTtl;
    private $enabled;
    
    public function __construct($cacheDir = null, $defaultTtl = 3600, $enabled = true) {
        $this->cacheDir = $cacheDir ?: sys_get_temp_dir() . '/mdm_cache';
        $this->defaultTtl = $defaultTtl;
        $this->enabled = $enabled;
        
        // Create cache directory if it doesn't exist
        if ($this->enabled && !is_dir($this->cacheDir)) {
            mkdir($this->cacheDir, 0755, true);
        }
    }
    
    /**
     * Get cached data
     */
    public function get($key) {
        if (!$this->enabled) {
            return null;
        }
        
        $filename = $this->getCacheFilename($key);
        
        if (!file_exists($filename)) {
            return null;
        }
        
        $data = file_get_contents($filename);
        $cached = json_decode($data, true);
        
        if (!$cached || !isset($cached['expires_at'], $cached['data'])) {
            return null;
        }
        
        // Check if expired
        if (time() > $cached['expires_at']) {
            $this->delete($key);
            return null;
        }
        
        return $cached['data'];
    }
    
    /**
     * Set cached data
     */
    public function set($key, $data, $ttl = null) {
        if (!$this->enabled) {
            return false;
        }
        
        $ttl = $ttl ?: $this->defaultTtl;
        $filename = $this->getCacheFilename($key);
        
        $cached = [
            'data' => $data,
            'created_at' => time(),
            'expires_at' => time() + $ttl,
            'key' => $key
        ];
        
        return file_put_contents($filename, json_encode($cached)) !== false;
    }
    
    /**
     * Delete cached data
     */
    public function delete($key) {
        if (!$this->enabled) {
            return false;
        }
        
        $filename = $this->getCacheFilename($key);
        
        if (file_exists($filename)) {
            return unlink($filename);
        }
        
        return true;
    }
    
    /**
     * Delete cached data by pattern
     */
    public function deletePattern($pattern) {
        if (!$this->enabled) {
            return false;
        }
        
        $files = glob($this->cacheDir . '/' . str_replace('*', '*', $pattern) . '.cache');
        $deleted = 0;
        
        foreach ($files as $file) {
            if (unlink($file)) {
                $deleted++;
            }
        }
        
        return $deleted;
    }
    
    /**
     * Clear all cache
     */
    public function clear() {
        if (!$this->enabled) {
            return false;
        }
        
        $files = glob($this->cacheDir . '/*.cache');
        $deleted = 0;
        
        foreach ($files as $file) {
            if (unlink($file)) {
                $deleted++;
            }
        }
        
        return $deleted;
    }
    
    /**
     * Get or set cached data with callback
     */
    public function remember($key, $callback, $ttl = null) {
        $data = $this->get($key);
        
        if ($data !== null) {
            return $data;
        }
        
        $data = $callback();
        $this->set($key, $data, $ttl);
        
        return $data;
    }
    
    /**
     * Get cache statistics
     */
    public function getStats() {
        if (!$this->enabled) {
            return [
                'enabled' => false,
                'total_files' => 0,
                'total_size' => 0
            ];
        }
        
        $files = glob($this->cacheDir . '/*.cache');
        $totalSize = 0;
        $expiredFiles = 0;
        $validFiles = 0;
        
        foreach ($files as $file) {
            $size = filesize($file);
            $totalSize += $size;
            
            // Check if expired
            $data = file_get_contents($file);
            $cached = json_decode($data, true);
            
            if ($cached && isset($cached['expires_at'])) {
                if (time() > $cached['expires_at']) {
                    $expiredFiles++;
                } else {
                    $validFiles++;
                }
            }
        }
        
        return [
            'enabled' => true,
            'cache_dir' => $this->cacheDir,
            'total_files' => count($files),
            'valid_files' => $validFiles,
            'expired_files' => $expiredFiles,
            'total_size' => $totalSize,
            'total_size_mb' => round($totalSize / 1024 / 1024, 2)
        ];
    }
    
    /**
     * Clean up expired cache files
     */
    public function cleanup() {
        if (!$this->enabled) {
            return 0;
        }
        
        $files = glob($this->cacheDir . '/*.cache');
        $cleaned = 0;
        
        foreach ($files as $file) {
            $data = file_get_contents($file);
            $cached = json_decode($data, true);
            
            if (!$cached || !isset($cached['expires_at'])) {
                // Invalid cache file, delete it
                if (unlink($file)) {
                    $cleaned++;
                }
                continue;
            }
            
            // Check if expired
            if (time() > $cached['expires_at']) {
                if (unlink($file)) {
                    $cleaned++;
                }
            }
        }
        
        return $cleaned;
    }
    
    /**
     * Get cache filename for key
     */
    private function getCacheFilename($key) {
        $safeKey = preg_replace('/[^a-zA-Z0-9_-]/', '_', $key);
        return $this->cacheDir . '/' . $safeKey . '.cache';
    }
    
    /**
     * Check if cache is enabled
     */
    public function isEnabled() {
        return $this->enabled;
    }
    
    /**
     * Enable or disable cache
     */
    public function setEnabled($enabled) {
        $this->enabled = $enabled;
    }
}

/**
 * Redis Cache Service (alternative implementation)
 */
class RedisCacheService extends CacheService {
    private $redis;
    private $prefix;
    
    public function __construct($redisConfig = [], $prefix = 'mdm:', $defaultTtl = 3600) {
        $this->prefix = $prefix;
        $this->defaultTtl = $defaultTtl;
        
        try {
            $this->redis = new \Redis();
            $host = $redisConfig['host'] ?? 'localhost';
            $port = $redisConfig['port'] ?? 6379;
            $password = $redisConfig['password'] ?? null;
            $database = $redisConfig['database'] ?? 0;
            
            $this->redis->connect($host, $port);
            
            if ($password) {
                $this->redis->auth($password);
            }
            
            $this->redis->select($database);
            $this->enabled = true;
            
        } catch (Exception $e) {
            error_log("Redis connection failed: " . $e->getMessage());
            $this->enabled = false;
        }
    }
    
    public function get($key) {
        if (!$this->enabled) {
            return null;
        }
        
        try {
            $data = $this->redis->get($this->prefix . $key);
            return $data ? json_decode($data, true) : null;
        } catch (Exception $e) {
            error_log("Redis get error: " . $e->getMessage());
            return null;
        }
    }
    
    public function set($key, $data, $ttl = null) {
        if (!$this->enabled) {
            return false;
        }
        
        try {
            $ttl = $ttl ?: $this->defaultTtl;
            return $this->redis->setex(
                $this->prefix . $key,
                $ttl,
                json_encode($data)
            );
        } catch (Exception $e) {
            error_log("Redis set error: " . $e->getMessage());
            return false;
        }
    }
    
    public function delete($key) {
        if (!$this->enabled) {
            return false;
        }
        
        try {
            return $this->redis->del($this->prefix . $key) > 0;
        } catch (Exception $e) {
            error_log("Redis delete error: " . $e->getMessage());
            return false;
        }
    }
    
    public function deletePattern($pattern) {
        if (!$this->enabled) {
            return false;
        }
        
        try {
            $keys = $this->redis->keys($this->prefix . $pattern);
            if (empty($keys)) {
                return 0;
            }
            
            return $this->redis->del($keys);
        } catch (Exception $e) {
            error_log("Redis delete pattern error: " . $e->getMessage());
            return false;
        }
    }
    
    public function clear() {
        if (!$this->enabled) {
            return false;
        }
        
        try {
            $keys = $this->redis->keys($this->prefix . '*');
            if (empty($keys)) {
                return 0;
            }
            
            return $this->redis->del($keys);
        } catch (Exception $e) {
            error_log("Redis clear error: " . $e->getMessage());
            return false;
        }
    }
    
    public function getStats() {
        if (!$this->enabled) {
            return ['enabled' => false];
        }
        
        try {
            $info = $this->redis->info();
            $keys = $this->redis->keys($this->prefix . '*');
            
            return [
                'enabled' => true,
                'type' => 'redis',
                'total_keys' => count($keys),
                'memory_usage' => $info['used_memory_human'] ?? 'unknown',
                'connected_clients' => $info['connected_clients'] ?? 'unknown'
            ];
        } catch (Exception $e) {
            error_log("Redis stats error: " . $e->getMessage());
            return ['enabled' => false, 'error' => $e->getMessage()];
        }
    }
    
    public function cleanup() {
        // Redis handles TTL automatically, no manual cleanup needed
        return 0;
    }
}
?>