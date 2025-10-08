-- =====================================================
-- MDM System Database Schema
-- Master Data Management for Product Information
-- =====================================================

-- Create database if not exists
CREATE DATABASE IF NOT EXISTS mdm_system 
CHARACTER SET utf8mb4 
COLLATE utf8mb4_unicode_ci;

USE mdm_system;

-- =====================================================
-- Table: master_products
-- Stores canonical product information with unique Master IDs
-- =====================================================
CREATE TABLE master_products (
    master_id VARCHAR(50) NOT NULL PRIMARY KEY COMMENT 'Unique Master Product ID',
    canonical_name VARCHAR(500) NOT NULL COMMENT 'Standardized product name',
    canonical_brand VARCHAR(200) NULL COMMENT 'Standardized brand name',
    canonical_category VARCHAR(200) NULL COMMENT 'Standardized category',
    description TEXT NULL COMMENT 'Product description',
    attributes JSON NULL COMMENT 'Additional product attributes in JSON format',
    barcode VARCHAR(100) NULL COMMENT 'Product barcode/EAN',
    weight_grams INT NULL COMMENT 'Product weight in grams',
    dimensions_json JSON NULL COMMENT 'Product dimensions (length, width, height)',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP COMMENT 'Record creation timestamp',
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT 'Last update timestamp',
    status ENUM('active', 'inactive', 'pending_review', 'merged') DEFAULT 'active' COMMENT 'Product status',
    created_by VARCHAR(100) NULL COMMENT 'User who created the record',
    updated_by VARCHAR(100) NULL COMMENT 'User who last updated the record',
    
    -- Indexes for performance
    INDEX idx_canonical_name (canonical_name(100)),
    INDEX idx_canonical_brand (canonical_brand),
    INDEX idx_canonical_category (canonical_category),
    INDEX idx_barcode (barcode),
    INDEX idx_status (status),
    INDEX idx_created_at (created_at),
    INDEX idx_updated_at (updated_at),
    
    -- Full-text search index for product search
    FULLTEXT KEY ft_search (canonical_name, canonical_brand, description)
) ENGINE=InnoDB 
COMMENT='Master products table - single source of truth for product data';

-- =====================================================
-- Table: sku_mapping
-- Maps external SKUs from different sources to Master IDs
-- =====================================================
CREATE TABLE sku_mapping (
    id BIGINT AUTO_INCREMENT PRIMARY KEY COMMENT 'Auto-increment ID',
    master_id VARCHAR(50) NOT NULL COMMENT 'Reference to master_products.master_id',
    external_sku VARCHAR(200) NOT NULL COMMENT 'SKU from external source',
    source VARCHAR(50) NOT NULL COMMENT 'Data source (ozon, wildberries, internal, etc.)',
    source_name VARCHAR(500) NULL COMMENT 'Original product name from source',
    source_brand VARCHAR(200) NULL COMMENT 'Original brand from source',
    source_category VARCHAR(200) NULL COMMENT 'Original category from source',
    source_price DECIMAL(10,2) NULL COMMENT 'Price from source',
    source_attributes JSON NULL COMMENT 'Additional attributes from source',
    confidence_score DECIMAL(3,2) NULL COMMENT 'Matching confidence score (0.00-1.00)',
    verification_status ENUM('auto', 'manual', 'pending', 'rejected') DEFAULT 'pending' COMMENT 'Verification status',
    match_method VARCHAR(100) NULL COMMENT 'Method used for matching (exact, fuzzy, manual)',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP COMMENT 'Record creation timestamp',
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT 'Last update timestamp',
    verified_by VARCHAR(100) NULL COMMENT 'User who verified the mapping',
    verified_at TIMESTAMP NULL COMMENT 'Verification timestamp',
    
    -- Foreign key constraint
    FOREIGN KEY (master_id) REFERENCES master_products(master_id) 
        ON DELETE CASCADE ON UPDATE CASCADE,
    
    -- Unique constraint to prevent duplicate source SKUs
    UNIQUE KEY unique_source_sku (source, external_sku),
    
    -- Indexes for performance
    INDEX idx_master_id (master_id),
    INDEX idx_external_sku (external_sku),
    INDEX idx_source (source),
    INDEX idx_verification_status (verification_status),
    INDEX idx_confidence_score (confidence_score),
    INDEX idx_created_at (created_at),
    INDEX idx_verified_at (verified_at),
    
    -- Composite indexes for common queries
    INDEX idx_source_status (source, verification_status),
    INDEX idx_master_source (master_id, source)
) ENGINE=InnoDB 
COMMENT='Mapping table between external SKUs and Master IDs';

-- =====================================================
-- Table: data_quality_metrics
-- Stores data quality metrics and monitoring information
-- =====================================================
CREATE TABLE data_quality_metrics (
    id BIGINT AUTO_INCREMENT PRIMARY KEY COMMENT 'Auto-increment ID',
    metric_name VARCHAR(100) NOT NULL COMMENT 'Name of the quality metric',
    metric_value DECIMAL(10,4) NOT NULL COMMENT 'Calculated metric value',
    metric_percentage DECIMAL(5,2) NULL COMMENT 'Metric as percentage (0.00-100.00)',
    total_records INT NOT NULL DEFAULT 0 COMMENT 'Total number of records analyzed',
    good_records INT NOT NULL DEFAULT 0 COMMENT 'Number of records meeting quality criteria',
    bad_records INT NOT NULL DEFAULT 0 COMMENT 'Number of records not meeting quality criteria',
    source VARCHAR(50) NULL COMMENT 'Data source for the metric',
    category VARCHAR(100) NULL COMMENT 'Metric category (completeness, accuracy, consistency)',
    calculation_date TIMESTAMP DEFAULT CURRENT_TIMESTAMP COMMENT 'When the metric was calculated',
    details JSON NULL COMMENT 'Additional metric details and breakdown',
    
    -- Indexes for performance
    INDEX idx_metric_name (metric_name),
    INDEX idx_calculation_date (calculation_date),
    INDEX idx_source (source),
    INDEX idx_category (category),
    
    -- Composite indexes for common queries
    INDEX idx_metric_date (metric_name, calculation_date),
    INDEX idx_source_date (source, calculation_date)
) ENGINE=InnoDB 
COMMENT='Data quality metrics and monitoring information';

-- =====================================================
-- Table: matching_history
-- Stores history of matching attempts and decisions
-- =====================================================
CREATE TABLE matching_history (
    id BIGINT AUTO_INCREMENT PRIMARY KEY COMMENT 'Auto-increment ID',
    external_sku VARCHAR(200) NOT NULL COMMENT 'External SKU being matched',
    source VARCHAR(50) NOT NULL COMMENT 'Data source',
    master_id VARCHAR(50) NULL COMMENT 'Matched Master ID (if any)',
    match_candidates JSON NULL COMMENT 'List of potential matches with scores',
    final_decision ENUM('auto_matched', 'manual_matched', 'new_master', 'rejected') NOT NULL COMMENT 'Final matching decision',
    confidence_score DECIMAL(3,2) NULL COMMENT 'Final confidence score',
    match_method VARCHAR(100) NULL COMMENT 'Matching method used',
    processing_time_ms INT NULL COMMENT 'Processing time in milliseconds',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP COMMENT 'When matching was performed',
    decided_by VARCHAR(100) NULL COMMENT 'User who made the decision (for manual matches)',
    
    -- Foreign key constraint (optional, as master_id might be NULL)
    FOREIGN KEY (master_id) REFERENCES master_products(master_id) 
        ON DELETE SET NULL ON UPDATE CASCADE,
    
    -- Indexes for performance
    INDEX idx_external_sku (external_sku),
    INDEX idx_source (source),
    INDEX idx_master_id (master_id),
    INDEX idx_final_decision (final_decision),
    INDEX idx_created_at (created_at),
    
    -- Composite indexes
    INDEX idx_sku_source (external_sku, source),
    INDEX idx_decision_date (final_decision, created_at)
) ENGINE=InnoDB 
COMMENT='History of matching attempts and decisions';

-- =====================================================
-- Table: audit_log
-- Stores audit trail of all changes to master data
-- =====================================================
CREATE TABLE audit_log (
    id BIGINT AUTO_INCREMENT PRIMARY KEY COMMENT 'Auto-increment ID',
    table_name VARCHAR(100) NOT NULL COMMENT 'Name of the table that was modified',
    record_id VARCHAR(100) NOT NULL COMMENT 'ID of the record that was modified',
    action ENUM('INSERT', 'UPDATE', 'DELETE', 'MERGE') NOT NULL COMMENT 'Type of action performed',
    old_values JSON NULL COMMENT 'Previous values (for UPDATE and DELETE)',
    new_values JSON NULL COMMENT 'New values (for INSERT and UPDATE)',
    changed_fields JSON NULL COMMENT 'List of fields that were changed',
    user_id VARCHAR(100) NULL COMMENT 'User who performed the action',
    ip_address VARCHAR(45) NULL COMMENT 'IP address of the user',
    user_agent TEXT NULL COMMENT 'User agent string',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP COMMENT 'When the action was performed',
    
    -- Indexes for performance
    INDEX idx_table_name (table_name),
    INDEX idx_record_id (record_id),
    INDEX idx_action (action),
    INDEX idx_user_id (user_id),
    INDEX idx_created_at (created_at),
    
    -- Composite indexes
    INDEX idx_table_record (table_name, record_id),
    INDEX idx_user_date (user_id, created_at)
) ENGINE=InnoDB 
COMMENT='Audit trail of all changes to master data';
-- 
=====================================================
-- Views for common queries and reporting
-- =====================================================

-- View: master_products_with_stats
-- Shows master products with mapping statistics
CREATE VIEW v_master_products_with_stats AS
SELECT 
    mp.master_id,
    mp.canonical_name,
    mp.canonical_brand,
    mp.canonical_category,
    mp.status,
    mp.created_at,
    mp.updated_at,
    COUNT(sm.id) as total_mappings,
    COUNT(CASE WHEN sm.verification_status = 'auto' THEN 1 END) as auto_mappings,
    COUNT(CASE WHEN sm.verification_status = 'manual' THEN 1 END) as manual_mappings,
    COUNT(CASE WHEN sm.verification_status = 'pending' THEN 1 END) as pending_mappings,
    AVG(sm.confidence_score) as avg_confidence_score,
    GROUP_CONCAT(DISTINCT sm.source) as sources
FROM master_products mp
LEFT JOIN sku_mapping sm ON mp.master_id = sm.master_id
GROUP BY mp.master_id, mp.canonical_name, mp.canonical_brand, 
         mp.canonical_category, mp.status, mp.created_at, mp.updated_at;

-- View: pending_verification_queue
-- Shows SKUs that need manual verification
CREATE VIEW v_pending_verification_queue AS
SELECT 
    sm.id,
    sm.external_sku,
    sm.source,
    sm.source_name,
    sm.source_brand,
    sm.source_category,
    sm.confidence_score,
    sm.created_at,
    mp.canonical_name as suggested_master_name,
    mp.canonical_brand as suggested_master_brand,
    mp.canonical_category as suggested_master_category
FROM sku_mapping sm
LEFT JOIN master_products mp ON sm.master_id = mp.master_id
WHERE sm.verification_status = 'pending'
ORDER BY sm.confidence_score DESC, sm.created_at ASC;

-- View: data_quality_summary
-- Shows latest data quality metrics summary
CREATE VIEW v_data_quality_summary AS
SELECT 
    dqm.metric_name,
    dqm.metric_value,
    dqm.metric_percentage,
    dqm.total_records,
    dqm.good_records,
    dqm.bad_records,
    dqm.source,
    dqm.category,
    dqm.calculation_date
FROM data_quality_metrics dqm
INNER JOIN (
    SELECT metric_name, source, MAX(calculation_date) as latest_date
    FROM data_quality_metrics
    GROUP BY metric_name, source
) latest ON dqm.metric_name = latest.metric_name 
    AND dqm.source = latest.source 
    AND dqm.calculation_date = latest.latest_date;

-- =====================================================
-- Triggers for audit logging
-- =====================================================

DELIMITER $$

-- Trigger for master_products INSERT
CREATE TRIGGER tr_master_products_insert 
AFTER INSERT ON master_products
FOR EACH ROW
BEGIN
    INSERT INTO audit_log (
        table_name, record_id, action, new_values, user_id, created_at
    ) VALUES (
        'master_products', 
        NEW.master_id, 
        'INSERT',
        JSON_OBJECT(
            'master_id', NEW.master_id,
            'canonical_name', NEW.canonical_name,
            'canonical_brand', NEW.canonical_brand,
            'canonical_category', NEW.canonical_category,
            'status', NEW.status
        ),
        NEW.created_by,
        NOW()
    );
END$$

-- Trigger for master_products UPDATE
CREATE TRIGGER tr_master_products_update 
AFTER UPDATE ON master_products
FOR EACH ROW
BEGIN
    INSERT INTO audit_log (
        table_name, record_id, action, old_values, new_values, user_id, created_at
    ) VALUES (
        'master_products', 
        NEW.master_id, 
        'UPDATE',
        JSON_OBJECT(
            'master_id', OLD.master_id,
            'canonical_name', OLD.canonical_name,
            'canonical_brand', OLD.canonical_brand,
            'canonical_category', OLD.canonical_category,
            'status', OLD.status
        ),
        JSON_OBJECT(
            'master_id', NEW.master_id,
            'canonical_name', NEW.canonical_name,
            'canonical_brand', NEW.canonical_brand,
            'canonical_category', NEW.canonical_category,
            'status', NEW.status
        ),
        NEW.updated_by,
        NOW()
    );
END$$

-- Trigger for sku_mapping INSERT
CREATE TRIGGER tr_sku_mapping_insert 
AFTER INSERT ON sku_mapping
FOR EACH ROW
BEGIN
    INSERT INTO audit_log (
        table_name, record_id, action, new_values, user_id, created_at
    ) VALUES (
        'sku_mapping', 
        NEW.id, 
        'INSERT',
        JSON_OBJECT(
            'master_id', NEW.master_id,
            'external_sku', NEW.external_sku,
            'source', NEW.source,
            'verification_status', NEW.verification_status
        ),
        NEW.verified_by,
        NOW()
    );
END$$

-- Trigger for sku_mapping UPDATE
CREATE TRIGGER tr_sku_mapping_update 
AFTER UPDATE ON sku_mapping
FOR EACH ROW
BEGIN
    INSERT INTO audit_log (
        table_name, record_id, action, old_values, new_values, user_id, created_at
    ) VALUES (
        'sku_mapping', 
        NEW.id, 
        'UPDATE',
        JSON_OBJECT(
            'master_id', OLD.master_id,
            'external_sku', OLD.external_sku,
            'source', OLD.source,
            'verification_status', OLD.verification_status
        ),
        JSON_OBJECT(
            'master_id', NEW.master_id,
            'external_sku', NEW.external_sku,
            'source', NEW.source,
            'verification_status', NEW.verification_status
        ),
        NEW.verified_by,
        NOW()
    );
END$$

DELIMITER ;

-- =====================================================
-- Stored procedures for common operations
-- =====================================================

DELIMITER $$

-- Procedure to generate unique Master ID
CREATE PROCEDURE sp_generate_master_id(OUT new_master_id VARCHAR(50))
BEGIN
    DECLARE counter INT DEFAULT 1;
    DECLARE temp_id VARCHAR(50);
    DECLARE id_exists INT DEFAULT 1;
    
    WHILE id_exists > 0 DO
        SET temp_id = CONCAT('MASTER_', LPAD(counter, 8, '0'));
        SELECT COUNT(*) INTO id_exists FROM master_products WHERE master_id = temp_id;
        IF id_exists = 0 THEN
            SET new_master_id = temp_id;
        ELSE
            SET counter = counter + 1;
        END IF;
    END WHILE;
END$$

-- Procedure to calculate data quality metrics
CREATE PROCEDURE sp_calculate_quality_metrics()
BEGIN
    DECLARE current_time TIMESTAMP DEFAULT NOW();
    
    -- Calculate master products completeness
    INSERT INTO data_quality_metrics (
        metric_name, metric_value, metric_percentage, total_records, good_records, bad_records,
        category, calculation_date
    )
    SELECT 
        'master_products_completeness',
        (COUNT(CASE WHEN canonical_name IS NOT NULL AND canonical_brand IS NOT NULL 
                   AND canonical_category IS NOT NULL THEN 1 END) / COUNT(*)),
        (COUNT(CASE WHEN canonical_name IS NOT NULL AND canonical_brand IS NOT NULL 
                   AND canonical_category IS NOT NULL THEN 1 END) / COUNT(*)) * 100,
        COUNT(*),
        COUNT(CASE WHEN canonical_name IS NOT NULL AND canonical_brand IS NOT NULL 
                   AND canonical_category IS NOT NULL THEN 1 END),
        COUNT(CASE WHEN canonical_name IS NULL OR canonical_brand IS NULL 
                   OR canonical_category IS NULL THEN 1 END),
        'completeness',
        current_time
    FROM master_products
    WHERE status = 'active';
    
    -- Calculate SKU mapping coverage
    INSERT INTO data_quality_metrics (
        metric_name, metric_value, metric_percentage, total_records, good_records, bad_records,
        category, calculation_date
    )
    SELECT 
        'sku_mapping_coverage',
        (COUNT(CASE WHEN verification_status IN ('auto', 'manual') THEN 1 END) / COUNT(*)),
        (COUNT(CASE WHEN verification_status IN ('auto', 'manual') THEN 1 END) / COUNT(*)) * 100,
        COUNT(*),
        COUNT(CASE WHEN verification_status IN ('auto', 'manual') THEN 1 END),
        COUNT(CASE WHEN verification_status = 'pending' THEN 1 END),
        'coverage',
        current_time
    FROM sku_mapping;
    
    -- Calculate auto-matching accuracy
    INSERT INTO data_quality_metrics (
        metric_name, metric_value, metric_percentage, total_records, good_records, bad_records,
        category, calculation_date
    )
    SELECT 
        'auto_matching_accuracy',
        AVG(confidence_score),
        AVG(confidence_score) * 100,
        COUNT(*),
        COUNT(CASE WHEN confidence_score >= 0.8 THEN 1 END),
        COUNT(CASE WHEN confidence_score < 0.8 THEN 1 END),
        'accuracy',
        current_time
    FROM sku_mapping
    WHERE verification_status = 'auto' AND confidence_score IS NOT NULL;
    
END$$

DELIMITER ;

-- =====================================================
-- Initial data and configuration
-- =====================================================

-- Insert initial quality metric definitions
INSERT INTO data_quality_metrics (
    metric_name, metric_value, metric_percentage, total_records, good_records, bad_records,
    category, calculation_date
) VALUES 
('system_initialization', 1.0, 100.0, 0, 0, 0, 'system', NOW());

-- =====================================================
-- Indexes for performance optimization
-- =====================================================

-- Additional composite indexes for complex queries
CREATE INDEX idx_master_brand_category ON master_products (canonical_brand, canonical_category);
CREATE INDEX idx_sku_confidence_status ON sku_mapping (confidence_score, verification_status);
CREATE INDEX idx_audit_table_date ON audit_log (table_name, created_at);

-- =====================================================
-- Database constraints and checks
-- =====================================================

-- Add check constraints for data validation
ALTER TABLE master_products 
ADD CONSTRAINT chk_master_id_format 
CHECK (master_id REGEXP '^[A-Z0-9_-]+$');

ALTER TABLE sku_mapping 
ADD CONSTRAINT chk_confidence_score_range 
CHECK (confidence_score IS NULL OR (confidence_score >= 0.0 AND confidence_score <= 1.0));

ALTER TABLE data_quality_metrics 
ADD CONSTRAINT chk_metric_percentage_range 
CHECK (metric_percentage IS NULL OR (metric_percentage >= 0.0 AND metric_percentage <= 100.0));

-- =====================================================
-- Comments and documentation
-- =====================================================

-- Add table comments for documentation
ALTER TABLE master_products COMMENT = 'Master products table - canonical source of truth for all product data';
ALTER TABLE sku_mapping COMMENT = 'Mapping between external SKUs and master product IDs';
ALTER TABLE data_quality_metrics COMMENT = 'Data quality metrics and monitoring information';
ALTER TABLE matching_history COMMENT = 'Historical record of all matching attempts and decisions';
ALTER TABLE audit_log COMMENT = 'Complete audit trail of all data modifications';

-- =====================================================
-- Schema version tracking
-- =====================================================

CREATE TABLE schema_version (
    version VARCHAR(20) PRIMARY KEY,
    description TEXT,
    applied_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    applied_by VARCHAR(100)
);

INSERT INTO schema_version (version, description, applied_by) 
VALUES ('1.0.0', 'Initial MDM system schema with core tables, views, triggers and procedures', 'system');

-- =====================================================
-- End of schema creation
-- =====================================================

SELECT 'MDM Database Schema created successfully!' as status;