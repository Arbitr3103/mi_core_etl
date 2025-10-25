<?php
/**
 * Analytics API Cache Configuration
 * 
 * Configuration for caching Analytics API responses and related data
 */

return [
    'cache_directory' => __DIR__ . '/../cache/analytics_api',
    'enabled' => true,
    'default_ttl' => 1800, // 30 minutes
    
    'cache_types' => [
        'api_responses' => [
            'directory' => 'api_responses',
            'ttl' => 1800, // 30 minutes
            'max_size' => 50 * 1024 * 1024, // 50MB
            'description' => 'Cached Analytics API responses'
        ],
        'warehouse_mappings' => [
            'directory' => 'warehouse_mappings',
            'ttl' => 86400, // 24 hours
            'max_size' => 10 * 1024 * 1024, // 10MB
            'description' => 'Cached warehouse normalization mappings'
        ],
        'validation_rules' => [
            'directory' => 'validation_rules',
            'ttl' => 3600, // 1 hour
            'max_size' => 5 * 1024 * 1024, // 5MB
            'description' => 'Cached validation rules'
        ]
    ],
    
    'cleanup' => [
        'enabled' => true,
        'interval' => 3600, // Run cleanup every hour
        'max_cache_size' => 100 * 1024 * 1024, // 100MB total
        'cleanup_threshold' => 0.8 // Clean when 80% full
    ],
    
    'serialization' => [
        'format' => 'json', // json, serialize, msgpack
        'compression' => true,
        'encryption' => false
    ]
];