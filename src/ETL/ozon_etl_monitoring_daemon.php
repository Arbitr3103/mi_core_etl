#!/usr/bin/env php
<?php
/**
 * Ozon ETL Monitoring Daemon
 * 
 * Continuous monitoring daemon for Ozon Stock Reports ETL system.
 * Runs health checks at regular intervals and handles alerting for system issues.
 * 
 * Usage:
 *   php ozon_etl_monitoring_daemon.php [options]
 * 
 * Options:
 *   --interval=SECONDS    Health check interval in seconds (default: 300)
 *   --daemon              Run as daemon (background process)
 *   --stop                Stop running daemon
 *   --status              Show daemon status
 *   --verbose             Enable verbose logging
 *   --help                Show help message
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

// Define paths and constants
define('ROOT_DIR', dirname(dirname(__DIR__)));
define('SRC_DIR', ROOT_DIR . '/src');
define('LOG_DIR', ROOT_DIR . '/logs');
define('PID_FILE', sys_get_temp_dir() . '/ozon_etl_monitoring_daemon.pid');
define('DAEMON_LOG_FILE', LOG_DIR . '/ozon_etl_monitoring_daemon.log');

// Ensure log directory exists
if (!is_dir(LOG_DIR)) {
    mkdir(LOG_DIR, 0755, true);
}

// Include required files
require_once SRC_DIR . '/config/database.php';
require_once SRC_DIR . '/classes/OzonETLMonitor.php';

/**
 * Monitoring Daemon Class
 */
class OzonETLMonitoringDaemon {
    
    private $options;
    private $pdo;
    private $monitor;
    private $running = false;
    private $checkInterval = 300; // 5 minutes default
    private $startTime;
    private $checksPerformed = 0;
    private $alertsSent = 0;
    
    /**
     * Constructor
     */
    public function __construct(array $options = []) {
        $this->options = $options;
        $this->startTime = time();
        
        // Set check interval from options
        if (isset($options['interval'])) {
            $this->checkInterval = max(60, (int)$options['interval']); // Minimum 1 minute
        }
        
        $this->initializeDatabase();
        $this->initializeMonitor();
        $this->setupSignalHandlers();
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
            exit(1);
        }
    }
    
    /**
     * Initialize ETL monitor
     */
    private function initializeMonitor(): void {
        try {
            $monitorConfig = [
                'enable_notifications' => true,
                'notification_channels' => ['log', 'database'],
                'alert_cooldown_minutes' => 30 // Reduced cooldown for daemon
            ];
            
            $this->monitor = new OzonETLMonitor($this->pdo, $monitorConfig);
            $this->log('INFO', 'ETL Monitor initialized');
            
        } catch (Exception $e) {
            $this->log('ERROR', 'Failed to initialize ETL Monitor: ' . $e->getMessage());
            exit(1);
        }
    }
    
    /**
     * Setup signal handlers for graceful shutdown
     */
    private function setupSignalHandlers(): void {
        if (function_exists('pcntl_signal')) {
            pcntl_signal(SIGTERM, [$this, 'handleShutdownSignal']);
            pcntl_signal(SIGINT, [$this, 'handleShutdownSignal']);
            pcntl_signal(SIGHUP, [$this, 'handleReloadSignal']);
        }
    }
    
    /**
     * Handle shutdown signals
     */
    public function handleShutdownSignal($signal): void {
        $this->log('INFO', "Received shutdown signal ($signal), stopping daemon gracefully");
        $this->running = false;
    }
    
    /**
     * Handle reload signal
     */
    public function handleReloadSignal($signal): void {
        $this->log('INFO', "Received reload signal ($signal), reloading configuration");
        // Reload configuration if needed
    }
    
    /**
     * Main execution method
     */
    public function run(): void {
        // Handle different command options
        if (isset($this->options['help'])) {
            $this->showHelp();
            return;
        }
        
        if (isset($this->options['status'])) {
            $this->showStatus();
            return;
        }
        
        if (isset($this->options['stop'])) {
            $this->stopDaemon();
            return;
        }
        
        // Check if daemon is already running
        if ($this->isDaemonRunning()) {
            echo "Daemon is already running (PID: " . $this->getRunningPID() . ")\n";
            exit(1);
        }
        
        // Start daemon
        if (isset($this->options['daemon'])) {
            $this->startAsDaemon();
        } else {
            $this->startInForeground();
        }
    }
    
    /**
     * Start daemon in background
     */
    private function startAsDaemon(): void {
        $this->log('INFO', 'Starting monitoring daemon in background mode');
        
        // Fork process
        $pid = pcntl_fork();
        
        if ($pid == -1) {
            die("Could not fork process\n");
        } elseif ($pid) {
            // Parent process - exit
            echo "Daemon started with PID: $pid\n";
            exit(0);
        }
        
        // Child process continues
        $this->becomeDaemon();
        $this->startMonitoring();
    }
    
    /**
     * Start daemon in foreground (for debugging)
     */
    private function startInForeground(): void {
        $this->log('INFO', 'Starting monitoring daemon in foreground mode');
        $this->writePIDFile();
        $this->startMonitoring();
    }
    
    /**
     * Become a proper daemon process
     */
    private function becomeDaemon(): void {
        // Create new session
        if (posix_setsid() == -1) {
            die("Could not create new session\n");
        }
        
        // Change working directory
        chdir('/');
        
        // Set file permissions mask
        umask(0);
        
        // Close standard file descriptors
        fclose(STDIN);
        fclose(STDOUT);
        fclose(STDERR);
        
        // Redirect standard streams to /dev/null
        $stdin = fopen('/dev/null', 'r');
        $stdout = fopen('/dev/null', 'w');
        $stderr = fopen('/dev/null', 'w');
        
        $this->writePIDFile();
    }
    
    /**
     * Start the monitoring loop
     */
    private function startMonitoring(): void {
        $this->running = true;
        $this->log('INFO', "Monitoring daemon started", [
            'pid' => getmypid(),
            'check_interval' => $this->checkInterval,
            'start_time' => date('Y-m-d H:i:s')
        ]);
        
        while ($this->running) {
            try {
                $this->performHealthCheck();
                $this->checksPerformed++;
                
                // Sleep until next check
                $this->sleepWithSignalHandling($this->checkInterval);
                
            } catch (Exception $e) {
                $this->log('ERROR', 'Error during monitoring cycle: ' . $e->getMessage());
                
                // Sleep shorter time on error to retry sooner
                $this->sleepWithSignalHandling(min(60, $this->checkInterval / 5));
            }
        }
        
        $this->shutdown();
    }
    
    /**
     * Perform health check cycle
     */
    private function performHealthCheck(): void {
        $startTime = microtime(true);
        
        try {
            $this->log('DEBUG', 'Starting health check cycle');
            
            // Perform comprehensive health check
            $healthStatus = $this->monitor->performHealthCheck();
            
            $duration = microtime(true) - $startTime;
            
            // Log results
            $this->log('INFO', 'Health check completed', [
                'overall_status' => $healthStatus['overall_status'],
                'duration_seconds' => round($duration, 2),
                'checks_count' => count($healthStatus['checks'] ?? [])
            ]);
            
            // Handle alerts if any issues found
            if ($healthStatus['overall_status'] !== 'healthy') {
                $this->handleHealthIssues($healthStatus);
            }
            
            // Update daemon statistics
            $this->updateDaemonStats($healthStatus);
            
        } catch (Exception $e) {
            $this->log('ERROR', 'Health check failed: ' . $e->getMessage());
            throw $e;
        }
    }
    
    /**
     * Handle health issues found during check
     */
    private function handleHealthIssues(array $healthStatus): void {
        $issueCount = 0;
        
        if (!empty($healthStatus['checks'])) {
            foreach ($healthStatus['checks'] as $checkName => $check) {
                if ($check['status'] === 'fail' || $check['status'] === 'error') {
                    $issueCount++;
                    
                    $this->log('WARNING', "Health check issue detected", [
                        'check' => $checkName,
                        'status' => $check['status'],
                        'details' => $check
                    ]);
                }
            }
        }
        
        if ($issueCount > 0) {
            $this->alertsSent++;
            
            // Send consolidated alert for multiple issues
            $this->sendConsolidatedAlert($healthStatus, $issueCount);
        }
    }
    
    /**
     * Send consolidated alert for multiple health issues
     */
    private function sendConsolidatedAlert(array $healthStatus, int $issueCount): void {
        $alertMessage = "ETL Health Check found $issueCount issue(s)";
        
        $context = [
            'overall_status' => $healthStatus['overall_status'],
            'issue_count' => $issueCount,
            'daemon_stats' => [
                'uptime_hours' => round((time() - $this->startTime) / 3600, 2),
                'checks_performed' => $this->checksPerformed,
                'alerts_sent' => $this->alertsSent
            ]
        ];
        
        $this->log('WARNING', $alertMessage, $context);
        
        // TODO: Send actual notifications (email, webhook, etc.)
        // This could integrate with external alerting systems
    }
    
    /**
     * Update daemon statistics
     */
    private function updateDaemonStats(array $healthStatus): void {
        try {
            // Create/update daemon stats table
            $this->pdo->exec("
                CREATE TABLE IF NOT EXISTS ozon_etl_daemon_stats (
                    id INT PRIMARY KEY DEFAULT 1,
                    pid INT NOT NULL,
                    started_at TIMESTAMP NOT NULL,
                    last_check_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    checks_performed INT DEFAULT 0,
                    alerts_sent INT DEFAULT 0,
                    uptime_seconds INT DEFAULT 0,
                    last_health_status ENUM('healthy', 'warning', 'error') DEFAULT 'healthy',
                    check_interval_seconds INT DEFAULT 300,
                    
                    UNIQUE KEY unique_daemon (id)
                ) ENGINE=InnoDB
            ");
            
            $uptimeSeconds = time() - $this->startTime;
            
            $sql = "INSERT INTO ozon_etl_daemon_stats 
                    (id, pid, started_at, checks_performed, alerts_sent, uptime_seconds, 
                     last_health_status, check_interval_seconds)
                    VALUES (1, :pid, FROM_UNIXTIME(:started_at), :checks_performed, :alerts_sent, 
                            :uptime_seconds, :health_status, :check_interval)
                    ON DUPLICATE KEY UPDATE
                    pid = VALUES(pid),
                    checks_performed = VALUES(checks_performed),
                    alerts_sent = VALUES(alerts_sent),
                    uptime_seconds = VALUES(uptime_seconds),
                    last_health_status = VALUES(last_health_status),
                    check_interval_seconds = VALUES(check_interval_seconds)";
            
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute([
                'pid' => getmypid(),
                'started_at' => $this->startTime,
                'checks_performed' => $this->checksPerformed,
                'alerts_sent' => $this->alertsSent,
                'uptime_seconds' => $uptimeSeconds,
                'health_status' => $healthStatus['overall_status'],
                'check_interval' => $this->checkInterval
            ]);
            
        } catch (Exception $e) {
            $this->log('WARNING', 'Failed to update daemon stats: ' . $e->getMessage());
        }
    }
    
    /**
     * Sleep with signal handling
     */
    private function sleepWithSignalHandling(int $seconds): void {
        $endTime = time() + $seconds;
        
        while (time() < $endTime && $this->running) {
            // Process signals if available
            if (function_exists('pcntl_signal_dispatch')) {
                pcntl_signal_dispatch();
            }
            
            // Sleep for 1 second at a time to allow signal processing
            sleep(1);
        }
    }
    
    /**
     * Check if daemon is already running
     */
    private function isDaemonRunning(): bool {
        if (!file_exists(PID_FILE)) {
            return false;
        }
        
        $pid = (int)file_get_contents(PID_FILE);
        
        // Check if process is actually running
        if (function_exists('posix_kill')) {
            return posix_kill($pid, 0);
        }
        
        // Fallback check for systems without posix functions
        return file_exists("/proc/$pid");
    }
    
    /**
     * Get running daemon PID
     */
    private function getRunningPID(): ?int {
        if (file_exists(PID_FILE)) {
            return (int)file_get_contents(PID_FILE);
        }
        return null;
    }
    
    /**
     * Write PID file
     */
    private function writePIDFile(): void {
        file_put_contents(PID_FILE, getmypid());
    }
    
    /**
     * Remove PID file
     */
    private function removePIDFile(): void {
        if (file_exists(PID_FILE)) {
            unlink(PID_FILE);
        }
    }
    
    /**
     * Stop running daemon
     */
    private function stopDaemon(): void {
        if (!$this->isDaemonRunning()) {
            echo "Daemon is not running\n";
            return;
        }
        
        $pid = $this->getRunningPID();
        
        if (function_exists('posix_kill')) {
            if (posix_kill($pid, SIGTERM)) {
                echo "Sent SIGTERM to daemon (PID: $pid)\n";
                
                // Wait for graceful shutdown
                $timeout = 10;
                while ($timeout > 0 && $this->isDaemonRunning()) {
                    sleep(1);
                    $timeout--;
                }
                
                if ($this->isDaemonRunning()) {
                    echo "Daemon did not stop gracefully, sending SIGKILL\n";
                    posix_kill($pid, SIGKILL);
                    sleep(1);
                }
                
                if (!$this->isDaemonRunning()) {
                    echo "Daemon stopped successfully\n";
                    $this->removePIDFile();
                } else {
                    echo "Failed to stop daemon\n";
                }
            } else {
                echo "Failed to send signal to daemon\n";
            }
        } else {
            echo "Cannot stop daemon - posix functions not available\n";
        }
    }
    
    /**
     * Show daemon status
     */
    private function showStatus(): void {
        if ($this->isDaemonRunning()) {
            $pid = $this->getRunningPID();
            echo "Daemon is running (PID: $pid)\n";
            
            // Try to get daemon stats from database
            try {
                $stmt = $this->pdo->query("SELECT * FROM ozon_etl_daemon_stats WHERE id = 1");
                $stats = $stmt->fetch();
                
                if ($stats) {
                    echo "Started: " . $stats['started_at'] . "\n";
                    echo "Last check: " . $stats['last_check_at'] . "\n";
                    echo "Checks performed: " . $stats['checks_performed'] . "\n";
                    echo "Alerts sent: " . $stats['alerts_sent'] . "\n";
                    echo "Uptime: " . gmdate('H:i:s', $stats['uptime_seconds']) . "\n";
                    echo "Last health status: " . $stats['last_health_status'] . "\n";
                    echo "Check interval: " . $stats['check_interval_seconds'] . " seconds\n";
                }
            } catch (Exception $e) {
                echo "Could not retrieve daemon stats: " . $e->getMessage() . "\n";
            }
        } else {
            echo "Daemon is not running\n";
        }
    }
    
    /**
     * Show help message
     */
    private function showHelp(): void {
        echo "Ozon ETL Monitoring Daemon\n";
        echo "==========================\n\n";
        echo "Usage: php ozon_etl_monitoring_daemon.php [options]\n\n";
        echo "Options:\n";
        echo "  --interval=SECONDS    Health check interval in seconds (default: 300)\n";
        echo "  --daemon              Run as daemon (background process)\n";
        echo "  --stop                Stop running daemon\n";
        echo "  --status              Show daemon status\n";
        echo "  --verbose             Enable verbose logging\n";
        echo "  --help                Show this help message\n\n";
        echo "Examples:\n";
        echo "  php ozon_etl_monitoring_daemon.php --daemon\n";
        echo "  php ozon_etl_monitoring_daemon.php --daemon --interval=180\n";
        echo "  php ozon_etl_monitoring_daemon.php --status\n";
        echo "  php ozon_etl_monitoring_daemon.php --stop\n\n";
    }
    
    /**
     * Shutdown daemon gracefully
     */
    private function shutdown(): void {
        $uptime = time() - $this->startTime;
        
        $this->log('INFO', 'Monitoring daemon shutting down', [
            'uptime_seconds' => $uptime,
            'checks_performed' => $this->checksPerformed,
            'alerts_sent' => $this->alertsSent
        ]);
        
        // Clean up daemon stats
        try {
            $this->pdo->exec("DELETE FROM ozon_etl_daemon_stats WHERE id = 1");
        } catch (Exception $e) {
            // Ignore cleanup errors
        }
        
        $this->removePIDFile();
        
        echo "Daemon stopped gracefully\n";
    }
    
    /**
     * Log message
     */
    private function log(string $level, string $message, array $context = []): void {
        $timestamp = date('Y-m-d H:i:s');
        $contextStr = !empty($context) ? ' | ' . json_encode($context, JSON_UNESCAPED_UNICODE) : '';
        $logMessage = "[$timestamp] [$level] [DAEMON] $message$contextStr\n";
        
        // Write to daemon log file
        file_put_contents(DAEMON_LOG_FILE, $logMessage, FILE_APPEND | LOCK_EX);
        
        // Output to console if verbose mode or important messages
        if (isset($this->options['verbose']) || in_array($level, ['ERROR', 'WARNING']) || !isset($this->options['daemon'])) {
            echo $logMessage;
        }
        
        // Also log to system error log for critical messages
        if ($level === 'ERROR') {
            error_log("[Ozon ETL Monitoring Daemon] $message" . $contextStr);
        }
    }
}

// Parse command line arguments
$options = [];
$args = array_slice($argv, 1);

foreach ($args as $arg) {
    if (strpos($arg, '--') === 0) {
        $parts = explode('=', substr($arg, 2), 2);
        $key = $parts[0];
        $value = isset($parts[1]) ? $parts[1] : true;
        $options[$key] = $value;
    }
}

// Create and run the daemon
try {
    $daemon = new OzonETLMonitoringDaemon($options);
    $daemon->run();
} catch (Exception $e) {
    echo "FATAL ERROR: " . $e->getMessage() . "\n";
    error_log("[Ozon ETL Monitoring Daemon] FATAL: " . $e->getMessage());
    exit(1);
}