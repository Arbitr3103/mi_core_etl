-- Rollback Migration: Remove Analytics API integration fields from inventory table
-- Purpose: Rollback changes made by 004_extend_inventory_analytics_integration.sql
-- Task: 3.1 Создать миграцию для расширения таблицы inventory (rollback)

-- Drop indexes first (in reverse order of creation)
DROP INDEX IF EXISTS idx_inventory_batch_source;
DROP INDEX IF EXISTS idx_inventory_warehouse_source;
DROP INDEX IF EXISTS idx_inventory_source_quality;
DROP INDEX IF EXISTS idx_inventory_sync_batch_id;
DROP INDEX IF EXISTS idx_inventory_original_warehouse;
DROP INDEX IF EXISTS idx_inventory_normalized_warehouse;
DROP INDEX IF EXISTS idx_inventory_last_analytics_sync;
DROP INDEX IF EXISTS idx_inventory_quality_score;
DROP INDEX IF EXISTS idx_inventory_data_source;

-- Remove columns (in reverse order of addition)
ALTER TABLE inventory 
DROP COLUMN IF EXISTS sync_batch_id,
DROP COLUMN IF EXISTS original_warehouse_name,
DROP COLUMN IF EXISTS normalized_warehouse_name,
DROP COLUMN IF EXISTS last_analytics_sync,
DROP COLUMN IF EXISTS data_quality_score,
DROP COLUMN IF EXISTS data_source;