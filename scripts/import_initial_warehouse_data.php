<?php
/**
 * Import Initial Warehouse Data
 * 
 * Imports Ozon warehouse data and calculates initial metrics for warehouse dashboard
 */

require_once __DIR__ . '/../config/database_postgresql.php';

echo "📦 Importing Initial Warehouse Data for Production\n";
echo str_repeat('=', 60) . "\n";

try {
    $pdo = getDatabaseConnection();
    
    // Step 1: Check current data status
    echo "📊 Step 1: Checking current data status...\n";
    
    // Check inventory records
    $inventory_count = $pdo->query("SELECT COUNT(*) as count FROM inventory")->fetch();
    echo "  📋 Inventory records: " . $inventory_count['count'] . "\n";
    
    // Check products
    $products_count = $pdo->query("SELECT COUNT(*) as count FROM dim_products")->fetch();
    echo "  📋 Products: " . $products_count['count'] . "\n";
    
    // Check stock movements
    $movements_count = $pdo->query("
        SELECT COUNT(*) as count 
        FROM stock_movements 
        WHERE movement_date >= CURRENT_DATE - 30
    ")->fetch();
    echo "  📋 Stock movements (last 30 days): " . $movements_count['count'] . "\n";
    
    // Check warehouse metrics
    $metrics_count = $pdo->query("SELECT COUNT(*) as count FROM warehouse_sales_metrics")->fetch();
    echo "  📋 Warehouse metrics: " . $metrics_count['count'] . "\n";
    
    // Step 2: Update cluster information for warehouses
    echo "\n📊 Step 2: Updating warehouse cluster information...\n";
    
    $cluster_updates = [
        'АДЫГЕЙСК_РФЦ' => 'Юг',
        'ЕКАТЕРИНБУРГ_РФЦ' => 'Урал',
        'КАЗАНЬ_РФЦ' => 'Поволжье',
        'КРАСНОДАР_РФЦ' => 'Юг',
        'НОВОСИБИРСК_РФЦ' => 'Сибирь',
        'РОСТОВ-НА-ДОНУ_РФЦ' => 'Юг',
        'САМАРА_РФЦ' => 'Поволжье',
        'САНКТ-ПЕТЕРБУРГ_РФЦ' => 'Северо-Запад',
        'ТВЕРЬ_РФЦ' => 'Центр',
        'ХАБАРОВСК_РФЦ' => 'Дальний Восток'
    ];
    
    $updated_warehouses = 0;
    foreach ($cluster_updates as $warehouse => $cluster) {
        $stmt = $pdo->prepare("
            UPDATE inventory 
            SET cluster = ? 
            WHERE warehouse_name = ? AND cluster IS NULL
        ");
        $result = $stmt->execute([$cluster, $warehouse]);
        $affected = $stmt->rowCount();
        
        if ($affected > 0) {
            echo "  ✅ Updated $warehouse -> $cluster ($affected records)\n";
            $updated_warehouses += $affected;
        }
    }
    
    echo "  📋 Total warehouse records updated: $updated_warehouses\n";
    
    // Step 3: Import/refresh Ozon metrics for existing inventory
    echo "\n📊 Step 3: Refreshing Ozon metrics for existing inventory...\n";
    
    // Get sample of inventory records to refresh metrics
    $inventory_sample = $pdo->query("
        SELECT DISTINCT product_id, warehouse_name, source
        FROM inventory 
        WHERE quantity_present > 0 
        LIMIT 50
    ")->fetchAll();
    
    echo "  📋 Refreshing metrics for " . count($inventory_sample) . " product-warehouse combinations...\n";
    
    $refreshed_count = 0;
    foreach ($inventory_sample as $item) {
        try {
            $stmt = $pdo->prepare("
                SELECT refresh_warehouse_metrics_for_product(?, ?, ?::marketplace_source)
            ");
            $stmt->execute([
                $item['product_id'],
                $item['warehouse_name'],
                $item['source']
            ]);
            $refreshed_count++;
            
            if ($refreshed_count % 10 == 0) {
                echo "  📊 Processed $refreshed_count/" . count($inventory_sample) . " items...\n";
            }
        } catch (Exception $e) {
            echo "  ⚠️ Failed to refresh metrics for product " . $item['product_id'] . ": " . $e->getMessage() . "\n";
        }
    }
    
    echo "  ✅ Successfully refreshed metrics for $refreshed_count items\n";
    
    // Step 4: Verify data integrity
    echo "\n📊 Step 4: Verifying data integrity...\n";
    
    // Check for products with inventory but no metrics
    $missing_metrics = $pdo->query("
        SELECT COUNT(*) as count
        FROM inventory i
        LEFT JOIN warehouse_sales_metrics m ON (
            i.product_id = m.product_id 
            AND i.warehouse_name = m.warehouse_name 
            AND i.source = m.source
        )
        WHERE i.quantity_present > 0 AND m.id IS NULL
    ")->fetch();
    
    echo "  📋 Products with inventory but no metrics: " . $missing_metrics['count'] . "\n";
    
    // Check liquidity distribution
    $liquidity_dist = $pdo->query("
        SELECT 
            liquidity_status,
            COUNT(*) as count
        FROM warehouse_sales_metrics
        GROUP BY liquidity_status
        ORDER BY 
            CASE liquidity_status
                WHEN 'critical' THEN 1
                WHEN 'low' THEN 2
                WHEN 'normal' THEN 3
                WHEN 'excess' THEN 4
                ELSE 5
            END
    ")->fetchAll();
    
    echo "  📋 Liquidity status distribution:\n";
    foreach ($liquidity_dist as $status) {
        echo "    - " . $status['liquidity_status'] . ": " . $status['count'] . " products\n";
    }
    
    // Check replenishment needs
    $replenishment_needs = $pdo->query("
        SELECT COUNT(*) as count, SUM(replenishment_need) as total_need
        FROM warehouse_sales_metrics
        WHERE replenishment_need > 0
    ")->fetch();
    
    echo "  📋 Products needing replenishment: " . $replenishment_needs['count'] . "\n";
    echo "  📋 Total replenishment units needed: " . ($replenishment_needs['total_need'] ?? 0) . "\n";
    
    // Step 5: Create sample test data if needed
    echo "\n📊 Step 5: Ensuring sufficient test data...\n";
    
    $current_metrics = $pdo->query("SELECT COUNT(*) as count FROM warehouse_sales_metrics")->fetch();
    
    if ($current_metrics['count'] < 10) {
        echo "  ⚠️ Low metrics count, creating additional sample data...\n";
        
        // Get some inventory records and ensure they have metrics
        $sample_inventory = $pdo->query("
            SELECT product_id, warehouse_name, source
            FROM inventory 
            WHERE quantity_present > 0
            LIMIT 20
        ")->fetchAll();
        
        foreach ($sample_inventory as $item) {
            try {
                $stmt = $pdo->prepare("
                    SELECT refresh_warehouse_metrics_for_product(?, ?, ?::marketplace_source)
                ");
                $stmt->execute([
                    $item['product_id'],
                    $item['warehouse_name'],
                    $item['source']
                ]);
            } catch (Exception $e) {
                // Continue on error
            }
        }
        
        $new_count = $pdo->query("SELECT COUNT(*) as count FROM warehouse_sales_metrics")->fetch();
        echo "  ✅ Metrics count increased to: " . $new_count['count'] . "\n";
    } else {
        echo "  ✅ Sufficient metrics data available: " . $current_metrics['count'] . " records\n";
    }
    
    // Summary
    echo "\n" . str_repeat('=', 60) . "\n";
    echo "📋 INITIAL DATA IMPORT SUMMARY\n";
    echo str_repeat('=', 60) . "\n";
    
    $final_inventory = $pdo->query("SELECT COUNT(*) as count FROM inventory")->fetch();
    $final_metrics = $pdo->query("SELECT COUNT(*) as count FROM warehouse_sales_metrics")->fetch();
    $clustered_warehouses = $pdo->query("
        SELECT COUNT(DISTINCT warehouse_name) as count 
        FROM inventory 
        WHERE cluster IS NOT NULL
    ")->fetch();
    
    echo "✅ Inventory records: " . $final_inventory['count'] . "\n";
    echo "✅ Warehouse metrics: " . $final_metrics['count'] . "\n";
    echo "✅ Warehouses with clusters: " . $clustered_warehouses['count'] . "\n";
    echo "✅ Warehouse records updated: $updated_warehouses\n";
    echo "✅ Metrics refreshed: $refreshed_count\n";
    
    echo "\n🎯 Next Steps:\n";
    echo "1. Set up hourly cron job to refresh metrics\n";
    echo "2. Test warehouse dashboard API endpoints\n";
    echo "3. Deploy frontend to production\n";
    
    echo "\n🎉 Initial data import completed successfully!\n";
    
} catch (Exception $e) {
    echo "❌ Data import failed: " . $e->getMessage() . "\n";
    exit(1);
}

echo "\n";