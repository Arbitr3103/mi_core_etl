<?php
require_once __DIR__ . '/config.php';

try {
    $pdo = new PDO(
        "mysql:host=" . DB_HOST . ";port=" . DB_PORT . ";dbname=" . DB_NAME . ";charset=utf8mb4",
        DB_USER,
        DB_PASSWORD,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
    
    echo "🔍 Searching for sales/orders data in all tables...\n\n";
    
    // Get all tables
    $stmt = $pdo->query("SHOW TABLES");
    $tables = $stmt->fetchAll(PDO::FETCH_COLUMN);
    
    echo "Total tables: " . count($tables) . "\n\n";
    
    // Check each table for sales-related columns
    foreach ($tables as $table) {
        echo "📋 Checking table: $table\n";
        
        try {
            // Get table structure
            $stmt = $pdo->query("DESCRIBE $table");
            $columns = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            $salesColumns = [];
            foreach ($columns as $column) {
                $field = strtolower($column['Field']);
                if (strpos($field, 'order') !== false || 
                    strpos($field, 'sale') !== false ||
                    strpos($field, 'quantity') !== false ||
                    strpos($field, 'revenue') !== false ||
                    strpos($field, 'price') !== false ||
                    strpos($field, 'amount') !== false) {
                    $salesColumns[] = $column['Field'];
                }
            }
            
            if (!empty($salesColumns)) {
                echo "   🎯 Found sales-related columns: " . implode(', ', $salesColumns) . "\n";
                
                // Get record count
                $stmt = $pdo->query("SELECT COUNT(*) as count FROM $table");
                $count = $stmt->fetch(PDO::FETCH_ASSOC)['count'];
                echo "   📊 Records: $count\n";
                
                if ($count > 0) {
                    // Show sample data
                    $stmt = $pdo->query("SELECT * FROM $table LIMIT 2");
                    $samples = $stmt->fetchAll(PDO::FETCH_ASSOC);
                    
                    echo "   📄 Sample data:\n";
                    foreach ($samples as $i => $sample) {
                        echo "      Record " . ($i + 1) . ":\n";
                        foreach ($sample as $key => $value) {
                            if (strlen($value) > 50) {
                                $value = substr($value, 0, 50) . '...';
                            }
                            echo "        $key: $value\n";
                        }
                        echo "\n";
                    }
                }
            }
            
        } catch (Exception $e) {
            echo "   ❌ Error checking table: " . $e->getMessage() . "\n";
        }
        
        echo "\n";
    }
    
    // Also check for any tables with 'fact' prefix (common in data warehouses)
    echo "🔍 Looking specifically for fact tables...\n";
    $factTables = array_filter($tables, function($table) {
        return stripos($table, 'fact') === 0;
    });
    
    if (!empty($factTables)) {
        foreach ($factTables as $table) {
            echo "📊 Fact table: $table\n";
        }
    } else {
        echo "No fact tables found\n";
    }
    
    // Check for any tables with transaction data
    echo "\n🔍 Looking for transaction-related tables...\n";
    $transactionTables = array_filter($tables, function($table) {
        return stripos($table, 'transaction') !== false ||
               stripos($table, 'movement') !== false ||
               stripos($table, 'history') !== false;
    });
    
    if (!empty($transactionTables)) {
        foreach ($transactionTables as $table) {
            echo "💳 Transaction table: $table\n";
        }
    } else {
        echo "No transaction tables found\n";
    }
    
} catch (Exception $e) {
    echo "❌ Error: " . $e->getMessage() . "\n";
}
?>