<?php
/**
 * Database Connection Pool for Regional Analytics
 * Optimizes database connections for production environment
 * Requirements: 5.3
 */

class DatabaseConnectionPool {
    private static $instance = null;
    private $connections = [];
    private $maxConnections = 10;
    private $currentConnections = 0;
    private $config;
    
    private function __construct() {
        $this->config = [
            'host' => $_ENV['DB_HOST'] ?? 'localhost',
            'dbname' => $_ENV['DB_NAME'] ?? 'mi_core_db',
            'username' => $_ENV['DB_USER'] ?? 'root',
            'password' => $_ENV['DB_PASS'] ?? '',
            'charset' => 'utf8mb4'
        ];
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
     * Get database connection from pool
     */
    public function getConnection() {
        // Try to reuse existing connection
        if (!empty($this->connections)) {
            $connection = array_pop($this->connections);
            if ($this->isConnectionAlive($connection)) {
                return $connection;
            }
        }
        
        // Create new connection if under limit
        if ($this->currentConnections < $this->maxConnections) {
            $connection = $this->createConnection();
            $this->currentConnections++;
            return $connection;
        }
        
        // Wait for available connection
        $maxWait = 30; // seconds
        $waited = 0;
        while (empty($this->connections) && $waited < $maxWait) {
            usleep(100000); // 0.1 second
            $waited += 0.1;
        }
        
        if (!empty($this->connections)) {
            return array_pop($this->connections);
        }
        
        throw new Exception('No database connections available');
    }
    
    /**
     * Return connection to pool
     */
    public function returnConnection($connection) {
        if ($this->isConnectionAlive($connection)) {
            $this->connections[] = $connection;
        } else {
            $this->currentConnections--;
        }
    }
    
    /**
     * Create new database connection
     */
    private function createConnection() {
        $dsn = "mysql:host={$this->config['host']};dbname={$this->config['dbname']};charset={$this->config['charset']}";
        
        $options = [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::ATTR_PERSISTENT => true,
            PDO::MYSQL_ATTR_INIT_COMMAND => "SET sql_mode='STRICT_TRANS_TABLES,ERROR_FOR_DIVISION_BY_ZERO,NO_AUTO_CREATE_USER,NO_ENGINE_SUBSTITUTION'",
            PDO::MYSQL_ATTR_USE_BUFFERED_QUERY => true,
            PDO::ATTR_TIMEOUT => 30
        ];
        
        return new PDO($dsn, $this->config['username'], $this->config['password'], $options);
    }
    
    /**
     * Check if connection is still alive
     */
    private function isConnectionAlive($connection) {
        try {
            $connection->query('SELECT 1');
            return true;
        } catch (PDOException $e) {
            return false;
        }
    }
    
    /**
     * Close all connections
     */
    public function closeAllConnections() {
        $this->connections = [];
        $this->currentConnections = 0;
    }
    
    /**
     * Get connection statistics
     */
    public function getStats() {
        return [
            'total_connections' => $this->currentConnections,
            'available_connections' => count($this->connections),
            'max_connections' => $this->maxConnections,
            'pool_utilization' => round(($this->currentConnections / $this->maxConnections) * 100, 2)
        ];
    }
}

/**
 * Database Manager with Connection Pooling
 */
class DatabaseManager {
    private $pool;
    
    public function __construct() {
        $this->pool = DatabaseConnectionPool::getInstance();
    }
    
    /**
     * Execute query with connection pooling
     */
    public function query($sql, $params = []) {
        $connection = $this->pool->getConnection();
        
        try {
            if (empty($params)) {
                $result = $connection->query($sql);
            } else {
                $stmt = $connection->prepare($sql);
                $stmt->execute($params);
                $result = $stmt;
            }
            
            $data = $result->fetchAll();
            $this->pool->returnConnection($connection);
            
            return $data;
            
        } catch (Exception $e) {
            $this->pool->returnConnection($connection);
            throw $e;
        }
    }
    
    /**
     * Execute prepared statement with connection pooling
     */
    public function execute($sql, $params = []) {
        $connection = $this->pool->getConnection();
        
        try {
            $stmt = $connection->prepare($sql);
            $result = $stmt->execute($params);
            
            $this->pool->returnConnection($connection);
            
            return $result;
            
        } catch (Exception $e) {
            $this->pool->returnConnection($connection);
            throw $e;
        }
    }
    
    /**
     * Get single row
     */
    public function fetchRow($sql, $params = []) {
        $connection = $this->pool->getConnection();
        
        try {
            if (empty($params)) {
                $result = $connection->query($sql);
            } else {
                $stmt = $connection->prepare($sql);
                $stmt->execute($params);
                $result = $stmt;
            }
            
            $data = $result->fetch();
            $this->pool->returnConnection($connection);
            
            return $data;
            
        } catch (Exception $e) {
            $this->pool->returnConnection($connection);
            throw $e;
        }
    }
    
    /**
     * Get connection pool statistics
     */
    public function getPoolStats() {
        return $this->pool->getStats();
    }
}