-- ===================================================================
-- Refactored v_detailed_inventory View with Functional Approach
-- ===================================================================
-- 
-- This script refactors the v_detailed_inventory view to use the immutable
-- functions and optimized indexes we created. This should provide much
-- better performance for stock status filtering and complex queries.
-- 
-- Requirements: 1.4, 3.4, 4.1
-- Task: 11.3 Refactor view to use functional approach
-- ===================================================================

-- Drop existing views to avoid dependency issues
DROP VIEW IF EXISTS v_detailed_inventory_filtered CASCADE;
DROP VIEW IF EXISTS v_detailed_inventory CASCADE;

-- Create the optimized view using functional approach
CREATE OR REPLACE VIEW v_detailed_inventory AS
SELECT
    -- Product identifiers
    p.id as product_id,
    p.product_name,
    COALESCE(
        NULLIF(p.sku_ozon, ''), 
        NULLIF(p.sku_wb, ''), 
        NULLIF(p.sku_internal, ''),
        'NO_SKU'
    ) as sku,
    p.sku_ozon,
    p.sku_wb,
    p.sku_internal,
    p.barcode,
    
    -- Warehouse information
    i.warehouse_name,
    COALESCE(i.cluster, 'Unknown') as cluster,
    i.source as marketplace_source,
    
    -- Visibility information
    COALESCE(p.visibility, 'UNKNOWN') as visibility,
    
    -- Stock information using functional approach
    COALESCE(i.quantity_present, 0) as quantity_present,
    COALESCE(i.quantity_reserved, 0) as quantity_reserved,
    COALESCE(i.preparing_for_sale, 0) as preparing_for_sale,
    COALESCE(i.in_supply_requests, 0) as in_supply_requests,
    COALESCE(i.in_transit, 0) as in_transit,
    
    -- Current stock (total stock)
    (COALESCE(i.quantity_present, 0) + 
     COALESCE(i.quantity_reserved, 0) + 
     COALESCE(i.preparing_for_sale, 0)) as current_stock,
    
    -- Available stock using immutable function (can be indexed!)
    get_available_stock(i.quantity_present, i.quantity_reserved) as available_stock,
    
    -- Sales metrics
    COALESCE(wsm.daily_sales_avg, 0) as daily_sales_avg,
    COALESCE(wsm.sales_last_28_days, 0) as sales_last_28_days,
    COALESCE(wsm.days_with_stock, 0) as days_with_stock,
    COALESCE(wsm.days_without_sales, 0) as days_without_sales,
    
    -- Days of stock using immutable function (can be indexed!)
    get_days_of_stock(i.quantity_present, i.quantity_reserved, wsm.daily_sales_avg) as days_of_stock,
    
    -- Stock status using immutable function (can be indexed!)
    get_product_stock_status(p.visibility, i.quantity_present, i.quantity_reserved, wsm.daily_sales_avg) as stock_status,
    
    -- Replenishment calculations (only for visible products)
    CASE
        WHEN COALESCE(p.visibility, 'UNKNOWN') IN ('VISIBLE', 'ACTIVE', 'продаётся') 
             AND COALESCE(wsm.daily_sales_avg, 0) > 0 THEN
            GREATEST(0,
                ROUND(wsm.daily_sales_avg * 60, 0) - 
                (get_available_stock(i.quantity_present, i.quantity_reserved) + 
                 COALESCE(i.in_supply_requests, 0) +
                 COALESCE(i.in_transit, 0))
            )
        ELSE 0
    END as recommended_qty,
    
    -- Estimated value of recommended quantity
    CASE
        WHEN COALESCE(p.visibility, 'UNKNOWN') IN ('VISIBLE', 'ACTIVE', 'продаётся')
             AND COALESCE(wsm.daily_sales_avg, 0) > 0 
             AND COALESCE(p.cost_price, 0) > 0 THEN
            GREATEST(0,
                ROUND(wsm.daily_sales_avg * 60, 0) - 
                (get_available_stock(i.quantity_present, i.quantity_reserved) + 
                 COALESCE(i.in_supply_requests, 0) +
                 COALESCE(i.in_transit, 0))
            ) * p.cost_price
        ELSE 0
    END as recommended_value,
    
    -- Urgency score (only for visible products)
    CASE
        WHEN COALESCE(p.visibility, 'UNKNOWN') NOT IN ('VISIBLE', 'ACTIVE', 'продаётся') THEN 0
        WHEN COALESCE(wsm.daily_sales_avg, 0) = 0 THEN 0
        WHEN get_available_stock(i.quantity_present, i.quantity_reserved) = 0 THEN 100
        WHEN get_days_of_stock(i.quantity_present, i.quantity_reserved, wsm.daily_sales_avg) IS NOT NULL THEN
            CASE 
                WHEN get_days_of_stock(i.quantity_present, i.quantity_reserved, wsm.daily_sales_avg) < 7 THEN 95
                WHEN get_days_of_stock(i.quantity_present, i.quantity_reserved, wsm.daily_sales_avg) < 14 THEN 80
                WHEN get_days_of_stock(i.quantity_present, i.quantity_reserved, wsm.daily_sales_avg) < 21 THEN 60
                WHEN get_days_of_stock(i.quantity_present, i.quantity_reserved, wsm.daily_sales_avg) < 30 THEN 40
                WHEN get_days_of_stock(i.quantity_present, i.quantity_reserved, wsm.daily_sales_avg) < 60 THEN 20
                ELSE 10
            END
        ELSE 10
    END as urgency_score,
    
    -- Stockout risk percentage (only for visible products)
    CASE
        WHEN COALESCE(p.visibility, 'UNKNOWN') NOT IN ('VISIBLE', 'ACTIVE', 'продаётся') THEN 0
        WHEN COALESCE(wsm.daily_sales_avg, 0) = 0 THEN 0
        WHEN get_available_stock(i.quantity_present, i.quantity_reserved) = 0 THEN 100
        WHEN get_days_of_stock(i.quantity_present, i.quantity_reserved, wsm.daily_sales_avg) IS NOT NULL THEN
            LEAST(100, GREATEST(0, 
                ROUND(100 * (1 - (get_days_of_stock(i.quantity_present, i.quantity_reserved, wsm.daily_sales_avg) / 30.0)), 0)
            ))
        ELSE 0
    END as stockout_risk,
    
    -- Financial metrics
    p.cost_price,
    p.margin_percent,
    
    -- Current stock value (based on available stock for visible products)
    CASE
        WHEN COALESCE(p.visibility, 'UNKNOWN') IN ('VISIBLE', 'ACTIVE', 'продаётся') THEN
            get_available_stock(i.quantity_present, i.quantity_reserved) * COALESCE(p.cost_price, 0)
        ELSE 0
    END as current_stock_value,
    
    -- Turnover rate (annual) - only for visible products
    CASE
        WHEN COALESCE(p.visibility, 'UNKNOWN') IN ('VISIBLE', 'ACTIVE', 'продаётся')
             AND get_available_stock(i.quantity_present, i.quantity_reserved) > 0 
             AND COALESCE(wsm.daily_sales_avg, 0) > 0 THEN
            ROUND((wsm.daily_sales_avg * 365) / get_available_stock(i.quantity_present, i.quantity_reserved), 2)
        ELSE 0
    END as turnover_rate,
    
    -- Sales trend
    CASE
        WHEN COALESCE(p.visibility, 'UNKNOWN') NOT IN ('VISIBLE', 'ACTIVE', 'продаётся') THEN 'hidden'
        WHEN COALESCE(wsm.days_without_sales, 0) > 14 THEN 'declining'
        WHEN COALESCE(wsm.daily_sales_avg, 0) > 0 AND 
             COALESCE(wsm.sales_last_28_days, 0) > (wsm.daily_sales_avg * 28 * 1.1) THEN 'growing'
        WHEN COALESCE(wsm.daily_sales_avg, 0) > 0 AND 
             COALESCE(wsm.sales_last_28_days, 0) < (wsm.daily_sales_avg * 28 * 0.9) THEN 'declining'
        ELSE 'stable'
    END as sales_trend,
    
    -- Metadata
    i.updated_at as inventory_updated_at,
    wsm.calculated_at as metrics_calculated_at,
    COALESCE(GREATEST(i.updated_at, wsm.calculated_at), wsm.calculated_at, i.updated_at) as last_updated,
    
    -- Last sale date (approximation based on days_without_sales)
    CASE
        WHEN COALESCE(wsm.days_without_sales, 0) > 0 THEN
            CURRENT_DATE - INTERVAL '1 day' * wsm.days_without_sales
        ELSE NULL
    END as last_sale_date

FROM inventory i
INNER JOIN dim_products p ON i.product_id = p.id
LEFT JOIN warehouse_sales_metrics wsm ON i.product_id = wsm.product_id 
    AND i.warehouse_name = wsm.warehouse_name 
    AND i.source = wsm.source;

-- Create the filtered view using the optimized approach
CREATE OR REPLACE VIEW v_detailed_inventory_filtered AS
SELECT *
FROM v_detailed_inventory
WHERE get_product_stock_status(visibility, quantity_present, quantity_reserved, daily_sales_avg) 
      NOT IN ('archived_or_hidden', 'out_of_stock')
  AND get_available_stock(quantity_present, quantity_reserved) > 0;

-- Create an ultra-fast view that uses the materialized view for complex queries
CREATE OR REPLACE VIEW v_detailed_inventory_fast AS
SELECT
    -- Map materialized view columns to expected API format
    msc.product_id,
    p.product_name,
    COALESCE(
        NULLIF(p.sku_ozon, ''), 
        NULLIF(p.sku_wb, ''), 
        NULLIF(p.sku_internal, ''),
        'NO_SKU'
    ) as sku,
    p.sku_ozon,
    p.sku_wb,
    p.sku_internal,
    p.barcode,
    msc.warehouse_name,
    i.cluster,
    msc.source as marketplace_source,
    msc.visibility,
    msc.quantity_present,
    msc.quantity_reserved,
    i.preparing_for_sale,
    i.in_supply_requests,
    i.in_transit,
    (msc.quantity_present + msc.quantity_reserved + COALESCE(i.preparing_for_sale, 0)) as current_stock,
    msc.available_stock,
    msc.daily_sales_avg,
    wsm.sales_last_28_days,
    wsm.days_with_stock,
    wsm.days_without_sales,
    msc.days_of_stock,
    msc.stock_status,
    
    -- Calculate replenishment using cached data
    CASE
        WHEN msc.visibility IN ('VISIBLE', 'ACTIVE', 'продаётся') 
             AND msc.daily_sales_avg > 0 THEN
            GREATEST(0,
                ROUND(msc.daily_sales_avg * 60, 0) - 
                (msc.available_stock + 
                 COALESCE(i.in_supply_requests, 0) +
                 COALESCE(i.in_transit, 0))
            )
        ELSE 0
    END as recommended_qty,
    
    -- Other calculated fields...
    p.cost_price,
    p.margin_percent,
    msc.available_stock * COALESCE(p.cost_price, 0) as current_stock_value,
    
    -- Metadata
    msc.last_updated as inventory_updated_at,
    wsm.calculated_at as metrics_calculated_at,
    msc.last_updated

FROM mv_stock_status_cache msc
INNER JOIN dim_products p ON msc.product_id = p.id
INNER JOIN inventory i ON msc.product_id = i.product_id 
    AND msc.warehouse_name = i.warehouse_name 
    AND msc.source = i.source
LEFT JOIN warehouse_sales_metrics wsm ON msc.product_id = wsm.product_id 
    AND msc.warehouse_name = wsm.warehouse_name 
    AND msc.source = wsm.source;

-- Add comments
COMMENT ON VIEW v_detailed_inventory IS 'Optimized detailed inventory view using immutable functions for better performance';
COMMENT ON VIEW v_detailed_inventory_filtered IS 'Filtered view that excludes hidden and out-of-stock products using functional indexes';
COMMENT ON VIEW v_detailed_inventory_fast IS 'Ultra-fast view using materialized cache for complex queries';

-- Log completion
SELECT 'Functional view refactoring completed successfully' as status;