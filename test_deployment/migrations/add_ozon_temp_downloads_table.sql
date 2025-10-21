-- Migration: Add temporary downloads table for Ozon analytics export functionality
-- Created: 2025-01-05
-- Description: Creates table to store temporary download links for exported data

-- Create temporary downloads table
CREATE TABLE IF NOT EXISTS ozon_temp_downloads (
    id INT PRIMARY KEY AUTO_INCREMENT,
    token VARCHAR(64) NOT NULL UNIQUE,
    filename VARCHAR(255) NOT NULL,
    filepath VARCHAR(500) NOT NULL,
    content_type VARCHAR(100) DEFAULT 'text/csv',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    expires_at TIMESTAMP NOT NULL,
    downloaded_at TIMESTAMP NULL,
    download_count INT DEFAULT 0,
    INDEX idx_token (token),
    INDEX idx_expires_at (expires_at),
    INDEX idx_created_at (created_at)
);

-- Add comment to table
ALTER TABLE ozon_temp_downloads COMMENT = 'Temporary download links for Ozon analytics data export';