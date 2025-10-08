<?php

require_once __DIR__ . '/../../bootstrap.php';
require_once __DIR__ . '/../../../src/MDM/Services/MatchingService.php';
require_once __DIR__ . '/../../../src/MDM/Services/ProductsService.php';

use PHPUnit\Framework\TestCase;

/**
 * Performance tests for MDM system
 * Tests system behavior under load with large data volumes
 */
class MDMPerformanceTest extends TestCase
{
    private $db;
    private $matchingService;
    private $productsService;
    private $performanceResults = [];

    protected function setUp(): void
    {
        $this->db = new PDO(
            "mysql:host=" . $_ENV['DB_HOST'] . ";dbname=" . $_ENV['TEST_DB_NAME'],
            $_ENV['DB_USER'],
            $_ENV['DB_PASS']
        );
        
        $this->matchingService = new MatchingService($this->db);
        $this->productsService = new ProductsService($this->db);
        
        $this->setupPerformanceTestData();
    }

    protected function tearDown(): void
    {
        $this->generatePerformanceReport();
        $this->cleanupPerformanceTestData();
    }

    /**
     * Test matching performance with large dataset
     * Requirements: 6.1, 6.2
     */
    public function testMatchingPerformanceWithLargeDataset()
    {
        $testSizes = [100, 500, 1000, 5000];
        
        foreach ($testSizes as $size) {
            $startTime = microtime(true);
            $memoryStart = memory_get_usage();
            
            // Generate test products
            $testProducts = $this->generateTestProducts($size);
            
            // Perform matching for each product
            $matchedCount = 0;
            foreach ($testProducts as $product) {
                $matches = $this->matchingService->findPotentialMatches(
                    $product['external_sku'],
                    $product['source']
                );
                if (!empty($matches)) {
                    $matchedCount++;
                }
            }
            
            $endTime = microtime(true);
            $memoryEnd = memory_get_usage();
            
            $executionTime = $endTime - $startTime;
            $memoryUsed = $memoryEnd - $memoryStart;
            
            $this->performanceResults[] = [
                'test' => 'matching_performance',
                'dataset_size' => $size,
                'execution_time' => $executionTime,
                'memory_used' => $memoryUsed,
                'matched_count' => $matchedCount,
                'throughput' => $size / $executionTime
            ];
            
            // Performance assertions
            $this->assertLessThan(30, $executionTime, "Matching {$size} products should take less than 30 seconds");
            $this->assertLessThan(100 * 1024 * 1024, $memoryUsed, "Memory usage should be less than 100MB");
        }
    }

    /**
     * Test bulk data loading performance
     * Requirements: 7.1, 7.3
     */
    public function testBulkDataLoadingPerformance()
    {
        $batchSizes = [50, 100, 500, 1000];
        
        foreach ($batchSizes as $batchSize) {
            $startTime = microtime(true);
            
            // Generate batch data
            $batchData = $this->generateTestProducts($batchSize);
            
            // Perform bulk insert
            $insertedCount = $this->productsService->bulkCreateMasterProducts($batchData);
            
            $endTime = microtime(true);
            $executionTime = $endTime - $startTime;
            
            $this->performanceResults[] = [
                'test' => 'bulk_loading',
                'batch_size' => $batchSize,
                'execution_time' => $executionTime,
                'inserted_count' => $insertedCount,
                'throughput' => $batchSize / $executionTime
            ];
            
            // Performance assertions
            $this->assertEquals($batchSize, $insertedCount, "All products should be inserted");
            $this->assertLessThan(10, $executionTime, "Bulk insert should take less than 10 seconds");
            
            // Cleanup for next iteration
            $this->db->exec("DELETE FROM master_products WHERE canonical_name LIKE 'PERF_TEST_%'");
        }
    }

    /**
     * Test concurrent access performance
     * Requirements: 6.1, 7.2
     */
    public function testConcurrentAccessPerformance()
    {
        $concurrentUsers = 10;
        $operationsPerUser = 50;
        
        $startTime = microtime(true);
        
        // Simulate concurrent users
        $processes = [];
        for ($i = 0; $i < $concurrentUsers; $i++) {
            $processes[] = $this->simulateConcurrentUser($operationsPerUser, $i);
        }
        
        // Wait for all processes to complete (simulated)
        $totalOperations = $concurrentUsers * $operationsPerUser;
        
        $endTime = microtime(true);
        $executionTime = $endTime - $startTime;
        
        $this->performanceResults[] = [
            'test' => 'concurrent_access',
            'concurrent_users' => $concurrentUsers,
            'operations_per_user' => $operationsPerUser,
            'total_operations' => $totalOperations,
            'execution_time' => $executionTime,
            'throughput' => $totalOperations / $executionTime
        ];
        
        // Performance assertions
        $this->assertLessThan(60, $executionTime, "Concurrent operations should complete within 60 seconds");
        $this->assertGreaterThan(10, $totalOperations / $executionTime, "Should handle at least 10 operations per second");
    }

    /**
     * Test database query performance
     * Requirements: 6.1, 6.2
     */
    public function testDatabaseQueryPerformance()
    {
        // Create test data
        $this->createLargeTestDataset(10000);
        
        $queries = [
            'find_by_name' => "SELECT * FROM master_products WHERE canonical_name LIKE '%TEST%' LIMIT 100",
            'find_by_brand' => "SELECT * FROM master_products WHERE canonical_brand = 'TEST_BRAND' LIMIT 100",
            'complex_join' => "
                SELECT mp.*, COUNT(sm.id) as sku_count 
                FROM master_products mp 
                LEFT JOIN sku_mapping sm ON mp.master_id = sm.master_id 
                WHERE mp.canonical_brand = 'TEST_BRAND' 
                GROUP BY mp.master_id 
                LIMIT 100
            "
        ];
        
        foreach ($queries as $queryName => $sql) {
            $startTime = microtime(true);
            
            $stmt = $this->db->query($sql);
            $results = $stmt->fetchAll();
            
            $endTime = microtime(true);
            $executionTime = $endTime - $startTime;
            
            $this->performanceResults[] = [
                'test' => 'query_performance',
                'query_name' => $queryName,
                'execution_time' => $executionTime,
                'result_count' => count($results)
            ];
            
            // Performance assertions
            $this->assertLessThan(2, $executionTime, "Query {$queryName} should execute in less than 2 seconds");
        }
    }

    /**
     * Test memory usage under load
     * Requirements: 6.1, 7.3
     */
    public function testMemoryUsageUnderLoad()
    {
        $initialMemory = memory_get_usage();
        
        // Process large dataset
        $largeDataset = $this->generateTestProducts(5000);
        
        $memoryAfterGeneration = memory_get_usage();
        
        // Process each product
        foreach ($largeDataset as $product) {
            $this->matchingService->findPotentialMatches($product['external_sku'], $product['source']);
        }
        
        $memoryAfterProcessing = memory_get_usage();
        $peakMemory = memory_get_peak_usage();
        
        $this->performanceResults[] = [
            'test' => 'memory_usage',
            'initial_memory' => $initialMemory,
            'memory_after_generation' => $memoryAfterGeneration,
            'memory_after_processing' => $memoryAfterProcessing,
            'peak_memory' => $peakMemory,
            'memory_increase' => $memoryAfterProcessing - $initialMemory
        ];
        
        // Memory assertions
        $memoryIncrease = $memoryAfterProcessing - $initialMemory;
        $this->assertLessThan(200 * 1024 * 1024, $memoryIncrease, "Memory increase should be less than 200MB");
        $this->assertLessThan(500 * 1024 * 1024, $peakMemory, "Peak memory should be less than 500MB");
    }

    private function generateTestProducts($count)
    {
        $products = [];
        $brands = ['BRAND_A', 'BRAND_B', 'BRAND_C', 'TEST_BRAND'];
        $categories = ['Category 1', 'Category 2', 'Category 3'];
        
        for ($i = 0; $i < $count; $i++) {
            $products[] = [
                'external_sku' => 'PERF_TEST_' . $i,
                'source' => 'test',
                'canonical_name' => 'PERF_TEST_Product_' . $i,
                'canonical_brand' => $brands[array_rand($brands)],
                'canonical_category' => $categories[array_rand($categories)]
            ];
        }
        
        return $products;
    }

    private function createLargeTestDataset($count)
    {
        $batchSize = 1000;
        $batches = ceil($count / $batchSize);
        
        for ($batch = 0; $batch < $batches; $batch++) {
            $currentBatchSize = min($batchSize, $count - ($batch * $batchSize));
            $batchData = $this->generateTestProducts($currentBatchSize);
            
            foreach ($batchData as $product) {
                $masterId = 'PERF_' . uniqid();
                $this->db->exec("
                    INSERT INTO master_products (master_id, canonical_name, canonical_brand, canonical_category) 
                    VALUES ('{$masterId}', '{$product['canonical_name']}', '{$product['canonical_brand']}', '{$product['canonical_category']}')
                ");
            }
        }
    }

    private function simulateConcurrentUser($operations, $userId)
    {
        // Simulate concurrent user operations
        for ($i = 0; $i < $operations; $i++) {
            // Simulate various operations
            $operation = rand(1, 3);
            
            switch ($operation) {
                case 1:
                    // Search operation
                    $this->matchingService->findPotentialMatches("USER_{$userId}_SKU_{$i}", 'test');
                    break;
                case 2:
                    // Create operation
                    $masterId = "USER_{$userId}_MASTER_{$i}";
                    $this->productsService->createMasterProduct([
                        'master_id' => $masterId,
                        'canonical_name' => "User {$userId} Product {$i}",
                        'canonical_brand' => "User Brand {$userId}",
                        'canonical_category' => 'Test Category'
                    ]);
                    break;
                case 3:
                    // Read operation
                    $this->productsService->getAllMasterProducts(10);
                    break;
            }
        }
        
        return true;
    }

    private function setupPerformanceTestData()
    {
        // Create necessary tables for performance testing
        $this->db->exec("
            CREATE TABLE IF NOT EXISTS master_products (
                master_id VARCHAR(50) PRIMARY KEY,
                canonical_name VARCHAR(500) NOT NULL,
                canonical_brand VARCHAR(200),
                canonical_category VARCHAR(200),
                description TEXT,
                attributes JSON,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                status ENUM('active', 'inactive', 'pending_review') DEFAULT 'active'
            )
        ");

        $this->db->exec("
            CREATE TABLE IF NOT EXISTS sku_mapping (
                id BIGINT AUTO_INCREMENT PRIMARY KEY,
                master_id VARCHAR(50) NOT NULL,
                external_sku VARCHAR(200) NOT NULL,
                source VARCHAR(50) NOT NULL,
                source_name VARCHAR(500),
                source_brand VARCHAR(200),
                source_category VARCHAR(200),
                confidence_score DECIMAL(3,2),
                verification_status ENUM('auto', 'manual', 'pending') DEFAULT 'pending',
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY unique_source_sku (source, external_sku)
            )
        ");
    }

    private function cleanupPerformanceTestData()
    {
        // Clean up test data
        $this->db->exec("DELETE FROM sku_mapping WHERE external_sku LIKE 'PERF_TEST_%' OR external_sku LIKE 'USER_%'");
        $this->db->exec("DELETE FROM master_products WHERE master_id LIKE 'PERF_%' OR master_id LIKE 'USER_%'");
    }

    private function generatePerformanceReport()
    {
        $reportPath = __DIR__ . '/../../results/mdm_performance_report_' . date('Y-m-d_H-i-s') . '.json';
        
        $report = [
            'timestamp' => date('Y-m-d H:i:s'),
            'test_environment' => [
                'php_version' => PHP_VERSION,
                'memory_limit' => ini_get('memory_limit'),
                'max_execution_time' => ini_get('max_execution_time')
            ],
            'results' => $this->performanceResults
        ];
        
        if (!is_dir(dirname($reportPath))) {
            mkdir(dirname($reportPath), 0755, true);
        }
        
        file_put_contents($reportPath, json_encode($report, JSON_PRETTY_PRINT));
        
        echo "\nPerformance report generated: {$reportPath}\n";
    }
}