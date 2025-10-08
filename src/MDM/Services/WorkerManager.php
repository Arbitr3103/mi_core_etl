<?php

namespace MDM\Services;

use MDM\Workers\BaseWorker;
use MDM\Workers\ProductMatchingWorker;
use Exception;

/**
 * Менеджер для управления воркерами асинхронной обработки
 */
class WorkerManager
{
    private QueueService $queueService;
    private array $config;
    private array $workers = [];
    private bool $shouldStop = false;
    
    public function __construct(QueueService $queueService, array $config = [])
    {
        $this->queueService = $queueService;
        $this->config = array_merge([
            'max_workers' => 4,
            'worker_types' => [
                'product_matching' => ProductMatchingWorker::class
            ],
            'monitoring_interval' => 30,
            'restart_failed_workers' => true
        ], $config);
        
        // Обработчики сигналов
        if (function_exists('pcntl_signal')) {
            pcntl_signal(SIGTERM, [$this, 'handleShutdown']);
            pcntl_signal(SIGINT, [$this, 'handleShutdown']);
            pcntl_signal(SIGCHLD, [$this, 'handleChildExit']);
        }
    }
    
    /**
     * Запустить менеджер воркеров
     */
    public function start(): void
    {
        $this->log("Worker Manager starting with max {$this->config['max_workers']} workers");
        
        // Запустить начальных воркеров
        $this->startInitialWorkers();
        
        // Основной цикл мониторинга
        while (!$this->shouldStop) {
            try {
                // Проверить сигналы
                if (function_exists('pcntl_signal_dispatch')) {
                    pcntl_signal_dispatch();
                }
                
                // Мониторинг воркеров
                $this->monitorWorkers();
                
                // Перезапуск упавших воркеров
                if ($this->config['restart_failed_workers']) {
                    $this->restartFailedWorkers();
                }
                
                // Подождать перед следующей проверкой
                sleep($this->config['monitoring_interval']);
                
            } catch (Exception $e) {
                $this->log("Manager error: " . $e->getMessage());
                sleep(5);
            }
        }
        
        $this->log("Worker Manager shutting down");
        $this->stopAllWorkers();
    }
    
    /**
     * Запустить начальных воркеров
     */
    private function startInitialWorkers(): void
    {
        $workersPerType = max(1, floor($this->config['max_workers'] / count($this->config['worker_types'])));
        
        foreach ($this->config['worker_types'] as $type => $class) {
            for ($i = 0; $i < $workersPerType; $i++) {
                $this->startWorker($type, $class);
            }
        }
    }
    
    /**
     * Запустить воркер
     */
    private function startWorker(string $type, string $class): ?int
    {
        if (count($this->workers) >= $this->config['max_workers']) {
            return null;
        }
        
        $pid = pcntl_fork();
        
        if ($pid == -1) {
            $this->log("Failed to fork worker process");
            return null;
        } elseif ($pid == 0) {
            // Дочерний процесс - запустить воркер
            try {
                $worker = $this->createWorker($type, $class);
                $worker->run();
                exit(0);
            } catch (Exception $e) {
                $this->log("Worker failed: " . $e->getMessage());
                exit(1);
            }
        } else {
            // Родительский процесс - сохранить информацию о воркере
            $this->workers[$pid] = [
                'type' => $type,
                'class' => $class,
                'started_at' => time(),
                'status' => 'running'
            ];
            
            $this->log("Started worker {$type} with PID {$pid}");
            return $pid;
        }
    }
    
    /**
     * Создать экземпляр воркера
     */
    private function createWorker(string $type, string $class): BaseWorker
    {
        switch ($class) {
            case ProductMatchingWorker::class:
                // Здесь нужно будет инжектить зависимости
                // Для примера создаем с минимальными зависимостями
                $matchingService = new MatchingService(/* dependencies */);
                $productsService = new ProductsService(/* dependencies */);
                return new ProductMatchingWorker($this->queueService, $matchingService, $productsService);
                
            default:
                throw new Exception("Unknown worker class: {$class}");
        }
    }
    
    /**
     * Мониторинг воркеров
     */
    private function monitorWorkers(): void
    {
        $activeWorkers = 0;
        $queueStats = $this->queueService->getQueueStats();
        
        foreach ($this->workers as $pid => $worker) {
            if ($worker['status'] === 'running') {
                // Проверить, что процесс еще жив
                if (posix_kill($pid, 0)) {
                    $activeWorkers++;
                } else {
                    $this->workers[$pid]['status'] = 'dead';
                    $this->log("Worker {$pid} ({$worker['type']}) is dead");
                }
            }
        }
        
        // Статистика
        $pendingJobs = 0;
        foreach ($queueStats['status_stats'] as $stat) {
            if ($stat['status'] === 'pending') {
                $pendingJobs = $stat['count'];
                break;
            }
        }
        
        $this->log("Active workers: {$activeWorkers}, Pending jobs: {$pendingJobs}");
        
        // Автомасштабирование
        $this->autoScale($activeWorkers, $pendingJobs);
    }
    
    /**
     * Автомасштабирование воркеров
     */
    private function autoScale(int $activeWorkers, int $pendingJobs): void
    {
        // Если много задач в очереди и есть свободные слоты, запустить больше воркеров
        if ($pendingJobs > $activeWorkers * 10 && $activeWorkers < $this->config['max_workers']) {
            $workersToStart = min(
                $this->config['max_workers'] - $activeWorkers,
                ceil($pendingJobs / 20)
            );
            
            for ($i = 0; $i < $workersToStart; $i++) {
                // Запустить воркер наиболее нужного типа
                $workerType = $this->getMostNeededWorkerType();
                if ($workerType) {
                    $this->startWorker($workerType['type'], $workerType['class']);
                }
            }
        }
    }
    
    /**
     * Получить тип воркера, который больше всего нужен
     */
    private function getMostNeededWorkerType(): ?array
    {
        $queueStats = $this->queueService->getQueueStats();
        
        // Найти тип задач с наибольшим количеством pending
        $maxPending = 0;
        $neededType = null;
        
        foreach ($queueStats['job_type_stats'] as $stat) {
            if ($stat['pending_jobs'] > $maxPending) {
                $maxPending = $stat['pending_jobs'];
                
                // Найти соответствующий класс воркера
                foreach ($this->config['worker_types'] as $type => $class) {
                    if (strpos($stat['job_type'], $type) !== false) {
                        $neededType = ['type' => $type, 'class' => $class];
                        break;
                    }
                }
            }
        }
        
        return $neededType;
    }
    
    /**
     * Перезапустить упавших воркеров
     */
    private function restartFailedWorkers(): void
    {
        foreach ($this->workers as $pid => $worker) {
            if ($worker['status'] === 'dead') {
                $this->log("Restarting failed worker {$worker['type']}");
                $newPid = $this->startWorker($worker['type'], $worker['class']);
                
                if ($newPid) {
                    unset($this->workers[$pid]);
                }
            }
        }
    }
    
    /**
     * Остановить всех воркеров
     */
    private function stopAllWorkers(): void
    {
        foreach ($this->workers as $pid => $worker) {
            if ($worker['status'] === 'running') {
                $this->log("Stopping worker {$pid}");
                
                // Послать SIGTERM для graceful shutdown
                posix_kill($pid, SIGTERM);
                
                // Подождать немного
                sleep(2);
                
                // Если процесс еще жив, принудительно убить
                if (posix_kill($pid, 0)) {
                    posix_kill($pid, SIGKILL);
                }
            }
        }
        
        // Дождаться завершения всех дочерних процессов
        while (pcntl_waitpid(-1, $status, WNOHANG) > 0) {
            // Ждем завершения
        }
    }
    
    /**
     * Обработчик сигнала завершения
     */
    public function handleShutdown(): void
    {
        $this->shouldStop = true;
        $this->log("Shutdown signal received");
    }
    
    /**
     * Обработчик завершения дочернего процесса
     */
    public function handleChildExit(): void
    {
        while (($pid = pcntl_waitpid(-1, $status, WNOHANG)) > 0) {
            if (isset($this->workers[$pid])) {
                $exitCode = pcntl_wexitstatus($status);
                $this->workers[$pid]['status'] = 'dead';
                $this->workers[$pid]['exit_code'] = $exitCode;
                
                $this->log("Worker {$pid} exited with code {$exitCode}");
            }
        }
    }
    
    /**
     * Получить статус воркеров
     */
    public function getWorkerStatus(): array
    {
        $status = [
            'total_workers' => count($this->workers),
            'active_workers' => 0,
            'dead_workers' => 0,
            'workers' => []
        ];
        
        foreach ($this->workers as $pid => $worker) {
            if ($worker['status'] === 'running') {
                $status['active_workers']++;
            } else {
                $status['dead_workers']++;
            }
            
            $status['workers'][] = [
                'pid' => $pid,
                'type' => $worker['type'],
                'status' => $worker['status'],
                'uptime' => time() - $worker['started_at'],
                'exit_code' => $worker['exit_code'] ?? null
            ];
        }
        
        return $status;
    }
    
    /**
     * Логирование
     */
    private function log(string $message): void
    {
        $timestamp = date('Y-m-d H:i:s');
        echo "[{$timestamp}] [WorkerManager] {$message}\n";
    }
}