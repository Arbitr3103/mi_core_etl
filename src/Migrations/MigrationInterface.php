<?php

namespace MDM\Migrations;

use PDO;

/**
 * Migration Interface
 * 
 * Defines the contract for database migrations in the MDM system.
 */
interface MigrationInterface
{
    /**
     * Execute the migration (upgrade)
     */
    public function up(PDO $pdo): bool;

    /**
     * Rollback the migration (downgrade)
     */
    public function down(PDO $pdo): bool;

    /**
     * Get migration version/identifier
     */
    public function getVersion(): string;

    /**
     * Get migration description
     */
    public function getDescription(): string;

    /**
     * Get migration dependencies (other migrations that must run first)
     */
    public function getDependencies(): array;

    /**
     * Validate migration can be executed
     */
    public function canExecute(PDO $pdo): bool;

    /**
     * Validate migration can be rolled back
     */
    public function canRollback(PDO $pdo): bool;
}