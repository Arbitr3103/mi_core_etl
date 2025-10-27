<?php
/**
 * PostgreSQL Database Configuration for mi_core_etl
 * 
 * Updated configuration for PostgreSQL migration
 * All secret data loaded from .env file
 */

// Load environment variables using function from app.php
if (!function_exists('loadEnvFile')) {
    require_once __DIR__ . '/app.php';
}

// ===================================================================
// POSTGRESQL DATABASE SETTINGS
// ===================================================================
define('DB_TYPE', 'postgresql');
define('DB_HOST', getenv('PG_HOST') ?: 'localhost');
define('DB_USER', getenv('PG_USER') ?: 'mi_core_user');
define('DB_PASSWORD', getenv('PG_PASSWORD') ?: '');
define('DB_NAME', getenv('PG_NAME') ?: 'mi_core_db');
define('DB_PORT', getenv('PG_PORT') ?: '5432');

// Connection options
define('DB_CHARSET', 'UTF8');
define('DB_TIMEZONE', 'Europe/Moscow');

// ===================================================================
// API SETTINGS (unchanged)
// ===================================================================
define('OZON_CLIENT_ID', getenv('OZON_CLIENT_ID') ?: '');
define('OZON_API_KEY', getenv('OZON_API_KEY') ?: '');
define('WB_API_KEY', getenv('WB_API_KEY') ?: '');

// Base URLs for APIs
define('OZON_API_BASE_URL', 'https://api-seller.ozon.ru');
define('WB_SUPPLIERS_API_URL', 'https://suppliers-api.wildberries.ru');
define('WB_CONTENT_API_URL', 'https://content-api.wildberries.ru');
define('WB_STATISTICS_API_URL', 'https://statistics-api.wildberries.ru');

// ===================================================================
// SYSTEM SETTINGS
// ===================================================================
define('LOG_LEVEL', 'INFO');
define('LOG_DIR', 'logs');
define('TEMP_DIR', '/tmp/mdm_system');
define('TIMEZONE', 'Europe/Moscow');

// Set timezone
date_default_timezone_set(TIMEZONE);

// ===================================================================
// REQUEST SETTINGS
// ===================================================================
define('REQUEST_TIMEOUT', 30);
define('MAX_RETRIES', 3);
define('OZON_REQUEST_DELAY', 0.1);
define('WB_REQUEST_DELAY', 0.5);

// ===================================================================
// POSTGRESQL CONNECTION FUNCTIONS
// ===================================================================

/**
 * Create and return PDO connection to PostgreSQL database
 * @return PDO
 */
function getDatabaseConnection() {
    try {
        $dsn = sprintf(
            "pgsql:host=%s;port=%s;dbname=%s;options='--client_encoding=%s'",
            DB_HOST,
            DB_PORT,
            DB_NAME,
            DB_CHARSET
        );
        
        $options = [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::ATTR_STRINGIFY_FETCHES => false,
        ];
        
        $pdo = new PDO($dsn, DB_USER, DB_PASSWORD, $options);
        
        // Set timezone for this connection
        $pdo->exec("SET timezone = '" . DB_TIMEZONE . "'");
        
        return $pdo;
    } catch (PDOException $e) {
        throw new Exception('PostgreSQL connection failed: ' . $e->getMessage());
    }
}

/**
 * Get database connection with connection pooling
 * @return PDO
 */
function getPooledDatabaseConnection() {
    static $connection = null;
    
    if ($connection === null) {
        $connection = getDatabaseConnection();
    }
    
    // Test connection
    try {
        $connection->query('SELECT 1');
    } catch (PDOException $e) {
        // Reconnect if connection is lost
        $connection = getDatabaseConnection();
    }
    
    return $connection;
}

/**
 * Execute a query with error handling and logging
 * @param string $query
 * @param array $params
 * @param PDO|null $pdo
 * @return array|bool
 */
function executeQuery($query, $params = [], $pdo = null) {
    if ($pdo === null) {
        $pdo = getPooledDatabaseConnection();
    }
    
    try {
        $stmt = $pdo->prepare($query);
        $result = $stmt->execute($params);
        
        // Return results for SELECT queries
        if (stripos(trim($query), 'SELECT') === 0) {
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        }
        
        return $result;
        
    } catch (PDOException $e) {
        error_log("Database query error: " . $e->getMessage());
        error_log("Query: " . $query);
        error_log("Params: " . json_encode($params));
        throw new Exception('Database query failed: ' . $e->getMessage());
    }
}

/**
 * Execute a query and return single row
 * @param string $query
 * @param array $params
 * @param PDO|null $pdo
 * @return array|null
 */
function executeQuerySingle($query, $params = [], $pdo = null) {
    $results = executeQuery($query, $params, $pdo);
    return is_array($results) && count($results) > 0 ? $results[0] : null;
}

/**
 * Begin database transaction
 * @param PDO|null $pdo
 * @return PDO
 */
function beginTransaction($pdo = null) {
    if ($pdo === null) {
        $pdo = getPooledDatabaseConnection();
    }
    
    $pdo->beginTransaction();
    return $pdo;
}

/**
 * Commit database transaction
 * @param PDO $pdo
 */
function commitTransaction($pdo) {
    $pdo->commit();
}

/**
 * Rollback database transaction
 * @param PDO $pdo
 */
function rollbackTransaction($pdo) {
    $pdo->rollback();
}

// ===================================================================
// CONFIGURATION VALIDATION FUNCTIONS
// ===================================================================

/**
 * Validate PostgreSQL configuration
 * @return array Array with errors and warnings
 */
function validateConfig() {
    $errors = [];
    $warnings = [];
    
    // Check database settings
    if (!DB_USER) {
        $errors[] = 'PG_USER not found in .env file';
    }
    
    if (!DB_PASSWORD) {
        $warnings[] = 'PG_PASSWORD not found in .env file';
    }
    
    if (!DB_NAME) {
        $errors[] = 'PG_NAME not found in .env file';
    }
    
    // Test database connection
    try {
        $pdo = getDatabaseConnection();
        $result = $pdo->query('SELECT version()')->fetch();
        if (!$result) {
            $errors[] = 'Unable to query PostgreSQL version';
        }
    } catch (Exception $e) {
        $errors[] = 'PostgreSQL connection test failed: ' . $e->getMessage();
    }
    
    // Check API keys (not critical for basic operation)
    if (!OZON_CLIENT_ID) {
        $warnings[] = 'OZON_CLIENT_ID not found in .env file';
    }
    
    if (!OZON_API_KEY) {
        $warnings[] = 'OZON_API_KEY not found in .env file';
    }
    
    if (!WB_API_KEY) {
        $warnings[] = 'WB_API_KEY not found in .env file';
    }
    
    return ['errors' => $errors, 'warnings' => $warnings];
}

/**
 * Print configuration status
 */
function printConfigStatus() {
    echo "📋 POSTGRESQL CONFIGURATION STATUS:\n";
    echo str_repeat('=', 50) . "\n";
    
    echo "DB_TYPE: " . DB_TYPE . "\n";
    echo "DB_HOST: " . DB_HOST . "\n";
    echo "DB_PORT: " . DB_PORT . "\n";
    echo "DB_NAME: " . DB_NAME . "\n";
    echo "DB_USER: " . (DB_USER ? '✅ Loaded' : '❌ Missing') . "\n";
    echo "DB_PASSWORD: " . (DB_PASSWORD ? '✅ Loaded' : '❌ Missing') . "\n";
    
    // Test connection
    try {
        $pdo = getDatabaseConnection();
        $result = $pdo->query('SELECT version()')->fetch();
        echo "PostgreSQL Version: ✅ " . $result['version'] . "\n";
        
        // Test basic query
        $count = $pdo->query('SELECT COUNT(*) as count FROM information_schema.tables WHERE table_schema = \'public\'')->fetch();
        echo "Tables in database: " . $count['count'] . "\n";
        
    } catch (Exception $e) {
        echo "Database Connection: ❌ " . $e->getMessage() . "\n";
    }
    
    echo "\nAPI Configuration:\n";
    echo "OZON_CLIENT_ID: " . (OZON_CLIENT_ID ? '✅ Loaded' : '❌ Missing') . " (" . strlen(OZON_CLIENT_ID) . " chars)\n";
    echo "OZON_API_KEY: " . (OZON_API_KEY ? '✅ Loaded' : '❌ Missing') . " (" . strlen(OZON_API_KEY) . " chars)\n";
    echo "WB_API_KEY: " . (WB_API_KEY ? '✅ Loaded' : '❌ Missing') . " (" . strlen(WB_API_KEY) . " chars)\n";
    
    $validation = validateConfig();
    
    if (!empty($validation['warnings'])) {
        echo "\n⚠️ WARNINGS:\n";
        foreach ($validation['warnings'] as $warning) {
            echo "  - $warning\n";
        }
    }
    
    if (!empty($validation['errors'])) {
        echo "\n❌ CONFIGURATION ERRORS:\n";
        foreach ($validation['errors'] as $error) {
            echo "  - $error\n";
        }
    } else {
        echo "\n✅ PostgreSQL configuration is valid!\n";
    }
    
    echo "\n🌐 API ENDPOINTS:\n";
    echo "Ozon API: " . OZON_API_BASE_URL . "\n";
    echo "WB Suppliers API: " . WB_SUPPLIERS_API_URL . "\n";
    echo "WB Statistics API: " . WB_STATISTICS_API_URL . "\n";
}

// ===================================================================
// POSTGRESQL-SPECIFIC HELPER FUNCTIONS
// ===================================================================

/**
 * Get PostgreSQL-specific information
 * @return array
 */
function getPostgreSQLInfo() {
    try {
        $pdo = getDatabaseConnection();
        
        $info = [];
        
        // Get version
        $result = $pdo->query('SELECT version()')->fetch();
        $info['version'] = $result['version'];
        
        // Get database size
        $result = $pdo->query("SELECT pg_size_pretty(pg_database_size(current_database())) as size")->fetch();
        $info['database_size'] = $result['size'];
        
        // Get connection count
        $result = $pdo->query('SELECT count(*) as connections FROM pg_stat_activity')->fetch();
        $info['active_connections'] = $result['connections'];
        
        // Get table count
        $result = $pdo->query("SELECT count(*) as tables FROM information_schema.tables WHERE table_schema = 'public'")->fetch();
        $info['table_count'] = $result['tables'];
        
        return $info;
        
    } catch (Exception $e) {
        return ['error' => $e->getMessage()];
    }
}

/**
 * Check if PostgreSQL extensions are available
 * @return array
 */
function checkPostgreSQLExtensions() {
    try {
        $pdo = getDatabaseConnection();
        
        $extensions = ['uuid-ossp', 'pg_trgm', 'btree_gin'];
        $status = [];
        
        foreach ($extensions as $ext) {
            $result = $pdo->query("SELECT EXISTS(SELECT 1 FROM pg_extension WHERE extname = '$ext') as installed")->fetch();
            $status[$ext] = $result['installed'] ? 'installed' : 'not_installed';
        }
        
        return $status;
        
    } catch (Exception $e) {
        return ['error' => $e->getMessage()];
    }
}

// Create global connection if not in CLI mode
if (!isset($pdo)) {
    try {
        $pdo = getDatabaseConnection();
    } catch (Exception $e) {
        // In CLI mode it's not critical, in web mode it is
        if (php_sapi_name() !== 'cli') {
            die('PostgreSQL connection error: ' . $e->getMessage());
        }
    }
}

// If file is run directly, show configuration status
if (php_sapi_name() === 'cli' && basename(__FILE__) === basename($_SERVER['SCRIPT_NAME'])) {
    printConfigStatus();
    
    echo "\n🔧 PostgreSQL Extensions:\n";
    $extensions = checkPostgreSQLExtensions();
    foreach ($extensions as $ext => $status) {
        $icon = $status === 'installed' ? '✅' : '❌';
        echo "  $ext: $icon $status\n";
    }
    
    echo "\n📊 Database Information:\n";
    $info = getPostgreSQLInfo();
    if (isset($info['error'])) {
        echo "  Error: " . $info['error'] . "\n";
    } else {
        foreach ($info as $key => $value) {
            echo "  " . ucfirst(str_replace('_', ' ', $key)) . ": $value\n";
        }
    }
}

?>