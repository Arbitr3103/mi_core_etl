-- Оптимизация запросов для модуля остатков товаров

-- 1. Создание индексов для ускорения запросов
CREATE INDEX IF NOT EXISTS idx_inventory_source ON inventory(source);
CREATE INDEX IF NOT EXISTS idx_inventory_warehouse ON inventory(warehouse_name);
CREATE INDEX IF NOT EXISTS idx_inventory_quantity ON inventory(quantity);
CREATE INDEX IF NOT EXISTS idx_inventory_product_source ON inventory(product_id, source);
CREATE INDEX IF NOT EXISTS idx_inventory_updated ON inventory(last_updated);

-- Составной индекс для основных фильтров
CREATE INDEX IF NOT EXISTS idx_inventory_main_filters ON inventory(source, warehouse_name, quantity);

-- 2. Индексы для dim_products
CREATE INDEX IF NOT EXISTS idx_products_name ON dim_products(product_name);
CREATE INDEX IF NOT EXISTS idx_products_sku_ozon ON dim_products(sku_ozon);
CREATE INDEX IF NOT EXISTS idx_products_sku_wb ON dim_products(sku_wb);

-- 3. Оптимизированный запрос для получения остатков с пагинацией
-- Использует LIMIT с OFFSET для эффективной пагинации
SELECT SQL_CALC_FOUND_ROWS
    i.product_id,
    dp.product_name,
    CASE 
        WHEN i.source = 'Ozon' THEN dp.sku_ozon
        WHEN i.source = 'Wildberries' THEN dp.sku_wb
        ELSE i.sku
    END as display_sku,
    dp.sku_ozon,
    dp.sku_wb,
    i.source as marketplace,
    i.warehouse_name,
    i.storage_type,
    i.quantity,
    COALESCE(i.reserved_quantity, 0) as reserved_quantity,
    (i.quantity - COALESCE(i.reserved_quantity, 0)) as available_quantity,
    dp.cost_price,
    (i.quantity * COALESCE(dp.cost_price, 0)) as inventory_value,
    i.last_updated,
    CASE 
        WHEN i.quantity <= 5 THEN 'critical'
        WHEN i.quantity <= 20 THEN 'low'
        WHEN i.quantity <= 50 THEN 'medium'
        ELSE 'good'
    END as stock_level
FROM inventory i
FORCE INDEX (idx_inventory_main_filters)
LEFT JOIN dim_products dp ON i.product_id = dp.id
WHERE i.quantity > 0
ORDER BY i.source, i.warehouse_name, dp.product_name
LIMIT 50 OFFSET 0;

-- 4. Оптимизированный запрос для сводной статистики (с кэшированием)
SELECT 
    i.source as marketplace,
    COUNT(DISTINCT i.product_id) as total_products,
    COUNT(DISTINCT i.warehouse_name) as total_warehouses,
    SUM(i.quantity) as total_quantity,
    SUM(COALESCE(i.reserved_quantity, 0)) as total_reserved,
    SUM(i.quantity - COALESCE(i.reserved_quantity, 0)) as total_available,
    SUM(i.quantity * COALESCE(dp.cost_price, 0)) as total_inventory_value,
    SUM(CASE WHEN i.quantity <= 5 THEN 1 ELSE 0 END) as critical_items,
    SUM(CASE WHEN i.quantity <= 20 THEN 1 ELSE 0 END) as low_stock_items
FROM inventory i
FORCE INDEX (idx_inventory_source)
LEFT JOIN dim_products dp ON i.product_id = dp.id
WHERE i.quantity > 0
GROUP BY i.source
ORDER BY total_inventory_value DESC;

-- 5. Анализ производительности запросов
EXPLAIN SELECT 
    i.product_id,
    dp.product_name,
    i.source,
    i.warehouse_name,
    i.quantity
FROM inventory i
LEFT JOIN dim_products dp ON i.product_id = dp.id
WHERE i.quantity > 0 
    AND i.source = 'Ozon'
ORDER BY i.quantity DESC
LIMIT 50;