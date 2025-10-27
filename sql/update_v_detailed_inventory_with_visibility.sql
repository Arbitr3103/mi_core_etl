-- ===================================================================
-- Updated v_detailed_inventory View with Visibility Logic
-- ===================================================================
-- 
-- This script updates the v_detailed_inventory view to include visibility
-- field from dim_products and implements the new stock status calculation
-- logic that combines visibility and availability.
-- 
-- Requirements: 1.4, 1.5, 4.1, 4.3
-- Task: 3.1 Implement new stock status calculation
-- ===================================================================

-- Drop the existing view
DROP VIEW IF EXISTS v_detailed_inventory;

-- Create the enhanced detailed inventory view with visibility logic
CREATE OR REPLACE VIEW v_detailed_inventory AS
WITH inventory_with_stock AS (
    -- Pre-filter to only include products with stock or recent activity
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
        -- Available stock calculation: present - reserved
        (COALESCE(i.quantity_present, 0) - COALESCE(i.quantity_reserved, 0)) as available_stock
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
    -- Pre-select products with visibility information
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
        dp.margin_percent,
        -- Include visibility field with proper NULL handling
        COALESCE(dp.visibility, 'UNKNOWN') as visibility
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
    
    -- Visibility information (NEW)
    pi.visibility,
    
    -- Stock information (updated available_stock calculation)
    COALESCE(iws.quantity_present, 0) as quantity_present,
    COALESCE(iws.quantity_reserved, 0) as quantity_reserved,
    COALESCE(iws.preparing_for_sale, 0) as preparing_for_sale,
    COALESCE(iws.in_supply_requests, 0) as in_supply_requests,
    COALESCE(iws.in_transit, 0) as in_transit,
    iws.current_stock,
    -- Available stock: present - reserved (as per requirements)
    GREATEST(0, iws.available_stock) as available_stock,
    
    -- Sales metrics
    COALESCE(sma.daily_sales_avg, 0) as daily_sales_avg,
    COALESCE(sma.sales_last_28_days, 0) as sales_last_28_days,
    COALESCE(sma.days_with_stock, 0) as days_with_stock,
    COALESCE(sma.days_without_sales, 0) as days_without_sales,
    
    -- Calculated metrics (optimized calculations)
    CASE
        WHEN COALESCE(sma.daily_sales_avg, 0) > 0 THEN
            ROUND(GREATEST(0, iws.available_stock) / sma.daily_sales_avg, 1)
        ELSE NULL
    END as days_of_stock,
    
    -- NEW STOCK STATUS CALCULATION with visibility and availability logic
    -- Status hierarchy: archived_or_hidden > out_of_stock > critical > low > normal > excess
    CASE
        -- First priority: Check visibility status (archived_or_hidden)
        WHEN pi.visibility NOT IN ('VISIBLE', 'ACTIVE', 'продаётся') OR pi.visibility IS NULL THEN 'archived_or_hidden'
        
        -- Second priority: Check available stock (out_of_stock)
        WHEN GREATEST(0, iws.available_stock) <= 0 THEN 'out_of_stock'
        
        -- Third priority and below: Calculate based on days of stock for visible products with stock
        WHEN COALESCE(sma.daily_sales_avg, 0) = 0 THEN 'no_sales'
        WHEN (GREATEST(0, iws.available_stock) / sma.daily_sales_avg) < 14 THEN 'critical'
        WHEN (GREATEST(0, iws.available_stock) / sma.daily_sales_avg) < 30 THEN 'low'
        WHEN (GREATEST(0, iws.available_stock) / sma.daily_sales_avg) < 60 THEN 'normal'
        ELSE 'excess'
    END as stock_status,
    
    -- Replenishment calculations (only for visible products)
    CASE
        WHEN pi.visibility IN ('VISIBLE', 'ACTIVE', 'продаётся') 
             AND COALESCE(sma.daily_sales_avg, 0) > 0 THEN
            GREATEST(0,
                ROUND(sma.daily_sales_avg * 60, 0) - 
                (GREATEST(0, iws.available_stock) + 
                 COALESCE(iws.in_supply_requests, 0) +
                 COALESCE(iws.in_transit, 0))
            )
        ELSE 0
    END as recommended_qty,
    
    -- Estimated value of recommended quantity
    CASE
        WHEN pi.visibility IN ('VISIBLE', 'ACTIVE', 'продаётся')
             AND COALESCE(sma.daily_sales_avg, 0) > 0 
             AND COALESCE(pi.cost_price, 0) > 0 THEN
            GREATEST(0,
                ROUND(sma.daily_sales_avg * 60, 0) - 
                (GREATEST(0, iws.available_stock) + 
                 COALESCE(iws.in_supply_requests, 0) +
                 COALESCE(iws.in_transit, 0))
            ) * pi.cost_price
        ELSE 0
    END as recommended_value,
    
    -- Urgency score (only for visible products)
    CASE
        WHEN pi.visibility NOT IN ('VISIBLE', 'ACTIVE', 'продаётся') THEN 0
        WHEN COALESCE(sma.daily_sales_avg, 0) = 0 THEN 0
        WHEN GREATEST(0, iws.available_stock) = 0 THEN 100
        WHEN COALESCE(sma.daily_sales_avg, 0) > 0 THEN
            CASE 
                WHEN (GREATEST(0, iws.available_stock) / sma.daily_sales_avg) < 7 THEN 95
                WHEN (GREATEST(0, iws.available_stock) / sma.daily_sales_avg) < 14 THEN 80
                WHEN (GREATEST(0, iws.available_stock) / sma.daily_sales_avg) < 21 THEN 60
                WHEN (GREATEST(0, iws.available_stock) / sma.daily_sales_avg) < 30 THEN 40
                WHEN (GREATEST(0, iws.available_stock) / sma.daily_sales_avg) < 60 THEN 20
                ELSE 10
            END
        ELSE 10
    END as urgency_score,
    
    -- Stockout risk percentage (only for visible products)
    CASE
        WHEN pi.visibility NOT IN ('VISIBLE', 'ACTIVE', 'продаётся') THEN 0
        WHEN COALESCE(sma.daily_sales_avg, 0) = 0 THEN 0
        WHEN GREATEST(0, iws.available_stock) = 0 THEN 100
        WHEN COALESCE(sma.daily_sales_avg, 0) > 0 THEN
            LEAST(100, GREATEST(0, 
                ROUND(100 * (1 - ((GREATEST(0, iws.available_stock) / sma.daily_sales_avg) / 30.0)), 0)
            ))
        ELSE 0
    END as stockout_risk,
    
    -- Financial metrics
    pi.cost_price,
    pi.margin_percent,
    
    -- Current stock value (based on available stock for visible products)
    CASE
        WHEN pi.visibility IN ('VISIBLE', 'ACTIVE', 'продаётся') THEN
            GREATEST(0, iws.available_stock) * COALESCE(pi.cost_price, 0)
        ELSE 0
    END as current_stock_value,
    
    -- Turnover rate (annual) - only for visible products
    CASE
        WHEN pi.visibility IN ('VISIBLE', 'ACTIVE', 'продаётся')
             AND GREATEST(0, iws.available_stock) > 0 
             AND COALESCE(sma.daily_sales_avg, 0) > 0 THEN
            ROUND((sma.daily_sales_avg * 365) / GREATEST(0, iws.available_stock), 2)
        ELSE 0
    END as turnover_rate,
    
    -- Sales trend (simplified)
    CASE
        WHEN pi.visibility NOT IN ('VISIBLE', 'ACTIVE', 'продаётся') THEN 'hidden'
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

-- Add comment to the enhanced view
COMMENT ON VIEW v_detailed_inventory IS 'Enhanced detailed inventory view with visibility-based stock status calculation and filtering';

-- Log completion
DO $
BEGIN
    RAISE NOTICE 'Enhanced v_detailed_inventory view created successfully';
    RAISE NOTICE 'New stock status calculation includes visibility logic';
    RAISE NOTICE 'Status hierarchy: archived_or_hidden > out_of_stock > critical > low > normal > excess';
    RAISE NOTICE 'Available stock calculation: present - reserved';
END $;