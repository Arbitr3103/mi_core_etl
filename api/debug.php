<?php
/**
 * Диагностическая страница для отладки API
 */

header("Content-Type: application/json; charset=utf-8");
header("Access-Control-Allow-Origin: *");

// Включаем отображение ошибок
error_reporting(E_ALL);
ini_set('display_errors', 1);

$debug_info = [
    'timestamp' => date('Y-m-d H:i:s'),
    'php_version' => PHP_VERSION,
    'server_info' => $_SERVER['SERVER_SOFTWARE'] ?? 'Unknown',
    'document_root' => $_SERVER['DOCUMENT_ROOT'] ?? 'Unknown',
    'script_filename' => __FILE__,
    'current_dir' => __DIR__,
    'parent_dir' => dirname(__DIR__),
];

// Проверяем наличие config.php
$config_paths = [
    __DIR__ . "/../config.php",
    __DIR__ . "/../../config.php", 
    dirname(__DIR__) . "/config.php"
];

$debug_info['config_search'] = [];
foreach ($config_paths as $path) {
    $debug_info['config_search'][] = [
        'path' => $path,
        'exists' => file_exists($path),
        'readable' => file_exists($path) && is_readable($path)
    ];
}

// Пытаемся загрузить конфигурацию
$config_loaded = false;
foreach ($config_paths as $config_path) {
    if (file_exists($config_path)) {
        try {
            require_once $config_path;
            $config_loaded = true;
            $debug_info['config_loaded_from'] = $config_path;
            break;
        } catch (Exception $e) {
            $debug_info['config_error'] = $e->getMessage();
        }
    }
}

$debug_info['config_loaded'] = $config_loaded;

// Проверяем константы базы данных
if ($config_loaded) {
    $debug_info['db_constants'] = [
        'DB_HOST' => defined('DB_HOST') ? DB_HOST : 'NOT_DEFINED',
        'DB_USER' => defined('DB_USER') ? DB_USER : 'NOT_DEFINED', 
        'DB_PASSWORD' => defined('DB_PASSWORD') ? (DB_PASSWORD ? 'SET' : 'EMPTY') : 'NOT_DEFINED',
        'DB_NAME' => defined('DB_NAME') ? DB_NAME : 'NOT_DEFINED'
    ];
    
    // Пытаемся подключиться к базе данных
    try {
        $pdo = new PDO(
            "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4",
            DB_USER,
            DB_PASSWORD,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
        );
        
        $debug_info['database_connection'] = 'SUCCESS';
        
        // Проверяем таблицы
        $tables = $pdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
        $debug_info['database_tables'] = $tables;
        
        // Проверяем product_master
        if (in_array('product_master', $tables)) {
            $count = $pdo->query("SELECT COUNT(*) FROM product_master")->fetchColumn();
            $debug_info['product_master_count'] = $count;
        }
        
    } catch (Exception $e) {
        $debug_info['database_connection'] = 'FAILED';
        $debug_info['database_error'] = $e->getMessage();
    }
} else {
    $debug_info['db_constants'] = 'CONFIG_NOT_LOADED';
}

// Проверяем права на файлы
$debug_info['file_permissions'] = [
    'current_dir_writable' => is_writable(__DIR__),
    'parent_dir_writable' => is_writable(dirname(__DIR__)),
    'api_dir_readable' => is_readable(__DIR__)
];

// Проверяем другие API файлы
$api_files = ['sync-stats.php', 'analytics.php', 'fix-product-names.php'];
$debug_info['api_files'] = [];
foreach ($api_files as $file) {
    $filepath = __DIR__ . '/' . $file;
    $debug_info['api_files'][$file] = [
        'exists' => file_exists($filepath),
        'readable' => file_exists($filepath) && is_readable($filepath),
        'size' => file_exists($filepath) ? filesize($filepath) : 0
    ];
}

echo json_encode($debug_info, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
?>