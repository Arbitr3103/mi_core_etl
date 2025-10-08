<?php

require_once __DIR__ . '/../../bootstrap.php';
require_once __DIR__ . '/../../../src/MDM/Services/MatchingService.php';
require_once __DIR__ . '/../../../src/MDM/Services/ProductsService.php';
require_once __DIR__ . '/../../../src/MDM/Services/VerificationService.php';
require_once __DIR__ . '/../../../src/ETL/ETLOrchestrator.php';

use PHPUnit\Framework\TestCase;

/**
 * Integration tests for MDM system components
 * Tests the complete workflow from data ingestion to master data creation
 */
class MDMIntegrationTest extends TestCase
{
    private $db;
    private $matchingService;
    private $productsService;
    private $verificationService;
    private $etlOrchestrator;

    protected function setUp(): void
    {
        // Setup test database connection
        $this->db = new PDO(
            "mysql:host=" . $_ENV['DB_HOST'] . ";dbname=" . $_ENV['TEST_DB_NAME'],
            $_ENV['DB_USER'],
            $_ENV['DB_PASS']
        );
        
        $this->matchingService = new MatchingService($this->db);
        $this->productsService = new ProductsService($this->db);
        $this->verificationService = new VerificationService($this->db);
        $this->etlOrchestrator = new ETLOrchestrator($this->db);
        
        // Clean test data
        $this->cleanTestData();
        $this->setupTestData();
    }

    protected function tearDown(): void
    {
        $this->cleanTestData();
    }

    /**
     * Test complete ETL to MDM workflow
     * Requirements: 3.1, 3.3, 7.1, 7.3
     */
    public function testCompleteETLToMDMWorkflow()
    {
        // 1. Simulate data extraction from external source
        $externalData = [
            [
                'external_sku' => 'OZON_123456',
                'source' => 'ozon',
                'name' => 'Смесь для выпечки ЭТОНОВО Пицца 9 порций',
                'brand' => 'ЭТОНОВО',
                'category' => 'Смеси для выпечки'
            ],
            [
                'external_sku' => 'WB_789012',
                'source' => 'wildberries',
                'name' => 'Смесь ЭТОНОВО для пиццы 9 шт',
                'brand' => 'ЭТОНОВО',
                'category' => 'Смеси'
            ]
        ];

        // 2. Process through ETL
        foreach ($externalData as $data) {
            $result = $this->etlOrchestrator->processProduct($data);
            $this->assertTrue($result['success'], 'ETL processing should succeed');
        }

        // 3. Verify automatic matching occurred
        $matches = $this->matchingService->findPotentialMatches('OZON_123456', 'ozon');
        $this->assertNotEmpty($matches, 'Should find potential matches');

        // 4. Verify master product creation
        $masterProducts = $this->productsService->getAllMasterProducts();
        $this->assertGreaterThan(0, count($masterProducts), 'Should create master products');

        // 5. Test SKU mapping
        $mapping = $this->db->query("SELECT * FROM sku_mapping WHERE external_sku = 'OZON_123456'")->fetch();
        $this->assertNotFalse($mapping, 'SKU mapping should exist');
    }

    /**
     * Test automatic matching algorithms
     * Requirements: 3.1, 3.2
     */
    public function testAutomaticMatchingAlgorithms()
    {
        // Create a master product
        $masterId = $this->productsService->createMasterProduct([
            'canonical_name' => 'Смесь для выпечки ЭТОНОВО Пицца',
            'canonical_brand' => 'ЭТОНОВО',
            'canonical_category' => 'Смеси для выпечки'
        ]);

        // Test exact match
        $exactMatch = $this->matchingService->findExactMatch('ЭТОНОВО', 'Смесь для выпечки ЭТОНОВО Пицца');
        $this->assertEquals($masterId, $exactMatch['master_id'] ?? null, 'Exact match should work');

        // Test fuzzy match
        $fuzzyMatches = $this->matchingService->findFuzzyMatches('Смесь ЭТОНОВО для пиццы');
        $this->assertNotEmpty($fuzzyMatches, 'Fuzzy matching should find similar products');
        $this->assertGreaterThan(0.7, $fuzzyMatches[0]['confidence_score'], 'Confidence score should be high');

        // Test brand + category match
        $brandCategoryMatches = $this->matchingService->findByBrandAndCategory('ЭТОНОВО', 'Смеси для выпечки');
        $this->assertNotEmpty($brandCategoryMatches, 'Brand+category matching should work');
    }

    /**
     * Test data quality validation
     * Requirements: 5.1, 5.2
     */
    public function testDataQualityValidation()
    {
        // Create test data with various quality issues
        $testProducts = [
            [
                'name' => 'Смесь для выпечки ЭТОНОВО Пицца',
                'brand' => 'ЭТОНОВО',
                'category' => 'Смеси для выпечки'
            ],
            [
                'name' => 'Товар без бренда',
                'brand' => '',
                'category' => 'Неизвестная категория'
            ],
            [
                'name' => '',
                'brand' => 'Бренд без названия',
                'category' => 'Категория'
            ]
        ];

        foreach ($testProducts as $product) {
            $this->productsService->createMasterProduct($product);
        }

        // Test quality metrics calculation
        $qualityMetrics = $this->productsService->calculateQualityMetrics();
        
        $this->assertArrayHasKey('completeness_score', $qualityMetrics);
        $this->assertArrayHasKey('total_products', $qualityMetrics);
        $this->assertArrayHasKey('products_with_complete_data', $qualityMetrics);
        
        $this->assertLessThan(100, $qualityMetrics['completeness_score'], 'Quality score should reflect incomplete data');
    }

    /**
     * Test verification workflow
     * Requirements: 4.2, 4.3, 3.4
     */
    public function testVerificationWorkflow()
    {
        // Create pending verification items
        $masterId1 = $this->productsService->createMasterProduct([
            'canonical_name' => 'Тестовый товар 1',
            'canonical_brand' => 'Тестовый бренд',
            'canonical_category' => 'Тестовая категория'
        ]);

        $this->verificationService->addPendingVerification([
            'master_id' => $masterId1,
            'external_sku' => 'TEST_SKU_001',
            'source' => 'test',
            'confidence_score' => 0.75,
            'suggested_action' => 'merge'
        ]);

        // Test getting pending items
        $pendingItems = $this->verificationService->getPendingVerifications();
        $this->assertNotEmpty($pendingItems, 'Should have pending verification items');

        // Test approving verification
        $verificationId = $pendingItems[0]['id'];
        $result = $this->verificationService->approveVerification($verificationId, 'merge');
        $this->assertTrue($result, 'Verification approval should succeed');

        // Verify the mapping was created
        $mapping = $this->db->query("SELECT * FROM sku_mapping WHERE external_sku = 'TEST_SKU_001'")->fetch();
        $this->assertNotFalse($mapping, 'SKU mapping should be created after approval');
        $this->assertEquals('manual', $mapping['verification_status']);
    }

    private function setupTestData()
    {
        // Create test tables if they don't exist
        $this->db->exec("
            CREATE TABLE IF NOT EXISTS master_products (
                master_id VARCHAR(50) PRIMARY KEY,
                canonical_name VARCHAR(500) NOT NULL,
                canonical_brand VARCHAR(200),
                canonical_category VARCHAR(200),
                description TEXT,
                attributes JSON,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                status ENUM('active', 'inactive', 'pending_review') DEFAULT 'active'
            )
        ");

        $this->db->exec("
            CREATE TABLE IF NOT EXISTS sku_mapping (
                id BIGINT AUTO_INCREMENT PRIMARY KEY,
                master_id VARCHAR(50) NOT NULL,
                external_sku VARCHAR(200) NOT NULL,
                source VARCHAR(50) NOT NULL,
                source_name VARCHAR(500),
                source_brand VARCHAR(200),
                source_category VARCHAR(200),
                confidence_score DECIMAL(3,2),
                verification_status ENUM('auto', 'manual', 'pending') DEFAULT 'pending',
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY unique_source_sku (source, external_sku)
            )
        ");

        $this->db->exec("
            CREATE TABLE IF NOT EXISTS verification_queue (
                id BIGINT AUTO_INCREMENT PRIMARY KEY,
                master_id VARCHAR(50),
                external_sku VARCHAR(200),
                source VARCHAR(50),
                confidence_score DECIMAL(3,2),
                suggested_action VARCHAR(50),
                status ENUM('pending', 'approved', 'rejected') DEFAULT 'pending',
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            )
        ");
    }

    private function cleanTestData()
    {
        $tables = ['sku_mapping', 'master_products', 'verification_queue'];
        foreach ($tables as $table) {
            $this->db->exec("DELETE FROM $table WHERE 1=1");
        }
    }
}