-- =====================================================
-- MDM System Database Constraints
-- Data integrity and business rules enforcement
-- =====================================================

USE mdm_system;

-- =====================================================
-- Check Constraints for Data Validation
-- =====================================================

-- Master Products Constraints
ALTER TABLE master_products 
ADD CONSTRAINT chk_master_id_not_empty 
CHECK (LENGTH(TRIM(master_id)) > 0);

ALTER TABLE master_products 
ADD CONSTRAINT chk_canonical_name_not_empty 
CHECK (LENGTH(TRIM(canonical_name)) > 0);

ALTER TABLE master_products 
ADD CONSTRAINT chk_canonical_name_length 
CHECK (LENGTH(canonical_name) >= 3 AND LENGTH(canonical_name) <= 500);

ALTER TABLE master_products 
ADD CONSTRAINT chk_canonical_brand_length 
CHECK (canonical_brand IS NULL OR LENGTH(canonical_brand) <= 200);

ALTER TABLE master_products 
ADD CONSTRAINT chk_canonical_category_length 
CHECK (canonical_category IS NULL OR LENGTH(canonical_category) <= 200);

ALTER TABLE master_products 
ADD CONSTRAINT chk_weight_positive 
CHECK (weight_grams IS NULL OR weight_grams > 0);

ALTER TABLE master_products 
ADD CONSTRAINT chk_barcode_format 
CHECK (barcode IS NULL OR barcode REGEXP '^[0-9]{8,14}$');

-- SKU Mapping Constraints
ALTER TABLE sku_mapping 
ADD CONSTRAINT chk_external_sku_not_empty 
CHECK (LENGTH(TRIM(external_sku)) > 0);

ALTER TABLE sku_mapping 
ADD CONSTRAINT chk_source_not_empty 
CHECK (LENGTH(TRIM(source)) > 0);

ALTER TABLE sku_mapping 
ADD CONSTRAINT chk_source_valid 
CHECK (source IN ('ozon', 'wildberries', 'internal', 'yandex_market', 'avito', 'manual', 'api_import'));

ALTER TABLE sku_mapping 
ADD CONSTRAINT chk_source_price_positive 
CHECK (source_price IS NULL OR source_price >= 0);

ALTER TABLE sku_mapping 
ADD CONSTRAINT chk_verification_status_valid 
CHECK (verification_status IN ('auto', 'manual', 'pending', 'rejected'));

-- Data Quality Metrics Constraints
ALTER TABLE data_quality_metrics 
ADD CONSTRAINT chk_metric_name_not_empty 
CHECK (LENGTH(TRIM(metric_name)) > 0);

ALTER TABLE data_quality_metrics 
ADD CONSTRAINT chk_total_records_non_negative 
CHECK (total_records >= 0);

ALTER TABLE data_quality_metrics 
ADD CONSTRAINT chk_good_records_non_negative 
CHECK (good_records >= 0);

ALTER TABLE data_quality_metrics 
ADD CONSTRAINT chk_bad_records_non_negative 
CHECK (bad_records >= 0);

ALTER TABLE data_quality_metrics 
ADD CONSTRAINT chk_records_consistency 
CHECK (total_records = good_records + bad_records);

ALTER TABLE data_quality_metrics 
ADD CONSTRAINT chk_category_valid 
CHECK (category IS NULL OR category IN ('completeness', 'accuracy', 'consistency', 'validity', 'uniqueness', 'timeliness'));

-- Matching History Constraints
ALTER TABLE matching_history 
ADD CONSTRAINT chk_processing_time_positive 
CHECK (processing_time_ms IS NULL OR processing_time_ms >= 0);

ALTER TABLE matching_history 
ADD CONSTRAINT chk_final_decision_valid 
CHECK (final_decision IN ('auto_matched', 'manual_matched', 'new_master', 'rejected'));

-- Audit Log Constraints
ALTER TABLE audit_log 
ADD CONSTRAINT chk_table_name_not_empty 
CHECK (LENGTH(TRIM(table_name)) > 0);

ALTER TABLE audit_log 
ADD CONSTRAINT chk_record_id_not_empty 
CHECK (LENGTH(TRIM(record_id)) > 0);

ALTER TABLE audit_log 
ADD CONSTRAINT chk_action_valid 
CHECK (action IN ('INSERT', 'UPDATE', 'DELETE', 'MERGE'));

-- =====================================================
-- Business Logic Constraints
-- =====================================================

-- Ensure master products have unique canonical names within same brand
-- (This is a business rule that can be adjusted based on requirements)
CREATE UNIQUE INDEX idx_unique_name_brand ON master_products (canonical_name, canonical_brand) 
WHERE status = 'active';

-- Ensure no duplicate external SKUs within same source
-- (Already handled by unique constraint, but adding for clarity)
-- ALTER TABLE sku_mapping ADD CONSTRAINT uk_source_sku UNIQUE (source, external_sku);

-- =====================================================
-- Referential Integrity Constraints
-- =====================================================

-- Ensure master_id in sku_mapping exists in master_products
-- (Already defined as foreign key, but ensuring it's properly set)
ALTER TABLE sku_mapping 
DROP FOREIGN KEY IF EXISTS fk_sku_mapping_master_id;

ALTER TABLE sku_mapping 
ADD CONSTRAINT fk_sku_mapping_master_id 
FOREIGN KEY (master_id) REFERENCES master_products(master_id) 
ON DELETE CASCADE ON UPDATE CASCADE;

-- Ensure master_id in matching_history exists in master_products (optional)
ALTER TABLE matching_history 
DROP FOREIGN KEY IF EXISTS fk_matching_history_master_id;

ALTER TABLE matching_history 
ADD CONSTRAINT fk_matching_history_master_id 
FOREIGN KEY (master_id) REFERENCES master_products(master_id) 
ON DELETE SET NULL ON UPDATE CASCADE;

-- =====================================================
-- Custom Validation Functions
-- =====================================================

DELIMITER $$

-- Function to validate Master ID format
CREATE FUNCTION fn_validate_master_id(master_id VARCHAR(50)) 
RETURNS BOOLEAN
READS SQL DATA
DETERMINISTIC
BEGIN
    DECLARE is_valid BOOLEAN DEFAULT FALSE;
    
    -- Check if master_id follows the pattern: MASTER_XXXXXXXX or PROD_XXXXXXXX
    IF master_id REGEXP '^(MASTER_|PROD_)[0-9A-Z]{6,}$' THEN
        SET is_valid = TRUE;
    END IF;
    
    RETURN is_valid;
END$$

-- Function to validate external SKU format
CREATE FUNCTION fn_validate_external_sku(sku VARCHAR(200), source VARCHAR(50)) 
RETURNS BOOLEAN
READS SQL DATA
DETERMINISTIC
BEGIN
    DECLARE is_valid BOOLEAN DEFAULT TRUE;
    
    -- Source-specific SKU validation
    CASE source
        WHEN 'ozon' THEN
            -- Ozon SKUs are typically numeric
            IF NOT (sku REGEXP '^[0-9]+$' AND LENGTH(sku) >= 6) THEN
                SET is_valid = FALSE;
            END IF;
        WHEN 'wildberries' THEN
            -- Wildberries SKUs are typically numeric
            IF NOT (sku REGEXP '^[0-9]+$' AND LENGTH(sku) >= 6) THEN
                SET is_valid = FALSE;
            END IF;
        WHEN 'internal' THEN
            -- Internal SKUs can be alphanumeric
            IF NOT (sku REGEXP '^[A-Z0-9_-]+$' AND LENGTH(sku) >= 3) THEN
                SET is_valid = FALSE;
            END IF;
        ELSE
            -- Generic validation for other sources
            IF LENGTH(TRIM(sku)) < 3 THEN
                SET is_valid = FALSE;
            END IF;
    END CASE;
    
    RETURN is_valid;
END$$

-- Function to calculate confidence score based on matching criteria
CREATE FUNCTION fn_calculate_confidence_score(
    name_similarity DECIMAL(3,2),
    brand_match BOOLEAN,
    category_match BOOLEAN,
    barcode_match BOOLEAN
) 
RETURNS DECIMAL(3,2)
READS SQL DATA
DETERMINISTIC
BEGIN
    DECLARE confidence DECIMAL(3,2) DEFAULT 0.0;
    
    -- Base score from name similarity (40% weight)
    SET confidence = confidence + (name_similarity * 0.4);
    
    -- Brand match bonus (30% weight)
    IF brand_match THEN
        SET confidence = confidence + 0.3;
    END IF;
    
    -- Category match bonus (20% weight)
    IF category_match THEN
        SET confidence = confidence + 0.2;
    END IF;
    
    -- Barcode match bonus (10% weight, but very strong indicator)
    IF barcode_match THEN
        SET confidence = confidence + 0.1;
        -- If barcode matches, boost confidence significantly
        SET confidence = GREATEST(confidence, 0.9);
    END IF;
    
    -- Ensure confidence is within valid range
    SET confidence = GREATEST(0.0, LEAST(1.0, confidence));
    
    RETURN confidence;
END$$

DELIMITER ;

-- =====================================================
-- Constraint Validation Triggers
-- =====================================================

DELIMITER $$

-- Trigger to validate master_id format before insert
CREATE TRIGGER tr_validate_master_id_insert 
BEFORE INSERT ON master_products
FOR EACH ROW
BEGIN
    IF NOT fn_validate_master_id(NEW.master_id) THEN
        SIGNAL SQLSTATE '45000' 
        SET MESSAGE_TEXT = 'Invalid Master ID format. Must follow pattern: MASTER_XXXXXXXX or PROD_XXXXXXXX';
    END IF;
END$$

-- Trigger to validate master_id format before update
CREATE TRIGGER tr_validate_master_id_update 
BEFORE UPDATE ON master_products
FOR EACH ROW
BEGIN
    IF NOT fn_validate_master_id(NEW.master_id) THEN
        SIGNAL SQLSTATE '45000' 
        SET MESSAGE_TEXT = 'Invalid Master ID format. Must follow pattern: MASTER_XXXXXXXX or PROD_XXXXXXXX';
    END IF;
END$$

-- Trigger to validate external SKU format before insert
CREATE TRIGGER tr_validate_external_sku_insert 
BEFORE INSERT ON sku_mapping
FOR EACH ROW
BEGIN
    IF NOT fn_validate_external_sku(NEW.external_sku, NEW.source) THEN
        SIGNAL SQLSTATE '45000' 
        SET MESSAGE_TEXT = 'Invalid external SKU format for the specified source';
    END IF;
END$$

-- Trigger to validate external SKU format before update
CREATE TRIGGER tr_validate_external_sku_update 
BEFORE UPDATE ON sku_mapping
FOR EACH ROW
BEGIN
    IF NOT fn_validate_external_sku(NEW.external_sku, NEW.source) THEN
        SIGNAL SQLSTATE '45000' 
        SET MESSAGE_TEXT = 'Invalid external SKU format for the specified source';
    END IF;
END$$

-- Trigger to prevent deletion of master products with active mappings
CREATE TRIGGER tr_prevent_master_delete_with_mappings 
BEFORE DELETE ON master_products
FOR EACH ROW
BEGIN
    DECLARE mapping_count INT DEFAULT 0;
    
    SELECT COUNT(*) INTO mapping_count 
    FROM sku_mapping 
    WHERE master_id = OLD.master_id 
    AND verification_status IN ('auto', 'manual');
    
    IF mapping_count > 0 THEN
        SIGNAL SQLSTATE '45000' 
        SET MESSAGE_TEXT = 'Cannot delete master product with active SKU mappings. Set status to inactive instead.';
    END IF;
END$$

DELIMITER ;

-- =====================================================
-- Data Consistency Checks
-- =====================================================

-- Create a procedure to run data consistency checks
DELIMITER $$

CREATE PROCEDURE sp_run_consistency_checks()
BEGIN
    DECLARE done INT DEFAULT FALSE;
    DECLARE error_count INT DEFAULT 0;
    DECLARE check_name VARCHAR(100);
    DECLARE check_result TEXT;
    
    -- Temporary table to store check results
    CREATE TEMPORARY TABLE temp_consistency_results (
        check_name VARCHAR(100),
        status ENUM('PASS', 'FAIL'),
        error_count INT,
        details TEXT
    );
    
    -- Check 1: Orphaned SKU mappings
    SELECT COUNT(*) INTO error_count
    FROM sku_mapping sm
    LEFT JOIN master_products mp ON sm.master_id = mp.master_id
    WHERE mp.master_id IS NULL;
    
    INSERT INTO temp_consistency_results VALUES (
        'Orphaned SKU Mappings',
        CASE WHEN error_count = 0 THEN 'PASS' ELSE 'FAIL' END,
        error_count,
        CASE WHEN error_count > 0 THEN CONCAT(error_count, ' SKU mappings reference non-existent master products') ELSE 'All SKU mappings have valid master product references' END
    );
    
    -- Check 2: Duplicate external SKUs within same source
    SELECT COUNT(*) INTO error_count
    FROM (
        SELECT source, external_sku, COUNT(*) as dup_count
        FROM sku_mapping
        GROUP BY source, external_sku
        HAVING COUNT(*) > 1
    ) duplicates;
    
    INSERT INTO temp_consistency_results VALUES (
        'Duplicate External SKUs',
        CASE WHEN error_count = 0 THEN 'PASS' ELSE 'FAIL' END,
        error_count,
        CASE WHEN error_count > 0 THEN CONCAT(error_count, ' duplicate external SKU entries found') ELSE 'No duplicate external SKUs found' END
    );
    
    -- Check 3: Master products without any SKU mappings
    SELECT COUNT(*) INTO error_count
    FROM master_products mp
    LEFT JOIN sku_mapping sm ON mp.master_id = sm.master_id
    WHERE mp.status = 'active' AND sm.master_id IS NULL;
    
    INSERT INTO temp_consistency_results VALUES (
        'Master Products Without Mappings',
        CASE WHEN error_count = 0 THEN 'PASS' ELSE 'FAIL' END,
        error_count,
        CASE WHEN error_count > 0 THEN CONCAT(error_count, ' active master products have no SKU mappings') ELSE 'All active master products have SKU mappings' END
    );
    
    -- Check 4: Invalid confidence scores
    SELECT COUNT(*) INTO error_count
    FROM sku_mapping
    WHERE confidence_score IS NOT NULL 
    AND (confidence_score < 0.0 OR confidence_score > 1.0);
    
    INSERT INTO temp_consistency_results VALUES (
        'Invalid Confidence Scores',
        CASE WHEN error_count = 0 THEN 'PASS' ELSE 'FAIL' END,
        error_count,
        CASE WHEN error_count > 0 THEN CONCAT(error_count, ' SKU mappings have invalid confidence scores') ELSE 'All confidence scores are within valid range' END
    );
    
    -- Return results
    SELECT * FROM temp_consistency_results;
    
    -- Clean up
    DROP TEMPORARY TABLE temp_consistency_results;
    
END$$

DELIMITER ;

-- =====================================================
-- Constraint Documentation
-- =====================================================

-- Create a table to document all constraints and their purposes
CREATE TABLE constraint_documentation (
    constraint_name VARCHAR(100) PRIMARY KEY,
    table_name VARCHAR(100) NOT NULL,
    constraint_type ENUM('CHECK', 'FOREIGN_KEY', 'UNIQUE', 'PRIMARY_KEY', 'TRIGGER') NOT NULL,
    description TEXT NOT NULL,
    business_rule TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Insert constraint documentation
INSERT INTO constraint_documentation VALUES
('chk_master_id_format', 'master_products', 'CHECK', 'Ensures Master ID follows standard format pattern', 'Master IDs must be unique and follow naming convention for system consistency', NOW()),
('chk_confidence_score_range', 'sku_mapping', 'CHECK', 'Ensures confidence scores are between 0.0 and 1.0', 'Confidence scores represent matching certainty and must be within valid probability range', NOW()),
('chk_records_consistency', 'data_quality_metrics', 'CHECK', 'Ensures total records equals sum of good and bad records', 'Data quality metrics must be mathematically consistent for accurate reporting', NOW()),
('fk_sku_mapping_master_id', 'sku_mapping', 'FOREIGN_KEY', 'Ensures SKU mappings reference valid master products', 'Referential integrity prevents orphaned SKU mappings', NOW()),
('unique_source_sku', 'sku_mapping', 'UNIQUE', 'Prevents duplicate external SKUs within same source', 'Each external SKU should be unique within its source system', NOW());

SELECT 'MDM Database Constraints created successfully!' as status;