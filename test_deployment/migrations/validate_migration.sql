-- Validation Script for Inventory Sync Migration
-- Version: 1.0
-- Date: 2025-01-06
-- Description: Validate that migration was applied correctly

-- Check table structure
SELECT 'TABLE STRUCTURE VALIDATION' as validation_type;

-- Verify inventory_data table has new columns
SELECT 
    'inventory_data_columns' as check_name,
    CASE 
        WHEN COUNT(*) = 5 THEN 'PASS'
        ELSE CONCAT('FAIL - Expected 5 new columns, found ', COUNT(*))
    END as status,
    GROUP_CONCAT(column_name ORDER BY column_name) as found_columns
FROM information_schema.columns 
WHERE table_schema = DATABASE() 
AND table_name = 'inventory_data' 
AND column_name IN ('warehouse_name', 'stock_type', 'quantity_present', 'quantity_reserved', 'last_sync_at');

-- Verify sync_logs table exists
SELECT 
    'sync_logs_table' as check_name,
    CASE 
        WHEN COUNT(*) > 0 THEN 'PASS'
        ELSE 'FAIL - sync_logs table not found'
    END as status,
    CONCAT('Found ', COUNT(*), ' columns') as details
FROM information_schema.columns 
WHERE table_schema = DATABASE() 
AND table_name = 'sync_logs';

-- Check indexes
SELECT 'INDEX VALIDATION' as validation_type;

SELECT 
    'inventory_data_indexes' as check_name,
    CASE 
        WHEN COUNT(*) >= 4 THEN 'PASS'
        ELSE CONCAT('FAIL - Expected at least 4 new indexes, found ', COUNT(*))
    END as status,
    GROUP_CONCAT(index_name ORDER BY index_name) as found_indexes
FROM information_schema.statistics 
WHERE table_schema = DATABASE() 
AND table_name = 'inventory_data' 
AND index_name IN ('idx_source_sync', 'idx_product_source', 'idx_warehouse_stock', 'idx_sync_date');

-- Check data integrity
SELECT 'DATA INTEGRITY VALIDATION' as validation_type;

-- Check that existing records have been updated with new fields
SELECT 
    'inventory_data_migration' as check_name,
    CASE 
        WHEN null_warehouse_count = 0 AND null_stock_type_count = 0 THEN 'PASS'
        ELSE CONCAT('FAIL - Found ', null_warehouse_count, ' NULL warehouse_name and ', null_stock_type_count, ' NULL stock_type')
    END as status,
    CONCAT('Total records: ', total_count, ', Updated records: ', updated_count) as details
FROM (
    SELECT 
        COUNT(*) as total_count,
        COUNT(CASE WHEN warehouse_name IS NOT NULL THEN 1 END) as updated_count,
        COUNT(CASE WHEN warehouse_name IS NULL THEN 1 END) as null_warehouse_count,
        COUNT(CASE WHEN stock_type IS NULL THEN 1 END) as null_stock_type_count
    FROM inventory_data
) as counts;

-- Check that quantity fields are properly set
SELECT 
    'quantity_fields_migration' as check_name,
    CASE 
        WHEN negative_present = 0 AND negative_reserved = 0 THEN 'PASS'
        ELSE CONCAT('FAIL - Found ', negative_present, ' negative quantity_present and ', negative_reserved, ' negative quantity_reserved')
    END as status,
    CONCAT('Records with quantity_present: ', with_present, ', Records with quantity_reserved: ', with_reserved) as details
FROM (
    SELECT 
        COUNT(CASE WHEN quantity_present < 0 THEN 1 END) as negative_present,
        COUNT(CASE WHEN quantity_reserved < 0 THEN 1 END) as negative_reserved,
        COUNT(CASE WHEN quantity_present > 0 THEN 1 END) as with_present,
        COUNT(CASE WHEN quantity_reserved > 0 THEN 1 END) as with_reserved
    FROM inventory_data
) as quantity_counts;

-- Check migration log
SELECT 'MIGRATION LOG VALIDATION' as validation_type;

SELECT 
    'migration_log_status' as check_name,
    CASE 
        WHEN status = 'completed' THEN 'PASS'
        ELSE CONCAT('FAIL - Migration status is ', status)
    END as status,
    CONCAT('Started: ', started_at, ', Completed: ', completed_at) as details
FROM migration_log 
WHERE migration_name = 'inventory_sync_production_migration_v1.0';

-- Performance validation
SELECT 'PERFORMANCE VALIDATION' as validation_type;

-- Check that indexes are being used (explain plan simulation)
SELECT 
    'index_usage_simulation' as check_name,
    'MANUAL_CHECK_REQUIRED' as status,
    'Run: EXPLAIN SELECT * FROM inventory_data WHERE source = "Ozon" AND last_sync_at > DATE_SUB(NOW(), INTERVAL 1 DAY)' as details;

-- Data consistency checks
SELECT 'DATA CONSISTENCY VALIDATION' as validation_type;

-- Check for orphaned records or data anomalies
SELECT 
    'data_anomalies' as check_name,
    CASE 
        WHEN anomaly_count = 0 THEN 'PASS'
        ELSE CONCAT('WARNING - Found ', anomaly_count, ' potential anomalies')
    END as status,
    'Records where quantity_present > 10000 or unusual stock_type values' as details
FROM (
    SELECT COUNT(*) as anomaly_count
    FROM inventory_data 
    WHERE quantity_present > 10000 
    OR stock_type NOT IN ('FBO', 'FBS', 'realFBS', 'WB')
    OR warehouse_name = ''
) as anomalies;

-- Summary statistics
SELECT 'MIGRATION SUMMARY' as validation_type;

SELECT 
    'inventory_data_summary' as check_name,
    'INFO' as status,
    CONCAT(
        'Total records: ', COUNT(*), 
        ', Ozon records: ', SUM(CASE WHEN source = 'Ozon' THEN 1 ELSE 0 END),
        ', WB records: ', SUM(CASE WHEN source = 'Wildberries' THEN 1 ELSE 0 END),
        ', Avg quantity: ', ROUND(AVG(quantity_present), 2)
    ) as details
FROM inventory_data;

SELECT 
    'sync_logs_summary' as check_name,
    'INFO' as status,
    CONCAT(
        'Total sync records: ', COUNT(*),
        ', Successful: ', SUM(CASE WHEN status = 'success' THEN 1 ELSE 0 END),
        ', Failed: ', SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END)
    ) as details
FROM sync_logs;

-- Final validation result
SELECT 
    'OVERALL_MIGRATION_STATUS' as validation_type,
    CASE 
        WHEN (
            SELECT COUNT(*) FROM information_schema.columns 
            WHERE table_schema = DATABASE() 
            AND table_name = 'inventory_data' 
            AND column_name IN ('warehouse_name', 'stock_type', 'quantity_present', 'quantity_reserved', 'last_sync_at')
        ) = 5
        AND (
            SELECT COUNT(*) FROM information_schema.tables 
            WHERE table_schema = DATABASE() 
            AND table_name = 'sync_logs'
        ) = 1
        AND (
            SELECT status FROM migration_log 
            WHERE migration_name = 'inventory_sync_production_migration_v1.0'
        ) = 'completed'
        THEN 'MIGRATION_SUCCESSFUL'
        ELSE 'MIGRATION_FAILED_OR_INCOMPLETE'
    END as final_status;