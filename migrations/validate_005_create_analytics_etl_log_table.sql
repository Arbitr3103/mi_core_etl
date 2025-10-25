-- Validation Script: Verify analytics_etl_log table creation
-- Purpose: Validate that 005_create_analytics_etl_log_table.sql was applied correctly
-- Task: 3.2 Создать таблицу analytics_etl_log (validation)

-- Check if analytics_etl_log table exists
SELECT 
    table_name,
    table_type,
    table_comment
FROM information_schema.tables 
WHERE table_name = 'analytics_etl_log'
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
WHERE table_name = 'analytics_etl_log' 
ORDER BY ordinal_position;

-- Check if all required indexes exist
SELECT 
    indexname,
    indexdef
FROM pg_indexes 
WHERE tablename = 'analytics_etl_log' 
ORDER BY indexname;

-- Check constraints
SELECT 
    conname,
    contype,
    consrc
FROM pg_constraint 
WHERE conrelid = 'analytics_etl_log'::regclass 
ORDER BY conname;

-- Verify column comments
SELECT 
    column_name,
    col_description(pgc.oid, ordinal_position) as column_comment
FROM information_schema.columns c
JOIN pg_class pgc ON pgc.relname = c.table_name
WHERE c.table_name = 'analytics_etl_log' 
AND col_description(pgc.oid, ordinal_position) IS NOT NULL
ORDER BY c.ordinal_position;

-- Test basic insert/select operations
INSERT INTO analytics_etl_log (
    batch_id, 
    etl_type, 
    status, 
    records_processed, 
    execution_time_ms,
    data_source
) VALUES (
    gen_random_uuid(),
    'validation_only',
    'completed',
    0,
    100,
    'analytics_api'
);

-- Verify the test record was inserted
SELECT 
    id,
    batch_id,
    etl_type,
    status,
    records_processed,
    execution_time_ms,
    data_source,
    started_at
FROM analytics_etl_log 
WHERE etl_type = 'validation_only'
ORDER BY started_at DESC 
LIMIT 1;

-- Clean up test record
DELETE FROM analytics_etl_log WHERE etl_type = 'validation_only';

-- Verify table is ready for use
SELECT 
    'analytics_etl_log table validation completed successfully' as validation_result;