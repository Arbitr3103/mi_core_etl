<?php
/**
 * PerformanceMonitor Class - Система мониторинга производительности ETL процессов
 * 
 * Отслеживает производительность ETL процессов, время выполнения, использование ресурсов,
 * выявляет узкие места и предоставляет рекомендации по оптимизации.
 * 
 * @version 1.0
 * @author Manhattan System
 */

class PerformanceMonitor {
    
    private $pdo;
    private $config;
    private $logger;
    
    // Константы для мониторинга производительности
    const MAX_EXECUTION_TIME_MINUTES = 90;      // Максимальное время выполнения
    const HIGH_MEMORY_USAGE_MB = 512;           // Высокое использование памяти
    const HIGH_CPU_USAGE_PERCENT = 80;          // Высокое использование CPU
    const SLOW_QUERY_THRESHOLD_SECONDS = 5;     // Медленные запросы
    const PERFORMANCE_DEGRADATION_PERCENT = 20; // Деградация производительности
    
    /**
     * Конструктор
     * 
     * @param PDO $pdo - подключение к базе данных
     * @param array $config - конфигурация мониторинга
     */
    public function __construct(PDO $pdo, array $config = []) {
        $this->pdo = $pdo;
        $this->config = array_merge($this->getDefaultConfig(), $config);
        $this->initializeLogger();
        $this->initializePerformanceTables();
    }
    
    /**
     * Получение конфигурации по умолчанию
     */
    private function getDefaultConfig(): array {
        return [
            'max_execution_time_minutes' => self::MAX_EXECUTION_TIME_MINUTES,
            'high_memory_usage_mb' => self::HIGH_MEMORY_USAGE_MB,
            'high_cpu_usage_percent' => self::HIGH_CPU_USAGE_PERCENT,
            'slow_query_threshold_seconds' => self::SLOW_QUERY_THRESHOLD_SECONDS,
            'performance_degradation_percent' => self::PERFORMANCE_DEGRADATION_PERCENT,
            'enable_profiling' => true,
            'enable_resource_monitoring' => true,
            'alert_thresholds' => [
                'execution_time_warning' => 60,    // минуты
                'execution_time_critical' => 90,   // минуты
                'memory_usage_warning' => 256,     // MB
                'memory_usage_critical' => 512,    // MB
                'cpu_usage_warning' => 60,         // процент
                'cpu_usage_critical' => 80         // процент
            ]
        ];
    }
    
    /**
     * Инициализация логгера
     */
    private function initializeLogger(): void {
        $this->logger = [
            'info' => function($message, $context = []) {
                error_log("[INFO] PerformanceMonitor: $message " . json_encode($context));
            },
            'warning' => function($message, $context = []) {
                error_log("[WARNING] PerformanceMonitor: $message " . json_encode($context));
            },
            'error' => function($message, $context = []) {
                error_log("[ERROR] PerformanceMonitor: $message " . json_encode($context));
            }
        ];
    }
    
    /**
     * Инициализация таблиц для мониторинга производительности
     */
    private function initializePerformanceTables(): void {
        try {
            // Таблица для детальных метрик производительности
            $this->pdo->exec("
                CREATE TABLE IF NOT EXISTS performance_metrics (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    etl_id VARCHAR(255) NOT NULL,
                    metric_timestamp TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    execution_phase ENUM('initialization', 'data_extraction', 'data_processing', 'data_loading', 'cleanup') NOT NULL,
                    duration_seconds DECIMAL(10,3) NOT NULL,
                    memory_usage_mb DECIMAL(10,2) NULL,
                    cpu_usage_percent DECIMAL(5,2) NULL,
                    disk_io_mb DECIMAL(10,2) NULL,
                    network_io_mb DECIMAL(10,2) NULL,
                    records_processed INT NULL,
                    records_per_second DECIMAL(10,2) NULL,
                    query_count INT NULL,
                    slow_query_count INT NULL,
                    error_count INT NULL,
                    metadata JSON NULL,
                    
                    INDEX idx_etl_id (etl_id),
                    INDEX idx_metric_timestamp (metric_timestamp),
                    INDEX idx_execution_phase (execution_phase),
                    INDEX idx_duration (duration_seconds)
                ) ENGINE=InnoDB
            ");
            
            // Таблица для профилирования запросов
            $this->pdo->exec("
                CREATE TABLE IF NOT EXISTS query_performance_log (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    etl_id VARCHAR(255) NOT NULL,
                    query_hash VARCHAR(64) NOT NULL,
                    query_type ENUM('SELECT', 'INSERT', 'UPDATE', 'DELETE', 'OTHER') NOT NULL,
                    execution_time_seconds DECIMAL(10,6) NOT NULL,
                    rows_examined INT NULL,
                    rows_affected INT NULL,
                    memory_used_mb DECIMAL(10,2) NULL,
                    query_text TEXT NULL,
                    execution_plan JSON NULL,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    
                    INDEX idx_etl_id (etl_id),
                    INDEX idx_query_hash (query_hash),
                    INDEX idx_execution_time (execution_time_seconds),
                    INDEX idx_created_at (created_at)
                ) ENGINE=InnoDB
            ");
            
            // Таблица для уведомлений о производительности
            $this->pdo->exec("
                CREATE TABLE IF NOT EXISTS performance_alerts (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    alert_type ENUM('execution_time', 'memory_usage', 'cpu_usage', 'query_performance', 'resource_bottleneck') NOT NULL,
                    severity ENUM('warning', 'critical') NOT NULL,
                    etl_id VARCHAR(255) NULL,
                    metric_value DECIMAL(10,2) NOT NULL,
                    threshold_value DECIMAL(10,2) NOT NULL,
                    alert_message TEXT NOT NULL,
                    alert_details JSON NULL,
                    is_resolved BOOLEAN DEFAULT FALSE,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    resolved_at TIMESTAMP NULL,
                    
                    INDEX idx_alert_type (alert_type),
                    INDEX idx_severity (severity),
                    INDEX idx_etl_id (etl_id),
                    INDEX idx_created_at (created_at),
                    INDEX idx_is_resolved (is_resolved)
                ) ENGINE=InnoDB
            ");
            
            // Таблица для рекомендаций по оптимизации
            $this->pdo->exec("
                CREATE TABLE IF NOT EXISTS optimization_recommendations (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    recommendation_type ENUM('query_optimization', 'resource_allocation', 'process_optimization', 'infrastructure') NOT NULL,
                    priority ENUM('low', 'medium', 'high', 'critical') NOT NULL,
                    title VARCHAR(255) NOT NULL,
                    description TEXT NOT NULL,
                    impact_estimate VARCHAR(255) NULL,
                    implementation_effort ENUM('low', 'medium', 'high') NULL,
                    related_etl_id VARCHAR(255) NULL,
                    status ENUM('pending', 'in_progress', 'completed', 'dismissed') DEFAULT 'pending',
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    
                    INDEX idx_recommendation_type (recommendation_type),
                    INDEX idx_priority (priority),
                    INDEX idx_status (status),
                    INDEX idx_created_at (created_at)
                ) ENGINE=InnoDB
            ");
            
        } catch (Exception $e) {
            ($this->logger['error'])("Failed to initialize performance tables", [
                'error' => $e->getMessage()
            ]);
        }
    }
    
    /**
     * Начало мониторинга производительности для ETL процесса
     * 
     * @param string $etlId - идентификатор ETL процесса
     * @return array данные для отслеживания
     */
    public function startPerformanceMonitoring(string $etlId): array {
        $monitoringData = [
            'etl_id' => $etlId,
            'start_time' => microtime(true),
            'start_memory' => memory_get_usage(true),
            'start_peak_memory' => memory_get_peak_usage(true),
            'phases' => []
        ];
        
        ($this->logger['info'])("Performance monitoring started", [
            'etl_id' => $etlId,
            'start_memory_mb' => round($monitoringData['start_memory'] / 1024 / 1024, 2)
        ]);
        
        return $monitoringData;
    }
    
    /**
     * Запись метрики производительности для фазы выполнения
     * 
     * @param array $monitoringData - данные мониторинга
     * @param string $phase - фаза выполнения
     * @param array $metrics - дополнительные метрики
     */
    public function recordPhaseMetrics(array &$monitoringData, string $phase, array $metrics = []): void {
        $currentTime = microtime(true);
        $currentMemory = memory_get_usage(true);
        $peakMemory = memory_get_peak_usage(true);
        
        $phaseData = [
            'phase' => $phase,
            'start_time' => $monitoringData['phases'][$phase]['start_time'] ?? $currentTime,
            'end_time' => $currentTime,
            'duration' => $currentTime - ($monitoringData['phases'][$phase]['start_time'] ?? $currentTime),
            'memory_usage' => $currentMemory,
            'peak_memory' => $peakMemory,
            'memory_delta' => $currentMemory - $monitoringData['start_memory'],
            'metrics' => $metrics
        ];
        
        $monitoringData['phases'][$phase] = $phaseData;
        
        // Сохранение в базу данных
        $this->savePhaseMetrics($monitoringData['etl_id'], $phase, $phaseData, $metrics);
        
        // Проверка на превышение пороговых значений
        $this->checkPerformanceThresholds($monitoringData['etl_id'], $phase, $phaseData);
    }
    
    /**
     * Завершение мониторинга производительности
     * 
     * @param array $monitoringData - данные мониторинга
     * @return array итоговые метрики
     */
    public function finishPerformanceMonitoring(array $monitoringData): array {
        $endTime = microtime(true);
        $endMemory = memory_get_usage(true);
        $peakMemory = memory_get_peak_usage(true);
        
        $summary = [
            'etl_id' => $monitoringData['etl_id'],
            'total_duration' => $endTime - $monitoringData['start_time'],
            'total_memory_used' => $endMemory - $monitoringData['start_memory'],
            'peak_memory_usage' => $peakMemory,
            'phases_count' => count($monitoringData['phases']),
            'phases' => $monitoringData['phases']
        ];
        
        // Анализ производительности
        $analysis = $this->analyzePerformance($summary);
        $summary['analysis'] = $analysis;
        
        // Генерация рекомендаций
        $recommendations = $this->generateOptimizationRecommendations($summary);
        $summary['recommendations'] = $recommendations;
        
        ($this->logger['info'])("Performance monitoring completed", [
            'etl_id' => $monitoringData['etl_id'],
            'total_duration_minutes' => round($summary['total_duration'] / 60, 2),
            'peak_memory_mb' => round($summary['peak_memory_usage'] / 1024 / 1024, 2)
        ]);
        
        return $summary;
    }
    
    /**
     * Сохранение метрик фазы в базу данных
     */
    private function savePhaseMetrics(string $etlId, string $phase, array $phaseData, array $additionalMetrics): void {
        try {
            $sql = "INSERT INTO performance_metrics 
                    (etl_id, execution_phase, duration_seconds, memory_usage_mb, 
                     records_processed, records_per_second, metadata)
                    VALUES (:etl_id, :phase, :duration, :memory_usage, 
                            :records_processed, :records_per_second, :metadata)";
            
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute([
                'etl_id' => $etlId,
                'phase' => $phase,
                'duration' => $phaseData['duration'],
                'memory_usage' => round($phaseData['memory_usage'] / 1024 / 1024, 2),
                'records_processed' => $additionalMetrics['records_processed'] ?? null,
                'records_per_second' => isset($additionalMetrics['records_processed']) && $phaseData['duration'] > 0 
                    ? round($additionalMetrics['records_processed'] / $phaseData['duration'], 2) : null,
                'metadata' => json_encode(array_merge($phaseData['metrics'], $additionalMetrics))
            ]);
            
        } catch (Exception $e) {
            ($this->logger['error'])("Failed to save phase metrics", [
                'etl_id' => $etlId,
                'phase' => $phase,
                'error' => $e->getMessage()
            ]);
        }
    }
    
    /**
     * Проверка пороговых значений производительности
     */
    private function checkPerformanceThresholds(string $etlId, string $phase, array $phaseData): void {
        $alerts = [];
        
        // Проверка времени выполнения
        $durationMinutes = $phaseData['duration'] / 60;
        if ($durationMinutes > $this->config['alert_thresholds']['execution_time_critical']) {
            $alerts[] = [
                'type' => 'execution_time',
                'severity' => 'critical',
                'value' => $durationMinutes,
                'threshold' => $this->config['alert_thresholds']['execution_time_critical'],
                'message' => "Phase '$phase' execution time exceeded critical threshold"
            ];
        } elseif ($durationMinutes > $this->config['alert_thresholds']['execution_time_warning']) {
            $alerts[] = [
                'type' => 'execution_time',
                'severity' => 'warning',
                'value' => $durationMinutes,
                'threshold' => $this->config['alert_thresholds']['execution_time_warning'],
                'message' => "Phase '$phase' execution time exceeded warning threshold"
            ];
        }
        
        // Проверка использования памяти
        $memoryUsageMB = $phaseData['memory_usage'] / 1024 / 1024;
        if ($memoryUsageMB > $this->config['alert_thresholds']['memory_usage_critical']) {
            $alerts[] = [
                'type' => 'memory_usage',
                'severity' => 'critical',
                'value' => $memoryUsageMB,
                'threshold' => $this->config['alert_thresholds']['memory_usage_critical'],
                'message' => "Phase '$phase' memory usage exceeded critical threshold"
            ];
        } elseif ($memoryUsageMB > $this->config['alert_thresholds']['memory_usage_warning']) {
            $alerts[] = [
                'type' => 'memory_usage',
                'severity' => 'warning',
                'value' => $memoryUsageMB,
                'threshold' => $this->config['alert_thresholds']['memory_usage_warning'],
                'message' => "Phase '$phase' memory usage exceeded warning threshold"
            ];
        }
        
        // Создание уведомлений
        foreach ($alerts as $alert) {
            $this->createPerformanceAlert($etlId, $alert);
        }
    }
    
    /**
     * Создание уведомления о производительности
     */
    private function createPerformanceAlert(string $etlId, array $alert): void {
        try {
            $sql = "INSERT INTO performance_alerts 
                    (alert_type, severity, etl_id, metric_value, threshold_value, alert_message, alert_details)
                    VALUES (:type, :severity, :etl_id, :value, :threshold, :message, :details)";
            
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute([
                'type' => $alert['type'],
                'severity' => $alert['severity'],
                'etl_id' => $etlId,
                'value' => $alert['value'],
                'threshold' => $alert['threshold'],
                'message' => $alert['message'],
                'details' => json_encode($alert)
            ]);
            
            ($this->logger['warning'])("Performance alert created", $alert);
            
        } catch (Exception $e) {
            ($this->logger['error'])("Failed to create performance alert", [
                'error' => $e->getMessage(),
                'alert' => $alert
            ]);
        }
    }
    
    /**
     * Анализ производительности
     */
    private function analyzePerformance(array $summary): array {
        $analysis = [
            'overall_rating' => 'good',
            'bottlenecks' => [],
            'efficiency_score' => 0,
            'recommendations_count' => 0
        ];
        
        try {
            $totalDurationMinutes = $summary['total_duration'] / 60;
            $peakMemoryMB = $summary['peak_memory_usage'] / 1024 / 1024;
            
            // Оценка общей производительности
            $performanceScore = 100;
            
            if ($totalDurationMinutes > $this->config['max_execution_time_minutes']) {
                $performanceScore -= 30;
                $analysis['bottlenecks'][] = 'Long execution time';
            }
            
            if ($peakMemoryMB > $this->config['high_memory_usage_mb']) {
                $performanceScore -= 20;
                $analysis['bottlenecks'][] = 'High memory usage';
            }
            
            // Анализ фаз выполнения
            $slowestPhase = null;
            $slowestDuration = 0;
            
            foreach ($summary['phases'] as $phaseName => $phaseData) {
                if ($phaseData['duration'] > $slowestDuration) {
                    $slowestDuration = $phaseData['duration'];
                    $slowestPhase = $phaseName;
                }
                
                // Проверка эффективности фазы
                if ($phaseData['duration'] > 300) { // 5 минут
                    $analysis['bottlenecks'][] = "Slow phase: $phaseName";
                    $performanceScore -= 10;
                }
            }
            
            $analysis['slowest_phase'] = $slowestPhase;
            $analysis['efficiency_score'] = max(0, $performanceScore);
            
            // Определение общего рейтинга
            if ($performanceScore >= 80) {
                $analysis['overall_rating'] = 'excellent';
            } elseif ($performanceScore >= 60) {
                $analysis['overall_rating'] = 'good';
            } elseif ($performanceScore >= 40) {
                $analysis['overall_rating'] = 'fair';
            } else {
                $analysis['overall_rating'] = 'poor';
            }
            
        } catch (Exception $e) {
            $analysis['error'] = $e->getMessage();
        }
        
        return $analysis;
    }
    
    /**
     * Генерация рекомендаций по оптимизации
     */
    private function generateOptimizationRecommendations(array $summary): array {
        $recommendations = [];
        
        try {
            $totalDurationMinutes = $summary['total_duration'] / 60;
            $peakMemoryMB = $summary['peak_memory_usage'] / 1024 / 1024;
            
            // Рекомендации по времени выполнения
            if ($totalDurationMinutes > $this->config['max_execution_time_minutes']) {
                $recommendations[] = [
                    'type' => 'process_optimization',
                    'priority' => 'high',
                    'title' => 'Optimize ETL execution time',
                    'description' => "ETL process took {$totalDurationMinutes} minutes, which exceeds the recommended maximum of {$this->config['max_execution_time_minutes']} minutes",
                    'impact_estimate' => 'Reduce execution time by 20-40%',
                    'implementation_effort' => 'medium'
                ];
            }
            
            // Рекомендации по использованию памяти
            if ($peakMemoryMB > $this->config['high_memory_usage_mb']) {
                $recommendations[] = [
                    'type' => 'resource_allocation',
                    'priority' => 'medium',
                    'title' => 'Optimize memory usage',
                    'description' => "Peak memory usage was {$peakMemoryMB}MB, consider implementing batch processing or memory optimization",
                    'impact_estimate' => 'Reduce memory usage by 30-50%',
                    'implementation_effort' => 'medium'
                ];
            }
            
            // Анализ фаз для специфических рекомендаций
            foreach ($summary['phases'] as $phaseName => $phaseData) {
                $phaseDurationMinutes = $phaseData['duration'] / 60;
                
                if ($phaseDurationMinutes > 10) { // Фаза длится более 10 минут
                    $recommendations[] = [
                        'type' => 'process_optimization',
                        'priority' => 'medium',
                        'title' => "Optimize $phaseName phase",
                        'description' => "The $phaseName phase took {$phaseDurationMinutes} minutes, consider optimization",
                        'impact_estimate' => 'Reduce phase time by 15-30%',
                        'implementation_effort' => 'low'
                    ];
                }
            }
            
            // Сохранение рекомендаций в базу данных
            foreach ($recommendations as $recommendation) {
                $this->saveOptimizationRecommendation($summary['etl_id'], $recommendation);
            }
            
        } catch (Exception $e) {
            ($this->logger['error'])("Failed to generate optimization recommendations", [
                'error' => $e->getMessage()
            ]);
        }
        
        return $recommendations;
    }
    
    /**
     * Сохранение рекомендации по оптимизации
     */
    private function saveOptimizationRecommendation(string $etlId, array $recommendation): void {
        try {
            $sql = "INSERT INTO optimization_recommendations 
                    (recommendation_type, priority, title, description, impact_estimate, 
                     implementation_effort, related_etl_id)
                    VALUES (:type, :priority, :title, :description, :impact, :effort, :etl_id)";
            
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute([
                'type' => $recommendation['type'],
                'priority' => $recommendation['priority'],
                'title' => $recommendation['title'],
                'description' => $recommendation['description'],
                'impact' => $recommendation['impact_estimate'],
                'effort' => $recommendation['implementation_effort'],
                'etl_id' => $etlId
            ]);
            
        } catch (Exception $e) {
            ($this->logger['error'])("Failed to save optimization recommendation", [
                'error' => $e->getMessage(),
                'recommendation' => $recommendation
            ]);
        }
    }
    
    /**
     * Получение статистики производительности
     * 
     * @param int $days количество дней для анализа
     * @return array статистика производительности
     */
    public function getPerformanceStatistics(int $days = 30): array {
        try {
            $sql = "SELECT 
                        DATE(metric_timestamp) as date,
                        execution_phase,
                        COUNT(*) as executions_count,
                        AVG(duration_seconds) as avg_duration,
                        MAX(duration_seconds) as max_duration,
                        MIN(duration_seconds) as min_duration,
                        AVG(memory_usage_mb) as avg_memory_usage,
                        MAX(memory_usage_mb) as max_memory_usage,
                        AVG(records_per_second) as avg_throughput
                    FROM performance_metrics
                    WHERE metric_timestamp >= DATE_SUB(NOW(), INTERVAL :days DAY)
                    GROUP BY DATE(metric_timestamp), execution_phase
                    ORDER BY date DESC, execution_phase";
            
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute(['days' => $days]);
            
            $statistics = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Группировка по датам
            $groupedStats = [];
            foreach ($statistics as $stat) {
                $date = $stat['date'];
                if (!isset($groupedStats[$date])) {
                    $groupedStats[$date] = [];
                }
                $groupedStats[$date][$stat['execution_phase']] = $stat;
            }
            
            return [
                'period_days' => $days,
                'daily_statistics' => $groupedStats,
                'summary' => $this->calculatePerformanceSummary($statistics)
            ];
            
        } catch (Exception $e) {
            ($this->logger['error'])("Failed to get performance statistics", [
                'error' => $e->getMessage()
            ]);
            return ['error' => $e->getMessage()];
        }
    }
    
    /**
     * Вычисление сводной статистики производительности
     */
    private function calculatePerformanceSummary(array $statistics): array {
        if (empty($statistics)) {
            return [];
        }
        
        $totalExecutions = array_sum(array_column($statistics, 'executions_count'));
        $avgDuration = array_sum(array_column($statistics, 'avg_duration')) / count($statistics);
        $avgMemoryUsage = array_sum(array_column($statistics, 'avg_memory_usage')) / count($statistics);
        $avgThroughput = array_sum(array_column($statistics, 'avg_throughput')) / count($statistics);
        
        return [
            'total_executions' => $totalExecutions,
            'avg_duration_seconds' => round($avgDuration, 2),
            'avg_duration_minutes' => round($avgDuration / 60, 2),
            'avg_memory_usage_mb' => round($avgMemoryUsage, 2),
            'avg_throughput_records_per_second' => round($avgThroughput, 2)
        ];
    }
    
    /**
     * Получение активных уведомлений о производительности
     * 
     * @param int $limit максимальное количество уведомлений
     * @return array список уведомлений
     */
    public function getActivePerformanceAlerts(int $limit = 50): array {
        try {
            $sql = "SELECT 
                        pa.*,
                        DATEDIFF(NOW(), pa.created_at) as days_active
                    FROM performance_alerts pa
                    WHERE pa.is_resolved = FALSE
                    ORDER BY 
                        CASE pa.severity 
                            WHEN 'critical' THEN 1 
                            WHEN 'warning' THEN 2 
                        END,
                        pa.created_at DESC
                    LIMIT :limit";
            
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute(['limit' => $limit]);
            
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
            
        } catch (Exception $e) {
            ($this->logger['error'])("Failed to get active performance alerts", [
                'error' => $e->getMessage()
            ]);
            return [];
        }
    }
    
    /**
     * Получение рекомендаций по оптимизации
     * 
     * @param string $status статус рекомендаций
     * @param int $limit максимальное количество рекомендаций
     * @return array список рекомендаций
     */
    public function getOptimizationRecommendations(string $status = 'pending', int $limit = 20): array {
        try {
            $sql = "SELECT *
                    FROM optimization_recommendations
                    WHERE status = :status
                    ORDER BY 
                        CASE priority 
                            WHEN 'critical' THEN 1 
                            WHEN 'high' THEN 2 
                            WHEN 'medium' THEN 3 
                            WHEN 'low' THEN 4 
                        END,
                        created_at DESC
                    LIMIT :limit";
            
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute([
                'status' => $status,
                'limit' => $limit
            ]);
            
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
            
        } catch (Exception $e) {
            ($this->logger['error'])("Failed to get optimization recommendations", [
                'error' => $e->getMessage()
            ]);
            return [];
        }
    }
    
    /**
     * Выполнение полного анализа производительности системы
     * 
     * @return array результаты анализа
     */
    public function performComprehensivePerformanceAnalysis(): array {
        ($this->logger['info'])("Starting comprehensive performance analysis");
        
        $analysis = [
            'timestamp' => date('Y-m-d H:i:s'),
            'statistics' => $this->getPerformanceStatistics(30),
            'active_alerts' => $this->getActivePerformanceAlerts(),
            'recommendations' => $this->getOptimizationRecommendations(),
            'capacity_analysis' => $this->analyzeCapacityPlanning(),
            'trend_analysis' => $this->analyzePerformanceTrends()
        ];
        
        ($this->logger['info'])("Comprehensive performance analysis completed", [
            'alerts_count' => count($analysis['active_alerts']),
            'recommendations_count' => count($analysis['recommendations'])
        ]);
        
        return $analysis;
    }
    
    /**
     * Анализ планирования мощностей
     */
    private function analyzeCapacityPlanning(): array {
        try {
            // Анализ трендов использования ресурсов
            $sql = "SELECT 
                        DATE(metric_timestamp) as date,
                        AVG(memory_usage_mb) as avg_memory,
                        MAX(memory_usage_mb) as peak_memory,
                        AVG(duration_seconds) as avg_duration,
                        COUNT(*) as executions_count
                    FROM performance_metrics
                    WHERE metric_timestamp >= DATE_SUB(NOW(), INTERVAL 90 DAY)
                    GROUP BY DATE(metric_timestamp)
                    ORDER BY date";
            
            $stmt = $this->pdo->query($sql);
            $trends = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Прогнозирование на основе трендов
            $memoryTrend = $this->calculateTrend(array_column($trends, 'avg_memory'));
            $durationTrend = $this->calculateTrend(array_column($trends, 'avg_duration'));
            
            return [
                'current_capacity' => [
                    'avg_memory_usage_mb' => round(array_sum(array_column($trends, 'avg_memory')) / count($trends), 2),
                    'peak_memory_usage_mb' => max(array_column($trends, 'peak_memory')),
                    'avg_execution_time_minutes' => round(array_sum(array_column($trends, 'avg_duration')) / count($trends) / 60, 2)
                ],
                'trends' => [
                    'memory_trend' => $memoryTrend > 0 ? 'increasing' : ($memoryTrend < 0 ? 'decreasing' : 'stable'),
                    'duration_trend' => $durationTrend > 0 ? 'increasing' : ($durationTrend < 0 ? 'decreasing' : 'stable')
                ],
                'recommendations' => $this->generateCapacityRecommendations($memoryTrend, $durationTrend)
            ];
            
        } catch (Exception $e) {
            return ['error' => $e->getMessage()];
        }
    }
    
    /**
     * Анализ трендов производительности
     */
    private function analyzePerformanceTrends(): array {
        try {
            $sql = "SELECT 
                        WEEK(metric_timestamp) as week,
                        YEAR(metric_timestamp) as year,
                        AVG(duration_seconds) as avg_duration,
                        AVG(memory_usage_mb) as avg_memory,
                        AVG(records_per_second) as avg_throughput
                    FROM performance_metrics
                    WHERE metric_timestamp >= DATE_SUB(NOW(), INTERVAL 12 WEEK)
                    GROUP BY YEAR(metric_timestamp), WEEK(metric_timestamp)
                    ORDER BY year, week";
            
            $stmt = $this->pdo->query($sql);
            $weeklyTrends = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            return [
                'weekly_trends' => $weeklyTrends,
                'performance_degradation' => $this->detectPerformanceDegradation($weeklyTrends)
            ];
            
        } catch (Exception $e) {
            return ['error' => $e->getMessage()];
        }
    }
    
    /**
     * Вычисление тренда
     */
    private function calculateTrend(array $values): float {
        if (count($values) < 2) {
            return 0;
        }
        
        $n = count($values);
        $sumX = array_sum(range(1, $n));
        $sumY = array_sum($values);
        $sumXY = 0;
        $sumX2 = 0;
        
        for ($i = 0; $i < $n; $i++) {
            $x = $i + 1;
            $y = $values[$i];
            $sumXY += $x * $y;
            $sumX2 += $x * $x;
        }
        
        $slope = ($n * $sumXY - $sumX * $sumY) / ($n * $sumX2 - $sumX * $sumX);
        
        return $slope;
    }
    
    /**
     * Генерация рекомендаций по планированию мощностей
     */
    private function generateCapacityRecommendations(float $memoryTrend, float $durationTrend): array {
        $recommendations = [];
        
        if ($memoryTrend > 5) { // Рост использования памяти более 5MB в день
            $recommendations[] = "Consider increasing memory allocation - memory usage is trending upward";
        }
        
        if ($durationTrend > 10) { // Рост времени выполнения более 10 секунд в день
            $recommendations[] = "Performance is degrading - consider system optimization or scaling";
        }
        
        if ($memoryTrend < -5) {
            $recommendations[] = "Memory usage is decreasing - consider optimizing memory allocation";
        }
        
        return $recommendations;
    }
    
    /**
     * Обнаружение деградации производительности
     */
    private function detectPerformanceDegradation(array $weeklyTrends): array {
        if (count($weeklyTrends) < 4) {
            return ['status' => 'insufficient_data'];
        }
        
        $recent = array_slice($weeklyTrends, -4); // Последние 4 недели
        $baseline = array_slice($weeklyTrends, 0, 4); // Первые 4 недели
        
        $recentAvgDuration = array_sum(array_column($recent, 'avg_duration')) / count($recent);
        $baselineAvgDuration = array_sum(array_column($baseline, 'avg_duration')) / count($baseline);
        
        $degradationPercent = (($recentAvgDuration - $baselineAvgDuration) / $baselineAvgDuration) * 100;
        
        return [
            'status' => $degradationPercent > $this->config['performance_degradation_percent'] ? 'degraded' : 'stable',
            'degradation_percent' => round($degradationPercent, 2),
            'recent_avg_duration' => round($recentAvgDuration, 2),
            'baseline_avg_duration' => round($baselineAvgDuration, 2)
        ];
    }
}