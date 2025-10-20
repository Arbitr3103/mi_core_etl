-- Rollback Migration: Remove Regional Analytics Schema
-- Description: Removes database structure for regional sales analytics system
-- Date: 2025-10-20
-- Requirements: 5.3

-- Drop views first (they depend on tables)
DROP VIEW IF EXISTS v_marketplace_comparison;
DROP VIEW IF EXISTS v_regional_sales_summary;

-- Drop tables in reverse order of dependencies
DROP TABLE IF EXISTS regional_analytics_cache;
DROP TABLE IF EXISTS ozon_regional_sales;
DROP TABLE IF EXISTS regions;

-- Note: This rollback will permanently delete all regional analytics data
-- Make sure to backup data before running this rollback migration