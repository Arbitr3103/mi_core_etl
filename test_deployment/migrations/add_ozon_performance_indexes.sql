-- Migration: Add Performance Indexes for Ozon Analytics
-- Description: Adds optimized indexes for better query performance
-- Date: 2025-01-05
-- Task: 13. Оптимизировать производительность и кэширование

-- Add composite indexes for ozon_funnel_data table
ALTER TABLE ozon_funnel_data 
ADD INDEX idx_funnel_performance (date_from, date_to, product_id, campaign_id),
ADD INDEX idx_funnel_date_product (date_from, product_id),
ADD INDEX idx_funnel_date_campaign (date_from, campaign_id),
ADD INDEX idx_funnel_conversions (conversion_overall, conversion_view_to_cart, conversion_cart_to_order),
ADD INDEX idx_funnel_metrics (views, cart_additions, orders);

-- Add composite indexes for ozon_demographics table  
ALTER TABLE ozon_demographics
ADD INDEX idx_demo_performance (date_from, date_to, age_group, gender, region),
ADD INDEX idx_demo_date_age (date_from, age_group),
ADD INDEX idx_demo_date_gender (date_from, gender),
ADD INDEX idx_demo_date_region (date_from, region),
ADD INDEX idx_demo_revenue (revenue, orders_count);

-- Add composite indexes for ozon_campaigns table
ALTER TABLE ozon_campaigns
ADD INDEX idx_campaign_performance (date_from, date_to, campaign_id),
ADD INDEX idx_campaign_metrics (roas, ctr, cpc),
ADD INDEX idx_campaign_spend (spend, revenue),
ADD INDEX idx_campaign_name_date (campaign_name, date_from);

-- Add indexes for cache table (if not already created)
CREATE TABLE IF NOT EXISTS ozon_cache (
    id INT PRIMARY KEY AUTO_INCREMENT,
    cache_key VARCHAR(255) NOT NULL,
    cache_data LONGTEXT NOT NULL,
    expires_at INT NOT NULL,
    created_at INT NOT NULL,
    data_size INT DEFAULT 0,
    access_count INT DEFAULT 0,
    last_accessed_at INT DEFAULT 0,
    
    UNIQUE KEY unique_key (cache_key),
    INDEX idx_expires (expires_at),
    INDEX idx_created (created_at),
    INDEX idx_access (last_accessed_at),
    INDEX idx_size (data_size),
    INDEX idx_access_count (access_count)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='Cache storage for Ozon analytics data';

-- Add temporary downloads table for export functionality
CREATE TABLE IF NOT EXISTS ozon_temp_downloads (
    id INT PRIMARY KEY AUTO_INCREMENT,
    token VARCHAR(64) NOT NULL,
    filename VARCHAR(255) NOT NULL,
    filepath VARCHAR(500) NOT NULL,
    content_type VARCHAR(100) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    expires_at TIMESTAMP NOT NULL,
    
    UNIQUE KEY unique_token (token),
    INDEX idx_expires (expires_at),
    INDEX idx_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='Temporary download links for exported data';

-- Add partitioning for large tables (optional, for high-volume data)
-- This is commented out as it requires careful planning and testing
-- ALTER TABLE ozon_funnel_data PARTITION BY RANGE (YEAR(date_from)) (
--     PARTITION p2024 VALUES LESS THAN (2025),
--     PARTITION p2025 VALUES LESS THAN (2026),
--     PARTITION p2026 VALUES LESS THAN (2027),
--     PARTITION p_future VALUES LESS THAN MAXVALUE
-- );

-- Create stored procedure for efficient data cleanup
DELIMITER //
CREATE PROCEDURE CleanupOzonAnalyticsData()
BEGIN
    DECLARE EXIT HANDLER FOR SQLEXCEPTION
    BEGIN
        ROLLBACK;
        RESIGNAL;
    END;
    
    START TRANSACTION;
    
    -- Clean up expired cache entries
    DELETE FROM ozon_cache WHERE expires_at <= UNIX_TIMESTAMP();
    
    -- Clean up expired temporary downloads
    DELETE FROM ozon_temp_downloads WHERE expires_at <= NOW();
    
    -- Clean up old analytics data (older than 1 year)
    DELETE FROM ozon_funnel_data WHERE date_to < DATE_SUB(CURDATE(), INTERVAL 1 YEAR);
    DELETE FROM ozon_demographics WHERE date_to < DATE_SUB(CURDATE(), INTERVAL 1 YEAR);
    DELETE FROM ozon_campaigns WHERE date_to < DATE_SUB(CURDATE(), INTERVAL 1 YEAR);
    
    COMMIT;
    
    -- Optimize tables after cleanup
    OPTIMIZE TABLE ozon_cache, ozon_temp_downloads, ozon_funnel_data, ozon_demographics, ozon_campaigns;
END //
DELIMITER ;

-- Create event scheduler for automatic cleanup (runs daily at 2 AM)
CREATE EVENT IF NOT EXISTS ozon_daily_cleanup
ON SCHEDULE EVERY 1 DAY
STARTS TIMESTAMP(CURDATE() + INTERVAL 1 DAY, '02:00:00')
DO
  CALL CleanupOzonAnalyticsData();

-- Enable event scheduler if not already enabled
-- SET GLOBAL event_scheduler = ON;