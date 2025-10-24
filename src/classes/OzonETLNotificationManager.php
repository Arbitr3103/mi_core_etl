<?php
/**
 * OzonETLNotificationManager Class - Error notification system for ETL processes
 * 
 * Implements comprehensive error notification system with:
 * - Administrator notifications for critical errors
 * - Escalation procedures for prolonged failures
 * - Error categorization and priority levels
 * - Multiple notification channels (email, SMS, webhook)
 * 
 * @version 1.0
 * @author Manhattan System
 */

class OzonETLNotificationManager {
    
    private $pdo;
    private $logger;
    private $config;
    
    // Error priority levels
    const PRIORITY_LOW = 1;
    const PRIORITY_MEDIUM = 2;
    const PRIORITY_HIGH = 3;
    const PRIORITY_CRITICAL = 4;
    const PRIORITY_EMERGENCY = 5;
    
    // Notification channels
    const CHANNEL_EMAIL = 'email';
    const CHANNEL_SMS = 'sms';
    const CHANNEL_WEBHOOK = 'webhook';
    const CHANNEL_SLACK = 'slack';
    const CHANNEL_TELEGRAM = 'telegram';
    
    // Error categories for notification routing
    const CATEGORIES = [
        'API_FAILURE' => ['priority' => self::PRIORITY_HIGH, 'escalation_minutes' => 30],
        'DATA_CORRUPTION' => ['priority' => self::PRIORITY_CRITICAL, 'escalation_minutes' => 15],
        'AUTHENTICATION_ERROR' => ['priority' => self::PRIORITY_CRITICAL, 'escalation_minutes' => 10],
        'SYSTEM_RESOURCE_ERROR' => ['priority' => self::PRIORITY_CRITICAL, 'escalation_minutes' => 15],
        'ETL_TIMEOUT' => ['priority' => self::PRIORITY_HIGH, 'escalation_minutes' => 60],
        'DATABASE_ERROR' => ['priority' => self::PRIORITY_CRITICAL, 'escalation_minutes' => 20],
        'NETWORK_ERROR' => ['priority' => self::PRIORITY_MEDIUM, 'escalation_minutes' => 45],
        'CONFIGURATION_ERROR' => ['priority' => self::PRIORITY_HIGH, 'escalation_minutes' => 30],
        'BUSINESS_LOGIC_ERROR' => ['priority' => self::PRIORITY_MEDIUM, 'escalation_minutes' => 60]
    ];
    
    /**
     * Constructor
     * 
     * @param PDO $pdo Database connection
     * @param OzonETLLogger $logger Logger instance
     * @param array $config Configuration options
     */
    public function __construct(PDO $pdo, $logger = null, array $config = []) {
        $this->pdo = $pdo;
        $this->logger = $logger;
        $this->config = array_merge($this->getDefaultConfig(), $config);
        $this->initializeNotificationTables();
    }
    
    /**
     * Get default configuration
     */
    private function getDefaultConfig(): array {
        return [
            'enable_notifications' => true,
            'enable_escalation' => true,
            'max_escalation_level' => 3,
            'escalation_multiplier' => 2,
            'notification_cooldown_minutes' => 15,
            'batch_notifications' => true,
            'batch_interval_minutes' => 5,
            'enable_digest' => true,
            'digest_interval_hours' => 4,
            'channels' => [
                self::CHANNEL_EMAIL => [
                    'enabled' => true,
                    'smtp_host' => 'localhost',
                    'smtp_port' => 587,
                    'smtp_username' => '',
                    'smtp_password' => '',
                    'from_email' => 'noreply@manhattan-system.com',
                    'from_name' => 'Manhattan ETL System'
                ],
                self::CHANNEL_SMS => [
                    'enabled' => false,
                    'provider' => 'twilio',
                    'api_key' => '',
                    'api_secret' => '',
                    'from_number' => ''
                ],
                self::CHANNEL_WEBHOOK => [
                    'enabled' => false,
                    'urls' => [],
                    'timeout_seconds' => 10,
                    'retry_attempts' => 3
                ],
                self::CHANNEL_SLACK => [
                    'enabled' => false,
                    'webhook_url' => '',
                    'channel' => '#alerts',
                    'username' => 'ETL Bot'
                ]
            ],
            'recipients' => [
                'administrators' => [
                    ['email' => 'admin@manhattan-system.com', 'phone' => '', 'escalation_level' => 1],
                ],
                'developers' => [
                    ['email' => 'dev@manhattan-system.com', 'phone' => '', 'escalation_level' => 2],
                ],
                'managers' => [
                    ['email' => 'manager@manhattan-system.com', 'phone' => '', 'escalation_level' => 3],
                ]
            ]
        ];
    }
    
    /**
     * Initialize notification tables
     */
    private function initializeNotificationTables(): void {
        try {
            // Notification queue table
            $this->pdo->exec("
                CREATE TABLE IF NOT EXISTS ozon_etl_notifications (
                    id BIGINT AUTO_INCREMENT PRIMARY KEY,
                    etl_id VARCHAR(255) NULL,
                    error_category VARCHAR(100) NOT NULL,
                    priority_level INT NOT NULL,
                    title VARCHAR(255) NOT NULL,
                    message TEXT NOT NULL,
                    error_details JSON NULL,
                    channels JSON NOT NULL,
                    recipients JSON NOT NULL,
                    status ENUM('pending', 'sent', 'failed', 'cancelled') DEFAULT 'pending',
                    attempts INT DEFAULT 0,
                    max_attempts INT DEFAULT 3,
                    scheduled_at TIMESTAMP NOT NULL,
                    sent_at TIMESTAMP NULL,
                    escalation_level INT DEFAULT 1,
                    next_escalation_at TIMESTAMP NULL,
                    parent_notification_id BIGINT NULL,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    
                    INDEX idx_etl_id (etl_id),
                    INDEX idx_status (status),
                    INDEX idx_priority_level (priority_level),
                    INDEX idx_scheduled_at (scheduled_at),
                    INDEX idx_next_escalation_at (next_escalation_at),
                    INDEX idx_error_category (error_category),
                    
                    FOREIGN KEY (parent_notification_id) REFERENCES ozon_etl_notifications(id) ON DELETE SET NULL
                ) ENGINE=InnoDB
            ");
            
            // Notification delivery log
            $this->pdo->exec("
                CREATE TABLE IF NOT EXISTS ozon_etl_notification_delivery_log (
                    id BIGINT AUTO_INCREMENT PRIMARY KEY,
                    notification_id BIGINT NOT NULL,
                    channel VARCHAR(50) NOT NULL,
                    recipient VARCHAR(255) NOT NULL,
                    status ENUM('success', 'failed', 'pending') NOT NULL,
                    response_data JSON NULL,
                    error_message TEXT NULL,
                    delivery_time_ms INT NULL,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    
                    INDEX idx_notification_id (notification_id),
                    INDEX idx_channel (channel),
                    INDEX idx_status (status),
                    INDEX idx_created_at (created_at),
                    
                    FOREIGN KEY (notification_id) REFERENCES ozon_etl_notifications(id) ON DELETE CASCADE
                ) ENGINE=InnoDB
            ");
            
            // Notification rules and templates
            $this->pdo->exec("
                CREATE TABLE IF NOT EXISTS ozon_etl_notification_rules (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    rule_name VARCHAR(100) NOT NULL UNIQUE,
                    error_category VARCHAR(100) NOT NULL,
                    priority_level INT NOT NULL,
                    channels JSON NOT NULL,
                    recipients JSON NOT NULL,
                    template_subject VARCHAR(255) NOT NULL,
                    template_body TEXT NOT NULL,
                    escalation_enabled BOOLEAN DEFAULT TRUE,
                    escalation_minutes INT DEFAULT 30,
                    cooldown_minutes INT DEFAULT 15,
                    is_active BOOLEAN DEFAULT TRUE,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    
                    INDEX idx_error_category (error_category),
                    INDEX idx_priority_level (priority_level),
                    INDEX idx_is_active (is_active)
                ) ENGINE=InnoDB
            ");
            
            // Notification statistics
            $this->pdo->exec("
                CREATE TABLE IF NOT EXISTS ozon_etl_notification_stats (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    date_hour TIMESTAMP NOT NULL,
                    error_category VARCHAR(100) NOT NULL,
                    priority_level INT NOT NULL,
                    channel VARCHAR(50) NOT NULL,
                    total_sent INT DEFAULT 0,
                    total_failed INT DEFAULT 0,
                    avg_delivery_time_ms DECIMAL(10,2) DEFAULT 0,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    
                    UNIQUE KEY unique_stats (date_hour, error_category, priority_level, channel),
                    INDEX idx_date_hour (date_hour),
                    INDEX idx_error_category (error_category)
                ) ENGINE=InnoDB
            ");
            
        } catch (Exception $e) {
            if ($this->logger) {
                $this->logger->error("Failed to initialize notification tables", ['error' => $e->getMessage()]);
            } else {
                error_log("Failed to initialize notification tables: " . $e->getMessage());
            }
        }
    }
    
    /**
     * Send critical error notification
     * 
     * @param string $etlId ETL process identifier
     * @param string $errorCategory Error category
     * @param string $title Notification title
     * @param string $message Notification message
     * @param array $errorDetails Additional error details
     * @param array $options Override options
     * @return bool Success status
     */
    public function sendCriticalErrorNotification(string $etlId, string $errorCategory, string $title, string $message, array $errorDetails = [], array $options = []): bool {
        if (!$this->config['enable_notifications']) {
            return true;
        }
        
        try {
            // Check for cooldown period
            if ($this->isInCooldownPeriod($etlId, $errorCategory)) {
                if ($this->logger) {
                    $this->logger->info("Notification skipped due to cooldown period", [
                        'etl_id' => $etlId,
                        'error_category' => $errorCategory
                    ]);
                }
                return true;
            }
            
            // Determine priority and escalation settings
            $categoryConfig = self::CATEGORIES[$errorCategory] ?? ['priority' => self::PRIORITY_MEDIUM, 'escalation_minutes' => 30];
            $priority = $options['priority'] ?? $categoryConfig['priority'];
            
            // Get notification rule or use defaults
            $rule = $this->getNotificationRule($errorCategory) ?? $this->getDefaultRule($errorCategory, $priority);
            
            // Create notification
            $notificationId = $this->createNotification([
                'etl_id' => $etlId,
                'error_category' => $errorCategory,
                'priority_level' => $priority,
                'title' => $title,
                'message' => $message,
                'error_details' => $errorDetails,
                'channels' => $rule['channels'],
                'recipients' => $rule['recipients'],
                'escalation_minutes' => $categoryConfig['escalation_minutes']
            ]);
            
            if ($notificationId) {
                // Send immediately for critical/emergency priorities
                if ($priority >= self::PRIORITY_CRITICAL) {
                    return $this->processNotification($notificationId);
                } else {
                    // Queue for batch processing
                    return true;
                }
            }
            
            return false;
            
        } catch (Exception $e) {
            if ($this->logger) {
                $this->logger->error("Failed to send critical error notification", [
                    'etl_id' => $etlId,
                    'error_category' => $errorCategory,
                    'error' => $e->getMessage()
                ]);
            }
            return false;
        }
    }
    
    /**
     * Check if notification is in cooldown period
     */
    private function isInCooldownPeriod(string $etlId, string $errorCategory): bool {
        try {
            $cooldownMinutes = $this->config['notification_cooldown_minutes'];
            
            $sql = "SELECT COUNT(*) as count 
                    FROM ozon_etl_notifications 
                    WHERE etl_id = :etl_id 
                    AND error_category = :error_category 
                    AND status = 'sent'
                    AND sent_at >= DATE_SUB(NOW(), INTERVAL :cooldown_minutes MINUTE)";
            
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute([
                'etl_id' => $etlId,
                'error_category' => $errorCategory,
                'cooldown_minutes' => $cooldownMinutes
            ]);
            
            $result = $stmt->fetch();
            return $result['count'] > 0;
            
        } catch (Exception $e) {
            return false; // Fail open
        }
    }
    
    /**
     * Get notification rule for error category
     */
    private function getNotificationRule(string $errorCategory): ?array {
        try {
            $sql = "SELECT * FROM ozon_etl_notification_rules 
                    WHERE error_category = :error_category 
                    AND is_active = TRUE 
                    ORDER BY priority_level DESC 
                    LIMIT 1";
            
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute(['error_category' => $errorCategory]);
            
            $rule = $stmt->fetch();
            
            if ($rule) {
                $rule['channels'] = json_decode($rule['channels'], true);
                $rule['recipients'] = json_decode($rule['recipients'], true);
                return $rule;
            }
            
        } catch (Exception $e) {
            if ($this->logger) {
                $this->logger->warning("Failed to get notification rule", [
                    'error_category' => $errorCategory,
                    'error' => $e->getMessage()
                ]);
            }
        }
        
        return null;
    }
    
    /**
     * Get default notification rule
     */
    private function getDefaultRule(string $errorCategory, int $priority): array {
        $channels = [self::CHANNEL_EMAIL];
        $recipients = $this->config['recipients']['administrators'];
        
        // Add SMS for critical/emergency priorities
        if ($priority >= self::PRIORITY_CRITICAL && $this->config['channels'][self::CHANNEL_SMS]['enabled']) {
            $channels[] = self::CHANNEL_SMS;
        }
        
        // Add webhook for all priorities if enabled
        if ($this->config['channels'][self::CHANNEL_WEBHOOK]['enabled']) {
            $channels[] = self::CHANNEL_WEBHOOK;
        }
        
        return [
            'channels' => $channels,
            'recipients' => $recipients,
            'template_subject' => "ETL Error Alert: {$errorCategory}",
            'template_body' => "An error occurred in the ETL process.\n\nCategory: {$errorCategory}\nPriority: {$priority}\n\nDetails: {{message}}"
        ];
    }
    
    /**
     * Create notification record
     */
    private function createNotification(array $data): ?int {
        try {
            $scheduledAt = date('Y-m-d H:i:s');
            $nextEscalationAt = null;
            
            if ($this->config['enable_escalation'] && isset($data['escalation_minutes'])) {
                $nextEscalationAt = date('Y-m-d H:i:s', time() + ($data['escalation_minutes'] * 60));
            }
            
            $sql = "INSERT INTO ozon_etl_notifications 
                    (etl_id, error_category, priority_level, title, message, error_details, 
                     channels, recipients, scheduled_at, next_escalation_at)
                    VALUES (:etl_id, :error_category, :priority_level, :title, :message, :error_details,
                            :channels, :recipients, :scheduled_at, :next_escalation_at)";
            
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute([
                'etl_id' => $data['etl_id'],
                'error_category' => $data['error_category'],
                'priority_level' => $data['priority_level'],
                'title' => $data['title'],
                'message' => $data['message'],
                'error_details' => json_encode($data['error_details']),
                'channels' => json_encode($data['channels']),
                'recipients' => json_encode($data['recipients']),
                'scheduled_at' => $scheduledAt,
                'next_escalation_at' => $nextEscalationAt
            ]);
            
            return $this->pdo->lastInsertId();
            
        } catch (Exception $e) {
            if ($this->logger) {
                $this->logger->error("Failed to create notification", [
                    'error' => $e->getMessage(),
                    'data' => $data
                ]);
            }
            return null;
        }
    }
    
    /**
     * Process notification (send to all channels)
     */
    private function processNotification(int $notificationId): bool {
        try {
            // Get notification details
            $notification = $this->getNotification($notificationId);
            if (!$notification) {
                return false;
            }
            
            $channels = json_decode($notification['channels'], true);
            $recipients = json_decode($notification['recipients'], true);
            $errorDetails = json_decode($notification['error_details'], true);
            
            $allSuccess = true;
            $deliveryResults = [];
            
            // Send to each channel
            foreach ($channels as $channel) {
                if (!$this->isChannelEnabled($channel)) {
                    continue;
                }
                
                $channelResults = $this->sendToChannel($channel, $notification, $recipients, $errorDetails);
                $deliveryResults = array_merge($deliveryResults, $channelResults);
                
                // Check if any delivery failed
                foreach ($channelResults as $result) {
                    if (!$result['success']) {
                        $allSuccess = false;
                    }
                }
            }
            
            // Update notification status
            $status = $allSuccess ? 'sent' : 'failed';
            $this->updateNotificationStatus($notificationId, $status, $deliveryResults);
            
            // Log delivery results
            $this->logDeliveryResults($notificationId, $deliveryResults);
            
            // Update statistics
            $this->updateNotificationStats($notification, $deliveryResults);
            
            return $allSuccess;
            
        } catch (Exception $e) {
            if ($this->logger) {
                $this->logger->error("Failed to process notification", [
                    'notification_id' => $notificationId,
                    'error' => $e->getMessage()
                ]);
            }
            return false;
        }
    }
    
    /**
     * Get notification by ID
     */
    private function getNotification(int $notificationId): ?array {
        try {
            $sql = "SELECT * FROM ozon_etl_notifications WHERE id = :id";
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute(['id' => $notificationId]);
            
            return $stmt->fetch();
            
        } catch (Exception $e) {
            return null;
        }
    }
    
    /**
     * Check if channel is enabled
     */
    private function isChannelEnabled(string $channel): bool {
        return $this->config['channels'][$channel]['enabled'] ?? false;
    }
    
    /**
     * Send notification to specific channel
     */
    private function sendToChannel(string $channel, array $notification, array $recipients, array $errorDetails): array {
        $results = [];
        
        switch ($channel) {
            case self::CHANNEL_EMAIL:
                $results = $this->sendEmailNotifications($notification, $recipients, $errorDetails);
                break;
                
            case self::CHANNEL_SMS:
                $results = $this->sendSMSNotifications($notification, $recipients, $errorDetails);
                break;
                
            case self::CHANNEL_WEBHOOK:
                $results = $this->sendWebhookNotifications($notification, $errorDetails);
                break;
                
            case self::CHANNEL_SLACK:
                $results = $this->sendSlackNotification($notification, $errorDetails);
                break;
                
            default:
                $results = [['channel' => $channel, 'success' => false, 'error' => 'Unknown channel']];
        }
        
        return $results;
    }
    
    /**
     * Send email notifications
     */
    private function sendEmailNotifications(array $notification, array $recipients, array $errorDetails): array {
        $results = [];
        $emailConfig = $this->config['channels'][self::CHANNEL_EMAIL];
        
        foreach ($recipients as $recipient) {
            if (empty($recipient['email'])) {
                continue;
            }
            
            $startTime = microtime(true);
            
            try {
                // Prepare email content
                $subject = $this->renderTemplate($notification['title'], $notification, $errorDetails);
                $body = $this->renderTemplate($notification['message'], $notification, $errorDetails);
                $body .= "\n\n" . $this->formatErrorDetails($errorDetails);
                
                // Send email (simplified implementation - replace with actual email service)
                $success = $this->sendEmail($recipient['email'], $subject, $body, $emailConfig);
                
                $deliveryTime = (microtime(true) - $startTime) * 1000;
                
                $results[] = [
                    'channel' => self::CHANNEL_EMAIL,
                    'recipient' => $recipient['email'],
                    'success' => $success,
                    'delivery_time_ms' => $deliveryTime,
                    'error' => $success ? null : 'Email delivery failed'
                ];
                
            } catch (Exception $e) {
                $deliveryTime = (microtime(true) - $startTime) * 1000;
                
                $results[] = [
                    'channel' => self::CHANNEL_EMAIL,
                    'recipient' => $recipient['email'],
                    'success' => false,
                    'delivery_time_ms' => $deliveryTime,
                    'error' => $e->getMessage()
                ];
            }
        }
        
        return $results;
    }
    
    /**
     * Send SMS notifications
     */
    private function sendSMSNotifications(array $notification, array $recipients, array $errorDetails): array {
        $results = [];
        $smsConfig = $this->config['channels'][self::CHANNEL_SMS];
        
        foreach ($recipients as $recipient) {
            if (empty($recipient['phone'])) {
                continue;
            }
            
            $startTime = microtime(true);
            
            try {
                // Prepare SMS content (keep it short)
                $message = $this->renderSMSTemplate($notification, $errorDetails);
                
                // Send SMS (simplified implementation - replace with actual SMS service)
                $success = $this->sendSMS($recipient['phone'], $message, $smsConfig);
                
                $deliveryTime = (microtime(true) - $startTime) * 1000;
                
                $results[] = [
                    'channel' => self::CHANNEL_SMS,
                    'recipient' => $recipient['phone'],
                    'success' => $success,
                    'delivery_time_ms' => $deliveryTime,
                    'error' => $success ? null : 'SMS delivery failed'
                ];
                
            } catch (Exception $e) {
                $deliveryTime = (microtime(true) - $startTime) * 1000;
                
                $results[] = [
                    'channel' => self::CHANNEL_SMS,
                    'recipient' => $recipient['phone'],
                    'success' => false,
                    'delivery_time_ms' => $deliveryTime,
                    'error' => $e->getMessage()
                ];
            }
        }
        
        return $results;
    }
    
    /**
     * Send webhook notifications
     */
    private function sendWebhookNotifications(array $notification, array $errorDetails): array {
        $results = [];
        $webhookConfig = $this->config['channels'][self::CHANNEL_WEBHOOK];
        
        foreach ($webhookConfig['urls'] as $url) {
            $startTime = microtime(true);
            
            try {
                $payload = [
                    'notification_id' => $notification['id'],
                    'etl_id' => $notification['etl_id'],
                    'error_category' => $notification['error_category'],
                    'priority_level' => $notification['priority_level'],
                    'title' => $notification['title'],
                    'message' => $notification['message'],
                    'error_details' => $errorDetails,
                    'timestamp' => $notification['created_at']
                ];
                
                $success = $this->sendWebhook($url, $payload, $webhookConfig);
                
                $deliveryTime = (microtime(true) - $startTime) * 1000;
                
                $results[] = [
                    'channel' => self::CHANNEL_WEBHOOK,
                    'recipient' => $url,
                    'success' => $success,
                    'delivery_time_ms' => $deliveryTime,
                    'error' => $success ? null : 'Webhook delivery failed'
                ];
                
            } catch (Exception $e) {
                $deliveryTime = (microtime(true) - $startTime) * 1000;
                
                $results[] = [
                    'channel' => self::CHANNEL_WEBHOOK,
                    'recipient' => $url,
                    'success' => false,
                    'delivery_time_ms' => $deliveryTime,
                    'error' => $e->getMessage()
                ];
            }
        }
        
        return $results;
    }
    
    /**
     * Send Slack notification
     */
    private function sendSlackNotification(array $notification, array $errorDetails): array {
        $slackConfig = $this->config['channels'][self::CHANNEL_SLACK];
        $startTime = microtime(true);
        
        try {
            $payload = [
                'channel' => $slackConfig['channel'],
                'username' => $slackConfig['username'],
                'text' => $notification['title'],
                'attachments' => [
                    [
                        'color' => $this->getPriorityColor($notification['priority_level']),
                        'fields' => [
                            [
                                'title' => 'ETL ID',
                                'value' => $notification['etl_id'],
                                'short' => true
                            ],
                            [
                                'title' => 'Category',
                                'value' => $notification['error_category'],
                                'short' => true
                            ],
                            [
                                'title' => 'Priority',
                                'value' => $this->getPriorityName($notification['priority_level']),
                                'short' => true
                            ],
                            [
                                'title' => 'Message',
                                'value' => $notification['message'],
                                'short' => false
                            ]
                        ],
                        'ts' => strtotime($notification['created_at'])
                    ]
                ]
            ];
            
            $success = $this->sendSlackWebhook($slackConfig['webhook_url'], $payload);
            
            $deliveryTime = (microtime(true) - $startTime) * 1000;
            
            return [[
                'channel' => self::CHANNEL_SLACK,
                'recipient' => $slackConfig['channel'],
                'success' => $success,
                'delivery_time_ms' => $deliveryTime,
                'error' => $success ? null : 'Slack delivery failed'
            ]];
            
        } catch (Exception $e) {
            $deliveryTime = (microtime(true) - $startTime) * 1000;
            
            return [[
                'channel' => self::CHANNEL_SLACK,
                'recipient' => $slackConfig['channel'],
                'success' => false,
                'delivery_time_ms' => $deliveryTime,
                'error' => $e->getMessage()
            ]];
        }
    }
    
    /**
     * Render template with variables
     */
    private function renderTemplate(string $template, array $notification, array $errorDetails): string {
        $variables = array_merge($notification, $errorDetails, [
            'timestamp' => date('Y-m-d H:i:s'),
            'priority_name' => $this->getPriorityName($notification['priority_level'])
        ]);
        
        foreach ($variables as $key => $value) {
            if (is_scalar($value)) {
                $template = str_replace("{{$key}}", $value, $template);
            }
        }
        
        return $template;
    }
    
    /**
     * Render SMS template (short format)
     */
    private function renderSMSTemplate(array $notification, array $errorDetails): string {
        $priority = $this->getPriorityName($notification['priority_level']);
        return "ETL Alert [{$priority}]: {$notification['error_category']} - {$notification['title']}. ETL ID: {$notification['etl_id']}";
    }
    
    /**
     * Format error details for display
     */
    private function formatErrorDetails(array $errorDetails): string {
        if (empty($errorDetails)) {
            return '';
        }
        
        $formatted = "\n--- Error Details ---\n";
        foreach ($errorDetails as $key => $value) {
            if (is_array($value) || is_object($value)) {
                $value = json_encode($value, JSON_PRETTY_PRINT);
            }
            $formatted .= "{$key}: {$value}\n";
        }
        
        return $formatted;
    }
    
    /**
     * Get priority name
     */
    private function getPriorityName(int $priority): string {
        $names = [
            self::PRIORITY_LOW => 'LOW',
            self::PRIORITY_MEDIUM => 'MEDIUM',
            self::PRIORITY_HIGH => 'HIGH',
            self::PRIORITY_CRITICAL => 'CRITICAL',
            self::PRIORITY_EMERGENCY => 'EMERGENCY'
        ];
        
        return $names[$priority] ?? 'UNKNOWN';
    }
    
    /**
     * Get priority color for Slack
     */
    private function getPriorityColor(int $priority): string {
        $colors = [
            self::PRIORITY_LOW => 'good',
            self::PRIORITY_MEDIUM => 'warning',
            self::PRIORITY_HIGH => 'warning',
            self::PRIORITY_CRITICAL => 'danger',
            self::PRIORITY_EMERGENCY => 'danger'
        ];
        
        return $colors[$priority] ?? 'warning';
    }
    
    /**
     * Send email (simplified implementation)
     */
    private function sendEmail(string $to, string $subject, string $body, array $config): bool {
        // This is a simplified implementation
        // In production, use a proper email service like PHPMailer, SwiftMailer, or a service like SendGrid
        
        $headers = [
            'From: ' . $config['from_name'] . ' <' . $config['from_email'] . '>',
            'Reply-To: ' . $config['from_email'],
            'Content-Type: text/plain; charset=UTF-8',
            'X-Mailer: Ozon ETL Notification System'
        ];
        
        return mail($to, $subject, $body, implode("\r\n", $headers));
    }
    
    /**
     * Send SMS (simplified implementation)
     */
    private function sendSMS(string $to, string $message, array $config): bool {
        // This is a simplified implementation
        // In production, integrate with SMS providers like Twilio, AWS SNS, etc.
        
        if ($this->logger) {
            $this->logger->info("SMS would be sent", [
                'to' => $to,
                'message' => $message,
                'provider' => $config['provider']
            ]);
        }
        
        return true; // Simulate success
    }
    
    /**
     * Send webhook
     */
    private function sendWebhook(string $url, array $payload, array $config): bool {
        $ch = curl_init();
        
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($payload),
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'User-Agent: Ozon ETL Notification System'
            ],
            CURLOPT_TIMEOUT => $config['timeout_seconds'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_SSL_VERIFYPEER => false
        ]);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        return $httpCode >= 200 && $httpCode < 300;
    }
    
    /**
     * Send Slack webhook
     */
    private function sendSlackWebhook(string $webhookUrl, array $payload): bool {
        return $this->sendWebhook($webhookUrl, $payload, ['timeout_seconds' => 10]);
    }
    
    /**
     * Update notification status
     */
    private function updateNotificationStatus(int $notificationId, string $status, array $deliveryResults): void {
        try {
            $sql = "UPDATE ozon_etl_notifications 
                    SET status = :status, sent_at = NOW(), attempts = attempts + 1
                    WHERE id = :id";
            
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute([
                'id' => $notificationId,
                'status' => $status
            ]);
            
        } catch (Exception $e) {
            if ($this->logger) {
                $this->logger->error("Failed to update notification status", [
                    'notification_id' => $notificationId,
                    'error' => $e->getMessage()
                ]);
            }
        }
    }
    
    /**
     * Log delivery results
     */
    private function logDeliveryResults(int $notificationId, array $deliveryResults): void {
        try {
            foreach ($deliveryResults as $result) {
                $sql = "INSERT INTO ozon_etl_notification_delivery_log 
                        (notification_id, channel, recipient, status, response_data, error_message, delivery_time_ms)
                        VALUES (:notification_id, :channel, :recipient, :status, :response_data, :error_message, :delivery_time_ms)";
                
                $stmt = $this->pdo->prepare($sql);
                $stmt->execute([
                    'notification_id' => $notificationId,
                    'channel' => $result['channel'],
                    'recipient' => $result['recipient'],
                    'status' => $result['success'] ? 'success' : 'failed',
                    'response_data' => json_encode($result['response_data'] ?? null),
                    'error_message' => $result['error'] ?? null,
                    'delivery_time_ms' => $result['delivery_time_ms'] ?? null
                ]);
            }
            
        } catch (Exception $e) {
            if ($this->logger) {
                $this->logger->error("Failed to log delivery results", [
                    'notification_id' => $notificationId,
                    'error' => $e->getMessage()
                ]);
            }
        }
    }
    
    /**
     * Update notification statistics
     */
    private function updateNotificationStats(array $notification, array $deliveryResults): void {
        try {
            $dateHour = date('Y-m-d H:00:00');
            
            foreach ($deliveryResults as $result) {
                $sql = "INSERT INTO ozon_etl_notification_stats 
                        (date_hour, error_category, priority_level, channel, total_sent, total_failed, avg_delivery_time_ms)
                        VALUES (:date_hour, :error_category, :priority_level, :channel, :total_sent, :total_failed, :avg_delivery_time_ms)
                        ON DUPLICATE KEY UPDATE
                        total_sent = total_sent + VALUES(total_sent),
                        total_failed = total_failed + VALUES(total_failed),
                        avg_delivery_time_ms = (avg_delivery_time_ms + VALUES(avg_delivery_time_ms)) / 2";
                
                $stmt = $this->pdo->prepare($sql);
                $stmt->execute([
                    'date_hour' => $dateHour,
                    'error_category' => $notification['error_category'],
                    'priority_level' => $notification['priority_level'],
                    'channel' => $result['channel'],
                    'total_sent' => $result['success'] ? 1 : 0,
                    'total_failed' => $result['success'] ? 0 : 1,
                    'avg_delivery_time_ms' => $result['delivery_time_ms'] ?? 0
                ]);
            }
            
        } catch (Exception $e) {
            if ($this->logger) {
                $this->logger->error("Failed to update notification stats", [
                    'error' => $e->getMessage()
                ]);
            }
        }
    }
    
    /**
     * Process escalation for overdue notifications
     */
    public function processEscalations(): int {
        if (!$this->config['enable_escalation']) {
            return 0;
        }
        
        try {
            $sql = "SELECT * FROM ozon_etl_notifications 
                    WHERE status = 'sent' 
                    AND next_escalation_at IS NOT NULL 
                    AND next_escalation_at <= NOW()
                    AND escalation_level < :max_escalation_level";
            
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute(['max_escalation_level' => $this->config['max_escalation_level']]);
            
            $notifications = $stmt->fetchAll();
            $escalatedCount = 0;
            
            foreach ($notifications as $notification) {
                if ($this->escalateNotification($notification)) {
                    $escalatedCount++;
                }
            }
            
            return $escalatedCount;
            
        } catch (Exception $e) {
            if ($this->logger) {
                $this->logger->error("Failed to process escalations", [
                    'error' => $e->getMessage()
                ]);
            }
            return 0;
        }
    }
    
    /**
     * Escalate notification to next level
     */
    private function escalateNotification(array $notification): bool {
        try {
            $nextLevel = $notification['escalation_level'] + 1;
            $escalationMultiplier = $this->config['escalation_multiplier'];
            
            // Get escalation recipients
            $escalationRecipients = $this->getEscalationRecipients($nextLevel);
            
            if (empty($escalationRecipients)) {
                return false;
            }
            
            // Create escalated notification
            $escalatedId = $this->createNotification([
                'etl_id' => $notification['etl_id'],
                'error_category' => $notification['error_category'],
                'priority_level' => min($notification['priority_level'] + 1, self::PRIORITY_EMERGENCY),
                'title' => "[ESCALATED] " . $notification['title'],
                'message' => "This is an escalated notification (Level {$nextLevel}).\n\n" . $notification['message'],
                'error_details' => json_decode($notification['error_details'], true),
                'channels' => [self::CHANNEL_EMAIL, self::CHANNEL_SMS], // Use all available channels for escalation
                'recipients' => $escalationRecipients,
                'escalation_minutes' => $notification['escalation_minutes'] * $escalationMultiplier
            ]);
            
            if ($escalatedId) {
                // Update original notification
                $sql = "UPDATE ozon_etl_notifications 
                        SET escalation_level = :escalation_level,
                            next_escalation_at = CASE 
                                WHEN :escalation_level < :max_level THEN DATE_ADD(NOW(), INTERVAL :escalation_minutes MINUTE)
                                ELSE NULL 
                            END
                        WHERE id = :id";
                
                $stmt = $this->pdo->prepare($sql);
                $stmt->execute([
                    'id' => $notification['id'],
                    'escalation_level' => $nextLevel,
                    'max_level' => $this->config['max_escalation_level'],
                    'escalation_minutes' => $notification['escalation_minutes'] * $escalationMultiplier
                ]);
                
                // Send escalated notification
                return $this->processNotification($escalatedId);
            }
            
            return false;
            
        } catch (Exception $e) {
            if ($this->logger) {
                $this->logger->error("Failed to escalate notification", [
                    'notification_id' => $notification['id'],
                    'error' => $e->getMessage()
                ]);
            }
            return false;
        }
    }
    
    /**
     * Get escalation recipients for level
     */
    private function getEscalationRecipients(int $level): array {
        $allRecipients = array_merge(
            $this->config['recipients']['administrators'] ?? [],
            $this->config['recipients']['developers'] ?? [],
            $this->config['recipients']['managers'] ?? []
        );
        
        $escalationRecipients = [];
        
        foreach ($allRecipients as $recipient) {
            if (($recipient['escalation_level'] ?? 1) <= $level) {
                $escalationRecipients[] = $recipient;
            }
        }
        
        return $escalationRecipients;
    }
    
    /**
     * Process pending notifications (for batch processing)
     */
    public function processPendingNotifications(): int {
        try {
            $sql = "SELECT * FROM ozon_etl_notifications 
                    WHERE status = 'pending' 
                    AND scheduled_at <= NOW()
                    ORDER BY priority_level DESC, created_at ASC
                    LIMIT 50";
            
            $stmt = $this->pdo->query($sql);
            $notifications = $stmt->fetchAll();
            
            $processedCount = 0;
            
            foreach ($notifications as $notification) {
                if ($this->processNotification($notification['id'])) {
                    $processedCount++;
                }
            }
            
            return $processedCount;
            
        } catch (Exception $e) {
            if ($this->logger) {
                $this->logger->error("Failed to process pending notifications", [
                    'error' => $e->getMessage()
                ]);
            }
            return 0;
        }
    }
    
    /**
     * Get notification statistics
     */
    public function getNotificationStatistics(int $hours = 24): array {
        try {
            $sql = "SELECT 
                        error_category,
                        priority_level,
                        channel,
                        SUM(total_sent) as total_sent,
                        SUM(total_failed) as total_failed,
                        AVG(avg_delivery_time_ms) as avg_delivery_time_ms
                    FROM ozon_etl_notification_stats 
                    WHERE date_hour >= DATE_SUB(NOW(), INTERVAL :hours HOUR)
                    GROUP BY error_category, priority_level, channel
                    ORDER BY total_sent DESC";
            
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute(['hours' => $hours]);
            
            return $stmt->fetchAll();
            
        } catch (Exception $e) {
            if ($this->logger) {
                $this->logger->error("Failed to get notification statistics", [
                    'error' => $e->getMessage()
                ]);
            }
            return [];
        }
    }
    
    /**
     * Clean up old notifications
     */
    public function cleanupOldNotifications(int $daysToKeep = 90): int {
        try {
            $sql = "DELETE FROM ozon_etl_notifications 
                    WHERE created_at < DATE_SUB(NOW(), INTERVAL :days DAY)";
            
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute(['days' => $daysToKeep]);
            
            return $stmt->rowCount();
            
        } catch (Exception $e) {
            if ($this->logger) {
                $this->logger->error("Failed to cleanup old notifications", [
                    'error' => $e->getMessage()
                ]);
            }
            return 0;
        }
    }
}