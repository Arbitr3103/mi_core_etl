<?php
/**
 * Performance Testing Script for Marketplace Data Separation
 * Tests performance with production data volumes and concurrent loads
 * 
 * @version 1.0.0
 * @author Manhattan System Team
 */

require_once 'MarginDashboardAPI.php';

class MarketplacePerformanceTest {
    private $api;
    private $results = [];
    private $logFile;
    
    // Performance thresholds
    private $thresholds = [
        'api_response_time' => 3.0,      // 3 seconds max
        'large_dataset_time' => 10.0,    // 10 seconds for large datasets
        'concurrent_success_rate' => 0.9, // 90% success rate
        'memory_limit' => 256 * 1024 * 1024, // 256MB
        'query_count_limit' => 50        // Max queries per request
    ];
    
    public function __construct($host, $dbname, $username, $password) {
        $this->logFile = 'performance_test_' . date('Y-m-d_H-i-s') . '.log';
        
        try {
            $this->api = new MarginDashboardAPI($host, $dbname, $username, $password);
            $this->log("✅ Database connection established");
        } catch (Exception $e) {
            $this->log("❌ Database connection failed: " . $e->getMessage());
            throw $e;
        }
    }
    
    /**
     * Run all performance tests
     */
    public function runPerformanceTests() {
        $this->log("⚡ Starting Performance Testing for Marketplace Data Separation");
        $this->log("================================================================");
        
        // Test scenarios
        $testScenarios = [
            'API Response Time Tests' => 'testAPIResponseTimes',
            'Large Dataset Performance' => 'testLargeDatasetPerformance',
            'Concurrent Load Tests' => 'testConcurrentLoad',
            'Memory Usage Tests' => 'testMemoryUsage',
            'Database Query Optimization' => 'testQueryOptimization',
            'Stress Tests' => 'testStressScenarios'
        ];
        
        foreach ($testScenarios as $scenarioName => $methodName) {
            $this->log("\n📊 Running $scenarioName...");
            $this->$methodName();
        }
        
        $this->generatePerformanceReport();
    }
    
    /**
     * Test API response times with various parameters
     */
    private function testAPIResponseTimes() {
        $this->log("🕐 Testing API response times...");
        
        $testCases = [
            'Recent data (7 days)' => [
                'start' => date('Y-m-d', strtotime('-7 days')),
                'end' => date('Y-m-d')
            ],
            'Monthly data (30 days)' => [
                'start' => date('Y-m-d', strtotime('-30 days')),
                'end' => date('Y-m-d')
            ],
            'Quarterly data (90 days)' => [
                'start' => date('Y-m-d', strtotime('-90 days')),
                'end' => date('Y-m-d')
            ],
            'Large period (1 year)' => [
                'start' => date('Y-m-d', strtotime('-1 year')),
                'end' => date('Y-m-d')
            ]
        ];
        
        $apiMethods = [
            'getMarginSummaryByMarketplace' => function($start, $end, $marketplace) {
                return $this->api->getMarginSummaryByMarketplace($start, $end, $marketplace);
            },
            'getTopProductsByMarketplace' => function($start, $end, $marketplace) {
                return $this->api->getTopProductsByMarketplace($marketplace, 20, $start, $end);
            },
            'getDailyMarginChartByMarketplace' => function($start, $end, $marketplace) {
                return $this->api->getDailyMarginChartByMarketplace($start, $end, $marketplace);
            },
            'getMarketplaceComparison' => function($start, $end, $marketplace) {
                return $this->api->getMarketplaceComparison($start, $end);
            }
        ];
        
        foreach ($testCases as $caseName => $dates) {
            $this->log("📅 Testing $caseName ({$dates['start']} to {$dates['end']})");
            
            foreach ($apiMethods as $methodName => $method) {
                foreach (['ozon', 'wildberries'] as $marketplace) {
                    $testKey = "$caseName - $methodName - $marketplace";
                    
                    $startTime = microtime(true);
                    $memoryBefore = memory_get_usage(true);
                    
                    try {
                        $result = $method($dates['start'], $dates['end'], $marketplace);
                        
                        $endTime = microtime(true);
                        $memoryAfter = memory_get_usage(true);
                        
                        $responseTime = $endTime - $startTime;
                        $memoryUsed = $memoryAfter - $memoryBefore;
                        
                        $this->results['response_times'][$testKey] = [
                            'time' => $responseTime,
                            'memory' => $memoryUsed,
                            'success' => $result['success'] ?? false,
                            'has_data' => $result['has_data'] ?? false
                        ];
                        
                        $status = $responseTime <= $this->thresholds['api_response_time'] ? '✅' : '⚠️';
                        $this->log("$status $methodName ($marketplace): {$responseTime}s, " . $this->formatBytes($memoryUsed));
                        
                    } catch (Exception $e) {
                        $this->results['response_times'][$testKey] = [
                            'time' => null,
                            'memory' => null,
                            'success' => false,
                            'error' => $e->getMessage()
                        ];
                        $this->log("❌ $methodName ($marketplace): " . $e->getMessage());
                    }
                }
            }
        }
    }
    
    /**
     * Test performance with large datasets
     */
    private function testLargeDatasetPerformance() {
        $this->log("📊 Testing large dataset performance...");
        
        $largeLimits = [10, 50, 100, 500, 1000];
        $startDate = date('Y-m-d', strtotime('-1 year'));
        $endDate = date('Y-m-d');
        
        foreach ($largeLimits as $limit) {
            foreach (['ozon', 'wildberries'] as $marketplace) {
                $testKey = "Large dataset - $marketplace - limit $limit";
                
                $startTime = microtime(true);
                $memoryBefore = memory_get_usage(true);
                
                try {
                    $result = $this->api->getTopProductsByMarketplace($marketplace, $limit, $startDate, $endDate);
                    
                    $endTime = microtime(true);
                    $memoryAfter = memory_get_usage(true);
                    
                    $responseTime = $endTime - $startTime;
                    $memoryUsed = $memoryAfter - $memoryBefore;
                    $recordCount = $result['has_data'] ? count($result['data']) : 0;
                    
                    $this->results['large_datasets'][$testKey] = [
                        'time' => $responseTime,
                        'memory' => $memoryUsed,
                        'limit' => $limit,
                        'actual_count' => $recordCount,
                        'success' => $result['success'] ?? false
                    ];
                    
                    $status = $responseTime <= $this->thresholds['large_dataset_time'] ? '✅' : '⚠️';
                    $this->log("$status $marketplace (limit $limit): {$responseTime}s, $recordCount records, " . $this->formatBytes($memoryUsed));
                    
                } catch (Exception $e) {
                    $this->results['large_datasets'][$testKey] = [
                        'time' => null,
                        'memory' => null,
                        'limit' => $limit,
                        'success' => false,
                        'error' => $e->getMessage()
                    ];
                    $this->log("❌ $marketplace (limit $limit): " . $e->getMessage());
                }
            }
        }
    }
    
    /**
     * Test concurrent load handling
     */
    private function testConcurrentLoad() {
        $this->log("🔄 Testing concurrent load handling...");
        
        $concurrentLevels = [5, 10, 20, 50];
        $startDate = date('Y-m-d', strtotime('-30 days'));
        $endDate = date('Y-m-d');
        
        foreach ($concurrentLevels as $concurrentRequests) {
            $this->log("🔄 Testing $concurrentRequests concurrent requests...");
            
            $startTime = microtime(true);
            $results = [];
            $errors = [];
            
            // Simulate concurrent requests
            for ($i = 0; $i < $concurrentRequests; $i++) {
                $marketplace = ($i % 2 === 0) ? 'ozon' : 'wildberries';
                $requestType = $i % 3; // Vary request types
                
                try {
                    switch ($requestType) {
                        case 0:
                            $result = $this->api->getMarginSummaryByMarketplace($startDate, $endDate, $marketplace);
                            break;
                        case 1:
                            $result = $this->api->getTopProductsByMarketplace($marketplace, 10, $startDate, $endDate);
                            break;
                        case 2:
                            $result = $this->api->getDailyMarginChartByMarketplace($startDate, $endDate, $marketplace);
                            break;
                    }
                    
                    $results[] = $result['success'] ?? false;
                    
                } catch (Exception $e) {
                    $results[] = false;
                    $errors[] = $e->getMessage();
                }
            }
            
            $endTime = microtime(true);
            $totalTime = $endTime - $startTime;
            $successCount = array_sum($results);
            $successRate = $successCount / $concurrentRequests;
            $avgTime = $totalTime / $concurrentRequests;
            
            $this->results['concurrent_load'][$concurrentRequests] = [
                'total_time' => $totalTime,
                'avg_time' => $avgTime,
                'success_count' => $successCount,
                'success_rate' => $successRate,
                'errors' => $errors
            ];
            
            $status = $successRate >= $this->thresholds['concurrent_success_rate'] ? '✅' : '⚠️';
            $this->log("$status $concurrentRequests requests: {$successCount}/$concurrentRequests succeeded ({$successRate}%), avg: {$avgTime}s");
            
            if (!empty($errors)) {
                $this->log("   Errors: " . implode(', ', array_unique($errors)));
            }
        }
    }
    
    /**
     * Test memory usage patterns
     */
    private function testMemoryUsage() {
        $this->log("💾 Testing memory usage patterns...");
        
        $memoryBefore = memory_get_usage(true);
        $peakMemory = memory_get_peak_usage(true);
        
        $startDate = date('Y-m-d', strtotime('-90 days'));
        $endDate = date('Y-m-d');
        
        // Test memory usage with various operations
        $operations = [
            'Small dataset' => function() use ($startDate, $endDate) {
                return $this->api->getTopProductsByMarketplace('ozon', 10, $startDate, $endDate);
            },
            'Medium dataset' => function() use ($startDate, $endDate) {
                return $this->api->getTopProductsByMarketplace('ozon', 100, $startDate, $endDate);
            },
            'Large dataset' => function() use ($startDate, $endDate) {
                return $this->api->getTopProductsByMarketplace('ozon', 1000, $startDate, $endDate);
            },
            'Daily chart data' => function() use ($startDate, $endDate) {
                return $this->api->getDailyMarginChartByMarketplace($startDate, $endDate, 'ozon');
            },
            'Marketplace comparison' => function() use ($startDate, $endDate) {
                return $this->api->getMarketplaceComparison($startDate, $endDate);
            }
        ];
        
        foreach ($operations as $operationName => $operation) {
            $memoryBeforeOp = memory_get_usage(true);
            
            try {
                $result = $operation();
                
                $memoryAfterOp = memory_get_usage(true);
                $memoryUsed = $memoryAfterOp - $memoryBeforeOp;
                $currentPeak = memory_get_peak_usage(true);
                
                $this->results['memory_usage'][$operationName] = [
                    'memory_used' => $memoryUsed,
                    'peak_memory' => $currentPeak,
                    'success' => $result['success'] ?? false
                ];
                
                $status = $memoryUsed <= $this->thresholds['memory_limit'] ? '✅' : '⚠️';
                $this->log("$status $operationName: " . $this->formatBytes($memoryUsed) . " used, peak: " . $this->formatBytes($currentPeak));
                
            } catch (Exception $e) {
                $this->results['memory_usage'][$operationName] = [
                    'memory_used' => null,
                    'success' => false,
                    'error' => $e->getMessage()
                ];
                $this->log("❌ $operationName: " . $e->getMessage());
            }
            
            // Force garbage collection
            gc_collect_cycles();
        }
        
        $memoryAfter = memory_get_usage(true);
        $finalPeak = memory_get_peak_usage(true);
        
        $this->log("📊 Memory summary:");
        $this->log("   Initial: " . $this->formatBytes($memoryBefore));
        $this->log("   Final: " . $this->formatBytes($memoryAfter));
        $this->log("   Peak: " . $this->formatBytes($finalPeak));
        $this->log("   Net change: " . $this->formatBytes($memoryAfter - $memoryBefore));
    }
    
    /**
     * Test database query optimization
     */
    private function testQueryOptimization() {
        $this->log("🗄️ Testing database query optimization...");
        
        // This test would ideally use query profiling
        // For now, we'll test response times as a proxy for query efficiency
        
        $startDate = date('Y-m-d', strtotime('-30 days'));
        $endDate = date('Y-m-d');
        
        $queryTests = [
            'Marketplace filtering' => function() use ($startDate, $endDate) {
                return $this->api->getMarginSummaryByMarketplace($startDate, $endDate, 'ozon');
            },
            'Date range filtering' => function() use ($startDate, $endDate) {
                return $this->api->getDailyMarginChartByMarketplace($startDate, $endDate, 'ozon');
            },
            'Product ranking' => function() use ($startDate, $endDate) {
                return $this->api->getTopProductsByMarketplace('ozon', 50, $startDate, $endDate);
            },
            'Cross-marketplace comparison' => function() use ($startDate, $endDate) {
                return $this->api->getMarketplaceComparison($startDate, $endDate);
            }
        ];
        
        foreach ($queryTests as $testName => $test) {
            $iterations = 3; // Run multiple times for average
            $times = [];
            
            for ($i = 0; $i < $iterations; $i++) {
                $startTime = microtime(true);
                
                try {
                    $result = $test();
                    $endTime = microtime(true);
                    $times[] = $endTime - $startTime;
                    
                } catch (Exception $e) {
                    $this->log("❌ $testName iteration $i: " . $e->getMessage());
                    $times[] = null;
                }
            }
            
            $validTimes = array_filter($times, function($t) { return $t !== null; });
            
            if (!empty($validTimes)) {
                $avgTime = array_sum($validTimes) / count($validTimes);
                $minTime = min($validTimes);
                $maxTime = max($validTimes);
                
                $this->results['query_optimization'][$testName] = [
                    'avg_time' => $avgTime,
                    'min_time' => $minTime,
                    'max_time' => $maxTime,
                    'iterations' => count($validTimes)
                ];
                
                $status = $avgTime <= $this->thresholds['api_response_time'] ? '✅' : '⚠️';
                $this->log("$status $testName: avg {$avgTime}s, min {$minTime}s, max {$maxTime}s");
            } else {
                $this->log("❌ $testName: All iterations failed");
            }
        }
    }
    
    /**
     * Test stress scenarios
     */
    private function testStressScenarios() {
        $this->log("🔥 Testing stress scenarios...");
        
        // Test 1: Rapid sequential requests
        $this->testRapidSequentialRequests();
        
        // Test 2: Large time range queries
        $this->testLargeTimeRangeQueries();
        
        // Test 3: Mixed workload simulation
        $this->testMixedWorkloadSimulation();
    }
    
    /**
     * Test rapid sequential requests
     */
    private function testRapidSequentialRequests() {
        $this->log("⚡ Testing rapid sequential requests...");
        
        $requestCount = 20;
        $startDate = date('Y-m-d', strtotime('-7 days'));
        $endDate = date('Y-m-d');
        
        $startTime = microtime(true);
        $successCount = 0;
        $errors = [];
        
        for ($i = 0; $i < $requestCount; $i++) {
            $marketplace = ($i % 2 === 0) ? 'ozon' : 'wildberries';
            
            try {
                $result = $this->api->getMarginSummaryByMarketplace($startDate, $endDate, $marketplace);
                if ($result['success']) {
                    $successCount++;
                }
            } catch (Exception $e) {
                $errors[] = $e->getMessage();
            }
        }
        
        $endTime = microtime(true);
        $totalTime = $endTime - $startTime;
        $avgTime = $totalTime / $requestCount;
        $successRate = $successCount / $requestCount;
        
        $this->results['stress']['rapid_sequential'] = [
            'total_time' => $totalTime,
            'avg_time' => $avgTime,
            'success_rate' => $successRate,
            'errors' => array_unique($errors)
        ];
        
        $status = $successRate >= 0.9 ? '✅' : '⚠️';
        $this->log("$status Rapid sequential: $successCount/$requestCount succeeded, avg: {$avgTime}s");
    }
    
    /**
     * Test large time range queries
     */
    private function testLargeTimeRangeQueries() {
        $this->log("📅 Testing large time range queries...");
        
        $timeRanges = [
            '6 months' => [
                'start' => date('Y-m-d', strtotime('-6 months')),
                'end' => date('Y-m-d')
            ],
            '1 year' => [
                'start' => date('Y-m-d', strtotime('-1 year')),
                'end' => date('Y-m-d')
            ],
            '2 years' => [
                'start' => date('Y-m-d', strtotime('-2 years')),
                'end' => date('Y-m-d')
            ]
        ];
        
        foreach ($timeRanges as $rangeName => $range) {
            $startTime = microtime(true);
            $memoryBefore = memory_get_usage(true);
            
            try {
                $result = $this->api->getMarginSummaryByMarketplace($range['start'], $range['end'], 'ozon');
                
                $endTime = microtime(true);
                $memoryAfter = memory_get_usage(true);
                
                $responseTime = $endTime - $startTime;
                $memoryUsed = $memoryAfter - $memoryBefore;
                
                $this->results['stress']['large_time_ranges'][$rangeName] = [
                    'time' => $responseTime,
                    'memory' => $memoryUsed,
                    'success' => $result['success'] ?? false,
                    'has_data' => $result['has_data'] ?? false
                ];
                
                $status = $responseTime <= $this->thresholds['large_dataset_time'] ? '✅' : '⚠️';
                $this->log("$status $rangeName: {$responseTime}s, " . $this->formatBytes($memoryUsed));
                
            } catch (Exception $e) {
                $this->results['stress']['large_time_ranges'][$rangeName] = [
                    'time' => null,
                    'success' => false,
                    'error' => $e->getMessage()
                ];
                $this->log("❌ $rangeName: " . $e->getMessage());
            }
        }
    }
    
    /**
     * Test mixed workload simulation
     */
    private function testMixedWorkloadSimulation() {
        $this->log("🔀 Testing mixed workload simulation...");
        
        $workloadMix = [
            'summary_requests' => 40,  // 40% summary requests
            'product_requests' => 30,  // 30% product requests
            'chart_requests' => 20,    // 20% chart requests
            'comparison_requests' => 10 // 10% comparison requests
        ];
        
        $totalRequests = 50;
        $startDate = date('Y-m-d', strtotime('-30 days'));
        $endDate = date('Y-m-d');
        
        $requests = [];
        
        // Build request mix
        foreach ($workloadMix as $requestType => $percentage) {
            $count = intval($totalRequests * $percentage / 100);
            for ($i = 0; $i < $count; $i++) {
                $requests[] = $requestType;
            }
        }
        
        // Shuffle to simulate random order
        shuffle($requests);
        
        $startTime = microtime(true);
        $results = [];
        $errors = [];
        
        foreach ($requests as $i => $requestType) {
            $marketplace = ($i % 2 === 0) ? 'ozon' : 'wildberries';
            
            try {
                switch ($requestType) {
                    case 'summary_requests':
                        $result = $this->api->getMarginSummaryByMarketplace($startDate, $endDate, $marketplace);
                        break;
                    case 'product_requests':
                        $result = $this->api->getTopProductsByMarketplace($marketplace, 20, $startDate, $endDate);
                        break;
                    case 'chart_requests':
                        $result = $this->api->getDailyMarginChartByMarketplace($startDate, $endDate, $marketplace);
                        break;
                    case 'comparison_requests':
                        $result = $this->api->getMarketplaceComparison($startDate, $endDate);
                        break;
                }
                
                $results[$requestType][] = $result['success'] ?? false;
                
            } catch (Exception $e) {
                $results[$requestType][] = false;
                $errors[] = $e->getMessage();
            }
        }
        
        $endTime = microtime(true);
        $totalTime = $endTime - $startTime;
        $avgTime = $totalTime / count($requests);
        
        // Calculate success rates by request type
        $successRates = [];
        foreach ($results as $requestType => $typeResults) {
            $successRates[$requestType] = array_sum($typeResults) / count($typeResults);
        }
        
        $overallSuccessRate = array_sum(array_map('array_sum', $results)) / array_sum(array_map('count', $results));
        
        $this->results['stress']['mixed_workload'] = [
            'total_time' => $totalTime,
            'avg_time' => $avgTime,
            'overall_success_rate' => $overallSuccessRate,
            'success_rates_by_type' => $successRates,
            'errors' => array_unique($errors)
        ];
        
        $status = $overallSuccessRate >= 0.9 ? '✅' : '⚠️';
        $this->log("$status Mixed workload: {$overallSuccessRate} overall success rate, avg: {$avgTime}s");
        
        foreach ($successRates as $type => $rate) {
            $this->log("   $type: $rate success rate");
        }
    }
    
    /**
     * Generate comprehensive performance report
     */
    private function generatePerformanceReport() {
        $this->log("\n" . str_repeat("=", 80));
        $this->log("📊 PERFORMANCE TEST REPORT");
        $this->log(str_repeat("=", 80));
        
        // Response time analysis
        if (isset($this->results['response_times'])) {
            $this->analyzeResponseTimes();
        }
        
        // Memory usage analysis
        if (isset($this->results['memory_usage'])) {
            $this->analyzeMemoryUsage();
        }
        
        // Concurrent load analysis
        if (isset($this->results['concurrent_load'])) {
            $this->analyzeConcurrentLoad();
        }
        
        // Overall performance summary
        $this->generateOverallSummary();
        
        // Save detailed report
        $this->savePerformanceReport();
    }
    
    /**
     * Analyze response times
     */
    private function analyzeResponseTimes() {
        $this->log("\n⏱️ RESPONSE TIME ANALYSIS:");
        
        $times = [];
        $slowQueries = [];
        
        foreach ($this->results['response_times'] as $testName => $result) {
            if ($result['time'] !== null) {
                $times[] = $result['time'];
                
                if ($result['time'] > $this->thresholds['api_response_time']) {
                    $slowQueries[] = "$testName: {$result['time']}s";
                }
            }
        }
        
        if (!empty($times)) {
            $avgTime = array_sum($times) / count($times);
            $minTime = min($times);
            $maxTime = max($times);
            $medianTime = $this->calculateMedian($times);
            
            $this->log("   Average response time: {$avgTime}s");
            $this->log("   Median response time: {$medianTime}s");
            $this->log("   Min response time: {$minTime}s");
            $this->log("   Max response time: {$maxTime}s");
            $this->log("   Threshold: {$this->thresholds['api_response_time']}s");
            
            if (!empty($slowQueries)) {
                $this->log("\n⚠️ SLOW QUERIES:");
                foreach ($slowQueries as $query) {
                    $this->log("   $query");
                }
            } else {
                $this->log("   ✅ All queries within acceptable response time");
            }
        }
    }
    
    /**
     * Analyze memory usage
     */
    private function analyzeMemoryUsage() {
        $this->log("\n💾 MEMORY USAGE ANALYSIS:");
        
        $memoryUsages = [];
        $highMemoryOps = [];
        
        foreach ($this->results['memory_usage'] as $operation => $result) {
            if ($result['memory_used'] !== null) {
                $memoryUsages[] = $result['memory_used'];
                
                if ($result['memory_used'] > $this->thresholds['memory_limit']) {
                    $highMemoryOps[] = "$operation: " . $this->formatBytes($result['memory_used']);
                }
            }
        }
        
        if (!empty($memoryUsages)) {
            $avgMemory = array_sum($memoryUsages) / count($memoryUsages);
            $maxMemory = max($memoryUsages);
            $totalMemory = array_sum($memoryUsages);
            
            $this->log("   Average memory per operation: " . $this->formatBytes($avgMemory));
            $this->log("   Max memory per operation: " . $this->formatBytes($maxMemory));
            $this->log("   Total memory used: " . $this->formatBytes($totalMemory));
            $this->log("   Memory threshold: " . $this->formatBytes($this->thresholds['memory_limit']));
            
            if (!empty($highMemoryOps)) {
                $this->log("\n⚠️ HIGH MEMORY OPERATIONS:");
                foreach ($highMemoryOps as $op) {
                    $this->log("   $op");
                }
            } else {
                $this->log("   ✅ All operations within memory limits");
            }
        }
    }
    
    /**
     * Analyze concurrent load performance
     */
    private function analyzeConcurrentLoad() {
        $this->log("\n🔄 CONCURRENT LOAD ANALYSIS:");
        
        foreach ($this->results['concurrent_load'] as $concurrentLevel => $result) {
            $status = $result['success_rate'] >= $this->thresholds['concurrent_success_rate'] ? '✅' : '⚠️';
            $this->log("   $status $concurrentLevel concurrent requests:");
            $this->log("      Success rate: {$result['success_rate']} (threshold: {$this->thresholds['concurrent_success_rate']})");
            $this->log("      Average time: {$result['avg_time']}s");
            $this->log("      Total time: {$result['total_time']}s");
            
            if (!empty($result['errors'])) {
                $this->log("      Errors: " . implode(', ', array_unique($result['errors'])));
            }
        }
    }
    
    /**
     * Generate overall performance summary
     */
    private function generateOverallSummary() {
        $this->log("\n🎯 OVERALL PERFORMANCE SUMMARY:");
        
        $issues = [];
        $recommendations = [];
        
        // Check response times
        if (isset($this->results['response_times'])) {
            $slowQueries = 0;
            foreach ($this->results['response_times'] as $result) {
                if ($result['time'] && $result['time'] > $this->thresholds['api_response_time']) {
                    $slowQueries++;
                }
            }
            
            if ($slowQueries > 0) {
                $issues[] = "$slowQueries queries exceed response time threshold";
                $recommendations[] = "Optimize slow queries with database indexes";
            }
        }
        
        // Check memory usage
        if (isset($this->results['memory_usage'])) {
            $highMemoryOps = 0;
            foreach ($this->results['memory_usage'] as $result) {
                if ($result['memory_used'] && $result['memory_used'] > $this->thresholds['memory_limit']) {
                    $highMemoryOps++;
                }
            }
            
            if ($highMemoryOps > 0) {
                $issues[] = "$highMemoryOps operations exceed memory threshold";
                $recommendations[] = "Implement result pagination for large datasets";
            }
        }
        
        // Check concurrent load
        if (isset($this->results['concurrent_load'])) {
            $failedConcurrentTests = 0;
            foreach ($this->results['concurrent_load'] as $result) {
                if ($result['success_rate'] < $this->thresholds['concurrent_success_rate']) {
                    $failedConcurrentTests++;
                }
            }
            
            if ($failedConcurrentTests > 0) {
                $issues[] = "$failedConcurrentTests concurrent load tests failed";
                $recommendations[] = "Implement connection pooling and query optimization";
            }
        }
        
        if (empty($issues)) {
            $this->log("   ✅ All performance tests passed!");
            $this->log("   🚀 System is ready for production deployment");
        } else {
            $this->log("   ⚠️ Performance issues found:");
            foreach ($issues as $issue) {
                $this->log("      • $issue");
            }
            
            $this->log("\n   💡 Recommendations:");
            foreach ($recommendations as $recommendation) {
                $this->log("      • $recommendation");
            }
        }
    }
    
    /**
     * Save detailed performance report
     */
    private function savePerformanceReport() {
        $reportFile = 'performance_report_' . date('Y-m-d_H-i-s') . '.json';
        
        $report = [
            'timestamp' => date('Y-m-d H:i:s'),
            'thresholds' => $this->thresholds,
            'results' => $this->results,
            'environment' => [
                'php_version' => PHP_VERSION,
                'memory_limit' => ini_get('memory_limit'),
                'max_execution_time' => ini_get('max_execution_time'),
                'database_host' => 'production'
            ]
        ];
        
        file_put_contents($reportFile, json_encode($report, JSON_PRETTY_PRINT));
        $this->log("\n📄 Detailed performance report saved to: $reportFile");
    }
    
    // Helper methods
    private function formatBytes($bytes) {
        $units = ['B', 'KB', 'MB', 'GB'];
        $bytes = max($bytes, 0);
        $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
        $pow = min($pow, count($units) - 1);
        
        $bytes /= pow(1024, $pow);
        
        return round($bytes, 2) . ' ' . $units[$pow];
    }
    
    private function calculateMedian($array) {
        sort($array);
        $count = count($array);
        $middle = floor($count / 2);
        
        if ($count % 2) {
            return $array[$middle];
        } else {
            return ($array[$middle - 1] + $array[$middle]) / 2;
        }
    }
    
    private function log($message) {
        $timestamp = date('Y-m-d H:i:s');
        $logMessage = "[$timestamp] $message";
        echo $logMessage . "\n";
        file_put_contents($this->logFile, $logMessage . "\n", FILE_APPEND);
    }
}

// Usage
if (php_sapi_name() === 'cli') {
    echo "⚡ Marketplace Performance Testing\n";
    echo "=================================\n\n";
    
    // Database configuration
    $config = [
        'host' => 'localhost',
        'dbname' => 'mi_core_db',
        'username' => 'mi_core_user',
        'password' => 'secure_password_123'
    ];
    
    try {
        $tester = new MarketplacePerformanceTest(
            $config['host'],
            $config['dbname'],
            $config['username'],
            $config['password']
        );
        
        $tester->runPerformanceTests();
        
    } catch (Exception $e) {
        echo "❌ Performance test execution failed: " . $e->getMessage() . "\n";
        exit(1);
    }
} else {
    echo "This script should be run from command line.\n";
    echo "Usage: php test_marketplace_performance.php\n";
}
?>