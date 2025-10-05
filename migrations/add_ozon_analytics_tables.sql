-- Migration: Add Ozon Analytics Tables
-- Description: Creates database structure for Ozon analytics integration
-- Date: 2025-01-05
-- Requirements: 4.1, 4.2

-- Create ozon_api_settings table for storing API configuration
CREATE TABLE IF NOT EXISTS ozon_api_settings (
    id INT PRIMARY KEY AUTO_INCREMENT,
    client_id VARCHAR(255) NOT NULL COMMENT 'Ozon API Client ID',
    api_key_hash VARCHAR(255) NOT NULL COMMENT 'Hashed API Key for security',
    is_active BOOLEAN DEFAULT TRUE COMMENT 'Whether this configuration is active',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    -- Indexes
    INDEX idx_active (is_active),
    INDEX idx_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci 
COMMENT='Stores Ozon API connection settings';

-- Create ozon_funnel_data table for caching funnel analytics
CREATE TABLE IF NOT EXISTS ozon_funnel_data (
    id INT PRIMARY KEY AUTO_INCREMENT,
    date_from DATE NOT NULL COMMENT 'Start date of the data period',
    date_to DATE NOT NULL COMMENT 'End date of the data period',
    product_id VARCHAR(100) NULL COMMENT 'Ozon product identifier',
    campaign_id VARCHAR(100) NULL COMMENT 'Marketing campaign identifier',
    views INT DEFAULT 0 COMMENT 'Number of product views',
    cart_additions INT DEFAULT 0 COMMENT 'Number of additions to cart',
    orders INT DEFAULT 0 COMMENT 'Number of completed orders',
    conversion_view_to_cart DECIMAL(5,2) DEFAULT 0.00 COMMENT 'Conversion rate from views to cart (%)',
    conversion_cart_to_order DECIMAL(5,2) DEFAULT 0.00 COMMENT 'Conversion rate from cart to order (%)',
    conversion_overall DECIMAL(5,2) DEFAULT 0.00 COMMENT 'Overall conversion rate (%)',
    cached_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP COMMENT 'When this data was cached',
    
    -- Indexes for optimization
    INDEX idx_date_range (date_from, date_to),
    INDEX idx_product (product_id),
    INDEX idx_campaign (campaign_id),
    INDEX idx_cached_at (cached_at),
    INDEX idx_product_date (product_id, date_from, date_to),
    INDEX idx_campaign_date (campaign_id, date_from, date_to)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci 
COMMENT='Cached funnel data from Ozon analytics';

-- Create ozon_demographics table for demographic analytics
CREATE TABLE IF NOT EXISTS ozon_demographics (
    id INT PRIMARY KEY AUTO_INCREMENT,
    date_from DATE NOT NULL COMMENT 'Start date of the data period',
    date_to DATE NOT NULL COMMENT 'End date of the data period',
    age_group VARCHAR(20) NULL COMMENT 'Age group (18-24, 25-34, etc.)',
    gender VARCHAR(10) NULL COMMENT 'Gender (male, female, other)',
    region VARCHAR(100) NULL COMMENT 'Geographic region',
    orders_count INT DEFAULT 0 COMMENT 'Number of orders for this demographic',
    revenue DECIMAL(12,2) DEFAULT 0.00 COMMENT 'Revenue from this demographic',
    cached_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP COMMENT 'When this data was cached',
    
    -- Indexes for optimization
    INDEX idx_date_range (date_from, date_to),
    INDEX idx_demographics (age_group, gender, region),
    INDEX idx_age_group (age_group),
    INDEX idx_gender (gender),
    INDEX idx_region (region),
    INDEX idx_cached_at (cached_at),
    INDEX idx_demo_date (age_group, gender, region, date_from, date_to)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci 
COMMENT='Demographic data from Ozon analytics';

-- Create ozon_campaigns table for campaign performance data
CREATE TABLE IF NOT EXISTS ozon_campaigns (
    id INT PRIMARY KEY AUTO_INCREMENT,
    campaign_id VARCHAR(100) NOT NULL COMMENT 'Ozon campaign identifier',
    campaign_name VARCHAR(255) NULL COMMENT 'Human-readable campaign name',
    date_from DATE NOT NULL COMMENT 'Start date of the data period',
    date_to DATE NOT NULL COMMENT 'End date of the data period',
    impressions INT DEFAULT 0 COMMENT 'Number of ad impressions',
    clicks INT DEFAULT 0 COMMENT 'Number of ad clicks',
    spend DECIMAL(12,2) DEFAULT 0.00 COMMENT 'Amount spent on advertising',
    orders INT DEFAULT 0 COMMENT 'Number of orders from campaign',
    revenue DECIMAL(12,2) DEFAULT 0.00 COMMENT 'Revenue generated from campaign',
    ctr DECIMAL(5,2) DEFAULT 0.00 COMMENT 'Click-through rate (%)',
    cpc DECIMAL(8,2) DEFAULT 0.00 COMMENT 'Cost per click',
    roas DECIMAL(8,2) DEFAULT 0.00 COMMENT 'Return on ad spend',
    cached_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP COMMENT 'When this data was cached',
    
    -- Indexes for optimization
    UNIQUE KEY unique_campaign_period (campaign_id, date_from, date_to),
    INDEX idx_campaign (campaign_id),
    INDEX idx_date_range (date_from, date_to),
    INDEX idx_campaign_name (campaign_name),
    INDEX idx_cached_at (cached_at),
    INDEX idx_performance (roas, ctr, cpc)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci 
COMMENT='Campaign performance data from Ozon analytics';