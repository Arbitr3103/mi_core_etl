-- ===================================================================
-- ROLLBACK: Warehouse Dashboard Schema
-- Date: October 22, 2025
-- Description: Rollback warehouse dashboard schema changes
-- ===================================================================

-- Connect to the database
\c mi_core_db;

-- ===================================================================
-- PART 1: Drop helper functions
-- ===================================================================

DROP FUNCTION IF EXISTS refresh_warehouse_metrics_for_product(INTEGER, VARCHAR(255), marketplace_source);
DROP FUNCTION IF EXISTS determine_liquidity_status(DECIMAL(10, 2));
DROP FUNCTION IF EXISTS calculate_days_of_stock(INTEGER, DECIMAL(10, 2));
DROP FUNCTION IF EXISTS calculate_daily_sales_avg(INTEGER, VARCHAR(255), INTEGER);

-- ===================================================================
-- PART 2: Drop warehouse_sales_metrics table
-- ===================================================================

DROP TABLE IF EXISTS warehouse_sales_metrics CASCADE;

-- ===================================================================
-- PART 3: Remove columns from inventory table
-- ===================================================================

ALTER TABLE inventory 
    DROP COLUMN IF EXISTS preparing_for_sale,
    DROP COLUMN IF EXISTS in_supply_requests,
    DROP COLUMN IF EXISTS in_transit,
    DROP COLUMN IF EXISTS in_inspection,
    DROP COLUMN IF EXISTS returning_from_customers,
    DROP COLUMN IF EXISTS expiring_soon,
    DROP COLUMN IF EXISTS defective,
    DROP COLUMN IF EXISTS excess_from_supply,
    DROP COLUMN IF EXISTS awaiting_upd,
    DROP COLUMN IF EXISTS preparing_for_removal,
    DROP COLUMN IF EXISTS cluster;

-- ===================================================================
-- Rollback completed successfully
-- ===================================================================

SELECT 'Warehouse dashboard schema rollback completed successfully' AS status;
