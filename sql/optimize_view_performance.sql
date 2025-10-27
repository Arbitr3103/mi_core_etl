-- ===================================================================
-- View Performance Optimization
-- ===================================================================
-- 
-- This script creates optimized indexes and simplifies the v_detailed_inventory
-- view to improve performance by eliminating sequential scans and optimizing JOINs.
-- 
-- Requirements: 3.4, 4.4
-- Task: 3.3 Optimize view performance
-- ===================================================================

-- Step 1: Create missing indexes for efficient JOINs
-- These indexes will help PostgreSQL avoid sequential scans

-- Index on inventory.product_id for JOIN with dim_products
CREATE INDEX IF NOT EXISTS idx_inventory_product_id ON inventory(product_id);

-- Index on inventory fields used in WHERE conditions
CREATE INDEX IF NOT EXISTS idx_inventory_stock_filter ON inventory(quantity_present, quantity_reserved) 
WHERE (quantity_present > 0 OR quantity_reserved > 0);

-- Composite index on warehouse_sales_metrics for efficient JOIN
CREATE INDEX IF NOT EXISTS idx_wsm_product_warehouse ON warehouse_sales_metrics(product_id, warehouse_name);

-- Index on warehouse_sales_metrics for filtering active products
CREATE INDEX IF NOT EXISTS idx_wsm_active_products ON warehouse_sales_metrics(daily_sales_avg, sales_last_28_days)
WHERE (daily_sales_avg > 0 OR sales_last_28_days > 0);

-- Step 2: Create optimized view without complex CTEs
DROP VIEW IF EXISTS v_detailed_inventory;

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
    
    -- Visibility information (NEW)
    COALESCE(p.visibility, 'UNKNOWN') as visibility,
    
    -- Stock information with corrected available_stock calculation
    COALESCE(i.quantity_present, 0) as quantity_present,
    COALESCE(i.quantity_reserved, 0) as quantity_reserved,
    COALESCE(i.preparing_for_sale, 0) as preparing_for_sale,
    COALESCE(i.in_supply_requests, 0) as in_supply_requests,
    COALESCE(i.in_transit, 0) as in_transit,
    
    -- Current stock (total stock)
    (COALESCE(i.quantity_present, 0) + 
     COALESCE(i.quantity_reserved, 0) + 
     COALESCE(i.preparing_for_sale, 0)) as current_stock,
    
    -- Available stock: present - reserved (as per requirements)
    GREATEST(0, COALESCE(i.quantity_present, 0) - COALESCE(i.quantity_reserved, 0)) as available_stock,
    
    -- Sales metrics
    COALESCE(wsm.daily_sales_avg, 0) as daily_sales_avg,
    COALESCE(wsm.sales_last_28_days, 0) as sales_last_28_days,
    COALESCE(wsm.days_with_stock, 0) as days_with_stock,
    COALESCE(wsm.days_without_sales, 0) as days_without_sales,
    
    -- Days of stock calculation
    CASE
        WHEN COALESCE(wsm.daily_sales_avg, 0) > 0 THEN
            ROUND(GREATEST(0, COALESCE(i.quantity_present, 0) - COALESCE(i.quantity_reserved, 0)) / wsm.daily_sales_avg, 1)
        ELSE NULL
    END as days_of_stock,
    
    -- NEW STOCK STATUS CALCULATION with visibility and availability logic
    -- Status hierarchy: archived_or_hidden > out_of_stock > critical > low > normal > excess
    CASE
        -- First priority: Check visibility status (archived_or_hidden)
        WHEN COALESCE(p.visibility, 'UNKNOWN') NOT IN ('VISIBLE', 'ACTIVE', 'продаётся') THEN 'archived_or_hidden'
        
        -- Second priority: Check available stock (out_of_stock)
        WHEN GREATEST(0, COALESCE(i.quantity_present, 0) - COALESCE(i.quantity_reserved, 0)) <= 0 THEN 'out_of_stock'
        
        -- Third priority and below: Calculate based on days of stock for visible products with stock
        WHEN COALESCE(wsm.daily_sales_avg, 0) = 0 THEN 'no_sales'
        WHEN (GREATEST(0, COALESCE(i.quantity_present, 0) - COALESCE(i.quantity_reserved, 0)) / wsm.daily_sales_avg) < 14 THEN 'critical'
        WHEN (GREATEST(0, COALESCE(i.quantity_present, 0) - COALESCE(i.quantity_reserved, 0)) / wsm.daily_sales_avg) < 30 THEN 'low'
        WHEN (GREATEST(0, COALESCE(i.quantity_present, 0) - COALESCE(i.quantity_reserved, 0)) / wsm.daily_sales_avg) < 60 THEN 'normal'
        ELSE 'excess'
    END as stock_status,
    
    -- Replenishment calculations (only for visible products)
    CASE
        WHEN COALESCE(p.visibility, 'UNKNOWN') IN ('VISIBLE', 'ACTIVE', 'продаётся') 
             AND COALESCE(wsm.daily_sales_avg, 0) > 0 THEN
            GREATEST(0,
                ROUND(wsm.daily_sales_avg * 60, 0) - 
                (GREATEST(0, COALESCE(i.quantity_present, 0) - COALESCE(i.quantity_reserved, 0)) + 
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
                (GREATEST(0, COALESCE(i.quantity_present, 0) - COALESCE(i.quantity_reserved, 0)) + 
                 COALESCE(i.in_supply_requests, 0) +
                 COALESCE(i.in_transit, 0))
            ) * p.cost_price
        ELSE 0
    END as recommended_value,
    
    -- Urgency score (only for visible products)
    CASE
        WHEN COALESCE(p.visibility, 'UNKNOWN') NOT IN ('VISIBLE', 'ACTIVE', 'продаётся') THEN 0
        WHEN COALESCE(wsm.daily_sales_avg, 0) = 0 THEN 0
        WHEN GREATEST(0, COALESCE(i.quantity_present, 0) - COALESCE(i.quantity_reserved, 0)) = 0 THEN 100
        WHEN COALESCE(wsm.daily_sales_avg, 0) > 0 THEN
            CASE 
                WHEN (GREATEST(0, COALESCE(i.quantity_present, 0) - COALESCE(i.quantity_reserved, 0)) / wsm.daily_sales_avg) < 7 THEN 95
                WHEN (GREATEST(0, COALESCE(i.quantity_present, 0) - COALESCE(i.quantity_reserved, 0)) / wsm.daily_sales_avg) < 14 THEN 80
                WHEN (GREATEST(0, COALESCE(i.quantity_present, 0) - COALESCE(i.quantity_reserved, 0)) / wsm.daily_sales_avg) < 21 THEN 60
                WHEN (GREATEST(0, COALESCE(i.quantity_present, 0) - COALESCE(i.quantity_reserved, 0)) / wsm.daily_sales_avg) < 30 THEN 40
                WHEN (GREATEST(0, COALESCE(i.quantity_present, 0) - COALESCE(i.quantity_reserved, 0)) / wsm.daily_sales_avg) < 60 THEN 20
                ELSE 10
            END
        ELSE 10
    END as urgency_score,
    
    -- Stockout risk percentage (only for visible products)
    CASE
        WHEN COALESCE(p.visibility, 'UNKNOWN') NOT IN ('VISIBLE', 'ACTIVE', 'продаётся') THEN 0
        WHEN COALESCE(wsm.daily_sales_avg, 0) = 0 THEN 0
        WHEN GREATEST(0, COALESCE(i.quantity_present, 0) - COALESCE(i.quantity_reserved, 0)) = 0 THEN 100
        WHEN COALESCE(wsm.daily_sales_avg, 0) > 0 THEN
            LEAST(100, GREATEST(0, 
                ROUND(100 * (1 - ((GREATEST(0, COALESCE(i.quantity_present, 0) - COALESCE(i.quantity_reserved, 0)) / wsm.daily_sales_avg) / 30.0)), 0)
            ))
        ELSE 0
    END as stockout_risk,
    
    -- Financial metrics
    p.cost_price,
    p.margin_percent,
    
    -- Current stock value (based on available stock for visible products)
    CASE
        WHEN COALESCE(p.visibility, 'UNKNOWN') IN ('VISIBLE', 'ACTIVE', 'продаётся') THEN
            GREATEST(0, COALESCE(i.quantity_present, 0) - COALESCE(i.quantity_reserved, 0)) * COALESCE(p.cost_price, 0)
        ELSE 0
    END as current_stock_value,
    
    -- Turnover rate (annual) - only for visible products
    CASE
        WHEN COALESCE(p.visibility, 'UNKNOWN') IN ('VISIBLE', 'ACTIVE', 'продаётся')
             AND GREATEST(0, COALESCE(i.quantity_present, 0) - COALESCE(i.quantity_reserved, 0)) > 0 
             AND COALESCE(wsm.daily_sales_avg, 0) > 0 THEN
            ROUND((wsm.daily_sales_avg * 365) / GREATEST(0, COALESCE(i.quantity_present, 0) - COALESCE(i.quantity_reserved, 0)), 2)
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

-- Update the filtered view as well
DROP VIEW IF EXISTS v_detailed_inventory_filtered;

CREATE OR REPLACE VIEW v_detailed_inventory_filtered AS
SELECT *
FROM v_detailed_inventory
WHERE visibility IN ('VISIBLE', 'ACTIVE', 'продаётся')
  AND available_stock > 0;

-- Add comments
COMMENT ON VIEW v_detailed_inventory IS 'Optimized detailed inventory view with visibility-based stock status calculation - uses proper indexes for performance';
COMMENT ON VIEW v_detailed_inventory_filtered IS 'Filtered version that excludes hidden products and products without available stock by default';

-- Step 3: Update table statistics to help query planner
ANALYZE inventory;
ANALYZE dim_products;
ANALYZE warehouse_sales_metrics;

-- Log completion
SELECT 'View performance optimization completed successfully' as status;