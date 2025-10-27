-- ===================================================================
-- Performance Optimization Indexes for Detailed Inventory View
-- ===================================================================
-- 
-- Additional indexes to optimize query performance for the detailed
-- inventory dashboard, focusing on common filtering and sorting patterns.
-- 
-- Requirements: 7.1, 7.2
-- Task: 4.1 Database performance optimization
-- ===================================================================

-- Performance monitoring setup
SET log_statement = 'all';
SET log_min_duration_statement = 100; -- Log queries taking more than 100ms

-- Advanced composite indexes for common query patterns
-- These indexes support the most frequent filter combinations

-- Index for warehouse + status filtering (very common pattern)
CREATE INDEX IF NOT EXISTS idx_inventory_warehouse_status_performance 
ON inventory(warehouse_name, source) 
INCLUDE (quantity_present, quantity_reserved, preparing_for_sale, updated_at);

-- Index for product search with stock information
CREATE INDEX IF NOT EXISTS idx_dim_products_search_performance 
ON dim_products(product_name, sku_ozon, sku_wb, sku_internal) 
INCLUDE (id, cost_price, margin_percent);

-- Index for sales metrics with status filtering
CREATE INDEX IF NOT EXISTS idx_warehouse_sales_metrics_performance 
ON warehouse_sales_metrics(warehouse_name, liquidity_status, daily_sales_avg) 
INCLUDE (product_id, sales_last_28_days, days_of_stock, calculated_at);

-- Partial indexes for high-performance filtering on active inventory
-- These indexes only include records that are likely to be queried

-- Active products with stock (most common query)
CREATE INDEX IF NOT EXISTS idx_inventory_active_stock_performance 
ON inventory(product_id, warehouse_name, source, updated_at) 
WHERE (quantity_present + quantity_reserved + preparing_for_sale) > 0;

-- Products with recent sales activity
CREATE INDEX IF NOT EXISTS idx_warehouse_sales_metrics_active_performance 
ON warehouse_sales_metrics(product_id, warehouse_name, daily_sales_avg DESC, calculated_at) 
WHERE daily_sales_avg > 0 OR sales_last_28_days > 0;

-- Critical and low stock items (high priority queries)
CREATE INDEX IF NOT EXISTS idx_warehouse_sales_metrics_urgent_performance 
ON warehouse_sales_metrics(warehouse_name, product_id, days_of_stock ASC, daily_sales_avg DESC) 
WHERE liquidity_status IN ('critical', 'low') AND days_of_stock IS NOT NULL;

-- Products needing replenishment (business critical queries)
CREATE INDEX IF NOT EXISTS idx_warehouse_sales_metrics_replenishment_performance 
ON warehouse_sales_metrics(warehouse_name, daily_sales_avg DESC, days_of_stock ASC) 
WHERE daily_sales_avg > 0 AND days_of_stock < 60;

-- Advanced text search indexes using PostgreSQL's full-text search
-- These provide much faster product name and SKU searches

-- Full-text search index for product names
DROP INDEX IF EXISTS idx_dim_products_product_name_gin;
CREATE INDEX idx_dim_products_product_name_fts 
ON dim_products USING gin(
    to_tsvector('russian', COALESCE(product_name, '')) ||
    to_tsvector('english', COALESCE(product_name, ''))
);

-- Combined SKU search index for all SKU types
CREATE INDEX IF NOT EXISTS idx_dim_products_sku_combined_fts 
ON dim_products USING gin(
    to_tsvector('simple', 
        COALESCE(sku_ozon, '') || ' ' || 
        COALESCE(sku_wb, '') || ' ' || 
        COALESCE(sku_internal, '') || ' ' ||
        COALESCE(barcode, '')
    )
);

-- Materialized view for frequently accessed aggregations
-- This pre-calculates expensive aggregations for dashboard summary

DROP MATERIALIZED VIEW IF EXISTS mv_inventory_summary_stats;
CREATE MATERIALIZED VIEW mv_inventory_summary_stats AS
SELECT 
    warehouse_name,
    COUNT(*) as total_products,
    COUNT(CASE WHEN (quantity_present + quantity_reserved + preparing_for_sale) > 0 THEN 1 END) as products_with_stock,
    COUNT(CASE WHEN wsm.liquidity_status = 'critical' THEN 1 END) as critical_products,
    COUNT(CASE WHEN wsm.liquidity_status = 'low' THEN 1 END) as low_products,
    COUNT(CASE WHEN wsm.liquidity_status = 'normal' THEN 1 END) as normal_products,
    COUNT(CASE WHEN wsm.liquidity_status = 'excess' THEN 1 END) as excess_products,
    SUM(quantity_present + quantity_reserved + preparing_for_sale) as total_stock_units,
    SUM((quantity_present + quantity_reserved + preparing_for_sale) * COALESCE(dp.cost_price, 0)) as total_stock_value,
    AVG(CASE WHEN wsm.daily_sales_avg > 0 THEN wsm.days_of_stock END) as avg_days_of_stock,
    COUNT(CASE WHEN wsm.daily_sales_avg > 0 AND wsm.days_of_stock < 60 THEN 1 END) as products_needing_replenishment,
    MAX(GREATEST(i.updated_at, wsm.calculated_at)) as last_updated
FROM inventory i
LEFT JOIN warehouse_sales_metrics wsm ON i.product_id = wsm.product_id 
    AND i.warehouse_name = wsm.warehouse_name 
    AND i.source = wsm.source
LEFT JOIN dim_products dp ON i.product_id = dp.id
WHERE dp.id IS NOT NULL
GROUP BY warehouse_name;

-- Index on the materialized view
CREATE UNIQUE INDEX idx_mv_inventory_summary_stats_warehouse 
ON mv_inventory_summary_stats(warehouse_name);

-- Refresh function for the materialized view
CREATE OR REPLACE FUNCTION refresh_inventory_summary_stats()
RETURNS void AS $$
BEGIN
    REFRESH MATERIALIZED VIEW CONCURRENTLY mv_inventory_summary_stats;
END;
$$ LANGUAGE plpgsql;

-- Partitioning setup for large tables (if needed in the future)
-- This is commented out but ready for implementation if data grows significantly

/*
-- Partition inventory table by warehouse_name if it becomes very large
-- This would require data migration and is only needed for very large datasets

-- Create partitioned table
CREATE TABLE inventory_partitioned (
    LIKE inventory INCLUDING ALL
) PARTITION BY HASH (warehouse_name);

-- Create partitions (example for 4 partitions)
CREATE TABLE inventory_partition_0 PARTITION OF inventory_partitioned
    FOR VALUES WITH (modulus 4, remainder 0);
CREATE TABLE inventory_partition_1 PARTITION OF inventory_partitioned
    FOR VALUES WITH (modulus 4, remainder 1);
CREATE TABLE inventory_partition_2 PARTITION OF inventory_partitioned
    FOR VALUES WITH (modulus 4, remainder 2);
CREATE TABLE inventory_partition_3 PARTITION OF inventory_partitioned
    FOR VALUES WITH (modulus 4, remainder 3);
*/

-- Query optimization settings for better performance
-- These settings optimize PostgreSQL for analytical workloads

-- Increase work memory for complex queries (session-level)
-- This should be set in postgresql.conf for production
-- SET work_mem = '256MB';
-- SET maintenance_work_mem = '1GB';
-- SET effective_cache_size = '4GB';

-- Enable parallel query execution for large datasets
-- SET max_parallel_workers_per_gather = 4;
-- SET parallel_tuple_cost = 0.1;
-- SET parallel_setup_cost = 1000;

-- Statistics collection for better query planning
-- Increase statistics target for frequently queried columns
ALTER TABLE inventory ALTER COLUMN warehouse_name SET STATISTICS 1000;
ALTER TABLE inventory ALTER COLUMN quantity_present SET STATISTICS 1000;
ALTER TABLE warehouse_sales_metrics ALTER COLUMN daily_sales_avg SET STATISTICS 1000;
ALTER TABLE warehouse_sales_metrics ALTER COLUMN days_of_stock SET STATISTICS 1000;
ALTER TABLE warehouse_sales_metrics ALTER COLUMN liquidity_status SET STATISTICS 1000;
ALTER TABLE dim_products ALTER COLUMN product_name SET STATISTICS 1000;

-- Update table statistics
ANALYZE inventory;
ANALYZE warehouse_sales_metrics;
ANALYZE dim_products;

-- Create extended statistics for correlated columns
-- This helps the query planner make better decisions for multi-column queries

CREATE STATISTICS IF NOT EXISTS stat_inventory_warehouse_stock_extended 
ON warehouse_name, product_id, quantity_present, quantity_reserved, preparing_for_sale, updated_at
FROM inventory;

CREATE STATISTICS IF NOT EXISTS stat_warehouse_metrics_status_performance 
ON warehouse_name, product_id, liquidity_status, daily_sales_avg, days_of_stock, calculated_at
FROM warehouse_sales_metrics;

CREATE STATISTICS IF NOT EXISTS stat_products_search_performance 
ON product_name, sku_ozon, sku_wb, sku_internal, cost_price
FROM dim_products;

-- Update extended statistics
ANALYZE inventory;
ANALYZE warehouse_sales_metrics;
ANALYZE dim_products;

-- Performance monitoring views
-- These views help monitor query performance and identify bottlenecks

CREATE OR REPLACE VIEW v_slow_queries AS
SELECT 
    query,
    calls,
    total_time,
    mean_time,
    rows,
    100.0 * shared_blks_hit / nullif(shared_blks_hit + shared_blks_read, 0) AS hit_percent
FROM pg_stat_statements 
WHERE query LIKE '%v_detailed_inventory%' 
   OR query LIKE '%inventory%'
   OR query LIKE '%warehouse_sales_metrics%'
ORDER BY mean_time DESC;

CREATE OR REPLACE VIEW v_index_usage AS
SELECT 
    schemaname,
    tablename,
    indexname,
    idx_tup_read,
    idx_tup_fetch,
    idx_scan,
    CASE 
        WHEN idx_scan = 0 THEN 'Unused'
        WHEN idx_scan < 10 THEN 'Low usage'
        WHEN idx_scan < 100 THEN 'Medium usage'
        ELSE 'High usage'
    END as usage_level
FROM pg_stat_user_indexes 
WHERE schemaname = 'public'
  AND (tablename LIKE '%inventory%' OR tablename LIKE '%warehouse%' OR tablename LIKE '%dim_products%')
ORDER BY idx_scan DESC;

-- Cache warming queries
-- These queries can be run periodically to keep frequently accessed data in cache

-- Warm up cache for most active warehouses
PREPARE warm_cache_active_warehouses AS
SELECT warehouse_name, COUNT(*) 
FROM v_detailed_inventory 
WHERE current_stock > 0 OR daily_sales_avg > 0
GROUP BY warehouse_name 
ORDER BY COUNT(*) DESC 
LIMIT 20;

-- Warm up cache for critical inventory items
PREPARE warm_cache_critical_items AS
SELECT * FROM v_detailed_inventory 
WHERE stock_status IN ('critical', 'low') 
  AND daily_sales_avg > 0
ORDER BY urgency_score DESC, days_of_stock ASC 
LIMIT 500;

-- Warm up cache for high-turnover products
PREPARE warm_cache_high_turnover AS
SELECT * FROM v_detailed_inventory 
WHERE daily_sales_avg > 1 
  AND current_stock > 0
ORDER BY daily_sales_avg DESC 
LIMIT 500;

-- Performance optimization complete
-- Run EXPLAIN ANALYZE on the view to verify performance improvements

COMMENT ON MATERIALIZED VIEW mv_inventory_summary_stats IS 'Pre-calculated summary statistics for dashboard performance - refresh every 15 minutes';

-- Log completion
DO $$
BEGIN
    RAISE NOTICE 'Performance optimization indexes created successfully';
    RAISE NOTICE 'Materialized view mv_inventory_summary_stats created';
    RAISE NOTICE 'Extended statistics created for better query planning';
    RAISE NOTICE 'Performance monitoring views created';
END $$;