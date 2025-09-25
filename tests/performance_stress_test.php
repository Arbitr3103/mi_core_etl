<?php
/**
 * Стресс-тест производительности для системы фильтрации по странам
 * 
 * Проверяет работу системы при больших объемах данных и высокой нагрузке
 * 
 * @version 1.0
 * @author ZUZ System
 */

require_once 'CountryFilterAPI.php';

class PerformanceStressTest {
    private $api;
    private $results = [];
    private $errors = [];
    
    public function __construct() {
        $this->api = new CountryFilterAPI();
    }
    
    /**
     * Запуск всех стресс-тестов
     */
    public function runAllTests() {
        echo "=== СТРЕСС-ТЕСТ ПРОИЗВОДИТЕЛЬНОСТИ ФИЛЬТРА ПО СТРАНАМ ===\n\n";
        
        $this->testHighVolumeRequests();
        $this->testConcurrentRequests();
        $this->testLargeDatasetFiltering();
        $this->testMemoryUsage();
        $this->testDatabasePerformance();
        
        $this->printDetailedSummary();
    }
    
    /**
     * Тест высокой частоты запросов
     */
    private function testHighVolumeRequests() {
        echo "1. 🚀 Тест высокой частоты запросов:\n";
        
        $requestCount = 100;
        $times = [];
        $errors = 0;
        
        $startTime = microtime(true);
        
        for ($i = 0; $i < $requestCount; $i++) {
            $requestStart = microtime(true);
            
            try {
                $result = $this->api->getAllCountries();
                
                if (!$result['success']) {
                    $errors++;
                }
                
            } catch (Exception $e) {
                $errors++;
                $this->errors[] = "Request $i failed: " . $e->getMessage();
            }
            
            $requestEnd = microtime(true);
            $times[] = ($requestEnd - $requestStart) * 1000;
        }
        
        $totalTime = microtime(true) - $startTime;
        $avgTime = array_sum($times) / count($times);
        $maxTime = max($times);
        $minTime = min($times);
        $requestsPerSecond = $requestCount / $totalTime;
        
        echo "   Общее время: " . number_format($totalTime, 2) . " сек\n";
        echo "   Запросов в секунду: " . number_format($requestsPerSecond, 2) . "\n";
        echo "   Среднее время запроса: " . number_format($avgTime, 2) . " мс\n";
        echo "   Минимальное время: " . number_format($minTime, 2) . " мс\n";
        echo "   Максимальное время: " . number_format($maxTime, 2) . " мс\n";
        echo "   Ошибок: $errors из $requestCount\n";
        
        $this->results['high_volume'] = [
            'total_time' => $totalTime,
            'requests_per_second' => $requestsPerSecond,
            'avg_time' => $avgTime,
            'max_time' => $maxTime,
            'min_time' => $minTime,
            'errors' => $errors,
            'success_rate' => (($requestCount - $errors) / $requestCount) * 100
        ];
        
        if ($requestsPerSecond > 50 && $errors == 0) {
            echo "   ✅ Отличная производительность\n\n";
        } elseif ($requestsPerSecond > 20 && $errors < 5) {
            echo "   ✅ Хорошая производительность\n\n";
        } else {
            echo "   ❌ Требуется оптимизация\n\n";
        }
    }
    
    /**
     * Тест параллельных запросов (имитация)
     */
    private function testConcurrentRequests() {
        echo "2. 🔄 Тест параллельных запросов:\n";
        
        $concurrentUsers = 10;
        $requestsPerUser = 10;
        $times = [];
        $errors = 0;
        
        $startTime = microtime(true);
        
        // Имитируем параллельные запросы через быстрые последовательные вызовы
        for ($user = 0; $user < $concurrentUsers; $user++) {
            for ($req = 0; $req < $requestsPerUser; $req++) {
                $requestStart = microtime(true);
                
                try {
                    // Чередуем разные типы запросов
                    switch ($req % 4) {
                        case 0:
                            $result = $this->api->getAllCountries();
                            break;
                        case 1:
                            $result = $this->api->getCountriesByBrand(1);
                            break;
                        case 2:
                            $result = $this->api->getCountriesByModel(1);
                            break;
                        case 3:
                            $result = $this->api->filterProducts(['brand_id' => 1, 'country_id' => 1]);
                            break;
                    }
                    
                    if (!$result['success']) {
                        $errors++;
                    }
                    
                } catch (Exception $e) {
                    $errors++;
                    $this->errors[] = "Concurrent request failed: " . $e->getMessage();
                }
                
                $requestEnd = microtime(true);
                $times[] = ($requestEnd - $requestStart) * 1000;
                
                // Небольшая задержка для имитации реального использования
                usleep(10000); // 10ms
            }
        }
        
        $totalTime = microtime(true) - $startTime;
        $totalRequests = $concurrentUsers * $requestsPerUser;
        $avgTime = array_sum($times) / count($times);
        $throughput = $totalRequests / $totalTime;
        
        echo "   Пользователей: $concurrentUsers\n";
        echo "   Запросов на пользователя: $requestsPerUser\n";
        echo "   Общее время: " . number_format($totalTime, 2) . " сек\n";
        echo "   Пропускная способность: " . number_format($throughput, 2) . " запросов/сек\n";
        echo "   Среднее время ответа: " . number_format($avgTime, 2) . " мс\n";
        echo "   Ошибок: $errors из $totalRequests\n";
        
        $this->results['concurrent'] = [
            'users' => $concurrentUsers,
            'total_requests' => $totalRequests,
            'throughput' => $throughput,
            'avg_time' => $avgTime,
            'errors' => $errors,
            'success_rate' => (($totalRequests - $errors) / $totalRequests) * 100
        ];
        
        if ($throughput > 30 && $errors == 0) {
            echo "   ✅ Отличная параллельная обработка\n\n";
        } elseif ($throughput > 15 && $errors < 10) {
            echo "   ✅ Хорошая параллельная обработка\n\n";
        } else {
            echo "   ❌ Проблемы с параллельной обработкой\n\n";
        }
    }
    
    /**
     * Тест фильтрации больших наборов данных
     */
    private function testLargeDatasetFiltering() {
        echo "3. 📊 Тест фильтрации больших наборов данных:\n";
        
        $complexFilters = [
            ['brand_id' => 1],
            ['brand_id' => 1, 'country_id' => 1],
            ['brand_id' => 1, 'model_id' => 1],
            ['brand_id' => 1, 'model_id' => 1, 'country_id' => 1],
            ['brand_id' => 1, 'model_id' => 1, 'country_id' => 1, 'year' => 2020]
        ];
        
        foreach ($complexFilters as $index => $filters) {
            $iterations = 5;
            $times = [];
            $resultCounts = [];
            
            for ($i = 0; $i < $iterations; $i++) {
                $start = microtime(true);
                
                try {
                    $result = $this->api->filterProducts($filters);
                    
                    if ($result['success']) {
                        $resultCounts[] = count($result['data']);
                    }
                    
                } catch (Exception $e) {
                    $this->errors[] = "Large dataset filter failed: " . $e->getMessage();
                }
                
                $end = microtime(true);
                $times[] = ($end - $start) * 1000;
            }
            
            $avgTime = array_sum($times) / count($times);
            $avgResults = count($resultCounts) > 0 ? array_sum($resultCounts) / count($resultCounts) : 0;
            
            $filterDesc = implode(', ', array_map(function($k, $v) {
                return "$k=$v";
            }, array_keys($filters), $filters));
            
            echo "   Фильтр " . ($index + 1) . " ($filterDesc):\n";
            echo "     Время: " . number_format($avgTime, 2) . " мс\n";
            echo "     Результатов: " . number_format($avgResults, 0) . "\n";
            
            if ($avgTime < 500) {
                echo "     ✅ Быстрая фильтрация\n";
            } elseif ($avgTime < 1000) {
                echo "     ✅ Приемлемая скорость\n";
            } else {
                echo "     ❌ Медленная фильтрация\n";
            }
        }
        
        echo "\n";
    }
    
    /**
     * Тест использования памяти
     */
    private function testMemoryUsage() {
        echo "4. 💾 Тест использования памяти:\n";
        
        $initialMemory = memory_get_usage(true);
        $peakMemory = memory_get_peak_usage(true);
        
        // Выполняем серию операций для нагрузки памяти
        for ($i = 0; $i < 50; $i++) {
            $result = $this->api->getAllCountries();
            $result = $this->api->getCountriesByBrand(1);
            $result = $this->api->filterProducts(['brand_id' => 1]);
        }
        
        $finalMemory = memory_get_usage(true);
        $finalPeakMemory = memory_get_peak_usage(true);
        
        $memoryUsed = $finalMemory - $initialMemory;
        $peakMemoryUsed = $finalPeakMemory - $peakMemory;
        
        echo "   Начальная память: " . $this->formatBytes($initialMemory) . "\n";
        echo "   Конечная память: " . $this->formatBytes($finalMemory) . "\n";
        echo "   Использовано памяти: " . $this->formatBytes($memoryUsed) . "\n";
        echo "   Пиковое использование: " . $this->formatBytes($peakMemoryUsed) . "\n";
        
        $this->results['memory'] = [
            'initial' => $initialMemory,
            'final' => $finalMemory,
            'used' => $memoryUsed,
            'peak' => $peakMemoryUsed
        ];
        
        if ($memoryUsed < 5 * 1024 * 1024) { // 5MB
            echo "   ✅ Эффективное использование памяти\n\n";
        } elseif ($memoryUsed < 10 * 1024 * 1024) { // 10MB
            echo "   ✅ Приемлемое использование памяти\n\n";
        } else {
            echo "   ❌ Высокое потребление памяти\n\n";
        }
    }
    
    /**
     * Тест производительности базы данных
     */
    private function testDatabasePerformance() {
        echo "5. 🗄️ Тест производительности базы данных:\n";
        
        try {
            $db = new CountryFilterDatabase();
            $pdo = $db->getConnection();
            
            // Тест сложных JOIN запросов
            $complexQuery = "
                SELECT DISTINCT r.id, r.name, COUNT(p.id) as product_count
                FROM regions r
                LEFT JOIN brands b ON r.id = b.region_id
                LEFT JOIN car_models cm ON b.id = cm.brand_id
                LEFT JOIN car_specifications cs ON cm.id = cs.car_model_id
                LEFT JOIN dim_products p ON cs.id = p.specification_id
                GROUP BY r.id, r.name
                ORDER BY product_count DESC
            ";
            
            $iterations = 10;
            $times = [];
            
            for ($i = 0; $i < $iterations; $i++) {
                $start = microtime(true);
                
                $stmt = $pdo->prepare($complexQuery);
                $stmt->execute();
                $results = $stmt->fetchAll();
                
                $end = microtime(true);
                $times[] = ($end - $start) * 1000;
            }
            
            $avgTime = array_sum($times) / count($times);
            $resultCount = count($results);
            
            echo "   Сложный JOIN запрос:\n";
            echo "     Среднее время: " . number_format($avgTime, 2) . " мс\n";
            echo "     Результатов: $resultCount\n";
            
            if ($avgTime < 100) {
                echo "     ✅ Отличная производительность БД\n";
            } elseif ($avgTime < 500) {
                echo "     ✅ Хорошая производительность БД\n";
            } else {
                echo "     ❌ Медленные запросы к БД\n";
            }
            
            // Проверка индексов
            $this->checkDatabaseIndexes($pdo);
            
        } catch (Exception $e) {
            echo "   ❌ Ошибка тестирования БД: " . $e->getMessage() . "\n";
            $this->errors[] = "Database test failed: " . $e->getMessage();
        }
        
        echo "\n";
    }
    
    /**
     * Проверка индексов базы данных
     */
    private function checkDatabaseIndexes($pdo) {
        echo "   Проверка индексов:\n";
        
        $indexQueries = [
            'brands.region_id' => "SHOW INDEX FROM brands WHERE Column_name = 'region_id'",
            'car_models.brand_id' => "SHOW INDEX FROM car_models WHERE Column_name = 'brand_id'",
            'car_specifications.car_model_id' => "SHOW INDEX FROM car_specifications WHERE Column_name = 'car_model_id'",
            'dim_products.specification_id' => "SHOW INDEX FROM dim_products WHERE Column_name = 'specification_id'"
        ];
        
        foreach ($indexQueries as $indexName => $query) {
            try {
                $stmt = $pdo->prepare($query);
                $stmt->execute();
                $indexes = $stmt->fetchAll();
                
                if (count($indexes) > 0) {
                    echo "     ✅ $indexName: индекс найден\n";
                } else {
                    echo "     ❌ $indexName: индекс отсутствует\n";
                }
                
            } catch (Exception $e) {
                echo "     ⚠️ $indexName: ошибка проверки\n";
            }
        }
    }
    
    /**
     * Форматирование размера в байтах
     */
    private function formatBytes($bytes, $precision = 2) {
        $units = array('B', 'KB', 'MB', 'GB', 'TB');
        
        for ($i = 0; $bytes > 1024; $i++) {
            $bytes /= 1024;
        }
        
        return round($bytes, $precision) . ' ' . $units[$i];
    }
    
    /**
     * Детальная сводка результатов
     */
    private function printDetailedSummary() {
        echo "=== ДЕТАЛЬНАЯ СВОДКА СТРЕСС-ТЕСТИРОВАНИЯ ===\n\n";
        
        // Общая оценка производительности
        $overallScore = 0;
        $maxScore = 0;
        
        if (isset($this->results['high_volume'])) {
            $hv = $this->results['high_volume'];
            echo "📈 ВЫСОКАЯ ЧАСТОТА ЗАПРОСОВ:\n";
            echo "   Запросов в секунду: " . number_format($hv['requests_per_second'], 2) . "\n";
            echo "   Успешность: " . number_format($hv['success_rate'], 1) . "%\n";
            echo "   Среднее время: " . number_format($hv['avg_time'], 2) . " мс\n";
            
            if ($hv['requests_per_second'] > 50 && $hv['success_rate'] > 95) {
                $overallScore += 25;
                echo "   🏆 Отличный результат\n\n";
            } elseif ($hv['requests_per_second'] > 20 && $hv['success_rate'] > 90) {
                $overallScore += 20;
                echo "   ✅ Хороший результат\n\n";
            } else {
                $overallScore += 10;
                echo "   ⚠️ Требуется оптимизация\n\n";
            }
            $maxScore += 25;
        }
        
        if (isset($this->results['concurrent'])) {
            $cc = $this->results['concurrent'];
            echo "🔄 ПАРАЛЛЕЛЬНЫЕ ЗАПРОСЫ:\n";
            echo "   Пропускная способность: " . number_format($cc['throughput'], 2) . " запросов/сек\n";
            echo "   Успешность: " . number_format($cc['success_rate'], 1) . "%\n";
            echo "   Среднее время ответа: " . number_format($cc['avg_time'], 2) . " мс\n";
            
            if ($cc['throughput'] > 30 && $cc['success_rate'] > 95) {
                $overallScore += 25;
                echo "   🏆 Отличная параллельная обработка\n\n";
            } elseif ($cc['throughput'] > 15 && $cc['success_rate'] > 90) {
                $overallScore += 20;
                echo "   ✅ Хорошая параллельная обработка\n\n";
            } else {
                $overallScore += 10;
                echo "   ⚠️ Проблемы с параллельностью\n\n";
            }
            $maxScore += 25;
        }
        
        if (isset($this->results['memory'])) {
            $mem = $this->results['memory'];
            echo "💾 ИСПОЛЬЗОВАНИЕ ПАМЯТИ:\n";
            echo "   Использовано: " . $this->formatBytes($mem['used']) . "\n";
            echo "   Пиковое использование: " . $this->formatBytes($mem['peak']) . "\n";
            
            if ($mem['used'] < 5 * 1024 * 1024) {
                $overallScore += 25;
                echo "   🏆 Эффективное использование памяти\n\n";
            } elseif ($mem['used'] < 10 * 1024 * 1024) {
                $overallScore += 20;
                echo "   ✅ Приемлемое использование памяти\n\n";
            } else {
                $overallScore += 10;
                echo "   ⚠️ Высокое потребление памяти\n\n";
            }
            $maxScore += 25;
        }
        
        // Ошибки
        if (count($this->errors) > 0) {
            echo "❌ ОБНАРУЖЕННЫЕ ОШИБКИ:\n";
            foreach ($this->errors as $index => $error) {
                echo "   " . ($index + 1) . ". $error\n";
            }
            echo "\n";
        } else {
            $overallScore += 25;
            $maxScore += 25;
            echo "✅ ОШИБОК НЕ ОБНАРУЖЕНО\n\n";
        }
        
        // Общая оценка
        $scorePercentage = $maxScore > 0 ? ($overallScore / $maxScore) * 100 : 0;
        
        echo "🎯 ОБЩАЯ ОЦЕНКА ПРОИЗВОДИТЕЛЬНОСТИ: " . number_format($scorePercentage, 1) . "%\n";
        
        if ($scorePercentage >= 90) {
            echo "🏆 ПРЕВОСХОДНО! Система готова к высоким нагрузкам.\n";
        } elseif ($scorePercentage >= 75) {
            echo "✅ ХОРОШО! Система справляется с нагрузкой.\n";
        } elseif ($scorePercentage >= 60) {
            echo "⚠️ УДОВЛЕТВОРИТЕЛЬНО! Рекомендуется оптимизация.\n";
        } else {
            echo "❌ НЕУДОВЛЕТВОРИТЕЛЬНО! Требуется серьезная оптимизация.\n";
        }
        
        echo "\n📋 РЕКОМЕНДАЦИИ ПО ОПТИМИЗАЦИИ:\n";
        
        if (isset($this->results['high_volume']) && $this->results['high_volume']['requests_per_second'] < 30) {
            echo "• Оптимизируйте SQL запросы и добавьте индексы\n";
        }
        
        if (isset($this->results['memory']) && $this->results['memory']['used'] > 10 * 1024 * 1024) {
            echo "• Оптимизируйте использование памяти и добавьте кэширование\n";
        }
        
        if (count($this->errors) > 0) {
            echo "• Исправьте обнаруженные ошибки\n";
        }
        
        echo "• Рассмотрите использование Redis для кэширования\n";
        echo "• Настройте connection pooling для базы данных\n";
        echo "• Мониторьте производительность в продакшене\n";
        
        echo "\n" . str_repeat("=", 60) . "\n";
    }
}

// Запуск стресс-тестов
if (php_sapi_name() === 'cli') {
    $test = new PerformanceStressTest();
    $test->runAllTests();
} else {
    header('Content-Type: text/plain; charset=utf-8');
    $test = new PerformanceStressTest();
    $test->runAllTests();
}
?>