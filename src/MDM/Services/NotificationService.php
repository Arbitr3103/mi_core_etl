<?php

namespace MDM\Services;

/**
 * Notification Service for MDM Updates
 * Handles sending notifications about data changes and system events
 */
class NotificationService {
    private $config;
    private $channels;
    
    public function __construct($config = []) {
        $this->config = array_merge([
            'enabled' => true,
            'default_channel' => 'log',
            'channels' => [
                'log' => ['enabled' => true],
                'email' => ['enabled' => false],
                'webhook' => ['enabled' => false]
            ]
        ], $config);
        
        $this->initializeChannels();
    }
    
    /**
     * Send notification
     */
    public function send($message, $level = 'info', $channels = null) {
        if (!$this->config['enabled']) {
            return false;
        }
        
        $channels = $channels ?: [$this->config['default_channel']];
        $success = true;
        
        foreach ($channels as $channel) {
            if (!$this->sendToChannel($channel, $message, $level)) {
                $success = false;
            }
        }
        
        return $success;
    }
    
    /**
     * Send critical alert
     */
    public function sendCriticalAlert($message, $context = []) {
        return $this->send([
            'level' => 'critical',
            'message' => $message,
            'context' => $context,
            'timestamp' => date('Y-m-d H:i:s')
        ], 'critical', ['log', 'email', 'webhook']);
    }
    
    /**
     * Send data quality alert
     */
    public function sendDataQualityAlert($metric, $currentValue, $threshold, $context = []) {
        $message = [
            'type' => 'data_quality_alert',
            'metric' => $metric,
            'current_value' => $currentValue,
            'threshold' => $threshold,
            'message' => "Data quality metric '{$metric}' is {$currentValue}%, below threshold of {$threshold}%",
            'context' => $context,
            'timestamp' => date('Y-m-d H:i:s')
        ];
        
        return $this->send($message, 'warning', ['log', 'email']);
    }
    
    /**
     * Send stock alert
     */
    public function sendStockAlert($sku, $currentStock, $reservedStock, $masterId = null) {
        $message = [
            'type' => 'stock_alert',
            'sku' => $sku,
            'master_id' => $masterId,
            'current_stock' => $currentStock,
            'reserved_stock' => $reservedStock,
            'message' => "Critical stock level for SKU {$sku}: {$currentStock} units (reserved: {$reservedStock})",
            'timestamp' => date('Y-m-d H:i:s')
        ];
        
        $level = $currentStock == 0 ? 'critical' : 'warning';
        return $this->send($message, $level, ['log', 'webhook']);
    }
    
    /**
     * Send system update notification
     */
    public function sendSystemUpdate($updateType, $details = []) {
        $message = [
            'type' => 'system_update',
            'update_type' => $updateType,
            'details' => $details,
            'message' => "System update: {$updateType}",
            'timestamp' => date('Y-m-d H:i:s')
        ];
        
        return $this->send($message, 'info', ['log']);
    }
    
    /**
     * Initialize notification channels
     */
    private function initializeChannels() {
        $this->channels = [];
        
        foreach ($this->config['channels'] as $name => $config) {
            if ($config['enabled']) {
                switch ($name) {
                    case 'log':
                        $this->channels[$name] = new LogNotificationChannel($config);
                        break;
                    case 'email':
                        $this->channels[$name] = new EmailNotificationChannel($config);
                        break;
                    case 'webhook':
                        $this->channels[$name] = new WebhookNotificationChannel($config);
                        break;
                }
            }
        }
    }
    
    /**
     * Send to specific channel
     */
    private function sendToChannel($channelName, $message, $level) {
        if (!isset($this->channels[$channelName])) {
            return false;
        }
        
        try {
            return $this->channels[$channelName]->send($message, $level);
        } catch (Exception $e) {
            error_log("Notification channel '{$channelName}' error: " . $e->getMessage());
            return false;
        }
    }
}

/**
 * Log Notification Channel
 */
class LogNotificationChannel {
    private $logFile;
    
    public function __construct($config = []) {
        $this->logFile = $config['log_file'] ?? sys_get_temp_dir() . '/mdm_notifications.log';
    }
    
    public function send($message, $level) {
        $timestamp = date('Y-m-d H:i:s');
        $levelUpper = strtoupper($level);
        
        if (is_array($message)) {
            $messageText = json_encode($message, JSON_UNESCAPED_UNICODE);
        } else {
            $messageText = $message;
        }
        
        $logEntry = "[{$timestamp}] {$levelUpper}: {$messageText}" . PHP_EOL;
        
        return file_put_contents($this->logFile, $logEntry, FILE_APPEND | LOCK_EX) !== false;
    }
}

/**
 * Email Notification Channel
 */
class EmailNotificationChannel {
    private $config;
    
    public function __construct($config = []) {
        $this->config = array_merge([
            'smtp_host' => 'localhost',
            'smtp_port' => 587,
            'smtp_username' => '',
            'smtp_password' => '',
            'from_email' => 'noreply@example.com',
            'from_name' => 'MDM System',
            'to_emails' => []
        ], $config);
    }
    
    public function send($message, $level) {
        if (empty($this->config['to_emails'])) {
            return false;
        }
        
        $subject = $this->getSubject($message, $level);
        $body = $this->formatEmailBody($message, $level);
        
        // Simple mail implementation (you might want to use a proper mail library)
        $headers = [
            'From: ' . $this->config['from_name'] . ' <' . $this->config['from_email'] . '>',
            'Content-Type: text/html; charset=UTF-8',
            'X-Mailer: MDM Notification System'
        ];
        
        $success = true;
        foreach ($this->config['to_emails'] as $email) {
            if (!mail($email, $subject, $body, implode("\r\n", $headers))) {
                $success = false;
            }
        }
        
        return $success;
    }
    
    private function getSubject($message, $level) {
        $prefix = strtoupper($level) . ': ';
        
        if (is_array($message) && isset($message['type'])) {
            switch ($message['type']) {
                case 'data_quality_alert':
                    return $prefix . 'Data Quality Alert - ' . $message['metric'];
                case 'stock_alert':
                    return $prefix . 'Stock Alert - SKU ' . $message['sku'];
                case 'system_update':
                    return $prefix . 'System Update - ' . $message['update_type'];
                default:
                    return $prefix . 'MDM Notification';
            }
        }
        
        return $prefix . 'MDM Notification';
    }
    
    private function formatEmailBody($message, $level) {
        if (is_string($message)) {
            return "<p>{$message}</p>";
        }
        
        $html = "<h3>MDM System Notification</h3>";
        $html .= "<p><strong>Level:</strong> " . strtoupper($level) . "</p>";
        
        if (isset($message['message'])) {
            $html .= "<p><strong>Message:</strong> {$message['message']}</p>";
        }
        
        if (isset($message['timestamp'])) {
            $html .= "<p><strong>Time:</strong> {$message['timestamp']}</p>";
        }
        
        if (isset($message['context']) && !empty($message['context'])) {
            $html .= "<h4>Context:</h4>";
            $html .= "<pre>" . json_encode($message['context'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "</pre>";
        }
        
        return $html;
    }
}

/**
 * Webhook Notification Channel
 */
class WebhookNotificationChannel {
    private $config;
    
    public function __construct($config = []) {
        $this->config = array_merge([
            'url' => '',
            'method' => 'POST',
            'headers' => ['Content-Type: application/json'],
            'timeout' => 30
        ], $config);
    }
    
    public function send($message, $level) {
        if (empty($this->config['url'])) {
            return false;
        }
        
        $payload = [
            'level' => $level,
            'message' => $message,
            'timestamp' => date('c'),
            'source' => 'mdm_system'
        ];
        
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $this->config['url'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $this->config['timeout'],
            CURLOPT_CUSTOMREQUEST => $this->config['method'],
            CURLOPT_POSTFIELDS => json_encode($payload),
            CURLOPT_HTTPHEADER => $this->config['headers']
        ]);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        
        if ($error) {
            error_log("Webhook notification error: " . $error);
            return false;
        }
        
        return $httpCode >= 200 && $httpCode < 300;
    }
}

/**
 * Slack Notification Channel (example of custom channel)
 */
class SlackNotificationChannel {
    private $webhookUrl;
    private $channel;
    private $username;
    
    public function __construct($config = []) {
        $this->webhookUrl = $config['webhook_url'] ?? '';
        $this->channel = $config['channel'] ?? '#general';
        $this->username = $config['username'] ?? 'MDM Bot';
    }
    
    public function send($message, $level) {
        if (empty($this->webhookUrl)) {
            return false;
        }
        
        $color = $this->getLevelColor($level);
        $text = is_array($message) ? json_encode($message, JSON_UNESCAPED_UNICODE) : $message;
        
        $payload = [
            'channel' => $this->channel,
            'username' => $this->username,
            'attachments' => [
                [
                    'color' => $color,
                    'title' => 'MDM System Notification',
                    'text' => $text,
                    'ts' => time()
                ]
            ]
        ];
        
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $this->webhookUrl,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($payload),
            CURLOPT_HTTPHEADER => ['Content-Type: application/json']
        ]);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        return $httpCode === 200;
    }
    
    private function getLevelColor($level) {
        switch ($level) {
            case 'critical':
                return 'danger';
            case 'warning':
                return 'warning';
            case 'info':
                return 'good';
            default:
                return '#36a64f';
        }
    }
}
?>