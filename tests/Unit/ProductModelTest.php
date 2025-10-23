<?php
/**
 * Unit Tests for Product Model
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../src/models/Product.php';

class ProductModelTest extends TestCase {
    public function testProductCreation() {
        $data = [
            'id' => 1,
            'sku' => 'TEST-SKU-001',
            'name' => 'Test Product',
            'current_stock' => 50,
            'available_stock' => 45,
            'reserved_stock' => 5,
            'warehouse_name' => 'Main Warehouse',
            'price' => 99.99,
            'category' => 'Electronics',
            'is_active' => true
        ];
        
        $product = new Product($data);
        
        $this->assertEquals(1, $product->getId());
        $this->assertEquals('TEST-SKU-001', $product->getSku());
        $this->assertEquals('Test Product', $product->getName());
        $this->assertEquals(50, $product->getCurrentStock());
        $this->assertEquals(45, $product->getAvailableStock());
        $this->assertEquals(5, $product->getReservedStock());
        $this->assertEquals('Main Warehouse', $product->getWarehouseName());
        $this->assertEquals(99.99, $product->getPrice());
        $this->assertEquals('Electronics', $product->getCategory());
        $this->assertTrue($product->isActive());
    }
    
    public function testEmptyProductCreation() {
        $product = new Product();
        
        $this->assertNull($product->getId());
        $this->assertEquals('', $product->getSku());
        $this->assertEquals('', $product->getName());
        $this->assertEquals(0, $product->getCurrentStock());
        $this->assertEquals(0, $product->getAvailableStock());
        $this->assertEquals(0, $product->getReservedStock());
        $this->assertTrue($product->isActive());
    }
    
    public function testStockStatusCalculation() {
        // Test critical stock
        $criticalProduct = new Product(['current_stock' => 3]);
        $this->assertEquals(Product::STATUS_CRITICAL, $criticalProduct->getStockStatus());
        $this->assertTrue($criticalProduct->isCritical());
        $this->assertFalse($criticalProduct->isLowStock());
        $this->assertFalse($criticalProduct->isOverstock());
        
        // Test low stock
        $lowStockProduct = new Product(['current_stock' => 15]);
        $this->assertEquals(Product::STATUS_LOW_STOCK, $lowStockProduct->getStockStatus());
        $this->assertFalse($lowStockProduct->isCritical());
        $this->assertTrue($lowStockProduct->isLowStock());
        $this->assertFalse($lowStockProduct->isOverstock());
        
        // Test normal stock
        $normalProduct = new Product(['current_stock' => 50]);
        $this->assertEquals(Product::STATUS_NORMAL, $normalProduct->getStockStatus());
        $this->assertFalse($normalProduct->isCritical());
        $this->assertFalse($normalProduct->isLowStock());
        $this->assertFalse($normalProduct->isOverstock());
        
        // Test overstock
        $overstockProduct = new Product(['current_stock' => 150]);
        $this->assertEquals(Product::STATUS_OVERSTOCK, $overstockProduct->getStockStatus());
        $this->assertFalse($overstockProduct->isCritical());
        $this->assertFalse($overstockProduct->isLowStock());
        $this->assertTrue($overstockProduct->isOverstock());
    }
    
    public function testStockLevelPercentage() {
        // Test critical level (0-25%)
        $criticalProduct = new Product(['current_stock' => 3]);
        $percentage = $criticalProduct->getStockLevelPercentage();
        $this->assertGreaterThanOrEqual(0, $percentage);
        $this->assertLessThanOrEqual(25, $percentage);
        
        // Test low stock level (25-75%)
        $lowStockProduct = new Product(['current_stock' => 15]);
        $percentage = $lowStockProduct->getStockLevelPercentage();
        $this->assertGreaterThan(25, $percentage);
        $this->assertLessThanOrEqual(75, $percentage);
        
        // Test normal level (75-100%)
        $normalProduct = new Product(['current_stock' => 50]);
        $percentage = $normalProduct->getStockLevelPercentage();
        $this->assertGreaterThan(75, $percentage);
        $this->assertLessThanOrEqual(100, $percentage);
        
        // Test overstock (100%)
        $overstockProduct = new Product(['current_stock' => 150]);
        $percentage = $overstockProduct->getStockLevelPercentage();
        $this->assertEquals(100.0, $percentage);
        
        // Test zero stock
        $zeroStockProduct = new Product(['current_stock' => 0]);
        $this->assertEquals(0.0, $zeroStockProduct->getStockLevelPercentage());
    }
    
    public function testValidation() {
        // Test valid product
        $validProduct = new Product([
            'sku' => 'VALID-SKU-001',
            'name' => 'Valid Product',
            'current_stock' => 10,
            'available_stock' => 8,
            'reserved_stock' => 2,
            'price' => 50.00
        ]);
        
        $this->assertTrue($validProduct->isValid());
        $this->assertEmpty($validProduct->validate());
        
        // Test invalid product - missing required fields
        $invalidProduct = new Product([
            'sku' => '',
            'name' => '',
            'current_stock' => -5,
            'price' => -10.00
        ]);
        
        $this->assertFalse($invalidProduct->isValid());
        $errors = $invalidProduct->validate();
        $this->assertNotEmpty($errors);
        $this->assertContains('SKU is required', $errors);
        $this->assertContains('Product name is required', $errors);
        $this->assertContains('Current stock cannot be negative', $errors);
        $this->assertContains('Price cannot be negative', $errors);
        
        // Test invalid SKU format
        $invalidSkuProduct = new Product([
            'sku' => 'INVALID SKU WITH SPACES!',
            'name' => 'Test Product'
        ]);
        
        $errors = $invalidSkuProduct->validate();
        $this->assertContains('SKU must contain only alphanumeric characters, dashes, and underscores', $errors);
    }
    
    public function testUpdateStock() {
        $product = new Product([
            'sku' => 'TEST-SKU-001',
            'name' => 'Test Product',
            'current_stock' => 50,
            'available_stock' => 45,
            'reserved_stock' => 5
        ]);
        
        $product->updateStock(30, 25, 5);
        
        $this->assertEquals(30, $product->getCurrentStock());
        $this->assertEquals(25, $product->getAvailableStock());
        $this->assertEquals(5, $product->getReservedStock());
        $this->assertInstanceOf(DateTime::class, $product->getLastUpdated());
    }
    
    public function testToArray() {
        $data = [
            'id' => 1,
            'sku' => 'TEST-SKU-001',
            'name' => 'Test Product',
            'current_stock' => 50,
            'available_stock' => 45,
            'reserved_stock' => 5,
            'warehouse_name' => 'Main Warehouse',
            'price' => 99.99,
            'category' => 'Electronics',
            'is_active' => true
        ];
        
        $product = new Product($data);
        $array = $product->toArray();
        
        $this->assertIsArray($array);
        $this->assertEquals(1, $array['id']);
        $this->assertEquals('TEST-SKU-001', $array['sku']);
        $this->assertEquals('Test Product', $array['name']);
        $this->assertEquals(50, $array['current_stock']);
        $this->assertEquals(45, $array['available_stock']);
        $this->assertEquals(5, $array['reserved_stock']);
        $this->assertEquals('Main Warehouse', $array['warehouse_name']);
        $this->assertEquals(99.99, $array['price']);
        $this->assertEquals('Electronics', $array['category']);
        $this->assertTrue($array['is_active']);
        
        // Check calculated fields
        $this->assertArrayHasKey('stock_status', $array);
        $this->assertArrayHasKey('stock_level_percentage', $array);
        $this->assertArrayHasKey('is_critical', $array);
        $this->assertArrayHasKey('is_low_stock', $array);
        $this->assertArrayHasKey('is_overstock', $array);
    }
    
    public function testToJson() {
        $product = new Product([
            'id' => 1,
            'sku' => 'TEST-SKU-001',
            'name' => 'Test Product',
            'current_stock' => 50
        ]);
        
        $json = $product->toJson();
        $this->assertIsString($json);
        
        $decoded = json_decode($json, true);
        $this->assertIsArray($decoded);
        $this->assertEquals(1, $decoded['id']);
        $this->assertEquals('TEST-SKU-001', $decoded['sku']);
        $this->assertEquals('Test Product', $decoded['name']);
    }
    
    public function testFromDatabase() {
        $row = [
            'id' => 1,
            'sku' => 'DB-SKU-001',
            'name' => 'Database Product',
            'current_stock' => 25,
            'available_stock' => 20,
            'reserved_stock' => 5,
            'warehouse_name' => 'DB Warehouse',
            'last_updated' => '2023-10-21 10:00:00',
            'price' => 75.50,
            'category' => 'Database Items',
            'is_active' => 1
        ];
        
        $product = Product::fromDatabase($row);
        
        $this->assertInstanceOf(Product::class, $product);
        $this->assertEquals(1, $product->getId());
        $this->assertEquals('DB-SKU-001', $product->getSku());
        $this->assertEquals('Database Product', $product->getName());
        $this->assertEquals(25, $product->getCurrentStock());
        $this->assertTrue($product->isActive());
    }
    
    public function testCollectionFromDatabase() {
        $rows = [
            [
                'id' => 1,
                'sku' => 'SKU-001',
                'name' => 'Product 1',
                'current_stock' => 10
            ],
            [
                'id' => 2,
                'sku' => 'SKU-002',
                'name' => 'Product 2',
                'current_stock' => 20
            ]
        ];
        
        $products = Product::collectionFromDatabase($rows);
        
        $this->assertIsArray($products);
        $this->assertCount(2, $products);
        $this->assertInstanceOf(Product::class, $products[0]);
        $this->assertInstanceOf(Product::class, $products[1]);
        $this->assertEquals('SKU-001', $products[0]->getSku());
        $this->assertEquals('SKU-002', $products[1]->getSku());
    }
    
    public function testDisplayMethods() {
        $product = new Product([
            'sku' => 'DISPLAY-SKU-001',
            'name' => 'Display Product',
            'current_stock' => 15
        ]);
        
        $displayName = $product->getDisplayName();
        $this->assertEquals('Display Product (DISPLAY-SKU-001)', $displayName);
        
        $badgeColor = $product->getStatusBadgeColor();
        $this->assertEquals('orange', $badgeColor); // Low stock = orange
        
        $statusText = $product->getStatusDisplayText();
        $this->assertEquals('Низкий остаток', $statusText);
    }
    
    public function testSetters() {
        $product = new Product();
        
        $product->setId(123);
        $product->setSku('SETTER-SKU');
        $product->setName('Setter Product');
        $product->setWarehouseName('Setter Warehouse');
        $product->setPrice(199.99);
        $product->setCategory('Setter Category');
        $product->setActive(false);
        
        $this->assertEquals(123, $product->getId());
        $this->assertEquals('SETTER-SKU', $product->getSku());
        $this->assertEquals('Setter Product', $product->getName());
        $this->assertEquals('Setter Warehouse', $product->getWarehouseName());
        $this->assertEquals(199.99, $product->getPrice());
        $this->assertEquals('Setter Category', $product->getCategory());
        $this->assertFalse($product->isActive());
    }
}