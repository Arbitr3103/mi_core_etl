<?php

/**
 * Data Linking Validation Test for Ozon ETL System
 * 
 * This test specifically validates the correctness of data linking
 * across all tables via offer_id as specified in requirements 1.4, 2.4, 3.4
 * 
 * Requirements: 1.4, 2.4, 3.4
 */

require_once __DIR__ . '/../autoload.php';

use MiCore\ETL\Ozon\Core\DatabaseConnection;
use MiCore\ETL\Ozon\Core\Logger;

class DataLinkingValidationTest
{
    private DatabaseConnection $db;
    private Logger $logger;
    private array $validationResults = [];

    public function __construct()
    {
        // Load configuration
        $config = require __DIR__ . '/../Config/bootstrap.php';
        
        $this->logger = new Logger('DataLinkingValidation', $config['etl']['logging']);
        $this->db = new DatabaseConnection($config['database']['connection']);
        
        $this->logger->info('Data Linking Validation Test initialized');
    }

    /**
     * Run comprehensive data linking validation
     */
    public function runValidation(): array
    {
        $this->logger->info('Starting data linking validation');
        
        try {
            // Test 1: Validate offer_id consistency across tables
            $this->validateOfferIdConsistency();
            
            // Test 2: Validate foreign key relationships
            $this->validateForeignKeyRelationships();
            
            // Test 3: Validate data completeness
            $this->validateDataCompleteness();
            
            // Test 4: Validate business logic consistency
            $this->validateBusinessLogicConsistency();
            
            // Test 5: Validate data quality metrics
            $this->validateDataQualityMetrics();
            
            // Test 6: Generate data linking report
            $this->generateDataLinkingReport();
            
            $this->validationResults['overall_status'] = 'SUCCESS';
            $this->logger->info('Data linking validation completed successfully');
            
        } catch (Exception $e) {
            $this->validationResults['overall_status'] = 'FAILED';
            $this->validationResults['error'] = $e->getMessage();
            $this->logger->error('Data linking validation failed', [
                'error' => $e->getMessage()
            ]);
        }
        
        return $this->validationResults;
    }

    /**
     * Test 1: Validate offer_id consistency across all tables
     * Requirement 1.4: offer_id as primary key for linking all data
     */
    private function validateOfferIdConsistency(): void
    {
        $this->logger->info('Validating offer_id consistency');
        
        // Check offer_id format consistency
        $inconsistentOfferIds = $this->db->query("
            SELECT 'dim_products' as table_name, offer_id, 'Invalid format' as issue
            FROM dim_products 
            WHERE offer_id IS NULL OR offer_id = '' OR LENGTH(offer_id) < 3
            
            UNION ALL
            
            SELECT 'fact_orders' as table_name, offer_id, 'Invalid format' as issue
            FROM fact_orders 
            WHERE offer_id IS NULL OR offer_id = '' OR LENGTH(offer_id) < 3
            
            UNION ALL
            
            SELECT 'inventory' as table_name, offer_id, 'Invalid format' as issue
            FROM inventory 
            WHERE offer_id IS NULL OR offer_id = '' OR LENGTH(offer_id) < 3
        ");
        
        if (!empty($inconsistentOfferIds)) {
            throw new Exception('Found ' . count($inconsistentOfferIds) . ' records with invalid offer_id format');
        }
        
        // Check for duplicate offer_ids in dim_products (should be unique)
        $duplicateProducts = $this->db->query("
            SELECT offer_id, COUNT(*) as count
            FROM dim_products
            GROUP BY offer_id
            HAVING COUNT(*) > 1
        ");
        
        if (!empty($duplicateProducts)) {
            throw new Exception('Found duplicate offer_ids in dim_products: ' . count($duplicateProducts));
        }
        
        $this->validationResults['offer_id_consistency'] = [
            'status' => 'PASSED',
            'invalid_formats' => count($inconsistentOfferIds),
            'duplicate_products' => count($duplicateProducts)
        ];
        
        $this->logger->info('Offer ID consistency validation passed');
    }

    /**
     * Test 2: Validate foreign key relationships
     * Requirement 2.4, 3.4: Proper linking between tables
     */
    private function validateForeignKeyRelationships(): void
    {
        $this->logger->info('Validating foreign key relationships');
        
        // Check orphaned sales records (sales without matching products)
        $orphanedSales = $this->db->query("
            SELECT o.offer_id, COUNT(*) as order_count
            FROM fact_orders o
            LEFT JOIN dim_products p ON o.offer_id = p.offer_id
            WHERE p.offer_id IS NULL
            GROUP BY o.offer_id
        ");
        
        // Check orphaned inventory records (inventory without matching products)
        $orphanedInventory = $this->db->query("
            SELECT i.offer_id, COUNT(*) as inventory_count
            FROM inventory i
            LEFT JOIN dim_products p ON i.offer_id = p.offer_id
            WHERE p.offer_id IS NULL
            GROUP BY i.offer_id
        ");
        
        // Check products without any related data
        $isolatedProducts = $this->db->query("
            SELECT p.offer_id, p.name
            FROM dim_products p
            LEFT JOIN fact_orders o ON p.offer_id = o.offer_id
            LEFT JOIN inventory i ON p.offer_id = i.offer_id
            WHERE o.offer_id IS NULL AND i.offer_id IS NULL
            AND p.status = 'active'
        ");
        
        $this->validationResults['foreign_key_relationships'] = [
            'status' => (empty($orphanedSales) && empty($orphanedInventory)) ? 'PASSED' : 'FAILED',
            'orphaned_sales_count' => count($orphanedSales),
            'orphaned_inventory_count' => count($orphanedInventory),
            'isolated_products_count' => count($isolatedProducts),
            'orphaned_sales' => array_slice($orphanedSales, 0, 10), // First 10 for debugging
            'orphaned_inventory' => array_slice($orphanedInventory, 0, 10)
        ];
        
        if (!empty($orphanedSales)) {
            throw new Exception('Found ' . count($orphanedSales) . ' orphaned sales records without matching products');
        }
        
        if (!empty($orphanedInventory)) {
            throw new Exception('Found ' . count($orphanedInventory) . ' orphaned inventory records without matching products');
        }
        
        $this->logger->info('Foreign key relationships validation passed');
    }

    /**
     * Test 3: Validate data completeness
     */
    private function validateDataCompleteness(): void
    {
        $this->logger->info('Validating data completeness');
        
        // Get counts for each table
        $productCount = $this->getTableCount('dim_products');
        $salesCount = $this->getTableCount('fact_orders');
        $inventoryCount = $this->getTableCount('inventory');
        
        // Check for reasonable data volumes
        if ($productCount < 1) {
            throw new Exception('No products found in dim_products table');
        }
        
        // Calculate data coverage metrics
        $coverageMetrics = $this->db->query("
            SELECT 
                COUNT(DISTINCT p.offer_id) as total_products,
                COUNT(DISTINCT o.offer_id) as products_with_sales,
                COUNT(DISTINCT i.offer_id) as products_with_inventory,
                COUNT(DISTINCT CASE WHEN o.offer_id IS NOT NULL AND i.offer_id IS NOT NULL THEN p.offer_id END) as products_with_both
            FROM dim_products p
            LEFT JOIN fact_orders o ON p.offer_id = o.offer_id
            LEFT JOIN inventory i ON p.offer_id = i.offer_id
            WHERE p.status = 'active' OR p.status IS NULL
        ");
        
        $metrics = $coverageMetrics[0];
        $salesCoverage = $metrics['total_products'] > 0 ? 
            round(($metrics['products_with_sales'] / $metrics['total_products']) * 100, 2) : 0;
        $inventoryCoverage = $metrics['total_products'] > 0 ? 
            round(($metrics['products_with_inventory'] / $metrics['total_products']) * 100, 2) : 0;
        $completeCoverage = $metrics['total_products'] > 0 ? 
            round(($metrics['products_with_both'] / $metrics['total_products']) * 100, 2) : 0;
        
        $this->validationResults['data_completeness'] = [
            'status' => 'PASSED',
            'product_count' => $productCount,
            'sales_count' => $salesCount,
            'inventory_count' => $inventoryCount,
            'coverage_metrics' => [
                'total_products' => $metrics['total_products'],
                'products_with_sales' => $metrics['products_with_sales'],
                'products_with_inventory' => $metrics['products_with_inventory'],
                'products_with_both' => $metrics['products_with_both'],
                'sales_coverage_percent' => $salesCoverage,
                'inventory_coverage_percent' => $inventoryCoverage,
                'complete_coverage_percent' => $completeCoverage
            ]
        ];
        
        $this->logger->info('Data completeness validation passed', [
            'sales_coverage' => $salesCoverage . '%',
            'inventory_coverage' => $inventoryCoverage . '%',
            'complete_coverage' => $completeCoverage . '%'
        ]);
    }

    /**
     * Test 4: Validate business logic consistency
     */
    private function validateBusinessLogicConsistency(): void
    {
        $this->logger->info('Validating business logic consistency');
        
        // Check for negative inventory values
        $negativeInventory = $this->db->query("
            SELECT offer_id, warehouse_name, present, reserved, available
            FROM inventory
            WHERE present < 0 OR reserved < 0 OR available < 0
        ");
        
        // Check for inventory calculation errors (available should equal present - reserved)
        $inventoryCalculationErrors = $this->db->query("
            SELECT offer_id, warehouse_name, present, reserved, available,
                   (present - reserved) as calculated_available
            FROM inventory
            WHERE available != (present - reserved)
        ");
        
        // Check for sales with zero or negative quantities
        $invalidSales = $this->db->query("
            SELECT posting_number, offer_id, quantity
            FROM fact_orders
            WHERE quantity <= 0
        ");
        
        // Check for future dates in sales data
        $futureSales = $this->db->query("
            SELECT posting_number, offer_id, in_process_at
            FROM fact_orders
            WHERE in_process_at > NOW()
        ");
        
        $businessLogicIssues = [];
        
        if (!empty($negativeInventory)) {
            $businessLogicIssues[] = 'Negative inventory values: ' . count($negativeInventory);
        }
        
        if (!empty($inventoryCalculationErrors)) {
            $businessLogicIssues[] = 'Inventory calculation errors: ' . count($inventoryCalculationErrors);
        }
        
        if (!empty($invalidSales)) {
            $businessLogicIssues[] = 'Invalid sales quantities: ' . count($invalidSales);
        }
        
        if (!empty($futureSales)) {
            $businessLogicIssues[] = 'Future sales dates: ' . count($futureSales);
        }
        
        $this->validationResults['business_logic_consistency'] = [
            'status' => empty($businessLogicIssues) ? 'PASSED' : 'FAILED',
            'issues' => $businessLogicIssues,
            'negative_inventory_count' => count($negativeInventory),
            'calculation_errors_count' => count($inventoryCalculationErrors),
            'invalid_sales_count' => count($invalidSales),
            'future_sales_count' => count($futureSales)
        ];
        
        if (!empty($businessLogicIssues)) {
            throw new Exception('Business logic consistency issues found: ' . implode(', ', $businessLogicIssues));
        }
        
        $this->logger->info('Business logic consistency validation passed');
    }

    /**
     * Test 5: Validate data quality metrics
     */
    private function validateDataQualityMetrics(): void
    {
        $this->logger->info('Validating data quality metrics');
        
        // Calculate data freshness
        $dataFreshness = $this->db->query("
            SELECT 
                'dim_products' as table_name,
                MAX(updated_at) as last_update,
                COUNT(*) as record_count
            FROM dim_products
            
            UNION ALL
            
            SELECT 
                'inventory' as table_name,
                MAX(updated_at) as last_update,
                COUNT(*) as record_count
            FROM inventory
            
            UNION ALL
            
            SELECT 
                'fact_orders' as table_name,
                MAX(created_at) as last_update,
                COUNT(*) as record_count
            FROM fact_orders
        ");
        
        // Check for stale data (older than 24 hours)
        $staleDataThreshold = date('Y-m-d H:i:s', strtotime('-24 hours'));
        $staleData = [];
        
        foreach ($dataFreshness as $table) {
            if ($table['last_update'] < $staleDataThreshold) {
                $staleData[] = $table['table_name'];
            }
        }
        
        // Calculate data quality score
        $qualityMetrics = $this->calculateDataQualityScore();
        
        $this->validationResults['data_quality_metrics'] = [
            'status' => empty($staleData) && $qualityMetrics['overall_score'] >= 80 ? 'PASSED' : 'WARNING',
            'data_freshness' => $dataFreshness,
            'stale_tables' => $staleData,
            'quality_score' => $qualityMetrics
        ];
        
        $this->logger->info('Data quality metrics validation completed', [
            'overall_score' => $qualityMetrics['overall_score'],
            'stale_tables' => count($staleData)
        ]);
    }

    /**
     * Test 6: Generate comprehensive data linking report
     */
    private function generateDataLinkingReport(): void
    {
        $this->logger->info('Generating data linking report');
        
        // Sample data chains for verification
        $sampleDataChains = $this->db->query("
            SELECT 
                p.offer_id,
                p.name,
                p.status,
                COUNT(DISTINCT o.id) as sales_records,
                SUM(o.quantity) as total_quantity_sold,
                COUNT(DISTINCT i.warehouse_name) as warehouses_count,
                SUM(i.available) as total_available_stock,
                MAX(o.in_process_at) as last_sale_date,
                MAX(i.updated_at) as inventory_last_updated
            FROM dim_products p
            LEFT JOIN fact_orders o ON p.offer_id = o.offer_id
            LEFT JOIN inventory i ON p.offer_id = i.offer_id
            WHERE p.status = 'active'
            GROUP BY p.offer_id, p.name, p.status
            HAVING COUNT(DISTINCT o.id) > 0 OR COUNT(DISTINCT i.warehouse_name) > 0
            ORDER BY total_quantity_sold DESC NULLS LAST
            LIMIT 20
        ");
        
        $this->validationResults['data_linking_report'] = [
            'status' => 'COMPLETED',
            'sample_data_chains' => $sampleDataChains,
            'validation_timestamp' => date('Y-m-d H:i:s'),
            'summary' => $this->generateLinkingSummary()
        ];
        
        $this->logger->info('Data linking report generated');
    }

    /**
     * Helper methods
     */
    private function getTableCount(string $table): int
    {
        $result = $this->db->query("SELECT COUNT(*) as count FROM {$table}");
        return (int)$result[0]['count'];
    }

    private function calculateDataQualityScore(): array
    {
        // Calculate various quality metrics
        $completenessScore = $this->calculateCompletenessScore();
        $consistencyScore = $this->calculateConsistencyScore();
        $validityScore = $this->calculateValidityScore();
        
        $overallScore = round(($completenessScore + $consistencyScore + $validityScore) / 3, 2);
        
        return [
            'overall_score' => $overallScore,
            'completeness_score' => $completenessScore,
            'consistency_score' => $consistencyScore,
            'validity_score' => $validityScore
        ];
    }

    private function calculateCompletenessScore(): float
    {
        // Check for missing required fields
        $totalProducts = $this->getTableCount('dim_products');
        
        if ($totalProducts == 0) {
            return 0;
        }
        
        $completeProducts = $this->db->query("
            SELECT COUNT(*) as count
            FROM dim_products
            WHERE offer_id IS NOT NULL 
                AND offer_id != ''
                AND product_id IS NOT NULL
                AND name IS NOT NULL
                AND name != ''
        ");
        
        return round(($completeProducts[0]['count'] / $totalProducts) * 100, 2);
    }

    private function calculateConsistencyScore(): float
    {
        // Check for data consistency across tables
        $totalLinks = $this->db->query("
            SELECT COUNT(DISTINCT p.offer_id) as count
            FROM dim_products p
            WHERE EXISTS (SELECT 1 FROM fact_orders o WHERE o.offer_id = p.offer_id)
                OR EXISTS (SELECT 1 FROM inventory i WHERE i.offer_id = p.offer_id)
        ");
        
        $brokenLinks = $this->db->query("
            SELECT COUNT(*) as count
            FROM (
                SELECT offer_id FROM fact_orders 
                WHERE offer_id NOT IN (SELECT offer_id FROM dim_products WHERE offer_id IS NOT NULL)
                UNION
                SELECT offer_id FROM inventory 
                WHERE offer_id NOT IN (SELECT offer_id FROM dim_products WHERE offer_id IS NOT NULL)
            ) broken
        ");
        
        $totalChecks = $totalLinks[0]['count'] + $brokenLinks[0]['count'];
        
        if ($totalChecks == 0) {
            return 100;
        }
        
        return round((($totalChecks - $brokenLinks[0]['count']) / $totalChecks) * 100, 2);
    }

    private function calculateValidityScore(): float
    {
        // Check for valid data formats and ranges
        $totalRecords = $this->getTableCount('dim_products') + 
                       $this->getTableCount('fact_orders') + 
                       $this->getTableCount('inventory');
        
        if ($totalRecords == 0) {
            return 0;
        }
        
        $invalidRecords = $this->db->query("
            SELECT COUNT(*) as count FROM (
                SELECT 1 FROM dim_products WHERE offer_id IS NULL OR offer_id = '' OR LENGTH(offer_id) < 3
                UNION ALL
                SELECT 1 FROM fact_orders WHERE quantity <= 0 OR in_process_at > NOW()
                UNION ALL
                SELECT 1 FROM inventory WHERE present < 0 OR reserved < 0 OR available < 0
            ) invalid
        ");
        
        return round((($totalRecords - $invalidRecords[0]['count']) / $totalRecords) * 100, 2);
    }

    private function generateLinkingSummary(): array
    {
        $summary = $this->db->query("
            SELECT 
                (SELECT COUNT(*) FROM dim_products) as total_products,
                (SELECT COUNT(DISTINCT offer_id) FROM fact_orders) as products_with_sales,
                (SELECT COUNT(DISTINCT offer_id) FROM inventory) as products_with_inventory,
                (SELECT COUNT(*) FROM fact_orders) as total_sales_records,
                (SELECT COUNT(*) FROM inventory) as total_inventory_records
        ");
        
        return $summary[0];
    }
}

// Execute test if run directly
if (basename(__FILE__) === basename($_SERVER['SCRIPT_NAME'])) {
    echo "Starting Data Linking Validation Test for Ozon ETL System...\n\n";
    
    try {
        $test = new DataLinkingValidationTest();
        $results = $test->runValidation();
        
        echo "Data Linking Validation Results:\n";
        echo "================================\n";
        echo "Overall Status: " . $results['overall_status'] . "\n\n";
        
        foreach ($results as $testName => $result) {
            if (is_array($result) && isset($result['status'])) {
                $status = $result['status'] === 'PASSED' ? '✅' : 
                         ($result['status'] === 'WARNING' ? '⚠️' : '❌');
                echo "{$status} {$testName}: {$result['status']}\n";
            }
        }
        
        if ($results['overall_status'] === 'SUCCESS') {
            echo "\n✅ Data Linking Validation PASSED\n";
            echo "All data is properly linked via offer_id across tables.\n";
            exit(0);
        } else {
            echo "\n❌ Data Linking Validation FAILED\n";
            if (isset($results['error'])) {
                echo "Error: " . $results['error'] . "\n";
            }
            exit(1);
        }
        
    } catch (Exception $e) {
        echo "\n❌ Validation execution failed: " . $e->getMessage() . "\n";
        exit(1);
    }
}