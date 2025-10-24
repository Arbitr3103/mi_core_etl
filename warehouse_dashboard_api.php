<?php
/**
 * API Endpoint for Warehouse Dashboard
 * Part of task 2.6: Создать дашборд мониторинга складов с аналитикой продаж
 */

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

// Handle preflight requests
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

include 'config/database_postgresql.php';

try {
    // Get database connection
    $pdo = getDatabaseConnection();
    
    if (!$pdo) {
        throw new Exception("Failed to establish database connection");
    }
    
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
    
    // Get activity filter parameter
    $activity_filter = $_GET['activity_filter'] ?? 'active';
    
    // Build activity filter condition
    $activity_condition = getActivityFilterCondition($total_stock_formula, $activity_filter);
    
    $endpoint = $_GET['endpoint'] ?? 'summary';
    
    switch ($endpoint) {
        case 'summary':
            echo json_encode(getSummaryData($pdo, $total_stock_formula, $activity_condition, $activity_filter));
            break;
            
        case 'warehouses':
            echo json_encode(getWarehousesData($pdo, $total_stock_formula, $activity_condition, $activity_filter));
            break;
            
        case 'urgent':
            echo json_encode(getUrgentReplenishments($pdo, $total_stock_formula, $activity_condition, $activity_filter));
            break;
            
        case 'charts':
            echo json_encode(getChartsData($pdo, $total_stock_formula, $activity_condition, $activity_filter));
            break;
            
        case 'export':
            echo json_encode(getExportData($pdo, $total_stock_formula, $activity_condition, $activity_filter));
            break;
            
        default:
            http_response_code(404);
            echo json_encode(['error' => 'Endpoint not found']);
    }
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}

function getSummaryData($pdo, $total_stock_formula, $activity_condition, $activity_filter) {
    $stmt = $pdo->prepare("
        SELECT 
            COUNT(DISTINCT i.warehouse_name) as total_warehouses,
            COUNT(DISTINCT i.product_id) as unique_products,
            SUM($total_stock_formula) as total_stock_value,
            COUNT(DISTINCT wsm.product_id) as products_with_sales,
            COALESCE(SUM(wsm.sales_last_28_days), 0) as total_sales_28d,
            COALESCE(AVG(wsm.daily_sales_avg), 0) as avg_daily_sales_rate,
            
            -- Activity metrics
            COUNT(CASE WHEN ($total_stock_formula) > 0 THEN 1 END) as active_products,
            COUNT(CASE WHEN ($total_stock_formula) = 0 THEN 1 END) as inactive_products,
            
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
        WHERE 1=1 $activity_condition
    ");
    $stmt->execute();
    $summary = $stmt->fetch(PDO::FETCH_ASSOC);
    
    return [
        'success' => true,
        'data' => [
            'totalWarehouses' => (int)$summary['total_warehouses'],
            'uniqueProducts' => (int)$summary['unique_products'],
            'totalStock' => (int)$summary['total_stock_value'],
            'sales28d' => (int)$summary['total_sales_28d'],
            'avgDailySales' => round($summary['avg_daily_sales_rate'], 2),
            'activeProducts' => (int)$summary['active_products'],
            'inactiveProducts' => (int)$summary['inactive_products'],
            'criticalProducts' => (int)$summary['critical_products'],
            'attentionProducts' => (int)$summary['attention_products'],
            'excessProducts' => (int)$summary['excess_products'],
            'replenishmentNeed' => (int)$summary['total_replenishment_need'],
            'investmentNeeded' => round($summary['estimated_investment_needed'] / 1000000, 2)
        ],
        'metadata' => [
            'activityFilter' => $activity_filter,
            'timestamp' => date('Y-m-d H:i:s')
        ]
    ];
}

function getWarehousesData($pdo, $total_stock_formula, $activity_condition, $activity_filter) {
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
                WHEN COUNT(CASE WHEN ($total_stock_formula) < wsm.daily_sales_avg * 7 THEN 1 END) > 0 THEN 'critical'
                WHEN COUNT(CASE WHEN ($total_stock_formula) < wsm.daily_sales_avg * 14 THEN 1 END) > 2 THEN 'warning'
                WHEN COUNT(CASE WHEN ($total_stock_formula) < wsm.daily_sales_avg * 30 THEN 1 END) > 3 THEN 'normal'
                ELSE 'excess'
            END as warehouse_status,
            
            -- Efficiency metrics
            CASE 
                WHEN AVG(wsm.daily_sales_avg) > 0 AND SUM($total_stock_formula) > 0 THEN 
                    ROUND((AVG(wsm.daily_sales_avg) * 365) / SUM($total_stock_formula), 2)
                ELSE 0
            END as turnover_rate
            
        FROM inventory i
        LEFT JOIN warehouse_sales_metrics wsm ON i.product_id = wsm.product_id 
            AND i.warehouse_name = wsm.warehouse_name
        WHERE 1=1 $activity_condition
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
    
    $result = [];
    foreach ($warehouses as $wh) {
        $result[] = [
            'name' => $wh['warehouse_name'],
            'products' => (int)$wh['total_products'],
            'totalStock' => (int)$wh['total_stock'],
            'activeProducts' => (int)$wh['active_products'],
            'inactiveProducts' => (int)$wh['inactive_products'],
            'sales28d' => (int)$wh['sales_28d'],
            'avgDailySales' => round($wh['avg_daily_sales'], 2),
            'daysOfStock' => $wh['days_of_stock'] ? (float)$wh['days_of_stock'] : null,
            'monthsOfStock' => $wh['months_of_stock'] ? (float)$wh['months_of_stock'] : null,
            'urgentItems' => (int)$wh['urgent_replenishment'],
            'plannedItems' => (int)$wh['planned_replenishment'],
            'recommendedOrder' => (int)$wh['recommended_order_qty'],
            'status' => $wh['warehouse_status'],
            'turnoverRate' => (float)$wh['turnover_rate'],
            'estimatedOrderValue' => round($wh['recommended_order_qty'] * 1000 / 1000000, 2)
        ];
    }
    
    return [
        'success' => true,
        'data' => $result,
        'metadata' => [
            'activityFilter' => $activity_filter,
            'timestamp' => date('Y-m-d H:i:s')
        ]
    ];
}

function getUrgentReplenishments($pdo, $total_stock_formula, $activity_condition, $activity_filter) {
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
            
            -- Recommended order
            GREATEST(0, ROUND(wsm.daily_sales_avg * 60, 0) - ($total_stock_formula)) as recommended_qty,
            
            -- Priority score
            CASE 
                WHEN wsm.daily_sales_avg = 0 THEN 0
                WHEN ($total_stock_formula) < wsm.daily_sales_avg * 7 THEN 100
                WHEN ($total_stock_formula) < wsm.daily_sales_avg * 14 THEN 80
                WHEN ($total_stock_formula) < wsm.daily_sales_avg * 30 THEN 60
                ELSE 20
            END as priority_score,
            
            -- Urgency level
            CASE 
                WHEN wsm.daily_sales_avg = 0 THEN 'no_sales'
                WHEN ($total_stock_formula) < wsm.daily_sales_avg * 3 THEN 'immediate'
                WHEN ($total_stock_formula) < wsm.daily_sales_avg * 7 THEN 'urgent'
                WHEN ($total_stock_formula) < wsm.daily_sales_avg * 14 THEN 'high'
                WHEN ($total_stock_formula) < wsm.daily_sales_avg * 30 THEN 'medium'
                ELSE 'low'
            END as urgency_level
            
        FROM inventory i
        INNER JOIN warehouse_sales_metrics wsm ON i.product_id = wsm.product_id 
            AND i.warehouse_name = wsm.warehouse_name
        WHERE wsm.daily_sales_avg > 0 
            AND ($total_stock_formula) < wsm.daily_sales_avg * 30
            $activity_condition
        ORDER BY priority_score DESC, wsm.daily_sales_avg DESC
        LIMIT 20
    ");
    $stmt->execute();
    $urgent = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $result = [];
    foreach ($urgent as $item) {
        $result[] = [
            'warehouseName' => $item['warehouse_name'],
            'productId' => (int)$item['product_id'],
            'currentStock' => (int)$item['current_stock'],
            'dailySales' => round($item['daily_sales_avg'], 2),
            'sales28d' => (int)$item['sales_last_28_days'],
            'daysUntilStockout' => $item['days_until_stockout'] ? (int)$item['days_until_stockout'] : null,
            'recommendedQty' => (int)$item['recommended_qty'],
            'priorityScore' => (int)$item['priority_score'],
            'urgencyLevel' => $item['urgency_level']
        ];
    }
    
    return [
        'success' => true,
        'data' => $result,
        'metadata' => [
            'activityFilter' => $activity_filter,
            'timestamp' => date('Y-m-d H:i:s')
        ]
    ];
}

function getChartsData($pdo, $total_stock_formula, $activity_condition, $activity_filter) {
    // Sales trend data
    $stmt = $pdo->prepare("
        SELECT 
            i.warehouse_name,
            SUM($total_stock_formula) as total_stock,
            COALESCE(SUM(wsm.sales_last_28_days), 0) as sales_28d,
            COALESCE(AVG(wsm.daily_sales_avg), 0) as avg_daily_sales
        FROM inventory i
        LEFT JOIN warehouse_sales_metrics wsm ON i.product_id = wsm.product_id 
            AND i.warehouse_name = wsm.warehouse_name
        WHERE 1=1 $activity_condition
        GROUP BY i.warehouse_name
        ORDER BY sales_28d DESC
    ");
    $stmt->execute();
    $salesTrend = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Stock distribution data
    $stmt = $pdo->prepare("
        SELECT 
            CASE 
                WHEN wsm.daily_sales_avg = 0 THEN 'no_sales'
                WHEN ($total_stock_formula) < wsm.daily_sales_avg * 7 THEN 'critical'
                WHEN ($total_stock_formula) < wsm.daily_sales_avg * 30 THEN 'low'
                WHEN ($total_stock_formula) < wsm.daily_sales_avg * 60 THEN 'normal'
                ELSE 'excess'
            END as stock_category,
            COUNT(*) as count
        FROM inventory i
        LEFT JOIN warehouse_sales_metrics wsm ON i.product_id = wsm.product_id 
            AND i.warehouse_name = wsm.warehouse_name
        WHERE 1=1 $activity_condition
        GROUP BY stock_category
    ");
    $stmt->execute();
    $stockDistribution = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    return [
        'success' => true,
        'data' => [
            'salesTrend' => $salesTrend,
            'stockDistribution' => $stockDistribution
        ],
        'metadata' => [
            'activityFilter' => $activity_filter,
            'timestamp' => date('Y-m-d H:i:s')
        ]
    ];
}

function getExportData($pdo, $total_stock_formula, $activity_condition, $activity_filter) {
    $stmt = $pdo->prepare("
        SELECT 
            i.warehouse_name,
            i.product_id,
            ($total_stock_formula) as current_stock,
            wsm.daily_sales_avg,
            wsm.sales_last_28_days,
            GREATEST(0, ROUND(wsm.daily_sales_avg * 60, 0) - ($total_stock_formula)) as recommended_qty,
            GREATEST(0, ROUND(wsm.daily_sales_avg * 60, 0) - ($total_stock_formula)) * 1000 as estimated_cost
        FROM inventory i
        INNER JOIN warehouse_sales_metrics wsm ON i.product_id = wsm.product_id 
            AND i.warehouse_name = wsm.warehouse_name
        WHERE wsm.daily_sales_avg > 0 $activity_condition
        ORDER BY i.warehouse_name, recommended_qty DESC
    ");
    $stmt->execute();
    $exportData = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $totalQty = 0;
    $totalCost = 0;
    
    foreach ($exportData as $item) {
        $totalQty += $item['recommended_qty'];
        $totalCost += $item['estimated_cost'];
    }
    
    return [
        'success' => true,
        'data' => [
            'items' => $exportData,
            'summary' => [
                'totalQuantity' => $totalQty,
                'totalCost' => $totalCost,
                'totalCostMillions' => round($totalCost / 1000000, 2)
            ]
        ],
        'metadata' => [
            'activityFilter' => $activity_filter,
            'timestamp' => date('Y-m-d H:i:s')
        ]
    ];
}

function getActivityFilterCondition($total_stock_formula, $activity_filter) {
    switch ($activity_filter) {
        case 'active':
            return "AND ($total_stock_formula) > 0";
        case 'inactive':
            return "AND ($total_stock_formula) = 0";
        case 'all':
        default:
            return "";
    }
}
?>