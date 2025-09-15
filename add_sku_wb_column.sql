-- Полная миграция для таблицы dim_products для поддержки импорта товаров WB
-- Добавляет все необходимые колонки с проверкой существования

USE mi_core_db;

-- Проверяем и добавляем колонку sku_wb если её нет
SET @col_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS 
                   WHERE TABLE_SCHEMA = 'mi_core_db' AND TABLE_NAME = 'dim_products' AND COLUMN_NAME = 'sku_wb');
SET @sql = IF(@col_exists = 0, 'ALTER TABLE dim_products ADD COLUMN sku_wb VARCHAR(50) NULL AFTER sku_ozon', 'SELECT "Column sku_wb already exists" AS message');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Проверяем и добавляем колонку name если её нет
SET @col_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS 
                   WHERE TABLE_SCHEMA = 'mi_core_db' AND TABLE_NAME = 'dim_products' AND COLUMN_NAME = 'name');
SET @sql = IF(@col_exists = 0, 'ALTER TABLE dim_products ADD COLUMN name VARCHAR(500) NULL AFTER product_name', 'SELECT "Column name already exists" AS message');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Проверяем и добавляем колонку brand если её нет
SET @col_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS 
                   WHERE TABLE_SCHEMA = 'mi_core_db' AND TABLE_NAME = 'dim_products' AND COLUMN_NAME = 'brand');
SET @sql = IF(@col_exists = 0, 'ALTER TABLE dim_products ADD COLUMN brand VARCHAR(255) NULL AFTER name', 'SELECT "Column brand already exists" AS message');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Проверяем и добавляем колонку category если её нет
SET @col_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS 
                   WHERE TABLE_SCHEMA = 'mi_core_db' AND TABLE_NAME = 'dim_products' AND COLUMN_NAME = 'category');
SET @sql = IF(@col_exists = 0, 'ALTER TABLE dim_products ADD COLUMN category VARCHAR(255) NULL AFTER brand', 'SELECT "Column category already exists" AS message');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Добавляем индексы если их нет
SET @idx_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS 
                   WHERE TABLE_SCHEMA = 'mi_core_db' AND TABLE_NAME = 'dim_products' AND INDEX_NAME = 'idx_sku_wb');
SET @sql = IF(@idx_exists = 0, 'ALTER TABLE dim_products ADD INDEX idx_sku_wb (sku_wb)', 'SELECT "Index idx_sku_wb already exists" AS message');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @idx_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS 
                   WHERE TABLE_SCHEMA = 'mi_core_db' AND TABLE_NAME = 'dim_products' AND INDEX_NAME = 'idx_name');
SET @sql = IF(@idx_exists = 0, 'ALTER TABLE dim_products ADD INDEX idx_name (name(100))', 'SELECT "Index idx_name already exists" AS message');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @idx_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS 
                   WHERE TABLE_SCHEMA = 'mi_core_db' AND TABLE_NAME = 'dim_products' AND INDEX_NAME = 'idx_brand');
SET @sql = IF(@idx_exists = 0, 'ALTER TABLE dim_products ADD INDEX idx_brand (brand)', 'SELECT "Index idx_brand already exists" AS message');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @idx_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS 
                   WHERE TABLE_SCHEMA = 'mi_core_db' AND TABLE_NAME = 'dim_products' AND INDEX_NAME = 'idx_category');
SET @sql = IF(@idx_exists = 0, 'ALTER TABLE dim_products ADD INDEX idx_category (category)', 'SELECT "Index idx_category already exists" AS message');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Копируем данные из product_name в name для существующих записей
UPDATE dim_products SET name = product_name WHERE name IS NULL AND product_name IS NOT NULL;

-- Показываем обновленную структуру таблицы
DESCRIBE dim_products;

-- Проверяем что все колонки добавлены
SELECT 
    COLUMN_NAME, 
    DATA_TYPE, 
    IS_NULLABLE, 
    COLUMN_DEFAULT,
    COLUMN_COMMENT
FROM INFORMATION_SCHEMA.COLUMNS 
WHERE TABLE_SCHEMA = 'mi_core_db' 
  AND TABLE_NAME = 'dim_products' 
  AND COLUMN_NAME IN ('sku_wb', 'name', 'brand', 'category')
ORDER BY ORDINAL_POSITION;
