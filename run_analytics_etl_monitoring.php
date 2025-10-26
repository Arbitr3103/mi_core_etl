#!/usr/bin/env php
<?php
/**
 * Analytics ETL Monitoring CLI Script
 * 
 * Command-line interface for running Analytics ETL monitoring
 * 
 * Task: 7.1 Создать AnalyticsETLMonitor
 * Requirements: 7.1, 7.2, 7.3, 17.5
 * 
 * Usage:
 *   php run_analytics_etl_monitoring.php [options]
 * 
 * Options:
 *   --help              Show this help message
 *   --verbose           Enable verbose output
 *   --json              Output results in JSON format
 *   --alerts-only       Only show alerts
 *   --health-score      Show only health score
 *   --sla-report        Show SLA compliance report
 *   --config=FILE       Use custom config file
 */

// Set error reporting and timezone
error_reporting(E_ALL);
ini_set('display_errors', 1);
date_default_timezone_set('Europe/Moscow');

// Include the monitor service
require_once __DIR__ . '/src/Services/AnalyticsETLMonitor.php';

/**
 * Show help message
 */
function showHelp(): void {
    echo "Analytics ETL Monitoring CLI\n";
    echo "===========================\n\n";
    echo "Usage: php run_analytics_etl_monitoring.php [options]\n\n";
    echo "Options:\n";
    echo "  --help              Show this help message\n";
    echo "  --verbose           Enable verbose output\n";
    echo "  --json              Output results in JSON format\n";
    echo "  --alerts-only       Only show alerts\n";
    echo "  --health-score      Show only health score\n";
    echo "  --sla-report        Show SLA compliance report\n";
    echo "  --config=FILE       Use custom config file\n\n";
    echo "Examples:\n";
    echo "  php run_analytics_etl_monitoring.php --verbose\n";
    echo "  php run_analytics_etl_monitoring.php --json > monitoring_report.json\n";
    echo "  php run_analytics_etl_monitoring.php --alerts-only\n";
    echo "  php run_analytics_etl_monitoring.php --health-score\n";
    echo "  php run_analytics_etl_monitoring.php --sla-report\n\n";
}

/**
 * Parse command line options
 */
function parseCommandLineOptions(): array {
    $options = [];
    $args = $_SERVER['argv'] ?? [];
    
    for ($i = 1; $i < count($args); $i++) {
        $arg = $args[$i];
        
        if ($arg === '--help') {
            $options['help'] = true;
        } elseif ($arg === '--verbose') {
            $options['verbose'] = true;
        } elseif ($arg === '--json') {
            $options['json'] = true;
        } elseif ($arg === '--alerts-only') {
            $options['alerts-only'] = true;
        } elseif ($arg === '--health-score') {
            $options['health-score'] = true;
        } elseif ($arg === '--sla-report') {
            $options['sla-report'] = true;
        } elseif (strpos($arg, '--config=') === 0) {
            $options['config'] = substr($arg, 9);
        }
    }
    
    return $options;
}

/**
 * Format health score with color coding
 */
function formatHealthScore(float $score): string {
    if ($score >= 95) {
        return "\033[32m{$score}%\033[0m (Excellent)"; // Green
    } elseif ($score >= 85) {
        return "\033[33m{$score}%\033[0m (Good)"; // Yellow
    } elseif ($score >= 70) {
        return "\033[31m{$score}%\033[0m (Poor)"; // Red
    } else {
        return "\033[91m{$score}%\033[0m (Critical)"; // Bright Red
    }
}

/**
 * Format alert level with color coding
 */
function formatAlertLevel(string $level): string {
    return match($level) {
        'CRITICAL' => "\033[91m{$level}\033[0m", // Bright Red
        'ERROR' => "\033[31m{$level}\033[0m",    // Red
        'WARNING' => "\033[33m{$level}\033[0m",  // Yellow
        'INFO' => "\033[36m{$level}\033[0m",     // Cyan
        default => $level
    };
}

/**
 * Display monitoring results in human-readable format
 */
function displayResults(array $result, array $options): void {
    if (!empty($options['json'])) {
        echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
        return;
    }
    
    if (!empty($options['health-score'])) {
        echo "Overall Health Score: " . formatHealthScore($result['overall_health_score']) . "\n";
        return;
    }
    
    if (!empty($options['alerts-only'])) {
        if (empty($result['alerts'])) {
            echo "✅ No alerts - system is healthy\n";
        } else {
            echo "🚨 Active Alerts (" . count($result['alerts']) . "):\n\n";
            foreach ($result['alerts'] as $alert) {
                echo "  " . formatAlertLevel($alert['level']) . ": " . $alert['message'] . "\n";
                echo "    Time: " . $alert['timestamp'] . "\n";
                if (!empty($alert['context'])) {
                    echo "    Details: " . json_encode($alert['context'], JSON_UNESCAPED_UNICODE) . "\n";
                }
                echo "\n";
            }
        }
        return;
    }
    
    if (!empty($options['sla-report'])) {
        echo "📊 SLA Compliance Report\n";
        echo "========================\n\n";
        
        if (isset($result['sla_compliance'])) {
            foreach ($result['sla_compliance'] as $sla => $data) {
                if ($sla === 'overall') {
                    echo "Overall SLA Compliance: " . $data['compliance_percent'] . "% ";
                    echo "(" . $data['compliant_slas'] . "/" . $data['total_slas'] . " SLAs met)\n\n";
                } else {
                    $status = $data['compliant'] ? "✅ PASS" : "❌ FAIL";
                    echo "{$sla}: {$status}\n";
                    echo "  Target: " . $data['target'] . "\n";
                    echo "  Current: " . $data['current'] . "\n\n";
                }
            }
        }
        return;
    }
    
    // Full report
    echo "🔍 Analytics ETL Monitoring Report\n";
    echo "==================================\n\n";
    
    echo "📈 Overall Status: " . strtoupper($result['status']) . "\n";
    echo "🏥 Health Score: " . formatHealthScore($result['overall_health_score']) . "\n";
    echo "⏱️  Execution Time: " . $result['execution_time_ms'] . "ms\n";
    echo "📅 Generated: " . $result['timestamp'] . "\n\n";
    
    // Alerts Summary
    if (!empty($result['alerts'])) {
        echo "🚨 Alerts (" . count($result['alerts']) . "):\n";
        $alertsByLevel = [];
        foreach ($result['alerts'] as $alert) {
            $alertsByLevel[$alert['level']][] = $alert;
        }
        
        foreach ($alertsByLevel as $level => $alerts) {
            echo "  " . formatAlertLevel($level) . ": " . count($alerts) . " alerts\n";
        }
        echo "\n";
    } else {
        echo "✅ No alerts - system is healthy\n\n";
    }
    
    // Key Metrics
    echo "📊 Key Metrics:\n";
    if (isset($result['metrics']['api_success_rate_last_24_hours'])) {
        echo "  API Success Rate (24h): " . $result['metrics']['api_success_rate_last_24_hours'] . "%\n";
    }
    if (isset($result['metrics']['data_quality_avg_score'])) {
        echo "  Data Quality Score: " . $result['metrics']['data_quality_avg_score'] . "/100\n";
    }
    if (isset($result['metrics']['sla_uptime_last_24_hours_percent'])) {
        echo "  Uptime (24h): " . $result['metrics']['sla_uptime_last_24_hours_percent'] . "%\n";
    }
    if (isset($result['metrics']['hours_since_last_successful_run'])) {
        echo "  Hours Since Last Run: " . $result['metrics']['hours_since_last_successful_run'] . "h\n";
    }
    
    if (!empty($options['verbose'])) {
        echo "\n📋 Detailed Metrics:\n";
        foreach ($result['metrics'] as $key => $value) {
            if (is_array($value)) {
                echo "  {$key}: " . json_encode($value, JSON_UNESCAPED_UNICODE) . "\n";
            } else {
                echo "  {$key}: {$value}\n";
            }
        }
        
        if (!empty($result['alerts'])) {
            echo "\n🔍 Detailed Alerts:\n";
            foreach ($result['alerts'] as $i => $alert) {
                echo "  Alert " . ($i + 1) . ":\n";
                echo "    Level: " . formatAlertLevel($alert['level']) . "\n";
                echo "    Message: " . $alert['message'] . "\n";
                echo "    Time: " . $alert['timestamp'] . "\n";
                if (!empty($alert['context'])) {
                    echo "    Context: " . json_encode($alert['context'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
                }
                echo "\n";
            }
        }
    }
}

// Main execution
if (php_sapi_name() === 'cli') {
    try {
        $options = parseCommandLineOptions();
        
        if (!empty($options['help'])) {
            showHelp();
            exit(0);
        }
        
        echo "🚀 Starting Analytics ETL Monitoring - " . date('Y-m-d H:i:s') . "\n\n";
        
        // Initialize monitor with custom config if provided
        $monitorConfig = [];
        if (!empty($options['config']) && file_exists($options['config'])) {
            $monitorConfig = include $options['config'];
        }
        
        if (!empty($options['verbose'])) {
            $monitorConfig['detailed_logging'] = true;
        }
        
        $monitor = new AnalyticsETLMonitor($monitorConfig);
        $result = $monitor->performMonitoring();
        
        displayResults($result, $options);
        
        // Exit with appropriate code
        if ($result['status'] === 'healthy') {
            exit(0);
        } elseif ($result['status'] === 'alerts') {
            // Check if there are any critical alerts
            $hasCritical = false;
            foreach ($result['alerts'] as $alert) {
                if ($alert['level'] === 'CRITICAL') {
                    $hasCritical = true;
                    break;
                }
            }
            exit($hasCritical ? 2 : 1);
        } else {
            exit(3); // Error status
        }
        
    } catch (Exception $e) {
        echo "\n❌ Monitoring failed!\n";
        echo "Error: " . $e->getMessage() . "\n";
        
        if (!empty($options['verbose'])) {
            echo "Stack trace:\n" . $e->getTraceAsString() . "\n";
        }
        
        exit(4);
    }
} else {
    // Web interface
    header('Content-Type: application/json');
    
    try {
        $options = $_GET;
        $monitor = new AnalyticsETLMonitor($options);
        $result = $monitor->performMonitoring();
        
        echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode([
            'status' => 'error',
            'message' => $e->getMessage(),
            'timestamp' => date('Y-m-d H:i:s')
        ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    }
}