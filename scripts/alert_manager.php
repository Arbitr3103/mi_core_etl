<?php
/**
 * Alert Manager for Production Monitoring
 * 
 * Manages alert rules, notifications, and suppression
 */

require_once __DIR__ . '/../config/error_logging_production.php';

class AlertManager {
    private $config;
    private $error_logger;
    private $alert_state_file;
    
    public function __construct($error_logger) {
        $this->error_logger = $error_logger;
        $this->config = require __DIR__ . '/../config/alerts.php';
        $this->alert_state_file = $_ENV['LOG_PATH'] . '/alert_state.json';
    }
    
    /**
     * Evaluate all alert rules
     */
    public function evaluateAlerts() {
        $alerts_triggered = [];
        
        foreach ($this->config['rules'] as $rule_name => $rule) {
            $alert = $this->evaluateRule($rule_name, $rule);
            if ($alert) {
                $alerts_triggered[] = $alert;
            }
        }
        
        // Process triggered alerts
        foreach ($alerts_triggered as $alert) {
            $this->processAlert($alert);
        }
        
        // Check for resolved alerts
        $this->checkResolvedAlerts();
        
        return $alerts_triggered;
    }
    
    /**
     * Evaluate a single alert rule
     */
    private function evaluateRule($rule_name, $rule) {
        switch ($rule['metric']) {
            case 'response_time':
                return $this->evaluateResponseTimeRule($rule_name, $rule);
            case 'error_rate':
                return $this->evaluateErrorRateRule($rule_name, $rule);
            case 'uptime':
                return $this->evaluateUptimeRule($rule_name, $rule);
            case 'database_response_time':
                return $this->evaluateDatabaseRule($rule_name, $rule);
            case 'disk_usage_percent':
                return $this->evaluateDiskUsageRule($rule_name, $rule);
            case 'memory_usage_percent':
                return $this->evaluateMemoryUsageRule($rule_name, $rule);
            default:
                return null;
        }
    }
    
    /**
     * Evaluate response time rule
     */
    private function evaluateResponseTimeRule($rule_name, $rule) {
        $metrics = $this->getRecentMetrics($rule['evaluation_window']);
        
        if (count($metrics) < $rule['min_samples']) {
            return null;
        }
        
        $avg_response_time = array_sum(array_column($metrics, 'response_time_ms')) / count($metrics);
        
        $severity = null;
        if ($avg_response_time > $rule['critical_threshold']) {
            $severity = 'critical';
        } elseif ($avg_response_time > $rule['warning_threshold']) {
            $severity = 'warning';
        }
        
        if ($severity) {
            return [
                'rule' => $rule_name,
                'severity' => $severity,
                'title' => 'High API Response Time',
                'message' => "Average response time is {$avg_response_time}ms over the last " . ($rule['evaluation_window'] / 60) . " minutes",
                'metrics' => [
                    'avg_response_time_ms' => round($avg_response_time, 2),
                    'sample_count' => count($metrics),
                    'threshold' => $severity === 'critical' ? $rule['critical_threshold'] : $rule['warning_threshold']
                ],
                'timestamp' => time()
            ];
        }
        
        return null;
    }
    
    /**
     * Evaluate error rate rule
     */
    private function evaluateErrorRateRule($rule_name, $rule) {
        $metrics = $this->getRecentMetrics($rule['evaluation_window']);
        
        if (count($metrics) < $rule['min_samples']) {
            return null;
        }
        
        $error_count = count(array_filter($metrics, function($m) {
            return $m['status_code'] >= 400;
        }));
        
        $error_rate = ($error_count / count($metrics)) * 100;
        
        $severity = null;
        if ($error_rate > $rule['critical_threshold']) {
            $severity = 'critical';
        } elseif ($error_rate > $rule['warning_threshold']) {
            $severity = 'warning';
        }
        
        if ($severity) {
            return [
                'rule' => $rule_name,
                'severity' => $severity,
                'title' => 'High Error Rate',
                'message' => "Error rate is {$error_rate}% over the last " . ($rule['evaluation_window'] / 60) . " minutes",
                'metrics' => [
                    'error_rate_percent' => round($error_rate, 2),
                    'error_count' => $error_count,
                    'total_requests' => count($metrics),
                    'threshold' => $severity === 'critical' ? $rule['critical_threshold'] : $rule['warning_threshold']
                ],
                'timestamp' => time()
            ];
        }
        
        return null;
    }
    
    /**
     * Evaluate uptime rule
     */
    private function evaluateUptimeRule($rule_name, $rule) {
        $uptime_log = $_ENV['LOG_PATH'] . '/uptime_' . date('Y-m-d') . '.log';
        
        if (!file_exists($uptime_log)) {
            return null;
        }
        
        $lines = file($uptime_log, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        $recent_lines = array_slice($lines, -($rule['consecutive_failures'] * 2));
        
        $consecutive_failures = 0;
        $total_checks = 0;
        $up_checks = 0;
        
        foreach ($recent_lines as $line) {
            $parts = explode(',', $line);
            if (count($parts) >= 4) {
                $total_checks++;
                if ($parts[3] === 'UP') {
                    $up_checks++;
                    $consecutive_failures = 0;
                } else {
                    $consecutive_failures++;
                }
            }
        }
        
        // Check for consecutive failures
        if ($consecutive_failures >= $rule['consecutive_failures']) {
            return [
                'rule' => $rule_name,
                'severity' => 'critical',
                'title' => 'Service Down',
                'message' => "Service has been down for {$consecutive_failures} consecutive checks",
                'metrics' => [
                    'consecutive_failures' => $consecutive_failures,
                    'threshold' => $rule['consecutive_failures']
                ],
                'timestamp' => time()
            ];
        }
        
        // Check overall uptime
        if ($total_checks > 0) {
            $uptime_percent = ($up_checks / $total_checks) * 100;
            if ($uptime_percent < $rule['critical_threshold']) {
                return [
                    'rule' => $rule_name,
                    'severity' => 'critical',
                    'title' => 'Low Uptime',
                    'message' => "Uptime is {$uptime_percent}% over recent checks",
                    'metrics' => [
                        'uptime_percent' => round($uptime_percent, 2),
                        'up_checks' => $up_checks,
                        'total_checks' => $total_checks,
                        'threshold' => $rule['critical_threshold']
                    ],
                    'timestamp' => time()
                ];
            }
        }
        
        return null;
    }
    
    /**
     * Evaluate database rule
     */
    private function evaluateDatabaseRule($rule_name, $rule) {
        // Test database connection
        try {
            require_once __DIR__ . '/../config/production.php';
            
            $start_time = microtime(true);
            $pdo = new PDO(
                "pgsql:host={$_ENV['DB_HOST']};port={$_ENV['DB_PORT']};dbname={$_ENV['DB_NAME']}",
                $_ENV['DB_USER'],
                $_ENV['DB_PASSWORD'],
                [PDO::ATTR_TIMEOUT => 5]
            );
            $pdo->query('SELECT 1');
            $response_time = (microtime(true) - $start_time) * 1000;
            
            $severity = null;
            if ($response_time > $rule['critical_threshold']) {
                $severity = 'critical';
            } elseif ($response_time > $rule['warning_threshold']) {
                $severity = 'warning';
            }
            
            if ($severity) {
                return [
                    'rule' => $rule_name,
                    'severity' => $severity,
                    'title' => 'Slow Database Response',
                    'message' => "Database response time is {$response_time}ms",
                    'metrics' => [
                        'response_time_ms' => round($response_time, 2),
                        'threshold' => $severity === 'critical' ? $rule['critical_threshold'] : $rule['warning_threshold']
                    ],
                    'timestamp' => time()
                ];
            }
            
        } catch (Exception $e) {
            return [
                'rule' => $rule_name,
                'severity' => 'critical',
                'title' => 'Database Connection Failed',
                'message' => "Cannot connect to database: " . $e->getMessage(),
                'metrics' => [
                    'error' => $e->getMessage()
                ],
                'timestamp' => time()
            ];
        }
        
        return null;
    }
    
    /**
     * Evaluate disk usage rule
     */
    private function evaluateDiskUsageRule($rule_name, $rule) {
        $disk_free = disk_free_space('/');
        $disk_total = disk_total_space('/');
        $disk_used_percent = (($disk_total - $disk_free) / $disk_total) * 100;
        
        $severity = null;
        if ($disk_used_percent > $rule['critical_threshold']) {
            $severity = 'critical';
        } elseif ($disk_used_percent > $rule['warning_threshold']) {
            $severity = 'warning';
        }
        
        if ($severity) {
            return [
                'rule' => $rule_name,
                'severity' => $severity,
                'title' => 'High Disk Usage',
                'message' => "Disk usage is {$disk_used_percent}%",
                'metrics' => [
                    'disk_used_percent' => round($disk_used_percent, 2),
                    'disk_free_gb' => round($disk_free / 1024 / 1024 / 1024, 2),
                    'threshold' => $severity === 'critical' ? $rule['critical_threshold'] : $rule['warning_threshold']
                ],
                'timestamp' => time()
            ];
        }
        
        return null;
    }
    
    /**
     * Evaluate memory usage rule
     */
    private function evaluateMemoryUsageRule($rule_name, $rule) {
        $memory_used = memory_get_usage(true);
        $memory_limit = ini_get('memory_limit');
        $memory_limit_bytes = $this->parseMemoryLimit($memory_limit);
        $memory_used_percent = ($memory_used / $memory_limit_bytes) * 100;
        
        $severity = null;
        if ($memory_used_percent > $rule['critical_threshold']) {
            $severity = 'critical';
        } elseif ($memory_used_percent > $rule['warning_threshold']) {
            $severity = 'warning';
        }
        
        if ($severity) {
            return [
                'rule' => $rule_name,
                'severity' => $severity,
                'title' => 'High Memory Usage',
                'message' => "Memory usage is {$memory_used_percent}%",
                'metrics' => [
                    'memory_used_percent' => round($memory_used_percent, 2),
                    'memory_used_mb' => round($memory_used / 1024 / 1024, 2),
                    'threshold' => $severity === 'critical' ? $rule['critical_threshold'] : $rule['warning_threshold']
                ],
                'timestamp' => time()
            ];
        }
        
        return null;
    }
    
    /**
     * Process a triggered alert
     */
    private function processAlert($alert) {
        // Check if alert should be suppressed
        if ($this->shouldSuppressAlert($alert)) {
            return;
        }
        
        // Update alert state
        $this->updateAlertState($alert);
        
        // Send notifications
        $this->sendNotifications($alert);
        
        // Log the alert
        $this->error_logger->logError($alert['severity'], 'Alert Triggered: ' . $alert['title'], [
            'alert' => $alert
        ]);
    }
    
    /**
     * Check if alert should be suppressed
     */
    private function shouldSuppressAlert($alert) {
        $state = $this->getAlertState();
        $alert_key = $alert['rule'] . '_' . $alert['severity'];
        
        // Check if same alert was sent recently
        if (isset($state['last_sent'][$alert_key])) {
            $time_since_last = time() - $state['last_sent'][$alert_key];
            if ($time_since_last < $this->config['suppression']['same_alert_interval']) {
                return true;
            }
        }
        
        // Check hourly alert limit
        $current_hour = date('Y-m-d H');
        if (!isset($state['hourly_count'][$current_hour])) {
            $state['hourly_count'][$current_hour] = 0;
        }
        
        if ($state['hourly_count'][$current_hour] >= $this->config['suppression']['max_alerts_per_hour']) {
            return true;
        }
        
        return false;
    }
    
    /**
     * Update alert state
     */
    private function updateAlertState($alert) {
        $state = $this->getAlertState();
        $alert_key = $alert['rule'] . '_' . $alert['severity'];
        $current_hour = date('Y-m-d H');
        
        $state['last_sent'][$alert_key] = time();
        $state['hourly_count'][$current_hour] = ($state['hourly_count'][$current_hour] ?? 0) + 1;
        $state['active_alerts'][$alert['rule']] = $alert;
        
        // Clean up old hourly counts (keep last 24 hours)
        $cutoff_hour = date('Y-m-d H', time() - 86400);
        foreach ($state['hourly_count'] as $hour => $count) {
            if ($hour < $cutoff_hour) {
                unset($state['hourly_count'][$hour]);
            }
        }
        
        file_put_contents($this->alert_state_file, json_encode($state), LOCK_EX);
    }
    
    /**
     * Get alert state
     */
    private function getAlertState() {
        if (file_exists($this->alert_state_file)) {
            $content = file_get_contents($this->alert_state_file);
            return json_decode($content, true) ?: [];
        }
        
        return [
            'last_sent' => [],
            'hourly_count' => [],
            'active_alerts' => []
        ];
    }
    
    /**
     * Check for resolved alerts
     */
    private function checkResolvedAlerts() {
        $state = $this->getAlertState();
        
        foreach ($state['active_alerts'] as $rule => $alert) {
            // Re-evaluate the rule to see if it's still triggered
            $rule_config = $this->config['rules'][$rule] ?? null;
            if ($rule_config) {
                $current_alert = $this->evaluateRule($rule, $rule_config);
                
                if (!$current_alert) {
                    // Alert is resolved
                    $resolved_alert = $alert;
                    $resolved_alert['severity'] = 'resolved';
                    $resolved_alert['title'] = 'Alert Resolved: ' . $alert['title'];
                    $resolved_alert['message'] = 'The alert condition is no longer present';
                    $resolved_alert['timestamp'] = time();
                    
                    $this->sendNotifications($resolved_alert);
                    
                    // Remove from active alerts
                    unset($state['active_alerts'][$rule]);
                    file_put_contents($this->alert_state_file, json_encode($state), LOCK_EX);
                    
                    $this->error_logger->logError('info', 'Alert Resolved: ' . $alert['title'], [
                        'alert' => $resolved_alert
                    ]);
                }
            }
        }
    }
    
    /**
     * Send notifications for an alert
     */
    private function sendNotifications($alert) {
        $notifications = $this->config['notifications'];
        
        // Email notification
        if ($notifications['email']['enabled']) {
            $this->sendEmailNotification($alert, $notifications['email']);
        }
        
        // Slack notification
        if ($notifications['slack']['enabled']) {
            $this->sendSlackNotification($alert, $notifications['slack']);
        }
        
        // Telegram notification
        if ($notifications['telegram']['enabled']) {
            $this->sendTelegramNotification($alert, $notifications['telegram']);
        }
        
        // Webhook notification
        if ($notifications['webhook']['enabled']) {
            $this->sendWebhookNotification($alert, $notifications['webhook']);
        }
    }
    
    /**
     * Send email notification
     */
    private function sendEmailNotification($alert, $config) {
        $template = $this->config['templates']['email'];
        
        $subject = $this->replaceTemplateVars($template['subject'], $alert);
        $body = $this->replaceTemplateVars($template['body'], $alert);
        
        // Use mail() function or configure SMTP if needed
        $headers = "From: {$config['from']}\r\n";
        $headers .= "Content-Type: text/plain; charset=UTF-8\r\n";
        
        mail($config['to'], $subject, $body, $headers);
    }
    
    /**
     * Send Slack notification
     */
    private function sendSlackNotification($alert, $config) {
        $template = $this->config['templates']['slack'][$alert['severity']] ?? $this->config['templates']['slack']['critical'];
        
        $payload = [
            'channel' => $config['channel'],
            'username' => $config['username'],
            'text' => $template['icon'] . ' ' . $alert['title'],
            'attachments' => [
                [
                    'color' => $template['color'],
                    'title' => $template['title'],
                    'text' => $alert['message'],
                    'fields' => [
                        ['title' => 'Severity', 'value' => strtoupper($alert['severity']), 'short' => true],
                        ['title' => 'Time', 'value' => date('Y-m-d H:i:s', $alert['timestamp']), 'short' => true],
                    ],
                    'footer' => 'Warehouse Dashboard Monitor',
                    'ts' => $alert['timestamp']
                ]
            ]
        ];
        
        // Add metrics to fields
        if (!empty($alert['metrics'])) {
            foreach ($alert['metrics'] as $key => $value) {
                $payload['attachments'][0]['fields'][] = [
                    'title' => ucwords(str_replace('_', ' ', $key)),
                    'value' => is_numeric($value) ? number_format($value, 2) : $value,
                    'short' => true
                ];
            }
        }
        
        $ch = curl_init($config['webhook_url']);
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_exec($ch);
        curl_close($ch);
    }
    
    /**
     * Send Telegram notification
     */
    private function sendTelegramNotification($alert, $config) {
        $message = "🚨 *{$alert['title']}*\n\n";
        $message .= "Severity: " . strtoupper($alert['severity']) . "\n";
        $message .= "Message: {$alert['message']}\n";
        $message .= "Time: " . date('Y-m-d H:i:s', $alert['timestamp']) . "\n\n";
        
        if (!empty($alert['metrics'])) {
            $message .= "*Metrics:*\n";
            foreach ($alert['metrics'] as $key => $value) {
                $message .= "• " . ucwords(str_replace('_', ' ', $key)) . ": " . $value . "\n";
            }
        }
        
        $url = "https://api.telegram.org/bot{$config['bot_token']}/sendMessage";
        $data = [
            'chat_id' => $config['chat_id'],
            'text' => $message,
            'parse_mode' => 'Markdown'
        ];
        
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_exec($ch);
        curl_close($ch);
    }
    
    /**
     * Send webhook notification
     */
    private function sendWebhookNotification($alert, $config) {
        $payload = [
            'alert' => $alert,
            'timestamp' => time(),
            'source' => 'warehouse-dashboard-monitor'
        ];
        
        // Add signature if secret is configured
        $headers = ['Content-Type: application/json'];
        if (!empty($config['secret'])) {
            $signature = hash_hmac('sha256', json_encode($payload), $config['secret']);
            $headers[] = "X-Signature: sha256={$signature}";
        }
        
        $ch = curl_init($config['url']);
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_exec($ch);
        curl_close($ch);
    }
    
    /**
     * Replace template variables
     */
    private function replaceTemplateVars($template, $alert) {
        $vars = [
            '{severity}' => strtoupper($alert['severity']),
            '{title}' => $alert['title'],
            '{message}' => $alert['message'],
            '{timestamp}' => date('Y-m-d H:i:s', $alert['timestamp']),
            '{server}' => $_SERVER['SERVER_NAME'] ?? 'localhost',
            '{metrics}' => $this->formatMetrics($alert['metrics'] ?? []),
            '{actions}' => $this->getRecommendedActions($alert)
        ];
        
        return str_replace(array_keys($vars), array_values($vars), $template);
    }
    
    /**
     * Format metrics for display
     */
    private function formatMetrics($metrics) {
        if (empty($metrics)) {
            return 'No metrics available';
        }
        
        $formatted = [];
        foreach ($metrics as $key => $value) {
            $formatted[] = ucwords(str_replace('_', ' ', $key)) . ': ' . $value;
        }
        
        return implode("\n", $formatted);
    }
    
    /**
     * Get recommended actions for an alert
     */
    private function getRecommendedActions($alert) {
        $actions = [
            'api_response_time' => [
                '1. Check server load and CPU usage',
                '2. Review database query performance',
                '3. Check for network issues',
                '4. Consider scaling resources'
            ],
            'error_rate' => [
                '1. Check application logs for errors',
                '2. Verify database connectivity',
                '3. Check API dependencies',
                '4. Review recent deployments'
            ],
            'uptime' => [
                '1. Check if service is running',
                '2. Verify server connectivity',
                '3. Check web server configuration',
                '4. Review system resources'
            ],
            'database_connection' => [
                '1. Check database server status',
                '2. Verify connection credentials',
                '3. Check network connectivity',
                '4. Review database logs'
            ],
            'disk_usage' => [
                '1. Clean up old log files',
                '2. Remove temporary files',
                '3. Archive old data',
                '4. Consider adding disk space'
            ],
            'memory_usage' => [
                '1. Check for memory leaks',
                '2. Review running processes',
                '3. Restart services if needed',
                '4. Consider increasing memory'
            ]
        ];
        
        $rule_actions = $actions[$alert['rule']] ?? ['1. Check system status', '2. Review logs', '3. Contact administrator'];
        
        return implode("\n", $rule_actions);
    }
    
    /**
     * Get recent metrics from log files
     */
    private function getRecentMetrics($seconds) {
        $cutoff_time = time() - $seconds;
        $metrics = [];
        
        // Load metrics from recent files
        for ($i = 0; $i <= 1; $i++) {
            $date = date('Y-m-d', time() - ($i * 86400));
            $metrics_file = $_ENV['LOG_PATH'] . '/metrics_' . $date . '.json';
            
            if (file_exists($metrics_file)) {
                $content = file_get_contents($metrics_file);
                $file_metrics = json_decode($content, true) ?: [];
                
                // Filter by time
                $file_metrics = array_filter($file_metrics, function($m) use ($cutoff_time) {
                    return $m['timestamp'] >= $cutoff_time;
                });
                
                $metrics = array_merge($metrics, $file_metrics);
            }
        }
        
        return $metrics;
    }
    
    /**
     * Parse memory limit string to bytes
     */
    private function parseMemoryLimit($limit) {
        $limit = trim($limit);
        $last = strtolower($limit[strlen($limit)-1]);
        $limit = (int) $limit;
        
        switch($last) {
            case 'g': $limit *= 1024;
            case 'm': $limit *= 1024;
            case 'k': $limit *= 1024;
        }
        
        return $limit;
    }
}

// CLI usage
if (php_sapi_name() === 'cli') {
    global $error_logger;
    $alert_manager = new AlertManager($error_logger);
    
    $alerts = $alert_manager->evaluateAlerts();
    
    if (empty($alerts)) {
        echo "No alerts triggered.\n";
    } else {
        echo "Triggered " . count($alerts) . " alerts:\n";
        foreach ($alerts as $alert) {
            echo "- {$alert['severity']}: {$alert['title']}\n";
        }
    }
}
?>