<?php
/**
 * Alert Manager
 * 
 * Manages alerts and notifications for the warehouse dashboard
 */

require_once __DIR__ . '/../config/production.php';
require_once __DIR__ . '/../config/error_logging_production.php';

class AlertManager {
    private $error_logger;
    private $config_file;
    private $config;
    
    public function __construct($error_logger) {
        $this->error_logger = $error_logger;
        $this->config_file = __DIR__ . '/../config/alerts.json';
        $this->loadConfig();
    }
    
    /**
     * Load alert configuration
     */
    private function loadConfig() {
        $default_config = [
            'enabled' => true,
            'channels' => [
                'log' => ['enabled' => true],
                'email' => ['enabled' => false, 'recipients' => []],
                'webhook' => ['enabled' => false, 'url' => '']
            ],
            'thresholds' => [
                'response_time_ms' => 3000,
                'error_rate_percent' => 5.0,
                'uptime_percent' => 99.0,
                'disk_usage_percent' => 85.0,
                'memory_usage_percent' => 85.0
            ],
            'cooldown_minutes' => 30,
            'escalation' => [
                'enabled' => false,
                'after_minutes' => 60,
                'channels' => ['email']
            ]
        ];
        
        if (file_exists($this->config_file)) {
            $content = file_get_contents($this->config_file);
            $loaded_config = json_decode($content, true);
            $this->config = array_merge($default_config, $loaded_config ?: []);
        } else {
            $this->config = $default_config;
            $this->saveConfig();
        }
    }
    
    /**
     * Save alert configuration
     */
    private function saveConfig() {
        file_put_contents($this->config_file, json_encode($this->config, JSON_PRETTY_PRINT), LOCK_EX);
    }
    
    /**
     * Send alert
     */
    public function sendAlert($type, $severity, $message, $data = []) {
        if (!$this->config['enabled']) {
            return false;
        }
        
        // Check cooldown
        if ($this->isInCooldown($type)) {
            return false;
        }
        
        $alert = [
            'id' => uniqid('alert_'),
            'type' => $type,
            'severity' => $severity, // info, warning, error, critical
            'message' => $message,
            'data' => $data,
            'timestamp' => time(),
            'resolved' => false
        ];
        
        // Store alert
        $this->storeAlert($alert);
        
        // Send through enabled channels
        $sent_channels = [];
        
        foreach ($this->config['channels'] as $channel => $channel_config) {
            if ($channel_config['enabled']) {
                try {
                    $this->sendToChannel($channel, $alert, $channel_config);
                    $sent_channels[] = $channel;
                } catch (Exception $e) {
                    $this->error_logger->logError('error', 'Alert Channel Failed', [
                        'channel' => $channel,
                        'error' => $e->getMessage(),
                        'alert_id' => $alert['id']
                    ]);
                }
            }
        }
        
        // Update cooldown
        $this->updateCooldown($type);
        
        // Log alert sent
        $this->error_logger->logError($severity, 'Alert Sent', [
            'alert_id' => $alert['id'],
            'type' => $type,
            'channels' => $sent_channels,
            'message' => $message
        ]);
        
        return $alert['id'];
    }
    
    /**
     * Send alert to specific channel
     */
    private function sendToChannel($channel, $alert, $config) {
        switch ($channel) {
            case 'log':
                $this->sendToLog($alert);
                break;
                
            case 'email':
                $this->sendToEmail($alert, $config);
                break;
                
            case 'webhook':
                $this->sendToWebhook($alert, $config);
                break;
                
            default:
                throw new Exception("Unknown alert channel: {$channel}");
        }
    }
    
    /**
     * Send alert to log file
     */
    private function sendToLog($alert) {
        $log_file = $_ENV['LOG_PATH'] . '/alerts.log';
        $severity_upper = strtoupper($alert['severity']);
        $timestamp = date('Y-m-d H:i:s', $alert['timestamp']);
        
        $log_entry = "[{$timestamp}] {$severity_upper} [{$alert['type']}] {$alert['message']}";
        
        if (!empty($alert['data'])) {
            $log_entry .= " | Data: " . json_encode($alert['data']);
        }
        
        $log_entry .= "\n";
        
        file_put_contents($log_file, $log_entry, FILE_APPEND | LOCK_EX);
    }
    
    /**
     * Send alert via email
     */
    private function sendToEmail($alert, $config) {
        if (empty($config['recipients'])) {
            throw new Exception('No email recipients configured');
        }
        
        $subject = "[Warehouse Dashboard] {$alert['severity']}: {$alert['type']}";
        $body = $this->formatEmailBody($alert);
        
        $headers = [
            'From: noreply@market-mi.ru',
            'Content-Type: text/html; charset=UTF-8',
            'X-Mailer: Warehouse Dashboard Alert System'
        ];
        
        foreach ($config['recipients'] as $recipient) {
            $sent = mail($recipient, $subject, $body, implode("\r\n", $headers));
            
            if (!$sent) {
                throw new Exception("Failed to send email to {$recipient}");
            }
        }
    }
    
    /**
     * Format email body
     */
    private function formatEmailBody($alert) {
        $timestamp = date('Y-m-d H:i:s', $alert['timestamp']);
        $severity_color = $this->getSeverityColor($alert['severity']);
        
        $html = "
        <html>
        <head>
            <style>
                body { font-family: Arial, sans-serif; margin: 20px; }
                .alert-header { background-color: {$severity_color}; color: white; padding: 15px; border-radius: 5px; }
                .alert-body { padding: 20px; border: 1px solid #ddd; border-radius: 5px; margin-top: 10px; }
                .data-table { width: 100%; border-collapse: collapse; margin-top: 15px; }
                .data-table th, .data-table td { border: 1px solid #ddd; padding: 8px; text-align: left; }
                .data-table th { background-color: #f2f2f2; }
            </style>
        </head>
        <body>
            <div class='alert-header'>
                <h2>Warehouse Dashboard Alert</h2>
                <p><strong>Type:</strong> {$alert['type']}</p>
                <p><strong>Severity:</strong> " . strtoupper($alert['severity']) . "</p>
                <p><strong>Time:</strong> {$timestamp}</p>
            </div>
            
            <div class='alert-body'>
                <h3>Message</h3>
                <p>{$alert['message']}</p>";
        
        if (!empty($alert['data'])) {
            $html .= "
                <h3>Additional Data</h3>
                <table class='data-table'>
                    <thead>
                        <tr><th>Key</th><th>Value</th></tr>
                    </thead>
                    <tbody>";
            
            foreach ($alert['data'] as $key => $value) {
                $value_str = is_array($value) ? json_encode($value) : (string)$value;
                $html .= "<tr><td>{$key}</td><td>{$value_str}</td></tr>";
            }
            
            $html .= "
                    </tbody>
                </table>";
        }
        
        $html .= "
                <hr>
                <p><small>This alert was generated by the Warehouse Dashboard monitoring system.</small></p>
            </div>
        </body>
        </html>";
        
        return $html;
    }
    
    /**
     * Get severity color for email
     */
    private function getSeverityColor($severity) {
        switch ($severity) {
            case 'critical': return '#dc3545';
            case 'error': return '#fd7e14';
            case 'warning': return '#ffc107';
            case 'info': return '#17a2b8';
            default: return '#6c757d';
        }
    }
    
    /**
     * Send alert to webhook
     */
    private function sendToWebhook($alert, $config) {
        if (empty($config['url'])) {
            throw new Exception('No webhook URL configured');
        }
        
        $payload = [
            'alert_id' => $alert['id'],
            'type' => $alert['type'],
            'severity' => $alert['severity'],
            'message' => $alert['message'],
            'timestamp' => $alert['timestamp'],
            'data' => $alert['data'],
            'source' => 'warehouse-dashboard'
        ];
        
        $context = stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => [
                    'Content-Type: application/json',
                    'User-Agent: Warehouse-Dashboard-Alerts/1.0'
                ],
                'content' => json_encode($payload),
                'timeout' => 10
            ]
        ]);
        
        $response = file_get_contents($config['url'], false, $context);
        
        if ($response === false) {
            throw new Exception('Webhook request failed');
        }
    }
    
    /**
     * Check if alert type is in cooldown
     */
    private function isInCooldown($type) {
        $cooldown_file = $_ENV['LOG_PATH'] . '/cooldown_' . $type . '.txt';
        
        if (!file_exists($cooldown_file)) {
            return false;
        }
        
        $last_sent = (int)file_get_contents($cooldown_file);
        $cooldown_seconds = $this->config['cooldown_minutes'] * 60;
        
        return (time() - $last_sent) < $cooldown_seconds;
    }
    
    /**
     * Update cooldown timestamp
     */
    private function updateCooldown($type) {
        $cooldown_file = $_ENV['LOG_PATH'] . '/cooldown_' . $type . '.txt';
        file_put_contents($cooldown_file, time(), LOCK_EX);
    }
    
    /**
     * Store alert in history
     */
    private function storeAlert($alert) {
        $date = date('Y-m-d');
        $alerts_file = $_ENV['LOG_PATH'] . '/alerts_' . $date . '.json';
        
        $alerts = [];
        if (file_exists($alerts_file)) {
            $content = file_get_contents($alerts_file);
            $alerts = json_decode($content, true) ?: [];
        }
        
        $alerts[] = $alert;
        
        // Keep only last 1000 alerts per day
        if (count($alerts) > 1000) {
            $alerts = array_slice($alerts, -1000);
        }
        
        file_put_contents($alerts_file, json_encode($alerts), LOCK_EX);
    }
    
    /**
     * Get recent alerts
     */
    public function getRecentAlerts($hours = 24, $severity = null) {
        $cutoff = time() - ($hours * 3600);
        $all_alerts = [];
        
        // Load alerts from recent files
        for ($i = 0; $i <= 1; $i++) {
            $date = date('Y-m-d', time() - ($i * 86400));
            $alerts_file = $_ENV['LOG_PATH'] . '/alerts_' . $date . '.json';
            
            if (file_exists($alerts_file)) {
                $content = file_get_contents($alerts_file);
                $alerts = json_decode($content, true) ?: [];
                
                // Filter by time
                $alerts = array_filter($alerts, function($a) use ($cutoff) {
                    return $a['timestamp'] >= $cutoff;
                });
                
                $all_alerts = array_merge($all_alerts, $alerts);
            }
        }
        
        // Filter by severity if specified
        if ($severity) {
            $all_alerts = array_filter($all_alerts, function($a) use ($severity) {
                return $a['severity'] === $severity;
            });
        }
        
        // Sort by timestamp (newest first)
        usort($all_alerts, function($a, $b) {
            return $b['timestamp'] - $a['timestamp'];
        });
        
        return $all_alerts;
    }
    
    /**
     * Get alert statistics
     */
    public function getAlertStats($days = 7) {
        $cutoff = time() - ($days * 86400);
        $all_alerts = [];
        
        // Load alerts from recent files
        for ($i = 0; $i < $days; $i++) {
            $date = date('Y-m-d', time() - ($i * 86400));
            $alerts_file = $_ENV['LOG_PATH'] . '/alerts_' . $date . '.json';
            
            if (file_exists($alerts_file)) {
                $content = file_get_contents($alerts_file);
                $alerts = json_decode($content, true) ?: [];
                
                $alerts = array_filter($alerts, function($a) use ($cutoff) {
                    return $a['timestamp'] >= $cutoff;
                });
                
                $all_alerts = array_merge($all_alerts, $alerts);
            }
        }
        
        $stats = [
            'period_days' => $days,
            'total_alerts' => count($all_alerts),
            'by_severity' => [],
            'by_type' => [],
            'by_day' => []
        ];
        
        // Count by severity
        foreach (['info', 'warning', 'error', 'critical'] as $severity) {
            $count = count(array_filter($all_alerts, function($a) use ($severity) {
                return $a['severity'] === $severity;
            }));
            $stats['by_severity'][$severity] = $count;
        }
        
        // Count by type
        $types = [];
        foreach ($all_alerts as $alert) {
            $types[$alert['type']] = ($types[$alert['type']] ?? 0) + 1;
        }
        $stats['by_type'] = $types;
        
        // Count by day
        for ($i = 0; $i < $days; $i++) {
            $date = date('Y-m-d', time() - ($i * 86400));
            $day_alerts = array_filter($all_alerts, function($a) use ($date) {
                return date('Y-m-d', $a['timestamp']) === $date;
            });
            $stats['by_day'][$date] = count($day_alerts);
        }
        
        return $stats;
    }
    
    /**
     * Update configuration
     */
    public function updateConfig($new_config) {
        $this->config = array_merge($this->config, $new_config);
        $this->saveConfig();
        return $this->config;
    }
    
    /**
     * Get current configuration
     */
    public function getConfig() {
        return $this->config;
    }
}

// Handle API requests
if (basename(__FILE__) === basename($_SERVER['SCRIPT_NAME'])) {
    header('Content-Type: application/json');
    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type');
    
    if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
        http_response_code(200);
        exit;
    }
    
    global $error_logger;
    $alert_manager = new AlertManager($error_logger);
    
    try {
        $action = $_GET['action'] ?? 'list';
        
        switch ($action) {
            case 'list':
                $hours = (int)($_GET['hours'] ?? 24);
                $severity = $_GET['severity'] ?? null;
                echo json_encode($alert_manager->getRecentAlerts($hours, $severity));
                break;
                
            case 'stats':
                $days = (int)($_GET['days'] ?? 7);
                echo json_encode($alert_manager->getAlertStats($days));
                break;
                
            case 'config':
                if ($_SERVER['REQUEST_METHOD'] === 'POST') {
                    $input = json_decode(file_get_contents('php://input'), true);
                    echo json_encode($alert_manager->updateConfig($input));
                } else {
                    echo json_encode($alert_manager->getConfig());
                }
                break;
                
            case 'test':
                $alert_id = $alert_manager->sendAlert(
                    'test',
                    'info',
                    'Test alert from alert manager',
                    ['test_data' => 'This is a test alert']
                );
                echo json_encode(['success' => true, 'alert_id' => $alert_id]);
                break;
                
            default:
                http_response_code(400);
                echo json_encode(['error' => 'Invalid action']);
        }
        
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['error' => $e->getMessage()]);
    }
}
?>