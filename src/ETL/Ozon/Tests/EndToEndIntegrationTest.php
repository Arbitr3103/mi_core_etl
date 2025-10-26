<?php

/**
 * End-to-End Integration Test for Ozon Complete ETL System
 * 
 * This test validates the complete ETL pipeline:
 * 1. Product synchronization (ProductETL)
 * 2. Sales data extraction (SalesETL) 
 * 3. Inventory updates (InventoryETL)
 * 4. Data linking validation via offer_id
 * 
 * Requirements: 1.4, 2.4, 3.4
 */

require_once __DIR__ . '/../autoload.php';

use MiCore\ETL\Ozon\Core\DatabaseConnection;
use MiCore\ETL\Ozon\Core\Logger;
use MiCore\ETL\Ozon\Api\OzonApiClient;
use MiCore\ETL\Ozon\Components\ProductETL;
use MiCore\ETL\Ozon\Components\SalesETL;
use MiCore\ETL\Ozon\Components\InventoryETL;

class EndToEndIntegrationTest
{
    private DatabaseConnection $db;
    private Logger $logger;
    private OzonApiClient $apiClient;
    private array $testResults = [];
    private string $testRunId;

    public function __construct()
    {
        $this->testRunId = 'e2e_test_' . date('Y-m-d_H-i-s');
        
        // Load configuration
        $config = require __DIR__ . '/../Config/bootstrap.php';
        
        $this->logger = new Logger('EndToEndTest', $config['etl']['logging']);
        $this->db = new DatabaseConnection($config['database']['connection']);
        
        // Initialize API client with test credentials
        $this->apiClient = OzonApiClient::fromConfig($config['api'], $this->logger);
        
        $this->logger->info('End-to-End Integration Test initialized', [
            'test_run_id' => $this->testRunId
        ]);
    }

    /**
     * Run complete end-to-end test suite
     */
    public function runFullTest(): array
    {
        $this->logger->info('Starting End-to-End Integration Test');
        
        try {
            // Step 1: Validate database schema
            $this->validateDatabaseSchema();
            
            // Step 2: Test ProductETL component
            $this->testProductETL();
            
            // Step 3: Test SalesETL component  
            $this->testSalesETL();
            
            // Step 4: Test InventoryETL component
            $this->testInventoryETL();
            
            // Step 5: Validate data linking via offer_id
            $this->validateDataLinking();
            
            // Step 6: Test complete ETL pipeline
            $this->testCompleteETLPipeline();
            
            // Step 7: Performance validation
            $this->validatePerformance();
            
            $this->testResults['overall_status'] = 'SUCCESS';
            $this->logger->info('End-to-End Integration Test completed successfully');
            
        } catch (Exception $e) {
            $this->testResults['overall_status'] = 'FAILED';
            $this->testResults['error'] = $e->getMessage();
            $this->logger->error('End-to-End Integration Test failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
        }
        
        return $this->generateTestReport();
    }

    /**
     * Validate database schema exists and is properly configured
     */
    private function validateDatabaseSchema(): void
    {
        $this->logger->info('Validating database schema');
        
        $requiredTables = ['dim_products', 'fact_orders', 'inventory', 'etl_execution_log'];
        $missingTables = [];
        
        foreach ($requiredTables as $table) {
            $result = $this->db->query("
                SELECT COUNT(*) as count 
                FROM information_schema.tables 
                WHERE table_name = ? AND table_schema = 'public'
            ", [$table]);
            
            if ($result[0]['count'] == 0) {
                $missingTables[] = $table;
            }
        }
        
        if (!empty($missingTables)) {
            throw new Exception('Missing required tables: ' . implode(', ', $missingTables));
        }
        
        // Validate foreign key constraints
        $this->validateForeignKeys();
        
        // Validate indexes
        $this->validateIndexes();
        
        $this->testResults['schema_validation'] = 'PASSED';
        $this->logger->info('Database schema validation passed');
    }

    /**
     * Test ProductETL component with real API data
     */
    private function testProductETL(): void
    {
        $this->logger->info('Testing ProductETL component');
        
        $productETL = new ProductETL($this->db, $this->logger, $this->apiClient);
        
        // Get initial product count
        $initialCount = $this->getTableCount('dim_products');
        
        // Execute ProductETL
        $result = $productETL->execute();
        
        if (!$result->isSuccess()) {
            throw new Exception('ProductETL execution failed: ' . $result->getErrorMessage());
        }
        
        // Validate results
        $finalCount = $this->getTableCount('dim_products');
        $processedCount = $result->getRecordsProcessed();
        
        // Validate data quality
        $this->validateProductData();
        
        $this->testResults['product_etl'] = [
            'status' => 'PASSED',
            'initial_count' => $initialCount,
            'final_count' => $finalCount,
            'processed_count' => $processedCount,
            'execution_time' => $result->getDuration()
        ];
        
        $this->logger->info('ProductETL test passed', $this->testResults['product_etl']);
    }

    /**
     * Test SalesETL component with real API data
     */
    private function testSalesETL(): void
    {
        $this->logger->info('Testing SalesETL component');
        
        $salesETL = new SalesETL($this->db, $this->logger, $this->apiClient);
        
        // Get initial sales count
        $initialCount = $this->getTableCount('fact_orders');
        
        // Execute SalesETL
        $result = $salesETL->execute();
        
        if (!$result->isSuccess()) {
            throw new Exception('SalesETL execution failed: ' . $result->getErrorMessage());
        }
        
        // Validate results
        $finalCount = $this->getTableCount('fact_orders');
        $processedCount = $result->getRecordsProcessed();
        
        // Validate sales data quality
        $this->validateSalesData();
        
        $this->testResults['sales_etl'] = [
            'status' => 'PASSED',
            'initial_count' => $initialCount,
            'final_count' => $finalCount,
            'processed_count' => $processedCount,
            'execution_time' => $result->getDuration()
        ];
        
        $this->logger->info('SalesETL test passed', $this->testResults['sales_etl']);
    }

    /**
     * Test InventoryETL component with real API data
     */
    private function testInventoryETL(): void
    {
        $this->logger->info('Testing InventoryETL component');
        
        $inventoryETL = new InventoryETL($this->db, $this->logger, $this->apiClient);
        
        // Execute InventoryETL
        $result = $inventoryETL->execute();
        
        if (!$result->isSuccess()) {
            throw new Exception('InventoryETL execution failed: ' . $result->getErrorMessage());
        }
        
        // Validate results
        $finalCount = $this->getTableCount('inventory');
        $processedCount = $result->getRecordsProcessed();
        
        // Validate inventory data quality
        $this->validateInventoryData();
        
        $this->testResults['inventory_etl'] = [
            'status' => 'PASSED',
            'final_count' => $finalCount,
            'processed_count' => $processedCount,
            'execution_time' => $result->getDuration()
        ];
        
        $this->logger->info('InventoryETL test passed', $this->testResults['inventory_etl']);
    }

    /**
     * Validate data linking across all tables via offer_id
     * Requirements: 1.4, 2.4, 3.4
     */
    private function validateDataLinking(): void
    {
        $this->logger->info('Validating data linking via offer_id');
        
        // Test 1: Check that all sales orders reference existing products
        $orphanedSales = $this->db->query("
            SELECT COUNT(*) as count
            FROM fact_orders o
            LEFT JOIN dim_products p ON o.offer_id = p.offer_id
            WHERE p.offer_id IS NULL
        ");
        
        if ($orphanedSales[0]['count'] > 0) {
            throw new Exception('Found orphaned sales records without matching products: ' . $orphanedSales[0]['count']);
        }
        
        // Test 2: Check that all inventory records reference existing products
        $orphanedInventory = $this->db->query("
            SELECT COUNT(*) as count
            FROM inventory i
            LEFT JOIN dim_products p ON i.offer_id = p.offer_id
            WHERE p.offer_id IS NULL
        ");
        
        if ($orphanedInventory[0]['count'] > 0) {
            throw new Exception('Found orphaned inventory records without matching products: ' . $orphanedInventory[0]['count']);
        }
        
        // Test 3: Validate complete data chain (products -> sales -> inventory)
        $dataChainValidation = $this->db->query("
            SELECT 
                p.offer_id,
                p.name,
                COUNT(DISTINCT o.id) as sales_count,
                COUNT(DISTINCT i.id) as inventory_count,
                SUM(i.available) as total_available_stock
            FROM dim_products p
            LEFT JOIN fact_orders o ON p.offer_id = o.offer_id
            LEFT JOIN inventory i ON p.offer_id = i.offer_id
            WHERE p.status = 'active'
            GROUP BY p.offer_id, p.name
            HAVING COUNT(DISTINCT i.id) > 0
            LIMIT 10
        ");
        
        if (empty($dataChainValidation)) {
            throw new Exception('No complete data chains found (products with both sales and inventory)');
        }
        
        $this->testResults['data_linking'] = [
            'status' => 'PASSED',
            'orphaned_sales' => $orphanedSales[0]['count'],
            'orphaned_inventory' => $orphanedInventory[0]['count'],
            'complete_chains' => count($dataChainValidation)
        ];
        
        $this->logger->info('Data linking validation passed', $this->testResults['data_linking']);
    }

    /**
     * Test complete ETL pipeline execution
     */
    private function testCompleteETLPipeline(): void
    {
        $this->logger->info('Testing complete ETL pipeline');
        
        $startTime = microtime(true);
        
        // Execute all ETL components in sequence
        $components = [
            'ProductETL' => new ProductETL($this->db, $this->logger, $this->apiClient),
            'SalesETL' => new SalesETL($this->db, $this->logger, $this->apiClient),
            'InventoryETL' => new InventoryETL($this->db, $this->logger, $this->apiClient)
        ];
        
        $pipelineResults = [];
        
        foreach ($components as $name => $component) {
            $componentStart = microtime(true);
            $result = $component->execute();
            $componentDuration = microtime(true) - $componentStart;
            
            if (!$result->isSuccess()) {
                throw new Exception("Pipeline failed at {$name}: " . $result->getErrorMessage());
            }
            
            $pipelineResults[$name] = [
                'records_processed' => $result->getRecordsProcessed(),
                'duration' => $componentDuration
            ];
        }
        
        $totalDuration = microtime(true) - $startTime;
        
        // Validate pipeline integrity
        $this->validatePipelineIntegrity();
        
        $this->testResults['pipeline_execution'] = [
            'status' => 'PASSED',
            'total_duration' => $totalDuration,
            'components' => $pipelineResults
        ];
        
        $this->logger->info('Complete ETL pipeline test passed', $this->testResults['pipeline_execution']);
    }

    /**
     * Validate system performance meets requirements
     */
    private function validatePerformance(): void
    {
        $this->logger->info('Validating system performance');
        
        // Check recent ETL execution times
        $recentExecutions = $this->db->query("
            SELECT 
                etl_class,
                AVG(duration_seconds) as avg_duration,
                MAX(duration_seconds) as max_duration,
                COUNT(*) as execution_count
            FROM etl_execution_log
            WHERE started_at >= NOW() - INTERVAL '24 hours'
            GROUP BY etl_class
        ");
        
        $performanceIssues = [];
        
        foreach ($recentExecutions as $execution) {
            // Define performance thresholds (adjust as needed)
            $thresholds = [
                'ProductETL' => 300,    // 5 minutes
                'SalesETL' => 600,      // 10 minutes
                'InventoryETL' => 1800  // 30 minutes
            ];
            
            $threshold = $thresholds[$execution['etl_class']] ?? 300;
            
            if ($execution['max_duration'] > $threshold) {
                $performanceIssues[] = [
                    'component' => $execution['etl_class'],
                    'max_duration' => $execution['max_duration'],
                    'threshold' => $threshold
                ];
            }
        }
        
        if (!empty($performanceIssues)) {
            $this->logger->warning('Performance issues detected', $performanceIssues);
        }
        
        $this->testResults['performance_validation'] = [
            'status' => empty($performanceIssues) ? 'PASSED' : 'WARNING',
            'recent_executions' => $recentExecutions,
            'performance_issues' => $performanceIssues
        ];
        
        $this->logger->info('Performance validation completed', $this->testResults['performance_validation']);
    }

    /**
     * Helper methods for validation
     */
    private function validateForeignKeys(): void
    {
        $foreignKeys = $this->db->query("
            SELECT 
                tc.constraint_name,
                tc.table_name,
                kcu.column_name,
                ccu.table_name AS foreign_table_name,
                ccu.column_name AS foreign_column_name
            FROM information_schema.table_constraints AS tc
            JOIN information_schema.key_column_usage AS kcu
                ON tc.constraint_name = kcu.constraint_name
            JOIN information_schema.constraint_column_usage AS ccu
                ON ccu.constraint_name = tc.constraint_name
            WHERE tc.constraint_type = 'FOREIGN KEY'
                AND tc.table_name IN ('fact_orders', 'inventory')
        ");
        
        $expectedForeignKeys = [
            'fk_fact_orders_offer_id',
            'fk_inventory_offer_id'
        ];
        
        $foundKeys = array_column($foreignKeys, 'constraint_name');
        $missingKeys = array_diff($expectedForeignKeys, $foundKeys);
        
        if (!empty($missingKeys)) {
            throw new Exception('Missing foreign key constraints: ' . implode(', ', $missingKeys));
        }
    }

    private function validateIndexes(): void
    {
        $indexes = $this->db->query("
            SELECT indexname, tablename
            FROM pg_indexes
            WHERE tablename IN ('dim_products', 'fact_orders', 'inventory', 'etl_execution_log')
                AND indexname LIKE 'idx_%'
        ");
        
        $requiredIndexes = [
            'idx_dim_products_offer_id',
            'idx_fact_orders_offer_id',
            'idx_inventory_offer_id'
        ];
        
        $foundIndexes = array_column($indexes, 'indexname');
        $missingIndexes = array_diff($requiredIndexes, $foundIndexes);
        
        if (!empty($missingIndexes)) {
            throw new Exception('Missing required indexes: ' . implode(', ', $missingIndexes));
        }
    }

    private function validateProductData(): void
    {
        // Check for required fields
        $invalidProducts = $this->db->query("
            SELECT COUNT(*) as count
            FROM dim_products
            WHERE offer_id IS NULL OR offer_id = '' OR product_id IS NULL
        ");
        
        if ($invalidProducts[0]['count'] > 0) {
            throw new Exception('Found products with missing required fields: ' . $invalidProducts[0]['count']);
        }
    }

    private function validateSalesData(): void
    {
        // Check for required fields and valid data
        $invalidSales = $this->db->query("
            SELECT COUNT(*) as count
            FROM fact_orders
            WHERE offer_id IS NULL OR offer_id = '' 
                OR posting_number IS NULL OR posting_number = ''
                OR quantity <= 0
                OR in_process_at IS NULL
        ");
        
        if ($invalidSales[0]['count'] > 0) {
            throw new Exception('Found sales records with invalid data: ' . $invalidSales[0]['count']);
        }
    }

    private function validateInventoryData(): void
    {
        // Check for required fields and valid stock levels
        $invalidInventory = $this->db->query("
            SELECT COUNT(*) as count
            FROM inventory
            WHERE offer_id IS NULL OR offer_id = ''
                OR warehouse_name IS NULL OR warehouse_name = ''
                OR present < 0 OR reserved < 0 OR available < 0
        ");
        
        if ($invalidInventory[0]['count'] > 0) {
            throw new Exception('Found inventory records with invalid data: ' . $invalidInventory[0]['count']);
        }
    }

    private function validatePipelineIntegrity(): void
    {
        // Ensure all components logged their execution
        $loggedExecutions = $this->db->query("
            SELECT DISTINCT etl_class
            FROM etl_execution_log
            WHERE started_at >= NOW() - INTERVAL '1 hour'
                AND status = 'success'
        ");
        
        $expectedComponents = ['ProductETL', 'SalesETL', 'InventoryETL'];
        $loggedComponents = array_column($loggedExecutions, 'etl_class');
        $missingLogs = array_diff($expectedComponents, $loggedComponents);
        
        if (!empty($missingLogs)) {
            throw new Exception('Missing execution logs for components: ' . implode(', ', $missingLogs));
        }
    }

    private function getTableCount(string $table): int
    {
        $result = $this->db->query("SELECT COUNT(*) as count FROM {$table}");
        return (int)$result[0]['count'];
    }

    /**
     * Generate comprehensive test report
     */
    private function generateTestReport(): array
    {
        $report = [
            'test_run_id' => $this->testRunId,
            'timestamp' => date('Y-m-d H:i:s'),
            'overall_status' => $this->testResults['overall_status'],
            'test_results' => $this->testResults,
            'summary' => $this->generateSummary()
        ];
        
        // Save report to file
        $reportFile = __DIR__ . "/../Logs/e2e_test_report_{$this->testRunId}.json";
        file_put_contents($reportFile, json_encode($report, JSON_PRETTY_PRINT));
        
        $this->logger->info('Test report generated', ['report_file' => $reportFile]);
        
        return $report;
    }

    private function generateSummary(): array
    {
        $passedTests = 0;
        $totalTests = 0;
        
        foreach ($this->testResults as $key => $result) {
            if (is_array($result) && isset($result['status'])) {
                $totalTests++;
                if ($result['status'] === 'PASSED') {
                    $passedTests++;
                }
            }
        }
        
        return [
            'total_tests' => $totalTests,
            'passed_tests' => $passedTests,
            'success_rate' => $totalTests > 0 ? round(($passedTests / $totalTests) * 100, 2) : 0,
            'recommendations' => $this->generateRecommendations()
        ];
    }

    private function generateRecommendations(): array
    {
        $recommendations = [];
        
        // Check performance issues
        if (isset($this->testResults['performance_validation']['performance_issues']) 
            && !empty($this->testResults['performance_validation']['performance_issues'])) {
            $recommendations[] = 'Consider optimizing ETL components with performance issues';
        }
        
        // Check data volumes
        if (isset($this->testResults['product_etl']['processed_count']) 
            && $this->testResults['product_etl']['processed_count'] < 100) {
            $recommendations[] = 'Low product count detected - verify API connectivity and data availability';
        }
        
        return $recommendations;
    }
}

// Execute test if run directly
if (basename(__FILE__) === basename($_SERVER['SCRIPT_NAME'])) {
    echo "Starting End-to-End Integration Test for Ozon ETL System...\n\n";
    
    try {
        $test = new EndToEndIntegrationTest();
        $report = $test->runFullTest();
        
        echo "Test Results:\n";
        echo "=============\n";
        echo "Overall Status: " . $report['overall_status'] . "\n";
        echo "Success Rate: " . $report['summary']['success_rate'] . "%\n";
        echo "Total Tests: " . $report['summary']['total_tests'] . "\n";
        echo "Passed Tests: " . $report['summary']['passed_tests'] . "\n\n";
        
        if (!empty($report['summary']['recommendations'])) {
            echo "Recommendations:\n";
            foreach ($report['summary']['recommendations'] as $recommendation) {
                echo "- " . $recommendation . "\n";
            }
            echo "\n";
        }
        
        echo "Detailed report saved to: Logs/e2e_test_report_{$report['test_run_id']}.json\n";
        
        if ($report['overall_status'] === 'SUCCESS') {
            echo "\n✅ End-to-End Integration Test PASSED\n";
            exit(0);
        } else {
            echo "\n❌ End-to-End Integration Test FAILED\n";
            if (isset($report['test_results']['error'])) {
                echo "Error: " . $report['test_results']['error'] . "\n";
            }
            exit(1);
        }
        
    } catch (Exception $e) {
        echo "\n❌ Test execution failed: " . $e->getMessage() . "\n";
        exit(1);
    }
}