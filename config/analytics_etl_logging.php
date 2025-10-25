<?php
/**
 * Analytics ETL Logging Configuration
 * 
 * Configuration for logging Analytics API ETL processes
 */

return [
    'log_directory' => __DIR__ . '/../logs/analytics_etl',
    'log_level' => 'INFO', // DEBUG, INFO, WARNING, ERROR, CRITICAL
    'max_file_size' => 100 * 1024 * 1024, // 100MB
    'max_files' => 30, // Keep 30 days of logs
    'log_format' => '[%datetime%] %level_name%: %message% %context%',
    
    'loggers' => [
        'etl_process' => [
            'file' => 'etl_process.log',
            'level' => 'INFO',
            'description' => 'Main ETL execution logs'
        ],
        'api_requests' => [
            'file' => 'api_requests.log', 
            'level' => 'DEBUG',
            'description' => 'Analytics API request/response logs'
        ],
        'data_quality' => [
            'file' => 'data_quality.log',
            'level' => 'INFO', 
            'description' => 'Data validation and quality logs'
        ],
        'errors' => [
            'file' => 'errors.log',
            'level' => 'ERROR',
            'description' => 'Error and exception logs'
        ]
    ],
    
    'rotation' => [
        'enabled' => true,
        'when' => 'daily', // daily, weekly, monthly
        'backup_count' => 30,
        'compress' => true
    ]
];