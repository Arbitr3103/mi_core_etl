-- Migration: Extend inventory table for Analytics API integration
-- Purpose: Add fields for multi-source data integration, quality tracking, and ETL batch management
-- Requirements: 5.1, 5.2, 5.3
-- Task: 3.1 Создать миграцию для расширения таблицы inventory

-- Add new columns for Analytics API integration
ALTER TABLE inventory 
ADD COLUMN IF NOT EXISTS data_source VARCHAR(20) DEFAULT 'unknown'
    CHECK (data_source IN ('api', 'ui_report', 'mixed', 'manual', 'unknown')),
ADD COLUMN IF NOT EXISTS data_quality_score INTEGER DEFAULT 0
    CHECK (data_quality_score >= 0 AND data_quality_score <= 100),
ADD COLUMN IF NOT EXISTS last_analytics_sync TIMESTAMP WITH TIME ZONE,
ADD COLUMN IF NOT EXISTS normalized_warehouse_name VARCHAR(255),
ADD COLUMN IF NOT EXISTS original_warehouse_name VARCHAR(255),
ADD COLUMN IF NOT EXISTS sync_batch_id UUID;

-- Create indexes for new fields to optimize queries
CREATE INDEX IF NOT EXISTS idx_inventory_data_source ON inventory(data_source);
CREATE INDEX IF NOT EXISTS idx_inventory_quality_score ON inventory(data_quality_score);
CREATE INDEX IF NOT EXISTS idx_inventory_last_analytics_sync ON inventory(last_analytics_sync);
CREATE INDEX IF NOT EXISTS idx_inventory_normalized_warehouse ON inventory(normalized_warehouse_name);
CREATE INDEX IF NOT EXISTS idx_inventory_original_warehouse ON inventory(original_warehouse_name);
CREATE INDEX IF NOT EXISTS idx_inventory_sync_batch_id ON inventory(sync_batch_id);

-- Composite indexes for common query patterns
CREATE INDEX IF NOT EXISTS idx_inventory_source_quality ON inventory(data_source, data_quality_score);
CREATE INDEX IF NOT EXISTS idx_inventory_warehouse_source ON inventory(normalized_warehouse_name, data_source);
CREATE INDEX IF NOT EXISTS idx_inventory_batch_source ON inventory(sync_batch_id, data_source);

-- Add comments for documentation
COMMENT ON COLUMN inventory.data_source IS 'Source of inventory data: api, ui_report, mixed, manual, unknown';
COMMENT ON COLUMN inventory.data_quality_score IS 'Quality score of data from 0-100, where API=100%, UI=80%, mixed=90%';
COMMENT ON COLUMN inventory.last_analytics_sync IS 'Timestamp of last synchronization via Analytics API';
COMMENT ON COLUMN inventory.normalized_warehouse_name IS 'Standardized warehouse name for consistent reporting';
COMMENT ON COLUMN inventory.original_warehouse_name IS 'Original warehouse name as received from source';
COMMENT ON COLUMN inventory.sync_batch_id IS 'UUID to track ETL batch operations for traceability';