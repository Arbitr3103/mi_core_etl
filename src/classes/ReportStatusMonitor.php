<?php
/**
 * ReportStatusMonitor Class - Мониторинг готовности отчетов с retry логикой
 * 
 * Отслеживает статус генерации отчетов Ozon, обеспечивает ожидание готовности
 * с настраиваемыми интервалами проверки и обработкой таймаутов.
 * 
 * @version 1.0
 * @author Manhattan System
 */

class ReportStatusMonitor {
    
    // Статусы отчетов
    const STATUS_PROCESSING = 'PROCESSING';
    const STATUS_SUCCESS = 'SUCCESS';
    const STATUS_ERROR = 'ERROR';
    const STATUS_TIMEOUT = 'TIMEOUT';
    const STATUS_UNKNOWN = 'UNKNOWN';
    
    // Настройки мониторинга
    const DEFAULT_CHECK_INTERVAL = 300; // 5 минут в секундах
    const MIN_CHECK_INTERVAL = 60;      // Минимум 1 минута
    const MAX_CHECK_INTERVAL = 600;     // Максимум 10 минут
    const DEFAULT_TIMEOUT = 3600;       // 1 час по умолчанию
    
    // Настройки retry логики
    const MAX_CONSECUTIVE_ERRORS = 5;
    const EXPONENTIAL_BACKOFF_BASE = 2;
    const MAX_BACKOFF_DELAY = 300; // 5 минут максимум
    
    private $ozonAPI;
    private $pdo;
    private $reportRequestHandler;
    private $logger;
    
    /**
     * Конструктор класса
     * 
     * @param OzonAnalyticsAPI $ozonAPI - экземпляр API класса Ozon
     * @param PDO $pdo - подключение к базе данных
     */
    public function __construct(OzonAnalyticsAPI $ozonAPI, PDO $pdo) {
        $this->ozonAPI = $ozonAPI;
        $this->pdo = $pdo;
        $this->reportRequestHandler = new ReportRequestHandler($ozonAPI);
        $this->initializeLogger();
    }
    
    /**
     * Инициализация системы логирования
     */
    private function initializeLogger() {
        $this->logger = [
            'info' => function($message, $context = []) {
                error_log("[INFO] ReportStatusMonitor: $message " . json_encode($context));
            },
            'warning' => function($message, $context = []) {
                error_log("[WARNING] ReportStatusMonitor: $message " . json_encode($context));
            },
            'error' => function($message, $context = []) {
                error_log("[ERROR] ReportStatusMonitor: $message " . json_encode($context));
            }
        ];
    }
    
    /**
     * Ожидание готовности отчета с настраиваемым таймаутом
     * 
     * @param string $reportCode - код отчета
     * @param int $timeoutMinutes - таймаут в минутах
     * @param int $checkIntervalSeconds - интервал проверки в секундах
     * @return array результат мониторинга
     * @throws Exception при критических ошибках
     */
    public function waitForReportCompletion(
        string $reportCode, 
        int $timeoutMinutes = 60, 
        int $checkIntervalSeconds = self::DEFAULT_CHECK_INTERVAL
    ): array {
        
        $startTime = time();
        $timeoutSeconds = $timeoutMinutes * 60;
        $checkInterval = $this->validateCheckInterval($checkIntervalSeconds);
        
        ($this->logger['info'])("Starting report completion monitoring", [
            'report_code' => $reportCode,
            'timeout_minutes' => $timeoutMinutes,
            'check_interval_seconds' => $checkInterval,
            'start_time' => date('Y-m-d H:i:s', $startTime)
        ]);
        
        $result = [
            'report_code' => $reportCode,
            'status' => self::STATUS_PROCESSING,
            'start_time' => date('Y-m-d H:i:s', $startTime),
            'end_time' => null,
            'monitoring_duration' => 0,
            'checks_performed' => 0,
            'errors_encountered' => 0,
            'final_report_info' => null,
            'error_message' => null
        ];
        
        $consecutiveErrors = 0;
        $checksPerformed = 0;
        $errorsEncountered = 0;
        
        try {
            while (true) {
                $currentTime = time();
                $elapsedTime = $currentTime - $startTime;
                
                // Проверяем таймаут
                if ($elapsedTime >= $timeoutSeconds) {
                    $result['status'] = self::STATUS_TIMEOUT;
                    $result['error_message'] = "Report monitoring timed out after $timeoutMinutes minutes";
                    
                    ($this->logger['warning'])("Report monitoring timed out", [
                        'report_code' => $reportCode,
                        'elapsed_minutes' => round($elapsedTime / 60, 2),
                        'timeout_minutes' => $timeoutMinutes
                    ]);
                    
                    // Обрабатываем таймаут
                    $this->handleReportTimeout($reportCode);
                    break;
                }
                
                try {
                    // Проверяем статус отчета
                    ($this->logger['info'])("Checking report status", [
                        'report_code' => $reportCode,
                        'check_number' => $checksPerformed + 1,
                        'elapsed_minutes' => round($elapsedTime / 60, 2)
                    ]);
                    
                    $reportInfo = $this->checkReportStatus($reportCode);
                    $checksPerformed++;
                    $consecutiveErrors = 0; // Сбрасываем счетчик ошибок при успешной проверке
                    
                    // Логируем информацию о статусе
                    $this->logReportStatusCheck($reportCode, $reportInfo, $checksPerformed);
                    
                    // Проверяем, завершен ли отчет
                    if ($this->isReportCompleted($reportInfo['status'])) {
                        $result['status'] = $reportInfo['status'];
                        $result['final_report_info'] = $reportInfo;
                        
                        ($this->logger['info'])("Report completed", [
                            'report_code' => $reportCode,
                            'final_status' => $reportInfo['status'],
                            'elapsed_minutes' => round($elapsedTime / 60, 2),
                            'checks_performed' => $checksPerformed
                        ]);
                        
                        break;
                    }
                    
                    // Если отчет еще обрабатывается, ждем следующую проверку
                    if ($reportInfo['status'] === self::STATUS_PROCESSING) {
                        ($this->logger['info'])("Report still processing, waiting for next check", [
                            'report_code' => $reportCode,
                            'next_check_in_seconds' => $checkInterval
                        ]);
                        
                        sleep($checkInterval);
                    }
                    
                } catch (Exception $e) {
                    $consecutiveErrors++;
                    $errorsEncountered++;
                    
                    ($this->logger['warning'])("Error checking report status", [
                        'report_code' => $reportCode,
                        'error' => $e->getMessage(),
                        'consecutive_errors' => $consecutiveErrors,
                        'total_errors' => $errorsEncountered
                    ]);
                    
                    // Если слишком много последовательных ошибок, прерываем мониторинг
                    if ($consecutiveErrors >= self::MAX_CONSECUTIVE_ERRORS) {
                        $result['status'] = self::STATUS_ERROR;
                        $result['error_message'] = "Too many consecutive errors: " . $e->getMessage();
                        
                        ($this->logger['error'])("Too many consecutive errors, stopping monitoring", [
                            'report_code' => $reportCode,
                            'consecutive_errors' => $consecutiveErrors,
                            'last_error' => $e->getMessage()
                        ]);
                        
                        break;
                    }
                    
                    // Применяем экспоненциальную задержку при ошибках
                    $backoffDelay = $this->calculateBackoffDelay($consecutiveErrors);
                    
                    ($this->logger['info'])("Applying exponential backoff", [
                        'report_code' => $reportCode,
                        'backoff_delay_seconds' => $backoffDelay,
                        'consecutive_errors' => $consecutiveErrors
                    ]);
                    
                    sleep($backoffDelay);
                }
            }
            
            // Завершаем мониторинг
            $endTime = time();
            $result['end_time'] = date('Y-m-d H:i:s', $endTime);
            $result['monitoring_duration'] = $endTime - $startTime;
            $result['checks_performed'] = $checksPerformed;
            $result['errors_encountered'] = $errorsEncountered;
            
            // Сохраняем результат мониторинга в базу данных
            $this->saveMonitoringResult($result);
            
            ($this->logger['info'])("Report monitoring completed", [
                'report_code' => $reportCode,
                'final_status' => $result['status'],
                'duration_minutes' => round($result['monitoring_duration'] / 60, 2),
                'checks_performed' => $checksPerformed,
                'errors_encountered' => $errorsEncountered
            ]);
            
            return $result;
            
        } catch (Exception $e) {
            $endTime = time();
            $result['status'] = self::STATUS_ERROR;
            $result['end_time'] = date('Y-m-d H:i:s', $endTime);
            $result['monitoring_duration'] = $endTime - $startTime;
            $result['checks_performed'] = $checksPerformed;
            $result['errors_encountered'] = $errorsEncountered;
            $result['error_message'] = $e->getMessage();
            
            ($this->logger['error'])("Report monitoring failed with exception", [
                'report_code' => $reportCode,
                'error' => $e->getMessage(),
                'duration_minutes' => round($result['monitoring_duration'] / 60, 2)
            ]);
            
            // Сохраняем результат с ошибкой
            $this->saveMonitoringResult($result);
            
            throw $e;
        }
    }
    
    /**
     * Проверка статуса отчета
     * 
     * @param string $reportCode - код отчета
     * @return array информация о статусе отчета
     * @throws Exception при ошибках проверки
     */
    public function checkReportStatus(string $reportCode): array {
        try {
            // Получаем информацию об отчете через ReportRequestHandler
            $reportInfo = $this->reportRequestHandler->getReportInfo($reportCode);
            
            // Нормализуем статус
            $normalizedStatus = $this->normalizeReportStatus($reportInfo['status'] ?? self::STATUS_UNKNOWN);
            $reportInfo['status'] = $normalizedStatus;
            
            // Добавляем временную метку проверки
            $reportInfo['checked_at'] = date('Y-m-d H:i:s');
            
            return $reportInfo;
            
        } catch (Exception $e) {
            ($this->logger['error'])("Failed to check report status", [
                'report_code' => $reportCode,
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }
    
    /**
     * Планирование проверки статуса с задержкой
     * 
     * @param string $reportCode - код отчета
     * @param int $delayMinutes - задержка в минутах
     */
    public function scheduleStatusCheck(string $reportCode, int $delayMinutes = 5): void {
        ($this->logger['info'])("Scheduling status check", [
            'report_code' => $reportCode,
            'delay_minutes' => $delayMinutes,
            'scheduled_for' => date('Y-m-d H:i:s', time() + ($delayMinutes * 60))
        ]);
        
        // В реальной реализации здесь можно использовать систему очередей
        // Для простоты используем sleep
        sleep($delayMinutes * 60);
        
        try {
            $this->checkReportStatus($reportCode);
        } catch (Exception $e) {
            ($this->logger['warning'])("Scheduled status check failed", [
                'report_code' => $reportCode,
                'error' => $e->getMessage()
            ]);
        }
    }
    
    /**
     * Обработка таймаута отчета
     * 
     * @param string $reportCode - код отчета
     */
    public function handleReportTimeout(string $reportCode): void {
        ($this->logger['warning'])("Handling report timeout", [
            'report_code' => $reportCode,
            'timestamp' => date('Y-m-d H:i:s')
        ]);
        
        try {
            // Обновляем статус отчета в базе данных
            $this->updateReportStatusInDatabase($reportCode, self::STATUS_TIMEOUT, [
                'timeout_handled_at' => date('Y-m-d H:i:s'),
                'error_message' => 'Report generation timed out'
            ]);
            
            // Логируем событие таймаута
            $this->logReportEvent($reportCode, 'TIMEOUT', 'Report generation timed out');
            
        } catch (Exception $e) {
            ($this->logger['error'])("Failed to handle report timeout", [
                'report_code' => $reportCode,
                'error' => $e->getMessage()
            ]);
        }
    }
    
    /**
     * Валидация интервала проверки
     * 
     * @param int $checkInterval - интервал в секундах
     * @return int валидированный интервал
     */
    private function validateCheckInterval(int $checkInterval): int {
        if ($checkInterval < self::MIN_CHECK_INTERVAL) {
            ($this->logger['warning'])("Check interval too small, using minimum", [
                'requested' => $checkInterval,
                'minimum' => self::MIN_CHECK_INTERVAL
            ]);
            return self::MIN_CHECK_INTERVAL;
        }
        
        if ($checkInterval > self::MAX_CHECK_INTERVAL) {
            ($this->logger['warning'])("Check interval too large, using maximum", [
                'requested' => $checkInterval,
                'maximum' => self::MAX_CHECK_INTERVAL
            ]);
            return self::MAX_CHECK_INTERVAL;
        }
        
        return $checkInterval;
    }
    
    /**
     * Нормализация статуса отчета
     * 
     * @param string $status - исходный статус
     * @return string нормализованный статус
     */
    private function normalizeReportStatus(string $status): string {
        $status = strtoupper(trim($status));
        
        // Маппинг различных вариантов статусов к стандартным
        $statusMap = [
            'PROCESSING' => self::STATUS_PROCESSING,
            'IN_PROGRESS' => self::STATUS_PROCESSING,
            'PENDING' => self::STATUS_PROCESSING,
            'RUNNING' => self::STATUS_PROCESSING,
            'SUCCESS' => self::STATUS_SUCCESS,
            'COMPLETED' => self::STATUS_SUCCESS,
            'DONE' => self::STATUS_SUCCESS,
            'READY' => self::STATUS_SUCCESS,
            'ERROR' => self::STATUS_ERROR,
            'FAILED' => self::STATUS_ERROR,
            'FAILURE' => self::STATUS_ERROR,
            'TIMEOUT' => self::STATUS_TIMEOUT,
            'EXPIRED' => self::STATUS_TIMEOUT
        ];
        
        return $statusMap[$status] ?? self::STATUS_UNKNOWN;
    }
    
    /**
     * Проверка, завершен ли отчет
     * 
     * @param string $status - статус отчета
     * @return bool true если отчет завершен
     */
    private function isReportCompleted(string $status): bool {
        $completedStatuses = [
            self::STATUS_SUCCESS,
            self::STATUS_ERROR,
            self::STATUS_TIMEOUT
        ];
        
        return in_array($status, $completedStatuses);
    }
    
    /**
     * Расчет задержки для экспоненциального backoff
     * 
     * @param int $attemptNumber - номер попытки
     * @return int задержка в секундах
     */
    private function calculateBackoffDelay(int $attemptNumber): int {
        $delay = min(
            self::MAX_BACKOFF_DELAY,
            pow(self::EXPONENTIAL_BACKOFF_BASE, $attemptNumber - 1) * 30
        );
        
        // Добавляем случайный jitter для избежания thundering herd
        $jitter = rand(0, 30);
        
        return $delay + $jitter;
    }
    
    /**
     * Логирование проверки статуса отчета
     * 
     * @param string $reportCode - код отчета
     * @param array $reportInfo - информация об отчете
     * @param int $checkNumber - номер проверки
     */
    private function logReportStatusCheck(string $reportCode, array $reportInfo, int $checkNumber): void {
        try {
            $sql = "INSERT INTO stock_report_logs 
                    (report_code, log_level, message, context, created_at)
                    VALUES (:report_code, :log_level, :message, :context, CURRENT_TIMESTAMP)";
            
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute([
                'report_code' => $reportCode,
                'log_level' => 'INFO',
                'message' => "Status check #$checkNumber: " . $reportInfo['status'],
                'context' => json_encode([
                    'check_number' => $checkNumber,
                    'status' => $reportInfo['status'],
                    'download_url' => $reportInfo['download_url'] ?? null,
                    'file_size' => $reportInfo['file_size'] ?? null,
                    'checked_at' => $reportInfo['checked_at']
                ])
            ]);
            
        } catch (Exception $e) {
            ($this->logger['warning'])("Failed to log status check", [
                'report_code' => $reportCode,
                'error' => $e->getMessage()
            ]);
        }
    }
    
    /**
     * Логирование события отчета
     * 
     * @param string $reportCode - код отчета
     * @param string $eventType - тип события
     * @param string $message - сообщение
     * @param array $context - дополнительный контекст
     */
    private function logReportEvent(string $reportCode, string $eventType, string $message, array $context = []): void {
        try {
            $logLevel = ($eventType === 'ERROR' || $eventType === 'TIMEOUT') ? 'ERROR' : 'INFO';
            
            $sql = "INSERT INTO stock_report_logs 
                    (report_code, log_level, message, context, created_at)
                    VALUES (:report_code, :log_level, :message, :context, CURRENT_TIMESTAMP)";
            
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute([
                'report_code' => $reportCode,
                'log_level' => $logLevel,
                'message' => "[$eventType] $message",
                'context' => json_encode(array_merge($context, [
                    'event_type' => $eventType,
                    'timestamp' => date('Y-m-d H:i:s')
                ]))
            ]);
            
        } catch (Exception $e) {
            ($this->logger['warning'])("Failed to log report event", [
                'report_code' => $reportCode,
                'event_type' => $eventType,
                'error' => $e->getMessage()
            ]);
        }
    }
    
    /**
     * Обновление статуса отчета в базе данных
     * 
     * @param string $reportCode - код отчета
     * @param string $status - новый статус
     * @param array $additionalData - дополнительные данные
     */
    private function updateReportStatusInDatabase(string $reportCode, string $status, array $additionalData = []): void {
        try {
            $updateFields = ['status = :status'];
            $params = [
                'report_code' => $reportCode,
                'status' => $status
            ];
            
            // Добавляем дополнительные поля
            if (isset($additionalData['error_message'])) {
                $updateFields[] = 'error_message = :error_message';
                $params['error_message'] = $additionalData['error_message'];
            }
            
            if ($status === self::STATUS_SUCCESS) {
                $updateFields[] = 'completed_at = CURRENT_TIMESTAMP';
            }
            
            $sql = "UPDATE ozon_stock_reports SET " . implode(', ', $updateFields) . 
                   " WHERE report_code = :report_code";
            
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($params);
            
        } catch (Exception $e) {
            ($this->logger['warning'])("Failed to update report status in database", [
                'report_code' => $reportCode,
                'status' => $status,
                'error' => $e->getMessage()
            ]);
        }
    }
    
    /**
     * Сохранение результата мониторинга
     * 
     * @param array $result - результат мониторинга
     */
    private function saveMonitoringResult(array $result): void {
        try {
            // Сохраняем результат мониторинга в отдельную таблицу
            $sql = "INSERT INTO ozon_report_monitoring 
                    (report_code, status, start_time, end_time, monitoring_duration,
                     checks_performed, errors_encountered, error_message, result_data)
                    VALUES 
                    (:report_code, :status, :start_time, :end_time, :monitoring_duration,
                     :checks_performed, :errors_encountered, :error_message, :result_data)";
            
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute([
                'report_code' => $result['report_code'],
                'status' => $result['status'],
                'start_time' => $result['start_time'],
                'end_time' => $result['end_time'],
                'monitoring_duration' => $result['monitoring_duration'],
                'checks_performed' => $result['checks_performed'],
                'errors_encountered' => $result['errors_encountered'],
                'error_message' => $result['error_message'],
                'result_data' => json_encode($result)
            ]);
            
        } catch (Exception $e) {
            ($this->logger['warning'])("Failed to save monitoring result", [
                'report_code' => $result['report_code'],
                'error' => $e->getMessage()
            ]);
        }
    }
    
    /**
     * Получение статистики мониторинга
     * 
     * @param int $days - количество дней для анализа
     * @return array статистика мониторинга
     */
    public function getMonitoringStatistics(int $days = 7): array {
        try {
            $sql = "SELECT 
                        COUNT(*) as total_monitoring_sessions,
                        SUM(CASE WHEN status = 'SUCCESS' THEN 1 ELSE 0 END) as successful_sessions,
                        SUM(CASE WHEN status = 'TIMEOUT' THEN 1 ELSE 0 END) as timeout_sessions,
                        SUM(CASE WHEN status = 'ERROR' THEN 1 ELSE 0 END) as error_sessions,
                        AVG(monitoring_duration) as avg_monitoring_duration,
                        AVG(checks_performed) as avg_checks_performed,
                        SUM(errors_encountered) as total_errors_encountered
                    FROM ozon_report_monitoring 
                    WHERE start_time >= CURRENT_TIMESTAMP - INTERVAL ':days days'";
            
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute(['days' => $days]);
            
            $stats = $stmt->fetch();
            
            // Рассчитываем дополнительные метрики
            $stats['success_rate'] = $stats['total_monitoring_sessions'] > 0 
                ? round(($stats['successful_sessions'] / $stats['total_monitoring_sessions']) * 100, 2) 
                : 0;
            
            $stats['timeout_rate'] = $stats['total_monitoring_sessions'] > 0 
                ? round(($stats['timeout_sessions'] / $stats['total_monitoring_sessions']) * 100, 2) 
                : 0;
            
            $stats['avg_monitoring_duration'] = round($stats['avg_monitoring_duration'] ?? 0, 2);
            $stats['avg_checks_performed'] = round($stats['avg_checks_performed'] ?? 0, 1);
            
            return $stats;
            
        } catch (Exception $e) {
            ($this->logger['error'])("Failed to get monitoring statistics", [
                'error' => $e->getMessage(),
                'days' => $days
            ]);
            
            return [
                'total_monitoring_sessions' => 0,
                'successful_sessions' => 0,
                'timeout_sessions' => 0,
                'error_sessions' => 0,
                'success_rate' => 0,
                'timeout_rate' => 0,
                'avg_monitoring_duration' => 0,
                'avg_checks_performed' => 0,
                'total_errors_encountered' => 0
            ];
        }
    }
}