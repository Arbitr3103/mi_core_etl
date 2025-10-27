-- Ozon ETL Refactoring Migration 001: Add Visibility Field
-- Description: Adds visibility field to dim_products table and creates necessary indexes
-- Author: ETL Development Team
-- Date: 2025-10-27
-- Rollback: ozon_etl_refactoring_001_rollback.sql

BEGIN;

-- Add visibility field to dim_products table
ALTER TABLE dim_products ADD COLUMN IF NOT EXISTS visibility VARCHAR(50);

-- Add metadata fields for tracking ETL updates
ALTER TABLE dim_products ADD COLUMN IF NOT EXISTS visibility_updated_at TIMESTAMP WITH TIME ZONE;

-- Add ETL tracking fields to inventory table
ALTER TABLE inventory ADD COLUMN IF NOT EXISTS etl_batch_id UUID;
ALTER TABLE inventory ADD COLUMN IF NOT EXISTS last_product_etl_sync TIMESTAMP WITH TIME ZONE;

-- Create index for visibility field (performance optimization)
CREATE INDEX IF NOT EXISTS idx_dim_products_visibility ON dim_products(visibility);

-- Create partial index for active products (most common query)
CREATE INDEX IF NOT EXISTS idx_dim_products_visibility_active 
ON dim_products(visibility) 
WHERE visibility IN ('VISIBLE', 'ACTIVE', 'продаётся');

-- Create composite index for common join operations
CREATE INDEX IF NOT EXISTS idx_dim_products_offer_visibility 
ON dim_products(offer_id, visibility);

-- Add index for visibility update tracking
CREATE INDEX IF NOT EXISTS idx_dim_products_visibility_updated 
ON dim_products(visibility_updated_at);

-- Add indexes for ETL tracking in inventory table
CREATE INDEX IF NOT EXISTS idx_inventory_etl_batch ON inventory(etl_batch_id);
CREATE INDEX IF NOT EXISTS idx_inventory_product_etl_sync ON inventory(last_product_etl_sync);

-- Update existing products with default visibility (will be updated by ProductETL)
UPDATE dim_products 
SET visibility = 'UNKNOWN', 
    visibility_updated_at = CURRENT_TIMESTAMP 
WHERE visibility IS NULL;

-- Add comment to document the new field
COMMENT ON COLUMN dim_products.visibility IS 'Product visibility status from Ozon API (VISIBLE, HIDDEN, MODERATION, DECLINED, UNKNOWN)';
COMMENT ON COLUMN dim_products.visibility_updated_at IS 'Timestamp when visibility was last updated by ProductETL';
COMMENT ON COLUMN inventory.etl_batch_id IS 'UUID identifying the ETL batch that created/updated this record';
COMMENT ON COLUMN inventory.last_product_etl_sync IS 'Timestamp of the last ProductETL run that could affect this inventory record';

-- Create function to validate visibility values
CREATE OR REPLACE FUNCTION validate_visibility_status(status VARCHAR(50)) 
RETURNS BOOLEAN AS $$
BEGIN
    RETURN status IN ('VISIBLE', 'ACTIVE', 'продаётся', 'HIDDEN', 'INACTIVE', 'ARCHIVED', 'скрыт', 'MODERATION', 'на модерации', 'DECLINED', 'отклонён', 'UNKNOWN');
END;
$$ LANGUAGE plpgsql IMMUTABLE;

-- Add check constraint for visibility values
ALTER TABLE dim_products 
ADD CONSTRAINT chk_dim_products_visibility 
CHECK (validate_visibility_status(visibility));

-- Log migration completion
INSERT INTO migration_log (migration_name, executed_at, description) 
VALUES (
    'ozon_etl_refactoring_001_add_visibility_field',
    CURRENT_TIMESTAMP,
    'Added visibility field to dim_products table with indexes and constraints'
) ON CONFLICT (migration_name) DO UPDATE SET 
    executed_at = CURRENT_TIMESTAMP,
    description = EXCLUDED.description;

COMMIT;

-- Verify migration success
DO $$
DECLARE
    visibility_column_exists BOOLEAN;
    index_count INTEGER;
BEGIN
    -- Check if visibility column exists
    SELECT EXISTS (
        SELECT 1 FROM information_schema.columns 
        WHERE table_name = 'dim_products' 
        AND column_name = 'visibility'
    ) INTO visibility_column_exists;
    
    -- Check if indexes were created
    SELECT COUNT(*) INTO index_count
    FROM pg_indexes 
    WHERE tablename = 'dim_products' 
    AND indexname LIKE '%visibility%';
    
    IF visibility_column_exists AND index_count >= 3 THEN
        RAISE NOTICE 'Migration 001 completed successfully - visibility field and indexes created';
    ELSE
        RAISE EXCEPTION 'Migration 001 failed - visibility field or indexes not created properly';
    END IF;
END $$;