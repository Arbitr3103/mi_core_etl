<?php

namespace MDM\Migrations\Migrations;

use MDM\Migrations\BaseMigration;
use PDO;

/**
 * Migration 001: Create Initial Schema
 * 
 * Creates the core MDM system tables.
 */
class Migration_001_CreateInitialSchema extends BaseMigration
{
    public function __construct()
    {
        parent::__construct(
            version: '001_create_initial_schema',
            description: 'Create initial MDM system schema with core tables'
        );
    }

    public function up(PDO $pdo): bool
    {
        $this->log("Creating initial MDM schema...");

        // Create master_products table
        $sql = "CREATE TABLE IF NOT EXISTS master_products (
            master_id VARCHAR(50) NOT NULL PRIMARY KEY COMMENT 'Unique Master Product ID',
            canonical_name VARCHAR(500) NOT NULL COMMENT 'Standardized product name',
            canonical_brand VARCHAR(200) NULL COMMENT 'Standardized brand name',
            canonical_category VARCHAR(200) NULL COMMENT 'Standardized category',
            description TEXT NULL COMMENT 'Product description',
            attributes JSON NULL COMMENT 'Additional product attributes in JSON format',
            barcode VARCHAR(100) NULL COMMENT 'Product barcode/EAN',
            weight_grams INT NULL COMMENT 'Product weight in grams',
            dimensions_json JSON NULL COMMENT 'Product dimensions (length, width, height)',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP COMMENT 'Record creation timestamp',
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT 'Last update timestamp',
            status ENUM('active', 'inactive', 'pending_review', 'merged') DEFAULT 'active' COMMENT 'Product status',
            created_by VARCHAR(100) NULL COMMENT 'User who created the record',
            updated_by VARCHAR(100) NULL COMMENT 'User who last updated the record',
            
            INDEX idx_canonical_name (canonical_name(100)),
            INDEX idx_canonical_brand (canonical_brand),
            INDEX idx_canonical_category (canonical_category),
            INDEX idx_barcode (barcode),
            INDEX idx_status (status),
            INDEX idx_created_at (created_at),
            INDEX idx_updated_at (updated_at),
            
            FULLTEXT KEY ft_search (canonical_name, canonical_brand, description)
        ) ENGINE=InnoDB 
        COMMENT='Master products table - single source of truth for product data'";

        $this->executeSql($pdo, $sql);
        $this->log("Created master_products table");

        // Create sku_mapping table
        $sql = "CREATE TABLE IF NOT EXISTS sku_mapping (
            id BIGINT AUTO_INCREMENT PRIMARY KEY COMMENT 'Auto-increment ID',
            master_id VARCHAR(50) NOT NULL COMMENT 'Reference to master_products.master_id',
            external_sku VARCHAR(200) NOT NULL COMMENT 'SKU from external source',
            source VARCHAR(50) NOT NULL COMMENT 'Data source (ozon, wildberries, internal, etc.)',
            source_name VARCHAR(500) NULL COMMENT 'Original product name from source',
            source_brand VARCHAR(200) NULL COMMENT 'Original brand from source',
            source_category VARCHAR(200) NULL COMMENT 'Original category from source',
            source_price DECIMAL(10,2) NULL COMMENT 'Price from source',
            source_attributes JSON NULL COMMENT 'Additional attributes from source',
            confidence_score DECIMAL(3,2) NULL COMMENT 'Matching confidence score (0.00-1.00)',
            verification_status ENUM('auto', 'manual', 'pending', 'rejected') DEFAULT 'pending' COMMENT 'Verification status',
            match_method VARCHAR(100) NULL COMMENT 'Method used for matching (exact, fuzzy, manual)',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP COMMENT 'Record creation timestamp',
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT 'Last update timestamp',
            verified_by VARCHAR(100) NULL COMMENT 'User who verified the mapping',
            verified_at TIMESTAMP NULL COMMENT 'Verification timestamp',
            
            FOREIGN KEY (master_id) REFERENCES master_products(master_id) 
                ON DELETE CASCADE ON UPDATE CASCADE,
            
            UNIQUE KEY unique_source_sku (source, external_sku),
            
            INDEX idx_master_id (master_id),
            INDEX idx_external_sku (external_sku),
            INDEX idx_source (source),
            INDEX idx_verification_status (verification_status),
            INDEX idx_confidence_score (confidence_score),
            INDEX idx_created_at (created_at),
            INDEX idx_verified_at (verified_at),
            
            INDEX idx_source_status (source, verification_status),
            INDEX idx_master_source (master_id, source)
        ) ENGINE=InnoDB 
        COMMENT='Mapping table between external SKUs and Master IDs'";

        $this->executeSql($pdo, $sql);
        $this->log("Created sku_mapping table");

        // Create data_quality_metrics table
        $sql = "CREATE TABLE IF NOT EXISTS data_quality_metrics (
            id BIGINT AUTO_INCREMENT PRIMARY KEY COMMENT 'Auto-increment ID',
            metric_name VARCHAR(100) NOT NULL COMMENT 'Name of the quality metric',
            metric_value DECIMAL(10,4) NOT NULL COMMENT 'Calculated metric value',
            metric_percentage DECIMAL(5,2) NULL COMMENT 'Metric as percentage (0.00-100.00)',
            total_records INT NOT NULL DEFAULT 0 COMMENT 'Total number of records analyzed',
            good_records INT NOT NULL DEFAULT 0 COMMENT 'Number of records meeting quality criteria',
            bad_records INT NOT NULL DEFAULT 0 COMMENT 'Number of records not meeting quality criteria',
            source VARCHAR(50) NULL COMMENT 'Data source for the metric',
            category VARCHAR(100) NULL COMMENT 'Metric category (completeness, accuracy, consistency)',
            calculation_date TIMESTAMP DEFAULT CURRENT_TIMESTAMP COMMENT 'When the metric was calculated',
            details JSON NULL COMMENT 'Additional metric details and breakdown',
            
            INDEX idx_metric_name (metric_name),
            INDEX idx_calculation_date (calculation_date),
            INDEX idx_source (source),
            INDEX idx_category (category),
            
            INDEX idx_metric_date (metric_name, calculation_date),
            INDEX idx_source_date (source, calculation_date)
        ) ENGINE=InnoDB 
        COMMENT='Data quality metrics and monitoring information'";

        $this->executeSql($pdo, $sql);
        $this->log("Created data_quality_metrics table");

        $this->log("Initial schema created successfully");
        return true;
    }

    public function down(PDO $pdo): bool
    {
        $this->log("Rolling back initial schema...");

        // Drop tables in reverse order due to foreign key constraints
        $tables = ['data_quality_metrics', 'sku_mapping', 'master_products'];
        
        foreach ($tables as $table) {
            $sql = "DROP TABLE IF EXISTS {$table}";
            $this->executeSql($pdo, $sql);
            $this->log("Dropped table: {$table}");
        }

        $this->log("Initial schema rollback completed");
        return true;
    }

    public function canExecute(PDO $pdo): bool
    {
        // Check if tables don't already exist
        return !$this->tableExists($pdo, 'master_products') &&
               !$this->tableExists($pdo, 'sku_mapping') &&
               !$this->tableExists($pdo, 'data_quality_metrics');
    }

    public function canRollback(PDO $pdo): bool
    {
        // Can rollback if tables exist and are empty or user confirms
        return $this->tableExists($pdo, 'master_products') ||
               $this->tableExists($pdo, 'sku_mapping') ||
               $this->tableExists($pdo, 'data_quality_metrics');
    }
}