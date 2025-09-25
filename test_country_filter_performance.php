<?php
/**
 * Тест производительности для оптимизированного фильтра по странам
 * 
 * Проверяет эффективность индексов и кэширования
 */

require_once 'CountryFilterAPI.php';

class CountryFilterPerformanceTest {
    private $api;
    private $results = [];
    
    public function __construct() {
        $this->api = new CountryFilterAPI();
    }
    
    /**
     * Запуск всех тестов производительности
     */
    public function runAllTests() {
        echo "=== Тест производительности фильтра по странам ===\n\n";
        
        $this->testGetAllCountries();
        $this->testGetCountriesByBrand();
        $this->testGetCountriesByModel();
        $this->testFilterProducts();
        $this->testCaching();
        
        $this->printSummary();
    }
    
    /**
     * Тест загрузки всех стран
     */
    private function testGetAllCountries() {
        echo "1. Тест загрузки всех стран:\n";
        
        $iterations = 10;
        $times = [];
        
        for ($i = 0; $i < $iterations; $i++) {
            $start = microtime(true);
            $result = $this->api->getAllCountries();
            $end = microtime(true);
            
            $times[] = ($end - $start) * 1000; // в миллисекундах
        }
        
        $avgTime = array_sum($times) / count($times);
        $minTime = min($times);
        $maxTime = max($times);
        
        echo "   Среднее время: " . number_format($avgTime, 2) . " мс\n";
        echo "   Минимальное время: " . number_format($minTime, 2) . " мс\n";
        echo "   Максимальное время: " . number_format($maxTime, 2) . " мс\n";
        echo "   Количество стран: " . (isset($result['data']) ? count($result['data']) : 0) . "\n\n";
        
        $this->results['getAllCountries'] = [
            'avg' => $avgTime,
            'min' => $minTime,
            'max' => $maxTime,
            'count' => isset($result['data']) ? count($result['data']) : 0
        ];
    }
    
    /**
     * Тест загрузки стран по марке
     */
    private function testGetCountriesByBrand() {
        echo "2. Тест загрузки стран по марке:\n";
        
        // Тестируем с разными ID марок
        $brandIds = [1, 2, 3, 5, 10];
        $iterations = 5;
        
        foreach ($brandIds as $brandId) {
            $times = [];
            
            for ($i = 0; $i < $iterations; $i++) {
                $start = microtime(true);
                $result = $this->api->getCountriesByBrand($brandId);
                $end = microtime(true);
                
                $times[] = ($end - $start) * 1000;
            }
            
            $avgTime = array_sum($times) / count($times);
            echo "   Марка ID {$brandId}: " . number_format($avgTime, 2) . " мс";
            echo " (стран: " . (isset($result['data']) ? count($result['data']) : 0) . ")\n";
        }
        echo "\n";
    }
    
    /**
     * Тест загрузки стран по модели
     */
    private function testGetCountriesByModel() {
        echo "3. Тест загрузки стран по модели:\n";
        
        // Тестируем с разными ID моделей
        $modelIds = [1, 2, 3, 5, 10];
        $iterations = 5;
        
        foreach ($modelIds as $modelId) {
            $times = [];
            
            for ($i = 0; $i < $iterations; $i++) {
                $start = microtime(true);
                $result = $this->api->getCountriesByModel($modelId);
                $end = microtime(true);
                
                $times[] = ($end - $start) * 1000;
            }
            
            $avgTime = array_sum($times) / count($times);
            echo "   Модель ID {$modelId}: " . number_format($avgTime, 2) . " мс";
            echo " (стран: " . (isset($result['data']) ? count($result['data']) : 0) . ")\n";
        }
        echo "\n";
    }
    
    /**
     * Тест фильтрации товаров
     */
    private function testFilterProducts() {
        echo "4. Тест фильтрации товаров:\n";
        
        $testCases = [
            ['brand_id' => 1],
            ['brand_id' => 1, 'country_id' => 1],
            ['brand_id' => 1, 'model_id' => 1],
            ['brand_id' => 1, 'model_id' => 1, 'country_id' => 1],
            ['brand_id' => 1, 'model_id' => 1, 'country_id' => 1, 'year' => 2020]
        ];
        
        foreach ($testCases as $index => $filters) {
            $iterations = 3;
            $times = [];
            
            for ($i = 0; $i < $iterations; $i++) {
                $start = microtime(true);
                $result = $this->api->filterProducts($filters);
                $end = microtime(true);
                
                $times[] = ($end - $start) * 1000;
            }
            
            $avgTime = array_sum($times) / count($times);
            $filterDesc = implode(', ', array_map(function($k, $v) {
                return "{$k}={$v}";
            }, array_keys($filters), $filters));
            
            echo "   Фильтр " . ($index + 1) . " ({$filterDesc}): " . number_format($avgTime, 2) . " мс";
            echo " (товаров: " . (isset($result['data']) ? count($result['data']) : 0) . ")\n";
        }
        echo "\n";
    }
    
    /**
     * Тест кэширования
     */
    private function testCaching() {
        echo "5. Тест кэширования:\n";
        
        // Первый запрос (без кэша)
        $start = microtime(true);
        $result1 = $this->api->getAllCountries();
        $end = microtime(true);
        $firstCallTime = ($end - $start) * 1000;
        
        // Второй запрос (с кэшем)
        $start = microtime(true);
        $result2 = $this->api->getAllCountries();
        $end = microtime(true);
        $secondCallTime = ($end - $start) * 1000;
        
        $improvement = (($firstCallTime - $secondCallTime) / $firstCallTime) * 100;
        
        echo "   Первый запрос (без кэша): " . number_format($firstCallTime, 2) . " мс\n";
        echo "   Второй запрос (с кэшем): " . number_format($secondCallTime, 2) . " мс\n";
        echo "   Улучшение производительности: " . number_format($improvement, 1) . "%\n\n";
        
        $this->results['caching'] = [
            'first' => $firstCallTime,
            'second' => $secondCallTime,
            'improvement' => $improvement
        ];
    }
    
    /**
     * Вывод сводки результатов
     */
    private function printSummary() {
        echo "=== СВОДКА РЕЗУЛЬТАТОВ ===\n";
        
        if (isset($this->results['getAllCountries'])) {
            $avg = $this->results['getAllCountries']['avg'];
            echo "Загрузка всех стран: " . number_format($avg, 2) . " мс (среднее)\n";
            
            if ($avg < 50) {
                echo "✅ Отличная производительность (< 50 мс)\n";
            } elseif ($avg < 100) {
                echo "✅ Хорошая производительность (< 100 мс)\n";
            } elseif ($avg < 200) {
                echo "⚠️ Приемлемая производительность (< 200 мс)\n";
            } else {
                echo "❌ Требуется оптимизация (> 200 мс)\n";
            }
        }
        
        if (isset($this->results['caching'])) {
            $improvement = $this->results['caching']['improvement'];
            echo "Эффективность кэширования: " . number_format($improvement, 1) . "%\n";
            
            if ($improvement > 50) {
                echo "✅ Кэширование работает отлично\n";
            } elseif ($improvement > 20) {
                echo "✅ Кэширование работает хорошо\n";
            } elseif ($improvement > 0) {
                echo "⚠️ Кэширование работает, но можно улучшить\n";
            } else {
                echo "❌ Кэширование не работает\n";
            }
        }
        
        echo "\n=== РЕКОМЕНДАЦИИ ===\n";
        echo "1. Убедитесь, что созданы все индексы из create_country_filter_indexes.sql\n";
        echo "2. Проверьте настройки MySQL для оптимизации JOIN операций\n";
        echo "3. Рассмотрите возможность использования Redis для кэширования\n";
        echo "4. Мониторьте производительность в продакшене\n";
    }
    
    /**
     * Проверка индексов в базе данных
     */
    public function checkIndexes() {
        echo "=== ПРОВЕРКА ИНДЕКСОВ ===\n";
        
        try {
            $db = new CountryFilterDatabase();
            $pdo = $db->getConnection();
            
            $sql = "
                SELECT 
                    TABLE_NAME,
                    INDEX_NAME,
                    GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX) as COLUMNS
                FROM INFORMATION_SCHEMA.STATISTICS 
                WHERE TABLE_SCHEMA = DATABASE() 
                  AND TABLE_NAME IN ('brands', 'car_models', 'car_specifications', 'dim_products', 'regions')
                  AND INDEX_NAME LIKE 'idx_%'
                GROUP BY TABLE_NAME, INDEX_NAME
                ORDER BY TABLE_NAME, INDEX_NAME
            ";
            
            $stmt = $pdo->prepare($sql);
            $stmt->execute();
            $indexes = $stmt->fetchAll();
            
            if (empty($indexes)) {
                echo "❌ Индексы для оптимизации не найдены!\n";
                echo "Выполните скрипт create_country_filter_indexes.sql\n\n";
            } else {
                echo "✅ Найдены следующие индексы:\n";
                foreach ($indexes as $index) {
                    echo "   {$index['TABLE_NAME']}.{$index['INDEX_NAME']} ({$index['COLUMNS']})\n";
                }
                echo "\n";
            }
            
        } catch (Exception $e) {
            echo "❌ Ошибка проверки индексов: " . $e->getMessage() . "\n\n";
        }
    }
}

// Запуск тестов
if (php_sapi_name() === 'cli') {
    $test = new CountryFilterPerformanceTest();
    $test->checkIndexes();
    $test->runAllTests();
} else {
    // Для веб-интерфейса
    header('Content-Type: text/plain; charset=utf-8');
    $test = new CountryFilterPerformanceTest();
    $test->checkIndexes();
    $test->runAllTests();
}
?>