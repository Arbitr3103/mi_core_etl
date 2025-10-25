-- Validation Script: Verify Analytics API integration fields in inventory table
-- Purpose: Validate that 004_extend_inventory_analytics_integration.sql was applied correctly
-- Task: 3.1 Создать миграцию для расширения таблицы inventory (validation)

-- Check if all required columns exist
SELECT 
    column_name,
    data_type,
    is_nullable,
    column_default,
    character_maximum_length
FROM information_schema.columns 
WHERE table_name = 'inventory' 
AND column_name IN (
    'data_source',
    'data_quality_score', 
    'last_analytics_sync',
    'normalized_warehouse_name',
    'original_warehouse_name',
    'sync_batch_id'
)
ORDER BY column_name;

-- Check if all required indexes exist
SELECT 
    indexname,
    indexdef
FROM pg_indexes 
WHERE tablename = 'inventory' 
AND indexname IN (
    'idx_inventory_data_source',
    'idx_inventory_quality_score',
    'idx_inventory_last_analytics_sync',
    'idx_inventory_normalized_warehouse',
    'idx_inventory_original_warehouse',
    'idx_inventory_sync_batch_id',
    'idx_inventory_source_quality',
    'idx_inventory_warehouse_source',
    'idx_inventory_batch_source'
)
ORDER BY indexname;

-- Check constraints
SELECT 
    conname,
    consrc
FROM pg_constraint 
WHERE conrelid = 'inventory'::regclass 
AND conname LIKE '%data_source%' OR conname LIKE '%quality_score%';

-- Verify column comments
SELECT 
    column_name,
    col_description(pgc.oid, ordinal_position) as column_comment
FROM information_schema.columns c
JOIN pg_class pgc ON pgc.relname = c.table_name
WHERE c.table_name = 'inventory' 
AND c.column_name IN (
    'data_source',
    'data_quality_score', 
    'last_analytics_sync',
    'normalized_warehouse_name',
    'original_warehouse_name',
    'sync_batch_id'
)
ORDER BY c.column_name;