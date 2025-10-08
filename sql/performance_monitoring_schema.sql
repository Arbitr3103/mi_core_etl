-- Performance Monitoring Schema for MDM System
-- This schema supports tracking system performance metrics

-- Performance metrics table
CREATE TABLE IF NOT EXISTS performance_metrics (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    operation_name VARCHAR(100) NOT NULL,
    execution_time_ms DECIMAL(10,2) NOT NULL,
    memory_usage_bytes BIGINT NOT NULL,
    peak_memory_bytes BIGINT NOT NULL,
    additional_data JSON,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    
    INDEX idx_operation_time (operation_name, created_at),
    INDEX idx_execution_time (execution_time_ms),
    INDEX idx_created_at (created_at)
);

-- System health metrics table
CREATE TABLE IF NOT EXISTS system_health_metrics (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    metric_name VARCHAR(100) NOT NULL,
    metric_value DECIMAL(10,2) NOT NULL,
    metric_unit VARCHAR(20),
    threshold_warning DECIMAL(10,2),
    threshold_critical DECIMAL(10,2),
    status ENUM('normal', 'warning', 'critical') DEFAULT 'normal',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    
    INDEX idx_metric_name_time (metric_name, created_at),
    INDEX idx_status (status)
);

-- Matching accuracy tracking table
CREATE TABLE IF NOT EXISTS matching_accuracy_log (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    operation_type ENUM('auto_match', 'manual_verification', 'bulk_import') NOT NULL,
    total_items INT NOT NULL,
    successful_matches INT NOT NULL,
    high_confidence_matches INT NOT NULL,
    medium_confidence_matches INT NOT NULL,
    low_confidence_matches INT NOT NULL,
    failed_matches INT NOT NULL,
    avg_confidence_score DECIMAL(4,3),
    processing_time_ms DECIMAL(10,2),
    source VARCHAR(50),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    
    INDEX idx_operation_type_time (operation_type, created_at),
    INDEX idx_source_time (source, created_at)
);

-- Data quality alerts table
CREATE TABLE IF NOT EXISTS data_quality_alerts (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    alert_type ENUM('completeness', 'accuracy', 'consistency', 'coverage', 'performance') NOT NULL,
    severity ENUM('info', 'warning', 'critical') NOT NULL,
    title VARCHAR(200) NOT NULL,
    description TEXT,
    metric_name VARCHAR(100),
    current_value DECIMAL(10,2),
    threshold_value DECIMAL(10,2),
    affected_records INT,
    status ENUM('active', 'acknowledged', 'resolved') DEFAULT 'active',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    acknowledged_at TIMESTAMP NULL,
    resolved_at TIMESTAMP NULL,
    
    INDEX idx_alert_type_status (alert_type, status),
    INDEX idx_severity_created (severity, created_at),
    INDEX idx_status (status)
);

-- Insert initial system health thresholds
INSERT IGNORE INTO system_health_metrics (metric_name, metric_value, metric_unit, threshold_warning, threshold_critical, status) VALUES
('cpu_usage_percent', 0, '%', 70, 90, 'normal'),
('memory_usage_percent', 0, '%', 80, 95, 'normal'),
('disk_usage_percent', 0, '%', 85, 95, 'normal'),
('database_connections', 0, 'count', 80, 100, 'normal'),
('pending_queue_size', 0, 'count', 1000, 5000, 'normal'),
('avg_response_time_ms', 0, 'ms', 1000, 3000, 'normal');