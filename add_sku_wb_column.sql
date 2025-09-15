-- Полная миграция для таблицы dim_products для поддержки импорта товаров WB
-- Добавляет все необходимые колонки для хранения данных товаров из разных источников

USE mi_core_db;

-- Добавляем все недостающие колонки для импорта товаров WB
ALTER TABLE dim_products 
ADD COLUMN sku_wb VARCHAR(50) NULL AFTER sku_ozon COMMENT 'nmID товара из Wildberries',
ADD COLUMN name VARCHAR(500) NULL AFTER product_name COMMENT 'Название товара (унифицированное)',
ADD COLUMN brand VARCHAR(255) NULL AFTER name COMMENT 'Бренд товара', 
ADD COLUMN category VARCHAR(255) NULL AFTER brand COMMENT 'Категория товара',
ADD INDEX idx_sku_wb (sku_wb),
ADD INDEX idx_name (name(100)),
ADD INDEX idx_brand (brand),
ADD INDEX idx_category (category);

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
