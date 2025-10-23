-- ===================================================================
-- PostgreSQL Materialized Views and Functions
-- Task 5.2: Создать оптимизированные представления и функции
-- ===================================================================

-- Connect to the database
\c mi_core_db;

-- Enable timing for performance monitoring
\timing on

-- ===================================================================
-- STEP 1: CREATE MATERIALIZED VIEWS FOR DASHBOARD
-- ===================================================================

-- Materialized view for dashboard inventory data
DROP MATERIALIZED VIEW IF EXISTS mv_dashboard_inventory CASCADE;
CREATE MATERIALIZED VIEW mv_dashboard_inventory AS
SELECT
    p.id,
    p.sku_ozon as sku,
    p.product_name as name,
    i.quantity_present as current_stock,
    i.quantity_present as available_stock,
    i.quantity_reserved as reserved_stock,
    i.warehouse_name,
    CASE 
        WHEN i.quantity_present <= 5 THEN 'critical'
        WHEN i.quantity_present <= 20 THEN 'low_stock'
        WHEN i.quantity_present > 100 THEN 'overstock'
        ELSE 'normal'
    END as stock_status,
    i.updated_at as last_updated,
    p.cost_price as price,
    'Auto Parts' as category,
    -- Additional calculated fields for better performance
    (i.quantity_present + i.quantity_reserved) as total_quantity,
    CASE 
        WHEN i.quantity_present <= 0 THEN 'out_of_stock'
        WHEN i.quantity_present <= 5 THEN 'critical'
        WHEN i.quantity_present <= 20 THEN 'low_stock'
        WHEN i.quantity_present > 100 THEN 'overstock'
        ELSE 'normal'
    END as detailed_status,
    -- Priority score for sorting
    CASE 
        WHEN i.quantity_present <= 0 THEN 1
        WHEN i.quantity_present <= 5 THEN 2
        WHEN i.quantity_present <= 20 THEN 3
        WHEN i.quantity_present > 100 THEN 4
        ELSE 5
    END as priority_score
FROM dim_products p
JOIN inventory i ON p.id = i.product_id
WHERE p.sku_ozon IS NOT NULL
  AND i.quantity_present IS NOT NULL;

-- Create indexes on materialized view
CREATE INDEX idx_mv_dashboard_inventory_stock_status ON mv_dashboard_inventory(stock_status);
CREATE INDEX idx_mv_dashboard_inventory_priority ON mv_dashboard_inventory(priority_score, current_stock);
CREATE INDEX idx_mv_dashboard_inventory_warehouse ON mv_dashboard_inventory(warehouse_name);
CREATE INDEX idx_mv_dashboard_inventory_updated ON mv_dashboard_inventory(last_updated DESC);

-- Materialized view for product turnover analysis
DROP MATERIALIZED VIEW IF EXISTS mv_product_turnover_analysis CASCADE;
CREATE MATERIALIZED VIEW mv_product_turnover_analysis AS
WITH sales_data AS (
    SELECT 
        sm.product_id,
        COUNT(*) as movement_count,
        SUM(CASE WHEN sm.quantity < 0 THEN ABS(sm.quantity) ELSE 0 END) as total_sold_30d,
        AVG(CASE WHEN sm.quantity < 0 THEN ABS(sm.quantity) ELSE 0 END) as avg_sale_quantity,
        COUNT(DISTINCT DATE(sm.movement_date)) as active_days,
        MAX(sm.movement_date) as last_sale_date,
        MIN(sm.movement_date) as first_sale_date
    FROM stock_movements sm
    WHERE sm.movement_date >= CURRENT_DATE - INTERVAL '30 days'
      AND sm.movement_type IN ('sale', 'order')
    GROUP BY sm.product_id
),
current_inventory AS (
    SELECT 
        product_id,
        SUM(quantity_present) as current_stock,
        SUM(quantity_reserved) as reserved_stock,
        COUNT(*) as warehouse_count,
        STRING_AGG(warehouse_name, ', ') as warehouses
    FROM inventory
    GROUP BY product_id
)
SELECT 
    dp.id as product_id,
    dp.sku_ozon,
    dp.sku_wb,
    dp.product_name,
    dp.cost_price,
    COALESCE(sd.total_sold_30d, 0) as total_sold_30d,
    COALESCE(sd.avg_sale_quantity, 0) as avg_sale_quantity,
    COALESCE(sd.active_days, 0) as active_days,
    COALESCE(ci.current_stock, 0) as current_stock,
    COALESCE(ci.reserved_stock, 0) as reserved_stock,
    COALESCE(ci.warehouse_count, 0) as warehouse_count,
    ci.warehouses,
    sd.last_sale_date,
    sd.first_sale_date,
    -- Calculate turnover metrics
    CASE 
        WHEN sd.total_sold_30d > 0 AND sd.active_days > 0 
        THEN sd.total_sold_30d / sd.active_days
        ELSE 0 
    END as avg_daily_sales,
    CASE 
        WHEN sd.total_sold_30d > 0 
        THEN COALESCE(ci.current_stock, 0) / (sd.total_sold_30d / 30.0)
        ELSE NULL 
    END as days_of_stock,
    -- Velocity classification
    CASE 
        WHEN sd.total_sold_30d = 0 THEN 'no_sales'
        WHEN sd.total_sold_30d <= 5 THEN 'slow_moving'
        WHEN sd.total_sold_30d <= 20 THEN 'medium_moving'
        ELSE 'fast_moving'
    END as velocity_category,
    -- Replenishment recommendation
    CASE 
        WHEN COALESCE(ci.current_stock, 0) <= 5 AND sd.total_sold_30d > 0 THEN 'urgent_replenishment'
        WHEN COALESCE(ci.current_stock, 0) <= 20 AND sd.total_sold_30d > 10 THEN 'replenishment_needed'
        WHEN COALESCE(ci.current_stock, 0) > 100 AND sd.total_sold_30d < 10 THEN 'overstock_review'
        ELSE 'normal'
    END as replenishment_status
FROM dim_products dp
LEFT JOIN sales_data sd ON dp.id = sd.product_id
LEFT JOIN current_inventory ci ON dp.id = ci.product_id
WHERE dp.sku_ozon IS NOT NULL;

-- Create indexes on turnover analysis view
CREATE INDEX idx_mv_turnover_velocity ON mv_product_turnover_analysis(velocity_category);
CREATE INDEX idx_mv_turnover_replenishment ON mv_product_turnover_analysis(replenishment_status);
CREATE INDEX idx_mv_turnover_days_stock ON mv_product_turnover_analysis(days_of_stock) WHERE days_of_stock IS NOT NULL;

-- Materialized view for MDM data quality metrics
DROP MATERIALIZED VIEW IF EXISTS mv_mdm_quality_dashboard CASCADE;
CREATE MATERIALIZED VIEW mv_mdm_quality_dashboard AS
WITH mapping_stats AS (
    SELECT 
        source,
        COUNT(*) as total_mappings,
        COUNT(CASE WHEN verification_status = 'auto' THEN 1 END) as auto_verified,
        COUNT(CASE WHEN verification_status = 'manual' THEN 1 END) as manual_verified,
        COUNT(CASE WHEN verification_status = 'pending' THEN 1 END) as pending_verification,
        COUNT(CASE WHEN verification_status = 'rejected' THEN 1 END) as rejected,
        AVG(confidence_score) as avg_confidence_score,
        COUNT(CASE WHEN confidence_score >= 0.9 THEN 1 END) as high_confidence,
        COUNT(CASE WHEN confidence_score BETWEEN 0.7 AND 0.89 THEN 1 END) as medium_confidence,
        COUNT(CASE WHEN confidence_score < 0.7 THEN 1 END) as low_confidence
    FROM sku_mapping
    GROUP BY source
),
master_stats AS (
    SELECT 
        status,
        COUNT(*) as count,
        COUNT(CASE WHEN updated_at >= CURRENT_DATE - INTERVAL '7 days' THEN 1 END) as updated_last_week
    FROM master_products
    GROUP BY status
)
SELECT 
    ms.source,
    ms.total_mappings,
    ms.auto_verified,
    ms.manual_verified,
    ms.pending_verification,
    ms.rejected,
    ROUND(ms.avg_confidence_score::numeric, 3) as avg_confidence_score,
    ms.high_confidence,
    ms.medium_confidence,
    ms.low_confidence,
    -- Quality percentages
    ROUND((ms.auto_verified::numeric / NULLIF(ms.total_mappings, 0) * 100), 2) as auto_verification_rate,
    ROUND((ms.high_confidence::numeric / NULLIF(ms.total_mappings, 0) * 100), 2) as high_confidence_rate,
    ROUND((ms.pending_verification::numeric / NULLIF(ms.total_mappings, 0) * 100), 2) as pending_rate,
    -- Quality score (0-100)
    ROUND((
        (ms.auto_verified * 1.0 + ms.manual_verified * 0.8 + ms.high_confidence * 0.3) / 
        NULLIF(ms.total_mappings, 0) * 100
    ), 2) as quality_score,
    CURRENT_TIMESTAMP as calculated_at
FROM mapping_stats ms;

-- Create indexes on MDM quality view
CREATE INDEX idx_mv_mdm_quality_source ON mv_mdm_quality_dashboard(source);
CREATE INDEX idx_mv_mdm_quality_score ON mv_mdm_quality_dashboard(quality_score DESC);

-- ===================================================================
-- STEP 2: CREATE POSTGRESQL FUNCTIONS FOR COMPLEX CALCULATIONS
-- ===================================================================

-- Function to calculate stock status with custom thresholds
CREATE OR REPLACE FUNCTION calculate_stock_status(
    current_stock INTEGER,
    critical_threshold INTEGER DEFAULT 5,
    low_threshold INTEGER DEFAULT 20,
    overstock_threshold INTEGER DEFAULT 100
) RETURNS TEXT AS $$
BEGIN
    IF current_stock IS NULL OR current_stock < 0 THEN
        RETURN 'unknown';
    ELSIF current_stock = 0 THEN
        RETURN 'out_of_stock';
    ELSIF current_stock <= critical_threshold THEN
        RETURN 'critical';
    ELSIF current_stock <= low_threshold THEN
        RETURN 'low_stock';
    ELSIF current_stock > overstock_threshold THEN
        RETURN 'overstock';
    ELSE
        RETURN 'normal';
    END IF;
END;
$$ LANGUAGE plpgsql IMMUTABLE;

-- Function to calculate replenishment recommendation
CREATE OR REPLACE FUNCTION calculate_replenishment_recommendation(
    product_id INTEGER,
    current_stock INTEGER DEFAULT NULL,
    sales_30d INTEGER DEFAULT NULL,
    lead_time_days INTEGER DEFAULT 14
) RETURNS TABLE(
    recommended_quantity INTEGER,
    priority_score DECIMAL(5,2),
    reason TEXT,
    urgency_level TEXT
) AS $$
DECLARE
    avg_daily_sales DECIMAL(10,2);
    safety_stock INTEGER;
    reorder_point INTEGER;
    recommended_qty INTEGER;
    priority DECIMAL(5,2);
    reason_text TEXT;
    urgency TEXT;
BEGIN
    -- Get current stock if not provided
    IF current_stock IS NULL THEN
        SELECT COALESCE(SUM(quantity_present), 0) 
        INTO current_stock 
        FROM inventory 
        WHERE inventory.product_id = calculate_replenishment_recommendation.product_id;
    END IF;
    
    -- Get sales data if not provided
    IF sales_30d IS NULL THEN
        SELECT COALESCE(SUM(CASE WHEN quantity < 0 THEN ABS(quantity) ELSE 0 END), 0)
        INTO sales_30d
        FROM stock_movements sm
        WHERE sm.product_id = calculate_replenishment_recommendation.product_id
          AND sm.movement_date >= CURRENT_DATE - INTERVAL '30 days'
          AND sm.movement_type IN ('sale', 'order');
    END IF;
    
    -- Calculate metrics
    avg_daily_sales := COALESCE(sales_30d / 30.0, 0);
    safety_stock := GREATEST(CEIL(avg_daily_sales * 7), 5); -- 1 week safety stock, minimum 5
    reorder_point := CEIL(avg_daily_sales * lead_time_days) + safety_stock;
    
    -- Determine recommendation
    IF current_stock <= 0 THEN
        recommended_qty := GREATEST(reorder_point * 2, 50);
        priority := 10.0;
        reason_text := 'Out of stock - urgent replenishment needed';
        urgency := 'critical';
    ELSIF current_stock <= 5 THEN
        recommended_qty := GREATEST(reorder_point - current_stock, 20);
        priority := 9.0;
        reason_text := 'Critical stock level';
        urgency := 'high';
    ELSIF current_stock < reorder_point THEN
        recommended_qty := reorder_point - current_stock + safety_stock;
        priority := 7.0;
        reason_text := 'Below reorder point';
        urgency := 'medium';
    ELSIF sales_30d = 0 AND current_stock > 50 THEN
        recommended_qty := 0;
        priority := 1.0;
        reason_text := 'No sales in 30 days - consider reducing stock';
        urgency := 'low';
    ELSE
        recommended_qty := 0;
        priority := 3.0;
        reason_text := 'Stock level adequate';
        urgency := 'low';
    END IF;
    
    RETURN QUERY SELECT recommended_qty, priority, reason_text, urgency;
END;
$$ LANGUAGE plpgsql;

-- Function to get dashboard data with caching
CREATE OR REPLACE FUNCTION get_dashboard_data(
    limit_per_category INTEGER DEFAULT 10,
    include_all BOOLEAN DEFAULT FALSE
) RETURNS TABLE(
    category TEXT,
    product_count BIGINT,
    products JSONB
) AS $$
DECLARE
    critical_products JSONB;
    low_stock_products JSONB;
    overstock_products JSONB;
BEGIN
    -- Get critical products
    SELECT jsonb_agg(
        jsonb_build_object(
            'id', id,
            'sku', sku,
            'name', name,
            'current_stock', current_stock,
            'available_stock', available_stock,
            'reserved_stock', reserved_stock,
            'warehouse_name', warehouse_name,
            'stock_status', stock_status,
            'last_updated', last_updated,
            'price', price,
            'category', category
        )
    )
    INTO critical_products
    FROM (
        SELECT *
        FROM mv_dashboard_inventory
        WHERE stock_status = 'critical'
        ORDER BY current_stock ASC, name
        LIMIT CASE WHEN include_all THEN NULL ELSE limit_per_category END
    ) critical_data;
    
    -- Get low stock products
    SELECT jsonb_agg(
        jsonb_build_object(
            'id', id,
            'sku', sku,
            'name', name,
            'current_stock', current_stock,
            'available_stock', available_stock,
            'reserved_stock', reserved_stock,
            'warehouse_name', warehouse_name,
            'stock_status', stock_status,
            'last_updated', last_updated,
            'price', price,
            'category', category
        )
    )
    INTO low_stock_products
    FROM (
        SELECT *
        FROM mv_dashboard_inventory
        WHERE stock_status = 'low_stock'
        ORDER BY current_stock ASC, name
        LIMIT CASE WHEN include_all THEN NULL ELSE limit_per_category END
    ) low_stock_data;
    
    -- Get overstock products
    SELECT jsonb_agg(
        jsonb_build_object(
            'id', id,
            'sku', sku,
            'name', name,
            'current_stock', current_stock,
            'available_stock', available_stock,
            'reserved_stock', reserved_stock,
            'warehouse_name', warehouse_name,
            'stock_status', stock_status,
            'last_updated', last_updated,
            'price', price,
            'category', category
        )
    )
    INTO overstock_products
    FROM (
        SELECT *
        FROM mv_dashboard_inventory
        WHERE stock_status = 'overstock'
        ORDER BY current_stock DESC, name
        LIMIT CASE WHEN include_all THEN NULL ELSE limit_per_category END
    ) overstock_data;
    
    -- Return results
    RETURN QUERY
    SELECT 'critical'::TEXT, 
           (SELECT COUNT(*) FROM mv_dashboard_inventory WHERE stock_status = 'critical'),
           COALESCE(critical_products, '[]'::jsonb)
    UNION ALL
    SELECT 'low_stock'::TEXT,
           (SELECT COUNT(*) FROM mv_dashboard_inventory WHERE stock_status = 'low_stock'),
           COALESCE(low_stock_products, '[]'::jsonb)
    UNION ALL
    SELECT 'overstock'::TEXT,
           (SELECT COUNT(*) FROM mv_dashboard_inventory WHERE stock_status = 'overstock'),
           COALESCE(overstock_products, '[]'::jsonb);
END;
$$ LANGUAGE plpgsql;

-- Function to analyze slow queries
CREATE OR REPLACE FUNCTION analyze_slow_queries(
    min_mean_time NUMERIC DEFAULT 100.0,
    limit_results INTEGER DEFAULT 20
) RETURNS TABLE(
    query_text TEXT,
    calls BIGINT,
    total_time NUMERIC,
    mean_time NUMERIC,
    rows_returned BIGINT,
    hit_percent NUMERIC,
    recommendation TEXT
) AS $$
BEGIN
    RETURN QUERY
    SELECT 
        LEFT(query, 100) as query_text,
        calls,
        total_time,
        mean_time,
        rows as rows_returned,
        ROUND(100.0 * shared_blks_hit / NULLIF(shared_blks_hit + shared_blks_read, 0), 2) as hit_percent,
        CASE 
            WHEN mean_time > 1000 THEN 'Critical - Review query structure'
            WHEN mean_time > 500 THEN 'High - Consider adding indexes'
            WHEN 100.0 * shared_blks_hit / NULLIF(shared_blks_hit + shared_blks_read, 0) < 90 THEN 'Low cache hit ratio - Check indexes'
            ELSE 'Monitor performance'
        END as recommendation
    FROM pg_stat_statements 
    WHERE mean_time >= min_mean_time
    ORDER BY mean_time DESC
    LIMIT limit_results;
END;
$$ LANGUAGE plpgsql;

-- ===================================================================
-- STEP 3: CREATE AUTOMATIC REFRESH FUNCTIONS AND TRIGGERS
-- ===================================================================

-- Function to refresh materialized views
CREATE OR REPLACE FUNCTION refresh_dashboard_materialized_views()
RETURNS VOID AS $$
BEGIN
    -- Refresh materialized views concurrently to avoid blocking
    REFRESH MATERIALIZED VIEW CONCURRENTLY mv_dashboard_inventory;
    REFRESH MATERIALIZED VIEW CONCURRENTLY mv_product_turnover_analysis;
    REFRESH MATERIALIZED VIEW CONCURRENTLY mv_mdm_quality_dashboard;
    
    -- Log the refresh
    INSERT INTO system_settings (setting_key, setting_value, description)
    VALUES ('last_mv_refresh', CURRENT_TIMESTAMP::TEXT, 'Last materialized view refresh')
    ON CONFLICT (setting_key) 
    DO UPDATE SET 
        setting_value = EXCLUDED.setting_value,
        updated_at = CURRENT_TIMESTAMP;
END;
$$ LANGUAGE plpgsql;

-- Function to refresh materialized views if data is stale
CREATE OR REPLACE FUNCTION refresh_if_stale(
    max_age_minutes INTEGER DEFAULT 30
) RETURNS BOOLEAN AS $$
DECLARE
    last_refresh TIMESTAMP;
    needs_refresh BOOLEAN := FALSE;
BEGIN
    -- Check when materialized views were last refreshed
    SELECT setting_value::TIMESTAMP INTO last_refresh
    FROM system_settings 
    WHERE setting_key = 'last_mv_refresh';
    
    -- If no record or older than max_age_minutes, refresh
    IF last_refresh IS NULL OR last_refresh < CURRENT_TIMESTAMP - (max_age_minutes || ' minutes')::INTERVAL THEN
        PERFORM refresh_dashboard_materialized_views();
        needs_refresh := TRUE;
    END IF;
    
    RETURN needs_refresh;
END;
$$ LANGUAGE plpgsql;

-- ===================================================================
-- STEP 4: CREATE PERFORMANCE ANALYSIS FUNCTIONS
-- ===================================================================

-- Function to analyze table performance
CREATE OR REPLACE FUNCTION analyze_table_performance()
RETURNS TABLE(
    table_name TEXT,
    total_size TEXT,
    table_size TEXT,
    index_size TEXT,
    row_count BIGINT,
    seq_scan BIGINT,
    seq_tup_read BIGINT,
    idx_scan BIGINT,
    idx_tup_fetch BIGINT,
    performance_score NUMERIC,
    recommendations TEXT[]
) AS $$
BEGIN
    RETURN QUERY
    WITH table_stats AS (
        SELECT 
            schemaname,
            tablename,
            pg_size_pretty(pg_total_relation_size(schemaname||'.'||tablename)) as total_size,
            pg_size_pretty(pg_relation_size(schemaname||'.'||tablename)) as table_size,
            pg_size_pretty(pg_total_relation_size(schemaname||'.'||tablename) - pg_relation_size(schemaname||'.'||tablename)) as index_size,
            pg_total_relation_size(schemaname||'.'||tablename) as total_bytes
        FROM pg_tables 
        WHERE schemaname = 'public'
    ),
    usage_stats AS (
        SELECT 
            schemaname,
            tablename,
            n_tup_ins + n_tup_upd + n_tup_del as total_modifications,
            seq_scan,
            seq_tup_read,
            idx_scan,
            idx_tup_fetch,
            n_live_tup as row_count
        FROM pg_stat_user_tables
    )
    SELECT 
        ts.tablename,
        ts.total_size,
        ts.table_size,
        ts.index_size,
        us.row_count,
        us.seq_scan,
        us.seq_tup_read,
        COALESCE(us.idx_scan, 0),
        COALESCE(us.idx_tup_fetch, 0),
        -- Performance score calculation (0-100)
        ROUND(
            CASE 
                WHEN us.seq_scan + COALESCE(us.idx_scan, 0) = 0 THEN 50
                ELSE LEAST(100, 
                    (COALESCE(us.idx_scan, 0)::NUMERIC / NULLIF(us.seq_scan + COALESCE(us.idx_scan, 0), 0) * 100)
                )
            END, 2
        ) as performance_score,
        -- Recommendations array
        ARRAY(
            SELECT recommendation 
            FROM (
                SELECT 'Add indexes - high sequential scan ratio' as recommendation
                WHERE us.seq_scan > COALESCE(us.idx_scan, 0) * 2 AND us.seq_scan > 100
                UNION ALL
                SELECT 'Consider partitioning - large table size' as recommendation
                WHERE ts.total_bytes > 1073741824 -- 1GB
                UNION ALL
                SELECT 'Analyze table statistics' as recommendation
                WHERE us.seq_tup_read > us.row_count * 10
                UNION ALL
                SELECT 'Review unused indexes' as recommendation
                WHERE COALESCE(us.idx_scan, 0) = 0 AND ts.index_size != '0 bytes'
            ) recommendations
        ) as recommendations
    FROM table_stats ts
    LEFT JOIN usage_stats us ON ts.schemaname = us.schemaname AND ts.tablename = us.tablename
    ORDER BY ts.total_bytes DESC;
END;
$$ LANGUAGE plpgsql;

-- ===================================================================
-- STEP 5: SETUP AUTOMATIC REFRESH SCHEDULE
-- ===================================================================

-- Create a function to be called by cron for automatic refresh
CREATE OR REPLACE FUNCTION scheduled_materialized_view_refresh()
RETURNS TEXT AS $$
DECLARE
    result TEXT;
BEGIN
    -- Refresh materialized views
    PERFORM refresh_dashboard_materialized_views();
    
    -- Update statistics
    ANALYZE mv_dashboard_inventory;
    ANALYZE mv_product_turnover_analysis;
    ANALYZE mv_mdm_quality_dashboard;
    
    result := 'Materialized views refreshed at ' || CURRENT_TIMESTAMP;
    
    -- Log the operation
    INSERT INTO audit_log (table_name, record_id, action, new_values, user_id)
    VALUES ('materialized_views', 'refresh', 'UPDATE', 
            jsonb_build_object('refreshed_at', CURRENT_TIMESTAMP, 'status', 'success'),
            'system_cron');
    
    RETURN result;
END;
$$ LANGUAGE plpgsql;

-- ===================================================================
-- STEP 6: CREATE QUERY PERFORMANCE MONITORING
-- ===================================================================

-- Enable pg_stat_statements if not already enabled
CREATE EXTENSION IF NOT EXISTS pg_stat_statements;

-- Function to reset query statistics
CREATE OR REPLACE FUNCTION reset_query_stats()
RETURNS TEXT AS $$
BEGIN
    SELECT pg_stat_statements_reset();
    RETURN 'Query statistics reset at ' || CURRENT_TIMESTAMP;
END;
$$ LANGUAGE plpgsql;

-- ===================================================================
-- COMPLETION AND TESTING
-- ===================================================================

-- Test materialized views
SELECT 'Testing materialized views...' as status;

-- Test dashboard materialized view
SELECT 
    stock_status,
    COUNT(*) as count
FROM mv_dashboard_inventory
GROUP BY stock_status
ORDER BY 
    CASE stock_status
        WHEN 'critical' THEN 1
        WHEN 'low_stock' THEN 2
        WHEN 'normal' THEN 3
        WHEN 'overstock' THEN 4
    END;

-- Test turnover analysis view
SELECT 
    velocity_category,
    COUNT(*) as count,
    AVG(days_of_stock) as avg_days_of_stock
FROM mv_product_turnover_analysis
WHERE days_of_stock IS NOT NULL
GROUP BY velocity_category;

-- Test dashboard function
SELECT 
    category,
    product_count,
    jsonb_array_length(products) as returned_products
FROM get_dashboard_data(5, false);

-- Test performance analysis
SELECT 
    table_name,
    total_size,
    performance_score,
    array_length(recommendations, 1) as recommendation_count
FROM analyze_table_performance()
WHERE table_name IN ('inventory', 'dim_products', 'stock_movements')
ORDER BY performance_score DESC;

-- Initial refresh of materialized views
SELECT refresh_dashboard_materialized_views();

SELECT 'PostgreSQL materialized views and functions created successfully!' as status,
       CURRENT_TIMESTAMP as completed_at;

\timing off