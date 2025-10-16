-- ===================================================================
-- PRODUCT ACTIVITY TRACKING VALIDATION
-- ===================================================================
-- Version: 1.0
-- Date: 2025-01-16
-- Description: Validate that product activity tracking migration was applied correctly

-- ===================================================================
-- VALIDATION 1: Check dim_products table structure
-- ===================================================================

SELECT 
    'dim_products_structure' as validation_name,
    CASE 
        WHEN (
            SELECT COUNT(*) 
            FROM INFORMATION_SCHEMA.COLUMNS 
            WHERE TABLE_SCHEMA = DATABASE() 
            AND TABLE_NAME = 'dim_products' 
            AND COLUMN_NAME IN ('is_active', 'activity_checked_at', 'activity_reason')
        ) = 3 THEN 'PASS'
        ELSE 'FAIL'
    END as result,
    'All activity tracking columns should exist in dim_products' as description;

-- ===================================================================
-- VALIDATION 2: Check indexes on dim_products
-- ===================================================================

SELECT 
    'dim_products_indexes' as validation_name,
    CASE 
        WHEN (
            SELECT COUNT(*) 
            FROM INFORMATION_SCHEMA.STATISTICS 
            WHERE TABLE_SCHEMA = DATABASE() 
            AND TABLE_NAME = 'dim_products' 
            AND INDEX_NAME IN ('idx_dim_products_is_active', 'idx_dim_products_activity_checked', 'idx_dim_products_active_updated')
        ) >= 3 THEN 'PASS'
        ELSE 'FAIL'
    END as result,
    'Required indexes should exist on dim_products' as description;

-- ===================================================================
-- VALIDATION 3: Check product_activity_log table exists
-- ===================================================================

SELECT 
    'product_activity_log_table' as validation_name,
    CASE 
        WHEN (
            SELECT COUNT(*) 
            FROM INFORMATION_SCHEMA.TABLES 
            WHERE TABLE_SCHEMA = DATABASE() 
            AND TABLE_NAME = 'product_activity_log'
        ) = 1 THEN 'PASS'
        ELSE 'FAIL'
    END as result,
    'product_activity_log table should exist' as description;

-- ===================================================================
-- VALIDATION 4: Check activity_monitoring_stats table exists
-- ===================================================================

SELECT 
    'activity_monitoring_stats_table' as validation_name,
    CASE 
        WHEN (
            SELECT COUNT(*) 
            FROM INFORMATION_SCHEMA.TABLES 
            WHERE TABLE_SCHEMA = DATABASE() 
            AND TABLE_NAME = 'activity_monitoring_stats'
        ) = 1 THEN 'PASS'
        ELSE 'FAIL'
    END as result,
    'activity_monitoring_stats table should exist' as description;

-- ===================================================================
-- VALIDATION 5: Check view exists
-- ===================================================================

SELECT 
    'v_active_products_stats_view' as validation_name,
    CASE 
        WHEN (
            SELECT COUNT(*) 
            FROM INFORMATION_SCHEMA.VIEWS 
            WHERE TABLE_SCHEMA = DATABASE() 
            AND TABLE_NAME = 'v_active_products_stats'
        ) = 1 THEN 'PASS'
        ELSE 'FAIL'
    END as result,
    'v_active_products_stats view should exist' as description;

-- ===================================================================
-- VALIDATION 6: Check stored procedure exists
-- ===================================================================

SELECT 
    'update_activity_monitoring_stats_procedure' as validation_name,
    CASE 
        WHEN (
            SELECT COUNT(*) 
            FROM INFORMATION_SCHEMA.ROUTINES 
            WHERE ROUTINE_SCHEMA = DATABASE() 
            AND ROUTINE_NAME = 'UpdateActivityMonitoringStats'
            AND ROUTINE_TYPE = 'PROCEDURE'
        ) = 1 THEN 'PASS'
        ELSE 'FAIL'
    END as result,
    'UpdateActivityMonitoringStats procedure should exist' as description;

-- ===================================================================
-- VALIDATION 7: Check foreign key constraints
-- ===================================================================

SELECT 
    'foreign_key_constraints' as validation_name,
    CASE 
        WHEN (
            SELECT COUNT(*) 
            FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE 
            WHERE CONSTRAINT_SCHEMA = DATABASE() 
            AND TABLE_NAME = 'product_activity_log'
            AND CONSTRAINT_NAME = 'fk_product_activity_log_product'
        ) = 1 THEN 'PASS'
        ELSE 'FAIL'
    END as result,
    'Foreign key constraint should exist on product_activity_log' as description;

-- ===================================================================
-- VALIDATION 8: Check system settings
-- ===================================================================

SELECT 
    'system_settings_configuration' as validation_name,
    CASE 
        WHEN (
            SELECT COUNT(*) 
            FROM system_settings 
            WHERE setting_key IN (
                'ozon_filter_active_only',
                'ozon_activity_check_interval',
                'ozon_stock_threshold',
                'activity_log_retention_days',
                'activity_change_notifications'
            )
        ) = 5 THEN 'PASS'
        ELSE 'FAIL'
    END as result,
    'All required system settings should be configured' as description;

-- ===================================================================
-- VALIDATION 9: Check initial data state
-- ===================================================================

SELECT 
    'initial_data_state' as validation_name,
    CASE 
        WHEN (
            SELECT COUNT(*) 
            FROM dim_products 
            WHERE sku_ozon IS NOT NULL 
            AND is_active = FALSE 
            AND activity_reason = 'Initial migration - needs activity check'
        ) = (
            SELECT COUNT(*) 
            FROM dim_products 
            WHERE sku_ozon IS NOT NULL
        ) THEN 'PASS'
        ELSE 'FAIL'
    END as result,
    'All existing products should be marked as inactive initially' as description;

-- ===================================================================
-- VALIDATION 10: Test view functionality
-- ===================================================================

SELECT 
    'view_functionality' as validation_name,
    CASE 
        WHEN (
            SELECT total_products 
            FROM v_active_products_stats
        ) > 0 THEN 'PASS'
        ELSE 'FAIL'
    END as result,
    'View should return valid statistics' as description;

-- ===================================================================
-- SUMMARY STATISTICS
-- ===================================================================

SELECT 
    '=== MIGRATION SUMMARY ===' as summary,
    '' as value;

SELECT 
    'Total products in system' as metric,
    COUNT(*) as value
FROM dim_products;

SELECT 
    'Products with Ozon SKU' as metric,
    COUNT(*) as value
FROM dim_products 
WHERE sku_ozon IS NOT NULL;

SELECT 
    'Products marked as inactive' as metric,
    COUNT(*) as value
FROM dim_products 
WHERE is_active = FALSE;

SELECT 
    'Products ready for activity check' as metric,
    COUNT(*) as value
FROM dim_products 
WHERE sku_ozon IS NOT NULL 
AND activity_checked_at IS NULL;

-- Show current view statistics
SELECT * FROM v_active_products_stats;