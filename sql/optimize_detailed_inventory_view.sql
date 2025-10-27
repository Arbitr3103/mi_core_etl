-- ===================================================================
-- Optimized Detailed Inventory View
-- ===================================================================
-- 
-- Optimized version of the detailed inventory view that takes advantage
-- of new performance indexes and uses more efficient query patterns.
-- 
-- Requirements: 7.1, 7.2
-- Task: 4.1 Database performance optimization
-- ===================================================================

-- Drop the existing view
DROP VIEW IF EXISTS v_detailed_inventory;

-- Create the optimized detailed inventory view
CREATE OR REPLACE VIEW v_detailed_inventory AS
WITH inventory_with_stock AS (
    -- Pre-filter to only include products with stock or recent activity
    -- This significantly reduces the dataset size for subsequent operations
    SELECT 
        i.product_id,
        i.warehouse_name,
        i.source,
        i.quantity_present,
        i.quantity_reserved,
        i.preparing_for_sale,
        i.in_supply_requests,
        i.in_transit,
        i.cluster,
        i.updated_at,
        -- Pre-calculate total stock to avoid repeated calculations
        (COALESCE(i.quantity_present, 0) + 
         COALESCE(i.quantity_reserved, 0) + 
         COALESCE(i.preparing_for_sale, 0)) as current_stock,
        (COALESCE(i.quantity_present, 0) + 
         COALESCE(i.preparing_for_sale, 0)) as available_stock
    FROM inventory i
    WHERE 
        -- Only include products with stock or that are tracked in sales metrics
        (COALESCE(i.quantity_present, 0) + 
         COALESCE(i.quantity_reserved, 0) + 
         COALESCE(i.preparing_for_sale, 0)) > 0
        OR EXISTS (
            SELECT 1 FROM warehouse_sales_metrics wsm 
            WHERE wsm.product_id = i.product_id 
              AND wsm.warehouse_name = i.warehouse_name 
              AND wsm.source = i.source
              AND (wsm.daily_sales_avg > 0 OR wsm.sales_last_28_days > 0)
        )
),
sales_metrics_active AS (
    -- Pre-filter sales metrics to only include active products
    SELECT 
        wsm.product_id,
        wsm.warehouse_name,
        wsm.source,
        wsm.daily_sales_avg,
        wsm.sales_last_28_days,
        wsm.days_with_stock,
        wsm.days_without_sales,
        wsm.liquidity_status,
        wsm.days_of_stock,
        wsm.calculated_at
    FROM warehouse_sales_metrics wsm
    WHERE wsm.daily_sales_avg > 0 OR wsm.sales_last_28_days > 0
),
product_info AS (
    -- Pre-select only the products we need
    SELECT 
        dp.id,
        dp.product_name,
        -- Use COALESCE to create a single SKU field for better performance
        COALESCE(
            NULLIF(dp.sku_ozon, ''), 
            NULLIF(dp.sku_wb, ''), 
            NULLIF(dp.sku_internal, ''),
            'NO_SKU'
        ) as sku,
        dp.sku_ozon,
        dp.sku_wb,
        dp.sku_internal,
        dp.barcode,
        dp.cost_price,
        dp.margin_percent
    FROM dim_products dp
    WHERE dp.id IN (
        SELECT DISTINCT product_id FROM inventory_with_stock
        UNION
        SELECT DISTINCT product_id FROM sales_metrics_active
    )
)
SELECT
    -- Product identifiers
    pi.id as product_id,
    pi.product_name,
    pi.sku,
    pi.sku_ozon,
    pi.sku_wb,
    pi.sku_internal,
    pi.barcode,
    
    -- Warehouse information
    iws.warehouse_name,
    COALESCE(iws.cluster, 'Unknown') as cluster,
    iws.source as marketplace_source,
    
    -- Stock information (pre-calculated in CTE)
    COALESCE(iws.quantity_present, 0) as quantity_present,
    COALESCE(iws.quantity_reserved, 0) as quantity_reserved,
    COALESCE(iws.preparing_for_sale, 0) as preparing_for_sale,
    COALESCE(iws.in_supply_requests, 0) as in_supply_requests,
    COALESCE(iws.in_transit, 0) as in_transit,
    iws.current_stock,
    iws.available_stock,
    
    -- Sales metrics
    COALESCE(sma.daily_sales_avg, 0) as daily_sales_avg,
    COALESCE(sma.sales_last_28_days, 0) as sales_last_28_days,
    COALESCE(sma.days_with_stock, 0) as days_with_stock,
    COALESCE(sma.days_without_sales, 0) as days_without_sales,
    
    -- Calculated metrics (optimized calculations)
    CASE
        WHEN COALESCE(sma.daily_sales_avg, 0) > 0 THEN
            ROUND(iws.current_stock / sma.daily_sales_avg, 1)
        ELSE NULL
    END as days_of_stock,
    
    -- Stock status calculation (simplified logic)
    CASE
        WHEN COALESCE(sma.daily_sales_avg, 0) = 0 THEN 'no_sales'
        WHEN iws.current_stock = 0 THEN 'out_of_stock'
        WHEN sma.days_of_stock IS NOT NULL THEN sma.liquidity_status
        WHEN (iws.current_stock / sma.daily_sales_avg) < 14 THEN 'critical'
        WHEN (iws.current_stock / sma.daily_sales_avg) < 30 THEN 'low'
        WHEN (iws.current_stock / sma.daily_sales_avg) < 60 THEN 'normal'
        ELSE 'excess'
    END as stock_status,
    
    -- Replenishment calculations (optimized)
    CASE
        WHEN COALESCE(sma.daily_sales_avg, 0) > 0 THEN
            GREATEST(0,
                ROUND(sma.daily_sales_avg * 60, 0) - 
                (iws.current_stock + 
                 COALESCE(iws.in_supply_requests, 0) +
                 COALESCE(iws.in_transit, 0))
            )
        ELSE 0
    END as recommended_qty,
    
    -- Estimated value of recommended quantity
    CASE
        WHEN COALESCE(sma.daily_sales_avg, 0) > 0 AND COALESCE(pi.cost_price, 0) > 0 THEN
            GREATEST(0,
                ROUND(sma.daily_sales_avg * 60, 0) - 
                (iws.current_stock + 
                 COALESCE(iws.in_supply_requests, 0) +
                 COALESCE(iws.in_transit, 0))
            ) * pi.cost_price
        ELSE 0
    END as recommended_value,
    
    -- Urgency score (simplified calculation for better performance)
    CASE
        WHEN COALESCE(sma.daily_sales_avg, 0) = 0 THEN 0
        WHEN iws.current_stock = 0 THEN 100
        WHEN sma.days_of_stock IS NOT NULL THEN
            CASE 
                WHEN sma.days_of_stock < 7 THEN 95
                WHEN sma.days_of_stock < 14 THEN 80
                WHEN sma.days_of_stock < 21 THEN 60
                WHEN sma.days_of_stock < 30 THEN 40
                WHEN sma.days_of_stock < 60 THEN 20
                ELSE 10
            END
        ELSE 10
    END as urgency_score,
    
    -- Stockout risk percentage (simplified)
    CASE
        WHEN COALESCE(sma.daily_sales_avg, 0) = 0 THEN 0
        WHEN iws.current_stock = 0 THEN 100
        WHEN sma.days_of_stock IS NOT NULL THEN
            LEAST(100, GREATEST(0, ROUND(100 * (1 - (sma.days_of_stock / 30.0)), 0)))
        ELSE 0
    END as stockout_risk,
    
    -- Financial metrics
    pi.cost_price,
    pi.margin_percent,
    
    -- Current stock value
    iws.current_stock * COALESCE(pi.cost_price, 0) as current_stock_value,
    
    -- Turnover rate (annual) - simplified calculation
    CASE
        WHEN iws.current_stock > 0 AND COALESCE(sma.daily_sales_avg, 0) > 0 THEN
            ROUND((sma.daily_sales_avg * 365) / iws.current_stock, 2)
        ELSE 0
    END as turnover_rate,
    
    -- Sales trend (simplified)
    CASE
        WHEN COALESCE(sma.days_without_sales, 0) > 14 THEN 'declining'
        WHEN COALESCE(sma.daily_sales_avg, 0) > 0 AND 
             COALESCE(sma.sales_last_28_days, 0) > (sma.daily_sales_avg * 28 * 1.1) THEN 'growing'
        WHEN COALESCE(sma.daily_sales_avg, 0) > 0 AND 
             COALESCE(sma.sales_last_28_days, 0) < (sma.daily_sales_avg * 28 * 0.9) THEN 'declining'
        ELSE 'stable'
    END as sales_trend,
    
    -- Metadata
    iws.updated_at as inventory_updated_at,
    sma.calculated_at as metrics_calculated_at,
    COALESCE(GREATEST(iws.updated_at, sma.calculated_at), sma.calculated_at, iws.updated_at) as last_updated,
    
    -- Last sale date (approximation based on days_without_sales)
    CASE
        WHEN COALESCE(sma.days_without_sales, 0) > 0 THEN
            CURRENT_DATE - INTERVAL '1 day' * sma.days_without_sales
        ELSE NULL
    END as last_sale_date

FROM inventory_with_stock iws
LEFT JOIN sales_metrics_active sma ON iws.product_id = sma.product_id 
    AND iws.warehouse_name = sma.warehouse_name 
    AND iws.source = sma.source
INNER JOIN product_info pi ON iws.product_id = pi.id;

-- Add comment to the optimized view
COMMENT ON VIEW v_detailed_inventory IS 'Optimized detailed inventory view for warehouse dashboard - uses CTEs and pre-filtering for better performance';

-- Create a function to get view performance statistics
CREATE OR REPLACE FUNCTION get_detailed_inventory_performance_stats()
RETURNS TABLE (
    metric_name text,
    metric_value text,
    description text
) AS $$
BEGIN
    RETURN QUERY
    SELECT 
        'total_rows'::text,
        (SELECT COUNT(*)::text FROM v_detailed_inventory),
        'Total number of product-warehouse pairs in the view'::text
    UNION ALL
    SELECT 
        'products_with_stock'::text,
        (SELECT COUNT(*)::text FROM v_detailed_inventory WHERE current_stock > 0),
        'Number of product-warehouse pairs with current stock'::text
    UNION ALL
    SELECT 
        'products_with_sales'::text,
        (SELECT COUNT(*)::text FROM v_detailed_inventory WHERE daily_sales_avg > 0),
        'Number of product-warehouse pairs with sales activity'::text
    UNION ALL
    SELECT 
        'critical_products'::text,
        (SELECT COUNT(*)::text FROM v_detailed_inventory WHERE stock_status = 'critical'),
        'Number of products in critical stock status'::text
    UNION ALL
    SELECT 
        'products_needing_replenishment'::text,
        (SELECT COUNT(*)::text FROM v_detailed_inventory WHERE recommended_qty > 0),
        'Number of products that need replenishment'::text
    UNION ALL
    SELECT 
        'avg_query_time'::text,
        COALESCE(
            (SELECT ROUND(mean_time, 2)::text || ' ms' 
             FROM pg_stat_statements 
             WHERE query LIKE '%v_detailed_inventory%' 
             ORDER BY calls DESC LIMIT 1),
            'No data available'
        ),
        'Average query execution time for the view'::text;
END;
$$ LANGUAGE plpgsql;

-- Create a function to refresh materialized view and update statistics
CREATE OR REPLACE FUNCTION refresh_inventory_performance_data()
RETURNS text AS $$
DECLARE
    start_time timestamp;
    end_time timestamp;
    refresh_duration interval;
BEGIN
    start_time := clock_timestamp();
    
    -- Refresh the materialized view
    REFRESH MATERIALIZED VIEW CONCURRENTLY mv_inventory_summary_stats;
    
    -- Update table statistics
    ANALYZE inventory;
    ANALYZE warehouse_sales_metrics;
    ANALYZE dim_products;
    
    end_time := clock_timestamp();
    refresh_duration := end_time - start_time;
    
    RETURN 'Performance data refreshed successfully in ' || refresh_duration::text;
END;
$$ LANGUAGE plpgsql;

-- Log completion
DO $$
BEGIN
    RAISE NOTICE 'Optimized detailed inventory view created successfully';
    RAISE NOTICE 'Performance monitoring functions created';
    RAISE NOTICE 'View uses CTEs and pre-filtering for improved performance';
END $$;