#!/usr/bin/env php
<?php
/**
 * Warehouse Metrics Refresh Script
 * 
 * This script recalculates and updates warehouse sales metrics in the
 * warehouse_sales_metrics table. It should be run periodically (e.g., hourly)
 * via cron to keep metrics up-to-date.
 * 
 * Requirements: 11, 12
 * 
 * Usage:
 *   php scripts/refresh_warehouse_metrics.php [options]
 * 
 * Options:
 *   --product-id=ID    Refresh metrics for specific product only
 *   --warehouse=NAME   Refresh metrics for specific warehouse only
 *   --verbose          Enable verbose output
 *   --help             Show this help message
 */

// Ensure script is run from CLI
if (php_sapi_name() !== 'cli') {
    die('This script can only be run from the command line.');
}

// Set error reporting
error_reporting(E_ALL);
ini_set('display_errors', '1');

// Set execution time limit (30 minutes)
set_time_limit(1800);

// Load configuration and database connection
require_once __DIR__ . '/../config/database_postgresql.php';
require_once __DIR__ . '/../api/classes/WarehouseSalesAnalyticsService.php';
require_once __DIR__ . '/../api/classes/ReplenishmentCalculator.php';

// ===================================================================
// CONFIGURATION
// ===================================================================

$config = [
    'batch_size' => 100,  // Process products in batches
    'log_file' => __DIR__ . '/../logs/warehouse_metrics_refresh.log',
    'verbose' => false,
    'product_id' => null,
    'warehouse' => null
];

// ===================================================================
// PARSE COMMAND LINE ARGUMENTS
// ===================================================================

$options = getopt('', ['product-id:', 'warehouse:', 'verbose', 'help']);

if (isset($options['help'])) {
    showHelp();
    exit(0);
}

if (isset($options['product-id'])) {
    $config['product_id'] = (int)$options['product-id'];
}

if (isset($options['warehouse'])) {
    $config['warehouse'] = $options['warehouse'];
}

if (isset($options['verbose'])) {
    $config['verbose'] = true;
}

// ===================================================================
// LOGGING FUNCTIONS
// ===================================================================

/**
 * Log message to file and optionally to console
 */
function logMessage($message, $level = 'INFO', $verbose = false) {
    global $config;
    
    $timestamp = date('Y-m-d H:i:s');
    $logEntry = "[$timestamp] [$level] $message\n";
    
    // Ensure log directory exists
    $logDir = dirname($config['log_file']);
    if (!is_dir($logDir)) {
        mkdir($logDir, 0755, true);
    }
    
    // Write to log file
    file_put_contents($config['log_file'], $logEntry, FILE_APPEND);
    
    // Output to console if verbose or error
    if ($config['verbose'] || $level === 'ERROR') {
        echo $logEntry;
    }
}

/**
 * Show help message
 */
function showHelp() {
    echo <<<HELP
Warehouse Metrics Refresh Script

This script recalculates and updates warehouse sales metrics.

Usage:
  php scripts/refresh_warehouse_metrics.php [options]

Options:
  --product-id=ID    Refresh metrics for specific product only
  --warehouse=NAME   Refresh metrics for specific warehouse only
  --verbose          Enable verbose output
  --help             Show this help message

Examples:
  # Refresh all metrics
  php scripts/refresh_warehouse_metrics.php

  # Refresh metrics for specific product
  php scripts/refresh_warehouse_metrics.php --product-id=123

  # Refresh metrics for specific warehouse
  php scripts/refresh_warehouse_metrics.php --warehouse="АДЫГЕЙСК_РФЦ"

  # Verbose mode
  php scripts/refresh_warehouse_metrics.php --verbose

HELP;
}

// ===================================================================
// MAIN EXECUTION
// ===================================================================

try {
    logMessage("=== Starting warehouse metrics refresh ===", 'INFO');
    
    if ($config['product_id']) {
        logMessage("Filtering by product_id: {$config['product_id']}", 'INFO');
    }
    
    if ($config['warehouse']) {
        logMessage("Filtering by warehouse: {$config['warehouse']}", 'INFO');
    }
    
    // Get database connection
    $pdo = getDatabaseConnection();
    
    // Initialize services
    $salesAnalytics = new WarehouseSalesAnalyticsService($pdo);
    $replenishmentCalc = new ReplenishmentCalculator();
    
    // Get list of inventory items to process
    $whereConditions = [];
    $params = [];
    
    if ($config['product_id']) {
        $whereConditions[] = "i.product_id = :product_id";
        $params['product_id'] = $config['product_id'];
    }
    
    if ($config['warehouse']) {
        $whereConditions[] = "i.warehouse_name = :warehouse";
        $params['warehouse'] = $config['warehouse'];
    }
    
    $whereClause = !empty($whereConditions) ? 'WHERE ' . implode(' AND ', $whereConditions) : '';
    
    $sql = "
        SELECT DISTINCT
            i.product_id,
            i.warehouse_name,
            i.source,
            i.quantity_present as available,
            COALESCE(i.in_transit, 0) as in_transit,
            COALESCE(i.in_supply_requests, 0) as in_supply_requests
        FROM inventory i
        INNER JOIN dim_products dp ON i.product_id = dp.id
        $whereClause
        ORDER BY i.product_id, i.warehouse_name
    ";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $items = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $totalItems = count($items);
    logMessage("Found $totalItems inventory items to process", 'INFO');
    
    if ($totalItems === 0) {
        logMessage("No items to process. Exiting.", 'INFO');
        exit(0);
    }
    
    // Process items in batches
    $processed = 0;
    $updated = 0;
    $errors = 0;
    $startTime = microtime(true);
    
    // Begin transaction for batch processing
    $pdo->beginTransaction();
    
    foreach ($items as $item) {
        try {
            $productId = (int)$item['product_id'];
            $warehouseName = $item['warehouse_name'];
            $source = $item['source'];
            $available = (int)$item['available'];
            $inTransit = (int)$item['in_transit'];
            $inSupplyRequests = (int)$item['in_supply_requests'];
            
            // Calculate sales metrics
            $dailySalesAvg = $salesAnalytics->calculateDailySalesAvg($productId, $warehouseName, 28);
            $salesLast28Days = $salesAnalytics->getSalesLast28Days($productId, $warehouseName);
            $daysWithStock = $salesAnalytics->getDaysWithStock($productId, $warehouseName, 28);
            $daysWithoutSales = $salesAnalytics->getDaysWithoutSales($productId, $warehouseName);
            
            // Calculate replenishment metrics
            $targetStock = $replenishmentCalc->calculateTargetStock($dailySalesAvg, 30);
            $replenishmentNeed = $replenishmentCalc->calculateReplenishmentNeed(
                $targetStock,
                $available,
                $inTransit,
                $inSupplyRequests
            );
            
            // Calculate liquidity metrics
            $daysOfStock = $replenishmentCalc->calculateDaysOfStock($available, $dailySalesAvg);
            $liquidityStatus = $replenishmentCalc->determineLiquidityStatus($daysOfStock);
            
            // Insert or update metrics in warehouse_sales_metrics table
            $upsertSql = "
                INSERT INTO warehouse_sales_metrics (
                    product_id,
                    warehouse_name,
                    source,
                    daily_sales_avg,
                    sales_last_28_days,
                    days_with_stock,
                    days_without_sales,
                    days_of_stock,
                    liquidity_status,
                    target_stock,
                    replenishment_need,
                    calculated_at
                ) VALUES (
                    :product_id,
                    :warehouse_name,
                    :source,
                    :daily_sales_avg,
                    :sales_last_28_days,
                    :days_with_stock,
                    :days_without_sales,
                    :days_of_stock,
                    :liquidity_status,
                    :target_stock,
                    :replenishment_need,
                    CURRENT_TIMESTAMP
                )
                ON CONFLICT (product_id, warehouse_name, source)
                DO UPDATE SET
                    daily_sales_avg = EXCLUDED.daily_sales_avg,
                    sales_last_28_days = EXCLUDED.sales_last_28_days,
                    days_with_stock = EXCLUDED.days_with_stock,
                    days_without_sales = EXCLUDED.days_without_sales,
                    days_of_stock = EXCLUDED.days_of_stock,
                    liquidity_status = EXCLUDED.liquidity_status,
                    target_stock = EXCLUDED.target_stock,
                    replenishment_need = EXCLUDED.replenishment_need,
                    calculated_at = CURRENT_TIMESTAMP
            ";
            
            $upsertStmt = $pdo->prepare($upsertSql);
            $upsertStmt->execute([
                'product_id' => $productId,
                'warehouse_name' => $warehouseName,
                'source' => $source,
                'daily_sales_avg' => $dailySalesAvg,
                'sales_last_28_days' => $salesLast28Days,
                'days_with_stock' => $daysWithStock,
                'days_without_sales' => $daysWithoutSales,
                'days_of_stock' => $daysOfStock,
                'liquidity_status' => $liquidityStatus,
                'target_stock' => $targetStock,
                'replenishment_need' => $replenishmentNeed
            ]);
            
            $updated++;
            $processed++;
            
            if ($config['verbose'] && $processed % 10 === 0) {
                logMessage("Processed $processed / $totalItems items...", 'INFO');
            }
            
            // Commit batch every N items
            if ($processed % $config['batch_size'] === 0) {
                $pdo->commit();
                $pdo->beginTransaction();
                
                logMessage("Committed batch at $processed items", 'INFO');
            }
            
        } catch (Exception $e) {
            $errors++;
            logMessage(
                "Error processing product $productId at warehouse $warehouseName: " . $e->getMessage(),
                'ERROR'
            );
        }
    }
    
    // Commit remaining items
    $pdo->commit();
    
    $endTime = microtime(true);
    $duration = round($endTime - $startTime, 2);
    
    // Log summary
    logMessage("=== Metrics refresh completed ===", 'INFO');
    logMessage("Total items processed: $processed", 'INFO');
    logMessage("Successfully updated: $updated", 'INFO');
    logMessage("Errors: $errors", 'INFO');
    logMessage("Duration: {$duration}s", 'INFO');
    
    // Clean up old metrics (optional - remove metrics older than 7 days)
    $cleanupSql = "
        DELETE FROM warehouse_sales_metrics
        WHERE calculated_at < CURRENT_TIMESTAMP - INTERVAL '7 days'
    ";
    $deletedRows = $pdo->exec($cleanupSql);
    
    if ($deletedRows > 0) {
        logMessage("Cleaned up $deletedRows old metric records", 'INFO');
    }
    
    exit(0);
    
} catch (Exception $e) {
    logMessage("Fatal error: " . $e->getMessage(), 'ERROR');
    logMessage("Stack trace: " . $e->getTraceAsString(), 'ERROR');
    
    // Rollback transaction if active
    if ($pdo && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    
    exit(1);
}
?>
