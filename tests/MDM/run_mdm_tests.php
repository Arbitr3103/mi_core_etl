<?php

/**
 * MDM System Test Runner
 * Runs all comprehensive tests for the MDM system
 * Requirements: 3.1, 3.2, 3.3, 3.4
 */

require_once __DIR__ . '/../bootstrap.php';

class MDMTestRunner
{
    private $testResults = [];
    private $startTime;
    private $testConfig;

    public function __construct()
    {
        $this->startTime = microtime(true);
        $this->loadTestConfig();
        $this->setupTestEnvironment();
    }

    /**
     * Run all MDM system tests
     */
    public function runAllTests()
    {
        echo "=== MDM System Comprehensive Test Suite ===\n";
        echo "Starting at: " . date('Y-m-d H:i:s') . "\n\n";

        // Run different test categories
        $this->runIntegrationTests();
        $this->runPerformanceTests();
        $this->runDataQualityTests();
        $this->runUITests();
        
        // Generate comprehensive report
        $this->generateComprehensiveReport();
        
        echo "\n=== Test Suite Completed ===\n";
        echo "Total execution time: " . round(microtime(true) - $this->startTime, 2) . " seconds\n";
    }

    /**
     * Run integration tests
     */
    private function runIntegrationTests()
    {
        echo "Running Integration Tests...\n";
        
        try {
            // Run PHPUnit integration tests
            $command = "cd " . __DIR__ . " && php -d memory_limit=512M ../../../vendor/bin/phpunit Integration/MDMIntegrationTest.php --testdox";
            $output = [];
            $returnCode = 0;
            
            exec($command, $output, $returnCode);
            
            $this->testResults['integration'] = [
                'status' => $returnCode === 0 ? 'PASSED' : 'FAILED',
                'output' => implode("\n", $output),
                'execution_time' => $this->getExecutionTime()
            ];
            
            echo $returnCode === 0 ? "✓ Integration tests PASSED\n" : "✗ Integration tests FAILED\n";
            
        } catch (Exception $e) {
            $this->testResults['integration'] = [
                'status' => 'ERROR',
                'error' => $e->getMessage(),
                'execution_time' => $this->getExecutionTime()
            ];
            echo "✗ Integration tests ERROR: " . $e->getMessage() . "\n";
        }
    }

    /**
     * Run performance tests
     */
    private function runPerformanceTests()
    {
        echo "Running Performance Tests...\n";
        
        try {
            // Run PHPUnit performance tests
            $command = "cd " . __DIR__ . " && php -d memory_limit=1G ../../../vendor/bin/phpunit Performance/MDMPerformanceTest.php --testdox";
            $output = [];
            $returnCode = 0;
            
            exec($command, $output, $returnCode);
            
            $this->testResults['performance'] = [
                'status' => $returnCode === 0 ? 'PASSED' : 'FAILED',
                'output' => implode("\n", $output),
                'execution_time' => $this->getExecutionTime()
            ];
            
            echo $returnCode === 0 ? "✓ Performance tests PASSED\n" : "✗ Performance tests FAILED\n";
            
        } catch (Exception $e) {
            $this->testResults['performance'] = [
                'status' => 'ERROR',
                'error' => $e->getMessage(),
                'execution_time' => $this->getExecutionTime()
            ];
            echo "✗ Performance tests ERROR: " . $e->getMessage() . "\n";
        }
    }

    /**
     * Run data quality tests
     */
    private function runDataQualityTests()
    {
        echo "Running Data Quality Tests...\n";
        
        try {
            // Run PHPUnit data quality tests
            $command = "cd " . __DIR__ . " && php -d memory_limit=512M ../../../vendor/bin/phpunit DataQuality/DataQualityValidationTest.php --testdox";
            $output = [];
            $returnCode = 0;
            
            exec($command, $output, $returnCode);
            
            $this->testResults['data_quality'] = [
                'status' => $returnCode === 0 ? 'PASSED' : 'FAILED',
                'output' => implode("\n", $output),
                'execution_time' => $this->getExecutionTime()
            ];
            
            echo $returnCode === 0 ? "✓ Data Quality tests PASSED\n" : "✗ Data Quality tests FAILED\n";
            
        } catch (Exception $e) {
            $this->testResults['data_quality'] = [
                'status' => 'ERROR',
                'error' => $e->getMessage(),
                'execution_time' => $this->getExecutionTime()
            ];
            echo "✗ Data Quality tests ERROR: " . $e->getMessage() . "\n";
        }
    }

    /**
     * Run UI automation tests
     */
    private function runUITests()
    {
        echo "Running UI Automation Tests...\n";
        
        try {
            // Check if Node.js and required packages are available
            if (!$this->checkUITestPrerequisites()) {
                echo "⚠ UI tests skipped - prerequisites not met\n";
                $this->testResults['ui'] = [
                    'status' => 'SKIPPED',
                    'reason' => 'Prerequisites not met (Node.js, Selenium WebDriver)',
                    'execution_time' => 0
                ];
                return;
            }
            
            // Run Node.js UI tests
            $command = "cd " . __DIR__ . " && node UI/MDMUIAutomationTest.js";
            $output = [];
            $returnCode = 0;
            
            exec($command, $output, $returnCode);
            
            $this->testResults['ui'] = [
                'status' => $returnCode === 0 ? 'PASSED' : 'FAILED',
                'output' => implode("\n", $output),
                'execution_time' => $this->getExecutionTime()
            ];
            
            echo $returnCode === 0 ? "✓ UI tests PASSED\n" : "✗ UI tests FAILED\n";
            
        } catch (Exception $e) {
            $this->testResults['ui'] = [
                'status' => 'ERROR',
                'error' => $e->getMessage(),
                'execution_time' => $this->getExecutionTime()
            ];
            echo "✗ UI tests ERROR: " . $e->getMessage() . "\n";
        }
    }

    /**
     * Check if UI test prerequisites are met
     */
    private function checkUITestPrerequisites()
    {
        // Check Node.js
        exec('node --version', $nodeOutput, $nodeReturn);
        if ($nodeReturn !== 0) {
            return false;
        }
        
        // Check if selenium-webdriver is installed
        exec('npm list selenium-webdriver', $seleniumOutput, $seleniumReturn);
        if ($seleniumReturn !== 0) {
            echo "Installing selenium-webdriver...\n";
            exec('npm install selenium-webdriver', $installOutput, $installReturn);
            if ($installReturn !== 0) {
                return false;
            }
        }
        
        return true;
    }

    /**
     * Generate comprehensive test report
     */
    private function generateComprehensiveReport()
    {
        $totalTests = count($this->testResults);
        $passedTests = count(array_filter($this->testResults, function($result) {
            return $result['status'] === 'PASSED';
        }));
        $failedTests = count(array_filter($this->testResults, function($result) {
            return $result['status'] === 'FAILED';
        }));
        $errorTests = count(array_filter($this->testResults, function($result) {
            return $result['status'] === 'ERROR';
        }));
        $skippedTests = count(array_filter($this->testResults, function($result) {
            return $result['status'] === 'SKIPPED';
        }));

        $report = [
            'test_suite' => 'MDM System Comprehensive Tests',
            'timestamp' => date('Y-m-d H:i:s'),
            'execution_time' => round(microtime(true) - $this->startTime, 2),
            'summary' => [
                'total_test_categories' => $totalTests,
                'passed' => $passedTests,
                'failed' => $failedTests,
                'errors' => $errorTests,
                'skipped' => $skippedTests,
                'success_rate' => $totalTests > 0 ? round(($passedTests / $totalTests) * 100, 2) : 0
            ],
            'test_results' => $this->testResults,
            'environment' => [
                'php_version' => PHP_VERSION,
                'memory_limit' => ini_get('memory_limit'),
                'max_execution_time' => ini_get('max_execution_time'),
                'database' => $this->testConfig['database'] ?? 'Unknown'
            ],
            'recommendations' => $this->generateRecommendations()
        ];

        // Save detailed report
        $reportPath = __DIR__ . '/../results/mdm_comprehensive_test_report_' . date('Y-m-d_H-i-s') . '.json';
        if (!is_dir(dirname($reportPath))) {
            mkdir(dirname($reportPath), 0755, true);
        }
        file_put_contents($reportPath, json_encode($report, JSON_PRETTY_PRINT));

        // Generate summary report
        $this->generateSummaryReport($report);

        echo "\nComprehensive test report saved: $reportPath\n";
    }

    /**
     * Generate summary report for quick overview
     */
    private function generateSummaryReport($report)
    {
        $summaryPath = __DIR__ . '/../results/mdm_test_summary_' . date('Y-m-d_H-i-s') . '.txt';
        
        $summary = "MDM System Test Summary\n";
        $summary .= "======================\n\n";
        $summary .= "Execution Date: " . $report['timestamp'] . "\n";
        $summary .= "Total Execution Time: " . $report['execution_time'] . " seconds\n\n";
        
        $summary .= "Test Results:\n";
        $summary .= "- Total Categories: " . $report['summary']['total_test_categories'] . "\n";
        $summary .= "- Passed: " . $report['summary']['passed'] . "\n";
        $summary .= "- Failed: " . $report['summary']['failed'] . "\n";
        $summary .= "- Errors: " . $report['summary']['errors'] . "\n";
        $summary .= "- Skipped: " . $report['summary']['skipped'] . "\n";
        $summary .= "- Success Rate: " . $report['summary']['success_rate'] . "%\n\n";
        
        $summary .= "Category Details:\n";
        foreach ($report['test_results'] as $category => $result) {
            $summary .= "- " . ucfirst(str_replace('_', ' ', $category)) . ": " . $result['status'] . "\n";
        }
        
        if (!empty($report['recommendations'])) {
            $summary .= "\nRecommendations:\n";
            foreach ($report['recommendations'] as $recommendation) {
                $summary .= "- " . $recommendation . "\n";
            }
        }
        
        file_put_contents($summaryPath, $summary);
        
        echo "\nTest summary saved: $summaryPath\n";
    }

    /**
     * Generate recommendations based on test results
     */
    private function generateRecommendations()
    {
        $recommendations = [];
        
        foreach ($this->testResults as $category => $result) {
            switch ($result['status']) {
                case 'FAILED':
                    $recommendations[] = "Review and fix issues in " . ucfirst(str_replace('_', ' ', $category)) . " tests";
                    break;
                case 'ERROR':
                    $recommendations[] = "Investigate technical issues preventing " . ucfirst(str_replace('_', ' ', $category)) . " tests from running";
                    break;
                case 'SKIPPED':
                    $recommendations[] = "Set up prerequisites for " . ucfirst(str_replace('_', ' ', $category)) . " tests";
                    break;
            }
        }
        
        // Performance-specific recommendations
        if (isset($this->testResults['performance']) && $this->testResults['performance']['status'] === 'FAILED') {
            $recommendations[] = "Consider database optimization and indexing improvements";
            $recommendations[] = "Review caching strategies for better performance";
        }
        
        // Data quality recommendations
        if (isset($this->testResults['data_quality']) && $this->testResults['data_quality']['status'] === 'FAILED') {
            $recommendations[] = "Implement additional data validation rules";
            $recommendations[] = "Review data cleansing processes";
        }
        
        return $recommendations;
    }

    /**
     * Load test configuration
     */
    private function loadTestConfig()
    {
        $configFile = __DIR__ . '/test_config.json';
        if (file_exists($configFile)) {
            $this->testConfig = json_decode(file_get_contents($configFile), true);
        } else {
            $this->testConfig = [
                'database' => $_ENV['TEST_DB_NAME'] ?? 'mdm_test',
                'timeout' => 300,
                'memory_limit' => '1G'
            ];
        }
    }

    /**
     * Setup test environment
     */
    private function setupTestEnvironment()
    {
        // Set memory limit for tests
        ini_set('memory_limit', $this->testConfig['memory_limit']);
        
        // Set execution time limit
        set_time_limit($this->testConfig['timeout']);
        
        // Ensure results directory exists
        $resultsDir = __DIR__ . '/../results';
        if (!is_dir($resultsDir)) {
            mkdir($resultsDir, 0755, true);
        }
    }

    /**
     * Get execution time for current test
     */
    private function getExecutionTime()
    {
        static $lastTime = null;
        $currentTime = microtime(true);
        
        if ($lastTime === null) {
            $lastTime = $this->startTime;
        }
        
        $executionTime = $currentTime - $lastTime;
        $lastTime = $currentTime;
        
        return round($executionTime, 2);
    }
}

// Run tests if called directly
if (basename(__FILE__) === basename($_SERVER['SCRIPT_NAME'])) {
    $testRunner = new MDMTestRunner();
    $testRunner->runAllTests();
}