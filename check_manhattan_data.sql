-- Проверка данных по ТД Манхэттен за период 22.09-28.09
-- Загрузка должна была произойти 29.09 в 3:00

-- 1. Проверяем все таблицы в базе
SHOW TABLES;

-- 2. Ищем таблицы с данными по продажам/заказам
SHOW TABLES LIKE '%order%';
SHOW TABLES LIKE '%sale%';
SHOW TABLES LIKE '%transaction%';
SHOW TABLES LIKE '%manhattan%';

-- 3. Проверяем таблицу fact_orders (если есть)
SELECT COUNT(*) as total_orders,
       MIN(created_at) as earliest_date,
       MAX(created_at) as latest_date
FROM fact_orders 
WHERE DATE(created_at) BETWEEN '2025-09-22' AND '2025-09-28';

-- 4. Проверяем данные по источникам (Wildberries, Ozon)
SELECT 
    source,
    COUNT(*) as orders_count,
    DATE(created_at) as order_date
FROM fact_orders 
WHERE DATE(created_at) BETWEEN '2025-09-22' AND '2025-09-28'
GROUP BY source, DATE(created_at)
ORDER BY order_date DESC, source;

-- 5. Проверяем последние загруженные данные
SELECT 
    source,
    COUNT(*) as records_count,
    MAX(created_at) as last_record,
    MIN(created_at) as first_record
FROM fact_orders 
GROUP BY source
ORDER BY last_record DESC;

-- 6. Проверяем данные конкретно за 29.09 (день загрузки)
SELECT 
    source,
    COUNT(*) as records_loaded,
    DATE(created_at) as data_date,
    HOUR(created_at) as load_hour
FROM fact_orders 
WHERE DATE(created_at) = '2025-09-29'
GROUP BY source, DATE(created_at), HOUR(created_at)
ORDER BY load_hour;

-- 7. Проверяем таблицу raw_events (если есть)
SELECT 
    event_type,
    source,
    COUNT(*) as events_count,
    DATE(created_at) as event_date
FROM raw_events 
WHERE DATE(created_at) BETWEEN '2025-09-22' AND '2025-09-29'
GROUP BY event_type, source, DATE(created_at)
ORDER BY event_date DESC;