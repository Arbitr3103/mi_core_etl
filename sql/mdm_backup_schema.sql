-- MDM Backup System Schema

-- Backup jobs configuration and history
CREATE TABLE mdm_backup_jobs (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    job_name VARCHAR(100) NOT NULL,
    backup_type ENUM('full', 'incremental', 'differential') NOT NULL,
    schedule_cron VARCHAR(100),
    tables_to_backup JSON,
    backup_path VARCHAR(500),
    compression_enabled BOOLEAN DEFAULT TRUE,
    encryption_enabled BOOLEAN DEFAULT FALSE,
    retention_days INT DEFAULT 30,
    status ENUM('active', 'inactive', 'paused') DEFAULT 'active',
    created_by BIGINT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (created_by) REFERENCES mdm_users(id),
    INDEX idx_job_name (job_name),
    INDEX idx_status (status)
);

-- Backup execution history
CREATE TABLE mdm_backup_executions (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    job_id BIGINT NOT NULL,
    execution_type ENUM('manual', 'scheduled') NOT NULL,
    backup_file_path VARCHAR(500),
    backup_file_size BIGINT,
    backup_checksum VARCHAR(64),
    tables_backed_up JSON,
    records_count JSON,
    started_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    completed_at TIMESTAMP NULL,
    status ENUM('running', 'completed', 'failed', 'cancelled') DEFAULT 'running',
    error_message TEXT,
    executed_by BIGINT,
    FOREIGN KEY (job_id) REFERENCES mdm_backup_jobs(id) ON DELETE CASCADE,
    FOREIGN KEY (executed_by) REFERENCES mdm_users(id),
    INDEX idx_job_id (job_id),
    INDEX idx_status (status),
    INDEX idx_started_at (started_at)
);

-- Restore operations history
CREATE TABLE mdm_restore_operations (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    backup_execution_id BIGINT NOT NULL,
    restore_type ENUM('full', 'selective') NOT NULL,
    tables_to_restore JSON,
    target_database VARCHAR(100),
    restore_options JSON,
    started_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    completed_at TIMESTAMP NULL,
    status ENUM('running', 'completed', 'failed', 'cancelled') DEFAULT 'running',
    error_message TEXT,
    records_restored JSON,
    performed_by BIGINT NOT NULL,
    FOREIGN KEY (backup_execution_id) REFERENCES mdm_backup_executions(id),
    FOREIGN KEY (performed_by) REFERENCES mdm_users(id),
    INDEX idx_backup_execution_id (backup_execution_id),
    INDEX idx_status (status),
    INDEX idx_started_at (started_at)
);

-- Backup verification tests
CREATE TABLE mdm_backup_verifications (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    backup_execution_id BIGINT NOT NULL,
    verification_type ENUM('integrity', 'restore_test', 'checksum') NOT NULL,
    test_database VARCHAR(100),
    verification_result ENUM('passed', 'failed', 'warning') NOT NULL,
    verification_details JSON,
    performed_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    performed_by BIGINT,
    FOREIGN KEY (backup_execution_id) REFERENCES mdm_backup_executions(id) ON DELETE CASCADE,
    FOREIGN KEY (performed_by) REFERENCES mdm_users(id),
    INDEX idx_backup_execution_id (backup_execution_id),
    INDEX idx_verification_result (verification_result),
    INDEX idx_performed_at (performed_at)
);

-- Insert default backup jobs
INSERT INTO mdm_backup_jobs (job_name, backup_type, schedule_cron, tables_to_backup, backup_path, retention_days) VALUES
('Daily Full Backup', 'full', '0 2 * * *', JSON_ARRAY(
    'master_products', 'sku_mapping', 'mdm_users', 'mdm_roles', 'mdm_user_roles',
    'mdm_master_products_audit', 'mdm_sku_mapping_audit', 'mdm_master_products_versions'
), '/backups/mdm/daily/', 30),
('Weekly Archive Backup', 'full', '0 1 * * 0', JSON_ARRAY(
    'master_products', 'sku_mapping', 'mdm_users', 'mdm_roles', 'mdm_user_roles',
    'mdm_master_products_audit', 'mdm_sku_mapping_audit', 'mdm_master_products_versions',
    'mdm_user_activity_log', 'data_quality_metrics'
), '/backups/mdm/weekly/', 90),
('Critical Data Backup', 'incremental', '0 */6 * * *', JSON_ARRAY(
    'master_products', 'sku_mapping'
), '/backups/mdm/critical/', 7);