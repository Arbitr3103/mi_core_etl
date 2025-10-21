<?php
/**
 * Comprehensive MDM Test Runner
 * 
 * Runs all MDM system tests and generates a detailed report.
 * This script can be used locally or in CI/CD pipelines.
 */

require_once __DIR__ . '/tests/bootstrap.php';

class MDMTestRunner {
    private $results = [];
    private $startTime;
    private $totalTests = 0;
    private $passedTests = 0;
    private $failedTests = 0;
    
    public function __construct() {
        $this->startTime = microtime(true);
    }
    
    /**
     * Run all test suites
     */
    public function runAllTests() {
        echo "=================================================\n";
        echo "MDM System - Comprehensive Test Suite\n";
        echo "=================================================\n\n";
        
        // Run test suites
        $this->runTestSuite('Unit Tests', [
            'tests/SafeSyncEngineTest.php',
            'tests/DataTypeNormalizerTest.php',
            'tests/FallbackDataProviderTest.php'
        ]);
        
        $this->runTestSuite('Integration Tests', [
            'tests/SyncIntegrationTest.php'
        ]);
        
        $this->runTestSuite('Regression Tests', [
            'tests/RegressionTest.php'
        ]);
        
        $this->runTestSuite('Data Type Compatibility Tests', [
            'tests/DataTypeCompatibilityTest.php'
        ]);
        
        $this->runTestSuite('SQL Query Tests', [
            'tests/test_sql_queries_comprehensive.php'
        ]);
        
        $this->runTestSuite('Product Sync Tests', [
            'tests/test_product_sync_comprehensive.php'
        ]);
        
        // Generate report
        $this->generateReport();
    }
    
    /**
     * Run a test suite
     */
    private function runTestSuite($suiteName, $testFiles) {
        echo "\n" . str_repeat("=", 50) . "\n";
        echo "Running: {$suiteName}\n";
        echo str_repeat("=", 50) . "\n\n";
        
        $suiteResults = [
            'name' => $suiteName,
            'tests' => [],
            'passed' => 0,
            'failed' => 0,
            'errors' => []
        ];
        
        foreach ($testFiles as $testFile) {
            if (!file_exists($testFile)) {
                echo "⚠️  Test file not found: {$testFile}\n";
                continue;
            }
            
            echo "Running: " . basename($testFile) . "... ";
            
            $result = $this->runTestFile($testFile);
            
            $suiteResults['tests'][] = $result;
            
            if ($result['status'] === 'passed') {
                echo "✅ PASSED\n";
                $suiteResults['passed']++;
                $this->passedTests++;
            } else {
                echo "❌ FAILED\n";
                $suiteResults['failed']++;
                $this->failedTests++;
                
                if (!empty($result['error'])) {
                    echo "   Error: {$result['error']}\n";
                    $suiteResults['errors'][] = $result['error'];
                }
            }
            
            $this->totalTests++;
        }
        
        $this->results[] = $suiteResults;
        
        echo "\nSuite Summary: ";
        echo "{$suiteResults['passed']} passed, {$suiteResults['failed']} failed\n";
    }
    
    /**
     * Run a single test file
     */
    private function runTestFile($testFile) {
        $result = [
            'file' => basename($testFile),
            'status' => 'passed',
            'error' => null,
            'duration' => 0
        ];
        
        $startTime = microtime(true);
        
        try {
            // Check if PHPUnit is available
            if (class_exists('PHPUnit\Framework\TestCase')) {
                // Run with PHPUnit
                $output = [];
                $returnCode = 0;
                
                exec("php vendor/bin/phpunit {$testFile} 2>&1", $output, $returnCode);
                
                if ($returnCode !== 0) {
                    $result['status'] = 'failed';
                    $result['error'] = implode("\n", $output);
                }
            } else {
                // Run directly
                ob_start();
                require $testFile;
                $output = ob_get_clean();
                
                // Check for errors in output
                if (strpos($output, 'FAILED') !== false || 
                    strpos($output, 'Error') !== false) {
                    $result['status'] = 'failed';
                    $result['error'] = $output;
                }
            }
        } catch (Exception $e) {
            $result['status'] = 'failed';
            $result['error'] = $e->getMessage();
        }
        
        $result['duration'] = microtime(true) - $startTime;
        
        return $result;
    }
    
    /**
     * Generate comprehensive test report
     */
    private function generateReport() {
        $duration = microtime(true) - $this->startTime;
        
        echo "\n\n";
        echo str_repeat("=", 50) . "\n";
        echo "TEST SUMMARY\n";
        echo str_repeat("=", 50) . "\n\n";
        
        echo "Total Tests: {$this->totalTests}\n";
        echo "Passed: {$this->passedTests} ✅\n";
        echo "Failed: {$this->failedTests} ❌\n";
        echo "Success Rate: " . round(($this->passedTests / $this->totalTests) * 100, 2) . "%\n";
        echo "Duration: " . round($duration, 2) . " seconds\n\n";
        
        // Detailed results by suite
        echo str_repeat("-", 50) . "\n";
        echo "RESULTS BY SUITE\n";
        echo str_repeat("-", 50) . "\n\n";
        
        foreach ($this->results as $suite) {
            echo "{$suite['name']}:\n";
            echo "  Passed: {$suite['passed']}\n";
            echo "  Failed: {$suite['failed']}\n";
            
            if (!empty($suite['errors'])) {
                echo "  Errors:\n";
                foreach ($suite['errors'] as $error) {
                    echo "    - " . substr($error, 0, 100) . "...\n";
                }
            }
            echo "\n";
        }
        
        // Save report to file
        $this->saveReportToFile();
        
        // Exit with appropriate code
        exit($this->failedTests > 0 ? 1 : 0);
    }
    
    /**
     * Save report to JSON file
     */
    private function saveReportToFile() {
        $report = [
            'timestamp' => date('Y-m-d H:i:s'),
            'duration' => microtime(true) - $this->startTime,
            'total_tests' => $this->totalTests,
            'passed' => $this->passedTests,
            'failed' => $this->failedTests,
            'success_rate' => round(($this->passedTests / $this->totalTests) * 100, 2),
            'suites' => $this->results
        ];
        
        $reportFile = 'test_results_' . date('Ymd_His') . '.json';
        file_put_contents($reportFile, json_encode($report, JSON_PRETTY_PRINT));
        
        echo "Report saved to: {$reportFile}\n\n";
    }
}

// Run tests
$runner = new MDMTestRunner();
$runner->runAllTests();
