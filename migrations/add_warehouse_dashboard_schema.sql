-- ===================================================================
-- MIGRATION: Warehouse Dashboard Schema
-- Date: October 22, 2025
-- Description: Extends inventory table with Ozon metrics and creates
--              warehouse_sales_metrics table for caching calculated metrics
-- Requirements: 2, 3, 4, 5, 6, 7
-- ===================================================================

-- Connect to the database
\c mi_core_db;

-- ===================================================================
-- PART 1: Extend inventory table with Ozon metrics and cluster field
-- ===================================================================

-- Add Ozon-specific metrics columns to inventory table
ALTER TABLE inventory 
    ADD COLUMN IF NOT EXISTS preparing_for_sale INTEGER DEFAULT 0,
    ADD COLUMN IF NOT EXISTS in_supply_requests INTEGER DEFAULT 0,
    ADD COLUMN IF NOT EXISTS in_transit INTEGER DEFAULT 0,
    ADD COLUMN IF NOT EXISTS in_inspection INTEGER DEFAULT 0,
    ADD COLUMN IF NOT EXISTS returning_from_customers INTEGER DEFAULT 0,
    ADD COLUMN IF NOT EXISTS expiring_soon INTEGER DEFAULT 0,
    ADD COLUMN IF NOT EXISTS defective INTEGER DEFAULT 0,
    ADD COLUMN IF NOT EXISTS excess_from_supply INTEGER DEFAULT 0,
    ADD COLUMN IF NOT EXISTS awaiting_upd INTEGER DEFAULT 0,
    ADD COLUMN IF NOT EXISTS preparing_for_removal INTEGER DEFAULT 0,
    ADD COLUMN IF NOT EXISTS cluster VARCHAR(100);

-- Add comments to new columns for documentation
COMMENT ON COLUMN inventory.preparing_for_sale IS 'Готовим к продаже (Ozon metric)';
COMMENT ON COLUMN inventory.in_supply_requests IS 'В заявках на поставку (Ozon metric)';
COMMENT ON COLUMN inventory.in_transit IS 'В поставках в пути (Ozon metric)';
COMMENT ON COLUMN inventory.in_inspection IS 'Проходят проверку (Ozon metric)';
COMMENT ON COLUMN inventory.returning_from_customers IS 'Возвращаются от покупателей (Ozon metric)';
COMMENT ON COLUMN inventory.expiring_soon IS 'Истекает срок годности (Ozon metric)';
COMMENT ON COLUMN inventory.defective IS 'Брак, доступный к вывозу (Ozon metric)';
COMMENT ON COLUMN inventory.excess_from_supply IS 'Излишки от поставки (Ozon metric)';
COMMENT ON COLUMN inventory.awaiting_upd IS 'Ожидают приёмки (Ozon metric)';
COMMENT ON COLUMN inventory.preparing_for_removal IS 'Готовятся к вывозу (Ozon metric)';
COMMENT ON COLUMN inventory.cluster IS 'Warehouse cluster grouping (e.g., Юг, Урал)';

-- Create index on cluster for filtering
CREATE INDEX IF NOT EXISTS idx_inventory_cluster ON inventory(cluster);

-- ===================================================================
-- PART 2: Create warehouse_sales_metrics table
-- ===================================================================

-- Create table for caching calculated sales and liquidity metrics
CREATE TABLE IF NOT EXISTS warehouse_sales_metrics (
    id SERIAL PRIMARY KEY,
    product_id INTEGER NOT NULL REFERENCES dim_products(id) ON DELETE CASCADE,
    warehouse_name VARCHAR(255) NOT NULL,
    source marketplace_source NOT NULL,

    -- Sales metrics
    daily_sales_avg DECIMAL(10, 2) DEFAULT 0,
    sales_last_28_days INTEGER DEFAULT 0,
    days_with_stock INTEGER DEFAULT 0,
    days_without_sales INTEGER DEFAULT 0,

    -- Liquidity metrics
    days_of_stock DECIMAL(10, 2),
    liquidity_status VARCHAR(50),

    -- Replenishment metrics
    target_stock INTEGER DEFAULT 0,
    replenishment_need INTEGER DEFAULT 0,

    -- Metadata
    calculated_at TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP,

    UNIQUE(product_id, warehouse_name, source)
);

-- Add comments to table and columns
COMMENT ON TABLE warehouse_sales_metrics IS 'Cached calculated metrics for warehouse dashboard (updated hourly)';
COMMENT ON COLUMN warehouse_sales_metrics.daily_sales_avg IS 'Average daily sales over last 28 days';
COMMENT ON COLUMN warehouse_sales_metrics.sales_last_28_days IS 'Total sales in last 28 days';
COMMENT ON COLUMN warehouse_sales_metrics.days_with_stock IS 'Number of days product was in stock (last 28 days)';
COMMENT ON COLUMN warehouse_sales_metrics.days_without_sales IS 'Consecutive days without sales';
COMMENT ON COLUMN warehouse_sales_metrics.days_of_stock IS 'Days of stock remaining at current sales rate';
COMMENT ON COLUMN warehouse_sales_metrics.liquidity_status IS 'Liquidity status: critical, low, normal, excess';
COMMENT ON COLUMN warehouse_sales_metrics.target_stock IS 'Target stock for 30 days supply';
COMMENT ON COLUMN warehouse_sales_metrics.replenishment_need IS 'Units needed to reach target stock';

-- Create indexes for performance
CREATE INDEX IF NOT EXISTS idx_warehouse_metrics_product ON warehouse_sales_metrics(product_id);
CREATE INDEX IF NOT EXISTS idx_warehouse_metrics_warehouse ON warehouse_sales_metrics(warehouse_name);
CREATE INDEX IF NOT EXISTS idx_warehouse_metrics_liquidity ON warehouse_sales_metrics(liquidity_status);
CREATE INDEX IF NOT EXISTS idx_warehouse_metrics_calculated ON warehouse_sales_metrics(calculated_at);
CREATE INDEX IF NOT EXISTS idx_warehouse_metrics_source ON warehouse_sales_metrics(source);
CREATE INDEX IF NOT EXISTS idx_warehouse_metrics_replenishment ON warehouse_sales_metrics(replenishment_need) WHERE replenishment_need > 0;

-- ===================================================================
-- PART 3: SQL Functions for metric calculations
-- ===================================================================

-- Function: Calculate average daily sales over last 28 days
CREATE OR REPLACE FUNCTION calculate_daily_sales_avg(
    p_product_id INTEGER,
    p_warehouse_name VARCHAR(255),
    p_days INTEGER DEFAULT 28
) RETURNS DECIMAL(10, 2) AS $$
DECLARE
    v_total_sales INTEGER;
    v_days_with_stock INTEGER;
    v_daily_avg DECIMAL(10, 2);
BEGIN
    -- Get total sales (negative quantities in stock_movements represent sales)
    SELECT COALESCE(SUM(ABS(quantity)), 0)
    INTO v_total_sales
    FROM stock_movements
    WHERE product_id = p_product_id
        AND warehouse_name = p_warehouse_name
        AND movement_date >= CURRENT_DATE - p_days
        AND quantity < 0
        AND movement_type IN ('sale', 'order');

    -- Count days when product had stock available
    SELECT COUNT(DISTINCT DATE(sm.movement_date))
    INTO v_days_with_stock
    FROM stock_movements sm
    WHERE sm.product_id = p_product_id
        AND sm.warehouse_name = p_warehouse_name
        AND sm.movement_date >= CURRENT_DATE - p_days
        AND EXISTS (
            SELECT 1 FROM inventory i
            WHERE i.product_id = sm.product_id
                AND i.warehouse_name = sm.warehouse_name
                AND i.quantity_present > 0
        );

    -- Calculate average (avoid division by zero)
    IF v_days_with_stock > 0 THEN
        v_daily_avg := v_total_sales::DECIMAL / v_days_with_stock;
    ELSE
        v_daily_avg := 0;
    END IF;

    RETURN ROUND(v_daily_avg, 2);
END;
$$ LANGUAGE plpgsql STABLE;

COMMENT ON FUNCTION calculate_daily_sales_avg IS 'Calculate average daily sales over specified period (default 28 days)';

-- Function: Calculate days of stock remaining
CREATE OR REPLACE FUNCTION calculate_days_of_stock(
    p_available INTEGER,
    p_daily_sales_avg DECIMAL(10, 2)
) RETURNS DECIMAL(10, 2) AS $$
BEGIN
    -- If no sales, return NULL (infinite stock)
    IF p_daily_sales_avg <= 0 THEN
        RETURN NULL;
    END IF;

    -- Calculate days of stock
    RETURN ROUND(p_available::DECIMAL / p_daily_sales_avg, 2);
END;
$$ LANGUAGE plpgsql IMMUTABLE;

COMMENT ON FUNCTION calculate_days_of_stock IS 'Calculate how many days current stock will last at current sales rate';

-- Function: Determine liquidity status based on days of stock
CREATE OR REPLACE FUNCTION determine_liquidity_status(
    p_days_of_stock DECIMAL(10, 2)
) RETURNS VARCHAR(50) AS $$
BEGIN
    -- NULL means no sales (infinite stock)
    IF p_days_of_stock IS NULL THEN
        RETURN 'excess';
    END IF;

    -- Categorize based on days of stock
    IF p_days_of_stock < 7 THEN
        RETURN 'critical';
    ELSIF p_days_of_stock < 15 THEN
        RETURN 'low';
    ELSIF p_days_of_stock <= 45 THEN
        RETURN 'normal';
    ELSE
        RETURN 'excess';
    END IF;
END;
$$ LANGUAGE plpgsql IMMUTABLE;

COMMENT ON FUNCTION determine_liquidity_status IS 'Determine liquidity status: critical (<7d), low (7-14d), normal (15-45d), excess (>45d)';

-- ===================================================================
-- PART 4: Helper function to refresh metrics for a product
-- ===================================================================

CREATE OR REPLACE FUNCTION refresh_warehouse_metrics_for_product(
    p_product_id INTEGER,
    p_warehouse_name VARCHAR(255),
    p_source marketplace_source
) RETURNS VOID AS $$
DECLARE
    v_daily_sales_avg DECIMAL(10, 2);
    v_sales_last_28_days INTEGER;
    v_days_with_stock INTEGER;
    v_days_without_sales INTEGER;
    v_available INTEGER;
    v_in_transit INTEGER;
    v_in_supply_requests INTEGER;
    v_days_of_stock DECIMAL(10, 2);
    v_liquidity_status VARCHAR(50);
    v_target_stock INTEGER;
    v_replenishment_need INTEGER;
BEGIN
    -- Get current inventory data
    SELECT 
        quantity_present,
        COALESCE(in_transit, 0),
        COALESCE(in_supply_requests, 0)
    INTO v_available, v_in_transit, v_in_supply_requests
    FROM inventory
    WHERE product_id = p_product_id
        AND warehouse_name = p_warehouse_name
        AND source = p_source;

    -- If no inventory record, skip
    IF NOT FOUND THEN
        RETURN;
    END IF;

    -- Calculate daily sales average
    v_daily_sales_avg := calculate_daily_sales_avg(p_product_id, p_warehouse_name, 28);

    -- Get total sales last 28 days
    SELECT COALESCE(SUM(ABS(quantity)), 0)
    INTO v_sales_last_28_days
    FROM stock_movements
    WHERE product_id = p_product_id
        AND warehouse_name = p_warehouse_name
        AND movement_date >= CURRENT_DATE - 28
        AND quantity < 0
        AND movement_type IN ('sale', 'order');

    -- Count days with stock (simplified - count days with movements)
    SELECT COUNT(DISTINCT DATE(movement_date))
    INTO v_days_with_stock
    FROM stock_movements
    WHERE product_id = p_product_id
        AND warehouse_name = p_warehouse_name
        AND movement_date >= CURRENT_DATE - 28;

    -- Calculate days without sales (consecutive days from today)
    SELECT COALESCE(CURRENT_DATE - MAX(DATE(movement_date)), 0)
    INTO v_days_without_sales
    FROM stock_movements
    WHERE product_id = p_product_id
        AND warehouse_name = p_warehouse_name
        AND quantity < 0
        AND movement_type IN ('sale', 'order');

    -- Calculate days of stock
    v_days_of_stock := calculate_days_of_stock(v_available, v_daily_sales_avg);

    -- Determine liquidity status
    v_liquidity_status := determine_liquidity_status(v_days_of_stock);

    -- Calculate target stock (30 days supply)
    v_target_stock := CEIL(v_daily_sales_avg * 30);

    -- Calculate replenishment need
    v_replenishment_need := GREATEST(0, v_target_stock - v_available - v_in_transit - v_in_supply_requests);

    -- Insert or update metrics
    INSERT INTO warehouse_sales_metrics (
        product_id,
        warehouse_name,
        source,
        daily_sales_avg,
        sales_last_28_days,
        days_with_stock,
        days_without_sales,
        days_of_stock,
        liquidity_status,
        target_stock,
        replenishment_need,
        calculated_at
    ) VALUES (
        p_product_id,
        p_warehouse_name,
        p_source,
        v_daily_sales_avg,
        v_sales_last_28_days,
        v_days_with_stock,
        v_days_without_sales,
        v_days_of_stock,
        v_liquidity_status,
        v_target_stock,
        v_replenishment_need,
        CURRENT_TIMESTAMP
    )
    ON CONFLICT (product_id, warehouse_name, source)
    DO UPDATE SET
        daily_sales_avg = EXCLUDED.daily_sales_avg,
        sales_last_28_days = EXCLUDED.sales_last_28_days,
        days_with_stock = EXCLUDED.days_with_stock,
        days_without_sales = EXCLUDED.days_without_sales,
        days_of_stock = EXCLUDED.days_of_stock,
        liquidity_status = EXCLUDED.liquidity_status,
        target_stock = EXCLUDED.target_stock,
        replenishment_need = EXCLUDED.replenishment_need,
        calculated_at = CURRENT_TIMESTAMP;
END;
$$ LANGUAGE plpgsql;

COMMENT ON FUNCTION refresh_warehouse_metrics_for_product IS 'Refresh all calculated metrics for a specific product-warehouse combination';

-- ===================================================================
-- PART 5: Validation queries
-- ===================================================================

-- Verify inventory table extensions
SELECT 
    column_name, 
    data_type, 
    is_nullable,
    column_default
FROM information_schema.columns
WHERE table_schema = 'public'
    AND table_name = 'inventory'
    AND column_name IN (
        'preparing_for_sale', 'in_supply_requests', 'in_transit',
        'in_inspection', 'returning_from_customers', 'expiring_soon',
        'defective', 'excess_from_supply', 'awaiting_upd',
        'preparing_for_removal', 'cluster'
    )
ORDER BY ordinal_position;

-- Verify warehouse_sales_metrics table creation
SELECT 
    table_name,
    table_type
FROM information_schema.tables
WHERE table_schema = 'public'
    AND table_name = 'warehouse_sales_metrics';

-- Verify indexes
SELECT 
    indexname,
    indexdef
FROM pg_indexes
WHERE schemaname = 'public'
    AND (tablename = 'inventory' OR tablename = 'warehouse_sales_metrics')
    AND indexname LIKE '%warehouse%'
ORDER BY tablename, indexname;

-- Verify functions
SELECT 
    routine_name,
    routine_type,
    data_type as return_type
FROM information_schema.routines
WHERE routine_schema = 'public'
    AND routine_name IN (
        'calculate_daily_sales_avg',
        'calculate_days_of_stock',
        'determine_liquidity_status',
        'refresh_warehouse_metrics_for_product'
    )
ORDER BY routine_name;

-- ===================================================================
-- Migration completed successfully
-- ===================================================================
