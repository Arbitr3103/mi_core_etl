#!/usr/bin/env php
<?php

/**
 * Ozon ETL System Health Check Script
 * 
 * CLI script for checking the health and status of the Ozon ETL system.
 * Performs comprehensive checks of database connectivity, API access,
 * system resources, and ETL process status.
 * 
 * Usage:
 *   php health_check.php [options]
 * 
 * Options:
 *   --config=FILE     Path to configuration file (optional)
 *   --format=FORMAT   Output format (text, json, xml) (default: text)
 *   --timeout=SEC     Timeout for API checks (default: 30)
 *   --verbose         Enable verbose output
 *   --quiet           Suppress all output except errors
 *   --help            Show this help message
 * 
 * Exit Codes:
 *   0 - All checks passed
 *   1 - Critical failures detected
 *   2 - Warning conditions detected
 * 
 * Requirements addressed:
 * - 4.4: Provide health check endpoint for monitoring system status
 * - 4.1: Log all operations with timestamps and status information
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

use MiCore\ETL\Ozon\Core\DatabaseConnection;
use MiCore\ETL\Ozon\Core\Logger;
use MiCore\ETL\Ozon\Api\OzonApiClient;

/**
 * Health check result class
 */
class HealthCheckResult {
    public string $component;
    public string $status; // 'ok', 'warning', 'critical'
    public string $message;
    public array $details;
    public float $responseTime;
    
    public function __construct(string $component, string $status, string $message, array $details = [], float $responseTime = 0.0) {
        $this->component = $component;
        $this->status = $status;
        $this->message = $message;
        $this->details = $details;
        $this->responseTime = $responseTime;
    }
    
    public function isHealthy(): bool {
        return $this->status === 'ok';
    }
    
    public function isCritical(): bool {
        return $this->status === 'critical';
    }
}

/**
 * Parse command line arguments
 */
function parseArguments(array $argv): array {
    $options = [
        'config' => null,
        'format' => 'text',
        'timeout' => 30,
        'verbose' => false,
        'quiet' => false,
        'help' => false
    ];

    for ($i = 1; $i < count($argv); $i++) {
        $arg = $argv[$i];
        
        if ($arg === '--help' || $arg === '-h') {
            $options['help'] = true;
        } elseif ($arg === '--verbose' || $arg === '-v') {
            $options['verbose'] = true;
        } elseif ($arg === '--quiet' || $arg === '-q') {
            $options['quiet'] = true;
        } elseif (strpos($arg, '--config=') === 0) {
            $options['config'] = substr($arg, 9);
        } elseif (strpos($arg, '--format=') === 0) {
            $options['format'] = substr($arg, 9);
        } elseif (strpos($arg, '--timeout=') === 0) {
            $options['timeout'] = (int)substr($arg, 10);
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
    echo "Ozon ETL System Health Check Script\n\n";
    echo "Usage: php health_check.php [options]\n\n";
    echo "Options:\n";
    echo "  --config=FILE     Path to configuration file (optional)\n";
    echo "  --format=FORMAT   Output format (text, json, xml) (default: text)\n";
    echo "  --timeout=SEC     Timeout for API checks (default: 30)\n";
    echo "  --verbose         Enable verbose output\n";
    echo "  --quiet           Suppress all output except errors\n";
    echo "  --help            Show this help message\n\n";
    echo "Output Formats:\n";
    echo "  text - Human readable text output\n";
    echo "  json - JSON formatted output for monitoring systems\n";
    echo "  xml  - XML formatted output\n\n";
    echo "Exit Codes:\n";
    echo "  0 - All checks passed\n";
    echo "  1 - Critical failures detected\n";
    echo "  2 - Warning conditions detected\n\n";
    echo "Examples:\n";
    echo "  php health_check.php\n";
    echo "  php health_check.php --format=json --quiet\n";
    echo "  php health_check.php --verbose --timeout=60\n\n";
}

/**
 * Check database connectivity and performance
 */
function checkDatabase(array $config, int $timeout): HealthCheckResult {
    $startTime = microtime(true);
    
    try {
        $db = new DatabaseConnection($config['database']);
        
        // Test basic connectivity
        $db->connect();
        
        // Test query performance
        $queryStart = microtime(true);
        $result = $db->query("SELECT 1 as test, NOW() as current_time");
        $queryTime = microtime(true) - $queryStart;
        
        // Check table existence
        $tables = ['dim_products', 'fact_orders', 'inventory', 'etl_execution_log'];
        $existingTables = [];
        $missingTables = [];
        
        foreach ($tables as $table) {
            $exists = $db->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_name = ?", [$table]);
            if ($exists && $exists[0]['count'] > 0) {
                $existingTables[] = $table;
            } else {
                $missingTables[] = $table;
            }
        }
        
        $responseTime = microtime(true) - $startTime;
        
        $details = [
            'query_time_ms' => round($queryTime * 1000, 2),
            'existing_tables' => $existingTables,
            'missing_tables' => $missingTables,
            'database_time' => $result[0]['current_time'] ?? null
        ];
        
        if (!empty($missingTables)) {
            return new HealthCheckResult(
                'database',
                'warning',
                'Database connected but some tables are missing',
                $details,
                $responseTime
            );
        }
        
        if ($queryTime > 1.0) {
            return new HealthCheckResult(
                'database',
                'warning',
                'Database connected but queries are slow',
                $details,
                $responseTime
            );
        }
        
        return new HealthCheckResult(
            'database',
            'ok',
            'Database connection healthy',
            $details,
            $responseTime
        );
        
    } catch (Exception $e) {
        $responseTime = microtime(true) - $startTime;
        
        return new HealthCheckResult(
            'database',
            'critical',
            'Database connection failed: ' . $e->getMessage(),
            ['error' => $e->getMessage()],
            $responseTime
        );
    }
}

/**
 * Check Ozon API connectivity and authentication
 */
function checkOzonAPI(array $config, int $timeout): HealthCheckResult {
    $startTime = microtime(true);
    
    try {
        $apiClient = new OzonApiClient($config['api']);
        
        // Test API connectivity with a simple request
        $queryStart = microtime(true);
        $response = $apiClient->getProducts(1); // Get just 1 product to test
        $queryTime = microtime(true) - $queryStart;
        
        $responseTime = microtime(true) - $startTime;
        
        $details = [
            'api_response_time_ms' => round($queryTime * 1000, 2),
            'products_available' => isset($response['result']['items']) ? count($response['result']['items']) : 0,
            'api_endpoint' => $config['api']['base_url'] ?? 'https://api-seller.ozon.ru'
        ];
        
        if ($queryTime > 5.0) {
            return new HealthCheckResult(
                'ozon_api',
                'warning',
                'Ozon API responding but slowly',
                $details,
                $responseTime
            );
        }
        
        return new HealthCheckResult(
            'ozon_api',
            'ok',
            'Ozon API connection healthy',
            $details,
            $responseTime
        );
        
    } catch (Exception $e) {
        $responseTime = microtime(true) - $startTime;
        
        return new HealthCheckResult(
            'ozon_api',
            'critical',
            'Ozon API connection failed: ' . $e->getMessage(),
            ['error' => $e->getMessage()],
            $responseTime
        );
    }
}

/**
 * Check system resources and configuration
 */
function checkSystemResources(array $config): HealthCheckResult {
    $startTime = microtime(true);
    
    $details = [
        'php_version' => PHP_VERSION,
        'memory_limit' => ini_get('memory_limit'),
        'memory_usage_mb' => round(memory_get_usage(true) / 1024 / 1024, 2),
        'memory_peak_mb' => round(memory_get_peak_usage(true) / 1024 / 1024, 2),
        'time_limit' => ini_get('max_execution_time'),
        'timezone' => date_default_timezone_get(),
        'disk_free_gb' => round(disk_free_space('.') / 1024 / 1024 / 1024, 2)
    ];
    
    // Check required directories
    $directories = [
        $config['etl']['logging']['path'] ?? '/tmp/ozon_etl_logs',
        $config['etl']['locks']['path'] ?? '/tmp/ozon_etl_locks',
        $config['api']['reports']['temp_dir'] ?? '/tmp/ozon_reports'
    ];
    
    $directoryStatus = [];
    $directoryIssues = [];
    
    foreach ($directories as $dir) {
        if (is_dir($dir) && is_writable($dir)) {
            $directoryStatus[$dir] = 'ok';
        } elseif (is_dir($dir)) {
            $directoryStatus[$dir] = 'not_writable';
            $directoryIssues[] = "$dir is not writable";
        } else {
            $directoryStatus[$dir] = 'missing';
            $directoryIssues[] = "$dir does not exist";
        }
    }
    
    $details['directories'] = $directoryStatus;
    
    $responseTime = microtime(true) - $startTime;
    
    // Check for issues
    $warnings = [];
    
    if (version_compare(PHP_VERSION, '8.1.0', '<')) {
        $warnings[] = 'PHP version is below recommended 8.1.0';
    }
    
    if ($details['disk_free_gb'] < 1.0) {
        $warnings[] = 'Low disk space (less than 1GB free)';
    }
    
    if (!empty($directoryIssues)) {
        $warnings = array_merge($warnings, $directoryIssues);
    }
    
    if (!empty($warnings)) {
        return new HealthCheckResult(
            'system',
            'warning',
            'System has some issues: ' . implode(', ', $warnings),
            $details,
            $responseTime
        );
    }
    
    return new HealthCheckResult(
        'system',
        'ok',
        'System resources healthy',
        $details,
        $responseTime
    );
}

/**
 * Check ETL process status and recent executions
 */
function checkETLStatus(DatabaseConnection $db): HealthCheckResult {
    $startTime = microtime(true);
    
    try {
        // Check recent ETL executions (last 24 hours)
        $recentExecutions = $db->query("
            SELECT etl_class, status, records_processed, duration_seconds, 
                   started_at, completed_at, error_message
            FROM etl_execution_log 
            WHERE started_at >= NOW() - INTERVAL '24 hours'
            ORDER BY started_at DESC
            LIMIT 10
        ");
        
        // Check for running processes (lock files)
        $lockFiles = [
            'sync_products.lock',
            'sync_sales.lock', 
            'sync_inventory.lock'
        ];
        
        $runningProcesses = [];
        foreach ($lockFiles as $lockFile) {
            $lockPath = sys_get_temp_dir() . '/' . $lockFile;
            if (file_exists($lockPath)) {
                $pid = (int)file_get_contents($lockPath);
                if (posix_kill($pid, 0)) {
                    $runningProcesses[] = [
                        'process' => str_replace('.lock', '', $lockFile),
                        'pid' => $pid
                    ];
                }
            }
        }
        
        $responseTime = microtime(true) - $startTime;
        
        $details = [
            'recent_executions' => count($recentExecutions),
            'running_processes' => $runningProcesses,
            'last_executions' => array_slice($recentExecutions, 0, 3) // Show last 3
        ];
        
        // Analyze execution status
        $failedExecutions = array_filter($recentExecutions, function($exec) {
            return $exec['status'] === 'failed';
        });
        
        $warnings = [];
        
        if (count($failedExecutions) > 0) {
            $warnings[] = count($failedExecutions) . ' failed executions in last 24 hours';
        }
        
        if (empty($recentExecutions)) {
            $warnings[] = 'No ETL executions found in last 24 hours';
        }
        
        if (!empty($warnings)) {
            return new HealthCheckResult(
                'etl_status',
                'warning',
                'ETL status has issues: ' . implode(', ', $warnings),
                $details,
                $responseTime
            );
        }
        
        return new HealthCheckResult(
            'etl_status',
            'ok',
            'ETL processes healthy',
            $details,
            $responseTime
        );
        
    } catch (Exception $e) {
        $responseTime = microtime(true) - $startTime;
        
        return new HealthCheckResult(
            'etl_status',
            'warning',
            'Could not check ETL status: ' . $e->getMessage(),
            ['error' => $e->getMessage()],
            $responseTime
        );
    }
}

/**
 * Output results in specified format
 */
function outputResults(array $results, string $format, bool $verbose, bool $quiet): void {
    if ($quiet && $format !== 'json') {
        return; // Suppress output in quiet mode except for JSON
    }
    
    switch ($format) {
        case 'json':
            $output = [
                'timestamp' => date('c'),
                'overall_status' => determineOverallStatus($results),
                'checks' => []
            ];
            
            foreach ($results as $result) {
                $check = [
                    'component' => $result->component,
                    'status' => $result->status,
                    'message' => $result->message,
                    'response_time_ms' => round($result->responseTime * 1000, 2)
                ];
                
                if ($verbose) {
                    $check['details'] = $result->details;
                }
                
                $output['checks'][] = $check;
            }
            
            echo json_encode($output, JSON_PRETTY_PRINT) . "\n";
            break;
            
        case 'xml':
            echo "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n";
            echo "<health_check timestamp=\"" . date('c') . "\" overall_status=\"" . determineOverallStatus($results) . "\">\n";
            
            foreach ($results as $result) {
                echo "  <check component=\"{$result->component}\" status=\"{$result->status}\" response_time_ms=\"" . round($result->responseTime * 1000, 2) . "\">\n";
                echo "    <message>" . htmlspecialchars($result->message) . "</message>\n";
                
                if ($verbose && !empty($result->details)) {
                    echo "    <details>\n";
                    foreach ($result->details as $key => $value) {
                        if (is_array($value)) {
                            $value = json_encode($value);
                        }
                        echo "      <{$key}>" . htmlspecialchars((string)$value) . "</{$key}>\n";
                    }
                    echo "    </details>\n";
                }
                
                echo "  </check>\n";
            }
            
            echo "</health_check>\n";
            break;
            
        default: // text
            echo "Ozon ETL System Health Check\n";
            echo "============================\n";
            echo "Timestamp: " . date('Y-m-d H:i:s T') . "\n\n";
            
            foreach ($results as $result) {
                $statusIcon = match($result->status) {
                    'ok' => '✓',
                    'warning' => '⚠',
                    'critical' => '✗',
                    default => '?'
                };
                
                echo sprintf("%-15s %s %s (%.2fms)\n", 
                    strtoupper($result->component), 
                    $statusIcon, 
                    $result->message,
                    $result->responseTime * 1000
                );
                
                if ($verbose && !empty($result->details)) {
                    foreach ($result->details as $key => $value) {
                        if (is_array($value)) {
                            $value = json_encode($value);
                        }
                        echo "                  {$key}: {$value}\n";
                    }
                }
                
                echo "\n";
            }
            
            echo "Overall Status: " . strtoupper(determineOverallStatus($results)) . "\n";
            break;
    }
}

/**
 * Determine overall system status
 */
function determineOverallStatus(array $results): string {
    $hasCritical = false;
    $hasWarning = false;
    
    foreach ($results as $result) {
        if ($result->isCritical()) {
            $hasCritical = true;
        } elseif ($result->status === 'warning') {
            $hasWarning = true;
        }
    }
    
    if ($hasCritical) {
        return 'critical';
    } elseif ($hasWarning) {
        return 'warning';
    } else {
        return 'ok';
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
    
    // Validate format
    if (!in_array($options['format'], ['text', 'json', 'xml'])) {
        echo "Error: Invalid format. Must be one of: text, json, xml\n";
        exit(1);
    }
    
    // Load custom configuration if provided
    if ($options['config'] && file_exists($options['config'])) {
        $customConfig = require $options['config'];
        $config = array_merge_recursive($config, $customConfig);
    }
    
    $results = [];
    
    try {
        // Initialize logger for health check
        $logger = new Logger($config['etl']['logging']);
        $logger->info('Health check started', ['format' => $options['format']]);
        
        // Run all health checks
        $results[] = checkSystemResources($config);
        $results[] = checkDatabase($config, $options['timeout']);
        $results[] = checkOzonAPI($config, $options['timeout']);
        
        // Check ETL status (requires database connection)
        if ($results[1]->isHealthy()) { // Database check passed
            $db = new DatabaseConnection($config['database']);
            $results[] = checkETLStatus($db);
        }
        
        // Output results
        outputResults($results, $options['format'], $options['verbose'], $options['quiet']);
        
        // Log completion
        $overallStatus = determineOverallStatus($results);
        $logger->info('Health check completed', [
            'overall_status' => $overallStatus,
            'checks_performed' => count($results)
        ]);
        
        // Set exit code based on overall status
        switch ($overallStatus) {
            case 'critical':
                exit(1);
            case 'warning':
                exit(2);
            default:
                exit(0);
        }
        
    } catch (Exception $e) {
        if (isset($logger)) {
            $logger->error('Health check failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
        }
        
        if (!$options['quiet']) {
            echo "Health check failed: " . $e->getMessage() . "\n";
        }
        
        exit(1);
    }
}

// Execute main function
main();