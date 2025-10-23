<?php
/**
 * Cache Configuration for mi_core_etl
 * 
 * Cache settings loaded from environment variables
 */

// Load environment variables if not already loaded
if (!function_exists('loadEnvFile')) {
    require_once __DIR__ . '/app.php';
}

return [
    // ===================================================================
    // DEFAULT CACHE DRIVER
    // ===================================================================
    'default' => $_ENV['CACHE_DRIVER'] ?? 'file',
    
    // ===================================================================
    // CACHE STORES
    // ===================================================================
    'stores' => [
        'file' => [
            'driver' => 'file',
            'path' => $_ENV['CACHE_PATH'] ?? __DIR__ . '/../storage/cache',
            'permissions' => 0755,
        ],
        'redis' => [
            'driver' => 'redis',
            'host' => $_ENV['REDIS_HOST'] ?? '127.0.0.1',
            'port' => (int)($_ENV['REDIS_PORT'] ?? 6379),
            'password' => $_ENV['REDIS_PASSWORD'] ?: null,
            'database' => (int)($_ENV['REDIS_DB'] ?? 0),
            'timeout' => 5.0,
            'read_timeout' => 5.0,
            'persistent' => true,
        ],
        'memory' => [
            'driver' => 'array',
            'serialize' => false,
        ],
    ],
    
    // ===================================================================
    // CACHE SETTINGS
    // ===================================================================
    'prefix' => $_ENV['CACHE_PREFIX'] ?? 'mi_core_etl',
    'ttl' => (int)($_ENV['CACHE_TTL'] ?? 3600), // 1 hour default
    
    // ===================================================================
    // CACHE TAGS (for Redis)
    // ===================================================================
    'tags' => [
        'inventory' => 'inventory_data',
        'products' => 'product_data',
        'analytics' => 'analytics_data',
        'api_responses' => 'api_cache',
    ],
    
    // ===================================================================
    // CACHE SERIALIZATION
    // ===================================================================
    'serialization' => [
        'method' => 'json', // json, serialize, igbinary
        'compress' => false,
    ],
    
    // ===================================================================
    // CACHE CLEANUP
    // ===================================================================
    'cleanup' => [
        'probability' => 2, // 2% chance of cleanup on cache write
        'divisor' => 100,
        'max_lifetime' => 86400 * 7, // 7 days
    ],
];