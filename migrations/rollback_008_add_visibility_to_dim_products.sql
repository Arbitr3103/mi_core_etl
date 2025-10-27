-- Rollback Migration: rollback_008_add_visibility_to_dim_products.sql
-- Description: Rollback visibility field addition from dim_products table
-- Requirements: 2.1, 3.1
-- Date: 2025-10-27
-- Task: 1.1 Create database migration script (rollback)

-- ============================================================================
-- ROLLBACK: Remove visibility field from dim_products table
-- ============================================================================

-- Remove index first
DROP INDEX IF EXISTS idx_dim_products_visibility;

-- Remove visibility column from dim_products table
ALTER TABLE dim_products 
DROP COLUMN IF EXISTS visibility;

-- ============================================================================
-- Log rollback execution
-- ============================================================================

INSERT INTO etl_execution_log (
    etl_class, 
    status, 
    records_processed, 
    duration_seconds, 
    started_at, 
    completed_at
) VALUES (
    'Rollback_008_AddVisibilityToDimProducts',
    'success',
    1, -- 1 column removed
    0,
    NOW(),
    NOW()
);