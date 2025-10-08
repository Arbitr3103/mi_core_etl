<?php

namespace MDM\Migrations;

use PDO;
use PDOException;
use InvalidArgumentException;
use RuntimeException;

/**
 * Migration Manager
 * 
 * Manages database migrations for the MDM system.
 */
class MigrationManager
{
    private PDO $pdo;
    private string $migrationsTable = 'schema_migrations';
    private array $migrations = [];

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
        $this->ensureMigrationsTable();
    }

    /**
     * Register a migration
     */
    public function addMigration(MigrationInterface $migration): void
    {
        $this->migrations[$migration->getVersion()] = $migration;
    }

    /**
     * Register multiple migrations
     */
    public function addMigrations(array $migrations): void
    {
        foreach ($migrations as $migration) {
            if (!$migration instanceof MigrationInterface) {
                throw new InvalidArgumentException('All migrations must implement MigrationInterface');
            }
            $this->addMigration($migration);
        }
    }

    /**
     * Run all pending migrations
     */
    public function migrate(): array
    {
        $executedMigrations = $this->getExecutedMigrations();
        $pendingMigrations = $this->getPendingMigrations($executedMigrations);
        
        if (empty($pendingMigrations)) {
            return ['message' => 'No pending migrations to execute'];
        }

        // Sort migrations by dependencies
        $sortedMigrations = $this->sortMigrationsByDependencies($pendingMigrations);
        
        $results = [];
        $this->pdo->beginTransaction();

        try {
            foreach ($sortedMigrations as $migration) {
                $this->log("Executing migration: {$migration->getVersion()} - {$migration->getDescription()}");
                
                if (!$migration->canExecute($this->pdo)) {
                    throw new RuntimeException("Migration {$migration->getVersion()} cannot be executed");
                }

                $startTime = microtime(true);
                
                if ($migration->up($this->pdo)) {
                    $executionTime = round((microtime(true) - $startTime) * 1000, 2);
                    $this->recordMigration($migration, $executionTime);
                    
                    $results[] = [
                        'version' => $migration->getVersion(),
                        'description' => $migration->getDescription(),
                        'status' => 'success',
                        'execution_time_ms' => $executionTime
                    ];
                    
                    $this->log("Migration {$migration->getVersion()} completed successfully ({$executionTime}ms)");
                } else {
                    throw new RuntimeException("Migration {$migration->getVersion()} failed to execute");
                }
            }

            $this->pdo->commit();
            $this->log("All migrations completed successfully");
            
        } catch (\Exception $e) {
            $this->pdo->rollback();
            $this->log("Migration failed: " . $e->getMessage());
            throw new RuntimeException("Migration failed: " . $e->getMessage(), 0, $e);
        }

        return $results;
    }

    /**
     * Rollback migrations to a specific version
     */
    public function rollback(string $targetVersion = null): array
    {
        $executedMigrations = $this->getExecutedMigrations();
        
        if (empty($executedMigrations)) {
            return ['message' => 'No migrations to rollback'];
        }

        // If no target version specified, rollback the last migration
        if ($targetVersion === null) {
            $targetVersion = end($executedMigrations)['version'];
            $migrationsToRollback = [$targetVersion];
        } else {
            // Find all migrations after the target version
            $migrationsToRollback = [];
            $found = false;
            
            foreach (array_reverse($executedMigrations) as $migration) {
                if ($migration['version'] === $targetVersion) {
                    $migrationsToRollback[] = $migration['version'];
                    break;
                }
                $migrationsToRollback[] = $migration['version'];
            }
        }

        $results = [];
        $this->pdo->beginTransaction();

        try {
            foreach ($migrationsToRollback as $version) {
                if (!isset($this->migrations[$version])) {
                    throw new RuntimeException("Migration {$version} not found for rollback");
                }

                $migration = $this->migrations[$version];
                $this->log("Rolling back migration: {$migration->getVersion()} - {$migration->getDescription()}");
                
                if (!$migration->canRollback($this->pdo)) {
                    throw new RuntimeException("Migration {$migration->getVersion()} cannot be rolled back");
                }

                $startTime = microtime(true);
                
                if ($migration->down($this->pdo)) {
                    $executionTime = round((microtime(true) - $startTime) * 1000, 2);
                    $this->removeMigrationRecord($migration);
                    
                    $results[] = [
                        'version' => $migration->getVersion(),
                        'description' => $migration->getDescription(),
                        'status' => 'rolled_back',
                        'execution_time_ms' => $executionTime
                    ];
                    
                    $this->log("Migration {$migration->getVersion()} rolled back successfully ({$executionTime}ms)");
                } else {
                    throw new RuntimeException("Migration {$migration->getVersion()} failed to rollback");
                }
            }

            $this->pdo->commit();
            $this->log("Rollback completed successfully");
            
        } catch (\Exception $e) {
            $this->pdo->rollback();
            $this->log("Rollback failed: " . $e->getMessage());
            throw new RuntimeException("Rollback failed: " . $e->getMessage(), 0, $e);
        }

        return $results;
    }

    /**
     * Get migration status
     */
    public function getStatus(): array
    {
        $executedMigrations = $this->getExecutedMigrations();
        $pendingMigrations = $this->getPendingMigrations($executedMigrations);
        
        return [
            'total_migrations' => count($this->migrations),
            'executed_migrations' => count($executedMigrations),
            'pending_migrations' => count($pendingMigrations),
            'executed' => array_map(fn($m) => [
                'version' => $m['version'],
                'description' => $this->migrations[$m['version']]->getDescription() ?? 'Unknown',
                'executed_at' => $m['executed_at'],
                'execution_time_ms' => $m['execution_time_ms']
            ], $executedMigrations),
            'pending' => array_map(fn($m) => [
                'version' => $m->getVersion(),
                'description' => $m->getDescription(),
                'dependencies' => $m->getDependencies()
            ], $pendingMigrations)
        ];
    }

    /**
     * Validate all migrations
     */
    public function validate(): array
    {
        $errors = [];
        
        foreach ($this->migrations as $migration) {
            // Check for circular dependencies
            if ($this->hasCircularDependencies($migration)) {
                $errors[] = "Migration {$migration->getVersion()} has circular dependencies";
            }
            
            // Check if dependencies exist
            foreach ($migration->getDependencies() as $dependency) {
                if (!isset($this->migrations[$dependency])) {
                    $errors[] = "Migration {$migration->getVersion()} depends on non-existent migration: {$dependency}";
                }
            }
        }
        
        return $errors;
    }

    /**
     * Reset all migrations (dangerous - use with caution)
     */
    public function reset(): bool
    {
        $this->log("Resetting all migrations - this will drop all data!");
        
        $executedMigrations = array_reverse($this->getExecutedMigrations());
        
        $this->pdo->beginTransaction();
        
        try {
            foreach ($executedMigrations as $migrationData) {
                $version = $migrationData['version'];
                if (isset($this->migrations[$version])) {
                    $migration = $this->migrations[$version];
                    $this->log("Rolling back migration: {$version}");
                    $migration->down($this->pdo);
                }
            }
            
            // Clear migration records
            $this->pdo->exec("DELETE FROM {$this->migrationsTable}");
            
            $this->pdo->commit();
            $this->log("All migrations reset successfully");
            return true;
            
        } catch (\Exception $e) {
            $this->pdo->rollback();
            $this->log("Reset failed: " . $e->getMessage());
            throw new RuntimeException("Reset failed: " . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Get executed migrations from database
     */
    private function getExecutedMigrations(): array
    {
        $stmt = $this->pdo->query("SELECT * FROM {$this->migrationsTable} ORDER BY executed_at ASC");
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Get pending migrations
     */
    private function getPendingMigrations(array $executedMigrations): array
    {
        $executedVersions = array_column($executedMigrations, 'version');
        
        return array_filter($this->migrations, function($migration) use ($executedVersions) {
            return !in_array($migration->getVersion(), $executedVersions);
        });
    }

    /**
     * Sort migrations by dependencies
     */
    private function sortMigrationsByDependencies(array $migrations): array
    {
        $sorted = [];
        $visited = [];
        $visiting = [];
        
        foreach ($migrations as $migration) {
            $this->visitMigration($migration, $migrations, $sorted, $visited, $visiting);
        }
        
        return $sorted;
    }

    /**
     * Visit migration for dependency sorting (topological sort)
     */
    private function visitMigration(MigrationInterface $migration, array $allMigrations, array &$sorted, array &$visited, array &$visiting): void
    {
        $version = $migration->getVersion();
        
        if (isset($visiting[$version])) {
            throw new RuntimeException("Circular dependency detected involving migration: {$version}");
        }
        
        if (isset($visited[$version])) {
            return;
        }
        
        $visiting[$version] = true;
        
        foreach ($migration->getDependencies() as $dependency) {
            if (isset($allMigrations[$dependency])) {
                $this->visitMigration($allMigrations[$dependency], $allMigrations, $sorted, $visited, $visiting);
            }
        }
        
        unset($visiting[$version]);
        $visited[$version] = true;
        $sorted[] = $migration;
    }

    /**
     * Check for circular dependencies
     */
    private function hasCircularDependencies(MigrationInterface $migration, array $visited = []): bool
    {
        $version = $migration->getVersion();
        
        if (in_array($version, $visited)) {
            return true;
        }
        
        $visited[] = $version;
        
        foreach ($migration->getDependencies() as $dependency) {
            if (isset($this->migrations[$dependency])) {
                if ($this->hasCircularDependencies($this->migrations[$dependency], $visited)) {
                    return true;
                }
            }
        }
        
        return false;
    }

    /**
     * Record migration execution
     */
    private function recordMigration(MigrationInterface $migration, float $executionTime): void
    {
        $sql = "INSERT INTO {$this->migrationsTable} (version, description, executed_at, execution_time_ms) 
                VALUES (?, ?, NOW(), ?)";
        
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            $migration->getVersion(),
            $migration->getDescription(),
            $executionTime
        ]);
    }

    /**
     * Remove migration record
     */
    private function removeMigrationRecord(MigrationInterface $migration): void
    {
        $sql = "DELETE FROM {$this->migrationsTable} WHERE version = ?";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([$migration->getVersion()]);
    }

    /**
     * Ensure migrations table exists
     */
    private function ensureMigrationsTable(): void
    {
        $sql = "CREATE TABLE IF NOT EXISTS {$this->migrationsTable} (
            id INT AUTO_INCREMENT PRIMARY KEY,
            version VARCHAR(255) NOT NULL UNIQUE,
            description TEXT,
            executed_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            execution_time_ms DECIMAL(10,2) DEFAULT 0,
            INDEX idx_version (version),
            INDEX idx_executed_at (executed_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
        
        $this->pdo->exec($sql);
    }

    /**
     * Log message
     */
    private function log(string $message): void
    {
        $timestamp = date('Y-m-d H:i:s');
        echo "[{$timestamp}] MigrationManager: {$message}\n";
    }
}