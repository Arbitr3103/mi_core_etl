-- ===================================================================
-- ТЕСТИРОВАНИЕ РАСЧЕТА МАРЖИНАЛЬНОСТИ ДЛЯ ОДНОЙ ДАТЫ
-- ===================================================================
-- ИНСТРУКЦИЯ: Замените '2024-09-20' на актуальную дату с данными
-- ===================================================================

USE mi_core_db;

-- Устанавливаем тестовую дату (ИЗМЕНИТЕ НА АКТУАЛЬНУЮ!)
SET @test_date = '2024-09-20';

SELECT CONCAT('🧪 ТЕСТИРОВАНИЕ МАРЖИНАЛЬНОСТИ ДЛЯ ДАТЫ: ', @test_date) as test_info;

-- 1. ПРОВЕРКА ИСХОДНЫХ ДАННЫХ
SELECT '=== 1. ИСХОДНЫЕ ДАННЫЕ ===' as step;

SELECT 
    COUNT(*) as orders_count,
    SUM(qty * price) as total_revenue,
    COUNT(DISTINCT client_id) as clients_count,
    COUNT(DISTINCT product_id) as products_count
FROM fact_orders 
WHERE order_date = @test_date AND transaction_type = 'продажа';

-- Детали по клиентам
SELECT 
    client_id,
    COUNT(*) as orders,
    SUM(qty * price) as revenue
FROM fact_orders 
WHERE order_date = @test_date AND transaction_type = 'продажа'
GROUP BY client_id;

-- 2. ПРОВЕРКА ТРАНЗАКЦИЙ
SELECT '=== 2. ТРАНЗАКЦИИ ===' as step;

SELECT 
    COUNT(*) as transactions_count,
    SUM(CASE WHEN amount > 0 THEN amount ELSE 0 END) as positive_amount,
    SUM(CASE WHEN amount < 0 THEN ABS(amount) ELSE 0 END) as negative_amount
FROM fact_transactions 
WHERE transaction_date = @test_date;

-- Типы транзакций
SELECT 
    transaction_type,
    COUNT(*) as count,
    SUM(amount) as total_amount
FROM fact_transactions 
WHERE transaction_date = @test_date
GROUP BY transaction_type
ORDER BY count DESC;

-- 3. ПРОВЕРКА СЕБЕСТОИМОСТИ
SELECT '=== 3. СЕБЕСТОИМОСТЬ ===' as step;

SELECT 
    COUNT(DISTINCT fo.product_id) as total_products_in_orders,
    COUNT(DISTINCT CASE WHEN dp.cost_price IS NOT NULL THEN fo.product_id END) as products_with_cost,
    ROUND(
        COUNT(DISTINCT CASE WHEN dp.cost_price IS NOT NULL THEN fo.product_id END) * 100.0 / 
        COUNT(DISTINCT fo.product_id), 2
    ) as cost_coverage_percent
FROM fact_orders fo
LEFT JOIN dim_products dp ON fo.product_id = dp.id
WHERE fo.order_date = @test_date AND fo.transaction_type = 'продажа';

-- 4. УДАЛЕНИЕ СТАРЫХ РЕЗУЛЬТАТОВ (для чистого теста)
SELECT '=== 4. ОЧИСТКА СТАРЫХ РЕЗУЛЬТАТОВ ===' as step;

DELETE FROM metrics_daily WHERE metric_date = @test_date;
SELECT 'Старые результаты удалены' as cleanup_status;

-- 5. РУЧНОЙ РАСЧЕТ (упрощенный)
SELECT '=== 5. РУЧНОЙ РАСЧЕТ ===' as step;

SELECT
    fo.client_id,
    
    -- Базовые метрики
    COUNT(*) AS orders_cnt,
    SUM(fo.qty * fo.price) AS revenue_sum,
    
    -- Себестоимость
    SUM(COALESCE(dp.cost_price * fo.qty, 0)) AS cogs_sum,
    
    -- Простая прибыль (без учета транзакций)
    (SUM(fo.qty * fo.price) - SUM(COALESCE(dp.cost_price * fo.qty, 0))) AS simple_profit,
    
    -- Простая маржа
    CASE 
        WHEN SUM(fo.qty * fo.price) > 0 
        THEN ROUND(
            (SUM(fo.qty * fo.price) - SUM(COALESCE(dp.cost_price * fo.qty, 0))) * 100.0 / 
            SUM(fo.qty * fo.price), 2
        )
        ELSE NULL 
    END AS simple_margin_percent

FROM fact_orders fo
LEFT JOIN dim_products dp ON fo.product_id = dp.id
WHERE fo.order_date = @test_date AND fo.transaction_type = 'продажа'
GROUP BY fo.client_id;

-- 6. ПОЛНЫЙ РАСЧЕТ С ТРАНЗАКЦИЯМИ (как в системе)
SELECT '=== 6. ПОЛНЫЙ РАСЧЕТ С ТРАНЗАКЦИЯМИ ===' as step;

-- Этот запрос имитирует логику из run_aggregation.py
INSERT INTO metrics_daily (
    client_id, metric_date, orders_cnt, revenue_sum, returns_sum, 
    cogs_sum, commission_sum, shipping_sum, other_expenses_sum, 
    profit_sum, margin_percent
)
SELECT
    fo.client_id,
    @test_date AS metric_date,
    
    -- Базовые метрики продаж
    COUNT(CASE WHEN fo.transaction_type = 'продажа' THEN fo.id END) AS orders_cnt,
    SUM(CASE WHEN fo.transaction_type = 'продажа' THEN (fo.qty * fo.price) ELSE 0 END) AS revenue_sum,
    SUM(CASE WHEN fo.transaction_type = 'возврат' THEN (fo.qty * fo.price) ELSE 0 END) AS returns_sum,
    
    -- Себестоимость проданных товаров (COGS)
    SUM(CASE 
        WHEN fo.transaction_type = 'продажа' AND dp.cost_price IS NOT NULL 
        THEN COALESCE(dp.cost_price * fo.qty, 0) 
        ELSE 0 
    END) AS cogs_sum,
    
    -- Комиссии маркетплейса и эквайринг
    COALESCE(commission_data.commission_sum, 0) AS commission_sum,
    
    -- Расходы на логистику и доставку
    COALESCE(logistics_data.shipping_sum, 0) AS shipping_sum,
    
    -- Прочие расходы
    COALESCE(other_data.other_expenses_sum, 0) AS other_expenses_sum,
    
    -- Расчет чистой прибыли
    (
        SUM(CASE WHEN fo.transaction_type = 'продажа' THEN (fo.qty * fo.price) ELSE 0 END) - -- Выручка
        SUM(CASE WHEN fo.transaction_type = 'возврат' THEN (fo.qty * fo.price) ELSE 0 END) - -- Возвраты
        SUM(CASE 
            WHEN fo.transaction_type = 'продажа' AND dp.cost_price IS NOT NULL 
            THEN COALESCE(dp.cost_price * fo.qty, 0) 
            ELSE 0 
        END) - -- Себестоимость
        COALESCE(commission_data.commission_sum, 0) - -- Комиссии
        COALESCE(logistics_data.shipping_sum, 0) - -- Логистика
        COALESCE(other_data.other_expenses_sum, 0) -- Прочие расходы
    ) AS profit_sum,
    
    -- Расчет процента маржинальности
    CASE 
        WHEN SUM(CASE WHEN fo.transaction_type = 'продажа' THEN (fo.qty * fo.price) ELSE 0 END) > 0 
        THEN (
            (
                SUM(CASE WHEN fo.transaction_type = 'продажа' THEN (fo.qty * fo.price) ELSE 0 END) - -- Выручка
                SUM(CASE WHEN fo.transaction_type = 'возврат' THEN (fo.qty * fo.price) ELSE 0 END) - -- Возвраты
                SUM(CASE 
                    WHEN fo.transaction_type = 'продажа' AND dp.cost_price IS NOT NULL 
                    THEN COALESCE(dp.cost_price * fo.qty, 0) 
                    ELSE 0 
                END) - -- Себестоимость
                COALESCE(commission_data.commission_sum, 0) - -- Комиссии
                COALESCE(logistics_data.shipping_sum, 0) - -- Логистика
                COALESCE(other_data.other_expenses_sum, 0) -- Прочие расходы
            ) / SUM(CASE WHEN fo.transaction_type = 'продажа' THEN (fo.qty * fo.price) ELSE 0 END)
        ) * 100
        ELSE NULL 
    END AS margin_percent

FROM fact_orders fo

-- JOIN с таблицей товаров для получения себестоимости
LEFT JOIN dim_products dp ON fo.product_id = dp.id

-- Подзапрос для агрегации комиссий и эквайринга
LEFT JOIN (
    SELECT 
        ft.client_id,
        SUM(ABS(ft.amount)) AS commission_sum
    FROM fact_transactions ft
    WHERE ft.transaction_date = @test_date
        AND (
            ft.transaction_type LIKE '%комиссия%' OR
            ft.transaction_type LIKE '%эквайринг%' OR
            ft.transaction_type LIKE '%commission%' OR
            ft.transaction_type LIKE '%fee%' OR
            ft.transaction_type LIKE '%OperationMarketplaceServiceItemFulfillment%'
        )
    GROUP BY ft.client_id
) commission_data ON fo.client_id = commission_data.client_id

-- Подзапрос для агрегации логистических расходов
LEFT JOIN (
    SELECT 
        ft.client_id,
        SUM(ABS(ft.amount)) AS shipping_sum
    FROM fact_transactions ft
    WHERE ft.transaction_date = @test_date
        AND (
            ft.transaction_type LIKE '%логистика%' OR
            ft.transaction_type LIKE '%доставка%' OR
            ft.transaction_type LIKE '%delivery%' OR
            ft.transaction_type LIKE '%shipping%' OR
            ft.transaction_type LIKE '%OperationMarketplaceServiceItemDeliveryToCustomer%'
        )
    GROUP BY ft.client_id
) logistics_data ON fo.client_id = logistics_data.client_id

-- Подзапрос для прочих расходов
LEFT JOIN (
    SELECT 
        ft.client_id,
        SUM(ABS(ft.amount)) AS other_expenses_sum
    FROM fact_transactions ft
    WHERE ft.transaction_date = @test_date
        AND ft.transaction_type NOT LIKE '%комиссия%'
        AND ft.transaction_type NOT LIKE '%эквайринг%'
        AND ft.transaction_type NOT LIKE '%commission%'
        AND ft.transaction_type NOT LIKE '%fee%'
        AND ft.transaction_type NOT LIKE '%логистика%'
        AND ft.transaction_type NOT LIKE '%доставка%'
        AND ft.transaction_type NOT LIKE '%delivery%'
        AND ft.transaction_type NOT LIKE '%shipping%'
        AND ft.transaction_type NOT LIKE '%возврат%'
        AND ft.transaction_type NOT LIKE '%return%'
        AND ft.transaction_type NOT LIKE '%OperationMarketplaceServiceItemFulfillment%'
        AND ft.transaction_type NOT LIKE '%OperationMarketplaceServiceItemDeliveryToCustomer%'
        AND ft.transaction_type NOT LIKE '%OperationMarketplaceServiceItemReturn%'
        AND ft.amount < 0 -- Только расходные операции
    GROUP BY ft.client_id
) other_data ON fo.client_id = other_data.client_id

WHERE fo.order_date = @test_date
GROUP BY fo.client_id;

-- 7. РЕЗУЛЬТАТЫ РАСЧЕТА
SELECT '=== 7. РЕЗУЛЬТАТЫ РАСЧЕТА ===' as step;

SELECT 
    client_id,
    orders_cnt,
    ROUND(revenue_sum, 2) as revenue_sum,
    ROUND(cogs_sum, 2) as cogs_sum,
    ROUND(commission_sum, 2) as commission_sum,
    ROUND(shipping_sum, 2) as shipping_sum,
    ROUND(other_expenses_sum, 2) as other_expenses_sum,
    ROUND(profit_sum, 2) as profit_sum,
    ROUND(margin_percent, 2) as margin_percent
FROM metrics_daily 
WHERE metric_date = @test_date;

-- 8. ИТОГОВАЯ СТАТИСТИКА
SELECT '=== 8. ИТОГОВАЯ СТАТИСТИКА ===' as step;

SELECT 
    COUNT(*) as clients_processed,
    SUM(orders_cnt) as total_orders,
    ROUND(SUM(revenue_sum), 2) as total_revenue,
    ROUND(SUM(profit_sum), 2) as total_profit,
    ROUND(SUM(profit_sum) * 100.0 / SUM(revenue_sum), 2) as overall_margin_percent
FROM metrics_daily 
WHERE metric_date = @test_date;

-- 9. ПРОВЕРКА КОРРЕКТНОСТИ
SELECT '=== 9. ПРОВЕРКА КОРРЕКТНОСТИ ===' as step;

SELECT 
    CASE 
        WHEN COUNT(*) = 0 THEN '❌ Нет результатов - проверьте данные'
        WHEN AVG(margin_percent) < -100 OR AVG(margin_percent) > 100 THEN '⚠️  Подозрительные значения маржи'
        WHEN SUM(revenue_sum) = 0 THEN '⚠️  Нулевая выручка'
        ELSE '✅ Результаты выглядят корректно'
    END as validation_status
FROM metrics_daily 
WHERE metric_date = @test_date;

SELECT CONCAT('🎉 ТЕСТ ЗАВЕРШЕН ДЛЯ ДАТЫ: ', @test_date) as completion_message;