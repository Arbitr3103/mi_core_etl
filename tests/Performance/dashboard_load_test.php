<?php
/**
 * Load Test for Dashboard API with Active Product Filtering
 * 
 * Tests dashboard API performance under various load conditions
 * Requirements: 2.4 - Performance optimization for active product filtering
 */

require_once __DIR__ . '/../../config.php';

class DashboardLoadTest
{
    private $baseUrl;
    private $results = [];
    private $errors = [];

    public function __construct()
    {
        $this->baseUrl = 'http://localhost/api/inventory-analytics.php';
    }

    /**
     * Run all load tests
     */
    public function runAllTests(): void
    {
        echo "=== DASHBOARD API LOAD TESTING ===\n\n";
        
        $this->testBasicLoadPerformance();
        $this->testHighFrequencyRequests();
        $this->testConcurrentUserSimulation();
        $this->testEndpointSpecificLoad();
        $this->testDataVolumeImpact();
        
        $this->generateLoadTestReport();
    }

    /**
     * Test basic load performance
     */
    private function testBasicLoadPerformance(): void
    {
        echo "1. 🚀 Basic Load Performance Test:\n";
        
        $endpoints = [
            'critical_stock' => '?action=critical_stock&active_only=true',
            'low_stock' => '?action=low_stock&active_only=true',
            'overstock' => '?action=overstock&active_only=true',
            'activity_stats' => '?action=activity_stats'
        ];

        $requestsPerEndpoint = 25;
        $overallResults = [];

        foreach ($endpoints as $name => $endpoint) {
            echo "  Testing endpoint: {$name}\n";
            
            $times = [];
            $errors = 0;
            $responseSizes = [];

            $startTime = microtime(true);

            for ($i = 0; $i < $requestsPerEndpoint; $i++) {
                $requestStart = microtime(true);
                
                try {
                    $response = $this->makeRequest($endpoint);
                    
                    if ($response === null) {
                        $errors++;
                    } else {
                        $responseSizes[] = strlen(json_encode($response));
                    }
                } catch (Exception $e) {
                    $errors++;
                    $this->errors[] = "Basic load test failed for {$name}: " . $e->getMessage();
                }
                
                $requestEnd = microtime(true);
                $times[] = ($requestEnd - $requestStart) * 1000;
            }

            $totalTime = microtime(true) - $startTime;
            $avgTime = array_sum($times) / count($times);
            $maxTime = max($times);
            $minTime = min($times);
            $requestsPerSecond = $requestsPerEndpoint / $totalTime;
            $avgResponseSize = count($responseSizes) > 0 ? array_sum($responseSizes) / count($responseSizes) : 0;

            $endpointResults = [
                'endpoint' => $name,
                'total_requests' => $requestsPerEndpoint,
                'total_time' => $totalTime,
                'avg_time' => $avgTime,
                'max_time' => $maxTime,
                'min_time' => $minTime,
                'requests_per_second' => $requestsPerSecond,
                'errors' => $errors,
                'success_rate' => (($requestsPerEndpoint - $errors) / $requestsPerEndpoint) * 100,
                'avg_response_size' => $avgResponseSize
            ];

            $overallResults[$name] = $endpointResults;

            echo "    Requests/sec: " . number_format($requestsPerSecond, 2) . "\n";
            echo "    Avg time: " . number_format($avgTime, 2) . " ms\n";
            echo "    Max time: " . number_format($maxTime, 2) . " ms\n";
            echo "    Success rate: " . number_format($endpointResults['success_rate'], 1) . "%\n";
            echo "    Avg response size: " . $this->formatBytes($avgResponseSize) . "\n";

            if ($requestsPerSecond > 20 && $errors == 0) {
                echo "    ✅ Excellent performance\n";
            } elseif ($requestsPerSecond > 10 && $errors < 3) {
                echo "    ✅ Good performance\n";
            } else {
                echo "    ❌ Performance needs improvement\n";
            }
            echo "\n";
        }

        $this->results['basic_load'] = $overallResults;
    }

    /**
     * Test high frequency requests
     */
    private function testHighFrequencyRequests(): void
    {
        echo "2. ⚡ High Frequency Request Test:\n";
        
        $endpoint = '?action=activity_stats';
        $requestCount = 100;
        $times = [];
        $errors = 0;

        echo "  Sending {$requestCount} rapid requests...\n";

        $startTime = microtime(true);

        for ($i = 0; $i < $requestCount; $i++) {
            $requestStart = microtime(true);
            
            try {
                $response = $this->makeRequest($endpoint);
                
                if ($response === null) {
                    $errors++;
                }
            } catch (Exception $e) {
                $errors++;
                $this->errors[] = "High frequency test failed at request {$i}: " . $e->getMessage();
            }
            
            $requestEnd = microtime(true);
            $times[] = ($requestEnd - $requestStart) * 1000;

            // No delay - test maximum frequency
        }

        $totalTime = microtime(true) - $startTime;
        $avgTime = array_sum($times) / count($times);
        $maxTime = max($times);
        $minTime = min($times);
        $requestsPerSecond = $requestCount / $totalTime;

        $results = [
            'total_requests' => $requestCount,
            'total_time' => $totalTime,
            'requests_per_second' => $requestsPerSecond,
            'avg_time' => $avgTime,
            'max_time' => $maxTime,
            'min_time' => $minTime,
            'errors' => $errors,
            'success_rate' => (($requestCount - $errors) / $requestCount) * 100
        ];

        echo "  Total time: " . number_format($totalTime, 2) . " seconds\n";
        echo "  Requests/sec: " . number_format($requestsPerSecond, 2) . "\n";
        echo "  Avg response time: " . number_format($avgTime, 2) . " ms\n";
        echo "  Max response time: " . number_format($maxTime, 2) . " ms\n";
        echo "  Min response time: " . number_format($minTime, 2) . " ms\n";
        echo "  Errors: {$errors}/{$requestCount}\n";
        echo "  Success rate: " . number_format($results['success_rate'], 1) . "%\n";

        if ($requestsPerSecond > 50 && $errors == 0) {
            echo "  ✅ Excellent high-frequency performance\n";
        } elseif ($requestsPerSecond > 25 && $errors < 5) {
            echo "  ✅ Good high-frequency performance\n";
        } else {
            echo "  ❌ High-frequency performance needs improvement\n";
        }

        $this->results['high_frequency'] = $results;
        echo "\n";
    }

    /**
     * Test concurrent user simulation
     */
    private function testConcurrentUserSimulation(): void
    {
        echo "3. 👥 Concurrent User Simulation:\n";
        
        $concurrentUsers = 15;
        $requestsPerUser = 8;
        $userResults = [];

        echo "  Simulating {$concurrentUsers} concurrent users, {$requestsPerUser} requests each...\n";

        $overallStartTime = microtime(true);

        for ($user = 0; $user < $concurrentUsers; $user++) {
            $userTimes = [];
            $userErrors = 0;

            for ($request = 0; $request < $requestsPerUser; $request++) {
                $requestStart = microtime(true);
                
                // Vary endpoints to simulate real user behavior
                $endpoints = [
                    '?action=critical_stock&active_only=true',
                    '?action=low_stock&active_only=true',
                    '?action=activity_stats',
                    '?action=inactive_products'
                ];
                
                $endpoint = $endpoints[$request % count($endpoints)];

                try {
                    $response = $this->makeRequest($endpoint);
                    
                    if ($response === null) {
                        $userErrors++;
                    }
                } catch (Exception $e) {
                    $userErrors++;
                    $this->errors[] = "Concurrent user {$user} request {$request} failed: " . $e->getMessage();
                }
                
                $requestEnd = microtime(true);
                $userTimes[] = ($requestEnd - $requestStart) * 1000;

                // Simulate user think time
                usleep(rand(50000, 200000)); // 50-200ms
            }

            $userResults[] = [
                'user_id' => $user,
                'avg_time' => array_sum($userTimes) / count($userTimes),
                'max_time' => max($userTimes),
                'errors' => $userErrors,
                'success_rate' => (($requestsPerUser - $userErrors) / $requestsPerUser) * 100
            ];
        }

        $overallTime = microtime(true) - $overallStartTime;
        $totalRequests = $concurrentUsers * $requestsPerUser;
        $totalErrors = array_sum(array_column($userResults, 'errors'));
        $avgUserTime = array_sum(array_column($userResults, 'avg_time')) / count($userResults);
        $maxUserTime = max(array_column($userResults, 'max_time'));
        $overallThroughput = $totalRequests / $overallTime;

        $results = [
            'concurrent_users' => $concurrentUsers,
            'requests_per_user' => $requestsPerUser,
            'total_requests' => $totalRequests,
            'overall_time' => $overallTime,
            'throughput' => $overallThroughput,
            'avg_user_response_time' => $avgUserTime,
            'max_user_response_time' => $maxUserTime,
            'total_errors' => $totalErrors,
            'overall_success_rate' => (($totalRequests - $totalErrors) / $totalRequests) * 100,
            'user_results' => $userResults
        ];

        echo "  Overall time: " . number_format($overallTime, 2) . " seconds\n";
        echo "  Throughput: " . number_format($overallThroughput, 2) . " requests/sec\n";
        echo "  Avg user response time: " . number_format($avgUserTime, 2) . " ms\n";
        echo "  Max user response time: " . number_format($maxUserTime, 2) . " ms\n";
        echo "  Total errors: {$totalErrors}/{$totalRequests}\n";
        echo "  Overall success rate: " . number_format($results['overall_success_rate'], 1) . "%\n";

        if ($overallThroughput > 30 && $totalErrors == 0) {
            echo "  ✅ Excellent concurrent performance\n";
        } elseif ($overallThroughput > 15 && $totalErrors < 10) {
            echo "  ✅ Good concurrent performance\n";
        } else {
            echo "  ❌ Concurrent performance needs improvement\n";
        }

        $this->results['concurrent_users'] = $results;
        echo "\n";
    }

    /**
     * Test endpoint-specific load characteristics
     */
    private function testEndpointSpecificLoad(): void
    {
        echo "4. 🎯 Endpoint-Specific Load Test:\n";
        
        $endpointTests = [
            'activity_stats' => [
                'endpoint' => '?action=activity_stats',
                'expected_complexity' => 'medium',
                'requests' => 30
            ],
            'inactive_products' => [
                'endpoint' => '?action=inactive_products',
                'expected_complexity' => 'high',
                'requests' => 20
            ],
            'critical_stock_active' => [
                'endpoint' => '?action=critical_stock&active_only=true',
                'expected_complexity' => 'medium',
                'requests' => 25
            ],
            'activity_changes' => [
                'endpoint' => '?action=activity_changes&date_from=2024-01-01&date_to=2024-12-31',
                'expected_complexity' => 'high',
                'requests' => 15
            ]
        ];

        $endpointResults = [];

        foreach ($endpointTests as $name => $test) {
            echo "  Testing {$name} endpoint ({$test['expected_complexity']} complexity)...\n";
            
            $times = [];
            $errors = 0;
            $responseSizes = [];

            for ($i = 0; $i < $test['requests']; $i++) {
                $requestStart = microtime(true);
                
                try {
                    $response = $this->makeRequest($test['endpoint']);
                    
                    if ($response === null) {
                        $errors++;
                    } else {
                        $responseSizes[] = strlen(json_encode($response));
                    }
                } catch (Exception $e) {
                    $errors++;
                    $this->errors[] = "Endpoint {$name} failed: " . $e->getMessage();
                }
                
                $requestEnd = microtime(true);
                $times[] = ($requestEnd - $requestStart) * 1000;
            }

            $avgTime = array_sum($times) / count($times);
            $maxTime = max($times);
            $minTime = min($times);
            $avgResponseSize = count($responseSizes) > 0 ? array_sum($responseSizes) / count($responseSizes) : 0;

            $endpointResults[$name] = [
                'endpoint' => $test['endpoint'],
                'complexity' => $test['expected_complexity'],
                'requests' => $test['requests'],
                'avg_time' => $avgTime,
                'max_time' => $maxTime,
                'min_time' => $minTime,
                'errors' => $errors,
                'success_rate' => (($test['requests'] - $errors) / $test['requests']) * 100,
                'avg_response_size' => $avgResponseSize
            ];

            echo "    Avg time: " . number_format($avgTime, 2) . " ms\n";
            echo "    Max time: " . number_format($maxTime, 2) . " ms\n";
            echo "    Success rate: " . number_format($endpointResults[$name]['success_rate'], 1) . "%\n";
            echo "    Avg response size: " . $this->formatBytes($avgResponseSize) . "\n";

            // Performance expectations based on complexity
            $timeThreshold = $test['expected_complexity'] === 'high' ? 1000 : 500;
            
            if ($avgTime < $timeThreshold && $errors == 0) {
                echo "    ✅ Performance meets expectations\n";
            } elseif ($avgTime < $timeThreshold * 2 && $errors < 2) {
                echo "    ✅ Performance acceptable\n";
            } else {
                echo "    ❌ Performance below expectations\n";
            }
            echo "\n";
        }

        $this->results['endpoint_specific'] = $endpointResults;
    }

    /**
     * Test impact of data volume on performance
     */
    private function testDataVolumeImpact(): void
    {
        echo "5. 📊 Data Volume Impact Test:\n";
        
        // Test with different filter parameters that affect data volume
        $volumeTests = [
            'small_dataset' => '?action=critical_stock&active_only=true&limit=10',
            'medium_dataset' => '?action=low_stock&active_only=true&limit=50',
            'large_dataset' => '?action=activity_stats',
            'full_dataset' => '?action=inactive_products'
        ];

        $volumeResults = [];

        foreach ($volumeTests as $name => $endpoint) {
            echo "  Testing {$name}...\n";
            
            $times = [];
            $errors = 0;
            $responseSizes = [];
            $iterations = 10;

            for ($i = 0; $i < $iterations; $i++) {
                $requestStart = microtime(true);
                
                try {
                    $response = $this->makeRequest($endpoint);
                    
                    if ($response === null) {
                        $errors++;
                    } else {
                        $responseSizes[] = strlen(json_encode($response));
                        
                        // Count data points if possible
                        if (isset($response['data']) && is_array($response['data'])) {
                            $dataPoints = count($response['data']);
                        } else {
                            $dataPoints = 0;
                        }
                    }
                } catch (Exception $e) {
                    $errors++;
                    $this->errors[] = "Volume test {$name} failed: " . $e->getMessage();
                }
                
                $requestEnd = microtime(true);
                $times[] = ($requestEnd - $requestStart) * 1000;
            }

            $avgTime = array_sum($times) / count($times);
            $avgResponseSize = count($responseSizes) > 0 ? array_sum($responseSizes) / count($responseSizes) : 0;

            $volumeResults[$name] = [
                'endpoint' => $endpoint,
                'avg_time' => $avgTime,
                'max_time' => max($times),
                'min_time' => min($times),
                'avg_response_size' => $avgResponseSize,
                'errors' => $errors,
                'success_rate' => (($iterations - $errors) / $iterations) * 100
            ];

            echo "    Avg time: " . number_format($avgTime, 2) . " ms\n";
            echo "    Avg response size: " . $this->formatBytes($avgResponseSize) . "\n";
            echo "    Success rate: " . number_format($volumeResults[$name]['success_rate'], 1) . "%\n";
            echo "\n";
        }

        $this->results['data_volume'] = $volumeResults;
    }

    /**
     * Make HTTP request to API
     */
    private function makeRequest(string $endpoint): ?array
    {
        $url = $this->baseUrl . $endpoint;
        
        $context = stream_context_create([
            'http' => [
                'method' => 'GET',
                'timeout' => 30,
                'header' => [
                    'Content-Type: application/json',
                    'User-Agent: DashboardLoadTest/1.0'
                ]
            ]
        ]);
        
        $response = @file_get_contents($url, false, $context);
        
        if ($response === false) {
            return null;
        }
        
        return json_decode($response, true);
    }

    /**
     * Format bytes for display
     */
    private function formatBytes(int $bytes, int $precision = 2): string
    {
        $units = ['B', 'KB', 'MB', 'GB'];
        
        for ($i = 0; $bytes > 1024 && $i < count($units) - 1; $i++) {
            $bytes /= 1024;
        }
        
        return round($bytes, $precision) . ' ' . $units[$i];
    }

    /**
     * Generate comprehensive load test report
     */
    private function generateLoadTestReport(): void
    {
        echo "=== LOAD TEST SUMMARY REPORT ===\n\n";
        
        $overallScore = 0;
        $maxScore = 0;

        // Analyze basic load performance
        if (isset($this->results['basic_load'])) {
            echo "📈 BASIC LOAD PERFORMANCE:\n";
            $basicResults = $this->results['basic_load'];
            $avgPerformance = 0;
            
            foreach ($basicResults as $endpoint => $result) {
                echo "  {$endpoint}: " . number_format($result['requests_per_second'], 2) . " req/sec, " .
                     number_format($result['success_rate'], 1) . "% success\n";
                
                if ($result['requests_per_second'] > 20 && $result['success_rate'] > 95) {
                    $avgPerformance += 25;
                } elseif ($result['requests_per_second'] > 10 && $result['success_rate'] > 90) {
                    $avgPerformance += 20;
                } else {
                    $avgPerformance += 10;
                }
            }
            
            $avgPerformance = $avgPerformance / count($basicResults);
            $overallScore += $avgPerformance;
            $maxScore += 25;
            echo "\n";
        }

        // Analyze high frequency performance
        if (isset($this->results['high_frequency'])) {
            echo "⚡ HIGH FREQUENCY PERFORMANCE:\n";
            $hf = $this->results['high_frequency'];
            echo "  Throughput: " . number_format($hf['requests_per_second'], 2) . " req/sec\n";
            echo "  Success rate: " . number_format($hf['success_rate'], 1) . "%\n";
            echo "  Avg response time: " . number_format($hf['avg_time'], 2) . " ms\n";
            
            if ($hf['requests_per_second'] > 50 && $hf['success_rate'] > 95) {
                $overallScore += 25;
                echo "  🏆 Excellent high-frequency performance\n";
            } elseif ($hf['requests_per_second'] > 25 && $hf['success_rate'] > 90) {
                $overallScore += 20;
                echo "  ✅ Good high-frequency performance\n";
            } else {
                $overallScore += 10;
                echo "  ⚠️ High-frequency performance needs improvement\n";
            }
            $maxScore += 25;
            echo "\n";
        }

        // Analyze concurrent user performance
        if (isset($this->results['concurrent_users'])) {
            echo "👥 CONCURRENT USER PERFORMANCE:\n";
            $cu = $this->results['concurrent_users'];
            echo "  Throughput: " . number_format($cu['throughput'], 2) . " req/sec\n";
            echo "  Success rate: " . number_format($cu['overall_success_rate'], 1) . "%\n";
            echo "  Avg user response time: " . number_format($cu['avg_user_response_time'], 2) . " ms\n";
            
            if ($cu['throughput'] > 30 && $cu['overall_success_rate'] > 95) {
                $overallScore += 25;
                echo "  🏆 Excellent concurrent performance\n";
            } elseif ($cu['throughput'] > 15 && $cu['overall_success_rate'] > 90) {
                $overallScore += 20;
                echo "  ✅ Good concurrent performance\n";
            } else {
                $overallScore += 10;
                echo "  ⚠️ Concurrent performance needs improvement\n";
            }
            $maxScore += 25;
            echo "\n";
        }

        // Error analysis
        if (count($this->errors) == 0) {
            $overallScore += 25;
            $maxScore += 25;
            echo "✅ NO ERRORS DETECTED\n\n";
        } else {
            $maxScore += 25;
            echo "❌ ERRORS DETECTED:\n";
            foreach (array_slice($this->errors, 0, 5) as $error) {
                echo "  • {$error}\n";
            }
            if (count($this->errors) > 5) {
                echo "  • ... and " . (count($this->errors) - 5) . " more errors\n";
            }
            echo "\n";
        }

        // Overall assessment
        $scorePercentage = $maxScore > 0 ? ($overallScore / $maxScore) * 100 : 0;
        
        echo "🎯 OVERALL LOAD TEST SCORE: " . number_format($scorePercentage, 1) . "%\n";
        
        if ($scorePercentage >= 90) {
            echo "🏆 EXCELLENT! System handles load very well.\n";
        } elseif ($scorePercentage >= 75) {
            echo "✅ GOOD! System performs well under load.\n";
        } elseif ($scorePercentage >= 60) {
            echo "⚠️ ACCEPTABLE! Some optimization recommended.\n";
        } else {
            echo "❌ POOR! Significant optimization required.\n";
        }

        // Recommendations
        echo "\n📋 OPTIMIZATION RECOMMENDATIONS:\n";
        
        if (isset($this->results['basic_load'])) {
            $slowEndpoints = array_filter($this->results['basic_load'], function($result) {
                return $result['avg_time'] > 500;
            });
            
            if (!empty($slowEndpoints)) {
                echo "• Optimize slow endpoints: " . implode(', ', array_keys($slowEndpoints)) . "\n";
            }
        }
        
        if (count($this->errors) > 0) {
            echo "• Fix error handling and stability issues\n";
        }
        
        echo "• Consider implementing response caching\n";
        echo "• Monitor database query performance\n";
        echo "• Consider connection pooling for high concurrency\n";
        
        // Save detailed report
        $reportFile = __DIR__ . '/../results/dashboard_load_test_' . date('Ymd_His') . '.json';
        $reportDir = dirname($reportFile);
        
        if (!is_dir($reportDir)) {
            mkdir($reportDir, 0755, true);
        }
        
        $report = [
            'test_timestamp' => date('Y-m-d H:i:s'),
            'overall_score' => $scorePercentage,
            'results' => $this->results,
            'errors' => $this->errors
        ];
        
        file_put_contents($reportFile, json_encode($report, JSON_PRETTY_PRINT));
        
        echo "\n📄 Detailed report saved to: {$reportFile}\n";
        echo str_repeat("=", 60) . "\n";
    }
}

// Run load tests if executed directly
if (php_sapi_name() === 'cli') {
    $loadTest = new DashboardLoadTest();
    $loadTest->runAllTests();
} else {
    header('Content-Type: text/plain; charset=utf-8');
    $loadTest = new DashboardLoadTest();
    $loadTest->runAllTests();
}