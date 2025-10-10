-- Исправленные SQL запросы без ошибок
-- Решает проблемы с DISTINCT + ORDER BY и совместимостью типов данных

-- =============================================================================
-- ПРОБЛЕМНЫЙ ЗАПРОС (вызывает ошибку):
-- SELECT DISTINCT i.product_id
-- FROM inventory_data i
-- LEFT JOIN dim_products dp ON CONCAT('', i.product_id) = dp.sku_ozon
-- WHERE i.product_id != 0
-- ORDER BY i.quantity_present DESC  -- ОШИБКА: колонка не в SELECT list
-- LIMIT 20;
-- =============================================================================

-- ИСПРАВЛЕННЫЙ ЗАПРОС 1: Включаем сортируемую колонку в SELECT
SELECT DISTINCT 
    i.product_id, 
    MAX(i.quantity_present) as max_quantity
FROM inventory_data i
LEFT JOIN product_cross_reference pcr ON CAST(i.product_id AS CHAR) = pcr.inventory_product_id
LEFT JOIN dim_products dp ON pcr.cross_ref_id = dp.cross_ref_id
WHERE i.product_id != 0
GROUP BY i.product_id
ORDER BY max_quantity DESC
LIMIT 20;

-- ИСПРАВЛЕННЫЙ ЗАПРОС 2: Используем подзапрос для сложной логики
SELECT product_id, product_name, quantity_present
FROM (
    SELECT 
        i.product_id,
        COALESCE(dp.name, pcr.cached_name, CONCAT('Товар ID ', i.product_id)) as product_name,
        i.quantity_present,
        ROW_NUMBER() OVER (ORDER BY i.quantity_present DESC) as rn
    FROM inventory_data i
    LEFT JOIN product_cross_reference pcr ON CAST(i.product_id AS CHAR) = pcr.inventory_product_id
    LEFT JOIN dim_products dp ON pcr.cross_ref_id = dp.cross_ref_id
    WHERE i.product_id != 0
) ranked_products
WHERE rn <= 20;

-- =============================================================================
-- БЕЗОПАСНЫЕ ПАТТЕРНЫ ЗАПРОСОВ
-- =============================================================================

-- Паттерн 1: Простой DISTINCT без ORDER BY
SELECT DISTINCT pcr.inventory_product_id
FROM product_cross_reference pcr
WHERE pcr.sync_status = 'pending'
LIMIT 20;

-- Паттерн 2: GROUP BY вместо DISTINCT для агрегации
SELECT 
    pcr.inventory_product_id,
    COUNT(*) as record_count,
    MAX(pcr.last_successful_sync) as last_sync
FROM product_cross_reference pcr
JOIN inventory_data i ON CAST(i.product_id AS CHAR) = pcr.inventory_product_id
GROUP BY pcr.inventory_product_id
HAVING record_count > 1;

-- Паттерн 3: Безопасный JOIN с приведением типов
SELECT 
    i.product_id,
    i.quantity_present,
    COALESCE(dp.name, pcr.cached_name, 'Неизвестный товар') as product_name,
    pcr.sync_status
FROM inventory_data i
LEFT JOIN product_cross_reference pcr ON CAST(i.product_id AS CHAR) = pcr.inventory_product_id
LEFT JOIN dim_products dp ON pcr.cross_ref_id = dp.cross_ref_id
WHERE i.product_id IS NOT NULL AND i.product_id != 0;

-- =============================================================================
-- ЗАПРОСЫ ДЛЯ СИНХРОНИЗАЦИИ НАЗВАНИЙ ТОВАРОВ
-- =============================================================================

-- Найти товары без названий (для sync-real-product-names.php)
SELECT 
    pcr.id,
    pcr.inventory_product_id,
    pcr.ozon_product_id,
    pcr.cached_name,
    pcr.sync_status
FROM product_cross_reference pcr
LEFT JOIN dim_products dp ON pcr.cross_ref_id = dp.cross_ref_id
WHERE (
    dp.name IS NULL 
    OR dp.name LIKE 'Товар Ozon ID%'
    OR pcr.cached_name IS NULL
)
AND pcr.sync_status IN ('pending', 'failed')
ORDER BY pcr.id
LIMIT 50;

-- Обновить название товара после получения из API
UPDATE product_cross_reference pcr
JOIN dim_products dp ON pcr.cross_ref_id = dp.cross_ref_id
SET 
    pcr.cached_name = ?,
    pcr.sync_status = 'synced',
    pcr.last_successful_sync = NOW(),
    dp.name = ?
WHERE pcr.inventory_product_id = ?;

-- =============================================================================
-- ЗАПРОСЫ ДЛЯ ПРОВЕРКИ СОВМЕСТИМОСТИ С MySQL ONLY_FULL_GROUP_BY
-- =============================================================================

-- Правильный GROUP BY с агрегацией
SELECT 
    pcr.sync_status,
    COUNT(*) as count,
    COUNT(CASE WHEN pcr.cached_name IS NOT NULL THEN 1 END) as with_names,
    MAX(pcr.last_successful_sync) as latest_sync
FROM product_cross_reference pcr
GROUP BY pcr.sync_status;

-- Избегаем SELECT * с GROUP BY
SELECT 
    i.product_id,
    SUM(i.quantity_present) as total_quantity,
    COUNT(*) as warehouse_count
FROM inventory_data i
WHERE i.product_id IS NOT NULL
GROUP BY i.product_id
HAVING total_quantity > 0;

-- =============================================================================
-- ПРОИЗВОДИТЕЛЬНЫЕ ЗАПРОСЫ С ИНДЕКСАМИ
-- =============================================================================

-- Быстрый поиск по inventory_product_id (использует индекс)
SELECT pcr.*, dp.name
FROM product_cross_reference pcr
LEFT JOIN dim_products dp ON pcr.cross_ref_id = dp.cross_ref_id
WHERE pcr.inventory_product_id = ?;

-- Быстрый поиск товаров для синхронизации (использует индекс sync_status)
SELECT pcr.inventory_product_id, pcr.ozon_product_id
FROM product_cross_reference pcr
WHERE pcr.sync_status = 'pending'
AND pcr.last_sync_attempt IS NULL
ORDER BY pcr.id
LIMIT 10;