<?php

/**
 * Ozon API Client Configuration
 * 
 * Configuration for Ozon API client including endpoints, authentication,
 * rate limiting, and retry policies
 */

return [
    // API Authentication
    'auth' => [
        'client_id' => $_ENV['OZON_CLIENT_ID'] ?? '',
        'api_key' => $_ENV['OZON_API_KEY'] ?? '',
        'base_url' => 'https://api-seller.ozon.ru'
    ],
    
    // API Endpoints
    'endpoints' => [
        'products' => [
            'list' => '/v2/product/list',
            'info' => '/v2/product/info'
        ],
        'sales' => [
            'fbo_list' => '/v2/posting/fbo/list',
            'fbs_list' => '/v2/posting/fbs/list'
        ],
        'reports' => [
            'warehouse_stock' => '/v1/report/warehouse/stock',
            'report_info' => '/v1/report/info'
        ]
    ],
    
    // Rate Limiting
    'rate_limiting' => [
        'enabled' => true,
        'requests_per_minute' => 60,
        'requests_per_hour' => 1000,
        'burst_limit' => 10
    ],
    
    // HTTP Client Settings
    'http' => [
        'timeout' => 30,
        'connect_timeout' => 10,
        'read_timeout' => 60,
        'user_agent' => 'MiCore-OzonETL/1.0',
        'verify_ssl' => true
    ],
    
    // Retry Policy
    'retry' => [
        'max_attempts' => 5,
        'base_delay' => 1, // seconds
        'max_delay' => 60, // seconds
        'exponential_base' => 2,
        'jitter' => true,
        
        // HTTP status codes that should trigger retry
        'retry_on_status' => [429, 500, 502, 503, 504],
        
        // Specific error conditions
        'retry_on_errors' => [
            'connection_timeout',
            'read_timeout',
            'network_error'
        ]
    ],
    
    // Request/Response Logging
    'logging' => [
        'log_requests' => ($_ENV['APP_ENV'] ?? 'production') === 'development',
        'log_responses' => ($_ENV['APP_ENV'] ?? 'production') === 'development',
        'log_errors' => true,
        'mask_sensitive_data' => true
    ],
    
    // Pagination Settings
    'pagination' => [
        'default_limit' => 1000,
        'max_limit' => 1000,
        'max_pages' => 1000 // Safety limit
    ],
    
    // Report Generation Settings
    'reports' => [
        'poll_interval' => 60, // seconds
        'max_wait_time' => 1800, // 30 minutes
        'download_timeout' => 300, // 5 minutes
        'temp_dir' => sys_get_temp_dir() . '/ozon_reports'
    ]
];