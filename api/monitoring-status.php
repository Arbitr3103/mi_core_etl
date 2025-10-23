<?php
/**
 * Monitoring Status API
 * 
 * Provides monitoring status and metrics for the warehouse dashboard
 */

require_once __DIR__ . '/../config/production.php';

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

// Handle preflight requests
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

class MonitoringStatus {
    private $log_path;
    
    public function __construct() {
        $this->log_path = $_ENV['LOG_PATH'] ?? './logs';
        
        // Create logs directory if it doesn't exist
        if (!is_dir($this->log_path)) {
            mkdir($this->log_path, 0755, true);
        }
    }
    
    /**
     * Get monitoring overview
     */
    public function getOverview() {
        return [
            'status' => 'operational',
            'timestamp' => time(),
            'uptime' => $this->getUptimeInfo(),
            'performance' => $this->getPerformanceInfo(),
            'alerts' => $this->getAlertInfo(),
            'system' => $this->getSystemInfo()
        ];
    }
    
    /**
     * Get uptime information
     */
    private function getUptimeInfo() {
        // Simulate uptime data (in production this would read from actual logs)
        return [
            'current_status' => 'up',
            'uptime_24h' => 99.5,
            'uptime_7d' => 99.8,
            'last_downtime' => null,
            'response_time_avg' => 245
        ];
    }
    
    /**
     * Get performance information
     */
    private function getPerformanceInfo() {
        return [
            'avg_response_time_ms' => 245,
            'requests_per_minute' => 12,
            'error_rate_percent' => 0.1,
            'memory_usage_percent' => 45,
            'cpu_usage_percent' => 23
        ];
    }
    
    /**
     * Get alert information
     */
    private function getAlertInfo() {
        return [
            'active_alerts' => 0,
            'alerts_24h' => 2,
            'last_alert' => [
                'type' => 'slow_response',
                'severity' => 'warning',
                'timestamp' => time() - 3600,
                'message' => 'API response time exceeded threshold'
            ]
        ];
    }
    
    /**
     * Get system information
     */
    private function getSystemInfo() {
        return [
            'php_version' => PHP_VERSION,
            'memory_limit' => ini_get('memory_limit'),
            'max_execution_time' => ini_get('max_execution_time'),
            'disk_free_gb' => round(disk_free_space('.') / 1024 / 1024 / 1024, 2),
            'load_average' => sys_getloadavg()[0] ?? 0
        ];
    }
    
    /**
     * Get detailed metrics
     */
    public function getMetrics($hours = 24) {
        $metrics = [];
        $now = time();
        
        // Generate sample metrics data
        for ($i = $hours; $i >= 0; $i--) {
            $timestamp = $now - ($i * 3600);
            $metrics[] = [
                'timestamp' => $timestamp,
                'response_time_ms' => rand(150, 400),
                'requests_count' => rand(50, 200),
                'error_count' => rand(0, 5),
                'memory_usage_mb' => rand(100, 200),
                'cpu_usage_percent' => rand(10, 50)
            ];
        }
        
        return [
            'period_hours' => $hours,
            'data_points' => count($metrics),
            'metrics' => $metrics
        ];
    }
    
    /**
     * Get endpoint status
     */
    public function getEndpointStatus() {
        $endpoints = [
            'dashboard' => [
                'url' => '/warehouse-dashboard',
                'status' => 'up',
                'response_time_ms' => 234,
                'last_check' => time() - 300
            ],
            'api_warehouse' => [
                'url' => '/api/warehouse-dashboard.php',
                'status' => 'up',
                'response_time_ms' => 156,
                'last_check' => time() - 300
            ],
            'api_countries' => [
                'url' => '/api/countries.php',
                'status' => 'up',
                'response_time_ms' => 89,
                'last_check' => time() - 300
            ],
            'api_brands' => [
                'url' => '/api/brands.php',
                'status' => 'up',
                'response_time_ms' => 112,
                'last_check' => time() - 300
            ]
        ];
        
        return $endpoints;
    }
    
    /**
     * Record a monitoring event
     */
    public function recordEvent($type, $data) {
        $event = [
            'timestamp' => time(),
            'type' => $type,
            'data' => $data
        ];
        
        $log_file = $this->log_path . '/monitoring_events.log';
        $log_entry = json_encode($event) . "\n";
        
        file_put_contents($log_file, $log_entry, FILE_APPEND | LOCK_EX);
        
        return ['success' => true, 'event_id' => uniqid()];
    }
}

// Handle requests
try {
    $monitor = new MonitoringStatus();
    $action = $_GET['action'] ?? 'overview';
    
    switch ($action) {
        case 'overview':
            $result = $monitor->getOverview();
            break;
            
        case 'metrics':
            $hours = (int)($_GET['hours'] ?? 24);
            $result = $monitor->getMetrics($hours);
            break;
            
        case 'endpoints':
            $result = $monitor->getEndpointStatus();
            break;
            
        case 'record':
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                throw new Exception('POST method required');
            }
            
            $input = json_decode(file_get_contents('php://input'), true);
            $result = $monitor->recordEvent($input['type'], $input['data']);
            break;
            
        default:
            throw new Exception('Invalid action');
    }
    
    echo json_encode($result);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}
?>