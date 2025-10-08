<?php

namespace MDM\Migrations\Migrations;

use MDM\Migrations\BaseMigration;
use PDO;

/**
 * Migration 003: Create Views and Triggers
 * 
 * Creates database views and audit triggers.
 */
class Migration_003_CreateViewsAndTriggers extends BaseMigration
{
    public function __construct()
    {
        parent::__construct(
            version: '003_create_views_and_triggers',
            description: 'Create database views and audit triggers',
            dependencies: ['002_create_audit_tables']
        );
    }

    public function up(PDO $pdo): bool
    {
        $this->log("Creating views and triggers...");

        // Create views
        $this->createViews($pdo);
        
        // Create triggers
        $this->createTriggers($pdo);

        $this->log("Views and triggers created successfully");
        return true;
    }

    public function down(PDO $pdo): bool
    {
        $this->log("Rolling back views and triggers...");

        // Drop triggers
        $this->dropTriggers($pdo);
        
        // Drop views
        $this->dropViews($pdo);

        $this->log("Views and triggers rollback completed");
        return true;
    }

    private function createViews(PDO $pdo): void
    {
        // View: master_products_with_stats
        $sql = "CREATE VIEW v_master_products_with_stats AS
        SELECT 
            mp.master_id,
            mp.canonical_name,
            mp.canonical_brand,
            mp.canonical_category,
            mp.status,
            mp.created_at,
            mp.updated_at,
            COUNT(sm.id) as total_mappings,
            COUNT(CASE WHEN sm.verification_status = 'auto' THEN 1 END) as auto_mappings,
            COUNT(CASE WHEN sm.verification_status = 'manual' THEN 1 END) as manual_mappings,
            COUNT(CASE WHEN sm.verification_status = 'pending' THEN 1 END) as pending_mappings,
            AVG(sm.confidence_score) as avg_confidence_score,
            GROUP_CONCAT(DISTINCT sm.source) as sources
        FROM master_products mp
        LEFT JOIN sku_mapping sm ON mp.master_id = sm.master_id
        GROUP BY mp.master_id, mp.canonical_name, mp.canonical_brand, 
                 mp.canonical_category, mp.status, mp.created_at, mp.updated_at";

        $this->executeSql($pdo, $sql);
        $this->log("Created view: v_master_products_with_stats");

        // View: pending_verification_queue
        $sql = "CREATE VIEW v_pending_verification_queue AS
        SELECT 
            sm.id,
            sm.external_sku,
            sm.source,
            sm.source_name,
            sm.source_brand,
            sm.source_category,
            sm.confidence_score,
            sm.created_at,
            mp.canonical_name as suggested_master_name,
            mp.canonical_brand as suggested_master_brand,
            mp.canonical_category as suggested_master_category
        FROM sku_mapping sm
        LEFT JOIN master_products mp ON sm.master_id = mp.master_id
        WHERE sm.verification_status = 'pending'
        ORDER BY sm.confidence_score DESC, sm.created_at ASC";

        $this->executeSql($pdo, $sql);
        $this->log("Created view: v_pending_verification_queue");

        // View: data_quality_summary
        $sql = "CREATE VIEW v_data_quality_summary AS
        SELECT 
            dqm.metric_name,
            dqm.metric_value,
            dqm.metric_percentage,
            dqm.total_records,
            dqm.good_records,
            dqm.bad_records,
            dqm.source,
            dqm.category,
            dqm.calculation_date
        FROM data_quality_metrics dqm
        INNER JOIN (
            SELECT metric_name, source, MAX(calculation_date) as latest_date
            FROM data_quality_metrics
            GROUP BY metric_name, source
        ) latest ON dqm.metric_name = latest.metric_name 
            AND (dqm.source = latest.source OR (dqm.source IS NULL AND latest.source IS NULL))
            AND dqm.calculation_date = latest.latest_date";

        $this->executeSql($pdo, $sql);
        $this->log("Created view: v_data_quality_summary");
    }

    private function createTriggers(PDO $pdo): void
    {
        // Set delimiter for trigger creation
        $pdo->exec("DELIMITER $$");

        // Trigger for master_products INSERT
        $sql = "CREATE TRIGGER tr_master_products_insert 
        AFTER INSERT ON master_products
        FOR EACH ROW
        BEGIN
            INSERT INTO audit_log (
                table_name, record_id, action, new_values, user_id, created_at
            ) VALUES (
                'master_products', 
                NEW.master_id, 
                'INSERT',
                JSON_OBJECT(
                    'master_id', NEW.master_id,
                    'canonical_name', NEW.canonical_name,
                    'canonical_brand', NEW.canonical_brand,
                    'canonical_category', NEW.canonical_category,
                    'status', NEW.status
                ),
                NEW.created_by,
                NOW()
            );
        END$$";

        $this->executeSql($pdo, $sql);
        $this->log("Created trigger: tr_master_products_insert");

        // Trigger for master_products UPDATE
        $sql = "CREATE TRIGGER tr_master_products_update 
        AFTER UPDATE ON master_products
        FOR EACH ROW
        BEGIN
            INSERT INTO audit_log (
                table_name, record_id, action, old_values, new_values, user_id, created_at
            ) VALUES (
                'master_products', 
                NEW.master_id, 
                'UPDATE',
                JSON_OBJECT(
                    'master_id', OLD.master_id,
                    'canonical_name', OLD.canonical_name,
                    'canonical_brand', OLD.canonical_brand,
                    'canonical_category', OLD.canonical_category,
                    'status', OLD.status
                ),
                JSON_OBJECT(
                    'master_id', NEW.master_id,
                    'canonical_name', NEW.canonical_name,
                    'canonical_brand', NEW.canonical_brand,
                    'canonical_category', NEW.canonical_category,
                    'status', NEW.status
                ),
                NEW.updated_by,
                NOW()
            );
        END$$";

        $this->executeSql($pdo, $sql);
        $this->log("Created trigger: tr_master_products_update");

        // Trigger for sku_mapping INSERT
        $sql = "CREATE TRIGGER tr_sku_mapping_insert 
        AFTER INSERT ON sku_mapping
        FOR EACH ROW
        BEGIN
            INSERT INTO audit_log (
                table_name, record_id, action, new_values, user_id, created_at
            ) VALUES (
                'sku_mapping', 
                NEW.id, 
                'INSERT',
                JSON_OBJECT(
                    'master_id', NEW.master_id,
                    'external_sku', NEW.external_sku,
                    'source', NEW.source,
                    'verification_status', NEW.verification_status
                ),
                NEW.verified_by,
                NOW()
            );
        END$$";

        $this->executeSql($pdo, $sql);
        $this->log("Created trigger: tr_sku_mapping_insert");

        // Trigger for sku_mapping UPDATE
        $sql = "CREATE TRIGGER tr_sku_mapping_update 
        AFTER UPDATE ON sku_mapping
        FOR EACH ROW
        BEGIN
            INSERT INTO audit_log (
                table_name, record_id, action, old_values, new_values, user_id, created_at
            ) VALUES (
                'sku_mapping', 
                NEW.id, 
                'UPDATE',
                JSON_OBJECT(
                    'master_id', OLD.master_id,
                    'external_sku', OLD.external_sku,
                    'source', OLD.source,
                    'verification_status', OLD.verification_status
                ),
                JSON_OBJECT(
                    'master_id', NEW.master_id,
                    'external_sku', NEW.external_sku,
                    'source', NEW.source,
                    'verification_status', NEW.verification_status
                ),
                NEW.verified_by,
                NOW()
            );
        END$$";

        $this->executeSql($pdo, $sql);
        $this->log("Created trigger: tr_sku_mapping_update");

        // Reset delimiter
        $pdo->exec("DELIMITER ;");
    }

    private function dropViews(PDO $pdo): void
    {
        $views = [
            'v_data_quality_summary',
            'v_pending_verification_queue', 
            'v_master_products_with_stats'
        ];
        
        foreach ($views as $view) {
            $sql = "DROP VIEW IF EXISTS {$view}";
            $this->executeSql($pdo, $sql);
            $this->log("Dropped view: {$view}");
        }
    }

    private function dropTriggers(PDO $pdo): void
    {
        $triggers = [
            'tr_sku_mapping_update',
            'tr_sku_mapping_insert',
            'tr_master_products_update',
            'tr_master_products_insert'
        ];
        
        foreach ($triggers as $trigger) {
            $sql = "DROP TRIGGER IF EXISTS {$trigger}";
            $this->executeSql($pdo, $sql);
            $this->log("Dropped trigger: {$trigger}");
        }
    }

    public function canExecute(PDO $pdo): bool
    {
        // Check if required tables exist
        return $this->tableExists($pdo, 'master_products') &&
               $this->tableExists($pdo, 'sku_mapping') &&
               $this->tableExists($pdo, 'audit_log');
    }

    public function canRollback(PDO $pdo): bool
    {
        return true; // Views and triggers can always be dropped
    }
}