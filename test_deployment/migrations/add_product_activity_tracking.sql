-- ===================================================================
-- PRODUCT ACTIVITY TRACKING MIGRATION
-- ===================================================================
-- Version: 1.0
-- Date: 2025-01-16
-- Description: Add activity tracking fields and tables for Ozon active product filtering
-- Requirements: 3.2, 4.2

-- ===================================================================
-- STEP 1: Add activity tracking fields to dim_products table
-- ===================================================================

-- Add activity tracking columns to existing products table
ALTER TABLE dim_products 
ADD COLUMN is_active BOOLEAN DEFAULT FALSE COMMENT 'Whether product is currently active in marketplace',
ADD COLUMN activity_checked_at TIMESTAMP NULL COMMENT 'Last time activity status was checked',
ADD COLUMN activity_reason VARCHAR(255) NULL COMMENT 'Reason for current activity status (for debugging)';

-- Add indexes for performance optimization
CREATE INDEX idx_dim_products_is_active ON dim_products(is_active);
CREATE INDEX idx_dim_products_activity_checked ON dim_products(activity_checked_at);
CREATE INDEX idx_dim_products_active_updated ON dim_products(is_active, updated_at);

-- ===================================================================
-- STEP 2: Create product activity log table
-- ===================================================================

CREATE TABLE IF NOT EXISTS product_activity_log (
    id INT AUTO_INCREMENT PRIMARY KEY,
    product_id INT NOT NULL COMMENT 'Reference to dim_products.id',
    sku_ozon VARCHAR(255) NOT NULL COMMENT 'Ozon SKU for reference',
    previous_status BOOLEAN NULL COMMENT 'Previous activity status (NULL for first check)',
    new_status BOOLEAN NOT NULL COMMENT 'New activity status',
    reason VARCHAR(255) NULL COMMENT 'Reason for status change',
    visibility VARCHAR(50) NULL COMMENT 'Product visibility from Ozon API',
    state VARCHAR(50) NULL COMMENT 'Product state from Ozon API',
    stock_present INT NULL COMMENT 'Stock present value from Ozon API',
    has_pricing BOOLEAN NULL COMMENT 'Whether product has valid pricing',
    changed_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP COMMENT 'When status change occurred',
    
    INDEX idx_product_activity_log_product_id (product_id),
    INDEX idx_product_activity_log_sku (sku_ozon),
    INDEX idx_product_activity_log_changed_at (changed_at),
    INDEX idx_product_activity_log_status (new_status, changed_at),
    
    CONSTRAINT fk_product_activity_log_product 
        FOREIGN KEY (product_id) REFERENCES dim_products(id) 
        ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB COMMENT='Log of product activity status changes';

-- ===================================================================
-- STEP 3: Create activity monitoring table
-- ===================================================================

CREATE TABLE IF NOT EXISTS activity_monitoring_stats (
    id INT AUTO_INCREMENT PRIMARY KEY,
    check_date DATE NOT NULL COMMENT 'Date of activity check',
    total_products INT NOT NULL DEFAULT 0 COMMENT 'Total products in system',
    active_products INT NOT NULL DEFAULT 0 COMMENT 'Number of active products',
    inactive_products INT NOT NULL DEFAULT 0 COMMENT 'Number of inactive products',
    newly_active INT NOT NULL DEFAULT 0 COMMENT 'Products that became active today',
    newly_inactive INT NOT NULL DEFAULT 0 COMMENT 'Products that became inactive today',
    check_duration_seconds INT NULL COMMENT 'Time taken to perform activity check',
    api_errors INT NOT NULL DEFAULT 0 COMMENT 'Number of API errors during check',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    
    UNIQUE KEY uk_activity_monitoring_date (check_date),
    INDEX idx_activity_monitoring_created (created_at)
) ENGINE=InnoDB COMMENT='Daily statistics for product activity monitoring';

-- ===================================================================
-- STEP 4: Create view for active products statistics
-- ===================================================================

CREATE OR REPLACE VIEW v_active_products_stats AS
SELECT 
    COUNT(*) as total_products,
    SUM(CASE WHEN is_active = 1 THEN 1 ELSE 0 END) as active_products,
    SUM(CASE WHEN is_active = 0 THEN 1 ELSE 0 END) as inactive_products,
    ROUND(
        (SUM(CASE WHEN is_active = 1 THEN 1 ELSE 0 END) * 100.0) / COUNT(*), 
        2
    ) as active_percentage,
    MAX(activity_checked_at) as last_activity_check,
    COUNT(CASE WHEN activity_checked_at IS NULL THEN 1 END) as never_checked
FROM dim_products
WHERE sku_ozon IS NOT NULL;

-- ===================================================================
-- STEP 5: Create stored procedure for updating activity monitoring stats
-- ===================================================================

DELIMITER //

CREATE PROCEDURE UpdateActivityMonitoringStats()
BEGIN
    DECLARE total_count INT DEFAULT 0;
    DECLARE active_count INT DEFAULT 0;
    DECLARE inactive_count INT DEFAULT 0;
    DECLARE newly_active_count INT DEFAULT 0;
    DECLARE newly_inactive_count INT DEFAULT 0;
    DECLARE today_date DATE DEFAULT CURDATE();
    
    -- Get current counts
    SELECT 
        COUNT(*),
        SUM(CASE WHEN is_active = 1 THEN 1 ELSE 0 END),
        SUM(CASE WHEN is_active = 0 THEN 1 ELSE 0 END)
    INTO total_count, active_count, inactive_count
    FROM dim_products 
    WHERE sku_ozon IS NOT NULL;
    
    -- Get newly active products (became active today)
    SELECT COUNT(*)
    INTO newly_active_count
    FROM product_activity_log 
    WHERE DATE(changed_at) = today_date 
    AND previous_status = 0 
    AND new_status = 1;
    
    -- Get newly inactive products (became inactive today)
    SELECT COUNT(*)
    INTO newly_inactive_count
    FROM product_activity_log 
    WHERE DATE(changed_at) = today_date 
    AND previous_status = 1 
    AND new_status = 0;
    
    -- Insert or update today's stats
    INSERT INTO activity_monitoring_stats (
        check_date, 
        total_products, 
        active_products, 
        inactive_products,
        newly_active,
        newly_inactive
    ) VALUES (
        today_date,
        total_count,
        active_count,
        inactive_count,
        newly_active_count,
        newly_inactive_count
    ) ON DUPLICATE KEY UPDATE
        total_products = total_count,
        active_products = active_count,
        inactive_products = inactive_count,
        newly_active = newly_active_count,
        newly_inactive = newly_inactive_count,
        created_at = CURRENT_TIMESTAMP;
        
END //

DELIMITER ;

-- ===================================================================
-- STEP 6: Initialize activity status for existing products
-- ===================================================================

-- Set all existing products as inactive by default
-- They will be properly checked during next ETL run
UPDATE dim_products 
SET is_active = FALSE, 
    activity_reason = 'Initial migration - needs activity check',
    activity_checked_at = NULL
WHERE sku_ozon IS NOT NULL;

-- ===================================================================
-- STEP 7: Insert initial configuration settings
-- ===================================================================

-- Insert activity filtering configuration
INSERT INTO system_settings (setting_key, setting_value, description) VALUES
('ozon_filter_active_only', 'true', 'Enable filtering of active products only in Ozon ETL'),
('ozon_activity_check_interval', '3600', 'Interval in seconds between activity checks'),
('ozon_stock_threshold', '0', 'Minimum stock level for product to be considered active'),
('activity_log_retention_days', '90', 'Number of days to keep activity change logs'),
('activity_change_notifications', 'true', 'Enable notifications for significant activity changes')
ON DUPLICATE KEY UPDATE 
    setting_value = VALUES(setting_value),
    updated_at = CURRENT_TIMESTAMP;

-- ===================================================================
-- MIGRATION COMPLETE
-- ===================================================================

-- Log migration completion
INSERT INTO system_settings (setting_key, setting_value, description) VALUES
('migration_product_activity_tracking', UNIX_TIMESTAMP(), 'Timestamp when product activity tracking migration was applied')
ON DUPLICATE KEY UPDATE 
    setting_value = UNIX_TIMESTAMP(),
    updated_at = CURRENT_TIMESTAMP;