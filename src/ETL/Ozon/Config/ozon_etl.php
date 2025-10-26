<?php

/**
 * Ozon ETL System Configuration
 * 
 * Main configuration file for the Ozon Complete ETL System
 * Contains settings for ETL processes, scheduling, and monitoring
 */

return [
    // ETL Process Configuration
    'etl' => [
        'batch_size' => 1000,
        'max_retries' => 5,
        'retry_delay' => 30, // seconds
        'timeout' => 3600, // 1 hour
        'memory_limit' => '512M',
        
        // Process scheduling (Moscow time)
        'schedule' => [
            'products' => '02:00',
            'sales' => '03:00', 
            'inventory' => '04:00'
        ],
        
        // Data retention settings
        'retention' => [
            'sales_history_days' => 30,
            'logs_retention_days' => 30,
            'execution_log_days' => 90
        ]
    ],
    
    // Monitoring and Alerting
    'monitoring' => [
        'enabled' => true,
        'health_check_interval' => 300, // 5 minutes
        'alert_on_failure' => true,
        'alert_on_long_execution' => true,
        'max_execution_time' => 1800, // 30 minutes
        
        // Alert channels
        'alerts' => [
            'email' => [
                'enabled' => true,
                'recipients' => explode(',', $_ENV['ALERT_EMAIL_RECIPIENTS'] ?? '')
            ],
            'slack' => [
                'enabled' => false,
                'webhook_url' => $_ENV['SLACK_WEBHOOK_URL'] ?? ''
            ]
        ]
    ],
    
    // Logging Configuration
    'logging' => [
        'level' => $_ENV['LOG_LEVEL'] ?? 'info',
        'path' => __DIR__ . '/../Logs',
        'max_files' => 30,
        'format' => 'json',
        'include_context' => true
    ],
    
    // Lock file settings (prevent concurrent execution)
    'locks' => [
        'enabled' => true,
        'path' => sys_get_temp_dir() . '/ozon_etl_locks',
        'timeout' => 7200 // 2 hours
    ],
    
    // Performance settings
    'performance' => [
        'enable_profiling' => ($_ENV['APP_ENV'] ?? 'production') === 'development',
        'memory_monitoring' => true,
        'query_logging' => ($_ENV['APP_ENV'] ?? 'production') === 'development'
    ]
];