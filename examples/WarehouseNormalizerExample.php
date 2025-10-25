<?php
/**
 * Example usage of WarehouseNormalizer
 * 
 * Demonstrates how to use the Warehouse Normalizer service for standardizing
 * warehouse names from Analytics API, handling duplicates, and managing rules.
 * 
 * Task: 4.3 Создать WarehouseNormalizer сервис (example)
 */

require_once __DIR__ . '/../src/Services/WarehouseNormalizer.php';

// Example 1: Basic warehouse name normalization
function basicNormalizationExample() {
    echo "=== Basic Warehouse Normalization Example ===\n";
    
    try {
        $normalizer = new WarehouseNormalizer(getDatabaseConnection());
        
        // Sample warehouse names from Analytics API
        $warehouseNames = [
            'РФЦ Москва',
            'рфц санкт-петербург',
            'МРФЦ Екатеринбург',
            'Склад Новосибирск',
            'Региональный Фулфилмент Центр Казань',
            'Мультирегиональный Фулфилмент Центр Ростов-на-Дону'
        ];
        
        echo "📦 Normalizing " . count($warehouseNames) . " warehouse names...\n\n";
        
        foreach ($warehouseNames as $warehouseName) {
            $result = $normalizer->normalize($warehouseName, WarehouseNormalizer::SOURCE_API);
            
            echo "Original: '{$result->getOriginalName()}'\n";
            echo "Normalized: '{$result->getNormalizedName()}'\n";
            echo "Match Type: {$result->getMatchType()}\n";
            echo "Confidence: " . round($result->getConfidence() * 100, 1) . "%\n";
            
            if ($result->isHighConfidence()) {
                echo "✅ High confidence normalization\n";
            } else {
                echo "⚠️  Low confidence - may need review\n";
            }
            
            if ($result->wasAutoCreated()) {
                echo "🔧 Auto-created rule\n";
            }
            
            echo "\n";
        }
        
    } catch (Exception $e) {
        echo "❌ Error: " . $e->getMessage() . "\n";
    }
}

// Example 2: Batch normalization for efficiency
function batchNormalizationExample() {
    echo "\n=== Batch Normalization Example ===\n";
    
    try {
        $normalizer = new WarehouseNormalizer(getDatabaseConnection());
        
        // Large batch of warehouse names
        $warehouseNames = [
            'РФЦ Москва',
            'РФЦ СПб',
            'МРФЦ Екатеринбург',
            'МРФЦ Новосибирск',
            'Склад Казань',
            'Склад Ростов',
            'ФЦ Нижний Новгород',
            'Логистический Центр Самара',
            'Распределительный Центр Уфа',
            'РФЦ Краснодар'
        ];
        
        echo "🔄 Processing batch of " . count($warehouseNames) . " warehouse names...\n";
        
        $startTime = microtime(true);
        $results = $normalizer->normalizeBatch($warehouseNames, WarehouseNormalizer::SOURCE_API);
        $executionTime = round((microtime(true) - $startTime) * 1000, 2);
        
        echo "⏱️  Batch processing completed in {$executionTime}ms\n\n";
        
        // Analyze results
        $stats = [
            'exact_matches' => 0,
            'rule_based' => 0,
            'fuzzy_matches' => 0,
            'auto_detected' => 0,
            'high_confidence' => 0,
            'needs_review' => 0
        ];
        
        foreach ($results as $result) {
            switch ($result->getMatchType()) {
                case WarehouseNormalizer::MATCH_EXACT:
                    $stats['exact_matches']++;
                    break;
                case WarehouseNormalizer::MATCH_RULE_BASED:
                    $stats['rule_based']++;
                    break;
                case WarehouseNormalizer::MATCH_FUZZY:
                    $stats['fuzzy_matches']++;
                    break;
                case WarehouseNormalizer::MATCH_AUTO_DETECTED:
                    $stats['auto_detected']++;
                    break;
            }
            
            if ($result->isHighConfidence()) {
                $stats['high_confidence']++;
            }
            
            if ($result->needsReview()) {
                $stats['needs_review']++;
            }
        }
        
        echo "📊 Batch Results:\n";
        echo "  - Exact matches: {$stats['exact_matches']}\n";
        echo "  - Rule-based: {$stats['rule_based']}\n";
        echo "  - Fuzzy matches: {$stats['fuzzy_matches']}\n";
        echo "  - Auto-detected: {$stats['auto_detected']}\n";
        echo "  - High confidence: {$stats['high_confidence']}\n";
        echo "  - Needs review: {$stats['needs_review']}\n";
        
        // Show some examples
        echo "\n🔍 Sample Results:\n";
        for ($i = 0; $i < min(5, count($results)); $i++) {
            $result = $results[$i];
            echo "  '{$result->getOriginalName()}' → '{$result->getNormalizedName()}' ({$result->getMatchType()})\n";
        }
        
    } catch (Exception $e) {
        echo "❌ Error: " . $e->getMessage() . "\n";
    }
}

// Example 3: Handling duplicates and variations
function duplicateHandlingExample() {
    echo "\n=== Duplicate and Variation Handling Example ===\n";
    
    try {
        $normalizer = new WarehouseNormalizer(getDatabaseConnection());
        
        // Various forms of the same warehouse
        $variations = [
            'РФЦ Москва',
            'рфц москва',
            'РФЦ МОСКВА',
            '  РФЦ   Москва  ',
            'Региональный Фулфилмент Центр Москва',
            'РФЦ-Москва',
            'РФЦ_Москва'
        ];
        
        echo "🔍 Testing variations of the same warehouse:\n\n";
        
        $normalizedResults = [];
        
        foreach ($variations as $variation) {
            $result = $normalizer->normalize($variation, WarehouseNormalizer::SOURCE_API);
            
            echo "'{$variation}' → '{$result->getNormalizedName()}'\n";
            
            $normalizedResults[] = $result->getNormalizedName();
        }
        
        // Check if all variations normalize to the same result
        $uniqueResults = array_unique($normalizedResults);
        
        echo "\n📊 Analysis:\n";
        echo "  - Input variations: " . count($variations) . "\n";
        echo "  - Unique normalized results: " . count($uniqueResults) . "\n";
        
        if (count($uniqueResults) === 1) {
            echo "  ✅ All variations normalized to the same result: '{$uniqueResults[0]}'\n";
        } else {
            echo "  ⚠️  Multiple normalized results found:\n";
            foreach ($uniqueResults as $result) {
                echo "    - '{$result}'\n";
            }
        }
        
    } catch (Exception $e) {
        echo "❌ Error: " . $e->getMessage() . "\n";
    }
}

// Example 4: Manual rule management
function manualRuleManagementExample() {
    echo "\n=== Manual Rule Management Example ===\n";
    
    try {
        $normalizer = new WarehouseNormalizer(getDatabaseConnection());
        
        echo "🔧 Adding manual normalization rules...\n";
        
        // Add some manual rules for special cases
        $manualRules = [
            ['Специальный Склад А', 'СПЕЦИАЛЬНЫЙ_СКЛАД_А'],
            ['Тестовый Центр', 'ТЕСТОВЫЙ_ЦЕНТР'],
            ['Экспериментальный РФЦ', 'ЭКСПЕРИМЕНТАЛЬНЫЙ_РФЦ'],
            ['Временный Склад 123', 'ВРЕМЕННЫЙ_СКЛАД_123']
        ];
        
        foreach ($manualRules as [$original, $normalized]) {
            $ruleId = $normalizer->addManualRule($original, $normalized, WarehouseNormalizer::SOURCE_MANUAL);
            echo "  ✅ Added rule #{$ruleId}: '{$original}' → '{$normalized}'\n";
        }
        
        echo "\n🧪 Testing manual rules:\n";
        
        foreach ($manualRules as [$original, $expected]) {
            $result = $normalizer->normalize($original, WarehouseNormalizer::SOURCE_MANUAL);
            
            echo "  '{$original}' → '{$result->getNormalizedName()}'";
            
            if ($result->getNormalizedName() === $expected) {
                echo " ✅\n";
            } else {
                echo " ❌ (expected: '{$expected}')\n";
            }
        }
        
        // Test with different source type
        echo "\n🔄 Testing same names with different source types:\n";
        
        $testName = 'Специальный Склад А';
        
        $apiResult = $normalizer->normalize($testName, WarehouseNormalizer::SOURCE_API);
        $manualResult = $normalizer->normalize($testName, WarehouseNormalizer::SOURCE_MANUAL);
        
        echo "  API source: '{$apiResult->getNormalizedName()}' (confidence: " . round($apiResult->getConfidence() * 100, 1) . "%)\n";
        echo "  Manual source: '{$manualResult->getNormalizedName()}' (confidence: " . round($manualResult->getConfidence() * 100, 1) . "%)\n";
        
    } catch (Exception $e) {
        echo "❌ Error: " . $e->getMessage() . "\n";
    }
}

// Example 5: Unrecognized warehouse handling
function unrecognizedWarehouseExample() {
    echo "\n=== Unrecognized Warehouse Handling Example ===\n";
    
    try {
        $normalizer = new WarehouseNormalizer(getDatabaseConnection());
        
        // Test with completely unrecognized warehouse names
        $unrecognizedNames = [
            'Загадочный Склад XYZ',
            'Mystery Warehouse 42',
            'Неопознанный Объект',
            'Strange Location ABC',
            'Тестовое Место 999'
        ];
        
        echo "🔍 Processing unrecognized warehouse names:\n\n";
        
        foreach ($unrecognizedNames as $name) {
            $result = $normalizer->normalize($name, WarehouseNormalizer::SOURCE_API);
            
            echo "Original: '{$result->getOriginalName()}'\n";
            echo "Normalized: '{$result->getNormalizedName()}'\n";
            echo "Match Type: {$result->getMatchType()}\n";
            echo "Confidence: " . round($result->getConfidence() * 100, 1) . "%\n";
            
            if ($result->needsReview()) {
                echo "⚠️  Needs manual review\n";
            }
            
            $metadata = $result->getMetadata();
            if (isset($metadata['unrecognized'])) {
                echo "🚨 Logged as unrecognized\n";
            }
            
            echo "\n";
        }
        
        // Get list of unrecognized warehouses
        echo "📋 Recently unrecognized warehouses:\n";
        $unrecognized = $normalizer->getUnrecognizedWarehouses(10);
        
        if (empty($unrecognized)) {
            echo "  No unrecognized warehouses in database\n";
        } else {
            foreach ($unrecognized as $warehouse) {
                echo "  - '{$warehouse['original_name']}' → '{$warehouse['normalized_name']}' ";
                echo "(confidence: " . round($warehouse['confidence_score'] * 100, 1) . "%, ";
                echo "usage: {$warehouse['usage_count']})\n";
            }
        }
        
    } catch (Exception $e) {
        echo "❌ Error: " . $e->getMessage() . "\n";
    }
}

// Example 6: Statistics and monitoring
function statisticsAndMonitoringExample() {
    echo "\n=== Statistics and Monitoring Example ===\n";
    
    try {
        $normalizer = new WarehouseNormalizer(getDatabaseConnection());
        
        // First, generate some activity
        echo "📊 Generating normalization activity...\n";
        
        $testNames = [
            'РФЦ Москва', 'РФЦ СПб', 'МРФЦ Екатеринбург',
            'Склад Новосибирск', 'ФЦ Казань', 'РЦ Ростов',
            'Новый Склад', 'Тестовый Центр', 'Неизвестное Место'
        ];
        
        // Normalize multiple times to generate usage statistics
        for ($i = 0; $i < 3; $i++) {
            foreach ($testNames as $name) {
                $normalizer->normalize($name, WarehouseNormalizer::SOURCE_API);
            }
        }
        
        echo "✅ Activity generated\n\n";
        
        // Get normalization statistics
        echo "📈 Normalization Statistics (last 7 days):\n";
        $stats = $normalizer->getNormalizationStatistics(7);
        
        echo "  📊 Overview:\n";
        echo "    - Total rules: {$stats['total_rules']}\n";
        echo "    - Active rules: {$stats['active_rules']}\n";
        
        if (!empty($stats['usage_stats'])) {
            echo "\n  🎯 Usage by Match Type:\n";
            foreach ($stats['usage_stats'] as $usage) {
                echo "    - {$usage['match_type']}: {$usage['count']} rules ";
                echo "(avg confidence: " . round($usage['avg_confidence'] * 100, 1) . "%, ";
                echo "total usage: {$usage['total_usage']})\n";
            }
        }
        
        if (!empty($stats['confidence_distribution'])) {
            echo "\n  📊 Confidence Distribution:\n";
            foreach ($stats['confidence_distribution'] as $dist) {
                echo "    - {$dist['confidence_range']}: {$dist['count']} rules\n";
            }
        }
        
        if (!empty($stats['source_distribution'])) {
            echo "\n  📍 Source Distribution:\n";
            foreach ($stats['source_distribution'] as $source) {
                echo "    - {$source['source_type']}: {$source['count']} rules ";
                echo "(avg confidence: " . round($source['avg_confidence'] * 100, 1) . "%)\n";
            }
        }
        
        // Quality assessment
        echo "\n  🎯 Quality Assessment:\n";
        $totalRules = $stats['total_rules'];
        $activeRules = $stats['active_rules'];
        
        if ($totalRules > 0) {
            $activeRatio = ($activeRules / $totalRules) * 100;
            echo "    - Active rules ratio: " . round($activeRatio, 1) . "%\n";
            
            if ($activeRatio >= 90) {
                echo "    ✅ Excellent rule maintenance\n";
            } elseif ($activeRatio >= 80) {
                echo "    ✅ Good rule maintenance\n";
            } else {
                echo "    ⚠️  Rule maintenance needs attention\n";
            }
        }
        
        // Check for rules that need review
        $unrecognized = $normalizer->getUnrecognizedWarehouses(5);
        if (!empty($unrecognized)) {
            echo "\n  ⚠️  Rules needing review:\n";
            foreach ($unrecognized as $warehouse) {
                echo "    - '{$warehouse['original_name']}' (confidence: " . 
                     round($warehouse['confidence_score'] * 100, 1) . "%)\n";
            }
        }
        
    } catch (Exception $e) {
        echo "❌ Error: " . $e->getMessage() . "\n";
    }
}

// Example 7: Integration with Analytics API
function analyticsApiIntegrationExample() {
    echo "\n=== Analytics API Integration Example ===\n";
    
    try {
        echo "🔗 Integration with AnalyticsApiClient workflow:\n\n";
        
        $codeExample = '
// Complete workflow example
$apiClient = new AnalyticsApiClient($clientId, $apiKey, $pdo);
$normalizer = new WarehouseNormalizer($pdo);
$validator = new DataValidator($pdo);

// 1. Fetch data from Analytics API
$batchId = "api_batch_" . date("Ymd_His");
$apiResponse = $apiClient->getStockOnWarehouses(0, 1000);

// 2. Normalize warehouse names
$normalizedData = [];
foreach ($apiResponse["data"] as $record) {
    $normResult = $normalizer->normalize(
        $record["warehouse_name"], 
        WarehouseNormalizer::SOURCE_API
    );
    
    $record["original_warehouse_name"] = $record["warehouse_name"];
    $record["normalized_warehouse_name"] = $normResult->getNormalizedName();
    $record["normalization_confidence"] = $normResult->getConfidence();
    
    $normalizedData[] = $record;
}

// 3. Validate normalized data
$validationResult = $validator->validateBatch($normalizedData, $batchId);

// 4. Process based on quality
if ($validationResult->getQualityScore() >= 80) {
    // Save to inventory table with normalized warehouse names
    foreach ($normalizedData as $record) {
        // INSERT INTO inventory (..., normalized_warehouse_name, original_warehouse_name, ...)
    }
    
    echo "✅ Data processed successfully";
} else {
    echo "⚠️  Data quality issues detected";
}

// 5. Monitor normalization quality
$normStats = $normalizer->getNormalizationStatistics();
if ($normStats["needs_review_count"] > 10) {
    echo "🚨 Many warehouse names need manual review";
}
';
        
        echo $codeExample . "\n";
        
        echo "🔍 Key Integration Benefits:\n";
        echo "  1. ✅ Consistent warehouse naming across all data\n";
        echo "  2. ✅ Automatic handling of variations and typos\n";
        echo "  3. ✅ Quality tracking and monitoring\n";
        echo "  4. ✅ Manual override capability for special cases\n";
        echo "  5. ✅ Performance optimization through caching\n";
        echo "  6. ✅ Audit trail for all normalization decisions\n";
        
        // Simulate the workflow with sample data
        echo "\n🧪 Simulating integration workflow:\n";
        
        $normalizer = new WarehouseNormalizer(getDatabaseConnection());
        
        // Sample API response data
        $sampleApiData = [
            ['warehouse_name' => 'РФЦ Москва', 'sku' => 'SKU001', 'available_stock' => 100],
            ['warehouse_name' => 'рфц санкт-петербург', 'sku' => 'SKU002', 'available_stock' => 75],
            ['warehouse_name' => 'МРФЦ Екатеринбург', 'sku' => 'SKU003', 'available_stock' => 50],
            ['warehouse_name' => 'Склад Новосибирск', 'sku' => 'SKU004', 'available_stock' => 25]
        ];
        
        echo "  📦 Processing " . count($sampleApiData) . " records...\n";
        
        $processedRecords = 0;
        $highConfidenceNormalizations = 0;
        
        foreach ($sampleApiData as $record) {
            $normResult = $normalizer->normalize($record['warehouse_name'], WarehouseNormalizer::SOURCE_API);
            
            echo "    '{$record['warehouse_name']}' → '{$normResult->getNormalizedName()}' ";
            echo "(" . round($normResult->getConfidence() * 100, 1) . "%)\n";
            
            $processedRecords++;
            if ($normResult->isHighConfidence()) {
                $highConfidenceNormalizations++;
            }
        }
        
        $confidenceRate = ($highConfidenceNormalizations / $processedRecords) * 100;
        echo "\n  📊 Results: {$processedRecords} processed, " . round($confidenceRate, 1) . "% high confidence\n";
        
        if ($confidenceRate >= 90) {
            echo "  ✅ Excellent normalization quality\n";
        } elseif ($confidenceRate >= 80) {
            echo "  ✅ Good normalization quality\n";
        } else {
            echo "  ⚠️  Normalization quality needs attention\n";
        }
        
    } catch (Exception $e) {
        echo "❌ Error: " . $e->getMessage() . "\n";
    }
}

// Helper function to get database connection
function getDatabaseConnection(): PDO {
    // For demo purposes, using SQLite in memory
    $pdo = new PDO('sqlite::memory:');
    
    // Create warehouse_normalization table
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS warehouse_normalization (
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
    
    // Insert some initial test data
    $pdo->exec("
        INSERT OR IGNORE INTO warehouse_normalization 
        (original_name, normalized_name, source_type, confidence_score, match_type, usage_count) 
        VALUES 
        ('РФЦ МОСКВА', 'РФЦ_МОСКВА', 'api', 1.0, 'exact', 15),
        ('РФЦ СПБ', 'РФЦ_САНКТ_ПЕТЕРБУРГ', 'api', 1.0, 'exact', 12),
        ('МРФЦ ЕКАТЕРИНБУРГ', 'МРФЦ_ЕКАТЕРИНБУРГ', 'api', 1.0, 'exact', 8),
        ('Склад Москва', 'СКЛАД_МОСКВА', 'ui_report', 0.9, 'rule_based', 5)
    ");
    
    return $pdo;
}

// Main execution
if (php_sapi_name() === 'cli') {
    echo "🚀 WarehouseNormalizer Examples\n";
    echo "===============================\n";
    
    // Run examples
    basicNormalizationExample();
    batchNormalizationExample();
    duplicateHandlingExample();
    manualRuleManagementExample();
    unrecognizedWarehouseExample();
    statisticsAndMonitoringExample();
    analyticsApiIntegrationExample();
    
    echo "\n✅ All examples completed!\n";
}