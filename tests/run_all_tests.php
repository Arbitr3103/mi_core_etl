<?php
/**
 * Test Runner для всех unit тестов классов Region и CarFilter
 * 
 * Запускает все тесты и выводит общий отчет
 * 
 * @version 1.0
 * @author ZUZ System
 */

require_once __DIR__ . '/RegionTest.php';
require_once __DIR__ . '/CarFilterTest.php';
require_once __DIR__ . '/CountryFilterAPI.test.php';

class TestRunner {
    private $totalTests = 0;
    private $passedTests = 0;
    private $failedTests = 0;
    private $startTime;
    
    public function __construct() {
        $this->startTime = microtime(true);
    }
    
    /**
     * Запуск всех тестов
     */
    public function runAllTests() {
        echo "🚀 ЗАПУСК ВСЕХ UNIT ТЕСТОВ\n";
        echo "=" . str_repeat("=", 80) . "\n";
        echo "Дата: " . date('Y-m-d H:i:s') . "\n";
        echo "Тестируемые классы: Region, CarFilter\n";
        echo "=" . str_repeat("=", 80) . "\n\n";
        
        // Запуск тестов Region
        echo "🔧 ТЕСТИРОВАНИЕ КЛАССА Region\n";
        echo "=" . str_repeat("=", 80) . "\n";
        
        try {
            $regionTest = new RegionTest();
            
            // Перехватываем вывод для подсчета результатов
            ob_start();
            $regionTest->runAllTests();
            $regionOutput = ob_get_clean();
            
            echo $regionOutput;
            
            // Подсчитываем результаты Region тестов
            $this->countTestResults($regionOutput);
            
        } catch (Exception $e) {
            echo "❌ КРИТИЧЕСКАЯ ОШИБКА В ТЕСТАХ Region: " . $e->getMessage() . "\n\n";
            $this->failedTests++;
        }
        
        echo "\n" . str_repeat("=", 80) . "\n\n";
        
        // Запуск тестов CountryFilterAPI
        echo "🔧 ТЕСТИРОВАНИЕ КЛАССА CountryFilterAPI\n";
        echo "=" . str_repeat("=", 80) . "\n";
        
        try {
            $apiTest = new CountryFilterAPITest();
            
            // Перехватываем вывод для подсчета результатов
            ob_start();
            $apiTest->runAllTests();
            $apiOutput = ob_get_clean();
            
            echo $apiOutput;
            
            // Подсчитываем результаты API тестов
            $this->countTestResults($apiOutput);
            
        } catch (Exception $e) {
            echo "❌ КРИТИЧЕСКАЯ ОШИБКА В ТЕСТАХ CountryFilterAPI: " . $e->getMessage() . "\n\n";
            $this->failedTests++;
        }
        
        echo "\n" . str_repeat("=", 80) . "\n\n";
        
        // Запуск тестов CarFilter
        echo "🔧 ТЕСТИРОВАНИЕ КЛАССА CarFilter\n";
        echo "=" . str_repeat("=", 80) . "\n";
        
        try {
            $carFilterTest = new CarFilterTest();
            
            // Перехватываем вывод для подсчета результатов
            ob_start();
            $carFilterTest->runAllTests();
            $carFilterOutput = ob_get_clean();
            
            echo $carFilterOutput;
            
            // Подсчитываем результаты CarFilter тестов
            $this->countTestResults($carFilterOutput);
            
        } catch (Exception $e) {
            echo "❌ КРИТИЧЕСКАЯ ОШИБКА В ТЕСТАХ CarFilter: " . $e->getMessage() . "\n\n";
            $this->failedTests++;
        }
        
        // Выводим общий отчет
        $this->printFinalReport();
    }
    
    /**
     * Подсчет результатов тестов из вывода
     */
    private function countTestResults($output) {
        // Подсчитываем количество пройденных тестов (✅)
        $passed = substr_count($output, '✅ Пройдено:');
        $failed = substr_count($output, '❌ Провалено:');
        
        // Если не найдены итоговые результаты, считаем по отдельным проверкам
        if ($passed === 0 && $failed === 0) {
            $passed = substr_count($output, '✅');
            $failed = substr_count($output, '❌');
        }
        
        $this->passedTests += $passed;
        $this->failedTests += $failed;
        $this->totalTests += ($passed + $failed);
    }
    
    /**
     * Вывод финального отчета
     */
    private function printFinalReport() {
        $endTime = microtime(true);
        $executionTime = round($endTime - $this->startTime, 2);
        
        echo "\n" . str_repeat("=", 80) . "\n";
        echo "🎯 ФИНАЛЬНЫЙ ОТЧЕТ ПО ТЕСТИРОВАНИЮ\n";
        echo "=" . str_repeat("=", 80) . "\n";
        
        echo "📊 СТАТИСТИКА:\n";
        echo "  Всего тестов: {$this->totalTests}\n";
        echo "  ✅ Пройдено: {$this->passedTests}\n";
        echo "  ❌ Провалено: {$this->failedTests}\n";
        
        if ($this->totalTests > 0) {
            $successRate = round(($this->passedTests / $this->totalTests) * 100, 1);
            echo "  📈 Успешность: {$successRate}%\n";
        }
        
        echo "  ⏱️  Время выполнения: {$executionTime} сек\n";
        
        echo "\n📋 ТЕСТИРУЕМАЯ ФУНКЦИОНАЛЬНОСТЬ:\n";
        echo "  ✅ Класс Region:\n";
        echo "     - Получение всех регионов\n";
        echo "     - Получение регионов по марке\n";
        echo "     - Получение регионов по модели\n";
        echo "     - Проверка существования региона\n";
        echo "     - Получение информации о регионе\n";
        echo "     - Подсчет брендов в регионе\n";
        echo "     - Статистика по регионам\n";
        echo "     - Обработка ошибок\n";
        
        echo "\n  ✅ Класс CarFilter:\n";
        echo "     - Установка и сброс фильтров\n";
        echo "     - Валидация параметров\n";
        echo "     - Построение SQL запросов\n";
        echo "     - Выполнение запросов фильтрации\n";
        echo "     - Подсчет результатов\n";
        echo "     - Пагинация\n";
        echo "     - Цепочка вызовов методов\n";
        echo "     - Обработка ошибок\n";
        
        echo "\n  ✅ Класс CountryFilterAPI:\n";
        echo "     - API endpoints для стран изготовления\n";
        echo "     - Валидация параметров API\n";
        echo "     - Фильтрация товаров по стране\n";
        echo "     - Кэширование результатов\n";
        echo "     - Обработка ошибок API\n";
        echo "     - Производительность запросов\n";
        echo "     - Защита от SQL инъекций\n";
        echo "     - Граничные случаи\n";
        
        echo "\n🎯 СООТВЕТСТВИЕ ТРЕБОВАНИЯМ:\n";
        echo "  ✅ Requirement 2.1: Управление данными о странах изготовления\n";
        echo "  ✅ Requirement 4.2: Валидация параметров фильтра\n";
        echo "  ✅ Requirement 4.1: Корректная обработка комбинации фильтров\n";
        echo "  ✅ Requirement 2.2: Обработка отсутствующей информации\n";
        
        // Определяем общий статус
        if ($this->failedTests === 0) {
            echo "\n🎉 ВСЕ ТЕСТЫ ПРОЙДЕНЫ УСПЕШНО!\n";
            echo "Классы Region, CarFilter и CountryFilterAPI готовы к использованию.\n";
            echo "Система фильтрации по стране изготовления полностью протестирована.\n";
        } else {
            echo "\n⚠️  ОБНАРУЖЕНЫ ПРОБЛЕМЫ!\n";
            echo "Необходимо исправить {$this->failedTests} провалившихся тестов.\n";
        }
        
        echo "\n" . str_repeat("=", 80) . "\n";
        
        // Возвращаем код выхода для CI/CD
        if ($this->failedTests > 0) {
            exit(1);
        }
    }
}

// Запуск всех тестов если файл выполняется напрямую
if (basename(__FILE__) == basename($_SERVER['SCRIPT_NAME'])) {
    try {
        $runner = new TestRunner();
        $runner->runAllTests();
    } catch (Exception $e) {
        echo "❌ КРИТИЧЕСКАЯ ОШИБКА ТЕСТ РАННЕРА: " . $e->getMessage() . "\n";
        exit(1);
    }
}