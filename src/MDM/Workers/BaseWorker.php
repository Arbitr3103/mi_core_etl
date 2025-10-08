<?php

namespace MDM\Workers;

use MDM\Services\QueueService;
use Exception;

/**
 * Базовый класс для воркеров асинхронной обработки
 */
abstract class BaseWorker
{
    protected QueueService $queueService;
    protected string $workerId;
    protected array $config;
    protected bool $shouldStop = false;
    
    public function __construct(QueueService $queueService, array $config = [])
    {
        $this->queueService = $queueService;
        $this->workerId = $this->generateWorkerId();
        $this->config = array_merge([
            'max_jobs_per_run' => 100,
            'sleep_interval' => 5,
            'memory_limit' => '256M',
            'time_limit' => 3600
        ], $config);
        
        // Установить лимиты
        ini_set('memory_limit', $this->config['memory_limit']);
        set_time_limit($this->config['time_limit']);
        
        // Обработчики сигналов для graceful shutdown
        if (function_exists('pcntl_signal')) {
            pcntl_signal(SIGTERM, [$this, 'handleShutdown']);
            pcntl_signal(SIGINT, [$this, 'handleShutdown']);
        }
    }
    
    /**
     * Запустить воркер
     */
    public function run(): void
    {
        $this->log("Worker {$this->workerId} started");
        
        $jobsProcessed = 0;
        
        while (!$this->shouldStop && $jobsProcessed < $this->config['max_jobs_per_run']) {
            try {
                // Проверить сигналы
                if (function_exists('pcntl_signal_dispatch')) {
                    pcntl_signal_dispatch();
                }
                
                // Получить задачу из очереди
                $job = $this->queueService->dequeue($this->workerId, $this->getSupportedJobTypes());
                
                if (!$job) {
                    // Нет задач, подождать
                    sleep($this->config['sleep_interval']);
                    continue;
                }
                
                $this->log("Processing job {$job['id']} of type {$job['type']}");
                
                // Обработать задачу
                $startTime = microtime(true);
                $startMemory = memory_get_usage(true);
                
                try {
                    $result = $this->processJob($job);
                    
                    $executionTime = microtime(true) - $startTime;
                    $memoryUsage = memory_get_usage(true) - $startMemory;
                    
                    $this->queueService->markCompleted(
                        $job['id'], 
                        $result, 
                        $executionTime, 
                        $memoryUsage
                    );
                    
                    $this->log("Job {$job['id']} completed in {$executionTime}s");
                    $jobsProcessed++;
                    
                } catch (Exception $e) {
                    $this->log("Job {$job['id']} failed: " . $e->getMessage());
                    $this->queueService->markFailed($job['id'], $e->getMessage());
                }
                
            } catch (Exception $e) {
                $this->log("Worker error: " . $e->getMessage());
                sleep($this->config['sleep_interval']);
            }
        }
        
        $this->log("Worker {$this->workerId} stopped after processing {$jobsProcessed} jobs");
    }
    
    /**
     * Обработать задачу (должен быть реализован в наследниках)
     */
    abstract protected function processJob(array $job): array;
    
    /**
     * Получить поддерживаемые типы задач
     */
    abstract protected function getSupportedJobTypes(): array;
    
    /**
     * Обработчик сигнала завершения
     */
    public function handleShutdown(): void
    {
        $this->shouldStop = true;
        $this->log("Shutdown signal received");
    }
    
    /**
     * Логирование
     */
    protected function log(string $message): void
    {
        $timestamp = date('Y-m-d H:i:s');
        echo "[{$timestamp}] [{$this->workerId}] {$message}\n";
    }
    
    /**
     * Генерировать ID воркера
     */
    private function generateWorkerId(): string
    {
        return gethostname() . '_' . getmypid() . '_' . uniqid();
    }
}