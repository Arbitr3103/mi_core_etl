-- Rollback Script for Inventory Sync Production Migration
-- Version: 1.0
-- Date: 2025-01-06
-- Description: Safely rollback inventory sync migration changes

-- Start transaction for safety
START TRANSACTION;

-- Log rollback start
INSERT INTO migration_log (migration_name, status, started_at) 
VALUES ('inventory_sync_rollback_v1.0', 'started', NOW())
ON DUPLICATE KEY UPDATE started_at = NOW(), status = 'started';

-- Check if backup table exists
SET @backup_exists = (
    SELECT COUNT(*) 
    FROM information_schema.tables 
    WHERE table_schema = DATABASE() 
    AND table_name = 'inventory_data_backup_20250106'
);

-- Check if migration was applied
SET @migration_applied = (
    SELECT COUNT(*) 
    FROM information_schema.columns 
    WHERE table_schema = DATABASE() 
    AND table_name = 'inventory_data' 
    AND column_name = 'warehouse_name'
);

-- Only proceed with rollback if migration was applied
IF @migration_applied > 0 THEN

    -- Create current state backup before rollback
    CREATE TABLE IF NOT EXISTS inventory_data_pre_rollback_20250106 AS SELECT * FROM inventory_data;

    -- If backup exists, restore from it
    IF @backup_exists > 0 THEN
        
        -- Drop current table
        DROP TABLE inventory_data;
        
        -- Restore from backup
        CREATE TABLE inventory_data AS SELECT * FROM inventory_data_backup_20250106;
        
        -- Recreate original indexes
        ALTER TABLE inventory_data 
        ADD PRIMARY KEY (id),
        ADD INDEX idx_product_id (product_id),
        ADD INDEX idx_source (source),
        ADD INDEX idx_snapshot_date (snapshot_date);
        
        SELECT 'Restored inventory_data from backup' as rollback_step;
        
    ELSE
        
        -- Manual rollback - remove added columns
        ALTER TABLE inventory_data 
        DROP COLUMN IF EXISTS warehouse_name,
        DROP COLUMN IF EXISTS stock_type,
        DROP COLUMN IF EXISTS quantity_present,
        DROP COLUMN IF EXISTS quantity_reserved,
        DROP COLUMN IF EXISTS last_sync_at,
        DROP INDEX IF EXISTS idx_source_sync,
        DROP INDEX IF EXISTS idx_product_source,
        DROP INDEX IF EXISTS idx_warehouse_stock,
        DROP INDEX IF EXISTS idx_sync_date;
        
        SELECT 'Removed added columns from inventory_data' as rollback_step;
        
    END IF;

    -- Rename sync_logs table instead of dropping (preserve data)
    SET @sync_logs_exists = (
        SELECT COUNT(*) 
        FROM information_schema.tables 
        WHERE table_schema = DATABASE() 
        AND table_name = 'sync_logs'
    );
    
    IF @sync_logs_exists > 0 THEN
        RENAME TABLE sync_logs TO sync_logs_backup_20250106;
        SELECT 'Renamed sync_logs to sync_logs_backup_20250106' as rollback_step;
    END IF;

    -- Update migration log
    UPDATE migration_log 
    SET status = 'rolled_back', completed_at = NOW() 
    WHERE migration_name = 'inventory_sync_production_migration_v1.0';

    -- Log rollback completion
    UPDATE migration_log 
    SET status = 'completed', completed_at = NOW() 
    WHERE migration_name = 'inventory_sync_rollback_v1.0';

    SELECT 'Rollback completed successfully' as result;

ELSE
    
    -- Migration was not applied, nothing to rollback
    UPDATE migration_log 
    SET status = 'completed', completed_at = NOW() 
    WHERE migration_name = 'inventory_sync_rollback_v1.0';
    
    SELECT 'No migration to rollback' as result;

END IF;

-- Commit transaction
COMMIT;

-- Verify rollback results
SELECT 
    table_name,
    column_name
FROM information_schema.columns 
WHERE table_schema = DATABASE() 
AND table_name = 'inventory_data'
ORDER BY ordinal_position;

-- Show rollback status
SELECT * FROM migration_log 
WHERE migration_name IN ('inventory_sync_production_migration_v1.0', 'inventory_sync_rollback_v1.0')
ORDER BY created_at DESC;