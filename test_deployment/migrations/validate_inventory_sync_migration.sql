-- ===================================================================
-- СКРИПТ ВАЛИДАЦИИ МИГРАЦИИ СИНХРОНИЗАЦИИ ОСТАТКОВ
-- ===================================================================
-- Дата создания: 2025-01-06
-- Описание: Проверка корректности применения миграции
-- ===================================================================

SELECT '=== ПРОВЕРКА МИГРАЦИИ СХЕМЫ БД ДЛЯ СИНХРОНИЗАЦИИ ОСТАТКОВ ===' as validation_step;

-- ===================================================================
-- 1. ПРОВЕРКА НОВЫХ ПОЛЕЙ В INVENTORY_DATA
-- ===================================================================

SELECT '1. Проверка новых полей в inventory_data' as validation_step;

SELECT 
    COLUMN_NAME as field_name,
    DATA_TYPE as data_type,
    IS_NULLABLE as nullable,
    COLUMN_DEFAULT as default_value,
    COLUMN_COMMENT as comment,
    CASE 
        WHEN COLUMN_NAME IN ('warehouse_name', 'stock_type', 'quantity_present', 'quantity_reserved', 'last_sync_at') 
        THEN '✓ OK' 
        ELSE '✗ MISSING' 
    END as status
FROM INFORMATION_SCHEMA.COLUMNS 
WHERE TABLE_NAME = 'inventory_data' 
    AND TABLE_SCHEMA = DATABASE()
    AND COLUMN_NAME IN ('warehouse_name', 'stock_type', 'quantity_present', 'quantity_reserved', 'last_sync_at')
ORDER BY COLUMN_NAME;

-- Подсчет найденных полей
SELECT 
    COUNT(*) as found_fields,
    CASE 
        WHEN COUNT(*) = 5 THEN '✓ Все поля добавлены' 
        ELSE CONCAT('✗ Найдено только ', COUNT(*), ' из 5 полей') 
    END as result
FROM INFORMATION_SCHEMA.COLUMNS 
WHERE TABLE_NAME = 'inventory_data' 
    AND TABLE_SCHEMA = DATABASE()
    AND COLUMN_NAME IN ('warehouse_name', 'stock_type', 'quantity_present', 'quantity_reserved', 'last_sync_at');

-- ===================================================================
-- 2. ПРОВЕРКА ТАБЛИЦЫ SYNC_LOGS
-- ===================================================================

SELECT '2. Проверка таблицы sync_logs' as validation_step;

SELECT 
    TABLE_NAME as table_name,
    ENGINE as engine,
    TABLE_COLLATION as collation,
    TABLE_COMMENT as comment,
    CASE 
        WHEN TABLE_NAME = 'sync_logs' THEN '✓ OK' 
        ELSE '✗ MISSING' 
    END as status
FROM INFORMATION_SCHEMA.TABLES 
WHERE TABLE_NAME = 'sync_logs' 
    AND TABLE_SCHEMA = DATABASE();

-- Проверка структуры таблицы sync_logs
SELECT 
    COLUMN_NAME as field_name,
    DATA_TYPE as data_type,
    IS_NULLABLE as nullable,
    COLUMN_DEFAULT as default_value
FROM INFORMATION_SCHEMA.COLUMNS 
WHERE TABLE_NAME = 'sync_logs' 
    AND TABLE_SCHEMA = DATABASE()
ORDER BY ORDINAL_POSITION;

-- ===================================================================
-- 3. ПРОВЕРКА ИНДЕКСОВ
-- ===================================================================

SELECT '3. Проверка новых индексов' as validation_step;

-- Проверка индексов inventory_data
SELECT 
    'inventory_data' as table_name,
    INDEX_NAME as index_name,
    GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX) as columns,
    CASE 
        WHEN INDEX_NAME IN ('idx_source_sync', 'idx_product_source', 'idx_warehouse_stock_type', 'idx_last_sync') 
        THEN '✓ OK' 
        ELSE '- Existing' 
    END as status
FROM INFORMATION_SCHEMA.STATISTICS 
WHERE TABLE_NAME = 'inventory_data'
    AND TABLE_SCHEMA = DATABASE()
GROUP BY INDEX_NAME
ORDER BY INDEX_NAME;

-- Проверка индексов sync_logs
SELECT 
    'sync_logs' as table_name,
    INDEX_NAME as index_name,
    GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX) as columns,
    '✓ OK' as status
FROM INFORMATION_SCHEMA.STATISTICS 
WHERE TABLE_NAME = 'sync_logs'
    AND TABLE_SCHEMA = DATABASE()
GROUP BY INDEX_NAME
ORDER BY INDEX_NAME;

-- ===================================================================
-- 4. ПРОВЕРКА ПРЕДСТАВЛЕНИЯ
-- ===================================================================

SELECT '4. Проверка представления v_sync_monitoring' as validation_step;

SELECT 
    TABLE_NAME as view_name,
    TABLE_TYPE as type,
    CASE 
        WHEN TABLE_NAME = 'v_sync_monitoring' AND TABLE_TYPE = 'VIEW' 
        THEN '✓ OK' 
        ELSE '✗ MISSING' 
    END as status
FROM INFORMATION_SCHEMA.TABLES 
WHERE TABLE_NAME = 'v_sync_monitoring' 
    AND TABLE_SCHEMA = DATABASE();

-- ===================================================================
-- 5. ПРОВЕРКА ПРОЦЕДУРЫ
-- ===================================================================

SELECT '5. Проверка процедуры CleanupOldSyncLogs' as validation_step;

SELECT 
    ROUTINE_NAME as procedure_name,
    ROUTINE_TYPE as type,
    CASE 
        WHEN ROUTINE_NAME = 'CleanupOldSyncLogs' AND ROUTINE_TYPE = 'PROCEDURE' 
        THEN '✓ OK' 
        ELSE '✗ MISSING' 
    END as status
FROM INFORMATION_SCHEMA.ROUTINES 
WHERE ROUTINE_NAME = 'CleanupOldSyncLogs' 
    AND ROUTINE_SCHEMA = DATABASE();

-- ===================================================================
-- 6. ПРОВЕРКА ТРИГГЕРА
-- ===================================================================

SELECT '6. Проверка триггера tr_inventory_data_update_sync_time' as validation_step;

SELECT 
    TRIGGER_NAME as trigger_name,
    EVENT_MANIPULATION as event_type,
    EVENT_OBJECT_TABLE as table_name,
    CASE 
        WHEN TRIGGER_NAME = 'tr_inventory_data_update_sync_time' 
        THEN '✓ OK' 
        ELSE '✗ MISSING' 
    END as status
FROM INFORMATION_SCHEMA.TRIGGERS 
WHERE TRIGGER_NAME = 'tr_inventory_data_update_sync_time' 
    AND TRIGGER_SCHEMA = DATABASE();

-- ===================================================================
-- 7. ПРОВЕРКА ДАННЫХ
-- ===================================================================

SELECT '7. Проверка миграции существующих данных' as validation_step;

-- Проверяем, что существующие записи обновлены
SELECT 
    COUNT(*) as total_records,
    COUNT(CASE WHEN warehouse_name IS NOT NULL THEN 1 END) as records_with_warehouse,
    COUNT(CASE WHEN stock_type IS NOT NULL THEN 1 END) as records_with_stock_type,
    COUNT(CASE WHEN quantity_present IS NOT NULL THEN 1 END) as records_with_quantity_present,
    COUNT(CASE WHEN last_sync_at IS NOT NULL THEN 1 END) as records_with_sync_time
FROM inventory_data;

-- ===================================================================
-- 8. ИТОГОВАЯ СВОДКА
-- ===================================================================

SELECT '=== ИТОГОВАЯ СВОДКА ВАЛИДАЦИИ ===' as validation_step;

SELECT 
    'Миграция схемы БД' as component,
    CASE 
        WHEN (
            SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS 
            WHERE TABLE_NAME = 'inventory_data' 
                AND TABLE_SCHEMA = DATABASE()
                AND COLUMN_NAME IN ('warehouse_name', 'stock_type', 'quantity_present', 'quantity_reserved', 'last_sync_at')
        ) = 5
        AND (
            SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES 
            WHERE TABLE_NAME = 'sync_logs' AND TABLE_SCHEMA = DATABASE()
        ) = 1
        AND (
            SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES 
            WHERE TABLE_NAME = 'v_sync_monitoring' AND TABLE_SCHEMA = DATABASE()
        ) = 1
        AND (
            SELECT COUNT(*) FROM INFORMATION_SCHEMA.ROUTINES 
            WHERE ROUTINE_NAME = 'CleanupOldSyncLogs' AND ROUTINE_SCHEMA = DATABASE()
        ) = 1
        AND (
            SELECT COUNT(*) FROM INFORMATION_SCHEMA.TRIGGERS 
            WHERE TRIGGER_NAME = 'tr_inventory_data_update_sync_time' AND TRIGGER_SCHEMA = DATABASE()
        ) = 1
        THEN '✓ УСПЕШНО ЗАВЕРШЕНА'
        ELSE '✗ ТРЕБУЕТ ВНИМАНИЯ'
    END as status;

SELECT 'Валидация миграции завершена!' as final_message;