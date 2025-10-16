<?php
/**
 * Unit Tests для класса ProductActivityChecker
 * 
 * Тестирует функциональность определения активности товаров на основе критериев:
 * - visibility = "VISIBLE"
 * - state = "processed" 
 * - present > 0 (наличие на складе)
 * - информация о ценах доступна
 * 
 * @version 1.0
 * @author ZUZ System
 */

require_once __DIR__ . '/../../../src/Services/ProductActivityChecker.php';

use Services\ProductActivityChecker;

class ProductActivityCheckerTest
{
    private $checker;
    private $validProductData;
    private $validStockData;
    private $validPriceData;
    private $testResults = [];

    public function __construct()
    {
        $this->checker = new ProductActivityChecker();
        
        // Настройка валидных тестовых данных
        $this->validProductData = [
            'visibility' => 'VISIBLE',
            'state' => 'processed',
            'product_id' => 'TEST_001',
            'name' => 'Test Product'
        ];
        
        $this->validStockData = [
            'present' => 10,
            'reserved' => 2
        ];
        
        $this->validPriceData = [
            'price' => 99.99,
            'old_price' => 120.00
        ];
    }

    /**
     * Запуск всех тестов
     */
    public function runAllTests()
    {
        echo "🧪 ЗАПУСК UNIT ТЕСТОВ ДЛЯ КЛАССА ProductActivityChecker\n";
        echo "=" . str_repeat("=", 70) . "\n\n";
        
        $this->testProductActiveWhenAllCriteriaMet();
        $this->testProductInactiveWhenVisibilityNotVisible();
        $this->testProductInactiveWhenStateNotProcessed();
        $this->testProductInactiveWhenStockIsZero();
        $this->testProductInactiveWhenPricingMissing();
        $this->testProductActiveWithOnlyCurrentPrice();
        $this->testActivityReasonForActiveProduct();
        $this->testActivityReasonForInactiveProduct();
        $this->testBatchProcessingMixedProducts();
        $this->testBatchProcessingEmptyProducts();
        $this->testConfigurationWithCustomThreshold();
        $this->testConfigurationWithPricingNotRequired();
        $this->testEdgeCasesWithMissingFields();
        $this->testEdgeCasesWithInvalidValues();
        $this->testPerformanceWithLargeBatch();
        
        $this->printResults();
    }

    /**
     * Тест: товар активен когда все критерии выполнены
     */
    private function testProductActiveWhenAllCriteriaMet()
    {
        echo "📍 Тест: Товар активен при выполнении всех критериев\n";
        echo "-" . str_repeat("-", 50) . "\n";
        
        try {
            $isActive = $this->checker->isProductActive(
                $this->validProductData,
                $this->validStockData,
                $this->validPriceData
            );
            
            $this->assert($isActive === true, 'Товар должен быть активен при выполнении всех критериев');
            
            $this->testResults['productActiveAllCriteria'] = ['status' => 'PASS', 'message' => 'Все проверки пройдены'];
            
        } catch (Exception $e) {
            $this->testResults['productActiveAllCriteria'] = ['status' => 'FAIL', 'message' => $e->getMessage()];
        }
        
        echo "\n";
    }

    /**
     * Тест: товар неактивен когда visibility не VISIBLE
     */
    private function testProductInactiveWhenVisibilityNotVisible()
    {
        echo "📍 Тест: Товар неактивен при visibility != VISIBLE\n";
        echo "-" . str_repeat("-", 50) . "\n";
        
        try {
            $productData = array_merge($this->validProductData, ['visibility' => 'HIDDEN']);
            
            $isActive = $this->checker->isProductActive($productData, $this->validStockData, $this->validPriceData);
            
            $this->assert($isActive === false, 'Товар должен быть неактивен при visibility = HIDDEN');
            
            // Тест с другими значениями visibility
            $productData['visibility'] = 'ARCHIVED';
            $isActive = $this->checker->isProductActive($productData, $this->validStockData, $this->validPriceData);
            $this->assert($isActive === false, 'Товар должен быть неактивен при visibility = ARCHIVED');
            
            $this->testResults['productInactiveVisibility'] = ['status' => 'PASS', 'message' => 'Все проверки пройдены'];
            
        } catch (Exception $e) {
            $this->testResults['productInactiveVisibility'] = ['status' => 'FAIL', 'message' => $e->getMessage()];
        }
        
        echo "\n";
    }

    /**
     * Тест: товар неактивен когда state не processed
     */
    private function testProductInactiveWhenStateNotProcessed()
    {
        echo "📍 Тест: Товар неактивен при state != processed\n";
        echo "-" . str_repeat("-", 50) . "\n";
        
        try {
            $productData = array_merge($this->validProductData, ['state' => 'draft']);
            
            $isActive = $this->checker->isProductActive($productData, $this->validStockData, $this->validPriceData);
            
            $this->assert($isActive === false, 'Товар должен быть неактивен при state = draft');
            
            // Тест с другими значениями state
            $productData['state'] = 'pending';
            $isActive = $this->checker->isProductActive($productData, $this->validStockData, $this->validPriceData);
            $this->assert($isActive === false, 'Товар должен быть неактивен при state = pending');
            
            $this->testResults['productInactiveState'] = ['status' => 'PASS', 'message' => 'Все проверки пройдены'];
            
        } catch (Exception $e) {
            $this->testResults['productInactiveState'] = ['status' => 'FAIL', 'message' => $e->getMessage()];
        }
        
        echo "\n";
    }

    /**
     * Тест: товар неактивен когда остатки равны нулю
     */
    private function testProductInactiveWhenStockIsZero()
    {
        echo "📍 Тест: Товар неактивен при нулевых остатках\n";
        echo "-" . str_repeat("-", 50) . "\n";
        
        try {
            $stockData = ['present' => 0];
            
            $isActive = $this->checker->isProductActive($this->validProductData, $stockData, $this->validPriceData);
            
            $this->assert($isActive === false, 'Товар должен быть неактивен при present = 0');
            
            // Тест с отрицательными остатками
            $stockData['present'] = -5;
            $isActive = $this->checker->isProductActive($this->validProductData, $stockData, $this->validPriceData);
            $this->assert($isActive === false, 'Товар должен быть неактивен при отрицательных остатках');
            
            $this->testResults['productInactiveStock'] = ['status' => 'PASS', 'message' => 'Все проверки пройдены'];
            
        } catch (Exception $e) {
            $this->testResults['productInactiveStock'] = ['status' => 'FAIL', 'message' => $e->getMessage()];
        }
        
        echo "\n";
    }

    /**
     * Тест: товар неактивен когда отсутствует информация о ценах
     */
    private function testProductInactiveWhenPricingMissing()
    {
        echo "📍 Тест: Товар неактивен при отсутствии цен\n";
        echo "-" . str_repeat("-", 50) . "\n";
        
        try {
            $priceData = [];
            
            $isActive = $this->checker->isProductActive($this->validProductData, $this->validStockData, $priceData);
            
            $this->assert($isActive === false, 'Товар должен быть неактивен при отсутствии цен');
            
            // Тест с нулевыми ценами
            $priceData = ['price' => 0, 'old_price' => 0];
            $isActive = $this->checker->isProductActive($this->validProductData, $this->validStockData, $priceData);
            $this->assert($isActive === false, 'Товар должен быть неактивен при нулевых ценах');
            
            $this->testResults['productInactivePricing'] = ['status' => 'PASS', 'message' => 'Все проверки пройдены'];
            
        } catch (Exception $e) {
            $this->testResults['productInactivePricing'] = ['status' => 'FAIL', 'message' => $e->getMessage()];
        }
        
        echo "\n";
    }

    /**
     * Тест: товар активен когда установлена только текущая цена
     */
    private function testProductActiveWithOnlyCurrentPrice()
    {
        echo "📍 Тест: Товар активен при наличии только текущей цены\n";
        echo "-" . str_repeat("-", 50) . "\n";
        
        try {
            $priceData = ['price' => 99.99, 'old_price' => 0];
            
            $isActive = $this->checker->isProductActive($this->validProductData, $this->validStockData, $priceData);
            
            $this->assert($isActive === true, 'Товар должен быть активен при наличии только текущей цены');
            
            // Тест с только старой ценой
            $priceData = ['price' => 0, 'old_price' => 120.00];
            $isActive = $this->checker->isProductActive($this->validProductData, $this->validStockData, $priceData);
            $this->assert($isActive === true, 'Товар должен быть активен при наличии только старой цены');
            
            $this->testResults['productActivePrice'] = ['status' => 'PASS', 'message' => 'Все проверки пройдены'];
            
        } catch (Exception $e) {
            $this->testResults['productActivePrice'] = ['status' => 'FAIL', 'message' => $e->getMessage()];
        }
        
        echo "\n";
    }

    /**
     * Тест: причина активности для активного товара
     */
    private function testActivityReasonForActiveProduct()
    {
        echo "📍 Тест: Причина активности для активного товара\n";
        echo "-" . str_repeat("-", 50) . "\n";
        
        try {
            $reason = $this->checker->getActivityReason(
                $this->validProductData,
                $this->validStockData,
                $this->validPriceData
            );
            
            $this->assert($reason === 'Product is active - all criteria met', 'Причина должна указывать на активность товара');
            
            $this->testResults['activityReasonActive'] = ['status' => 'PASS', 'message' => 'Все проверки пройдены'];
            
        } catch (Exception $e) {
            $this->testResults['activityReasonActive'] = ['status' => 'FAIL', 'message' => $e->getMessage()];
        }
        
        echo "\n";
    }

    /**
     * Тест: причина неактивности для товара с множественными проблемами
     */
    private function testActivityReasonForInactiveProduct()
    {
        echo "📍 Тест: Причина неактивности товара с множественными проблемами\n";
        echo "-" . str_repeat("-", 50) . "\n";
        
        try {
            $productData = [
                'visibility' => 'HIDDEN',
                'state' => 'draft'
            ];
            $stockData = ['present' => 0];
            $priceData = [];
            
            $reason = $this->checker->getActivityReason($productData, $stockData, $priceData);
            
            $this->assert(strpos($reason, 'Product is inactive:') !== false, 'Причина должна указывать на неактивность');
            $this->assert(strpos($reason, 'visibility is \'HIDDEN\'') !== false, 'Должна быть указана проблема с visibility');
            $this->assert(strpos($reason, 'state is \'draft\'') !== false, 'Должна быть указана проблема с state');
            $this->assert(strpos($reason, 'stock is 0') !== false, 'Должна быть указана проблема с остатками');
            $this->assert(strpos($reason, 'pricing information missing') !== false, 'Должна быть указана проблема с ценами');
            
            // Тест с одной проблемой
            $productData = array_merge($this->validProductData, ['visibility' => 'ARCHIVED']);
            $reason = $this->checker->getActivityReason($productData, $this->validStockData, $this->validPriceData);
            
            $this->assert(strpos($reason, 'visibility is \'ARCHIVED\'') !== false, 'Должна быть указана только проблема с visibility');
            $this->assert(strpos($reason, 'state is') === false, 'Не должно быть проблем с state');
            
            $this->testResults['activityReasonInactive'] = ['status' => 'PASS', 'message' => 'Все проверки пройдены'];
            
        } catch (Exception $e) {
            $this->testResults['activityReasonInactive'] = ['status' => 'FAIL', 'message' => $e->getMessage()];
        }
        
        echo "\n";
    }

    /**
     * Тест: пакетная обработка смешанных активных/неактивных товаров
     */
    private function testBatchProcessingMixedProducts()
    {
        echo "📍 Тест: Пакетная обработка смешанных товаров\n";
        echo "-" . str_repeat("-", 50) . "\n";
        
        try {
            $products = [
                'ACTIVE_001' => [
                    'product' => $this->validProductData,
                    'stock' => $this->validStockData,
                    'price' => $this->validPriceData
                ],
                'INACTIVE_001' => [
                    'product' => array_merge($this->validProductData, ['visibility' => 'HIDDEN']),
                    'stock' => $this->validStockData,
                    'price' => $this->validPriceData
                ],
                'INACTIVE_002' => [
                    'product' => $this->validProductData,
                    'stock' => ['present' => 0],
                    'price' => $this->validPriceData
                ]
            ];
            
            $results = $this->checker->batchCheckActivity($products);
            
            $this->assert(count($results) === 3, 'Должно быть обработано 3 товара');
            
            // Проверка активного товара
            $this->assert($results['ACTIVE_001']['is_active'] === true, 'ACTIVE_001 должен быть активен');
            $this->assert($results['ACTIVE_001']['reason'] === 'Product is active - all criteria met', 'Причина активности должна быть корректной');
            $this->assert($results['ACTIVE_001']['criteria']['visibility_ok'] === true, 'Критерий visibility должен быть выполнен');
            $this->assert($results['ACTIVE_001']['criteria']['state_ok'] === true, 'Критерий state должен быть выполнен');
            $this->assert($results['ACTIVE_001']['criteria']['stock_ok'] === true, 'Критерий stock должен быть выполнен');
            $this->assert($results['ACTIVE_001']['criteria']['pricing_ok'] === true, 'Критерий pricing должен быть выполнен');
            
            // Проверка неактивных товаров
            $this->assert($results['INACTIVE_001']['is_active'] === false, 'INACTIVE_001 должен быть неактивен');
            $this->assert($results['INACTIVE_001']['criteria']['visibility_ok'] === false, 'Критерий visibility не должен быть выполнен');
            
            $this->assert($results['INACTIVE_002']['is_active'] === false, 'INACTIVE_002 должен быть неактивен');
            $this->assert($results['INACTIVE_002']['criteria']['stock_ok'] === false, 'Критерий stock не должен быть выполнен');
            
            // Проверка обязательных полей
            foreach ($results as $productId => $result) {
                $this->assert(isset($result['is_active']), "Поле is_active должно присутствовать для {$productId}");
                $this->assert(isset($result['reason']), "Поле reason должно присутствовать для {$productId}");
                $this->assert(isset($result['checked_at']), "Поле checked_at должно присутствовать для {$productId}");
                $this->assert(isset($result['criteria']), "Поле criteria должно присутствовать для {$productId}");
                $this->assert(is_string($result['checked_at']), "Поле checked_at должно быть строкой для {$productId}");
            }
            
            $this->testResults['batchProcessingMixed'] = ['status' => 'PASS', 'message' => 'Все проверки пройдены'];
            
        } catch (Exception $e) {
            $this->testResults['batchProcessingMixed'] = ['status' => 'FAIL', 'message' => $e->getMessage()];
        }
        
        echo "\n";
    }

    /**
     * Тест: пакетная обработка пустого массива товаров
     */
    private function testBatchProcessingEmptyProducts()
    {
        echo "📍 Тест: Пакетная обработка пустого массива\n";
        echo "-" . str_repeat("-", 50) . "\n";
        
        try {
            $results = $this->checker->batchCheckActivity([]);
            
            $this->assert(is_array($results), 'Результат должен быть массивом');
            $this->assert(empty($results), 'Результат должен быть пустым массивом');
            
            // Тест с неполными данными
            $products = [
                'INCOMPLETE_001' => [
                    'product' => ['visibility' => 'VISIBLE'], // Отсутствует state
                    'stock' => [], // Отсутствует present
                    'price' => [] // Отсутствует price
                ],
                'INCOMPLETE_002' => [
                    'product' => [], // Пустые данные товара
                    // Отсутствуют stock и price
                ]
            ];
            
            $results = $this->checker->batchCheckActivity($products);
            
            $this->assert(count($results) === 2, 'Должно быть обработано 2 товара с неполными данными');
            
            foreach ($results as $result) {
                $this->assert($result['is_active'] === false, 'Товары с неполными данными должны быть неактивны');
                $this->assert(strpos($result['reason'], 'Product is inactive:') !== false, 'Причина должна указывать на неактивность');
            }
            
            $this->testResults['batchProcessingEmpty'] = ['status' => 'PASS', 'message' => 'Все проверки пройдены'];
            
        } catch (Exception $e) {
            $this->testResults['batchProcessingEmpty'] = ['status' => 'FAIL', 'message' => $e->getMessage()];
        }
        
        echo "\n";
    }

    /**
     * Тест: конфигурация с пользовательским порогом остатков
     */
    private function testConfigurationWithCustomThreshold()
    {
        echo "📍 Тест: Конфигурация с пользовательским порогом остатков\n";
        echo "-" . str_repeat("-", 50) . "\n";
        
        try {
            $checker = new ProductActivityChecker(['min_stock_threshold' => 5]);
            
            // Товар с остатками = 3 должен быть неактивен при пороге = 5
            $stockData = ['present' => 3];
            $isActive = $checker->isProductActive($this->validProductData, $stockData, $this->validPriceData);
            
            $this->assert($isActive === false, 'Товар должен быть неактивен при остатках ниже порога');
            
            // Товар с остатками = 6 должен быть активен при пороге = 5
            $stockData = ['present' => 6];
            $isActive = $checker->isProductActive($this->validProductData, $stockData, $this->validPriceData);
            
            $this->assert($isActive === true, 'Товар должен быть активен при остатках выше порога');
            
            // Тест конфигурации
            $config = $checker->getConfig();
            $this->assert($config['min_stock_threshold'] === 5, 'Порог остатков должен быть установлен в 5');
            
            $this->testResults['configurationThreshold'] = ['status' => 'PASS', 'message' => 'Все проверки пройдены'];
            
        } catch (Exception $e) {
            $this->testResults['configurationThreshold'] = ['status' => 'FAIL', 'message' => $e->getMessage()];
        }
        
        echo "\n";
    }

    /**
     * Тест: конфигурация без требования цен
     */
    private function testConfigurationWithPricingNotRequired()
    {
        echo "📍 Тест: Конфигурация без требования цен\n";
        echo "-" . str_repeat("-", 50) . "\n";
        
        try {
            $checker = new ProductActivityChecker(['require_pricing' => false]);
            
            $isActive = $checker->isProductActive($this->validProductData, $this->validStockData, []);
            
            $this->assert($isActive === true, 'Товар должен быть активен когда цены не требуются');
            
            // Тест обновления конфигурации
            $checker->updateConfig(['required_visibility' => 'ALL']);
            $config = $checker->getConfig();
            $this->assert($config['require_pricing'] === false, 'Настройка require_pricing должна остаться false');
            $this->assert($config['required_visibility'] === 'ALL', 'Настройка required_visibility должна быть обновлена');
            
            $this->testResults['configurationPricing'] = ['status' => 'PASS', 'message' => 'Все проверки пройдены'];
            
        } catch (Exception $e) {
            $this->testResults['configurationPricing'] = ['status' => 'FAIL', 'message' => $e->getMessage()];
        }
        
        echo "\n";
    }

    /**
     * Тест: граничные случаи с отсутствующими полями
     */
    private function testEdgeCasesWithMissingFields()
    {
        echo "📍 Тест: Граничные случаи с отсутствующими полями\n";
        echo "-" . str_repeat("-", 50) . "\n";
        
        try {
            // Тест с отсутствующим полем visibility
            $productData = ['state' => 'processed'];
            $isActive = $this->checker->isProductActive($productData, $this->validStockData, $this->validPriceData);
            $this->assert($isActive === false, 'Товар должен быть неактивен при отсутствии visibility');
            
            $reason = $this->checker->getActivityReason($productData, $this->validStockData, $this->validPriceData);
            $this->assert(strpos($reason, 'visibility is \'unknown\'') !== false, 'Причина должна указывать на неизвестную visibility');
            
            // Тест с отсутствующим полем state
            $productData = ['visibility' => 'VISIBLE'];
            $isActive = $this->checker->isProductActive($productData, $this->validStockData, $this->validPriceData);
            $this->assert($isActive === false, 'Товар должен быть неактивен при отсутствии state');
            
            $reason = $this->checker->getActivityReason($productData, $this->validStockData, $this->validPriceData);
            $this->assert(strpos($reason, 'state is \'unknown\'') !== false, 'Причина должна указывать на неизвестный state');
            
            // Тест с отсутствующим полем present в остатках
            $stockData = ['reserved' => 2];
            $isActive = $this->checker->isProductActive($this->validProductData, $stockData, $this->validPriceData);
            $this->assert($isActive === false, 'Товар должен быть неактивен при отсутствии поля present');
            
            $this->testResults['edgeCasesMissingFields'] = ['status' => 'PASS', 'message' => 'Все проверки пройдены'];
            
        } catch (Exception $e) {
            $this->testResults['edgeCasesMissingFields'] = ['status' => 'FAIL', 'message' => $e->getMessage()];
        }
        
        echo "\n";
    }

    /**
     * Тест: граничные случаи с некорректными значениями
     */
    private function testEdgeCasesWithInvalidValues()
    {
        echo "📍 Тест: Граничные случаи с некорректными значениями\n";
        echo "-" . str_repeat("-", 50) . "\n";
        
        try {
            // Тест с отрицательными остатками
            $stockData = ['present' => -5];
            $isActive = $this->checker->isProductActive($this->validProductData, $stockData, $this->validPriceData);
            $this->assert($isActive === false, 'Товар должен быть неактивен при отрицательных остатках');
            
            // Тест с отрицательными ценами
            $priceData = ['price' => -10.00, 'old_price' => -20.00];
            $isActive = $this->checker->isProductActive($this->validProductData, $this->validStockData, $priceData);
            $this->assert($isActive === false, 'Товар должен быть неактивен при отрицательных ценах');
            
            // Тест с пустыми строками
            $productData = [
                'visibility' => '',
                'state' => ''
            ];
            $isActive = $this->checker->isProductActive($productData, $this->validStockData, $this->validPriceData);
            $this->assert($isActive === false, 'Товар должен быть неактивен при пустых строках');
            
            $this->testResults['edgeCasesInvalidValues'] = ['status' => 'PASS', 'message' => 'Все проверки пройдены'];
            
        } catch (Exception $e) {
            $this->testResults['edgeCasesInvalidValues'] = ['status' => 'FAIL', 'message' => $e->getMessage()];
        }
        
        echo "\n";
    }

    /**
     * Тест: производительность с большой пакетной обработкой
     */
    private function testPerformanceWithLargeBatch()
    {
        echo "📍 Тест: Производительность с большой пакетной обработкой\n";
        echo "-" . str_repeat("-", 50) . "\n";
        
        try {
            $products = [];
            
            // Создаем 100 тестовых товаров
            for ($i = 1; $i <= 100; $i++) {
                $products["PERF_TEST_{$i}"] = [
                    'product' => array_merge($this->validProductData, ['product_id' => "PERF_TEST_{$i}"]),
                    'stock' => $this->validStockData,
                    'price' => $this->validPriceData
                ];
            }
            
            $startTime = microtime(true);
            $results = $this->checker->batchCheckActivity($products);
            $endTime = microtime(true);
            
            $executionTime = $endTime - $startTime;
            
            $this->assert(count($results) === 100, 'Должно быть обработано 100 товаров');
            $this->assert($executionTime < 1.0, 'Пакетная обработка должна завершиться менее чем за 1 секунду');
            
            // Проверяем, что все товары обработаны корректно
            foreach ($results as $result) {
                $this->assert($result['is_active'] === true, 'Все тестовые товары должны быть активны');
                $this->assert($result['reason'] === 'Product is active - all criteria met', 'Причина активности должна быть корректной');
            }
            
            // Тест использования памяти
            $initialMemory = memory_get_usage();
            
            for ($i = 1; $i <= 50; $i++) {
                $products["MEMORY_TEST_{$i}"] = [
                    'product' => $this->validProductData,
                    'stock' => $this->validStockData,
                    'price' => $this->validPriceData
                ];
            }
            
            $this->checker->batchCheckActivity($products);
            
            $finalMemory = memory_get_usage();
            $memoryIncrease = $finalMemory - $initialMemory;
            
            $this->assert($memoryIncrease < 5 * 1024 * 1024, 'Увеличение использования памяти должно быть разумным (менее 5MB)');
            
            $this->testResults['performanceLargeBatch'] = ['status' => 'PASS', 'message' => "Обработано 100 товаров за {$executionTime} сек"];
            
        } catch (Exception $e) {
            $this->testResults['performanceLargeBatch'] = ['status' => 'FAIL', 'message' => $e->getMessage()];
        }
        
        echo "\n";
    }

    /**
     * Вспомогательный метод для проверки утверждений
     */
    private function assert($condition, $message)
    {
        if (!$condition) {
            throw new Exception("Assertion failed: " . $message);
        }
        echo "✅ " . $message . "\n";
    }

    /**
     * Вывод результатов тестирования
     */
    private function printResults()
    {
        echo "🎉 РЕЗУЛЬТАТЫ ТЕСТИРОВАНИЯ КЛАССА ProductActivityChecker\n";
        echo "=" . str_repeat("=", 70) . "\n";
        
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
        
        echo "\n📋 ПРОТЕСТИРОВАННАЯ ФУНКЦИОНАЛЬНОСТЬ:\n";
        echo "  ✅ Определение активности товара по всем критериям\n";
        echo "  ✅ Проверка критерия visibility (VISIBLE)\n";
        echo "  ✅ Проверка критерия state (processed)\n";
        echo "  ✅ Проверка критерия остатков (present > 0)\n";
        echo "  ✅ Проверка критерия цен (price > 0 или old_price > 0)\n";
        echo "  ✅ Получение детальных причин активности/неактивности\n";
        echo "  ✅ Пакетная обработка товаров\n";
        echo "  ✅ Настройка пользовательских параметров\n";
        echo "  ✅ Обработка граничных случаев\n";
        echo "  ✅ Производительность при больших объемах данных\n";
        
        echo "\n🎯 СООТВЕТСТВИЕ ТРЕБОВАНИЯМ:\n";
        echo "  ✅ Requirement 3.1: Комбинированные критерии активности товаров\n";
        echo "  ✅ Requirement 3.1: Эффективная пакетная обработка\n";
        echo "  ✅ Requirement 3.1: Детальное логирование причин неактивности\n";
        
        if ($failed === 0) {
            echo "\n🎉 ВСЕ ТЕСТЫ ПРОЙДЕНЫ УСПЕШНО!\n";
            echo "Класс ProductActivityChecker готов к использованию.\n";
            echo "Система определения активности товаров полностью протестирована.\n";
        } else {
            echo "\n⚠️  ОБНАРУЖЕНЫ ПРОБЛЕМЫ!\n";
            echo "Необходимо исправить {$failed} провалившихся тестов.\n";
        }
        
        echo "\n" . str_repeat("=", 70) . "\n";
    }
}

// Запуск тестов если файл выполняется напрямую
if (basename(__FILE__) == basename($_SERVER['SCRIPT_NAME'])) {
    try {
        $test = new ProductActivityCheckerTest();
        $test->runAllTests();
    } catch (Exception $e) {
        echo "❌ КРИТИЧЕСКАЯ ОШИБКА: " . $e->getMessage() . "\n";
    }
}