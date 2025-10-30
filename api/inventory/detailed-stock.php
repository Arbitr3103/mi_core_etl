<?php
/**
 * Detailed Stock API Endpoint
 * Returns inventory data with warehouse breakdown
 * Supports actions: warehouses, summary, or default (detailed stock list)
 */

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

try {
    // Connect to PostgreSQL
    $dsn = "pgsql:host=localhost;port=5432;dbname=mi_core_db";
    $pdo = new PDO($dsn, 'mi_core_user', 'MiCore2025Secure', [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
    ]);
    
    // Get action parameter
    $action = $_GET['action'] ?? 'list';
    
    // Handle different actions
    switch ($action) {
        case 'warehouses':
            handleWarehousesAction($pdo);
            break;
        case 'summary':
            handleSummaryAction($pdo);
            break;
        default:
            handleListAction($pdo);
            break;
    }
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage(),
        'timestamp' => date('Y-m-d H:i:s')
    ], JSON_PRETTY_PRINT);
}

/**
 * Get list of warehouses with stock counts
 */
function handleWarehousesAction($pdo) {
    $query = "
        SELECT 
            i.warehouse_name,
            COUNT(DISTINCT dp.id) as product_count,
            SUM(i.quantity_present) as total_present,
            SUM(i.quantity_reserved) as total_reserved,
            SUM(i.quantity_present - i.quantity_reserved) as total_available,
            COUNT(CASE WHEN (i.quantity_present - i.quantity_reserved) <= 0 THEN 1 END) as out_of_stock_count,
            COUNT(CASE WHEN (i.quantity_present - i.quantity_reserved) > 0 AND (i.quantity_present - i.quantity_reserved) < 20 THEN 1 END) as critical_count,
            COUNT(CASE WHEN (i.quantity_present - i.quantity_reserved) >= 20 AND (i.quantity_present - i.quantity_reserved) < 50 THEN 1 END) as low_count,
            COUNT(CASE WHEN (i.quantity_present - i.quantity_reserved) < 20 THEN 1 END) as replenishment_needed_count
        FROM inventory i
        JOIN dim_products dp ON i.product_id = dp.id
        WHERE 1=1
        GROUP BY i.warehouse_name
        ORDER BY i.warehouse_name
    ";
    
    $stmt = $pdo->query($query);
    $warehouses = $stmt->fetchAll();
    
    echo json_encode([
        'success' => true,
        'data' => $warehouses,
        'meta' => [
            'total_warehouses' => count($warehouses),
            'action' => 'warehouses'
        ],
        'timestamp' => date('Y-m-d H:i:s')
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
}

/**
 * Get summary statistics
 */
function handleSummaryAction($pdo) {
    $query = "
        SELECT 
            COUNT(DISTINCT dp.id) as total_products,
            COUNT(DISTINCT i.warehouse_name) as total_warehouses,
            SUM(i.quantity_present) as total_present,
            SUM(i.quantity_reserved) as total_reserved,
            SUM(i.quantity_present - i.quantity_reserved) as total_available,
            COUNT(CASE WHEN (i.quantity_present - i.quantity_reserved) <= 0 THEN 1 END) as out_of_stock_count,
            COUNT(CASE WHEN (i.quantity_present - i.quantity_reserved) > 0 AND (i.quantity_present - i.quantity_reserved) < 20 THEN 1 END) as critical_count,
            COUNT(CASE WHEN (i.quantity_present - i.quantity_reserved) >= 20 AND (i.quantity_present - i.quantity_reserved) < 50 THEN 1 END) as low_count,
            COUNT(CASE WHEN (i.quantity_present - i.quantity_reserved) >= 50 AND (i.quantity_present - i.quantity_reserved) < 100 THEN 1 END) as normal_count,
            COUNT(CASE WHEN (i.quantity_present - i.quantity_reserved) >= 100 THEN 1 END) as excess_count
        FROM inventory i
        JOIN dim_products dp ON i.product_id = dp.id
        WHERE 1=1
    ";
    
    $stmt = $pdo->query($query);
    $summary = $stmt->fetch();
    
    echo json_encode([
        'success' => true,
        'data' => $summary,
        'meta' => [
            'action' => 'summary'
        ],
        'timestamp' => date('Y-m-d H:i:s')
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
}

/**
 * Get detailed stock list with filters
 */
function handleListAction($pdo) {
    // Get query parameters
    $limit = isset($_GET['limit']) ? min((int)$_GET['limit'], 1000) : 100;
    $offset = isset($_GET['offset']) ? max((int)$_GET['offset'], 0) : 0;
    $stockStatus = $_GET['stock_status'] ?? null;
    $warehouse = $_GET['warehouse'] ?? null;
    $search = $_GET['search'] ?? null;
    
    // Build query
    $whereConditions = ["1=1"];
    $params = [];
    
    if ($warehouse) {
        $whereConditions[] = "i.warehouse_name = :warehouse";
        $params['warehouse'] = $warehouse;
    }
    
    if ($search) {
        $whereConditions[] = "(dp.product_name ILIKE :search OR dp.sku_ozon ILIKE :search)";
        $params['search'] = "%$search%";
    }
    
    $whereClause = implode(' AND ', $whereConditions);
    
    $query = "
        SELECT 
            dp.id as product_id,
            dp.sku_ozon as offer_id,
            dp.product_name,
            NULL as visibility,
            i.warehouse_name,
            i.stock_type,
            i.quantity_present as present,
            i.quantity_reserved as reserved,
            (i.quantity_present - i.quantity_reserved) as available_stock,
            CASE 
                WHEN (i.quantity_present - i.quantity_reserved) <= 0 THEN 'out_of_stock'
                WHEN (i.quantity_present - i.quantity_reserved) < 20 THEN 'critical'
                WHEN (i.quantity_present - i.quantity_reserved) < 50 THEN 'low'
                WHEN (i.quantity_present - i.quantity_reserved) < 100 THEN 'normal'
                ELSE 'excess'
            END as stock_status,
            i.updated_at as last_updated
        FROM dim_products dp
        LEFT JOIN inventory i ON dp.id = i.product_id
        WHERE $whereClause
        ORDER BY dp.product_name, i.warehouse_name
        LIMIT :limit OFFSET :offset
    ";
    
    $stmt = $pdo->prepare($query);
    foreach ($params as $key => $value) {
        $stmt->bindValue(":$key", $value);
    }
    $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmt->execute();
    
    $data = $stmt->fetchAll();
    
    // Get total count
    $countQuery = "
        SELECT COUNT(*) as total
        FROM dim_products dp
        LEFT JOIN inventory i ON dp.id = i.product_id
        WHERE $whereClause
    ";
    
    $countStmt = $pdo->prepare($countQuery);
    foreach ($params as $key => $value) {
        $countStmt->bindValue(":$key", $value);
    }
    $countStmt->execute();
    $total = $countStmt->fetch()['total'];
    
    echo json_encode([
        'success' => true,
        'data' => $data,
        'meta' => [
            'total_count' => (int)$total,
            'filtered_count' => count($data),
            'limit' => $limit,
            'offset' => $offset,
            'filters_applied' => [
                'warehouse' => $warehouse,
                'stock_status' => $stockStatus,
                'search' => $search
            ]
        ],
        'timestamp' => date('Y-m-d H:i:s')
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
}
