<?php

namespace Replenishment;

use PDO;
use Exception;
use DateTime;
use DateInterval;

/**
 * ReplenishmentMonitor Class
 * 
 * Provides system health monitoring, performance metrics collection,
 * and alerting for the replenishment system.
 */
class ReplenishmentMonitor
{
    private PDO $pdo;
    private array $config;
    private array $metrics = [];
    
    public function __construct(PDO $pdo, array $config = [])
    {
        $this->pdo = $pdo;
        $this->config = array_merge([
            'monitoring_enabled' => true,
            'alert_enabled' => true,
            'performance_tracking' => true,
            'health_check_interval' => 300,     // 5 minutes
            'alert_cooldown' => 3600,           // 1 hour between same alerts
            'notification_email' => '',
            'critical_thresholds' => [
                'execution_time' => 1800,       // 30 minutes
                'memory_usage' => 512,          // 512 MB
                'error_rate' => 0.1,            // 10% error rate
                'data_freshness' => 86400       // 24 hours
            ],
            'warning_thresholds' => [
                'execution_time' => 900,        // 15 minutes
                'memory_usage' => 256,          // 256 MB
                'error_rate' => 0.05,           // 5% error rate
                'data_freshness' => 43200       // 12 hours
            ],
            'metrics_retention_days' => 90,
            'debug' => false
        ], $config);
        
        $this->initializeMonitoringTables();
    }
    
    /**
     * Perform comprehensive system health check
     * 
     * @return array Health check results
     */
    public function performHealthCheck(): array
    {
        $this->log("Starting system health check");
        
        $healthResults = [
            'timestamp' => date('Y-m-d H:i:s'),
            'overall_status' => 'healthy',
            'checks' => [],
            'alerts' => [],
            'metrics' => []
        ];
        
        try {
            // Database connectivity check
            $healthResults['checks']['database'] = $this->checkDatabaseHealth();
            
            // Data freshness check
            $healthResults['checks']['data_freshness'] = $this->checkDataFreshness();
            
            // Execution performance check
            $healthResults['checks']['execution_performance'] = $this->checkExecutionPerformance();
            
            // Error rate check
            $healthResults['checks']['error_rate'] = $this->checkErrorRate();
            
            // System resources check
            $healthResults['checks']['system_resources'] = $this->checkSystemResources();
            
            // Configuration validation check
            $healthResults['checks']['configuration'] = $this->checkConfiguration();
            
            // Determine overall status
            $healthResults['overall_status'] = $this->determineOverallStatus($healthResults['checks']);
            
            // Collect performance metrics
            $healthResults['metrics'] = $this->collectPerformanceMetrics();
            
            // Generate alerts if needed
            $healthResults['alerts'] = $this->generateAlerts($healthResults);
            
            // Save health check results
            $this->saveHealthCheckResults($healthResults);
            
            $this->log("Health check completed", [
                'status' => $healthResults['overall_status'],
                'alerts_count' => count($healthResults['alerts'])
            ]);
            
            return $healthResults;
            
        } catch (Exception $e) {
            $this->log("Health check failed: " . $e->getMessage());
            
            $healthResults['overall_status'] = 'critical';
            $healthResults['checks']['health_check_error'] = [
                'status' => 'critical',
                'message' => 'Health check system failure: ' . $e->getMessage(),
                'timestamp' => date('Y-m-d H:i:s')
            ];
            
            return $healthResults;
        }
    }
    
    /**
     * Check database health
     * 
     * @return array Database health status
     */
    private function checkDatabaseHealth(): array
    {
        try {
            $startTime = microtime(true);
            
            // Test basic connectivity
            $stmt = $this->pdo->query("SELECT 1");
            $stmt->fetch();
            
            // Check required tables exist
            $requiredTables = [
                'replenishment_recommendations',
                'replenishment_config',
                'replenishment_calculations',
                'replenishment_scheduler_log'
            ];
            
            $missingTables = [];
            
            foreach ($requiredTables as $table) {
                $stmt = $this->pdo->prepare("SHOW TABLES LIKE ?");
                $stmt->execute([$table]);
                
                if (!$stmt->fetch()) {
                    $missingTables[] = $table;
                }
            }
            
            $responseTime = round((microtime(true) - $startTime) * 1000, 2);
            
            if (!empty($missingTables)) {
                return [
                    'status' => 'critical',
                    'message' => 'Missing required tables: ' . implode(', ', $missingTables),
                    'response_time_ms' => $responseTime,
                    'missing_tables' => $missingTables
                ];
            }
            
            // Check database performance
            if ($responseTime > 1000) {
                return [
                    'status' => 'warning',
                    'message' => 'Slow database response time',
                    'response_time_ms' => $responseTime
                ];
            }
            
            return [
                'status' => 'healthy',
                'message' => 'Database connectivity and structure OK',
                'response_time_ms' => $responseTime
            ];
            
        } catch (Exception $e) {
            return [
                'status' => 'critical',
                'message' => 'Database connection failed: ' . $e->getMessage(),
                'error' => $e->getMessage()
            ];
        }
    }
    
    /**
     * Check data freshness
     * 
     * @return array Data freshness status
     */
    private function checkDataFreshness(): array
    {
        try {
            // Check last recommendation calculation
            $stmt = $this->pdo->query("
                SELECT MAX(calculation_date) as last_calculation,
                       TIMESTAMPDIFF(SECOND, MAX(calculation_date), NOW()) as seconds_ago
                FROM replenishment_recommendations
            ");
            
            $result = $stmt->fetch();
            
            if (!$result['last_calculation']) {
                return [
                    'status' => 'critical',
                    'message' => 'No recommendations found in database',
                    'last_calculation' => null,
                    'age_seconds' => null
                ];
            }
            
            $ageSeconds = $result['seconds_ago'];
            $criticalThreshold = $this->config['critical_thresholds']['data_freshness'];
            $warningThreshold = $this->config['warning_thresholds']['data_freshness'];
            
            if ($ageSeconds > $criticalThreshold) {
                return [
                    'status' => 'critical',
                    'message' => 'Recommendations data is critically outdated',
                    'last_calculation' => $result['last_calculation'],
                    'age_seconds' => $ageSeconds,
                    'age_hours' => round($ageSeconds / 3600, 1)
                ];
            }
            
            if ($ageSeconds > $warningThreshold) {
                return [
                    'status' => 'warning',
                    'message' => 'Recommendations data is getting outdated',
                    'last_calculation' => $result['last_calculation'],
                    'age_seconds' => $ageSeconds,
                    'age_hours' => round($ageSeconds / 3600, 1)
                ];
            }
            
            return [
                'status' => 'healthy',
                'message' => 'Recommendations data is fresh',
                'last_calculation' => $result['last_calculation'],
                'age_seconds' => $ageSeconds,
                'age_hours' => round($ageSeconds / 3600, 1)
            ];
            
        } catch (Exception $e) {
            return [
                'status' => 'critical',
                'message' => 'Failed to check data freshness: ' . $e->getMessage(),
                'error' => $e->getMessage()
            ];
        }
    }
    
    /**
     * Check execution performance
     * 
     * @return array Execution performance status
     */
    private function checkExecutionPerformance(): array
    {
        try {
            // Get recent execution statistics
            $stmt = $this->pdo->query("
                SELECT 
                    AVG(execution_time_seconds) as avg_execution_time,
                    MAX(execution_time_seconds) as max_execution_time,
                    AVG(memory_usage_mb) as avg_memory_usage,
                    MAX(memory_usage_mb) as max_memory_usage,
                    COUNT(*) as execution_count
                FROM replenishment_scheduler_log 
                WHERE started_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
                  AND status IN ('success', 'error')
                  AND execution_time_seconds IS NOT NULL
            ");
            
            $stats = $stmt->fetch();
            
            if (!$stats['execution_count']) {
                return [
                    'status' => 'warning',
                    'message' => 'No recent execution data available',
                    'execution_count' => 0
                ];
            }
            
            $criticalTimeThreshold = $this->config['critical_thresholds']['execution_time'];
            $warningTimeThreshold = $this->config['warning_thresholds']['execution_time'];
            $criticalMemoryThreshold = $this->config['critical_thresholds']['memory_usage'];
            $warningMemoryThreshold = $this->config['warning_thresholds']['memory_usage'];
            
            $issues = [];
            $status = 'healthy';
            
            // Check execution time
            if ($stats['max_execution_time'] > $criticalTimeThreshold) {
                $issues[] = 'Critical execution time detected';
                $status = 'critical';
            } elseif ($stats['avg_execution_time'] > $warningTimeThreshold) {
                $issues[] = 'Slow average execution time';
                $status = 'warning';
            }
            
            // Check memory usage
            if ($stats['max_memory_usage'] > $criticalMemoryThreshold) {
                $issues[] = 'High memory usage detected';
                $status = 'critical';
            } elseif ($stats['avg_memory_usage'] > $warningMemoryThreshold) {
                $issues[] = 'Elevated memory usage';
                if ($status === 'healthy') $status = 'warning';
            }
            
            return [
                'status' => $status,
                'message' => empty($issues) ? 'Execution performance is good' : implode(', ', $issues),
                'avg_execution_time' => round($stats['avg_execution_time'], 2),
                'max_execution_time' => round($stats['max_execution_time'], 2),
                'avg_memory_usage' => round($stats['avg_memory_usage'], 2),
                'max_memory_usage' => round($stats['max_memory_usage'], 2),
                'execution_count' => $stats['execution_count']
            ];
            
        } catch (Exception $e) {
            return [
                'status' => 'critical',
                'message' => 'Failed to check execution performance: ' . $e->getMessage(),
                'error' => $e->getMessage()
            ];
        }
    }
    
    /**
     * Check error rate
     * 
     * @return array Error rate status
     */
    private function checkErrorRate(): array
    {
        try {
            // Get error statistics for last 7 days
            $stmt = $this->pdo->query("
                SELECT 
                    COUNT(*) as total_executions,
                    SUM(CASE WHEN status = 'error' THEN 1 ELSE 0 END) as error_count,
                    SUM(CASE WHEN status = 'success' THEN 1 ELSE 0 END) as success_count
                FROM replenishment_scheduler_log 
                WHERE started_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
            ");
            
            $stats = $stmt->fetch();
            
            if ($stats['total_executions'] == 0) {
                return [
                    'status' => 'warning',
                    'message' => 'No execution history available',
                    'total_executions' => 0,
                    'error_rate' => 0
                ];
            }
            
            $errorRate = $stats['error_count'] / $stats['total_executions'];
            $criticalThreshold = $this->config['critical_thresholds']['error_rate'];
            $warningThreshold = $this->config['warning_thresholds']['error_rate'];
            
            if ($errorRate > $criticalThreshold) {
                return [
                    'status' => 'critical',
                    'message' => 'High error rate detected',
                    'error_rate' => round($errorRate, 3),
                    'error_percentage' => round($errorRate * 100, 1),
                    'total_executions' => $stats['total_executions'],
                    'error_count' => $stats['error_count'],
                    'success_count' => $stats['success_count']
                ];
            }
            
            if ($errorRate > $warningThreshold) {
                return [
                    'status' => 'warning',
                    'message' => 'Elevated error rate',
                    'error_rate' => round($errorRate, 3),
                    'error_percentage' => round($errorRate * 100, 1),
                    'total_executions' => $stats['total_executions'],
                    'error_count' => $stats['error_count'],
                    'success_count' => $stats['success_count']
                ];
            }
            
            return [
                'status' => 'healthy',
                'message' => 'Error rate is within acceptable limits',
                'error_rate' => round($errorRate, 3),
                'error_percentage' => round($errorRate * 100, 1),
                'total_executions' => $stats['total_executions'],
                'error_count' => $stats['error_count'],
                'success_count' => $stats['success_count']
            ];
            
        } catch (Exception $e) {
            return [
                'status' => 'critical',
                'message' => 'Failed to check error rate: ' . $e->getMessage(),
                'error' => $e->getMessage()
            ];
        }
    }
    
    /**
     * Check system resources
     * 
     * @return array System resources status
     */
    private function checkSystemResources(): array
    {
        try {
            $issues = [];
            $status = 'healthy';
            
            // Check disk space
            $diskFree = disk_free_space(__DIR__);
            $diskTotal = disk_total_space(__DIR__);
            $diskUsagePercent = (($diskTotal - $diskFree) / $diskTotal) * 100;
            
            if ($diskUsagePercent > 95) {
                $issues[] = 'Critical disk space usage';
                $status = 'critical';
            } elseif ($diskUsagePercent > 85) {
                $issues[] = 'High disk space usage';
                $status = 'warning';
            }
            
            // Check memory usage
            $memoryUsage = memory_get_usage(true);
            $memoryLimit = $this->parseMemoryLimit(ini_get('memory_limit'));
            $memoryUsagePercent = ($memoryUsage / $memoryLimit) * 100;
            
            if ($memoryUsagePercent > 90) {
                $issues[] = 'High memory usage';
                if ($status !== 'critical') $status = 'warning';
            }
            
            return [
                'status' => $status,
                'message' => empty($issues) ? 'System resources are adequate' : implode(', ', $issues),
                'disk_usage_percent' => round($diskUsagePercent, 1),
                'disk_free_gb' => round($diskFree / 1024 / 1024 / 1024, 2),
                'memory_usage_mb' => round($memoryUsage / 1024 / 1024, 2),
                'memory_usage_percent' => round($memoryUsagePercent, 1)
            ];
            
        } catch (Exception $e) {
            return [
                'status' => 'warning',
                'message' => 'Could not check system resources: ' . $e->getMessage(),
                'error' => $e->getMessage()
            ];
        }
    }
    
    /**
     * Check configuration validity
     * 
     * @return array Configuration status
     */
    private function checkConfiguration(): array
    {
        try {
            $issues = [];
            $status = 'healthy';
            
            // Check if email is configured for notifications
            $stmt = $this->pdo->prepare("
                SELECT parameter_value 
                FROM replenishment_config 
                WHERE parameter_name = 'notification_email'
            ");
            $stmt->execute();
            $notificationEmail = $stmt->fetchColumn();
            
            if (empty($notificationEmail)) {
                $issues[] = 'No notification email configured';
                $status = 'warning';
            } elseif (!filter_var($notificationEmail, FILTER_VALIDATE_EMAIL)) {
                $issues[] = 'Invalid notification email format';
                $status = 'warning';
            }
            
            // Check if scheduler is enabled
            $stmt = $this->pdo->prepare("
                SELECT parameter_value 
                FROM replenishment_config 
                WHERE parameter_name = 'schedule_enabled'
            ");
            $stmt->execute();
            $scheduleEnabled = $stmt->fetchColumn();
            
            if ($scheduleEnabled === 'false' || $scheduleEnabled === '0') {
                $issues[] = 'Scheduler is disabled';
                $status = 'warning';
            }
            
            return [
                'status' => $status,
                'message' => empty($issues) ? 'Configuration is valid' : implode(', ', $issues),
                'notification_email' => $notificationEmail ?: '(not set)',
                'schedule_enabled' => $scheduleEnabled !== 'false' && $scheduleEnabled !== '0'
            ];
            
        } catch (Exception $e) {
            return [
                'status' => 'warning',
                'message' => 'Could not validate configuration: ' . $e->getMessage(),
                'error' => $e->getMessage()
            ];
        }
    }
    
    /**
     * Determine overall system status
     * 
     * @param array $checks Individual check results
     * @return string Overall status
     */
    private function determineOverallStatus(array $checks): string
    {
        $hasCritical = false;
        $hasWarning = false;
        
        foreach ($checks as $check) {
            if ($check['status'] === 'critical') {
                $hasCritical = true;
            } elseif ($check['status'] === 'warning') {
                $hasWarning = true;
            }
        }
        
        if ($hasCritical) {
            return 'critical';
        } elseif ($hasWarning) {
            return 'warning';
        } else {
            return 'healthy';
        }
    }
    
    /**
     * Collect performance metrics
     * 
     * @return array Performance metrics
     */
    private function collectPerformanceMetrics(): array
    {
        try {
            $metrics = [];
            
            // Execution metrics
            $stmt = $this->pdo->query("
                SELECT 
                    COUNT(*) as total_executions_24h,
                    AVG(execution_time_seconds) as avg_execution_time_24h,
                    AVG(memory_usage_mb) as avg_memory_usage_24h,
                    SUM(recommendations_count) as total_recommendations_24h,
                    SUM(actionable_count) as total_actionable_24h
                FROM replenishment_scheduler_log 
                WHERE started_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)
                  AND status = 'success'
            ");
            
            $execMetrics = $stmt->fetch();
            $metrics['execution'] = $execMetrics;
            
            // Recommendation metrics
            $stmt = $this->pdo->query("
                SELECT 
                    COUNT(*) as total_products,
                    SUM(CASE WHEN recommended_quantity > 0 THEN 1 ELSE 0 END) as actionable_products,
                    AVG(ads) as avg_ads,
                    SUM(recommended_quantity) as total_recommended_quantity
                FROM replenishment_recommendations 
                WHERE calculation_date = (
                    SELECT MAX(calculation_date) FROM replenishment_recommendations
                )
            ");
            
            $recMetrics = $stmt->fetch();
            $metrics['recommendations'] = $recMetrics;
            
            // System metrics
            $metrics['system'] = [
                'timestamp' => date('Y-m-d H:i:s'),
                'memory_usage_mb' => round(memory_get_usage(true) / 1024 / 1024, 2),
                'peak_memory_mb' => round(memory_get_peak_usage(true) / 1024 / 1024, 2),
                'disk_free_gb' => round(disk_free_space(__DIR__) / 1024 / 1024 / 1024, 2)
            ];
            
            return $metrics;
            
        } catch (Exception $e) {
            $this->log("Error collecting performance metrics: " . $e->getMessage());
            return [];
        }
    }
    
    /**
     * Generate alerts based on health check results
     * 
     * @param array $healthResults Health check results
     * @return array Generated alerts
     */
    private function generateAlerts(array $healthResults): array
    {
        if (!$this->config['alert_enabled']) {
            return [];
        }
        
        $alerts = [];
        
        foreach ($healthResults['checks'] as $checkName => $checkResult) {
            if (in_array($checkResult['status'], ['critical', 'warning'])) {
                $alert = [
                    'type' => $checkName,
                    'level' => $checkResult['status'],
                    'message' => $checkResult['message'],
                    'timestamp' => date('Y-m-d H:i:s'),
                    'data' => $checkResult
                ];
                
                // Check if we should send this alert (cooldown period)
                if ($this->shouldSendAlert($alert)) {
                    $alerts[] = $alert;
                    $this->sendAlert($alert);
                    $this->recordAlert($alert);
                }
            }
        }
        
        return $alerts;
    }
    
    /**
     * Check if alert should be sent (respecting cooldown)
     * 
     * @param array $alert Alert data
     * @return bool True if alert should be sent
     */
    private function shouldSendAlert(array $alert): bool
    {
        try {
            $stmt = $this->pdo->prepare("
                SELECT MAX(created_at) as last_alert
                FROM replenishment_monitor_alerts 
                WHERE alert_type = ? AND alert_level = ?
                  AND created_at >= DATE_SUB(NOW(), INTERVAL ? SECOND)
            ");
            
            $stmt->execute([
                $alert['type'],
                $alert['level'],
                $this->config['alert_cooldown']
            ]);
            
            $lastAlert = $stmt->fetchColumn();
            
            return !$lastAlert; // Send if no recent alert found
            
        } catch (Exception $e) {
            // If we can't check, err on the side of sending the alert
            return true;
        }
    }
    
    /**
     * Send alert notification
     * 
     * @param array $alert Alert data
     */
    private function sendAlert(array $alert): void
    {
        if (!$this->config['alert_enabled']) {
            return;
        }
        
        try {
            $recipients = $this->getAlertRecipients();
            
            if (empty($recipients)) {
                $this->log("No alert recipients configured");
                return;
            }
            
            $subject = $this->buildAlertSubject($alert);
            $message = $this->buildAlertMessage($alert);
            $headers = $this->buildAlertHeaders();
            
            foreach ($recipients as $recipient) {
                try {
                    $sent = mail($recipient, $subject, $message, $headers);
                    
                    if ($sent) {
                        $this->log("Alert sent to: $recipient", ['alert_type' => $alert['type']]);
                    } else {
                        $this->log("Failed to send alert to: $recipient");
                    }
                } catch (Exception $e) {
                    $this->log("Error sending alert to $recipient: " . $e->getMessage());
                }
            }
            
        } catch (Exception $e) {
            $this->log("Error sending alert: " . $e->getMessage());
        }
    }
    
    /**
     * Get alert recipients
     * 
     * @return array List of email addresses
     */
    private function getAlertRecipients(): array
    {
        $recipients = [];
        
        // Get from configuration
        if (!empty($this->config['notification_email'])) {
            $recipients[] = $this->config['notification_email'];
        }
        
        // Get from database
        try {
            $stmt = $this->pdo->prepare("
                SELECT parameter_value 
                FROM replenishment_config 
                WHERE parameter_name = 'notification_email'
            ");
            $stmt->execute();
            $dbEmail = $stmt->fetchColumn();
            
            if ($dbEmail) {
                $recipients[] = $dbEmail;
            }
        } catch (Exception $e) {
            // Ignore database errors
        }
        
        // Get from environment
        $envEmail = $_ENV['REPLENISHMENT_ALERT_EMAIL'] ?? getenv('REPLENISHMENT_ALERT_EMAIL');
        if ($envEmail) {
            $recipients[] = $envEmail;
        }
        
        return array_unique(array_filter($recipients));
    }
    
    /**
     * Build alert email subject
     * 
     * @param array $alert Alert data
     * @return string Email subject
     */
    private function buildAlertSubject(array $alert): string
    {
        $levelPrefix = strtoupper($alert['level']) === 'CRITICAL' ? '[CRITICAL]' : '[WARNING]';
        
        return "$levelPrefix Replenishment System Alert - {$alert['type']} - " . date('Y-m-d H:i');
    }
    
    /**
     * Build alert email message
     * 
     * @param array $alert Alert data
     * @return string Email message
     */
    private function buildAlertMessage(array $alert): string
    {
        $message = "Replenishment System Alert\n";
        $message .= str_repeat("=", 40) . "\n\n";
        
        $message .= "Alert Details:\n";
        $message .= "• Type: {$alert['type']}\n";
        $message .= "• Level: " . strtoupper($alert['level']) . "\n";
        $message .= "• Time: {$alert['timestamp']}\n";
        $message .= "• Message: {$alert['message']}\n\n";
        
        if (!empty($alert['data'])) {
            $message .= "Additional Information:\n";
            
            foreach ($alert['data'] as $key => $value) {
                if (!in_array($key, ['status', 'message', 'timestamp'])) {
                    if (is_array($value)) {
                        $message .= "• $key: " . json_encode($value) . "\n";
                    } else {
                        $message .= "• $key: $value\n";
                    }
                }
            }
            
            $message .= "\n";
        }
        
        $message .= "Recommended Actions:\n";
        
        switch ($alert['type']) {
            case 'database':
                $message .= "• Check database connectivity and server status\n";
                $message .= "• Verify database credentials and permissions\n";
                $message .= "• Check for missing tables or schema issues\n";
                break;
                
            case 'data_freshness':
                $message .= "• Check if scheduled calculations are running\n";
                $message .= "• Verify cron job configuration\n";
                $message .= "• Run manual calculation if needed\n";
                break;
                
            case 'execution_performance':
                $message .= "• Check system resources (CPU, memory, disk)\n";
                $message .= "• Review recent execution logs for errors\n";
                $message .= "• Consider optimizing database queries\n";
                break;
                
            case 'error_rate':
                $message .= "• Review execution logs for error patterns\n";
                $message .= "• Check data quality and integrity\n";
                $message .= "• Verify system configuration\n";
                break;
                
            default:
                $message .= "• Check system logs for detailed information\n";
                $message .= "• Verify system configuration and connectivity\n";
                $message .= "• Contact system administrator if needed\n";
                break;
        }
        
        $message .= "\n" . str_repeat("=", 40) . "\n";
        $message .= "This is an automated alert from the Replenishment Monitoring System.\n";
        $message .= "Server: " . gethostname() . "\n";
        $message .= "Generated: " . date('Y-m-d H:i:s') . "\n";
        
        return $message;
    }
    
    /**
     * Build alert email headers
     * 
     * @return string Email headers
     */
    private function buildAlertHeaders(): string
    {
        $headers = [];
        $headers[] = "From: Replenishment Monitor <noreply@replenishment-system.local>";
        $headers[] = "Reply-To: noreply@replenishment-system.local";
        $headers[] = "X-Mailer: Replenishment Monitor";
        $headers[] = "Content-Type: text/plain; charset=UTF-8";
        $headers[] = "Content-Transfer-Encoding: 8bit";
        $headers[] = "X-Priority: 1"; // High priority for alerts
        
        return implode("\r\n", $headers);
    }
    
    /**
     * Record alert in database
     * 
     * @param array $alert Alert data
     */
    private function recordAlert(array $alert): void
    {
        try {
            $stmt = $this->pdo->prepare("
                INSERT INTO replenishment_monitor_alerts 
                (alert_type, alert_level, message, alert_data, created_at)
                VALUES (?, ?, ?, ?, NOW())
            ");
            
            $stmt->execute([
                $alert['type'],
                $alert['level'],
                $alert['message'],
                json_encode($alert['data'], JSON_UNESCAPED_UNICODE)
            ]);
            
        } catch (Exception $e) {
            $this->log("Error recording alert: " . $e->getMessage());
        }
    }
    
    /**
     * Save health check results
     * 
     * @param array $healthResults Health check results
     */
    private function saveHealthCheckResults(array $healthResults): void
    {
        try {
            $stmt = $this->pdo->prepare("
                INSERT INTO replenishment_monitor_health 
                (overall_status, check_results, performance_metrics, alerts_generated, created_at)
                VALUES (?, ?, ?, ?, NOW())
            ");
            
            $stmt->execute([
                $healthResults['overall_status'],
                json_encode($healthResults['checks'], JSON_UNESCAPED_UNICODE),
                json_encode($healthResults['metrics'], JSON_UNESCAPED_UNICODE),
                count($healthResults['alerts'])
            ]);
            
        } catch (Exception $e) {
            $this->log("Error saving health check results: " . $e->getMessage());
        }
    }
    
    /**
     * Get monitoring statistics
     * 
     * @param int $days Number of days to analyze
     * @return array Monitoring statistics
     */
    public function getMonitoringStatistics(int $days = 7): array
    {
        try {
            // Health check statistics
            $stmt = $this->pdo->prepare("
                SELECT 
                    overall_status,
                    COUNT(*) as count,
                    MAX(created_at) as last_occurrence
                FROM replenishment_monitor_health 
                WHERE created_at >= DATE_SUB(NOW(), INTERVAL ? DAY)
                GROUP BY overall_status
            ");
            $stmt->execute([$days]);
            $healthStats = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Alert statistics
            $stmt = $this->pdo->prepare("
                SELECT 
                    alert_type,
                    alert_level,
                    COUNT(*) as count,
                    MAX(created_at) as last_occurrence
                FROM replenishment_monitor_alerts 
                WHERE created_at >= DATE_SUB(NOW(), INTERVAL ? DAY)
                GROUP BY alert_type, alert_level
            ");
            $stmt->execute([$days]);
            $alertStats = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Recent health checks
            $stmt = $this->pdo->prepare("
                SELECT * FROM replenishment_monitor_health 
                WHERE created_at >= DATE_SUB(NOW(), INTERVAL ? DAY)
                ORDER BY created_at DESC 
                LIMIT 10
            ");
            $stmt->execute([$days]);
            $recentHealthChecks = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            return [
                'period_days' => $days,
                'health_statistics' => $healthStats,
                'alert_statistics' => $alertStats,
                'recent_health_checks' => $recentHealthChecks
            ];
            
        } catch (Exception $e) {
            $this->log("Error getting monitoring statistics: " . $e->getMessage());
            return [];
        }
    }
    
    /**
     * Cleanup old monitoring data
     * 
     * @param int $daysToKeep Number of days to keep
     * @return array Cleanup results
     */
    public function cleanupMonitoringData(int $daysToKeep = null): array
    {
        $daysToKeep = $daysToKeep ?? $this->config['metrics_retention_days'];
        
        try {
            $results = [];
            
            // Cleanup health check records
            $stmt = $this->pdo->prepare("
                DELETE FROM replenishment_monitor_health 
                WHERE created_at < DATE_SUB(NOW(), INTERVAL ? DAY)
            ");
            $stmt->execute([$daysToKeep]);
            $results['health_records_deleted'] = $stmt->rowCount();
            
            // Cleanup alert records
            $stmt = $this->pdo->prepare("
                DELETE FROM replenishment_monitor_alerts 
                WHERE created_at < DATE_SUB(NOW(), INTERVAL ? DAY)
            ");
            $stmt->execute([$daysToKeep]);
            $results['alert_records_deleted'] = $stmt->rowCount();
            
            $this->log("Monitoring data cleanup completed", $results);
            
            return $results;
            
        } catch (Exception $e) {
            $this->log("Error cleaning up monitoring data: " . $e->getMessage());
            return ['error' => $e->getMessage()];
        }
    }
    
    /**
     * Parse memory limit string to bytes
     * 
     * @param string $memoryLimit Memory limit string
     * @return int Memory limit in bytes
     */
    private function parseMemoryLimit(string $memoryLimit): int
    {
        $memoryLimit = trim($memoryLimit);
        $last = strtolower($memoryLimit[strlen($memoryLimit) - 1]);
        $value = (int)$memoryLimit;
        
        switch ($last) {
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
     * Initialize monitoring database tables
     */
    private function initializeMonitoringTables(): void
    {
        try {
            // Health check results table
            $this->pdo->exec("
                CREATE TABLE IF NOT EXISTS replenishment_monitor_health (
                    id INT PRIMARY KEY AUTO_INCREMENT,
                    overall_status ENUM('healthy', 'warning', 'critical') NOT NULL,
                    check_results JSON NOT NULL,
                    performance_metrics JSON NULL,
                    alerts_generated INT DEFAULT 0,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    
                    INDEX idx_monitor_health_status (overall_status),
                    INDEX idx_monitor_health_created (created_at)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
                COMMENT='Health check results for replenishment system'
            ");
            
            // Alerts table
            $this->pdo->exec("
                CREATE TABLE IF NOT EXISTS replenishment_monitor_alerts (
                    id INT PRIMARY KEY AUTO_INCREMENT,
                    alert_type VARCHAR(50) NOT NULL,
                    alert_level ENUM('warning', 'critical') NOT NULL,
                    message TEXT NOT NULL,
                    alert_data JSON NULL,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    
                    INDEX idx_monitor_alerts_type (alert_type),
                    INDEX idx_monitor_alerts_level (alert_level),
                    INDEX idx_monitor_alerts_created (created_at)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
                COMMENT='Alert records for replenishment system'
            ");
            
        } catch (Exception $e) {
            $this->log("Warning: Could not create monitoring tables: " . $e->getMessage());
        }
    }
    
    /**
     * Log debug messages
     * 
     * @param string $message Message to log
     * @param array $context Additional context
     */
    private function log(string $message, array $context = []): void
    {
        $fullMessage = "[ReplenishmentMonitor] " . date('Y-m-d H:i:s') . " - $message";
        
        if (!empty($context)) {
            $fullMessage .= " | Context: " . json_encode($context, JSON_UNESCAPED_UNICODE);
        }
        
        if ($this->config['debug']) {
            echo $fullMessage . "\n";
        }
        
        error_log($fullMessage);
    }
}
?>