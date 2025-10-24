-- Master migration script for Ozon Warehouse Stock Reports
-- Purpose: Apply all database schema changes for the warehouse reports feature
-- Requirements: 1.3, 2.4, 3.2, 2.5, 5.5

-- Disable foreign key checks temporarily for clean migration
SET FOREIGN_KEY_CHECKS = 0;

-- Step 1: Create ozon_stock_reports table
SOURCE 001_create_ozon_stock_reports_table.sql;

-- Step 2: Create stock_report_logs table
SOURCE 002_create_stock_report_logs_table.sql;

-- Step 3: Extend inventory table schema
SOURCE 003_extend_inventory_table_schema.sql;

-- Re-enable foreign key checks
SET FOREIGN_KEY_CHECKS = 1;

-- Verify tables were created successfully
SHOW TABLES LIKE '%stock%';
DESCRIBE ozon_stock_reports;
DESCRIBE stock_report_logs;
SHOW COLUMNS FROM inventory WHERE Field IN ('report_source', 'last_report_update', 'report_code');