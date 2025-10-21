<?php
/**
 * Health Check Endpoint
 * Проверяет состояние системы региональной аналитики
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-API-Key');

// Handle preflight requests
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

try {
    require_once '../config.php';
    require_once '../DatabaseConnectionPool.php';
    
    $health = [
        'status' => 'ok',
        'timestamp' => date('c'),
        'version' => ANALYTICS_API_VERSION ?? '1.0.0',
        'checks' => []
    ];
    
    // Database connectivity check
    try {
        $db = new DatabaseManager();
        $result = $db->fetchRow('SELECT 1 as test');
        
        if ($result && $result['test'] == 1) {
            $health['checks']['database'] = 'ok';
        } else {
            $health['checks']['database'] = 'error';
        }
        
        // Check connection pool stats
        $poolStats = $db->getPoolStats();
        $health['checks']['connection_pool'] = $poolStats;
        
    } catch (Exception $e) {
        $health['checks']['database'] = 'error';
        $health['checks']['database_error'] = $e->getMessage();
    }
    
    // Check critical tables
    try {
        $tables = ['ozon_regional_sales', 'regions', 'regional_analytics_cache'];
        foreach ($tables as $table) {
            $result = $db->fetchRow("SELECT COUNT(*) as count FROM {$table}");
            $health['checks']["table_{$table}"] = [
                'status' => 'ok',
                'record_count' => (int)$result['count']
            ];
        }
    } catch (Exception $e) {
        $health['checks']['tables'] = 'error';
        $health['checks']['tables_error'] = $e->getMessage();
    }
    
    // Check API services
    if (class_exists('SalesAnalyticsService')) {
        $health['checks']['analytics_service'] = 'ok';
    } else {
        $health['checks']['analytics_service'] = 'error';
    }
    
    if (class_exists('OzonAPIService')) {
        $health['checks']['ozon_api_service'] = 'ok';
    } else {
        $health['checks']['ozon_api_service'] = 'error';
    }
    
    // Check file system
    $health['checks']['filesystem'] = [
        'config_readable' => is_readable('../config.php'),
        'logs_writable' => is_writable('../../logs') || is_writable('/var/log'),
        'cache_writable' => is_writable('/tmp')
    ];
    
    // Overall status determination
    $hasErrors = false;
    foreach ($health['checks'] as $check) {
        if (is_array($check)) {
            if (isset($check['status']) && $check['status'] === 'error') {
                $hasErrors = true;
                break;
            }
        } elseif ($check === 'error') {
            $hasErrors = true;
            break;
        }
    }
    
    if ($hasErrors) {
        $health['status'] = 'error';
        http_response_code(500);
    }
    
} catch (Exception $e) {
    $health = [
        'status' => 'error',
        'timestamp' => date('c'),
        'error' => $e->getMessage(),
        'checks' => []
    ];
    http_response_code(500);
}

echo json_encode($health, JSON_PRETTY_PRINT);
?>