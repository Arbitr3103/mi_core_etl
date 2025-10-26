#!/usr/bin/env php
<?php

/**
 * Ozon ETL Monitoring Script
 * 
 * Monitors ETL processes and sends alerts based on configured thresholds.
 * Checks for failed processes, performance issues, and system health.
 * 
 * Usage:
 *   php monitor_etl.php [options]
 * 
 * Options:
 *   --check-failures    Check for recent failures
 *   --check-performance Check performance metrics
 *   --check-health      Check system health
 *   --send-summary      Send daily summary
 *   --all               Run all checks (default)
 *   --verbose           Enable verbose output
 * 
 * Requirements addressed:
 * - 4.2: Send alert notifications when ETL process fails
 * - 4.3: Monitor processing metrics and execution time
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

use MiCore\ETL\Ozon\Core\Logger;
use MiCore\ETL\Ozon\Core\AlertManager;
use MiCore\ETL\Ozon\Core\ProcessManager;
use MiCore\ETL\Ozon\Core\DatabaseConnection;

/**
 * Parse command line arguments
 */
function parseArguments(array $argv): array {
    $options = [
        'check_failures' => false,
        'check_performance' => false,
        'check_health' => false,
        'send_summary' => false,
        'all' => true,
        'verbose' => false
    ];

    for ($i = 1; $i < count($argv); $i++) {
        $arg = $argv[$i];
        
        switch ($arg) {
            case '--check-failures':
                $options['check_failures'] = true;
                $options['all'] = false;
                break;
            case '--check-performance':
                $options['check_performance'] = true;
                $options['all'] = false;
                break;
            case '--check-health':
                $options['check_health'] = true;
                $options['all'] = false;
                break;
            case '--send-summary':
                $options['send_summary'] = true;
                $options['all'] = false;
                break;
            case '--all':
                $options['all'] = true;
                break;
            case '--verbose':
            case '-v':
                $options['verbose'] = true;
                break;
            case '--help':
            case '-h':
                showHelp();
                exit(0);
        }
    }
    
    // If --all is true, enable all checks
    if ($options['all']) {
        $options['check_failures'] = true;
        $options['check_performance'] = true;
        $options['check_health'] = true;
    }

    return $options;
}

/**
 * Show help message
 */
function showHelp(): void {
    echo "Ozon ETL Monitoring Script\n\n";
    echo "Usage: php monitor_etl.php [options]\n\n";
    echo "Options:\n";
    echo "  --check-failures    Check for recent failures\n";
    echo "  --check-performance Check performance metrics\n";
    echo "  --check-health      Check system health\n";
    echo "  --send-summary      Send daily summary\n";
    echo "  --all               Run all checks (default)\n";
    echo "  --verbose           Enable verbose output\n";
    echo "  --help              Show this help message\n\n";
}

/**
 * Check for recent ETL failures
 */
function checkFailures(Logger $logger, AlertManager $alertManager, array $options): array {
    $results = ['failures_found' => 0, 'alerts_sent' => 0];
    
    if ($options['verbose']) {
        echo "Checking for recent ETL failures...\n";
    }
    
    try {
        // Check log files for recent failures
        $logDir = $logger->getLogDirectory();
        $failurePatterns = [
            'ERROR',
            'CRITICAL',
            'ETL process failed',
            'Exception',
            'Fatal error'
        ];
        
        $processes = ['sync_products', 'sync_sales', 'sync_inventory'];
        
        foreach ($processes as $process) {
            $logFiles = glob($logDir . "/{$process}_*.log");
            
            // Check the most recent log file
            if (!empty($logFiles)) {
                usort($logFiles, function($a, $b) {
                    return filemtime($b) - filemtime($a);
                });
                
                $latestLog = $logFiles[0];
                $logContent = file_get_contents($latestLog);
                
                foreach ($failurePatterns as $pattern) {
                    if (strpos($logContent, $pattern) !== false) {
                        $results['failures_found']++;
                        
                        // Extract error details
                        $lines = explode("\n", $logContent);
                        $errorLines = array_filter($lines, function($line) use ($pattern) {
                            return strpos($line, $pattern) !== false;
                        });
                        
                        $errorMessage = implode("\n", array_slice($errorLines, -3)); // Last 3 error lines
                        
                        $alertManager->sendFailureAlert($process, $errorMessage, [
                            'log_file' => basename($latestLog),
                            'pattern_matched' => $pattern
                        ]);
                        
                        $results['alerts_sent']++;
                        
                        if ($options['verbose']) {
                            echo "  ❌ Failure detected in {$process}: {$pattern}\n";
                        }
                        
                        break; // Only send one alert per process
                    }
                }
            }
        }
        
        if ($options['verbose'] && $results['failures_found'] === 0) {
            echo "  ✅ No recent failures detected\n";
        }
        
    } catch (Exception $e) {
        $logger->error('Failed to check for failures', [
            'error' => $e->getMessage()
        ]);
        
        if ($options['verbose']) {
            echo "  ❌ Error checking failures: " . $e->getMessage() . "\n";
        }
    }
    
    return $results;
}

/**
 * Check performance metrics
 */
function checkPerformance(Logger $logger, AlertManager $alertManager, DatabaseConnection $db, array $cronConfig, array $options): array {
    $results = ['performance_issues' => 0, 'alerts_sent' => 0];
    
    if ($options['verbose']) {
        echo "Checking performance metrics...\n";
    }
    
    try {
        $thresholds = $cronConfig['monitoring']['alert_thresholds'] ?? [];
        $maxTimes = $cronConfig['monitoring']['max_execution_time'] ?? [];
        
        // Check recent ETL execution times from database
        $query = "
            SELECT etl_class, duration_seconds, records_processed, started_at
            FROM etl_execution_log 
            WHERE started_at >= NOW() - INTERVAL '24 hours'
            AND status = 'success'
            ORDER BY started_at DESC
        ";
        
        $executions = $db->query($query);
        
        foreach ($executions as $execution) {
            $processName = basename(str_replace('\\', '/', $execution['etl_class']));
            $duration = (float)$execution['duration_seconds'];
            $records = (int)$execution['records_processed'];
            
            // Check execution time threshold
            if (isset($maxTimes[$processName])) {
                $maxTime = $maxTimes[$processName];
                $multiplier = $thresholds['execution_time_multiplier'] ?? 2.0;
                $threshold = $maxTime * $multiplier;
                
                if ($duration > $threshold) {
                    $alertManager->sendPerformanceAlert(
                        $processName,
                        'execution_time',
                        round($duration, 2) . 's',
                        round($threshold, 2) . 's',
                        [
                            'records_processed' => $records,
                            'started_at' => $execution['started_at']
                        ]
                    );
                    
                    $results['performance_issues']++;
                    $results['alerts_sent']++;
                    
                    if ($options['verbose']) {
                        echo "  ⚠️  Performance issue in {$processName}: execution time {$duration}s > {$threshold}s\n";
                    }
                }
            }
            
            // Check records per second rate (if applicable)
            if ($records > 0 && $duration > 0) {
                $recordsPerSecond = $records / $duration;
                $minRate = $thresholds['min_records_per_second'][$processName] ?? null;
                
                if ($minRate && $recordsPerSecond < $minRate) {
                    $alertManager->sendPerformanceAlert(
                        $processName,
                        'processing_rate',
                        round($recordsPerSecond, 2) . ' records/sec',
                        $minRate . ' records/sec',
                        [
                            'total_records' => $records,
                            'duration_seconds' => $duration,
                            'started_at' => $execution['started_at']
                        ]
                    );
                    
                    $results['performance_issues']++;
                    $results['alerts_sent']++;
                    
                    if ($options['verbose']) {
                        echo "  ⚠️  Performance issue in {$processName}: processing rate {$recordsPerSecond} < {$minRate} records/sec\n";
                    }
                }
            }
        }
        
        if ($options['verbose'] && $results['performance_issues'] === 0) {
            echo "  ✅ No performance issues detected\n";
        }
        
    } catch (Exception $e) {
        $logger->error('Failed to check performance metrics', [
            'error' => $e->getMessage()
        ]);
        
        if ($options['verbose']) {
            echo "  ❌ Error checking performance: " . $e->getMessage() . "\n";
        }
    }
    
    return $results;
}

/**
 * Check system health
 */
function checkHealth(Logger $logger, AlertManager $alertManager, DatabaseConnection $db, ProcessManager $processManager, array $options): array {
    $results = ['health_issues' => 0, 'alerts_sent' => 0];
    
    if ($options['verbose']) {
        echo "Checking system health...\n";
    }
    
    try {
        $issues = [];
        
        // Check database connectivity
        try {
            $db->query("SELECT 1");
            if ($options['verbose']) {
                echo "  ✅ Database connection OK\n";
            }
        } catch (Exception $e) {
            $issues[] = "Database connection failed: " . $e->getMessage();
            if ($options['verbose']) {
                echo "  ❌ Database connection failed\n";
            }
        }
        
        // Check disk space
        $freeSpaceGB = disk_free_space('.') / 1024 / 1024 / 1024;
        $minFreeSpaceGB = 5; // Minimum 5GB free space
        
        if ($freeSpaceGB < $minFreeSpaceGB) {
            $issues[] = "Low disk space: {$freeSpaceGB}GB free (minimum: {$minFreeSpaceGB}GB)";
            if ($options['verbose']) {
                echo "  ❌ Low disk space: " . round($freeSpaceGB, 1) . "GB\n";
            }
        } else {
            if ($options['verbose']) {
                echo "  ✅ Disk space OK: " . round($freeSpaceGB, 1) . "GB free\n";
            }
        }
        
        // Check memory usage
        $memoryUsageMB = memory_get_usage(true) / 1024 / 1024;
        $memoryLimitMB = ini_get('memory_limit');
        
        if ($memoryLimitMB !== '-1') {
            $memoryLimitMB = (int)$memoryLimitMB;
            $memoryUsagePercent = ($memoryUsageMB / $memoryLimitMB) * 100;
            
            if ($memoryUsagePercent > 80) {
                $issues[] = "High memory usage: {$memoryUsagePercent}%";
                if ($options['verbose']) {
                    echo "  ⚠️  High memory usage: " . round($memoryUsagePercent, 1) . "%\n";
                }
            } else {
                if ($options['verbose']) {
                    echo "  ✅ Memory usage OK: " . round($memoryUsagePercent, 1) . "%\n";
                }
            }
        }
        
        // Check for stale processes
        $processStatus = $processManager->getProcessStatus();
        $staleProcesses = array_filter($processStatus['active_processes'], function($process) {
            return !$process['is_running'];
        });
        
        if (!empty($staleProcesses)) {
            $staleCount = count($staleProcesses);
            $issues[] = "Found {$staleCount} stale process(es)";
            if ($options['verbose']) {
                echo "  ⚠️  Found {$staleCount} stale processes\n";
            }
        } else {
            if ($options['verbose']) {
                echo "  ✅ No stale processes found\n";
            }
        }
        
        // Send alerts for any issues found
        if (!empty($issues)) {
            $alertManager->sendFailureAlert('system_health', implode('; ', $issues), [
                'free_space_gb' => round($freeSpaceGB, 2),
                'memory_usage_mb' => round($memoryUsageMB, 2),
                'stale_processes' => count($staleProcesses)
            ]);
            
            $results['health_issues'] = count($issues);
            $results['alerts_sent'] = 1;
        }
        
    } catch (Exception $e) {
        $logger->error('Failed to check system health', [
            'error' => $e->getMessage()
        ]);
        
        if ($options['verbose']) {
            echo "  ❌ Error checking system health: " . $e->getMessage() . "\n";
        }
    }
    
    return $results;
}

/**
 * Send daily summary
 */
function sendDailySummary(Logger $logger, AlertManager $alertManager, DatabaseConnection $db, array $options): array {
    $results = ['summary_sent' => false];
    
    if ($options['verbose']) {
        echo "Generating daily summary...\n";
    }
    
    try {
        // Get today's ETL execution summary
        $query = "
            SELECT 
                etl_class,
                COUNT(*) as executions,
                SUM(CASE WHEN status = 'success' THEN 1 ELSE 0 END) as successes,
                SUM(CASE WHEN status = 'error' THEN 1 ELSE 0 END) as errors,
                SUM(records_processed) as total_records,
                AVG(duration_seconds) as avg_duration
            FROM etl_execution_log 
            WHERE DATE(started_at) = CURRENT_DATE
            GROUP BY etl_class
            ORDER BY etl_class
        ";
        
        $executions = $db->query($query);
        
        $summary = [
            'date' => date('Y-m-d'),
            'processes' => [],
            'total_records' => 0,
            'total_errors' => 0,
            'total_executions' => 0
        ];
        
        foreach ($executions as $execution) {
            $processName = basename(str_replace('\\', '/', $execution['etl_class']));
            
            $summary['processes'][$processName] = [
                'executions' => (int)$execution['executions'],
                'success' => (int)$execution['successes'] > 0,
                'errors' => (int)$execution['errors'],
                'records' => (int)$execution['total_records'],
                'avg_duration' => round((float)$execution['avg_duration'], 2)
            ];
            
            $summary['total_records'] += (int)$execution['total_records'];
            $summary['total_errors'] += (int)$execution['errors'];
            $summary['total_executions'] += (int)$execution['executions'];
        }
        
        // Only send summary if there were executions today
        if ($summary['total_executions'] > 0) {
            $alertManager->sendDailySummary($summary);
            $results['summary_sent'] = true;
            
            if ($options['verbose']) {
                echo "  ✅ Daily summary sent\n";
                echo "  Total executions: {$summary['total_executions']}\n";
                echo "  Total records: {$summary['total_records']}\n";
                echo "  Total errors: {$summary['total_errors']}\n";
            }
        } else {
            if ($options['verbose']) {
                echo "  ℹ️  No executions today, skipping summary\n";
            }
        }
        
    } catch (Exception $e) {
        $logger->error('Failed to send daily summary', [
            'error' => $e->getMessage()
        ]);
        
        if ($options['verbose']) {
            echo "  ❌ Error sending daily summary: " . $e->getMessage() . "\n";
        }
    }
    
    return $results;
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
        $alertManager = new AlertManager($cronConfig, $logger);
        $processManager = new ProcessManager($cronConfig, $logger);
        $db = new DatabaseConnection($config['database']);
        
        if ($options['verbose']) {
            echo "Ozon ETL Monitoring - " . date('Y-m-d H:i:s') . "\n";
            echo str_repeat('=', 50) . "\n";
        }
        
        $totalResults = [
            'failures_found' => 0,
            'performance_issues' => 0,
            'health_issues' => 0,
            'alerts_sent' => 0,
            'summary_sent' => false
        ];
        
        // Run checks based on options
        if ($options['check_failures']) {
            $results = checkFailures($logger, $alertManager, $options);
            $totalResults['failures_found'] += $results['failures_found'];
            $totalResults['alerts_sent'] += $results['alerts_sent'];
        }
        
        if ($options['check_performance']) {
            $results = checkPerformance($logger, $alertManager, $db, $cronConfig, $options);
            $totalResults['performance_issues'] += $results['performance_issues'];
            $totalResults['alerts_sent'] += $results['alerts_sent'];
        }
        
        if ($options['check_health']) {
            $results = checkHealth($logger, $alertManager, $db, $processManager, $options);
            $totalResults['health_issues'] += $results['health_issues'];
            $totalResults['alerts_sent'] += $results['alerts_sent'];
        }
        
        if ($options['send_summary']) {
            $results = sendDailySummary($logger, $alertManager, $db, $options);
            $totalResults['summary_sent'] = $results['summary_sent'];
        }
        
        // Log monitoring results
        $logger->info('ETL monitoring completed', $totalResults);
        
        if ($options['verbose']) {
            echo "\nMonitoring Summary:\n";
            echo "- Failures found: {$totalResults['failures_found']}\n";
            echo "- Performance issues: {$totalResults['performance_issues']}\n";
            echo "- Health issues: {$totalResults['health_issues']}\n";
            echo "- Alerts sent: {$totalResults['alerts_sent']}\n";
            echo "- Summary sent: " . ($totalResults['summary_sent'] ? 'Yes' : 'No') . "\n";
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