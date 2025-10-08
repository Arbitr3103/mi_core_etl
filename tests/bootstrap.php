<?php

/**
 * PHPUnit Bootstrap File for MDM System Tests
 * 
 * Sets up the testing environment and autoloading
 */

// Set error reporting for tests
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Set timezone
date_default_timezone_set('UTC');

// Define test constants
define('TEST_ROOT', __DIR__);
define('PROJECT_ROOT', dirname(__DIR__));

// Composer autoloader (if available)
$autoloaderPath = PROJECT_ROOT . '/vendor/autoload.php';
if (file_exists($autoloaderPath)) {
    $autoloader = require $autoloaderPath;
    // Add src directory to autoloader for MDM namespace
    $autoloader->addPsr4('MDM\\', PROJECT_ROOT . '/src/');
} else {
    // Manual autoloader for testing without Composer
    spl_autoload_register(function ($class) {
        $prefix = 'MDM\\';
        $baseDir = PROJECT_ROOT . '/src/';
        
        $len = strlen($prefix);
        if (strncmp($prefix, $class, $len) !== 0) {
            return;
        }
        
        $relativeClass = substr($class, $len);
        $file = $baseDir . str_replace('\\', '/', $relativeClass) . '.php';
        
        if (file_exists($file)) {
            require $file;
        }
    });
}

// Create results directory if it doesn't exist
$resultsDir = TEST_ROOT . '/results';
if (!is_dir($resultsDir)) {
    mkdir($resultsDir, 0755, true);
}

// Test helper functions
function createTestMasterProduct(string $masterId, ?string $name = null, ?string $brand = null, ?string $category = null): \MDM\Models\MasterProduct
{
    return new \MDM\Models\MasterProduct(
        $masterId,
        $name ?? "Test Product {$masterId}",
        $brand ?? 'Test Brand',
        $category ?? 'Test Category',
        'Test description',
        ['test_attribute' => 'test_value'],
        '1234567890123'
    );
}

function generateTestProductData(string $sku, array $overrides = []): array
{
    $defaults = [
        'sku' => $sku,
        'name' => "Test Product {$sku}",
        'brand' => 'Test Brand',
        'category' => 'Test Category',
        'description' => 'Test product description',
        'barcode' => '1234567890123',
        'source' => 'test'
    ];

    return array_merge($defaults, $overrides);
}

function assertMatchingResult(array $result, \PHPUnit\Framework\TestCase $testCase): void
{
    $testCase->assertArrayHasKey('master_product_id', $result);
    $testCase->assertArrayHasKey('confidence_score', $result);
    $testCase->assertArrayHasKey('decision', $result);
    $testCase->assertArrayHasKey('match_details', $result);
    $testCase->assertArrayHasKey('reasoning', $result);
    
    $testCase->assertIsString($result['master_product_id']);
    $testCase->assertIsFloat($result['confidence_score']);
    $testCase->assertIsString($result['decision']);
    $testCase->assertIsArray($result['match_details']);
    $testCase->assertIsString($result['reasoning']);
    
    $testCase->assertGreaterThanOrEqual(0.0, $result['confidence_score']);
    $testCase->assertLessThanOrEqual(1.0, $result['confidence_score']);
}

// Memory and performance tracking
class TestPerformanceTracker
{
    private static array $measurements = [];
    
    public static function startMeasurement(string $name): void
    {
        self::$measurements[$name] = [
            'start_time' => microtime(true),
            'start_memory' => memory_get_usage()
        ];
    }
    
    public static function endMeasurement(string $name): array
    {
        if (!isset(self::$measurements[$name])) {
            throw new InvalidArgumentException("Measurement '{$name}' not started");
        }
        
        $measurement = self::$measurements[$name];
        $endTime = microtime(true);
        $endMemory = memory_get_usage();
        
        $result = [
            'execution_time' => $endTime - $measurement['start_time'],
            'memory_used' => $endMemory - $measurement['start_memory'],
            'peak_memory' => memory_get_peak_usage() - $measurement['start_memory']
        ];
        
        unset(self::$measurements[$name]);
        return $result;
    }
    
    public static function getAllMeasurements(): array
    {
        return self::$measurements;
    }
}

// Test data generators
class TestDataGenerator
{
    public static function generateMasterProducts(int $count): array
    {
        $products = [];
        $brands = ['Samsung', 'Apple', 'Sony', 'LG', 'Bosch', 'Philips'];
        $categories = ['Electronics', 'Appliances', 'Auto Parts', 'Clothing', 'Food'];
        
        for ($i = 0; $i < $count; $i++) {
            $brand = $brands[$i % count($brands)];
            $category = $categories[$i % count($categories)];
            
            $products[] = new \MDM\Models\MasterProduct(
                "MASTER_GEN_{$i}",
                "Generated Product {$i} {$brand}",
                $brand,
                $category,
                "Generated description for product {$i}",
                ['generated' => true, 'index' => $i],
                sprintf('%013d', 1000000000000 + $i)
            );
        }
        
        return $products;
    }
    
    public static function generateProductDataBatch(int $count): array
    {
        $batch = [];
        
        for ($i = 0; $i < $count; $i++) {
            $batch[] = [
                'sku' => "GEN_SKU_{$i}",
                'name' => "Generated Product {$i}",
                'brand' => 'Generated Brand',
                'category' => 'Generated Category',
                'description' => "Generated description {$i}",
                'source' => 'generator'
            ];
        }
        
        return $batch;
    }
}

echo "MDM System Test Bootstrap Loaded\n";
echo "Test environment initialized\n";
echo "Available test helpers: createTestMasterProduct, generateTestProductData, assertMatchingResult\n";
echo "Performance tracker: TestPerformanceTracker\n";
echo "Data generator: TestDataGenerator\n";