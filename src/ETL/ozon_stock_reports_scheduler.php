#!/usr/bin/env php
<?php
/**
 * Ozon Stock Reports ETL Scheduler Script
 * 
 * Cron-compatible script for daily execution of Ozon warehouse stock reports ETL process.
 * Supports both automated scheduling and manual execution with command-line interface.
 * 
 * Usage:
 *   php ozon_stock_reports_scheduler.php [options]
 * 
 * Options:
 *   --manual          Run ETL manually (bypass scheduling checks)
 *   --force           Force execution even if another process is running
 *   --dry-run         Show what would be executed without running
 *   --verbose         Enable verbose logging output
 *   --help            Show this help message
 * 
 * Cron setup (daily at 06:00 UTC):
 *   0 6 * * * /usr/bin/php /path/to/src/ETL/ozon_stock_reports_scheduler.php
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
define('LOCK_FILE', sys_get_temp_dir() . '/ozon_stock_reports_etl.lock');

// Ensure log directory exists
if (!is_dir(LOG_DIR)) {
    mkdir(LOG_DIR, 0755, true);
}

// Include required files
require_once SRC_DIR . '/config/database.php';
require_once SRC_DIR . '/classes/OzonAnalyticsAPI.php';
require_once SRC_DIR . '/classes/OzonStockReportsManager.php';

/**
 * ETL Scheduler Class for Ozon Stock Reports
 */
class OzonStockReportsScheduler {
    
    private $options;
    private $pdo;
    private $ozonAPI;
    private $stockReportsManager;
    private $logFile;
    private $startTime;
    
    /**
     * Constructor
     * 
     * @param array $options Command line options
     */
    public function __construct(array $options = []) {
        $this->options = $options;
        $this->startTime = microtime(true);
        $this->logFile = LOG_DIR . '/ozon_stock_reports_etl_' . date('Y-m-d') . '.log';
        
        $this->initializeDatabase();
        $this->initializeOzonAPI();
        $this->initializeStockReportsManager();
    }
    
    /**
     * Initialize database connection
     */
    private function initializeDatabase(): void {
        try {
            $config = include SRC_DIR . '/config/database.php';
            
            $dsn = "mysql:host={$config['host']};dbname={$config['database']};charset={$config['charset']}";
            $this->pdo = new PDO($dsn, $config['username'], $config['password'], $config['options']);
            
            $this->log('INFO', 'Database connection established successfully');
            
        } catch (Exception $e) {
            $this->log('ERROR', 'Failed to connect to database: ' . $e->getMessage());
            $this->exitWithError('Database connection failed', 1);
        }
    }
    
    /**
     * Initialize Ozon API
     */
    private function initializeOzonAPI(): void {
        try {
            // Load API credentials from environment or database
            $apiKey = $_ENV['OZON_API_KEY'] ?? $this->getOzonAPIKey();
            $clientId = $_ENV['OZON_CLIENT_ID'] ?? $this->getOzonClientId();
            
            if (empty($apiKey) || empty($clientId)) {
                throw new Exception('Ozon API credentials not found');
            }
            
            $this->ozonAPI = new OzonAnalyticsAPI($apiKey, $clientId);
            $this->log('INFO', 'Ozon API initialized successfully');
            
        } catch (Exception $e) {
            $this->log('ERROR', 'Failed to initialize Ozon API: ' . $e->getMessage());
            $this->exitWithError('Ozon API initialization failed', 2);
        }
    }
    
    /**
     * Initialize Stock Reports Manager
     */
    private function initializeStockReportsManager(): void {
        try {
            $this->stockReportsManager = new OzonStockReportsManager($this->pdo, $this->ozonAPI);
            $this->log('INFO', 'Stock Reports Manager initialized successfully');
            
        } catch (Exception $e) {
            $this->log('ERROR', 'Failed to initialize Stock Reports Manager: ' . $e->getMessage());
            $this->exitWithError('Stock Reports Manager initialization failed', 3);
        }
    }
    
    /**
     * Main execution method
     */
    public function run(): void {
        $this->log('INFO', 'Starting Ozon Stock Reports ETL Scheduler', [
            'options' => $this->options,
            'timestamp' => date('Y-m-d H:i:s'),
            'timezone' => date_default_timezone_get()
        ]);
        
        try {
            // Show help if requested
            if (isset($this->options['help'])) {
                $this->showHelp();
                return;
            }
            
            // Dry run mode
            if (isset($this->options['dry-run'])) {
                $this->performDryRun();
                return;
            }
            
            // Check if we should run (scheduling logic)
            if (!$this->shouldRun()) {
                $this->log('INFO', 'ETL execution skipped based on scheduling rules');
                return;
            }
            
            // Acquire lock to prevent concurrent execution
            if (!$this->acquireLock()) {
                $message = 'Another ETL process is already running';
                if (isset($this->options['force'])) {
                    $this->log('WARNING', $message . ' - forcing execution due to --force flag');
                    $this->releaseLock(); // Force release existing lock
                    if (!$this->acquireLock()) {
                        $this->exitWithError('Failed to acquire lock even with --force flag', 4);
                    }
                } else {
                    $this->log('WARNING', $message);
                    $this->exitWithError($message, 4);
                }
            }
            
            try {
                // Execute the ETL process
                $this->executeETL();
                
            } finally {
                // Always release the lock
                $this->releaseLock();
            }
            
        } catch (Exception $e) {
            $this->log('ERROR', 'ETL Scheduler execution failed: ' . $e->getMessage());
            $this->exitWithError('ETL execution failed', 5);
        }
    }
    
    /**
     * Determine if ETL should run based on scheduling rules
     */
    private function shouldRun(): bool {
        // Manual execution always runs
        if (isset($this->options['manual'])) {
            $this->log('INFO', 'Manual execution requested - bypassing scheduling checks');
            return true;
        }
        
        // Check if we're in the correct time window (06:00 UTC ± 30 minutes)
        $currentHour = (int)date('H');
        $currentMinute = (int)date('i');
        $currentTimeInMinutes = $currentHour * 60 + $currentMinute;
        
        $targetTimeInMinutes = 6 * 60; // 06:00 UTC
        $windowMinutes = 30; // ±30 minutes window
        
        $inTimeWindow = abs($currentTimeInMinutes - $targetTimeInMinutes) <= $windowMinutes;
        
        if (!$inTimeWindow) {
            $this->log('INFO', 'Outside scheduled time window', [
                'current_time' => date('H:i'),
                'target_time' => '06:00',
                'window_minutes' => $windowMinutes
            ]);
            return false;
        }
        
        // Check if ETL already ran successfully today
        if ($this->hasRunToday()) {
            $this->log('INFO', 'ETL already completed successfully today');
            return false;
        }
        
        return true;
    }
    
    /**
     * Check if ETL has already run successfully today
     */
    private function hasRunToday(): bool {
        try {
            $sql = "SELECT COUNT(*) FROM ozon_etl_history 
                    WHERE status = 'completed' 
                    AND DATE(start_time) = CURDATE()";
            
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute();
            
            return $stmt->fetchColumn() > 0;
            
        } catch (Exception $e) {
            $this->log('WARNING', 'Failed to check if ETL ran today: ' . $e->getMessage());
            return false; // Assume it hasn't run if we can't check
        }
    }
    
    /**
     * Execute the ETL process
     */
    private function executeETL(): void {
        $this->log('INFO', 'Starting ETL execution');
        
        try {
            // Prepare ETL filters (can be extended based on requirements)
            $filters = $this->prepareETLFilters();
            
            // Execute the stock reports ETL
            $result = $this->stockReportsManager->executeStockReportsETL($filters);
            
            // Log the results
            $this->logETLResults($result);
            
            // Send notifications if needed
            $this->handleETLCompletion($result);
            
            $duration = microtime(true) - $this->startTime;
            $this->log('INFO', 'ETL execution completed successfully', [
                'duration_seconds' => round($duration, 2),
                'etl_id' => $result['etl_id'],
                'status' => $result['status']
            ]);
            
        } catch (Exception $e) {
            $duration = microtime(true) - $this->startTime;
            $this->log('ERROR', 'ETL execution failed', [
                'error' => $e->getMessage(),
                'duration_seconds' => round($duration, 2)
            ]);
            
            // Send error notification
            $this->handleETLError($e);
            
            throw $e;
        }
    }
    
    /**
     * Prepare filters for ETL execution
     */
    private function prepareETLFilters(): array {
        $filters = [
            'date_from' => date('Y-m-d', strtotime('-1 day')),
            'date_to' => date('Y-m-d'),
            'include_zero_stock' => false
        ];
        
        // Add any additional filters from command line options
        if (isset($this->options['warehouse'])) {
            $filters['warehouse_filter'] = $this->options['warehouse'];
        }
        
        if (isset($this->options['product'])) {
            $filters['product_filter'] = $this->options['product'];
        }
        
        return $filters;
    }
    
    /**
     * Log ETL results
     */
    private function logETLResults(array $result): void {
        $this->log('INFO', 'ETL Results Summary', [
            'etl_id' => $result['etl_id'],
            'status' => $result['status'],
            'duration' => $result['duration_seconds'] ?? 0,
            'statistics' => $result['statistics'] ?? []
        ]);
        
        // Log each step
        if (!empty($result['steps'])) {
            foreach ($result['steps'] as $step) {
                $this->log('INFO', "Step {$step['step']}: {$step['name']}", [
                    'status' => $step['status'],
                    'timestamp' => $step['timestamp']
                ]);
            }
        }
    }
    
    /**
     * Handle ETL completion (notifications, cleanup, etc.)
     */
    private function handleETLCompletion(array $result): void {
        // Check if there were any issues that need attention
        $hasWarnings = false;
        $hasErrors = $result['status'] !== 'completed';
        
        if (!empty($result['statistics'])) {
            $stats = $result['statistics'];
            
            // Check for concerning statistics
            if ($stats['errors_count'] > 0) {
                $hasErrors = true;
            }
            
            if ($stats['records_processed'] == 0) {
                $hasWarnings = true;
                $this->log('WARNING', 'No records were processed during ETL execution');
            }
            
            if ($stats['alerts_generated'] > 10) {
                $hasWarnings = true;
                $this->log('WARNING', 'High number of stock alerts generated', [
                    'alerts_count' => $stats['alerts_generated']
                ]);
            }
        }
        
        // Send notifications based on results
        if ($hasErrors) {
            $this->sendErrorNotification($result);
        } elseif ($hasWarnings) {
            $this->sendWarningNotification($result);
        } else {
            $this->sendSuccessNotification($result);
        }
    }
    
    /**
     * Handle ETL errors
     */
    private function handleETLError(Exception $e): void {
        $errorData = [
            'error_message' => $e->getMessage(),
            'error_file' => $e->getFile(),
            'error_line' => $e->getLine(),
            'timestamp' => date('Y-m-d H:i:s'),
            'duration' => round(microtime(true) - $this->startTime, 2)
        ];
        
        $this->log('ERROR', 'Critical ETL Error', $errorData);
        $this->sendCriticalErrorNotification($errorData);
    }
    
    /**
     * Perform dry run (show what would be executed)
     */
    private function performDryRun(): void {
        $this->log('INFO', '=== DRY RUN MODE ===');
        $this->log('INFO', 'The following actions would be performed:');
        
        $filters = $this->prepareETLFilters();
        $this->log('INFO', '1. ETL would be executed with filters:', $filters);
        
        $this->log('INFO', '2. Database tables that would be updated:');
        $this->log('INFO', '   - ozon_stock_reports (new report entries)');
        $this->log('INFO', '   - inventory (stock data updates)');
        $this->log('INFO', '   - stock_report_logs (process logs)');
        $this->log('INFO', '   - ozon_etl_history (execution history)');
        
        $this->log('INFO', '3. Notifications that would be sent:');
        $this->log('INFO', '   - Success/Error notifications based on results');
        $this->log('INFO', '   - Stock alert notifications if critical levels detected');
        
        $this->log('INFO', '=== END DRY RUN ===');
    }
    
    /**
     * Show help message
     */
    private function showHelp(): void {
        echo "Ozon Stock Reports ETL Scheduler\n";
        echo "================================\n\n";
        echo "Usage: php ozon_stock_reports_scheduler.php [options]\n\n";
        echo "Options:\n";
        echo "  --manual          Run ETL manually (bypass scheduling checks)\n";
        echo "  --force           Force execution even if another process is running\n";
        echo "  --dry-run         Show what would be executed without running\n";
        echo "  --verbose         Enable verbose logging output\n";
        echo "  --warehouse=NAME  Filter by specific warehouse name\n";
        echo "  --product=SKU     Filter by specific product SKU\n";
        echo "  --help            Show this help message\n\n";
        echo "Examples:\n";
        echo "  php ozon_stock_reports_scheduler.php --manual\n";
        echo "  php ozon_stock_reports_scheduler.php --dry-run\n";
        echo "  php ozon_stock_reports_scheduler.php --manual --warehouse=Тверь\n\n";
        echo "Cron setup (daily at 06:00 UTC):\n";
        echo "  0 6 * * * /usr/bin/php /path/to/src/ETL/ozon_stock_reports_scheduler.php\n\n";
    }
    
    /**
     * Acquire execution lock
     */
    private function acquireLock(): bool {
        if (file_exists(LOCK_FILE)) {
            $lockContent = file_get_contents(LOCK_FILE);
            $lockData = json_decode($lockContent, true);
            
            if ($lockData && isset($lockData['pid'])) {
                // Check if process is still running (Unix systems)
                if (function_exists('posix_kill') && posix_kill($lockData['pid'], 0)) {
                    return false; // Process is still active
                }
            }
            
            // Remove stale lock file
            unlink(LOCK_FILE);
        }
        
        $lockData = [
            'pid' => getmypid(),
            'started_at' => date('Y-m-d H:i:s'),
            'hostname' => gethostname(),
            'script_path' => __FILE__
        ];
        
        return file_put_contents(LOCK_FILE, json_encode($lockData, JSON_PRETTY_PRINT)) !== false;
    }
    
    /**
     * Release execution lock
     */
    private function releaseLock(): void {
        if (file_exists(LOCK_FILE)) {
            unlink(LOCK_FILE);
        }
    }
    
    /**
     * Get Ozon API key from database or environment
     */
    private function getOzonAPIKey(): ?string {
        try {
            $stmt = $this->pdo->prepare("SELECT api_key FROM ozon_settings WHERE is_active = 1 LIMIT 1");
            $stmt->execute();
            return $stmt->fetchColumn() ?: null;
        } catch (Exception $e) {
            return null;
        }
    }
    
    /**
     * Get Ozon Client ID from database or environment
     */
    private function getOzonClientId(): ?string {
        try {
            $stmt = $this->pdo->prepare("SELECT client_id FROM ozon_settings WHERE is_active = 1 LIMIT 1");
            $stmt->execute();
            return $stmt->fetchColumn() ?: null;
        } catch (Exception $e) {
            return null;
        }
    }
    
    /**
     * Send success notification
     */
    private function sendSuccessNotification(array $result): void {
        $message = "Ozon Stock Reports ETL completed successfully";
        $details = [
            'etl_id' => $result['etl_id'],
            'duration' => $result['duration_seconds'] ?? 0,
            'records_processed' => $result['statistics']['records_processed'] ?? 0,
            'records_updated' => $result['statistics']['records_updated'] ?? 0,
            'alerts_generated' => $result['statistics']['alerts_generated'] ?? 0
        ];
        
        $this->log('INFO', $message, $details);
        // TODO: Implement actual notification sending (email, webhook, etc.)
    }
    
    /**
     * Send warning notification
     */
    private function sendWarningNotification(array $result): void {
        $message = "Ozon Stock Reports ETL completed with warnings";
        $details = [
            'etl_id' => $result['etl_id'],
            'status' => $result['status'],
            'statistics' => $result['statistics'] ?? []
        ];
        
        $this->log('WARNING', $message, $details);
        // TODO: Implement actual notification sending
    }
    
    /**
     * Send error notification
     */
    private function sendErrorNotification(array $result): void {
        $message = "Ozon Stock Reports ETL failed";
        $details = [
            'etl_id' => $result['etl_id'],
            'status' => $result['status'],
            'error' => $result['error'] ?? 'Unknown error',
            'statistics' => $result['statistics'] ?? []
        ];
        
        $this->log('ERROR', $message, $details);
        // TODO: Implement actual notification sending
    }
    
    /**
     * Send critical error notification
     */
    private function sendCriticalErrorNotification(array $errorData): void {
        $message = "CRITICAL: Ozon Stock Reports ETL Scheduler Error";
        
        $this->log('ERROR', $message, $errorData);
        // TODO: Implement actual notification sending with high priority
    }
    
    /**
     * Log message to file and optionally to console
     */
    private function log(string $level, string $message, array $context = []): void {
        $timestamp = date('Y-m-d H:i:s');
        $contextStr = !empty($context) ? ' | ' . json_encode($context, JSON_UNESCAPED_UNICODE) : '';
        $logMessage = "[$timestamp] [$level] $message$contextStr\n";
        
        // Write to log file
        file_put_contents($this->logFile, $logMessage, FILE_APPEND | LOCK_EX);
        
        // Output to console if verbose mode or error/warning
        if (isset($this->options['verbose']) || in_array($level, ['ERROR', 'WARNING'])) {
            echo $logMessage;
        }
        
        // Also log to system error log for critical messages
        if ($level === 'ERROR') {
            error_log("[Ozon ETL Scheduler] $message" . $contextStr);
        }
    }
    
    /**
     * Exit with error message and code
     */
    private function exitWithError(string $message, int $code): void {
        $this->log('ERROR', "Exiting with error: $message (code: $code)");
        echo "ERROR: $message\n";
        exit($code);
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

// Create and run the scheduler
try {
    $scheduler = new OzonStockReportsScheduler($options);
    $scheduler->run();
    exit(0);
} catch (Exception $e) {
    echo "FATAL ERROR: " . $e->getMessage() . "\n";
    error_log("[Ozon ETL Scheduler] FATAL: " . $e->getMessage());
    exit(1);
}