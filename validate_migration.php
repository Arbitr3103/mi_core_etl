<?php
/**
 * Validate visibility migration completion
 * Task: 1.2 Execute schema migration with backup - Validation
 */

require_once __DIR__ . '/config.php';

try {
    echo "🔍 Validating visibility migration...\n\n";
    
    // Get database connection
    $pdo = getDatabaseConnection();
    
    // 1. Check if visibility column exists
    echo "1️⃣ Checking visibility column...\n";
    $stmt = $pdo->query("
        SELECT column_name, data_type, is_nullable, column_default
        FROM information_schema.columns 
        WHERE table_name = 'dim_products' 
        AND column_name = 'visibility'
        AND table_schema = 'public'
    ");
    
    $column = $stmt->fetch();
    if ($column) {
        echo "   ✅ Visibility column exists\n";
        echo "   📋 Type: {$column['data_type']}\n";
        echo "   📋 Nullable: {$column['is_nullable']}\n\n";
    } else {
        echo "   ❌ Visibility column not found!\n\n";
        exit(1);
    }
    
    // 2. Check if index exists
    echo "2️⃣ Checking visibility index...\n";
    $stmt = $pdo->query("
        SELECT indexname, tablename, indexdef
        FROM pg_indexes 
        WHERE tablename = 'dim_products' 
        AND indexname = 'idx_dim_products_visibility'
    ");
    
    $index = $stmt->fetch();
    if ($index) {
        echo "   ✅ Visibility index exists\n";
        echo "   📋 Definition: {$index['indexdef']}\n\n";
    } else {
        echo "   ❌ Visibility index not found!\n\n";
        exit(1);
    }
    
    // 3. Show complete table structure
    echo "3️⃣ Complete dim_products table structure:\n";
    $stmt = $pdo->query("
        SELECT 
            column_name,
            data_type,
            character_maximum_length,
            is_nullable,
            column_default
        FROM information_schema.columns 
        WHERE table_name = 'dim_products' 
        AND table_schema = 'public'
        ORDER BY ordinal_position
    ");
    
    $columns = $stmt->fetchAll();
    foreach ($columns as $col) {
        $length = $col['character_maximum_length'] ? "({$col['character_maximum_length']})" : "";
        $nullable = $col['is_nullable'] === 'YES' ? 'NULL' : 'NOT NULL';
        $default = $col['column_default'] ? " DEFAULT {$col['column_default']}" : "";
        
        echo "   📋 {$col['column_name']}: {$col['data_type']}{$length} {$nullable}{$default}\n";
    }
    
    // 4. Check migration log
    echo "\n4️⃣ Checking migration log...\n";
    $stmt = $pdo->query("
        SELECT * FROM etl_execution_log 
        WHERE etl_class = 'Migration_008_AddVisibilityToDimProducts'
        ORDER BY started_at DESC 
        LIMIT 1
    ");
    
    $log = $stmt->fetch();
    if ($log) {
        echo "   ✅ Migration logged successfully\n";
        echo "   📋 Status: {$log['status']}\n";
        echo "   📋 Started: {$log['started_at']}\n";
        echo "   📋 Completed: {$log['completed_at']}\n\n";
    } else {
        echo "   ⚠️ Migration log not found\n\n";
    }
    
    // 5. Test inserting a record with visibility
    echo "5️⃣ Testing visibility field functionality...\n";
    
    // Insert test record
    $testOfferId = 'TEST_VISIBILITY_' . time();
    $stmt = $pdo->prepare("
        INSERT INTO dim_products (product_id, offer_id, name, visibility) 
        VALUES (?, ?, ?, ?)
        ON CONFLICT (offer_id) DO UPDATE SET 
            visibility = EXCLUDED.visibility,
            updated_at = NOW()
    ");
    
    $stmt->execute([999999, $testOfferId, 'Test Product for Visibility', 'VISIBLE']);
    echo "   ✅ Test record inserted with visibility\n";
    
    // Query test record
    $stmt = $pdo->prepare("SELECT * FROM dim_products WHERE offer_id = ?");
    $stmt->execute([$testOfferId]);
    $testRecord = $stmt->fetch();
    
    if ($testRecord && $testRecord['visibility'] === 'VISIBLE') {
        echo "   ✅ Visibility field working correctly\n";
        echo "   📋 Test record visibility: {$testRecord['visibility']}\n";
    } else {
        echo "   ❌ Visibility field not working properly\n";
        exit(1);
    }
    
    // Clean up test record
    $stmt = $pdo->prepare("DELETE FROM dim_products WHERE offer_id = ?");
    $stmt->execute([$testOfferId]);
    echo "   🧹 Test record cleaned up\n\n";
    
    echo "🎉 Migration validation completed successfully!\n";
    echo "📝 The visibility field is ready for use in ProductETL refactoring.\n";
    
} catch (Exception $e) {
    echo "❌ Validation failed: " . $e->getMessage() . "\n";
    exit(1);
}
?>