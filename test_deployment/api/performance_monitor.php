<?php
/**
 * Performance Monitoring System for Inventory Analytics
 * Task 7.2: Add performance metrics and logging
 * 
 * This class provides execution time tracking, memory usage monitoring,
 * and performance dashboard for monitoring system health.
 */

class PerformanceMonitor {
    private $metrics = [];
    private $start_times = [];
    private $memory_snapshots = [];
    private $log_file;
    private $enabled;
    
    public function __construct($log_file = null, $enabled = true) {
        $this->log_file = $log_file ?: __DIR__ . '/../logs/performance.log';
        $this->enabled = $enabled;
        
        // Create log directory if it doesn't exist
        $log_dir = dirname($this->log_file);
        if (!is_dir($log_dir)) {
            mkdir($log_dir, 0755, true);
        }
        
        // Initialize system metrics
        $this->recordSystemSnapshot();
    }
    
    /**
     * Start timing an operation
     */
    public function startTimer($operation_name) {
        if (!$this->enabled) return;
        
        $this->start_times[$operation_name] = [
            'start_time' => microtime(true),
            'start_memory' => memory_get_usage(true),
            'start_peak_memory' => memory_get_peak_usage(true)
        ];
    }
    
    /**
     * End timing an operation and record metrics
     */
    public function endTimer($operation_name, $additional_data = []) {
        if (!$this->enabled || !isset($this->start_times[$operation_name])) {
            return null;
        }
        
        $start_data = $this->start_times[$operation_name];
        $end_time = microtime(true);
        $end_memory = memory_get_usage(true);
        $end_peak_memory = memory_get_peak_usage(true);
        
        $metrics = [
            'operation' => $operation_name,
            'execution_time_ms' => round(($end_time - $start_data['start_time']) * 1000, 2),
            'memory_used_mb' => round(($end_memory - $start_data['start_memory']) / 1024 / 1024, 2),
            'peak_memory_mb' => round($end_peak_memory / 1024 / 1024, 2),
            'memory_delta_mb' => round(($end_peak_memory - $start_data['start_peak_memory']) / 1024 / 1024, 2),
            'timestamp' => date('Y-m-d H:i:s'),
            'additional_data' => $additional_data
        ];
        
        // Store metrics
        $this->metrics[] = $metrics;
        
        // Log to file
        $this->logMetrics($metrics);
        
        // Clean up
        unset($this->start_times[$operation_name]);
        
        return $metrics;
    }
    
    /**
     * Record a custom metric
     */
    public function recordMetric($name, $value, $unit = '', $additional_data = []) {
        if (!$this->enabled) return;
        
        $metric = [
            'type' => 'custom_metric',
            'name' => $name,
            'value' => $value,
            'unit' => $unit,
            'timestamp' => date('Y-m-d H:i:s'),
            'memory_usage_mb' => round(memory_get_usage(true) / 1024 / 1024, 2),
            'additional_data' => $additional_data
        ];
        
        $this->metrics[] = $metric;
        $this->logMetrics($metric);
    }
    
    /**
     * Record database query performance
     */
    public function recordDatabaseQuery($query, $execution_time, $result_count = null, $explain_plan = null) {
        if (!$this->enabled) return;
        
        $metric = [
            'type' => 'database_query',
            'query_hash' => md5($query),
            'query_preview' => substr($query, 0, 100) . (strlen($query) > 100 ? '...' : ''),
            'execution_time_ms' => round($execution_time * 1000, 2),
            'result_count' => $result_count,
            'explain_plan' => $explain_plan,
            'timestamp' => date('Y-m-d H:i:s'),
            'memory_usage_mb' => round(memory_get_usage(true) / 1024 / 1024, 2)
        ];
        
        $this->metrics[] = $metric;
        $this->logMetrics($metric);
    }
    
    /**
     * Record API endpoint performance
     */
    public function recordApiEndpoint($endpoint, $method, $execution_time, $response_size = null, $status_code = 200) {
        if (!$this->enabled) return;
        
        $metric = [
            'type' => 'api_endpoint',
            'endpoint' => $endpoint,
            'method' => $method,
            'execution_time_ms' => round($execution_time * 1000, 2),
            'response_size_bytes' => $response_size,
            'status_code' => $status_code,
            'timestamp' => date('Y-m-d H:i:s'),
            'memory_usage_mb' => round(memory_get_usage(true) / 1024 / 1024, 2)
        ];
        
        $this->metrics[] = $metric;
        $this->logMetrics($metric);
    }
    
    /**
     * Record system snapshot
     */
    public function recordSystemSnapshot() {
        if (!$this->enabled) return;
        
        $snapshot = [
            'type' => 'system_snapshot',
            'timestamp' => date('Y-m-d H:i:s'),
            'memory_usage_mb' => round(memory_get_usage(true) / 1024 / 1024, 2),
            'peak_memory_mb' => round(memory_get_peak_usage(true) / 1024 / 1024, 2),
            'memory_limit' => ini_get('memory_limit'),
            'execution_time_limit' => ini_get('max_execution_time'),
            'php_version' => PHP_VERSION,
            'server_load' => $this->getServerLoad(),
            'disk_usage' => $this->getDiskUsage()
        ];
        
        $this->memory_snapshots[] = $snapshot;
        $this->logMetrics($snapshot);
    }
    
    /**
     * Get server load average (Unix systems)
     */
    private function getServerLoad() {
        if (function_exists('sys_getloadavg')) {
            $load = sys_getloadavg();
            return [
                '1min' => $load[0],
                '5min' => $load[1],
                '15min' => $load[2]
            ];
        }
        return null;
    }
    
    /**
     * Get disk usage for cache and log directories
     */
    private function getDiskUsage() {
        $cache_dir = __DIR__ . '/../cache';
        $logs_dir = __DIR__ . '/../logs';
        
        $usage = [];
        
        if (is_dir($cache_dir)) {
            $usage['cache_size_mb'] = round($this->getDirectorySize($cache_dir) / 1024 / 1024, 2);
        }
        
        if (is_dir($logs_dir)) {
            $usage['logs_size_mb'] = round($this->getDirectorySize($logs_dir) / 1024 / 1024, 2);
        }
        
        return $usage;
    }
    
    /**
     * Calculate directory size recursively
     */
    private function getDirectorySize($directory) {
        $size = 0;
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory));
        
        foreach ($iterator as $file) {
            if ($file->isFile()) {
                $size += $file->getSize();
            }
        }
        
        return $size;
    }
    
    /**
     * Log metrics to file
     */
    private function logMetrics($metrics) {
        if (!$this->enabled) return;
        
        $log_entry = json_encode($metrics) . "\n";
        file_put_contents($this->log_file, $log_entry, FILE_APPEND | LOCK_EX);
    }
    
    /**
     * Get performance summary
     */
    public function getPerformanceSummary($hours = 24) {
        $cutoff_time = time() - ($hours * 3600);
        $recent_metrics = array_filter($this->metrics, function($metric) use ($cutoff_time) {
            return strtotime($metric['timestamp']) > $cutoff_time;
        });
        
        if (empty($recent_metrics)) {
            return [
                'summary' => 'No metrics available for the specified time period',
                'period_hours' => $hours,
                'total_operations' => 0
            ];
        }
        
        // Group metrics by type
        $by_type = [];
        foreach ($recent_metrics as $metric) {
            $type = $metric['type'] ?? 'operation';
            if (!isset($by_type[$type])) {
                $by_type[$type] = [];
            }
            $by_type[$type][] = $metric;
        }
        
        $summary = [
            'period_hours' => $hours,
            'total_operations' => count($recent_metrics),
            'by_type' => []
        ];
        
        foreach ($by_type as $type => $metrics) {
            $execution_times = array_filter(array_column($metrics, 'execution_time_ms'));
            $memory_usage = array_filter(array_column($metrics, 'memory_usage_mb'));
            
            $type_summary = [
                'count' => count($metrics),
                'avg_execution_time_ms' => !empty($execution_times) ? round(array_sum($execution_times) / count($execution_times), 2) : null,
                'max_execution_time_ms' => !empty($execution_times) ? max($execution_times) : null,
                'min_execution_time_ms' => !empty($execution_times) ? min($execution_times) : null,
                'avg_memory_usage_mb' => !empty($memory_usage) ? round(array_sum($memory_usage) / count($memory_usage), 2) : null,
                'max_memory_usage_mb' => !empty($memory_usage) ? max($memory_usage) : null
            ];
            
            // Add specific analysis for different types
            if ($type === 'database_query') {
                $slow_queries = array_filter($metrics, function($m) {
                    return isset($m['execution_time_ms']) && $m['execution_time_ms'] > 1000; // > 1 second
                });
                $type_summary['slow_queries_count'] = count($slow_queries);
                $type_summary['slow_queries_percentage'] = round((count($slow_queries) / count($metrics)) * 100, 2);
            }
            
            if ($type === 'api_endpoint') {
                $error_responses = array_filter($metrics, function($m) {
                    return isset($m['status_code']) && $m['status_code'] >= 400;
                });
                $type_summary['error_responses_count'] = count($error_responses);
                $type_summary['error_rate_percentage'] = round((count($error_responses) / count($metrics)) * 100, 2);
            }
            
            $summary['by_type'][$type] = $type_summary;
        }
        
        return $summary;
    }
    
    /**
     * Get performance trends
     */
    public function getPerformanceTrends($hours = 24, $interval_minutes = 60) {
        $cutoff_time = time() - ($hours * 3600);
        $interval_seconds = $interval_minutes * 60;
        
        $recent_metrics = array_filter($this->metrics, function($metric) use ($cutoff_time) {
            return strtotime($metric['timestamp']) > $cutoff_time;
        });
        
        if (empty($recent_metrics)) {
            return ['trends' => [], 'message' => 'No data available for trends analysis'];
        }
        
        // Group metrics by time intervals
        $trends = [];
        $start_time = $cutoff_time;
        $end_time = time();
        
        for ($time = $start_time; $time < $end_time; $time += $interval_seconds) {
            $interval_end = $time + $interval_seconds;
            $interval_metrics = array_filter($recent_metrics, function($metric) use ($time, $interval_end) {
                $metric_time = strtotime($metric['timestamp']);
                return $metric_time >= $time && $metric_time < $interval_end;
            });
            
            if (!empty($interval_metrics)) {
                $execution_times = array_filter(array_column($interval_metrics, 'execution_time_ms'));
                $memory_usage = array_filter(array_column($interval_metrics, 'memory_usage_mb'));
                
                $trends[] = [
                    'timestamp' => date('Y-m-d H:i:s', $time),
                    'operations_count' => count($interval_metrics),
                    'avg_execution_time_ms' => !empty($execution_times) ? round(array_sum($execution_times) / count($execution_times), 2) : 0,
                    'avg_memory_usage_mb' => !empty($memory_usage) ? round(array_sum($memory_usage) / count($memory_usage), 2) : 0,
                    'max_execution_time_ms' => !empty($execution_times) ? max($execution_times) : 0
                ];
            }
        }
        
        return [
            'trends' => $trends,
            'interval_minutes' => $interval_minutes,
            'total_intervals' => count($trends)
        ];
    }
    
    /**
     * Get slowest operations
     */
    public function getSlowestOperations($limit = 10, $hours = 24) {
        $cutoff_time = time() - ($hours * 3600);
        $recent_metrics = array_filter($this->metrics, function($metric) use ($cutoff_time) {
            return strtotime($metric['timestamp']) > $cutoff_time && isset($metric['execution_time_ms']);
        });
        
        // Sort by execution time descending
        usort($recent_metrics, function($a, $b) {
            return $b['execution_time_ms'] <=> $a['execution_time_ms'];
        });
        
        return array_slice($recent_metrics, 0, $limit);
    }
    
    /**
     * Get memory intensive operations
     */
    public function getMemoryIntensiveOperations($limit = 10, $hours = 24) {
        $cutoff_time = time() - ($hours * 3600);
        $recent_metrics = array_filter($this->metrics, function($metric) use ($cutoff_time) {
            return strtotime($metric['timestamp']) > $cutoff_time && isset($metric['memory_usage_mb']);
        });
        
        // Sort by memory usage descending
        usort($recent_metrics, function($a, $b) {
            return $b['memory_usage_mb'] <=> $a['memory_usage_mb'];
        });
        
        return array_slice($recent_metrics, 0, $limit);
    }
    
    /**
     * Generate performance alerts
     */
    public function generatePerformanceAlerts($thresholds = []) {
        $default_thresholds = [
            'max_execution_time_ms' => 5000,  // 5 seconds
            'max_memory_usage_mb' => 128,     // 128 MB
            'error_rate_threshold' => 5,      // 5%
            'slow_query_threshold_ms' => 1000 // 1 second
        ];
        
        $thresholds = array_merge($default_thresholds, $thresholds);
        $alerts = [];
        
        $summary = $this->getPerformanceSummary(1); // Last hour
        
        // Check for slow operations
        foreach ($summary['by_type'] as $type => $stats) {
            if (isset($stats['max_execution_time_ms']) && $stats['max_execution_time_ms'] > $thresholds['max_execution_time_ms']) {
                $alerts[] = [
                    'type' => 'slow_operation',
                    'severity' => 'high',
                    'message' => "Slow {$type} detected: {$stats['max_execution_time_ms']}ms (threshold: {$thresholds['max_execution_time_ms']}ms)",
                    'value' => $stats['max_execution_time_ms'],
                    'threshold' => $thresholds['max_execution_time_ms']
                ];
            }
            
            if (isset($stats['max_memory_usage_mb']) && $stats['max_memory_usage_mb'] > $thresholds['max_memory_usage_mb']) {
                $alerts[] = [
                    'type' => 'high_memory_usage',
                    'severity' => 'medium',
                    'message' => "High memory usage in {$type}: {$stats['max_memory_usage_mb']}MB (threshold: {$thresholds['max_memory_usage_mb']}MB)",
                    'value' => $stats['max_memory_usage_mb'],
                    'threshold' => $thresholds['max_memory_usage_mb']
                ];
            }
            
            if (isset($stats['error_rate_percentage']) && $stats['error_rate_percentage'] > $thresholds['error_rate_threshold']) {
                $alerts[] = [
                    'type' => 'high_error_rate',
                    'severity' => 'high',
                    'message' => "High error rate in {$type}: {$stats['error_rate_percentage']}% (threshold: {$thresholds['error_rate_threshold']}%)",
                    'value' => $stats['error_rate_percentage'],
                    'threshold' => $thresholds['error_rate_threshold']
                ];
            }
        }
        
        return [
            'alerts' => $alerts,
            'total_alerts' => count($alerts),
            'high_severity_count' => count(array_filter($alerts, function($a) { return $a['severity'] === 'high'; })),
            'generated_at' => date('Y-m-d H:i:s')
        ];
    }
    
    /**
     * Clear old metrics
     */
    public function clearOldMetrics($hours = 168) { // 7 days default
        $cutoff_time = time() - ($hours * 3600);
        
        $this->metrics = array_filter($this->metrics, function($metric) use ($cutoff_time) {
            return strtotime($metric['timestamp']) > $cutoff_time;
        });
        
        $this->memory_snapshots = array_filter($this->memory_snapshots, function($snapshot) use ($cutoff_time) {
            return strtotime($snapshot['timestamp']) > $cutoff_time;
        });
        
        return count($this->metrics);
    }
    
    /**
     * Export metrics to JSON
     */
    public function exportMetrics($hours = 24) {
        $cutoff_time = time() - ($hours * 3600);
        $recent_metrics = array_filter($this->metrics, function($metric) use ($cutoff_time) {
            return strtotime($metric['timestamp']) > $cutoff_time;
        });
        
        return [
            'export_timestamp' => date('Y-m-d H:i:s'),
            'period_hours' => $hours,
            'total_metrics' => count($recent_metrics),
            'metrics' => array_values($recent_metrics),
            'summary' => $this->getPerformanceSummary($hours)
        ];
    }
    
    /**
     * Enable/disable monitoring
     */
    public function setEnabled($enabled) {
        $this->enabled = $enabled;
    }
    
    /**
     * Check if monitoring is enabled
     */
    public function isEnabled() {
        return $this->enabled;
    }
}

/**
 * Global performance monitor instance
 */
function getPerformanceMonitor() {
    static $instance = null;
    
    if ($instance === null) {
        $log_file = $_ENV['PERFORMANCE_LOG_FILE'] ?? __DIR__ . '/../logs/performance.log';
        $enabled = $_ENV['PERFORMANCE_MONITORING_ENABLED'] ?? true;
        
        $instance = new PerformanceMonitor($log_file, $enabled);
    }
    
    return $instance;
}
?>