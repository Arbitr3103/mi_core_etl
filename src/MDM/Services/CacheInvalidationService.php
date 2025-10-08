<?php

namespace MDM\Services;

use PDO;
use Exception;

/**
 * Сервис для управления инвалидацией кэша при обновлении данных
 */
class CacheInvalidationService
{
    private AdvancedCacheService $cacheService;
    private PDO $pdo;
    private array $config;
    
    public function __construct(AdvancedCacheService $cacheService, PDO $pdo, array $config = [])
    {
        $this->cacheService = $cacheService;
        $this->pdo = $pdo;
        $this->config = array_merge([
            'auto_invalidation' => true,
            'batch_invalidation' => true,
            'invalidation_delay' => 0 // секунды задержки
        ], $config);
        
        $this->setupTriggers();
    }
    
    /**
     * Настроить триггеры базы данных для автоматической инвалидации
     */
    private function setupTriggers(): void
    {
        if (!$this->config['auto_invalidation']) {
            return;
        }
        
        try {
            // Триггер для обновления мастер-продуктов
            $this->pdo->exec("
                DROP TRIGGER IF EXISTS mdm_master_products_cache_invalidation;
                
                CREATE TRIGGER mdm_master_products_cache_invalidation
                AFTER UPDATE ON master_products
                FOR EACH ROW
                BEGIN
                    INSERT INTO mdm_cache_invalidation_queue (cache_pattern, created_at)
                    VALUES (CONCAT('master_product_', NEW.master_id, '*'), NOW());
                    
                    INSERT INTO mdm_cache_invalidation_queue (cache_pattern, created_at)
                    VALUES ('statistics_*', NOW());
                END;
            ");
            
            // Триггер для обновления сопоставлений SKU
            $this->pdo->exec("
                DROP TRIGGER IF EXISTS mdm_sku_mapping_cache_invalidation;
                
                CREATE TRIGGER mdm_sku_mapping_cache_invalidation
                AFTER INSERT ON sku_mapping
                FOR EACH ROW
                BEGIN
                    INSERT INTO mdm_cache_invalidation_queue (cache_pattern, created_at)
                    VALUES (CONCAT('master_product_', NEW.master_id, '*'), NOW());
                    
                    INSERT INTO mdm_cache_invalidation_queue (cache_pattern, created_at)
                    VALUES ('matching_results_*', NOW());
                    
                    INSERT INTO mdm_cache_invalidation_queue (cache_pattern, created_at)
                    VALUES ('statistics_*', NOW());
                END;
            ");
            
            // Создать таблицу очереди инвалидации
            $this->pdo->exec("
                CREATE TABLE IF NOT EXISTS mdm_cache_invalidation_queue (
                    id BIGINT AUTO_INCREMENT PRIMARY KEY,
                    cache_pattern VARCHAR(255) NOT NULL,
                    processed BOOLEAN DEFAULT FALSE,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    processed_at TIMESTAMP NULL,
                    
                    INDEX idx_processed (processed, created_at)
                )
            ");
            
        } catch (Exception $e) {
            error_log("Failed to setup cache invalidation triggers: " . $e->getMessage());
        }
    }
    
    /**
     * Инвалидировать кэш при обновлении мастер-продукта
     */
    public function invalidateMasterProduct(string $masterId): void
    {
        $patterns = [
            "master_product_{$masterId}",
            "master_product_{$masterId}_*",
            "master_product_list_*",
            "statistics_*",
            "quality_stats_*"
        ];
        
        $this->invalidatePatterns($patterns);
    }
    
    /**
     * Инвалидировать кэш при создании/обновлении сопоставления
     */
    public function invalidateMapping(string $masterId, string $externalSku, string $source): void
    {
        $patterns = [
            "master_product_{$masterId}",
            "master_product_{$masterId}_*",
            "matching_results_*",
            "sku_mapping_{$source}_*",
            "statistics_*"
        ];
        
        $this->invalidatePatterns($patterns);
    }
    
    /**
     * Инвалидировать кэш статистики
     */
    public function invalidateStatistics(): void
    {
        $patterns = [
            "statistics_*",
            "quality_stats_*",
            "dashboard_*",
            "reports_*"
        ];
        
        $this->invalidatePatterns($patterns);
    }
    
    /**
     * Инвалидировать кэш по множественным паттернам
     */
    public function invalidatePatterns(array $patterns): void
    {
        if ($this->config['batch_invalidation']) {
            $this->batchInvalidate($patterns);
        } else {
            foreach ($patterns as $pattern) {
                $this->cacheService->invalidatePattern($pattern);
            }
        }
    }
    
    /**
     * Пакетная инвалидация с задержкой
     */
    private function batchInvalidate(array $patterns): void
    {
        try {
            // Добавить паттерны в очередь
            $stmt = $this->pdo->prepare("
                INSERT INTO mdm_cache_invalidation_queue (cache_pattern, created_at)
                VALUES (?, NOW())
            ");
            
            foreach ($patterns as $pattern) {
                $stmt->execute([$pattern]);
            }
            
            // Если задержка не установлена, обработать сразу
            if ($this->config['invalidation_delay'] === 0) {
                $this->processInvalidationQueue();
            }
            
        } catch (Exception $e) {
            error_log("Batch invalidation failed: " . $e->getMessage());
            
            // Fallback к прямой инвалидации
            foreach ($patterns as $pattern) {
                $this->cacheService->invalidatePattern($pattern);
            }
        }
    }
    
    /**
     * Обработать очередь инвалидации
     */
    public function processInvalidationQueue(): int
    {
        try {
            $processed = 0;
            
            // Получить необработанные записи
            $stmt = $this->pdo->prepare("
                SELECT id, cache_pattern
                FROM mdm_cache_invalidation_queue
                WHERE processed = FALSE
                AND created_at <= DATE_SUB(NOW(), INTERVAL ? SECOND)
                ORDER BY created_at ASC
                LIMIT 100
            ");
            
            $stmt->execute([$this->config['invalidation_delay']]);
            $items = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            if (empty($items)) {
                return 0;
            }
            
            // Группировать по паттернам для оптимизации
            $patterns = [];
            $ids = [];
            
            foreach ($items as $item) {
                $patterns[] = $item['cache_pattern'];
                $ids[] = $item['id'];
            }
            
            // Удалить дубликаты паттернов
            $patterns = array_unique($patterns);
            
            // Инвалидировать кэш
            foreach ($patterns as $pattern) {
                $deleted = $this->cacheService->invalidatePattern($pattern);
                $processed += $deleted;
            }
            
            // Отметить как обработанные
            if (!empty($ids)) {
                $placeholders = str_repeat('?,', count($ids) - 1) . '?';
                $updateStmt = $this->pdo->prepare("
                    UPDATE mdm_cache_invalidation_queue
                    SET processed = TRUE, processed_at = NOW()
                    WHERE id IN ($placeholders)
                ");
                $updateStmt->execute($ids);
            }
            
            return $processed;
            
        } catch (Exception $e) {
            error_log("Failed to process invalidation queue: " . $e->getMessage());
            return 0;
        }
    }
    
    /**
     * Очистить старые записи из очереди инвалидации
     */
    public function cleanupInvalidationQueue(int $olderThanHours = 24): int
    {
        try {
            $stmt = $this->pdo->prepare("
                DELETE FROM mdm_cache_invalidation_queue
                WHERE processed = TRUE
                AND processed_at < DATE_SUB(NOW(), INTERVAL ? HOUR)
            ");
            $stmt->execute([$olderThanHours]);
            
            return $stmt->rowCount();
        } catch (Exception $e) {
            error_log("Failed to cleanup invalidation queue: " . $e->getMessage());
            return 0;
        }
    }
    
    /**
     * Получить статистику очереди инвалидации
     */
    public function getInvalidationStats(): array
    {
        try {
            $stmt = $this->pdo->query("
                SELECT 
                    COUNT(*) as total_items,
                    SUM(CASE WHEN processed = FALSE THEN 1 ELSE 0 END) as pending_items,
                    SUM(CASE WHEN processed = TRUE THEN 1 ELSE 0 END) as processed_items,
                    MIN(created_at) as oldest_pending,
                    MAX(processed_at) as last_processed
                FROM mdm_cache_invalidation_queue
            ");
            
            $stats = $stmt->fetch(PDO::FETCH_ASSOC);
            
            // Топ паттернов по частоте
            $stmt = $this->pdo->query("
                SELECT 
                    cache_pattern,
                    COUNT(*) as frequency,
                    MAX(created_at) as last_used
                FROM mdm_cache_invalidation_queue
                WHERE created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)
                GROUP BY cache_pattern
                ORDER BY frequency DESC
                LIMIT 10
            ");
            
            $stats['top_patterns'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            return $stats;
            
        } catch (Exception $e) {
            error_log("Failed to get invalidation stats: " . $e->getMessage());
            return [
                'total_items' => 0,
                'pending_items' => 0,
                'processed_items' => 0,
                'top_patterns' => []
            ];
        }
    }
    
    /**
     * Принудительная инвалидация всего кэша
     */
    public function forceInvalidateAll(): bool
    {
        try {
            // Очистить весь кэш
            $this->cacheService->clear();
            
            // Очистить очередь инвалидации
            $this->pdo->exec("DELETE FROM mdm_cache_invalidation_queue");
            
            return true;
        } catch (Exception $e) {
            error_log("Failed to force invalidate all cache: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Настроить автоматическую инвалидацию
     */
    public function setAutoInvalidation(bool $enabled): void
    {
        $this->config['auto_invalidation'] = $enabled;
        
        if ($enabled) {
            $this->setupTriggers();
        } else {
            try {
                $this->pdo->exec("DROP TRIGGER IF EXISTS mdm_master_products_cache_invalidation");
                $this->pdo->exec("DROP TRIGGER IF EXISTS mdm_sku_mapping_cache_invalidation");
            } catch (Exception $e) {
                error_log("Failed to drop invalidation triggers: " . $e->getMessage());
            }
        }
    }
}