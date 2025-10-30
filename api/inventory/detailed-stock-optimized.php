<?php
/**
 * Optimized Detailed Stock API Endpoint
 * 
 * Enhanced version with:
 * - Query result caching
 * - Optimized JOIN operations
 * - Performance monitoring
 * - Materialized view usage
 */

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

// Include query cache
require_once __DIR__ . '/../../src/Database/QueryCache.php';

use Database\QueryCache;

try {
    $startTime = microtime(true);
    
    // Connect to PostgreSQL
    $dsn = "pgsql:host=localhost;port=5432;dbname=mi_core_db";
    $pdo = new PDO($dsn, 'mi_core_user', 'MiCore2025Secure', [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
    ]);
    
    // Initialize cache (5 minute TTL for most queries)
    $cache = new QueryCache('/tmp/warehouse_cache', 300, true);
    
    // Get action parameter
    $action = $_GET['action'] ?? 'list';
    
    // Handle different actions
    switch ($action) {
        case 'warehouses':
            handleWarehousesAction($pdo, $cache);
            break;
        case 'summary':
            handleSummaryAction($pdo, $cache);
            break;
        case 'cache_stats':
            handleCacheStatsAction($cache);
            break;
        case 'clear_cache':
            handleClearCacheAction($cache);
            break;
        default:
            handleListAction($pdo, $cache);
            break;
    }
    
    // Log query performance
    $executionTime = round((microtime(true) - $startTime) * 1000, 2);
    logQueryPerformance($pdo, $action, $executionTime);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage(),
        'timestamp' => date('Y-m-d H:i:s')
    ], JSON_PRETTY_PRINT);
}

/**
 * Get list of warehouses with stock counts (uses materialized view)
 */
function handleWarehousesAction($pdo, $cache) {
    $cacheKey = 'warehouses_summary';
    
    $result = $cache->remember($cacheKey, function() use ($pdo) {
        // Use materialized view for fast aggregation
        $query = "
            SELECT 
                warehouse_name,
                source,
                product_count,
                total_present,
                total_reserved,
                total_available,
                out_of_stock_count,
                critical_count,
                low_count,
                normal_count,
                excess_count,
                last_updated
            FROM mv_warehouse_summary
            ORDER BY warehouse_name, source
        ";
        
        $stmt = $pdo->query($query);
        return $stmt->fetchAll();
    }, 300); // 5 minute cache
    
    echo json_encode([
        'success' => true,
        'data' => $result,
        'meta' => [
            'total_warehouses' => count($result),
            'action' => 'warehouses',
            'cached' => true,
            'data_source' => 'materialized_view'
        ],
        'timestamp' => date('Y-m-d H:i:s')
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
}

/**
 * Get summary statistics (optimized with covering indexes)
 */
function handleSummaryAction($pdo, $cache) {
    $cacheKey = 'inventory_summary';
    
    $result = $cache->remember($cacheKey, function() use ($pdo) {
        // Optimized query using covering indexes
        $query = "
            SELECT 
                COUNT(DISTINCT dp.id) as total_products,
                COUNT(DISTINCT i.warehouse_name) as total_warehouses,
                SUM(i.quantity_present) as total_present,
                SUM(i.quantity_reserved) as total_reserved,
                SUM(i.quantity_present - i.quantity_reserved) as total_available,
                COUNT(CASE WHEN (i.quantity_present - i.quantity_reserved) <= 0 THEN 1 END) as out_of_stock_count,
                COUNT(CASE WHEN (i.quantity_present - i.quantity_reserved) > 0 
                           AND (i.quantity_present - i.quantity_reserved) < 20 THEN 1 END) as critical_count,
                COUNT(CASE WHEN (i.quantity_present - i.quantity_reserved) >= 20 
                           AND (i.quantity_present - i.quantity_reserved) < 50 THEN 1 END) as low_count,
                COUNT(CASE WHEN (i.quantity_present - i.quantity_reserved) >= 50 
                           AND (i.quantity_present - i.quantity_reserved) < 100 THEN 1 END) as normal_count,
                COUNT(CASE WHEN (i.quantity_present - i.quantity_reserved) >= 100 THEN 1 END) as excess_count
            FROM dim_products dp
            INNER JOIN inventory i ON dp.id = i.product_id
            WHERE i.quantity_present > 0 OR i.quantity_reserved > 0
        ";
        
        $stmt = $pdo->query($query);
        return $stmt->fetch();
    }, 300); // 5 minute cache
    
    echo json_encode([
        'success' => true,
        'data' => $result,
        'meta' => [
            'action' => 'summary',
            'cached' => true
        ],
        'timestamp' => date('Y-m-d H:i:s')
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
}

/**
 * Get detailed stock list with filters (optimized queries)
 */
function handleListAction($pdo, $cache) {
    // Get query parameters
    $limit = isset($_GET['limit']) ? min((int)$_GET['limit'], 1000) : 100;
    $offset = isset($_GET['offset']) ? max((int)$_GET['offset'], 0) : 0;
    $stockStatus = $_GET['stock_status'] ?? null;
    $warehouse = $_GET['warehouse'] ?? null;
    $search = $_GET['search'] ?? null;
    $source = $_GET['source'] ?? null;
    
    // Generate cache key based on parameters
    $cacheKey = QueryCache::generateKey('detailed_stock_list', [
        'limit' => $limit,
        'offset' => $offset,
        'stock_status' => $stockStatus,
        'warehouse' => $warehouse,
        'search' => $search,
        'source' => $source
    ]);
    
    $result = $cache->remember($cacheKey, function() use ($pdo, $limit, $offset, $stockStatus, $warehouse, $search, $source) {
        // Build optimized query with proper index usage
        $whereConditions = ["1=1"];
        $params = [];
        
        if ($warehouse) {
            $whereConditions[] = "i.warehouse_name = :warehouse";
            $params['warehouse'] = $warehouse;
        }
        
        if ($source) {
            $whereConditions[] = "i.source = :source";
            $params['source'] = $source;
        }
        
        if ($search) {
            // Use trigram index for fuzzy search
            $whereConditions[] = "(dp.product_name ILIKE :search OR dp.sku_ozon ILIKE :search OR dp.sku_wb ILIKE :search)";
            $params['search'] = "%$search%";
        }
        
        // Add stock status filter using partial indexes
        if ($stockStatus) {
            switch ($stockStatus) {
                case 'out_of_stock':
                    $whereConditions[] = "(i.quantity_present - i.quantity_reserved) <= 0";
                    break;
                case 'critical':
                    $whereConditions[] = "(i.quantity_present - i.quantity_reserved) > 0 AND (i.quantity_present - i.quantity_reserved) < 20";
                    break;
                case 'low':
                    $whereConditions[] = "(i.quantity_present - i.quantity_reserved) >= 20 AND (i.quantity_present - i.quantity_reserved) < 50";
                    break;
                case 'normal':
                    $whereConditions[] = "(i.quantity_present - i.quantity_reserved) >= 50 AND (i.quantity_present - i.quantity_reserved) < 100";
                    break;
                case 'excess':
                    $whereConditions[] = "(i.quantity_present - i.quantity_reserved) >= 100";
                    break;
            }
        }
        
        $whereClause = implode(' AND ', $whereConditions);
        
        // Optimized query using covering indexes
        $query = "
            SELECT 
                dp.id as product_id,
                dp.sku_ozon as offer_id,
                dp.product_name,
                dp.visibility,
                i.warehouse_name,
                i.stock_type,
                i.source,
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
            INNER JOIN inventory i ON dp.id = i.product_id
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
        
        // Get total count (optimized with same indexes)
        $countQuery = "
            SELECT COUNT(*) as total
            FROM dim_products dp
            INNER JOIN inventory i ON dp.id = i.product_id
            WHERE $whereClause
        ";
        
        $countStmt = $pdo->prepare($countQuery);
        foreach ($params as $key => $value) {
            $countStmt->bindValue(":$key", $value);
        }
        $countStmt->execute();
        $total = $countStmt->fetch()['total'];
        
        return [
            'data' => $data,
            'total' => $total
        ];
    }, 180); // 3 minute cache for list queries
    
    echo json_encode([
        'success' => true,
        'data' => $result['data'],
        'meta' => [
            'total_count' => (int)$result['total'],
            'filtered_count' => count($result['data']),
            'limit' => $limit,
            'offset' => $offset,
            'filters_applied' => [
                'warehouse' => $warehouse,
                'stock_status' => $stockStatus,
                'search' => $search,
                'source' => $source
            ],
            'cached' => true
        ],
        'timestamp' => date('Y-m-d H:i:s')
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
}

/**
 * Get cache statistics
 */
function handleCacheStatsAction($cache) {
    $stats = $cache->getStats();
    
    echo json_encode([
        'success' => true,
        'data' => $stats,
        'meta' => [
            'action' => 'cache_stats'
        ],
        'timestamp' => date('Y-m-d H:i:s')
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
}

/**
 * Clear cache
 */
function handleClearCacheAction($cache) {
    $cache->clear();
    
    echo json_encode([
        'success' => true,
        'message' => 'Cache cleared successfully',
        'meta' => [
            'action' => 'clear_cache'
        ],
        'timestamp' => date('Y-m-d H:i:s')
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
}

/**
 * Log query performance for monitoring
 */
function logQueryPerformance($pdo, $queryName, $executionTimeMs) {
    try {
        $sql = "INSERT INTO query_performance_log (query_name, execution_time_ms, executed_at) 
                VALUES (:query_name, :execution_time, NOW())";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            'query_name' => $queryName,
            'execution_time' => $executionTimeMs
        ]);
    } catch (Exception $e) {
        // Don't fail the request if logging fails
        error_log("Failed to log query performance: " . $e->getMessage());
    }
}
