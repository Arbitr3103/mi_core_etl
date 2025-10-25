-- Migration: Create warehouse_normalization table for warehouse name mapping
-- Purpose: Create lookup table for normalizing warehouse names from Analytics API and UI reports
-- Requirements: 14.1, 14.2, 14.3
-- Task: 3.3 Создать таблицу warehouse_normalization (упрощенная)

-- Create warehouse_normalization table
CREATE TABLE IF NOT EXISTS warehouse_normalization (
    id SERIAL PRIMARY KEY,
    
    -- Warehouse name mapping (as required by task)
    original_name VARCHAR(255) NOT NULL,
    normalized_name VARCHAR(255) NOT NULL,
    source_type VARCHAR(20) NOT NULL,
    
    -- Quality and confidence metrics
    confidence_score DECIMAL(3,2) DEFAULT 1.0,
    match_type VARCHAR(20) DEFAULT 'exact',
    
    -- Metadata for tracking
    created_at TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP,
    created_by VARCHAR(100) DEFAULT 'system',
    
    -- Additional warehouse information
    cluster_name VARCHAR(100),
    warehouse_code VARCHAR(50),
    region VARCHAR(100),
    is_active BOOLEAN DEFAULT true,
    
    -- Usage statistics
    usage_count INTEGER DEFAULT 0,
    last_used_at TIMESTAMP WITH TIME ZONE,
    
    -- Constraints for data integrity
    UNIQUE(original_name, source_type),
    CONSTRAINT norm_valid_source CHECK (
        source_type IN ('api', 'ui_report', 'manual', 'auto_detected')
    ),
    CONSTRAINT norm_valid_confidence CHECK (
        confidence_score >= 0.0 AND confidence_score <= 1.0
    ),
    CONSTRAINT norm_valid_match_type CHECK (
        match_type IN ('exact', 'fuzzy', 'manual', 'rule_based')
    ),
    CONSTRAINT norm_non_empty_names CHECK (
        LENGTH(TRIM(original_name)) > 0 AND LENGTH(TRIM(normalized_name)) > 0
    )
);

-- Create indexes for fast lookup (as required by task)
CREATE INDEX IF NOT EXISTS idx_norm_original_name ON warehouse_normalization(original_name);
CREATE INDEX IF NOT EXISTS idx_norm_normalized_name ON warehouse_normalization(normalized_name);
CREATE INDEX IF NOT EXISTS idx_norm_source_type ON warehouse_normalization(source_type);
CREATE INDEX IF NOT EXISTS idx_norm_active ON warehouse_normalization(is_active);
CREATE INDEX IF NOT EXISTS idx_norm_confidence ON warehouse_normalization(confidence_score);
CREATE INDEX IF NOT EXISTS idx_norm_updated_at ON warehouse_normalization(updated_at);

-- Composite indexes for common query patterns
CREATE INDEX IF NOT EXISTS idx_norm_original_source ON warehouse_normalization(original_name, source_type);
CREATE INDEX IF NOT EXISTS idx_norm_normalized_active ON warehouse_normalization(normalized_name, is_active);
CREATE INDEX IF NOT EXISTS idx_norm_source_active ON warehouse_normalization(source_type, is_active);

-- Add comments for documentation
COMMENT ON TABLE warehouse_normalization IS 'Lookup table for normalizing warehouse names from different sources (Analytics API, UI reports)';
COMMENT ON COLUMN warehouse_normalization.original_name IS 'Original warehouse name as received from source';
COMMENT ON COLUMN warehouse_normalization.normalized_name IS 'Standardized warehouse name for consistent reporting';
COMMENT ON COLUMN warehouse_normalization.source_type IS 'Source of original name: api, ui_report, manual, auto_detected';
COMMENT ON COLUMN warehouse_normalization.confidence_score IS 'Confidence level of normalization mapping (0.0-1.0)';
COMMENT ON COLUMN warehouse_normalization.match_type IS 'Type of matching used: exact, fuzzy, manual, rule_based';
COMMENT ON COLUMN warehouse_normalization.cluster_name IS 'Warehouse cluster or group name for organizational purposes';
COMMENT ON COLUMN warehouse_normalization.warehouse_code IS 'Internal warehouse code or identifier';
COMMENT ON COLUMN warehouse_normalization.usage_count IS 'Number of times this mapping has been used';
COMMENT ON COLUMN warehouse_normalization.last_used_at IS 'Timestamp of last usage for cleanup purposes';

-- Insert some common warehouse normalization rules
INSERT INTO warehouse_normalization (original_name, normalized_name, source_type, confidence_score, match_type, cluster_name) VALUES
-- РФЦ variations
('РФЦ Москва', 'РФЦ_МОСКВА', 'api', 1.0, 'exact', 'РФЦ'),
('РФЦ МОСКВА', 'РФЦ_МОСКВА', 'ui_report', 1.0, 'exact', 'РФЦ'),
('рфц москва', 'РФЦ_МОСКВА', 'manual', 1.0, 'rule_based', 'РФЦ'),
('РФЦ СПб', 'РФЦ_САНКТ_ПЕТЕРБУРГ', 'api', 1.0, 'exact', 'РФЦ'),
('РФЦ САНКТ-ПЕТЕРБУРГ', 'РФЦ_САНКТ_ПЕТЕРБУРГ', 'ui_report', 1.0, 'exact', 'РФЦ'),

-- МРФЦ variations
('МРФЦ Екатеринбург', 'МРФЦ_ЕКАТЕРИНБУРГ', 'api', 1.0, 'exact', 'МРФЦ'),
('МРФЦ ЕКАТЕРИНБУРГ', 'МРФЦ_ЕКАТЕРИНБУРГ', 'ui_report', 1.0, 'exact', 'МРФЦ'),
('МРФЦ Новосибирск', 'МРФЦ_НОВОСИБИРСК', 'api', 1.0, 'exact', 'МРФЦ'),
('МРФЦ НОВОСИБИРСК', 'МРФЦ_НОВОСИБИРСК', 'ui_report', 1.0, 'exact', 'МРФЦ'),

-- Common variations
('Склад Москва', 'СКЛАД_МОСКВА', 'ui_report', 0.9, 'rule_based', 'РЕГИОНАЛЬНЫЙ'),
('Warehouse Moscow', 'РФЦ_МОСКВА', 'api', 0.8, 'fuzzy', 'РФЦ'),
('Центральный склад', 'ЦЕНТРАЛЬНЫЙ_СКЛАД', 'manual', 1.0, 'manual', 'ЦЕНТРАЛЬНЫЙ')

ON CONFLICT (original_name, source_type) DO NOTHING;