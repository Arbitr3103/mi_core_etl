-- ===================================================================
-- MARKETPLACE FILTERING DATABASE INDEXES
-- ===================================================================
-- Создание индексов для оптимизации запросов с фильтрацией по маркетплейсам
-- Поддерживает разделение данных между Ozon и Wildberries
-- 
-- Требования: 6.3 - Оптимизация запросов к базе данных для работы с разделенными данными
-- ===================================================================

-- Проверяем текущую базу данных
SELECT DATABASE() as current_database;

-- ===================================================================
-- ИНДЕКСЫ ДЛЯ ТАБЛИЦЫ SOURCES (источники данных)
-- ===================================================================

-- Индекс для быстрого поиска по коду источника (основной для определения маркетплейса)
-- Используется в MarketplaceDetector для фильтрации по source.code
CREATE INDEX IF NOT EXISTS idx_sources_code_marketplace 
ON sources(code);

-- Индекс для поиска по названию источника (дополнительный способ определения маркетплейса)
-- Используется в MarketplaceDetector для фильтрации по source.name
CREATE INDEX IF NOT EXISTS idx_sources_name_marketplace 
ON sources(name(100));

-- Составной индекс для оптимизации JOIN операций с fact_orders
CREATE INDEX IF NOT EXISTS idx_sources_id_code 
ON sources(id, code);

-- ===================================================================
-- ИНДЕКСЫ ДЛЯ ТАБЛИЦЫ FACT_ORDERS (заказы)
-- ===================================================================

-- Составной индекс для фильтрации заказов по источнику и дате
-- Критически важен для производительности запросов с marketplace фильтрами
CREATE INDEX IF NOT EXISTS idx_fact_orders_source_date 
ON fact_orders(source_id, order_date);

-- Составной индекс для фильтрации по источнику, дате и клиенту
-- Оптимизирует запросы с фильтрацией по маркетплейсу для конкретного клиента
CREATE INDEX IF NOT EXISTS idx_fact_orders_source_date_client 
ON fact_orders(source_id, order_date, client_id);

-- Индекс для связи заказов с товарами через SKU
-- Используется для определения маркетплейса через SKU matching
CREATE INDEX IF NOT EXISTS idx_fact_orders_sku_product 
ON fact_orders(sku, product_id);

-- Составной индекс для агрегации данных по маркетплейсам
-- Покрывает основные поля для расчета KPI по маркетплейсам
CREATE INDEX IF NOT EXISTS idx_fact_orders_marketplace_kpi 
ON fact_orders(source_id, transaction_type, order_date, price, qty);

-- ===================================================================
-- ИНДЕКСЫ ДЛЯ ТАБЛИЦЫ DIM_PRODUCTS (товары)
-- ===================================================================

-- Составные индексы для SKU полей маркетплейсов (если еще не созданы)
-- Критически важны для определения маркетплейса через SKU matching

-- Проверяем существование индекса для sku_ozon
SET @idx_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS 
                   WHERE TABLE_SCHEMA = DATABASE() 
                   AND TABLE_NAME = 'dim_products' 
                   AND INDEX_NAME = 'idx_sku_ozon');

SET @sql = IF(@idx_exists = 0, 
    'CREATE INDEX idx_sku_ozon ON dim_products(sku_ozon)', 
    'SELECT "Index idx_sku_ozon already exists" AS message');

PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Проверяем существование индекса для sku_wb
SET @idx_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS 
                   WHERE TABLE_SCHEMA = DATABASE() 
                   AND TABLE_NAME = 'dim_products' 
                   AND INDEX_NAME = 'idx_sku_wb');

SET @sql = IF(@idx_exists = 0, 
    'CREATE INDEX idx_sku_wb ON dim_products(sku_wb)', 
    'SELECT "Index idx_sku_wb already exists" AS message');

PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Составной индекс для оптимизации JOIN операций с fact_orders
-- Покрывает основные поля для связи товаров с заказами
CREATE INDEX IF NOT EXISTS idx_dim_products_marketplace_join 
ON dim_products(id, sku_ozon, sku_wb);

-- ===================================================================
-- ИНДЕКСЫ ДЛЯ ТАБЛИЦЫ FACT_TRANSACTIONS (транзакции)
-- ===================================================================

-- Составной индекс для фильтрации транзакций по источнику и дате
-- Используется для расчета маржинальности по маркетплейсам
CREATE INDEX IF NOT EXISTS idx_fact_transactions_source_date 
ON fact_transactions(source_id, transaction_date);

-- Составной индекс для связи транзакций с заказами по маркетплейсам
CREATE INDEX IF NOT EXISTS idx_fact_transactions_order_source 
ON fact_transactions(order_id, source_id);

-- ===================================================================
-- АНАЛИЗ ПРОИЗВОДИТЕЛЬНОСТИ СОЗДАННЫХ ИНДЕКСОВ
-- ===================================================================

-- Показываем все созданные индексы для маркетплейс-фильтрации
SELECT 
    TABLE_NAME,
    INDEX_NAME,
    COLUMN_NAME,
    SEQ_IN_INDEX,
    CARDINALITY
FROM INFORMATION_SCHEMA.STATISTICS 
WHERE TABLE_SCHEMA = DATABASE()
    AND INDEX_NAME LIKE '%marketplace%' 
    OR INDEX_NAME LIKE '%source%'
    OR INDEX_NAME LIKE '%sku_ozon%'
    OR INDEX_NAME LIKE '%sku_wb%'
ORDER BY TABLE_NAME, INDEX_NAME, SEQ_IN_INDEX;

-- ===================================================================
-- РЕКОМЕНДАЦИИ ПО МОНИТОРИНГУ ПРОИЗВОДИТЕЛЬНОСТИ
-- ===================================================================

-- Запрос для мониторинга использования индексов
-- Выполнять периодически для анализа эффективности
/*
SELECT 
    s.TABLE_NAME,
    s.INDEX_NAME,
    s.CARDINALITY,
    ROUND(s.CARDINALITY / t.TABLE_ROWS * 100, 2) as selectivity_percent
FROM INFORMATION_SCHEMA.STATISTICS s
JOIN INFORMATION_SCHEMA.TABLES t ON s.TABLE_SCHEMA = t.TABLE_SCHEMA AND s.TABLE_NAME = t.TABLE_NAME
WHERE s.TABLE_SCHEMA = DATABASE()
    AND s.INDEX_NAME IN (
        'idx_sources_code_marketplace',
        'idx_fact_orders_source_date',
        'idx_fact_orders_source_date_client',
        'idx_sku_ozon',
        'idx_sku_wb'
    )
ORDER BY s.TABLE_NAME, s.INDEX_NAME;
*/

-- ===================================================================
-- ТЕСТОВЫЕ ЗАПРОСЫ ДЛЯ ПРОВЕРКИ ПРОИЗВОДИТЕЛЬНОСТИ
-- ===================================================================

-- Тест 1: Фильтрация заказов по маркетплейсу Ozon
/*
EXPLAIN SELECT fo.*, s.code, s.name
FROM fact_orders fo
JOIN sources s ON fo.source_id = s.id
WHERE (s.code LIKE '%ozon%' OR s.name LIKE '%ozon%')
    AND fo.order_date BETWEEN '2024-01-01' AND '2024-12-31';
*/

-- Тест 2: Агрегация данных по маркетплейсу Wildberries
/*
EXPLAIN SELECT 
    COUNT(*) as orders_count,
    SUM(fo.qty * fo.price) as total_revenue
FROM fact_orders fo
JOIN sources s ON fo.source_id = s.id
WHERE (s.code LIKE '%wb%' OR s.code LIKE '%wildberries%')
    AND fo.order_date BETWEEN '2024-01-01' AND '2024-12-31'
    AND fo.transaction_type = 'продажа';
*/

-- Тест 3: Определение маркетплейса через SKU matching
/*
EXPLAIN SELECT fo.*, dp.sku_ozon, dp.sku_wb
FROM fact_orders fo
JOIN dim_products dp ON fo.product_id = dp.id
WHERE (dp.sku_ozon IS NOT NULL AND fo.sku = dp.sku_ozon)
    OR (dp.sku_wb IS NOT NULL AND fo.sku = dp.sku_wb);
*/

SELECT 'Marketplace filtering indexes created successfully!' as status;