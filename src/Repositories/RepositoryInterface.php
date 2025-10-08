<?php

namespace MDM\Repositories;

/**
 * Base Repository Interface
 * 
 * Defines the common CRUD operations that all repositories should implement.
 */
interface RepositoryInterface
{
    /**
     * Find a record by its primary key
     */
    public function findById($id): ?object;

    /**
     * Find all records
     */
    public function findAll(): array;

    /**
     * Find records by criteria
     */
    public function findBy(array $criteria, ?array $orderBy = null, ?int $limit = null, ?int $offset = null): array;

    /**
     * Find one record by criteria
     */
    public function findOneBy(array $criteria): ?object;

    /**
     * Save a record (insert or update)
     */
    public function save(object $entity): bool;

    /**
     * Delete a record
     */
    public function delete(object $entity): bool;

    /**
     * Delete a record by its primary key
     */
    public function deleteById($id): bool;

    /**
     * Count records by criteria
     */
    public function count(array $criteria = []): int;

    /**
     * Check if a record exists by criteria
     */
    public function exists(array $criteria): bool;
}