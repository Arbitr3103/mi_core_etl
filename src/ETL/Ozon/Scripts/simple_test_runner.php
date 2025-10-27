#!/usr/bin/env php
<?php

/**
 * Simple Test Runner for ETL Integration Tests
 * 
 * Runs basic integration tests without requiring PHPUnit
 */

declare(strict_types=1);

// Set error reporting and time limits
error_reporting(E_ALL);
ini_set('display_errors', '1');
set_time_limit(300); // 5 minutes

// Change to script directory
chdir(__DIR__);

echo "ETL Integration Tests - Simple Runner\n";
echo "====================================\n\n";

try {
    // Load autoloader and configuration
    $config = require_once __DIR__ . '/../autoload.php';
    
    $testsPassed = 0;
    $testsFailed = 0;
    $startTime = microtime(true);
    
    // Test 1: Configuration Loading
    echo "1. Testing configuration loading...\n";
    try {
        
        if (!is_array($config)) {
            throw new Exception('Configuration should return an array');
        }
        
        if (!isset($config['database'])) {
            throw new Exception('Configuration should contain database settings');
        }
        
        echo "   ✅ Configuration loading test passed\n";
        $testsPassed++;
        
    } catch (Exception $e) {
        echo "   ❌ Configuration loading test failed: " . $e->getMessage() . "\n";
        $testsFailed++;
    }
    
    // Test 2: Database Connection
    echo "2. Testing database connection...\n";
    try {
        $db = new MiCore\ETL\Ozon\Core\DatabaseConnection($config['database']);
        
        $result = $db->query('SELECT 1 as test');
        
        if (empty($result) || $result[0]['test'] != 1) {
            throw new Exception('Database connectivity test failed');
        }
        
        echo "   ✅ Database connection test passed\n";
        $testsPassed++;
        
    } catch (Exception $e) {
        echo "   ❌ Database connection test failed: " . $e->getMessage() . "\n";
        $testsFailed++;
    }
    
    // Test 3: Logger Functionality
    echo "3. Testing logger functionality...\n";
    try {
        $logFile = '/tmp/etl_test_' . uniqid() . '.log';
        $logger = new MiCore\ETL\Ozon\Core\Logger($logFile, 'DEBUG');
        
        $logger->info('Test message', ['test' => true]);
        
        if (!file_exists($logFile)) {
            throw new Exception('Log file was not created');
        }
        
        $logContent = file_get_contents($logFile);
        if (strpos($logContent, 'Test message') === false) {
            throw new Exception('Log message was not written');
        }
        
        // Clean up
        unlink($logFile);
        
        echo "   ✅ Logger functionality test passed\n";
        $testsPassed++;
        
    } catch (Exception $e) {
        echo "   ❌ Logger functionality test failed: " . $e->getMessage() . "\n";
        $testsFailed++;
    }
    
    // Test 4: ETL Orchestrator Initialization
    echo "4. Testing ETL orchestrator initialization...\n";
    try {
        $db = new MiCore\ETL\Ozon\Core\DatabaseConnection($config['database']);
        $logger = new MiCore\ETL\Ozon\Core\Logger('/tmp/etl_test.log', 'INFO');
        
        // Use mock API client from autoloader
        $apiClient = new MockOzonApiClient($config['ozon_api']);
        
        $orchestrator = new MiCore\ETL\Ozon\Core\ETLOrchestrator($db, $logger, $apiClient, [
            'max_retries' => 1,
            'retry_delay' => 1,
            'enable_dependency_checks' => false, // Disable for testing
            'enable_retry_logic' => true
        ]);
        
        $status = $orchestrator->getStatus();
        
        if (!is_array($status)) {
            throw new Exception('Orchestrator status should return an array');
        }
        
        if (!isset($status['config'])) {
            throw new Exception('Orchestrator status should contain config');
        }
        
        echo "   ✅ ETL orchestrator initialization test passed\n";
        $testsPassed++;
        
    } catch (Exception $e) {
        echo "   ❌ ETL orchestrator initialization test failed: " . $e->getMessage() . "\n";
        $testsFailed++;
    }
    
    // Test 5: Mock API Client
    echo "5. Testing mock API client...\n";
    try {
        $apiClient = new MockOzonApiClient($config['ozon_api']);
        
        // Test products report creation
        $reportResult = $apiClient->createProductsReport();
        if (!isset($reportResult['result']['code'])) {
            throw new Exception('Products report creation should return a code');
        }
        
        // Test report completion
        $completionResult = $apiClient->waitForReportCompletion($reportResult['result']['code']);
        if (!isset($completionResult['result']['file'])) {
            throw new Exception('Report completion should return a file URL');
        }
        
        // Test CSV download
        $csvData = $apiClient->downloadAndParseCsv($completionResult['result']['file']);
        if (!is_array($csvData) || empty($csvData)) {
            throw new Exception('CSV download should return non-empty array');
        }
        
        echo "   ✅ Mock API client test passed\n";
        $testsPassed++;
        
    } catch (Exception $e) {
        echo "   ❌ Mock API client test failed: " . $e->getMessage() . "\n";
        $testsFailed++;
    }
    
    // Test 6: ETL Workflow Execution (Mock)
    echo "6. Testing ETL workflow execution (mock)...\n";
    try {
        $db = new MiCore\ETL\Ozon\Core\DatabaseConnection($config['database']);
        $logger = new MiCore\ETL\Ozon\Core\Logger('/tmp/etl_workflow_test.log', 'INFO');
        $apiClient = new MockOzonApiClient($config['ozon_api']);
        
        $orchestrator = new MiCore\ETL\Ozon\Core\ETLOrchestrator($db, $logger, $apiClient, [
            'max_retries' => 1,
            'retry_delay' => 1,
            'enable_dependency_checks' => false, // Disable for mock testing
            'enable_retry_logic' => true,
            'product_etl' => $config['product_etl'],
            'inventory_etl' => $config['inventory_etl']
        ]);
        
        // This should work with mock data
        $result = $orchestrator->executeETLWorkflow(['test_mode' => true]);
        
        if (!is_array($result)) {
            throw new Exception('Workflow execution should return an array');
        }
        
        if ($result['status'] !== 'success') {
            throw new Exception('Workflow should complete successfully with mock data');
        }
        
        if (!isset($result['workflow_id'])) {
            throw new Exception('Workflow result should contain workflow_id');
        }
        
        echo "   ✅ ETL workflow execution test passed\n";
        $testsPassed++;
        
    } catch (Exception $e) {
        echo "   ❌ ETL workflow execution test failed: " . $e->getMessage() . "\n";
        $testsFailed++;
    }
    
    // Test 7: Cron Configuration Validation
    echo "7. Testing cron configuration validation...\n";
    try {
        $cronConfigPath = __DIR__ . '/../Config/cron_config.php';
        
        if (!file_exists($cronConfigPath)) {
            throw new Exception('Cron configuration file not found');
        }
        
        $cronConfig = require $cronConfigPath;
        
        if (!is_array($cronConfig)) {
            throw new Exception('Cron configuration should return an array');
        }
        
        $requiredSections = ['etl_execution', 'monitoring', 'alerts', 'dependencies'];
        foreach ($requiredSections as $section) {
            if (!isset($cronConfig[$section])) {
                throw new Exception("Missing required cron configuration section: $section");
            }
        }
        
        echo "   ✅ Cron configuration validation test passed\n";
        $testsPassed++;
        
    } catch (Exception $e) {
        echo "   ❌ Cron configuration validation test failed: " . $e->getMessage() . "\n";
        $testsFailed++;
    }
    
    // Test 8: Script Files Existence
    echo "8. Testing script files existence...\n";
    try {
        $requiredScripts = [
            'run_etl_workflow.php',
            'manage_cron.php',
            'visibility_metrics_tracker.php',
            'etl_health_check.php'
        ];
        
        foreach ($requiredScripts as $script) {
            $scriptPath = __DIR__ . '/' . $script;
            if (!file_exists($scriptPath)) {
                throw new Exception("Required script not found: $script");
            }
            
            if (!is_executable($scriptPath)) {
                // Try to make it executable
                chmod($scriptPath, 0755);
            }
        }
        
        echo "   ✅ Script files existence test passed\n";
        $testsPassed++;
        
    } catch (Exception $e) {
        echo "   ❌ Script files existence test failed: " . $e->getMessage() . "\n";
        $testsFailed++;
    }
    
    // Test 9: Directory Structure
    echo "9. Testing directory structure...\n";
    try {
        $requiredDirs = [
            __DIR__ . '/../Core',
            __DIR__ . '/../Components',
            __DIR__ . '/../Config',
            __DIR__ . '/../Scripts',
            __DIR__ . '/../Tests'
        ];
        
        foreach ($requiredDirs as $dir) {
            if (!is_dir($dir)) {
                throw new Exception("Required directory not found: " . basename($dir));
            }
        }
        
        // Create log directories if they don't exist
        $logDirs = [
            __DIR__ . '/../Logs',
            __DIR__ . '/../Logs/cron',
            __DIR__ . '/../Logs/visibility_reports'
        ];
        
        foreach ($logDirs as $logDir) {
            if (!is_dir($logDir)) {
                mkdir($logDir, 0755, true);
            }
            
            if (!is_writable($logDir)) {
                throw new Exception("Log directory not writable: " . basename($logDir));
            }
        }
        
        echo "   ✅ Directory structure test passed\n";
        $testsPassed++;
        
    } catch (Exception $e) {
        echo "   ❌ Directory structure test failed: " . $e->getMessage() . "\n";
        $testsFailed++;
    }
    
    // Test 10: File Permissions
    echo "10. Testing file permissions...\n";
    try {
        $scriptsDir = __DIR__;
        $scripts = glob($scriptsDir . '/*.php');
        
        foreach ($scripts as $script) {
            if (!is_readable($script)) {
                throw new Exception("Script not readable: " . basename($script));
            }
        }
        
        // Test log file creation
        $testLogFile = __DIR__ . '/../Logs/permission_test.log';
        if (file_put_contents($testLogFile, 'test') === false) {
            throw new Exception('Cannot write to log directory');
        }
        
        // Clean up
        if (file_exists($testLogFile)) {
            unlink($testLogFile);
        }
        
        echo "   ✅ File permissions test passed\n";
        $testsPassed++;
        
    } catch (Exception $e) {
        echo "   ❌ File permissions test failed: " . $e->getMessage() . "\n";
        $testsFailed++;
    }
    
    // Summary
    $duration = microtime(true) - $startTime;
    $totalTests = $testsPassed + $testsFailed;
    
    echo "\n" . str_repeat('=', 50) . "\n";
    echo "Test Results Summary\n";
    echo str_repeat('=', 50) . "\n";
    echo "Total Tests: $totalTests\n";
    echo "Passed: $testsPassed\n";
    echo "Failed: $testsFailed\n";
    echo "Duration: " . round($duration, 2) . " seconds\n";
    
    if ($testsFailed === 0) {
        echo "\n✅ All tests passed! ETL system is ready.\n";
        exit(0);
    } else {
        echo "\n❌ Some tests failed. Please check the output above.\n";
        exit(1);
    }
    
} catch (Exception $e) {
    echo "Fatal error running tests: " . $e->getMessage() . "\n";
    exit(1);
}