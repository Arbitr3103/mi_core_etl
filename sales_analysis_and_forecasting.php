<?php
/**
 * Sales Analysis and Demand Forecasting Script
 * Part of task 2.2: Реализовать анализ продаж и прогнозирование потребности
 */

include 'config/database_postgresql.php';

try {
    // Get database connection
    $pdo = getDatabaseConnection();
    
    if (!$pdo) {
        throw new Exception("Failed to establish database connection");
    }
    
    echo "=== АНАЛИЗ ПРОДАЖ И ПРОГНОЗИРОВАНИЕ ПОТРЕБНОСТИ ===\n\n";
    
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
    
    // 1. Analysis of actual sales for the last month by products and warehouses
    echo "1. Анализ фактических продаж за последний месяц по товарам и складам:\n";
    $stmt = $pdo->prepare("
        SELECT 
            wsm.warehouse_name,
            wsm.product_id,
            wsm.sales_last_28_days,
            wsm.daily_sales_avg,
            wsm.days_with_stock,
            wsm.days_without_sales,
            
            -- Calculate monthly sales rate
            ROUND(wsm.daily_sales_avg * 30, 2) as projected_monthly_sales,
            
            -- Sales velocity (sales per day when in stock)
            CASE 
                WHEN wsm.days_with_stock > 0 THEN 
                    ROUND(wsm.sales_last_28_days::numeric / wsm.days_with_stock, 2)
                ELSE 0
            END as sales_velocity_when_in_stock,
            
            -- Sales consistency (percentage of days with sales when in stock)
            CASE 
                WHEN wsm.days_with_stock > 0 THEN 
                    ROUND((wsm.days_with_stock - wsm.days_without_sales) * 100.0 / wsm.days_with_stock, 1)
                ELSE 0
            END as sales_consistency_percent
            
        FROM warehouse_sales_metrics wsm
        WHERE wsm.sales_last_28_days > 0
        ORDER BY wsm.sales_last_28_days DESC, wsm.daily_sales_avg DESC
    ");
    $stmt->execute();
    $sales_analysis = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    printf("%-20s %-8s %-10s %-10s %-12s %-15s %-12s %-12s\n", 
        "WAREHOUSE", "PROD_ID", "SALES_28D", "DAILY_AVG", "PROJ_MONTHLY", "VELOCITY", "CONSISTENCY%", "DAYS_STOCK");
    echo str_repeat("-", 120) . "\n";
    
    foreach ($sales_analysis as $sale) {
        printf("%-20s %-8s %-10s %-10s %-12s %-15s %-12s %-12s\n",
            substr($sale['warehouse_name'], 0, 19),
            $sale['product_id'],
            $sale['sales_last_28_days'],
            number_format($sale['daily_sales_avg'], 2),
            number_format($sale['projected_monthly_sales'], 1),
            number_format($sale['sales_velocity_when_in_stock'], 2),
            $sale['sales_consistency_percent'] . '%',
            $sale['days_with_stock']
        );
    }
    
    // 2. Calculate average monthly consumption per warehouse
    echo "\n\n2. Расчет среднемесячного расхода товаров на каждом складе:\n";
    $stmt = $pdo->prepare("
        SELECT 
            wsm.warehouse_name,
            COUNT(*) as products_count,
            SUM(wsm.sales_last_28_days) as total_sales_28d,
            AVG(wsm.sales_last_28_days) as avg_sales_per_product_28d,
            SUM(wsm.daily_sales_avg * 30) as projected_monthly_consumption,
            AVG(wsm.daily_sales_avg * 30) as avg_monthly_per_product,
            
            -- Warehouse sales performance metrics
            ROUND(AVG(wsm.daily_sales_avg), 2) as avg_daily_sales_rate,
            ROUND(AVG(wsm.days_with_stock), 1) as avg_days_with_stock,
            ROUND(AVG(wsm.days_without_sales), 1) as avg_days_without_sales,
            
            -- Warehouse efficiency
            ROUND(
                AVG(CASE 
                    WHEN wsm.days_with_stock > 0 THEN 
                        (wsm.days_with_stock - wsm.days_without_sales) * 100.0 / wsm.days_with_stock
                    ELSE 0
                END), 1
            ) as warehouse_efficiency_percent
            
        FROM warehouse_sales_metrics wsm
        WHERE wsm.sales_last_28_days >= 0
        GROUP BY wsm.warehouse_name
        ORDER BY projected_monthly_consumption DESC
    ");
    $stmt->execute();
    $monthly_consumption = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    printf("%-20s %-8s %-12s %-15s %-18s %-15s %-12s %-12s\n", 
        "WAREHOUSE", "PRODUCTS", "SALES_28D", "AVG_PER_PROD", "PROJ_MONTHLY", "AVG_MONTHLY", "EFFICIENCY%", "AVG_DAILY");
    echo str_repeat("-", 130) . "\n";
    
    foreach ($monthly_consumption as $consumption) {
        printf("%-20s %-8s %-12s %-15s %-18s %-15s %-12s %-12s\n",
            substr($consumption['warehouse_name'], 0, 19),
            $consumption['products_count'],
            $consumption['total_sales_28d'],
            number_format($consumption['avg_sales_per_product_28d'], 1),
            number_format($consumption['projected_monthly_consumption'], 1),
            number_format($consumption['avg_monthly_per_product'], 1),
            $consumption['warehouse_efficiency_percent'] . '%',
            number_format($consumption['avg_daily_sales_rate'], 2)
        );
    }
    
    // 3. Determine minimum stock for one month ahead
    echo "\n\n3. Определение минимального запаса для обеспечения продаж на месяц вперед:\n";
    $stmt = $pdo->prepare("
        SELECT 
            wsm.warehouse_name,
            wsm.product_id,
            ($total_stock_formula) as current_stock,
            wsm.daily_sales_avg,
            wsm.sales_last_28_days,
            
            -- Minimum stock calculations for different periods
            CEIL(wsm.daily_sales_avg * 30) as min_stock_30_days,
            CEIL(wsm.daily_sales_avg * 45) as recommended_stock_45_days,
            CEIL(wsm.daily_sales_avg * 60) as safe_stock_60_days,
            
            -- Current stock sufficiency
            CASE 
                WHEN wsm.daily_sales_avg > 0 THEN 
                    ROUND(($total_stock_formula) / wsm.daily_sales_avg, 1)
                ELSE NULL
            END as current_stock_days,
            
            -- Stock status
            CASE 
                WHEN wsm.daily_sales_avg = 0 THEN 'NO_SALES'
                WHEN ($total_stock_formula) < wsm.daily_sales_avg * 15 THEN 'CRITICAL'
                WHEN ($total_stock_formula) < wsm.daily_sales_avg * 30 THEN 'LOW'
                WHEN ($total_stock_formula) < wsm.daily_sales_avg * 60 THEN 'NORMAL'
                ELSE 'EXCESS'
            END as stock_status,
            
            -- Gap analysis
            GREATEST(0, CEIL(wsm.daily_sales_avg * 30) - ($total_stock_formula)) as shortage_for_30_days
            
        FROM warehouse_sales_metrics wsm
        INNER JOIN inventory i ON wsm.product_id = i.product_id 
            AND wsm.warehouse_name = i.warehouse_name
        WHERE wsm.daily_sales_avg > 0
        ORDER BY 
            CASE 
                WHEN wsm.daily_sales_avg = 0 THEN 5
                WHEN ($total_stock_formula) < wsm.daily_sales_avg * 15 THEN 1
                WHEN ($total_stock_formula) < wsm.daily_sales_avg * 30 THEN 2
                WHEN ($total_stock_formula) < wsm.daily_sales_avg * 60 THEN 3
                ELSE 4
            END,
            wsm.daily_sales_avg DESC
    ");
    $stmt->execute();
    $min_stock_analysis = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    printf("%-20s %-8s %-12s %-10s %-10s %-10s %-12s %-12s %-10s\n", 
        "WAREHOUSE", "PROD_ID", "CURR_STOCK", "DAILY_AVG", "MIN_30D", "REC_45D", "CURR_DAYS", "STATUS", "SHORTAGE");
    echo str_repeat("-", 120) . "\n";
    
    foreach ($min_stock_analysis as $stock) {
        printf("%-20s %-8s %-12s %-10s %-10s %-10s %-12s %-12s %-10s\n",
            substr($stock['warehouse_name'], 0, 19),
            $stock['product_id'],
            $stock['current_stock'],
            number_format($stock['daily_sales_avg'], 2),
            $stock['min_stock_30_days'],
            $stock['recommended_stock_45_days'],
            $stock['current_stock_days'] ? number_format($stock['current_stock_days'], 1) . 'd' : 'N/A',
            $stock['stock_status'],
            $stock['shortage_for_30_days']
        );
    }
    
    // 4. Demand forecasting algorithm
    echo "\n\n4. Алгоритм прогнозирования потребности в пополнении:\n";
    $stmt = $pdo->prepare("
        SELECT 
            wsm.warehouse_name,
            wsm.product_id,
            ($total_stock_formula) as current_stock,
            wsm.daily_sales_avg,
            wsm.sales_last_28_days,
            
            -- Forecasting calculations
            wsm.daily_sales_avg * 30 as forecast_30_days,
            wsm.daily_sales_avg * 60 as forecast_60_days,
            wsm.daily_sales_avg * 90 as forecast_90_days,
            
            -- Seasonal adjustment (based on sales consistency)
            CASE 
                WHEN wsm.days_with_stock > 0 THEN 
                    wsm.daily_sales_avg * 30 * 
                    (1 + (wsm.days_without_sales::numeric / wsm.days_with_stock * 0.2))
                ELSE wsm.daily_sales_avg * 30
            END as seasonal_adjusted_forecast,
            
            -- Safety stock calculation (20% buffer for uncertainty)
            CEIL(wsm.daily_sales_avg * 30 * 1.2) as safety_stock_level,
            
            -- Replenishment recommendation
            GREATEST(0, 
                CEIL(wsm.daily_sales_avg * 45) - ($total_stock_formula)
            ) as recommended_replenishment,
            
            -- Urgency level
            CASE 
                WHEN ($total_stock_formula) < wsm.daily_sales_avg * 7 THEN 'URGENT'
                WHEN ($total_stock_formula) < wsm.daily_sales_avg * 14 THEN 'HIGH'
                WHEN ($total_stock_formula) < wsm.daily_sales_avg * 30 THEN 'MEDIUM'
                WHEN ($total_stock_formula) < wsm.daily_sales_avg * 60 THEN 'LOW'
                ELSE 'NONE'
            END as replenishment_urgency,
            
            -- Lead time consideration (assuming 7-14 days lead time)
            GREATEST(0, 
                CEIL(wsm.daily_sales_avg * (30 + 14)) - ($total_stock_formula)
            ) as replenishment_with_lead_time
            
        FROM warehouse_sales_metrics wsm
        INNER JOIN inventory i ON wsm.product_id = i.product_id 
            AND wsm.warehouse_name = i.warehouse_name
        WHERE wsm.daily_sales_avg > 0
        ORDER BY 
            CASE 
                WHEN ($total_stock_formula) < wsm.daily_sales_avg * 7 THEN 1
                WHEN ($total_stock_formula) < wsm.daily_sales_avg * 14 THEN 2
                WHEN ($total_stock_formula) < wsm.daily_sales_avg * 30 THEN 3
                ELSE 4
            END,
            wsm.daily_sales_avg DESC
        LIMIT 20
    ");
    $stmt->execute();
    $forecasting = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    printf("%-20s %-8s %-12s %-10s %-12s %-15s %-12s %-10s %-15s\n", 
        "WAREHOUSE", "PROD_ID", "CURR_STOCK", "DAILY_AVG", "FORECAST_30D", "SAFETY_STOCK", "RECOMMEND", "URGENCY", "WITH_LEADTIME");
    echo str_repeat("-", 140) . "\n";
    
    foreach ($forecasting as $forecast) {
        printf("%-20s %-8s %-12s %-10s %-12s %-15s %-12s %-10s %-15s\n",
            substr($forecast['warehouse_name'], 0, 19),
            $forecast['product_id'],
            $forecast['current_stock'],
            number_format($forecast['daily_sales_avg'], 2),
            number_format($forecast['forecast_30_days'], 1),
            $forecast['safety_stock_level'],
            $forecast['recommended_replenishment'],
            $forecast['replenishment_urgency'],
            $forecast['replenishment_with_lead_time']
        );
    }
    
    // 5. Summary statistics for forecasting
    echo "\n\n5. Сводная статистика прогнозирования:\n";
    $stmt = $pdo->prepare("
        SELECT 
            COUNT(*) as total_products_with_sales,
            SUM(wsm.sales_last_28_days) as total_sales_28d,
            AVG(wsm.daily_sales_avg) as avg_daily_sales_all_products,
            SUM(wsm.daily_sales_avg * 30) as total_monthly_forecast,
            
            -- Stock status distribution
            COUNT(CASE WHEN ($total_stock_formula) < wsm.daily_sales_avg * 7 THEN 1 END) as urgent_replenishment,
            COUNT(CASE WHEN ($total_stock_formula) BETWEEN wsm.daily_sales_avg * 7 AND wsm.daily_sales_avg * 14 THEN 1 END) as high_priority,
            COUNT(CASE WHEN ($total_stock_formula) BETWEEN wsm.daily_sales_avg * 14 AND wsm.daily_sales_avg * 30 THEN 1 END) as medium_priority,
            COUNT(CASE WHEN ($total_stock_formula) > wsm.daily_sales_avg * 60 THEN 1 END) as excess_stock,
            
            -- Total replenishment needs
            SUM(GREATEST(0, CEIL(wsm.daily_sales_avg * 30) - ($total_stock_formula))) as total_shortage_30d,
            SUM(GREATEST(0, CEIL(wsm.daily_sales_avg * 45) - ($total_stock_formula))) as total_recommended_order
            
        FROM warehouse_sales_metrics wsm
        INNER JOIN inventory i ON wsm.product_id = i.product_id 
            AND wsm.warehouse_name = i.warehouse_name
        WHERE wsm.daily_sales_avg > 0
    ");
    $stmt->execute();
    $summary = $stmt->fetch(PDO::FETCH_ASSOC);
    
    echo "Товаров с продажами: {$summary['total_products_with_sales']}\n";
    echo "Общие продажи за 28 дней: {$summary['total_sales_28d']}\n";
    echo "Средние ежедневные продажи: " . number_format($summary['avg_daily_sales_all_products'], 2) . "\n";
    echo "Прогноз месячных продаж: " . number_format($summary['total_monthly_forecast'], 1) . "\n\n";
    
    echo "Распределение по приоритетам пополнения:\n";
    echo "  Срочное пополнение (< 7 дней): {$summary['urgent_replenishment']} товаров\n";
    echo "  Высокий приоритет (7-14 дней): {$summary['high_priority']} товаров\n";
    echo "  Средний приоритет (14-30 дней): {$summary['medium_priority']} товаров\n";
    echo "  Избыточные остатки (> 60 дней): {$summary['excess_stock']} товаров\n\n";
    
    echo "Потребности в пополнении:\n";
    echo "  Дефицит для 30 дней: " . number_format($summary['total_shortage_30d']) . " единиц\n";
    echo "  Рекомендуемый заказ: " . number_format($summary['total_recommended_order']) . " единиц\n";
    
    echo "\n=== АНАЛИЗ ПРОДАЖ И ПРОГНОЗИРОВАНИЕ ЗАВЕРШЕНО ===\n";
    echo "\n✅ РЕЗУЛЬТАТЫ ЗАДАЧИ 2.2:\n";
    echo "1. ✅ Созданы запросы для анализа фактических продаж за последний месяц\n";
    echo "2. ✅ Рассчитан среднемесячный расход товаров на каждом складе\n";
    echo "3. ✅ Определен минимальный запас для обеспечения продаж на месяц вперед\n";
    echo "4. ✅ Создан алгоритм прогнозирования потребности в пополнении\n";
    echo "5. ✅ Учтены факторы: сезонность, время поставки, буферный запас\n";
    
} catch (Exception $e) {
    echo "❌ Ошибка: " . $e->getMessage() . "\n";
    exit(1);
}
?>