<?php

namespace MDM\ETL\Monitoring;

use Exception;
use PDO;

/**
 * Контроллер dashboard для мониторинга ETL операций
 * Предоставляет данные для веб-интерфейса мониторинга
 */
class ETLDashboardController
{
    private PDO $pdo;
    private ETLMonitoringService $monitoringService;
    
    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
        $this->monitoringService = new ETLMonitoringService($pdo);
    }
    
    /**
     * Получение данных для главной страницы dashboard
     * 
     * @return array Данные для dashboard
     */
    public function getDashboardData(): array
    {
        try {
            return [
                'active_tasks' => $this->monitoringService->getActiveTasksStatus(),
                'system_alerts' => $this->monitoringService->getSystemAlerts(),
                'performance_metrics' => $this->monitoringService->getPerformanceMetrics(7),
                'error_statistics' => $this->monitoringService->getErrorStatistics(7),
                'recent_tasks' => $this->monitoringService->getTaskHistory(10),
                'system_health' => $this->getSystemHealth(),
                'quick_stats' => $this->getQuickStats()
            ];
            
        } catch (Exception $e) {
            $this->log('ERROR', 'Ошибка получения данных dashboard', [
                'error' => $e->getMessage()
            ]);
            return $this->getEmptyDashboardData();
        }
    }
    
    /**
     * Получение детальной информации о задаче
     * 
     * @param string $sessionId ID сессии мониторинга
     * @return array|null Детальная информация о задаче
     */
    public function getTaskDetails(string $sessionId): ?array
    {
        try {
            $stmt = $this->pdo->prepare("
                SELECT 
                    session_id,
                    task_id,
                    task_type,
                    status,
                    current_step,
                    records_processed,
                    records_total,
                    progress_data,
                    metadata,
                    results,
                    started_at,
                    finished_at,
                    duration_seconds,
                    CASE 
                        WHEN records_total > 0 THEN ROUND((records_processed / records_total) * 100, 1)
                        ELSE 0 
                    END as progress_percent
                FROM etl_monitoring_sessions 
                WHERE session_id = ?
            ");
            $stmt->execute([$sessionId]);
            $task = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$task) {
                return null;
            }
            
            // Получаем логи для этой задачи
            $task['logs'] = $this->getTaskLogs($sessionId);
            
            // Получаем метрики производительности
            $task['performance_metrics'] = $this->getTaskPerformanceMetrics($sessionId);
            
            // Декодируем JSON поля
            $task['progress_data'] = $task['progress_data'] ? json_decode($task['progress_data'], true) : null;
            $task['metadata'] = $task['metadata'] ? json_decode($task['metadata'], true) : null;
            $task['results'] = $task['results'] ? json_decode($task['results'], true) : null;
            
            return $task;
            
        } catch (Exception $e) {
            $this->log('ERROR', 'Ошибка получения деталей задачи', [
                'session_id' => $sessionId,
                'error' => $e->getMessage()
            ]);
            return null;
        }
    }
    
    /**
     * Получение истории задач с фильтрацией
     * 
     * @param array $filters Фильтры
     * @param int $page Номер страницы
     * @param int $limit Количество записей на странице
     * @return array История задач с пагинацией
     */
    public function getTaskHistoryPaginated(array $filters = [], int $page = 1, int $limit = 20): array
    {
        try {
            $offset = ($page - 1) * $limit;
            
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
            
            if (!empty($filters['search'])) {
                $whereConditions[] = "(task_id LIKE ? OR session_id LIKE ?)";
                $searchTerm = '%' . $filters['search'] . '%';
                $params[] = $searchTerm;
                $params[] = $searchTerm;
            }
            
            $whereClause = !empty($whereConditions) ? 'WHERE ' . implode(' AND ', $whereConditions) : '';
            
            // Получаем общее количество записей
            $countStmt = $this->pdo->prepare("
                SELECT COUNT(*) as total
                FROM etl_monitoring_sessions 
                $whereClause
            ");
            $countStmt->execute($params);
            $totalRecords = $countStmt->fetch(PDO::FETCH_ASSOC)['total'];
            
            // Получаем записи для текущей страницы
            $dataParams = array_merge($params, [$limit, $offset]);
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
                    CASE 
                        WHEN records_total > 0 THEN ROUND((records_processed / records_total) * 100, 1)
                        ELSE 0 
                    END as progress_percent
                FROM etl_monitoring_sessions 
                $whereClause
                ORDER BY started_at DESC 
                LIMIT ? OFFSET ?
            ");
            $stmt->execute($dataParams);
            $tasks = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            return [
                'tasks' => $tasks,
                'pagination' => [
                    'current_page' => $page,
                    'total_pages' => ceil($totalRecords / $limit),
                    'total_records' => $totalRecords,
                    'per_page' => $limit,
                    'has_next' => $page < ceil($totalRecords / $limit),
                    'has_prev' => $page > 1
                ]
            ];
            
        } catch (Exception $e) {
            $this->log('ERROR', 'Ошибка получения истории задач', [
                'filters' => $filters,
                'error' => $e->getMessage()
            ]);
            return [
                'tasks' => [],
                'pagination' => [
                    'current_page' => 1,
                    'total_pages' => 0,
                    'total_records' => 0,
                    'per_page' => $limit,
                    'has_next' => false,
                    'has_prev' => false
                ]
            ];
        }
    }
    
    /**
     * Получение аналитических данных для отчетов
     * 
     * @param int $days Количество дней для анализа
     * @return array Аналитические данные
     */
    public function getAnalyticsData(int $days = 30): array
    {
        try {
            return [
                'performance_trends' => $this->getPerformanceTrends($days),
                'error_analysis' => $this->getErrorAnalysis($days),
                'throughput_analysis' => $this->getThroughputAnalysis($days),
                'source_statistics' => $this->getSourceStatistics($days),
                'task_type_distribution' => $this->getTaskTypeDistribution($days)
            ];
            
        } catch (Exception $e) {
            $this->log('ERROR', 'Ошибка получения аналитических данных', [
                'days' => $days,
                'error' => $e->getMessage()
            ]);
            return [];
        }
    }
    
    /**
     * Получение данных для экспорта отчета
     * 
     * @param string $reportType Тип отчета
     * @param array $filters Фильтры
     * @return array Данные для экспорта
     */
    public function getExportData(string $reportType, array $filters = []): array
    {
        try {
            return match($reportType) {
                'performance' => $this->getPerformanceExportData($filters),
                'errors' => $this->getErrorsExportData($filters),
                'tasks' => $this->getTasksExportData($filters),
                'summary' => $this->getSummaryExportData($filters),
                default => []
            };
            
        } catch (Exception $e) {
            $this->log('ERROR', 'Ошибка получения данных для экспорта', [
                'report_type' => $reportType,
                'error' => $e->getMessage()
            ]);
            return [];
        }
    }
    
    /**
     * Получение быстрой статистики для dashboard
     */
    private function getQuickStats(): array
    {
        try {
            // Статистика за последние 24 часа
            $stmt = $this->pdo->query("
                SELECT 
                    COUNT(*) as total_tasks_24h,
                    COUNT(CASE WHEN status = 'success' THEN 1 END) as successful_tasks_24h,
                    COUNT(CASE WHEN status = 'error' THEN 1 END) as failed_tasks_24h,
                    COUNT(CASE WHEN status = 'running' THEN 1 END) as running_tasks,
                    ROUND(AVG(CASE WHEN status IN ('success', 'error') THEN duration_seconds END), 2) as avg_duration_24h,
                    SUM(records_processed) as total_records_24h
                FROM etl_monitoring_sessions 
                WHERE started_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)
            ");
            $stats24h = $stmt->fetch(PDO::FETCH_ASSOC);
            
            // Статистика за последние 7 дней для сравнения
            $stmt = $this->pdo->query("
                SELECT 
                    COUNT(*) as total_tasks_7d,
                    COUNT(CASE WHEN status = 'success' THEN 1 END) as successful_tasks_7d,
                    ROUND(AVG(CASE WHEN status IN ('success', 'error') THEN duration_seconds END), 2) as avg_duration_7d
                FROM etl_monitoring_sessions 
                WHERE started_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
            ");
            $stats7d = $stmt->fetch(PDO::FETCH_ASSOC);
            
            // Вычисляем процентные изменения
            $successRate24h = $stats24h['total_tasks_24h'] > 0 ? 
                round(($stats24h['successful_tasks_24h'] / $stats24h['total_tasks_24h']) * 100, 1) : 0;
            
            $successRate7d = $stats7d['total_tasks_7d'] > 0 ? 
                round(($stats7d['successful_tasks_7d'] / $stats7d['total_tasks_7d']) * 100, 1) : 0;
            
            return array_merge($stats24h, $stats7d, [
                'success_rate_24h' => $successRate24h,
                'success_rate_7d' => $successRate7d,
                'success_rate_change' => $successRate24h - $successRate7d,
                'avg_duration_change' => $stats24h['avg_duration_24h'] - $stats7d['avg_duration_7d']
            ]);
            
        } catch (Exception $e) {
            $this->log('ERROR', 'Ошибка получения быстрой статистики', [
                'error' => $e->getMessage()
            ]);
            return [];
        }
    }
    
    /**
     * Получение состояния здоровья системы
     */
    private function getSystemHealth(): array
    {
        try {
            $health = [
                'overall_status' => 'healthy',
                'components' => [],
                'issues' => []
            ];
            
            // Проверка базы данных
            try {
                $this->pdo->query('SELECT 1');
                $health['components']['database'] = 'healthy';
            } catch (Exception $e) {
                $health['components']['database'] = 'unhealthy';
                $health['issues'][] = 'Database connection failed';
                $health['overall_status'] = 'unhealthy';
            }
            
            // Проверка активных задач
            $activeTasksCount = count($this->monitoringService->getActiveTasksStatus());
            if ($activeTasksCount > 10) {
                $health['components']['task_load'] = 'warning';
                $health['issues'][] = "High task load: {$activeTasksCount} active tasks";
                if ($health['overall_status'] === 'healthy') {
                    $health['overall_status'] = 'warning';
                }
            } else {
                $health['components']['task_load'] = 'healthy';
            }
            
            // Проверка частоты ошибок
            $stmt = $this->pdo->query("
                SELECT COUNT(*) as error_count
                FROM etl_monitoring_sessions 
                WHERE status = 'error' 
                    AND started_at >= DATE_SUB(NOW(), INTERVAL 1 HOUR)
            ");
            $errorCount = $stmt->fetch(PDO::FETCH_ASSOC)['error_count'];
            
            if ($errorCount > 5) {
                $health['components']['error_rate'] = 'unhealthy';
                $health['issues'][] = "High error rate: {$errorCount} errors in last hour";
                $health['overall_status'] = 'unhealthy';
            } elseif ($errorCount > 2) {
                $health['components']['error_rate'] = 'warning';
                $health['issues'][] = "Elevated error rate: {$errorCount} errors in last hour";
                if ($health['overall_status'] === 'healthy') {
                    $health['overall_status'] = 'warning';
                }
            } else {
                $health['components']['error_rate'] = 'healthy';
            }
            
            return $health;
            
        } catch (Exception $e) {
            return [
                'overall_status' => 'unhealthy',
                'components' => [],
                'issues' => ['Health check failed: ' . $e->getMessage()]
            ];
        }
    }
    
    /**
     * Получение логов для конкретной задачи
     */
    private function getTaskLogs(string $sessionId): array
    {
        try {
            $stmt = $this->pdo->prepare("
                SELECT level, message, context, created_at
                FROM etl_logs 
                WHERE JSON_EXTRACT(context, '$.session_id') = ?
                    OR message LIKE ?
                ORDER BY created_at DESC 
                LIMIT 100
            ");
            $stmt->execute([$sessionId, "%{$sessionId}%"]);
            
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
            
        } catch (Exception $e) {
            $this->log('ERROR', 'Ошибка получения логов задачи', [
                'session_id' => $sessionId,
                'error' => $e->getMessage()
            ]);
            return [];
        }
    }
    
    /**
     * Получение метрик производительности для конкретной задачи
     */
    private function getTaskPerformanceMetrics(string $sessionId): array
    {
        try {
            $stmt = $this->pdo->prepare("
                SELECT 
                    duration_seconds,
                    records_processed,
                    throughput_per_second,
                    created_at
                FROM etl_performance_metrics 
                WHERE session_id = ?
            ");
            $stmt->execute([$sessionId]);
            
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
            
        } catch (Exception $e) {
            return [];
        }
    }
    
    /**
     * Получение трендов производительности
     */
    private function getPerformanceTrends(int $days): array
    {
        try {
            $stmt = $this->pdo->prepare("
                SELECT 
                    DATE(started_at) as date,
                    ROUND(AVG(duration_seconds), 2) as avg_duration,
                    ROUND(AVG(records_processed), 0) as avg_records,
                    COUNT(*) as task_count
                FROM etl_monitoring_sessions 
                WHERE started_at >= DATE_SUB(NOW(), INTERVAL ? DAY)
                    AND status IN ('success', 'error')
                GROUP BY DATE(started_at)
                ORDER BY date ASC
            ");
            $stmt->execute([$days]);
            
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
            
        } catch (Exception $e) {
            return [];
        }
    }
    
    /**
     * Получение анализа ошибок
     */
    private function getErrorAnalysis(int $days): array
    {
        try {
            $stmt = $this->pdo->prepare("
                SELECT 
                    task_type,
                    COUNT(*) as error_count,
                    COUNT(DISTINCT task_id) as affected_tasks,
                    MAX(started_at) as last_error
                FROM etl_monitoring_sessions 
                WHERE status = 'error' 
                    AND started_at >= DATE_SUB(NOW(), INTERVAL ? DAY)
                GROUP BY task_type
                ORDER BY error_count DESC
            ");
            $stmt->execute([$days]);
            
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
            
        } catch (Exception $e) {
            return [];
        }
    }
    
    /**
     * Получение анализа пропускной способности
     */
    private function getThroughputAnalysis(int $days): array
    {
        try {
            $stmt = $this->pdo->prepare("
                SELECT 
                    task_type,
                    ROUND(AVG(CASE WHEN duration_seconds > 0 THEN records_processed / duration_seconds ELSE 0 END), 2) as avg_throughput,
                    ROUND(MAX(CASE WHEN duration_seconds > 0 THEN records_processed / duration_seconds ELSE 0 END), 2) as max_throughput,
                    COUNT(*) as task_count
                FROM etl_monitoring_sessions 
                WHERE started_at >= DATE_SUB(NOW(), INTERVAL ? DAY)
                    AND status = 'success'
                    AND records_processed > 0
                GROUP BY task_type
                ORDER BY avg_throughput DESC
            ");
            $stmt->execute([$days]);
            
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
            
        } catch (Exception $e) {
            return [];
        }
    }
    
    /**
     * Получение статистики по источникам
     */
    private function getSourceStatistics(int $days): array
    {
        try {
            $stmt = $this->pdo->prepare("
                SELECT 
                    JSON_EXTRACT(metadata, '$.source') as source,
                    COUNT(*) as task_count,
                    COUNT(CASE WHEN status = 'success' THEN 1 END) as success_count,
                    ROUND(AVG(duration_seconds), 2) as avg_duration,
                    SUM(records_processed) as total_records
                FROM etl_monitoring_sessions 
                WHERE started_at >= DATE_SUB(NOW(), INTERVAL ? DAY)
                    AND JSON_EXTRACT(metadata, '$.source') IS NOT NULL
                GROUP BY JSON_EXTRACT(metadata, '$.source')
                ORDER BY task_count DESC
            ");
            $stmt->execute([$days]);
            
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
            
        } catch (Exception $e) {
            return [];
        }
    }
    
    /**
     * Получение распределения типов задач
     */
    private function getTaskTypeDistribution(int $days): array
    {
        try {
            $stmt = $this->pdo->prepare("
                SELECT 
                    task_type,
                    COUNT(*) as count,
                    ROUND(COUNT(*) * 100.0 / (SELECT COUNT(*) FROM etl_monitoring_sessions WHERE started_at >= DATE_SUB(NOW(), INTERVAL ? DAY)), 1) as percentage
                FROM etl_monitoring_sessions 
                WHERE started_at >= DATE_SUB(NOW(), INTERVAL ? DAY)
                GROUP BY task_type
                ORDER BY count DESC
            ");
            $stmt->execute([$days, $days]);
            
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
            
        } catch (Exception $e) {
            return [];
        }
    }
    
    /**
     * Получение пустых данных dashboard при ошибке
     */
    private function getEmptyDashboardData(): array
    {
        return [
            'active_tasks' => [],
            'system_alerts' => [],
            'performance_metrics' => [],
            'error_statistics' => [],
            'recent_tasks' => [],
            'system_health' => ['overall_status' => 'unknown'],
            'quick_stats' => []
        ];
    }
    
    /**
     * Получение данных для экспорта производительности
     */
    private function getPerformanceExportData(array $filters): array
    {
        // Реализация экспорта данных производительности
        return [];
    }
    
    /**
     * Получение данных для экспорта ошибок
     */
    private function getErrorsExportData(array $filters): array
    {
        // Реализация экспорта данных об ошибках
        return [];
    }
    
    /**
     * Получение данных для экспорта задач
     */
    private function getTasksExportData(array $filters): array
    {
        // Реализация экспорта данных о задачах
        return [];
    }
    
    /**
     * Получение данных для экспорта сводки
     */
    private function getSummaryExportData(array $filters): array
    {
        // Реализация экспорта сводных данных
        return [];
    }
    
    /**
     * Логирование операций контроллера
     */
    private function log(string $level, string $message, array $context = []): void
    {
        try {
            $stmt = $this->pdo->prepare("
                INSERT INTO etl_logs (source, level, message, context, created_at) 
                VALUES ('dashboard', ?, ?, ?, NOW())
            ");
            $stmt->execute([
                $level, 
                $message, 
                !empty($context) ? json_encode($context) : null
            ]);
        } catch (Exception $e) {
            error_log("[ETL Dashboard] Failed to log: " . $e->getMessage());
        }
    }
}