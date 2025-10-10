<?php
/**
 * Test Coverage Verification Script
 * 
 * Verifies that all critical components have adequate test coverage
 * and identifies gaps in the test suite.
 */

class TestCoverageVerifier {
    private $components = [];
    private $testFiles = [];
    private $coverage = [];
    
    public function __construct() {
        $this->loadComponents();
        $this->loadTestFiles();
    }
    
    /**
     * Load all source components
     */
    private function loadComponents() {
        $sourceDir = __DIR__ . '/src';
        
        if (is_dir($sourceDir)) {
            $files = scandir($sourceDir);
            foreach ($files as $file) {
                if (pathinfo($file, PATHINFO_EXTENSION) === 'php') {
                    $this->components[] = $file;
                }
            }
        }
        
        // Add critical scripts
        $criticalScripts = [
            'sync-real-product-names-v2.php',
            'api/analytics.php',
            'api/analytics-enhanced.php',
            'api/sync-monitor.php',
            'api/sync-trigger.php',
            'api/data-quality-reports.php'
        ];
        
        foreach ($criticalScripts as $script) {
            if (file_exists(__DIR__ . '/' . $script)) {
                $this->components[] = $script;
            }
        }
    }
    
    /**
     * Load all test files
     */
    private function loadTestFiles() {
        $testDir = __DIR__ . '/tests';
        
        if (is_dir($testDir)) {
            $files = scandir($testDir);
            foreach ($files as $file) {
                if (pathinfo($file, PATHINFO_EXTENSION) === 'php' && 
                    (strpos($file, 'Test') !== false || strpos($file, 'test_') === 0)) {
                    $this->testFiles[] = $file;
                }
            }
        }
    }
    
    /**
     * Verify test coverage
     */
    public function verify() {
        echo "=================================================\n";
        echo "Test Coverage Verification\n";
        echo "=================================================\n\n";
        
        echo "Components found: " . count($this->components) . "\n";
        echo "Test files found: " . count($this->testFiles) . "\n\n";
        
        $this->checkComponentCoverage();
        $this->checkCriticalFunctionsCoverage();
        $this->generateReport();
    }
    
    /**
     * Check if each component has tests
     */
    private function checkComponentCoverage() {
        echo "Checking component coverage...\n\n";
        
        $covered = 0;
        $uncovered = [];
        
        foreach ($this->components as $component) {
            $componentName = pathinfo($component, PATHINFO_FILENAME);
            $hasTest = false;
            
            foreach ($this->testFiles as $testFile) {
                if (strpos($testFile, $componentName) !== false) {
                    $hasTest = true;
                    break;
                }
            }
            
            if ($hasTest) {
                echo "✅ {$component} - Has tests\n";
                $covered++;
                $this->coverage[$component] = true;
            } else {
                echo "⚠️  {$component} - No tests found\n";
                $uncovered[] = $component;
                $this->coverage[$component] = false;
            }
        }
        
        echo "\nCoverage: {$covered}/" . count($this->components) . " components\n";
        
        if (!empty($uncovered)) {
            echo "\nComponents without tests:\n";
            foreach ($uncovered as $component) {
                echo "  - {$component}\n";
            }
        }
        
        echo "\n";
    }
    
    /**
     * Check coverage of critical functions
     */
    private function checkCriticalFunctionsCoverage() {
        echo "Checking critical functions coverage...\n\n";
        
        $criticalFunctions = [
            'SafeSyncEngine' => [
                'syncProductNames',
                'findProductsNeedingSync',
                'processBatch',
                'getSyncStatistics'
            ],
            'DataTypeNormalizer' => [
                'normalizeId',
                'normalizeProduct',
                'compareIds',
                'validateNormalizedData',
                'normalizeAPIResponse'
            ],
            'FallbackDataProvider' => [
                'getProductName',
                'getCachedName',
                'cacheProductName',
                'clearStaleCache',
                'getCacheStatistics'
            ]
        ];
        
        foreach ($criticalFunctions as $class => $functions) {
            echo "{$class}:\n";
            
            $testFile = "tests/{$class}Test.php";
            if (!file_exists($testFile)) {
                echo "  ❌ Test file not found\n";
                continue;
            }
            
            $testContent = file_get_contents($testFile);
            
            foreach ($functions as $function) {
                $pattern = '/function\s+test.*' . preg_quote($function, '/') . '/i';
                if (preg_match($pattern, $testContent)) {
                    echo "  ✅ {$function} - Tested\n";
                } else {
                    echo "  ⚠️  {$function} - Not tested\n";
                }
            }
            
            echo "\n";
        }
    }
    
    /**
     * Generate coverage report
     */
    private function generateReport() {
        $totalComponents = count($this->components);
        $coveredComponents = count(array_filter($this->coverage));
        $coveragePercentage = ($coveredComponents / $totalComponents) * 100;
        
        echo str_repeat("=", 50) . "\n";
        echo "COVERAGE SUMMARY\n";
        echo str_repeat("=", 50) . "\n\n";
        
        echo "Total Components: {$totalComponents}\n";
        echo "Covered: {$coveredComponents}\n";
        echo "Uncovered: " . ($totalComponents - $coveredComponents) . "\n";
        echo "Coverage: " . round($coveragePercentage, 2) . "%\n\n";
        
        if ($coveragePercentage >= 90) {
            echo "✅ Excellent coverage! (>= 90%)\n";
        } elseif ($coveragePercentage >= 75) {
            echo "✅ Good coverage (>= 75%)\n";
        } elseif ($coveragePercentage >= 60) {
            echo "⚠️  Moderate coverage (>= 60%)\n";
            echo "   Consider adding more tests\n";
        } else {
            echo "❌ Low coverage (< 60%)\n";
            echo "   More tests needed!\n";
        }
        
        echo "\n";
        
        // Check for regression tests
        $this->checkRegressionTests();
        
        // Save report
        $this->saveReport($coveragePercentage);
    }
    
    /**
     * Check for regression tests
     */
    private function checkRegressionTests() {
        echo str_repeat("-", 50) . "\n";
        echo "REGRESSION TESTS\n";
        echo str_repeat("-", 50) . "\n\n";
        
        $regressionTestFile = 'tests/RegressionTest.php';
        
        if (!file_exists($regressionTestFile)) {
            echo "❌ Regression test file not found\n";
            return;
        }
        
        $content = file_get_contents($regressionTestFile);
        
        $knownIssues = [
            'SQL DISTINCT + ORDER BY' => 'testSQLDistinctOrderByNoError',
            'Data Type Incompatibility' => 'testDataTypeCompatibilityInJoins',
            'Placeholder Names' => 'testPlaceholderNameReplacement',
            'Transaction Rollback' => 'testSyncEngineTransactionRollback',
            'Batch Processing Timeout' => 'testBatchProcessingPreventsTimeout',
            'Cross-Reference Integrity' => 'testCrossReferenceTableIntegrity'
        ];
        
        foreach ($knownIssues as $issue => $testMethod) {
            if (strpos($content, $testMethod) !== false) {
                echo "✅ {$issue} - Covered\n";
            } else {
                echo "⚠️  {$issue} - Not covered\n";
            }
        }
        
        echo "\n";
    }
    
    /**
     * Save report to file
     */
    private function saveReport($coveragePercentage) {
        $report = [
            'timestamp' => date('Y-m-d H:i:s'),
            'total_components' => count($this->components),
            'covered_components' => count(array_filter($this->coverage)),
            'coverage_percentage' => round($coveragePercentage, 2),
            'components' => $this->coverage,
            'test_files' => $this->testFiles
        ];
        
        $reportFile = 'test_coverage_report_' . date('Ymd_His') . '.json';
        file_put_contents($reportFile, json_encode($report, JSON_PRETTY_PRINT));
        
        echo "Report saved to: {$reportFile}\n\n";
    }
}

// Run verification
$verifier = new TestCoverageVerifier();
$verifier->verify();
