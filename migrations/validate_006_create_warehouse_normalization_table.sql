-- Validation Script: Verify warehouse_normalization table creation
-- Purpose: Validate that 006_create_warehouse_normalization_table.sql was applied correctly
-- Task: 3.3 Создать таблицу warehouse_normalization (validation)

-- Check if warehouse_normalization table exists
SELECT 
    table_name,
    table_type,
    table_comment
FROM information_schema.tables 
WHERE table_name = 'warehouse_normalization'
ORDER BY table_name;

-- Check if all required columns exist with correct types
SELECT 
    column_name,
    data_type,
    is_nullable,
    column_default,
    character_maximum_length,
    numeric_precision,
    numeric_scale
FROM information_schema.columns 
WHERE table_name = 'warehouse_normalization' 
ORDER BY ordinal_position;

-- Check if all required indexes exist
SELECT 
    indexname,
    indexdef
FROM pg_indexes 
WHERE tablename = 'warehouse_normalization' 
ORDER BY indexname;

-- Check constraints
SELECT 
    conname,
    contype,
    consrc
FROM pg_constraint 
WHERE conrelid = 'warehouse_normalization'::regclass 
ORDER BY conname;

-- Verify column comments
SELECT 
    column_name,
    col_description(pgc.oid, ordinal_position) as column_comment
FROM information_schema.columns c
JOIN pg_class pgc ON pgc.relname = c.table_name
WHERE c.table_name = 'warehouse_normalization' 
AND col_description(pgc.oid, ordinal_position) IS NOT NULL
ORDER BY c.ordinal_position;

-- Check if initial data was inserted
SELECT 
    COUNT(*) as total_records,
    COUNT(DISTINCT source_type) as source_types,
    COUNT(DISTINCT cluster_name) as clusters,
    MIN(confidence_score) as min_confidence,
    MAX(confidence_score) as max_confidence
FROM warehouse_normalization;

-- Show sample normalization rules
SELECT 
    original_name,
    normalized_name,
    source_type,
    confidence_score,
    match_type,
    cluster_name
FROM warehouse_normalization 
ORDER BY cluster_name, confidence_score DESC
LIMIT 10;

-- Test normalization lookup functionality
SELECT 
    'Normalization lookup test' as test_name,
    original_name,
    normalized_name,
    source_type
FROM warehouse_normalization 
WHERE original_name = 'РФЦ Москва' AND source_type = 'api';

-- Test insert/update operations
INSERT INTO warehouse_normalization (
    original_name, 
    normalized_name, 
    source_type, 
    confidence_score,
    match_type
) VALUES (
    'TEST_WAREHOUSE_VALIDATION',
    'TEST_NORMALIZED',
    'manual',
    1.0,
    'manual'
);

-- Verify the test record was inserted
SELECT 
    id,
    original_name,
    normalized_name,
    source_type,
    confidence_score,
    created_at
FROM warehouse_normalization 
WHERE original_name = 'TEST_WAREHOUSE_VALIDATION';

-- Test update functionality
UPDATE warehouse_normalization 
SET usage_count = 1, last_used_at = CURRENT_TIMESTAMP
WHERE original_name = 'TEST_WAREHOUSE_VALIDATION';

-- Verify update worked
SELECT 
    original_name,
    usage_count,
    last_used_at
FROM warehouse_normalization 
WHERE original_name = 'TEST_WAREHOUSE_VALIDATION';

-- Clean up test record
DELETE FROM warehouse_normalization WHERE original_name = 'TEST_WAREHOUSE_VALIDATION';

-- Verify table is ready for use
SELECT 
    'warehouse_normalization table validation completed successfully' as validation_result;