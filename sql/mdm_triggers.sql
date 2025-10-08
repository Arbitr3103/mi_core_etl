-- MDM Database Triggers for Automatic Report Updates
-- These triggers automatically update reports when master data changes

-- Create tables for tracking updates and async processing
CREATE TABLE IF NOT EXISTS async_update_queue (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    task_type VARCHAR(50) NOT NULL,
    task_data JSON NOT NULL,
    priority INT DEFAULT 3,
    status ENUM('pending', 'processing', 'completed', 'failed') DEFAULT 'pending',
    error_message TEXT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_status_priority (status, priority, created_at)
);

CREATE TABLE IF NOT EXISTS data_change_log (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    change_type VARCHAR(50) NOT NULL,
    entity_id VARCHAR(100) NOT NULL,
    changes JSON NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_change_type_date (change_type, created_at),
    INDEX idx_entity_id (entity_id)
);

-- Trigger for master_products updates
DELIMITER $$

CREATE TRIGGER tr_master_products_after_update
AFTER UPDATE ON master_products
FOR EACH ROW
BEGIN
    DECLARE changes_json JSON;
    
    -- Build changes JSON
    SET changes_json = JSON_OBJECT(
        'old_canonical_name', OLD.canonical_name,
        'new_canonical_name', NEW.canonical_name,
        'old_canonical_brand', OLD.canonical_brand,
        'new_canonical_brand', NEW.canonical_brand,
        'old_canonical_category', OLD.canonical_category,
        'new_canonical_category', NEW.canonical_category,
        'old_status', OLD.status,
        'new_status', NEW.status
    );
    
    -- Log the change
    INSERT INTO data_change_log (change_type, entity_id, changes)
    VALUES ('master_product_update', NEW.master_id, changes_json);
    
    -- Queue async update task
    INSERT INTO async_update_queue (task_type, task_data, priority)
    VALUES (
        'report_update',
        JSON_OBJECT(
            'type', 'master_product_update',
            'master_id', NEW.master_id,
            'changes', changes_json
        ),
        CASE 
            WHEN OLD.canonical_name != NEW.canonical_name OR 
                 OLD.canonical_brand != NEW.canonical_brand OR 
                 OLD.canonical_category != NEW.canonical_category OR
                 OLD.status != NEW.status THEN 2
            ELSE 3
        END
    );
END$$

-- Trigger for master_products inserts
CREATE TRIGGER tr_master_products_after_insert
AFTER INSERT ON master_products
FOR EACH ROW
BEGIN
    -- Log the change
    INSERT INTO data_change_log (change_type, entity_id, changes)
    VALUES ('master_product_create', NEW.master_id, JSON_OBJECT(
        'canonical_name', NEW.canonical_name,
        'canonical_brand', NEW.canonical_brand,
        'canonical_category', NEW.canonical_category,
        'status', NEW.status
    ));
    
    -- Queue async update task
    INSERT INTO async_update_queue (task_type, task_data, priority)
    VALUES (
        'report_update',
        JSON_OBJECT(
            'type', 'master_product_create',
            'master_id', NEW.master_id
        ),
        2
    );
END$$

DELIMITER ;-- Tri
gger for sku_mapping updates
DELIMITER $$

CREATE TRIGGER tr_sku_mapping_after_update
AFTER UPDATE ON sku_mapping
FOR EACH ROW
BEGIN
    DECLARE changes_json JSON;
    
    -- Build changes JSON
    SET changes_json = JSON_OBJECT(
        'old_master_id', OLD.master_id,
        'new_master_id', NEW.master_id,
        'external_sku', NEW.external_sku,
        'old_verification_status', OLD.verification_status,
        'new_verification_status', NEW.verification_status,
        'old_confidence_score', OLD.confidence_score,
        'new_confidence_score', NEW.confidence_score
    );
    
    -- Log the change
    INSERT INTO data_change_log (change_type, entity_id, changes)
    VALUES ('sku_mapping_update', NEW.id, changes_json);
    
    -- Queue async update task
    INSERT INTO async_update_queue (task_type, task_data, priority)
    VALUES (
        'report_update',
        JSON_OBJECT(
            'type', 'sku_mapping_update',
            'mapping_id', NEW.id,
            'master_id', NEW.master_id,
            'external_sku', NEW.external_sku,
            'changes', changes_json
        ),
        CASE 
            WHEN OLD.master_id != NEW.master_id OR 
                 OLD.verification_status != NEW.verification_status THEN 2
            ELSE 3
        END
    );
END$$

-- Trigger for sku_mapping inserts
CREATE TRIGGER tr_sku_mapping_after_insert
AFTER INSERT ON sku_mapping
FOR EACH ROW
BEGIN
    -- Log the change
    INSERT INTO data_change_log (change_type, entity_id, changes)
    VALUES ('sku_mapping_create', NEW.id, JSON_OBJECT(
        'master_id', NEW.master_id,
        'external_sku', NEW.external_sku,
        'source', NEW.source,
        'verification_status', NEW.verification_status
    ));
    
    -- Queue async update task
    INSERT INTO async_update_queue (task_type, task_data, priority)
    VALUES (
        'report_update',
        JSON_OBJECT(
            'type', 'sku_mapping_create',
            'mapping_id', NEW.id,
            'master_id', NEW.master_id,
            'external_sku', NEW.external_sku
        ),
        2
    );
END$$

-- Trigger for inventory_data updates (if table exists)
CREATE TRIGGER tr_inventory_data_after_update
AFTER UPDATE ON inventory_data
FOR EACH ROW
BEGIN
    DECLARE changes_json JSON;
    
    -- Build changes JSON for stock changes
    SET changes_json = JSON_OBJECT(
        'sku', NEW.sku,
        'old_current_stock', OLD.current_stock,
        'new_current_stock', NEW.current_stock,
        'old_reserved_stock', OLD.reserved_stock,
        'new_reserved_stock', NEW.reserved_stock,
        'warehouse_name', NEW.warehouse_name,
        'source', NEW.source
    );
    
    -- Only log if stock levels changed
    IF OLD.current_stock != NEW.current_stock OR OLD.reserved_stock != NEW.reserved_stock THEN
        -- Log the change
        INSERT INTO data_change_log (change_type, entity_id, changes)
        VALUES ('inventory_update', NEW.sku, changes_json);
        
        -- Queue async update task with higher priority for critical stock
        INSERT INTO async_update_queue (task_type, task_data, priority)
        VALUES (
            'report_update',
            JSON_OBJECT(
                'type', 'inventory_update',
                'sku', NEW.sku,
                'changes', changes_json
            ),
            CASE 
                WHEN NEW.current_stock <= 5 OR 
                     (NEW.current_stock <= 10 AND NEW.reserved_stock > 0) THEN 1
                ELSE 3
            END
        );
    END IF;
END$$

DELIMITER ;

-- Create stored procedures for manual report updates

DELIMITER $$

-- Procedure to recalculate all data quality metrics
CREATE PROCEDURE sp_recalculate_data_quality_metrics()
BEGIN
    DECLARE EXIT HANDLER FOR SQLEXCEPTION
    BEGIN
        ROLLBACK;
        RESIGNAL;
    END;
    
    START TRANSACTION;
    
    -- Master data coverage
    INSERT INTO data_quality_metrics (metric_name, metric_value, total_records, good_records)
    SELECT 
        'master_data_coverage',
        ROUND((COUNT(DISTINCT CASE WHEN mp.master_id IS NOT NULL THEN i.sku END) / COUNT(DISTINCT i.sku)) * 100, 2),
        COUNT(DISTINCT i.sku),
        COUNT(DISTINCT CASE WHEN mp.master_id IS NOT NULL THEN i.sku END)
    FROM inventory_data i
    LEFT JOIN sku_mapping sm ON i.sku = sm.external_sku
    LEFT JOIN master_products mp ON sm.master_id = mp.master_id AND mp.status = 'active';
    
    -- Brand completeness
    INSERT INTO data_quality_metrics (metric_name, metric_value, total_records, good_records)
    SELECT 
        'brand_completeness',
        ROUND((COUNT(CASE WHEN canonical_brand IS NOT NULL AND canonical_brand != 'Неизвестный бренд' THEN 1 END) / COUNT(*)) * 100, 2),
        COUNT(*),
        COUNT(CASE WHEN canonical_brand IS NOT NULL AND canonical_brand != 'Неизвестный бренд' THEN 1 END)
    FROM master_products
    WHERE status = 'active';
    
    -- Category completeness
    INSERT INTO data_quality_metrics (metric_name, metric_value, total_records, good_records)
    SELECT 
        'category_completeness',
        ROUND((COUNT(CASE WHEN canonical_category IS NOT NULL AND canonical_category != 'Без категории' THEN 1 END) / COUNT(*)) * 100, 2),
        COUNT(*),
        COUNT(CASE WHEN canonical_category IS NOT NULL AND canonical_category != 'Без категории' THEN 1 END)
    FROM master_products
    WHERE status = 'active';
    
    -- Description completeness
    INSERT INTO data_quality_metrics (metric_name, metric_value, total_records, good_records)
    SELECT 
        'description_completeness',
        ROUND((COUNT(CASE WHEN description IS NOT NULL AND description != '' THEN 1 END) / COUNT(*)) * 100, 2),
        COUNT(*),
        COUNT(CASE WHEN description IS NOT NULL AND description != '' THEN 1 END)
    FROM master_products
    WHERE status = 'active';
    
    -- Verification completeness
    INSERT INTO data_quality_metrics (metric_name, metric_value, total_records, good_records)
    SELECT 
        'verification_completeness',
        ROUND((COUNT(CASE WHEN verification_status IN ('auto', 'manual') THEN 1 END) / COUNT(*)) * 100, 2),
        COUNT(*),
        COUNT(CASE WHEN verification_status IN ('auto', 'manual') THEN 1 END)
    FROM sku_mapping;
    
    COMMIT;
END$$

-- Procedure to clean up old async tasks
CREATE PROCEDURE sp_cleanup_async_tasks(IN days_old INT)
BEGIN
    DELETE FROM async_update_queue 
    WHERE status IN ('completed', 'failed') 
    AND created_at < DATE_SUB(NOW(), INTERVAL days_old DAY);
    
    DELETE FROM data_change_log 
    WHERE created_at < DATE_SUB(NOW(), INTERVAL days_old DAY);
END$$

-- Procedure to process pending async tasks
CREATE PROCEDURE sp_process_async_tasks(IN batch_size INT)
BEGIN
    DECLARE done INT DEFAULT FALSE;
    DECLARE task_id BIGINT;
    DECLARE task_data JSON;
    
    DECLARE task_cursor CURSOR FOR
        SELECT id, task_data
        FROM async_update_queue
        WHERE status = 'pending'
        ORDER BY priority ASC, created_at ASC
        LIMIT batch_size;
    
    DECLARE CONTINUE HANDLER FOR NOT FOUND SET done = TRUE;
    
    OPEN task_cursor;
    
    task_loop: LOOP
        FETCH task_cursor INTO task_id, task_data;
        IF done THEN
            LEAVE task_loop;
        END IF;
        
        -- Mark as processing
        UPDATE async_update_queue 
        SET status = 'processing', updated_at = NOW()
        WHERE id = task_id;
        
        -- Here you would call your PHP service to process the task
        -- For now, we'll just mark it as completed
        UPDATE async_update_queue 
        SET status = 'completed', updated_at = NOW()
        WHERE id = task_id;
        
    END LOOP;
    
    CLOSE task_cursor;
END$$

DELIMITER ;

-- Create events for automatic maintenance (if event scheduler is enabled)

-- Event to recalculate data quality metrics every hour
CREATE EVENT IF NOT EXISTS ev_recalculate_quality_metrics
ON SCHEDULE EVERY 1 HOUR
STARTS CURRENT_TIMESTAMP
DO
    CALL sp_recalculate_data_quality_metrics();

-- Event to clean up old tasks daily
CREATE EVENT IF NOT EXISTS ev_cleanup_async_tasks
ON SCHEDULE EVERY 1 DAY
STARTS CURRENT_TIMESTAMP
DO
    CALL sp_cleanup_async_tasks(7); -- Keep 7 days of history

-- Create indexes for better performance
CREATE INDEX IF NOT EXISTS idx_master_products_brand_category_status 
ON master_products(canonical_brand, canonical_category, status);

CREATE INDEX IF NOT EXISTS idx_sku_mapping_master_sku 
ON sku_mapping(master_id, external_sku);

CREATE INDEX IF NOT EXISTS idx_sku_mapping_verification 
ON sku_mapping(verification_status, confidence_score);

CREATE INDEX IF NOT EXISTS idx_inventory_data_stock_levels 
ON inventory_data(current_stock, reserved_stock);

CREATE INDEX IF NOT EXISTS idx_data_quality_metrics_name_date 
ON data_quality_metrics(metric_name, calculation_date);