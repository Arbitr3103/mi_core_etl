-- Скрипт для заполнения таблицы product_cross_reference существующими данными
-- Обновляет существующие данные для совместимости с новой схемой

-- Шаг 1: Заполняем cross_reference данными из inventory_data
INSERT INTO product_cross_reference (
    inventory_product_id, 
    sku_ozon, 
    sync_status,
    created_at
)
SELECT DISTINCT 
    CAST(i.product_id AS CHAR) as inventory_product_id,
    CAST(i.product_id AS CHAR) as sku_ozon,
    'pending' as sync_status,
    NOW() as created_at
FROM inventory_data i
WHERE i.product_id IS NOT NULL 
    AND i.product_id != 0
    AND NOT EXISTS (
        SELECT 1 FROM product_cross_reference pcr 
        WHERE pcr.inventory_product_id = CAST(i.product_id AS CHAR)
    );

-- Шаг 2: Обновляем связи в dim_products с новой cross_reference таблицей
UPDATE dim_products dp
JOIN product_cross_reference pcr ON dp.sku_ozon = pcr.sku_ozon
SET dp.cross_ref_id = pcr.id
WHERE dp.cross_ref_id IS NULL;

-- Шаг 3: Создаем записи в cross_reference для товаров из dim_products, которых нет в inventory
INSERT INTO product_cross_reference (
    inventory_product_id,
    sku_ozon,
    cached_name,
    sync_status,
    created_at
)
SELECT DISTINCT
    dp.sku_ozon as inventory_product_id,
    dp.sku_ozon,
    dp.name as cached_name,
    CASE 
        WHEN dp.name LIKE 'Товар Ozon ID%' THEN 'pending'
        ELSE 'synced'
    END as sync_status,
    NOW() as created_at
FROM dim_products dp
WHERE dp.sku_ozon IS NOT NULL
    AND NOT EXISTS (
        SELECT 1 FROM product_cross_reference pcr 
        WHERE pcr.sku_ozon = dp.sku_ozon
    );

-- Шаг 4: Обновляем cross_ref_id для новых записей
UPDATE dim_products dp
JOIN product_cross_reference pcr ON dp.sku_ozon = pcr.sku_ozon
SET dp.cross_ref_id = pcr.id
WHERE dp.cross_ref_id IS NULL;

-- Шаг 5: Добавляем внешний ключ (раскомментировать после проверки данных)
-- ALTER TABLE dim_products 
-- ADD CONSTRAINT fk_dim_products_cross_ref 
-- FOREIGN KEY (cross_ref_id) REFERENCES product_cross_reference(id) 
-- ON DELETE SET NULL ON UPDATE CASCADE;