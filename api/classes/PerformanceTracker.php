<?php
/**
 * Enhanced Performance Tracker
 * Task 13: Add comprehensive performance monitoring
 * 
 * Tracks API response times, database query performance, memory and CPU usage
 */

class PerformanceTracker {
    private static $instance = null;
    private $metrics = [];
    private $log_file;
    private $enabled;
    private $start_time;
    private $start_memory;
    
    private function __construct() {
        $this->log_file = __DIR__ . '/../../logs/performance_tracker.log';
        $this->enabled = $_ENV['PERFORMANCE_TRACKING_ENABLED'] ?? true;
        $this->start_time = microtime(true);
        $this->start_memory = memory_get_usage(true);
        
        // Ensure log directory exists
        $log_dir = dirname($this->log_file);
        if (!is_dir($log_dir)) {
            mkdir($log_dir, 0755, true);
        }
        
        // Register shutdown function to log request completion
        register_shutdown_function([$this, 'logRequestCompletion']);
    }
    
    public static function getInstance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    /**
     * Track API endpoint performance
     */
    public function trackApiRequest($endpoint, $method = 'GET') {
        if (!$this->enabled) return;
        
        $this->metrics['api_request'] = [
            'endpoint' => $endpoint,
            'method' => $method,
            'start_time' => microtime(true),
            'start_memory' => memory_get_usage(true),
            'request_uri' => $_SERVER['REQUEST_URI'] ?? '',
            'query_string' => $_SERVER['QUERY_STRING'] ?? '',
            'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? '',
            'remote_addr' => $_SERVER['REMOTE_ADDR'] ?? ''
        ];
    }
    
    /**
     * Track database query performance
     */
    public function trackDatabaseQuery($query, $params = []) {
        if (!$this->enabled) return;
        
        $query_id = uniqid('query_', true);
        
        $this->metrics['database_queries'][$query_id] = [
            'query' => $this->sanitizeQuery($query),
            'query_hash' => md5($query),
            'params_count' => count($params),
            'start_time' => microtime(true),
            'start_memory' => memory_get_usage(true)
        ];
        
        return $query_id;
    }
    
    /**
     * End database query tracking
     */
    public function endDatabaseQuery($query_id, $result_count = null) {
        if (!$this->enabled || !isset($this->metrics['database_queries'][$query_id])) {
            return;
        }
        
        $query_data = &$this->metrics['database_queries'][$query_id];
        $query_data['end_time'] = microtime(true);
        $query_data['execution_time_ms'] = round(($query_data['end_time'] - $query_data['start_time']) * 1000, 2);
        $query_data['memory_used_mb'] = round((memory_get_usage(true) - $query_data['start_memory']) / 1024 / 1024, 2);
        $query_data['result_count'] = $result_count;
        
        // Flag slow queries
        if ($query_data['execution_time_ms'] > 1000) {
            $query_data['slow_query'] = true;
        }
    }
    
    /**
     * Track cache operations
     */
    public function trackCacheOperation($operation, $key, $hit = null) {
        if (!$this->enabled) return;
        
        if (!isset($this->metrics['cache_operations'])) {
            $this->metrics['cache_operations'] = [];
        }
        
        $this->metrics['cache_operations'][] = [
            'operation' => $operation,
            'key' => $key,
            'hit' => $hit,
            'timestamp' => microtime(true)
        ];
    }
    
    /**
     * Track external API calls
     */
    public function trackExternalApiCall($service, $endpoint) {
        if (!$this->enabled) return;
        
        $call_id = uniqid('ext_api_', true);
        
        if (!isset($this->metrics['external_api_calls'])) {
            $this->metrics['external_api_calls'] = [];
        }
        
        $this->metrics['external_api_calls'][$call_id] = [
            'service' => $service,
            'endpoint' => $endpoint,
            'start_time' => microtime(true)
        ];
        
        return $call_id;
    }
    
    /**
     * End external API call tracking
     */
    public function endExternalApiCall($call_id, $status_code = null, $response_size = null) {
        if (!$this->enabled || !isset($this->metrics['external_api_calls'][$call_id])) {
            return;
        }
        
        $call_data = &$this->metrics['external_api_calls'][$call_id];
        $call_data['end_time'] = microtime(true);
        $call_data['execution_time_ms'] = round(($call_data['end_time'] - $call_data['start_time']) * 1000, 2);
        $call_data['status_code'] = $status_code;
        $call_data['response_size_bytes'] = $response_size;
    }
    
    /**
     * Get current CPU usage (Unix systems)
     */
    public function getCpuUsage() {
        if (function_exists('sys_getloadavg')) {
            $load = sys_getloadavg();
            return [
                'load_1min' => round($load[0], 2),
                'load_5min' => round($load[1], 2),
                'load_15min' => round($load[2], 2),
                'cpu_count' => $this->getCpuCount()
            ];
        }
        return null;
    }
    
    /**
     * Get CPU count
     */
    private function getCpuCount() {
        if (is_file('/proc/cpuinfo')) {
            $cpuinfo = file_get_contents('/proc/cpuinfo');
            preg_match_all('/^processor/m', $cpuinfo, $matches);
            return count($matches[0]);
        }
        return 1;
    }
    
    /**
     * Get memory usage statistics
     */
    public function getMemoryUsage() {
        return [
            'current_mb' => round(memory_get_usage(true) / 1024 / 1024, 2),
            'peak_mb' => round(memory_get_peak_usage(true) / 1024 / 1024, 2),
            'limit' => ini_get('memory_limit'),
            'usage_percent' => $this->getMemoryUsagePercent()
        ];
    }
    
    /**
     * Calculate memory usage percentage
     */
    private function getMemoryUsagePercent() {
        $limit = ini_get('memory_limit');
        if ($limit === '-1') {
            return 0;
        }
        
        $limit_bytes = $this->convertToBytes($limit);
        $current_bytes = memory_get_usage(true);
        
        return round(($current_bytes / $limit_bytes) * 100, 2);
    }
    
    /**
     * Convert memory limit string to bytes
     */
    private function convertToBytes($value) {
        $value = trim($value);
        $last = strtolower($value[strlen($value)-1]);
        $value = (int)$value;
        
        switch($last) {
            case 'g':
                $value *= 1024;
            case 'm':
                $value *= 1024;
            case 'k':
                $value *= 1024;
        }
        
        return $value;
    }
    
    /**
     * Log request completion
     */
    public function logRequestCompletion() {
        if (!$this->enabled) return;
        
        $total_time = microtime(true) - $this->start_time;
        $total_memory = memory_get_peak_usage(true) - $this->start_memory;
        
        $log_entry = [
            'timestamp' => date('Y-m-d H:i:s'),
            'request' => $this->metrics['api_request'] ?? [],
            'total_execution_time_ms' => round($total_time * 1000, 2),
            'total_memory_used_mb' => round($total_memory / 1024 / 1024, 2),
            'peak_memory_mb' => round(memory_get_peak_usage(true) / 1024 / 1024, 2),
            'database_queries' => [
                'count' => count($this->metrics['database_queries'] ?? []),
                'total_time_ms' => $this->calculateTotalQueryTime(),
                'slow_queries' => $this->countSlowQueries(),
                'queries' => array_values($this->metrics['database_queries'] ?? [])
            ],
            'cache_operations' => [
                'count' => count($this->metrics['cache_operations'] ?? []),
                'hits' => $this->countCacheHits(),
                'misses' => $this->countCacheMisses()
            ],
            'external_api_calls' => [
                'count' => count($this->metrics['external_api_calls'] ?? []),
                'total_time_ms' => $this->calculateTotalExternalApiTime(),
                'calls' => array_values($this->metrics['external_api_calls'] ?? [])
            ],
            'system_resources' => [
                'cpu' => $this->getCpuUsage(),
                'memory' => $this->getMemoryUsage()
            ]
        ];
        
        // Add performance flags
        $log_entry['performance_flags'] = $this->generatePerformanceFlags($log_entry);
        
        // Write to log file
        file_put_contents(
            $this->log_file,
            json_encode($log_entry) . "\n",
            FILE_APPEND | LOCK_EX
        );
    }
    
    /**
     * Calculate total query execution time
     */
    private function calculateTotalQueryTime() {
        $total = 0;
        foreach ($this->metrics['database_queries'] ?? [] as $query) {
            $total += $query['execution_time_ms'] ?? 0;
        }
        return round($total, 2);
    }
    
    /**
     * Count slow queries
     */
    private function countSlowQueries() {
        $count = 0;
        foreach ($this->metrics['database_queries'] ?? [] as $query) {
            if (isset($query['slow_query']) && $query['slow_query']) {
                $count++;
            }
        }
        return $count;
    }
    
    /**
     * Count cache hits
     */
    private function countCacheHits() {
        $count = 0;
        foreach ($this->metrics['cache_operations'] ?? [] as $op) {
            if ($op['hit'] === true) {
                $count++;
            }
        }
        return $count;
    }
    
    /**
     * Count cache misses
     */
    private function countCacheMisses() {
        $count = 0;
        foreach ($this->metrics['cache_operations'] ?? [] as $op) {
            if ($op['hit'] === false) {
                $count++;
            }
        }
        return $count;
    }
    
    /**
     * Calculate total external API time
     */
    private function calculateTotalExternalApiTime() {
        $total = 0;
        foreach ($this->metrics['external_api_calls'] ?? [] as $call) {
            $total += $call['execution_time_ms'] ?? 0;
        }
        return round($total, 2);
    }
    
    /**
     * Generate performance flags
     */
    private function generatePerformanceFlags($log_entry) {
        $flags = [];
        
        // Check response time
        if ($log_entry['total_execution_time_ms'] > 5000) {
            $flags[] = 'VERY_SLOW_RESPONSE';
        } elseif ($log_entry['total_execution_time_ms'] > 2000) {
            $flags[] = 'SLOW_RESPONSE';
        }
        
        // Check memory usage
        if ($log_entry['system_resources']['memory']['usage_percent'] > 90) {
            $flags[] = 'HIGH_MEMORY_USAGE';
        } elseif ($log_entry['system_resources']['memory']['usage_percent'] > 75) {
            $flags[] = 'ELEVATED_MEMORY_USAGE';
        }
        
        // Check slow queries
        if ($log_entry['database_queries']['slow_queries'] > 0) {
            $flags[] = 'SLOW_QUERIES_DETECTED';
        }
        
        // Check CPU load
        $cpu = $log_entry['system_resources']['cpu'];
        if ($cpu && isset($cpu['load_1min']) && isset($cpu['cpu_count'])) {
            $load_per_cpu = $cpu['load_1min'] / $cpu['cpu_count'];
            if ($load_per_cpu > 0.8) {
                $flags[] = 'HIGH_CPU_LOAD';
            }
        }
        
        return $flags;
    }
    
    /**
     * Sanitize query for logging
     */
    private function sanitizeQuery($query) {
        // Remove extra whitespace
        $query = preg_replace('/\s+/', ' ', trim($query));
        
        // Truncate if too long
        if (strlen($query) > 500) {
            $query = substr($query, 0, 500) . '...';
        }
        
        return $query;
    }
    
    /**
     * Get current metrics
     */
    public function getMetrics() {
        return $this->metrics;
    }
    
    /**
     * Enable/disable tracking
     */
    public function setEnabled($enabled) {
        $this->enabled = $enabled;
    }
    
    /**
     * Check if tracking is enabled
     */
    public function isEnabled() {
        return $this->enabled;
    }
}
