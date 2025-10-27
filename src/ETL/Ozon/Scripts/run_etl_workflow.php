#!/usr/bin/env php
<?php

/**
 * ETL Workflow Execution Script
 * 
 * Executes the complete ETL workflow (ProductETL -> InventoryETL) with
 * dependency management, retry logic, and comprehensive error handling.
 * 
 * Requirements addressed:
 * - 5.1: Update cron jobs to run ProductETL before InventoryETL with proper timing
 * - 5.1: Add dependency checks to prevent InventoryETL from running if ProductETL failed
 * - 5.1: Implement retry logic for failed ETL processes
 * 
 * Usage:
 *   php run_etl_workflow.php [options]
 * 
 * Options:
 *   --dry-run          Show what would be done without executing
 *   --verbose          Enable verbose output
 *   --config=FILE      Use custom configuration file
 *   --no-retry         Disable retry logic
 *   --no-dependency    Disable dependency checks
 *   --help             Show this help message
 */

declare(strict_types=1);

// Set error reporting and time limits
error_reporting(E_ALL);
ini_set('display_errors', '1');
ini_set('memory_limit', '1024M');
set_time_limit(7200); // 2 hours

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
use MiCore\ETL\Ozon\Core\ETLOrchestrator;
use MiCore\ETL\Ozon\Core\DatabaseConnection;
use MiCore\ETL\Ozon\Core\Logger;
use MiCore\ETL\Ozon\Api\OzonApiClient;

/**
 * Parse command line arguments
 */
function parseArguments(array $argv): array
{
    $options = [
        'dry_run' => false,
        'verbose' => false,
        'config_file' => null,
        'no_retry' => false,
        'no_dependency' => false,
        'help' => false
    ];
    
    for ($i = 1; $i < count($argv); $i++) {
        $arg = $argv[$i];
        
        switch ($arg) {
            case '--dry-run':
                $options['dry_run'] = true;
                break;
            case '--verbose':
                $options['verbose'] = true;
                break;
            case '--no-retry':
                $options['no_retry'] = true;
                break;
            case '--no-dependency':
                $options['no_dependency'] = true;
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
    echo "ETL Workflow Execution Script\n";
    echo "=============================\n\n";
    echo "Executes the complete ETL workflow (ProductETL -> InventoryETL) with\n";
    echo "dependency management, retry logic, and comprehensive error handling.\n\n";
    echo "Usage:\n";
    echo "  php run_etl_workflow.php [options]\n\n";
    echo "Options:\n";
    echo "  --dry-run          Show what would be done without executing\n";
    echo "  --verbose          Enable verbose output\n";
    echo "  --config=FILE      Use custom configuration file\n";
    echo "  --no-retry         Disable retry logic\n";
    echo "  --no-dependency    Disable dependency checks\n";
    echo "  --help             Show this help message\n\n";
    echo "Examples:\n";
    echo "  php run_etl_workflow.php --verbose\n";
    echo "  php run_etl_workflow.php --dry-run --no-retry\n";
    echo "  php run_etl_workflow.php --config=/path/to/custom/config.php\n\n";
}

/**
 * Create lock file to prevent concurrent execution
 */
function createLockFile(string $lockFile): bool
{
    if (file_exists($lockFile)) {
        $lockContent = file_get_contents($lockFile);
        $lockData = json_decode($lockContent, true);
        
        if ($lockData && isset($lockData['pid'])) {
            // Check if process is still running
            if (function_exists('posix_kill') && posix_kill($lockData['pid'], 0)) {
                return false; // Process still running
            }
        }
        
        // Remove stale lock file
        unlink($lockFile);
    }
    
    $lockData = [
        'pid' => getmypid(),
        'started_at' => date('Y-m-d H:i:s'),
        'hostname' => gethostname(),
        'script' => basename(__FILE__)
    ];
    
    return file_put_contents($lockFile, json_encode($lockData, JSON_PRETTY_PRINT)) !== false;
}

/**
 * Remove lock file
 */
function removeLockFile(string $lockFile): void
{
    if (file_exists($lockFile)) {
        unlink($lockFile);
    }
}

/**
 * Setup signal handlers for graceful shutdown
 */
function setupSignalHandlers(string $lockFile): void
{
    if (function_exists('pcntl_signal')) {
        pcntl_signal(SIGTERM, function() use ($lockFile) {
            echo "Received SIGTERM, shutting down gracefully...\n";
            removeLockFile($lockFile);
            exit(0);
        });
        
        pcntl_signal(SIGINT, function() use ($lockFile) {
            echo "Received SIGINT, shutting down gracefully...\n";
            removeLockFile($lockFile);
            exit(0);
        });
    }
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
        
        if (!file_exists($configFile)) {
            throw new Exception("Configuration file not found: $configFile");
        }
        
        $cronConfig = require $configFile;
        $etlConfig = require __DIR__ . '/../Config/etl_config.php';
        
    } catch (Exception $e) {
        echo "Error loading configuration: " . $e->getMessage() . "\n";
        return 1;
    }
    
    // Setup lock file
    $lockDir = $cronConfig['locks']['lock_directory'] ?? '/tmp';
    $lockFile = $lockDir . '/etl_workflow.lock';
    
    if (!is_dir($lockDir)) {
        mkdir($lockDir, 0755, true);
    }
    
    if (!createLockFile($lockFile)) {
        echo "ETL workflow is already running (lock file exists)\n";
        return 1;
    }
    
    // Setup signal handlers
    setupSignalHandlers($lockFile);
    
    try {
        // Initialize logger
        $logConfig = $cronConfig['logging'] ?? [];
        $logFile = $logConfig['log_directory'] ?? '/tmp';
        $logFile .= '/etl_workflow_' . date('Y-m-d') . '.log';
        
        $logger = new Logger($logFile, $options['verbose'] ? 'DEBUG' : 'INFO');
        
        if ($options['verbose']) {
            echo "Starting ETL workflow execution...\n";
            echo "Lock file: $lockFile\n";
            echo "Log file: $logFile\n";
            echo "Configuration: $configFile\n";
        }
        
        $logger->info('ETL workflow execution started', [
            'script' => basename(__FILE__),
            'pid' => getmypid(),
            'options' => $options,
            'config_file' => $configFile
        ]);
        
        // Dry run mode
        if ($options['dry_run']) {
            echo "DRY RUN MODE - No actual execution will occur\n";
            echo "Would execute: ProductETL -> InventoryETL workflow\n";
            echo "Configuration loaded successfully\n";
            
            $logger->info('Dry run completed successfully');
            removeLockFile($lockFile);
            return 0;
        }
        
        // Initialize database connection
        $db = new DatabaseConnection($etlConfig['database']);
        
        // Initialize API client
        $apiClient = new OzonApiClient($etlConfig['ozon_api']);
        
        // Prepare orchestrator configuration
        $orchestratorConfig = [
            'max_retries' => $options['no_retry'] ? 1 : ($cronConfig['retry']['default_max_retries'] ?? 3),
            'retry_delay' => $cronConfig['retry']['default_retry_delay'] ?? 900,
            'enable_dependency_checks' => !$options['no_dependency'],
            'enable_retry_logic' => !$options['no_retry'],
            'product_etl' => $etlConfig['product_etl'] ?? [],
            'inventory_etl' => $etlConfig['inventory_etl'] ?? []
        ];
        
        // Initialize ETL orchestrator
        $orchestrator = new ETLOrchestrator($db, $logger, $apiClient, $orchestratorConfig);
        
        if ($options['verbose']) {
            echo "ETL orchestrator initialized successfully\n";
            echo "Executing workflow...\n";
        }
        
        // Execute ETL workflow
        $result = $orchestrator->executeETLWorkflow([
            'verbose' => $options['verbose']
        ]);
        
        $duration = microtime(true) - $startTime;
        
        if ($options['verbose']) {
            echo "ETL workflow completed successfully\n";
            echo "Duration: " . round($duration, 2) . " seconds\n";
            echo "Workflow ID: " . $result['workflow_id'] . "\n";
        }
        
        $logger->info('ETL workflow execution completed successfully', [
            'duration' => round($duration, 2),
            'result' => $result
        ]);
        
        // Remove lock file
        removeLockFile($lockFile);
        
        return 0;
        
    } catch (Exception $e) {
        $duration = microtime(true) - $startTime;
        
        echo "ETL workflow execution failed: " . $e->getMessage() . "\n";
        
        if (isset($logger)) {
            $logger->error('ETL workflow execution failed', [
                'duration' => round($duration, 2),
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
        }
        
        // Remove lock file
        removeLockFile($lockFile);
        
        return 1;
    }
}

// Execute main function
exit(main());