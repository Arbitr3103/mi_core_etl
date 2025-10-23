<?php
/**
 * Production Database Configuration Script
 * Configures production database for regional analytics system
 * Requirements: 5.3
 */

require_once 'config.php';

class ProductionDatabaseConfigurator {
    private $pdo;
    private $logFile;
    
    public function __construct() {
        $this->logFile = 'logs/production_db_setup_' . date('Y-m-d_H-i-s') . '.log';
        $this->ensureLogDirectory();
        $this->log("Starting production database configuration");
    }
    
    /**
     * Main configuration method
     */
    public function configure() {
        try {
            $this->connectToDatabase();
            $this->runMigrations();
            $this->setupDatabaseOptimizations();
            $this->configureBackups();
            $this->setupMonitoring();
            $this->validateConfiguration();
            
            $this->log("Production database configuration completed successfully");
            echo "✅ Production database configured successfully\n";
            return true;
            
        } catch (Exception $e) {
            $this->log("ERROR: " . $e->getMessage());
            echo "❌ Error configuring production database: " . $e->getMessage() . "\n";
            return false;
        }
    }
    
    /**
     * Connect to production database
     */
    private function connectToDatabase() {
        $this->log("Connecting to production database");
        
        $host = $_ENV['DB_HOST'] ?? 'localhost';
        $dbname = $_ENV['DB_NAME'] ?? 'mi_core_db';
        $username = $_ENV['DB_USER'] ?? 'root';
        $password = $_ENV['DB_PASS'] ?? '';
        
        $dsn = "mysql:host={$host};dbname={$dbname};charset=utf8mb4";
        
        $options = [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::MYSQL_ATTR_INIT_COMMAND => "SET sql_mode='STRICT_TRANS_TABLES,ERROR_FOR_DIVISION_BY_ZERO,NO_AUTO_CREATE_USER,NO_ENGINE_SUBSTITUTION'"
        ];
        
        $this->pdo = new PDO($dsn, $username, $password, $options);
        $this->log("Database connection established");
    }
    
    /**
     * Run database migrations
     */
    private function runMigrations() {
        $this->log("Running database migrations");
        
        $migrationFile = 'migrations/add_regional_analytics_schema.sql';
        
        if (!file_exists($migrationFile)) {
            throw new Exception("Migration file not found: {$migrationFile}");
        }
        
        $sql = file_get_contents($migrationFile);
        
        // Split SQL into individual statements
        $statements = array_filter(
            array_map('trim', explode(';', $sql)),
            function($stmt) { return !empty($stmt) && !preg_match('/^\s*--/', $stmt); }
        );
        
        foreach ($statements as $statement) {
            if (trim($statement)) {
                try {
                    $this->pdo->exec($statement);
                    $this->log("Executed: " . substr($statement, 0, 100) . "...");
                } catch (PDOException $e) {
                    // Log warning but continue for CREATE TABLE IF NOT EXISTS
                    if (strpos($e->getMessage(), 'already exists') !== false) {
                        $this->log("WARNING: " . $e->getMessage());
                    } else {
                        throw $e;
                    }
                }
            }
        }
        
        $this->log("Database migrations completed");
    }
    
    /**
     * Setup database optimizations
     */
    private function setupDatabaseOptimizations() {
        $this->log("Setting up database optimizations");
        
        // Configure MySQL settings for analytics workload
        $optimizations = [
            "SET GLOBAL innodb_buffer_pool_size = 1073741824", // 1GB
            "SET GLOBAL query_cache_size = 268435456", // 256MB
            "SET GLOBAL query_cache_type = 1",
            "SET GLOBAL slow_query_log = 1",
            "SET GLOBAL long_query_time = 2",
            "SET GLOBAL innodb_flush_log_at_trx_commit = 2"
        ];
        
        foreach ($optimizations as $sql) {
            try {
                $this->pdo->exec($sql);
                $this->log("Applied optimization: {$sql}");
            } catch (PDOException $e) {
                $this->log("WARNING: Could not apply optimization '{$sql}': " . $e->getMessage());
            }
        }
        
        // Analyze tables for better query performance
        $tables = ['ozon_regional_sales', 'regions', 'regional_analytics_cache'];
        foreach ($tables as $table) {
            try {
                $this->pdo->exec("ANALYZE TABLE {$table}");
                $this->log("Analyzed table: {$table}");
            } catch (PDOException $e) {
                $this->log("WARNING: Could not analyze table '{$table}': " . $e->getMessage());
            }
        }
    }
    
    /**
     * Configure database backups
     */
    private function configureBackups() {
        $this->log("Configuring database backups");
        
        // Create backup script
        $backupScript = <<<'BASH'
#!/bin/bash
# Regional Analytics Database Backup Script
# Run daily via cron

BACKUP_DIR="/var/backups/mysql/regional_analytics"
DATE=$(date +%Y%m%d_%H%M%S)
DB_NAME="mi_core_db"
DB_USER="${DB_USER:-root}"
DB_PASS="${DB_PASS}"
DB_HOST="${DB_HOST:-localhost}"

# Create backup directory
mkdir -p "$BACKUP_DIR"

# Backup regional analytics tables
mysqldump -h "$DB_HOST" -u "$DB_USER" -p"$DB_PASS" "$DB_NAME" \
    ozon_regional_sales regions regional_analytics_cache \
    --single-transaction --routines --triggers \
    > "$BACKUP_DIR/regional_analytics_backup_$DATE.sql"

# Compress backup
gzip "$BACKUP_DIR/regional_analytics_backup_$DATE.sql"

# Keep only last 30 days of backups
find "$BACKUP_DIR" -name "regional_analytics_backup_*.sql.gz" -mtime +30 -delete

echo "Backup completed: regional_analytics_backup_$DATE.sql.gz"
BASH;

        file_put_contents('scripts/backup_regional_analytics.sh', $backupScript);
        chmod('scripts/backup_regional_analytics.sh', 0755);
        
        // Create cron job entry
        $cronEntry = "0 2 * * * /path/to/scripts/backup_regional_analytics.sh >> /var/log/regional_analytics_backup.log 2>&1";
        file_put_contents('scripts/regional_analytics_backup.cron', $cronEntry);
        
        $this->log("Backup scripts created");
    }
    
    /**
     * Setup database monitoring
     */
    private function setupMonitoring() {
        $this->log("Setting up database monitoring");
        
        // Create monitoring queries
        $monitoringQueries = [
            'table_sizes' => "
                SELECT 
                    table_name,
                    ROUND(((data_length + index_length) / 1024 / 1024), 2) AS 'Size (MB)'
                FROM information_schema.tables 
                WHERE table_schema = DATABASE()
                AND table_name IN ('ozon_regional_sales', 'regions', 'regional_analytics_cache')
                ORDER BY (data_length + index_length) DESC
            ",
            'slow_queries' => "
                SELECT 
                    sql_text,
                    count_star as execution_count,
                    avg_timer_wait/1000000000000 as avg_time_seconds
                FROM performance_schema.events_statements_summary_by_digest 
                WHERE sql_text LIKE '%ozon_regional_sales%' 
                   OR sql_text LIKE '%regions%'
                   OR sql_text LIKE '%regional_analytics_cache%'
                ORDER BY avg_timer_wait DESC 
                LIMIT 10
            ",
            'index_usage' => "
                SELECT 
                    object_schema,
                    object_name,
                    index_name,
                    count_read,
                    count_write,
                    count_fetch,
                    count_insert,
                    count_update,
                    count_delete
                FROM performance_schema.table_io_waits_summary_by_index_usage
                WHERE object_schema = DATABASE()
                AND object_name IN ('ozon_regional_sales', 'regions', 'regional_analytics_cache')
                ORDER BY count_read DESC
            "
        ];
        
        // Create monitoring script
        $monitoringScript = '<?php
/**
 * Regional Analytics Database Monitoring Script
 */

require_once "config.php";

class DatabaseMonitor {
    private $pdo;
    
    public function __construct() {
        $host = $_ENV["DB_HOST"] ?? "localhost";
        $dbname = $_ENV["DB_NAME"] ?? "mi_core_db";
        $username = $_ENV["DB_USER"] ?? "root";
        $password = $_ENV["DB_PASS"] ?? "";
        
        $dsn = "mysql:host={$host};dbname={$dbname};charset=utf8mb4";
        $this->pdo = new PDO($dsn, $username, $password, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
        ]);
    }
    
    public function generateReport() {
        $report = [
            "timestamp" => date("Y-m-d H:i:s"),
            "table_sizes" => $this->getTableSizes(),
            "performance_metrics" => $this->getPerformanceMetrics(),
            "cache_statistics" => $this->getCacheStatistics()
        ];
        
        return $report;
    }
    
    private function getTableSizes() {
        $sql = "' . str_replace("\n", "\\n", $monitoringQueries['table_sizes']) . '";
        return $this->pdo->query($sql)->fetchAll();
    }
    
    private function getPerformanceMetrics() {
        $sql = "' . str_replace("\n", "\\n", $monitoringQueries['slow_queries']) . '";
        return $this->pdo->query($sql)->fetchAll();
    }
    
    private function getCacheStatistics() {
        $sql = "
            SELECT 
                cache_type,
                COUNT(*) as total_entries,
                COUNT(CASE WHEN expires_at > NOW() THEN 1 END) as active_entries,
                COUNT(CASE WHEN expires_at <= NOW() THEN 1 END) as expired_entries
            FROM regional_analytics_cache 
            GROUP BY cache_type
        ";
        return $this->pdo->query($sql)->fetchAll();
    }
}

if (php_sapi_name() === "cli") {
    $monitor = new DatabaseMonitor();
    $report = $monitor->generateReport();
    echo json_encode($report, JSON_PRETTY_PRINT) . "\n";
}
';
        
        file_put_contents('scripts/monitor_regional_analytics_db.php', $monitoringScript);
        
        $this->log("Database monitoring setup completed");
    }
    
    /**
     * Validate configuration
     */
    private function validateConfiguration() {
        $this->log("Validating database configuration");
        
        // Check if tables exist
        $tables = ['ozon_regional_sales', 'regions', 'regional_analytics_cache'];
        foreach ($tables as $table) {
            $stmt = $this->pdo->prepare("SHOW TABLES LIKE ?");
            $stmt->execute([$table]);
            if (!$stmt->fetch()) {
                throw new Exception("Table {$table} was not created");
            }
        }
        
        // Check if views exist
        $views = ['v_regional_sales_summary', 'v_marketplace_comparison'];
        foreach ($views as $view) {
            $stmt = $this->pdo->prepare("SHOW TABLES LIKE ?");
            $stmt->execute([$view]);
            if (!$stmt->fetch()) {
                throw new Exception("View {$view} was not created");
            }
        }
        
        // Test basic functionality
        $this->pdo->query("SELECT COUNT(*) FROM regions")->fetch();
        $this->pdo->query("SELECT COUNT(*) FROM ozon_regional_sales")->fetch();
        
        $this->log("Database configuration validation passed");
    }
    
    /**
     * Ensure log directory exists
     */
    private function ensureLogDirectory() {
        $logDir = dirname($this->logFile);
        if (!is_dir($logDir)) {
            mkdir($logDir, 0755, true);
        }
    }
    
    /**
     * Log message
     */
    private function log($message) {
        $timestamp = date('Y-m-d H:i:s');
        $logMessage = "[{$timestamp}] {$message}\n";
        file_put_contents($this->logFile, $logMessage, FILE_APPEND | LOCK_EX);
    }
}

// Run configuration if called directly
if (php_sapi_name() === 'cli') {
    $configurator = new ProductionDatabaseConfigurator();
    $success = $configurator->configure();
    exit($success ? 0 : 1);
}