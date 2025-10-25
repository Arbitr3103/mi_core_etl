<?php
/**
 * AnalyticsETL Service Usage Examples
 * 
 * Demonstrates how to use the AnalyticsETL service for orchestrating
 * the complete ETL process: Extract → Transform → Load
 * 
 * Requirements: 2.1, 2.2, 2.3
 * Task: 4.4 Создать AnalyticsETL сервис (основной оркестратор)
 */

require_once __DIR__ . '/../src/Services/AnalyticsETL.php';
require_once __DIR__ . '/../src/Services/AnalyticsApiClient.php';
require_once __DIR__ . '/../src/Services/DataValidator.php';
require_once __DIR__ . '/../src/Services/WarehouseNormalizer.php';

echo "=== AnalyticsETL Service Examples ===\n\n";

// Example 1: Basic ETL Setup and Execution
echo "1. Basic ETL Setup and Execution\n";
echo "================================\n";

try {
    // Database connection
    $pdo = new PDO(
        'pgsql:host=localhost;dbname=warehouse_db',
        'username',
        'password',
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
    
    // Initialize services
    $apiClient = new AnalyticsApiClient([
        'base_url' => 'https://api.analytics.example.com',
        'api_key' => 'your_api_key_here',
        'timeout' => 30,
        'rate_limit' => 100
    ]);
    
    $validator = new DataValidator([
        'enable_anomaly_detection' => true,
        'quality_thresholds' => [
            'completeness' => 0.95,
            'accuracy' => 0.90,
            'consistency' => 0.85
        ]
    ]);
    
    $normalizer = new WarehouseNormalizer($pdo, [
        'fuzzy_threshold' => 0.8,
        'enable_learning' => true
    ]);
    
    // Create ETL orchestrator
    $etl = new AnalyticsETL(
        $apiClient,
        $validator,
        $normalizer,
        $pdo,
        [
            'load_batch_size' => 1000,
            'min_quality_score' => 80.0,
            'enable_audit_logging' => true
        ]
    );
    
    echo "✓ ETL services initialized successfully\n";
    
    // Execute incremental sync
    echo "\nExecuting incremental ETL sync...\n";
    $result = $etl->executeETL(AnalyticsETL::TYPE_INCREMENTAL_SYNC);
    
    if ($result->isSuccessful()) {
        echo "✓ ETL completed successfully!\n";
        echo "  - Batch ID: {$result->getBatchId()}\n";
        echo "  - Status: {$result->getStatus()}\n";
        echo "  - Records processed: {$result->getTotalRecordsProcessed()}\n";
        echo "  - Records inserted: {$result->getRecordsInserted()}\n";
        echo "  - Records updated: {$result->getRecordsUpdated()}\n";
        echo "  - Execution time: {$result->getExecutionTime()}ms\n";
        echo "  - Quality score: {$result->getQualityScore()}%\n";
    } else {
        echo "✗ ETL failed: {$result->getErrorMessage()}\n";
    }
    
} catch (Exception $e) {
    echo "✗ Error: {$e->getMessage()}\n";
}

echo "\n" . str_repeat("-", 50) . "\n\n";

// Example 2: Different ETL Types
echo "2. Different ETL Types\n";
echo "=====================\n";

$etlTypes = [
    AnalyticsETL::TYPE_FULL_SYNC => 'Full synchronization (all data)',
    AnalyticsETL::TYPE_INCREMENTAL_SYNC => 'Incremental sync (changes only)',
    AnalyticsETL::TYPE_MANUAL_SYNC => 'Manual sync (user-triggered)',
    AnalyticsETL::TYPE_VALIDATION_ONLY => 'Validation only (no data load)'
];

foreach ($etlTypes as $type => $description) {
    echo "\nETL Type: {$type}\n";
    echo "Description: {$description}\n";
    
    try {
        // For demo purposes, we'll just show the status
        $status = $etl->getETLStatus();
        echo "Current status: {$status['status']}\n";
        
        // In real usage, you would execute:
        // $result = $etl->executeETL($type);
        
    } catch (Exception $e) {
        echo "Error: {$e->getMessage()}\n";
    }
}

echo "\n" . str_repeat("-", 50) . "\n\n";

// Example 3: ETL with Custom Options
echo "3. ETL with Custom Options\n";
echo "=========================\n";

try {
    $customOptions = [
        'filters' => [
            'warehouse_names' => ['Москва РФЦ', 'СПб МРФЦ'],
            'date_from' => '2024-01-01',
            'date_to' => '2024-12-31',
            'categories' => ['electronics', 'clothing']
        ],
        'batch_size' => 500,
        'enable_validation' => true,
        'enable_normalization' => true
    ];
    
    echo "Custom ETL options:\n";
    echo json_encode($customOptions, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
    
    // Execute with custom options
    echo "\nExecuting ETL with custom options...\n";
    // $result = $etl->executeETL(AnalyticsETL::TYPE_MANUAL_SYNC, $customOptions);
    
    echo "✓ ETL configuration prepared\n";
    
} catch (Exception $e) {
    echo "✗ Error: {$e->getMessage()}\n";
}

echo "\n" . str_repeat("-", 50) . "\n\n";

// Example 4: ETL Status Monitoring
echo "4. ETL Status Monitoring\n";
echo "=======================\n";

try {
    // Get current ETL status
    $status = $etl->getETLStatus();
    
    echo "Current ETL Status:\n";
    echo "- Status: {$status['status']}\n";
    echo "- Current batch: " . ($status['current_batch_id'] ?? 'none') . "\n";
    echo "- Started at: " . ($status['started_at'] ?? 'not started') . "\n";
    echo "- Last update: {$status['last_update']}\n";
    
    // Get ETL statistics
    echo "\nETL Statistics (last 7 days):\n";
    $stats = $etl->getETLStatistics(7);
    
    if (!empty($stats)) {
        echo "- Total runs: {$stats['total_runs']}\n";
        echo "- Successful runs: {$stats['successful_runs']}\n";
        echo "- Failed runs: {$stats['failed_runs']}\n";
        echo "- Average execution time: " . round($stats['avg_execution_time_ms']) . "ms\n";
        echo "- Total records processed: {$stats['total_records_processed']}\n";
        echo "- Last run: {$stats['last_run_at']}\n";
    } else {
        echo "No statistics available\n";
    }
    
} catch (Exception $e) {
    echo "✗ Error: {$e->getMessage()}\n";
}

echo "\n" . str_repeat("-", 50) . "\n\n";

// Example 5: Error Handling and Recovery
echo "5. Error Handling and Recovery\n";
echo "=============================\n";

try {
    echo "Demonstrating ETL error handling...\n";
    
    // Simulate ETL execution with potential errors
    class ETLErrorDemo {
        public static function simulateETLWithErrors(AnalyticsETL $etl): void {
            try {
                // This would normally execute ETL
                echo "1. Attempting ETL execution...\n";
                
                // Simulate different error scenarios
                $errorScenarios = [
                    'API timeout' => 'Analytics API is temporarily unavailable',
                    'Data quality' => 'Data quality below acceptable threshold',
                    'Database error' => 'Database connection lost during load phase',
                    'Validation error' => 'Critical validation errors detected'
                ];
                
                foreach ($errorScenarios as $scenario => $description) {
                    echo "\nScenario: {$scenario}\n";
                    echo "Description: {$description}\n";
                    echo "Recovery action: Retry with exponential backoff\n";
                }
                
                echo "\n✓ Error handling scenarios documented\n";
                
            } catch (ETLException $e) {
                echo "ETL Error in phase '{$e->getPhase()}': {$e->getMessage()}\n";
                
                // Recovery strategies
                echo "Recovery strategies:\n";
                echo "- Retry failed phase with different parameters\n";
                echo "- Skip problematic records and continue\n";
                echo "- Alert administrators for manual intervention\n";
                echo "- Rollback changes if in transaction\n";
                
            } catch (Exception $e) {
                echo "General error: {$e->getMessage()}\n";
                echo "Fallback: Log error and schedule retry\n";
            }
        }
    }
    
    ETLErrorDemo::simulateETLWithErrors($etl);
    
} catch (Exception $e) {
    echo "✗ Error: {$e->getMessage()}\n";
}

echo "\n" . str_repeat("-", 50) . "\n\n";

// Example 6: ETL Performance Optimization
echo "6. ETL Performance Optimization\n";
echo "==============================\n";

try {
    echo "ETL Performance Tips:\n\n";
    
    $performanceTips = [
        'Batch Size Optimization' => [
            'description' => 'Adjust batch size based on available memory and network latency',
            'recommendation' => 'Start with 1000 records, monitor memory usage',
            'config' => ['load_batch_size' => 1000]
        ],
        'Memory Management' => [
            'description' => 'Limit in-memory records to prevent out-of-memory errors',
            'recommendation' => 'Set max_memory_records based on available RAM',
            'config' => ['max_memory_records' => 10000]
        ],
        'Quality Thresholds' => [
            'description' => 'Balance data quality requirements with processing speed',
            'recommendation' => 'Set minimum quality score based on business needs',
            'config' => ['min_quality_score' => 80.0]
        ],
        'Parallel Processing' => [
            'description' => 'Process multiple warehouses in parallel when possible',
            'recommendation' => 'Use separate ETL instances for independent data sources',
            'config' => ['enable_parallel_processing' => true]
        ]
    ];
    
    foreach ($performanceTips as $tip => $details) {
        echo "{$tip}:\n";
        echo "  Description: {$details['description']}\n";
        echo "  Recommendation: {$details['recommendation']}\n";
        echo "  Config: " . json_encode($details['config']) . "\n\n";
    }
    
    // Example optimized configuration
    echo "Optimized ETL Configuration Example:\n";
    $optimizedConfig = [
        'load_batch_size' => 2000,
        'max_memory_records' => 20000,
        'min_quality_score' => 85.0,
        'enable_audit_logging' => true,
        'retry_failed_batches' => true,
        'max_retries' => 3,
        'retry_delay' => 5
    ];
    
    echo json_encode($optimizedConfig, JSON_PRETTY_PRINT) . "\n";
    
} catch (Exception $e) {
    echo "✗ Error: {$e->getMessage()}\n";
}

echo "\n" . str_repeat("=", 50) . "\n";
echo "AnalyticsETL Examples Complete!\n";
echo str_repeat("=", 50) . "\n";

/**
 * Helper function to format execution time
 */
function formatExecutionTime(int $milliseconds): string {
    if ($milliseconds < 1000) {
        return "{$milliseconds}ms";
    } elseif ($milliseconds < 60000) {
        return round($milliseconds / 1000, 2) . "s";
    } else {
        $minutes = floor($milliseconds / 60000);
        $seconds = round(($milliseconds % 60000) / 1000, 2);
        return "{$minutes}m {$seconds}s";
    }
}

/**
 * Helper function to format file size
 */
function formatDataSize(int $records): string {
    if ($records < 1000) {
        return "{$records} records";
    } elseif ($records < 1000000) {
        return round($records / 1000, 1) . "K records";
    } else {
        return round($records / 1000000, 1) . "M records";
    }
}