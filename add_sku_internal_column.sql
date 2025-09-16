-- ===================================================================
-- МИГРАЦИЯ: Добавление поля sku_internal в dim_products
-- Описание: Добавляет колонку для внутреннего артикула клиента
-- ===================================================================

-- Проверяем существование колонки sku_internal
SET @column_exists = (
    SELECT COUNT(*)
    FROM INFORMATION_SCHEMA.COLUMNS 
    WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'dim_products'
    AND COLUMN_NAME = 'sku_internal'
);

-- Добавляем колонку sku_internal если её нет
SET @sql = IF(@column_exists = 0,
    'ALTER TABLE dim_products ADD COLUMN sku_internal VARCHAR(255) NULL AFTER sku_ozon',
    'SELECT "Колонка sku_internal уже существует" as message'
);

PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Добавляем индекс для sku_internal если его нет
SET @index_exists = (
    SELECT COUNT(*)
    FROM INFORMATION_SCHEMA.STATISTICS 
    WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'dim_products'
    AND INDEX_NAME = 'idx_sku_internal'
);

SET @sql = IF(@index_exists = 0,
    'ALTER TABLE dim_products ADD INDEX idx_sku_internal (sku_internal)',
    'SELECT "Индекс idx_sku_internal уже существует" as message'
);

PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Показываем итоговую структуру таблицы
DESCRIBE dim_products;
