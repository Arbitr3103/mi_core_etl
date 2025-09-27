-- Исправление представления v_product_turnover_30d
-- Проблема: представление использует пустую таблицу stock_movements
-- Решение: использовать fact_orders для расчета продаж

-- Удаляем старое представление
DROP VIEW IF EXISTS v_product_turnover_30d;

-- Создаем исправленное представление с данными из fact_orders
CREATE VIEW v_product_turnover_30d AS
SELECT 
    dp.id as product_id,
    dp.sku_ozon,
    dp.sku_wb,
    dp.product_name,
    COALESCE(SUM(fo.qty), 0) as total_sold_30d,
    COALESCE(AVG(fo.qty), 0) as avg_daily_sales,
    COUNT(DISTINCT fo.order_date) as active_days,
    COALESCE(SUM(i.quantity_present), 0) as current_stock,
    CASE 
        WHEN SUM(fo.qty) > 0 THEN 
            COALESCE(SUM(i.quantity_present), 0) / (SUM(fo.qty) / 30.0)
        ELSE NULL 
    END as days_of_stock
FROM dim_products dp
LEFT JOIN fact_orders fo ON dp.id = fo.product_id 
    AND fo.order_date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
    AND fo.transaction_type IN ('продажа', 'sale', 'order')
LEFT JOIN inventory i ON dp.id = i.product_id
GROUP BY dp.id, dp.sku_ozon, dp.sku_wb, dp.product_name;

-- Проверяем результат
SELECT 
    'Исправлено представление v_product_turnover_30d' as status,
    COUNT(*) as total_products,
    SUM(CASE WHEN total_sold_30d > 0 THEN 1 ELSE 0 END) as products_with_sales
FROM v_product_turnover_30d;
