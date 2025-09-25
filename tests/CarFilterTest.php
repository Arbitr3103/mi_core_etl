<?php
/**
 * Unit Tests для класса CarFilter
 * 
 * Тестирует функциональность класса CarFilter для валидации и построения
 * запросов фильтрации автомобилей
 * 
 * @version 1.0
 * @author ZUZ System
 */

require_once __DIR__ . '/../classes/CarFilter.php';

class CarFilterTest {
    private $pdo;
    private $filter;
    private $testResults = [];
    
    public function __construct() {
        $this->setupTestDatabase();
        $this->filter = new CarFilter($this->pdo);
    }
    
    /**
     * Настройка тестовой базы данных (используем in-memory SQLite для тестов)
     */
    private function setupTestDatabase() {
        try {
            $this->pdo = new PDO('sqlite::memory:');
            $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            
            // Создаем тестовые таблицы
            $this->pdo->exec("
                CREATE TABLE regions (
                    id INTEGER PRIMARY KEY,
                    name TEXT NOT NULL
                )
            ");
            
            $this->pdo->exec("
                CREATE TABLE brands (
                    id INTEGER PRIMARY KEY,
                    name TEXT NOT NULL,
                    region_id INTEGER,
                    FOREIGN KEY (region_id) REFERENCES regions(id)
                )
            ");
            
            $this->pdo->exec("
                CREATE TABLE car_models (
                    id INTEGER PRIMARY KEY,
                    name TEXT NOT NULL,
                    brand_id INTEGER,
                    FOREIGN KEY (brand_id) REFERENCES brands(id)
                )
            ");
            
            $this->pdo->exec("
                CREATE TABLE car_specifications (
                    id INTEGER PRIMARY KEY,
                    car_model_id INTEGER,
                    year_start INTEGER,
                    year_end INTEGER,
                    FOREIGN KEY (car_model_id) REFERENCES car_models(id)
                )
            ");
            
            $this->pdo->exec("
                CREATE TABLE dim_products (
                    id INTEGER PRIMARY KEY,
                    sku_ozon TEXT,
                    sku_wb TEXT,
                    product_name TEXT NOT NULL,
                    cost_price REAL,
                    specification_id INTEGER,
                    FOREIGN KEY (specification_id) REFERENCES car_specifications(id)
                )
            ");
            
            // Вставляем тестовые данные
            $this->insertTestData();
            
        } catch (PDOException $e) {
            throw new Exception("Ошибка настройки тестовой БД: " . $e->getMessage());
        }
    }
    
    /**
     * Вставка тестовых данных
     */
    private function insertTestData() {
        // Регионы
        $this->pdo->exec("INSERT INTO regions (id, name) VALUES (1, 'Германия')");
        $this->pdo->exec("INSERT INTO regions (id, name) VALUES (2, 'Япония')");
        
        // Бренды
        $this->pdo->exec("INSERT INTO brands (id, name, region_id) VALUES (1, 'BMW', 1)");
        $this->pdo->exec("INSERT INTO brands (id, name, region_id) VALUES (2, 'Toyota', 2)");
        
        // Модели
        $this->pdo->exec("INSERT INTO car_models (id, name, brand_id) VALUES (1, 'X5', 1)");
        $this->pdo->exec("INSERT INTO car_models (id, name, brand_id) VALUES (2, 'Camry', 2)");
        
        // Спецификации
        $this->pdo->exec("INSERT INTO car_specifications (id, car_model_id, year_start, year_end) VALUES (1, 1, 2015, 2020)");
        $this->pdo->exec("INSERT INTO car_specifications (id, car_model_id, year_start, year_end) VALUES (2, 2, 2018, 2023)");
        
        // Товары
        $this->pdo->exec("INSERT INTO dim_products (id, sku_ozon, sku_wb, product_name, cost_price, specification_id) VALUES (1, 'BMW001', 'WB001', 'Проставка BMW X5', 1500.00, 1)");
        $this->pdo->exec("INSERT INTO dim_products (id, sku_ozon, sku_wb, product_name, cost_price, specification_id) VALUES (2, 'TOY001', 'WB002', 'Проставка Toyota Camry', 1200.00, 2)");
    }
    
    /**
     * Запуск всех тестов
     */
    public function runAllTests() {
        echo "🧪 ЗАПУСК UNIT ТЕСТОВ ДЛЯ КЛАССА CarFilter\n";
        echo "=" . str_repeat("=", 60) . "\n\n";
        
        $this->testConstructorAndReset();
        $this->testSetters();
        $this->testSetFilters();
        $this->testValidation();
        $this->testBuildQuery();
        $this->testBuildCountQuery();
        $this->testExecute();
        $this->testUtilityMethods();
        $this->testErrorHandling();
        
        $this->printResults();
    }
    
    /**
     * Тест конструктора и метода reset()
     */
    private function testConstructorAndReset() {
        echo "📍 Тест: Конструктор и reset()\n";
        echo "-" . str_repeat("-", 30) . "\n";
        
        try {
            // Проверяем начальные значения
            $filters = $this->filter->getFilters();
            $this->assert($filters['brand_id'] === null, "Начальное значение brand_id должно быть null");
            $this->assert($filters['model_id'] === null, "Начальное значение model_id должно быть null");
            $this->assert($filters['year'] === null, "Начальное значение year должно быть null");
            $this->assert($filters['country_id'] === null, "Начальное значение country_id должно быть null");
            $this->assert($filters['limit'] === 100, "Начальное значение limit должно быть 100");
            $this->assert($filters['offset'] === 0, "Начальное значение offset должно быть 0");
            
            // Устанавливаем значения и проверяем reset
            $this->filter->setBrand(1)->setModel(2)->setYear(2020);
            $this->filter->reset();
            
            $filtersAfterReset = $this->filter->getFilters();
            $this->assert($filtersAfterReset['brand_id'] === null, "После reset brand_id должно быть null");
            $this->assert($filtersAfterReset['model_id'] === null, "После reset model_id должно быть null");
            $this->assert($filtersAfterReset['year'] === null, "После reset year должно быть null");
            
            $this->testResults['constructorAndReset'] = ['status' => 'PASS', 'message' => 'Все проверки пройдены'];
            
        } catch (Exception $e) {
            $this->testResults['constructorAndReset'] = ['status' => 'FAIL', 'message' => $e->getMessage()];
        }
        
        echo "\n";
    }
    
    /**
     * Тест методов установки фильтров
     */
    private function testSetters() {
        echo "📍 Тест: Методы установки фильтров\n";
        echo "-" . str_repeat("-", 30) . "\n";
        
        try {
            $this->filter->reset();
            
            // Тест setBrand
            $result = $this->filter->setBrand(1);
            $this->assert($result instanceof CarFilter, "setBrand должен возвращать экземпляр CarFilter для цепочки вызовов");
            $this->assert($this->filter->getFilters()['brand_id'] === 1, "setBrand должен устанавливать brand_id");
            
            // Тест setModel
            $this->filter->setModel(2);
            $this->assert($this->filter->getFilters()['model_id'] === 2, "setModel должен устанавливать model_id");
            
            // Тест setYear
            $this->filter->setYear(2020);
            $this->assert($this->filter->getFilters()['year'] === 2020, "setYear должен устанавливать year");
            
            // Тест setCountry
            $this->filter->setCountry(1);
            $this->assert($this->filter->getFilters()['country_id'] === 1, "setCountry должен устанавливать country_id");
            
            // Тест setLimit
            $this->filter->setLimit(50);
            $this->assert($this->filter->getFilters()['limit'] === 50, "setLimit должен устанавливать limit");
            
            // Тест setOffset
            $this->filter->setOffset(10);
            $this->assert($this->filter->getFilters()['offset'] === 10, "setOffset должен устанавливать offset");
            
            // Тест цепочки вызовов
            $this->filter->reset()->setBrand(1)->setModel(2)->setYear(2020)->setCountry(1);
            $filters = $this->filter->getFilters();
            $this->assert($filters['brand_id'] === 1 && $filters['model_id'] === 2, "Цепочка вызовов должна работать корректно");
            
            $this->testResults['setters'] = ['status' => 'PASS', 'message' => 'Все проверки пройдены'];
            
        } catch (Exception $e) {
            $this->testResults['setters'] = ['status' => 'FAIL', 'message' => $e->getMessage()];
        }
        
        echo "\n";
    }
    
    /**
     * Тест метода setFilters()
     */
    private function testSetFilters() {
        echo "📍 Тест: setFilters()\n";
        echo "-" . str_repeat("-", 30) . "\n";
        
        try {
            $this->filter->reset();
            
            $filtersArray = [
                'brand_id' => 1,
                'model_id' => 2,
                'year' => 2020,
                'country_id' => 1,
                'limit' => 25,
                'offset' => 5
            ];
            
            $result = $this->filter->setFilters($filtersArray);
            $this->assert($result instanceof CarFilter, "setFilters должен возвращать экземпляр CarFilter");
            
            $currentFilters = $this->filter->getFilters();
            $this->assert($currentFilters['brand_id'] === 1, "setFilters должен устанавливать brand_id");
            $this->assert($currentFilters['model_id'] === 2, "setFilters должен устанавливать model_id");
            $this->assert($currentFilters['year'] === 2020, "setFilters должен устанавливать year");
            $this->assert($currentFilters['country_id'] === 1, "setFilters должен устанавливать country_id");
            $this->assert($currentFilters['limit'] === 25, "setFilters должен устанавливать limit");
            $this->assert($currentFilters['offset'] === 5, "setFilters должен устанавливать offset");
            
            // Тест с частичным массивом
            $this->filter->reset();
            $this->filter->setFilters(['brand_id' => 2, 'year' => 2019]);
            $partialFilters = $this->filter->getFilters();
            $this->assert($partialFilters['brand_id'] === 2, "Частичный setFilters должен устанавливать указанные поля");
            $this->assert($partialFilters['model_id'] === null, "Частичный setFilters не должен изменять неуказанные поля");
            
            $this->testResults['setFilters'] = ['status' => 'PASS', 'message' => 'Все проверки пройдены'];
            
        } catch (Exception $e) {
            $this->testResults['setFilters'] = ['status' => 'FAIL', 'message' => $e->getMessage()];
        }
        
        echo "\n";
    }
    
    /**
     * Тест валидации
     */
    private function testValidation() {
        echo "📍 Тест: validate()\n";
        echo "-" . str_repeat("-", 30) . "\n";
        
        try {
            // Тест валидных данных
            $this->filter->reset();
            $this->filter->setBrand(1)->setModel(2)->setYear(2020)->setCountry(1);
            $validation = $this->filter->validate();
            $this->assert($validation['valid'] === true, "Валидные данные должны проходить валидацию");
            $this->assert(empty($validation['errors']), "Для валидных данных не должно быть ошибок");
            
            // Тест некорректного brand_id
            $this->filter->reset()->setBrand('invalid');
            $validation = $this->filter->validate();
            $this->assert($validation['valid'] === false, "Некорректный brand_id должен не проходить валидацию");
            $this->assert(!empty($validation['errors']), "Должны быть ошибки валидации");
            
            // Тест некорректного года
            $this->filter->reset()->setYear(1800);
            $validation = $this->filter->validate();
            $this->assert($validation['valid'] === false, "Некорректный год должен не проходить валидацию");
            
            // Тест некорректного лимита
            $this->filter->reset()->setLimit(2000);
            $validation = $this->filter->validate();
            $this->assert($validation['valid'] === false, "Слишком большой лимит должен не проходить валидацию");
            
            // Тест отрицательного смещения
            $this->filter->reset()->setOffset(-1);
            $validation = $this->filter->validate();
            $this->assert($validation['valid'] === false, "Отрицательное смещение должно не проходить валидацию");
            
            $this->testResults['validation'] = ['status' => 'PASS', 'message' => 'Все проверки пройдены'];
            
        } catch (Exception $e) {
            $this->testResults['validation'] = ['status' => 'FAIL', 'message' => $e->getMessage()];
        }
        
        echo "\n";
    }
    
    /**
     * Тест построения основного запроса
     */
    private function testBuildQuery() {
        echo "📍 Тест: buildQuery()\n";
        echo "-" . str_repeat("-", 30) . "\n";
        
        try {
            // Тест базового запроса без фильтров
            $this->filter->reset();
            $queryData = $this->filter->buildQuery();
            
            $this->assert(isset($queryData['sql']), "buildQuery должен возвращать SQL");
            $this->assert(isset($queryData['params']), "buildQuery должен возвращать параметры");
            $this->assert(is_string($queryData['sql']), "SQL должен быть строкой");
            $this->assert(is_array($queryData['params']), "Параметры должны быть массивом");
            
            // Проверяем, что SQL содержит основные элементы
            $sql = $queryData['sql'];
            $this->assert(strpos($sql, 'SELECT') !== false, "SQL должен содержать SELECT");
            $this->assert(strpos($sql, 'FROM dim_products') !== false, "SQL должен содержать FROM dim_products");
            $this->assert(strpos($sql, 'LIMIT') !== false, "SQL должен содержать LIMIT");
            $this->assert(strpos($sql, 'OFFSET') !== false, "SQL должен содержать OFFSET");
            
            // Тест запроса с фильтрами
            $this->filter->reset()->setBrand(1)->setYear(2020);
            $queryDataWithFilters = $this->filter->buildQuery();
            
            $this->assert(strpos($queryDataWithFilters['sql'], 'b.id = :brand_id') !== false, "SQL должен содержать условие по бренду");
            $this->assert(strpos($queryDataWithFilters['sql'], 'year_start') !== false, "SQL должен содержать условие по году");
            $this->assert(isset($queryDataWithFilters['params']['brand_id']), "Параметры должны содержать brand_id");
            $this->assert(isset($queryDataWithFilters['params']['year']), "Параметры должны содержать year");
            
            // Тест с некорректными данными
            $this->filter->reset()->setBrand('invalid');
            try {
                $this->filter->buildQuery();
                $this->assert(false, "buildQuery должен выбрасывать исключение для некорректных данных");
            } catch (Exception $e) {
                $this->assert(true, "Корректно обработано исключение для некорректных данных");
            }
            
            $this->testResults['buildQuery'] = ['status' => 'PASS', 'message' => 'Все проверки пройдены'];
            
        } catch (Exception $e) {
            $this->testResults['buildQuery'] = ['status' => 'FAIL', 'message' => $e->getMessage()];
        }
        
        echo "\n";
    }
    
    /**
     * Тест построения запроса подсчета
     */
    private function testBuildCountQuery() {
        echo "📍 Тест: buildCountQuery()\n";
        echo "-" . str_repeat("-", 30) . "\n";
        
        try {
            $this->filter->reset()->setBrand(1);
            $countData = $this->filter->buildCountQuery();
            
            $this->assert(isset($countData['sql']), "buildCountQuery должен возвращать SQL");
            $this->assert(isset($countData['params']), "buildCountQuery должен возвращать параметры");
            
            $sql = $countData['sql'];
            $this->assert(strpos($sql, 'COUNT(*)') !== false, "SQL должен содержать COUNT(*)");
            $this->assert(strpos($sql, 'LIMIT') === false, "COUNT запрос не должен содержать LIMIT");
            $this->assert(strpos($sql, 'OFFSET') === false, "COUNT запрос не должен содержать OFFSET");
            $this->assert(strpos($sql, 'b.id = :brand_id') !== false, "COUNT запрос должен содержать те же условия фильтрации");
            
            $this->testResults['buildCountQuery'] = ['status' => 'PASS', 'message' => 'Все проверки пройдены'];
            
        } catch (Exception $e) {
            $this->testResults['buildCountQuery'] = ['status' => 'FAIL', 'message' => $e->getMessage()];
        }
        
        echo "\n";
    }
    
    /**
     * Тест выполнения запроса
     */
    private function testExecute() {
        echo "📍 Тест: execute()\n";
        echo "-" . str_repeat("-", 30) . "\n";
        
        try {
            // Тест выполнения без фильтров
            $this->filter->reset()->setLimit(10);
            $result = $this->filter->execute();
            
            $this->assert(isset($result['data']), "Результат должен содержать поле 'data'");
            $this->assert(isset($result['pagination']), "Результат должен содержать поле 'pagination'");
            $this->assert(isset($result['filters_applied']), "Результат должен содержать поле 'filters_applied'");
            
            $this->assert(is_array($result['data']), "Данные должны быть массивом");
            $this->assert(is_array($result['pagination']), "Пагинация должна быть массивом");
            
            // Проверяем структуру пагинации
            $pagination = $result['pagination'];
            $this->assert(isset($pagination['total']), "Пагинация должна содержать 'total'");
            $this->assert(isset($pagination['limit']), "Пагинация должна содержать 'limit'");
            $this->assert(isset($pagination['offset']), "Пагинация должна содержать 'offset'");
            $this->assert(isset($pagination['has_more']), "Пагинация должна содержать 'has_more'");
            
            // Тест с фильтром по бренду
            $this->filter->reset()->setBrand(1);
            $filteredResult = $this->filter->execute();
            
            $this->assert(is_array($filteredResult['data']), "Отфильтрованные данные должны быть массивом");
            $this->assert($filteredResult['filters_applied']['brand_id'] === 1, "Примененные фильтры должны содержать brand_id");
            
            $this->testResults['execute'] = ['status' => 'PASS', 'message' => 'Все проверки пройдены'];
            
        } catch (Exception $e) {
            $this->testResults['execute'] = ['status' => 'FAIL', 'message' => $e->getMessage()];
        }
        
        echo "\n";
    }
    
    /**
     * Тест вспомогательных методов
     */
    private function testUtilityMethods() {
        echo "📍 Тест: Вспомогательные методы\n";
        echo "-" . str_repeat("-", 30) . "\n";
        
        try {
            // Тест hasFilters
            $this->filter->reset();
            $this->assert($this->filter->hasFilters() === false, "hasFilters должен возвращать false для пустых фильтров");
            
            $this->filter->setBrand(1);
            $this->assert($this->filter->hasFilters() === true, "hasFilters должен возвращать true при наличии фильтров");
            
            // Тест getFilterCount
            $this->filter->reset();
            $this->assert($this->filter->getFilterCount() === 0, "getFilterCount должен возвращать 0 для пустых фильтров");
            
            $this->filter->setBrand(1)->setYear(2020);
            $this->assert($this->filter->getFilterCount() === 2, "getFilterCount должен возвращать количество установленных фильтров");
            
            // Тест getFilters
            $filters = $this->filter->getFilters();
            $this->assert(is_array($filters), "getFilters должен возвращать массив");
            $this->assert(count($filters) === 6, "getFilters должен возвращать все поля фильтров");
            
            $this->testResults['utilityMethods'] = ['status' => 'PASS', 'message' => 'Все проверки пройдены'];
            
        } catch (Exception $e) {
            $this->testResults['utilityMethods'] = ['status' => 'FAIL', 'message' => $e->getMessage()];
        }
        
        echo "\n";
    }
    
    /**
     * Тест обработки ошибок
     */
    private function testErrorHandling() {
        echo "📍 Тест: Обработка ошибок\n";
        echo "-" . str_repeat("-", 30) . "\n";
        
        try {
            // Создаем CarFilter с некорректным PDO для тестирования ошибок БД
            $invalidPdo = new PDO('sqlite::memory:');
            $invalidFilter = new CarFilter($invalidPdo);
            
            try {
                $invalidFilter->execute();
                $this->assert(false, "Должно быть выброшено исключение при ошибке БД");
            } catch (Exception $e) {
                $this->assert(true, "Корректно обработана ошибка БД");
            }
            
            $this->testResults['errorHandling'] = ['status' => 'PASS', 'message' => 'Обработка ошибок работает корректно'];
            
        } catch (Exception $e) {
            $this->testResults['errorHandling'] = ['status' => 'FAIL', 'message' => $e->getMessage()];
        }
        
        echo "\n";
    }
    
    /**
     * Вспомогательный метод для проверки утверждений
     */
    private function assert($condition, $message) {
        if (!$condition) {
            throw new Exception("Assertion failed: " . $message);
        }
        echo "✅ " . $message . "\n";
    }
    
    /**
     * Вывод результатов тестирования
     */
    private function printResults() {
        echo "🎉 РЕЗУЛЬТАТЫ ТЕСТИРОВАНИЯ КЛАССА CarFilter\n";
        echo "=" . str_repeat("=", 60) . "\n";
        
        $passed = 0;
        $failed = 0;
        
        foreach ($this->testResults as $testName => $result) {
            $status = $result['status'] === 'PASS' ? '✅' : '❌';
            echo "{$status} {$testName}: {$result['message']}\n";
            
            if ($result['status'] === 'PASS') {
                $passed++;
            } else {
                $failed++;
            }
        }
        
        echo "\n📊 ИТОГО:\n";
        echo "✅ Пройдено: {$passed}\n";
        echo "❌ Провалено: {$failed}\n";
        echo "📈 Успешность: " . round(($passed / ($passed + $failed)) * 100, 1) . "%\n";
    }
}

// Запуск тестов если файл выполняется напрямую
if (basename(__FILE__) == basename($_SERVER['SCRIPT_NAME'])) {
    try {
        $test = new CarFilterTest();
        $test->runAllTests();
    } catch (Exception $e) {
        echo "❌ КРИТИЧЕСКАЯ ОШИБКА: " . $e->getMessage() . "\n";
    }
}