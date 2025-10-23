<?php
/**
 * Logging Configuration for mi_core_etl
 * 
 * Logging settings loaded from environment variables
 */

// Load environment variables if not already loaded
if (!function_exists('loadEnvFile')) {
    require_once __DIR__ . '/app.php';
}

return [
    // ===================================================================
    // DEFAULT LOG CHANNEL
    // ===================================================================
    'default' => $_ENV['LOG_CHANNEL'] ?? 'file',
    
    // ===================================================================
    // LOG CHANNELS
    // ===================================================================
    'channels' => [
        'file' => [
            'driver' => 'file',
            'path' => $_ENV['LOG_PATH'] ?? __DIR__ . '/../storage/logs',
            'level' => $_ENV['LOG_LEVEL'] ?? 'info',
            'max_files' => (int)($_ENV['LOG_MAX_FILES'] ?? 30),
            'max_size' => $_ENV['LOG_MAX_SIZE'] ?? '100MB',
            'permissions' => 0644,
        ],
        'daily' => [
            'driver' => 'daily',
            'path' => $_ENV['LOG_PATH'] ?? __DIR__ . '/../storage/logs',
            'level' => $_ENV['LOG_LEVEL'] ?? 'info',
            'days' => (int)($_ENV['LOG_MAX_FILES'] ?? 30),
            'max_size' => $_ENV['LOG_MAX_SIZE'] ?? '100MB',
        ],
        'syslog' => [
            'driver' => 'syslog',
            'level' => $_ENV['LOG_LEVEL'] ?? 'info',
            'facility' => LOG_USER,
            'ident' => 'mi_core_etl',
        ],
        'error_log' => [
            'driver' => 'errorlog',
            'level' => 'error',
        ],
    ],
    
    // ===================================================================
    // LOG FORMATTING
    // ===================================================================
    'format' => $_ENV['LOG_FORMAT'] ?? '[%datetime%] %level_name%: %message% %context%',
    'date_format' => 'Y-m-d H:i:s',
    'include_stacktrace' => filter_var($_ENV['APP_DEBUG'] ?? false, FILTER_VALIDATE_BOOLEAN),
    
    // ===================================================================
    // LOG LEVELS
    // ===================================================================
    'levels' => [
        'emergency' => 0,
        'alert' => 1,
        'critical' => 2,
        'error' => 3,
        'warning' => 4,
        'notice' => 5,
        'info' => 6,
        'debug' => 7,
    ],
    
    // ===================================================================
    // STRUCTURED LOGGING
    // ===================================================================
    'structured' => [
        'enabled' => true,
        'include_context' => true,
        'include_extra' => true,
        'max_context_depth' => 3,
    ],
    
    // ===================================================================
    // LOG ROTATION
    // ===================================================================
    'rotation' => [
        'enabled' => true,
        'compress' => true,
        'delete_old' => true,
    ],
    
    // ===================================================================
    // PERFORMANCE LOGGING
    // ===================================================================
    'performance' => [
        'enabled' => filter_var($_ENV['APP_DEBUG'] ?? false, FILTER_VALIDATE_BOOLEAN),
        'slow_query_threshold' => 1000, // milliseconds
        'memory_usage_threshold' => 50 * 1024 * 1024, // 50MB
    ],
];