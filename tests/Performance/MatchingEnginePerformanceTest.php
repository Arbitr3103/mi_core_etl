<?php

namespace Tests\Performance;

use PHPUnit\Framework\TestCase;
use MDM\Services\MatchingEngine;
use MDM\Services\MatchingScoreService;
use MDM\Services\ProductMatchingOrchestrator;
use MDM\Models\MasterProduct;

/**
 * Performance tests for matching engine components
 * 
 * Tests performance and scalability with large datasets
 */
class MatchingEnginePerformanceTest extends TestCase
{
    private MatchingEngine $matchingEngine;
    private MatchingScoreService $scoreService;
    private array $largeMasterProductDataset;

    protected function setUp(): void
    {
        parent::setUp();
        $this->matchingEngine = new MatchingEngine();
        $this->scoreService = new MatchingScoreService();
        $this->setupLargeDataset();
    }

    /**
     * Setup large dataset for performance testing
     */
    private function setupLargeDataset(): void
    {
        $this->largeMasterProductDataset = [];
        
        $brands = ['Samsung', 'Apple', 'Sony', 'LG', 'Panasonic', 'Philips', 'Bosch', 'Siemens'];
        $categories = ['Электроника', 'Бытовая техника', 'Автозапчасти', 'Одежда', 'Продукты питания'];
        
        for ($i = 0; $i < 5000; $i++) {
            $brand = $brands[$i % count($brands)];
            $category = $categories[$i % count($categories)];
            
            $this->largeMasterProductDataset[] = new MasterProduct(
                "MASTER_{$i}",
                "Product Name {$i} {$brand} Model",
                $brand,
                $category,
                "Description for product {$i}",
                ['model' => "Model_{$i}", 'year' => 2020 + ($i % 5)],
                sprintf('%013d', 1000000000000 + $i)
            );
        }
    }

    /**
     * Test performance of finding matches in large dataset
     */
    public function testFindMatchesPerformanceWithLargeDataset(): void
    {
        $productData = [
            'name' => 'Product Name 2500 Samsung Model',
            'brand' => 'Samsung',
            'category' => 'Электроника',
            'sku' => 'TEST_2500'
        ];

        $startTime = microtime(true);
        $startMemory = memory_get_usage();

        $matches = $this->matchingEngine->findMatches($productData, $this->largeMasterProductDataset);

        $endTime = microtime(true);
        $endMemory = memory_get_usage();

        $executionTime = $endTime - $startTime;
        $memoryUsed = $endMemory - $startMemory;

        // Performance assertions
        $this->assertLessThan(10.0, $executionTime, 'Finding matches in 5000 products should take less than 10 seconds');
        $this->assertLessThan(50 * 1024 * 1024, $memoryUsed, 'Memory usage should be less than 50MB');
        
        // Functionality assertions
        $this->assertNotEmpty($matches, 'Should find matches in large dataset');
        $this->assertLessThanOrEqual(100, count($matches), 'Should limit number of matches returned');

        echo "\nPerformance Results for findMatches with 5000 products:\n";
        echo "Execution time: " . round($executionTime, 3) . " seconds\n";
        echo "Memory used: " . round($memoryUsed / 1024 / 1024, 2) . " MB\n";
        echo "Matches found: " . count($matches) . "\n";
    }

    /**
     * Test performance of fuzzy name matching
     */
    public function testFuzzyNameMatchingPerformance(): void
    {
        $testNames = [
            'Samsung Galaxy S21 Ultra 256GB',
            'Apple iPhone 13 Pro Max',
            'Sony WH-1000XM4 Headphones',
            'LG OLED55C1PUB 55-Inch TV',
            'Bosch Serie 6 Washing Machine'
        ];

        $totalTime = 0;
        $totalComparisons = 0;

        foreach ($testNames as $testName) {
            $productData = ['name' => $testName];
            
            $startTime = microtime(true);
            
            foreach ($this->largeMasterProductDataset as $masterProduct) {
                $this->matchingEngine->fuzzyNameMatch($productData, $masterProduct);
                $totalComparisons++;
            }
            
            $endTime = microtime(true);
            $totalTime += ($endTime - $startTime);
        }

        $averageTimePerComparison = $totalTime / $totalComparisons;

        $this->assertLessThan(0.001, $averageTimePerComparison, 'Average fuzzy name comparison should be very fast');
        $this->assertLessThan(30.0, $totalTime, 'Total fuzzy matching time should be reasonable');

        echo "\nFuzzy Name Matching Performance:\n";
        echo "Total comparisons: {$totalComparisons}\n";
        echo "Total time: " . round($totalTime, 3) . " seconds\n";
        echo "Average time per comparison: " . round($averageTimePerComparison * 1000, 3) . " ms\n";
    }

    /**
     * Test performance of confidence score calculation
     */
    public function testConfidenceScoreCalculationPerformance(): void
    {
        $matchDetailsSets = [];
        
        // Generate various match details for testing
        for ($i = 0; $i < 1000; $i++) {
            $matchDetailsSets[] = [
                'exact_sku_match' => false,
                'exact_barcode_match' => ($i % 10 === 0),
                'name_similarity' => rand(0, 100) / 100,
                'brand_category_match' => rand(0, 100) / 100,
                'attributes_similarity' => rand(0, 100) / 100,
                'product_name' => "Test Product {$i}",
                'product_brand' => 'Test Brand',
                'master_brand' => ($i % 3 === 0) ? 'Test Brand' : 'Other Brand'
            ];
        }

        $startTime = microtime(true);
        $startMemory = memory_get_usage();

        $scores = [];
        foreach ($matchDetailsSets as $matchDetails) {
            $scores[] = $this->scoreService->calculateConfidenceScore($matchDetails);
        }

        $endTime = microtime(true);
        $endMemory = memory_get_usage();

        $executionTime = $endTime - $startTime;
        $memoryUsed = $endMemory - $startMemory;

        $this->assertLessThan(1.0, $executionTime, 'Calculating 1000 confidence scores should be very fast');
        $this->assertLessThan(5 * 1024 * 1024, $memoryUsed, 'Memory usage should be minimal');
        $this->assertCount(1000, $scores, 'Should calculate all scores');

        // Verify score validity
        foreach ($scores as $score) {
            $this->assertGreaterThanOrEqual(0.0, $score);
            $this->assertLessThanOrEqual(1.0, $score);
        }

        echo "\nConfidence Score Calculation Performance:\n";
        echo "Calculations: 1000\n";
        echo "Execution time: " . round($executionTime, 3) . " seconds\n";
        echo "Memory used: " . round($memoryUsed / 1024, 2) . " KB\n";
        echo "Average time per calculation: " . round(($executionTime / 1000) * 1000, 3) . " ms\n";
    }

    /**
     * Test performance of batch processing
     */
    public function testBatchProcessingPerformance(): void
    {
        // Create batch of products to process
        $batchSize = 100;
        $productsBatch = [];
        
        for ($i = 0; $i < $batchSize; $i++) {
            $productsBatch[] = [
                'sku' => "BATCH_PERF_{$i}",
                'name' => "Batch Performance Test Product {$i}",
                'brand' => 'Performance Brand',
                'category' => 'Performance Category'
            ];
        }

        // Create mock dependencies for orchestrator
        $mockEnrichmentService = $this->createMock(\MDM\Services\DataEnrichmentService::class);
        $mockMasterProductRepository = $this->createMock(\MDM\Repositories\MasterProductRepository::class);
        $mockSkuMappingRepository = $this->createMock(\MDM\Repositories\SkuMappingRepository::class);

        // Setup mocks for performance test
        $mockEnrichmentService->method('enrichProductData')
            ->willReturnCallback(function($productData) {
                return [
                    'enriched_data' => $productData,
                    'enrichment_results' => [],
                    'overall_status' => 'success'
                ];
            });

        $mockMasterProductRepository->method('findAll')
            ->willReturn(array_slice($this->largeMasterProductDataset, 0, 1000)); // Use subset for performance

        $orchestrator = new ProductMatchingOrchestrator(
            $this->matchingEngine,
            $this->scoreService,
            $mockEnrichmentService,
            $mockMasterProductRepository,
            $mockSkuMappingRepository
        );

        $startTime = microtime(true);
        $startMemory = memory_get_usage();

        $result = $orchestrator->processBatch($productsBatch);

        $endTime = microtime(true);
        $endMemory = memory_get_usage();

        $executionTime = $endTime - $startTime;
        $memoryUsed = $endMemory - $startMemory;

        $this->assertLessThan(60.0, $executionTime, 'Batch processing should complete within 60 seconds');
        $this->assertLessThan(100 * 1024 * 1024, $memoryUsed, 'Memory usage should be reasonable');
        $this->assertEquals($batchSize, $result['statistics']['total_processed']);

        echo "\nBatch Processing Performance:\n";
        echo "Batch size: {$batchSize} products\n";
        echo "Master products: 1000\n";
        echo "Execution time: " . round($executionTime, 3) . " seconds\n";
        echo "Memory used: " . round($memoryUsed / 1024 / 1024, 2) . " MB\n";
        echo "Average time per product: " . round(($executionTime / $batchSize), 3) . " seconds\n";
    }

    /**
     * Test memory efficiency with repeated operations
     */
    public function testMemoryEfficiencyWithRepeatedOperations(): void
    {
        $initialMemory = memory_get_usage();
        $peakMemory = $initialMemory;

        $productData = [
            'name' => 'Memory Test Product',
            'brand' => 'Memory Brand',
            'category' => 'Memory Category'
        ];

        // Perform repeated matching operations
        for ($i = 0; $i < 50; $i++) {
            $matches = $this->matchingEngine->findMatches($productData, 
                array_slice($this->largeMasterProductDataset, 0, 100));
            
            $currentMemory = memory_get_usage();
            $peakMemory = max($peakMemory, $currentMemory);
            
            // Force garbage collection periodically
            if ($i % 10 === 0) {
                gc_collect_cycles();
            }
        }

        $finalMemory = memory_get_usage();
        $memoryIncrease = $finalMemory - $initialMemory;
        $peakIncrease = $peakMemory - $initialMemory;

        $this->assertLessThan(20 * 1024 * 1024, $memoryIncrease, 'Memory should not increase significantly');
        $this->assertLessThan(50 * 1024 * 1024, $peakIncrease, 'Peak memory usage should be reasonable');

        echo "\nMemory Efficiency Test:\n";
        echo "Operations: 50 x 100 products\n";
        echo "Initial memory: " . round($initialMemory / 1024 / 1024, 2) . " MB\n";
        echo "Final memory: " . round($finalMemory / 1024 / 1024, 2) . " MB\n";
        echo "Peak memory: " . round($peakMemory / 1024 / 1024, 2) . " MB\n";
        echo "Memory increase: " . round($memoryIncrease / 1024 / 1024, 2) . " MB\n";
    }

    /**
     * Test scalability with increasing dataset sizes
     */
    public function testScalabilityWithIncreasingDatasetSizes(): void
    {
        $datasetSizes = [100, 500, 1000, 2500, 5000];
        $results = [];

        $productData = [
            'name' => 'Scalability Test Product',
            'brand' => 'Test Brand',
            'category' => 'Test Category'
        ];

        foreach ($datasetSizes as $size) {
            $dataset = array_slice($this->largeMasterProductDataset, 0, $size);
            
            $startTime = microtime(true);
            $matches = $this->matchingEngine->findMatches($productData, $dataset);
            $endTime = microtime(true);
            
            $executionTime = $endTime - $startTime;
            $results[$size] = $executionTime;

            $this->assertNotEmpty($matches, "Should find matches in dataset of size {$size}");
        }

        // Verify that execution time scales reasonably (should be roughly linear or better)
        $timeFor100 = $results[100];
        $timeFor5000 = $results[5000];
        $scalingFactor = $timeFor5000 / $timeFor100;

        $this->assertLessThan(100, $scalingFactor, 'Scaling should be reasonable (less than 100x for 50x data)');

        echo "\nScalability Test Results:\n";
        foreach ($results as $size => $time) {
            echo "Dataset size: {$size}, Time: " . round($time, 3) . " seconds\n";
        }
        echo "Scaling factor (5000/100): " . round($scalingFactor, 2) . "x\n";
    }

    /**
     * Test concurrent matching simulation
     */
    public function testConcurrentMatchingSimulation(): void
    {
        $concurrentProducts = [];
        for ($i = 0; $i < 10; $i++) {
            $concurrentProducts[] = [
                'name' => "Concurrent Product {$i}",
                'brand' => 'Concurrent Brand',
                'category' => 'Concurrent Category'
            ];
        }

        $startTime = microtime(true);
        $results = [];

        // Simulate concurrent processing (sequential in test environment)
        foreach ($concurrentProducts as $productData) {
            $matches = $this->matchingEngine->findMatches($productData, 
                array_slice($this->largeMasterProductDataset, 0, 500));
            $results[] = $matches;
        }

        $endTime = microtime(true);
        $totalTime = $endTime - $startTime;

        $this->assertCount(10, $results, 'Should process all concurrent requests');
        $this->assertLessThan(20.0, $totalTime, 'Concurrent processing should be efficient');

        // Verify results are independent
        for ($i = 1; $i < count($results); $i++) {
            $this->assertNotSame($results[0], $results[$i], 'Results should be independent');
        }

        echo "\nConcurrent Processing Simulation:\n";
        echo "Concurrent requests: 10\n";
        echo "Products per request: 500\n";
        echo "Total time: " . round($totalTime, 3) . " seconds\n";
        echo "Average time per request: " . round($totalTime / 10, 3) . " seconds\n";
    }

    /**
     * Test performance degradation with complex products
     */
    public function testPerformanceWithComplexProducts(): void
    {
        // Create complex product with many attributes
        $complexProductData = [
            'name' => 'Very Complex Product Name With Many Words And Details',
            'brand' => 'Complex Brand Name',
            'category' => 'Complex Category > Subcategory > Sub-subcategory',
            'description' => str_repeat('Complex description with many details. ', 50),
            'attributes' => array_fill(0, 100, 'complex_attribute_value'),
            'tags' => array_fill(0, 50, 'complex_tag'),
            'specifications' => array_fill_keys(range(1, 50), 'specification_value')
        ];

        $startTime = microtime(true);
        $matches = $this->matchingEngine->findMatches($complexProductData, 
            array_slice($this->largeMasterProductDataset, 0, 1000));
        $endTime = microtime(true);

        $executionTime = $endTime - $startTime;

        $this->assertLessThan(15.0, $executionTime, 'Complex product matching should still be reasonably fast');
        $this->assertIsArray($matches, 'Should handle complex products without errors');

        echo "\nComplex Product Performance:\n";
        echo "Product complexity: High (many attributes)\n";
        echo "Dataset size: 1000 products\n";
        echo "Execution time: " . round($executionTime, 3) . " seconds\n";
    }
}