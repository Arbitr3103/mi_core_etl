-- Rollback script for Ozon Warehouse Stock Reports schema changes
-- Purpose: Safely remove all database changes if needed

-- Disable foreign key checks for clean rollback
SET FOREIGN_KEY_CHECKS = 0;

-- Step 1: Remove foreign key constraint and new columns from inventory table
ALTER TABLE inventory 
DROP FOREIGN KEY IF EXISTS fk_inventory_report_code,
DROP INDEX IF EXISTS idx_report_source,
DROP INDEX IF EXISTS idx_last_report_update,
DROP INDEX IF EXISTS idx_report_code,
DROP INDEX IF EXISTS idx_report_source_update,
DROP COLUMN IF EXISTS report_source,
DROP COLUMN IF EXISTS last_report_update,
DROP COLUMN IF EXISTS report_code;

-- Step 2: Drop stock_report_logs table
DROP TABLE IF EXISTS stock_report_logs;

-- Step 3: Drop ozon_stock_reports table
DROP TABLE IF EXISTS ozon_stock_reports;

-- Re-enable foreign key checks
SET FOREIGN_KEY_CHECKS = 1;

-- Verify rollback was successful
SHOW TABLES LIKE '%stock%';
SHOW COLUMNS FROM inventory WHERE Field IN ('report_source', 'last_report_update', 'report_code');