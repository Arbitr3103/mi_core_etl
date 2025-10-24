<?php
/**
 * OzonETLMonitor Class - Система мониторинга ETL процессов для Ozon Stock Reports
 * 
 * Обеспечивает мониторинг процессов ETL, обнаружение таймаутов, проверки состояния
 * и генерацию уведомлений о проблемах в работе системы.
 * 
 * @version 1.0
 * @author Manhattan System
 */

class OzonETLMonitor {
    
    private $pdo;
    private $config;
    private $logger;
    
    // Константы для мониторинга
    const MAX_ETL_DURATION_MINUTES = 120; // 2 часа максимум
    const STALE_PROCESS_MINUTES = 180;    // 3 часа для определения зависшего процесса
    const HEALTH_CHECK_INTERVAL = 300;    // 5 минут между проверками
    const ALERT_COOLDOWN_MINUTES = 60;    // 1 час между повторными уведомлениями
    
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
        $this->initializeMonitoringTables();
    }
    
    /**
     * Получение конфигурации по умолчанию
     */
    private function getDefaultConfig(): array {
        return [
            'max_etl_duration_minutes' => self::MAX_ETL_DURATION_MINUTES,
            'stale_process_minutes' => self::STALE_PROCESS_MINUTES,
            'health_check_interval' => self::HEALTH_CHECK_INTERVAL,
            'alert_cooldown_minutes' => self::ALERT_COOLDOWN_MINUTES,
            'enable_notifications' => true,
            'notification_channels' => ['log', 'database'],
            'critical_thresholds' => [
                'max_failed_runs_per_day' => 3,
                'min_success_rate_percent' => 80,
                'max_avg_duration_minutes' => 90
            ]
        ];
    }
    
    /**
     * Инициализация логгера
     */
    private function initializeLogger(): void {
        $this->logger = [
            'info' => function($message, $context = []) {
                error_log("[INFO] OzonETLMonitor: $message " . json_encode($context));
            },
            'warning' => function($message, $context = []) {
                error_log("[WARNING] OzonETLMonitor: $message " . json_encode($context));
            },
            'error' => function($message, $context = []) {
                error_log("[ERROR] OzonETLMonitor: $message " . json_encode($context));
            }
        ];
    }
    
    /**
     * Инициализация таблиц мониторинга
     */
    private function initializeMonitoringTables(): void {
        try {
            // Создаем таблицу для мониторинга процессов если не существует
            $this->pdo->exec("
                CREATE TABLE IF NOT EXISTS ozon_etl_monitoring (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    etl_id VARCHAR(255) NOT NULL,
                    process_type ENUM('scheduler', 'manual', 'retry') DEFAULT 'scheduler',
                    status ENUM('running', 'completed', 'failed', 'timeout', 'stalled') NOT NULL,
                    pid INT NULL,
                    hostname VARCHAR(255) NULL,
                    started_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    last_heartbeat TIMESTAMP NULL,
                    completed_at TIMESTAMP NULL,
                    duration_seconds DECIMAL(10,2) NULL,
                    memory_usage_mb DECIMAL(10,2) NULL,
                    cpu_usage_percent DECIMAL(5,2) NULL,
                    error_message TEXT NULL,
                    metadata JSON NULL,
                    
                    INDEX idx_etl_id (etl_id),
                    INDEX idx_status (status),
                    INDEX idx_started_at (started_at),
                    INDEX idx_last_heartbeat (last_heartbeat)
                ) ENGINE=InnoDB
            ");
            
            // Создаем таблицу для уведомлений
            $this->pdo->exec("
                CREATE TABLE IF NOT EXISTS ozon_etl_alerts (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    alert_type ENUM('timeout', 'stalled', 'failed', 'performance', 'health_check') NOT NULL,
                    severity ENUM('low', 'medium', 'high', 'critical') NOT NULL,
                    title VARCHAR(255) NOT NULL,
                    message TEXT NOT NULL,
                    context JSON NULL,
                    etl_id VARCHAR(255) NULL,
                    is_resolved BOOLEAN DEFAULT FALSE,
                    resolved_at TIMESTAMP NULL,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    notified_at TIMESTAMP NULL,
                    
                    INDEX idx_alert_type (alert_type),
                    INDEX idx_severity (severity),
                    INDEX idx_created_at (created_at),
                    INDEX idx_is_resolved (is_resolved)
                ) ENGINE=InnoDB
            ");
            
        } catch (Exception $e) {
            ($this->logger['error'])("Failed to initialize monitoring tables", [
                'error' => $e->getMessage()
            ]);
        }
    }
    
    /**
     * Регистрация начала ETL процесса
     * 
     * @param string $etlId - идентификатор ETL процесса
     * @param string $processType - тип процесса
     * @param array $metadata - дополнительные метаданные
     * @return bool успешность регистрации
     */
    public function registerETLStart(string $etlId, string $processType = 'scheduler', array $metadata = []): bool {
        try {
            $sql = "INSERT INTO ozon_etl_monitoring 
                    (etl_id, process_type, status, pid, hostname, started_at, last_heartbeat, metadata)
                    VALUES (:etl_id, :process_type, 'running', :pid, :hostname, NOW(), NOW(), :metadata)";
            
            $stmt = $this->pdo->prepare($sql);
            $result = $stmt->execute([
                'etl_id' => $etlId,
                'process_type' => $processType,
                'pid' => getmypid(),
                'hostname' => gethostname(),
                'metadata' => json_encode($metadata)
            ]);
            
            ($this->logger['info'])("ETL process registered for monitoring", [
                'etl_id' => $etlId,
                'process_type' => $processType,
                'pid' => getmypid()
            ]);
            
            return $result;
            
        } catch (Exception $e) {
            ($this->logger['error'])("Failed to register ETL start", [
                'etl_id' => $etlId,
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }
    
    /**
     * Обновление heartbeat для активного процесса
     * 
     * @param string $etlId - идентификатор ETL процесса
     * @param array $systemMetrics - системные метрики
     * @return bool успешность обновления
     */
    public function updateHeartbeat(string $etlId, array $systemMetrics = []): bool {
        try {
            $updateFields = ['last_heartbeat = NOW()'];
            $params = ['etl_id' => $etlId];
            
            // Добавляем системные метрики если доступны
            if (!empty($systemMetrics['memory_usage_mb'])) {
                $updateFields[] = 'memory_usage_mb = :memory_usage_mb';
                $params['memory_usage_mb'] = $systemMetrics['memory_usage_mb'];
            }
            
            if (!empty($systemMetrics['cpu_usage_percent'])) {
                $updateFields[] = 'cpu_usage_percent = :cpu_usage_percent';
                $params['cpu_usage_percent'] = $systemMetrics['cpu_usage_percent'];
            }
            
            $sql = "UPDATE ozon_etl_monitoring SET " . implode(', ', $updateFields) . 
                   " WHERE etl_id = :etl_id AND status = 'running'";
            
            $stmt = $this->pdo->prepare($sql);
            return $stmt->execute($params);
            
        } catch (Exception $e) {
            ($this->logger['error'])("Failed to update heartbeat", [
                'etl_id' => $etlId,
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }
    
    /**
     * Регистрация завершения ETL процесса
     * 
     * @param string $etlId - идентификатор ETL процесса
     * @param string $status - финальный статус
     * @param float $duration - продолжительность в секундах
     * @param string $errorMessage - сообщение об ошибке (если есть)
     * @return bool успешность регистрации
     */
    public function registerETLCompletion(string $etlId, string $status, float $duration = null, string $errorMessage = null): bool {
        try {
            $sql = "UPDATE ozon_etl_monitoring 
                    SET status = :status, completed_at = NOW(), duration_seconds = :duration, error_message = :error_message
                    WHERE etl_id = :etl_id";
            
            $stmt = $this->pdo->prepare($sql);
            $result = $stmt->execute([
                'etl_id' => $etlId,
                'status' => $status,
                'duration' => $duration,
                'error_message' => $errorMessage
            ]);
            
            ($this->logger['info'])("ETL process completion registered", [
                'etl_id' => $etlId,
                'status' => $status,
                'duration' => $duration
            ]);
            
            return $result;
            
        } catch (Exception $e) {
            ($this->logger['error'])("Failed to register ETL completion", [
                'etl_id' => $etlId,
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }
    
    /**
     * Выполнение проверки состояния системы
     * 
     * @return array результаты проверки
     */
    public function performHealthCheck(): array {
        ($this->logger['info'])("Starting ETL health check");
        
        $healthStatus = [
            'timestamp' => date('Y-m-d H:i:s'),
            'overall_status' => 'healthy',
            'checks' => [],
            'alerts' => [],
            'recommendations' => []
        ];
        
        try {
            // Проверка зависших процессов
            $stalledCheck = $this->checkForStalledProcesses();
            $healthStatus['checks']['stalled_processes'] = $stalledCheck;
            
            // Проверка процессов с таймаутом
            $timeoutCheck = $this->checkForTimeoutProcesses();
            $healthStatus['checks']['timeout_processes'] = $timeoutCheck;
            
            // Проверка производительности
            $performanceCheck = $this->checkPerformanceMetrics();
            $healthStatus['checks']['performance'] = $performanceCheck;
            
            // Проверка частоты сбоев
            $failureRateCheck = $this->checkFailureRate();
            $healthStatus['checks']['failure_rate'] = $failureRateCheck;
            
            // Проверка доступности API
            $apiCheck = $this->checkAPIAvailability();
            $healthStatus['checks']['api_availability'] = $apiCheck;
            
            // Проверка состояния базы данных
            $dbCheck = $this->checkDatabaseHealth();
            $healthStatus['checks']['database'] = $dbCheck;
            
            // Определяем общий статус
            $healthStatus['overall_status'] = $this->determineOverallHealth($healthStatus['checks']);
            
            // Генерируем рекомендации
            $healthStatus['recommendations'] = $this->generateRecommendations($healthStatus['checks']);
            
            // Сохраняем результаты проверки
            $this->saveHealthCheckResults($healthStatus);
            
            ($this->logger['info'])("Health check completed", [
                'overall_status' => $healthStatus['overall_status'],
                'checks_count' => count($healthStatus['checks'])
            ]);
            
        } catch (Exception $e) {
            $healthStatus['overall_status'] = 'error';
            $healthStatus['error'] = $e->getMessage();
            
            ($this->logger['error'])("Health check failed", [
                'error' => $e->getMessage()
            ]);
        }
        
        return $healthStatus;
    }
    
    /**
     * Проверка зависших процессов
     */
    private function checkForStalledProcesses(): array {
        $check = [
            'name' => 'Stalled Processes Check',
            'status' => 'pass',
            'details' => [],
            'stalled_count' => 0
        ];
        
        try {
            $sql = "SELECT etl_id, started_at, last_heartbeat, TIMESTAMPDIFF(MINUTE, last_heartbeat, NOW()) as minutes_since_heartbeat
                    FROM ozon_etl_monitoring 
                    WHERE status = 'running' 
                    AND TIMESTAMPDIFF(MINUTE, last_heartbeat, NOW()) > :stale_minutes";
            
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute(['stale_minutes' => $this->config['stale_process_minutes']]);
            
            $stalledProcesses = $stmt->fetchAll();
            
            if (!empty($stalledProcesses)) {
                $check['status'] = 'fail';
                $check['stalled_count'] = count($stalledProcesses);
                $check['details'] = $stalledProcesses;
                
                // Создаем уведомления для зависших процессов
                foreach ($stalledProcesses as $process) {
                    $this->createAlert('stalled', 'high', 
                        'ETL Process Stalled', 
                        "ETL process {$process['etl_id']} has been stalled for {$process['minutes_since_heartbeat']} minutes",
                        ['etl_id' => $process['etl_id'], 'stalled_minutes' => $process['minutes_since_heartbeat']],
                        $process['etl_id']
                    );
                    
                    // Помечаем процесс как зависший
                    $this->markProcessAsStalled($process['etl_id']);
                }
            }
            
        } catch (Exception $e) {
            $check['status'] = 'error';
            $check['error'] = $e->getMessage();
        }
        
        return $check;
    }
    
    /**
     * Проверка процессов с превышением времени выполнения
     */
    private function checkForTimeoutProcesses(): array {
        $check = [
            'name' => 'Timeout Processes Check',
            'status' => 'pass',
            'details' => [],
            'timeout_count' => 0
        ];
        
        try {
            $sql = "SELECT etl_id, started_at, TIMESTAMPDIFF(MINUTE, started_at, NOW()) as running_minutes
                    FROM ozon_etl_monitoring 
                    WHERE status = 'running' 
                    AND TIMESTAMPDIFF(MINUTE, started_at, NOW()) > :max_duration";
            
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute(['max_duration' => $this->config['max_etl_duration_minutes']]);
            
            $timeoutProcesses = $stmt->fetchAll();
            
            if (!empty($timeoutProcesses)) {
                $check['status'] = 'fail';
                $check['timeout_count'] = count($timeoutProcesses);
                $check['details'] = $timeoutProcesses;
                
                // Создаем уведомления для процессов с таймаутом
                foreach ($timeoutProcesses as $process) {
                    $this->createAlert('timeout', 'critical', 
                        'ETL Process Timeout', 
                        "ETL process {$process['etl_id']} has been running for {$process['running_minutes']} minutes (max: {$this->config['max_etl_duration_minutes']})",
                        ['etl_id' => $process['etl_id'], 'running_minutes' => $process['running_minutes']],
                        $process['etl_id']
                    );
                    
                    // Помечаем процесс как превысивший время
                    $this->markProcessAsTimeout($process['etl_id']);
                }
            }
            
        } catch (Exception $e) {
            $check['status'] = 'error';
            $check['error'] = $e->getMessage();
        }
        
        return $check;
    }
    
    /**
     * Проверка метрик производительности
     */
    private function checkPerformanceMetrics(): array {
        $check = [
            'name' => 'Performance Metrics Check',
            'status' => 'pass',
            'metrics' => [],
            'issues' => []
        ];
        
        try {
            // Получаем статистику за последние 7 дней
            $sql = "SELECT 
                        COUNT(*) as total_runs,
                        AVG(duration_seconds) as avg_duration,
                        MAX(duration_seconds) as max_duration,
                        SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as successful_runs,
                        AVG(memory_usage_mb) as avg_memory_usage,
                        MAX(memory_usage_mb) as max_memory_usage
                    FROM ozon_etl_monitoring 
                    WHERE started_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
                    AND status IN ('completed', 'failed', 'timeout')";
            
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute();
            
            $metrics = $stmt->fetch();
            $check['metrics'] = $metrics;
            
            if ($metrics['total_runs'] > 0) {
                $successRate = ($metrics['successful_runs'] / $metrics['total_runs']) * 100;
                $avgDurationMinutes = $metrics['avg_duration'] / 60;
                
                $check['metrics']['success_rate_percent'] = round($successRate, 2);
                $check['metrics']['avg_duration_minutes'] = round($avgDurationMinutes, 2);
                
                // Проверяем пороговые значения
                if ($successRate < $this->config['critical_thresholds']['min_success_rate_percent']) {
                    $check['status'] = 'fail';
                    $check['issues'][] = "Low success rate: {$successRate}% (threshold: {$this->config['critical_thresholds']['min_success_rate_percent']}%)";
                }
                
                if ($avgDurationMinutes > $this->config['critical_thresholds']['max_avg_duration_minutes']) {
                    $check['status'] = 'fail';
                    $check['issues'][] = "High average duration: {$avgDurationMinutes} minutes (threshold: {$this->config['critical_thresholds']['max_avg_duration_minutes']} minutes)";
                }
                
                // Создаем уведомление о проблемах производительности
                if (!empty($check['issues'])) {
                    $this->createAlert('performance', 'medium', 
                        'Performance Issues Detected', 
                        implode('; ', $check['issues']),
                        $check['metrics']
                    );
                }
            }
            
        } catch (Exception $e) {
            $check['status'] = 'error';
            $check['error'] = $e->getMessage();
        }
        
        return $check;
    }
    
    /**
     * Проверка частоты сбоев
     */
    private function checkFailureRate(): array {
        $check = [
            'name' => 'Failure Rate Check',
            'status' => 'pass',
            'failed_today' => 0,
            'threshold' => $this->config['critical_thresholds']['max_failed_runs_per_day']
        ];
        
        try {
            $sql = "SELECT COUNT(*) as failed_count
                    FROM ozon_etl_monitoring 
                    WHERE DATE(started_at) = CURDATE()
                    AND status IN ('failed', 'timeout', 'stalled')";
            
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute();
            
            $failedCount = $stmt->fetchColumn();
            $check['failed_today'] = $failedCount;
            
            if ($failedCount >= $this->config['critical_thresholds']['max_failed_runs_per_day']) {
                $check['status'] = 'fail';
                
                $this->createAlert('failed', 'high', 
                    'High Failure Rate', 
                    "Too many failed ETL runs today: {$failedCount} (threshold: {$check['threshold']})",
                    ['failed_count' => $failedCount, 'threshold' => $check['threshold']]
                );
            }
            
        } catch (Exception $e) {
            $check['status'] = 'error';
            $check['error'] = $e->getMessage();
        }
        
        return $check;
    }
    
    /**
     * Проверка доступности Ozon API
     */
    private function checkAPIAvailability(): array {
        $check = [
            'name' => 'Ozon API Availability Check',
            'status' => 'pass',
            'response_time_ms' => null,
            'last_error' => null
        ];
        
        try {
            // Простая проверка доступности API (можно расширить)
            $startTime = microtime(true);
            
            // Здесь должна быть реальная проверка API
            // Для примера используем простую проверку подключения
            $context = stream_context_create([
                'http' => [
                    'timeout' => 10,
                    'method' => 'HEAD'
                ]
            ]);
            
            $result = @file_get_contents('https://api-seller.ozon.ru/health', false, $context);
            $responseTime = (microtime(true) - $startTime) * 1000;
            
            $check['response_time_ms'] = round($responseTime, 2);
            
            if ($result === false) {
                $check['status'] = 'fail';
                $check['last_error'] = 'API endpoint not accessible';
                
                $this->createAlert('health_check', 'medium', 
                    'Ozon API Unavailable', 
                    'Ozon API health check failed - API may be unavailable',
                    ['response_time_ms' => $check['response_time_ms']]
                );
            }
            
        } catch (Exception $e) {
            $check['status'] = 'error';
            $check['error'] = $e->getMessage();
        }
        
        return $check;
    }
    
    /**
     * Проверка состояния базы данных
     */
    private function checkDatabaseHealth(): array {
        $check = [
            'name' => 'Database Health Check',
            'status' => 'pass',
            'connection_time_ms' => null,
            'table_sizes' => []
        ];
        
        try {
            $startTime = microtime(true);
            
            // Проверяем подключение
            $this->pdo->query('SELECT 1');
            $connectionTime = (microtime(true) - $startTime) * 1000;
            $check['connection_time_ms'] = round($connectionTime, 2);
            
            // Проверяем размеры ключевых таблиц
            $tables = ['ozon_stock_reports', 'ozon_etl_history', 'ozon_etl_monitoring', 'inventory'];
            
            foreach ($tables as $table) {
                try {
                    $stmt = $this->pdo->prepare("SELECT COUNT(*) FROM $table");
                    $stmt->execute();
                    $check['table_sizes'][$table] = $stmt->fetchColumn();
                } catch (Exception $e) {
                    $check['table_sizes'][$table] = 'error: ' . $e->getMessage();
                }
            }
            
            // Проверяем медленные запросы (если доступно)
            try {
                $stmt = $this->pdo->query("SHOW VARIABLES LIKE 'slow_query_log'");
                $slowLogStatus = $stmt->fetch();
                $check['slow_query_log_enabled'] = $slowLogStatus['Value'] ?? 'unknown';
            } catch (Exception $e) {
                // Игнорируем если нет доступа к системным переменным
            }
            
        } catch (Exception $e) {
            $check['status'] = 'error';
            $check['error'] = $e->getMessage();
            
            $this->createAlert('health_check', 'critical', 
                'Database Health Check Failed', 
                'Database health check failed: ' . $e->getMessage(),
                ['error' => $e->getMessage()]
            );
        }
        
        return $check;
    }
    
    /**
     * Определение общего состояния системы
     */
    private function determineOverallHealth(array $checks): string {
        $hasError = false;
        $hasFail = false;
        
        foreach ($checks as $check) {
            if ($check['status'] === 'error') {
                $hasError = true;
            } elseif ($check['status'] === 'fail') {
                $hasFail = true;
            }
        }
        
        if ($hasError) {
            return 'error';
        } elseif ($hasFail) {
            return 'warning';
        } else {
            return 'healthy';
        }
    }
    
    /**
     * Генерация рекомендаций на основе результатов проверок
     */
    private function generateRecommendations(array $checks): array {
        $recommendations = [];
        
        // Рекомендации по зависшим процессам
        if (!empty($checks['stalled_processes']['stalled_count'])) {
            $recommendations[] = "Consider investigating stalled processes and implementing automatic cleanup";
        }
        
        // Рекомендации по производительности
        if (!empty($checks['performance']['issues'])) {
            $recommendations[] = "Review ETL performance and consider optimization";
            $recommendations[] = "Check system resources (CPU, memory, disk I/O)";
        }
        
        // Рекомендации по частоте сбоев
        if ($checks['failure_rate']['status'] === 'fail') {
            $recommendations[] = "Investigate root causes of ETL failures";
            $recommendations[] = "Consider implementing additional error handling and retry logic";
        }
        
        // Рекомендации по API
        if ($checks['api_availability']['status'] !== 'pass') {
            $recommendations[] = "Check Ozon API status and network connectivity";
            $recommendations[] = "Consider implementing API fallback mechanisms";
        }
        
        return $recommendations;
    }
    
    /**
     * Создание уведомления
     */
    private function createAlert(string $type, string $severity, string $title, string $message, array $context = [], string $etlId = null): void {
        try {
            // Проверяем, не было ли недавно такого же уведомления (cooldown)
            if ($this->isAlertInCooldown($type, $etlId)) {
                return;
            }
            
            $sql = "INSERT INTO ozon_etl_alerts 
                    (alert_type, severity, title, message, context, etl_id, created_at)
                    VALUES (:alert_type, :severity, :title, :message, :context, :etl_id, NOW())";
            
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute([
                'alert_type' => $type,
                'severity' => $severity,
                'title' => $title,
                'message' => $message,
                'context' => json_encode($context),
                'etl_id' => $etlId
            ]);
            
            ($this->logger['warning'])("Alert created", [
                'type' => $type,
                'severity' => $severity,
                'title' => $title,
                'etl_id' => $etlId
            ]);
            
            // Отправляем уведомление если включено
            if ($this->config['enable_notifications']) {
                $this->sendAlert($type, $severity, $title, $message, $context);
            }
            
        } catch (Exception $e) {
            ($this->logger['error'])("Failed to create alert", [
                'error' => $e->getMessage(),
                'alert_type' => $type
            ]);
        }
    }
    
    /**
     * Проверка cooldown для уведомлений
     */
    private function isAlertInCooldown(string $type, string $etlId = null): bool {
        try {
            $sql = "SELECT COUNT(*) FROM ozon_etl_alerts 
                    WHERE alert_type = :alert_type 
                    AND created_at > DATE_SUB(NOW(), INTERVAL :cooldown_minutes MINUTE)";
            
            $params = [
                'alert_type' => $type,
                'cooldown_minutes' => $this->config['alert_cooldown_minutes']
            ];
            
            if ($etlId) {
                $sql .= " AND etl_id = :etl_id";
                $params['etl_id'] = $etlId;
            }
            
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($params);
            
            return $stmt->fetchColumn() > 0;
            
        } catch (Exception $e) {
            return false; // В случае ошибки разрешаем создание уведомления
        }
    }
    
    /**
     * Отправка уведомления
     */
    private function sendAlert(string $type, string $severity, string $title, string $message, array $context): void {
        // Базовая реализация - логирование
        // Можно расширить для отправки email, webhook, etc.
        
        $alertData = [
            'type' => $type,
            'severity' => $severity,
            'title' => $title,
            'message' => $message,
            'context' => $context,
            'timestamp' => date('Y-m-d H:i:s')
        ];
        
        ($this->logger['warning'])("ALERT: $title", $alertData);
        
        // TODO: Implement actual notification sending
        // - Email notifications
        // - Webhook notifications
        // - SMS for critical alerts
        // - Integration with monitoring systems (Slack, PagerDuty, etc.)
    }
    
    /**
     * Пометка процесса как зависшего
     */
    private function markProcessAsStalled(string $etlId): void {
        try {
            $sql = "UPDATE ozon_etl_monitoring 
                    SET status = 'stalled', completed_at = NOW() 
                    WHERE etl_id = :etl_id AND status = 'running'";
            
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute(['etl_id' => $etlId]);
            
        } catch (Exception $e) {
            ($this->logger['error'])("Failed to mark process as stalled", [
                'etl_id' => $etlId,
                'error' => $e->getMessage()
            ]);
        }
    }
    
    /**
     * Пометка процесса как превысившего время выполнения
     */
    private function markProcessAsTimeout(string $etlId): void {
        try {
            $sql = "UPDATE ozon_etl_monitoring 
                    SET status = 'timeout', completed_at = NOW() 
                    WHERE etl_id = :etl_id AND status = 'running'";
            
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute(['etl_id' => $etlId]);
            
        } catch (Exception $e) {
            ($this->logger['error'])("Failed to mark process as timeout", [
                'etl_id' => $etlId,
                'error' => $e->getMessage()
            ]);
        }
    }
    
    /**
     * Сохранение результатов проверки состояния
     */
    private function saveHealthCheckResults(array $healthStatus): void {
        try {
            // Создаем таблицу для истории проверок если не существует
            $this->pdo->exec("
                CREATE TABLE IF NOT EXISTS ozon_etl_health_checks (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    overall_status ENUM('healthy', 'warning', 'error') NOT NULL,
                    checks_data JSON NOT NULL,
                    recommendations JSON NULL,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    
                    INDEX idx_overall_status (overall_status),
                    INDEX idx_created_at (created_at)
                ) ENGINE=InnoDB
            ");
            
            $sql = "INSERT INTO ozon_etl_health_checks 
                    (overall_status, checks_data, recommendations, created_at)
                    VALUES (:overall_status, :checks_data, :recommendations, NOW())";
            
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute([
                'overall_status' => $healthStatus['overall_status'],
                'checks_data' => json_encode($healthStatus['checks']),
                'recommendations' => json_encode($healthStatus['recommendations'])
            ]);
            
        } catch (Exception $e) {
            ($this->logger['error'])("Failed to save health check results", [
                'error' => $e->getMessage()
            ]);
        }
    }
    
    /**
     * Получение статуса мониторинга
     * 
     * @return array текущий статус системы мониторинга
     */
    public function getMonitoringStatus(): array {
        try {
            $status = [
                'timestamp' => date('Y-m-d H:i:s'),
                'running_processes' => 0,
                'recent_alerts' => 0,
                'last_health_check' => null,
                'system_metrics' => []
            ];
            
            // Количество запущенных процессов
            $stmt = $this->pdo->query("SELECT COUNT(*) FROM ozon_etl_monitoring WHERE status = 'running'");
            $status['running_processes'] = $stmt->fetchColumn();
            
            // Количество недавних уведомлений
            $stmt = $this->pdo->query("SELECT COUNT(*) FROM ozon_etl_alerts WHERE created_at > DATE_SUB(NOW(), INTERVAL 24 HOUR)");
            $status['recent_alerts'] = $stmt->fetchColumn();
            
            // Последняя проверка состояния
            $stmt = $this->pdo->query("SELECT created_at, overall_status FROM ozon_etl_health_checks ORDER BY created_at DESC LIMIT 1");
            $lastCheck = $stmt->fetch();
            if ($lastCheck) {
                $status['last_health_check'] = $lastCheck;
            }
            
            // Системные метрики
            $status['system_metrics'] = $this->getSystemMetrics();
            
            return $status;
            
        } catch (Exception $e) {
            return [
                'timestamp' => date('Y-m-d H:i:s'),
                'error' => $e->getMessage()
            ];
        }
    }
    
    /**
     * Получение системных метрик
     */
    private function getSystemMetrics(): array {
        $metrics = [];
        
        try {
            // Использование памяти
            $metrics['memory_usage_mb'] = round(memory_get_usage(true) / 1024 / 1024, 2);
            $metrics['memory_peak_mb'] = round(memory_get_peak_usage(true) / 1024 / 1024, 2);
            
            // Время работы скрипта
            if (defined('REQUEST_TIME_FLOAT')) {
                $metrics['script_runtime_seconds'] = round(microtime(true) - REQUEST_TIME_FLOAT, 2);
            }
            
            // Загрузка системы (если доступно)
            if (function_exists('sys_getloadavg')) {
                $load = sys_getloadavg();
                $metrics['system_load'] = [
                    '1min' => $load[0],
                    '5min' => $load[1],
                    '15min' => $load[2]
                ];
            }
            
        } catch (Exception $e) {
            $metrics['error'] = $e->getMessage();
        }
        
        return $metrics;
    }
    
    /**
     * Очистка старых записей мониторинга
     * 
     * @param int $daysToKeep количество дней для хранения
     */
    public function cleanupOldRecords(int $daysToKeep = 30): array {
        $result = [
            'cleaned_tables' => [],
            'total_deleted' => 0
        ];
        
        try {
            $tables = [
                'ozon_etl_monitoring' => 'started_at',
                'ozon_etl_alerts' => 'created_at',
                'ozon_etl_health_checks' => 'created_at'
            ];
            
            foreach ($tables as $table => $dateColumn) {
                $sql = "DELETE FROM $table WHERE $dateColumn < DATE_SUB(NOW(), INTERVAL :days DAY)";
                $stmt = $this->pdo->prepare($sql);
                $stmt->execute(['days' => $daysToKeep]);
                
                $deletedCount = $stmt->rowCount();
                $result['cleaned_tables'][$table] = $deletedCount;
                $result['total_deleted'] += $deletedCount;
            }
            
            ($this->logger['info'])("Cleanup completed", $result);
            
        } catch (Exception $e) {
            $result['error'] = $e->getMessage();
            ($this->logger['error'])("Cleanup failed", ['error' => $e->getMessage()]);
        }
        
        return $result;
    }
}