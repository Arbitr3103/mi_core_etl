<?php
/**
 * Configuration for Performance Tests
 * 
 * Handles database connection and API endpoint configuration
 */

class PerformanceTestConfig
{
    private static $config = null;

    public static function getConfig(): array
    {
        if (self::$config === null) {
            self::$config = [
                'database' => [
                    'host' => $_ENV['DB_HOST'] ?? 'localhost',
                    'dbname' => $_ENV['DB_NAME'] ?? 'test_inventory',
                    'username' => $_ENV['DB_USER'] ?? 'root',
                    'password' => $_ENV['DB_PASS'] ?? '',
                    'charset' => 'utf8mb4'
                ],
                'api' => [
                    'base_url' => $_ENV['API_BASE_URL'] ?? 'http://localhost/api/inventory-analytics.php',
                    'timeout' => 30
                ],
                'test_settings' => [
                    'create_test_data' => true,
                    'cleanup_after_tests' => true,
                    'max_test_data_volume' => 10000,
                    'performance_thresholds' => [
                        'api_response_time_ms' => 1000,
                        'db_query_time_ms' => 500,
                        'memory_limit_mb' => 100
                    ]
                ]
            ];
        }
        
        return self::$config;
    }

    public static function getDatabaseConnection(): ?PDO
    {
        $config = self::getConfig()['database'];
        
        try {
            $dsn = "mysql:host={$config['host']};charset={$config['charset']}";
            
            // Try to connect without database first
            $pdo = new PDO($dsn, $config['username'], $config['password'], [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
            ]);
            
            // Try to create database if it doesn't exist
            $pdo->exec("CREATE DATABASE IF NOT EXISTS `{$config['dbname']}`");
            $pdo->exec("USE `{$config['dbname']}`");
            
            // Create test tables if they don't exist
            self::createTestTables($pdo);
            
            return $pdo;
            
        } catch (PDOException $e) {
            error_log("Database connection failed: " . $e->getMessage());
            return null;
        }
    }

    private static function createTestTables(PDO $pdo): void
    {
        $tables = [
            'products' => "
                CREATE TABLE IF NOT EXISTS products (
                    id VARCHAR(255) PRIMARY KEY,
                    external_sku VARCHAR(255),
                    name VARCHAR(500),
                    is_active BOOLEAN DEFAULT FALSE,
                    activity_checked_at TIMESTAMP NULL,
                    activity_reason VARCHAR(255),
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    INDEX idx_is_active (is_active),
                    INDEX idx_activity_checked (activity_checked_at)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
            ",
            'inventory_data' => "
                CREATE TABLE IF NOT EXISTS inventory_data (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    product_id VARCHAR(255),
                    external_sku VARCHAR(255),
                    stock_quantity INT DEFAULT 0,
                    last_updated TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    INDEX idx_product_id (product_id),
                    INDEX idx_stock_quantity (stock_quantity),
                    FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
            ",
            'product_activity_log' => "
                CREATE TABLE IF NOT EXISTS product_activity_log (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    product_id VARCHAR(255),
                    external_sku VARCHAR(255),
                    previous_status BOOLEAN,
                    new_status BOOLEAN,
                    reason VARCHAR(255),
                    changed_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    INDEX idx_product_id (product_id),
                    INDEX idx_changed_at (changed_at),
                    FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
            "
        ];

        foreach ($tables as $tableName => $sql) {
            try {
                $pdo->exec($sql);
            } catch (PDOException $e) {
                error_log("Failed to create table {$tableName}: " . $e->getMessage());
            }
        }
    }

    public static function createSampleData(PDO $pdo, int $count = 100): void
    {
        // Clean existing test data
        $pdo->exec("DELETE FROM products WHERE id LIKE 'test_%'");
        
        // Create sample products
        $productValues = [];
        $inventoryValues = [];
        
        for ($i = 1; $i <= $count; $i++) {
            $isActive = ($i % 3 !== 0) ? 1 : 0; // ~67% active
            $stock = $isActive ? rand(0, 100) : 0;
            
            $productValues[] = sprintf(
                "('test_%d', 'TEST_SKU_%d', 'Test Product %d', %d, NOW(), '%s')",
                $i, $i, $i, $isActive, $isActive ? 'active' : 'inactive'
            );
            
            $inventoryValues[] = sprintf(
                "('test_%d', 'TEST_SKU_%d', %d, NOW())",
                $i, $i, $stock
            );
        }
        
        // Insert in batches
        $batchSize = 50;
        $productBatches = array_chunk($productValues, $batchSize);
        $inventoryBatches = array_chunk($inventoryValues, $batchSize);
        
        foreach ($productBatches as $batch) {
            $sql = "INSERT INTO products (id, external_sku, name, is_active, activity_checked_at, activity_reason) VALUES " . 
                   implode(',', $batch);
            $pdo->exec($sql);
        }
        
        foreach ($inventoryBatches as $batch) {
            $sql = "INSERT INTO inventory_data (product_id, external_sku, stock_quantity, last_updated) VALUES " . 
                   implode(',', $batch);
            $pdo->exec($sql);
        }
    }

    public static function cleanupTestData(PDO $pdo): void
    {
        $pdo->exec("DELETE FROM products WHERE id LIKE 'test_%' OR id LIKE 'bench_%' OR id LIKE 'perf_%'");
        $pdo->exec("DELETE FROM inventory_data WHERE product_id LIKE 'test_%' OR product_id LIKE 'bench_%' OR product_id LIKE 'perf_%'");
        $pdo->exec("DELETE FROM product_activity_log WHERE product_id LIKE 'test_%' OR product_id LIKE 'bench_%' OR product_id LIKE 'perf_%'");
    }

    public static function isAPIAvailable(): bool
    {
        $config = self::getConfig()['api'];
        
        $context = stream_context_create([
            'http' => [
                'method' => 'GET',
                'timeout' => 5,
                'ignore_errors' => true
            ]
        ]);
        
        $response = @file_get_contents($config['base_url'] . '?action=test', false, $context);
        
        return $response !== false;
    }

    public static function getPerformanceThresholds(): array
    {
        return self::getConfig()['test_settings']['performance_thresholds'];
    }
}