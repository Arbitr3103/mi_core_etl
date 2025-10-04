-- SQL запросы для модуля остатков товаров

-- 1. Основной запрос для получения остатков с детализацией
SELECT 
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
    i.reserved_quantity,
    (i.quantity - COALESCE(i.reserved_quantity, 0)) as available_quantity,
    dp.cost_price,
    (i.quantity * COALESCE(dp.cost_price, 0)) as inventory_value,
    i.last_updated,
    -- Индикатор критических остатков
    CASE 
        WHEN i.quantity <= 5 THEN 'critical'
        WHEN i.quantity <= 20 THEN 'low'
        WHEN i.quantity <= 50 THEN 'medium'
        ELSE 'good'
    END as stock_level
FROM inventory i
LEFT JOIN dim_products dp ON i.product_id = dp.id
WHERE i.quantity > 0
ORDER BY i.source, i.warehouse_name, dp.product_name;

-- 2. Сводная статистика по маркетплейсам
SELECT 
    i.source as marketplace,
    COUNT(DISTINCT i.product_id) as total_products,
    COUNT(DISTINCT i.warehouse_name) as total_warehouses,
    SUM(i.quantity) as total_quantity,
    SUM(i.reserved_quantity) as total_reserved,
    SUM(i.quantity - COALESCE(i.reserved_quantity, 0)) as total_available,
    SUM(i.quantity * COALESCE(dp.cost_price, 0)) as total_inventory_value,
    -- Критические остатки
    SUM(CASE WHEN i.quantity <= 5 THEN 1 ELSE 0 END) as critical_items,
    SUM(CASE WHEN i.quantity <= 20 THEN 1 ELSE 0 END) as low_stock_items
FROM inventory i
LEFT JOIN dim_products dp ON i.product_id = dp.id
WHERE i.quantity > 0
GROUP BY i.source
ORDER BY total_inventory_value DESC;

-- 3. Остатки по складам для конкретного маркетплейса
SELECT 
    i.warehouse_name,
    i.storage_type,
    COUNT(DISTINCT i.product_id) as products_count,
    SUM(i.quantity) as total_quantity,
    SUM(i.quantity * COALESCE(dp.cost_price, 0)) as warehouse_value,
    AVG(i.quantity) as avg_quantity_per_product
FROM inventory i
LEFT JOIN dim_products dp ON i.product_id = dp.id
WHERE i.source = :marketplace AND i.quantity > 0
GROUP BY i.warehouse_name, i.storage_type
ORDER BY warehouse_value DESC;

-- 4. Топ товаров по остаткам
SELECT 
    i.product_id,
    dp.product_name,
    CASE 
        WHEN i.source = 'Ozon' THEN dp.sku_ozon
        WHEN i.source = 'Wildberries' THEN dp.sku_wb
        ELSE i.sku
    END as display_sku,
    i.source,
    SUM(i.quantity) as total_stock,
    SUM(i.quantity * COALESCE(dp.cost_price, 0)) as stock_value,
    COUNT(DISTINCT i.warehouse_name) as warehouses_count
FROM inventory i
LEFT JOIN dim_products dp ON i.product_id = dp.id
WHERE i.quantity > 0
GROUP BY i.product_id, dp.product_name, i.source
ORDER BY stock_value DESC
LIMIT :limit;

-- 5. Критические остатки (товары на исходе)
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
    i.quantity,
    i.reserved_quantity,
    (i.quantity - COALESCE(i.reserved_quantity, 0)) as available_quantity
FROM inventory i
LEFT JOIN dim_products dp ON i.product_id = dp.id
WHERE i.quantity > 0 AND i.quantity <= :critical_threshold
ORDER BY i.quantity ASC, i.source, dp.product_name;