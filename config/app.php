<?php
/**
 * Application Configuration for mi_core_etl
 * 
 * Main application settings loaded from environment variables
 */

// Load environment variables if not already loaded
if (!function_exists('loadEnvFile')) {
    function loadEnvFile($path = '.env') {
        if (!file_exists($path)) {
            return;
        }
        
        $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        foreach ($lines as $line) {
            if (strpos(trim($line), '#') === 0) {
                continue; // Skip comments
            }
            
            if (strpos($line, '=') === false) {
                continue; // Skip lines without =
            }
            
            list($name, $value) = explode('=', $line, 2);
            $name = trim($name);
            $value = trim($value, '"\'');
            
            if (!array_key_exists($name, $_SERVER) && !array_key_exists($name, $_ENV)) {
                putenv(sprintf('%s=%s', $name, $value));
                $_ENV[$name] = $value;
                $_SERVER[$name] = $value;
            }
        }
    }
    
    // Load .env file from project root
    $envPath = dirname(__DIR__) . '/.env';
    loadEnvFile($envPath);
}

return [
    // ===================================================================
    // APPLICATION SETTINGS
    // ===================================================================
    'name' => 'mi_core_etl',
    'version' => $_ENV['VERSION'] ?? '1.0.0',
    'env' => $_ENV['APP_ENV'] ?? 'production',
    'debug' => filter_var($_ENV['APP_DEBUG'] ?? false, FILTER_VALIDATE_BOOLEAN),
    'timezone' => $_ENV['TIMEZONE'] ?? 'Europe/Moscow',
    
    // ===================================================================
    // SECURITY SETTINGS
    // ===================================================================
    'jwt_secret' => $_ENV['JWT_SECRET'] ?? '',
    'encryption_key' => $_ENV['ENCRYPTION_KEY'] ?? '',
    
    // ===================================================================
    // PERFORMANCE SETTINGS
    // ===================================================================
    'max_connections' => (int)($_ENV['MAX_CONNECTIONS'] ?? 200),
    'query_timeout' => (int)($_ENV['QUERY_TIMEOUT'] ?? 30),
    'request_timeout' => (int)($_ENV['REQUEST_TIMEOUT'] ?? 30),
    'max_retries' => (int)($_ENV['MAX_RETRIES'] ?? 3),
    
    // ===================================================================
    // PATHS
    // ===================================================================
    'temp_dir' => $_ENV['TEMP_DIR'] ?? '/tmp/mi_core_etl',
    'upload_max_size' => $_ENV['UPLOAD_MAX_SIZE'] ?? '50MB',
    
    // ===================================================================
    // SESSION SETTINGS
    // ===================================================================
    'session_timeout' => (int)($_ENV['SESSION_TIMEOUT'] ?? 3600),
    
    // ===================================================================
    // SSL SETTINGS
    // ===================================================================
    'ssl' => [
        'cert_path' => $_ENV['SSL_CERT_PATH'] ?? '',
        'key_path' => $_ENV['SSL_KEY_PATH'] ?? '',
    ],
];