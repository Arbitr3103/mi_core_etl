-- ===================================================================
-- ROLLBACK СКРИПТ ДЛЯ МИГРАЦИИ СИНХРОНИЗАЦИИ ОСТАТКОВ
-- ===================================================================
-- Дата создания: 2025-01-06
-- Описание: Откат изменений схемы БД для синхронизации остатков
-- ===================================================================

-- Начало транзакции для безопасного отката
START TRANSACTION;

-- ===================================================================
-- 1. УДАЛЕНИЕ ТРИГГЕРА
-- ===================================================================

DROP TRIGGER IF EXISTS tr_inventory_data_update_sync_time;

-- ===================================================================
-- 2. УДАЛЕНИЕ ПРОЦЕДУРЫ
-- ===================================================================

DROP PROCEDURE IF EXISTS CleanupOldSyncLogs;

-- ===================================================================
-- 3. УДАЛЕНИЕ ПРЕДСТАВЛЕНИЯ
-- ===================================================================

DROP VIEW IF EXISTS v_sync_monitoring;

-- ===================================================================
-- 4. УДАЛЕНИЕ ТАБЛИЦЫ SYNC_LOGS
-- ===================================================================

DROP TABLE IF EXISTS sync_logs;

-- ===================================================================
-- 5. УДАЛЕНИЕ НОВЫХ ИНДЕКСОВ ИЗ INVENTORY_DATA
-- ===================================================================

ALTER TABLE inventory_data 
DROP INDEX IF EXISTS idx_source_sync,
DROP INDEX IF EXISTS idx_product_source,
DROP INDEX IF EXISTS idx_warehouse_stock_type,
DROP INDEX IF EXISTS idx_last_sync;

-- ===================================================================
-- 6. УДАЛЕНИЕ НОВЫХ ПОЛЕЙ ИЗ INVENTORY_DATA
-- ===================================================================

ALTER TABLE inventory_data 
DROP COLUMN IF EXISTS warehouse_name,
DROP COLUMN IF EXISTS stock_type,
DROP COLUMN IF EXISTS quantity_present,
DROP COLUMN IF EXISTS quantity_reserved,
DROP COLUMN IF EXISTS last_sync_at;

-- ===================================================================
-- 7. ПРОВЕРКА ОТКАТА
-- ===================================================================

-- Проверяем, что новые поля удалены
SELECT 
    COUNT(*) as remaining_new_columns
FROM INFORMATION_SCHEMA.COLUMNS 
WHERE TABLE_NAME = 'inventory_data' 
    AND TABLE_SCHEMA = DATABASE()
    AND COLUMN_NAME IN ('warehouse_name', 'stock_type', 'quantity_present', 'quantity_reserved', 'last_sync_at');

-- Проверяем, что таблица sync_logs удалена
SELECT 
    COUNT(*) as sync_logs_table_exists
FROM INFORMATION_SCHEMA.TABLES 
WHERE TABLE_NAME = 'sync_logs' 
    AND TABLE_SCHEMA = DATABASE();

-- Фиксация транзакции отката
COMMIT;

-- ===================================================================
-- ЗАВЕРШЕНИЕ ОТКАТА
-- ===================================================================

SELECT 'Откат миграции схемы БД для синхронизации остатков успешно завершен!' as status;