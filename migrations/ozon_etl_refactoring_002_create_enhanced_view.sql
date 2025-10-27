-- Ozon ETL Refactoring Migration 002: Create Enhanced View
-- Description: Creates enhanced v_detailed_inventory view with stock status calculation
-- Author: ETL Development Team
-- Date: 2025-10-27
-- Rollback: ozon_etl_refactoring_002_rollback.sql

BEGIN;

-- Create immutable function for stock status calculation
CREATE OR REPLACE FUNCTION get_product_stock_status(
    p_visibility VARCHAR(50),
    p_present INTEGER,
    p_reserved INTEGER,
    p_daily_sales_avg DECIMAL(10,2)
) RETURNS VARCHAR(50) AS $$
BEGIN
    -- Check visibility first - hidden products are always archived_or_hidden
    IF p_visibility IS NULL OR p_visibility NOT IN ('VISIBLE', 'ACTIVE', 'продаётся') THEN
        RETURN 'archived_or_hidden';
    END IF;
    
    -- Check available stock - no stock means out_of_stock
    IF p_present IS NULL OR p_reserved IS NULL OR (p_present - p_reserved) <= 0 THEN
        RETURN 'out_of_stock';
    END IF;
    
    -- If no sales data, default to normal
    IF p_daily_sales_avg IS NULL OR p_daily_sales_avg <= 0 THEN
        RETURN 'normal';
    END IF;
    
    -- Calculate days of stock and categorize
    DECLARE
        days_of_stock DECIMAL(10,2);
    BEGIN
        days_of_stock := (p_present - p_reserved) / p_daily_sales_avg;
        
        CASE 
            WHEN days_of_stock < 14 THEN
                RETURN 'critical';
            WHEN days_of_stock < 30 THEN
                RETURN 'low';
            WHEN days_of_stock < 60 THEN
                RETURN 'normal';
            ELSE
                RETURN 'excess';
        END CASE;
    END;
END;
$$ LANGUAGE plpgsql IMMUTABLE;

-- Create function for urgency score calculation
CREATE OR REPLACE FUNCTION get_urgency_score(
    p_visibility VARCHAR(50),
    p_present INTEGER,
    p_reserved INTEGER,
    p_daily_sales_avg DECIMAL(10,2)
) RETURNS INTEGER AS $$
BEGIN
    -- Hidden products have no urgency
    IF p_visibility IS NULL OR p_visibility NOT IN ('VISIBLE', 'ACTIVE', 'продаётся') THEN
        RETURN 0;
    END IF;
    
    -- Out of stock is maximum urgency
    IF p_present IS NULL OR p_reserved IS NULL OR (p_present - p_reserved) <= 0 THEN
        RETURN 5;
    END IF;
    
    -- If no sales data, default to low urgency
    IF p_daily_sales_avg IS NULL OR p_daily_sales_avg <= 0 THEN
        RETURN 1;
    END IF;
    
    -- Calculate urgency based on days of stock
    DECLARE
        days_of_stock DECIMAL(10,2);
    BEGIN
        days_of_stock := (p_present - p_reserved) / p_daily_sales_avg;
        
        CASE 
            WHEN days_of_stock < 7 THEN
                RETURN 5;  -- Critical urgency
            WHEN days_of_stock < 14 THEN
                RETURN 4;  -- High urgency
            WHEN days_of_stock < 30 THEN
                RETURN 3;  -- Medium urgency
            WHEN days_of_stock < 60 THEN
                RETURN 2;  -- Low urgency
            ELSE
                RETURN 1;  -- Minimal urgency
        END CASE;
    END;
END;
$$ LANGUAGE plpgsql IMMUTABLE;

-- Drop existing view if it exists
DROP VIEW IF EXISTS v_detailed_inventory;

-- Create enhanced detailed inventory view
CREATE VIEW v_detailed_inventory AS
SELECT
    -- Product information
    p.product_id,
    p.name as product_name,
    p.offer_id,
    p.fbo_sku,
    p.fbs_sku,
    p.status as product_status,
    p.visibility,
    p.visibility_updated_at,
    
    -- Inventory information
    i.warehouse_name,
    i.present,
    i.reserved,
    (i.present - i.reserved) AS available_stock,
    
    -- Stock status calculation using function
    get_product_stock_status(
        p.visibility,
        i.present,
        i.reserved,
        wsm.daily_sales_avg
    ) as stock_status,
    
    -- Sales metrics
    wsm.daily_sales_avg,
    wsm.weekly_sales_avg,
    wsm.monthly_sales_avg,
    
    -- Calculated metrics
    CASE 
        WHEN wsm.daily_sales_avg > 0 AND i.present > 0 AND i.reserved >= 0 THEN 
            ROUND((i.present - i.reserved) / wsm.daily_sales_avg, 1)
        ELSE NULL 
    END as days_of_stock,
    
    -- Recommended quantity (30 days of stock)
    CASE 
        WHEN wsm.daily_sales_avg > 0 THEN 
            CEIL(wsm.daily_sales_avg * 30)
        ELSE NULL 
    END as recommended_qty,
    
    -- Urgency score using function
    get_urgency_score(
        p.visibility,
        i.present,
        i.reserved,
        wsm.daily_sales_avg
    ) as urgency_score,
    
    -- Timestamps and tracking
    i.updated_at as inventory_updated_at,
    p.updated_at as product_updated_at,
    i.etl_batch_id,
    i.last_product_etl_sync,
    
    -- Additional calculated fields
    CASE 
        WHEN wsm.daily_sales_avg > 0 AND (i.present - i.reserved) > 0 THEN
            ROUND((i.present - i.reserved) * wsm.daily_sales_avg * 30, 2)  -- Estimated monthly revenue
        ELSE 0
    END as estimated_monthly_revenue,
    
    -- Stock value (if cost data available)
    CASE 
        WHEN p.cost_price > 0 AND (i.present - i.reserved) > 0 THEN
            ROUND((i.present - i.reserved) * p.cost_price, 2)
        ELSE 0
    END as stock_value

FROM inventory i
INNER JOIN dim_products p ON i.offer_id = p.offer_id
LEFT JOIN warehouse_sales_metrics wsm ON i.offer_id = wsm.offer_id 
    AND i.warehouse_name = wsm.warehouse_name
WHERE 
    -- Only include products with visibility data
    p.visibility IS NOT NULL
    -- Default filter: only visible products with stock
    AND p.visibility IN ('VISIBLE', 'ACTIVE', 'продаётся')
    AND (i.present - i.reserved) > 0;

-- Create functional indexes for performance optimization
CREATE INDEX IF NOT EXISTS idx_inventory_stock_status_func 
ON inventory(get_product_stock_status(
    (SELECT visibility FROM dim_products WHERE dim_products.offer_id = inventory.offer_id),
    present, 
    reserved, 
    (SELECT daily_sales_avg FROM warehouse_sales_metrics wsm 
     WHERE wsm.offer_id = inventory.offer_id 
     AND wsm.warehouse_name = inventory.warehouse_name)
));

-- Create partial index for available stock calculations
CREATE INDEX IF NOT EXISTS idx_inventory_available_stock 
ON inventory((present - reserved)) 
WHERE (present - reserved) > 0;

-- Create composite index for common filtering operations
CREATE INDEX IF NOT EXISTS idx_inventory_warehouse_available 
ON inventory(warehouse_name, (present - reserved)) 
WHERE (present - reserved) > 0;

-- Create view for all products (including hidden) for administrative purposes
CREATE VIEW v_detailed_inventory_admin AS
SELECT
    -- All fields from main view
    p.product_id,
    p.name as product_name,
    p.offer_id,
    p.fbo_sku,
    p.fbs_sku,
    p.status as product_status,
    p.visibility,
    p.visibility_updated_at,
    
    i.warehouse_name,
    i.present,
    i.reserved,
    (i.present - i.reserved) AS available_stock,
    
    get_product_stock_status(
        p.visibility,
        i.present,
        i.reserved,
        wsm.daily_sales_avg
    ) as stock_status,
    
    wsm.daily_sales_avg,
    wsm.weekly_sales_avg,
    wsm.monthly_sales_avg,
    
    CASE 
        WHEN wsm.daily_sales_avg > 0 AND i.present > 0 AND i.reserved >= 0 THEN 
            ROUND((i.present - i.reserved) / wsm.daily_sales_avg, 1)
        ELSE NULL 
    END as days_of_stock,
    
    CASE 
        WHEN wsm.daily_sales_avg > 0 THEN 
            CEIL(wsm.daily_sales_avg * 30)
        ELSE NULL 
    END as recommended_qty,
    
    get_urgency_score(
        p.visibility,
        i.present,
        i.reserved,
        wsm.daily_sales_avg
    ) as urgency_score,
    
    i.updated_at as inventory_updated_at,
    p.updated_at as product_updated_at,
    i.etl_batch_id,
    i.last_product_etl_sync

FROM inventory i
INNER JOIN dim_products p ON i.offer_id = p.offer_id
LEFT JOIN warehouse_sales_metrics wsm ON i.offer_id = wsm.offer_id 
    AND i.warehouse_name = wsm.warehouse_name
WHERE p.visibility IS NOT NULL;  -- Include all products with visibility data

-- Add comments to document the views
COMMENT ON VIEW v_detailed_inventory IS 'Enhanced inventory view showing only visible products with available stock and calculated stock status';
COMMENT ON VIEW v_detailed_inventory_admin IS 'Administrative inventory view showing all products including hidden ones for management purposes';

-- Create materialized view for performance-critical scenarios
CREATE MATERIALIZED VIEW mv_detailed_inventory AS
SELECT * FROM v_detailed_inventory;

-- Create unique index on materialized view for concurrent refresh
CREATE UNIQUE INDEX idx_mv_detailed_inventory_unique 
ON mv_detailed_inventory(offer_id, warehouse_name);

-- Create function to refresh materialized view
CREATE OR REPLACE FUNCTION refresh_detailed_inventory_mv()
RETURNS void AS $$
BEGIN
    REFRESH MATERIALIZED VIEW CONCURRENTLY mv_detailed_inventory;
    
    -- Log refresh completion
    INSERT INTO etl_execution_log (
        component, 
        batch_id, 
        status, 
        records_processed, 
        completed_at,
        execution_time_seconds
    ) VALUES (
        'materialized_view_refresh',
        gen_random_uuid(),
        'completed',
        (SELECT COUNT(*) FROM mv_detailed_inventory),
        CURRENT_TIMESTAMP,
        0
    );
END;
$$ LANGUAGE plpgsql;

-- Grant appropriate permissions
GRANT SELECT ON v_detailed_inventory TO PUBLIC;
GRANT SELECT ON v_detailed_inventory_admin TO admin_role;
GRANT SELECT ON mv_detailed_inventory TO PUBLIC;

-- Log migration completion
INSERT INTO migration_log (migration_name, executed_at, description) 
VALUES (
    'ozon_etl_refactoring_002_create_enhanced_view',
    CURRENT_TIMESTAMP,
    'Created enhanced v_detailed_inventory view with stock status calculation functions and materialized view'
) ON CONFLICT (migration_name) DO UPDATE SET 
    executed_at = CURRENT_TIMESTAMP,
    description = EXCLUDED.description;

COMMIT;

-- Verify migration success
DO $$
DECLARE
    view_exists BOOLEAN;
    function_exists BOOLEAN;
    mv_exists BOOLEAN;
BEGIN
    -- Check if view exists
    SELECT EXISTS (
        SELECT 1 FROM information_schema.views 
        WHERE table_name = 'v_detailed_inventory'
    ) INTO view_exists;
    
    -- Check if function exists
    SELECT EXISTS (
        SELECT 1 FROM pg_proc 
        WHERE proname = 'get_product_stock_status'
    ) INTO function_exists;
    
    -- Check if materialized view exists
    SELECT EXISTS (
        SELECT 1 FROM pg_matviews 
        WHERE matviewname = 'mv_detailed_inventory'
    ) INTO mv_exists;
    
    IF view_exists AND function_exists AND mv_exists THEN
        RAISE NOTICE 'Migration 002 completed successfully - enhanced view, functions, and materialized view created';
    ELSE
        RAISE EXCEPTION 'Migration 002 failed - view, functions, or materialized view not created properly';
    END IF;
END $$;