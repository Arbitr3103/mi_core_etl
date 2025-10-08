#!/usr/bin/env php
<?php

/**
 * CLI утилита для управления кэшем MDM системы
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../src/MDM/Services/AdvancedCacheService.php';
require_once __DIR__ . '/../src/MDM/Services/CacheInvalidationService.php';

use MDM\Services\AdvancedCacheService;
use MDM\Services\CacheInvalidationService;

class CacheManager
{
    private AdvancedCacheService $cacheService;
    private CacheInvalidationService $invalidationService;
    
    public function __construct()
    {
        global $pdo;
        
        $cacheConfig = [
            'enable_redis_cache' => true,
            'redis_host' => '127.0.0.1',
            'redis_port' => 6379,
            'memory_cache_size' => 2000
        ];
        
        $this->cacheService = new AdvancedCacheService($pdo, $cacheConfig);
        $this->invalidationService = new CacheInvalidationService($this->cacheService, $pdo);
    }
    
    public function run(array $args): void
    {
        if (count($args) < 2) {
            $this->showHelp();
            return;
        }
        
        $command = $args[1];
        
        switch ($command) {
            case 'stats':
                $this->showStats();
                break;
                
            case 'cleanup':
                $this->cleanup();
                break;
                
            case 'clear':
                $this->clear($args[2] ?? null);
                break;
                
            case 'invalidate':
                $this->invalidate($args[2] ?? null);
                break;
                
            case 'process-queue':
                $this->processInvalidationQueue();
                break;
                
            case 'warm-up':
                $this->warmUp();
                break;
                
            case 'monitor':
                $this->monitor();
                break;
                
            default:
                echo "Unknown command: {$command}\n";
                $this->showHelp();
        }
    }
    
    private function showHelp(): void
    {
        echo "MDM Cache Manager\n";
        echo "Usage: php cache_manager.php <command> [options]\n\n";
        echo "Commands:\n";
        echo "  stats              Show cache statistics\n";
        echo "  cleanup            Clean up expired cache entries\n";
        echo "  clear [pattern]    Clear cache (optionally by pattern)\n";
        echo "  invalidate <key>   Invalidate specific cache key or pattern\n";
        echo "  process-queue      Process invalidation queue\n";
        echo "  warm-up            Warm up cache with frequently used data\n";
        echo "  monitor            Monitor cache performance in real-time\n\n";
        echo "Examples:\n";
        echo "  php cache_manager.php stats\n";
        echo "  php cache_manager.php clear 'master_product_*'\n";
        echo "  php cache_manager.php invalidate statistics_*\n";
    }
    
    private function showStats(): void
    {
        echo "=== Cache Statistics ===\n\n";
        
        $stats = $this->cacheService->getCacheStats();
        
        // Memory Cache
        echo "Memory Cache:\n";
        echo "  Size: {$stats['memory_cache']['size']} / {$stats['memory_cache']['max_size']}\n";
        echo "  Usage: " . round(($stats['memory_cache']['size'] / $stats['memory_cache']['max_size']) * 100, 2) . "%\n\n";
        
        // Redis Cache
        echo "Redis Cache:\n";
        echo "  Enabled: " . ($stats['redis_cache']['enabled'] ? 'Yes' : 'No') . "\n";
        echo "  Connected: " . ($stats['redis_cache']['connected'] ? 'Yes' : 'No') . "\n";
        if ($stats['redis_cache']['memory_usage']) {
            echo "  Memory Usage: " . $this->formatBytes($stats['redis_cache']['memory_usage']) . "\n";
        }
        echo "\n";
        
        // Database Cache
        echo "Database Cache:\n";
        echo "  Total Entries: {$stats['database_cache']['total_entries']}\n";
        echo "  Expired Entries: {$stats['database_cache']['expired_entries']}\n";
        echo "  Size: {$stats['database_cache']['size_mb']} MB\n\n";
        
        // Top Keys
        if (!empty($stats['top_keys'])) {
            echo "Top Cache Keys:\n";
            foreach ($stats['top_keys'] as $key) {
                $hitRate = $key['hit_count'] + $key['miss_count'] > 0 
                    ? round(($key['hit_count'] / ($key['hit_count'] + $key['miss_count'])) * 100, 2)
                    : 0;
                echo "  {$key['cache_key']}: {$key['hit_count']} hits, {$key['miss_count']} misses ({$hitRate}%)\n";
            }
        }
        
        // Invalidation Stats
        echo "\n=== Invalidation Queue Statistics ===\n";
        $invalidationStats = $this->invalidationService->getInvalidationStats();
        echo "Total Items: {$invalidationStats['total_items']}\n";
        echo "Pending Items: {$invalidationStats['pending_items']}\n";
        echo "Processed Items: {$invalidationStats['processed_items']}\n";
        
        if (!empty($invalidationStats['top_patterns'])) {
            echo "\nTop Invalidation Patterns:\n";
            foreach ($invalidationStats['top_patterns'] as $pattern) {
                echo "  {$pattern['cache_pattern']}: {$pattern['frequency']} times\n";
            }
        }
    }
    
    private function cleanup(): void
    {
        echo "Cleaning up expired cache entries...\n";
        
        $deleted = $this->cacheService->cleanup();
        echo "Deleted {$deleted} expired entries from cache\n";
        
        $queueDeleted = $this->invalidationService->cleanupInvalidationQueue();
        echo "Deleted {$queueDeleted} processed items from invalidation queue\n";
        
        echo "Cleanup completed\n";
    }
    
    private function clear(?string $pattern): void
    {
        if ($pattern) {
            echo "Clearing cache entries matching pattern: {$pattern}\n";
            $deleted = $this->cacheService->invalidatePattern($pattern);
            echo "Deleted {$deleted} cache entries\n";
        } else {
            echo "Clearing all cache...\n";
            $result = $this->cacheService->clear();
            echo $result ? "Cache cleared successfully\n" : "Failed to clear cache\n";
        }
    }
    
    private function invalidate(?string $key): void
    {
        if (!$key) {
            echo "Error: Cache key or pattern is required\n";
            return;
        }
        
        echo "Invalidating cache: {$key}\n";
        
        if (strpos($key, '*') !== false) {
            $deleted = $this->cacheService->invalidatePattern($key);
            echo "Invalidated {$deleted} cache entries\n";
        } else {
            $result = $this->cacheService->delete($key);
            echo $result ? "Cache key invalidated\n" : "Failed to invalidate cache key\n";
        }
    }
    
    private function processInvalidationQueue(): void
    {
        echo "Processing invalidation queue...\n";
        
        $processed = $this->invalidationService->processInvalidationQueue();
        echo "Processed {$processed} invalidation items\n";
    }
    
    private function warmUp(): void
    {
        echo "Warming up cache with frequently used data...\n";
        
        global $pdo;
        
        try {
            // Загрузить популярные мастер-продукты
            $stmt = $pdo->query("
                SELECT mp.master_id, mp.canonical_name, mp.canonical_brand, mp.canonical_category
                FROM master_products mp
                JOIN sku_mapping sm ON mp.master_id = sm.master_id
                WHERE mp.status = 'active'
                GROUP BY mp.master_id
                ORDER BY COUNT(sm.id) DESC
                LIMIT 100
            ");
            
            $products = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            foreach ($products as $product) {
                $this->cacheService->cacheMasterProduct($product['master_id'], $product);
            }
            
            echo "Cached " . count($products) . " popular master products\n";
            
            // Загрузить статистику качества данных
            $stmt = $pdo->query("
                SELECT 
                    COUNT(DISTINCT mp.master_id) as total_masters,
                    COUNT(DISTINCT sm.external_sku) as total_skus,
                    AVG(sm.confidence_score) as avg_confidence
                FROM master_products mp
                LEFT JOIN sku_mapping sm ON mp.master_id = sm.master_id
                WHERE mp.status = 'active'
            ");
            
            $stats = $stmt->fetch(PDO::FETCH_ASSOC);
            $this->cacheService->cacheQualityStats($stats);
            
            echo "Cached quality statistics\n";
            echo "Cache warm-up completed\n";
            
        } catch (Exception $e) {
            echo "Error during cache warm-up: " . $e->getMessage() . "\n";
        }
    }
    
    private function monitor(): void
    {
        echo "Starting cache performance monitor (Press Ctrl+C to stop)...\n\n";
        
        $lastStats = null;
        
        while (true) {
            $stats = $this->cacheService->getCacheStats();
            $timestamp = date('Y-m-d H:i:s');
            
            // Очистить экран
            system('clear');
            
            echo "=== Cache Monitor - {$timestamp} ===\n\n";
            
            // Memory Cache
            $memoryUsage = round(($stats['memory_cache']['size'] / $stats['memory_cache']['max_size']) * 100, 2);
            echo "Memory Cache: {$stats['memory_cache']['size']} / {$stats['memory_cache']['max_size']} ({$memoryUsage}%)\n";
            
            // Redis Cache
            echo "Redis: " . ($stats['redis_cache']['connected'] ? 'Connected' : 'Disconnected');
            if ($stats['redis_cache']['memory_usage']) {
                echo " - " . $this->formatBytes($stats['redis_cache']['memory_usage']);
            }
            echo "\n";
            
            // Database Cache
            echo "Database: {$stats['database_cache']['total_entries']} entries";
            if ($stats['database_cache']['expired_entries'] > 0) {
                echo " ({$stats['database_cache']['expired_entries']} expired)";
            }
            echo " - {$stats['database_cache']['size_mb']} MB\n\n";
            
            // Изменения с последней проверки
            if ($lastStats) {
                $memoryDiff = $stats['memory_cache']['size'] - $lastStats['memory_cache']['size'];
                $dbDiff = $stats['database_cache']['total_entries'] - $lastStats['database_cache']['total_entries'];
                
                echo "Changes: Memory ";
                echo $memoryDiff >= 0 ? "+{$memoryDiff}" : $memoryDiff;
                echo ", Database ";
                echo $dbDiff >= 0 ? "+{$dbDiff}" : $dbDiff;
                echo "\n\n";
            }
            
            // Invalidation Queue
            $invalidationStats = $this->invalidationService->getInvalidationStats();
            echo "Invalidation Queue: {$invalidationStats['pending_items']} pending, {$invalidationStats['processed_items']} processed\n";
            
            $lastStats = $stats;
            sleep(5);
        }
    }
    
    private function formatBytes(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB'];
        $unitIndex = 0;
        
        while ($bytes >= 1024 && $unitIndex < count($units) - 1) {
            $bytes /= 1024;
            $unitIndex++;
        }
        
        return round($bytes, 2) . ' ' . $units[$unitIndex];
    }
}

// Запуск
$manager = new CacheManager();
$manager->run($argv);