<?php
/**
 * Unit Tests for CSVReportProcessor Class
 * 
 * Tests CSV parsing, validation, and data transformation functionality
 * for Ozon warehouse stock reports processing.
 */

require_once __DIR__ . '/../../src/classes/CSVReportProcessor.php';

class CSVReportProcessorTest extends PHPUnit\Framework\TestCase {
    
    private $processor;
    private $mockPdo;
    
    protected function setUp(): void {
        // Create mock PDO for testing
        $this->mockPdo = $this->createMock(PDO::class);
        $this->processor = new CSVReportProcessor($this->mockPdo);
    }
    
    /**
     * Test CSV parsing with valid data
     */
    public function testParseWarehouseStockCSVWithValidData(): void {
        $csvContent = "SKU,Warehouse_Name,Current_Stock,Reserved_Stock,Available_Stock,Last_Updated\n" .
                     "1234567890,Хоругвино,150,25,125,2025-10-23 10:30:00\n" .
                     "1234567891,Тверь,89,12,77,2025-10-23 10:30:00";
        
        $result = $this->processor->parseWarehouseStockCSV($csvContent);
        
        $this->assertCount(2, $result);
        $this->assertEquals('1234567890', $result[0]['SKU']);
        $this->assertEquals('Хоругвино', $result[0]['Warehouse_Name']);
        $this->assertEquals(150, $result[0]['Current_Stock']);
        $this->assertEquals(25, $result[0]['Reserved_Stock']);
        $this->assertEquals(125, $result[0]['Available_Stock']);
    }
    
    /**
     * Test CSV parsing with empty content
     */
    public function testParseWarehouseStockCSVWithEmptyContent(): void {
        $this->expectException(Exception::class);
        $this->expectExceptionMessage('CSV content is empty');
        
        $this->processor->parseWarehouseStockCSV('');
    }
    
    /**
     * Test CSV parsing with missing header
     */
    public function testParseWarehouseStockCSVWithMissingHeader(): void {
        $csvContent = "1234567890,Хоругвино,150,25,125,2025-10-23 10:30:00";
        
        $this->expectException(Exception::class);
        $this->expectExceptionMessage('CSV structure validation failed');
        
        $this->processor->parseWarehouseStockCSV($csvContent);
    }
    
    /**
     * Test CSV parsing with malformed rows
     */
    public function testParseWarehouseStockCSVWithMalformedRows(): void {
        $csvContent = "SKU,Warehouse_Name,Current_Stock,Reserved_Stock,Available_Stock,Last_Updated\n" .
                     "1234567890,Хоругвино,150,25,125,2025-10-23 10:30:00\n" .
                     "1234567891,Тверь,89,12\n" . // Missing columns
                     "1234567892,Екатеринбург,75,10,65,2025-10-23 10:30:00";
        
        $result = $this->processor->parseWarehouseStockCSV($csvContent);
        
        // Should skip malformed row and return only valid ones
        $this->assertCount(2, $result);
        $this->assertEquals('1234567890', $result[0]['SKU']);
        $this->assertEquals('1234567892', $result[1]['SKU']);
    }
    
    /**
     * Test CSV structure validation with valid header
     */
    public function testValidateCSVStructureWithValidHeader(): void {
        $csvData = [
            'header' => ['SKU', 'Warehouse_Name', 'Current_Stock', 'Reserved_Stock', 'Available_Stock', 'Last_Updated']
        ];
        
        $result = $this->processor->validateCSVStructure($csvData);
        $this->assertTrue($result);
    }
    
    /**
     * Test CSV structure validation with missing required columns
     */
    public function testValidateCSVStructureWithMissingColumns(): void {
        $csvData = [
            'header' => ['SKU', 'Warehouse_Name', 'Current_Stock'] // Missing required columns
        ];
        
        $result = $this->processor->validateCSVStructure($csvData);
        $this->assertFalse($result);
    }
    
    /**
     * Test warehouse name normalization
     */
    public function testNormalizeWarehouseNames(): void {
        $stockData = [
            ['Warehouse_Name' => 'Хоругвино', 'SKU' => '123'],
            ['Warehouse_Name' => 'Тверь', 'SKU' => '456'],
            ['Warehouse_Name' => 'Unknown_Warehouse', 'SKU' => '789']
        ];
        
        $result = $this->processor->normalizeWarehouseNames($stockData);
        
        $this->assertTrue($result[0]['warehouse_normalized']);
        $this->assertEquals('Московская область', $result[0]['warehouse_region']);
        $this->assertEquals('main', $result[0]['warehouse_type']);
        
        $this->assertTrue($result[1]['warehouse_normalized']);
        $this->assertEquals('Тверская область', $result[1]['warehouse_region']);
        
        $this->assertFalse($result[2]['warehouse_normalized']);
        $this->assertArrayNotHasKey('warehouse_region', $result[2]);
    }
    
    /**
     * Test SKU mapping with mock database
     */
    public function testMapProductSKUs(): void {
        // Mock PDO statement for successful SKU lookup
        $mockStmt = $this->createMock(PDOStatement::class);
        $mockStmt->expects($this->once())
                 ->method('execute')
                 ->with(['123', '456']);
        
        $mockStmt->expects($this->once())
                 ->method('fetchAll')
                 ->with(PDO::FETCH_ASSOC)
                 ->willReturn([
                     ['sku' => '123', 'product_id' => 1, 'product_name' => 'Product 1'],
                     ['sku' => '456', 'product_id' => 2, 'product_name' => 'Product 2']
                 ]);
        
        $this->mockPdo->expects($this->once())
                      ->method('prepare')
                      ->willReturn($mockStmt);
        
        $stockData = [
            ['SKU' => '123', 'Warehouse_Name' => 'Хоругвино'],
            ['SKU' => '456', 'Warehouse_Name' => 'Тверь'],
            ['SKU' => '789', 'Warehouse_Name' => 'Екатеринбург'] // This SKU won't be found
        ];
        
        $result = $this->processor->mapProductSKUs($stockData);
        
        $this->assertEquals(1, $result[0]['product_id']);
        $this->assertEquals('Product 1', $result[0]['product_name']);
        $this->assertTrue($result[0]['sku_mapped']);
        
        $this->assertEquals(2, $result[1]['product_id']);
        $this->assertEquals('Product 2', $result[1]['product_name']);
        $this->assertTrue($result[1]['sku_mapped']);
        
        $this->assertNull($result[2]['product_id']);
        $this->assertFalse($result[2]['sku_mapped']);
    }
    
    /**
     * Test SKU mapping with database error
     */
    public function testMapProductSKUsWithDatabaseError(): void {
        // Mock PDO to throw exception
        $this->mockPdo->expects($this->once())
                      ->method('prepare')
                      ->willThrowException(new PDOException('Database error'));
        
        $stockData = [
            ['SKU' => '123', 'Warehouse_Name' => 'Хоругвино']
        ];
        
        $result = $this->processor->mapProductSKUs($stockData);
        
        // Should handle error gracefully and return unmapped data
        $this->assertNull($result[0]['product_id']);
        $this->assertFalse($result[0]['sku_mapped']);
    }
    
    /**
     * Test warehouse mapping configuration
     */
    public function testGetWarehouseMapping(): void {
        $mapping = $this->processor->getWarehouseMapping();
        
        $this->assertIsArray($mapping);
        $this->assertArrayHasKey('Хоругвино', $mapping);
        $this->assertArrayHasKey('Тверь', $mapping);
        $this->assertEquals('Хоругвино', $mapping['Хоругвино']);
    }
    
    /**
     * Test adding new warehouse mapping
     */
    public function testAddWarehouseMapping(): void {
        $this->processor->addWarehouseMapping(
            'New_Warehouse', 
            'Новый склад',
            ['region' => 'Новая область', 'type' => 'regional']
        );
        
        $mapping = $this->processor->getWarehouseMapping();
        $this->assertArrayHasKey('New_Warehouse', $mapping);
        $this->assertEquals('Новый склад', $mapping['New_Warehouse']);
    }
    
    /**
     * Test validation of unknown warehouses
     */
    public function testValidateUnknownWarehouses(): void {
        $stockData = [
            ['Warehouse_Name' => 'Хоругвино', 'warehouse_normalized' => true],
            ['Warehouse_Name' => 'Unknown1', 'warehouse_normalized' => false],
            ['Warehouse_Name' => 'Тверь', 'warehouse_normalized' => true],
            ['Warehouse_Name' => 'Unknown2', 'warehouse_normalized' => false]
        ];
        
        $unknownWarehouses = $this->processor->validateUnknownWarehouses($stockData);
        
        $this->assertCount(2, $unknownWarehouses);
        $this->assertContains('Unknown1', $unknownWarehouses);
        $this->assertContains('Unknown2', $unknownWarehouses);
    }
    
    /**
     * Test CSV parsing with various date formats
     */
    public function testParseWarehouseStockCSVWithVariousDateFormats(): void {
        $csvContent = "SKU,Warehouse_Name,Current_Stock,Reserved_Stock,Available_Stock,Last_Updated\n" .
                     "123,Хоругвино,150,25,125,2025-10-23 10:30:00\n" .
                     "456,Тверь,89,12,77,2025-10-23T10:30:00\n" .
                     "789,Екатеринбург,75,10,65,invalid_date\n" .
                     "101,Казань,50,5,45,";
        
        $result = $this->processor->parseWarehouseStockCSV($csvContent);
        
        $this->assertCount(4, $result);
        
        // Valid date should be preserved
        $this->assertEquals('2025-10-23 10:30:00', $result[0]['Last_Updated']);
        
        // ISO format should be converted
        $this->assertNotEmpty($result[1]['Last_Updated']);
        
        // Invalid date should be replaced with current timestamp
        $this->assertNotEmpty($result[2]['Last_Updated']);
        
        // Empty date should be replaced with current timestamp
        $this->assertNotEmpty($result[3]['Last_Updated']);
    }
    
    /**
     * Test CSV parsing with negative stock values
     */
    public function testParseWarehouseStockCSVWithNegativeValues(): void {
        $csvContent = "SKU,Warehouse_Name,Current_Stock,Reserved_Stock,Available_Stock,Last_Updated\n" .
                     "123,Хоругвино,-10,25,125,2025-10-23 10:30:00\n" .
                     "456,Тверь,89,-5,77,2025-10-23 10:30:00";
        
        $result = $this->processor->parseWarehouseStockCSV($csvContent);
        
        // Should skip rows with negative values
        $this->assertCount(0, $result);
    }
    
    /**
     * Test CSV parsing with non-numeric SKU
     */
    public function testParseWarehouseStockCSVWithNonNumericSKU(): void {
        $csvContent = "SKU,Warehouse_Name,Current_Stock,Reserved_Stock,Available_Stock,Last_Updated\n" .
                     "ABC123,Хоругвино,150,25,125,2025-10-23 10:30:00\n" .
                     "456,Тверь,89,12,77,2025-10-23 10:30:00";
        
        $result = $this->processor->parseWarehouseStockCSV($csvContent);
        
        // Should skip row with non-numeric SKU
        $this->assertCount(1, $result);
        $this->assertEquals('456', $result[0]['SKU']);
    }
}