#!/usr/bin/env php
<?php
/**
 * Data Quality Monitoring Script
 * 
 * Run this script via cron to continuously monitor MDM data quality
 * and trigger alerts when thresholds are exceeded
 * 
 * Usage:
 *   php monitor_data_quality.php
 *   php monitor_data_quality.php --verbose
 *   php monitor_data_quality.php --report-only
 * 
 * Cron example (run every hour):
 *   0 * * * * php /path/to/monitor_data_quality.php
 * 
 * Requirements: 8.3, 4.3
 */

require_once 'config.php';
require_once 'src/DataQualityMonitor.php';
require_once 'src/AlertHandlers.php';

// Parse command line arguments
$options = getopt('', ['verbose', 'report-only', 'help']);
$verbose = isset($options['verbose']);
$reportOnly = isset($options['report-only']);

if (isset($options['help'])) {
    showHelp();
    exit(0);
}

// Initialize monitor
$monitor = new DataQualityMonitor($pdo);

// Configure alert thresholds (can be customized)
$monitor->setThresholds([
    'failed_percentage' => 5,      // Alert if > 5% failed
    'pending_percentage' => 10,    // Alert if > 10% pending
    'real_names_percentage' => 80, // Alert if < 80% have real names
    'sync_age_hours' => 48,        // Alert if no sync in 48h
    'error_count_hourly' => 10     // Alert if > 10 errors/hour
]);

// Setup alert handlers
if (!$reportOnly) {
    // Log handler (always enabled)
    $monitor->addAlertHandler(new LogAlertHandler('logs/quality_alerts.log'));
    
    // Console handler (if verbose)
    if ($verbose) {
        $monitor->addAlertHandler(new ConsoleAlertHandler());
    }
    
    // Email handler (if configured)
    if (defined('ALERT_EMAIL') && ALERT_EMAIL) {
        $monitor->addAlertHandler(new EmailAlertHandler(ALERT_EMAIL));
    }
    
    // Slack handler (if configured)
    if (defined('SLACK_WEBHOOK_URL') && SLACK_WEBHOOK_URL) {
        $monitor->addAlertHandler(new SlackAlertHandler(SLACK_WEBHOOK_URL));
    }
}

// Run quality checks
echo "=== MDM Data Quality Monitor ===\n";
echo "Started at: " . date('Y-m-d H:i:s') . "\n\n";

try {
    // Get quality metrics
    echo "Collecting quality metrics...\n";
    $metrics = $monitor->getQualityMetrics();
    
    // Display metrics
    displayMetrics($metrics, $verbose);
    
    // Run checks and trigger alerts
    if (!$reportOnly) {
        echo "\nRunning quality checks...\n";
        $result = $monitor->runQualityChecks();
        
        if ($result['alerts_triggered'] > 0) {
            echo "⚠️  {$result['alerts_triggered']} alert(s) triggered!\n";
            
            if ($verbose) {
                foreach ($result['alerts'] as $alert) {
                    echo "\n  [{$alert['level']}] {$alert['message']}\n";
                    echo "    Value: {$alert['value']}, Threshold: {$alert['threshold']}\n";
                }
            }
        } else {
            echo "✓ All quality checks passed\n";
        }
    }
    
    // Show recent alerts
    if ($verbose) {
        echo "\n=== Recent Alerts (Last 10) ===\n";
        $recentAlerts = $monitor->getRecentAlerts(10);
        
        if (empty($recentAlerts)) {
            echo "No recent alerts\n";
        } else {
            foreach ($recentAlerts as $alert) {
                echo "[{$alert['created_at']}] [{$alert['alert_level']}] {$alert['message']}\n";
            }
        }
        
        echo "\n=== Alert Statistics (Last 7 Days) ===\n";
        $alertStats = $monitor->getAlertStats();
        
        if (empty($alertStats)) {
            echo "No alerts in last 7 days\n";
        } else {
            foreach ($alertStats as $stat) {
                echo "{$stat['alert_type']} ({$stat['alert_level']}): {$stat['count']} occurrences\n";
                echo "  Last: {$stat['last_occurrence']}\n";
            }
        }
    }
    
    echo "\nCompleted at: " . date('Y-m-d H:i:s') . "\n";
    
} catch (Exception $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
    error_log("Data quality monitoring failed: " . $e->getMessage());
    exit(1);
}

function displayMetrics($metrics, $verbose) {
    // Sync status
    echo "--- Sync Status ---\n";
    $sync = $metrics['sync_status'];
    echo "Total products: {$sync['total']}\n";
    echo "Synced: {$sync['synced']} ({$sync['synced_percentage']}%)\n";
    echo "Pending: {$sync['pending']} ({$sync['pending_percentage']}%)\n";
    echo "Failed: {$sync['failed']} ({$sync['failed_percentage']}%)\n";
    
    // Data quality
    echo "\n--- Data Quality ---\n";
    $quality = $metrics['data_quality'];
    echo "Products with real names: {$quality['with_real_names']} ({$quality['real_names_percentage']}%)\n";
    echo "Products with brands: {$quality['with_brands']} ({$quality['brands_percentage']}%)\n";
    echo "Average cache age: {$quality['avg_cache_age_days']} days\n";
    
    // Errors
    echo "\n--- Errors ---\n";
    $errors = $metrics['error_metrics'];
    echo "Errors (24h): {$errors['total_errors_24h']}\n";
    echo "Errors (7d): {$errors['total_errors_7d']}\n";
    echo "Affected products: {$errors['affected_products']}\n";
    
    if ($verbose && !empty($errors['error_types'])) {
        echo "\nTop error types:\n";
        foreach ($errors['error_types'] as $errorType) {
            echo "  {$errorType['error_type']}: {$errorType['count']}\n";
        }
    }
    
    // Performance
    echo "\n--- Performance ---\n";
    $perf = $metrics['performance'];
    echo "Products synced today: {$perf['products_synced_today']}\n";
    echo "Last sync: " . ($perf['last_sync_time'] ?? 'Never') . "\n";
    
    // Overall score
    echo "\n--- Overall Quality Score ---\n";
    $score = $metrics['overall_score'];
    $scoreColor = $score >= 90 ? '✓' : ($score >= 70 ? '⚠' : '✗');
    echo "{$scoreColor} {$score}/100\n";
    
    if ($score < 70) {
        echo "Status: Poor - Immediate attention required\n";
    } elseif ($score < 90) {
        echo "Status: Fair - Improvements needed\n";
    } else {
        echo "Status: Good - System healthy\n";
    }
}

function showHelp() {
    echo "MDM Data Quality Monitor\n\n";
    echo "Usage:\n";
    echo "  php monitor_data_quality.php [options]\n\n";
    echo "Options:\n";
    echo "  --verbose      Show detailed output\n";
    echo "  --report-only  Only show metrics, don't trigger alerts\n";
    echo "  --help         Show this help message\n\n";
    echo "Examples:\n";
    echo "  php monitor_data_quality.php\n";
    echo "  php monitor_data_quality.php --verbose\n";
    echo "  php monitor_data_quality.php --report-only\n\n";
    echo "Cron setup (run every hour):\n";
    echo "  0 * * * * php /path/to/monitor_data_quality.php\n\n";
}
