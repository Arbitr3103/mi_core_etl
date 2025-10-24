<?php
/**
 * Replenishment Recommendations System
 * Part of task 2.4: Создать систему рекомендаций по пополнению
 */

include 'config/database_postgresql.php';

try {
    // Get database connection
    $pdo = getDatabaseConnection();
    
    if (!$pdo) {
        throw new Exception("Failed to establish database connection");
    }
    
    echo "=== СИСТЕМА РЕКОМЕНДАЦИЙ ПО ПОПОЛНЕНИЮ ===\n\n";
    
    // Define the total stock calculation formula
    $total_stock_formula = "
        COALESCE(i.quantity_present, 0) + 
        COALESCE(i.quantity_reserved, 0) + 
        COALESCE(i.preparing_for_sale, 0) + 
        COALESCE(i.in_supply_requests, 0) + 
        COALESCE(i.in_transit, 0) + 
        COALESCE(i.in_inspection, 0) + 
        COALESCE(i.returning_from_customers, 0) + 
        COALESCE(i.expiring_soon, 0) + 
        COALESCE(i.defective, 0) + 
        COALESCE(i.excess_from_supply, 0) + 
        COALESCE(i.awaiting_upd, 0) + 
        COALESCE(i.preparing_for_removal, 0)
    ";
    
    // 1. Calculate recommended order quantities based on monthly sales
    echo "1. Расчет рекомендуемого количества для заказа на основе месячных продаж:\n";
    $stmt = $pdo->prepare("
        SELECT 
            i.warehouse_name,
            i.product_id,
            ($total_stock_formula) as current_stock,
            wsm.daily_sales_avg,
            wsm.sales_last_28_days,
            
            -- Base calculations
            ROUND(wsm.daily_sales_avg * 30, 0) as monthly_sales_forecast,
            ROUND(wsm.daily_sales_avg * 60, 0) as two_month_target_stock,
            
            -- Recommended order calculation: (average_monthly_sales * 2) - current_stock
            GREATEST(0, 
                ROUND(wsm.daily_sales_avg * 60, 0) - ($total_stock_formula)
            ) as recommended_order_qty,
            
            -- Alternative calculation with safety buffer (25%)
            GREATEST(0, 
                ROUND(wsm.daily_sales_avg * 60 * 1.25, 0) - ($total_stock_formula)
            ) as recommended_order_with_buffer,
            
            -- Lead time consideration (14 days)
            GREATEST(0, 
                ROUND(wsm.daily_sales_avg * (60 + 14), 0) - ($total_stock_formula)
            ) as recommended_order_with_leadtime,
            
            -- Order value estimation (assuming average price of 1000 rubles)
            GREATEST(0, 
                ROUND(wsm.daily_sales_avg * 60, 0) - ($total_stock_formula)
            ) * 1000 as estimated_order_value,
            
            -- Current stock sufficiency in days
            CASE 
                WHEN wsm.daily_sales_avg > 0 THEN 
                    ROUND(($total_stock_formula) / wsm.daily_sales_avg, 1)
                ELSE NULL
            END as current_stock_days
            
        FROM inventory i
        INNER JOIN warehouse_sales_metrics wsm ON i.product_id = wsm.product_id 
            AND i.warehouse_name = wsm.warehouse_name
        WHERE wsm.daily_sales_avg > 0
        ORDER BY 
            GREATEST(0, ROUND(wsm.daily_sales_avg * 60, 0) - ($total_stock_formula)) DESC,
            wsm.daily_sales_avg DESC
        LIMIT 20
    ");
    $stmt->execute();
    $order_recommendations = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    printf("%-20s %-8s %-12s %-10s %-12s %-15s %-15s %-15s %-12s\n", 
        "WAREHOUSE", "PROD_ID", "CURR_STOCK", "DAILY_AVG", "MONTHLY", "RECOMMEND", "WITH_BUFFER", "WITH_LEADTIME", "CURR_DAYS");
    echo str_repeat("-", 140) . "\n";
    
    foreach ($order_recommendations as $rec) {
        printf("%-20s %-8s %-12s %-10s %-12s %-15s %-15s %-15s %-12s\n",
            substr($rec['warehouse_name'], 0, 19),
            $rec['product_id'],
            $rec['current_stock'],
            number_format($rec['daily_sales_avg'], 2),
            $rec['monthly_sales_forecast'],
            $rec['recommended_order_qty'],
            $rec['recommended_order_with_buffer'],
            $rec['recommended_order_with_leadtime'],
            $rec['current_stock_days'] ? number_format($rec['current_stock_days'], 1) . 'd' : 'N/A'
        );
    }
    
    // 2. Determine criticality of replenishment
    echo "\n\n2. Определение критичности пополнения:\n";
    $stmt = $pdo->prepare("
        SELECT 
            i.warehouse_name,
            i.product_id,
            ($total_stock_formula) as current_stock,
            wsm.daily_sales_avg,
            wsm.sales_last_28_days,
            
            -- Days until stockout
            CASE 
                WHEN wsm.daily_sales_avg > 0 THEN 
                    FLOOR(($total_stock_formula) / wsm.daily_sales_avg)
                ELSE NULL
            END as days_until_stockout,
            
            -- Criticality level based on days until stockout
            CASE 
                WHEN wsm.daily_sales_avg = 0 THEN 'НЕТ_ПРОДАЖ'
                WHEN ($total_stock_formula) < wsm.daily_sales_avg * 3 THEN 'КРИТИЧЕСКИЙ'
                WHEN ($total_stock_formula) < wsm.daily_sales_avg * 7 THEN 'СРОЧНО'
                WHEN ($total_stock_formula) < wsm.daily_sales_avg * 14 THEN 'В_ТЕЧЕНИЕ_НЕДЕЛИ'
                WHEN ($total_stock_formula) < wsm.daily_sales_avg * 30 THEN 'ПЛАНОВОЕ'
                ELSE 'НЕ_ТРЕБУЕТСЯ'
            END as criticality_level,
            
            -- Recommended action timeline
            CASE 
                WHEN wsm.daily_sales_avg = 0 THEN 'МОНИТОРИНГ'
                WHEN ($total_stock_formula) < wsm.daily_sales_avg * 3 THEN 'ЗАКАЗАТЬ_СЕГОДНЯ'
                WHEN ($total_stock_formula) < wsm.daily_sales_avg * 7 THEN 'ЗАКАЗАТЬ_НА_ЭТОЙ_НЕДЕЛЕ'
                WHEN ($total_stock_formula) < wsm.daily_sales_avg * 14 THEN 'ЗАКАЗАТЬ_В_ТЕЧЕНИЕ_2_НЕДЕЛЬ'
                WHEN ($total_stock_formula) < wsm.daily_sales_avg * 30 THEN 'ВКЛЮЧИТЬ_В_ПЛАН_ЗАКУПОК'
                ELSE 'ДОСТАТОЧНО_ЗАПАСОВ'
            END as recommended_action,
            
            -- Risk assessment
            CASE 
                WHEN wsm.daily_sales_avg = 0 THEN 0
                WHEN ($total_stock_formula) < wsm.daily_sales_avg * 3 THEN 100
                WHEN ($total_stock_formula) < wsm.daily_sales_avg * 7 THEN 80
                WHEN ($total_stock_formula) < wsm.daily_sales_avg * 14 THEN 60
                WHEN ($total_stock_formula) < wsm.daily_sales_avg * 30 THEN 40
                ELSE 0
            END as risk_score,
            
            -- Recommended order quantity
            GREATEST(0, 
                ROUND(wsm.daily_sales_avg * 60, 0) - ($total_stock_formula)
            ) as recommended_qty
            
        FROM inventory i
        INNER JOIN warehouse_sales_metrics wsm ON i.product_id = wsm.product_id 
            AND i.warehouse_name = wsm.warehouse_name
        WHERE wsm.daily_sales_avg > 0
        ORDER BY 
            CASE 
                WHEN ($total_stock_formula) < wsm.daily_sales_avg * 3 THEN 1
                WHEN ($total_stock_formula) < wsm.daily_sales_avg * 7 THEN 2
                WHEN ($total_stock_formula) < wsm.daily_sales_avg * 14 THEN 3
                WHEN ($total_stock_formula) < wsm.daily_sales_avg * 30 THEN 4
                ELSE 5
            END,
            wsm.daily_sales_avg DESC
        LIMIT 25
    ");
    $stmt->execute();
    $criticality_analysis = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    printf("%-20s %-8s %-12s %-10s %-12s %-15s %-25s %-8s %-12s\n", 
        "WAREHOUSE", "PROD_ID", "CURR_STOCK", "DAILY_AVG", "DAYS_LEFT", "CRITICALITY", "ACTION", "RISK%", "REC_QTY");
    echo str_repeat("-", 150) . "\n";
    
    foreach ($criticality_analysis as $crit) {
        printf("%-20s %-8s %-12s %-10s %-12s %-15s %-25s %-8s %-12s\n",
            substr($crit['warehouse_name'], 0, 19),
            $crit['product_id'],
            $crit['current_stock'],
            number_format($crit['daily_sales_avg'], 2),
            $crit['days_until_stockout'] ?: 'N/A',
            $crit['criticality_level'],
            substr($crit['recommended_action'], 0, 24),
            $crit['risk_score'] . '%',
            $crit['recommended_qty']
        );
    }
    
    // 3. Seasonal and lead time adjustments
    echo "\n\n3. Учет времени поставки и сезонности продаж:\n";
    $stmt = $pdo->prepare("
        SELECT 
            i.warehouse_name,
            i.product_id,
            ($total_stock_formula) as current_stock,
            wsm.daily_sales_avg,
            wsm.sales_last_28_days,
            wsm.days_with_stock,
            wsm.days_without_sales,
            
            -- Seasonal adjustment factor based on sales consistency
            CASE 
                WHEN wsm.days_with_stock > 0 THEN 
                    1 + (wsm.days_without_sales::numeric / wsm.days_with_stock * 0.3)
                ELSE 1.0
            END as seasonal_factor,
            
            -- Lead time scenarios (7, 14, 21 days)
            GREATEST(0, 
                ROUND(wsm.daily_sales_avg * (60 + 7), 0) - ($total_stock_formula)
            ) as order_qty_7d_leadtime,
            
            GREATEST(0, 
                ROUND(wsm.daily_sales_avg * (60 + 14), 0) - ($total_stock_formula)
            ) as order_qty_14d_leadtime,
            
            GREATEST(0, 
                ROUND(wsm.daily_sales_avg * (60 + 21), 0) - ($total_stock_formula)
            ) as order_qty_21d_leadtime,
            
            -- Seasonal adjusted recommendation
            GREATEST(0, 
                ROUND(wsm.daily_sales_avg * 60 * 
                    (1 + (wsm.days_without_sales::numeric / GREATEST(wsm.days_with_stock, 1) * 0.3)), 0
                ) - ($total_stock_formula)
            ) as seasonal_adjusted_qty,
            
            -- Final recommendation with all factors
            GREATEST(0, 
                ROUND(wsm.daily_sales_avg * (60 + 14) * 
                    (1 + (wsm.days_without_sales::numeric / GREATEST(wsm.days_with_stock, 1) * 0.3)) * 1.1, 0
                ) - ($total_stock_formula)
            ) as final_recommendation
            
        FROM inventory i
        INNER JOIN warehouse_sales_metrics wsm ON i.product_id = wsm.product_id 
            AND i.warehouse_name = wsm.warehouse_name
        WHERE wsm.daily_sales_avg > 0
        ORDER BY 
            GREATEST(0, 
                ROUND(wsm.daily_sales_avg * (60 + 14) * 
                    (1 + (wsm.days_without_sales::numeric / GREATEST(wsm.days_with_stock, 1) * 0.3)) * 1.1, 0
                ) - ($total_stock_formula)
            ) DESC
        LIMIT 15
    ");
    $stmt->execute();
    $adjusted_recommendations = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    printf("%-20s %-8s %-12s %-10s %-12s %-12s %-12s %-12s %-15s\n", 
        "WAREHOUSE", "PROD_ID", "CURR_STOCK", "DAILY_AVG", "7D_LEAD", "14D_LEAD", "21D_LEAD", "SEASONAL", "FINAL_REC");
    echo str_repeat("-", 130) . "\n";
    
    foreach ($adjusted_recommendations as $adj) {
        printf("%-20s %-8s %-12s %-10s %-12s %-12s %-12s %-12s %-15s\n",
            substr($adj['warehouse_name'], 0, 19),
            $adj['product_id'],
            $adj['current_stock'],
            number_format($adj['daily_sales_avg'], 2),
            $adj['order_qty_7d_leadtime'],
            $adj['order_qty_14d_leadtime'],
            $adj['order_qty_21d_leadtime'],
            $adj['seasonal_adjusted_qty'],
            $adj['final_recommendation']
        );
    }
    
    // 4. Summary replenishment report by warehouse
    echo "\n\n4. Сводный отчет по пополнению по складам:\n";
    $stmt = $pdo->prepare("
        WITH replenishment_summary AS (
            SELECT 
                i.warehouse_name,
                COUNT(*) as total_products,
                SUM(($total_stock_formula)) as total_current_stock,
                SUM(wsm.sales_last_28_days) as total_sales_28d,
                AVG(wsm.daily_sales_avg) as avg_daily_sales,
                
                -- Replenishment needs by urgency
                COUNT(CASE WHEN ($total_stock_formula) < wsm.daily_sales_avg * 7 THEN 1 END) as urgent_products,
                COUNT(CASE WHEN ($total_stock_formula) BETWEEN wsm.daily_sales_avg * 7 AND wsm.daily_sales_avg * 14 THEN 1 END) as high_priority,
                COUNT(CASE WHEN ($total_stock_formula) BETWEEN wsm.daily_sales_avg * 14 AND wsm.daily_sales_avg * 30 THEN 1 END) as medium_priority,
                
                -- Total recommended orders
                SUM(GREATEST(0, ROUND(wsm.daily_sales_avg * 60, 0) - ($total_stock_formula))) as total_recommended_qty,
                
                -- Estimated order value (assuming 1000 rubles per unit)
                SUM(GREATEST(0, ROUND(wsm.daily_sales_avg * 60, 0) - ($total_stock_formula))) * 1000 as estimated_order_value,
                
                -- Warehouse efficiency metrics
                ROUND(AVG(CASE 
                    WHEN wsm.daily_sales_avg > 0 THEN 
                        ($total_stock_formula) / wsm.daily_sales_avg
                    ELSE NULL
                END), 1) as avg_days_of_stock
                
            FROM inventory i
            INNER JOIN warehouse_sales_metrics wsm ON i.product_id = wsm.product_id 
                AND i.warehouse_name = wsm.warehouse_name
            WHERE wsm.daily_sales_avg > 0
            GROUP BY i.warehouse_name
        )
        SELECT 
            warehouse_name,
            total_products,
            total_current_stock,
            total_sales_28d,
            ROUND(avg_daily_sales, 2) as avg_daily_sales,
            urgent_products,
            high_priority,
            medium_priority,
            total_recommended_qty,
            ROUND(estimated_order_value / 1000000.0, 2) as estimated_value_millions,
            avg_days_of_stock,
            
            -- Priority ranking
            CASE 
                WHEN urgent_products > 0 THEN 'КРИТИЧЕСКИЙ'
                WHEN high_priority > 2 THEN 'ВЫСОКИЙ'
                WHEN medium_priority > 3 THEN 'СРЕДНИЙ'
                ELSE 'НИЗКИЙ'
            END as warehouse_priority
            
        FROM replenishment_summary
        ORDER BY 
            urgent_products DESC,
            high_priority DESC,
            total_recommended_qty DESC
    ");
    $stmt->execute();
    $warehouse_summary = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    printf("%-20s %-8s %-12s %-10s %-10s %-8s %-8s %-8s %-12s %-12s %-12s\n", 
        "WAREHOUSE", "PRODUCTS", "CURR_STOCK", "SALES_28D", "DAILY_AVG", "URGENT", "HIGH", "MEDIUM", "REC_QTY", "VALUE_MLN", "PRIORITY");
    echo str_repeat("-", 140) . "\n";
    
    $total_recommended = 0;
    $total_value = 0;
    
    foreach ($warehouse_summary as $summary) {
        printf("%-20s %-8s %-12s %-10s %-10s %-8s %-8s %-8s %-12s %-12s %-12s\n",
            substr($summary['warehouse_name'], 0, 19),
            $summary['total_products'],
            number_format($summary['total_current_stock']),
            $summary['total_sales_28d'],
            $summary['avg_daily_sales'],
            $summary['urgent_products'],
            $summary['high_priority'],
            $summary['medium_priority'],
            number_format($summary['total_recommended_qty']),
            $summary['estimated_value_millions'] . 'M',
            $summary['warehouse_priority']
        );
        
        $total_recommended += $summary['total_recommended_qty'];
        $total_value += $summary['estimated_value_millions'];
    }
    
    echo str_repeat("-", 140) . "\n";
    echo "ИТОГО рекомендуемый заказ: " . number_format($total_recommended) . " единиц\n";
    echo "Общая стоимость заказов: " . number_format($total_value, 2) . " млн рублей\n";
    
    echo "\n=== СИСТЕМА РЕКОМЕНДАЦИЙ ПО ПОПОЛНЕНИЮ ЗАВЕРШЕНА ===\n";
    echo "\n✅ РЕЗУЛЬТАТЫ ЗАДАЧИ 2.4:\n";
    echo "1. ✅ Рассчитано рекомендуемое количество на основе формулы: (среднемесячные_продажи × 2) - текущий_остаток\n";
    echo "2. ✅ Определена критичность пополнения: срочно, в течение недели, плановое\n";
    echo "3. ✅ Учтено время поставки (7-21 дней) и сезонность продаж\n";
    echo "4. ✅ Создан сводный отчет с приоритизацией складов и оценкой стоимости\n";
    echo "5. ✅ Система готова для интеграции в дашборд мониторинга\n";
    
} catch (Exception $e) {
    echo "❌ Ошибка: " . $e->getMessage() . "\n";
    exit(1);
}
?>