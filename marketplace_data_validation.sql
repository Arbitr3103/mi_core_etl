-- ===================================================================
-- MARKETPLACE DATA VALIDATION AND QUALITY MONITORING
-- ===================================================================
-- SQL скрипты для валидации корректности классификации данных по маркетплейсам
-- и мониторинга качества данных
-- 
-- Требования: 6.1, 6.2, 6.4 - Валидация данных и обеспечение консистентности
-- ===================================================================

-- Проверяем текущую базу данных
SELECT 'MARKETPLACE DATA VALIDATION REPORT' as report_title, DATABASE() as database_name, NOW() as generated_at;

-- ===================================================================
-- 1. АНАЛИЗ SKU НАЗНАЧЕНИЙ
-- ===================================================================

SELECT '1. SKU ASSIGNMENTS ANALYSIS' as section_title;

-- Статистика по SKU назначениям
SELECT 
    'SKU Assignment Statistics' as analysis_type,
    COUNT(*) as total_products,
    SUM(CASE WHEN sku_ozon IS NOT NULL THEN 1 ELSE 0 END) as products_with_ozon_sku,
    SUM(CASE WHEN sku_wb IS NOT NULL THEN 1 ELSE 0 END) as products_with_wb_sku,
    SUM(CASE WHEN sku_ozon IS NOT NULL AND sku_wb IS NOT NULL THEN 1 ELSE 0 END) as products_with_both_sku,
    SUM(CASE WHEN sku_ozon IS NULL AND sku_wb IS NULL THEN 1 ELSE 0 END) as products_with_no_sku,
    ROUND(SUM(CASE WHEN sku_ozon IS NOT NULL THEN 1 ELSE 0 END) / COUNT(*) * 100, 2) as ozon_sku_coverage_percent,
    ROUND(SUM(CASE WHEN sku_wb IS NOT NULL THEN 1 ELSE 0 END) / COUNT(*) * 100, 2) as wb_sku_coverage_percent
FROM dim_products;

-- Товары с конфликтующими SKU (одинаковые для разных маркетплейсов)
SELECT 
    'Conflicting SKU Check' as check_type,
    COUNT(*) as conflicting_products_count
FROM dim_products 
WHERE sku_ozon IS NOT NULL 
    AND sku_wb IS NOT NULL 
    AND sku_ozon = sku_wb;

-- Детали товаров с конфликтующими SKU
SELECT 
    'Conflicting SKU Details' as details_type,
    id,
    name,
    brand,
    sku_ozon,
    sku_wb,
    'CRITICAL: Same SKU for different marketplaces' as issue
FROM dim_products 
WHERE sku_ozon IS NOT NULL 
    AND sku_wb IS NOT NULL 
    AND sku_ozon = sku_wb
LIMIT 10;

-- ===================================================================
-- 2. АНАЛИЗ СООТВЕТСТВИЯ SKU И ЗАКАЗОВ
-- ===================================================================

SELECT '2. SKU-ORDERS MATCHING ANALYSIS' as section_title;

-- Товары, которые продаются на Ozon, но не имеют sku_ozon
SELECT 
    'Missing Ozon SKU' as issue_type,
    COUNT(DISTINCT dp.id) as affected_products,
    SUM(fo.qty * fo.price) as lost_revenue_tracking
FROM dim_products dp
JOIN fact_orders fo ON dp.id = fo.product_id
JOIN sources s ON fo.source_id = s.id
WHERE (s.code LIKE '%ozon%' OR s.name LIKE '%ozon%')
    AND dp.sku_ozon IS NULL
    AND fo.order_date >= DATE_SUB(CURDATE(), INTERVAL 90 DAY);

-- Товары, которые продаются на Wildberries, но не имеют sku_wb
SELECT 
    'Missing Wildberries SKU' as issue_type,
    COUNT(DISTINCT dp.id) as affected_products,
    SUM(fo.qty * fo.price) as lost_revenue_tracking
FROM dim_products dp
JOIN fact_orders fo ON dp.id = fo.product_id
JOIN sources s ON fo.source_id = s.id
WHERE (s.code LIKE '%wb%' OR s.code LIKE '%wildberries%' OR s.name LIKE '%wildberries%')
    AND dp.sku_wb IS NULL
    AND fo.order_date >= DATE_SUB(CURDATE(), INTERVAL 90 DAY);

-- Детали товаров с отсутствующими SKU для Ozon
SELECT 
    'Missing Ozon SKU Details' as details_type,
    dp.id,
    dp.name,
    dp.brand,
    dp.category,
    COUNT(fo.id) as ozon_orders_count,
    SUM(fo.qty * fo.price) as ozon_revenue,
    'Add sku_ozon field' as recommended_action
FROM dim_products dp
JOIN fact_orders fo ON dp.id = fo.product_id
JOIN sources s ON fo.source_id = s.id
WHERE (s.code LIKE '%ozon%' OR s.name LIKE '%ozon%')
    AND dp.sku_ozon IS NULL
    AND fo.order_date >= DATE_SUB(CURDATE(), INTERVAL 90 DAY)
GROUP BY dp.id, dp.name, dp.brand, dp.category
ORDER BY ozon_revenue DESC
LIMIT 10;

-- ===================================================================
-- 3. АНАЛИЗ ИСТОЧНИКОВ ДАННЫХ
-- ===================================================================

SELECT '3. DATA SOURCES ANALYSIS' as section_title;

-- Статистика по источникам данных
SELECT 
    'Sources Statistics' as analysis_type,
    s.id,
    s.code,
    s.name,
    COUNT(fo.id) as orders_count,
    SUM(fo.qty * fo.price) as total_revenue,
    COUNT(DISTINCT fo.product_id) as unique_products,
    MIN(fo.order_date) as first_order,
    MAX(fo.order_date) as last_order,
    CASE 
        WHEN s.code LIKE '%ozon%' OR s.name LIKE '%ozon%' THEN 'ozon'
        WHEN s.code LIKE '%wb%' OR s.code LIKE '%wildberries%' OR s.name LIKE '%wildberries%' THEN 'wildberries'
        ELSE 'unknown'
    END as detected_marketplace
FROM sources s
LEFT JOIN fact_orders fo ON s.id = fo.source_id 
    AND fo.order_date >= DATE_SUB(CURDATE(), INTERVAL 90 DAY)
GROUP BY s.id, s.code, s.name
ORDER BY total_revenue DESC;

-- Источники с неопределенным маркетплейсом
SELECT 
    'Unknown Marketplace Sources' as issue_type,
    s.id,
    s.code,
    s.name,
    COUNT(fo.id) as orders_count,
    SUM(fo.qty * fo.price) as revenue_impact,
    'Update source code/name for marketplace detection' as recommended_action
FROM sources s
LEFT JOIN fact_orders fo ON s.id = fo.source_id 
    AND fo.order_date >= DATE_SUB(CURDATE(), INTERVAL 90 DAY)
WHERE NOT (s.code LIKE '%ozon%' OR s.name LIKE '%ozon%' 
          OR s.code LIKE '%wb%' OR s.code LIKE '%wildberries%' OR s.name LIKE '%wildberries%')
    AND COUNT(fo.id) > 0
GROUP BY s.id, s.code, s.name
HAVING orders_count > 0
ORDER BY revenue_impact DESC;

-- ===================================================================
-- 4. ПРОВЕРКА КОНСИСТЕНТНОСТИ ДАННЫХ
-- ===================================================================

SELECT '4. DATA CONSISTENCY CHECK' as section_title;

-- Сравнение общих показателей с суммой по маркетплейсам
WITH total_stats AS (
    SELECT 
        COUNT(DISTINCT order_id) as total_orders,
        SUM(qty * price) as total_revenue,
        COUNT(DISTINCT product_id) as total_products
    FROM fact_orders fo
    WHERE fo.order_date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
        AND fo.transaction_type IN ('продажа', 'sale', 'order')
),
marketplace_stats AS (
    SELECT 
        SUM(CASE WHEN s.code LIKE '%ozon%' OR s.name LIKE '%ozon%' 
                 OR (dp.sku_ozon IS NOT NULL AND fo.sku = dp.sku_ozon) 
             THEN 1 ELSE 0 END) as ozon_orders,
        SUM(CASE WHEN s.code LIKE '%wb%' OR s.code LIKE '%wildberries%' OR s.name LIKE '%wildberries%'
                 OR (dp.sku_wb IS NOT NULL AND fo.sku = dp.sku_wb)
             THEN 1 ELSE 0 END) as wb_orders,
        SUM(CASE WHEN s.code LIKE '%ozon%' OR s.name LIKE '%ozon%' 
                 OR (dp.sku_ozon IS NOT NULL AND fo.sku = dp.sku_ozon) 
             THEN fo.qty * fo.price ELSE 0 END) as ozon_revenue,
        SUM(CASE WHEN s.code LIKE '%wb%' OR s.code LIKE '%wildberries%' OR s.name LIKE '%wildberries%'
                 OR (dp.sku_wb IS NOT NULL AND fo.sku = dp.sku_wb)
             THEN fo.qty * fo.price ELSE 0 END) as wb_revenue
    FROM fact_orders fo
    JOIN sources s ON fo.source_id = s.id
    LEFT JOIN dim_products dp ON fo.product_id = dp.id
    WHERE fo.order_date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
        AND fo.transaction_type IN ('продажа', 'sale', 'order')
)
SELECT 
    'Data Consistency Check' as check_type,
    ts.total_orders,
    ms.ozon_orders + ms.wb_orders as marketplace_orders_sum,
    ts.total_orders - (ms.ozon_orders + ms.wb_orders) as orders_difference,
    ROUND(ts.total_revenue, 2) as total_revenue,
    ROUND(ms.ozon_revenue + ms.wb_revenue, 2) as marketplace_revenue_sum,
    ROUND(ts.total_revenue - (ms.ozon_revenue + ms.wb_revenue), 2) as revenue_difference,
    CASE 
        WHEN ABS(ts.total_orders - (ms.ozon_orders + ms.wb_orders)) = 0 THEN 'CONSISTENT'
        WHEN ABS(ts.total_orders - (ms.ozon_orders + ms.wb_orders)) <= ts.total_orders * 0.05 THEN 'MINOR_DISCREPANCY'
        ELSE 'MAJOR_DISCREPANCY'
    END as consistency_status
FROM total_stats ts, marketplace_stats ms;

-- ===================================================================
-- 5. ПРОВЕРКА ДУБЛИРУЮЩИХСЯ ЗАКАЗОВ
-- ===================================================================

SELECT '5. DUPLICATE ORDERS CHECK' as section_title;

-- Поиск дублирующихся заказов
SELECT 
    'Duplicate Orders Summary' as check_type,
    COUNT(*) as duplicate_groups,
    SUM(duplicate_count - 1) as extra_duplicate_records
FROM (
    SELECT 
        order_id,
        sku,
        order_date,
        COUNT(*) as duplicate_count
    FROM fact_orders
    WHERE order_date >= DATE_SUB(CURDATE(), INTERVAL 90 DAY)
    GROUP BY order_id, sku, order_date
    HAVING COUNT(*) > 1
) duplicates;

-- Детали дублирующихся заказов
SELECT 
    'Duplicate Orders Details' as details_type,
    order_id,
    sku,
    order_date,
    COUNT(*) as duplicate_count,
    GROUP_CONCAT(id) as duplicate_record_ids,
    GROUP_CONCAT(DISTINCT source_id) as involved_sources,
    SUM(qty * price) as total_duplicate_revenue
FROM fact_orders
WHERE order_date >= DATE_SUB(CURDATE(), INTERVAL 90 DAY)
GROUP BY order_id, sku, order_date
HAVING COUNT(*) > 1
ORDER BY duplicate_count DESC, total_duplicate_revenue DESC
LIMIT 10;

-- ===================================================================
-- 6. РЕКОМЕНДАЦИИ ПО УЛУЧШЕНИЮ КАЧЕСТВА ДАННЫХ
-- ===================================================================

SELECT '6. DATA QUALITY RECOMMENDATIONS' as section_title;

-- Сводка рекомендаций
SELECT 
    'Data Quality Recommendations' as recommendation_type,
    'HIGH' as priority,
    'Fix missing SKU assignments' as recommendation,
    'Add sku_ozon for products selling on Ozon, sku_wb for Wildberries products' as action_required
UNION ALL
SELECT 
    'Data Quality Recommendations',
    'HIGH',
    'Resolve conflicting SKU assignments',
    'Ensure sku_ozon and sku_wb are different for each product'
UNION ALL
SELECT 
    'Data Quality Recommendations',
    'MEDIUM',
    'Improve marketplace classification',
    'Update source codes/names to include marketplace identifiers'
UNION ALL
SELECT 
    'Data Quality Recommendations',
    'MEDIUM',
    'Remove duplicate orders',
    'Implement stricter unique constraints and data validation'
UNION ALL
SELECT 
    'Data Quality Recommendations',
    'LOW',
    'Monitor data consistency',
    'Set up regular automated checks for data consistency';

-- ===================================================================
-- 7. СОЗДАНИЕ ПРЕДСТАВЛЕНИЙ ДЛЯ МОНИТОРИНГА
-- ===================================================================

-- Создаем представление для мониторинга качества данных маркетплейсов
CREATE OR REPLACE VIEW v_marketplace_data_quality AS
SELECT 
    'sku_coverage' as metric_name,
    ROUND(SUM(CASE WHEN sku_ozon IS NOT NULL THEN 1 ELSE 0 END) / COUNT(*) * 100, 2) as ozon_value,
    ROUND(SUM(CASE WHEN sku_wb IS NOT NULL THEN 1 ELSE 0 END) / COUNT(*) * 100, 2) as wb_value,
    'percentage' as unit,
    CASE 
        WHEN (SUM(CASE WHEN sku_ozon IS NOT NULL THEN 1 ELSE 0 END) / COUNT(*) * 100) >= 90 
         AND (SUM(CASE WHEN sku_wb IS NOT NULL THEN 1 ELSE 0 END) / COUNT(*) * 100) >= 90 
        THEN 'GOOD'
        ELSE 'NEEDS_IMPROVEMENT'
    END as status
FROM dim_products

UNION ALL

SELECT 
    'conflicting_sku_count',
    COUNT(*),
    0,
    'count',
    CASE WHEN COUNT(*) = 0 THEN 'GOOD' ELSE 'CRITICAL' END
FROM dim_products 
WHERE sku_ozon IS NOT NULL AND sku_wb IS NOT NULL AND sku_ozon = sku_wb;

-- Представление для мониторинга источников данных
CREATE OR REPLACE VIEW v_marketplace_sources_quality AS
SELECT 
    s.id,
    s.code,
    s.name,
    CASE 
        WHEN s.code LIKE '%ozon%' OR s.name LIKE '%ozon%' THEN 'ozon'
        WHEN s.code LIKE '%wb%' OR s.code LIKE '%wildberries%' OR s.name LIKE '%wildberries%' THEN 'wildberries'
        ELSE 'unknown'
    END as detected_marketplace,
    COUNT(fo.id) as recent_orders_count,
    SUM(fo.qty * fo.price) as recent_revenue,
    CASE 
        WHEN s.code LIKE '%ozon%' OR s.name LIKE '%ozon%' 
          OR s.code LIKE '%wb%' OR s.code LIKE '%wildberries%' OR s.name LIKE '%wildberries%' 
        THEN 'CLASSIFIED'
        ELSE 'UNCLASSIFIED'
    END as classification_status
FROM sources s
LEFT JOIN fact_orders fo ON s.id = fo.source_id 
    AND fo.order_date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
GROUP BY s.id, s.code, s.name;

SELECT 'Marketplace data validation completed successfully!' as status;
SELECT 'Views created: v_marketplace_data_quality, v_marketplace_sources_quality' as created_objects;