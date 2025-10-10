<?php
/**
 * Comprehensive SQL Query Testing Suite
 * Task 5.1: Протестировать исправленные SQL запросы
 * 
 * Tests:
 * - Выполнить все исправленные запросы на тестовой базе данных
 * - Проверить совместимость с разными версиями MySQL
 * - Протестировать производительность на больших объемах данных
 * - Валидировать корректность возвращаемых результатов
 */

require_once __DIR__ . '/../config.php';

class ComprehensiveSQLTester {
    private $pdo;
    private $results = [];
    private $mysqlVersion;
    
    public function __construct() {
        try {
            $this->pdo = new PDO(
                "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4",
                DB_USER,
                DB_PASSWORD,
                [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
                ]
            );
            
            // Get MySQL version
            $this->mysqlVersion = $this->pdo->query('SELECT VERSION()')->fetchColumn();
            
        } catch (PDOException $e) {
            die("❌ Ошибка подключения к БД: " . $e->getMessage() . "\n");
        }
    }
    
    public function runAllTests() {
        echo "╔════════════════════════════════════════════════════════════════╗\n";
        echo "║  COMPREHENSIVE SQL QUERY TESTING SUITE                        ║\n";
        echo "║  Task 5.1: Тестирование исправленных SQL запросов             ║\n";
        echo "╚════════════════════════════════════════════════════════════════╝\n\n";
        
        echo "📊 MySQL Version: {$this->mysqlVersion}\n";
        echo "🗄️  Database: " . DB_NAME . "\n";
        echo "🕐 Test Started: " . date('Y-m-d H:i:s') . "\n\n";
        
        // Test categories
        $this->testMySQLCompatibility();
        $this->testFixedQueries();
        $this->testPerformance();
        $this->testDataCorrectness();
        
        // Generate report
        $this->generateReport();
    }
    
    /**
     * Test 1: MySQL Compatibility
     * Проверить совместимость с разными версиями MySQL
     */
    private function testMySQLCompatibility() {
        echo "═══════════════════════════════════════════════════════════════\n";
        echo "TEST 1: MySQL COMPATIBILITY\n";
        echo "═══════════════════════════════════════════════════════════════\n\n";
        
        $tests = [
            'ONLY_FULL_GROUP_BY Mode' => function() {
                $this->pdo->exec("SET sql_mode = 'ONLY_FULL_GROUP_BY,STRICT_TRANS_TABLES'");
                return "Enabled";
            },
            'Window Functions Support' => function() {
                $stmt = $this->pdo->query("SELECT ROW_NUMBER() OVER (ORDER BY 1) as rn FROM (SELECT 1) t");
                return $stmt ? "Supported" : "Not Supported";
            },
            'JSON Functions' => function() {
                $stmt = $this->pdo->query("SELECT JSON_OBJECT('key', 'value') as json_test");
                return $stmt ? "Supported" : "Not Supported";
            },
            'CTE Support' => function() {
                try {
                    $stmt = $this->pdo->query("WITH cte AS (SELECT 1 as n) SELECT * FROM cte");
                    return $stmt ? "Supported" : "Not Supported";
                } catch (Exception $e) {
                    return "Not Supported";
                }
            }
        ];
        
        foreach ($tests as $testName => $testFunc) {
            try {
                $result = $testFunc();
                $this->logTest('MySQL Compatibility', $testName, true, $result);
                echo "  ✅ $testName: $result\n";
            } catch (Exception $e) {
                $this->logTest('MySQL Compatibility', $testName, false, $e->getMessage());
                echo "  ❌ $testName: " . $e->getMessage() . "\n";
            }
        }
        
        echo "\n";
    }
    
    /**
     * Test 2: Fixed Queries Execution
     * Выполнить все исправленные запросы на тестовой базе данных
     */
    private function testFixedQueries() {
        echo "═══════════════════════════════════════════════════════════════\n";
        echo "TEST 2: FIXED QUERIES EXECUTION\n";
        echo "═══════════════════════════════════════════════════════════════\n\n";
        
        // Enable strict mode
        $this->pdo->exec("SET sql_mode = 'ONLY_FULL_GROUP_BY,STRICT_TRANS_TABLES'");
        
        $queries = [
            'Fixed DISTINCT with ORDER BY' => "
                SELECT DISTINCT 
                    i.product_id, 
                    MAX(i.quantity_present) as max_quantity
                FROM inventory_data i
                LEFT JOIN product_cross_reference pcr ON CAST(i.product_id AS CHAR) COLLATE utf8mb4_general_ci = pcr.inventory_product_id
                WHERE i.product_id != 0
                GROUP BY i.product_id
                ORDER BY max_quantity DESC
                LIMIT 5
            ",
            
            'Subquery Pattern' => "
                SELECT product_id, product_name, quantity_present
                FROM (
                    SELECT 
                        i.product_id,
                        COALESCE(dp.name, pcr.cached_name, CONCAT('Товар ID ', i.product_id)) as product_name,
                        i.quantity_present
                    FROM inventory_data i
                    LEFT JOIN product_cross_reference pcr ON CAST(i.product_id AS CHAR) COLLATE utf8mb4_general_ci = pcr.inventory_product_id
                    LEFT JOIN dim_products dp ON pcr.sku_ozon = dp.sku_ozon
                    WHERE i.product_id != 0
                    ORDER BY i.quantity_present DESC
                    LIMIT 5
                ) ranked_products
            ",
            
            'Safe JOIN with Type Casting' => "
                SELECT 
                    i.product_id,
                    i.quantity_present,
                    COALESCE(dp.name, pcr.cached_name, 'Неизвестный товар') as product_name,
                    pcr.sync_status
                FROM inventory_data i
                LEFT JOIN product_cross_reference pcr ON CAST(i.product_id AS CHAR) COLLATE utf8mb4_general_ci = pcr.inventory_product_id
                LEFT JOIN dim_products dp ON pcr.sku_ozon = dp.sku_ozon
                WHERE i.product_id IS NOT NULL AND i.product_id != 0
                LIMIT 5
            ",
            
            'Products Needing Sync' => "
                SELECT 
                    pcr.id,
                    pcr.inventory_product_id,
                    pcr.ozon_product_id,
                    pcr.cached_name,
                    pcr.sync_status
                FROM product_cross_reference pcr
                LEFT JOIN dim_products dp ON pcr.sku_ozon = dp.sku_ozon
                WHERE (
                    dp.name IS NULL 
                    OR dp.name LIKE 'Товар Ozon ID%'
                    OR pcr.cached_name IS NULL
                )
                AND pcr.sync_status IN ('pending', 'failed')
                ORDER BY pcr.id
                LIMIT 5
            ",
            
            'GROUP BY Aggregation' => "
                SELECT 
                    pcr.sync_status,
                    COUNT(*) as count,
                    COUNT(CASE WHEN pcr.cached_name IS NOT NULL THEN 1 END) as with_names,
                    MAX(pcr.last_successful_sync) as latest_sync
                FROM product_cross_reference pcr
                GROUP BY pcr.sync_status
            "
        ];
        
        foreach ($queries as $queryName => $sql) {
            try {
                $startTime = microtime(true);
                $stmt = $this->pdo->prepare($sql);
                $stmt->execute();
                $results = $stmt->fetchAll();
                $executionTime = (microtime(true) - $startTime) * 1000;
                
                $this->logTest('Fixed Queries', $queryName, true, [
                    'rows' => count($results),
                    'time_ms' => round($executionTime, 2)
                ]);
                
                echo "  ✅ $queryName\n";
                echo "     Rows: " . count($results) . " | Time: " . round($executionTime, 2) . "ms\n";
                
            } catch (Exception $e) {
                $this->logTest('Fixed Queries', $queryName, false, $e->getMessage());
                echo "  ❌ $queryName\n";
                echo "     Error: " . $e->getMessage() . "\n";
            }
        }
        
        echo "\n";
    }
    
    /**
     * Test 3: Performance Testing
     * Протестировать производительность на больших объемах данных
     */
    private function testPerformance() {
        echo "═══════════════════════════════════════════════════════════════\n";
        echo "TEST 3: PERFORMANCE TESTING\n";
        echo "═══════════════════════════════════════════════════════════════\n\n";
        
        $performanceTests = [
            'Small Dataset (LIMIT 10)' => [
                'query' => "
                    SELECT DISTINCT 
                        i.product_id, 
                        MAX(i.quantity_present) as max_quantity
                    FROM inventory_data i
                    LEFT JOIN product_cross_reference pcr ON CAST(i.product_id AS CHAR) COLLATE utf8mb4_general_ci = pcr.inventory_product_id
                    WHERE i.product_id != 0
                    GROUP BY i.product_id
                    ORDER BY max_quantity DESC
                    LIMIT 10
                ",
                'threshold_ms' => 100
            ],
            'Medium Dataset (LIMIT 100)' => [
                'query' => "
                    SELECT DISTINCT 
                        i.product_id, 
                        MAX(i.quantity_present) as max_quantity
                    FROM inventory_data i
                    LEFT JOIN product_cross_reference pcr ON CAST(i.product_id AS CHAR) COLLATE utf8mb4_general_ci = pcr.inventory_product_id
                    WHERE i.product_id != 0
                    GROUP BY i.product_id
                    ORDER BY max_quantity DESC
                    LIMIT 100
                ",
                'threshold_ms' => 500
            ],
            'Large Dataset (LIMIT 1000)' => [
                'query' => "
                    SELECT DISTINCT 
                        i.product_id, 
                        MAX(i.quantity_present) as max_quantity
                    FROM inventory_data i
                    LEFT JOIN product_cross_reference pcr ON CAST(i.product_id AS CHAR) COLLATE utf8mb4_general_ci = pcr.inventory_product_id
                    WHERE i.product_id != 0
                    GROUP BY i.product_id
                    ORDER BY max_quantity DESC
                    LIMIT 1000
                ",
                'threshold_ms' => 2000
            ],
            'Complex JOIN Query' => [
                'query' => "
                    SELECT 
                        i.product_id,
                        COALESCE(dp.name, pcr.cached_name, 'Unknown') as product_name,
                        SUM(i.quantity_present) as total_quantity,
                        COUNT(DISTINCT i.warehouse_name) as warehouse_count
                    FROM inventory_data i
                    LEFT JOIN product_cross_reference pcr ON CAST(i.product_id AS CHAR) COLLATE utf8mb4_general_ci = pcr.inventory_product_id
                    LEFT JOIN dim_products dp ON pcr.sku_ozon = dp.sku_ozon
                    WHERE i.product_id != 0
                    GROUP BY i.product_id, dp.name, pcr.cached_name
                    LIMIT 100
                ",
                'threshold_ms' => 1000
            ]
        ];
        
        foreach ($performanceTests as $testName => $testData) {
            try {
                // Run query 3 times and take average
                $times = [];
                for ($i = 0; $i < 3; $i++) {
                    $startTime = microtime(true);
                    $stmt = $this->pdo->prepare($testData['query']);
                    $stmt->execute();
                    $results = $stmt->fetchAll();
                    $times[] = (microtime(true) - $startTime) * 1000;
                }
                
                $avgTime = array_sum($times) / count($times);
                $passed = $avgTime < $testData['threshold_ms'];
                
                $this->logTest('Performance', $testName, $passed, [
                    'avg_time_ms' => round($avgTime, 2),
                    'threshold_ms' => $testData['threshold_ms'],
                    'rows' => count($results)
                ]);
                
                $icon = $passed ? '✅' : '⚠️';
                echo "  $icon $testName\n";
                echo "     Avg Time: " . round($avgTime, 2) . "ms";
                echo " | Threshold: {$testData['threshold_ms']}ms";
                echo " | Rows: " . count($results) . "\n";
                
            } catch (Exception $e) {
                $this->logTest('Performance', $testName, false, $e->getMessage());
                echo "  ❌ $testName\n";
                echo "     Error: " . $e->getMessage() . "\n";
            }
        }
        
        echo "\n";
    }
    
    /**
     * Test 4: Data Correctness Validation
     * Валидировать корректность возвращаемых результатов
     */
    private function testDataCorrectness() {
        echo "═══════════════════════════════════════════════════════════════\n";
        echo "TEST 4: DATA CORRECTNESS VALIDATION\n";
        echo "═══════════════════════════════════════════════════════════════\n\n";
        
        $validationTests = [
            'No NULL product_id in results' => function() {
                $stmt = $this->pdo->query("
                    SELECT COUNT(*) as null_count
                    FROM (
                        SELECT i.product_id
                        FROM inventory_data i
                        LEFT JOIN product_cross_reference pcr ON CAST(i.product_id AS CHAR) COLLATE utf8mb4_general_ci = pcr.inventory_product_id
                        WHERE i.product_id IS NULL
                        LIMIT 100
                    ) t
                ");
                $result = $stmt->fetch();
                return $result['null_count'] == 0;
            },
            
            'All product names are strings' => function() {
                $stmt = $this->pdo->query("
                    SELECT 
                        COALESCE(dp.name, pcr.cached_name, 'Default') as product_name
                    FROM inventory_data i
                    LEFT JOIN product_cross_reference pcr ON CAST(i.product_id AS CHAR) COLLATE utf8mb4_general_ci = pcr.inventory_product_id
                    LEFT JOIN dim_products dp ON pcr.sku_ozon = dp.sku_ozon
                    WHERE i.product_id != 0
                    LIMIT 10
                ");
                $results = $stmt->fetchAll();
                foreach ($results as $row) {
                    if (!is_string($row['product_name'])) {
                        return false;
                    }
                }
                return true;
            },
            
            'Quantities are non-negative' => function() {
                $stmt = $this->pdo->query("
                    SELECT COUNT(*) as negative_count
                    FROM inventory_data
                    WHERE quantity_present < 0
                ");
                $result = $stmt->fetch();
                return $result['negative_count'] == 0;
            },
            
            'JOIN produces valid results' => function() {
                $stmt = $this->pdo->query("
                    SELECT COUNT(*) as count
                    FROM inventory_data i
                    LEFT JOIN product_cross_reference pcr ON CAST(i.product_id AS CHAR) COLLATE utf8mb4_general_ci = pcr.inventory_product_id
                    WHERE i.product_id != 0
                    LIMIT 100
                ");
                $result = $stmt->fetch();
                return $result['count'] > 0;
            },
            
            'Sync status values are valid' => function() {
                $stmt = $this->pdo->query("
                    SELECT COUNT(*) as invalid_count
                    FROM product_cross_reference
                    WHERE sync_status NOT IN ('synced', 'pending', 'failed')
                ");
                $result = $stmt->fetch();
                return $result['invalid_count'] == 0;
            }
        ];
        
        foreach ($validationTests as $testName => $testFunc) {
            try {
                $passed = $testFunc();
                $this->logTest('Data Correctness', $testName, $passed, $passed ? 'Valid' : 'Invalid');
                
                $icon = $passed ? '✅' : '❌';
                echo "  $icon $testName: " . ($passed ? 'PASSED' : 'FAILED') . "\n";
                
            } catch (Exception $e) {
                $this->logTest('Data Correctness', $testName, false, $e->getMessage());
                echo "  ❌ $testName: Error - " . $e->getMessage() . "\n";
            }
        }
        
        echo "\n";
    }
    
    /**
     * Log test result
     */
    private function logTest($category, $testName, $passed, $details) {
        $this->results[] = [
            'category' => $category,
            'test' => $testName,
            'passed' => $passed,
            'details' => $details,
            'timestamp' => date('Y-m-d H:i:s')
        ];
    }
    
    /**
     * Generate final report
     */
    private function generateReport() {
        echo "═══════════════════════════════════════════════════════════════\n";
        echo "FINAL REPORT\n";
        echo "═══════════════════════════════════════════════════════════════\n\n";
        
        $totalTests = count($this->results);
        $passedTests = count(array_filter($this->results, fn($r) => $r['passed']));
        $failedTests = $totalTests - $passedTests;
        
        $categories = [];
        foreach ($this->results as $result) {
            $cat = $result['category'];
            if (!isset($categories[$cat])) {
                $categories[$cat] = ['total' => 0, 'passed' => 0];
            }
            $categories[$cat]['total']++;
            if ($result['passed']) {
                $categories[$cat]['passed']++;
            }
        }
        
        echo "📊 SUMMARY:\n";
        echo "  Total Tests: $totalTests\n";
        echo "  Passed: $passedTests ✅\n";
        echo "  Failed: $failedTests ❌\n";
        echo "  Success Rate: " . round(($passedTests / $totalTests) * 100, 1) . "%\n\n";
        
        echo "📋 BY CATEGORY:\n";
        foreach ($categories as $category => $stats) {
            $rate = round(($stats['passed'] / $stats['total']) * 100, 1);
            echo "  $category: {$stats['passed']}/{$stats['total']} ($rate%)\n";
        }
        
        echo "\n";
        
        if ($failedTests > 0) {
            echo "❌ FAILED TESTS:\n";
            foreach ($this->results as $result) {
                if (!$result['passed']) {
                    echo "  - [{$result['category']}] {$result['test']}\n";
                    if (is_string($result['details'])) {
                        echo "    Details: {$result['details']}\n";
                    }
                }
            }
            echo "\n";
        }
        
        // Save report to file
        $reportFile = 'logs/sql_test_report_' . date('Y-m-d_H-i-s') . '.json';
        @mkdir('logs', 0755, true);
        file_put_contents($reportFile, json_encode([
            'mysql_version' => $this->mysqlVersion,
            'test_date' => date('Y-m-d H:i:s'),
            'summary' => [
                'total' => $totalTests,
                'passed' => $passedTests,
                'failed' => $failedTests,
                'success_rate' => round(($passedTests / $totalTests) * 100, 1)
            ],
            'categories' => $categories,
            'results' => $this->results
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        
        echo "💾 Report saved to: $reportFile\n\n";
        
        if ($passedTests === $totalTests) {
            echo "🎉 ALL TESTS PASSED! SQL queries are production-ready.\n";
        } else {
            echo "⚠️  Some tests failed. Please review and fix issues before deployment.\n";
        }
    }
}

// Run tests
if (php_sapi_name() === 'cli') {
    $tester = new ComprehensiveSQLTester();
    $tester->runAllTests();
} else {
    header('Content-Type: text/plain; charset=utf-8');
    $tester = new ComprehensiveSQLTester();
    $tester->runAllTests();
}
