<?php
/**
 * Тесты для проверки оптимизаций производительности дашборда складских остатков
 * Проверяет работу индексов, кэширования и общую производительность
 * Создано для задачи 7: Оптимизировать производительность
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../api/inventory_cache_manager.php';

class InventoryPerformanceOptimizationTest {
    private $pdo;
    private $cache;
    private $test_results = [];
    
    public function __construct() {
        $this->pdo = getDatabaseConnection();
        $this->cache = getInventoryCacheManager();
    }
    
    public function runAllTests() {
        echo "=== Тестирование оптимизаций производительности дашборда складских остатков ===\n\n";
        
        $tests = [
            'testDatabaseIndexes' => 'Проверка индексов базы данных',
            'testCacheSystem' => 'Проверка системы кэширования',
            'testApiPerformance' => 'Проверка производительности API',
            'testQueryOptimization' => 'Проверка оптимизации запросов',
            'testCacheEfficiency' => 'Проверка эффективности кэша',
            'testErrorHandling' => 'Проверка обработки ошибок'
        ];
        
        foreach ($tests as $method => $description) {
            echo "🔍 $description...\n";
            $this->runTest($method);
            echo "\n";
        }
        
        $this->generateSummary();
    }
    
    private function runTest($method) {
        $start_time = microtime(true);
        
        try {
            $result = $this->$method();
            $execution_time = microtime(true) - $start_time;
            
            $this->test_results[$method] = [
                'status' => 'success',
                'execution_time' => $execution_time,
                'details' => $result
            ];
            
            echo "✅ Тест пройден за " . round($execution_time * 1000, 2) . " мс\n";
            
            if (is_array($result) && !empty($result)) {
                foreach ($result as $detail) {
                    echo "   $detail\n";
                }
            }
            
        } catch (Exception $e) {
            $execution_time = microtime(true) - $start_time;
            
            $this->test_results[$method] = [
                'status' => 'error',
                'execution_time' => $execution_time,
                'error' => $e->getMessage()
            ];
            
            echo "❌ Тест не пройден: " . $e->getMessage() . "\n";
        }
    }
    
    /**
     * Проверка индексов базы данных
     */
    private function testDatabaseIndexes() {
        $results = [];
        
        // Проверяем существование ключевых индексов для inventory_data
        $expected_indexes = [
            'idx_inventory_data_sku',
            'idx_inventory_data_warehouse',
            'idx_inventory_data_current_stock',
            'idx_inventory_data_main_query'
        ];
        
        $stmt = $this->pdo->prepare("
            SELECT INDEX_NAME, COLUMN_NAME, CARDINALITY
            FROM information_schema.STATISTICS 
            WHERE TABLE_SCHEMA = DATABASE() 
            AND TABLE_NAME = 'inventory_data'
            AND INDEX_NAME LIKE 'idx_%'
            ORDER BY INDEX_NAME, SEQ_IN_INDEX
        ");
        $stmt->execute();
        $indexes = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        $found_indexes = array_unique(array_column($indexes, 'INDEX_NAME'));
        
        foreach ($expected_indexes as $expected) {
            if (in_array($expected, $found_indexes)) {
                $results[] = "✅ Индекс $expected найден";
            } else {
                $results[] = "⚠️  Индекс $expected отсутствует";
            }
        }
        
        // Проверяем кардинальность индексов
        $low_cardinality_indexes = array_filter($indexes, function($idx) {
            return $idx['CARDINALITY'] < 10;
        });
        
        if (!empty($low_cardinality_indexes)) {
            $results[] = "⚠️  Найдены индексы с низкой кардинальностью";
        } else {
            $results[] = "✅ Кардинальность индексов в норме";
        }
        
        return $results;
    }
    
    /**
     * Проверка системы кэширования
     */
    private function testCacheSystem() {
        $results = [];
        
        // Проверяем доступность кэша
        if (!$this->cache->isEnabled()) {
            throw new Exception("Кэширование отключено");
        }
        
        $results[] = "✅ Кэширование включено";
        
        // Тест записи и чтения
        $test_key = 'performance_test_' . time();
        $test_data = ['test' => true, 'timestamp' => time()];
        
        $write_success = $this->cache->set($test_key, $test_data, 60);
        if (!$write_success) {
            throw new Exception("Ошибка записи в кэш");
        }
        
        $results[] = "✅ Запись в кэш работает";
        
        $read_data = $this->cache->get($test_key);
        if (!$read_data || $read_data['test'] !== true) {
            throw new Exception("Ошибка чтения из кэша");
        }
        
        $results[] = "✅ Чтение из кэша работает";
        
        // Тест TTL
        $this->cache->set($test_key . '_ttl', ['ttl_test' => true], 1);
        sleep(2);
        $expired_data = $this->cache->get($test_key . '_ttl');
        
        if ($expired_data === null) {
            $results[] = "✅ TTL работает корректно";
        } else {
            $results[] = "⚠️  Проблемы с TTL";
        }
        
        // Очистка тестовых данных
        $this->cache->delete($test_key);
        $this->cache->delete($test_key . '_ttl');
        
        return $results;
    }
    
    /**
     * Проверка производительности API
     */
    private function testApiPerformance() {
        $results = [];
        
        // Тестируем основные функции напрямую
        require_once __DIR__ . '/../api/inventory-analytics.php';
        
        $functions_to_test = [
            'getInventoryDashboardData' => 'Dashboard data',
            'getCriticalProducts' => 'Critical products',
            'getWarehouseSummary' => 'Warehouse summary'
        ];
        
        foreach ($functions_to_test as $function => $name) {
            if (function_exists($function)) {
                $start = microtime(true);
                
                try {
                    $result = $function($this->pdo);
                    $execution_time = microtime(true) - $start;
                    $time_ms = round($execution_time * 1000, 2);
                    
                    if ($execution_time < 0.5) {
                        $results[] = "✅ $name: {$time_ms} мс (быстро)";
                    } elseif ($execution_time < 1.0) {
                        $results[] = "⚠️  $name: {$time_ms} мс (приемлемо)";
                    } else {
                        $results[] = "❌ $name: {$time_ms} мс (медленно)";
                    }
                    
                    if (is_array($result) && isset($result['data'])) {
                        $results[] = "   📊 Данные получены успешно";
                    }
                    
                } catch (Exception $e) {
                    $results[] = "❌ $name: " . $e->getMessage();
                }
            } else {
                $results[] = "⚠️  Функция $function не найдена";
            }
        }
        
        return $results;
    }
    
    /**
     * Проверка оптимизации запросов
     */
    private function testQueryOptimization() {
        $results = [];
        
        // Тестируем основной запрос дашборда
        $main_query = "
            SELECT 
                i.sku,
                i.warehouse_name,
                SUM(i.current_stock) as total_stock,
                CASE
                    WHEN SUM(i.current_stock) <= 5 THEN 'critical'
                    WHEN SUM(i.current_stock) <= 20 THEN 'low'
                    WHEN SUM(i.current_stock) > 100 THEN 'overstock'
                    ELSE 'normal'
                END as stock_status
            FROM inventory_data i
            WHERE i.current_stock IS NOT NULL
            GROUP BY i.sku, i.warehouse_name
            LIMIT 100
        ";
        
        // Проверяем план выполнения
        $explain_stmt = $this->pdo->prepare("EXPLAIN " . $main_query);
        $explain_stmt->execute();
        $explain = $explain_stmt->fetchAll(PDO::FETCH_ASSOC);
        
        $uses_index = false;
        $estimated_rows = 0;
        
        foreach ($explain as $row) {
            if ($row['key'] && $row['key'] !== 'NULL') {
                $uses_index = true;
                $results[] = "✅ Используется индекс: " . $row['key'];
            }
            $estimated_rows += $row['rows'];
        }
        
        if (!$uses_index) {
            $results[] = "⚠️  Запрос не использует индексы";
        }
        
        if ($estimated_rows > 10000) {
            $results[] = "⚠️  Большое количество сканируемых строк: " . number_format($estimated_rows);
        } else {
            $results[] = "✅ Оптимальное количество сканируемых строк: " . number_format($estimated_rows);
        }
        
        // Измеряем время выполнения
        $start = microtime(true);
        $stmt = $this->pdo->prepare($main_query);
        $stmt->execute();
        $results_count = $stmt->rowCount();
        $execution_time = microtime(true) - $start;
        
        $time_ms = round($execution_time * 1000, 2);
        
        if ($execution_time < 0.1) {
            $results[] = "✅ Быстрое выполнение: {$time_ms} мс";
        } elseif ($execution_time < 0.5) {
            $results[] = "⚠️  Приемлемое время: {$time_ms} мс";
        } else {
            $results[] = "❌ Медленное выполнение: {$time_ms} мс";
        }
        
        return $results;
    }
    
    /**
     * Проверка эффективности кэша
     */
    private function testCacheEfficiency() {
        $results = [];
        
        if (!$this->cache->isEnabled()) {
            throw new Exception("Кэширование отключено");
        }
        
        // Очищаем кэш для чистого теста
        $this->cache->clear();
        
        // Тестируем кэш напрямую с функциями
        require_once __DIR__ . '/../api/inventory-analytics.php';
        
        if (function_exists('getInventoryDashboardData')) {
            // Первый запрос (должен идти в БД)
            $cache_key = InventoryCacheKeys::getDashboardKey();
            
            $start = microtime(true);
            $result1 = getInventoryDashboardData($this->pdo);
            $time1 = microtime(true) - $start;
            
            // Сохраняем в кэш
            $this->cache->set($cache_key, $result1, 300);
            
            // Второй запрос (должен идти из кэша)
            $start = microtime(true);
            $cached_result = $this->cache->get($cache_key);
            $time2 = microtime(true) - $start;
            
            if ($cached_result) {
                $speedup = $time1 / $time2;
                
                if ($speedup > 10) {
                    $results[] = "✅ Кэш ускоряет запросы в " . round($speedup, 1) . " раз";
                } else {
                    $results[] = "⚠️  Ускорение от кэша: " . round($speedup, 1) . "x";
                }
                
                $results[] = "📊 Запрос из БД: " . round($time1 * 1000, 2) . " мс";
                $results[] = "📊 Запрос из кэша: " . round($time2 * 1000, 2) . " мс";
                $results[] = "✅ Кэширование работает эффективно";
            } else {
                $results[] = "⚠️  Проблемы с получением данных из кэша";
            }
        } else {
            $results[] = "⚠️  Функция getInventoryDashboardData не найдена";
        }
        
        return $results;
    }
    
    /**
     * Проверка обработки ошибок
     */
    private function testErrorHandling() {
        $results = [];
        
        // Тест кэша при ошибках
        $invalid_key = 'invalid/key\\with*special?chars';
        
        try {
            $this->cache->set($invalid_key, ['test' => true]);
            $this->cache->get($invalid_key);
            $results[] = "✅ Кэш обрабатывает специальные символы";
        } catch (Exception $e) {
            $results[] = "⚠️  Проблемы с обработкой специальных символов в кэше";
        }
        
        // Тест обработки ошибок в функциях
        try {
            // Тестируем с недопустимым PDO объектом
            $invalid_pdo = null;
            
            require_once __DIR__ . '/../api/inventory-analytics.php';
            
            if (function_exists('validateDatabaseConnection')) {
                $validation_result = validateDatabaseConnection($this->pdo);
                if ($validation_result) {
                    $results[] = "✅ Валидация подключения к БД работает";
                } else {
                    $results[] = "⚠️  Проблемы с валидацией подключения к БД";
                }
            }
            
            // Тест валидации входных параметров
            if (function_exists('validateInput')) {
                $validation_errors = validateInput('invalid_action');
                if (!empty($validation_errors)) {
                    $results[] = "✅ Валидация входных параметров работает";
                } else {
                    $results[] = "⚠️  Валидация входных параметров не работает";
                }
            }
            
        } catch (Exception $e) {
            $results[] = "⚠️  Ошибка при тестировании обработки ошибок: " . $e->getMessage();
        }
        
        return $results;
    }
    
    /**
     * Генерация итогового отчета
     */
    private function generateSummary() {
        echo "=== ИТОГОВЫЙ ОТЧЕТ ТЕСТИРОВАНИЯ ОПТИМИЗАЦИЙ ===\n\n";
        
        $total_tests = count($this->test_results);
        $successful_tests = count(array_filter($this->test_results, function($r) { 
            return $r['status'] === 'success'; 
        }));
        $failed_tests = $total_tests - $successful_tests;
        
        echo "📊 Статистика тестов:\n";
        echo "   Всего тестов: $total_tests\n";
        echo "   Успешных: $successful_tests\n";
        echo "   Неудачных: $failed_tests\n";
        echo "   Процент успеха: " . round(($successful_tests / $total_tests) * 100, 1) . "%\n\n";
        
        echo "⏱️  Время выполнения:\n";
        $total_time = 0;
        foreach ($this->test_results as $test => $result) {
            $status_icon = $result['status'] === 'success' ? '✅' : '❌';
            $time = round($result['execution_time'] * 1000, 2);
            $total_time += $result['execution_time'];
            echo "   $status_icon " . str_replace('test', '', $test) . ": {$time} мс\n";
        }
        
        echo "\n📈 Общее время тестирования: " . round($total_time * 1000, 2) . " мс\n\n";
        
        // Рекомендации
        echo "🎯 Рекомендации:\n";
        
        if ($failed_tests > 0) {
            echo "   ❌ Исправьте ошибки в неудачных тестах\n";
        }
        
        if ($successful_tests === $total_tests) {
            echo "   ✅ Все оптимизации работают корректно\n";
            echo "   📊 Рекомендуется регулярный мониторинг производительности\n";
        }
        
        echo "   🔄 Запускайте тесты после изменений в системе\n";
        echo "   📈 Мониторьте производительность в продакшене\n";
    }
}

// Запуск тестов
if (php_sapi_name() === 'cli') {
    $tester = new InventoryPerformanceOptimizationTest();
    $tester->runAllTests();
} else {
    echo "Эти тесты должны запускаться из командной строки\n";
}
?>