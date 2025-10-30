#!/usr/bin/env php
<?php
/**
 * System Performance Monitor
 * Task 13: Continuous performance monitoring script
 * 
 * This script monitors system performance and can be run via cron
 * to track CPU, memory, disk usage, and generate alerts
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../api/classes/PerformanceTracker.php';

class SystemPerformanceMonitor {
    private $log_file;
    private $alert_file;
    private $thresholds;
    
    public function __construct() {
        $this->log_file = __DIR__ . '/../logs/system_performance.log';
        $this->alert_file = __DIR__ . '/../logs/performance_alerts.log';
        
        // Performance thresholds
        $this->thresholds = [
            'cpu_load_per_core' => 0.8,
            'memory_usage_percent' => 85,
            'disk_usage_percent' => 90,
            'avg_response_time_ms' => 2000,
            'slow_query_count' => 10
        ];
        
        // Ensure log directories exist
        $log_dir = dirname($this->log_file);
        if (!is_dir($log_dir)) {
            mkdir($log_dir, 0755, true);
        }
    }
    
    public function run() {
        echo "=== System Performance Monitor ===\n";
        echo "Started at: " . date('Y-m-d H:i:s') . "\n\n";
        
        $metrics = $this->collectMetrics();
        $this->logMetrics($metrics);
        
        $alerts = $this->checkThresholds($metrics);
        if (!empty($alerts)) {
            $this->logAlerts($alerts);
            $this->displayAlerts($alerts);
        } else {
            echo "✅ All metrics within normal thresholds\n";
        }
        
        $this->displayMetrics($metrics);
        
        echo "\nCompleted at: " . date('Y-m-d H:i:s') . "\n";
    }
    
    private function collectMetrics() {
        $tracker = PerformanceTracker::getInstance();
        
        $metrics = [
            'timestamp' => date('Y-m-d H:i:s'),
            'cpu' => $this->getCpuMetrics(),
            'memory' => $this->getMemoryMetrics(),
            'disk' => $this->getDiskMetrics(),
            'processes' => $this->getProcessMetrics(),
            'network' => $this->getNetworkMetrics(),
            'application' => $this->getApplicationMetrics()
        ];
        
        return $metrics;
    }
    
    private function getCpuMetrics() {
        $metrics = [
            'load_average' => null,
            'cpu_count' => 1,
            'load_per_core' => null,
            'usage_percent' => null
        ];
        
        if (function_exists('sys_getloadavg')) {
            $load = sys_getloadavg();
            $metrics['load_average'] = [
                '1min' => round($load[0], 2),
                '5min' => round($load[1], 2),
                '15min' => round($load[2], 2)
            ];
            
            // Get CPU count
            if (is_file('/proc/cpuinfo')) {
                $cpuinfo = file_get_contents('/proc/cpuinfo');
                preg_match_all('/^processor/m', $cpuinfo, $matches);
                $metrics['cpu_count'] = count($matches[0]);
            }
            
            $metrics['load_per_core'] = round($load[0] / $metrics['cpu_count'], 2);
        }
        
        // Try to get CPU usage percentage (Linux)
        if (is_file('/proc/stat')) {
            $metrics['usage_percent'] = $this->calculateCpuUsage();
        }
        
        return $metrics;
    }
    
    private function calculateCpuUsage() {
        static $prev_idle = null;
        static $prev_total = null;
        
        $stat = file_get_contents('/proc/stat');
        preg_match('/^cpu\s+(\d+)\s+(\d+)\s+(\d+)\s+(\d+)\s+(\d+)\s+(\d+)\s+(\d+)/', $stat, $matches);
        
        if (count($matches) < 8) {
            return null;
        }
        
        $idle = $matches[4] + $matches[5];
        $total = array_sum(array_slice($matches, 1, 7));
        
        if ($prev_idle !== null && $prev_total !== null) {
            $idle_delta = $idle - $prev_idle;
            $total_delta = $total - $prev_total;
            
            if ($total_delta > 0) {
                $usage = 100 * (1 - $idle_delta / $total_delta);
                $prev_idle = $idle;
                $prev_total = $total;
                return round($usage, 2);
            }
        }
        
        $prev_idle = $idle;
        $prev_total = $total;
        
        return null;
    }
    
    private function getMemoryMetrics() {
        $metrics = [
            'total_mb' => 0,
            'used_mb' => 0,
            'free_mb' => 0,
            'usage_percent' => 0,
            'php_memory_mb' => round(memory_get_usage(true) / 1024 / 1024, 2),
            'php_peak_mb' => round(memory_get_peak_usage(true) / 1024 / 1024, 2),
            'php_limit' => ini_get('memory_limit')
        ];
        
        // Get system memory (Linux)
        if (is_file('/proc/meminfo')) {
            $meminfo = file_get_contents('/proc/meminfo');
            
            preg_match('/MemTotal:\s+(\d+)/', $meminfo, $total);
            preg_match('/MemAvailable:\s+(\d+)/', $meminfo, $available);
            
            if (!empty($total) && !empty($available)) {
                $metrics['total_mb'] = round($total[1] / 1024, 2);
                $metrics['free_mb'] = round($available[1] / 1024, 2);
                $metrics['used_mb'] = $metrics['total_mb'] - $metrics['free_mb'];
                $metrics['usage_percent'] = round(($metrics['used_mb'] / $metrics['total_mb']) * 100, 2);
            }
        }
        
        return $metrics;
    }
    
    private function getDiskMetrics() {
        $metrics = [];
        
        $paths = [
            'root' => '/',
            'logs' => __DIR__ . '/../logs',
            'cache' => __DIR__ . '/../cache'
        ];
        
        foreach ($paths as $name => $path) {
            if (is_dir($path)) {
                $total = disk_total_space($path);
                $free = disk_free_space($path);
                $used = $total - $free;
                
                $metrics[$name] = [
                    'total_gb' => round($total / 1024 / 1024 / 1024, 2),
                    'used_gb' => round($used / 1024 / 1024 / 1024, 2),
                    'free_gb' => round($free / 1024 / 1024 / 1024, 2),
                    'usage_percent' => round(($used / $total) * 100, 2)
                ];
            }
        }
        
        return $metrics;
    }
    
    private function getProcessMetrics() {
        $metrics = [
            'php_processes' => 0,
            'total_processes' => 0
        ];
        
        // Count PHP processes (Unix)
        if (function_exists('exec')) {
            exec('ps aux | grep php | grep -v grep | wc -l', $output);
            if (!empty($output)) {
                $metrics['php_processes'] = (int)$output[0];
            }
            
            exec('ps aux | wc -l', $output);
            if (!empty($output)) {
                $metrics['total_processes'] = (int)$output[0];
            }
        }
        
        return $metrics;
    }
    
    private function getNetworkMetrics() {
        $metrics = [
            'connections' => 0,
            'listening_ports' => []
        ];
        
        // Count network connections (Unix)
        if (function_exists('exec')) {
            exec('netstat -an 2>/dev/null | grep ESTABLISHED | wc -l', $output);
            if (!empty($output)) {
                $metrics['connections'] = (int)$output[0];
            }
        }
        
        return $metrics;
    }
    
    private function getApplicationMetrics() {
        $metrics = [
            'recent_requests' => 0,
            'avg_response_time_ms' => 0,
            'slow_queries' => 0,
            'error_count' => 0
        ];
        
        // Read recent performance data
        $perf_log = __DIR__ . '/../logs/performance_tracker.log';
        if (file_exists($perf_log)) {
            $lines = array_slice(file($perf_log, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES), -100);
            
            $response_times = [];
            $slow_queries = 0;
            
            foreach ($lines as $line) {
                $entry = json_decode($line, true);
                if ($entry) {
                    $response_times[] = $entry['total_execution_time_ms'] ?? 0;
                    $slow_queries += $entry['database_queries']['slow_queries'] ?? 0;
                }
            }
            
            $metrics['recent_requests'] = count($response_times);
            $metrics['avg_response_time_ms'] = !empty($response_times) ? 
                round(array_sum($response_times) / count($response_times), 2) : 0;
            $metrics['slow_queries'] = $slow_queries;
        }
        
        // Count recent errors
        $error_log = __DIR__ . '/../logs/error.log';
        if (file_exists($error_log)) {
            $lines = array_slice(file($error_log, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES), -100);
            $metrics['error_count'] = count($lines);
        }
        
        return $metrics;
    }
    
    private function checkThresholds($metrics) {
        $alerts = [];
        
        // Check CPU load
        if (isset($metrics['cpu']['load_per_core']) && 
            $metrics['cpu']['load_per_core'] > $this->thresholds['cpu_load_per_core']) {
            $alerts[] = [
                'severity' => 'high',
                'type' => 'cpu_load',
                'message' => "High CPU load: {$metrics['cpu']['load_per_core']} per core",
                'value' => $metrics['cpu']['load_per_core'],
                'threshold' => $this->thresholds['cpu_load_per_core']
            ];
        }
        
        // Check memory usage
        if ($metrics['memory']['usage_percent'] > $this->thresholds['memory_usage_percent']) {
            $alerts[] = [
                'severity' => 'high',
                'type' => 'memory_usage',
                'message' => "High memory usage: {$metrics['memory']['usage_percent']}%",
                'value' => $metrics['memory']['usage_percent'],
                'threshold' => $this->thresholds['memory_usage_percent']
            ];
        }
        
        // Check disk usage
        foreach ($metrics['disk'] as $disk_name => $disk_metrics) {
            if ($disk_metrics['usage_percent'] > $this->thresholds['disk_usage_percent']) {
                $alerts[] = [
                    'severity' => 'medium',
                    'type' => 'disk_usage',
                    'message' => "High disk usage on {$disk_name}: {$disk_metrics['usage_percent']}%",
                    'value' => $disk_metrics['usage_percent'],
                    'threshold' => $this->thresholds['disk_usage_percent']
                ];
            }
        }
        
        // Check application performance
        if ($metrics['application']['avg_response_time_ms'] > $this->thresholds['avg_response_time_ms']) {
            $alerts[] = [
                'severity' => 'medium',
                'type' => 'slow_response',
                'message' => "Slow average response time: {$metrics['application']['avg_response_time_ms']} ms",
                'value' => $metrics['application']['avg_response_time_ms'],
                'threshold' => $this->thresholds['avg_response_time_ms']
            ];
        }
        
        if ($metrics['application']['slow_queries'] > $this->thresholds['slow_query_count']) {
            $alerts[] = [
                'severity' => 'medium',
                'type' => 'slow_queries',
                'message' => "High number of slow queries: {$metrics['application']['slow_queries']}",
                'value' => $metrics['application']['slow_queries'],
                'threshold' => $this->thresholds['slow_query_count']
            ];
        }
        
        return $alerts;
    }
    
    private function logMetrics($metrics) {
        $log_entry = json_encode($metrics) . "\n";
        file_put_contents($this->log_file, $log_entry, FILE_APPEND | LOCK_EX);
    }
    
    private function logAlerts($alerts) {
        foreach ($alerts as $alert) {
            $log_entry = json_encode(array_merge($alert, [
                'timestamp' => date('Y-m-d H:i:s')
            ])) . "\n";
            file_put_contents($this->alert_file, $log_entry, FILE_APPEND | LOCK_EX);
        }
    }
    
    private function displayMetrics($metrics) {
        echo "\n📊 Current Metrics:\n\n";
        
        // CPU
        echo "🖥️  CPU:\n";
        if (isset($metrics['cpu']['load_average'])) {
            echo "   Load Average: {$metrics['cpu']['load_average']['1min']} / ";
            echo "{$metrics['cpu']['load_average']['5min']} / ";
            echo "{$metrics['cpu']['load_average']['15min']}\n";
            echo "   CPU Cores: {$metrics['cpu']['cpu_count']}\n";
            echo "   Load per Core: {$metrics['cpu']['load_per_core']}\n";
        }
        if (isset($metrics['cpu']['usage_percent'])) {
            echo "   CPU Usage: {$metrics['cpu']['usage_percent']}%\n";
        }
        
        // Memory
        echo "\n💾 Memory:\n";
        echo "   System: {$metrics['memory']['used_mb']} MB / {$metrics['memory']['total_mb']} MB ";
        echo "({$metrics['memory']['usage_percent']}%)\n";
        echo "   PHP: {$metrics['memory']['php_memory_mb']} MB (Peak: {$metrics['memory']['php_peak_mb']} MB)\n";
        echo "   PHP Limit: {$metrics['memory']['php_limit']}\n";
        
        // Disk
        echo "\n💿 Disk Usage:\n";
        foreach ($metrics['disk'] as $name => $disk) {
            echo "   {$name}: {$disk['used_gb']} GB / {$disk['total_gb']} GB ";
            echo "({$disk['usage_percent']}%)\n";
        }
        
        // Application
        echo "\n🚀 Application:\n";
        echo "   Recent Requests: {$metrics['application']['recent_requests']}\n";
        echo "   Avg Response Time: {$metrics['application']['avg_response_time_ms']} ms\n";
        echo "   Slow Queries: {$metrics['application']['slow_queries']}\n";
        echo "   Recent Errors: {$metrics['application']['error_count']}\n";
        
        // Processes
        echo "\n⚙️  Processes:\n";
        echo "   PHP Processes: {$metrics['processes']['php_processes']}\n";
        echo "   Total Processes: {$metrics['processes']['total_processes']}\n";
    }
    
    private function displayAlerts($alerts) {
        echo "\n⚠️  PERFORMANCE ALERTS:\n\n";
        
        foreach ($alerts as $alert) {
            $icon = $alert['severity'] === 'high' ? '🔴' : '🟡';
            echo "{$icon} [{$alert['severity']}] {$alert['message']}\n";
            echo "   Value: {$alert['value']}, Threshold: {$alert['threshold']}\n\n";
        }
    }
}

// Run monitor
if (php_sapi_name() === 'cli') {
    $monitor = new SystemPerformanceMonitor();
    $monitor->run();
} else {
    echo "This script must be run from the command line\n";
}
