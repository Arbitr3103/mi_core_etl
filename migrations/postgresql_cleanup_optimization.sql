-- ===================================================================
-- PostgreSQL Database Cleanup and Optimization Script
-- Task 5.1: Очистка и оптимизация PostgreSQL схемы
-- ===================================================================

-- Connect to the database
\c mi_core_db;

-- Enable timing for performance monitoring
\timing on

-- ===================================================================
-- STEP 1: CLEANUP TEMPORARY TABLES AND VIEWS
-- ===================================================================

DO $$
DECLARE
    rec RECORD;
    table_name TEXT;
BEGIN
    RAISE NOTICE 'Starting cleanup of temporary tables and views...';
    
    -- Drop temporary tables with suffixes _copy, _test, _backup, _temp, _old
    FOR rec IN 
        SELECT schemaname, tablename 
        FROM pg_tables 
        WHERE schemaname = 'public' 
        AND (tablename LIKE '%_copy' 
             OR tablename LIKE '%_test' 
             OR tablename LIKE '%_backup%' 
             OR tablename LIKE '%_temp%' 
             OR tablename LIKE '%_old')
    LOOP
        table_name := quote_ident(rec.schemaname) || '.' || quote_ident(rec.tablename);
        EXECUTE 'DROP TABLE IF EXISTS ' || table_name || ' CASCADE';
        RAISE NOTICE 'Dropped table: %', rec.tablename;
    END LOOP;
    
    -- Drop temporary views
    FOR rec IN 
        SELECT schemaname, viewname 
        FROM pg_views 
        WHERE schemaname = 'public' 
        AND (viewname LIKE '%_copy' 
             OR viewname LIKE '%_test' 
             OR viewname LIKE '%_backup%' 
             OR viewname LIKE '%_temp%' 
             OR viewname LIKE '%_old')
    LOOP
        EXECUTE 'DROP VIEW IF EXISTS ' || quote_ident(rec.schemaname) || '.' || quote_ident(rec.viewname) || ' CASCADE';
        RAISE NOTICE 'Dropped view: %', rec.viewname;
    END LOOP;
    
    RAISE NOTICE 'Temporary objects cleanup completed.';
END $$;

-- ===================================================================
-- STEP 2: CREATE OPTIMIZED INDEXES FOR POSTGRESQL
-- ===================================================================

-- Drop existing indexes that might not be optimal
DROP INDEX IF EXISTS idx_inventory_stock_status;
DROP INDEX IF EXISTS idx_dim_products_name;
DROP INDEX IF EXISTS idx_master_products_name;

-- Create optimized indexes for inventory management
CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_inventory_product_warehouse_source 
ON inventory(product_id, warehouse_name, source);

CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_inventory_stock_levels 
ON inventory(quantity_present, quantity_reserved) 
WHERE quantity_present IS NOT NULL;

-- Functional index for stock status calculation
CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_inventory_stock_status_func 
ON inventory((
    CASE 
        WHEN quantity_present <= 5 THEN 'critical'
        WHEN quantity_present <= 20 THEN 'low_stock'
        WHEN quantity_present > 100 THEN 'overstock'
        ELSE 'normal'
    END
));

-- Optimized text search indexes using PostgreSQL's full-text search
CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_dim_products_name_fts 
ON dim_products USING gin(to_tsvector('english', product_name));

CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_master_products_name_fts 
ON master_products USING gin(to_tsvector('english', canonical_name));

-- Composite indexes for common query patterns
CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_stock_movements_product_date_type 
ON stock_movements(product_id, movement_date DESC, movement_type);

CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_fact_orders_date_client_source 
ON fact_orders(order_date DESC, client_id, source_id);

-- Partial indexes for active records only
CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_master_products_active_status 
ON master_products(status, updated_at DESC) 
WHERE status = 'active';

CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_sku_mapping_pending_verification 
ON sku_mapping(verification_status, created_at DESC) 
WHERE verification_status = 'pending';

-- BRIN indexes for time-series data (more efficient for large tables)
CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_stock_movements_date_brin 
ON stock_movements USING brin(movement_date);

CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_audit_log_created_at_brin 
ON audit_log USING brin(created_at);

-- ===================================================================
-- STEP 3: SETUP TABLE PARTITIONING FOR LARGE TABLES
-- ===================================================================

-- Partition stock_movements table by date (monthly partitions)
DO $$
DECLARE
    start_date DATE;
    end_date DATE;
    partition_name TEXT;
    partition_sql TEXT;
BEGIN
    -- Check if table is already partitioned
    IF NOT EXISTS (
        SELECT 1 FROM pg_partitioned_table 
        WHERE partrelid = 'stock_movements'::regclass
    ) THEN
        RAISE NOTICE 'Setting up partitioning for stock_movements table...';
        
        -- Create new partitioned table
        CREATE TABLE stock_movements_new (
            LIKE stock_movements INCLUDING ALL
        ) PARTITION BY RANGE (movement_date);
        
        -- Create partitions for the last 12 months and next 6 months
        start_date := DATE_TRUNC('month', CURRENT_DATE - INTERVAL '12 months');
        
        FOR i IN 0..17 LOOP
            partition_name := 'stock_movements_' || TO_CHAR(start_date + (i || ' months')::INTERVAL, 'YYYY_MM');
            end_date := start_date + ((i + 1) || ' months')::INTERVAL;
            
            partition_sql := FORMAT(
                'CREATE TABLE %I PARTITION OF stock_movements_new FOR VALUES FROM (%L) TO (%L)',
                partition_name, start_date + (i || ' months')::INTERVAL, end_date
            );
            
            EXECUTE partition_sql;
            RAISE NOTICE 'Created partition: %', partition_name;
        END LOOP;
        
        -- Copy data from original table (if it has data)
        IF EXISTS (SELECT 1 FROM stock_movements LIMIT 1) THEN
            INSERT INTO stock_movements_new SELECT * FROM stock_movements;
            RAISE NOTICE 'Data copied to partitioned table';
        END IF;
        
        -- Rename tables
        ALTER TABLE stock_movements RENAME TO stock_movements_old;
        ALTER TABLE stock_movements_new RENAME TO stock_movements;
        
        -- Drop old table after verification
        -- DROP TABLE stock_movements_old; -- Uncomment after verification
        
        RAISE NOTICE 'Stock movements table partitioning completed';
    ELSE
        RAISE NOTICE 'Stock movements table is already partitioned';
    END IF;
END $$;

-- Partition audit_log table by date (monthly partitions)
DO $$
DECLARE
    start_date DATE;
    end_date DATE;
    partition_name TEXT;
    partition_sql TEXT;
BEGIN
    -- Check if table is already partitioned
    IF NOT EXISTS (
        SELECT 1 FROM pg_partitioned_table 
        WHERE partrelid = 'audit_log'::regclass
    ) THEN
        RAISE NOTICE 'Setting up partitioning for audit_log table...';
        
        -- Create new partitioned table
        CREATE TABLE audit_log_new (
            LIKE audit_log INCLUDING ALL
        ) PARTITION BY RANGE (created_at);
        
        -- Create partitions for the last 6 months and next 6 months
        start_date := DATE_TRUNC('month', CURRENT_DATE - INTERVAL '6 months');
        
        FOR i IN 0..11 LOOP
            partition_name := 'audit_log_' || TO_CHAR(start_date + (i || ' months')::INTERVAL, 'YYYY_MM');
            end_date := start_date + ((i + 1) || ' months')::INTERVAL;
            
            partition_sql := FORMAT(
                'CREATE TABLE %I PARTITION OF audit_log_new FOR VALUES FROM (%L) TO (%L)',
                partition_name, start_date + (i || ' months')::INTERVAL, end_date
            );
            
            EXECUTE partition_sql;
            RAISE NOTICE 'Created partition: %', partition_name;
        END LOOP;
        
        -- Copy data from original table (if it has data)
        IF EXISTS (SELECT 1 FROM audit_log LIMIT 1) THEN
            INSERT INTO audit_log_new SELECT * FROM audit_log;
            RAISE NOTICE 'Data copied to partitioned table';
        END IF;
        
        -- Rename tables
        ALTER TABLE audit_log RENAME TO audit_log_old;
        ALTER TABLE audit_log_new RENAME TO audit_log;
        
        RAISE NOTICE 'Audit log table partitioning completed';
    ELSE
        RAISE NOTICE 'Audit log table is already partitioned';
    END IF;
END $$;

-- ===================================================================
-- STEP 4: DATA CLEANUP ACCORDING TO RETENTION POLICY
-- ===================================================================

-- Clean up old audit log entries (keep last 90 days)
DELETE FROM audit_log 
WHERE created_at < CURRENT_DATE - INTERVAL '90 days';

-- Clean up old job runs (keep last 30 days)
DELETE FROM job_runs 
WHERE created_at < CURRENT_DATE - INTERVAL '30 days';

-- Clean up old data quality metrics (keep last 180 days)
DELETE FROM data_quality_metrics 
WHERE calculation_date < CURRENT_DATE - INTERVAL '180 days';

-- Clean up old matching history (keep last 365 days)
DELETE FROM matching_history 
WHERE created_at < CURRENT_DATE - INTERVAL '365 days';

-- Clean up resolved replenishment alerts (keep last 30 days)
DELETE FROM replenishment_alerts 
WHERE is_resolved = true 
AND resolved_at < CURRENT_DATE - INTERVAL '30 days';

-- ===================================================================
-- STEP 5: OPTIMIZE TABLE STATISTICS
-- ===================================================================

-- Update table statistics for better query planning
ANALYZE inventory;
ANALYZE dim_products;
ANALYZE stock_movements;
ANALYZE fact_orders;
ANALYZE master_products;
ANALYZE sku_mapping;
ANALYZE audit_log;

-- ===================================================================
-- STEP 6: VACUUM AND REINDEX
-- ===================================================================

-- Vacuum tables to reclaim space
VACUUM (ANALYZE, VERBOSE) inventory;
VACUUM (ANALYZE, VERBOSE) dim_products;
VACUUM (ANALYZE, VERBOSE) stock_movements;
VACUUM (ANALYZE, VERBOSE) fact_orders;
VACUUM (ANALYZE, VERBOSE) master_products;
VACUUM (ANALYZE, VERBOSE) sku_mapping;

-- ===================================================================
-- STEP 7: CREATE PERFORMANCE MONITORING VIEWS
-- ===================================================================

-- View for monitoring table sizes
CREATE OR REPLACE VIEW v_table_sizes AS
SELECT 
    schemaname,
    tablename,
    pg_size_pretty(pg_total_relation_size(schemaname||'.'||tablename)) as size,
    pg_size_pretty(pg_relation_size(schemaname||'.'||tablename)) as table_size,
    pg_size_pretty(pg_total_relation_size(schemaname||'.'||tablename) - pg_relation_size(schemaname||'.'||tablename)) as index_size
FROM pg_tables 
WHERE schemaname = 'public'
ORDER BY pg_total_relation_size(schemaname||'.'||tablename) DESC;

-- View for monitoring index usage
CREATE OR REPLACE VIEW v_index_usage AS
SELECT 
    schemaname,
    tablename,
    indexname,
    idx_tup_read,
    idx_tup_fetch,
    idx_scan,
    CASE 
        WHEN idx_scan = 0 THEN 'Never used'
        WHEN idx_scan < 100 THEN 'Rarely used'
        WHEN idx_scan < 1000 THEN 'Moderately used'
        ELSE 'Frequently used'
    END as usage_level
FROM pg_stat_user_indexes
ORDER BY idx_scan DESC;

-- View for monitoring slow queries
CREATE OR REPLACE VIEW v_slow_queries AS
SELECT 
    query,
    calls,
    total_time,
    mean_time,
    rows,
    100.0 * shared_blks_hit / nullif(shared_blks_hit + shared_blks_read, 0) AS hit_percent
FROM pg_stat_statements 
WHERE mean_time > 100  -- queries taking more than 100ms on average
ORDER BY mean_time DESC
LIMIT 20;

-- ===================================================================
-- COMPLETION REPORT
-- ===================================================================

DO $$
DECLARE
    table_count INTEGER;
    index_count INTEGER;
    view_count INTEGER;
    total_size TEXT;
BEGIN
    -- Get statistics
    SELECT COUNT(*) INTO table_count FROM pg_tables WHERE schemaname = 'public';
    SELECT COUNT(*) INTO index_count FROM pg_indexes WHERE schemaname = 'public';
    SELECT COUNT(*) INTO view_count FROM pg_views WHERE schemaname = 'public';
    SELECT pg_size_pretty(pg_database_size(current_database())) INTO total_size;
    
    RAISE NOTICE '=== PostgreSQL OPTIMIZATION COMPLETED ===';
    RAISE NOTICE 'Database: %', current_database();
    RAISE NOTICE 'Total tables: %', table_count;
    RAISE NOTICE 'Total indexes: %', index_count;
    RAISE NOTICE 'Total views: %', view_count;
    RAISE NOTICE 'Database size: %', total_size;
    RAISE NOTICE 'Optimization completed at: %', CURRENT_TIMESTAMP;
    RAISE NOTICE '==========================================';
END $$;

-- Show table sizes after optimization
SELECT 'TABLE SIZES AFTER OPTIMIZATION:' as info;
SELECT * FROM v_table_sizes LIMIT 10;

-- Show index usage statistics
SELECT 'INDEX USAGE STATISTICS:' as info;
SELECT * FROM v_index_usage WHERE usage_level != 'Never used' LIMIT 10;

\timing off