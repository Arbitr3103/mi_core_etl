<?php

namespace MDM\ETL\Monitoring;

use Exception;
use PDO;

/**
 * Сервис мониторинга ETL процессов
 * Отслеживает статус выполнения, метрики производительности и уведомления
 */
class ETLMonitoringService
{
    private PDO $pdo;
    private array $config;
    private ETLNotificationService $notificationService;
    
    public function __construct(PDO $pdo, array $config = [])
    {
        $this->pdo = $pdo;
        $this->config = array_merge([
            'performance_threshold_seconds' => 300, // 5 минут
            'error_threshold_count' => 10,
            'notification_cooldown_minutes' => 30,
            'metrics_retention_days' => 90
        ], $config);
        
        $this->notificationService = new ETLNotificationService($pdo, $config['notifications'] ?? []);
    }
    
    /**
     * Запуск мониторинга ETL задачи
     * 
     * @param string $taskId Уникальный ID задачи
     * @param string $taskType Тип задачи (full_etl, incremental_etl, source_etl)
     * @param array $metadata Дополнительные метаданные
     * @return string ID сессии мониторинга
     */
    public function startTaskMonitoring(string $taskId, string $taskType, array $metadata = []): string
    {
        $sessionId = $this->generateSessionId();
        
        try {
            $stmt = $this->pdo->prepare("
                INSERT INTO etl_monitoring_sessions 
                (session_id, task_id, task_type, status, metadata, started_at)
                VALUES (?, ?, ?, 'running', ?, NOW())
            ");
            
            $stmt->execute([
                $sessionId,
                $taskId,
                $taskType,
                json_encode($metadata)
            ]);
            
            $this->log('INFO', "Начат мониторинг задачи", [
                'session_id' => $sessionId,
                'task_id' => $taskId,
                'task_type' => $taskType
            ]);
            
            return $sessionId;
            
        } catch (Exception $e) {
            $this->log('ERROR', 'Ошибка запуска мониторинга', [
                'task_id' => $taskId,
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }
    
    /**
     * Обновление прогресса выполнения задачи
     * 
     * @param string $sessionId ID сессии мониторинга
     * @param array $progress Данные о прогрессе
     */
    public function updateTaskProgress(string $sessionId, array $progress): void
    {
        try {
            $stmt = $this->pdo->prepare("
                UPDATE etl_monitoring_sessions 
                SET 
                    progress_data = ?,
                    records_processed = ?,
                    records_total = ?,
                    current_step = ?,
                    updated_at = NOW()
                WHERE session_id = ?
            ");
            
            $stmt->execute([
                json_encode($progress),
                $progress['records_processed'] ?? 0,
                $progress['records_total'] ?? 0,
                $progress['current_step'] ?? null,
                $sessionId
            ]);
            
            // Проверяем производительность
            $this->checkPerformanceThresholds($sessionId);
            
        } catch (Exception $e) {
            $this->log('ERROR', 'Ошибка обновления прогресса', [
                'session_id' => $sessionId,
                'error' => $e->getMessage()
            ]);
        }
    }
    
    /**
     * Завершение мониторинга задачи
     * 
     * @param string $sessionId ID сессии мониторинга
     * @param string $status Финальный статус (success, error, cancelled)
     * @param array $results Результаты выполнения
     */
    public function finishTaskMonitoring(string $sessionId, string $status, array $results = []): void
    {
        try {
            $stmt = $this->pdo->prepare("
                UPDATE etl_monitoring_sessions 
                SET 
                    status = ?,
                    results = ?,
                    finished_at = NOW(),
                    duration_seconds = TIMESTAMPDIFF(SECOND, started_at, NOW())
                WHERE session_id = ?
            ");
            
            $stmt->execute([
                $status,
                json_encode($results),
                $sessionId
            ]);
            
            // Сохраняем метрики производительности
            $this->savePerformanceMetrics($sessionId);
            
            // Отправляем уведомления при необходимости
            if ($status === 'error') {
                $this->handleTaskError($sessionId, $results);
            }
            
            $this->log('INFO', "Завершен мониторинг задачи", [
                'session_id' => $sessionId,
                'status' => $status
            ]);
            
        } catch (Exception $e) {
            $this->log('ERROR', 'Ошибка завершения мониторинга', [
                'session_id' => $sessionId,
                'error' => $e->getMessage()
            ]);
        }
    }
    
    /**
     * Получение текущего статуса всех активных задач
     * 
     * @return array Статус активных задач
     */
    public function getActiveTasksStatus(): array
    {
        try {
            $stmt = $this->pdo->query("
                SELECT 
                    session_id,
                    task_id,
                    task_type,
                    status,
                    current_step,
                    records_processed,
                    records_total,
                    CASE 
                        WHEN records_total > 0 THEN ROUND((records_processed / records_total) * 100, 1)
                        ELSE 0 
                    END as progress_percent,
                    TIMESTAMPDIFF(SECOND, started_at, NOW()) as running_seconds,
                    started_at
                FROM etl_monitoring_sessions 
                WHERE status = 'running'
                ORDER BY started_at DESC
            ");
            
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
            
        } catch (Exception $e) {
            $this->log('ERROR', 'Ошибка получения статуса активных задач', [
                'error' => $e->getMessage()
            ]);
            return [];
        }
    }
    
    /**
     * Получение истории выполнения задач
     * 
     * @param int $limit Количество записей
     * @param array $filters Фильтры
     * @return array История задач
     */
    public function getTaskHistory(int $limit = 50, array $filters = []): array
    {
        try {
            $whereConditions = [];
            $params = [];
            
            if (!empty($filters['task_type'])) {
                $whereConditions[] = "task_type = ?";
                $params[] = $filters['task_type'];
            }
            
            if (!empty($filters['status'])) {
                $whereConditions[] = "status = ?";
                $params[] = $filters['status'];
            }
            
            if (!empty($filters['date_from'])) {
                $whereConditions[] = "started_at >= ?";
                $params[] = $filters['date_from'];
            }
            
            if (!empty($filters['date_to'])) {
                $whereConditions[] = "started_at <= ?";
                $params[] = $filters['date_to'];
            }
            
            $whereClause = !empty($whereConditions) ? 'WHERE ' . implode(' AND ', $whereConditions) : '';
            
            $stmt = $this->pdo->prepare("
                SELECT 
                    session_id,
                    task_id,
                    task_type,
                    status,
                    records_processed,
                    records_total,
                    duration_seconds,
                    started_at,
                    finished_at,
                    results
                FROM etl_monitoring_sessions 
                $whereClause
                ORDER BY started_at DESC 
                LIMIT ?
            ");
            
            $params[] = $limit;
            $stmt->execute($params);
            
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
            
        } catch (Exception $e) {
            $this->log('ERROR', 'Ошибка получения истории задач', [
                'error' => $e->getMessage()
            ]);
            return [];
        }
    }
    
    /**
     * Получение метрик производительности
     * 
     * @param int $days Количество дней для анализа
     * @return array Метрики производительности
     */
    public function getPerformanceMetrics(int $days = 7): array
    {
        try {
            // Общая статистика
            $stmt = $this->pdo->prepare("
                SELECT 
                    COUNT(*) as total_tasks,
                    COUNT(CASE WHEN status = 'success' THEN 1 END) as successful_tasks,
                    COUNT(CASE WHEN status = 'error' THEN 1 END) as failed_tasks,
                    ROUND(AVG(duration_seconds), 2) as avg_duration,
                    ROUND(MAX(duration_seconds), 2) as max_duration,
                    SUM(records_processed) as total_records_processed
                FROM etl_monitoring_sessions 
                WHERE started_at >= DATE_SUB(NOW(), INTERVAL ? DAY)
                    AND status IN ('success', 'error')
            ");
            $stmt->execute([$days]);
            $overallStats = $stmt->fetch(PDO::FETCH_ASSOC);
            
            // Статистика по типам задач
            $stmt = $this->pdo->prepare("
                SELECT 
                    task_type,
                    COUNT(*) as task_count,
                    ROUND(AVG(duration_seconds), 2) as avg_duration,
                    COUNT(CASE WHEN status = 'success' THEN 1 END) as success_count,
                    COUNT(CASE WHEN status = 'error' THEN 1 END) as error_count,
                    ROUND(AVG(records_processed), 0) as avg_records_processed
                FROM etl_monitoring_sessions 
                WHERE started_at >= DATE_SUB(NOW(), INTERVAL ? DAY)
                    AND status IN ('success', 'error')
                GROUP BY task_type
                ORDER BY task_count DESC
            ");
            $stmt->execute([$days]);
            $taskTypeStats = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Тренды по дням
            $stmt = $this->pdo->prepare("
                SELECT 
                    DATE(started_at) as date,
                    COUNT(*) as tasks_count,
                    COUNT(CASE WHEN status = 'success' THEN 1 END) as success_count,
                    COUNT(CASE WHEN status = 'error' THEN 1 END) as error_count,
                    ROUND(AVG(duration_seconds), 2) as avg_duration,
                    SUM(records_processed) as total_records
                FROM etl_monitoring_sessions 
                WHERE started_at >= DATE_SUB(NOW(), INTERVAL ? DAY)
                    AND status IN ('success', 'error')
                GROUP BY DATE(started_at)
                ORDER BY date DESC
            ");
            $stmt->execute([$days]);
            $dailyTrends = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Топ медленных задач
            $stmt = $this->pdo->prepare("
                SELECT 
                    task_id,
                    task_type,
                    duration_seconds,
                    records_processed,
                    started_at,
                    status
                FROM etl_monitoring_sessions 
                WHERE started_at >= DATE_SUB(NOW(), INTERVAL ? DAY)
                    AND status IN ('success', 'error')
                ORDER BY duration_seconds DESC 
                LIMIT 10
            ");
            $stmt->execute([$days]);
            $slowestTasks = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            return [
                'overall_stats' => $overallStats,
                'task_type_stats' => $taskTypeStats,
                'daily_trends' => $dailyTrends,
                'slowest_tasks' => $slowestTasks,
                'analysis_period_days' => $days
            ];
            
        } catch (Exception $e) {
            $this->log('ERROR', 'Ошибка получения метрик производительности', [
                'error' => $e->getMessage()
            ]);
            return [];
        }
    }
    
    /**
     * Получение статистики ошибок
     * 
     * @param int $days Количество дней для анализа
     * @return array Статистика ошибок
     */
    public function getErrorStatistics(int $days = 7): array
    {
        try {
            // Общая статистика ошибок
            $stmt = $this->pdo->prepare("
                SELECT 
                    COUNT(*) as total_errors,
                    COUNT(DISTINCT task_type) as affected_task_types,
                    DATE(MIN(started_at)) as first_error_date,
                    DATE(MAX(started_at)) as last_error_date
                FROM etl_monitoring_sessions 
                WHERE started_at >= DATE_SUB(NOW(), INTERVAL ? DAY)
                    AND status = 'error'
            ");
            $stmt->execute([$days]);
            $errorOverview = $stmt->fetch(PDO::FETCH_ASSOC);
            
            // Ошибки по типам задач
            $stmt = $this->pdo->prepare("
                SELECT 
                    task_type,
                    COUNT(*) as error_count,
                    COUNT(DISTINCT task_id) as affected_tasks,
                    MAX(started_at) as last_error_at
                FROM etl_monitoring_sessions 
                WHERE started_at >= DATE_SUB(NOW(), INTERVAL ? DAY)
                    AND status = 'error'
                GROUP BY task_type
                ORDER BY error_count DESC
            ");
            $stmt->execute([$days]);
            $errorsByTaskType = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Последние ошибки
            $stmt = $this->pdo->prepare("
                SELECT 
                    session_id,
                    task_id,
                    task_type,
                    started_at,
                    duration_seconds,
                    JSON_EXTRACT(results, '$.error_message') as error_message
                FROM etl_monitoring_sessions 
                WHERE started_at >= DATE_SUB(NOW(), INTERVAL ? DAY)
                    AND status = 'error'
                ORDER BY started_at DESC 
                LIMIT 20
            ");
            $stmt->execute([$days]);
            $recentErrors = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            return [
                'error_overview' => $errorOverview,
                'errors_by_task_type' => $errorsByTaskType,
                'recent_errors' => $recentErrors
            ];
            
        } catch (Exception $e) {
            $this->log('ERROR', 'Ошибка получения статистики ошибок', [
                'error' => $e->getMessage()
            ]);
            return [];
        }
    }
    
    /**
     * Получение системных алертов
     * 
     * @return array Активные алерты
     */
    public function getSystemAlerts(): array
    {
        try {
            $alerts = [];
            
            // Проверка зависших задач
            $stmt = $this->pdo->prepare("
                SELECT COUNT(*) as stuck_tasks_count
                FROM etl_monitoring_sessions 
                WHERE status = 'running' 
                    AND started_at < DATE_SUB(NOW(), INTERVAL ? SECOND)
            ");
            $stmt->execute([$this->config['performance_threshold_seconds']]);
            $stuckTasks = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($stuckTasks['stuck_tasks_count'] > 0) {
                $alerts[] = [
                    'type' => 'warning',
                    'category' => 'performance',
                    'message' => "Обнаружено {$stuckTasks['stuck_tasks_count']} зависших задач",
                    'severity' => 'medium'
                ];
            }
            
            // Проверка частоты ошибок
            $stmt = $this->pdo->prepare("
                SELECT COUNT(*) as recent_errors
                FROM etl_monitoring_sessions 
                WHERE status = 'error' 
                    AND started_at >= DATE_SUB(NOW(), INTERVAL 1 HOUR)
            ");
            $stmt->execute();
            $recentErrors = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($recentErrors['recent_errors'] >= $this->config['error_threshold_count']) {
                $alerts[] = [
                    'type' => 'error',
                    'category' => 'reliability',
                    'message' => "Высокая частота ошибок: {$recentErrors['recent_errors']} за последний час",
                    'severity' => 'high'
                ];
            }
            
            // Проверка производительности
            $stmt = $this->pdo->query("
                SELECT AVG(duration_seconds) as avg_duration
                FROM etl_monitoring_sessions 
                WHERE status = 'success' 
                    AND started_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)
            ");
            $performance = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($performance['avg_duration'] > $this->config['performance_threshold_seconds']) {
                $alerts[] = [
                    'type' => 'warning',
                    'category' => 'performance',
                    'message' => "Снижение производительности: среднее время выполнения " . round($performance['avg_duration'], 1) . " сек",
                    'severity' => 'medium'
                ];
            }
            
            return $alerts;
            
        } catch (Exception $e) {
            $this->log('ERROR', 'Ошибка получения системных алертов', [
                'error' => $e->getMessage()
            ]);
            return [];
        }
    }
    
    /**
     * Проверка пороговых значений производительности
     * 
     * @param string $sessionId ID сессии мониторинга
     */
    private function checkPerformanceThresholds(string $sessionId): void
    {
        try {
            $stmt = $this->pdo->prepare("
                SELECT 
                    task_id,
                    task_type,
                    TIMESTAMPDIFF(SECOND, started_at, NOW()) as running_seconds
                FROM etl_monitoring_sessions 
                WHERE session_id = ?
            ");
            $stmt->execute([$sessionId]);
            $task = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($task && $task['running_seconds'] > $this->config['performance_threshold_seconds']) {
                $this->notificationService->sendPerformanceAlert(
                    $sessionId,
                    $task['task_id'],
                    $task['task_type'],
                    $task['running_seconds']
                );
            }
            
        } catch (Exception $e) {
            $this->log('ERROR', 'Ошибка проверки производительности', [
                'session_id' => $sessionId,
                'error' => $e->getMessage()
            ]);
        }
    }
    
    /**
     * Обработка ошибки задачи
     * 
     * @param string $sessionId ID сессии мониторинга
     * @param array $results Результаты с ошибкой
     */
    private function handleTaskError(string $sessionId, array $results): void
    {
        try {
            $stmt = $this->pdo->prepare("
                SELECT task_id, task_type, started_at
                FROM etl_monitoring_sessions 
                WHERE session_id = ?
            ");
            $stmt->execute([$sessionId]);
            $task = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($task) {
                $this->notificationService->sendErrorAlert(
                    $sessionId,
                    $task['task_id'],
                    $task['task_type'],
                    $results['error_message'] ?? 'Неизвестная ошибка'
                );
            }
            
        } catch (Exception $e) {
            $this->log('ERROR', 'Ошибка обработки ошибки задачи', [
                'session_id' => $sessionId,
                'error' => $e->getMessage()
            ]);
        }
    }
    
    /**
     * Сохранение метрик производительности
     * 
     * @param string $sessionId ID сессии мониторинга
     */
    private function savePerformanceMetrics(string $sessionId): void
    {
        try {
            $stmt = $this->pdo->prepare("
                INSERT INTO etl_performance_metrics 
                (session_id, task_type, duration_seconds, records_processed, throughput_per_second, created_at)
                SELECT 
                    session_id,
                    task_type,
                    duration_seconds,
                    records_processed,
                    CASE 
                        WHEN duration_seconds > 0 THEN ROUND(records_processed / duration_seconds, 2)
                        ELSE 0 
                    END as throughput_per_second,
                    NOW()
                FROM etl_monitoring_sessions 
                WHERE session_id = ? AND status IN ('success', 'error')
            ");
            $stmt->execute([$sessionId]);
            
        } catch (Exception $e) {
            $this->log('ERROR', 'Ошибка сохранения метрик производительности', [
                'session_id' => $sessionId,
                'error' => $e->getMessage()
            ]);
        }
    }
    
    /**
     * Генерация уникального ID сессии
     * 
     * @return string Уникальный ID
     */
    private function generateSessionId(): string
    {
        return 'etl_' . date('Ymd_His') . '_' . substr(md5(uniqid()), 0, 8);
    }
    
    /**
     * Логирование операций мониторинга
     * 
     * @param string $level Уровень лога
     * @param string $message Сообщение
     * @param array $context Контекст
     */
    private function log(string $level, string $message, array $context = []): void
    {
        try {
            $stmt = $this->pdo->prepare("
                INSERT INTO etl_logs (source, level, message, context, created_at) 
                VALUES ('monitoring', ?, ?, ?, NOW())
            ");
            $stmt->execute([
                $level, 
                $message, 
                !empty($context) ? json_encode($context) : null
            ]);
        } catch (Exception $e) {
            error_log("[ETL Monitoring] Failed to log: " . $e->getMessage());
        }
    }
}