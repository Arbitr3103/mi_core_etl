#!/usr/bin/env php
<?php

/**
 * Business Verification Dashboard Generator
 * 
 * Builds verification dashboard showing before/after comparison,
 * implements export functionality for validation results, and creates
 * automated reports for business stakeholders.
 * 
 * Requirements addressed:
 * - 6.2: Build verification dashboard showing before/after comparison
 * - 6.2: Implement export functionality for validation results
 * - 6.2: Create automated reports for business stakeholders
 * - 6.3: Generate validation reports with discrepancy analysis
 * - 6.4: Create verification reports for business stakeholders
 * 
 * Usage:
 *   php business_verification_dashboard.php [options]
 * 
 * Options:
 *   --verbose          Enable verbose output
 *   --config=FILE      Use custom configuration file
 *   --output-dir=DIR   Output directory for dashboard files (default: ./reports)
 *   --format=FORMAT    Output format: html, json, csv, excel (default: html)
 *   --period=PERIOD    Comparison period: 1d, 7d, 30d (default: 7d)
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
        'output_dir' => './reports',
        'format' => 'html',
        'period' => '7d',
        'help' => false
    ];
    
    for ($i = 1; $i < count($argv); $i++) {
        $arg = $argv[$i];
        
        switch ($arg) {
            case '--verbose':
                $options['verbose'] = true;
                break;
            case '--help':
                $options['help'] = true;
                break;
            default:
                if (strpos($arg, '--config=') === 0) {
                    $options['config_file'] = substr($arg, 9);
                } elseif (strpos($arg, '--output-dir=') === 0) {
                    $options['output_dir'] = substr($arg, 13);
                } elseif (strpos($arg, '--format=') === 0) {
                    $options['format'] = substr($arg, 9);
                } elseif (strpos($arg, '--period=') === 0) {
                    $options['period'] = substr($arg, 9);
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
    echo "Business Verification Dashboard Generator\n";
    echo "=========================================\n\n";
    echo "Builds verification dashboard showing before/after comparison,\n";
    echo "implements export functionality for validation results, and creates\n";
    echo "automated reports for business stakeholders.\n\n";
    echo "Usage:\n";
    echo "  php business_verification_dashboard.php [options]\n\n";
    echo "Options:\n";
    echo "  --verbose          Enable verbose output\n";
    echo "  --config=FILE      Use custom configuration file\n";
    echo "  --output-dir=DIR   Output directory for dashboard files (default: ./reports)\n";
    echo "  --format=FORMAT    Output format: html, json, csv, excel (default: html)\n";
    echo "  --period=PERIOD    Comparison period: 1d, 7d, 30d (default: 7d)\n";
    echo "  --help             Show this help message\n\n";
    echo "Examples:\n";
    echo "  php business_verification_dashboard.php --verbose\n";
    echo "  php business_verification_dashboard.php --format=json --output-dir=/tmp/reports\n";
    echo "  php business_verification_dashboard.php --period=30d --format=excel\n\n";
}

/**
 * Business Verification Dashboard Class
 */
class BusinessVerificationDashboard
{
    private DatabaseConnection $db;
    private Logger $logger;
    private array $config;
    private string $outputDir;
    private string $period;
    
    public function __construct(
        DatabaseConnection $db, 
        Logger $logger, 
        array $config = [],
        string $outputDir = './reports',
        string $period = '7d'
    ) {
        $this->db = $db;
        $this->logger = $logger;
        $this->config = $config;
        $this->outputDir = $outputDir;
        $this->period = $period;
        
        // Create output directory if it doesn't exist
        if (!is_dir($this->outputDir)) {
            mkdir($this->outputDir, 0755, true);
        }
    }
    
    /**
     * Generate comprehensive business verification dashboard
     */
    public function generateDashboard(string $format = 'html'): array
    {
        $this->logger->info('Starting business verification dashboard generation', [
            'format' => $format,
            'output_dir' => $this->outputDir,
            'period' => $this->period
        ]);
        
        $startTime = microtime(true);
        
        try {
            // Collect all dashboard data
            $dashboardData = [
                'metadata' => $this->generateMetadata(),
                'executive_summary' => $this->generateExecutiveSummary(),
                'product_metrics' => $this->generateProductMetrics(),
                'inventory_metrics' => $this->generateInventoryMetrics(),
                'visibility_analysis' => $this->generateVisibilityAnalysis(),
                'stock_status_analysis' => $this->generateStockStatusAnalysis(),
                'etl_performance' => $this->generateETLPerformanceMetrics(),
                'data_quality_metrics' => $this->generateDataQualityMetrics(),
                'trend_analysis' => $this->generateTrendAnalysis(),
                'recommendations' => $this->generateRecommendations()
            ];
            
            // Generate output files based on format
            $outputFiles = [];
            
            switch ($format) {
                case 'html':
                    $outputFiles[] = $this->generateHTMLDashboard($dashboardData);
                    break;
                case 'json':
                    $outputFiles[] = $this->generateJSONReport($dashboardData);
                    break;
                case 'csv':
                    $outputFiles = $this->generateCSVReports($dashboardData);
                    break;
                case 'excel':
                    $outputFiles[] = $this->generateExcelReport($dashboardData);
                    break;
                default:
                    throw new Exception("Unsupported format: $format");
            }
            
            $duration = microtime(true) - $startTime;
            
            $this->logger->info('Business verification dashboard generated successfully', [
                'format' => $format,
                'duration' => round($duration, 2),
                'output_files' => $outputFiles
            ]);
            
            return [
                'status' => 'success',
                'format' => $format,
                'output_files' => $outputFiles,
                'dashboard_data' => $dashboardData,
                'generation_time' => round($duration, 2)
            ];
            
        } catch (Exception $e) {
            $this->logger->error('Business verification dashboard generation failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            
            return [
                'status' => 'failed',
                'error' => $e->getMessage()
            ];
        }
    }
    
    /**
     * Generate dashboard metadata
     */
    private function generateMetadata(): array
    {
        return [
            'generated_at' => date('Y-m-d H:i:s'),
            'period' => $this->period,
            'period_start' => $this->getPeriodStart(),
            'period_end' => date('Y-m-d H:i:s'),
            'dashboard_version' => '1.0.0',
            'data_sources' => ['dim_products', 'inventory', 'etl_workflow_executions']
        ];
    }
    
    /**
     * Generate executive summary
     */
    private function generateExecutiveSummary(): array
    {
        $this->logger->debug('Generating executive summary');
        
        try {
            // Current state metrics
            $currentMetricsResult = $this->db->query("
                SELECT 
                    COUNT(DISTINCT p.offer_id) as total_products,
                    COUNT(DISTINCT CASE WHEN p.visibility = 'VISIBLE' THEN p.offer_id END) as visible_products,
                    COUNT(DISTINCT i.offer_id) as products_with_inventory,
                    COUNT(DISTINCT CASE WHEN i.present > 0 THEN i.offer_id END) as products_with_stock,
                    SUM(i.present) as total_present_stock,
                    SUM(i.reserved) as total_reserved_stock,
                    SUM(i.present - i.reserved) as total_available_stock
                FROM dim_products p
                LEFT JOIN inventory i ON p.offer_id = i.offer_id
                WHERE p.visibility IS NOT NULL
            ");
            
            // Historical comparison (period ago)
            $historicalMetricsResult = $this->db->query("
                SELECT 
                    COUNT(DISTINCT p.offer_id) as total_products,
                    COUNT(DISTINCT CASE WHEN p.visibility = 'VISIBLE' THEN p.offer_id END) as visible_products,
                    COUNT(DISTINCT i.offer_id) as products_with_inventory,
                    COUNT(DISTINCT CASE WHEN i.present > 0 THEN i.offer_id END) as products_with_stock
                FROM dim_products p
                LEFT JOIN inventory i ON p.offer_id = i.offer_id
                WHERE p.visibility IS NOT NULL 
                  AND p.updated_at <= ?
            ", [$this->getPeriodStart()]);
            
            // ETL execution metrics
            $etlMetricsResult = $this->db->query("
                SELECT 
                    COUNT(*) as total_executions,
                    COUNT(CASE WHEN status = 'success' THEN 1 END) as successful_executions,
                    AVG(duration) as avg_duration,
                    MAX(created_at) as last_execution
                FROM etl_workflow_executions
                WHERE created_at >= ?
            ", [$this->getPeriodStart()]);
            
            $currentMetrics = $currentMetricsResult[0] ?? [];
            $historicalMetrics = $historicalMetricsResult[0] ?? [];
            $etlMetrics = $etlMetricsResult[0] ?? [];
            
            // Calculate changes
            $changes = [];
            foreach (['total_products', 'visible_products', 'products_with_inventory', 'products_with_stock'] as $metric) {
                $current = (int)($currentMetrics[$metric] ?? 0);
                $historical = (int)($historicalMetrics[$metric] ?? 0);
                $change = $current - $historical;
                $changePercent = $historical > 0 ? ($change / $historical) * 100 : 0;
                
                $changes[$metric] = [
                    'current' => $current,
                    'historical' => $historical,
                    'change' => $change,
                    'change_percent' => round($changePercent, 2)
                ];
            }
            
            // Calculate key performance indicators
            $totalProducts = (int)($currentMetrics['total_products'] ?? 0);
            $visibleProducts = (int)($currentMetrics['visible_products'] ?? 0);
            $productsWithStock = (int)($currentMetrics['products_with_stock'] ?? 0);
            
            $visibilityRate = $totalProducts > 0 ? ($visibleProducts / $totalProducts) * 100 : 0;
            $stockCoverage = $visibleProducts > 0 ? ($productsWithStock / $visibleProducts) * 100 : 0;
            $etlSuccessRate = ((int)($etlMetrics['total_executions'] ?? 0)) > 0 ? 
                (((int)($etlMetrics['successful_executions'] ?? 0)) / ((int)($etlMetrics['total_executions'] ?? 0))) * 100 : 0;
            
            return [
                'current_metrics' => $currentMetrics,
                'historical_metrics' => $historicalMetrics,
                'changes' => $changes,
                'kpis' => [
                    'visibility_rate_percent' => round($visibilityRate, 2),
                    'stock_coverage_percent' => round($stockCoverage, 2),
                    'etl_success_rate_percent' => round($etlSuccessRate, 2),
                    'avg_etl_duration_minutes' => round(((float)($etlMetrics['avg_duration'] ?? 0)) / 60, 2)
                ],
                'etl_metrics' => $etlMetrics,
                'summary_status' => $this->calculateSummaryStatus($visibilityRate, $stockCoverage, $etlSuccessRate)
            ];
            
        } catch (Exception $e) {
            $this->logger->error('Failed to generate executive summary', [
                'error' => $e->getMessage()
            ]);
            
            return [
                'error' => 'Failed to generate executive summary: ' . $e->getMessage()
            ];
        }
    }
    
    /**
     * Generate product metrics
     */
    private function generateProductMetrics(): array
    {
        $this->logger->debug('Generating product metrics');
        
        try {
            // Product visibility distribution
            $visibilityDistributionResult = $this->db->query("
                SELECT 
                    visibility,
                    COUNT(*) as count,
                    ROUND(COUNT(*) * 100.0 / SUM(COUNT(*)) OVER(), 2) as percentage
                FROM dim_products 
                WHERE visibility IS NOT NULL
                GROUP BY visibility
                ORDER BY count DESC
            ");
            
            // Product status distribution
            $statusDistributionResult = $this->db->query("
                SELECT 
                    status,
                    COUNT(*) as count,
                    ROUND(COUNT(*) * 100.0 / SUM(COUNT(*)) OVER(), 2) as percentage
                FROM dim_products 
                WHERE status IS NOT NULL
                GROUP BY status
                ORDER BY count DESC
            ");
            
            // Products by update frequency
            $updateFrequencyResult = $this->db->query("
                SELECT 
                    CASE 
                        WHEN updated_at > NOW() - INTERVAL 1 DAY THEN 'Last 24 hours'
                        WHEN updated_at > NOW() - INTERVAL 7 DAY THEN 'Last 7 days'
                        WHEN updated_at > NOW() - INTERVAL 30 DAY THEN 'Last 30 days'
                        ELSE 'Older than 30 days'
                    END as update_period,
                    COUNT(*) as count,
                    ROUND(COUNT(*) * 100.0 / SUM(COUNT(*)) OVER(), 2) as percentage
                FROM dim_products 
                WHERE updated_at IS NOT NULL
                GROUP BY 
                    CASE 
                        WHEN updated_at > NOW() - INTERVAL 1 DAY THEN 'Last 24 hours'
                        WHEN updated_at > NOW() - INTERVAL 7 DAY THEN 'Last 7 days'
                        WHEN updated_at > NOW() - INTERVAL 30 DAY THEN 'Last 30 days'
                        ELSE 'Older than 30 days'
                    END
                ORDER BY 
                    CASE 
                        WHEN update_period = 'Last 24 hours' THEN 1
                        WHEN update_period = 'Last 7 days' THEN 2
                        WHEN update_period = 'Last 30 days' THEN 3
                        ELSE 4
                    END
            ");
            
            // Top products by inventory value (if we have cost data)
            $topProductsResult = $this->db->query("
                SELECT 
                    p.offer_id,
                    p.name,
                    p.visibility,
                    SUM(i.present) as total_present,
                    SUM(i.reserved) as total_reserved,
                    SUM(i.present - i.reserved) as total_available,
                    COUNT(i.warehouse_name) as warehouse_count
                FROM dim_products p
                INNER JOIN inventory i ON p.offer_id = i.offer_id
                WHERE p.visibility = 'VISIBLE'
                GROUP BY p.offer_id, p.name, p.visibility
                ORDER BY total_available DESC
                LIMIT 20
            ");
            
            return [
                'visibility_distribution' => $visibilityDistributionResult,
                'status_distribution' => $statusDistributionResult,
                'update_frequency' => $updateFrequencyResult,
                'top_products_by_stock' => $topProductsResult
            ];
            
        } catch (Exception $e) {
            $this->logger->error('Failed to generate product metrics', [
                'error' => $e->getMessage()
            ]);
            
            return [
                'error' => 'Failed to generate product metrics: ' . $e->getMessage()
            ];
        }
    }
    
    /**
     * Generate inventory metrics
     */
    private function generateInventoryMetrics(): array
    {
        $this->logger->debug('Generating inventory metrics');
        
        try {
            // Warehouse distribution
            $warehouseDistributionResult = $this->db->query("
                SELECT 
                    warehouse_name,
                    COUNT(*) as product_count,
                    SUM(present) as total_present,
                    SUM(reserved) as total_reserved,
                    SUM(present - reserved) as total_available,
                    AVG(present) as avg_present,
                    COUNT(CASE WHEN present > 0 THEN 1 END) as products_with_stock
                FROM inventory
                GROUP BY warehouse_name
                ORDER BY total_available DESC
            ");
            
            // Stock level distribution
            $stockLevelDistributionResult = $this->db->query("
                SELECT 
                    CASE 
                        WHEN present = 0 THEN 'Out of stock'
                        WHEN present BETWEEN 1 AND 10 THEN 'Low stock (1-10)'
                        WHEN present BETWEEN 11 AND 50 THEN 'Medium stock (11-50)'
                        WHEN present BETWEEN 51 AND 100 THEN 'High stock (51-100)'
                        ELSE 'Very high stock (100+)'
                    END as stock_level,
                    COUNT(*) as count,
                    ROUND(COUNT(*) * 100.0 / SUM(COUNT(*)) OVER(), 2) as percentage
                FROM inventory
                GROUP BY 
                    CASE 
                        WHEN present = 0 THEN 'Out of stock'
                        WHEN present BETWEEN 1 AND 10 THEN 'Low stock (1-10)'
                        WHEN present BETWEEN 11 AND 50 THEN 'Medium stock (11-50)'
                        WHEN present BETWEEN 51 AND 100 THEN 'High stock (51-100)'
                        ELSE 'Very high stock (100+)'
                    END
                ORDER BY 
                    CASE 
                        WHEN stock_level = 'Out of stock' THEN 1
                        WHEN stock_level = 'Low stock (1-10)' THEN 2
                        WHEN stock_level = 'Medium stock (11-50)' THEN 3
                        WHEN stock_level = 'High stock (51-100)' THEN 4
                        ELSE 5
                    END
            ");
            
            // Reserved vs Available analysis
            $reservationAnalysisResult = $this->db->query("
                SELECT 
                    COUNT(*) as total_records,
                    COUNT(CASE WHEN reserved > 0 THEN 1 END) as records_with_reservations,
                    SUM(present) as total_present,
                    SUM(reserved) as total_reserved,
                    SUM(present - reserved) as total_available,
                    ROUND(SUM(reserved) * 100.0 / NULLIF(SUM(present), 0), 2) as reservation_rate_percent,
                    AVG(CASE WHEN reserved > 0 THEN reserved END) as avg_reservation_when_reserved
                FROM inventory
            ");
            
            // Inventory freshness
            $freshnessResult = $this->db->query("
                SELECT 
                    CASE 
                        WHEN updated_at > NOW() - INTERVAL 1 HOUR THEN 'Last hour'
                        WHEN updated_at > NOW() - INTERVAL 6 HOUR THEN 'Last 6 hours'
                        WHEN updated_at > NOW() - INTERVAL 24 HOUR THEN 'Last 24 hours'
                        WHEN updated_at > NOW() - INTERVAL 7 DAY THEN 'Last 7 days'
                        ELSE 'Older than 7 days'
                    END as freshness_period,
                    COUNT(*) as count,
                    ROUND(COUNT(*) * 100.0 / SUM(COUNT(*)) OVER(), 2) as percentage
                FROM inventory
                WHERE updated_at IS NOT NULL
                GROUP BY 
                    CASE 
                        WHEN updated_at > NOW() - INTERVAL 1 HOUR THEN 'Last hour'
                        WHEN updated_at > NOW() - INTERVAL 6 HOUR THEN 'Last 6 hours'
                        WHEN updated_at > NOW() - INTERVAL 24 HOUR THEN 'Last 24 hours'
                        WHEN updated_at > NOW() - INTERVAL 7 DAY THEN 'Last 7 days'
                        ELSE 'Older than 7 days'
                    END
                ORDER BY 
                    CASE 
                        WHEN freshness_period = 'Last hour' THEN 1
                        WHEN freshness_period = 'Last 6 hours' THEN 2
                        WHEN freshness_period = 'Last 24 hours' THEN 3
                        WHEN freshness_period = 'Last 7 days' THEN 4
                        ELSE 5
                    END
            ");
            
            return [
                'warehouse_distribution' => $warehouseDistributionResult,
                'stock_level_distribution' => $stockLevelDistributionResult,
                'reservation_analysis' => $reservationAnalysisResult[0] ?? [],
                'freshness_analysis' => $freshnessResult
            ];
            
        } catch (Exception $e) {
            $this->logger->error('Failed to generate inventory metrics', [
                'error' => $e->getMessage()
            ]);
            
            return [
                'error' => 'Failed to generate inventory metrics: ' . $e->getMessage()
            ];
        }
    }
    
    /**
     * Generate visibility analysis
     */
    private function generateVisibilityAnalysis(): array
    {
        $this->logger->debug('Generating visibility analysis');
        
        try {
            // Visibility vs inventory correlation
            $visibilityInventoryResult = $this->db->query("
                SELECT 
                    p.visibility,
                    COUNT(DISTINCT p.offer_id) as total_products,
                    COUNT(DISTINCT i.offer_id) as products_with_inventory,
                    COUNT(DISTINCT CASE WHEN i.present > 0 THEN i.offer_id END) as products_with_stock,
                    ROUND(COUNT(DISTINCT i.offer_id) * 100.0 / COUNT(DISTINCT p.offer_id), 2) as inventory_coverage_percent,
                    ROUND(COUNT(DISTINCT CASE WHEN i.present > 0 THEN i.offer_id END) * 100.0 / COUNT(DISTINCT p.offer_id), 2) as stock_coverage_percent
                FROM dim_products p
                LEFT JOIN inventory i ON p.offer_id = i.offer_id
                WHERE p.visibility IS NOT NULL
                GROUP BY p.visibility
                ORDER BY total_products DESC
            ");
            
            // Visibility changes over time (if we track history)
            $visibilityTrendsResult = $this->db->query("
                SELECT 
                    DATE(updated_at) as update_date,
                    visibility,
                    COUNT(*) as count
                FROM dim_products
                WHERE updated_at >= ? AND visibility IS NOT NULL
                GROUP BY DATE(updated_at), visibility
                ORDER BY update_date DESC, count DESC
                LIMIT 100
            ", [$this->getPeriodStart()]);
            
            // Products that should be visible but aren't in stock
            $visibleWithoutStockResult = $this->db->query("
                SELECT 
                    p.offer_id,
                    p.name,
                    p.visibility,
                    COALESCE(SUM(i.present), 0) as total_present,
                    COALESCE(SUM(i.reserved), 0) as total_reserved,
                    COALESCE(SUM(i.present - i.reserved), 0) as total_available
                FROM dim_products p
                LEFT JOIN inventory i ON p.offer_id = i.offer_id
                WHERE p.visibility = 'VISIBLE'
                GROUP BY p.offer_id, p.name, p.visibility
                HAVING COALESCE(SUM(i.present - i.reserved), 0) <= 0
                ORDER BY p.name
                LIMIT 50
            ");
            
            return [
                'visibility_inventory_correlation' => $visibilityInventoryResult,
                'visibility_trends' => $visibilityTrendsResult,
                'visible_without_stock' => $visibleWithoutStockResult
            ];
            
        } catch (Exception $e) {
            $this->logger->error('Failed to generate visibility analysis', [
                'error' => $e->getMessage()
            ]);
            
            return [
                'error' => 'Failed to generate visibility analysis: ' . $e->getMessage()
            ];
        }
    }
    
    /**
     * Generate stock status analysis
     */
    private function generateStockStatusAnalysis(): array
    {
        $this->logger->debug('Generating stock status analysis');
        
        try {
            // Stock status distribution using business logic
            $stockStatusResult = $this->db->query("
                SELECT 
                    CASE 
                        WHEN p.visibility NOT IN ('VISIBLE', 'ACTIVE', 'продаётся') THEN 'archived_or_hidden'
                        WHEN COALESCE(SUM(i.present - i.reserved), 0) <= 0 THEN 'out_of_stock'
                        WHEN COALESCE(SUM(i.present - i.reserved), 0) BETWEEN 1 AND 10 THEN 'critical'
                        WHEN COALESCE(SUM(i.present - i.reserved), 0) BETWEEN 11 AND 30 THEN 'low'
                        WHEN COALESCE(SUM(i.present - i.reserved), 0) BETWEEN 31 AND 100 THEN 'normal'
                        ELSE 'excess'
                    END as stock_status,
                    COUNT(DISTINCT p.offer_id) as count,
                    ROUND(COUNT(DISTINCT p.offer_id) * 100.0 / SUM(COUNT(DISTINCT p.offer_id)) OVER(), 2) as percentage
                FROM dim_products p
                LEFT JOIN inventory i ON p.offer_id = i.offer_id
                WHERE p.visibility IS NOT NULL
                GROUP BY 
                    CASE 
                        WHEN p.visibility NOT IN ('VISIBLE', 'ACTIVE', 'продаётся') THEN 'archived_or_hidden'
                        WHEN COALESCE(SUM(i.present - i.reserved), 0) <= 0 THEN 'out_of_stock'
                        WHEN COALESCE(SUM(i.present - i.reserved), 0) BETWEEN 1 AND 10 THEN 'critical'
                        WHEN COALESCE(SUM(i.present - i.reserved), 0) BETWEEN 11 AND 30 THEN 'low'
                        WHEN COALESCE(SUM(i.present - i.reserved), 0) BETWEEN 31 AND 100 THEN 'normal'
                        ELSE 'excess'
                    END
                ORDER BY count DESC
            ");
            
            // Critical stock items that need attention
            $criticalStockResult = $this->db->query("
                SELECT 
                    p.offer_id,
                    p.name,
                    p.visibility,
                    SUM(i.present) as total_present,
                    SUM(i.reserved) as total_reserved,
                    SUM(i.present - i.reserved) as total_available,
                    COUNT(i.warehouse_name) as warehouse_count
                FROM dim_products p
                INNER JOIN inventory i ON p.offer_id = i.offer_id
                WHERE p.visibility = 'VISIBLE'
                GROUP BY p.offer_id, p.name, p.visibility
                HAVING SUM(i.present - i.reserved) BETWEEN 1 AND 10
                ORDER BY total_available ASC, p.name
                LIMIT 30
            ");
            
            return [
                'stock_status_distribution' => $stockStatusResult,
                'critical_stock_items' => $criticalStockResult
            ];
            
        } catch (Exception $e) {
            $this->logger->error('Failed to generate stock status analysis', [
                'error' => $e->getMessage()
            ]);
            
            return [
                'error' => 'Failed to generate stock status analysis: ' . $e->getMessage()
            ];
        }
    }
    
    /**
     * Generate ETL performance metrics
     */
    private function generateETLPerformanceMetrics(): array
    {
        $this->logger->debug('Generating ETL performance metrics');
        
        try {
            // ETL execution history
            $executionHistoryResult = $this->db->query("
                SELECT 
                    DATE(created_at) as execution_date,
                    COUNT(*) as total_executions,
                    COUNT(CASE WHEN status = 'success' THEN 1 END) as successful_executions,
                    COUNT(CASE WHEN status = 'failed' THEN 1 END) as failed_executions,
                    AVG(duration) as avg_duration,
                    MIN(duration) as min_duration,
                    MAX(duration) as max_duration
                FROM etl_workflow_executions
                WHERE created_at >= ?
                GROUP BY DATE(created_at)
                ORDER BY execution_date DESC
            ", [$this->getPeriodStart()]);
            
            // Recent ETL executions
            $recentExecutionsResult = $this->db->query("
                SELECT 
                    workflow_id,
                    status,
                    duration,
                    product_etl_status,
                    inventory_etl_status,
                    created_at
                FROM etl_workflow_executions
                WHERE created_at >= ?
                ORDER BY created_at DESC
                LIMIT 20
            ", [$this->getPeriodStart()]);
            
            // ETL component performance
            $componentPerformanceResult = $this->db->query("
                SELECT 
                    'ProductETL' as component,
                    COUNT(CASE WHEN product_etl_status = 'success' THEN 1 END) as successful,
                    COUNT(CASE WHEN product_etl_status = 'failed' THEN 1 END) as failed,
                    ROUND(COUNT(CASE WHEN product_etl_status = 'success' THEN 1 END) * 100.0 / COUNT(*), 2) as success_rate
                FROM etl_workflow_executions
                WHERE created_at >= ? AND product_etl_status IS NOT NULL
                
                UNION ALL
                
                SELECT 
                    'InventoryETL' as component,
                    COUNT(CASE WHEN inventory_etl_status = 'success' THEN 1 END) as successful,
                    COUNT(CASE WHEN inventory_etl_status = 'failed' THEN 1 END) as failed,
                    ROUND(COUNT(CASE WHEN inventory_etl_status = 'success' THEN 1 END) * 100.0 / COUNT(*), 2) as success_rate
                FROM etl_workflow_executions
                WHERE created_at >= ? AND inventory_etl_status IS NOT NULL
            ", [$this->getPeriodStart(), $this->getPeriodStart()]);
            
            return [
                'execution_history' => $executionHistoryResult,
                'recent_executions' => $recentExecutionsResult,
                'component_performance' => $componentPerformanceResult
            ];
            
        } catch (Exception $e) {
            $this->logger->error('Failed to generate ETL performance metrics', [
                'error' => $e->getMessage()
            ]);
            
            return [
                'error' => 'Failed to generate ETL performance metrics: ' . $e->getMessage()
            ];
        }
    }
    
    /**
     * Generate data quality metrics
     */
    private function generateDataQualityMetrics(): array
    {
        $this->logger->debug('Generating data quality metrics');
        
        try {
            // Data completeness metrics
            $completenessResult = $this->db->query("
                SELECT 
                    'Products' as table_name,
                    COUNT(*) as total_records,
                    COUNT(CASE WHEN offer_id IS NOT NULL AND offer_id != '' THEN 1 END) as complete_offer_id,
                    COUNT(CASE WHEN name IS NOT NULL AND name != '' THEN 1 END) as complete_name,
                    COUNT(CASE WHEN visibility IS NOT NULL THEN 1 END) as complete_visibility,
                    ROUND(COUNT(CASE WHEN offer_id IS NOT NULL AND offer_id != '' THEN 1 END) * 100.0 / COUNT(*), 2) as offer_id_completeness,
                    ROUND(COUNT(CASE WHEN visibility IS NOT NULL THEN 1 END) * 100.0 / COUNT(*), 2) as visibility_completeness
                FROM dim_products
                
                UNION ALL
                
                SELECT 
                    'Inventory' as table_name,
                    COUNT(*) as total_records,
                    COUNT(CASE WHEN offer_id IS NOT NULL AND offer_id != '' THEN 1 END) as complete_offer_id,
                    COUNT(CASE WHEN warehouse_name IS NOT NULL AND warehouse_name != '' THEN 1 END) as complete_warehouse,
                    COUNT(CASE WHEN present >= 0 AND reserved >= 0 THEN 1 END) as valid_quantities,
                    ROUND(COUNT(CASE WHEN offer_id IS NOT NULL AND offer_id != '' THEN 1 END) * 100.0 / COUNT(*), 2) as offer_id_completeness,
                    ROUND(COUNT(CASE WHEN present >= 0 AND reserved >= 0 THEN 1 END) * 100.0 / COUNT(*), 2) as quantity_validity
                FROM inventory
            ");
            
            // Data consistency checks
            $consistencyResult = $this->db->query("
                SELECT 
                    'Orphaned Inventory' as check_name,
                    COUNT(*) as issue_count,
                    'Inventory records without matching products' as description
                FROM inventory i
                LEFT JOIN dim_products p ON i.offer_id = p.offer_id
                WHERE p.offer_id IS NULL
                
                UNION ALL
                
                SELECT 
                    'Invalid Quantities' as check_name,
                    COUNT(*) as issue_count,
                    'Inventory records where reserved > present' as description
                FROM inventory
                WHERE reserved > present
                
                UNION ALL
                
                SELECT 
                    'Missing Visibility' as check_name,
                    COUNT(*) as issue_count,
                    'Products without visibility status' as description
                FROM dim_products
                WHERE visibility IS NULL OR visibility = ''
            ");
            
            return [
                'completeness_metrics' => $completenessResult,
                'consistency_checks' => $consistencyResult
            ];
            
        } catch (Exception $e) {
            $this->logger->error('Failed to generate data quality metrics', [
                'error' => $e->getMessage()
            ]);
            
            return [
                'error' => 'Failed to generate data quality metrics: ' . $e->getMessage()
            ];
        }
    }
    
    /**
     * Generate trend analysis
     */
    private function generateTrendAnalysis(): array
    {
        $this->logger->debug('Generating trend analysis');
        
        try {
            // Daily product and inventory trends
            $dailyTrendsResult = $this->db->query("
                SELECT 
                    DATE(p.updated_at) as trend_date,
                    COUNT(DISTINCT p.offer_id) as products_updated,
                    COUNT(DISTINCT CASE WHEN p.visibility = 'VISIBLE' THEN p.offer_id END) as visible_products_updated,
                    COUNT(DISTINCT i.offer_id) as inventory_updated
                FROM dim_products p
                LEFT JOIN inventory i ON p.offer_id = i.offer_id AND DATE(i.updated_at) = DATE(p.updated_at)
                WHERE p.updated_at >= ?
                GROUP BY DATE(p.updated_at)
                ORDER BY trend_date DESC
                LIMIT 30
            ", [$this->getPeriodStart()]);
            
            return [
                'daily_trends' => $dailyTrendsResult
            ];
            
        } catch (Exception $e) {
            $this->logger->error('Failed to generate trend analysis', [
                'error' => $e->getMessage()
            ]);
            
            return [
                'error' => 'Failed to generate trend analysis: ' . $e->getMessage()
            ];
        }
    }
    
    /**
     * Generate recommendations based on analysis
     */
    private function generateRecommendations(): array
    {
        $this->logger->debug('Generating recommendations');
        
        $recommendations = [];
        
        try {
            // Check for data quality issues and generate recommendations
            
            // Check visibility coverage
            $visibilityResult = $this->db->query("
                SELECT 
                    COUNT(*) as total_products,
                    COUNT(CASE WHEN visibility IS NULL THEN 1 END) as missing_visibility
                FROM dim_products
            ");
            
            $visibilityStats = $visibilityResult[0] ?? [];
            $missingVisibilityPercent = ((int)($visibilityStats['total_products'] ?? 0)) > 0 ? 
                (((int)($visibilityStats['missing_visibility'] ?? 0)) / ((int)($visibilityStats['total_products'] ?? 0))) * 100 : 0;
            
            if ($missingVisibilityPercent > 5) {
                $recommendations[] = [
                    'type' => 'data_quality',
                    'priority' => 'high',
                    'title' => 'Missing Visibility Data',
                    'description' => "High percentage of products ({$missingVisibilityPercent}%) are missing visibility status",
                    'action' => 'Review ProductETL process and ensure visibility field is properly populated'
                ];
            }
            
            // Check inventory coverage
            $inventoryResult = $this->db->query("
                SELECT 
                    COUNT(DISTINCT p.offer_id) as visible_products,
                    COUNT(DISTINCT i.offer_id) as visible_with_inventory
                FROM dim_products p
                LEFT JOIN inventory i ON p.offer_id = i.offer_id
                WHERE p.visibility = 'VISIBLE'
            ");
            
            $inventoryStats = $inventoryResult[0] ?? [];
            $inventoryCoverage = ((int)($inventoryStats['visible_products'] ?? 0)) > 0 ? 
                (((int)($inventoryStats['visible_with_inventory'] ?? 0)) / ((int)($inventoryStats['visible_products'] ?? 0))) * 100 : 0;
            
            if ($inventoryCoverage < 80) {
                $recommendations[] = [
                    'type' => 'inventory',
                    'priority' => 'medium',
                    'title' => 'Low Inventory Coverage',
                    'description' => "Only {$inventoryCoverage}% of visible products have inventory data",
                    'action' => 'Investigate InventoryETL process and ensure all visible products have inventory records'
                ];
            }
            
            // Check ETL performance
            $etlResult = $this->db->query("
                SELECT 
                    COUNT(*) as total_executions,
                    COUNT(CASE WHEN status = 'success' THEN 1 END) as successful_executions
                FROM etl_workflow_executions
                WHERE created_at >= ?
            ", [$this->getPeriodStart()]);
            
            $etlStats = $etlResult[0] ?? [];
            $etlSuccessRate = ((int)($etlStats['total_executions'] ?? 0)) > 0 ? 
                (((int)($etlStats['successful_executions'] ?? 0)) / ((int)($etlStats['total_executions'] ?? 0))) * 100 : 0;
            
            if ($etlSuccessRate < 95) {
                $recommendations[] = [
                    'type' => 'etl_performance',
                    'priority' => 'high',
                    'title' => 'ETL Reliability Issues',
                    'description' => "ETL success rate is {$etlSuccessRate}%, below the 95% target",
                    'action' => 'Review ETL logs, improve error handling, and implement retry mechanisms'
                ];
            }
            
            // Add general recommendations
            $recommendations[] = [
                'type' => 'monitoring',
                'priority' => 'low',
                'title' => 'Regular Monitoring',
                'description' => 'Continue regular monitoring of data quality and ETL performance',
                'action' => 'Schedule daily validation reports and set up alerts for critical issues'
            ];
            
            return [
                'recommendations' => $recommendations,
                'total_recommendations' => count($recommendations),
                'high_priority' => count(array_filter($recommendations, fn($r) => $r['priority'] === 'high')),
                'medium_priority' => count(array_filter($recommendations, fn($r) => $r['priority'] === 'medium')),
                'low_priority' => count(array_filter($recommendations, fn($r) => $r['priority'] === 'low'))
            ];
            
        } catch (Exception $e) {
            $this->logger->error('Failed to generate recommendations', [
                'error' => $e->getMessage()
            ]);
            
            return [
                'error' => 'Failed to generate recommendations: ' . $e->getMessage(),
                'recommendations' => $recommendations
            ];
        }
    }
    
    /**
     * Generate HTML dashboard
     */
    private function generateHTMLDashboard(array $dashboardData): string
    {
        $filename = $this->outputDir . '/business_verification_dashboard_' . date('Y-m-d_H-i-s') . '.html';
        
        $html = $this->buildHTMLDashboard($dashboardData);
        
        file_put_contents($filename, $html);
        
        return $filename;
    }
    
    /**
     * Generate JSON report
     */
    private function generateJSONReport(array $dashboardData): string
    {
        $filename = $this->outputDir . '/business_verification_report_' . date('Y-m-d_H-i-s') . '.json';
        
        $json = json_encode($dashboardData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        
        file_put_contents($filename, $json);
        
        return $filename;
    }
    
    /**
     * Generate CSV reports (multiple files)
     */
    private function generateCSVReports(array $dashboardData): array
    {
        $files = [];
        
        // Generate separate CSV files for different data sections
        $csvSections = [
            'product_metrics' => 'product_metrics',
            'inventory_metrics' => 'inventory_metrics',
            'etl_performance' => 'etl_performance'
        ];
        
        foreach ($csvSections as $section => $filename) {
            if (isset($dashboardData[$section])) {
                $csvFile = $this->outputDir . "/{$filename}_" . date('Y-m-d_H-i-s') . '.csv';
                $this->generateCSVFile($dashboardData[$section], $csvFile);
                $files[] = $csvFile;
            }
        }
        
        return $files;
    }
    
    /**
     * Generate Excel report (placeholder - would need PHPSpreadsheet)
     */
    private function generateExcelReport(array $dashboardData): string
    {
        // For now, generate a detailed JSON file as Excel would require additional dependencies
        $filename = $this->outputDir . '/business_verification_excel_data_' . date('Y-m-d_H-i-s') . '.json';
        
        $excelData = [
            'note' => 'Excel generation requires PHPSpreadsheet library. This is the data that would be included.',
            'data' => $dashboardData
        ];
        
        file_put_contents($filename, json_encode($excelData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        
        return $filename;
    }
    
    /**
     * Build HTML dashboard content
     */
    private function buildHTMLDashboard(array $data): string
    {
        $metadata = $data['metadata'] ?? [];
        $summary = $data['executive_summary'] ?? [];
        
        $html = '<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Business Verification Dashboard</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 20px; background-color: #f5f5f5; }
        .container { max-width: 1200px; margin: 0 auto; background: white; padding: 20px; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.1); }
        .header { text-align: center; margin-bottom: 30px; padding-bottom: 20px; border-bottom: 2px solid #eee; }
        .kpi-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); gap: 20px; margin-bottom: 30px; }
        .kpi-card { background: #f8f9fa; padding: 20px; border-radius: 8px; border-left: 4px solid #007bff; }
        .kpi-value { font-size: 2em; font-weight: bold; color: #007bff; }
        .kpi-label { color: #666; margin-top: 5px; }
        .section { margin-bottom: 30px; }
        .section-title { font-size: 1.5em; font-weight: bold; margin-bottom: 15px; color: #333; border-bottom: 1px solid #ddd; padding-bottom: 5px; }
        .table { width: 100%; border-collapse: collapse; margin-top: 10px; }
        .table th, .table td { padding: 10px; text-align: left; border-bottom: 1px solid #ddd; }
        .table th { background-color: #f8f9fa; font-weight: bold; }
        .status-passed { color: #28a745; font-weight: bold; }
        .status-warning { color: #ffc107; font-weight: bold; }
        .status-failed { color: #dc3545; font-weight: bold; }
        .recommendation { background: #fff3cd; border: 1px solid #ffeaa7; border-radius: 4px; padding: 15px; margin-bottom: 10px; }
        .recommendation.high { border-color: #dc3545; background: #f8d7da; }
        .recommendation.medium { border-color: #ffc107; background: #fff3cd; }
        .recommendation.low { border-color: #28a745; background: #d4edda; }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>Business Verification Dashboard</h1>
            <p>Generated: ' . ($metadata['generated_at'] ?? 'Unknown') . '</p>
            <p>Period: ' . ($metadata['period'] ?? 'Unknown') . ' (' . ($metadata['period_start'] ?? 'Unknown') . ' to ' . ($metadata['period_end'] ?? 'Unknown') . ')</p>
        </div>';
        
        // KPI Section
        if (isset($summary['kpis'])) {
            $kpis = $summary['kpis'];
            $html .= '
        <div class="section">
            <div class="section-title">Key Performance Indicators</div>
            <div class="kpi-grid">
                <div class="kpi-card">
                    <div class="kpi-value">' . ($kpis['visibility_rate_percent'] ?? 0) . '%</div>
                    <div class="kpi-label">Visibility Rate</div>
                </div>
                <div class="kpi-card">
                    <div class="kpi-value">' . ($kpis['stock_coverage_percent'] ?? 0) . '%</div>
                    <div class="kpi-label">Stock Coverage</div>
                </div>
                <div class="kpi-card">
                    <div class="kpi-value">' . ($kpis['etl_success_rate_percent'] ?? 0) . '%</div>
                    <div class="kpi-label">ETL Success Rate</div>
                </div>
                <div class="kpi-card">
                    <div class="kpi-value">' . ($kpis['avg_etl_duration_minutes'] ?? 0) . ' min</div>
                    <div class="kpi-label">Avg ETL Duration</div>
                </div>
            </div>
        </div>';
        }
        
        // Current vs Historical Metrics
        if (isset($summary['changes'])) {
            $html .= '
        <div class="section">
            <div class="section-title">Metrics Comparison</div>
            <table class="table">
                <thead>
                    <tr>
                        <th>Metric</th>
                        <th>Current</th>
                        <th>Previous</th>
                        <th>Change</th>
                        <th>Change %</th>
                    </tr>
                </thead>
                <tbody>';
            
            foreach ($summary['changes'] as $metric => $change) {
                $changeClass = $change['change'] >= 0 ? 'status-passed' : 'status-failed';
                $changeSign = $change['change'] >= 0 ? '+' : '';
                
                $html .= '
                    <tr>
                        <td>' . ucfirst(str_replace('_', ' ', $metric)) . '</td>
                        <td>' . number_format($change['current']) . '</td>
                        <td>' . number_format($change['historical']) . '</td>
                        <td class="' . $changeClass . '">' . $changeSign . number_format($change['change']) . '</td>
                        <td class="' . $changeClass . '">' . $changeSign . number_format($change['change_percent'], 2) . '%</td>
                    </tr>';
            }
            
            $html .= '
                </tbody>
            </table>
        </div>';
        }
        
        // Recommendations
        if (isset($data['recommendations']['recommendations'])) {
            $html .= '
        <div class="section">
            <div class="section-title">Recommendations</div>';
            
            foreach ($data['recommendations']['recommendations'] as $rec) {
                $html .= '
            <div class="recommendation ' . ($rec['priority'] ?? 'low') . '">
                <strong>' . ($rec['title'] ?? 'Recommendation') . '</strong> (' . strtoupper($rec['priority'] ?? 'low') . ' Priority)
                <p>' . ($rec['description'] ?? '') . '</p>
                <p><strong>Action:</strong> ' . ($rec['action'] ?? '') . '</p>
            </div>';
            }
            
            $html .= '
        </div>';
        }
        
        $html .= '
    </div>
</body>
</html>';
        
        return $html;
    }
    
    /**
     * Generate CSV file from data array
     */
    private function generateCSVFile(array $data, string $filename): void
    {
        $handle = fopen($filename, 'w');
        
        if ($handle === false) {
            throw new Exception("Cannot create CSV file: $filename");
        }
        
        $headerWritten = false;
        
        foreach ($data as $section => $sectionData) {
            if (is_array($sectionData) && !empty($sectionData)) {
                // Write section header
                fputcsv($handle, ["Section: $section"]);
                
                // If it's an array of arrays (table data)
                if (isset($sectionData[0]) && is_array($sectionData[0])) {
                    // Write column headers
                    if (!$headerWritten) {
                        fputcsv($handle, array_keys($sectionData[0]));
                        $headerWritten = true;
                    }
                    
                    // Write data rows
                    foreach ($sectionData as $row) {
                        fputcsv($handle, array_values($row));
                    }
                } else {
                    // Write key-value pairs
                    foreach ($sectionData as $key => $value) {
                        fputcsv($handle, [$key, is_array($value) ? json_encode($value) : $value]);
                    }
                }
                
                // Add empty row between sections
                fputcsv($handle, []);
            }
        }
        
        fclose($handle);
    }
    
    /**
     * Get period start date based on period setting
     */
    private function getPeriodStart(): string
    {
        switch ($this->period) {
            case '1d':
                return date('Y-m-d H:i:s', strtotime('-1 day'));
            case '7d':
                return date('Y-m-d H:i:s', strtotime('-7 days'));
            case '30d':
                return date('Y-m-d H:i:s', strtotime('-30 days'));
            default:
                return date('Y-m-d H:i:s', strtotime('-7 days'));
        }
    }
    
    /**
     * Calculate summary status based on KPIs
     */
    private function calculateSummaryStatus(float $visibilityRate, float $stockCoverage, float $etlSuccessRate): array
    {
        $issues = [];
        
        if ($visibilityRate < 80) {
            $issues[] = 'Low visibility rate';
        }
        
        if ($stockCoverage < 70) {
            $issues[] = 'Low stock coverage';
        }
        
        if ($etlSuccessRate < 95) {
            $issues[] = 'ETL reliability issues';
        }
        
        if (empty($issues)) {
            return [
                'status' => 'healthy',
                'message' => 'All key metrics are within acceptable ranges'
            ];
        } elseif (count($issues) === 1) {
            return [
                'status' => 'warning',
                'message' => 'Minor issues detected: ' . implode(', ', $issues)
            ];
        } else {
            return [
                'status' => 'critical',
                'message' => 'Multiple issues detected: ' . implode(', ', $issues)
            ];
        }
    }
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
    
    // Validate format
    $validFormats = ['html', 'json', 'csv', 'excel'];
    if (!in_array($options['format'], $validFormats)) {
        echo "Error: Invalid format '{$options['format']}'. Valid formats: " . implode(', ', $validFormats) . "\n";
        return 1;
    }
    
    // Validate period
    $validPeriods = ['1d', '7d', '30d'];
    if (!in_array($options['period'], $validPeriods)) {
        echo "Error: Invalid period '{$options['period']}'. Valid periods: " . implode(', ', $validPeriods) . "\n";
        return 1;
    }
    
    // Load configuration
    try {
        $configFile = $options['config_file'] ?? __DIR__ . '/../Config/etl_config.php';
        
        if (!file_exists($configFile)) {
            throw new Exception("Configuration file not found: $configFile");
        }
        
        $etlConfig = require $configFile;
        
    } catch (Exception $e) {
        echo "Error loading configuration: " . $e->getMessage() . "\n";
        return 1;
    }
    
    try {
        // Initialize logger
        $logFile = ($etlConfig['logging']['log_directory'] ?? '/tmp') . '/business_verification_dashboard.log';
        $logger = new Logger($logFile, $options['verbose'] ? 'DEBUG' : 'INFO');
        
        if ($options['verbose']) {
            echo "Starting business verification dashboard generation...\n";
            echo "Output directory: {$options['output_dir']}\n";
            echo "Format: {$options['format']}\n";
            echo "Period: {$options['period']}\n";
            echo "Log file: $logFile\n";
        }
        
        $logger->info('Business verification dashboard generation started', [
            'script' => basename(__FILE__),
            'options' => $options
        ]);
        
        // Initialize database connection
        $db = new DatabaseConnection($etlConfig['database']);
        
        // Initialize dashboard generator
        $dashboard = new BusinessVerificationDashboard(
            $db, 
            $logger, 
            $etlConfig,
            $options['output_dir'],
            $options['period']
        );
        
        // Generate dashboard
        $result = $dashboard->generateDashboard($options['format']);
        
        if ($result['status'] === 'success') {
            echo "Dashboard generated successfully!\n";
            echo "Format: {$result['format']}\n";
            echo "Output files:\n";
            foreach ($result['output_files'] as $file) {
                echo "  - $file\n";
            }
            echo "Generation time: {$result['generation_time']} seconds\n";
        } else {
            echo "Dashboard generation failed: " . ($result['error'] ?? 'Unknown error') . "\n";
            return 1;
        }
        
        $duration = microtime(true) - $startTime;
        
        $logger->info('Business verification dashboard generation completed', [
            'duration' => round($duration, 2),
            'status' => $result['status'],
            'output_files' => $result['output_files'] ?? []
        ]);
        
        if ($options['verbose']) {
            echo "Dashboard generation completed in " . round($duration, 2) . " seconds\n";
        }
        
        return 0;
        
    } catch (Exception $e) {
        echo "Error: " . $e->getMessage() . "\n";
        
        if (isset($logger)) {
            $logger->error('Business verification dashboard generation failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
        }
        
        return 1;
    }
}

// Execute main function
exit(main());