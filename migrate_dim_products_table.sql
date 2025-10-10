-- Миграционный скрипт для безопасного изменения таблицы dim_products
-- Исправляет проблему несовместимости типов данных (INT vs VARCHAR)

-- Шаг 1: Создаем резервную копию существующих данных
CREATE TABLE IF NOT EXISTS dim_products_backup AS 
SELECT * FROM dim_products WHERE 1=0;

INSERT INTO dim_products_backup SELECT * FROM dim_products;

-- Шаг 2: Добавляем новую колонку для связи с product_cross_reference
ALTER TABLE dim_products 
ADD COLUMN cross_ref_id BIGINT NULL COMMENT 'Связь с таблицей product_cross_reference';

-- Шаг 3: Создаем временную колонку для нового типа данных sku_ozon
ALTER TABLE dim_products 
ADD COLUMN sku_ozon_new VARCHAR(50) NULL COMMENT 'Новое поле sku_ozon с типом VARCHAR';

-- Шаг 4: Копируем данные из старой колонки в новую с приведением типов
UPDATE dim_products 
SET sku_ozon_new = CASE 
    WHEN sku_ozon IS NOT NULL AND sku_ozon != 0 
    THEN CAST(sku_ozon AS CHAR) 
    ELSE NULL 
END;

-- Шаг 5: Удаляем старую колонку sku_ozon
ALTER TABLE dim_products DROP COLUMN sku_ozon;

-- Шаг 6: Переименовываем новую колонку
ALTER TABLE dim_products CHANGE COLUMN sku_ozon_new sku_ozon VARCHAR(50) NULL;

-- Шаг 7: Добавляем индекс для новой колонки
ALTER TABLE dim_products ADD INDEX idx_sku_ozon (sku_ozon);

-- Шаг 8: Добавляем внешний ключ для связи с product_cross_reference
-- (Добавляем после создания записей в cross_reference таблице)
-- ALTER TABLE dim_products 
-- ADD CONSTRAINT fk_dim_products_cross_ref 
-- FOREIGN KEY (cross_ref_id) REFERENCES product_cross_reference(id) 
-- ON DELETE SET NULL ON UPDATE CASCADE;

-- Шаг 9: Создаем индекс для внешнего ключа
ALTER TABLE dim_products ADD INDEX idx_cross_ref_id (cross_ref_id);