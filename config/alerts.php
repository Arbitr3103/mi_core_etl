<?php
/**
 * Alert Configuration for Production Monitoring
 * 
 * Configures alert thresholds and notification channels
 */

return [
    // Alert thresholds
    'thresholds' => [
        'response_time_warning' => 3000,    // 3 seconds
        'response_time_critical' => 5000,   // 5 seconds
        'error_rate_warning' => 5,          // 5% error rate
        'error_rate_critical' => 10,        // 10% error rate
        'disk_usage_warning' => 80,         // 80% disk usage
        'disk_usage_critical' => 90,        // 90% disk usage
        'memory_usage_warning' => 80,       // 80% memory usage
        'memory_usage_critical' => 90,      // 90% memory usage
        'consecutive_failures' => 3,        // 3 consecutive failures trigger alert
    ],
    
    // Notification channels
    'notifications' => [
        'email' => [
            'enabled' => !empty($_ENV['EMAIL_ALERTS_TO']),
            'to' => $_ENV['EMAIL_ALERTS_TO'] ?? '',
            'from' => $_ENV['EMAIL_ALERTS_FROM'] ?? 'noreply@market-mi.ru',
            'smtp' => [
                'host' => $_ENV['SMTP_HOST'] ?? 'localhost',
                'port' => $_ENV['SMTP_PORT'] ?? 587,
                'username' => $_ENV['SMTP_USERNAME'] ?? '',
                'password' => $_ENV['SMTP_PASSWORD'] ?? '',
                'encryption' => $_ENV['SMTP_ENCRYPTION'] ?? 'tls',
            ]
        ],
        
        'slack' => [
            'enabled' => !empty($_ENV['SLACK_WEBHOOK_URL']),
            'webhook_url' => $_ENV['SLACK_WEBHOOK_URL'] ?? '',
            'channel' => $_ENV['SLACK_CHANNEL'] ?? '#alerts',
            'username' => $_ENV['SLACK_USERNAME'] ?? 'Warehouse Dashboard Monitor',
        ],
        
        'telegram' => [
            'enabled' => !empty($_ENV['TELEGRAM_BOT_TOKEN']) && !empty($_ENV['TELEGRAM_CHAT_ID']),
            'bot_token' => $_ENV['TELEGRAM_BOT_TOKEN'] ?? '',
            'chat_id' => $_ENV['TELEGRAM_CHAT_ID'] ?? '',
        ],
        
        'webhook' => [
            'enabled' => !empty($_ENV['WEBHOOK_URL']),
            'url' => $_ENV['WEBHOOK_URL'] ?? '',
            'secret' => $_ENV['WEBHOOK_SECRET'] ?? '',
        ]
    ],
    
    // Alert rules
    'rules' => [
        'api_response_time' => [
            'metric' => 'response_time',
            'warning_threshold' => 3000,
            'critical_threshold' => 5000,
            'evaluation_window' => 300, // 5 minutes
            'min_samples' => 5,
        ],
        
        'error_rate' => [
            'metric' => 'error_rate',
            'warning_threshold' => 5,
            'critical_threshold' => 10,
            'evaluation_window' => 600, // 10 minutes
            'min_samples' => 10,
        ],
        
        'uptime' => [
            'metric' => 'uptime',
            'critical_threshold' => 99, // 99% uptime
            'evaluation_window' => 3600, // 1 hour
            'consecutive_failures' => 3,
        ],
        
        'database_connection' => [
            'metric' => 'database_response_time',
            'warning_threshold' => 1000,
            'critical_threshold' => 5000,
            'consecutive_failures' => 2,
        ],
        
        'disk_space' => [
            'metric' => 'disk_usage_percent',
            'warning_threshold' => 80,
            'critical_threshold' => 90,
            'check_interval' => 3600, // Check every hour
        ],
        
        'memory_usage' => [
            'metric' => 'memory_usage_percent',
            'warning_threshold' => 80,
            'critical_threshold' => 90,
            'check_interval' => 300, // Check every 5 minutes
        ]
    ],
    
    // Alert suppression (to prevent spam)
    'suppression' => [
        'same_alert_interval' => 1800,     // Don't send same alert within 30 minutes
        'max_alerts_per_hour' => 10,       // Maximum 10 alerts per hour
        'escalation_interval' => 3600,     // Escalate unresolved alerts after 1 hour
    ],
    
    // Alert templates
    'templates' => [
        'email' => [
            'subject' => 'Warehouse Dashboard Alert - {severity}: {title}',
            'body' => '
Alert Details:
==============
Severity: {severity}
Title: {title}
Message: {message}
Time: {timestamp}
Server: {server}

Metrics:
--------
{metrics}

Actions Required:
-----------------
{actions}

Dashboard: https://www.market-mi.ru/warehouse-dashboard
Monitoring: https://www.market-mi.ru/api/monitoring.php

This is an automated alert from the Warehouse Dashboard monitoring system.
            '
        ],
        
        'slack' => [
            'warning' => [
                'color' => 'warning',
                'icon' => ':warning:',
                'title' => 'Warning Alert'
            ],
            'critical' => [
                'color' => 'danger',
                'icon' => ':rotating_light:',
                'title' => 'Critical Alert'
            ],
            'resolved' => [
                'color' => 'good',
                'icon' => ':white_check_mark:',
                'title' => 'Alert Resolved'
            ]
        ]
    ]
];
?>