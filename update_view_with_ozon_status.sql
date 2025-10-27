-- Создать обновленное представление с поддержкой ozon_status
CREATE VIEW v_detailed_inventory AS
SELECT 
    dp.id AS product_id,
    dp.product_name,
    dp.ozon_status,
    dp.ozon_visibility,
    COALESCE(dp.sku_ozon, dp.sku_wb, dp.sku_internal) AS sku,
    dp.sku_ozon,
    dp.sku_wb,
    dp.sku_internal,
    dp.barcode,
    wsm.warehouse_name,
    COALESCE(i.cluster, 'Unknown') AS cluster,
    wsm.source AS marketplace_source,
    COALESCE(i.quantity_present, 0) AS quantity_present,
    COALESCE(i.quantity_reserved, 0) AS quantity_reserved,
    COALESCE(i.preparing_for_sale, 0) AS preparing_for_sale,
    COALESCE(i.in_supply_requests, 0) AS in_supply_requests,
    COALESCE(i.in_transit, 0) AS in_transit,
    -- Обновленный current_stock с учетом всех полей
    (COALESCE(i.available, 0) + 
     COALESCE(i.reserved, 0) + 
     COALESCE(i.preparing_for_sale, 0) + 
     COALESCE(i.in_requests, 0) + 
     COALESCE(i.in_transit, 0) + 
     COALESCE(i.in_inspection, 0) + 
     COALESCE(i.returns, 0) + 
     COALESCE(i.in_supply_requests, 0) + 
     COALESCE(i.returning_from_customers, 0) + 
     COALESCE(i.excess_from_supply, 0) + 
     COALESCE(i.awaiting_upd, 0)) AS current_stock,
    COALESCE(i.available, 0) AS available_stock,
    COALESCE(wsm.daily_sales_avg, 0) AS daily_sales_avg,
    COALESCE(wsm.sales_last_28_days, 0) AS sales_last_28_days,
    COALESCE(wsm.days_with_stock, 0) AS days_with_stock,
    COALESCE(wsm.days_without_sales, 0) AS days_without_sales,
    -- Days of stock calculation
    CASE 
        WHEN COALESCE(wsm.daily_sales_avg, 0) > 0 
        THEN ROUND((COALESCE(i.available, 0) + 
                    COALESCE(i.reserved, 0) + 
                    COALESCE(i.preparing_for_sale, 0) + 
                    COALESCE(i.in_requests, 0) + 
                    COALESCE(i.in_transit, 0) + 
                    COALESCE(i.in_inspection, 0) + 
                    COALESCE(i.returns, 0) + 
                    COALESCE(i.in_supply_requests, 0) + 
                    COALESCE(i.returning_from_customers, 0) + 
                    COALESCE(i.excess_from_supply, 0) + 
                    COALESCE(i.awaiting_upd, 0))::numeric / wsm.daily_sales_avg, 1)
        ELSE NULL
    END AS days_of_stock,
    -- Stock status
    CASE 
        WHEN COALESCE(wsm.daily_sales_avg, 0) = 0 THEN 'no_sales'
        WHEN (COALESCE(i.available, 0) + 
              COALESCE(i.reserved, 0) + 
              COALESCE(i.preparing_for_sale, 0) + 
              COALESCE(i.in_requests, 0) + 
              COALESCE(i.in_transit, 0) + 
              COALESCE(i.in_inspection, 0) + 
              COALESCE(i.returns, 0) + 
              COALESCE(i.in_supply_requests, 0) + 
              COALESCE(i.returning_from_customers, 0) + 
              COALESCE(i.excess_from_supply, 0) + 
              COALESCE(i.awaiting_upd, 0)) = 0 THEN 'out_of_stock'
        WHEN ((COALESCE(i.available, 0) + 
               COALESCE(i.reserved, 0) + 
               COALESCE(i.preparing_for_sale, 0) + 
               COALESCE(i.in_requests, 0) + 
               COALESCE(i.in_transit, 0) + 
               COALESCE(i.in_inspection, 0) + 
               COALESCE(i.returns, 0) + 
               COALESCE(i.in_supply_requests, 0) + 
               COALESCE(i.returning_from_customers, 0) + 
               COALESCE(i.excess_from_supply, 0) + 
               COALESCE(i.awaiting_upd, 0))::numeric / wsm.daily_sales_avg) < 14 THEN 'critical'
        WHEN ((COALESCE(i.available, 0) + 
               COALESCE(i.reserved, 0) + 
               COALESCE(i.preparing_for_sale, 0) + 
               COALESCE(i.in_requests, 0) + 
               COALESCE(i.in_transit, 0) + 
               COALESCE(i.in_inspection, 0) + 
               COALESCE(i.returns, 0) + 
               COALESCE(i.in_supply_requests, 0) + 
               COALESCE(i.returning_from_customers, 0) + 
               COALESCE(i.excess_from_supply, 0) + 
               COALESCE(i.awaiting_upd, 0))::numeric / wsm.daily_sales_avg) < 30 THEN 'low'
        WHEN ((COALESCE(i.available, 0) + 
               COALESCE(i.reserved, 0) + 
               COALESCE(i.preparing_for_sale, 0) + 
               COALESCE(i.in_requests, 0) + 
               COALESCE(i.in_transit, 0) + 
               COALESCE(i.in_inspection, 0) + 
               COALESCE(i.returns, 0) + 
               COALESCE(i.in_supply_requests, 0) + 
               COALESCE(i.returning_from_customers, 0) + 
               COALESCE(i.excess_from_supply, 0) + 
               COALESCE(i.awaiting_upd, 0))::numeric / wsm.daily_sales_avg) < 60 THEN 'normal'
        ELSE 'excess'
    END AS stock_status,
    -- Recommended quantity
    CASE 
        WHEN COALESCE(wsm.daily_sales_avg, 0) > 0 
        THEN GREATEST(0, ROUND(wsm.daily_sales_avg * 60, 0) - 
                         (COALESCE(i.available, 0) + 
                          COALESCE(i.reserved, 0) + 
                          COALESCE(i.preparing_for_sale, 0) + 
                          COALESCE(i.in_requests, 0) + 
                          COALESCE(i.in_transit, 0) + 
                          COALESCE(i.in_inspection, 0) + 
                          COALESCE(i.returns, 0) + 
                          COALESCE(i.in_supply_requests, 0) + 
                          COALESCE(i.returning_from_customers, 0) + 
                          COALESCE(i.excess_from_supply, 0) + 
                          COALESCE(i.awaiting_upd, 0)))
        ELSE 0
    END AS recommended_qty,
    -- Recommended value
    CASE 
        WHEN COALESCE(wsm.daily_sales_avg, 0) > 0 AND COALESCE(dp.cost_price, 0) > 0 
        THEN GREATEST(0, ROUND(wsm.daily_sales_avg * 60, 0) - 
                         (COALESCE(i.available, 0) + 
                          COALESCE(i.reserved, 0) + 
                          COALESCE(i.preparing_for_sale, 0) + 
                          COALESCE(i.in_requests, 0) + 
                          COALESCE(i.in_transit, 0) + 
                          COALESCE(i.in_inspection, 0) + 
                          COALESCE(i.returns, 0) + 
                          COALESCE(i.in_supply_requests, 0) + 
                          COALESCE(i.returning_from_customers, 0) + 
                          COALESCE(i.excess_from_supply, 0) + 
                          COALESCE(i.awaiting_upd, 0))) * dp.cost_price
        ELSE 0
    END AS recommended_value,
    -- Urgency score
    CASE 
        WHEN COALESCE(wsm.daily_sales_avg, 0) = 0 THEN 0
        WHEN (COALESCE(i.available, 0) + 
              COALESCE(i.reserved, 0) + 
              COALESCE(i.preparing_for_sale, 0) + 
              COALESCE(i.in_requests, 0) + 
              COALESCE(i.in_transit, 0) + 
              COALESCE(i.in_inspection, 0) + 
              COALESCE(i.returns, 0) + 
              COALESCE(i.in_supply_requests, 0) + 
              COALESCE(i.returning_from_customers, 0) + 
              COALESCE(i.excess_from_supply, 0) + 
              COALESCE(i.awaiting_upd, 0)) = 0 THEN 100
        WHEN ((COALESCE(i.available, 0) + 
               COALESCE(i.reserved, 0) + 
               COALESCE(i.preparing_for_sale, 0) + 
               COALESCE(i.in_requests, 0) + 
               COALESCE(i.in_transit, 0) + 
               COALESCE(i.in_inspection, 0) + 
               COALESCE(i.returns, 0) + 
               COALESCE(i.in_supply_requests, 0) + 
               COALESCE(i.returning_from_customers, 0) + 
               COALESCE(i.excess_from_supply, 0) + 
               COALESCE(i.awaiting_upd, 0))::numeric / wsm.daily_sales_avg) < 7 THEN 95
        WHEN ((COALESCE(i.available, 0) + 
               COALESCE(i.reserved, 0) + 
               COALESCE(i.preparing_for_sale, 0) + 
               COALESCE(i.in_requests, 0) + 
               COALESCE(i.in_transit, 0) + 
               COALESCE(i.in_inspection, 0) + 
               COALESCE(i.returns, 0) + 
               COALESCE(i.in_supply_requests, 0) + 
               COALESCE(i.returning_from_customers, 0) + 
               COALESCE(i.excess_from_supply, 0) + 
               COALESCE(i.awaiting_upd, 0))::numeric / wsm.daily_sales_avg) < 14 THEN 80
        WHEN ((COALESCE(i.available, 0) + 
               COALESCE(i.reserved, 0) + 
               COALESCE(i.preparing_for_sale, 0) + 
               COALESCE(i.in_requests, 0) + 
               COALESCE(i.in_transit, 0) + 
               COALESCE(i.in_inspection, 0) + 
               COALESCE(i.returns, 0) + 
               COALESCE(i.in_supply_requests, 0) + 
               COALESCE(i.returning_from_customers, 0) + 
               COALESCE(i.excess_from_supply, 0) + 
               COALESCE(i.awaiting_upd, 0))::numeric / wsm.daily_sales_avg) < 21 THEN 60
        WHEN ((COALESCE(i.available, 0) + 
               COALESCE(i.reserved, 0) + 
               COALESCE(i.preparing_for_sale, 0) + 
               COALESCE(i.in_requests, 0) + 
               COALESCE(i.in_transit, 0) + 
               COALESCE(i.in_inspection, 0) + 
               COALESCE(i.returns, 0) + 
               COALESCE(i.in_supply_requests, 0) + 
               COALESCE(i.returning_from_customers, 0) + 
               COALESCE(i.excess_from_supply, 0) + 
               COALESCE(i.awaiting_upd, 0))::numeric / wsm.daily_sales_avg) < 30 THEN 40
        WHEN ((COALESCE(i.available, 0) + 
               COALESCE(i.reserved, 0) + 
               COALESCE(i.preparing_for_sale, 0) + 
               COALESCE(i.in_requests, 0) + 
               COALESCE(i.in_transit, 0) + 
               COALESCE(i.in_inspection, 0) + 
               COALESCE(i.returns, 0) + 
               COALESCE(i.in_supply_requests, 0) + 
               COALESCE(i.returning_from_customers, 0) + 
               COALESCE(i.excess_from_supply, 0) + 
               COALESCE(i.awaiting_upd, 0))::numeric / wsm.daily_sales_avg) < 60 THEN 20
        ELSE 10
    END AS urgency_score,
    -- Stockout risk
    CASE 
        WHEN COALESCE(wsm.daily_sales_avg, 0) = 0 THEN 0
        WHEN (COALESCE(i.available, 0) + 
              COALESCE(i.reserved, 0) + 
              COALESCE(i.preparing_for_sale, 0) + 
              COALESCE(i.in_requests, 0) + 
              COALESCE(i.in_transit, 0) + 
              COALESCE(i.in_inspection, 0) + 
              COALESCE(i.returns, 0) + 
              COALESCE(i.in_supply_requests, 0) + 
              COALESCE(i.returning_from_customers, 0) + 
              COALESCE(i.excess_from_supply, 0) + 
              COALESCE(i.awaiting_upd, 0)) = 0 THEN 100
        ELSE LEAST(100, ROUND(100 * (1 - (COALESCE(i.available, 0) + 
                                           COALESCE(i.reserved, 0) + 
                                           COALESCE(i.preparing_for_sale, 0) + 
                                           COALESCE(i.in_requests, 0) + 
                                           COALESCE(i.in_transit, 0) + 
                                           COALESCE(i.in_inspection, 0) + 
                                           COALESCE(i.returns, 0) + 
                                           COALESCE(i.in_supply_requests, 0) + 
                                           COALESCE(i.returning_from_customers, 0) + 
                                           COALESCE(i.excess_from_supply, 0) + 
                                           COALESCE(i.awaiting_upd, 0))::numeric / 
                                      GREATEST(1, wsm.daily_sales_avg * 30)), 0))
    END AS stockout_risk,
    dp.cost_price,
    dp.margin_percent,
    -- Current stock value
    (COALESCE(i.available, 0) + 
     COALESCE(i.reserved, 0) + 
     COALESCE(i.preparing_for_sale, 0) + 
     COALESCE(i.in_requests, 0) + 
     COALESCE(i.in_transit, 0) + 
     COALESCE(i.in_inspection, 0) + 
     COALESCE(i.returns, 0) + 
     COALESCE(i.in_supply_requests, 0) + 
     COALESCE(i.returning_from_customers, 0) + 
     COALESCE(i.excess_from_supply, 0) + 
     COALESCE(i.awaiting_upd, 0))::numeric * COALESCE(dp.cost_price, 0) AS current_stock_value,
    -- Turnover rate
    CASE 
        WHEN (COALESCE(i.available, 0) + 
              COALESCE(i.reserved, 0) + 
              COALESCE(i.preparing_for_sale, 0) + 
              COALESCE(i.in_requests, 0) + 
              COALESCE(i.in_transit, 0) + 
              COALESCE(i.in_inspection, 0) + 
              COALESCE(i.returns, 0) + 
              COALESCE(i.in_supply_requests, 0) + 
              COALESCE(i.returning_from_customers, 0) + 
              COALESCE(i.excess_from_supply, 0) + 
              COALESCE(i.awaiting_upd, 0)) > 0 
             AND COALESCE(wsm.daily_sales_avg, 0) > 0 
        THEN ROUND(wsm.daily_sales_avg * 365 / (COALESCE(i.available, 0) + 
                                                  COALESCE(i.reserved, 0) + 
                                                  COALESCE(i.preparing_for_sale, 0) + 
                                                  COALESCE(i.in_requests, 0) + 
                                                  COALESCE(i.in_transit, 0) + 
                                                  COALESCE(i.in_inspection, 0) + 
                                                  COALESCE(i.returns, 0) + 
                                                  COALESCE(i.in_supply_requests, 0) + 
                                                  COALESCE(i.returning_from_customers, 0) + 
                                                  COALESCE(i.excess_from_supply, 0) + 
                                                  COALESCE(i.awaiting_upd, 0))::numeric, 2)
        ELSE 0
    END AS turnover_rate,
    -- Sales trend
    CASE 
        WHEN COALESCE(wsm.days_without_sales, 0) > 14 THEN 'declining'
        WHEN COALESCE(wsm.daily_sales_avg, 0) > 0 
             AND COALESCE(wsm.sales_last_28_days, 0)::numeric > (wsm.daily_sales_avg * 28 * 1.1) THEN 'growing'
        WHEN COALESCE(wsm.daily_sales_avg, 0) > 0 
             AND COALESCE(wsm.sales_last_28_days, 0)::numeric < (wsm.daily_sales_avg * 28 * 0.9) THEN 'declining'
        ELSE 'stable'
    END AS sales_trend,
    i.updated_at AS inventory_updated_at,
    wsm.calculated_at AS metrics_calculated_at,
    COALESCE(GREATEST(i.updated_at, wsm.calculated_at), wsm.calculated_at, i.updated_at) AS last_updated,
    CASE 
        WHEN COALESCE(wsm.days_without_sales, 0) > 0 
        THEN CURRENT_DATE - INTERVAL '1 day' * wsm.days_without_sales
        ELSE NULL
    END AS last_sale_date
FROM warehouse_sales_metrics wsm
LEFT JOIN dim_products dp ON wsm.product_id = dp.id
LEFT JOIN inventory i ON wsm.product_id = i.product_id 
                      AND wsm.warehouse_name = i.warehouse_name 
                      AND wsm.source = i.source
WHERE dp.id IS NOT NULL 
  AND (COALESCE(wsm.sales_last_28_days, 0) > 0 
       OR COALESCE(wsm.daily_sales_avg, 0) > 0 
       OR (COALESCE(i.available, 0) + 
           COALESCE(i.reserved, 0) + 
           COALESCE(i.preparing_for_sale, 0) + 
           COALESCE(i.in_requests, 0) + 
           COALESCE(i.in_transit, 0) + 
           COALESCE(i.in_inspection, 0) + 
           COALESCE(i.returns, 0) + 
           COALESCE(i.in_supply_requests, 0) + 
           COALESCE(i.returning_from_customers, 0) + 
           COALESCE(i.excess_from_supply, 0) + 
           COALESCE(i.awaiting_upd, 0)) > 0);