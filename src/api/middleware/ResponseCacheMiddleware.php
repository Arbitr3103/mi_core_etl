<?php
/**
 * Response Cache Middleware
 * 
 * Implements response caching for frequently requested data
 * 
 * @version 1.0
 * @author Manhattan System
 */

class ResponseCacheMiddleware {
    
    private $logger;
    private $cacheDir;
    private $defaultTtl;
    private $enabled;
    
    public function __construct() {
        $this->logger = Logger::getInstance();
        $this->cacheDir = sys_get_temp_dir() . '/api_cache/';
        $this->defaultTtl = 300; // 5 minutes default
        $this->enabled = true;
        
        $this->initializeCache();
    }
    
    /**
     * Get cached response if available
     * 
     * @param string $cacheKey - Cache key
     * @return array|null Cached response or null if not found
     */
    public function getCachedResponse(string $cacheKey): ?array {
        if (!$this->enabled) {
            return null;
        }
        
        try {
            $cacheFile = $this->getCacheFilePath($cacheKey);
            
            if (!file_exists($cacheFile)) {
                return null;
            }
            
            // Check if cache is expired
            if (filemtime($cacheFile) < time() - $this->getTtlForKey($cacheKey)) {
                unlink($cacheFile);
                return null;
            }
            
            $content = file_get_contents($cacheFile);
            $data = json_decode($content, true);
            
            if ($data === null) {
                unlink($cacheFile); // Remove corrupted cache
                return null;
            }
            
            $this->logger->debug('Cache hit', [
                'cache_key' => $cacheKey,
                'file_size' => filesize($cacheFile)
            ]);
            
            return $data;
            
        } catch (Exception $e) {
            $this->logger->error('Failed to get cached response', [
                'cache_key' => $cacheKey,
                'error' => $e->getMessage()
            ]);
            
            return null;
        }
    }
    
    /**
     * Cache response data
     * 
     * @param string $cacheKey - Cache key
     * @param array $response - Response data to cache
     * @param int|null $ttl - Time to live in seconds
     * @return bool True if cached successfully
     */
    public function cacheResponse(string $cacheKey, array $response, ?int $ttl = null): bool {
        if (!$this->enabled) {
            return false;
        }
        
        try {
            $cacheFile = $this->getCacheFilePath($cacheKey);
            $content = json_encode($response, JSON_UNESCAPED_UNICODE);
            
            if ($content === false) {
                throw new Exception('Failed to encode response for caching');
            }
            
            $result = file_put_contents($cacheFile, $content, LOCK_EX);
            
            if ($result === false) {
                throw new Exception('Failed to write cache file');
            }
            
            $this->logger->debug('Response cached', [
                'cache_key' => $cacheKey,
                'file_size' => strlen($content),
                'ttl' => $ttl ?? $this->defaultTtl
            ]);
            
            return true;
            
        } catch (Exception $e) {
            $this->logger->error('Failed to cache response', [
                'cache_key' => $cacheKey,
                'error' => $e->getMessage()
            ]);
            
            return false;
        }
    }
    
    /**
     * Generate cache key from request parameters
     * 
     * @param string $endpoint - API endpoint
     * @param array $params - Request parameters
     * @return string Cache key
     */
    public function generateCacheKey(string $endpoint, array $params): string {
        // Sort parameters for consistent cache keys
        ksort($params);
        
        // Remove sensitive parameters
        $filteredParams = array_diff_key($params, array_flip(['api_key', 'token']));
        
        // Create hash from endpoint and parameters
        $keyData = $endpoint . ':' . serialize($filteredParams);
        return 'api_' . hash('sha256', $keyData);
    }
    
    /**
     * Check if response should be cached
     * 
     * @param string $endpoint - API endpoint
     * @param array $params - Request parameters
     * @param array $response - Response data
     * @return bool True if should be cached
     */
    public function shouldCache(string $endpoint, array $params, array $response): bool {
        // Don't cache error responses
        if (!($response['success'] ?? false)) {
            return false;
        }
        
        // Don't cache empty responses
        if (empty($response['data'])) {
            return false;
        }
        
        // Don't cache real-time data requests
        if (isset($params['real_time']) && $params['real_time']) {
            return false;
        }
        
        // Cache based on endpoint
        $cacheableEndpoints = [
            'warehouse-stock' => true,
            'warehouse-stock-specific' => true,
            'stock-reports' => true,
            'stock-reports-details' => false // Don't cache detailed reports
        ];
        
        return $cacheableEndpoints[$endpoint] ?? false;
    }
    
    /**
     * Get TTL for specific cache key
     * 
     * @param string $cacheKey - Cache key
     * @return int TTL in seconds
     */
    private function getTtlForKey(string $cacheKey): int {
        // Different TTL for different types of data
        if (strpos($cacheKey, 'warehouse-stock') !== false) {
            return 600; // 10 minutes for stock data
        }
        
        if (strpos($cacheKey, 'stock-reports') !== false) {
            return 1800; // 30 minutes for reports
        }
        
        return $this->defaultTtl;
    }
    
    /**
     * Get cache file path
     * 
     * @param string $cacheKey - Cache key
     * @return string File path
     */
    private function getCacheFilePath(string $cacheKey): string {
        return $this->cacheDir . $cacheKey . '.json';
    }
    
    /**
     * Initialize cache directory
     */
    private function initializeCache(): void {
        try {
            if (!is_dir($this->cacheDir)) {
                mkdir($this->cacheDir, 0755, true);
            }
            
            // Create .htaccess to prevent direct access
            $htaccessFile = $this->cacheDir . '.htaccess';
            if (!file_exists($htaccessFile)) {
                file_put_contents($htaccessFile, "Deny from all\n");
            }
            
        } catch (Exception $e) {
            $this->logger->error('Failed to initialize cache directory', [
                'cache_dir' => $this->cacheDir,
                'error' => $e->getMessage()
            ]);
            
            $this->enabled = false;
        }
    }
    
    /**
     * Clear expired cache files
     * 
     * @return int Number of files cleared
     */
    public function clearExpiredCache(): int {
        try {
            $files = glob($this->cacheDir . '*.json');
            $cleared = 0;
            $now = time();
            
            foreach ($files as $file) {
                $cacheKey = basename($file, '.json');
                $ttl = $this->getTtlForKey($cacheKey);
                
                if (filemtime($file) < $now - $ttl) {
                    unlink($file);
                    $cleared++;
                }
            }
            
            if ($cleared > 0) {
                $this->logger->info('Cleared expired cache files', [
                    'files_cleared' => $cleared
                ]);
            }
            
            return $cleared;
            
        } catch (Exception $e) {
            $this->logger->error('Failed to clear expired cache', [
                'error' => $e->getMessage()
            ]);
            
            return 0;
        }
    }
    
    /**
     * Clear all cache files
     * 
     * @return int Number of files cleared
     */
    public function clearAllCache(): int {
        try {
            $files = glob($this->cacheDir . '*.json');
            $cleared = 0;
            
            foreach ($files as $file) {
                unlink($file);
                $cleared++;
            }
            
            $this->logger->info('Cleared all cache files', [
                'files_cleared' => $cleared
            ]);
            
            return $cleared;
            
        } catch (Exception $e) {
            $this->logger->error('Failed to clear all cache', [
                'error' => $e->getMessage()
            ]);
            
            return 0;
        }
    }
    
    /**
     * Get cache statistics
     * 
     * @return array Cache statistics
     */
    public function getCacheStats(): array {
        try {
            $files = glob($this->cacheDir . '*.json');
            $totalSize = 0;
            $totalFiles = count($files);
            $expiredFiles = 0;
            $now = time();
            
            foreach ($files as $file) {
                $totalSize += filesize($file);
                
                $cacheKey = basename($file, '.json');
                $ttl = $this->getTtlForKey($cacheKey);
                
                if (filemtime($file) < $now - $ttl) {
                    $expiredFiles++;
                }
            }
            
            return [
                'enabled' => $this->enabled,
                'cache_dir' => $this->cacheDir,
                'total_files' => $totalFiles,
                'expired_files' => $expiredFiles,
                'total_size_bytes' => $totalSize,
                'total_size_mb' => round($totalSize / 1024 / 1024, 2),
                'default_ttl' => $this->defaultTtl
            ];
            
        } catch (Exception $e) {
            $this->logger->error('Failed to get cache stats', [
                'error' => $e->getMessage()
            ]);
            
            return [
                'enabled' => $this->enabled,
                'error' => $e->getMessage()
            ];
        }
    }
    
    /**
     * Invalidate cache for specific pattern
     * 
     * @param string $pattern - Pattern to match cache keys
     * @return int Number of files invalidated
     */
    public function invalidateCache(string $pattern): int {
        try {
            $files = glob($this->cacheDir . '*.json');
            $invalidated = 0;
            
            foreach ($files as $file) {
                $cacheKey = basename($file, '.json');
                
                if (fnmatch($pattern, $cacheKey)) {
                    unlink($file);
                    $invalidated++;
                }
            }
            
            if ($invalidated > 0) {
                $this->logger->info('Invalidated cache files', [
                    'pattern' => $pattern,
                    'files_invalidated' => $invalidated
                ]);
            }
            
            return $invalidated;
            
        } catch (Exception $e) {
            $this->logger->error('Failed to invalidate cache', [
                'pattern' => $pattern,
                'error' => $e->getMessage()
            ]);
            
            return 0;
        }
    }
    
    /**
     * Enable or disable caching
     * 
     * @param bool $enabled - Whether to enable caching
     */
    public function setEnabled(bool $enabled): void {
        $this->enabled = $enabled;
        
        $this->logger->info('Cache status changed', [
            'enabled' => $enabled
        ]);
    }
    
    /**
     * Set default TTL
     * 
     * @param int $ttl - TTL in seconds
     */
    public function setDefaultTtl(int $ttl): void {
        $this->defaultTtl = max(60, $ttl); // Minimum 1 minute
        
        $this->logger->info('Default TTL changed', [
            'ttl' => $this->defaultTtl
        ]);
    }
}