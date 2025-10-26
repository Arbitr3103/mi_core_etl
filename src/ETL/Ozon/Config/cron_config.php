<?php

/**
 * Ozon ETL Cron Configuration
 * 
 * Configuration for scheduled ETL processes including timing,
 * logging, and process management settings
 */

return [
    'schedule' => [
        'timezone' => 'Europe/Moscow',
        'jobs' => [
            'sync_products' => [
                'script' => 'sync_products.php',
                'schedule' => '15 2 * * *', // Daily at 02:15 Moscow time (avoid conflict with Analytics cleanup)
                'description' => 'Synchronize product catalog from Ozon API',
                'timeout' => 1800, // 30 minutes
                'retry_count' => 3,
                'retry_delay' => 300, // 5 minutes between retries
                'enabled' => true
            ],
            'sync_sales' => [
                'script' => 'sync_sales.php',
                'schedule' => '15 3 * * *', // Daily at 03:15 Moscow time
                'description' => 'Extract sales history for last 30 days',
                'timeout' => 2400, // 40 minutes
                'retry_count' => 3,
                'retry_delay' => 300,
                'enabled' => true
            ],
            'sync_inventory' => [
                'script' => 'sync_inventory.php',
                'schedule' => '15 4 * * *', // Daily at 04:15 Moscow time
                'description' => 'Update warehouse inventory from Ozon reports',
                'timeout' => 3600, // 60 minutes (report generation can take time)
                'retry_count' => 2,
                'retry_delay' => 600, // 10 minutes between retries
                'enabled' => true
            ],
            'health_check' => [
                'script' => 'health_check.php',
                'schedule' => '*/15 * * * *', // Every 15 minutes
                'description' => 'System health monitoring',
                'timeout' => 300, // 5 minutes
                'retry_count' => 1,
                'retry_delay' => 60,
                'enabled' => true
            ],
            'monitor_etl' => [
                'script' => 'monitor_etl.php',
                'schedule' => '*/30 9-18 * * 1-5', // Every 30 minutes during business hours
                'description' => 'ETL process monitoring and alerting',
                'timeout' => 600, // 10 minutes
                'retry_count' => 2,
                'retry_delay' => 120,
                'enabled' => true
            ],
            'daily_summary' => [
                'script' => 'monitor_etl.php --send-summary',
                'schedule' => '0 20 * * *', // Daily at 8 PM
                'description' => 'Send daily ETL summary report',
                'timeout' => 300,
                'retry_count' => 1,
                'retry_delay' => 60,
                'enabled' => true
            ]
        ]
    ],
    
    'logging' => [
        'cron_log_file' => $_ENV['ETL_CRON_LOG_PATH'] ?? '/var/log/ozon_etl_cron.log',
        'job_log_dir' => $_ENV['ETL_JOB_LOG_DIR'] ?? __DIR__ . '/../Logs/cron_jobs',
        'max_log_size' => $_ENV['ETL_MAX_LOG_SIZE'] ?? '100M',
        'log_retention_days' => $_ENV['ETL_LOG_RETENTION_DAYS'] ?? 30,
        'log_level' => $_ENV['ETL_CRON_LOG_LEVEL'] ?? 'INFO'
    ],
    
    'paths' => [
        'project_root' => realpath(__DIR__ . '/../../../../'),
        'scripts_dir' => __DIR__ . '/../Scripts',
        'php_binary' => $_ENV['PHP_BINARY'] ?? '/opt/homebrew/bin/php',
        'lock_dir' => $_ENV['ETL_LOCK_DIR'] ?? __DIR__ . '/../Logs/locks',
        'pid_dir' => $_ENV['ETL_PID_DIR'] ?? __DIR__ . '/../Logs/pids'
    ],
    
    'notifications' => [
        'enabled' => $_ENV['ETL_NOTIFICATIONS_ENABLED'] ?? true,
        'throttle_minutes' => $_ENV['ETL_ALERT_THROTTLE_MINUTES'] ?? 15,
        'email' => [
            'enabled' => $_ENV['ETL_EMAIL_NOTIFICATIONS'] ?? false,
            'smtp_host' => $_ENV['SMTP_HOST'] ?? '',
            'smtp_port' => $_ENV['SMTP_PORT'] ?? 587,
            'smtp_user' => $_ENV['SMTP_USER'] ?? '',
            'smtp_password' => $_ENV['SMTP_PASSWORD'] ?? '',
            'from_email' => $_ENV['ETL_FROM_EMAIL'] ?? 'etl@company.com',
            'to_emails' => explode(',', $_ENV['ETL_TO_EMAILS'] ?? 'admin@company.com')
        ],
        'slack' => [
            'enabled' => $_ENV['ETL_SLACK_NOTIFICATIONS'] ?? false,
            'webhook_url' => $_ENV['SLACK_WEBHOOK_URL'] ?? '',
            'channel' => $_ENV['SLACK_CHANNEL'] ?? '#etl-alerts'
        ],
        'webhook' => [
            'enabled' => $_ENV['ETL_WEBHOOK_NOTIFICATIONS'] ?? false,
            'url' => $_ENV['ETL_WEBHOOK_URL'] ?? ''
        ],
        'daily_summary' => [
            'enabled' => $_ENV['ETL_DAILY_SUMMARY_ENABLED'] ?? true,
            'time' => $_ENV['ETL_DAILY_SUMMARY_TIME'] ?? '20:00'
        ]
    ],
    
    'monitoring' => [
        'max_execution_time' => [
            'sync_products' => 1800,  // 30 minutes
            'sync_sales' => 2400,     // 40 minutes
            'sync_inventory' => 3600, // 60 minutes
            'health_check' => 300     // 5 minutes
        ],
        'alert_thresholds' => [
            'consecutive_failures' => 3,
            'execution_time_multiplier' => 2.0, // Alert if execution takes 2x normal time
            'memory_usage_mb' => 1024 // Alert if memory usage exceeds 1GB
        ]
    ]
];