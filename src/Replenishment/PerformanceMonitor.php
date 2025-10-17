<?php

namespace Replenishment;

use PDO;
use Exception;

/**
 * PerformanceMonitor Class
 * 
 * Monitors and tracks performance metrics for the replenishment system.
 * Provides execution time monitoring, memory usage tracking, and performance analytics.
 */
class PerformanceMonitor
{
    private PDO $pdo;
    private array $config;
    private array $metrics;
    private array $timers;
    private ?RedisCache $cache;
    
    public function __construct(PDO $pdo, array $config = [])
    {
        $this->pdo = $pdo;
        $this->config = array_merge([
            'enable_db_logging' => true,
            'enable_cache_logging' => true,
            'enable_memory_tracking' => true,
            'enable_query_profiling' => true,
            'retention_days' => 30,
            'alert_thresholds' => [
                'execution_time' => 30.0,      // seconds
                'memory_usage' => 512,         // MB
                'query_time' => 5.0,           // seconds
                'cache_hit_rate' => 80.0       // percentage
            ],
            'debug' => false
        ], $config);
        
        $this->metrics = [];
        $this->timers = [];
        
        // Initialize Redis cache if available
        try {
            $this->cache = new RedisCache($config['redis'] ?? []);
        } catch (Exception $e) {
            $this->cache = null;
            $this->log("Redis cache not available for performance monitoring: " . $e->getMessage(), 'WARN');
        }
        
        $this->initializeMonitoringTables();
    }
    
    /**
     * Start timing a process
     * 
     * @param string $name Timer name
     * @param array $context Additional context data
     */
    public function startTimer(string $name, array $context = []): void
    {
        $this->timers[$name] = [
            'start_time' => microtime(true),
            'start_memory' => memory_get_usage(true),
            'context' => $context,
            'queries_before' => $this->getQueryCount()
        ];
        
        $this->log("Timer started: $name");
    }
    
    /**
     * Stop timing a process and record metrics
     * 
     * @param string $name Timer name
     * @param array $additionalData Additional data to record
     * @return array Performance metrics
     */
    public function stopTimer(string $name, array $additionalData = []): array
    {
        if (!isset($this->timers[$name])) {
            throw new Exception("Timer '$name' was not started");
        }
        
        $timer = $this->timers[$name];
        $endTime = microtime(true);
        $endMemory = memory_get_usage(true);
        
        $metrics = [
            'name' => $name,
            'execution_time' => round($endTime - $timer['start_time'], 4),
            'memory_used' => $endMemory - $timer['start_memory'],
            'memory_peak' => memory_get_peak_usage(true),
            'queries_executed' => $this->getQueryCount() - $timer['queries_before'],
            'timestamp' => date('Y-m-d H:i:s'),
            'context' => $timer['context'],
            'additional_data' => $additionalData
        ];
        
        // Store metrics
        $this->recordMetrics($metrics);
        
        // Check for performance alerts
        $this->checkPerformanceAlerts($metrics);
        
        // Clean up timer
        unset($this->timers[$name]);
        
        $this->log("Timer stopped: $name - {$metrics['execution_time']}s, " . 
                  $this->formatBytes($metrics['memory_used']) . " memory");
        
        return $metrics;
    }
    
    /**
     * Record performance metrics
     * 
     * @param array $metrics Metrics data
     */
    private function recordMetrics(array $metrics): void
    {
        // Store in memory for current session
        $this->metrics[] = $metrics;
        
        // Store in database if enabled
        if ($this->config['enable_db_logging']) {
            $this->storeMetricsInDatabase($metrics);
        }
        
        // Store in cache if enabled
        if ($this->config['enable_cache_logging'] && $this->cache) {
            $this->storeMetricsInCache($metrics);
        }
    }
    
    /**
     * Store metrics in database
     * 
     * @param array $metrics Metrics data
     */
    private function storeMetricsInDatabase(array $metrics): void
    {
        try {
            $sql = "
                INSERT INTO performance_metrics (
                    metric_name, execution_time, memory_used, memory_peak,
                    queries_executed, context_data, additional_data, created_at
                ) VALUES (?, ?, ?, ?, ?, ?, ?, NOW())
            ";
            
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute([
                $metrics['name'],
                $metrics['execution_time'],
                $metrics['memory_used'],
                $metrics['memory_peak'],
                $metrics['queries_executed'],
                json_encode($metrics['context']),
                json_encode($metrics['additional_data'])
            ]);
            
        } catch (Exception $e) {
            $this->log("Error storing metrics in database: " . $e->getMessage(), 'ERROR');
        }
    }
    
    /**
     * Store metrics in cache
     * 
     * @param array $metrics Metrics data
     */
    private function storeMetricsInCache(array $metrics): void
    {
        try {
            $key = "metrics:recent:" . date('Y-m-d-H');
            $existingMetrics = $this->cache->get($key) ?? [];
            $existingMetrics[] = $metrics;
            
            // Keep only last 100 metrics per hour
            if (count($existingMetrics) > 100) {
                $existingMetrics = array_slice($existingMetrics, -100);
            }
            
            $this->cache->set($key, $existingMetrics, 7200); // 2 hours
            
            // Update performance counters
            $this->updatePerformanceCounters($metrics);
            
        } catch (Exception $e) {
            $this->log("Error storing metrics in cache: " . $e->getMessage(), 'ERROR');
        }
    }
    
    /**
     * Update performance counters in cache
     * 
     * @param array $metrics Metrics data
     */
    private function updatePerformanceCounters(array $metrics): void
    {
        if (!$this->cache) {
            return;
        }
        
        $date = date('Y-m-d');
        
        // Increment execution counter
        $this->cache->increment("counter:executions:$date");
        
        // Track execution times
        $timeKey = "times:{$metrics['name']}:$date";
        $times = $this->cache->get($timeKey) ?? [];
        $times[] = $metrics['execution_time'];
        
        // Keep only last 1000 execution times
        if (count($times) > 1000) {
            $times = array_slice($times, -1000);
        }
        
        $this->cache->set($timeKey, $times, 86400); // 24 hours
        
        // Track memory usage
        $memoryKey = "memory:{$metrics['name']}:$date";
        $memoryUsage = $this->cache->get($memoryKey) ?? [];
        $memoryUsage[] = $metrics['memory_used'];
        
        if (count($memoryUsage) > 1000) {
            $memoryUsage = array_slice($memoryUsage, -1000);
        }
        
        $this->cache->set($memoryKey, $memoryUsage, 86400);
    }
    
    /**
     * Check for performance alerts
     * 
     * @param array $metrics Metrics data
     */
    private function checkPerformanceAlerts(array $metrics): void
    {
        $alerts = [];
        
        // Check execution time
        if ($metrics['execution_time'] > $this->config['alert_thresholds']['execution_time']) {
            $alerts[] = [
                'type' => 'execution_time',
                'message' => "Slow execution: {$metrics['name']} took {$metrics['execution_time']}s",
                'severity' => 'warning'
            ];
        }
        
        // Check memory usage
        $memoryMB = $metrics['memory_used'] / 1024 / 1024;
        if ($memoryMB > $this->config['alert_thresholds']['memory_usage']) {
            $alerts[] = [
                'type' => 'memory_usage',
                'message' => "High memory usage: {$metrics['name']} used " . $this->formatBytes($metrics['memory_used']),
                'severity' => 'warning'
            ];
        }
        
        // Log alerts
        foreach ($alerts as $alert) {
            $this->log("ALERT: {$alert['message']}", strtoupper($alert['severity']));
            $this->recordAlert($alert, $metrics);
        }
    }
    
    /**
     * Record performance alert
     * 
     * @param array $alert Alert data
     * @param array $metrics Related metrics
     */
    private function recordAlert(array $alert, array $metrics): void
    {
        try {
            $sql = "
                INSERT INTO performance_alerts (
                    alert_type, message, severity, metric_name,
                    execution_time, memory_used, created_at
                ) VALUES (?, ?, ?, ?, ?, ?, NOW())
            ";
            
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute([
                $alert['type'],
                $alert['message'],
                $alert['severity'],
                $metrics['name'],
                $metrics['execution_time'],
                $metrics['memory_used']
            ]);
            
        } catch (Exception $e) {
            $this->log("Error recording alert: " . $e->getMessage(), 'ERROR');
        }
    }
    
    /**
     * Get performance statistics
     * 
     * @param string|null $metricName Specific metric name (null for all)
     * @param int $days Number of days to analyze
     * @return array Performance statistics
     */
    public function getPerformanceStatistics(?string $metricName = null, int $days = 7): array
    {
        $stats = [
            'period' => "$days days",
            'generated_at' => date('Y-m-d H:i:s'),
            'metrics' => [],
            'alerts' => [],
            'cache_stats' => null,
            'database_stats' => []
        ];
        
        try {
            // Get metrics from database
            $whereClause = "WHERE created_at >= DATE_SUB(NOW(), INTERVAL ? DAY)";
            $params = [$days];
            
            if ($metricName) {
                $whereClause .= " AND metric_name = ?";
                $params[] = $metricName;
            }
            
            $sql = "
                SELECT 
                    metric_name,
                    COUNT(*) as execution_count,
                    AVG(execution_time) as avg_execution_time,
                    MIN(execution_time) as min_execution_time,
                    MAX(execution_time) as max_execution_time,
                    AVG(memory_used) as avg_memory_used,
                    MAX(memory_used) as max_memory_used,
                    AVG(queries_executed) as avg_queries_executed
                FROM performance_metrics
                $whereClause
                GROUP BY metric_name
                ORDER BY execution_count DESC
            ";
            
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($params);
            
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $stats['metrics'][$row['metric_name']] = [
                    'execution_count' => (int)$row['execution_count'],
                    'avg_execution_time' => round($row['avg_execution_time'], 4),
                    'min_execution_time' => round($row['min_execution_time'], 4),
                    'max_execution_time' => round($row['max_execution_time'], 4),
                    'avg_memory_used' => $this->formatBytes($row['avg_memory_used']),
                    'max_memory_used' => $this->formatBytes($row['max_memory_used']),
                    'avg_queries_executed' => round($row['avg_queries_executed'], 1)
                ];
            }
            
            // Get recent alerts
            $alertSql = "
                SELECT alert_type, message, severity, created_at
                FROM performance_alerts
                WHERE created_at >= DATE_SUB(NOW(), INTERVAL ? DAY)
                ORDER BY created_at DESC
                LIMIT 50
            ";
            
            $stmt = $this->pdo->prepare($alertSql);
            $stmt->execute([$days]);
            $stats['alerts'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Get cache statistics if available
            if ($this->cache) {
                $stats['cache_stats'] = $this->cache->getStatistics();
            }
            
            // Get database statistics
            $stats['database_stats'] = $this->getDatabaseStatistics();
            
        } catch (Exception $e) {
            $this->log("Error getting performance statistics: " . $e->getMessage(), 'ERROR');
        }
        
        return $stats;
    }
    
    /**
     * Get database performance statistics
     * 
     * @return array Database statistics
     */
    private function getDatabaseStatistics(): array
    {
        $stats = [];
        
        try {
            // Get table sizes
            $sql = "
                SELECT 
                    table_name,
                    table_rows,
                    ROUND(((data_length + index_length) / 1024 / 1024), 2) AS size_mb
                FROM information_schema.tables 
                WHERE table_schema = DATABASE()
                    AND table_name IN ('performance_metrics', 'performance_alerts', 'replenishment_recommendations')
                ORDER BY size_mb DESC
            ";
            
            $stmt = $this->pdo->query($sql);
            $stats['table_sizes'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Get slow query information if available
            $slowQuerySql = "SHOW VARIABLES LIKE 'slow_query_log'";
            $stmt = $this->pdo->query($slowQuerySql);
            $slowQueryEnabled = $stmt->fetch(PDO::FETCH_ASSOC);
            
            $stats['slow_query_log_enabled'] = ($slowQueryEnabled['Value'] ?? 'OFF') === 'ON';
            
        } catch (Exception $e) {
            $this->log("Error getting database statistics: " . $e->getMessage(), 'ERROR');
        }
        
        return $stats;
    }
    
    /**
     * Get real-time performance metrics
     * 
     * @return array Real-time metrics
     */
    public function getRealTimeMetrics(): array
    {
        $metrics = [
            'timestamp' => date('Y-m-d H:i:s'),
            'memory_usage' => [
                'current' => memory_get_usage(true),
                'current_formatted' => $this->formatBytes(memory_get_usage(true)),
                'peak' => memory_get_peak_usage(true),
                'peak_formatted' => $this->formatBytes(memory_get_peak_usage(true))
            ],
            'active_timers' => count($this->timers),
            'session_metrics_count' => count($this->metrics),
            'cache_available' => $this->cache !== null && $this->cache->isConnected()
        ];
        
        // Get recent execution times from cache
        if ($this->cache) {
            $recentKey = "metrics:recent:" . date('Y-m-d-H');
            $recentMetrics = $this->cache->get($recentKey) ?? [];
            
            $metrics['recent_executions'] = count($recentMetrics);
            
            if (!empty($recentMetrics)) {
                $executionTimes = array_column($recentMetrics, 'execution_time');
                $metrics['recent_avg_execution_time'] = round(array_sum($executionTimes) / count($executionTimes), 4);
                $metrics['recent_max_execution_time'] = round(max($executionTimes), 4);
            }
        }
        
        return $metrics;
    }
    
    /**
     * Clean up old performance data
     * 
     * @param int|null $retentionDays Number of days to retain (null for config default)
     * @return array Cleanup results
     */
    public function cleanup(?int $retentionDays = null): array
    {
        $retentionDays = $retentionDays ?? $this->config['retention_days'];
        $results = ['metrics_deleted' => 0, 'alerts_deleted' => 0];
        
        try {
            // Clean up old metrics
            $sql = "DELETE FROM performance_metrics WHERE created_at < DATE_SUB(NOW(), INTERVAL ? DAY)";
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute([$retentionDays]);
            $results['metrics_deleted'] = $stmt->rowCount();
            
            // Clean up old alerts
            $sql = "DELETE FROM performance_alerts WHERE created_at < DATE_SUB(NOW(), INTERVAL ? DAY)";
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute([$retentionDays]);
            $results['alerts_deleted'] = $stmt->rowCount();
            
            $this->log("Cleanup completed: {$results['metrics_deleted']} metrics, {$results['alerts_deleted']} alerts deleted");
            
        } catch (Exception $e) {
            $this->log("Error during cleanup: " . $e->getMessage(), 'ERROR');
        }
        
        return $results;
    }
    
    /**
     * Get query count (approximation)
     * 
     * @return int Query count
     */
    private function getQueryCount(): int
    {
        try {
            $stmt = $this->pdo->query("SHOW SESSION STATUS LIKE 'Questions'");
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            return (int)($result['Value'] ?? 0);
        } catch (Exception $e) {
            return 0;
        }
    }
    
    /**
     * Format bytes to human readable format
     * 
     * @param int $bytes Bytes
     * @return string Formatted string
     */
    private function formatBytes(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB'];
        $unitIndex = 0;
        
        while ($bytes >= 1024 && $unitIndex < count($units) - 1) {
            $bytes /= 1024;
            $unitIndex++;
        }
        
        return round($bytes, 2) . ' ' . $units[$unitIndex];
    }
    
    /**
     * Initialize monitoring tables
     */
    private function initializeMonitoringTables(): void
    {
        try {
            // Create performance metrics table
            $sql = "
                CREATE TABLE IF NOT EXISTS performance_metrics (
                    id INT PRIMARY KEY AUTO_INCREMENT,
                    metric_name VARCHAR(100) NOT NULL,
                    execution_time DECIMAL(10,4) NOT NULL,
                    memory_used BIGINT NOT NULL,
                    memory_peak BIGINT NOT NULL,
                    queries_executed INT NOT NULL DEFAULT 0,
                    context_data JSON,
                    additional_data JSON,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    
                    INDEX idx_metric_name (metric_name),
                    INDEX idx_created_at (created_at),
                    INDEX idx_execution_time (execution_time),
                    INDEX idx_metric_date (metric_name, created_at)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
                COMMENT='Performance metrics for replenishment system'
            ";
            
            $this->pdo->exec($sql);
            
            // Create performance alerts table
            $sql = "
                CREATE TABLE IF NOT EXISTS performance_alerts (
                    id INT PRIMARY KEY AUTO_INCREMENT,
                    alert_type VARCHAR(50) NOT NULL,
                    message TEXT NOT NULL,
                    severity ENUM('info', 'warning', 'error', 'critical') NOT NULL DEFAULT 'warning',
                    metric_name VARCHAR(100),
                    execution_time DECIMAL(10,4),
                    memory_used BIGINT,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    
                    INDEX idx_alert_type (alert_type),
                    INDEX idx_severity (severity),
                    INDEX idx_created_at (created_at),
                    INDEX idx_metric_name (metric_name)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
                COMMENT='Performance alerts for replenishment system'
            ";
            
            $this->pdo->exec($sql);
            
        } catch (Exception $e) {
            $this->log("Error initializing monitoring tables: " . $e->getMessage(), 'ERROR');
        }
    }
    
    /**
     * Log debug messages
     * 
     * @param string $message Message to log
     * @param string $level Log level
     */
    private function log(string $message, string $level = 'INFO'): void
    {
        if ($this->config['debug']) {
            echo "[PerformanceMonitor] [$level] " . date('Y-m-d H:i:s') . " - $message\n";
        }
    }
}
?>