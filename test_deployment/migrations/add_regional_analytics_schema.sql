-- Migration: Add Regional Analytics Schema
-- Description: Creates database structure for regional sales analytics system
-- Date: 2025-10-20
-- Requirements: 5.3

-- Create ozon_regional_sales table for storing regional sales data from Ozon API
CREATE TABLE IF NOT EXISTS ozon_regional_sales (
    id INT PRIMARY KEY AUTO_INCREMENT,
    date_from DATE NOT NULL COMMENT 'Start date of the data period',
    date_to DATE NOT NULL COMMENT 'End date of the data period',
    region_id VARCHAR(50) NULL COMMENT 'Ozon region identifier',
    federal_district VARCHAR(100) NULL COMMENT 'Federal district name',
    offer_id VARCHAR(255) NULL COMMENT 'Ozon offer identifier',
    product_id INT NULL COMMENT 'Internal product ID reference',
    sku VARCHAR(255) NULL COMMENT 'Product SKU',
    sales_qty INT DEFAULT 0 COMMENT 'Number of units sold',
    sales_amount DECIMAL(12,2) DEFAULT 0.00 COMMENT 'Total sales amount in rubles',
    orders_count INT DEFAULT 0 COMMENT 'Number of orders',
    marketplace VARCHAR(50) DEFAULT 'OZON' COMMENT 'Marketplace identifier',
    synced_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP COMMENT 'When this data was synced from API',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    -- Indexes for performance optimization
    INDEX idx_date_range (date_from, date_to),
    INDEX idx_region (region_id),
    INDEX idx_federal_district (federal_district),
    INDEX idx_product (product_id),
    INDEX idx_offer (offer_id),
    INDEX idx_sku (sku),
    INDEX idx_marketplace (marketplace),
    INDEX idx_synced_at (synced_at),
    INDEX idx_date_region (date_from, date_to, region_id),
    INDEX idx_product_date (product_id, date_from, date_to),
    INDEX idx_region_product (region_id, product_id),
    INDEX idx_district_date (federal_district, date_from, date_to),
    
    -- Composite indexes for common queries
    INDEX idx_analytics_main (date_from, date_to, marketplace, region_id),
    INDEX idx_product_analytics (product_id, marketplace, date_from, date_to),
    
    -- Foreign key constraint
    FOREIGN KEY (product_id) REFERENCES dim_products(id) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci 
COMMENT='Regional sales data from Ozon API for analytics';

-- Create enhanced regions table for regional reference data
CREATE TABLE IF NOT EXISTS regions (
    id INT PRIMARY KEY AUTO_INCREMENT,
    region_code VARCHAR(50) UNIQUE NOT NULL COMMENT 'Unique region code from Ozon API',
    region_name VARCHAR(200) NOT NULL COMMENT 'Human-readable region name',
    federal_district VARCHAR(100) NULL COMMENT 'Federal district name',
    federal_district_code VARCHAR(10) NULL COMMENT 'Federal district code',
    timezone VARCHAR(50) NULL COMMENT 'Region timezone',
    population INT NULL COMMENT 'Region population for analytics',
    economic_zone VARCHAR(100) NULL COMMENT 'Economic zone classification',
    is_active BOOLEAN DEFAULT TRUE COMMENT 'Whether region is active for analytics',
    priority INT DEFAULT 0 COMMENT 'Priority for reporting (higher = more important)',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    -- Indexes for performance optimization
    UNIQUE KEY unique_region_code (region_code),
    INDEX idx_region_name (region_name),
    INDEX idx_federal_district (federal_district),
    INDEX idx_federal_district_code (federal_district_code),
    INDEX idx_active (is_active),
    INDEX idx_priority (priority),
    INDEX idx_economic_zone (economic_zone),
    INDEX idx_district_active (federal_district, is_active),
    INDEX idx_priority_active (priority, is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci 
COMMENT='Regional reference data for analytics';

-- Create regional_analytics_cache table for performance optimization
CREATE TABLE IF NOT EXISTS regional_analytics_cache (
    id INT PRIMARY KEY AUTO_INCREMENT,
    cache_key VARCHAR(255) NOT NULL COMMENT 'Unique cache key',
    cache_type VARCHAR(50) NOT NULL COMMENT 'Type of cached data (marketplace_comparison, top_products, etc.)',
    date_from DATE NOT NULL COMMENT 'Start date of cached data',
    date_to DATE NOT NULL COMMENT 'End date of cached data',
    region_filter VARCHAR(100) NULL COMMENT 'Region filter applied',
    marketplace_filter VARCHAR(50) NULL COMMENT 'Marketplace filter applied',
    cache_data JSON NOT NULL COMMENT 'Cached analytics data in JSON format',
    expires_at TIMESTAMP NOT NULL COMMENT 'When this cache expires',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    
    -- Indexes for cache performance
    UNIQUE KEY unique_cache_key (cache_key),
    INDEX idx_cache_type (cache_type),
    INDEX idx_date_range (date_from, date_to),
    INDEX idx_expires_at (expires_at),
    INDEX idx_region_filter (region_filter),
    INDEX idx_marketplace_filter (marketplace_filter),
    INDEX idx_type_date (cache_type, date_from, date_to),
    INDEX idx_active_cache (cache_type, expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci 
COMMENT='Cache table for regional analytics performance optimization';

-- Insert initial federal districts data
INSERT INTO regions (region_code, region_name, federal_district, federal_district_code, is_active, priority) VALUES
('RU-MOW', 'Москва', 'Центральный федеральный округ', 'ЦФО', TRUE, 100),
('RU-SPE', 'Санкт-Петербург', 'Северо-Западный федеральный округ', 'СЗФО', TRUE, 95),
('RU-MOS', 'Московская область', 'Центральный федеральный округ', 'ЦФО', TRUE, 90),
('RU-LEN', 'Ленинградская область', 'Северо-Западный федеральный округ', 'СЗФО', TRUE, 85),
('RU-KRA', 'Краснодарский край', 'Южный федеральный округ', 'ЮФО', TRUE, 80),
('RU-ROS', 'Ростовская область', 'Южный федеральный округ', 'ЮФО', TRUE, 75),
('RU-SVE', 'Свердловская область', 'Уральский федеральный округ', 'УФО', TRUE, 70),
('RU-CHE', 'Челябинская область', 'Уральский федеральный округ', 'УФО', TRUE, 65),
('RU-TYU', 'Тюменская область', 'Уральский федеральный округ', 'УФО', TRUE, 60),
('RU-NSK', 'Новосибирская область', 'Сибирский федеральный округ', 'СФО', TRUE, 55)
ON DUPLICATE KEY UPDATE 
    region_name = VALUES(region_name),
    federal_district = VALUES(federal_district),
    federal_district_code = VALUES(federal_district_code),
    priority = VALUES(priority);

-- Create view for easy regional analytics queries
CREATE OR REPLACE VIEW v_regional_sales_summary AS
SELECT 
    r.region_name,
    r.federal_district,
    ors.marketplace,
    DATE_FORMAT(ors.date_from, '%Y-%m') as month_year,
    COUNT(DISTINCT ors.offer_id) as unique_products,
    SUM(ors.sales_qty) as total_qty,
    SUM(ors.sales_amount) as total_revenue,
    SUM(ors.orders_count) as total_orders,
    ROUND(AVG(ors.sales_amount / NULLIF(ors.sales_qty, 0)), 2) as avg_price_per_unit,
    ROUND(SUM(ors.sales_amount) / NULLIF(SUM(ors.orders_count), 0), 2) as avg_order_value
FROM ozon_regional_sales ors
LEFT JOIN regions r ON ors.region_id = r.region_code
WHERE ors.sales_qty > 0 OR ors.sales_amount > 0
GROUP BY r.region_name, r.federal_district, ors.marketplace, DATE_FORMAT(ors.date_from, '%Y-%m')
ORDER BY total_revenue DESC;

-- Create view for marketplace comparison
CREATE OR REPLACE VIEW v_marketplace_comparison AS
SELECT 
    dp.product_name,
    dp.brand,
    dp.sku_ozon,
    dp.sku_wb,
    -- Ozon data
    COALESCE(ozon_data.total_qty, 0) as ozon_qty,
    COALESCE(ozon_data.total_revenue, 0) as ozon_revenue,
    COALESCE(ozon_data.total_orders, 0) as ozon_orders,
    COALESCE(ozon_data.avg_price, 0) as ozon_avg_price,
    -- Wildberries data (from fact_orders)
    COALESCE(wb_data.total_qty, 0) as wb_qty,
    COALESCE(wb_data.total_revenue, 0) as wb_revenue,
    COALESCE(wb_data.total_orders, 0) as wb_orders,
    COALESCE(wb_data.avg_price, 0) as wb_avg_price,
    -- Combined totals
    COALESCE(ozon_data.total_qty, 0) + COALESCE(wb_data.total_qty, 0) as total_qty,
    COALESCE(ozon_data.total_revenue, 0) + COALESCE(wb_data.total_revenue, 0) as total_revenue,
    COALESCE(ozon_data.total_orders, 0) + COALESCE(wb_data.total_orders, 0) as total_orders
FROM dim_products dp
LEFT JOIN (
    SELECT 
        product_id,
        SUM(sales_qty) as total_qty,
        SUM(sales_amount) as total_revenue,
        SUM(orders_count) as total_orders,
        ROUND(AVG(sales_amount / NULLIF(sales_qty, 0)), 2) as avg_price
    FROM ozon_regional_sales 
    WHERE marketplace = 'OZON'
    GROUP BY product_id
) ozon_data ON dp.id = ozon_data.product_id
LEFT JOIN (
    SELECT 
        fo.product_id,
        SUM(fo.qty) as total_qty,
        SUM(fo.price * fo.qty) as total_revenue,
        COUNT(*) as total_orders,
        ROUND(AVG(fo.price), 2) as avg_price
    FROM fact_orders fo
    JOIN dim_sources ds ON fo.source_id = ds.id
    WHERE ds.code IN ('wb', 'wildberries')
    GROUP BY fo.product_id
) wb_data ON dp.id = wb_data.product_id
WHERE dp.is_active = 1
ORDER BY total_revenue DESC;