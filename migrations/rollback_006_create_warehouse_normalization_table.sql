-- Rollback Migration: Remove warehouse_normalization table
-- Purpose: Rollback changes made by 006_create_warehouse_normalization_table.sql
-- Task: 3.3 Создать таблицу warehouse_normalization (rollback)

-- Drop indexes first (in reverse order of creation)
DROP INDEX IF EXISTS idx_norm_source_active;
DROP INDEX IF EXISTS idx_norm_normalized_active;
DROP INDEX IF EXISTS idx_norm_original_source;
DROP INDEX IF EXISTS idx_norm_updated_at;
DROP INDEX IF EXISTS idx_norm_confidence;
DROP INDEX IF EXISTS idx_norm_active;
DROP INDEX IF EXISTS idx_norm_source_type;
DROP INDEX IF EXISTS idx_norm_normalized_name;
DROP INDEX IF EXISTS idx_norm_original_name;

-- Drop the table
DROP TABLE IF EXISTS warehouse_normalization;