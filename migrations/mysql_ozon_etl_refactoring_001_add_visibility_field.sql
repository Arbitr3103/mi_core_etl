-- Ozon ETL Refactoring Migration 001: Add Visibility Field (MySQL version)
-- Description: Adds visibility field to dim_products table and creates necessary indexes
-- Author: ETL Development Team
-- Date: 2025-10-27

-- First, let's create the inventory table based on inventory_data structure
CREATE TABLE IF NOT EXISTS inventory (
    id INT AUTO_INCREMENT PRIMARY KEY,
    offer_id VARCHAR(255),
    warehouse_name VARCHAR(255),
    present INT DEFAULT 0,
    reserved INT DEFAULT 0,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    etl_batch_id VARCHAR(36),
    last_product_etl_sync TIMESTAMP NULL,
    
    INDEX idx_inventory_offer_warehouse (offer_id, warehouse_name),
    INDEX idx_inventory_etl_batch (etl_batch_id),
    INDEX idx_inventory_product_etl_sync (last_product_etl_sync),
    INDEX idx_inventory_updated_at (updated_at)
);

-- Add visibility field to dim_products table
ALTER TABLE dim_products 
ADD COLUMN visibility VARCHAR(50) DEFAULT 'UNKNOWN',
ADD COLUMN visibility_updated_at TIMESTAMP NULL;

-- Create indexes for visibility field (performance optimization)
CREATE INDEX idx_dim_products_visibility ON dim_products(visibility);

-- Create index for visibility update tracking
CREATE INDEX idx_dim_products_visibility_updated ON dim_products(visibility_updated_at);

-- Update existing products with default visibility (will be updated by ProductETL)
UPDATE dim_products 
SET visibility = 'UNKNOWN', 
    visibility_updated_at = CURRENT_TIMESTAMP 
WHERE visibility IS NULL;

-- Populate inventory table from inventory_data
INSERT INTO inventory (offer_id, warehouse_name, present, reserved, updated_at)
SELECT 
    sku as offer_id,
    warehouse_name,
    COALESCE(current_stock, 0) as present,
    COALESCE(reserved_stock, 0) as reserved,
    COALESCE(last_sync_at, CURRENT_TIMESTAMP) as updated_at
FROM inventory_data
WHERE sku IS NOT NULL
ON DUPLICATE KEY UPDATE
    present = VALUES(present),
    reserved = VALUES(reserved),
    updated_at = VALUES(updated_at);

SELECT 'Migration 001 completed successfully' as status;