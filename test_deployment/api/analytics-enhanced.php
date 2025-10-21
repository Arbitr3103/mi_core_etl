<?php
/**
 * Enhanced Analytics API with MDM Integration
 * 
 * Uses product_cross_reference table for reliable product name resolution
 * with fallback mechanisms for data quality.
 * 
 * Requirements: 1.1, 1.2, 1.3
 */

// Error handling configuration
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

header("Content-Type: application/json; charset=utf-8");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");

// Handle OPTIONS request
if (isset($_SERVER['REQUEST_METHOD']) && $_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

// Load configuration
$config_loaded = false;
$config_paths = [
    __DIR__ . "/../config.php",
    __DIR__ . "/../../config.php",
    dirname(__DIR__) . "/config.php"
];

foreach ($config_paths as $config_path) {
    if (file_exists($config_path)) {
        require_once $config_path;
        $config_loaded = true;
        break;
    }
}

if (!$config_loaded) {
    // Fallback configuration
    define('DB_HOST', 'localhost');
    define('DB_USER', 'root');
    define('DB_PASSWORD', '');
    define('DB_NAME', 'mi_core');
}

// Include MDM services
require_once __DIR__ . '/../src/FallbackDataProvider.php';

try {
    $pdo = new PDO(
        "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4",
        DB_USER,
        DB_PASSWORD,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
    
    $action = $_GET['action'] ?? 'overview';
    
    switch ($action) {
        case 'overview':
            handleOverviewRequest($pdo);
            break;
            
        case 'products':
            handleProductsRequest($pdo);
            break;
            
        case 'sync-status':
            handleSyncStatusRequest($pdo);
            break;
            
        case 'quality-metrics':
            handleQualityMetricsRequest($pdo);
            break;
            
        default:
            handleOverviewRequest($pdo);
            break;
    }
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        "error" => $e->getMessage(),
        "total_products" => 0,
        "data_quality_score" => 0
    ], JSON_UNESCAPED_UNICODE);
}

/**
 * Handle overview analytics request
 */
function handleOverviewRequest($pdo) {
    $analytics = [
        "total_products" => 0,
        "products_with_names" => 0,
        "products_with_real_names" => 0,
        "products_with_brands" => 0,
        "products_with_categories" => 0,
        "sync_status" => [],
        "data_quality_score" => 0,
        "top_brands" => [],
        "top_categories" => []
    ];
    
    // Total products using cross-reference table
    $analytics["total_products"] = $pdo->query("
        SELECT COUNT(DISTINCT pcr.inventory_product_id) 
        FROM product_cross_reference pcr
    ")->fetchColumn();
    
    // Products with any names (including placeholders)
    $analytics["products_with_names"] = $pdo->query("
        SELECT COUNT(DISTINCT pcr.inventory_product_id)
        FROM product_cross_reference pcr
        WHERE pcr.cached_name IS NOT NULL AND pcr.cached_name != ''
    ")->fetchColumn();
    
    // Products with real names (not placeholders)
    $analytics["products_with_real_names"] = $pdo->query("
        SELECT COUNT(DISTINCT pcr.inventory_product_id)
        FROM product_cross_reference pcr
        WHERE pcr.cached_name IS NOT NULL 
          AND pcr.cached_name != ''
          AND pcr.cached_name NOT LIKE 'Товар%ID%'
          AND pcr.cached_name NOT LIKE 'Product ID%'
          AND pcr.sync_status = 'synced'
    ")->fetchColumn();
    
    // Products with brands
    $analytics["products_with_brands"] = $pdo->query("
        SELECT COUNT(DISTINCT pcr.inventory_product_id)
        FROM product_cross_reference pcr
        WHERE pcr.cached_brand IS NOT NULL AND pcr.cached_brand != ''
    ")->fetchColumn();
    
    // Sync status breakdown
    $syncStatusQuery = $pdo->query("
        SELECT 
            sync_status,
            COUNT(*) as count
        FROM product_cross_reference
        GROUP BY sync_status
    ");
    
    while ($row = $syncStatusQuery->fetch()) {
        $analytics["sync_status"][$row['sync_status']] = (int)$row['count'];
    }
    
    // Calculate data quality score
    if ($analytics["total_products"] > 0) {
        $qualityScore = (
            ($analytics["products_with_real_names"] / $analytics["total_products"]) * 0.6 +
            ($analytics["products_with_brands"] / $analytics["total_products"]) * 0.4
        ) * 100;
        $analytics["data_quality_score"] = round($qualityScore, 1);
    }
    
    // Top brands from cross-reference cache
    $topBrands = $pdo->query("
        SELECT 
            pcr.cached_brand as brand, 
            COUNT(*) as count 
        FROM product_cross_reference pcr
        WHERE pcr.cached_brand IS NOT NULL AND pcr.cached_brand != ''
        GROUP BY pcr.cached_brand 
        ORDER BY count DESC 
        LIMIT 5
    ")->fetchAll(PDO::FETCH_ASSOC);
    $analytics["top_brands"] = $topBrands;
    
    echo json_encode($analytics, JSON_UNESCAPED_UNICODE);
}

/**
 * Handle products list request with enhanced name resolution
 */
function handleProductsRequest($pdo) {
    $limit = min((int)($_GET['limit'] ?? 50), 1000);
    $offset = (int)($_GET['offset'] ?? 0);
    $search = $_GET['search'] ?? '';
    $syncStatus = $_GET['sync_status'] ?? null;
    
    $whereConditions = ['1=1'];
    $params = [];
    
    if ($search) {
        $whereConditions[] = '(pcr.cached_name LIKE ? OR pcr.inventory_product_id LIKE ?)';
        $params[] = "%$search%";
        $params[] = "%$search%";
    }
    
    if ($syncStatus) {
        $whereConditions[] = 'pcr.sync_status = ?';
        $params[] = $syncStatus;
    }
    
    $whereClause = implode(' AND ', $whereConditions);
    
    // Enhanced query using cross-reference table with fallback
    $sql = "
        SELECT 
            pcr.inventory_product_id as product_id,
            COALESCE(
                NULLIF(pcr.cached_name, ''),
                dp.name,
                dp.product_name,
                CONCAT('Товар ID ', pcr.inventory_product_id)
            ) as display_name,
            pcr.cached_brand as brand,
            pcr.sync_status,
            pcr.last_api_sync,
            CASE 
                WHEN pcr.cached_name IS NOT NULL 
                     AND pcr.cached_name != '' 
                     AND pcr.cached_name NOT LIKE 'Товар%ID%' 
                     AND pcr.sync_status = 'synced' 
                THEN 'real'
                WHEN pcr.cached_name IS NOT NULL 
                     AND pcr.cached_name != '' 
                THEN 'placeholder'
                ELSE 'missing'
            END as name_quality,
            i.quantity_present as stock_quantity,
            i.quantity_reserved as reserved_quantity
        FROM product_cross_reference pcr
        LEFT JOIN dim_products dp ON pcr.sku_ozon = dp.sku_ozon
        LEFT JOIN inventory_data i ON CAST(i.product_id AS CHAR) = pcr.inventory_product_id
        WHERE $whereClause
        GROUP BY pcr.inventory_product_id
        ORDER BY pcr.last_api_sync DESC
        LIMIT ? OFFSET ?
    ";
    
    $params[] = $limit;
    $params[] = $offset;
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $products = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Get total count
    $countSql = "
        SELECT COUNT(DISTINCT pcr.inventory_product_id) as total
        FROM product_cross_reference pcr
        LEFT JOIN dim_products dp ON pcr.sku_ozon = dp.sku_ozon
        WHERE $whereClause
    ";
    
    $countStmt = $pdo->prepare($countSql);
    $countStmt->execute(array_slice($params, 0, -2));
    $totalCount = $countStmt->fetch()['total'];
    
    echo json_encode([
        'success' => true,
        'data' => [
            'products' => $products,
            'pagination' => [
                'total' => (int)$totalCount,
                'limit' => $limit,
                'offset' => $offset,
                'has_more' => ($offset + $limit) < $totalCount
            ]
        ]
    ], JSON_UNESCAPED_UNICODE);
}

/**
 * Handle sync status request
 */
function handleSyncStatusRequest($pdo) {
    $fallbackProvider = new FallbackDataProvider($pdo);
    
    // Get cache statistics
    $cacheStats = $fallbackProvider->getCacheStatistics();
    
    // Get sync statistics
    $syncStats = $pdo->query("
        SELECT 
            COUNT(*) as total,
            SUM(CASE WHEN sync_status = 'synced' THEN 1 ELSE 0 END) as synced,
            SUM(CASE WHEN sync_status = 'pending' THEN 1 ELSE 0 END) as pending,
            SUM(CASE WHEN sync_status = 'failed' THEN 1 ELSE 0 END) as failed,
            MAX(last_api_sync) as last_sync_time,
            MIN(last_api_sync) as oldest_sync_time
        FROM product_cross_reference
    ")->fetch();
    
    // Calculate sync percentage
    $syncPercentage = $syncStats['total'] > 0 
        ? round(($syncStats['synced'] / $syncStats['total']) * 100, 2) 
        : 0;
    
    echo json_encode([
        'success' => true,
        'data' => [
            'sync_statistics' => [
                'total_products' => (int)$syncStats['total'],
                'synced' => (int)$syncStats['synced'],
                'pending' => (int)$syncStats['pending'],
                'failed' => (int)$syncStats['failed'],
                'sync_percentage' => $syncPercentage,
                'last_sync_time' => $syncStats['last_sync_time'],
                'oldest_sync_time' => $syncStats['oldest_sync_time']
            ],
            'cache_statistics' => $cacheStats
        ]
    ], JSON_UNESCAPED_UNICODE);
}

/**
 * Handle quality metrics request
 */
function handleQualityMetricsRequest($pdo) {
    // Data quality breakdown
    $qualityBreakdown = $pdo->query("
        SELECT 
            CASE 
                WHEN cached_name IS NOT NULL 
                     AND cached_name != '' 
                     AND cached_name NOT LIKE 'Товар%ID%' 
                     AND sync_status = 'synced' 
                THEN 'high_quality'
                WHEN cached_name IS NOT NULL 
                     AND cached_name != '' 
                THEN 'medium_quality'
                ELSE 'low_quality'
            END as quality_level,
            COUNT(*) as count
        FROM product_cross_reference
        GROUP BY quality_level
    ")->fetchAll(PDO::FETCH_ASSOC);
    
    // Products needing attention
    $needsAttention = $pdo->query("
        SELECT 
            inventory_product_id as product_id,
            cached_name,
            sync_status,
            last_api_sync,
            CASE 
                WHEN sync_status = 'failed' THEN 'Sync failed'
                WHEN cached_name IS NULL OR cached_name = '' THEN 'Missing name'
                WHEN cached_name LIKE 'Товар%ID%' THEN 'Placeholder name'
                WHEN last_api_sync < DATE_SUB(NOW(), INTERVAL 30 DAY) THEN 'Stale data'
                ELSE 'Unknown issue'
            END as issue_type
        FROM product_cross_reference
        WHERE sync_status = 'failed'
           OR cached_name IS NULL
           OR cached_name = ''
           OR cached_name LIKE 'Товар%ID%'
           OR last_api_sync < DATE_SUB(NOW(), INTERVAL 30 DAY)
        ORDER BY 
            CASE sync_status
                WHEN 'failed' THEN 1
                WHEN 'pending' THEN 2
                ELSE 3
            END,
            last_api_sync ASC
        LIMIT 100
    ")->fetchAll(PDO::FETCH_ASSOC);
    
    echo json_encode([
        'success' => true,
        'data' => [
            'quality_breakdown' => $qualityBreakdown,
            'products_needing_attention' => $needsAttention,
            'total_issues' => count($needsAttention)
        ]
    ], JSON_UNESCAPED_UNICODE);
}
?>
