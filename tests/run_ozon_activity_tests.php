<?php
/**
 * Test Runner for Ozon Activity and Warehouse Analysis Tests
 * Task 6: Тестирование и валидация
 * 
 * Runs all tests related to product activity logic, warehouse analysis,
 * dashboard filtering, and visual display validation
 */

// Only include database config to avoid function conflicts
if (!function_exists('getDatabaseConnection')) {
    require_once __DIR__ . '/../config/database_postgresql.php';
}

// Test configuration
$testConfig = [
    'database_required' => true,
    'api_required' => true,
    'frontend_required' => false,
    'performance_tests' => true,
    'visual_tests' => true
];

// Color output functions
function colorOutput($text, $color = 'white') {
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
    
    return $colors[$color] . $text . $colors['reset'];
}

function printHeader($text) {
    echo "\n" . colorOutput(str_repeat('=', 80), 'cyan') . "\n";
    echo colorOutput($text, 'cyan') . "\n";
    echo colorOutput(str_repeat('=', 80), 'cyan') . "\n\n";
}

function printSubHeader($text) {
    echo "\n" . colorOutput(str_repeat('-', 60), 'blue') . "\n";
    echo colorOutput($text, 'blue') . "\n";
    echo colorOutput(str_repeat('-', 60), 'blue') . "\n\n";
}

function printSuccess($text) {
    echo colorOutput("✅ " . $text, 'green') . "\n";
}

function printError($text) {
    echo colorOutput("❌ " . $text, 'red') . "\n";
}

function printWarning($text) {
    echo colorOutput("⚠️  " . $text, 'yellow') . "\n";
}

function printInfo($text) {
    echo colorOutput("ℹ️  " . $text, 'blue') . "\n";
}

// Test execution functions
function runTestClass($className, $testFile) {
    printSubHeader("Running {$className}");
    
    if (!file_exists($testFile)) {
        printError("Test file not found: {$testFile}");
        return false;
    }
    
    try {
        require_once $testFile;
        
        if (!class_exists($className)) {
            printError("Test class {$className} not found in {$testFile}");
            return false;
        }
        
        $testClass = new $className();
        $reflection = new ReflectionClass($className);
        $methods = $reflection->getMethods(ReflectionMethod::IS_PUBLIC);
        
        $testMethods = array_filter($methods, function($method) {
            return strpos($method->getName(), 'test') === 0;
        });
        
        $passed = 0;
        $failed = 0;
        $errors = [];
        
        // Setup
        if (method_exists($testClass, 'setUp')) {
            try {
                $testClass->setUp();
                printInfo("Setup completed");
            } catch (Exception $e) {
                printError("Setup failed: " . $e->getMessage());
                return false;
            }
        }
        
        // Run tests
        foreach ($testMethods as $method) {
            $methodName = $method->getName();
            echo "  Running {$methodName}... ";
            
            try {
                $startTime = microtime(true);
                $testClass->$methodName();
                $executionTime = round((microtime(true) - $startTime) * 1000, 2);
                
                echo colorOutput("PASS", 'green') . " ({$executionTime}ms)\n";
                $passed++;
            } catch (Exception $e) {
                echo colorOutput("FAIL", 'red') . "\n";
                $failed++;
                $errors[] = [
                    'method' => $methodName,
                    'error' => $e->getMessage(),
                    'file' => $e->getFile(),
                    'line' => $e->getLine()
                ];
                
                echo "    " . colorOutput("Error: " . $e->getMessage(), 'red') . "\n";
            }
        }
        
        // Teardown
        if (method_exists($testClass, 'tearDown')) {
            try {
                $testClass->tearDown();
                printInfo("Teardown completed");
            } catch (Exception $e) {
                printWarning("Teardown warning: " . $e->getMessage());
            }
        }
        
        // Summary
        echo "\n";
        printInfo("Tests passed: {$passed}");
        if ($failed > 0) {
            printError("Tests failed: {$failed}");
        }
        
        return $failed === 0;
        
    } catch (Exception $e) {
        printError("Failed to run test class {$className}: " . $e->getMessage());
        return false;
    }
}

function checkPrerequisites() {
    printSubHeader("Checking Prerequisites");
    
    $allGood = true;
    
    // Check PHP version
    if (version_compare(PHP_VERSION, '7.4.0', '<')) {
        printError("PHP 7.4+ required, found " . PHP_VERSION);
        $allGood = false;
    } else {
        printSuccess("PHP version: " . PHP_VERSION);
    }
    
    // Check database connection
    try {
        $pdo = getDatabaseConnection();
        if ($pdo) {
            printSuccess("Database connection: OK");
            
            // Check if inventory table exists
            $stmt = $pdo->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_name = 'inventory'");
            if ($stmt && $stmt->fetchColumn() > 0) {
                printSuccess("Inventory table: EXISTS");
            } else {
                printWarning("Inventory table: NOT FOUND (some tests may fail)");
            }
        } else {
            printError("Database connection: FAILED");
            $allGood = false;
        }
    } catch (Exception $e) {
        printError("Database connection error: " . $e->getMessage());
        $allGood = false;
    }
    
    // Check required extensions
    $requiredExtensions = ['pdo', 'pdo_pgsql', 'mbstring', 'json'];
    foreach ($requiredExtensions as $ext) {
        if (extension_loaded($ext)) {
            printSuccess("Extension {$ext}: LOADED");
        } else {
            printError("Extension {$ext}: NOT LOADED");
            $allGood = false;
        }
    }
    
    return $allGood;
}

function generateTestReport($results) {
    printSubHeader("Test Report Generation");
    
    $reportData = [
        'timestamp' => date('Y-m-d H:i:s'),
        'total_test_classes' => count($results),
        'passed_classes' => count(array_filter($results)),
        'failed_classes' => count(array_filter($results, function($r) { return !$r; })),
        'results' => $results,
        'environment' => [
            'php_version' => PHP_VERSION,
            'os' => PHP_OS,
            'memory_limit' => ini_get('memory_limit'),
            'max_execution_time' => ini_get('max_execution_time')
        ]
    ];
    
    $reportFile = __DIR__ . '/results/ozon_activity_test_report_' . date('Y-m-d_H-i-s') . '.json';
    
    // Create results directory if it doesn't exist
    $resultsDir = dirname($reportFile);
    if (!is_dir($resultsDir)) {
        mkdir($resultsDir, 0755, true);
    }
    
    if (file_put_contents($reportFile, json_encode($reportData, JSON_PRETTY_PRINT))) {
        printSuccess("Test report saved: {$reportFile}");
    } else {
        printError("Failed to save test report");
    }
    
    return $reportData;
}

function printFinalSummary($reportData) {
    printHeader("FINAL TEST SUMMARY");
    
    echo "Test Execution Time: " . date('Y-m-d H:i:s') . "\n";
    echo "Total Test Classes: " . $reportData['total_test_classes'] . "\n";
    echo "Passed Classes: " . colorOutput($reportData['passed_classes'], 'green') . "\n";
    echo "Failed Classes: " . colorOutput($reportData['failed_classes'], $reportData['failed_classes'] > 0 ? 'red' : 'green') . "\n";
    
    $successRate = $reportData['total_test_classes'] > 0 ? 
        round(($reportData['passed_classes'] / $reportData['total_test_classes']) * 100, 1) : 0;
    
    echo "Success Rate: " . colorOutput($successRate . '%', $successRate >= 80 ? 'green' : ($successRate >= 60 ? 'yellow' : 'red')) . "\n";
    
    echo "\nEnvironment:\n";
    echo "  PHP Version: " . $reportData['environment']['php_version'] . "\n";
    echo "  Operating System: " . $reportData['environment']['os'] . "\n";
    echo "  Memory Limit: " . $reportData['environment']['memory_limit'] . "\n";
    
    if ($reportData['failed_classes'] === 0) {
        echo "\n" . colorOutput("🎉 ALL TESTS PASSED! 🎉", 'green') . "\n";
        echo colorOutput("The Ozon warehouse stock reports system is ready for production.", 'green') . "\n";
    } else {
        echo "\n" . colorOutput("⚠️  SOME TESTS FAILED", 'yellow') . "\n";
        echo colorOutput("Please review the failed tests before deploying to production.", 'yellow') . "\n";
    }
}

// Main execution
function main() {
    printHeader("OZON WAREHOUSE STOCK REPORTS - TESTING AND VALIDATION");
    printInfo("Task 6: Тестирование и валидация");
    printInfo("Testing product activity logic, warehouse analysis, dashboard filtering, and visual display");
    
    // Check prerequisites
    if (!checkPrerequisites()) {
        printError("Prerequisites check failed. Please fix the issues above before running tests.");
        exit(1);
    }
    
    // Define test classes to run
    $testClasses = [
        [
            'name' => 'ProductActivityTest',
            'file' => __DIR__ . '/Unit/ProductActivityTest.php',
            'description' => 'Task 6.1: Tests product activity logic and total stock calculations'
        ],
        [
            'name' => 'WarehouseAnalysisTest',
            'file' => __DIR__ . '/Unit/WarehouseAnalysisTest.php',
            'description' => 'Task 6.2: Tests warehouse analysis, critical levels, and replenishment priorities'
        ],
        [
            'name' => 'DashboardFilteringTest',
            'file' => __DIR__ . '/Integration/DashboardFilteringTest.php',
            'description' => 'Task 6.3: Tests dashboard filtering functionality and statistics updates'
        ],
        [
            'name' => 'VisualDisplayValidationTest',
            'file' => __DIR__ . '/E2E/VisualDisplayValidationTest.php',
            'description' => 'Task 6.4: Tests visual display, color coding, and interface usability'
        ]
    ];
    
    $results = [];
    $startTime = microtime(true);
    
    // Run each test class
    foreach ($testClasses as $testClass) {
        printHeader($testClass['description']);
        printInfo("Running: " . $testClass['name']);
        
        $result = runTestClass($testClass['name'], $testClass['file']);
        $results[$testClass['name']] = $result;
        
        if ($result) {
            printSuccess("✅ {$testClass['name']} completed successfully");
        } else {
            printError("❌ {$testClass['name']} failed");
        }
    }
    
    $totalTime = round(microtime(true) - $startTime, 2);
    
    // Generate report
    $reportData = generateTestReport($results);
    $reportData['total_execution_time'] = $totalTime;
    
    // Print final summary
    printFinalSummary($reportData);
    
    echo "\nTotal Execution Time: " . colorOutput($totalTime . ' seconds', 'cyan') . "\n";
    
    // Exit with appropriate code
    $allPassed = !in_array(false, $results, true);
    exit($allPassed ? 0 : 1);
}

// Simple assertion functions for tests
function assertEquals($expected, $actual, $message = '') {
    if ($expected !== $actual) {
        throw new Exception($message ?: "Expected '{$expected}', got '{$actual}'");
    }
}

function assertNotEquals($expected, $actual, $message = '') {
    if ($expected === $actual) {
        throw new Exception($message ?: "Expected not '{$expected}', but got '{$actual}'");
    }
}

function assertTrue($condition, $message = '') {
    if (!$condition) {
        throw new Exception($message ?: "Expected true, got false");
    }
}

function assertFalse($condition, $message = '') {
    if ($condition) {
        throw new Exception($message ?: "Expected false, got true");
    }
}

function assertGreaterThan($expected, $actual, $message = '') {
    if ($actual <= $expected) {
        throw new Exception($message ?: "Expected '{$actual}' to be greater than '{$expected}'");
    }
}

function assertGreaterThanOrEqual($expected, $actual, $message = '') {
    if ($actual < $expected) {
        throw new Exception($message ?: "Expected '{$actual}' to be greater than or equal to '{$expected}'");
    }
}

function assertLessThan($expected, $actual, $message = '') {
    if ($actual >= $expected) {
        throw new Exception($message ?: "Expected '{$actual}' to be less than '{$expected}'");
    }
}

function assertLessThanOrEqual($expected, $actual, $message = '') {
    if ($actual > $expected) {
        throw new Exception($message ?: "Expected '{$actual}' to be less than or equal to '{$expected}'");
    }
}

function assertArrayHasKey($key, $array, $message = '') {
    if (!is_array($array) || !array_key_exists($key, $array)) {
        throw new Exception($message ?: "Array does not have key '{$key}'");
    }
}

function assertNotEmpty($value, $message = '') {
    if (empty($value)) {
        throw new Exception($message ?: "Expected non-empty value");
    }
}

function assertEmpty($value, $message = '') {
    if (!empty($value)) {
        throw new Exception($message ?: "Expected empty value");
    }
}

function assertContains($needle, $haystack, $message = '') {
    if (is_array($haystack)) {
        if (!in_array($needle, $haystack)) {
            throw new Exception($message ?: "Array does not contain '{$needle}'");
        }
    } else {
        if (strpos($haystack, $needle) === false) {
            throw new Exception($message ?: "String does not contain '{$needle}'");
        }
    }
}

function assertNotContains($needle, $haystack, $message = '') {
    if (is_array($haystack)) {
        if (in_array($needle, $haystack)) {
            throw new Exception($message ?: "Array should not contain '{$needle}'");
        }
    } else {
        if (strpos($haystack, $needle) !== false) {
            throw new Exception($message ?: "String should not contain '{$needle}'");
        }
    }
}

function assertCount($expectedCount, $array, $message = '') {
    $actualCount = count($array);
    if ($actualCount !== $expectedCount) {
        throw new Exception($message ?: "Expected count {$expectedCount}, got {$actualCount}");
    }
}

function assertNull($value, $message = '') {
    if ($value !== null) {
        throw new Exception($message ?: "Expected null value");
    }
}

function assertNotNull($value, $message = '') {
    if ($value === null) {
        throw new Exception($message ?: "Expected non-null value");
    }
}

// Run the tests
if (php_sapi_name() === 'cli') {
    main();
} else {
    echo "This script must be run from the command line.\n";
    exit(1);
}
?>