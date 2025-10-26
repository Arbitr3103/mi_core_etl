<?php

declare(strict_types=1);

namespace MiCore\ETL\Ozon\Core;

use PDO;
use PDOException;
use Exception;

/**
 * Database Connection Class
 * 
 * Provides PostgreSQL database connectivity with connection pooling,
 * transaction management, and batch operations for ETL processes
 */
class DatabaseConnection
{
    private ?PDO $connection = null;
    private array $config;
    private bool $inTransaction = false;
    private array $connectionPool = [];
    private int $maxConnections;
    private int $activeConnections = 0;

    public function __construct(array $config)
    {
        $this->config = $config;
        $this->maxConnections = $config['max_connections'] ?? 5;
        $this->validateConfig();
    }

    /**
     * Get database connection with lazy initialization
     * 
     * @return PDO Database connection
     * @throws Exception When connection fails
     */
    public function getConnection(): PDO
    {
        if ($this->connection === null || !$this->isConnectionAlive()) {
            $this->connection = $this->createConnection();
        }

        return $this->connection;
    }

    /**
     * Create a new database connection
     * 
     * @return PDO New database connection
     * @throws Exception When connection fails
     */
    private function createConnection(): PDO
    {
        try {
            $dsn = sprintf(
                'pgsql:host=%s;port=%s;dbname=%s;sslmode=%s',
                $this->config['host'],
                $this->config['port'] ?? 5432,
                $this->config['database'],
                $this->config['sslmode'] ?? 'prefer'
            );

            $options = [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
                PDO::ATTR_PERSISTENT => $this->config['persistent'] ?? false,
                PDO::ATTR_TIMEOUT => $this->config['timeout'] ?? 30,
            ];

            $connection = new PDO(
                $dsn,
                $this->config['username'],
                $this->config['password'],
                $options
            );

            // Set PostgreSQL specific settings
            $connection->exec("SET timezone = '" . ($this->config['timezone'] ?? 'UTC') . "'");
            $connection->exec("SET statement_timeout = " . ($this->config['statement_timeout'] ?? 300000));
            $connection->exec("SET lock_timeout = " . ($this->config['lock_timeout'] ?? 30000));

            $this->activeConnections++;

            return $connection;

        } catch (PDOException $e) {
            throw new Exception(
                'Database connection failed: ' . $e->getMessage(),
                (int)$e->getCode(),
                $e
            );
        }
    }

    /**
     * Check if connection is alive
     * 
     * @return bool True if connection is alive
     */
    private function isConnectionAlive(): bool
    {
        if ($this->connection === null) {
            return false;
        }

        try {
            $this->connection->query('SELECT 1');
            return true;
        } catch (PDOException $e) {
            return false;
        }
    }

    /**
     * Execute a query and return results
     * 
     * @param string $sql SQL query
     * @param array $params Query parameters
     * @return array Query results
     * @throws Exception When query fails
     */
    public function query(string $sql, array $params = []): array
    {
        try {
            $connection = $this->getConnection();
            $statement = $connection->prepare($sql);
            $statement->execute($params);

            return $statement->fetchAll();

        } catch (PDOException $e) {
            throw new Exception(
                'Query execution failed: ' . $e->getMessage() . ' SQL: ' . $sql,
                (int)$e->getCode(),
                $e
            );
        }
    }

    /**
     * Execute a statement without returning results
     * 
     * @param string $sql SQL statement
     * @param array $params Statement parameters
     * @return int Number of affected rows
     * @throws Exception When execution fails
     */
    public function execute(string $sql, array $params = []): int
    {
        try {
            $connection = $this->getConnection();
            $statement = $connection->prepare($sql);
            $statement->execute($params);

            return $statement->rowCount();

        } catch (PDOException $e) {
            throw new Exception(
                'Statement execution failed: ' . $e->getMessage() . ' SQL: ' . $sql,
                (int)$e->getCode(),
                $e
            );
        }
    }

    /**
     * Execute batch insert operation
     * 
     * @param string $table Table name
     * @param array $columns Column names
     * @param array $data Array of row data
     * @param string $conflictAction Action on conflict (IGNORE, UPDATE, etc.)
     * @return int Number of affected rows
     * @throws Exception When batch insert fails
     */
    public function batchInsert(
        string $table,
        array $columns,
        array $data,
        string $conflictAction = 'IGNORE'
    ): int {
        if (empty($data)) {
            return 0;
        }

        $placeholders = '(' . str_repeat('?,', count($columns) - 1) . '?)';
        $values = str_repeat($placeholders . ',', count($data) - 1) . $placeholders;
        
        $sql = "INSERT INTO {$table} (" . implode(',', $columns) . ") VALUES {$values}";
        
        // Add conflict resolution
        if ($conflictAction === 'IGNORE') {
            $sql .= " ON CONFLICT DO NOTHING";
        } elseif ($conflictAction === 'UPDATE') {
            $updateClauses = [];
            foreach ($columns as $column) {
                if ($column !== 'id') { // Assuming 'id' is primary key
                    $updateClauses[] = "{$column} = EXCLUDED.{$column}";
                }
            }
            if (!empty($updateClauses)) {
                $sql .= " ON CONFLICT DO UPDATE SET " . implode(', ', $updateClauses);
            }
        }

        // Flatten data array
        $flatData = [];
        foreach ($data as $row) {
            foreach ($columns as $column) {
                $flatData[] = $row[$column] ?? null;
            }
        }

        return $this->execute($sql, $flatData);
    }

    /**
     * Execute batch upsert operation (INSERT ... ON CONFLICT UPDATE)
     * 
     * @param string $table Table name
     * @param array $columns Column names
     * @param array $data Array of row data
     * @param array $conflictColumns Columns that define conflict
     * @param array $updateColumns Columns to update on conflict
     * @return int Number of affected rows
     * @throws Exception When upsert fails
     */
    public function batchUpsert(
        string $table,
        array $columns,
        array $data,
        array $conflictColumns,
        array $updateColumns = []
    ): int {
        if (empty($data)) {
            return 0;
        }

        // Use all columns except conflict columns for update if not specified
        if (empty($updateColumns)) {
            $updateColumns = array_diff($columns, $conflictColumns);
        }

        $placeholders = '(' . str_repeat('?,', count($columns) - 1) . '?)';
        $values = str_repeat($placeholders . ',', count($data) - 1) . $placeholders;
        
        $sql = "INSERT INTO {$table} (" . implode(',', $columns) . ") VALUES {$values}";
        $sql .= " ON CONFLICT (" . implode(',', $conflictColumns) . ") DO UPDATE SET ";
        
        $updateClauses = [];
        foreach ($updateColumns as $column) {
            $updateClauses[] = "{$column} = EXCLUDED.{$column}";
        }
        $sql .= implode(', ', $updateClauses);

        // Flatten data array
        $flatData = [];
        foreach ($data as $row) {
            foreach ($columns as $column) {
                $flatData[] = $row[$column] ?? null;
            }
        }

        return $this->execute($sql, $flatData);
    }

    /**
     * Begin database transaction
     * 
     * @return void
     * @throws Exception When transaction start fails
     */
    public function beginTransaction(): void
    {
        if ($this->inTransaction) {
            throw new Exception('Transaction already in progress');
        }

        try {
            $connection = $this->getConnection();
            $connection->beginTransaction();
            $this->inTransaction = true;

        } catch (PDOException $e) {
            throw new Exception(
                'Failed to begin transaction: ' . $e->getMessage(),
                (int)$e->getCode(),
                $e
            );
        }
    }

    /**
     * Commit database transaction
     * 
     * @return void
     * @throws Exception When commit fails
     */
    public function commit(): void
    {
        if (!$this->inTransaction) {
            throw new Exception('No transaction in progress');
        }

        try {
            $this->connection->commit();
            $this->inTransaction = false;

        } catch (PDOException $e) {
            throw new Exception(
                'Failed to commit transaction: ' . $e->getMessage(),
                (int)$e->getCode(),
                $e
            );
        }
    }

    /**
     * Rollback database transaction
     * 
     * @return void
     * @throws Exception When rollback fails
     */
    public function rollback(): void
    {
        if (!$this->inTransaction) {
            throw new Exception('No transaction in progress');
        }

        try {
            $this->connection->rollBack();
            $this->inTransaction = false;

        } catch (PDOException $e) {
            throw new Exception(
                'Failed to rollback transaction: ' . $e->getMessage(),
                (int)$e->getCode(),
                $e
            );
        }
    }

    /**
     * Check if currently in transaction
     * 
     * @return bool True if in transaction
     */
    public function inTransaction(): bool
    {
        return $this->inTransaction;
    }

    /**
     * Execute multiple statements in a transaction
     * 
     * @param callable $callback Callback function to execute
     * @return mixed Result of callback function
     * @throws Exception When transaction fails
     */
    public function transaction(callable $callback)
    {
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
     * Get database connection statistics
     * 
     * @return array Connection statistics
     */
    public function getStats(): array
    {
        return [
            'active_connections' => $this->activeConnections,
            'max_connections' => $this->maxConnections,
            'in_transaction' => $this->inTransaction,
            'connection_alive' => $this->isConnectionAlive()
        ];
    }

    /**
     * Close database connection
     * 
     * @return void
     */
    public function close(): void
    {
        if ($this->inTransaction) {
            $this->rollback();
        }

        $this->connection = null;
        $this->activeConnections = max(0, $this->activeConnections - 1);
    }

    /**
     * Validate database configuration
     * 
     * @return void
     * @throws Exception When configuration is invalid
     */
    private function validateConfig(): void
    {
        $required = ['host', 'database', 'username', 'password'];
        $missing = [];

        foreach ($required as $key) {
            if (!isset($this->config[$key]) || empty($this->config[$key])) {
                $missing[] = $key;
            }
        }

        if (!empty($missing)) {
            throw new Exception(
                'Missing required database configuration: ' . implode(', ', $missing)
            );
        }
    }

    /**
     * Destructor - ensure connection is closed
     */
    public function __destruct()
    {
        $this->close();
    }
}