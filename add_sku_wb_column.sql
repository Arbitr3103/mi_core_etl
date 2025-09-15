-- Миграция для добавления колонки sku_wb в таблицу dim_products
-- Эта колонка будет хранить nmID (артикул товара) из Wildberries

USE mi_core_db;

-- Добавляем колонку sku_wb для хранения артикула Wildberries
ALTER TABLE dim_products 
ADD COLUMN sku_wb VARCHAR(50) NULL AFTER sku_ozon,
ADD INDEX idx_sku_wb (sku_wb);

-- Показываем структуру таблицы после изменений
DESCRIBE dim_products;

-- Проверяем что колонка добавлена
SELECT 
    COLUMN_NAME, 
    DATA_TYPE, 
    IS_NULLABLE, 
    COLUMN_DEFAULT
FROM INFORMATION_SCHEMA.COLUMNS 
WHERE TABLE_SCHEMA = 'mi_core_db' 
  AND TABLE_NAME = 'dim_products' 
  AND COLUMN_NAME = 'sku_wb';
