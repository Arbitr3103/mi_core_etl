-- Ozon ETL Refactoring Migration 003: Create ETL Tracking System
-- Description: Creates tables and functions for ETL execution tracking and monitoring
-- Author: ETL Development Team
-- Date: 2025-10-27
-- Rollback: ozon_etl_refactoring_003_rollback.sql

BEGIN;

-- Create ETL execution log table for detailed tracking
CREATE TABLE IF NOT EXISTS etl_execution_log (
    id SERIAL PRIMARY KEY,
    
    -- ETL execution metadata
    component VARCHAR(50) NOT NULL,
    batch_id UUID NOT NULL DEFAULT gen_random_uuid(),
    started_at TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP,
    completed_at TIMESTAMP WITH TIME ZONE,
    status VARCHAR(20) NOT NULL DEFAULT 'running',
    
    -- Processing statistics
    records_extracted INTEGER DEFAULT 0,
    records_validated INTEGER DEFAULT 0,
    records_normalized INTEGER DEFAULT 0,
    records_inserted INTEGER DEFAULT 0,
    records_updated INTEGER DEFAULT 0,
    records_failed INTEGER DEFAULT 0,
    
    -- API statistics
    api_requests_made INTEGER DEFAULT 0,
    api_requests_failed INTEGER DEFAULT 0,
    total_api_time_ms INTEGER DEFAULT 0,
    
    -- Error information
    error_message TEXT,
    error_details JSONB,
    
    -- Performance metrics
    execution_time_seconds INTEGER,
    memory_used_mb DECIMAL(10,2),
    
    -- Additional metadata
    configuration JSONB,
    created_by VARCHAR(100) DEFAULT 'system',
    
    CONSTRAINT etl_log_valid_status CHECK (
        status IN ('running', 'completed', 'failed', 'cancelled', 'warning')
    ),
    CONSTRAINT etl_log_valid_component CHECK (
        component IN ('ProductETL', 'InventoryETL', 'materialized_view_refresh', 'data_quality_check', 'health_check')
    ),
    CONSTRAINT etl_log_valid_execution_time CHECK (
        execution_time_seconds IS NULL OR execution_time_seconds >= 0
    )
);

-- Create indexes for ETL log table
CREATE INDEX IF NOT EXISTS idx_etl_log_component ON etl_execution_log(component);
CREATE INDEX IF NOT EXISTS idx_etl_log_batch_id ON etl_execution_log(batch_id);
CREATE INDEX IF NOT EXISTS idx_etl_log_started_at ON etl_execution_log(started_at);
CREATE INDEX IF NOT EXISTS idx_etl_log_status ON etl_execution_log(status);
CREATE INDEX IF NOT EXISTS idx_etl_log_component_status ON etl_execution_log(component, status);
CREATE INDEX IF NOT EXISTS idx_etl_log_started_at_desc ON etl_execution_log(started_at DESC);

-- Create ETL workflow executions table for high-level tracking
CREATE TABLE IF NOT EXISTS etl_workflow_executions (
    id SERIAL PRIMARY KEY,
    workflow_id UUID NOT NULL DEFAULT gen_random_uuid(),
    workflow_type VARCHAR(50) NOT NULL,
    started_at TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP,
    completed_at TIMESTAMP WITH TIME ZONE,
    status VARCHAR(20) NOT NULL DEFAULT 'running',
    
    -- Component tracking
    product_etl_batch_id UUID,
    inventory_etl_batch_id UUID,
    
    -- Workflow statistics
    total_products_processed INTEGER DEFAULT 0,
    total_inventory_records_processed INTEGER DEFAULT 0,
    data_quality_score DECIMAL(5,2),
    
    -- Error tracking
    error_message TEXT,
    warnings_count INTEGER DEFAULT 0,
    
    -- Performance metrics
    total_execution_time_seconds INTEGER,
    
    CONSTRAINT workflow_valid_status CHECK (
        status IN ('running', 'completed', 'failed', 'cancelled', 'partial_success')
    ),
    CONSTRAINT workflow_valid_type CHECK (
        workflow_type IN ('scheduled_full', 'scheduled_incremental', 'manual_full', 'manual_incremental', 'emergency_sync')
    )
);

-- Create indexes for workflow executions
CREATE INDEX IF NOT EXISTS idx_workflow_executions_workflow_id ON etl_workflow_executions(workflow_id);
CREATE INDEX IF NOT EXISTS idx_workflow_executions_type ON etl_workflow_executions(workflow_type);
CREATE INDEX IF NOT EXISTS idx_workflow_executions_started_at ON etl_workflow_executions(started_at);
CREATE INDEX IF NOT EXISTS idx_workflow_executions_status ON etl_workflow_executions(status);

-- Create data quality metrics table
CREATE TABLE IF NOT EXISTS data_quality_metrics (
    id SERIAL PRIMARY KEY,
    measured_at TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP,
    
    -- Overall quality score
    overall_score DECIMAL(5,2) NOT NULL,
    
    -- Visibility completeness metrics
    total_products INTEGER NOT NULL,
    products_with_visibility INTEGER NOT NULL,
    products_without_visibility INTEGER NOT NULL,
    visibility_completeness_score DECIMAL(5,2) NOT NULL,
    
    -- Inventory consistency metrics
    total_inventory_records INTEGER NOT NULL,
    orphaned_inventory_records INTEGER NOT NULL,
    negative_stock_records INTEGER NOT NULL,
    inventory_consistency_score DECIMAL(5,2) NOT NULL,
    
    -- Data freshness metrics
    hours_since_product_etl DECIMAL(10,2),
    hours_since_inventory_etl DECIMAL(10,2),
    data_freshness_score DECIMAL(5,2) NOT NULL,
    
    -- Performance metrics
    avg_api_response_time_ms INTEGER,
    cache_hit_rate DECIMAL(5,2),
    performance_score DECIMAL(5,2) NOT NULL,
    
    -- Additional metrics as JSONB for flexibility
    additional_metrics JSONB
);

-- Create indexes for data quality metrics
CREATE INDEX IF NOT EXISTS idx_data_quality_measured_at ON data_quality_metrics(measured_at);
CREATE INDEX IF NOT EXISTS idx_data_quality_overall_score ON data_quality_metrics(overall_score);

-- Create function to start ETL execution tracking
CREATE OR REPLACE FUNCTION start_etl_execution(
    p_component VARCHAR(50),
    p_configuration JSONB DEFAULT NULL
) RETURNS UUID AS $$
DECLARE
    v_batch_id UUID;
BEGIN
    v_batch_id := gen_random_uuid();
    
    INSERT INTO etl_execution_log (
        component,
        batch_id,
        status,
        configuration,
        started_at
    ) VALUES (
        p_component,
        v_batch_id,
        'running',
        p_configuration,
        CURRENT_TIMESTAMP
    );
    
    RETURN v_batch_id;
END;
$$ LANGUAGE plpgsql;

-- Create function to complete ETL execution tracking
CREATE OR REPLACE FUNCTION complete_etl_execution(
    p_batch_id UUID,
    p_status VARCHAR(20),
    p_records_processed INTEGER DEFAULT 0,
    p_error_message TEXT DEFAULT NULL,
    p_performance_metrics JSONB DEFAULT NULL
) RETURNS void AS $$
DECLARE
    v_started_at TIMESTAMP WITH TIME ZONE;
    v_execution_time INTEGER;
BEGIN
    -- Get start time
    SELECT started_at INTO v_started_at
    FROM etl_execution_log
    WHERE batch_id = p_batch_id;
    
    -- Calculate execution time
    v_execution_time := EXTRACT(EPOCH FROM (CURRENT_TIMESTAMP - v_started_at));
    
    -- Update execution log
    UPDATE etl_execution_log SET
        completed_at = CURRENT_TIMESTAMP,
        status = p_status,
        records_processed = p_records_processed,
        error_message = p_error_message,
        execution_time_seconds = v_execution_time,
        
        -- Extract performance metrics from JSONB
        records_extracted = COALESCE((p_performance_metrics->>'records_extracted')::INTEGER, records_extracted),
        records_validated = COALESCE((p_performance_metrics->>'records_validated')::INTEGER, records_validated),
        records_normalized = COALESCE((p_performance_metrics->>'records_normalized')::INTEGER, records_normalized),
        records_inserted = COALESCE((p_performance_metrics->>'records_inserted')::INTEGER, records_inserted),
        records_updated = COALESCE((p_performance_metrics->>'records_updated')::INTEGER, records_updated),
        records_failed = COALESCE((p_performance_metrics->>'records_failed')::INTEGER, records_failed),
        api_requests_made = COALESCE((p_performance_metrics->>'api_requests_made')::INTEGER, api_requests_made),
        api_requests_failed = COALESCE((p_performance_metrics->>'api_requests_failed')::INTEGER, api_requests_failed),
        total_api_time_ms = COALESCE((p_performance_metrics->>'total_api_time_ms')::INTEGER, total_api_time_ms),
        memory_used_mb = COALESCE((p_performance_metrics->>'memory_used_mb')::DECIMAL, memory_used_mb)
    WHERE batch_id = p_batch_id;
END;
$$ LANGUAGE plpgsql;

-- Create function to get ETL execution status
CREATE OR REPLACE FUNCTION get_etl_status(p_component VARCHAR(50) DEFAULT NULL)
RETURNS TABLE (
    component VARCHAR(50),
    last_run TIMESTAMP WITH TIME ZONE,
    status VARCHAR(20),
    records_processed INTEGER,
    execution_time_seconds INTEGER,
    success_rate DECIMAL(5,2)
) AS $$
BEGIN
    RETURN QUERY
    WITH recent_executions AS (
        SELECT 
            el.component,
            el.started_at,
            el.status,
            el.records_processed,
            el.execution_time_seconds,
            ROW_NUMBER() OVER (PARTITION BY el.component ORDER BY el.started_at DESC) as rn
        FROM etl_execution_log el
        WHERE (p_component IS NULL OR el.component = p_component)
        AND el.started_at >= CURRENT_TIMESTAMP - INTERVAL '7 days'
    ),
    success_rates AS (
        SELECT 
            el.component,
            ROUND(
                (COUNT(*) FILTER (WHERE el.status = 'completed')::DECIMAL / COUNT(*)) * 100, 
                2
            ) as success_rate
        FROM etl_execution_log el
        WHERE (p_component IS NULL OR el.component = p_component)
        AND el.started_at >= CURRENT_TIMESTAMP - INTERVAL '7 days'
        GROUP BY el.component
    )
    SELECT 
        re.component,
        re.started_at as last_run,
        re.status,
        re.records_processed,
        re.execution_time_seconds,
        COALESCE(sr.success_rate, 0) as success_rate
    FROM recent_executions re
    LEFT JOIN success_rates sr ON re.component = sr.component
    WHERE re.rn = 1
    ORDER BY re.component;
END;
$$ LANGUAGE plpgsql;

-- Create function to calculate data quality score
CREATE OR REPLACE FUNCTION calculate_data_quality_score()
RETURNS DECIMAL(5,2) AS $$
DECLARE
    v_total_products INTEGER;
    v_products_with_visibility INTEGER;
    v_orphaned_inventory INTEGER;
    v_negative_stock INTEGER;
    v_hours_since_product_etl DECIMAL(10,2);
    v_hours_since_inventory_etl DECIMAL(10,2);
    
    v_visibility_score DECIMAL(5,2);
    v_consistency_score DECIMAL(5,2);
    v_freshness_score DECIMAL(5,2);
    v_overall_score DECIMAL(5,2);
BEGIN
    -- Calculate visibility completeness
    SELECT COUNT(*) INTO v_total_products FROM dim_products;
    SELECT COUNT(*) INTO v_products_with_visibility 
    FROM dim_products WHERE visibility IS NOT NULL AND visibility != 'UNKNOWN';
    
    v_visibility_score := CASE 
        WHEN v_total_products = 0 THEN 0
        ELSE (v_products_with_visibility::DECIMAL / v_total_products) * 100
    END;
    
    -- Calculate inventory consistency
    SELECT COUNT(*) INTO v_orphaned_inventory
    FROM inventory i
    LEFT JOIN dim_products p ON i.offer_id = p.offer_id
    WHERE p.offer_id IS NULL;
    
    SELECT COUNT(*) INTO v_negative_stock
    FROM inventory WHERE (present - reserved) < 0;
    
    v_consistency_score := GREATEST(0, 100 - (v_orphaned_inventory * 0.1) - (v_negative_stock * 0.5));
    
    -- Calculate data freshness
    SELECT 
        EXTRACT(EPOCH FROM (CURRENT_TIMESTAMP - MAX(completed_at))) / 3600
    INTO v_hours_since_product_etl
    FROM etl_execution_log 
    WHERE component = 'ProductETL' AND status = 'completed';
    
    SELECT 
        EXTRACT(EPOCH FROM (CURRENT_TIMESTAMP - MAX(completed_at))) / 3600
    INTO v_hours_since_inventory_etl
    FROM etl_execution_log 
    WHERE component = 'InventoryETL' AND status = 'completed';
    
    v_freshness_score := CASE
        WHEN v_hours_since_product_etl IS NULL OR v_hours_since_inventory_etl IS NULL THEN 0
        WHEN GREATEST(v_hours_since_product_etl, v_hours_since_inventory_etl) <= 2 THEN 100
        WHEN GREATEST(v_hours_since_product_etl, v_hours_since_inventory_etl) <= 6 THEN 90
        WHEN GREATEST(v_hours_since_product_etl, v_hours_since_inventory_etl) <= 12 THEN 75
        WHEN GREATEST(v_hours_since_product_etl, v_hours_since_inventory_etl) <= 24 THEN 50
        ELSE 25
    END;
    
    -- Calculate overall score (weighted average)
    v_overall_score := (v_visibility_score * 0.4) + (v_consistency_score * 0.4) + (v_freshness_score * 0.2);
    
    -- Insert metrics record
    INSERT INTO data_quality_metrics (
        overall_score,
        total_products,
        products_with_visibility,
        products_without_visibility,
        visibility_completeness_score,
        total_inventory_records,
        orphaned_inventory_records,
        negative_stock_records,
        inventory_consistency_score,
        hours_since_product_etl,
        hours_since_inventory_etl,
        data_freshness_score,
        performance_score
    ) VALUES (
        v_overall_score,
        v_total_products,
        v_products_with_visibility,
        v_total_products - v_products_with_visibility,
        v_visibility_score,
        (SELECT COUNT(*) FROM inventory),
        v_orphaned_inventory,
        v_negative_stock,
        v_consistency_score,
        v_hours_since_product_etl,
        v_hours_since_inventory_etl,
        v_freshness_score,
        95.0  -- Default performance score, can be updated by monitoring
    );
    
    RETURN v_overall_score;
END;
$$ LANGUAGE plpgsql;

-- Create view for ETL dashboard
CREATE VIEW v_etl_dashboard AS
SELECT 
    -- Current status
    (SELECT calculate_data_quality_score()) as current_quality_score,
    
    -- Recent executions
    (SELECT COUNT(*) FROM etl_execution_log 
     WHERE started_at >= CURRENT_TIMESTAMP - INTERVAL '24 hours') as executions_last_24h,
    
    (SELECT COUNT(*) FROM etl_execution_log 
     WHERE started_at >= CURRENT_TIMESTAMP - INTERVAL '24 hours' 
     AND status = 'failed') as failures_last_24h,
    
    -- Component status
    (SELECT status FROM etl_execution_log 
     WHERE component = 'ProductETL' 
     ORDER BY started_at DESC LIMIT 1) as product_etl_status,
    
    (SELECT status FROM etl_execution_log 
     WHERE component = 'InventoryETL' 
     ORDER BY started_at DESC LIMIT 1) as inventory_etl_status,
    
    -- Data metrics
    (SELECT COUNT(*) FROM dim_products WHERE visibility IN ('VISIBLE', 'ACTIVE', 'продаётся')) as active_products,
    (SELECT COUNT(*) FROM v_detailed_inventory) as products_in_stock,
    (SELECT COUNT(*) FROM v_detailed_inventory WHERE stock_status = 'critical') as critical_products,
    
    -- Timestamps
    CURRENT_TIMESTAMP as dashboard_updated_at;

-- Add comments
COMMENT ON TABLE etl_execution_log IS 'Detailed tracking of individual ETL component executions';
COMMENT ON TABLE etl_workflow_executions IS 'High-level tracking of complete ETL workflow executions';
COMMENT ON TABLE data_quality_metrics IS 'Historical data quality metrics and scores';
COMMENT ON VIEW v_etl_dashboard IS 'Real-time ETL system dashboard with key metrics';

-- Grant permissions
GRANT SELECT ON etl_execution_log TO PUBLIC;
GRANT SELECT ON etl_workflow_executions TO PUBLIC;
GRANT SELECT ON data_quality_metrics TO PUBLIC;
GRANT SELECT ON v_etl_dashboard TO PUBLIC;

-- Grant execute permissions on functions
GRANT EXECUTE ON FUNCTION start_etl_execution(VARCHAR, JSONB) TO etl_user;
GRANT EXECUTE ON FUNCTION complete_etl_execution(UUID, VARCHAR, INTEGER, TEXT, JSONB) TO etl_user;
GRANT EXECUTE ON FUNCTION get_etl_status(VARCHAR) TO PUBLIC;
GRANT EXECUTE ON FUNCTION calculate_data_quality_score() TO etl_user;

-- Log migration completion
INSERT INTO migration_log (migration_name, executed_at, description) 
VALUES (
    'ozon_etl_refactoring_003_create_etl_tracking',
    CURRENT_TIMESTAMP,
    'Created ETL tracking system with execution logs, workflow tracking, and data quality metrics'
) ON CONFLICT (migration_name) DO UPDATE SET 
    executed_at = CURRENT_TIMESTAMP,
    description = EXCLUDED.description;

COMMIT;

-- Verify migration success
DO $$
DECLARE
    tables_created INTEGER;
    functions_created INTEGER;
    views_created INTEGER;
BEGIN
    -- Check tables
    SELECT COUNT(*) INTO tables_created
    FROM information_schema.tables 
    WHERE table_name IN ('etl_execution_log', 'etl_workflow_executions', 'data_quality_metrics');
    
    -- Check functions
    SELECT COUNT(*) INTO functions_created
    FROM pg_proc 
    WHERE proname IN ('start_etl_execution', 'complete_etl_execution', 'get_etl_status', 'calculate_data_quality_score');
    
    -- Check views
    SELECT COUNT(*) INTO views_created
    FROM information_schema.views 
    WHERE table_name = 'v_etl_dashboard';
    
    IF tables_created = 3 AND functions_created = 4 AND views_created = 1 THEN
        RAISE NOTICE 'Migration 003 completed successfully - ETL tracking system created';
    ELSE
        RAISE EXCEPTION 'Migration 003 failed - tables: %, functions: %, views: %', tables_created, functions_created, views_created;
    END IF;
END $$;