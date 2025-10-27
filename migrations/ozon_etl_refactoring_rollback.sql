-- Ozon ETL Refactoring Rollback Script
-- Description: Complete rollback of all ETL refactoring changes
-- Author: ETL Development Team
-- Date: 2025-10-27
-- WARNING: This will remove all ETL refactoring changes and data

BEGIN;

-- Log rollback start
INSERT INTO migration_log (migration_name, executed_at, description) 
VALUES (
    'ozon_etl_refactoring_rollback',
    CURRENT_TIMESTAMP,
    'Starting complete rollback of ETL refactoring changes'
) ON CONFLICT (migration_name) DO UPDATE SET 
    executed_at = CURRENT_TIMESTAMP,
    description = EXCLUDED.description;

-- ============================================================================
-- ROLLBACK MIGRATION 003: ETL Tracking System
-- ============================================================================

-- Drop views
DROP VIEW IF EXISTS v_etl_dashboard;

-- Drop functions
DROP FUNCTION IF EXISTS start_etl_execution(VARCHAR, JSONB);
DROP FUNCTION IF EXISTS complete_etl_execution(UUID, VARCHAR, INTEGER, TEXT, JSONB);
DROP FUNCTION IF EXISTS get_etl_status(VARCHAR);
DROP FUNCTION IF EXISTS calculate_data_quality_score();

-- Drop tables (in reverse dependency order)
DROP TABLE IF EXISTS data_quality_metrics;
DROP TABLE IF EXISTS etl_workflow_executions;
DROP TABLE IF EXISTS etl_execution_log;

-- ============================================================================
-- ROLLBACK MIGRATION 002: Enhanced View
-- ============================================================================

-- Drop materialized view and related objects
DROP FUNCTION IF EXISTS refresh_detailed_inventory_mv();
DROP MATERIALIZED VIEW IF EXISTS mv_detailed_inventory;

-- Drop views
DROP VIEW IF EXISTS v_detailed_inventory_admin;
DROP VIEW IF EXISTS v_detailed_inventory;

-- Drop functions
DROP FUNCTION IF EXISTS get_product_stock_status(VARCHAR, INTEGER, INTEGER, DECIMAL);
DROP FUNCTION IF EXISTS get_urgency_score(VARCHAR, INTEGER, INTEGER, DECIMAL);

-- Drop functional indexes
DROP INDEX IF EXISTS idx_inventory_stock_status_func;
DROP INDEX IF EXISTS idx_inventory_available_stock;
DROP INDEX IF EXISTS idx_inventory_warehouse_available;

-- ============================================================================
-- ROLLBACK MIGRATION 001: Visibility Field
-- ============================================================================

-- Drop validation function
DROP FUNCTION IF EXISTS validate_visibility_status(VARCHAR);

-- Drop indexes related to visibility and ETL tracking
DROP INDEX IF EXISTS idx_dim_products_visibility;
DROP INDEX IF EXISTS idx_dim_products_visibility_active;
DROP INDEX IF EXISTS idx_dim_products_offer_visibility;
DROP INDEX IF EXISTS idx_dim_products_visibility_updated;
DROP INDEX IF EXISTS idx_inventory_etl_batch;
DROP INDEX IF EXISTS idx_inventory_product_etl_sync;

-- Remove check constraint
ALTER TABLE dim_products DROP CONSTRAINT IF EXISTS chk_dim_products_visibility;

-- Remove added columns from inventory table
ALTER TABLE inventory DROP COLUMN IF EXISTS etl_batch_id;
ALTER TABLE inventory DROP COLUMN IF EXISTS last_product_etl_sync;

-- Remove added columns from dim_products table
ALTER TABLE dim_products DROP COLUMN IF EXISTS visibility_updated_at;
ALTER TABLE dim_products DROP COLUMN IF EXISTS visibility;

-- ============================================================================
-- RESTORE ORIGINAL VIEW (if it existed)
-- ============================================================================

-- Recreate original v_detailed_inventory view (basic version)
CREATE OR REPLACE VIEW v_detailed_inventory AS
SELECT
    p.product_id,
    p.name as product_name,
    p.offer_id,
    p.fbo_sku,
    p.fbs_sku,
    p.status,
    
    i.warehouse_name,
    i.present,
    i.reserved,
    (i.present - i.reserved) AS available_stock,
    
    -- Simple stock status based only on quantity
    CASE 
        WHEN (i.present - i.reserved) <= 0 THEN 'out_of_stock'
        WHEN wsm.daily_sales_avg > 0 AND (i.present - i.reserved) / wsm.daily_sales_avg < 14 THEN 'critical'
        WHEN wsm.daily_sales_avg > 0 AND (i.present - i.reserved) / wsm.daily_sales_avg < 30 THEN 'low'
        WHEN wsm.daily_sales_avg > 0 AND (i.present - i.reserved) / wsm.daily_sales_avg < 60 THEN 'normal'
        ELSE 'excess'
    END as stock_status,
    
    wsm.daily_sales_avg,
    wsm.weekly_sales_avg,
    wsm.monthly_sales_avg,
    
    CASE 
        WHEN wsm.daily_sales_avg > 0 THEN 
            ROUND((i.present - i.reserved) / wsm.daily_sales_avg, 1)
        ELSE NULL 
    END as days_of_stock,
    
    i.updated_at as inventory_updated_at,
    p.updated_at as product_updated_at

FROM inventory i
INNER JOIN dim_products p ON i.offer_id = p.offer_id
LEFT JOIN warehouse_sales_metrics wsm ON i.offer_id = wsm.offer_id 
    AND i.warehouse_name = wsm.warehouse_name
WHERE (i.present - i.reserved) > 0;

-- ============================================================================
-- CLEANUP AND VERIFICATION
-- ============================================================================

-- Remove migration log entries for rolled back migrations
DELETE FROM migration_log 
WHERE migration_name IN (
    'ozon_etl_refactoring_001_add_visibility_field',
    'ozon_etl_refactoring_002_create_enhanced_view',
    'ozon_etl_refactoring_003_create_etl_tracking'
);

-- Log rollback completion
UPDATE migration_log 
SET description = 'Completed rollback of ETL refactoring changes - system restored to pre-refactoring state'
WHERE migration_name = 'ozon_etl_refactoring_rollback';

COMMIT;

-- Verify rollback success
DO $$
DECLARE
    visibility_column_exists BOOLEAN;
    etl_tables_exist INTEGER;
    enhanced_functions_exist INTEGER;
BEGIN
    -- Check if visibility column was removed
    SELECT EXISTS (
        SELECT 1 FROM information_schema.columns 
        WHERE table_name = 'dim_products' 
        AND column_name = 'visibility'
    ) INTO visibility_column_exists;
    
    -- Check if ETL tracking tables were removed
    SELECT COUNT(*) INTO etl_tables_exist
    FROM information_schema.tables 
    WHERE table_name IN ('etl_execution_log', 'etl_workflow_executions', 'data_quality_metrics');
    
    -- Check if enhanced functions were removed
    SELECT COUNT(*) INTO enhanced_functions_exist
    FROM pg_proc 
    WHERE proname IN ('get_product_stock_status', 'get_urgency_score', 'calculate_data_quality_score');
    
    IF NOT visibility_column_exists AND etl_tables_exist = 0 AND enhanced_functions_exist = 0 THEN
        RAISE NOTICE 'Rollback completed successfully - all ETL refactoring changes have been removed';
        RAISE NOTICE 'System has been restored to pre-refactoring state';
        RAISE NOTICE 'Original v_detailed_inventory view has been restored';
    ELSE
        RAISE WARNING 'Rollback may be incomplete:';
        RAISE WARNING '  - Visibility column exists: %', visibility_column_exists;
        RAISE WARNING '  - ETL tables remaining: %', etl_tables_exist;
        RAISE WARNING '  - Enhanced functions remaining: %', enhanced_functions_exist;
    END IF;
END $$;

-- Final verification query
SELECT 
    'Rollback Verification' as check_type,
    CASE 
        WHEN NOT EXISTS (
            SELECT 1 FROM information_schema.columns 
            WHERE table_name = 'dim_products' AND column_name = 'visibility'
        ) THEN 'SUCCESS: Visibility column removed'
        ELSE 'ERROR: Visibility column still exists'
    END as visibility_check,
    
    CASE 
        WHEN NOT EXISTS (
            SELECT 1 FROM information_schema.tables 
            WHERE table_name = 'etl_execution_log'
        ) THEN 'SUCCESS: ETL tracking tables removed'
        ELSE 'ERROR: ETL tracking tables still exist'
    END as etl_tables_check,
    
    CASE 
        WHEN EXISTS (
            SELECT 1 FROM information_schema.views 
            WHERE table_name = 'v_detailed_inventory'
        ) THEN 'SUCCESS: Original view restored'
        ELSE 'ERROR: View not found'
    END as view_check,
    
    CURRENT_TIMESTAMP as verified_at;