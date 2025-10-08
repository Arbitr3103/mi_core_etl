<?php

require_once __DIR__ . '/../../bootstrap.php';
require_once __DIR__ . '/../../../src/MDM/Services/DataQualityService.php';
require_once __DIR__ . '/../../../src/MDM/Services/ProductsService.php';

use PHPUnit\Framework\TestCase;

/**
 * Data quality validation tests for MDM system
 * Tests data completeness, accuracy, and consistency
 */
class DataQualityValidationTest extends TestCase
{
    private $db;
    private $dataQualityService;
    private $productsService;

    protected function setUp(): void
    {
        $this->db = new PDO(
            "mysql:host=" . $_ENV['DB_HOST'] . ";dbname=" . $_ENV['TEST_DB_NAME'],
            $_ENV['DB_USER'],
            $_ENV['DB_PASS']
        );
        
        $this->dataQualityService = new DataQualityService($this->db);
        $this->productsService = new ProductsService($this->db);
        
        $this->setupDataQualityTestData();
    }

    protected function tearDown(): void
    {
        $this->cleanupDataQualityTestData();
    }

    /**
     * Test data completeness validation
     * Requirements: 5.1, 5.2
     */
    public function testDataCompletenessValidation()
    {
        // Create test products with varying completeness
        $testProducts = [
            [
                'master_id' => 'COMPLETE_001',
                'canonical_name' => 'Complete Product',
                'canonical_brand' => 'Complete Brand',
                'canonical_category' => 'Complete Category',
                'description' => 'Complete description'
            ],
            [
                'master_id' => 'INCOMPLETE_001',
                'canonical_name' => 'Incomplete Product',
                'canonical_brand' => '',
                'canonical_category' => null,
                'description' => null
            ],
            [
                'master_id' => 'PARTIAL_001',
                'canonical_name' => 'Partial Product',
                'canonical_brand' => 'Partial Brand',
                'canonical_category' => '',
                'description' => 'Has description'
            ]
        ];

        foreach ($testProducts as $product) {
            $this->productsService->createMasterProduct($product);
        }

        // Test completeness calculation
        $completenessMetrics = $this->dataQualityService->calculateCompletenessMetrics();
        
        $this->assertArrayHasKey('overall_completeness', $completenessMetrics);
        $this->assertArrayHasKey('field_completeness', $completenessMetrics);
        $this->assertArrayHasKey('complete_products_count', $completenessMetrics);
        $this->assertArrayHasKey('total_products_count', $completenessMetrics);
        
        // Verify field-level completeness
        $fieldCompleteness = $completenessMetrics['field_completeness'];
        $this->assertEquals(100, $fieldCompleteness['canonical_name'], 'All products should have names');
        $this->assertEquals(67, round($fieldCompleteness['canonical_brand']), 'Brand completeness should be ~67%');
        $this->assertEquals(33, round($fieldCompleteness['canonical_category']), 'Category completeness should be ~33%');
        
        // Test incomplete products identification
        $incompleteProducts = $this->dataQualityService->getIncompleteProducts();
        $this->assertCount(2, $incompleteProducts, 'Should identify 2 incomplete products');
    }

    /**
     * Test data accuracy validation
     * Requirements: 5.1, 5.2
     */
    public function testDataAccuracyValidation()
    {
        // Create test products with accuracy issues
        $testProducts = [
            [
                'master_id' => 'ACCURATE_001',
                'canonical_name' => 'Смесь для выпечки ЭТОНОВО Пицца',
                'canonical_brand' => 'ЭТОНОВО',
                'canonical_category' => 'Смеси для выпечки'
            ],
            [
                'master_id' => 'INACCURATE_001',
                'canonical_name' => 'смесь этоново пицца!!!',
                'canonical_brand' => 'этоново',
                'canonical_category' => 'смеси'
            ],
            [
                'master_id' => 'SUSPICIOUS_001',
                'canonical_name' => 'Product Name',
                'canonical_brand' => 'Неизвестный бренд',
                'canonical_category' => 'Без категории'
            ]
        ];

        foreach ($testProducts as $product) {
            $this->productsService->createMasterProduct($product);
        }

        // Test accuracy validation
        $accuracyIssues = $this->dataQualityService->validateDataAccuracy();
        
        $this->assertArrayHasKey('formatting_issues', $accuracyIssues);
        $this->assertArrayHasKey('suspicious_values', $accuracyIssues);
        $this->assertArrayHasKey('standardization_needed', $accuracyIssues);
        
        // Check for formatting issues
        $formattingIssues = $accuracyIssues['formatting_issues'];
        $this->assertGreaterThan(0, count($formattingIssues), 'Should detect formatting issues');
        
        // Check for suspicious values
        $suspiciousValues = $accuracyIssues['suspicious_values'];
        $this->assertGreaterThan(0, count($suspiciousValues), 'Should detect suspicious values');
        
        // Verify specific issues are detected
        $suspiciousBrands = array_column($suspiciousValues, 'canonical_brand');
        $this->assertContains('Неизвестный бренд', $suspiciousBrands, 'Should detect unknown brand');
    }

    /**
     * Test data consistency validation
     * Requirements: 5.1, 5.2
     */
    public function testDataConsistencyValidation()
    {
        // Create test data with consistency issues
        $testProducts = [
            [
                'master_id' => 'CONSISTENT_001',
                'canonical_name' => 'Смесь для выпечки ЭТОНОВО Пицца',
                'canonical_brand' => 'ЭТОНОВО',
                'canonical_category' => 'Смеси для выпечки'
            ],
            [
                'master_id' => 'INCONSISTENT_001',
                'canonical_name' => 'Смесь для выпечки ЭТОНОВО Хлеб',
                'canonical_brand' => 'Этоново',  // Different case
                'canonical_category' => 'Смеси для выпечки'
            ],
            [
                'master_id' => 'INCONSISTENT_002',
                'canonical_name' => 'Товар ЭТОНОВО',
                'canonical_brand' => 'ETОНOVO',  // Different spelling
                'canonical_category' => 'Смеси'
            ]
        ];

        foreach ($testProducts as $product) {
            $this->productsService->createMasterProduct($product);
        }

        // Create SKU mappings for consistency testing
        $skuMappings = [
            [
                'master_id' => 'CONSISTENT_001',
                'external_sku' => 'OZON_123',
                'source' => 'ozon',
                'source_brand' => 'ЭТОНОВО'
            ],
            [
                'master_id' => 'INCONSISTENT_001',
                'external_sku' => 'WB_456',
                'source' => 'wildberries',
                'source_brand' => 'ДРУГОЙ_БРЕНД'  // Inconsistent with master
            ]
        ];

        foreach ($skuMappings as $mapping) {
            $this->db->exec("
                INSERT INTO sku_mapping (master_id, external_sku, source, source_brand) 
                VALUES ('{$mapping['master_id']}', '{$mapping['external_sku']}', '{$mapping['source']}', '{$mapping['source_brand']}')
            ");
        }

        // Test consistency validation
        $consistencyIssues = $this->dataQualityService->validateDataConsistency();
        
        $this->assertArrayHasKey('brand_inconsistencies', $consistencyIssues);
        $this->assertArrayHasKey('category_inconsistencies', $consistencyIssues);
        $this->assertArrayHasKey('sku_mapping_inconsistencies', $consistencyIssues);
        
        // Check brand inconsistencies
        $brandInconsistencies = $consistencyIssues['brand_inconsistencies'];
        $this->assertGreaterThan(0, count($brandInconsistencies), 'Should detect brand inconsistencies');
        
        // Check SKU mapping inconsistencies
        $skuInconsistencies = $consistencyIssues['sku_mapping_inconsistencies'];
        $this->assertGreaterThan(0, count($skuInconsistencies), 'Should detect SKU mapping inconsistencies');
    }

    /**
     * Test duplicate detection
     * Requirements: 3.1, 3.2
     */
    public function testDuplicateDetection()
    {
        // Create potential duplicate products
        $testProducts = [
            [
                'master_id' => 'ORIGINAL_001',
                'canonical_name' => 'Смесь для выпечки ЭТОНОВО Пицца',
                'canonical_brand' => 'ЭТОНОВО',
                'canonical_category' => 'Смеси для выпечки'
            ],
            [
                'master_id' => 'DUPLICATE_001',
                'canonical_name' => 'Смесь ЭТОНОВО для пиццы',
                'canonical_brand' => 'ЭТОНОВО',
                'canonical_category' => 'Смеси для выпечки'
            ],
            [
                'master_id' => 'SIMILAR_001',
                'canonical_name' => 'Смесь для выпечки ДРУГОЙ_БРЕНД Пицца',
                'canonical_brand' => 'ДРУГОЙ_БРЕНД',
                'canonical_category' => 'Смеси для выпечки'
            ]
        ];

        foreach ($testProducts as $product) {
            $this->productsService->createMasterProduct($product);
        }

        // Test duplicate detection
        $duplicates = $this->dataQualityService->detectPotentialDuplicates();
        
        $this->assertNotEmpty($duplicates, 'Should detect potential duplicates');
        
        // Verify duplicate pairs
        $duplicatePairs = [];
        foreach ($duplicates as $duplicate) {
            $duplicatePairs[] = [$duplicate['master_id_1'], $duplicate['master_id_2']];
        }
        
        $this->assertTrue(
            in_array(['ORIGINAL_001', 'DUPLICATE_001'], $duplicatePairs) || 
            in_array(['DUPLICATE_001', 'ORIGINAL_001'], $duplicatePairs),
            'Should detect ORIGINAL_001 and DUPLICATE_001 as potential duplicates'
        );
        
        // Verify similarity scores
        foreach ($duplicates as $duplicate) {
            $this->assertGreaterThan(0.7, $duplicate['similarity_score'], 'Similarity score should be high for duplicates');
        }
    }

    /**
     * Test quality metrics calculation
     * Requirements: 5.1, 5.2
     */
    public function testQualityMetricsCalculation()
    {
        // Create diverse test dataset
        $this->createDiverseTestDataset();
        
        // Calculate overall quality metrics
        $qualityMetrics = $this->dataQualityService->calculateOverallQualityMetrics();
        
        $this->assertArrayHasKey('completeness_score', $qualityMetrics);
        $this->assertArrayHasKey('accuracy_score', $qualityMetrics);
        $this->assertArrayHasKey('consistency_score', $qualityMetrics);
        $this->assertArrayHasKey('overall_quality_score', $qualityMetrics);
        
        // Verify score ranges
        $this->assertGreaterThanOrEqual(0, $qualityMetrics['completeness_score']);
        $this->assertLessThanOrEqual(100, $qualityMetrics['completeness_score']);
        
        $this->assertGreaterThanOrEqual(0, $qualityMetrics['accuracy_score']);
        $this->assertLessThanOrEqual(100, $qualityMetrics['accuracy_score']);
        
        $this->assertGreaterThanOrEqual(0, $qualityMetrics['consistency_score']);
        $this->assertLessThanOrEqual(100, $qualityMetrics['consistency_score']);
        
        $this->assertGreaterThanOrEqual(0, $qualityMetrics['overall_quality_score']);
        $this->assertLessThanOrEqual(100, $qualityMetrics['overall_quality_score']);
        
        // Test historical tracking
        $this->dataQualityService->saveQualityMetrics($qualityMetrics);
        
        $historicalMetrics = $this->dataQualityService->getHistoricalQualityMetrics(7);
        $this->assertNotEmpty($historicalMetrics, 'Should save and retrieve historical metrics');
    }

    /**
     * Test quality improvement suggestions
     * Requirements: 5.3, 5.4
     */
    public function testQualityImprovementSuggestions()
    {
        // Create test data with known issues
        $this->createProblematicTestDataset();
        
        // Get improvement suggestions
        $suggestions = $this->dataQualityService->generateImprovementSuggestions();
        
        $this->assertArrayHasKey('high_priority', $suggestions);
        $this->assertArrayHasKey('medium_priority', $suggestions);
        $this->assertArrayHasKey('low_priority', $suggestions);
        
        // Verify suggestion structure
        foreach ($suggestions as $priority => $prioritySuggestions) {
            foreach ($prioritySuggestions as $suggestion) {
                $this->assertArrayHasKey('issue_type', $suggestion);
                $this->assertArrayHasKey('description', $suggestion);
                $this->assertArrayHasKey('affected_count', $suggestion);
                $this->assertArrayHasKey('suggested_action', $suggestion);
            }
        }
        
        // Verify specific suggestions are generated
        $allSuggestions = array_merge(
            $suggestions['high_priority'],
            $suggestions['medium_priority'],
            $suggestions['low_priority']
        );
        
        $issueTypes = array_column($allSuggestions, 'issue_type');
        $this->assertContains('missing_brand', $issueTypes, 'Should suggest fixing missing brands');
        $this->assertContains('unknown_category', $issueTypes, 'Should suggest fixing unknown categories');
    }

    private function setupDataQualityTestData()
    {
        // Create test tables
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
            CREATE TABLE IF NOT EXISTS data_quality_metrics (
                id BIGINT AUTO_INCREMENT PRIMARY KEY,
                metric_name VARCHAR(100) NOT NULL,
                metric_value DECIMAL(10,2),
                total_records INT,
                good_records INT,
                calculation_date TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_metric_date (metric_name, calculation_date)
            )
        ");
    }

    private function createDiverseTestDataset()
    {
        $products = [
            ['master_id' => 'GOOD_001', 'canonical_name' => 'Good Product 1', 'canonical_brand' => 'Good Brand', 'canonical_category' => 'Good Category'],
            ['master_id' => 'GOOD_002', 'canonical_name' => 'Good Product 2', 'canonical_brand' => 'Good Brand', 'canonical_category' => 'Good Category'],
            ['master_id' => 'MISSING_BRAND_001', 'canonical_name' => 'Product Missing Brand', 'canonical_brand' => '', 'canonical_category' => 'Category'],
            ['master_id' => 'MISSING_CATEGORY_001', 'canonical_name' => 'Product Missing Category', 'canonical_brand' => 'Brand', 'canonical_category' => ''],
            ['master_id' => 'UNKNOWN_001', 'canonical_name' => 'Unknown Product', 'canonical_brand' => 'Неизвестный бренд', 'canonical_category' => 'Без категории']
        ];

        foreach ($products as $product) {
            $this->productsService->createMasterProduct($product);
        }
    }

    private function createProblematicTestDataset()
    {
        $products = [
            ['master_id' => 'PROB_001', 'canonical_name' => 'Product 1', 'canonical_brand' => '', 'canonical_category' => 'Category'],
            ['master_id' => 'PROB_002', 'canonical_name' => 'Product 2', 'canonical_brand' => 'Неизвестный бренд', 'canonical_category' => ''],
            ['master_id' => 'PROB_003', 'canonical_name' => 'Product 3', 'canonical_brand' => 'Brand', 'canonical_category' => 'Без категории'],
            ['master_id' => 'PROB_004', 'canonical_name' => '', 'canonical_brand' => 'Brand', 'canonical_category' => 'Category']
        ];

        foreach ($products as $product) {
            $this->productsService->createMasterProduct($product);
        }
    }

    private function cleanupDataQualityTestData()
    {
        $testPrefixes = ['COMPLETE_', 'INCOMPLETE_', 'PARTIAL_', 'ACCURATE_', 'INACCURATE_', 'SUSPICIOUS_', 
                        'CONSISTENT_', 'INCONSISTENT_', 'ORIGINAL_', 'DUPLICATE_', 'SIMILAR_', 'GOOD_', 
                        'MISSING_', 'UNKNOWN_', 'PROB_'];
        
        foreach ($testPrefixes as $prefix) {
            $this->db->exec("DELETE FROM sku_mapping WHERE master_id LIKE '{$prefix}%'");
            $this->db->exec("DELETE FROM master_products WHERE master_id LIKE '{$prefix}%'");
        }
        
        $this->db->exec("DELETE FROM data_quality_metrics WHERE 1=1");
    }
}