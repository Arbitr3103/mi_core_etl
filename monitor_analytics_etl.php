#!/usr/bin/env php
<?php
/**
 * Analytics ETL Monitoring Script
 * 
 * Monitors Analytics ETL processes and sends alerts when issues are detected
 * Task: 6.2 Настроить cron задачи для Analytics ETL
 * Requirements: 7.1, 7.2, 11.1
 * 
 * Usage:
 *   php monitor_analytics_etl.php [options]
 * 
 * Options:
 *   --help              Show help message
 *   --verbose           Enable verbose output
 *   --alert-only        Only send alerts, don't show status
 *   --check-health      Perform health checks
 *   --check-logs        Check log files for errors
 *   --check-database    Check database for ETL status
 *   --send-report       Send daily/weekly report
 *   --config=FILE       Use custom config file
 */

// Set error reporting and timezone
error_reporting(E_ALL);
ini_set('display_errors', 1);
date_default_timezone_set('Europe/Moscow');

// Include configuration
require_once __DIR__ . '/config.php';

/**
 * Analytics ETL Monitor Class
 */
class AnalyticsETLMonitor
{
    private PDO $pdo;
    private array $config;
    private array $options;
    private bool $verbose;
    private array $alerts = [];
    private array $metrics = [];
    
    // Alert thresholds
    private const ALERT_THRESHOLDS = [
        'max_execution_time' => 3600,      // 1 hour
        'min_success_rate' => 80,          // 80% success rate
        'max_failed_runs' => 3,            // 3 consecutive failures
        'max_log_size_mb' => 100,          // 100MB log file size
        'max_disk_usage_percent' => 85,    // 85% disk usage
        'min_records_processed' => 10,     // Minimum records per run
        'max_hours_since_last_run' => 6    // Maximum hours since last successful run
    ];
    
    public function __construct(array $options = [])
    {
        $this->options = $options;
        $this->verbose = !empty($options['verbose']);
        
        $this->initializeDatabase();
        $this->loadConfiguration();
        
        $this->log('INFO', 'Analytics ETL Monitor initialized', [
            'options' => $this->options,
            'thresholds' => self::ALERT_THRESHOLDS
        ]);
    }
    
    /**
     * Initialize database connection
     */
    private function initializeDatabase(): void
    {
        try {
            $this->pdo = getDatabaseConnection();
            $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        } catch (Exception $e) {
            $this->addAlert('CRITICAL', 'Database connection failed', [
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }
    
    /**
     * Load monitoring configuration
     */
    private function loadConfiguration(): void
    {
        $configFile = $this->options['config'] ?? __DIR__ . '/config/monitoring.php';
        
        $this->config = [
            'email_alerts' => $_ENV['ALERT_EMAIL'] ?? 'admin@yourcompany.com',
            'slack_webhook' => $_ENV['SLACK_WEBHOOK_URL'] ?? '',
            'telegram_bot_token' => $_ENV['TELEGRAM_BOT_TOKEN'] ?? '',
            'telegram_chat_id' => $_ENV['TELEGRAM_CHAT_ID'] ?? '',
            'alert_cooldown' => 3600, // 1 hour cooldown between similar alerts
            'report_recipients' => explode(',', $_ENV['REPORT_RECIPIENTS'] ?? 'admin@yourcompany.com'),
            'enable_email_alerts' => filter_var($_ENV['ENABLE_EMAIL_ALERTS'] ?? 'true', FILTER_VALIDATE_BOOLEAN),
            'enable_slack_alerts' => filter_var($_ENV['ENABLE_SLACK_ALERTS'] ?? 'false', FILTER_VALIDATE_BOOLEAN),
            'enable_telegram_alerts' => filter_var($_ENV['ENABLE_TELEGRAM_ALERTS'] ?? 'false', FILTER_VALIDATE_BOOLEAN)
        ];
        
        if (file_exists($configFile)) {
            $customConfig = include $configFile;
            if (is_array($customConfig)) {
                $this->config = array_merge($this->config, $customConfig);
            }
        }
    }
    
    /**
     * Main monitoring execution
     */
    public function run(): array
    {
        $this->log('INFO', 'Starting Analytics ETL monitoring');
        
        $results = [
            'status' => 'ok',
            'timestamp' => date('Y-m-d H:i:s'),
            'checks_performed' => [],
            'alerts_generated' => 0,
            'metrics' => []
        ];
        
        try {
            // Perform various checks based on options
            if (empty($this->options) || !empty($this->options['check-health'])) {
                $this->checkETLHealth();
                $results['checks_performed'][] = 'health';
            }
            
            if (empty($this->options) || !empty($this->options['check-database'])) {
                $this->checkDatabaseStatus();
                $results['checks_performed'][] = 'database';
            }
            
            if (empty($this->options) || !empty($this->options['check-logs'])) {
                $this->checkLogFiles();
                $results['checks_performed'][] = 'logs';
            }
            
            // System checks
            $this->checkSystemResources();
            $results['checks_performed'][] = 'system';
            
            // Process alerts
            if (!empty($this->alerts)) {
                $this->processAlerts();
                $results['alerts_generated'] = count($this->alerts);
                $results['status'] = 'alerts';
            }
            
            // Send report if requested
            if (!empty($this->options['send-report'])) {
                $this->sendReport();
                $results['checks_performed'][] = 'report';
            }
            
            $results['metrics'] = $this->metrics;
            
            $this->log('INFO', 'Monitoring completed', $results);
            
        } catch (Exception $e) {
            $this->addAlert('CRITICAL', 'Monitoring script failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            
            $results['status'] = 'error';
            $results['error'] = $e->getMessage();
        }
        
        return $results;
    }
    
    /**
     * Check ETL process health
     */
    private function checkETLHealth(): void
    {
        $this->log('INFO', 'Checking ETL health');
        
        try {
            // Check for running ETL processes
            $runningProcesses = $this->getRunningETLProcesses();
            $this->metrics['running_processes'] = count($runningProcesses);
            
            if (count($runningProcesses) > 3) {
                $this->addAlert('WARNING', 'Too many ETL processes running', [
                    'count' => count($runningProcesses),
                    'processes' => $runningProcesses
                ]);
            }
            
            // Check last successful run
            $lastRun = $this->getLastETLRun();
            if ($lastRun) {
                $hoursSinceLastRun = (time() - strtotime($lastRun['started_at'])) / 3600;
                $this->metrics['hours_since_last_run'] = round($hoursSinceLastRun, 2);
                
                if ($hoursSinceLastRun > self::ALERT_THRESHOLDS['max_hours_since_last_run']) {
                    $this->addAlert('WARNING', 'ETL has not run recently', [
                        'hours_since_last_run' => round($hoursSinceLastRun, 2),
                        'last_run' => $lastRun
                    ]);
                }
                
                // Check execution time
                if ($lastRun['execution_time'] > self::ALERT_THRESHOLDS['max_execution_time']) {
                    $this->addAlert('WARNING', 'ETL execution time is too long', [
                        'execution_time' => $lastRun['execution_time'],
                        'threshold' => self::ALERT_THRESHOLDS['max_execution_time']
                    ]);
                }
                
                // Check records processed
                if ($lastRun['records_processed'] < self::ALERT_THRESHOLDS['min_records_processed']) {
                    $this->addAlert('WARNING', 'ETL processed too few records', [
                        'records_processed' => $lastRun['records_processed'],
                        'threshold' => self::ALERT_THRESHOLDS['min_records_processed']
                    ]);
                }
            } else {
                $this->addAlert('CRITICAL', 'No ETL runs found in database');
            }
            
        } catch (Exception $e) {
            $this->addAlert('ERROR', 'Failed to check ETL health', [
                'error' => $e->getMessage()
            ]);
        }
    }
    
    /**
     * Check database status
     */
    private function checkDatabaseStatus(): void
    {
        $this->log('INFO', 'Checking database status');
        
        try {
            // Check recent ETL runs success rate
            $stmt = $this->pdo->query("
                SELECT 
                    COUNT(*) as total_runs,
                    COUNT(CASE WHEN status = 'completed' THEN 1 END) as successful_runs,
                    COUNT(CASE WHEN status = 'failed' THEN 1 END) as failed_runs,
                    AVG(execution_time) as avg_execution_time,
                    AVG(records_processed) as avg_records_processed
                FROM analytics_etl_log 
                WHERE started_at > NOW() - INTERVAL '24 hours'
            ");
            
            $stats = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($stats['total_runs'] > 0) {
                $successRate = ($stats['successful_runs'] / $stats['total_runs']) * 100;
                $this->metrics['success_rate_24h'] = round($successRate, 2);
                $this->metrics['total_runs_24h'] = $stats['total_runs'];
                $this->metrics['failed_runs_24h'] = $stats['failed_runs'];
                $this->metrics['avg_execution_time'] = round($stats['avg_execution_time'], 2);
                $this->metrics['avg_records_processed'] = round($stats['avg_records_processed'], 2);
                
                if ($successRate < self::ALERT_THRESHOLDS['min_success_rate']) {
                    $this->addAlert('CRITICAL', 'ETL success rate is too low', [
                        'success_rate' => round($successRate, 2),
                        'threshold' => self::ALERT_THRESHOLDS['min_success_rate'],
                        'stats' => $stats
                    ]);
                }
            }
            
            // Check for consecutive failures
            $stmt = $this->pdo->query("
                SELECT status, started_at 
                FROM analytics_etl_log 
                ORDER BY started_at DESC 
                LIMIT " . self::ALERT_THRESHOLDS['max_failed_runs']
            );
            
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
            
            if ($consecutiveFailures >= self::ALERT_THRESHOLDS['max_failed_runs']) {
                $this->addAlert('CRITICAL', 'Too many consecutive ETL failures', [
                    'consecutive_failures' => $consecutiveFailures,
                    'recent_runs' => $recentRuns
                ]);
            }
            
            // Check database table sizes
            $stmt = $this->pdo->query("
                SELECT 
                    schemaname,
                    tablename,
                    pg_size_pretty(pg_total_relation_size(schemaname||'.'||tablename)) as size,
                    pg_total_relation_size(schemaname||'.'||tablename) as size_bytes
                FROM pg_tables 
                WHERE tablename IN ('inventory', 'analytics_etl_log', 'warehouse_normalization')
                ORDER BY pg_total_relation_size(schemaname||'.'||tablename) DESC
            ");
            
            $tableSizes = $stmt->fetchAll(PDO::FETCH_ASSOC);
            $this->metrics['table_sizes'] = $tableSizes;
            
        } catch (Exception $e) {
            $this->addAlert('ERROR', 'Failed to check database status', [
                'error' => $e->getMessage()
            ]);
        }
    }
    
    /**
     * Check log files
     */
    private function checkLogFiles(): void
    {
        $this->log('INFO', 'Checking log files');
        
        try {
            $logDirs = [
                __DIR__ . '/logs/analytics_etl',
                __DIR__ . '/logs/cron'
            ];
            
            foreach ($logDirs as $logDir) {
                if (!is_dir($logDir)) {
                    continue;
                }
                
                $logFiles = glob($logDir . '/*.log');
                
                foreach ($logFiles as $logFile) {
                    $fileSize = filesize($logFile);
                    $fileSizeMB = $fileSize / (1024 * 1024);
                    
                    if ($fileSizeMB > self::ALERT_THRESHOLDS['max_log_size_mb']) {
                        $this->addAlert('WARNING', 'Log file is too large', [
                            'file' => basename($logFile),
                            'size_mb' => round($fileSizeMB, 2),
                            'threshold_mb' => self::ALERT_THRESHOLDS['max_log_size_mb']
                        ]);
                    }
                    
                    // Check for recent errors in log files
                    $recentErrors = $this->checkLogFileForErrors($logFile);
                    if (!empty($recentErrors)) {
                        $this->addAlert('ERROR', 'Recent errors found in log file', [
                            'file' => basename($logFile),
                            'error_count' => count($recentErrors),
                            'recent_errors' => array_slice($recentErrors, 0, 5) // First 5 errors
                        ]);
                    }
                }
            }
            
        } catch (Exception $e) {
            $this->addAlert('ERROR', 'Failed to check log files', [
                'error' => $e->getMessage()
            ]);
        }
    }
    
    /**
     * Check system resources
     */
    private function checkSystemResources(): void
    {
        $this->log('INFO', 'Checking system resources');
        
        try {
            // Check disk usage
            $diskUsage = disk_free_space(__DIR__);
            $diskTotal = disk_total_space(__DIR__);
            
            if ($diskUsage && $diskTotal) {
                $diskUsagePercent = (($diskTotal - $diskUsage) / $diskTotal) * 100;
                $this->metrics['disk_usage_percent'] = round($diskUsagePercent, 2);
                
                if ($diskUsagePercent > self::ALERT_THRESHOLDS['max_disk_usage_percent']) {
                    $this->addAlert('CRITICAL', 'Disk usage is too high', [
                        'usage_percent' => round($diskUsagePercent, 2),
                        'free_space' => $this->formatBytes($diskUsage),
                        'total_space' => $this->formatBytes($diskTotal)
                    ]);
                }
            }
            
            // Check memory usage (if available)
            if (function_exists('sys_getloadavg')) {
                $load = sys_getloadavg();
                $this->metrics['system_load'] = $load;
                
                if ($load[0] > 5.0) { // Load average > 5
                    $this->addAlert('WARNING', 'System load is high', [
                        'load_1min' => $load[0],
                        'load_5min' => $load[1],
                        'load_15min' => $load[2]
                    ]);
                }
            }
            
            // Check PHP memory usage
            $memoryUsage = memory_get_usage(true);
            $memoryLimit = $this->parseMemoryLimit(ini_get('memory_limit'));
            
            if ($memoryLimit > 0) {
                $memoryUsagePercent = ($memoryUsage / $memoryLimit) * 100;
                $this->metrics['php_memory_usage_percent'] = round($memoryUsagePercent, 2);
                
                if ($memoryUsagePercent > 80) {
                    $this->addAlert('WARNING', 'PHP memory usage is high', [
                        'usage_percent' => round($memoryUsagePercent, 2),
                        'usage' => $this->formatBytes($memoryUsage),
                        'limit' => $this->formatBytes($memoryLimit)
                    ]);
                }
            }
            
        } catch (Exception $e) {
            $this->addAlert('ERROR', 'Failed to check system resources', [
                'error' => $e->getMessage()
            ]);
        }
    }
    
    /**
     * Get running ETL processes
     */
    private function getRunningETLProcesses(): array
    {
        $processes = [];
        
        try {
            $output = shell_exec('pgrep -f "warehouse_etl_analytics.php" 2>/dev/null');
            if ($output) {
                $pids = array_filter(explode("\n", trim($output)));
                foreach ($pids as $pid) {
                    $processInfo = shell_exec("ps -p $pid -o pid,etime,cmd --no-headers 2>/dev/null");
                    if ($processInfo) {
                        $processes[] = trim($processInfo);
                    }
                }
            }
        } catch (Exception $e) {
            $this->log('WARNING', 'Failed to get running processes', ['error' => $e->getMessage()]);
        }
        
        return $processes;
    }
    
    /**
     * Get last ETL run from database
     */
    private function getLastETLRun(): ?array
    {
        try {
            $stmt = $this->pdo->query("
                SELECT 
                    batch_id,
                    status,
                    records_processed,
                    execution_time,
                    started_at,
                    completed_at
                FROM analytics_etl_log 
                ORDER BY started_at DESC 
                LIMIT 1
            ");
            
            return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
            
        } catch (Exception $e) {
            $this->log('ERROR', 'Failed to get last ETL run', ['error' => $e->getMessage()]);
            return null;
        }
    }
    
    /**
     * Check log file for recent errors
     */
    private function checkLogFileForErrors(string $logFile): array
    {
        $errors = [];
        
        try {
            // Get last 100 lines of log file
            $lines = array_slice(file($logFile), -100);
            
            foreach ($lines as $line) {
                if (preg_match('/\[(ERROR|CRITICAL|FATAL)\]/', $line)) {
                    // Check if error is recent (within last 24 hours)
                    if (preg_match('/\[(\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2})\]/', $line, $matches)) {
                        $errorTime = strtotime($matches[1]);
                        if ($errorTime && (time() - $errorTime) < 86400) { // 24 hours
                            $errors[] = trim($line);
                        }
                    } else {
                        // If no timestamp, assume it's recent
                        $errors[] = trim($line);
                    }
                }
            }
            
        } catch (Exception $e) {
            $this->log('WARNING', 'Failed to check log file for errors', [
                'file' => $logFile,
                'error' => $e->getMessage()
            ]);
        }
        
        return $errors;
    }
    
    /**
     * Add alert to the alerts array
     */
    private function addAlert(string $level, string $message, array $context = []): void
    {
        $alert = [
            'level' => $level,
            'message' => $message,
            'context' => $context,
            'timestamp' => date('Y-m-d H:i:s')
        ];
        
        $this->alerts[] = $alert;
        
        if ($this->verbose || !empty($this->options['alert-only'])) {
            $this->log($level, $message, $context);
        }
    }
    
    /**
     * Process and send alerts
     */
    private function processAlerts(): void
    {
        if (empty($this->alerts)) {
            return;
        }
        
        $this->log('INFO', 'Processing alerts', ['count' => count($this->alerts)]);
        
        // Group alerts by level
        $alertsByLevel = [];
        foreach ($this->alerts as $alert) {
            $alertsByLevel[$alert['level']][] = $alert;
        }
        
        // Send alerts based on configuration
        if ($this->config['enable_email_alerts']) {
            $this->sendEmailAlerts($alertsByLevel);
        }
        
        if ($this->config['enable_slack_alerts'] && !empty($this->config['slack_webhook'])) {
            $this->sendSlackAlerts($alertsByLevel);
        }
        
        if ($this->config['enable_telegram_alerts'] && !empty($this->config['telegram_bot_token'])) {
            $this->sendTelegramAlerts($alertsByLevel);
        }
        
        // Log alerts to database
        $this->logAlertsToDatabase();
    }
    
    /**
     * Send email alerts
     */
    private function sendEmailAlerts(array $alertsByLevel): void
    {
        try {
            $subject = 'Analytics ETL Alert - ' . date('Y-m-d H:i:s');
            $body = $this->formatAlertsForEmail($alertsByLevel);
            
            $headers = [
                'From: Analytics ETL Monitor <noreply@yourcompany.com>',
                'Content-Type: text/html; charset=UTF-8',
                'X-Priority: 1'
            ];
            
            if (mail($this->config['email_alerts'], $subject, $body, implode("\r\n", $headers))) {
                $this->log('INFO', 'Email alert sent successfully');
            } else {
                $this->log('ERROR', 'Failed to send email alert');
            }
            
        } catch (Exception $e) {
            $this->log('ERROR', 'Failed to send email alerts', ['error' => $e->getMessage()]);
        }
    }
    
    /**
     * Send Slack alerts
     */
    private function sendSlackAlerts(array $alertsByLevel): void
    {
        try {
            $payload = [
                'text' => 'Analytics ETL Alert',
                'attachments' => []
            ];
            
            foreach ($alertsByLevel as $level => $alerts) {
                $color = match($level) {
                    'CRITICAL' => 'danger',
                    'ERROR' => 'warning',
                    'WARNING' => 'warning',
                    default => 'good'
                };
                
                $attachment = [
                    'color' => $color,
                    'title' => "$level Alerts (" . count($alerts) . ")",
                    'fields' => []
                ];
                
                foreach ($alerts as $alert) {
                    $attachment['fields'][] = [
                        'title' => $alert['message'],
                        'value' => !empty($alert['context']) ? json_encode($alert['context'], JSON_PRETTY_PRINT) : 'No additional details',
                        'short' => false
                    ];
                }
                
                $payload['attachments'][] = $attachment;
            }
            
            $ch = curl_init($this->config['slack_webhook']);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
            curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            
            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            
            if ($httpCode === 200) {
                $this->log('INFO', 'Slack alert sent successfully');
            } else {
                $this->log('ERROR', 'Failed to send Slack alert', ['http_code' => $httpCode, 'response' => $response]);
            }
            
        } catch (Exception $e) {
            $this->log('ERROR', 'Failed to send Slack alerts', ['error' => $e->getMessage()]);
        }
    }
    
    /**
     * Send Telegram alerts
     */
    private function sendTelegramAlerts(array $alertsByLevel): void
    {
        try {
            $message = "🚨 *Analytics ETL Alert*\n";
            $message .= "Time: " . date('Y-m-d H:i:s') . "\n\n";
            
            foreach ($alertsByLevel as $level => $alerts) {
                $emoji = match($level) {
                    'CRITICAL' => '🔴',
                    'ERROR' => '🟠',
                    'WARNING' => '🟡',
                    default => '🔵'
                };
                
                $message .= "$emoji *$level* (" . count($alerts) . " alerts)\n";
                
                foreach ($alerts as $alert) {
                    $message .= "• " . $alert['message'] . "\n";
                }
                
                $message .= "\n";
            }
            
            $url = "https://api.telegram.org/bot{$this->config['telegram_bot_token']}/sendMessage";
            
            $data = [
                'chat_id' => $this->config['telegram_chat_id'],
                'text' => $message,
                'parse_mode' => 'Markdown'
            ];
            
            $ch = curl_init($url);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data));
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            
            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            
            if ($httpCode === 200) {
                $this->log('INFO', 'Telegram alert sent successfully');
            } else {
                $this->log('ERROR', 'Failed to send Telegram alert', ['http_code' => $httpCode, 'response' => $response]);
            }
            
        } catch (Exception $e) {
            $this->log('ERROR', 'Failed to send Telegram alerts', ['error' => $e->getMessage()]);
        }
    }
    
    /**
     * Format alerts for email
     */
    private function formatAlertsForEmail(array $alertsByLevel): string
    {
        $html = '<html><body>';
        $html .= '<h2>Analytics ETL Alert Report</h2>';
        $html .= '<p><strong>Generated:</strong> ' . date('Y-m-d H:i:s') . '</p>';
        
        foreach ($alertsByLevel as $level => $alerts) {
            $color = match($level) {
                'CRITICAL' => '#dc3545',
                'ERROR' => '#fd7e14',
                'WARNING' => '#ffc107',
                default => '#28a745'
            };
            
            $html .= "<h3 style='color: $color'>$level Alerts (" . count($alerts) . ")</h3>";
            $html .= '<ul>';
            
            foreach ($alerts as $alert) {
                $html .= '<li>';
                $html .= '<strong>' . htmlspecialchars($alert['message']) . '</strong>';
                $html .= '<br><small>Time: ' . $alert['timestamp'] . '</small>';
                
                if (!empty($alert['context'])) {
                    $html .= '<br><pre style="background: #f8f9fa; padding: 10px; margin: 5px 0;">';
                    $html .= htmlspecialchars(json_encode($alert['context'], JSON_PRETTY_PRINT));
                    $html .= '</pre>';
                }
                
                $html .= '</li>';
            }
            
            $html .= '</ul>';
        }
        
        // Add metrics summary
        if (!empty($this->metrics)) {
            $html .= '<h3>System Metrics</h3>';
            $html .= '<table border="1" cellpadding="5" cellspacing="0">';
            
            foreach ($this->metrics as $key => $value) {
                $html .= '<tr>';
                $html .= '<td><strong>' . htmlspecialchars($key) . '</strong></td>';
                $html .= '<td>' . htmlspecialchars(is_array($value) ? json_encode($value) : $value) . '</td>';
                $html .= '</tr>';
            }
            
            $html .= '</table>';
        }
        
        $html .= '</body></html>';
        
        return $html;
    }
    
    /**
     * Log alerts to database
     */
    private function logAlertsToDatabase(): void
    {
        try {
            $stmt = $this->pdo->prepare("
                INSERT INTO analytics_etl_log 
                (batch_id, etl_type, status, error_details, started_at, completed_at)
                VALUES (?, 'monitoring', 'completed', ?, NOW(), NOW())
            ");
            
            $batchId = 'monitor_' . date('Ymd_His');
            $errorDetails = json_encode([
                'alerts' => $this->alerts,
                'metrics' => $this->metrics
            ], JSON_UNESCAPED_UNICODE);
            
            $stmt->execute([$batchId, $errorDetails]);
            
        } catch (Exception $e) {
            $this->log('ERROR', 'Failed to log alerts to database', ['error' => $e->getMessage()]);
        }
    }
    
    /**
     * Send periodic report
     */
    private function sendReport(): void
    {
        $this->log('INFO', 'Generating periodic report');
        
        try {
            // Generate comprehensive report
            $report = $this->generateReport();
            
            // Send report via email
            if ($this->config['enable_email_alerts']) {
                $subject = 'Analytics ETL Report - ' . date('Y-m-d');
                $body = $this->formatReportForEmail($report);
                
                foreach ($this->config['report_recipients'] as $recipient) {
                    $recipient = trim($recipient);
                    if (!empty($recipient)) {
                        mail($recipient, $subject, $body, 'Content-Type: text/html; charset=UTF-8');
                    }
                }
            }
            
        } catch (Exception $e) {
            $this->log('ERROR', 'Failed to send report', ['error' => $e->getMessage()]);
        }
    }
    
    /**
     * Generate comprehensive report
     */
    private function generateReport(): array
    {
        $report = [
            'period' => 'last_24_hours',
            'generated_at' => date('Y-m-d H:i:s'),
            'summary' => [],
            'detailed_stats' => [],
            'recommendations' => []
        ];
        
        try {
            // Get ETL run statistics
            $stmt = $this->pdo->query("
                SELECT 
                    COUNT(*) as total_runs,
                    COUNT(CASE WHEN status = 'completed' THEN 1 END) as successful_runs,
                    COUNT(CASE WHEN status = 'failed' THEN 1 END) as failed_runs,
                    AVG(execution_time) as avg_execution_time,
                    MAX(execution_time) as max_execution_time,
                    MIN(execution_time) as min_execution_time,
                    SUM(records_processed) as total_records_processed,
                    AVG(records_processed) as avg_records_processed
                FROM analytics_etl_log 
                WHERE started_at > NOW() - INTERVAL '24 hours'
            ");
            
            $stats = $stmt->fetch(PDO::FETCH_ASSOC);
            $report['summary'] = $stats;
            
            // Get hourly breakdown
            $stmt = $this->pdo->query("
                SELECT 
                    DATE_TRUNC('hour', started_at) as hour,
                    COUNT(*) as runs,
                    AVG(execution_time) as avg_time,
                    SUM(records_processed) as records
                FROM analytics_etl_log 
                WHERE started_at > NOW() - INTERVAL '24 hours'
                GROUP BY DATE_TRUNC('hour', started_at)
                ORDER BY hour
            ");
            
            $report['detailed_stats']['hourly_breakdown'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Generate recommendations
            if ($stats['total_runs'] > 0) {
                $successRate = ($stats['successful_runs'] / $stats['total_runs']) * 100;
                
                if ($successRate < 90) {
                    $report['recommendations'][] = 'Success rate is below 90%. Consider investigating recent failures.';
                }
                
                if ($stats['avg_execution_time'] > 1800) { // 30 minutes
                    $report['recommendations'][] = 'Average execution time is high. Consider optimizing ETL process or reducing batch size.';
                }
                
                if ($stats['avg_records_processed'] < 100) {
                    $report['recommendations'][] = 'Low number of records processed. Check if data source is providing sufficient data.';
                }
            } else {
                $report['recommendations'][] = 'No ETL runs in the last 24 hours. Check cron configuration and system status.';
            }
            
        } catch (Exception $e) {
            $report['error'] = $e->getMessage();
        }
        
        return $report;
    }
    
    /**
     * Format report for email
     */
    private function formatReportForEmail(array $report): string
    {
        $html = '<html><body>';
        $html .= '<h2>Analytics ETL Daily Report</h2>';
        $html .= '<p><strong>Generated:</strong> ' . $report['generated_at'] . '</p>';
        $html .= '<p><strong>Period:</strong> Last 24 hours</p>';
        
        // Summary
        if (!empty($report['summary'])) {
            $html .= '<h3>Summary</h3>';
            $html .= '<table border="1" cellpadding="5" cellspacing="0">';
            
            foreach ($report['summary'] as $key => $value) {
                $html .= '<tr>';
                $html .= '<td><strong>' . ucwords(str_replace('_', ' ', $key)) . '</strong></td>';
                $html .= '<td>' . (is_numeric($value) ? number_format($value, 2) : htmlspecialchars($value)) . '</td>';
                $html .= '</tr>';
            }
            
            $html .= '</table>';
        }
        
        // Recommendations
        if (!empty($report['recommendations'])) {
            $html .= '<h3>Recommendations</h3>';
            $html .= '<ul>';
            
            foreach ($report['recommendations'] as $recommendation) {
                $html .= '<li>' . htmlspecialchars($recommendation) . '</li>';
            }
            
            $html .= '</ul>';
        }
        
        $html .= '</body></html>';
        
        return $html;
    }
    
    /**
     * Utility functions
     */
    private function formatBytes(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $bytes = max($bytes, 0);
        $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
        $pow = min($pow, count($units) - 1);
        
        $bytes /= (1 << (10 * $pow));
        
        return round($bytes, 2) . ' ' . $units[$pow];
    }
    
    private function parseMemoryLimit(string $memoryLimit): int
    {
        if ($memoryLimit === '-1') {
            return 0; // Unlimited
        }
        
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
     * Log message
     */
    private function log(string $level, string $message, array $context = []): void
    {
        $timestamp = date('Y-m-d H:i:s');
        $contextStr = !empty($context) ? ' | ' . json_encode($context, JSON_UNESCAPED_UNICODE) : '';
        $logLine = "[$timestamp] [$level] [Monitor] $message$contextStr\n";
        
        // Write to console if verbose or CLI
        if ($this->verbose || php_sapi_name() === 'cli') {
            echo $logLine;
        }
        
        // Write to log file
        $logFile = __DIR__ . '/logs/cron/analytics_etl_monitor.log';
        if (!is_dir(dirname($logFile))) {
            mkdir(dirname($logFile), 0755, true);
        }
        
        file_put_contents($logFile, $logLine, FILE_APPEND | LOCK_EX);
    }
}

/**
 * Command line interface
 */
function showHelp(): void
{
    echo "Analytics ETL Monitoring Script\n";
    echo "==============================\n\n";
    echo "Usage: php monitor_analytics_etl.php [options]\n\n";
    echo "Options:\n";
    echo "  --help              Show this help message\n";
    echo "  --verbose           Enable verbose output\n";
    echo "  --alert-only        Only send alerts, don't show status\n";
    echo "  --check-health      Perform health checks\n";
    echo "  --check-logs        Check log files for errors\n";
    echo "  --check-database    Check database for ETL status\n";
    echo "  --send-report       Send daily/weekly report\n";
    echo "  --config=FILE       Use custom config file\n\n";
    echo "Examples:\n";
    echo "  php monitor_analytics_etl.php --verbose\n";
    echo "  php monitor_analytics_etl.php --check-health --alert-only\n";
    echo "  php monitor_analytics_etl.php --send-report\n\n";
}

function parseCommandLineOptions(): array
{
    $options = [];
    $args = $_SERVER['argv'] ?? [];
    
    for ($i = 1; $i < count($args); $i++) {
        $arg = $args[$i];
        
        if ($arg === '--help') {
            $options['help'] = true;
        } elseif ($arg === '--verbose') {
            $options['verbose'] = true;
        } elseif ($arg === '--alert-only') {
            $options['alert-only'] = true;
        } elseif ($arg === '--check-health') {
            $options['check-health'] = true;
        } elseif ($arg === '--check-logs') {
            $options['check-logs'] = true;
        } elseif ($arg === '--check-database') {
            $options['check-database'] = true;
        } elseif ($arg === '--send-report') {
            $options['send-report'] = true;
        } elseif (strpos($arg, '--config=') === 0) {
            $options['config'] = substr($arg, 9);
        }
    }
    
    return $options;
}

// Main execution
if (php_sapi_name() === 'cli') {
    try {
        $options = parseCommandLineOptions();
        
        if (!empty($options['help'])) {
            showHelp();
            exit(0);
        }
        
        $monitor = new AnalyticsETLMonitor($options);
        $result = $monitor->run();
        
        if (!empty($options['alert-only'])) {
            // Only output if there are alerts
            if ($result['alerts_generated'] > 0) {
                echo "Alerts generated: " . $result['alerts_generated'] . "\n";
            }
        } else {
            echo "Monitoring completed: " . $result['status'] . "\n";
            echo "Checks performed: " . implode(', ', $result['checks_performed']) . "\n";
            echo "Alerts generated: " . $result['alerts_generated'] . "\n";
        }
        
        exit($result['status'] === 'ok' ? 0 : 1);
        
    } catch (Exception $e) {
        echo "Monitor failed: " . $e->getMessage() . "\n";
        exit(1);
    }
} else {
    // Web interface
    header('Content-Type: application/json');
    
    try {
        $options = $_GET;
        $monitor = new AnalyticsETLMonitor($options);
        $result = $monitor->run();
        
        echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode([
            'status' => 'error',
            'message' => $e->getMessage(),
            'timestamp' => date('Y-m-d H:i:s')
        ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    }
}