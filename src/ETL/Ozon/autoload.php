<?php

/**
 * Ozon ETL System Autoloader
 * 
 * Simple autoloader for the Ozon ETL system that ensures
 * Composer autoloader is loaded and configurations are available
 */

// Find and load Composer autoloader
$autoloadPaths = [
    __DIR__ . '/../../../vendor/autoload.php',
    __DIR__ . '/../../../../vendor/autoload.php',
    __DIR__ . '/../../../../../vendor/autoload.php'
];

$autoloaderFound = false;
foreach ($autoloadPaths as $path) {
    if (file_exists($path)) {
        require_once $path;
        $autoloaderFound = true;
        break;
    }
}

if (!$autoloaderFound) {
    throw new \RuntimeException(
        'Composer autoloader not found. Please run "composer install" first.'
    );
}

// Load bootstrap configuration
$config = require __DIR__ . '/Config/bootstrap.php';

// Make configuration globally available
if (!defined('OZON_ETL_CONFIG')) {
    define('OZON_ETL_CONFIG', $config);
}

return $config;