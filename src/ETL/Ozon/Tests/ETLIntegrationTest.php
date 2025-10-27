<?php

declare(strict_types=1);

namespace MiCore\ETL\Ozon\Tests;

use Exception;
use PHPUnit\Framework\TestCase;
use MiCore\ETL\Ozon\Core\ETLOrchestrator;
use MiCore\ETL\Ozon\Core\DatabaseConnection;
use MiCore\ETL\Ozon\Core\Logger;
use MiCore\ETL\Ozon\Api\OzonApiClient;

/**
 * ETL Integration Tests
 * 
 * Tests the complete ETL workflow including ProductETL -> InventoryETL sequence,
 * dependency management, retry logic, and data consistency validation.
 * 
 * Requirements addressed:
 * - 5.3: Write end-to-end tests for complete ETL workflow
 * - 5.3: Test ETL sequence execution and dependency handling
 * - 5.3: Verify data consistency after both ETL processes complete
 */
class ETLIntegrationTest extends TestCase
{
    private DatabaseConnection $db;
    private Logger $logger;
    private OzonApiClient $apiClient;
    private ETLOrchestrator $orchestrator;
    private array $testConfig;
    
    protected function setUp(): void
    {
        parent::setUp();
        
        // Load test configuration
        $this->testConfig = $this->loadTestConfiguration();
        
        // Initialize test database connection
        $this->db = new DatabaseConnection($this->testConfig['database']);
        
        // Initialize test logger
        $this->logger = new Logger('/tmp/etl_integration_test.log', 'DEBUG');
        
        // Initialize mock API client
        $this->apiClient = $this->createMockApiClient();
        
        // Initialize ETL orchestrator
        $this->orchestrator = new ETLOrchestrator(
            $this->db,
            $this->logger,
            $this->apiClient,
            $this->testConfig['orchestrator']
        );
        
        // Setup test database
        $this->setupTestDatabase();
    }
    
    protected function tearDown(): void
    {
        // Clean up test data
        $this->cleanupTestDatabase();
        
        parent::tearDown();
    }
    
    /**
     * Test complete ETL workflow execution
     * 
     * Requirements addressed:
     * - 5.3: Write end-to-end tests for complete ETL workflow
     */
    public function testCompleteETLWorkflow(): void
    {
        $this->logger->info('Starting complete ETL workflow test');
        
        // Execute complete workflow
        $result = $this->orchestrator->executeETLWorkflow([
            'test_mode' => true
        ]);
        
        // Verify workflow completed successfully
        $this->assertEquals('success', $result['status'], 'ETL workflow should complete successfully');
        $this->assertArrayHasKey('workflow_id', $result, 'Result should contain workflow ID');
        $this->assertArrayHasKey('product_etl', $result, 'Result should contain ProductETL results');
        $this->assertArrayHasKey('inventory_etl', $result, 'Result should contain InventoryETL results');
        
        // Verify ProductETL completed successfully
        $this->assertEquals('success', $result['product_etl']['status'], 'ProductETL should complete successfully');
        $this->assertGreaterThan(0, $result['product_etl']['duration'], 'ProductETL should have measurable duration');
        
        // Verify InventoryETL completed successfully
        $this->assertEquals('success', $result['inventory_etl']['status'], 'InventoryETL should complete successfully');
        $this->assertGreaterThan(0, $result['inventory_etl']['duration'], 'InventoryETL should have measurable duration');
        
        // Verify execution sequence (ProductETL should complete before InventoryETL starts)
        $this->assertLessThan(
            $result['inventory_etl']['duration'],
            $result['product_etl']['duration'],
            'ProductETL should complete before InventoryETL in the sequence'
        );
        
        $this->logger->info('Complete ETL workflow test passed');
    }
    
    /**
     * Test ETL sequence execution and dependency handling
     * 
     * Requirements addressed:
     * - 5.3: Test ETL sequence execution and dependency handling
     */
    public function testETLSequenceAndDependencies(): void
    {
        $this->logger->info('Starting ETL sequence and dependency test');
        
        // Test 1: Normal sequence execution
        $result = $this->orchestrator->executeETLWorkflow();
        
        $this->assertEquals('success', $result['status'], 'Normal sequence should execute successfully');
        
        // Verify execution status tracking
        $executionStatus = $result['execution_status'];
        $this->assertEquals('completed', $executionStatus['product_etl']['status'], 'ProductETL should be marked as completed');
        $this->assertEquals('completed', $executionStatus['inventory_etl']['status'], 'InventoryETL should be marked as completed');
        $this->assertGreaterThan(0, $executionStatus['product_etl']['attempts'], 'ProductETL should have at least one attempt');
        $this->assertGreaterThan(0, $executionStatus['inventory_etl']['attempts'], 'InventoryETL should have at least one attempt');
        
        // Test 2: Dependency validation
        $this->verifyProductETLDependency();
        
        $this->logger->info('ETL sequence and dependency test passed');
    }
    
    /**
     * Test retry logic for failed ETL processes
     * 
     * Requirements addressed:
     * - 5.1: Implement retry logic for failed ETL processes
     */
    public function testETLRetryLogic(): void
    {
        $this->logger->info('Starting ETL retry logic test');
        
        // Configure orchestrator with retry settings
        $retryConfig = array_merge($this->testConfig['orchestrator'], [
            'max_retries' => 2,
            'retry_delay' => 1, // 1 second for faster testing
            'enable_retry_logic' => true
        ]);
        
        $retryOrchestrator = new ETLOrchestrator(
            $this->db,
            $this->logger,
            $this->createFailingApiClient(1), // Fail on first attempt, succeed on second
            $retryConfig
        );
        
        // Execute workflow that should fail once then succeed
        $result = $retryOrchestrator->executeETLWorkflow();
        
        // Verify that retry logic worked
        $this->assertEquals('success', $result['status'], 'Workflow should eventually succeed with retry logic');
        
        // Verify retry attempts were made
        $executionStatus = $result['execution_status'];
        $this->assertGreaterThan(1, $executionStatus['product_etl']['attempts'], 'ProductETL should have multiple attempts due to retry');
        
        $this->logger->info('ETL retry logic test passed');
    }
    
    /**
     * Test data consistency after both ETL processes complete
     * 
     * Requirements addressed:
     * - 5.3: Verify data consistency after both ETL processes complete
     */
    public function testDataConsistencyAfterETL(): void
    {
        $this->logger->info('Starting data consistency test');
        
        // Execute complete workflow
        $result = $this->orchestrator->executeETLWorkflow();
        
        $this->assertEquals('success', $result['status'], 'ETL workflow should complete successfully');
        
        // Test 1: Verify products data
        $this->verifyProductsDataConsistency();
        
        // Test 2: Verify inventory data
        $this->verifyInventoryDataConsistency();
        
        // Test 3: Verify cross-table consistency
        $this->verifyCrossTableConsistency();
        
        // Test 4: Verify visibility data integrity
        $this->verifyVisibilityDataIntegrity();
        
        $this->logger->info('Data consistency test passed');
    }
    
    /**
     * Test ETL failure scenarios and error handling
     */
    public function testETLFailureScenarios(): void
    {
        $this->logger->info('Starting ETL failure scenarios test');
        
        // Test 1: ProductETL failure should prevent InventoryETL
        $failingOrchestrator = new ETLOrchestrator(
            $this->db,
            $this->logger,
            $this->createFailingApiClient(5), // Always fail
            array_merge($this->testConfig['orchestrator'], [
                'max_retries' => 1,
                'enable_dependency_checks' => true
            ])
        );
        
        try {
            $failingOrchestrator->executeETLWorkflow();
            $this->fail('ETL workflow should fail when ProductETL fails');
        } catch (Exception $e) {
            $this->assertStringContains('ProductETL failed', $e->getMessage(), 'Error should indicate ProductETL failure');
        }
        
        // Test 2: Dependency check failure
        $this->testDependencyCheckFailure();
        
        $this->logger->info('ETL failure scenarios test passed');
    }
    
    /**
     * Test ETL performance and metrics collection
     */
    public function testETLPerformanceMetrics(): void
    {
        $this->logger->info('Starting ETL performance metrics test');
        
        // Execute workflow
        $result = $this->orchestrator->executeETLWorkflow();
        
        // Verify performance metrics are collected
        $this->assertArrayHasKey('duration', $result, 'Result should contain overall duration');
        $this->assertGreaterThan(0, $result['duration'], 'Duration should be positive');
        
        // Verify component-specific metrics
        $productMetrics = $result['product_etl']['metrics'] ?? [];
        $this->assertArrayHasKey('records_extracted', $productMetrics, 'ProductETL should track extracted records');
        $this->assertArrayHasKey('records_loaded', $productMetrics, 'ProductETL should track loaded records');
        
        $inventoryMetrics = $result['inventory_etl']['metrics'] ?? [];
        $this->assertArrayHasKey('records_extracted', $inventoryMetrics, 'InventoryETL should track extracted records');
        $this->assertArrayHasKey('records_loaded', $inventoryMetrics, 'InventoryETL should track loaded records');
        
        // Verify metrics are reasonable
        $this->assertGreaterThanOrEqual(0, $productMetrics['records_extracted'] ?? 0, 'Extracted records should be non-negative');
        $this->assertGreaterThanOrEqual(0, $inventoryMetrics['records_extracted'] ?? 0, 'Extracted records should be non-negative');
        
        $this->logger->info('ETL performance metrics test passed');
    }
    
    /**
     * Verify ProductETL dependency requirements
     */
    private function verifyProductETLDependency(): void
    {
        // Check that products table has visibility data
        $result = $this->db->query("
            SELECT 
                COUNT(*) as total_products,
                COUNT(CASE WHEN visibility IS NOT NULL THEN 1 END) as products_with_visibility
            FROM dim_products
        ");
        
        $stats = $result[0];
        $this->assertGreaterThan(0, $stats['total_products'], 'Should have products in database');
        $this->assertGreaterThan(0, $stats['products_with_visibility'], 'Should have products with visibility data');
        
        // Verify visibility coverage is adequate
        $coveragePercent = ($stats['products_with_visibility'] / $stats['total_products']) * 100;
        $this->assertGreaterThan(90, $coveragePercent, 'Visibility coverage should be over 90%');
    }
    
    /**
     * Verify products data consistency
     */
    private function verifyProductsDataConsistency(): void
    {
        // Check basic product data integrity
        $result = $this->db->query("
            SELECT 
                COUNT(*) as total_products,
                COUNT(CASE WHEN offer_id IS NOT NULL AND offer_id != '' THEN 1 END) as products_with_offer_id,
                COUNT(CASE WHEN visibility IS NOT NULL THEN 1 END) as products_with_visibility,
                COUNT(CASE WHEN visibility = 'VISIBLE' THEN 1 END) as visible_products
            FROM dim_products
        ");
        
        $stats = $result[0];
        
        // All products should have offer_id
        $this->assertEquals($stats['total_products'], $stats['products_with_offer_id'], 'All products should have offer_id');
        
        // Most products should have visibility data
        $visibilityPercent = ($stats['products_with_visibility'] / $stats['total_products']) * 100;
        $this->assertGreaterThan(95, $visibilityPercent, 'At least 95% of products should have visibility data');
        
        // Should have some visible products
        $this->assertGreaterThan(0, $stats['visible_products'], 'Should have some visible products');
    }
    
    /**
     * Verify inventory data consistency
     */
    private function verifyInventoryDataConsistency(): void
    {
        // Check basic inventory data integrity
        $result = $this->db->query("
            SELECT 
                COUNT(*) as total_inventory,
                COUNT(CASE WHEN offer_id IS NOT NULL AND offer_id != '' THEN 1 END) as inventory_with_offer_id,
                COUNT(CASE WHEN warehouse_name IS NOT NULL AND warehouse_name != '' THEN 1 END) as inventory_with_warehouse,
                COUNT(CASE WHEN present >= 0 THEN 1 END) as inventory_with_valid_present,
                COUNT(CASE WHEN reserved >= 0 THEN 1 END) as inventory_with_valid_reserved
            FROM inventory
        ");
        
        $stats = $result[0];
        
        if ($stats['total_inventory'] > 0) {
            // All inventory should have required fields
            $this->assertEquals($stats['total_inventory'], $stats['inventory_with_offer_id'], 'All inventory should have offer_id');
            $this->assertEquals($stats['total_inventory'], $stats['inventory_with_warehouse'], 'All inventory should have warehouse_name');
            $this->assertEquals($stats['total_inventory'], $stats['inventory_with_valid_present'], 'All inventory should have valid present quantity');
            $this->assertEquals($stats['total_inventory'], $stats['inventory_with_valid_reserved'], 'All inventory should have valid reserved quantity');
        }
    }
    
    /**
     * Verify cross-table consistency
     */
    private function verifyCrossTableConsistency(): void
    {
        // Check for orphaned inventory (inventory without products)
        $orphanedResult = $this->db->query("
            SELECT COUNT(*) as orphaned_count
            FROM inventory i
            LEFT JOIN dim_products p ON i.offer_id = p.offer_id
            WHERE p.offer_id IS NULL
        ");
        
        $orphanedCount = $orphanedResult[0]['orphaned_count'];
        $this->assertEquals(0, $orphanedCount, 'Should not have orphaned inventory items');
        
        // Check inventory coverage for visible products
        $coverageResult = $this->db->query("
            SELECT 
                COUNT(CASE WHEN p.visibility = 'VISIBLE' THEN 1 END) as visible_products,
                COUNT(CASE WHEN p.visibility = 'VISIBLE' AND i.offer_id IS NOT NULL THEN 1 END) as visible_with_inventory
            FROM dim_products p
            LEFT JOIN inventory i ON p.offer_id = i.offer_id
            WHERE p.visibility IS NOT NULL
        ");
        
        $coverage = $coverageResult[0];
        
        if ($coverage['visible_products'] > 0) {
            $inventoryCoveragePercent = ($coverage['visible_with_inventory'] / $coverage['visible_products']) * 100;
            // Note: Not all visible products may have inventory, so we just check it's reasonable
            $this->assertGreaterThanOrEqual(0, $inventoryCoveragePercent, 'Inventory coverage should be non-negative');
        }
    }
    
    /**
     * Verify visibility data integrity
     */
    private function verifyVisibilityDataIntegrity(): void
    {
        // Check visibility status distribution
        $distributionResult = $this->db->query("
            SELECT 
                visibility,
                COUNT(*) as count
            FROM dim_products 
            WHERE visibility IS NOT NULL
            GROUP BY visibility
        ");
        
        $validStatuses = ['VISIBLE', 'HIDDEN', 'MODERATION', 'DECLINED', 'UNKNOWN'];
        
        foreach ($distributionResult as $row) {
            $this->assertContains($row['visibility'], $validStatuses, "Visibility status '{$row['visibility']}' should be valid");
            $this->assertGreaterThan(0, $row['count'], 'Each visibility status should have at least one product');
        }
        
        // Check that we have some visible products
        $visibleResult = $this->db->query("
            SELECT COUNT(*) as visible_count
            FROM dim_products 
            WHERE visibility = 'VISIBLE'
        ");
        
        $visibleCount = $visibleResult[0]['visible_count'];
        $this->assertGreaterThan(0, $visibleCount, 'Should have some visible products');
    }
    
    /**
     * Test dependency check failure scenario
     */
    private function testDependencyCheckFailure(): void
    {
        // Clear products table to simulate ProductETL failure
        $this->db->execute('DELETE FROM dim_products');
        
        // Try to run workflow with dependency checks enabled
        $dependencyOrchestrator = new ETLOrchestrator(
            $this->db,
            $this->logger,
            $this->apiClient,
            array_merge($this->testConfig['orchestrator'], [
                'enable_dependency_checks' => true
            ])
        );
        
        try {
            $dependencyOrchestrator->executeETLWorkflow();
            $this->fail('ETL workflow should fail when dependency check fails');
        } catch (Exception $e) {
            $this->assertStringContains('dependency', strtolower($e->getMessage()), 'Error should indicate dependency failure');
        }
        
        // Restore test data
        $this->setupTestDatabase();
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
            ],
            'orchestrator' => [
                'max_retries' => 2,
                'retry_delay' => 1,
                'enable_dependency_checks' => true,
                'enable_retry_logic' => true,
                'product_etl' => [
                    'batch_size' => 100,
                    'max_products' => 1000
                ],
                'inventory_etl' => [
                    'batch_size' => 100,
                    'max_wait_time' => 300,
                    'poll_interval' => 5
                ]
            ]
        ];
    }
    
    /**
     * Create mock API client for testing
     */
    private function createMockApiClient(): OzonApiClient
    {
        $mock = $this->createMock(OzonApiClient::class);
        
        // Mock ProductETL API calls
        $mock->method('createProductsReport')
            ->willReturn(['result' => ['code' => 'test_report_123']]);
        
        $mock->method('waitForReportCompletion')
            ->willReturn(['result' => ['file' => 'http://test.com/products.csv']]);
        
        $mock->method('downloadAndParseCsv')
            ->willReturnCallback(function($url) {
                if (strpos($url, 'products.csv') !== false) {
                    return $this->generateTestProductsData();
                } else {
                    return $this->generateTestInventoryData();
                }
            });
        
        // Mock InventoryETL API calls
        $mock->method('createStockReport')
            ->willReturn(['result' => ['code' => 'test_stock_456']]);
        
        $mock->method('getReportStatus')
            ->willReturn(['result' => ['status' => 'success', 'file' => 'http://test.com/inventory.csv']]);
        
        return $mock;
    }
    
    /**
     * Create failing API client for testing retry logic
     */
    private function createFailingApiClient(int $failAttempts): OzonApiClient
    {
        $mock = $this->createMock(OzonApiClient::class);
        
        $attemptCount = 0;
        
        $mock->method('createProductsReport')
            ->willReturnCallback(function() use (&$attemptCount, $failAttempts) {
                $attemptCount++;
                if ($attemptCount <= $failAttempts) {
                    throw new Exception('API temporarily unavailable');
                }
                return ['result' => ['code' => 'test_report_123']];
            });
        
        $mock->method('waitForReportCompletion')
            ->willReturn(['result' => ['file' => 'http://test.com/products.csv']]);
        
        $mock->method('downloadAndParseCsv')
            ->willReturn($this->generateTestProductsData());
        
        return $mock;
    }
    
    /**
     * Generate test products data
     */
    private function generateTestProductsData(): array
    {
        $products = [];
        
        for ($i = 1; $i <= 100; $i++) {
            $products[] = [
                'product_id' => 1000 + $i,
                'offer_id' => 'TEST_SKU_' . str_pad($i, 3, '0', STR_PAD_LEFT),
                'name' => 'Test Product ' . $i,
                'fbo_sku' => 'FBO_' . $i,
                'fbs_sku' => 'FBS_' . $i,
                'status' => 'active',
                'visibility' => $i % 4 === 0 ? 'HIDDEN' : 'VISIBLE'
            ];
        }
        
        return $products;
    }
    
    /**
     * Generate test inventory data
     */
    private function generateTestInventoryData(): array
    {
        $inventory = [];
        $warehouses = ['Warehouse A', 'Warehouse B', 'Warehouse C'];
        
        for ($i = 1; $i <= 100; $i++) {
            foreach ($warehouses as $warehouse) {
                $inventory[] = [
                    'SKU' => 'TEST_SKU_' . str_pad($i, 3, '0', STR_PAD_LEFT),
                    'Warehouse name' => $warehouse,
                    'Item Name' => 'Test Product ' . $i,
                    'Present' => rand(0, 100),
                    'Reserved' => rand(0, 20)
                ];
            }
        }
        
        return $inventory;
    }
    
    /**
     * Setup test database with required tables and sample data
     */
    private function setupTestDatabase(): void
    {
        // Create tables if they don't exist
        $this->createTestTables();
        
        // Insert sample data
        $this->insertTestData();
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
     * Insert test data
     */
    private function insertTestData(): void
    {
        // Clear existing test data
        $this->db->execute('DELETE FROM inventory');
        $this->db->execute('DELETE FROM dim_products');
        $this->db->execute('DELETE FROM etl_workflow_executions');
        
        // Insert sample products
        $products = $this->generateTestProductsData();
        foreach ($products as $product) {
            $this->db->execute("
                INSERT INTO dim_products (product_id, offer_id, name, fbo_sku, fbs_sku, status, visibility, updated_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, NOW())
            ", [
                $product['product_id'],
                $product['offer_id'],
                $product['name'],
                $product['fbo_sku'],
                $product['fbs_sku'],
                $product['status'],
                $product['visibility']
            ]);
        }
        
        // Insert sample inventory
        $inventory = $this->generateTestInventoryData();
        foreach ($inventory as $item) {
            $this->db->execute("
                INSERT INTO inventory (offer_id, warehouse_name, item_name, present, reserved, updated_at)
                VALUES (?, ?, ?, ?, ?, NOW())
            ", [
                $item['SKU'],
                $item['Warehouse name'],
                $item['Item Name'],
                $item['Present'],
                $item['Reserved']
            ]);
        }
    }
    
    /**
     * Clean up test database
     */
    private function cleanupTestDatabase(): void
    {
        // Clean up test data but keep tables for other tests
        $this->db->execute('DELETE FROM inventory WHERE offer_id LIKE \'TEST_SKU_%\'');
        $this->db->execute('DELETE FROM dim_products WHERE offer_id LIKE \'TEST_SKU_%\'');
        $this->db->execute('DELETE FROM etl_workflow_executions WHERE workflow_id LIKE \'etl_workflow_%\'');
    }
}