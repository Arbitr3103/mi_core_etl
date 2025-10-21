<?php
/**
 * Rollback Regional Analytics Migration
 * Removes database schema for regional sales analytics system
 */

require_once __DIR__ . '/config.php';

function runRollback($pdo, $rollbackFile, $description) {
    echo "🔄 Running rollback: $description\n";
    
    if (!file_exists($rollbackFile)) {
        throw new Exception("Rollback file not found: $rollbackFile");
    }
    
    $sql = file_get_contents($rollbackFile);
    if ($sql === false) {
        throw new Exception("Could not read rollback file: $rollbackFile");
    }
    
    // Split SQL into individual statements
    $statements = array_filter(
        array_map('trim', explode(';', $sql)),
        function($stmt) {
            return !empty($stmt) && !preg_match('/^\s*--/', $stmt);
        }
    );
    
    $executedStatements = 0;
    foreach ($statements as $statement) {
        if (trim($statement)) {
            try {
                $pdo->exec($statement);
                $executedStatements++;
            } catch (PDOException $e) {
                // Check if it's a "table doesn't exist" error, which we can ignore during rollback
                if (strpos($e->getMessage(), "doesn't exist") === false && 
                    strpos($e->getMessage(), 'Unknown table') === false) {
                    throw new Exception("Error executing rollback statement: " . $e->getMessage() . "\nStatement: " . substr($statement, 0, 200) . "...");
                }
            }
        }
    }
    
    echo "✅ Rollback completed successfully ($executedStatements statements executed)\n\n";
}

try {
    echo "🔄 Regional Analytics Migration Rollback\n";
    echo "=======================================\n\n";
    
    // Confirm rollback
    echo "⚠️  WARNING: This will permanently delete all regional analytics data!\n";
    echo "Are you sure you want to proceed? (yes/no): ";
    $handle = fopen("php://stdin", "r");
    $confirmation = trim(fgets($handle));
    fclose($handle);
    
    if (strtolower($confirmation) !== 'yes') {
        echo "❌ Rollback cancelled.\n";
        exit(0);
    }
    
    // Connect to database
    $pdo = new PDO(
        "mysql:host=" . DB_HOST . ";port=" . DB_PORT . ";dbname=" . DB_NAME . ";charset=utf8mb4",
        DB_USER,
        DB_PASSWORD,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
        ]
    );
    
    echo "✅ Connected to database: " . DB_NAME . "\n\n";
    
    // Check what tables exist before rollback
    echo "📋 Checking existing tables...\n";
    $tables = ['ozon_regional_sales', 'regions', 'regional_analytics_cache'];
    $existingTables = [];
    
    foreach ($tables as $table) {
        $stmt = $pdo->query("SHOW TABLES LIKE '$table'");
        if ($stmt->rowCount() > 0) {
            $existingTables[] = $table;
            echo "   ✓ $table exists\n";
        } else {
            echo "   - $table does not exist\n";
        }
    }
    
    if (empty($existingTables)) {
        echo "\n✅ No regional analytics tables found. Nothing to rollback.\n";
        exit(0);
    }
    
    echo "\n";
    
    // Run the rollback migration
    runRollback(
        $pdo, 
        __DIR__ . '/migrations/rollback_regional_analytics_schema.sql',
        'Regional Analytics Schema Removal'
    );
    
    // Verify rollback
    echo "🔍 Verifying rollback...\n";
    $remainingTables = [];
    
    foreach ($tables as $table) {
        $stmt = $pdo->query("SHOW TABLES LIKE '$table'");
        if ($stmt->rowCount() > 0) {
            $remainingTables[] = $table;
            echo "   ⚠️  $table still exists\n";
        } else {
            echo "   ✓ $table removed\n";
        }
    }
    
    if (empty($remainingTables)) {
        echo "\n✅ Regional Analytics migration rollback completed successfully!\n";
        echo "All tables and views have been removed.\n";
    } else {
        echo "\n⚠️  Rollback completed with warnings. Some tables may still exist:\n";
        foreach ($remainingTables as $table) {
            echo "   - $table\n";
        }
    }
    
} catch (Exception $e) {
    echo "❌ Rollback failed: " . $e->getMessage() . "\n";
    exit(1);
}
?>