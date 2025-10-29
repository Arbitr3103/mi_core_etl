-- Скрипт для заполнения таблицы inventory тестовыми данными
-- на основе существующих товаров из dim_products
--
-- Создает реалистичные остатки для демонстрации дашборда

-- Очистка старых данных
TRUNCATE TABLE inventory CASCADE;

-- Вставка тестовых остатков для товаров
-- Создаем остатки для ~50 товаров на разных складах
INSERT INTO inventory (
    product_id,
    warehouse_name,
    stock_type,
    quantity_present,
    quantity_reserved,
    available,
    source,
    sku,
    name
)
SELECT 
    dp.id as product_id,
    CASE 
        WHEN random() < 0.5 THEN 'Дополнительный склад'
        WHEN random() < 0.75 THEN 'Коледино'
        ELSE 'Тверь'
    END as warehouse_name,
    'FBO' as stock_type,
    -- Генерируем случайные остатки от 0 до 200
    FLOOR(random() * 200)::integer as quantity_present,
    -- Резерв 0-20% от остатка
    FLOOR(random() * 40)::integer as quantity_reserved,
    -- Available будет рассчитан триггером или вручную
    GREATEST(0, FLOOR(random() * 200)::integer - FLOOR(random() * 40)::integer) as available,
    'Ozon' as source,
    dp.sku_ozon as sku,
    dp.product_name as name
FROM dim_products dp
WHERE dp.sku_ozon IS NOT NULL
LIMIT 50;

-- Обновляем available = quantity_present - quantity_reserved
UPDATE inventory
SET available = GREATEST(0, quantity_present - quantity_reserved);

-- Добавляем несколько товаров с критическими остатками (< 20)
UPDATE inventory
SET quantity_present = FLOOR(random() * 15)::integer,
    quantity_reserved = FLOOR(random() * 5)::integer
WHERE id IN (
    SELECT id FROM inventory ORDER BY random() LIMIT 15
);

-- Обновляем available после изменения
UPDATE inventory
SET available = GREATEST(0, quantity_present - quantity_reserved);

-- Добавляем товары с нулевыми остатками
UPDATE inventory
SET quantity_present = 0,
    quantity_reserved = 0,
    available = 0
WHERE id IN (
    SELECT id FROM inventory ORDER BY random() LIMIT 10
);

-- Статистика
SELECT 
    COUNT(*) as total_products,
    COUNT(*) FILTER (WHERE available > 0) as with_stock,
    COUNT(*) FILTER (WHERE available = 0) as out_of_stock,
    COUNT(*) FILTER (WHERE available > 0 AND available < 20) as critical,
    COUNT(*) FILTER (WHERE available >= 20 AND available < 50) as low,
    COUNT(*) FILTER (WHERE available >= 50) as normal,
    SUM(available) as total_available
FROM inventory;
