<?php

namespace Repositories;

use PDO;
use PDOException;
use InvalidArgumentException;

/**
 * BaseRepository - Abstract base class for all repositories
 * 
 * Provides common database operations and utilities for all repositories.
 */
abstract class BaseRepository
{
    protected PDO $pdo;
    protected string $tableName;
    protected string $primaryKey;

    public function __construct(PDO $pdo, string $tableName, string $primaryKey = 'id')
    {
        $this->pdo = $pdo;
        $this->tableName = $tableName;
        $this->primaryKey = $primaryKey;
    }

    /**
     * Create entity from database array - must be implemented by child classes
     */
    abstract protected function createEntityFromArray(array $data): object;

    /**
     * Convert entity to database array - must be implemented by child classes
     */
    abstract protected function entityToArray(object $entity): array;

    /**
     * Find entity by primary key
     */
    public function findById($id): ?object
    {
        $sql = "SELECT * FROM {$this->tableName} WHERE {$this->primaryKey} = :id";
        $stmt = $this->pdo->prepare($sql);
        $stmt->bindValue(':id', $id);
        $stmt->execute();

        $data = $stmt->fetch(PDO::FETCH_ASSOC);
        
        return $data ? $this->createEntityFromArray($data) : null;
    }

    /**
     * Find all entities
     */
    public function findAll(?array $orderBy = null, ?int $limit = null, ?int $offset = null): array
    {
        $sql = "SELECT * FROM {$this->tableName}";

        if ($orderBy) {
            $orderClauses = [];
            foreach ($orderBy as $column => $direction) {
                $direction = strtoupper($direction) === 'DESC' ? 'DESC' : 'ASC';
                $orderClauses[] = "{$column} {$direction}";
            }
            $sql .= " ORDER BY " . implode(', ', $orderClauses);
        }

        if ($limit) {
            $sql .= " LIMIT " . (int)$limit;
            if ($offset) {
                $sql .= " OFFSET " . (int)$offset;
            }
        }

        $stmt = $this->pdo->query($sql);
        
        $results = [];
        while ($data = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $results[] = $this->createEntityFromArray($data);
        }

        return $results;
    }

    /**
     * Find entities by criteria
     */
    public function findBy(array $criteria, ?array $orderBy = null, ?int $limit = null, ?int $offset = null): array
    {
        if (empty($criteria)) {
            return $this->findAll($orderBy, $limit, $offset);
        }

        $conditions = [];
        $params = [];

        foreach ($criteria as $column => $value) {
            if ($value === null) {
                $conditions[] = "{$column} IS NULL";
            } else {
                $conditions[] = "{$column} = :{$column}";
                $params[":{$column}"] = $value;
            }
        }

        $sql = "SELECT * FROM {$this->tableName} WHERE " . implode(' AND ', $conditions);

        if ($orderBy) {
            $orderClauses = [];
            foreach ($orderBy as $column => $direction) {
                $direction = strtoupper($direction) === 'DESC' ? 'DESC' : 'ASC';
                $orderClauses[] = "{$column} {$direction}";
            }
            $sql .= " ORDER BY " . implode(', ', $orderClauses);
        }

        if ($limit) {
            $sql .= " LIMIT " . (int)$limit;
            if ($offset) {
                $sql .= " OFFSET " . (int)$offset;
            }
        }

        $stmt = $this->pdo->prepare($sql);
        foreach ($params as $param => $value) {
            $stmt->bindValue($param, $value);
        }
        $stmt->execute();

        $results = [];
        while ($data = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $results[] = $this->createEntityFromArray($data);
        }

        return $results;
    }

    /**
     * Find one entity by criteria
     */
    public function findOneBy(array $criteria): ?object
    {
        $results = $this->findBy($criteria, null, 1);
        return $results[0] ?? null;
    }

    /**
     * Count entities
     */
    public function count(?array $criteria = null): int
    {
        $sql = "SELECT COUNT(*) FROM {$this->tableName}";
        $params = [];

        if ($criteria && !empty($criteria)) {
            $conditions = [];
            foreach ($criteria as $column => $value) {
                if ($value === null) {
                    $conditions[] = "{$column} IS NULL";
                } else {
                    $conditions[] = "{$column} = :{$column}";
                    $params[":{$column}"] = $value;
                }
            }
            $sql .= " WHERE " . implode(' AND ', $conditions);
        }

        $stmt = $this->pdo->prepare($sql);
        foreach ($params as $param => $value) {
            $stmt->bindValue($param, $value);
        }
        $stmt->execute();

        return (int)$stmt->fetchColumn();
    }

    /**
     * Check if entity exists
     */
    public function exists($id): bool
    {
        $sql = "SELECT 1 FROM {$this->tableName} WHERE {$this->primaryKey} = :id";
        $stmt = $this->pdo->prepare($sql);
        $stmt->bindValue(':id', $id);
        $stmt->execute();

        return $stmt->fetchColumn() !== false;
    }

    /**
     * Delete entity by primary key
     */
    public function deleteById($id): bool
    {
        $sql = "DELETE FROM {$this->tableName} WHERE {$this->primaryKey} = :id";
        $stmt = $this->pdo->prepare($sql);
        $stmt->bindValue(':id', $id);
        $stmt->execute();

        return $stmt->rowCount() > 0;
    }

    /**
     * Delete entities by criteria
     */
    public function deleteBy(array $criteria): int
    {
        if (empty($criteria)) {
            throw new InvalidArgumentException('Criteria cannot be empty for delete operation');
        }

        $conditions = [];
        $params = [];

        foreach ($criteria as $column => $value) {
            if ($value === null) {
                $conditions[] = "{$column} IS NULL";
            } else {
                $conditions[] = "{$column} = :{$column}";
                $params[":{$column}"] = $value;
            }
        }

        $sql = "DELETE FROM {$this->tableName} WHERE " . implode(' AND ', $conditions);

        $stmt = $this->pdo->prepare($sql);
        foreach ($params as $param => $value) {
            $stmt->bindValue($param, $value);
        }
        $stmt->execute();

        return $stmt->rowCount();
    }

    /**
     * Execute raw SQL query
     */
    protected function executeQuery(string $sql, array $params = []): \PDOStatement
    {
        $stmt = $this->pdo->prepare($sql);
        foreach ($params as $param => $value) {
            $stmt->bindValue($param, $value);
        }
        $stmt->execute();

        return $stmt;
    }

    /**
     * Get last insert ID
     */
    protected function getLastInsertId(): string
    {
        return $this->pdo->lastInsertId();
    }

    /**
     * Begin transaction
     */
    public function beginTransaction(): bool
    {
        return $this->pdo->beginTransaction();
    }

    /**
     * Commit transaction
     */
    public function commit(): bool
    {
        return $this->pdo->commit();
    }

    /**
     * Rollback transaction
     */
    public function rollback(): bool
    {
        return $this->pdo->rollback();
    }

    /**
     * Check if in transaction
     */
    public function inTransaction(): bool
    {
        return $this->pdo->inTransaction();
    }

    /**
     * Get table name
     */
    public function getTableName(): string
    {
        return $this->tableName;
    }

    /**
     * Get primary key column name
     */
    public function getPrimaryKey(): string
    {
        return $this->primaryKey;
    }

    /**
     * Get PDO instance
     */
    public function getPdo(): PDO
    {
        return $this->pdo;
    }

    /**
     * Check if table exists
     */
    protected function tableExists(string $tableName): bool
    {
        try {
            $this->pdo->query("SELECT 1 FROM {$tableName} LIMIT 1");
            return true;
        } catch (PDOException $e) {
            return false;
        }
    }

    /**
     * Get table columns
     */
    protected function getTableColumns(): array
    {
        try {
            $sql = "SELECT column_name FROM information_schema.columns 
                    WHERE table_schema = DATABASE() AND table_name = :table_name";
            $stmt = $this->pdo->prepare($sql);
            $stmt->bindValue(':table_name', $this->tableName);
            $stmt->execute();

            return $stmt->fetchAll(PDO::FETCH_COLUMN);
        } catch (PDOException $e) {
            // Fallback for databases that don't support information_schema
            return [];
        }
    }

    /**
     * Truncate table
     */
    public function truncate(): bool
    {
        try {
            $this->pdo->exec("TRUNCATE TABLE {$this->tableName}");
            return true;
        } catch (PDOException $e) {
            return false;
        }
    }

    /**
     * Get table statistics
     */
    public function getTableStats(): array
    {
        try {
            $sql = "SELECT 
                        COUNT(*) as row_count,
                        ROUND(
                            (data_length + index_length) / 1024 / 1024, 2
                        ) AS size_mb
                    FROM {$this->tableName}, information_schema.tables 
                    WHERE table_schema = DATABASE() 
                    AND table_name = '{$this->tableName}'";

            $stmt = $this->pdo->query($sql);
            return $stmt->fetch(PDO::FETCH_ASSOC) ?: ['row_count' => 0, 'size_mb' => 0];
        } catch (PDOException $e) {
            // Fallback
            $count = $this->count();
            return ['row_count' => $count, 'size_mb' => 0];
        }
    }
}