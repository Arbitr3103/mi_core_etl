-- Migration: Create ozon_stock_reports table
-- Purpose: Track stock report requests and their status for Ozon warehouse reports
-- Requirements: 1.3, 2.4

CREATE TABLE ozon_stock_reports (
    id INT AUTO_INCREMENT PRIMARY KEY,
    report_code VARCHAR(255) NOT NULL UNIQUE,
    report_type ENUM('warehouse_stock') NOT NULL,
    status ENUM('REQUESTED', 'PROCESSING', 'SUCCESS', 'ERROR', 'TIMEOUT') NOT NULL,
    request_parameters JSON,
    download_url VARCHAR(500) NULL,
    file_size INT NULL,
    records_processed INT DEFAULT 0,
    error_message TEXT NULL,
    requested_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    completed_at TIMESTAMP NULL,
    processed_at TIMESTAMP NULL,
    
    -- Indexes for performance optimization
    INDEX idx_status (status),
    INDEX idx_requested_at (requested_at),
    INDEX idx_report_type (report_type),
    INDEX idx_report_code (report_code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;