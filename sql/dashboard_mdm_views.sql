-- Dashboard MDM Views
-- Creates views that integrate master data with existing analytics

-- Enhanced inventory view with master data
CREATE OR REPLACE VIEW v_inventory_enhanced AS
SELECT 
    i.sku,
    i.current_stock,
    i.reserved_stock,
    i.warehouse_name,
    i.source,
    i.stock_type,
    -- Master data fields
    mp.master_id,
    COALESCE(mp.canonical_name, i.sku) as display_name,
    COALESCE(mp.canonical_brand, 'Неизвестный бренд') as canonical_brand,
    COALESCE(mp.canonical_category, 'Без категории') as canonical_category,
    mp.description,
    mp.attributes,
    -- SKU mapping info
    sm.confidence_score,
    sm.verification_status,
    sm.source_name as original_name,
    sm.source_brand as original_brand,
    -- Data quality indicators
    CASE 
        WHEN mp.master_id IS NOT NULL THEN 'master_data'
        WHEN sm.external_sku IS NOT NULL THEN 'mapped'
        ELSE 'raw_data'
    END as data_quality_level,
    -- Stock level classification
    CASE 
        WHEN i.current_stock > 100 THEN 'high'
        WHEN i.current_stock > 20 THEN 'medium'
        WHEN i.current_stock > 0 THEN 'low'
        ELSE 'empty'
    END as stock_level,
    -- Demand indicators
    CASE 
        WHEN i.reserved_stock > 0 AND i.current_stock < 10 THEN 'high_demand_low_stock'
        WHEN i.reserved_stock > 0 THEN 'has_demand'
        WHEN i.current_stock > 100 AND i.reserved_stock = 0 THEN 'overstocked'
        ELSE 'normal'
    END as demand_status,
    -- Timestamps
    i.created_at as inventory_created_at,
    i.updated_at as inventory_updated_at,
    mp.created_at as master_created_at,
    mp.updated_at as master_updated_at
FROM inventory_data i
LEFT JOIN sku_mapping sm ON i.sku = sm.external_sku
LEFT JOIN master_products mp ON sm.master_id = mp.master_id AND mp.status = 'active';

-- Brand analytics view with master data
CREATE OR REPLACE VIEW v_brand_analytics AS
SELECT 
    mp.canonical_brand as brand_name,
    COUNT(DISTINCT mp.master_id) as master_products_count,
    COUNT(DISTINCT sm.external_sku) as mapped_skus_count,
    COUNT(DISTINCT i.sku) as inventory_skus_count,
    SUM(i.current_stock) as total_stock,
    SUM(i.reserved_stock) as total_reserved,
    AVG(i.current_stock) as avg_stock_per_sku,
    -- Quality metrics
    COUNT(DISTINCT CASE WHEN mp.canonical_category IS NOT NULL AND mp.canonical_category != 'Без категории' THEN mp.master_id END) as products_with_category,
    COUNT(DISTINCT CASE WHEN mp.description IS NOT NULL AND mp.description != '' THEN mp.master_id END) as products_with_description,
    ROUND((COUNT(DISTINCT CASE WHEN mp.canonical_category IS NOT NULL AND mp.canonical_category != 'Без категории' THEN mp.master_id END) / COUNT(DISTINCT mp.master_id)) * 100, 2) as category_completeness_percentage,
    ROUND((COUNT(DISTINCT CASE WHEN mp.description IS NOT NULL AND mp.description != '' THEN mp.master_id END) / COUNT(DISTINCT mp.master_id)) * 100, 2) as description_completeness_percentage,
    -- Stock level distribution
    COUNT(DISTINCT CASE WHEN i.current_stock > 100 THEN i.sku END) as high_stock_skus,
    COUNT(DISTINCT CASE WHEN i.current_stock BETWEEN 20 AND 100 THEN i.sku END) as medium_stock_skus,
    COUNT(DISTINCT CASE WHEN i.current_stock BETWEEN 1 AND 19 THEN i.sku END) as low_stock_skus,
    COUNT(DISTINCT CASE WHEN i.current_stock = 0 THEN i.sku END) as empty_stock_skus,
    -- Demand indicators
    COUNT(DISTINCT CASE WHEN i.reserved_stock > 0 THEN i.sku END) as skus_with_demand,
    COUNT(DISTINCT CASE WHEN i.reserved_stock > 0 AND i.current_stock < 10 THEN i.sku END) as critical_demand_skus,
    ROUND((COUNT(DISTINCT CASE WHEN i.reserved_stock > 0 THEN i.sku END) / COUNT(DISTINCT i.sku)) * 100, 2) as demand_percentage,
    -- Performance indicators
    ROUND((SUM(i.reserved_stock) / NULLIF(SUM(i.current_stock), 0)) * 100, 2) as demand_ratio,
    -- Data sources
    COUNT(DISTINCT sm.source) as data_sources_count,
    GROUP_CONCAT(DISTINCT sm.source) as data_sources
FROM master_products mp
LEFT JOIN sku_mapping sm ON mp.master_id = sm.master_id
LEFT JOIN inventory_data i ON sm.external_sku = i.sku
WHERE mp.canonical_brand IS NOT NULL 
AND mp.canonical_brand != 'Неизвестный бренд'
AND mp.status = 'active'
GROUP BY mp.canonical_brand
HAVING total_stock > 0
ORDER BY total_stock DESC;

-- Category analytics view with master data
CREATE OR REPLACE VIEW v_category_analytics AS
SELECT 
    mp.canonical_category as category_name,
    COUNT(DISTINCT mp.master_id) as master_products_count,
    COUNT(DISTINCT sm.external_sku) as mapped_skus_count,
    COUNT(DISTINCT i.sku) as inventory_skus_count,
    SUM(i.current_stock) as total_stock,
    SUM(i.reserved_stock) as total_reserved,
    AVG(i.current_stock) as avg_stock_per_sku,
    -- Brand diversity
    COUNT(DISTINCT mp.canonical_brand) as unique_brands,
    -- Stock performance
    ROUND(AVG(CASE WHEN i.current_stock > 0 THEN 100 ELSE 0 END), 2) as availability_percentage,
    ROUND(AVG(CASE WHEN i.reserved_stock > 0 THEN 100 ELSE 0 END), 2) as demand_percentage,
    -- Stock distribution
    COUNT(DISTINCT CASE WHEN i.current_stock > 100 THEN i.sku END) as high_stock_skus,
    COUNT(DISTINCT CASE WHEN i.current_stock BETWEEN 20 AND 100 THEN i.sku END) as medium_stock_skus,
    COUNT(DISTINCT CASE WHEN i.current_stock BETWEEN 1 AND 19 THEN i.sku END) as low_stock_skus,
    -- Performance metrics
    ROUND((SUM(i.reserved_stock) / NULLIF(SUM(i.current_stock), 0)) * 100, 2) as turnover_ratio,
    -- Top brand in category
    (
        SELECT mp2.canonical_brand 
        FROM master_products mp2
        LEFT JOIN sku_mapping sm2 ON mp2.master_id = sm2.master_id
        LEFT JOIN inventory_data i2 ON sm2.external_sku = i2.sku
        WHERE mp2.canonical_category = mp.canonical_category
        AND mp2.status = 'active'
        GROUP BY mp2.canonical_brand
        ORDER BY SUM(i2.current_stock) DESC
        LIMIT 1
    ) as top_brand_by_stock
FROM master_products mp
LEFT JOIN sku_mapping sm ON mp.master_id = sm.master_id
LEFT JOIN inventory_data i ON sm.external_sku = i.sku
WHERE mp.canonical_category IS NOT NULL 
AND mp.canonical_category != 'Без категории'
AND mp.status = 'active'
GROUP BY mp.canonical_category
HAVING total_stock > 0
ORDER BY total_stock DESC;

-- Critical stock alerts view
CREATE OR REPLACE VIEW v_critical_stock_alerts AS
SELECT 
    i.sku,
    i.current_stock,
    i.reserved_stock,
    i.warehouse_name,
    i.source,
    -- Master data context
    mp.master_id,
    COALESCE(mp.canonical_name, i.sku) as display_name,
    COALESCE(mp.canonical_brand, 'Неизвестный бренд') as canonical_brand,
    COALESCE(mp.canonical_category, 'Без категории') as canonical_category,
    -- Alert classification
    CASE 
        WHEN i.current_stock = 0 AND i.reserved_stock > 0 THEN 'out_of_stock_with_demand'
        WHEN i.current_stock < 3 AND i.reserved_stock > 0 THEN 'critical_with_demand'
        WHEN i.current_stock < 3 THEN 'critical_no_demand'
        WHEN i.current_stock < 10 AND i.reserved_stock > 0 THEN 'low_with_demand'
        ELSE 'low_stock'
    END as alert_type,
    -- Priority scoring (1 = highest priority)
    CASE 
        WHEN i.current_stock = 0 AND i.reserved_stock > 0 THEN 1
        WHEN i.current_stock < 3 AND i.reserved_stock > 0 THEN 2
        WHEN i.current_stock < 3 THEN 3
        WHEN i.current_stock < 10 AND i.reserved_stock > 0 THEN 4
        ELSE 5
    END as priority,
    -- Data quality indicator
    CASE 
        WHEN mp.master_id IS NOT NULL THEN 'master_data'
        WHEN sm.external_sku IS NOT NULL THEN 'mapped'
        ELSE 'raw_data'
    END as data_quality_level,
    -- Additional context
    sm.confidence_score,
    sm.verification_status,
    i.updated_at as last_inventory_update
FROM inventory_data i
LEFT JOIN sku_mapping sm ON i.sku = sm.external_sku
LEFT JOIN master_products mp ON sm.master_id = mp.master_id AND mp.status = 'active'
WHERE i.current_stock < 10
ORDER BY priority ASC, i.reserved_stock DESC, i.current_stock ASC;

-- Data quality summary view
CREATE OR REPLACE VIEW v_data_quality_summary AS
SELECT 
    'master_data_coverage' as metric_name,
    ROUND((COUNT(DISTINCT CASE WHEN mp.master_id IS NOT NULL THEN i.sku END) / COUNT(DISTINCT i.sku)) * 100, 2) as percentage,
    COUNT(DISTINCT i.sku) as total_items,
    COUNT(DISTINCT CASE WHEN mp.master_id IS NOT NULL THEN i.sku END) as good_items,
    'Процент SKU с мастер-данными' as description
FROM inventory_data i
LEFT JOIN sku_mapping sm ON i.sku = sm.external_sku
LEFT JOIN master_products mp ON sm.master_id = mp.master_id AND mp.status = 'active'

UNION ALL

SELECT 
    'brand_completeness' as metric_name,
    ROUND((COUNT(CASE WHEN canonical_brand IS NOT NULL AND canonical_brand != 'Неизвестный бренд' THEN 1 END) / COUNT(*)) * 100, 2) as percentage,
    COUNT(*) as total_items,
    COUNT(CASE WHEN canonical_brand IS NOT NULL AND canonical_brand != 'Неизвестный бренд' THEN 1 END) as good_items,
    'Процент товаров с корректным брендом' as description
FROM master_products
WHERE status = 'active'

UNION ALL

SELECT 
    'category_completeness' as metric_name,
    ROUND((COUNT(CASE WHEN canonical_category IS NOT NULL AND canonical_category != 'Без категории' THEN 1 END) / COUNT(*)) * 100, 2) as percentage,
    COUNT(*) as total_items,
    COUNT(CASE WHEN canonical_category IS NOT NULL AND canonical_category != 'Без категории' THEN 1 END) as good_items,
    'Процент товаров с категорией' as description
FROM master_products
WHERE status = 'active'

UNION ALL

SELECT 
    'description_completeness' as metric_name,
    ROUND((COUNT(CASE WHEN description IS NOT NULL AND description != '' THEN 1 END) / COUNT(*)) * 100, 2) as percentage,
    COUNT(*) as total_items,
    COUNT(CASE WHEN description IS NOT NULL AND description != '' THEN 1 END) as good_items,
    'Процент товаров с описанием' as description
FROM master_products
WHERE status = 'active'

UNION ALL

SELECT 
    'verification_completeness' as metric_name,
    ROUND((COUNT(CASE WHEN verification_status IN ('auto', 'manual') THEN 1 END) / COUNT(*)) * 100, 2) as percentage,
    COUNT(*) as total_items,
    COUNT(CASE WHEN verification_status IN ('auto', 'manual') THEN 1 END) as good_items,
    'Процент верифицированных сопоставлений' as description
FROM sku_mapping;

-- Top performing products view
CREATE OR REPLACE VIEW v_top_performing_products AS
SELECT 
    mp.master_id,
    mp.canonical_name,
    mp.canonical_brand,
    mp.canonical_category,
    COUNT(DISTINCT sm.external_sku) as mapped_skus_count,
    SUM(i.current_stock) as total_stock,
    SUM(i.reserved_stock) as total_reserved,
    AVG(i.current_stock) as avg_stock_per_sku,
    -- Performance indicators
    ROUND((SUM(i.reserved_stock) / NULLIF(SUM(i.current_stock), 0)) * 100, 2) as demand_ratio,
    COUNT(DISTINCT i.source) as data_sources_count,
    GROUP_CONCAT(DISTINCT i.source) as data_sources,
    -- Quality score (0-3 based on completeness)
    (CASE WHEN mp.canonical_brand IS NOT NULL AND mp.canonical_brand != 'Неизвестный бренд' THEN 1 ELSE 0 END +
     CASE WHEN mp.canonical_category IS NOT NULL AND mp.canonical_category != 'Без категории' THEN 1 ELSE 0 END +
     CASE WHEN mp.description IS NOT NULL AND mp.description != '' THEN 1 ELSE 0 END) as quality_score,
    -- Stock performance classification
    CASE 
        WHEN SUM(i.reserved_stock) > 0 AND SUM(i.current_stock) < 20 THEN 'high_demand_low_stock'
        WHEN SUM(i.reserved_stock) > 0 THEN 'good_performance'
        WHEN SUM(i.current_stock) > 200 AND SUM(i.reserved_stock) = 0 THEN 'overstocked'
        ELSE 'normal'
    END as performance_category
FROM master_products mp
INNER JOIN sku_mapping sm ON mp.master_id = sm.master_id
INNER JOIN inventory_data i ON sm.external_sku = i.sku
WHERE mp.status = 'active'
AND i.current_stock > 0
GROUP BY mp.master_id, mp.canonical_name, mp.canonical_brand, mp.canonical_category
ORDER BY total_reserved DESC, total_stock DESC;

-- Marketing insights view
CREATE OR REPLACE VIEW v_marketing_insights AS
SELECT 
    'brand_performance' as insight_type,
    mp.canonical_brand as entity_name,
    COUNT(DISTINCT mp.master_id) as products_count,
    SUM(i.current_stock) as total_stock,
    SUM(i.reserved_stock) as total_demand,
    ROUND((SUM(i.reserved_stock) / NULLIF(SUM(i.current_stock), 0)) * 100, 2) as performance_score,
    CASE 
        WHEN SUM(i.reserved_stock) > 0 AND SUM(i.current_stock) / SUM(i.reserved_stock) < 5 THEN 'Высокий спрос, нужно пополнение'
        WHEN SUM(i.reserved_stock) = 0 AND SUM(i.current_stock) > 100 THEN 'Избыток товара, нужна реклама'
        WHEN SUM(i.reserved_stock) > 0 THEN 'Стабильный спрос'
        ELSE 'Требует анализа'
    END as recommendation
FROM master_products mp
INNER JOIN sku_mapping sm ON mp.master_id = sm.master_id
INNER JOIN inventory_data i ON sm.external_sku = i.sku
WHERE mp.canonical_brand IS NOT NULL 
AND mp.canonical_brand != 'Неизвестный бренд'
AND mp.status = 'active'
GROUP BY mp.canonical_brand
HAVING total_stock > 0

UNION ALL

SELECT 
    'category_performance' as insight_type,
    mp.canonical_category as entity_name,
    COUNT(DISTINCT mp.master_id) as products_count,
    SUM(i.current_stock) as total_stock,
    SUM(i.reserved_stock) as total_demand,
    ROUND((SUM(i.reserved_stock) / NULLIF(SUM(i.current_stock), 0)) * 100, 2) as performance_score,
    CASE 
        WHEN SUM(i.reserved_stock) > 0 AND SUM(i.current_stock) / SUM(i.reserved_stock) < 5 THEN 'Высокий спрос в категории'
        WHEN SUM(i.reserved_stock) = 0 AND SUM(i.current_stock) > 100 THEN 'Категория требует продвижения'
        WHEN SUM(i.reserved_stock) > 0 THEN 'Стабильная категория'
        ELSE 'Анализ категории'
    END as recommendation
FROM master_products mp
INNER JOIN sku_mapping sm ON mp.master_id = sm.master_id
INNER JOIN inventory_data i ON sm.external_sku = i.sku
WHERE mp.canonical_category IS NOT NULL 
AND mp.canonical_category != 'Без категории'
AND mp.status = 'active'
GROUP BY mp.canonical_category
HAVING total_stock > 0

ORDER BY performance_score DESC;

-- Create indexes for better performance
CREATE INDEX IF NOT EXISTS idx_inventory_enhanced_master_id ON inventory_data(sku);
CREATE INDEX IF NOT EXISTS idx_inventory_enhanced_stock_level ON inventory_data(current_stock, reserved_stock);
CREATE INDEX IF NOT EXISTS idx_master_products_brand_category ON master_products(canonical_brand, canonical_category, status);
CREATE INDEX IF NOT EXISTS idx_sku_mapping_verification ON sku_mapping(verification_status, confidence_score);
CREATE INDEX IF NOT EXISTS idx_data_quality_metrics_date ON data_quality_metrics(calculation_date, metric_name);