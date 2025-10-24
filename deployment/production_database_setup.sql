-- Production Database Setup for Ozon Warehouse Stock Reports
-- This script applies all necessary database migrations and security configurations
-- Requirements: 2.4, 5.5

-- ===================================================================
-- STEP 1: Apply Database Migrations
-- ===================================================================

-- Create ozon_stock_reports table
CREATE TABLE IF NOT EXISTS ozon_stock_reports (
    id INT AUTO_INCREMENT PRIMARY KEY,
    report_code VARCHAR(255) NOT NULL UNIQUE,
    report_type ENUM('warehouse_stock') NOT NULL,
    status ENUM('REQUESTED', 'PROCESSING', 'SUCCESS', 'ERROR', 'TIMEOUT') NOT NULL,
    request_parameters JSON,
    download_url VARCHAR(500) NULL,
    file_size INT NULL,
    records_processed INT DEFAULT 0,
    error_message TEXT NULL,
    requested_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    completed_at TIMESTAMP NULL,
    processed_at TIMESTAMP NULL,
    
    -- Indexes for performance optimization
    INDEX idx_status (status),
    INDEX idx_requested_at (requested_at),
    INDEX idx_report_type (report_type),
    INDEX idx_report_code (report_code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Create stock_report_logs table
CREATE TABLE IF NOT EXISTS stock_report_logs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    report_code VARCHAR(255) NOT NULL,
    log_level ENUM('INFO', 'WARNING', 'ERROR') NOT NULL,
    message TEXT NOT NULL,
    context JSON NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    
    -- Indexes for efficient log querying and filtering
    INDEX idx_report_code (report_code),
    INDEX idx_log_level (log_level),
    INDEX idx_created_at (created_at),
    INDEX idx_log_level_created (log_level, created_at),
    
    -- Foreign key constraint to reports table
    FOREIGN KEY (report_code) REFERENCES ozon_stock_reports(report_code) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Extend inventory table with report-specific fields
ALTER TABLE inventory
ADD COLUMN IF NOT EXISTS report_source ENUM('API_DIRECT', 'API_REPORTS') DEFAULT 'API_DIRECT' COMMENT 'Source of inventory data',
ADD COLUMN IF NOT EXISTS last_report_update TIMESTAMP NULL COMMENT 'Timestamp of last report-based update',
ADD COLUMN IF NOT EXISTS report_code VARCHAR(255) NULL COMMENT 'Reference to the report that updated this record';

-- Create indexes for new fields (only if they don't exist)
ALTER TABLE inventory
ADD INDEX IF NOT EXISTS idx_report_source (report_source),
ADD INDEX IF NOT EXISTS idx_last_report_update (last_report_update),
ADD INDEX IF NOT EXISTS idx_report_code (report_code),
ADD INDEX IF NOT EXISTS idx_report_source_update (report_source, last_report_update);

-- Add foreign key constraint (only if it doesn't exist)
-- Check if constraint exists first
SET @constraint_exists = (
    SELECT COUNT(*)
    FROM information_schema.TABLE_CONSTRAINTS
    WHERE CONSTRAINT_SCHEMA = DATABASE()
    AND TABLE_NAME = 'inventory'
    AND CONSTRAINT_NAME = 'fk_inventory_report_code'
);

SET @sql = IF(@constraint_exists = 0,
    'ALTER TABLE inventory ADD CONSTRAINT fk_inventory_report_code FOREIGN KEY (report_code) REFERENCES ozon_stock_reports(report_code) ON DELETE SET NULL',
    'SELECT "Foreign key constraint already exists" as message'
);

PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- ===================================================================
-- STEP 2: Database Security Configuration
-- ===================================================================

-- Create dedicated user for ozon stock reports ETL
CREATE USER IF NOT EXISTS 'ozon_etl_user'@'localhost' IDENTIFIED BY 'CHANGE_THIS_PASSWORD_IN_PRODUCTION';
CREATE USER IF NOT EXISTS 'ozon_etl_user'@'%' IDENTIFIED BY 'CHANGE_THIS_PASSWORD_IN_PRODUCTION';

-- Grant necessary permissions for ETL operations
GRANT SELECT, INSERT, UPDATE ON *.ozon_stock_reports TO 'ozon_etl_user'@'localhost';
GRANT SELECT, INSERT, UPDATE ON *.stock_report_logs TO 'ozon_etl_user'@'localhost';
GRANT SELECT, INSERT, UPDATE ON *.inventory TO 'ozon_etl_user'@'localhost';
GRANT SELECT ON *.products TO 'ozon_etl_user'@'localhost';

GRANT SELECT, INSERT, UPDATE ON *.ozon_stock_reports TO 'ozon_etl_user'@'%';
GRANT SELECT, INSERT, UPDATE ON *.stock_report_logs TO 'ozon_etl_user'@'%';
GRANT SELECT, INSERT, UPDATE ON *.inventory TO 'ozon_etl_user'@'%';
GRANT SELECT ON *.products TO 'ozon_etl_user'@'%';

-- Create read-only user for monitoring and reporting
CREATE USER IF NOT EXISTS 'ozon_readonly_user'@'localhost' IDENTIFIED BY 'CHANGE_THIS_READONLY_PASSWORD';
CREATE USER IF NOT EXISTS 'ozon_readonly_user'@'%' IDENTIFIED BY 'CHANGE_THIS_READONLY_PASSWORD';

-- Grant read-only permissions
GRANT SELECT ON *.ozon_stock_reports TO 'ozon_readonly_user'@'localhost';
GRANT SELECT ON *.stock_report_logs TO 'ozon_readonly_user'@'localhost';
GRANT SELECT ON *.inventory TO 'ozon_readonly_user'@'localhost';

GRANT SELECT ON *.ozon_stock_reports TO 'ozon_readonly_user'@'%';
GRANT SELECT ON *.stock_report_logs TO 'ozon_readonly_user'@'%';
GRANT SELECT ON *.inventory TO 'ozon_readonly_user'@'%';

-- Flush privileges to apply changes
FLUSH PRIVILEGES;

-- ===================================================================
-- STEP 3: Performance Optimization
-- ===================================================================

-- Analyze tables for query optimization
ANALYZE TABLE ozon_stock_reports;
ANALYZE TABLE stock_report_logs;
ANALYZE TABLE inventory;

-- ===================================================================
-- STEP 4: Data Validation
-- ===================================================================

-- Verify table creation
SELECT 
    TABLE_NAME,
    TABLE_ROWS,
    DATA_LENGTH,
    INDEX_LENGTH,
    CREATE_TIME
FROM information_schema.TABLES 
WHERE TABLE_SCHEMA = DATABASE() 
AND TABLE_NAME IN ('ozon_stock_reports', 'stock_report_logs', 'inventory');

-- Verify indexes
SELECT 
    TABLE_NAME,
    INDEX_NAME,
    COLUMN_NAME,
    SEQ_IN_INDEX
FROM information_schema.STATISTICS 
WHERE TABLE_SCHEMA = DATABASE() 
AND TABLE_NAME IN ('ozon_stock_reports', 'stock_report_logs', 'inventory')
ORDER BY TABLE_NAME, INDEX_NAME, SEQ_IN_INDEX;

-- Verify foreign key constraints
SELECT 
    CONSTRAINT_NAME,
    TABLE_NAME,
    COLUMN_NAME,
    REFERENCED_TABLE_NAME,
    REFERENCED_COLUMN_NAME
FROM information_schema.KEY_COLUMN_USAGE 
WHERE TABLE_SCHEMA = DATABASE() 
AND REFERENCED_TABLE_NAME IS NOT NULL
AND TABLE_NAME IN ('stock_report_logs', 'inventory');

-- Show created users (excluding passwords)
SELECT User, Host FROM mysql.user WHERE User LIKE 'ozon_%';

SELECT 'Production database setup completed successfully!' as status;