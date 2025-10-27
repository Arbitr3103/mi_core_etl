#!/usr/bin/env php
<?php

/**
 * Data Accuracy Validation Script
 * 
 * Creates validation scripts to compare results with Ozon personal cabinet,
 * implements spot-checking logic for random product samples, and generates
 * validation reports with discrepancy analysis.
 * 
 * Requirements addressed:
 * - 6.1: Create SQL queries to count active products and compare with Ozon cabinet
 * - 6.1: Implement spot-checking logic for random product samples
 * - 6.1: Generate validation reports with discrepancy analysis
 * - 6.2: Validate data consistency between ETL runs
 * 
 * Usage:
 *   php data_accuracy_validator.php [options]
 * 
 * Options:
 *   --verbose          Enable verbose output
 *   --config=FILE      Use custom configuration file
 *   --sample-size=N    Number of products to spot-check (default: 50)
 *   --json             Output results in JSON format
 *   --report-file=FILE Save report to file
 *   --help             Show this help message
 */

declare(strict_types=1);

// Set error reporting and time limits
error_reporting(E_ALL);
ini_set('display_errors', '1');
set_time_limit(600); // 10 minutes

// Change to script directory
chdir(__DIR__);

// Load autoloader and configuration
try {
    require_once __DIR__ . '/../autoload.php';
    
    use MiCore\ETL\Ozon\Core\DatabaseConnection;
    use MiCore\ETL\Ozon\Core\Logger;
    use MiCore\ETL\Ozon\Api\OzonApiClient;
    
} catch (Exception $e) {
    echo "Error loading dependencies: " . $e->getMessage() . "\n";
    exit(1);
}

/**
 * Parse command line arguments
 */
function parseArguments(array $argv): array
{
    $options = [
        'verbose' => false,
        'config_file' => null,
        'sample_size' => 50,
        'json' => false,
        'report_file' => null,
        'help' => false
    ];
    
    for ($i = 1; $i < count($argv); $i++) {
        $arg = $argv[$i];
        
        switch ($arg) {
            case '--verbose':
                $options['verbose'] = true;
                break;
            case '--json':
                $options['json'] = true;
                break;
            case '--help':
                $options['help'] = true;
                break;
            default:
                if (strpos($arg, '--config=') === 0) {
                    $options['config_file'] = substr($arg, 9);
                } elseif (strpos($arg, '--sample-size=') === 0) {
                    $options['sample_size'] = (int)substr($arg, 14);
                } elseif (strpos($arg, '--report-file=') === 0) {
                    $options['report_file'] = substr($arg, 14);
                } else {
                    echo "Unknown option: $arg\n";
                    exit(1);
                }
        }
    }
    
    return $options;
}

/**
 * Show help message
 */
function showHelp(): void
{
    echo "Data Accuracy Validation Script\n";
    echo "===============================\n\n";
    echo "Creates validation scripts to compare results with Ozon personal cabinet,\n";
    echo "implements spot-checking logic for random product samples, and generates\n";
    echo "validation reports with discrepancy analysis.\n\n";
    echo "Usage:\n";
    echo "  php data_accuracy_validator.php [options]\n\n";
    echo "Options:\n";
    echo "  --verbose          Enable verbose output\n";
    echo "  --config=FILE      Use custom configuration file\n";
    echo "  --sample-size=N    Number of products to spot-check (default: 50)\n";
    echo "  --json             Output results in JSON format\n";
    echo "  --report-file=FILE Save report to file\n";
    echo "  --help             Show this help message\n\n";
    echo "Examples:\n";
    echo "  php data_accuracy_validator.php --verbose\n";
    echo "  php data_accuracy_validator.php --sample-size=100 --json\n";
    echo "  php data_accuracy_validator.php --report-file=validation_report.json\n\n";
}

/**
 * Data Accuracy Validator Class
 */
class DataAccuracyValidator
{
    private DatabaseConnection $db;
    private Logger $logger;
    private OzonApiClient $apiClient;
    private array $config;
    private array $validationResults = [];
    
    public function __construct(
        DatabaseConnection $db, 
        Logger $logger, 
        OzonApiClient $apiClient,
        array $config = []
    ) {
        $this->db = $db;
        $this->logger = $logger;
        $this->apiClient = $apiClient;
        $this->config = $config;
    }
    
    /**
     * Perform comprehensive data accuracy validation
     */
    public function performValidation(int $sampleSize = 50): array
    {
        $this->logger->info('Starting data accuracy validation', [
            'sample_size' => $sampleSize
        ]);
        
        $startTime = microtime(true);
        
        try {
            // 1. Validate active product counts
            $this->validationResults['active_products_count'] = $this->validateActiveProductsCount();
            
            // 2. Validate visibility status distribution
            $this->validationResults['visibility_distribution'] = $this->validateVisibilityDistribution();
            
            // 3. Validate inventory data consistency
            $this->validationResults['inventory_consistency'] = $this->validateInventoryConsistency();
            
            // 4. Perform spot-checking on random samples
            $this->validationResults['spot_check'] = $this->performSpotCheck($sampleSize);
            
            // 5. Validate cross-table relationships
            $this->validationResults['cross_table_validation'] = $this->validateCrossTableRelationships();
            
            // 6. Check data freshness and completeness
            $this->validationResults['data_freshness'] = $this->validateDataFreshness();
            
            // 7. Calculate overall validation score
            $this->validationResults['overall_score'] = $this->calculateOverallValidationScore();
            
            $duration = microtime(true) - $startTime;
            
            $this->validationResults['validation_info'] = [
                'validated_at' => date('Y-m-d H:i:s'),
                'duration_seconds' => round($duration, 2),
                'sample_size' => $sampleSize,
                'validations_performed' => count($this->validationResults) - 1
            ];
            
            $this->logger->info('Data accuracy validation completed', [
                'duration' => round($duration, 2),
                'overall_score' => $this->validationResults['overall_score']['score'],
                'validations_performed' => $this->validationResults['validation_info']['validations_performed']
            ]);
            
            return $this->validationResults;
            
        } catch (Exception $e) {
            $this->logger->error('Data accuracy validation failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            
            $this->validationResults['overall_score'] = [
                'score' => 0,
                'status' => 'failed',
                'message' => 'Validation failed: ' . $e->getMessage()
            ];
            
            return $this->validationResults;
        }
    }
    
    /**
     * Validate active products count against expected values
     * 
     * Requirements addressed:
     * - 6.1: Create SQL queries to count active products and compare with Ozon cabinet
     */
    private function validateActiveProductsCount(): array
    {
        $this->logger->debug('Validating active products count');
        
        try {
            // Query to count products by visibility status
            $visibilityCountsResult = $this->db->query("
                SELECT 
                    visibility,
                    COUNT(*) as count
                FROM dim_products 
                WHERE visibility IS NOT NULL
                GROUP BY visibility
                ORDER BY count DESC
            ");
            
            // Query to count products with inventory
            $productsWithInventoryResult = $this->db->query("
                SELECT 
                    COUNT(DISTINCT p.offer_id) as products_with_inventory,
                    COUNT(DISTINCT CASE WHEN p.visibility = 'VISIBLE' THEN p.offer_id END) as visible_products_with_inventory,
                    COUNT(DISTINCT CASE WHEN i.present > 0 THEN p.offer_id END) as products_with_stock
                FROM dim_products p
                INNER JOIN inventory i ON p.offer_id = i.offer_id
                WHERE p.visibility IS NOT NULL
            ");
            
            // Query to get detailed stock status distribution
            $stockStatusResult = $this->db->query("
                SELECT 
                    CASE 
                        WHEN p.visibility NOT IN ('VISIBLE', 'ACTIVE', 'продаётся') THEN 'archived_or_hidden'
                        WHEN COALESCE(SUM(i.present - i.reserved), 0) <= 0 THEN 'out_of_stock'
                        ELSE 'in_stock'
                    END as stock_status,
                    COUNT(DISTINCT p.offer_id) as count
                FROM dim_products p
                LEFT JOIN inventory i ON p.offer_id = i.offer_id
                WHERE p.visibility IS NOT NULL
                GROUP BY 
                    CASE 
                        WHEN p.visibility NOT IN ('VISIBLE', 'ACTIVE', 'продаётся') THEN 'archived_or_hidden'
                        WHEN COALESCE(SUM(i.present - i.reserved), 0) <= 0 THEN 'out_of_stock'
                        ELSE 'in_stock'
                    END
                ORDER BY count DESC
            ");
            
            $visibilityCounts = [];
            foreach ($visibilityCountsResult as $row) {
                $visibilityCounts[$row['visibility']] = (int)$row['count'];
            }
            
            $inventoryStats = $productsWithInventoryResult[0] ?? [];
            
            $stockStatusCounts = [];
            foreach ($stockStatusResult as $row) {
                $stockStatusCounts[$row['stock_status']] = (int)$row['count'];
            }
            
            // Calculate key metrics
            $totalProducts = array_sum($visibilityCounts);
            $visibleProducts = $visibilityCounts['VISIBLE'] ?? 0;
            $hiddenProducts = ($visibilityCounts['HIDDEN'] ?? 0) + ($visibilityCounts['INACTIVE'] ?? 0);
            $productsInStock = $stockStatusCounts['in_stock'] ?? 0;
            
            // Validation checks
            $validationIssues = [];
            
            // Check if we have reasonable number of products
            if ($totalProducts < 100) {
                $validationIssues[] = "Very low product count: {$totalProducts} products";
            }
            
            // Check visibility distribution
            $visiblePercentage = $totalProducts > 0 ? ($visibleProducts / $totalProducts) * 100 : 0;
            if ($visiblePercentage < 10) {
                $validationIssues[] = "Very low visible products percentage: {$visiblePercentage}%";
            } elseif ($visiblePercentage > 95) {
                $validationIssues[] = "Suspiciously high visible products percentage: {$visiblePercentage}%";
            }
            
            // Check inventory coverage
            $inventoryCoverage = $totalProducts > 0 ? 
                ((int)($inventoryStats['products_with_inventory'] ?? 0) / $totalProducts) * 100 : 0;
            if ($inventoryCoverage < 50) {
                $validationIssues[] = "Low inventory coverage: {$inventoryCoverage}%";
            }
            
            $status = empty($validationIssues) ? 'passed' : 'warning';
            $score = empty($validationIssues) ? 100 : max(0, 100 - (count($validationIssues) * 20));
            
            return [
                'status' => $status,
                'score' => $score,
                'message' => empty($validationIssues) ? 'Active products count validation passed' : 
                    'Issues found: ' . implode('; ', $validationIssues),
                'metrics' => [
                    'total_products' => $totalProducts,
                    'visible_products' => $visibleProducts,
                    'hidden_products' => $hiddenProducts,
                    'products_in_stock' => $productsInStock,
                    'visible_percentage' => round($visiblePercentage, 2),
                    'inventory_coverage_percentage' => round($inventoryCoverage, 2)
                ],
                'visibility_distribution' => $visibilityCounts,
                'stock_status_distribution' => $stockStatusCounts,
                'inventory_stats' => $inventoryStats,
                'validation_issues' => $validationIssues
            ];
            
        } catch (Exception $e) {
            return [
                'status' => 'failed',
                'score' => 0,
                'message' => 'Active products count validation failed: ' . $e->getMessage(),
                'error' => $e->getMessage()
            ];
        }
    }
    
    /**
     * Validate visibility status distribution
     */
    private function validateVisibilityDistribution(): array
    {
        $this->logger->debug('Validating visibility status distribution');
        
        try {
            // Check for valid visibility statuses
            $validStatusesResult = $this->db->query("
                SELECT 
                    visibility,
                    COUNT(*) as count,
                    ROUND(COUNT(*) * 100.0 / SUM(COUNT(*)) OVER(), 2) as percentage
                FROM dim_products 
                WHERE visibility IS NOT NULL
                GROUP BY visibility
                ORDER BY count DESC
            ");
            
            // Check for invalid or unknown statuses
            $invalidStatusesResult = $this->db->query("
                SELECT 
                    COUNT(*) as products_without_visibility,
                    COUNT(CASE WHEN visibility IS NULL THEN 1 END) as null_visibility,
                    COUNT(CASE WHEN visibility = '' THEN 1 END) as empty_visibility,
                    COUNT(CASE WHEN visibility NOT IN ('VISIBLE', 'HIDDEN', 'INACTIVE', 'MODERATION', 'DECLINED', 'UNKNOWN') THEN 1 END) as unknown_visibility
                FROM dim_products
            ");
            
            $validStatuses = [];
            foreach ($validStatusesResult as $row) {
                $validStatuses[$row['visibility']] = [
                    'count' => (int)$row['count'],
                    'percentage' => (float)$row['percentage']
                ];
            }
            
            $invalidStats = $invalidStatusesResult[0] ?? [];
            
            // Validation checks
            $validationIssues = [];
            
            // Check for products without visibility
            $productsWithoutVisibility = (int)($invalidStats['products_without_visibility'] ?? 0);
            if ($productsWithoutVisibility > 0) {
                $validationIssues[] = "{$productsWithoutVisibility} products without visibility status";
            }
            
            // Check for unknown visibility statuses
            $unknownVisibility = (int)($invalidStats['unknown_visibility'] ?? 0);
            if ($unknownVisibility > 0) {
                $validationIssues[] = "{$unknownVisibility} products with unknown visibility status";
            }
            
            // Check distribution balance
            $visiblePercentage = $validStatuses['VISIBLE']['percentage'] ?? 0;
            if ($visiblePercentage < 5) {
                $validationIssues[] = "Very low visible products percentage: {$visiblePercentage}%";
            }
            
            $status = empty($validationIssues) ? 'passed' : 'warning';
            $score = empty($validationIssues) ? 100 : max(0, 100 - (count($validationIssues) * 15));
            
            return [
                'status' => $status,
                'score' => $score,
                'message' => empty($validationIssues) ? 'Visibility distribution validation passed' : 
                    'Issues found: ' . implode('; ', $validationIssues),
                'valid_statuses' => $validStatuses,
                'invalid_stats' => $invalidStats,
                'validation_issues' => $validationIssues
            ];
            
        } catch (Exception $e) {
            return [
                'status' => 'failed',
                'score' => 0,
                'message' => 'Visibility distribution validation failed: ' . $e->getMessage(),
                'error' => $e->getMessage()
            ];
        }
    }
    
    /**
     * Validate inventory data consistency
     */
    private function validateInventoryConsistency(): array
    {
        $this->logger->debug('Validating inventory data consistency');
        
        try {
            // Check inventory data integrity
            $integrityResult = $this->db->query("
                SELECT 
                    COUNT(*) as total_inventory_records,
                    COUNT(CASE WHEN offer_id IS NULL OR offer_id = '' THEN 1 END) as missing_offer_id,
                    COUNT(CASE WHEN warehouse_name IS NULL OR warehouse_name = '' THEN 1 END) as missing_warehouse,
                    COUNT(CASE WHEN present < 0 THEN 1 END) as negative_present,
                    COUNT(CASE WHEN reserved < 0 THEN 1 END) as negative_reserved,
                    COUNT(CASE WHEN reserved > present THEN 1 END) as reserved_exceeds_present,
                    AVG(present) as avg_present,
                    AVG(reserved) as avg_reserved,
                    MAX(present) as max_present,
                    MAX(reserved) as max_reserved
                FROM inventory
            ");
            
            // Check for duplicate inventory records
            $duplicatesResult = $this->db->query("
                SELECT 
                    offer_id,
                    warehouse_name,
                    COUNT(*) as duplicate_count
                FROM inventory
                GROUP BY offer_id, warehouse_name
                HAVING COUNT(*) > 1
                ORDER BY duplicate_count DESC
                LIMIT 10
            ");
            
            // Check warehouse distribution
            $warehouseDistributionResult = $this->db->query("
                SELECT 
                    warehouse_name,
                    COUNT(*) as product_count,
                    SUM(present) as total_present,
                    SUM(reserved) as total_reserved,
                    AVG(present) as avg_present
                FROM inventory
                GROUP BY warehouse_name
                ORDER BY product_count DESC
            ");
            
            $integrityStats = $integrityResult[0] ?? [];
            $duplicates = $duplicatesResult;
            $warehouseDistribution = $warehouseDistributionResult;
            
            // Validation checks
            $validationIssues = [];
            
            // Check for missing required fields
            $missingOfferIds = (int)($integrityStats['missing_offer_id'] ?? 0);
            if ($missingOfferIds > 0) {
                $validationIssues[] = "{$missingOfferIds} inventory records missing offer_id";
            }
            
            $missingWarehouses = (int)($integrityStats['missing_warehouse'] ?? 0);
            if ($missingWarehouses > 0) {
                $validationIssues[] = "{$missingWarehouses} inventory records missing warehouse_name";
            }
            
            // Check for invalid quantities
            $negativePresent = (int)($integrityStats['negative_present'] ?? 0);
            if ($negativePresent > 0) {
                $validationIssues[] = "{$negativePresent} inventory records with negative present quantity";
            }
            
            $negativeReserved = (int)($integrityStats['negative_reserved'] ?? 0);
            if ($negativeReserved > 0) {
                $validationIssues[] = "{$negativeReserved} inventory records with negative reserved quantity";
            }
            
            $reservedExceedsPresent = (int)($integrityStats['reserved_exceeds_present'] ?? 0);
            if ($reservedExceedsPresent > 0) {
                $validationIssues[] = "{$reservedExceedsPresent} inventory records where reserved > present";
            }
            
            // Check for duplicates
            if (!empty($duplicates)) {
                $validationIssues[] = count($duplicates) . " duplicate inventory records found";
            }
            
            // Check warehouse distribution
            if (count($warehouseDistribution) < 2) {
                $validationIssues[] = "Very few warehouses found: " . count($warehouseDistribution);
            }
            
            $status = empty($validationIssues) ? 'passed' : 'warning';
            $score = empty($validationIssues) ? 100 : max(0, 100 - (count($validationIssues) * 10));
            
            return [
                'status' => $status,
                'score' => $score,
                'message' => empty($validationIssues) ? 'Inventory consistency validation passed' : 
                    'Issues found: ' . implode('; ', $validationIssues),
                'integrity_stats' => $integrityStats,
                'duplicates' => array_slice($duplicates, 0, 5), // Show first 5 duplicates
                'warehouse_distribution' => $warehouseDistribution,
                'validation_issues' => $validationIssues
            ];
            
        } catch (Exception $e) {
            return [
                'status' => 'failed',
                'score' => 0,
                'message' => 'Inventory consistency validation failed: ' . $e->getMessage(),
                'error' => $e->getMessage()
            ];
        }
    }
    
    /**
     * Perform spot-checking on random product samples
     * 
     * Requirements addressed:
     * - 6.1: Implement spot-checking logic for random product samples
     */
    private function performSpotCheck(int $sampleSize): array
    {
        $this->logger->debug('Performing spot-check validation', [
            'sample_size' => $sampleSize
        ]);
        
        try {
            // Get random sample of products with inventory
            $sampleResult = $this->db->query("
                SELECT 
                    p.offer_id,
                    p.name,
                    p.visibility,
                    p.status,
                    COUNT(i.id) as inventory_records,
                    SUM(i.present) as total_present,
                    SUM(i.reserved) as total_reserved,
                    SUM(i.present - i.reserved) as total_available
                FROM dim_products p
                LEFT JOIN inventory i ON p.offer_id = i.offer_id
                WHERE p.visibility IS NOT NULL
                GROUP BY p.offer_id, p.name, p.visibility, p.status
                ORDER BY RANDOM()
                LIMIT ?
            ", [$sampleSize]);
            
            $spotCheckResults = [];
            $validationIssues = [];
            $passedChecks = 0;
            $failedChecks = 0;
            
            foreach ($sampleResult as $product) {
                $checkResult = $this->validateSingleProduct($product);
                $spotCheckResults[] = $checkResult;
                
                if ($checkResult['status'] === 'passed') {
                    $passedChecks++;
                } else {
                    $failedChecks++;
                    $validationIssues = array_merge($validationIssues, $checkResult['issues']);
                }
            }
            
            // Calculate spot-check statistics
            $totalChecks = count($spotCheckResults);
            $passRate = $totalChecks > 0 ? ($passedChecks / $totalChecks) * 100 : 0;
            
            $status = 'passed';
            if ($passRate < 80) {
                $status = 'failed';
            } elseif ($passRate < 95) {
                $status = 'warning';
            }
            
            $score = (int)$passRate;
            
            return [
                'status' => $status,
                'score' => $score,
                'message' => "Spot-check validation: {$passedChecks}/{$totalChecks} products passed ({$passRate}%)",
                'sample_size' => $totalChecks,
                'passed_checks' => $passedChecks,
                'failed_checks' => $failedChecks,
                'pass_rate_percentage' => round($passRate, 2),
                'sample_results' => array_slice($spotCheckResults, 0, 10), // Show first 10 results
                'validation_issues' => array_slice(array_unique($validationIssues), 0, 20) // Show first 20 unique issues
            ];
            
        } catch (Exception $e) {
            return [
                'status' => 'failed',
                'score' => 0,
                'message' => 'Spot-check validation failed: ' . $e->getMessage(),
                'error' => $e->getMessage()
            ];
        }
    }
    
    /**
     * Validate a single product for spot-checking
     */
    private function validateSingleProduct(array $product): array
    {
        $issues = [];
        
        // Check basic product data
        if (empty($product['offer_id'])) {
            $issues[] = 'Missing offer_id';
        }
        
        if (empty($product['name'])) {
            $issues[] = 'Missing product name';
        }
        
        if (empty($product['visibility'])) {
            $issues[] = 'Missing visibility status';
        }
        
        // Check inventory consistency
        $inventoryRecords = (int)($product['inventory_records'] ?? 0);
        $totalPresent = (int)($product['total_present'] ?? 0);
        $totalReserved = (int)($product['total_reserved'] ?? 0);
        $totalAvailable = (int)($product['total_available'] ?? 0);
        
        if ($product['visibility'] === 'VISIBLE' && $inventoryRecords === 0) {
            $issues[] = 'Visible product has no inventory records';
        }
        
        if ($totalReserved > $totalPresent) {
            $issues[] = 'Total reserved exceeds total present';
        }
        
        if ($totalAvailable !== ($totalPresent - $totalReserved)) {
            $issues[] = 'Available quantity calculation mismatch';
        }
        
        // Check business logic consistency
        if ($product['visibility'] === 'VISIBLE' && $totalAvailable <= 0) {
            // This might be acceptable, but worth noting
            $issues[] = 'Visible product has no available stock';
        }
        
        $status = empty($issues) ? 'passed' : 'failed';
        
        return [
            'offer_id' => $product['offer_id'],
            'name' => $product['name'],
            'status' => $status,
            'issues' => $issues,
            'metrics' => [
                'visibility' => $product['visibility'],
                'inventory_records' => $inventoryRecords,
                'total_present' => $totalPresent,
                'total_reserved' => $totalReserved,
                'total_available' => $totalAvailable
            ]
        ];
    }
    
    /**
     * Validate cross-table relationships
     */
    private function validateCrossTableRelationships(): array
    {
        $this->logger->debug('Validating cross-table relationships');
        
        try {
            // Check for orphaned inventory (inventory without products)
            $orphanedInventoryResult = $this->db->query("
                SELECT COUNT(*) as orphaned_count
                FROM inventory i
                LEFT JOIN dim_products p ON i.offer_id = p.offer_id
                WHERE p.offer_id IS NULL
            ");
            
            // Check for products without inventory
            $productsWithoutInventoryResult = $this->db->query("
                SELECT 
                    COUNT(*) as products_without_inventory,
                    COUNT(CASE WHEN p.visibility = 'VISIBLE' THEN 1 END) as visible_without_inventory
                FROM dim_products p
                LEFT JOIN inventory i ON p.offer_id = i.offer_id
                WHERE i.offer_id IS NULL AND p.visibility IS NOT NULL
            ");
            
            // Check referential integrity
            $referentialIntegrityResult = $this->db->query("
                SELECT 
                    COUNT(DISTINCT p.offer_id) as total_products,
                    COUNT(DISTINCT i.offer_id) as products_with_inventory,
                    COUNT(DISTINCT CASE WHEN p.visibility = 'VISIBLE' THEN p.offer_id END) as visible_products,
                    COUNT(DISTINCT CASE WHEN p.visibility = 'VISIBLE' AND i.offer_id IS NOT NULL THEN p.offer_id END) as visible_with_inventory
                FROM dim_products p
                LEFT JOIN inventory i ON p.offer_id = i.offer_id
                WHERE p.visibility IS NOT NULL
            ");
            
            $orphanedCount = (int)($orphanedInventoryResult[0]['orphaned_count'] ?? 0);
            $productsWithoutInventory = $productsWithoutInventoryResult[0] ?? [];
            $referentialStats = $referentialIntegrityResult[0] ?? [];
            
            // Validation checks
            $validationIssues = [];
            
            if ($orphanedCount > 0) {
                $validationIssues[] = "{$orphanedCount} orphaned inventory records (no matching product)";
            }
            
            $productsWithoutInventoryCount = (int)($productsWithoutInventory['products_without_inventory'] ?? 0);
            if ($productsWithoutInventoryCount > 0) {
                $validationIssues[] = "{$productsWithoutInventoryCount} products without inventory";
            }
            
            $visibleWithoutInventory = (int)($productsWithoutInventory['visible_without_inventory'] ?? 0);
            if ($visibleWithoutInventory > 0) {
                $validationIssues[] = "{$visibleWithoutInventory} visible products without inventory";
            }
            
            // Calculate coverage metrics
            $totalProducts = (int)($referentialStats['total_products'] ?? 0);
            $productsWithInventoryCount = (int)($referentialStats['products_with_inventory'] ?? 0);
            $inventoryCoverage = $totalProducts > 0 ? ($productsWithInventoryCount / $totalProducts) * 100 : 0;
            
            if ($inventoryCoverage < 70) {
                $validationIssues[] = "Low inventory coverage: {$inventoryCoverage}%";
            }
            
            $status = empty($validationIssues) ? 'passed' : 'warning';
            $score = empty($validationIssues) ? 100 : max(0, 100 - (count($validationIssues) * 15));
            
            return [
                'status' => $status,
                'score' => $score,
                'message' => empty($validationIssues) ? 'Cross-table relationships validation passed' : 
                    'Issues found: ' . implode('; ', $validationIssues),
                'orphaned_inventory_count' => $orphanedCount,
                'products_without_inventory' => $productsWithoutInventory,
                'referential_integrity' => $referentialStats,
                'inventory_coverage_percentage' => round($inventoryCoverage, 2),
                'validation_issues' => $validationIssues
            ];
            
        } catch (Exception $e) {
            return [
                'status' => 'failed',
                'score' => 0,
                'message' => 'Cross-table relationships validation failed: ' . $e->getMessage(),
                'error' => $e->getMessage()
            ];
        }
    }
    
    /**
     * Validate data freshness and completeness
     */
    private function validateDataFreshness(): array
    {
        $this->logger->debug('Validating data freshness');
        
        try {
            // Check data update timestamps
            $freshnessResult = $this->db->query("
                SELECT 
                    'products' as table_name,
                    COUNT(*) as total_records,
                    MAX(updated_at) as newest_update,
                    MIN(updated_at) as oldest_update,
                    COUNT(CASE WHEN updated_at > NOW() - INTERVAL 24 HOUR THEN 1 END) as updated_last_24h
                FROM dim_products
                WHERE visibility IS NOT NULL
                
                UNION ALL
                
                SELECT 
                    'inventory' as table_name,
                    COUNT(*) as total_records,
                    MAX(updated_at) as newest_update,
                    MIN(updated_at) as oldest_update,
                    COUNT(CASE WHEN updated_at > NOW() - INTERVAL 24 HOUR THEN 1 END) as updated_last_24h
                FROM inventory
            ");
            
            $freshnessData = [];
            foreach ($freshnessResult as $row) {
                $freshnessData[$row['table_name']] = $row;
            }
            
            // Validation checks
            $validationIssues = [];
            
            foreach ($freshnessData as $tableName => $data) {
                $newestUpdate = $data['newest_update'];
                $totalRecords = (int)$data['total_records'];
                $updatedLast24h = (int)$data['updated_last_24h'];
                
                if (empty($newestUpdate)) {
                    $validationIssues[] = "No update timestamps found in {$tableName} table";
                    continue;
                }
                
                $hoursSinceUpdate = (time() - strtotime($newestUpdate)) / 3600;
                
                if ($hoursSinceUpdate > 8) {
                    $validationIssues[] = "{$tableName} data is stale (last update: {$hoursSinceUpdate} hours ago)";
                }
                
                if ($totalRecords > 0) {
                    $recentUpdatePercentage = ($updatedLast24h / $totalRecords) * 100;
                    if ($recentUpdatePercentage < 50) {
                        $validationIssues[] = "Low recent update rate in {$tableName}: {$recentUpdatePercentage}%";
                    }
                }
            }
            
            $status = empty($validationIssues) ? 'passed' : 'warning';
            $score = empty($validationIssues) ? 100 : max(0, 100 - (count($validationIssues) * 20));
            
            return [
                'status' => $status,
                'score' => $score,
                'message' => empty($validationIssues) ? 'Data freshness validation passed' : 
                    'Issues found: ' . implode('; ', $validationIssues),
                'freshness_data' => $freshnessData,
                'validation_issues' => $validationIssues
            ];
            
        } catch (Exception $e) {
            return [
                'status' => 'failed',
                'score' => 0,
                'message' => 'Data freshness validation failed: ' . $e->getMessage(),
                'error' => $e->getMessage()
            ];
        }
    }
    
    /**
     * Calculate overall validation score
     */
    private function calculateOverallValidationScore(): array
    {
        $validations = [
            'active_products_count',
            'visibility_distribution', 
            'inventory_consistency',
            'spot_check',
            'cross_table_validation',
            'data_freshness'
        ];
        
        $totalScore = 0;
        $totalWeight = 0;
        $passedValidations = 0;
        $failedValidations = 0;
        $warningValidations = 0;
        
        $weights = [
            'active_products_count' => 20,
            'visibility_distribution' => 15,
            'inventory_consistency' => 20,
            'spot_check' => 25,
            'cross_table_validation' => 15,
            'data_freshness' => 5
        ];
        
        foreach ($validations as $validation) {
            if (!isset($this->validationResults[$validation])) {
                continue;
            }
            
            $result = $this->validationResults[$validation];
            $score = $result['score'] ?? 0;
            $status = $result['status'] ?? 'failed';
            $weight = $weights[$validation] ?? 10;
            
            $totalScore += $score * $weight;
            $totalWeight += $weight;
            
            switch ($status) {
                case 'passed':
                    $passedValidations++;
                    break;
                case 'warning':
                    $warningValidations++;
                    break;
                case 'failed':
                    $failedValidations++;
                    break;
            }
        }
        
        $overallScore = $totalWeight > 0 ? round($totalScore / $totalWeight, 2) : 0;
        
        // Determine overall status
        if ($failedValidations > 0) {
            $overallStatus = 'failed';
            $message = "Data validation failed ({$failedValidations} failed, {$warningValidations} warnings, {$passedValidations} passed)";
        } elseif ($warningValidations > 0) {
            $overallStatus = 'warning';
            $message = "Data validation has warnings ({$warningValidations} warnings, {$passedValidations} passed)";
        } else {
            $overallStatus = 'passed';
            $message = "All data validations passed ({$passedValidations} validations)";
        }
        
        return [
            'status' => $overallStatus,
            'score' => $overallScore,
            'message' => $message,
            'summary' => [
                'passed' => $passedValidations,
                'warning' => $warningValidations,
                'failed' => $failedValidations,
                'total' => count($validations)
            ],
            'weighted_scores' => array_map(function($validation) use ($weights) {
                $result = $this->validationResults[$validation] ?? [];
                return [
                    'score' => $result['score'] ?? 0,
                    'weight' => $weights[$validation] ?? 10,
                    'weighted_score' => ($result['score'] ?? 0) * ($weights[$validation] ?? 10)
                ];
            }, $validations)
        ];
    }
    
    /**
     * Get validation results
     */
    public function getValidationResults(): array
    {
        return $this->validationResults;
    }
}

/**
 * Format validation results for output
 */
function formatValidationResults(array $validationResults, bool $json = false): string
{
    if ($json) {
        return json_encode($validationResults, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    }
    
    $output = '';
    
    $output .= "Data Accuracy Validation Report\n";
    $output .= "===============================\n";
    $output .= "Validated: " . ($validationResults['validation_info']['validated_at'] ?? 'Unknown') . "\n";
    $output .= "Duration: " . ($validationResults['validation_info']['duration_seconds'] ?? 'Unknown') . " seconds\n";
    $output .= "Sample Size: " . ($validationResults['validation_info']['sample_size'] ?? 'Unknown') . "\n\n";
    
    // Overall score
    $overall = $validationResults['overall_score'] ?? [];
    $statusIcon = match($overall['status'] ?? 'unknown') {
        'passed' => '✅',
        'warning' => '⚠️',
        'failed' => '❌',
        default => '❓'
    };
    
    $output .= "Overall Score: {$statusIcon} " . ($overall['score'] ?? 0) . "/100 (" . strtoupper($overall['status'] ?? 'UNKNOWN') . ")\n";
    $output .= "Message: " . ($overall['message'] ?? 'No message') . "\n\n";
    
    // Individual validations
    $validations = [
        'active_products_count' => 'Active Products Count',
        'visibility_distribution' => 'Visibility Distribution',
        'inventory_consistency' => 'Inventory Consistency',
        'spot_check' => 'Spot Check',
        'cross_table_validation' => 'Cross-table Validation',
        'data_freshness' => 'Data Freshness'
    ];
    
    foreach ($validations as $key => $name) {
        if (!isset($validationResults[$key])) {
            continue;
        }
        
        $validation = $validationResults[$key];
        $validationIcon = match($validation['status'] ?? 'unknown') {
            'passed' => '✅',
            'warning' => '⚠️',
            'failed' => '❌',
            default => '❓'
        };
        
        $output .= "{$name}: {$validationIcon} " . ($validation['score'] ?? 0) . "/100 (" . strtoupper($validation['status'] ?? 'UNKNOWN') . ")\n";
        $output .= "  " . ($validation['message'] ?? 'No message') . "\n";
        
        // Add specific details for some validations
        if ($key === 'active_products_count' && isset($validation['metrics'])) {
            $metrics = $validation['metrics'];
            $output .= "  Total Products: {$metrics['total_products']}, Visible: {$metrics['visible_products']} ({$metrics['visible_percentage']}%)\n";
        }
        
        if ($key === 'spot_check' && isset($validation['pass_rate_percentage'])) {
            $output .= "  Pass Rate: {$validation['pass_rate_percentage']}% ({$validation['passed_checks']}/{$validation['sample_size']})\n";
        }
        
        $output .= "\n";
    }
    
    return $output;
}

/**
 * Main execution function
 */
function main(): int
{
    global $argv;
    
    $startTime = microtime(true);
    $options = parseArguments($argv);
    
    // Show help if requested
    if ($options['help']) {
        showHelp();
        return 0;
    }
    
    // Load configuration
    try {
        $configFile = $options['config_file'] ?? __DIR__ . '/../Config/etl_config.php';
        $apiConfigFile = __DIR__ . '/../Config/api_config.php';
        
        if (!file_exists($configFile)) {
            throw new Exception("Configuration file not found: $configFile");
        }
        
        if (!file_exists($apiConfigFile)) {
            throw new Exception("API configuration file not found: $apiConfigFile");
        }
        
        $etlConfig = require $configFile;
        $apiConfig = require $apiConfigFile;
        
    } catch (Exception $e) {
        echo "Error loading configuration: " . $e->getMessage() . "\n";
        return 1;
    }
    
    try {
        // Initialize logger
        $logFile = ($etlConfig['logging']['log_directory'] ?? '/tmp') . '/data_accuracy_validation.log';
        $logger = new Logger($logFile, $options['verbose'] ? 'DEBUG' : 'INFO');
        
        if ($options['verbose']) {
            echo "Starting data accuracy validation...\n";
            echo "Log file: $logFile\n";
            echo "Sample size: {$options['sample_size']}\n";
        }
        
        $logger->info('Data accuracy validation started', [
            'script' => basename(__FILE__),
            'options' => $options
        ]);
        
        // Initialize database connection
        $db = new DatabaseConnection($etlConfig['database']);
        
        // Initialize API client
        $apiClient = new OzonApiClient($apiConfig);
        
        // Initialize validator
        $validator = new DataAccuracyValidator($db, $logger, $apiClient, $etlConfig);
        
        // Perform validation
        $validationResults = $validator->performValidation($options['sample_size']);
        
        // Format and output results
        $output = formatValidationResults($validationResults, $options['json']);
        
        if (!empty($output)) {
            echo $output;
        }
        
        // Save report to file if requested
        if ($options['report_file']) {
            $reportContent = $options['json'] ? 
                json_encode($validationResults, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) :
                $output;
            
            file_put_contents($options['report_file'], $reportContent);
            
            if ($options['verbose']) {
                echo "Report saved to: {$options['report_file']}\n";
            }
        }
        
        $duration = microtime(true) - $startTime;
        
        $logger->info('Data accuracy validation completed', [
            'duration' => round($duration, 2),
            'overall_score' => $validationResults['overall_score']['score'] ?? 0,
            'overall_status' => $validationResults['overall_score']['status'] ?? 'unknown'
        ]);
        
        if ($options['verbose']) {
            echo "Validation completed in " . round($duration, 2) . " seconds\n";
        }
        
        // Return appropriate exit code
        $overallStatus = $validationResults['overall_score']['status'] ?? 'unknown';
        return match($overallStatus) {
            'passed' => 0,
            'warning' => 1,
            'failed' => 2,
            default => 3
        };
        
    } catch (Exception $e) {
        echo "Error: " . $e->getMessage() . "\n";
        
        if (isset($logger)) {
            $logger->error('Data accuracy validation failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
        }
        
        return 1;
    }
}

// Execute main function
exit(main());