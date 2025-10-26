<?php

declare(strict_types=1);

namespace MiCore\ETL\Ozon\Core;

/**
 * Process Manager
 * 
 * Manages ETL processes including monitoring, status tracking,
 * and process lifecycle management.
 * 
 * Requirements addressed:
 * - 5.5: Create mechanism for checking active processes
 * - 4.3: Monitor processing metrics and execution time
 */
class ProcessManager
{
    private string $lockDir;
    private string $pidDir;
    private Logger $logger;
    private array $config;
    
    public function __construct(array $config, Logger $logger)
    {
        $this->config = $config;
        $this->logger = $logger;
        $this->lockDir = $config['paths']['lock_dir'];
        $this->pidDir = $config['paths']['pid_dir'];
        
        // Ensure directories exist
        foreach ([$this->lockDir, $this->pidDir] as $dir) {
            if (!is_dir($dir)) {
                mkdir($dir, 0755, true);
            }
        }
    }
    
    /**
     * Start a new ETL process with monitoring
     * 
     * @param string $processName Name of the ETL process
     * @param callable $processFunction Function to execute
     * @param array $options Process options
     * @return ProcessResult Result of the process execution
     */
    public function startProcess(string $processName, callable $processFunction, array $options = []): ProcessResult
    {
        $startTime = microtime(true);
        $pid = getmypid();
        
        // Create process lock
        $lock = new ProcessLock($processName, $this->lockDir, $this->logger);
        
        if (!$lock->acquire()) {
            $lockInfo = $lock->getLockInfo();
            $message = "Process '{$processName}' is already running";
            
            if ($lockInfo) {
                $message .= " (PID: {$lockInfo['pid']}, started: {$lockInfo['started_at']})";
            }
            
            $this->logger->warning($message, [
                'process_name' => $processName,
                'current_pid' => $pid,
                'lock_info' => $lockInfo
            ]);
            
            return new ProcessResult(false, 0, 0, $message);
        }
        
        try {
            // Create PID file
            $this->createPidFile($processName, $pid, $options);
            
            // Log process start
            $this->logger->info('Starting ETL process', [
                'process_name' => $processName,
                'pid' => $pid,
                'options' => $options,
                'memory_limit' => ini_get('memory_limit'),
                'max_execution_time' => ini_get('max_execution_time')
            ]);
            
            // Execute the process function
            $result = $processFunction();
            
            $duration = microtime(true) - $startTime;
            $memoryUsage = memory_get_peak_usage(true);
            
            // Log successful completion
            $this->logger->info('ETL process completed successfully', [
                'process_name' => $processName,
                'pid' => $pid,
                'duration_seconds' => round($duration, 3),
                'memory_peak_mb' => round($memoryUsage / 1024 / 1024, 2),
                'records_processed' => $result->getRecordsProcessed() ?? 0
            ]);
            
            // Check for performance alerts
            $this->checkPerformanceAlerts($processName, $duration, $memoryUsage);
            
            return new ProcessResult(
                true,
                $result->getRecordsProcessed() ?? 0,
                $duration,
                'Process completed successfully'
            );
            
        } catch (\Exception $e) {
            $duration = microtime(true) - $startTime;
            $memoryUsage = memory_get_peak_usage(true);
            
            $this->logger->error('ETL process failed', [
                'process_name' => $processName,
                'pid' => $pid,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'duration_seconds' => round($duration, 3),
                'memory_peak_mb' => round($memoryUsage / 1024 / 1024, 2)
            ]);
            
            return new ProcessResult(
                false,
                0,
                $duration,
                'Process failed: ' . $e->getMessage()
            );
            
        } finally {
            // Clean up
            $this->removePidFile($processName);
            $lock->release();
        }
    }
    
    /**
     * Get status of all ETL processes
     * 
     * @return array Array of process status information
     */
    public function getProcessStatus(): array
    {
        $status = [
            'active_processes' => [],
            'recent_executions' => [],
            'system_info' => $this->getSystemInfo()
        ];
        
        // Get active locks (running processes)
        $activeLocks = ProcessLock::getActiveLocks($this->lockDir, $this->logger);
        
        foreach ($activeLocks as $lockInfo) {
            $processStatus = [
                'process_name' => $lockInfo['process_name'],
                'pid' => $lockInfo['pid'],
                'started_at' => $lockInfo['started_at'],
                'running_time_seconds' => $lockInfo['lock_age_seconds'],
                'is_running' => $lockInfo['is_process_running'],
                'hostname' => $lockInfo['hostname'] ?? 'unknown',
                'user' => $lockInfo['user'] ?? 'unknown'
            ];
            
            // Add memory and CPU usage if available
            if ($lockInfo['is_process_running']) {
                $processStatus = array_merge($processStatus, $this->getProcessResourceUsage($lockInfo['pid']));
            }
            
            $status['active_processes'][] = $processStatus;
        }
        
        // Get recent executions from logs
        $status['recent_executions'] = $this->getRecentExecutions();
        
        return $status;
    }
    
    /**
     * Kill a running ETL process
     * 
     * @param string $processName Name of the process to kill
     * @param bool $force Whether to force kill the process
     * @return bool True if process was killed successfully
     */
    public function killProcess(string $processName, bool $force = false): bool
    {
        $lock = new ProcessLock($processName, $this->lockDir, $this->logger);
        $lockInfo = $lock->getLockInfo();
        
        if (!$lockInfo) {
            $this->logger->info('No active process found to kill', [
                'process_name' => $processName
            ]);
            return true;
        }
        
        $pid = $lockInfo['pid'];
        
        if (!$lockInfo['is_process_running']) {
            // Process is not running, just clean up the lock
            $lock->forceRelease();
            $this->removePidFile($processName);
            
            $this->logger->info('Cleaned up stale process lock', [
                'process_name' => $processName,
                'stale_pid' => $pid
            ]);
            
            return true;
        }
        
        try {
            // Try graceful termination first
            if (function_exists('posix_kill')) {
                $signal = $force ? SIGKILL : SIGTERM;
                $killed = posix_kill($pid, $signal);
                
                if ($killed) {
                    // Wait a moment for process to terminate
                    sleep(2);
                    
                    // Check if process is still running
                    if (!posix_kill($pid, 0)) {
                        // Process terminated successfully
                        $lock->forceRelease();
                        $this->removePidFile($processName);
                        
                        $this->logger->info('Process killed successfully', [
                            'process_name' => $processName,
                            'pid' => $pid,
                            'signal' => $force ? 'SIGKILL' : 'SIGTERM'
                        ]);
                        
                        return true;
                    } elseif (!$force) {
                        // Try force kill
                        return $this->killProcess($processName, true);
                    }
                }
            } else {
                // Fallback for systems without posix extension
                $command = PHP_OS_FAMILY === 'Windows' ? "taskkill /PID {$pid}" : "kill {$pid}";
                if ($force && PHP_OS_FAMILY !== 'Windows') {
                    $command = "kill -9 {$pid}";
                }
                
                exec($command, $output, $returnCode);
                
                if ($returnCode === 0) {
                    sleep(2);
                    $lock->forceRelease();
                    $this->removePidFile($processName);
                    
                    $this->logger->info('Process killed successfully', [
                        'process_name' => $processName,
                        'pid' => $pid,
                        'command' => $command
                    ]);
                    
                    return true;
                }
            }
            
            $this->logger->error('Failed to kill process', [
                'process_name' => $processName,
                'pid' => $pid,
                'force' => $force
            ]);
            
            return false;
            
        } catch (\Exception $e) {
            $this->logger->error('Exception while killing process', [
                'process_name' => $processName,
                'pid' => $pid,
                'error' => $e->getMessage()
            ]);
            
            return false;
        }
    }
    
    /**
     * Clean up stale processes and locks
     * 
     * @param int $maxAgeSeconds Maximum age for stale processes
     * @return array Cleanup results
     */
    public function cleanup(int $maxAgeSeconds = 86400): array
    {
        $results = [
            'stale_locks_removed' => 0,
            'stale_pids_removed' => 0,
            'errors' => []
        ];
        
        try {
            // Clean up stale locks
            $results['stale_locks_removed'] = ProcessLock::cleanupStaleLocks(
                $this->lockDir,
                $this->logger,
                $maxAgeSeconds
            );
            
            // Clean up stale PID files
            $results['stale_pids_removed'] = $this->cleanupStalePidFiles($maxAgeSeconds);
            
            $this->logger->info('Process cleanup completed', $results);
            
        } catch (\Exception $e) {
            $results['errors'][] = $e->getMessage();
            $this->logger->error('Process cleanup failed', [
                'error' => $e->getMessage()
            ]);
        }
        
        return $results;
    }
    
    /**
     * Create PID file for process tracking
     */
    private function createPidFile(string $processName, int $pid, array $options): void
    {
        $pidFile = $this->pidDir . '/' . $processName . '.pid';
        
        $pidData = [
            'pid' => $pid,
            'process_name' => $processName,
            'started_at' => date('Y-m-d H:i:s'),
            'options' => $options,
            'hostname' => gethostname(),
            'user' => get_current_user(),
            'working_directory' => getcwd(),
            'command_line' => implode(' ', $_SERVER['argv'] ?? [])
        ];
        
        file_put_contents($pidFile, json_encode($pidData, JSON_PRETTY_PRINT));
    }
    
    /**
     * Remove PID file
     */
    private function removePidFile(string $processName): void
    {
        $pidFile = $this->pidDir . '/' . $processName . '.pid';
        
        if (file_exists($pidFile)) {
            unlink($pidFile);
        }
    }
    
    /**
     * Clean up stale PID files
     */
    private function cleanupStalePidFiles(int $maxAgeSeconds): int
    {
        $cleaned = 0;
        $pidFiles = glob($this->pidDir . '/*.pid');
        
        foreach ($pidFiles as $pidFile) {
            $age = time() - filemtime($pidFile);
            
            if ($age > $maxAgeSeconds) {
                unlink($pidFile);
                $cleaned++;
                
                $this->logger->info('Removed stale PID file', [
                    'pid_file' => $pidFile,
                    'age_seconds' => $age
                ]);
            }
        }
        
        return $cleaned;
    }
    
    /**
     * Get system information
     */
    private function getSystemInfo(): array
    {
        return [
            'hostname' => gethostname(),
            'php_version' => PHP_VERSION,
            'memory_limit' => ini_get('memory_limit'),
            'max_execution_time' => ini_get('max_execution_time'),
            'current_memory_usage_mb' => round(memory_get_usage(true) / 1024 / 1024, 2),
            'load_average' => function_exists('sys_getloadavg') ? sys_getloadavg() : null,
            'disk_free_space_gb' => round(disk_free_space('.') / 1024 / 1024 / 1024, 2)
        ];
    }
    
    /**
     * Get resource usage for a specific process
     */
    private function getProcessResourceUsage(int $pid): array
    {
        $usage = [
            'memory_usage_mb' => null,
            'cpu_percent' => null
        ];
        
        try {
            if (PHP_OS_FAMILY !== 'Windows') {
                // Get memory usage from /proc/PID/status
                $statusFile = "/proc/{$pid}/status";
                if (file_exists($statusFile)) {
                    $status = file_get_contents($statusFile);
                    if (preg_match('/VmRSS:\s+(\d+)\s+kB/', $status, $matches)) {
                        $usage['memory_usage_mb'] = round($matches[1] / 1024, 2);
                    }
                }
                
                // Get CPU usage (simplified)
                $statFile = "/proc/{$pid}/stat";
                if (file_exists($statFile)) {
                    $stat = file_get_contents($statFile);
                    $statParts = explode(' ', $stat);
                    if (count($statParts) > 15) {
                        $utime = $statParts[13];
                        $stime = $statParts[14];
                        $usage['cpu_time_seconds'] = ($utime + $stime) / 100; // Convert from jiffies
                    }
                }
            }
        } catch (\Exception $e) {
            // Ignore errors in resource usage collection
        }
        
        return $usage;
    }
    
    /**
     * Get recent process executions from logs
     */
    private function getRecentExecutions(int $limit = 10): array
    {
        // This would typically parse log files to get recent execution history
        // For now, return empty array - implementation depends on log format
        return [];
    }
    
    /**
     * Check for performance alerts
     */
    private function checkPerformanceAlerts(string $processName, float $duration, int $memoryUsage): void
    {
        $thresholds = $this->config['monitoring']['alert_thresholds'] ?? [];
        $maxTimes = $this->config['monitoring']['max_execution_time'] ?? [];
        
        // Check execution time
        if (isset($maxTimes[$processName])) {
            $maxTime = $maxTimes[$processName];
            $multiplier = $thresholds['execution_time_multiplier'] ?? 2.0;
            
            if ($duration > ($maxTime * $multiplier)) {
                $this->logger->warning('Process execution time exceeded threshold', [
                    'process_name' => $processName,
                    'duration_seconds' => $duration,
                    'threshold_seconds' => $maxTime * $multiplier,
                    'multiplier' => $multiplier
                ]);
            }
        }
        
        // Check memory usage
        $memoryThresholdMb = $thresholds['memory_usage_mb'] ?? 1024;
        $memoryUsageMb = $memoryUsage / 1024 / 1024;
        
        if ($memoryUsageMb > $memoryThresholdMb) {
            $this->logger->warning('Process memory usage exceeded threshold', [
                'process_name' => $processName,
                'memory_usage_mb' => round($memoryUsageMb, 2),
                'threshold_mb' => $memoryThresholdMb
            ]);
        }
    }
}

/**
 * Process Result Class
 */
class ProcessResult
{
    private bool $success;
    private int $recordsProcessed;
    private float $duration;
    private string $message;
    
    public function __construct(bool $success, int $recordsProcessed, float $duration, string $message = '')
    {
        $this->success = $success;
        $this->recordsProcessed = $recordsProcessed;
        $this->duration = $duration;
        $this->message = $message;
    }
    
    public function isSuccess(): bool
    {
        return $this->success;
    }
    
    public function getRecordsProcessed(): int
    {
        return $this->recordsProcessed;
    }
    
    public function getDuration(): float
    {
        return $this->duration;
    }
    
    public function getMessage(): string
    {
        return $this->message;
    }
}