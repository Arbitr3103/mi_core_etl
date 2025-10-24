<?php
/**
 * Business Metrics API - Ozon Stock Reports
 * 
 * Предоставляет REST API для получения бизнес-метрик системы управления запасами.
 * Включает данные о критических остатках, свежести данных и трендах движения товаров.
 * 
 * Endpoints:
 *   GET /api/business_metrics.php - все бизнес-метрики
 *   GET /api/business_metrics.php?type=stock_levels - уровни запасов
 *   GET /api/business_metrics.php?type=data_freshness - свежесть данных
 *   GET /api/business_metrics.php?type=completeness - полнота данных
 *   GET /api/business_metrics.php?type=trends - тренды движения
 *   GET /api/business_metrics.php?type=alerts - активные уведомления
 *   GET /api/business_metrics.php?warehouse=Хоругвино - метрики по складу
 * 
 * @version 1.0
 * @author Manhattan System
 */

// Set headers for API response
header('Content-Type: application/json');
header('Cache-Control: no-cache, must-revalidate');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

// Handle preflight requests
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// Define paths
define('ROOT_DIR', dirname(dirname(__DIR__)));
define('SRC_DIR', ROOT_DIR . '/src');

try {
    // Include required files
    require_once SRC_DIR . '/config/database.php';
    require_once SRC_DIR . '/classes/BusinessMetricsMonitor.php';
    
    // Initialize database connection
    $config = include SRC_DIR . '/config/database.php';
    $dsn = "mysql:host={$config['host']};dbname={$config['database']};charset={$config['charset']}";
    $pdo = new PDO($dsn, $config['username'], $config['password'], $config['options']);
    
    // Initialize business metrics monitor
    $metricsMonitor = new BusinessMetricsMonitor($pdo);
    
    // Get query parameters
    $type = $_GET['type'] ?? 'all';
    $warehouse = $_GET['warehouse'] ?? null;
    $limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 100;
    $days = isset($_GET['days']) ? (int)$_GET['days'] : 30;
    
    // Validate parameters
    $allowedTypes = ['all', 'stock_levels', 'data_freshness', 'completeness', 'trends', 'alerts'];
    
    if (!in_array($type, $allowedTypes)) {
        http_response_code(400);
        echo json_encode([
            'error' => 'Invalid type parameter',
            'allowed_types' => $allowedTypes
        ]);
        exit;
    }
    
    // Handle different request methods
    $method = $_SERVER['REQUEST_METHOD'];
    $startTime = microtime(true);
    
    switch ($method) {
        case 'GET':
            $data = handleGetRequest($metricsMonitor, $type, $warehouse, $limit, $days);
            break;
            
        case 'POST':
            $data = handlePostRequest($metricsMonitor);
            break;
            
        default:
            http_response_code(405);
            echo json_encode(['error' => 'Method not allowed']);
            exit;
    }
    
    $responseTime = round((microtime(true) - $startTime) * 1000, 2);
    
    // Format response
    $response = [
        'api' => [
            'name' => 'Ozon Business Metrics API',
            'version' => '1.0',
            'timestamp' => date('Y-m-d H:i:s'),
            'response_time_ms' => $responseTime,
            'method' => $method,
            'type' => $type
        ],
        'data' => $data
    ];
    
    // Add filters info if applicable
    if ($warehouse) {
        $response['api']['warehouse_filter'] = $warehouse;
    }
    
    echo json_encode($response, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    
} catch (Exception $e) {
    // Handle errors gracefully
    http_response_code(500);
    
    $errorResponse = [
        'api' => [
            'name' => 'Ozon Business Metrics API',
            'version' => '1.0',
            'timestamp' => date('Y-m-d H:i:s')
        ],
        'error' => [
            'message' => 'Failed to retrieve business metrics',
            'details' => $e->getMessage(),
            'code' => $e->getCode()
        ]
    ];
    
    echo json_encode($errorResponse, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    
    // Log the error
    error_log("[Business Metrics API] ERROR: " . $e->getMessage());
}

/**
 * Обработка GET запросов
 */
function handleGetRequest($metricsMonitor, $type, $warehouse, $limit, $days) {
    switch ($type) {
        case 'stock_levels':
            return getStockLevelsMetrics($metricsMonitor, $warehouse);
            
        case 'data_freshness':
            return getDataFreshnessMetrics($metricsMonitor, $warehouse);
            
        case 'completeness':
            return getCompletenessMetrics($metricsMonitor, $warehouse);
            
        case 'trends':
            return getTrendsMetrics($metricsMonitor, $warehouse, $days);
            
        case 'alerts':
            return getActiveAlerts($metricsMonitor, $limit);
            
        default: // 'all'
            return $metricsMonitor->performBusinessMetricsAnalysis();
    }
}

/**
 * Обработка POST запросов
 */
function handlePostRequest($metricsMonitor) {
    $input = json_decode(file_get_contents('php://input'), true);
    
    if (!$input) {
        http_response_code(400);
        return ['error' => 'Invalid JSON input'];
    }
    
    $action = $input['action'] ?? '';
    
    switch ($action) {
        case 'resolve_alert':
            if (!isset($input['alert_id'])) {
                http_response_code(400);
                return ['error' => 'Missing alert_id parameter'];
            }
            
            $result = $metricsMonitor->resolveStockAlert(
                (int)$input['alert_id'], 
                $input['reason'] ?? ''
            );
            
            return [
                'success' => $result,
                'message' => $result ? 'Alert resolved successfully' : 'Failed to resolve alert'
            ];
            
        case 'trigger_analysis':
            return $metricsMonitor->performBusinessMetricsAnalysis();
            
        default:
            http_response_code(400);
            return ['error' => 'Invalid action parameter'];
    }
}

/**
 * Получение метрик уровней запасов
 */
function getStockLevelsMetrics($metricsMonitor, $warehouse) {
    global $pdo;
    
    try {
        $sql = "SELECT 
                    warehouse_name,
                    COUNT(*) as total_products,
                    COUNT(CASE WHEN current_stock = 0 THEN 1 END) as zero_stock,
                    COUNT(CASE WHEN current_stock > 0 AND current_stock <= 10 THEN 1 END) as critical_stock,
                    COUNT(CASE WHEN current_stock > 10 AND current_stock <= 50 THEN 1 END) as low_stock,
                    AVG(current_stock) as avg_stock,
                    SUM(current_stock) as total_stock,
                    MAX(last_report_update) as last_update
                FROM inventory 
                WHERE report_source = 'API_REPORTS'";
        
        $params = [];
        
        if ($warehouse) {
            $sql .= " AND warehouse_name = :warehouse";
            $params['warehouse'] = $warehouse;
        }
        
        $sql .= " GROUP BY warehouse_name ORDER BY warehouse_name";
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        
        $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Добавляем процентные показатели
        foreach ($results as &$result) {
            $total = $result['total_products'];
            $result['zero_stock_percent'] = $total > 0 ? round(($result['zero_stock'] / $total) * 100, 2) : 0;
            $result['critical_stock_percent'] = $total > 0 ? round(($result['critical_stock'] / $total) * 100, 2) : 0;
            $result['low_stock_percent'] = $total > 0 ? round(($result['low_stock'] / $total) * 100, 2) : 0;
            $result['avg_stock'] = round($result['avg_stock'], 2);
        }
        
        return [
            'warehouses' => $results,
            'summary' => [
                'total_warehouses' => count($results),
                'total_products' => array_sum(array_column($results, 'total_products')),
                'total_critical' => array_sum(array_column($results, 'critical_stock')),
                'total_zero_stock' => array_sum(array_column($results, 'zero_stock'))
            ]
        ];
        
    } catch (Exception $e) {
        return ['error' => $e->getMessage()];
    }
}

/**
 * Получение метрик свежести данных
 */
function getDataFreshnessMetrics($metricsMonitor, $warehouse) {
    global $pdo;
    
    try {
        $sql = "SELECT 
                    warehouse_name,
                    COUNT(*) as total_records,
                    COUNT(CASE WHEN last_report_update >= DATE_SUB(NOW(), INTERVAL 24 HOUR) THEN 1 END) as fresh_24h,
                    COUNT(CASE WHEN last_report_update >= DATE_SUB(NOW(), INTERVAL 6 HOUR) THEN 1 END) as fresh_6h,
                    COUNT(CASE WHEN last_report_update < DATE_SUB(NOW(), INTERVAL 24 HOUR) THEN 1 END) as stale_records,
                    MAX(last_report_update) as latest_update,
                    MIN(last_report_update) as oldest_update,
                    AVG(TIMESTAMPDIFF(HOUR, last_report_update, NOW())) as avg_age_hours
                FROM inventory 
                WHERE report_source = 'API_REPORTS'";
        
        $params = [];
        
        if ($warehouse) {
            $sql .= " AND warehouse_name = :warehouse";
            $params['warehouse'] = $warehouse;
        }
        
        $sql .= " GROUP BY warehouse_name ORDER BY warehouse_name";
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        
        $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Добавляем процентные показатели
        foreach ($results as &$result) {
            $total = $result['total_records'];
            $result['freshness_24h_percent'] = $total > 0 ? round(($result['fresh_24h'] / $total) * 100, 2) : 0;
            $result['freshness_6h_percent'] = $total > 0 ? round(($result['fresh_6h'] / $total) * 100, 2) : 0;
            $result['avg_age_hours'] = round($result['avg_age_hours'], 2);
        }
        
        return [
            'warehouses' => $results,
            'summary' => [
                'total_records' => array_sum(array_column($results, 'total_records')),
                'fresh_records_24h' => array_sum(array_column($results, 'fresh_24h')),
                'stale_records' => array_sum(array_column($results, 'stale_records')),
                'overall_freshness_percent' => count($results) > 0 ? 
                    round(array_sum(array_column($results, 'freshness_24h_percent')) / count($results), 2) : 0
            ]
        ];
        
    } catch (Exception $e) {
        return ['error' => $e->getMessage()];
    }
}

/**
 * Получение метрик полноты данных
 */
function getCompletenessMetrics($metricsMonitor, $warehouse) {
    global $pdo;
    
    try {
        $sql = "SELECT 
                    warehouse_name,
                    COUNT(*) as total_records,
                    COUNT(CASE WHEN sku IS NULL OR sku = '' THEN 1 END) as missing_sku,
                    COUNT(CASE WHEN current_stock IS NULL THEN 1 END) as missing_stock,
                    COUNT(CASE WHEN last_report_update IS NULL THEN 1 END) as missing_update_time,
                    COUNT(CASE WHEN product_id IS NULL THEN 1 END) as missing_product_id
                FROM inventory 
                WHERE report_source = 'API_REPORTS'";
        
        $params = [];
        
        if ($warehouse) {
            $sql .= " AND warehouse_name = :warehouse";
            $params['warehouse'] = $warehouse;
        }
        
        $sql .= " GROUP BY warehouse_name ORDER BY warehouse_name";
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        
        $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Вычисляем показатели полноты
        foreach ($results as &$result) {
            $total = $result['total_records'];
            $missingTotal = $result['missing_sku'] + $result['missing_stock'] + 
                           $result['missing_update_time'] + $result['missing_product_id'];
            
            $result['complete_records'] = $total - $missingTotal;
            $result['completeness_percent'] = $total > 0 ? 
                round((($total - $missingTotal) / $total) * 100, 2) : 0;
        }
        
        return [
            'warehouses' => $results,
            'summary' => [
                'total_records' => array_sum(array_column($results, 'total_records')),
                'complete_records' => array_sum(array_column($results, 'complete_records')),
                'overall_completeness_percent' => count($results) > 0 ? 
                    round(array_sum(array_column($results, 'completeness_percent')) / count($results), 2) : 0
            ]
        ];
        
    } catch (Exception $e) {
        return ['error' => $e->getMessage()];
    }
}

/**
 * Получение метрик трендов
 */
function getTrendsMetrics($metricsMonitor, $warehouse, $days) {
    return $metricsMonitor->getMetricsTrends('stock_levels', $days);
}

/**
 * Получение активных уведомлений
 */
function getActiveAlerts($metricsMonitor, $limit) {
    return [
        'critical_alerts' => $metricsMonitor->getActiveCriticalAlerts($limit),
        'alert_summary' => getAlertSummary()
    ];
}

/**
 * Получение сводки по уведомлениям
 */
function getAlertSummary() {
    global $pdo;
    
    try {
        $sql = "SELECT 
                    threshold_type,
                    alert_status,
                    COUNT(*) as count,
                    AVG(DATEDIFF(NOW(), first_detected)) as avg_days_active
                FROM critical_stock_alerts
                GROUP BY threshold_type, alert_status
                ORDER BY threshold_type, alert_status";
        
        $stmt = $pdo->query($sql);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
        
    } catch (Exception $e) {
        return ['error' => $e->getMessage()];
    }
}