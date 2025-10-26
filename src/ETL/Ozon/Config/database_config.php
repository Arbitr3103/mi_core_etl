<?php

/**
 * Database Configuration for Ozon ETL System
 * 
 * Database connection settings and table configurations
 * for the Ozon ETL system
 */

return [
    // Database Connection
    'connection' => [
        'driver' => 'pgsql',
        'host' => $_ENV['PG_HOST'] ?? $_ENV['DB_HOST'] ?? 'localhost',
        'port' => $_ENV['PG_PORT'] ?? 5432,
        'database' => $_ENV['PG_NAME'] ?? $_ENV['DB_NAME'] ?? 'mi_core_db',
        'username' => $_ENV['PG_USER'] ?? $_ENV['DB_USER'] ?? 'postgres',
        'password' => $_ENV['PG_PASSWORD'] ?? $_ENV['DB_PASSWORD'] ?? '',
        'charset' => 'utf8',
        'schema' => 'public',
        'sslmode' => $_ENV['DB_SSLMODE'] ?? 'prefer'
    ],
    
    // Connection Pool Settings
    'pool' => [
        'min_connections' => 2,
        'max_connections' => 10,
        'connection_timeout' => 30,
        'idle_timeout' => 300,
        'max_lifetime' => 3600
    ],
    
    // Table Names
    'tables' => [
        'dim_products' => 'dim_products',
        'fact_orders' => 'fact_orders', 
        'inventory' => 'inventory',
        'etl_execution_log' => 'etl_execution_log'
    ],
    
    // Batch Processing
    'batch' => [
        'insert_size' => 1000,
        'update_size' => 500,
        'delete_size' => 1000,
        'transaction_timeout' => 300
    ],
    
    // Query Settings
    'queries' => [
        'statement_timeout' => 300, // 5 minutes
        'lock_timeout' => 60, // 1 minute
        'idle_in_transaction_timeout' => 300,
        'log_slow_queries' => true,
        'slow_query_threshold' => 5 // seconds
    ],
    
    // Migration Settings
    'migrations' => [
        'table' => 'ozon_etl_migrations',
        'path' => __DIR__ . '/../../Migrations/Ozon',
        'auto_run' => false
    ],
    
    // Backup Settings
    'backup' => [
        'enabled' => true,
        'before_major_operations' => true,
        'retention_days' => 7,
        'path' => $_ENV['BACKUP_PATH'] ?? '/tmp/ozon_etl_backups'
    ],
    
    // Performance Monitoring
    'monitoring' => [
        'log_queries' => ($_ENV['APP_ENV'] ?? 'production') === 'development',
        'track_connection_usage' => true,
        'alert_on_slow_queries' => true,
        'alert_on_connection_issues' => true
    ]
];