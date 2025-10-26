<?php

/**
 * Production Monitoring Script for Ozon ETL System
 * 
 * This script monitors the health and performance of the ETL system
 * in production environment and sends alerts when issues are detected.
 * 
 * Requirements: 5.1, 5.2, 5.3, 4.4
 */

require_once __DIR__ . '/../autoload.php';

use MiCore\ETL\Ozon\Core\DatabaseConnection;
use MiCore\ETL\Ozon\Core\SimpleLogger;

class ProductionMonitor
{
    private DatabaseConnection $db;
    private SimpleLogger $logger;
    private array $config;
    private array $alerts = [];

    public function __construct()
    {
        // Load configuration
        $this->config = require __DIR__ . '/../Config/bootstrap.php';
        
        $this->logger = new SimpleLogger('ProductionMonitor');
        $this->db = new DatabaseConnection($this->config['database']['connection']);
        
        $this->logger->info('Production Monitor initialized');
    }

    /**
     * Run comprehensive production monitoring
     */
    public function runMonitoring(): array
    {
        $this->logger->info('Starting production monitoring check');
        
        $monitoringResults = [
            'timestamp' => date('Y-m-d H:i:s'),
            'status' => 'HEALTHY',
            'checks' => []
        ];
        
        try {
            // System health checks
            $monitoringResults['checks']['database_health'] = $this->checkDatabaseHealth();
            $monitoringResults['checks']['etl_performance'] = $this->checkETLPerformance();
            $monitoringResults['checks']['data_freshness'] = $this->checkDataFreshness();
            $monitoringResults['checks']['system_resources'] = $this->checkSystemResources();
            $monitoringResults['checks']['error_rates'] = $this->checkErrorRates();
            $monitoringResults['checks']['data_quality'] = $this->checkDataQuality();
            
            // Determine overall status
            $monitoringResults['status'] = $this->determineOverallStatus($monitoringResults['checks']);
            
            // Generate alerts if needed
            $this->generateAlerts($monitoringResults);
            
            // Log monitoring results
            $this->logMonitoringResults($monitoringResults);
            
        } catch (Exception $e) {
            $monitoringResults['status'] = 'CRITICAL';
            $monitoringResults['error'] = $e->getMessage();
            $this->logger->error('Production monitoring failed', [
                'error' => $e->getMessage()
            ]);
        }
        
        return $monitoringResults;
    }

    /**
     * Check database health and connectivity
     */
    private function checkDatabaseHealth(): array
    {
        $this->logger->info('Checking database health');
        
        $result = [
            'status' => 'HEALTHY',
            'metrics' => []
        ];
        
        try {
            // Test basic connectivity
            $startTime = microtime(true);
            $this->db->query("SELECT 1");
            $connectionTime = microtime(true) - $startTime;
            
            $result['metrics']['connection_time_ms'] = round($connectionTime * 1000, 2);
            
            // Check database size
            $dbSize = $this->db->query("
                SELECT pg_size_pretty(pg_database_size(current_database())) as size,
                       pg_database_size(current_database()) as size_bytes
            ");
            
            $result['metrics']['database_size'] = $dbSize[0]['size'];
            $result['metrics']['database_size_bytes'] = $dbSize[0]['size_bytes'];
            
            // Check active connections
            $connections = $this->db->query("
                SELECT count(*) as active_connections
                FROM pg_stat_activity
                WHERE state = 'active'
            ");
            
            $result['metrics']['active_connections'] = $connections[0]['active_connections'];
            
            // Check table sizes
            $tableSizes = $this->db->query("
                SELECT 
                    schemaname,
                    tablename,
                    pg_size_pretty(pg_total_relation_size(schemaname||'.'||tablename)) as size,
                    pg_total_relation_size(schemaname||'.'||tablename) as size_bytes
                FROM pg_tables 
                WHERE schemaname = 'public' 
                    AND tablename IN ('dim_products', 'fact_orders', 'inventory', 'etl_execution_log')
                ORDER BY pg_total_relation_size(schemaname||'.'||tablename) DESC
            ");
            
            $result['metrics']['table_sizes'] = $tableSizes;
            
            // Performance thresholds
            if ($connectionTime > 1.0) {
                $result['status'] = 'WARNING';
                $result['issues'][] = 'Slow database connection time: ' . round($connectionTime, 2) . 's';
            }
            
            if ($connections[0]['active_connections'] > 50) {
                $result['status'] = 'WARNING';
                $result['issues'][] = 'High number of active connections: ' . $connections[0]['active_connections'];
            }
            
        } catch (Exception $e) {
            $result['status'] = 'CRITICAL';
            $result['error'] = $e->getMessage();
        }
        
        return $result;
    }

    /**
     * Check ETL performance metrics
     */
    private function checkETLPerformance(): array
    {
        $this->logger->info('Checking ETL performance');
        
        $result = [
            'status' => 'HEALTHY',
            'metrics' => []
        ];
        
        try {
            // Check recent ETL executions
            $recentExecutions = $this->db->query("
                SELECT 
                    etl_class,
                    COUNT(*) as execution_count,
                    AVG(duration_seconds) as avg_duration,
                    MAX(duration_seconds) as max_duration,
                    MIN(duration_seconds) as min_duration,
                    SUM(CASE WHEN status = 'success' THEN 1 ELSE 0 END) as success_count,
                    SUM(CASE WHEN status = 'error' THEN 1 ELSE 0 END) as error_count,
                    MAX(started_at) as last_execution
                FROM etl_execution_log
                WHERE started_at >= NOW() - INTERVAL '24 hours'
                GROUP BY etl_class
                ORDER BY last_execution DESC
            ");
            
            $result['metrics']['recent_executions'] = $recentExecutions;
            
            // Performance thresholds
            $performanceThresholds = [
                'ProductETL' => 1800,    // 30 minutes
                'SalesETL' => 3600,      // 60 minutes
                'InventoryETL' => 1800   // 30 minutes
            ];
            
            foreach ($recentExecutions as $execution) {
                $threshold = $performanceThresholds[$execution['etl_class']] ?? 1800;
                
                // Check execution time
                if ($execution['max_duration'] > $threshold) {
                    $result['status'] = 'WARNING';
                    $result['issues'][] = "{$execution['etl_class']} exceeded time threshold: {$execution['max_duration']}s > {$threshold}s";
                }
                
                // Check error rate
                $errorRate = $execution['execution_count'] > 0 ? 
                    ($execution['error_count'] / $execution['execution_count']) * 100 : 0;
                
                if ($errorRate > 10) {
                    $result['status'] = 'WARNING';
                    $result['issues'][] = "{$execution['etl_class']} high error rate: {$errorRate}%";
                }
                
                // Check if ETL hasn't run recently
                $lastExecution = strtotime($execution['last_execution']);
                $hoursAgo = (time() - $lastExecution) / 3600;
                
                if ($hoursAgo > 6) {
                    $result['status'] = 'WARNING';
                    $result['issues'][] = "{$execution['etl_class']} hasn't run for {$hoursAgo} hours";
                }
            }
            
        } catch (Exception $e) {
            $result['status'] = 'CRITICAL';
            $result['error'] = $e->getMessage();
        }
        
        return $result;
    }

    /**
     * Check data freshness
     */
    private function checkDataFreshness(): array
    {
        $this->logger->info('Checking data freshness');
        
        $result = [
            'status' => 'HEALTHY',
            'metrics' => []
        ];
        
        try {
            // Check when each table was last updated
            $dataFreshness = $this->db->query("
                SELECT 
                    'dim_products' as table_name,
                    COUNT(*) as record_count,
                    MAX(updated_at) as last_update,
                    EXTRACT(EPOCH FROM (NOW() - MAX(updated_at)))/3600 as hours_since_update
                FROM dim_products
                WHERE updated_at IS NOT NULL
                
                UNION ALL
                
                SELECT 
                    'inventory' as table_name,
                    COUNT(*) as record_count,
                    MAX(updated_at) as last_update,
                    EXTRACT(EPOCH FROM (NOW() - MAX(updated_at)))/3600 as hours_since_update
                FROM inventory
                WHERE updated_at IS NOT NULL
                
                UNION ALL
                
                SELECT 
                    'fact_orders' as table_name,
                    COUNT(*) as record_count,
                    MAX(created_at) as last_update,
                    EXTRACT(EPOCH FROM (NOW() - MAX(created_at)))/3600 as hours_since_update
                FROM fact_orders
                WHERE created_at IS NOT NULL
            ");
            
            $result['metrics']['data_freshness'] = $dataFreshness;
            
            // Check freshness thresholds
            foreach ($dataFreshness as $table) {
                $hoursThreshold = 24; // 24 hours
                
                if ($table['hours_since_update'] > $hoursThreshold) {
                    $result['status'] = 'WARNING';
                    $result['issues'][] = "Stale data in {$table['table_name']}: {$table['hours_since_update']} hours old";
                }
            }
            
        } catch (Exception $e) {
            $result['status'] = 'CRITICAL';
            $result['error'] = $e->getMessage();
        }
        
        return $result;
    }

    /**
     * Check system resources
     */
    private function checkSystemResources(): array
    {
        $this->logger->info('Checking system resources');
        
        $result = [
            'status' => 'HEALTHY',
            'metrics' => []
        ];
        
        try {
            // Memory usage
            $memoryUsage = memory_get_usage(true);
            $memoryPeak = memory_get_peak_usage(true);
            
            $result['metrics']['memory_usage_mb'] = round($memoryUsage / 1024 / 1024, 2);
            $result['metrics']['memory_peak_mb'] = round($memoryPeak / 1024 / 1024, 2);
            
            // Disk space (if possible)
            $logDir = $this->config['etl']['logging']['path'] ?? __DIR__ . '/../Logs';
            if (is_dir($logDir)) {
                $diskFree = disk_free_space($logDir);
                $diskTotal = disk_total_space($logDir);
                $diskUsedPercent = (($diskTotal - $diskFree) / $diskTotal) * 100;
                
                $result['metrics']['disk_free_gb'] = round($diskFree / 1024 / 1024 / 1024, 2);
                $result['metrics']['disk_used_percent'] = round($diskUsedPercent, 2);
                
                if ($diskUsedPercent > 90) {
                    $result['status'] = 'CRITICAL';
                    $result['issues'][] = "Disk space critically low: {$diskUsedPercent}% used";
                } elseif ($diskUsedPercent > 80) {
                    $result['status'] = 'WARNING';
                    $result['issues'][] = "Disk space running low: {$diskUsedPercent}% used";
                }
            }
            
            // Log file sizes
            $logFiles = glob($logDir . '/*.log');
            $totalLogSize = 0;
            foreach ($logFiles as $logFile) {
                $totalLogSize += filesize($logFile);
            }
            
            $result['metrics']['log_files_count'] = count($logFiles);
            $result['metrics']['total_log_size_mb'] = round($totalLogSize / 1024 / 1024, 2);
            
        } catch (Exception $e) {
            $result['status'] = 'WARNING';
            $result['error'] = $e->getMessage();
        }
        
        return $result;
    }

    /**
     * Check error rates
     */
    private function checkErrorRates(): array
    {
        $this->logger->info('Checking error rates');
        
        $result = [
            'status' => 'HEALTHY',
            'metrics' => []
        ];
        
        try {
            // Check ETL error rates
            $errorStats = $this->db->query("
                SELECT 
                    etl_class,
                    COUNT(*) as total_executions,
                    SUM(CASE WHEN status = 'error' THEN 1 ELSE 0 END) as error_count,
                    ROUND(
                        (SUM(CASE WHEN status = 'error' THEN 1 ELSE 0 END)::float / COUNT(*)) * 100, 
                        2
                    ) as error_rate_percent
                FROM etl_execution_log
                WHERE started_at >= NOW() - INTERVAL '7 days'
                GROUP BY etl_class
                HAVING COUNT(*) > 0
            ");
            
            $result['metrics']['error_stats'] = $errorStats;
            
            // Check for high error rates
            foreach ($errorStats as $stat) {
                if ($stat['error_rate_percent'] > 20) {
                    $result['status'] = 'CRITICAL';
                    $result['issues'][] = "High error rate for {$stat['etl_class']}: {$stat['error_rate_percent']}%";
                } elseif ($stat['error_rate_percent'] > 10) {
                    $result['status'] = 'WARNING';
                    $result['issues'][] = "Elevated error rate for {$stat['etl_class']}: {$stat['error_rate_percent']}%";
                }
            }
            
            // Check recent errors
            $recentErrors = $this->db->query("
                SELECT etl_class, error_message, started_at
                FROM etl_execution_log
                WHERE status = 'error' 
                    AND started_at >= NOW() - INTERVAL '1 hour'
                ORDER BY started_at DESC
                LIMIT 10
            ");
            
            $result['metrics']['recent_errors'] = $recentErrors;
            
        } catch (Exception $e) {
            $result['status'] = 'CRITICAL';
            $result['error'] = $e->getMessage();
        }
        
        return $result;
    }

    /**
     * Check data quality metrics
     */
    private function checkDataQuality(): array
    {
        $this->logger->info('Checking data quality');
        
        $result = [
            'status' => 'HEALTHY',
            'metrics' => []
        ];
        
        try {
            // Check for data anomalies
            $qualityChecks = [];
            
            // Check for products without names
            $productsWithoutNames = $this->db->query("
                SELECT COUNT(*) as count
                FROM dim_products
                WHERE product_name IS NULL OR product_name = ''
            ");
            $qualityChecks['products_without_names'] = $productsWithoutNames[0]['count'];
            
            // Check for orders with zero quantities
            $ordersWithZeroQty = $this->db->query("
                SELECT COUNT(*) as count
                FROM fact_orders
                WHERE qty <= 0
            ");
            $qualityChecks['orders_with_zero_qty'] = $ordersWithZeroQty[0]['count'];
            
            // Check for negative inventory
            $negativeInventory = $this->db->query("
                SELECT COUNT(*) as count
                FROM inventory
                WHERE quantity_present < 0 OR quantity_reserved < 0
            ");
            $qualityChecks['negative_inventory'] = $negativeInventory[0]['count'];
            
            $result['metrics']['quality_checks'] = $qualityChecks;
            
            // Set warnings for data quality issues
            if ($qualityChecks['products_without_names'] > 0) {
                $result['status'] = 'WARNING';
                $result['issues'][] = "Found {$qualityChecks['products_without_names']} products without names";
            }
            
            if ($qualityChecks['orders_with_zero_qty'] > 0) {
                $result['status'] = 'WARNING';
                $result['issues'][] = "Found {$qualityChecks['orders_with_zero_qty']} orders with zero quantity";
            }
            
            if ($qualityChecks['negative_inventory'] > 0) {
                $result['status'] = 'WARNING';
                $result['issues'][] = "Found {$qualityChecks['negative_inventory']} negative inventory records";
            }
            
        } catch (Exception $e) {
            $result['status'] = 'WARNING';
            $result['error'] = $e->getMessage();
        }
        
        return $result;
    }

    /**
     * Determine overall system status
     */
    private function determineOverallStatus(array $checks): string
    {
        $criticalCount = 0;
        $warningCount = 0;
        
        foreach ($checks as $check) {
            if ($check['status'] === 'CRITICAL') {
                $criticalCount++;
            } elseif ($check['status'] === 'WARNING') {
                $warningCount++;
            }
        }
        
        if ($criticalCount > 0) {
            return 'CRITICAL';
        } elseif ($warningCount > 0) {
            return 'WARNING';
        } else {
            return 'HEALTHY';
        }
    }

    /**
     * Generate alerts based on monitoring results
     */
    private function generateAlerts(array $results): void
    {
        if ($results['status'] === 'CRITICAL') {
            $this->sendAlert('CRITICAL', 'Ozon ETL System Critical Alert', $results);
        } elseif ($results['status'] === 'WARNING') {
            $this->sendAlert('WARNING', 'Ozon ETL System Warning', $results);
        }
    }

    /**
     * Send alert notification
     */
    private function sendAlert(string $level, string $subject, array $data): void
    {
        $this->logger->warning("Alert generated: $level - $subject");
        
        // Here you would implement actual alerting mechanisms:
        // - Email notifications
        // - Slack webhooks
        // - SMS alerts
        // - Monitoring system integration
        
        // For now, just log the alert
        $alertData = [
            'level' => $level,
            'subject' => $subject,
            'timestamp' => date('Y-m-d H:i:s'),
            'data' => $data
        ];
        
        $this->alerts[] = $alertData;
    }

    /**
     * Log monitoring results
     */
    private function logMonitoringResults(array $results): void
    {
        // Log to ETL execution log
        try {
            $this->db->query("
                INSERT INTO etl_execution_log (
                    etl_class, status, records_processed, duration_seconds, 
                    started_at, completed_at, error_message
                ) VALUES (?, ?, ?, ?, ?, ?, ?)
            ", [
                'ProductionMonitor',
                $results['status'] === 'HEALTHY' ? 'success' : 'warning',
                count($results['checks']),
                0,
                $results['timestamp'],
                $results['timestamp'],
                isset($results['error']) ? $results['error'] : null
            ]);
        } catch (Exception $e) {
            $this->logger->error('Failed to log monitoring results', [
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * Get monitoring summary for dashboard
     */
    public function getMonitoringSummary(): array
    {
        $results = $this->runMonitoring();
        
        return [
            'status' => $results['status'],
            'timestamp' => $results['timestamp'],
            'summary' => [
                'database_status' => $results['checks']['database_health']['status'],
                'etl_status' => $results['checks']['etl_performance']['status'],
                'data_freshness' => $results['checks']['data_freshness']['status'],
                'system_resources' => $results['checks']['system_resources']['status']
            ],
            'alerts_count' => count($this->alerts)
        ];
    }
}

// Execute monitoring if run directly
if (basename(__FILE__) === basename($_SERVER['SCRIPT_NAME'])) {
    echo "Starting Production Monitoring for Ozon ETL System...\n\n";
    
    try {
        $monitor = new ProductionMonitor();
        $results = $monitor->runMonitoring();
        
        echo "Monitoring Results:\n";
        echo "==================\n";
        echo "Status: " . $results['status'] . "\n";
        echo "Timestamp: " . $results['timestamp'] . "\n\n";
        
        foreach ($results['checks'] as $checkName => $checkResult) {
            $status = $checkResult['status'] === 'HEALTHY' ? '✅' : 
                     ($checkResult['status'] === 'WARNING' ? '⚠️' : '❌');
            echo "{$status} {$checkName}: {$checkResult['status']}\n";
            
            if (isset($checkResult['issues'])) {
                foreach ($checkResult['issues'] as $issue) {
                    echo "   - {$issue}\n";
                }
            }
        }
        
        echo "\nMonitoring completed successfully.\n";
        
        if ($results['status'] === 'HEALTHY') {
            exit(0);
        } else {
            exit(1);
        }
        
    } catch (Exception $e) {
        echo "❌ Monitoring failed: " . $e->getMessage() . "\n";
        exit(1);
    }
}