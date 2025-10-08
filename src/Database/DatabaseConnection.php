<?php

namespace MDM\Database;

use PDO;
use PDOException;
use InvalidArgumentException;

/**
 * Database Connection Manager
 * 
 * Manages PDO database connections for the MDM system.
 */
class DatabaseConnection
{
    private static ?PDO $instance = null;
    private static array $config = [];

    /**
     * Set database configuration
     */
    public static function setConfig(array $config): void
    {
        $requiredKeys = ['host', 'database', 'username', 'password'];
        foreach ($requiredKeys as $key) {
            if (!isset($config[$key])) {
                throw new InvalidArgumentException("Missing required database config key: {$key}");
            }
        }

        self::$config = array_merge([
            'port' => 3306,
            'charset' => 'utf8mb4',
            'options' => [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
                PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci"
            ]
        ], $config);
    }

    /**
     * Get PDO instance (singleton)
     */
    public static function getInstance(): PDO
    {
        if (self::$instance === null) {
            self::$instance = self::createConnection();
        }

        return self::$instance;
    }

    /**
     * Create a new PDO connection
     */
    private static function createConnection(): PDO
    {
        if (empty(self::$config)) {
            throw new InvalidArgumentException('Database configuration not set. Call setConfig() first.');
        }

        $dsn = sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=%s',
            self::$config['host'],
            self::$config['port'],
            self::$config['database'],
            self::$config['charset']
        );

        try {
            $pdo = new PDO(
                $dsn,
                self::$config['username'],
                self::$config['password'],
                self::$config['options']
            );

            // Set timezone to UTC
            $pdo->exec("SET time_zone = '+00:00'");

            return $pdo;
        } catch (PDOException $e) {
            throw new PDOException("Database connection failed: " . $e->getMessage());
        }
    }

    /**
     * Create a new connection (not singleton)
     */
    public static function createNewConnection(): PDO
    {
        return self::createConnection();
    }

    /**
     * Close the connection
     */
    public static function closeConnection(): void
    {
        self::$instance = null;
    }

    /**
     * Test database connection
     */
    public static function testConnection(): bool
    {
        try {
            $pdo = self::getInstance();
            $pdo->query('SELECT 1');
            return true;
        } catch (PDOException $e) {
            return false;
        }
    }

    /**
     * Get database configuration (without sensitive data)
     */
    public static function getConfig(): array
    {
        $config = self::$config;
        unset($config['password']);
        return $config;
    }

    /**
     * Execute database schema from file
     */
    public static function executeSchemaFile(string $filePath): bool
    {
        if (!file_exists($filePath)) {
            throw new InvalidArgumentException("Schema file not found: {$filePath}");
        }

        $sql = file_get_contents($filePath);
        if ($sql === false) {
            throw new InvalidArgumentException("Failed to read schema file: {$filePath}");
        }

        try {
            $pdo = self::getInstance();
            
            // Split SQL into individual statements
            $statements = array_filter(
                array_map('trim', explode(';', $sql)),
                fn($stmt) => !empty($stmt) && !preg_match('/^--/', $stmt)
            );

            $pdo->beginTransaction();

            foreach ($statements as $statement) {
                if (!empty($statement)) {
                    $pdo->exec($statement);
                }
            }

            $pdo->commit();
            return true;
        } catch (PDOException $e) {
            $pdo->rollback();
            throw new PDOException("Failed to execute schema: " . $e->getMessage());
        }
    }

    /**
     * Check if database exists
     */
    public static function databaseExists(): bool
    {
        try {
            $config = self::$config;
            $dsn = sprintf(
                'mysql:host=%s;port=%d;charset=%s',
                $config['host'],
                $config['port'],
                $config['charset']
            );

            $pdo = new PDO(
                $dsn,
                $config['username'],
                $config['password'],
                $config['options']
            );

            $stmt = $pdo->prepare('SELECT SCHEMA_NAME FROM INFORMATION_SCHEMA.SCHEMATA WHERE SCHEMA_NAME = ?');
            $stmt->execute([$config['database']]);

            return $stmt->fetchColumn() !== false;
        } catch (PDOException $e) {
            return false;
        }
    }

    /**
     * Create database if it doesn't exist
     */
    public static function createDatabase(): bool
    {
        try {
            $config = self::$config;
            $dsn = sprintf(
                'mysql:host=%s;port=%d;charset=%s',
                $config['host'],
                $config['port'],
                $config['charset']
            );

            $pdo = new PDO(
                $dsn,
                $config['username'],
                $config['password'],
                $config['options']
            );

            $sql = sprintf(
                'CREATE DATABASE IF NOT EXISTS `%s` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci',
                $config['database']
            );

            return $pdo->exec($sql) !== false;
        } catch (PDOException $e) {
            throw new PDOException("Failed to create database: " . $e->getMessage());
        }
    }

    /**
     * Get database version
     */
    public static function getDatabaseVersion(): string
    {
        try {
            $pdo = self::getInstance();
            return $pdo->query('SELECT VERSION()')->fetchColumn();
        } catch (PDOException $e) {
            return 'Unknown';
        }
    }

    /**
     * Get database size
     */
    public static function getDatabaseSize(): array
    {
        try {
            $pdo = self::getInstance();
            $sql = "SELECT 
                        table_name,
                        ROUND(((data_length + index_length) / 1024 / 1024), 2) AS size_mb
                    FROM information_schema.tables 
                    WHERE table_schema = :database
                    ORDER BY (data_length + index_length) DESC";
            
            $stmt = $pdo->prepare($sql);
            $stmt->bindValue(':database', self::$config['database']);
            $stmt->execute();
            
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            return [];
        }
    }

    /**
     * Check table exists
     */
    public static function tableExists(string $tableName): bool
    {
        try {
            $pdo = self::getInstance();
            $sql = "SELECT COUNT(*) FROM information_schema.tables 
                    WHERE table_schema = :database AND table_name = :table_name";
            
            $stmt = $pdo->prepare($sql);
            $stmt->bindValue(':database', self::$config['database']);
            $stmt->bindValue(':table_name', $tableName);
            $stmt->execute();
            
            return $stmt->fetchColumn() > 0;
        } catch (PDOException $e) {
            return false;
        }
    }

    /**
     * Get table list
     */
    public static function getTables(): array
    {
        try {
            $pdo = self::getInstance();
            $sql = "SELECT table_name FROM information_schema.tables 
                    WHERE table_schema = :database 
                    ORDER BY table_name";
            
            $stmt = $pdo->prepare($sql);
            $stmt->bindValue(':database', self::$config['database']);
            $stmt->execute();
            
            return $stmt->fetchAll(PDO::FETCH_COLUMN);
        } catch (PDOException $e) {
            return [];
        }
    }
}