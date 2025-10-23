<?php
/**
 * Monitoring Configuration
 * 
 * Configuration settings for the warehouse dashboard monitoring system
 */

// Monitoring settings
$monitoring_config = [
    // General settings
    'enabled' => true,
    'debug_mode' => false,
    
    // Log settings
    'log_path' => $_ENV['LOG_PATH'] ?? '/var/log/warehouse-dashboard',
    'log_retention_days' => 30,
    'max_log_size_mb' => 100,
    
    // Health check settings
    'health_check' => [
        'timeout_seconds' => 10,
        'database_timeout' => 5,
        'api_timeout' => 5,
        'memory_limit_percent' => 85,
        'disk_limit_percent' => 85
    ],
    
    // Uptime monitoring
    'uptime' => [
        'check_interval_minutes' => 5,
        'timeout_seconds' => 10,
        'max_failures' => 3,
        'alert_cooldown_minutes' => 30,
        'endpoints' => [
            'dashboard' => [
                'url' => 'https://www.market-mi.ru/warehouse-dashboard',
                'expected_content' => 'warehouse-dashboard',
                'critical' => true
            ],
            'api_health' => [
                'url' => 'https://www.market-mi.ru/api/monitoring.php?action=health',
                'expected_content' => '"status"',
                'critical' => true
            ],
            'api_warehouse' => [
                'url' => 'https://www.market-mi.ru/api/warehouse-dashboard.php?limit=1',
                'expected_content' => 'data',
                'critical' => true
            ],
            'api_countries' => [
                'url' => 'https://www.market-mi.ru/api/countries.php',
                'expected_content' => 'data',
                'critical' => false
            ],
            'api_brands' => [
                'url' => 'https://www.market-mi.ru/api/brands.php',
                'expected_content' => 'data',
                'critical' => false
            ]
        ]
    ],
    
    // Performance monitoring
    'performance' => [
        'slow_query_threshold_ms' => 3000,
        'slow_api_threshold_ms' => 2000,
        'high_memory_threshold_percent' => 80,
        'metrics_retention_days' => 7,
        'sample_rate' => 1.0 // 100% sampling
    ],
    
    // Alert thresholds
    'alerts' => [
        'response_time_ms' => 3000,
        'error_rate_percent' => 5.0,
        'uptime_percent' => 99.0,
        'disk_usage_percent' => 85.0,
        'memory_usage_percent' => 85.0,
        'database_connections' => 80,
        'api_error_rate_percent' => 10.0
    ],
    
    // Notification channels
    'notifications' => [
        'log' => [
            'enabled' => true,
            'level' => 'info' // debug, info, warning, error, critical
        ],
        'email' => [
            'enabled' => false,
            'smtp_host' => '',
            'smtp_port' => 587,
            'smtp_username' => '',
            'smtp_password' => '',
            'from_email' => 'noreply@market-mi.ru',
            'from_name' => 'Warehouse Dashboard Monitoring',
            'recipients' => []
        ],
        'webhook' => [
            'enabled' => false,
            'url' => '',
            'timeout_seconds' => 10,
            'retry_attempts' => 3,
            'headers' => [
                'Content-Type: application/json',
                'User-Agent: Warehouse-Dashboard-Monitor/1.0'
            ]
        ],
        'slack' => [
            'enabled' => false,
            'webhook_url' => '',
            'channel' => '#alerts',
            'username' => 'Warehouse Dashboard',
            'icon_emoji' => ':warning:'
        ]
    ],
    
    // Escalation rules
    'escalation' => [
        'enabled' => false,
        'levels' => [
            [
                'after_minutes' => 15,
                'channels' => ['email'],
                'severity' => ['critical']
            ],
            [
                'after_minutes' => 60,
                'channels' => ['email', 'webhook'],
                'severity' => ['critical', 'error']
            ]
        ]
    ],
    
    // Maintenance windows
    'maintenance' => [
        'enabled' => false,
        'windows' => [
            // Example: Daily maintenance from 2-3 AM
            [
                'start_time' => '02:00',
                'end_time' => '03:00',
                'days' => ['*'], // * for all days, or specific days: ['monday', 'tuesday']
                'timezone' => 'UTC',
                'suppress_alerts' => true
            ]
        ]
    ],
    
    // Dashboard settings
    'dashboard' => [
        'refresh_interval_seconds' => 30,
        'show_debug_info' => false,
        'max_alerts_display' => 10,
        'chart_data_points' => 24 // Hours of data for charts
    ],
    
    // Database monitoring
    'database' => [
        'monitor_queries' => true,
        'slow_query_log' => true,
        'connection_pool_monitoring' => true,
        'table_size_monitoring' => true,
        'index_usage_monitoring' => false
    ],
    
    // API monitoring
    'api' => [
        'monitor_all_endpoints' => true,
        'track_user_agents' => false,
        'track_ip_addresses' => false,
        'rate_limit_monitoring' => true,
        'response_size_monitoring' => true
    ],
    
    // Security monitoring
    'security' => [
        'monitor_failed_logins' => false, // Not applicable for this dashboard
        'monitor_suspicious_requests' => true,
        'monitor_large_requests' => true,
        'max_request_size_mb' => 10,
        'suspicious_patterns' => [
            'sql_injection' => ['union', 'select', 'drop', 'delete', 'insert'],
            'xss_attempts' => ['<script', 'javascript:', 'onerror='],
            'path_traversal' => ['../', '..\\', '/etc/passwd']
        ]
    ]
];

// Environment-specific overrides
if (isset($_ENV['ENVIRONMENT'])) {
    switch ($_ENV['ENVIRONMENT']) {
        case 'development':
            $monitoring_config['debug_mode'] = true;
            $monitoring_config['alerts']['response_time_ms'] = 5000;
            $monitoring_config['performance']['sample_rate'] = 0.1; // 10% sampling
            break;
            
        case 'staging':
            $monitoring_config['notifications']['email']['enabled'] = false;
            $monitoring_config['alerts']['uptime_percent'] = 95.0;
            break;
            
        case 'production':
            $monitoring_config['debug_mode'] = false;
            $monitoring_config['notifications']['log']['level'] = 'warning';
            break;
    }
}

// Validate configuration
function validateMonitoringConfig($config) {
    $errors = [];
    
    // Check required paths
    if (!is_dir($config['log_path'])) {
        $errors[] = "Log path does not exist: {$config['log_path']}";
    }
    
    if (!is_writable($config['log_path'])) {
        $errors[] = "Log path is not writable: {$config['log_path']}";
    }
    
    // Validate thresholds
    foreach ($config['alerts'] as $key => $value) {
        if (!is_numeric($value) || $value < 0) {
            $errors[] = "Invalid alert threshold for {$key}: {$value}";
        }
    }
    
    // Validate email configuration if enabled
    if ($config['notifications']['email']['enabled']) {
        $email_config = $config['notifications']['email'];
        if (empty($email_config['recipients'])) {
            $errors[] = "Email notifications enabled but no recipients configured";
        }
    }
    
    // Validate webhook configuration if enabled
    if ($config['notifications']['webhook']['enabled']) {
        $webhook_config = $config['notifications']['webhook'];
        if (empty($webhook_config['url'])) {
            $errors[] = "Webhook notifications enabled but no URL configured";
        }
    }
    
    return $errors;
}

// Export configuration
$GLOBALS['monitoring_config'] = $monitoring_config;

// Validate configuration
$config_errors = validateMonitoringConfig($monitoring_config);
if (!empty($config_errors)) {
    error_log("Monitoring configuration errors: " . implode(', ', $config_errors));
}

return $monitoring_config;
?>