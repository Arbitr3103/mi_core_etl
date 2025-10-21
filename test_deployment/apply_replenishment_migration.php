<?php
/**
 * Apply Replenishment Schema Migration
 * 
 * This script applies the database schema for the inventory replenishment
 * recommendation system. It creates all necessary tables, indexes, views,
 * and stored procedures.
 */

require_once __DIR__ . '/config.php';

function applyReplenishmentMigration() {
    try {
        // Get database connection
        $pdo = getReplenishmentMigrationConnection();
        
        echo "🚀 Starting replenishment schema migration...\n";
        echo "Database: " . DB_NAME . "\n";
        echo "Host: " . DB_HOST . "\n\n";
        
        // Read migration SQL file
        $migrationFile = __DIR__ . '/migrations/create_replenishment_schema.sql';
        if (!file_exists($migrationFile)) {
            throw new Exception("Migration file not found: $migrationFile");
        }
        
        $sql = file_get_contents($migrationFile);
        if ($sql === false) {
            throw new Exception("Failed to read migration file");
        }
        
        echo "📄 Migration file loaded: " . basename($migrationFile) . "\n";
        echo "📏 SQL size: " . number_format(strlen($sql)) . " bytes\n\n";
        
        // Split SQL into individual statements
        $statements = explode(';', $sql);
        $executedStatements = 0;
        $skippedStatements = 0;
        
        // Execute each statement
        foreach ($statements as $index => $statement) {
            $statement = trim($statement);
            
            // Skip empty statements and comments
            if (empty($statement) || 
                strpos($statement, '--') === 0 || 
                strpos($statement, '/*') === 0) {
                $skippedStatements++;
                continue;
            }
            
            try {
                echo "⚡ Executing statement " . ($executedStatements + 1) . "...\n";
                
                // Show first 100 characters of statement for debugging
                $preview = substr(str_replace(["\n", "\r", "\t"], ' ', $statement), 0, 100);
                echo "   Preview: " . $preview . (strlen($statement) > 100 ? '...' : '') . "\n";
                
                $result = $pdo->exec($statement);
                
                if ($result !== false) {
                    echo "   ✅ Success";
                    if ($result > 0) {
                        echo " (affected rows: $result)";
                    }
                    echo "\n";
                } else {
                    echo "   ✅ Success (no rows affected)\n";
                }
                
                $executedStatements++;
                
            } catch (PDOException $e) {
                // Check if error is about existing table/view (which is OK)
                $errorCode = $e->getCode();
                $errorMessage = $e->getMessage();
                
                if (strpos($errorMessage, 'already exists') !== false ||
                    strpos($errorMessage, 'Duplicate key name') !== false ||
                    strpos($errorMessage, 'Duplicate entry') !== false) {
                    echo "   ⚠️  Warning: " . $errorMessage . "\n";
                    $executedStatements++;
                } else {
                    echo "   ❌ Error: " . $errorMessage . "\n";
                    echo "   Statement: " . substr($statement, 0, 200) . "...\n";
                    throw $e;
                }
            }
            
            echo "\n";
        }
        
        echo "📊 Migration Summary:\n";
        echo "   Executed statements: $executedStatements\n";
        echo "   Skipped statements: $skippedStatements\n";
        echo "   Total statements: " . count($statements) . "\n\n";
        
        // Verify created tables
        echo "🔍 Verifying created tables...\n";
        $tables = ['replenishment_recommendations', 'replenishment_config', 
                  'replenishment_calculations', 'replenishment_calculation_details'];
        
        foreach ($tables as $table) {
            $stmt = $pdo->query("SHOW TABLES LIKE '$table'");
            if ($stmt->rowCount() > 0) {
                echo "   ✅ Table '$table' exists\n";
                
                // Show row count
                $countStmt = $pdo->query("SELECT COUNT(*) as count FROM $table");
                $count = $countStmt->fetch(PDO::FETCH_ASSOC)['count'];
                echo "      Rows: $count\n";
            } else {
                echo "   ❌ Table '$table' NOT found\n";
            }
        }
        
        echo "\n";
        
        // Verify views
        echo "🔍 Verifying created views...\n";
        $views = ['v_latest_replenishment_recommendations', 'v_replenishment_config'];
        
        foreach ($views as $view) {
            $stmt = $pdo->query("SHOW TABLES LIKE '$view'");
            if ($stmt->rowCount() > 0) {
                echo "   ✅ View '$view' exists\n";
            } else {
                echo "   ❌ View '$view' NOT found\n";
            }
        }
        
        echo "\n";
        
        // Show configuration parameters
        echo "⚙️  Configuration parameters:\n";
        $configStmt = $pdo->query("SELECT parameter_name, parameter_value, description FROM replenishment_config ORDER BY parameter_name");
        while ($config = $configStmt->fetch(PDO::FETCH_ASSOC)) {
            echo "   • {$config['parameter_name']}: {$config['parameter_value']}\n";
            echo "     {$config['description']}\n";
        }
        
        echo "\n🎉 Migration completed successfully!\n";
        echo "✨ Replenishment recommendation system is ready to use.\n\n";
        
        // Show next steps
        echo "📋 Next steps:\n";
        echo "1. Implement SalesAnalyzer class\n";
        echo "2. Implement StockCalculator class\n";
        echo "3. Create ReplenishmentRecommender class\n";
        echo "4. Build API endpoints\n";
        echo "5. Create dashboard interface\n\n";
        
        return true;
        
    } catch (Exception $e) {
        echo "❌ Migration failed: " . $e->getMessage() . "\n";
        echo "📍 File: " . $e->getFile() . "\n";
        echo "📍 Line: " . $e->getLine() . "\n";
        
        if ($e instanceof PDOException) {
            echo "🔍 SQL Error Code: " . $e->getCode() . "\n";
        }
        
        return false;
    }
}

// Helper function to get database connection for migration
function getReplenishmentMigrationConnection() {
    try {
        $dsn = "mysql:host=" . DB_HOST . ";port=" . DB_PORT . ";dbname=" . DB_NAME . ";charset=utf8mb4";
        $options = [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci"
        ];
        
        $pdo = new PDO($dsn, DB_USER, DB_PASSWORD, $options);
        
        // Test connection
        $pdo->query("SELECT 1");
        
        return $pdo;
        
    } catch (PDOException $e) {
        throw new Exception("Database connection failed: " . $e->getMessage());
    }
}

// Run migration if script is executed directly
if (basename(__FILE__) === basename($_SERVER['SCRIPT_NAME'])) {
    echo "🔧 Replenishment Schema Migration Tool\n";
    echo "=====================================\n\n";
    
    $success = applyReplenishmentMigration();
    
    if ($success) {
        echo "✅ Migration completed successfully!\n";
        exit(0);
    } else {
        echo "❌ Migration failed!\n";
        exit(1);
    }
}
?>