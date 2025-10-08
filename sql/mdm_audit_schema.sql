-- MDM Data Audit Schema

-- Audit log for master products changes
CREATE TABLE mdm_master_products_audit (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    master_id VARCHAR(50) NOT NULL,
    operation ENUM('INSERT', 'UPDATE', 'DELETE') NOT NULL,
    old_values JSON,
    new_values JSON,
    changed_fields JSON,
    user_id BIGINT,
    session_id VARCHAR(128),
    ip_address VARCHAR(45),
    user_agent TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES mdm_users(id) ON DELETE SET NULL,
    INDEX idx_master_id (master_id),
    INDEX idx_operation (operation),
    INDEX idx_user_id (user_id),
    INDEX idx_created_at (created_at)
);

-- Audit log for SKU mapping changes
CREATE TABLE mdm_sku_mapping_audit (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    mapping_id BIGINT,
    master_id VARCHAR(50),
    external_sku VARCHAR(200),
    source VARCHAR(50),
    operation ENUM('INSERT', 'UPDATE', 'DELETE') NOT NULL,
    old_values JSON,
    new_values JSON,
    changed_fields JSON,
    user_id BIGINT,
    session_id VARCHAR(128),
    ip_address VARCHAR(45),
    user_agent TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES mdm_users(id) ON DELETE SET NULL,
    INDEX idx_mapping_id (mapping_id),
    INDEX idx_master_id (master_id),
    INDEX idx_external_sku (external_sku),
    INDEX idx_operation (operation),
    INDEX idx_user_id (user_id),
    INDEX idx_created_at (created_at)
);

-- Version history for master products
CREATE TABLE mdm_master_products_versions (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    master_id VARCHAR(50) NOT NULL,
    version_number INT NOT NULL,
    canonical_name VARCHAR(500),
    canonical_brand VARCHAR(200),
    canonical_category VARCHAR(200),
    description TEXT,
    attributes JSON,
    status ENUM('active', 'inactive', 'pending_review'),
    created_by BIGINT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    is_current BOOLEAN DEFAULT FALSE,
    FOREIGN KEY (created_by) REFERENCES mdm_users(id) ON DELETE SET NULL,
    INDEX idx_master_id_version (master_id, version_number),
    INDEX idx_master_id_current (master_id, is_current),
    INDEX idx_created_at (created_at),
    UNIQUE KEY unique_master_version (master_id, version_number)
);

-- Rollback operations log
CREATE TABLE mdm_rollback_operations (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    operation_type ENUM('master_product', 'sku_mapping') NOT NULL,
    target_id VARCHAR(100) NOT NULL,
    from_version INT,
    to_version INT,
    rollback_reason TEXT,
    rollback_data JSON,
    performed_by BIGINT NOT NULL,
    performed_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    status ENUM('pending', 'completed', 'failed') DEFAULT 'pending',
    error_message TEXT,
    FOREIGN KEY (performed_by) REFERENCES mdm_users(id),
    INDEX idx_operation_type (operation_type),
    INDEX idx_target_id (target_id),
    INDEX idx_performed_by (performed_by),
    INDEX idx_performed_at (performed_at)
);

-- Audit configuration
CREATE TABLE mdm_audit_config (
    id INT AUTO_INCREMENT PRIMARY KEY,
    table_name VARCHAR(100) NOT NULL UNIQUE,
    audit_enabled BOOLEAN DEFAULT TRUE,
    track_inserts BOOLEAN DEFAULT TRUE,
    track_updates BOOLEAN DEFAULT TRUE,
    track_deletes BOOLEAN DEFAULT TRUE,
    retention_days INT DEFAULT 365,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

-- Insert default audit configuration
INSERT INTO mdm_audit_config (table_name, audit_enabled, track_inserts, track_updates, track_deletes, retention_days) VALUES
('master_products', TRUE, TRUE, TRUE, TRUE, 365),
('sku_mapping', TRUE, TRUE, TRUE, TRUE, 365);

-- Triggers for automatic audit logging

-- Master Products Audit Triggers
DELIMITER //

CREATE TRIGGER mdm_master_products_audit_insert
AFTER INSERT ON master_products
FOR EACH ROW
BEGIN
    INSERT INTO mdm_master_products_audit (
        master_id, operation, new_values, user_id, session_id, ip_address
    ) VALUES (
        NEW.master_id,
        'INSERT',
        JSON_OBJECT(
            'master_id', NEW.master_id,
            'canonical_name', NEW.canonical_name,
            'canonical_brand', NEW.canonical_brand,
            'canonical_category', NEW.canonical_category,
            'description', NEW.description,
            'attributes', NEW.attributes,
            'status', NEW.status
        ),
        @audit_user_id,
        @audit_session_id,
        @audit_ip_address
    );
    
    -- Create version record
    INSERT INTO mdm_master_products_versions (
        master_id, version_number, canonical_name, canonical_brand, 
        canonical_category, description, attributes, status, created_by, is_current
    ) VALUES (
        NEW.master_id, 1, NEW.canonical_name, NEW.canonical_brand,
        NEW.canonical_category, NEW.description, NEW.attributes, NEW.status,
        @audit_user_id, TRUE
    );
END//

CREATE TRIGGER mdm_master_products_audit_update
AFTER UPDATE ON master_products
FOR EACH ROW
BEGIN
    DECLARE changed_fields JSON DEFAULT JSON_ARRAY();
    
    -- Check which fields changed
    IF OLD.canonical_name != NEW.canonical_name THEN
        SET changed_fields = JSON_ARRAY_APPEND(changed_fields, '$', 'canonical_name');
    END IF;
    IF OLD.canonical_brand != NEW.canonical_brand THEN
        SET changed_fields = JSON_ARRAY_APPEND(changed_fields, '$', 'canonical_brand');
    END IF;
    IF OLD.canonical_category != NEW.canonical_category THEN
        SET changed_fields = JSON_ARRAY_APPEND(changed_fields, '$', 'canonical_category');
    END IF;
    IF OLD.description != NEW.description THEN
        SET changed_fields = JSON_ARRAY_APPEND(changed_fields, '$', 'description');
    END IF;
    IF OLD.attributes != NEW.attributes THEN
        SET changed_fields = JSON_ARRAY_APPEND(changed_fields, '$', 'attributes');
    END IF;
    IF OLD.status != NEW.status THEN
        SET changed_fields = JSON_ARRAY_APPEND(changed_fields, '$', 'status');
    END IF;
    
    -- Only log if there are actual changes
    IF JSON_LENGTH(changed_fields) > 0 THEN
        INSERT INTO mdm_master_products_audit (
            master_id, operation, old_values, new_values, changed_fields,
            user_id, session_id, ip_address
        ) VALUES (
            NEW.master_id,
            'UPDATE',
            JSON_OBJECT(
                'master_id', OLD.master_id,
                'canonical_name', OLD.canonical_name,
                'canonical_brand', OLD.canonical_brand,
                'canonical_category', OLD.canonical_category,
                'description', OLD.description,
                'attributes', OLD.attributes,
                'status', OLD.status
            ),
            JSON_OBJECT(
                'master_id', NEW.master_id,
                'canonical_name', NEW.canonical_name,
                'canonical_brand', NEW.canonical_brand,
                'canonical_category', NEW.canonical_category,
                'description', NEW.description,
                'attributes', NEW.attributes,
                'status', NEW.status
            ),
            changed_fields,
            @audit_user_id,
            @audit_session_id,
            @audit_ip_address
        );
        
        -- Create new version
        SET @next_version = (
            SELECT COALESCE(MAX(version_number), 0) + 1 
            FROM mdm_master_products_versions 
            WHERE master_id = NEW.master_id
        );
        
        -- Mark previous version as not current
        UPDATE mdm_master_products_versions 
        SET is_current = FALSE 
        WHERE master_id = NEW.master_id AND is_current = TRUE;
        
        -- Insert new version
        INSERT INTO mdm_master_products_versions (
            master_id, version_number, canonical_name, canonical_brand,
            canonical_category, description, attributes, status, created_by, is_current
        ) VALUES (
            NEW.master_id, @next_version, NEW.canonical_name, NEW.canonical_brand,
            NEW.canonical_category, NEW.description, NEW.attributes, NEW.status,
            @audit_user_id, TRUE
        );
    END IF;
END//

CREATE TRIGGER mdm_master_products_audit_delete
AFTER DELETE ON master_products
FOR EACH ROW
BEGIN
    INSERT INTO mdm_master_products_audit (
        master_id, operation, old_values, user_id, session_id, ip_address
    ) VALUES (
        OLD.master_id,
        'DELETE',
        JSON_OBJECT(
            'master_id', OLD.master_id,
            'canonical_name', OLD.canonical_name,
            'canonical_brand', OLD.canonical_brand,
            'canonical_category', OLD.canonical_category,
            'description', OLD.description,
            'attributes', OLD.attributes,
            'status', OLD.status
        ),
        @audit_user_id,
        @audit_session_id,
        @audit_ip_address
    );
END//

-- SKU Mapping Audit Triggers
CREATE TRIGGER mdm_sku_mapping_audit_insert
AFTER INSERT ON sku_mapping
FOR EACH ROW
BEGIN
    INSERT INTO mdm_sku_mapping_audit (
        mapping_id, master_id, external_sku, source, operation, new_values,
        user_id, session_id, ip_address
    ) VALUES (
        NEW.id, NEW.master_id, NEW.external_sku, NEW.source, 'INSERT',
        JSON_OBJECT(
            'id', NEW.id,
            'master_id', NEW.master_id,
            'external_sku', NEW.external_sku,
            'source', NEW.source,
            'source_name', NEW.source_name,
            'source_brand', NEW.source_brand,
            'source_category', NEW.source_category,
            'confidence_score', NEW.confidence_score,
            'verification_status', NEW.verification_status
        ),
        @audit_user_id,
        @audit_session_id,
        @audit_ip_address
    );
END//

CREATE TRIGGER mdm_sku_mapping_audit_update
AFTER UPDATE ON sku_mapping
FOR EACH ROW
BEGIN
    DECLARE changed_fields JSON DEFAULT JSON_ARRAY();
    
    -- Check which fields changed
    IF OLD.master_id != NEW.master_id THEN
        SET changed_fields = JSON_ARRAY_APPEND(changed_fields, '$', 'master_id');
    END IF;
    IF OLD.external_sku != NEW.external_sku THEN
        SET changed_fields = JSON_ARRAY_APPEND(changed_fields, '$', 'external_sku');
    END IF;
    IF OLD.source != NEW.source THEN
        SET changed_fields = JSON_ARRAY_APPEND(changed_fields, '$', 'source');
    END IF;
    IF OLD.verification_status != NEW.verification_status THEN
        SET changed_fields = JSON_ARRAY_APPEND(changed_fields, '$', 'verification_status');
    END IF;
    
    -- Only log if there are actual changes
    IF JSON_LENGTH(changed_fields) > 0 THEN
        INSERT INTO mdm_sku_mapping_audit (
            mapping_id, master_id, external_sku, source, operation,
            old_values, new_values, changed_fields, user_id, session_id, ip_address
        ) VALUES (
            NEW.id, NEW.master_id, NEW.external_sku, NEW.source, 'UPDATE',
            JSON_OBJECT(
                'id', OLD.id,
                'master_id', OLD.master_id,
                'external_sku', OLD.external_sku,
                'source', OLD.source,
                'source_name', OLD.source_name,
                'source_brand', OLD.source_brand,
                'source_category', OLD.source_category,
                'confidence_score', OLD.confidence_score,
                'verification_status', OLD.verification_status
            ),
            JSON_OBJECT(
                'id', NEW.id,
                'master_id', NEW.master_id,
                'external_sku', NEW.external_sku,
                'source', NEW.source,
                'source_name', NEW.source_name,
                'source_brand', NEW.source_brand,
                'source_category', NEW.source_category,
                'confidence_score', NEW.confidence_score,
                'verification_status', NEW.verification_status
            ),
            changed_fields,
            @audit_user_id,
            @audit_session_id,
            @audit_ip_address
        );
    END IF;
END//

CREATE TRIGGER mdm_sku_mapping_audit_delete
AFTER DELETE ON sku_mapping
FOR EACH ROW
BEGIN
    INSERT INTO mdm_sku_mapping_audit (
        mapping_id, master_id, external_sku, source, operation, old_values,
        user_id, session_id, ip_address
    ) VALUES (
        OLD.id, OLD.master_id, OLD.external_sku, OLD.source, 'DELETE',
        JSON_OBJECT(
            'id', OLD.id,
            'master_id', OLD.master_id,
            'external_sku', OLD.external_sku,
            'source', OLD.source,
            'source_name', OLD.source_name,
            'source_brand', OLD.source_brand,
            'source_category', OLD.source_category,
            'confidence_score', OLD.confidence_score,
            'verification_status', OLD.verification_status
        ),
        @audit_user_id,
        @audit_session_id,
        @audit_ip_address
    );
END//

DELIMITER ;