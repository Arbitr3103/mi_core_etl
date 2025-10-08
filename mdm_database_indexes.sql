-- =====================================================
-- MDM System Database Indexes Optimization
-- Additional indexes for performance optimization
-- =====================================================

USE mdm_system;

-- =====================================================
-- Performance Indexes for master_products table
-- =====================================================

-- Index for searching by partial name (for autocomplete)
CREATE INDEX idx_master_name_prefix ON master_products (canonical_name(50));

-- Index for brand-based filtering and grouping
CREATE INDEX idx_master_brand_status ON master_products (canonical_brand, status);

-- Index for category-based analytics
CREATE INDEX idx_master_category_status ON master_products (canonical_category, status);

-- Index for recent products (time-based queries)
CREATE INDEX idx_master_created_status ON master_products (created_at DESC, status);

-- Index for barcode lookups
CREATE INDEX idx_master_barcode_unique ON master_products (barcode);

-- Composite index for brand + category filtering
CREATE INDEX idx_master_brand_cat_status ON master_products (canonical_brand, canonical_category, status);

-- =====================================================
-- Performance Indexes for sku_mapping table
-- =====================================================

-- Index for source-specific queries
CREATE INDEX idx_sku_source_status_conf ON sku_mapping (source, verification_status, confidence_score DESC);

-- Index for confidence-based filtering
CREATE INDEX idx_sku_confidence_desc ON sku_mapping (confidence_score DESC, verification_status);

-- Index for verification workflow
CREATE INDEX idx_sku_status_created ON sku_mapping (verification_status, created_at);

-- Index for master product aggregations
CREATE INDEX idx_sku_master_source_status ON sku_mapping (master_id, source, verification_status);

-- Index for external SKU searches with source
CREATE INDEX idx_sku_external_source_status ON sku_mapping (external_sku, source, verification_status);

-- Index for verification user tracking
CREATE INDEX idx_sku_verified_by_date ON sku_mapping (verified_by, verified_at);

-- Index for matching method analysis
CREATE INDEX idx_sku_match_method_conf ON sku_mapping (match_method, confidence_score);

-- =====================================================
-- Performance Indexes for data_quality_metrics table
-- =====================================================

-- Index for metric trending analysis
CREATE INDEX idx_quality_name_date_desc ON data_quality_metrics (metric_name, calculation_date DESC);

-- Index for source-specific quality tracking
CREATE INDEX idx_quality_source_category_date ON data_quality_metrics (source, category, calculation_date DESC);

-- Index for category-based quality analysis
CREATE INDEX idx_quality_category_value ON data_quality_metrics (category, metric_value DESC);

-- Index for latest metrics per source
CREATE INDEX idx_quality_source_name_date ON data_quality_metrics (source, metric_name, calculation_date DESC);

-- =====================================================
-- Performance Indexes for matching_history table
-- =====================================================

-- Index for SKU matching history lookup
CREATE INDEX idx_history_sku_source_date ON matching_history (external_sku, source, created_at DESC);

-- Index for decision analysis
CREATE INDEX idx_history_decision_date ON matching_history (final_decision, created_at DESC);

-- Index for performance analysis
CREATE INDEX idx_history_method_time ON matching_history (match_method, processing_time_ms);

-- Index for confidence score analysis
CREATE INDEX idx_history_confidence_decision ON matching_history (confidence_score DESC, final_decision);

-- Index for user decision tracking
CREATE INDEX idx_history_decided_by_date ON matching_history (decided_by, created_at DESC);

-- =====================================================
-- Performance Indexes for audit_log table
-- =====================================================

-- Index for table-specific audit queries
CREATE INDEX idx_audit_table_record_date ON audit_log (table_name, record_id, created_at DESC);

-- Index for user activity tracking
CREATE INDEX idx_audit_user_action_date ON audit_log (user_id, action, created_at DESC);

-- Index for action-based filtering
CREATE INDEX idx_audit_action_table_date ON audit_log (action, table_name, created_at DESC);

-- Index for recent changes tracking
CREATE INDEX idx_audit_created_desc ON audit_log (created_at DESC);

-- =====================================================
-- Covering Indexes for Common Query Patterns
-- =====================================================

-- Covering index for master products list with stats
CREATE INDEX idx_master_list_covering ON master_products (
    status, canonical_brand, canonical_category, 
    master_id, canonical_name, created_at, updated_at
);

-- Covering index for SKU mapping verification queue
CREATE INDEX idx_sku_verification_covering ON sku_mapping (
    verification_status, confidence_score DESC, created_at,
    master_id, external_sku, source, source_name
);

-- Covering index for quality metrics dashboard
CREATE INDEX idx_quality_dashboard_covering ON data_quality_metrics (
    metric_name, source, calculation_date DESC,
    metric_value, metric_percentage, total_records, good_records
);

-- =====================================================
-- Partial Indexes for Specific Use Cases
-- =====================================================

-- Index only for active master products
CREATE INDEX idx_master_active_name ON master_products (canonical_name) 
WHERE status = 'active';

-- Index only for pending SKU mappings
CREATE INDEX idx_sku_pending_confidence ON sku_mapping (confidence_score DESC, created_at) 
WHERE verification_status = 'pending';

-- Index only for recent audit entries (last 30 days)
CREATE INDEX idx_audit_recent ON audit_log (table_name, record_id, created_at DESC) 
WHERE created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY);

-- =====================================================
-- Function-based Indexes for Search Optimization
-- =====================================================

-- Index for case-insensitive brand search
CREATE INDEX idx_master_brand_lower ON master_products ((LOWER(canonical_brand)));

-- Index for case-insensitive name search
CREATE INDEX idx_master_name_lower ON master_products ((LOWER(canonical_name(100))));

-- Index for normalized external SKU search
CREATE INDEX idx_sku_external_normalized ON sku_mapping ((UPPER(TRIM(external_sku))));

-- =====================================================
-- Statistics and Maintenance
-- =====================================================

-- Update table statistics for query optimizer
ANALYZE TABLE master_products;
ANALYZE TABLE sku_mapping;
ANALYZE TABLE data_quality_metrics;
ANALYZE TABLE matching_history;
ANALYZE TABLE audit_log;

-- =====================================================
-- Index Usage Monitoring Queries
-- =====================================================

-- Query to monitor index usage (for future optimization)
/*
SELECT 
    TABLE_SCHEMA,
    TABLE_NAME,
    INDEX_NAME,
    CARDINALITY,
    SUB_PART,
    PACKED,
    NULLABLE,
    INDEX_TYPE,
    COMMENT
FROM INFORMATION_SCHEMA.STATISTICS 
WHERE TABLE_SCHEMA = 'mdm_system'
ORDER BY TABLE_NAME, INDEX_NAME;
*/

-- Query to check for unused indexes (run periodically)
/*
SELECT 
    s.TABLE_SCHEMA,
    s.TABLE_NAME,
    s.INDEX_NAME,
    s.CARDINALITY
FROM INFORMATION_SCHEMA.STATISTICS s
LEFT JOIN performance_schema.table_io_waits_summary_by_index_usage p 
    ON s.TABLE_SCHEMA = p.OBJECT_SCHEMA 
    AND s.TABLE_NAME = p.OBJECT_NAME 
    AND s.INDEX_NAME = p.INDEX_NAME
WHERE s.TABLE_SCHEMA = 'mdm_system'
    AND p.INDEX_NAME IS NULL
    AND s.INDEX_NAME != 'PRIMARY'
ORDER BY s.TABLE_NAME, s.INDEX_NAME;
*/

SELECT 'MDM Database Indexes created successfully!' as status;