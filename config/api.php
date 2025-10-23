<?php
/**
 * API Configuration for mi_core_etl
 * 
 * API settings loaded from environment variables
 */

// Load environment variables if not already loaded
if (!function_exists('loadEnvFile')) {
    require_once __DIR__ . '/app.php';
}

return [
    // ===================================================================
    // API BASIC SETTINGS
    // ===================================================================
    'version' => $_ENV['VERSION'] ?? '1.0.0',
    'base_url' => $_ENV['API_BASE_URL'] ?? 'http://localhost/api',
    
    // ===================================================================
    // RATE LIMITING
    // ===================================================================
    'rate_limit' => [
        'enabled' => filter_var($_ENV['RATE_LIMIT_ENABLED'] ?? true, FILTER_VALIDATE_BOOLEAN),
        'requests_per_minute' => (int)($_ENV['RATE_LIMIT_RPM'] ?? 60),
    ],
    
    // ===================================================================
    // CORS CONFIGURATION
    // ===================================================================
    'cors' => [
        'enabled' => filter_var($_ENV['CORS_ENABLED'] ?? true, FILTER_VALIDATE_BOOLEAN),
        'allowed_origins' => explode(',', $_ENV['CORS_ALLOWED_ORIGINS'] ?? '*'),
        'allowed_methods' => ['GET', 'POST', 'PUT', 'DELETE', 'OPTIONS'],
        'allowed_headers' => ['Content-Type', 'Authorization', 'X-Requested-With'],
        'max_age' => 86400, // 24 hours
    ],
    
    // ===================================================================
    // API CACHE SETTINGS
    // ===================================================================
    'cache' => [
        'enabled' => filter_var($_ENV['API_CACHE_ENABLED'] ?? true, FILTER_VALIDATE_BOOLEAN),
        'ttl' => (int)($_ENV['API_CACHE_TTL'] ?? 300), // 5 minutes
        'prefix' => 'api_',
    ],
    
    // ===================================================================
    // EXTERNAL API SETTINGS
    // ===================================================================
    'external_apis' => [
        'ozon' => [
            'client_id' => $_ENV['OZON_CLIENT_ID'] ?? '',
            'api_key' => $_ENV['OZON_API_KEY'] ?? '',
            'base_url' => 'https://api-seller.ozon.ru',
            'request_delay' => (float)($_ENV['OZON_REQUEST_DELAY'] ?? 0.1),
            'timeout' => (int)($_ENV['REQUEST_TIMEOUT'] ?? 30),
            'max_retries' => (int)($_ENV['MAX_RETRIES'] ?? 3),
        ],
        'wildberries' => [
            'api_key' => $_ENV['WB_API_KEY'] ?? '',
            'suppliers_url' => 'https://suppliers-api.wildberries.ru',
            'content_url' => 'https://content-api.wildberries.ru',
            'statistics_url' => 'https://statistics-api.wildberries.ru',
            'request_delay' => (float)($_ENV['WB_REQUEST_DELAY'] ?? 0.5),
            'timeout' => (int)($_ENV['REQUEST_TIMEOUT'] ?? 30),
            'max_retries' => (int)($_ENV['MAX_RETRIES'] ?? 3),
        ],
    ],
    
    // ===================================================================
    // ERROR HANDLING
    // ===================================================================
    'error_handling' => [
        'show_details' => filter_var($_ENV['APP_DEBUG'] ?? false, FILTER_VALIDATE_BOOLEAN),
        'log_errors' => true,
        'default_error_message' => 'An error occurred while processing your request',
    ],
    
    // ===================================================================
    // RESPONSE SETTINGS
    // ===================================================================
    'response' => [
        'default_format' => 'json',
        'pretty_print' => filter_var($_ENV['APP_DEBUG'] ?? false, FILTER_VALIDATE_BOOLEAN),
        'include_metadata' => true,
    ],
];