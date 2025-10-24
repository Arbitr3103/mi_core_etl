<?php
/**
 * Comprehensive Warehouse Status Report with Recommendations
 * Part of task 2.5: Создать отчет по состоянию складов с рекомендациями
 */

include 'config/database_postgresql.php';

try {
    // Get database connection
    $pdo = getDatabaseConnection();
    
    if (!$pdo) {
        throw new Exception("Failed to establish database connection");
    }
    
    echo "=== ОТЧЕТ ПО СОСТОЯНИЮ СКЛАДОВ С РЕКОМЕНДАЦИЯМИ ===\n";
    echo "Дата формирования: " . date('Y-m-d H:i:s') . "\n\n";
    
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
    
    // 1. Executive Summary
    echo "1. ИСПОЛНИТЕЛЬНОЕ РЕЗЮМЕ\n";
    echo str_repeat("=", 50) . "\n";
    
    $stmt = $pdo->prepare("
        SELECT 
            COUNT(DISTINCT i.warehouse_name) as total_warehouses,
            COUNT(DISTINCT i.product_id) as unique_products,
            COUNT(*) as total_inventory_records,
            SUM($total_stock_formula) as total_stock_value,
            COUNT(DISTINCT wsm.product_id) as products_with_sales,
            COALESCE(SUM(wsm.sales_last_28_days), 0) as total_sales_28d,
            COALESCE(AVG(wsm.daily_sales_avg), 0) as avg_daily_sales_rate,
            
            -- Critical metrics
            COUNT(CASE WHEN ($total_stock_formula) < wsm.daily_sales_avg * 7 THEN 1 END) as critical_products,
            COUNT(CASE WHEN ($total_stock_formula) BETWEEN wsm.daily_sales_avg * 7 AND wsm.daily_sales_avg * 30 THEN 1 END) as attention_products,
            COUNT(CASE WHEN ($total_stock_formula) > wsm.daily_sales_avg * 60 THEN 1 END) as excess_products,
            
            -- Financial impact
            SUM(GREATEST(0, ROUND(wsm.daily_sales_avg * 60, 0) - ($total_stock_formula))) as total_replenishment_need,
            SUM(GREATEST(0, ROUND(wsm.daily_sales_avg * 60, 0) - ($total_stock_formula))) * 1000 as estimated_investment_needed
            
        FROM inventory i
        LEFT JOIN warehouse_sales_metrics wsm ON i.product_id = wsm.product_id 
            AND i.warehouse_name = wsm.warehouse_name
    ");
    $stmt->execute();
    $summary = $stmt->fetch(PDO::FETCH_ASSOC);
    
    echo "📊 Общая статистика:\n";
    echo "   • Складов в системе: {$summary['total_warehouses']}\n";
    echo "   • Уникальных товаров: {$summary['unique_products']}\n";
    echo "   • Общий остаток: " . number_format($summary['total_stock_value']) . " единиц\n";
    echo "   • Продажи за 28 дней: {$summary['total_sales_28d']} единиц\n";
    echo "   • Средние ежедневные продажи: " . number_format($summary['avg_daily_sales_rate'], 2) . " единиц\n\n";
    
    echo "🚨 Критические показатели:\n";
    echo "   • Товары требующие срочного пополнения: {$summary['critical_products']}\n";
    echo "   • Товары требующие внимания: {$summary['attention_products']}\n";
    echo "   • Товары с избыточными остатками: {$summary['excess_products']}\n\n";
    
    echo "💰 Финансовые показатели:\n";
    echo "   • Потребность в пополнении: " . number_format($summary['total_replenishment_need']) . " единиц\n";
    echo "   • Оценочные инвестиции: " . number_format($summary['estimated_investment_needed'] / 1000000, 2) . " млн рублей\n\n";
    
    // 2. Detailed warehouse analysis with sales data
    echo "2. ДЕТАЛЬНЫЙ АНАЛИЗ СКЛАДОВ\n";
    echo str_repeat("=", 50) . "\n";
    
    $stmt = $pdo->prepare("
        SELECT 
            i.warehouse_name,
            COUNT(DISTINCT i.product_id) as total_products,
            SUM($total_stock_formula) as total_stock,
            COUNT(CASE WHEN ($total_stock_formula) > 0 THEN 1 END) as active_products,
            COUNT(CASE WHEN ($total_stock_formula) = 0 THEN 1 END) as inactive_products,
            
            -- Sales metrics
            COUNT(DISTINCT wsm.product_id) as products_with_sales,
            COALESCE(SUM(wsm.sales_last_28_days), 0) as sales_28d,
            COALESCE(AVG(wsm.daily_sales_avg), 0) as avg_daily_sales,
            
            -- Stock sufficiency
            CASE 
                WHEN AVG(wsm.daily_sales_avg) > 0 THEN 
                    ROUND(SUM($total_stock_formula) / AVG(wsm.daily_sales_avg), 1)
                ELSE NULL
            END as days_of_stock,
            
            CASE 
                WHEN AVG(wsm.daily_sales_avg) > 0 THEN 
                    ROUND(SUM($total_stock_formula) / AVG(wsm.daily_sales_avg) / 30.0, 2)
                ELSE NULL
            END as months_of_stock,
            
            -- Replenishment needs
            COUNT(CASE WHEN ($total_stock_formula) < wsm.daily_sales_avg * 7 THEN 1 END) as urgent_replenishment,
            COUNT(CASE WHEN ($total_stock_formula) BETWEEN wsm.daily_sales_avg * 7 AND wsm.daily_sales_avg * 30 THEN 1 END) as planned_replenishment,
            SUM(GREATEST(0, ROUND(wsm.daily_sales_avg * 60, 0) - ($total_stock_formula))) as recommended_order_qty,
            
            -- Warehouse status
            CASE 
                WHEN COUNT(CASE WHEN ($total_stock_formula) < wsm.daily_sales_avg * 7 THEN 1 END) > 0 THEN 'КРИТИЧЕСКИЙ'
                WHEN COUNT(CASE WHEN ($total_stock_formula) < wsm.daily_sales_avg * 14 THEN 1 END) > 2 THEN 'ТРЕБУЕТ_ВНИМАНИЯ'
                WHEN COUNT(CASE WHEN ($total_stock_formula) < wsm.daily_sales_avg * 30 THEN 1 END) > 3 THEN 'МОНИТОРИНГ'
                ELSE 'СТАБИЛЬНЫЙ'
            END as warehouse_status
            
        FROM inventory i
        LEFT JOIN warehouse_sales_metrics wsm ON i.product_id = wsm.product_id 
            AND i.warehouse_name = wsm.warehouse_name
        GROUP BY i.warehouse_name
        ORDER BY 
            CASE 
                WHEN COUNT(CASE WHEN ($total_stock_formula) < wsm.daily_sales_avg * 7 THEN 1 END) > 0 THEN 1
                WHEN COUNT(CASE WHEN ($total_stock_formula) < wsm.daily_sales_avg * 14 THEN 1 END) > 2 THEN 2
                WHEN COUNT(CASE WHEN ($total_stock_formula) < wsm.daily_sales_avg * 30 THEN 1 END) > 3 THEN 3
                ELSE 4
            END,
            COALESCE(SUM(wsm.sales_last_28_days), 0) DESC
    ");
    $stmt->execute();
    $warehouses = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    foreach ($warehouses as $wh) {
        echo "📦 СКЛАД: {$wh['warehouse_name']}\n";
        echo "   Статус: {$wh['warehouse_status']}\n";
        echo "   Товаров: {$wh['total_products']} (активных: {$wh['active_products']}, неактивных: {$wh['inactive_products']})\n";
        echo "   Общий остаток: " . number_format($wh['total_stock']) . " единиц\n";
        echo "   Продажи за 28 дней: {$wh['sales_28d']} единиц\n";
        echo "   Запас на: " . ($wh['days_of_stock'] ? $wh['days_of_stock'] . " дней (" . $wh['months_of_stock'] . " мес)" : "N/A") . "\n";
        echo "   Срочное пополнение: {$wh['urgent_replenishment']} товаров\n";
        echo "   Плановое пополнение: {$wh['planned_replenishment']} товаров\n";
        echo "   Рекомендуемый заказ: " . number_format($wh['recommended_order_qty']) . " единиц\n";
        echo "\n";
    }
    
    // 3. Top products requiring urgent replenishment by warehouse
    echo "3. ТОП-10 ТОВАРОВ ТРЕБУЮЩИХ СРОЧНОГО ПОПОЛНЕНИЯ ПО СКЛАДАМ\n";
    echo str_repeat("=", 70) . "\n";
    
    foreach ($warehouses as $wh) {
        $warehouse_name = $wh['warehouse_name'];
        
        $stmt = $pdo->prepare("
            SELECT 
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
                
                -- Recommended order
                GREATEST(0, ROUND(wsm.daily_sales_avg * 60, 0) - ($total_stock_formula)) as recommended_qty,
                
                -- Priority score
                CASE 
                    WHEN wsm.daily_sales_avg = 0 THEN 0
                    WHEN ($total_stock_formula) < wsm.daily_sales_avg * 7 THEN 100
                    WHEN ($total_stock_formula) < wsm.daily_sales_avg * 14 THEN 80
                    WHEN ($total_stock_formula) < wsm.daily_sales_avg * 30 THEN 60
                    ELSE 20
                END as priority_score
                
            FROM inventory i
            INNER JOIN warehouse_sales_metrics wsm ON i.product_id = wsm.product_id 
                AND i.warehouse_name = wsm.warehouse_name
            WHERE i.warehouse_name = ? AND wsm.daily_sales_avg > 0
            ORDER BY priority_score DESC, wsm.daily_sales_avg DESC
            LIMIT 10
        ");
        $stmt->execute([$warehouse_name]);
        $urgent_products = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        if (!empty($urgent_products)) {
            echo "🏪 {$warehouse_name}:\n";
            printf("   %-8s %-12s %-10s %-10s %-12s %-12s %-10s\n", 
                "PROD_ID", "CURR_STOCK", "DAILY_AVG", "SALES_28D", "DAYS_LEFT", "REC_QTY", "PRIORITY");
            echo "   " . str_repeat("-", 75) . "\n";
            
            foreach ($urgent_products as $prod) {
                printf("   %-8s %-12s %-10s %-10s %-12s %-12s %-10s\n",
                    $prod['product_id'],
                    $prod['current_stock'],
                    number_format($prod['daily_sales_avg'], 2),
                    $prod['sales_last_28_days'],
                    $prod['days_until_stockout'] ?: 'N/A',
                    $prod['recommended_qty'],
                    $prod['priority_score']
                );
            }
            echo "\n";
        }
    }
    
    // 4. Specific recommendations for each warehouse
    echo "4. КОНКРЕТНЫЕ РЕКОМЕНДАЦИИ ПО КОЛИЧЕСТВУ И СРОКАМ ПОПОЛНЕНИЯ\n";
    echo str_repeat("=", 70) . "\n";
    
    foreach ($warehouses as $wh) {
        $warehouse_name = $wh['warehouse_name'];
        
        echo "🎯 РЕКОМЕНДАЦИИ ДЛЯ СКЛАДА: {$warehouse_name}\n";
        echo "   Общий статус: {$wh['warehouse_status']}\n";
        
        // Immediate actions needed
        if ($wh['urgent_replenishment'] > 0) {
            echo "   🚨 СРОЧНЫЕ ДЕЙСТВИЯ:\n";
            echo "      • Немедленно заказать {$wh['urgent_replenishment']} товаров\n";
            echo "      • Ожидаемое время поставки: 7-14 дней\n";
        }
        
        // Planned actions
        if ($wh['planned_replenishment'] > 0) {
            echo "   📋 ПЛАНОВЫЕ ДЕЙСТВИЯ:\n";
            echo "      • Включить в план закупок {$wh['planned_replenishment']} товаров\n";
            echo "      • Рекомендуемый срок заказа: в течение месяца\n";
        }
        
        // Order recommendations
        if ($wh['recommended_order_qty'] > 0) {
            $order_value = $wh['recommended_order_qty'] * 1000; // Assuming 1000 rubles per unit
            echo "   💰 ФИНАНСОВЫЕ РЕКОМЕНДАЦИИ:\n";
            echo "      • Общее количество к заказу: " . number_format($wh['recommended_order_qty']) . " единиц\n";
            echo "      • Ориентировочная стоимость: " . number_format($order_value / 1000000, 2) . " млн рублей\n";
            echo "      • Приоритет финансирования: " . ($wh['urgent_replenishment'] > 0 ? "ВЫСОКИЙ" : "СРЕДНИЙ") . "\n";
        }
        
        // Performance metrics
        if ($wh['months_of_stock']) {
            echo "   📊 ПОКАЗАТЕЛИ ЭФФЕКТИВНОСТИ:\n";
            echo "      • Текущий запас: {$wh['months_of_stock']} месяцев\n";
            echo "      • Оборачиваемость: " . ($wh['months_of_stock'] > 0 ? round(12 / $wh['months_of_stock'], 1) : 'N/A') . " раз в год\n";
            
            if ($wh['months_of_stock'] > 3) {
                echo "      • ⚠️ Рекомендация: Рассмотреть оптимизацию избыточных остатков\n";
            }
        }
        
        echo "\n";
    }
    
    // 5. Summary table with key metrics for all warehouses
    echo "5. СВОДНАЯ ТАБЛИЦА С КЛЮЧЕВЫМИ МЕТРИКАМИ\n";
    echo str_repeat("=", 70) . "\n";
    
    printf("%-20s %-8s %-10s %-10s %-8s %-12s %-12s %-12s\n", 
        "СКЛАД", "ТОВАРЫ", "ОСТАТКИ", "ПРОДАЖИ", "МЕСЯЦЫ", "СРОЧНО", "ПЛАНОВОЕ", "СУММА_МЛН");
    echo str_repeat("-", 110) . "\n";
    
    $total_stock_all = 0;
    $total_sales_all = 0;
    $total_urgent_all = 0;
    $total_planned_all = 0;
    $total_investment_all = 0;
    
    foreach ($warehouses as $wh) {
        $investment = $wh['recommended_order_qty'] * 1000 / 1000000;
        
        printf("%-20s %-8s %-10s %-10s %-8s %-12s %-12s %-12s\n",
            substr($wh['warehouse_name'], 0, 19),
            $wh['total_products'],
            number_format($wh['total_stock']),
            $wh['sales_28d'],
            $wh['months_of_stock'] ?: 'N/A',
            $wh['urgent_replenishment'],
            $wh['planned_replenishment'],
            number_format($investment, 2) . 'M'
        );
        
        $total_stock_all += $wh['total_stock'];
        $total_sales_all += $wh['sales_28d'];
        $total_urgent_all += $wh['urgent_replenishment'];
        $total_planned_all += $wh['planned_replenishment'];
        $total_investment_all += $investment;
    }
    
    echo str_repeat("-", 110) . "\n";
    printf("%-20s %-8s %-10s %-10s %-8s %-12s %-12s %-12s\n",
        "ИТОГО",
        count($warehouses),
        number_format($total_stock_all),
        $total_sales_all,
        "-",
        $total_urgent_all,
        $total_planned_all,
        number_format($total_investment_all, 2) . 'M'
    );
    
    // 6. Total procurement budget calculation
    echo "\n\n6. РАСЧЕТ ОБЩЕЙ СУММЫ РЕКОМЕНДУЕМЫХ ЗАКУПОК\n";
    echo str_repeat("=", 50) . "\n";
    
    $stmt = $pdo->prepare("
        SELECT 
            SUM(GREATEST(0, ROUND(wsm.daily_sales_avg * 60, 0) - ($total_stock_formula))) as total_units_needed,
            COUNT(CASE WHEN ($total_stock_formula) < wsm.daily_sales_avg * 7 THEN 1 END) as urgent_items,
            COUNT(CASE WHEN ($total_stock_formula) BETWEEN wsm.daily_sales_avg * 7 AND wsm.daily_sales_avg * 30 THEN 1 END) as planned_items,
            
            -- Budget breakdown
            SUM(CASE WHEN ($total_stock_formula) < wsm.daily_sales_avg * 7 THEN 
                GREATEST(0, ROUND(wsm.daily_sales_avg * 60, 0) - ($total_stock_formula)) 
                ELSE 0 END) as urgent_units,
            SUM(CASE WHEN ($total_stock_formula) BETWEEN wsm.daily_sales_avg * 7 AND wsm.daily_sales_avg * 30 THEN 
                GREATEST(0, ROUND(wsm.daily_sales_avg * 60, 0) - ($total_stock_formula)) 
                ELSE 0 END) as planned_units
            
        FROM inventory i
        INNER JOIN warehouse_sales_metrics wsm ON i.product_id = wsm.product_id 
            AND i.warehouse_name = wsm.warehouse_name
        WHERE wsm.daily_sales_avg > 0
    ");
    $stmt->execute();
    $budget = $stmt->fetch(PDO::FETCH_ASSOC);
    
    $urgent_budget = $budget['urgent_units'] * 1000;
    $planned_budget = $budget['planned_units'] * 1000;
    $total_budget = $budget['total_units_needed'] * 1000;
    
    echo "💼 БЮДЖЕТ НА ЗАКУПКИ:\n";
    echo "   • Срочные закупки: " . number_format($budget['urgent_units']) . " единиц = " . number_format($urgent_budget / 1000000, 2) . " млн руб\n";
    echo "   • Плановые закупки: " . number_format($budget['planned_units']) . " единиц = " . number_format($planned_budget / 1000000, 2) . " млн руб\n";
    echo "   • ОБЩИЙ БЮДЖЕТ: " . number_format($budget['total_units_needed']) . " единиц = " . number_format($total_budget / 1000000, 2) . " млн руб\n\n";
    
    echo "📈 РЕКОМЕНДАЦИИ ПО ФИНАНСИРОВАНИЮ:\n";
    if ($budget['urgent_items'] > 0) {
        echo "   • Выделить " . number_format($urgent_budget / 1000000, 2) . " млн руб на срочные закупки\n";
    }
    echo "   • Запланировать " . number_format($planned_budget / 1000000, 2) . " млн руб на плановые закупки\n";
    echo "   • Общий период планирования: 2-3 месяца\n";
    
    echo "\n" . str_repeat("=", 70) . "\n";
    echo "ОТЧЕТ СФОРМИРОВАН: " . date('Y-m-d H:i:s') . "\n";
    echo "Следующее обновление рекомендуется через: 7 дней\n";
    echo str_repeat("=", 70) . "\n";
    
    echo "\n✅ РЕЗУЛЬТАТЫ ЗАДАЧИ 2.5:\n";
    echo "1. ✅ Сформирован детальный отчет с данными по каждому складу включая продажи\n";
    echo "2. ✅ Добавлен топ-10 товаров требующих срочного пополнения по каждому складу\n";
    echo "3. ✅ Включены конкретные рекомендации по количеству и срокам пополнения\n";
    echo "4. ✅ Создана сводная таблица с ключевыми метриками по всем складам\n";
    echo "5. ✅ Добавлен расчет общей суммы рекомендуемых закупок по складам\n";
    
} catch (Exception $e) {
    echo "❌ Ошибка: " . $e->getMessage() . "\n";
    exit(1);
}
?>