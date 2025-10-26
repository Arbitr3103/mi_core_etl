<?php

/**
 * Simple test runner for SalesETL tests
 * 
 * This script provides a basic way to verify that the SalesETL tests
 * can be instantiated and basic functionality works without requiring
 * a full PHPUnit setup.
 */

require_once __DIR__ . '/../autoload.php';

echo "SalesETL Test Verification\n";
echo "==========================\n\n";

try {
    // Test 1: Verify classes can be loaded
    echo "1. Testing class loading...\n";
    
    if (class_exists('MiCore\ETL\Ozon\Components\SalesETL')) {
        echo "   ✓ SalesETL class loaded successfully\n";
    } else {
        echo "   ✗ SalesETL class not found\n";
        exit(1);
    }
    
    if (class_exists('MiCore\ETL\Ozon\Tests\SalesETLTest')) {
        echo "   ✓ SalesETLTest class loaded successfully\n";
    } else {
        echo "   ✗ SalesETLTest class not found\n";
        exit(1);
    }
    
    // Test 2: Verify test class can be instantiated
    echo "\n2. Testing test class instantiation...\n";
    
    // Create a simple mock setup
    $mockDb = new class {
        public function beginTransaction() {}
        public function commit() {}
        public function rollback() {}
        public function query($sql, $params = []) { return []; }
        public function execute($sql, $params = []) {}
    };
    
    $mockLogger = new class {
        public function info($message, $context = []) {}
        public function debug($message, $context = []) {}
        public function warning($message, $context = []) {}
        public function error($message, $context = []) {}
        public function critical($message, $context = []) {}
        public function logPerformance($operation, $duration, $context = []) {}
    };
    
    $mockApiClient = new class {
        public function getSalesHistory($since, $to, $limit = 1000, $offset = 0, $additionalFilters = []) {
            return [
                'result' => [
                    'postings' => [
                        [
                            'posting_number' => 'ORDER-001',
                            'in_process_at' => '2023-01-01T10:00:00Z',
                            'warehouse_id' => 'WH-001',
                            'products' => [
                                [
                                    'sku' => 'TEST-001',
                                    'quantity' => 2,
                                    'price' => 100.50
                                ]
                            ]
                        ]
                    ]
                ]
            ];
        }
    };
    
    // Test SalesETL instantiation
    $salesETL = new \MiCore\ETL\Ozon\Components\SalesETL(
        $mockDb,
        $mockLogger,
        $mockApiClient,
        ['batch_size' => 100, 'days_back' => 30, 'enable_progress' => false, 'incremental_load' => true]
    );
    
    echo "   ✓ SalesETL instance created successfully\n";
    
    // Test 3: Basic functionality test
    echo "\n3. Testing basic functionality...\n";
    
    // Test transformation with sample data
    $sampleData = [
        [
            'posting_number' => 'ORDER-001',
            'in_process_at' => '2023-01-01T10:00:00Z',
            'warehouse_id' => 'WH-001',
            'products' => [
                [
                    'sku' => 'TEST-001',
                    'quantity' => 2,
                    'price' => 100.50
                ],
                [
                    'sku' => 'TEST-002',
                    'quantity' => 1,
                    'price' => 50.25
                ]
            ]
        ]
    ];
    
    $transformed = $salesETL->transform($sampleData);
    
    if (count($transformed) === 2 && $transformed[0]['offer_id'] === 'TEST-001' && $transformed[1]['offer_id'] === 'TEST-002') {
        echo "   ✓ Data transformation works correctly (products array processing)\n";
    } else {
        echo "   ✗ Data transformation failed\n";
        echo "   Expected 2 items, got " . count($transformed) . "\n";
        if (!empty($transformed)) {
            echo "   First item offer_id: " . ($transformed[0]['offer_id'] ?? 'missing') . "\n";
        }
        exit(1);
    }
    
    // Test date normalization
    if ($transformed[0]['in_process_at'] === '2023-01-01 10:00:00') {
        echo "   ✓ Date normalization works correctly\n";
    } else {
        echo "   ✗ Date normalization failed\n";
        echo "   Expected: 2023-01-01 10:00:00, got: " . ($transformed[0]['in_process_at'] ?? 'null') . "\n";
        exit(1);
    }
    
    // Test stats functionality
    $stats = $salesETL->getSalesETLStats();
    if (isset($stats['config']) && isset($stats['metrics'])) {
        echo "   ✓ Statistics functionality works correctly\n";
    } else {
        echo "   ✗ Statistics functionality failed\n";
        exit(1);
    }
    
    // Test validation with invalid data
    echo "\n4. Testing validation with invalid data...\n";
    
    $invalidData = [
        [
            // Missing posting_number
            'products' => [
                [
                    'sku' => 'TEST-001',
                    'quantity' => 1
                ]
            ]
        ],
        [
            'posting_number' => 'ORDER-002',
            'products' => [
                [
                    // Missing sku
                    'quantity' => 1
                ]
            ]
        ]
    ];
    
    $invalidTransformed = $salesETL->transform($invalidData);
    
    if (count($invalidTransformed) === 0) {
        echo "   ✓ Invalid data validation works correctly\n";
    } else {
        echo "   ✗ Invalid data validation failed - should have returned 0 items\n";
        exit(1);
    }
    
    echo "\n✓ All basic tests passed!\n";
    echo "\nTo run full PHPUnit tests, use:\n";
    echo "vendor/bin/phpunit src/ETL/Ozon/Tests/SalesETLTest.php\n";
    
} catch (Exception $e) {
    echo "\n✗ Test failed with error: " . $e->getMessage() . "\n";
    echo "Stack trace:\n" . $e->getTraceAsString() . "\n";
    exit(1);
}