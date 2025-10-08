<?php

namespace Tests\Unit\Services;

use PHPUnit\Framework\TestCase;
use MDM\Services\DataEnrichmentService;

/**
 * Unit tests for DataEnrichmentService
 * 
 * Tests data enrichment from external sources, caching, and error handling
 */
class DataEnrichmentServiceTest extends TestCase
{
    private DataEnrichmentService $enrichmentService;
    private array $mockSourceConfigs;

    protected function setUp(): void
    {
        parent::setUp();
        
        $this->mockSourceConfigs = [
            DataEnrichmentService::SOURCE_OZON_API => [
                'api_key' => 'test_ozon_key',
                'client_id' => 'test_client_id',
                'base_url' => 'https://api-seller.ozon.ru'
            ],
            DataEnrichmentService::SOURCE_WILDBERRIES_API => [
                'api_key' => 'test_wb_key',
                'base_url' => 'https://suppliers-api.wildberries.ru'
            ],
            DataEnrichmentService::SOURCE_BARCODE_API => [
                'api_key' => 'test_barcode_key',
                'base_url' => 'https://api.barcodelookup.com'
            ]
        ];

        $this->enrichmentService = new DataEnrichmentService($this->mockSourceConfigs);
    }

    /**
     * Test basic product data enrichment
     */
    public function testEnrichProductData(): void
    {
        $productData = [
            'sku' => 'TEST_001',
            'name' => 'Test Product',
            'brand' => 'Test Brand'
        ];

        // Test with empty sources (should use all configured sources)
        $result = $this->enrichmentService->enrichProductData($productData);

        $this->assertArrayHasKey('enriched_data', $result);
        $this->assertArrayHasKey('enrichment_results', $result);
        $this->assertArrayHasKey('overall_status', $result);

        // Original data should be preserved
        $this->assertEquals($productData['sku'], $result['enriched_data']['sku']);
        $this->assertEquals($productData['name'], $result['enriched_data']['name']);
        $this->assertEquals($productData['brand'], $result['enriched_data']['brand']);

        // Should have results for each configured source
        $this->assertArrayHasKey(DataEnrichmentService::SOURCE_OZON_API, $result['enrichment_results']);
        $this->assertArrayHasKey(DataEnrichmentService::SOURCE_WILDBERRIES_API, $result['enrichment_results']);
        $this->assertArrayHasKey(DataEnrichmentService::SOURCE_BARCODE_API, $result['enrichment_results']);
    }

    /**
     * Test enrichment with specific sources
     */
    public function testEnrichProductDataWithSpecificSources(): void
    {
        $productData = [
            'sku' => 'TEST_001',
            'name' => 'Test Product'
        ];

        $sources = [DataEnrichmentService::SOURCE_OZON_API];
        $result = $this->enrichmentService->enrichProductData($productData, $sources);

        // Should only have results for specified source
        $this->assertArrayHasKey(DataEnrichmentService::SOURCE_OZON_API, $result['enrichment_results']);
        $this->assertArrayNotHasKey(DataEnrichmentService::SOURCE_WILDBERRIES_API, $result['enrichment_results']);
        $this->assertArrayNotHasKey(DataEnrichmentService::SOURCE_BARCODE_API, $result['enrichment_results']);
    }

    /**
     * Test enrichment with empty product data
     */
    public function testEnrichProductDataEmpty(): void
    {
        $productData = [];
        $result = $this->enrichmentService->enrichProductData($productData);

        $this->assertArrayHasKey('enriched_data', $result);
        $this->assertArrayHasKey('enrichment_results', $result);
        $this->assertArrayHasKey('overall_status', $result);

        // Should handle empty data gracefully
        $this->assertEquals(DataEnrichmentService::ENRICHMENT_FAILED, $result['overall_status']);
    }

    /**
     * Test source configuration
     */
    public function testSourceConfiguration(): void
    {
        $newConfigs = [
            DataEnrichmentService::SOURCE_OZON_API => [
                'api_key' => 'new_key',
                'client_id' => 'new_client_id',
                'base_url' => 'https://new-api.ozon.ru'
            ]
        ];

        $this->enrichmentService->setSourceConfigs($newConfigs);

        // Test that new configuration is used
        $productData = ['sku' => 'TEST_001'];
        $result = $this->enrichmentService->enrichProductData($productData, [DataEnrichmentService::SOURCE_OZON_API]);

        $this->assertArrayHasKey(DataEnrichmentService::SOURCE_OZON_API, $result['enrichment_results']);
    }

    /**
     * Test cache lifetime configuration
     */
    public function testCacheLifetimeConfiguration(): void
    {
        $newLifetime = 7200; // 2 hours
        $this->enrichmentService->setCacheLifetime($newLifetime);

        // Test that service accepts the new lifetime without error
        $productData = ['sku' => 'TEST_001'];
        $result = $this->enrichmentService->enrichProductData($productData);

        $this->assertIsArray($result);
    }

    /**
     * Test request timeout configuration
     */
    public function testRequestTimeoutConfiguration(): void
    {
        $newTimeout = 60; // 60 seconds
        $this->enrichmentService->setRequestTimeout($newTimeout);

        // Test that service accepts the new timeout without error
        $productData = ['sku' => 'TEST_001'];
        $result = $this->enrichmentService->enrichmentData($productData);

        $this->assertIsArray($result);
    }

    /**
     * Test enrichment with missing source configuration
     */
    public function testEnrichmentWithMissingSourceConfig(): void
    {
        // Create service with empty configuration
        $emptyService = new DataEnrichmentService([]);
        
        $productData = ['sku' => 'TEST_001'];
        $result = $emptyService->enrichProductData($productData);

        $this->assertEquals(DataEnrichmentService::ENRICHMENT_FAILED, $result['overall_status']);
        $this->assertEmpty($result['enrichment_results']);
    }

    /**
     * Test enrichment error handling
     */
    public function testEnrichmentErrorHandling(): void
    {
        // Test with invalid source configuration
        $invalidConfigs = [
            DataEnrichmentService::SOURCE_OZON_API => [
                // Missing required api_key and client_id
                'base_url' => 'https://api-seller.ozon.ru'
            ]
        ];

        $service = new DataEnrichmentService($invalidConfigs);
        $productData = ['sku' => 'TEST_001'];
        $result = $service->enrichProductData($productData, [DataEnrichmentService::SOURCE_OZON_API]);

        $this->assertArrayHasKey(DataEnrichmentService::SOURCE_OZON_API, $result['enrichment_results']);
        $this->assertEquals(
            DataEnrichmentService::ENRICHMENT_FAILED,
            $result['enrichment_results'][DataEnrichmentService::SOURCE_OZON_API]['status']
        );
        $this->assertArrayHasKey('error', $result['enrichment_results'][DataEnrichmentService::SOURCE_OZON_API]);
    }

    /**
     * Test overall status calculation
     */
    public function testOverallStatusCalculation(): void
    {
        // Mock a service that simulates different enrichment results
        $mockService = $this->createMockEnrichmentService();

        // Test all success
        $allSuccessResults = [
            DataEnrichmentService::SOURCE_OZON_API => ['status' => DataEnrichmentService::ENRICHMENT_SUCCESS],
            DataEnrichmentService::SOURCE_WILDBERRIES_API => ['status' => DataEnrichmentService::ENRICHMENT_SUCCESS]
        ];
        $status = $mockService->calculateOverallStatus($allSuccessResults);
        $this->assertEquals(DataEnrichmentService::ENRICHMENT_SUCCESS, $status);

        // Test partial success
        $partialResults = [
            DataEnrichmentService::SOURCE_OZON_API => ['status' => DataEnrichmentService::ENRICHMENT_SUCCESS],
            DataEnrichmentService::SOURCE_WILDBERRIES_API => ['status' => DataEnrichmentService::ENRICHMENT_FAILED]
        ];
        $status = $mockService->calculateOverallStatus($partialResults);
        $this->assertEquals(DataEnrichmentService::ENRICHMENT_PARTIAL, $status);

        // Test all failed
        $allFailedResults = [
            DataEnrichmentService::SOURCE_OZON_API => ['status' => DataEnrichmentService::ENRICHMENT_FAILED],
            DataEnrichmentService::SOURCE_WILDBERRIES_API => ['status' => DataEnrichmentService::ENRICHMENT_FAILED]
        ];
        $status = $mockService->calculateOverallStatus($allFailedResults);
        $this->assertEquals(DataEnrichmentService::ENRICHMENT_FAILED, $status);

        // Test with cached results
        $cachedResults = [
            DataEnrichmentService::SOURCE_OZON_API => ['status' => DataEnrichmentService::ENRICHMENT_CACHED],
            DataEnrichmentService::SOURCE_WILDBERRIES_API => ['status' => DataEnrichmentService::ENRICHMENT_SUCCESS]
        ];
        $status = $mockService->calculateOverallStatus($cachedResults);
        $this->assertEquals(DataEnrichmentService::ENRICHMENT_SUCCESS, $status);
    }

    /**
     * Test data mapping from different sources
     */
    public function testDataMappingFromSources(): void
    {
        $mockService = $this->createMockEnrichmentService();

        // Test Ozon API response mapping
        $ozonResponse = [
            'name' => 'Ozon Product Name',
            'brand' => 'Ozon Brand',
            'category_path' => 'Category > Subcategory',
            'description' => 'Product description',
            'images' => ['image1.jpg', 'image2.jpg'],
            'attributes' => ['color' => 'red', 'size' => 'L'],
            'weight' => 500,
            'dimensions' => ['length' => 10, 'width' => 5, 'height' => 3]
        ];

        $mapped = $mockService->mapOzonResponse($ozonResponse);
        
        $this->assertEquals('Ozon Product Name', $mapped['name']);
        $this->assertEquals('Ozon Brand', $mapped['brand']);
        $this->assertEquals('Category > Subcategory', $mapped['category']);
        $this->assertEquals('Product description', $mapped['description']);
        $this->assertIsArray($mapped['images']);
        $this->assertIsArray($mapped['attributes']);

        // Test Wildberries API response mapping
        $wbResponse = [
            'object' => 'WB Product Name',
            'brand' => 'WB Brand',
            'subjectName' => 'WB Category',
            'description' => 'WB description',
            'vendorCode' => 'WB123',
            'characteristics' => ['material' => 'cotton']
        ];

        $mapped = $mockService->mapWildberriesResponse($wbResponse);
        
        $this->assertEquals('WB Product Name', $mapped['name']);
        $this->assertEquals('WB Brand', $mapped['brand']);
        $this->assertEquals('WB Category', $mapped['category']);
        $this->assertEquals('WB123', $mapped['vendor_code']);

        // Test Barcode API response mapping
        $barcodeResponse = [
            'title' => 'Barcode Product',
            'brand' => 'Barcode Brand',
            'category' => 'Barcode Category',
            'description' => 'Barcode description',
            'upc' => '1234567890123'
        ];

        $mapped = $mockService->mapBarcodeResponse($barcodeResponse);
        
        $this->assertEquals('Barcode Product', $mapped['name']);
        $this->assertEquals('Barcode Brand', $mapped['brand']);
        $this->assertEquals('1234567890123', $mapped['barcode']);
    }

    /**
     * Test cache key generation
     */
    public function testCacheKeyGeneration(): void
    {
        $mockService = $this->createMockEnrichmentService();

        $productData1 = ['sku' => 'TEST_001', 'name' => 'Product 1'];
        $productData2 = ['sku' => 'TEST_002', 'name' => 'Product 2'];
        $productData3 = ['sku' => 'TEST_001', 'name' => 'Product 1']; // Same as productData1

        $key1 = $mockService->generateCacheKey($productData1, DataEnrichmentService::SOURCE_OZON_API);
        $key2 = $mockService->generateCacheKey($productData2, DataEnrichmentService::SOURCE_OZON_API);
        $key3 = $mockService->generateCacheKey($productData3, DataEnrichmentService::SOURCE_OZON_API);

        // Different products should have different keys
        $this->assertNotEquals($key1, $key2);
        
        // Same product data should have same key
        $this->assertEquals($key1, $key3);

        // Different sources should have different keys
        $key4 = $mockService->generateCacheKey($productData1, DataEnrichmentService::SOURCE_WILDBERRIES_API);
        $this->assertNotEquals($key1, $key4);
    }

    /**
     * Test default attributes for categories
     */
    public function testDefaultAttributesForCategory(): void
    {
        $mockService = $this->createMockEnrichmentService();

        // Test known category
        $attributes = $mockService->getDefaultAttributesForCategory('Смеси для выпечки');
        $this->assertIsArray($attributes);
        $this->assertArrayHasKey('type', $attributes);
        $this->assertEquals('dry_mix', $attributes['type']);

        // Test unknown category
        $attributes = $mockService->getDefaultAttributesForCategory('Unknown Category');
        $this->assertIsArray($attributes);
        $this->assertEmpty($attributes);
    }

    /**
     * Test performance with multiple enrichment sources
     */
    public function testPerformanceWithMultipleSources(): void
    {
        $productData = [
            'sku' => 'PERF_TEST_001',
            'name' => 'Performance Test Product',
            'brand' => 'Test Brand'
        ];

        $startTime = microtime(true);
        $result = $this->enrichmentService->enrichProductData($productData);
        $endTime = microtime(true);

        $executionTime = $endTime - $startTime;

        // Should complete within reasonable time (5 seconds for all sources)
        $this->assertLessThan(5.0, $executionTime, 'Enrichment should complete within reasonable time');
        $this->assertIsArray($result, 'Should return valid result');
    }

    /**
     * Test memory usage during enrichment
     */
    public function testMemoryUsageDuringEnrichment(): void
    {
        $initialMemory = memory_get_usage();

        // Process multiple products
        for ($i = 0; $i < 10; $i++) {
            $productData = [
                'sku' => "MEMORY_TEST_{$i}",
                'name' => "Memory Test Product {$i}",
                'brand' => 'Test Brand'
            ];

            $this->enrichmentService->enrichProductData($productData);
        }

        $finalMemory = memory_get_usage();
        $memoryIncrease = $finalMemory - $initialMemory;

        // Memory increase should be reasonable (less than 10MB)
        $this->assertLessThan(10 * 1024 * 1024, $memoryIncrease, 'Memory usage should be reasonable');
    }

    /**
     * Test concurrent enrichment requests
     */
    public function testConcurrentEnrichmentRequests(): void
    {
        $productDataSets = [
            ['sku' => 'CONCURRENT_001', 'name' => 'Product 1'],
            ['sku' => 'CONCURRENT_002', 'name' => 'Product 2'],
            ['sku' => 'CONCURRENT_003', 'name' => 'Product 3']
        ];

        $results = [];
        foreach ($productDataSets as $productData) {
            $results[] = $this->enrichmentService->enrichProductData($productData);
        }

        // All requests should complete successfully
        $this->assertCount(3, $results);
        
        foreach ($results as $result) {
            $this->assertArrayHasKey('enriched_data', $result);
            $this->assertArrayHasKey('enrichment_results', $result);
            $this->assertArrayHasKey('overall_status', $result);
        }
    }

    /**
     * Test edge cases with malformed data
     */
    public function testEdgeCasesWithMalformedData(): void
    {
        // Test with null values
        $productData = [
            'sku' => null,
            'name' => null,
            'brand' => null,
            'barcode' => null
        ];

        $result = $this->enrichmentService->enrichProductData($productData);
        $this->assertIsArray($result, 'Should handle null values gracefully');

        // Test with very long strings
        $longString = str_repeat('A', 10000);
        $productData = [
            'sku' => $longString,
            'name' => $longString,
            'brand' => $longString
        ];

        $result = $this->enrichmentService->enrichProductData($productData);
        $this->assertIsArray($result, 'Should handle very long strings');

        // Test with special characters
        $productData = [
            'sku' => '!@#$%^&*()_+{}|:"<>?[];\'\\,./`~',
            'name' => 'Тест 中文 العربية 🚀',
            'brand' => '©®™€£¥'
        ];

        $result = $this->enrichmentService->enrichProductData($productData);
        $this->assertIsArray($result, 'Should handle special characters');
    }

    /**
     * Test enrichment with different data types
     */
    public function testEnrichmentWithDifferentDataTypes(): void
    {
        // Test with numeric values
        $productData = [
            'sku' => 12345,
            'name' => 'Product Name',
            'price' => 99.99,
            'weight' => 500
        ];

        $result = $this->enrichmentService->enrichProductData($productData);
        $this->assertIsArray($result, 'Should handle numeric values');

        // Test with boolean values
        $productData = [
            'sku' => 'TEST_001',
            'name' => 'Product Name',
            'in_stock' => true,
            'discontinued' => false
        ];

        $result = $this->enrichmentService->enrichProductData($productData);
        $this->assertIsArray($result, 'Should handle boolean values');

        // Test with array values
        $productData = [
            'sku' => 'TEST_001',
            'name' => 'Product Name',
            'categories' => ['Category 1', 'Category 2'],
            'tags' => ['tag1', 'tag2', 'tag3']
        ];

        $result = $this->enrichmentService->enrichProductData($productData);
        $this->assertIsArray($result, 'Should handle array values');
    }

    /**
     * Create a mock enrichment service for testing internal methods
     */
    private function createMockEnrichmentService(): object
    {
        return new class extends DataEnrichmentService {
            public function calculateOverallStatus(array $results): string
            {
                return parent::calculateOverallStatus($results);
            }

            public function mapOzonResponse(array $product): array
            {
                return parent::mapOzonResponse($product);
            }

            public function mapWildberriesResponse(array $card): array
            {
                return parent::mapWildberriesResponse($card);
            }

            public function mapBarcodeResponse(array $product): array
            {
                return parent::mapBarcodeResponse($product);
            }

            public function generateCacheKey(array $productData, string $source): string
            {
                return parent::generateCacheKey($productData, $source);
            }

            public function getDefaultAttributesForCategory(string $category): array
            {
                return parent::getDefaultAttributesForCategory($category);
            }
        };
    }
}