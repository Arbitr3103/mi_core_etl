<?php

declare(strict_types=1);

namespace MiCore\ETL\Ozon\Core;

/**
 * Alert Manager
 * 
 * Manages notifications and alerts for ETL processes including
 * email notifications, Slack messages, and monitoring alerts.
 * 
 * Requirements addressed:
 * - 4.2: Send alert notifications when ETL process fails
 * - 4.3: Monitor processing metrics and execution time
 */
class AlertManager
{
    private array $config;
    private Logger $logger;
    private array $alertHistory = [];
    
    public function __construct(array $config, Logger $logger)
    {
        $this->config = $config;
        $this->logger = $logger;
    }
    
    /**
     * Send ETL failure alert
     * 
     * @param string $processName Name of the failed process
     * @param string $errorMessage Error message
     * @param array $context Additional context information
     */
    public function sendFailureAlert(string $processName, string $errorMessage, array $context = []): void
    {
        $alertData = [
            'type' => 'failure',
            'process_name' => $processName,
            'error_message' => $errorMessage,
            'timestamp' => date('Y-m-d H:i:s'),
            'hostname' => gethostname(),
            'context' => $context
        ];
        
        $this->logger->error('ETL process failure alert', $alertData);
        
        // Check if we should throttle this alert
        if ($this->shouldThrottleAlert('failure', $processName)) {
            $this->logger->info('Alert throttled to prevent spam', [
                'process_name' => $processName,
                'alert_type' => 'failure'
            ]);
            return;
        }
        
        $subject = "🚨 ETL Process Failed: {$processName}";
        $message = $this->formatFailureMessage($alertData);
        
        $this->sendNotifications($subject, $message, 'error', $alertData);
        $this->recordAlert('failure', $processName);
    }
    
    /**
     * Send performance warning alert
     * 
     * @param string $processName Name of the process
     * @param string $metric Metric that exceeded threshold
     * @param mixed $value Current value
     * @param mixed $threshold Threshold value
     * @param array $context Additional context
     */
    public function sendPerformanceAlert(string $processName, string $metric, $value, $threshold, array $context = []): void
    {
        $alertData = [
            'type' => 'performance',
            'process_name' => $processName,
            'metric' => $metric,
            'value' => $value,
            'threshold' => $threshold,
            'timestamp' => date('Y-m-d H:i:s'),
            'hostname' => gethostname(),
            'context' => $context
        ];
        
        $this->logger->warning('ETL performance alert', $alertData);
        
        // Check if we should throttle this alert
        if ($this->shouldThrottleAlert('performance', $processName)) {
            return;
        }
        
        $subject = "⚠️ ETL Performance Warning: {$processName}";
        $message = $this->formatPerformanceMessage($alertData);
        
        $this->sendNotifications($subject, $message, 'warning', $alertData);
        $this->recordAlert('performance', $processName);
    }
    
    /**
     * Send success notification (for recovery or completion)
     * 
     * @param string $processName Name of the process
     * @param array $metrics Process metrics
     * @param array $context Additional context
     */
    public function sendSuccessNotification(string $processName, array $metrics = [], array $context = []): void
    {
        $alertData = [
            'type' => 'success',
            'process_name' => $processName,
            'metrics' => $metrics,
            'timestamp' => date('Y-m-d H:i:s'),
            'hostname' => gethostname(),
            'context' => $context
        ];
        
        $this->logger->info('ETL process success notification', $alertData);
        
        // Only send success notifications if there were recent failures
        if (!$this->hasRecentFailures($processName)) {
            return;
        }
        
        $subject = "✅ ETL Process Recovered: {$processName}";
        $message = $this->formatSuccessMessage($alertData);
        
        $this->sendNotifications($subject, $message, 'success', $alertData);
        $this->clearAlertHistory($processName);
    }
    
    /**
     * Send daily summary report
     * 
     * @param array $summary Summary data for all processes
     */
    public function sendDailySummary(array $summary): void
    {
        if (!$this->config['notifications']['daily_summary']['enabled'] ?? false) {
            return;
        }
        
        $subject = "📊 Daily ETL Summary - " . date('Y-m-d');
        $message = $this->formatDailySummary($summary);
        
        $this->sendNotifications($subject, $message, 'info', $summary);
        
        $this->logger->info('Daily summary sent', [
            'summary' => $summary
        ]);
    }
    
    /**
     * Send notifications via configured channels
     */
    private function sendNotifications(string $subject, string $message, string $level, array $data): void
    {
        $notifications = $this->config['notifications'] ?? [];
        
        if (!($notifications['enabled'] ?? true)) {
            return;
        }
        
        // Send email notification
        if ($notifications['email']['enabled'] ?? false) {
            $this->sendEmailNotification($subject, $message, $level, $data);
        }
        
        // Send Slack notification
        if ($notifications['slack']['enabled'] ?? false) {
            $this->sendSlackNotification($subject, $message, $level, $data);
        }
        
        // Send webhook notification
        if ($notifications['webhook']['enabled'] ?? false) {
            $this->sendWebhookNotification($subject, $message, $level, $data);
        }
    }
    
    /**
     * Send email notification
     */
    private function sendEmailNotification(string $subject, string $message, string $level, array $data): void
    {
        try {
            $emailConfig = $this->config['notifications']['email'];
            
            // Simple mail sending - in production, use a proper mail library like PHPMailer
            $headers = [
                'From: ' . $emailConfig['from_email'],
                'Reply-To: ' . $emailConfig['from_email'],
                'Content-Type: text/html; charset=UTF-8',
                'X-Mailer: Ozon ETL Alert System'
            ];
            
            $htmlMessage = $this->convertToHtml($message);
            
            foreach ($emailConfig['to_emails'] as $email) {
                $sent = mail($email, $subject, $htmlMessage, implode("\r\n", $headers));
                
                if ($sent) {
                    $this->logger->info('Email notification sent', [
                        'to' => $email,
                        'subject' => $subject,
                        'level' => $level
                    ]);
                } else {
                    $this->logger->error('Failed to send email notification', [
                        'to' => $email,
                        'subject' => $subject
                    ]);
                }
            }
            
        } catch (\Exception $e) {
            $this->logger->error('Email notification error', [
                'error' => $e->getMessage(),
                'subject' => $subject
            ]);
        }
    }
    
    /**
     * Send Slack notification
     */
    private function sendSlackNotification(string $subject, string $message, string $level, array $data): void
    {
        try {
            $slackConfig = $this->config['notifications']['slack'];
            $webhookUrl = $slackConfig['webhook_url'];
            
            if (empty($webhookUrl)) {
                return;
            }
            
            // Map alert levels to Slack colors
            $colors = [
                'error' => '#ff0000',
                'warning' => '#ffaa00',
                'success' => '#00ff00',
                'info' => '#0099ff'
            ];
            
            $color = $colors[$level] ?? '#cccccc';
            
            $payload = [
                'channel' => $slackConfig['channel'],
                'username' => 'Ozon ETL Bot',
                'icon_emoji' => ':robot_face:',
                'attachments' => [
                    [
                        'color' => $color,
                        'title' => $subject,
                        'text' => $message,
                        'footer' => 'Ozon ETL System',
                        'ts' => time(),
                        'fields' => $this->formatSlackFields($data)
                    ]
                ]
            ];
            
            $this->sendHttpRequest($webhookUrl, json_encode($payload), [
                'Content-Type: application/json'
            ]);
            
            $this->logger->info('Slack notification sent', [
                'channel' => $slackConfig['channel'],
                'subject' => $subject,
                'level' => $level
            ]);
            
        } catch (\Exception $e) {
            $this->logger->error('Slack notification error', [
                'error' => $e->getMessage(),
                'subject' => $subject
            ]);
        }
    }
    
    /**
     * Send webhook notification
     */
    private function sendWebhookNotification(string $subject, string $message, string $level, array $data): void
    {
        try {
            $webhookConfig = $this->config['notifications']['webhook'];
            $webhookUrl = $webhookConfig['url'] ?? '';
            
            if (empty($webhookUrl)) {
                return;
            }
            
            $payload = [
                'subject' => $subject,
                'message' => $message,
                'level' => $level,
                'timestamp' => date('c'),
                'hostname' => gethostname(),
                'data' => $data
            ];
            
            $this->sendHttpRequest($webhookUrl, json_encode($payload), [
                'Content-Type: application/json',
                'User-Agent: Ozon-ETL-AlertManager/1.0'
            ]);
            
            $this->logger->info('Webhook notification sent', [
                'url' => $webhookUrl,
                'subject' => $subject,
                'level' => $level
            ]);
            
        } catch (\Exception $e) {
            $this->logger->error('Webhook notification error', [
                'error' => $e->getMessage(),
                'subject' => $subject
            ]);
        }
    }
    
    /**
     * Format failure message
     */
    private function formatFailureMessage(array $alertData): string
    {
        $message = "ETL Process Failure Alert\n\n";
        $message .= "Process: {$alertData['process_name']}\n";
        $message .= "Time: {$alertData['timestamp']}\n";
        $message .= "Host: {$alertData['hostname']}\n";
        $message .= "Error: {$alertData['error_message']}\n\n";
        
        if (!empty($alertData['context'])) {
            $message .= "Additional Information:\n";
            foreach ($alertData['context'] as $key => $value) {
                $message .= "- {$key}: " . (is_array($value) ? json_encode($value) : $value) . "\n";
            }
        }
        
        $message .= "\nPlease investigate and resolve the issue.";
        
        return $message;
    }
    
    /**
     * Format performance message
     */
    private function formatPerformanceMessage(array $alertData): string
    {
        $message = "ETL Performance Warning\n\n";
        $message .= "Process: {$alertData['process_name']}\n";
        $message .= "Time: {$alertData['timestamp']}\n";
        $message .= "Host: {$alertData['hostname']}\n";
        $message .= "Metric: {$alertData['metric']}\n";
        $message .= "Current Value: {$alertData['value']}\n";
        $message .= "Threshold: {$alertData['threshold']}\n\n";
        
        if (!empty($alertData['context'])) {
            $message .= "Additional Information:\n";
            foreach ($alertData['context'] as $key => $value) {
                $message .= "- {$key}: " . (is_array($value) ? json_encode($value) : $value) . "\n";
            }
        }
        
        return $message;
    }
    
    /**
     * Format success message
     */
    private function formatSuccessMessage(array $alertData): string
    {
        $message = "ETL Process Recovery\n\n";
        $message .= "Process: {$alertData['process_name']}\n";
        $message .= "Time: {$alertData['timestamp']}\n";
        $message .= "Host: {$alertData['hostname']}\n";
        $message .= "Status: Process completed successfully\n\n";
        
        if (!empty($alertData['metrics'])) {
            $message .= "Metrics:\n";
            foreach ($alertData['metrics'] as $key => $value) {
                $message .= "- {$key}: {$value}\n";
            }
        }
        
        return $message;
    }
    
    /**
     * Format daily summary
     */
    private function formatDailySummary(array $summary): string
    {
        $message = "Daily ETL Summary - " . date('Y-m-d') . "\n\n";
        
        $message .= "Process Status:\n";
        foreach ($summary['processes'] ?? [] as $process => $status) {
            $icon = $status['success'] ? '✅' : '❌';
            $message .= "{$icon} {$process}: ";
            $message .= $status['success'] ? 'Success' : 'Failed';
            
            if (isset($status['records'])) {
                $message .= " ({$status['records']} records)";
            }
            
            if (isset($status['duration'])) {
                $message .= " in {$status['duration']}s";
            }
            
            $message .= "\n";
        }
        
        if (isset($summary['total_records'])) {
            $message .= "\nTotal Records Processed: {$summary['total_records']}\n";
        }
        
        if (isset($summary['total_errors'])) {
            $message .= "Total Errors: {$summary['total_errors']}\n";
        }
        
        return $message;
    }
    
    /**
     * Convert plain text to HTML
     */
    private function convertToHtml(string $text): string
    {
        $html = "<html><body style='font-family: Arial, sans-serif;'>";
        $html .= "<pre style='background: #f5f5f5; padding: 15px; border-radius: 5px;'>";
        $html .= htmlspecialchars($text);
        $html .= "</pre></body></html>";
        
        return $html;
    }
    
    /**
     * Format fields for Slack attachment
     */
    private function formatSlackFields(array $data): array
    {
        $fields = [];
        
        if (isset($data['process_name'])) {
            $fields[] = [
                'title' => 'Process',
                'value' => $data['process_name'],
                'short' => true
            ];
        }
        
        if (isset($data['hostname'])) {
            $fields[] = [
                'title' => 'Host',
                'value' => $data['hostname'],
                'short' => true
            ];
        }
        
        if (isset($data['timestamp'])) {
            $fields[] = [
                'title' => 'Time',
                'value' => $data['timestamp'],
                'short' => true
            ];
        }
        
        return $fields;
    }
    
    /**
     * Send HTTP request
     */
    private function sendHttpRequest(string $url, string $data, array $headers = []): void
    {
        $context = stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => implode("\r\n", $headers),
                'content' => $data,
                'timeout' => 10
            ]
        ]);
        
        $result = file_get_contents($url, false, $context);
        
        if ($result === false) {
            throw new \Exception("Failed to send HTTP request to {$url}");
        }
    }
    
    /**
     * Check if alert should be throttled
     */
    private function shouldThrottleAlert(string $type, string $processName): bool
    {
        $key = "{$type}:{$processName}";
        $now = time();
        $throttleWindow = $this->config['notifications']['throttle_minutes'] ?? 15;
        
        if (isset($this->alertHistory[$key])) {
            $lastAlert = $this->alertHistory[$key];
            return ($now - $lastAlert) < ($throttleWindow * 60);
        }
        
        return false;
    }
    
    /**
     * Record alert in history
     */
    private function recordAlert(string $type, string $processName): void
    {
        $key = "{$type}:{$processName}";
        $this->alertHistory[$key] = time();
    }
    
    /**
     * Check if there were recent failures
     */
    private function hasRecentFailures(string $processName): bool
    {
        $key = "failure:{$processName}";
        return isset($this->alertHistory[$key]);
    }
    
    /**
     * Clear alert history for a process
     */
    private function clearAlertHistory(string $processName): void
    {
        $keysToRemove = [];
        
        foreach (array_keys($this->alertHistory) as $key) {
            if (str_ends_with($key, ":{$processName}")) {
                $keysToRemove[] = $key;
            }
        }
        
        foreach ($keysToRemove as $key) {
            unset($this->alertHistory[$key]);
        }
    }
}