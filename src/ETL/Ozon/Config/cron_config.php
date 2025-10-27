<?php

/**
 * Cron Configuration for Ozon ETL System
 * 
 * Defines cron job schedules, dependencies, and monitoring settings
 * for the refactored ETL system with ProductETL -> InventoryETL sequence.
 * 
 * Requirements addressed:
 * - 5.1: Update cron jobs to run ProductETL before InventoryETL with proper timing
 * - 5.2: Create metrics tracking for visibility field updates and status distribution
 * - 5.3: Implement alerts for ETL sequence failures or data quality issues
 */

return [
    // ETL Execution Configuration
    'etl_execution' => [
        // Main ETL workflow schedule (runs complete ProductETL -> InventoryETL sequence)
        'main_workflow' => [
            'schedule' => '0 2,8,14,20 * * *', // Every 6 hours at 02:00, 08:00, 14:00, 20:00
            'command' => 'php /var/www/mi_core_etl/src/ETL/Ozon/Scripts/run_etl_workflow.php',
            'description' => 'Main ETL workflow (ProductETL -> InventoryETL)',
            'timeout' => 7200, // 2 hours
            'enabled' => true,
            'log_file' => '/var/www/mi_core_etl/logs/cron/etl_workflow_%Y%m%d.log',
            'dependencies' => [],
            'retry_on_failure' => true,
            'max_retries' => 2,
            'retry_delay' => 1800 // 30 minutes
        ],
        
        // Individual ProductETL (for manual/emergency runs)
        'product_etl_only' => [
            'schedule' => null, // Manual execution only
            'command' => 'php /var/www/mi_core_etl/src/ETL/Ozon/Scripts/run_product_etl.php',
            'description' => 'ProductETL only (manual execution)',
            'timeout' => 3600, // 1 hour
            'enabled' => false,
            'log_file' => '/var/www/mi_core_etl/logs/cron/product_etl_%Y%m%d.log',
            'dependencies' => [],
            'retry_on_failure' => true,
            'max_retries' => 3,
            'retry_delay' => 900 // 15 minutes
        ],
        
        // Individual InventoryETL (for manual/emergency runs)
        'inventory_etl_only' => [
            'schedule' => null, // Manual execution only
            'command' => 'php /var/www/mi_core_etl/src/ETL/Ozon/Scripts/run_inventory_etl.php',
            'description' => 'InventoryETL only (manual execution)',
            'timeout' => 3600, // 1 hour
            'enabled' => false,
            'log_file' => '/var/www/mi_core_etl/logs/cron/inventory_etl_%Y%m%d.log',
            'dependencies' => ['product_etl_success'], // Requires ProductETL success
            'retry_on_failure' => true,
            'max_retries' => 3,
            'retry_delay' => 900 // 15 minutes
        ]
    ],
    
    // Monitoring and Health Checks
    'monitoring' => [
        // ETL health check (runs between main ETL executions)
        'health_check' => [
            'schedule' => '*/15 * * * *', // Every 15 minutes
            'command' => 'php /var/www/mi_core_etl/src/ETL/Ozon/Scripts/etl_health_check.php',
            'description' => 'ETL system health check',
            'timeout' => 300, // 5 minutes
            'enabled' => true,
            'log_file' => '/var/www/mi_core_etl/logs/cron/health_check_%Y%m%d.log',
            'dependencies' => [],
            'retry_on_failure' => false
        ],
        
        // Data quality monitoring
        'data_quality_check' => [
            'schedule' => '30 3,9,15,21 * * *', // 30 minutes after each main ETL run
            'command' => 'php /var/www/mi_core_etl/src/ETL/Ozon/Scripts/data_quality_monitor.php',
            'description' => 'Data quality and consistency monitoring',
            'timeout' => 1800, // 30 minutes
            'enabled' => true,
            'log_file' => '/var/www/mi_core_etl/logs/cron/data_quality_%Y%m%d.log',
            'dependencies' => ['main_workflow_success'],
            'retry_on_failure' => false
        ],
        
        // Visibility metrics tracking
        'visibility_metrics' => [
            'schedule' => '45 2,8,14,20 * * *', // 45 minutes after each main ETL run
            'command' => 'php /var/www/mi_core_etl/src/ETL/Ozon/Scripts/visibility_metrics_tracker.php',
            'description' => 'Track visibility field updates and status distribution',
            'timeout' => 600, // 10 minutes
            'enabled' => true,
            'log_file' => '/var/www/mi_core_etl/logs/cron/visibility_metrics_%Y%m%d.log',
            'dependencies' => ['main_workflow_success'],
            'retry_on_failure' => false
        ]
    ],
    
    // Maintenance Tasks
    'maintenance' => [
        // Log cleanup
        'log_cleanup' => [
            'schedule' => '0 1 * * 0', // Weekly on Sunday at 01:00
            'command' => 'php /var/www/mi_core_etl/src/ETL/Ozon/Scripts/cleanup_logs.php',
            'description' => 'Clean up old log files',
            'timeout' => 1800, // 30 minutes
            'enabled' => true,
            'log_file' => '/var/www/mi_core_etl/logs/cron/log_cleanup_%Y%m%d.log',
            'dependencies' => [],
            'retry_on_failure' => false
        ],
        
        // Database maintenance
        'db_maintenance' => [
            'schedule' => '0 3 * * 0', // Weekly on Sunday at 03:00
            'command' => 'php /var/www/mi_core_etl/src/ETL/Ozon/Scripts/db_maintenance.php',
            'description' => 'Database maintenance and optimization',
            'timeout' => 3600, // 1 hour
            'enabled' => true,
            'log_file' => '/var/www/mi_core_etl/logs/cron/db_maintenance_%Y%m%d.log',
            'dependencies' => [],
            'retry_on_failure' => false
        ]
    ],
    
    // Alert Configuration
    'alerts' => [
        // ETL failure alerts
        'etl_failure' => [
            'enabled' => true,
            'threshold' => 1, // Alert after 1 failure
            'cooldown' => 3600, // 1 hour cooldown between alerts
            'recipients' => [
                'admin@company.com',
                'devops@company.com'
            ],
            'webhook_url' => null, // Optional webhook for Slack/Teams
            'include_logs' => true,
            'max_log_lines' => 100
        ],
        
        // Data quality alerts
        'data_quality' => [
            'enabled' => true,
            'thresholds' => [
                'visibility_coverage_min' => 95, // Minimum % of products with visibility data
                'orphaned_inventory_max' => 100, // Maximum orphaned inventory items
                'data_freshness_hours' => 8 // Maximum hours since last successful ETL
            ],
            'cooldown' => 7200, // 2 hours cooldown
            'recipients' => [
                'admin@company.com',
                'data-team@company.com'
            ]
        ],
        
        // Performance alerts
        'performance' => [
            'enabled' => true,
            'thresholds' => [
                'etl_duration_max' => 5400, // Maximum ETL duration in seconds (1.5 hours)
                'memory_usage_max' => 1024, // Maximum memory usage in MB
                'api_error_rate_max' => 5 // Maximum API error rate percentage
            ],
            'cooldown' => 3600, // 1 hour cooldown
            'recipients' => [
                'devops@company.com'
            ]
        ]
    ],
    
    // Dependency Management
    'dependencies' => [
        'check_interval' => 300, // Check dependencies every 5 minutes
        'max_wait_time' => 3600, // Maximum wait time for dependencies (1 hour)
        'dependency_definitions' => [
            'product_etl_success' => [
                'type' => 'database_check',
                'query' => "SELECT COUNT(*) as count FROM dim_products WHERE updated_at > NOW() - INTERVAL 6 HOURS AND visibility IS NOT NULL",
                'condition' => 'count > 0',
                'description' => 'ProductETL completed successfully with visibility data'
            ],
            'main_workflow_success' => [
                'type' => 'database_check',
                'query' => "SELECT COUNT(*) as count FROM etl_workflow_executions WHERE status = 'success' AND created_at > NOW() - INTERVAL 6 HOURS",
                'condition' => 'count > 0',
                'description' => 'Main ETL workflow completed successfully'
            ]
        ]
    ],
    
    // Retry Configuration
    'retry' => [
        'default_max_retries' => 3,
        'default_retry_delay' => 900, // 15 minutes
        'exponential_backoff' => true,
        'backoff_multiplier' => 1.5,
        'max_retry_delay' => 3600, // 1 hour
        'retry_on_exit_codes' => [1, 2, 130], // Retry on these exit codes
        'no_retry_on_exit_codes' => [127, 126] // Don't retry on these exit codes
    ],
    
    // Logging Configuration
    'logging' => [
        'log_directory' => '/var/www/mi_core_etl/logs/cron',
        'log_rotation' => [
            'enabled' => true,
            'max_files' => 30, // Keep 30 days of logs
            'max_size' => '100M', // Rotate when log exceeds 100MB
            'compress' => true
        ],
        'log_levels' => [
            'default' => 'INFO',
            'etl_workflow' => 'DEBUG',
            'monitoring' => 'INFO',
            'maintenance' => 'WARNING'
        ]
    ],
    
    // Lock File Configuration
    'locks' => [
        'lock_directory' => '/var/www/mi_core_etl/locks',
        'lock_timeout' => 7200, // 2 hours
        'stale_lock_cleanup' => true,
        'lock_file_format' => 'etl_%s.lock' // %s will be replaced with job name
    ],
    
    // Environment Configuration
    'environment' => [
        'php_binary' => '/usr/bin/php',
        'working_directory' => '/var/www/mi_core_etl',
        'environment_variables' => [
            'ETL_ENV' => 'production',
            'ETL_LOG_LEVEL' => 'INFO',
            'ETL_MEMORY_LIMIT' => '1024M',
            'ETL_TIME_LIMIT' => '7200'
        ]
    ]
];