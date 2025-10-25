<?php
/**
 * AnalyticsETLMonitor - Comprehensive monitoring service for Analytics ETL processes
 * 
 * Implements monitoring for:
 * - Analytics API request success rates
 * - Data quality metrics and validation
 * - SLA metrics and uptime tracking
 * - Performance monitoring and alerting
 * 
 * Task: 7.1 Создать AnalyticsETLMonitor
 * Requirements: 7.1, 7.2, 7.3, 17.5
 * 
 * @version 1.0
 * @author Warehouse Multi-Source Integration System
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/AlertManager.php';

class AnalyticsETLMonitor {
    private PDO $pdo;
    private array $config;
    private array $metrics = [];
    private array $alerts = [];
    private string $logFile;
    private ?AlertManager $alertManager = null;
    
    // SLA Thresholds
    private const SLA_THRESHOLDS = [
        'api_success_rate_min' => 95.0,           // Minimum API success rate (%)
        'data_quality_score_min' => 90.0,        // Minimum data quality score (%)
        'max_execution_time' => 1800,            // Maximum ETL execution time (seconds)
        'max_hours_since_last_run' => 4,         // Maximum hours since last successful run
        'min_records_per_run' => 50,             // Minimum records processed per run
        'max_consecutive_failures' => 3,         // Maximum consecutive failures
        'uptime_target' => 99.5,                 // Target uptime percentage
        'response_time_max' => 30,               // Maximum API response time (seconds)
        'data_freshness_max_hours' => 6,        // Maximum data age in hours
        'error_rate_max' => 5.0                  // Maximum error rate (%)
    ];
    
    // Monitoring periods
    private const MONITORING_PERIODS = [
        'last_hour' => '1 HOUR',
        'last_24_hours' => '24 HOUR',
        'last_7_days' => '7 DAY',
        'last_30_days' => '30 DAY'
    ];
    
    public function __construct(array $config = []) {
        $this->config = array_merge($this->getDefaultConfig(), $config);
        $this->logFile = $this->config['log_file'];
        
        $this->initializeDatabase();
        $this->initializeAlertManager();
        $this->log('INFO', 'AnalyticsETLMonitor initialized', [
            'thresholds' => self::SLA_THRESHOLDS,
            'config' => $this->config
        ]);
    }
    
    /**
     * Get default configuration
     */
    private function getDefaultConfig(): array {
        return [
            'log_file' => __DIR__ . '/../../logs/analytics_etl/monitor.log',
            'enable_alerts' => true,
            'alert_cooldown_minutes' => 60,
            'detailed_logging' => true,
            'performance_tracking' => true,
            'sla_reporting' => true
        ];
    }
    
    /**
     * Initialize database connection
     */
    private function initializeDatabase(): void {
        try {
            $this->pdo = getDatabaseConnection();
            $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        } catch (Exception $e) {
            throw new Exception('Database connection failed: ' . $e->getMessage());
        }
    }
    
    /**
     * Initialize AlertManager for sending alerts
     */
    private function initializeAlertManager(): void {
        try {
            $alertConfig = [
                'log_file' => dirname($this->logFile) . '/alerts.log',
                'enable_email' => $this->config['enable_alerts'] ?? true,
                'enable_slack' => $this->config['enable_slack_alerts'] ?? false,
                'enable_telegram' => $this->config['enable_telegram_alerts'] ?? false
            ];
            
            $this->alertManager = new AlertManager($alertConfig);
            
        } catch (Exception $e) {
            $this->log('WARNING', 'Failed to initialize AlertManager', [
                'error' => $e->getMessage()
            ]);
            // Continue without AlertManager - monitoring will still work
        }
    }
    
    /**
     * Main monitoring execution - performs comprehensive ETL monitoring
     * 
     * @return array Monitoring results with metrics and alerts
     */
    public function performMonitoring(): array {
        $startTime = microtime(true);
        $this->log('INFO', 'Starting comprehensive ETL monitoring');
        
        try {
            // Reset metrics and alerts for this monitoring cycle
            $this->metrics = [];
            $this->alerts = [];
            
            // 1. Monitor Analytics API request success rates
            $this->monitorApiRequestSuccess();
            
            // 2. Monitor data quality metrics
            $this->monitorDataQuality();
            
            // 3. Monitor SLA metrics and uptime
            $this->monitorSLAMetrics();
            
            // 4. Monitor ETL process performance
            $this->monitorETLPerformance();
            
            // 5. Monitor system health
            $this->monitorSystemHealth();
            
            // 6. Calculate overall health score
            $overallHealth = $this->calculateOverallHealthScore();
            
            $executionTime = round((microtime(true) - $startTime) * 1000, 2);
            
            $result = [
                'status' => empty($this->alerts) ? 'healthy' : 'alerts',
                'timestamp' => date('Y-m-d H:i:s'),
                'execution_time_ms' => $executionTime,
                'overall_health_score' => $overallHealth,
                'metrics' => $this->metrics,
                'alerts' => $this->alerts,
                'sla_compliance' => $this->calculateSLACompliance()
            ];
            
            // Log monitoring results
            $this->logMonitoringResults($result);
            
            $this->log('INFO', 'ETL monitoring completed', [
                'health_score' => $overallHealth,
                'alerts_count' => count($this->alerts),
                'execution_time_ms' => $executionTime
            ]);
            
            return $result;
            
        } catch (Exception $e) {
            $this->log('ERROR', 'Monitoring execution failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            
            return [
                'status' => 'error',
                'timestamp' => date('Y-m-d H:i:s'),
                'error' => $e->getMessage(),
                'metrics' => $this->metrics,
                'alerts' => $this->alerts
            ];
        }
    }
    
    /**
     * Monitor Analytics API request success rates
     */
    private function monitorApiRequestSuccess(): void {
        $this->log('INFO', 'Monitoring API request success rates');
        
        try {
            foreach (self::MONITORING_PERIODS as $period => $interval) {
                $stmt = $this->pdo->prepare("
                    SELECT 
                        COUNT(*) as total_requests,
                        COUNT(CASE WHEN status = 'completed' THEN 1 END) as successful_requests,
                        COUNT(CASE WHEN status = 'failed' THEN 1 END) as failed_requests,
                        AVG(execution_time_ms) as avg_response_time_ms,
                        MAX(execution_time_ms) as max_response_time_ms,
                        MIN(execution_time_ms) as min_response_time_ms
                    FROM analytics_etl_log 
                    WHERE etl_type = 'api_sync' 
                    AND started_at >= NOW() - INTERVAL '{$interval}'
                ");
                
                $stmt->execute();
                $stats = $stmt->fetch(PDO::FETCH_ASSOC);
                
                if ($stats['total_requests'] > 0) {
                    $successRate = ($stats['successful_requests'] / $stats['total_requests']) * 100;
                    $avgResponseTime = $stats['avg_response_time_ms'] / 1000; // Convert to seconds
                    
                    $this->metrics["api_success_rate_{$period}"] = round($successRate, 2);
                    $this->metrics["api_total_requests_{$period}"] = $stats['total_requests'];
                    $this->metrics["api_failed_requests_{$period}"] = $stats['failed_requests'];
                    $this->metrics["api_avg_response_time_{$period}_sec"] = round($avgResponseTime, 2);
                    $this->metrics["api_max_response_time_{$period}_sec"] = round($stats['max_response_time_ms'] / 1000, 2);
                    
                    // Check SLA thresholds
                    if ($successRate < self::SLA_THRESHOLDS['api_success_rate_min']) {
                        $this->addAlert('CRITICAL', "API success rate below threshold for {$period}", [
                            'current_rate' => $successRate,
                            'threshold' => self::SLA_THRESHOLDS['api_success_rate_min'],
                            'period' => $period,
                            'failed_requests' => $stats['failed_requests'],
                            'total_requests' => $stats['total_requests']
                        ]);
                    }
                    
                    if ($avgResponseTime > self::SLA_THRESHOLDS['response_time_max']) {
                        $this->addAlert('WARNING', "API response time above threshold for {$period}", [
                            'current_time' => $avgResponseTime,
                            'threshold' => self::SLA_THRESHOLDS['response_time_max'],
                            'period' => $period
                        ]);
                    }
                } else {
                    $this->metrics["api_success_rate_{$period}"] = 0;
                    $this->metrics["api_total_requests_{$period}"] = 0;
                    
                    if ($period === 'last_24_hours') {
                        $this->addAlert('CRITICAL', "No API requests in the last 24 hours", [
                            'period' => $period
                        ]);
                    }
                }
            }
            
        } catch (Exception $e) {
            $this->log('ERROR', 'Failed to monitor API request success', [
                'error' => $e->getMessage()
            ]);
            $this->addAlert('ERROR', 'Failed to monitor API request success', [
                'error' => $e->getMessage()
            ]);
        }
    }
    
    /**
     * Monitor data quality metrics
     */
    private function monitorDataQuality(): void {
        $this->log('INFO', 'Monitoring data quality metrics');
        
        try {
            // Check recent data quality scores
            $stmt = $this->pdo->prepare("
                SELECT 
                    AVG(data_quality_score) as avg_quality_score,
                    MIN(data_quality_score) as min_quality_score,
                    MAX(data_quality_score) as max_quality_score,
                    COUNT(*) as total_records,
                    COUNT(CASE WHEN data_quality_score < 80 THEN 1 END) as low_quality_records,
                    COUNT(CASE WHEN last_analytics_sync < NOW() - INTERVAL '6 HOUR' THEN 1 END) as stale_records
                FROM inventory 
                WHERE data_source = 'api' 
                AND last_analytics_sync IS NOT NULL
            ");
            
            $stmt->execute();
            $qualityStats = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($qualityStats['total_records'] > 0) {
                $avgQualityScore = $qualityStats['avg_quality_score'];
                $lowQualityPercent = ($qualityStats['low_quality_records'] / $qualityStats['total_records']) * 100;
                $staleDataPercent = ($qualityStats['stale_records'] / $qualityStats['total_records']) * 100;
                
                $this->metrics['data_quality_avg_score'] = round($avgQualityScore, 2);
                $this->metrics['data_quality_min_score'] = $qualityStats['min_quality_score'];
                $this->metrics['data_quality_max_score'] = $qualityStats['max_quality_score'];
                $this->metrics['data_quality_total_records'] = $qualityStats['total_records'];
                $this->metrics['data_quality_low_quality_percent'] = round($lowQualityPercent, 2);
                $this->metrics['data_quality_stale_data_percent'] = round($staleDataPercent, 2);
                
                // Check quality thresholds
                if ($avgQualityScore < self::SLA_THRESHOLDS['data_quality_score_min']) {
                    $this->addAlert('CRITICAL', 'Average data quality score below threshold', [
                        'current_score' => $avgQualityScore,
                        'threshold' => self::SLA_THRESHOLDS['data_quality_score_min'],
                        'low_quality_records' => $qualityStats['low_quality_records'],
                        'total_records' => $qualityStats['total_records']
                    ]);
                }
                
                if ($staleDataPercent > 20) {
                    $this->addAlert('WARNING', 'High percentage of stale data detected', [
                        'stale_percent' => $staleDataPercent,
                        'stale_records' => $qualityStats['stale_records'],
                        'total_records' => $qualityStats['total_records']
                    ]);
                }
            } else {
                $this->addAlert('CRITICAL', 'No data quality records found', [
                    'total_records' => $qualityStats['total_records']
                ]);
            }
            
            // Check warehouse coverage
            $stmt = $this->pdo->query("
                SELECT 
                    COUNT(DISTINCT normalized_warehouse_name) as unique_warehouses,
                    COUNT(*) as total_records
                FROM inventory 
                WHERE data_source = 'api' 
                AND last_analytics_sync >= NOW() - INTERVAL '24 HOUR'
            ");
            
            $coverageStats = $stmt->fetch(PDO::FETCH_ASSOC);
            $this->metrics['warehouse_coverage_unique_count'] = $coverageStats['unique_warehouses'];
            $this->metrics['warehouse_coverage_total_records'] = $coverageStats['total_records'];
            
            if ($coverageStats['unique_warehouses'] < 10) {
                $this->addAlert('WARNING', 'Low warehouse coverage detected', [
                    'unique_warehouses' => $coverageStats['unique_warehouses'],
                    'expected_minimum' => 10
                ]);
            }
            
        } catch (Exception $e) {
            $this->log('ERROR', 'Failed to monitor data quality', [
                'error' => $e->getMessage()
            ]);
            $this->addAlert('ERROR', 'Failed to monitor data quality', [
                'error' => $e->getMessage()
            ]);
        }
    }
    
    /**
     * Monitor SLA metrics and uptime tracking
     */
    private function monitorSLAMetrics(): void {
        $this->log('INFO', 'Monitoring SLA metrics and uptime');
        
        try {
            // Calculate uptime for different periods
            foreach (self::MONITORING_PERIODS as $period => $interval) {
                $stmt = $this->pdo->prepare("
                    SELECT 
                        COUNT(*) as total_runs,
                        COUNT(CASE WHEN status = 'completed' THEN 1 END) as successful_runs,
                        COUNT(CASE WHEN status = 'failed' THEN 1 END) as failed_runs,
                        AVG(execution_time_ms / 1000) as avg_execution_time_sec,
                        MAX(execution_time_ms / 1000) as max_execution_time_sec,
                        SUM(records_processed) as total_records_processed,
                        AVG(records_processed) as avg_records_per_run
                    FROM analytics_etl_log 
                    WHERE started_at >= NOW() - INTERVAL '{$interval}'
                ");
                
                $stmt->execute();
                $slaStats = $stmt->fetch(PDO::FETCH_ASSOC);
                
                if ($slaStats['total_runs'] > 0) {
                    $uptime = ($slaStats['successful_runs'] / $slaStats['total_runs']) * 100;
                    $errorRate = ($slaStats['failed_runs'] / $slaStats['total_runs']) * 100;
                    
                    $this->metrics["sla_uptime_{$period}_percent"] = round($uptime, 2);
                    $this->metrics["sla_error_rate_{$period}_percent"] = round($errorRate, 2);
                    $this->metrics["sla_avg_execution_time_{$period}_sec"] = round($slaStats['avg_execution_time_sec'], 2);
                    $this->metrics["sla_max_execution_time_{$period}_sec"] = round($slaStats['max_execution_time_sec'], 2);
                    $this->metrics["sla_total_records_{$period}"] = $slaStats['total_records_processed'];
                    $this->metrics["sla_avg_records_per_run_{$period}"] = round($slaStats['avg_records_per_run'], 2);
                    
                    // Check SLA compliance
                    if ($uptime < self::SLA_THRESHOLDS['uptime_target']) {
                        $this->addAlert('CRITICAL', "Uptime below SLA target for {$period}", [
                            'current_uptime' => $uptime,
                            'target_uptime' => self::SLA_THRESHOLDS['uptime_target'],
                            'period' => $period,
                            'failed_runs' => $slaStats['failed_runs'],
                            'total_runs' => $slaStats['total_runs']
                        ]);
                    }
                    
                    if ($errorRate > self::SLA_THRESHOLDS['error_rate_max']) {
                        $this->addAlert('WARNING', "Error rate above threshold for {$period}", [
                            'current_error_rate' => $errorRate,
                            'threshold' => self::SLA_THRESHOLDS['error_rate_max'],
                            'period' => $period
                        ]);
                    }
                    
                    if ($slaStats['avg_execution_time_sec'] > self::SLA_THRESHOLDS['max_execution_time']) {
                        $this->addAlert('WARNING', "Average execution time above threshold for {$period}", [
                            'current_time' => $slaStats['avg_execution_time_sec'],
                            'threshold' => self::SLA_THRESHOLDS['max_execution_time'],
                            'period' => $period
                        ]);
                    }
                    
                    if ($slaStats['avg_records_per_run'] < self::SLA_THRESHOLDS['min_records_per_run']) {
                        $this->addAlert('WARNING', "Average records per run below threshold for {$period}", [
                            'current_avg' => $slaStats['avg_records_per_run'],
                            'threshold' => self::SLA_THRESHOLDS['min_records_per_run'],
                            'period' => $period
                        ]);
                    }
                }
            }
            
            // Check time since last successful run
            $stmt = $this->pdo->query("
                SELECT 
                    started_at,
                    EXTRACT(EPOCH FROM (NOW() - started_at)) / 3600 as hours_since_last_run
                FROM analytics_etl_log 
                WHERE status = 'completed' 
                ORDER BY started_at DESC 
                LIMIT 1
            ");
            
            $lastRun = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($lastRun) {
                $hoursSinceLastRun = $lastRun['hours_since_last_run'];
                $this->metrics['hours_since_last_successful_run'] = $hoursSinceLastRun;
                
                if ($hoursSinceLastRun > self::SLA_THRESHOLDS['max_hours_since_last_run']) {
                    $this->addAlert('CRITICAL', 'Too much time since last successful ETL run', [
                        'hours_since_last_run' => $hoursSinceLastRun,
                        'threshold' => self::SLA_THRESHOLDS['max_hours_since_last_run'],
                        'last_run_time' => $lastRun['started_at']
                    ]);
                }
            } else {
                $this->addAlert('CRITICAL', 'No successful ETL runs found in database');
            }
            
        } catch (Exception $e) {
            $this->log('ERROR', 'Failed to monitor SLA metrics', [
                'error' => $e->getMessage()
            ]);
            $this->addAlert('ERROR', 'Failed to monitor SLA metrics', [
                'error' => $e->getMessage()
            ]);
        }
    }
    
    /**
     * Monitor ETL process performance
     */
    private function monitorETLPerformance(): void {
        $this->log('INFO', 'Monitoring ETL process performance');
        
        try {
            // Check for consecutive failures
            $stmt = $this->pdo->prepare("
                SELECT status, started_at 
                FROM analytics_etl_log 
                ORDER BY started_at DESC 
                LIMIT ?
            ");
            
            $stmt->execute([self::SLA_THRESHOLDS['max_consecutive_failures']]);
            $recentRuns = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            $consecutiveFailures = 0;
            foreach ($recentRuns as $run) {
                if ($run['status'] === 'failed') {
                    $consecutiveFailures++;
                } else {
                    break;
                }
            }
            
            $this->metrics['consecutive_failures'] = $consecutiveFailures;
            
            if ($consecutiveFailures >= self::SLA_THRESHOLDS['max_consecutive_failures']) {
                $this->addAlert('CRITICAL', 'Too many consecutive ETL failures', [
                    'consecutive_failures' => $consecutiveFailures,
                    'threshold' => self::SLA_THRESHOLDS['max_consecutive_failures'],
                    'recent_runs' => array_slice($recentRuns, 0, 3)
                ]);
            }
            
            // Check performance trends
            $stmt = $this->pdo->query("
                SELECT 
                    DATE(started_at) as run_date,
                    COUNT(*) as runs_per_day,
                    AVG(execution_time_ms / 1000) as avg_execution_time_sec,
                    AVG(records_processed) as avg_records_processed,
                    COUNT(CASE WHEN status = 'completed' THEN 1 END) as successful_runs
                FROM analytics_etl_log 
                WHERE started_at >= NOW() - INTERVAL '7 DAY'
                GROUP BY DATE(started_at)
                ORDER BY run_date DESC
            ");
            
            $performanceTrends = $stmt->fetchAll(PDO::FETCH_ASSOC);
            $this->metrics['performance_trends_7_days'] = $performanceTrends;
            
            // Analyze trends for performance degradation
            if (count($performanceTrends) >= 3) {
                $recentAvgTime = array_sum(array_column(array_slice($performanceTrends, 0, 3), 'avg_execution_time_sec')) / 3;
                $olderAvgTime = array_sum(array_column(array_slice($performanceTrends, -3), 'avg_execution_time_sec')) / 3;
                
                if ($recentAvgTime > $olderAvgTime * 1.5) { // 50% increase
                    $this->addAlert('WARNING', 'Performance degradation detected', [
                        'recent_avg_time' => round($recentAvgTime, 2),
                        'older_avg_time' => round($olderAvgTime, 2),
                        'degradation_percent' => round((($recentAvgTime - $olderAvgTime) / $olderAvgTime) * 100, 2)
                    ]);
                }
            }
            
        } catch (Exception $e) {
            $this->log('ERROR', 'Failed to monitor ETL performance', [
                'error' => $e->getMessage()
            ]);
            $this->addAlert('ERROR', 'Failed to monitor ETL performance', [
                'error' => $e->getMessage()
            ]);
        }
    }
    
    /**
     * Monitor system health (disk space, memory, etc.)
     */
    private function monitorSystemHealth(): void {
        $this->log('INFO', 'Monitoring system health');
        
        try {
            // Check disk space
            $diskFree = disk_free_space(__DIR__);
            $diskTotal = disk_total_space(__DIR__);
            
            if ($diskFree && $diskTotal) {
                $diskUsagePercent = (($diskTotal - $diskFree) / $diskTotal) * 100;
                $this->metrics['disk_usage_percent'] = round($diskUsagePercent, 2);
                $this->metrics['disk_free_gb'] = round($diskFree / (1024 * 1024 * 1024), 2);
                $this->metrics['disk_total_gb'] = round($diskTotal / (1024 * 1024 * 1024), 2);
                
                if ($diskUsagePercent > 90) {
                    $this->addAlert('CRITICAL', 'Disk usage critically high', [
                        'usage_percent' => $diskUsagePercent,
                        'free_gb' => round($diskFree / (1024 * 1024 * 1024), 2)
                    ]);
                } elseif ($diskUsagePercent > 80) {
                    $this->addAlert('WARNING', 'Disk usage high', [
                        'usage_percent' => $diskUsagePercent,
                        'free_gb' => round($diskFree / (1024 * 1024 * 1024), 2)
                    ]);
                }
            }
            
            // Check memory usage
            $memoryUsage = memory_get_usage(true);
            $memoryPeak = memory_get_peak_usage(true);
            $this->metrics['memory_usage_mb'] = round($memoryUsage / (1024 * 1024), 2);
            $this->metrics['memory_peak_mb'] = round($memoryPeak / (1024 * 1024), 2);
            
            // Check database connection health
            $dbHealthStart = microtime(true);
            $this->pdo->query('SELECT 1');
            $dbResponseTime = (microtime(true) - $dbHealthStart) * 1000;
            
            $this->metrics['database_response_time_ms'] = round($dbResponseTime, 2);
            
            if ($dbResponseTime > 1000) { // 1 second
                $this->addAlert('WARNING', 'Database response time high', [
                    'response_time_ms' => $dbResponseTime
                ]);
            }
            
            // Check log file sizes
            $logDir = dirname($this->logFile);
            if (is_dir($logDir)) {
                $logFiles = glob($logDir . '/*.log');
                $totalLogSize = 0;
                
                foreach ($logFiles as $logFile) {
                    if (file_exists($logFile)) {
                        $totalLogSize += filesize($logFile);
                    }
                }
                
                $totalLogSizeMB = $totalLogSize / (1024 * 1024);
                $this->metrics['total_log_size_mb'] = round($totalLogSizeMB, 2);
                
                if ($totalLogSizeMB > 500) { // 500MB
                    $this->addAlert('WARNING', 'Log files size is large', [
                        'total_size_mb' => $totalLogSizeMB,
                        'log_files_count' => count($logFiles)
                    ]);
                }
            }
            
        } catch (Exception $e) {
            $this->log('ERROR', 'Failed to monitor system health', [
                'error' => $e->getMessage()
            ]);
            $this->addAlert('ERROR', 'Failed to monitor system health', [
                'error' => $e->getMessage()
            ]);
        }
    }
    
    /**
     * Calculate overall health score based on all metrics
     */
    private function calculateOverallHealthScore(): float {
        $scores = [];
        
        // API success rate score (weight: 25%)
        $apiSuccessRate = $this->metrics['api_success_rate_last_24_hours'] ?? 0;
        $scores['api'] = min(100, ($apiSuccessRate / self::SLA_THRESHOLDS['api_success_rate_min']) * 100) * 0.25;
        
        // Data quality score (weight: 25%)
        $dataQualityScore = $this->metrics['data_quality_avg_score'] ?? 0;
        $scores['data_quality'] = min(100, ($dataQualityScore / self::SLA_THRESHOLDS['data_quality_score_min']) * 100) * 0.25;
        
        // Uptime score (weight: 30%)
        $uptime = $this->metrics['sla_uptime_last_24_hours_percent'] ?? 0;
        $scores['uptime'] = min(100, ($uptime / self::SLA_THRESHOLDS['uptime_target']) * 100) * 0.30;
        
        // Performance score (weight: 20%)
        $avgExecutionTime = $this->metrics['sla_avg_execution_time_last_24_hours_sec'] ?? self::SLA_THRESHOLDS['max_execution_time'];
        $performanceScore = max(0, 100 - (($avgExecutionTime / self::SLA_THRESHOLDS['max_execution_time']) * 100));
        $scores['performance'] = $performanceScore * 0.20;
        
        $overallScore = array_sum($scores);
        
        $this->metrics['health_score_breakdown'] = [
            'api_score' => round($scores['api'], 2),
            'data_quality_score' => round($scores['data_quality'], 2),
            'uptime_score' => round($scores['uptime'], 2),
            'performance_score' => round($scores['performance'], 2),
            'overall_score' => round($overallScore, 2)
        ];
        
        return round($overallScore, 2);
    }
    
    /**
     * Calculate SLA compliance metrics
     */
    private function calculateSLACompliance(): array {
        $compliance = [];
        
        // API Success Rate SLA
        $apiSuccessRate = $this->metrics['api_success_rate_last_24_hours'] ?? 0;
        $compliance['api_success_rate'] = [
            'target' => self::SLA_THRESHOLDS['api_success_rate_min'],
            'current' => $apiSuccessRate,
            'compliant' => $apiSuccessRate >= self::SLA_THRESHOLDS['api_success_rate_min']
        ];
        
        // Data Quality SLA
        $dataQualityScore = $this->metrics['data_quality_avg_score'] ?? 0;
        $compliance['data_quality'] = [
            'target' => self::SLA_THRESHOLDS['data_quality_score_min'],
            'current' => $dataQualityScore,
            'compliant' => $dataQualityScore >= self::SLA_THRESHOLDS['data_quality_score_min']
        ];
        
        // Uptime SLA
        $uptime = $this->metrics['sla_uptime_last_24_hours_percent'] ?? 0;
        $compliance['uptime'] = [
            'target' => self::SLA_THRESHOLDS['uptime_target'],
            'current' => $uptime,
            'compliant' => $uptime >= self::SLA_THRESHOLDS['uptime_target']
        ];
        
        // Response Time SLA
        $avgResponseTime = $this->metrics['api_avg_response_time_last_24_hours_sec'] ?? 0;
        $compliance['response_time'] = [
            'target' => self::SLA_THRESHOLDS['response_time_max'],
            'current' => $avgResponseTime,
            'compliant' => $avgResponseTime <= self::SLA_THRESHOLDS['response_time_max']
        ];
        
        // Data Freshness SLA
        $hoursSinceLastRun = $this->metrics['hours_since_last_successful_run'] ?? 999;
        $compliance['data_freshness'] = [
            'target' => self::SLA_THRESHOLDS['max_hours_since_last_run'],
            'current' => $hoursSinceLastRun,
            'compliant' => $hoursSinceLastRun <= self::SLA_THRESHOLDS['max_hours_since_last_run']
        ];
        
        // Calculate overall SLA compliance
        $compliantCount = count(array_filter($compliance, fn($sla) => $sla['compliant']));
        $totalSLAs = count($compliance);
        $overallCompliance = ($compliantCount / $totalSLAs) * 100;
        
        $compliance['overall'] = [
            'compliant_slas' => $compliantCount,
            'total_slas' => $totalSLAs,
            'compliance_percent' => round($overallCompliance, 2)
        ];
        
        return $compliance;
    }
    
    /**
     * Add alert to the alerts array and send via AlertManager
     */
    private function addAlert(string $level, string $message, array $context = []): void {
        $alert = [
            'level' => $level,
            'message' => $message,
            'context' => $context,
            'timestamp' => date('Y-m-d H:i:s'),
            'component' => 'AnalyticsETLMonitor'
        ];
        
        $this->alerts[] = $alert;
        
        $this->log($level, "ALERT: {$message}", $context);
        
        // Send alert via AlertManager if available and level is critical/error
        if ($this->alertManager && in_array($level, ['CRITICAL', 'ERROR'])) {
            try {
                $alertType = $this->determineAlertType($message, $context);
                $this->alertManager->sendAlert(
                    $alertType,
                    $level,
                    $message,
                    $this->formatAlertMessage($message, $context),
                    array_merge($context, [
                        'component' => 'AnalyticsETLMonitor',
                        'timestamp' => $alert['timestamp']
                    ])
                );
            } catch (Exception $e) {
                $this->log('WARNING', 'Failed to send alert via AlertManager', [
                    'error' => $e->getMessage(),
                    'original_alert' => $message
                ]);
            }
        }
    }
    
    /**
     * Determine alert type based on message and context
     */
    private function determineAlertType(string $message, array $context): string {
        if (stripos($message, 'api') !== false || stripos($message, 'request') !== false) {
            return AlertManager::TYPE_API_FAILURE;
        }
        
        if (stripos($message, 'data quality') !== false || stripos($message, 'quality') !== false) {
            return AlertManager::TYPE_DATA_QUALITY;
        }
        
        if (stripos($message, 'etl') !== false || stripos($message, 'process') !== false) {
            return AlertManager::TYPE_ETL_FAILURE;
        }
        
        if (stripos($message, 'sla') !== false || stripos($message, 'uptime') !== false) {
            return AlertManager::TYPE_SLA_BREACH;
        }
        
        return AlertManager::TYPE_SYSTEM_HEALTH;
    }
    
    /**
     * Format alert message for AlertManager
     */
    private function formatAlertMessage(string $message, array $context): string {
        $formatted = "Analytics ETL Monitor Alert: {$message}";
        
        if (!empty($context)) {
            $formatted .= "\n\nAdditional Details:\n";
            foreach ($context as $key => $value) {
                if (is_scalar($value)) {
                    $formatted .= "• {$key}: {$value}\n";
                }
            }
        }
        
        return $formatted;
    }
    
    /**
     * Log monitoring results to database
     */
    private function logMonitoringResults(array $result): void {
        try {
            $stmt = $this->pdo->prepare("
                INSERT INTO analytics_etl_log (
                    batch_id, etl_type, status, started_at, completed_at,
                    execution_time_ms, records_processed, error_message, results
                ) VALUES (?, ?, ?, NOW(), NOW(), ?, ?, ?, ?)
            ");
            
            $batchId = 'monitor_' . date('Ymd_His') . '_' . substr(md5(uniqid()), 0, 8);
            $status = $result['status'] === 'healthy' ? 'completed' : 'completed_with_alerts';
            $errorMessage = !empty($result['alerts']) ? 'Alerts generated: ' . count($result['alerts']) : null;
            
            $stmt->execute([
                $batchId,
                'monitoring',
                $status,
                $result['execution_time_ms'],
                count($result['alerts']), // Use alert count as records processed
                $errorMessage,
                json_encode($result, JSON_UNESCAPED_UNICODE)
            ]);
            
        } catch (Exception $e) {
            $this->log('ERROR', 'Failed to log monitoring results to database', [
                'error' => $e->getMessage()
            ]);
        }
    }
    
    /**
     * Get monitoring metrics for external access
     */
    public function getMetrics(): array {
        return $this->metrics;
    }
    
    /**
     * Get alerts for external access
     */
    public function getAlerts(): array {
        return $this->alerts;
    }
    
    /**
     * Get SLA thresholds
     */
    public function getSLAThresholds(): array {
        return self::SLA_THRESHOLDS;
    }
    
    /**
     * Send daily summary report via AlertManager
     */
    public function sendDailySummaryReport(): bool {
        if (!$this->alertManager) {
            $this->log('WARNING', 'AlertManager not available for daily summary');
            return false;
        }
        
        try {
            return $this->alertManager->sendDailySummaryReport();
        } catch (Exception $e) {
            $this->log('ERROR', 'Failed to send daily summary report', [
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }
    
    /**
     * Get AlertManager instance for external access
     */
    public function getAlertManager(): ?AlertManager {
        return $this->alertManager;
    }
    
    /**
     * Log message to file
     */
    private function log(string $level, string $message, array $context = []): void {
        if (!$this->config['detailed_logging'] && $level === 'INFO') {
            return;
        }
        
        $timestamp = date('Y-m-d H:i:s');
        $contextStr = !empty($context) ? ' | ' . json_encode($context, JSON_UNESCAPED_UNICODE) : '';
        $logLine = "[$timestamp] [$level] [AnalyticsETLMonitor] $message$contextStr\n";
        
        // Ensure log directory exists
        $logDir = dirname($this->logFile);
        if (!is_dir($logDir)) {
            mkdir($logDir, 0755, true);
        }
        
        // Write to log file
        file_put_contents($this->logFile, $logLine, FILE_APPEND | LOCK_EX);
        
        // Also output to console if running in CLI
        if (php_sapi_name() === 'cli') {
            echo $logLine;
        }
    }
}