-- ===================================================================
-- PostgreSQL Query Optimization Migration
-- Task 14: Optimize PostgreSQL queries and indexes
-- ===================================================================
-- This migration adds optimized indexes for frequent query patterns,
-- improves JOIN performance, and sets up query monitoring
-- ===================================================================

-- Note: This migration creates indexes and views
-- Some operations are not wrapped in transaction for safety

-- ===================================================================
-- PART 1: Composite Indexes for Frequent Query Patterns
-- ===================================================================

-- Index for warehouse grouping queries (used in detailed-stock.php)
-- Optimizes: GROUP BY warehouse_name queries with aggregations
CREATE INDEX IF NOT EXISTS idx_inventory_warehouse_aggregation 
ON inventory(warehouse_name, product_id, quantity_present, quantity_reserved)
WHERE quantity_present > 0 OR quantity_reserved > 0;

-- Index for product lookup with warehouse (most common JOIN pattern)
-- Optimizes: JOIN between dim_products and inventory
CREATE INDEX IF NOT EXISTS idx_inventory_product_warehouse_lookup
ON inventory(product_id, warehouse_name, quantity_present, quantity_reserved, source);

-- Composite index for stock status calculations
-- Optimizes: CASE WHEN queries calculating stock_status
CREATE INDEX IF NOT EXISTS idx_inventory_stock_status_calc
ON inventory((quantity_present - quantity_reserved), warehouse_name, product_id)
WHERE (quantity_present - quantity_reserved) >= 0;

-- Index for warehouse sales metrics lookups
CREATE INDEX IF NOT EXISTS idx_warehouse_sales_product_warehouse
ON warehouse_sales_metrics(product_id, warehouse_name, source, daily_sales_avg);

-- Composite index for replenishment queries
CREATE INDEX IF NOT EXISTS idx_warehouse_sales_replenishment
ON warehouse_sales_metrics(warehouse_name, replenishment_need, daily_sales_avg)
WHERE replenishment_need > 0;

-- ===================================================================
-- PART 2: Optimize Product Search Queries
-- ===================================================================

-- Composite index for product search with SKU variants
-- Optimizes: ILIKE searches across multiple SKU fields
CREATE INDEX IF NOT EXISTS idx_dim_products_sku_search
ON dim_products(sku_ozon, sku_wb, sku_internal, product_name)
WHERE sku_ozon IS NOT NULL OR sku_wb IS NOT NULL OR sku_internal IS NOT NULL;

-- Trigram index for fuzzy product name search (better than GIN for ILIKE)
CREATE EXTENSION IF NOT EXISTS pg_trgm;

CREATE INDEX IF NOT EXISTS idx_dim_products_name_trgm
ON dim_products USING gin (product_name gin_trgm_ops);

-- Index for active products filtering
CREATE INDEX IF NOT EXISTS idx_dim_products_active_visibility
ON dim_products(id, visibility, ozon_status, product_name)
WHERE visibility IN ('VISIBLE', 'ACTIVE', 'продаётся');

-- ===================================================================
-- PART 3: Optimize Warehouse Sales Metrics Queries
-- ===================================================================

-- Index for liquidity status filtering
CREATE INDEX IF NOT EXISTS idx_warehouse_sales_liquidity
ON warehouse_sales_metrics(liquidity_status, product_id, warehouse_name)
WHERE liquidity_status IS NOT NULL;

-- Index for days of stock calculations
CREATE INDEX IF NOT EXISTS idx_warehouse_sales_days_stock
ON warehouse_sales_metrics(days_of_stock, product_id)
WHERE days_of_stock IS NOT NULL AND days_of_stock > 0;

-- Composite index for sales analysis
CREATE INDEX IF NOT EXISTS idx_warehouse_sales_analysis
ON warehouse_sales_metrics(product_id, warehouse_name, sales_last_28_days, daily_sales_avg, calculated_at);

-- ===================================================================
-- PART 4: Partial Indexes for Common Filters
-- ===================================================================

-- Index for out-of-stock products
CREATE INDEX IF NOT EXISTS idx_inventory_out_of_stock
ON inventory(product_id, warehouse_name, source)
WHERE (quantity_present - quantity_reserved) <= 0;

-- Index for critical stock levels (< 20 units)
CREATE INDEX IF NOT EXISTS idx_inventory_critical_stock
ON inventory(product_id, warehouse_name, quantity_present, quantity_reserved)
WHERE (quantity_present - quantity_reserved) > 0 
  AND (quantity_present - quantity_reserved) < 20;

-- Index for low stock levels (20-50 units)
CREATE INDEX IF NOT EXISTS idx_inventory_low_stock
ON inventory(product_id, warehouse_name, quantity_present, quantity_reserved)
WHERE (quantity_present - quantity_reserved) >= 20 
  AND (quantity_present - quantity_reserved) < 50;

-- Index for excess stock (>= 100 units)
CREATE INDEX IF NOT EXISTS idx_inventory_excess_stock
ON inventory(product_id, warehouse_name, quantity_present, quantity_reserved)
WHERE (quantity_present - quantity_reserved) >= 100;

-- ===================================================================
-- PART 5: Covering Indexes for Common Queries
-- ===================================================================

-- Covering index for warehouse summary queries
CREATE INDEX IF NOT EXISTS idx_inventory_warehouse_summary_covering
ON inventory(warehouse_name, product_id, quantity_present, quantity_reserved, source, stock_type);

-- Covering index for product detail queries
CREATE INDEX IF NOT EXISTS idx_inventory_product_detail_covering
ON inventory(product_id, warehouse_name, quantity_present, quantity_reserved, 
             stock_type, source, updated_at, cluster);

-- ===================================================================
-- PART 6: Update Statistics and Analyze Tables
-- ===================================================================

-- Update table statistics for query planner
ANALYZE inventory;
ANALYZE dim_products;
ANALYZE warehouse_sales_metrics;

-- ===================================================================
-- PART 7: Create Query Performance Monitoring View
-- ===================================================================

-- View to monitor slow queries (only if pg_stat_statements is available)
-- Note: This view will be empty if pg_stat_statements extension is not enabled
DO $$
BEGIN
    IF EXISTS (SELECT 1 FROM pg_extension WHERE extname = 'pg_stat_statements') THEN
        EXECUTE '
        CREATE OR REPLACE VIEW v_slow_queries AS
        SELECT 
            query,
            calls,
            total_exec_time,
            mean_exec_time,
            max_exec_time,
            stddev_exec_time,
            rows
        FROM pg_stat_statements
        WHERE mean_exec_time > 100
        ORDER BY mean_exec_time DESC
        LIMIT 50';
    ELSE
        -- Create placeholder view
        EXECUTE '
        CREATE OR REPLACE VIEW v_slow_queries AS
        SELECT 
            ''pg_stat_statements extension not enabled''::text as message,
            0::bigint as calls,
            0::double precision as total_exec_time,
            0::double precision as mean_exec_time,
            0::double precision as max_exec_time,
            0::double precision as stddev_exec_time,
            0::bigint as rows
        WHERE false';
    END IF;
END $$;

-- View to monitor table bloat and index usage
CREATE OR REPLACE VIEW v_index_usage_stats AS
SELECT
    schemaname,
    relname as tablename,
    indexrelname as indexname,
    idx_scan as index_scans,
    idx_tup_read as tuples_read,
    idx_tup_fetch as tuples_fetched,
    pg_size_pretty(pg_relation_size(indexrelid)) as index_size
FROM pg_stat_user_indexes
WHERE schemaname = 'public'
ORDER BY idx_scan DESC;

-- View to monitor table statistics
CREATE OR REPLACE VIEW v_table_stats AS
SELECT
    schemaname,
    relname as tablename,
    n_live_tup as live_rows,
    n_dead_tup as dead_rows,
    n_tup_ins as inserts,
    n_tup_upd as updates,
    n_tup_del as deletes,
    last_vacuum,
    last_autovacuum,
    last_analyze,
    last_autoanalyze,
    pg_size_pretty(pg_total_relation_size(schemaname||'.'||relname)) as total_size
FROM pg_stat_user_tables
WHERE schemaname = 'public'
ORDER BY n_live_tup DESC;

-- ===================================================================
-- PART 8: Create Materialized View for Dashboard Summary
-- ===================================================================

-- Materialized view for warehouse summary (refreshed periodically)
CREATE MATERIALIZED VIEW IF NOT EXISTS mv_warehouse_summary AS
SELECT 
    i.warehouse_name,
    i.source,
    COUNT(DISTINCT dp.id) as product_count,
    SUM(i.quantity_present) as total_present,
    SUM(i.quantity_reserved) as total_reserved,
    SUM(i.quantity_present - i.quantity_reserved) as total_available,
    COUNT(CASE WHEN (i.quantity_present - i.quantity_reserved) <= 0 THEN 1 END) as out_of_stock_count,
    COUNT(CASE WHEN (i.quantity_present - i.quantity_reserved) > 0 
               AND (i.quantity_present - i.quantity_reserved) < 20 THEN 1 END) as critical_count,
    COUNT(CASE WHEN (i.quantity_present - i.quantity_reserved) >= 20 
               AND (i.quantity_present - i.quantity_reserved) < 50 THEN 1 END) as low_count,
    COUNT(CASE WHEN (i.quantity_present - i.quantity_reserved) >= 50 
               AND (i.quantity_present - i.quantity_reserved) < 100 THEN 1 END) as normal_count,
    COUNT(CASE WHEN (i.quantity_present - i.quantity_reserved) >= 100 THEN 1 END) as excess_count,
    MAX(i.updated_at) as last_updated,
    NOW() as calculated_at
FROM inventory i
JOIN dim_products dp ON i.product_id = dp.id
WHERE i.quantity_present > 0 OR i.quantity_reserved > 0
GROUP BY i.warehouse_name, i.source;

-- Create index on materialized view
CREATE INDEX IF NOT EXISTS idx_mv_warehouse_summary_warehouse
ON mv_warehouse_summary(warehouse_name, source);

-- ===================================================================
-- PART 9: PostgreSQL Configuration Recommendations
-- ===================================================================

-- Note: These settings should be applied to postgresql.conf
-- Included here as documentation

COMMENT ON VIEW v_slow_queries IS 
'Monitor slow queries. Requires pg_stat_statements extension.
To enable: CREATE EXTENSION IF NOT EXISTS pg_stat_statements;
Add to postgresql.conf: shared_preload_libraries = ''pg_stat_statements''';

COMMENT ON VIEW v_index_usage_stats IS
'Monitor index usage to identify unused indexes that can be dropped';

COMMENT ON VIEW v_table_stats IS
'Monitor table statistics, bloat, and maintenance status';

COMMENT ON MATERIALIZED VIEW mv_warehouse_summary IS
'Cached warehouse summary for fast dashboard loading.
Refresh with: REFRESH MATERIALIZED VIEW CONCURRENTLY mv_warehouse_summary;
Recommended refresh interval: Every 5-15 minutes';

-- ===================================================================
-- PART 10: Create Function to Refresh Materialized Views
-- ===================================================================

CREATE OR REPLACE FUNCTION refresh_dashboard_cache()
RETURNS void AS $$
BEGIN
    -- Refresh warehouse summary
    REFRESH MATERIALIZED VIEW CONCURRENTLY mv_warehouse_summary;
    
    -- Log refresh
    RAISE NOTICE 'Dashboard cache refreshed at %', NOW();
END;
$$ LANGUAGE plpgsql;

COMMENT ON FUNCTION refresh_dashboard_cache() IS
'Refresh all materialized views used by dashboard.
Call periodically via cron or pg_cron extension.';

-- ===================================================================
-- PART 11: Query Optimization Statistics
-- ===================================================================

-- Create table to track query performance over time
CREATE TABLE IF NOT EXISTS query_performance_log (
    id SERIAL PRIMARY KEY,
    query_name VARCHAR(100) NOT NULL,
    execution_time_ms NUMERIC(10,2) NOT NULL,
    rows_returned INTEGER,
    query_params JSONB,
    executed_at TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX IF NOT EXISTS idx_query_performance_log_name_time
ON query_performance_log(query_name, executed_at DESC);

COMMENT ON TABLE query_performance_log IS
'Log query performance for monitoring and optimization.
Use this to track API query performance over time.';

-- Optimization complete

-- ===================================================================
-- Post-Migration Verification
-- ===================================================================

-- Display created indexes
SELECT 
    schemaname,
    relname as tablename,
    indexrelname as indexname,
    pg_size_pretty(pg_relation_size(indexrelid)) as size
FROM pg_stat_user_indexes
WHERE schemaname = 'public'
  AND indexrelname LIKE 'idx_%'
ORDER BY relname, indexrelname;

-- Display optimization summary
SELECT 
    'Indexes Created' as metric,
    COUNT(*) as value
FROM pg_indexes
WHERE schemaname = 'public'
  AND indexname LIKE 'idx_%'
UNION ALL
SELECT 
    'Materialized Views' as metric,
    COUNT(*) as value
FROM pg_matviews
WHERE schemaname = 'public'
UNION ALL
SELECT
    'Monitoring Views' as metric,
    COUNT(*) as value
FROM pg_views
WHERE schemaname = 'public'
  AND viewname LIKE 'v_%';
