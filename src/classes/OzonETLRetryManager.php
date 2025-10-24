<?php
/**
 * OzonETLRetryManager Class - Система повторных попыток и восстановления для ETL процессов
 * 
 * Обеспечивает автоматические повторные попытки при временных сбоях,
 * graceful degradation при недоступности API и процедуры восстановления
 * после критических ошибок.
 * 
 * @version 1.0
 * @author Manhattan System
 */

class OzonETLRetryManager {
    
    private $pdo;
    private $config;
    private $logger;
    
    // Константы для retry логики
    const DEFAULT_MAX_RETRIES = 3;
    const DEFAULT_BASE_DELAY = 30; // секунды
    const DEFAULT_MAX_DELAY = 300; // 5 минут максимум
    const DEFAULT_BACKOFF_MULTIPLIER = 2;
    
    // Типы ошибок для retry
    const RETRYABLE_ERRORS = [
        'network_timeout',
        'api_rate_limit',
        'temporary_api_error',
        'database_connection_lost',
        'file_download_failed',
        'report_generation_timeout'
    ];
    
    // Критические ошибки, требующие вмешательства
    const CRITICAL_ERRORS = [
        'authentication_failed',
        'invalid_api_credentials',
        'database_schema_error',
        'disk_space_full',
        'memory_exhausted'
    ];
    
    /**
     * Конструктор
     * 
     * @param PDO $pdo - подключение к базе данных
     * @param array $config - конфигурация retry механизмов
     */
    public function __construct(PDO $pdo, array $config = []) {
        $this->pdo = $pdo;
        $this->config = array_merge($this->getDefaultConfig(), $config);
        $this->initializeLogger();
        $this->initializeRetryTables();
    }
    
    /**
     * Получение конфигурации по умолчанию
     */
    private function getDefaultConfig(): array {
        return [
            'max_retries' => self::DEFAULT_MAX_RETRIES,
            'base_delay_seconds' => self::DEFAULT_BASE_DELAY,
            'max_delay_seconds' => self::DEFAULT_MAX_DELAY,
            'backoff_multiplier' => self::DEFAULT_BACKOFF_MULTIPLIER,
            'enable_exponential_backoff' => true,
            'enable_jitter' => true,
            'jitter_max_seconds' => 10,
            'retry_timeout_hours' => 24,
            'enable_graceful_degradation' => true,
            'fallback_data_max_age_hours' => 48,
            'critical_error_notification' => true,
            'auto_recovery_enabled' => true,
            'recovery_check_interval_minutes' => 30
        ];
    }
    
    /**
     * Инициализация логгера
     */
    private function initializeLogger(): void {
        $this->logger = [
            'info' => function($message, $context = []) {
                error_log("[INFO] OzonETLRetryManager: $message " . json_encode($context));
            },
            'warning' => function($message, $context = []) {
                error_log("[WARNING] OzonETLRetryManager: $message " . json_encode($context));
            },
            'error' => function($message, $context = []) {
                error_log("[ERROR] OzonETLRetryManager: $message " . json_encode($context));
            }
        ];
    }
    
    /**
     * Инициализация таблиц для retry механизмов
     */
    private function initializeRetryTables(): void {
        try {
            // Таблица для отслеживания retry попыток
            $this->pdo->exec("
                CREATE TABLE IF NOT EXISTS ozon_etl_retry_attempts (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    etl_id VARCHAR(255) NOT NULL,
                    operation_type ENUM('full_etl', 'report_request', 'report_download', 'data_processing', 'alert_generation') NOT NULL,
                    operation_details JSON NULL,
                    error_type VARCHAR(100) NOT NULL,
                    error_message TEXT NOT NULL,
                    attempt_number INT NOT NULL DEFAULT 1,
                    max_attempts INT NOT NULL DEFAULT 3,
                    next_retry_at TIMESTAMP NULL,
                    retry_delay_seconds INT NOT NULL,
                    is_retryable BOOLEAN NOT NULL DEFAULT TRUE,
                    is_completed BOOLEAN NOT NULL DEFAULT FALSE,
                    completed_at TIMESTAMP NULL,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    
                    INDEX idx_etl_id (etl_id),
                    INDEX idx_operation_type (operation_type),
                    INDEX idx_next_retry_at (next_retry_at),
                    INDEX idx_is_completed (is_completed),
                    INDEX idx_created_at (created_at)
                ) ENGINE=InnoDB
            ");
            
            // Таблица для fallback данных
            $this->pdo->exec("
                CREATE TABLE IF NOT EXISTS ozon_etl_fallback_data (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    data_type ENUM('stock_reports', 'inventory_data', 'alert_data') NOT NULL,
                    data_key VARCHAR(255) NOT NULL,
                    data_content JSON NOT NULL,
                    source_etl_id VARCHAR(255) NULL,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    expires_at TIMESTAMP NULL,
                    usage_count INT DEFAULT 0,
                    last_used_at TIMESTAMP NULL,
                    
                    UNIQUE KEY unique_data_key (data_type, data_key),
                    INDEX idx_data_type (data_type),
                    INDEX idx_expires_at (expires_at),
                    INDEX idx_created_at (created_at)
                ) ENGINE=InnoDB
            ");
            
            // Таблица для recovery процедур
            $this->pdo->exec("
                CREATE TABLE IF NOT EXISTS ozon_etl_recovery_procedures (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    procedure_name VARCHAR(100) NOT NULL,
                    trigger_condition VARCHAR(255) NOT NULL,
                    recovery_steps JSON NOT NULL,
                    is_active BOOLEAN NOT NULL DEFAULT TRUE,
                    success_count INT DEFAULT 0,
                    failure_count INT DEFAULT 0,
                    last_executed_at TIMESTAMP NULL,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    
                    UNIQUE KEY unique_procedure_name (procedure_name),
                    INDEX idx_is_active (is_active),
                    INDEX idx_last_executed_at (last_executed_at)
                ) ENGINE=InnoDB
            ");
            
        } catch (Exception $e) {
            ($this->logger['error'])("Failed to initialize retry tables", [
                'error' => $e->getMessage()
            ]);
        }
    }
    
    /**
     * Выполнение операции с автоматическими повторными попытками
     * 
     * @param callable $operation - операция для выполнения
     * @param string $operationType - тип операции
     * @param string $etlId - идентификатор ETL процесса
     * @param array $operationDetails - детали операции
     * @param array $retryConfig - конфигурация retry для этой операции
     * @return mixed результат операции
     * @throws Exception если все попытки исчерпаны
     */
    public function executeWithRetry(callable $operation, string $operationType, string $etlId, array $operationDetails = [], array $retryConfig = []) {
        $config = array_merge($this->config, $retryConfig);
        $maxAttempts = $config['max_retries'] + 1; // +1 для первой попытки
        
        ($this->logger['info'])("Starting operation with retry", [
            'operation_type' => $operationType,
            'etl_id' => $etlId,
            'max_attempts' => $maxAttempts
        ]);
        
        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            try {
                ($this->logger['info'])("Executing operation attempt $attempt/$maxAttempts", [
                    'operation_type' => $operationType,
                    'etl_id' => $etlId
                ]);
                
                // Выполняем операцию
                $result = $operation();
                
                // Операция успешна - очищаем retry записи если есть
                $this->markRetryCompleted($etlId, $operationType);
                
                ($this->logger['info'])("Operation completed successfully", [
                    'operation_type' => $operationType,
                    'etl_id' => $etlId,
                    'attempt' => $attempt
                ]);
                
                return $result;
                
            } catch (Exception $e) {
                $errorType = $this->classifyError($e);
                $isRetryable = $this->isErrorRetryable($errorType);
                
                ($this->logger['warning'])("Operation failed", [
                    'operation_type' => $operationType,
                    'etl_id' => $etlId,
                    'attempt' => $attempt,
                    'error_type' => $errorType,
                    'error_message' => $e->getMessage(),
                    'is_retryable' => $isRetryable
                ]);
                
                // Сохраняем информацию о попытке
                $this->recordRetryAttempt($etlId, $operationType, $operationDetails, $errorType, $e->getMessage(), $attempt, $maxAttempts);
                
                // Если ошибка не подлежит повтору или это последняя попытка
                if (!$isRetryable || $attempt >= $maxAttempts) {
                    if (in_array($errorType, self::CRITICAL_ERRORS)) {
                        $this->handleCriticalError($etlId, $operationType, $errorType, $e);
                    }
                    
                    // Пытаемся использовать fallback данные если доступны
                    if ($config['enable_graceful_degradation']) {
                        $fallbackResult = $this->tryGracefulDegradation($operationType, $etlId, $operationDetails);
                        if ($fallbackResult !== null) {
                            return $fallbackResult;
                        }
                    }
                    
                    throw $e;
                }
                
                // Рассчитываем задержку перед следующей попыткой
                $delay = $this->calculateRetryDelay($attempt, $config);
                
                ($this->logger['info'])("Retrying operation after delay", [
                    'operation_type' => $operationType,
                    'etl_id' => $etlId,
                    'delay_seconds' => $delay,
                    'next_attempt' => $attempt + 1
                ]);
                
                // Обновляем запись о следующей попытке
                $this->scheduleNextRetry($etlId, $operationType, $delay);
                
                // Ждем перед следующей попыткой
                sleep($delay);
            }
        }
    }
    
    /**
     * Классификация типа ошибки
     */
    private function classifyError(Exception $e): string {
        $message = strtolower($e->getMessage());
        $code = $e->getCode();
        
        // Проверяем по сообщению об ошибке
        if (strpos($message, 'timeout') !== false || strpos($message, 'timed out') !== false) {
            return 'network_timeout';
        }
        
        if (strpos($message, 'rate limit') !== false || $code === 429) {
            return 'api_rate_limit';
        }
        
        if (strpos($message, 'authentication') !== false || strpos($message, 'unauthorized') !== false || $code === 401) {
            return 'authentication_failed';
        }
        
        if (strpos($message, 'forbidden') !== false || $code === 403) {
            return 'invalid_api_credentials';
        }
        
        if (strpos($message, 'connection') !== false && strpos($message, 'database') !== false) {
            return 'database_connection_lost';
        }
        
        if (strpos($message, 'download') !== false && strpos($message, 'failed') !== false) {
            return 'file_download_failed';
        }
        
        if (strpos($message, 'report') !== false && strpos($message, 'timeout') !== false) {
            return 'report_generation_timeout';
        }
        
        if (strpos($message, 'memory') !== false && strpos($message, 'exhausted') !== false) {
            return 'memory_exhausted';
        }
        
        if (strpos($message, 'disk') !== false && strpos($message, 'space') !== false) {
            return 'disk_space_full';
        }
        
        // HTTP коды ошибок
        if ($code >= 500 && $code < 600) {
            return 'temporary_api_error';
        }
        
        if ($code >= 400 && $code < 500) {
            return 'client_error';
        }
        
        // По умолчанию - неизвестная ошибка
        return 'unknown_error';
    }
    
    /**
     * Проверка, подлежит ли ошибка повторной попытке
     */
    private function isErrorRetryable(string $errorType): bool {
        return in_array($errorType, self::RETRYABLE_ERRORS);
    }
    
    /**
     * Расчет задержки перед следующей попыткой
     */
    private function calculateRetryDelay(int $attempt, array $config): int {
        $baseDelay = $config['base_delay_seconds'];
        $maxDelay = $config['max_delay_seconds'];
        
        if ($config['enable_exponential_backoff']) {
            // Экспоненциальная задержка
            $delay = $baseDelay * pow($config['backoff_multiplier'], $attempt - 1);
        } else {
            // Линейная задержка
            $delay = $baseDelay * $attempt;
        }
        
        // Ограничиваем максимальной задержкой
        $delay = min($delay, $maxDelay);
        
        // Добавляем jitter для избежания thundering herd
        if ($config['enable_jitter']) {
            $jitter = rand(0, $config['jitter_max_seconds']);
            $delay += $jitter;
        }
        
        return (int)$delay;
    }
    
    /**
     * Запись попытки retry
     */
    private function recordRetryAttempt(string $etlId, string $operationType, array $operationDetails, string $errorType, string $errorMessage, int $attempt, int $maxAttempts): void {
        try {
            $sql = "INSERT INTO ozon_etl_retry_attempts 
                    (etl_id, operation_type, operation_details, error_type, error_message, 
                     attempt_number, max_attempts, is_retryable)
                    VALUES (:etl_id, :operation_type, :operation_details, :error_type, :error_message,
                            :attempt_number, :max_attempts, :is_retryable)";
            
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute([
                'etl_id' => $etlId,
                'operation_type' => $operationType,
                'operation_details' => json_encode($operationDetails),
                'error_type' => $errorType,
                'error_message' => $errorMessage,
                'attempt_number' => $attempt,
                'max_attempts' => $maxAttempts,
                'is_retryable' => $this->isErrorRetryable($errorType)
            ]);
            
        } catch (Exception $e) {
            ($this->logger['warning'])("Failed to record retry attempt", [
                'error' => $e->getMessage()
            ]);
        }
    }
    
    /**
     * Планирование следующей попытки
     */
    private function scheduleNextRetry(string $etlId, string $operationType, int $delaySeconds): void {
        try {
            $sql = "UPDATE ozon_etl_retry_attempts 
                    SET next_retry_at = DATE_ADD(NOW(), INTERVAL :delay_seconds SECOND),
                        retry_delay_seconds = :delay_seconds
                    WHERE etl_id = :etl_id AND operation_type = :operation_type 
                    AND is_completed = FALSE
                    ORDER BY id DESC LIMIT 1";
            
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute([
                'etl_id' => $etlId,
                'operation_type' => $operationType,
                'delay_seconds' => $delaySeconds
            ]);
            
        } catch (Exception $e) {
            ($this->logger['warning'])("Failed to schedule next retry", [
                'error' => $e->getMessage()
            ]);
        }
    }
    
    /**
     * Пометка retry как завершенного
     */
    private function markRetryCompleted(string $etlId, string $operationType): void {
        try {
            $sql = "UPDATE ozon_etl_retry_attempts 
                    SET is_completed = TRUE, completed_at = NOW()
                    WHERE etl_id = :etl_id AND operation_type = :operation_type 
                    AND is_completed = FALSE";
            
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute([
                'etl_id' => $etlId,
                'operation_type' => $operationType
            ]);
            
        } catch (Exception $e) {
            ($this->logger['warning'])("Failed to mark retry as completed", [
                'error' => $e->getMessage()
            ]);
        }
    }
    
    /**
     * Обработка критических ошибок
     */
    private function handleCriticalError(string $etlId, string $operationType, string $errorType, Exception $e): void {
        ($this->logger['error'])("Critical error detected", [
            'etl_id' => $etlId,
            'operation_type' => $operationType,
            'error_type' => $errorType,
            'error_message' => $e->getMessage()
        ]);
        
        // Сохраняем информацию о критической ошибке
        $this->recordCriticalError($etlId, $operationType, $errorType, $e);
        
        // Отправляем уведомление если включено
        if ($this->config['critical_error_notification']) {
            $this->sendCriticalErrorNotification($etlId, $operationType, $errorType, $e);
        }
        
        // Запускаем процедуру восстановления если доступна
        if ($this->config['auto_recovery_enabled']) {
            $this->triggerRecoveryProcedure($errorType, $etlId, $operationType);
        }
    }
    
    /**
     * Запись критической ошибки
     */
    private function recordCriticalError(string $etlId, string $operationType, string $errorType, Exception $e): void {
        try {
            // Создаем таблицу для критических ошибок если не существует
            $this->pdo->exec("
                CREATE TABLE IF NOT EXISTS ozon_etl_critical_errors (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    etl_id VARCHAR(255) NOT NULL,
                    operation_type VARCHAR(100) NOT NULL,
                    error_type VARCHAR(100) NOT NULL,
                    error_message TEXT NOT NULL,
                    error_trace TEXT NULL,
                    recovery_attempted BOOLEAN DEFAULT FALSE,
                    recovery_successful BOOLEAN NULL,
                    resolved_at TIMESTAMP NULL,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    
                    INDEX idx_etl_id (etl_id),
                    INDEX idx_error_type (error_type),
                    INDEX idx_created_at (created_at),
                    INDEX idx_recovery_attempted (recovery_attempted)
                ) ENGINE=InnoDB
            ");
            
            $sql = "INSERT INTO ozon_etl_critical_errors 
                    (etl_id, operation_type, error_type, error_message, error_trace)
                    VALUES (:etl_id, :operation_type, :error_type, :error_message, :error_trace)";
            
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute([
                'etl_id' => $etlId,
                'operation_type' => $operationType,
                'error_type' => $errorType,
                'error_message' => $e->getMessage(),
                'error_trace' => $e->getTraceAsString()
            ]);
            
        } catch (Exception $ex) {
            ($this->logger['error'])("Failed to record critical error", [
                'error' => $ex->getMessage()
            ]);
        }
    }
    
    /**
     * Отправка уведомления о критической ошибке
     */
    private function sendCriticalErrorNotification(string $etlId, string $operationType, string $errorType, Exception $e): void {
        $notificationData = [
            'severity' => 'CRITICAL',
            'title' => 'Critical ETL Error Detected',
            'message' => "Critical error in ETL process: {$e->getMessage()}",
            'details' => [
                'etl_id' => $etlId,
                'operation_type' => $operationType,
                'error_type' => $errorType,
                'timestamp' => date('Y-m-d H:i:s'),
                'error_file' => $e->getFile(),
                'error_line' => $e->getLine()
            ]
        ];
        
        ($this->logger['error'])("CRITICAL ERROR NOTIFICATION", $notificationData);
        
        // TODO: Implement actual notification sending
        // - Email to administrators
        // - SMS for critical alerts
        // - Webhook to monitoring systems
        // - Integration with PagerDuty, Slack, etc.
    }
    
    /**
     * Попытка graceful degradation
     */
    private function tryGracefulDegradation(string $operationType, string $etlId, array $operationDetails) {
        ($this->logger['info'])("Attempting graceful degradation", [
            'operation_type' => $operationType,
            'etl_id' => $etlId
        ]);
        
        try {
            // Определяем тип fallback данных на основе операции
            $dataType = $this->mapOperationToDataType($operationType);
            if (!$dataType) {
                return null;
            }
            
            // Ищем подходящие fallback данные
            $fallbackData = $this->getFallbackData($dataType, $operationDetails);
            
            if ($fallbackData) {
                ($this->logger['info'])("Using fallback data for graceful degradation", [
                    'operation_type' => $operationType,
                    'etl_id' => $etlId,
                    'fallback_age_hours' => $fallbackData['age_hours']
                ]);
                
                // Обновляем статистику использования
                $this->updateFallbackUsage($fallbackData['id']);
                
                return $fallbackData['data_content'];
            }
            
        } catch (Exception $e) {
            ($this->logger['warning'])("Graceful degradation failed", [
                'operation_type' => $operationType,
                'error' => $e->getMessage()
            ]);
        }
        
        return null;
    }
    
    /**
     * Сопоставление типа операции с типом данных
     */
    private function mapOperationToDataType(string $operationType): ?string {
        $mapping = [
            'full_etl' => 'stock_reports',
            'report_request' => 'stock_reports',
            'report_download' => 'stock_reports',
            'data_processing' => 'inventory_data',
            'alert_generation' => 'alert_data'
        ];
        
        return $mapping[$operationType] ?? null;
    }
    
    /**
     * Получение fallback данных
     */
    private function getFallbackData(string $dataType, array $operationDetails): ?array {
        try {
            $maxAgeHours = $this->config['fallback_data_max_age_hours'];
            
            // Генерируем ключ данных на основе деталей операции
            $dataKey = $this->generateDataKey($operationDetails);
            
            $sql = "SELECT id, data_content, 
                           TIMESTAMPDIFF(HOUR, created_at, NOW()) as age_hours
                    FROM ozon_etl_fallback_data 
                    WHERE data_type = :data_type 
                    AND (data_key = :data_key OR data_key = 'default')
                    AND (expires_at IS NULL OR expires_at > NOW())
                    AND TIMESTAMPDIFF(HOUR, created_at, NOW()) <= :max_age_hours
                    ORDER BY 
                        CASE WHEN data_key = :data_key THEN 0 ELSE 1 END,
                        created_at DESC
                    LIMIT 1";
            
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute([
                'data_type' => $dataType,
                'data_key' => $dataKey,
                'max_age_hours' => $maxAgeHours
            ]);
            
            $result = $stmt->fetch();
            
            if ($result) {
                $result['data_content'] = json_decode($result['data_content'], true);
                return $result;
            }
            
        } catch (Exception $e) {
            ($this->logger['warning'])("Failed to get fallback data", [
                'error' => $e->getMessage()
            ]);
        }
        
        return null;
    }
    
    /**
     * Генерация ключа данных
     */
    private function generateDataKey(array $operationDetails): string {
        if (empty($operationDetails)) {
            return 'default';
        }
        
        // Создаем ключ на основе важных параметров
        $keyParts = [];
        
        if (isset($operationDetails['date_from'])) {
            $keyParts[] = 'from_' . $operationDetails['date_from'];
        }
        
        if (isset($operationDetails['date_to'])) {
            $keyParts[] = 'to_' . $operationDetails['date_to'];
        }
        
        if (isset($operationDetails['warehouse_filter'])) {
            $keyParts[] = 'warehouse_' . $operationDetails['warehouse_filter'];
        }
        
        return !empty($keyParts) ? implode('_', $keyParts) : 'default';
    }
    
    /**
     * Обновление статистики использования fallback данных
     */
    private function updateFallbackUsage(int $fallbackId): void {
        try {
            $sql = "UPDATE ozon_etl_fallback_data 
                    SET usage_count = usage_count + 1, last_used_at = NOW()
                    WHERE id = :id";
            
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute(['id' => $fallbackId]);
            
        } catch (Exception $e) {
            ($this->logger['warning'])("Failed to update fallback usage", [
                'error' => $e->getMessage()
            ]);
        }
    }
    
    /**
     * Сохранение данных для fallback
     */
    public function saveFallbackData(string $dataType, array $data, string $dataKey = 'default', string $sourceEtlId = null, int $expiresInHours = null): bool {
        try {
            $expiresAt = null;
            if ($expiresInHours) {
                $expiresAt = date('Y-m-d H:i:s', time() + ($expiresInHours * 3600));
            }
            
            $sql = "INSERT INTO ozon_etl_fallback_data 
                    (data_type, data_key, data_content, source_etl_id, expires_at)
                    VALUES (:data_type, :data_key, :data_content, :source_etl_id, :expires_at)
                    ON DUPLICATE KEY UPDATE
                    data_content = VALUES(data_content),
                    source_etl_id = VALUES(source_etl_id),
                    expires_at = VALUES(expires_at),
                    created_at = NOW()";
            
            $stmt = $this->pdo->prepare($sql);
            $result = $stmt->execute([
                'data_type' => $dataType,
                'data_key' => $dataKey,
                'data_content' => json_encode($data),
                'source_etl_id' => $sourceEtlId,
                'expires_at' => $expiresAt
            ]);
            
            ($this->logger['info'])("Fallback data saved", [
                'data_type' => $dataType,
                'data_key' => $dataKey,
                'expires_at' => $expiresAt
            ]);
            
            return $result;
            
        } catch (Exception $e) {
            ($this->logger['error'])("Failed to save fallback data", [
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }
    
    /**
     * Запуск процедуры восстановления
     */
    private function triggerRecoveryProcedure(string $errorType, string $etlId, string $operationType): void {
        try {
            ($this->logger['info'])("Triggering recovery procedure", [
                'error_type' => $errorType,
                'etl_id' => $etlId,
                'operation_type' => $operationType
            ]);
            
            // Ищем подходящую процедуру восстановления
            $procedure = $this->findRecoveryProcedure($errorType);
            
            if ($procedure) {
                $this->executeRecoveryProcedure($procedure, $etlId, $operationType);
            } else {
                ($this->logger['warning'])("No recovery procedure found for error type", [
                    'error_type' => $errorType
                ]);
            }
            
        } catch (Exception $e) {
            ($this->logger['error'])("Recovery procedure failed", [
                'error' => $e->getMessage()
            ]);
        }
    }
    
    /**
     * Поиск процедуры восстановления
     */
    private function findRecoveryProcedure(string $errorType): ?array {
        try {
            $sql = "SELECT * FROM ozon_etl_recovery_procedures 
                    WHERE is_active = TRUE 
                    AND (trigger_condition = :error_type OR trigger_condition = 'any_error')
                    ORDER BY 
                        CASE WHEN trigger_condition = :error_type THEN 0 ELSE 1 END,
                        success_count DESC
                    LIMIT 1";
            
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute(['error_type' => $errorType]);
            
            $procedure = $stmt->fetch();
            
            if ($procedure) {
                $procedure['recovery_steps'] = json_decode($procedure['recovery_steps'], true);
                return $procedure;
            }
            
        } catch (Exception $e) {
            ($this->logger['error'])("Failed to find recovery procedure", [
                'error' => $e->getMessage()
            ]);
        }
        
        return null;
    }
    
    /**
     * Выполнение процедуры восстановления
     */
    private function executeRecoveryProcedure(array $procedure, string $etlId, string $operationType): void {
        $procedureName = $procedure['procedure_name'];
        $steps = $procedure['recovery_steps'];
        
        ($this->logger['info'])("Executing recovery procedure", [
            'procedure_name' => $procedureName,
            'steps_count' => count($steps),
            'etl_id' => $etlId
        ]);
        
        try {
            // Обновляем время последнего выполнения
            $this->updateProcedureExecutionTime($procedure['id']);
            
            $success = true;
            
            foreach ($steps as $stepIndex => $step) {
                try {
                    ($this->logger['info'])("Executing recovery step", [
                        'procedure_name' => $procedureName,
                        'step_index' => $stepIndex,
                        'step_type' => $step['type']
                    ]);
                    
                    $this->executeRecoveryStep($step, $etlId, $operationType);
                    
                } catch (Exception $e) {
                    ($this->logger['error'])("Recovery step failed", [
                        'procedure_name' => $procedureName,
                        'step_index' => $stepIndex,
                        'error' => $e->getMessage()
                    ]);
                    
                    $success = false;
                    
                    // Если шаг критичен, прерываем выполнение
                    if ($step['critical'] ?? false) {
                        break;
                    }
                }
            }
            
            // Обновляем статистику процедуры
            $this->updateProcedureStats($procedure['id'], $success);
            
            ($this->logger['info'])("Recovery procedure completed", [
                'procedure_name' => $procedureName,
                'success' => $success
            ]);
            
        } catch (Exception $e) {
            $this->updateProcedureStats($procedure['id'], false);
            
            ($this->logger['error'])("Recovery procedure execution failed", [
                'procedure_name' => $procedureName,
                'error' => $e->getMessage()
            ]);
        }
    }
    
    /**
     * Выполнение шага восстановления
     */
    private function executeRecoveryStep(array $step, string $etlId, string $operationType): void {
        $stepType = $step['type'];
        $stepParams = $step['params'] ?? [];
        
        switch ($stepType) {
            case 'clear_locks':
                $this->clearETLLocks($etlId);
                break;
                
            case 'reset_retry_counters':
                $this->resetRetryCounters($etlId, $operationType);
                break;
                
            case 'cleanup_temp_data':
                $this->cleanupTempData($etlId);
                break;
                
            case 'restart_etl':
                $this->scheduleETLRestart($etlId, $stepParams);
                break;
                
            case 'send_notification':
                $this->sendRecoveryNotification($etlId, $operationType, $stepParams);
                break;
                
            case 'wait':
                $waitSeconds = $stepParams['seconds'] ?? 60;
                sleep($waitSeconds);
                break;
                
            default:
                throw new Exception("Unknown recovery step type: $stepType");
        }
    }
    
    /**
     * Очистка блокировок ETL
     */
    private function clearETLLocks(string $etlId): void {
        // Очищаем файловые блокировки
        $lockFiles = [
            sys_get_temp_dir() . '/ozon_stock_reports_etl.lock',
            sys_get_temp_dir() . '/ozon_etl_monitoring_daemon.pid'
        ];
        
        foreach ($lockFiles as $lockFile) {
            if (file_exists($lockFile)) {
                unlink($lockFile);
                ($this->logger['info'])("Removed lock file", ['file' => $lockFile]);
            }
        }
        
        // Обновляем статус в базе данных
        try {
            $sql = "UPDATE ozon_etl_monitoring 
                    SET status = 'failed', completed_at = NOW(), error_message = 'Cleared by recovery procedure'
                    WHERE etl_id = :etl_id AND status = 'running'";
            
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute(['etl_id' => $etlId]);
            
        } catch (Exception $e) {
            ($this->logger['warning'])("Failed to update ETL status during lock clearing", [
                'error' => $e->getMessage()
            ]);
        }
    }
    
    /**
     * Сброс счетчиков retry
     */
    private function resetRetryCounters(string $etlId, string $operationType): void {
        try {
            $sql = "UPDATE ozon_etl_retry_attempts 
                    SET is_completed = TRUE, completed_at = NOW()
                    WHERE etl_id = :etl_id AND operation_type = :operation_type 
                    AND is_completed = FALSE";
            
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute([
                'etl_id' => $etlId,
                'operation_type' => $operationType
            ]);
            
            ($this->logger['info'])("Reset retry counters", [
                'etl_id' => $etlId,
                'operation_type' => $operationType
            ]);
            
        } catch (Exception $e) {
            ($this->logger['warning'])("Failed to reset retry counters", [
                'error' => $e->getMessage()
            ]);
        }
    }
    
    /**
     * Очистка временных данных
     */
    private function cleanupTempData(string $etlId): void {
        // Очистка временных файлов
        $tempDir = sys_get_temp_dir();
        $pattern = $tempDir . '/ozon_etl_' . $etlId . '_*';
        
        foreach (glob($pattern) as $tempFile) {
            if (is_file($tempFile)) {
                unlink($tempFile);
                ($this->logger['info'])("Removed temp file", ['file' => $tempFile]);
            }
        }
        
        // Очистка старых записей в базе данных
        try {
            $tables = [
                'ozon_etl_retry_attempts',
                'stock_report_logs'
            ];
            
            foreach ($tables as $table) {
                $sql = "DELETE FROM $table WHERE etl_id = :etl_id AND created_at < DATE_SUB(NOW(), INTERVAL 1 DAY)";
                $stmt = $this->pdo->prepare($sql);
                $stmt->execute(['etl_id' => $etlId]);
            }
            
        } catch (Exception $e) {
            ($this->logger['warning'])("Failed to cleanup temp data from database", [
                'error' => $e->getMessage()
            ]);
        }
    }
    
    /**
     * Планирование перезапуска ETL
     */
    private function scheduleETLRestart(string $etlId, array $params): void {
        $delayMinutes = $params['delay_minutes'] ?? 30;
        
        try {
            // Создаем таблицу для планирования перезапусков если не существует
            $this->pdo->exec("
                CREATE TABLE IF NOT EXISTS ozon_etl_restart_schedule (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    original_etl_id VARCHAR(255) NOT NULL,
                    scheduled_at TIMESTAMP NOT NULL,
                    restart_params JSON NULL,
                    is_executed BOOLEAN DEFAULT FALSE,
                    executed_at TIMESTAMP NULL,
                    new_etl_id VARCHAR(255) NULL,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    
                    INDEX idx_scheduled_at (scheduled_at),
                    INDEX idx_is_executed (is_executed)
                ) ENGINE=InnoDB
            ");
            
            $scheduledAt = date('Y-m-d H:i:s', time() + ($delayMinutes * 60));
            
            $sql = "INSERT INTO ozon_etl_restart_schedule 
                    (original_etl_id, scheduled_at, restart_params)
                    VALUES (:etl_id, :scheduled_at, :restart_params)";
            
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute([
                'etl_id' => $etlId,
                'scheduled_at' => $scheduledAt,
                'restart_params' => json_encode($params)
            ]);
            
            ($this->logger['info'])("ETL restart scheduled", [
                'original_etl_id' => $etlId,
                'scheduled_at' => $scheduledAt,
                'delay_minutes' => $delayMinutes
            ]);
            
        } catch (Exception $e) {
            ($this->logger['error'])("Failed to schedule ETL restart", [
                'error' => $e->getMessage()
            ]);
        }
    }
    
    /**
     * Отправка уведомления о восстановлении
     */
    private function sendRecoveryNotification(string $etlId, string $operationType, array $params): void {
        $message = $params['message'] ?? 'Recovery procedure executed';
        $severity = $params['severity'] ?? 'info';
        
        ($this->logger['info'])("Recovery notification", [
            'etl_id' => $etlId,
            'operation_type' => $operationType,
            'message' => $message,
            'severity' => $severity
        ]);
        
        // TODO: Implement actual notification sending
    }
    
    /**
     * Обновление времени выполнения процедуры
     */
    private function updateProcedureExecutionTime(int $procedureId): void {
        try {
            $sql = "UPDATE ozon_etl_recovery_procedures 
                    SET last_executed_at = NOW()
                    WHERE id = :id";
            
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute(['id' => $procedureId]);
            
        } catch (Exception $e) {
            ($this->logger['warning'])("Failed to update procedure execution time", [
                'error' => $e->getMessage()
            ]);
        }
    }
    
    /**
     * Обновление статистики процедуры
     */
    private function updateProcedureStats(int $procedureId, bool $success): void {
        try {
            $field = $success ? 'success_count' : 'failure_count';
            
            $sql = "UPDATE ozon_etl_recovery_procedures 
                    SET $field = $field + 1
                    WHERE id = :id";
            
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute(['id' => $procedureId]);
            
        } catch (Exception $e) {
            ($this->logger['warning'])("Failed to update procedure stats", [
                'error' => $e->getMessage()
            ]);
        }
    }
    
    /**
     * Получение статистики retry механизмов
     */
    public function getRetryStatistics(int $days = 7): array {
        try {
            $sql = "SELECT 
                        operation_type,
                        error_type,
                        COUNT(*) as total_attempts,
                        COUNT(DISTINCT etl_id) as unique_etls,
                        AVG(attempt_number) as avg_attempts,
                        MAX(attempt_number) as max_attempts,
                        SUM(CASE WHEN is_completed = TRUE THEN 1 ELSE 0 END) as successful_retries,
                        AVG(retry_delay_seconds) as avg_delay_seconds
                    FROM ozon_etl_retry_attempts 
                    WHERE created_at >= DATE_SUB(NOW(), INTERVAL :days DAY)
                    GROUP BY operation_type, error_type
                    ORDER BY total_attempts DESC";
            
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute(['days' => $days]);
            
            return $stmt->fetchAll();
            
        } catch (Exception $e) {
            ($this->logger['error'])("Failed to get retry statistics", [
                'error' => $e->getMessage()
            ]);
            return [];
        }
    }
    
    /**
     * Очистка старых записей retry
     */
    public function cleanupOldRetryRecords(int $daysToKeep = 30): int {
        try {
            $sql = "DELETE FROM ozon_etl_retry_attempts 
                    WHERE created_at < DATE_SUB(NOW(), INTERVAL :days DAY)";
            
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute(['days' => $daysToKeep]);
            
            $deletedCount = $stmt->rowCount();
            
            ($this->logger['info'])("Cleaned up old retry records", [
                'deleted_count' => $deletedCount,
                'days_kept' => $daysToKeep
            ]);
            
            return $deletedCount;
            
        } catch (Exception $e) {
            ($this->logger['error'])("Failed to cleanup old retry records", [
                'error' => $e->getMessage()
            ]);
            return 0;
        }
    }
}