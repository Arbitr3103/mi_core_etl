<?php
/**
 * Detailed Inventory API Test Runner
 * 
 * Runs the detailed inventory API tests and reports results.
 * 
 * Task: 1.4 Write backend API tests
 */

// Include bootstrap
require_once __DIR__ . '/bootstrap.php';

// Set up error handling
set_error_handler(function($severity, $message, $file, $line) {
    if (!(error_reporting() & $severity)) {
        return false;
    }
    throw new ErrorException($message, 0, $severity, $file, $line);
});

echo "=== Detailed Inventory API Test Suite ===\n\n";

// Run Task 4.3 Integration Tests first
echo "Running Task 4.3 Integration Tests...\n";
require_once __DIR__ . '/test_api_integration_task_4_3.php';

$task43Tester = new APIIntegrationTestTask43();
$task43Success = $task43Tester->runAllTests();

echo "\n" . str_repeat("=", 50) . "\n\n";

// Check if PHPUnit is available
$phpunitPaths = [
    __DIR__ . '/../vendor/bin/phpunit',
    '/usr/local/bin/phpunit',
    '/usr/bin/phpunit'
];

$phpunitPath = null;
foreach ($phpunitPaths as $path) {
    if (file_exists($path)) {
        $phpunitPath = $path;
        break;
    }
}

if ($phpunitPath) {
    echo "Running tests with PHPUnit...\n";
    
    $testFile = __DIR__ . '/Unit/DetailedInventoryAPITest.php';
    $command = "$phpunitPath --bootstrap " . __DIR__ . "/bootstrap.php $testFile";
    
    echo "Command: $command\n\n";
    
    $output = [];
    $returnCode = 0;
    exec($command . ' 2>&1', $output, $returnCode);
    
    echo implode("\n", $output) . "\n";
    
    if ($returnCode === 0) {
        echo "\n✅ All tests passed!\n";
    } else {
        echo "\n❌ Some tests failed. Return code: $returnCode\n";
    }
} else {
    echo "PHPUnit not found. Running manual test execution...\n\n";
    
    // Manual test execution without PHPUnit
    try {
        require_once __DIR__ . '/Unit/DetailedInventoryAPITest.php';
        
        $testClass = new DetailedInventoryAPITest();
        
        // Get all test methods
        $reflection = new ReflectionClass($testClass);
        $methods = $reflection->getMethods(ReflectionMethod::IS_PUBLIC);
        
        $testMethods = array_filter($methods, function($method) {
            return strpos($method->getName(), 'test') === 0;
        });
        
        $passed = 0;
        $failed = 0;
        $errors = [];
        
        echo "Running " . count($testMethods) . " tests...\n\n";
        
        foreach ($testMethods as $method) {
            $methodName = $method->getName();
            echo "Running $methodName... ";
            
            try {
                // Set up
                $testClass->setUp();
                
                // Run test
                $testClass->$methodName();
                
                // Tear down
                $testClass->tearDown();
                
                echo "✅ PASSED\n";
                $passed++;
                
            } catch (Exception $e) {
                echo "❌ FAILED\n";
                $failed++;
                $errors[] = "$methodName: " . $e->getMessage();
            }
        }
        
        echo "\n=== Test Results ===\n";
        echo "Passed: $passed\n";
        echo "Failed: $failed\n";
        echo "Total: " . ($passed + $failed) . "\n";
        
        if (!empty($errors)) {
            echo "\n=== Errors ===\n";
            foreach ($errors as $error) {
                echo "- $error\n";
            }
        }
        
        if ($failed === 0) {
            echo "\n✅ All tests passed!\n";
        } else {
            echo "\n❌ $failed test(s) failed.\n";
        }
        
    } catch (Exception $e) {
        echo "❌ Error running tests: " . $e->getMessage() . "\n";
        echo "Stack trace:\n" . $e->getTraceAsString() . "\n";
    }
}

echo "\n=== Test Suite Complete ===\n";

?>