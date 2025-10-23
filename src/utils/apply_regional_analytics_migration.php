<?php
/**
 * Apply Regional Analytics Migration
 * Creates database schema for regional sales analytics system
 */

require_once __DIR__ . '/config.php';

function runMigration($pdo, $migrationFile, $description) {
    echo "🔄 Running migration: $description\n";
    
    if (!file_exists($migrationFile)) {
        throw new Exception("Migration file not found: $migrationFile");
    }
    
    $sql = file_get_contents($migrationFile);
    if ($sql === false) {
        throw new Exception("Could not read migration file: $migrationFile");
    }
    
    // Split SQL into individual statements, handling multi-line statements
    $statements = [];
    $currentStatement = '';
    $lines = explode("\n", $sql);
    
    foreach ($lines as $line) {
        $line = trim($line);
        
        // Skip empty lines and comments
        if (empty($line) || preg_match('/^\s*--/', $line)) {
            continue;
        }
        
        $currentStatement .= $line . "\n";
        
        // Check if statement ends with semicolon
        if (preg_match('/;\s*$/', $line)) {
            $statement = trim($currentStatement);
            if (!empty($statement)) {
                $statements[] = $statement;
            }
            $currentStatement = '';
        }
    }
    
    // Add any remaining statement
    if (!empty(trim($currentStatement))) {
        $statements[] = trim($currentStatement);
    }
    
    $executedStatements = 0;
    foreach ($statements as $statement) {
        if (trim($statement)) {
            try {
                $pdo->exec($statement);
                $executedStatements++;
            } catch (PDOException $e) {
                // Check if it's a "table already exists" error, which we can ignore
                if (strpos($e->getMessage(), 'already exists') === false) {
                    throw new Exception("Error executing statement: " . $e->getMessage() . "\nStatement: " . substr($statement, 0, 200) . "...");
                }
            }
        }
    }
    
    echo "✅ Migration completed successfully ($executedStatements statements executed)\n\n";
}

function validateMigration($pdo, $validationFile) {
    echo "🔍 Validating migration...\n";
    
    if (!file_exists($validationFile)) {
        echo "⚠️  Validation file not found: $validationFile\n";
        return;
    }
    
    $sql = file_get_contents($validationFile);
    if ($sql === false) {
        echo "⚠️  Could not read validation file: $validationFile\n";
        return;
    }
    
    // Split SQL into individual statements
    $statements = array_filter(
        array_map('trim', explode(';', $sql)),
        function($stmt) {
            return !empty($stmt) && !preg_match('/^\s*--/', $stmt) && stripos($stmt, 'SELECT') !== false;
        }
    );
    
    foreach ($statements as $statement) {
        if (trim($statement)) {
            try {
                $stmt = $pdo->query($statement);
                $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
                
                if (!empty($results)) {
                    echo "📊 Validation results:\n";
                    foreach ($results as $row) {
                        echo "   " . implode(' | ', $row) . "\n";
                    }
                    echo "\n";
                }
            } catch (PDOException $e) {
                echo "⚠️  Validation query failed: " . $e->getMessage() . "\n";
            }
        }
    }
    
    echo "✅ Validation completed\n\n";
}

try {
    echo "🚀 Regional Analytics Migration Runner\n";
    echo "=====================================\n\n";
    
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
    
    // Check if dim_products table exists (required for foreign key)
    $stmt = $pdo->query("SHOW TABLES LIKE 'dim_products'");
    if ($stmt->rowCount() === 0) {
        echo "⚠️  Warning: dim_products table does not exist. Foreign key constraint will fail.\n";
        echo "   Please ensure dim_products table is created first.\n\n";
    }
    
    // Run the main migration
    runMigration(
        $pdo, 
        __DIR__ . '/migrations/add_regional_analytics_schema.sql',
        'Regional Analytics Schema Creation'
    );
    
    // Validate the migration
    validateMigration($pdo, __DIR__ . '/migrations/validate_regional_analytics_schema.sql');
    
    echo "🎉 Regional Analytics migration completed successfully!\n";
    echo "\nNext steps:\n";
    echo "1. Verify the tables were created correctly\n";
    echo "2. Test the API endpoints with the new schema\n";
    echo "3. Begin implementing the SalesAnalyticsService class\n\n";
    
    echo "📋 Created tables:\n";
    echo "   - ozon_regional_sales (for Ozon API data)\n";
    echo "   - regions (regional reference data)\n";
    echo "   - regional_analytics_cache (performance optimization)\n\n";
    
    echo "📋 Created views:\n";
    echo "   - v_regional_sales_summary\n";
    echo "   - v_marketplace_comparison\n\n";
    
} catch (Exception $e) {
    echo "❌ Migration failed: " . $e->getMessage() . "\n";
    echo "\nTo rollback this migration, run:\n";
    echo "php -f rollback_regional_analytics_migration.php\n";
    exit(1);
}
?>