<?php
/**
 * Cache Service for mi_core_etl
 * Provides flexible caching with multiple drivers (file, Redis, memory)
 */

require_once __DIR__ . '/../utils/Logger.php';

class CacheService {
    private static $instance = null;
    private $config = [];
    private $driver = null;
    private $logger;
    private $prefix = '';
    private $defaultTtl = 3600;
    
    private function __construct() {
        $this->logger = Logger::getInstance();
        $this->loadConfig();
        $this->initializeDriver();
    }
    
    /**
     * Get singleton instance
     */
    public static function getInstance(): CacheService {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    /**
     * Load configuration from environment and config files
     */
    private function loadConfig(): void {
        $this->loadEnvFile();
        
        // Load from config file if exists
        $configFile = __DIR__ . '/../../config/cache.php';
        if (file_exists($configFile)) {
            $this->config = require $configFile;
        } else {
            // Default configuration
            $this->config = [
                'default' => $_ENV['CACHE_DRIVER'] ?? 'file',
                'stores' => [
                    'file' => [
                        'driver' => 'file',
                        'path' => $_ENV['CACHE_PATH'] ?? __DIR__ . '/../../storage/cache',
                    ],
                    'memory' => [
                        'driver' => 'array',
                    ]
                ]
            ];
        }
        
        $this->prefix = $_ENV['CACHE_PREFIX'] ?? 'mi_core_etl';
        $this->defaultTtl = (int)($_ENV['CACHE_TTL'] ?? 3600);
    }
    
    /**
     * Load environment variables from .env file
     */
    private function loadEnvFile(): void {
        $envFile = __DIR__ . '/../../.env';
        
        if (file_exists($envFile)) {
            $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            foreach ($lines as $line) {
                if (strpos($line, '=') !== false && strpos($line, '#') !== 0) {
                    list($key, $value) = explode('=', $line, 2);
                    $key = trim($key);
                    $value = trim($value, '"\'');
                    
                    if (!array_key_exists($key, $_ENV)) {
                        $_ENV[$key] = $value;
                        putenv("$key=$value");
                    }
                }
            }
        }
    }
    
    /**
     * Initialize cache driver
     */
    private function initializeDriver(): void {
        $driverName = $this->config['default'] ?? 'file';
        $driverConfig = $this->config['stores'][$driverName] ?? [];
        
        switch ($driverConfig['driver'] ?? 'file') {
            case 'redis':
                $this->driver = new RedisCacheDriver($driverConfig, $this->logger);
                break;
            case 'array':
                $this->driver = new ArrayCacheDriver($driverConfig, $this->logger);
                break;
            case 'file':
            default:
                $this->driver = new FileCacheDriver($driverConfig, $this->logger);
                break;
        }
        
        $this->logger->info('Cache driver initialized', [
            'driver' => $driverConfig['driver'] ?? 'file',
            'config' => array_diff_key($driverConfig, ['password' => null])
        ]);
    }
    
    /**
     * Get item from cache
     */
    public function get(string $key, $default = null) {
        $key = $this->buildKey($key);
        
        try {
            $value = $this->driver->get($key);
            
            if ($value !== null) {
                $this->logger->debug('Cache hit', ['key' => $key]);
                return $value;
            }
            
            $this->logger->debug('Cache miss', ['key' => $key]);
            return $default;
            
        } catch (Exception $e) {
            $this->logger->error('Cache get error', [
                'key' => $key,
                'error' => $e->getMessage()
            ]);
            return $default;
        }
    }
    
    /**
     * Set item in cache
     */
    public function set(string $key, $value, ?int $ttl = null): bool {
        $key = $this->buildKey($key);
        $ttl = $ttl ?? $this->defaultTtl;
        
        try {
            $result = $this->driver->set($key, $value, $ttl);
            
            $this->logger->debug('Cache set', [
                'key' => $key,
                'ttl' => $ttl,
                'success' => $result
            ]);
            
            return $result;
            
        } catch (Exception $e) {
            $this->logger->error('Cache set error', [
                'key' => $key,
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }
    
    /**
     * Delete item from cache
     */
    public function delete(string $key): bool {
        $key = $this->buildKey($key);
        
        try {
            $result = $this->driver->delete($key);
            
            $this->logger->debug('Cache delete', [
                'key' => $key,
                'success' => $result
            ]);
            
            return $result;
            
        } catch (Exception $e) {
            $this->logger->error('Cache delete error', [
                'key' => $key,
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }
    
    /**
     * Check if item exists in cache
     */
    public function has(string $key): bool {
        $key = $this->buildKey($key);
        
        try {
            return $this->driver->has($key);
        } catch (Exception $e) {
            $this->logger->error('Cache has error', [
                'key' => $key,
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }
    
    /**
     * Get multiple items from cache
     */
    public function getMultiple(array $keys, $default = null): array {
        $results = [];
        
        foreach ($keys as $key) {
            $results[$key] = $this->get($key, $default);
        }
        
        return $results;
    }
    
    /**
     * Set multiple items in cache
     */
    public function setMultiple(array $values, ?int $ttl = null): bool {
        $success = true;
        
        foreach ($values as $key => $value) {
            if (!$this->set($key, $value, $ttl)) {
                $success = false;
            }
        }
        
        return $success;
    }
    
    /**
     * Delete multiple items from cache
     */
    public function deleteMultiple(array $keys): bool {
        $success = true;
        
        foreach ($keys as $key) {
            if (!$this->delete($key)) {
                $success = false;
            }
        }
        
        return $success;
    }
    
    /**
     * Clear all cache
     */
    public function clear(): bool {
        try {
            $result = $this->driver->clear();
            
            $this->logger->info('Cache cleared', ['success' => $result]);
            
            return $result;
            
        } catch (Exception $e) {
            $this->logger->error('Cache clear error', ['error' => $e->getMessage()]);
            return false;
        }
    }
    
    /**
     * Get or set cache value with callback
     */
    public function remember(string $key, callable $callback, ?int $ttl = null) {
        $value = $this->get($key);
        
        if ($value !== null) {
            return $value;
        }
        
        $value = $callback();
        $this->set($key, $value, $ttl);
        
        return $value;
    }
    
    /**
     * Increment cache value
     */
    public function increment(string $key, int $value = 1): int {
        $key = $this->buildKey($key);
        
        try {
            return $this->driver->increment($key, $value);
        } catch (Exception $e) {
            $this->logger->error('Cache increment error', [
                'key' => $key,
                'error' => $e->getMessage()
            ]);
            return 0;
        }
    }
    
    /**
     * Decrement cache value
     */
    public function decrement(string $key, int $value = 1): int {
        $key = $this->buildKey($key);
        
        try {
            return $this->driver->decrement($key, $value);
        } catch (Exception $e) {
            $this->logger->error('Cache decrement error', [
                'key' => $key,
                'error' => $e->getMessage()
            ]);
            return 0;
        }
    }
    
    /**
     * Get cache statistics
     */
    public function getStats(): array {
        try {
            $stats = $this->driver->getStats();
            $stats['prefix'] = $this->prefix;
            $stats['default_ttl'] = $this->defaultTtl;
            
            return $stats;
            
        } catch (Exception $e) {
            $this->logger->error('Cache stats error', ['error' => $e->getMessage()]);
            return ['error' => $e->getMessage()];
        }
    }
    
    /**
     * Build cache key with prefix
     */
    private function buildKey(string $key): string {
        return $this->prefix . ':' . $key;
    }
    
    /**
     * Prevent cloning
     */
    private function __clone() {}
    
    /**
     * Prevent unserialization
     */
    public function __wakeup() {}
}

/**
 * File Cache Driver
 */
class FileCacheDriver {
    private $path;
    private $logger;
    
    public function __construct(array $config, Logger $logger) {
        $this->path = $config['path'] ?? sys_get_temp_dir() . '/cache';
        $this->logger = $logger;
        
        if (!is_dir($this->path)) {
            mkdir($this->path, 0755, true);
        }
    }
    
    public function get(string $key) {
        $filepath = $this->getFilePath($key);
        
        if (!file_exists($filepath)) {
            return null;
        }
        
        $content = file_get_contents($filepath);
        $data = unserialize($content);
        
        if ($data['expires'] > 0 && $data['expires'] < time()) {
            unlink($filepath);
            return null;
        }
        
        return $data['value'];
    }
    
    public function set(string $key, $value, int $ttl): bool {
        $filepath = $this->getFilePath($key);
        $expires = $ttl > 0 ? time() + $ttl : 0;
        
        $data = [
            'value' => $value,
            'expires' => $expires,
            'created' => time()
        ];
        
        return file_put_contents($filepath, serialize($data), LOCK_EX) !== false;
    }
    
    public function delete(string $key): bool {
        $filepath = $this->getFilePath($key);
        
        if (file_exists($filepath)) {
            return unlink($filepath);
        }
        
        return true;
    }
    
    public function has(string $key): bool {
        return $this->get($key) !== null;
    }
    
    public function clear(): bool {
        $files = glob($this->path . '/*');
        $success = true;
        
        foreach ($files as $file) {
            if (is_file($file) && !unlink($file)) {
                $success = false;
            }
        }
        
        return $success;
    }
    
    public function increment(string $key, int $value): int {
        $current = (int)$this->get($key);
        $new = $current + $value;
        $this->set($key, $new, 0);
        return $new;
    }
    
    public function decrement(string $key, int $value): int {
        $current = (int)$this->get($key);
        $new = $current - $value;
        $this->set($key, $new, 0);
        return $new;
    }
    
    public function getStats(): array {
        $files = glob($this->path . '/*');
        $totalSize = 0;
        
        foreach ($files as $file) {
            if (is_file($file)) {
                $totalSize += filesize($file);
            }
        }
        
        return [
            'driver' => 'file',
            'path' => $this->path,
            'file_count' => count($files),
            'total_size' => $totalSize,
            'total_size_human' => $this->formatBytes($totalSize)
        ];
    }
    
    private function getFilePath(string $key): string {
        return $this->path . '/' . md5($key) . '.cache';
    }
    
    private function formatBytes(int $bytes): string {
        $units = ['B', 'KB', 'MB', 'GB'];
        $factor = floor((strlen($bytes) - 1) / 3);
        return sprintf("%.2f %s", $bytes / pow(1024, $factor), $units[$factor]);
    }
}

/**
 * Array Cache Driver (in-memory)
 */
class ArrayCacheDriver {
    private $cache = [];
    private $logger;
    
    public function __construct(array $config, Logger $logger) {
        $this->logger = $logger;
    }
    
    public function get(string $key) {
        if (!isset($this->cache[$key])) {
            return null;
        }
        
        $data = $this->cache[$key];
        
        if ($data['expires'] > 0 && $data['expires'] < time()) {
            unset($this->cache[$key]);
            return null;
        }
        
        return $data['value'];
    }
    
    public function set(string $key, $value, int $ttl): bool {
        $expires = $ttl > 0 ? time() + $ttl : 0;
        
        $this->cache[$key] = [
            'value' => $value,
            'expires' => $expires,
            'created' => time()
        ];
        
        return true;
    }
    
    public function delete(string $key): bool {
        unset($this->cache[$key]);
        return true;
    }
    
    public function has(string $key): bool {
        return $this->get($key) !== null;
    }
    
    public function clear(): bool {
        $this->cache = [];
        return true;
    }
    
    public function increment(string $key, int $value): int {
        $current = (int)$this->get($key);
        $new = $current + $value;
        $this->set($key, $new, 0);
        return $new;
    }
    
    public function decrement(string $key, int $value): int {
        $current = (int)$this->get($key);
        $new = $current - $value;
        $this->set($key, $new, 0);
        return $new;
    }
    
    public function getStats(): array {
        return [
            'driver' => 'array',
            'item_count' => count($this->cache),
            'memory_usage' => memory_get_usage(true),
            'memory_peak' => memory_get_peak_usage(true)
        ];
    }
}

/**
 * Redis Cache Driver (if Redis extension is available)
 */
class RedisCacheDriver {
    private $redis;
    private $logger;
    private $config;
    
    public function __construct(array $config, Logger $logger) {
        $this->config = $config;
        $this->logger = $logger;
        
        if (!extension_loaded('redis')) {
            throw new Exception('Redis extension is not loaded');
        }
        
        $this->connect();
    }
    
    private function connect(): void {
        $this->redis = new Redis();
        
        $host = $this->config['host'] ?? '127.0.0.1';
        $port = $this->config['port'] ?? 6379;
        $timeout = $this->config['timeout'] ?? 5.0;
        
        if (!$this->redis->connect($host, $port, $timeout)) {
            throw new Exception("Cannot connect to Redis server at {$host}:{$port}");
        }
        
        if (!empty($this->config['password'])) {
            $this->redis->auth($this->config['password']);
        }
        
        if (isset($this->config['database'])) {
            $this->redis->select($this->config['database']);
        }
    }
    
    public function get(string $key) {
        $value = $this->redis->get($key);
        
        if ($value === false) {
            return null;
        }
        
        return unserialize($value);
    }
    
    public function set(string $key, $value, int $ttl): bool {
        $serialized = serialize($value);
        
        if ($ttl > 0) {
            return $this->redis->setex($key, $ttl, $serialized);
        } else {
            return $this->redis->set($key, $serialized);
        }
    }
    
    public function delete(string $key): bool {
        return $this->redis->del($key) > 0;
    }
    
    public function has(string $key): bool {
        return $this->redis->exists($key);
    }
    
    public function clear(): bool {
        return $this->redis->flushDB();
    }
    
    public function increment(string $key, int $value): int {
        return $this->redis->incrBy($key, $value);
    }
    
    public function decrement(string $key, int $value): int {
        return $this->redis->decrBy($key, $value);
    }
    
    public function getStats(): array {
        $info = $this->redis->info();
        
        return [
            'driver' => 'redis',
            'host' => $this->config['host'] ?? '127.0.0.1',
            'port' => $this->config['port'] ?? 6379,
            'database' => $this->config['database'] ?? 0,
            'connected_clients' => $info['connected_clients'] ?? 0,
            'used_memory' => $info['used_memory_human'] ?? 'unknown',
            'total_commands_processed' => $info['total_commands_processed'] ?? 0
        ];
    }
}

/**
 * Helper functions for global access
 */
function cache(): CacheService {
    return CacheService::getInstance();
}

function cacheGet(string $key, $default = null) {
    return CacheService::getInstance()->get($key, $default);
}

function cacheSet(string $key, $value, ?int $ttl = null): bool {
    return CacheService::getInstance()->set($key, $value, $ttl);
}

function cacheRemember(string $key, callable $callback, ?int $ttl = null) {
    return CacheService::getInstance()->remember($key, $callback, $ttl);
}