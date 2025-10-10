<?php
/**
 * Unit Tests for DataTypeNormalizer
 * 
 * Tests normalization of different data types, validation,
 * and API response handling.
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../src/DataTypeNormalizer.php';

class DataTypeNormalizerTest extends TestCase {
    private $normalizer;
    
    protected function setUp(): void {
        $this->normalizer = new DataTypeNormalizer();
    }
    
    protected function tearDown(): void {
        $this->normalizer = null;
    }
    
    /**
     * Test: Normalize integer ID to string
     */
    public function testNormalizeIntegerIdToString() {
        $result = $this->normalizer->normalizeId(12345);
        
        $this->assertIsString($result);
        $this->assertEquals('12345', $result);
    }
    
    /**
     * Test: Normalize string ID
     */
    public function testNormalizeStringId() {
        $result = $this->normalizer->normalizeId('67890');
        
        $this->assertIsString($result);
        $this->assertEquals('67890', $result);
    }
    
    /**
     * Test: Normalize ID with whitespace
     */
    public function testNormalizeIdWithWhitespace() {
        $result = $this->normalizer->normalizeId('  12345  ');
        
        $this->assertEquals('12345', $result);
    }
    
    /**
     * Test: Normalize null ID
     */
    public function testNormalizeNullId() {
        $result = $this->normalizer->normalizeId(null);
        
        $this->assertNull($result);
    }
    
    /**
     * Test: Normalize empty string ID
     */
    public function testNormalizeEmptyStringId() {
        $result = $this->normalizer->normalizeId('');
        
        $this->assertNull($result);
    }
    
    /**
     * Test: Normalize zero ID
     */
    public function testNormalizeZeroId() {
        $result = $this->normalizer->normalizeId(0);
        
        $this->assertNull($result);
    }
    
    /**
     * Test: Normalize product with all fields
     */
    public function testNormalizeProductWithAllFields() {
        $product = [
            'id' => 12345,
            'inventory_product_id' => '67890',
            'name' => '  Test Product  ',
            'quantity' => '100',
            'price' => '1,234.56',
            'created_at' => '2025-10-10 12:00:00'
        ];
        
        $result = $this->normalizer->normalizeProduct($product);
        
        $this->assertEquals('12345', $result['id']);
        $this->assertEquals('67890', $result['inventory_product_id']);
        $this->assertEquals('Test Product', $result['name']);
        $this->assertEquals(100, $result['quantity']);
        $this->assertEquals(1234.56, $result['price']);
        $this->assertEquals('2025-10-10 12:00:00', $result['created_at']);
    }
    
    /**
     * Test: Normalize string value
     */
    public function testNormalizeStringValue() {
        $result = $this->normalizer->normalizeValue('  Test   String  ', 'name');
        
        $this->assertEquals('Test String', $result);
    }
    
    /**
     * Test: Normalize numeric value from string
     */
    public function testNormalizeNumericValueFromString() {
        $result = $this->normalizer->normalizeValue('123.45', 'price');
        
        $this->assertEquals(123.45, $result);
    }
    
    /**
     * Test: Normalize numeric value with comma
     */
    public function testNormalizeNumericValueWithComma() {
        $result = $this->normalizer->normalizeValue('1,234.56', 'price');
        
        $this->assertEquals(1234.56, $result);
    }
    
    /**
     * Test: Normalize integer value
     */
    public function testNormalizeIntegerValue() {
        $result = $this->normalizer->normalizeValue('100', 'quantity');
        
        $this->assertIsInt($result);
        $this->assertEquals(100, $result);
    }
    
    /**
     * Test: Normalize datetime value
     */
    public function testNormalizeDateTimeValue() {
        $result = $this->normalizer->normalizeValue('2025-10-10', 'created_at');
        
        $this->assertEquals('2025-10-10 00:00:00', $result);
    }
    
    /**
     * Test: Normalize invalid datetime
     */
    public function testNormalizeInvalidDateTime() {
        $result = $this->normalizer->normalizeValue('invalid-date', 'created_at');
        
        $this->assertNull($result);
    }
    
    /**
     * Test: Normalize boolean value from string
     */
    public function testNormalizeBooleanValueFromString() {
        $this->assertTrue($this->normalizer->normalizeValue('true', 'is_active'));
        $this->assertTrue($this->normalizer->normalizeValue('yes', 'is_active'));
        $this->assertTrue($this->normalizer->normalizeValue('1', 'is_active'));
        $this->assertFalse($this->normalizer->normalizeValue('false', 'is_active'));
        $this->assertFalse($this->normalizer->normalizeValue('no', 'is_active'));
        $this->assertFalse($this->normalizer->normalizeValue('0', 'is_active'));
    }
    
    /**
     * Test: Validate product ID - valid cases
     */
    public function testValidateProductIdValid() {
        $this->assertTrue($this->normalizer->isValidProductId('12345'));
        $this->assertTrue($this->normalizer->isValidProductId(12345));
        $this->assertTrue($this->normalizer->isValidProductId('123-456'));
    }
    
    /**
     * Test: Validate product ID - invalid cases
     */
    public function testValidateProductIdInvalid() {
        $this->assertFalse($this->normalizer->isValidProductId(null));
        $this->assertFalse($this->normalizer->isValidProductId(''));
        $this->assertFalse($this->normalizer->isValidProductId('abc123'));
        $this->assertFalse($this->normalizer->isValidProductId('12.34'));
    }
    
    /**
     * Test: Compare IDs with same values
     */
    public function testCompareIdsWithSameValues() {
        $this->assertTrue($this->normalizer->compareIds('12345', '12345'));
        $this->assertTrue($this->normalizer->compareIds(12345, '12345'));
        $this->assertTrue($this->normalizer->compareIds('12345', 12345));
    }
    
    /**
     * Test: Compare IDs with different values
     */
    public function testCompareIdsWithDifferentValues() {
        $this->assertFalse($this->normalizer->compareIds('12345', '67890'));
        $this->assertFalse($this->normalizer->compareIds(12345, 67890));
    }
    
    /**
     * Test: Compare IDs with null values
     */
    public function testCompareIdsWithNullValues() {
        $this->assertFalse($this->normalizer->compareIds(null, '12345'));
        $this->assertFalse($this->normalizer->compareIds('12345', null));
        $this->assertFalse($this->normalizer->compareIds(null, null));
    }
    
    /**
     * Test: Normalize Ozon API response
     */
    public function testNormalizeOzonAPIResponse() {
        $ozonData = [
            'product_id' => 12345,
            'offer_id' => 'SKU-123',
            'name' => 'Test Product',
            'price' => '1234.56',
            'stocks' => [
                ['present' => 50],
                ['present' => 30]
            ]
        ];
        
        $result = $this->normalizer->normalizeAPIResponse($ozonData, 'ozon');
        
        $this->assertEquals('12345', $result['ozon_product_id']);
        $this->assertEquals('SKU-123', $result['sku_ozon']);
        $this->assertEquals('Test Product', $result['name']);
        $this->assertEquals(80, $result['quantity']); // 50 + 30
    }
    
    /**
     * Test: Normalize Wildberries API response
     */
    public function testNormalizeWildberriesAPIResponse() {
        $wbData = [
            'nmId' => 12345,
            'vendorCode' => 'WB-SKU-123',
            'title' => 'Test Product',
            'brand' => 'Test Brand',
            'quantity' => 100
        ];
        
        $result = $this->normalizer->normalizeAPIResponse($wbData, 'wb');
        
        $this->assertEquals('12345', $result['wb_product_id']);
        $this->assertEquals('WB-SKU-123', $result['sku_wb']);
        $this->assertEquals('Test Product', $result['name']);
        $this->assertEquals('Test Brand', $result['brand']);
        $this->assertEquals(100, $result['quantity']);
    }
    
    /**
     * Test: Normalize Analytics API response
     */
    public function testNormalizeAnalyticsAPIResponse() {
        $analyticsData = [
            'product_id' => 12345,
            'sku' => 'SKU-123',
            'product_name' => 'Test Product',
            'revenue' => '5000.00',
            'orders_count' => 50
        ];
        
        $result = $this->normalizer->normalizeAPIResponse($analyticsData, 'analytics');
        
        $this->assertEquals('12345', $result['analytics_product_id']);
        $this->assertEquals('SKU-123', $result['sku_ozon']);
        $this->assertEquals('Test Product', $result['name']);
        $this->assertEquals(5000.00, $result['revenue']);
        $this->assertEquals(50, $result['orders_count']);
    }
    
    /**
     * Test: Normalize Inventory API response
     */
    public function testNormalizeInventoryAPIResponse() {
        $inventoryData = [
            'product_id' => 12345,
            'sku' => 'SKU-123',
            'quantity_present' => 100,
            'quantity_reserved' => 20,
            'warehouse_id' => 'WH-001'
        ];
        
        $result = $this->normalizer->normalizeAPIResponse($inventoryData, 'inventory');
        
        $this->assertEquals('12345', $result['inventory_product_id']);
        $this->assertEquals('SKU-123', $result['sku_ozon']);
        $this->assertEquals(100, $result['quantity_present']);
        $this->assertEquals(20, $result['quantity_reserved']);
        $this->assertEquals('WH-001', $result['warehouse_id']);
    }
    
    /**
     * Test: Validate normalized data - valid
     */
    public function testValidateNormalizedDataValid() {
        $data = [
            'inventory_product_id' => '12345',
            'name' => 'Test Product',
            'quantity' => 100,
            'price' => 1234.56
        ];
        
        $result = $this->normalizer->validateNormalizedData($data);
        
        $this->assertTrue($result['valid']);
        $this->assertEmpty($result['errors']);
    }
    
    /**
     * Test: Validate normalized data - missing required field
     */
    public function testValidateNormalizedDataMissingRequiredField() {
        $data = [
            'name' => 'Test Product',
            'quantity' => 100
        ];
        
        $result = $this->normalizer->validateNormalizedData($data);
        
        $this->assertFalse($result['valid']);
        $this->assertNotEmpty($result['errors']);
        $this->assertStringContainsString('inventory_product_id', $result['errors'][0]);
    }
    
    /**
     * Test: Validate normalized data - invalid ID format
     */
    public function testValidateNormalizedDataInvalidIdFormat() {
        $data = [
            'inventory_product_id' => 'abc123',
            'name' => 'Test Product'
        ];
        
        $result = $this->normalizer->validateNormalizedData($data);
        
        $this->assertFalse($result['valid']);
        $this->assertNotEmpty($result['errors']);
    }
    
    /**
     * Test: Validate normalized data - string too long
     */
    public function testValidateNormalizedDataStringTooLong() {
        $data = [
            'inventory_product_id' => '12345',
            'name' => str_repeat('A', 501) // Exceeds 500 char limit
        ];
        
        $result = $this->normalizer->validateNormalizedData($data);
        
        $this->assertFalse($result['valid']);
        $this->assertNotEmpty($result['errors']);
        $this->assertStringContainsString('exceeds maximum length', $result['errors'][0]);
    }
    
    /**
     * Test: Validate normalized data - invalid numeric field
     */
    public function testValidateNormalizedDataInvalidNumericField() {
        $data = [
            'inventory_product_id' => '12345',
            'quantity' => 'not-a-number'
        ];
        
        $result = $this->normalizer->validateNormalizedData($data);
        
        $this->assertFalse($result['valid']);
        $this->assertNotEmpty($result['errors']);
        $this->assertStringContainsString('must be numeric', $result['errors'][0]);
    }
    
    /**
     * Test: Create safe JOIN value for VARCHAR
     */
    public function testCreateSafeJoinValueForVarchar() {
        $result = $this->normalizer->createSafeJoinValue(12345, 'VARCHAR');
        
        $this->assertEquals("'12345'", $result);
    }
    
    /**
     * Test: Create safe JOIN value for INT
     */
    public function testCreateSafeJoinValueForInt() {
        $result = $this->normalizer->createSafeJoinValue('12345', 'INT');
        
        $this->assertEquals('12345', $result);
    }
    
    /**
     * Test: Create safe JOIN value for null
     */
    public function testCreateSafeJoinValueForNull() {
        $result = $this->normalizer->createSafeJoinValue(null, 'VARCHAR');
        
        $this->assertEquals('NULL', $result);
    }
    
    /**
     * Test: Get safe comparison SQL
     */
    public function testGetSafeComparisonSQL() {
        $result = $this->normalizer->getSafeComparisonSQL('field1', 'field2');
        
        $this->assertStringContainsString('CAST', $result);
        $this->assertStringContainsString('field1', $result);
        $this->assertStringContainsString('field2', $result);
        $this->assertStringContainsString('=', $result);
    }
    
    /**
     * Test: Normalize empty string to null
     */
    public function testNormalizeEmptyStringToNull() {
        $result = $this->normalizer->normalizeValue('', 'name');
        
        $this->assertNull($result);
    }
    
    /**
     * Test: Normalize whitespace-only string to null
     */
    public function testNormalizeWhitespaceOnlyStringToNull() {
        $result = $this->normalizer->normalizeValue('   ', 'name');
        
        $this->assertNull($result);
    }
    
    /**
     * Test: Normalize product with mixed types
     */
    public function testNormalizeProductWithMixedTypes() {
        $product = [
            'id' => '12345',
            'inventory_product_id' => 67890,
            'ozon_product_id' => '  99999  ',
            'name' => 'Test',
            'quantity' => '100',
            'is_active' => '1'
        ];
        
        $result = $this->normalizer->normalizeProduct($product);
        
        $this->assertEquals('12345', $result['id']);
        $this->assertEquals('67890', $result['inventory_product_id']);
        $this->assertEquals('99999', $result['ozon_product_id']);
        $this->assertEquals('Test', $result['name']);
        $this->assertEquals(100, $result['quantity']);
        $this->assertTrue($result['is_active']);
    }
}
