<?php
/**
 * Comprehensive Test Runner for Ozon Analytics Integration
 * 
 * This script runs all tests for the Ozon Analytics system including:
 * - Integration tests
 * - Data validation tests
 * - Security tests
 * - Performance tests
 * - API endpoint tests
 * 
 * Usage:
 * php run_ozon_analytics_tests.php [--verbose] [--test-type=all|integration|validation|security|performance]
 * 
 * @version 1.0
 * @author Manhattan System
 */

class OzonAnalyticsTestRunner {
    
    private $verbose = false;
    private $testType = 'all';
    private $results = [];
    private $startTime;
    
    public function __construct($args = []) {
        $this->parseArguments($args);
        $this->startTime = microtime(true);
    }
    
    /**
     * Parse command line arguments
     */
    private function parseArguments($args) {
        foreach ($args as $arg) {
            if ($arg === '--verbose' || $arg === '-v') {
                $this->verbose = true;
            } elseif (strpos($arg, '--test-type=') === 0) {
                $this->testType = substr($arg, 12);
            }
        }
    }
    
    /**
     * Run all selected tests
     */
    public function runTests() {
        $this->log("🚀 Starting Ozon Analytics Test Suite", 'INFO');
        $this->log("Test Type: " . strtoupper($this->testType), 'INFO');
        $this->log("Verbose Mode: " . ($this->verbose ? 'ON' : 'OFF'), 'INFO');
        $this->log(str_repeat("=", 80), 'INFO');
        
        $testSuites = $this->getTestSuites();
        
        $totalPassed = 0;
        $totalFailed = 0;
        $totalTime = 0;
        
        foreach ($testSuites as $suiteName => $suiteConfig) {
            if ($this->shouldRunSuite($suiteName)) {
                $this->log("\n🔧 Running Test Suite: $suiteName", 'INFO');
                $this->log(str_repeat("-", 60), 'INFO');
                
                $suiteStartTime = microtime(true);
                $result = $this->runTestSuite($suiteConfig);
                $suiteEndTime = microtime(true);
                
                $suiteTime = $suiteEndTime - $suiteStartTime;
                $totalTime += $suiteTime;
                
                $this->results[$suiteName] = [
                    'passed' => $result['passed'],
                    'failed' => $result['failed'],
                    'time' => $suiteTime,
                    'success' => $result['failed'] === 0
                ];
                
                $totalPassed += $result['passed'];
                $totalFailed += $result['failed'];
                
                $status = $result['failed'] === 0 ? '✅ PASSED' : '❌ FAILED';
                $this->log("$status - {$result['passed']} passed, {$result['failed']} failed ({$suiteTime:.2f}s)", 
                          $result['failed'] === 0 ? 'SUCCESS' : 'ERROR');
            }
        }
        
        $this->printFinalSummary($totalPassed, $totalFailed, $totalTime);
        
        return $totalFailed === 0;
    }
    
    /**
     * Get available test suites
     */
    private function getTestSuites() {
        return [
            'integration' => [
                'name' => 'Integration Tests',
                'description' => 'Tests system integration and component interaction',
                'file' => 'tests/OzonAnalyticsIntegrationTest.php',
                'class' => 'OzonAnalyticsIntegrationTest',
                'critical' => true
            ],
            'validation' => [
                'name' => 'Data Validation Tests',
                'description' => 'Tests data processing accuracy and business logic',
                'file' => 'tests/OzonDataValidationTest.php',
                'class' => 'OzonDataValidationTest',
                'critical' => true
            ],
            'security' => [
                'name' => 'Security Tests',
                'description' => 'Tests security features and access control',
                'file' => 'test_ozon_security_integration.php',
                'class' => 'OzonSecurityTest',
                'critical' => true
            ],
            'api' => [
                'name' => 'API Endpoint Tests',
                'description' => 'Tests API endpoints and HTTP responses',
                'file' => 'test_ozon_analytics_api.php',
                'class' => null, // Script-based test
                'critical' => false
            ],
            'export' => [
                'name' => 'Export Functionality Tests',
                'description' => 'Tests data export features',
                'file' => 'test_ozon_export_functionality.php',
                'class' => null, // Script-based test
                'critical' => false
            ],
            'performance' => [
                'name' => 'Performance Tests',
                'description' => 'Tests system performance and scalability',
                'file' => 'test_ozon_performance.php',
                'class' => null, // Script-based test
                'critical' => false
            ]
        ];
    }
    
    /**
     * Check if a test suite should be run
     */
    private function shouldRunSuite($suiteName) {
        if ($this->testType === 'all') {
            return true;
        }
        
        return $suiteName === $this->testType;
    }
    
    /**
     * Run a single test suite
     */
    private function runTestSuite($suiteConfig) {
        if (!file_exists($suiteConfig['file'])) {
            $this->log("❌ Test file not found: {$suiteConfig['file']}", 'ERROR');
            return ['passed' => 0, 'failed' => 1];
        }
        
        try {
            if ($suiteConfig['class']) {
                // Class-based test
                require_once $suiteConfig['file'];
                
                if (!class_exists($suiteConfig['class'])) {
                    $this->log("❌ Test class not found: {$suiteConfig['class']}", 'ERROR');
                    return ['passed' => 0, 'failed' => 1];
                }
                
                $testInstance = new $suiteConfig['class']();
                
                if (!method_exists($testInstance, 'runAllTests')) {
                    $this->log("❌ runAllTests method not found in {$suiteConfig['class']}", 'ERROR');
                    return ['passed' => 0, 'failed' => 1];
                }
                
                // Capture output if not verbose
                if (!$this->verbose) {
                    ob_start();
                }
                
                $success = $testInstance->runAllTests();
                
                if (!$this->verbose) {
                    $output = ob_get_clean();
                    if (!$success) {
                        echo $output; // Show output only if tests failed
                    }
                }
                
                // Try to get detailed results if available
                if (method_exists($testInstance, 'getTestResults')) {
                    $testResults = $testInstance->getTestResults();
                    $passed = count(array_filter($testResults, function($result) {
                        return strpos($result, 'PASSED') !== false;
                    }));
                    $failed = count($testResults) - $passed;
                } else {
                    // Fallback to simple success/failure
                    $passed = $success ? 1 : 0;
                    $failed = $success ? 0 : 1;
                }
                
                return ['passed' => $passed, 'failed' => $failed];
                
            } else {
                // Script-based test
                if (!$this->verbose) {
                    ob_start();
                }
                
                $output = [];
                $returnCode = 0;
                exec("php {$suiteConfig['file']} 2>&1", $output, $returnCode);
                
                if (!$this->verbose) {
                    ob_get_clean();
                    if ($returnCode !== 0) {
                        echo implode("\n", $output) . "\n"; // Show output only if tests failed
                    }
                }
                
                $success = $returnCode === 0;
                
                // Try to parse output for detailed results
                $passed = 0;
                $failed = 0;
                
                foreach ($output as $line) {
                    if (preg_match('/(\d+)\s+passed,\s+(\d+)\s+failed/', $line, $matches)) {
                        $passed = (int)$matches[1];
                        $failed = (int)$matches[2];
                        break;
                    }
                }
                
                if ($passed === 0 && $failed === 0) {
                    // Fallback to simple success/failure
                    $passed = $success ? 1 : 0;
                    $failed = $success ? 0 : 1;
                }
                
                return ['passed' => $passed, 'failed' => $failed];
            }
            
        } catch (Exception $e) {
            $this->log("💥 Exception in test suite: " . $e->getMessage(), 'ERROR');
            return ['passed' => 0, 'failed' => 1];
        }
    }
    
    /**
     * Print final test summary
     */
    private function printFinalSummary($totalPassed, $totalFailed, $totalTime) {
        $totalTests = $totalPassed + $totalFailed;
        $successRate = $totalTests > 0 ? round(($totalPassed / $totalTests) * 100, 2) : 0;
        
        $this->log("\n" . str_repeat("=", 80), 'INFO');
        $this->log("🏁 FINAL TEST SUMMARY", 'INFO');
        $this->log(str_repeat("=", 80), 'INFO');
        
        $this->log("Total Test Suites: " . count($this->results), 'INFO');
        $this->log("Total Tests: $totalTests", 'INFO');
        $this->log("Passed: $totalPassed", 'SUCCESS');
        $this->log("Failed: $totalFailed", $totalFailed > 0 ? 'ERROR' : 'INFO');
        $this->log("Success Rate: {$successRate}%", $totalFailed === 0 ? 'SUCCESS' : 'ERROR');
        $this->log("Total Time: {$totalTime:.2f}s", 'INFO');
        
        $this->log("\nTest Suite Results:", 'INFO');
        foreach ($this->results as $suiteName => $result) {
            $status = $result['success'] ? '✅' : '❌';
            $this->log("$status $suiteName: {$result['passed']} passed, {$result['failed']} failed ({$result['time']:.2f}s)", 
                      $result['success'] ? 'SUCCESS' : 'ERROR');
        }
        
        // Check for critical failures
        $criticalFailures = [];
        $testSuites = $this->getTestSuites();
        
        foreach ($this->results as $suiteName => $result) {
            if (!$result['success'] && isset($testSuites[$suiteName]) && $testSuites[$suiteName]['critical']) {
                $criticalFailures[] = $suiteName;
            }
        }
        
        if (!empty($criticalFailures)) {
            $this->log("\n⚠️  CRITICAL FAILURES DETECTED:", 'ERROR');
            foreach ($criticalFailures as $suite) {
                $this->log("   - $suite", 'ERROR');
            }
            $this->log("These failures may prevent the system from working correctly in production.", 'ERROR');
        }
        
        if ($totalFailed === 0) {
            $this->log("\n🎉 ALL TESTS PASSED! Ozon Analytics integration is ready for production.", 'SUCCESS');
            $this->log("The system has been thoroughly tested and validated.", 'SUCCESS');
        } else {
            $this->log("\n❌ SOME TESTS FAILED. Please review and fix the issues before deployment.", 'ERROR');
            $this->log("Check the detailed output above for specific error information.", 'ERROR');
        }
        
        $this->printRecommendations($totalFailed, $criticalFailures);
    }
    
    /**
     * Print recommendations based on test results
     */
    private function printRecommendations($totalFailed, $criticalFailures) {
        $this->log("\n📋 RECOMMENDATIONS:", 'INFO');
        
        if ($totalFailed === 0) {
            $this->log("✅ All tests passed. The system is ready for deployment.", 'SUCCESS');
            $this->log("✅ Consider running performance tests under production load.", 'INFO');
            $this->log("✅ Set up monitoring and alerting for production environment.", 'INFO');
        } else {
            if (!empty($criticalFailures)) {
                $this->log("🔴 Fix critical failures before deployment:", 'ERROR');
                foreach ($criticalFailures as $suite) {
                    $this->log("   - Review and fix issues in $suite", 'ERROR');
                }
            }
            
            $this->log("🔧 General recommendations:", 'INFO');
            $this->log("   - Review failed test output for specific issues", 'INFO');
            $this->log("   - Check database connectivity and schema", 'INFO');
            $this->log("   - Verify API credentials and permissions", 'INFO');
            $this->log("   - Ensure all required files are present", 'INFO');
            $this->log("   - Run tests again after fixing issues", 'INFO');
        }
        
        $this->log("\n📚 Additional Resources:", 'INFO');
        $this->log("   - User Guide: docs/OZON_ANALYTICS_USER_GUIDE.md", 'INFO');
        $this->log("   - Technical Guide: docs/OZON_ANALYTICS_TECHNICAL_GUIDE.md", 'INFO');
        $this->log("   - Deployment Guide: docs/OZON_ANALYTICS_DEPLOYMENT_GUIDE.md", 'INFO');
        $this->log("   - Support: support@manhattan-system.ru", 'INFO');
    }
    
    /**
     * Logging helper
     */
    private function log($message, $level = 'INFO') {
        $timestamp = date('Y-m-d H:i:s');
        $colors = [
            'INFO' => "\033[0m",      // Default
            'SUCCESS' => "\033[32m",  // Green
            'WARNING' => "\033[33m",  // Yellow
            'ERROR' => "\033[31m"     // Red
        ];
        
        $color = $colors[$level] ?? $colors['INFO'];
        $reset = "\033[0m";
        
        echo "{$color}[{$timestamp}] {$message}{$reset}\n";
    }
}

/**
 * Display usage information
 */
function showUsage() {
    echo "Ozon Analytics Test Runner\n";
    echo "Usage: php run_ozon_analytics_tests.php [options]\n\n";
    echo "Options:\n";
    echo "  --verbose, -v              Show detailed test output\n";
    echo "  --test-type=TYPE           Run specific test type (all|integration|validation|security|api|export|performance)\n";
    echo "  --help, -h                 Show this help message\n\n";
    echo "Examples:\n";
    echo "  php run_ozon_analytics_tests.php                    # Run all tests\n";
    echo "  php run_ozon_analytics_tests.php --verbose          # Run all tests with detailed output\n";
    echo "  php run_ozon_analytics_tests.php --test-type=integration  # Run only integration tests\n";
    echo "  php run_ozon_analytics_tests.php --test-type=validation   # Run only data validation tests\n\n";
}

// Main execution
if (basename(__FILE__) === basename($_SERVER['SCRIPT_NAME'])) {
    $args = array_slice($argv, 1);
    
    // Check for help flag
    if (in_array('--help', $args) || in_array('-h', $args)) {
        showUsage();
        exit(0);
    }
    
    try {
        $runner = new OzonAnalyticsTestRunner($args);
        $success = $runner->runTests();
        exit($success ? 0 : 1);
    } catch (Exception $e) {
        echo "❌ Test runner failed: " . $e->getMessage() . "\n";
        exit(1);
    }
}