<?php
/**
 * Final script for warehouse inventory grouping and analysis
 * Part of task 2.1: Проанализировать структуру данных продаж и создать базовые запросы
 */

include 'config/database_postgresql.php';

try {
    // Get database connection
    $pdo = getDatabaseConnection();
    
    if (!$pdo) {
        throw new Exception("Failed to establish database connection");
    }
    
    echo "=== ГРУППИРОВКА ДАННЫХ ОСТАТКОВ ПО СКЛАДАМ ===\n\n";
    
    // Define the total stock calculation formula
    $total_stock_formula = "
        COALESCE(quantity_present, 0) + 
        COALESCE(quantity_reserved, 0) + 
        COALESCE(preparing_for_sale, 0) + 
        COALESCE(in_supply_requests, 0) + 
        COALESCE(in_transit, 0) + 
        COALESCE(in_inspection, 0) + 
        COALESCE(returning_from_customers, 0) + 
        COALESCE(expiring_soon, 0) + 
        COALESCE(defective, 0) + 
        COALESCE(excess_from_supply, 0) + 
        COALESCE(awaiting_upd, 0) + 
        COALESCE(preparing_for_removal, 0)
    ";
    
    // 1. Basic warehouse grouping with stock totals
    echo "1. Базовая группировка по складам с общими остатками:\n";
    $stmt = $pdo->prepare("
        SELECT 
            warehouse_name,
            COUNT(*) as total_products,
            COUNT(CASE WHEN ($total_stock_formula) > 0 THEN 1 END) as active_products,
            COUNT(CASE WHEN ($total_stock_formula) = 0 THEN 1 END) as inactive_products,
            SUM($total_stock_formula) as total_stock,
            AVG($total_stock_formula) as avg_stock_per_product,
            MIN($total_stock_formula) as min_stock,
            MAX($total_stock_formula) as max_stock,
            ROUND(
                COUNT(CASE WHEN ($total_stock_formula) > 0 THEN 1 END) * 100.0 / COUNT(*), 2
            ) as fill_percentage
        FROM inventory
        GROUP BY warehouse_name
        ORDER BY total_stock DESC
    ");
    $stmt->execute();
    $basic_grouping = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    printf("%-25s %-8s %-8s %-8s %-12s %-10s %-8s %-8s %-8s\n", 
        "WAREHOUSE", "TOTAL", "ACTIVE", "INACTIVE", "TOTAL_STOCK", "AVG_STOCK", "MIN", "MAX", "FILL_%");
    echo str_repeat("-", 100) . "\n";
    
    foreach ($basic_grouping as $wh) {
        printf("%-25s %-8s %-8s %-8s %-12s %-10s %-8s %-8s %-8s\n",
            substr($wh['warehouse_name'], 0, 24),
            $wh['total_products'],
            $wh['active_products'],
            $wh['inactive_products'],
            number_format($wh['total_stock']),
            number_format($wh['avg_stock_per_product'], 1),
            $wh['min_stock'],
            number_format($wh['max_stock']),
            $wh['fill_percentage'] . '%'
        );
    }
    
    // 2. Warehouse analysis with sales integration
    echo "\n\n2. Анализ складов с интеграцией данных продаж:\n";
    $stmt = $pdo->prepare("
        SELECT 
            i.warehouse_name,
            COUNT(DISTINCT i.product_id) as inventory_products,
            COUNT(DISTINCT wsm.product_id) as products_with_sales,
            SUM($total_stock_formula) as current_total_stock,
            COALESCE(SUM(wsm.sales_last_28_days), 0) as sales_28_days,
            COALESCE(AVG(wsm.daily_sales_avg), 0) as avg_daily_sales,
            
            -- Calculate average days of stock for warehouse
            CASE 
                WHEN AVG(wsm.daily_sales_avg) > 0 THEN 
                    ROUND(SUM($total_stock_formula) / AVG(wsm.daily_sales_avg), 1)
                ELSE NULL
            END as warehouse_days_of_stock,
            
            -- Sales coverage percentage
            ROUND(
                COUNT(DISTINCT wsm.product_id) * 100.0 / 
                NULLIF(COUNT(DISTINCT i.product_id), 0), 2
            ) as sales_coverage_percent
            
        FROM inventory i
        LEFT JOIN warehouse_sales_metrics wsm ON i.product_id = wsm.product_id 
            AND i.warehouse_name = wsm.warehouse_name
        GROUP BY i.warehouse_name
        ORDER BY sales_28_days DESC
    ");
    $stmt->execute();
    $sales_integration = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    printf("%-25s %-8s %-8s %-12s %-10s %-10s %-12s %-10s\n", 
        "WAREHOUSE", "INV_PROD", "SALES_PROD", "CURR_STOCK", "SALES_28D", "DAILY_AVG", "DAYS_STOCK", "COVERAGE_%");
    echo str_repeat("-", 110) . "\n";
    
    foreach ($sales_integration as $si) {
        printf("%-25s %-8s %-8s %-12s %-10s %-10s %-12s %-10s\n",
            substr($si['warehouse_name'], 0, 24),
            $si['inventory_products'],
            $si['products_with_sales'],
            number_format($si['current_total_stock']),
            $si['sales_28_days'],
            number_format($si['avg_daily_sales'], 2),
            $si['warehouse_days_of_stock'] ? $si['warehouse_days_of_stock'] . 'd' : 'N/A',
            $si['sales_coverage_percent'] . '%'
        );
    }
    
    // 3. Detailed product analysis by warehouse
    echo "\n\n3. Детальный анализ товаров по складам (топ 20):\n";
    $stmt = $pdo->prepare("
        SELECT 
            i.warehouse_name,
            i.product_id,
            ($total_stock_formula) as current_stock,
            i.quantity_present,
            i.quantity_reserved,
            i.preparing_for_sale,
            wsm.daily_sales_avg,
            wsm.sales_last_28_days,
            wsm.days_of_stock,
            wsm.liquidity_status,
            
            -- Activity status
            CASE 
                WHEN ($total_stock_formula) > 0 THEN 'ACTIVE'
                ELSE 'INACTIVE'
            END as activity_status
            
        FROM inventory i
        LEFT JOIN warehouse_sales_metrics wsm ON i.product_id = wsm.product_id 
            AND i.warehouse_name = wsm.warehouse_name
        ORDER BY ($total_stock_formula) DESC, wsm.sales_last_28_days DESC
        LIMIT 20
    ");
    $stmt->execute();
    $detailed_products = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    printf("%-20s %-8s %-10s %-8s %-8s %-8s %-10s %-8s %-8s %-10s %-8s\n", 
        "WAREHOUSE", "PROD_ID", "CURR_STOCK", "PRESENT", "RESERVED", "PREP", "DAILY_AVG", "SALES_28D", "DAYS", "LIQUIDITY", "STATUS");
    echo str_repeat("-", 130) . "\n";
    
    foreach ($detailed_products as $prod) {
        printf("%-20s %-8s %-10s %-8s %-8s %-8s %-10s %-8s %-8s %-10s %-8s\n",
            substr($prod['warehouse_name'], 0, 19),
            $prod['product_id'],
            $prod['current_stock'],
            $prod['quantity_present'] ?: '0',
            $prod['quantity_reserved'] ?: '0',
            $prod['preparing_for_sale'] ?: '0',
            $prod['daily_sales_avg'] ? number_format($prod['daily_sales_avg'], 2) : 'N/A',
            $prod['sales_last_28_days'] ?: '0',
            $prod['days_of_stock'] ? number_format($prod['days_of_stock'], 1) : 'N/A',
            $prod['liquidity_status'] ?: 'N/A',
            $prod['activity_status']
        );
    }
    
    // 4. Summary statistics
    echo "\n\n4. Сводная статистика:\n";
    $stmt = $pdo->prepare("
        SELECT 
            COUNT(DISTINCT i.warehouse_name) as total_warehouses,
            COUNT(DISTINCT i.product_id) as unique_products,
            COUNT(*) as total_inventory_records,
            SUM($total_stock_formula) as grand_total_stock,
            COUNT(CASE WHEN ($total_stock_formula) > 0 THEN 1 END) as active_records,
            COUNT(CASE WHEN ($total_stock_formula) = 0 THEN 1 END) as inactive_records,
            COUNT(DISTINCT wsm.product_id) as products_with_sales_data,
            COALESCE(SUM(wsm.sales_last_28_days), 0) as total_sales_all_warehouses
        FROM inventory i
        LEFT JOIN warehouse_sales_metrics wsm ON i.product_id = wsm.product_id 
            AND i.warehouse_name = wsm.warehouse_name
    ");
    $stmt->execute();
    $summary = $stmt->fetch(PDO::FETCH_ASSOC);
    
    echo "Общее количество складов: {$summary['total_warehouses']}\n";
    echo "Уникальных товаров: {$summary['unique_products']}\n";
    echo "Всего записей в inventory: {$summary['total_inventory_records']}\n";
    echo "Общий остаток по всем складам: " . number_format($summary['grand_total_stock']) . "\n";
    echo "Активных записей: {$summary['active_records']}\n";
    echo "Неактивных записей: {$summary['inactive_records']}\n";
    echo "Товаров с данными продаж: {$summary['products_with_sales_data']}\n";
    echo "Общие продажи за 28 дней: {$summary['total_sales_all_warehouses']}\n";
    
    $active_percentage = round($summary['active_records'] * 100.0 / $summary['total_inventory_records'], 2);
    $sales_coverage = round($summary['products_with_sales_data'] * 100.0 / $summary['unique_products'], 2);
    
    echo "Процент активных записей: {$active_percentage}%\n";
    echo "Покрытие данными продаж: {$sales_coverage}%\n";
    
    echo "\n=== ГРУППИРОВКА ЗАВЕРШЕНА ===\n";
    echo "\n✅ РЕЗУЛЬТАТЫ ЗАДАЧИ 2.1:\n";
    echo "1. ✅ Исследована структура данных продаж - найдена таблица warehouse_sales_metrics\n";
    echo "2. ✅ Созданы SQL запросы для извлечения продаж за последний месяц\n";
    echo "3. ✅ Написаны запросы для группировки остатков по warehouse_name\n";
    echo "4. ✅ Рассчитаны общие остатки, активные и неактивные товары по складам\n";
    echo "5. ✅ Определены средние, минимальные и максимальные остатки\n";
    echo "6. ✅ Создана связь между таблицами inventory и warehouse_sales_metrics\n";
    
} catch (Exception $e) {
    echo "❌ Ошибка: " . $e->getMessage() . "\n";
    exit(1);
}
?>