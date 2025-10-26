<?php

/**
 * Simple test runner for ProductETL tests
 * 
 * This script provides a basic way to verify that the ProductETL tests
 * can be instantiated and basic functionality works without requiring
 * a full PHPUnit setup.
 */

require_once __DIR__ . '/../autoload.php';

echo "ProductETL Test Verification\n";
echo "===========================\n\n";

try {
    // Test 1: Verify classes can be loaded
    echo "1. Testing class loading...\n";
    
    if (class_exists('MiCore\ETL\Ozon\Components\ProductETL')) {
        echo "   ✓ ProductETL class loaded successfully\n";
    } else {
        echo "   ✗ ProductETL class not found\n";
        exit(1);
    }
    
    if (class_exists('MiCore\ETL\Ozon\Tests\ProductETLTest')) {
        echo "   ✓ ProductETLTest class loaded successfully\n";
    } else {
        echo "   ✗ ProductETLTest class not found\n";
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
        public function getProducts($limit = 1000, $lastId = null, $filter = []) {
            return [
                'result' => [
                    'items' => [
                        [
                            'product_id' => 123,
                            'offer_id' => 'TEST-001',
                            'name' => 'Test Product',
                            'fbo_sku' => 'FBO-001',
                            'fbs_sku' => null,
                            'status' => 'active'
                        ]
                    ],
                    'last_id' => null
                ]
            ];
        }
    };
    
    // Test ProductETL instantiation
    $productETL = new \MiCore\ETL\Ozon\Components\ProductETL(
        $mockDb,
        $mockLogger,
        $mockApiClient,
        ['batch_size' => 100, 'max_products' => 0, 'enable_progress' => false]
    );
    
    echo "   ✓ ProductETL instance created successfully\n";
    
    // Test 3: Basic functionality test
    echo "\n3. Testing basic functionality...\n";
    
    // Test transformation with sample data
    $sampleData = [
        [
            'product_id' => 123,
            'offer_id' => 'TEST-001',
            'name' => 'Test Product',
            'fbo_sku' => 'FBO-001',
            'fbs_sku' => null,
            'status' => 'active'
        ]
    ];
    
    $transformed = $productETL->transform($sampleData);
    
    if (count($transformed) === 1 && $transformed[0]['offer_id'] === 'TEST-001') {
        echo "   ✓ Data transformation works correctly\n";
    } else {
        echo "   ✗ Data transformation failed\n";
        exit(1);
    }
    
    // Test stats functionality
    $stats = $productETL->getProductETLStats();
    if (isset($stats['config']) && isset($stats['metrics'])) {
        echo "   ✓ Statistics functionality works correctly\n";
    } else {
        echo "   ✗ Statistics functionality failed\n";
        exit(1);
    }
    
    echo "\n✓ All basic tests passed!\n";
    echo "\nTo run full PHPUnit tests, use:\n";
    echo "vendor/bin/phpunit src/ETL/Ozon/Tests/ProductETLTest.php\n";
    
} catch (Exception $e) {
    echo "\n✗ Test failed with error: " . $e->getMessage() . "\n";
    echo "Stack trace:\n" . $e->getTraceAsString() . "\n";
    exit(1);
}