<?php

declare(strict_types=1);

namespace MiCore\ETL\Ozon\Core;

/**
 * Process Lock Manager
 * 
 * Provides file-based locking mechanism to prevent concurrent execution
 * of ETL processes. Includes stale lock detection and cleanup.
 * 
 * Requirements addressed:
 * - 5.5: Prevent concurrent execution of the same ETL process
 */
class ProcessLock
{
    private string $lockDir;
    private string $lockFile;
    private string $processName;
    private ?resource $lockHandle = null;
    private Logger $logger;
    
    public function __construct(string $processName, string $lockDir, Logger $logger)
    {
        $this->processName = $processName;
        $this->lockDir = rtrim($lockDir, '/');
        $this->lockFile = $this->lockDir . '/' . $processName . '.lock';
        $this->logger = $logger;
        
        // Ensure lock directory exists
        if (!is_dir($this->lockDir)) {
            mkdir($this->lockDir, 0755, true);
        }
    }
    
    /**
     * Acquire exclusive lock for the process
     * 
     * @return bool True if lock acquired successfully, false if another process is running
     * @throws \RuntimeException If lock file cannot be created or accessed
     */
    public function acquire(): bool
    {
        try {
            // Check for existing lock
            if ($this->isLocked()) {
                $existingPid = $this->getLockedPid();
                
                if ($existingPid && $this->isProcessRunning($existingPid)) {
                    $this->logger->warning('Process lock already held by running process', [
                        'process_name' => $this->processName,
                        'existing_pid' => $existingPid,
                        'current_pid' => getmypid()
                    ]);
                    return false;
                } else {
                    // Stale lock file - remove it
                    $this->logger->info('Removing stale lock file', [
                        'process_name' => $this->processName,
                        'stale_pid' => $existingPid,
                        'lock_file' => $this->lockFile
                    ]);
                    $this->release();
                }
            }
            
            // Create lock file with exclusive access
            $this->lockHandle = fopen($this->lockFile, 'c+');
            if (!$this->lockHandle) {
                throw new \RuntimeException("Cannot create lock file: {$this->lockFile}");
            }
            
            // Try to acquire exclusive lock
            if (!flock($this->lockHandle, LOCK_EX | LOCK_NB)) {
                fclose($this->lockHandle);
                $this->lockHandle = null;
                return false;
            }
            
            // Write process information to lock file
            $lockData = [
                'pid' => getmypid(),
                'process_name' => $this->processName,
                'started_at' => date('Y-m-d H:i:s'),
                'hostname' => gethostname(),
                'user' => get_current_user(),
                'php_version' => PHP_VERSION,
                'memory_limit' => ini_get('memory_limit'),
                'max_execution_time' => ini_get('max_execution_time')
            ];
            
            ftruncate($this->lockHandle, 0);
            rewind($this->lockHandle);
            fwrite($this->lockHandle, json_encode($lockData, JSON_PRETTY_PRINT));
            fflush($this->lockHandle);
            
            $this->logger->info('Process lock acquired successfully', [
                'process_name' => $this->processName,
                'pid' => getmypid(),
                'lock_file' => $this->lockFile
            ]);
            
            // Register shutdown function to ensure lock is released
            register_shutdown_function([$this, 'release']);
            
            return true;
            
        } catch (\Exception $e) {
            $this->logger->error('Failed to acquire process lock', [
                'process_name' => $this->processName,
                'error' => $e->getMessage(),
                'lock_file' => $this->lockFile
            ]);
            throw new \RuntimeException("Failed to acquire lock: " . $e->getMessage(), 0, $e);
        }
    }
    
    /**
     * Release the process lock
     * 
     * @return bool True if lock released successfully
     */
    public function release(): bool
    {
        try {
            if ($this->lockHandle) {
                flock($this->lockHandle, LOCK_UN);
                fclose($this->lockHandle);
                $this->lockHandle = null;
            }
            
            if (file_exists($this->lockFile)) {
                unlink($this->lockFile);
            }
            
            $this->logger->info('Process lock released', [
                'process_name' => $this->processName,
                'pid' => getmypid()
            ]);
            
            return true;
            
        } catch (\Exception $e) {
            $this->logger->error('Failed to release process lock', [
                'process_name' => $this->processName,
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }
    
    /**
     * Check if process is currently locked
     * 
     * @return bool True if lock file exists and is valid
     */
    public function isLocked(): bool
    {
        return file_exists($this->lockFile) && is_readable($this->lockFile);
    }
    
    /**
     * Get PID of the process holding the lock
     * 
     * @return int|null PID if found, null if lock file is invalid
     */
    public function getLockedPid(): ?int
    {
        if (!$this->isLocked()) {
            return null;
        }
        
        try {
            $lockContent = file_get_contents($this->lockFile);
            $lockData = json_decode($lockContent, true);
            
            return isset($lockData['pid']) ? (int)$lockData['pid'] : null;
            
        } catch (\Exception $e) {
            $this->logger->warning('Failed to read lock file', [
                'lock_file' => $this->lockFile,
                'error' => $e->getMessage()
            ]);
            return null;
        }
    }
    
    /**
     * Get detailed information about the current lock
     * 
     * @return array|null Lock information or null if not locked
     */
    public function getLockInfo(): ?array
    {
        if (!$this->isLocked()) {
            return null;
        }
        
        try {
            $lockContent = file_get_contents($this->lockFile);
            $lockData = json_decode($lockContent, true);
            
            if ($lockData) {
                $lockData['lock_file'] = $this->lockFile;
                $lockData['lock_age_seconds'] = time() - filemtime($this->lockFile);
                $lockData['is_process_running'] = $this->isProcessRunning($lockData['pid'] ?? 0);
            }
            
            return $lockData;
            
        } catch (\Exception $e) {
            $this->logger->warning('Failed to get lock info', [
                'lock_file' => $this->lockFile,
                'error' => $e->getMessage()
            ]);
            return null;
        }
    }
    
    /**
     * Check if a process with given PID is running
     * 
     * @param int $pid Process ID to check
     * @return bool True if process is running
     */
    private function isProcessRunning(int $pid): bool
    {
        if ($pid <= 0) {
            return false;
        }
        
        // Use posix_kill with signal 0 to check if process exists
        if (function_exists('posix_kill')) {
            return posix_kill($pid, 0);
        }
        
        // Fallback for systems without posix extension
        if (PHP_OS_FAMILY === 'Windows') {
            $output = shell_exec("tasklist /FI \"PID eq {$pid}\" 2>NUL");
            return $output && strpos($output, (string)$pid) !== false;
        } else {
            $output = shell_exec("ps -p {$pid} 2>/dev/null");
            return $output && strpos($output, (string)$pid) !== false;
        }
    }
    
    /**
     * Force remove lock file (use with caution)
     * 
     * @return bool True if lock removed successfully
     */
    public function forceRelease(): bool
    {
        try {
            if (file_exists($this->lockFile)) {
                unlink($this->lockFile);
                
                $this->logger->warning('Process lock force removed', [
                    'process_name' => $this->processName,
                    'lock_file' => $this->lockFile
                ]);
                
                return true;
            }
            
            return true;
            
        } catch (\Exception $e) {
            $this->logger->error('Failed to force remove lock', [
                'process_name' => $this->processName,
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }
    
    /**
     * Clean up stale lock files in the lock directory
     * 
     * @param int $maxAgeSeconds Maximum age of lock files to keep (default: 24 hours)
     * @return int Number of stale locks removed
     */
    public static function cleanupStaleLocks(string $lockDir, Logger $logger, int $maxAgeSeconds = 86400): int
    {
        $cleaned = 0;
        
        try {
            if (!is_dir($lockDir)) {
                return 0;
            }
            
            $lockFiles = glob($lockDir . '/*.lock');
            
            foreach ($lockFiles as $lockFile) {
                $lockAge = time() - filemtime($lockFile);
                
                if ($lockAge > $maxAgeSeconds) {
                    // Check if process is still running
                    $lockContent = file_get_contents($lockFile);
                    $lockData = json_decode($lockContent, true);
                    
                    if ($lockData && isset($lockData['pid'])) {
                        $pid = (int)$lockData['pid'];
                        
                        // Create temporary ProcessLock instance to check if process is running
                        $tempLock = new self('temp', $lockDir, $logger);
                        
                        if (!$tempLock->isProcessRunning($pid)) {
                            unlink($lockFile);
                            $cleaned++;
                            
                            $logger->info('Removed stale lock file', [
                                'lock_file' => $lockFile,
                                'age_seconds' => $lockAge,
                                'stale_pid' => $pid
                            ]);
                        }
                    } else {
                        // Invalid lock file format - remove it
                        unlink($lockFile);
                        $cleaned++;
                        
                        $logger->info('Removed invalid lock file', [
                            'lock_file' => $lockFile,
                            'age_seconds' => $lockAge
                        ]);
                    }
                }
            }
            
        } catch (\Exception $e) {
            $logger->error('Failed to cleanup stale locks', [
                'lock_dir' => $lockDir,
                'error' => $e->getMessage()
            ]);
        }
        
        return $cleaned;
    }
    
    /**
     * Get list of all active locks in the directory
     * 
     * @return array Array of lock information
     */
    public static function getActiveLocks(string $lockDir, Logger $logger): array
    {
        $activeLocks = [];
        
        try {
            if (!is_dir($lockDir)) {
                return [];
            }
            
            $lockFiles = glob($lockDir . '/*.lock');
            
            foreach ($lockFiles as $lockFile) {
                $processName = basename($lockFile, '.lock');
                $tempLock = new self($processName, $lockDir, $logger);
                
                $lockInfo = $tempLock->getLockInfo();
                if ($lockInfo) {
                    $activeLocks[] = $lockInfo;
                }
            }
            
        } catch (\Exception $e) {
            $logger->error('Failed to get active locks', [
                'lock_dir' => $lockDir,
                'error' => $e->getMessage()
            ]);
        }
        
        return $activeLocks;
    }
}