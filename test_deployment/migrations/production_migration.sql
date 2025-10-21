-- Production Migration Script for Inventory Sync System
-- Version: 1.0
-- Date: 2025-01-06
-- Description: Safe migration to update inventory_data table and add sync_logs table

-- Start transaction for rollback capability
START TRANSACTION;

-- Create backup tables before making changes
CREATE TABLE IF NOT EXISTS inventory_data_backup_20250106 AS SELECT * FROM inventory_data;

-- Log migration start
INSERT INTO migration_log (migration_name, status, started_at) 
VALUES ('inventory_sync_production_migration_v1.0', 'started', NOW())
ON DUPLICATE KEY UPDATE started_at = NOW(), status = 'started';

-- Check if migration has already been applied
SET @migration_applied = (
    SELECT COUNT(*) 
    FROM information_schema.columns 
    WHERE table_schema = DATABASE() 
    AND table_name = 'inventory_data' 
    AND column_name = 'warehouse_name'
);

-- Only proceed if migration hasn't been applied
IF @migration_applied = 0 THEN

    -- Add new columns to inventory_data table
    ALTER TABLE inventory_data 
    ADD COLUMN warehouse_name VARCHAR(255) DEFAULT 'Main Warehouse' COMMENT 'Название склада',
    ADD COLUMN stock_type ENUM('FBO', 'FBS', 'realFBS', 'WB') DEFAULT 'FBO' COMMENT 'Тип склада/фулфилмента',
    ADD COLUMN quantity_present INT DEFAULT 0 COMMENT 'Количество товара в наличии',
    ADD COLUMN quantity_reserved INT DEFAULT 0 COMMENT 'Зарезервированное количество',
    ADD COLUMN last_sync_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT 'Время последней синхронизации';

    -- Add indexes for performance optimization
    ALTER TABLE inventory_data 
    ADD INDEX idx_source_sync (source, last_sync_at),
    ADD INDEX idx_product_source (product_id, source),
    ADD INDEX idx_warehouse_stock (warehouse_name, stock_type),
    ADD INDEX idx_sync_date (last_sync_at);

    -- Create sync_logs table for tracking synchronization
    CREATE TABLE IF NOT EXISTS sync_logs (
        id INT AUTO_INCREMENT PRIMARY KEY,
        sync_type ENUM('inventory', 'orders', 'transactions') NOT NULL COMMENT 'Тип синхронизации',
        source ENUM('Ozon', 'Wildberries', 'Manual', 'System') NOT NULL COMMENT 'Источник данных',
        status ENUM('success', 'partial', 'failed', 'running') NOT NULL COMMENT 'Статус выполнения',
        records_processed INT DEFAULT 0 COMMENT 'Количество обработанных записей',
        records_updated INT DEFAULT 0 COMMENT 'Количество обновленных записей',
        records_inserted INT DEFAULT 0 COMMENT 'Количество добавленных записей',
        records_failed INT DEFAULT 0 COMMENT 'Количество записей с ошибками',
        error_message TEXT COMMENT 'Сообщение об ошибке',
        error_details JSON COMMENT 'Детали ошибки в JSON формате',
        started_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'Время начала синхронизации',
        completed_at TIMESTAMP NULL COMMENT 'Время завершения синхронизации',
        duration_seconds INT GENERATED ALWAYS AS (
            CASE 
                WHEN completed_at IS NOT NULL 
                THEN TIMESTAMPDIFF(SECOND, started_at, completed_at)
                ELSE NULL 
            END
        ) STORED COMMENT 'Длительность выполнения в секундах',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        
        INDEX idx_sync_type_status (sync_type, status),
        INDEX idx_source_date (source, started_at),
        INDEX idx_status_date (status, started_at),
        INDEX idx_duration (duration_seconds),
        INDEX idx_created_date (created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci 
    COMMENT='Логи синхронизации данных с маркетплейсами';

    -- Create migration_log table if it doesn't exist
    CREATE TABLE IF NOT EXISTS migration_log (
        id INT AUTO_INCREMENT PRIMARY KEY,
        migration_name VARCHAR(255) UNIQUE NOT NULL,
        status ENUM('started', 'completed', 'failed', 'rolled_back') NOT NULL,
        started_at TIMESTAMP NULL,
        completed_at TIMESTAMP NULL,
        error_message TEXT,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

    -- Migrate existing data to new structure
    UPDATE inventory_data 
    SET 
        warehouse_name = CASE 
            WHEN source = 'Ozon' THEN 'Ozon Warehouse'
            WHEN source = 'Wildberries' THEN 'WB Warehouse'
            ELSE 'Main Warehouse'
        END,
        stock_type = CASE 
            WHEN source = 'Ozon' THEN 'FBO'
            WHEN source = 'Wildberries' THEN 'WB'
            ELSE 'FBO'
        END,
        quantity_present = COALESCE(current_stock, 0),
        quantity_reserved = 0,
        last_sync_at = COALESCE(snapshot_date, NOW())
    WHERE warehouse_name IS NULL;

    -- Create initial sync log entry
    INSERT INTO sync_logs (sync_type, source, status, records_processed, started_at, completed_at)
    VALUES ('inventory', 'System', 'success', 
            (SELECT COUNT(*) FROM inventory_data), 
            NOW(), NOW());

    -- Update migration status
    UPDATE migration_log 
    SET status = 'completed', completed_at = NOW() 
    WHERE migration_name = 'inventory_sync_production_migration_v1.0';

    SELECT 'Migration completed successfully' as result;

ELSE
    -- Migration already applied
    UPDATE migration_log 
    SET status = 'completed', completed_at = NOW() 
    WHERE migration_name = 'inventory_sync_production_migration_v1.0';
    
    SELECT 'Migration already applied' as result;

END IF;

-- Commit transaction
COMMIT;

-- Verify migration results
SELECT 
    'inventory_data' as table_name,
    COUNT(*) as total_records,
    COUNT(CASE WHEN warehouse_name IS NOT NULL THEN 1 END) as records_with_warehouse,
    COUNT(CASE WHEN last_sync_at IS NOT NULL THEN 1 END) as records_with_sync_time
FROM inventory_data

UNION ALL

SELECT 
    'sync_logs' as table_name,
    COUNT(*) as total_records,
    COUNT(CASE WHEN status = 'success' THEN 1 END) as successful_syncs,
    COUNT(CASE WHEN status = 'failed' THEN 1 END) as failed_syncs
FROM sync_logs;

-- Show migration status
SELECT * FROM migration_log WHERE migration_name = 'inventory_sync_production_migration_v1.0';