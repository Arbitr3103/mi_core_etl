<?php

namespace Tests\Unit\Services;

use PHPUnit\Framework\TestCase;
use MDM\Services\MatchingScoreService;

/**
 * Unit tests for MatchingScoreService
 * 
 * Tests confidence score calculation, decision making, and scoring system
 */
class MatchingScoreServiceTest extends TestCase
{
    private MatchingScoreService $scoreService;

    protected function setUp(): void
    {
        parent::setUp();
        $this->scoreService = new MatchingScoreService();
    }

    /**
     * Test confidence score calculation with exact matches
     */
    public function testCalculateConfidenceScoreExactMatches(): void
    {
        // Test exact SKU match
        $matchDetails = [
            'exact_sku_match' => true,
            'exact_barcode_match' => false,
            'name_similarity' => 0.5,
            'brand_category_match' => 0.8
        ];

        $score = $this->scoreService->calculateConfidenceScore($matchDetails);
        $this->assertEquals(1.0, $score, 'Exact SKU match should return 1.0');

        // Test exact barcode match
        $matchDetails = [
            'exact_sku_match' => false,
            'exact_barcode_match' => true,
            'name_similarity' => 0.3,
            'brand_category_match' => 0.2
        ];

        $score = $this->scoreService->calculateConfidenceScore($matchDetails);
        $this->assertEquals(1.0, $score, 'Exact barcode match should return 1.0');
    }

    /**
     * Test confidence score calculation with fuzzy matches
     */
    public function testCalculateConfidenceScoreFuzzyMatches(): void
    {
        // Test high similarity match
        $matchDetails = [
            'exact_sku_match' => false,
            'exact_barcode_match' => false,
            'name_similarity' => 0.9,
            'brand_category_match' => 0.8,
            'product_brand' => 'TestBrand',
            'master_brand' => 'TestBrand'
        ];

        $score = $this->scoreService->calculateConfidenceScore($matchDetails);
        $this->assertGreaterThan(0.7, $score, 'High similarity should give high confidence');
        $this->assertLessThan(1.0, $score, 'Fuzzy match should be less than 1.0');

        // Test medium similarity match
        $matchDetails = [
            'exact_sku_match' => false,
            'exact_barcode_match' => false,
            'name_similarity' => 0.6,
            'brand_category_match' => 0.5
        ];

        $score = $this->scoreService->calculateConfidenceScore($matchDetails);
        $this->assertGreaterThan(0.3, $score, 'Medium similarity should give medium confidence');
        $this->assertLessThan(0.8, $score, 'Medium similarity should not be too high');

        // Test low similarity match
        $matchDetails = [
            'exact_sku_match' => false,
            'exact_barcode_match' => false,
            'name_similarity' => 0.2,
            'brand_category_match' => 0.1
        ];

        $score = $this->scoreService->calculateConfidenceScore($matchDetails);
        $this->assertLessThan(0.4, $score, 'Low similarity should give low confidence');
    }

    /**
     * Test confidence score with no matches
     */
    public function testCalculateConfidenceScoreNoMatches(): void
    {
        $matchDetails = [
            'exact_sku_match' => false,
            'exact_barcode_match' => false,
            'name_similarity' => 0.0,
            'brand_category_match' => 0.0
        ];

        $score = $this->scoreService->calculateConfidenceScore($matchDetails);
        $this->assertEquals(0.0, $score, 'No matches should return 0.0');
    }

    /**
     * Test decision making based on confidence scores
     */
    public function testMakeDecision(): void
    {
        // Test auto accept decision
        $decision = $this->scoreService->makeDecision(0.95);
        $this->assertEquals(MatchingScoreService::DECISION_AUTO_ACCEPT, $decision);

        $decision = $this->scoreService->makeDecision(0.90);
        $this->assertEquals(MatchingScoreService::DECISION_AUTO_ACCEPT, $decision);

        // Test manual review decision
        $decision = $this->scoreService->makeDecision(0.85);
        $this->assertEquals(MatchingScoreService::DECISION_MANUAL_REVIEW, $decision);

        $decision = $this->scoreService->makeDecision(0.70);
        $this->assertEquals(MatchingScoreService::DECISION_MANUAL_REVIEW, $decision);

        // Test auto reject decision
        $decision = $this->scoreService->makeDecision(0.50);
        $this->assertEquals(MatchingScoreService::DECISION_AUTO_REJECT, $decision);

        $decision = $this->scoreService->makeDecision(0.30);
        $this->assertEquals(MatchingScoreService::DECISION_AUTO_REJECT, $decision);

        // Test create new decision
        $decision = $this->scoreService->makeDecision(0.20);
        $this->assertEquals(MatchingScoreService::DECISION_CREATE_NEW, $decision);

        $decision = $this->scoreService->makeDecision(0.0);
        $this->assertEquals(MatchingScoreService::DECISION_CREATE_NEW, $decision);
    }

    /**
     * Test threshold configuration
     */
    public function testThresholdConfiguration(): void
    {
        $originalThresholds = $this->scoreService->getThresholds();
        $this->assertIsArray($originalThresholds);

        // Test setting new thresholds
        $newThresholds = [
            'auto_accept' => 0.95,
            'manual_review' => 0.75,
            'auto_reject' => 0.40
        ];

        $this->scoreService->setThresholds($newThresholds);
        $updatedThresholds = $this->scoreService->getThresholds();

        $this->assertEquals(0.95, $updatedThresholds['auto_accept']);
        $this->assertEquals(0.75, $updatedThresholds['manual_review']);
        $this->assertEquals(0.40, $updatedThresholds['auto_reject']);

        // Test decision making with new thresholds
        $decision = $this->scoreService->makeDecision(0.80);
        $this->assertEquals(MatchingScoreService::DECISION_MANUAL_REVIEW, $decision);

        $decision = $this->scoreService->makeDecision(0.96);
        $this->assertEquals(MatchingScoreService::DECISION_AUTO_ACCEPT, $decision);
    }

    /**
     * Test score weights configuration
     */
    public function testScoreWeightsConfiguration(): void
    {
        $originalWeights = $this->scoreService->getScoreWeights();
        $this->assertIsArray($originalWeights);

        // Test setting new weights
        $newWeights = [
            'name_similarity' => 0.6,
            'brand_match' => 0.4
        ];

        $this->scoreService->setScoreWeights($newWeights);
        $updatedWeights = $this->scoreService->getScoreWeights();

        $this->assertEquals(0.6, $updatedWeights['name_similarity']);
        $this->assertEquals(0.4, $updatedWeights['brand_match']);
    }

    /**
     * Test processing matching results
     */
    public function testProcessMatchingResult(): void
    {
        $productData = [
            'sku' => 'TEST_001',
            'name' => 'Test Product',
            'brand' => 'Test Brand'
        ];

        $mockMasterProduct = $this->createMockMasterProduct('MASTER_001');

        $matches = [
            [
                'master_product' => $mockMasterProduct,
                'match_details' => [
                    'exact_sku_match' => false,
                    'exact_barcode_match' => false,
                    'name_similarity' => 0.85,
                    'brand_category_match' => 0.9,
                    'product_brand' => 'Test Brand',
                    'master_brand' => 'Test Brand'
                ]
            ]
        ];

        $results = $this->scoreService->processMatchingResult($productData, $matches);

        $this->assertNotEmpty($results);
        $this->assertArrayHasKey('master_product_id', $results[0]);
        $this->assertArrayHasKey('confidence_score', $results[0]);
        $this->assertArrayHasKey('decision', $results[0]);
        $this->assertArrayHasKey('match_details', $results[0]);
        $this->assertArrayHasKey('reasoning', $results[0]);

        $this->assertEquals('MASTER_001', $results[0]['master_product_id']);
        $this->assertGreaterThan(0.7, $results[0]['confidence_score']);
    }

    /**
     * Test processing multiple matches with sorting
     */
    public function testProcessMultipleMatchesWithSorting(): void
    {
        $productData = ['sku' => 'TEST_001', 'name' => 'Test Product'];

        $mockMasterProduct1 = $this->createMockMasterProduct('MASTER_001');
        $mockMasterProduct2 = $this->createMockMasterProduct('MASTER_002');

        $matches = [
            [
                'master_product' => $mockMasterProduct1,
                'match_details' => [
                    'exact_sku_match' => false,
                    'exact_barcode_match' => false,
                    'name_similarity' => 0.6,
                    'brand_category_match' => 0.5
                ]
            ],
            [
                'master_product' => $mockMasterProduct2,
                'match_details' => [
                    'exact_sku_match' => false,
                    'exact_barcode_match' => false,
                    'name_similarity' => 0.9,
                    'brand_category_match' => 0.8
                ]
            ]
        ];

        $results = $this->scoreService->processMatchingResult($productData, $matches);

        $this->assertCount(2, $results);
        
        // Results should be sorted by confidence score (descending)
        $this->assertGreaterThan($results[1]['confidence_score'], $results[0]['confidence_score']);
        $this->assertEquals('MASTER_002', $results[0]['master_product_id']);
        $this->assertEquals('MASTER_001', $results[1]['master_product_id']);
    }

    /**
     * Test decision statistics calculation
     */
    public function testGetDecisionStatistics(): void
    {
        $results = [
            ['decision' => MatchingScoreService::DECISION_AUTO_ACCEPT],
            ['decision' => MatchingScoreService::DECISION_AUTO_ACCEPT],
            ['decision' => MatchingScoreService::DECISION_MANUAL_REVIEW],
            ['decision' => MatchingScoreService::DECISION_AUTO_REJECT],
            ['decision' => MatchingScoreService::DECISION_CREATE_NEW]
        ];

        $stats = $this->scoreService->getDecisionStatistics($results);

        $this->assertArrayHasKey('counts', $stats);
        $this->assertArrayHasKey('percentages', $stats);
        $this->assertArrayHasKey('total', $stats);

        $this->assertEquals(5, $stats['total']);
        $this->assertEquals(2, $stats['counts'][MatchingScoreService::DECISION_AUTO_ACCEPT]);
        $this->assertEquals(1, $stats['counts'][MatchingScoreService::DECISION_MANUAL_REVIEW]);
        $this->assertEquals(1, $stats['counts'][MatchingScoreService::DECISION_AUTO_REJECT]);
        $this->assertEquals(1, $stats['counts'][MatchingScoreService::DECISION_CREATE_NEW]);

        $this->assertEquals(40.0, $stats['percentages'][MatchingScoreService::DECISION_AUTO_ACCEPT]);
        $this->assertEquals(20.0, $stats['percentages'][MatchingScoreService::DECISION_MANUAL_REVIEW]);
    }

    /**
     * Test edge cases and error handling
     */
    public function testEdgeCases(): void
    {
        // Test with empty match details
        $score = $this->scoreService->calculateConfidenceScore([]);
        $this->assertEquals(0.0, $score, 'Empty match details should return 0.0');

        // Test with missing keys
        $matchDetails = [
            'exact_sku_match' => false,
            'exact_barcode_match' => false
            // Missing name_similarity and brand_category_match
        ];
        $score = $this->scoreService->calculateConfidenceScore($matchDetails);
        $this->assertEquals(0.0, $score, 'Missing keys should return 0.0');

        // Test with null values
        $matchDetails = [
            'exact_sku_match' => false,
            'exact_barcode_match' => false,
            'name_similarity' => null,
            'brand_category_match' => null
        ];
        $score = $this->scoreService->calculateConfidenceScore($matchDetails);
        $this->assertEquals(0.0, $score, 'Null values should return 0.0');

        // Test decision statistics with empty results
        $stats = $this->scoreService->getDecisionStatistics([]);
        $this->assertEquals(0, $stats['total']);
        $this->assertEquals(0.0, $stats['percentages'][MatchingScoreService::DECISION_AUTO_ACCEPT]);
    }

    /**
     * Test score adjustments for edge cases
     */
    public function testScoreAdjustments(): void
    {
        // Test penalty for very short product names
        $matchDetails = [
            'exact_sku_match' => false,
            'exact_barcode_match' => false,
            'name_similarity' => 0.8,
            'brand_category_match' => 0.7,
            'product_name' => 'AB', // Very short name
            'product_brand' => 'TestBrand',
            'master_brand' => 'TestBrand'
        ];

        $score = $this->scoreService->calculateConfidenceScore($matchDetails);
        
        // Should be penalized for short name
        $this->assertLessThan(0.6, $score, 'Very short names should be penalized');

        // Test bonus for brand and category match
        $matchDetails = [
            'exact_sku_match' => false,
            'exact_barcode_match' => false,
            'name_similarity' => 0.7,
            'brand_category_match' => 0.9,
            'product_name' => 'Normal length product name',
            'product_brand' => 'TestBrand',
            'master_brand' => 'TestBrand'
        ];

        $score = $this->scoreService->calculateConfidenceScore($matchDetails);
        
        // Should get bonus for good brand/category match
        $this->assertGreaterThan(0.6, $score, 'Good brand/category match should get bonus');
    }

    /**
     * Test brand score calculation
     */
    public function testBrandScoreCalculation(): void
    {
        // Test exact brand match
        $matchDetails = [
            'exact_sku_match' => false,
            'exact_barcode_match' => false,
            'name_similarity' => 0.5,
            'brand_category_match' => 1.0,
            'product_brand' => 'TestBrand',
            'master_brand' => 'TestBrand'
        ];

        $score = $this->scoreService->calculateConfidenceScore($matchDetails);
        $this->assertGreaterThan(0.4, $score, 'Exact brand match should contribute significantly');

        // Test case insensitive brand match
        $matchDetails = [
            'exact_sku_match' => false,
            'exact_barcode_match' => false,
            'name_similarity' => 0.5,
            'brand_category_match' => 1.0,
            'product_brand' => 'testbrand',
            'master_brand' => 'TestBrand'
        ];

        $score = $this->scoreService->calculateConfidenceScore($matchDetails);
        $this->assertGreaterThan(0.4, $score, 'Case insensitive brand match should work');

        // Test empty brand handling
        $matchDetails = [
            'exact_sku_match' => false,
            'exact_barcode_match' => false,
            'name_similarity' => 0.5,
            'brand_category_match' => 0.0,
            'product_brand' => '',
            'master_brand' => 'TestBrand'
        ];

        $score = $this->scoreService->calculateConfidenceScore($matchDetails);
        $this->assertLessThan(0.4, $score, 'Empty brand should not contribute');
    }

    /**
     * Test performance with large number of matches
     */
    public function testPerformanceWithLargeMatches(): void
    {
        $productData = ['sku' => 'TEST_001', 'name' => 'Test Product'];
        
        // Create large number of matches
        $matches = [];
        for ($i = 0; $i < 1000; $i++) {
            $mockMasterProduct = $this->createMockMasterProduct("MASTER_{$i}");
            $matches[] = [
                'master_product' => $mockMasterProduct,
                'match_details' => [
                    'exact_sku_match' => false,
                    'exact_barcode_match' => false,
                    'name_similarity' => rand(0, 100) / 100,
                    'brand_category_match' => rand(0, 100) / 100
                ]
            ];
        }

        $startTime = microtime(true);
        $results = $this->scoreService->processMatchingResult($productData, $matches);
        $endTime = microtime(true);

        $executionTime = $endTime - $startTime;

        $this->assertLessThan(2.0, $executionTime, 'Processing should be fast even with many matches');
        $this->assertCount(1000, $results, 'Should process all matches');
        
        // Verify sorting is maintained
        for ($i = 1; $i < count($results); $i++) {
            $this->assertGreaterThanOrEqual(
                $results[$i]['confidence_score'],
                $results[$i - 1]['confidence_score'],
                'Results should remain sorted by confidence score'
            );
        }
    }

    /**
     * Test boundary values for confidence scores
     */
    public function testConfidenceScoreBoundaries(): void
    {
        // Test maximum possible score (without exact match)
        $matchDetails = [
            'exact_sku_match' => false,
            'exact_barcode_match' => false,
            'name_similarity' => 1.0,
            'brand_category_match' => 1.0,
            'attributes_similarity' => 1.0,
            'product_name' => 'Long enough product name',
            'product_brand' => 'TestBrand',
            'master_brand' => 'TestBrand'
        ];

        $score = $this->scoreService->calculateConfidenceScore($matchDetails);
        $this->assertLessThanOrEqual(1.0, $score, 'Score should not exceed 1.0');
        $this->assertGreaterThan(0.8, $score, 'Perfect fuzzy match should be very high');

        // Test minimum possible score
        $matchDetails = [
            'exact_sku_match' => false,
            'exact_barcode_match' => false,
            'name_similarity' => 0.0,
            'brand_category_match' => 0.0,
            'attributes_similarity' => 0.0
        ];

        $score = $this->scoreService->calculateConfidenceScore($matchDetails);
        $this->assertGreaterThanOrEqual(0.0, $score, 'Score should not be negative');
        $this->assertEquals(0.0, $score, 'No match should give 0.0 score');
    }

    /**
     * Create a mock master product for testing
     */
    private function createMockMasterProduct(string $masterId): object
    {
        $mock = $this->createMock(\MDM\Models\MasterProduct::class);
        $mock->method('getMasterId')->willReturn($masterId);
        return $mock;
    }
}