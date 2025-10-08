<?php

namespace MDM\ETL;

use Exception;
use PDO;
use MDM\ETL\Scheduler\ETLScheduler;
use MDM\ETL\DataTransformers\ProductDataTransformer;
use MDM\ETL\DataTransformers\DataCleaningService;
use MDM\ETL\DataLoaders\MDMLoader;
use MDM\ETL\Config\ETLConfigManager;
use MDM\ETL\Monitoring\ETLMonitoringService;

/**
 * Оркестратор ETL процессов
 * Координирует выполнение всех этапов ETL: извлечение, трансформация, загрузка
 */
class ETLOrchestrator
{
    private PDO $pdo;
    private ETLConfigManager $configManager;
    private ETLScheduler $scheduler;
    private ProductDataTransformer $transformer;
    private DataCleaningService $cleaningService;
    private MDMLoader $loader;
    private ETLMonitoringService $monitoringService;
    
    public function __construct(PDO $pdo, array $config = [])
    {
        $this->pdo = $pdo;
        $this->configManager = new ETLConfigManager($pdo, $config);
        
        $etlConfig = $this->configManager->getETLConfig();
        
        $this->scheduler = new ETLScheduler($pdo, $etlConfig);
        $this->transformer = new ProductDataTransformer($pdo, $config['transformer'] ?? []);
        $this->cleaningService = new DataCleaningService($pdo, $config['cleaning'] ?? []);
        $this->loader = new MDMLoader($pdo, $config['loader'] ?? []);
        $this->monitoringService = new ETLMonitoringService($pdo, $config['monitoring'] ?? []);
    }
    
    /**
     * Выполнение полного ETL процесса
     * 
     * @param array $options Опции выполнения
     * @return array Результаты выполнения
     */
    public function runFullETL(array $options = []): array
    {
        $taskId = $options['task_id'] ?? 'full_etl_' . date('Ymd_His');
        $sessionId = $this->monitoringService->startTaskMonitoring($taskId, 'full_etl', $options);
        
        $this->log('INFO', 'Запуск полного ETL процесса', array_merge($options, ['session_id' => $sessionId]));
        
        $results = [
            'session_id' => $sessionId,
            'extraction' => null,
            'transformation' => null,
            'loading' => null,
            'overall_stats' => [
                'start_time' => microtime(true),
                'success' => false,
                'total_duration' => 0
            ]
        ];
        
        try {
            // Этап 1: Извлечение данных
            $this->log('INFO', 'Этап 1: Извлечение данных');
            $this->monitoringService->updateTaskProgress($sessionId, [
                'current_step' => 'Извлечение данных',
                'records_processed' => 0,
                'records_total' => 0
            ]);
            $results['extraction'] = $this->runExtraction($options);
            
            // Этап 2: Трансформация и очистка данных
            $this->log('INFO', 'Этап 2: Трансформация и очистка данных');
            $this->monitoringService->updateTaskProgress($sessionId, [
                'current_step' => 'Трансформация данных',
                'records_processed' => $results['extraction']['total_extracted'] ?? 0,
                'records_total' => $results['extraction']['total_extracted'] ?? 0
            ]);
            $results['transformation'] = $this->runTransformation($options);
            
            // Этап 3: Загрузка в MDM систему
            $this->log('INFO', 'Этап 3: Загрузка в MDM систему');
            $this->monitoringService->updateTaskProgress($sessionId, [
                'current_step' => 'Загрузка в MDM',
                'records_processed' => $results['transformation']['total_processed'] ?? 0,
                'records_total' => $results['extraction']['total_extracted'] ?? 0
            ]);
            $results['loading'] = $this->runLoading($options);
            
            // Этап 4: Постобработка (опционально)
            if ($options['run_post_processing'] ?? true) {
                $this->log('INFO', 'Этап 4: Постобработка');
                $this->monitoringService->updateTaskProgress($sessionId, [
                    'current_step' => 'Постобработка',
                    'records_processed' => $results['loading']['total_saved'] ?? 0,
                    'records_total' => $results['extraction']['total_extracted'] ?? 0
                ]);
                $results['post_processing'] = $this->runPostProcessing($options);
            }
            
            $results['overall_stats']['success'] = true;
            $results['overall_stats']['total_duration'] = microtime(true) - $results['overall_stats']['start_time'];
            
            // Завершаем мониторинг с успехом
            $this->monitoringService->finishTaskMonitoring($sessionId, 'success', [
                'duration_seconds' => $results['overall_stats']['total_duration'],
                'records_processed' => $results['loading']['total_saved'] ?? 0,
                'extraction_results' => $results['extraction'],
                'transformation_results' => $results['transformation'],
                'loading_results' => $results['loading']
            ]);
            
            $this->log('INFO', 'Полный ETL процесс завершен успешно', [
                'session_id' => $sessionId,
                'duration' => $results['overall_stats']['total_duration']
            ]);
            
            return $results;
            
        } catch (Exception $e) {
            $results['overall_stats']['total_duration'] = microtime(true) - $results['overall_stats']['start_time'];
            $results['overall_stats']['error'] = $e->getMessage();
            
            // Завершаем мониторинг с ошибкой
            $this->monitoringService->finishTaskMonitoring($sessionId, 'error', [
                'error_message' => $e->getMessage(),
                'duration_seconds' => $results['overall_stats']['total_duration'],
                'records_processed' => $results['loading']['total_saved'] ?? 0,
                'partial_results' => $results
            ]);
            
            $this->log('ERROR', 'Ошибка выполнения ETL процесса', [
                'session_id' => $sessionId,
                'error' => $e->getMessage(),
                'duration' => $results['overall_stats']['total_duration']
            ]);
            
            throw $e;
        }
    }
    
    /**
     * Выполнение только извлечения данных
     * 
     * @param array $options Опции выполнения
     * @return array Результаты извлечения
     */
    public function runExtraction(array $options = []): array
    {
        try {
            if (!empty($options['source'])) {
                return $this->scheduler->runSourceETL($options['source'], $options);
            } elseif ($options['incremental'] ?? false) {
                return $this->scheduler->runIncrementalETL($options);
            } else {
                return $this->scheduler->runFullETL($options);
            }
            
        } catch (Exception $e) {
            $this->log('ERROR', 'Ошибка извлечения данных', [
                'error' => $e->getMessage(),
                'options' => $options
            ]);
            throw $e;
        }
    }
    
    /**
     * Выполнение трансформации и очистки данных
     * 
     * @param array $options Опции выполнения
     * @return array Результаты трансформации
     */
    public function runTransformation(array $options = []): array
    {
        try {
            $results = [];
            
            // Основная очистка данных
            $results['cleaning'] = $this->cleaningService->cleanExtractedData($options['filters'] ?? []);
            
            // Удаление дубликатов
            if ($options['remove_duplicates'] ?? true) {
                $results['deduplication'] = $this->cleaningService->removeDuplicates();
            }
            
            // Стандартизация брендов
            if ($options['standardize_brands'] ?? true) {
                $results['brand_standardization'] = $this->cleaningService->standardizeBrands();
            }
            
            // Валидация качества данных
            if ($options['validate_quality'] ?? true) {
                $results['quality_validation'] = $this->cleaningService->validateDataQuality();
            }
            
            return $results;
            
        } catch (Exception $e) {
            $this->log('ERROR', 'Ошибка трансформации данных', [
                'error' => $e->getMessage(),
                'options' => $options
            ]);
            throw $e;
        }
    }
    
    /**
     * Выполнение загрузки в MDM систему
     * 
     * @param array $options Опции выполнения
     * @return array Результаты загрузки
     */
    public function runLoading(array $options = []): array
    {
        try {
            return $this->loader->loadToMDM($options['filters'] ?? []);
            
        } catch (Exception $e) {
            $this->log('ERROR', 'Ошибка загрузки в MDM', [
                'error' => $e->getMessage(),
                'options' => $options
            ]);
            throw $e;
        }
    }
    
    /**
     * Выполнение постобработки
     * 
     * @param array $options Опции выполнения
     * @return array Результаты постобработки
     */
    public function runPostProcessing(array $options = []): array
    {
        try {
            $results = [];
            
            // Обновление метрик качества данных
            $results['quality_metrics'] = $this->updateDataQualityMetrics();
            
            // Очистка временных данных
            if ($options['cleanup_temp_data'] ?? true) {
                $results['cleanup'] = $this->cleanupTempData();
            }
            
            // Генерация отчетов
            if ($options['generate_reports'] ?? true) {
                $results['reports'] = $this->generateETLReports();
            }
            
            return $results;
            
        } catch (Exception $e) {
            $this->log('ERROR', 'Ошибка постобработки', [
                'error' => $e->getMessage(),
                'options' => $options
            ]);
            throw $e;
        }
    }
    
    /**
     * Получение статуса ETL системы
     * 
     * @return array Статус системы
     */
    public function getSystemStatus(): array
    {
        try {
            $status = [
                'scheduler' => $this->scheduler->getStatus(),
                'loader' => $this->loader->getLoadingStats(),
                'data_quality' => $this->cleaningService->validateDataQuality(),
                'system_health' => $this->checkSystemHealth()
            ];
            
            return $status;
            
        } catch (Exception $e) {
            $this->log('ERROR', 'Ошибка получения статуса системы', [
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }
    
    /**
     * Обработка записей, требующих ручной верификации
     * 
     * @return array Записи для верификации
     */
    public function getPendingVerification(): array
    {
        try {
            return $this->loader->getPendingVerification();
            
        } catch (Exception $e) {
            $this->log('ERROR', 'Ошибка получения записей для верификации', [
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }
    
    /**
     * Подтверждение сопоставления
     * 
     * @param int $mappingId ID маппинга
     * @param bool $approved Подтверждено ли
     * @param string|null $newMasterId Новый Master ID
     */
    public function confirmMapping(int $mappingId, bool $approved, ?string $newMasterId = null): void
    {
        try {
            $this->loader->confirmMapping($mappingId, $approved, $newMasterId);
            
            $this->log('INFO', 'Сопоставление обработано', [
                'mapping_id' => $mappingId,
                'approved' => $approved,
                'new_master_id' => $newMasterId
            ]);
            
        } catch (Exception $e) {
            $this->log('ERROR', 'Ошибка подтверждения сопоставления', [
                'mapping_id' => $mappingId,
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }
    
    /**
     * Обновление метрик качества данных
     * 
     * @return array Результаты обновления метрик
     */
    private function updateDataQualityMetrics(): array
    {
        try {
            $metrics = [];
            
            // Процент покрытия мастер-данными
            $stmt = $this->pdo->query("
                SELECT 
                    COUNT(DISTINCT sm.external_sku) as mapped_products,
                    (SELECT COUNT(DISTINCT external_sku) FROM etl_extracted_data) as total_products
                FROM sku_mapping sm
            ");
            $coverageData = $stmt->fetch(PDO::FETCH_ASSOC);
            
            $coveragePercent = $coverageData['total_products'] > 0 ? 
                round(($coverageData['mapped_products'] / $coverageData['total_products']) * 100, 2) : 0;
            
            $this->saveMetric('master_data_coverage', $coveragePercent, $coverageData['total_products'], $coverageData['mapped_products']);
            $metrics['coverage'] = $coveragePercent;
            
            // Процент автоматического сопоставления
            $stmt = $this->pdo->query("
                SELECT 
                    verification_status,
                    COUNT(*) as count
                FROM sku_mapping 
                GROUP BY verification_status
            ");
            $verificationStats = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            $autoCount = 0;
            $totalCount = 0;
            
            foreach ($verificationStats as $stat) {
                $totalCount += $stat['count'];
                if ($stat['verification_status'] === 'auto') {
                    $autoCount = $stat['count'];
                }
            }
            
            $autoPercent = $totalCount > 0 ? round(($autoCount / $totalCount) * 100, 2) : 0;
            
            $this->saveMetric('auto_matching_accuracy', $autoPercent, $totalCount, $autoCount);
            $metrics['auto_matching'] = $autoPercent;
            
            // Полнота заполнения атрибутов
            $stmt = $this->pdo->query("
                SELECT 
                    COUNT(*) as total_masters,
                    COUNT(CASE WHEN canonical_brand != 'Неизвестный бренд' THEN 1 END) as with_brands,
                    COUNT(CASE WHEN canonical_category != 'Без категории' THEN 1 END) as with_categories,
                    COUNT(CASE WHEN description IS NOT NULL AND description != '' THEN 1 END) as with_descriptions
                FROM master_products
                WHERE status = 'active'
            ");
            $completenessData = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($completenessData['total_masters'] > 0) {
                $brandCompleteness = round(($completenessData['with_brands'] / $completenessData['total_masters']) * 100, 2);
                $categoryCompleteness = round(($completenessData['with_categories'] / $completenessData['total_masters']) * 100, 2);
                $descriptionCompleteness = round(($completenessData['with_descriptions'] / $completenessData['total_masters']) * 100, 2);
                
                $this->saveMetric('brand_completeness', $brandCompleteness, $completenessData['total_masters'], $completenessData['with_brands']);
                $this->saveMetric('category_completeness', $categoryCompleteness, $completenessData['total_masters'], $completenessData['with_categories']);
                $this->saveMetric('description_completeness', $descriptionCompleteness, $completenessData['total_masters'], $completenessData['with_descriptions']);
                
                $metrics['brand_completeness'] = $brandCompleteness;
                $metrics['category_completeness'] = $categoryCompleteness;
                $metrics['description_completeness'] = $descriptionCompleteness;
            }
            
            return $metrics;
            
        } catch (Exception $e) {
            $this->log('ERROR', 'Ошибка обновления метрик качества', [
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }
    
    /**
     * Сохранение метрики качества данных
     * 
     * @param string $metricName Название метрики
     * @param float $metricValue Значение метрики
     * @param int $totalRecords Общее количество записей
     * @param int $goodRecords Количество хороших записей
     */
    private function saveMetric(string $metricName, float $metricValue, int $totalRecords, int $goodRecords): void
    {
        $stmt = $this->pdo->prepare("
            INSERT INTO data_quality_metrics 
            (metric_name, metric_value, total_records, good_records)
            VALUES (?, ?, ?, ?)
        ");
        
        $stmt->execute([$metricName, $metricValue, $totalRecords, $goodRecords]);
    }
    
    /**
     * Очистка временных данных
     * 
     * @return array Результаты очистки
     */
    private function cleanupTempData(): array
    {
        try {
            $results = [];
            
            // Очистка старых логов (старше 30 дней)
            $stmt = $this->pdo->prepare("
                DELETE FROM etl_logs 
                WHERE created_at < DATE_SUB(NOW(), INTERVAL 30 DAY)
            ");
            $stmt->execute();
            $results['logs_cleaned'] = $stmt->rowCount();
            
            // Очистка устаревшего кэша
            $stmt = $this->pdo->prepare("
                DELETE FROM etl_api_cache 
                WHERE expires_at < NOW()
            ");
            $stmt->execute();
            $results['cache_cleaned'] = $stmt->rowCount();
            
            // Очистка успешно обработанных записей из очереди повторов
            $stmt = $this->pdo->prepare("
                DELETE FROM etl_retry_queue 
                WHERE status = 'success' AND created_at < DATE_SUB(NOW(), INTERVAL 7 DAY)
            ");
            $stmt->execute();
            $results['retry_queue_cleaned'] = $stmt->rowCount();
            
            return $results;
            
        } catch (Exception $e) {
            $this->log('ERROR', 'Ошибка очистки временных данных', [
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }
    
    /**
     * Генерация отчетов ETL
     * 
     * @return array Результаты генерации отчетов
     */
    private function generateETLReports(): array
    {
        try {
            $reports = [];
            
            // Отчет о последних запусках ETL
            $stmt = $this->pdo->query("
                SELECT 
                    status,
                    duration,
                    total_extracted,
                    total_saved,
                    created_at
                FROM etl_runs 
                ORDER BY created_at DESC 
                LIMIT 10
            ");
            $reports['recent_runs'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Отчет о качестве данных по источникам
            $stmt = $this->pdo->query("
                SELECT 
                    ed.source,
                    COUNT(*) as total_records,
                    COUNT(sm.id) as mapped_records,
                    ROUND(COUNT(sm.id) * 100.0 / COUNT(*), 2) as mapping_percentage,
                    ROUND(AVG(sm.confidence_score), 2) as avg_confidence
                FROM etl_extracted_data ed
                LEFT JOIN sku_mapping sm ON ed.source = sm.source AND ed.external_sku = sm.external_sku
                GROUP BY ed.source
            ");
            $reports['quality_by_source'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Отчет о топ брендах и категориях
            $stmt = $this->pdo->query("
                SELECT 
                    canonical_brand,
                    COUNT(*) as product_count
                FROM master_products 
                WHERE status = 'active' AND canonical_brand != 'Неизвестный бренд'
                GROUP BY canonical_brand 
                ORDER BY product_count DESC 
                LIMIT 20
            ");
            $reports['top_brands'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            $stmt = $this->pdo->query("
                SELECT 
                    canonical_category,
                    COUNT(*) as product_count
                FROM master_products 
                WHERE status = 'active' AND canonical_category != 'Без категории'
                GROUP BY canonical_category 
                ORDER BY product_count DESC 
                LIMIT 20
            ");
            $reports['top_categories'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            return $reports;
            
        } catch (Exception $e) {
            $this->log('ERROR', 'Ошибка генерации отчетов', [
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }
    
    /**
     * Проверка здоровья системы
     * 
     * @return array Результаты проверки
     */
    private function checkSystemHealth(): array
    {
        try {
            $health = [
                'database' => 'ok',
                'extractors' => 'ok',
                'disk_space' => 'ok',
                'issues' => []
            ];
            
            // Проверка подключения к БД
            try {
                $this->pdo->query('SELECT 1');
            } catch (Exception $e) {
                $health['database'] = 'error';
                $health['issues'][] = 'Database connection failed: ' . $e->getMessage();
            }
            
            // Проверка доступности экстракторов
            $extractorStatus = $this->scheduler->getStatus();
            foreach ($extractorStatus['extractors'] as $name => $status) {
                if (!$status['available']) {
                    $health['extractors'] = 'warning';
                    $health['issues'][] = "Extractor $name is not available";
                }
            }
            
            // Проверка места на диске (если возможно)
            $tempDir = sys_get_temp_dir();
            if (function_exists('disk_free_space')) {
                $freeBytes = disk_free_space($tempDir);
                $totalBytes = disk_total_space($tempDir);
                
                if ($freeBytes && $totalBytes) {
                    $freePercent = ($freeBytes / $totalBytes) * 100;
                    
                    if ($freePercent < 10) {
                        $health['disk_space'] = 'error';
                        $health['issues'][] = 'Low disk space: ' . round($freePercent, 1) . '% free';
                    } elseif ($freePercent < 20) {
                        $health['disk_space'] = 'warning';
                        $health['issues'][] = 'Disk space getting low: ' . round($freePercent, 1) . '% free';
                    }
                }
            }
            
            return $health;
            
        } catch (Exception $e) {
            return [
                'database' => 'error',
                'extractors' => 'unknown',
                'disk_space' => 'unknown',
                'issues' => ['Health check failed: ' . $e->getMessage()]
            ];
        }
    }
    
    /**
     * Логирование операций оркестратора
     * 
     * @param string $level Уровень лога
     * @param string $message Сообщение
     * @param array $context Контекст
     */
    private function log(string $level, string $message, array $context = []): void
    {
        $fullMessage = $message;
        
        if (!empty($context)) {
            $fullMessage .= ' | Context: ' . json_encode($context, JSON_UNESCAPED_UNICODE);
        }
        
        error_log("[ETL Orchestrator] [$level] $fullMessage");
        
        // Сохраняем в БД
        try {
            $stmt = $this->pdo->prepare("
                INSERT INTO etl_logs (source, level, message, context, created_at) 
                VALUES ('orchestrator', ?, ?, ?, NOW())
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