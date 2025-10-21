-- Validation Script: Regional Analytics Schema
-- Description: Validates that regional analytics schema was created correctly
-- Date: 2025-10-20
-- Requirements: 5.3

-- Check if all tables exist
SELECT 
    'ozon_regional_sales' as table_name,
    CASE WHEN COUNT(*) > 0 THEN 'EXISTS' ELSE 'MISSING' END as status
FROM information_schema.tables 
WHERE table_schema = DATABASE() AND table_name = 'ozon_regional_sales'

UNION ALL

SELECT 
    'regions' as table_name,
    CASE WHEN COUNT(*) > 0 THEN 'EXISTS' ELSE 'MISSING' END as status
FROM information_schema.tables 
WHERE table_schema = DATABASE() AND table_name = 'regions'

UNION ALL

SELECT 
    'regional_analytics_cache' as table_name,
    CASE WHEN COUNT(*) > 0 THEN 'EXISTS' ELSE 'MISSING' END as status
FROM information_schema.tables 
WHERE table_schema = DATABASE() AND table_name = 'regional_analytics_cache';

-- Check if views exist
SELECT 
    'v_regional_sales_summary' as view_name,
    CASE WHEN COUNT(*) > 0 THEN 'EXISTS' ELSE 'MISSING' END as status
FROM information_schema.views 
WHERE table_schema = DATABASE() AND table_name = 'v_regional_sales_summary'

UNION ALL

SELECT 
    'v_marketplace_comparison' as view_name,
    CASE WHEN COUNT(*) > 0 THEN 'EXISTS' ELSE 'MISSING' END as status
FROM information_schema.views 
WHERE table_schema = DATABASE() AND table_name = 'v_marketplace_comparison';

-- Check indexes on ozon_regional_sales table
SELECT 
    CONCAT('Index: ', index_name) as index_info,
    CASE WHEN non_unique = 0 THEN 'UNIQUE' ELSE 'NON-UNIQUE' END as index_type,
    GROUP_CONCAT(column_name ORDER BY seq_in_index) as columns
FROM information_schema.statistics 
WHERE table_schema = DATABASE() 
    AND table_name = 'ozon_regional_sales'
    AND index_name != 'PRIMARY'
GROUP BY index_name, non_unique
ORDER BY index_name;

-- Check indexes on regions table
SELECT 
    CONCAT('Index: ', index_name) as index_info,
    CASE WHEN non_unique = 0 THEN 'UNIQUE' ELSE 'NON-UNIQUE' END as index_type,
    GROUP_CONCAT(column_name ORDER BY seq_in_index) as columns
FROM information_schema.statistics 
WHERE table_schema = DATABASE() 
    AND table_name = 'regions'
    AND index_name != 'PRIMARY'
GROUP BY index_name, non_unique
ORDER BY index_name;

-- Check foreign key constraints
SELECT 
    constraint_name,
    table_name,
    column_name,
    referenced_table_name,
    referenced_column_name
FROM information_schema.key_column_usage
WHERE table_schema = DATABASE() 
    AND referenced_table_name IS NOT NULL
    AND table_name IN ('ozon_regional_sales', 'regions', 'regional_analytics_cache');

-- Check initial data in regions table
SELECT 
    COUNT(*) as regions_count,
    COUNT(DISTINCT federal_district) as federal_districts_count,
    COUNT(CASE WHEN is_active = 1 THEN 1 END) as active_regions_count
FROM regions;

-- Show sample of regions data
SELECT 
    region_code,
    region_name,
    federal_district,
    is_active,
    priority
FROM regions 
ORDER BY priority DESC 
LIMIT 5;