<?php

/**
 * Simple functionality test for SalesETL without PHPUnit
 */

require_once __DIR__ . '/../autoload.php';

echo "SalesETL Simple Functionality Test\n";
echo "==================================\n\n";

try {
    // Test 1: Verify SalesETL class can be loaded
    echo "1. Testing SalesETL class loading...\n";
    
    if (class_exists('MiCore\ETL\Ozon\Components\SalesETL')) {
        echo "   ✓ SalesETL class loaded successfully\n";
    } else {
        echo "   ✗ SalesETL class not found\n";
        exit(1);
    }
    
    // Test 2: Create mock dependencies
    echo "\n2. Creating mock dependencies...\n";
    
    $mockDb = new class {
        public function beginTransaction() {}
        public function commit() {}
        public function rollback() {}
        public function query($sql, $params = []) { return []; }
        public function execute($sql, $params = []) { return 0; }
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
                    'postings' => []
                ]
            ];
        }
    };
    
    echo "   ✓ Mock dependencies created\n";
    
    // Test 3: Instantiate SalesETL
    echo "\n3. Testing SalesETL instantiation...\n";
    
    $salesETL = new \MiCore\ETL\Ozon\Components\SalesETL(
        $mockDb,
        $mockLogger,
        $mockApiClient,
        [
            'batch_size' => 100,
            'days_back' => 30,
            'enable_progress' => false,
            'incremental_load' => true
        ]
    );
    
    echo "   ✓ SalesETL instance created successfully\n";
    
    // Test 4: Test data transformation
    echo "\n4. Testing data transformation...\n";
    
    $sampleOrderData = [
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
        ],
        [
            'posting_number' => 'ORDER-002',
            'in_process_at' => '2023-01-01T11:00:00Z',
            'warehouse_id' => null,
            'products' => [
                [
                    'sku' => '  TEST-003  ', // Test trimming
                    'quantity' => '3', // Test string to int conversion
                    'price' => null // Test null price
                ]
            ]
        ]
    ];
    
    $transformed = $salesETL->transform($sampleOrderData);
    
    // Verify transformation results
    if (count($transformed) === 3) {
        echo "   ✓ Correct number of order items transformed (3)\n";
    } else {
        echo "   ✗ Expected 3 transformed items, got " . count($transformed) . "\n";
        exit(1);
    }
    
    // Check first order item
    if ($transformed[0]['posting_number'] === 'ORDER-001' && 
        $transformed[0]['offer_id'] === 'TEST-001' &&
        $transformed[0]['quantity'] === 2 &&
        $transformed[0]['price'] === 100.50) {
        echo "   ✓ First order item transformed correctly\n";
    } else {
        echo "   ✗ First order item transformation failed\n";
        print_r($transformed[0]);
        exit(1);
    }
    
    // Check date normalization
    if ($transformed[0]['in_process_at'] === '2023-01-01 10:00:00') {
        echo "   ✓ Date normalization works correctly\n";
    } else {
        echo "   ✗ Date normalization failed: " . $transformed[0]['in_process_at'] . "\n";
        exit(1);
    }
    
    // Check trimming and type conversion
    if ($transformed[2]['offer_id'] === 'TEST-003' && 
        $transformed[2]['quantity'] === 3 &&
        $transformed[2]['price'] === null) {
        echo "   ✓ String trimming and type conversion work correctly\n";
    } else {
        echo "   ✗ String trimming or type conversion failed\n";
        print_r($transformed[2]);
        exit(1);
    }
    
    // Test 5: Test validation with invalid data
    echo "\n5. Testing validation with invalid data...\n";
    
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
        ],
        [
            'posting_number' => 'ORDER-003',
            'products' => [] // Empty products array
        ]
    ];
    
    $invalidTransformed = $salesETL->transform($invalidData);
    
    if (count($invalidTransformed) === 0) {
        echo "   ✓ Invalid data properly filtered out\n";
    } else {
        echo "   ✗ Invalid data validation failed - got " . count($invalidTransformed) . " items\n";
        exit(1);
    }
    
    // Test 6: Test statistics functionality
    echo "\n6. Testing statistics functionality...\n";
    
    $stats = $salesETL->getSalesETLStats();
    
    if (isset($stats['config']) && isset($stats['metrics'])) {
        echo "   ✓ Statistics structure is correct\n";
    } else {
        echo "   ✗ Statistics structure is invalid\n";
        exit(1);
    }
    
    if ($stats['config']['batch_size'] === 100 &&
        $stats['config']['days_back'] === 30 &&
        $stats['config']['incremental_load'] === true) {
        echo "   ✓ Configuration values are correct\n";
    } else {
        echo "   ✗ Configuration values are incorrect\n";
        print_r($stats['config']);
        exit(1);
    }
    
    // Test 7: Test configuration validation
    echo "\n7. Testing configuration validation...\n";
    
    try {
        new \MiCore\ETL\Ozon\Components\SalesETL(
            $mockDb,
            $mockLogger,
            $mockApiClient,
            ['batch_size' => 0] // Invalid batch size
        );
        echo "   ✗ Configuration validation failed - should have thrown exception\n";
        exit(1);
    } catch (InvalidArgumentException $e) {
        if (strpos($e->getMessage(), 'Batch size must be between 1 and 1000') !== false) {
            echo "   ✓ Batch size validation works correctly\n";
        } else {
            echo "   ✗ Unexpected validation error: " . $e->getMessage() . "\n";
            exit(1);
        }
    }
    
    try {
        new \MiCore\ETL\Ozon\Components\SalesETL(
            $mockDb,
            $mockLogger,
            $mockApiClient,
            ['days_back' => 0] // Invalid days back
        );
        echo "   ✗ Days back validation failed - should have thrown exception\n";
        exit(1);
    } catch (InvalidArgumentException $e) {
        if (strpos($e->getMessage(), 'Days back must be between 1 and 365') !== false) {
            echo "   ✓ Days back validation works correctly\n";
        } else {
            echo "   ✗ Unexpected validation error: " . $e->getMessage() . "\n";
            exit(1);
        }
    }
    
    echo "\n✓ All SalesETL functionality tests passed!\n";
    echo "\nSalesETL component is working correctly and ready for use.\n";
    
} catch (Exception $e) {
    echo "\n✗ Test failed with error: " . $e->getMessage() . "\n";
    echo "Stack trace:\n" . $e->getTraceAsString() . "\n";
    exit(1);
}