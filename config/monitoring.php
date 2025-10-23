<?php
/**
 * Monitoring Configuration for mi_core_etl
 * 
 * Monitoring and alerting settings loaded from environment variables
 */

// Load environment variables if not already loaded
if (!function_exists('loadEnvFile')) {
    require_once __DIR__ . '/app.php';
}

return [
    // ===================================================================
    // MONITORING SETTINGS
    // ===================================================================
    'enabled' => filter_var($_ENV['MONITORING_ENABLED'] ?? true, FILTER_VALIDATE_BOOLEAN),
    
    // ===================================================================
    // HEALTH CHECK SETTINGS
    // ===================================================================
    'health_check' => [
        'interval' => (int)($_ENV['HEALTH_CHECK_INTERVAL'] ?? 60), // seconds
        'timeout' => (int)($_ENV['HEALTH_CHECK_TIMEOUT'] ?? 10), // seconds
        'retries' => (int)($_ENV['HEALTH_CHECK_RETRIES'] ?? 3),
        'endpoints' => [
            'database' => true,
            'cache' => true,
            'external_apis' => true,
            'disk_space' => true,
            'memory' => true,
        ],
    ],
    
    // ===================================================================
    // ALERT THRESHOLDS
    // ===================================================================
    'thresholds' => [
        'cpu_usage' => (int)($_ENV['CPU_ALERT_THRESHOLD'] ?? 80), // percentage
        'memory_usage' => (int)($_ENV['MEMORY_ALERT_THRESHOLD'] ?? 85), // percentage
        'disk_usage' => (int)($_ENV['DISK_ALERT_THRESHOLD'] ?? 85), // percentage
        'api_response_time' => (int)($_ENV['API_RESPONSE_TIME_THRESHOLD'] ?? 1000), // milliseconds
        'error_rate' => 5, // percentage
        'queue_size' => 1000, // number of items
    ],
    
    // ===================================================================
    // NOTIFICATION SETTINGS
    // ===================================================================
    'notifications' => [
        'email' => [
            'enabled' => !empty($_ENV['EMAIL_ALERTS_TO']),
            'recipients' => explode(',', $_ENV['EMAIL_ALERTS_TO'] ?? ''),
            'smtp' => [
                'host' => $_ENV['SMTP_HOST'] ?? '',
                'port' => (int)($_ENV['SMTP_PORT'] ?? 587),
                'username' => $_ENV['SMTP_USER'] ?? '',
                'password' => $_ENV['SMTP_PASSWORD'] ?? '',
                'encryption' => 'tls',
            ],
        ],
        'slack' => [
            'enabled' => !empty($_ENV['SLACK_WEBHOOK_URL']),
            'webhook_url' => $_ENV['SLACK_WEBHOOK_URL'] ?? '',
            'channel' => '#alerts',
            'username' => 'mi_core_etl',
        ],
    ],
    
    // ===================================================================
    // METRICS COLLECTION
    // ===================================================================
    'metrics' => [
        'enabled' => true,
        'collection_interval' => 60, // seconds
        'retention_days' => 30,
        'storage' => [
            'driver' => 'database', // database, file, redis
            'table' => 'system_metrics',
        ],
        'collect' => [
            'system_resources' => true,
            'database_performance' => true,
            'api_performance' => true,
            'etl_performance' => true,
            'error_rates' => true,
        ],
    ],
    
    // ===================================================================
    // DATA QUALITY MONITORING
    // ===================================================================
    'data_quality' => [
        'enabled' => true,
        'check_schedule' => $_ENV['DATA_QUALITY_CHECK_SCHEDULE'] ?? '0 3 * * *',
        'min_score' => (int)($_ENV['MIN_DATA_QUALITY_SCORE'] ?? 80),
        'checks' => [
            'completeness' => true,
            'accuracy' => true,
            'consistency' => true,
            'timeliness' => true,
            'validity' => true,
        ],
    ],
    
    // ===================================================================
    // ETL MONITORING
    // ===================================================================
    'etl' => [
        'track_performance' => true,
        'alert_on_failure' => true,
        'max_execution_time' => 3600, // seconds (1 hour)
        'schedules' => [
            'ozon' => $_ENV['ETL_SCHEDULE_OZON'] ?? '0 */6 * * *',
            'wildberries' => $_ENV['ETL_SCHEDULE_WB'] ?? '0 */4 * * *',
            'cleanup' => $_ENV['ETL_SCHEDULE_CLEANUP'] ?? '0 1 * * *',
        ],
    ],
    
    // ===================================================================
    // BACKUP MONITORING
    // ===================================================================
    'backup' => [
        'monitor_schedule' => true,
        'verify_integrity' => true,
        'alert_on_failure' => true,
        'schedule' => $_ENV['BACKUP_SCHEDULE'] ?? '0 2 * * *',
        'retention_days' => (int)($_ENV['BACKUP_RETENTION_DAYS'] ?? 30),
    ],
];