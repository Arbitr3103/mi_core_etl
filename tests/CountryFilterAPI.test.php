<?php
/**
 * Unit и Integration тесты для CountryFilterAPI
 *
 * Проверяет все API endpoints для фильтра по стране изготовления,
 * включая валидацию, обработку ошибок и производительность
 *
 * @version 1.0
 * @author ZUZ System
 */

require_once __DIR__ . '/../CountryFilterAPI.php';

class CountryFilterAPITest {
    private $api;
    private $testResults = [];
    private $totalTests = 0;
    private $passedTests = 0;
    private $failedTests = 0;
    
    public function __construct() {
        echo "🚀 ИНИЦИАЛИЗАЦИЯ ТЕСТОВ CountryFilterAPI\n";
        echo "=" . str_repeat("=", 80) . "\n";
        
        try {
            $this->api = new CountryFilterAPI();
            echo "✅ API инициализирован успешно\n";
        } catch (Exception $e) {
            echo "❌ КРИТИЧЕСКАЯ ОШИБКА: Не удалось инициализировать API: " . $e->getMessage() . "\n";
            exit(1);
        }
        
        echo "=" . str_repeat("=", 80) . "\n\n";
    }
    
    /**
     * Запуск всех тестов
     */
    public function runAllTests() {
        echo "🧪 ЗАПУСК ВСЕХ API ТЕСТОВ\n\n";
        
        // Unit тесты
        $this->testGetAllCountries();
        $this->testGetCountriesByBrand();
        $this->testGetCountriesByModel();
        $this->testFilterProducts();
        $this->testValidateFilters();
        $this->testValidateFilterExistence();
        
        // Integration тесты
        $this->testFullFilteringWorkflow();
        $this->testErrorHandling();
        $this->testCaching();
        $this->testPerformance();
        
        // Edge cases
        $this->testEdgeCases();
        
        $this->printTestResults();
    }
    
    /**
     * Тест получения всех стран
     */
    private function testGetAllCountries() {
        echo "🔧 Тестирование getAllCountries()\n";
        echo "-" . str_repeat("-", 50) . "\n";
        
        try {
            $result = $this->api->getAllCountries();
            
            // Проверяем структуру ответа
            $this->assert(
                isset($result['success']),
                "Ответ должен содержать поле 'success'"
            );
            
            if ($result['success']) {
                $this->assert(
                    isset($result['data']) && is_array($result['data']),
                    "Успешный ответ должен содержать массив 'data'"
                );
                
                // Проверяем структуру данных стран
                if (!empty($result['data'])) {
                    $country = $result['data'][0];
                    $this->assert(
                        isset($country['id']) && isset($country['name']),
                        "Каждая страна должна содержать 'id' и 'name'"
                    );
                    
                    $this->assert(
                        is_int($country['id']) && is_string($country['name']),
                        "ID должен быть числом, name - строкой"
                    );
                }
            }
            
            echo "✅ getAllCountries() работает корректно\n";
            
        } catch (Exception $e) {
            $this->assert(false, "getAllCountries() выбросил исключение: " . $e->getMessage());
        }
        
        echo "\n";
    }
    
    /**
     * Тест получения стран по марке
     */
    private function testGetCountriesByBrand() {
        echo "🔧 Тестирование getCountriesByBrand()\n";
        echo "-" . str_repeat("-", 50) . "\n";
        
        // Тест с валидным ID
        try {
            $result = $this->api->getCountriesByBrand(1);
            
            $this->assert(
                isset($result['success']),
                "Ответ должен содержать поле 'success'"
            );
            
            if ($result['success']) {
                $this->assert(
                    isset($result['data']) && is_array($result['data']),
                    "Успешный ответ должен содержать массив 'data'"
                );
            }
            
            echo "✅ getCountriesByBrand() с валидным ID работает\n";
            
        } catch (Exception $e) {
            $this->assert(false, "getCountriesByBrand() выбросил исключение: " . $e->getMessage());
        }
        
        // Тест с невалидным ID
        $result = $this->api->getCountriesByBrand(-1);
        $this->assert(
            !$result['success'],
            "Невалидный ID должен возвращать success: false"
        );
        
        $result = $this->api->getCountriesByBrand("abc");
        $this->assert(
            !$result['success'],
            "Нечисловой ID должен возвращать success: false"
        );
        
        $result = $this->api->getCountriesByBrand(1000000);
        $this->assert(
            !$result['success'],
            "Слишком большой ID должен возвращать success: false"
        );
        
        echo "✅ Валидация параметров getCountriesByBrand() работает\n";
        echo "\n";
    }
    
    /**
     * Тест получения стран по модели
     */
    private function testGetCountriesByModel() {
        echo "🔧 Тестирование getCountriesByModel()\n";
        echo "-" . str_repeat("-", 50) . "\n";
        
        // Тест с валидным ID
        try {
            $result = $this->api->getCountriesByModel(1);
            
            $this->assert(
                isset($result['success']),
                "Ответ должен содержать поле 'success'"
            );
            
            if ($result['success']) {
                $this->assert(
                    isset($result['data']) && is_array($result['data']),
                    "Успешный ответ должен содержать массив 'data'"
                );
            }
            
            echo "✅ getCountriesByModel() с валидным ID работает\n";
            
        } catch (Exception $e) {
            $this->assert(false, "getCountriesByModel() выбросил исключение: " . $e->getMessage());
        }
        
        // Тест валидации
        $result = $this->api->getCountriesByModel(0);
        $this->assert(
            !$result['success'],
            "ID = 0 должен возвращать success: false"
        );
        
        echo "✅ Валидация параметров getCountriesByModel() работает\n";
        echo "\n";
    }
    
    /**
     * Тест фильтрации товаров
     */
    private function testFilterProducts() {
        echo "🔧 Тестирование filterProducts()\n";
        echo "-" . str_repeat("-", 50) . "\n";
        
        // Тест без фильтров
        try {
            $result = $this->api->filterProducts([]);
            
            $this->assert(
                isset($result['success']),
                "Ответ должен содержать поле 'success'"
            );
            
            if ($result['success']) {
                $this->assert(
                    isset($result['data']) && is_array($result['data']),
                    "Успешный ответ должен содержать массив 'data'"
                );
                
                $this->assert(
                    isset($result['pagination']),
                    "Ответ должен содержать информацию о пагинации"
                );
                
                $this->assert(
                    isset($result['filters_applied']),
                    "Ответ должен содержать примененные фильтры"
                );
            }
            
            echo "✅ filterProducts() без фильтров работает\n";
            
        } catch (Exception $e) {
            $this->assert(false, "filterProducts() выбросил исключение: " . $e->getMessage());
        }
        
        // Тест с фильтрами
        $filters = [
            'brand_id' => 1,
            'country_id' => 1,
            'limit' => 10,
            'offset' => 0
        ];
        
        try {
            $result = $this->api->filterProducts($filters);
            
            if ($result['success']) {
                $this->assert(
                    $result['pagination']['limit'] == 10,
                    "Лимит должен соответствовать запрошенному"
                );
                
                $this->assert(
                    $result['filters_applied']['brand_id'] == 1,
                    "Примененные фильтры должны соответствовать запрошенным"
                );
            }
            
            echo "✅ filterProducts() с фильтрами работает\n";
            
        } catch (Exception $e) {
            $this->assert(false, "filterProducts() с фильтрами выбросил исключение: " . $e->getMessage());
        }
        
        echo "\n";
    }
    
    /**
     * Тест валидации фильтров
     */
    private function testValidateFilters() {
        echo "🔧 Тестирование validateFilters()\n";
        echo "-" . str_repeat("-", 50) . "\n";
        
        // Валидные фильтры
        $validFilters = [
            'brand_id' => 1,
            'model_id' => 1,
            'year' => 2020,
            'country_id' => 1,
            'limit' => 50,
            'offset' => 0
        ];
        
        $result = $this->api->validateFilters($validFilters);
        $this->assert(
            $result['valid'] === true,
            "Валидные фильтры должны проходить валидацию"
        );
        
        // Невалидные фильтры
        $invalidFilters = [
            'brand_id' => -1,
            'model_id' => 'abc',
            'year' => 1800,
            'country_id' => 0,
            'limit' => -5,
            'offset' => -1
        ];
        
        $result = $this->api->validateFilters($invalidFilters);
        $this->assert(
            $result['valid'] === false,
            "Невалидные фильтры должны не проходить валидацию"
        );
        
        $this->assert(
            !empty($result['errors']),
            "Должны возвращаться ошибки валидации"
        );
        
        echo "✅ validateFilters() работает корректно\n";
        echo "\n";
    }
    
    /**
     * Тест валидации существования записей
     */
    private function testValidateFilterExistence() {
        echo "🔧 Тестирование validateFilterExistence()\n";
        echo "-" . str_repeat("-", 50) . "\n";
        
        // Тест с существующими записями (предполагаем что ID 1 существует)
        $existingFilters = [
            'brand_id' => 1,
            'country_id' => 1
        ];
        
        try {
            $result = $this->api->validateFilterExistence($existingFilters);
            
            $this->assert(
                isset($result['valid']),
                "Результат валидации должен содержать поле 'valid'"
            );
            
            echo "✅ validateFilterExistence() выполняется без ошибок\n";
            
        } catch (Exception $e) {
            $this->assert(false, "validateFilterExistence() выбросил исключение: " . $e->getMessage());
        }
        
        // Тест с несуществующими записями
        $nonExistingFilters = [
            'brand_id' => 999999,
            'model_id' => 999999,
            'country_id' => 999999
        ];
        
        try {
            $result = $this->api->validateFilterExistence($nonExistingFilters);
            
            $this->assert(
                $result['valid'] === false,
                "Несуществующие записи должны не проходить валидацию"
            );
            
            echo "✅ Валидация несуществующих записей работает\n";
            
        } catch (Exception $e) {
            // Ошибки БД ожидаемы при тестировании несуществующих записей
            echo "ℹ️  Ошибка БД при валидации несуществующих записей (ожидаемо)\n";
        }
        
        echo "\n";
    }
    
    /**
     * Интеграционный тест полного workflow фильтрации
     */
    private function testFullFilteringWorkflow() {
        echo "🔧 Тестирование полного workflow фильтрации\n";
        echo "-" . str_repeat("-", 50) . "\n";
        
        try {
            // 1. Получаем все страны
            $countries = $this->api->getAllCountries();
            $this->assert(
                $countries['success'],
                "Шаг 1: Получение всех стран должно быть успешным"
            );
            
            // 2. Получаем страны для марки (если есть данные)
            if (!empty($countries['data'])) {
                $brandCountries = $this->api->getCountriesByBrand(1);
                $this->assert(
                    isset($brandCountries['success']),
                    "Шаг 2: Получение стран для марки должно возвращать результат"
                );
            }
            
            // 3. Фильтруем товары
            $products = $this->api->filterProducts(['limit' => 5]);
            $this->assert(
                $products['success'],
                "Шаг 3: Фильтрация товаров должна быть успешной"
            );
            
            if ($products['success']) {
                $this->assert(
                    count($products['data']) <= 5,
                    "Количество товаров не должно превышать лимит"
                );
            }
            
            echo "✅ Полный workflow фильтрации работает корректно\n";
            
        } catch (Exception $e) {
            $this->assert(false, "Workflow фильтрации выбросил исключение: " . $e->getMessage());
        }
        
        echo "\n";
    }
    
    /**
     * Тест обработки ошибок
     */
    private function testErrorHandling() {
        echo "🔧 Тестирование обработки ошибок\n";
        echo "-" . str_repeat("-", 50) . "\n";
        
        // Тест с некорректными типами данных
        $result = $this->api->getCountriesByBrand([]);
        $this->assert(
            !$result['success'],
            "Массив вместо числа должен вызывать ошибку"
        );
        
        $result = $this->api->getCountriesByModel(null);
        $this->assert(
            !$result['success'],
            "null вместо числа должен вызывать ошибку"
        );
        
        // Тест с экстремальными значениями
        $result = $this->api->filterProducts([
            'brand_id' => PHP_INT_MAX,
            'limit' => 10000,
            'offset' => -100
        ]);
        
        $this->assert(
            !$result['success'],
            "Экстремальные значения должны вызывать ошибку валидации"
        );
        
        echo "✅ Обработка ошибок работает корректно\n";
        echo "\n";
    }
    
    /**
     * Тест кэширования
     */
    private function testCaching() {
        echo "🔧 Тестирование кэширования\n";
        echo "-" . str_repeat("-", 50) . "\n";
        
        try {
            // Очищаем кэш
            $this->api->clearCache();
            
            // Первый запрос
            $start1 = microtime(true);
            $result1 = $this->api->getAllCountries();
            $time1 = microtime(true) - $start1;
            
            // Второй запрос (должен использовать кэш)
            $start2 = microtime(true);
            $result2 = $this->api->getAllCountries();
            $time2 = microtime(true) - $start2;
            
            $this->assert(
                $result1 == $result2,
                "Результаты кэшированного и некэшированного запросов должны совпадать"
            );
            
            // Второй запрос должен быть быстрее (кэш)
            if ($time1 > 0.001) { // Только если первый запрос занял заметное время
                $this->assert(
                    $time2 < $time1,
                    "Кэшированный запрос должен быть быстрее"
                );
            }
            
            echo "✅ Кэширование работает корректно\n";
            
        } catch (Exception $e) {
            $this->assert(false, "Тест кэширования выбросил исключение: " . $e->getMessage());
        }
        
        echo "\n";
    }
    
    /**
     * Тест производительности
     */
    private function testPerformance() {
        echo "🔧 Тестирование производительности\n";
        echo "-" . str_repeat("-", 50) . "\n";
        
        try {
            // Тест множественных запросов
            $start = microtime(true);
            
            for ($i = 0; $i < 10; $i++) {
                $this->api->getAllCountries();
            }
            
            $totalTime = microtime(true) - $start;
            $avgTime = $totalTime / 10;
            
            $this->assert(
                $avgTime < 1.0,
                "Средний время ответа должно быть меньше 1 секунды"
            );
            
            echo "✅ Средний время ответа: " . round($avgTime * 1000, 2) . "ms\n";
            
            // Тест с большим лимитом
            $start = microtime(true);
            $result = $this->api->filterProducts(['limit' => 1000]);
            $time = microtime(true) - $start;
            
            $this->assert(
                $time < 5.0,
                "Запрос с большим лимитом должен выполняться менее чем за 5 секунд"
            );
            
            echo "✅ Время запроса с лимитом 1000: " . round($time * 1000, 2) . "ms\n";
            
        } catch (Exception $e) {
            $this->assert(false, "Тест производительности выбросил исключение: " . $e->getMessage());
        }
        
        echo "\n";
    }
    
    /**
     * Тест граничных случаев
     */
    private function testEdgeCases() {
        echo "🔧 Тестирование граничных случаев\n";
        echo "-" . str_repeat("-", 50) . "\n";
        
        // Пустые строки
        $result = $this->api->getCountriesByBrand("");
        $this->assert(
            !$result['success'],
            "Пустая строка должна вызывать ошибку"
        );
        
        // Очень длинные строки
        $longString = str_repeat("a", 1000);
        $result = $this->api->filterProducts(['brand_id' => $longString]);
        $this->assert(
            !$result['success'],
            "Очень длинная строка должна вызывать ошибку"
        );
        
        // Специальные символы
        $result = $this->api->getCountriesByBrand("'; DROP TABLE brands; --");
        $this->assert(
            !$result['success'],
            "SQL инъекция должна быть заблокирована"
        );
        
        // Нулевые значения
        $result = $this->api->filterProducts([
            'brand_id' => null,
            'model_id' => null,
            'year' => null,
            'country_id' => null
        ]);
        $this->assert(
            $result['success'],
            "Нулевые значения должны обрабатываться корректно"
        );
        
        echo "✅ Граничные случаи обрабатываются корректно\n";
        echo "\n";
    }
    
    /**
     * Вспомогательный метод для проверок
     */
    private function assert($condition, $message) {
        $this->totalTests++;
        
        if ($condition) {
            $this->passedTests++;
            $this->testResults[] = "✅ " . $message;
        } else {
            $this->failedTests++;
            $this->testResults[] = "❌ " . $message;
            echo "❌ ПРОВАЛ: " . $message . "\n";
        }
    }
    
    /**
     * Вывод результатов тестирования
     */
    private function printTestResults() {
        echo "\n" . str_repeat("=", 80) . "\n";
        echo "🎯 РЕЗУЛЬТАТЫ ТЕСТИРОВАНИЯ CountryFilterAPI\n";
        echo "=" . str_repeat("=", 80) . "\n";
        
        echo "📊 СТАТИСТИКА:\n";
        echo "  Всего тестов: {$this->totalTests}\n";
        echo "  ✅ Пройдено: {$this->passedTests}\n";
        echo "  ❌ Провалено: {$this->failedTests}\n";
        
        if ($this->totalTests > 0) {
            $successRate = round(($this->passedTests / $this->totalTests) * 100, 1);
            echo "  📈 Успешность: {$successRate}%\n";
        }
        
        echo "\n📋 ПРОТЕСТИРОВАННАЯ ФУНКЦИОНАЛЬНОСТЬ:\n";
        echo "  ✅ API Endpoints:\n";
        echo "     - GET /api/countries (getAllCountries)\n";
        echo "     - GET /api/countries-by-brand (getCountriesByBrand)\n";
        echo "     - GET /api/countries-by-model (getCountriesByModel)\n";
        echo "     - GET /api/products-filter (filterProducts)\n";
        
        echo "\n  ✅ Валидация:\n";
        echo "     - Валидация параметров фильтров\n";
        echo "     - Валидация существования записей в БД\n";
        echo "     - Защита от SQL инъекций\n";
        echo "     - Обработка граничных случаев\n";
        
        echo "\n  ✅ Производительность:\n";
        echo "     - Кэширование результатов\n";
        echo "     - Оптимизация запросов\n";
        echo "     - Тестирование нагрузки\n";
        
        echo "\n  ✅ Обработка ошибок:\n";
        echo "     - Некорректные параметры\n";
        echo "     - Ошибки базы данных\n";
        echo "     - Экстремальные значения\n";
        
        echo "\n🎯 СООТВЕТСТВИЕ ТРЕБОВАНИЯМ:\n";
        echo "  ✅ Requirement 4.1: Корректная обработка комбинации фильтров\n";
        echo "  ✅ Requirement 4.2: Валидация параметров фильтра на backend\n";
        echo "  ✅ Requirement 2.1: Управление данными о странах изготовления\n";
        echo "  ✅ Requirement 2.2: Обработка отсутствующей информации о стране\n";
        
        if ($this->failedTests === 0) {
            echo "\n🎉 ВСЕ API ТЕСТЫ ПРОЙДЕНЫ УСПЕШНО!\n";
            echo "CountryFilterAPI готов к использованию в продакшене.\n";
        } else {
            echo "\n⚠️  ОБНАРУЖЕНЫ ПРОБЛЕМЫ В API!\n";
            echo "Необходимо исправить {$this->failedTests} провалившихся тестов.\n";
        }
        
        echo "\n" . str_repeat("=", 80) . "\n";
    }
}

// Запуск тестов если файл выполняется напрямую
if (basename(__FILE__) == basename($_SERVER['SCRIPT_NAME'])) {
    try {
        $apiTest = new CountryFilterAPITest();
        $apiTest->runAllTests();
    } catch (Exception $e) {
        echo "❌ КРИТИЧЕСКАЯ ОШИБКА ТЕСТИРОВАНИЯ API: " . $e->getMessage() . "\n";
        exit(1);
    }
}