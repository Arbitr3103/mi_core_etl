<?php
/**
 * AlertManager - Comprehensive alerting system for Analytics ETL processes
 * 
 * Provides:
 * - Critical error alerting for ETL processes
 * - Daily summary reports
 * - Email/Slack/Telegram integration
 * - Alert throttling and deduplication
 * 
 * Task: 7.2 Создать AlertManager для Analytics ETL
 * Requirements: 7.1, 7.2, 7.3, 7.4, 7.5
 * 
 * @version 1.0
 * @author Warehouse Multi-Source Integration System
 */

require_once __DIR__ . '/../config/database.php';

class AlertManager {
    private PDO $pdo;
    private array $config;
    private string $logFile;
    private array $alertChannels = [];
    
    // Alert severity levels
    public const SEVERITY_CRITICAL = 'CRITICAL';
    public const SEVERITY_ERROR = 'ERROR';
    public const SEVERITY_WARNING = 'WARNING';
    public const SEVERITY_INFO = 'INFO';
    
    // Alert types
    public const TYPE_ETL_FAILURE = 'etl_failure';
    public const TYPE_API_FAILURE = 'api_failure';
    public const TYPE_DATA_QUALITY = 'data_quality';
    public const TYPE_SYSTEM_HEALTH = 'system_health';
    public const TYPE_SLA_BREACH = 'sla_breach';
    public const TYPE_DAILY_SUMMARY = 'daily_summary';
    
    // Alert thresholds (from Requirement 7)
    private const ALERT_THRESHOLDS = [
        'api_failure_rate' => 20,           // Alert when API failure rate > 20%
        'data_age_hours' => 6,              // Alert when data older than 6 hours
        'source_discrepancy' => 15,         // Alert when source discrepancy > 15%
        'data_unavailable_hours' => 12,     // Critical alert when data unavailable > 12 hours
        'consecutive_failures' => 3,        // Alert after 3 consecutive failures
        'throttle_minutes' => 60            // Throttle similar alerts for 60 minutes
    ];
    
    public function __construct(array $config = []) {
        $this->config = array_merge($this->getDefaultConfig(), $config);
        $this->logFile = $this->config['log_file'];
        
        $this->initializeDatabase();
        $this->initializeAlertChannels();
        
        $this->log('INFO', 'AlertManager initialized', [
            'channels' => array_keys($this->alertChannels),
            'thresholds' => self::ALERT_THRESHOLDS
        ]);
    }
    
    /**
     * Get default configuration
     */
    private function getDefaultConfig(): array {
        return [
            'log_file' => __DIR__ . '/../../logs/analytics_etl/alerts.log',
            'enable_email' => filter_var($_ENV['ALERT_EMAIL_ENABLED'] ?? 'true', FILTER_VALIDATE_BOOLEAN),
            'enable_slack' => filter_var($_ENV['ALERT_SLACK_ENABLED'] ?? 'false', FILTER_VALIDATE_BOOLEAN),
            'enable_telegram' => filter_var($_ENV['ALERT_TELEGRAM_ENABLED'] ?? 'false', FILTER_VALIDATE_BOOLEAN),
            'email_recipients' => explode(',', $_ENV['ALERT_EMAIL_RECIPIENTS'] ?? 'admin@company.com'),
            'slack_webhook_url' => $_ENV['SLACK_WEBHOOK_URL'] ?? '',
            'telegram_bot_token' => $_ENV['TELEGRAM_BOT_TOKEN'] ?? '',
            'telegram_chat_id' => $_ENV['TELEGRAM_CHAT_ID'] ?? '',
            'throttle_enabled' => true,
            'daily_summary_time' => '09:00',
            'timezone' => 'Europe/Moscow'
        ];
    }
    
    /**
     * Initialize database connection
     */
    private function initializeDatabase(): void {
        try {
            $this->pdo = getDatabaseConnection();
            $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            
            // Create alerts table if it doesn't exist
            $this->createAlertsTable();
            
        } catch (Exception $e) {
            throw new Exception('Database connection failed: ' . $e->getMessage());
        }
    }
    
    /**
     * Create alerts table for tracking sent alerts and throttling
     */
    private function createAlertsTable(): void {
        try {
            $this->pdo->exec("
                CREATE TABLE IF NOT EXISTS alert_history (
                    id SERIAL PRIMARY KEY,
                    alert_type VARCHAR(50) NOT NULL,
                    severity VARCHAR(20) NOT NULL,
                    title VARCHAR(255) NOT NULL,
                    message TEXT NOT NULL,
                    context JSONB,
                    channels_sent TEXT[],
                    throttle_key VARCHAR(255),
                    created_at TIMESTAMP DEFAULT NOW(),
                    sent_at TIMESTAMP,
                    status VARCHAR(20) DEFAULT 'pending'
                )
            ");
            
            // Create indexes
            $this->pdo->exec("CREATE INDEX IF NOT EXISTS idx_alert_history_type ON alert_history(alert_type)");
            $this->pdo->exec("CREATE INDEX IF NOT EXISTS idx_alert_history_severity ON alert_history(severity)");
            $this->pdo->exec("CREATE INDEX IF NOT EXISTS idx_alert_history_throttle ON alert_history(throttle_key)");
            $this->pdo->exec("CREATE INDEX IF NOT EXISTS idx_alert_history_created ON alert_history(created_at)");
            
        } catch (Exception $e) {
            $this->log('ERROR', 'Failed to create alerts table', [
                'error' => $e->getMessage()
            ]);
        }
    }
    
    /**
     * Initialize alert channels
     */
    private function initializeAlertChannels(): void {
        // Email channel
        if ($this->config['enable_email']) {
            $this->alertChannels['email'] = new EmailAlertChannel([
                'recipients' => $this->config['email_recipients'],
                'from_email' => $_ENV['ALERT_FROM_EMAIL'] ?? 'alerts@warehouse-system.com',
                'from_name' => $_ENV['ALERT_FROM_NAME'] ?? 'Warehouse ETL Alerts'
            ]);
        }
        
        // Slack channel
        if ($this->config['enable_slack'] && !empty($this->config['slack_webhook_url'])) {
            $this->alertChannels['slack'] = new SlackAlertChannel([
                'webhook_url' => $this->config['slack_webhook_url'],
                'channel' => $_ENV['SLACK_ALERT_CHANNEL'] ?? '#alerts',
                'username' => $_ENV['SLACK_BOT_USERNAME'] ?? 'ETL Alert Bot'
            ]);
        }
        
        // Telegram channel
        if ($this->config['enable_telegram'] && !empty($this->config['telegram_bot_token'])) {
            $this->alertChannels['telegram'] = new TelegramAlertChannel([
                'bot_token' => $this->config['telegram_bot_token'],
                'chat_id' => $this->config['telegram_chat_id']
            ]);
        }
        
        $this->log('INFO', 'Alert channels initialized', [
            'channels' => array_keys($this->alertChannels)
        ]);
    }
    
    /**
     * Send alert with automatic severity-based channel selection
     * 
     * @param string $type Alert type
     * @param string $severity Alert severity
     * @param string $title Alert title
     * @param string $message Alert message
     * @param array $context Additional context data
     * @param array $channels Specific channels to use (optional)
     * @return bool Success status
     */
    public function sendAlert(
        string $type, 
        string $severity, 
        string $title, 
        string $message, 
        array $context = [], 
        array $channels = []
    ): bool {
        try {
            // Generate throttle key for deduplication
            $throttleKey = $this->generateThrottleKey($type, $severity, $title);
            
            // Check if alert should be throttled
            if ($this->config['throttle_enabled'] && $this->isAlertThrottled($throttleKey)) {
                $this->log('INFO', 'Alert throttled', [
                    'type' => $type,
                    'severity' => $severity,
                    'title' => $title,
                    'throttle_key' => $throttleKey
                ]);
                return false;
            }
            
            // Log alert to database
            $alertId = $this->logAlertToDatabase($type, $severity, $title, $message, $context, $throttleKey);
            
            // Determine channels to use
            if (empty($channels)) {
                $channels = $this->getChannelsForSeverity($severity);
            }
            
            $sentChannels = [];
            $success = true;
            
            // Send to each channel
            foreach ($channels as $channelName) {
                if (isset($this->alertChannels[$channelName])) {
                    try {
                        $channel = $this->alertChannels[$channelName];
                        $sent = $channel->sendAlert($type, $severity, $title, $message, $context);
                        
                        if ($sent) {
                            $sentChannels[] = $channelName;
                            $this->log('INFO', "Alert sent via {$channelName}", [
                                'alert_id' => $alertId,
                                'type' => $type,
                                'severity' => $severity
                            ]);
                        } else {
                            $this->log('ERROR', "Failed to send alert via {$channelName}", [
                                'alert_id' => $alertId,
                                'type' => $type,
                                'severity' => $severity
                            ]);
                            $success = false;
                        }
                    } catch (Exception $e) {
                        $this->log('ERROR', "Exception sending alert via {$channelName}", [
                            'alert_id' => $alertId,
                            'error' => $e->getMessage()
                        ]);
                        $success = false;
                    }
                }
            }
            
            // Update alert status in database
            $this->updateAlertStatus($alertId, $sentChannels, $success ? 'sent' : 'failed');
            
            return $success;
            
        } catch (Exception $e) {
            $this->log('ERROR', 'Failed to send alert', [
                'type' => $type,
                'severity' => $severity,
                'title' => $title,
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }
    
    /**
     * Send ETL failure alert
     */
    public function sendETLFailureAlert(string $batchId, string $errorMessage, array $context = []): bool {
        return $this->sendAlert(
            self::TYPE_ETL_FAILURE,
            self::SEVERITY_CRITICAL,
            "ETL Process Failed: {$batchId}",
            "Analytics ETL process has failed with error: {$errorMessage}",
            array_merge($context, ['batch_id' => $batchId, 'error' => $errorMessage])
        );
    }
    
    /**
     * Send API failure alert
     */
    public function sendAPIFailureAlert(float $failureRate, int $totalRequests, int $failedRequests): bool {
        if ($failureRate <= self::ALERT_THRESHOLDS['api_failure_rate']) {
            return false; // No alert needed
        }
        
        return $this->sendAlert(
            self::TYPE_API_FAILURE,
            self::SEVERITY_ERROR,
            "High API Failure Rate: {$failureRate}%",
            "Analytics API failure rate ({$failureRate}%) exceeds threshold ({$this->getThreshold('api_failure_rate')}%). " .
            "Failed requests: {$failedRequests}/{$totalRequests}",
            [
                'failure_rate' => $failureRate,
                'total_requests' => $totalRequests,
                'failed_requests' => $failedRequests,
                'threshold' => self::ALERT_THRESHOLDS['api_failure_rate']
            ]
        );
    }
    
    /**
     * Send data quality alert
     */
    public function sendDataQualityAlert(float $qualityScore, int $lowQualityRecords, int $totalRecords): bool {
        return $this->sendAlert(
            self::TYPE_DATA_QUALITY,
            $qualityScore < 70 ? self::SEVERITY_CRITICAL : self::SEVERITY_WARNING,
            "Data Quality Issue: Score {$qualityScore}%",
            "Data quality score ({$qualityScore}%) is below acceptable levels. " .
            "Low quality records: {$lowQualityRecords}/{$totalRecords}",
            [
                'quality_score' => $qualityScore,
                'low_quality_records' => $lowQualityRecords,
                'total_records' => $totalRecords
            ]
        );
    }
    
    /**
     * Send data staleness alert
     */
    public function sendDataStalenessAlert(float $hoursOld, string $lastUpdateTime): bool {
        if ($hoursOld <= self::ALERT_THRESHOLDS['data_age_hours']) {
            return false; // No alert needed
        }
        
        $severity = $hoursOld >= self::ALERT_THRESHOLDS['data_unavailable_hours'] 
            ? self::SEVERITY_CRITICAL 
            : self::SEVERITY_WARNING;
        
        return $this->sendAlert(
            self::TYPE_DATA_QUALITY,
            $severity,
            "Stale Data Detected: {$hoursOld} hours old",
            "Warehouse data is {$hoursOld} hours old (last update: {$lastUpdateTime}). " .
            "Threshold: {$this->getThreshold('data_age_hours')} hours.",
            [
                'hours_old' => $hoursOld,
                'last_update' => $lastUpdateTime,
                'threshold_hours' => self::ALERT_THRESHOLDS['data_age_hours'],
                'critical_threshold_hours' => self::ALERT_THRESHOLDS['data_unavailable_hours']
            ]
        );
    }
    
    /**
     * Send SLA breach alert
     */
    public function sendSLABreachAlert(string $slaType, float $currentValue, float $threshold): bool {
        return $this->sendAlert(
            self::TYPE_SLA_BREACH,
            self::SEVERITY_ERROR,
            "SLA Breach: {$slaType}",
            "SLA breach detected for {$slaType}. Current value: {$currentValue}, Threshold: {$threshold}",
            [
                'sla_type' => $slaType,
                'current_value' => $currentValue,
                'threshold' => $threshold
            ]
        );
    }
    
    /**
     * Generate and send daily summary report
     */
    public function sendDailySummaryReport(): bool {
        try {
            $summary = $this->generateDailySummary();
            
            return $this->sendAlert(
                self::TYPE_DAILY_SUMMARY,
                self::SEVERITY_INFO,
                "Daily ETL Summary Report - " . date('Y-m-d'),
                $this->formatDailySummaryMessage($summary),
                $summary
            );
            
        } catch (Exception $e) {
            $this->log('ERROR', 'Failed to send daily summary report', [
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }
    
    /**
     * Generate daily summary data
     */
    private function generateDailySummary(): array {
        try {
            // Get ETL statistics for the last 24 hours
            $stmt = $this->pdo->query("
                SELECT 
                    COUNT(*) as total_runs,
                    COUNT(CASE WHEN status = 'completed' THEN 1 END) as successful_runs,
                    COUNT(CASE WHEN status = 'failed' THEN 1 END) as failed_runs,
                    AVG(execution_time_ms / 1000) as avg_execution_time_sec,
                    SUM(records_processed) as total_records_processed,
                    AVG(records_processed) as avg_records_per_run,
                    MIN(started_at) as first_run_time,
                    MAX(started_at) as last_run_time
                FROM analytics_etl_log 
                WHERE started_at >= NOW() - INTERVAL '24 HOUR'
            ");
            
            $etlStats = $stmt->fetch(PDO::FETCH_ASSOC);
            
            // Get data quality statistics
            $stmt = $this->pdo->query("
                SELECT 
                    AVG(data_quality_score) as avg_quality_score,
                    COUNT(*) as total_records,
                    COUNT(CASE WHEN data_quality_score < 80 THEN 1 END) as low_quality_records,
                    COUNT(DISTINCT normalized_warehouse_name) as unique_warehouses
                FROM inventory 
                WHERE data_source = 'api' 
                AND last_analytics_sync >= NOW() - INTERVAL '24 HOUR'
            ");
            
            $qualityStats = $stmt->fetch(PDO::FETCH_ASSOC);
            
            // Get alert statistics
            $stmt = $this->pdo->query("
                SELECT 
                    COUNT(*) as total_alerts,
                    COUNT(CASE WHEN severity = 'CRITICAL' THEN 1 END) as critical_alerts,
                    COUNT(CASE WHEN severity = 'ERROR' THEN 1 END) as error_alerts,
                    COUNT(CASE WHEN severity = 'WARNING' THEN 1 END) as warning_alerts
                FROM alert_history 
                WHERE created_at >= NOW() - INTERVAL '24 HOUR'
            ");
            
            $alertStats = $stmt->fetch(PDO::FETCH_ASSOC);
            
            // Calculate success rate and health score
            $successRate = $etlStats['total_runs'] > 0 
                ? ($etlStats['successful_runs'] / $etlStats['total_runs']) * 100 
                : 0;
            
            $healthScore = $this->calculateHealthScore($etlStats, $qualityStats, $alertStats);
            
            return [
                'date' => date('Y-m-d'),
                'period' => 'Last 24 hours',
                'etl_statistics' => $etlStats,
                'data_quality' => $qualityStats,
                'alert_statistics' => $alertStats,
                'success_rate' => round($successRate, 2),
                'health_score' => $healthScore,
                'generated_at' => date('Y-m-d H:i:s')
            ];
            
        } catch (Exception $e) {
            $this->log('ERROR', 'Failed to generate daily summary', [
                'error' => $e->getMessage()
            ]);
            
            return [
                'date' => date('Y-m-d'),
                'error' => 'Failed to generate summary: ' . $e->getMessage(),
                'generated_at' => date('Y-m-d H:i:s')
            ];
        }
    }
    
    /**
     * Format daily summary message for alerts
     */
    private function formatDailySummaryMessage(array $summary): string {
        if (isset($summary['error'])) {
            return "Failed to generate daily summary: " . $summary['error'];
        }
        
        $etl = $summary['etl_statistics'];
        $quality = $summary['data_quality'];
        $alerts = $summary['alert_statistics'];
        
        $message = "📊 Daily ETL Summary Report for {$summary['date']}\n\n";
        
        // ETL Performance
        $message .= "🔄 ETL Performance:\n";
        $message .= "• Total runs: {$etl['total_runs']}\n";
        $message .= "• Successful: {$etl['successful_runs']}\n";
        $message .= "• Failed: {$etl['failed_runs']}\n";
        $message .= "• Success rate: {$summary['success_rate']}%\n";
        $message .= "• Avg execution time: " . round($etl['avg_execution_time_sec'], 2) . "s\n";
        $message .= "• Total records processed: " . number_format($etl['total_records_processed']) . "\n\n";
        
        // Data Quality
        $message .= "📈 Data Quality:\n";
        $message .= "• Average quality score: " . round($quality['avg_quality_score'], 2) . "%\n";
        $message .= "• Total records: " . number_format($quality['total_records']) . "\n";
        $message .= "• Low quality records: {$quality['low_quality_records']}\n";
        $message .= "• Unique warehouses: {$quality['unique_warehouses']}\n\n";
        
        // Alerts
        $message .= "🚨 Alert Summary:\n";
        $message .= "• Total alerts: {$alerts['total_alerts']}\n";
        $message .= "• Critical: {$alerts['critical_alerts']}\n";
        $message .= "• Errors: {$alerts['error_alerts']}\n";
        $message .= "• Warnings: {$alerts['warning_alerts']}\n\n";
        
        // Overall Health
        $healthEmoji = $summary['health_score'] >= 90 ? '🟢' : 
                      ($summary['health_score'] >= 70 ? '🟡' : '🔴');
        $message .= "🏥 Overall Health Score: {$healthEmoji} {$summary['health_score']}%\n";
        
        return $message;
    }
    
    /**
     * Calculate overall health score
     */
    private function calculateHealthScore(array $etlStats, array $qualityStats, array $alertStats): float {
        $scores = [];
        
        // ETL Success Rate (40% weight)
        $successRate = $etlStats['total_runs'] > 0 
            ? ($etlStats['successful_runs'] / $etlStats['total_runs']) * 100 
            : 0;
        $scores['etl'] = $successRate * 0.4;
        
        // Data Quality Score (30% weight)
        $qualityScore = $qualityStats['avg_quality_score'] ?? 0;
        $scores['quality'] = $qualityScore * 0.3;
        
        // Alert Score (30% weight) - fewer alerts = higher score
        $alertPenalty = ($alertStats['critical_alerts'] * 20) + 
                       ($alertStats['error_alerts'] * 10) + 
                       ($alertStats['warning_alerts'] * 5);
        $alertScore = max(0, 100 - $alertPenalty);
        $scores['alerts'] = $alertScore * 0.3;
        
        return round(array_sum($scores), 2);
    }
    
    /**
     * Check if alert should be throttled
     */
    private function isAlertThrottled(string $throttleKey): bool {
        try {
            $stmt = $this->pdo->prepare("
                SELECT COUNT(*) 
                FROM alert_history 
                WHERE throttle_key = ? 
                AND created_at > NOW() - INTERVAL '1 MINUTE' * ?
            ");
            
            $stmt->execute([$throttleKey, self::ALERT_THRESHOLDS['throttle_minutes']]);
            
            return $stmt->fetchColumn() > 0;
            
        } catch (Exception $e) {
            $this->log('ERROR', 'Failed to check alert throttling', [
                'throttle_key' => $throttleKey,
                'error' => $e->getMessage()
            ]);
            return false; // Don't throttle if we can't check
        }
    }
    
    /**
     * Generate throttle key for alert deduplication
     */
    private function generateThrottleKey(string $type, string $severity, string $title): string {
        return md5($type . '|' . $severity . '|' . $title);
    }
    
    /**
     * Log alert to database
     */
    private function logAlertToDatabase(
        string $type, 
        string $severity, 
        string $title, 
        string $message, 
        array $context, 
        string $throttleKey
    ): int {
        try {
            $stmt = $this->pdo->prepare("
                INSERT INTO alert_history 
                (alert_type, severity, title, message, context, throttle_key, created_at)
                VALUES (?, ?, ?, ?, ?, ?, NOW())
            ");
            
            $stmt->execute([
                $type,
                $severity,
                $title,
                $message,
                json_encode($context, JSON_UNESCAPED_UNICODE),
                $throttleKey
            ]);
            
            return $this->pdo->lastInsertId();
            
        } catch (Exception $e) {
            $this->log('ERROR', 'Failed to log alert to database', [
                'error' => $e->getMessage()
            ]);
            return 0;
        }
    }
    
    /**
     * Update alert status in database
     */
    private function updateAlertStatus(int $alertId, array $sentChannels, string $status): void {
        if ($alertId === 0) return;
        
        try {
            $stmt = $this->pdo->prepare("
                UPDATE alert_history 
                SET channels_sent = ?, sent_at = NOW(), status = ?
                WHERE id = ?
            ");
            
            $stmt->execute([
                '{' . implode(',', $sentChannels) . '}',
                $status,
                $alertId
            ]);
            
        } catch (Exception $e) {
            $this->log('ERROR', 'Failed to update alert status', [
                'alert_id' => $alertId,
                'error' => $e->getMessage()
            ]);
        }
    }
    
    /**
     * Get channels for severity level
     */
    private function getChannelsForSeverity(string $severity): array {
        switch ($severity) {
            case self::SEVERITY_CRITICAL:
                return array_keys($this->alertChannels); // All channels
            case self::SEVERITY_ERROR:
                return ['email', 'slack']; // Email and Slack
            case self::SEVERITY_WARNING:
                return ['slack']; // Slack only
            case self::SEVERITY_INFO:
                return ['email']; // Email only for summaries
            default:
                return ['email'];
        }
    }
    
    /**
     * Get threshold value
     */
    private function getThreshold(string $key): mixed {
        return self::ALERT_THRESHOLDS[$key] ?? null;
    }
    
    /**
     * Get alert statistics
     */
    public function getAlertStatistics(int $days = 7): array {
        try {
            $stmt = $this->pdo->prepare("
                SELECT 
                    alert_type,
                    severity,
                    COUNT(*) as count,
                    MAX(created_at) as last_alert
                FROM alert_history 
                WHERE created_at >= NOW() - INTERVAL '1 DAY' * ?
                GROUP BY alert_type, severity
                ORDER BY count DESC
            ");
            
            $stmt->execute([$days]);
            
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
            
        } catch (Exception $e) {
            $this->log('ERROR', 'Failed to get alert statistics', [
                'error' => $e->getMessage()
            ]);
            return [];
        }
    }
    
    /**
     * Clean up old alerts
     */
    public function cleanupOldAlerts(int $daysToKeep = 90): int {
        try {
            $stmt = $this->pdo->prepare("
                DELETE FROM alert_history 
                WHERE created_at < NOW() - INTERVAL '1 DAY' * ?
            ");
            
            $stmt->execute([$daysToKeep]);
            $deletedCount = $stmt->rowCount();
            
            $this->log('INFO', 'Cleaned up old alerts', [
                'deleted_count' => $deletedCount,
                'days_kept' => $daysToKeep
            ]);
            
            return $deletedCount;
            
        } catch (Exception $e) {
            $this->log('ERROR', 'Failed to cleanup old alerts', [
                'error' => $e->getMessage()
            ]);
            return 0;
        }
    }
    
    /**
     * Log message to file
     */
    private function log(string $level, string $message, array $context = []): void {
        $timestamp = date('Y-m-d H:i:s');
        $contextStr = !empty($context) ? ' | ' . json_encode($context, JSON_UNESCAPED_UNICODE) : '';
        $logLine = "[$timestamp] [$level] [AlertManager] $message$contextStr\n";
        
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

/**
 * Email Alert Channel
 */
class EmailAlertChannel {
    private array $config;
    
    public function __construct(array $config) {
        $this->config = $config;
    }
    
    public function sendAlert(string $type, string $severity, string $title, string $message, array $context): bool {
        try {
            $subject = "[{$severity}] {$title}";
            $body = $this->formatEmailBody($type, $severity, $title, $message, $context);
            
            $headers = [
                'From: ' . $this->config['from_name'] . ' <' . $this->config['from_email'] . '>',
                'Content-Type: text/html; charset=UTF-8',
                'X-Priority: ' . $this->getPriority($severity)
            ];
            
            $success = true;
            foreach ($this->config['recipients'] as $recipient) {
                $recipient = trim($recipient);
                if (!empty($recipient)) {
                    $sent = mail($recipient, $subject, $body, implode("\r\n", $headers));
                    if (!$sent) {
                        $success = false;
                    }
                }
            }
            
            return $success;
            
        } catch (Exception $e) {
            error_log("Email alert failed: " . $e->getMessage());
            return false;
        }
    }
    
    private function formatEmailBody(string $type, string $severity, string $title, string $message, array $context): string {
        $html = '<html><body>';
        $html .= '<h2 style="color: ' . $this->getSeverityColor($severity) . '">' . htmlspecialchars($title) . '</h2>';
        $html .= '<p><strong>Severity:</strong> ' . $severity . '</p>';
        $html .= '<p><strong>Type:</strong> ' . $type . '</p>';
        $html .= '<p><strong>Time:</strong> ' . date('Y-m-d H:i:s') . '</p>';
        $html .= '<hr>';
        $html .= '<p>' . nl2br(htmlspecialchars($message)) . '</p>';
        
        if (!empty($context)) {
            $html .= '<hr>';
            $html .= '<h3>Additional Details:</h3>';
            $html .= '<pre style="background: #f5f5f5; padding: 10px; border-radius: 5px;">';
            $html .= htmlspecialchars(json_encode($context, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
            $html .= '</pre>';
        }
        
        $html .= '</body></html>';
        
        return $html;
    }
    
    private function getSeverityColor(string $severity): string {
        return match($severity) {
            'CRITICAL' => '#dc3545',
            'ERROR' => '#fd7e14',
            'WARNING' => '#ffc107',
            'INFO' => '#17a2b8',
            default => '#6c757d'
        };
    }
    
    private function getPriority(string $severity): int {
        return match($severity) {
            'CRITICAL' => 1,
            'ERROR' => 2,
            'WARNING' => 3,
            'INFO' => 4,
            default => 3
        };
    }
}

/**
 * Slack Alert Channel
 */
class SlackAlertChannel {
    private array $config;
    
    public function __construct(array $config) {
        $this->config = $config;
    }
    
    public function sendAlert(string $type, string $severity, string $title, string $message, array $context): bool {
        try {
            $payload = [
                'channel' => $this->config['channel'],
                'username' => $this->config['username'],
                'icon_emoji' => $this->getSeverityEmoji($severity),
                'attachments' => [
                    [
                        'color' => $this->getSeverityColor($severity),
                        'title' => $title,
                        'text' => $message,
                        'fields' => [
                            [
                                'title' => 'Severity',
                                'value' => $severity,
                                'short' => true
                            ],
                            [
                                'title' => 'Type',
                                'value' => $type,
                                'short' => true
                            ],
                            [
                                'title' => 'Time',
                                'value' => date('Y-m-d H:i:s'),
                                'short' => true
                            ]
                        ],
                        'footer' => 'Warehouse ETL Alert System',
                        'ts' => time()
                    ]
                ]
            ];
            
            if (!empty($context)) {
                $payload['attachments'][0]['fields'][] = [
                    'title' => 'Details',
                    'value' => '```' . json_encode($context, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . '```',
                    'short' => false
                ];
            }
            
            $ch = curl_init($this->config['webhook_url']);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
            curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, 10);
            
            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            
            return $httpCode === 200;
            
        } catch (Exception $e) {
            error_log("Slack alert failed: " . $e->getMessage());
            return false;
        }
    }
    
    private function getSeverityColor(string $severity): string {
        return match($severity) {
            'CRITICAL' => 'danger',
            'ERROR' => 'warning',
            'WARNING' => 'warning',
            'INFO' => 'good',
            default => '#808080'
        };
    }
    
    private function getSeverityEmoji(string $severity): string {
        return match($severity) {
            'CRITICAL' => ':rotating_light:',
            'ERROR' => ':exclamation:',
            'WARNING' => ':warning:',
            'INFO' => ':information_source:',
            default => ':speech_balloon:'
        };
    }
}

/**
 * Telegram Alert Channel
 */
class TelegramAlertChannel {
    private array $config;
    
    public function __construct(array $config) {
        $this->config = $config;
    }
    
    public function sendAlert(string $type, string $severity, string $title, string $message, array $context): bool {
        try {
            $emoji = $this->getSeverityEmoji($severity);
            $text = "{$emoji} *{$title}*\n\n";
            $text .= "📊 *Severity:* {$severity}\n";
            $text .= "🔧 *Type:* {$type}\n";
            $text .= "⏰ *Time:* " . date('Y-m-d H:i:s') . "\n\n";
            $text .= "📝 *Message:*\n{$message}";
            
            if (!empty($context)) {
                $text .= "\n\n📋 *Details:*\n```json\n";
                $text .= json_encode($context, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
                $text .= "\n```";
            }
            
            $url = "https://api.telegram.org/bot{$this->config['bot_token']}/sendMessage";
            
            $data = [
                'chat_id' => $this->config['chat_id'],
                'text' => $text,
                'parse_mode' => 'Markdown',
                'disable_web_page_preview' => true
            ];
            
            $ch = curl_init($url);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data));
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, 10);
            
            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            
            return $httpCode === 200;
            
        } catch (Exception $e) {
            error_log("Telegram alert failed: " . $e->getMessage());
            return false;
        }
    }
    
    private function getSeverityEmoji(string $severity): string {
        return match($severity) {
            'CRITICAL' => '🚨',
            'ERROR' => '❌',
            'WARNING' => '⚠️',
            'INFO' => 'ℹ️',
            default => '📢'
        };
    }
}