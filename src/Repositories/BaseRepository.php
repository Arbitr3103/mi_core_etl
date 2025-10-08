<?php

namespace MDM\Repositories;

use PDO;
use PDOException;
use InvalidArgumentException;

/**
 * Base Repository Class
 * 
 * Provides common database operations and utilities for all repositories.
 */
abstract class BaseRepository implements RepositoryInterface
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
     * Find a record by its primary key
     */
    public function findById($id): ?object
    {
        $sql = "SELECT * FROM {$this->tableName} WHERE {$this->primaryKey} = :id LIMIT 1";
        $stmt = $this->pdo->prepare($sql);
        $stmt->bindValue(':id', $id);
        $stmt->execute();
        
        $data = $stmt->fetch(PDO::FETCH_ASSOC);
        return $data ? $this->createEntityFromArray($data) : null;
    }

    /**
     * Find all records
     */
    public function findAll(): array
    {
        $sql = "SELECT * FROM {$this->tableName}";
        $stmt = $this->pdo->query($sql);
        
        $results = [];
        while ($data = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $results[] = $this->createEntityFromArray($data);
        }
        
        return $results;
    }

    /**
     * Find records by criteria
     */
    public function findBy(array $criteria, ?array $orderBy = null, ?int $limit = null, ?int $offset = null): array
    {
        $sql = "SELECT * FROM {$this->tableName}";
        $params = [];
        
        if (!empty($criteria)) {
            $conditions = [];
            foreach ($criteria as $field => $value) {
                if ($value === null) {
                    $conditions[] = "{$field} IS NULL";
                } elseif (is_array($value)) {
                    $placeholders = [];
                    foreach ($value as $i => $val) {
                        $placeholder = ":{$field}_{$i}";
                        $placeholders[] = $placeholder;
                        $params[$placeholder] = $val;
                    }
                    $conditions[] = "{$field} IN (" . implode(', ', $placeholders) . ")";
                } else {
                    $placeholder = ":{$field}";
                    $conditions[] = "{$field} = {$placeholder}";
                    $params[$placeholder] = $value;
                }
            }
            $sql .= " WHERE " . implode(' AND ', $conditions);
        }
        
        if ($orderBy) {
            $orderClauses = [];
            foreach ($orderBy as $field => $direction) {
                $direction = strtoupper($direction) === 'DESC' ? 'DESC' : 'ASC';
                $orderClauses[] = "{$field} {$direction}";
            }
            $sql .= " ORDER BY " . implode(', ', $orderClauses);
        }
        
        if ($limit !== null) {
            $sql .= " LIMIT " . (int) $limit;
            if ($offset !== null) {
                $sql .= " OFFSET " . (int) $offset;
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
     * Find one record by criteria
     */
    public function findOneBy(array $criteria): ?object
    {
        $results = $this->findBy($criteria, null, 1);
        return $results[0] ?? null;
    }

    /**
     * Count records by criteria
     */
    public function count(array $criteria = []): int
    {
        $sql = "SELECT COUNT(*) FROM {$this->tableName}";
        $params = [];
        
        if (!empty($criteria)) {
            $conditions = [];
            foreach ($criteria as $field => $value) {
                if ($value === null) {
                    $conditions[] = "{$field} IS NULL";
                } elseif (is_array($value)) {
                    $placeholders = [];
                    foreach ($value as $i => $val) {
                        $placeholder = ":{$field}_{$i}";
                        $placeholders[] = $placeholder;
                        $params[$placeholder] = $val;
                    }
                    $conditions[] = "{$field} IN (" . implode(', ', $placeholders) . ")";
                } else {
                    $placeholder = ":{$field}";
                    $conditions[] = "{$field} = {$placeholder}";
                    $params[$placeholder] = $value;
                }
            }
            $sql .= " WHERE " . implode(' AND ', $conditions);
        }
        
        $stmt = $this->pdo->prepare($sql);
        foreach ($params as $param => $value) {
            $stmt->bindValue($param, $value);
        }
        $stmt->execute();
        
        return (int) $stmt->fetchColumn();
    }

    /**
     * Check if a record exists by criteria
     */
    public function exists(array $criteria): bool
    {
        return $this->count($criteria) > 0;
    }

    /**
     * Delete a record by its primary key
     */
    public function deleteById($id): bool
    {
        $sql = "DELETE FROM {$this->tableName} WHERE {$this->primaryKey} = :id";
        $stmt = $this->pdo->prepare($sql);
        $stmt->bindValue(':id', $id);
        
        try {
            return $stmt->execute();
        } catch (PDOException $e) {
            throw new InvalidArgumentException("Failed to delete record: " . $e->getMessage());
        }
    }

    /**
     * Execute a raw SQL query
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
     * Begin a database transaction
     */
    public function beginTransaction(): bool
    {
        return $this->pdo->beginTransaction();
    }

    /**
     * Commit a database transaction
     */
    public function commit(): bool
    {
        return $this->pdo->commit();
    }

    /**
     * Rollback a database transaction
     */
    public function rollback(): bool
    {
        return $this->pdo->rollback();
    }

    /**
     * Execute a callback within a transaction
     */
    public function transaction(callable $callback)
    {
        $this->beginTransaction();
        
        try {
            $result = $callback($this);
            $this->commit();
            return $result;
        } catch (\Exception $e) {
            $this->rollback();
            throw $e;
        }
    }

    /**
     * Build WHERE clause from criteria
     */
    protected function buildWhereClause(array $criteria): array
    {
        $conditions = [];
        $params = [];
        
        foreach ($criteria as $field => $value) {
            if ($value === null) {
                $conditions[] = "{$field} IS NULL";
            } elseif (is_array($value)) {
                $placeholders = [];
                foreach ($value as $i => $val) {
                    $placeholder = ":{$field}_{$i}";
                    $placeholders[] = $placeholder;
                    $params[$placeholder] = $val;
                }
                $conditions[] = "{$field} IN (" . implode(', ', $placeholders) . ")";
            } else {
                $placeholder = ":{$field}";
                $conditions[] = "{$field} = {$placeholder}";
                $params[$placeholder] = $value;
            }
        }
        
        return [
            'conditions' => $conditions,
            'params' => $params
        ];
    }

    /**
     * Get the last inserted ID
     */
    protected function getLastInsertId(): string
    {
        return $this->pdo->lastInsertId();
    }

    /**
     * Abstract method to create entity from database array
     * Must be implemented by concrete repositories
     */
    abstract protected function createEntityFromArray(array $data): object;

    /**
     * Abstract method to convert entity to database array
     * Must be implemented by concrete repositories
     */
    abstract protected function entityToArray(object $entity): array;
}