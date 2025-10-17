-- ============================================================================
-- REPLENISHMENT SYSTEM ROLLBACK MIGRATION
-- ============================================================================
-- Description: Rollback script for inventory replenishment system
-- Version: 1.0.0
-- Date: 2025-10-17
-- WARNING: This will remove all replenishment data and tables
-- ============================================================================

-- Set session variables for safe rollback
SET SESSION sql_mode = 'STRICT_TRANS_TABLES,ERROR_FOR_DIVISION_BY_ZERO,NO_AUTO_CREATE_USER,NO_ENGINE_SUBSTITUTION';
SET SESSION foreign_key_checks = 0;

-- Log rollback start
INSERT INTO migration_log (migration_name, version, status) 
VALUES ('replenishment_system_rollback', '1.0.0', 'started');

SET @rollback_id = LAST_INSERT_ID();

-- ============================================================================
-- 1. BACKUP DATA BEFORE ROLLBACK (OPTIONAL)
-- ============================================================================

-- Create backup tables with timestamp
SET @backup_suffix = DATE_FORMAT(NOW(), '%Y%m%d_%H%i%s');

-- Backup recommendations
SET @sql = CONCAT('CREATE TABLE replenishment_recommendations_backup_', @backup_suffix, ' AS SELECT * FROM replenishment_recommendations');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Backup configuration
SET @sql = CONCAT('CREATE TABLE replenishment_config_backup_', @backup_suffix, ' AS SELECT * FROM replenishment_config');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Backup calculations log
SET @sql = CONCAT('CREATE TABLE replenishment_calculations_backup_', @backup_suffix, ' AS SELECT * FROM replenishment_calculations');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SELECT CONCAT('Data backed up with suffix: ', @backup_suffix) as backup_info;

-- ============================================================================
-- 2. DROP STORED PROCEDURES
-- ============================================================================

DROP PROCEDURE IF EXISTS CleanupOldRecommendations;
DROP PROCEDURE IF EXISTS GetADSCalculationData;
DROP PROCEDURE IF EXISTS GetCurrentStock;

SELECT 'Stored procedures dropped' as status;

-- ============================================================================
-- 3. DROP VIEWS
-- ============================================================================

DROP VIEW IF EXISTS v_latest_replenishment_recommendations;
DROP VIEW IF EXISTS v_replenishment_config;

SELECT 'Views dropped' as status;

-- ============================================================================
-- 4. DROP FOREIGN KEY CONSTRAINTS
-- ============================================================================

-- Drop foreign key constraint if exists
SET @constraint_exists = (
    SELECT COUNT(*) 
    FROM information_schema.key_column_usage 
    WHERE table_schema = DATABASE() 
        AND table_name = 'replenishment_recommendations' 
        AND constraint_name = 'fk_replenishment_product'
);

SET @sql = IF(@constraint_exists > 0,
    'ALTER TABLE replenishment_recommendations DROP FOREIGN KEY fk_replenishment_product',
    'SELECT "Foreign key constraint not found" as notice');

PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Drop foreign key for calculation details
ALTER TABLE replenishment_calculation_details DROP FOREIGN KEY replenishment_calculation_details_ibfk_1;

SELECT 'Foreign key constraints dropped' as status;

-- ============================================================================
-- 5. DROP TABLES IN CORRECT ORDER
-- ============================================================================

-- Drop dependent tables first
DROP TABLE IF EXISTS replenishment_calculation_details;
DROP TABLE IF EXISTS replenishment_statistics;
DROP TABLE IF EXISTS replenishment_calculations;
DROP TABLE IF EXISTS replenishment_recommendations;
DROP TABLE IF EXISTS replenishment_config;

SELECT 'Replenishment tables dropped' as status;

-- ============================================================================
-- 6. REMOVE INDEXES FROM OTHER TABLES (ADDED FOR REPLENISHMENT)
-- ============================================================================

-- Remove indexes from fact_orders
DROP INDEX IF EXISTS idx_fact_orders_ads_calculation ON fact_orders;
DROP INDEX IF EXISTS idx_fact_orders_date_product ON fact_orders;
DROP INDEX IF EXISTS idx_fact_orders_sales_covering ON fact_orders;

-- Remove indexes from inventory_data
DROP INDEX IF EXISTS idx_inventory_stock_availability ON inventory_data;
DROP INDEX IF EXISTS idx_inventory_date_stock ON inventory_data;
DROP INDEX IF EXISTS idx_inventory_current_stock_covering ON inventory_data;

-- Remove indexes from dim_products (if they were added)
DROP INDEX IF EXISTS idx_dim_products_active_lookup ON dim_products;
DROP INDEX IF EXISTS idx_dim_products_info_covering ON dim_products;

SELECT 'Replenishment-specific indexes removed' as status;

-- ============================================================================
-- 7. CLEAN UP CRON JOBS (INFORMATIONAL)
-- ============================================================================

SELECT 'MANUAL ACTION REQUIRED: Remove replenishment cron jobs from crontab:' as notice;
SELECT '  crontab -e' as command1;
SELECT '  # Remove lines containing: replenishment, cron_replenishment_weekly.php' as command2;

-- ============================================================================
-- 8. CLEAN UP FILES (INFORMATIONAL)
-- ============================================================================

SELECT 'MANUAL ACTION REQUIRED: Remove replenishment files:' as notice;
SELECT '  rm -rf src/Replenishment/' as command1;
SELECT '  rm -f api/replenishment.php' as command2;
SELECT '  rm -f html/replenishment_dashboard.php' as command3;
SELECT '  rm -f cron_replenishment_weekly.php' as command4;
SELECT '  rm -f replenishment_*.php' as command5;

-- ============================================================================
-- 9. VERIFY ROLLBACK
-- ============================================================================

-- Check that tables are gone
SELECT 
    COUNT(*) as remaining_replenishment_tables
FROM information_schema.tables 
WHERE table_schema = DATABASE() 
    AND table_name LIKE 'replenishment_%'
    AND table_name NOT LIKE '%backup_%';

-- Check that procedures are gone
SELECT 
    COUNT(*) as remaining_replenishment_procedures
FROM information_schema.routines 
WHERE routine_schema = DATABASE() 
    AND routine_name IN ('CleanupOldRecommendations', 'GetADSCalculationData', 'GetCurrentStock');

-- Check that views are gone
SELECT 
    COUNT(*) as remaining_replenishment_views
FROM information_schema.views 
WHERE table_schema = DATABASE() 
    AND table_name LIKE '%replenishment%';

-- ============================================================================
-- 10. ROLLBACK COMPLETION
-- ============================================================================

-- Re-enable foreign key checks
SET SESSION foreign_key_checks = 1;

-- Update migration log
UPDATE migration_log 
SET status = 'completed', completed_at = NOW() 
WHERE id = @rollback_id;

-- Show rollback results
SELECT 
    'Rollback completed successfully' as status,
    NOW() as completed_at,
    CONCAT('Data backed up with suffix: ', @backup_suffix) as backup_info;

-- Show backup tables created
SELECT 
    table_name as backup_table,
    table_rows as rows_backed_up,
    ROUND(((data_length + index_length) / 1024 / 1024), 2) as size_mb
FROM information_schema.tables 
WHERE table_schema = DATABASE() 
    AND table_name LIKE CONCAT('%backup_', @backup_suffix)
ORDER BY table_name;

-- ============================================================================
-- ROLLBACK COMPLETE
-- ============================================================================

SELECT '
ROLLBACK SUMMARY:
================
✓ All replenishment tables dropped
✓ All stored procedures removed
✓ All views removed
✓ Foreign key constraints dropped
✓ Replenishment-specific indexes removed
✓ Data backed up before removal

MANUAL ACTIONS REQUIRED:
========================
1. Remove cron jobs from crontab
2. Remove replenishment PHP files
3. Remove replenishment directories
4. Update any application code that references replenishment system

BACKUP TABLES CREATED:
======================
- replenishment_recommendations_backup_' + @backup_suffix + '
- replenishment_config_backup_' + @backup_suffix + '
- replenishment_calculations_backup_' + @backup_suffix + '

To restore data later, rename backup tables back to original names.
' as rollback_summary;