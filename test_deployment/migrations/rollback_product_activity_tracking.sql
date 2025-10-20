-- ===================================================================
-- PRODUCT ACTIVITY TRACKING ROLLBACK MIGRATION
-- ===================================================================
-- Version: 1.0
-- Date: 2025-01-16
-- Description: Rollback product activity tracking migration
-- WARNING: This will remove all activity tracking data permanently!

-- ===================================================================
-- STEP 1: Drop stored procedure
-- ===================================================================

DROP PROCEDURE IF EXISTS UpdateActivityMonitoringStats;

-- ===================================================================
-- STEP 2: Drop view
-- ===================================================================

DROP VIEW IF EXISTS v_active_products_stats;

-- ===================================================================
-- STEP 3: Drop activity monitoring table
-- ===================================================================

DROP TABLE IF EXISTS activity_monitoring_stats;

-- ===================================================================
-- STEP 4: Drop product activity log table
-- ===================================================================

DROP TABLE IF EXISTS product_activity_log;

-- ===================================================================
-- STEP 5: Remove activity tracking columns from dim_products
-- ===================================================================

-- Remove indexes first
DROP INDEX IF EXISTS idx_dim_products_active_updated ON dim_products;
DROP INDEX IF EXISTS idx_dim_products_activity_checked ON dim_products;
DROP INDEX IF EXISTS idx_dim_products_is_active ON dim_products;

-- Remove columns
ALTER TABLE dim_products 
DROP COLUMN IF EXISTS activity_reason,
DROP COLUMN IF EXISTS activity_checked_at,
DROP COLUMN IF EXISTS is_active;

-- ===================================================================
-- STEP 6: Remove configuration settings
-- ===================================================================

DELETE FROM system_settings 
WHERE setting_key IN (
    'ozon_filter_active_only',
    'ozon_activity_check_interval', 
    'ozon_stock_threshold',
    'activity_log_retention_days',
    'activity_change_notifications',
    'migration_product_activity_tracking'
);

-- ===================================================================
-- ROLLBACK COMPLETE
-- ===================================================================

-- Log rollback completion
INSERT INTO system_settings (setting_key, setting_value, description) VALUES
('rollback_product_activity_tracking', UNIX_TIMESTAMP(), 'Timestamp when product activity tracking rollback was applied')
ON DUPLICATE KEY UPDATE 
    setting_value = UNIX_TIMESTAMP(),
    updated_at = CURRENT_TIMESTAMP;