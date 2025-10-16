<?php

namespace Tests\Performance;

use PHPUnit\Framework\TestCase;

/**
 * Performance tests for Active Product Filter system
 * 
 * Tests dashboard API performance, database performance with various data volumes,
 * and benchmarks system performance before and after optimization
 * 
 * Requirements: 2.4 - Performance optimization for active product filtering
 */
class ActiveProductFilterPerformanceTest extends TestCase
{
    private $pdo;
    private $baseUrl;
    private $performanceResults = [];
    private $testStartTime;
    private $testStartMemory;

    protected function setUp(): void
    {
        parent::setUp();
        
        $this->testStartTime = microtime(true);
        $this->testStartMemory = memory_get_usage(true);
        
        // Database connection
        $this->setupDatabaseConnection();
        
        // API base URL
        $this->baseUrl = 'http://localhost/api/inventory-analytics.php';
        
        // Ensure test data exists
        $this->setupTestData();
    }

    protected function tearDown(): void
    {
        $testEndTime = microtime(true);
        $testEndMemory = memory_get_usage(true);
        
        $this->performanceResults['test_execution'] = [
            'total_time' => $testEndTime - $this->testStartTime,
            'memory_used' => $testEndMemory - $this->testStartMemory,
            'peak_memory' => memory_get_peak_usage(true)
        ];
        
        parent::tearDown();
    }

    private function setupDatabaseConnection(): void
    {
        try {
            $this->pdo = new \PDO(
                "mysql:host=localhost;dbname=zuz_inventory;charset=utf8mb4",
                "root",
                "",
                [
                    \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
                    \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
                    \PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4"
                ]
            );
        } catch (\PDOException $e) {
            $this->markTestSkipped('Database connection failed: ' . $e->getMessage());
        }
    }

    private function setupTestData(): void
    {
        // Ensure we have test products with activity status
        $this->pdo->exec("
            INSERT IGNORE INTO products (id, external_sku, name, is_active, activity_checked_at, activity_reason)
            VALUES 
            ('test_active_1', 'ACTIVE_SKU_1', 'Active Test Product 1', 1, NOW(), 'visible_processed_stock'),
            ('test_active_2', 'ACTIVE_SKU_2', 'Active Test Product 2', 1, NOW(), 'visible_processed_stock'),
            ('test_inactive_1', 'INACTIVE_SKU_1', 'Inactive Test Product 1', 0, NOW(), 'not_visible'),
            ('test_inactive_2', 'INACTIVE_SKU_2', 'Inactive Test Product 2', 0, NOW(), 'no_stock')
        ");

        // Ensure inventory data exists
        $this->pdo->exec("
            INSERT IGNORE INTO inventory_data (product_id, external_sku, stock_quantity, last_updated)
            VALUES 
            ('test_active_1', 'ACTIVE_SKU_1', 100, NOW()),
            ('test_active_2', 'ACTIVE_SKU_2', 50, NOW()),
            ('test_inactive_1', 'INACTIVE_SKU_1', 0, NOW()),
            ('test_inactive_2', 'INACTIVE_SKU_2', 0, NOW())
        ");
    }

    /**
     * Test dashboard API performance with active product filtering
     * Requirements: 2.4
     */
    public function testDashboardAPIPerformanceWithActiveFiltering(): void
    {
        echo "\n=== Dashboard API Performance Tests ===\n";
        
        $endpoints = [
            'critical_stock' => '?action=critical_stock&active_only=true',
            'low_stock' => '?action=low_stock&active_only=true',
            'overstock' => '?action=overstock&active_only=true',
            'activity_stats' => '?action=activity_stats',
            'inactive_products' => '?action=inactive_products'
        ];

        $results = [];
        
        foreach ($endpoints as $name => $endpoint) {
            $times = [];
            $errors = 0;
            $iterations = 10;

            for ($i = 0; $i < $iterations; $i++) {
                $startTime = microtime(true);
                $startMemory = memory_get_usage();

                try {
                    $response = $this->makeAPIRequest($endpoint);
                    
                    if (!$response || !isset($response['success']) || !$response['success']) {
                        $errors++;
                    }
                } catch (\Exception $e) {
                    $errors++;
                }

                $endTime = microtime(true);
                $endMemory = memory_get_usage();
                
                $times[] = ($endTime - $startTime) * 1000; // Convert to milliseconds
            }

            $avgTime = array_sum($times) / count($times);
            $maxTime = max($times);
            $minTime = min($times);
            $successRate = (($iterations - $errors) / $iterations) * 100;

            $results[$name] = [
                'avg_time' => $avgTime,
                'max_time' => $maxTime,
                'min_time' => $minTime,
                'success_rate' => $successRate,
                'errors' => $errors
            ];

            echo "Endpoint: {$name}\n";
            echo "  Average time: " . number_format($avgTime, 2) . " ms\n";
            echo "  Max time: " . number_format($maxTime, 2) . " ms\n";
            echo "  Min time: " . number_format($minTime, 2) . " ms\n";
            echo "  Success rate: " . number_format($successRate, 1) . "%\n";

            // Performance assertions
            $this->assertLessThan(1000, $avgTime, "Average response time for {$name} should be under 1 second");
            $this->assertGreaterThan(95, $successRate, "Success rate for {$name} should be above 95%");
        }

        $this->performanceResults['dashboard_api'] = $results;
        
        // Overall API performance assessment
        $overallAvgTime = array_sum(array_column($results, 'avg_time')) / count($results);
        $this->assertLessThan(500, $overallAvgTime, 'Overall API performance should be good');
        
        echo "Overall average response time: " . number_format($overallAvgTime, 2) . " ms\n\n";
    }

    /**
     * Test database performance with various data volumes
     * Requirements: 2.4
     */
    public function testDatabasePerformanceWithVariousDataVolumes(): void
    {
        echo "=== Database Performance Tests ===\n";
        
        $dataVolumes = [100, 500, 1000, 2500, 5000];
        $results = [];

        foreach ($dataVolumes as $volume) {
            echo "Testing with {$volume} products...\n";
            
            // Create test data for this volume
            $this->createTestDataVolume($volume);
            
            // Test various queries
            $queryResults = $this->testDatabaseQueries($volume);
            $results[$volume] = $queryResults;
            
            // Clean up test data
            $this->cleanupTestDataVolume($volume);
        }

        $this->performanceResults['database_volumes'] = $results;
        
        // Verify scaling is reasonable
        $this->verifyDatabaseScaling($results);
    }

    private function createTestDataVolume(int $volume): void
    {
        // Clean existing test data
        $this->pdo->exec("DELETE FROM products WHERE id LIKE 'perf_test_%'");
        $this->pdo->exec("DELETE FROM inventory_data WHERE product_id LIKE 'perf_test_%'");

        // Create products in batches for better performance
        $batchSize = 100;
        $batches = ceil($volume / $batchSize);

        for ($batch = 0; $batch < $batches; $batch++) {
            $values = [];
            $inventoryValues = [];
            
            $start = $batch * $batchSize;
            $end = min($start + $batchSize, $volume);
            
            for ($i = $start; $i < $end; $i++) {
                $isActive = ($i % 3 !== 0) ? 1 : 0; // ~67% active products
                $stock = $isActive ? rand(1, 100) : 0;
                
                $values[] = "('perf_test_{$i}', 'PERF_SKU_{$i}', 'Performance Test Product {$i}', {$isActive}, NOW(), 'test_data')";
                $inventoryValues[] = "('perf_test_{$i}', 'PERF_SKU_{$i}', {$stock}, NOW())";
            }
            
            if (!empty($values)) {
                $this->pdo->exec("
                    INSERT INTO products (id, external_sku, name, is_active, activity_checked_at, activity_reason)
                    VALUES " . implode(',', $values)
                );
                
                $this->pdo->exec("
                    INSERT INTO inventory_data (product_id, external_sku, stock_quantity, last_updated)
                    VALUES " . implode(',', $inventoryValues)
                );
            }
        }
    }

    private function testDatabaseQueries(int $volume): array
    {
        $queries = [
            'active_products_count' => "SELECT COUNT(*) FROM products WHERE is_active = 1 AND id LIKE 'perf_test_%'",
            'active_with_stock' => "
                SELECT COUNT(*) 
                FROM products p 
                JOIN inventory_data i ON p.id = i.product_id 
                WHERE p.is_active = 1 AND i.stock_quantity > 0 AND p.id LIKE 'perf_test_%'
            ",
            'critical_stock_active' => "
                SELECT COUNT(*) 
                FROM products p 
                JOIN inventory_data i ON p.id = i.product_id 
                WHERE p.is_active = 1 AND i.stock_quantity < 10 AND i.stock_quantity > 0 AND p.id LIKE 'perf_test_%'
            ",
            'activity_summary' => "
                SELECT 
                    is_active,
                    COUNT(*) as count,
                    AVG(CASE WHEN i.stock_quantity IS NOT NULL THEN i.stock_quantity ELSE 0 END) as avg_stock
                FROM products p 
                LEFT JOIN inventory_data i ON p.id = i.product_id 
                WHERE p.id LIKE 'perf_test_%'
                GROUP BY is_active
            "
        ];

        $results = [];
        
        foreach ($queries as $name => $sql) {
            $times = [];
            $iterations = 5;
            
            for ($i = 0; $i < $iterations; $i++) {
                $startTime = microtime(true);
                
                $stmt = $this->pdo->prepare($sql);
                $stmt->execute();
                $result = $stmt->fetchAll();
                
                $endTime = microtime(true);
                $times[] = ($endTime - $startTime) * 1000;
            }
            
            $avgTime = array_sum($times) / count($times);
            $results[$name] = [
                'avg_time' => $avgTime,
                'max_time' => max($times),
                'min_time' => min($times)
            ];
            
            echo "  Query {$name}: " . number_format($avgTime, 2) . " ms\n";
            
            // Performance assertion
            $this->assertLessThan(2000, $avgTime, "Query {$name} should complete within 2 seconds for {$volume} products");
        }
        
        return $results;
    }

    private function cleanupTestDataVolume(int $volume): void
    {
        $this->pdo->exec("DELETE FROM products WHERE id LIKE 'perf_test_%'");
        $this->pdo->exec("DELETE FROM inventory_data WHERE product_id LIKE 'perf_test_%'");
    }

    private function verifyDatabaseScaling(array $results): void
    {
        $volumes = array_keys($results);
        sort($volumes);
        
        $smallVolume = $volumes[0];
        $largeVolume = end($volumes);
        
        foreach (['active_products_count', 'active_with_stock'] as $queryType) {
            $smallTime = $results[$smallVolume][$queryType]['avg_time'];
            $largeTime = $results[$largeVolume][$queryType]['avg_time'];
            
            $scalingFactor = $largeTime / $smallTime;
            $volumeRatio = $largeVolume / $smallVolume;
            
            echo "Query {$queryType} scaling: {$scalingFactor}x for {$volumeRatio}x data\n";
            
            // Scaling should be reasonable (not worse than linear)
            $this->assertLessThan($volumeRatio * 2, $scalingFactor, 
                "Query {$queryType} scaling should be better than 2x linear");
        }
    }

    /**
     * Benchmark system performance before and after optimization
     * Requirements: 2.4
     */
    public function testSystemPerformanceBenchmark(): void
    {
        echo "=== System Performance Benchmark ===\n";
        
        // Test current system performance
        $beforeOptimization = $this->runPerformanceBenchmark('before');
        
        // Apply temporary optimizations for testing
        $this->applyTemporaryOptimizations();
        
        // Test optimized system performance
        $afterOptimization = $this->runPerformanceBenchmark('after');
        
        // Remove temporary optimizations
        $this->removeTemporaryOptimizations();
        
        // Compare results
        $this->comparePerformanceResults($beforeOptimization, $afterOptimization);
        
        $this->performanceResults['benchmark'] = [
            'before' => $beforeOptimization,
            'after' => $afterOptimization
        ];
    }

    private function runPerformanceBenchmark(string $phase): array
    {
        echo "Running {$phase} optimization benchmark...\n";
        
        $results = [];
        
        // Test 1: API response times
        $apiTimes = [];
        for ($i = 0; $i < 20; $i++) {
            $startTime = microtime(true);
            $this->makeAPIRequest('?action=critical_stock&active_only=true');
            $endTime = microtime(true);
            $apiTimes[] = ($endTime - $startTime) * 1000;
        }
        
        $results['api_performance'] = [
            'avg_time' => array_sum($apiTimes) / count($apiTimes),
            'max_time' => max($apiTimes),
            'min_time' => min($apiTimes)
        ];
        
        // Test 2: Database query performance
        $dbTimes = [];
        $query = "
            SELECT COUNT(*) as active_count
            FROM products p 
            JOIN inventory_data i ON p.id = i.product_id 
            WHERE p.is_active = 1 AND i.stock_quantity > 0
        ";
        
        for ($i = 0; $i < 10; $i++) {
            $startTime = microtime(true);
            $stmt = $this->pdo->prepare($query);
            $stmt->execute();
            $result = $stmt->fetch();
            $endTime = microtime(true);
            $dbTimes[] = ($endTime - $startTime) * 1000;
        }
        
        $results['db_performance'] = [
            'avg_time' => array_sum($dbTimes) / count($dbTimes),
            'max_time' => max($dbTimes),
            'min_time' => min($dbTimes)
        ];
        
        // Test 3: Memory usage
        $initialMemory = memory_get_usage(true);
        
        // Perform memory-intensive operations
        for ($i = 0; $i < 10; $i++) {
            $this->makeAPIRequest('?action=activity_stats');
        }
        
        $finalMemory = memory_get_usage(true);
        $peakMemory = memory_get_peak_usage(true);
        
        $results['memory_usage'] = [
            'initial' => $initialMemory,
            'final' => $finalMemory,
            'peak' => $peakMemory,
            'used' => $finalMemory - $initialMemory
        ];
        
        echo "  API avg time: " . number_format($results['api_performance']['avg_time'], 2) . " ms\n";
        echo "  DB avg time: " . number_format($results['db_performance']['avg_time'], 2) . " ms\n";
        echo "  Memory used: " . $this->formatBytes($results['memory_usage']['used']) . "\n";
        
        return $results;
    }

    private function applyTemporaryOptimizations(): void
    {
        // Add temporary indexes for testing
        try {
            $this->pdo->exec("CREATE INDEX IF NOT EXISTS temp_idx_products_active ON products(is_active, activity_checked_at)");
            $this->pdo->exec("CREATE INDEX IF NOT EXISTS temp_idx_inventory_stock ON inventory_data(stock_quantity, last_updated)");
        } catch (\PDOException $e) {
            // Indexes might already exist
        }
    }

    private function removeTemporaryOptimizations(): void
    {
        try {
            $this->pdo->exec("DROP INDEX IF EXISTS temp_idx_products_active ON products");
            $this->pdo->exec("DROP INDEX IF EXISTS temp_idx_inventory_stock ON inventory_data");
        } catch (\PDOException $e) {
            // Ignore errors when dropping indexes
        }
    }

    private function comparePerformanceResults(array $before, array $after): void
    {
        echo "Performance comparison:\n";
        
        // API performance improvement
        $apiImprovement = (($before['api_performance']['avg_time'] - $after['api_performance']['avg_time']) 
                          / $before['api_performance']['avg_time']) * 100;
        
        echo "  API performance improvement: " . number_format($apiImprovement, 1) . "%\n";
        
        // Database performance improvement
        $dbImprovement = (($before['db_performance']['avg_time'] - $after['db_performance']['avg_time']) 
                         / $before['db_performance']['avg_time']) * 100;
        
        echo "  Database performance improvement: " . number_format($dbImprovement, 1) . "%\n";
        
        // Memory usage comparison
        $memoryChange = $after['memory_usage']['used'] - $before['memory_usage']['used'];
        echo "  Memory usage change: " . $this->formatBytes($memoryChange) . "\n";
        
        // Assertions for meaningful improvements
        $this->assertGreaterThan(-10, $apiImprovement, 'API performance should not degrade significantly');
        $this->assertGreaterThan(-10, $dbImprovement, 'Database performance should not degrade significantly');
    }

    /**
     * Test concurrent load on dashboard API
     */
    public function testConcurrentLoadPerformance(): void
    {
        echo "=== Concurrent Load Performance Test ===\n";
        
        $concurrentRequests = 20;
        $requestsPerBatch = 5;
        $results = [];
        
        for ($batch = 0; $batch < $concurrentRequests / $requestsPerBatch; $batch++) {
            $batchStartTime = microtime(true);
            
            // Simulate concurrent requests by rapid sequential calls
            for ($i = 0; $i < $requestsPerBatch; $i++) {
                $startTime = microtime(true);
                
                try {
                    $response = $this->makeAPIRequest('?action=critical_stock&active_only=true');
                    $success = $response && isset($response['success']) && $response['success'];
                } catch (\Exception $e) {
                    $success = false;
                }
                
                $endTime = microtime(true);
                $results[] = [
                    'time' => ($endTime - $startTime) * 1000,
                    'success' => $success
                ];
                
                // Small delay to simulate real-world usage
                usleep(10000); // 10ms
            }
            
            $batchEndTime = microtime(true);
            echo "  Batch " . ($batch + 1) . " completed in " . 
                 number_format(($batchEndTime - $batchStartTime) * 1000, 2) . " ms\n";
        }
        
        // Analyze results
        $times = array_column($results, 'time');
        $successes = array_filter($results, function($r) { return $r['success']; });
        
        $avgTime = array_sum($times) / count($times);
        $maxTime = max($times);
        $successRate = (count($successes) / count($results)) * 100;
        
        echo "Concurrent load results:\n";
        echo "  Total requests: " . count($results) . "\n";
        echo "  Average time: " . number_format($avgTime, 2) . " ms\n";
        echo "  Max time: " . number_format($maxTime, 2) . " ms\n";
        echo "  Success rate: " . number_format($successRate, 1) . "%\n";
        
        $this->performanceResults['concurrent_load'] = [
            'total_requests' => count($results),
            'avg_time' => $avgTime,
            'max_time' => $maxTime,
            'success_rate' => $successRate
        ];
        
        // Performance assertions
        $this->assertLessThan(2000, $avgTime, 'Average response time under concurrent load should be reasonable');
        $this->assertGreaterThan(90, $successRate, 'Success rate under concurrent load should be high');
        $this->assertLessThan(5000, $maxTime, 'Maximum response time should not be excessive');
    }

    /**
     * Test memory efficiency with repeated operations
     */
    public function testMemoryEfficiencyWithRepeatedOperations(): void
    {
        echo "=== Memory Efficiency Test ===\n";
        
        $initialMemory = memory_get_usage(true);
        $memoryReadings = [$initialMemory];
        
        // Perform repeated operations
        for ($i = 0; $i < 50; $i++) {
            $this->makeAPIRequest('?action=activity_stats');
            
            if ($i % 10 === 0) {
                $currentMemory = memory_get_usage(true);
                $memoryReadings[] = $currentMemory;
                
                // Force garbage collection
                gc_collect_cycles();
                
                echo "  Iteration {$i}: " . $this->formatBytes($currentMemory) . "\n";
            }
        }
        
        $finalMemory = memory_get_usage(true);
        $peakMemory = memory_get_peak_usage(true);
        $memoryIncrease = $finalMemory - $initialMemory;
        
        echo "Memory efficiency results:\n";
        echo "  Initial memory: " . $this->formatBytes($initialMemory) . "\n";
        echo "  Final memory: " . $this->formatBytes($finalMemory) . "\n";
        echo "  Peak memory: " . $this->formatBytes($peakMemory) . "\n";
        echo "  Memory increase: " . $this->formatBytes($memoryIncrease) . "\n";
        
        $this->performanceResults['memory_efficiency'] = [
            'initial' => $initialMemory,
            'final' => $finalMemory,
            'peak' => $peakMemory,
            'increase' => $memoryIncrease
        ];
        
        // Memory efficiency assertions
        $this->assertLessThan(20 * 1024 * 1024, $memoryIncrease, 'Memory increase should be reasonable (< 20MB)');
        $this->assertLessThan(100 * 1024 * 1024, $peakMemory, 'Peak memory usage should be reasonable (< 100MB)');
    }

    private function makeAPIRequest(string $endpoint): ?array
    {
        $url = $this->baseUrl . $endpoint;
        
        $context = stream_context_create([
            'http' => [
                'method' => 'GET',
                'timeout' => 30,
                'header' => 'Content-Type: application/json'
            ]
        ]);
        
        $response = @file_get_contents($url, false, $context);
        
        if ($response === false) {
            return null;
        }
        
        return json_decode($response, true);
    }

    private function formatBytes(int $bytes, int $precision = 2): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        
        for ($i = 0; $bytes > 1024 && $i < count($units) - 1; $i++) {
            $bytes /= 1024;
        }
        
        return round($bytes, $precision) . ' ' . $units[$i];
    }

    /**
     * Generate performance report
     */
    public function testGeneratePerformanceReport(): void
    {
        echo "\n=== Performance Test Summary ===\n";
        
        if (empty($this->performanceResults)) {
            echo "No performance data collected.\n";
            return;
        }
        
        $report = [
            'test_execution_time' => date('Y-m-d H:i:s'),
            'results' => $this->performanceResults
        ];
        
        // Save report to file
        $reportFile = __DIR__ . '/../results/active_product_filter_performance_' . date('Ymd_His') . '.json';
        $reportDir = dirname($reportFile);
        
        if (!is_dir($reportDir)) {
            mkdir($reportDir, 0755, true);
        }
        
        file_put_contents($reportFile, json_encode($report, JSON_PRETTY_PRINT));
        
        echo "Performance report saved to: {$reportFile}\n";
        
        // Print summary
        if (isset($this->performanceResults['dashboard_api'])) {
            $apiResults = $this->performanceResults['dashboard_api'];
            $avgApiTime = array_sum(array_column($apiResults, 'avg_time')) / count($apiResults);
            echo "Average API response time: " . number_format($avgApiTime, 2) . " ms\n";
        }
        
        if (isset($this->performanceResults['test_execution'])) {
            $execution = $this->performanceResults['test_execution'];
            echo "Total test execution time: " . number_format($execution['total_time'], 2) . " seconds\n";
            echo "Total memory used: " . $this->formatBytes($execution['memory_used']) . "\n";
        }
        
        $this->assertTrue(true, 'Performance report generated successfully');
    }
}