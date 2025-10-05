-- Migration: Add token fields to ozon_api_settings table
-- Description: Adds access_token and token_expiry fields for token caching
-- Date: 2025-01-05
-- Requirements: 4.1, 4.2

-- Add token fields to ozon_api_settings table
ALTER TABLE ozon_api_settings 
ADD COLUMN access_token TEXT NULL COMMENT 'Cached access token from Ozon API' AFTER api_key_hash,
ADD COLUMN token_expiry TIMESTAMP NULL COMMENT 'When the access token expires' AFTER access_token;

-- Add index for token expiry
ALTER TABLE ozon_api_settings 
ADD INDEX idx_token_expiry (token_expiry);