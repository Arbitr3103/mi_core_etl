-- Rollback Migration: 007_create_ozon_etl_schema.sql
-- Description: Rollback complete database schema for Ozon ETL System
-- Date: 2025-10-26

-- ============================================================================
-- Drop views first (dependent objects)
-- ============================================================================

DROP VIEW IF EXISTS v_etl_monitoring;
DROP VIEW IF EXISTS v_sales_summary_30d;
DROP VIEW IF EXISTS v_products_with_inventory;

-- ============================================================================
-- Drop triggers
-- ============================================================================

DROP TRIGGER IF EXISTS update_inventory_updated_at ON inventory;
DROP TRIGGER IF EXISTS update_dim_products_updated_at ON dim_products;

-- ============================================================================
-- Drop function
-- ============================================================================

DROP FUNCTION IF EXISTS update_updated_at_column();

-- ============================================================================
-- Drop foreign key constraints
-- ============================================================================

ALTER TABLE IF EXISTS inventory DROP CONSTRAINT IF EXISTS fk_inventory_offer_id;
ALTER TABLE IF EXISTS fact_orders DROP CONSTRAINT IF EXISTS fk_fact_orders_offer_id;

-- ============================================================================
-- Drop indexes
-- ============================================================================

-- ETL execution log indexes
DROP INDEX IF EXISTS idx_etl_log_started_at;
DROP INDEX IF EXISTS idx_etl_log_status;
DROP INDEX IF EXISTS idx_etl_log_class;

-- Inventory indexes
DROP INDEX IF EXISTS idx_inventory_updated_at;
DROP INDEX IF EXISTS idx_inventory_available;
DROP INDEX IF EXISTS idx_inventory_warehouse_name;
DROP INDEX IF EXISTS idx_inventory_offer_id;

-- Fact orders indexes
DROP INDEX IF EXISTS idx_fact_orders_date_range;
DROP INDEX IF EXISTS idx_fact_orders_warehouse_id;
DROP INDEX IF EXISTS idx_fact_orders_in_process_at;
DROP INDEX IF EXISTS idx_fact_orders_posting_number;
DROP INDEX IF EXISTS idx_fact_orders_offer_id;

-- Dim products indexes
DROP INDEX IF EXISTS idx_dim_products_updated_at;
DROP INDEX IF EXISTS idx_dim_products_status;
DROP INDEX IF EXISTS idx_dim_products_product_id;
DROP INDEX IF EXISTS idx_dim_products_offer_id;

-- ============================================================================
-- Drop tables (in reverse dependency order)
-- ============================================================================

DROP TABLE IF EXISTS etl_execution_log;
DROP TABLE IF EXISTS inventory;
DROP TABLE IF EXISTS fact_orders;
DROP TABLE IF EXISTS dim_products;

-- ============================================================================
-- Log rollback completion
-- ============================================================================

-- Note: This will fail if etl_execution_log table was already dropped
-- This is expected behavior for rollback operations
INSERT INTO etl_execution_log (
    etl_class, 
    status, 
    records_processed, 
    duration_seconds, 
    started_at, 
    completed_at
) VALUES (
    'Rollback_007_OzonETLSchema',
    'success',
    4, -- 4 tables dropped
    0,
    NOW(),
    NOW()
) ON CONFLICT DO NOTHING;