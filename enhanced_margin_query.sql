-- ===================================================================
-- УЛУЧШЕННЫЙ SQL-ЗАПРОС ДЛЯ РАСЧЕТА ПОЛНОЙ МАРЖИНАЛЬНОСТИ
-- ===================================================================

-- Этот запрос объединяет данные из fact_orders, dim_products и fact_transactions
-- для расчета полной маржинальности с учетом всех расходов

-- Параметры: @date_param - дата для обработки (например, '2024-09-22')

SELECT
    fo.client_id,
    @date_param AS metric_date,
    
    -- Базовые метрики продаж
    COUNT(CASE WHEN fo.transaction_type = 'продажа' THEN fo.id END) AS orders_cnt,
    SUM(CASE WHEN fo.transaction_type = 'продажа' THEN (fo.qty * fo.price) ELSE 0 END) AS revenue_sum,
    SUM(CASE WHEN fo.transaction_type = 'возврат' THEN (fo.qty * fo.price) ELSE 0 END) AS returns_sum,
    
    -- Себестоимость проданных товаров (COGS - Cost of Goods Sold)
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
    WHERE ft.transaction_date = @date_param
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
    WHERE ft.transaction_date = @date_param
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
    WHERE ft.transaction_date = @date_param
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

WHERE fo.order_date = @date_param
GROUP BY fo.client_id;

-- ===================================================================
-- АЛЬТЕРНАТИВНЫЙ УПРОЩЕННЫЙ ЗАПРОС (если подзапросы работают медленно)
-- ===================================================================

/*
SELECT
    fo.client_id,
    @date_param AS metric_date,
    
    -- Базовые метрики
    COUNT(CASE WHEN fo.transaction_type = 'продажа' THEN fo.id END) AS orders_cnt,
    SUM(CASE WHEN fo.transaction_type = 'продажа' THEN (fo.qty * fo.price) ELSE 0 END) AS revenue_sum,
    SUM(CASE WHEN fo.transaction_type = 'возврат' THEN (fo.qty * fo.price) ELSE 0 END) AS returns_sum,
    
    -- Себестоимость
    SUM(CASE 
        WHEN fo.transaction_type = 'продажа' AND dp.cost_price IS NOT NULL 
        THEN dp.cost_price * fo.qty 
        ELSE 0 
    END) AS cogs_sum,
    
    -- Агрегация всех расходов из транзакций
    SUM(CASE 
        WHEN ft.transaction_type LIKE '%комиссия%' OR ft.transaction_type LIKE '%эквайринг%' 
        THEN ABS(ft.amount) 
        ELSE 0 
    END) AS commission_sum,
    
    SUM(CASE 
        WHEN ft.transaction_type LIKE '%логистика%' OR ft.transaction_type LIKE '%доставка%' 
        THEN ABS(ft.amount) 
        ELSE 0 
    END) AS shipping_sum,
    
    SUM(CASE 
        WHEN ft.amount < 0 
            AND ft.transaction_type NOT LIKE '%комиссия%'
            AND ft.transaction_type NOT LIKE '%эквайринг%'
            AND ft.transaction_type NOT LIKE '%логистика%'
            AND ft.transaction_type NOT LIKE '%доставка%'
            AND ft.transaction_type NOT LIKE '%возврат%'
        THEN ABS(ft.amount) 
        ELSE 0 
    END) AS other_expenses_sum,
    
    -- Чистая прибыль (упрощенный расчет)
    (
        SUM(CASE WHEN fo.transaction_type = 'продажа' THEN (fo.qty * fo.price) ELSE 0 END) -
        SUM(CASE WHEN fo.transaction_type = 'возврат' THEN (fo.qty * fo.price) ELSE 0 END) -
        SUM(CASE 
            WHEN fo.transaction_type = 'продажа' AND dp.cost_price IS NOT NULL 
            THEN dp.cost_price * fo.qty 
            ELSE 0 
        END) -
        SUM(CASE WHEN ft.amount < 0 THEN ABS(ft.amount) ELSE 0 END)
    ) AS profit_sum

FROM fact_orders fo
LEFT JOIN dim_products dp ON fo.product_id = dp.id
LEFT JOIN fact_transactions ft ON fo.order_id = ft.order_id AND ft.transaction_date = @date_param

WHERE fo.order_date = @date_param
GROUP BY fo.client_id;
*/

-- ===================================================================
-- ТЕСТОВЫЙ ЗАПРОС ДЛЯ ПРОВЕРКИ ДАННЫХ
-- ===================================================================

-- Используйте этот запрос для проверки данных перед внедрением основного запроса
-- Замените '2024-09-22' на актуальную дату с данными

/*
-- Проверяем наличие данных за конкретную дату
SELECT 'Проверка данных за дату' as check_type;

SELECT 
    'fact_orders' as table_name,
    COUNT(*) as records_count,
    SUM(CASE WHEN transaction_type = 'продажа' THEN qty * price ELSE 0 END) as total_revenue
FROM fact_orders 
WHERE order_date = '2024-09-22';

SELECT 
    'fact_transactions' as table_name,
    COUNT(*) as records_count,
    SUM(amount) as total_amount
FROM fact_transactions 
WHERE transaction_date = '2024-09-22';

SELECT 
    'dim_products with cost_price' as table_name,
    COUNT(*) as total_products,
    COUNT(cost_price) as products_with_cost
FROM dim_products;
*/