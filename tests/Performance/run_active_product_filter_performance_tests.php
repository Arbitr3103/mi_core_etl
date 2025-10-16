<?php
/**
 * Performance Test Runner for Active Product Filter System
 * 
 * Executes all performance tests and generates comprehensive reports
 * Requirements: 2.4 - Performance optimization for active product filtering
 */

require_once __DIR__ . '/../../config.php';

class ActiveProductFilterPerformanceTestRunner
{
    private $results = [];
    private $startTime;
    private $testSuite = 'Active Product Filter Performance Tests';

    public function __construct()
    {
        $this->startTime = microtime(true);
    }

    /**
     * Run all performance tests
     */
    public function runAllTests(): void
    {
        echo "=== {$this->testSuite} ===\n";
        echo "Started at: " . date('Y-m-d H:i:s') . "\n\n";
        
        $this->runPHPUnitPerformanceTests();
        $this->runDashboardLoadTests();
        $this->runDatabaseBenchmarks();
        
        $this->generateUnifiedReport();
        $this->printExecutionSummary();
    }

    /**
     * Run PHPUnit performance tests
     */
    private function runPHPUnitPerformanceTests(): void
    {
        echo "1. 🧪 Running PHPUnit Performance Tests...\n";
        
        $testFile = __DIR__ . '/ActiveProductFilterPerformanceTest.php';
        
        if (!file_exists($testFile)) {
            echo "   ❌ PHPUnit test file not found: {$testFile}\n\n";
            return;
        }

        $startTime = microtime(true);
        
        // Check if PHPUnit is available
        $phpunitPath = $this->findPHPUnit();
        
        if ($phpunitPath) {
            $command = "{$phpunitPath} --testdox {$testFile}";
            $output = [];
            $returnCode = 0;
            
            exec($command . ' 2>&1', $output, $returnCode);
            
            $endTime = microtime(true);
            $executionTime = $endTime - $startTime;
            
            $this->results['phpunit_tests'] = [
                'execution_time' => $executionTime,
                'return_code' => $returnCode,
                'output' => implode("\n", $output),
                'success' => $returnCode === 0
            ];
            
            if ($returnCode === 0) {
                echo "   ✅ PHPUnit tests completed successfully\n";
                echo "   ⏱️ Execution time: " . number_format($executionTime, 2) . " seconds\n";
            } else {
                echo "   ❌ PHPUnit tests failed with return code: {$returnCode}\n";
                echo "   ⏱️ Execution time: " . number_format($executionTime, 2) . " seconds\n";
            }
        } else {
            echo "   ⚠️ PHPUnit not found, running simplified tests...\n";
            $this->runSimplifiedPerformanceTests();
        }
        
        echo "\n";
    }

    /**
     * Find PHPUnit executable
     */
    private function findPHPUnit(): ?string
    {
        $possiblePaths = [
            __DIR__ . '/../../vendor/bin/phpunit',
            'phpunit',
            '/usr/local/bin/phpunit',
            '/usr/bin/phpunit'
        ];
        
        foreach ($possiblePaths as $path) {
            if (is_executable($path) || (exec("which {$path} 2>/dev/null") !== '')) {
                return $path;
            }
        }
        
        return null;
    }

    /**
     * Run simplified performance tests when PHPUnit is not available
     */
    private function runSimplifiedPerformanceTests(): void
    {
        $startTime = microtime(true);
        
        try {
            // Basic API performance test
            $apiResults = $this->testBasicAPIPerformance();
            
            // Basic database performance test
            $dbResults = $this->testBasicDatabasePerformance();
            
            $endTime = microtime(true);
            
            $this->results['simplified_tests'] = [
                'execution_time' => $endTime - $startTime,
                'api_results' => $apiResults,
                'database_results' => $dbResults,
                'success' => true
            ];
            
            echo "   ✅ Simplified performance tests completed\n";
            echo "   ⏱️ Execution time: " . number_format($endTime - $startTime, 2) . " seconds\n";
            
        } catch (Exception $e) {
            echo "   ❌ Simplified tests failed: " . $e->getMessage() . "\n";
            
            $this->results['simplified_tests'] = [
                'execution_time' => microtime(true) - $startTime,
                'error' => $e->getMessage(),
                'success' => false
            ];
        }
    }

    private function testBasicAPIPerformance(): array
    {
        $baseUrl = 'http://localhost/api/inventory-analytics.php';
        $endpoints = [
            '?action=critical_stock&active_only=true',
            '?action=activity_stats'
        ];
        
        $results = [];
        
        foreach ($endpoints as $endpoint) {
            $times = [];
            
            for ($i = 0; $i < 5; $i++) {
                $startTime = microtime(true);
                
                $context = stream_context_create([
                    'http' => [
                        'method' => 'GET',
                        'timeout' => 10
                    ]
                ]);
                
                $response = @file_get_contents($baseUrl . $endpoint, false, $context);
                $endTime = microtime(true);
                
                $times[] = ($endTime - $startTime) * 1000;
            }
            
            $results[$endpoint] = [
                'avg_time' => array_sum($times) / count($times),
                'max_time' => max($times),
                'min_time' => min($times)
            ];
        }
        
        return $results;
    }

    private function testBasicDatabasePerformance(): array
    {
        try {
            $pdo = new PDO(
                "mysql:host=localhost;dbname=zuz_inventory;charset=utf8mb4",
                "root",
                "",
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
            );
            
            $queries = [
                'active_count' => "SELECT COUNT(*) FROM products WHERE is_active = 1",
                'active_with_stock' => "
                    SELECT COUNT(*) 
                    FROM products p 
                    JOIN inventory_data i ON p.id = i.product_id 
                    WHERE p.is_active = 1 AND i.stock_quantity > 0
                "
            ];
            
            $results = [];
            
            foreach ($queries as $name => $sql) {
                $times = [];
                
                for ($i = 0; $i < 5; $i++) {
                    $startTime = microtime(true);
                    
                    $stmt = $pdo->prepare($sql);
                    $stmt->execute();
                    $result = $stmt->fetchAll();
                    
                    $endTime = microtime(true);
                    $times[] = ($endTime - $startTime) * 1000;
                }
                
                $results[$name] = [
                    'avg_time' => array_sum($times) / count($times),
                    'max_time' => max($times),
                    'min_time' => min($times)
                ];
            }
            
            return $results;
            
        } catch (PDOException $e) {
            throw new Exception("Database connection failed: " . $e->getMessage());
        }
    }

    /**
     * Run dashboard load tests
     */
    private function runDashboardLoadTests(): void
    {
        echo "2. 🚀 Running Dashboard Load Tests...\n";
        
        $loadTestFile = __DIR__ . '/dashboard_load_test.php';
        
        if (!file_exists($loadTestFile)) {
            echo "   ❌ Load test file not found: {$loadTestFile}\n\n";
            return;
        }

        $startTime = microtime(true);
        
        // Capture output from load test
        ob_start();
        
        try {
            include $loadTestFile;
            $output = ob_get_contents();
            $success = true;
        } catch (Exception $e) {
            $output = "Load test failed: " . $e->getMessage();
            $success = false;
        } finally {
            ob_end_clean();
        }
        
        $endTime = microtime(true);
        $executionTime = $endTime - $startTime;
        
        $this->results['load_tests'] = [
            'execution_time' => $executionTime,
            'output' => $output,
            'success' => $success
        ];
        
        if ($success) {
            echo "   ✅ Dashboard load tests completed successfully\n";
        } else {
            echo "   ❌ Dashboard load tests failed\n";
        }
        
        echo "   ⏱️ Execution time: " . number_format($executionTime, 2) . " seconds\n\n";
    }

    /**
     * Run database benchmarks
     */
    private function runDatabaseBenchmarks(): void
    {
        echo "3. 🗄️ Running Database Performance Benchmarks...\n";
        
        $benchmarkFile = __DIR__ . '/database_performance_benchmark.php';
        
        if (!file_exists($benchmarkFile)) {
            echo "   ❌ Database benchmark file not found: {$benchmarkFile}\n\n";
            return;
        }

        $startTime = microtime(true);
        
        // Capture output from benchmark
        ob_start();
        
        try {
            include $benchmarkFile;
            $output = ob_get_contents();
            $success = true;
        } catch (Exception $e) {
            $output = "Database benchmark failed: " . $e->getMessage();
            $success = false;
        } finally {
            ob_end_clean();
        }
        
        $endTime = microtime(true);
        $executionTime = $endTime - $startTime;
        
        $this->results['database_benchmarks'] = [
            'execution_time' => $executionTime,
            'output' => $output,
            'success' => $success
        ];
        
        if ($success) {
            echo "   ✅ Database benchmarks completed successfully\n";
        } else {
            echo "   ❌ Database benchmarks failed\n";
        }
        
        echo "   ⏱️ Execution time: " . number_format($executionTime, 2) . " seconds\n\n";
    }

    /**
     * Generate unified performance report
     */
    private function generateUnifiedReport(): void
    {
        echo "4. 📊 Generating Unified Performance Report...\n";
        
        $reportData = [
            'test_suite' => $this->testSuite,
            'execution_timestamp' => date('Y-m-d H:i:s'),
            'total_execution_time' => microtime(true) - $this->startTime,
            'system_info' => $this->getSystemInfo(),
            'test_results' => $this->results,
            'summary' => $this->generateSummary()
        ];
        
        // Save JSON report
        $jsonReportFile = __DIR__ . '/../results/unified_performance_report_' . date('Ymd_His') . '.json';
        $this->saveReport($jsonReportFile, $reportData, 'json');
        
        // Save HTML report
        $htmlReportFile = __DIR__ . '/../results/unified_performance_report_' . date('Ymd_His') . '.html';
        $this->saveReport($htmlReportFile, $reportData, 'html');
        
        echo "   ✅ Reports generated:\n";
        echo "      📄 JSON: {$jsonReportFile}\n";
        echo "      🌐 HTML: {$htmlReportFile}\n\n";
    }

    private function getSystemInfo(): array
    {
        return [
            'php_version' => PHP_VERSION,
            'memory_limit' => ini_get('memory_limit'),
            'max_execution_time' => ini_get('max_execution_time'),
            'server_software' => $_SERVER['SERVER_SOFTWARE'] ?? 'CLI',
            'operating_system' => PHP_OS,
            'current_memory_usage' => memory_get_usage(true),
            'peak_memory_usage' => memory_get_peak_usage(true)
        ];
    }

    private function generateSummary(): array
    {
        $summary = [
            'total_tests_run' => 0,
            'successful_tests' => 0,
            'failed_tests' => 0,
            'total_execution_time' => microtime(true) - $this->startTime,
            'performance_score' => 0,
            'recommendations' => []
        ];
        
        foreach ($this->results as $testType => $result) {
            $summary['total_tests_run']++;
            
            if (isset($result['success']) && $result['success']) {
                $summary['successful_tests']++;
            } else {
                $summary['failed_tests']++;
            }
        }
        
        // Calculate performance score (0-100)
        $successRate = $summary['total_tests_run'] > 0 ? 
            ($summary['successful_tests'] / $summary['total_tests_run']) * 100 : 0;
        
        $summary['performance_score'] = $successRate;
        
        // Generate recommendations
        if ($summary['failed_tests'] > 0) {
            $summary['recommendations'][] = 'Fix failing tests to improve system reliability';
        }
        
        if ($summary['total_execution_time'] > 300) { // 5 minutes
            $summary['recommendations'][] = 'Consider optimizing test execution time';
        }
        
        $summary['recommendations'][] = 'Monitor performance metrics regularly';
        $summary['recommendations'][] = 'Implement automated performance regression testing';
        
        return $summary;
    }

    private function saveReport(string $filename, array $data, string $format): void
    {
        $dir = dirname($filename);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        
        if ($format === 'json') {
            file_put_contents($filename, json_encode($data, JSON_PRETTY_PRINT));
        } elseif ($format === 'html') {
            $html = $this->generateHTMLReport($data);
            file_put_contents($filename, $html);
        }
    }

    private function generateHTMLReport(array $data): string
    {
        $html = '<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>' . htmlspecialchars($data['test_suite']) . ' - Performance Report</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 20px; background-color: #f5f5f5; }
        .container { max-width: 1200px; margin: 0 auto; background: white; padding: 20px; border-radius: 8px; box-shadow: 0 2px 10px rgba(0,0,0,0.1); }
        .header { text-align: center; margin-bottom: 30px; }
        .summary { background: #e8f4fd; padding: 15px; border-radius: 5px; margin-bottom: 20px; }
        .test-section { margin-bottom: 30px; border: 1px solid #ddd; border-radius: 5px; }
        .test-header { background: #f8f9fa; padding: 10px 15px; border-bottom: 1px solid #ddd; font-weight: bold; }
        .test-content { padding: 15px; }
        .success { color: #28a745; }
        .failure { color: #dc3545; }
        .warning { color: #ffc107; }
        .metric { display: inline-block; margin: 5px 10px; padding: 5px 10px; background: #f8f9fa; border-radius: 3px; }
        .recommendations { background: #fff3cd; border: 1px solid #ffeaa7; padding: 15px; border-radius: 5px; }
        pre { background: #f8f9fa; padding: 10px; border-radius: 3px; overflow-x: auto; }
        table { width: 100%; border-collapse: collapse; margin: 10px 0; }
        th, td { padding: 8px 12px; text-align: left; border-bottom: 1px solid #ddd; }
        th { background-color: #f8f9fa; font-weight: bold; }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>' . htmlspecialchars($data['test_suite']) . '</h1>
            <p>Performance Report Generated: ' . htmlspecialchars($data['execution_timestamp']) . '</p>
        </div>
        
        <div class="summary">
            <h2>Executive Summary</h2>
            <div class="metric">Total Tests: ' . $data['summary']['total_tests_run'] . '</div>
            <div class="metric success">Successful: ' . $data['summary']['successful_tests'] . '</div>
            <div class="metric failure">Failed: ' . $data['summary']['failed_tests'] . '</div>
            <div class="metric">Performance Score: ' . number_format($data['summary']['performance_score'], 1) . '%</div>
            <div class="metric">Total Time: ' . number_format($data['summary']['total_execution_time'], 2) . 's</div>
        </div>';
        
        // Add test results sections
        foreach ($data['test_results'] as $testType => $result) {
            $statusClass = (isset($result['success']) && $result['success']) ? 'success' : 'failure';
            $statusText = (isset($result['success']) && $result['success']) ? '✅ Success' : '❌ Failed';
            
            $html .= '
        <div class="test-section">
            <div class="test-header">
                <span class="' . $statusClass . '">' . $statusText . '</span>
                ' . htmlspecialchars(ucwords(str_replace('_', ' ', $testType))) . '
                <span style="float: right;">⏱️ ' . number_format($result['execution_time'], 2) . 's</span>
            </div>
            <div class="test-content">';
            
            if (isset($result['output']) && !empty($result['output'])) {
                $html .= '<pre>' . htmlspecialchars(substr($result['output'], 0, 2000)) . 
                        (strlen($result['output']) > 2000 ? '...' : '') . '</pre>';
            }
            
            $html .= '</div>
        </div>';
        }
        
        // Add recommendations
        if (!empty($data['summary']['recommendations'])) {
            $html .= '
        <div class="recommendations">
            <h3>📋 Recommendations</h3>
            <ul>';
            
            foreach ($data['summary']['recommendations'] as $recommendation) {
                $html .= '<li>' . htmlspecialchars($recommendation) . '</li>';
            }
            
            $html .= '</ul>
        </div>';
        }
        
        // Add system info
        $html .= '
        <div class="test-section">
            <div class="test-header">System Information</div>
            <div class="test-content">
                <table>
                    <tr><th>Property</th><th>Value</th></tr>';
        
        foreach ($data['system_info'] as $key => $value) {
            $html .= '<tr><td>' . htmlspecialchars(ucwords(str_replace('_', ' ', $key))) . '</td><td>' . htmlspecialchars($value) . '</td></tr>';
        }
        
        $html .= '
                </table>
            </div>
        </div>
    </div>
</body>
</html>';
        
        return $html;
    }

    /**
     * Print execution summary
     */
    private function printExecutionSummary(): void
    {
        $totalTime = microtime(true) - $this->startTime;
        
        echo "=== PERFORMANCE TEST EXECUTION SUMMARY ===\n\n";
        
        $summary = $this->results;
        $totalTests = count($summary);
        $successfulTests = 0;
        $failedTests = 0;
        
        foreach ($summary as $testType => $result) {
            if (isset($result['success']) && $result['success']) {
                $successfulTests++;
            } else {
                $failedTests++;
            }
        }
        
        echo "📊 TEST RESULTS:\n";
        echo "   Total test suites: {$totalTests}\n";
        echo "   Successful: {$successfulTests}\n";
        echo "   Failed: {$failedTests}\n";
        echo "   Success rate: " . number_format(($successfulTests / $totalTests) * 100, 1) . "%\n\n";
        
        echo "⏱️ EXECUTION TIME:\n";
        echo "   Total execution time: " . number_format($totalTime, 2) . " seconds\n";
        echo "   Average time per suite: " . number_format($totalTime / $totalTests, 2) . " seconds\n\n";
        
        echo "💾 MEMORY USAGE:\n";
        echo "   Current memory usage: " . $this->formatBytes(memory_get_usage(true)) . "\n";
        echo "   Peak memory usage: " . $this->formatBytes(memory_get_peak_usage(true)) . "\n\n";
        
        if ($failedTests > 0) {
            echo "❌ FAILED TESTS:\n";
            foreach ($summary as $testType => $result) {
                if (!isset($result['success']) || !$result['success']) {
                    echo "   • " . ucwords(str_replace('_', ' ', $testType)) . "\n";
                }
            }
            echo "\n";
        }
        
        echo "🎯 OVERALL ASSESSMENT:\n";
        $successRate = ($successfulTests / $totalTests) * 100;
        
        if ($successRate >= 100) {
            echo "   🏆 EXCELLENT! All performance tests passed.\n";
        } elseif ($successRate >= 80) {
            echo "   ✅ GOOD! Most performance tests passed.\n";
        } elseif ($successRate >= 60) {
            echo "   ⚠️ ACCEPTABLE! Some performance issues detected.\n";
        } else {
            echo "   ❌ POOR! Significant performance issues detected.\n";
        }
        
        echo "\n📋 NEXT STEPS:\n";
        echo "   • Review detailed reports for specific performance metrics\n";
        echo "   • Address any failed tests or performance bottlenecks\n";
        echo "   • Set up automated performance monitoring\n";
        echo "   • Schedule regular performance testing\n";
        
        echo "\n" . str_repeat("=", 60) . "\n";
        echo "Performance testing completed at: " . date('Y-m-d H:i:s') . "\n";
    }

    private function formatBytes(int $bytes, int $precision = 2): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        
        for ($i = 0; $bytes > 1024 && $i < count($units) - 1; $i++) {
            $bytes /= 1024;
        }
        
        return round($bytes, $precision) . ' ' . $units[$i];
    }
}

// Run all performance tests if executed directly
if (php_sapi_name() === 'cli') {
    $runner = new ActiveProductFilterPerformanceTestRunner();
    $runner->runAllTests();
} else {
    header('Content-Type: text/plain; charset=utf-8');
    $runner = new ActiveProductFilterPerformanceTestRunner();
    $runner->runAllTests();
}