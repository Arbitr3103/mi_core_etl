-- ===================================================================
-- МИГРАЦИЯ СХЕМЫ БД ДЛЯ ИСПРАВЛЕНИЯ СИНХРОНИЗАЦИИ ОСТАТКОВ
-- ===================================================================
-- Дата создания: 2025-01-06
-- Описание: Обновление таблицы inventory_data и создание sync_logs
-- Требования: 1.1, 1.2, 1.3, 2.1, 2.2
-- ===================================================================

-- Начало транзакции для безопасной миграции
START TRANSACTION;

-- ===================================================================
-- 1. ОБНОВЛЕНИЕ ТАБЛИЦЫ INVENTORY_DATA
-- ===================================================================

-- Добавление новых полей в таблицу inventory_data
ALTER TABLE inventory_data 
ADD COLUMN warehouse_name VARCHAR(255) DEFAULT 'Main Warehouse' COMMENT 'Название склада',
ADD COLUMN stock_type ENUM('FBO', 'FBS', 'realFBS') DEFAULT 'FBO' COMMENT 'Тип склада маркетплейса',
ADD COLUMN quantity_present INT DEFAULT 0 COMMENT 'Количество товара в наличии',
ADD COLUMN quantity_reserved INT DEFAULT 0 COMMENT 'Зарезервированное количество',
ADD COLUMN last_sync_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT 'Время последней синхронизации';

-- Добавление новых индексов для оптимизации запросов
ALTER TABLE inventory_data 
ADD INDEX idx_source_sync (source, last_sync_at),
ADD INDEX idx_product_source (product_id, source),
ADD INDEX idx_warehouse_stock_type (warehouse_name, stock_type),
ADD INDEX idx_last_sync (last_sync_at);

-- ===================================================================
-- 2. СОЗДАНИЕ ТАБЛИЦЫ SYNC_LOGS
-- ===================================================================

CREATE TABLE IF NOT EXISTS sync_logs (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    
    -- Основная информация о синхронизации
    sync_type ENUM('inventory', 'orders', 'transactions') NOT NULL COMMENT 'Тип синхронизации',
    source ENUM('Ozon', 'Wildberries', 'Manual') NOT NULL COMMENT 'Источник данных',
    status ENUM('success', 'partial', 'failed') NOT NULL COMMENT 'Статус выполнения',
    
    -- Статистика обработки
    records_processed INT DEFAULT 0 COMMENT 'Количество обработанных записей',
    records_updated INT DEFAULT 0 COMMENT 'Количество обновленных записей',
    records_inserted INT DEFAULT 0 COMMENT 'Количество добавленных записей',
    records_failed INT DEFAULT 0 COMMENT 'Количество записей с ошибками',
    
    -- Информация об ошибках
    error_message TEXT COMMENT 'Сообщение об ошибке',
    error_code VARCHAR(50) COMMENT 'Код ошибки',
    
    -- Временные метки
    started_at TIMESTAMP NOT NULL COMMENT 'Время начала синхронизации',
    completed_at TIMESTAMP NULL COMMENT 'Время завершения синхронизации',
    duration_seconds INT GENERATED ALWAYS AS (
        CASE 
            WHEN completed_at IS NOT NULL 
            THEN TIMESTAMPDIFF(SECOND, started_at, completed_at)
            ELSE NULL 
        END
    ) STORED COMMENT 'Длительность выполнения в секундах',
    
    -- Дополнительные метаданные
    api_requests_count INT DEFAULT 0 COMMENT 'Количество API запросов',
    data_size_mb DECIMAL(10,2) DEFAULT 0 COMMENT 'Размер обработанных данных в МБ',
    
    -- Метаданные записи
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    
    -- Индексы для оптимизации запросов
    INDEX idx_sync_type_status (sync_type, status),
    INDEX idx_source_date (source, started_at),
    INDEX idx_status_date (status, started_at),
    INDEX idx_started_at (started_at),
    INDEX idx_duration (duration_seconds),
    INDEX idx_sync_type_source (sync_type, source)
    
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='Логи синхронизации данных с маркетплейсами';

-- ===================================================================
-- 3. СОЗДАНИЕ ПРЕДСТАВЛЕНИЯ ДЛЯ МОНИТОРИНГА СИНХРОНИЗАЦИИ
-- ===================================================================

CREATE OR REPLACE VIEW v_sync_monitoring AS
SELECT 
    source,
    sync_type,
    status,
    COUNT(*) as sync_count,
    MAX(started_at) as last_sync_time,
    AVG(duration_seconds) as avg_duration_seconds,
    SUM(records_processed) as total_records_processed,
    SUM(records_updated) as total_records_updated,
    SUM(records_failed) as total_records_failed,
    ROUND(
        (SUM(CASE WHEN status = 'success' THEN 1 ELSE 0 END) * 100.0 / COUNT(*)), 2
    ) as success_rate_percent
FROM sync_logs 
WHERE started_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
GROUP BY source, sync_type, status
ORDER BY source, sync_type, status;

-- ===================================================================
-- 4. СОЗДАНИЕ ФУНКЦИИ ДЛЯ ОЧИСТКИ СТАРЫХ ЛОГОВ
-- ===================================================================

DELIMITER //

CREATE PROCEDURE CleanupOldSyncLogs(IN days_to_keep INT DEFAULT 90)
BEGIN
    DECLARE deleted_count INT DEFAULT 0;
    
    -- Удаление логов старше указанного количества дней
    DELETE FROM sync_logs 
    WHERE started_at < DATE_SUB(NOW(), INTERVAL days_to_keep DAY);
    
    SET deleted_count = ROW_COUNT();
    
    -- Логирование результата очистки
    INSERT INTO sync_logs (
        sync_type, source, status, records_processed, 
        started_at, completed_at, error_message
    ) VALUES (
        'inventory', 'Manual', 'success', deleted_count,
        NOW(), NOW(), CONCAT('Cleanup completed: ', deleted_count, ' old logs removed')
    );
    
    SELECT CONCAT('Deleted ', deleted_count, ' old sync logs') as result;
END //

DELIMITER ;

-- ===================================================================
-- 5. МИГРАЦИЯ СУЩЕСТВУЮЩИХ ДАННЫХ
-- ===================================================================

-- Обновление существующих записей с новыми значениями по умолчанию
UPDATE inventory_data 
SET 
    quantity_present = current_stock,
    quantity_reserved = reserved_stock,
    last_sync_at = created_at
WHERE quantity_present IS NULL OR quantity_reserved IS NULL;

-- ===================================================================
-- 6. СОЗДАНИЕ ТРИГГЕРА ДЛЯ АВТОМАТИЧЕСКОГО ОБНОВЛЕНИЯ LAST_SYNC_AT
-- ===================================================================

DELIMITER //

CREATE TRIGGER tr_inventory_data_update_sync_time
    BEFORE UPDATE ON inventory_data
    FOR EACH ROW
BEGIN
    -- Обновляем время синхронизации только если изменились данные об остатках
    IF (NEW.current_stock != OLD.current_stock 
        OR NEW.reserved_stock != OLD.reserved_stock 
        OR NEW.available_stock != OLD.available_stock
        OR NEW.quantity_present != OLD.quantity_present
        OR NEW.quantity_reserved != OLD.quantity_reserved) THEN
        SET NEW.last_sync_at = CURRENT_TIMESTAMP;
    END IF;
END //

DELIMITER ;

-- ===================================================================
-- 7. ПРОВЕРКА ЦЕЛОСТНОСТИ МИГРАЦИИ
-- ===================================================================

-- Проверяем, что все новые поля добавлены
SELECT 
    COLUMN_NAME, 
    DATA_TYPE, 
    IS_NULLABLE, 
    COLUMN_DEFAULT,
    COLUMN_COMMENT
FROM INFORMATION_SCHEMA.COLUMNS 
WHERE TABLE_NAME = 'inventory_data' 
    AND TABLE_SCHEMA = DATABASE()
    AND COLUMN_NAME IN ('warehouse_name', 'stock_type', 'quantity_present', 'quantity_reserved', 'last_sync_at');

-- Проверяем, что таблица sync_logs создана
SELECT 
    TABLE_NAME,
    TABLE_COMMENT,
    ENGINE,
    TABLE_COLLATION
FROM INFORMATION_SCHEMA.TABLES 
WHERE TABLE_NAME = 'sync_logs' 
    AND TABLE_SCHEMA = DATABASE();

-- Проверяем индексы
SELECT 
    INDEX_NAME,
    COLUMN_NAME,
    SEQ_IN_INDEX
FROM INFORMATION_SCHEMA.STATISTICS 
WHERE TABLE_NAME IN ('inventory_data', 'sync_logs')
    AND TABLE_SCHEMA = DATABASE()
    AND INDEX_NAME IN ('idx_source_sync', 'idx_product_source', 'idx_sync_type_status', 'idx_source_date')
ORDER BY TABLE_NAME, INDEX_NAME, SEQ_IN_INDEX;

-- Фиксация транзакции
COMMIT;

-- ===================================================================
-- ЗАВЕРШЕНИЕ МИГРАЦИИ
-- ===================================================================

SELECT 'Миграция схемы БД для синхронизации остатков успешно завершена!' as status;