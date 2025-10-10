<?php
/**
 * Data Type Compatibility Tests
 * 
 * Comprehensive tests for data type normalization and compatibility
 * across different database systems and API responses.
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../src/DataTypeNormalizer.php';

class DataTypeCompatibilityTest extends TestCase {
    private $normalizer;
    
    protected function setUp(): void {
        $this->normalizer = new DataTypeNormalizer();
    }
    
    protected function tearDown(): void {
        $this->normalizer = null;
    }
    
    /**
     * Test: INT to VARCHAR conversion
     */
    public function testIntToVarcharConversion() {
        $testCases = [
            [12345, '12345'],
            [0, null],
            [999999999, '999999999'],
            [-1, null],
        ];
        
        foreach ($testCases as [$input, $expected]) {
            $result = $this->normalizer->normalizeId($input);
            $this->assertEquals($expected, $result, "Failed for input: {$input}");
        }
    }
    
    /**
     * Test: VARCHAR to INT conversion
     */
    public function testVarcharToIntConversion() {
        $testCases = [
            ['12345', '12345'],
            ['0', null],
            ['  12345  ', '12345'],
            ['', null],
        ];
        
        foreach ($testCases as [$input, $expected]) {
            $result = $this->normalizer->normalizeId($input);
            $this->assertEquals($expected, $result, "Failed for input: {$input}");
        }
    }
    
    /**
     * Test: Mixed type ID comparison
     */
    public function testMixedTypeIdComparison() {
        $testCases = [
            ['12345', 12345, true],
            [12345, '12345', true],
            ['12345', '12345', true],
            [12345, 12345, true],
            ['12345', '67890', false],
            [12345, 67890, false],
            [null, '12345', false],
            ['12345', null, false],
            [null, null, false],
            [0, '0', false],
            ['', '', false],
        ];
        
        foreach ($testCases as [$id1, $id2, $expected]) {
            $result = $this->normalizer->compareIds($id1, $id2);
            $this->assertEquals(
                $expected, 
                $result, 
                "Failed comparing {$id1} and {$id2}"
            );
        }
    }
    
    /**
     * Test: SQL CAST generation for different types
     */
    public function testSQLCastGeneration() {
        $testCases = [
            ['field1', 'field2', 'CAST(field1 AS CHAR) = CAST(field2 AS CHAR)'],
            ['id', 'product_id', 'CAST(id AS CHAR) = CAST(product_id AS CHAR)'],
        ];
        
        foreach ($testCases as [$field1, $field2, $expectedPattern]) {
            $result = $this->normalizer->getSafeComparisonSQL($field1, $field2);
            $this->assertStringContainsString('CAST', $result);
            $this->assertStringContainsString($field1, $result);
            $this->assertStringContainsString($field2, $result);
        }
    }
    
    /**
     * Test: Safe JOIN value creation
     */
    public function testSafeJoinValueCreation() {
        $testCases = [
            [12345, 'VARCHAR', "'12345'"],
            ['12345', 'VARCHAR', "'12345'"],
            [12345, 'INT', '12345'],
            ['12345', 'INT', '12345'],
            [null, 'VARCHAR', 'NULL'],
            [null, 'INT', 'NULL'],
            ['', 'VARCHAR', 'NULL'],
            [0, 'INT', 'NULL'],
        ];
        
        foreach ($testCases as [$value, $type, $expected]) {
            $result = $this->normalizer->createSafeJoinValue($value, $type);
            $this->assertEquals($expected, $result, "Failed for value: {$value}, type: {$type}");
        }
    }
    
    /**
     * Test: Numeric string normalization
     */
    public function testNumericStringNormalization() {
        $testCases = [
            ['123', 'quantity', 123],
            ['123.45', 'price', 123.45],
            ['1,234.56', 'price', 1234.56],
            ['1 234.56', 'price', 1234.56],
            ['not-a-number', 'quantity', null],
            ['', 'quantity', null],
        ];
        
        foreach ($testCases as [$input, $field, $expected]) {
            $result = $this->normalizer->normalizeValue($input, $field);
            $this->assertEquals($expected, $result, "Failed for input: {$input}");
        }
    }
    
    /**
     * Test: Boolean value normalization
     */
    public function testBooleanValueNormalization() {
        $trueCases = ['true', 'TRUE', 'yes', 'YES', '1', 1, true];
        $falseCases = ['false', 'FALSE', 'no', 'NO', '0', 0, false];
        
        foreach ($trueCases as $input) {
            $result = $this->normalizer->normalizeValue($input, 'is_active');
            $this->assertTrue($result, "Failed for input: {$input}");
        }
        
        foreach ($falseCases as $input) {
            $result = $this->normalizer->normalizeValue($input, 'is_active');
            $this->assertFalse($result, "Failed for input: {$input}");
        }
    }
    
    /**
     * Test: DateTime normalization
     */
    public function testDateTimeNormalization() {
        $testCases = [
            ['2025-10-10', '2025-10-10 00:00:00'],
            ['2025-10-10 12:30:45', '2025-10-10 12:30:45'],
            ['invalid-date', null],
            ['', null],
        ];
        
        foreach ($testCases as [$input, $expected]) {
            $result = $this->normalizer->normalizeValue($input, 'created_at');
            $this->assertEquals($expected, $result, "Failed for input: {$input}");
        }
    }
    
    /**
     * Test: String trimming and cleaning
     */
    public function testStringTrimmingAndCleaning() {
        $testCases = [
            ['  Test  ', 'Test'],
            ['Test   String', 'Test String'],
            ['  ', null],
            ['', null],
            ['Test\nString', 'Test String'],
            ['Test\tString', 'Test String'],
        ];
        
        foreach ($testCases as [$input, $expected]) {
            $result = $this->normalizer->normalizeValue($input, 'name');
            $this->assertEquals($expected, $result, "Failed for input: {$input}");
        }
    }
    
    /**
     * Test: Product ID validation
     */
    public function testProductIdValidation() {
        $validIds = ['12345', 12345, '123-456', '123_456'];
        $invalidIds = [null, '', 0, 'abc123', '12.34', '  ', -1];
        
        foreach ($validIds as $id) {
            $result = $this->normalizer->isValidProductId($id);
            $this->assertTrue($result, "Failed for valid ID: {$id}");
        }
        
        foreach ($invalidIds as $id) {
            $result = $this->normalizer->isValidProductId($id);
            $this->assertFalse($result, "Failed for invalid ID: {$id}");
        }
    }
    
    /**
     * Test: Ozon API response normalization
     */
    public function testOzonAPIResponseNormalization() {
        $responses = [
            // Standard response
            [
                'input' => [
                    'product_id' => 12345,
                    'offer_id' => 'SKU-123',
                    'name' => 'Test Product',
                    'price' => '1234.56',
                    'stocks' => [
                        ['present' => 50],
                        ['present' => 30]
                    ]
                ],
                'expected' => [
                    'ozon_product_id' => '12345',
                    'sku_ozon' => 'SKU-123',
                    'name' => 'Test Product',
                    'quantity' => 80
                ]
            ],
            // Response with string IDs
            [
                'input' => [
                    'product_id' => '67890',
                    'offer_id' => 'SKU-456',
                    'name' => 'Another Product',
                    'stocks' => []
                ],
                'expected' => [
                    'ozon_product_id' => '67890',
                    'sku_ozon' => 'SKU-456',
                    'name' => 'Another Product',
                    'quantity' => 0
                ]
            ],
        ];
        
        foreach ($responses as $testCase) {
            $result = $this->normalizer->normalizeAPIResponse($testCase['input'], 'ozon');
            
            foreach ($testCase['expected'] as $key => $value) {
                $this->assertEquals(
                    $value, 
                    $result[$key], 
                    "Failed for key: {$key}"
                );
            }
        }
    }
    
    /**
     * Test: Wildberries API response normalization
     */
    public function testWildberriesAPIResponseNormalization() {
        $response = [
            'nmId' => 12345,
            'vendorCode' => 'WB-SKU-123',
            'title' => 'WB Product',
            'brand' => 'Test Brand',
            'quantity' => 100
        ];
        
        $result = $this->normalizer->normalizeAPIResponse($response, 'wb');
        
        $this->assertEquals('12345', $result['wb_product_id']);
        $this->assertEquals('WB-SKU-123', $result['sku_wb']);
        $this->assertEquals('WB Product', $result['name']);
        $this->assertEquals('Test Brand', $result['brand']);
        $this->assertEquals(100, $result['quantity']);
    }
    
    /**
     * Test: Analytics API response normalization
     */
    public function testAnalyticsAPIResponseNormalization() {
        $response = [
            'product_id' => 12345,
            'sku' => 'SKU-123',
            'product_name' => 'Analytics Product',
            'revenue' => '5000.00',
            'orders_count' => 50
        ];
        
        $result = $this->normalizer->normalizeAPIResponse($response, 'analytics');
        
        $this->assertEquals('12345', $result['analytics_product_id']);
        $this->assertEquals('SKU-123', $result['sku_ozon']);
        $this->assertEquals('Analytics Product', $result['name']);
        $this->assertEquals(5000.00, $result['revenue']);
        $this->assertEquals(50, $result['orders_count']);
    }
    
    /**
     * Test: Inventory API response normalization
     */
    public function testInventoryAPIResponseNormalization() {
        $response = [
            'product_id' => 12345,
            'sku' => 'SKU-123',
            'quantity_present' => 100,
            'quantity_reserved' => 20,
            'warehouse_id' => 'WH-001'
        ];
        
        $result = $this->normalizer->normalizeAPIResponse($response, 'inventory');
        
        $this->assertEquals('12345', $result['inventory_product_id']);
        $this->assertEquals('SKU-123', $result['sku_ozon']);
        $this->assertEquals(100, $result['quantity_present']);
        $this->assertEquals(20, $result['quantity_reserved']);
        $this->assertEquals('WH-001', $result['warehouse_id']);
    }
    
    /**
     * Test: Product normalization with all fields
     */
    public function testProductNormalizationWithAllFields() {
        $product = [
            'id' => 12345,
            'inventory_product_id' => '67890',
            'ozon_product_id' => '  99999  ',
            'name' => '  Test Product  ',
            'brand' => 'Test Brand',
            'quantity' => '100',
            'price' => '1,234.56',
            'is_active' => '1',
            'created_at' => '2025-10-10'
        ];
        
        $result = $this->normalizer->normalizeProduct($product);
        
        $this->assertEquals('12345', $result['id']);
        $this->assertEquals('67890', $result['inventory_product_id']);
        $this->assertEquals('99999', $result['ozon_product_id']);
        $this->assertEquals('Test Product', $result['name']);
        $this->assertEquals('Test Brand', $result['brand']);
        $this->assertEquals(100, $result['quantity']);
        $this->assertEquals(1234.56, $result['price']);
        $this->assertTrue($result['is_active']);
        $this->assertEquals('2025-10-10 00:00:00', $result['created_at']);
    }
    
    /**
     * Test: Data validation - valid data
     */
    public function testDataValidationValid() {
        $validData = [
            'inventory_product_id' => '12345',
            'name' => 'Valid Product',
            'quantity' => 100,
            'price' => 1234.56
        ];
        
        $result = $this->normalizer->validateNormalizedData($validData);
        
        $this->assertTrue($result['valid']);
        $this->assertEmpty($result['errors']);
    }
    
    /**
     * Test: Data validation - missing required fields
     */
    public function testDataValidationMissingRequiredFields() {
        $invalidData = [
            'name' => 'Product without ID'
        ];
        
        $result = $this->normalizer->validateNormalizedData($invalidData);
        
        $this->assertFalse($result['valid']);
        $this->assertNotEmpty($result['errors']);
        $this->assertStringContainsString('inventory_product_id', $result['errors'][0]);
    }
    
    /**
     * Test: Data validation - invalid ID format
     */
    public function testDataValidationInvalidIdFormat() {
        $invalidData = [
            'inventory_product_id' => 'abc123',
            'name' => 'Product'
        ];
        
        $result = $this->normalizer->validateNormalizedData($invalidData);
        
        $this->assertFalse($result['valid']);
        $this->assertNotEmpty($result['errors']);
    }
    
    /**
     * Test: Data validation - string too long
     */
    public function testDataValidationStringTooLong() {
        $invalidData = [
            'inventory_product_id' => '12345',
            'name' => str_repeat('A', 501) // Exceeds 500 char limit
        ];
        
        $result = $this->normalizer->validateNormalizedData($invalidData);
        
        $this->assertFalse($result['valid']);
        $this->assertNotEmpty($result['errors']);
        $this->assertStringContainsString('exceeds maximum length', $result['errors'][0]);
    }
    
    /**
     * Test: Data validation - invalid numeric field
     */
    public function testDataValidationInvalidNumericField() {
        $invalidData = [
            'inventory_product_id' => '12345',
            'quantity' => 'not-a-number'
        ];
        
        $result = $this->normalizer->validateNormalizedData($invalidData);
        
        $this->assertFalse($result['valid']);
        $this->assertNotEmpty($result['errors']);
        $this->assertStringContainsString('must be numeric', $result['errors'][0]);
    }
    
    /**
     * Test: Handling of special characters in IDs
     */
    public function testSpecialCharactersInIds() {
        $testCases = [
            ['123-456', '123-456'],
            ['123_456', '123_456'],
            ['123.456', null], // Dots not allowed
            ['123/456', null], // Slashes not allowed
            ['123 456', '123456'], // Spaces trimmed
        ];
        
        foreach ($testCases as [$input, $expected]) {
            $result = $this->normalizer->normalizeId($input);
            $this->assertEquals($expected, $result, "Failed for input: {$input}");
        }
    }
    
    /**
     * Test: Large number handling
     */
    public function testLargeNumberHandling() {
        $largeNumbers = [
            999999999,
            '999999999',
            9999999999999,
            '9999999999999'
        ];
        
        foreach ($largeNumbers as $number) {
            $result = $this->normalizer->normalizeId($number);
            $this->assertIsString($result);
            $this->assertEquals((string)$number, $result);
        }
    }
    
    /**
     * Test: Unicode string handling
     */
    public function testUnicodeStringHandling() {
        $unicodeStrings = [
            'Товар Ozon ID 12345',
            'Смесь для выпечки ЭТОНОВО',
            '产品名称',
            'Продукт 日本語'
        ];
        
        foreach ($unicodeStrings as $string) {
            $result = $this->normalizer->normalizeValue($string, 'name');
            $this->assertEquals($string, $result);
            $this->assertIsString($result);
        }
    }
}
