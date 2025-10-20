#!/usr/bin/env php
<?php
/**
 * Ozon Data Synchronization CLI Script
 * 
 * Command-line interface for synchronizing regional sales data from Ozon API.
 * Can be run manually or scheduled via cron.
 * 
 * Usage:
 *   php sync_ozon_data.php daily [date]
 *   php sync_ozon_data.php incremental <date_from> <date_to> [--force]
 *   php sync_ozon_data.php full-resync <date_from> <date_to>
 *   php sync_ozon_data.php status [date_from] [date_to]
 *   php sync_ozon_data.php stats [days]
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/OzonSyncManager.php';

// Ensure this script is run from CLI only
if (php_sapi_name() !== 'cli') {
    die("This script can only be run from the command line.\n");
}

/**
 * Display usage information
 */
function showUsage() {
    echo "Ozon Data Synchronization CLI\n";
    echo "=============================\n\n";
    echo "Usage:\n";
    echo "  php sync_ozon_data.php daily [date]                    - Sync data for a specific date (default: yesterday)\n";
    echo "  php sync_ozon_data.php incremental <from> <to> [--force] - Sync data for date range\n";
    echo "  php sync_ozon_data.php full-resync <from> <to>         - Full resync (clears existing data)\n";
    echo "  php sync_ozon_data.php status [from] [to]              - Show sync status\n";
    echo "  php sync_ozon_data.php stats [days]                    - Show sync statistics\n";
    echo "  php sync_ozon_data.php recent [limit]                  - Show recent sync activity\n";
    echo "  php sync_ozon_data.php test-connection                 - Test Ozon API connection\n";
    echo "\n";
    echo "Examples:\n";
    echo "  php sync_ozon_data.php daily\n";
    echo "  php sync_ozon_data.php daily 2025-10-19\n";
    echo "  php sync_ozon_data.php incremental 2025-10-01 2025-10-19\n";
    echo "  php sync_ozon_data.php incremental 2025-10-01 2025-10-19 --force\n";
    echo "  php sync_ozon_data.php full-resync 2025-10-01 2025-10-19\n";
    echo "  php sync_ozon_data.php status 2025-10-01 2025-10-19\n";
    echo "  php sync_ozon_data.php stats 7\n";
    echo "\n";
}

/**
 * Validate date format
 */
function validateDate($date) {
    return preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) && strtotime($date) !== false;
}

/**
 * Format duration in human readable format
 */
function formatDuration($seconds) {
    if ($seconds < 60) {
        return sprintf("%.1fs", $seconds);
    } elseif ($seconds < 3600) {
        return sprintf("%.1fm", $seconds / 60);
    } else {
        return sprintf("%.1fh", $seconds / 3600);
    }
}

/**
 * Format bytes in human readable format
 */
function formatBytes($bytes) {
    $units = ['B', 'KB', 'MB', 'GB'];
    $bytes = max($bytes, 0);
    $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
    $pow = min($pow, count($units) - 1);
    
    $bytes /= pow(1024, $pow);
    
    return round($bytes, 2) . ' ' . $units[$pow];
}

/**
 * Print colored output
 */
function printColored($text, $color = 'white') {
    $colors = [
        'red' => "\033[31m",
        'green' => "\033[32m",
        'yellow' => "\033[33m",
        'blue' => "\033[34m",
        'magenta' => "\033[35m",
        'cyan' => "\033[36m",
        'white' => "\033[37m",
        'reset' => "\033[0m"
    ];
    
    echo $colors[$color] . $text . $colors['reset'];
}

/**
 * Print success message
 */
function printSuccess($message) {
    printColored("✓ ", 'green');
    echo $message . "\n";
}

/**
 * Print error message
 */
function printError($message) {
    printColored("✗ ", 'red');
    echo $message . "\n";
}

/**
 * Print warning message
 */
function printWarning($message) {
    printColored("⚠ ", 'yellow');
    echo $message . "\n";
}

/**
 * Print info message
 */
function printInfo($message) {
    printColored("ℹ ", 'blue');
    echo $message . "\n";
}

// Parse command line arguments
$command = $argv[1] ?? '';
$args = array_slice($argv, 2);

// Handle help
if (empty($command) || $command === 'help' || $command === '--help' || $command === '-h') {
    showUsage();
    exit(0);
}

try {
    // Initialize sync manager
    printInfo("Initializing Ozon Sync Manager...");
    $syncManager = new OzonSyncManager();
    
    switch ($command) {
        case 'daily':
            $date = $args[0] ?? date('Y-m-d', strtotime('-1 day'));
            
            if (!validateDate($date)) {
                printError("Invalid date format. Use YYYY-MM-DD.");
                exit(1);
            }
            
            printInfo("Starting daily sync for date: $date");
            $startTime = microtime(true);
            
            $results = $syncManager->performDailySync($date);
            
            $duration = microtime(true) - $startTime;
            
            if ($results['success']) {
                printSuccess("Daily sync completed successfully in " . formatDuration($duration));
                
                if (isset($results['records_processed'])) {
                    echo "  Records processed: " . $results['records_processed'] . "\n";
                    echo "  Records inserted: " . $results['records_inserted'] . "\n";
                    echo "  Records updated: " . $results['records_updated'] . "\n";
                }
            } else {
                printError("Daily sync failed");
                if (!empty($results['errors'])) {
                    foreach ($results['errors'] as $error) {
                        echo "  Error: $error\n";
                    }
                }
                exit(1);
            }
            break;
            
        case 'incremental':
            if (count($args) < 2) {
                printError("Incremental sync requires date_from and date_to arguments.");
                showUsage();
                exit(1);
            }
            
            $dateFrom = $args[0];
            $dateTo = $args[1];
            $forceResync = in_array('--force', $args);
            
            if (!validateDate($dateFrom) || !validateDate($dateTo)) {
                printError("Invalid date format. Use YYYY-MM-DD.");
                exit(1);
            }
            
            printInfo("Starting incremental sync from $dateFrom to $dateTo" . ($forceResync ? " (forced)" : ""));
            $startTime = microtime(true);
            
            $results = $syncManager->performIncrementalSync($dateFrom, $dateTo, $forceResync);
            
            $duration = microtime(true) - $startTime;
            
            if ($results['success']) {
                printSuccess("Incremental sync completed successfully in " . formatDuration($duration));
                echo "  Dates processed: " . $results['dates_processed'] . "\n";
                echo "  Records processed: " . $results['records_processed'] . "\n";
                echo "  Records inserted: " . $results['records_inserted'] . "\n";
                echo "  Records updated: " . $results['records_updated'] . "\n";
            } else {
                printWarning("Incremental sync completed with errors in " . formatDuration($duration));
                echo "  Dates processed: " . $results['dates_processed'] . "\n";
                echo "  Records processed: " . $results['records_processed'] . "\n";
                echo "  Records inserted: " . $results['records_inserted'] . "\n";
                echo "  Records updated: " . $results['records_updated'] . "\n";
                echo "  Errors: " . count($results['errors']) . "\n";
                
                foreach ($results['errors'] as $error) {
                    if (is_array($error)) {
                        echo "    Date " . $error['date'] . ": " . (is_array($error['errors']) ? implode(', ', $error['errors']) : $error['error']) . "\n";
                    } else {
                        echo "    $error\n";
                    }
                }
            }
            break;
            
        case 'full-resync':
            if (count($args) < 2) {
                printError("Full resync requires date_from and date_to arguments.");
                showUsage();
                exit(1);
            }
            
            $dateFrom = $args[0];
            $dateTo = $args[1];
            
            if (!validateDate($dateFrom) || !validateDate($dateTo)) {
                printError("Invalid date format. Use YYYY-MM-DD.");
                exit(1);
            }
            
            printWarning("Starting full resync from $dateFrom to $dateTo (this will clear existing data)");
            echo "Are you sure you want to continue? (y/N): ";
            $handle = fopen("php://stdin", "r");
            $line = fgets($handle);
            fclose($handle);
            
            if (trim(strtolower($line)) !== 'y') {
                printInfo("Full resync cancelled.");
                exit(0);
            }
            
            $startTime = microtime(true);
            
            $results = $syncManager->performFullResync($dateFrom, $dateTo);
            
            $duration = microtime(true) - $startTime;
            
            if ($results['success']) {
                printSuccess("Full resync completed successfully in " . formatDuration($duration));
                echo "  Dates processed: " . $results['dates_processed'] . "\n";
                echo "  Records processed: " . $results['records_processed'] . "\n";
                echo "  Records inserted: " . $results['records_inserted'] . "\n";
                echo "  Records updated: " . $results['records_updated'] . "\n";
            } else {
                printError("Full resync failed");
                if (!empty($results['errors'])) {
                    foreach ($results['errors'] as $error) {
                        if (is_array($error)) {
                            echo "  Date " . $error['date'] . ": " . (is_array($error['errors']) ? implode(', ', $error['errors']) : $error['error']) . "\n";
                        } else {
                            echo "  Error: $error\n";
                        }
                    }
                }
                exit(1);
            }
            break;
            
        case 'status':
            $dateFrom = $args[0] ?? date('Y-m-d', strtotime('-7 days'));
            $dateTo = $args[1] ?? date('Y-m-d');
            
            if (!validateDate($dateFrom) || !validateDate($dateTo)) {
                printError("Invalid date format. Use YYYY-MM-DD.");
                exit(1);
            }
            
            printInfo("Getting sync status from $dateFrom to $dateTo");
            
            $status = $syncManager->getSyncStatus($dateFrom, $dateTo);
            
            echo "\n";
            printColored("Sync Status Summary\n", 'cyan');
            echo "==================\n";
            echo "Period: $dateFrom to $dateTo\n";
            echo "Total records: " . number_format($status['summary']['total_records']) . "\n";
            echo "Total revenue: ₽" . number_format($status['summary']['total_revenue'], 2) . "\n";
            echo "Unique regions: " . $status['summary']['unique_regions'] . "\n";
            echo "Unique products: " . $status['summary']['unique_products'] . "\n";
            echo "Dates with data: " . $status['summary']['dates_with_data'] . "\n";
            
            if (!empty($status['synced_dates'])) {
                echo "\n";
                printColored("Daily Breakdown\n", 'cyan');
                echo "===============\n";
                printf("%-12s %-10s %-15s %-10s %-10s %-20s\n", 
                    'Date', 'Records', 'Revenue', 'Regions', 'Products', 'Last Synced');
                echo str_repeat('-', 80) . "\n";
                
                foreach ($status['synced_dates'] as $date) {
                    printf("%-12s %-10s ₽%-14s %-10s %-10s %-20s\n",
                        $date['sync_date'],
                        number_format($date['records_count']),
                        number_format($date['total_revenue'], 2),
                        $date['regions_count'],
                        $date['products_count'],
                        $date['last_synced'] ? date('Y-m-d H:i:s', strtotime($date['last_synced'])) : 'Never'
                    );
                }
            }
            
            if (!empty($status['sync_logs'])) {
                echo "\n";
                printColored("Recent Sync Operations\n", 'cyan');
                echo "======================\n";
                printf("%-12s %-10s %-12s %-12s %-10s %-30s\n", 
                    'Type', 'Status', 'Date From', 'Date To', 'Duration', 'Message');
                echo str_repeat('-', 100) . "\n";
                
                foreach ($status['sync_logs'] as $log) {
                    $duration = '';
                    if ($log['started_at'] && $log['completed_at']) {
                        $start = strtotime($log['started_at']);
                        $end = strtotime($log['completed_at']);
                        $duration = formatDuration($end - $start);
                    }
                    
                    printf("%-12s %-10s %-12s %-12s %-10s %-30s\n",
                        $log['sync_type'],
                        $log['status'],
                        $log['date_from'],
                        $log['date_to'],
                        $duration,
                        substr($log['message'] ?? '', 0, 30)
                    );
                }
            }
            break;
            
        case 'stats':
            $days = intval($args[0] ?? 30);
            
            printInfo("Getting sync statistics for the last $days days");
            
            $stats = $syncManager->getSyncStatistics($days);
            
            echo "\n";
            printColored("Sync Statistics ($days days)\n", 'cyan');
            echo "========================\n";
            echo "Period: " . $stats['date_from'] . " to " . date('Y-m-d') . "\n";
            echo "Total syncs: " . $stats['summary']['total_syncs'] . "\n";
            echo "Successful syncs: " . $stats['summary']['successful_syncs'] . "\n";
            echo "Failed syncs: " . $stats['summary']['failed_syncs'] . "\n";
            
            if ($stats['summary']['total_syncs'] > 0) {
                $successRate = ($stats['summary']['successful_syncs'] / $stats['summary']['total_syncs']) * 100;
                echo "Success rate: " . number_format($successRate, 1) . "%\n";
            }
            
            echo "First sync: " . ($stats['summary']['first_sync'] ?? 'Never') . "\n";
            echo "Last sync: " . ($stats['summary']['last_sync'] ?? 'Never') . "\n";
            
            if (!empty($stats['status_breakdown'])) {
                echo "\n";
                printColored("Status Breakdown\n", 'cyan');
                echo "================\n";
                printf("%-15s %-10s %-15s\n", 'Status', 'Count', 'Avg Duration');
                echo str_repeat('-', 40) . "\n";
                
                foreach ($stats['status_breakdown'] as $status) {
                    $avgDuration = $status['avg_duration_seconds'] ? formatDuration($status['avg_duration_seconds']) : 'N/A';
                    printf("%-15s %-10s %-15s\n",
                        $status['status'],
                        $status['count'],
                        $avgDuration
                    );
                }
            }
            break;
            
        case 'recent':
            $limit = intval($args[0] ?? 20);
            
            printInfo("Getting recent sync activity (last $limit entries)");
            
            $activity = $syncManager->getRecentSyncActivity($limit);
            
            if (empty($activity)) {
                printWarning("No sync activity found.");
                break;
            }
            
            echo "\n";
            printColored("Recent Sync Activity\n", 'cyan');
            echo "====================\n";
            printf("%-5s %-12s %-10s %-12s %-12s %-10s %-20s %-30s\n", 
                'ID', 'Type', 'Status', 'Date From', 'Date To', 'Duration', 'Started', 'Message');
            echo str_repeat('-', 130) . "\n";
            
            foreach ($activity as $entry) {
                $duration = $entry['duration_seconds'] ? formatDuration($entry['duration_seconds']) : 'N/A';
                $startedAt = date('Y-m-d H:i:s', strtotime($entry['started_at']));
                
                printf("%-5s %-12s %-10s %-12s %-12s %-10s %-20s %-30s\n",
                    $entry['id'],
                    $entry['sync_type'],
                    $entry['status'],
                    $entry['date_from'],
                    $entry['date_to'],
                    $duration,
                    $startedAt,
                    substr($entry['message'] ?? '', 0, 30)
                );
            }
            break;
            
        case 'test-connection':
            printInfo("Testing Ozon API connection...");
            
            $ozonApi = new OzonAPIService();
            $status = $ozonApi->getConnectionStatus();
            
            if ($status['connected']) {
                printSuccess("Ozon API connection successful");
                echo "  Client ID: " . $status['client_id'] . "\n";
                echo "  API Key: " . $status['api_key'] . "\n";
            } else {
                printError("Ozon API connection failed");
                echo "  Error: " . $status['message'] . "\n";
                echo "  Client ID: " . $status['client_id'] . "\n";
                echo "  API Key: " . $status['api_key'] . "\n";
                exit(1);
            }
            break;
            
        default:
            printError("Unknown command: $command");
            showUsage();
            exit(1);
    }
    
} catch (Exception $e) {
    printError("Error: " . $e->getMessage());
    
    if (isset($argv) && in_array('--debug', $argv)) {
        echo "\nStack trace:\n";
        echo $e->getTraceAsString() . "\n";
    }
    
    exit(1);
}

printSuccess("Operation completed successfully.");
exit(0);

?>