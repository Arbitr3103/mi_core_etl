#!/usr/bin/env php
<?php
/**
 * Мониторинг производительности дашборда складских остатков
 * Анализирует время выполнения запросов и эффективность индексов
 * Создано для задачи 7: Оптимизировать производительность
 */

require_once __DIR__ . '/../config.php';

class InventoryPerformanceMonitor {
    private $pdo;
    private $results = [];
    
    public function __construct() {
        $this->pdo = getDatabaseConnection();
    }
    
    public function run($args = []) {
        echo "=== Мониторинг производительности дашборда складских остатков ===\n\n";
        
        $tests = [
            'database_connection' => 'Тест подключения к базе данных',
            'table_structure' => 'Анализ структуры таблиц',
            'index_analysis' => 'Анализ индексов',
            'query_performance' => 'Тест производительности запросов',
            'cache_performance' => 'Тест производительности кэша',
            'api_performance' => 'Тест производительности API'
        ];
        
        foreach ($tests as $test => $description) {
            echo "🔍 $description...\n";
            $this->runTest($test);
            echo "\n";
        }
        
        $this->generateReport();
    }
    
    private function runTest($test) {
        $start_time = microtime(true);
        
        try {
            switch ($test) {
                case 'database_connection':
                    $this->testDatabaseConnection();
                    break;
                case 'table_structure':
                    $this->analyzeTableStructure();
                    break;
                case 'index_analysis':
                    $this->analyzeIndexes();
                    break;
                case 'query_performance':
                    $this->testQueryPerformance();
                    break;
                case 'cache_performance':
                    $this->testCachePerformance();
                    break;
                case 'api_performance':
                    $this->testApiPerformance();
                    break;
            }
            
            $execution_time = microtime(true) - $start_time;
            $this->results[$test] = [
                'status' => 'success',
                'execution_time' => $execution_time,
                'message' => 'Тест выполнен успешно'
            ];
            
        } catch (Exception $e) {
            $execution_time = microtime(true) - $start_time;
            $this->results[$test] = [
                'status' => 'error',
                'execution_time' => $execution_time,
                'message' => $e->getMessage()
            ];
            echo "❌ Ошибка: " . $e->getMessage() . "\n";
        }
    }
    
    private function testDatabaseConnection() {
        $start = microtime(true);
        $stmt = $this->pdo->query("SELECT 1");
        $connection_time = microtime(true) - $start;
        
        echo "✅ Подключение к БД: " . round($connection_time * 1000, 2) . " мс\n";
        
        // Проверяем версию MySQL
        $version_stmt = $this->pdo->query("SELECT VERSION() as version");
        $version = $version_stmt->fetch()['version'];
        echo "📊 Версия MySQL: $version\n";
        
        // Проверяем настройки производительности
        $this->checkMySQLSettings();
    }
    
    private function checkMySQLSettings() {
        $important_settings = [
            'innodb_buffer_pool_size',
            'query_cache_size',
            'tmp_table_size',
            'max_heap_table_size'
        ];
        
        foreach ($important_settings as $setting) {
            try {
                $stmt = $this->pdo->prepare("SHOW VARIABLES LIKE ?");
                $stmt->execute([$setting]);
                $result = $stmt->fetch();
                
                if ($result) {
                    echo "⚙️  $setting: {$result['Value']}\n";
                }
            } catch (Exception $e) {
                // Игнорируем ошибки для настроек, которые могут отсутствовать
            }
        }
    }
    
    private function analyzeTableStructure() {
        $tables = ['inventory_data', 'dim_products', 'sku_cross_reference'];
        
        foreach ($tables as $table) {
            echo "📋 Анализ таблицы $table:\n";
            
            try {
                // Проверяем существование таблицы
                $stmt = $this->pdo->prepare("SHOW TABLES LIKE ?");
                $stmt->execute([$table]);
                
                if (!$stmt->fetch()) {
                    echo "  ⚠️  Таблица не найдена\n";
                    continue;
                }
                
                // Получаем информацию о таблице
                $stmt = $this->pdo->prepare("
                    SELECT 
                        TABLE_ROWS,
                        DATA_LENGTH,
                        INDEX_LENGTH,
                        (DATA_LENGTH + INDEX_LENGTH) as TOTAL_SIZE
                    FROM information_schema.TABLES 
                    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?
                ");
                $stmt->execute([$table]);
                $info = $stmt->fetch();
                
                if ($info) {
                    echo "  📊 Строк: " . number_format($info['TABLE_ROWS']) . "\n";
                    echo "  💾 Размер данных: " . $this->formatBytes($info['DATA_LENGTH']) . "\n";
                    echo "  🗂️  Размер индексов: " . $this->formatBytes($info['INDEX_LENGTH']) . "\n";
                    echo "  📦 Общий размер: " . $this->formatBytes($info['TOTAL_SIZE']) . "\n";
                }
                
            } catch (Exception $e) {
                echo "  ❌ Ошибка анализа: " . $e->getMessage() . "\n";
            }
        }
    }
    
    private function analyzeIndexes() {
        $tables = ['inventory_data', 'dim_products'];
        
        foreach ($tables as $table) {
            echo "🗂️  Индексы таблицы $table:\n";
            
            try {
                $stmt = $this->pdo->prepare("SHOW INDEX FROM $table");
                $stmt->execute();
                $indexes = $stmt->fetchAll();
                
                $index_stats = [];
                foreach ($indexes as $index) {
                    $key_name = $index['Key_name'];
                    if (!isset($index_stats[$key_name])) {
                        $index_stats[$key_name] = [
                            'columns' => [],
                            'unique' => $index['Non_unique'] == 0,
                            'cardinality' => 0
                        ];
                    }
                    $index_stats[$key_name]['columns'][] = $index['Column_name'];
                    $index_stats[$key_name]['cardinality'] += $index['Cardinality'];
                }
                
                foreach ($index_stats as $index_name => $stats) {
                    $columns = implode(', ', $stats['columns']);
                    $unique = $stats['unique'] ? 'UNIQUE' : 'INDEX';
                    $cardinality = number_format($stats['cardinality']);
                    
                    echo "  📌 $index_name ($unique): $columns (Cardinality: $cardinality)\n";
                }
                
            } catch (Exception $e) {
                echo "  ❌ Ошибка анализа индексов: " . $e->getMessage() . "\n";
            }
        }
    }
    
    private function testQueryPerformance() {
        $queries = [
            'dashboard_main' => "
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
            ",
            'critical_products' => "
                SELECT i.sku, i.warehouse_name, SUM(i.current_stock) as total_stock
                FROM inventory_data i
                WHERE i.current_stock IS NOT NULL
                GROUP BY i.sku, i.warehouse_name
                HAVING SUM(i.current_stock) <= 5
                LIMIT 50
            ",
            'warehouse_summary' => "
                SELECT 
                    i.warehouse_name,
                    COUNT(DISTINCT i.sku) as total_products,
                    SUM(i.current_stock) as total_stock
                FROM inventory_data i
                WHERE i.current_stock IS NOT NULL
                GROUP BY i.warehouse_name
            ",
            'product_names_join' => "
                SELECT i.sku, dp.product_name, i.current_stock
                FROM inventory_data i
                LEFT JOIN dim_products dp ON (i.sku = dp.sku_ozon OR i.sku = dp.sku_wb)
                WHERE i.current_stock IS NOT NULL
                LIMIT 100
            "
        ];
        
        foreach ($queries as $query_name => $sql) {
            echo "🔍 Тест запроса '$query_name':\n";
            
            try {
                // Включаем профилирование
                $this->pdo->exec("SET profiling = 1");
                
                $start = microtime(true);
                $stmt = $this->pdo->prepare($sql);
                $stmt->execute();
                $results = $stmt->fetchAll();
                $execution_time = microtime(true) - $start;
                
                echo "  ⏱️  Время выполнения: " . round($execution_time * 1000, 2) . " мс\n";
                echo "  📊 Результатов: " . count($results) . "\n";
                
                // Получаем план выполнения
                $explain_stmt = $this->pdo->prepare("EXPLAIN " . $sql);
                $explain_stmt->execute();
                $explain = $explain_stmt->fetchAll();
                
                foreach ($explain as $row) {
                    $key = $row['key'] ?: 'NO INDEX';
                    $rows = number_format($row['rows']);
                    echo "  🗂️  Таблица: {$row['table']}, Индекс: $key, Строк: $rows\n";
                }
                
                // Проверяем производительность
                if ($execution_time > 1.0) {
                    echo "  ⚠️  МЕДЛЕННЫЙ ЗАПРОС (>1с)\n";
                } elseif ($execution_time > 0.1) {
                    echo "  ⚠️  Запрос требует оптимизации (>100мс)\n";
                } else {
                    echo "  ✅ Хорошая производительность\n";
                }
                
            } catch (Exception $e) {
                echo "  ❌ Ошибка выполнения: " . $e->getMessage() . "\n";
            }
            
            echo "\n";
        }
    }
    
    private function testCachePerformance() {
        require_once __DIR__ . '/../api/inventory_cache_manager.php';
        
        $cache = getInventoryCacheManager();
        
        if (!$cache->isEnabled()) {
            echo "⚠️  Кэширование отключено\n";
            return;
        }
        
        echo "🚀 Тест производительности кэша:\n";
        
        // Тест записи
        $write_times = [];
        for ($i = 0; $i < 10; $i++) {
            $start = microtime(true);
            $cache->set("perf_test_$i", ['data' => str_repeat('x', 1000), 'timestamp' => time()]);
            $write_times[] = microtime(true) - $start;
        }
        
        $avg_write = array_sum($write_times) / count($write_times);
        echo "  ✍️  Средняя запись: " . round($avg_write * 1000, 2) . " мс\n";
        
        // Тест чтения
        $read_times = [];
        for ($i = 0; $i < 10; $i++) {
            $start = microtime(true);
            $cache->get("perf_test_$i");
            $read_times[] = microtime(true) - $start;
        }
        
        $avg_read = array_sum($read_times) / count($read_times);
        echo "  📖 Среднее чтение: " . round($avg_read * 1000, 2) . " мс\n";
        
        // Очистка тестовых данных
        for ($i = 0; $i < 10; $i++) {
            $cache->delete("perf_test_$i");
        }
        
        // Статистика кэша
        $stats = $cache->getStats();
        echo "  📊 Файлов в кэше: {$stats['total_files']}\n";
        echo "  💾 Размер кэша: {$stats['total_size_mb']} MB\n";
    }
    
    private function testApiPerformance() {
        echo "🌐 Тест производительности API:\n";
        
        $api_endpoints = [
            'dashboard' => '../api/inventory-analytics.php?action=dashboard',
            'critical-products' => '../api/inventory-analytics.php?action=critical-products',
            'warehouse-summary' => '../api/inventory-analytics.php?action=warehouse-summary'
        ];
        
        foreach ($api_endpoints as $endpoint_name => $url) {
            echo "  🔗 Тест endpoint '$endpoint_name':\n";
            
            try {
                $start = microtime(true);
                
                // Симулируем HTTP запрос через включение файла
                ob_start();
                $_GET['action'] = str_replace('../api/inventory-analytics.php?action=', '', $url);
                
                include __DIR__ . '/../api/inventory-analytics.php';
                
                $response = ob_get_clean();
                $execution_time = microtime(true) - $start;
                
                $data = json_decode($response, true);
                
                echo "    ⏱️  Время ответа: " . round($execution_time * 1000, 2) . " мс\n";
                
                if ($data && $data['status'] === 'success') {
                    echo "    ✅ Успешный ответ\n";
                    
                    if (isset($data['metadata']['cached'])) {
                        echo "    💾 Кэширование: " . ($data['metadata']['cached'] ? 'Да' : 'Нет') . "\n";
                    }
                } else {
                    echo "    ❌ Ошибка в ответе\n";
                }
                
                // Оценка производительности
                if ($execution_time > 3.0) {
                    echo "    ⚠️  ОЧЕНЬ МЕДЛЕННО (>3с)\n";
                } elseif ($execution_time > 1.0) {
                    echo "    ⚠️  МЕДЛЕННО (>1с)\n";
                } elseif ($execution_time > 0.5) {
                    echo "    ⚠️  Требует оптимизации (>500мс)\n";
                } else {
                    echo "    ✅ Хорошая производительность\n";
                }
                
            } catch (Exception $e) {
                echo "    ❌ Ошибка: " . $e->getMessage() . "\n";
            }
            
            // Очищаем $_GET для следующего теста
            unset($_GET['action']);
        }
    }
    
    private function generateReport() {
        echo "=== ИТОГОВЫЙ ОТЧЕТ ===\n\n";
        
        $total_tests = count($this->results);
        $successful_tests = count(array_filter($this->results, function($r) { return $r['status'] === 'success'; }));
        $failed_tests = $total_tests - $successful_tests;
        
        echo "📊 Общая статистика:\n";
        echo "  Всего тестов: $total_tests\n";
        echo "  Успешных: $successful_tests\n";
        echo "  Неудачных: $failed_tests\n";
        echo "  Успешность: " . round(($successful_tests / $total_tests) * 100, 1) . "%\n\n";
        
        echo "⏱️  Время выполнения тестов:\n";
        foreach ($this->results as $test => $result) {
            $status_icon = $result['status'] === 'success' ? '✅' : '❌';
            $time = round($result['execution_time'] * 1000, 2);
            echo "  $status_icon $test: {$time} мс\n";
        }
        
        echo "\n🎯 Рекомендации:\n";
        $this->generateRecommendations();
    }
    
    private function generateRecommendations() {
        $recommendations = [];
        
        // Анализируем результаты и генерируем рекомендации
        foreach ($this->results as $test => $result) {
            if ($result['status'] === 'error') {
                $recommendations[] = "❌ Исправить ошибки в тесте '$test': {$result['message']}";
            } elseif ($result['execution_time'] > 1.0) {
                $recommendations[] = "⚠️  Оптимизировать '$test' - время выполнения превышает 1 секунду";
            }
        }
        
        // Общие рекомендации
        $recommendations[] = "📈 Регулярно запускать мониторинг производительности";
        $recommendations[] = "🗂️  Проверять эффективность индексов при росте данных";
        $recommendations[] = "💾 Настроить автоматическую очистку кэша";
        $recommendations[] = "📊 Мониторить размер таблиц и планировать архивирование";
        
        foreach ($recommendations as $recommendation) {
            echo "  $recommendation\n";
        }
    }
    
    private function formatBytes($bytes) {
        $units = ['B', 'KB', 'MB', 'GB'];
        $bytes = max($bytes, 0);
        $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
        $pow = min($pow, count($units) - 1);
        
        $bytes /= pow(1024, $pow);
        
        return round($bytes, 2) . ' ' . $units[$pow];
    }
}

// Запуск мониторинга
if (php_sapi_name() === 'cli') {
    $monitor = new InventoryPerformanceMonitor();
    $args = array_slice($argv, 1);
    $monitor->run($args);
} else {
    echo "Эта утилита должна запускаться из командной строки\n";
}
?>