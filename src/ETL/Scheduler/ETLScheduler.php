<?php

namespace MDM\ETL\Scheduler;

use Exception;
use PDO;
use MDM\ETL\DataExtractors\OzonExtractor;
use MDM\ETL\DataExtractors\WildberriesExtractor;
use MDM\ETL\DataExtractors\InternalSystemExtractor;

/**
 * Планировщик ETL процессов
 * Управляет регулярным извлечением данных из различных источников
 */
class ETLScheduler
{
    private PDO $pdo;
    private array $config;
    private array $extractors;
    private string $lockFile;
    
    public function __construct(PDO $pdo, array $config = [])
    {
        $this->pdo = $pdo;
        $this->config = $config;
        $this->lockFile = $config['lock_file'] ?? sys_get_temp_dir() . '/mdm_etl.lock';
        
        $this->initializeExtractors();
    }
    
    /**
     * Инициализация экстракторов данных
     */
    private function initializeExtractors(): void
    {
        $this->extractors = [];
        
        // Ozon экстрактор
        if (!empty($this->config['ozon'])) {
            try {
                $this->extractors['ozon'] = new OzonExtractor($this->pdo, $this->config['ozon']);
            } catch (Exception $e) {
                $this->log('WARNING', 'Не удалось инициализировать Ozon экстрактор', [
                    'error' => $e->getMessage()
                ]);
            }
        }
        
        // Wildberries экстрактор
        if (!empty($this->config['wildberries'])) {
            try {
                $this->extractors['wildberries'] = new WildberriesExtractor($this->pdo, $this->config['wildberries']);
            } catch (Exception $e) {
                $this->log('WARNING', 'Не удалось инициализировать Wildberries экстрактор', [
                    'error' => $e->getMessage()
                ]);
            }
        }
        
        // Внутренний экстрактор
        if (!empty($this->config['internal'])) {
            try {
                $this->extractors['internal'] = new InternalSystemExtractor($this->pdo, $this->config['internal']);
            } catch (Exception $e) {
                $this->log('WARNING', 'Не удалось инициализировать внутренний экстрактор', [
                    'error' => $e->getMessage()
                ]);
            }
        }
    }
    
    /**
     * Запуск полного ETL процесса
     * 
     * @param array $options Опции запуска
     * @return array Результаты выполнения
     */
    public function runFullETL(array $options = []): array
    {
        if (!$this->acquireLock()) {
            throw new Exception('ETL процесс уже запущен');
        }
        
        try {
            $this->log('INFO', 'Запуск полного ETL процесса', $options);
            
            $results = [];
            $startTime = microtime(true);
            
            // Извлекаем данные из всех доступных источников
            foreach ($this->extractors as $sourceName => $extractor) {
                try {
                    $sourceStartTime = microtime(true);
                    
                    if (!$extractor->isAvailable()) {
                        $this->log('WARNING', "Источник $sourceName недоступен");
                        $results[$sourceName] = [
                            'status' => 'unavailable',
                            'extracted_count' => 0,
                            'duration' => 0
                        ];
                        continue;
                    }
                    
                    $filters = $this->buildFilters($sourceName, $options);
                    $extractedData = $extractor->extract($filters);
                    
                    // Сохраняем извлеченные данные
                    $savedCount = $this->saveExtractedData($sourceName, $extractedData);
                    
                    $duration = microtime(true) - $sourceStartTime;
                    
                    $results[$sourceName] = [
                        'status' => 'success',
                        'extracted_count' => count($extractedData),
                        'saved_count' => $savedCount,
                        'duration' => round($duration, 2)
                    ];
                    
                    $this->log('INFO', "Завершено извлечение из $sourceName", $results[$sourceName]);
                    
                } catch (Exception $e) {
                    $results[$sourceName] = [
                        'status' => 'error',
                        'error' => $e->getMessage(),
                        'extracted_count' => 0,
                        'duration' => 0
                    ];
                    
                    $this->log('ERROR', "Ошибка извлечения из $sourceName", [
                        'error' => $e->getMessage()
                    ]);
                }
            }
            
            $totalDuration = microtime(true) - $startTime;
            
            // Сохраняем результаты выполнения
            $this->saveETLRun($results, $totalDuration);
            
            $this->log('INFO', 'Полный ETL процесс завершен', [
                'total_duration' => round($totalDuration, 2),
                'results' => $results
            ]);
            
            return $results;
            
        } finally {
            $this->releaseLock();
        }
    }
    
    /**
     * Запуск инкрементального ETL процесса
     * 
     * @param array $options Опции запуска
     * @return array Результаты выполнения
     */
    public function runIncrementalETL(array $options = []): array
    {
        // Получаем время последнего успешного запуска
        $lastRunTime = $this->getLastSuccessfulRunTime();
        
        if ($lastRunTime) {
            $options['updated_after'] = $lastRunTime;
        }
        
        return $this->runFullETL($options);
    }
    
    /**
     * Запуск ETL для конкретного источника
     * 
     * @param string $sourceName Имя источника
     * @param array $options Опции запуска
     * @return array Результаты выполнения
     */
    public function runSourceETL(string $sourceName, array $options = []): array
    {
        if (!isset($this->extractors[$sourceName])) {
            throw new Exception("Экстрактор для источника '$sourceName' не найден");
        }
        
        if (!$this->acquireLock()) {
            throw new Exception('ETL процесс уже запущен');
        }
        
        try {
            $this->log('INFO', "Запуск ETL для источника $sourceName", $options);
            
            $extractor = $this->extractors[$sourceName];
            $startTime = microtime(true);
            
            if (!$extractor->isAvailable()) {
                throw new Exception("Источник $sourceName недоступен");
            }
            
            $filters = $this->buildFilters($sourceName, $options);
            $extractedData = $extractor->extract($filters);
            
            $savedCount = $this->saveExtractedData($sourceName, $extractedData);
            
            $duration = microtime(true) - $startTime;
            
            $result = [
                'status' => 'success',
                'extracted_count' => count($extractedData),
                'saved_count' => $savedCount,
                'duration' => round($duration, 2)
            ];
            
            $this->log('INFO', "ETL для источника $sourceName завершен", $result);
            
            return [$sourceName => $result];
            
        } finally {
            $this->releaseLock();
        }
    }
    
    /**
     * Построение фильтров для извлечения данных
     * 
     * @param string $sourceName Имя источника
     * @param array $options Опции
     * @return array Фильтры
     */
    private function buildFilters(string $sourceName, array $options): array
    {
        $filters = [];
        
        // Общие фильтры
        if (!empty($options['updated_after'])) {
            $filters['updated_after'] = $options['updated_after'];
        }
        
        if (!empty($options['limit'])) {
            $filters['limit'] = $options['limit'];
        }
        
        if (!empty($options['max_pages'])) {
            $filters['max_pages'] = $options['max_pages'];
        }
        
        // Специфичные фильтры для источников
        $sourceConfig = $this->config[$sourceName] ?? [];
        
        if (!empty($sourceConfig['default_filters'])) {
            $filters = array_merge($filters, $sourceConfig['default_filters']);
        }
        
        // Переопределяем фильтрами из опций
        if (!empty($options['filters'])) {
            $filters = array_merge($filters, $options['filters']);
        }
        
        return $filters;
    }
    
    /**
     * Сохранение извлеченных данных в промежуточную таблицу
     * 
     * @param string $sourceName Имя источника
     * @param array $data Данные для сохранения
     * @return int Количество сохраненных записей
     */
    private function saveExtractedData(string $sourceName, array $data): int
    {
        if (empty($data)) {
            return 0;
        }
        
        $savedCount = 0;
        $activityChanges = [];
        
        try {
            $this->pdo->beginTransaction();
            
            // Check if we need to handle activity data
            $hasActivityData = !empty($data[0]['is_active']) || isset($data[0]['is_active']);
            
            if ($hasActivityData) {
                // Prepare statement with activity fields
                $stmt = $this->pdo->prepare("
                    INSERT INTO etl_extracted_data 
                    (source, external_sku, source_name, source_brand, source_category, 
                     price, description, attributes, raw_data, extracted_at, 
                     is_active, activity_checked_at, activity_reason, created_at)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
                    ON DUPLICATE KEY UPDATE
                    source_name = VALUES(source_name),
                    source_brand = VALUES(source_brand),
                    source_category = VALUES(source_category),
                    price = VALUES(price),
                    description = VALUES(description),
                    attributes = VALUES(attributes),
                    raw_data = VALUES(raw_data),
                    extracted_at = VALUES(extracted_at),
                    is_active = VALUES(is_active),
                    activity_checked_at = VALUES(activity_checked_at),
                    activity_reason = VALUES(activity_reason),
                    updated_at = NOW()
                ");
                
                // Get existing activity status for change tracking
                $existingActivityStmt = $this->pdo->prepare("
                    SELECT external_sku, is_active 
                    FROM etl_extracted_data 
                    WHERE source = ? AND external_sku IN (" . str_repeat('?,', count($data) - 1) . "?)
                ");
                $skus = array_column($data, 'external_sku');
                $existingActivityStmt->execute(array_merge([$sourceName], $skus));
                $existingActivity = $existingActivityStmt->fetchAll(PDO::FETCH_KEY_PAIR);
                
            } else {
                // Standard statement without activity fields
                $stmt = $this->pdo->prepare("
                    INSERT INTO etl_extracted_data 
                    (source, external_sku, source_name, source_brand, source_category, 
                     price, description, attributes, raw_data, extracted_at, created_at)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
                    ON DUPLICATE KEY UPDATE
                    source_name = VALUES(source_name),
                    source_brand = VALUES(source_brand),
                    source_category = VALUES(source_category),
                    price = VALUES(price),
                    description = VALUES(description),
                    attributes = VALUES(attributes),
                    raw_data = VALUES(raw_data),
                    extracted_at = VALUES(extracted_at),
                    updated_at = NOW()
                ");
            }
            
            foreach ($data as $item) {
                if ($hasActivityData) {
                    // Track activity changes
                    $currentActivity = $item['is_active'] ?? null;
                    $previousActivity = $existingActivity[$item['external_sku']] ?? null;
                    
                    if ($previousActivity !== null && $currentActivity !== $previousActivity) {
                        $activityChanges[] = [
                            'external_sku' => $item['external_sku'],
                            'previous_status' => $previousActivity,
                            'new_status' => $currentActivity,
                            'reason' => $item['activity_reason'] ?? 'status_change'
                        ];
                    }
                    
                    $stmt->execute([
                        $item['source'],
                        $item['external_sku'],
                        $item['source_name'],
                        $item['source_brand'],
                        $item['source_category'],
                        $item['price'],
                        $item['description'],
                        json_encode($item['attributes'], JSON_UNESCAPED_UNICODE),
                        $item['raw_data'],
                        $item['extracted_at'],
                        $item['is_active'],
                        $item['activity_checked_at'],
                        $item['activity_reason']
                    ]);
                } else {
                    $stmt->execute([
                        $item['source'],
                        $item['external_sku'],
                        $item['source_name'],
                        $item['source_brand'],
                        $item['source_category'],
                        $item['price'],
                        $item['description'],
                        json_encode($item['attributes'], JSON_UNESCAPED_UNICODE),
                        $item['raw_data'],
                        $item['extracted_at']
                    ]);
                }
                
                $savedCount++;
            }
            
            // Log activity changes
            if (!empty($activityChanges)) {
                $this->logActivityChanges($sourceName, $activityChanges);
            }
            
            $this->pdo->commit();
            
            // Update activity monitoring stats if we have activity data
            if ($hasActivityData) {
                $this->updateActivityMonitoringStats($sourceName);
            }
            
        } catch (Exception $e) {
            $this->pdo->rollBack();
            
            $this->log('ERROR', 'Ошибка сохранения извлеченных данных', [
                'source' => $sourceName,
                'error' => $e->getMessage(),
                'data_count' => count($data)
            ]);
            
            throw $e;
        }
        
        return $savedCount;
    }
    
    /**
     * Логирование изменений активности товаров
     * 
     * @param string $sourceName Имя источника
     * @param array $activityChanges Изменения активности
     */
    private function logActivityChanges(string $sourceName, array $activityChanges): void
    {
        if (empty($activityChanges)) {
            return;
        }
        
        try {
            $stmt = $this->pdo->prepare("
                INSERT INTO etl_product_activity_log 
                (source, external_sku, previous_status, new_status, reason, changed_at)
                VALUES (?, ?, ?, ?, ?, NOW())
            ");
            
            foreach ($activityChanges as $change) {
                $stmt->execute([
                    $sourceName,
                    $change['external_sku'],
                    $change['previous_status'],
                    $change['new_status'],
                    $change['reason']
                ]);
            }
            
            $this->log('INFO', 'Зафиксированы изменения активности товаров', [
                'source' => $sourceName,
                'changes_count' => count($activityChanges)
            ]);
            
        } catch (Exception $e) {
            $this->log('ERROR', 'Ошибка логирования изменений активности', [
                'source' => $sourceName,
                'error' => $e->getMessage(),
                'changes_count' => count($activityChanges)
            ]);
        }
    }
    
    /**
     * Обновление статистики мониторинга активности
     * 
     * @param string $sourceName Имя источника
     */
    private function updateActivityMonitoringStats(string $sourceName): void
    {
        try {
            $stmt = $this->pdo->prepare("CALL UpdateActivityMonitoringStats(?)");
            $stmt->execute([$sourceName]);
            
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($result && $result['should_notify']) {
                $this->log('WARNING', 'Значительное изменение количества активных товаров', [
                    'source' => $sourceName,
                    'current_active' => $result['current_active'],
                    'previous_active' => $result['previous_active'],
                    'change_percent' => $result['change_percent'],
                    'threshold' => $result['threshold']
                ]);
                
                // Here you could trigger notification system
                $this->triggerActivityChangeNotification($sourceName, $result);
            }
            
        } catch (Exception $e) {
            $this->log('ERROR', 'Ошибка обновления статистики мониторинга активности', [
                'source' => $sourceName,
                'error' => $e->getMessage()
            ]);
        }
    }
    
    /**
     * Отправка уведомления об изменении активности товаров
     * 
     * @param string $sourceName Имя источника
     * @param array $changeData Данные об изменении
     */
    private function triggerActivityChangeNotification(string $sourceName, array $changeData): void
    {
        // This method can be extended to send actual notifications
        // For now, we just log the notification trigger
        
        $message = sprintf(
            "Источник %s: количество активных товаров изменилось с %d до %d (%.2f%% изменение, порог %.2f%%)",
            $sourceName,
            $changeData['previous_active'],
            $changeData['current_active'],
            $changeData['change_percent'],
            $changeData['threshold']
        );
        
        $this->log('WARNING', 'Уведомление об изменении активности товаров', [
            'source' => $sourceName,
            'message' => $message,
            'change_data' => $changeData
        ]);
        
        // TODO: Implement actual notification sending (email, webhook, etc.)
        // This could integrate with the notification system from task 6.2
    }
    
    /**
     * Сохранение информации о запуске ETL
     * 
     * @param array $results Результаты выполнения
     * @param float $duration Продолжительность выполнения
     */
    private function saveETLRun(array $results, float $duration): void
    {
        try {
            $totalExtracted = array_sum(array_column($results, 'extracted_count'));
            $totalSaved = array_sum(array_column($results, 'saved_count'));
            $hasErrors = !empty(array_filter($results, fn($r) => $r['status'] === 'error'));
            
            $stmt = $this->pdo->prepare("
                INSERT INTO etl_runs 
                (status, duration, total_extracted, total_saved, results, created_at)
                VALUES (?, ?, ?, ?, ?, NOW())
            ");
            
            $stmt->execute([
                $hasErrors ? 'partial_success' : 'success',
                $duration,
                $totalExtracted,
                $totalSaved,
                json_encode($results, JSON_UNESCAPED_UNICODE)
            ]);
            
        } catch (Exception $e) {
            $this->log('ERROR', 'Ошибка сохранения информации о запуске ETL', [
                'error' => $e->getMessage()
            ]);
        }
    }
    
    /**
     * Получение времени последнего успешного запуска
     * 
     * @return string|null Время последнего запуска
     */
    private function getLastSuccessfulRunTime(): ?string
    {
        try {
            $stmt = $this->pdo->prepare("
                SELECT created_at 
                FROM etl_runs 
                WHERE status IN ('success', 'partial_success')
                ORDER BY created_at DESC 
                LIMIT 1
            ");
            $stmt->execute();
            
            $result = $stmt->fetch(PDO::FETCH_COLUMN);
            
            return $result ?: null;
            
        } catch (Exception $e) {
            $this->log('ERROR', 'Ошибка получения времени последнего запуска', [
                'error' => $e->getMessage()
            ]);
            return null;
        }
    }
    
    /**
     * Получение блокировки для предотвращения параллельного запуска
     * 
     * @return bool Успешность получения блокировки
     */
    private function acquireLock(): bool
    {
        if (file_exists($this->lockFile)) {
            $lockContent = file_get_contents($this->lockFile);
            $lockData = json_decode($lockContent, true);
            
            if ($lockData && isset($lockData['pid'])) {
                // Проверяем, активен ли процесс
                if (function_exists('posix_kill') && posix_kill($lockData['pid'], 0)) {
                    return false; // Процесс еще активен
                }
            }
            
            // Удаляем устаревший lock файл
            unlink($this->lockFile);
        }
        
        $lockData = [
            'pid' => getmypid(),
            'started_at' => date('Y-m-d H:i:s'),
            'hostname' => gethostname()
        ];
        
        return file_put_contents($this->lockFile, json_encode($lockData)) !== false;
    }
    
    /**
     * Освобождение блокировки
     */
    private function releaseLock(): void
    {
        if (file_exists($this->lockFile)) {
            unlink($this->lockFile);
        }
    }
    
    /**
     * Получение статуса ETL процессов
     * 
     * @return array Статус процессов
     */
    public function getStatus(): array
    {
        $status = [
            'is_running' => file_exists($this->lockFile),
            'extractors' => [],
            'last_runs' => []
        ];
        
        // Проверяем статус экстракторов
        foreach ($this->extractors as $name => $extractor) {
            $status['extractors'][$name] = [
                'available' => $extractor->isAvailable(),
                'class' => get_class($extractor)
            ];
        }
        
        // Получаем информацию о последних запусках
        try {
            $stmt = $this->pdo->prepare("
                SELECT status, duration, total_extracted, total_saved, created_at
                FROM etl_runs 
                ORDER BY created_at DESC 
                LIMIT 10
            ");
            $stmt->execute();
            
            $status['last_runs'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
        } catch (Exception $e) {
            $this->log('ERROR', 'Ошибка получения статуса ETL', [
                'error' => $e->getMessage()
            ]);
        }
        
        return $status;
    }
    
    /**
     * Логирование сообщений
     * 
     * @param string $level Уровень логирования
     * @param string $message Сообщение
     * @param array $context Контекст
     */
    private function log(string $level, string $message, array $context = []): void
    {
        $fullMessage = $message;
        
        if (!empty($context)) {
            $fullMessage .= ' | Context: ' . json_encode($context, JSON_UNESCAPED_UNICODE);
        }
        
        error_log("[ETL Scheduler] [$level] $fullMessage");
        
        // Сохраняем в БД
        try {
            $stmt = $this->pdo->prepare("
                INSERT INTO etl_logs (source, level, message, context, created_at) 
                VALUES ('scheduler', ?, ?, ?, NOW())
            ");
            $stmt->execute([
                $level, 
                $message, 
                !empty($context) ? json_encode($context) : null
            ]);
        } catch (Exception $e) {
            // Игнорируем ошибки логирования
        }
    }
}