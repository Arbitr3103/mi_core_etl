-- Migration: Create stock_report_logs table
-- Purpose: Log all activities related to stock report processing
-- Requirements: 2.5, 5.5

CREATE TABLE stock_report_logs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    report_code VARCHAR(255) NOT NULL,
    log_level ENUM('INFO', 'WARNING', 'ERROR') NOT NULL,
    message TEXT NOT NULL,
    context JSON NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    
    -- Indexes for efficient log querying and filtering
    INDEX idx_report_code (report_code),
    INDEX idx_log_level (log_level),
    INDEX idx_created_at (created_at),
    INDEX idx_log_level_created (log_level, created_at),
    
    -- Foreign key constraint to reports table
    FOREIGN KEY (report_code) REFERENCES ozon_stock_reports(report_code) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;