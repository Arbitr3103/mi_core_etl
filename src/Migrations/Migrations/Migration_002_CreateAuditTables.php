<?php

namespace MDM\Migrations\Migrations;

use MDM\Migrations\BaseMigration;
use PDO;

/**
 * Migration 002: Create Audit Tables
 * 
 * Creates audit and history tracking tables.
 */
class Migration_002_CreateAuditTables extends BaseMigration
{
    public function __construct()
    {
        parent::__construct(
            version: '002_create_audit_tables',
            description: 'Create audit and history tracking tables',
            dependencies: ['001_create_initial_schema']
        );
    }

    public function up(PDO $pdo): bool
    {
        $this->log("Creating audit and history tables...");

        // Create matching_history table
        $sql = "CREATE TABLE IF NOT EXISTS matching_history (
            id BIGINT AUTO_INCREMENT PRIMARY KEY COMMENT 'Auto-increment ID',
            external_sku VARCHAR(200) NOT NULL COMMENT 'External SKU being matched',
            source VARCHAR(50) NOT NULL COMMENT 'Data source',
            master_id VARCHAR(50) NULL COMMENT 'Matched Master ID (if any)',
            match_candidates JSON NULL COMMENT 'List of potential matches with scores',
            final_decision ENUM('auto_matched', 'manual_matched', 'new_master', 'rejected') NOT NULL COMMENT 'Final matching decision',
            confidence_score DECIMAL(3,2) NULL COMMENT 'Final confidence score',
            match_method VARCHAR(100) NULL COMMENT 'Matching method used',
            processing_time_ms INT NULL COMMENT 'Processing time in milliseconds',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP COMMENT 'When matching was performed',
            decided_by VARCHAR(100) NULL COMMENT 'User who made the decision (for manual matches)',
            
            FOREIGN KEY (master_id) REFERENCES master_products(master_id) 
                ON DELETE SET NULL ON UPDATE CASCADE,
            
            INDEX idx_external_sku (external_sku),
            INDEX idx_source (source),
            INDEX idx_master_id (master_id),
            INDEX idx_final_decision (final_decision),
            INDEX idx_created_at (created_at),
            
            INDEX idx_sku_source (external_sku, source),
            INDEX idx_decision_date (final_decision, created_at)
        ) ENGINE=InnoDB 
        COMMENT='History of matching attempts and decisions'";

        $this->executeSql($pdo, $sql);
        $this->log("Created matching_history table");

        // Create audit_log table
        $sql = "CREATE TABLE IF NOT EXISTS audit_log (
            id BIGINT AUTO_INCREMENT PRIMARY KEY COMMENT 'Auto-increment ID',
            table_name VARCHAR(100) NOT NULL COMMENT 'Name of the table that was modified',
            record_id VARCHAR(100) NOT NULL COMMENT 'ID of the record that was modified',
            action ENUM('INSERT', 'UPDATE', 'DELETE', 'MERGE') NOT NULL COMMENT 'Type of action performed',
            old_values JSON NULL COMMENT 'Previous values (for UPDATE and DELETE)',
            new_values JSON NULL COMMENT 'New values (for INSERT and UPDATE)',
            changed_fields JSON NULL COMMENT 'List of fields that were changed',
            user_id VARCHAR(100) NULL COMMENT 'User who performed the action',
            ip_address VARCHAR(45) NULL COMMENT 'IP address of the user',
            user_agent TEXT NULL COMMENT 'User agent string',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP COMMENT 'When the action was performed',
            
            INDEX idx_table_name (table_name),
            INDEX idx_record_id (record_id),
            INDEX idx_action (action),
            INDEX idx_user_id (user_id),
            INDEX idx_created_at (created_at),
            
            INDEX idx_table_record (table_name, record_id),
            INDEX idx_user_date (user_id, created_at)
        ) ENGINE=InnoDB 
        COMMENT='Audit trail of all changes to master data'";

        $this->executeSql($pdo, $sql);
        $this->log("Created audit_log table");

        $this->log("Audit tables created successfully");
        return true;
    }

    public function down(PDO $pdo): bool
    {
        $this->log("Rolling back audit tables...");

        // Drop audit tables
        $tables = ['audit_log', 'matching_history'];
        
        foreach ($tables as $table) {
            $sql = "DROP TABLE IF EXISTS {$table}";
            $this->executeSql($pdo, $sql);
            $this->log("Dropped table: {$table}");
        }

        $this->log("Audit tables rollback completed");
        return true;
    }

    public function canExecute(PDO $pdo): bool
    {
        // Check if core tables exist and audit tables don't
        return $this->tableExists($pdo, 'master_products') &&
               !$this->tableExists($pdo, 'matching_history') &&
               !$this->tableExists($pdo, 'audit_log');
    }

    public function canRollback(PDO $pdo): bool
    {
        // Can rollback if audit tables exist
        return $this->tableExists($pdo, 'matching_history') ||
               $this->tableExists($pdo, 'audit_log');
    }
}