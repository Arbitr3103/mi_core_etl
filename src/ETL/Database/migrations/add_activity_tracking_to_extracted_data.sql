-- Migration: Add activity tracking fields to etl_extracted_data table
-- This migration adds fields needed for tracking product activity status

-- Add activity tracking columns to etl_extracted_data table
ALTER TABLE etl_extracted_data 
ADD COLUMN is_active BOOLEAN DEFAULT NULL COMMENT 'Whether the product is currently active for sale',
ADD COLUMN activity_checked_at TIMESTAMP NULL COMMENT 'When the activity status was last checked',
ADD COLUMN activity_reason VARCHAR(255) NULL COMMENT 'Reason for the current activity status';

-- Add index for efficient filtering by active products
CREATE INDEX idx_etl_extracted_data_active ON etl_extracted_data(is_active, source);

-- Add composite index for activity monitoring queries
CREATE INDEX idx_etl_extracted_data_activity_check ON etl_extracted_data(source, activity_checked_at, is_active);

-- Create table for tracking activity changes over time
CREATE TABLE IF NOT EXISTS etl_product_activity_log (
    id INT PRIMARY KEY AUTO_INCREMENT,
    source VARCHAR(50) NOT NULL,
    external_sku VARCHAR(255) NOT NULL,
    previous_status BOOLEAN NULL,
    new_status BOOLEAN NOT NULL,
    reason VARCHAR(255) NULL,
    changed_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    
    INDEX idx_product_activity_log_source_sku (source, external_sku),
    INDEX idx_product_activity_log_changed_at (changed_at),
    INDEX idx_product_activity_log_status_change (previous_status, new_status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='Log of product activity status changes for monitoring and analysis';

-- Create table for activity monitoring configuration
CREATE TABLE IF NOT EXISTS etl_activity_monitoring (
    id INT PRIMARY KEY AUTO_INCREMENT,
    source VARCHAR(50) NOT NULL,
    monitoring_enabled BOOLEAN DEFAULT TRUE,
    last_check_at TIMESTAMP NULL,
    active_count_current INT DEFAULT 0,
    active_count_previous INT DEFAULT 0,
    total_count_current INT DEFAULT 0,
    change_threshold_percent DECIMAL(5,2) DEFAULT 10.00,
    notification_sent_at TIMESTAMP NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    UNIQUE KEY uk_activity_monitoring_source (source)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='Configuration and status for activity monitoring by source';

-- Insert default monitoring configuration for Ozon
INSERT INTO etl_activity_monitoring (source, monitoring_enabled, change_threshold_percent)
VALUES ('ozon', TRUE, 10.00)
ON DUPLICATE KEY UPDATE
    monitoring_enabled = VALUES(monitoring_enabled),
    change_threshold_percent = VALUES(change_threshold_percent);

-- Create view for easy access to active products statistics
CREATE OR REPLACE VIEW v_etl_active_products_stats AS
SELECT 
    source,
    COUNT(*) as total_products,
    COUNT(CASE WHEN is_active = 1 THEN 1 END) as active_products,
    COUNT(CASE WHEN is_active = 0 THEN 1 END) as inactive_products,
    COUNT(CASE WHEN is_active IS NULL THEN 1 END) as unchecked_products,
    ROUND(
        COUNT(CASE WHEN is_active = 1 THEN 1 END) * 100.0 / COUNT(*), 
        2
    ) as active_percentage,
    MAX(activity_checked_at) as last_activity_check,
    MAX(extracted_at) as last_extraction
FROM etl_extracted_data
GROUP BY source;

-- Create stored procedure for updating activity monitoring stats
DELIMITER //

CREATE OR REPLACE PROCEDURE UpdateActivityMonitoringStats(IN p_source VARCHAR(50))
BEGIN
    DECLARE v_active_count INT DEFAULT 0;
    DECLARE v_total_count INT DEFAULT 0;
    DECLARE v_previous_active INT DEFAULT 0;
    DECLARE v_change_percent DECIMAL(5,2) DEFAULT 0;
    DECLARE v_threshold DECIMAL(5,2) DEFAULT 10.00;
    
    -- Get current counts
    SELECT 
        COUNT(CASE WHEN is_active = 1 THEN 1 END),
        COUNT(*)
    INTO v_active_count, v_total_count
    FROM etl_extracted_data 
    WHERE source = p_source;
    
    -- Get previous active count and threshold
    SELECT 
        COALESCE(active_count_current, 0),
        COALESCE(change_threshold_percent, 10.00)
    INTO v_previous_active, v_threshold
    FROM etl_activity_monitoring 
    WHERE source = p_source;
    
    -- Calculate change percentage
    IF v_previous_active > 0 THEN
        SET v_change_percent = ABS((v_active_count - v_previous_active) * 100.0 / v_previous_active);
    END IF;
    
    -- Update monitoring stats
    INSERT INTO etl_activity_monitoring 
    (source, last_check_at, active_count_current, active_count_previous, 
     total_count_current, monitoring_enabled, change_threshold_percent)
    VALUES 
    (p_source, NOW(), v_active_count, v_previous_active, 
     v_total_count, TRUE, v_threshold)
    ON DUPLICATE KEY UPDATE
        last_check_at = NOW(),
        active_count_previous = active_count_current,
        active_count_current = v_active_count,
        total_count_current = v_total_count,
        updated_at = NOW();
    
    -- Return change information for notification logic
    SELECT 
        v_active_count as current_active,
        v_previous_active as previous_active,
        v_total_count as total_products,
        v_change_percent as change_percent,
        v_threshold as threshold,
        (v_change_percent > v_threshold) as should_notify;
        
END //

DELIMITER ;

-- Add comments to explain the new fields
ALTER TABLE etl_extracted_data 
MODIFY COLUMN is_active BOOLEAN DEFAULT NULL 
COMMENT 'Product activity status: TRUE=active, FALSE=inactive, NULL=not checked';

ALTER TABLE etl_extracted_data 
MODIFY COLUMN activity_checked_at TIMESTAMP NULL 
COMMENT 'Timestamp when activity status was last determined';

ALTER TABLE etl_extracted_data 
MODIFY COLUMN activity_reason VARCHAR(255) NULL 
COMMENT 'Reason for current activity status (e.g., "no_stock", "not_visible", "processed")';