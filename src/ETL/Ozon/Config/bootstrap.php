<?php

/**
 * Ozon ETL System Bootstrap Configuration
 * 
 * Main bootstrap file that loads all configurations and sets up
 * the environment for the Ozon ETL system
 */

// Load environment variables if not already loaded
if (!isset($_ENV['APP_ENV'])) {
    $envFile = __DIR__ . '/../../../../.env';
    if (file_exists($envFile) && class_exists('\Dotenv\Dotenv')) {
        try {
            $dotenv = \Dotenv\Dotenv::createImmutable(dirname($envFile));
            $dotenv->load();
        } catch (Exception $e) {
            // Dotenv loading failed, continue with existing environment
        }
    }
}

// Set default timezone
date_default_timezone_set($_ENV['APP_TIMEZONE'] ?? 'Europe/Moscow');

// Set memory limit for ETL processes
ini_set('memory_limit', $_ENV['ETL_MEMORY_LIMIT'] ?? '512M');

// Set execution time limit
set_time_limit($_ENV['ETL_TIME_LIMIT'] ?? 3600);

// Load all configuration files
$config = [
    'etl' => require __DIR__ . '/ozon_etl.php',
    'api' => require __DIR__ . '/api_config.php', 
    'database' => require __DIR__ . '/database_config.php'
];

// Validate required environment variables (only in production mode)
if (($_ENV['APP_ENV'] ?? 'development') === 'production') {
    $requiredEnvVars = [
        'OZON_CLIENT_ID',
        'OZON_API_KEY',
        'DB_HOST',
        'DB_DATABASE',
        'DB_USERNAME',
        'DB_PASSWORD'
    ];

    $missingVars = [];
    foreach ($requiredEnvVars as $var) {
        if (empty($_ENV[$var])) {
            $missingVars[] = $var;
        }
    }

    if (!empty($missingVars)) {
        throw new \RuntimeException(
            'Missing required environment variables: ' . implode(', ', $missingVars)
        );
    }
}

// Create necessary directories
$directories = [
    $config['etl']['logging']['path'],
    $config['etl']['locks']['path'],
    $config['api']['reports']['temp_dir'],
    $config['database']['backup']['path']
];

foreach ($directories as $dir) {
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }
}

// Set error reporting based on environment
if (($_ENV['APP_ENV'] ?? 'production') === 'production') {
    error_reporting(E_ERROR | E_WARNING | E_PARSE);
    ini_set('display_errors', '0');
    ini_set('log_errors', '1');
} else {
    error_reporting(E_ALL);
    ini_set('display_errors', '1');
}

return $config;