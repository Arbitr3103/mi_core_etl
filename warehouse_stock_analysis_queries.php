<?php
/**
 * Base queries for warehouse stock analysis with sales data
 * Part of task 2.1: Проанализировать структуру данных продаж и создать базовые запросы
 */

include 'config/database_postgresql.php';

try {
    // Get database connection
    $pdo = getDatabaseConnection();
    
    if (!$pdo) {
        throw new Exception("Failed to establish database connection");
    }
    
    echo "=== БАЗОВЫЕ ЗАПРОСЫ ДЛЯ АНАЛИЗА ОСТАТКОВ ПО СКЛАДАМ ===\n\n";
    
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
    
    // 1. Comprehensive warehouse analysis with sales data
    echo "1. Комплексный анализ складов с данными продаж:\n";
    $stmt = $pdo->prepare("
        SELECT 
            i.warehouse_name,
            COUNT(DISTINCT i.product_id) as total_products,
            COUNT(DISTINCT CASE WHEN ($total_stock_formula) > 0 THEN i.product_id END) as active_products,
            COUNT(DISTINCT CASE WHEN ($total_stock_formula) = 0 THEN i.product_id END) as inactive_products,
            SUM($total_stock_formula) as total_stock_all_products,
            AVG($total_stock_formula) as avg_stock_per_product,
            
            -- Sales metrics
            COUNT(DISTINCT wsm.product_id) as products_with_sales_data,
            COALESCE(SUM(wsm.sales_last_28_days), 0) as total_sales_28d,
            COALESCE(AVG(wsm.daily_sales_avg), 0) as avg_daily_sales,
            COALESCE(AVG(wsm.days_of_stock), 0) as avg_days_of_stock,
            
            -- Replenishment indicators
            COUNT(CASE WHEN wsm.days_of_stock < 7 THEN 1 END) as critical_products,
            COUNT(CASE WHEN wsm.days_of_stock BETWEEN 7 AND 30 THEN 1 END) as low_stock_products,
            COUNT(CASE WHEN wsm.days_of_stock > 60 THEN 1 END) as excess_stock_products,
            
            -- Activity percentage
            ROUND(
                COUNT(DISTINCT CASE WHEN ($total_stock_formula) > 0 THEN i.product_id END) * 100.0 / 
                NULLIF(COUNT(DISTINCT i.product_id), 0), 2
            ) as active_percentage
            
        FROM inventory i
        LEFT JOIN warehouse_sales_metrics wsm ON i.product_id = wsm.product_id 
            AND i.warehouse_name = wsm.warehouse_name
        GROUP BY i.warehouse_name
        ORDER BY total_sales_28d DESC, active_percentage DESC
    ");
    $stmt->execute();
    $warehouse_analysis = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    printf("%-20s %-8s %-8s %-8s %-12s %-10s %-10s %-8s %-8s %-8s\n", 
        "WAREHOUSE", "TOTAL", "ACTIVE", "INACTIVE", "TOTAL_STOCK", "SALES_28D", "AVG_DAYS", "CRITICAL", "LOW", "EXCESS");
    echo str_repeat("-", 120) . "\n";
    
    foreach ($warehouse_analysis as $wh) {
        printf("%-20s %-8s %-8s %-8s %-12s %-10s %-10s %-8s %-8s %-8s\n",
            substr($wh['warehouse_name'], 0, 19),
            $wh['total_products'],
            $wh['active_products'],
            $wh['inactive_products'],
            number_format($wh['total_stock_all_products']),
            $wh['total_sales_28d'],
            number_format($wh['avg_days_of_stock'], 1),
            $wh['critical_products'],
            $wh['low_stock_products'],
            $wh['excess_stock_products']
        );
    }
    
    // 2. Products requiring urgent replenishment by warehouse
    echo "\n\n2. Товары требующие срочного пополнения по складам:\n";
    $stmt = $pdo->prepare("
        SELECT 
            i.warehouse_name,
            i.product_id,
            ($total_stock_formula) as current_stock,
            wsm.daily_sales_avg,
            wsm.sales_last_28_days,
            wsm.days_of_stock,
            wsm.liquidity_status,
            
            -- Replenishment calculations
            CASE 
                WHEN wsm.daily_sales_avg > 0 THEN 
                    GREATEST(0, CEIL(wsm.daily_sales_avg * 30) - ($total_stock_formula))
                ELSE 0
            END as recommended_order_qty,
            
            CASE 
                WHEN wsm.days_of_stock < 7 THEN 'СРОЧНО'
                WHEN wsm.days_of_stock < 14 THEN 'В ТЕЧЕНИЕ НЕДЕЛИ'
                WHEN wsm.days_of_stock < 30 THEN 'ПЛАНОВОЕ'
                ELSE 'НЕ ТРЕБУЕТСЯ'
            END as urgency_level
            
        FROM inventory i
        INNER JOIN warehouse_sales_metrics wsm ON i.product_id = wsm.product_id 
            AND i.warehouse_name = wsm.warehouse_name
        WHERE wsm.daily_sales_avg > 0 
            AND wsm.days_of_stock < 30
        ORDER BY wsm.days_of_stock ASC, wsm.daily_sales_avg DESC
        LIMIT 15
    ");
    $stmt->execute();
    $urgent_replenishment = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    if (!empty($urgent_replenishment)) {
        printf("%-20s %-8s %-12s %-10s %-10s %-10s %-15s %-15s\n", 
            "WAREHOUSE", "PROD_ID", "CURR_STOCK", "DAILY_AVG", "DAYS_LEFT", "RECOMMEND", "URGENCY", "STATUS");
        echo str_repeat("-", 120) . "\n";
        
        foreach ($urgent_replenishment as $item) {
            printf("%-20s %-8s %-12s %-10s %-10s %-10s %-15s %-15s\n",
                substr($item['warehouse_name'], 0, 19),
                $item['product_id'],
                $item['current_stock'],
                number_format($item['daily_sales_avg'], 2),
                number_format($item['days_of_stock'], 1),
                $item['recommended_order_qty'],
                $item['urgency_level'],
                $item['liquidity_status']
            );
        }
    } else {
        echo "Товары требующие срочного пополнения не найдены.\n";
    }
    
    // 3. Warehouse performance summary
    echo "\n\n3. Сводка производительности складов:\n";
    $stmt = $pdo->prepare("
        SELECT 
            i.warehouse_name,
            
            -- Stock metrics
            SUM($total_stock_formula) as total_stock,
            COUNT(DISTINCT i.product_id) as total_products,
            
            -- Sales metrics  
            COALESCE(SUM(wsm.sales_last_28_days), 0) as total_sales_28d,
            COALESCE(AVG(wsm.daily_sales_avg), 0) as avg_daily_sales_rate,
            
            -- Efficiency metrics
            CASE 
                WHEN SUM(wsm.sales_last_28_days) > 0 THEN 
                    ROUND(SUM($total_stock_formula) / NULLIF(SUM(wsm.sales_last_28_days), 0) * 28, 1)
                ELSE NULL
            END as stock_turnover_days,
            
            -- Replenishment priority
            COUNT(CASE WHEN wsm.days_of_stock < 7 THEN 1 END) as critical_count,
            COUNT(CASE WHEN wsm.days_of_stock BETWEEN 7 AND 30 THEN 1 END) as low_count,
            
            -- Priority score (higher = more urgent)
            (COUNT(CASE WHEN wsm.days_of_stock < 7 THEN 1 END) * 10 +
             COUNT(CASE WHEN wsm.days_of_stock BETWEEN 7 AND 14 THEN 1 END) * 5 +
             COUNT(CASE WHEN wsm.days_of_stock BETWEEN 14 AND 30 THEN 1 END) * 2) as priority_score,
             
            -- Warehouse status
            CASE 
                WHEN COUNT(CASE WHEN wsm.days_of_stock < 7 THEN 1 END) > 0 THEN 'КРИТИЧЕСКИЙ'
                WHEN COUNT(CASE WHEN wsm.days_of_stock < 14 THEN 1 END) > 2 THEN 'ТРЕБУЕТ ВНИМАНИЯ'
                WHEN COUNT(CASE WHEN wsm.days_of_stock < 30 THEN 1 END) > 5 THEN 'НИЗКИЙ ПРИОРИТЕТ'
                ELSE 'НОРМАЛЬНЫЙ'
            END as warehouse_status
            
        FROM inventory i
        LEFT JOIN warehouse_sales_metrics wsm ON i.product_id = wsm.product_id 
            AND i.warehouse_name = wsm.warehouse_name
        GROUP BY i.warehouse_name
        ORDER BY priority_score DESC, total_sales_28d DESC
    ");
    $stmt->execute();
    $performance = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    printf("%-20s %-12s %-10s %-10s %-12s %-8s %-8s %-8s %-15s\n", 
        "WAREHOUSE", "TOTAL_STOCK", "SALES_28D", "TURNOVER", "PRIORITY", "CRITICAL", "LOW", "PRODUCTS", "STATUS");
    echo str_repeat("-", 120) . "\n";
    
    foreach ($performance as $perf) {
        printf("%-20s %-12s %-10s %-10s %-12s %-8s %-8s %-8s %-15s\n",
            substr($perf['warehouse_name'], 0, 19),
            number_format($perf['total_stock']),
            $perf['total_sales_28d'],
            $perf['stock_turnover_days'] ? number_format($perf['stock_turnover_days'], 1) . 'd' : 'N/A',
            $perf['priority_score'],
            $perf['critical_count'],
            $perf['low_count'],
            $perf['total_products'],
            $perf['warehouse_status']
        );
    }
    
    // 4. Top products by sales performance across warehouses
    echo "\n\n4. Топ товары по продажам по всем складам:\n";
    $stmt = $pdo->prepare("
        SELECT 
            wsm.product_id,
            COUNT(DISTINCT wsm.warehouse_name) as warehouses_count,
            SUM(wsm.sales_last_28_days) as total_sales_all_warehouses,
            AVG(wsm.daily_sales_avg) as avg_daily_sales,
            SUM($total_stock_formula) as total_stock_all_warehouses,
            
            -- Overall days of stock across all warehouses
            CASE 
                WHEN AVG(wsm.daily_sales_avg) > 0 THEN 
                    ROUND(SUM($total_stock_formula) / AVG(wsm.daily_sales_avg), 1)
                ELSE NULL
            END as total_days_of_stock,
            
            -- Recommended total order
            CASE 
                WHEN AVG(wsm.daily_sales_avg) > 0 THEN 
                    GREATEST(0, CEIL(AVG(wsm.daily_sales_avg) * 60) - SUM($total_stock_formula))
                ELSE 0
            END as recommended_total_order
            
        FROM warehouse_sales_metrics wsm
        INNER JOIN inventory i ON wsm.product_id = i.product_id 
            AND wsm.warehouse_name = i.warehouse_name
        WHERE wsm.sales_last_28_days > 0
        GROUP BY wsm.product_id
        HAVING SUM(wsm.sales_last_28_days) > 0
        ORDER BY total_sales_all_warehouses DESC
        LIMIT 10
    ");
    $stmt->execute();
    $top_products = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    printf("%-10s %-12s %-12s %-12s %-12s %-12s %-15s\n", 
        "PROD_ID", "WAREHOUSES", "TOTAL_SALES", "AVG_DAILY", "TOTAL_STOCK", "DAYS_STOCK", "RECOMMEND");
    echo str_repeat("-", 90) . "\n";
    
    foreach ($top_products as $prod) {
        printf("%-10s %-12s %-12s %-12s %-12s %-12s %-15s\n",
            $prod['product_id'],
            $prod['warehouses_count'],
            $prod['total_sales_all_warehouses'],
            number_format($prod['avg_daily_sales'], 2),
            $prod['total_stock_all_warehouses'],
            $prod['total_days_of_stock'] ? number_format($prod['total_days_of_stock'], 1) : 'N/A',
            $prod['recommended_total_order']
        );
    }
    
    echo "\n=== БАЗОВЫЕ ЗАПРОСЫ СОЗДАНЫ ===\n";
    echo "\n✅ ГОТОВЫЕ КОМПОНЕНТЫ:\n";
    echo "1. Комплексный анализ складов с метриками продаж\n";
    echo "2. Определение товаров требующих пополнения\n";
    echo "3. Расчет приоритетов складов по критичности\n";
    echo "4. Анализ топ товаров по продажам\n";
    echo "5. Формулы для расчета рекомендуемых заказов\n";
    
} catch (Exception $e) {
    echo "❌ Ошибка: " . $e->getMessage() . "\n";
    exit(1);
}
?>