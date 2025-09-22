-- ===================================================================
-- ТЕСТИРОВАНИЕ СИСТЕМЫ РАСЧЕТА МАРЖИНАЛЬНОСТИ
-- ===================================================================

USE mi_core_db;

-- 1. ПРОВЕРКА ГОТОВНОСТИ СХЕМЫ
SELECT '=== ПРОВЕРКА ГОТОВНОСТИ СХЕМЫ ===' as step;

-- Проверяем наличие колонки margin_percent
SELECT 
    CASE 
        WHEN COUNT(*) > 0 THEN '✅ Колонка margin_percent существует'
        ELSE '❌ Колонка margin_percent отсутствует - выполните add_margin_percent_column.sql'
    END as margin_percent_status
FROM INFORMATION_SCHEMA.COLUMNS 
WHERE TABLE_SCHEMA = 'mi_core_db' 
    AND TABLE_NAME = 'metrics_daily' 
    AND COLUMN_NAME = 'margin_percent';

-- Проверяем структуру таблицы metrics_daily
SELECT '--- Структура metrics_daily ---' as info;
DESCRIBE metrics_daily;

-- 2. АНАЛИЗ ДАННЫХ
SELECT '=== АНАЛИЗ ИСХОДНЫХ ДАННЫХ ===' as step;

-- Общая статистика
SELECT 
    'fact_orders' as table_name,
    COUNT(*) as total_records,
    COUNT(CASE WHEN transaction_type = 'продажа' THEN 1 END) as sales_records,
    MIN(order_date) as min_date,
    MAX(order_date) as max_date
FROM fact_orders;

SELECT 
    'fact_transactions' as table_name,
    COUNT(*) as total_records,
    MIN(transaction_date) as min_date,
    MAX(transaction_date) as max_date
FROM fact_transactions;

SELECT 
    'dim_products' as table_name,
    COUNT(*) as total_products,
    COUNT(cost_price) as products_with_cost,
    ROUND(COUNT(cost_price) * 100.0 / COUNT(*), 2) as cost_coverage_percent
FROM dim_products;

-- 3. ПОИСК ПОДХОДЯЩЕЙ ДАТЫ ДЛЯ ТЕСТИРОВАНИЯ
SELECT '=== ПОИСК ТЕСТОВОЙ ДАТЫ ===' as step;

SELECT 
    fo.order_date,
    COUNT(*) as orders_count,
    SUM(fo.qty * fo.price) as total_revenue,
    COUNT(DISTINCT fo.client_id) as clients_count
FROM fact_orders fo
WHERE fo.transaction_type = 'продажа'
    AND fo.order_date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
GROUP BY fo.order_date
HAVING orders_count >= 3
ORDER BY fo.order_date DESC
LIMIT 5;

-- 4. АНАЛИЗ ТИПОВ ТРАНЗАКЦИЙ
SELECT '=== АНАЛИЗ ТИПОВ ТРАНЗАКЦИЙ ===' as step;

SELECT 
    transaction_type,
    COUNT(*) as count,
    SUM(amount) as total_amount,
    AVG(amount) as avg_amount,
    CASE 
        WHEN transaction_type LIKE '%комиссия%' OR transaction_type LIKE '%эквайринг%' 
             OR transaction_type LIKE '%commission%' OR transaction_type LIKE '%fee%' THEN 'КОМИССИИ'
        WHEN transaction_type LIKE '%логистика%' OR transaction_type LIKE '%доставка%' 
             OR transaction_type LIKE '%delivery%' OR transaction_type LIKE '%shipping%' THEN 'ЛОГИСТИКА'
        WHEN transaction_type LIKE '%возврат%' OR transaction_type LIKE '%return%' THEN 'ВОЗВРАТЫ'
        ELSE 'ПРОЧИЕ'
    END as category
FROM fact_transactions 
WHERE transaction_date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
GROUP BY transaction_type
ORDER BY count DESC
LIMIT 10;

-- 5. ТЕСТОВЫЙ РАСЧЕТ МАРЖИНАЛЬНОСТИ ДЛЯ ОДНОЙ ДАТЫ
-- ВНИМАНИЕ: Замените '2024-09-20' на актуальную дату из результатов выше!

SET @test_date = (
    SELECT fo.order_date
    FROM fact_orders fo
    WHERE fo.transaction_type = 'продажа'
        AND fo.order_date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
    GROUP BY fo.order_date
    HAVING COUNT(*) >= 3
    ORDER BY fo.order_date DESC
    LIMIT 1
);

SELECT CONCAT('=== ТЕСТОВЫЙ РАСЧЕТ ДЛЯ ДАТЫ: ', @test_date, ' ===') as step;

-- Исходные данные за тестовую дату
SELECT 
    'Исходные данные' as type,
    COUNT(*) as orders_count,
    SUM(qty * price) as total_revenue,
    COUNT(DISTINCT client_id) as clients_count
FROM fact_orders 
WHERE order_date = @test_date AND transaction_type = 'продажа';

-- Транзакции за тестовую дату
SELECT 
    'Транзакции' as type,
    COUNT(*) as transactions_count,
    SUM(ABS(amount)) as total_amount
FROM fact_transactions 
WHERE transaction_date = @test_date;

-- 6. РУЧНОЙ РАСЧЕТ МАРЖИНАЛЬНОСТИ (для сравнения)
SELECT '=== РУЧНОЙ РАСЧЕТ МАРЖИНАЛЬНОСТИ ===' as step;

SELECT
    fo.client_id,
    @test_date AS test_date,
    
    -- Базовые метрики
    COUNT(CASE WHEN fo.transaction_type = 'продажа' THEN fo.id END) AS orders_cnt,
    SUM(CASE WHEN fo.transaction_type = 'продажа' THEN (fo.qty * fo.price) ELSE 0 END) AS revenue_sum,
    
    -- Себестоимость
    SUM(CASE 
        WHEN fo.transaction_type = 'продажа' AND dp.cost_price IS NOT NULL 
        THEN COALESCE(dp.cost_price * fo.qty, 0) 
        ELSE 0 
    END) AS cogs_sum,
    
    -- Простой расчет прибыли (без детальной классификации транзакций)
    (
        SUM(CASE WHEN fo.transaction_type = 'продажа' THEN (fo.qty * fo.price) ELSE 0 END) -
        SUM(CASE 
            WHEN fo.transaction_type = 'продажа' AND dp.cost_price IS NOT NULL 
            THEN COALESCE(dp.cost_price * fo.qty, 0) 
            ELSE 0 
        END)
    ) AS simple_profit,
    
    -- Процент маржинальности
    CASE 
        WHEN SUM(CASE WHEN fo.transaction_type = 'продажа' THEN (fo.qty * fo.price) ELSE 0 END) > 0 
        THEN ROUND(
            (
                SUM(CASE WHEN fo.transaction_type = 'продажа' THEN (fo.qty * fo.price) ELSE 0 END) -
                SUM(CASE 
                    WHEN fo.transaction_type = 'продажа' AND dp.cost_price IS NOT NULL 
                    THEN COALESCE(dp.cost_price * fo.qty, 0) 
                    ELSE 0 
                END)
            ) * 100.0 / SUM(CASE WHEN fo.transaction_type = 'продажа' THEN (fo.qty * fo.price) ELSE 0 END), 2
        )
        ELSE NULL 
    END AS simple_margin_percent

FROM fact_orders fo
LEFT JOIN dim_products dp ON fo.product_id = dp.id
WHERE fo.order_date = @test_date
GROUP BY fo.client_id;

-- 7. ПРОВЕРКА СУЩЕСТВУЮЩИХ РЕЗУЛЬТАТОВ В METRICS_DAILY
SELECT '=== СУЩЕСТВУЮЩИЕ РЕЗУЛЬТАТЫ В METRICS_DAILY ===' as step;

SELECT 
    metric_date,
    client_id,
    orders_cnt,
    revenue_sum,
    cogs_sum,
    commission_sum,
    shipping_sum,
    profit_sum,
    margin_percent
FROM metrics_daily 
WHERE metric_date = @test_date;

-- Если результатов нет, показываем сообщение
SELECT 
    CASE 
        WHEN COUNT(*) = 0 THEN '⚠️  Нет результатов в metrics_daily для тестовой даты. Запустите: python3 run_aggregation.py'
        ELSE CONCAT('✅ Найдено ', COUNT(*), ' записей в metrics_daily для тестовой даты')
    END as metrics_status
FROM metrics_daily 
WHERE metric_date = @test_date;

-- 8. РЕКОМЕНДАЦИИ
SELECT '=== РЕКОМЕНДАЦИИ ===' as step;

SELECT 
    'Следующие шаги:' as recommendations,
    '1. Если колонка margin_percent отсутствует: mysql -u root -p mi_core_db < add_margin_percent_column.sql' as step1,
    '2. Для тестирования одной даты: python3 quick_margin_test.py' as step2,
    '3. Для полной агрегации: python3 run_aggregation.py' as step3,
    '4. Для валидации результатов: python3 validate_margin_calculations.py' as step4;

SELECT '=== ТЕСТ ЗАВЕРШЕН ===' as final_step;