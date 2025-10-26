#!/usr/bin/env php
<?php

/**
 * Ozon Product Synchronization Script
 * 
 * CLI script for running ProductETL component to synchronize
 * product catalog data from Ozon API to local database.
 * 
 * Usage:
 *   php sync_products.php [options]
 * 
 * Options:
 *   --config=FILE     Path to configuration file (optional)
 *   --batch-size=N    Number of products to process in each batch (default: 1000)
 *   --max-products=N  Maximum number of products to sync (0 = no limit)
 *   --dry-run         Run without making database changes
 *   --verbose         Enable verbose output
 *   --help            Show this help message
 * 
 * Requirements addressed:
 * - 5.1: Execute product synchronization daily at 02:00 Moscow time
 * - 1.5: Log all product synchronization activities with timestamps
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

use MiCore\ETL\Ozon\Components\ProductETL;
use MiCore\ETL\Ozon\Core\DatabaseConnection;
use MiCore\ETL\Ozon\Core\Logger;
use MiCore\ETL\Ozon\Core\ProcessLock;
use MiCore\ETL\Ozon\Api\OzonApiClient;

/**
 * Parse command line arguments
 */
function parseArguments(array $argv): array {
    $options = [
        'config' => null,
        'batch_size' => 1000,
        'max_products' => 0,
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
        } elseif (strpos($arg, '--batch-size=') === 0) {
            $options['batch_size'] = (int)substr($arg, 13);
        } elseif (strpos($arg, '--max-products=') === 0) {
            $options['max_products'] = (int)substr($arg, 15);
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
    echo "Ozon Product Synchronization Script\n\n";
    echo "Usage: php sync_products.php [options]\n\n";
    echo "Options:\n";
    echo "  --config=FILE     Path to configuration file (optional)\n";
    echo "  --batch-size=N    Number of products to process in each batch (default: 1000)\n";
    echo "  --max-products=N  Maximum number of products to sync (0 = no limit)\n";
    echo "  --dry-run         Run without making database changes\n";
    echo "  --verbose         Enable verbose output\n";
    echo "  --help            Show this help message\n\n";
    echo "Examples:\n";
    echo "  php sync_products.php\n";
    echo "  php sync_products.php --batch-size=500 --verbose\n";
    echo "  php sync_products.php --dry-run --max-products=1000\n\n";
}

/**
 * Create process lock to prevent concurrent execution
 */
function createProcessLock(string $lockDir, Logger $logger): ?ProcessLock {
    $lock = new ProcessLock('sync_products', $lockDir, $logger);
    
    if ($lock->acquire()) {
        return $lock;
    }
    
    return null;
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
    
    // Validate batch size
    if ($options['batch_size'] < 1 || $options['batch_size'] > 10000) {
        echo "Error: Batch size must be between 1 and 10000\n";
        exit(1);
    }
    
    // Initialize logger first
    $logger = new Logger($config['etl']['logging']);
    
    // Create process lock
    $lockDir = $config['etl']['locks']['path'];
    $processLock = createProcessLock($lockDir, $logger);
    if (!$processLock) {
        echo "Error: Another instance of sync_products.php is already running\n";
        exit(1);
    }
    
    try {
        // Initialize components
        $db = new DatabaseConnection($config['database']);
        $apiClient = new OzonApiClient($config['api']);
        
        // Configure ProductETL
        $etlConfig = [
            'batch_size' => $options['batch_size'],
            'max_products' => $options['max_products'],
            'enable_progress' => $options['verbose'],
            'dry_run' => $options['dry_run']
        ];
        
        $productETL = new ProductETL($db, $logger, $apiClient, $etlConfig);
        
        // Log start of execution
        $logger->info('Starting product synchronization', [
            'script' => 'sync_products.php',
            'options' => $options,
            'pid' => getmypid()
        ]);
        
        if ($options['verbose']) {
            echo "Starting product synchronization...\n";
            echo "Batch size: {$options['batch_size']}\n";
            echo "Max products: " . ($options['max_products'] ?: 'unlimited') . "\n";
            echo "Dry run: " . ($options['dry_run'] ? 'yes' : 'no') . "\n\n";
        }
        
        // Execute ETL process
        $result = $productETL->execute();
        
        // Log completion
        $logger->info('Product synchronization completed', [
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
        $logger->error('Product synchronization failed', [
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString(),
            'memory_usage_mb' => round(memory_get_usage(true) / 1024 / 1024, 2)
        ]);
        
        echo "Error: " . $e->getMessage() . "\n";
        
        if ($options['verbose']) {
            echo "Stack trace:\n" . $e->getTraceAsString() . "\n";
        }
        
        exit(1);
    } finally {
        // Release process lock
        if (isset($processLock)) {
            $processLock->release();
        }
    }
}

// Execute main function
main();