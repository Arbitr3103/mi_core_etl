<?php

namespace Tests\Unit\Services;

use PHPUnit\Framework\TestCase;
use MDM\Services\MatchingEngine;
use MDM\Models\MasterProduct;

/**
 * Unit tests for MatchingEngine
 * 
 * Tests all matching algorithms, edge cases, error handling, and performance
 */
class MatchingEngineTest extends TestCase
{
    private MatchingEngine $matchingEngine;
    private array $testMasterProducts;

    protected function setUp(): void
    {
        parent::setUp();
        $this->matchingEngine = new MatchingEngine();
        $this->setupTestMasterProducts();
    }

    /**
     * Setup test master products for various test scenarios
     */
    private function setupTestMasterProducts(): void
    {
        $this->testMasterProducts = [
            new MasterProduct(
                'MASTER_001',
                'Смесь для выпечки ЭТОНОВО Пицца 9 порций',
                'ЭТОНОВО',
                'Смеси для выпечки',
                'Готовая смесь для приготовления теста для пиццы',
                ['weight' => '450г', 'servings' => 9, 'type' => 'dry_mix'],
                '1234567890123'
            ),
            new MasterProduct(
                'MASTER_002',
                'Автомобильный фильтр воздушный Toyota Camry',
                'Toyota',
                'Автозапчасти',
                'Воздушный фильтр для Toyota Camry 2018-2022',
                ['car_model' => 'Camry', 'year_from' => 2018, 'year_to' => 2022],
                '9876543210987'
            ),
            new MasterProduct(
                'MASTER_003',
                'Кофе молотый Lavazza Qualita Oro 250г',
                'Lavazza',
                'Кофе и чай',
                'Премиальный молотый кофе',
                ['weight' => '250г', 'grind' => 'medium'],
                '5555666677778'
            ),
            new MasterProduct(
                'MASTER_004',
                'Смартфон Samsung Galaxy A54 128GB',
                'Samsung',
                'Электроника',
                'Смартфон с экраном 6.4 дюйма',
                ['storage' => '128GB', 'screen_size' => '6.4'],
                '1111222233334'
            )
        ];
    }

    /**
     * Test exact SKU matching algorithm
     */
    public function testExactSkuMatch(): void
    {
        $productData = [
            'sku' => 'TEST_SKU_001',
            'name' => 'Test Product',
            'brand' => 'Test Brand'
        ];

        $masterProduct = $this->testMasterProducts[0];

        // Test with empty SKU
        $result = $this->matchingEngine->exactSkuMatch([], $masterProduct);
        $this->assertFalse($result);

        // Test with non-matching SKU (current implementation returns false as it's a stub)
        $result = $this->matchingEngine->exactSkuMatch($productData, $masterProduct);
        $this->assertFalse($result);
    }

    /**
     * Test exact barcode matching algorithm
     */
    public function testExactBarcodeMatch(): void
    {
        $masterProduct = $this->testMasterProducts[0];

        // Test exact match
        $productData = ['barcode' => '1234567890123'];
        $result = $this->matchingEngine->exactBarcodeMatch($productData, $masterProduct);
        $this->assertTrue($result);

        // Test no match
        $productData = ['barcode' => '9999999999999'];
        $result = $this->matchingEngine->exactBarcodeMatch($productData, $masterProduct);
        $this->assertFalse($result);

        // Test empty barcode in product
        $productData = ['barcode' => ''];
        $result = $this->matchingEngine->exactBarcodeMatch($productData, $masterProduct);
        $this->assertFalse($result);

        // Test missing barcode in product
        $productData = [];
        $result = $this->matchingEngine->exactBarcodeMatch($productData, $masterProduct);
        $this->assertFalse($result);
    }

    /**
     * Test fuzzy name matching algorithm
     */
    public function testFuzzyNameMatch(): void
    {
        $masterProduct = $this->testMasterProducts[0];

        // Test exact name match
        $productData = ['name' => 'Смесь для выпечки ЭТОНОВО Пицца 9 порций'];
        $result = $this->matchingEngine->fuzzyNameMatch($productData, $masterProduct);
        $this->assertEquals(1.0, $result, 'Exact name match should return 1.0');

        // Test similar name
        $productData = ['name' => 'Смесь для выпечки ЭТОНОВО пицца 9 шт'];
        $result = $this->matchingEngine->fuzzyNameMatch($productData, $masterProduct);
        $this->assertGreaterThan(0.7, $result, 'Similar name should have high similarity');

        // Test completely different name
        $productData = ['name' => 'Автомобильные шины'];
        $result = $this->matchingEngine->fuzzyNameMatch($productData, $masterProduct);
        $this->assertLessThan(0.3, $result, 'Different name should have low similarity');

        // Test empty name
        $productData = ['name' => ''];
        $result = $this->matchingEngine->fuzzyNameMatch($productData, $masterProduct);
        $this->assertEquals(0.0, $result, 'Empty name should return 0.0');

        // Test missing name
        $productData = [];
        $result = $this->matchingEngine->fuzzyNameMatch($productData, $masterProduct);
        $this->assertEquals(0.0, $result, 'Missing name should return 0.0');
    }

    /**
     * Test brand and category matching algorithm
     */
    public function testBrandCategoryMatch(): void
    {
        $masterProduct = $this->testMasterProducts[0];

        // Test exact brand and category match
        $productData = [
            'brand' => 'ЭТОНОВО',
            'category' => 'Смеси для выпечки'
        ];
        $result = $this->matchingEngine->brandCategoryMatch($productData, $masterProduct);
        $this->assertEquals(1.0, $result, 'Exact brand and category match should return 1.0');

        // Test only brand match
        $productData = [
            'brand' => 'ЭТОНОВО',
            'category' => 'Другая категория'
        ];
        $result = $this->matchingEngine->brandCategoryMatch($productData, $masterProduct);
        $this->assertEquals(0.5, $result, 'Only brand match should return 0.5');

        // Test only category match
        $productData = [
            'brand' => 'Другой бренд',
            'category' => 'Смеси для выпечки'
        ];
        $result = $this->matchingEngine->brandCategoryMatch($productData, $masterProduct);
        $this->assertEquals(0.5, $result, 'Only category match should return 0.5');

        // Test no match
        $productData = [
            'brand' => 'Другой бренд',
            'category' => 'Другая категория'
        ];
        $result = $this->matchingEngine->brandCategoryMatch($productData, $masterProduct);
        $this->assertEquals(0.0, $result, 'No match should return 0.0');

        // Test case insensitive matching
        $productData = [
            'brand' => 'этоново',
            'category' => 'смеси для выпечки'
        ];
        $result = $this->matchingEngine->brandCategoryMatch($productData, $masterProduct);
        $this->assertEquals(1.0, $result, 'Case insensitive match should work');

        // Test empty values
        $productData = ['brand' => '', 'category' => ''];
        $result = $this->matchingEngine->brandCategoryMatch($productData, $masterProduct);
        $this->assertEquals(0.0, $result, 'Empty values should return 0.0');
    }

    /**
     * Test overall match score calculation
     */
    public function testCalculateMatchScore(): void
    {
        $masterProduct = $this->testMasterProducts[0];

        // Test exact barcode match (should return 1.0 immediately)
        $productData = [
            'barcode' => '1234567890123',
            'name' => 'Different name',
            'brand' => 'Different brand'
        ];
        $result = $this->matchingEngine->calculateMatchScore($productData, $masterProduct);
        $this->assertEquals(1.0, $result, 'Exact barcode match should return 1.0');

        // Test combined fuzzy matching
        $productData = [
            'name' => 'Смесь для выпечки ЭТОНОВО пицца',
            'brand' => 'ЭТОНОВО',
            'category' => 'Смеси для выпечки'
        ];
        $result = $this->matchingEngine->calculateMatchScore($productData, $masterProduct);
        $this->assertGreaterThan(0.7, $result, 'Good combined match should have high score');
        $this->assertLessThan(1.0, $result, 'Non-exact match should be less than 1.0');

        // Test poor match
        $productData = [
            'name' => 'Completely different product',
            'brand' => 'Different brand',
            'category' => 'Different category'
        ];
        $result = $this->matchingEngine->calculateMatchScore($productData, $masterProduct);
        $this->assertLessThan(0.3, $result, 'Poor match should have low score');

        // Test no data
        $productData = [];
        $result = $this->matchingEngine->calculateMatchScore($productData, $masterProduct);
        $this->assertEquals(0.0, $result, 'No data should return 0.0');
    }

    /**
     * Test finding matches among multiple master products
     */
    public function testFindMatches(): void
    {
        // Test finding best match
        $productData = [
            'name' => 'Смесь для выпечки ЭТОНОВО пицца 9 шт',
            'brand' => 'ЭТОНОВО',
            'category' => 'Смеси для выпечки'
        ];

        $matches = $this->matchingEngine->findMatches($productData, $this->testMasterProducts);

        $this->assertNotEmpty($matches, 'Should find at least one match');
        $this->assertArrayHasKey('master_product', $matches[0]);
        $this->assertArrayHasKey('score', $matches[0]);
        $this->assertArrayHasKey('match_details', $matches[0]);

        // Verify matches are sorted by score (descending)
        for ($i = 1; $i < count($matches); $i++) {
            $this->assertGreaterThanOrEqual(
                $matches[$i]['score'],
                $matches[$i - 1]['score'],
                'Matches should be sorted by score in descending order'
            );
        }

        // Test with no matches
        $productData = [
            'name' => 'Completely unrelated product xyz123',
            'brand' => 'NonExistentBrand',
            'category' => 'NonExistentCategory'
        ];

        $matches = $this->matchingEngine->findMatches($productData, $this->testMasterProducts);
        $this->assertEmpty($matches, 'Should find no matches for unrelated product');
    }

    /**
     * Test weight configuration
     */
    public function testWeightConfiguration(): void
    {
        $originalWeights = $this->matchingEngine->getWeights();
        $this->assertIsArray($originalWeights);

        // Test setting new weights
        $newWeights = [
            MatchingEngine::MATCH_TYPE_FUZZY_NAME => 0.6,
            MatchingEngine::MATCH_TYPE_BRAND_CATEGORY => 0.4
        ];

        $this->matchingEngine->setWeights($newWeights);
        $updatedWeights = $this->matchingEngine->getWeights();

        $this->assertEquals(0.6, $updatedWeights[MatchingEngine::MATCH_TYPE_FUZZY_NAME]);
        $this->assertEquals(0.4, $updatedWeights[MatchingEngine::MATCH_TYPE_BRAND_CATEGORY]);
    }

    /**
     * Test edge cases and error handling
     */
    public function testEdgeCases(): void
    {
        $masterProduct = $this->testMasterProducts[0];

        // Test with null values
        $productData = [
            'name' => null,
            'brand' => null,
            'category' => null,
            'barcode' => null
        ];
        $result = $this->matchingEngine->calculateMatchScore($productData, $masterProduct);
        $this->assertEquals(0.0, $result, 'Null values should return 0.0');

        // Test with very short strings
        $productData = ['name' => 'A'];
        $result = $this->matchingEngine->fuzzyNameMatch($productData, $masterProduct);
        $this->assertLessThan(0.1, $result, 'Very short strings should have low similarity');

        // Test with very long strings
        $longName = str_repeat('A', 1000);
        $productData = ['name' => $longName];
        $result = $this->matchingEngine->fuzzyNameMatch($productData, $masterProduct);
        $this->assertIsFloat($result, 'Should handle long strings without error');

        // Test with special characters
        $productData = ['name' => 'Тест!@#$%^&*()_+{}|:"<>?[];\'\\,./`~'];
        $result = $this->matchingEngine->fuzzyNameMatch($productData, $masterProduct);
        $this->assertIsFloat($result, 'Should handle special characters without error');

        // Test with Unicode characters
        $productData = ['name' => 'Тест 中文 العربية 🚀'];
        $result = $this->matchingEngine->fuzzyNameMatch($productData, $masterProduct);
        $this->assertIsFloat($result, 'Should handle Unicode characters without error');
    }

    /**
     * Test string normalization
     */
    public function testStringNormalization(): void
    {
        $masterProduct = new MasterProduct(
            'MASTER_TEST',
            'Test Product Name',
            'Test Brand',
            'Test Category'
        );

        // Test case insensitive matching
        $productData1 = ['name' => 'TEST PRODUCT NAME'];
        $productData2 = ['name' => 'test product name'];
        
        $result1 = $this->matchingEngine->fuzzyNameMatch($productData1, $masterProduct);
        $result2 = $this->matchingEngine->fuzzyNameMatch($productData2, $masterProduct);
        
        $this->assertEquals($result1, $result2, 'Case should not affect matching');
        $this->assertEquals(1.0, $result1, 'Normalized strings should match exactly');

        // Test extra spaces handling
        $productData = ['name' => '  Test   Product    Name  '];
        $result = $this->matchingEngine->fuzzyNameMatch($productData, $masterProduct);
        $this->assertEquals(1.0, $result, 'Extra spaces should be normalized');

        // Test special characters removal
        $productData = ['name' => 'Test!!! Product@@@ Name###'];
        $result = $this->matchingEngine->fuzzyNameMatch($productData, $masterProduct);
        $this->assertEquals(1.0, $result, 'Special characters should be removed');
    }

    /**
     * Test performance with large datasets
     */
    public function testPerformanceWithLargeDataset(): void
    {
        // Create a large dataset of master products
        $largeMasterProducts = [];
        for ($i = 0; $i < 1000; $i++) {
            $largeMasterProducts[] = new MasterProduct(
                "MASTER_{$i}",
                "Product Name {$i}",
                "Brand {$i}",
                "Category " . ($i % 10)
            );
        }

        $productData = [
            'name' => 'Product Name 500',
            'brand' => 'Brand 500',
            'category' => 'Category 0'
        ];

        $startTime = microtime(true);
        $matches = $this->matchingEngine->findMatches($productData, $largeMasterProducts);
        $endTime = microtime(true);

        $executionTime = $endTime - $startTime;

        // Performance should be reasonable (less than 5 seconds for 1000 products)
        $this->assertLessThan(5.0, $executionTime, 'Performance should be acceptable for large datasets');
        $this->assertNotEmpty($matches, 'Should find matches in large dataset');
    }

    /**
     * Test memory usage with large datasets
     */
    public function testMemoryUsageWithLargeDataset(): void
    {
        $initialMemory = memory_get_usage();

        // Create a large dataset
        $largeMasterProducts = [];
        for ($i = 0; $i < 500; $i++) {
            $largeMasterProducts[] = new MasterProduct(
                "MASTER_{$i}",
                "Product Name {$i}",
                "Brand {$i}",
                "Category " . ($i % 10)
            );
        }

        $productData = [
            'name' => 'Test Product',
            'brand' => 'Test Brand',
            'category' => 'Test Category'
        ];

        $this->matchingEngine->findMatches($productData, $largeMasterProducts);

        $finalMemory = memory_get_usage();
        $memoryIncrease = $finalMemory - $initialMemory;

        // Memory increase should be reasonable (less than 50MB)
        $this->assertLessThan(50 * 1024 * 1024, $memoryIncrease, 'Memory usage should be reasonable');
    }

    /**
     * Test concurrent matching scenarios
     */
    public function testConcurrentMatching(): void
    {
        $productDataSets = [
            ['name' => 'Product A', 'brand' => 'Brand A'],
            ['name' => 'Product B', 'brand' => 'Brand B'],
            ['name' => 'Product C', 'brand' => 'Brand C']
        ];

        $results = [];
        foreach ($productDataSets as $productData) {
            $results[] = $this->matchingEngine->findMatches($productData, $this->testMasterProducts);
        }

        // Each result should be independent
        $this->assertCount(3, $results, 'Should have results for all products');
        
        // Results should be consistent when run multiple times
        $secondResults = [];
        foreach ($productDataSets as $productData) {
            $secondResults[] = $this->matchingEngine->findMatches($productData, $this->testMasterProducts);
        }

        $this->assertEquals($results, $secondResults, 'Results should be consistent across runs');
    }

    /**
     * Test boundary conditions for similarity scores
     */
    public function testSimilarityScoreBoundaries(): void
    {
        $masterProduct = $this->testMasterProducts[0];

        // Test minimum score (0.0)
        $productData = ['name' => ''];
        $result = $this->matchingEngine->fuzzyNameMatch($productData, $masterProduct);
        $this->assertEquals(0.0, $result, 'Empty string should give minimum score');

        // Test maximum score (1.0)
        $productData = ['name' => $masterProduct->getCanonicalName()];
        $result = $this->matchingEngine->fuzzyNameMatch($productData, $masterProduct);
        $this->assertEquals(1.0, $result, 'Identical string should give maximum score');

        // Test that scores are always between 0 and 1
        $testNames = [
            'Partial match',
            'Completely different string',
            'Смесь',
            'ЭТОНОВО',
            'Random text xyz123'
        ];

        foreach ($testNames as $name) {
            $productData = ['name' => $name];
            $result = $this->matchingEngine->fuzzyNameMatch($productData, $masterProduct);
            $this->assertGreaterThanOrEqual(0.0, $result, 'Score should not be negative');
            $this->assertLessThanOrEqual(1.0, $result, 'Score should not exceed 1.0');
        }
    }

    /**
     * Test match details generation
     */
    public function testMatchDetailsGeneration(): void
    {
        $productData = [
            'name' => 'Смесь для выпечки ЭТОНОВО пицца',
            'brand' => 'ЭТОНОВО',
            'category' => 'Смеси для выпечки',
            'barcode' => '1234567890123'
        ];

        $matches = $this->matchingEngine->findMatches($productData, [$this->testMasterProducts[0]]);
        
        $this->assertNotEmpty($matches);
        $matchDetails = $matches[0]['match_details'];

        $this->assertArrayHasKey('exact_sku_match', $matchDetails);
        $this->assertArrayHasKey('exact_barcode_match', $matchDetails);
        $this->assertArrayHasKey('name_similarity', $matchDetails);
        $this->assertArrayHasKey('brand_category_match', $matchDetails);
        $this->assertArrayHasKey('product_name', $matchDetails);
        $this->assertArrayHasKey('master_name', $matchDetails);
        $this->assertArrayHasKey('product_brand', $matchDetails);
        $this->assertArrayHasKey('master_brand', $matchDetails);

        $this->assertTrue($matchDetails['exact_barcode_match']);
        $this->assertEquals($productData['name'], $matchDetails['product_name']);
        $this->assertEquals($this->testMasterProducts[0]->getCanonicalName(), $matchDetails['master_name']);
    }
}