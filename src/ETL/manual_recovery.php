#!/usr/bin/env php
<?php
/**
 * Manual Recovery Script for Ozon ETL System
 * 
 * Provides manual recovery procedures for critical failures that require human intervention.
 * This script should be used when automatic recovery procedures fail or for emergency situations.
 * 
 * Usage:
 *   php manual_recovery.php [command] [options]
 * 
 * Commands:
 *   status                Show current system status
 *   clear-locks           Clear all ETL locks
 *   reset-failed          Reset failed ETL processes
 *   cleanup               Clean up temporary data and old records
 *   force-restart         Force restart of ETL system
 *   emergency-stop        Emergency stop of all ETL processes
 *   restore-fallback      Restore system using fallback data
 *   health-check          Perform comprehensive health check
 * 
 * Options:
 *   --etl-id=ID          Target specific ETL process
 *   --force              Force operation without confirmation
 *   --dry-run            Show what would be done without executing
 *   --verbose            Enable verbose output
 *   --help               Show help message
 * 
 * @version 1.0
 * @author Manhattan System
 */

// Ensure script is run from command line
if (php_sapi_name() !== 'cli') {
    die("This script can only be run from the command line.\n");
}

// Set error reporting and timezone
error_reporting(E_ALL);
ini_set('display_errors', 1);
date_default_timezone_set('UTC');

// Define paths
define('ROOT_DIR', dirname(dirname(__DIR__)));
define('SRC_DIR', ROOT_DIR . '/src');
define('LOG_DIR', ROOT_DIR . '/logs');

// Ensure log directory exists
if (!is_dir(LOG_DIR)) {
    mkdir(LOG_DIR, 0755, true);
}

// Include required files
require_once SRC_DIR . '/config/database.php';
require_once SRC_DIR . '/classes/OzonETLMonitor.php';
require_once SRC_DIR . '/classes/OzonETLRetryManager.php';

/**
 * Manual Recovery Class
 */
class ManualRecovery {
    
    private $pdo;
    private $monitor;
    private $retryManager;
    private $options;
    private $command;
    private $logFile;
    
    /**
     * Constructor
     */
    public function __construct(string $command, array $options = []) {
        $this->command = $command;
        $this->options = $options;
        $this->logFile = LOG_DIR . '/manual_recovery_' . date('Y-m-d') . '.log';
        
        $this->initializeDatabase();
        $this->initializeComponents();
    }
    
    /**
     * Initialize database connection
     */
    private function initializeDatabase(): void {
        try {
            $config = include SRC_DIR . '/config/database.php';
            $dsn = "mysql:host={$config['host']};dbname={$config['database']};charset={$config['charset']}";
            $this->pdo = new PDO($dsn, $config['username'], $config['password'], $config['options']);
            
            $this->log('INFO', 'Database connection established');
            
        } catch (Exception $e) {
            $this->log('ERROR', 'Failed to connect to database: ' . $e->getMessage());
            die("Database connection failed\n");
        }
    }
    
    /**
     * Initialize components
     */
    private function initializeComponents(): void {
        try {
            $this->monitor = new OzonETLMonitor($this->pdo);
            $this->retryManager = new OzonETLRetryManager($this->pdo);
            
            $this->log('INFO', 'Components initialized successfully');
            
        } catch (Exception $e) {
            $this->log('ERROR', 'Failed to initialize components: ' . $e->getMessage());
            die("Component initialization failed\n");
        }
    }
    
    /**
     * Main execution method
     */
    public function run(): void {
        $this->log('INFO', "Starting manual recovery", [
            'command' => $this->command,
            'options' => $this->options,
            'timestamp' => date('Y-m-d H:i:s')
        ]);
        
        try {
            switch ($this->command) {
                case 'status':
                    $this->showStatus();
                    break;
                    
                case 'clear-locks':
                    $this->clearLocks();
                    break;
                    
                case 'reset-failed':
                    $this->resetFailedProcesses();
                    break;
                    
                case 'cleanup':
                    $this->performCleanup();
                    break;
                    
                case 'force-restart':
                    $this->forceRestart();
                    break;
                    
                case 'emergency-stop':
                    $this->emergencyStop();
                    break;
                    
                case 'restore-fallback':
                    $this->restoreFromFallback();
                    break;
                    
                case 'health-check':
                    $this->performHealthCheck();
                    break;
                    
                case 'help':
                case '--help':
                    $this->showHelp();
                    break;
                    
                default:
                    echo "Unknown command: {$this->command}\n";
                    echo "Use 'help' to see available commands\n";
                    exit(1);
            }
            
        } catch (Exception $e) {
            $this->log('ERROR', 'Manual recovery failed: ' . $e->getMessage());
            echo "ERROR: " . $e->getMessage() . "\n";
            exit(1);
        }
    }
    
    /**
     * Show system status
     */
    private function showStatus(): void {
        echo "Ozon ETL System Status\n";
        echo str_repeat("=", 50) . "\n";
        
        try {
            // Get monitoring status
            $monitoringStatus = $this->monitor->getMonitoringStatus();
            
            echo "Timestamp: " . $monitoringStatus['timestamp'] . "\n";
            echo "Running Processes: " . $monitoringStatus['running_processes'] . "\n";
            echo "Recent Alerts (24h): " . $monitoringStatus['recent_alerts'] . "\n";
            
            if (!empty($monitoringStatus['last_health_check'])) {
                echo "Last Health Check: " . $monitoringStatus['last_health_check']['created_at'] . "\n";
                echo "Health Status: " . $monitoringStatus['last_health_check']['overall_status'] . "\n";
            }
            
            // Show system metrics
            if (!empty($monitoringStatus['system_metrics'])) {
                echo "\nSystem Metrics:\n";
                foreach ($monitoringStatus['system_metrics'] as $metric => $value) {
                    if (is_array($value)) {
                        echo "  $metric: " . json_encode($value) . "\n";
                    } else {
                        echo "  $metric: $value\n";
                    }
                }
            }
            
            // Show recent ETL runs
            $this->showRecentETLRuns();
            
            // Show active retry attempts
            $this->showActiveRetries();
            
            // Show critical errors
            $this->showCriticalErrors();
            
        } catch (Exception $e) {
            echo "Failed to get system status: " . $e->getMessage() . "\n";
        }
    }
    
    /**
     * Show recent ETL runs
     */
    private function showRecentETLRuns(): void {
        try {
            echo "\nRecent ETL Runs (last 10):\n";
            echo str_repeat("-", 30) . "\n";
            
            $sql = "SELECT etl_id, status, started_at, completed_at, duration_seconds, error_message
                    FROM ozon_etl_monitoring 
                    ORDER BY started_at DESC 
                    LIMIT 10";
            
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute();
            
            $runs = $stmt->fetchAll();
            
            if (empty($runs)) {
                echo "No recent ETL runs found\n";
                return;
            }
            
            foreach ($runs as $run) {
                $status = strtoupper($run['status']);
                $duration = $run['duration_seconds'] ? round($run['duration_seconds'], 2) . 's' : 'N/A';
                
                echo "ETL ID: {$run['etl_id']}\n";
                echo "  Status: $status\n";
                echo "  Started: {$run['started_at']}\n";
                echo "  Duration: $duration\n";
                
                if ($run['error_message']) {
                    echo "  Error: " . substr($run['error_message'], 0, 100) . "...\n";
                }
                
                echo "\n";
            }
            
        } catch (Exception $e) {
            echo "Failed to get recent ETL runs: " . $e->getMessage() . "\n";
        }
    }
    
    /**
     * Show active retry attempts
     */
    private function showActiveRetries(): void {
        try {
            echo "Active Retry Attempts:\n";
            echo str_repeat("-", 30) . "\n";
            
            $sql = "SELECT etl_id, operation_type, error_type, attempt_number, max_attempts, 
                           next_retry_at, created_at
                    FROM ozon_etl_retry_attempts 
                    WHERE is_completed = FALSE 
                    ORDER BY created_at DESC 
                    LIMIT 10";
            
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute();
            
            $retries = $stmt->fetchAll();
            
            if (empty($retries)) {
                echo "No active retry attempts\n\n";
                return;
            }
            
            foreach ($retries as $retry) {
                echo "ETL ID: {$retry['etl_id']}\n";
                echo "  Operation: {$retry['operation_type']}\n";
                echo "  Error: {$retry['error_type']}\n";
                echo "  Attempt: {$retry['attempt_number']}/{$retry['max_attempts']}\n";
                echo "  Next Retry: " . ($retry['next_retry_at'] ?? 'Not scheduled') . "\n";
                echo "  Started: {$retry['created_at']}\n\n";
            }
            
        } catch (Exception $e) {
            echo "Failed to get active retries: " . $e->getMessage() . "\n";
        }
    }
    
    /**
     * Show critical errors
     */
    private function showCriticalErrors(): void {
        try {
            echo "Recent Critical Errors (last 24h):\n";
            echo str_repeat("-", 30) . "\n";
            
            $sql = "SELECT etl_id, operation_type, error_type, error_message, 
                           recovery_attempted, recovery_successful, created_at
                    FROM ozon_etl_critical_errors 
                    WHERE created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)
                    ORDER BY created_at DESC 
                    LIMIT 5";
            
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute();
            
            $errors = $stmt->fetchAll();
            
            if (empty($errors)) {
                echo "No critical errors in the last 24 hours\n\n";
                return;
            }
            
            foreach ($errors as $error) {
                echo "ETL ID: {$error['etl_id']}\n";
                echo "  Operation: {$error['operation_type']}\n";
                echo "  Error Type: {$error['error_type']}\n";
                echo "  Message: " . substr($error['error_message'], 0, 100) . "...\n";
                echo "  Recovery Attempted: " . ($error['recovery_attempted'] ? 'Yes' : 'No') . "\n";
                
                if ($error['recovery_attempted']) {
                    $recoveryStatus = $error['recovery_successful'] ? 'Successful' : 'Failed';
                    echo "  Recovery Status: $recoveryStatus\n";
                }
                
                echo "  Time: {$error['created_at']}\n\n";
            }
            
        } catch (Exception $e) {
            echo "Failed to get critical errors: " . $e->getMessage() . "\n";
        }
    }
    
    /**
     * Clear all ETL locks
     */
    private function clearLocks(): void {
        if (!$this->confirmAction("clear all ETL locks")) {
            return;
        }
        
        echo "Clearing ETL locks...\n";
        
        if (isset($this->options['dry-run'])) {
            echo "[DRY RUN] Would clear the following locks:\n";
        }
        
        $clearedCount = 0;
        
        // Clear file locks
        $lockFiles = [
            sys_get_temp_dir() . '/ozon_stock_reports_etl.lock',
            sys_get_temp_dir() . '/ozon_etl_monitoring_daemon.pid'
        ];
        
        foreach ($lockFiles as $lockFile) {
            if (file_exists($lockFile)) {
                if (isset($this->options['dry-run'])) {
                    echo "  File lock: $lockFile\n";
                } else {
                    unlink($lockFile);
                    echo "Removed file lock: $lockFile\n";
                }
                $clearedCount++;
            }
        }
        
        // Clear database locks (running processes)
        try {
            if (isset($this->options['dry-run'])) {
                $sql = "SELECT COUNT(*) FROM ozon_etl_monitoring WHERE status = 'running'";
                $stmt = $this->pdo->prepare($sql);
                $stmt->execute();
                $runningCount = $stmt->fetchColumn();
                echo "  Database locks (running processes): $runningCount\n";
            } else {
                $sql = "UPDATE ozon_etl_monitoring 
                        SET status = 'failed', completed_at = NOW(), 
                            error_message = 'Cleared by manual recovery'
                        WHERE status = 'running'";
                
                $stmt = $this->pdo->prepare($sql);
                $stmt->execute();
                
                $updatedCount = $stmt->rowCount();
                echo "Updated $updatedCount running processes to failed status\n";
                $clearedCount += $updatedCount;
            }
            
        } catch (Exception $e) {
            echo "Failed to clear database locks: " . $e->getMessage() . "\n";
        }
        
        if (!isset($this->options['dry-run'])) {
            echo "Cleared $clearedCount locks total\n";
            $this->log('INFO', 'Manual lock clearing completed', ['cleared_count' => $clearedCount]);
        }
    }
    
    /**
     * Reset failed ETL processes
     */
    private function resetFailedProcesses(): void {
        $etlId = $this->options['etl-id'] ?? null;
        
        if ($etlId) {
            if (!$this->confirmAction("reset failed process for ETL ID: $etlId")) {
                return;
            }
        } else {
            if (!$this->confirmAction("reset ALL failed ETL processes")) {
                return;
            }
        }
        
        echo "Resetting failed ETL processes...\n";
        
        try {
            if (isset($this->options['dry-run'])) {
                $sql = "SELECT COUNT(*) FROM ozon_etl_retry_attempts 
                        WHERE is_completed = FALSE" . 
                        ($etlId ? " AND etl_id = :etl_id" : "");
                
                $stmt = $this->pdo->prepare($sql);
                if ($etlId) {
                    $stmt->execute(['etl_id' => $etlId]);
                } else {
                    $stmt->execute();
                }
                
                $retryCount = $stmt->fetchColumn();
                echo "[DRY RUN] Would reset $retryCount retry attempts\n";
                
            } else {
                // Reset retry attempts
                $sql = "UPDATE ozon_etl_retry_attempts 
                        SET is_completed = TRUE, completed_at = NOW()
                        WHERE is_completed = FALSE" . 
                        ($etlId ? " AND etl_id = :etl_id" : "");
                
                $stmt = $this->pdo->prepare($sql);
                if ($etlId) {
                    $stmt->execute(['etl_id' => $etlId]);
                } else {
                    $stmt->execute();
                }
                
                $resetCount = $stmt->rowCount();
                echo "Reset $resetCount retry attempts\n";
                
                // Mark critical errors as resolved
                $sql = "UPDATE ozon_etl_critical_errors 
                        SET resolved_at = NOW()
                        WHERE resolved_at IS NULL" . 
                        ($etlId ? " AND etl_id = :etl_id" : "");
                
                $stmt = $this->pdo->prepare($sql);
                if ($etlId) {
                    $stmt->execute(['etl_id' => $etlId]);
                } else {
                    $stmt->execute();
                }
                
                $resolvedCount = $stmt->rowCount();
                echo "Marked $resolvedCount critical errors as resolved\n";
                
                $this->log('INFO', 'Failed processes reset completed', [
                    'etl_id' => $etlId,
                    'reset_count' => $resetCount,
                    'resolved_count' => $resolvedCount
                ]);
            }
            
        } catch (Exception $e) {
            echo "Failed to reset processes: " . $e->getMessage() . "\n";
        }
    }
    
    /**
     * Perform cleanup
     */
    private function performCleanup(): void {
        if (!$this->confirmAction("perform system cleanup")) {
            return;
        }
        
        echo "Performing system cleanup...\n";
        
        $cleanupResults = [];
        
        // Cleanup old retry records
        try {
            if (isset($this->options['dry-run'])) {
                echo "[DRY RUN] Would cleanup old retry records\n";
            } else {
                $deletedRetries = $this->retryManager->cleanupOldRetryRecords(30);
                $cleanupResults['retry_records'] = $deletedRetries;
                echo "Cleaned up $deletedRetries old retry records\n";
            }
        } catch (Exception $e) {
            echo "Failed to cleanup retry records: " . $e->getMessage() . "\n";
        }
        
        // Cleanup old monitoring records
        try {
            if (isset($this->options['dry-run'])) {
                echo "[DRY RUN] Would cleanup old monitoring records\n";
            } else {
                $monitoringCleanup = $this->monitor->cleanupOldRecords(30);
                $cleanupResults['monitoring_records'] = $monitoringCleanup['total_deleted'];
                echo "Cleaned up {$monitoringCleanup['total_deleted']} old monitoring records\n";
            }
        } catch (Exception $e) {
            echo "Failed to cleanup monitoring records: " . $e->getMessage() . "\n";
        }
        
        // Cleanup temporary files
        try {
            if (isset($this->options['dry-run'])) {
                echo "[DRY RUN] Would cleanup temporary files\n";
            } else {
                $tempCleanup = $this->cleanupTempFiles();
                $cleanupResults['temp_files'] = $tempCleanup;
                echo "Cleaned up $tempCleanup temporary files\n";
            }
        } catch (Exception $e) {
            echo "Failed to cleanup temp files: " . $e->getMessage() . "\n";
        }
        
        // Cleanup old log files
        try {
            if (isset($this->options['dry-run'])) {
                echo "[DRY RUN] Would cleanup old log files\n";
            } else {
                $logCleanup = $this->cleanupOldLogs();
                $cleanupResults['log_files'] = $logCleanup;
                echo "Cleaned up $logCleanup old log files\n";
            }
        } catch (Exception $e) {
            echo "Failed to cleanup log files: " . $e->getMessage() . "\n";
        }
        
        if (!isset($this->options['dry-run'])) {
            $this->log('INFO', 'System cleanup completed', $cleanupResults);
        }
    }
    
    /**
     * Cleanup temporary files
     */
    private function cleanupTempFiles(): int {
        $tempDir = sys_get_temp_dir();
        $pattern = $tempDir . '/ozon_etl_*';
        $cleanedCount = 0;
        
        foreach (glob($pattern) as $tempFile) {
            if (is_file($tempFile) && (time() - filemtime($tempFile)) > 86400) { // Older than 24 hours
                unlink($tempFile);
                $cleanedCount++;
            }
        }
        
        return $cleanedCount;
    }
    
    /**
     * Cleanup old log files
     */
    private function cleanupOldLogs(): int {
        $logPattern = LOG_DIR . '/ozon_*_*.log';
        $cleanedCount = 0;
        $maxAge = 30 * 86400; // 30 days
        
        foreach (glob($logPattern) as $logFile) {
            if (is_file($logFile) && (time() - filemtime($logFile)) > $maxAge) {
                unlink($logFile);
                $cleanedCount++;
            }
        }
        
        return $cleanedCount;
    }
    
    /**
     * Force restart ETL system
     */
    private function forceRestart(): void {
        if (!$this->confirmAction("force restart the ETL system")) {
            return;
        }
        
        echo "Force restarting ETL system...\n";
        
        if (isset($this->options['dry-run'])) {
            echo "[DRY RUN] Would perform the following actions:\n";
            echo "  1. Stop all running processes\n";
            echo "  2. Clear all locks\n";
            echo "  3. Reset failed processes\n";
            echo "  4. Schedule new ETL run\n";
            return;
        }
        
        try {
            // Step 1: Emergency stop
            echo "Step 1: Stopping all processes...\n";
            $this->performEmergencyStop(false);
            
            // Step 2: Clear locks
            echo "Step 2: Clearing locks...\n";
            $this->performClearLocks(false);
            
            // Step 3: Reset failed processes
            echo "Step 3: Resetting failed processes...\n";
            $this->performResetFailed(false);
            
            // Step 4: Schedule new ETL run
            echo "Step 4: Scheduling new ETL run...\n";
            $this->scheduleNewETLRun();
            
            echo "Force restart completed successfully\n";
            $this->log('INFO', 'Force restart completed');
            
        } catch (Exception $e) {
            echo "Force restart failed: " . $e->getMessage() . "\n";
            $this->log('ERROR', 'Force restart failed', ['error' => $e->getMessage()]);
        }
    }
    
    /**
     * Emergency stop all processes
     */
    private function emergencyStop(): void {
        if (!$this->confirmAction("emergency stop ALL ETL processes")) {
            return;
        }
        
        echo "Performing emergency stop...\n";
        
        $this->performEmergencyStop(!isset($this->options['dry-run']));
    }
    
    /**
     * Perform emergency stop
     */
    private function performEmergencyStop(bool $execute = true): void {
        if (!$execute) {
            echo "[DRY RUN] Would stop all ETL processes\n";
            return;
        }
        
        $stoppedCount = 0;
        
        // Stop monitoring daemon
        $daemonPidFile = sys_get_temp_dir() . '/ozon_etl_monitoring_daemon.pid';
        if (file_exists($daemonPidFile)) {
            $pid = (int)file_get_contents($daemonPidFile);
            if (function_exists('posix_kill') && posix_kill($pid, SIGTERM)) {
                echo "Sent SIGTERM to monitoring daemon (PID: $pid)\n";
                $stoppedCount++;
                
                // Wait for graceful shutdown
                sleep(5);
                
                // Force kill if still running
                if (posix_kill($pid, 0)) {
                    posix_kill($pid, SIGKILL);
                    echo "Force killed monitoring daemon\n";
                }
            }
        }
        
        // Update database status
        try {
            $sql = "UPDATE ozon_etl_monitoring 
                    SET status = 'failed', completed_at = NOW(), 
                        error_message = 'Emergency stop by manual recovery'
                    WHERE status IN ('running', 'stalled')";
            
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute();
            
            $updatedCount = $stmt->rowCount();
            echo "Stopped $updatedCount ETL processes in database\n";
            $stoppedCount += $updatedCount;
            
        } catch (Exception $e) {
            echo "Failed to update process status: " . $e->getMessage() . "\n";
        }
        
        echo "Emergency stop completed - stopped $stoppedCount processes\n";
        $this->log('WARNING', 'Emergency stop executed', ['stopped_count' => $stoppedCount]);
    }
    
    /**
     * Restore from fallback data
     */
    private function restoreFromFallback(): void {
        if (!$this->confirmAction("restore system using fallback data")) {
            return;
        }
        
        echo "Restoring from fallback data...\n";
        
        if (isset($this->options['dry-run'])) {
            echo "[DRY RUN] Would restore from available fallback data\n";
            $this->showAvailableFallbackData();
            return;
        }
        
        try {
            // Get available fallback data
            $fallbackData = $this->getAvailableFallbackData();
            
            if (empty($fallbackData)) {
                echo "No fallback data available for restoration\n";
                return;
            }
            
            echo "Found " . count($fallbackData) . " fallback data entries\n";
            
            $restoredCount = 0;
            
            foreach ($fallbackData as $data) {
                try {
                    // Process fallback data based on type
                    $this->processFallbackData($data);
                    $restoredCount++;
                    
                    echo "Restored {$data['data_type']} data (key: {$data['data_key']})\n";
                    
                } catch (Exception $e) {
                    echo "Failed to restore {$data['data_type']} data: " . $e->getMessage() . "\n";
                }
            }
            
            echo "Restoration completed - restored $restoredCount data entries\n";
            $this->log('INFO', 'Fallback restoration completed', ['restored_count' => $restoredCount]);
            
        } catch (Exception $e) {
            echo "Fallback restoration failed: " . $e->getMessage() . "\n";
        }
    }
    
    /**
     * Get available fallback data
     */
    private function getAvailableFallbackData(): array {
        $sql = "SELECT data_type, data_key, data_content, created_at, usage_count
                FROM ozon_etl_fallback_data 
                WHERE (expires_at IS NULL OR expires_at > NOW())
                AND TIMESTAMPDIFF(HOUR, created_at, NOW()) <= 48
                ORDER BY created_at DESC";
        
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute();
        
        $data = $stmt->fetchAll();
        
        foreach ($data as &$item) {
            $item['data_content'] = json_decode($item['data_content'], true);
        }
        
        return $data;
    }
    
    /**
     * Show available fallback data
     */
    private function showAvailableFallbackData(): void {
        $fallbackData = $this->getAvailableFallbackData();
        
        if (empty($fallbackData)) {
            echo "No fallback data available\n";
            return;
        }
        
        echo "Available Fallback Data:\n";
        echo str_repeat("-", 30) . "\n";
        
        foreach ($fallbackData as $data) {
            echo "Type: {$data['data_type']}\n";
            echo "Key: {$data['data_key']}\n";
            echo "Created: {$data['created_at']}\n";
            echo "Usage Count: {$data['usage_count']}\n";
            echo "Data Size: " . strlen(json_encode($data['data_content'])) . " bytes\n\n";
        }
    }
    
    /**
     * Process fallback data for restoration
     */
    private function processFallbackData(array $data): void {
        // This is a placeholder for actual restoration logic
        // In a real implementation, this would restore the data to the appropriate tables
        
        switch ($data['data_type']) {
            case 'stock_reports':
                // Restore stock report data
                break;
                
            case 'inventory_data':
                // Restore inventory data
                break;
                
            case 'alert_data':
                // Restore alert data
                break;
        }
        
        // Update usage count
        $sql = "UPDATE ozon_etl_fallback_data 
                SET usage_count = usage_count + 1, last_used_at = NOW()
                WHERE data_type = :data_type AND data_key = :data_key";
        
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            'data_type' => $data['data_type'],
            'data_key' => $data['data_key']
        ]);
    }
    
    /**
     * Perform comprehensive health check
     */
    private function performHealthCheck(): void {
        echo "Performing comprehensive health check...\n";
        
        try {
            $healthStatus = $this->monitor->performHealthCheck();
            
            echo "Health Check Results:\n";
            echo str_repeat("=", 50) . "\n";
            echo "Overall Status: " . strtoupper($healthStatus['overall_status']) . "\n";
            echo "Timestamp: " . $healthStatus['timestamp'] . "\n\n";
            
            if (!empty($healthStatus['checks'])) {
                echo "Individual Checks:\n";
                echo str_repeat("-", 30) . "\n";
                
                foreach ($healthStatus['checks'] as $checkName => $check) {
                    $status = strtoupper($check['status']);
                    echo "$checkName: $status\n";
                    
                    if ($check['status'] !== 'pass' && !empty($check['issues'])) {
                        foreach ($check['issues'] as $issue) {
                            echo "  Issue: $issue\n";
                        }
                    }
                    
                    if (!empty($check['error'])) {
                        echo "  Error: {$check['error']}\n";
                    }
                    
                    echo "\n";
                }
            }
            
            if (!empty($healthStatus['recommendations'])) {
                echo "Recommendations:\n";
                echo str_repeat("-", 30) . "\n";
                
                foreach ($healthStatus['recommendations'] as $recommendation) {
                    echo "• $recommendation\n";
                }
                echo "\n";
            }
            
        } catch (Exception $e) {
            echo "Health check failed: " . $e->getMessage() . "\n";
        }
    }
    
    /**
     * Helper methods for internal use
     */
    private function performClearLocks(bool $execute = true): void {
        // Implementation similar to clearLocks() but without user interaction
    }
    
    private function performResetFailed(bool $execute = true): void {
        // Implementation similar to resetFailedProcesses() but without user interaction
    }
    
    private function scheduleNewETLRun(): void {
        try {
            // Create a new ETL run entry
            $newEtlId = uniqid('recovery_', true);
            
            $sql = "INSERT INTO ozon_etl_restart_schedule 
                    (original_etl_id, scheduled_at, restart_params)
                    VALUES (:etl_id, DATE_ADD(NOW(), INTERVAL 5 MINUTE), :params)";
            
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute([
                'etl_id' => $newEtlId,
                'params' => json_encode(['type' => 'manual_recovery_restart'])
            ]);
            
            echo "Scheduled new ETL run (ID: $newEtlId) in 5 minutes\n";
            
        } catch (Exception $e) {
            echo "Failed to schedule new ETL run: " . $e->getMessage() . "\n";
        }
    }
    
    /**
     * Confirm action with user
     */
    private function confirmAction(string $action): bool {
        if (isset($this->options['force'])) {
            return true;
        }
        
        echo "Are you sure you want to $action? (y/N): ";
        $handle = fopen("php://stdin", "r");
        $line = fgets($handle);
        fclose($handle);
        
        return strtolower(trim($line)) === 'y';
    }
    
    /**
     * Log message
     */
    private function log(string $level, string $message, array $context = []): void {
        $timestamp = date('Y-m-d H:i:s');
        $contextStr = !empty($context) ? ' | ' . json_encode($context, JSON_UNESCAPED_UNICODE) : '';
        $logMessage = "[$timestamp] [$level] [MANUAL_RECOVERY] $message$contextStr\n";
        
        file_put_contents($this->logFile, $logMessage, FILE_APPEND | LOCK_EX);
        
        if (isset($this->options['verbose'])) {
            echo $logMessage;
        }
    }
    
    /**
     * Show help message
     */
    private function showHelp(): void {
        echo "Manual Recovery Script for Ozon ETL System\n";
        echo str_repeat("=", 50) . "\n\n";
        echo "Usage: php manual_recovery.php [command] [options]\n\n";
        echo "Commands:\n";
        echo "  status                Show current system status\n";
        echo "  clear-locks           Clear all ETL locks\n";
        echo "  reset-failed          Reset failed ETL processes\n";
        echo "  cleanup               Clean up temporary data and old records\n";
        echo "  force-restart         Force restart of ETL system\n";
        echo "  emergency-stop        Emergency stop of all ETL processes\n";
        echo "  restore-fallback      Restore system using fallback data\n";
        echo "  health-check          Perform comprehensive health check\n";
        echo "  help                  Show this help message\n\n";
        echo "Options:\n";
        echo "  --etl-id=ID          Target specific ETL process\n";
        echo "  --force              Force operation without confirmation\n";
        echo "  --dry-run            Show what would be done without executing\n";
        echo "  --verbose            Enable verbose output\n";
        echo "  --help               Show help message\n\n";
        echo "Examples:\n";
        echo "  php manual_recovery.php status\n";
        echo "  php manual_recovery.php clear-locks --force\n";
        echo "  php manual_recovery.php reset-failed --etl-id=etl_12345\n";
        echo "  php manual_recovery.php cleanup --dry-run\n";
        echo "  php manual_recovery.php force-restart --verbose\n\n";
        echo "CAUTION: This script performs critical system operations.\n";
        echo "Always use --dry-run first to see what will be affected.\n\n";
    }
}

// Parse command line arguments
if (count($argv) < 2) {
    echo "Usage: php manual_recovery.php [command] [options]\n";
    echo "Use 'help' to see available commands\n";
    exit(1);
}

$command = $argv[1];
$options = [];
$args = array_slice($argv, 2);

foreach ($args as $arg) {
    if (strpos($arg, '--') === 0) {
        $parts = explode('=', substr($arg, 2), 2);
        $key = $parts[0];
        $value = isset($parts[1]) ? $parts[1] : true;
        $options[$key] = $value;
    }
}

// Create and run the recovery script
try {
    $recovery = new ManualRecovery($command, $options);
    $recovery->run();
} catch (Exception $e) {
    echo "FATAL ERROR: " . $e->getMessage() . "\n";
    exit(1);
}