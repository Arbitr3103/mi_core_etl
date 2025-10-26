#!/usr/bin/env php
<?php

/**
 * Ozon ETL Process Monitor Script
 * 
 * Script for monitoring and managing ETL processes.
 * Provides functionality to view status, kill processes, and cleanup stale locks.
 * 
 * Usage:
 *   php process_monitor.php status     - Show process status
 *   php process_monitor.php kill <name> - Kill specific process
 *   php process_monitor.php cleanup    - Clean up stale processes
 *   php process_monitor.php locks      - Show active locks
 * 
 * Requirements addressed:
 * - 5.5: Create mechanism for checking active processes
 */

declare(strict_types=1);

// Ensure script is run from command line
if (php_sapi_name() !== 'cli') {
    echo "This script must be run from the command line.\n";
    exit(1);
}

// Load configuration
try {
    $config = require __DIR__ . '/../autoload.php';
    $cronConfig = require __DIR__ . '/../Config/cron_config.php';
} catch (Exception $e) {
    echo "Error loading configuration: " . $e->getMessage() . "\n";
    exit(1);
}

use MiCore\ETL\Ozon\Core\ProcessManager;
use MiCore\ETL\Ozon\Core\ProcessLock;
use MiCore\ETL\Ozon\Core\SimpleLogger as Logger;

/**
 * Show help message
 */
function showHelp(): void {
    echo "Ozon ETL Process Monitor\n\n";
    echo "Usage: php process_monitor.php <command> [options]\n\n";
    echo "Commands:\n";
    echo "  status              Show current process status\n";
    echo "  kill <process_name> Kill specific process\n";
    echo "  cleanup             Clean up stale processes and locks\n";
    echo "  locks               Show active locks\n";
    echo "  help                Show this help message\n\n";
    echo "Options:\n";
    echo "  --force             Force kill processes (use with kill command)\n";
    echo "  --verbose           Enable verbose output\n";
    echo "  --json              Output in JSON format\n\n";
    echo "Examples:\n";
    echo "  php process_monitor.php status\n";
    echo "  php process_monitor.php kill sync_products --force\n";
    echo "  php process_monitor.php cleanup --verbose\n\n";
}

/**
 * Parse command line arguments
 */
function parseArguments(array $argv): array {
    $options = [
        'command' => $argv[1] ?? 'help',
        'process_name' => $argv[2] ?? null,
        'force' => false,
        'verbose' => false,
        'json' => false
    ];

    for ($i = 2; $i < count($argv); $i++) {
        $arg = $argv[$i];
        
        if ($arg === '--force') {
            $options['force'] = true;
        } elseif ($arg === '--verbose' || $arg === '-v') {
            $options['verbose'] = true;
        } elseif ($arg === '--json') {
            $options['json'] = true;
        } elseif (!isset($options['process_name']) && !str_starts_with($arg, '--')) {
            $options['process_name'] = $arg;
        }
    }

    return $options;
}

/**
 * Format duration in human readable format
 */
function formatDuration(int $seconds): string {
    if ($seconds < 60) {
        return "{$seconds}s";
    } elseif ($seconds < 3600) {
        $minutes = floor($seconds / 60);
        $secs = $seconds % 60;
        return "{$minutes}m {$secs}s";
    } else {
        $hours = floor($seconds / 3600);
        $minutes = floor(($seconds % 3600) / 60);
        return "{$hours}h {$minutes}m";
    }
}

/**
 * Format memory usage
 */
function formatMemory(?float $mb): string {
    if ($mb === null) {
        return 'N/A';
    }
    
    if ($mb < 1024) {
        return round($mb, 1) . ' MB';
    } else {
        return round($mb / 1024, 1) . ' GB';
    }
}

/**
 * Show process status
 */
function showStatus(ProcessManager $processManager, array $options): void {
    $status = $processManager->getProcessStatus();
    
    if ($options['json']) {
        echo json_encode($status, JSON_PRETTY_PRINT) . "\n";
        return;
    }
    
    echo "Ozon ETL Process Status\n";
    echo str_repeat('=', 50) . "\n\n";
    
    // Active processes
    if (empty($status['active_processes'])) {
        echo "No active ETL processes running.\n\n";
    } else {
        echo "Active Processes:\n";
        echo str_repeat('-', 30) . "\n";
        
        foreach ($status['active_processes'] as $process) {
            $runningTime = formatDuration($process['running_time_seconds']);
            $memoryUsage = formatMemory($process['memory_usage_mb'] ?? null);
            $statusIcon = $process['is_running'] ? '🟢' : '🔴';
            
            echo "{$statusIcon} {$process['process_name']}\n";
            echo "   PID: {$process['pid']}\n";
            echo "   Started: {$process['started_at']}\n";
            echo "   Running: {$runningTime}\n";
            echo "   Memory: {$memoryUsage}\n";
            echo "   Host: {$process['hostname']}\n";
            echo "   User: {$process['user']}\n";
            
            if (!$process['is_running']) {
                echo "   ⚠️  Process appears to be stale\n";
            }
            
            echo "\n";
        }
    }
    
    // System information
    if ($options['verbose']) {
        echo "System Information:\n";
        echo str_repeat('-', 30) . "\n";
        $sysInfo = $status['system_info'];
        
        echo "Hostname: {$sysInfo['hostname']}\n";
        echo "PHP Version: {$sysInfo['php_version']}\n";
        echo "Memory Limit: {$sysInfo['memory_limit']}\n";
        echo "Current Memory: " . formatMemory($sysInfo['current_memory_usage_mb']) . "\n";
        echo "Max Execution Time: {$sysInfo['max_execution_time']}s\n";
        echo "Disk Free Space: {$sysInfo['disk_free_space_gb']} GB\n";
        
        if ($sysInfo['load_average']) {
            echo "Load Average: " . implode(', ', array_map(fn($x) => round($x, 2), $sysInfo['load_average'])) . "\n";
        }
        
        echo "\n";
    }
}

/**
 * Show active locks
 */
function showLocks(array $cronConfig, Logger $logger, array $options): void {
    $lockDir = $cronConfig['paths']['lock_dir'];
    $activeLocks = ProcessLock::getActiveLocks($lockDir, $logger);
    
    if ($options['json']) {
        echo json_encode($activeLocks, JSON_PRETTY_PRINT) . "\n";
        return;
    }
    
    echo "Active Process Locks\n";
    echo str_repeat('=', 50) . "\n\n";
    
    if (empty($activeLocks)) {
        echo "No active locks found.\n";
        return;
    }
    
    foreach ($activeLocks as $lock) {
        $age = formatDuration($lock['lock_age_seconds']);
        $statusIcon = $lock['is_process_running'] ? '🔒' : '💀';
        
        echo "{$statusIcon} {$lock['process_name']}\n";
        echo "   Lock File: {$lock['lock_file']}\n";
        echo "   PID: {$lock['pid']}\n";
        echo "   Started: {$lock['started_at']}\n";
        echo "   Age: {$age}\n";
        echo "   Host: {$lock['hostname']}\n";
        echo "   User: {$lock['user']}\n";
        echo "   Running: " . ($lock['is_process_running'] ? 'Yes' : 'No (Stale)') . "\n";
        
        if ($options['verbose']) {
            echo "   PHP Version: {$lock['php_version']}\n";
            echo "   Memory Limit: {$lock['memory_limit']}\n";
            echo "   Max Exec Time: {$lock['max_execution_time']}\n";
        }
        
        echo "\n";
    }
}

/**
 * Kill a process
 */
function killProcess(ProcessManager $processManager, string $processName, array $options): void {
    $force = $options['force'];
    $verbose = $options['verbose'];
    
    if ($verbose) {
        echo "Attempting to kill process: {$processName}\n";
        echo "Force mode: " . ($force ? 'enabled' : 'disabled') . "\n\n";
    }
    
    $success = $processManager->killProcess($processName, $force);
    
    if ($success) {
        echo "✅ Process '{$processName}' killed successfully.\n";
    } else {
        echo "❌ Failed to kill process '{$processName}'.\n";
        
        if (!$force) {
            echo "Try using --force flag for forceful termination.\n";
        }
        
        exit(1);
    }
}

/**
 * Cleanup stale processes
 */
function cleanup(ProcessManager $processManager, array $options): void {
    $verbose = $options['verbose'];
    
    if ($verbose) {
        echo "Starting cleanup of stale processes and locks...\n\n";
    }
    
    $results = $processManager->cleanup();
    
    if ($options['json']) {
        echo json_encode($results, JSON_PRETTY_PRINT) . "\n";
        return;
    }
    
    echo "Cleanup Results:\n";
    echo str_repeat('-', 20) . "\n";
    echo "Stale locks removed: {$results['stale_locks_removed']}\n";
    echo "Stale PIDs removed: {$results['stale_pids_removed']}\n";
    
    if (!empty($results['errors'])) {
        echo "\nErrors encountered:\n";
        foreach ($results['errors'] as $error) {
            echo "  ❌ {$error}\n";
        }
    } else {
        echo "\n✅ Cleanup completed successfully.\n";
    }
}

/**
 * Main execution function
 */
function main(): void {
    global $config, $cronConfig;
    
    $options = parseArguments($_SERVER['argv']);
    
    try {
        // Initialize components
        $logger = new Logger('ozon-etl', $config['etl']['logging']);
        $processManager = new ProcessManager($cronConfig, $logger);
        
        switch ($options['command']) {
            case 'status':
                showStatus($processManager, $options);
                break;
                
            case 'kill':
                if (!$options['process_name']) {
                    echo "Error: Process name required for kill command.\n";
                    echo "Usage: php process_monitor.php kill <process_name> [--force]\n";
                    exit(1);
                }
                killProcess($processManager, $options['process_name'], $options);
                break;
                
            case 'cleanup':
                cleanup($processManager, $options);
                break;
                
            case 'locks':
                showLocks($cronConfig, $logger, $options);
                break;
                
            case 'help':
            default:
                showHelp();
                break;
        }
        
    } catch (Exception $e) {
        echo "Error: " . $e->getMessage() . "\n";
        
        if ($options['verbose']) {
            echo "Stack trace:\n" . $e->getTraceAsString() . "\n";
        }
        
        exit(1);
    }
}

// Execute main function
main();