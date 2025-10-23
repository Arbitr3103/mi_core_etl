<?php
/**
 * Uptime Monitoring Script
 * 
 * Monitors warehouse dashboard uptime and sends alerts
 * Run via cron every 5 minutes
 */

require_once __DIR__ . '/../config/production.php';
require_once __DIR__ . '/../config/error_logging_production.php';

class UptimeMonitor {
    private $error_logger;
    private $config;
    private $status_file;
    
    public function __construct($error_logger) {
        $this->error_logger = $error_logger;
        $this->status_file = $_ENV['LOG_PATH'] . '/uptime_status.json';
        $this->config = [
            'timeout' => 10,
            'max_failures' => 3,
            'alert_cooldown' => 1800, // 30 minutes
            'endpoints' => [
                'dashboard' => 'https://www.market-mi.ru/warehouse-dashboard',
                'api_health' => 'https://www.market-mi.ru/api/monitoring.php?action=health',
                'api_warehouse' => 'https://www.market-mi.ru/api/warehouse-dashboard.php?limit=1'
            ]
        ];
    }
    
    /**
     * Run uptime check
     */
    public function runCheck() {
        $timestamp = time();
        $results = [];
        
        foreach ($this->config['endpoints'] as $name => $url) {
            $results[$name] = $this->checkEndpoint($url);
        }
        
        // Load previous status
        $previous_status = $this->loadStatus();
        
        // Update status
        $current_status = [
            'timestamp' => $timestamp,
            'results' => $results,
            'overall_status' => $this->calculateOverallStatus($results)
        ];
        
        $this->saveStatus($current_status);
        
        // Check for alerts
        $this->checkAlerts($current_status, $previous_status);
        
        // Log results
        $this->error_logger->logError('info', 'Uptime Check', [
            'overall_status' => $current_status['overall_status'],
            'failed_endpoints' => $this->getFailedEndpoints($results)
        ]);
        
        return $current_status;
    }
    
    /**
     * Check single endpoint
     */
    private function checkEndpoint($url) {
        $start_time = microtime(true);
        
        $context = stream_context_create([
            'http' => [
                'timeout' => $this->config['timeout'],
                'method' => 'GET',
                'header' => [
                    'User-Agent: Warehouse-Dashboard-Monitor/1.0',
                    'Accept: text/html,application/json'
                ]
            ]
        ]);
        
        try {
            $response = file_get_contents($url, false, $context);
            $response_time = microtime(true) - $start_time;
            
            if ($response === false) {
                return [
                    'status' => 'down',
                    'response_time' => null,
                    'error' => 'No response received',
                    'timestamp' => time()
                ];
            }
            
            // Check if response looks valid
            $is_valid = $this->validateResponse($url, $response);
            
            return [
                'status' => $is_valid ? 'up' : 'degraded',
                'response_time' => round($response_time * 1000, 2),
                'error' => $is_valid ? null : 'Invalid response content',
                'timestamp' => time()
            ];
            
        } catch (Exception $e) {
            return [
                'status' => 'down',
                'response_time' => null,
                'error' => $e->getMessage(),
                'timestamp' => time()
            ];
        }
    }
    
    /**
     * Validate response content
     */
    private function validateResponse($url, $response) {
        if (strpos($url, '/api/') !== false) {
            // API endpoint - should return valid JSON
            $json = json_decode($response, true);
            return $json !== null;
        } else {
            // Dashboard - should contain expected HTML elements
            return strpos($response, 'warehouse-dashboard') !== false || 
                   strpos($response, 'Warehouse Dashboard') !== false;
        }
    }
    
    /**
     * Calculate overall status
     */
    private function calculateOverallStatus($results) {
        $down_count = 0;
        $degraded_count = 0;
        
        foreach ($results as $result) {
            if ($result['status'] === 'down') {
                $down_count++;
            } elseif ($result['status'] === 'degraded') {
                $degraded_count++;
            }
        }
        
        if ($down_count > 0) {
            return $down_count >= count($results) / 2 ? 'critical' : 'warning';
        } elseif ($degraded_count > 0) {
            return 'degraded';
        }
        
        return 'operational';
    }
    
    /**
     * Get failed endpoints
     */
    private function getFailedEndpoints($results) {
        $failed = [];
        foreach ($results as $name => $result) {
            if ($result['status'] !== 'up') {
                $failed[] = $name;
            }
        }
        return $failed;
    }
    
    /**
     * Load previous status
     */
    private function loadStatus() {
        if (!file_exists($this->status_file)) {
            return null;
        }
        
        $content = file_get_contents($this->status_file);
        return json_decode($content, true);
    }
    
    /**
     * Save current status
     */
    private function saveStatus($status) {
        file_put_contents($this->status_file, json_encode($status, JSON_PRETTY_PRINT), LOCK_EX);
    }
    
    /**
     * Check for alerts
     */
    private function checkAlerts($current_status, $previous_status) {
        // Check for status changes
        if ($previous_status) {
            $prev_overall = $previous_status['overall_status'];
            $curr_overall = $current_status['overall_status'];
            
            // Alert on status degradation
            if ($prev_overall === 'operational' && $curr_overall !== 'operational') {
                $this->sendAlert('degradation', $current_status);
            }
            
            // Alert on recovery
            if ($prev_overall !== 'operational' && $curr_overall === 'operational') {
                $this->sendAlert('recovery', $current_status);
            }
            
            // Alert on critical status
            if ($curr_overall === 'critical') {
                $this->sendAlert('critical', $current_status);
            }
        }
        
        // Check individual endpoints
        foreach ($current_status['results'] as $name => $result) {
            if ($result['status'] === 'down') {
                $this->checkEndpointFailures($name, $result);
            }
        }
    }
    
    /**
     * Check endpoint failure count
     */
    private function checkEndpointFailures($endpoint_name, $result) {
        $failures_file = $_ENV['LOG_PATH'] . '/failures_' . $endpoint_name . '.json';
        
        $failures = [];
        if (file_exists($failures_file)) {
            $content = file_get_contents($failures_file);
            $failures = json_decode($content, true) ?: [];
        }
        
        // Add current failure
        $failures[] = [
            'timestamp' => time(),
            'error' => $result['error']
        ];
        
        // Remove old failures (older than 1 hour)
        $cutoff = time() - 3600;
        $failures = array_filter($failures, function($f) use ($cutoff) {
            return $f['timestamp'] > $cutoff;
        });
        
        file_put_contents($failures_file, json_encode($failures), LOCK_EX);
        
        // Send alert if threshold reached
        if (count($failures) >= $this->config['max_failures']) {
            $this->sendAlert('endpoint_down', [
                'endpoint' => $endpoint_name,
                'failure_count' => count($failures),
                'latest_error' => $result['error']
            ]);
        }
    }
    
    /**
     * Send alert
     */
    private function sendAlert($type, $data) {
        $alert_file = $_ENV['LOG_PATH'] . '/last_alert_' . $type . '.txt';
        
        // Check cooldown
        if (file_exists($alert_file)) {
            $last_alert = (int)file_get_contents($alert_file);
            if (time() - $last_alert < $this->config['alert_cooldown']) {
                return; // Still in cooldown
            }
        }
        
        // Record alert time
        file_put_contents($alert_file, time(), LOCK_EX);
        
        // Log alert
        $this->error_logger->logError('critical', 'Uptime Alert', [
            'type' => $type,
            'data' => $data,
            'timestamp' => date('Y-m-d H:i:s')
        ]);
        
        // Send notification (implement based on your notification system)
        $this->sendNotification($type, $data);
    }
    
    /**
     * Send notification (placeholder - implement based on your system)
     */
    private function sendNotification($type, $data) {
        $message = $this->formatAlertMessage($type, $data);
        
        // Log the alert message
        error_log("WAREHOUSE DASHBOARD ALERT: " . $message);
        
        // TODO: Implement actual notification system
        // - Email alerts
        // - Slack/Discord webhooks
        // - SMS notifications
        // - Push notifications
        
        // For now, write to a special alert log
        $alert_log = $_ENV['LOG_PATH'] . '/alerts.log';
        $log_entry = "[" . date('Y-m-d H:i:s') . "] {$type}: {$message}\n";
        file_put_contents($alert_log, $log_entry, FILE_APPEND | LOCK_EX);
    }
    
    /**
     * Format alert message
     */
    private function formatAlertMessage($type, $data) {
        switch ($type) {
            case 'degradation':
                $failed = $this->getFailedEndpoints($data['results']);
                return "Warehouse Dashboard status degraded. Failed endpoints: " . implode(', ', $failed);
                
            case 'recovery':
                return "Warehouse Dashboard has recovered and is now operational";
                
            case 'critical':
                $failed = $this->getFailedEndpoints($data['results']);
                return "CRITICAL: Warehouse Dashboard is experiencing major issues. Failed endpoints: " . implode(', ', $failed);
                
            case 'endpoint_down':
                return "Endpoint '{$data['endpoint']}' has failed {$data['failure_count']} times. Latest error: {$data['latest_error']}";
                
            default:
                return "Unknown alert type: {$type}";
        }
    }
    
    /**
     * Get uptime statistics
     */
    public function getUptimeStats($days = 7) {
        $stats = [
            'period_days' => $days,
            'uptime_percentage' => 0,
            'total_checks' => 0,
            'successful_checks' => 0,
            'avg_response_time_ms' => 0,
            'incidents' => []
        ];
        
        // Load historical data
        $cutoff = time() - ($days * 86400);
        $all_checks = [];
        
        for ($i = 0; $i < $days; $i++) {
            $date = date('Y-m-d', time() - ($i * 86400));
            $log_file = $_ENV['LOG_PATH'] . '/uptime_' . $date . '.json';
            
            if (file_exists($log_file)) {
                $content = file_get_contents($log_file);
                $checks = json_decode($content, true) ?: [];
                
                $checks = array_filter($checks, function($c) use ($cutoff) {
                    return $c['timestamp'] >= $cutoff;
                });
                
                $all_checks = array_merge($all_checks, $checks);
            }
        }
        
        if (empty($all_checks)) {
            return $stats;
        }
        
        $stats['total_checks'] = count($all_checks);
        
        // Calculate uptime
        $successful = array_filter($all_checks, function($c) {
            return $c['overall_status'] === 'operational';
        });
        
        $stats['successful_checks'] = count($successful);
        $stats['uptime_percentage'] = round((count($successful) / count($all_checks)) * 100, 2);
        
        // Calculate average response time
        $response_times = [];
        foreach ($all_checks as $check) {
            foreach ($check['results'] as $result) {
                if ($result['response_time'] !== null) {
                    $response_times[] = $result['response_time'];
                }
            }
        }
        
        if (!empty($response_times)) {
            $stats['avg_response_time_ms'] = round(array_sum($response_times) / count($response_times), 2);
        }
        
        return $stats;
    }
}

// Run if called directly
if (basename(__FILE__) === basename($_SERVER['SCRIPT_NAME'])) {
    global $error_logger;
    
    $monitor = new UptimeMonitor($error_logger);
    
    if (isset($_GET['action'])) {
        header('Content-Type: application/json');
        
        switch ($_GET['action']) {
            case 'check':
                echo json_encode($monitor->runCheck());
                break;
                
            case 'stats':
                $days = (int)($_GET['days'] ?? 7);
                echo json_encode($monitor->getUptimeStats($days));
                break;
                
            default:
                http_response_code(400);
                echo json_encode(['error' => 'Invalid action']);
        }
    } else {
        // Run check and output results
        $result = $monitor->runCheck();
        echo "Uptime check completed at " . date('Y-m-d H:i:s') . "\n";
        echo "Overall status: " . $result['overall_status'] . "\n";
        
        foreach ($result['results'] as $name => $endpoint_result) {
            echo "{$name}: {$endpoint_result['status']}";
            if ($endpoint_result['response_time']) {
                echo " ({$endpoint_result['response_time']}ms)";
            }
            if ($endpoint_result['error']) {
                echo " - {$endpoint_result['error']}";
            }
            echo "\n";
        }
    }
}
?>