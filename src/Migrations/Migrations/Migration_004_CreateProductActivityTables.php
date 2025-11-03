<?php

namespace MDM\Migrations\Migrations;

use MDM\Migrations\BaseMigration;
use PDO;

/**
 * Migration 004: Create Product Activity Tables
 * 
 * Creates tables for tracking product activity status and changes.
 */
class Migration_004_CreateProductActivityTables extends BaseMigration
{
    public function __construct()
    {
        parent::__construct(
            version: '004_create_product_activity_tables',
            description: 'Create product activity tracking tables with logging capabilities'
        );
    }

    public function up(PDO $pdo): bool
    {
        $this->log("Creating product activity tracking tables...");

        // Add activity columns to products table if they don't exist
        $this->addActivityColumnsToProducts($pdo);

        // Create product_activity_log table
        $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        
        if ($driver === 'sqlite') {
            $sql = "CREATE TABLE IF NOT EXISTS product_activity_log (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                product_id VARCHAR(255) NOT NULL,
                external_sku VARCHAR(255) NOT NULL,
                previous_status BOOLEAN,
                new_status BOOLEAN NOT NULL,
                reason VARCHAR(500) NOT NULL,
                changed_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                changed_by VARCHAR(100),
                metadata TEXT
            )";
        } elseif ($driver === 'pgsql') {
            $sql = "CREATE TABLE IF NOT EXISTS product_activity_log (
                id SERIAL PRIMARY KEY,
                product_id VARCHAR(255) NOT NULL,
                external_sku VARCHAR(255) NOT NULL,
                previous_status BOOLEAN NULL,
                new_status BOOLEAN NOT NULL,
                reason VARCHAR(500) NOT NULL,
                changed_at TIMESTAMP DEFAULT NOW(),
                changed_by VARCHAR(100) NULL,
                metadata JSONB NULL
            )";
        } else {
            $sql = "CREATE TABLE IF NOT EXISTS product_activity_log (
                id INT PRIMARY KEY AUTO_INCREMENT COMMENT 'Auto-increment ID',
                product_id VARCHAR(255) NOT NULL COMMENT 'Product ID or external SKU',
                external_sku VARCHAR(255) NOT NULL COMMENT 'External SKU from marketplace',
                previous_status BOOLEAN NULL COMMENT 'Previous activity status (NULL for initial check)',
                new_status BOOLEAN NOT NULL COMMENT 'New activity status',
                reason VARCHAR(500) NOT NULL COMMENT 'Reason for status change',
                changed_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP COMMENT 'When the change occurred',
                changed_by VARCHAR(100) NULL COMMENT 'User or system that made the change',
                metadata JSON NULL COMMENT 'Additional metadata about the change',
                
                INDEX idx_product_id (product_id),
                INDEX idx_external_sku (external_sku),
                INDEX idx_changed_at (changed_at),
                INDEX idx_changed_by (changed_by),
                INDEX idx_new_status (new_status),
                INDEX idx_product_changed_at (product_id, changed_at),
                INDEX idx_status_change (previous_status, new_status),
                
                FULLTEXT KEY ft_reason (reason)
            ) ENGINE=InnoDB 
            COMMENT='Log of all product activity status changes'";
        }

        $this->executeSql($pdo, $sql);
        $this->log("Created product_activity_log table");

        // Create indexes for better performance
        $this->createOptimizedIndexes($pdo);

        $this->log("Product activity tracking tables created successfully");
        return true;
    }

    public function down(PDO $pdo): bool
    {
        $this->log("Rolling back product activity tracking tables...");

        // Drop product_activity_log table
        $sql = "DROP TABLE IF EXISTS product_activity_log";
        $this->executeSql($pdo, $sql);
        $this->log("Dropped product_activity_log table");

        // Remove activity columns from products table
        $this->removeActivityColumnsFromProducts($pdo);

        $this->log("Product activity tracking tables rollback completed");
        return true;
    }

    /**
     * Add activity tracking columns to products table
     */
    private function addActivityColumnsToProducts(PDO $pdo): void
    {
        $this->log("Adding activity columns to products table...");

        // Check if products table exists
        if (!$this->tableExists($pdo, 'products')) {
            $this->log("Products table doesn't exist, creating it...");
            $this->createProductsTable($pdo);
        }

        // Add activity columns if they don't exist
        $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        
        if ($driver === 'sqlite') {
            $columns = [
                'is_active' => 'BOOLEAN DEFAULT 0',
                'activity_checked_at' => 'TIMESTAMP',
                'activity_reason' => 'VARCHAR(500)'
            ];
        } else {
            $columns = [
                'is_active' => 'BOOLEAN DEFAULT FALSE COMMENT "Whether the product is currently active"',
                'activity_checked_at' => 'TIMESTAMP NULL COMMENT "When activity status was last checked"',
                'activity_reason' => 'VARCHAR(500) NULL COMMENT "Reason for current activity status"'
            ];
        }

        foreach ($columns as $columnName => $columnDefinition) {
            if (!$this->columnExists($pdo, 'products', $columnName)) {
                $sql = "ALTER TABLE products ADD COLUMN {$columnName} {$columnDefinition}";
                $this->executeSql($pdo, $sql);
                $this->log("Added column {$columnName} to products table");
            } else {
                $this->log("Column {$columnName} already exists in products table");
            }
        }

        // Add indexes for activity columns (skip for SQLite as it doesn't support ALTER TABLE ADD INDEX)
        if ($driver !== 'sqlite') {
            $indexes = [
                'idx_is_active' => 'is_active',
                'idx_activity_checked_at' => 'activity_checked_at',
                'idx_active_checked' => 'is_active, activity_checked_at'
            ];

            foreach ($indexes as $indexName => $indexColumns) {
                if (!$this->indexExists($pdo, 'products', $indexName)) {
                    if ($driver === 'pgsql') {
                        $sql = "CREATE INDEX {$indexName} ON products ({$indexColumns})";
                    } else {
                        $sql = "ALTER TABLE products ADD INDEX {$indexName} ({$indexColumns})";
                    }
                    $this->executeSql($pdo, $sql);
                    $this->log("Added index {$indexName} to products table");
                }
            }
        } else {
            // For SQLite, create indexes separately
            $indexes = [
                'idx_is_active' => 'CREATE INDEX IF NOT EXISTS idx_is_active ON products (is_active)',
                'idx_activity_checked_at' => 'CREATE INDEX IF NOT EXISTS idx_activity_checked_at ON products (activity_checked_at)',
                'idx_active_checked' => 'CREATE INDEX IF NOT EXISTS idx_active_checked ON products (is_active, activity_checked_at)'
            ];

            foreach ($indexes as $indexName => $sql) {
                $this->executeSql($pdo, $sql);
                $this->log("Added index {$indexName} to products table");
            }
        }
    }

    /**
     * Remove activity tracking columns from products table
     */
    private function removeActivityColumnsFromProducts(PDO $pdo): void
    {
        $this->log("Removing activity columns from products table...");

        if (!$this->tableExists($pdo, 'products')) {
            $this->log("Products table doesn't exist, skipping column removal");
            return;
        }

        $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        
        if ($driver === 'sqlite') {
            // SQLite doesn't support DROP COLUMN easily, especially with indexes
            // For testing purposes, we'll recreate the table without the columns
            $this->log("SQLite detected - recreating table without activity columns");
            
            // Get existing data
            $backupSql = "CREATE TEMPORARY TABLE products_backup AS 
                         SELECT id, external_sku, name, brand, category, price, stock_quantity, marketplace, created_at, updated_at 
                         FROM products";
            $this->executeSql($pdo, $backupSql);
            
            // Drop original table
            $this->executeSql($pdo, "DROP TABLE products");
            
            // Recreate without activity columns
            $this->createProductsTable($pdo);
            
            // Restore data
            $restoreSql = "INSERT INTO products (id, external_sku, name, brand, category, price, stock_quantity, marketplace, created_at, updated_at)
                          SELECT id, external_sku, name, brand, category, price, stock_quantity, marketplace, created_at, updated_at 
                          FROM products_backup";
            $this->executeSql($pdo, $restoreSql);
            
            // Drop backup
            $this->executeSql($pdo, "DROP TABLE products_backup");
            
        } else {
            $columns = ['is_active', 'activity_checked_at', 'activity_reason'];

            foreach ($columns as $columnName) {
                if ($this->columnExists($pdo, 'products', $columnName)) {
                    $sql = "ALTER TABLE products DROP COLUMN {$columnName}";
                    $this->executeSql($pdo, $sql);
                    $this->log("Removed column {$columnName} from products table");
                }
            }
        }
    }

    /**
     * Create products table if it doesn't exist
     */
    private function createProductsTable(PDO $pdo): void
    {
        // Detect database type
        $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        
        if ($driver === 'sqlite') {
            $sql = "CREATE TABLE IF NOT EXISTS products (
                id VARCHAR(255) PRIMARY KEY,
                external_sku VARCHAR(255) NOT NULL UNIQUE,
                name VARCHAR(500),
                brand VARCHAR(200),
                category VARCHAR(200),
                price DECIMAL(10,2),
                stock_quantity INTEGER DEFAULT 0,
                marketplace VARCHAR(50),
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            )";
        } else {
            $sql = "CREATE TABLE IF NOT EXISTS products (
                id VARCHAR(255) PRIMARY KEY COMMENT 'Product ID',
                external_sku VARCHAR(255) NOT NULL COMMENT 'External SKU from marketplace',
                name VARCHAR(500) NULL COMMENT 'Product name',
                brand VARCHAR(200) NULL COMMENT 'Product brand',
                category VARCHAR(200) NULL COMMENT 'Product category',
                price DECIMAL(10,2) NULL COMMENT 'Product price',
                stock_quantity INT DEFAULT 0 COMMENT 'Stock quantity',
                marketplace VARCHAR(50) NULL COMMENT 'Source marketplace',
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP COMMENT 'Record creation time',
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT 'Last update time',
                
                UNIQUE KEY unique_external_sku (external_sku),
                INDEX idx_marketplace (marketplace),
                INDEX idx_created_at (created_at),
                INDEX idx_updated_at (updated_at),
                
                FULLTEXT KEY ft_search (name, brand)
            ) ENGINE=InnoDB 
            COMMENT='Products table with activity tracking support'";
        }

        $this->executeSql($pdo, $sql);
        $this->log("Created products table");
    }

    /**
     * Create optimized indexes for better query performance
     */
    private function createOptimizedIndexes(PDO $pdo): void
    {
        $this->log("Creating optimized indexes...");
        
        $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);

        if ($driver === 'sqlite') {
            // SQLite-compatible indexes
            $indexes = [
                'idx_product_recent_changes' => 'CREATE INDEX IF NOT EXISTS idx_product_recent_changes ON product_activity_log (product_id, changed_at)',
                'idx_status_changes_recent' => 'CREATE INDEX IF NOT EXISTS idx_status_changes_recent ON product_activity_log (previous_status, new_status, changed_at)',
                'idx_user_activity' => 'CREATE INDEX IF NOT EXISTS idx_user_activity ON product_activity_log (changed_by, changed_at)',
                'idx_product_id' => 'CREATE INDEX IF NOT EXISTS idx_product_id ON product_activity_log (product_id)',
                'idx_external_sku' => 'CREATE INDEX IF NOT EXISTS idx_external_sku ON product_activity_log (external_sku)',
                'idx_changed_at' => 'CREATE INDEX IF NOT EXISTS idx_changed_at ON product_activity_log (changed_at)',
                'idx_new_status' => 'CREATE INDEX IF NOT EXISTS idx_new_status ON product_activity_log (new_status)'
            ];

            foreach ($indexes as $indexName => $sql) {
                try {
                    $this->executeSql($pdo, $sql);
                    $this->log("Created index {$indexName}");
                } catch (\PDOException $e) {
                    $this->log("Warning: Could not create index {$indexName}: " . $e->getMessage());
                }
            }
        } else {
            // MySQL-compatible indexes
            $indexes = [
                // For finding recent changes by product
                'idx_product_recent_changes' => 'product_activity_log (product_id, changed_at DESC)',
                
                // For finding status changes (activations/deactivations)
                'idx_status_changes_recent' => 'product_activity_log (previous_status, new_status, changed_at DESC)',
                
                // For user activity tracking
                'idx_user_activity' => 'product_activity_log (changed_by, changed_at DESC)',
                
                // For daily/weekly reporting
                'idx_date_status_reporting' => 'product_activity_log (DATE(changed_at), new_status)'
            ];

            foreach ($indexes as $indexName => $indexDefinition) {
                $tableName = strpos($indexDefinition, '(') !== false ? 
                             substr($indexDefinition, 0, strpos($indexDefinition, ' (')) : 
                             $indexDefinition;
                
                $indexColumns = strpos($indexDefinition, '(') !== false ? 
                               substr($indexDefinition, strpos($indexDefinition, '(')) : 
                               '';

                if (!$this->indexExists($pdo, $tableName, $indexName)) {
                    $sql = "CREATE INDEX {$indexName} ON {$indexDefinition}";
                    try {
                        $this->executeSql($pdo, $sql);
                        $this->log("Created index {$indexName}");
                    } catch (\PDOException $e) {
                        $this->log("Warning: Could not create index {$indexName}: " . $e->getMessage());
                    }
                }
            }
        }
    }

    /**
     * Check if a column exists in a table
     */
    protected function columnExists(PDO $pdo, string $tableName, string $columnName): bool
    {
        $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        
        if ($driver === 'sqlite') {
            try {
                $pdo->query("SELECT {$columnName} FROM {$tableName} LIMIT 1");
                return true;
            } catch (\PDOException $e) {
                return false;
            }
        } elseif ($driver === 'pgsql') {
            $sql = "SELECT COUNT(*) FROM information_schema.columns 
                    WHERE table_schema = ANY(current_schemas(false))
                      AND table_name = :table_name 
                      AND column_name = :column_name";
            $stmt = $pdo->prepare($sql);
            $stmt->bindValue(':table_name', $tableName);
            $stmt->bindValue(':column_name', $columnName);
            $stmt->execute();
            return $stmt->fetchColumn() > 0;
        } else {
            $sql = "SELECT COUNT(*) FROM information_schema.columns 
                    WHERE table_schema = DATABASE() 
                    AND table_name = :table_name 
                    AND column_name = :column_name";
            $stmt = $pdo->prepare($sql);
            $stmt->bindValue(':table_name', $tableName);
            $stmt->bindValue(':column_name', $columnName);
            $stmt->execute();
            return $stmt->fetchColumn() > 0;
        }
    }

    /**
     * Check if an index exists on a table
     */
    protected function indexExists(PDO $pdo, string $tableName, string $indexName): bool
    {
        $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        
        if ($driver === 'sqlite') {
            try {
                $sql = "SELECT name FROM sqlite_master WHERE type='index' AND name = :index_name";
                $stmt = $pdo->prepare($sql);
                $stmt->bindValue(':index_name', $indexName);
                $stmt->execute();
                return $stmt->fetchColumn() !== false;
            } catch (\PDOException $e) {
                return false;
            }
        } elseif ($driver === 'pgsql') {
            $sql = "SELECT COUNT(*) FROM pg_indexes 
                    WHERE schemaname = ANY(current_schemas(false))
                      AND tablename = :table_name 
                      AND indexname = :index_name";
            $stmt = $pdo->prepare($sql);
            $stmt->bindValue(':table_name', $tableName);
            $stmt->bindValue(':index_name', $indexName);
            $stmt->execute();
            return $stmt->fetchColumn() > 0;
        } else {
            $sql = "SELECT COUNT(*) FROM information_schema.statistics 
                    WHERE table_schema = DATABASE() 
                    AND table_name = :table_name 
                    AND index_name = :index_name";
            $stmt = $pdo->prepare($sql);
            $stmt->bindValue(':table_name', $tableName);
            $stmt->bindValue(':index_name', $indexName);
            $stmt->execute();
            return $stmt->fetchColumn() > 0;
        }
    }

    public function canExecute(PDO $pdo): bool
    {
        // Can execute if product_activity_log table doesn't exist
        return !$this->tableExists($pdo, 'product_activity_log');
    }

    public function canRollback(PDO $pdo): bool
    {
        // Can rollback if product_activity_log table exists
        return $this->tableExists($pdo, 'product_activity_log');
    }
}