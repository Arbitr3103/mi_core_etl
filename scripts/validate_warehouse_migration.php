<?php
/**
 * Validate Warehouse Dashboard Migration
 * 
 * Simple PHP script to validate the warehouse dashboard schema migration
 */

require_once __DIR__ . '/../config/database_postgresql.php';

echo "🔍 Validating Warehouse Dashboard Migration\n";
echo str_repeat('=', 60) . "\n";

try {
    $pdo = getDatabaseConnection();
    
    // Test 1: Check inventory table extensions
    echo "📊 Test 1: Checking inventory table extensions...\n";
    
    $expected_columns = [
        'preparing_for_sale', 'in_supply_requests', 'in_transit',
        'in_inspection', 'returning_from_customers', 'expiring_soon',
        'defective', 'excess_from_supply', 'awaiting_upd',
        'preparing_for_removal', 'cluster'
    ];
    
    $found_columns = [];
    foreach ($expected_columns as $column) {
        $result = $pdo->query("
            SELECT column_name 
            FROM information_schema.columns
            WHERE table_schema = 'public'
                AND table_name = 'inventory'
                AND column_name = '$column'
        ")->fetch();
        
        if ($result) {
            $found_columns[] = $column;
            echo "  ✅ $column\n";
        } else {
            echo "  ❌ $column (missing)\n";
        }
    }
    
    echo "  📋 Found " . count($found_columns) . "/" . count($expected_columns) . " columns\n";
    
    // Test 2: Check warehouse_sales_metrics table
    echo "\n📊 Test 2: Checking warehouse_sales_metrics table...\n";
    
    $table_check = $pdo->query("
        SELECT table_name 
        FROM information_schema.tables
        WHERE table_schema = 'public'
            AND table_name = 'warehouse_sales_metrics'
    ")->fetch();
    
    if ($table_check) {
        echo "  ✅ warehouse_sales_metrics table exists\n";
        
        // Check record count
        $count = $pdo->query("SELECT COUNT(*) as count FROM warehouse_sales_metrics")->fetch();
        echo "  📊 Records in table: " . $count['count'] . "\n";
    } else {
        echo "  ❌ warehouse_sales_metrics table missing\n";
    }
    
    // Test 3: Check SQL functions
    echo "\n📊 Test 3: Checking SQL functions...\n";
    
    $expected_functions = [
        'calculate_daily_sales_avg',
        'calculate_days_of_stock', 
        'determine_liquidity_status',
        'refresh_warehouse_metrics_for_product'
    ];
    
    $found_functions = [];
    foreach ($expected_functions as $function) {
        $result = $pdo->query("
            SELECT routine_name 
            FROM information_schema.routines
            WHERE routine_schema = 'public'
                AND routine_name = '$function'
        ")->fetch();
        
        if ($result) {
            $found_functions[] = $function;
            echo "  ✅ $function\n";
        } else {
            echo "  ❌ $function (missing)\n";
        }
    }
    
    echo "  📋 Found " . count($found_functions) . "/" . count($expected_functions) . " functions\n";
    
    // Test 4: Test function functionality
    echo "\n📊 Test 4: Testing function functionality...\n";
    
    try {
        // Test calculate_days_of_stock
        $result = $pdo->query("SELECT calculate_days_of_stock(100, 5.5) as days")->fetch();
        echo "  ✅ calculate_days_of_stock(100, 5.5) = " . $result['days'] . " days\n";
        
        // Test determine_liquidity_status
        $result = $pdo->query("SELECT determine_liquidity_status(5) as status")->fetch();
        echo "  ✅ determine_liquidity_status(5) = " . $result['status'] . "\n";
        
        $result = $pdo->query("SELECT determine_liquidity_status(20) as status")->fetch();
        echo "  ✅ determine_liquidity_status(20) = " . $result['status'] . "\n";
        
    } catch (Exception $e) {
        echo "  ❌ Function test failed: " . $e->getMessage() . "\n";
    }
    
    // Test 5: Check indexes
    echo "\n📊 Test 5: Checking indexes...\n";
    
    $indexes = $pdo->query("
        SELECT indexname
        FROM pg_indexes
        WHERE schemaname = 'public'
            AND tablename = 'warehouse_sales_metrics'
        ORDER BY indexname
    ")->fetchAll();
    
    echo "  📋 Found " . count($indexes) . " indexes on warehouse_sales_metrics:\n";
    foreach ($indexes as $index) {
        echo "    - " . $index['indexname'] . "\n";
    }
    
    // Summary
    echo "\n" . str_repeat('=', 60) . "\n";
    echo "📋 MIGRATION VALIDATION SUMMARY\n";
    echo str_repeat('=', 60) . "\n";
    
    $all_good = true;
    
    if (count($found_columns) == count($expected_columns)) {
        echo "✅ Inventory columns: " . count($found_columns) . "/" . count($expected_columns) . "\n";
    } else {
        echo "❌ Inventory columns: " . count($found_columns) . "/" . count($expected_columns) . "\n";
        $all_good = false;
    }
    
    if ($table_check) {
        echo "✅ Warehouse metrics table: exists\n";
    } else {
        echo "❌ Warehouse metrics table: missing\n";
        $all_good = false;
    }
    
    if (count($found_functions) == count($expected_functions)) {
        echo "✅ SQL functions: " . count($found_functions) . "/" . count($expected_functions) . "\n";
    } else {
        echo "❌ SQL functions: " . count($found_functions) . "/" . count($expected_functions) . "\n";
        $all_good = false;
    }
    
    if (count($indexes) >= 6) {
        echo "✅ Indexes: " . count($indexes) . " (expected 6+)\n";
    } else {
        echo "⚠️ Indexes: " . count($indexes) . " (expected 6+)\n";
    }
    
    echo "\n";
    
    if ($all_good) {
        echo "🎉 ALL MIGRATION VALIDATIONS PASSED!\n";
        echo "✅ Database is ready for warehouse dashboard production deployment\n";
    } else {
        echo "❌ SOME VALIDATIONS FAILED\n";
        echo "⚠️ Migration may need to be re-run\n";
    }
    
} catch (Exception $e) {
    echo "❌ Validation failed: " . $e->getMessage() . "\n";
    exit(1);
}

echo "\n";