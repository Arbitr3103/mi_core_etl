<?php

namespace MDM\Services;

use PDO;
use PDOException;
use Redis;
use Exception;

/**
 * Расширенный сервис для кэширования данных MDM системы
 * Поддерживает многоуровневое кэширование: память -> Redis -> база данных
 */
class AdvancedCacheService
{
    private PDO $pdo;
    private ?Redis $redis = null;
    private array $config;
    private array $memoryCache = [];
    
    public function __construct(PDO $pdo, array $config = [])
    {
        $this->pdo = $pdo;
        $this->config = array_merge([
            'default_ttl' => 3600, // 1 час
            'memory_cache_size' => 1000,
            'enable_memory_cache' => true,
            'enable_redis_cache' => false,
            'cache_prefix' => 'mdm_',
            'redis_host' => '127.0.0.1',
            'redis_port' => 6379,
            'redis_password' => null,
            'redis_database' => 0,
            'distributed_cache' => false
        ], $config);
        
        $this->initializeCacheTable();
        $this->initializeRedis();
    }
    
    /**
     * Инициализация таблицы кэша
     */
    private function initializeCacheTable(): void
    {
        $sql = "
        CREATE TABLE IF NOT EXISTS mdm_cache (
            cache_key VARCHAR(255) PRIMARY KEY,
            cache_value LONGTEXT NOT NULL,
            expires_at TIMESTAMP NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            
            INDEX idx_expires_at (expires_at)
        );
        
        CREATE TABLE IF NOT EXISTS mdm_cache_stats (
            id BIGINT AUTO_INCREMENT PRIMARY KEY,
            cache_key VARCHAR(255) NOT NULL,
            hit_count INT DEFAULT 0,
            miss_count INT DEFAULT 0,
            last_access TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            
            UNIQUE KEY unique_key (cache_key),
            INDEX idx_last_access (last_access)
        );
        ";
        
        $this->pdo->exec($sql);
    }
    
    /**
     * Инициализация Redis
     */
    private function initializeRedis(): void
    {
        if (!$this->config['enable_redis_cache']) {
            return;
        }
        
        try {
            if (class_exists('Redis')) {
                $this->redis = new Redis();
                $this->redis->connect($this->config['redis_host'], $this->config['redis_port']);
                
                if ($this->config['redis_password']) {
                    $this->redis->auth($this->config['redis_password']);
                }
                
                $this->redis->select($this->config['redis_database']);
            }
        } catch (Exception $e) {
            error_log("Redis initialization failed: " . $e->getMessage());
            $this->redis = null;
        }
    }
    
    /**
     * Получить значение из кэша
     */
    public function get(string $key): mixed
    {
        $fullKey = $this->config['cache_prefix'] . $key;
        
        // 1. Проверить memory cache
        if ($this->config['enable_memory_cache'] && isset($this->memoryCache[$fullKey])) {
            $cached = $this->memoryCache[$fullKey];
            if ($cached['expires_at'] > time()) {
                $this->recordCacheHit($key, 'memory');
                return $cached['value'];
            } else {
                unset($this->memoryCache[$fullKey]);
            }
        }
        
        // 2. Проверить Redis cache
        if ($this->redis) {
            try {
                $cached = $this->redis->get($fullKey);
                if ($cached !== false) {
                    $value = unserialize($cached);
                    
                    // Добавить в memory cache
                    if ($this->config['enable_memory_cache']) {
                        $ttl = $this->redis->ttl($fullKey);
                        if ($ttl > 0) {
                            $this->addToMemoryCache($fullKey, $value, time() + $ttl);
                        }
                    }
                    
                    $this->recordCacheHit($key, 'redis');
                    return $value;
                }
            } catch (Exception $e) {
                error_log("Redis get error: " . $e->getMessage());
            }
        }
        
        // 3. Проверить database cache
        try {
            $stmt = $this->pdo->prepare("
                SELECT cache_value, expires_at 
                FROM mdm_cache 
                WHERE cache_key = ? AND expires_at > NOW()
            ");
            $stmt->execute([$fullKey]);
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($result) {
                $value = unserialize($result['cache_value']);
                $expiresAt = strtotime($result['expires_at']);
                $ttl = $expiresAt - time();
                
                // Добавить в Redis cache
                if ($this->redis && $ttl > 0) {
                    try {
                        $this->redis->setex($fullKey, $ttl, serialize($value));
                    } catch (Exception $e) {
                        error_log("Redis set error: " . $e->getMessage());
                    }
                }
                
                // Добавить в memory cache
                if ($this->config['enable_memory_cache']) {
                    $this->addToMemoryCache($fullKey, $value, $expiresAt);
                }
                
                $this->recordCacheHit($key, 'database');
                return $value;
            }
        } catch (PDOException $e) {
            error_log("Cache get error: " . $e->getMessage());
        }
        
        $this->recordCacheMiss($key);
        return null;
    }
    
    /**
     * Сохранить значение в кэш
     */
    public function set(string $key, mixed $value, ?int $ttl = null): bool
    {
        $fullKey = $this->config['cache_prefix'] . $key;
        $ttl = $ttl ?? $this->config['default_ttl'];
        $expiresAt = time() + $ttl;
        
        $success = true;
        
        try {
            // Сохранить в database cache
            $stmt = $this->pdo->prepare("
                INSERT INTO mdm_cache (cache_key, cache_value, expires_at)
                VALUES (?, ?, FROM_UNIXTIME(?))
                ON DUPLICATE KEY UPDATE
                cache_value = VALUES(cache_value),
                expires_at = VALUES(expires_at),
                updated_at = NOW()
            ");
            
            $stmt->execute([$fullKey, serialize($value), $expiresAt]);
            
            // Сохранить в Redis cache
            if ($this->redis) {
                try {
                    $this->redis->setex($fullKey, $ttl, serialize($value));
                } catch (Exception $e) {
                    error_log("Redis set error: " . $e->getMessage());
                    $success = false;
                }
            }
            
            // Добавить в memory cache
            if ($this->config['enable_memory_cache']) {
                $this->addToMemoryCache($fullKey, $value, $expiresAt);
            }
            
            return $success;
        } catch (PDOException $e) {
            error_log("Cache set error: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Удалить значение из кэша
     */
    public function delete(string $key): bool
    {
        $fullKey = $this->config['cache_prefix'] . $key;
        
        // Удалить из memory cache
        unset($this->memoryCache[$fullKey]);
        
        // Удалить из Redis cache
        if ($this->redis) {
            try {
                $this->redis->del($fullKey);
            } catch (Exception $e) {
                error_log("Redis delete error: " . $e->getMessage());
            }
        }
        
        // Удалить из database cache
        try {
            $stmt = $this->pdo->prepare("DELETE FROM mdm_cache WHERE cache_key = ?");
            $stmt->execute([$fullKey]);
            return true;
        } catch (PDOException $e) {
            error_log("Cache delete error: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Инвалидация кэша по паттерну
     */
    public function invalidatePattern(string $pattern): int
    {
        $fullPattern = $this->config['cache_prefix'] . $pattern;
        $deleted = 0;
        
        // Инвалидация в memory cache
        foreach (array_keys($this->memoryCache) as $key) {
            if (fnmatch($fullPattern, $key)) {
                unset($this->memoryCache[$key]);
                $deleted++;
            }
        }
        
        // Инвалидация в Redis cache
        if ($this->redis) {
            try {
                $keys = $this->redis->keys($fullPattern);
                if (!empty($keys)) {
                    $this->redis->del($keys);
                    $deleted += count($keys);
                }
            } catch (Exception $e) {
                error_log("Redis pattern delete error: " . $e->getMessage());
            }
        }
        
        // Инвалидация в database cache
        try {
            $stmt = $this->pdo->prepare("DELETE FROM mdm_cache WHERE cache_key LIKE ?");
            $stmt->execute([str_replace('*', '%', $fullPattern)]);
            $deleted += $stmt->rowCount();
        } catch (PDOException $e) {
            error_log("Cache pattern delete error: " . $e->getMessage());
        }
        
        return $deleted;
    }
    
    /**
     * Кэширование результатов сопоставления
     */
    public function cacheMatchingResults(string $productHash, array $matches, int $ttl = 7200): bool
    {
        $key = "matching_results_{$productHash}";
        return $this->set($key, $matches, $ttl);
    }
    
    /**
     * Получить кэшированные результаты сопоставления
     */
    public function getCachedMatchingResults(string $productHash): ?array
    {
        $key = "matching_results_{$productHash}";
        return $this->get($key);
    }
    
    /**
     * Кэширование мастер-данных товара
     */
    public function cacheMasterProduct(string $masterId, array $product, int $ttl = 3600): bool
    {
        $key = "master_product_{$masterId}";
        return $this->set($key, $product, $ttl);
    }
    
    /**
     * Получить кэшированные мастер-данные товара
     */
    public function getCachedMasterProduct(string $masterId): ?array
    {
        $key = "master_product_{$masterId}";
        return $this->get($key);
    }
    
    /**
     * Инвалидировать кэш мастер-данных при обновлении
     */
    public function invalidateMasterProductCache(string $masterId): void
    {
        $this->delete("master_product_{$masterId}");
        $this->invalidatePattern("master_product_list_*");
        $this->invalidatePattern("statistics_*");
    }
    
    /**
     * Кэширование статистики качества данных
     */
    public function cacheQualityStats(array $stats, int $ttl = 1800): bool
    {
        $key = "quality_stats_" . date('Y-m-d_H');
        return $this->set($key, $stats, $ttl);
    }
    
    /**
     * Получить кэшированную статистику качества данных
     */
    public function getCachedQualityStats(): ?array
    {
        $key = "quality_stats_" . date('Y-m-d_H');
        return $this->get($key);
    }
    
    /**
     * Очистить весь кэш
     */
    public function clear(): bool
    {
        // Очистить memory cache
        $this->memoryCache = [];
        
        // Очистить Redis cache
        if ($this->redis) {
            try {
                $keys = $this->redis->keys($this->config['cache_prefix'] . '*');
                if (!empty($keys)) {
                    $this->redis->del($keys);
                }
            } catch (Exception $e) {
                error_log("Redis clear error: " . $e->getMessage());
            }
        }
        
        // Очистить database cache
        try {
            $this->pdo->exec("DELETE FROM mdm_cache WHERE cache_key LIKE '" . $this->config['cache_prefix'] . "%'");
            return true;
        } catch (PDOException $e) {
            error_log("Cache clear error: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Очистить просроченные записи
     */
    public function cleanup(): int
    {
        $deleted = 0;
        
        // Очистить просроченные записи из memory cache
        foreach ($this->memoryCache as $key => $cached) {
            if ($cached['expires_at'] <= time()) {
                unset($this->memoryCache[$key]);
                $deleted++;
            }
        }
        
        // Очистить просроченные записи из database cache
        try {
            $stmt = $this->pdo->prepare("DELETE FROM mdm_cache WHERE expires_at < NOW()");
            $stmt->execute();
            $deleted += $stmt->rowCount();
        } catch (PDOException $e) {
            error_log("Cache cleanup error: " . $e->getMessage());
        }
        
        return $deleted;
    }
    
    /**
     * Получить статистику кэша
     */
    public function getCacheStats(): array
    {
        $stats = [
            'memory_cache' => [
                'size' => count($this->memoryCache),
                'max_size' => $this->config['memory_cache_size'],
                'hit_rate' => 0
            ],
            'redis_cache' => [
                'enabled' => $this->redis !== null,
                'connected' => false,
                'memory_usage' => 0
            ],
            'database_cache' => [
                'total_entries' => 0,
                'expired_entries' => 0,
                'size_mb' => 0
            ],
            'cache_hits' => [],
            'top_keys' => []
        ];
        
        // Redis статистика
        if ($this->redis) {
            try {
                $stats['redis_cache']['connected'] = $this->redis->ping() === '+PONG';
                $info = $this->redis->info('memory');
                $stats['redis_cache']['memory_usage'] = $info['used_memory'] ?? 0;
            } catch (Exception $e) {
                $stats['redis_cache']['connected'] = false;
            }
        }
        
        // Database статистика
        try {
            $stmt = $this->pdo->query("
                SELECT 
                    COUNT(*) as total_entries,
                    SUM(CASE WHEN expires_at < NOW() THEN 1 ELSE 0 END) as expired_entries,
                    ROUND(SUM(LENGTH(cache_value)) / 1024 / 1024, 2) as size_mb
                FROM mdm_cache
                WHERE cache_key LIKE '" . $this->config['cache_prefix'] . "%'
            ");
            $dbStats = $stmt->fetch(PDO::FETCH_ASSOC);
            $stats['database_cache'] = array_merge($stats['database_cache'], $dbStats);
            
            // Топ ключей по обращениям
            $stmt = $this->pdo->query("
                SELECT cache_key, hit_count, miss_count, last_access
                FROM mdm_cache_stats
                ORDER BY (hit_count + miss_count) DESC
                LIMIT 10
            ");
            $stats['top_keys'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
        } catch (PDOException $e) {
            error_log("Cache stats error: " . $e->getMessage());
        }
        
        return $stats;
    }
    
    /**
     * Записать попадание в кэш
     */
    private function recordCacheHit(string $key, string $source): void
    {
        try {
            $stmt = $this->pdo->prepare("
                INSERT INTO mdm_cache_stats (cache_key, hit_count, last_access)
                VALUES (?, 1, NOW())
                ON DUPLICATE KEY UPDATE
                hit_count = hit_count + 1,
                last_access = NOW()
            ");
            $stmt->execute([$key]);
        } catch (PDOException $e) {
            // Игнорируем ошибки статистики
        }
    }
    
    /**
     * Записать промах кэша
     */
    private function recordCacheMiss(string $key): void
    {
        try {
            $stmt = $this->pdo->prepare("
                INSERT INTO mdm_cache_stats (cache_key, miss_count, last_access)
                VALUES (?, 1, NOW())
                ON DUPLICATE KEY UPDATE
                miss_count = miss_count + 1,
                last_access = NOW()
            ");
            $stmt->execute([$key]);
        } catch (PDOException $e) {
            // Игнорируем ошибки статистики
        }
    }
    
    /**
     * Добавить в memory cache
     */
    private function addToMemoryCache(string $key, mixed $value, int $expiresAt): void
    {
        // Проверить размер memory cache
        if (count($this->memoryCache) >= $this->config['memory_cache_size']) {
            // Удалить самые старые записи
            $this->evictOldestMemoryCacheEntries();
        }
        
        $this->memoryCache[$key] = [
            'value' => $value,
            'expires_at' => $expiresAt,
            'created_at' => time()
        ];
    }
    
    /**
     * Удалить самые старые записи из memory cache
     */
    private function evictOldestMemoryCacheEntries(): void
    {
        // Сортировать по времени создания и удалить 10% самых старых
        uasort($this->memoryCache, function($a, $b) {
            return $a['created_at'] <=> $b['created_at'];
        });
        
        $toRemove = (int) ($this->config['memory_cache_size'] * 0.1);
        $removed = 0;
        
        foreach ($this->memoryCache as $key => $value) {
            if ($removed >= $toRemove) break;
            unset($this->memoryCache[$key]);
            $removed++;
        }
    }
}