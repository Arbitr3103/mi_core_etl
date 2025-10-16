<?php

namespace MDM\Migrations;

use PDO;
use PDOException;

/**
 * BaseMigration - Abstract base class for all database migrations
 * 
 * Provides common functionality for database migrations including
 * logging, error handling, and utility methods.
 */
abstract class BaseMigration
{
    protected string $version;
    protected string $description;
    protected array $logs = [];

    public function __construct(string $version, string $description)
    {
        $this->version = $version;
        $this->description = $description;
    }

    /**
     * Execute the migration (up)
     */
    abstract public function up(PDO $pdo): bool;

    /**
     * Rollback the migration (down)
     */
    abstract public function down(PDO $pdo): bool;

    /**
     * Check if migration can be executed
     */
    public function canExecute(PDO $pdo): bool
    {
        return true;
    }

    /**
     * Check if migration can be rolled back
     */
    public function canRollback(PDO $pdo): bool
    {
        return true;
    }

    /**
     * Get migration version
     */
    public function getVersion(): string
    {
        return $this->version;
    }

    /**
     * Get migration description
     */
    public function getDescription(): string
    {
        return $this->description;
    }

    /**
     * Get migration logs
     */
    public function getLogs(): array
    {
        return $this->logs;
    }

    /**
     * Log a message
     */
    protected function log(string $message, string $level = 'info'): void
    {
        $timestamp = date('Y-m-d H:i:s');
        $logEntry = "[{$timestamp}] [{$level}] {$message}";
        
        $this->logs[] = $logEntry;
        
        // Also output to console if running in CLI
        if (php_sapi_name() === 'cli') {
            echo $logEntry . "\n";
        }
    }

    /**
     * Execute SQL with error handling and logging
     */
    protected function executeSql(PDO $pdo, string $sql, array $params = []): bool
    {
        try {
            if (empty($params)) {
                $result = $pdo->exec($sql);
                $this->log("Executed SQL: " . $this->truncateSql($sql));
                return $result !== false;
            } else {
                $stmt = $pdo->prepare($sql);
                $result = $stmt->execute($params);
                $this->log("Executed prepared SQL: " . $this->truncateSql($sql));
                return $result;
            }
        } catch (PDOException $e) {
            $this->log("SQL Error: " . $e->getMessage(), 'error');
            $this->log("Failed SQL: " . $this->truncateSql($sql), 'error');
            throw $e;
        }
    }

    /**
     * Check if table exists
     */
    protected function tableExists(PDO $pdo, string $tableName): bool
    {
        try {
            $pdo->query("SELECT 1 FROM {$tableName} LIMIT 1");
            return true;
        } catch (PDOException $e) {
            return false;
        }
    }

    /**
     * Check if column exists in table
     */
    protected function columnExists(PDO $pdo, string $tableName, string $columnName): bool
    {
        try {
            $sql = "SELECT COUNT(*) FROM information_schema.columns 
                    WHERE table_schema = DATABASE() 
                    AND table_name = :table_name 
                    AND column_name = :column_name";
            
            $stmt = $pdo->prepare($sql);
            $stmt->bindValue(':table_name', $tableName);
            $stmt->bindValue(':column_name', $columnName);
            $stmt->execute();
            
            return $stmt->fetchColumn() > 0;
        } catch (PDOException $e) {
            // Fallback method for databases that don't support information_schema
            try {
                $pdo->query("SELECT {$columnName} FROM {$tableName} LIMIT 1");
                return true;
            } catch (PDOException $e) {
                return false;
            }
        }
    }

    /**
     * Check if index exists on table
     */
    protected function indexExists(PDO $pdo, string $tableName, string $indexName): bool
    {
        try {
            $sql = "SELECT COUNT(*) FROM information_schema.statistics 
                    WHERE table_schema = DATABASE() 
                    AND table_name = :table_name 
                    AND index_name = :index_name";
            
            $stmt = $pdo->prepare($sql);
            $stmt->bindValue(':table_name', $tableName);
            $stmt->bindValue(':index_name', $indexName);
            $stmt->execute();
            
            return $stmt->fetchColumn() > 0;
        } catch (PDOException $e) {
            // For databases that don't support information_schema, assume false
            return false;
        }
    }

    /**
     * Get table row count
     */
    protected function getTableRowCount(PDO $pdo, string $tableName): int
    {
        try {
            $sql = "SELECT COUNT(*) FROM {$tableName}";
            $stmt = $pdo->query($sql);
            return (int)$stmt->fetchColumn();
        } catch (PDOException $e) {
            $this->log("Could not get row count for table {$tableName}: " . $e->getMessage(), 'warning');
            return 0;
        }
    }

    /**
     * Create table backup
     */
    protected function createTableBackup(PDO $pdo, string $tableName): string
    {
        $backupTableName = $tableName . '_backup_' . date('Ymd_His');
        
        try {
            $sql = "CREATE TABLE {$backupTableName} AS SELECT * FROM {$tableName}";
            $this->executeSql($pdo, $sql);
            $this->log("Created backup table: {$backupTableName}");
            return $backupTableName;
        } catch (PDOException $e) {
            $this->log("Failed to create backup for table {$tableName}: " . $e->getMessage(), 'error');
            throw $e;
        }
    }

    /**
     * Drop table backup
     */
    protected function dropTableBackup(PDO $pdo, string $backupTableName): bool
    {
        try {
            $sql = "DROP TABLE IF EXISTS {$backupTableName}";
            $this->executeSql($pdo, $sql);
            $this->log("Dropped backup table: {$backupTableName}");
            return true;
        } catch (PDOException $e) {
            $this->log("Failed to drop backup table {$backupTableName}: " . $e->getMessage(), 'warning');
            return false;
        }
    }

    /**
     * Restore from table backup
     */
    protected function restoreFromBackup(PDO $pdo, string $tableName, string $backupTableName): bool
    {
        try {
            // Drop current table
            $this->executeSql($pdo, "DROP TABLE IF EXISTS {$tableName}");
            
            // Rename backup to original name
            $this->executeSql($pdo, "RENAME TABLE {$backupTableName} TO {$tableName}");
            
            $this->log("Restored table {$tableName} from backup {$backupTableName}");
            return true;
        } catch (PDOException $e) {
            $this->log("Failed to restore table {$tableName} from backup: " . $e->getMessage(), 'error');
            return false;
        }
    }

    /**
     * Add column to table if it doesn't exist
     */
    protected function addColumnIfNotExists(PDO $pdo, string $tableName, string $columnName, string $columnDefinition): bool
    {
        if (!$this->columnExists($pdo, $tableName, $columnName)) {
            $sql = "ALTER TABLE {$tableName} ADD COLUMN {$columnName} {$columnDefinition}";
            return $this->executeSql($pdo, $sql);
        } else {
            $this->log("Column {$columnName} already exists in table {$tableName}");
            return true;
        }
    }

    /**
     * Drop column from table if it exists
     */
    protected function dropColumnIfExists(PDO $pdo, string $tableName, string $columnName): bool
    {
        if ($this->columnExists($pdo, $tableName, $columnName)) {
            $sql = "ALTER TABLE {$tableName} DROP COLUMN {$columnName}";
            return $this->executeSql($pdo, $sql);
        } else {
            $this->log("Column {$columnName} does not exist in table {$tableName}");
            return true;
        }
    }

    /**
     * Add index to table if it doesn't exist
     */
    protected function addIndexIfNotExists(PDO $pdo, string $tableName, string $indexName, string $columns): bool
    {
        if (!$this->indexExists($pdo, $tableName, $indexName)) {
            $sql = "CREATE INDEX {$indexName} ON {$tableName} ({$columns})";
            return $this->executeSql($pdo, $sql);
        } else {
            $this->log("Index {$indexName} already exists on table {$tableName}");
            return true;
        }
    }

    /**
     * Drop index from table if it exists
     */
    protected function dropIndexIfExists(PDO $pdo, string $tableName, string $indexName): bool
    {
        if ($this->indexExists($pdo, $tableName, $indexName)) {
            $sql = "DROP INDEX {$indexName} ON {$tableName}";
            return $this->executeSql($pdo, $sql);
        } else {
            $this->log("Index {$indexName} does not exist on table {$tableName}");
            return true;
        }
    }

    /**
     * Get database version
     */
    protected function getDatabaseVersion(PDO $pdo): string
    {
        try {
            $stmt = $pdo->query("SELECT VERSION()");
            return $stmt->fetchColumn();
        } catch (PDOException $e) {
            return 'unknown';
        }
    }

    /**
     * Get database name
     */
    protected function getDatabaseName(PDO $pdo): string
    {
        try {
            $stmt = $pdo->query("SELECT DATABASE()");
            return $stmt->fetchColumn();
        } catch (PDOException $e) {
            return 'unknown';
        }
    }

    /**
     * Truncate SQL for logging (to avoid very long log entries)
     */
    private function truncateSql(string $sql, int $maxLength = 200): string
    {
        $sql = preg_replace('/\s+/', ' ', trim($sql));
        
        if (strlen($sql) <= $maxLength) {
            return $sql;
        }
        
        return substr($sql, 0, $maxLength) . '...';
    }

    /**
     * Validate migration prerequisites
     */
    protected function validatePrerequisites(PDO $pdo): array
    {
        $errors = [];
        
        // Check database connection
        try {
            $pdo->query("SELECT 1");
        } catch (PDOException $e) {
            $errors[] = "Database connection failed: " . $e->getMessage();
        }
        
        // Check if we have necessary privileges
        try {
            $pdo->query("SHOW GRANTS");
        } catch (PDOException $e) {
            // This is not critical, just log it
            $this->log("Could not check database privileges: " . $e->getMessage(), 'warning');
        }
        
        return $errors;
    }

    /**
     * Get migration summary
     */
    public function getSummary(): array
    {
        return [
            'version' => $this->version,
            'description' => $this->description,
            'log_count' => count($this->logs),
            'class' => get_class($this)
        ];
    }

    /**
     * Clear logs
     */
    public function clearLogs(): void
    {
        $this->logs = [];
    }

    /**
     * Export logs to file
     */
    public function exportLogs(string $filename): bool
    {
        try {
            $content = implode("\n", $this->logs);
            return file_put_contents($filename, $content) !== false;
        } catch (Exception $e) {
            return false;
        }
    }
}