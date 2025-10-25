<?php
/**
 * Example usage of DataValidator
 * 
 * Demonstrates how to use the Data Validator service for validating
 * Analytics API data, detecting anomalies, and calculating quality metrics.
 * 
 * Task: 4.2 Создать DataValidator сервис (example)
 */

require_once __DIR__ . '/../src/Services/DataValidator.php';
require_once __DIR__ . '/../src/Services/AnalyticsApiClient.php';

// Example 1: Basic validation of Analytics API data
function basicValidationExample() {
    echo "=== Basic Validation Example ===\n";
    
    try {
        $validator = new DataValidator(getDatabaseConnection());
        
        // Sample data from Analytics API
        $apiData = [
            [
                'sku' => 'PRODUCT_001',
                'warehouse_name' => 'РФЦ Москва',
                'available_stock' => 150,
                'reserved_stock' => 25,
                'total_stock' => 175,
                'product_name' => 'Смартфон Samsung Galaxy',
                'category' => 'Electronics',
                'brand' => 'Samsung',
                'price' => 45999.99,
                'currency' => 'RUB',
                'updated_at' => date('Y-m-d H:i:s')
            ],
            [
                'sku' => 'PRODUCT_002',
                'warehouse_name' => 'РФЦ Санкт-Петербург',
                'available_stock' => 75,
                'reserved_stock' => 15,
                'total_stock' => 90,
                'product_name' => 'Ноутбук ASUS',
                'category' => 'Electronics',
                'brand' => 'ASUS',
                'price' => 89999.99,
                'currency' => 'RUB',
                'updated_at' => date('Y-m-d H:i:s')
            ],
            [
                'sku' => 'PRODUCT_003',
                'warehouse_name' => 'МРФЦ Екатеринбург',
                'available_stock' => 200,
                'reserved_stock' => 50,
                'total_stock' => 250,
                'product_name' => 'Планшет iPad',
                'category' => 'Electronics',
                'brand' => 'Apple',
                'price' => 65999.99,
                'currency' => 'RUB',
                'updated_at' => date('Y-m-d H:i:s')
            ]
        ];
        
        echo "📦 Validating " . count($apiData) . " records from Analytics API...\n";
        
        $result = $validator->validateBatch($apiData, 'example_batch_001');
        
        echo "✅ Validation completed!\n";
        echo "📊 Results:\n";
        echo "  - Total records: " . $result->getResults()['total_records'] . "\n";
        echo "  - Valid records: " . $result->getResults()['valid_records'] . "\n";
        echo "  - Invalid records: " . $result->getResults()['invalid_records'] . "\n";
        echo "  - Warnings: " . $result->getResults()['warnings'] . "\n";
        echo "  - Quality score: " . $result->getQualityScore() . "%\n";
        echo "  - Execution time: " . $result->getExecutionTime() . "ms\n";
        
        if ($result->hasAnomalies()) {
            echo "⚠️  Anomalies detected: " . count($result->getResults()['anomalies']) . "\n";
        }
        
        $metrics = $result->getQualityMetrics();
        echo "📈 Quality metrics:\n";
        foreach ($metrics as $metric => $value) {
            echo "  - " . ucfirst(str_replace('_', ' ', $metric)) . ": {$value}%\n";
        }
        
    } catch (Exception $e) {
        echo "❌ Error: " . $e->getMessage() . "\n";
    }
}

// Example 2: Validation with problematic data
function problematicDataExample() {
    echo "\n=== Problematic Data Validation Example ===\n";
    
    try {
        $validator = new DataValidator(getDatabaseConnection());
        
        // Sample data with various issues
        $problematicData = [
            [
                'sku' => '', // Missing required field
                'warehouse_name' => 'РФЦ Москва',
                'available_stock' => 100,
                'reserved_stock' => 20,
                'total_stock' => 120
            ],
            [
                'sku' => 'NEGATIVE_STOCK',
                'warehouse_name' => 'РФЦ СПб',
                'available_stock' => -50, // Negative stock
                'reserved_stock' => -10, // Negative stock
                'total_stock' => -60, // Negative stock
                'price' => -1000 // Negative price
            ],
            [
                'sku' => 'INCONSISTENT_STOCK',
                'warehouse_name' => 'МРФЦ Екатеринбург',
                'available_stock' => 200, // Available + Reserved > Total
                'reserved_stock' => 100,
                'total_stock' => 250,
                'price' => 15999.99
            ],
            [
                'sku' => 'EXTREME_VALUES',
                'warehouse_name' => 'РФЦ Новосибирск',
                'available_stock' => 5000000, // Extremely high stock
                'reserved_stock' => 0,
                'total_stock' => 5000000,
                'price' => 0.01, // Suspicious low price
                'product_name' => '123' // Suspicious product name
            ]
        ];
        
        echo "🔍 Validating problematic data...\n";
        
        $result = $validator->validateBatch($problematicData, 'problematic_batch_001');
        
        echo "📊 Validation Results:\n";
        echo "  - Total records: " . $result->getResults()['total_records'] . "\n";
        echo "  - Valid records: " . $result->getResults()['valid_records'] . "\n";
        echo "  - Invalid records: " . $result->getResults()['invalid_records'] . "\n";
        echo "  - Warnings: " . $result->getResults()['warnings'] . "\n";
        echo "  - Quality score: " . $result->getQualityScore() . "%\n";
        
        // Show detailed issues
        echo "\n🚨 Validation Issues:\n";
        foreach ($result->getResults()['validation_details'] as $index => $recordResult) {
            if (!$recordResult->isValid() || !empty($recordResult->getIssues())) {
                echo "  Record #{$index}:\n";
                echo "    - Status: " . $recordResult->getStatus() . "\n";
                
                foreach ($recordResult->getIssues() as $issue) {
                    echo "    - Issue: " . $issue['message'] . " (Rule: " . $issue['rule'] . ")\n";
                }
                
                if ($recordResult->hasAnomalies()) {
                    foreach ($recordResult->getAnomalies() as $anomaly) {
                        echo "    - Anomaly: " . $anomaly['message'] . " (Detector: " . $anomaly['detector'] . ")\n";
                    }
                }
            }
        }
        
    } catch (Exception $e) {
        echo "❌ Error: " . $e->getMessage() . "\n";
    }
}

// Example 3: Anomaly detection
function anomalyDetectionExample() {
    echo "\n=== Anomaly Detection Example ===\n";
    
    try {
        $validator = new DataValidator();
        
        // Generate dataset with some anomalies
        $dataWithAnomalies = [];
        
        // Normal data
        for ($i = 1; $i <= 20; $i++) {
            $dataWithAnomalies[] = [
                'sku' => "NORMAL_SKU_{$i}",
                'warehouse_name' => 'РФЦ Москва',
                'available_stock' => rand(50, 200),
                'reserved_stock' => rand(10, 50),
                'total_stock' => rand(60, 250),
                'price' => rand(1000, 5000),
                'product_name' => "Normal Product {$i}"
            ];
        }
        
        // Add anomalies
        $dataWithAnomalies[] = [
            'sku' => 'EXTREME_STOCK_SKU',
            'warehouse_name' => 'РФЦ СПб',
            'available_stock' => 2000000, // Extreme outlier
            'reserved_stock' => 0,
            'total_stock' => 2000000,
            'price' => 2500,
            'product_name' => 'Extreme Stock Product'
        ];
        
        $dataWithAnomalies[] = [
            'sku' => 'ZERO_AVAILABLE_SKU',
            'warehouse_name' => 'МРФЦ Екатеринбург',
            'available_stock' => 0, // Zero available but has reserved
            'reserved_stock' => 100,
            'total_stock' => 100,
            'price' => 1800,
            'product_name' => 'Zero Available Product'
        ];
        
        $dataWithAnomalies[] = [
            'sku' => 'SUSPICIOUS_PRICE_SKU',
            'warehouse_name' => 'РФЦ Новосибирск',
            'available_stock' => 75,
            'reserved_stock' => 25,
            'total_stock' => 100,
            'price' => 0.50, // Suspicious low price
            'product_name' => 'Suspicious Price Product'
        ];
        
        echo "🔍 Detecting anomalies in " . count($dataWithAnomalies) . " records...\n";
        
        $anomalies = $validator->detectAnomalies($dataWithAnomalies);
        
        echo "⚠️  Found " . count($anomalies) . " anomalies:\n";
        
        foreach ($anomalies as $anomaly) {
            echo "  - Type: " . $anomaly['type'] . "\n";
            echo "    SKU: " . $anomaly['sku'] . "\n";
            echo "    Warehouse: " . $anomaly['warehouse'] . "\n";
            echo "    Value: " . $anomaly['value'] . "\n";
            echo "    Severity: " . $anomaly['severity'] . "\n";
            
            if (isset($anomaly['expected_range'])) {
                echo "    Expected range: " . $anomaly['expected_range']['min'] . " - " . $anomaly['expected_range']['max'] . "\n";
            }
            
            if (isset($anomaly['issues'])) {
                echo "    Issues: " . implode(', ', $anomaly['issues']) . "\n";
            }
            
            echo "\n";
        }
        
    } catch (Exception $e) {
        echo "❌ Error: " . $e->getMessage() . "\n";
    }
}

// Example 4: Quality metrics calculation
function qualityMetricsExample() {
    echo "\n=== Quality Metrics Calculation Example ===\n";
    
    try {
        $validator = new DataValidator();
        
        // Create datasets with different quality levels
        $datasets = [
            'high_quality' => [
                [
                    'sku' => 'HQ_SKU_001',
                    'warehouse_name' => 'РФЦ Москва',
                    'available_stock' => 100,
                    'reserved_stock' => 20,
                    'total_stock' => 120,
                    'product_name' => 'High Quality Product 1',
                    'category' => 'Electronics',
                    'brand' => 'Samsung',
                    'price' => 25999.99,
                    'updated_at' => date('Y-m-d H:i:s')
                ],
                [
                    'sku' => 'HQ_SKU_002',
                    'warehouse_name' => 'РФЦ СПб',
                    'available_stock' => 75,
                    'reserved_stock' => 15,
                    'total_stock' => 90,
                    'product_name' => 'High Quality Product 2',
                    'category' => 'Electronics',
                    'brand' => 'Apple',
                    'price' => 45999.99,
                    'updated_at' => date('Y-m-d H:i:s')
                ]
            ],
            'medium_quality' => [
                [
                    'sku' => 'MQ_SKU_001',
                    'warehouse_name' => 'МРФЦ Екатеринбург',
                    'available_stock' => 50,
                    'reserved_stock' => 10,
                    'total_stock' => 60,
                    'product_name' => 'Medium Quality Product',
                    // Missing some optional fields
                    'price' => 15999.99,
                    'updated_at' => date('Y-m-d H:i:s', time() - 7200) // 2 hours old
                ],
                [
                    'sku' => 'MQ_SKU_002',
                    'warehouse_name' => 'РФЦ Новосибирск',
                    'available_stock' => 30,
                    'reserved_stock' => 5,
                    'total_stock' => 35,
                    // Missing product name and other fields
                    'updated_at' => date('Y-m-d H:i:s', time() - 14400) // 4 hours old
                ]
            ],
            'low_quality' => [
                [
                    'sku' => 'LQ_SKU_001',
                    'warehouse_name' => 'РФЦ Казань',
                    'available_stock' => 25,
                    // Missing many fields
                    'updated_at' => date('Y-m-d H:i:s', time() - 86400) // 24 hours old
                ],
                [
                    'sku' => 'LQ_SKU_002',
                    'warehouse_name' => 'РФЦ Ростов',
                    'available_stock' => 10,
                    // Very minimal data
                    'updated_at' => date('Y-m-d H:i:s', time() - 172800) // 48 hours old
                ]
            ]
        ];
        
        foreach ($datasets as $qualityLevel => $data) {
            echo "📊 Calculating quality metrics for {$qualityLevel} data:\n";
            
            $metrics = $validator->calculateQualityMetrics($data);
            
            echo "  - Completeness: " . $metrics['completeness'] . "%\n";
            echo "  - Accuracy: " . $metrics['accuracy'] . "%\n";
            echo "  - Consistency: " . $metrics['consistency'] . "%\n";
            echo "  - Freshness: " . $metrics['freshness'] . "%\n";
            echo "  - Validity: " . $metrics['validity'] . "%\n";
            echo "  - Overall Score: " . $metrics['overall_score'] . "%\n";
            
            // Interpret quality level
            if ($metrics['overall_score'] >= 90) {
                echo "  ✅ Quality Level: Excellent\n";
            } elseif ($metrics['overall_score'] >= 80) {
                echo "  ✅ Quality Level: Good\n";
            } elseif ($metrics['overall_score'] >= 70) {
                echo "  ⚠️  Quality Level: Acceptable\n";
            } elseif ($metrics['overall_score'] >= 50) {
                echo "  ⚠️  Quality Level: Poor\n";
            } else {
                echo "  ❌ Quality Level: Critical\n";
            }
            
            echo "\n";
        }
        
    } catch (Exception $e) {
        echo "❌ Error: " . $e->getMessage() . "\n";
    }
}

// Example 5: Integration with AnalyticsApiClient
function integrationExample() {
    echo "\n=== Integration with AnalyticsApiClient Example ===\n";
    
    try {
        // Note: This would require real API credentials
        echo "🔄 This example shows how to integrate DataValidator with AnalyticsApiClient\n";
        echo "📝 Code example (requires real API credentials):\n\n";
        
        $codeExample = '
// Initialize services
$apiClient = new AnalyticsApiClient($clientId, $apiKey, $pdo);
$validator = new DataValidator($pdo);

// Fetch data from Analytics API
$batchId = "api_batch_" . date("Ymd_His");
$apiData = $apiClient->getStockOnWarehouses(0, 1000);

// Validate the fetched data
$validationResult = $validator->validateBatch($apiData["data"], $batchId);

// Process validation results
if ($validationResult->isValid()) {
    echo "✅ All data is valid, proceeding with ETL...";
    // Process valid data
} else {
    echo "⚠️  Data validation issues found:";
    echo "  - Invalid records: " . $validationResult->getResults()["invalid_records"];
    echo "  - Quality score: " . $validationResult->getQualityScore() . "%";
    
    // Handle validation issues
    if ($validationResult->getQualityScore() < 80) {
        echo "❌ Data quality too low, skipping this batch";
        return;
    }
}

// Log validation metrics for monitoring
$metrics = $validationResult->getQualityMetrics();
foreach ($metrics as $metric => $value) {
    echo "📊 {$metric}: {$value}%";
}
';
        
        echo $codeExample . "\n";
        
        echo "🔍 Key integration points:\n";
        echo "  1. Validate API data before processing\n";
        echo "  2. Use quality scores to decide on data acceptance\n";
        echo "  3. Log validation results for monitoring\n";
        echo "  4. Handle anomalies and quality issues appropriately\n";
        echo "  5. Integrate with ETL logging for full traceability\n";
        
    } catch (Exception $e) {
        echo "❌ Error: " . $e->getMessage() . "\n";
    }
}

// Example 6: Validation statistics and monitoring
function validationStatisticsExample() {
    echo "\n=== Validation Statistics Example ===\n";
    
    try {
        $pdo = getDatabaseConnection();
        $validator = new DataValidator($pdo);
        
        // Simulate some validation batches
        echo "📊 Simulating validation batches for statistics...\n";
        
        $testBatches = [
            [
                'name' => 'Morning Batch',
                'data' => generateTestData(150, 0.95), // 95% quality
                'batch_id' => 'morning_batch_' . date('Ymd')
            ],
            [
                'name' => 'Afternoon Batch',
                'data' => generateTestData(200, 0.88), // 88% quality
                'batch_id' => 'afternoon_batch_' . date('Ymd')
            ],
            [
                'name' => 'Evening Batch',
                'data' => generateTestData(100, 0.92), // 92% quality
                'batch_id' => 'evening_batch_' . date('Ymd')
            ]
        ];
        
        foreach ($testBatches as $batch) {
            echo "  Processing {$batch['name']}...\n";
            $result = $validator->validateBatch($batch['data'], $batch['batch_id']);
            echo "    Quality Score: " . $result->getQualityScore() . "%\n";
        }
        
        // Get validation statistics
        echo "\n📈 Validation Statistics (last 7 days):\n";
        $stats = $validator->getValidationStatistics(7);
        
        if (!empty($stats)) {
            echo "  - Total batches processed: " . $stats['total_batches'] . "\n";
            echo "  - Average quality score: " . round($stats['avg_quality_score'], 2) . "%\n";
            echo "  - Total records validated: " . $stats['total_records_validated'] . "\n";
            echo "  - Total valid records: " . $stats['total_valid_records'] . "\n";
            echo "  - Total warnings: " . $stats['total_warnings'] . "\n";
            echo "  - Total anomalies: " . $stats['total_anomalies'] . "\n";
            echo "  - Average execution time: " . round($stats['avg_execution_time'], 2) . "ms\n";
            
            // Calculate derived metrics
            $validationRate = $stats['total_records_validated'] > 0 
                ? round(($stats['total_valid_records'] / $stats['total_records_validated']) * 100, 2)
                : 0;
            
            echo "  - Overall validation rate: {$validationRate}%\n";
            
            if ($stats['avg_quality_score'] >= 90) {
                echo "  ✅ System data quality: Excellent\n";
            } elseif ($stats['avg_quality_score'] >= 80) {
                echo "  ✅ System data quality: Good\n";
            } else {
                echo "  ⚠️  System data quality: Needs attention\n";
            }
        } else {
            echo "  No validation statistics available\n";
        }
        
    } catch (Exception $e) {
        echo "❌ Error: " . $e->getMessage() . "\n";
    }
}

// Helper function to generate test data with specified quality
function generateTestData(int $count, float $qualityRatio): array {
    $data = [];
    $validCount = (int)($count * $qualityRatio);
    
    // Generate valid records
    for ($i = 1; $i <= $validCount; $i++) {
        $data[] = [
            'sku' => "VALID_SKU_{$i}",
            'warehouse_name' => 'РФЦ Москва',
            'available_stock' => rand(10, 500),
            'reserved_stock' => rand(5, 50),
            'total_stock' => rand(15, 550),
            'product_name' => "Valid Product {$i}",
            'category' => 'Electronics',
            'brand' => 'Test Brand',
            'price' => rand(1000, 50000),
            'updated_at' => date('Y-m-d H:i:s')
        ];
    }
    
    // Generate invalid records
    for ($i = $validCount + 1; $i <= $count; $i++) {
        $data[] = [
            'sku' => rand(0, 1) ? '' : "INVALID_SKU_{$i}", // Sometimes missing SKU
            'warehouse_name' => rand(0, 1) ? '' : 'РФЦ СПб', // Sometimes missing warehouse
            'available_stock' => rand(0, 1) ? -rand(1, 100) : rand(10, 500), // Sometimes negative
            'reserved_stock' => rand(0, 1) ? -rand(1, 50) : rand(5, 50),
            'total_stock' => rand(0, 1) ? -rand(1, 100) : rand(15, 550),
            'product_name' => rand(0, 1) ? '' : "Invalid Product {$i}",
            'price' => rand(0, 1) ? -rand(100, 1000) : rand(1000, 50000),
            'updated_at' => date('Y-m-d H:i:s')
        ];
    }
    
    return $data;
}

// Helper function to get database connection
function getDatabaseConnection(): PDO {
    // For demo purposes, using SQLite in memory
    $pdo = new PDO('sqlite::memory:');
    
    // Create required tables
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS data_quality_log (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            validation_batch_id VARCHAR(255) NOT NULL,
            warehouse_name VARCHAR(255),
            product_id INTEGER,
            sku VARCHAR(255),
            issue_type VARCHAR(100) NOT NULL,
            issue_description TEXT,
            validation_status VARCHAR(50) NOT NULL,
            resolution_action VARCHAR(100),
            quality_score INTEGER,
            validated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            resolved_at DATETIME,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        )
    ");
    
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS validation_batches (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            validation_batch_id VARCHAR(255) UNIQUE NOT NULL,
            source_batch_id VARCHAR(255),
            total_records INTEGER DEFAULT 0,
            valid_records INTEGER DEFAULT 0,
            invalid_records INTEGER DEFAULT 0,
            warnings INTEGER DEFAULT 0,
            anomalies INTEGER DEFAULT 0,
            quality_score DECIMAL(5,2) DEFAULT 0,
            execution_time_ms INTEGER DEFAULT 0,
            status VARCHAR(50) DEFAULT 'completed',
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        )
    ");
    
    return $pdo;
}

// Main execution
if (php_sapi_name() === 'cli') {
    echo "🚀 DataValidator Examples\n";
    echo "=========================\n";
    
    // Run examples
    basicValidationExample();
    problematicDataExample();
    anomalyDetectionExample();
    qualityMetricsExample();
    integrationExample();
    validationStatisticsExample();
    
    echo "\n✅ All examples completed!\n";
}