-- =====================================================
-- Индексы для оптимизации производительности дашборда складских остатков
-- Создано для задачи 7: Оптимизировать производительность
-- =====================================================

-- Проверяем существование таблицы inventory_data
SELECT 'Создание индексов для inventory_data...' as status;

-- 1. Основные индексы для inventory_data
-- Индекс для быстрого поиска по SKU (основной ключ для JOIN с dim_products)
CREATE INDEX IF NOT EXISTS idx_inventory_data_sku 
ON inventory_data(sku);

-- Индекс для фильтрации по складам
CREATE INDEX IF NOT EXISTS idx_inventory_data_warehouse 
ON inventory_data(warehouse_name);

-- Индекс для фильтрации по остаткам (критически важен для классификации товаров)
CREATE INDEX IF NOT EXISTS idx_inventory_data_current_stock 
ON inventory_data(current_stock);

-- Составной индекс для основного запроса дашборда (sku + warehouse + stock)
CREATE INDEX IF NOT EXISTS idx_inventory_data_main_query 
ON inventory_data(sku, warehouse_name, current_stock);

-- Индекс для сортировки по дате последнего обновления
CREATE INDEX IF NOT EXISTS idx_inventory_data_last_sync 
ON inventory_data(last_sync_at DESC);

-- Составной индекс для агрегации по складам
CREATE INDEX IF NOT EXISTS idx_inventory_data_warehouse_aggregation 
ON inventory_data(warehouse_name, current_stock, available_stock, reserved_stock);

-- 2. Оптимизация индексов для dim_products
-- Составной индекс для быстрого поиска названий товаров по SKU
CREATE INDEX IF NOT EXISTS idx_dim_products_sku_lookup 
ON dim_products(sku_ozon, sku_wb, product_name, name);

-- Индекс для поиска по стоимости (используется для расчета стоимости остатков)
CREATE INDEX IF NOT EXISTS idx_dim_products_cost_price 
ON dim_products(cost_price);

-- 3. Индексы для sku_cross_reference (если таблица существует)
-- Проверяем существование таблицы sku_cross_reference
SET @table_exists = (
    SELECT COUNT(*)
    FROM information_schema.tables 
    WHERE table_schema = DATABASE() 
    AND table_name = 'sku_cross_reference'
);

-- Создаем индексы только если таблица существует
SET @sql = IF(@table_exists > 0,
    'CREATE INDEX IF NOT EXISTS idx_sku_cross_reference_text_sku ON sku_cross_reference(text_sku)',
    'SELECT "Таблица sku_cross_reference не найдена, пропускаем создание индексов" AS message'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql = IF(@table_exists > 0,
    'CREATE INDEX IF NOT EXISTS idx_sku_cross_reference_numeric_sku ON sku_cross_reference(numeric_sku)',
    'SELECT "Таблица sku_cross_reference не найдена, пропускаем создание индексов" AS message'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- 4. Специализированные индексы для классификации товаров
-- Частичные индексы для быстрой фильтрации критических товаров
CREATE INDEX IF NOT EXISTS idx_inventory_data_critical_stock 
ON inventory_data(sku, warehouse_name) 
WHERE current_stock <= 5;

-- Частичные индексы для товаров с низкими остатками
CREATE INDEX IF NOT EXISTS idx_inventory_data_low_stock 
ON inventory_data(sku, warehouse_name) 
WHERE current_stock > 5 AND current_stock <= 20;

-- Частичные индексы для товаров с избытком
CREATE INDEX IF NOT EXISTS idx_inventory_data_overstock 
ON inventory_data(sku, warehouse_name) 
WHERE current_stock > 100;

-- 5. Составные индексы для покрытия основных запросов
-- Покрывающий индекс для основного запроса дашборда
CREATE INDEX IF NOT EXISTS idx_inventory_data_dashboard_covering 
ON inventory_data(sku, warehouse_name, current_stock, available_stock, reserved_stock, last_sync_at);

-- Покрывающий индекс для агрегации по складам
CREATE INDEX IF NOT EXISTS idx_inventory_data_warehouse_covering 
ON inventory_data(warehouse_name, current_stock, available_stock, reserved_stock, sku);

-- 6. Анализ производительности существующих индексов
SELECT 'Анализ созданных индексов...' as status;

-- Показываем созданные индексы для inventory_data
SELECT 
    TABLE_NAME,
    INDEX_NAME,
    COLUMN_NAME,
    SEQ_IN_INDEX,
    CARDINALITY
FROM information_schema.STATISTICS 
WHERE TABLE_SCHEMA = DATABASE() 
AND TABLE_NAME = 'inventory_data'
AND INDEX_NAME LIKE 'idx_%'
ORDER BY TABLE_NAME, INDEX_NAME, SEQ_IN_INDEX;

-- Показываем созданные индексы для dim_products
SELECT 
    TABLE_NAME,
    INDEX_NAME,
    COLUMN_NAME,
    SEQ_IN_INDEX,
    CARDINALITY
FROM information_schema.STATISTICS 
WHERE TABLE_SCHEMA = DATABASE() 
AND TABLE_NAME = 'dim_products'
AND INDEX_NAME LIKE 'idx_%'
ORDER BY TABLE_NAME, INDEX_NAME, SEQ_IN_INDEX;

-- 7. Рекомендации по обслуживанию индексов
SELECT 'Индексы успешно созданы!' as status;
SELECT 'Рекомендуется периодически запускать ANALYZE TABLE для обновления статистики' as recommendation;

-- Команды для обновления статистики (запускать периодически)
-- ANALYZE TABLE inventory_data;
-- ANALYZE TABLE dim_products;
-- ANALYZE TABLE sku_cross_reference;