<?php
/**
 * Performance Testing for Detailed Inventory API
 * 
 * Tests API performance with large datasets and validates response times
 * meet the requirements (under 500ms for filtered queries).
 * 
 * Requirements: 7.1, 7.2, 7.3
 * Task: 4.3 Performance testing and validation
 */

// Set memory limit for large dataset testing
ini_set('memory_limit', '1G');
set_time_limit(300); // 5 minutes

// Load configuration
require_once __DIR__ . '/../config/database_postgresql.php';
require_once __DIR__ . '/../api/classes/DetailedInventoryService.php';
require_once __DIR__ . '/../api/classes/EnhancedCacheService.php';

class PerformanceTestRunner {
    
    private $pdo;
    private $service;
    private $cache;
    private $results = [];
    
    public function __construct() {
        $this->pdo = getDatabaseConnection();
        $this->cache = new EnhancedCacheService($this->pdo);
        $this->service = new DetailedInventoryService($this->pdo, $this->cache);
    }
    
    /**
     * Run all performance tests
     */
    public function runAllTests() {
        echo "=== Detailed Inventory API Performance Tests ===\n\n";
        
        // Clear cache to start fresh
        $this->cache->clear();
        
        // Test 1: Basic query performance
        $this->testBasicQueryPerformance();
        
        // Test 2: Filtered query performance
        $this->testFilteredQueryPerformance();
        
        // Test 3: Search query performance
        $this->testSearchQueryPerformance();
        
        // Test 4: Sorting performance
        $this->testSortingPerformance();
        
        // Test 5: Large dataset handling
        $this->testLargeDatasetHandling();
        
        // Test 6: Cache performance
        $this->testCachePerformance();
        
        // Test 7: Concurrent request handling
        $this->testConcurrentRequests();
        
        // Test 8: Database query optimization
        $this->testDatabaseQueryOptimization();
        
        // Generate summary report
        $this->generateSummaryReport();
    }
    
    /**
     * Test basic query performance
     */
    private function testBasicQueryPerformance() {
        echo "Test 1: Basic Query Performance\n";
        echo "--------------------------------\n";
        
        $iterations = 10;
        $times = [];
        
        for ($i = 0; $i < $iterations; $i++) {
            $startTime = microtime(true);
            
            $result = $this->service->getDetailedInventory([
                'limit' => 100,
                'active_only' => true
            ]);
            
            $endTime = microtime(true);
            $executionTime = ($endTime - $startTime) * 1000; // Convert to milliseconds
            $times[] = $executionTime;
            
            echo sprintf("Iteration %d: %.2f ms\n", $i + 1, $executionTime);
        }
        
        $avgTime = array_sum($times) / count($times);
        $minTime = min($times);
        $maxTime = max($times);
        
        $this->results['basic_query'] = [
            'average_time' => $avgTime,
            'min_time' => $minTime,
            'max_time' => $maxTime,
            'target_time' => 500,
            'passed' => $avgTime < 500
        ];
        
        echo sprintf("Average: %.2f ms, Min: %.2f ms, Max: %.2f ms\n", $avgTime, $minTime, $maxTime);
        echo sprintf("Target: < 500ms - %s\n\n", $avgTime < 500 ? "PASSED" : "FAILED");
    }
    
    /**
     * Test filtered query performance
     */
    private function testFilteredQueryPerformance() {
        echo "Test 2: Filtered Query Performance\n";
        echo "----------------------------------\n";
        
        $filterTests = [
            'warehouse_filter' => ['warehouses' => ['Коледино']],
            'status_filter' => ['statuses' => ['critical', 'low']],
            'combined_filter' => [
                'warehouses' => ['Коледино', 'Тверь'],
                'statuses' => ['critical', 'low'],
                'min_urgency_score' => 50
            ],
            'replenishment_filter' => ['has_replenishment_need' => true]
        ];
        
        foreach ($filterTests as $testName => $filters) {
            echo "Testing: $testName\n";
            
            $iterations = 5;
            $times = [];
            
            for ($i = 0; $i < $iterations; $i++) {
                $startTime = microtime(true);
                
                $result = $this->service->getDetailedInventory(array_merge($filters, [
                    'limit' => 500,
                    'active_only' => true
                ]));
                
                $endTime = microtime(true);
                $executionTime = ($endTime - $startTime) * 1000;
                $times[] = $executionTime;
            }
            
            $avgTime = array_sum($times) / count($times);
            
            $this->results['filtered_queries'][$testName] = [
                'average_time' => $avgTime,
                'target_time' => 500,
                'passed' => $avgTime < 500
            ];
            
            echo sprintf("  Average: %.2f ms - %s\n", $avgTime, $avgTime < 500 ? "PASSED" : "FAILED");
        }
        
        echo "\n";
    }
    
    /**
     * Test search query performance
     */
    private function testSearchQueryPerformance() {
        echo "Test 3: Search Query Performance\n";
        echo "--------------------------------\n";
        
        $searchTerms = [
            'short_term' => 'AB',
            'medium_term' => 'Автозапчасть',
            'long_term' => 'Автомобильная запчасть для двигателя',
            'sku_search' => '12345'
        ];
        
        foreach ($searchTerms as $testName => $searchTerm) {
            echo "Testing search: $testName ('$searchTerm')\n";
            
            $iterations = 5;
            $times = [];
            
            for ($i = 0; $i < $iterations; $i++) {
                $startTime = microtime(true);
                
                $result = $this->service->getDetailedInventory([
                    'search' => $searchTerm,
                    'limit' => 100,
                    'active_only' => true
                ]);
                
                $endTime = microtime(true);
                $executionTime = ($endTime - $startTime) * 1000;
                $times[] = $executionTime;
            }
            
            $avgTime = array_sum($times) / count($times);
            
            $this->results['search_queries'][$testName] = [
                'average_time' => $avgTime,
                'search_term' => $searchTerm,
                'target_time' => 1000, // Search queries have higher threshold
                'passed' => $avgTime < 1000
            ];
            
            echo sprintf("  Average: %.2f ms - %s\n", $avgTime, $avgTime < 1000 ? "PASSED" : "FAILED");
        }
        
        echo "\n";
    }
    
    /**
     * Test sorting performance
     */
    private function testSortingPerformance() {
        echo "Test 4: Sorting Performance\n";
        echo "---------------------------\n";
        
        $sortTests = [
            'days_of_stock_asc' => ['sort_by' => 'days_of_stock', 'sort_order' => 'asc'],
            'urgency_score_desc' => ['sort_by' => 'urgency_score', 'sort_order' => 'desc'],
            'daily_sales_desc' => ['sort_by' => 'daily_sales_avg', 'sort_order' => 'desc'],
            'product_name_asc' => ['sort_by' => 'product_name', 'sort_order' => 'asc']
        ];
        
        foreach ($sortTests as $testName => $sortConfig) {
            echo "Testing sort: $testName\n";
            
            $startTime = microtime(true);
            
            $result = $this->service->getDetailedInventory(array_merge($sortConfig, [
                'limit' => 1000,
                'active_only' => true
            ]));
            
            $endTime = microtime(true);
            $executionTime = ($endTime - $startTime) * 1000;
            
            $this->results['sorting'][$testName] = [
                'execution_time' => $executionTime,
                'target_time' => 750,
                'passed' => $executionTime < 750
            ];
            
            echo sprintf("  Time: %.2f ms - %s\n", $executionTime, $executionTime < 750 ? "PASSED" : "FAILED");
        }
        
        echo "\n";
    }
    
    /**
     * Test large dataset handling
     */
    private function testLargeDatasetHandling() {
        echo "Test 5: Large Dataset Handling\n";
        echo "-------------------------------\n";
        
        $datasetSizes = [1000, 5000, 10000];
        
        foreach ($datasetSizes as $size) {
            echo "Testing dataset size: $size records\n";
            
            $startTime = microtime(true);
            
            $result = $this->service->getDetailedInventory([
                'limit' => $size,
                'active_only' => true
            ]);
            
            $endTime = microtime(true);
            $executionTime = ($endTime - $startTime) * 1000;
            
            // Memory usage
            $memoryUsage = memory_get_peak_usage(true) / 1024 / 1024; // MB
            
            $this->results['large_dataset'][$size] = [
                'execution_time' => $executionTime,
                'memory_usage_mb' => $memoryUsage,
                'records_returned' => count($result['data'] ?? []),
                'target_time' => 2000, // 2 seconds for large datasets
                'passed' => $executionTime < 2000
            ];
            
            echo sprintf("  Time: %.2f ms, Memory: %.2f MB, Records: %d - %s\n", 
                $executionTime, $memoryUsage, count($result['data'] ?? []),
                $executionTime < 2000 ? "PASSED" : "FAILED"
            );
        }
        
        echo "\n";
    }
    
    /**
     * Test cache performance
     */
    private function testCachePerformance() {
        echo "Test 6: Cache Performance\n";
        echo "-------------------------\n";
        
        $filters = [
            'warehouses' => ['Коледино'],
            'statuses' => ['critical'],
            'limit' => 500
        ];
        
        // First request (cache miss)
        echo "First request (cache miss):\n";
        $startTime = microtime(true);
        $result1 = $this->service->getDetailedInventory($filters);
        $endTime = microtime(true);
        $cacheMissTime = ($endTime - $startTime) * 1000;
        
        echo sprintf("  Time: %.2f ms\n", $cacheMissTime);
        
        // Second request (cache hit)
        echo "Second request (cache hit):\n";
        $startTime = microtime(true);
        $result2 = $this->service->getDetailedInventory($filters);
        $endTime = microtime(true);
        $cacheHitTime = ($endTime - $startTime) * 1000;
        
        echo sprintf("  Time: %.2f ms\n", $cacheHitTime);
        
        $speedup = $cacheMissTime / $cacheHitTime;
        
        $this->results['cache_performance'] = [
            'cache_miss_time' => $cacheMissTime,
            'cache_hit_time' => $cacheHitTime,
            'speedup_factor' => $speedup,
            'target_speedup' => 5, // Cache should be at least 5x faster
            'passed' => $speedup >= 5
        ];
        
        echo sprintf("Cache speedup: %.1fx - %s\n\n", $speedup, $speedup >= 5 ? "PASSED" : "FAILED");
    }
    
    /**
     * Test concurrent request handling
     */
    private function testConcurrentRequests() {
        echo "Test 7: Concurrent Request Simulation\n";
        echo "-------------------------------------\n";
        
        // Simulate concurrent requests by making multiple rapid requests
        $concurrentRequests = 5;
        $times = [];
        
        echo "Making $concurrentRequests concurrent requests...\n";
        
        for ($i = 0; $i < $concurrentRequests; $i++) {
            $startTime = microtime(true);
            
            $result = $this->service->getDetailedInventory([
                'warehouses' => ['Коледино'],
                'limit' => 200,
                'offset' => $i * 200
            ]);
            
            $endTime = microtime(true);
            $executionTime = ($endTime - $startTime) * 1000;
            $times[] = $executionTime;
            
            echo sprintf("Request %d: %.2f ms\n", $i + 1, $executionTime);
        }
        
        $avgTime = array_sum($times) / count($times);
        $maxTime = max($times);
        
        $this->results['concurrent_requests'] = [
            'average_time' => $avgTime,
            'max_time' => $maxTime,
            'request_count' => $concurrentRequests,
            'target_time' => 1000,
            'passed' => $maxTime < 1000
        ];
        
        echo sprintf("Average: %.2f ms, Max: %.2f ms - %s\n\n", 
            $avgTime, $maxTime, $maxTime < 1000 ? "PASSED" : "FAILED"
        );
    }
    
    /**
     * Test database query optimization
     */
    private function testDatabaseQueryOptimization() {
        echo "Test 8: Database Query Optimization\n";
        echo "-----------------------------------\n";
        
        // Test the optimized view performance
        $queries = [
            'view_performance' => "SELECT COUNT(*) FROM v_detailed_inventory",
            'index_usage' => "EXPLAIN (ANALYZE, BUFFERS) SELECT * FROM v_detailed_inventory WHERE warehouse_name = 'Коледино' LIMIT 100",
            'filter_performance' => "EXPLAIN (ANALYZE, BUFFERS) SELECT * FROM v_detailed_inventory WHERE stock_status = 'critical' LIMIT 100"
        ];
        
        foreach ($queries as $testName => $query) {
            echo "Testing: $testName\n";
            
            $startTime = microtime(true);
            
            try {
                $stmt = $this->pdo->prepare($query);
                $stmt->execute();
                $result = $stmt->fetchAll(PDO::FETCH_ASSOC);
                
                $endTime = microtime(true);
                $executionTime = ($endTime - $startTime) * 1000;
                
                $this->results['database_optimization'][$testName] = [
                    'execution_time' => $executionTime,
                    'rows_affected' => $stmt->rowCount(),
                    'passed' => $executionTime < 100 // Database queries should be very fast
                ];
                
                echo sprintf("  Time: %.2f ms, Rows: %d - %s\n", 
                    $executionTime, $stmt->rowCount(),
                    $executionTime < 100 ? "PASSED" : "FAILED"
                );
                
            } catch (Exception $e) {
                echo "  ERROR: " . $e->getMessage() . "\n";
                $this->results['database_optimization'][$testName] = [
                    'error' => $e->getMessage(),
                    'passed' => false
                ];
            }
        }
        
        echo "\n";
    }
    
    /**
     * Generate summary report
     */
    private function generateSummaryReport() {
        echo "=== PERFORMANCE TEST SUMMARY ===\n";
        echo "================================\n\n";
        
        $totalTests = 0;
        $passedTests = 0;
        
        foreach ($this->results as $category => $tests) {
            echo strtoupper(str_replace('_', ' ', $category)) . ":\n";
            
            if (isset($tests['passed'])) {
                // Single test
                $totalTests++;
                if ($tests['passed']) $passedTests++;
                
                echo sprintf("  %s\n", $tests['passed'] ? "✓ PASSED" : "✗ FAILED");
                
                if (isset($tests['average_time'])) {
                    echo sprintf("  Average time: %.2f ms (target: < %.0f ms)\n", 
                        $tests['average_time'], $tests['target_time']);
                }
            } else {
                // Multiple tests
                foreach ($tests as $testName => $testResult) {
                    $totalTests++;
                    if ($testResult['passed']) $passedTests++;
                    
                    echo sprintf("  %s: %s\n", 
                        str_replace('_', ' ', $testName),
                        $testResult['passed'] ? "✓ PASSED" : "✗ FAILED"
                    );
                }
            }
            
            echo "\n";
        }
        
        $successRate = ($totalTests > 0) ? ($passedTests / $totalTests) * 100 : 0;
        
        echo "OVERALL RESULTS:\n";
        echo sprintf("Tests passed: %d/%d (%.1f%%)\n", $passedTests, $totalTests, $successRate);
        echo sprintf("Overall status: %s\n", $successRate >= 80 ? "✓ PASSED" : "✗ FAILED");
        
        // Save results to file
        $reportFile = __DIR__ . '/performance_test_results_' . date('Y-m-d_H-i-s') . '.json';
        file_put_contents($reportFile, json_encode($this->results, JSON_PRETTY_PRINT));
        echo "\nDetailed results saved to: $reportFile\n";
    }
}

// Run the tests
if (php_sapi_name() === 'cli') {
    $tester = new PerformanceTestRunner();
    $tester->runAllTests();
} else {
    echo "This script must be run from the command line.\n";
    echo "Usage: php " . basename(__FILE__) . "\n";
}

?>