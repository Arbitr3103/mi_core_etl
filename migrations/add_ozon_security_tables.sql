-- Migration: Add Ozon Analytics Security Tables
-- Description: Creates tables for access control, audit logging, and rate limiting
-- Version: 1.0
-- Date: 2025-01-05

-- Create access log table for audit trail
CREATE TABLE IF NOT EXISTS ozon_access_log (
    id INT PRIMARY KEY AUTO_INCREMENT,
    event_type VARCHAR(50) NOT NULL COMMENT 'Type of security event',
    user_id VARCHAR(100) NOT NULL COMMENT 'User identifier',
    operation VARCHAR(100) NOT NULL COMMENT 'Operation being performed',
    ip_address VARCHAR(45) COMMENT 'Client IP address',
    user_agent TEXT COMMENT 'Client user agent string',
    context_data JSON COMMENT 'Additional request context',
    details JSON COMMENT 'Event-specific details',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    
    INDEX idx_user_operation (user_id, operation),
    INDEX idx_event_type (event_type),
    INDEX idx_created_at (created_at),
    INDEX idx_user_time (user_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='Audit log for Ozon Analytics security events';

-- Create user access control table
CREATE TABLE IF NOT EXISTS ozon_user_access (
    user_id VARCHAR(100) PRIMARY KEY COMMENT 'User identifier',
    access_level INT NOT NULL DEFAULT 0 COMMENT 'Access level: 0=None, 1=Read, 2=Export, 3=Admin',
    granted_by VARCHAR(100) COMMENT 'User who granted the access',
    granted_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP COMMENT 'When access was granted',
    is_active BOOLEAN DEFAULT TRUE COMMENT 'Whether access is currently active',
    notes TEXT COMMENT 'Additional notes about access grant',
    
    INDEX idx_access_level (access_level),
    INDEX idx_granted_by (granted_by),
    INDEX idx_is_active (is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='User access levels for Ozon Analytics';

-- Create rate limiting counters table
CREATE TABLE IF NOT EXISTS ozon_rate_limit_counters (
    user_id VARCHAR(100) NOT NULL COMMENT 'User identifier',
    operation VARCHAR(100) NOT NULL COMMENT 'Operation being rate limited',
    request_count INT DEFAULT 0 COMMENT 'Number of requests in current window',
    window_start TIMESTAMP NOT NULL COMMENT 'Start of current rate limit window',
    last_request TIMESTAMP DEFAULT CURRENT_TIMESTAMP COMMENT 'Time of last request',
    
    PRIMARY KEY (user_id, operation, window_start),
    INDEX idx_window_start (window_start),
    INDEX idx_last_request (last_request)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='Rate limiting counters for Ozon Analytics operations';

-- Create security configuration table
CREATE TABLE IF NOT EXISTS ozon_security_config (
    config_key VARCHAR(100) PRIMARY KEY COMMENT 'Configuration key',
    config_value JSON NOT NULL COMMENT 'Configuration value',
    description TEXT COMMENT 'Description of configuration',
    updated_by VARCHAR(100) COMMENT 'User who last updated',
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    INDEX idx_updated_at (updated_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='Security configuration for Ozon Analytics';

-- Insert default security configuration
INSERT INTO ozon_security_config (config_key, config_value, description) VALUES
('rate_limits', JSON_OBJECT(
    'view_funnel', 100,
    'view_demographics', 100,
    'view_campaigns', 100,
    'export_data', 10,
    'manage_settings', 50,
    'clear_cache', 50
), 'Default rate limits per hour for different operations'),

('access_levels', JSON_OBJECT(
    'NONE', 0,
    'READ', 1,
    'EXPORT', 2,
    'ADMIN', 3
), 'Access level definitions'),

('security_settings', JSON_OBJECT(
    'require_authentication', true,
    'enable_rate_limiting', true,
    'log_all_requests', true,
    'session_timeout', 3600,
    'max_concurrent_sessions', 5,
    'cleanup_logs_after_days', 90
), 'General security settings')

ON DUPLICATE KEY UPDATE 
    config_value = VALUES(config_value),
    updated_at = CURRENT_TIMESTAMP;

-- Create default admin user (should be updated with real user ID)
INSERT INTO ozon_user_access (user_id, access_level, granted_by, notes) VALUES
('admin', 3, 'system', 'Default admin user created during migration')
ON DUPLICATE KEY UPDATE 
    access_level = GREATEST(access_level, 3),
    notes = CONCAT(IFNULL(notes, ''), ' | Updated during migration');

-- Create indexes for performance optimization
CREATE INDEX IF NOT EXISTS idx_ozon_access_log_user_event 
ON ozon_access_log (user_id, event_type, created_at);

CREATE INDEX IF NOT EXISTS idx_ozon_access_log_operation_time 
ON ozon_access_log (operation, created_at);

-- Create view for security statistics
CREATE OR REPLACE VIEW v_ozon_security_stats AS
SELECT 
    DATE(created_at) as log_date,
    event_type,
    operation,
    COUNT(*) as event_count,
    COUNT(DISTINCT user_id) as unique_users,
    COUNT(DISTINCT ip_address) as unique_ips
FROM ozon_access_log 
WHERE created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
GROUP BY DATE(created_at), event_type, operation
ORDER BY log_date DESC, event_count DESC;

-- Create view for user activity summary
CREATE OR REPLACE VIEW v_ozon_user_activity AS
SELECT 
    ual.user_id,
    uua.access_level,
    COUNT(*) as total_requests,
    COUNT(CASE WHEN ual.event_type = 'ACCESS_GRANTED' THEN 1 END) as successful_requests,
    COUNT(CASE WHEN ual.event_type = 'ACCESS_DENIED' THEN 1 END) as denied_requests,
    COUNT(CASE WHEN ual.event_type = 'RATE_LIMITED' THEN 1 END) as rate_limited_requests,
    MAX(ual.created_at) as last_activity,
    COUNT(DISTINCT ual.ip_address) as unique_ips
FROM ozon_access_log ual
LEFT JOIN ozon_user_access uua ON ual.user_id = uua.user_id
WHERE ual.created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
GROUP BY ual.user_id, uua.access_level
ORDER BY total_requests DESC;

-- Create stored procedure for cleanup
DELIMITER //

CREATE OR REPLACE PROCEDURE CleanupOzonSecurityLogs(IN days_to_keep INT)
BEGIN
    DECLARE deleted_logs INT DEFAULT 0;
    DECLARE deleted_counters INT DEFAULT 0;
    
    -- Clean up old access logs
    DELETE FROM ozon_access_log 
    WHERE created_at < DATE_SUB(NOW(), INTERVAL days_to_keep DAY);
    
    SET deleted_logs = ROW_COUNT();
    
    -- Clean up old rate limit counters (keep only last 7 days)
    DELETE FROM ozon_rate_limit_counters 
    WHERE window_start < DATE_SUB(NOW(), INTERVAL 7 DAY);
    
    SET deleted_counters = ROW_COUNT();
    
    -- Log cleanup operation
    INSERT INTO ozon_access_log (event_type, user_id, operation, details)
    VALUES ('SECURITY_CLEANUP', 'system', 'cleanup', JSON_OBJECT(
        'deleted_logs', deleted_logs,
        'deleted_counters', deleted_counters,
        'days_kept', days_to_keep
    ));
    
    SELECT deleted_logs as deleted_access_logs, deleted_counters as deleted_rate_counters;
END //

DELIMITER ;

-- Create event for automatic cleanup (runs daily at 2 AM)
SET GLOBAL event_scheduler = ON;

CREATE EVENT IF NOT EXISTS ev_cleanup_ozon_security
ON SCHEDULE EVERY 1 DAY
STARTS TIMESTAMP(CURRENT_DATE + INTERVAL 1 DAY, '02:00:00')
DO
BEGIN
    -- Get cleanup setting from config
    DECLARE cleanup_days INT DEFAULT 90;
    
    SELECT JSON_EXTRACT(config_value, '$.cleanup_logs_after_days') INTO cleanup_days
    FROM ozon_security_config 
    WHERE config_key = 'security_settings';
    
    -- Run cleanup
    CALL CleanupOzonSecurityLogs(cleanup_days);
END;

-- Grant necessary permissions (adjust user as needed)
-- GRANT SELECT, INSERT, UPDATE, DELETE ON ozon_access_log TO 'mi_core_user'@'localhost';
-- GRANT SELECT, INSERT, UPDATE, DELETE ON ozon_user_access TO 'mi_core_user'@'localhost';
-- GRANT SELECT, INSERT, UPDATE, DELETE ON ozon_rate_limit_counters TO 'mi_core_user'@'localhost';
-- GRANT SELECT ON ozon_security_config TO 'mi_core_user'@'localhost';

-- Migration completed successfully
SELECT 'Ozon Analytics Security Tables Migration Completed' as status;