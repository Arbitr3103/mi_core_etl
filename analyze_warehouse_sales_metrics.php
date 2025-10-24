<?php
/**
 * Detailed analysis of warehouse_sales_metrics table
 * Part of task 2.1: Проанализировать структуру данных продаж и создать базовые запросы
 */

include 'config/database_postgresql.php';

try {
    // Get database connection
    $pdo = getDatabaseConnection();
    
    if (!$pdo) {
        throw new Exception("Failed to establish database connection");
    }
    
    echo "=== ДЕТАЛЬНЫЙ АНАЛИЗ ТАБЛИЦЫ warehouse_sales_metrics ===\n\n";
    
    // 1. Detailed structure analysis
    echo "1. Структура таблицы warehouse_sales_metrics:\n";
    $stmt = $pdo->prepare("
        SELECT 
            column_name,
            data_type,
            is_nullable,
            column_default,
            character_maximum_length,
            numeric_precision,
            numeric_scale
        FROM information_schema.columns 
        WHERE table_schema = 'public' 
        AND table_name = 'warehouse_sales_metrics'
        ORDER BY ordinal_position
    ");
    $stmt->execute();
    $columns = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    printf("%-25s %-20s %-10s %-30s\n", "COLUMN", "TYPE", "NULLABLE", "DESCRIPTION");
    echo str_repeat("-", 90) . "\n";
    
    $field_descriptions = [
        'id' => 'Уникальный идентификатор',
        'product_id' => 'ID товара (связь с inventory)',
        'warehouse_name' => 'Название склада',
        'source' => 'Источник данных (ozon, wb, etc)',
        'daily_sales_avg' => 'Средние продажи в день',
        'sales_last_28_days' => 'Продажи за последние 28 дней',
        'days_with_stock' => 'Дни с наличием товара',
        'days_without_sales' => 'Дни без продаж',
        'days_of_stock' => 'Дни остатка (остаток/средние продажи)',
        'liquidity_status' => 'Статус ликвидности товара',
        'target_stock' => 'Целевой остаток',
        'replenishment_need' => 'Потребность в пополнении',
        'calculated_at' => 'Время расчета метрик'
    ];
    
    foreach ($columns as $col) {
        $description = $field_descriptions[$col['column_name']] ?? 'Не определено';
        printf("%-25s %-20s %-10s %-30s\n", 
            $col['column_name'], 
            $col['data_type'], 
            $col['is_nullable'], 
            $description
        );
    }
    
    // 2. Data analysis
    echo "\n2. Анализ данных в таблице:\n";
    
    // Total records
    $stmt = $pdo->prepare("SELECT COUNT(*) as total FROM warehouse_sales_metrics");
    $stmt->execute();
    $total = $stmt->fetch(PDO::FETCH_ASSOC);
    echo "Всего записей: {$total['total']}\n";
    
    // Records by warehouse
    echo "\nРаспределение по складам:\n";
    $stmt = $pdo->prepare("
        SELECT 
            warehouse_name,
            COUNT(*) as products_count,
            AVG(daily_sales_avg) as avg_daily_sales,
            SUM(sales_last_28_days) as total_sales_28d
        FROM warehouse_sales_metrics 
        GROUP BY warehouse_name
        ORDER BY total_sales_28d DESC
    ");
    $stmt->execute();
    $warehouses = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    printf("%-25s %-12s %-15s %-15s\n", "WAREHOUSE", "PRODUCTS", "AVG_DAILY", "TOTAL_28D");
    echo str_repeat("-", 70) . "\n";
    foreach ($warehouses as $wh) {
        printf("%-25s %-12s %-15s %-15s\n",
            substr($wh['warehouse_name'], 0, 24),
            $wh['products_count'],
            number_format($wh['avg_daily_sales'], 2),
            $wh['total_sales_28d'] ?: '0'
        );
    }
    
    // 3. Liquidity status analysis
    echo "\n3. Анализ статусов ликвидности:\n";
    $stmt = $pdo->prepare("
        SELECT 
            liquidity_status,
            COUNT(*) as count,
            ROUND(COUNT(*) * 100.0 / (SELECT COUNT(*) FROM warehouse_sales_metrics), 2) as percentage
        FROM warehouse_sales_metrics 
        GROUP BY liquidity_status
        ORDER BY count DESC
    ");
    $stmt->execute();
    $liquidity = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    foreach ($liquidity as $liq) {
        echo "  {$liq['liquidity_status']}: {$liq['count']} товаров ({$liq['percentage']}%)\n";
    }
    
    // 4. Sales performance analysis
    echo "\n4. Анализ производительности продаж:\n";
    
    // Products with sales
    $stmt = $pdo->prepare("
        SELECT COUNT(*) as products_with_sales
        FROM warehouse_sales_metrics 
        WHERE sales_last_28_days > 0
    ");
    $stmt->execute();
    $with_sales = $stmt->fetch(PDO::FETCH_ASSOC);
    
    // Products without sales
    $stmt = $pdo->prepare("
        SELECT COUNT(*) as products_without_sales
        FROM warehouse_sales_metrics 
        WHERE sales_last_28_days = 0 OR sales_last_28_days IS NULL
    ");
    $stmt->execute();
    $without_sales = $stmt->fetch(PDO::FETCH_ASSOC);
    
    echo "Товары с продажами за 28 дней: {$with_sales['products_with_sales']}\n";
    echo "Товары без продаж за 28 дней: {$without_sales['products_without_sales']}\n";
    
    // 5. Days of stock analysis
    echo "\n5. Анализ дней остатка (days_of_stock):\n";
    $stmt = $pdo->prepare("
        WITH stock_categories AS (
            SELECT 
                CASE 
                    WHEN days_of_stock IS NULL THEN 'Не определено'
                    WHEN days_of_stock < 7 THEN 'Критический (< 7 дней)'
                    WHEN days_of_stock < 30 THEN 'Низкий (7-30 дней)'
                    WHEN days_of_stock < 60 THEN 'Нормальный (30-60 дней)'
                    ELSE 'Избыточный (> 60 дней)'
                END as stock_category,
                CASE 
                    WHEN days_of_stock IS NULL THEN 5
                    WHEN days_of_stock < 7 THEN 1
                    WHEN days_of_stock < 30 THEN 2
                    WHEN days_of_stock < 60 THEN 3
                    ELSE 4
                END as sort_order
            FROM warehouse_sales_metrics
        )
        SELECT 
            stock_category,
            COUNT(*) as count
        FROM stock_categories
        GROUP BY stock_category, sort_order
        ORDER BY sort_order
    ");
    $stmt->execute();
    $stock_days = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    foreach ($stock_days as $cat) {
        echo "  {$cat['stock_category']}: {$cat['count']} товаров\n";
    }
    
    // 6. Sample detailed data
    echo "\n6. Примеры детальных данных:\n";
    $stmt = $pdo->prepare("
        SELECT 
            product_id,
            warehouse_name,
            daily_sales_avg,
            sales_last_28_days,
            days_of_stock,
            liquidity_status,
            target_stock,
            replenishment_need
        FROM warehouse_sales_metrics
        WHERE sales_last_28_days > 0
        ORDER BY daily_sales_avg DESC
        LIMIT 5
    ");
    $stmt->execute();
    $samples = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    printf("%-10s %-20s %-10s %-10s %-10s %-12s %-10s %-10s\n", 
        "PROD_ID", "WAREHOUSE", "DAILY_AVG", "SALES_28D", "DAYS_STOCK", "LIQUIDITY", "TARGET", "REPLEN");
    echo str_repeat("-", 100) . "\n";
    
    foreach ($samples as $sample) {
        printf("%-10s %-20s %-10s %-10s %-10s %-12s %-10s %-10s\n",
            $sample['product_id'],
            substr($sample['warehouse_name'], 0, 19),
            number_format($sample['daily_sales_avg'], 2),
            $sample['sales_last_28_days'],
            number_format($sample['days_of_stock'], 1),
            $sample['liquidity_status'],
            $sample['target_stock'] ?: 'NULL',
            $sample['replenishment_need'] ?: 'NULL'
        );
    }
    
    // 7. Connection with inventory table
    echo "\n7. Связь с таблицей inventory:\n";
    $stmt = $pdo->prepare("
        SELECT 
            COUNT(DISTINCT wsm.product_id) as products_in_sales,
            COUNT(DISTINCT i.product_id) as products_in_inventory,
            COUNT(DISTINCT CASE WHEN i.product_id IS NOT NULL THEN wsm.product_id END) as products_matched
        FROM warehouse_sales_metrics wsm
        LEFT JOIN inventory i ON wsm.product_id = i.product_id AND wsm.warehouse_name = i.warehouse_name
    ");
    $stmt->execute();
    $connection = $stmt->fetch(PDO::FETCH_ASSOC);
    
    echo "Товары в sales_metrics: {$connection['products_in_sales']}\n";
    echo "Товары в inventory: {$connection['products_in_inventory']}\n";
    echo "Совпадающие товары: {$connection['products_matched']}\n";
    
    // 8. Test queries for replenishment logic
    echo "\n8. Тестовые запросы для логики пополнения:\n";
    
    // Query 1: Products needing urgent replenishment (< 7 days of stock)
    echo "\nТовары требующие срочного пополнения (< 7 дней остатка):\n";
    $stmt = $pdo->prepare("
        SELECT 
            wsm.product_id,
            wsm.warehouse_name,
            wsm.daily_sales_avg,
            wsm.days_of_stock,
            i.quantity_present as current_stock,
            CEIL(wsm.daily_sales_avg * 30) as recommended_order
        FROM warehouse_sales_metrics wsm
        LEFT JOIN inventory i ON wsm.product_id = i.product_id AND wsm.warehouse_name = i.warehouse_name
        WHERE wsm.days_of_stock < 7 AND wsm.daily_sales_avg > 0
        ORDER BY wsm.days_of_stock ASC
        LIMIT 5
    ");
    $stmt->execute();
    $urgent = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    if (!empty($urgent)) {
        printf("%-10s %-20s %-10s %-10s %-12s %-15s\n", 
            "PROD_ID", "WAREHOUSE", "DAILY_AVG", "DAYS_LEFT", "CURR_STOCK", "RECOMMEND");
        echo str_repeat("-", 80) . "\n";
        
        foreach ($urgent as $item) {
            printf("%-10s %-20s %-10s %-10s %-12s %-15s\n",
                $item['product_id'],
                substr($item['warehouse_name'], 0, 19),
                number_format($item['daily_sales_avg'], 2),
                number_format($item['days_of_stock'], 1),
                $item['current_stock'] ?: '0',
                $item['recommended_order']
            );
        }
    } else {
        echo "Товары требующие срочного пополнения не найдены.\n";
    }
    
    echo "\n=== АНАЛИЗ ЗАВЕРШЕН ===\n";
    echo "\n✅ ВЫВОДЫ:\n";
    echo "1. Таблица warehouse_sales_metrics содержит готовые метрики продаж\n";
    echo "2. Есть поля для расчета потребности в пополнении (days_of_stock, daily_sales_avg)\n";
    echo "3. Можно использовать эти данные для создания рекомендаций по пополнению\n";
    echo "4. Связь с inventory позволяет сопоставить остатки и продажи\n";
    
} catch (Exception $e) {
    echo "❌ Ошибка: " . $e->getMessage() . "\n";
    exit(1);
}
?>