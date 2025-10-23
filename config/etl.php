<?php
/**
 * ETL Configuration for mi_core_etl
 * 
 * ETL process settings loaded from environment variables
 */

// Load environment variables if not already loaded
if (!function_exists('loadEnvFile')) {
    require_once __DIR__ . '/app.php';
}

return [
    // ===================================================================
    // ETL GENERAL SETTINGS
    // ===================================================================
    'batch_size' => (int)($_ENV['ETL_BATCH_SIZE'] ?? 1000),
    'timeout' => (int)($_ENV['ETL_TIMEOUT'] ?? 300), // 5 minutes
    'memory_limit' => '512M',
    'max_execution_time' => 3600, // 1 hour
    
    // ===================================================================
    // ETL SCHEDULES
    // ===================================================================
    'schedules' => [
        'ozon' => [
            'cron' => $_ENV['ETL_SCHEDULE_OZON'] ?? '0 */6 * * *', // Every 6 hours
            'enabled' => true,
            'timeout' => 1800, // 30 minutes
            'retry_attempts' => 3,
            'retry_delay' => 300, // 5 minutes
        ],
        'wildberries' => [
            'cron' => $_ENV['ETL_SCHEDULE_WB'] ?? '0 */4 * * *', // Every 4 hours
            'enabled' => true,
            'timeout' => 1800, // 30 minutes
            'retry_attempts' => 3,
            'retry_delay' => 300, // 5 minutes
        ],
        'cleanup' => [
            'cron' => $_ENV['ETL_SCHEDULE_CLEANUP'] ?? '0 1 * * *', // Daily at 1 AM
            'enabled' => true,
            'timeout' => 600, // 10 minutes
            'retry_attempts' => 1,
        ],
        'data_quality' => [
            'cron' => $_ENV['DATA_QUALITY_CHECK_SCHEDULE'] ?? '0 3 * * *', // Daily at 3 AM
            'enabled' => true,
            'timeout' => 900, // 15 minutes
            'retry_attempts' => 2,
        ],
    ],
    
    // ===================================================================
    // DATA SOURCES
    // ===================================================================
    'sources' => [
        'ozon' => [
            'enabled' => !empty($_ENV['OZON_API_KEY']),
            'api_key' => $_ENV['OZON_API_KEY'] ?? '',
            'client_id' => $_ENV['OZON_CLIENT_ID'] ?? '',
            'base_url' => 'https://api-seller.ozon.ru',
            'request_delay' => (float)($_ENV['OZON_REQUEST_DELAY'] ?? 0.1),
            'rate_limit' => 10, // requests per second
            'endpoints' => [
                'products' => '/v2/product/list',
                'stocks' => '/v3/product/info/stocks',
                'analytics' => '/v1/analytics/data',
            ],
        ],
        'wildberries' => [
            'enabled' => !empty($_ENV['WB_API_KEY']),
            'api_key' => $_ENV['WB_API_KEY'] ?? '',
            'suppliers_url' => 'https://suppliers-api.wildberries.ru',
            'content_url' => 'https://content-api.wildberries.ru',
            'statistics_url' => 'https://statistics-api.wildberries.ru',
            'request_delay' => (float)($_ENV['WB_REQUEST_DELAY'] ?? 0.5),
            'rate_limit' => 2, // requests per second
            'endpoints' => [
                'stocks' => '/api/v3/stocks',
                'orders' => '/api/v3/orders',
                'sales' => '/api/v5/supplier/reportDetailByPeriod',
            ],
        ],
    ],
    
    // ===================================================================
    // DATA PROCESSING
    // ===================================================================
    'processing' => [
        'parallel_workers' => 4,
        'chunk_size' => 100,
        'validation' => [
            'enabled' => true,
            'strict_mode' => false,
            'skip_invalid_records' => true,
            'log_validation_errors' => true,
        ],
        'transformation' => [
            'normalize_data' => true,
            'calculate_metrics' => true,
            'enrich_data' => true,
        ],
    ],
    
    // ===================================================================
    // DATA STORAGE
    // ===================================================================
    'storage' => [
        'primary_database' => 'postgresql',
        'backup_database' => 'mysql',
        'staging_tables' => [
            'prefix' => 'staging_',
            'cleanup_after_load' => true,
            'retention_hours' => 24,
        ],
        'partitioning' => [
            'enabled' => true,
            'strategy' => 'monthly',
            'retention_months' => 12,
        ],
    ],
    
    // ===================================================================
    // ERROR HANDLING
    // ===================================================================
    'error_handling' => [
        'continue_on_error' => true,
        'max_errors_per_batch' => 10,
        'error_threshold_percentage' => 5,
        'quarantine_invalid_data' => true,
        'notification_on_failure' => true,
    ],
    
    // ===================================================================
    // LOGGING AND MONITORING
    // ===================================================================
    'logging' => [
        'level' => $_ENV['LOG_LEVEL'] ?? 'info',
        'log_sql_queries' => filter_var($_ENV['APP_DEBUG'] ?? false, FILTER_VALIDATE_BOOLEAN),
        'log_api_requests' => true,
        'log_performance_metrics' => true,
        'separate_log_files' => true,
    ],
    
    // ===================================================================
    // CLEANUP SETTINGS
    // ===================================================================
    'cleanup' => [
        'old_logs' => [
            'enabled' => true,
            'retention_days' => 30,
            'compress_old_logs' => true,
        ],
        'temp_files' => [
            'enabled' => true,
            'retention_hours' => 24,
            'temp_directory' => $_ENV['TEMP_DIR'] ?? '/tmp/mi_core_etl',
        ],
        'cache' => [
            'enabled' => true,
            'retention_hours' => 168, // 7 days
        ],
        'old_data' => [
            'enabled' => true,
            'retention_months' => 12,
            'archive_before_delete' => true,
        ],
    ],
];