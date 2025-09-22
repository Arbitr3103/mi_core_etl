-- ===================================================================
-- СКРИПТЫ ДЛЯ АНАЛИЗА ДАННЫХ ПЕРЕД УЛУЧШЕНИЕМ РАСЧЕТА МАРЖИНАЛЬНОСТИ
-- ===================================================================

-- 1. Общая статистика по таблице fact_transactions
SELECT 'ОБЩАЯ СТАТИСТИКА ТРАНЗАКЦИЙ' as analysis_type;
SELECT COUNT(*) as total_transactions FROM fact_transactions;

-- 2. Анализ типов транзакций
SELECT 'ТИПЫ ТРАНЗАКЦИЙ' as analysis_type;
SELECT 
    transaction_type,
    COUNT(*) as count,
    SUM(amount) as total_amount,
    AVG(amount) as avg_amount,
    MIN(amount) as min_amount,
    MAX(amount) as max_amount
FROM fact_transactions 
GROUP BY transaction_type 
ORDER BY count DESC;

-- 3. Анализ связи транзакций с заказами
SELECT 'СВЯЗЬ С ЗАКАЗАМИ' as analysis_type;
SELECT 
    COUNT(*) as transactions_with_orders,
    COUNT(DISTINCT order_id) as unique_orders
FROM fact_transactions 
WHERE order_id IS NOT NULL AND order_id != '';

-- 4. Анализ по источникам
SELECT 'АНАЛИЗ ПО ИСТОЧНИКАМ' as analysis_type;
SELECT 
    s.name as source_name,
    ft.transaction_type,
    COUNT(*) as count,
    SUM(ft.amount) as total_amount
FROM fact_transactions ft
JOIN sources s ON ft.source_id = s.id
GROUP BY s.name, ft.transaction_type
ORDER BY s.name, count DESC;

-- 5. Анализ временного распределения (последние 30 дней)
SELECT 'ВРЕМЕННОЕ РАСПРЕДЕЛЕНИЕ' as analysis_type;
SELECT 
    transaction_date,
    COUNT(*) as daily_count,
    SUM(amount) as daily_amount
FROM fact_transactions 
WHERE transaction_date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
GROUP BY transaction_date
ORDER BY transaction_date DESC
LIMIT 10;

-- 6. Проверка схемы metrics_daily
SELECT 'СХЕМА METRICS_DAILY' as analysis_type;
DESCRIBE metrics_daily;

-- 7. Статистика по metrics_daily
SELECT 'СТАТИСТИКА METRICS_DAILY' as analysis_type;
SELECT COUNT(*) as total_records FROM metrics_daily;

-- 8. Пример данных из metrics_daily
SELECT 'ПРИМЕР ДАННЫХ METRICS_DAILY' as analysis_type;
SELECT 
    metric_date,
    client_id,
    orders_cnt,
    revenue_sum,
    cogs_sum,
    profit_sum
FROM metrics_daily 
ORDER BY metric_date DESC 
LIMIT 5;

-- 9. Анализ товаров с себестоимостью
SELECT 'ТОВАРЫ С СЕБЕСТОИМОСТЬЮ' as analysis_type;
SELECT 
    COUNT(*) as total_products,
    COUNT(cost_price) as products_with_cost,
    AVG(cost_price) as avg_cost_price,
    MIN(cost_price) as min_cost_price,
    MAX(cost_price) as max_cost_price
FROM dim_products;

-- 10. Анализ заказов с привязкой к товарам
SELECT 'ЗАКАЗЫ С ТОВАРАМИ' as analysis_type;
SELECT 
    COUNT(*) as total_orders,
    COUNT(fo.product_id) as orders_with_product_id,
    COUNT(dp.cost_price) as orders_with_cost_price
FROM fact_orders fo
LEFT JOIN dim_products dp ON fo.product_id = dp.id
WHERE fo.transaction_type = 'продажа';

-- ===================================================================
-- СКРИПТ ДЛЯ ДОБАВЛЕНИЯ КОЛОНКИ MARGIN_PERCENT
-- ===================================================================

-- Проверяем, существует ли уже колонка margin_percent
SELECT 
    COLUMN_NAME 
FROM INFORMATION_SCHEMA.COLUMNS 
WHERE TABLE_SCHEMA = 'mi_core_db' 
    AND TABLE_NAME = 'metrics_daily' 
    AND COLUMN_NAME = 'margin_percent';

-- Если колонки нет, добавляем её (выполнить только если предыдущий запрос вернул пустой результат)
-- ALTER TABLE metrics_daily 
-- ADD COLUMN margin_percent DECIMAL(8,4) NULL COMMENT 'Процент маржинальности' 
-- AFTER profit_sum;

-- ===================================================================
-- СКРИПТЫ ДЛЯ СОЗДАНИЯ ИНДЕКСОВ (если их еще нет)
-- ===================================================================

-- Проверяем существующие индексы
SELECT 'СУЩЕСТВУЮЩИЕ ИНДЕКСЫ' as analysis_type;
SHOW INDEX FROM fact_orders;
SHOW INDEX FROM fact_transactions;
SHOW INDEX FROM dim_products;

-- Создание индексов для оптимизации (выполнить только если индексов нет)
-- CREATE INDEX idx_fact_orders_date_client ON fact_orders(order_date, client_id);
-- CREATE INDEX idx_fact_orders_product ON fact_orders(product_id);
-- CREATE INDEX idx_fact_transactions_order ON fact_transactions(order_id);
-- CREATE INDEX idx_fact_transactions_date_type ON fact_transactions(transaction_date, transaction_type);
-- CREATE INDEX idx_dim_products_cost ON dim_products(id, cost_price);