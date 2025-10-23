<?php
/**
 * Production Monitoring System
 * 
 * Monitors API response times, uptime, and system health
 */

require_once __DIR__ . '/../config/error_logging_production.php';

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

// Handle preflight requests
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

class SystemMonitor {
    private $error_logger;
    private $metrics_file;
    
    public function __construct($error_logger) {
        $this->error_logger = $error_logger;
        $this->metrics_file = $_ENV['LOG_PATH'] . '/metrics.json';
    }
    
    /**
     * Get system health status
     */
    public function getHealthStatus() {
        $start_time = microtime(true);
        $health = [
            'status' => 'healthy',
            'timestamp' => date('Y-m-d H:i:s'),
            'checks' => []
        ];
        
        // Database connectivity check
        $health['checks']['database'] = $this->checkDatabase();
        
        // Disk space check
        $health['checks']['disk_space'] = $this->checkDiskSpace();
        
        // Memory usage check
        $health['checks']['memory'] = $this->checkMemoryUsage();
        
        // Log file accessibility check
        $health['checks']['logging'] = $this->checkLogging();
        
        // API endpoints check
        $health['checks']['api_endpoints'] = $this->checkApiEndpoints();
        
        // Determine overall status
        $failed_checks = array_filter($health['checks'], function($check) {
            return $check['status'] !== 'ok';
        });
        
        if (count($failed_checks) > 0) {
            $health['status'] = count($failed_checks) > 2 ? 'critical' : 'warning';
        }
        
        $health['response_time_ms'] = round((microtime(true) - $start_time) * 1000, 2);
        
        // Log health check
        $this->error_logger->logError('info', 'Health Check', [
            'status' => $health['status'],
            'failed_checks' => count($failed_checks),
            'response_time_ms' => $health['response_time_ms']
        ]);
        
        return $health;
    }
    
    /**
     * Check database connectivity
     */
    private function checkDatabase() {
        try {
            require_once __DIR__ . '/../config/production.php';
            
            $pdo = new PDO(
                "pgsql:host={$_ENV['DB_HOST']};port={$_ENV['DB_PORT']};dbname={$_ENV['DB_NAME']}",
                $_ENV['DB_USER'],
                $_ENV['DB_PASSWORD'],
                [PDO::ATTR_TIMEOUT => 5]
            );
            
            $start_time = microtime(true);
            $stmt = $pdo->query('SELECT 1');
            $response_time = round((microtime(true) - $start_time) * 1000, 2);
            
            return [
                'status' => 'ok',
                'response_time_ms' => $response_time,
                'message' => 'Database connection successful'
            ];
            
        } catch (Exception $e) {
            return [
                'status' => 'error',
                'message' => 'Database connection failed: ' . $e->getMessage()
            ];
        }
    }
    
    /**
     * Check disk space
     */
    private function checkDiskSpace() {
        $disk_free = disk_free_space('/');
        $disk_total = disk_total_space('/');
        $disk_used_percent = round((($disk_total - $disk_free) / $disk_total) * 100, 2);
        
        $status = 'ok';
        $message = "Disk usage: {$disk_used_percent}%";
        
        if ($disk_used_percent > 90) {
            $status = 'critical';
            $message .= ' - Critical disk usage';
        } elseif ($disk_used_percent > 80) {
            $status = 'warning';
            $message .= ' - High disk usage';
        }
        
        return [
            'status' => $status,
            'disk_used_percent' => $disk_used_percent,
            'disk_free_gb' => round($disk_free / 1024 / 1024 / 1024, 2),
            'message' => $message
        ];
    }
    
    /**
     * Check memory usage
     */
    private function checkMemoryUsage() {
        $memory_used = memory_get_usage(true);
        $memory_peak = memory_get_peak_usage(true);
        $memory_limit = ini_get('memory_limit');
        
        // Convert memory limit to bytes
        $memory_limit_bytes = $this->parseMemoryLimit($memory_limit);
        $memory_used_percent = round(($memory_used / $memory_limit_bytes) * 100, 2);
        
        $status = 'ok';
        $message = "Memory usage: {$memory_used_percent}%";
        
        if ($memory_used_percent > 90) {
            $status = 'critical';
            $message .= ' - Critical memory usage';
        } elseif ($memory_used_percent > 80) {
            $status = 'warning';
            $message .= ' - High memory usage';
        }
        
        return [
            'status' => $status,
            'memory_used_mb' => round($memory_used / 1024 / 1024, 2),
            'memory_peak_mb' => round($memory_peak / 1024 / 1024, 2),
            'memory_used_percent' => $memory_used_percent,
            'message' => $message
        ];
    }
    
    /**
     * Parse memory limit string to bytes
     */
    private function parseMemoryLimit($limit) {
        $limit = trim($limit);
        $last = strtolower($limit[strlen($limit)-1]);
        $limit = (int) $limit;
        
        switch($last) {
            case 'g': $limit *= 1024;
            case 'm': $limit *= 1024;
            case 'k': $limit *= 1024;
        }
        
        return $limit;
    }
    
    /**
     * Check logging system
     */
    private function checkLogging() {
        $log_dir = $_ENV['LOG_PATH'] ?? '/var/log/warehouse-dashboard';
        
        if (!is_dir($log_dir)) {
            return [
                'status' => 'error',
                'message' => 'Log directory does not exist'
            ];
        }
        
        if (!is_writable($log_dir)) {
            return [
                'status' => 'error',
                'message' => 'Log directory is not writable'
            ];
        }
        
        // Test log writing
        $test_file = $log_dir . '/health_check_' . date('Y-m-d') . '.log';
        $test_content = "[" . date('Y-m-d H:i:s') . "] Health check test\n";
        
        if (file_put_contents($test_file, $test_content, FILE_APPEND | LOCK_EX) === false) {
            return [
                'status' => 'error',
                'message' => 'Cannot write to log files'
            ];
        }
        
        return [
            'status' => 'ok',
            'message' => 'Logging system operational'
        ];
    }
    
    /**
     * Check API endpoints
     */
    private function checkApiEndpoints() {
        $endpoints = [
            '/api/warehouse-dashboard.php',
            '/api/countries.php',
            '/api/brands.php'
        ];
        
        $results = [];
        $failed_count = 0;
        
        foreach ($endpoints as $endpoint) {
            $start_time = microtime(true);
            
            try {
                $url = 'http://localhost' . $endpoint . '?health_check=1';
                $context = stream_context_create([
                    'http' => [
                        'timeout' => 5,
                        'method' => 'GET'
                    ]
                ]);
                
                $response = file_get_contents($url, false, $context);
                $response_time = round((microtime(true) - $start_time) * 1000, 2);
                
                if ($response !== false) {
                    $results[$endpoint] = [
                        'status' => 'ok',
                        'response_time_ms' => $response_time
                    ];
                } else {
                    $results[$endpoint] = [
                        'status' => 'error',
                        'message' => 'No response'
                    ];
                    $failed_count++;
                }
                
            } catch (Exception $e) {
                $results[$endpoint] = [
                    'status' => 'error',
                    'message' => $e->getMessage()
                ];
                $failed_count++;
            }
        }
        
        return [
            'status' => $failed_count === 0 ? 'ok' : ($failed_count > 1 ? 'critical' : 'warning'),
            'endpoints' => $results,
            'failed_count' => $failed_count,
            'message' => $failed_count === 0 ? 'All endpoints operational' : "{$failed_count} endpoints failed"
        ];
    }
    
    /**
     * Record performance metrics
     */
    public function recordMetrics($endpoint, $method, $response_time, $status_code, $error = null) {
        $metric = [
            'timestamp' => time(),
            'endpoint' => $endpoint,
            'method' => $method,
            'response_time_ms' => round($response_time * 1000, 2),
            'status_code' => $status_code,
            'error' => $error,
            'memory_usage_mb' => round(memory_get_usage(true) / 1024 / 1024, 2)
        ];
        
        // Store metrics in file (rotate daily)
        $metrics_file = $_ENV['LOG_PATH'] . '/metrics_' . date('Y-m-d') . '.json';
        
        $metrics = [];
        if (file_exists($metrics_file)) {
            $content = file_get_contents($metrics_file);
            $metrics = json_decode($content, true) ?: [];
        }
        
        $metrics[] = $metric;
        
        // Keep only last 10000 metrics per day
        if (count($metrics) > 10000) {
            $metrics = array_slice($metrics, -10000);
        }
        
        file_put_contents($metrics_file, json_encode($metrics), LOCK_EX);
        
        // Log slow responses
        if ($response_time > 3.0) {
            $this->error_logger->logError('warning', 'Slow API Response', [
                'endpoint' => $endpoint,
                'response_time_ms' => $metric['response_time_ms'],
                'status_code' => $status_code
            ]);
        }
        
        // Log errors
        if ($status_code >= 400) {
            $this->error_logger->logError('error', 'API Error Response', [
                'endpoint' => $endpoint,
                'status_code' => $status_code,
                'error' => $error
            ]);
        }
    }
    
    /**
     * Get performance metrics summary
     */
    public function getMetricsSummary($hours = 24) {
        $summary = [
            'period_hours' => $hours,
            'total_requests' => 0,
            'avg_response_time_ms' => 0,
            'error_rate_percent' => 0,
            'slowest_endpoints' => [],
            'error_endpoints' => []
        ];
        
        $cutoff_time = time() - ($hours * 3600);
        $all_metrics = [];
        
        // Load metrics from recent files
        for ($i = 0; $i <= 1; $i++) {
            $date = date('Y-m-d', time() - ($i * 86400));
            $metrics_file = $_ENV['LOG_PATH'] . '/metrics_' . $date . '.json';
            
            if (file_exists($metrics_file)) {
                $content = file_get_contents($metrics_file);
                $metrics = json_decode($content, true) ?: [];
                
                // Filter by time
                $metrics = array_filter($metrics, function($m) use ($cutoff_time) {
                    return $m['timestamp'] >= $cutoff_time;
                });
                
                $all_metrics = array_merge($all_metrics, $metrics);
            }
        }
        
        if (empty($all_metrics)) {
            return $summary;
        }
        
        $summary['total_requests'] = count($all_metrics);
        
        // Calculate averages
        $total_response_time = array_sum(array_column($all_metrics, 'response_time_ms'));
        $summary['avg_response_time_ms'] = round($total_response_time / count($all_metrics), 2);
        
        // Calculate error rate
        $errors = array_filter($all_metrics, function($m) {
            return $m['status_code'] >= 400;
        });
        $summary['error_rate_percent'] = round((count($errors) / count($all_metrics)) * 100, 2);
        
        // Find slowest endpoints
        $endpoint_times = [];
        foreach ($all_metrics as $metric) {
            $endpoint = $metric['endpoint'];
            if (!isset($endpoint_times[$endpoint])) {
                $endpoint_times[$endpoint] = [];
            }
            $endpoint_times[$endpoint][] = $metric['response_time_ms'];
        }
        
        foreach ($endpoint_times as $endpoint => $times) {
            $avg_time = array_sum($times) / count($times);
            $summary['slowest_endpoints'][] = [
                'endpoint' => $endpoint,
                'avg_response_time_ms' => round($avg_time, 2),
                'request_count' => count($times)
            ];
        }
        
        // Sort by response time
        usort($summary['slowest_endpoints'], function($a, $b) {
            return $b['avg_response_time_ms'] <=> $a['avg_response_time_ms'];
        });
        
        $summary['slowest_endpoints'] = array_slice($summary['slowest_endpoints'], 0, 5);
        
        return $summary;
    }
}

// Initialize monitor
global $error_logger;
$monitor = new SystemMonitor($error_logger);

// Handle different actions
$action = $_GET['action'] ?? 'health';

try {
    switch ($action) {
        case 'health':
            $result = $monitor->getHealthStatus();
            break;
            
        case 'metrics':
            $hours = (int)($_GET['hours'] ?? 24);
            $result = $monitor->getMetricsSummary($hours);
            break;
            
        case 'record':
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                throw new Exception('POST method required for recording metrics');
            }
            
            $input = json_decode(file_get_contents('php://input'), true);
            $monitor->recordMetrics(
                $input['endpoint'],
                $input['method'],
                $input['response_time'],
                $input['status_code'],
                $input['error'] ?? null
            );
            
            $result = ['success' => true, 'message' => 'Metrics recorded'];
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