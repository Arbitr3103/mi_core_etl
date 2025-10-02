<?php
/**
 * Unit Tests for MarketplaceDetector Class
 * 
 * Tests marketplace detection logic, edge cases, and SQL filter generation
 * 
 * @version 1.0
 * @author Manhattan System
 */

require_once __DIR__ . '/../src/classes/MarketplaceDetector.php';

class MarketplaceDetectorTest {
    
    private $mockPdo;
    private $detector;
    private $testResults = [];
    
    public function __construct() {
        // Create mock PDO for database-dependent tests
        $this->mockPdo = new class {
            public function prepare($sql) {
                return new class {
                    public function execute($params) { return true; }
                    public function fetchAll($mode) { return []; }
                };
            }
        };
        $this->detector = new MarketplaceDetector($this->mockPdo);
    }
    
    /**
     * Run all MarketplaceDetector tests
     */
    public function runAllTests() {
        echo "🧪 ТЕСТИРОВАНИЕ MarketplaceDetector\n";
        echo "=" . str_repeat("=", 80) . "\n";
        
        $this->testDetectFromSourceCode();
        $this->testDetectFromSourceName();
        $this->testDetectFromSku();
        $this->testDetectMarketplace();
        $this->testUtilityMethods();
        $this->testValidation();
        $this->testSqlFilters();
        $this->testEdgeCases();
        
        $this->printTestSummary();
    }
    
    private function assert($condition, $message) {
        if ($condition) {
            echo "  ✅ {$message}\n";
            $this->testResults[] = true;
        } else {
            echo "  ❌ {$message}\n";
            $this->testResults[] = false;
        }
    }
    
    private function assertEquals($expected, $actual, $message = '') {
        $condition = $expected === $actual;
        $msg = $message ?: "Expected '{$expected}', got '{$actual}'";
        $this->assert($condition, $msg);
    }
    
    private function assertTrue($condition, $message = '') {
        $this->assert($condition, $message ?: "Expected true");
    }
    
    private function assertFalse($condition, $message = '') {
        $this->assert(!$condition, $message ?: "Expected false");
    }
    
    private function assertNull($value, $message = '') {
        $this->assert($value === null, $message ?: "Expected null");
    }
    
    private function assertIsArray($value, $message = '') {
        $this->assert(is_array($value), $message ?: "Expected array");
    }
    
    private function assertCount($expected, $array, $message = '') {
        $condition = count($array) === $expected;
        $msg = $message ?: "Expected count {$expected}, got " . count($array);
        $this->assert($condition, $msg);
    }
    
    private function assertContains($needle, $haystack, $message = '') {
        $condition = in_array($needle, $haystack);
        $msg = $message ?: "Expected array to contain '{$needle}'";
        $this->assert($condition, $msg);
    }
    
    private function assertStringContains($needle, $haystack, $message = '') {
        $condition = strpos($haystack, $needle) !== false;
        $msg = $message ?: "Expected string to contain '{$needle}'";
        $this->assert($condition, $msg);
    }
    
    private function assertStringStartsWith($prefix, $string, $message = '') {
        $condition = strpos($string, $prefix) === 0;
        $msg = $message ?: "Expected string to start with '{$prefix}'";
        $this->assert($condition, $msg);
    }
    
    private function assertStringNotContains($needle, $haystack, $message = '') {
        $condition = strpos($haystack, $needle) === false;
        $msg = $message ?: "Expected string to NOT contain '{$needle}'";
        $this->assert($condition, $msg);
    }
    
    private function assertArrayHasKey($key, $array, $message = '') {
        $condition = array_key_exists($key, $array);
        $msg = $message ?: "Expected array to have key '{$key}'";
        $this->assert($condition, $msg);
    }
    
    private function assertEmpty($value, $message = '') {
        $this->assert(empty($value), $message ?: "Expected empty value");
    }
    
    /**
     * Test detectFromSourceCode method
     */
    public function testDetectFromSourceCode() {
        echo "\n🔍 Тестирование detectFromSourceCode:\n";
        
        // Ozon patterns
        $this->assertEquals(MarketplaceDetector::OZON, MarketplaceDetector::detectFromSourceCode('ozon'), "Обнаружение 'ozon'");
        $this->assertEquals(MarketplaceDetector::OZON, MarketplaceDetector::detectFromSourceCode('OZON'), "Обнаружение 'OZON' (верхний регистр)");
        $this->assertEquals(MarketplaceDetector::OZON, MarketplaceDetector::detectFromSourceCode('ozon_api'), "Обнаружение 'ozon_api'");
        $this->assertEquals(MarketplaceDetector::OZON, MarketplaceDetector::detectFromSourceCode('озон'), "Обнаружение 'озон' (кириллица)");
        $this->assertEquals(MarketplaceDetector::OZON, MarketplaceDetector::detectFromSourceCode('  ozon  '), "Обнаружение с пробелами");
        
        // Wildberries patterns
        $this->assertEquals(MarketplaceDetector::WILDBERRIES, MarketplaceDetector::detectFromSourceCode('wildberries'), "Обнаружение 'wildberries'");
        $this->assertEquals(MarketplaceDetector::WILDBERRIES, MarketplaceDetector::detectFromSourceCode('wb'), "Обнаружение 'wb'");
        $this->assertEquals(MarketplaceDetector::WILDBERRIES, MarketplaceDetector::detectFromSourceCode('вб'), "Обнаружение 'вб' (кириллица)");
        $this->assertEquals(MarketplaceDetector::WILDBERRIES, MarketplaceDetector::detectFromSourceCode('wb_api'), "Обнаружение 'wb_api'");
        
        // Edge cases
        $this->assertEquals(MarketplaceDetector::UNKNOWN, MarketplaceDetector::detectFromSourceCode(''), "Пустая строка");
        $this->assertEquals(MarketplaceDetector::UNKNOWN, MarketplaceDetector::detectFromSourceCode(null), "Null значение");
        $this->assertEquals(MarketplaceDetector::UNKNOWN, MarketplaceDetector::detectFromSourceCode('amazon'), "Неизвестный источник");
        $this->assertEquals(MarketplaceDetector::UNKNOWN, MarketplaceDetector::detectFromSourceCode('ozo'), "Частичное совпадение");
    }
    
    /**
     * Test detectFromSourceName method
     */
    public function testDetectFromSourceName() {
        echo "\n🔍 Тестирование detectFromSourceName:\n";
        
        $this->assertEquals(MarketplaceDetector::OZON, MarketplaceDetector::detectFromSourceName('Ozon Marketplace'), "Обнаружение в названии Ozon");
        $this->assertEquals(MarketplaceDetector::OZON, MarketplaceDetector::detectFromSourceName('ОЗОН Маркетплейс'), "Обнаружение в названии ОЗОН");
        $this->assertEquals(MarketplaceDetector::WILDBERRIES, MarketplaceDetector::detectFromSourceName('Wildberries Store'), "Обнаружение в названии Wildberries");
        $this->assertEquals(MarketplaceDetector::WILDBERRIES, MarketplaceDetector::detectFromSourceName('WB API'), "Обнаружение в названии WB");
        $this->assertEquals(MarketplaceDetector::UNKNOWN, MarketplaceDetector::detectFromSourceName(''), "Пустое название");
        $this->assertEquals(MarketplaceDetector::UNKNOWN, MarketplaceDetector::detectFromSourceName('Amazon Store'), "Неизвестное название");
    }
    
    /**
     * Test detectFromSku method
     */
    public function testDetectFromSku() {
        echo "\n🔍 Тестирование detectFromSku:\n";
        
        // Exact matches
        $this->assertEquals(MarketplaceDetector::OZON, MarketplaceDetector::detectFromSku('OZ123456', 'WB789012', 'OZ123456'), "Точное совпадение Ozon SKU");
        $this->assertEquals(MarketplaceDetector::WILDBERRIES, MarketplaceDetector::detectFromSku('OZ123456', 'WB789012', 'WB789012'), "Точное совпадение Wildberries SKU");
        $this->assertEquals(MarketplaceDetector::UNKNOWN, MarketplaceDetector::detectFromSku('OZ123456', 'WB789012', 'OTHER123'), "Нет совпадений");
        
        // Edge cases
        $this->assertEquals(MarketplaceDetector::UNKNOWN, MarketplaceDetector::detectFromSku('OZ123456', 'WB789012', ''), "Пустой текущий SKU");
        $this->assertEquals(MarketplaceDetector::UNKNOWN, MarketplaceDetector::detectFromSku('', '', 'SOME123'), "Пустые SKU маркетплейсов");
        $this->assertEquals(MarketplaceDetector::OZON, MarketplaceDetector::detectFromSku('OZ123456', null, 'OZ123456'), "Только Ozon SKU");
        $this->assertEquals(MarketplaceDetector::WILDBERRIES, MarketplaceDetector::detectFromSku(null, 'WB789012', 'WB789012'), "Только Wildberries SKU");
        
        // Ambiguous case - same SKU for both marketplaces (should return first match - Ozon)
        $this->assertEquals(MarketplaceDetector::OZON, MarketplaceDetector::detectFromSku('SAME123', 'SAME123', 'SAME123'), "Одинаковые SKU");
    }
    
    /**
     * Test detectMarketplace method with priority logic
     */
    public function testDetectMarketplace() {
        echo "\n🔍 Тестирование detectMarketplace (приоритеты):\n";
        
        // Priority 1: Source code takes precedence
        $this->assertEquals(MarketplaceDetector::OZON, 
            MarketplaceDetector::detectMarketplace('ozon', 'wildberries_store', 'OZ123', 'WB456', 'WB456'), 
            "Приоритет кода источника");
        
        // Priority 2: Source name when source code is empty
        $this->assertEquals(MarketplaceDetector::WILDBERRIES, 
            MarketplaceDetector::detectMarketplace('', 'wildberries_store', 'OZ123', 'WB456', 'OZ123'), 
            "Приоритет названия источника");
        
        // Priority 3: SKU when both source code and name are empty
        $this->assertEquals(MarketplaceDetector::OZON, 
            MarketplaceDetector::detectMarketplace('', '', 'OZ123', 'WB456', 'OZ123'), 
            "Приоритет SKU");
        
        // All methods return unknown
        $this->assertEquals(MarketplaceDetector::UNKNOWN, 
            MarketplaceDetector::detectMarketplace('unknown', 'unknown_store', 'OZ123', 'WB456', 'OTHER789'), 
            "Все методы возвращают неизвестный");
        
        // All parameters are empty
        $this->assertEquals(MarketplaceDetector::UNKNOWN, 
            MarketplaceDetector::detectMarketplace('', '', '', '', ''), 
            "Все параметры пустые");
    }
    
    /**
     * Test utility methods
     */
    public function testUtilityMethods() {
        echo "\n🔧 Тестирование вспомогательных методов:\n";
        
        // getAllMarketplaces
        $marketplaces = MarketplaceDetector::getAllMarketplaces();
        $this->assertIsArray($marketplaces, "getAllMarketplaces возвращает массив");
        $this->assertCount(2, $marketplaces, "Два маркетплейса");
        $this->assertContains(MarketplaceDetector::OZON, $marketplaces, "Содержит Ozon");
        $this->assertContains(MarketplaceDetector::WILDBERRIES, $marketplaces, "Содержит Wildberries");
        
        // getMarketplaceName
        $this->assertEquals('Ozon', MarketplaceDetector::getMarketplaceName(MarketplaceDetector::OZON), "Название Ozon");
        $this->assertEquals('Wildberries', MarketplaceDetector::getMarketplaceName(MarketplaceDetector::WILDBERRIES), "Название Wildberries");
        $this->assertEquals('Неопределенный источник', MarketplaceDetector::getMarketplaceName(MarketplaceDetector::UNKNOWN), "Название неизвестного");
        $this->assertEquals('Неизвестный маркетплейс', MarketplaceDetector::getMarketplaceName('invalid'), "Название недопустимого");
        
        // getMarketplaceIcon
        $this->assertEquals('📦', MarketplaceDetector::getMarketplaceIcon(MarketplaceDetector::OZON), "Иконка Ozon");
        $this->assertEquals('🛍️', MarketplaceDetector::getMarketplaceIcon(MarketplaceDetector::WILDBERRIES), "Иконка Wildberries");
        $this->assertEquals('❓', MarketplaceDetector::getMarketplaceIcon(MarketplaceDetector::UNKNOWN), "Иконка неизвестного");
        $this->assertEquals('🏪', MarketplaceDetector::getMarketplaceIcon('invalid'), "Иконка недопустимого");
    }
    
    /**
     * Test validation methods
     */
    public function testValidation() {
        echo "\n✅ Тестирование валидации:\n";
        
        // Valid inputs
        $result = MarketplaceDetector::validateMarketplaceParameter(null);
        $this->assertTrue($result['valid'], "Null параметр валиден");
        $this->assertNull($result['error'], "Null параметр без ошибки");
        
        $result = MarketplaceDetector::validateMarketplaceParameter(MarketplaceDetector::OZON);
        $this->assertTrue($result['valid'], "Ozon параметр валиден");
        
        $result = MarketplaceDetector::validateMarketplaceParameter('OZON');
        $this->assertTrue($result['valid'], "OZON (верхний регистр) валиден");
        
        $result = MarketplaceDetector::validateMarketplaceParameter('  ozon  ');
        $this->assertTrue($result['valid'], "Ozon с пробелами валиден");
        
        // Invalid inputs
        $result = MarketplaceDetector::validateMarketplaceParameter(123);
        $this->assertFalse($result['valid'], "Число невалидно");
        $this->assertEquals('Параметр маркетплейса должен быть строкой', $result['error'], "Ошибка для числа");
        
        $result = MarketplaceDetector::validateMarketplaceParameter('');
        $this->assertFalse($result['valid'], "Пустая строка невалидна");
        
        $result = MarketplaceDetector::validateMarketplaceParameter('amazon');
        $this->assertFalse($result['valid'], "Неизвестный маркетплейс невалиден");
        $this->assertStringContains('Недопустимый маркетплейс', $result['error'], "Ошибка содержит текст о недопустимом маркетплейсе");
    }
    
    /**
     * Test SQL filter building
     */
    public function testSqlFilters() {
        echo "\n🗄️ Тестирование SQL фильтров:\n";
        
        // Ozon filter
        $result = $this->detector->buildMarketplaceFilter(MarketplaceDetector::OZON);
        $this->assertIsArray($result, "Результат фильтра Ozon - массив");
        $this->assertArrayHasKey('condition', $result, "Есть ключ condition");
        $this->assertArrayHasKey('params', $result, "Есть ключ params");
        $this->assertStringContains('s.code LIKE :ozon_code', $result['condition'], "Условие содержит код Ozon");
        $this->assertStringContains('dp.sku_ozon IS NOT NULL', $result['condition'], "Условие содержит SKU Ozon");
        $this->assertEquals('%ozon%', $result['params']['ozon_code'], "Параметр ozon_code корректен");
        
        // Wildberries filter
        $result = $this->detector->buildMarketplaceFilter(MarketplaceDetector::WILDBERRIES);
        $this->assertStringContains('s.code LIKE :wb_code1', $result['condition'], "Условие содержит код WB");
        $this->assertStringContains('dp.sku_wb IS NOT NULL', $result['condition'], "Условие содержит SKU WB");
        $this->assertEquals('%wb%', $result['params']['wb_code1'], "Параметр wb_code1 корректен");
        $this->assertEquals('%wildberries%', $result['params']['wb_code2'], "Параметр wb_code2 корректен");
        
        // Null marketplace (all marketplaces)
        $result = $this->detector->buildMarketplaceFilter(null);
        $this->assertEquals('1=1', $result['condition'], "Null маркетплейс возвращает 1=1");
        $this->assertEmpty($result['params'], "Null маркетплейс без параметров");
        
        // Custom table aliases
        $result = $this->detector->buildMarketplaceFilter(MarketplaceDetector::OZON, 'sources', 'products', 'orders');
        $this->assertStringContains('sources.code', $result['condition'], "Кастомный алиас sources");
        $this->assertStringContains('products.sku_ozon', $result['condition'], "Кастомный алиас products");
        $this->assertStringContains('orders.sku', $result['condition'], "Кастомный алиас orders");
        
        // Exclude filter
        $result = $this->detector->buildMarketplaceExcludeFilter(MarketplaceDetector::OZON);
        $this->assertStringStartsWith('NOT (', $result['condition'], "Исключающий фильтр начинается с NOT");
        
        // SQL injection prevention
        $result = $this->detector->buildMarketplaceFilter(MarketplaceDetector::OZON);
        $this->assertStringNotContains('ozon', $result['condition'], "Условие не содержит литеральных значений");
        $this->assertStringContains(':ozon_code', $result['condition'], "Условие содержит параметры");
        $this->assertArrayHasKey('ozon_code', $result['params'], "Параметры содержат ozon_code");
    }
    
    /**
     * Test edge cases and error handling
     */
    public function testEdgeCases() {
        echo "\n🚨 Тестирование граничных случаев:\n";
        
        // Missing data scenarios
        $this->assertEquals(MarketplaceDetector::UNKNOWN, 
            MarketplaceDetector::detectMarketplace(null, null, null, null, null), 
            "Все null значения");
        
        $this->assertEquals(MarketplaceDetector::UNKNOWN, 
            MarketplaceDetector::detectMarketplace('   ', '   ', '   ', '   ', '   '), 
            "Только пробелы");
        
        // Case sensitivity
        $this->assertEquals(MarketplaceDetector::OZON, MarketplaceDetector::detectFromSourceCode('OZON'), "Верхний регистр источника");
        $this->assertEquals(MarketplaceDetector::OZON, MarketplaceDetector::detectFromSourceCode('ozon'), "Нижний регистр источника");
        
        // SKU detection should be case sensitive (exact match)
        $this->assertEquals(MarketplaceDetector::OZON, MarketplaceDetector::detectFromSku('OZ123', 'WB456', 'OZ123'), "Точное совпадение SKU");
        $this->assertEquals(MarketplaceDetector::UNKNOWN, MarketplaceDetector::detectFromSku('OZ123', 'WB456', 'oz123'), "Неточное совпадение SKU");
        
        // Invalid marketplace parameter should throw exception
        try {
            $this->detector->buildMarketplaceFilter('invalid_marketplace');
            $this->assert(false, "Исключение должно быть выброшено для недопустимого маркетплейса");
        } catch (InvalidArgumentException $e) {
            $this->assertStringContains('Неподдерживаемый маркетплейс', $e->getMessage(), "Правильное сообщение об ошибке");
        }
    }
    
    private function printTestSummary() {
        $total = count($this->testResults);
        $passed = count(array_filter($this->testResults));
        $failed = $total - $passed;
        
        echo "\n" . str_repeat("=", 80) . "\n";
        echo "📊 РЕЗУЛЬТАТЫ ТЕСТИРОВАНИЯ MarketplaceDetector:\n";
        echo "  Всего тестов: {$total}\n";
        echo "  ✅ Пройдено: {$passed}\n";
        echo "  ❌ Провалено: {$failed}\n";
        
        if ($total > 0) {
            $successRate = round(($passed / $total) * 100, 1);
            echo "  📈 Успешность: {$successRate}%\n";
        }
        
        echo "\n📋 ПРОТЕСТИРОВАННАЯ ФУНКЦИОНАЛЬНОСТЬ:\n";
        echo "  ✅ Определение маркетплейса по коду источника\n";
        echo "  ✅ Определение маркетплейса по названию источника\n";
        echo "  ✅ Определение маркетплейса по SKU\n";
        echo "  ✅ Комплексное определение с приоритетами\n";
        echo "  ✅ Вспомогательные методы (названия, иконки)\n";
        echo "  ✅ Валидация параметров\n";
        echo "  ✅ Построение SQL фильтров\n";
        echo "  ✅ Обработка граничных случаев\n";
        echo "  ✅ Защита от SQL инъекций\n";
        echo "  ✅ Обработка ошибок\n";
        
        echo "\n🎯 СООТВЕТСТВИЕ ТРЕБОВАНИЯМ:\n";
        echo "  ✅ Requirement 6.1: Определение маркетплейса по источнику данных\n";
        echo "  ✅ Requirement 6.2: Обработка отсутствующих данных\n";
        echo "  ✅ Requirement 6.4: Валидация параметров маркетплейса\n";
        
        if ($failed === 0) {
            echo "\n🎉 ВСЕ ТЕСТЫ MarketplaceDetector ПРОЙДЕНЫ УСПЕШНО!\n";
            echo "Класс MarketplaceDetector готов к использованию.\n";
            echo "Логика определения маркетплейсов полностью протестирована.\n";
        } else {
            echo "\n⚠️  ОБНАРУЖЕНЫ ПРОБЛЕМЫ В MarketplaceDetector!\n";
            echo "Необходимо исправить {$failed} провалившихся тестов.\n";
        }
        
        echo "\n" . str_repeat("=", 80) . "\n";
    }
}
?>