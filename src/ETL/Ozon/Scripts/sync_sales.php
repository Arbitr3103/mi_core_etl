#!/usr/bin/env php
<?php

/**
 * Ozon Sales Synchronization Script
 * 
 * CLI script for running SalesETL component to synchronize
 * sales history data from Ozon API to local database.
 * 
 * Usage:
 *   php sync_sales.php [options]
 * 
 * Options:
 *   --config=FILE     Path to configuration file (optional)
 *   --since=DATE      Start date for sales extraction (YYYY-MM-DD format)
 *   --to=DATE         End date for sales extraction (YYYY-MM-DD format)
 *   --days=N          Number of days back from today (default: 30)
 *   --batch-size=N    Number of orders to process in each batch (default: 1000)
 *   --dry-run         Run without making database changes
 *   --verbose         Enable verbose output
 *   --help            Show this help message
 * 
 * Requirements addressed:
 * - 5.2: Execute sales data extraction daily at 03:00 Moscow time
 * - 2.5: Process sales data for the last 30 days to enable ADS calculations
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

use MiCore\ETL\Ozon\Components\SalesETL;
use MiCore\ETL\Ozon\Core\DatabaseConnection;
use MiCore\ETL\Ozon\Core\Logger;
use MiCore\ETL\Ozon\Api\OzonApiClient;

/**
 * Parse command line arguments
 */
function parseArguments(array $argv): array {
    $options = [
        'config' => null,
        'since' => null,
        'to' => null,
        'days' => 30,
        'batch_size' => 1000,
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
        } elseif (strpos($arg, '--since=') === 0) {
            $options['since'] = substr($arg, 8);
        } elseif (strpos($arg, '--to=') === 0) {
            $options['to'] = substr($arg, 5);
        } elseif (strpos($arg, '--days=') === 0) {
            $options['days'] = (int)substr($arg, 7);
        } elseif (strpos($arg, '--batch-size=') === 0) {
            $options['batch_size'] = (int)substr($arg, 13);
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
    echo "Ozon Sales Synchronization Script\n\n";
    echo "Usage: php sync_sales.php [options]\n\n";
    echo "Options:\n";
    echo "  --config=FILE     Path to configuration file (optional)\n";
    echo "  --since=DATE      Start date for sales extraction (YYYY-MM-DD format)\n";
    echo "  --to=DATE         End date for sales extraction (YYYY-MM-DD format)\n";
    echo "  --days=N          Number of days back from today (default: 30)\n";
    echo "  --batch-size=N    Number of orders to process in each batch (default: 1000)\n";
    echo "  --dry-run         Run without making database changes\n";
    echo "  --verbose         Enable verbose output\n";
    echo "  --help            Show this help message\n\n";
    echo "Date Options:\n";
    echo "  If --since and --to are provided, they take precedence over --days\n";
    echo "  If only --days is provided, extracts sales from N days ago to today\n";
    echo "  Default behavior: extract sales from last 30 days\n\n";
    echo "Examples:\n";
    echo "  php sync_sales.php\n";
    echo "  php sync_sales.php --days=7 --verbose\n";
    echo "  php sync_sales.php --since=2024-01-01 --to=2024-01-31\n";
    echo "  php sync_sales.php --dry-run --batch-size=500\n\n";
}

/**
 * Validate and normalize date parameters
 */
function validateDates(array &$options): void {
    $now = new DateTime();
    
    // If specific dates are provided, validate them
    if ($options['since'] || $options['to']) {
        if ($options['since']) {
            $sinceDate = DateTime::createFromFormat('Y-m-d', $options['since']);
            if (!$sinceDate) {
                throw new InvalidArgumentException("Invalid since date format. Use YYYY-MM-DD");
            }
            $options['since'] = $sinceDate->format('Y-m-d\TH:i:s\Z');
        }
        
        if ($options['to']) {
            $toDate = DateTime::createFromFormat('Y-m-d', $options['to']);
            if (!$toDate) {
                throw new InvalidArgumentException("Invalid to date format. Use YYYY-MM-DD");
            }
            // Set to end of day
            $toDate->setTime(23, 59, 59);
            $options['to'] = $toDate->format('Y-m-d\TH:i:s\Z');
        }
        
        // If only one date is provided, set the other
        if ($options['since'] && !$options['to']) {
            $options['to'] = $now->format('Y-m-d\TH:i:s\Z');
        }
        if ($options['to'] && !$options['since']) {
            $sinceDate = clone $now;
            $sinceDate->modify('-30 days');
            $options['since'] = $sinceDate->format('Y-m-d\TH:i:s\Z');
        }
    } else {
        // Use days parameter
        if ($options['days'] < 1 || $options['days'] > 365) {
            throw new InvalidArgumentException("Days parameter must be between 1 and 365");
        }
        
        $sinceDate = clone $now;
        $sinceDate->modify("-{$options['days']} days");
        $options['since'] = $sinceDate->format('Y-m-d\TH:i:s\Z');
        $options['to'] = $now->format('Y-m-d\TH:i:s\Z');
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
        // Validate and normalize dates
        validateDates($options);
        
        // Validate batch size
        if ($options['batch_size'] < 1 || $options['batch_size'] > 10000) {
            throw new InvalidArgumentException("Batch size must be between 1 and 10000");
        }
        
    } catch (InvalidArgumentException $e) {
        echo "Error: " . $e->getMessage() . "\n";
        exit(1);
    }
    
    // Create process lock
    $lockFile = $config['etl']['locks']['path'] . '/sync_sales.lock';
    if (!createProcessLock($lockFile)) {
        echo "Error: Another instance of sync_sales.php is already running\n";
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
        
        // Configure SalesETL
        $etlConfig = [
            'since' => $options['since'],
            'to' => $options['to'],
            'batch_size' => $options['batch_size'],
            'enable_progress' => $options['verbose'],
            'dry_run' => $options['dry_run']
        ];
        
        $salesETL = new SalesETL($db, $logger, $apiClient, $etlConfig);
        
        // Log start of execution
        $logger->info('Starting sales synchronization', [
            'script' => 'sync_sales.php',
            'options' => $options,
            'pid' => getmypid(),
            'date_range' => [
                'since' => $options['since'],
                'to' => $options['to']
            ]
        ]);
        
        if ($options['verbose']) {
            echo "Starting sales synchronization...\n";
            echo "Date range: {$options['since']} to {$options['to']}\n";
            echo "Batch size: {$options['batch_size']}\n";
            echo "Dry run: " . ($options['dry_run'] ? 'yes' : 'no') . "\n\n";
        }
        
        // Execute ETL process
        $result = $salesETL->execute();
        
        // Log completion
        $logger->info('Sales synchronization completed', [
            'success' => $result->isSuccess(),
            'records_processed' => $result->getRecordsProcessed(),
            'duration_seconds' => $result->getDuration(),
            'memory_peak_mb' => round(memory_get_peak_usage(true) / 1024 / 1024, 2)
        ]);
        
        if ($options['verbose']) {
            echo "\nSynchronization completed successfully!\n";
            echo "Records processed: {$result->getRecordsProcessed()}\n";
            echo "Duration: " . round($result->getDuration(), 2) . " seconds\n";
            echo "Peak memory usage: " . round(memory_get_peak_usage(true) / 1024 / 1024, 2) . " MB\n";
        }
        
        exit(0);
        
    } catch (Exception $e) {
        $logger->error('Sales synchronization failed', [
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