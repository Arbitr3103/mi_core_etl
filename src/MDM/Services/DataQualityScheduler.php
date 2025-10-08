<?php

namespace MDM\Services;

/**
 * Data Quality Scheduler for MDM System
 * Schedules and runs periodic data quality checks and alerts
 */
class DataQualityScheduler
{
    private DataQualityAlertService $alertService;
    private DataQualityService $qualityService;
    private \PDO $pdo;
    private array $schedules;

    public function __construct()
    {
        $this->alertService = new DataQualityAlertService();
        $this->qualityService = new DataQualityService();
        $this->pdo = $this->getDatabaseConnection();
        $this->loadSchedules();
    }

    /**
     * Run all scheduled data quality checks
     */
    public function runScheduledChecks(): array
    {
        $results = [];
        $currentTime = time();

        foreach ($this->schedules as $checkName => $schedule) {
            if ($this->shouldRunCheck($checkName, $schedule, $currentTime)) {
                $result = $this->runCheck($checkName, $schedule);
                $results[$checkName] = $result;
                $this->updateLastRun($checkName, $currentTime);
            }
        }

        return $results;
    }

    /**
     * Run a specific check
     */
    private function runCheck(string $checkName, array $schedule): array
    {
        $startTime = microtime(true);
        
        try {
            switch ($checkName) {
                case 'quality_metrics_update':
                    $this->qualityService->updateQualityMetrics();
                    $result = ['status' => 'success', 'message' => 'Quality metrics updated'];
                    break;

                case 'alert_check':
                    $alerts = $this->alertService->checkDataQualityAndAlert();
                    $result = [
                        'status' => 'success', 
                        'message' => 'Alert check completed',
                        'alerts_generated' => count($alerts),
                        'alerts' => $alerts
                    ];
                    break;

                case 'weekly_report':
                    $report = $this->alertService->generateWeeklyReport();
                    $result = [
                        'status' => 'success', 
                        'message' => 'Weekly report generated',
                        'report' => $report
                    ];
                    break;

                case 'performance_cleanup':
                    $performanceService = new PerformanceMonitoringService();
                    $deletedCount = $performanceService->cleanOldMetrics(30);
                    $result = [
                        'status' => 'success', 
                        'message' => 'Performance metrics cleaned',
                        'deleted_records' => $deletedCount
                    ];
                    break;

                default:
                    $result = ['status' => 'error', 'message' => "Unknown check: {$checkName}"];
            }
        } catch (\Exception $e) {
            $result = [
                'status' => 'error', 
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ];
        }

        $executionTime = round((microtime(true) - $startTime) * 1000, 2);
        $result['execution_time_ms'] = $executionTime;
        $result['executed_at'] = date('Y-m-d H:i:s');

        // Log the execution
        $this->logExecution($checkName, $result);

        return $result;
    }

    /**
     * Check if a scheduled check should run
     */
    private function shouldRunCheck(string $checkName, array $schedule, int $currentTime): bool
    {
        $lastRun = $this->getLastRun($checkName);
        $interval = $schedule['interval_seconds'];

        return ($currentTime - $lastRun) >= $interval;
    }

    /**
     * Get last run timestamp for a check
     */
    private function getLastRun(string $checkName): int
    {
        $sql = "
            SELECT UNIX_TIMESTAMP(last_run) as last_run_timestamp
            FROM scheduler_status 
            WHERE check_name = ?
        ";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([$checkName]);
        $result = $stmt->fetch();

        return $result ? (int) $result['last_run_timestamp'] : 0;
    }

    /**
     * Update last run timestamp for a check
     */
    private function updateLastRun(string $checkName, int $timestamp): void
    {
        $sql = "
            INSERT INTO scheduler_status (check_name, last_run, status) 
            VALUES (?, FROM_UNIXTIME(?), 'completed')
            ON DUPLICATE KEY UPDATE 
                last_run = FROM_UNIXTIME(?), 
                status = 'completed',
                updated_at = CURRENT_TIMESTAMP
        ";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([$checkName, $timestamp, $timestamp]);
    }

    /**
     * Log check execution
     */
    private function logExecution(string $checkName, array $result): void
    {
        $sql = "
            INSERT INTO scheduler_execution_log (
                check_name, status, message, execution_time_ms, 
                additional_data, executed_at
            ) VALUES (?, ?, ?, ?, ?, ?)
        ";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            $checkName,
            $result['status'],
            $result['message'],
            $result['execution_time_ms'],
            json_encode($result),
            $result['executed_at']
        ]);
    }

    /**
     * Get execution history for a check
     */
    public function getExecutionHistory(string $checkName = null, int $limit = 50): array
    {
        $whereClause = $checkName ? "WHERE check_name = ?" : "";
        $params = $checkName ? [$checkName, $limit] : [$limit];

        $sql = "
            SELECT * FROM scheduler_execution_log 
            {$whereClause}
            ORDER BY executed_at DESC 
            LIMIT ?
        ";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        
        return $stmt->fetchAll();
    }

    /**
     * Get scheduler status
     */
    public function getSchedulerStatus(): array
    {
        $sql = "
            SELECT 
                ss.*,
                sel.status as last_execution_status,
                sel.message as last_execution_message,
                sel.execution_time_ms as last_execution_time
            FROM scheduler_status ss
            LEFT JOIN scheduler_execution_log sel ON ss.check_name = sel.check_name
            WHERE sel.id = (
                SELECT MAX(id) FROM scheduler_execution_log 
                WHERE check_name = ss.check_name
            ) OR sel.id IS NULL
            ORDER BY ss.check_name
        ";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute();
        
        return $stmt->fetchAll();
    }

    /**
     * Enable or disable a scheduled check
     */
    public function toggleCheck(string $checkName, bool $enabled): bool
    {
        if (!isset($this->schedules[$checkName])) {
            return false;
        }

        $this->schedules[$checkName]['enabled'] = $enabled;
        
        // Update in database
        $sql = "
            UPDATE scheduler_status 
            SET enabled = ?, updated_at = CURRENT_TIMESTAMP 
            WHERE check_name = ?
        ";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([$enabled ? 1 : 0, $checkName]);

        return true;
    }

    /**
     * Update check interval
     */
    public function updateCheckInterval(string $checkName, int $intervalSeconds): bool
    {
        if (!isset($this->schedules[$checkName])) {
            return false;
        }

        $this->schedules[$checkName]['interval_seconds'] = $intervalSeconds;
        
        // Update in database
        $sql = "
            UPDATE scheduler_status 
            SET interval_seconds = ?, updated_at = CURRENT_TIMESTAMP 
            WHERE check_name = ?
        ";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([$intervalSeconds, $checkName]);

        return true;
    }

    /**
     * Force run a specific check
     */
    public function forceRunCheck(string $checkName): array
    {
        if (!isset($this->schedules[$checkName])) {
            return ['status' => 'error', 'message' => "Check '{$checkName}' not found"];
        }

        $result = $this->runCheck($checkName, $this->schedules[$checkName]);
        $this->updateLastRun($checkName, time());

        return $result;
    }

    /**
     * Load schedule configuration
     */
    private function loadSchedules(): void
    {
        // Default schedules
        $this->schedules = [
            'quality_metrics_update' => [
                'enabled' => true,
                'interval_seconds' => 3600, // Every hour
                'description' => 'Update data quality metrics'
            ],
            'alert_check' => [
                'enabled' => true,
                'interval_seconds' => 1800, // Every 30 minutes
                'description' => 'Check for data quality alerts'
            ],
            'weekly_report' => [
                'enabled' => true,
                'interval_seconds' => 604800, // Every week
                'description' => 'Generate weekly data quality report'
            ],
            'performance_cleanup' => [
                'enabled' => true,
                'interval_seconds' => 86400, // Daily
                'description' => 'Clean old performance metrics'
            ]
        ];

        // Load from database if exists
        $this->loadSchedulesFromDatabase();
    }

    /**
     * Load schedules from database
     */
    private function loadSchedulesFromDatabase(): void
    {
        try {
            $sql = "SELECT * FROM scheduler_status";
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute();
            
            while ($row = $stmt->fetch()) {
                if (isset($this->schedules[$row['check_name']])) {
                    $this->schedules[$row['check_name']]['enabled'] = (bool) $row['enabled'];
                    $this->schedules[$row['check_name']]['interval_seconds'] = $row['interval_seconds'];
                }
            }
        } catch (\Exception $e) {
            // Database table might not exist yet, use defaults
        }
    }

    /**
     * Initialize scheduler tables
     */
    public function initializeScheduler(): void
    {
        // Create scheduler tables if they don't exist
        $this->createSchedulerTables();
        
        // Insert default schedules
        foreach ($this->schedules as $checkName => $schedule) {
            $sql = "
                INSERT IGNORE INTO scheduler_status (
                    check_name, enabled, interval_seconds, description
                ) VALUES (?, ?, ?, ?)
            ";

            $stmt = $this->pdo->prepare($sql);
            $stmt->execute([
                $checkName,
                $schedule['enabled'] ? 1 : 0,
                $schedule['interval_seconds'],
                $schedule['description']
            ]);
        }
    }

    /**
     * Create scheduler tables
     */
    private function createSchedulerTables(): void
    {
        $sql = "
            CREATE TABLE IF NOT EXISTS scheduler_status (
                id INT AUTO_INCREMENT PRIMARY KEY,
                check_name VARCHAR(100) UNIQUE NOT NULL,
                enabled BOOLEAN DEFAULT TRUE,
                interval_seconds INT NOT NULL,
                description TEXT,
                last_run TIMESTAMP NULL,
                status ENUM('pending', 'running', 'completed', 'failed') DEFAULT 'pending',
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                
                INDEX idx_check_name (check_name),
                INDEX idx_enabled (enabled),
                INDEX idx_last_run (last_run)
            )
        ";

        $this->pdo->exec($sql);

        $sql = "
            CREATE TABLE IF NOT EXISTS scheduler_execution_log (
                id BIGINT AUTO_INCREMENT PRIMARY KEY,
                check_name VARCHAR(100) NOT NULL,
                status ENUM('success', 'error', 'warning') NOT NULL,
                message TEXT,
                execution_time_ms DECIMAL(10,2),
                additional_data JSON,
                executed_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                
                INDEX idx_check_name_time (check_name, executed_at),
                INDEX idx_status (status),
                INDEX idx_executed_at (executed_at)
            )
        ";

        $this->pdo->exec($sql);
    }

    /**
     * Get database connection
     */
    private function getDatabaseConnection(): \PDO
    {
        $config = require __DIR__ . '/../../config/database.php';
        
        $dsn = "mysql:host={$config['host']};dbname={$config['database']};charset=utf8mb4";
        return new \PDO($dsn, $config['username'], $config['password'], [
            \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
            \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC
        ]);
    }
}