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
    $pdo = new PDO($dsn, 'api_user', 'ApiUser2025Secure', [
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
    // Агрегация по вью с обязательным бэкенд-фильтром (без archived_or_hidden)
    $query = "
        SELECT 
            v.warehouse_name,
            COUNT(*) as product_count,
            SUM(v.current_stock) as total_present,
            0 as total_reserved,
            SUM(v.available_stock) as total_available,
            SUM(CASE WHEN v.stock_status = 'out_of_stock' THEN 1 ELSE 0 END) as out_of_stock_count,
            SUM(CASE WHEN v.stock_status = 'critical' THEN 1 ELSE 0 END) as critical_count,
            SUM(CASE WHEN COALESCE(v.recommended_qty,0) > 0 THEN 1 ELSE 0 END) as replenishment_needed_count
        FROM v_detailed_inventory v
        WHERE v.stock_status <> 'archived_or_hidden'
          AND v.available_stock > 0
          AND v.warehouse_name IS NOT NULL
        GROUP BY v.warehouse_name
        ORDER BY v.warehouse_name
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
    // Сводка по активным товарам (без archived_or_hidden)
    $query = "
        SELECT 
            COUNT(*) as total_products,
            COUNT(DISTINCT v.warehouse_name) as total_warehouses,
            SUM(v.current_stock) as total_present,
            0 as total_reserved,
            SUM(v.available_stock) as total_available,
            SUM(CASE WHEN v.stock_status = 'out_of_stock' THEN 1 ELSE 0 END) as out_of_stock_count,
            SUM(CASE WHEN v.stock_status = 'critical' THEN 1 ELSE 0 END) as critical_count,
            SUM(CASE WHEN v.stock_status = 'low' THEN 1 ELSE 0 END) as low_count,
            SUM(CASE WHEN v.stock_status = 'normal' THEN 1 ELSE 0 END) as normal_count,
            SUM(CASE WHEN v.stock_status = 'excess' THEN 1 ELSE 0 END) as excess_count,
            SUM(COALESCE(v.recommended_qty,0)) as total_recommended_qty
        FROM v_detailed_inventory v
        WHERE v.stock_status <> 'archived_or_hidden'
          AND v.available_stock > 0
          AND v.warehouse_name IS NOT NULL
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
    $stockStatus = $_GET['stock_status'] ?? null; // игнорируется по умолчанию
    $warehouse = $_GET['warehouse'] ?? null;
    $search = $_GET['search'] ?? null;
    $sortBy = $_GET['sortBy'] ?? 'days_of_stock';
    $sortOrder = strtoupper($_GET['sortOrder'] ?? 'ASC');
    if (!in_array($sortOrder, ['ASC','DESC'])) { $sortOrder = 'ASC'; }

    // Build query (вью с обязательным фильтром активных)
    $whereConditions = [
        "v.stock_status <> 'archived_or_hidden'",
        "v.available_stock > 0",
        "v.warehouse_name IS NOT NULL"
    ];
    $params = [];
    
    if ($warehouse) {
        $whereConditions[] = "v.warehouse_name = :warehouse";
        $params['warehouse'] = $warehouse;
    }
    
    if ($search) {
        $whereConditions[] = "(v.product_name ILIKE :search OR v.sku ILIKE :search OR v.sku_ozon ILIKE :search)";
        $params['search'] = "%$search%";
    }
    
    $whereClause = implode(' AND ', $whereConditions);
    
    $query = "
        SELECT 
            v.product_id,
            v.product_name,
            v.sku as offer_id,
            v.visibility,
            v.warehouse_name,
            NULL as stock_type,
            v.current_stock as present,
            0 as reserved,
            v.available_stock,
            v.stock_status,
            v.last_updated,
            v.days_of_stock,
            -- Рекомендация и стоимость берём из вью (временный хотфикс до обновления вью)
            COALESCE(v.recommended_qty, 0)::int AS recommended_qty,
            COALESCE(v.recommended_value, 0)::numeric AS recommended_value
        FROM v_detailed_inventory v
        WHERE $whereClause
        ORDER BY " . ($sortBy === 'days_of_stock' ? 'v.days_of_stock' : 'v.days_of_stock') . " $sortOrder
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
        FROM v_detailed_inventory v
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
