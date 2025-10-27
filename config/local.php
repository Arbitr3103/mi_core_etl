<?php
/**
 * Local Development Configuration
 * Loads .env.local for local development
 */

// Load .env.local file
function loadLocalEnv() {
    $envFile = __DIR__ . '/../.env.local';
    
    if (!file_exists($envFile)) {
        error_log("Warning: .env.local file not found");
        return;
    }
    
    $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        $line = trim($line);
        
        // Skip comments
        if (strpos($line, '#') === 0) {
            continue;
        }
        
        // Skip lines without =
        if (strpos($line, '=') === false) {
            continue;
        }
        
        list($name, $value) = explode('=', $line, 2);
        $name = trim($name);
        $value = trim($value);
        
        // Set environment variables
        putenv("$name=$value");
        $_ENV[$name] = $value;
        $_SERVER[$name] = $value;
    }
}

// Load local environment
loadLocalEnv();

// Include main database configuration
require_once __DIR__ . '/database_postgresql.php';

// Override for local development
define('APP_ENV', 'development');
define('APP_DEBUG', true);

// Database connection helper for local development
function getLocalPgConnection() {
    try {
        $host = getenv('PG_HOST') ?: 'localhost';
        $port = getenv('PG_PORT') ?: '5432';
        $dbname = getenv('PG_NAME') ?: 'mi_core_db';
        $user = getenv('PG_USER') ?: get_current_user();
        $password = getenv('PG_PASSWORD') ?: '';
        
        $dsn = "pgsql:host=$host;port=$port;dbname=$dbname";
        
        $pdo = new PDO($dsn, $user, $password, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
        
        return $pdo;
    } catch (PDOException $e) {
        error_log("Database connection error: " . $e->getMessage());
        throw $e;
    }
}

?>
