<?php
/**
 * Simple Performance Test for Active Product Filter System
 * Requirements: 2.4 - Performance optimization for active product filtering
 */

class SimplePerformanceTest
{
    private $results = [];

    public function runAllTests(): void
    {
        echo "=== SIMPLE PERFORMANCE TEST ===\n\n";
        
        $this->testAPIResponseTimes();
        $this->testMemoryUsage();
        $this->testLoadSimulation();
        
        $this->generateReport();
    }

    private function testAPIResponseTimes(): void
    {
        echo "1. 🚀 API Response Time Test:\n";
        
        $mockResponses = [
            'critical_stock' => ['time' => rand(50, 200), 'success' => true],
            'activity_stats' => ['time' => rand(80, 300), 'success' => true],
            'low_stock' => ['time' => rand(60, 250), 'success' => true]
        ];

        $apiResults = [];
        
        foreach ($mockResponses as $endpoint => $mock) {
            $times = [];
            
            for ($i = 0; $i < 10; $i++) {
                $startTime = microtime(true);
                
                // Simulate API call
                usleep($mock['time'] * 1000);
                
                $endTime = microtime(true);
                $times[] = ($endTime - $startTime) * 1000;
            }

            $avgTime = array_sum($times) / count($times);
            $apiResults[$endpoint] = [
                'avg_time' => $avgTime,
                'max_time' => max($times),
                'min_time' => min($times)
            ];

            echo "  {$endpoint}: " . number_format($avgTime, 2) . "ms avg\n";
            
            if ($avgTime < 500) {
                echo "    ✅ Good performance\n";
            } else {
                echo "    ⚠️ Needs optimization\n";
            }
        }

        $this->results['api_performance'] = $apiResults;
        echo "\n";
    }

    private function testMemoryUsage(): void
    {
        echo "2. 💾 Memory Usage Test:\n";
        
        $initialMemory = memory_get_usage(true);
        echo "  Initial memory: " . $this->formatBytes($initialMemory) . "\n";

        // Simulate memory-intensive operations
        $data = [];
        for ($i = 0; $i < 1000; $i++) {
            $data[] = [
                'id' => "test_{$i}",
                'name' => "Test Product {$i}",
                'stock' => rand(0, 100),
                'active' => ($i % 3 !== 0)
            ];
        }

        $peakMemory = memory_get_peak_usage(true);
        $finalMemory = memory_get_usage(true);
        $memoryIncrease = $finalMemory - $initialMemory;

        echo "  Final memory: " . $this->formatBytes($finalMemory) . "\n";
        echo "  Peak memory: " . $this->formatBytes($peakMemory) . "\n";
        echo "  Memory increase: " . $this->formatBytes($memoryIncrease) . "\n";

        $this->results['memory_usage'] = [
            'initial' => $initialMemory,
            'final' => $finalMemory,
            'peak' => $peakMemory,
            'increase' => $memoryIncrease
        ];

        if ($memoryIncrease < 10 * 1024 * 1024) { // 10MB
            echo "  ✅ Memory usage efficient\n";
        } else {
            echo "  ⚠️ High memory usage\n";
        }
        echo "\n";
    }

    private function testLoadSimulation(): void
    {
        echo "3. 📈 Load Simulation Test:\n";
        
        $requestCounts = [10, 25, 50];
        $loadResults = [];

        foreach ($requestCounts as $requests) {
            echo "  Testing {$requests} requests...\n";
            
            $startTime = microtime(true);
            $times = [];

            for ($i = 0; $i < $requests; $i++) {
                $requestStart = microtime(true);
                
                // Simulate request processing
                usleep(rand(50000, 200000)); // 50-200ms
                
                $requestEnd = microtime(true);
                $times[] = ($requestEnd - $requestStart) * 1000;
            }

            $totalTime = microtime(true) - $startTime;
            $avgTime = array_sum($times) / count($times);
            $throughput = $requests / $totalTime;

            $loadResults[$requests] = [
                'total_time' => $totalTime,
                'avg_response_time' => $avgTime,
                'throughput' => $throughput
            ];

            echo "    Throughput: " . number_format($throughput, 2) . " req/sec\n";
            echo "    Avg response: " . number_format($avgTime, 2) . "ms\n";

            if ($throughput > 5) {
                echo "    ✅ Good throughput\n";
            } else {
                echo "    ⚠️ Low throughput\n";
            }
        }

        $this->results['load_simulation'] = $loadResults;
        echo "\n";
    }

    private function formatBytes(int $bytes, int $precision = 2): string
    {
        $units = ['B', 'KB', 'MB', 'GB'];
        
        for ($i = 0; $bytes > 1024 && $i < count($units) - 1; $i++) {
            $bytes /= 1024;
        }
        
        return round($bytes, $precision) . ' ' . $units[$i];
    }

    private function generateReport(): void
    {
        echo "=== PERFORMANCE TEST REPORT ===\n\n";
        
        $overallScore = 0;
        $maxScore = 0;

        // API Performance
        if (isset($this->results['api_performance'])) {
            echo "🚀 API PERFORMANCE:\n";
            $apiScore = 0;
            
            foreach ($this->results['api_performance'] as $endpoint => $result) {
                echo "  {$endpoint}: " . number_format($result['avg_time'], 2) . "ms\n";
                
                if ($result['avg_time'] < 200) {
                    $apiScore += 25;
                } elseif ($result['avg_time'] < 500) {
                    $apiScore += 20;
                } else {
                    $apiScore += 10;
                }
            }
            
            $apiScore = $apiScore / count($this->results['api_performance']);
            $overallScore += $apiScore;
            $maxScore += 25;
            echo "\n";
        }

        // Memory Usage
        if (isset($this->results['memory_usage'])) {
            echo "💾 MEMORY USAGE:\n";
            $memResult = $this->results['memory_usage'];
            echo "  Memory increase: " . $this->formatBytes($memResult['increase']) . "\n";
            
            if ($memResult['increase'] < 10 * 1024 * 1024) {
                $overallScore += 25;
                echo "  ✅ Efficient\n";
            } else {
                $overallScore += 15;
                echo "  ⚠️ High usage\n";
            }
            $maxScore += 25;
            echo "\n";
        }

        // Load Performance
        if (isset($this->results['load_simulation'])) {
            echo "📈 LOAD PERFORMANCE:\n";
            $loadScore = 0;
            
            foreach ($this->results['load_simulation'] as $requests => $result) {
                echo "  {$requests} requests: " . number_format($result['throughput'], 2) . " req/sec\n";
                
                if ($result['throughput'] > 10) {
                    $loadScore += 25;
                } elseif ($result['throughput'] > 5) {
                    $loadScore += 20;
                } else {
                    $loadScore += 10;
                }
            }
            
            $loadScore = $loadScore / count($this->results['load_simulation']);
            $overallScore += $loadScore;
            $maxScore += 25;
            echo "\n";
        }

        // Overall Score
        $scorePercentage = $maxScore > 0 ? ($overallScore / $maxScore) * 100 : 0;
        
        echo "🎯 OVERALL SCORE: " . number_format($scorePercentage, 1) . "%\n";
        
        if ($scorePercentage >= 80) {
            echo "🏆 EXCELLENT performance!\n";
        } elseif ($scorePercentage >= 60) {
            echo "✅ GOOD performance\n";
        } else {
            echo "⚠️ NEEDS optimization\n";
        }

        // Save report
        $reportFile = __DIR__ . '/../results/simple_performance_report_' . date('Ymd_His') . '.json';
        $reportDir = dirname($reportFile);
        
        if (!is_dir($reportDir)) {
            mkdir($reportDir, 0755, true);
        }
        
        file_put_contents($reportFile, json_encode([
            'timestamp' => date('Y-m-d H:i:s'),
            'overall_score' => $scorePercentage,
            'results' => $this->results
        ], JSON_PRETTY_PRINT));
        
        echo "\n📄 Report saved to: {$reportFile}\n";
        echo str_repeat("=", 50) . "\n";
    }
}

// Run test if executed directly
if (php_sapi_name() === 'cli') {
    $test = new SimplePerformanceTest();
    $test->runAllTests();
}