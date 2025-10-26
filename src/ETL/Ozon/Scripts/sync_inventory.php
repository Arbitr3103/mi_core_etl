#!/usr/bin/env php
<?php

/**
 * Ozon Inventory Synchronization Script
 * 
 * CLI script for running InventoryETL component to synchronize
 * warehouse stock data from Ozon API to local database.
 * 
 * Usage:
 *   php sync_inventory.php [options]
 * 
 * Options:
 *   --config=FILE         Path to configuration file (optional)
 *   --timeout=SECONDS     Timeout for report generation (default: 1800)
 *   --poll-interval=SEC   Polling interval for report status (default: 60)
 *   --language=LANG       Report language (DEFAULT, RU, EN) (default: DEFAULT)
 *   --dry-run             Run without making database changes
 *   --verbose             Enable verbose output
 *   --help                Show this help message
 * 
 * Requirements addressed:
 * - 5.3: Execute inventory update daily at 04:00 Moscow time
 * - 3.5: Completely refresh inventory table with each update to ensure data accuracy
 */

declare(strict_types=1);

// Ensure script is run from command line
if (php_sapi_name() !== 'cli') {
    echo "This script must be run from the command line.\n";
    exit(1);
}

// Load autoloader and configuration
try {
    $config = require __DIR__ . '/../autoload.php';
} catch (Exception $e) {
    echo "Error loading configuration: " . $e->getMessage() . "\n";
    exit(1);
}

use MiCore\ETL\Ozon\Components\InventoryETL;
use MiCore\ETL\Ozon\Core\DatabaseConnection;
use MiCore\ETL\Ozon\Core\Logger;
use MiCore\ETL\Ozon\Api\OzonApiClient;

/**
 * Parse command line arguments
 */
function parseArguments(array $argv): array {
    $options = [
        'config' => null,
        'timeout' => 1800, // 30 minutes
        'poll_interval' => 60, // 1 minute
        'language' => 'DEFAULT',
        'dry_run' => false,
        'verbose' => false,
        'help' => false
    ];

    for ($i = 1; $i < count($argv); $i++) {
        $arg = $argv[$i];
        
        if ($arg === '--help' || $arg === '-h') {
            $options['help'] = true;
        } elseif ($arg === '--dry-run') {
            $options['dry_run'] = true;
        } elseif ($arg === '--verbose' || $arg === '-v') {
            $options['verbose'] = true;
        } elseif (strpos($arg, '--config=') === 0) {
            $options['config'] = substr($arg, 9);
        } elseif (strpos($arg, '--timeout=') === 0) {
            $options['timeout'] = (int)substr($arg, 10);
        } elseif (strpos($arg, '--poll-interval=') === 0) {
            $options['poll_interval'] = (int)substr($arg, 16);
        } elseif (strpos($arg, '--language=') === 0) {
            $options['language'] = substr($arg, 11);
        } else {
            echo "Unknown option: $arg\n";
            echo "Use --help for usage information.\n";
            exit(1);
        }
    }

    return $options;
}

/**
 * Show help message
 */
function showHelp(): void {
    echo "Ozon Inventory Synchronization Script\n\n";
    echo "Usage: php sync_inventory.php [options]\n\n";
    echo "Options:\n";
    echo "  --config=FILE         Path to configuration file (optional)\n";
    echo "  --timeout=SECONDS     Timeout for report generation (default: 1800)\n";
    echo "  --poll-interval=SEC   Polling interval for report status (default: 60)\n";
    echo "  --language=LANG       Report language (DEFAULT, RU, EN) (default: DEFAULT)\n";
    echo "  --dry-run             Run without making database changes\n";
    echo "  --verbose             Enable verbose output\n";
    echo "  --help                Show this help message\n\n";
    echo "Report Generation:\n";
    echo "  This script requests a warehouse stock report from Ozon API,\n";
    echo "  waits for it to be generated, downloads the CSV file, and\n";
    echo "  updates the local inventory table with the latest data.\n\n";
    echo "  The process can take several minutes depending on the amount\n";
    echo "  of data. Use --timeout to adjust the maximum wait time.\n\n";
    echo "Examples:\n";
    echo "  php sync_inventory.php\n";
    echo "  php sync_inventory.php --verbose --timeout=3600\n";
    echo "  php sync_inventory.php --dry-run --poll-interval=30\n";
    echo "  php sync_inventory.php --language=RU\n\n";
}

/**
 * Validate configuration parameters
 */
function validateOptions(array $options): void {
    // Validate timeout
    if ($options['timeout'] < 60 || $options['timeout'] > 7200) {
        throw new InvalidArgumentException("Timeout must be between 60 and 7200 seconds");
    }
    
    // Validate poll interval
    if ($options['poll_interval'] < 10 || $options['poll_interval'] > 300) {
        throw new InvalidArgumentException("Poll interval must be between 10 and 300 seconds");
    }
    
    // Validate language
    $validLanguages = ['DEFAULT', 'RU', 'EN'];
    if (!in_array($options['language'], $validLanguages)) {
        throw new InvalidArgumentException("Language must be one of: " . implode(', ', $validLanguages));
    }
    
    // Ensure poll interval is not greater than timeout
    if ($options['poll_interval'] >= $options['timeout']) {
        throw new InvalidArgumentException("Poll interval must be less than timeout");
    }
}

/**
 * Create process lock to prevent concurrent execution
 */
function createProcessLock(string $lockFile): bool {
    if (file_exists($lockFile)) {
        $pid = (int)file_get_contents($lockFile);
        
        // Check if process is still running
        if (posix_kill($pid, 0)) {
            return false; // Process is still running
        } else {
            // Stale lock file, remove it
            unlink($lockFile);
        }
    }
    
    // Create new lock file
    file_put_contents($lockFile, getmypid());
    return true;
}

/**
 * Remove process lock
 */
function removeProcessLock(string $lockFile): void {
    if (file_exists($lockFile)) {
        unlink($lockFile);
    }
}

/**
 * Format duration in human readable format
 */
function formatDuration(float $seconds): string {
    if ($seconds < 60) {
        return round($seconds, 1) . ' seconds';
    } elseif ($seconds < 3600) {
        $minutes = floor($seconds / 60);
        $remainingSeconds = $seconds % 60;
        return $minutes . 'm ' . round($remainingSeconds, 1) . 's';
    } else {
        $hours = floor($seconds / 3600);
        $minutes = floor(($seconds % 3600) / 60);
        return $hours . 'h ' . $minutes . 'm';
    }
}

/**
 * Main execution function
 */
function main(): void {
    global $config;
    
    $options = parseArguments($_SERVER['argv']);
    
    if ($options['help']) {
        showHelp();
        exit(0);
    }
    
    // Load custom configuration if provided
    if ($options['config'] && file_exists($options['config'])) {
        $customConfig = require $options['config'];
        $config = array_merge_recursive($config, $customConfig);
    }
    
    try {
        // Validate options
        validateOptions($options);
        
    } catch (InvalidArgumentException $e) {
        echo "Error: " . $e->getMessage() . "\n";
        exit(1);
    }
    
    // Create process lock
    $lockFile = $config['etl']['locks']['path'] . '/sync_inventory.lock';
    if (!createProcessLock($lockFile)) {
        echo "Error: Another instance of sync_inventory.php is already running\n";
        exit(1);
    }
    
    // Register shutdown function to clean up lock
    register_shutdown_function(function() use ($lockFile) {
        removeProcessLock($lockFile);
    });
    
    try {
        // Initialize components
        $logger = new Logger($config['etl']['logging']);
        $db = new DatabaseConnection($config['database']);
        $apiClient = new OzonApiClient($config['api']);
        
        // Configure InventoryETL
        $etlConfig = [
            'report_timeout' => $options['timeout'],
            'poll_interval' => $options['poll_interval'],
            'language' => $options['language'],
            'enable_progress' => $options['verbose'],
            'dry_run' => $options['dry_run']
        ];
        
        $inventoryETL = new InventoryETL($db, $logger, $apiClient, $etlConfig);
        
        // Log start of execution
        $logger->info('Starting inventory synchronization', [
            'script' => 'sync_inventory.php',
            'options' => $options,
            'pid' => getmypid()
        ]);
        
        if ($options['verbose']) {
            echo "Starting inventory synchronization...\n";
            echo "Report timeout: " . formatDuration($options['timeout']) . "\n";
            echo "Poll interval: {$options['poll_interval']} seconds\n";
            echo "Language: {$options['language']}\n";
            echo "Dry run: " . ($options['dry_run'] ? 'yes' : 'no') . "\n\n";
            echo "Note: Report generation may take several minutes...\n\n";
        }
        
        // Execute ETL process
        $startTime = microtime(true);
        $result = $inventoryETL->execute();
        $totalDuration = microtime(true) - $startTime;
        
        // Log completion
        $logger->info('Inventory synchronization completed', [
            'success' => $result->isSuccess(),
            'records_processed' => $result->getRecordsProcessed(),
            'duration_seconds' => $result->getDuration(),
            'total_duration_seconds' => $totalDuration,
            'memory_peak_mb' => round(memory_get_peak_usage(true) / 1024 / 1024, 2)
        ]);
        
        if ($options['verbose']) {
            echo "\nSynchronization completed successfully!\n";
            echo "Records processed: {$result->getRecordsProcessed()}\n";
            echo "ETL duration: " . formatDuration($result->getDuration()) . "\n";
            echo "Total duration: " . formatDuration($totalDuration) . "\n";
            echo "Peak memory usage: " . round(memory_get_peak_usage(true) / 1024 / 1024, 2) . " MB\n";
        }
        
        exit(0);
        
    } catch (Exception $e) {
        $logger->error('Inventory synchronization failed', [
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString(),
            'memory_usage_mb' => round(memory_get_usage(true) / 1024 / 1024, 2)
        ]);
        
        echo "Error: " . $e->getMessage() . "\n";
        
        if ($options['verbose']) {
            echo "Stack trace:\n" . $e->getTraceAsString() . "\n";
        }
        
        exit(1);
    }
}

// Execute main function
main();