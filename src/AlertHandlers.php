<?php
/**
 * Alert Handlers
 * 
 * Different alert notification handlers (email, Slack, log, etc.)
 * Requirements: 8.3, 4.3
 */

class EmailAlertHandler {
    private $recipients;
    private $fromEmail;
    
    public function __construct($recipients, $fromEmail = 'mdm-monitoring@example.com') {
        $this->recipients = is_array($recipients) ? $recipients : [$recipients];
        $this->fromEmail = $fromEmail;
    }
    
    public function __invoke($alert) {
        $subject = "[MDM Alert - {$alert['level']}] {$alert['type']}";
        $message = $this->formatEmailMessage($alert);
        $headers = "From: {$this->fromEmail}\r\n";
        $headers .= "Content-Type: text/html; charset=UTF-8\r\n";
        
        foreach ($this->recipients as $recipient) {
            mail($recipient, $subject, $message, $headers);
        }
    }
    
    private function formatEmailMessage($alert) {
        $levelColor = $this->getLevelColor($alert['level']);
        
        return "
        <html>
        <body style='font-family: Arial, sans-serif;'>
            <div style='background-color: {$levelColor}; color: white; padding: 15px; border-radius: 5px;'>
                <h2 style='margin: 0;'>MDM System Alert</h2>
                <p style='margin: 5px 0 0 0;'>Level: {$alert['level']}</p>
            </div>
            <div style='padding: 20px; background-color: #f5f5f5; margin-top: 10px; border-radius: 5px;'>
                <h3>Alert Details</h3>
                <p><strong>Type:</strong> {$alert['type']}</p>
                <p><strong>Message:</strong> {$alert['message']}</p>
                <p><strong>Current Value:</strong> {$alert['value']}</p>
                <p><strong>Threshold:</strong> {$alert['threshold']}</p>
                <p><strong>Time:</strong> " . date('Y-m-d H:i:s') . "</p>
            </div>
            <div style='padding: 20px; margin-top: 10px;'>
                <p><strong>Recommended Actions:</strong></p>
                <ul>
                    {$this->getRecommendedActions($alert['type'])}
                </ul>
            </div>
        </body>
        </html>
        ";
    }
    
    private function getLevelColor($level) {
        switch ($level) {
            case 'critical': return '#dc3545';
            case 'warning': return '#ffc107';
            case 'info': return '#17a2b8';
            default: return '#6c757d';
        }
    }
    
    private function getRecommendedActions($type) {
        $actions = [
            'high_failure_rate' => [
                'Check sync error logs',
                'Verify API connectivity',
                'Run manual sync for failed products'
            ],
            'high_pending_rate' => [
                'Run sync script manually',
                'Check if cron job is running',
                'Verify database performance'
            ],
            'low_real_names' => [
                'Run product name sync',
                'Check API credentials',
                'Verify product_cross_reference table'
            ],
            'stale_sync' => [
                'Check cron job status',
                'Run manual sync',
                'Verify system resources'
            ],
            'high_error_rate' => [
                'Check error logs',
                'Verify API status',
                'Check database connectivity'
            ]
        ];
        
        $actionList = $actions[$type] ?? ['Check system logs', 'Contact administrator'];
        return '<li>' . implode('</li><li>', $actionList) . '</li>';
    }
}

class SlackAlertHandler {
    private $webhookUrl;
    
    public function __construct($webhookUrl) {
        $this->webhookUrl = $webhookUrl;
    }
    
    public function __invoke($alert) {
        $payload = [
            'text' => "MDM Alert: {$alert['message']}",
            'attachments' => [
                [
                    'color' => $this->getLevelColor($alert['level']),
                    'fields' => [
                        [
                            'title' => 'Level',
                            'value' => $alert['level'],
                            'short' => true
                        ],
                        [
                            'title' => 'Type',
                            'value' => $alert['type'],
                            'short' => true
                        ],
                        [
                            'title' => 'Current Value',
                            'value' => $alert['value'],
                            'short' => true
                        ],
                        [
                            'title' => 'Threshold',
                            'value' => $alert['threshold'],
                            'short' => true
                        ]
                    ],
                    'footer' => 'MDM Monitoring System',
                    'ts' => time()
                ]
            ]
        ];
        
        $this->sendToSlack($payload);
    }
    
    private function sendToSlack($payload) {
        $ch = curl_init($this->webhookUrl);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'POST');
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
        curl_exec($ch);
        curl_close($ch);
    }
    
    private function getLevelColor($level) {
        switch ($level) {
            case 'critical': return 'danger';
            case 'warning': return 'warning';
            case 'info': return 'good';
            default: return '#808080';
        }
    }
}

class LogAlertHandler {
    private $logFile;
    
    public function __construct($logFile = 'logs/quality_alerts.log') {
        $this->logFile = $logFile;
        
        // Create logs directory if not exists
        $dir = dirname($logFile);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
    }
    
    public function __invoke($alert) {
        $timestamp = date('Y-m-d H:i:s');
        $logEntry = sprintf(
            "[%s] [%s] [%s] %s (Value: %s, Threshold: %s)\n",
            $timestamp,
            strtoupper($alert['level']),
            $alert['type'],
            $alert['message'],
            $alert['value'],
            $alert['threshold']
        );
        
        file_put_contents($this->logFile, $logEntry, FILE_APPEND);
    }
}

class ConsoleAlertHandler {
    private $colors = [
        'critical' => "\033[1;31m", // Red
        'warning' => "\033[1;33m",  // Yellow
        'info' => "\033[1;36m",     // Cyan
        'reset' => "\033[0m"
    ];
    
    public function __invoke($alert) {
        $color = $this->colors[$alert['level']] ?? $this->colors['reset'];
        $reset = $this->colors['reset'];
        
        echo "{$color}[{$alert['level']}]{$reset} {$alert['message']}\n";
        echo "  Type: {$alert['type']}\n";
        echo "  Value: {$alert['value']} (Threshold: {$alert['threshold']})\n";
        echo "  Time: " . date('Y-m-d H:i:s') . "\n\n";
    }
}

class WebhookAlertHandler {
    private $webhookUrl;
    private $headers;
    
    public function __construct($webhookUrl, $headers = []) {
        $this->webhookUrl = $webhookUrl;
        $this->headers = $headers;
    }
    
    public function __invoke($alert) {
        $payload = [
            'alert' => $alert,
            'timestamp' => date('c'),
            'system' => 'MDM'
        ];
        
        $ch = curl_init($this->webhookUrl);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'POST');
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, array_merge(
            ['Content-Type: application/json'],
            $this->headers
        ));
        curl_exec($ch);
        curl_close($ch);
    }
}

class CompositeAlertHandler {
    private $handlers = [];
    
    public function addHandler($handler) {
        $this->handlers[] = $handler;
    }
    
    public function __invoke($alert) {
        foreach ($this->handlers as $handler) {
            try {
                call_user_func($handler, $alert);
            } catch (Exception $e) {
                error_log("Alert handler failed: " . $e->getMessage());
            }
        }
    }
}
