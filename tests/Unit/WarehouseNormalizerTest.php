<?php
/**
 * Unit Tests for WarehouseNormalizer
 * 
 * Tests for the Warehouse Normalizer service including normalization rules,
 * fuzzy matching, auto rules, and database integration.
 * 
 * Task: 4.3 Создать WarehouseNormalizer сервис (tests)
 */

require_once __DIR__ . '/../../src/Services/WarehouseNormalizer.php';

class WarehouseNormalizerTest extends PHPUnit\Framework\TestCase {
    private WarehouseNormalizer $normalizer;
    private PDO $mockPdo;
    
    protected function setUp(): void {
        // Create mock PDO for testing
        $this->mockPdo = new PDO('sqlite::memory:');
        
        // Create test table
        $this->mockPdo->exec("
            CREATE TABLE warehouse_normalization (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                original_name VARCHAR(255) NOT NULL,
                normalized_name VARCHAR(255) NOT NULL,
                source_type VARCHAR(20) NOT NULL,
                confidence_score DECIMAL(3,2) DEFAULT 1.0,
                match_type VARCHAR(20) DEFAULT 'exact',
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                created_by VARCHAR(100) DEFAULT 'system',
                cluster_name VARCHAR(100),
                warehouse_code VARCHAR(50),
                region VARCHAR(100),
                is_active BOOLEAN DEFAULT 1,
                usage_count INTEGER DEFAULT 0,
                last_used_at DATETIME,
                UNIQUE(original_name, source_type)
            )
        ");
        
        // Insert some test data
        $this->mockPdo->exec("
            INSERT INTO warehouse_normalization 
            (original_name, normalized_name, source_type, confidence_score, match_type, usage_count) 
            VALUES 
            ('РФЦ МОСКВА', 'РФЦ_МОСКВА', 'api', 1.0, 'exact', 10),
            ('РФЦ СПБ', 'РФЦ_САНКТ_ПЕТЕРБУРГ', 'api', 1.0, 'exact', 8),
            ('МРФЦ ЕКАТЕРИНБУРГ', 'МРФЦ_ЕКАТЕРИНБУРГ', 'api', 1.0, 'exact', 5),
            ('Склад Москва', 'СКЛАД_МОСКВА', 'ui_report', 0.9, 'rule_based', 3)
        ");
        
        $this->normalizer = new WarehouseNormalizer($this->mockPdo);
    }
    
    public function testExactMatchNormalization(): void {
        $result = $this->normalizer->normalize('РФЦ МОСКВА', WarehouseNormalizer::SOURCE_API);
        
        $this->assertInstanceOf(NormalizationResult::class, $result);
        $this->assertEquals('РФЦ МОСКВА', $result->getOriginalName());
        $this->assertEquals('РФЦ_МОСКВА', $result->getNormalizedName());
        $this->assertEquals(WarehouseNormalizer::MATCH_EXACT, $result->getMatchType());
        $this->assertEquals(WarehouseNormalizer::CONFIDENCE_EXACT, $result->getConfidence());
        $this->assertTrue($result->isHighConfidence());
        $this->assertFalse($result->needsReview());
    }
    
    public function testRuleBasedNormalization(): void {
        // Test РФЦ pattern
        $result = $this->normalizer->normalize('РФЦ Новосибирск', WarehouseNormalizer::SOURCE_API);
        
        $this->assertEquals('РФЦ Новосибирск', $result->getOriginalName());
        $this->assertEquals('РФЦ_НОВОСИБИРСК', $result->getNormalizedName());
        $this->assertEquals(WarehouseNormalizer::MATCH_RULE_BASED, $result->getMatchType());
        $this->assertEquals(WarehouseNormalizer::CONFIDENCE_RULE_BASED, $result->getConfidence());
        $this->assertTrue($result->isHighConfidence());
        $this->assertTrue($result->wasAutoCreated());
    }
    
    public function testMRFCNormalization(): void {
        // Test МРФЦ pattern
        $result = $this->normalizer->normalize('МРФЦ Казань', WarehouseNormalizer::SOURCE_API);
        
        $this->assertEquals('МРФЦ Казань', $result->getOriginalName());
        $this->assertEquals('МРФЦ_КАЗАНЬ', $result->getNormalizedName());
        $this->assertEquals(WarehouseNormalizer::MATCH_RULE_BASED, $result->getMatchType());
        $this->assertEquals(WarehouseNormalizer::CONFIDENCE_RULE_BASED, $result->getConfidence());
    }
    
    public function testWarehousePatternNormalization(): void {
        // Test general warehouse pattern
        $result = $this->normalizer->normalize('Склад Ростов-на-Дону', WarehouseNormalizer::SOURCE_UI_REPORT);
        
        $this->assertEquals('Склад Ростов-на-Дону', $result->getOriginalName());
        $this->assertEquals('СКЛАД_РОСТОВ_НА_ДОНУ', $result->getNormalizedName());
        $this->assertEquals(WarehouseNormalizer::MATCH_RULE_BASED, $result->getMatchType());
    }
    
    public function testCityNameStandardization(): void {
        // Test city name standardization
        $result = $this->normalizer->normalize('РФЦ Санкт-Петербург', WarehouseNormalizer::SOURCE_API);
        
        $this->assertEquals('РФЦ_САНКТ_ПЕТЕРБУРГ', $result->getNormalizedName());
        
        // Test SPB abbreviation
        $result2 = $this->normalizer->normalize('РФЦ СПб', WarehouseNormalizer::SOURCE_API);
        $this->assertEquals('РФЦ_САНКТ_ПЕТЕРБУРГ', $result2->getNormalizedName());
    }
    
    public function testFuzzyMatching(): void {
        // Test fuzzy matching with slight variations
        $result = $this->normalizer->normalize('РФЦ МОСКВА ', WarehouseNormalizer::SOURCE_API); // Extra space
        
        // Should still match exactly after cleaning
        $this->assertEquals('РФЦ_МОСКВА', $result->getNormalizedName());
        $this->assertEquals(WarehouseNormalizer::MATCH_EXACT, $result->getMatchType());
    }
    
    public function testUnrecognizedWarehouse(): void {
        // Test completely unrecognized warehouse name
        $result = $this->normalizer->normalize('Неизвестный Склад XYZ', WarehouseNormalizer::SOURCE_API);
        
        $this->assertEquals('Неизвестный Склад XYZ', $result->getOriginalName());
        $this->assertEquals('СКЛАД_НЕИЗВЕСТНЫЙ_СКЛАД_XYZ', $result->getNormalizedName());
        $this->assertEquals(WarehouseNormalizer::MATCH_AUTO_DETECTED, $result->getMatchType());
        $this->assertEquals(WarehouseNormalizer::CONFIDENCE_AUTO_DETECTED, $result->getConfidence());
        $this->assertFalse($result->isHighConfidence());
        $this->assertTrue($result->needsReview());
    }
    
    public function testEmptyWarehouseName(): void {
        // Test empty warehouse name
        $result = $this->normalizer->normalize('', WarehouseNormalizer::SOURCE_API);
        
        $this->assertEquals('', $result->getOriginalName());
        $this->assertEquals('', $result->getNormalizedName());
        $this->assertEquals(0.0, $result->getConfidence());
        $this->assertArrayHasKey('error', $result->getMetadata());
    }
    
    public function testBatchNormalization(): void {
        $warehouseNames = [
            'РФЦ МОСКВА',
            'МРФЦ Екатеринбург',
            'Склад Новый',
            'РФЦ Казань'
        ];
        
        $results = $this->normalizer->normalizeBatch($warehouseNames, WarehouseNormalizer::SOURCE_API);
        
        $this->assertCount(4, $results);
        
        // Check first result (exact match)
        $this->assertEquals('РФЦ_МОСКВА', $results[0]->getNormalizedName());
        $this->assertEquals(WarehouseNormalizer::MATCH_EXACT, $results[0]->getMatchType());
        
        // Check second result (exact match)
        $this->assertEquals('МРФЦ_ЕКАТЕРИНБУРГ', $results[1]->getNormalizedName());
        $this->assertEquals(WarehouseNormalizer::MATCH_EXACT, $results[1]->getMatchType());
        
        // Check third result (rule-based)
        $this->assertEquals('СКЛАД_НОВЫЙ', $results[2]->getNormalizedName());
        $this->assertEquals(WarehouseNormalizer::MATCH_RULE_BASED, $results[2]->getMatchType());
        
        // Check fourth result (rule-based)
        $this->assertEquals('РФЦ_КАЗАНЬ', $results[3]->getNormalizedName());
        $this->assertEquals(WarehouseNormalizer::MATCH_RULE_BASED, $results[3]->getMatchType());
    }
    
    public function testManualRuleAddition(): void {
        $ruleId = $this->normalizer->addManualRule(
            'Специальный Склад',
            'СПЕЦИАЛЬНЫЙ_СКЛАД',
            WarehouseNormalizer::SOURCE_MANUAL
        );
        
        $this->assertGreaterThan(0, $ruleId);
        
        // Test that the manual rule works
        $result = $this->normalizer->normalize('Специальный Склад', WarehouseNormalizer::SOURCE_MANUAL);
        
        $this->assertEquals('СПЕЦИАЛЬНЫЙ_СКЛАД', $result->getNormalizedName());
        $this->assertEquals(WarehouseNormalizer::MATCH_EXACT, $result->getMatchType());
        $this->assertEquals(WarehouseNormalizer::CONFIDENCE_EXACT, $result->getConfidence());
    }
    
    public function testCleanWarehouseName(): void {
        $reflection = new ReflectionClass($this->normalizer);
        $method = $reflection->getMethod('cleanWarehouseName');
        $method->setAccessible(true);
        
        // Test various cleaning scenarios
        $testCases = [
            'рфц москва' => 'РФЦ МОСКВА',
            '  РФЦ   Санкт-Петербург  ' => 'РФЦ САНКТ-ПЕТЕРБУРГ',
            'Региональный Фулфилмент Центр Москва' => 'РФЦ МОСКВА',
            'Мультирегиональный Фулфилмент Центр Екатеринбург' => 'МРФЦ ЕКАТЕРИНБУРГ',
            'Склад №1 Москва!!!' => 'СКЛАД 1 МОСКВА'
        ];
        
        foreach ($testCases as $input => $expected) {
            $result = $method->invoke($this->normalizer, $input);
            $this->assertEquals($expected, $result, "Failed for input: '$input'");
        }
    }
    
    public function testAutoRulesApplication(): void {
        $reflection = new ReflectionClass($this->normalizer);
        $method = $reflection->getMethod('applyAutoRules');
        $method->setAccessible(true);
        
        $testCases = [
            'РФЦ МОСКВА' => 'РФЦ_МОСКВА',
            'МОСКВА РФЦ' => 'РФЦ_МОСКВА',
            'МРФЦ ЕКАТЕРИНБУРГ' => 'МРФЦ_ЕКАТЕРИНБУРГ',
            'СКЛАД НОВОСИБИРСК' => 'СКЛАД_НОВОСИБИРСК',
            'САНКТ ПЕТЕРБУРГ' => 'САНКТ_ПЕТЕРБУРГ'
        ];
        
        foreach ($testCases as $input => $expected) {
            $result = $method->invoke($this->normalizer, $input);
            $this->assertTrue($result['success'], "Auto rules should succeed for: '$input'");
            $this->assertEquals($expected, $result['normalized'], "Failed normalization for: '$input'");
            $this->assertNotEmpty($result['applied_rules'], "Should have applied rules for: '$input'");
        }
    }
    
    public function testSimilarityCalculation(): void {
        $reflection = new ReflectionClass($this->normalizer);
        $method = $reflection->getMethod('calculateSimilarity');
        $method->setAccessible(true);
        
        // Test identical strings
        $similarity = $method->invoke($this->normalizer, 'РФЦ МОСКВА', 'РФЦ МОСКВА');
        $this->assertEquals(1.0, $similarity);
        
        // Test similar strings
        $similarity = $method->invoke($this->normalizer, 'РФЦ МОСКВА', 'РФЦ_МОСКВА');
        $this->assertGreaterThan(0.8, $similarity);
        
        // Test different strings
        $similarity = $method->invoke($this->normalizer, 'РФЦ МОСКВА', 'МРФЦ ЕКАТЕРИНБУРГ');
        $this->assertLessThan(0.5, $similarity);
    }
    
    public function testFallbackNormalization(): void {
        $reflection = new ReflectionClass($this->normalizer);
        $method = $reflection->getMethod('generateFallbackNormalization');
        $method->setAccessible(true);
        
        $testCases = [
            'НЕИЗВЕСТНЫЙ СКЛАД' => 'СКЛАД_НЕИЗВЕСТНЫЙ_СКЛАД',
            'РФЦ НЕИЗВЕСТНЫЙ' => 'РФЦ_НЕИЗВЕСТНЫЙ',
            'ПРОСТО НАЗВАНИЕ' => 'СКЛАД_ПРОСТО_НАЗВАНИЕ',
            'СКЛАД УЖЕ ЕСТЬ' => 'СКЛАД_УЖЕ_ЕСТЬ'
        ];
        
        foreach ($testCases as $input => $expected) {
            $result = $method->invoke($this->normalizer, $input);
            $this->assertEquals($expected, $result, "Failed fallback for: '$input'");
        }
    }
    
    public function testNormalizationStatistics(): void {
        $stats = $this->normalizer->getNormalizationStatistics(7);
        
        $this->assertArrayHasKey('total_rules', $stats);
        $this->assertArrayHasKey('active_rules', $stats);
        $this->assertArrayHasKey('usage_stats', $stats);
        $this->assertArrayHasKey('confidence_distribution', $stats);
        $this->assertArrayHasKey('source_distribution', $stats);
        
        $this->assertGreaterThan(0, $stats['total_rules']);
        $this->assertGreaterThan(0, $stats['active_rules']);
    }
    
    public function testUnrecognizedWarehouses(): void {
        // First, create some unrecognized warehouses
        $this->normalizer->normalize('Неизвестный Склад 1', WarehouseNormalizer::SOURCE_API);
        $this->normalizer->normalize('Неизвестный Склад 2', WarehouseNormalizer::SOURCE_API);
        
        $unrecognized = $this->normalizer->getUnrecognizedWarehouses(10);
        
        $this->assertIsArray($unrecognized);
        // Should have some unrecognized warehouses from the test data or newly created ones
    }
    
    public function testNormalizationResultMethods(): void {
        $result = new NormalizationResult(
            'Original Name',
            'NORMALIZED_NAME',
            WarehouseNormalizer::MATCH_RULE_BASED,
            WarehouseNormalizer::CONFIDENCE_RULE_BASED,
            WarehouseNormalizer::SOURCE_API,
            ['auto_created' => true, 'needs_review' => false]
        );
        
        $this->assertEquals('Original Name', $result->getOriginalName());
        $this->assertEquals('NORMALIZED_NAME', $result->getNormalizedName());
        $this->assertEquals(WarehouseNormalizer::MATCH_RULE_BASED, $result->getMatchType());
        $this->assertEquals(WarehouseNormalizer::CONFIDENCE_RULE_BASED, $result->getConfidence());
        $this->assertEquals(WarehouseNormalizer::SOURCE_API, $result->getSourceType());
        
        $this->assertTrue($result->isHighConfidence());
        $this->assertFalse($result->needsReview());
        $this->assertTrue($result->wasAutoCreated());
    }
    
    public function testLowConfidenceResult(): void {
        $result = new NormalizationResult(
            'Test Name',
            'TEST_NAME',
            WarehouseNormalizer::MATCH_AUTO_DETECTED,
            WarehouseNormalizer::CONFIDENCE_AUTO_DETECTED,
            WarehouseNormalizer::SOURCE_API,
            ['needs_review' => true]
        );
        
        $this->assertFalse($result->isHighConfidence());
        $this->assertTrue($result->needsReview());
        $this->assertFalse($result->wasAutoCreated());
    }
    
    public function testWithoutDatabase(): void {
        // Test normalizer without database connection
        $normalizerNoDb = new WarehouseNormalizer();
        
        $result = $normalizerNoDb->normalize('РФЦ Тест', WarehouseNormalizer::SOURCE_API);
        
        $this->assertEquals('РФЦ Тест', $result->getOriginalName());
        $this->assertEquals('РФЦ_ТЕСТ', $result->getNormalizedName());
        $this->assertEquals(WarehouseNormalizer::MATCH_RULE_BASED, $result->getMatchType());
        
        // Statistics should return empty array without database
        $stats = $normalizerNoDb->getNormalizationStatistics();
        $this->assertEmpty($stats);
    }
    
    protected function tearDown(): void {
        $this->mockPdo = null;
        $this->normalizer = null;
    }
}