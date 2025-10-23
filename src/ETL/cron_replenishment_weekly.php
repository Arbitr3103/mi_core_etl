#!/usr/bin/env php
<?php
/**
 * Weekly Replenishment Calculation Cron Script
 * 
 * This script should be added to crontab to run weekly replenishment calculations.
 * 
 * Cron schedule example (every Monday at 6:00 AM):
 * 0 6 * * 1 /usr/bin/php /path/to/cron_replenishment_weekly.php
 * 
 * Usage:
 *   php cron_replenishment_weekly.php [--force] [--config-file=path] [--debug]
 * 
 * Options:
 *   --force         Force execution even if not scheduled
 *   --config-file   Path to custom configuration file
 *   --debug         Enable debug output
 *   --help          Show this help message
 */

// Prevent web access
if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    die('This script can only be run from command line');
}

// Set error reporting
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Include required files
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/src/Replenishment/ReplenishmentScheduler.php';

use Replenishment\ReplenishmentScheduler;

/**
 * Main execution class
 */
class ReplenishmentCronJob
{
    private PDO $pdo;
    private array $config;
    private bool $debug = false;
    private bool $force = false;
    private string $logFile;
    
    public function __construct()
    {
        $this->initializeDatabase();
        $this->loadConfiguration();
        $this->parseArguments();
        $this->setupLogging();
    }
    
    /**
     * Main execution method
     */
    public function run(): void
    {
        $this->log("=== Weekly Replenishment Calculation Started ===");
        $this->log("Timestamp: " . date('Y-m-d H:i:s'));
        $this->log("Force execution: " . ($this->force ? 'Yes' : 'No'));
        $this->log("Debug mode: " . ($this->debug ? 'Yes' : 'No'));
        
        try {
            // Create scheduler instance
            $scheduler = new ReplenishmentScheduler($this->pdo, $this->config);
            
            // Check if execution is allowed (unless forced)
            if (!$this->force && !$scheduler->isExecutionAllowed()) {
                $nextExecution = $scheduler->getNextExecutionTime();
                $this->log("Execution not scheduled for current time");
                $this->log("Next scheduled execution: " . $nextExecution->format('Y-m-d H:i:s'));
                $this->log("Use --force flag to override schedule");
                return;
            }
            
            // Execute weekly calculation
            $this->log("Starting weekly calculation...");
            $results = $scheduler->executeWeeklyCalculation($this->force);
            
            // Log results
            $this->logResults($results);
            
            // Cleanup old logs if configured
            if (!empty($this->config['cleanup_enabled'])) {
                $this->log("Cleaning up old execution logs...");
                $deletedCount = $scheduler->cleanupExecutionLogs();
                $this->log("Cleaned up $deletedCount old log records");
            }
            
            $this->log("=== Weekly Replenishment Calculation Completed Successfully ===");
            
            exit(0);
            
        } catch (Exception $e) {
            $this->log("ERROR: " . $e->getMessage());
            $this->log("Stack trace: " . $e->getTraceAsString());
            $this->log("=== Weekly Replenishment Calculation Failed ===");
            
            // Send error notification if configured
            $this->sendErrorNotification($e);
            
            exit(1);
        }
    }
    
    /**
     * Initialize database connection
     */
    private function initializeDatabase(): void
    {
        try {
            $this->pdo = new PDO(
                "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4",
                DB_USER,
                DB_PASS,
                [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES => false
                ]
            );
        } catch (Exception $e) {
            die("Database connection failed: " . $e->getMessage() . "\n");
        }
    }
    
    /**
     * Load configuration
     */
    private function loadConfiguration(): void
    {
        // Default configuration
        $this->config = [
            'schedule_enabled' => true,
            'weekly_day' => 'monday',
            'weekly_time' => '06:00',
            'email_enabled' => true,
            'notification_email' => '',
            'report_format' => 'detailed',
            'max_execution_time' => 3600,
            'retry_attempts' => 3,
            'cleanup_enabled' => true,
            'cleanup_days' => 30,
            'debug' => false
        ];
        
        // Load from database configuration
        try {
            $stmt = $this->pdo->prepare("
                SELECT parameter_name, parameter_value 
                FROM replenishment_config 
                WHERE parameter_name IN (
                    'schedule_enabled', 'weekly_day', 'weekly_time', 
                    'email_enabled', 'notification_email', 'report_format',
                    'max_execution_time', 'cleanup_enabled', 'cleanup_days'
                )
            ");
            $stmt->execute();
            
            while ($row = $stmt->fetch()) {
                $value = $row['parameter_value'];
                
                // Convert boolean values
                if (in_array($row['parameter_name'], ['schedule_enabled', 'email_enabled', 'cleanup_enabled'])) {
                    $value = filter_var($value, FILTER_VALIDATE_BOOLEAN);
                }
                
                // Convert numeric values
                if (in_array($row['parameter_name'], ['max_execution_time', 'cleanup_days'])) {
                    $value = (int)$value;
                }
                
                $this->config[$row['parameter_name']] = $value;
            }
        } catch (Exception $e) {
            $this->log("Warning: Could not load configuration from database: " . $e->getMessage());
        }
        
        // Override with environment variables
        $envMappings = [
            'REPLENISHMENT_SCHEDULE_ENABLED' => 'schedule_enabled',
            'REPLENISHMENT_WEEKLY_DAY' => 'weekly_day',
            'REPLENISHMENT_WEEKLY_TIME' => 'weekly_time',
            'REPLENISHMENT_EMAIL_ENABLED' => 'email_enabled',
            'REPLENISHMENT_NOTIFICATION_EMAIL' => 'notification_email',
            'REPLENISHMENT_REPORT_FORMAT' => 'report_format',
            'REPLENISHMENT_MAX_EXECUTION_TIME' => 'max_execution_time'
        ];
        
        foreach ($envMappings as $envVar => $configKey) {
            $envValue = $_ENV[$envVar] ?? getenv($envVar);
            if ($envValue !== false) {
                // Convert boolean values
                if (in_array($configKey, ['schedule_enabled', 'email_enabled'])) {
                    $envValue = filter_var($envValue, FILTER_VALIDATE_BOOLEAN);
                }
                
                // Convert numeric values
                if (in_array($configKey, ['max_execution_time'])) {
                    $envValue = (int)$envValue;
                }
                
                $this->config[$configKey] = $envValue;
            }
        }
    }
    
    /**
     * Parse command line arguments
     */
    private function parseArguments(): void
    {
        global $argv;
        
        foreach ($argv as $arg) {
            switch ($arg) {
                case '--force':
                    $this->force = true;
                    break;
                    
                case '--debug':
                    $this->debug = true;
                    $this->config['debug'] = true;
                    break;
                    
                case '--help':
                    $this->showHelp();
                    exit(0);
                    
                default:
                    if (strpos($arg, '--config-file=') === 0) {
                        $configFile = substr($arg, 14);
                        $this->loadConfigFile($configFile);
                    }
                    break;
            }
        }
    }
    
    /**
     * Load configuration from file
     * 
     * @param string $configFile Path to configuration file
     */
    private function loadConfigFile(string $configFile): void
    {
        if (!file_exists($configFile)) {
            die("Configuration file not found: $configFile\n");
        }
        
        $fileConfig = include $configFile;
        
        if (is_array($fileConfig)) {
            $this->config = array_merge($this->config, $fileConfig);
        }
    }
    
    /**
     * Setup logging
     */
    private function setupLogging(): void
    {
        $logDir = __DIR__ . '/logs';
        
        if (!is_dir($logDir)) {
            mkdir($logDir, 0755, true);
        }
        
        $this->logFile = $logDir . '/replenishment_cron_' . date('Y-m-d') . '.log';
    }
    
    /**
     * Log message
     * 
     * @param string $message Message to log
     */
    private function log(string $message): void
    {
        $timestamp = date('Y-m-d H:i:s');
        $logMessage = "[$timestamp] $message\n";
        
        // Write to log file
        file_put_contents($this->logFile, $logMessage, FILE_APPEND | LOCK_EX);
        
        // Output to console if debug mode
        if ($this->debug) {
            echo $logMessage;
        }
    }
    
    /**
     * Log execution results
     * 
     * @param array $results Execution results
     */
    private function logResults(array $results): void
    {
        $this->log("Execution Results:");
        $this->log("  Status: " . $results['status']);
        $this->log("  Execution time: " . $results['execution_time'] . " seconds");
        $this->log("  Memory usage: " . $results['memory_usage_mb'] . " MB");
        $this->log("  Total recommendations: " . $results['recommendations_count']);
        $this->log("  Actionable recommendations: " . $results['actionable_count']);
        $this->log("  Email sent: " . ($results['email_sent'] ? 'Yes' : 'No'));
        
        if (!empty($results['email_recipients'])) {
            $this->log("  Email recipients: " . implode(', ', $results['email_recipients']));
        }
        
        if (!empty($results['error_message'])) {
            $this->log("  Error: " . $results['error_message']);
        }
    }
    
    /**
     * Send error notification
     * 
     * @param Exception $exception Exception that occurred
     */
    private function sendErrorNotification(Exception $exception): void
    {
        if (!$this->config['email_enabled'] || empty($this->config['notification_email'])) {
            return;
        }
        
        $subject = "[CRITICAL] Replenishment Cron Job Failed - " . date('Y-m-d H:i');
        
        $message = "Critical Error in Weekly Replenishment Calculation\n";
        $message .= "Time: " . date('Y-m-d H:i:s') . "\n";
        $message .= "Server: " . gethostname() . "\n";
        $message .= str_repeat("=", 50) . "\n\n";
        
        $message .= "ERROR DETAILS:\n";
        $message .= "Message: " . $exception->getMessage() . "\n";
        $message .= "File: " . $exception->getFile() . "\n";
        $message .= "Line: " . $exception->getLine() . "\n\n";
        
        $message .= "STACK TRACE:\n";
        $message .= $exception->getTraceAsString() . "\n\n";
        
        $message .= "CONFIGURATION:\n";
        $message .= "Force execution: " . ($this->force ? 'Yes' : 'No') . "\n";
        $message .= "Debug mode: " . ($this->debug ? 'Yes' : 'No') . "\n";
        $message .= "Log file: " . $this->logFile . "\n\n";
        
        $message .= "IMMEDIATE ACTIONS REQUIRED:\n";
        $message .= "1. Check the log file for detailed information\n";
        $message .= "2. Verify database connectivity and data integrity\n";
        $message .= "3. Check system resources (memory, disk space)\n";
        $message .= "4. Consider running manual calculation for diagnosis\n";
        $message .= "5. Contact system administrator immediately\n\n";
        
        $message .= str_repeat("=", 50) . "\n";
        $message .= "This is an automated critical error notification.\n";
        $message .= "Please investigate and resolve immediately.\n";
        
        $headers = "From: Replenishment Cron <noreply@replenishment-system.local>\r\n";
        $headers .= "Content-Type: text/plain; charset=UTF-8\r\n";
        
        try {
            mail($this->config['notification_email'], $subject, $message, $headers);
            $this->log("Critical error notification sent to: " . $this->config['notification_email']);
        } catch (Exception $e) {
            $this->log("Failed to send critical error notification: " . $e->getMessage());
        }
    }
    
    /**
     * Show help message
     */
    private function showHelp(): void
    {
        echo "Weekly Replenishment Calculation Cron Script\n";
        echo str_repeat("=", 50) . "\n\n";
        
        echo "Usage:\n";
        echo "  php cron_replenishment_weekly.php [options]\n\n";
        
        echo "Options:\n";
        echo "  --force              Force execution even if not scheduled\n";
        echo "  --config-file=path   Path to custom configuration file\n";
        echo "  --debug              Enable debug output\n";
        echo "  --help               Show this help message\n\n";
        
        echo "Cron Schedule Examples:\n";
        echo "  # Every Monday at 6:00 AM\n";
        echo "  0 6 * * 1 /usr/bin/php /path/to/cron_replenishment_weekly.php\n\n";
        
        echo "  # Every Sunday at 23:00 with debug output\n";
        echo "  0 23 * * 0 /usr/bin/php /path/to/cron_replenishment_weekly.php --debug\n\n";
        
        echo "Configuration:\n";
        echo "  Configuration is loaded from:\n";
        echo "  1. Database (replenishment_config table)\n";
        echo "  2. Environment variables (REPLENISHMENT_*)\n";
        echo "  3. Custom configuration file (--config-file option)\n\n";
        
        echo "Environment Variables:\n";
        echo "  REPLENISHMENT_SCHEDULE_ENABLED      Enable/disable scheduler\n";
        echo "  REPLENISHMENT_WEEKLY_DAY            Day of week (monday, tuesday, etc.)\n";
        echo "  REPLENISHMENT_WEEKLY_TIME           Time of day (HH:MM format)\n";
        echo "  REPLENISHMENT_EMAIL_ENABLED         Enable/disable email notifications\n";
        echo "  REPLENISHMENT_NOTIFICATION_EMAIL    Email address for notifications\n";
        echo "  REPLENISHMENT_REPORT_FORMAT         Report format (summary or detailed)\n";
        echo "  REPLENISHMENT_MAX_EXECUTION_TIME    Maximum execution time in seconds\n\n";
        
        echo "Log Files:\n";
        echo "  Execution logs are saved to: logs/replenishment_cron_YYYY-MM-DD.log\n";
        echo "  Database execution history is maintained in: replenishment_scheduler_log\n\n";
    }
}

// Execute the cron job
try {
    $cronJob = new ReplenishmentCronJob();
    $cronJob->run();
} catch (Exception $e) {
    error_log("Fatal error in replenishment cron job: " . $e->getMessage());
    exit(1);
}
?>