<?php

namespace MDM\Services;

/**
 * Performance Monitoring Service for MDM System
 * Tracks system performance metrics and processing times
 */
class PerformanceMonitoringService
{
    private \PDO $pdo;
    private array $performanceLog = [];

    public function __construct()
    {
        $this->pdo = $this->getDatabaseConnection();
    }

    /**
     * Start performance tracking for an operation
     */
    public function startTracking(string $operationName): string
    {
        $trackingId = uniqid('perf_', true);
        $this->performanceLog[$trackingId] = [
            'operation' => $operationName,
            'start_time' => microtime(true),
            'start_memory' => memory_get_usage(true)
        ];
        
        return $trackingId;
    }

    /**
     * End performance tracking and log results
     */
    public function endTracking(string $trackingId, array $additionalData = []): array
    {
        if (!isset($this->performanceLog[$trackingId])) {
            throw new \InvalidArgumentException("Tracking ID not found: {$trackingId}");
        }

        $log = $this->performanceLog[$trackingId];
        $endTime = microtime(true);
        $endMemory = memory_get_usage(true);

        $metrics = [
            'operation' => $log['operation'],
            'execution_time' => round(($endTime - $log['start_time']) * 1000, 2), // milliseconds
            'memory_usage' => $endMemory - $log['start_memory'],
            'peak_memory' => memory_get_peak_usage(true),
            'timestamp' => date('Y-m-d H:i:s'),
            'additional_data' => $additionalData
        ];

        // Log to database
        $this->logPerformanceMetric($metrics);

        // Clean up
        unset($this->performanceLog[$trackingId]);

        return $metrics;
    }

    /**
     * Get system performance summary
     */
    public function getPerformanceSummary(int $hours = 24): array
    {
        $sql = "
            SELECT 
                operation_name,
                COUNT(*) as operation_count,
                AVG(execution_time_ms) as avg_execution_time,
                MIN(execution_time_ms) as min_execution_time,
                MAX(execution_time_ms) as max_execution_time,
                AVG(memory_usage_bytes) as avg_memory_usage,
                MAX(memory_usage_bytes) as peak_memory_usage
            FROM performance_metrics 
            WHERE created_at >= DATE_SUB(NOW(), INTERVAL ? HOUR)
            GROUP BY operation_name
            ORDER BY avg_execution_time DESC
        ";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([$hours]);
        
        return $stmt->fetchAll();
    }

    /**
     * Get performance trends over time
     */
    public function getPerformanceTrends(string $operation = null, int $days = 7): array
    {
        $whereClause = $operation ? "AND operation_name = ?" : "";
        $params = $operation ? [$days, $operation] : [$days];

        $sql = "
            SELECT 
                DATE(created_at) as date,
                HOUR(created_at) as hour,
                operation_name,
                COUNT(*) as operation_count,
                AVG(execution_time_ms) as avg_execution_time,
                AVG(memory_usage_bytes) as avg_memory_usage
            FROM performance_metrics 
            WHERE created_at >= DATE_SUB(NOW(), INTERVAL ? DAY)
            {$whereClause}
            GROUP BY DATE(created_at), HOUR(created_at), operation_name
            ORDER BY date DESC, hour DESC
        ";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        
        return $stmt->fetchAll();
    }

    /**
     * Get slow operations (above threshold)
     */
    public function getSlowOperations(float $thresholdMs = 1000, int $hours = 24): array
    {
        $sql = "
            SELECT 
                operation_name,
                execution_time_ms,
                memory_usage_bytes,
                additional_data,
                created_at
            FROM performance_metrics 
            WHERE execution_time_ms > ?
              AND created_at >= DATE_SUB(NOW(), INTERVAL ? HOUR)
            ORDER BY execution_time_ms DESC
            LIMIT 100
        ";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([$thresholdMs, $hours]);
        
        return $stmt->fetchAll();
    }

    /**
     * Get memory usage statistics
     */
    public function getMemoryUsageStats(int $hours = 24): array
    {
        $sql = "
            SELECT 
                AVG(memory_usage_bytes) as avg_memory,
                MIN(memory_usage_bytes) as min_memory,
                MAX(memory_usage_bytes) as max_memory,
                AVG(peak_memory_bytes) as avg_peak_memory,
                MAX(peak_memory_bytes) as max_peak_memory,
                COUNT(*) as total_operations
            FROM performance_metrics 
            WHERE created_at >= DATE_SUB(NOW(), INTERVAL ? HOUR)
        ";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([$hours]);
        
        $result = $stmt->fetch();
        
        return [
            'avg_memory_mb' => round($result['avg_memory'] / 1024 / 1024, 2),
            'min_memory_mb' => round($result['min_memory'] / 1024 / 1024, 2),
            'max_memory_mb' => round($result['max_memory'] / 1024 / 1024, 2),
            'avg_peak_memory_mb' => round($result['avg_peak_memory'] / 1024 / 1024, 2),
            'max_peak_memory_mb' => round($result['max_peak_memory'] / 1024 / 1024, 2),
            'total_operations' => $result['total_operations']
        ];
    }

    /**
     * Log performance metric to database
     */
    private function logPerformanceMetric(array $metrics): void
    {
        $sql = "
            INSERT INTO performance_metrics (
                operation_name, 
                execution_time_ms, 
                memory_usage_bytes, 
                peak_memory_bytes,
                additional_data
            ) VALUES (?, ?, ?, ?, ?)
        ";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            $metrics['operation'],
            $metrics['execution_time'],
            $metrics['memory_usage'],
            $metrics['peak_memory'],
            json_encode($metrics['additional_data'])
        ]);
    }

    /**
     * Clean old performance metrics
     */
    public function cleanOldMetrics(int $daysToKeep = 30): int
    {
        $sql = "
            DELETE FROM performance_metrics 
            WHERE created_at < DATE_SUB(NOW(), INTERVAL ? DAY)
        ";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([$daysToKeep]);
        
        return $stmt->rowCount();
    }

    /**
     * Get database connection
     */
    private function getDatabaseConnection(): \PDO
    {
        $config = require __DIR__ . '/../../config/database.php';
        
        $dsn = "mysql:host={$config['host']};dbname={$config['database']};charset=utf8mb4";
        return new \PDO($dsn, $config['username'], $config['password'], [
            \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
            \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC
        ]);
    }
}