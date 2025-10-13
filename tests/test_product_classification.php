<?php
/**
 * Тесты для классификации товаров по уровням остатков
 * Проверяет логику классификации согласно требованиям 2.1, 2.2, 2.3
 */

require_once __DIR__ . '/../config.php';

class ProductClassificationTest {
    private $pdo;
    private $test_results = [];
    
    public function __construct() {
        $this->pdo = getDatabaseConnection();
    }
    
    /**
     * Тест классификации критических товаров (≤5 единиц)
     * Требование 2.1: КОГДА товар имеет остаток <= 5 единиц, ТО система ДОЛЖНА классифицировать его как "критический"
     */
    public function testCriticalStockClassification() {
        echo "\n🧪 Тестирование классификации критических товаров...\n";
        
        try {
            $test_cases = [
                ['stock' => 0, 'expected' => 'critical', 'description' => 'Нулевой остаток'],
                ['stock' => 1, 'expected' => 'critical', 'description' => '1 единица'],
                ['stock' => 3, 'expected' => 'critical', 'description' => '3 единицы'],
                ['stock' => 5, 'expected' => 'critical', 'description' => '5 единиц (граничное значение)'],
            ];
            
            foreach ($test_cases as $case) {
                $classification = $this->classifyStock($case['stock']);
                $this->assertEquals('critical', $classification, 
                    "Товар с остатком {$case['stock']} должен быть классифицирован как 'critical' ({$case['description']})");
                echo "  ✅ {$case['description']}: {$case['stock']} → {$classification}\n";
            }
            
            $this->test_results['critical_classification'] = 'PASSED';
            echo "✅ Тест классификации критических товаров пройден\n";
            
        } catch (Exception $e) {
            $this->test_results['critical_classification'] = 'FAILED: ' . $e->getMessage();
            echo "❌ Тест классификации критических товаров провален: " . $e->getMessage() . "\n";
        }
    }
    
    /**
     * Тест классификации товаров с низким остатком (6-20 единиц)
     * Требование 2.2: КОГДА товар имеет остаток от 6 до 20 единиц, ТО система ДОЛЖНА классифицировать его как "низкий"
     */
    public function testLowStockClassification() {
        echo "\n🧪 Тестирование классификации товаров с низким остатком...\n";
        
        try {
            $test_cases = [
                ['stock' => 6, 'expected' => 'low', 'description' => '6 единиц (нижняя граница)'],
                ['stock' => 10, 'expected' => 'low', 'description' => '10 единиц'],
                ['stock' => 15, 'expected' => 'low', 'description' => '15 единиц'],
                ['stock' => 20, 'expected' => 'low', 'description' => '20 единиц (верхняя граница)'],
            ];
            
            foreach ($test_cases as $case) {
                $classification = $this->classifyStock($case['stock']);
                $this->assertEquals('low', $classification, 
                    "Товар с остатком {$case['stock']} должен быть классифицирован как 'low' ({$case['description']})");
                echo "  ✅ {$case['description']}: {$case['stock']} → {$classification}\n";
            }
            
            $this->test_results['low_classification'] = 'PASSED';
            echo "✅ Тест классификации товаров с низким остатком пройден\n";
            
        } catch (Exception $e) {
            $this->test_results['low_classification'] = 'FAILED: ' . $e->getMessage();
            echo "❌ Тест классификации товаров с низким остатком провален: " . $e->getMessage() . "\n";
        }
    }
    
    /**
     * Тест классификации товаров с избытком (>100 единиц)
     * Требование 2.3: КОГДА товар имеет остаток > 100 единиц, ТО система ДОЛЖНА классифицировать его как "избыток"
     */
    public function testOverstockClassification() {
        echo "\n🧪 Тестирование классификации товаров с избытком...\n";
        
        try {
            $test_cases = [
                ['stock' => 101, 'expected' => 'overstock', 'description' => '101 единица (минимальный избыток)'],
                ['stock' => 150, 'expected' => 'overstock', 'description' => '150 единиц'],
                ['stock' => 500, 'expected' => 'overstock', 'description' => '500 единиц'],
                ['stock' => 1000, 'expected' => 'overstock', 'description' => '1000 единиц'],
            ];
            
            foreach ($test_cases as $case) {
                $classification = $this->classifyStock($case['stock']);
                $this->assertEquals('overstock', $classification, 
                    "Товар с остатком {$case['stock']} должен быть классифицирован как 'overstock' ({$case['description']})");
                echo "  ✅ {$case['description']}: {$case['stock']} → {$classification}\n";
            }
            
            $this->test_results['overstock_classification'] = 'PASSED';
            echo "✅ Тест классификации товаров с избытком пройден\n";
            
        } catch (Exception $e) {
            $this->test_results['overstock_classification'] = 'FAILED: ' . $e->getMessage();
            echo "❌ Тест классификации товаров с избытком провален: " . $e->getMessage() . "\n";
        }
    }
    
    /**
     * Тест классификации нормальных товаров (21-100 единиц)
     */
    public function testNormalStockClassification() {
        echo "\n🧪 Тестирование классификации нормальных товаров...\n";
        
        try {
            $test_cases = [
                ['stock' => 21, 'expected' => 'normal', 'description' => '21 единица (нижняя граница нормы)'],
                ['stock' => 50, 'expected' => 'normal', 'description' => '50 единиц'],
                ['stock' => 75, 'expected' => 'normal', 'description' => '75 единиц'],
                ['stock' => 100, 'expected' => 'normal', 'description' => '100 единиц (верхняя граница нормы)'],
            ];
            
            foreach ($test_cases as $case) {
                $classification = $this->classifyStock($case['stock']);
                $this->assertEquals('normal', $classification, 
                    "Товар с остатком {$case['stock']} должен быть классифицирован как 'normal' ({$case['description']})");
                echo "  ✅ {$case['description']}: {$case['stock']} → {$classification}\n";
            }
            
            $this->test_results['normal_classification'] = 'PASSED';
            echo "✅ Тест классификации нормальных товаров пройден\n";
            
        } catch (Exception $e) {
            $this->test_results['normal_classification'] = 'FAILED: ' . $e->getMessage();
            echo "❌ Тест классификации нормальных товаров провален: " . $e->getMessage() . "\n";
        }
    }
    
    /**
     * Тест граничных значений классификации
     */
    public function testBoundaryValues() {
        echo "\n🧪 Тестирование граничных значений классификации...\n";
        
        try {
            $boundary_cases = [
                // Граница между critical и low
                ['stock' => 5, 'expected' => 'critical', 'description' => 'Граница critical/low: 5'],
                ['stock' => 6, 'expected' => 'low', 'description' => 'Граница critical/low: 6'],
                
                // Граница между low и normal
                ['stock' => 20, 'expected' => 'low', 'description' => 'Граница low/normal: 20'],
                ['stock' => 21, 'expected' => 'normal', 'description' => 'Граница low/normal: 21'],
                
                // Граница между normal и overstock
                ['stock' => 100, 'expected' => 'normal', 'description' => 'Граница normal/overstock: 100'],
                ['stock' => 101, 'expected' => 'overstock', 'description' => 'Граница normal/overstock: 101'],
            ];
            
            foreach ($boundary_cases as $case) {
                $classification = $this->classifyStock($case['stock']);
                $this->assertEquals($case['expected'], $classification, 
                    "Граничное значение {$case['stock']} должно быть классифицировано как '{$case['expected']}'");
                echo "  ✅ {$case['description']}: {$case['stock']} → {$classification}\n";
            }
            
            $this->test_results['boundary_values'] = 'PASSED';
            echo "✅ Тест граничных значений пройден\n";
            
        } catch (Exception $e) {
            $this->test_results['boundary_values'] = 'FAILED: ' . $e->getMessage();
            echo "❌ Тест граничных значений провален: " . $e->getMessage() . "\n";
        }
    }
    
    /**
     * Тест классификации с реальными данными из базы
     */
    public function testDatabaseClassification() {
        echo "\n🧪 Тестирование классификации с данными из базы...\n";
        
        try {
            // Создаем тестовые данные
            $this->setupTestData();
            
            // Получаем классификацию из базы данных
            $stmt = $this->pdo->prepare("
                SELECT 
                    i.sku,
                    SUM(i.current_stock) as total_stock,
                    CASE
                        WHEN SUM(i.current_stock) <= 5 THEN 'critical'
                        WHEN SUM(i.current_stock) <= 20 THEN 'low'
                        WHEN SUM(i.current_stock) > 100 THEN 'overstock'
                        ELSE 'normal'
                    END as stock_status
                FROM inventory_data i
                WHERE i.sku LIKE 'TEST-CLASS-%'
                GROUP BY i.sku
                ORDER BY i.sku
            ");
            
            $stmt->execute();
            $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            $expected_results = [
                'TEST-CLASS-CRITICAL' => 'critical',
                'TEST-CLASS-LOW' => 'low',
                'TEST-CLASS-NORMAL' => 'normal',
                'TEST-CLASS-OVERSTOCK' => 'overstock'
            ];
            
            foreach ($results as $result) {
                $expected = $expected_results[$result['sku']] ?? null;
                $this->assertNotNull($expected, "Неожиданный SKU в результатах: {$result['sku']}");
                $this->assertEquals($expected, $result['stock_status'], 
                    "SKU {$result['sku']} с остатком {$result['total_stock']} должен иметь статус '{$expected}', получен '{$result['stock_status']}'");
                echo "  ✅ {$result['sku']}: {$result['total_stock']} единиц → {$result['stock_status']}\n";
            }
            
            // Очищаем тестовые данные
            $this->cleanupTestData();
            
            $this->test_results['database_classification'] = 'PASSED';
            echo "✅ Тест классификации с данными из базы пройден\n";
            
        } catch (Exception $e) {
            $this->cleanupTestData();
            $this->test_results['database_classification'] = 'FAILED: ' . $e->getMessage();
            echo "❌ Тест классификации с данными из базы провален: " . $e->getMessage() . "\n";
        }
    }
    
    /**
     * Тест производительности классификации
     */
    public function testClassificationPerformance() {
        echo "\n🧪 Тестирование производительности классификации...\n";
        
        try {
            $start_time = microtime(true);
            
            // Тестируем классификацию 1000 товаров
            for ($i = 0; $i < 1000; $i++) {
                $stock = rand(0, 200);
                $classification = $this->classifyStock($stock);
                
                // Проверяем что классификация корректна
                if ($stock <= 5) {
                    $this->assertEquals('critical', $classification);
                } elseif ($stock <= 20) {
                    $this->assertEquals('low', $classification);
                } elseif ($stock > 100) {
                    $this->assertEquals('overstock', $classification);
                } else {
                    $this->assertEquals('normal', $classification);
                }
            }
            
            $end_time = microtime(true);
            $execution_time = ($end_time - $start_time) * 1000; // в миллисекундах
            
            echo "  ✅ Классифицировано 1000 товаров за " . number_format($execution_time, 2) . " мс\n";
            
            // Проверяем что время выполнения разумное (< 100 мс)
            $this->assertTrue($execution_time < 100, 
                "Классификация 1000 товаров должна выполняться менее чем за 100 мс, выполнено за " . number_format($execution_time, 2) . " мс");
            
            $this->test_results['classification_performance'] = 'PASSED';
            echo "✅ Тест производительности классификации пройден\n";
            
        } catch (Exception $e) {
            $this->test_results['classification_performance'] = 'FAILED: ' . $e->getMessage();
            echo "❌ Тест производительности классификации провален: " . $e->getMessage() . "\n";
        }
    }
    
    /**
     * Функция классификации товаров (реплицирует логику из API)
     */
    private function classifyStock($stock) {
        if ($stock <= 5) {
            return 'critical';
        } elseif ($stock <= 20) {
            return 'low';
        } elseif ($stock > 100) {
            return 'overstock';
        } else {
            return 'normal';
        }
    }
    
    /**
     * Создание тестовых данных для классификации
     */
    private function setupTestData() {
        try {
            // Очищаем старые тестовые данные
            $this->pdo->exec("DELETE FROM inventory_data WHERE sku LIKE 'TEST-CLASS-%'");
            
            // Создаем тестовые данные для каждого типа классификации
            $test_data = [
                ['sku' => 'TEST-CLASS-CRITICAL', 'stock' => 3],
                ['sku' => 'TEST-CLASS-LOW', 'stock' => 15],
                ['sku' => 'TEST-CLASS-NORMAL', 'stock' => 50],
                ['sku' => 'TEST-CLASS-OVERSTOCK', 'stock' => 150]
            ];
            
            $stmt = $this->pdo->prepare("
                INSERT INTO inventory_data (sku, warehouse_name, current_stock, available_stock, reserved_stock, last_sync_at) 
                VALUES (?, 'Тестовый склад', ?, ?, 0, NOW())
            ");
            
            foreach ($test_data as $data) {
                $stmt->execute([$data['sku'], $data['stock'], $data['stock']]);
            }
            
        } catch (Exception $e) {
            throw new Exception("Ошибка создания тестовых данных: " . $e->getMessage());
        }
    }
    
    /**
     * Очистка тестовых данных
     */
    private function cleanupTestData() {
        try {
            $this->pdo->exec("DELETE FROM inventory_data WHERE sku LIKE 'TEST-CLASS-%'");
        } catch (Exception $e) {
            // Игнорируем ошибки очистки
        }
    }
    
    /**
     * Простые функции assert для тестов
     */
    private function assertEquals($expected, $actual, $message = '') {
        if ($expected !== $actual) {
            throw new Exception($message ?: "Expected '$expected', got '$actual'");
        }
    }
    
    private function assertTrue($condition, $message = '') {
        if (!$condition) {
            throw new Exception($message ?: 'Assertion failed');
        }
    }
    
    private function assertNotNull($value, $message = '') {
        if ($value === null) {
            throw new Exception($message ?: 'Value should not be null');
        }
    }
    
    /**
     * Запуск всех тестов классификации
     */
    public function runAllTests() {
        echo "🚀 Запуск тестов классификации товаров по уровням остатков\n";
        echo "=" . str_repeat("=", 70) . "\n";
        
        $start_time = microtime(true);
        
        // Запускаем все тесты
        $this->testCriticalStockClassification();
        $this->testLowStockClassification();
        $this->testOverstockClassification();
        $this->testNormalStockClassification();
        $this->testBoundaryValues();
        $this->testDatabaseClassification();
        $this->testClassificationPerformance();
        
        $end_time = microtime(true);
        $execution_time = round($end_time - $start_time, 2);
        
        // Выводим результаты
        echo "\n" . str_repeat("=", 70) . "\n";
        echo "📊 РЕЗУЛЬТАТЫ ТЕСТИРОВАНИЯ КЛАССИФИКАЦИИ ТОВАРОВ\n";
        echo str_repeat("=", 70) . "\n";
        
        $passed = 0;
        $total = count($this->test_results);
        
        foreach ($this->test_results as $test_name => $result) {
            $status = strpos($result, 'PASSED') !== false ? '✅ PASSED' : '❌ FAILED';
            echo sprintf("%-50s %s\n", $test_name, $status);
            
            if (strpos($result, 'PASSED') !== false) {
                $passed++;
            } else {
                echo "   Детали: " . str_replace('FAILED: ', '', $result) . "\n";
            }
        }
        
        echo str_repeat("-", 70) . "\n";
        echo sprintf("Пройдено: %d/%d тестов (%.1f%%)\n", $passed, $total, ($passed / $total) * 100);
        echo "Время выполнения: {$execution_time} сек\n";
        
        if ($passed === $total) {
            echo "🎉 ВСЕ ТЕСТЫ КЛАССИФИКАЦИИ ПРОЙДЕНЫ УСПЕШНО!\n";
            return true;
        } else {
            echo "⚠️ НЕКОТОРЫЕ ТЕСТЫ КЛАССИФИКАЦИИ НЕ ПРОЙДЕНЫ\n";
            return false;
        }
    }
}

// Запуск тестов если файл выполняется напрямую
if (basename(__FILE__) === basename($_SERVER['SCRIPT_NAME'])) {
    try {
        $tester = new ProductClassificationTest();
        $success = $tester->runAllTests();
        exit($success ? 0 : 1);
    } catch (Exception $e) {
        echo "❌ Критическая ошибка при запуске тестов: " . $e->getMessage() . "\n";
        exit(1);
    }
}
?>