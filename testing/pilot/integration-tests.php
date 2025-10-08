<?php

/**
 * Тесты интеграции с существующими системами для пилотного тестирования
 */

require_once __DIR__ . '/../../config.php';

class IntegrationTests {
    private $db;
    private $logFile;
    private $results = [];
    
    public function __construct() {
        $this->db = new PDO(
            "mysql:host=" . DB_HOST . ";dbname=mdm_pilot;charset=utf8mb4",
            DB_USER,
            DB_PASSWORD,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
        );
        
        $this->logFile = __DIR__ . '/logs/integration_tests_' . date('Y-m-d_H-i-s') . '.log';
        $this->createLogDirectory();
    }
    
    private function createLogDirectory() {
        $logDir = dirname($this->logFile);
        if (!is_dir($logDir)) {
            mkdir($logDir, 0755, true);
        }
    }
    
    private function log($message) {
        $timestamp = date('Y-m-d H:i:s');
        $logMessage = "[$timestamp] $message" . PHP_EOL;
        file_put_contents($this->logFile, $logMessage, FILE_APPEND);
        echo $logMessage;
    }
    
    /**
     * Тестирование API endpoints
     */
    public function testApiEndpoints() {
        $this->log("=== Тестирование API endpoints ===");
        
        $endpoints = [
            '/api/master-products' => 'GET',
            '/api/master-products/search' => 'POST',
            '/api/sku-mapping' => 'GET',
            '/api/data-quality/metrics' => 'GET'
        ];
        
        $baseUrl = 'http://localhost:8080'; // Предполагаемый URL API
        
        foreach ($endpoints as $endpoint => $method) {
            try {
                $this->log("Тестируем $method $endpoint");
                
                $ch = curl_init();
                curl_setopt($ch, CURLOPT_URL, $baseUrl . $endpoint);
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                curl_setopt($ch, CURLOPT_TIMEOUT, 10);
                curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
                
                if ($method === 'POST') {
                    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode(['query' => 'test']));
                    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
                }
                
                $startTime = microtime(true);
                $response = curl_exec($ch);
                $responseTime = (microtime(true) - $startTime) * 1000;
                
                $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                curl_close($ch);
                
                if ($httpCode >= 200 && $httpCode < 300) {
                    $this->results["api_$endpoint"] = "PASS ({$responseTime}ms)";
                    $this->log("✓ $endpoint: PASS (HTTP $httpCode, {$responseTime}ms)");
                } else {
                    $this->results["api_$endpoint"] = "FAIL (HTTP $httpCode)";
                    $this->log("✗ $endpoint: FAIL (HTTP $httpCode)");
                }
                
            } catch (Exception $e) {
                $this->results["api_$endpoint"] = "ERROR: " . $e->getMessage();
                $this->log("✗ $endpoint: ERROR - " . $e->getMessage());
            }
        }
    }
    
    /**
     * Тестирование совместимости с существующими дашбордами
     */
    public function testDashboardCompatibility() {
        $this->log("=== Тестирование совместимости с дашбордами ===");
        
        try {
            // Тест 1: Проверяем, что данные можно получить в формате, совместимом с существующими дашбордами
            $sql = "
                SELECT 
                    mp.master_id,
                    mp.canonical_name as product_name,
                    mp.canonical_brand as brand,
                    mp.canonical_category as category,
                    GROUP_CONCAT(sm.external_sku) as external_skus,
                    GROUP_CONCAT(sm.source) as sources
                FROM master_products mp
                LEFT JOIN sku_mapping sm ON mp.master_id = sm.master_id
                GROUP BY mp.master_id
                LIMIT 10
            ";
            
            $startTime = microtime(true);
            $stmt = $this->db->query($sql);
            $products = $stmt->fetchAll(PDO::FETCH_ASSOC);
            $queryTime = (microtime(true) - $startTime) * 1000;
            
            if (count($products) > 0) {
                $this->results['dashboard_data_format'] = "PASS ({$queryTime}ms)";
                $this->log("✓ Формат данных для дашбордов: PASS");
            } else {
                $this->results['dashboard_data_format'] = "FAIL (No data)";
                $this->log("✗ Формат данных для дашбордов: FAIL - Нет данных");
            }
            
            // Тест 2: Проверяем агрегацию по мастер-данным
            $sql = "
                SELECT 
                    mp.canonical_brand,
                    COUNT(DISTINCT mp.master_id) as unique_products,
                    COUNT(sm.id) as total_skus
                FROM master_products mp
                LEFT JOIN sku_mapping sm ON mp.master_id = sm.master_id
                WHERE mp.canonical_brand != 'Неизвестный бренд'
                GROUP BY mp.canonical_brand
                ORDER BY unique_products DESC
            ";
            
            $startTime = microtime(true);
            $stmt = $this->db->query($sql);
            $brandStats = $stmt->fetchAll(PDO::FETCH_ASSOC);
            $queryTime = (microtime(true) - $startTime) * 1000;
            
            if (count($brandStats) > 0) {
                $this->results['dashboard_aggregation'] = "PASS ({$queryTime}ms)";
                $this->log("✓ Агрегация для дашбордов: PASS");
            } else {
                $this->results['dashboard_aggregation'] = "FAIL (No aggregation data)";
                $this->log("✗ Агрегация для дашбордов: FAIL");
            }
            
        } catch (Exception $e) {
            $this->results['dashboard_compatibility'] = "ERROR: " . $e->getMessage();
            $this->log("✗ Совместимость с дашбордами: ERROR - " . $e->getMessage());
        }
    }
    
    /**
     * Тестирование интеграции с внешними источниками данных
     */
    public function testExternalDataSources() {
        $this->log("=== Тестирование интеграции с внешними источниками ===");
        
        // Тест подключения к основной базе данных
        try {
            $mainDb = new PDO(
                "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4",
                DB_USER,
                DB_PASSWORD,
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
            );
            
            // Проверяем наличие исходных таблиц
            $tables = ['products', 'ozon_products', 'wb_products'];
            $availableTables = [];
            
            foreach ($tables as $table) {
                try {
                    $stmt = $mainDb->query("SELECT COUNT(*) FROM $table LIMIT 1");
                    $count = $stmt->fetchColumn();
                    $availableTables[$table] = $count;
                    $this->log("✓ Таблица $table доступна ($count записей)");
                } catch (Exception $e) {
                    $this->log("⚠ Таблица $table недоступна: " . $e->getMessage());
                    $availableTables[$table] = 0;
                }
            }
            
            if (count($availableTables) > 0) {
                $this->results['external_data_sources'] = "PASS (" . count($availableTables) . " sources)";
            } else {
                $this->results['external_data_sources'] = "FAIL (No sources available)";
            }
            
        } catch (Exception $e) {
            $this->results['external_data_sources'] = "ERROR: " . $e->getMessage();
            $this->log("✗ Подключение к внешним источникам: ERROR - " . $e->getMessage());
        }
    }
    
    /**
     * Тестирование производительности интеграционных запросов
     */
    public function testIntegrationPerformance() {
        $this->log("=== Тестирование производительности интеграции ===");
        
        $performanceTests = [
            'master_product_lookup' => "
                SELECT mp.*, sm.external_sku, sm.source
                FROM master_products mp
                JOIN sku_mapping sm ON mp.master_id = sm.master_id
                WHERE sm.external_sku = 'SKU_001'
            ",
            'brand_aggregation' => "
                SELECT 
                    canonical_brand,
                    COUNT(*) as product_count
                FROM master_products
                GROUP BY canonical_brand
            ",
            'category_breakdown' => "
                SELECT 
                    canonical_category,
                    canonical_brand,
                    COUNT(*) as count
                FROM master_products
                GROUP BY canonical_category, canonical_brand
            ",
            'source_mapping_stats' => "
                SELECT 
                    source,
                    COUNT(*) as sku_count,
                    COUNT(DISTINCT master_id) as unique_products
                FROM sku_mapping
                GROUP BY source
            "
        ];
        
        foreach ($performanceTests as $testName => $query) {
            try {
                $iterations = 10;
                $totalTime = 0;
                
                for ($i = 0; $i < $iterations; $i++) {
                    $startTime = microtime(true);
                    $stmt = $this->db->query($query);
                    $stmt->fetchAll();
                    $totalTime += (microtime(true) - $startTime) * 1000;
                }
                
                $avgTime = $totalTime / $iterations;
                $this->results["performance_$testName"] = round($avgTime, 2) . "ms";
                
                if ($avgTime < 100) {
                    $this->log("✓ $testName: " . round($avgTime, 2) . "ms (отлично)");
                } elseif ($avgTime < 200) {
                    $this->log("⚠ $testName: " . round($avgTime, 2) . "ms (приемлемо)");
                } else {
                    $this->log("✗ $testName: " . round($avgTime, 2) . "ms (медленно)");
                }
                
            } catch (Exception $e) {
                $this->results["performance_$testName"] = "ERROR: " . $e->getMessage();
                $this->log("✗ $testName: ERROR - " . $e->getMessage());
            }
        }
    }
    
    /**
     * Тестирование обратной совместимости
     */
    public function testBackwardCompatibility() {
        $this->log("=== Тестирование обратной совместимости ===");
        
        try {
            // Проверяем, что старые запросы все еще работают через представления или адаптеры
            
            // Создаем временное представление для совместимости
            $this->db->exec("
                CREATE OR REPLACE VIEW legacy_products AS
                SELECT 
                    sm.external_sku as id,
                    mp.canonical_name as name,
                    mp.canonical_brand as brand,
                    mp.canonical_category as category,
                    sm.source,
                    mp.created_at
                FROM master_products mp
                JOIN sku_mapping sm ON mp.master_id = sm.master_id
                WHERE sm.source = 'internal'
            ");
            
            // Тестируем запрос в старом формате
            $sql = "SELECT id, name, brand, category FROM legacy_products LIMIT 5";
            $stmt = $this->db->query($sql);
            $legacyData = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            if (count($legacyData) > 0) {
                $this->results['backward_compatibility'] = "PASS";
                $this->log("✓ Обратная совместимость: PASS");
            } else {
                $this->results['backward_compatibility'] = "FAIL (No legacy data)";
                $this->log("✗ Обратная совместимость: FAIL");
            }
            
            // Удаляем временное представление
            $this->db->exec("DROP VIEW IF EXISTS legacy_products");
            
        } catch (Exception $e) {
            $this->results['backward_compatibility'] = "ERROR: " . $e->getMessage();
            $this->log("✗ Обратная совместимость: ERROR - " . $e->getMessage());
        }
    }
    
    /**
     * Создание отчета по интеграционным тестам
     */
    public function createIntegrationReport() {
        $this->log("=== Создание отчета по интеграционным тестам ===");
        
        $report = [
            'test_date' => date('Y-m-d H:i:s'),
            'test_results' => $this->results,
            'summary' => [
                'total_tests' => count($this->results),
                'passed_tests' => 0,
                'failed_tests' => 0,
                'error_tests' => 0
            ]
        ];
        
        // Подсчитываем статистику
        foreach ($this->results as $test => $result) {
            if (strpos($result, 'PASS') === 0) {
                $report['summary']['passed_tests']++;
            } elseif (strpos($result, 'FAIL') === 0) {
                $report['summary']['failed_tests']++;
            } elseif (strpos($result, 'ERROR') === 0) {
                $report['summary']['error_tests']++;
            }
        }
        
        // Определяем общий статус
        if ($report['summary']['error_tests'] > 0 || $report['summary']['failed_tests'] > 0) {
            $report['overall_status'] = 'NEEDS_ATTENTION';
        } else {
            $report['overall_status'] = 'PASS';
        }
        
        // Сохраняем отчет
        $reportFile = __DIR__ . '/results/integration_test_report.json';
        $this->ensureDirectoryExists(dirname($reportFile));
        file_put_contents($reportFile, json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        
        $this->log("Отчет по интеграционным тестам сохранен: $reportFile");
        
        return $report;
    }
    
    private function ensureDirectoryExists($directory) {
        if (!is_dir($directory)) {
            mkdir($directory, 0755, true);
        }
    }
    
    /**
     * Запуск всех интеграционных тестов
     */
    public function runAllTests() {
        $this->log("=== Запуск всех интеграционных тестов ===");
        
        $this->testApiEndpoints();
        $this->testDashboardCompatibility();
        $this->testExternalDataSources();
        $this->testIntegrationPerformance();
        $this->testBackwardCompatibility();
        
        return $this->createIntegrationReport();
    }
    
    /**
     * Печать результатов тестов
     */
    public function printResults() {
        echo "\n=== РЕЗУЛЬТАТЫ ИНТЕГРАЦИОННЫХ ТЕСТОВ ===\n";
        
        foreach ($this->results as $test => $result) {
            $status = strpos($result, 'PASS') === 0 ? '✓' : (strpos($result, 'FAIL') === 0 ? '✗' : '⚠');
            echo "$status $test: $result\n";
        }
        
        $passed = count(array_filter($this->results, function($result) {
            return strpos($result, 'PASS') === 0;
        }));
        
        $total = count($this->results);
        
        echo "\nИтого: $passed/$total тестов пройдено\n";
        
        if ($passed == $total) {
            echo "✅ Все интеграционные тесты пройдены успешно!\n";
        } else {
            echo "❌ Некоторые интеграционные тесты не пройдены.\n";
        }
    }
}

// Запуск интеграционных тестов
if (php_sapi_name() === 'cli') {
    try {
        $tests = new IntegrationTests();
        $report = $tests->runAllTests();
        $tests->printResults();
        
        echo "\nОтчет сохранен в: testing/pilot/results/integration_test_report.json\n";
        
        if ($report['overall_status'] !== 'PASS') {
            exit(1);
        }
        
    } catch (Exception $e) {
        echo "Ошибка при выполнении интеграционных тестов: " . $e->getMessage() . "\n";
        exit(1);
    }
}

?>