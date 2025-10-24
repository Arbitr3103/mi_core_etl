<?php
/**
 * StockAlertManager Usage Example
 * 
 * This example demonstrates how to use the StockAlertManager class
 * for critical stock monitoring, alert generation, and notification delivery.
 */

require_once __DIR__ . '/../classes/StockAlertManager.php';

// Example usage of StockAlertManager
try {
    // Database connection (replace with your actual PDO connection)
    $pdo = new PDO('mysql:host=localhost;dbname=mi_core_db', 'username', 'password');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    // Initialize StockAlertManager
    $alertManager = new StockAlertManager($pdo);
    
    echo "=== StockAlertManager Example Usage ===\n\n";
    
    // 1. Analyze stock levels for all warehouses
    echo "1. Analyzing stock levels...\n";
    $analysis = $alertManager->analyzeStockLevels();
    
    echo "Total items analyzed: " . $analysis['summary']['total_analyzed'] . "\n";
    echo "Critical alerts: " . ($analysis['summary']['by_alert_level']['CRITICAL'] ?? 0) . "\n";
    echo "High priority alerts: " . ($analysis['summary']['by_alert_level']['HIGH'] ?? 0) . "\n";
    echo "Warehouses with issues: " . count($analysis['summary']['by_warehouse']) . "\n\n";
    
    // 2. Analyze stock levels for specific warehouse
    echo "2. Analyzing stock levels for specific warehouse...\n";
    $warehouseAnalysis = $alertManager->analyzeStockLevels(['warehouse' => 'Хоругвино']);
    echo "Items in Хоругвино warehouse: " . $warehouseAnalysis['summary']['total_analyzed'] . "\n\n";
    
    // 3. Generate critical stock alerts
    echo "3. Generating critical stock alerts...\n";
    $alertsResult = $alertManager->generateCriticalStockAlerts();
    
    echo "Total alerts generated: " . $alertsResult['total_alerts'] . "\n";
    echo "Warehouses affected: " . count($alertsResult['grouped_by_warehouse']) . "\n";
    
    if (!empty($alertsResult['alerts'])) {
        echo "Sample alert:\n";
        $sampleAlert = $alertsResult['alerts'][0];
        echo "- Product: " . $sampleAlert['product_name'] . "\n";
        echo "- Warehouse: " . $sampleAlert['warehouse_name'] . "\n";
        echo "- Alert Level: " . $sampleAlert['alert_level'] . "\n";
        echo "- Current Stock: " . $sampleAlert['current_stock'] . "\n";
        echo "- Days until stockout: " . ($sampleAlert['days_until_stockout'] ?? 'N/A') . "\n";
    }
    echo "\n";
    
    // 4. Send notifications (if alerts exist)
    if (!empty($alertsResult['alerts'])) {
        echo "4. Sending stock alert notifications...\n";
        $notificationResult = $alertManager->sendStockAlertNotifications($alertsResult['alerts']);
        echo "Notifications sent successfully: " . ($notificationResult ? 'Yes' : 'No') . "\n\n";
    }
    
    // 5. Get alert history
    echo "5. Retrieving alert history (last 7 days)...\n";
    $history = $alertManager->getStockAlertHistory(7);
    
    echo "Total alerts in last 7 days: " . $history['total_alerts'] . "\n";
    if (isset($history['metrics']['status_distribution'])) {
        echo "Status breakdown:\n";
        foreach ($history['metrics']['status_distribution'] as $status => $count) {
            echo "- {$status}: {$count}\n";
        }
    }
    echo "\n";
    
    // 6. Get response metrics
    echo "6. Getting alert response metrics (last 30 days)...\n";
    $metrics = $alertManager->getAlertResponseMetrics(30);
    
    echo "Total alerts: " . $metrics['total_alerts'] . "\n";
    echo "Effectiveness score: " . $metrics['effectiveness_score'] . "%\n";
    if ($metrics['response_time_metrics']['average_hours']) {
        echo "Average response time: " . $metrics['response_time_metrics']['average_hours'] . " hours\n";
    }
    echo "\n";
    
    // 7. Example of acknowledging an alert (if any exist)
    if (!empty($history['alerts'])) {
        $alertId = $history['alerts'][0]['id'];
        echo "7. Acknowledging alert ID {$alertId}...\n";
        $acknowledged = $alertManager->acknowledgeAlert($alertId, 'system_admin', 'Alert reviewed and action planned');
        echo "Alert acknowledged: " . ($acknowledged ? 'Yes' : 'No') . "\n\n";
        
        // 8. Example of resolving an alert
        echo "8. Resolving alert ID {$alertId}...\n";
        $resolved = $alertManager->resolveAlert($alertId, 'warehouse_manager', 'Stock replenished successfully');
        echo "Alert resolved: " . ($resolved ? 'Yes' : 'No') . "\n\n";
    }
    
    echo "=== Example completed successfully ===\n";
    
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
    echo "This is expected if database is not properly configured.\n";
    echo "Please ensure:\n";
    echo "1. Database connection parameters are correct\n";
    echo "2. Required tables exist (inventory, dim_products, replenishment_alerts, replenishment_settings)\n";
    echo "3. Sample data is available for testing\n";
}

/**
 * Example of advanced filtering
 */
function demonstrateAdvancedFiltering($alertManager) {
    echo "\n=== Advanced Filtering Examples ===\n";
    
    // Filter by specific product
    $productAnalysis = $alertManager->analyzeStockLevels(['product_id' => 123]);
    echo "Analysis for product ID 123: " . $productAnalysis['summary']['total_analyzed'] . " items\n";
    
    // Filter by source (Ozon or Wildberries)
    $ozonAnalysis = $alertManager->analyzeStockLevels(['source' => 'Ozon']);
    echo "Ozon items analyzed: " . $ozonAnalysis['summary']['total_analyzed'] . "\n";
    
    // Get history with filters
    $criticalHistory = $alertManager->getStockAlertHistory(30, [
        'alert_level' => 'CRITICAL',
        'status' => 'NEW'
    ]);
    echo "Unresolved critical alerts in last 30 days: " . $criticalHistory['total_alerts'] . "\n";
}

/**
 * Example of batch alert processing
 */
function demonstrateBatchProcessing($alertManager) {
    echo "\n=== Batch Processing Example ===\n";
    
    // Generate alerts for all warehouses
    $allAlerts = $alertManager->generateCriticalStockAlerts();
    
    // Process alerts by warehouse priority
    foreach ($allAlerts['grouped_by_warehouse'] as $warehouse => $warehouseData) {
        echo "Processing {$warehouse}: {$warehouseData['critical_count']} critical, {$warehouseData['high_count']} high priority\n";
        
        // Send notifications for this warehouse
        $alertManager->sendStockAlertNotifications([$warehouseData]);
    }
}