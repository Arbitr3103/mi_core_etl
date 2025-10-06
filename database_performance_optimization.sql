-- ===================================================================
-- ОПТИМИЗАЦИЯ ПРОИЗВОДИТЕЛЬНОСТИ БАЗЫ ДАННЫХ ДЛЯ СИНХРОНИЗАЦИИ ОСТАТКОВ
-- ===================================================================
-- Дата создания: 2025-01-06
-- Описание: Добавление недостающих индексов и оптимизация UPSERT операций
-- Требования: 1.3, 1.4
-- ===================================================================

-- Начало транзакции для безопасной оптимизации
START TRANSACTION;

-- ===================================================================
-- 1. ДОБАВЛЕНИЕ НЕДОСТАЮЩИХ ИНДЕКСОВ ДЛЯ БЫСТРОГО ПОИСКА
-- ===================================================================

SELECT '1. Добавление оптимизированных индексов...' as optimization_step;

-- Составной индекс для основных операций поиска остатков
CREATE INDEX IF NOT EXISTS idx_inventory_data_main_search 
ON inventory_data (source, product_id, snapshot_date, warehouse_name);

-- Индекс для быстрого поиска по SKU и источнику
CREATE INDEX IF NOT EXISTS idx_inventory_data_sku_source 
ON inventory_data (sku, source);

-- Индекс для поиска товаров с остатками
CREATE INDEX IF NOT EXISTS idx_inventory_data_stock_levels 
ON inventory_data (quantity_present, quantity_reserved, source);

-- Индекс для временных запросов и мониторинга
CREATE INDEX IF NOT EXISTS idx_inventory_data_time_monitoring 
ON inventory_data (last_sync_at, source, snapshot_date);

-- Покрывающий индекс для статистических запросов
CREATE INDEX IF NOT EXISTS idx_inventory_data_stats_covering 
ON inventory_data (source, warehouse_name, stock_type, quantity_present, quantity_reserved);

-- ===================================================================
-- 2. ОПТИМИЗАЦИЯ ИНДЕКСОВ ДЛЯ SYNC_LOGS
-- ===================================================================

SELECT '2. Оптимизация индексов для sync_logs...' as optimization_step;

-- Составной индекс для мониторинга производительности
CREATE INDEX IF NOT EXISTS idx_sync_logs_performance_monitoring 
ON sync_logs (sync_type, source, status, started_at, duration_seconds);

-- Индекс для поиска последних синхронизаций
CREATE INDEX IF NOT EXISTS idx_sync_logs_recent_syncs 
ON sync_logs (source, sync_type, completed_at DESC);

-- Покрывающий индекс для отчетов
CREATE INDEX IF NOT EXISTS idx_sync_logs_reporting_covering 
ON sync_logs (started_at, source, sync_type, status, records_processed, records_updated, records_failed);

-- ===================================================================
-- 3. ОПТИМИЗАЦИЯ ИНДЕКСОВ ДЛЯ DIM_PRODUCTS
-- ===================================================================

SELECT '3. Оптимизация индексов для dim_products...' as optimization_step;

-- Составной индекс для поиска по SKU маркетплейсов
CREATE INDEX IF NOT EXISTS idx_dim_products_marketplace_skus 
ON dim_products (sku_ozon, sku_wb);

-- Индекс для поиска по штрихкоду
CREATE INDEX IF NOT EXISTS idx_dim_products_barcode 
ON dim_products (barcode);

-- Покрывающий индекс для основных запросов остатков
CREATE INDEX IF NOT EXISTS idx_dim_products_inventory_covering 
ON dim_products (id, product_name, sku_ozon, sku_wb, barcode, cost_price);

-- ===================================================================
-- 4. СОЗДАНИЕ ОПТИМИЗИРОВАННЫХ ХРАНИМЫХ ПРОЦЕДУР ДЛЯ UPSERT
-- ===================================================================

SELECT '4. Создание оптимизированных процедур для UPSERT...' as optimization_step;

DELIMITER //

-- Процедура для пакетного UPSERT остатков
CREATE PROCEDURE BatchUpsertInventoryData(
    IN p_source VARCHAR(50),
    IN p_snapshot_date DATE,
    IN p_batch_size INT DEFAULT 1000
)
BEGIN
    DECLARE done INT DEFAULT FALSE;
    DECLARE v_product_id INT;
    DECLARE v_sku VARCHAR(255);
    DECLARE v_warehouse_name VARCHAR(255);
    DECLARE v_stock_type VARCHAR(20);
    DECLARE v_quantity_present INT;
    DECLARE v_quantity_reserved INT;
    
    -- Объявляем курсор для обработки данных батчами
    DECLARE batch_cursor CURSOR FOR 
        SELECT product_id, sku, warehouse_name, stock_type, quantity_present, quantity_reserved
        FROM temp_inventory_batch;
    
    DECLARE CONTINUE HANDLER FOR NOT FOUND SET done = TRUE;
    
    -- Начинаем транзакцию для батча
    START TRANSACTION;
    
    -- Создаем временную таблицу для батча если не существует
    CREATE TEMPORARY TABLE IF NOT EXISTS temp_inventory_batch (
        product_id INT,
        sku VARCHAR(255),
        warehouse_name VARCHAR(255),
        stock_type VARCHAR(20),
        quantity_present INT,
        quantity_reserved INT,
        INDEX idx_temp_product_sku (product_id, sku)
    );
    
    -- Оптимизированный UPSERT с использованием ON DUPLICATE KEY UPDATE
    INSERT INTO inventory_data (
        product_id, sku, source, warehouse_name, stock_type,
        snapshot_date, current_stock, reserved_stock, available_stock,
        quantity_present, quantity_reserved, last_sync_at
    )
    SELECT 
        product_id, sku, p_source, warehouse_name, stock_type,
        p_snapshot_date, quantity_present, quantity_reserved, 
        GREATEST(0, quantity_present - quantity_reserved),
        quantity_present, quantity_reserved, NOW()
    FROM temp_inventory_batch
    ON DUPLICATE KEY UPDATE
        warehouse_name = VALUES(warehouse_name),
        stock_type = VALUES(stock_type),
        current_stock = VALUES(current_stock),
        reserved_stock = VALUES(reserved_stock),
        available_stock = VALUES(available_stock),
        quantity_present = VALUES(quantity_present),
        quantity_reserved = VALUES(quantity_reserved),
        last_sync_at = NOW();
    
    -- Фиксируем транзакцию
    COMMIT;
    
    -- Очищаем временную таблицу
    DELETE FROM temp_inventory_batch;
    
END //

-- Процедура для быстрой очистки старых данных
CREATE PROCEDURE CleanupOldInventoryData(
    IN p_source VARCHAR(50),
    IN p_days_to_keep INT DEFAULT 30
)
BEGIN
    DECLARE deleted_count INT DEFAULT 0;
    
    -- Удаляем старые данные батчами для избежания блокировок
    DELETE FROM inventory_data 
    WHERE source = p_source 
        AND snapshot_date < DATE_SUB(CURDATE(), INTERVAL p_days_to_keep DAY)
    LIMIT 10000;
    
    SET deleted_count = ROW_COUNT();
    
    -- Повторяем пока есть что удалять
    WHILE deleted_count > 0 DO
        DELETE FROM inventory_data 
        WHERE source = p_source 
            AND snapshot_date < DATE_SUB(CURDATE(), INTERVAL p_days_to_keep DAY)
        LIMIT 10000;
        
        SET deleted_count = ROW_COUNT();
    END WHILE;
    
END //

-- Процедура для оптимизированного поиска товаров по SKU
CREATE PROCEDURE FindProductBySKU(
    IN p_sku VARCHAR(255),
    IN p_source VARCHAR(50),
    OUT p_product_id INT
)
BEGIN
    SET p_product_id = NULL;
    
    -- Оптимизированный поиск с использованием индексов
    IF p_source = 'Ozon' THEN
        SELECT id INTO p_product_id 
        FROM dim_products 
        WHERE sku_ozon = p_sku 
        LIMIT 1;
    ELSEIF p_source = 'Wildberries' THEN
        SELECT id INTO p_product_id 
        FROM dim_products 
        WHERE sku_wb = p_sku 
        LIMIT 1;
    END IF;
    
    -- Если не найден по специфичному SKU, ищем по общему
    IF p_product_id IS NULL THEN
        SELECT id INTO p_product_id 
        FROM dim_products 
        WHERE barcode = p_sku 
        LIMIT 1;
    END IF;
    
END //

DELIMITER ;

-- ===================================================================
-- 5. СОЗДАНИЕ ОПТИМИЗИРОВАННЫХ ПРЕДСТАВЛЕНИЙ
-- ===================================================================

SELECT '5. Создание оптимизированных представлений...' as optimization_step;

-- Представление для быстрого доступа к актуальным остаткам
CREATE OR REPLACE VIEW v_current_inventory_optimized AS
SELECT 
    i.product_id,
    dp.product_name,
    CASE 
        WHEN i.source = 'Ozon' THEN dp.sku_ozon
        WHEN i.source = 'Wildberries' THEN dp.sku_wb
        ELSE i.sku
    END as display_sku,
    i.source,
    i.warehouse_name,
    i.stock_type,
    i.quantity_present,
    i.quantity_reserved,
    (i.quantity_present - i.quantity_reserved) as available_quantity,
    i.last_sync_at,
    CASE 
        WHEN i.quantity_present <= 5 THEN 'critical'
        WHEN i.quantity_present <= 20 THEN 'low'
        WHEN i.quantity_present <= 50 THEN 'medium'
        ELSE 'good'
    END as stock_level
FROM inventory_data i
FORCE INDEX (idx_inventory_data_main_search)
INNER JOIN dim_products dp ON i.product_id = dp.id
WHERE i.snapshot_date = CURDATE() 
    AND i.quantity_present > 0;

-- Материализованное представление для статистики (эмуляция через таблицу)
CREATE TABLE IF NOT EXISTS inventory_stats_cache (
    source VARCHAR(50),
    warehouse_name VARCHAR(255),
    stock_type VARCHAR(20),
    total_products INT,
    total_quantity_present INT,
    total_quantity_reserved INT,
    total_available INT,
    critical_items INT,
    low_stock_items INT,
    last_updated TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (source, warehouse_name, stock_type),
    INDEX idx_stats_cache_updated (last_updated)
) ENGINE=InnoDB;

-- ===================================================================
-- 6. СОЗДАНИЕ ПРОЦЕДУРЫ ОБНОВЛЕНИЯ КЭША СТАТИСТИКИ
-- ===================================================================

DELIMITER //

CREATE PROCEDURE RefreshInventoryStatsCache()
BEGIN
    -- Очищаем старый кэш
    TRUNCATE TABLE inventory_stats_cache;
    
    -- Заполняем новыми данными
    INSERT INTO inventory_stats_cache (
        source, warehouse_name, stock_type, total_products,
        total_quantity_present, total_quantity_reserved, total_available,
        critical_items, low_stock_items
    )
    SELECT 
        i.source,
        i.warehouse_name,
        i.stock_type,
        COUNT(DISTINCT i.product_id) as total_products,
        SUM(i.quantity_present) as total_quantity_present,
        SUM(i.quantity_reserved) as total_quantity_reserved,
        SUM(i.quantity_present - i.quantity_reserved) as total_available,
        SUM(CASE WHEN i.quantity_present <= 5 THEN 1 ELSE 0 END) as critical_items,
        SUM(CASE WHEN i.quantity_present <= 20 THEN 1 ELSE 0 END) as low_stock_items
    FROM inventory_data i
    WHERE i.snapshot_date = CURDATE()
        AND i.quantity_present > 0
    GROUP BY i.source, i.warehouse_name, i.stock_type;
    
END //

DELIMITER ;

-- ===================================================================
-- 7. НАСТРОЙКА АВТОМАТИЧЕСКОГО ОБНОВЛЕНИЯ КЭША
-- ===================================================================

-- Создаем событие для автоматического обновления кэша каждый час
CREATE EVENT IF NOT EXISTS ev_refresh_inventory_stats
ON SCHEDULE EVERY 1 HOUR
STARTS CURRENT_TIMESTAMP
DO
    CALL RefreshInventoryStatsCache();

-- ===================================================================
-- 8. АНАЛИЗ ПРОИЗВОДИТЕЛЬНОСТИ ЗАПРОСОВ
-- ===================================================================

SELECT '6. Создание процедуры анализа производительности...' as optimization_step;

DELIMITER //

CREATE PROCEDURE AnalyzeInventoryQueryPerformance()
BEGIN
    -- Анализ использования индексов
    SELECT 
        'Index Usage Analysis' as analysis_type,
        TABLE_NAME,
        INDEX_NAME,
        CARDINALITY,
        CASE 
            WHEN CARDINALITY > 1000 THEN 'Good'
            WHEN CARDINALITY > 100 THEN 'Moderate'
            ELSE 'Low'
        END as index_effectiveness
    FROM INFORMATION_SCHEMA.STATISTICS 
    WHERE TABLE_SCHEMA = DATABASE()
        AND TABLE_NAME IN ('inventory_data', 'sync_logs', 'dim_products')
        AND INDEX_NAME != 'PRIMARY'
    ORDER BY TABLE_NAME, CARDINALITY DESC;
    
    -- Анализ размера таблиц
    SELECT 
        'Table Size Analysis' as analysis_type,
        TABLE_NAME,
        TABLE_ROWS,
        ROUND(((DATA_LENGTH + INDEX_LENGTH) / 1024 / 1024), 2) as size_mb,
        ROUND((INDEX_LENGTH / 1024 / 1024), 2) as index_size_mb
    FROM INFORMATION_SCHEMA.TABLES 
    WHERE TABLE_SCHEMA = DATABASE()
        AND TABLE_NAME IN ('inventory_data', 'sync_logs', 'dim_products')
    ORDER BY size_mb DESC;
    
END //

DELIMITER ;

-- ===================================================================
-- 9. ПРОВЕРКА ОПТИМИЗАЦИИ
-- ===================================================================

SELECT '7. Проверка применения оптимизаций...' as optimization_step;

-- Проверяем созданные индексы
SELECT 
    TABLE_NAME,
    INDEX_NAME,
    GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX) as columns,
    CARDINALITY
FROM INFORMATION_SCHEMA.STATISTICS 
WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME IN ('inventory_data', 'sync_logs', 'dim_products')
    AND INDEX_NAME LIKE 'idx_%'
GROUP BY TABLE_NAME, INDEX_NAME
ORDER BY TABLE_NAME, INDEX_NAME;

-- Проверяем созданные процедуры
SELECT 
    ROUTINE_NAME,
    ROUTINE_TYPE,
    CREATED,
    LAST_ALTERED
FROM INFORMATION_SCHEMA.ROUTINES 
WHERE ROUTINE_SCHEMA = DATABASE()
    AND ROUTINE_NAME IN (
        'BatchUpsertInventoryData', 
        'CleanupOldInventoryData', 
        'FindProductBySKU',
        'RefreshInventoryStatsCache',
        'AnalyzeInventoryQueryPerformance'
    );

-- Фиксация транзакции
COMMIT;

-- ===================================================================
-- ЗАВЕРШЕНИЕ ОПТИМИЗАЦИИ
-- ===================================================================

SELECT 'Оптимизация производительности базы данных успешно завершена!' as status;
SELECT 'Рекомендуется запустить ANALYZE TABLE для обновления статистики индексов' as recommendation;