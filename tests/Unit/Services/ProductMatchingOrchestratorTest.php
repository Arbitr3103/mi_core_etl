<?php

namespace Tests\Unit\Services;

use PHPUnit\Framework\TestCase;
use MDM\Services\ProductMatchingOrchestrator;
use MDM\Services\MatchingEngine;
use MDM\Services\MatchingScoreService;
use MDM\Services\DataEnrichmentService;
use MDM\Repositories\MasterProductRepository;
use MDM\Repositories\SkuMappingRepository;
use MDM\Models\MasterProduct;

/**
 * Unit tests for ProductMatchingOrchestrator
 * 
 * Tests the orchestration of matching, scoring, and enrichment processes
 */
class ProductMatchingOrchestratorTest extends TestCase
{
    private ProductMatchingOrchestrator $orchestrator;
    private MatchingEngine $mockMatchingEngine;
    private MatchingScoreService $mockScoreService;
    private DataEnrichmentService $mockEnrichmentService;
    private MasterProductRepository $mockMasterProductRepository;
    private SkuMappingRepository $mockSkuMappingRepository;

    protected function setUp(): void
    {
        parent::setUp();

        $this->mockMatchingEngine = $this->createMock(MatchingEngine::class);
        $this->mockScoreService = $this->createMock(MatchingScoreService::class);
        $this->mockEnrichmentService = $this->createMock(DataEnrichmentService::class);
        $this->mockMasterProductRepository = $this->createMock(MasterProductRepository::class);
        $this->mockSkuMappingRepository = $this->createMock(SkuMappingRepository::class);

        $this->orchestrator = new ProductMatchingOrchestrator(
            $this->mockMatchingEngine,
            $this->mockScoreService,
            $this->mockEnrichmentService,
            $this->mockMasterProductRepository,
            $this->mockSkuMappingRepository
        );
    }

    /**
     * Test successful product processing with auto accept decision
     */
    public function testProcessNewProductAutoAccept(): void
    {
        $productData = [
            'sku' => 'TEST_001',
            'name' => 'Test Product',
            'brand' => 'Test Brand',
            'category' => 'Test Category'
        ];

        // Mock enrichment service
        $enrichedData = array_merge($productData, ['description' => 'Enriched description']);
        $enrichmentResult = [
            'enriched_data' => $enrichedData,
            'enrichment_results' => [
                'ozon_api' => ['status' => 'success', 'data' => ['description' => 'Enriched description']]
            ],
            'overall_status' => 'success'
        ];

        $this->mockEnrichmentService
            ->expects($this->once())
            ->method('enrichProductData')
            ->with($productData)
            ->willReturn($enrichmentResult);

        // Mock master product repository
        $masterProducts = [$this->createMockMasterProduct('MASTER_001')];
        $this->mockMasterProductRepository
            ->expects($this->once())
            ->method('findAll')
            ->willReturn($masterProducts);

        // Mock matching engine
        $matches = [
            [
                'master_product' => $masterProducts[0],
                'score' => 0.95,
                'match_details' => [
                    'exact_sku_match' => false,
                    'exact_barcode_match' => true,
                    'name_similarity' => 0.9,
                    'brand_category_match' => 0.8
                ]
            ]
        ];

        $this->mockMatchingEngine
            ->expects($this->once())
            ->method('findMatches')
            ->with($enrichedData, $masterProducts)
            ->willReturn($matches);

        // Mock score service
        $scoringResults = [
            [
                'master_product_id' => 'MASTER_001',
                'confidence_score' => 0.95,
                'decision' => MatchingScoreService::DECISION_AUTO_ACCEPT,
                'match_details' => $matches[0]['match_details'],
                'reasoning' => 'High confidence match'
            ]
        ];

        $this->mockScoreService
            ->expects($this->once())
            ->method('processMatchingResult')
            ->with($enrichedData, $matches)
            ->willReturn($scoringResults);

        // Execute test
        $result = $this->orchestrator->processNewProduct($productData);

        // Verify result structure
        $this->assertArrayHasKey('product_data', $result);
        $this->assertArrayHasKey('enrichment_result', $result);
        $this->assertArrayHasKey('matches', $result);
        $this->assertArrayHasKey('final_decision', $result);
        $this->assertArrayHasKey('processing_summary', $result);

        // Verify final decision
        $this->assertEquals(MatchingScoreService::DECISION_AUTO_ACCEPT, $result['final_decision']['action']);
        $this->assertEquals('MASTER_001', $result['final_decision']['master_product_id']);
        $this->assertEquals(0.95, $result['final_decision']['confidence_score']);

        // Verify processing summary
        $this->assertEquals('success', $result['processing_summary']['enrichment_status']);
        $this->assertEquals(1, $result['processing_summary']['matches_found']);
        $this->assertEquals(0.95, $result['processing_summary']['best_confidence_score']);
        $this->assertFalse($result['processing_summary']['requires_manual_review']);
    }

    /**
     * Test product processing with manual review decision
     */
    public function testProcessNewProductManualReview(): void
    {
        $productData = [
            'sku' => 'TEST_002',
            'name' => 'Ambiguous Product',
            'brand' => 'Test Brand'
        ];

        // Setup mocks for manual review scenario
        $this->setupBasicMocks($productData);

        $scoringResults = [
            [
                'master_product_id' => 'MASTER_001',
                'confidence_score' => 0.75,
                'decision' => MatchingScoreService::DECISION_MANUAL_REVIEW,
                'match_details' => [],
                'reasoning' => 'Medium confidence - requires manual review'
            ]
        ];

        $this->mockScoreService
            ->method('processMatchingResult')
            ->willReturn($scoringResults);

        $result = $this->orchestrator->processNewProduct($productData);

        $this->assertEquals(MatchingScoreService::DECISION_MANUAL_REVIEW, $result['final_decision']['action']);
        $this->assertTrue($result['processing_summary']['requires_manual_review']);
    }

    /**
     * Test product processing with create new decision
     */
    public function testProcessNewProductCreateNew(): void
    {
        $productData = [
            'sku' => 'TEST_003',
            'name' => 'Unique Product',
            'brand' => 'New Brand'
        ];

        // Setup mocks for no matches scenario
        $this->setupBasicMocks($productData, []);

        $result = $this->orchestrator->processNewProduct($productData);

        $this->assertEquals(MatchingScoreService::DECISION_CREATE_NEW, $result['final_decision']['action']);
        $this->assertNull($result['final_decision']['master_product_id']);
        $this->assertEquals(0.0, $result['final_decision']['confidence_score']);
        $this->assertEquals('Не найдено потенциальных совпадений', $result['final_decision']['reasoning']);
    }

    /**
     * Test batch processing functionality
     */
    public function testProcessBatch(): void
    {
        $productsData = [
            [
                'sku' => 'BATCH_001',
                'name' => 'Batch Product 1',
                'brand' => 'Brand A'
            ],
            [
                'sku' => 'BATCH_002',
                'name' => 'Batch Product 2',
                'brand' => 'Brand B'
            ],
            [
                'sku' => 'BATCH_003',
                'name' => 'Batch Product 3',
                'brand' => 'Brand C'
            ]
        ];

        // Setup mocks to return different decisions for each product
        $this->setupBatchMocks($productsData);

        $result = $this->orchestrator->processBatch($productsData);

        // Verify batch result structure
        $this->assertArrayHasKey('results', $result);
        $this->assertArrayHasKey('statistics', $result);
        $this->assertArrayHasKey('batch_summary', $result);

        // Verify statistics
        $this->assertEquals(3, $result['statistics']['total_processed']);
        $this->assertEquals(0, $result['statistics']['processing_errors']);

        // Verify batch summary
        $this->assertEquals(3, $result['batch_summary']['total_processed']);
        $this->assertEquals(100.0, $result['batch_summary']['success_rate']);
        $this->assertArrayHasKey('processing_timestamp', $result['batch_summary']);
    }

    /**
     * Test batch processing with errors
     */
    public function testProcessBatchWithErrors(): void
    {
        $productsData = [
            [
                'sku' => 'VALID_001',
                'name' => 'Valid Product'
            ],
            [
                // Invalid product data that will cause an exception
                'invalid_field' => 'invalid_value'
            ]
        ];

        // Setup mocks to throw exception for second product
        $this->setupBatchMocksWithError($productsData);

        $result = $this->orchestrator->processBatch($productsData);

        $this->assertEquals(1, $result['statistics']['total_processed']);
        $this->assertEquals(1, $result['statistics']['processing_errors']);
        $this->assertArrayHasKey('error', $result['results'][1]);
    }

    /**
     * Test getting pending manual review items
     */
    public function testGetPendingManualReview(): void
    {
        $pendingItems = [
            ['sku' => 'PENDING_001', 'status' => 'pending_review'],
            ['sku' => 'PENDING_002', 'status' => 'pending_review']
        ];

        $this->mockSkuMappingRepository
            ->expects($this->once())
            ->method('findPendingReview')
            ->with(50)
            ->willReturn($pendingItems);

        $result = $this->orchestrator->getPendingManualReview();

        $this->assertCount(2, $result);
        $this->assertEquals('PENDING_001', $result[0]['sku']);
        $this->assertEquals('PENDING_002', $result[1]['sku']);
    }

    /**
     * Test confirming matching
     */
    public function testConfirmMatching(): void
    {
        $externalSku = 'TEST_SKU_001';
        $masterId = 'MASTER_001';

        $this->mockSkuMappingRepository
            ->expects($this->once())
            ->method('updateVerificationStatus')
            ->with($externalSku, $masterId, 'manual')
            ->willReturn(true);

        $result = $this->orchestrator->confirmMatching($externalSku, $masterId);

        $this->assertTrue($result);
    }

    /**
     * Test confirming matching with error
     */
    public function testConfirmMatchingWithError(): void
    {
        $externalSku = 'TEST_SKU_001';
        $masterId = 'MASTER_001';

        $this->mockSkuMappingRepository
            ->expects($this->once())
            ->method('updateVerificationStatus')
            ->with($externalSku, $masterId, 'manual')
            ->willThrowException(new \Exception('Database error'));

        $result = $this->orchestrator->confirmMatching($externalSku, $masterId);

        $this->assertFalse($result);
    }

    /**
     * Test rejecting match and creating new master product
     */
    public function testRejectAndCreateNew(): void
    {
        $externalSku = 'TEST_SKU_002';
        $productData = [
            'name' => 'New Product',
            'brand' => 'New Brand',
            'category' => 'New Category',
            'description' => 'New product description',
            'attributes' => ['color' => 'blue'],
            'source' => 'ozon'
        ];

        $newMasterId = 'MASTER_NEW_001';

        $this->mockMasterProductRepository
            ->expects($this->once())
            ->method('save')
            ->willReturn($newMasterId);

        $this->mockSkuMappingRepository
            ->expects($this->once())
            ->method('create')
            ->with([
                'master_id' => $newMasterId,
                'external_sku' => $externalSku,
                'source' => 'ozon',
                'verification_status' => 'manual'
            ])
            ->willReturn(true);

        $result = $this->orchestrator->rejectAndCreateNew($externalSku, $productData);

        $this->assertEquals($newMasterId, $result);
    }

    /**
     * Test rejecting match with error
     */
    public function testRejectAndCreateNewWithError(): void
    {
        $externalSku = 'TEST_SKU_003';
        $productData = ['name' => 'Test Product'];

        $this->mockMasterProductRepository
            ->expects($this->once())
            ->method('save')
            ->willThrowException(new \Exception('Save error'));

        $result = $this->orchestrator->rejectAndCreateNew($externalSku, $productData);

        $this->assertNull($result);
    }

    /**
     * Test performance with large batch
     */
    public function testPerformanceWithLargeBatch(): void
    {
        // Create large batch of products
        $largeBatch = [];
        for ($i = 0; $i < 100; $i++) {
            $largeBatch[] = [
                'sku' => "PERF_TEST_{$i}",
                'name' => "Performance Test Product {$i}",
                'brand' => 'Test Brand'
            ];
        }

        // Setup basic mocks for performance test
        $this->setupPerformanceMocks();

        $startTime = microtime(true);
        $result = $this->orchestrator->processBatch($largeBatch);
        $endTime = microtime(true);

        $executionTime = $endTime - $startTime;

        // Should process 100 products within reasonable time (10 seconds)
        $this->assertLessThan(10.0, $executionTime, 'Batch processing should be performant');
        $this->assertEquals(100, $result['statistics']['total_processed']);
    }

    /**
     * Test memory usage during batch processing
     */
    public function testMemoryUsageDuringBatchProcessing(): void
    {
        $initialMemory = memory_get_usage();

        // Create medium batch
        $batch = [];
        for ($i = 0; $i < 50; $i++) {
            $batch[] = [
                'sku' => "MEMORY_TEST_{$i}",
                'name' => "Memory Test Product {$i}",
                'brand' => 'Test Brand'
            ];
        }

        $this->setupPerformanceMocks();
        $this->orchestrator->processBatch($batch);

        $finalMemory = memory_get_usage();
        $memoryIncrease = $finalMemory - $initialMemory;

        // Memory increase should be reasonable (less than 20MB)
        $this->assertLessThan(20 * 1024 * 1024, $memoryIncrease, 'Memory usage should be reasonable');
    }

    /**
     * Test concurrent processing scenarios
     */
    public function testConcurrentProcessing(): void
    {
        $productDataSets = [
            ['sku' => 'CONCURRENT_001', 'name' => 'Product 1'],
            ['sku' => 'CONCURRENT_002', 'name' => 'Product 2'],
            ['sku' => 'CONCURRENT_003', 'name' => 'Product 3']
        ];

        $this->setupBasicMocks($productDataSets[0]);

        $results = [];
        foreach ($productDataSets as $productData) {
            $results[] = $this->orchestrator->processNewProduct($productData);
        }

        // All results should be independent and valid
        $this->assertCount(3, $results);
        
        foreach ($results as $result) {
            $this->assertArrayHasKey('final_decision', $result);
            $this->assertArrayHasKey('processing_summary', $result);
        }
    }

    /**
     * Test edge cases and error handling
     */
    public function testEdgeCasesAndErrorHandling(): void
    {
        // Test with empty product data
        $emptyProductData = [];
        
        $this->setupBasicMocks($emptyProductData);
        $result = $this->orchestrator->processNewProduct($emptyProductData);
        
        $this->assertIsArray($result, 'Should handle empty product data gracefully');

        // Test with null values
        $nullProductData = [
            'sku' => null,
            'name' => null,
            'brand' => null
        ];

        $this->setupBasicMocks($nullProductData);
        $result = $this->orchestrator->processNewProduct($nullProductData);
        
        $this->assertIsArray($result, 'Should handle null values gracefully');
    }

    /**
     * Setup basic mocks for testing
     */
    private function setupBasicMocks(array $productData, array $matches = null): void
    {
        // Default enrichment result
        $enrichmentResult = [
            'enriched_data' => $productData,
            'enrichment_results' => [],
            'overall_status' => 'success'
        ];

        $this->mockEnrichmentService
            ->method('enrichProductData')
            ->willReturn($enrichmentResult);

        // Default master products
        $masterProducts = [$this->createMockMasterProduct('MASTER_001')];
        $this->mockMasterProductRepository
            ->method('findAll')
            ->willReturn($masterProducts);

        // Default matches
        if ($matches === null) {
            $matches = [
                [
                    'master_product' => $masterProducts[0],
                    'score' => 0.8,
                    'match_details' => []
                ]
            ];
        }

        $this->mockMatchingEngine
            ->method('findMatches')
            ->willReturn($matches);

        // Default scoring results
        $scoringResults = [];
        if (!empty($matches)) {
            $scoringResults = [
                [
                    'master_product_id' => 'MASTER_001',
                    'confidence_score' => 0.8,
                    'decision' => MatchingScoreService::DECISION_AUTO_ACCEPT,
                    'match_details' => [],
                    'reasoning' => 'Good match'
                ]
            ];
        }

        $this->mockScoreService
            ->method('processMatchingResult')
            ->willReturn($scoringResults);
    }

    /**
     * Setup mocks for batch processing
     */
    private function setupBatchMocks(array $productsData): void
    {
        $this->mockEnrichmentService
            ->method('enrichProductData')
            ->willReturnCallback(function($productData) {
                return [
                    'enriched_data' => $productData,
                    'enrichment_results' => [],
                    'overall_status' => 'success'
                ];
            });

        $this->mockMasterProductRepository
            ->method('findAll')
            ->willReturn([$this->createMockMasterProduct('MASTER_001')]);

        $this->mockMatchingEngine
            ->method('findMatches')
            ->willReturn([]);

        $this->mockScoreService
            ->method('processMatchingResult')
            ->willReturn([]);
    }

    /**
     * Setup mocks for batch processing with errors
     */
    private function setupBatchMocksWithError(array $productsData): void
    {
        $this->mockEnrichmentService
            ->method('enrichProductData')
            ->willReturnCallback(function($productData) {
                if (isset($productData['invalid_field'])) {
                    throw new \Exception('Invalid product data');
                }
                return [
                    'enriched_data' => $productData,
                    'enrichment_results' => [],
                    'overall_status' => 'success'
                ];
            });

        $this->mockMasterProductRepository
            ->method('findAll')
            ->willReturn([]);

        $this->mockMatchingEngine
            ->method('findMatches')
            ->willReturn([]);

        $this->mockScoreService
            ->method('processMatchingResult')
            ->willReturn([]);
    }

    /**
     * Setup mocks for performance testing
     */
    private function setupPerformanceMocks(): void
    {
        $this->mockEnrichmentService
            ->method('enrichProductData')
            ->willReturnCallback(function($productData) {
                return [
                    'enriched_data' => $productData,
                    'enrichment_results' => [],
                    'overall_status' => 'success'
                ];
            });

        $this->mockMasterProductRepository
            ->method('findAll')
            ->willReturn([]);

        $this->mockMatchingEngine
            ->method('findMatches')
            ->willReturn([]);

        $this->mockScoreService
            ->method('processMatchingResult')
            ->willReturn([]);
    }

    /**
     * Create a mock master product
     */
    private function createMockMasterProduct(string $masterId): MasterProduct
    {
        return new MasterProduct(
            $masterId,
            'Test Master Product',
            'Test Brand',
            'Test Category'
        );
    }
}