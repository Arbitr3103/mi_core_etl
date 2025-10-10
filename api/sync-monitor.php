<?php
/**
 * Sync Monitor API
 * 
 * Provides real-time synchronization metrics and alerts
 * for monitoring dashboards.
 * 
 * Requirements: 3.3, 8.3
 */

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit();
}

// Load configuration
require_once __DIR__ . '/../config.php';

try {
    $pdo = new PDO(
        "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4",
        DB_USER,
        DB_PASSWORD,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Database connection failed'
    ]);
    exit();
}

$action = $_GET['action'] ?? 'status';

try {
    switch ($action) {
        case 'status':
            handleStatusRequest($pdo);
            break;
            
        case 'metrics':
            handleMetricsRequest($pdo);
            break;
            
        case 'alerts':
            handleAlertsRequest($pdo);
            break;
            
        case 'history':
            handleHistoryRequest($pdo);
            break;
            
        default:
            handleStatusRequest($pdo);
            break;
    }
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}

/**
 * Handle status request - current sync status
 */
function handleStatusRequest($pdo) {
    $syncStats = $pdo->query("
        SELECT 
            COUNT(*) as total,
            SUM(CASE WHEN sync_status = 'synced' THEN 1 ELSE 0 END) as synced,
            SUM(CASE WHEN sync_status = 'pending' THEN 1 ELSE 0 END) as pending,
            SUM(CASE WHEN sync_status = 'failed' THEN 1 ELSE 0 END) as failed,
            MAX(last_api_sync) as last_sync_time,
            MIN(CASE WHEN sync_status = 'synced' THEN last_api_sync END) as oldest_sync_time
        FROM product_cross_reference
    ")->fetch();
    
    $syncPercentage = $syncStats['total'] > 0 
        ? round(($syncStats['synced'] / $syncStats['total']) * 100, 2) 
        : 0;
    
    // Determine health status
    $healthStatus = 'healthy';
    if ($syncStats['failed'] > 10 || $syncPercentage < 70) {
        $healthStatus = 'critical';
    } elseif ($syncStats['failed'] > 0 || $syncPercentage < 90) {
        $healthStatus = 'warning';
    }
    
    echo json_encode([
        'success' => true,
        'data' => [
            'health_status' => $healthStatus,
            'sync_percentage' => $syncPercentage,
            'total_products' => (int)$syncStats['total'],
            'synced' => (int)$syncStats['synced'],
            'pending' => (int)$syncStats['pending'],
            'failed' => (int)$syncStats['failed'],
            'last_sync_time' => $syncStats['last_sync_time'],
            'oldest_sync_time' => $syncStats['oldest_sync_time'],
            'timestamp' => date('Y-m-d H:i:s')
        ]
    ]);
}

/**
 * Handle metrics request - detailed metrics
 */
function handleMetricsRequest($pdo) {
    // Quality breakdown
    $qualityMetrics = $pdo->query("
        SELECT 
            CASE 
                WHEN cached_name IS NOT NULL 
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
    
    // Sync age distribution
    $ageDistribution = $pdo->query("
        SELECT 
            CASE 
                WHEN last_api_sync IS NULL THEN 'never_synced'
                WHEN last_api_sync >= DATE_SUB(NOW(), INTERVAL 1 DAY) THEN 'last_24h'
                WHEN last_api_sync >= DATE_SUB(NOW(), INTERVAL 7 DAY) THEN 'last_week'
                WHEN last_api_sync >= DATE_SUB(NOW(), INTERVAL 30 DAY) THEN 'last_month'
                ELSE 'older'
            END as age_group,
            COUNT(*) as count
        FROM product_cross_reference
        GROUP BY age_group
    ")->fetchAll(PDO::FETCH_ASSOC);
    
    // Cache statistics
    require_once __DIR__ . '/../src/FallbackDataProvider.php';
    $fallbackProvider = new FallbackDataProvider($pdo);
    $cacheStats = $fallbackProvider->getCacheStatistics();
    
    echo json_encode([
        'success' => true,
        'data' => [
            'quality_metrics' => $qualityMetrics,
            'age_distribution' => $ageDistribution,
            'cache_statistics' => $cacheStats,
            'timestamp' => date('Y-m-d H:i:s')
        ]
    ]);
}

/**
 * Handle alerts request - products needing attention
 */
function handleAlertsRequest($pdo) {
    // Failed products
    $failedProducts = $pdo->query("
        SELECT 
            inventory_product_id as product_id,
            cached_name,
            last_api_sync,
            'sync_failed' as alert_type,
            'critical' as severity
        FROM product_cross_reference
        WHERE sync_status = 'failed'
        ORDER BY last_api_sync DESC
        LIMIT 20
    ")->fetchAll(PDO::FETCH_ASSOC);
    
    // Stale data (not synced in 30 days)
    $staleProducts = $pdo->query("
        SELECT 
            inventory_product_id as product_id,
            cached_name,
            last_api_sync,
            'stale_data' as alert_type,
            'warning' as severity
        FROM product_cross_reference
        WHERE last_api_sync < DATE_SUB(NOW(), INTERVAL 30 DAY)
          AND sync_status = 'synced'
        ORDER BY last_api_sync ASC
        LIMIT 20
    ")->fetchAll(PDO::FETCH_ASSOC);
    
    // Missing names
    $missingNames = $pdo->query("
        SELECT 
            inventory_product_id as product_id,
            cached_name,
            last_api_sync,
            'missing_name' as alert_type,
            'warning' as severity
        FROM product_cross_reference
        WHERE cached_name IS NULL 
           OR cached_name = ''
           OR cached_name LIKE 'Товар%ID%'
        ORDER BY last_api_sync DESC
        LIMIT 20
    ")->fetchAll(PDO::FETCH_ASSOC);
    
    $allAlerts = array_merge($failedProducts, $staleProducts, $missingNames);
    
    // Sort by severity
    usort($allAlerts, function($a, $b) {
        $severityOrder = ['critical' => 0, 'warning' => 1, 'info' => 2];
        return $severityOrder[$a['severity']] - $severityOrder[$b['severity']];
    });
    
    echo json_encode([
        'success' => true,
        'data' => [
            'alerts' => $allAlerts,
            'total_alerts' => count($allAlerts),
            'critical_count' => count($failedProducts),
            'warning_count' => count($staleProducts) + count($missingNames),
            'timestamp' => date('Y-m-d H:i:s')
        ]
    ]);
}

/**
 * Handle history request - sync history over time
 */
function handleHistoryRequest($pdo) {
    $days = min((int)($_GET['days'] ?? 7), 30);
    
    // Get sync history from sync_logs table if it exists
    $tableExists = $pdo->query("SHOW TABLES LIKE 'sync_logs'")->rowCount() > 0;
    
    if ($tableExists) {
        $history = $pdo->prepare("
            SELECT 
                DATE(created_at) as date,
                COUNT(*) as sync_count,
                SUM(CASE WHEN status = 'success' THEN 1 ELSE 0 END) as success_count,
                SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) as failed_count
            FROM sync_logs
            WHERE created_at >= DATE_SUB(NOW(), INTERVAL ? DAY)
            GROUP BY DATE(created_at)
            ORDER BY date DESC
        ");
        $history->execute([$days]);
        $historyData = $history->fetchAll(PDO::FETCH_ASSOC);
    } else {
        $historyData = [];
    }
    
    // Get current trend
    $currentStats = $pdo->query("
        SELECT 
            sync_status,
            COUNT(*) as count
        FROM product_cross_reference
        GROUP BY sync_status
    ")->fetchAll(PDO::FETCH_ASSOC);
    
    echo json_encode([
        'success' => true,
        'data' => [
            'history' => $historyData,
            'current_stats' => $currentStats,
            'period_days' => $days,
            'timestamp' => date('Y-m-d H:i:s')
        ]
    ]);
}
?>
