#!/usr/bin/env php
<?php

/**
 * Data Quality Monitor CLI Script
 * Runs scheduled data quality checks and monitoring
 */

require_once __DIR__ . '/src/MDM/Services/DataQualityScheduler.php';
require_once __DIR__ . '/src/MDM/Services/DataQualityAlertService.php';
require_once __DIR__ . '/src/MDM/Services/DataQualityService.php';
require_once __DIR__ . '/src/MDM/Services/PerformanceMonitoringService.php';
require_once __DIR__ . '/src/MDM/Services/NotificationService.php';

use MDM\Services\DataQualityScheduler;
use MDM\Services\DataQualityAlertService;

function printUsage() {
    echo "Data Quality Monitor CLI\n";
    echo "Usage: php data_quality_monitor.php [command] [options]\n\n";
    echo "Commands:\n";
    echo "  run                    Run all scheduled checks\n";
    echo "  check [check_name]     Force run a specific check\n";
    echo "  status                 Show scheduler status\n";
    echo "  history [check_name]   Show execution history\n";
    echo "  init                   Initialize scheduler tables\n";
    echo "  enable [check_name]    Enable a check\n";
    echo "  disable [check_name]   Disable a check\n";
    echo "  interval [check_name] [seconds]  Update check interval\n\n";
    echo "Available checks:\n";
    echo "  - quality_metrics_update\n";
    echo "  - alert_check\n";
    echo "  - weekly_report\n";
    echo "  - performance_cleanup\n\n";
    echo "Examples:\n";
    echo "  php data_quality_monitor.php run\n";
    echo "  php data_quality_monitor.php check alert_check\n";
    echo "  php data_quality_monitor.php status\n";
    echo "  php data_quality_monitor.php interval alert_check 1800\n";
}

function formatBytes($bytes) {
    $units = ['B', 'KB', 'MB', 'GB'];
    $bytes = max($bytes, 0);
    $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
    $pow = min($pow, count($units) - 1);
    
    $bytes /= pow(1024, $pow);
    
    return round($bytes, 2) . ' ' . $units[$pow];
}

function printResults($results) {
    foreach ($results as $checkName => $result) {
        echo "\n" . str_repeat('=', 50) . "\n";
        echo "Check: {$checkName}\n";
        echo "Status: {$result['status']}\n";
        echo "Message: {$result['message']}\n";
        echo "Execution Time: {$result['execution_time_ms']} ms\n";
        echo "Executed At: {$result['executed_at']}\n";
        
        if (isset($result['alerts_generated'])) {
            echo "Alerts Generated: {$result['alerts_generated']}\n";
        }
        
        if (isset($result['deleted_records'])) {
            echo "Records Deleted: {$result['deleted_records']}\n";
        }
        
        if ($result['status'] === 'error' && isset($result['trace'])) {
            echo "\nError Details:\n";
            echo $result['trace'] . "\n";
        }
    }
}

function printStatus($statusData) {
    echo "\nScheduler Status:\n";
    echo str_repeat('=', 80) . "\n";
    printf("%-25s %-10s %-15s %-20s %-15s\n", 
           'Check Name', 'Enabled', 'Interval', 'Last Run', 'Last Status');
    echo str_repeat('-', 80) . "\n";
    
    foreach ($statusData as $status) {
        $enabled = $status['enabled'] ? 'Yes' : 'No';
        $interval = gmdate('H:i:s', $status['interval_seconds']);
        $lastRun = $status['last_run'] ? date('Y-m-d H:i:s', strtotime($status['last_run'])) : 'Never';
        $lastStatus = $status['last_execution_status'] ?? 'Unknown';
        
        printf("%-25s %-10s %-15s %-20s %-15s\n", 
               $status['check_name'], $enabled, $interval, $lastRun, $lastStatus);
    }
}

function printHistory($historyData) {
    echo "\nExecution History:\n";
    echo str_repeat('=', 100) . "\n";
    printf("%-25s %-10s %-15s %-20s %-30s\n", 
           'Check Name', 'Status', 'Exec Time (ms)', 'Executed At', 'Message');
    echo str_repeat('-', 100) . "\n";
    
    foreach ($historyData as $entry) {
        $message = strlen($entry['message']) > 30 ? 
                   substr($entry['message'], 0, 27) . '...' : 
                   $entry['message'];
        
        printf("%-25s %-10s %-15s %-20s %-30s\n", 
               $entry['check_name'], 
               $entry['status'], 
               $entry['execution_time_ms'], 
               $entry['executed_at'], 
               $message);
    }
}

// Main execution
try {
    $scheduler = new DataQualityScheduler();
    
    if ($argc < 2) {
        printUsage();
        exit(1);
    }
    
    $command = $argv[1];
    
    switch ($command) {
        case 'run':
            echo "Running scheduled data quality checks...\n";
            $results = $scheduler->runScheduledChecks();
            
            if (empty($results)) {
                echo "No checks were due to run.\n";
            } else {
                echo "Executed " . count($results) . " checks.\n";
                printResults($results);
            }
            break;
            
        case 'check':
            if ($argc < 3) {
                echo "Error: Check name required.\n";
                printUsage();
                exit(1);
            }
            
            $checkName = $argv[2];
            echo "Force running check: {$checkName}...\n";
            $result = $scheduler->forceRunCheck($checkName);
            printResults([$checkName => $result]);
            break;
            
        case 'status':
            $status = $scheduler->getSchedulerStatus();
            printStatus($status);
            break;
            
        case 'history':
            $checkName = $argc >= 3 ? $argv[2] : null;
            $history = $scheduler->getExecutionHistory($checkName, 20);
            printHistory($history);
            break;
            
        case 'init':
            echo "Initializing scheduler tables...\n";
            $scheduler->initializeScheduler();
            echo "Scheduler initialized successfully.\n";
            break;
            
        case 'enable':
            if ($argc < 3) {
                echo "Error: Check name required.\n";
                printUsage();
                exit(1);
            }
            
            $checkName = $argv[2];
            if ($scheduler->toggleCheck($checkName, true)) {
                echo "Check '{$checkName}' enabled.\n";
            } else {
                echo "Error: Check '{$checkName}' not found.\n";
                exit(1);
            }
            break;
            
        case 'disable':
            if ($argc < 3) {
                echo "Error: Check name required.\n";
                printUsage();
                exit(1);
            }
            
            $checkName = $argv[2];
            if ($scheduler->toggleCheck($checkName, false)) {
                echo "Check '{$checkName}' disabled.\n";
            } else {
                echo "Error: Check '{$checkName}' not found.\n";
                exit(1);
            }
            break;
            
        case 'interval':
            if ($argc < 4) {
                echo "Error: Check name and interval (in seconds) required.\n";
                printUsage();
                exit(1);
            }
            
            $checkName = $argv[2];
            $interval = (int) $argv[3];
            
            if ($interval <= 0) {
                echo "Error: Interval must be a positive number.\n";
                exit(1);
            }
            
            if ($scheduler->updateCheckInterval($checkName, $interval)) {
                echo "Check '{$checkName}' interval updated to {$interval} seconds.\n";
            } else {
                echo "Error: Check '{$checkName}' not found.\n";
                exit(1);
            }
            break;
            
        default:
            echo "Error: Unknown command '{$command}'.\n";
            printUsage();
            exit(1);
    }
    
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
    echo "Stack trace:\n" . $e->getTraceAsString() . "\n";
    exit(1);
}

echo "\nMemory usage: " . formatBytes(memory_get_peak_usage(true)) . "\n";
echo "Execution time: " . round((microtime(true) - $_SERVER['REQUEST_TIME_FLOAT']) * 1000, 2) . " ms\n";