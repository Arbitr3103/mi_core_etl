<?php
/**
 * Ozon ETL Monitoring Metrics API
 * 
 * Предоставляет REST API для получения метрик мониторинга ETL процессов.
 * Поддерживает различные форматы вывода и фильтрацию данных.
 * 
 * Endpoints:
 *   GET /api/monitoring_metrics.php - все метрики
 *   GET /api/monitoring_metrics.php?type=overview - обзор системы
 *   GET /api/monitoring_metrics.php?type=processes - текущие процессы
 *   GET /api/monitoring_metrics.php?type=alerts - уведомления
 *   GET /api/monitoring_metrics.php?type=performance - метрики производительности
 *   GET /api/monitoring_metrics.php?type=health - статус здоровья
 *   GET /api/monitoring_metrics.php?format=prometheus - экспорт в Prometheus
 * 
 * @version 1.0
 * @author Manhattan System
 */

// Set headers for API response
header('Content-Type: application/json');
header('Cache-Control: no-cache, must-revalidate');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

// Handle preflight requests
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// Only allow GET requests
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

// Define paths
define('ROOT_DIR', dirname(dirname(__DIR__)));
define('SRC_DIR', ROOT_DIR . '/src');

try {
    // Include required files
    require_once SRC_DIR . '/config/database.php';
    require_once SRC_DIR . '/classes/OzonETLMonitor.php';
    require_once SRC_DIR . '/monitoring/SystemMonitoringDashboard.php';
    
    // Initialize database connection
    $config = include SRC_DIR . '/config/database.php';
    $dsn = "mysql:host={$config['host']};dbname={$config['database']};charset={$config['charset']}";
    $pdo = new PDO($dsn, $config['username'], $config['password'], $config['options']);
    
    // Initialize components
    $monitor = new OzonETLMonitor($pdo);
    $dashboard = new SystemMonitoringDashboard($pdo, $monitor);
    
    // Get query parameters
    $type = $_GET['type'] ?? 'all';
    $format = $_GET['format'] ?? 'json';
    $limit = isset($_GET['limit']) ? (int)$_GET['limit'] : null;
    $since = $_GET['since'] ?? null;
    
    // Validate parameters
    $allowedTypes = ['all', 'overview', 'processes', 'alerts', 'performance', 'health', 'resources'];
    $allowedFormats = ['json', 'prometheus'];
    
    if (!in_array($type, $allowedTypes)) {
        http_response_code(400);
        echo json_encode([
            'error' => 'Invalid type parameter',
            'allowed_types' => $allowedTypes
        ]);
        exit;
    }
    
    if (!in_array($format, $allowedFormats)) {
        http_response_code(400);
        echo json_encode([
            'error' => 'Invalid format parameter',
            'allowed_formats' => $allowedFormats
        ]);
        exit;
    }
    
    // Get data based on type
    $startTime = microtime(true);
    
    switch ($type) {
        case 'overview':
            $data = $dashboard->getSystemOverview();
            break;
            
        case 'processes':
            $data = $dashboard->getCurrentProcesses($limit);
            break;
            
        case 'alerts':
            $data = $dashboard->getRecentAlerts($limit, $since);
            break;
            
        case 'performance':
            $data = $dashboard->getPerformanceMetrics($limit);
            break;
            
        case 'health':
            $data = $monitor->performHealthCheck();
            break;
            
        case 'resources':
            $data = $dashboard->getSystemResources();
            break;
            
        default: // 'all'
            $data = $dashboard->getDashboardDataAPI();
    }
    
    $responseTime = round((microtime(true) - $startTime) * 1000, 2);
    
    // Format response based on requested format
    if ($format === 'prometheus') {
        header('Content-Type: text/plain');
        echo $dashboard->exportMetrics('prometheus');
    } else {
        // JSON response with metadata
        $response = [
            'api' => [
                'name' => 'Ozon ETL Monitoring Metrics API',
                'version' => '1.0',
                'timestamp' => date('Y-m-d H:i:s'),
                'response_time_ms' => $responseTime,
                'type' => $type,
                'format' => $format
            ],
            'data' => $data
        ];
        
        // Add pagination info if applicable
        if ($limit && is_array($data) && count($data) >= $limit) {
            $response['pagination'] = [
                'limit' => $limit,
                'has_more' => true,
                'next_url' => $_SERVER['REQUEST_URI'] . '&offset=' . $limit
            ];
        }
        
        echo json_encode($response, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    }
    
} catch (Exception $e) {
    // Handle errors gracefully
    http_response_code(500);
    
    $errorResponse = [
        'api' => [
            'name' => 'Ozon ETL Monitoring Metrics API',
            'version' => '1.0',
            'timestamp' => date('Y-m-d H:i:s')
        ],
        'error' => [
            'message' => 'Failed to retrieve metrics',
            'details' => $e->getMessage(),
            'code' => $e->getCode()
        ]
    ];
    
    echo json_encode($errorResponse, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    
    // Log the error
    error_log("[Ozon ETL Metrics API] ERROR: " . $e->getMessage());
}

/**
 * Additional helper functions for the dashboard class
 */
if (!class_exists('SystemMonitoringDashboard')) {
    // Fallback methods if dashboard class is not available
    class SystemMonitoringDashboard {
        private $pdo;
        private $monitor;
        
        public function __construct($pdo, $monitor) {
            $this->pdo = $pdo;
            $this->monitor = $monitor;
        }
        
        public function getSystemOverview() {
            try {
                $sql = "SELECT 
                            COUNT(CASE WHEN status = 'running' THEN 1 END) as running_processes,
                            COUNT(CASE WHEN status = 'completed' AND DATE(completed_at) = CURDATE() THEN 1 END) as completed_today,
                            COUNT(CASE WHEN status IN ('failed', 'timeout', 'stalled') AND DATE(started_at) = CURDATE() THEN 1 END) as failed_today,
                            AVG(CASE WHEN status = 'completed' AND completed_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR) THEN duration_seconds END) as avg_duration_24h
                        FROM ozon_etl_monitoring";
                
                $stmt = $this->pdo->query($sql);
                return $stmt->fetch(PDO::FETCH_ASSOC);
                
            } catch (Exception $e) {
                return ['error' => $e->getMessage()];
            }
        }
        
        public function getCurrentProcesses($limit = null) {
            try {
                $sql = "SELECT 
                            etl_id,
                            process_type,
                            status,
                            started_at,
                            last_heartbeat,
                            TIMESTAMPDIFF(MINUTE, started_at, NOW()) as running_minutes,
                            TIMESTAMPDIFF(MINUTE, last_heartbeat, NOW()) as minutes_since_heartbeat,
                            memory_usage_mb,
                            cpu_usage_percent,
                            hostname
                        FROM ozon_etl_monitoring 
                        WHERE status IN ('running', 'stalled', 'timeout')
                        ORDER BY started_at DESC";
                
                if ($limit) {
                    $sql .= " LIMIT " . (int)$limit;
                }
                
                $stmt = $this->pdo->query($sql);
                return $stmt->fetchAll(PDO::FETCH_ASSOC);
                
            } catch (Exception $e) {
                return ['error' => $e->getMessage()];
            }
        }
        
        public function getRecentAlerts($limit = null, $since = null) {
            try {
                $sql = "SELECT 
                            alert_type,
                            severity,
                            title,
                            message,
                            etl_id,
                            created_at,
                            is_resolved,
                            resolved_at
                        FROM ozon_etl_alerts";
                
                $conditions = [];
                $params = [];
                
                if ($since) {
                    $conditions[] = "created_at >= :since";
                    $params['since'] = $since;
                }
                
                if (!empty($conditions)) {
                    $sql .= " WHERE " . implode(' AND ', $conditions);
                }
                
                $sql .= " ORDER BY created_at DESC";
                
                if ($limit) {
                    $sql .= " LIMIT " . (int)$limit;
                }
                
                $stmt = $this->pdo->prepare($sql);
                $stmt->execute($params);
                return $stmt->fetchAll(PDO::FETCH_ASSOC);
                
            } catch (Exception $e) {
                return ['error' => $e->getMessage()];
            }
        }
        
        public function getPerformanceMetrics($limit = null) {
            try {
                $sql = "SELECT 
                            DATE(started_at) as date,
                            COUNT(*) as total_runs,
                            COUNT(CASE WHEN status = 'completed' THEN 1 END) as successful_runs,
                            AVG(duration_seconds) as avg_duration,
                            MAX(duration_seconds) as max_duration,
                            AVG(memory_usage_mb) as avg_memory_usage,
                            MAX(memory_usage_mb) as max_memory_usage
                        FROM ozon_etl_monitoring 
                        WHERE started_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
                        AND status IN ('completed', 'failed', 'timeout')
                        GROUP BY DATE(started_at)
                        ORDER BY date DESC";
                
                if ($limit) {
                    $sql .= " LIMIT " . (int)$limit;
                }
                
                $stmt = $this->pdo->query($sql);
                $metrics = $stmt->fetchAll(PDO::FETCH_ASSOC);
                
                // Calculate success rate
                foreach ($metrics as &$metric) {
                    $metric['success_rate'] = $metric['total_runs'] > 0 
                        ? round(($metric['successful_runs'] / $metric['total_runs']) * 100, 2)
                        : 0;
                    $metric['avg_duration_minutes'] = round($metric['avg_duration'] / 60, 2);
                    $metric['max_duration_minutes'] = round($metric['max_duration'] / 60, 2);
                }
                
                return $metrics;
                
            } catch (Exception $e) {
                return ['error' => $e->getMessage()];
            }
        }
        
        public function getSystemResources() {
            $resources = [];
            
            try {
                // Memory usage
                $resources['memory'] = [
                    'current_mb' => round(memory_get_usage(true) / 1024 / 1024, 2),
                    'peak_mb' => round(memory_get_peak_usage(true) / 1024 / 1024, 2),
                    'limit' => ini_get('memory_limit')
                ];
                
                // System load
                if (function_exists('sys_getloadavg')) {
                    $load = sys_getloadavg();
                    $resources['system_load'] = [
                        '1min' => round($load[0], 2),
                        '5min' => round($load[1], 2),
                        '15min' => round($load[2], 2)
                    ];
                }
                
                // Disk space
                $resources['disk'] = [
                    'free_space_gb' => round(disk_free_space('.') / 1024 / 1024 / 1024, 2),
                    'total_space_gb' => round(disk_total_space('.') / 1024 / 1024 / 1024, 2)
                ];
                
            } catch (Exception $e) {
                $resources['error'] = $e->getMessage();
            }
            
            return $resources;
        }
        
        public function getDashboardDataAPI() {
            return [
                'overview' => $this->getSystemOverview(),
                'current_processes' => $this->getCurrentProcesses(),
                'recent_alerts' => $this->getRecentAlerts(),
                'performance_metrics' => $this->getPerformanceMetrics(),
                'system_resources' => $this->getSystemResources()
            ];
        }
        
        public function exportMetrics($format = 'json') {
            $data = $this->getDashboardDataAPI();
            
            if ($format === 'prometheus') {
                $metrics = [];
                
                if (isset($data['overview'])) {
                    $metrics[] = '# HELP ozon_etl_running_processes Number of currently running ETL processes';
                    $metrics[] = '# TYPE ozon_etl_running_processes gauge';
                    $metrics[] = 'ozon_etl_running_processes ' . ($data['overview']['running_processes'] ?? 0);
                    
                    $metrics[] = '# HELP ozon_etl_completed_today Number of ETL processes completed today';
                    $metrics[] = '# TYPE ozon_etl_completed_today counter';
                    $metrics[] = 'ozon_etl_completed_today ' . ($data['overview']['completed_today'] ?? 0);
                    
                    $metrics[] = '# HELP ozon_etl_failed_today Number of ETL processes failed today';
                    $metrics[] = '# TYPE ozon_etl_failed_today counter';
                    $metrics[] = 'ozon_etl_failed_today ' . ($data['overview']['failed_today'] ?? 0);
                }
                
                return implode("\n", $metrics) . "\n";
            }
            
            return json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        }
    }
}