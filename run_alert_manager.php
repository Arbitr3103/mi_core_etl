#!/usr/bin/env php
<?php
/**
 * Alert Manager CLI Script
 * 
 * Command-line interface for managing Analytics ETL alerts
 * 
 * Task: 7.2 Создать AlertManager для Analytics ETL
 * Requirements: 7.1, 7.2, 7.3, 7.4, 7.5
 * 
 * Usage:
 *   php run_alert_manager.php [command] [options]
 * 
 * Commands:
 *   test-alert          Send test alert
 *   daily-summary       Generate and send daily summary
 *   cleanup             Clean up old alerts
 *   stats               Show alert statistics
 *   test-channels       Test all alert channels
 */

// Set error reporting and timezone
error_reporting(E_ALL);
ini_set('display_errors', 1);
date_default_timezone_set('Europe/Moscow');

// Include the alert manager
require_once __DIR__ . '/src/Services/AlertManager.php';

/**
 * Show help message
 */
function showHelp(): void {
    echo "Alert Manager CLI\n";
    echo "================\n\n";
    echo "Usage: php run_alert_manager.php [command] [options]\n\n";
    echo "Commands:\n";
    echo "  test-alert          Send test alert\n";
    echo "  daily-summary       Generate and send daily summary\n";
    echo "  cleanup             Clean up old alerts (default: 90 days)\n";
    echo "  stats               Show alert statistics\n";
    echo "  test-channels       Test all alert channels\n";
    echo "  help                Show this help message\n\n";
    echo "Options:\n";
    echo "  --severity=LEVEL    Alert severity (CRITICAL, ERROR, WARNING, INFO)\n";
    echo "  --type=TYPE         Alert type (etl_failure, api_failure, data_quality, etc.)\n";
    echo "  --channels=LIST     Comma-separated list of channels (email,slack,telegram)\n";
    echo "  --days=N            Number of days for cleanup or statistics\n";
    echo "  --config=FILE       Custom configuration file\n";
    echo "  --verbose           Enable verbose output\n\n";
    echo "Examples:\n";
    echo "  php run_alert_manager.php test-alert --severity=WARNING\n";
    echo "  php run_alert_manager.php daily-summary\n";
    echo "  php run_alert_manager.php cleanup --days=30\n";
    echo "  php run_alert_manager.php stats --days=7\n";
    echo "  php run_alert_manager.php test-channels\n\n";
}

/**
 * Parse command line arguments
 */
function parseArguments(): array {
    global $argv;
    
    $args = [
        'command' => $argv[1] ?? 'help',
        'options' => []
    ];
    
    for ($i = 2; $i < count($argv); $i++) {
        $arg = $argv[$i];
        
        if (strpos($arg, '--') === 0) {
            $parts = explode('=', substr($arg, 2), 2);
            $key = $parts[0];
            $value = $parts[1] ?? true;
            
            $args['options'][$key] = $value;
        }
    }
    
    return $args;
}

/**
 * Send test alert
 */
function sendTestAlert(AlertManager $alertManager, array $options): void {
    $severity = $options['severity'] ?? AlertManager::SEVERITY_INFO;
    $type = $options['type'] ?? AlertManager::TYPE_SYSTEM_HEALTH;
    $channels = isset($options['channels']) ? explode(',', $options['channels']) : [];
    
    echo "🧪 Sending test alert...\n";
    echo "Severity: {$severity}\n";
    echo "Type: {$type}\n";
    
    if (!empty($channels)) {
        echo "Channels: " . implode(', ', $channels) . "\n";
    }
    
    echo "\n";
    
    $success = $alertManager->sendAlert(
        $type,
        $severity,
        "Test Alert - " . date('Y-m-d H:i:s'),
        "This is a test alert sent from the Alert Manager CLI. " .
        "If you receive this message, the alert system is working correctly.",
        [
            'test' => true,
            'timestamp' => date('Y-m-d H:i:s'),
            'severity' => $severity,
            'type' => $type
        ],
        $channels
    );
    
    if ($success) {
        echo "✅ Test alert sent successfully!\n";
    } else {
        echo "❌ Failed to send test alert!\n";
        exit(1);
    }
}

/**
 * Send daily summary
 */
function sendDailySummary(AlertManager $alertManager, array $options): void {
    echo "📊 Generating and sending daily summary report...\n\n";
    
    $success = $alertManager->sendDailySummaryReport();
    
    if ($success) {
        echo "✅ Daily summary report sent successfully!\n";
    } else {
        echo "❌ Failed to send daily summary report!\n";
        exit(1);
    }
}

/**
 * Clean up old alerts
 */
function cleanupAlerts(AlertManager $alertManager, array $options): void {
    $days = (int)($options['days'] ?? 90);
    
    echo "🧹 Cleaning up alerts older than {$days} days...\n\n";
    
    $deletedCount = $alertManager->cleanupOldAlerts($days);
    
    echo "✅ Cleaned up {$deletedCount} old alerts\n";
}

/**
 * Show alert statistics
 */
function showAlertStatistics(AlertManager $alertManager, array $options): void {
    $days = (int)($options['days'] ?? 7);
    
    echo "📈 Alert Statistics (Last {$days} days)\n";
    echo "=====================================\n\n";
    
    $stats = $alertManager->getAlertStatistics($days);
    
    if (empty($stats)) {
        echo "No alerts found in the last {$days} days.\n";
        return;
    }
    
    $totalAlerts = array_sum(array_column($stats, 'count'));
    echo "Total alerts: {$totalAlerts}\n\n";
    
    // Group by severity
    $bySeverity = [];
    foreach ($stats as $stat) {
        $severity = $stat['severity'];
        if (!isset($bySeverity[$severity])) {
            $bySeverity[$severity] = 0;
        }
        $bySeverity[$severity] += $stat['count'];
    }
    
    echo "By Severity:\n";
    foreach ($bySeverity as $severity => $count) {
        $percentage = round(($count / $totalAlerts) * 100, 1);
        echo "  {$severity}: {$count} ({$percentage}%)\n";
    }
    
    echo "\nBy Type and Severity:\n";
    foreach ($stats as $stat) {
        $percentage = round(($stat['count'] / $totalAlerts) * 100, 1);
        echo "  {$stat['alert_type']} ({$stat['severity']}): {$stat['count']} ({$percentage}%) - Last: {$stat['last_alert']}\n";
    }
}

/**
 * Test all alert channels
 */
function testAlertChannels(AlertManager $alertManager, array $options): void {
    echo "🔧 Testing all alert channels...\n\n";
    
    $testAlerts = [
        [
            'severity' => AlertManager::SEVERITY_INFO,
            'title' => 'Channel Test - INFO',
            'message' => 'Testing INFO level alert delivery'
        ],
        [
            'severity' => AlertManager::SEVERITY_WARNING,
            'title' => 'Channel Test - WARNING',
            'message' => 'Testing WARNING level alert delivery'
        ],
        [
            'severity' => AlertManager::SEVERITY_ERROR,
            'title' => 'Channel Test - ERROR',
            'message' => 'Testing ERROR level alert delivery'
        ],
        [
            'severity' => AlertManager::SEVERITY_CRITICAL,
            'title' => 'Channel Test - CRITICAL',
            'message' => 'Testing CRITICAL level alert delivery'
        ]
    ];
    
    $allSuccess = true;
    
    foreach ($testAlerts as $alert) {
        echo "Testing {$alert['severity']} alert...\n";
        
        $success = $alertManager->sendAlert(
            AlertManager::TYPE_SYSTEM_HEALTH,
            $alert['severity'],
            $alert['title'],
            $alert['message'],
            [
                'test' => true,
                'channel_test' => true,
                'timestamp' => date('Y-m-d H:i:s')
            ]
        );
        
        if ($success) {
            echo "  ✅ {$alert['severity']} alert sent successfully\n";
        } else {
            echo "  ❌ {$alert['severity']} alert failed\n";
            $allSuccess = false;
        }
        
        // Small delay between alerts
        sleep(1);
    }
    
    echo "\n";
    
    if ($allSuccess) {
        echo "✅ All channel tests completed successfully!\n";
    } else {
        echo "⚠️  Some channel tests failed. Check configuration and logs.\n";
        exit(1);
    }
}

/**
 * Format output with colors
 */
function colorOutput(string $text, string $color): string {
    if (php_sapi_name() !== 'cli') {
        return $text;
    }
    
    $colors = [
        'red' => "\033[31m",
        'green' => "\033[32m",
        'yellow' => "\033[33m",
        'blue' => "\033[34m",
        'purple' => "\033[35m",
        'cyan' => "\033[36m",
        'white' => "\033[37m",
        'reset' => "\033[0m"
    ];
    
    return ($colors[$color] ?? '') . $text . ($colors['reset'] ?? '');
}

// Main execution
if (php_sapi_name() === 'cli') {
    try {
        $args = parseArguments();
        $command = $args['command'];
        $options = $args['options'];
        
        if ($command === 'help' || $command === '--help') {
            showHelp();
            exit(0);
        }
        
        echo colorOutput("🚀 Alert Manager CLI - " . date('Y-m-d H:i:s') . "\n", 'blue');
        echo "Command: {$command}\n\n";
        
        // Initialize AlertManager with custom config if provided
        $config = [];
        if (isset($options['config']) && file_exists($options['config'])) {
            $config = include $options['config'];
        }
        
        if (isset($options['verbose'])) {
            $config['detailed_logging'] = true;
        }
        
        $alertManager = new AlertManager($config);
        
        // Execute command
        switch ($command) {
            case 'test-alert':
                sendTestAlert($alertManager, $options);
                break;
                
            case 'daily-summary':
                sendDailySummary($alertManager, $options);
                break;
                
            case 'cleanup':
                cleanupAlerts($alertManager, $options);
                break;
                
            case 'stats':
                showAlertStatistics($alertManager, $options);
                break;
                
            case 'test-channels':
                testAlertChannels($alertManager, $options);
                break;
                
            default:
                echo colorOutput("❌ Unknown command: {$command}\n", 'red');
                echo "Use 'php run_alert_manager.php help' for available commands.\n";
                exit(1);
        }
        
        echo "\n" . colorOutput("✅ Command completed successfully!", 'green') . "\n";
        exit(0);
        
    } catch (Exception $e) {
        echo "\n" . colorOutput("❌ Error: " . $e->getMessage(), 'red') . "\n";
        
        if (isset($options['verbose'])) {
            echo "\nStack trace:\n" . $e->getTraceAsString() . "\n";
        }
        
        exit(1);
    }
} else {
    // Web interface (basic)
    header('Content-Type: application/json');
    
    try {
        $command = $_GET['command'] ?? 'stats';
        $options = $_GET;
        
        $alertManager = new AlertManager();
        
        switch ($command) {
            case 'stats':
                $days = (int)($options['days'] ?? 7);
                $result = [
                    'command' => 'stats',
                    'days' => $days,
                    'statistics' => $alertManager->getAlertStatistics($days)
                ];
                break;
                
            case 'daily-summary':
                $success = $alertManager->sendDailySummaryReport();
                $result = [
                    'command' => 'daily-summary',
                    'success' => $success
                ];
                break;
                
            default:
                $result = [
                    'error' => 'Unknown command',
                    'available_commands' => ['stats', 'daily-summary']
                ];
        }
        
        echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode([
            'error' => $e->getMessage(),
            'timestamp' => date('Y-m-d H:i:s')
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    }
}