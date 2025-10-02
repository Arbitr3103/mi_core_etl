-- ===================================================================
-- MARKETPLACE QUERY PERFORMANCE ANALYSIS
-- ===================================================================
-- Скрипт для анализа производительности запросов с фильтрацией по маркетплейсам
-- Тестирует эффективность созданных индексов и выявляет узкие места
-- 
-- Требования: 6.3 - Анализ производительности запросов с marketplace фильтрами
-- ===================================================================

-- Включаем профилирование запросов
SET profiling = 1;

-- ===================================================================
-- АНАЛИЗ СУЩЕСТВУЮЩИХ ИНДЕКСОВ
-- ===================================================================

SELECT 'АНАЛИЗ ИНДЕКСОВ ДЛЯ MARKETPLACE ФИЛЬТРАЦИИ' as analysis_section;

-- Проверяем наличие всех необходимых индексов
SELECT 
    'INDEX COVERAGE CHECK' as check_type,
    TABLE_NAME,
    INDEX_NAME,
    COLUMN_NAME,
    CARDINALITY,
    CASE 
        WHEN CARDINALITY > 0 THEN 'ACTIVE'
        ELSE 'UNUSED'
    END as status
FROM INFORMATION_SCHEMA.STATISTICS 
WHERE TABLE_SCHEMA = DATABASE()
    AND (
        INDEX_NAME LIKE '%marketplace%' 
        OR INDEX_NAME LIKE '%source%'
        OR INDEX_NAME LIKE '%sku_ozon%'
        OR INDEX_NAME LIKE '%sku_wb%'
        OR (TABLE_NAME = 'fact_orders' AND INDEX_NAME IN ('idx_fact_orders_source_date', 'idx_fact_orders_source_date_client'))
        OR (TABLE_NAME = 'sources' AND INDEX_NAME IN ('idx_sources_code_marketplace', 'idx_sources_name_marketplace'))
    )
ORDER BY TABLE_NAME, INDEX_NAME;

-- ===================================================================
-- ТЕСТИРОВАНИЕ ПРОИЗВОДИТЕЛЬНОСТИ БАЗОВЫХ ЗАПРОСОВ
-- ===================================================================

SELECT 'PERFORMANCE TESTING - BASIC QUERIES' as test_section;

-- Тест 1: Базовая фильтрация по источнику Ozon
SELECT 'TEST 1: Ozon source filtering' as test_name;

EXPLAIN FORMAT=JSON
SELECT fo.id, fo.order_id, fo.sku, fo.price, fo.order_date, s.code, s.name
FROM fact_orders fo
JOIN sources s ON fo.source_id = s.id
WHERE (s.code LIKE '%ozon%' OR s.name LIKE '%ozon%')
    AND fo.order_date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
LIMIT 100;

-- Тест 2: Базовая фильтрация по источнику Wildberries
SELECT 'TEST 2: Wildberries source filtering' as test_name;

EXPLAIN FORMAT=JSON
SELECT fo.id, fo.order_id, fo.sku, fo.price, fo.order_date, s.code, s.name
FROM fact_orders fo
JOIN sources s ON fo.source_id = s.id
WHERE (s.code LIKE '%wb%' OR s.code LIKE '%wildberries%' OR s.name LIKE '%wildberries%')
    AND fo.order_date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
LIMIT 100;

-- ===================================================================
-- ТЕСТИРОВАНИЕ АГРЕГАЦИОННЫХ ЗАПРОСОВ
-- ===================================================================

SELECT 'PERFORMANCE TESTING - AGGREGATION QUERIES' as test_section;

-- Тест 3: Агрегация KPI по маркетплейсу Ozon
SELECT 'TEST 3: Ozon KPI aggregation' as test_name;

EXPLAIN FORMAT=JSON
SELECT 
    COUNT(DISTINCT fo.order_id) as orders_count,
    SUM(fo.qty * fo.price) as total_revenue,
    AVG(fo.price) as avg_price,
    COUNT(DISTINCT fo.product_id) as unique_products
FROM fact_orders fo
JOIN sources s ON fo.source_id = s.id
WHERE (s.code LIKE '%ozon%' OR s.name LIKE '%ozon%')
    AND fo.order_date BETWEEN DATE_SUB(CURDATE(), INTERVAL 90 DAY) AND CURDATE()
    AND fo.transaction_type IN ('продажа', 'sale', 'order');

-- Тест 4: Агрегация KPI по маркетплейсу Wildberries
SELECT 'TEST 4: Wildberries KPI aggregation' as test_name;

EXPLAIN FORMAT=JSON
SELECT 
    COUNT(DISTINCT fo.order_id) as orders_count,
    SUM(fo.qty * fo.price) as total_revenue,
    AVG(fo.price) as avg_price,
    COUNT(DISTINCT fo.product_id) as unique_products
FROM fact_orders fo
JOIN sources s ON fo.source_id = s.id
WHERE (s.code LIKE '%wb%' OR s.code LIKE '%wildberries%' OR s.name LIKE '%wildberries%')
    AND fo.order_date BETWEEN DATE_SUB(CURDATE(), INTERVAL 90 DAY) AND CURDATE()
    AND fo.transaction_type IN ('продажа', 'sale', 'order');

-- ===================================================================
-- ТЕСТИРОВАНИЕ SKU-BASED ФИЛЬТРАЦИИ
-- ===================================================================

SELECT 'PERFORMANCE TESTING - SKU-BASED FILTERING' as test_section;

-- Тест 5: Определение маркетплейса через SKU matching
SELECT 'TEST 5: SKU-based marketplace detection' as test_name;

EXPLAIN FORMAT=JSON
SELECT fo.id, fo.order_id, fo.sku, dp.sku_ozon, dp.sku_wb,
    CASE 
        WHEN dp.sku_ozon IS NOT NULL AND fo.sku = dp.sku_ozon THEN 'ozon'
        WHEN dp.sku_wb IS NOT NULL AND fo.sku = dp.sku_wb THEN 'wildberries'
        ELSE 'unknown'
    END as detected_marketplace
FROM fact_orders fo
JOIN dim_products dp ON fo.product_id = dp.id
WHERE (dp.sku_ozon IS NOT NULL AND fo.sku = dp.sku_ozon)
    OR (dp.sku_wb IS NOT NULL AND fo.sku = dp.sku_wb)
    AND fo.order_date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
LIMIT 100;

-- ===================================================================
-- ТЕСТИРОВАНИЕ КОМПЛЕКСНЫХ ЗАПРОСОВ
-- ===================================================================

SELECT 'PERFORMANCE TESTING - COMPLEX QUERIES' as test_section;

-- Тест 6: Комплексный запрос с множественными JOIN и фильтрацией
SELECT 'TEST 6: Complex marketplace query with multiple JOINs' as test_name;

EXPLAIN FORMAT=JSON
SELECT 
    s.code as source_code,
    s.name as source_name,
    dp.name as product_name,
    dp.sku_ozon,
    dp.sku_wb,
    fo.order_date,
    fo.qty * fo.price as revenue,
    CASE 
        WHEN s.code LIKE '%ozon%' OR s.name LIKE '%ozon%' THEN 'ozon'
        WHEN s.code LIKE '%wb%' OR s.code LIKE '%wildberries%' THEN 'wildberries'
        WHEN dp.sku_ozon IS NOT NULL AND fo.sku = dp.sku_ozon THEN 'ozon'
        WHEN dp.sku_wb IS NOT NULL AND fo.sku = dp.sku_wb THEN 'wildberries'
        ELSE 'unknown'
    END as marketplace
FROM fact_orders fo
JOIN sources s ON fo.source_id = s.id
JOIN dim_products dp ON fo.product_id = dp.id
WHERE fo.order_date BETWEEN DATE_SUB(CURDATE(), INTERVAL 30 DAY) AND CURDATE()
    AND fo.transaction_type IN ('продажа', 'sale', 'order')
    AND (
        s.code LIKE '%ozon%' OR s.name LIKE '%ozon%' OR
        s.code LIKE '%wb%' OR s.code LIKE '%wildberries%' OR
        (dp.sku_ozon IS NOT NULL AND fo.sku = dp.sku_ozon) OR
        (dp.sku_wb IS NOT NULL AND fo.sku = dp.sku_wb)
    )
ORDER BY fo.order_date DESC
LIMIT 50;

-- ===================================================================
-- АНАЛИЗ СТАТИСТИКИ ВЫПОЛНЕНИЯ
-- ===================================================================

SELECT 'QUERY EXECUTION STATISTICS' as stats_section;

-- Показываем профили выполнения запросов
SHOW PROFILES;

-- ===================================================================
-- РЕКОМЕНДАЦИИ ПО ОПТИМИЗАЦИИ
-- ===================================================================

SELECT 'OPTIMIZATION RECOMMENDATIONS' as recommendations_section;

-- Анализ селективности индексов
SELECT 
    'INDEX SELECTIVITY ANALYSIS' as analysis_type,
    s.TABLE_NAME,
    s.INDEX_NAME,
    s.CARDINALITY,
    t.TABLE_ROWS,
    CASE 
        WHEN t.TABLE_ROWS > 0 THEN ROUND(s.CARDINALITY / t.TABLE_ROWS * 100, 2)
        ELSE 0
    END as selectivity_percent,
    CASE 
        WHEN t.TABLE_ROWS > 0 AND s.CARDINALITY / t.TABLE_ROWS > 0.1 THEN 'GOOD'
        WHEN t.TABLE_ROWS > 0 AND s.CARDINALITY / t.TABLE_ROWS > 0.01 THEN 'FAIR'
        ELSE 'POOR'
    END as selectivity_rating
FROM INFORMATION_SCHEMA.STATISTICS s
JOIN INFORMATION_SCHEMA.TABLES t ON s.TABLE_SCHEMA = t.TABLE_SCHEMA AND s.TABLE_NAME = t.TABLE_NAME
WHERE s.TABLE_SCHEMA = DATABASE()
    AND s.INDEX_NAME IN (
        'idx_sources_code_marketplace',
        'idx_sources_name_marketplace',
        'idx_fact_orders_source_date',
        'idx_fact_orders_source_date_client',
        'idx_sku_ozon',
        'idx_sku_wb',
        'idx_dim_products_marketplace_join'
    )
    AND s.SEQ_IN_INDEX = 1  -- Только первый столбец составных индексов
ORDER BY selectivity_percent DESC;

-- Проверка дублирующихся индексов
SELECT 
    'DUPLICATE INDEX CHECK' as check_type,
    TABLE_NAME,
    GROUP_CONCAT(INDEX_NAME) as potentially_duplicate_indexes,
    GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX) as columns
FROM INFORMATION_SCHEMA.STATISTICS 
WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME IN ('fact_orders', 'sources', 'dim_products')
GROUP BY TABLE_NAME, GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX)
HAVING COUNT(DISTINCT INDEX_NAME) > 1;

-- ===================================================================
-- МОНИТОРИНГ ИСПОЛЬЗОВАНИЯ ИНДЕКСОВ
-- ===================================================================

-- Запрос для периодического мониторинга (требует включенного performance_schema)
/*
SELECT 
    'INDEX USAGE MONITORING' as monitor_type,
    object_schema,
    object_name,
    index_name,
    count_read,
    count_write,
    count_read / (count_read + count_write + 1) * 100 as read_percentage
FROM performance_schema.table_io_waits_summary_by_index_usage
WHERE object_schema = DATABASE()
    AND object_name IN ('fact_orders', 'sources', 'dim_products')
    AND index_name IS NOT NULL
ORDER BY count_read DESC;
*/

-- Отключаем профилирование
SET profiling = 0;

SELECT 'Marketplace query performance analysis completed!' as status;