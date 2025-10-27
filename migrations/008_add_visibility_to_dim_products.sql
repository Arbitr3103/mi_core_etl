-- Migration: 008_add_visibility_to_dim_products.sql
-- Description: Add visibility field to dim_products table for Ozon ETL refactoring
-- Requirements: 2.1, 3.1
-- Date: 2025-10-27
-- Task: 1.1 Create database migration script

-- ============================================================================
-- MIGRATION: Add visibility field to dim_products table
-- ============================================================================

-- Add visibility column to dim_products table
ALTER TABLE dim_products 
ADD COLUMN IF NOT EXISTS visibility VARCHAR(50);

-- Create index for performance optimization on visibility field
CREATE INDEX IF NOT EXISTS idx_dim_products_visibility ON dim_products(visibility);

-- Add comment for documentation
COMMENT ON COLUMN dim_products.visibility IS 'Product visibility status from Ozon (VISIBLE, HIDDEN, MODERATION, etc.)';

-- ============================================================================
-- Log migration execution
-- ============================================================================

INSERT INTO etl_execution_log (
    etl_class, 
    status, 
    records_processed, 
    duration_seconds, 
    started_at, 
    completed_at
) VALUES (
    'Migration_008_AddVisibilityToDimProducts',
    'success',
    1, -- 1 column added
    0,
    NOW(),
    NOW()
);