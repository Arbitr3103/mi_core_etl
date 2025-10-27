#!/usr/bin/env php
<?php

/**
 * Visibility Metrics Tracker
 * 
 * Tracks visibility field updates and status distribution for ProductETL
 * monitoring. Provides detailed metrics about visibility processing and
 * data quality for business stakeholders.
 * 
 * Requirements addressed:
 * - 5.2: Create metrics tracking for visibility field updates and status distribution
 * - 5.3: Implement alerts for ETL sequence failures or data quality issues
 * 
 * Usage:
 *   php visibility_metrics_tracker.php [options]
 * 
 * Options:
 *   --verbose          Enable verbose output
 *   --config=FILE      Use custom configuration file
 *   --output=FORMAT    Output format (json|text|csv) [default: text]
 *   --save-report      Save report to file
 *   --alert-check      Check alert thresholds and send notifications
 *   --help             Show this help message
 */

declare(strict_types=1);

// Set error reporting and time limits
error_reporting(E_ALL);
ini_set('display_errors', '1');
set_time_limit(1800); // 30 minutes

// Change to script directory
chdir(__DIR__);

// Load autoloader and configuration
try {
    require_once __DIR__ . '/../autoload.php';
} catch (Exception $e) {
    echo "Error loading dependencies: " . $e->getMessage() . "\n";
    exit(1);
}

// Import required classes
use MiCore\ETL\Ozon\Core\DatabaseConnection;
use MiCore\ETL\Ozon\Core\Logger;

/**
 * Parse command line arguments
 */
function parseArguments(array $argv): array
{
    $options = [
        'verbose' => false,
        'config_file' => null,
        'output_format' => 'text',
        'save_report' => false,
        'alert_check' => false,
        'help' => false
    ];
    
    for ($i = 1; $i < count($argv); $i++) {
        $arg = $argv[$i];
        
        switch ($arg) {
            case '--verbose':
                $options['verbose'] = true;
                break;
            case '--save-report':
                $options['save_report'] = true;
                break;
            case '--alert-check':
                $options['alert_check'] = true;
                break;
            case '--help':
                $options['help'] = true;
                break;
            default:
                if (strpos($arg, '--config=') === 0) {
                    $options['config_file'] = substr($arg, 9);
                } elseif (strpos($arg, '--output=') === 0) {
                    $options['output_format'] = substr($arg, 9);
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
    echo "Visibility Metrics Tracker\n";
    echo "==========================\n\n";
    echo "Tracks visibility field updates and status distribution for ProductETL\n";
    echo "monitoring. Provides detailed metrics about visibility processing and\n";
    echo "data quality for business stakeholders.\n\n";
    echo "Usage:\n";
    echo "  php visibility_metrics_tracker.php [options]\n\n";
    echo "Options:\n";
    echo "  --verbose          Enable verbose output\n";
    echo "  --config=FILE      Use custom configuration file\n";
    echo "  --output=FORMAT    Output format (json|text|csv) [default: text]\n";
    echo "  --save-report      Save report to file\n";
    echo "  --alert-check      Check alert thresholds and send notifications\n";
    echo "  --help             Show this help message\n\n";
    echo "Examples:\n";
    echo "  php visibility_metrics_tracker.php --verbose\n";
    echo "  php visibility_metrics_tracker.php --output=json --save-report\n";
    echo "  php visibility_metrics_tracker.php --alert-check\n\n";
}

/**
 * Visibility Metrics Tracker Class
 */
class VisibilityMetricsTracker
{
    private DatabaseConnection $db;
    private Logger $logger;
    private array $config;
    private array $metrics = [];
    
    public function __construct(DatabaseConnection $db, Logger $logger, array $config = [])
    {
        $this->db = $db;
        $this->logger = $logger;
        $this->config = $config;
    }
    
    /**
     * Collect all visibility metrics
     */
    public function collectMetrics(): array
    {
        $this->logger->info('Starting visibility metrics collection');
        
        $startTime = microtime(true);
        
        try {
            // Collect basic visibility statistics
            $this->metrics['basic_stats'] = $this->collectBasicVisibilityStats();
            
            // Collect visibility distribution
            $this->metrics['visibility_distribution'] = $this->collectVisibilityDistribution();
            
            // Collect temporal metrics (changes over time)
            $this->metrics['temporal_metrics'] = $this->collectTemporalMetrics();
            
            // Collect data quality metrics
            $this->metrics['data_quality'] = $this->collectDataQualityMetrics();
            
            // Collect ETL performance metrics
            $this->metrics['etl_performance'] = $this->collectETLPerformanceMetrics();
            
            // Collect business impact metrics
            $this->metrics['business_impact'] = $this->collectBusinessImpactMetrics();
            
            $duration = microtime(true) - $startTime;
            
            $this->metrics['collection_info'] = [
                'collected_at' => date('Y-m-d H:i:s'),
                'duration_seconds' => round($duration, 2),
                'total_metrics' => count($this->metrics)
            ];
            
            $this->logger->info('Visibility metrics collection completed', [
                'duration' => round($duration, 2),
                'metrics_collected' => count($this->metrics)
            ]);
            
            return $this->metrics;
            
        } catch (Exception $e) {
            $this->logger->error('Visibility metrics collection failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            throw $e;
        }
    }
    
    /**
     * Collect basic visibility statistics
     */
    private function collectBasicVisibilityStats(): array
    {
        $this->logger->debug('Collecting basic visibility statistics');
        
        $result = $this->db->query("
            SELECT 
                COUNT(*) as total_products,
                COUNT(CASE WHEN visibility IS NOT NULL THEN 1 END) as products_with_visibility,
                COUNT(CASE WHEN visibility IS NULL THEN 1 END) as products_without_visibility,
                COUNT(CASE WHEN visibility = 'VISIBLE' THEN 1 END) as visible_products,
                COUNT(CASE WHEN visibility = 'HIDDEN' THEN 1 END) as hidden_products,
                COUNT(CASE WHEN visibility = 'MODERATION' THEN 1 END) as moderation_products,
                COUNT(CASE WHEN visibility = 'DECLINED' THEN 1 END) as declined_products,
                COUNT(CASE WHEN visibility = 'UNKNOWN' THEN 1 END) as unknown_products,
                MIN(updated_at) as oldest_update,
                MAX(updated_at) as newest_update
            FROM dim_products
        ");
        
        if (empty($result)) {
            throw new Exception('Failed to collect basic visibility statistics');
        }
        
        $stats = $result[0];
        
        // Calculate percentages
        $totalProducts = (int)$stats['total_products'];
        
        if ($totalProducts > 0) {
            $stats['visibility_coverage_percent'] = round(((int)$stats['products_with_visibility'] / $totalProducts) * 100, 2);
            $stats['visible_percent'] = round(((int)$stats['visible_products'] / $totalProducts) * 100, 2);
            $stats['hidden_percent'] = round(((int)$stats['hidden_products'] / $totalProducts) * 100, 2);
            $stats['moderation_percent'] = round(((int)$stats['moderation_products'] / $totalProducts) * 100, 2);
            $stats['declined_percent'] = round(((int)$stats['declined_products'] / $totalProducts) * 100, 2);
            $stats['unknown_percent'] = round(((int)$stats['unknown_products'] / $totalProducts) * 100, 2);
        } else {
            $stats['visibility_coverage_percent'] = 0;
            $stats['visible_percent'] = 0;
            $stats['hidden_percent'] = 0;
            $stats['moderation_percent'] = 0;
            $stats['declined_percent'] = 0;
            $stats['unknown_percent'] = 0;
        }
        
        // Convert to integers for cleaner output
        foreach (['total_products', 'products_with_visibility', 'products_without_visibility', 
                  'visible_products', 'hidden_products', 'moderation_products', 
                  'declined_products', 'unknown_products'] as $field) {
            $stats[$field] = (int)$stats[$field];
        }
        
        return $stats;
    }
    
    /**
     * Collect visibility distribution with detailed breakdown
     */
    private function collectVisibilityDistribution(): array
    {
        $this->logger->debug('Collecting visibility distribution');
        
        $result = $this->db->query("
            SELECT 
                visibility,
                COUNT(*) as count,
                ROUND((COUNT(*) * 100.0 / SUM(COUNT(*)) OVER()), 2) as percentage,
                MIN(updated_at) as first_seen,
                MAX(updated_at) as last_updated
            FROM dim_products 
            WHERE visibility IS NOT NULL
            GROUP BY visibility
            ORDER BY count DESC
        ");
        
        $distribution = [];
        foreach ($result as $row) {
            $distribution[$row['visibility']] = [
                'count' => (int)$row['count'],
                'percentage' => (float)$row['percentage'],
                'first_seen' => $row['first_seen'],
                'last_updated' => $row['last_updated']
            ];
        }
        
        return $distribution;
    }
    
    /**
     * Collect temporal metrics (changes over time)
     */
    private function collectTemporalMetrics(): array
    {
        $this->logger->debug('Collecting temporal metrics');
        
        // Recent updates (last 24 hours)
        $recentUpdates = $this->db->query("
            SELECT 
                visibility,
                COUNT(*) as count
            FROM dim_products 
            WHERE updated_at > NOW() - INTERVAL 24 HOUR
            AND visibility IS NOT NULL
            GROUP BY visibility
            ORDER BY count DESC
        ");
        
        $recentMetrics = [];
        foreach ($recentUpdates as $row) {
            $recentMetrics[$row['visibility']] = (int)$row['count'];
        }
        
        // Updates by hour (last 24 hours)
        $hourlyUpdates = $this->db->query("
            SELECT 
                HOUR(updated_at) as hour,
                COUNT(*) as count
            FROM dim_products 
            WHERE updated_at > NOW() - INTERVAL 24 HOUR
            AND visibility IS NOT NULL
            GROUP BY HOUR(updated_at)
            ORDER BY hour
        ");
        
        $hourlyMetrics = [];
        foreach ($hourlyUpdates as $row) {
            $hourlyMetrics[(int)$row['hour']] = (int)$row['count'];
        }
        
        // Data freshness
        $freshnessResult = $this->db->query("
            SELECT 
                COUNT(CASE WHEN updated_at > NOW() - INTERVAL 1 HOUR THEN 1 END) as updated_last_hour,
                COUNT(CASE WHEN updated_at > NOW() - INTERVAL 6 HOUR THEN 1 END) as updated_last_6_hours,
                COUNT(CASE WHEN updated_at > NOW() - INTERVAL 24 HOUR THEN 1 END) as updated_last_24_hours,
                COUNT(CASE WHEN updated_at < NOW() - INTERVAL 24 HOUR THEN 1 END) as older_than_24_hours
            FROM dim_products
            WHERE visibility IS NOT NULL
        ");
        
        $freshness = $freshnessResult[0] ?? [];
        foreach ($freshness as $key => $value) {
            $freshness[$key] = (int)$value;
        }
        
        return [
            'recent_updates_24h' => $recentMetrics,
            'hourly_distribution' => $hourlyMetrics,
            'data_freshness' => $freshness
        ];
    }
    
    /**
     * Collect data quality metrics
     */
    private function collectDataQualityMetrics(): array
    {
        $this->logger->debug('Collecting data quality metrics');
        
        // Orphaned inventory (inventory without products)
        $orphanedInventory = $this->db->query("
            SELECT COUNT(*) as count
            FROM inventory i
            LEFT JOIN dim_products p ON i.offer_id = p.offer_id
            WHERE p.offer_id IS NULL
        ");
        
        // Products without inventory
        $productsWithoutInventory = $this->db->query("
            SELECT COUNT(*) as count
            FROM dim_products p
            LEFT JOIN inventory i ON p.offer_id = i.offer_id
            WHERE i.offer_id IS NULL
        ");
        
        // Visibility consistency check
        $visibilityConsistency = $this->db->query("
            SELECT 
                COUNT(CASE WHEN p.visibility = 'VISIBLE' AND i.offer_id IS NOT NULL THEN 1 END) as visible_with_inventory,
                COUNT(CASE WHEN p.visibility = 'VISIBLE' AND i.offer_id IS NULL THEN 1 END) as visible_without_inventory,
                COUNT(CASE WHEN p.visibility != 'VISIBLE' AND i.offer_id IS NOT NULL THEN 1 END) as hidden_with_inventory,
                COUNT(CASE WHEN p.visibility != 'VISIBLE' AND i.offer_id IS NULL THEN 1 END) as hidden_without_inventory
            FROM dim_products p
            LEFT JOIN inventory i ON p.offer_id = i.offer_id
            WHERE p.visibility IS NOT NULL
        ");
        
        $consistency = $visibilityConsistency[0] ?? [];
        foreach ($consistency as $key => $value) {
            $consistency[$key] = (int)$value;
        }
        
        return [
            'orphaned_inventory_items' => (int)($orphanedInventory[0]['count'] ?? 0),
            'products_without_inventory' => (int)($productsWithoutInventory[0]['count'] ?? 0),
            'visibility_inventory_consistency' => $consistency
        ];
    }
    
    /**
     * Collect ETL performance metrics
     */
    private function collectETLPerformanceMetrics(): array
    {
        $this->logger->debug('Collecting ETL performance metrics');
        
        // Recent ETL executions
        $recentExecutions = $this->db->query("
            SELECT 
                workflow_id,
                status,
                duration,
                product_etl_status,
                inventory_etl_status,
                created_at
            FROM etl_workflow_executions
            WHERE created_at > NOW() - INTERVAL 7 DAY
            ORDER BY created_at DESC
            LIMIT 10
        ");
        
        // ETL success rate
        $successRate = $this->db->query("
            SELECT 
                COUNT(*) as total_executions,
                COUNT(CASE WHEN status = 'success' THEN 1 END) as successful_executions,
                COUNT(CASE WHEN status = 'failed' THEN 1 END) as failed_executions,
                AVG(duration) as avg_duration,
                MIN(duration) as min_duration,
                MAX(duration) as max_duration
            FROM etl_workflow_executions
            WHERE created_at > NOW() - INTERVAL 7 DAY
        ");
        
        $performanceStats = $successRate[0] ?? [];
        
        if (!empty($performanceStats)) {
            $totalExecutions = (int)$performanceStats['total_executions'];
            $successfulExecutions = (int)$performanceStats['successful_executions'];
            
            $performanceStats['success_rate_percent'] = $totalExecutions > 0 ? 
                round(($successfulExecutions / $totalExecutions) * 100, 2) : 0;
            
            foreach (['total_executions', 'successful_executions', 'failed_executions'] as $field) {
                $performanceStats[$field] = (int)$performanceStats[$field];
            }
            
            foreach (['avg_duration', 'min_duration', 'max_duration'] as $field) {
                $performanceStats[$field] = round((float)$performanceStats[$field], 2);
            }
        }
        
        return [
            'recent_executions' => $recentExecutions,
            'performance_stats' => $performanceStats
        ];
    }
    
    /**
     * Collect business impact metrics
     */
    private function collectBusinessImpactMetrics(): array
    {
        $this->logger->debug('Collecting business impact metrics');
        
        // Products "in sale" vs total
        $businessMetrics = $this->db->query("
            SELECT 
                COUNT(*) as total_products,
                COUNT(CASE WHEN p.visibility = 'VISIBLE' AND i.offer_id IS NOT NULL AND (i.present - i.reserved) > 0 THEN 1 END) as products_in_sale,
                COUNT(CASE WHEN p.visibility = 'VISIBLE' THEN 1 END) as visible_products,
                COUNT(CASE WHEN i.offer_id IS NOT NULL AND (i.present - i.reserved) > 0 THEN 1 END) as products_with_stock
            FROM dim_products p
            LEFT JOIN inventory i ON p.offer_id = i.offer_id
            WHERE p.visibility IS NOT NULL
        ");
        
        $business = $businessMetrics[0] ?? [];
        
        if (!empty($business)) {
            $totalProducts = (int)$business['total_products'];
            $productsInSale = (int)$business['products_in_sale'];
            
            $business['in_sale_percentage'] = $totalProducts > 0 ? 
                round(($productsInSale / $totalProducts) * 100, 2) : 0;
            
            foreach (['total_products', 'products_in_sale', 'visible_products', 'products_with_stock'] as $field) {
                $business[$field] = (int)$business[$field];
            }
        }
        
        // Stock status distribution for visible products
        $stockDistribution = $this->db->query("
            SELECT 
                CASE 
                    WHEN p.visibility != 'VISIBLE' THEN 'archived_or_hidden'
                    WHEN (i.present - i.reserved) <= 0 THEN 'out_of_stock'
                    WHEN (i.present - i.reserved) > 0 THEN 'in_stock'
                    ELSE 'no_inventory_data'
                END as stock_category,
                COUNT(*) as count
            FROM dim_products p
            LEFT JOIN inventory i ON p.offer_id = i.offer_id
            WHERE p.visibility IS NOT NULL
            GROUP BY stock_category
            ORDER BY count DESC
        ");
        
        $stockStats = [];
        foreach ($stockDistribution as $row) {
            $stockStats[$row['stock_category']] = (int)$row['count'];
        }
        
        return [
            'business_metrics' => $business,
            'stock_distribution' => $stockStats
        ];
    }
    
    /**
     * Check alert thresholds and generate alerts
     */
    public function checkAlertThresholds(): array
    {
        $this->logger->info('Checking visibility metrics alert thresholds');
        
        $alerts = [];
        $alertConfig = $this->config['alerts']['data_quality'] ?? [];
        
        if (!($alertConfig['enabled'] ?? false)) {
            $this->logger->info('Data quality alerts are disabled');
            return $alerts;
        }
        
        $thresholds = $alertConfig['thresholds'] ?? [];
        
        // Check visibility coverage
        if (isset($thresholds['visibility_coverage_min'])) {
            $coveragePercent = $this->metrics['basic_stats']['visibility_coverage_percent'] ?? 0;
            $minCoverage = $thresholds['visibility_coverage_min'];
            
            if ($coveragePercent < $minCoverage) {
                $alerts[] = [
                    'type' => 'visibility_coverage_low',
                    'severity' => 'warning',
                    'message' => "Visibility coverage is {$coveragePercent}%, below threshold of {$minCoverage}%",
                    'current_value' => $coveragePercent,
                    'threshold' => $minCoverage,
                    'metric' => 'visibility_coverage_percent'
                ];
            }
        }
        
        // Check orphaned inventory
        if (isset($thresholds['orphaned_inventory_max'])) {
            $orphanedCount = $this->metrics['data_quality']['orphaned_inventory_items'] ?? 0;
            $maxOrphaned = $thresholds['orphaned_inventory_max'];
            
            if ($orphanedCount > $maxOrphaned) {
                $alerts[] = [
                    'type' => 'orphaned_inventory_high',
                    'severity' => 'error',
                    'message' => "Orphaned inventory items: {$orphanedCount}, above threshold of {$maxOrphaned}",
                    'current_value' => $orphanedCount,
                    'threshold' => $maxOrphaned,
                    'metric' => 'orphaned_inventory_items'
                ];
            }
        }
        
        // Check data freshness
        if (isset($thresholds['data_freshness_hours'])) {
            $maxHours = $thresholds['data_freshness_hours'];
            $newestUpdate = $this->metrics['basic_stats']['newest_update'] ?? null;
            
            if ($newestUpdate) {
                $hoursSinceUpdate = (time() - strtotime($newestUpdate)) / 3600;
                
                if ($hoursSinceUpdate > $maxHours) {
                    $alerts[] = [
                        'type' => 'data_stale',
                        'severity' => 'warning',
                        'message' => "Data is stale: last update {$hoursSinceUpdate} hours ago, threshold is {$maxHours} hours",
                        'current_value' => round($hoursSinceUpdate, 1),
                        'threshold' => $maxHours,
                        'metric' => 'data_freshness_hours'
                    ];
                }
            }
        }
        
        if (!empty($alerts)) {
            $this->logger->warning('Visibility metrics alerts triggered', [
                'alert_count' => count($alerts),
                'alerts' => $alerts
            ]);
        } else {
            $this->logger->info('No visibility metrics alerts triggered');
        }
        
        return $alerts;
    }
    
    /**
     * Get metrics in specified format
     */
    public function getMetrics(): array
    {
        return $this->metrics;
    }
}

/**
 * Format metrics for output
 */
function formatMetrics(array $metrics, string $format): string
{
    switch ($format) {
        case 'json':
            return json_encode($metrics, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
            
        case 'csv':
            return formatMetricsAsCsv($metrics);
            
        case 'text':
        default:
            return formatMetricsAsText($metrics);
    }
}

/**
 * Format metrics as text
 */
function formatMetricsAsText(array $metrics): string
{
    $output = "Visibility Metrics Report\n";
    $output .= "========================\n";
    $output .= "Generated: " . ($metrics['collection_info']['collected_at'] ?? 'Unknown') . "\n";
    $output .= "Duration: " . ($metrics['collection_info']['duration_seconds'] ?? 'Unknown') . " seconds\n\n";
    
    // Basic Statistics
    if (isset($metrics['basic_stats'])) {
        $stats = $metrics['basic_stats'];
        $output .= "Basic Statistics:\n";
        $output .= "-----------------\n";
        $output .= "Total Products: " . number_format($stats['total_products']) . "\n";
        $output .= "Products with Visibility: " . number_format($stats['products_with_visibility']) . " ({$stats['visibility_coverage_percent']}%)\n";
        $output .= "Visible Products: " . number_format($stats['visible_products']) . " ({$stats['visible_percent']}%)\n";
        $output .= "Hidden Products: " . number_format($stats['hidden_products']) . " ({$stats['hidden_percent']}%)\n";
        $output .= "In Moderation: " . number_format($stats['moderation_products']) . " ({$stats['moderation_percent']}%)\n";
        $output .= "Declined: " . number_format($stats['declined_products']) . " ({$stats['declined_percent']}%)\n";
        $output .= "Unknown Status: " . number_format($stats['unknown_products']) . " ({$stats['unknown_percent']}%)\n";
        $output .= "Data Range: {$stats['oldest_update']} to {$stats['newest_update']}\n\n";
    }
    
    // Visibility Distribution
    if (isset($metrics['visibility_distribution'])) {
        $output .= "Visibility Distribution:\n";
        $output .= "-----------------------\n";
        foreach ($metrics['visibility_distribution'] as $status => $data) {
            $output .= sprintf("%-12s: %s (%s%%)\n", 
                $status, 
                number_format($data['count']), 
                $data['percentage']
            );
        }
        $output .= "\n";
    }
    
    // Business Impact
    if (isset($metrics['business_impact']['business_metrics'])) {
        $business = $metrics['business_impact']['business_metrics'];
        $output .= "Business Impact:\n";
        $output .= "----------------\n";
        $output .= "Products In Sale: " . number_format($business['products_in_sale']) . " ({$business['in_sale_percentage']}%)\n";
        $output .= "Visible Products: " . number_format($business['visible_products']) . "\n";
        $output .= "Products with Stock: " . number_format($business['products_with_stock']) . "\n\n";
    }
    
    // Data Quality
    if (isset($metrics['data_quality'])) {
        $quality = $metrics['data_quality'];
        $output .= "Data Quality:\n";
        $output .= "-------------\n";
        $output .= "Orphaned Inventory Items: " . number_format($quality['orphaned_inventory_items']) . "\n";
        $output .= "Products without Inventory: " . number_format($quality['products_without_inventory']) . "\n\n";
    }
    
    // ETL Performance
    if (isset($metrics['etl_performance']['performance_stats'])) {
        $perf = $metrics['etl_performance']['performance_stats'];
        $output .= "ETL Performance (Last 7 Days):\n";
        $output .= "------------------------------\n";
        $output .= "Total Executions: " . ($perf['total_executions'] ?? 0) . "\n";
        $output .= "Success Rate: " . ($perf['success_rate_percent'] ?? 0) . "%\n";
        $output .= "Average Duration: " . ($perf['avg_duration'] ?? 0) . " seconds\n\n";
    }
    
    return $output;
}

/**
 * Format metrics as CSV
 */
function formatMetricsAsCsv(array $metrics): string
{
    $csv = "Metric,Value,Percentage,Timestamp\n";
    
    if (isset($metrics['basic_stats'])) {
        $stats = $metrics['basic_stats'];
        $timestamp = $metrics['collection_info']['collected_at'] ?? '';
        
        $csv .= "Total Products,{$stats['total_products']},,{$timestamp}\n";
        $csv .= "Products with Visibility,{$stats['products_with_visibility']},{$stats['visibility_coverage_percent']},{$timestamp}\n";
        $csv .= "Visible Products,{$stats['visible_products']},{$stats['visible_percent']},{$timestamp}\n";
        $csv .= "Hidden Products,{$stats['hidden_products']},{$stats['hidden_percent']},{$timestamp}\n";
    }
    
    return $csv;
}

/**
 * Save report to file
 */
function saveReport(string $content, string $format, array $options): string
{
    $reportsDir = __DIR__ . '/../Logs/visibility_reports';
    
    if (!is_dir($reportsDir)) {
        mkdir($reportsDir, 0755, true);
    }
    
    $filename = 'visibility_metrics_' . date('Y-m-d_H-i-s') . '.' . $format;
    $filepath = $reportsDir . '/' . $filename;
    
    if (file_put_contents($filepath, $content) === false) {
        throw new Exception("Failed to save report to: $filepath");
    }
    
    if ($options['verbose']) {
        echo "Report saved to: $filepath\n";
    }
    
    return $filepath;
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
        $configFile = $options['config_file'] ?? __DIR__ . '/../Config/cron_config.php';
        $etlConfigFile = __DIR__ . '/../Config/etl_config.php';
        
        if (!file_exists($configFile)) {
            throw new Exception("Configuration file not found: $configFile");
        }
        
        if (!file_exists($etlConfigFile)) {
            throw new Exception("ETL configuration file not found: $etlConfigFile");
        }
        
        $cronConfig = require $configFile;
        $etlConfig = require $etlConfigFile;
        
    } catch (Exception $e) {
        echo "Error loading configuration: " . $e->getMessage() . "\n";
        return 1;
    }
    
    try {
        // Initialize logger
        $logConfig = $cronConfig['logging'] ?? [];
        $logFile = ($logConfig['log_directory'] ?? '/tmp') . '/visibility_metrics_' . date('Y-m-d') . '.log';
        
        $logger = new Logger($logFile, $options['verbose'] ? 'DEBUG' : 'INFO');
        
        if ($options['verbose']) {
            echo "Starting visibility metrics tracking...\n";
            echo "Log file: $logFile\n";
        }
        
        $logger->info('Visibility metrics tracking started', [
            'script' => basename(__FILE__),
            'options' => $options
        ]);
        
        // Initialize database connection
        $db = new DatabaseConnection($etlConfig['database']);
        
        // Initialize metrics tracker
        $tracker = new VisibilityMetricsTracker($db, $logger, $cronConfig);
        
        // Collect metrics
        $metrics = $tracker->collectMetrics();
        
        // Check alerts if requested
        $alerts = [];
        if ($options['alert_check']) {
            $alerts = $tracker->checkAlertThresholds();
            
            if (!empty($alerts)) {
                echo "ALERTS TRIGGERED:\n";
                foreach ($alerts as $alert) {
                    echo "  [{$alert['severity']}] {$alert['message']}\n";
                }
                echo "\n";
            }
        }
        
        // Format and output metrics
        $formattedMetrics = formatMetrics($metrics, $options['output_format']);
        
        if ($options['save_report']) {
            $reportFile = saveReport($formattedMetrics, $options['output_format'], $options);
            
            if ($options['verbose']) {
                echo "Report saved to: $reportFile\n";
            }
        } else {
            echo $formattedMetrics;
        }
        
        $duration = microtime(true) - $startTime;
        
        $logger->info('Visibility metrics tracking completed', [
            'duration' => round($duration, 2),
            'metrics_collected' => count($metrics),
            'alerts_triggered' => count($alerts)
        ]);
        
        if ($options['verbose']) {
            echo "Visibility metrics tracking completed in " . round($duration, 2) . " seconds\n";
        }
        
        return 0;
        
    } catch (Exception $e) {
        echo "Error: " . $e->getMessage() . "\n";
        
        if (isset($logger)) {
            $logger->error('Visibility metrics tracking failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
        }
        
        return 1;
    }
}

// Execute main function
exit(main());