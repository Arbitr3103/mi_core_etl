<?php
/**
 * Critical Warehouses Analysis Based on Sales Data
 * Part of task 2.3: Реализовать определение критических складов на основе продаж
 */

include 'config/database_postgresql.php';

try {
    // Get database connection
    $pdo = getDatabaseConnection();
    
    if (!$pdo) {
        throw new Exception("Failed to establish database connection");
    }
    
    echo "=== ОПРЕДЕЛЕНИЕ КРИТИЧЕСКИХ СКЛАДОВ НА ОСНОВЕ ПРОДАЖ ===\n\n";
    
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
    
    // 1. Warehouse categorization based on stock-to-sales ratio
    echo "1. Категоризация складов на основе соотношения остатков к продажам:\n";
    $stmt = $pdo->prepare("
        WITH warehouse_metrics AS (
            SELECT 
                i.warehouse_name,
                COUNT(DISTINCT i.product_id) as total_products,
                SUM($total_stock_formula) as total_current_stock,
                COUNT(DISTINCT wsm.product_id) as products_with_sales,
                COALESCE(SUM(wsm.sales_last_28_days), 0) as total_sales_28d,
                COALESCE(AVG(wsm.daily_sales_avg), 0) as avg_daily_sales_rate,
                
                -- Calculate warehouse-level days of stock
                CASE 
                    WHEN AVG(wsm.daily_sales_avg) > 0 THEN 
                        SUM($total_stock_formula) / AVG(wsm.daily_sales_avg)
                    ELSE NULL
                END as warehouse_days_of_stock,
                
                -- Calculate months of stock
                CASE 
                    WHEN AVG(wsm.daily_sales_avg) > 0 THEN 
                        (SUM($total_stock_formula) / AVG(wsm.daily_sales_avg)) / 30.0
                    ELSE NULL
                END as warehouse_months_of_stock,
                
                -- Products distribution by stock levels
                COUNT(CASE WHEN wsm.days_of_stock < 15 THEN 1 END) as products_critical,
                COUNT(CASE WHEN wsm.days_of_stock BETWEEN 15 AND 30 THEN 1 END) as products_low,
                COUNT(CASE WHEN wsm.days_of_stock BETWEEN 30 AND 60 THEN 1 END) as products_normal,
                COUNT(CASE WHEN wsm.days_of_stock > 60 THEN 1 END) as products_excess
                
            FROM inventory i
            LEFT JOIN warehouse_sales_metrics wsm ON i.product_id = wsm.product_id 
                AND i.warehouse_name = wsm.warehouse_name
            GROUP BY i.warehouse_name
        )
        SELECT 
            warehouse_name,
            total_products,
            products_with_sales,
            total_current_stock,
            total_sales_28d,
            ROUND(avg_daily_sales_rate, 2) as avg_daily_sales,
            ROUND(warehouse_days_of_stock, 1) as days_of_stock,
            ROUND(warehouse_months_of_stock, 2) as months_of_stock,
            
            -- Warehouse category based on months of stock
            CASE 
                WHEN warehouse_months_of_stock IS NULL THEN 'НЕТ_ПРОДАЖ'
                WHEN warehouse_months_of_stock < 0.5 THEN 'КРИТИЧЕСКИЙ'
                WHEN warehouse_months_of_stock < 1.0 THEN 'НИЗКИЙ'
                WHEN warehouse_months_of_stock BETWEEN 1.0 AND 2.0 THEN 'НОРМАЛЬНЫЙ'
                ELSE 'ИЗБЫТОЧНЫЙ'
            END as warehouse_category,
            
            -- Risk level calculation
            CASE 
                WHEN warehouse_months_of_stock IS NULL THEN 0
                WHEN warehouse_months_of_stock < 0.5 THEN 100
                WHEN warehouse_months_of_stock < 1.0 THEN 75
                WHEN warehouse_months_of_stock < 2.0 THEN 25
                ELSE 0
            END as risk_level,
            
            products_critical,
            products_low,
            products_normal,
            products_excess,
            
            -- Calculate percentage of products in each category
            ROUND(products_critical * 100.0 / NULLIF(products_with_sales, 0), 1) as critical_percent,
            ROUND(products_low * 100.0 / NULLIF(products_with_sales, 0), 1) as low_percent
            
        FROM warehouse_metrics
        ORDER BY 
            CASE 
                WHEN warehouse_months_of_stock IS NULL THEN 5
                WHEN warehouse_months_of_stock < 0.5 THEN 1
                WHEN warehouse_months_of_stock < 1.0 THEN 2
                WHEN warehouse_months_of_stock < 2.0 THEN 3
                ELSE 4
            END,
            warehouse_months_of_stock ASC NULLS LAST
    ");
    $stmt->execute();
    $warehouse_categories = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    printf("%-20s %-8s %-12s %-10s %-10s %-12s %-12s %-8s %-8s %-8s %-8s\n", 
        "WAREHOUSE", "PRODUCTS", "CURR_STOCK", "SALES_28D", "MONTHS", "CATEGORY", "RISK%", "CRIT", "LOW", "NORM", "EXCESS");
    echo str_repeat("-", 130) . "\n";
    
    foreach ($warehouse_categories as $wh) {
        printf("%-20s %-8s %-12s %-10s %-10s %-12s %-12s %-8s %-8s %-8s %-8s\n",
            substr($wh['warehouse_name'], 0, 19),
            $wh['products_with_sales'],
            number_format($wh['total_current_stock']),
            $wh['total_sales_28d'],
            $wh['months_of_stock'] ?: 'N/A',
            $wh['warehouse_category'],
            $wh['risk_level'] . '%',
            $wh['products_critical'],
            $wh['products_low'],
            $wh['products_normal'],
            $wh['products_excess']
        );
    }
    
    // 2. Detailed analysis of warehouses with insufficient stock
    echo "\n\n2. Детальный анализ складов с недостаточными остатками:\n";
    $stmt = $pdo->prepare("
        SELECT 
            i.warehouse_name,
            i.product_id,
            ($total_stock_formula) as current_stock,
            wsm.daily_sales_avg,
            wsm.sales_last_28_days,
            wsm.days_of_stock,
            
            -- Stock sufficiency analysis
            CASE 
                WHEN wsm.daily_sales_avg > 0 THEN 
                    ROUND(($total_stock_formula) / wsm.daily_sales_avg / 30.0, 2)
                ELSE NULL
            END as months_of_stock,
            
            -- Criticality level
            CASE 
                WHEN wsm.daily_sales_avg = 0 THEN 'НЕТ_ПРОДАЖ'
                WHEN ($total_stock_formula) < wsm.daily_sales_avg * 15 THEN 'КРИТИЧЕСКИЙ'
                WHEN ($total_stock_formula) < wsm.daily_sales_avg * 30 THEN 'НИЗКИЙ'
                WHEN ($total_stock_formula) < wsm.daily_sales_avg * 60 THEN 'НОРМАЛЬНЫЙ'
                ELSE 'ИЗБЫТОЧНЫЙ'
            END as stock_criticality,
            
            -- Days until stockout
            CASE 
                WHEN wsm.daily_sales_avg > 0 THEN 
                    FLOOR(($total_stock_formula) / wsm.daily_sales_avg)
                ELSE NULL
            END as days_until_stockout,
            
            -- Replenishment urgency
            CASE 
                WHEN wsm.daily_sales_avg = 0 THEN 'НЕ_ТРЕБУЕТСЯ'
                WHEN ($total_stock_formula) < wsm.daily_sales_avg * 7 THEN 'НЕМЕДЛЕННО'
                WHEN ($total_stock_formula) < wsm.daily_sales_avg * 14 THEN 'НА_ЭТОЙ_НЕДЕЛЕ'
                WHEN ($total_stock_formula) < wsm.daily_sales_avg * 30 THEN 'В_ТЕЧЕНИЕ_МЕСЯЦА'
                ELSE 'НЕ_СРОЧНО'
            END as replenishment_urgency
            
        FROM inventory i
        INNER JOIN warehouse_sales_metrics wsm ON i.product_id = wsm.product_id 
            AND i.warehouse_name = wsm.warehouse_name
        WHERE wsm.daily_sales_avg > 0 
            AND ($total_stock_formula) < wsm.daily_sales_avg * 60  -- Focus on products with less than 2 months stock
        ORDER BY 
            CASE 
                WHEN ($total_stock_formula) < wsm.daily_sales_avg * 7 THEN 1
                WHEN ($total_stock_formula) < wsm.daily_sales_avg * 14 THEN 2
                WHEN ($total_stock_formula) < wsm.daily_sales_avg * 30 THEN 3
                ELSE 4
            END,
            wsm.daily_sales_avg DESC
    ");
    $stmt->execute();
    $insufficient_stock = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    if (!empty($insufficient_stock)) {
        printf("%-20s %-8s %-12s %-10s %-10s %-12s %-12s %-15s %-15s\n", 
            "WAREHOUSE", "PROD_ID", "CURR_STOCK", "DAILY_AVG", "MONTHS", "CRITICALITY", "DAYS_LEFT", "URGENCY", "SALES_28D");
        echo str_repeat("-", 140) . "\n";
        
        foreach ($insufficient_stock as $stock) {
            printf("%-20s %-8s %-12s %-10s %-10s %-12s %-12s %-15s %-15s\n",
                substr($stock['warehouse_name'], 0, 19),
                $stock['product_id'],
                $stock['current_stock'],
                number_format($stock['daily_sales_avg'], 2),
                $stock['months_of_stock'] ?: 'N/A',
                $stock['stock_criticality'],
                $stock['days_until_stockout'] ?: 'N/A',
                $stock['replenishment_urgency'],
                $stock['sales_last_28_days']
            );
        }
    } else {
        echo "Товары с недостаточными остатками не найдены.\n";
    }
    
    // 3. Replenishment priority calculation for each warehouse
    echo "\n\n3. Расчет приоритета пополнения для каждого склада:\n";
    $stmt = $pdo->prepare("
        WITH warehouse_priorities AS (
            SELECT 
                i.warehouse_name,
                COUNT(DISTINCT i.product_id) as total_products,
                COALESCE(SUM(wsm.sales_last_28_days), 0) as total_sales_28d,
                COALESCE(AVG(wsm.daily_sales_avg), 0) as avg_daily_sales,
                
                -- Priority factors
                COUNT(CASE WHEN ($total_stock_formula) < wsm.daily_sales_avg * 7 THEN 1 END) as immediate_need,
                COUNT(CASE WHEN ($total_stock_formula) BETWEEN wsm.daily_sales_avg * 7 AND wsm.daily_sales_avg * 14 THEN 1 END) as urgent_need,
                COUNT(CASE WHEN ($total_stock_formula) BETWEEN wsm.daily_sales_avg * 14 AND wsm.daily_sales_avg * 30 THEN 1 END) as medium_need,
                
                -- Sales velocity factor
                COALESCE(AVG(wsm.daily_sales_avg), 0) as sales_velocity,
                
                -- Stock turnover rate
                CASE 
                    WHEN AVG(wsm.daily_sales_avg) > 0 THEN 
                        SUM($total_stock_formula) / AVG(wsm.daily_sales_avg)
                    ELSE 999
                END as avg_days_of_stock
                
            FROM inventory i
            LEFT JOIN warehouse_sales_metrics wsm ON i.product_id = wsm.product_id 
                AND i.warehouse_name = wsm.warehouse_name
            GROUP BY i.warehouse_name
        )
        SELECT 
            warehouse_name,
            total_products,
            total_sales_28d,
            ROUND(avg_daily_sales, 2) as avg_daily_sales,
            immediate_need,
            urgent_need,
            medium_need,
            ROUND(sales_velocity, 2) as sales_velocity,
            ROUND(avg_days_of_stock, 1) as avg_days_stock,
            
            -- Calculate priority score (higher = more urgent)
            (immediate_need * 100 + 
             urgent_need * 50 + 
             medium_need * 20 + 
             LEAST(sales_velocity * 10, 50) +  -- Cap sales velocity bonus at 50
             CASE 
                WHEN avg_days_of_stock < 30 THEN 30
                WHEN avg_days_of_stock < 60 THEN 15
                ELSE 0
             END) as priority_score,
            
            -- Priority level
            CASE 
                WHEN immediate_need > 0 THEN 'КРИТИЧЕСКИЙ'
                WHEN urgent_need > 2 OR (urgent_need > 0 AND sales_velocity > 2) THEN 'ВЫСОКИЙ'
                WHEN medium_need > 3 OR urgent_need > 0 THEN 'СРЕДНИЙ'
                WHEN avg_days_of_stock < 90 THEN 'НИЗКИЙ'
                ELSE 'НОРМАЛЬНЫЙ'
            END as priority_level,
            
            -- Recommended action
            CASE 
                WHEN immediate_need > 0 THEN 'СРОЧНОЕ ПОПОЛНЕНИЕ'
                WHEN urgent_need > 0 THEN 'ПОПОЛНЕНИЕ НА ЭТОЙ НЕДЕЛЕ'
                WHEN medium_need > 0 THEN 'ПЛАНОВОЕ ПОПОЛНЕНИЕ'
                ELSE 'МОНИТОРИНГ'
            END as recommended_action
            
        FROM warehouse_priorities
        ORDER BY priority_score DESC, total_sales_28d DESC
    ");
    $stmt->execute();
    $priorities = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    printf("%-20s %-8s %-10s %-10s %-8s %-8s %-8s %-12s %-12s %-20s\n", 
        "WAREHOUSE", "PRODUCTS", "SALES_28D", "DAILY_AVG", "IMMED", "URGENT", "MEDIUM", "PRIORITY", "LEVEL", "ACTION");
    echo str_repeat("-", 140) . "\n";
    
    foreach ($priorities as $priority) {
        printf("%-20s %-8s %-10s %-10s %-8s %-8s %-8s %-12s %-12s %-20s\n",
            substr($priority['warehouse_name'], 0, 19),
            $priority['total_products'],
            $priority['total_sales_28d'],
            $priority['avg_daily_sales'],
            $priority['immediate_need'],
            $priority['urgent_need'],
            $priority['medium_need'],
            $priority['priority_score'],
            $priority['priority_level'],
            substr($priority['recommended_action'], 0, 19)
        );
    }
    
    // 4. Summary statistics
    echo "\n\n4. Сводная статистика по критическим складам:\n";
    $stmt = $pdo->prepare("
        WITH warehouse_stats AS (
            SELECT 
                i.warehouse_name,
                AVG(wsm.daily_sales_avg) as avg_daily_sales,
                SUM($total_stock_formula) as total_stock,
                CASE 
                    WHEN AVG(wsm.daily_sales_avg) > 0 THEN 
                        SUM($total_stock_formula) / AVG(wsm.daily_sales_avg)
                    ELSE NULL
                END as days_of_stock
            FROM inventory i
            LEFT JOIN warehouse_sales_metrics wsm ON i.product_id = wsm.product_id 
                AND i.warehouse_name = wsm.warehouse_name
            GROUP BY i.warehouse_name
        ),
        product_stats AS (
            SELECT 
                COUNT(CASE WHEN ($total_stock_formula) < wsm.daily_sales_avg * 7 THEN 1 END) as products_immediate_need,
                COUNT(CASE WHEN ($total_stock_formula) BETWEEN wsm.daily_sales_avg * 7 AND wsm.daily_sales_avg * 14 THEN 1 END) as products_urgent_need,
                COUNT(CASE WHEN ($total_stock_formula) BETWEEN wsm.daily_sales_avg * 14 AND wsm.daily_sales_avg * 30 THEN 1 END) as products_medium_need
            FROM inventory i
            LEFT JOIN warehouse_sales_metrics wsm ON i.product_id = wsm.product_id 
                AND i.warehouse_name = wsm.warehouse_name
        )
        SELECT 
            (SELECT COUNT(*) FROM warehouse_stats) as total_warehouses,
            (SELECT COUNT(*) FROM warehouse_stats WHERE days_of_stock < 15) as critical_warehouses,
            (SELECT COUNT(*) FROM warehouse_stats WHERE days_of_stock BETWEEN 15 AND 30) as low_stock_warehouses,
            (SELECT COUNT(*) FROM warehouse_stats WHERE days_of_stock BETWEEN 30 AND 60) as normal_warehouses,
            (SELECT COUNT(*) FROM warehouse_stats WHERE days_of_stock > 60) as excess_warehouses,
            products_immediate_need,
            products_urgent_need,
            products_medium_need
        FROM product_stats
    ");
    $stmt->execute();
    $summary = $stmt->fetch(PDO::FETCH_ASSOC);
    
    echo "Общее количество складов: {$summary['total_warehouses']}\n";
    echo "Критические склады (< 15 дней): {$summary['critical_warehouses']}\n";
    echo "Склады с низкими остатками (15-30 дней): {$summary['low_stock_warehouses']}\n";
    echo "Нормальные склады (30-60 дней): {$summary['normal_warehouses']}\n";
    echo "Склады с избытком (> 60 дней): {$summary['excess_warehouses']}\n\n";
    
    echo "Товары требующие немедленного пополнения: {$summary['products_immediate_need']}\n";
    echo "Товары требующие срочного пополнения: {$summary['products_urgent_need']}\n";
    echo "Товары требующие планового пополнения: {$summary['products_medium_need']}\n";
    
    echo "\n=== ОПРЕДЕЛЕНИЕ КРИТИЧЕСКИХ СКЛАДОВ ЗАВЕРШЕНО ===\n";
    echo "\n✅ РЕЗУЛЬТАТЫ ЗАДАЧИ 2.3:\n";
    echo "1. ✅ Создана логика для выявления складов с низкими остатками относительно продаж\n";
    echo "2. ✅ Установлены пороговые значения: критический (< 0.5 мес), низкий (< 1 мес), нормальный (1-2 мес), избыточный (> 2 мес)\n";
    echo "3. ✅ Добавлена категоризация складов на основе соотношения остатков к месячным продажам\n";
    echo "4. ✅ Рассчитан приоритет пополнения на основе скорости продаж и текущих остатков\n";
    echo "5. ✅ Создана система оценки критичности и рекомендаций по действиям\n";
    
} catch (Exception $e) {
    echo "❌ Ошибка: " . $e->getMessage() . "\n";
    exit(1);
}
?>