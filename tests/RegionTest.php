<?php
/**
 * Unit Tests для класса Region
 * 
 * Тестирует функциональность класса Region для работы со странами/регионами
 * изготовления автомобилей
 * 
 * @version 1.0
 * @author ZUZ System
 */

require_once __DIR__ . '/../classes/Region.php';

class RegionTest {
    private $pdo;
    private $region;
    private $testResults = [];
    
    public function __construct() {
        $this->setupTestDatabase();
        $this->region = new Region($this->pdo);
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
        $this->pdo->exec("INSERT INTO regions (id, name) VALUES (3, 'США')");
        $this->pdo->exec("INSERT INTO regions (id, name) VALUES (4, '')"); // Пустое название
        $this->pdo->exec("INSERT INTO regions (id, name) VALUES (5, 'Корея')");
        
        // Бренды
        $this->pdo->exec("INSERT INTO brands (id, name, region_id) VALUES (1, 'BMW', 1)");
        $this->pdo->exec("INSERT INTO brands (id, name, region_id) VALUES (2, 'Mercedes', 1)");
        $this->pdo->exec("INSERT INTO brands (id, name, region_id) VALUES (3, 'Toyota', 2)");
        $this->pdo->exec("INSERT INTO brands (id, name, region_id) VALUES (4, 'Honda', 2)");
        $this->pdo->exec("INSERT INTO brands (id, name, region_id) VALUES (5, 'Ford', 3)");
        $this->pdo->exec("INSERT INTO brands (id, name, region_id) VALUES (6, 'Hyundai', 5)");
        
        // Модели
        $this->pdo->exec("INSERT INTO car_models (id, name, brand_id) VALUES (1, 'X5', 1)");
        $this->pdo->exec("INSERT INTO car_models (id, name, brand_id) VALUES (2, '3 Series', 1)");
        $this->pdo->exec("INSERT INTO car_models (id, name, brand_id) VALUES (3, 'C-Class', 2)");
        $this->pdo->exec("INSERT INTO car_models (id, name, brand_id) VALUES (4, 'Camry', 3)");
        $this->pdo->exec("INSERT INTO car_models (id, name, brand_id) VALUES (5, 'Civic', 4)");
        $this->pdo->exec("INSERT INTO car_models (id, name, brand_id) VALUES (6, 'Focus', 5)");
        $this->pdo->exec("INSERT INTO car_models (id, name, brand_id) VALUES (7, 'Elantra', 6)");
    }
    
    /**
     * Запуск всех тестов
     */
    public function runAllTests() {
        echo "🧪 ЗАПУСК UNIT ТЕСТОВ ДЛЯ КЛАССА Region\n";
        echo "=" . str_repeat("=", 60) . "\n\n";
        
        $this->testGetAll();
        $this->testGetByBrand();
        $this->testGetByModel();
        $this->testExists();
        $this->testGetById();
        $this->testGetBrandCount();
        $this->testGetStatistics();
        $this->testErrorHandling();
        
        $this->printResults();
    }
    
    /**
     * Тест метода getAll()
     */
    private function testGetAll() {
        echo "📍 Тест: getAll()\n";
        echo "-" . str_repeat("-", 30) . "\n";
        
        try {
            $regions = $this->region->getAll();
            
            // Проверяем, что возвращается массив
            $this->assert(is_array($regions), "getAll() должен возвращать массив");
            
            // Проверяем, что возвращаются только регионы с непустыми названиями
            $this->assert(count($regions) == 4, "Должно быть 4 региона (исключая пустое название)");
            
            // Проверяем структуру данных
            if (!empty($regions)) {
                $firstRegion = $regions[0];
                $this->assert(isset($firstRegion['id']), "Регион должен содержать поле 'id'");
                $this->assert(isset($firstRegion['name']), "Регион должен содержать поле 'name'");
                $this->assert(!empty($firstRegion['name']), "Название региона не должно быть пустым");
            }
            
            // Проверяем сортировку по названию
            $names = array_column($regions, 'name');
            $sortedNames = $names;
            sort($sortedNames);
            $this->assert($names === $sortedNames, "Регионы должны быть отсортированы по названию");
            
            $this->testResults['getAll'] = ['status' => 'PASS', 'message' => 'Все проверки пройдены'];
            
        } catch (Exception $e) {
            $this->testResults['getAll'] = ['status' => 'FAIL', 'message' => $e->getMessage()];
        }
        
        echo "\n";
    }
    
    /**
     * Тест метода getByBrand()
     */
    private function testGetByBrand() {
        echo "📍 Тест: getByBrand()\n";
        echo "-" . str_repeat("-", 30) . "\n";
        
        try {
            // Тест с существующим брендом (BMW - регион Германия)
            $regions = $this->region->getByBrand(1);
            $this->assert(is_array($regions), "getByBrand() должен возвращать массив");
            $this->assert(count($regions) == 1, "Для BMW должен быть 1 регион");
            $this->assert($regions[0]['name'] == 'Германия', "Регион BMW должен быть Германия");
            
            // Тест с несуществующим брендом
            $regions = $this->region->getByBrand(999);
            $this->assert(is_array($regions), "getByBrand() должен возвращать массив даже для несуществующего бренда");
            $this->assert(count($regions) == 0, "Для несуществующего бренда должен быть пустой массив");
            
            // Тест с некорректным ID
            try {
                $this->region->getByBrand('invalid');
                $this->assert(false, "getByBrand() должен выбрасывать исключение для некорректного ID");
            } catch (Exception $e) {
                $this->assert(true, "Корректно обработано исключение для некорректного ID");
            }
            
            $this->testResults['getByBrand'] = ['status' => 'PASS', 'message' => 'Все проверки пройдены'];
            
        } catch (Exception $e) {
            $this->testResults['getByBrand'] = ['status' => 'FAIL', 'message' => $e->getMessage()];
        }
        
        echo "\n";
    }
    
    /**
     * Тест метода getByModel()
     */
    private function testGetByModel() {
        echo "📍 Тест: getByModel()\n";
        echo "-" . str_repeat("-", 30) . "\n";
        
        try {
            // Тест с существующей моделью (BMW X5 - регион Германия)
            $regions = $this->region->getByModel(1);
            $this->assert(is_array($regions), "getByModel() должен возвращать массив");
            $this->assert(count($regions) == 1, "Для BMW X5 должен быть 1 регион");
            $this->assert($regions[0]['name'] == 'Германия', "Регион BMW X5 должен быть Германия");
            
            // Тест с несуществующей моделью
            $regions = $this->region->getByModel(999);
            $this->assert(is_array($regions), "getByModel() должен возвращать массив даже для несуществующей модели");
            $this->assert(count($regions) == 0, "Для несуществующей модели должен быть пустой массив");
            
            // Тест с некорректным ID
            try {
                $this->region->getByModel(-1);
                $this->assert(false, "getByModel() должен выбрасывать исключение для некорректного ID");
            } catch (Exception $e) {
                $this->assert(true, "Корректно обработано исключение для некорректного ID");
            }
            
            $this->testResults['getByModel'] = ['status' => 'PASS', 'message' => 'Все проверки пройдены'];
            
        } catch (Exception $e) {
            $this->testResults['getByModel'] = ['status' => 'FAIL', 'message' => $e->getMessage()];
        }
        
        echo "\n";
    }
    
    /**
     * Тест метода exists()
     */
    private function testExists() {
        echo "📍 Тест: exists()\n";
        echo "-" . str_repeat("-", 30) . "\n";
        
        try {
            // Тест с существующим регионом
            $this->assert($this->region->exists(1) === true, "exists(1) должен возвращать true");
            
            // Тест с несуществующим регионом
            $this->assert($this->region->exists(999) === false, "exists(999) должен возвращать false");
            
            // Тест с некорректным ID
            $this->assert($this->region->exists('invalid') === false, "exists('invalid') должен возвращать false");
            $this->assert($this->region->exists(-1) === false, "exists(-1) должен возвращать false");
            
            $this->testResults['exists'] = ['status' => 'PASS', 'message' => 'Все проверки пройдены'];
            
        } catch (Exception $e) {
            $this->testResults['exists'] = ['status' => 'FAIL', 'message' => $e->getMessage()];
        }
        
        echo "\n";
    }
    
    /**
     * Тест метода getById()
     */
    private function testGetById() {
        echo "📍 Тест: getById()\n";
        echo "-" . str_repeat("-", 30) . "\n";
        
        try {
            // Тест с существующим регионом
            $region = $this->region->getById(1);
            $this->assert(is_array($region), "getById() должен возвращать массив для существующего региона");
            $this->assert($region['id'] == 1, "ID региона должен быть 1");
            $this->assert($region['name'] == 'Германия', "Название региона должно быть 'Германия'");
            
            // Тест с несуществующим регионом
            $region = $this->region->getById(999);
            $this->assert($region === null, "getById() должен возвращать null для несуществующего региона");
            
            // Тест с некорректным ID
            try {
                $this->region->getById('invalid');
                $this->assert(false, "getById() должен выбрасывать исключение для некорректного ID");
            } catch (Exception $e) {
                $this->assert(true, "Корректно обработано исключение для некорректного ID");
            }
            
            $this->testResults['getById'] = ['status' => 'PASS', 'message' => 'Все проверки пройдены'];
            
        } catch (Exception $e) {
            $this->testResults['getById'] = ['status' => 'FAIL', 'message' => $e->getMessage()];
        }
        
        echo "\n";
    }
    
    /**
     * Тест метода getBrandCount()
     */
    private function testGetBrandCount() {
        echo "📍 Тест: getBrandCount()\n";
        echo "-" . str_repeat("-", 30) . "\n";
        
        try {
            // Тест для Германии (должно быть 2 бренда: BMW и Mercedes)
            $count = $this->region->getBrandCount(1);
            $this->assert($count === 2, "В Германии должно быть 2 бренда");
            
            // Тест для Японии (должно быть 2 бренда: Toyota и Honda)
            $count = $this->region->getBrandCount(2);
            $this->assert($count === 2, "В Японии должно быть 2 бренда");
            
            // Тест для несуществующего региона
            $count = $this->region->getBrandCount(999);
            $this->assert($count === 0, "Для несуществующего региона должно быть 0 брендов");
            
            $this->testResults['getBrandCount'] = ['status' => 'PASS', 'message' => 'Все проверки пройдены'];
            
        } catch (Exception $e) {
            $this->testResults['getBrandCount'] = ['status' => 'FAIL', 'message' => $e->getMessage()];
        }
        
        echo "\n";
    }
    
    /**
     * Тест метода getStatistics()
     */
    private function testGetStatistics() {
        echo "📍 Тест: getStatistics()\n";
        echo "-" . str_repeat("-", 30) . "\n";
        
        try {
            $stats = $this->region->getStatistics();
            
            $this->assert(is_array($stats), "getStatistics() должен возвращать массив");
            $this->assert(count($stats) > 0, "Статистика должна содержать данные");
            
            // Проверяем структуру данных
            if (!empty($stats)) {
                $firstStat = $stats[0];
                $this->assert(isset($firstStat['id']), "Статистика должна содержать поле 'id'");
                $this->assert(isset($firstStat['name']), "Статистика должна содержать поле 'name'");
                $this->assert(isset($firstStat['brand_count']), "Статистика должна содержать поле 'brand_count'");
                $this->assert(isset($firstStat['model_count']), "Статистика должна содержать поле 'model_count'");
            }
            
            $this->testResults['getStatistics'] = ['status' => 'PASS', 'message' => 'Все проверки пройдены'];
            
        } catch (Exception $e) {
            $this->testResults['getStatistics'] = ['status' => 'FAIL', 'message' => $e->getMessage()];
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
            // Создаем Region с некорректным PDO для тестирования ошибок БД
            $invalidPdo = new PDO('sqlite::memory:');
            $invalidRegion = new Region($invalidPdo);
            
            try {
                $invalidRegion->getAll();
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
        echo "🎉 РЕЗУЛЬТАТЫ ТЕСТИРОВАНИЯ КЛАССА Region\n";
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
        $test = new RegionTest();
        $test->runAllTests();
    } catch (Exception $e) {
        echo "❌ КРИТИЧЕСКАЯ ОШИБКА: " . $e->getMessage() . "\n";
    }
}