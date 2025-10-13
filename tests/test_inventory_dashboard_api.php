<?php
/**
 * Тесты для API endpoints дашборда складских остатков
 * Проверяет все основные функции API согласно требованиям
 */

require_once __DIR__ . '/../config.php';

class InventoryDashboardApiTest {
    private $pdo;
    private $test_results = [];
    private $base_url;
    
    public function __construct() {
        $this->pdo = getDatabaseConnection();
        $this->base_url = 'http://localhost/api/inventory-analytics.php';
        
        // Создаем тестовые данные
        $this->setupTestData();
    }
    
    /**
     * Создание тестовых данных для проверки функциональности
     */
    private function setupTestData() {
        try {
            // Очищаем тестовые данные если они есть
            $this->pdo->exec("DELETE FROM inventory_data WHERE sku LIKE 'TEST-%'");
            $this->pdo->exec("DELETE FROM dim_products WHERE sku_ozon LIKE 'TEST-%'");
            
            // Создаем тестовые товары в dim_products
            $products_stmt = $this->pdo->prepare("
                INSERT INTO dim_products (sku_ozon, sku_wb, product_name, name, cost_price) VALUES
                ('TEST-CRITICAL-001', 'TEST-CRITICAL-001', 'Тестовый критический товар 1', 'Тестовый критический товар 1', 100.00),
                ('TEST-LOW-001', 'TEST-LOW-001', 'Тестовый товар с низким остатком', 'Тестовый товар с низким остатком', 50.00),
                ('TEST-OVERSTOCK-001', 'TEST-OVERSTOCK-001', 'Тестовый товар с избытком', 'Тестовый товар с избытком', 25.00),
                ('TEST-NORMAL-001', 'TEST-NORMAL-001', 'Тестовый нормальный товар', 'Тестовый нормальный товар', 75.00),
                ('TEST-NO-NAME-001', 'TEST-NO-NAME-001', NULL, NULL, 30.00)
            ");
            $products_stmt->execute();
            
            // Создаем тестовые данные в inventory_data
            $inventory_stmt = $this->pdo->prepare("
                INSERT INTO inventory_data (sku, warehouse_name, current_stock, available_stock, reserved_stock, last_sync_at) VALUES
                ('TEST-CRITICAL-001', 'Тестовый склад 1', 3, 3, 0, NOW()),
                ('TEST-CRITICAL-001', 'Тестовый склад 2', 2, 2, 0, NOW()),
                ('TEST-LOW-001', 'Тестовый склад 1', 15, 12, 3, NOW()),
                ('TEST-OVERSTOCK-001', 'Тестовый склад 1', 150, 140, 10, NOW()),
                ('TEST-NORMAL-001', 'Тестовый склад 1', 50, 45, 5, NOW()),
                ('TEST-NO-NAME-001', 'Тестовый склад 1', 8, 8, 0, NOW())
            ");
            $inventory_stmt->execute();
            
            echo "✅ Тестовые данные созданы успешно\n";
            
        } catch (Exception $e) {
            echo "❌ Ошибка создания тестовых данных: " . $e->getMessage() . "\n";
            throw $e;
        }
    }
    
    /**
     * Тест основного dashboard endpoint
     * Требования: 1.1, 1.2, 2.1, 2.2, 2.3
     */
    public function testDashboardEndpoint() {
        echo "\n🧪 Тестирование dashboard endpoint...\n";
        
        try {
            $response = $this->makeApiRequest('dashboard');
            
            // Проверяем структуру ответа
            $this->assertTrue(isset($response['status']), "Ответ должен содержать поле 'status'");
            $this->assertEquals('success', $response['status'], "Статус должен быть 'success'");
            $this->assertTrue(isset($response['data']), "Ответ должен содержать поле 'data'");
            
            $data = $response['data'];
            
            // Проверяем обязательные поля (требование 1.1)
            $required_fields = [
                'critical_stock_count', 'low_stock_count', 'overstock_count', 
                'total_inventory_value', 'critical_products', 'low_stock_products', 
                'overstock_products', 'warehouses_summary', 'recommendations'
            ];
            
            foreach ($required_fields as $field) {
                $this->assertTrue(isset($data[$field]), "Поле '$field' должно присутствовать в ответе");
            }
            
            // Проверяем типы данных
            $this->assertTrue(is_int($data['critical_stock_count']), "critical_stock_count должно быть числом");
            $this->assertTrue(is_array($data['critical_products']), "critical_products должно быть массивом");
            $this->assertTrue(is_array($data['warehouses_summary']), "warehouses_summary должно быть массивом");
            
            // Проверяем наличие тестовых данных
            $this->assertTrue($data['critical_stock_count'] >= 1, "Должен быть хотя бы один критический товар");
            $this->assertTrue($data['low_stock_count'] >= 1, "Должен быть хотя бы один товар с низким остатком");
            $this->assertTrue($data['overstock_count'] >= 1, "Должен быть хотя бы один товар с избытком");
            
            $this->test_results['dashboard_endpoint'] = 'PASSED';
            echo "✅ Dashboard endpoint тест пройден\n";
            
        } catch (Exception $e) {
            $this->test_results['dashboard_endpoint'] = 'FAILED: ' . $e->getMessage();
            echo "❌ Dashboard endpoint тест провален: " . $e->getMessage() . "\n";
        }
    }
    
    /**
     * Тест endpoint для критических товаров
     * Требования: 2.1
     */
    public function testCriticalProductsEndpoint() {
        echo "\n🧪 Тестирование critical-products endpoint...\n";
        
        try {
            $response = $this->makeApiRequest('critical-products');
            
            $this->assertEquals('success', $response['status'], "Статус должен быть 'success'");
            $this->assertTrue(isset($response['data']), "Ответ должен содержать данные");
            $this->assertTrue(is_array($response['data']), "Данные должны быть массивом");
            
            // Проверяем что все товары действительно критические (≤5 единиц)
            foreach ($response['data'] as $product) {
                $this->assertTrue($product['stock'] <= 5, 
                    "Товар {$product['sku']} имеет остаток {$product['stock']}, что больше 5 единиц");
                
                // Проверяем обязательные поля товара
                $required_product_fields = ['name', 'sku', 'stock', 'warehouse'];
                foreach ($required_product_fields as $field) {
                    $this->assertTrue(isset($product[$field]), 
                        "Товар должен содержать поле '$field'");
                }
            }
            
            $this->test_results['critical_products_endpoint'] = 'PASSED';
            echo "✅ Critical products endpoint тест пройден\n";
            
        } catch (Exception $e) {
            $this->test_results['critical_products_endpoint'] = 'FAILED: ' . $e->getMessage();
            echo "❌ Critical products endpoint тест провален: " . $e->getMessage() . "\n";
        }
    }
    
    /**
     * Тест endpoint для товаров с избытком
     * Требования: 2.3
     */
    public function testOverstockProductsEndpoint() {
        echo "\n🧪 Тестирование overstock-products endpoint...\n";
        
        try {
            $response = $this->makeApiRequest('overstock-products');
            
            $this->assertEquals('success', $response['status'], "Статус должен быть 'success'");
            $this->assertTrue(is_array($response['data']), "Данные должны быть массивом");
            
            // Проверяем что все товары действительно с избытком (>100 единиц)
            foreach ($response['data'] as $product) {
                $this->assertTrue($product['stock'] > 100, 
                    "Товар {$product['sku']} имеет остаток {$product['stock']}, что меньше или равно 100 единиц");
                
                // Проверяем наличие поля excess_stock
                $this->assertTrue(isset($product['excess_stock']), 
                    "Товар должен содержать поле 'excess_stock'");
                $this->assertTrue($product['excess_stock'] > 0, 
                    "excess_stock должно быть больше 0");
            }
            
            $this->test_results['overstock_products_endpoint'] = 'PASSED';
            echo "✅ Overstock products endpoint тест пройден\n";
            
        } catch (Exception $e) {
            $this->test_results['overstock_products_endpoint'] = 'FAILED: ' . $e->getMessage();
            echo "❌ Overstock products endpoint тест провален: " . $e->getMessage() . "\n";
        }
    }
    
    /**
     * Тест endpoint для сводки по складам
     * Требования: 5.1, 5.2, 5.3
     */
    public function testWarehouseSummaryEndpoint() {
        echo "\n🧪 Тестирование warehouse-summary endpoint...\n";
        
        try {
            $response = $this->makeApiRequest('warehouse-summary');
            
            $this->assertEquals('success', $response['status'], "Статус должен быть 'success'");
            $this->assertTrue(is_array($response['data']), "Данные должны быть массивом");
            
            // Проверяем структуру данных по складам
            foreach ($response['data'] as $warehouse) {
                $required_warehouse_fields = [
                    'warehouse_name', 'total_products', 'total_stock', 
                    'critical_count', 'low_count', 'overstock_count'
                ];
                
                foreach ($required_warehouse_fields as $field) {
                    $this->assertTrue(isset($warehouse[$field]), 
                        "Склад должен содержать поле '$field'");
                }
                
                // Проверяем типы данных
                $this->assertTrue(is_string($warehouse['warehouse_name']), 
                    "warehouse_name должно быть строкой");
                $this->assertTrue(is_numeric($warehouse['total_products']), 
                    "total_products должно быть числом");
                $this->assertTrue(is_numeric($warehouse['total_stock']), 
                    "total_stock должно быть числом");
            }
            
            $this->test_results['warehouse_summary_endpoint'] = 'PASSED';
            echo "✅ Warehouse summary endpoint тест пройден\n";
            
        } catch (Exception $e) {
            $this->test_results['warehouse_summary_endpoint'] = 'FAILED: ' . $e->getMessage();
            echo "❌ Warehouse summary endpoint тест провален: " . $e->getMessage() . "\n";
        }
    }
    
    /**
     * Тест endpoint для рекомендаций
     * Требования: 4.1, 4.2, 4.3
     */
    public function testRecommendationsEndpoint() {
        echo "\n🧪 Тестирование recommendations endpoint...\n";
        
        try {
            $response = $this->makeApiRequest('recommendations');
            
            $this->assertEquals('success', $response['status'], "Статус должен быть 'success'");
            $this->assertTrue(is_array($response['data']), "Данные должны быть массивом");
            
            // Проверяем структуру рекомендаций
            foreach ($response['data'] as $recommendation) {
                $required_rec_fields = ['type', 'title', 'message', 'action'];
                
                foreach ($required_rec_fields as $field) {
                    $this->assertTrue(isset($recommendation[$field]), 
                        "Рекомендация должна содержать поле '$field'");
                }
                
                // Проверяем допустимые типы рекомендаций
                $allowed_types = ['urgent', 'optimization', 'planning'];
                $this->assertTrue(in_array($recommendation['type'], $allowed_types), 
                    "Тип рекомендации должен быть одним из: " . implode(', ', $allowed_types));
            }
            
            $this->test_results['recommendations_endpoint'] = 'PASSED';
            echo "✅ Recommendations endpoint тест пройден\n";
            
        } catch (Exception $e) {
            $this->test_results['recommendations_endpoint'] = 'FAILED: ' . $e->getMessage();
            echo "❌ Recommendations endpoint тест провален: " . $e->getMessage() . "\n";
        }
    }
    
    /**
     * Тест обработки ошибок API
     * Требования: 1.1, 3.2
     */
    public function testErrorHandling() {
        echo "\n🧪 Тестирование обработки ошибок...\n";
        
        try {
            // Тест недопустимого action
            $response = $this->makeApiRequest('invalid-action');
            $this->assertEquals('error', $response['status'], "Должна быть ошибка для недопустимого action");
            $this->assertEquals('VALIDATION_ERROR', $response['error_code'], "Должен быть код ошибки VALIDATION_ERROR");
            
            // Тест warehouse-details без параметра warehouse
            $response = $this->makeApiRequest('warehouse-details');
            $this->assertEquals('error', $response['status'], "Должна быть ошибка при отсутствии параметра warehouse");
            
            $this->test_results['error_handling'] = 'PASSED';
            echo "✅ Error handling тест пройден\n";
            
        } catch (Exception $e) {
            $this->test_results['error_handling'] = 'FAILED: ' . $e->getMessage();
            echo "❌ Error handling тест провален: " . $e->getMessage() . "\n";
        }
    }
    
    /**
     * Выполнение запроса к API
     */
    private function makeApiRequest($action, $params = []) {
        $url = $this->base_url . '?action=' . $action;
        
        foreach ($params as $key => $value) {
            $url .= '&' . urlencode($key) . '=' . urlencode($value);
        }
        
        $context = stream_context_create([
            'http' => [
                'method' => 'GET',
                'timeout' => 30,
                'header' => 'Content-Type: application/json'
            ]
        ]);
        
        $response = file_get_contents($url, false, $context);
        
        if ($response === false) {
            throw new Exception("Не удалось выполнить запрос к API: $url");
        }
        
        $decoded = json_decode($response, true);
        
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new Exception("Некорректный JSON ответ: " . json_last_error_msg());
        }
        
        return $decoded;
    }
    
    /**
     * Простая функция assert для тестов
     */
    private function assertTrue($condition, $message = '') {
        if (!$condition) {
            throw new Exception($message ?: 'Assertion failed');
        }
    }
    
    private function assertEquals($expected, $actual, $message = '') {
        if ($expected !== $actual) {
            throw new Exception($message ?: "Expected '$expected', got '$actual'");
        }
    }
    
    /**
     * Очистка тестовых данных
     */
    private function cleanupTestData() {
        try {
            $this->pdo->exec("DELETE FROM inventory_data WHERE sku LIKE 'TEST-%'");
            $this->pdo->exec("DELETE FROM dim_products WHERE sku_ozon LIKE 'TEST-%'");
            echo "✅ Тестовые данные очищены\n";
        } catch (Exception $e) {
            echo "⚠️ Ошибка очистки тестовых данных: " . $e->getMessage() . "\n";
        }
    }
    
    /**
     * Запуск всех тестов
     */
    public function runAllTests() {
        echo "🚀 Запуск тестов API endpoints дашборда складских остатков\n";
        echo "=" . str_repeat("=", 60) . "\n";
        
        $start_time = microtime(true);
        
        // Запускаем все тесты
        $this->testDashboardEndpoint();
        $this->testCriticalProductsEndpoint();
        $this->testOverstockProductsEndpoint();
        $this->testWarehouseSummaryEndpoint();
        $this->testRecommendationsEndpoint();
        $this->testErrorHandling();
        
        $end_time = microtime(true);
        $execution_time = round($end_time - $start_time, 2);
        
        // Очищаем тестовые данные
        $this->cleanupTestData();
        
        // Выводим результаты
        echo "\n" . str_repeat("=", 60) . "\n";
        echo "📊 РЕЗУЛЬТАТЫ ТЕСТИРОВАНИЯ API ENDPOINTS\n";
        echo str_repeat("=", 60) . "\n";
        
        $passed = 0;
        $total = count($this->test_results);
        
        foreach ($this->test_results as $test_name => $result) {
            $status = strpos($result, 'PASSED') !== false ? '✅ PASSED' : '❌ FAILED';
            echo sprintf("%-40s %s\n", $test_name, $status);
            
            if (strpos($result, 'PASSED') !== false) {
                $passed++;
            } else {
                echo "   Детали: " . str_replace('FAILED: ', '', $result) . "\n";
            }
        }
        
        echo str_repeat("-", 60) . "\n";
        echo sprintf("Пройдено: %d/%d тестов (%.1f%%)\n", $passed, $total, ($passed / $total) * 100);
        echo "Время выполнения: {$execution_time} сек\n";
        
        if ($passed === $total) {
            echo "🎉 ВСЕ ТЕСТЫ API ENDPOINTS ПРОЙДЕНЫ УСПЕШНО!\n";
            return true;
        } else {
            echo "⚠️ НЕКОТОРЫЕ ТЕСТЫ API ENDPOINTS НЕ ПРОЙДЕНЫ\n";
            return false;
        }
    }
}

// Запуск тестов если файл выполняется напрямую
if (basename(__FILE__) === basename($_SERVER['SCRIPT_NAME'])) {
    try {
        $tester = new InventoryDashboardApiTest();
        $success = $tester->runAllTests();
        exit($success ? 0 : 1);
    } catch (Exception $e) {
        echo "❌ Критическая ошибка при запуске тестов: " . $e->getMessage() . "\n";
        exit(1);
    }
}
?>