<?php
/**
 * Test Runner for Ozon Stock Reports System
 * 
 * Executes the comprehensive test suite including unit tests, integration tests,
 * and end-to-end tests for the Ozon warehouse stock reports system.
 */

// Set error reporting
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Set memory limit for testing
ini_set('memory_limit', '1G');

// Set time limit for long-running tests
set_time_limit(1800); // 30 minutes

echo "=================================================================\n";
echo "OZON STOCK REPORTS SYSTEM - COMPREHENSIVE TEST SUITE\n";
echo "=================================================================\n\n";

// Check if PHPUnit is available
if (!class_exists('PHPUnit\Framework\TestCase')) {
    echo "ERROR: PHPUnit is not available. Please install PHPUnit to run tests.\n";
    echo "You can install it via Composer: composer require --dev phpunit/phpunit\n";
    exit(1);
}

// Check database connection
try {
    $testConnection = new PDO('mysql:host=localhost', 'root', '');
    echo "✓ Database connection available\n";
} catch (PDOException $e) {
    echo "✗ Database connection failed: " . $e->getMessage() . "\n";
    echo "Please ensure MySQL is running and accessible.\n";
    exit(1);
}

echo "✓ Test environment ready\n\n";

// Test configuration
$testConfig = [
    'unit_tests' => [
        'enabled' => true,
        'path' => __DIR__ . '/Unit',
        'files' => [
            'CSVReportProcessorTest.php',
            'InventoryDataUpdaterTest.php',
            'StockAlertManagerTest.php'
        ]
    ],
    'integration_tests' => [
        'enabled' => true,
        'path' => __DIR__ . '/Integration',
        'files' => [
            'OzonStockReportsETLIntegrationTest.php',
            'OzonStockReportsErrorHandlingTest.php'
        ]
    ],
    'e2e_tests' => [
        'enabled' => true,
        'path' => __DIR__ . '/E2E',
        'files' => [
            'OzonStockReportsE2ETest.php',
            'OzonStockReportsPerformanceTest.php'
        ]
    ]
];

// Test results tracking
$testResults = [
    'total_tests' => 0,
    'passed_tests' => 0,
    'failed_tests' => 0,
    'skipped_tests' => 0,
    'errors' => [],
    'performance_metrics' => []
];

/**
 * Run a single test file
 */
function runTestFile($filePath, $testName) {
    global $testResults;
    
    echo "Running: {$testName}\n";
    echo str_repeat("-", 50) . "\n";
    
    $startTime = microtime(true);
    $startMemory = memory_get_usage(true);
    
    try {
        // Include the test file
        require_once $filePath;
        
        // Get the test class name from file name
        $className = pathinfo($filePath, PATHINFO_FILENAME);
        
        if (!class_exists($className)) {
            throw new Exception("Test class {$className} not found in {$filePath}");
        }
        
        // Create test suite
        $suite = new PHPUnit\Framework\TestSuite();
        $suite->addTestSuite($className);
        
        // Create test runner
        $runner = new PHPUnit\TextUI\TestRunner();
        
        // Capture output
        ob_start();
        $result = $runner->run($suite);
        $output = ob_get_clean();
        
        $endTime = microtime(true);
        $endMemory = memory_get_usage(true);
        
        // Process results
        $testCount = $result->count();
        $failureCount = $result->failureCount();
        $errorCount = $result->errorCount();
        $skippedCount = $result->skippedCount();
        $passedCount = $testCount - $failureCount - $errorCount - $skippedCount;
        
        $testResults['total_tests'] += $testCount;
        $testResults['passed_tests'] += $passedCount;
        $testResults['failed_tests'] += $failureCount;
        $testResults['skipped_tests'] += $skippedCount;
        
        // Store performance metrics
        $testResults['performance_metrics'][$testName] = [
            'execution_time' => round($endTime - $startTime, 3),
            'memory_used' => round(($endMemory - $startMemory) / 1024 / 1024, 2),
            'tests_run' => $testCount
        ];
        
        // Display results
        echo "Tests: {$testCount}, ";
        echo "Passed: {$passedCount}, ";
        echo "Failed: {$failureCount}, ";
        echo "Errors: {$errorCount}, ";
        echo "Skipped: {$skippedCount}\n";
        
        if ($failureCount > 0 || $errorCount > 0) {
            echo "\nFailures/Errors:\n";
            foreach ($result->failures() as $failure) {
                echo "- " . $failure->getTestName() . ": " . $failure->getExceptionMessage() . "\n";
                $testResults['errors'][] = [
                    'test' => $testName,
                    'method' => $failure->getTestName(),
                    'message' => $failure->getExceptionMessage()
                ];
            }
            foreach ($result->errors() as $error) {
                echo "- " . $error->getTestName() . ": " . $error->getExceptionMessage() . "\n";
                $testResults['errors'][] = [
                    'test' => $testName,
                    'method' => $error->getTestName(),
                    'message' => $error->getExceptionMessage()
                ];
            }
        }
        
        echo "Time: " . round($endTime - $startTime, 3) . "s, ";
        echo "Memory: " . round(($endMemory - $startMemory) / 1024 / 1024, 2) . "MB\n";
        
        if ($passedCount === $testCount) {
            echo "✓ ALL TESTS PASSED\n";
        } else {
            echo "✗ SOME TESTS FAILED\n";
        }
        
    } catch (Exception $e) {
        $testResults['errors'][] = [
            'test' => $testName,
            'method' => 'setup',
            'message' => $e->getMessage()
        ];
        echo "✗ ERROR: " . $e->getMessage() . "\n";
    }
    
    echo "\n";
}

/**
 * Run test suite
 */
function runTestSuite($suiteName, $config) {
    echo "=================================================================\n";
    echo strtoupper($suiteName) . " TESTS\n";
    echo "=================================================================\n\n";
    
    if (!$config['enabled']) {
        echo "Suite disabled - skipping\n\n";
        return;
    }
    
    foreach ($config['files'] as $file) {
        $filePath = $config['path'] . '/' . $file;
        
        if (!file_exists($filePath)) {
            echo "✗ Test file not found: {$filePath}\n\n";
            continue;
        }
        
        $testName = pathinfo($file, PATHINFO_FILENAME);
        runTestFile($filePath, $testName);
    }
}

// Main execution
echo "Starting test execution...\n\n";

$overallStartTime = microtime(true);

// Run all test suites
runTestSuite('Unit', $testConfig['unit_tests']);
runTestSuite('Integration', $testConfig['integration_tests']);
runTestSuite('End-to-End', $testConfig['e2e_tests']);

$overallEndTime = microtime(true);

// Final results summary
echo "=================================================================\n";
echo "FINAL TEST RESULTS SUMMARY\n";
echo "=================================================================\n\n";

echo "Overall Statistics:\n";
echo "- Total Tests: {$testResults['total_tests']}\n";
echo "- Passed: {$testResults['passed_tests']}\n";
echo "- Failed: {$testResults['failed_tests']}\n";
echo "- Skipped: {$testResults['skipped_tests']}\n";
echo "- Success Rate: " . round(($testResults['passed_tests'] / max($testResults['total_tests'], 1)) * 100, 2) . "%\n";
echo "- Total Execution Time: " . round($overallEndTime - $overallStartTime, 3) . "s\n\n";

if (!empty($testResults['performance_metrics'])) {
    echo "Performance Metrics:\n";
    foreach ($testResults['performance_metrics'] as $testName => $metrics) {
        echo "- {$testName}: {$metrics['execution_time']}s, {$metrics['memory_used']}MB, {$metrics['tests_run']} tests\n";
    }
    echo "\n";
}

if (!empty($testResults['errors'])) {
    echo "Errors Summary:\n";
    foreach ($testResults['errors'] as $error) {
        echo "- {$error['test']}::{$error['method']}: {$error['message']}\n";
    }
    echo "\n";
}

// Overall result
if ($testResults['failed_tests'] === 0 && empty($testResults['errors'])) {
    echo "🎉 ALL TESTS PASSED! The Ozon Stock Reports system is ready for production.\n";
    exit(0);
} else {
    echo "❌ SOME TESTS FAILED. Please review the errors above before deploying.\n";
    exit(1);
}