<?php
/**
 * Apply Ozon Stock Reports Migrations
 * 
 * This script applies all necessary database migrations for the Ozon warehouse stock reports system
 * in the correct order to handle foreign key dependencies.
 */

require_once __DIR__ . '/../utils/Database.php';

function applyMigrations() {
    try {
        $db = Database::getInstance();
        $pdo = $db->getConnection();
        
        echo "🚀 Starting Ozon Stock Reports migrations...\n\n";
        
        // Migration files in dependency order
        $migrations = [
            'create_ozon_stock_reports_table.sql',
            'create_ozon_etl_history_table.sql', 
            'create_ozon_report_monitoring_table.sql',
            'create_stock_report_logs_table.sql'
        ];
        
        foreach ($migrations as $migrationFile) {
            $filePath = __DIR__ . '/' . $migrationFile;
            
            if (!file_exists($filePath)) {
                throw new Exception("Migration file not found: $migrationFile");
            }
            
            echo "📄 Applying migration: $migrationFile\n";
            
            $sql = file_get_contents($filePath);
            
            // Execute the migration
            $pdo->exec($sql);
            
            echo "✅ Migration applied successfully: $migrationFile\n\n";
        }
        
        echo "🎉 All Ozon Stock Reports migrations applied successfully!\n";
        
        // Verify tables were created
        echo "\n📊 Verifying created tables:\n";
        $tables = ['ozon_stock_reports', 'ozon_etl_history', 'ozon_report_monitoring', 'stock_report_logs'];
        
        foreach ($tables as $table) {
            $exists = $db->tableExists($table);
            $status = $exists ? '✅' : '❌';
            echo "$status Table: $table\n";
        }
        
        return true;
        
    } catch (Exception $e) {
        echo "❌ Migration failed: " . $e->getMessage() . "\n";
        echo "Stack trace:\n" . $e->getTraceAsString() . "\n";
        return false;
    }
}

// Run migrations if script is executed directly
if (basename(__FILE__) === basename($_SERVER['SCRIPT_NAME'])) {
    $success = applyMigrations();
    exit($success ? 0 : 1);
}