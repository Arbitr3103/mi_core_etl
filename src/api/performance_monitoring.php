<?php
/**
 * Performance Monitoring API - Ozon Stock Reports
 * 
 * Предоставляет REST API для мониторинга производительности ETL процессов,
 * получения метрик, уведомлений и рекомендаций по оптимизации.
 * 
 * Endpoints:
 *   GET /api/performance_monitoring.php - полный анализ производительности
 *   GET /api/performance_monitoring.php?type=statistics - статистика производительности
 *   GET /api/performance_monitoring.php?type=alerts - активные уведомления
 *   GET /api/performance_monitoring.php?type=recommendations - рекомендации по оптимизации
 *   GET /api/performance_monitoring.php?type=capacity - анализ планирования мощностей
 *   GET /api/performance_monitoring.php?type=trends - анализ трендов
 *   POST /api/performance_monitoring.php - управление уведомлениями и рекомендациями
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
    require_once SRC_DIR . '/classes/PerformanceMonitor.php';
    
    // Initialize database connection
    $config = include SRC_DIR . '/config/database.php';
    $dsn = "mysql:host={$config['host']};dbname={$config['database']};charset={$config['charset']}";
    $pdo = new PDO($dsn, $config['username'], $config['password'], $config['options']);
    
    // Initialize performance monitor
    $performanceMonitor = new PerformanceMonitor($pdo);
    
    // Get query parameters
    $type = $_GET['type'] ?? 'comprehensive';
    $days = isset($_GET['days']) ? (int)$_GET['days'] : 30;
    $limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 50;
    $etlId = $_GET['etl_id'] ?? null;
    
    // Validate parameters
    $allowedTypes = ['comprehensive', 'statistics', 'alerts', 'recommendations', 'capacity', 'trends'];
    
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
            $data = handleGetRequest($performanceMonitor, $type, $days, $limit, $etlId);
            break;
            
        case 'POST':
            $data = handlePostRequest($performanceMonitor, $pdo);
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
            'name' => 'Ozon Performance Monitoring API',
            'version' => '1.0',
            'timestamp' => date('Y-m-d H:i:s'),
            'response_time_ms' => $responseTime,
            'method' => $method,
            'type' => $type
        ],
        'data' => $data
    ];
    
    // Add filters info if applicable
    if ($days !== 30) {
        $response['api']['days_filter'] = $days;
    }
    if ($etlId) {
        $response['api']['etl_id_filter'] = $etlId;
    }
    
    echo json_encode($response, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    
} catch (Exception $e) {
    // Handle errors gracefully
    http_response_code(500);
    
    $errorResponse = [
        'api' => [
            'name' => 'Ozon Performance Monitoring API',
            'version' => '1.0',
            'timestamp' => date('Y-m-d H:i:s')
        ],
        'error' => [
            'message' => 'Failed to retrieve performance data',
            'details' => $e->getMessage(),
            'code' => $e->getCode()
        ]
    ];
    
    echo json_encode($errorResponse, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    
    // Log the error
    error_log("[Performance Monitoring API] ERROR: " . $e->getMessage());
}

/**
 * Обработка GET запросов
 */
function handleGetRequest($performanceMonitor, $type, $days, $limit, $etlId) {
    switch ($type) {
        case 'statistics':
            return getPerformanceStatistics($performanceMonitor, $days, $etlId);
            
        case 'alerts':
            return getPerformanceAlerts($performanceMonitor, $limit);
            
        case 'recommendations':
            return getOptimizationRecommendations($performanceMonitor, $limit);
            
        case 'capacity':
            return getCapacityAnalysis($performanceMonitor);
            
        case 'trends':
            return getPerformanceTrends($performanceMonitor, $days);
            
        default: // 'comprehensive'
            return $performanceMonitor->performComprehensivePerformanceAnalysis();
    }
}

/**
 * Обработка POST запросов
 */
function handlePostRequest($performanceMonitor, $pdo) {
    $input = json_decode(file_get_contents('php://input'), true);
    
    if (!$input) {
        http_response_code(400);
        return ['error' => 'Invalid JSON input'];
    }
    
    $action = $input['action'] ?? '';
    
    switch ($action) {
        case 'resolve_alert':
            return resolvePerformanceAlert($pdo, $input);
            
        case 'update_recommendation':
            return updateOptimizationRecommendation($pdo, $input);
            
        case 'trigger_analysis':
            return $performanceMonitor->performComprehensivePerformanceAnalysis();
            
        default:
            http_response_code(400);
            return ['error' => 'Invalid action parameter'];
    }
}

/**
 * Получение статистики производительности
 */
function getPerformanceStatistics($performanceMonitor, $days, $etlId) {
    $statistics = $performanceMonitor->getPerformanceStatistics($days);
    
    // Если указан конкретный ETL ID, фильтруем данные
    if ($etlId) {
        $statistics['etl_specific'] = getETLSpecificStatistics($etlId, $days);
    }
    
    return $statistics;
}

/**
 * Получение статистики для конкретного ETL процесса
 */
function getETLSpecificStatistics($etlId, $days) {
    global $pdo;
    
    try {
        $sql = "SELECT 
                    execution_phase,
                    COUNT(*) as executions_count,
                    AVG(duration_seconds) as avg_duration,
                    MAX(duration_seconds) as max_duration,
                    MIN(duration_seconds) as min_duration,
                    AVG(memory_usage_mb) as avg_memory_usage,
                    MAX(memory_usage_mb) as max_memory_usage,
                    AVG(records_per_second) as avg_throughput,
                    STDDEV(duration_seconds) as duration_stddev
                FROM performance_metrics
                WHERE etl_id LIKE :etl_pattern
                AND metric_timestamp >= DATE_SUB(NOW(), INTERVAL :days DAY)
                GROUP BY execution_phase
                ORDER BY execution_phase";
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            'etl_pattern' => "%$etlId%",
            'days' => $days
        ]);
        
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
        
    } catch (Exception $e) {
        return ['error' => $e->getMessage()];
    }
}

/**
 * Получение уведомлений о производительности
 */
function getPerformanceAlerts($performanceMonitor, $limit) {
    $alerts = $performanceMonitor->getActivePerformanceAlerts($limit);
    
    // Добавляем сводную статистику по уведомлениям
    $alertsSummary = getAlertsSummary();
    
    return [
        'active_alerts' => $alerts,
        'summary' => $alertsSummary
    ];
}

/**
 * Получение сводки по уведомлениям
 */
function getAlertsSummary() {
    global $pdo;
    
    try {
        $sql = "SELECT 
                    alert_type,
                    severity,
                    COUNT(*) as count,
                    AVG(DATEDIFF(NOW(), created_at)) as avg_days_active
                FROM performance_alerts
                WHERE is_resolved = FALSE
                GROUP BY alert_type, severity
                ORDER BY severity DESC, alert_type";
        
        $stmt = $pdo->query($sql);
        $summary = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Общая статистика
        $totalAlerts = array_sum(array_column($summary, 'count'));
        $criticalAlerts = array_sum(array_column(
            array_filter($summary, function($item) { return $item['severity'] === 'critical'; }),
            'count'
        ));
        
        return [
            'total_active_alerts' => $totalAlerts,
            'critical_alerts' => $criticalAlerts,
            'warning_alerts' => $totalAlerts - $criticalAlerts,
            'by_type_and_severity' => $summary
        ];
        
    } catch (Exception $e) {
        return ['error' => $e->getMessage()];
    }
}

/**
 * Получение рекомендаций по оптимизации
 */
function getOptimizationRecommendations($performanceMonitor, $limit) {
    $recommendations = $performanceMonitor->getOptimizationRecommendations('pending', $limit);
    
    // Добавляем статистику по рекомендациям
    $recommendationsSummary = getRecommendationsSummary();
    
    return [
        'pending_recommendations' => $recommendations,
        'summary' => $recommendationsSummary
    ];
}

/**
 * Получение сводки по рекомендациям
 */
function getRecommendationsSummary() {
    global $pdo;
    
    try {
        $sql = "SELECT 
                    recommendation_type,
                    priority,
                    status,
                    COUNT(*) as count
                FROM optimization_recommendations
                GROUP BY recommendation_type, priority, status
                ORDER BY priority DESC, recommendation_type, status";
        
        $stmt = $pdo->query($sql);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
        
    } catch (Exception $e) {
        return ['error' => $e->getMessage()];
    }
}

/**
 * Получение анализа планирования мощностей
 */
function getCapacityAnalysis($performanceMonitor) {
    // Используем приватный метод через рефлексию или создаем публичный метод
    $analysis = $performanceMonitor->performComprehensivePerformanceAnalysis();
    return $analysis['capacity_analysis'] ?? ['error' => 'Capacity analysis not available'];
}

/**
 * Получение анализа трендов производительности
 */
function getPerformanceTrends($performanceMonitor, $days) {
    global $pdo;
    
    try {
        // Тренды по дням
        $sql = "SELECT 
                    DATE(metric_timestamp) as date,
                    AVG(duration_seconds) as avg_duration,
                    AVG(memory_usage_mb) as avg_memory,
                    AVG(records_per_second) as avg_throughput,
                    COUNT(*) as executions_count
                FROM performance_metrics
                WHERE metric_timestamp >= DATE_SUB(NOW(), INTERVAL :days DAY)
                GROUP BY DATE(metric_timestamp)
                ORDER BY date";
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute(['days' => $days]);
        $dailyTrends = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Тренды по фазам выполнения
        $sql = "SELECT 
                    execution_phase,
                    DATE(metric_timestamp) as date,
                    AVG(duration_seconds) as avg_duration,
                    AVG(memory_usage_mb) as avg_memory
                FROM performance_metrics
                WHERE metric_timestamp >= DATE_SUB(NOW(), INTERVAL :days DAY)
                GROUP BY execution_phase, DATE(metric_timestamp)
                ORDER BY date, execution_phase";
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute(['days' => $days]);
        $phaseTrends = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        return [
            'daily_trends' => $dailyTrends,
            'phase_trends' => $phaseTrends,
            'trend_analysis' => analyzeTrends($dailyTrends)
        ];
        
    } catch (Exception $e) {
        return ['error' => $e->getMessage()];
    }
}

/**
 * Анализ трендов
 */
function analyzeTrends($dailyTrends) {
    if (count($dailyTrends) < 7) {
        return ['status' => 'insufficient_data'];
    }
    
    $recent = array_slice($dailyTrends, -7); // Последние 7 дней
    $previous = array_slice($dailyTrends, -14, 7); // Предыдущие 7 дней
    
    $recentAvgDuration = array_sum(array_column($recent, 'avg_duration')) / count($recent);
    $previousAvgDuration = array_sum(array_column($previous, 'avg_duration')) / count($previous);
    
    $recentAvgMemory = array_sum(array_column($recent, 'avg_memory')) / count($recent);
    $previousAvgMemory = array_sum(array_column($previous, 'avg_memory')) / count($previous);
    
    $durationChange = (($recentAvgDuration - $previousAvgDuration) / $previousAvgDuration) * 100;
    $memoryChange = (($recentAvgMemory - $previousAvgMemory) / $previousAvgMemory) * 100;
    
    return [
        'duration_trend' => $durationChange > 5 ? 'increasing' : ($durationChange < -5 ? 'decreasing' : 'stable'),
        'memory_trend' => $memoryChange > 5 ? 'increasing' : ($memoryChange < -5 ? 'decreasing' : 'stable'),
        'duration_change_percent' => round($durationChange, 2),
        'memory_change_percent' => round($memoryChange, 2),
        'recent_avg_duration' => round($recentAvgDuration, 2),
        'previous_avg_duration' => round($previousAvgDuration, 2),
        'recent_avg_memory' => round($recentAvgMemory, 2),
        'previous_avg_memory' => round($previousAvgMemory, 2)
    ];
}

/**
 * Разрешение уведомления о производительности
 */
function resolvePerformanceAlert($pdo, $input) {
    if (!isset($input['alert_id'])) {
        http_response_code(400);
        return ['error' => 'Missing alert_id parameter'];
    }
    
    try {
        $sql = "UPDATE performance_alerts 
                SET is_resolved = TRUE, resolved_at = NOW()
                WHERE id = :alert_id";
        
        $stmt = $pdo->prepare($sql);
        $result = $stmt->execute(['alert_id' => (int)$input['alert_id']]);
        
        return [
            'success' => $result,
            'message' => $result ? 'Performance alert resolved successfully' : 'Failed to resolve alert'
        ];
        
    } catch (Exception $e) {
        return ['error' => $e->getMessage()];
    }
}

/**
 * Обновление рекомендации по оптимизации
 */
function updateOptimizationRecommendation($pdo, $input) {
    if (!isset($input['recommendation_id']) || !isset($input['status'])) {
        http_response_code(400);
        return ['error' => 'Missing recommendation_id or status parameter'];
    }
    
    $allowedStatuses = ['pending', 'in_progress', 'completed', 'dismissed'];
    if (!in_array($input['status'], $allowedStatuses)) {
        http_response_code(400);
        return ['error' => 'Invalid status. Allowed: ' . implode(', ', $allowedStatuses)];
    }
    
    try {
        $sql = "UPDATE optimization_recommendations 
                SET status = :status, updated_at = NOW()
                WHERE id = :recommendation_id";
        
        $stmt = $pdo->prepare($sql);
        $result = $stmt->execute([
            'recommendation_id' => (int)$input['recommendation_id'],
            'status' => $input['status']
        ]);
        
        return [
            'success' => $result,
            'message' => $result ? 'Recommendation updated successfully' : 'Failed to update recommendation'
        ];
        
    } catch (Exception $e) {
        return ['error' => $e->getMessage()];
    }
}