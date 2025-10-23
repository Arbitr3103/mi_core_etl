<?php
/**
 * Database Utility for mi_core_etl
 * Enhanced database management with connection pooling, transactions, and monitoring
 */

require_once __DIR__ . '/Logger.php';

class Database {
    private static $instance = null;
    private $pdo = null;
    private $config = [];
    private $logger;
    private $transactionLevel = 0;
    private $queryCount = 0;
    private $totalQueryTime = 0;
    
    private function __construct() {
        $this->logger = Logger::getInstance();
        $this->loadConfig();
        $this->connect();
    }
    
    /**
     * Get singleton instance
     */
    public static function getInstance(): Database {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    /**
     * Load configuration from environment and config files
     */
    private function loadConfig(): void {
        $this->loadEnvFile();
        
        // Load from config file if exists
        $configFile = __DIR__ . '/../../config/database_postgresql.php';
        if (file_exists($configFile)) {
            require_once $configFile;
        }
        
        $this->config = [
            'host' => $_ENV['PG_HOST'] ?? 'localhost',
            'port' => $_ENV['PG_PORT'] ?? '5432',
            'database' => $_ENV['PG_NAME'] ?? 'mi_core_db',
            'username' => $_ENV['PG_USER'] ?? 'mi_core_user',
            'password' => $_ENV['PG_PASSWORD'] ?? '',
            'charset' => 'UTF8',
            'timezone' => $_ENV['TIMEZONE'] ?? 'Europe/Moscow',
            'options' => [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
                PDO::ATTR_STRINGIFY_FETCHES => false,
                PDO::ATTR_PERSISTENT => false
            ]
        ];
    }
    
    /**
     * Load environment variables from .env file
     */
    private function loadEnvFile(): void {
        $envFile = __DIR__ . '/../../.env';
        
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
    private function connect(): void {
        try {
            $dsn = sprintf(
                "pgsql:host=%s;port=%s;dbname=%s;options='--client_encoding=%s'",
                $this->config['host'],
                $this->config['port'],
                $this->config['database'],
                $this->config['charset']
            );
            
            $this->pdo = new PDO($dsn, $this->config['username'], $this->config['password'], $this->config['options']);
            
            // Set timezone for this connection
            $this->pdo->exec("SET timezone = '" . $this->config['timezone'] . "'");
            
            $this->logger->info('Database connection established', [
                'host' => $this->config['host'],
                'database' => $this->config['database'],
                'user' => $this->config['username']
            ]);
            
        } catch (PDOException $e) {
            $this->logger->error('PostgreSQL connection failed', [
                'error' => $e->getMessage(),
                'host' => $this->config['host'],
                'database' => $this->config['database']
            ]);
            throw new Exception('PostgreSQL connection failed: ' . $e->getMessage());
        }
    }
    
    /**
     * Get PDO connection with health check
     */
    public function getConnection(): PDO {
        // Test connection and reconnect if needed
        try {
            $this->pdo->query('SELECT 1');
        } catch (PDOException $e) {
            $this->logger->warning('Database connection lost, reconnecting', ['error' => $e->getMessage()]);
            $this->connect();
        }
        
        return $this->pdo;
    }
    
    /**
     * Execute query with parameters and performance monitoring
     */
    public function query(string $sql, array $params = []): PDOStatement {
        $startTime = microtime(true);
        
        try {
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($params);
            
            $duration = microtime(true) - $startTime;
            $this->queryCount++;
            $this->totalQueryTime += $duration;
            
            // Log slow queries
            $slowThreshold = 1.0; // 1 second
            if ($duration > $slowThreshold) {
                $this->logger->warning('Slow query detected', [
                    'sql' => $sql,
                    'params' => $params,
                    'duration_ms' => round($duration * 1000, 2)
                ]);
            }
            
            $this->logger->query($sql, $params, $duration);
            
            return $stmt;
            
        } catch (PDOException $e) {
            $this->logger->error('Database query error', [
                'sql' => $sql,
                'params' => $params,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            throw new Exception('Database query failed: ' . $e->getMessage());
        }
    }
    
    /**
     * Execute query and fetch all results
     */
    public function fetchAll(string $sql, array $params = []): array {
        $stmt = $this->query($sql, $params);
        return $stmt->fetchAll();
    }
    
    /**
     * Execute query and fetch single result
     */
    public function fetchOne(string $sql, array $params = []): ?array {
        $stmt = $this->query($sql, $params);
        $result = $stmt->fetch();
        return $result ?: null;
    }
    
    /**
     * Execute query and return affected rows count
     */
    public function execute(string $sql, array $params = []): int {
        $stmt = $this->query($sql, $params);
        return $stmt->rowCount();
    }
    
    /**
     * Execute query and return single value
     */
    public function fetchValue(string $sql, array $params = []) {
        $stmt = $this->query($sql, $params);
        return $stmt->fetchColumn();
    }
    
    /**
     * Execute multiple queries in a transaction
     */
    public function transaction(callable $callback) {
        $this->beginTransaction();
        
        try {
            $result = $callback($this);
            $this->commit();
            return $result;
        } catch (Exception $e) {
            $this->rollback();
            throw $e;
        }
    }
    
    /**
     * Begin transaction with nesting support
     */
    public function beginTransaction(): bool {
        if ($this->transactionLevel === 0) {
            $result = $this->pdo->beginTransaction();
            $this->logger->debug('Transaction started');
        } else {
            // Use savepoints for nested transactions
            $savepointName = 'sp_' . $this->transactionLevel;
            $this->pdo->exec("SAVEPOINT {$savepointName}");
            $this->logger->debug('Savepoint created', ['savepoint' => $savepointName]);
            $result = true;
        }
        
        $this->transactionLevel++;
        return $result;
    }
    
    /**
     * Commit transaction with nesting support
     */
    public function commit(): bool {
        if ($this->transactionLevel === 0) {
            throw new Exception('No active transaction to commit');
        }
        
        $this->transactionLevel--;
        
        if ($this->transactionLevel === 0) {
            $result = $this->pdo->commit();
            $this->logger->debug('Transaction committed');
        } else {
            // Release savepoint
            $savepointName = 'sp_' . $this->transactionLevel;
            $this->pdo->exec("RELEASE SAVEPOINT {$savepointName}");
            $this->logger->debug('Savepoint released', ['savepoint' => $savepointName]);
            $result = true;
        }
        
        return $result;
    }
    
    /**
     * Rollback transaction with nesting support
     */
    public function rollback(): bool {
        if ($this->transactionLevel === 0) {
            throw new Exception('No active transaction to rollback');
        }
        
        $this->transactionLevel--;
        
        if ($this->transactionLevel === 0) {
            $result = $this->pdo->rollback();
            $this->logger->debug('Transaction rolled back');
        } else {
            // Rollback to savepoint
            $savepointName = 'sp_' . $this->transactionLevel;
            $this->pdo->exec("ROLLBACK TO SAVEPOINT {$savepointName}");
            $this->logger->debug('Rolled back to savepoint', ['savepoint' => $savepointName]);
            $result = true;
        }
        
        return $result;
    }
    
    /**
     * Get last insert ID with sequence support
     */
    public function lastInsertId(string $sequence = null): string {
        return $this->pdo->lastInsertId($sequence);
    }
    
    /**
     * Insert data with automatic ID return
     */
    public function insert(string $table, array $data, string $idColumn = 'id'): int {
        $columns = array_keys($data);
        $placeholders = array_map(function($col) { return ':' . $col; }, $columns);
        
        $sql = sprintf(
            "INSERT INTO %s (%s) VALUES (%s) RETURNING %s",
            $table,
            implode(', ', $columns),
            implode(', ', $placeholders),
            $idColumn
        );
        
        $stmt = $this->query($sql, $data);
        $result = $stmt->fetch();
        
        return $result[$idColumn];
    }
    
    /**
     * Update data with WHERE conditions
     */
    public function update(string $table, array $data, array $where): int {
        $setParts = [];
        $params = [];
        
        foreach ($data as $column => $value) {
            $setParts[] = "{$column} = :{$column}";
            $params[$column] = $value;
        }
        
        $whereParts = [];
        foreach ($where as $column => $value) {
            $whereKey = "where_{$column}";
            $whereParts[] = "{$column} = :{$whereKey}";
            $params[$whereKey] = $value;
        }
        
        $sql = sprintf(
            "UPDATE %s SET %s WHERE %s",
            $table,
            implode(', ', $setParts),
            implode(' AND ', $whereParts)
        );
        
        return $this->execute($sql, $params);
    }
    
    /**
     * Delete data with WHERE conditions
     */
    public function delete(string $table, array $where): int {
        $whereParts = [];
        $params = [];
        
        foreach ($where as $column => $value) {
            $whereParts[] = "{$column} = :{$column}";
            $params[$column] = $value;
        }
        
        $sql = sprintf(
            "DELETE FROM %s WHERE %s",
            $table,
            implode(' AND ', $whereParts)
        );
        
        return $this->execute($sql, $params);
    }
    
    /**
     * Check if table exists
     */
    public function tableExists(string $tableName): bool {
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
    public function getTableCount(string $tableName): int {
        if (!$this->tableExists($tableName)) {
            return 0;
        }
        
        $sql = "SELECT COUNT(*) as count FROM " . $tableName;
        $result = $this->fetchOne($sql);
        return $result['count'] ?? 0;
    }
    
    /**
     * Get table schema information
     */
    public function getTableSchema(string $tableName): array {
        $sql = "
            SELECT 
                column_name,
                data_type,
                is_nullable,
                column_default,
                character_maximum_length
            FROM information_schema.columns 
            WHERE table_schema = 'public' AND table_name = ?
            ORDER BY ordinal_position
        ";
        
        return $this->fetchAll($sql, [$tableName]);
    }
    
    /**
     * Get database performance statistics
     */
    public function getStats(): array {
        return [
            'query_count' => $this->queryCount,
            'total_query_time' => round($this->totalQueryTime, 4),
            'average_query_time' => $this->queryCount > 0 ? round($this->totalQueryTime / $this->queryCount, 4) : 0,
            'transaction_level' => $this->transactionLevel,
            'connection_info' => [
                'host' => $this->config['host'],
                'database' => $this->config['database'],
                'user' => $this->config['username']
            ]
        ];
    }
    
    /**
     * Get database information
     */
    public function getDatabaseInfo(): array {
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
            
            // Get database uptime
            $result = $this->fetchOne("SELECT date_trunc('second', current_timestamp - pg_postmaster_start_time()) as uptime");
            $info['uptime'] = $result['uptime'];
            
        } catch (Exception $e) {
            $info['error'] = $e->getMessage();
        }
        
        return $info;
    }
    
    /**
     * Test database connection
     */
    public function testConnection(): bool {
        try {
            $result = $this->fetchOne('SELECT 1 as test');
            return $result['test'] === 1;
        } catch (Exception $e) {
            $this->logger->error('Database connection test failed', ['error' => $e->getMessage()]);
            return false;
        }
    }
    
    /**
     * Execute EXPLAIN ANALYZE for query optimization
     */
    public function explainQuery(string $sql, array $params = []): array {
        $explainSql = "EXPLAIN (ANALYZE, BUFFERS, FORMAT JSON) " . $sql;
        $result = $this->fetchOne($explainSql, $params);
        
        return json_decode($result['QUERY PLAN'], true);
    }
    
    /**
     * Prevent cloning
     */
    private function __clone() {}
    
    /**
     * Prevent unserialization
     */
    public function __wakeup() {}
}

/**
 * Helper functions for global access
 */
function db(): Database {
    return Database::getInstance();
}

function dbQuery(string $sql, array $params = []): PDOStatement {
    return Database::getInstance()->query($sql, $params);
}

function dbFetchAll(string $sql, array $params = []): array {
    return Database::getInstance()->fetchAll($sql, $params);
}

function dbFetchOne(string $sql, array $params = []): ?array {
    return Database::getInstance()->fetchOne($sql, $params);
}

function dbExecute(string $sql, array $params = []): int {
    return Database::getInstance()->execute($sql, $params);
}

function dbTransaction(callable $callback) {
    return Database::getInstance()->transaction($callback);
}