<?php
/**
 * Database Connection Utility for PostgreSQL
 * Centralized database connection management for mi_core_etl
 */

class DatabaseConnection {
    private static $instance = null;
    private $pdo = null;
    private $config = [];

    private function __construct() {
        $this->loadConfig();
        $this->connect();
    }

    /**
     * Get singleton instance
     */
    public static function getInstance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Get PDO connection
     */
    public function getConnection() {
        // Test connection and reconnect if needed
        try {
            $this->pdo->query('SELECT 1');
        } catch (PDOException $e) {
            $this->connect();
        }
        
        return $this->pdo;
    }

    /**
     * Load configuration from environment
     */
    private function loadConfig() {
        $this->loadEnvFile();
        
        $this->config = [
            'host' => $_ENV['PG_HOST'] ?? 'localhost',
            'port' => $_ENV['PG_PORT'] ?? '5432',
            'database' => $_ENV['PG_NAME'] ?? 'mi_core_db',
            'username' => $_ENV['PG_USER'] ?? 'mi_core_user',
            'password' => $_ENV['PG_PASSWORD'] ?? '',
            'charset' => 'UTF8',
            'timezone' => $_ENV['TIMEZONE'] ?? 'Europe/Moscow'
        ];
    }

    /**
     * Load environment variables from .env file
     */
    private function loadEnvFile() {
        $envFile = dirname(__DIR__, 2) . '/.env';
        
        if (file_exists($envFile)) {
            $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            foreach ($lines as $line) {
                if (strpos($line, '=') !== false && strpos($line, '#') !== 0) {
                    list($key, $value) = explode('=', $line, 2);
                    $key = trim($key);
                    $value = trim($value, '"\'');
                    
                    if (!array_key_exists($key, $_ENV)) {
                        $_ENV[$key] = $value;
                        putenv("$key=$value");
                    }
                }
            }
        }
    }

    /**
     * Establish PostgreSQL connection
     */
    private function connect() {
        try {
            $dsn = sprintf(
                "pgsql:host=%s;port=%s;dbname=%s;options='--client_encoding=%s'",
                $this->config['host'],
                $this->config['port'],
                $this->config['database'],
                $this->config['charset']
            );

            $options = [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
                PDO::ATTR_STRINGIFY_FETCHES => false,
                PDO::ATTR_PERSISTENT => false
            ];

            $this->pdo = new PDO($dsn, $this->config['username'], $this->config['password'], $options);
            
            // Set timezone for this connection
            $this->pdo->exec("SET timezone = '" . $this->config['timezone'] . "'");
            
        } catch (PDOException $e) {
            throw new Exception('PostgreSQL connection failed: ' . $e->getMessage());
        }
    }

    /**
     * Execute query with parameters
     */
    public function query($sql, $params = []) {
        try {
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($params);
            return $stmt;
        } catch (PDOException $e) {
            error_log("Database query error: " . $e->getMessage());
            error_log("SQL: " . $sql);
            error_log("Params: " . json_encode($params));
            throw new Exception('Database query failed: ' . $e->getMessage());
        }
    }

    /**
     * Execute query and fetch all results
     */
    public function fetchAll($sql, $params = []) {
        $stmt = $this->query($sql, $params);
        return $stmt->fetchAll();
    }

    /**
     * Execute query and fetch single result
     */
    public function fetchOne($sql, $params = []) {
        $stmt = $this->query($sql, $params);
        return $stmt->fetch();
    }

    /**
     * Execute query and return affected rows count
     */
    public function execute($sql, $params = []) {
        $stmt = $this->query($sql, $params);
        return $stmt->rowCount();
    }

    /**
     * Begin transaction
     */
    public function beginTransaction() {
        return $this->pdo->beginTransaction();
    }

    /**
     * Commit transaction
     */
    public function commit() {
        return $this->pdo->commit();
    }

    /**
     * Rollback transaction
     */
    public function rollback() {
        return $this->pdo->rollback();
    }

    /**
     * Get last insert ID
     */
    public function lastInsertId($sequence = null) {
        return $this->pdo->lastInsertId($sequence);
    }

    /**
     * Check if table exists
     */
    public function tableExists($tableName) {
        $sql = "SELECT EXISTS (
            SELECT FROM information_schema.tables 
            WHERE table_schema = 'public' AND table_name = ?
        )";
        
        $result = $this->fetchOne($sql, [$tableName]);
        return $result['exists'] ?? false;
    }

    /**
     * Get table row count
     */
    public function getTableCount($tableName) {
        if (!$this->tableExists($tableName)) {
            return 0;
        }
        
        $sql = "SELECT COUNT(*) as count FROM " . $tableName;
        $result = $this->fetchOne($sql);
        return $result['count'] ?? 0;
    }

    /**
     * Get database information
     */
    public function getDatabaseInfo() {
        $info = [];
        
        try {
            // Get PostgreSQL version
            $result = $this->fetchOne('SELECT version()');
            $info['version'] = $result['version'];
            
            // Get database size
            $result = $this->fetchOne("SELECT pg_size_pretty(pg_database_size(current_database())) as size");
            $info['database_size'] = $result['size'];
            
            // Get connection count
            $result = $this->fetchOne('SELECT count(*) as connections FROM pg_stat_activity');
            $info['active_connections'] = $result['connections'];
            
            // Get table count
            $result = $this->fetchOne("SELECT count(*) as tables FROM information_schema.tables WHERE table_schema = 'public'");
            $info['table_count'] = $result['tables'];
            
        } catch (Exception $e) {
            $info['error'] = $e->getMessage();
        }
        
        return $info;
    }

    /**
     * Test database connection
     */
    public function testConnection() {
        try {
            $result = $this->fetchOne('SELECT 1 as test');
            return $result['test'] === 1;
        } catch (Exception $e) {
            return false;
        }
    }

    /**
     * Prevent cloning
     */
    private function __clone() {}

    /**
     * Prevent unserialization
     */
    private function __wakeup() {}
}

/**
 * Helper function to get database connection
 */
function getPostgreSQLConnection() {
    return DatabaseConnection::getInstance()->getConnection();
}

/**
 * Helper function to execute query
 */
function executePostgreSQLQuery($sql, $params = []) {
    return DatabaseConnection::getInstance()->query($sql, $params);
}

/**
 * Helper function to fetch all results
 */
function fetchAllPostgreSQL($sql, $params = []) {
    return DatabaseConnection::getInstance()->fetchAll($sql, $params);
}

/**
 * Helper function to fetch single result
 */
function fetchOnePostgreSQL($sql, $params = []) {
    return DatabaseConnection::getInstance()->fetchOne($sql, $params);
}
?>