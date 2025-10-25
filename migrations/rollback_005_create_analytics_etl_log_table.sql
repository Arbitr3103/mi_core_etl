-- Rollback Migration: Remove analytics_etl_log table
-- Purpose: Rollback changes made by 005_create_analytics_etl_log_table.sql
-- Task: 3.2 Создать таблицу analytics_etl_log (rollback)

-- Drop indexes first (in reverse order of creation)
DROP INDEX IF EXISTS idx_analytics_etl_source_status;
DROP INDEX IF EXISTS idx_analytics_etl_type_status;
DROP INDEX IF EXISTS idx_analytics_etl_status_started;
DROP INDEX IF EXISTS idx_analytics_etl_completed_at;
DROP INDEX IF EXISTS idx_analytics_etl_source;
DROP INDEX IF EXISTS idx_analytics_etl_type;
DROP INDEX IF EXISTS idx_analytics_etl_status;
DROP INDEX IF EXISTS idx_analytics_etl_started_at;
DROP INDEX IF EXISTS idx_analytics_etl_batch_id;

-- Drop the table
DROP TABLE IF EXISTS analytics_etl_log;