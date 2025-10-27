#!/usr/bin/env php
<?php

/**
 * ETL Health Check Script
 * 
 * Monitors the health and status of the ETL system components,
 * checking for failures, data quality issues, and system availability.
 * 
 * Requirements addressed:
 * - 5.2: Update monitoring to track both ETL components separately
 * - 5.3: Implement alerts for ETL sequence failures or data quality issues
 * 
 * Usage:
 *   php etl_health_check.php [options]
 * 
 * Options:
 *   --verbose          Enable verbose output
 *   --config=FILE      Use custom configuration file
 *   --alert-only       Only output if alerts are triggered
 *   --json             Output results in JSON format
 *   --help             Show this help message
 */

declare(strict_types=1);

// Set error reporting and time limits
error_reporting(E_ALL);
ini_set('display_errors', '1');
set_time_limit(300); // 5 minutes

// Change to script directory
chdir(__DIR__);

// Load autoloader and configuration
try {
    require_once __DIR__ . '/../autoload.php';
} catch (Exception $e) {
    echo "Error loading dependencies: " . $e->getMessage() . "\n";
    exit(1);
}

// Import required classes
use MiCore\ETL\Ozon\Core\DatabaseConnection;
use MiCore\ETL\Ozon\Core\Logger;

/**
 * Parse command line arguments
 */
function parseArguments(array $argv): array
{
    $options = [
        'verbose' => false,
        'config_file' => null,
        'alert_only' => false,
        'json' => false,
        'help' => false
    ];
    
    for ($i = 1; $i < count($argv); $i++) {
        $arg = $argv[$i];
        
        switch ($arg) {
            case '--verbose':
                $options['verbose'] = true;
                break;
            case '--alert-only':
                $options['alert_only'] = true;
                break;
            case '--json':
                $options['json'] = true;
                break;
            case '--help':
                $options['help'] = true;
                break;
            default:
                if (strpos($arg, '--config=') === 0) {
                    $options['config_file'] = substr($arg, 9);
                } else {
                    echo "Unknown option: $arg\n";
                    exit(1);
                }
        }
    }
    
    return $options;
}

/**
 * Show help message
 */
function showHelp(): void
{
    echo "ETL Health Check Script\n";
    echo "=======================\n\n";
    echo "Monitors the health and status of the ETL system components,\n";
    echo "checking for failures, data quality issues, and system availability.\n\n";
    echo "Usage:\n";
    echo "  php etl_health_check.php [options]\n\n";
    echo "Options:\n";
    echo "  --verbose          Enable verbose output\n";
    echo "  --config=FILE      Use custom configuration file\n";
    echo "  --alert-only       Only output if alerts are triggered\n";
    echo "  --json             Output results in JSON format\n";
    echo "  --help             Show this help message\n\n";
    echo "Examples:\n";
    echo "  php etl_health_check.php --verbose\n";
    echo "  php etl_health_check.php --json\n";
    echo "  php etl_health_check.php --alert-only\n\n";
}

/**
 * ETL Health Check Class
 */
class ETLHealthChecker
{
    private DatabaseConnection $db;
    private Logger $logger;
    private array $config;
    private array $healthStatus = [];
    
    public function __construct(DatabaseConnection $db, Logger $logger, array $config = [])
    {
        $this->db = $db;
        $this->logger = $logger;
        $this->config = $config;
    }
    
    /**
     * Perform comprehensive health check
     */
    public function performHealthCheck(): array
    {
        $this->logger->info('Starting ETL health check');
        
        $startTime = microtime(true);
        
        try {
            // Check database connectivity
            $this->healthStatus['database'] = $this->checkDatabaseHealth();
            
            // Check ETL execution status
            $this->healthStatus['etl_execution'] = $this->checkETLExecutionHealth();
            
            // Check data freshness
            $this->healthStatus['data_freshness'] = $this->checkDataFreshness();
            
            // Check system resources
            $this->healthStatus['system_resources'] = $this->checkSystemResources();
            
            // Check lock files
            $this->healthStatus['lock_files'] = $this->checkLockFiles();
            
            // Check log files
            $this->healthStatus['log_files'] = $this->checkLogFiles();
            
            // Calculate overall health
            $this->healthStatus['overall'] = $this->calculateOverallHealth();
            
            $duration = microtime(true) - $startTime;
            
            $this->healthStatus['check_info'] = [
                'checked_at' => date('Y-m-d H:i:s'),
                'duration_seconds' => round($duration, 2),
                'checks_performed' => count($this->healthStatus) - 1 // Exclude check_info itself
            ];
            
            $this->logger->info('ETL health check completed', [
                'duration' => round($duration, 2),
                'overall_status' => $this->healthStatus['overall']['status'],
                'checks_performed' => $this->healthStatus['check_info']['checks_performed']
            ]);
            
            return $this->healthStatus;
            
        } catch (Exception $e) {
            $this->logger->error('ETL health check failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            
            $this->healthStatus['overall'] = [
                'status' => 'critical',
                'message' => 'Health check failed: ' . $e->getMessage()
            ];
            
            return $this->healthStatus;
        }
    }
    
    /**
     * Check database connectivity and basic queries
     */
    private function checkDatabaseHealth(): array
    {
        $this->logger->debug('Checking database health');
        
        try {
            // Test basic connectivity
            $result = $this->db->query('SELECT 1 as test');
            
            if (empty($result) || $result[0]['test'] != 1) {
                throw new Exception('Database connectivity test failed');
            }
            
            // Check required tables exist
            $requiredTables = ['dim_products', 'inventory', 'etl_workflow_executions'];
            $missingTables = [];
            
            foreach ($requiredTables as $table) {
                $tableCheck = $this->db->query("SHOW TABLES LIKE '$table'");
                if (empty($tableCheck)) {
                    $missingTables[] = $table;
                }
            }
            
            if (!empty($missingTables)) {
                return [
                    'status' => 'critical',
                    'message' => 'Missing required tables: ' . implode(', ', $missingTables),
                    'missing_tables' => $missingTables
                ];
            }
            
            // Check table row counts
            $tableCounts = [];
            foreach ($requiredTables as $table) {
                $countResult = $this->db->query("SELECT COUNT(*) as count FROM $table");
                $tableCounts[$table] = (int)($countResult[0]['count'] ?? 0);
            }
            
            return [
                'status' => 'healthy',
                'message' => 'Database connectivity and tables OK',
                'table_counts' => $tableCounts,
                'response_time_ms' => 0 // Could add timing if needed
            ];
            
        } catch (Exception $e) {
            return [
                'status' => 'critical',
                'message' => 'Database health check failed: ' . $e->getMessage(),
                'error' => $e->getMessage()
            ];
        }
    }
    
    /**
     * Check ETL execution status and recent runs
     */
    private function checkETLExecutionHealth(): array
    {
        $this->logger->debug('Checking ETL execution health');
        
        try {
            // Check recent workflow executions (last 24 hours)
            $recentExecutions = $this->db->query("
                SELECT 
                    workflow_id,
                    status,
                    duration,
                    product_etl_status,
                    inventory_etl_status,
                    created_at
                FROM etl_workflow_executions
                WHERE created_at > NOW() - INTERVAL 24 HOUR
                ORDER BY created_at DESC
                LIMIT 10
            ");
            
            // Calculate success metrics
            $totalExecutions = count($recentExecutions);
            $successfulExecutions = 0;
            $failedExecutions = 0;
            $lastExecution = null;
            
            foreach ($recentExecutions as $execution) {
                if ($execution['status'] === 'success') {
                    $successfulExecutions++;
                } else {
                    $failedExecutions++;
                }
                
                if ($lastExecution === null) {
                    $lastExecution = $execution;
                }
            }
            
            // Determine status
            $status = 'healthy';
            $message = 'ETL executions running normally';
            
            if ($totalExecutions === 0) {
                $status = 'warning';
                $message = 'No ETL executions in the last 24 hours';
            } elseif ($failedExecutions > 0) {
                $failureRate = ($failedExecutions / $totalExecutions) * 100;
                
                if ($failureRate > 50) {
                    $status = 'critical';
                    $message = "High failure rate: {$failureRate}% of executions failed";
                } elseif ($failureRate > 20) {
                    $status = 'warning';
                    $message = "Elevated failure rate: {$failureRate}% of executions failed";
                }
            }
            
            // Check if last execution was too long ago
            if ($lastExecution) {
                $hoursSinceLastExecution = (time() - strtotime($lastExecution['created_at'])) / 3600;
                
                if ($hoursSinceLastExecution > 8) { // More than 8 hours
                    $status = 'warning';
                    $message = "Last execution was {$hoursSinceLastExecution} hours ago";
                }
            }
            
            return [
                'status' => $status,
                'message' => $message,
                'total_executions_24h' => $totalExecutions,
                'successful_executions' => $successfulExecutions,
                'failed_executions' => $failedExecutions,
                'success_rate_percent' => $totalExecutions > 0 ? round(($successfulExecutions / $totalExecutions) * 100, 2) : 0,
                'last_execution' => $lastExecution,
                'hours_since_last_execution' => $lastExecution ? round((time() - strtotime($lastExecution['created_at'])) / 3600, 1) : null
            ];
            
        } catch (Exception $e) {
            return [
                'status' => 'critical',
                'message' => 'ETL execution health check failed: ' . $e->getMessage(),
                'error' => $e->getMessage()
            ];
        }
    }
    
    /**
     * Check data freshness
     */
    private function checkDataFreshness(): array
    {
        $this->logger->debug('Checking data freshness');
        
        try {
            // Check dim_products freshness
            $productsResult = $this->db->query("
                SELECT 
                    COUNT(*) as total_products,
                    COUNT(CASE WHEN updated_at > NOW() - INTERVAL 6 HOUR THEN 1 END) as updated_last_6h,
                    COUNT(CASE WHEN updated_at > NOW() - INTERVAL 24 HOUR THEN 1 END) as updated_last_24h,
                    MAX(updated_at) as newest_update,
                    MIN(updated_at) as oldest_update
                FROM dim_products
            ");
            
            // Check inventory freshness
            $inventoryResult = $this->db->query("
                SELECT 
                    COUNT(*) as total_inventory,
                    COUNT(CASE WHEN updated_at > NOW() - INTERVAL 6 HOUR THEN 1 END) as updated_last_6h,
                    COUNT(CASE WHEN updated_at > NOW() - INTERVAL 24 HOUR THEN 1 END) as updated_last_24h,
                    MAX(updated_at) as newest_update,
                    MIN(updated_at) as oldest_update
                FROM inventory
            ");
            
            $productsData = $productsResult[0] ?? [];
            $inventoryData = $inventoryResult[0] ?? [];
            
            // Determine freshness status
            $status = 'healthy';
            $messages = [];
            
            // Check products freshness
            if (!empty($productsData['newest_update'])) {
                $hoursSinceProductsUpdate = (time() - strtotime($productsData['newest_update'])) / 3600;
                
                if ($hoursSinceProductsUpdate > 8) {
                    $status = 'warning';
                    $messages[] = "Products data is stale (last update: {$hoursSinceProductsUpdate} hours ago)";
                }
            } else {
                $status = 'critical';
                $messages[] = 'No products data found';
            }
            
            // Check inventory freshness
            if (!empty($inventoryData['newest_update'])) {
                $hoursSinceInventoryUpdate = (time() - strtotime($inventoryData['newest_update'])) / 3600;
                
                if ($hoursSinceInventoryUpdate > 8) {
                    if ($status !== 'critical') {
                        $status = 'warning';
                    }
                    $messages[] = "Inventory data is stale (last update: {$hoursSinceInventoryUpdate} hours ago)";
                }
            } else {
                $status = 'critical';
                $messages[] = 'No inventory data found';
            }
            
            $message = empty($messages) ? 'Data freshness is good' : implode('; ', $messages);
            
            return [
                'status' => $status,
                'message' => $message,
                'products' => [
                    'total' => (int)($productsData['total_products'] ?? 0),
                    'updated_last_6h' => (int)($productsData['updated_last_6h'] ?? 0),
                    'updated_last_24h' => (int)($productsData['updated_last_24h'] ?? 0),
                    'newest_update' => $productsData['newest_update'] ?? null,
                    'hours_since_update' => !empty($productsData['newest_update']) ? 
                        round((time() - strtotime($productsData['newest_update'])) / 3600, 1) : null
                ],
                'inventory' => [
                    'total' => (int)($inventoryData['total_inventory'] ?? 0),
                    'updated_last_6h' => (int)($inventoryData['updated_last_6h'] ?? 0),
                    'updated_last_24h' => (int)($inventoryData['updated_last_24h'] ?? 0),
                    'newest_update' => $inventoryData['newest_update'] ?? null,
                    'hours_since_update' => !empty($inventoryData['newest_update']) ? 
                        round((time() - strtotime($inventoryData['newest_update'])) / 3600, 1) : null
                ]
            ];
            
        } catch (Exception $e) {
            return [
                'status' => 'critical',
                'message' => 'Data freshness check failed: ' . $e->getMessage(),
                'error' => $e->getMessage()
            ];
        }
    }
    
    /**
     * Check system resources
     */
    private function checkSystemResources(): array
    {
        $this->logger->debug('Checking system resources');
        
        try {
            $resources = [
                'memory_usage_mb' => round(memory_get_usage(true) / 1024 / 1024, 2),
                'memory_peak_mb' => round(memory_get_peak_usage(true) / 1024 / 1024, 2),
                'memory_limit' => ini_get('memory_limit'),
                'time_limit' => ini_get('max_execution_time'),
                'php_version' => PHP_VERSION
            ];
            
            // Check disk space if possible
            $logDir = $this->config['logging']['log_directory'] ?? '/tmp';
            if (is_dir($logDir)) {
                $diskFree = disk_free_space($logDir);
                $diskTotal = disk_total_space($logDir);
                
                if ($diskFree !== false && $diskTotal !== false) {
                    $resources['disk_free_gb'] = round($diskFree / 1024 / 1024 / 1024, 2);
                    $resources['disk_total_gb'] = round($diskTotal / 1024 / 1024 / 1024, 2);
                    $resources['disk_usage_percent'] = round((($diskTotal - $diskFree) / $diskTotal) * 100, 2);
                }
            }
            
            // Determine status based on resource usage
            $status = 'healthy';
            $messages = [];
            
            // Check memory usage (warn if over 80% of limit)
            $memoryLimitBytes = $this->parseMemoryLimit($resources['memory_limit']);
            if ($memoryLimitBytes > 0) {
                $memoryUsagePercent = ($resources['memory_usage_mb'] * 1024 * 1024) / $memoryLimitBytes * 100;
                
                if ($memoryUsagePercent > 80) {
                    $status = 'warning';
                    $messages[] = "High memory usage: {$memoryUsagePercent}%";
                }
            }
            
            // Check disk space (warn if over 90% full)
            if (isset($resources['disk_usage_percent']) && $resources['disk_usage_percent'] > 90) {
                $status = 'warning';
                $messages[] = "Low disk space: {$resources['disk_usage_percent']}% used";
            }
            
            $message = empty($messages) ? 'System resources are healthy' : implode('; ', $messages);
            
            return [
                'status' => $status,
                'message' => $message,
                'resources' => $resources
            ];
            
        } catch (Exception $e) {
            return [
                'status' => 'warning',
                'message' => 'System resources check failed: ' . $e->getMessage(),
                'error' => $e->getMessage()
            ];
        }
    }
    
    /**
     * Check for stale lock files
     */
    private function checkLockFiles(): array
    {
        $this->logger->debug('Checking lock files');
        
        try {
            $lockDir = $this->config['locks']['lock_directory'] ?? '/tmp';
            $staleLocks = [];
            $activeLocks = [];
            
            if (is_dir($lockDir)) {
                $lockFiles = glob($lockDir . '/*.lock');
                
                foreach ($lockFiles as $lockFile) {
                    $lockContent = file_get_contents($lockFile);
                    $lockData = json_decode($lockContent, true);
                    
                    if ($lockData && isset($lockData['pid'])) {
                        // Check if process is still running
                        if (function_exists('posix_kill') && posix_kill($lockData['pid'], 0)) {
                            $activeLocks[] = [
                                'file' => basename($lockFile),
                                'pid' => $lockData['pid'],
                                'started_at' => $lockData['started_at'] ?? 'unknown'
                            ];
                        } else {
                            $staleLocks[] = [
                                'file' => basename($lockFile),
                                'pid' => $lockData['pid'],
                                'started_at' => $lockData['started_at'] ?? 'unknown'
                            ];
                        }
                    } else {
                        $staleLocks[] = [
                            'file' => basename($lockFile),
                            'pid' => 'unknown',
                            'started_at' => 'unknown'
                        ];
                    }
                }
            }
            
            $status = 'healthy';
            $message = 'Lock files are clean';
            
            if (!empty($staleLocks)) {
                $status = 'warning';
                $message = count($staleLocks) . ' stale lock files found';
            }
            
            return [
                'status' => $status,
                'message' => $message,
                'active_locks' => $activeLocks,
                'stale_locks' => $staleLocks,
                'lock_directory' => $lockDir
            ];
            
        } catch (Exception $e) {
            return [
                'status' => 'warning',
                'message' => 'Lock files check failed: ' . $e->getMessage(),
                'error' => $e->getMessage()
            ];
        }
    }
    
    /**
     * Check log files
     */
    private function checkLogFiles(): array
    {
        $this->logger->debug('Checking log files');
        
        try {
            $logDir = $this->config['logging']['log_directory'] ?? '/tmp';
            $logInfo = [];
            
            if (is_dir($logDir)) {
                $logFiles = glob($logDir . '/*.log');
                
                foreach ($logFiles as $logFile) {
                    $fileInfo = [
                        'file' => basename($logFile),
                        'size_mb' => round(filesize($logFile) / 1024 / 1024, 2),
                        'modified' => date('Y-m-d H:i:s', filemtime($logFile))
                    ];
                    
                    $logInfo[] = $fileInfo;
                }
                
                // Sort by modification time (newest first)
                usort($logInfo, function($a, $b) {
                    return strtotime($b['modified']) - strtotime($a['modified']);
                });
            }
            
            $status = 'healthy';
            $message = 'Log files are accessible';
            
            // Check for very large log files (over 100MB)
            $largeLogs = array_filter($logInfo, function($log) {
                return $log['size_mb'] > 100;
            });
            
            if (!empty($largeLogs)) {
                $status = 'warning';
                $message = count($largeLogs) . ' large log files found (>100MB)';
            }
            
            return [
                'status' => $status,
                'message' => $message,
                'log_directory' => $logDir,
                'log_files' => array_slice($logInfo, 0, 10), // Show only first 10
                'total_log_files' => count($logInfo),
                'large_log_files' => $largeLogs
            ];
            
        } catch (Exception $e) {
            return [
                'status' => 'warning',
                'message' => 'Log files check failed: ' . $e->getMessage(),
                'error' => $e->getMessage()
            ];
        }
    }
    
    /**
     * Calculate overall health status
     */
    private function calculateOverallHealth(): array
    {
        $criticalCount = 0;
        $warningCount = 0;
        $healthyCount = 0;
        
        $checks = ['database', 'etl_execution', 'data_freshness', 'system_resources', 'lock_files', 'log_files'];
        
        foreach ($checks as $check) {
            if (!isset($this->healthStatus[$check])) {
                continue;
            }
            
            $status = $this->healthStatus[$check]['status'] ?? 'unknown';
            
            switch ($status) {
                case 'critical':
                    $criticalCount++;
                    break;
                case 'warning':
                    $warningCount++;
                    break;
                case 'healthy':
                    $healthyCount++;
                    break;
            }
        }
        
        // Determine overall status
        if ($criticalCount > 0) {
            $overallStatus = 'critical';
            $message = "System has critical issues ({$criticalCount} critical, {$warningCount} warnings)";
        } elseif ($warningCount > 0) {
            $overallStatus = 'warning';
            $message = "System has warnings ({$warningCount} warnings, {$healthyCount} healthy)";
        } else {
            $overallStatus = 'healthy';
            $message = "All systems are healthy ({$healthyCount} checks passed)";
        }
        
        return [
            'status' => $overallStatus,
            'message' => $message,
            'summary' => [
                'critical' => $criticalCount,
                'warning' => $warningCount,
                'healthy' => $healthyCount,
                'total_checks' => count($checks)
            ]
        ];
    }
    
    /**
     * Parse memory limit string to bytes
     */
    private function parseMemoryLimit(string $memoryLimit): int
    {
        $memoryLimit = trim($memoryLimit);
        
        if ($memoryLimit === '-1') {
            return 0; // Unlimited
        }
        
        $unit = strtolower(substr($memoryLimit, -1));
        $value = (int)substr($memoryLimit, 0, -1);
        
        switch ($unit) {
            case 'g':
                return $value * 1024 * 1024 * 1024;
            case 'm':
                return $value * 1024 * 1024;
            case 'k':
                return $value * 1024;
            default:
                return (int)$memoryLimit;
        }
    }
    
    /**
     * Get health status
     */
    public function getHealthStatus(): array
    {
        return $this->healthStatus;
    }
}

/**
 * Format health status for output
 */
function formatHealthStatus(array $healthStatus, bool $json = false, bool $alertOnly = false): string
{
    if ($json) {
        return json_encode($healthStatus, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    }
    
    $output = '';
    
    // Check if we should output (alert-only mode)
    if ($alertOnly) {
        $overallStatus = $healthStatus['overall']['status'] ?? 'unknown';
        if ($overallStatus === 'healthy') {
            return ''; // No output for healthy status in alert-only mode
        }
    }
    
    $output .= "ETL Health Check Report\n";
    $output .= "======================\n";
    $output .= "Checked: " . ($healthStatus['check_info']['checked_at'] ?? 'Unknown') . "\n";
    $output .= "Duration: " . ($healthStatus['check_info']['duration_seconds'] ?? 'Unknown') . " seconds\n\n";
    
    // Overall status
    $overall = $healthStatus['overall'] ?? [];
    $statusIcon = match($overall['status'] ?? 'unknown') {
        'healthy' => '✅',
        'warning' => '⚠️',
        'critical' => '❌',
        default => '❓'
    };
    
    $output .= "Overall Status: {$statusIcon} " . strtoupper($overall['status'] ?? 'UNKNOWN') . "\n";
    $output .= "Message: " . ($overall['message'] ?? 'No message') . "\n\n";
    
    // Individual checks
    $checks = ['database', 'etl_execution', 'data_freshness', 'system_resources', 'lock_files', 'log_files'];
    
    foreach ($checks as $check) {
        if (!isset($healthStatus[$check])) {
            continue;
        }
        
        $checkData = $healthStatus[$check];
        $checkIcon = match($checkData['status'] ?? 'unknown') {
            'healthy' => '✅',
            'warning' => '⚠️',
            'critical' => '❌',
            default => '❓'
        };
        
        $output .= ucfirst(str_replace('_', ' ', $check)) . ": {$checkIcon} " . strtoupper($checkData['status']) . "\n";
        $output .= "  " . ($checkData['message'] ?? 'No message') . "\n";
        
        // Add specific details for some checks
        if ($check === 'etl_execution' && isset($checkData['last_execution'])) {
            $lastExec = $checkData['last_execution'];
            $output .= "  Last execution: {$lastExec['workflow_id']} ({$lastExec['status']}) at {$lastExec['created_at']}\n";
        }
        
        if ($check === 'data_freshness') {
            if (isset($checkData['products']['hours_since_update'])) {
                $output .= "  Products last updated: {$checkData['products']['hours_since_update']} hours ago\n";
            }
            if (isset($checkData['inventory']['hours_since_update'])) {
                $output .= "  Inventory last updated: {$checkData['inventory']['hours_since_update']} hours ago\n";
            }
        }
        
        $output .= "\n";
    }
    
    return $output;
}

/**
 * Main execution function
 */
function main(): int
{
    global $argv;
    
    $startTime = microtime(true);
    $options = parseArguments($argv);
    
    // Show help if requested
    if ($options['help']) {
        showHelp();
        return 0;
    }
    
    // Load configuration
    try {
        $configFile = $options['config_file'] ?? __DIR__ . '/../Config/cron_config.php';
        $etlConfigFile = __DIR__ . '/../Config/etl_config.php';
        
        if (!file_exists($configFile)) {
            throw new Exception("Configuration file not found: $configFile");
        }
        
        if (!file_exists($etlConfigFile)) {
            throw new Exception("ETL configuration file not found: $etlConfigFile");
        }
        
        $cronConfig = require $configFile;
        $etlConfig = require $etlConfigFile;
        
    } catch (Exception $e) {
        echo "Error loading configuration: " . $e->getMessage() . "\n";
        return 1;
    }
    
    try {
        // Initialize logger
        $logConfig = $cronConfig['logging'] ?? [];
        $logFile = ($logConfig['log_directory'] ?? '/tmp') . '/etl_health_check_' . date('Y-m-d') . '.log';
        
        $logger = new Logger($logFile, $options['verbose'] ? 'DEBUG' : 'INFO');
        
        if ($options['verbose']) {
            echo "Starting ETL health check...\n";
            echo "Log file: $logFile\n";
        }
        
        $logger->info('ETL health check started', [
            'script' => basename(__FILE__),
            'options' => $options
        ]);
        
        // Initialize database connection
        $db = new DatabaseConnection($etlConfig['database']);
        
        // Initialize health checker
        $healthChecker = new ETLHealthChecker($db, $logger, $cronConfig);
        
        // Perform health check
        $healthStatus = $healthChecker->performHealthCheck();
        
        // Format and output results
        $output = formatHealthStatus($healthStatus, $options['json'], $options['alert_only']);
        
        if (!empty($output)) {
            echo $output;
        }
        
        $duration = microtime(true) - $startTime;
        
        $logger->info('ETL health check completed', [
            'duration' => round($duration, 2),
            'overall_status' => $healthStatus['overall']['status'] ?? 'unknown'
        ]);
        
        if ($options['verbose']) {
            echo "Health check completed in " . round($duration, 2) . " seconds\n";
        }
        
        // Return appropriate exit code
        $overallStatus = $healthStatus['overall']['status'] ?? 'unknown';
        return match($overallStatus) {
            'healthy' => 0,
            'warning' => 1,
            'critical' => 2,
            default => 3
        };
        
    } catch (Exception $e) {
        echo "Error: " . $e->getMessage() . "\n";
        
        if (isset($logger)) {
            $logger->error('ETL health check failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
        }
        
        return 1;
    }
}

// Execute main function
exit(main());