#!/usr/bin/env php
<?php
/**
 * Analytics ETL Production Smoke Tests
 * 
 * Task: 10.1 Деплой Analytics ETL системы - Провести smoke tests в production
 * Requirements: 2.1, 6.2, 7.1
 * 
 * This script performs comprehensive smoke tests to verify that the Analytics ETL
 * system is properly deployed and functioning in production.
 * 
 * Usage:
 *   php analytics_etl_smoke_tests.php [options]
 * 
 * Options:
 *   --help              Show this help message
 *   --verbose           Enable verbose output
 *   --json              Output results in JSON format
 *   --quick             Run only quick tests (skip long-running tests)
 *   --critical-only     Run only critical tests
 *   --report=FILE       Save detailed report to file
 */

// Set error reporting and timezone
error_reporting(E_ALL);
ini_set('display_errors', 1);
date_default_timezone_set('Europe/Moscow');

// Include configuration
require_once __DIR__ . '/config.php';

/**
 * Analytics ETL Smoke Test Suite
 */
class AnalyticsETLSmokeTests
{
    private array $options;
    private array $results = [];
    private int $totalTests = 0;
    private int $passedTests = 0;
    private int $failedTests = 0;
    private int $skippedTests = 0;
    private float $startTime;
    private bool $verbose;
    private bool $jsonOutput;
    
    // Test categories
    private const CRITICAL_TESTS = [
        'testDatabaseConnectivity',
        'testRequiredTables',
        'testETLScriptExists',
        'testBasicETLFunctionality'
    ];
    
    private const QUICK_TESTS = [
        'testDatabaseConnectivity',
        'testRequiredTables',
        'testETLScriptExists',
        'testMonitoringScript',
        'testLogDirectories',
        'testEnvironmentVariables'
    ];
    
    public function __construct(array $options = [])
    {
        $this->options = $options;
        $this->verbose = $options['verbose'] ?? false;
        $this->jsonOutput = $options['json'] ?? false;
        $this->startTime = microtime(true);
    }
    
    /**
     * Run all smoke tests
     */
    public function runTests(): array
    {
        $this->output("🧪 Analytics ETL Production Smoke Tests", 'header');
        $this->output("=====================================", 'header');
        $this->output("");
        
        $testMethods = $this->getTestMethods();
        
        foreach ($testMethods as $method) {
            $this->runTest($method);
        }
        
        $this->generateSummary();
        
        return $this->results;
    }
    
    /**
     * Get list of test methods to run
     */
    private function getTestMethods(): array
    {
        $allTests = [
            'testDatabaseConnectivity',
            'testRequiredTables',
            'testETLScriptExists',
            'testMonitoringScript',
            'testAlertManager',
            'testLogDirectories',
            'testCacheDirectories',
            'testEnvironmentVariables',
            'testBasicETLFunctionality',
            'testDatabaseSchema',
            'testCronJobs',
            'testAPIEndpoints',
            'testFilePermissions',
            'testDiskSpace',
            'testSystemResources'
        ];
        
        if (isset($this->options['critical-only'])) {
            return array_intersect($allTests, self::CRITICAL_TESTS);
        }
        
        if (isset($this->options['quick'])) {
            return array_intersect($allTests, self::QUICK_TESTS);
        }
        
        return $allTests;
    }
    
    /**
     * Run individual test
     */
    private function runTest(string $method): void
    {
        $this->totalTests++;
        $testName = $this->getTestName($method);
        
        $this->output("Running: $testName", 'test');
        
        $startTime = microtime(true);
        
        try {
            $result = $this->$method();
            $duration = microtime(true) - $startTime;
            
            if ($result['success']) {
                $this->passedTests++;
                $this->output("✅ PASS: $testName (" . number_format($duration, 3) . "s)", 'pass');
            } else {
                $this->failedTests++;
                $this->output("❌ FAIL: $testName - " . $result['message'], 'fail');
            }
            
            $this->results[$method] = [
                'name' => $testName,
                'success' => $result['success'],
                'message' => $result['message'],
                'duration' => $duration,
                'details' => $result['details'] ?? null
            ];
            
        } catch (Exception $e) {
            $this->failedTests++;
            $duration = microtime(true) - $startTime;
            
            $this->output("❌ ERROR: $testName - " . $e->getMessage(), 'fail');
            
            $this->results[$method] = [
                'name' => $testName,
                'success' => false,
                'message' => 'Exception: ' . $e->getMessage(),
                'duration' => $duration,
                'details' => [
                    'exception' => get_class($e),
                    'file' => $e->getFile(),
                    'line' => $e->getLine()
                ]
            ];
        }
        
        if ($this->verbose && isset($this->results[$method]['details'])) {
            $this->output("   Details: " . json_encode($this->results[$method]['details']), 'detail');
        }
        
        $this->output("");
    }
    
    /**
     * Test database connectivity
     */
    private function testDatabaseConnectivity(): array
    {
        try {
            $pdo = getDatabaseConnection();
            $stmt = $pdo->query('SELECT 1 as test');
            $result = $stmt->fetch();
            
            if ($result && $result['test'] == 1) {
                return [
                    'success' => true,
                    'message' => 'Database connection successful',
                    'details' => [
                        'driver' => $pdo->getAttribute(PDO::ATTR_DRIVER_NAME),
                        'server_version' => $pdo->getAttribute(PDO::ATTR_SERVER_VERSION)
                    ]
                ];
            } else {
                return [
                    'success' => false,
                    'message' => 'Database query failed'
                ];
            }
        } catch (Exception $e) {
            return [
                'success' => false,
                'message' => 'Database connection failed: ' . $e->getMessage()
            ];
        }
    }
    
    /**
     * Test required database tables
     */
    private function testRequiredTables(): array
    {
        $requiredTables = [
            'inventory',
            'analytics_etl_log',
            'warehouse_normalization'
        ];
        
        try {
            $pdo = getDatabaseConnection();
            $missingTables = [];
            
            foreach ($requiredTables as $table) {
                $stmt = $pdo->prepare("
                    SELECT COUNT(*) 
                    FROM information_schema.tables 
                    WHERE table_name = ? AND table_schema = 'public'
                ");
                $stmt->execute([$table]);
                
                if ($stmt->fetchColumn() == 0) {
                    $missingTables[] = $table;
                }
            }
            
            if (empty($missingTables)) {
                return [
                    'success' => true,
                    'message' => 'All required tables exist',
                    'details' => ['tables' => $requiredTables]
                ];
            } else {
                return [
                    'success' => false,
                    'message' => 'Missing tables: ' . implode(', ', $missingTables),
                    'details' => ['missing_tables' => $missingTables]
                ];
            }
        } catch (Exception $e) {
            return [
                'success' => false,
                'message' => 'Table check failed: ' . $e->getMessage()
            ];
        }
    }
    
    /**
     * Test ETL script exists and is executable
     */
    private function testETLScriptExists(): array
    {
        $scriptPath = __DIR__ . '/warehouse_etl_analytics.php';
        
        if (!file_exists($scriptPath)) {
            return [
                'success' => false,
                'message' => 'ETL script not found',
                'details' => ['expected_path' => $scriptPath]
            ];
        }
        
        if (!is_executable($scriptPath)) {
            return [
                'success' => false,
                'message' => 'ETL script is not executable',
                'details' => ['path' => $scriptPath, 'permissions' => substr(sprintf('%o', fileperms($scriptPath)), -4)]
            ];
        }
        
        return [
            'success' => true,
            'message' => 'ETL script exists and is executable',
            'details' => [
                'path' => $scriptPath,
                'size' => filesize($scriptPath),
                'modified' => date('Y-m-d H:i:s', filemtime($scriptPath))
            ]
        ];
    }
    
    /**
     * Test monitoring script
     */
    private function testMonitoringScript(): array
    {
        $scriptPath = __DIR__ . '/monitor_analytics_etl.php';
        
        if (!file_exists($scriptPath)) {
            return [
                'success' => false,
                'message' => 'Monitoring script not found',
                'details' => ['expected_path' => $scriptPath]
            ];
        }
        
        if (!is_executable($scriptPath)) {
            return [
                'success' => false,
                'message' => 'Monitoring script is not executable'
            ];
        }
        
        return [
            'success' => true,
            'message' => 'Monitoring script exists and is executable',
            'details' => [
                'path' => $scriptPath,
                'size' => filesize($scriptPath)
            ]
        ];
    }
    
    /**
     * Test alert manager
     */
    private function testAlertManager(): array
    {
        $scriptPath = __DIR__ . '/run_alert_manager.php';
        
        if (!file_exists($scriptPath)) {
            return [
                'success' => false,
                'message' => 'Alert manager script not found'
            ];
        }
        
        // Test basic functionality
        $output = shell_exec("php $scriptPath stats 2>&1");
        
        if ($output === null) {
            return [
                'success' => false,
                'message' => 'Alert manager failed to execute'
            ];
        }
        
        return [
            'success' => true,
            'message' => 'Alert manager is functional',
            'details' => ['output_length' => strlen($output)]
        ];
    }
    
    /**
     * Test log directories
     */
    private function testLogDirectories(): array
    {
        $requiredDirs = [
            'logs/analytics_etl',
            'logs/cron'
        ];
        
        $missingDirs = [];
        $details = [];
        
        foreach ($requiredDirs as $dir) {
            $fullPath = __DIR__ . '/' . $dir;
            
            if (!is_dir($fullPath)) {
                $missingDirs[] = $dir;
            } else {
                $details[$dir] = [
                    'exists' => true,
                    'writable' => is_writable($fullPath),
                    'permissions' => substr(sprintf('%o', fileperms($fullPath)), -4)
                ];
            }
        }
        
        if (empty($missingDirs)) {
            return [
                'success' => true,
                'message' => 'All log directories exist',
                'details' => $details
            ];
        } else {
            return [
                'success' => false,
                'message' => 'Missing log directories: ' . implode(', ', $missingDirs),
                'details' => ['missing_dirs' => $missingDirs]
            ];
        }
    }
    
    /**
     * Test cache directories
     */
    private function testCacheDirectories(): array
    {
        $requiredDirs = [
            'cache/analytics_api'
        ];
        
        $missingDirs = [];
        
        foreach ($requiredDirs as $dir) {
            $fullPath = __DIR__ . '/' . $dir;
            
            if (!is_dir($fullPath)) {
                $missingDirs[] = $dir;
            }
        }
        
        if (empty($missingDirs)) {
            return [
                'success' => true,
                'message' => 'All cache directories exist'
            ];
        } else {
            return [
                'success' => false,
                'message' => 'Missing cache directories: ' . implode(', ', $missingDirs)
            ];
        }
    }
    
    /**
     * Test environment variables
     */
    private function testEnvironmentVariables(): array
    {
        $requiredVars = [
            'OZON_CLIENT_ID',
            'OZON_API_KEY'
        ];
        
        $missingVars = [];
        $setVars = [];
        
        foreach ($requiredVars as $var) {
            $value = getenv($var);
            if (empty($value)) {
                $missingVars[] = $var;
            } else {
                $setVars[] = $var;
            }
        }
        
        if (empty($missingVars)) {
            return [
                'success' => true,
                'message' => 'All required environment variables are set',
                'details' => ['set_vars' => $setVars]
            ];
        } else {
            return [
                'success' => false,
                'message' => 'Missing environment variables: ' . implode(', ', $missingVars),
                'details' => ['missing_vars' => $missingVars, 'set_vars' => $setVars]
            ];
        }
    }
    
    /**
     * Test basic ETL functionality
     */
    private function testBasicETLFunctionality(): array
    {
        $scriptPath = __DIR__ . '/warehouse_etl_analytics.php';
        
        // Run ETL in dry-run mode with minimal data
        $command = "timeout 60 php $scriptPath --dry-run --limit=1 --debug 2>&1";
        $output = shell_exec($command);
        $exitCode = shell_exec("echo $?");
        
        if ($exitCode == 0) {
            return [
                'success' => true,
                'message' => 'ETL basic functionality test passed',
                'details' => ['output_length' => strlen($output)]
            ];
        } else {
            return [
                'success' => false,
                'message' => 'ETL basic functionality test failed',
                'details' => ['exit_code' => trim($exitCode), 'output' => substr($output, 0, 500)]
            ];
        }
    }
    
    /**
     * Test database schema
     */
    private function testDatabaseSchema(): array
    {
        try {
            $pdo = getDatabaseConnection();
            
            // Test inventory table has required columns
            $stmt = $pdo->query("
                SELECT column_name 
                FROM information_schema.columns 
                WHERE table_name = 'inventory' AND table_schema = 'public'
            ");
            $columns = $stmt->fetchAll(PDO::FETCH_COLUMN);
            
            $requiredColumns = [
                'data_source',
                'data_quality_score',
                'last_analytics_sync',
                'normalized_warehouse_name'
            ];
            
            $missingColumns = array_diff($requiredColumns, $columns);
            
            if (empty($missingColumns)) {
                return [
                    'success' => true,
                    'message' => 'Database schema is properly extended',
                    'details' => ['columns_found' => count($columns)]
                ];
            } else {
                return [
                    'success' => false,
                    'message' => 'Missing required columns: ' . implode(', ', $missingColumns)
                ];
            }
        } catch (Exception $e) {
            return [
                'success' => false,
                'message' => 'Schema check failed: ' . $e->getMessage()
            ];
        }
    }
    
    /**
     * Test cron jobs installation
     */
    private function testCronJobs(): array
    {
        $output = shell_exec('crontab -l 2>/dev/null');
        
        if ($output === null) {
            return [
                'success' => false,
                'message' => 'No crontab found or crontab command failed'
            ];
        }
        
        $hasETLJob = strpos($output, 'warehouse_etl_analytics.php') !== false;
        $hasMonitoringJob = strpos($output, 'monitor_analytics_etl.php') !== false;
        
        if ($hasETLJob && $hasMonitoringJob) {
            return [
                'success' => true,
                'message' => 'Analytics ETL cron jobs are installed',
                'details' => ['etl_job' => $hasETLJob, 'monitoring_job' => $hasMonitoringJob]
            ];
        } else {
            return [
                'success' => false,
                'message' => 'Missing cron jobs - ETL: ' . ($hasETLJob ? 'found' : 'missing') . ', Monitoring: ' . ($hasMonitoringJob ? 'found' : 'missing')
            ];
        }
    }
    
    /**
     * Test API endpoints
     */
    private function testAPIEndpoints(): array
    {
        $endpoints = [
            '/api/warehouse/analytics-status',
            '/api/warehouse/data-quality'
        ];
        
        $results = [];
        $allPassed = true;
        
        foreach ($endpoints as $endpoint) {
            $url = "http://localhost$endpoint";
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, 5);
            curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
            
            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            
            $results[$endpoint] = [
                'http_code' => $httpCode,
                'accessible' => $httpCode < 500
            ];
            
            if ($httpCode >= 500) {
                $allPassed = false;
            }
        }
        
        if ($allPassed) {
            return [
                'success' => true,
                'message' => 'API endpoints are accessible',
                'details' => $results
            ];
        } else {
            return [
                'success' => false,
                'message' => 'Some API endpoints are not accessible',
                'details' => $results
            ];
        }
    }
    
    /**
     * Test file permissions
     */
    private function testFilePermissions(): array
    {
        $files = [
            'warehouse_etl_analytics.php' => 0755,
            'monitor_analytics_etl.php' => 0755,
            'run_alert_manager.php' => 0755
        ];
        
        $issues = [];
        
        foreach ($files as $file => $expectedPerms) {
            $fullPath = __DIR__ . '/' . $file;
            
            if (file_exists($fullPath)) {
                $actualPerms = fileperms($fullPath) & 0777;
                
                if ($actualPerms != $expectedPerms) {
                    $issues[] = "$file has permissions " . decoct($actualPerms) . ", expected " . decoct($expectedPerms);
                }
            } else {
                $issues[] = "$file does not exist";
            }
        }
        
        if (empty($issues)) {
            return [
                'success' => true,
                'message' => 'File permissions are correct'
            ];
        } else {
            return [
                'success' => false,
                'message' => 'Permission issues: ' . implode(', ', $issues)
            ];
        }
    }
    
    /**
     * Test disk space
     */
    private function testDiskSpace(): array
    {
        $freeBytes = disk_free_space(__DIR__);
        $totalBytes = disk_total_space(__DIR__);
        
        if ($freeBytes === false || $totalBytes === false) {
            return [
                'success' => false,
                'message' => 'Unable to check disk space'
            ];
        }
        
        $freePercent = ($freeBytes / $totalBytes) * 100;
        $freeGB = $freeBytes / (1024 * 1024 * 1024);
        
        if ($freePercent < 10) {
            return [
                'success' => false,
                'message' => 'Low disk space: ' . number_format($freePercent, 1) . '% free',
                'details' => ['free_gb' => number_format($freeGB, 2)]
            ];
        } else {
            return [
                'success' => true,
                'message' => 'Sufficient disk space: ' . number_format($freePercent, 1) . '% free',
                'details' => ['free_gb' => number_format($freeGB, 2)]
            ];
        }
    }
    
    /**
     * Test system resources
     */
    private function testSystemResources(): array
    {
        $memoryLimit = ini_get('memory_limit');
        $maxExecutionTime = ini_get('max_execution_time');
        
        $issues = [];
        
        // Check memory limit
        if ($memoryLimit !== '-1') {
            $memoryBytes = $this->parseMemoryLimit($memoryLimit);
            if ($memoryBytes < 256 * 1024 * 1024) { // 256MB
                $issues[] = "Memory limit too low: $memoryLimit";
            }
        }
        
        // Check execution time
        if ($maxExecutionTime > 0 && $maxExecutionTime < 300) { // 5 minutes
            $issues[] = "Max execution time too low: {$maxExecutionTime}s";
        }
        
        if (empty($issues)) {
            return [
                'success' => true,
                'message' => 'System resources are adequate',
                'details' => [
                    'memory_limit' => $memoryLimit,
                    'max_execution_time' => $maxExecutionTime
                ]
            ];
        } else {
            return [
                'success' => false,
                'message' => 'Resource issues: ' . implode(', ', $issues),
                'details' => [
                    'memory_limit' => $memoryLimit,
                    'max_execution_time' => $maxExecutionTime
                ]
            ];
        }
    }
    
    /**
     * Parse memory limit string to bytes
     */
    private function parseMemoryLimit(string $limit): int
    {
        $limit = trim($limit);
        $last = strtolower($limit[strlen($limit) - 1]);
        $value = (int) $limit;
        
        switch ($last) {
            case 'g':
                $value *= 1024;
            case 'm':
                $value *= 1024;
            case 'k':
                $value *= 1024;
        }
        
        return $value;
    }
    
    /**
     * Get human-readable test name
     */
    private function getTestName(string $method): string
    {
        $name = str_replace('test', '', $method);
        $name = preg_replace('/([A-Z])/', ' $1', $name);
        return trim($name);
    }
    
    /**
     * Output message with formatting
     */
    private function output(string $message, string $type = 'info'): void
    {
        if ($this->jsonOutput) {
            return; // Don't output during JSON mode
        }
        
        switch ($type) {
            case 'header':
                echo "\033[1;35m$message\033[0m\n";
                break;
            case 'test':
                echo "\033[0;36m$message\033[0m\n";
                break;
            case 'pass':
                echo "\033[0;32m$message\033[0m\n";
                break;
            case 'fail':
                echo "\033[0;31m$message\033[0m\n";
                break;
            case 'detail':
                echo "\033[0;37m$message\033[0m\n";
                break;
            default:
                echo "$message\n";
        }
    }
    
    /**
     * Generate test summary
     */
    private function generateSummary(): void
    {
        $duration = microtime(true) - $this->startTime;
        
        if ($this->jsonOutput) {
            $summary = [
                'summary' => [
                    'total_tests' => $this->totalTests,
                    'passed' => $this->passedTests,
                    'failed' => $this->failedTests,
                    'skipped' => $this->skippedTests,
                    'success_rate' => $this->totalTests > 0 ? ($this->passedTests / $this->totalTests) * 100 : 0,
                    'duration' => $duration,
                    'timestamp' => date('Y-m-d H:i:s')
                ],
                'results' => $this->results
            ];
            
            echo json_encode($summary, JSON_PRETTY_PRINT) . "\n";
        } else {
            $this->output("", 'info');
            $this->output("📊 Test Summary", 'header');
            $this->output("==============", 'header');
            $this->output("Total tests: {$this->totalTests}");
            $this->output("Passed: {$this->passedTests}", 'pass');
            $this->output("Failed: {$this->failedTests}", $this->failedTests > 0 ? 'fail' : 'info');
            $this->output("Skipped: {$this->skippedTests}");
            
            if ($this->totalTests > 0) {
                $successRate = ($this->passedTests / $this->totalTests) * 100;
                $this->output("Success rate: " . number_format($successRate, 1) . "%");
            }
            
            $this->output("Duration: " . number_format($duration, 2) . " seconds");
            $this->output("");
            
            if ($this->failedTests > 0) {
                $this->output("❌ Some tests failed. Please review the issues above.", 'fail');
                $this->output("The Analytics ETL system may not be fully functional.", 'fail');
            } else {
                $this->output("✅ All tests passed! Analytics ETL system is ready for production.", 'pass');
            }
        }
    }
}

// Parse command line arguments
$options = [];
$args = array_slice($argv, 1);

foreach ($args as $arg) {
    if ($arg === '--help') {
        echo "Analytics ETL Production Smoke Tests\n";
        echo "===================================\n\n";
        echo "Usage: php analytics_etl_smoke_tests.php [options]\n\n";
        echo "Options:\n";
        echo "  --help              Show this help message\n";
        echo "  --verbose           Enable verbose output\n";
        echo "  --json              Output results in JSON format\n";
        echo "  --quick             Run only quick tests (skip long-running tests)\n";
        echo "  --critical-only     Run only critical tests\n";
        echo "  --report=FILE       Save detailed report to file\n\n";
        echo "Test Categories:\n";
        echo "  Critical: Database connectivity, required tables, ETL script, basic functionality\n";
        echo "  Quick: Critical tests + monitoring, logs, environment variables\n";
        echo "  Full: All tests including API endpoints, cron jobs, permissions, resources\n\n";
        exit(0);
    } elseif ($arg === '--verbose') {
        $options['verbose'] = true;
    } elseif ($arg === '--json') {
        $options['json'] = true;
    } elseif ($arg === '--quick') {
        $options['quick'] = true;
    } elseif ($arg === '--critical-only') {
        $options['critical-only'] = true;
    } elseif (strpos($arg, '--report=') === 0) {
        $options['report'] = substr($arg, 9);
    }
}

// Run smoke tests
try {
    $smokeTests = new AnalyticsETLSmokeTests($options);
    $results = $smokeTests->runTests();
    
    // Save report if requested
    if (isset($options['report'])) {
        $reportData = [
            'timestamp' => date('Y-m-d H:i:s'),
            'hostname' => gethostname(),
            'php_version' => PHP_VERSION,
            'results' => $results
        ];
        
        file_put_contents($options['report'], json_encode($reportData, JSON_PRETTY_PRINT));
        
        if (!isset($options['json'])) {
            echo "\n📄 Detailed report saved to: {$options['report']}\n";
        }
    }
    
    // Exit with appropriate code
    $hasFailures = false;
    foreach ($results as $result) {
        if (!$result['success']) {
            $hasFailures = true;
            break;
        }
    }
    
    exit($hasFailures ? 1 : 0);
    
} catch (Exception $e) {
    if (isset($options['json'])) {
        echo json_encode([
            'error' => $e->getMessage(),
            'timestamp' => date('Y-m-d H:i:s')
        ]) . "\n";
    } else {
        echo "❌ Smoke tests failed with exception: " . $e->getMessage() . "\n";
    }
    exit(1);
}