<?php

namespace MDM\Migrations;

use PDO;
use PDOException;
use InvalidArgumentException;

/**
 * Base Migration Class
 * 
 * Provides common functionality for database migrations.
 */
abstract class BaseMigration implements MigrationInterface
{
    protected string $version;
    protected string $description;
    protected array $dependencies;

    public function __construct(string $version, string $description, array $dependencies = [])
    {
        $this->version = $version;
        $this->description = $description;
        $this->dependencies = $dependencies;
    }

    /**
     * Get migration version/identifier
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
     * Get migration dependencies
     */
    public function getDependencies(): array
    {
        return $this->dependencies;
    }

    /**
     * Execute SQL statements safely
     */
    protected function executeSql(PDO $pdo, string $sql): bool
    {
        try {
            // Split SQL into individual statements
            $statements = $this->splitSqlStatements($sql);
            
            foreach ($statements as $statement) {
                if (!empty(trim($statement))) {
                    $pdo->exec($statement);
                }
            }
            
            return true;
        } catch (PDOException $e) {
            throw new PDOException("Migration SQL execution failed: " . $e->getMessage());
        }
    }

    /**
     * Execute SQL from file
     */
    protected function executeSqlFile(PDO $pdo, string $filePath): bool
    {
        if (!file_exists($filePath)) {
            throw new InvalidArgumentException("SQL file not found: {$filePath}");
        }

        $sql = file_get_contents($filePath);
        if ($sql === false) {
            throw new InvalidArgumentException("Failed to read SQL file: {$filePath}");
        }

        return $this->executeSql($pdo, $sql);
    }

    /**
     * Check if table exists
     */
    protected function tableExists(PDO $pdo, string $tableName): bool
    {
        try {
            $stmt = $pdo->prepare("SHOW TABLES LIKE ?");
            $stmt->execute([$tableName]);
            return $stmt->rowCount() > 0;
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
            $stmt = $pdo->prepare("SHOW COLUMNS FROM `{$tableName}` LIKE ?");
            $stmt->execute([$columnName]);
            return $stmt->rowCount() > 0;
        } catch (PDOException $e) {
            return false;
        }
    }

    /**
     * Check if index exists
     */
    protected function indexExists(PDO $pdo, string $tableName, string $indexName): bool
    {
        try {
            $stmt = $pdo->prepare("SHOW INDEX FROM `{$tableName}` WHERE Key_name = ?");
            $stmt->execute([$indexName]);
            return $stmt->rowCount() > 0;
        } catch (PDOException $e) {
            return false;
        }
    }

    /**
     * Check if foreign key constraint exists
     */
    protected function foreignKeyExists(PDO $pdo, string $tableName, string $constraintName): bool
    {
        try {
            $sql = "SELECT COUNT(*) FROM information_schema.table_constraints 
                    WHERE table_name = ? AND constraint_name = ? AND constraint_type = 'FOREIGN KEY'";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([$tableName, $constraintName]);
            return $stmt->fetchColumn() > 0;
        } catch (PDOException $e) {
            return false;
        }
    }

    /**
     * Get table row count
     */
    protected function getTableRowCount(PDO $pdo, string $tableName): int
    {
        try {
            $stmt = $pdo->query("SELECT COUNT(*) FROM `{$tableName}`");
            return (int) $stmt->fetchColumn();
        } catch (PDOException $e) {
            return 0;
        }
    }

    /**
     * Backup table data
     */
    protected function backupTable(PDO $pdo, string $tableName, string $backupTableName = null): bool
    {
        if ($backupTableName === null) {
            $backupTableName = $tableName . '_backup_' . date('Y_m_d_H_i_s');
        }

        try {
            $sql = "CREATE TABLE `{$backupTableName}` AS SELECT * FROM `{$tableName}`";
            $pdo->exec($sql);
            return true;
        } catch (PDOException $e) {
            throw new PDOException("Failed to backup table {$tableName}: " . $e->getMessage());
        }
    }

    /**
     * Restore table from backup
     */
    protected function restoreTable(PDO $pdo, string $tableName, string $backupTableName): bool
    {
        try {
            // Drop current table
            $pdo->exec("DROP TABLE IF EXISTS `{$tableName}`");
            
            // Rename backup table
            $pdo->exec("RENAME TABLE `{$backupTableName}` TO `{$tableName}`");
            
            return true;
        } catch (PDOException $e) {
            throw new PDOException("Failed to restore table {$tableName}: " . $e->getMessage());
        }
    }

    /**
     * Split SQL into individual statements
     */
    private function splitSqlStatements(string $sql): array
    {
        // Remove comments and empty lines
        $lines = explode("\n", $sql);
        $cleanLines = [];
        
        foreach ($lines as $line) {
            $line = trim($line);
            if (!empty($line) && !preg_match('/^--/', $line) && !preg_match('/^\/\*/', $line)) {
                $cleanLines[] = $line;
            }
        }
        
        $cleanSql = implode("\n", $cleanLines);
        
        // Split by semicolon, but be careful with stored procedures
        $statements = [];
        $currentStatement = '';
        $inDelimiter = false;
        
        $tokens = preg_split('/(\bDELIMITER\b|;)/i', $cleanSql, -1, PREG_SPLIT_DELIM_CAPTURE);
        
        foreach ($tokens as $token) {
            if (preg_match('/^\s*DELIMITER\s*$/i', $token)) {
                $inDelimiter = !$inDelimiter;
                $currentStatement .= $token;
            } elseif ($token === ';' && !$inDelimiter) {
                if (!empty(trim($currentStatement))) {
                    $statements[] = trim($currentStatement);
                }
                $currentStatement = '';
            } else {
                $currentStatement .= $token;
            }
        }
        
        // Add the last statement if it exists
        if (!empty(trim($currentStatement))) {
            $statements[] = trim($currentStatement);
        }
        
        return array_filter($statements, fn($stmt) => !empty(trim($stmt)));
    }

    /**
     * Log migration activity
     */
    protected function log(string $message): void
    {
        $timestamp = date('Y-m-d H:i:s');
        echo "[{$timestamp}] Migration {$this->version}: {$message}\n";
    }

    /**
     * Default validation - can be overridden by specific migrations
     */
    public function canExecute(PDO $pdo): bool
    {
        return true;
    }

    /**
     * Default rollback validation - can be overridden by specific migrations
     */
    public function canRollback(PDO $pdo): bool
    {
        return true;
    }

    /**
     * Abstract methods that must be implemented by concrete migrations
     */
    abstract public function up(PDO $pdo): bool;
    abstract public function down(PDO $pdo): bool;
}