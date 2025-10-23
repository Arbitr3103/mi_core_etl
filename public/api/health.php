<?php
/**
 * Health Check Endpoint
 * Monitors the status of API and database connections
 */

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../../src/utils/Database.php';
require_once __DIR__ . '/../../src/utils/Logger.php';

header('Content-Type: application/json');

$health = [
    'status' => 'healthy',
    'timestamp' => date('Y-m-d H:i:s'),
    'checks' => []
];

$allHealthy = true;

// Check database connection
try {
    $db = new Database();
    $connection = $db->getConnection();
    
    // Test query
    $stmt = $connection->query('SELECT 1');
    $result = $stmt->fetch();
    
    if ($result) {
        $health['checks']['database'] = [
            'status' => 'healthy',
            'message' => 'Database connection successful',
            'type' => getenv('DB_CONNECTION') ?: 'mysql'
        ];
    } else {
        throw new Exception('Database query failed');
    }
} catch (Exception $e) {
    $allHealthy = false;
    $health['checks']['database'] = [
        'status' => 'unhealthy',
        'message' => 'Database connection failed: ' . $e->getMessage()
    ];
}

// Check storage directories
$storageChecks = [
    'logs' => __DIR__ . '/../../storage/logs',
    'cache' => __DIR__ . '/../../storage/cache',
    'backups' => __DIR__ . '/../../storage/backups'
];

foreach ($storageChecks as $name => $path) {
    if (is_dir($path) && is_writable($path)) {
        $health['checks']['storage_' . $name] = [
            'status' => 'healthy',
            'message' => ucfirst($name) . ' directory is writable'
        ];
    } else {
        $allHealthy = false;
        $health['checks']['storage_' . $name] = [
            'status' => 'unhealthy',
            'message' => ucfirst($name) . ' directory is not writable or does not exist'
        ];
    }
}

// Check PHP version
$phpVersion = phpversion();
$minPhpVersion = '7.4.0';
if (version_compare($phpVersion, $minPhpVersion, '>=')) {
    $health['checks']['php_version'] = [
        'status' => 'healthy',
        'message' => 'PHP version is compatible',
        'version' => $phpVersion
    ];
} else {
    $allHealthy = false;
    $health['checks']['php_version'] = [
        'status' => 'unhealthy',
        'message' => 'PHP version is too old',
        'version' => $phpVersion,
        'required' => $minPhpVersion
    ];
}

// Check required extensions
$requiredExtensions = ['pdo', 'json', 'mbstring'];
$dbConnection = getenv('DB_CONNECTION') ?: 'mysql';
if ($dbConnection === 'pgsql') {
    $requiredExtensions[] = 'pdo_pgsql';
} else {
    $requiredExtensions[] = 'pdo_mysql';
}

$missingExtensions = [];
foreach ($requiredExtensions as $ext) {
    if (!extension_loaded($ext)) {
        $missingExtensions[] = $ext;
    }
}

if (empty($missingExtensions)) {
    $health['checks']['php_extensions'] = [
        'status' => 'healthy',
        'message' => 'All required PHP extensions are loaded'
    ];
} else {
    $allHealthy = false;
    $health['checks']['php_extensions'] = [
        'status' => 'unhealthy',
        'message' => 'Missing required PHP extensions',
        'missing' => $missingExtensions
    ];
}

// Check environment file
if (file_exists(__DIR__ . '/../../.env')) {
    $health['checks']['environment'] = [
        'status' => 'healthy',
        'message' => 'Environment file exists'
    ];
} else {
    $allHealthy = false;
    $health['checks']['environment'] = [
        'status' => 'unhealthy',
        'message' => 'Environment file not found'
    ];
}

// Set overall status
$health['status'] = $allHealthy ? 'healthy' : 'unhealthy';

// Set HTTP status code
http_response_code($allHealthy ? 200 : 503);

// Output JSON
echo json_encode($health, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
