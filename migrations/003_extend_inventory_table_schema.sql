-- Migration: Extend inventory table with report-specific fields
-- Purpose: Add fields to track report source and update timestamps
-- Requirements: 1.3, 3.2

-- Add new columns to inventory table
ALTER TABLE inventory
ADD COLUMN report_source ENUM('API_DIRECT', 'API_REPORTS') DEFAULT 'API_DIRECT' COMMENT 'Source of inventory data',
ADD COLUMN last_report_update TIMESTAMP NULL COMMENT 'Timestamp of last report-based update',
ADD COLUMN report_code VARCHAR(255) NULL COMMENT 'Reference to the report that updated this record';

-- Create indexes for new fields to optimize queries
ALTER TABLE inventory
ADD INDEX idx_report_source (report_source),
ADD INDEX idx_last_report_update (last_report_update),
ADD INDEX idx_report_code (report_code),
ADD INDEX idx_report_source_update (report_source, last_report_update);

-- Add foreign key constraint to link with reports (optional, allows NULL)
-- Note: This is a soft reference since not all inventory records will have report_code
ALTER TABLE inventory
ADD CONSTRAINT fk_inventory_report_code 
FOREIGN KEY (report_code) REFERENCES ozon_stock_reports(report_code) ON DELETE SET NULL;