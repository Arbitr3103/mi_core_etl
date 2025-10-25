-- Migration: Create analytics_etl_log table for Analytics API ETL process tracking
-- Purpose: Create table for logging Analytics API ETL processes with batch tracking and monitoring
-- Requirements: 4.1, 4.2, 4.3
-- Task: 3.2 Создать таблицу analytics_etl_log

-- Create analytics_etl_log table
CREATE TABLE IF NOT EXISTS analytics_etl_log (
    id SERIAL PRIMARY KEY,
    
    -- ETL execution metadata
    batch_id UUID NOT NULL,
    etl_type VARCHAR(50) NOT NULL DEFAULT 'incremental_sync',
    started_at TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP,
    completed_at TIMESTAMP WITH TIME ZONE,
    status VARCHAR(20) NOT NULL DEFAULT 'running',
    
    -- Processing statistics (as required by task)
    records_processed INTEGER DEFAULT 0,
    records_extracted INTEGER DEFAULT 0,
    records_validated INTEGER DEFAULT 0,
    records_normalized INTEGER DEFAULT 0,
    records_inserted INTEGER DEFAULT 0,
    records_updated INTEGER DEFAULT 0,
    records_failed INTEGER DEFAULT 0,
    
    -- Execution time tracking (as required by task)
    execution_time_ms INTEGER,
    memory_used_mb DECIMAL(10,2),
    
    -- API statistics for Analytics API monitoring
    api_requests_made INTEGER DEFAULT 0,
    api_requests_failed INTEGER DEFAULT 0,
    total_api_time_ms INTEGER DEFAULT 0,
    
    -- Error information
    error_message TEXT,
    error_details JSONB,
    
    -- Source tracking
    data_source VARCHAR(50) DEFAULT 'analytics_api',
    warehouse_count INTEGER DEFAULT 0,
    
    -- Quality metrics
    data_quality_issues INTEGER DEFAULT 0,
    validation_warnings INTEGER DEFAULT 0,
    
    -- Constraints for data integrity
    CONSTRAINT etl_log_valid_status CHECK (
        status IN ('running', 'completed', 'failed', 'cancelled', 'partial_success')
    ),
    CONSTRAINT etl_log_valid_type CHECK (
        etl_type IN ('full_sync', 'incremental_sync', 'manual_sync', 'validation_only')
    ),
    CONSTRAINT etl_log_valid_source CHECK (
        data_source IN ('analytics_api', 'ui_report', 'mixed', 'manual')
    ),
    CONSTRAINT etl_log_positive_records CHECK (
        records_processed >= 0 AND records_extracted >= 0 AND 
        records_validated >= 0 AND records_normalized >= 0 AND
        records_inserted >= 0 AND records_updated >= 0 AND records_failed >= 0
    ),
    CONSTRAINT etl_log_positive_metrics CHECK (
        api_requests_made >= 0 AND api_requests_failed >= 0 AND
        total_api_time_ms >= 0 AND warehouse_count >= 0 AND
        data_quality_issues >= 0 AND validation_warnings >= 0
    )
);

-- Create indexes for ETL monitoring and performance (as required by task)
CREATE INDEX IF NOT EXISTS idx_analytics_etl_batch_id ON analytics_etl_log(batch_id);
CREATE INDEX IF NOT EXISTS idx_analytics_etl_started_at ON analytics_etl_log(started_at);
CREATE INDEX IF NOT EXISTS idx_analytics_etl_status ON analytics_etl_log(status);
CREATE INDEX IF NOT EXISTS idx_analytics_etl_type ON analytics_etl_log(etl_type);
CREATE INDEX IF NOT EXISTS idx_analytics_etl_source ON analytics_etl_log(data_source);
CREATE INDEX IF NOT EXISTS idx_analytics_etl_completed_at ON analytics_etl_log(completed_at);

-- Composite indexes for common monitoring queries
CREATE INDEX IF NOT EXISTS idx_analytics_etl_status_started ON analytics_etl_log(status, started_at);
CREATE INDEX IF NOT EXISTS idx_analytics_etl_type_status ON analytics_etl_log(etl_type, status);
CREATE INDEX IF NOT EXISTS idx_analytics_etl_source_status ON analytics_etl_log(data_source, status);

-- Add comments for documentation
COMMENT ON TABLE analytics_etl_log IS 'Log table for tracking Analytics API ETL processes with batch monitoring and performance metrics';
COMMENT ON COLUMN analytics_etl_log.batch_id IS 'UUID to track ETL batch operations for traceability';
COMMENT ON COLUMN analytics_etl_log.etl_type IS 'Type of ETL operation: full_sync, incremental_sync, manual_sync, validation_only';
COMMENT ON COLUMN analytics_etl_log.status IS 'Current status: running, completed, failed, cancelled, partial_success';
COMMENT ON COLUMN analytics_etl_log.records_processed IS 'Total number of records processed in this ETL batch';
COMMENT ON COLUMN analytics_etl_log.execution_time_ms IS 'Total execution time in milliseconds';
COMMENT ON COLUMN analytics_etl_log.api_requests_made IS 'Number of API requests made to Analytics API';
COMMENT ON COLUMN analytics_etl_log.data_source IS 'Source of data: analytics_api, ui_report, mixed, manual';
COMMENT ON COLUMN analytics_etl_log.data_quality_issues IS 'Number of data quality issues detected during processing';
COMMENT ON COLUMN analytics_etl_log.error_details IS 'JSON object containing detailed error information and stack traces';