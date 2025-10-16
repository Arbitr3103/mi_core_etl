<?php
/**
 * Database Performance Benchmark for Active Product Filtering
 * 
 * Tests database performance with various data volumes and query patterns
 * Requirements: 2.4 - Performance optimization for active product filtering
 */

require_once __DIR__ . '/../../config.php';

class DatabasePerformanceBenchmark
{
    private $pdo;
    private $results = [];
    private $testDataCreated = [];

    public function __construct()
    {
        $this->setupDatabaseConnection();
    }

    private function setupDatabaseConnection(): void
    {
        try {
            $this->pdo = new PDO(
                "mysql:host=localhost;dbname=zuz_inventory;charset=utf8mb4",
                "root",
                "",
                [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4"
                ]
            );
        } catch (PDOException $e) {
            die("Database connection failed: " . $e->getMessage());
        }
    }

    /**
     * Run all database performance benchmarks
     */
    public function runAllBenchmarks(): void
    {
        echo "=== DATABASE PERFORMANCE BENCHMARK ===\n\n";
        
        $this->benchmarkCurrentPerformance();
        $this->benchmarkWithVariousDataVolumes();
        $this->benchmarkQueryOptimizations();
        $this->benchmarkIndexEffectiveness();
        $this->benchmarkConcurrentQueries();
        
        $this->generateBenchmarkReport();
        $this->cleanup();
    }

    /**
     * Benchmark current system performance
     */
    private function benchmarkCurrentPerformance(): void
    {
        echo "1. 📊 Current System Performance Benchmark:\n";
        
        $queries = [
            'active_products_count' => [
                'sql' => "SELECT COUNT(*) as count FROM products WHERE is_active = 1",
                'description' => 'Count active products'
            ],
            'active_with_stock' => [
                'sql' => "
                    SELECT COUNT(*) as count 
                    FROM products p 
                    JOIN inventory_data i ON p.id = i.product_id 
                    WHERE p.is_active = 1 AND i.stock_quantity > 0
                ",
                'description' => 'Active products with stock'
            ],
            'critical_stock_active' => [
                'sql' => "
                    SELECT p.id, p.name, i.stock_quantity 
                    FROM products p 
                    JOIN inventory_data i ON p.id = i.product_id 
                    WHERE p.is_active = 1 AND i.stock_quantity < 10 AND i.stock_quantity > 0
                    ORDER BY i.stock_quantity ASC
                    LIMIT 20
                ",
                'description' => 'Critical stock for active products'
            ],
            'activity_summary' => [
                'sql' => "
                    SELECT 
                        is_active,
                        COUNT(*) as product_count,
                        AVG(CASE WHEN i.stock_quantity IS NOT NULL THEN i.stock_quantity ELSE 0 END) as avg_stock,
                        SUM(CASE WHEN i.stock_quantity > 0 THEN 1 ELSE 0 END) as products_with_stock
                    FROM products p 
                    LEFT JOIN inventory_data i ON p.id = i.product_id 
                    GROUP BY is_active
                ",
                'description' => 'Activity summary with stock analysis'
            ],
            'recent_activity_changes' => [
                'sql' => "
                    SELECT COUNT(*) as changes
                    FROM product_activity_log 
                    WHERE changed_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
                ",
                'description' => 'Recent activity changes (7 days)'
            ]
        ];

        $currentResults = [];

        foreach ($queries as $name => $query) {
            echo "  Testing: {$query['description']}\n";
            
            $times = [];
            $resultCounts = [];
            $iterations = 10;

            for ($i = 0; $i < $iterations; $i++) {
                $startTime = microtime(true);
                
                try {
                    $stmt = $this->pdo->prepare($query['sql']);
                    $stmt->execute();
                    $result = $stmt->fetchAll();
                    $resultCounts[] = count($result);
                } catch (PDOException $e) {
                    echo "    ❌ Query failed: " . $e->getMessage() . "\n";
                    continue;
                }
                
                $endTime = microtime(true);
                $times[] = ($endTime - $startTime) * 1000;
            }

            if (!empty($times)) {
                $avgTime = array_sum($times) / count($times);
                $maxTime = max($times);
                $minTime = min($times);
                $avgResults = array_sum($resultCounts) / count($resultCounts);

                $currentResults[$name] = [
                    'description' => $query['description'],
                    'avg_time' => $avgTime,
                    'max_time' => $maxTime,
                    'min_time' => $minTime,
                    'avg_results' => $avgResults,
                    'iterations' => count($times)
                ];

                echo "    Avg time: " . number_format($avgTime, 2) . " ms\n";
                echo "    Max time: " . number_format($maxTime, 2) . " ms\n";
                echo "    Avg results: " . number_format($avgResults, 0) . "\n";

                if ($avgTime < 100) {
                    echo "    ✅ Excellent performance\n";
                } elseif ($avgTime < 500) {
                    echo "    ✅ Good performance\n";
                } elseif ($avgTime < 1000) {
                    echo "    ⚠️ Acceptable performance\n";
                } else {
                    echo "    ❌ Poor performance\n";
                }
            }
            echo "\n";
        }

        $this->results['current_performance'] = $currentResults;
    }

    /**
     * Benchmark with various data volumes
     */
    private function benchmarkWithVariousDataVolumes(): void
    {
        echo "2. 📈 Data Volume Performance Benchmark:\n";
        
        $dataVolumes = [500, 1000, 2500, 5000, 10000];
        $volumeResults = [];

        foreach ($dataVolumes as $volume) {
            echo "  Testing with {$volume} products...\n";
            
            // Create test data
            $this->createTestData($volume);
            
            // Test key queries
            $queryResults = $this->testVolumeQueries($volume);
            $volumeResults[$volume] = $queryResults;
            
            // Clean up test data
            $this->cleanupTestData($volume);
            
            echo "    Completed {$volume} product test\n\n";
        }

        $this->results['volume_performance'] = $volumeResults;
        
        // Analyze scaling
        $this->analyzeScaling($volumeResults);
    }

    private function createTestData(int $volume): void
    {
        echo "    Creating {$volume} test products...\n";
        
        // Clean existing test data
        $this->pdo->exec("DELETE FROM products WHERE id LIKE 'bench_%'");
        $this->pdo->exec("DELETE FROM inventory_data WHERE product_id LIKE 'bench_%'");
        $this->pdo->exec("DELETE FROM product_activity_log WHERE product_id LIKE 'bench_%'");

        // Create products in batches
        $batchSize = 500;
        $batches = ceil($volume / $batchSize);

        for ($batch = 0; $batch < $batches; $batch++) {
            $productValues = [];
            $inventoryValues = [];
            $activityValues = [];
            
            $start = $batch * $batchSize;
            $end = min($start + $batchSize, $volume);
            
            for ($i = $start; $i < $end; $i++) {
                $isActive = ($i % 4 !== 0) ? 1 : 0; // 75% active
                $stock = $isActive ? rand(0, 200) : 0;
                $activityReason = $isActive ? 'visible_processed_stock' : 'not_visible';
                
                $productValues[] = sprintf(
                    "('bench_%d', 'BENCH_SKU_%d', 'Benchmark Product %d', %d, NOW(), '%s')",
                    $i, $i, $i, $isActive, $activityReason
                );
                
                $inventoryValues[] = sprintf(
                    "('bench_%d', 'BENCH_SKU_%d', %d, NOW())",
                    $i, $i, $stock
                );
                
                // Add some activity log entries
                if (rand(1, 10) <= 3) { // 30% chance of activity change
                    $prevStatus = $isActive ? 0 : 1;
                    $activityValues[] = sprintf(
                        "('bench_%d', 'BENCH_SKU_%d', %d, %d, '%s', DATE_SUB(NOW(), INTERVAL %d DAY))",
                        $i, $i, $prevStatus, $isActive, $activityReason, rand(1, 30)
                    );
                }
            }
            
            // Insert products
            if (!empty($productValues)) {
                $sql = "INSERT INTO products (id, external_sku, name, is_active, activity_checked_at, activity_reason) VALUES " . 
                       implode(',', $productValues);
                $this->pdo->exec($sql);
            }
            
            // Insert inventory data
            if (!empty($inventoryValues)) {
                $sql = "INSERT INTO inventory_data (product_id, external_sku, stock_quantity, last_updated) VALUES " . 
                       implode(',', $inventoryValues);
                $this->pdo->exec($sql);
            }
            
            // Insert activity log entries
            if (!empty($activityValues)) {
                $sql = "INSERT INTO product_activity_log (product_id, external_sku, previous_status, new_status, reason, changed_at) VALUES " . 
                       implode(',', $activityValues);
                $this->pdo->exec($sql);
            }
        }

        $this->testDataCreated[] = $volume;
    }

    private function testVolumeQueries(int $volume): array
    {
        $queries = [
            'active_count' => "SELECT COUNT(*) FROM products WHERE is_active = 1 AND id LIKE 'bench_%'",
            'active_with_stock' => "
                SELECT COUNT(*) 
                FROM products p 
                JOIN inventory_data i ON p.id = i.product_id 
                WHERE p.is_active = 1 AND i.stock_quantity > 0 AND p.id LIKE 'bench_%'
            ",
            'low_stock_active' => "
                SELECT COUNT(*) 
                FROM products p 
                JOIN inventory_data i ON p.id = i.product_id 
                WHERE p.is_active = 1 AND i.stock_quantity BETWEEN 1 AND 20 AND p.id LIKE 'bench_%'
            ",
            'activity_changes' => "
                SELECT COUNT(*) 
                FROM product_activity_log 
                WHERE product_id LIKE 'bench_%' AND changed_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
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
            
            $results[$name] = [
                'avg_time' => array_sum($times) / count($times),
                'max_time' => max($times),
                'min_time' => min($times)
            ];
        }
        
        return $results;
    }

    private function cleanupTestData(int $volume): void
    {
        $this->pdo->exec("DELETE FROM products WHERE id LIKE 'bench_%'");
        $this->pdo->exec("DELETE FROM inventory_data WHERE product_id LIKE 'bench_%'");
        $this->pdo->exec("DELETE FROM product_activity_log WHERE product_id LIKE 'bench_%'");
    }

    private function analyzeScaling(array $volumeResults): void
    {
        echo "  📊 Scaling Analysis:\n";
        
        $volumes = array_keys($volumeResults);
        sort($volumes);
        
        $baseVolume = $volumes[0];
        $maxVolume = end($volumes);
        
        foreach (['active_count', 'active_with_stock', 'low_stock_active'] as $queryType) {
            $baseTime = $volumeResults[$baseVolume][$queryType]['avg_time'];
            $maxTime = $volumeResults[$maxVolume][$queryType]['avg_time'];
            
            $scalingFactor = $maxTime / $baseTime;
            $volumeRatio = $maxVolume / $baseVolume;
            
            echo "    {$queryType}: {$scalingFactor}x time for {$volumeRatio}x data\n";
            
            if ($scalingFactor <= $volumeRatio) {
                echo "      ✅ Linear or better scaling\n";
            } elseif ($scalingFactor <= $volumeRatio * 2) {
                echo "      ⚠️ Acceptable scaling\n";
            } else {
                echo "      ❌ Poor scaling - optimization needed\n";
            }
        }
        echo "\n";
    }

    /**
     * Benchmark query optimizations
     */
    private function benchmarkQueryOptimizations(): void
    {
        echo "3. 🔧 Query Optimization Benchmark:\n";
        
        $optimizationTests = [
            'without_index' => [
                'setup' => "DROP INDEX IF EXISTS idx_products_active ON products",
                'query' => "SELECT COUNT(*) FROM products WHERE is_active = 1",
                'description' => 'Query without index'
            ],
            'with_index' => [
                'setup' => "CREATE INDEX IF NOT EXISTS idx_products_active ON products(is_active)",
                'query' => "SELECT COUNT(*) FROM products WHERE is_active = 1",
                'description' => 'Query with index'
            ],
            'compound_index_test' => [
                'setup' => "CREATE INDEX IF NOT EXISTS idx_products_active_updated ON products(is_active, activity_checked_at)",
                'query' => "SELECT COUNT(*) FROM products WHERE is_active = 1 AND activity_checked_at >= DATE_SUB(NOW(), INTERVAL 1 DAY)",
                'description' => 'Query with compound index'
            ]
        ];

        $optimizationResults = [];

        foreach ($optimizationTests as $name => $test) {
            echo "  Testing: {$test['description']}\n";
            
            // Apply setup
            try {
                $this->pdo->exec($test['setup']);
            } catch (PDOException $e) {
                echo "    Setup failed: " . $e->getMessage() . "\n";
                continue;
            }

            $times = [];
            $iterations = 10;

            for ($i = 0; $i < $iterations; $i++) {
                $startTime = microtime(true);
                
                $stmt = $this->pdo->prepare($test['query']);
                $stmt->execute();
                $result = $stmt->fetchAll();
                
                $endTime = microtime(true);
                $times[] = ($endTime - $startTime) * 1000;
            }

            $avgTime = array_sum($times) / count($times);
            
            $optimizationResults[$name] = [
                'description' => $test['description'],
                'avg_time' => $avgTime,
                'max_time' => max($times),
                'min_time' => min($times)
            ];

            echo "    Avg time: " . number_format($avgTime, 2) . " ms\n";
            echo "\n";
        }

        // Compare optimization results
        if (isset($optimizationResults['without_index']) && isset($optimizationResults['with_index'])) {
            $improvement = (($optimizationResults['without_index']['avg_time'] - $optimizationResults['with_index']['avg_time']) 
                           / $optimizationResults['without_index']['avg_time']) * 100;
            
            echo "  Index improvement: " . number_format($improvement, 1) . "%\n";
            
            if ($improvement > 50) {
                echo "  ✅ Significant improvement with indexing\n";
            } elseif ($improvement > 20) {
                echo "  ✅ Good improvement with indexing\n";
            } else {
                echo "  ⚠️ Minimal improvement - check query patterns\n";
            }
        }

        $this->results['optimization_tests'] = $optimizationResults;
        echo "\n";
    }

    /**
     * Benchmark index effectiveness
     */
    private function benchmarkIndexEffectiveness(): void
    {
        echo "4. 📇 Index Effectiveness Benchmark:\n";
        
        // Check current indexes
        $this->analyzeCurrentIndexes();
        
        // Test different index configurations
        $indexTests = [
            'basic_active_index' => [
                'create' => "CREATE INDEX IF NOT EXISTS test_idx_active ON products(is_active)",
                'drop' => "DROP INDEX IF EXISTS test_idx_active ON products",
                'query' => "SELECT * FROM products WHERE is_active = 1 LIMIT 100"
            ],
            'compound_active_date_index' => [
                'create' => "CREATE INDEX IF NOT EXISTS test_idx_active_date ON products(is_active, activity_checked_at)",
                'drop' => "DROP INDEX IF EXISTS test_idx_active_date ON products",
                'query' => "SELECT * FROM products WHERE is_active = 1 AND activity_checked_at >= DATE_SUB(NOW(), INTERVAL 1 HOUR) LIMIT 100"
            ],
            'inventory_stock_index' => [
                'create' => "CREATE INDEX IF NOT EXISTS test_idx_stock ON inventory_data(stock_quantity)",
                'drop' => "DROP INDEX IF EXISTS test_idx_stock ON inventory_data",
                'query' => "SELECT * FROM inventory_data WHERE stock_quantity BETWEEN 1 AND 10 LIMIT 100"
            ]
        ];

        $indexResults = [];

        foreach ($indexTests as $name => $test) {
            echo "  Testing index: {$name}\n";
            
            // Test without index
            $this->pdo->exec($test['drop']);
            $timeWithoutIndex = $this->measureQueryTime($test['query']);
            
            // Test with index
            $this->pdo->exec($test['create']);
            $timeWithIndex = $this->measureQueryTime($test['query']);
            
            $improvement = $timeWithoutIndex > 0 ? (($timeWithoutIndex - $timeWithIndex) / $timeWithoutIndex) * 100 : 0;
            
            $indexResults[$name] = [
                'time_without_index' => $timeWithoutIndex,
                'time_with_index' => $timeWithIndex,
                'improvement_percent' => $improvement
            ];

            echo "    Without index: " . number_format($timeWithoutIndex, 2) . " ms\n";
            echo "    With index: " . number_format($timeWithIndex, 2) . " ms\n";
            echo "    Improvement: " . number_format($improvement, 1) . "%\n";
            
            // Clean up test index
            $this->pdo->exec($test['drop']);
            echo "\n";
        }

        $this->results['index_effectiveness'] = $indexResults;
    }

    private function analyzeCurrentIndexes(): void
    {
        echo "  Current indexes analysis:\n";
        
        $tables = ['products', 'inventory_data', 'product_activity_log'];
        
        foreach ($tables as $table) {
            try {
                $stmt = $this->pdo->prepare("SHOW INDEX FROM {$table}");
                $stmt->execute();
                $indexes = $stmt->fetchAll();
                
                echo "    {$table}: " . count($indexes) . " indexes\n";
                
                foreach ($indexes as $index) {
                    if ($index['Key_name'] !== 'PRIMARY') {
                        echo "      - {$index['Key_name']} on {$index['Column_name']}\n";
                    }
                }
            } catch (PDOException $e) {
                echo "    {$table}: Error analyzing indexes\n";
            }
        }
        echo "\n";
    }

    private function measureQueryTime(string $query, int $iterations = 5): float
    {
        $times = [];
        
        for ($i = 0; $i < $iterations; $i++) {
            $startTime = microtime(true);
            
            $stmt = $this->pdo->prepare($query);
            $stmt->execute();
            $result = $stmt->fetchAll();
            
            $endTime = microtime(true);
            $times[] = ($endTime - $startTime) * 1000;
        }
        
        return array_sum($times) / count($times);
    }

    /**
     * Benchmark concurrent queries
     */
    private function benchmarkConcurrentQueries(): void
    {
        echo "5. 🔄 Concurrent Query Benchmark:\n";
        
        $queries = [
            "SELECT COUNT(*) FROM products WHERE is_active = 1",
            "SELECT COUNT(*) FROM inventory_data WHERE stock_quantity > 0",
            "SELECT COUNT(*) FROM product_activity_log WHERE changed_at >= DATE_SUB(NOW(), INTERVAL 1 DAY)",
            "SELECT p.id FROM products p JOIN inventory_data i ON p.id = i.product_id WHERE p.is_active = 1 AND i.stock_quantity < 10 LIMIT 10"
        ];

        $concurrentResults = [];
        $iterations = 20;

        echo "  Running {$iterations} iterations of concurrent queries...\n";

        $startTime = microtime(true);

        for ($i = 0; $i < $iterations; $i++) {
            $iterationStart = microtime(true);
            
            // Execute all queries in sequence (simulating concurrent load)
            foreach ($queries as $queryIndex => $query) {
                $queryStart = microtime(true);
                
                $stmt = $this->pdo->prepare($query);
                $stmt->execute();
                $result = $stmt->fetchAll();
                
                $queryEnd = microtime(true);
                
                if (!isset($concurrentResults[$queryIndex])) {
                    $concurrentResults[$queryIndex] = [];
                }
                
                $concurrentResults[$queryIndex][] = ($queryEnd - $queryStart) * 1000;
            }
            
            $iterationEnd = microtime(true);
            
            if ($i % 5 === 0) {
                echo "    Completed iteration " . ($i + 1) . "/" . $iterations . "\n";
            }
        }

        $totalTime = microtime(true) - $startTime;

        echo "  Concurrent query results:\n";
        
        foreach ($concurrentResults as $queryIndex => $times) {
            $avgTime = array_sum($times) / count($times);
            $maxTime = max($times);
            $minTime = min($times);
            
            echo "    Query " . ($queryIndex + 1) . ":\n";
            echo "      Avg time: " . number_format($avgTime, 2) . " ms\n";
            echo "      Max time: " . number_format($maxTime, 2) . " ms\n";
            echo "      Min time: " . number_format($minTime, 2) . " ms\n";
        }

        $totalQueries = $iterations * count($queries);
        $queriesPerSecond = $totalQueries / $totalTime;

        echo "  Overall concurrent performance:\n";
        echo "    Total queries: {$totalQueries}\n";
        echo "    Total time: " . number_format($totalTime, 2) . " seconds\n";
        echo "    Queries per second: " . number_format($queriesPerSecond, 2) . "\n";

        if ($queriesPerSecond > 100) {
            echo "    ✅ Excellent concurrent performance\n";
        } elseif ($queriesPerSecond > 50) {
            echo "    ✅ Good concurrent performance\n";
        } else {
            echo "    ⚠️ Concurrent performance could be improved\n";
        }

        $this->results['concurrent_queries'] = [
            'total_queries' => $totalQueries,
            'total_time' => $totalTime,
            'queries_per_second' => $queriesPerSecond,
            'query_details' => $concurrentResults
        ];

        echo "\n";
    }

    /**
     * Generate comprehensive benchmark report
     */
    private function generateBenchmarkReport(): void
    {
        echo "=== DATABASE PERFORMANCE BENCHMARK REPORT ===\n\n";
        
        $overallScore = 0;
        $maxScore = 0;

        // Current performance assessment
        if (isset($this->results['current_performance'])) {
            echo "📊 CURRENT PERFORMANCE ASSESSMENT:\n";
            $currentPerf = $this->results['current_performance'];
            $avgPerformance = 0;
            
            foreach ($currentPerf as $query => $result) {
                echo "  {$result['description']}: " . number_format($result['avg_time'], 2) . " ms\n";
                
                if ($result['avg_time'] < 100) {
                    $avgPerformance += 25;
                } elseif ($result['avg_time'] < 500) {
                    $avgPerformance += 20;
                } elseif ($result['avg_time'] < 1000) {
                    $avgPerformance += 15;
                } else {
                    $avgPerformance += 5;
                }
            }
            
            $avgPerformance = $avgPerformance / count($currentPerf);
            $overallScore += $avgPerformance;
            $maxScore += 25;
            echo "\n";
        }

        // Volume scaling assessment
        if (isset($this->results['volume_performance'])) {
            echo "📈 VOLUME SCALING ASSESSMENT:\n";
            $volumes = array_keys($this->results['volume_performance']);
            sort($volumes);
            
            $smallVolume = $volumes[0];
            $largeVolume = end($volumes);
            
            $scalingGood = true;
            
            foreach (['active_count', 'active_with_stock'] as $queryType) {
                $smallTime = $this->results['volume_performance'][$smallVolume][$queryType]['avg_time'];
                $largeTime = $this->results['volume_performance'][$largeVolume][$queryType]['avg_time'];
                
                $scalingFactor = $largeTime / $smallTime;
                $volumeRatio = $largeVolume / $smallVolume;
                
                echo "  {$queryType}: {$scalingFactor}x time for {$volumeRatio}x data\n";
                
                if ($scalingFactor > $volumeRatio * 2) {
                    $scalingGood = false;
                }
            }
            
            if ($scalingGood) {
                $overallScore += 25;
                echo "  ✅ Good scaling characteristics\n";
            } else {
                $overallScore += 10;
                echo "  ⚠️ Scaling needs optimization\n";
            }
            $maxScore += 25;
            echo "\n";
        }

        // Index effectiveness assessment
        if (isset($this->results['index_effectiveness'])) {
            echo "📇 INDEX EFFECTIVENESS ASSESSMENT:\n";
            $indexResults = $this->results['index_effectiveness'];
            $avgImprovement = 0;
            
            foreach ($indexResults as $indexName => $result) {
                echo "  {$indexName}: " . number_format($result['improvement_percent'], 1) . "% improvement\n";
                $avgImprovement += $result['improvement_percent'];
            }
            
            $avgImprovement = $avgImprovement / count($indexResults);
            
            if ($avgImprovement > 50) {
                $overallScore += 25;
                echo "  ✅ Indexes are highly effective\n";
            } elseif ($avgImprovement > 20) {
                $overallScore += 20;
                echo "  ✅ Indexes provide good improvement\n";
            } else {
                $overallScore += 10;
                echo "  ⚠️ Index effectiveness could be improved\n";
            }
            $maxScore += 25;
            echo "\n";
        }

        // Concurrent performance assessment
        if (isset($this->results['concurrent_queries'])) {
            echo "🔄 CONCURRENT PERFORMANCE ASSESSMENT:\n";
            $concurrent = $this->results['concurrent_queries'];
            echo "  Queries per second: " . number_format($concurrent['queries_per_second'], 2) . "\n";
            
            if ($concurrent['queries_per_second'] > 100) {
                $overallScore += 25;
                echo "  ✅ Excellent concurrent performance\n";
            } elseif ($concurrent['queries_per_second'] > 50) {
                $overallScore += 20;
                echo "  ✅ Good concurrent performance\n";
            } else {
                $overallScore += 10;
                echo "  ⚠️ Concurrent performance needs improvement\n";
            }
            $maxScore += 25;
            echo "\n";
        }

        // Overall assessment
        $scorePercentage = $maxScore > 0 ? ($overallScore / $maxScore) * 100 : 0;
        
        echo "🎯 OVERALL DATABASE PERFORMANCE SCORE: " . number_format($scorePercentage, 1) . "%\n";
        
        if ($scorePercentage >= 90) {
            echo "🏆 EXCELLENT! Database performance is outstanding.\n";
        } elseif ($scorePercentage >= 75) {
            echo "✅ GOOD! Database performance is solid.\n";
        } elseif ($scorePercentage >= 60) {
            echo "⚠️ ACCEPTABLE! Some optimization recommended.\n";
        } else {
            echo "❌ POOR! Significant database optimization required.\n";
        }

        // Recommendations
        echo "\n📋 OPTIMIZATION RECOMMENDATIONS:\n";
        echo "• Ensure proper indexing on is_active column\n";
        echo "• Consider compound indexes for frequently joined tables\n";
        echo "• Monitor query execution plans for optimization opportunities\n";
        echo "• Consider partitioning for very large datasets\n";
        echo "• Implement query result caching for frequently accessed data\n";
        echo "• Regular ANALYZE TABLE to keep statistics current\n";
        
        // Save detailed report
        $reportFile = __DIR__ . '/../results/database_performance_benchmark_' . date('Ymd_His') . '.json';
        $reportDir = dirname($reportFile);
        
        if (!is_dir($reportDir)) {
            mkdir($reportDir, 0755, true);
        }
        
        $report = [
            'benchmark_timestamp' => date('Y-m-d H:i:s'),
            'overall_score' => $scorePercentage,
            'results' => $this->results
        ];
        
        file_put_contents($reportFile, json_encode($report, JSON_PRETTY_PRINT));
        
        echo "\n📄 Detailed benchmark report saved to: {$reportFile}\n";
        echo str_repeat("=", 60) . "\n";
    }

    /**
     * Clean up any test data
     */
    private function cleanup(): void
    {
        foreach ($this->testDataCreated as $volume) {
            $this->cleanupTestData($volume);
        }
        
        // Remove any test indexes
        $testIndexes = [
            "DROP INDEX IF EXISTS test_idx_active ON products",
            "DROP INDEX IF EXISTS test_idx_active_date ON products",
            "DROP INDEX IF EXISTS test_idx_stock ON inventory_data"
        ];
        
        foreach ($testIndexes as $dropSql) {
            try {
                $this->pdo->exec($dropSql);
            } catch (PDOException $e) {
                // Ignore errors when dropping test indexes
            }
        }
    }
}

// Run benchmarks if executed directly
if (php_sapi_name() === 'cli') {
    $benchmark = new DatabasePerformanceBenchmark();
    $benchmark->runAllBenchmarks();
} else {
    header('Content-Type: text/plain; charset=utf-8');
    $benchmark = new DatabasePerformanceBenchmark();
    $benchmark->runAllBenchmarks();
}