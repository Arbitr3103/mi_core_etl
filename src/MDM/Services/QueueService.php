<?php

namespace MDM\Services;

use PDO;
use PDOException;
use Exception;

/**
 * Сервис для управления очередями задач асинхронной обработки
 */
class QueueService
{
    private PDO $pdo;
    private array $config;
    
    const QUEUE_STATUS_PENDING = 'pending';
    const QUEUE_STATUS_PROCESSING = 'processing';
    const QUEUE_STATUS_COMPLETED = 'completed';
    const QUEUE_STATUS_FAILED = 'failed';
    const QUEUE_STATUS_RETRY = 'retry';
    
    const PRIORITY_LOW = 1;
    const PRIORITY_NORMAL = 5;
    const PRIORITY_HIGH = 10;
    const PRIORITY_CRITICAL = 15;
    
    public function __construct(PDO $pdo, array $config = [])
    {
        $this->pdo = $pdo;
        $this->config = array_merge([
            'max_retries' => 3,
            'retry_delay' => 300, // 5 минут
            'batch_size' => 100,
            'worker_timeout' => 3600 // 1 час
        ], $config);
        
        $this->initializeQueueTables();
    }
    
    /**
     * Инициализация таблиц очередей
     */
    private function initializeQueueTables(): void
    {
        $sql = "
        CREATE TABLE IF NOT EXISTS mdm_job_queue (
            id BIGINT AUTO_INCREMENT PRIMARY KEY,
            job_type VARCHAR(100) NOT NULL,
            job_data JSON NOT NULL,
            priority INT DEFAULT 5,
            status ENUM('pending', 'processing', 'completed', 'failed', 'retry') DEFAULT 'pending',
            attempts INT DEFAULT 0,
            max_attempts INT DEFAULT 3,
            worker_id VARCHAR(100) NULL,
            scheduled_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            started_at TIMESTAMP NULL,
            completed_at TIMESTAMP NULL,
            error_message TEXT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            
            INDEX idx_status_priority (status, priority DESC),
            INDEX idx_job_type (job_type),
            INDEX idx_scheduled_at (scheduled_at),
            INDEX idx_worker_id (worker_id)
        );
        
        CREATE TABLE IF NOT EXISTS mdm_job_results (
            id BIGINT AUTO_INCREMENT PRIMARY KEY,
            job_id BIGINT NOT NULL,
            result_data JSON,
            execution_time DECIMAL(10,3),
            memory_usage BIGINT,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            
            FOREIGN KEY (job_id) REFERENCES mdm_job_queue(id) ON DELETE CASCADE,
            INDEX idx_job_id (job_id)
        );
        
        CREATE TABLE IF NOT EXISTS mdm_worker_stats (
            id BIGINT AUTO_INCREMENT PRIMARY KEY,
            worker_id VARCHAR(100) NOT NULL,
            jobs_processed INT DEFAULT 0,
            jobs_failed INT DEFAULT 0,
            total_execution_time DECIMAL(10,3) DEFAULT 0,
            last_activity TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            status ENUM('active', 'idle', 'stopped') DEFAULT 'idle',
            
            UNIQUE KEY unique_worker (worker_id),
            INDEX idx_status (status),
            INDEX idx_last_activity (last_activity)
        );
        ";
        
        $this->pdo->exec($sql);
    }
    
    /**
     * Добавить задачу в очередь
     */
    public function enqueue(string $jobType, array $jobData, int $priority = self::PRIORITY_NORMAL, ?string $scheduledAt = null): int
    {
        try {
            $stmt = $this->pdo->prepare("
                INSERT INTO mdm_job_queue (job_type, job_data, priority, scheduled_at, max_attempts)
                VALUES (?, ?, ?, ?, ?)
            ");
            
            $stmt->execute([
                $jobType,
                json_encode($jobData),
                $priority,
                $scheduledAt ?? date('Y-m-d H:i:s'),
                $this->config['max_retries']
            ]);
            
            return $this->pdo->lastInsertId();
        } catch (PDOException $e) {
            throw new Exception("Failed to enqueue job: " . $e->getMessage());
        }
    }
    
    /**
     * Получить следующую задачу для обработки
     */
    public function dequeue(string $workerId, array $jobTypes = []): ?array
    {
        try {
            $this->pdo->beginTransaction();
            
            // Построить условие для типов задач
            $jobTypeCondition = '';
            $params = [$workerId];
            
            if (!empty($jobTypes)) {
                $placeholders = str_repeat('?,', count($jobTypes) - 1) . '?';
                $jobTypeCondition = "AND job_type IN ($placeholders)";
                $params = array_merge($params, $jobTypes);
            }
            
            // Найти и заблокировать задачу
            $stmt = $this->pdo->prepare("
                SELECT id, job_type, job_data, attempts, max_attempts
                FROM mdm_job_queue
                WHERE status = 'pending'
                AND scheduled_at <= NOW()
                $jobTypeCondition
                ORDER BY priority DESC, scheduled_at ASC
                LIMIT 1
                FOR UPDATE
            ");
            
            $stmt->execute($params);
            $job = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$job) {
                $this->pdo->rollBack();
                return null;
            }
            
            // Обновить статус задачи
            $updateStmt = $this->pdo->prepare("
                UPDATE mdm_job_queue 
                SET status = 'processing', 
                    worker_id = ?, 
                    started_at = NOW(),
                    attempts = attempts + 1,
                    updated_at = NOW()
                WHERE id = ?
            ");
            
            $updateStmt->execute([$workerId, $job['id']]);
            
            $this->pdo->commit();
            
            // Обновить статистику воркера
            $this->updateWorkerStats($workerId, 'active');
            
            return [
                'id' => $job['id'],
                'type' => $job['job_type'],
                'data' => json_decode($job['job_data'], true),
                'attempts' => $job['attempts'],
                'max_attempts' => $job['max_attempts']
            ];
            
        } catch (PDOException $e) {
            $this->pdo->rollBack();
            throw new Exception("Failed to dequeue job: " . $e->getMessage());
        }
    }
    
    /**
     * Отметить задачу как выполненную
     */
    public function markCompleted(int $jobId, array $result = [], float $executionTime = 0, int $memoryUsage = 0): void
    {
        try {
            $this->pdo->beginTransaction();
            
            // Обновить статус задачи
            $stmt = $this->pdo->prepare("
                UPDATE mdm_job_queue 
                SET status = 'completed', 
                    completed_at = NOW(),
                    updated_at = NOW()
                WHERE id = ?
            ");
            $stmt->execute([$jobId]);
            
            // Сохранить результат
            if (!empty($result) || $executionTime > 0) {
                $resultStmt = $this->pdo->prepare("
                    INSERT INTO mdm_job_results (job_id, result_data, execution_time, memory_usage)
                    VALUES (?, ?, ?, ?)
                ");
                $resultStmt->execute([
                    $jobId,
                    json_encode($result),
                    $executionTime,
                    $memoryUsage
                ]);
            }
            
            $this->pdo->commit();
            
            // Обновить статистику воркера
            $this->incrementWorkerStats($jobId, 'completed');
            
        } catch (PDOException $e) {
            $this->pdo->rollBack();
            throw new Exception("Failed to mark job as completed: " . $e->getMessage());
        }
    }
    
    /**
     * Отметить задачу как неудачную
     */
    public function markFailed(int $jobId, string $errorMessage): void
    {
        try {
            $this->pdo->beginTransaction();
            
            // Получить информацию о задаче
            $stmt = $this->pdo->prepare("
                SELECT attempts, max_attempts FROM mdm_job_queue WHERE id = ?
            ");
            $stmt->execute([$jobId]);
            $job = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$job) {
                throw new Exception("Job not found");
            }
            
            // Определить статус: retry или failed
            $status = ($job['attempts'] < $job['max_attempts']) ? 'retry' : 'failed';
            $scheduledAt = null;
            
            if ($status === 'retry') {
                // Запланировать повторную попытку с задержкой
                $scheduledAt = date('Y-m-d H:i:s', time() + $this->config['retry_delay']);
            }
            
            // Обновить статус задачи
            $updateStmt = $this->pdo->prepare("
                UPDATE mdm_job_queue 
                SET status = ?, 
                    error_message = ?,
                    scheduled_at = COALESCE(?, scheduled_at),
                    worker_id = NULL,
                    updated_at = NOW()
                WHERE id = ?
            ");
            
            $updateStmt->execute([$status, $errorMessage, $scheduledAt, $jobId]);
            
            $this->pdo->commit();
            
            // Обновить статистику воркера
            $this->incrementWorkerStats($jobId, 'failed');
            
        } catch (PDOException $e) {
            $this->pdo->rollBack();
            throw new Exception("Failed to mark job as failed: " . $e->getMessage());
        }
    }
    
    /**
     * Получить статистику очередей
     */
    public function getQueueStats(): array
    {
        try {
            // Статистика по статусам
            $stmt = $this->pdo->query("
                SELECT 
                    status,
                    COUNT(*) as count,
                    AVG(CASE WHEN started_at IS NOT NULL AND completed_at IS NOT NULL 
                        THEN TIMESTAMPDIFF(SECOND, started_at, completed_at) END) as avg_execution_time
                FROM mdm_job_queue
                GROUP BY status
            ");
            $statusStats = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Статистика по типам задач
            $stmt = $this->pdo->query("
                SELECT 
                    job_type,
                    COUNT(*) as total_jobs,
                    SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as completed_jobs,
                    SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) as failed_jobs,
                    SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending_jobs
                FROM mdm_job_queue
                WHERE created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)
                GROUP BY job_type
            ");
            $jobTypeStats = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Статистика воркеров
            $stmt = $this->pdo->query("
                SELECT 
                    worker_id,
                    jobs_processed,
                    jobs_failed,
                    total_execution_time,
                    status,
                    last_activity
                FROM mdm_worker_stats
                ORDER BY last_activity DESC
            ");
            $workerStats = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            return [
                'status_stats' => $statusStats,
                'job_type_stats' => $jobTypeStats,
                'worker_stats' => $workerStats
            ];
            
        } catch (PDOException $e) {
            throw new Exception("Failed to get queue stats: " . $e->getMessage());
        }
    }
    
    /**
     * Очистить завершенные задачи старше указанного времени
     */
    public function cleanupCompletedJobs(int $olderThanHours = 24): int
    {
        try {
            $stmt = $this->pdo->prepare("
                DELETE FROM mdm_job_queue 
                WHERE status = 'completed' 
                AND completed_at < DATE_SUB(NOW(), INTERVAL ? HOUR)
            ");
            $stmt->execute([$olderThanHours]);
            
            return $stmt->rowCount();
        } catch (PDOException $e) {
            throw new Exception("Failed to cleanup completed jobs: " . $e->getMessage());
        }
    }
    
    /**
     * Сбросить зависшие задачи
     */
    public function resetStuckJobs(int $timeoutMinutes = 60): int
    {
        try {
            $stmt = $this->pdo->prepare("
                UPDATE mdm_job_queue 
                SET status = 'pending', 
                    worker_id = NULL,
                    started_at = NULL,
                    error_message = 'Reset due to timeout',
                    updated_at = NOW()
                WHERE status = 'processing' 
                AND started_at < DATE_SUB(NOW(), INTERVAL ? MINUTE)
            ");
            $stmt->execute([$timeoutMinutes]);
            
            return $stmt->rowCount();
        } catch (PDOException $e) {
            throw new Exception("Failed to reset stuck jobs: " . $e->getMessage());
        }
    }
    
    /**
     * Обновить статистику воркера
     */
    private function updateWorkerStats(string $workerId, string $status): void
    {
        try {
            $stmt = $this->pdo->prepare("
                INSERT INTO mdm_worker_stats (worker_id, status, last_activity)
                VALUES (?, ?, NOW())
                ON DUPLICATE KEY UPDATE
                status = VALUES(status),
                last_activity = VALUES(last_activity)
            ");
            $stmt->execute([$workerId, $status]);
        } catch (PDOException $e) {
            error_log("Failed to update worker stats: " . $e->getMessage());
        }
    }
    
    /**
     * Увеличить счетчики статистики воркера
     */
    private function incrementWorkerStats(int $jobId, string $type): void
    {
        try {
            // Получить worker_id и время выполнения
            $stmt = $this->pdo->prepare("
                SELECT 
                    worker_id,
                    TIMESTAMPDIFF(SECOND, started_at, completed_at) as execution_time
                FROM mdm_job_queue 
                WHERE id = ?
            ");
            $stmt->execute([$jobId]);
            $job = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$job || !$job['worker_id']) {
                return;
            }
            
            $field = ($type === 'completed') ? 'jobs_processed' : 'jobs_failed';
            $executionTime = $job['execution_time'] ?? 0;
            
            $updateStmt = $this->pdo->prepare("
                UPDATE mdm_worker_stats 
                SET {$field} = {$field} + 1,
                    total_execution_time = total_execution_time + ?,
                    last_activity = NOW()
                WHERE worker_id = ?
            ");
            $updateStmt->execute([$executionTime, $job['worker_id']]);
            
        } catch (PDOException $e) {
            error_log("Failed to increment worker stats: " . $e->getMessage());
        }
    }
}