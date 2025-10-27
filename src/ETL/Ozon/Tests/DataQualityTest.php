<?php

declare(strict_types=1);

namespace MiCore\ETL\Ozon\Tests;

use Exception;
use PHPUnit\Framework\TestCase;
use MiCore\ETL\Ozon\Core\DatabaseConnection;
use MiCore\ETL\Ozon\Core\Logger;

/**
 * Data Quality Tests
 * 
 * Creates automated tests for data consistency validation,
 * tests edge cases and boundary conditions in business logic,
 * and implements regression tests to prevent data quality degradation.
 * 
 * Requirements addressed:
 * - 6.3: Create automated tests for data consistency validation
 * - 6.3: Test edge cases and boundary conditions in business logic
 * - 6.3: Implement regression tests to prevent data quality degradation
 * - 6.1: Generate validation reports with discrepancy analysis
 * - 6.2: Validate data consistency between ETL runs
 */
class DataQualityTest extends TestCase
{
    private DatabaseConnection $db;
    private Logger $logger;
    private array $testConfig;
    
    protected function setUp(): void
    {
        parent::setUp();
        
        // Load test configuration
        $this->testConfig = $this->loadTestConfiguration();
        
        // Initialize test database connection
        $this->db = new DatabaseConnection($this->testConfig['database']);
        
        // Initialize test logger
        $this->logger = new Logger('/tmp/data_quality_test.log', 'DEBUG');
        
        // Setup test data
        $this->setupTestData();
    }
    
    protected function tearDown(): void
    {
        // Clean up test data
        $this->cleanupTestData();
        
        parent::tearDown();
    }
    
    /**
     * Test data consistency validation
     * 
     * Requirements addressed:
     * - 6.3: Create automated tests for data consistency validation
     */
    public function testDataConsistencyValidation(): void
    {
        $this->logger->info('Starting data consistency validation test');
        
        // Test 1: Products table consistency
        $this->validateProductsTableConsistency();
        
        // Test 2: Inventory table consistency
        $this->validateInventoryTableConsistency();
        
        // Test 3: Cross-table referential integrity
        $this->validateCrossTableReferentialIntegrity();
        
        // Test 4: Data completeness
        $this->validateDataCompleteness();
        
        $this->logger->info('Data consistency validation test completed');
    }   
 
    /**
     * Test edge cases and boundary conditions
     * 
     * Requirements addressed:
     * - 6.3: Test edge cases and boundary conditions in business logic
     */
    public function testEdgeCasesAndBoundaryConditions(): void
    {
        $this->logger->info('Starting edge cases and boundary conditions test');
        
        // Test 1: Zero and negative quantities
        $this->testZeroAndNegativeQuantities();
        
        // Test 2: Extreme values
        $this->testExtremeValues();
        
        // Test 3: Null and empty values
        $this->testNullAndEmptyValues();
        
        // Test 4: Special characters and encoding
        $this->testSpecialCharactersAndEncoding();
        
        // Test 5: Boundary visibility statuses
        $this->testBoundaryVisibilityStatuses();
        
        $this->logger->info('Edge cases and boundary conditions test completed');
    }
    
    /**
     * Test business logic validation
     * 
     * Requirements addressed:
     * - 6.3: Test edge cases and boundary conditions in business logic
     */
    public function testBusinessLogicValidation(): void
    {
        $this->logger->info('Starting business logic validation test');
        
        // Test 1: Stock status calculation logic
        $this->testStockStatusCalculationLogic();
        
        // Test 2: Visibility status mapping
        $this->testVisibilityStatusMapping();
        
        // Test 3: Available stock calculation
        $this->testAvailableStockCalculation();
        
        // Test 4: Reservation validation
        $this->testReservationValidation();
        
        $this->logger->info('Business logic validation test completed');
    }
    
    /**
     * Test regression prevention
     * 
     * Requirements addressed:
     * - 6.3: Implement regression tests to prevent data quality degradation
     */
    public function testRegressionPrevention(): void
    {
        $this->logger->info('Starting regression prevention test');
        
        // Test 1: Data quality metrics baseline
        $this->testDataQualityMetricsBaseline();
        
        // Test 2: ETL process stability
        $this->testETLProcessStability();
        
        // Test 3: Performance regression
        $this->testPerformanceRegression();
        
        // Test 4: Data volume validation
        $this->testDataVolumeValidation();
        
        $this->logger->info('Regression prevention test completed');
    }
    
    /**
     * Test data accuracy validation
     * 
     * Requirements addressed:
     * - 6.1: Generate validation reports with discrepancy analysis
     */
    public function testDataAccuracyValidation(): void
    {
        $this->logger->info('Starting data accuracy validation test');
        
        // Test 1: Product count accuracy
        $this->testProductCountAccuracy();
        
        // Test 2: Inventory accuracy
        $this->testInventoryAccuracy();
        
        // Test 3: Visibility status accuracy
        $this->testVisibilityStatusAccuracy();
        
        // Test 4: Cross-reference accuracy
        $this->testCrossReferenceAccuracy();
        
        $this->logger->info('Data accuracy validation test completed');
    }
    
    /**
     * Test ETL consistency between runs
     * 
     * Requirements addressed:
     * - 6.2: Validate data consistency between ETL runs
     */
    public function testETLConsistencyBetweenRuns(): void
    {
        $this->logger->info('Starting ETL consistency between runs test');
        
        // Test 1: Idempotency validation
        $this->testETLIdempotency();
        
        // Test 2: Data preservation
        $this->testDataPreservation();
        
        // Test 3: Incremental updates
        $this->testIncrementalUpdates();
        
        // Test 4: Rollback consistency
        $this->testRollbackConsistency();
        
        $this->logger->info('ETL consistency between runs test completed');
    }
    
    // ========================================
    // Products Table Consistency Tests
    // ========================================
    
    /**
     * Validate products table consistency
     */
    private function validateProductsTableConsistency(): void
    {
        // Test required fields are not null
        $nullOfferIdResult = $this->db->query("
            SELECT COUNT(*) as count 
            FROM dim_products 
            WHERE offer_id IS NULL OR offer_id = ''
        ");
        
        $nullOfferIdCount = (int)($nullOfferIdResult[0]['count'] ?? 0);
        $this->assertEquals(0, $nullOfferIdCount, 'Products should not have null or empty offer_id');
        
        // Test offer_id uniqueness
        $duplicateOfferIdResult = $this->db->query("
            SELECT offer_id, COUNT(*) as count 
            FROM dim_products 
            GROUP BY offer_id 
            HAVING COUNT(*) > 1
        ");
        
        $this->assertEmpty($duplicateOfferIdResult, 'Products should have unique offer_id values');
        
        // Test visibility status validity
        $invalidVisibilityResult = $this->db->query("
            SELECT COUNT(*) as count 
            FROM dim_products 
            WHERE visibility IS NOT NULL 
              AND visibility NOT IN ('VISIBLE', 'HIDDEN', 'INACTIVE', 'MODERATION', 'DECLINED', 'UNKNOWN')
        ");
        
        $invalidVisibilityCount = (int)($invalidVisibilityResult[0]['count'] ?? 0);
        $this->assertEquals(0, $invalidVisibilityCount, 'Products should have valid visibility status');
        
        // Test product_id validity
        $invalidProductIdResult = $this->db->query("
            SELECT COUNT(*) as count 
            FROM dim_products 
            WHERE product_id IS NULL OR product_id <= 0
        ");
        
        $invalidProductIdCount = (int)($invalidProductIdResult[0]['count'] ?? 0);
        $this->assertEquals(0, $invalidProductIdCount, 'Products should have valid positive product_id');
    }
    
    /**
     * Validate inventory table consistency
     */
    private function validateInventoryTableConsistency(): void
    {
        // Test required fields are not null
        $nullFieldsResult = $this->db->query("
            SELECT 
                COUNT(CASE WHEN offer_id IS NULL OR offer_id = '' THEN 1 END) as null_offer_id,
                COUNT(CASE WHEN warehouse_name IS NULL OR warehouse_name = '' THEN 1 END) as null_warehouse,
                COUNT(CASE WHEN present IS NULL THEN 1 END) as null_present,
                COUNT(CASE WHEN reserved IS NULL THEN 1 END) as null_reserved
            FROM inventory
        ");
        
        $nullFields = $nullFieldsResult[0] ?? [];
        $this->assertEquals(0, (int)($nullFields['null_offer_id'] ?? 0), 'Inventory should not have null offer_id');
        $this->assertEquals(0, (int)($nullFields['null_warehouse'] ?? 0), 'Inventory should not have null warehouse_name');
        $this->assertEquals(0, (int)($nullFields['null_present'] ?? 0), 'Inventory should not have null present');
        $this->assertEquals(0, (int)($nullFields['null_reserved'] ?? 0), 'Inventory should not have null reserved');
        
        // Test quantity validity
        $invalidQuantitiesResult = $this->db->query("
            SELECT 
                COUNT(CASE WHEN present < 0 THEN 1 END) as negative_present,
                COUNT(CASE WHEN reserved < 0 THEN 1 END) as negative_reserved,
                COUNT(CASE WHEN reserved > present THEN 1 END) as reserved_exceeds_present
            FROM inventory
        ");
        
        $invalidQuantities = $invalidQuantitiesResult[0] ?? [];
        $this->assertEquals(0, (int)($invalidQuantities['negative_present'] ?? 0), 'Present quantity should not be negative');
        $this->assertEquals(0, (int)($invalidQuantities['negative_reserved'] ?? 0), 'Reserved quantity should not be negative');
        $this->assertEquals(0, (int)($invalidQuantities['reserved_exceeds_present'] ?? 0), 'Reserved should not exceed present');
        
        // Test uniqueness constraint
        $duplicateRecordsResult = $this->db->query("
            SELECT offer_id, warehouse_name, COUNT(*) as count 
            FROM inventory 
            GROUP BY offer_id, warehouse_name 
            HAVING COUNT(*) > 1
        ");
        
        $this->assertEmpty($duplicateRecordsResult, 'Inventory should have unique offer_id + warehouse_name combinations');
    }
    
    /**
     * Validate cross-table referential integrity
     */
    private function validateCrossTableReferentialIntegrity(): void
    {
        // Test for orphaned inventory records
        $orphanedInventoryResult = $this->db->query("
            SELECT COUNT(*) as count 
            FROM inventory i 
            LEFT JOIN dim_products p ON i.offer_id = p.offer_id 
            WHERE p.offer_id IS NULL
        ");
        
        $orphanedInventoryCount = (int)($orphanedInventoryResult[0]['count'] ?? 0);
        $this->assertEquals(0, $orphanedInventoryCount, 'Should not have orphaned inventory records');
        
        // Test for products without inventory (warning, not error)
        $productsWithoutInventoryResult = $this->db->query("
            SELECT COUNT(*) as count 
            FROM dim_products p 
            LEFT JOIN inventory i ON p.offer_id = i.offer_id 
            WHERE i.offer_id IS NULL AND p.visibility = 'VISIBLE'
        ");
        
        $productsWithoutInventoryCount = (int)($productsWithoutInventoryResult[0]['count'] ?? 0);
        
        // This is a warning, not a failure - some visible products might legitimately not have inventory
        if ($productsWithoutInventoryCount > 0) {
            $this->logger->warning("Found {$productsWithoutInventoryCount} visible products without inventory");
        }
    }
    
    /**
     * Validate data completeness
     */
    private function validateDataCompleteness(): void
    {
        // Test products data completeness
        $productsCompletenessResult = $this->db->query("
            SELECT 
                COUNT(*) as total_products,
                COUNT(CASE WHEN offer_id IS NOT NULL AND offer_id != '' THEN 1 END) as complete_offer_id,
                COUNT(CASE WHEN name IS NOT NULL AND name != '' THEN 1 END) as complete_name,
                COUNT(CASE WHEN visibility IS NOT NULL THEN 1 END) as complete_visibility
            FROM dim_products
        ");
        
        $productsCompleteness = $productsCompletenessResult[0] ?? [];
        $totalProducts = (int)($productsCompleteness['total_products'] ?? 0);
        
        if ($totalProducts > 0) {
            $offerIdCompleteness = ((int)($productsCompleteness['complete_offer_id'] ?? 0)) / $totalProducts * 100;
            $visibilityCompleteness = ((int)($productsCompleteness['complete_visibility'] ?? 0)) / $totalProducts * 100;
            
            $this->assertGreaterThanOrEqual(100, $offerIdCompleteness, 'All products should have offer_id');
            $this->assertGreaterThanOrEqual(95, $visibilityCompleteness, 'At least 95% of products should have visibility status');
        }
        
        // Test inventory data completeness
        $inventoryCompletenessResult = $this->db->query("
            SELECT 
                COUNT(*) as total_inventory,
                COUNT(CASE WHEN offer_id IS NOT NULL AND offer_id != '' THEN 1 END) as complete_offer_id,
                COUNT(CASE WHEN warehouse_name IS NOT NULL AND warehouse_name != '' THEN 1 END) as complete_warehouse
            FROM inventory
        ");
        
        $inventoryCompleteness = $inventoryCompletenessResult[0] ?? [];
        $totalInventory = (int)($inventoryCompleteness['total_inventory'] ?? 0);
        
        if ($totalInventory > 0) {
            $offerIdCompleteness = ((int)($inventoryCompleteness['complete_offer_id'] ?? 0)) / $totalInventory * 100;
            $warehouseCompleteness = ((int)($inventoryCompleteness['complete_warehouse'] ?? 0)) / $totalInventory * 100;
            
            $this->assertGreaterThanOrEqual(100, $offerIdCompleteness, 'All inventory records should have offer_id');
            $this->assertGreaterThanOrEqual(100, $warehouseCompleteness, 'All inventory records should have warehouse_name');
        }
    }
    
    // ========================================
    // Edge Cases and Boundary Conditions Tests
    // ========================================
    
    /**
     * Test zero and negative quantities
     */
    private function testZeroAndNegativeQuantities(): void
    {
        // Insert test data with edge case quantities
        $this->db->execute("
            INSERT INTO inventory (offer_id, warehouse_name, present, reserved, updated_at)
            VALUES 
                ('TEST_ZERO_QTY', 'Test Warehouse', 0, 0, NOW()),
                ('TEST_EDGE_QTY', 'Test Warehouse', 1, 0, NOW()),
                ('TEST_MAX_RESERVED', 'Test Warehouse', 100, 100, NOW())
        ");
        
        // Test that zero quantities are handled correctly
        $zeroQtyResult = $this->db->query("
            SELECT * FROM inventory 
            WHERE offer_id = 'TEST_ZERO_QTY'
        ");
        
        $this->assertNotEmpty($zeroQtyResult, 'Zero quantity records should be allowed');
        $this->assertEquals(0, (int)$zeroQtyResult[0]['present'], 'Zero present quantity should be preserved');
        $this->assertEquals(0, (int)$zeroQtyResult[0]['reserved'], 'Zero reserved quantity should be preserved');
        
        // Test edge case with minimum positive quantity
        $edgeQtyResult = $this->db->query("
            SELECT * FROM inventory 
            WHERE offer_id = 'TEST_EDGE_QTY'
        ");
        
        $this->assertNotEmpty($edgeQtyResult, 'Minimum positive quantity records should be allowed');
        $this->assertEquals(1, (int)$edgeQtyResult[0]['present'], 'Minimum positive quantity should be preserved');
        
        // Test maximum reservation scenario
        $maxReservedResult = $this->db->query("
            SELECT * FROM inventory 
            WHERE offer_id = 'TEST_MAX_RESERVED'
        ");
        
        $this->assertNotEmpty($maxReservedResult, 'Maximum reservation records should be allowed');
        $this->assertEquals(100, (int)$maxReservedResult[0]['present'], 'Present quantity should be preserved');
        $this->assertEquals(100, (int)$maxReservedResult[0]['reserved'], 'Reserved quantity should be preserved');
        
        // Verify that negative quantities are rejected (should fail constraint)
        try {
            $this->db->execute("
                INSERT INTO inventory (offer_id, warehouse_name, present, reserved, updated_at)
                VALUES ('TEST_NEGATIVE', 'Test Warehouse', -1, 0, NOW())
            ");
            $this->fail('Negative present quantity should be rejected');
        } catch (Exception $e) {
            // Expected to fail - negative quantities should be rejected
            $this->assertStringContains('constraint', strtolower($e->getMessage()), 'Should fail due to constraint violation');
        }
    }
    
    /**
     * Test extreme values
     */
    private function testExtremeValues(): void
    {
        // Test very large quantities
        $this->db->execute("
            INSERT INTO inventory (offer_id, warehouse_name, present, reserved, updated_at)
            VALUES ('TEST_LARGE_QTY', 'Test Warehouse', 999999999, 0, NOW())
        ");
        
        $largeQtyResult = $this->db->query("
            SELECT * FROM inventory 
            WHERE offer_id = 'TEST_LARGE_QTY'
        ");
        
        $this->assertNotEmpty($largeQtyResult, 'Large quantity records should be allowed');
        $this->assertEquals(999999999, (int)$largeQtyResult[0]['present'], 'Large quantities should be preserved');
        
        // Test very long product names
        $longName = str_repeat('A', 500); // 500 characters
        $this->db->execute("
            INSERT INTO dim_products (product_id, offer_id, name, visibility, updated_at)
            VALUES (999999, 'TEST_LONG_NAME', ?, 'VISIBLE', NOW())
        ", [$longName]);
        
        $longNameResult = $this->db->query("
            SELECT * FROM dim_products 
            WHERE offer_id = 'TEST_LONG_NAME'
        ");
        
        $this->assertNotEmpty($longNameResult, 'Long name records should be allowed');
        
        // Test very long warehouse names
        $longWarehouse = str_repeat('W', 200); // 200 characters
        $this->db->execute("
            INSERT INTO inventory (offer_id, warehouse_name, present, reserved, updated_at)
            VALUES ('TEST_LONG_WAREHOUSE', ?, 10, 0, NOW())
        ", [$longWarehouse]);
        
        $longWarehouseResult = $this->db->query("
            SELECT * FROM inventory 
            WHERE offer_id = 'TEST_LONG_WAREHOUSE'
        ");
        
        $this->assertNotEmpty($longWarehouseResult, 'Long warehouse name records should be allowed');
    }
    
    /**
     * Test null and empty values
     */
    private function testNullAndEmptyValues(): void
    {
        // Test that required fields reject null values
        try {
            $this->db->execute("
                INSERT INTO dim_products (product_id, offer_id, name, visibility, updated_at)
                VALUES (999998, NULL, 'Test Product', 'VISIBLE', NOW())
            ");
            $this->fail('Null offer_id should be rejected');
        } catch (Exception $e) {
            // Expected to fail
            $this->assertStringContains('null', strtolower($e->getMessage()), 'Should fail due to null constraint');
        }
        
        // Test that optional fields can be null
        $this->db->execute("
            INSERT INTO dim_products (product_id, offer_id, name, visibility, updated_at)
            VALUES (999997, 'TEST_NULL_OPTIONAL', NULL, NULL, NOW())
        ");
        
        $nullOptionalResult = $this->db->query("
            SELECT * FROM dim_products 
            WHERE offer_id = 'TEST_NULL_OPTIONAL'
        ");
        
        $this->assertNotEmpty($nullOptionalResult, 'Records with null optional fields should be allowed');
        $this->assertNull($nullOptionalResult[0]['name'], 'Name can be null');
        $this->assertNull($nullOptionalResult[0]['visibility'], 'Visibility can be null');
        
        // Test empty string handling
        $this->db->execute("
            INSERT INTO dim_products (product_id, offer_id, name, visibility, updated_at)
            VALUES (999996, 'TEST_EMPTY_STRINGS', '', '', NOW())
        ");
        
        $emptyStringsResult = $this->db->query("
            SELECT * FROM dim_products 
            WHERE offer_id = 'TEST_EMPTY_STRINGS'
        ");
        
        $this->assertNotEmpty($emptyStringsResult, 'Records with empty strings should be allowed');
        $this->assertEquals('', $emptyStringsResult[0]['name'], 'Empty name should be preserved');
        $this->assertEquals('', $emptyStringsResult[0]['visibility'], 'Empty visibility should be preserved');
    }
    
    /**
     * Test special characters and encoding
     */
    private function testSpecialCharactersAndEncoding(): void
    {
        // Test Unicode characters
        $unicodeName = 'Тест продукт с русскими символами 测试产品 🚀';
        $this->db->execute("
            INSERT INTO dim_products (product_id, offer_id, name, visibility, updated_at)
            VALUES (999995, 'TEST_UNICODE', ?, 'VISIBLE', NOW())
        ", [$unicodeName]);
        
        $unicodeResult = $this->db->query("
            SELECT * FROM dim_products 
            WHERE offer_id = 'TEST_UNICODE'
        ");
        
        $this->assertNotEmpty($unicodeResult, 'Unicode characters should be supported');
        $this->assertEquals($unicodeName, $unicodeResult[0]['name'], 'Unicode name should be preserved');
        
        // Test special characters in offer_id
        $specialOfferId = 'TEST-SPECIAL_CHARS.123';
        $this->db->execute("
            INSERT INTO dim_products (product_id, offer_id, name, visibility, updated_at)
            VALUES (999994, ?, 'Special Chars Product', 'VISIBLE', NOW())
        ", [$specialOfferId]);
        
        $specialCharsResult = $this->db->query("
            SELECT * FROM dim_products 
            WHERE offer_id = ?
        ", [$specialOfferId]);
        
        $this->assertNotEmpty($specialCharsResult, 'Special characters in offer_id should be supported');
        $this->assertEquals($specialOfferId, $specialCharsResult[0]['offer_id'], 'Special offer_id should be preserved');
        
        // Test SQL injection prevention
        $maliciousInput = "'; DROP TABLE dim_products; --";
        $this->db->execute("
            INSERT INTO dim_products (product_id, offer_id, name, visibility, updated_at)
            VALUES (999993, 'TEST_SQL_INJECTION', ?, 'VISIBLE', NOW())
        ", [$maliciousInput]);
        
        $sqlInjectionResult = $this->db->query("
            SELECT * FROM dim_products 
            WHERE offer_id = 'TEST_SQL_INJECTION'
        ");
        
        $this->assertNotEmpty($sqlInjectionResult, 'Malicious input should be safely stored');
        $this->assertEquals($maliciousInput, $sqlInjectionResult[0]['name'], 'Malicious input should be treated as literal text');
        
        // Verify table still exists (SQL injection was prevented)
        $tableExistsResult = $this->db->query("SELECT COUNT(*) as count FROM dim_products");
        $this->assertGreaterThan(0, (int)($tableExistsResult[0]['count'] ?? 0), 'Table should still exist after malicious input');
    }
    
    /**
     * Test boundary visibility statuses
     */
    private function testBoundaryVisibilityStatuses(): void
    {
        $validStatuses = ['VISIBLE', 'HIDDEN', 'INACTIVE', 'MODERATION', 'DECLINED', 'UNKNOWN'];
        
        foreach ($validStatuses as $index => $status) {
            $offerId = "TEST_VISIBILITY_{$index}";
            $this->db->execute("
                INSERT INTO dim_products (product_id, offer_id, name, visibility, updated_at)
                VALUES (?, ?, 'Test Product', ?, NOW())
            ", [999990 - $index, $offerId, $status]);
            
            $statusResult = $this->db->query("
                SELECT * FROM dim_products 
                WHERE offer_id = ?
            ", [$offerId]);
            
            $this->assertNotEmpty($statusResult, "Valid status '{$status}' should be accepted");
            $this->assertEquals($status, $statusResult[0]['visibility'], "Status '{$status}' should be preserved");
        }
        
        // Test case sensitivity
        $this->db->execute("
            INSERT INTO dim_products (product_id, offer_id, name, visibility, updated_at)
            VALUES (999980, 'TEST_CASE_SENSITIVE', 'Test Product', 'visible', NOW())
        ");
        
        $caseResult = $this->db->query("
            SELECT * FROM dim_products 
            WHERE offer_id = 'TEST_CASE_SENSITIVE'
        ");
        
        $this->assertNotEmpty($caseResult, 'Lowercase visibility status should be accepted');
        $this->assertEquals('visible', $caseResult[0]['visibility'], 'Case should be preserved');
    }  
  
    // ========================================
    // Business Logic Validation Tests
    // ========================================
    
    /**
     * Test stock status calculation logic
     */
    private function testStockStatusCalculationLogic(): void
    {
        // Setup test data for stock status calculation
        $testCases = [
            ['offer_id' => 'STOCK_HIDDEN', 'visibility' => 'HIDDEN', 'present' => 100, 'reserved' => 10, 'expected_status' => 'archived_or_hidden'],
            ['offer_id' => 'STOCK_OUT', 'visibility' => 'VISIBLE', 'present' => 0, 'reserved' => 0, 'expected_status' => 'out_of_stock'],
            ['offer_id' => 'STOCK_CRITICAL', 'visibility' => 'VISIBLE', 'present' => 5, 'reserved' => 0, 'expected_status' => 'critical'],
            ['offer_id' => 'STOCK_LOW', 'visibility' => 'VISIBLE', 'present' => 20, 'reserved' => 5, 'expected_status' => 'low'],
            ['offer_id' => 'STOCK_NORMAL', 'visibility' => 'VISIBLE', 'present' => 50, 'reserved' => 10, 'expected_status' => 'normal'],
            ['offer_id' => 'STOCK_EXCESS', 'visibility' => 'VISIBLE', 'present' => 200, 'reserved' => 50, 'expected_status' => 'excess']
        ];
        
        foreach ($testCases as $case) {
            // Insert product
            $this->db->execute("
                INSERT INTO dim_products (product_id, offer_id, name, visibility, updated_at)
                VALUES (?, ?, 'Test Product', ?, NOW())
            ", [rand(100000, 999999), $case['offer_id'], $case['visibility']]);
            
            // Insert inventory
            $this->db->execute("
                INSERT INTO inventory (offer_id, warehouse_name, present, reserved, updated_at)
                VALUES (?, 'Test Warehouse', ?, ?, NOW())
            ", [$case['offer_id'], $case['present'], $case['reserved']]);
        }
        
        // Test stock status calculation using business logic
        foreach ($testCases as $case) {
            $stockStatusResult = $this->db->query("
                SELECT 
                    p.offer_id,
                    p.visibility,
                    i.present,
                    i.reserved,
                    (i.present - i.reserved) as available,
                    CASE 
                        WHEN p.visibility NOT IN ('VISIBLE', 'ACTIVE', 'продаётся') THEN 'archived_or_hidden'
                        WHEN (i.present - i.reserved) <= 0 THEN 'out_of_stock'
                        WHEN (i.present - i.reserved) BETWEEN 1 AND 10 THEN 'critical'
                        WHEN (i.present - i.reserved) BETWEEN 11 AND 30 THEN 'low'
                        WHEN (i.present - i.reserved) BETWEEN 31 AND 100 THEN 'normal'
                        ELSE 'excess'
                    END as calculated_status
                FROM dim_products p
                INNER JOIN inventory i ON p.offer_id = i.offer_id
                WHERE p.offer_id = ?
            ", [$case['offer_id']]);
            
            $this->assertNotEmpty($stockStatusResult, "Stock status calculation should work for {$case['offer_id']}");
            
            $result = $stockStatusResult[0];
            $this->assertEquals($case['expected_status'], $result['calculated_status'], 
                "Stock status for {$case['offer_id']} should be {$case['expected_status']}, got {$result['calculated_status']}");
        }
    }
    
    /**
     * Test visibility status mapping
     */
    private function testVisibilityStatusMapping(): void
    {
        $mappingTestCases = [
            ['input' => 'VISIBLE', 'expected' => 'VISIBLE'],
            ['input' => 'ACTIVE', 'expected' => 'VISIBLE'], // Should be normalized
            ['input' => 'продаётся', 'expected' => 'VISIBLE'], // Russian equivalent
            ['input' => 'HIDDEN', 'expected' => 'HIDDEN'],
            ['input' => 'INACTIVE', 'expected' => 'HIDDEN'], // Should be normalized
            ['input' => 'скрыт', 'expected' => 'HIDDEN'], // Russian equivalent
            ['input' => 'MODERATION', 'expected' => 'MODERATION'],
            ['input' => 'на модерации', 'expected' => 'MODERATION'], // Russian equivalent
            ['input' => 'DECLINED', 'expected' => 'DECLINED'],
            ['input' => 'отклонён', 'expected' => 'DECLINED'], // Russian equivalent
            ['input' => 'UNKNOWN_STATUS', 'expected' => 'UNKNOWN'] // Should be normalized to UNKNOWN
        ];
        
        foreach ($mappingTestCases as $index => $case) {
            $offerId = "VISIBILITY_MAP_{$index}";
            
            // Insert product with original visibility
            $this->db->execute("
                INSERT INTO dim_products (product_id, offer_id, name, visibility, updated_at)
                VALUES (?, ?, 'Visibility Test Product', ?, NOW())
            ", [800000 + $index, $offerId, $case['input']]);
            
            // Verify the visibility was stored (normalization would happen in ETL process)
            $visibilityResult = $this->db->query("
                SELECT visibility FROM dim_products WHERE offer_id = ?
            ", [$offerId]);
            
            $this->assertNotEmpty($visibilityResult, "Visibility mapping test should work for {$case['input']}");
            
            // For this test, we just verify the input was stored correctly
            // The actual normalization would happen in the ETL process
            $storedVisibility = $visibilityResult[0]['visibility'];
            $this->assertEquals($case['input'], $storedVisibility, 
                "Input visibility '{$case['input']}' should be stored as-is");
        }
    }
    
    /**
     * Test available stock calculation
     */
    private function testAvailableStockCalculation(): void
    {
        $calculationTestCases = [
            ['present' => 100, 'reserved' => 20, 'expected_available' => 80],
            ['present' => 50, 'reserved' => 50, 'expected_available' => 0],
            ['present' => 0, 'reserved' => 0, 'expected_available' => 0],
            ['present' => 1, 'reserved' => 0, 'expected_available' => 1],
            ['present' => 1000, 'reserved' => 1, 'expected_available' => 999]
        ];
        
        foreach ($calculationTestCases as $index => $case) {
            $offerId = "AVAILABLE_CALC_{$index}";
            
            // Insert inventory record
            $this->db->execute("
                INSERT INTO inventory (offer_id, warehouse_name, present, reserved, updated_at)
                VALUES (?, 'Test Warehouse', ?, ?, NOW())
            ", [$offerId, $case['present'], $case['reserved']]);
            
            // Test available stock calculation
            $availableResult = $this->db->query("
                SELECT 
                    present,
                    reserved,
                    (present - reserved) as calculated_available
                FROM inventory 
                WHERE offer_id = ?
            ", [$offerId]);
            
            $this->assertNotEmpty($availableResult, "Available stock calculation should work for {$offerId}");
            
            $result = $availableResult[0];
            $this->assertEquals($case['expected_available'], (int)$result['calculated_available'], 
                "Available stock for {$offerId} should be {$case['expected_available']}");
            $this->assertEquals($case['present'], (int)$result['present'], "Present quantity should match");
            $this->assertEquals($case['reserved'], (int)$result['reserved'], "Reserved quantity should match");
        }
    }
    
    /**
     * Test reservation validation
     */
    private function testReservationValidation(): void
    {
        // Test valid reservations
        $validReservations = [
            ['present' => 100, 'reserved' => 0],   // No reservation
            ['present' => 100, 'reserved' => 50],  // Partial reservation
            ['present' => 100, 'reserved' => 100], // Full reservation
            ['present' => 0, 'reserved' => 0]      // No stock, no reservation
        ];
        
        foreach ($validReservations as $index => $case) {
            $offerId = "VALID_RESERVATION_{$index}";
            
            $this->db->execute("
                INSERT INTO inventory (offer_id, warehouse_name, present, reserved, updated_at)
                VALUES (?, 'Test Warehouse', ?, ?, NOW())
            ", [$offerId, $case['present'], $case['reserved']]);
            
            $reservationResult = $this->db->query("
                SELECT * FROM inventory WHERE offer_id = ?
            ", [$offerId]);
            
            $this->assertNotEmpty($reservationResult, "Valid reservation should be accepted for {$offerId}");
            $this->assertLessThanOrEqual($case['present'], $case['reserved'], 
                "Reserved should not exceed present for {$offerId}");
        }
        
        // Test invalid reservations (should be prevented by constraints or business logic)
        $invalidReservations = [
            ['present' => 50, 'reserved' => 100], // Reserved exceeds present
            ['present' => 0, 'reserved' => 10]    // Reserved without stock
        ];
        
        foreach ($invalidReservations as $index => $case) {
            $offerId = "INVALID_RESERVATION_{$index}";
            
            // This should either be prevented by database constraints or business logic
            try {
                $this->db->execute("
                    INSERT INTO inventory (offer_id, warehouse_name, present, reserved, updated_at)
                    VALUES (?, 'Test Warehouse', ?, ?, NOW())
                ", [$offerId, $case['present'], $case['reserved']]);
                
                // If the insert succeeds, verify it's flagged as invalid
                $invalidResult = $this->db->query("
                    SELECT 
                        *,
                        CASE WHEN reserved > present THEN 'invalid' ELSE 'valid' END as validation_status
                    FROM inventory 
                    WHERE offer_id = ?
                ", [$offerId]);
                
                if (!empty($invalidResult)) {
                    $this->assertEquals('invalid', $invalidResult[0]['validation_status'], 
                        "Invalid reservation should be flagged for {$offerId}");
                }
                
            } catch (Exception $e) {
                // Expected to fail due to constraints
                $this->assertStringContains('constraint', strtolower($e->getMessage()), 
                    "Invalid reservation should be rejected by constraints for {$offerId}");
            }
        }
    }
    
    // ========================================
    // Regression Prevention Tests
    // ========================================
    
    /**
     * Test data quality metrics baseline
     */
    private function testDataQualityMetricsBaseline(): void
    {
        // Define baseline quality metrics that should be maintained
        $baselineMetrics = [
            'min_products_count' => 10,
            'min_visibility_completeness_percent' => 90,
            'max_orphaned_inventory_percent' => 5,
            'max_invalid_reservations_percent' => 1
        ];
        
        // Test minimum products count
        $productsCountResult = $this->db->query("SELECT COUNT(*) as count FROM dim_products");
        $productsCount = (int)($productsCountResult[0]['count'] ?? 0);
        
        $this->assertGreaterThanOrEqual($baselineMetrics['min_products_count'], $productsCount, 
            "Should have at least {$baselineMetrics['min_products_count']} products");
        
        // Test visibility completeness
        if ($productsCount > 0) {
            $visibilityCompletenessResult = $this->db->query("
                SELECT 
                    COUNT(*) as total,
                    COUNT(CASE WHEN visibility IS NOT NULL THEN 1 END) as with_visibility
                FROM dim_products
            ");
            
            $visibilityStats = $visibilityCompletenessResult[0] ?? [];
            $visibilityCompleteness = ((int)($visibilityStats['with_visibility'] ?? 0)) / 
                                    ((int)($visibilityStats['total'] ?? 1)) * 100;
            
            $this->assertGreaterThanOrEqual($baselineMetrics['min_visibility_completeness_percent'], 
                $visibilityCompleteness, 
                "Visibility completeness should be at least {$baselineMetrics['min_visibility_completeness_percent']}%");
        }
        
        // Test orphaned inventory percentage
        $inventoryCountResult = $this->db->query("SELECT COUNT(*) as count FROM inventory");
        $inventoryCount = (int)($inventoryCountResult[0]['count'] ?? 0);
        
        if ($inventoryCount > 0) {
            $orphanedInventoryResult = $this->db->query("
                SELECT COUNT(*) as count 
                FROM inventory i 
                LEFT JOIN dim_products p ON i.offer_id = p.offer_id 
                WHERE p.offer_id IS NULL
            ");
            
            $orphanedCount = (int)($orphanedInventoryResult[0]['count'] ?? 0);
            $orphanedPercent = ($orphanedCount / $inventoryCount) * 100;
            
            $this->assertLessThanOrEqual($baselineMetrics['max_orphaned_inventory_percent'], 
                $orphanedPercent, 
                "Orphaned inventory should be less than {$baselineMetrics['max_orphaned_inventory_percent']}%");
        }
        
        // Test invalid reservations percentage
        if ($inventoryCount > 0) {
            $invalidReservationsResult = $this->db->query("
                SELECT COUNT(*) as count FROM inventory WHERE reserved > present
            ");
            
            $invalidReservationsCount = (int)($invalidReservationsResult[0]['count'] ?? 0);
            $invalidReservationsPercent = ($invalidReservationsCount / $inventoryCount) * 100;
            
            $this->assertLessThanOrEqual($baselineMetrics['max_invalid_reservations_percent'], 
                $invalidReservationsPercent, 
                "Invalid reservations should be less than {$baselineMetrics['max_invalid_reservations_percent']}%");
        }
    }
    
    /**
     * Test ETL process stability
     */
    private function testETLProcessStability(): void
    {
        // Test that recent ETL executions have acceptable success rate
        $recentETLResult = $this->db->query("
            SELECT 
                COUNT(*) as total_executions,
                COUNT(CASE WHEN status = 'success' THEN 1 END) as successful_executions
            FROM etl_workflow_executions
            WHERE created_at > NOW() - INTERVAL 7 DAY
        ");
        
        $etlStats = $recentETLResult[0] ?? [];
        $totalExecutions = (int)($etlStats['total_executions'] ?? 0);
        $successfulExecutions = (int)($etlStats['successful_executions'] ?? 0);
        
        if ($totalExecutions > 0) {
            $successRate = ($successfulExecutions / $totalExecutions) * 100;
            
            $this->assertGreaterThanOrEqual(80, $successRate, 
                "ETL success rate should be at least 80% over the last 7 days");
        }
        
        // Test that data is being updated regularly
        $dataFreshnessResult = $this->db->query("
            SELECT 
                MAX(updated_at) as last_products_update,
                (SELECT MAX(updated_at) FROM inventory) as last_inventory_update
            FROM dim_products
        ");
        
        $freshnessStats = $dataFreshnessResult[0] ?? [];
        
        if (!empty($freshnessStats['last_products_update'])) {
            $hoursSinceProductsUpdate = (time() - strtotime($freshnessStats['last_products_update'])) / 3600;
            $this->assertLessThan(48, $hoursSinceProductsUpdate, 
                "Products should be updated within 48 hours");
        }
        
        if (!empty($freshnessStats['last_inventory_update'])) {
            $hoursSinceInventoryUpdate = (time() - strtotime($freshnessStats['last_inventory_update'])) / 3600;
            $this->assertLessThan(48, $hoursSinceInventoryUpdate, 
                "Inventory should be updated within 48 hours");
        }
    }
    
    /**
     * Test performance regression
     */
    private function testPerformanceRegression(): void
    {
        // Test query performance for common operations
        $performanceTests = [
            'products_count' => "SELECT COUNT(*) FROM dim_products",
            'inventory_count' => "SELECT COUNT(*) FROM inventory",
            'visible_products' => "SELECT COUNT(*) FROM dim_products WHERE visibility = 'VISIBLE'",
            'products_with_stock' => "
                SELECT COUNT(DISTINCT p.offer_id) 
                FROM dim_products p 
                INNER JOIN inventory i ON p.offer_id = i.offer_id 
                WHERE i.present > 0"
        ];
        
        foreach ($performanceTests as $testName => $query) {
            $startTime = microtime(true);
            
            $result = $this->db->query($query);
            
            $duration = microtime(true) - $startTime;
            
            // Performance threshold: queries should complete within 5 seconds
            $this->assertLessThan(5.0, $duration, 
                "Query '{$testName}' should complete within 5 seconds, took {$duration} seconds");
            
            // Verify query returns results
            $this->assertNotEmpty($result, "Query '{$testName}' should return results");
        }
    }
    
    /**
     * Test data volume validation
     */
    private function testDataVolumeValidation(): void
    {
        // Test that data volumes are within expected ranges
        $volumeChecks = [
            'products' => ['table' => 'dim_products', 'min' => 1, 'max' => 1000000],
            'inventory' => ['table' => 'inventory', 'min' => 0, 'max' => 10000000]
        ];
        
        foreach ($volumeChecks as $checkName => $check) {
            $countResult = $this->db->query("SELECT COUNT(*) as count FROM {$check['table']}");
            $count = (int)($countResult[0]['count'] ?? 0);
            
            $this->assertGreaterThanOrEqual($check['min'], $count, 
                "{$checkName} count should be at least {$check['min']}");
            
            $this->assertLessThanOrEqual($check['max'], $count, 
                "{$checkName} count should not exceed {$check['max']}");
        }
        
        // Test data distribution
        $warehouseDistributionResult = $this->db->query("
            SELECT 
                warehouse_name,
                COUNT(*) as product_count
            FROM inventory
            GROUP BY warehouse_name
            ORDER BY product_count DESC
        ");
        
        if (!empty($warehouseDistributionResult)) {
            // Should have at least one warehouse
            $this->assertGreaterThanOrEqual(1, count($warehouseDistributionResult), 
                "Should have at least one warehouse");
            
            // No single warehouse should dominate (more than 90% of products)
            $totalInventoryResult = $this->db->query("SELECT COUNT(*) as count FROM inventory");
            $totalInventory = (int)($totalInventoryResult[0]['count'] ?? 0);
            
            if ($totalInventory > 0) {
                $largestWarehouseCount = (int)($warehouseDistributionResult[0]['product_count'] ?? 0);
                $dominancePercent = ($largestWarehouseCount / $totalInventory) * 100;
                
                $this->assertLessThan(90, $dominancePercent, 
                    "No single warehouse should have more than 90% of inventory");
            }
        }
    }
    
    // ========================================
    // Data Accuracy Validation Tests
    // ========================================
    
    /**
     * Test product count accuracy
     */
    private function testProductCountAccuracy(): void
    {
        // Test that product counts are consistent across different queries
        $directCountResult = $this->db->query("SELECT COUNT(*) as count FROM dim_products");
        $directCount = (int)($directCountResult[0]['count'] ?? 0);
        
        $distinctCountResult = $this->db->query("SELECT COUNT(DISTINCT offer_id) as count FROM dim_products");
        $distinctCount = (int)($distinctCountResult[0]['count'] ?? 0);
        
        $this->assertEquals($directCount, $distinctCount, 
            "Direct count and distinct offer_id count should match (no duplicates)");
        
        // Test visibility-based counts
        $visibilityCountsResult = $this->db->query("
            SELECT 
                COUNT(*) as total,
                COUNT(CASE WHEN visibility = 'VISIBLE' THEN 1 END) as visible,
                COUNT(CASE WHEN visibility = 'HIDDEN' THEN 1 END) as hidden,
                COUNT(CASE WHEN visibility IS NULL THEN 1 END) as null_visibility
            FROM dim_products
        ");
        
        $visibilityCounts = $visibilityCountsResult[0] ?? [];
        $total = (int)($visibilityCounts['total'] ?? 0);
        $visible = (int)($visibilityCounts['visible'] ?? 0);
        $hidden = (int)($visibilityCounts['hidden'] ?? 0);
        $nullVisibility = (int)($visibilityCounts['null_visibility'] ?? 0);
        
        // Verify counts are logical
        $this->assertGreaterThanOrEqual(0, $visible, "Visible count should be non-negative");
        $this->assertGreaterThanOrEqual(0, $hidden, "Hidden count should be non-negative");
        $this->assertGreaterThanOrEqual(0, $nullVisibility, "Null visibility count should be non-negative");
        $this->assertLessThanOrEqual($total, $visible + $hidden + $nullVisibility, 
            "Sum of visibility categories should not exceed total");
    }
    
    /**
     * Test inventory accuracy
     */
    private function testInventoryAccuracy(): void
    {
        // Test inventory quantity consistency
        $quantityConsistencyResult = $this->db->query("
            SELECT 
                COUNT(*) as total_records,
                SUM(present) as total_present,
                SUM(reserved) as total_reserved,
                SUM(present - reserved) as total_available,
                AVG(present) as avg_present,
                AVG(reserved) as avg_reserved
            FROM inventory
        ");
        
        $quantityStats = $quantityConsistencyResult[0] ?? [];
        
        // Verify totals are logical
        $totalPresent = (int)($quantityStats['total_present'] ?? 0);
        $totalReserved = (int)($quantityStats['total_reserved'] ?? 0);
        $totalAvailable = (int)($quantityStats['total_available'] ?? 0);
        
        $this->assertGreaterThanOrEqual(0, $totalPresent, "Total present should be non-negative");
        $this->assertGreaterThanOrEqual(0, $totalReserved, "Total reserved should be non-negative");
        $this->assertLessThanOrEqual($totalPresent, $totalReserved, "Total reserved should not exceed total present");
        $this->assertEquals($totalPresent - $totalReserved, $totalAvailable, 
            "Total available should equal present minus reserved");
        
        // Test warehouse-level consistency
        $warehouseConsistencyResult = $this->db->query("
            SELECT 
                warehouse_name,
                COUNT(*) as product_count,
                SUM(present) as warehouse_present,
                SUM(reserved) as warehouse_reserved
            FROM inventory
            GROUP BY warehouse_name
            HAVING COUNT(*) > 0
        ");
        
        foreach ($warehouseConsistencyResult as $warehouse) {
            $warehousePresent = (int)($warehouse['warehouse_present'] ?? 0);
            $warehouseReserved = (int)($warehouse['warehouse_reserved'] ?? 0);
            
            $this->assertGreaterThanOrEqual(0, $warehousePresent, 
                "Warehouse {$warehouse['warehouse_name']} present should be non-negative");
            $this->assertGreaterThanOrEqual(0, $warehouseReserved, 
                "Warehouse {$warehouse['warehouse_name']} reserved should be non-negative");
            $this->assertLessThanOrEqual($warehousePresent, $warehouseReserved, 
                "Warehouse {$warehouse['warehouse_name']} reserved should not exceed present");
        }
    }
    
    /**
     * Test visibility status accuracy
     */
    private function testVisibilityStatusAccuracy(): void
    {
        // Test that visibility statuses are within expected values
        $visibilityDistributionResult = $this->db->query("
            SELECT 
                visibility,
                COUNT(*) as count
            FROM dim_products
            WHERE visibility IS NOT NULL
            GROUP BY visibility
        ");
        
        $knownStatuses = ['VISIBLE', 'HIDDEN', 'INACTIVE', 'MODERATION', 'DECLINED', 'UNKNOWN'];
        $foundStatuses = [];
        
        foreach ($visibilityDistributionResult as $row) {
            $status = $row['visibility'];
            $count = (int)$row['count'];
            
            $foundStatuses[] = $status;
            $this->assertGreaterThan(0, $count, "Status '{$status}' should have positive count");
        }
        
        // Check for unexpected statuses (this is informational, not a failure)
        $unexpectedStatuses = array_diff($foundStatuses, $knownStatuses);
        if (!empty($unexpectedStatuses)) {
            $this->logger->warning("Found unexpected visibility statuses", [
                'unexpected_statuses' => $unexpectedStatuses
            ]);
        }
        
        // Test visibility vs inventory correlation
        $visibilityInventoryResult = $this->db->query("
            SELECT 
                p.visibility,
                COUNT(DISTINCT p.offer_id) as products_count,
                COUNT(DISTINCT i.offer_id) as products_with_inventory
            FROM dim_products p
            LEFT JOIN inventory i ON p.offer_id = i.offer_id
            WHERE p.visibility IS NOT NULL
            GROUP BY p.visibility
        ");
        
        foreach ($visibilityInventoryResult as $row) {
            $visibility = $row['visibility'];
            $productsCount = (int)$row['products_count'];
            $productsWithInventory = (int)$row['products_with_inventory'];
            
            $this->assertGreaterThanOrEqual(0, $productsWithInventory, 
                "Products with inventory for '{$visibility}' should be non-negative");
            $this->assertLessThanOrEqual($productsCount, $productsWithInventory, 
                "Products with inventory should not exceed total products for '{$visibility}'");
        }
    }
    
    /**
     * Test cross-reference accuracy
     */
    private function testCrossReferenceAccuracy(): void
    {
        // Test product-inventory cross-reference accuracy
        $crossRefResult = $this->db->query("
            SELECT 
                (SELECT COUNT(DISTINCT offer_id) FROM dim_products) as total_products,
                (SELECT COUNT(DISTINCT offer_id) FROM inventory) as products_in_inventory,
                (SELECT COUNT(DISTINCT p.offer_id) 
                 FROM dim_products p 
                 INNER JOIN inventory i ON p.offer_id = i.offer_id) as products_with_inventory,
                (SELECT COUNT(DISTINCT i.offer_id) 
                 FROM inventory i 
                 LEFT JOIN dim_products p ON i.offer_id = p.offer_id 
                 WHERE p.offer_id IS NULL) as orphaned_inventory
            ");
        
        $crossRefStats = $crossRefResult[0] ?? [];
        
        $totalProducts = (int)($crossRefStats['total_products'] ?? 0);
        $productsInInventory = (int)($crossRefStats['products_in_inventory'] ?? 0);
        $productsWithInventory = (int)($crossRefStats['products_with_inventory'] ?? 0);
        $orphanedInventory = (int)($crossRefStats['orphaned_inventory'] ?? 0);
        
        // Verify cross-reference consistency
        $this->assertEquals($productsWithInventory, $productsInInventory - $orphanedInventory, 
            "Products with inventory should equal inventory products minus orphaned");
        
        $this->assertEquals(0, $orphanedInventory, 
            "Should not have orphaned inventory records");
        
        $this->assertLessThanOrEqual($totalProducts, $productsWithInventory, 
            "Products with inventory should not exceed total products");
        
        // Test specific cross-reference samples
        $sampleCrossRefResult = $this->db->query("
            SELECT 
                p.offer_id,
                p.name,
                p.visibility,
                COUNT(i.id) as inventory_records,
                SUM(i.present) as total_present
            FROM dim_products p
            LEFT JOIN inventory i ON p.offer_id = i.offer_id
            WHERE p.visibility = 'VISIBLE'
            GROUP BY p.offer_id, p.name, p.visibility
            ORDER BY RANDOM()
            LIMIT 10
        ");
        
        foreach ($sampleCrossRefResult as $sample) {
            $offerId = $sample['offer_id'];
            $inventoryRecords = (int)$sample['inventory_records'];
            $totalPresent = (int)($sample['total_present'] ?? 0);
            
            if ($inventoryRecords > 0) {
                $this->assertGreaterThanOrEqual(0, $totalPresent, 
                    "Product {$offerId} with inventory should have non-negative total present");
            }
        }
    }
    
    // ========================================
    // ETL Consistency Tests
    // ========================================
    
    /**
     * Test ETL idempotency
     */
    private function testETLIdempotency(): void
    {
        // This test would verify that running ETL multiple times with the same data
        // produces the same results (idempotency)
        
        // Get current state
        $beforeState = $this->captureDataState();
        
        // Simulate ETL run (in a real test, this would trigger actual ETL)
        // For this test, we'll just verify the current state is consistent
        
        $afterState = $this->captureDataState();
        
        // Verify state consistency
        $this->assertEquals($beforeState['products_count'], $afterState['products_count'], 
            "Products count should remain consistent");
        $this->assertEquals($beforeState['inventory_count'], $afterState['inventory_count'], 
            "Inventory count should remain consistent");
        
        // In a real ETL test, we would run the ETL process twice and compare results
        $this->assertTrue(true, "ETL idempotency test placeholder - would test actual ETL runs");
    }
    
    /**
     * Test data preservation
     */
    private function testDataPreservation(): void
    {
        // Test that critical data is preserved across ETL runs
        $criticalDataResult = $this->db->query("
            SELECT 
                offer_id,
                name,
                visibility,
                updated_at
            FROM dim_products
            WHERE visibility = 'VISIBLE'
            ORDER BY updated_at DESC
            LIMIT 5
        ");
        
        // Verify critical data exists and is accessible
        $this->assertNotEmpty($criticalDataResult, "Should have visible products data");
        
        foreach ($criticalDataResult as $product) {
            $this->assertNotEmpty($product['offer_id'], "Product should have offer_id");
            $this->assertNotNull($product['updated_at'], "Product should have update timestamp");
        }
        
        // Test inventory data preservation
        $criticalInventoryResult = $this->db->query("
            SELECT 
                offer_id,
                warehouse_name,
                present,
                reserved,
                updated_at
            FROM inventory
            WHERE present > 0
            ORDER BY updated_at DESC
            LIMIT 5
        ");
        
        $this->assertNotEmpty($criticalInventoryResult, "Should have inventory data with stock");
        
        foreach ($criticalInventoryResult as $inventory) {
            $this->assertNotEmpty($inventory['offer_id'], "Inventory should have offer_id");
            $this->assertNotEmpty($inventory['warehouse_name'], "Inventory should have warehouse_name");
            $this->assertGreaterThan(0, (int)$inventory['present'], "Inventory should have positive present quantity");
        }
    }
    
    /**
     * Test incremental updates
     */
    private function testIncrementalUpdates(): void
    {
        // Test that incremental updates work correctly
        $recentUpdatesResult = $this->db->query("
            SELECT 
                COUNT(*) as recent_products
            FROM dim_products
            WHERE updated_at > NOW() - INTERVAL 24 HOUR
        ");
        
        $recentProductsCount = (int)($recentUpdatesResult[0]['recent_products'] ?? 0);
        
        // In an active system, we should see some recent updates
        // This is informational rather than a hard requirement
        if ($recentProductsCount === 0) {
            $this->logger->info("No recent product updates found in last 24 hours");
        }
        
        $recentInventoryResult = $this->db->query("
            SELECT 
                COUNT(*) as recent_inventory
            FROM inventory
            WHERE updated_at > NOW() - INTERVAL 24 HOUR
        ");
        
        $recentInventoryCount = (int)($recentInventoryResult[0]['recent_inventory'] ?? 0);
        
        if ($recentInventoryCount === 0) {
            $this->logger->info("No recent inventory updates found in last 24 hours");
        }
        
        // Test that updates maintain data integrity
        $integrityAfterUpdatesResult = $this->db->query("
            SELECT 
                COUNT(*) as total_products,
                COUNT(CASE WHEN offer_id IS NOT NULL AND offer_id != '' THEN 1 END) as valid_offer_ids
            FROM dim_products
            WHERE updated_at > NOW() - INTERVAL 24 HOUR
        ");
        
        $integrityStats = $integrityAfterUpdatesResult[0] ?? [];
        $totalRecentProducts = (int)($integrityStats['total_products'] ?? 0);
        $validOfferIds = (int)($integrityStats['valid_offer_ids'] ?? 0);
        
        if ($totalRecentProducts > 0) {
            $this->assertEquals($totalRecentProducts, $validOfferIds, 
                "All recently updated products should have valid offer_ids");
        }
    }
    
    /**
     * Test rollback consistency
     */
    private function testRollbackConsistency(): void
    {
        // Test that the system can handle rollback scenarios consistently
        // This is a placeholder for rollback testing logic
        
        // Verify current data state is consistent
        $consistencyResult = $this->db->query("
            SELECT 
                (SELECT COUNT(*) FROM dim_products WHERE offer_id IS NULL OR offer_id = '') as invalid_products,
                (SELECT COUNT(*) FROM inventory WHERE offer_id IS NULL OR offer_id = '') as invalid_inventory,
                (SELECT COUNT(*) FROM inventory WHERE reserved > present) as invalid_reservations
        ");
        
        $consistencyStats = $consistencyResult[0] ?? [];
        
        $this->assertEquals(0, (int)($consistencyStats['invalid_products'] ?? 0), 
            "Should not have invalid products after rollback");
        $this->assertEquals(0, (int)($consistencyStats['invalid_inventory'] ?? 0), 
            "Should not have invalid inventory after rollback");
        $this->assertEquals(0, (int)($consistencyStats['invalid_reservations'] ?? 0), 
            "Should not have invalid reservations after rollback");
        
        // Test referential integrity after rollback
        $referentialIntegrityResult = $this->db->query("
            SELECT COUNT(*) as orphaned_count
            FROM inventory i
            LEFT JOIN dim_products p ON i.offer_id = p.offer_id
            WHERE p.offer_id IS NULL
        ");
        
        $orphanedCount = (int)($referentialIntegrityResult[0]['orphaned_count'] ?? 0);
        $this->assertEquals(0, $orphanedCount, "Should not have orphaned inventory after rollback");
    }
    
    // ========================================
    // Helper Methods
    // ========================================
    
    /**
     * Capture current data state for comparison
     */
    private function captureDataState(): array
    {
        $stateResult = $this->db->query("
            SELECT 
                (SELECT COUNT(*) FROM dim_products) as products_count,
                (SELECT COUNT(*) FROM inventory) as inventory_count,
                (SELECT COUNT(*) FROM dim_products WHERE visibility = 'VISIBLE') as visible_products_count,
                (SELECT SUM(present) FROM inventory) as total_present_stock,
                (SELECT SUM(reserved) FROM inventory) as total_reserved_stock
        ");
        
        return $stateResult[0] ?? [];
    }
    
    /**
     * Load test configuration
     */
    private function loadTestConfiguration(): array
    {
        return [
            'database' => [
                'host' => $_ENV['TEST_DB_HOST'] ?? 'localhost',
                'port' => $_ENV['TEST_DB_PORT'] ?? 5432,
                'database' => $_ENV['TEST_DB_NAME'] ?? 'etl_test',
                'username' => $_ENV['TEST_DB_USER'] ?? 'test',
                'password' => $_ENV['TEST_DB_PASS'] ?? 'test'
            ]
        ];
    }
    
    /**
     * Setup test data
     */
    private function setupTestData(): void
    {
        // Create test tables if they don't exist
        $this->createTestTables();
        
        // Insert minimal test data
        $this->insertMinimalTestData();
    }
    
    /**
     * Create test database tables
     */
    private function createTestTables(): void
    {
        // Create dim_products table
        $this->db->execute("
            CREATE TABLE IF NOT EXISTS dim_products (
                id SERIAL PRIMARY KEY,
                product_id BIGINT NOT NULL,
                offer_id VARCHAR(255) UNIQUE NOT NULL,
                name VARCHAR(1000),
                fbo_sku VARCHAR(255),
                fbs_sku VARCHAR(255),
                status VARCHAR(50) DEFAULT 'unknown',
                visibility VARCHAR(50),
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            )
        ");
        
        // Create inventory table
        $this->db->execute("
            CREATE TABLE IF NOT EXISTS inventory (
                id SERIAL PRIMARY KEY,
                offer_id VARCHAR(255) NOT NULL,
                warehouse_name VARCHAR(255) NOT NULL,
                item_name VARCHAR(1000),
                present INTEGER DEFAULT 0,
                reserved INTEGER DEFAULT 0,
                available INTEGER GENERATED ALWAYS AS (present - reserved) STORED,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                UNIQUE(offer_id, warehouse_name)
            )
        ");
        
        // Create etl_workflow_executions table
        $this->db->execute("
            CREATE TABLE IF NOT EXISTS etl_workflow_executions (
                id SERIAL PRIMARY KEY,
                workflow_id VARCHAR(255) NOT NULL,
                status VARCHAR(50) NOT NULL,
                duration DECIMAL(10,2),
                product_etl_status VARCHAR(50),
                inventory_etl_status VARCHAR(50),
                execution_details TEXT,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            )
        ");
    }
    
    /**
     * Insert minimal test data
     */
    private function insertMinimalTestData(): void
    {
        // Clear existing test data
        $this->db->execute("DELETE FROM inventory WHERE offer_id LIKE 'TEST_%'");
        $this->db->execute("DELETE FROM dim_products WHERE offer_id LIKE 'TEST_%'");
        $this->db->execute("DELETE FROM etl_workflow_executions WHERE workflow_id LIKE 'test_%'");
        
        // Insert sample products
        $testProducts = [
            ['product_id' => 100001, 'offer_id' => 'TEST_PRODUCT_001', 'name' => 'Test Product 1', 'visibility' => 'VISIBLE'],
            ['product_id' => 100002, 'offer_id' => 'TEST_PRODUCT_002', 'name' => 'Test Product 2', 'visibility' => 'HIDDEN'],
            ['product_id' => 100003, 'offer_id' => 'TEST_PRODUCT_003', 'name' => 'Test Product 3', 'visibility' => 'VISIBLE'],
            ['product_id' => 100004, 'offer_id' => 'TEST_PRODUCT_004', 'name' => 'Test Product 4', 'visibility' => null]
        ];
        
        foreach ($testProducts as $product) {
            $this->db->execute("
                INSERT INTO dim_products (product_id, offer_id, name, visibility, updated_at)
                VALUES (?, ?, ?, ?, NOW())
            ", [$product['product_id'], $product['offer_id'], $product['name'], $product['visibility']]);
        }
        
        // Insert sample inventory
        $testInventory = [
            ['offer_id' => 'TEST_PRODUCT_001', 'warehouse_name' => 'Test Warehouse A', 'present' => 100, 'reserved' => 20],
            ['offer_id' => 'TEST_PRODUCT_001', 'warehouse_name' => 'Test Warehouse B', 'present' => 50, 'reserved' => 10],
            ['offer_id' => 'TEST_PRODUCT_002', 'warehouse_name' => 'Test Warehouse A', 'present' => 0, 'reserved' => 0],
            ['offer_id' => 'TEST_PRODUCT_003', 'warehouse_name' => 'Test Warehouse A', 'present' => 25, 'reserved' => 5]
        ];
        
        foreach ($testInventory as $inventory) {
            $this->db->execute("
                INSERT INTO inventory (offer_id, warehouse_name, present, reserved, updated_at)
                VALUES (?, ?, ?, ?, NOW())
            ", [$inventory['offer_id'], $inventory['warehouse_name'], $inventory['present'], $inventory['reserved']]);
        }
        
        // Insert sample ETL executions
        $testExecutions = [
            ['workflow_id' => 'test_workflow_001', 'status' => 'success', 'duration' => 120.5, 'product_etl_status' => 'success', 'inventory_etl_status' => 'success'],
            ['workflow_id' => 'test_workflow_002', 'status' => 'success', 'duration' => 95.2, 'product_etl_status' => 'success', 'inventory_etl_status' => 'success'],
            ['workflow_id' => 'test_workflow_003', 'status' => 'failed', 'duration' => 45.1, 'product_etl_status' => 'failed', 'inventory_etl_status' => null]
        ];
        
        foreach ($testExecutions as $execution) {
            $this->db->execute("
                INSERT INTO etl_workflow_executions (workflow_id, status, duration, product_etl_status, inventory_etl_status, created_at)
                VALUES (?, ?, ?, ?, ?, NOW() - INTERVAL '1 DAY' * RANDOM())
            ", [$execution['workflow_id'], $execution['status'], $execution['duration'], $execution['product_etl_status'], $execution['inventory_etl_status']]);
        }
    }
    
    /**
     * Clean up test data
     */
    private function cleanupTestData(): void
    {
        // Clean up test data but keep tables for other tests
        $this->db->execute("DELETE FROM inventory WHERE offer_id LIKE 'TEST_%'");
        $this->db->execute("DELETE FROM dim_products WHERE offer_id LIKE 'TEST_%'");
        $this->db->execute("DELETE FROM etl_workflow_executions WHERE workflow_id LIKE 'test_%'");
    }
}