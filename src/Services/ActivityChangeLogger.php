<?php

namespace Services;

use PDO;
use PDOException;
use InvalidArgumentException;
use Models\ActivityChangeLog;
use DateTime;

/**
 * ActivityChangeLogger - Comprehensive logging system for product activity changes
 * 
 * This service handles logging of all product activity status changes with
 * batch processing, log retention, and cleanup capabilities.
 */
class ActivityChangeLogger
{
    private PDO $pdo;
    private array $config;
    private array $logBuffer = [];
    private int $bufferSize = 0;
    
    public function __construct(PDO $pdo, array $config = [])
    {
        $this->pdo = $pdo;
        $this->config = array_merge([
            'batch_size' => 50,
            'auto_flush' => true,
            'buffer_max_size' => 100,
            'retention_days' => 90,
            'enable_cleanup' => true,
            'log_level' => 'info', // debug, info, warning, error
            'include_metadata' => true,
            'compress_old_logs' => false
        ], $config);
    }
    
    /**
     * Log a single activity change
     * 
     * @param ActivityChangeLog $changeLog The change log entry to record
     * @return bool True if logged successfully
     */
    public function logChange(ActivityChangeLog $changeLog): bool
    {
        try {
            if ($this->config['auto_flush'] && $this->bufferSize >= $this->config['buffer_max_size']) {
                $this->flushBuffer();
            }
            
            $this->addToBuffer($changeLog);
            
            if ($this->config['auto_flush']) {
                return $this->flushBuffer();
            }
            
            return true;
            
        } catch (PDOException $e) {
            error_log("ActivityChangeLogger: Failed to log change - " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Log multiple activity changes in batch
     * 
     * @param array $changeLogs Array of ActivityChangeLog objects
     * @return array Results with success/failure counts
     */
    public function logBatchChanges(array $changeLogs): array
    {
        $results = [
            'total' => count($changeLogs),
            'successful' => 0,
            'failed' => 0,
            'errors' => [],
            'start_time' => new DateTime()
        ];
        
        if (empty($changeLogs)) {
            $results['end_time'] = new DateTime();
            return $results;
        }
        
        // Process in batches
        $batches = array_chunk($changeLogs, $this->config['batch_size']);
        
        foreach ($batches as $batch) {
            $batchResult = $this->processBatchLogs($batch);
            $results['successful'] += $batchResult['successful'];
            $results['failed'] += $batchResult['failed'];
            $results['errors'] = array_merge($results['errors'], $batchResult['errors']);
        }
        
        $results['end_time'] = new DateTime();
        return $results;
    }
    
    /**
     * Add change log to buffer
     */
    private function addToBuffer(ActivityChangeLog $changeLog): void
    {
        $this->logBuffer[] = $changeLog;
        $this->bufferSize++;
    }
    
    /**
     * Flush buffer to database
     */
    public function flushBuffer(): bool
    {
        if (empty($this->logBuffer)) {
            return true;
        }
        
        try {
            $result = $this->processBatchLogs($this->logBuffer);
            $this->clearBuffer();
            
            return $result['failed'] === 0;
            
        } catch (PDOException $e) {
            error_log("ActivityChangeLogger: Failed to flush buffer - " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Process a batch of log entries
     */
    private function processBatchLogs(array $changeLogs): array
    {
        $result = [
            'successful' => 0,
            'failed' => 0,
            'errors' => []
        ];
        
        if (empty($changeLogs)) {
            return $result;
        }
        
        $this->pdo->beginTransaction();
        
        try {
            $sql = "INSERT INTO product_activity_log (
                        product_id,
                        external_sku,
                        previous_status,
                        new_status,
                        reason,
                        changed_at,
                        changed_by,
                        metadata
                    ) VALUES (
                        :product_id,
                        :external_sku,
                        :previous_status,
                        :new_status,
                        :reason,
                        :changed_at,
                        :changed_by,
                        :metadata
                    )";
            
            $stmt = $this->pdo->prepare($sql);
            
            foreach ($changeLogs as $changeLog) {
                if (!$changeLog instanceof ActivityChangeLog) {
                    $result['failed']++;
                    $result['errors'][] = 'Invalid ActivityChangeLog object';
                    continue;
                }
                
                try {
                    $stmt->bindValue(':product_id', $changeLog->getProductId());
                    $stmt->bindValue(':external_sku', $changeLog->getExternalSku());
                    $stmt->bindValue(':previous_status', $changeLog->getPreviousStatus(), PDO::PARAM_BOOL);
                    $stmt->bindValue(':new_status', $changeLog->getNewStatus(), PDO::PARAM_BOOL);
                    $stmt->bindValue(':reason', $changeLog->getReason());
                    $stmt->bindValue(':changed_at', $changeLog->getChangedAt()->format('Y-m-d H:i:s'));
                    $stmt->bindValue(':changed_by', $changeLog->getChangedBy());
                    
                    $metadata = $this->config['include_metadata'] ? $changeLog->getMetadata() : null;
                    $stmt->bindValue(':metadata', $metadata ? json_encode($metadata) : null);
                    
                    $stmt->execute();
                    $result['successful']++;
                    
                } catch (PDOException $e) {
                    $result['failed']++;
                    $result['errors'][] = [
                        'product_id' => $changeLog->getProductId(),
                        'error' => $e->getMessage()
                    ];
                }
            }
            
            $this->pdo->commit();
            
        } catch (PDOException $e) {
            $this->pdo->rollback();
            $result['failed'] = count($changeLogs);
            $result['successful'] = 0;
            $result['errors'] = ['Batch transaction failed: ' . $e->getMessage()];
        }
        
        return $result;
    }
    
    /**
     * Clear the buffer
     */
    public function clearBuffer(): void
    {
        $this->logBuffer = [];
        $this->bufferSize = 0;
    }
    
    /**
     * Get activity change logs with filtering options
     * 
     * @param array $filters Filtering criteria
     * @param array $options Query options (limit, offset, order)
     * @return array Array of ActivityChangeLog objects
     */
    public function getChangeLogs(array $filters = [], array $options = []): array
    {
        $sql = "SELECT * FROM product_activity_log";
        $params = [];
        $conditions = [];
        
        // Build WHERE clause
        if (!empty($filters)) {
            if (isset($filters['product_id'])) {
                $conditions[] = "product_id = :product_id";
                $params[':product_id'] = $filters['product_id'];
            }
            
            if (isset($filters['external_sku'])) {
                $conditions[] = "external_sku = :external_sku";
                $params[':external_sku'] = $filters['external_sku'];
            }
            
            if (isset($filters['change_type'])) {
                switch ($filters['change_type']) {
                    case 'activation':
                        $conditions[] = "previous_status = 0 AND new_status = 1";
                        break;
                    case 'deactivation':
                        $conditions[] = "previous_status = 1 AND new_status = 0";
                        break;
                    case 'initial':
                        $conditions[] = "previous_status IS NULL";
                        break;
                    case 'recheck':
                        $conditions[] = "previous_status = new_status";
                        break;
                }
            }
            
            if (isset($filters['date_from'])) {
                $conditions[] = "changed_at >= :date_from";
                $params[':date_from'] = $filters['date_from'];
            }
            
            if (isset($filters['date_to'])) {
                $conditions[] = "changed_at <= :date_to";
                $params[':date_to'] = $filters['date_to'];
            }
            
            if (isset($filters['changed_by'])) {
                $conditions[] = "changed_by = :changed_by";
                $params[':changed_by'] = $filters['changed_by'];
            }
        }
        
        if (!empty($conditions)) {
            $sql .= " WHERE " . implode(' AND ', $conditions);
        }
        
        // Add ordering
        $orderBy = $options['order_by'] ?? 'changed_at';
        $orderDir = strtoupper($options['order_dir'] ?? 'DESC');
        $orderDir = in_array($orderDir, ['ASC', 'DESC']) ? $orderDir : 'DESC';
        $sql .= " ORDER BY {$orderBy} {$orderDir}";
        
        // Add limit and offset
        if (isset($options['limit'])) {
            $sql .= " LIMIT " . (int)$options['limit'];
            if (isset($options['offset'])) {
                $sql .= " OFFSET " . (int)$options['offset'];
            }
        }
        
        try {
            $stmt = $this->pdo->prepare($sql);
            foreach ($params as $param => $value) {
                $stmt->bindValue($param, $value);
            }
            $stmt->execute();
            
            $results = [];
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $results[] = ActivityChangeLog::fromArray($row);
            }
            
            return $results;
            
        } catch (PDOException $e) {
            error_log("ActivityChangeLogger: Failed to get change logs - " . $e->getMessage());
            return [];
        }
    }
    
    /**
     * Get activity change statistics
     * 
     * @param array $filters Optional filters for statistics
     * @return array Statistics data
     */
    public function getChangeStatistics(array $filters = []): array
    {
        try {
            $sql = "SELECT 
                        COUNT(*) as total_changes,
                        SUM(CASE WHEN previous_status IS NULL THEN 1 ELSE 0 END) as initial_checks,
                        SUM(CASE WHEN previous_status = 0 AND new_status = 1 THEN 1 ELSE 0 END) as activations,
                        SUM(CASE WHEN previous_status = 1 AND new_status = 0 THEN 1 ELSE 0 END) as deactivations,
                        SUM(CASE WHEN previous_status = new_status THEN 1 ELSE 0 END) as rechecks,
                        COUNT(DISTINCT product_id) as unique_products,
                        COUNT(DISTINCT changed_by) as unique_users,
                        MIN(changed_at) as first_change,
                        MAX(changed_at) as last_change
                    FROM product_activity_log";
            
            $params = [];
            $conditions = [];
            
            // Apply filters
            if (isset($filters['date_from'])) {
                $conditions[] = "changed_at >= :date_from";
                $params[':date_from'] = $filters['date_from'];
            }
            
            if (isset($filters['date_to'])) {
                $conditions[] = "changed_at <= :date_to";
                $params[':date_to'] = $filters['date_to'];
            }
            
            if (!empty($conditions)) {
                $sql .= " WHERE " . implode(' AND ', $conditions);
            }
            
            $stmt = $this->pdo->prepare($sql);
            foreach ($params as $param => $value) {
                $stmt->bindValue($param, $value);
            }
            $stmt->execute();
            
            $stats = $stmt->fetch(PDO::FETCH_ASSOC);
            
            // Get daily activity for the last 30 days
            $dailyActivitySql = "SELECT 
                                    DATE(changed_at) as date,
                                    COUNT(*) as changes,
                                    SUM(CASE WHEN previous_status = 0 AND new_status = 1 THEN 1 ELSE 0 END) as activations,
                                    SUM(CASE WHEN previous_status = 1 AND new_status = 0 THEN 1 ELSE 0 END) as deactivations
                                FROM product_activity_log 
                                WHERE changed_at >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
                                GROUP BY DATE(changed_at)
                                ORDER BY date DESC";
            
            $stmt = $this->pdo->query($dailyActivitySql);
            $dailyActivity = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            return [
                'overall' => $stats,
                'daily_activity' => $dailyActivity,
                'generated_at' => new DateTime()
            ];
            
        } catch (PDOException $e) {
            return [
                'error' => 'Failed to get statistics: ' . $e->getMessage(),
                'generated_at' => new DateTime()
            ];
        }
    }
    
    /**
     * Clean up old log entries based on retention policy
     * 
     * @return array Cleanup results
     */
    public function cleanupOldLogs(): array
    {
        if (!$this->config['enable_cleanup']) {
            return [
                'status' => 'disabled',
                'message' => 'Log cleanup is disabled in configuration'
            ];
        }
        
        $results = [
            'deleted_count' => 0,
            'retention_days' => $this->config['retention_days'],
            'start_time' => new DateTime(),
            'end_time' => null,
            'errors' => []
        ];
        
        try {
            // Calculate cutoff date
            $cutoffDate = new DateTime();
            $cutoffDate->modify("-{$this->config['retention_days']} days");
            
            // First, get count of records to be deleted
            $countSql = "SELECT COUNT(*) FROM product_activity_log WHERE changed_at < :cutoff_date";
            $stmt = $this->pdo->prepare($countSql);
            $stmt->bindValue(':cutoff_date', $cutoffDate->format('Y-m-d H:i:s'));
            $stmt->execute();
            $toDeleteCount = $stmt->fetchColumn();
            
            if ($toDeleteCount > 0) {
                // Delete old records
                $deleteSql = "DELETE FROM product_activity_log WHERE changed_at < :cutoff_date";
                $stmt = $this->pdo->prepare($deleteSql);
                $stmt->bindValue(':cutoff_date', $cutoffDate->format('Y-m-d H:i:s'));
                $stmt->execute();
                
                $results['deleted_count'] = $stmt->rowCount();
            }
            
            $results['status'] = 'success';
            $results['cutoff_date'] = $cutoffDate->format('Y-m-d H:i:s');
            
        } catch (PDOException $e) {
            $results['status'] = 'error';
            $results['errors'][] = $e->getMessage();
        }
        
        $results['end_time'] = new DateTime();
        return $results;
    }
    
    /**
     * Archive old logs to a separate table or file
     * 
     * @param int $archiveDays Number of days to keep in main table
     * @return array Archive results
     */
    public function archiveOldLogs(?int $archiveDays = null): array
    {
        $archiveDays = $archiveDays ?? $this->config['retention_days'];
        
        $results = [
            'archived_count' => 0,
            'archive_days' => $archiveDays,
            'start_time' => new DateTime(),
            'end_time' => null,
            'errors' => []
        ];
        
        try {
            // Calculate cutoff date
            $cutoffDate = new DateTime();
            $cutoffDate->modify("-{$archiveDays} days");
            
            // Create archive table if it doesn't exist
            $this->createArchiveTable();
            
            // Move old records to archive
            $archiveSql = "INSERT INTO product_activity_log_archive 
                          SELECT * FROM product_activity_log 
                          WHERE changed_at < :cutoff_date";
            
            $stmt = $this->pdo->prepare($archiveSql);
            $stmt->bindValue(':cutoff_date', $cutoffDate->format('Y-m-d H:i:s'));
            $stmt->execute();
            
            $results['archived_count'] = $stmt->rowCount();
            
            // Delete archived records from main table
            if ($results['archived_count'] > 0) {
                $deleteSql = "DELETE FROM product_activity_log WHERE changed_at < :cutoff_date";
                $stmt = $this->pdo->prepare($deleteSql);
                $stmt->bindValue(':cutoff_date', $cutoffDate->format('Y-m-d H:i:s'));
                $stmt->execute();
            }
            
            $results['status'] = 'success';
            $results['cutoff_date'] = $cutoffDate->format('Y-m-d H:i:s');
            
        } catch (PDOException $e) {
            $results['status'] = 'error';
            $results['errors'][] = $e->getMessage();
        }
        
        $results['end_time'] = new DateTime();
        return $results;
    }
    
    /**
     * Create archive table for old logs
     */
    private function createArchiveTable(): void
    {
        $sql = "CREATE TABLE IF NOT EXISTS product_activity_log_archive (
                    id INT PRIMARY KEY AUTO_INCREMENT,
                    product_id VARCHAR(255) NOT NULL,
                    external_sku VARCHAR(255) NOT NULL,
                    previous_status BOOLEAN,
                    new_status BOOLEAN,
                    reason VARCHAR(255),
                    changed_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    changed_by VARCHAR(100),
                    metadata JSON,
                    archived_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    
                    INDEX idx_product_id (product_id),
                    INDEX idx_changed_at (changed_at),
                    INDEX idx_archived_at (archived_at)
                ) ENGINE=InnoDB 
                COMMENT='Archived product activity change logs'";
        
        $this->pdo->exec($sql);
    }
    
    /**
     * Get buffer status
     */
    public function getBufferStatus(): array
    {
        return [
            'buffer_size' => $this->bufferSize,
            'max_buffer_size' => $this->config['buffer_max_size'],
            'auto_flush' => $this->config['auto_flush'],
            'buffer_usage_percent' => round(($this->bufferSize / $this->config['buffer_max_size']) * 100, 2)
        ];
    }
    
    /**
     * Get configuration
     */
    public function getConfig(): array
    {
        return $this->config;
    }
    
    /**
     * Update configuration
     */
    public function updateConfig(array $newConfig): void
    {
        $this->config = array_merge($this->config, $newConfig);
    }
    
    /**
     * Test logging system
     */
    public function testLoggingSystem(): array
    {
        $results = [
            'database_connection' => false,
            'table_exists' => false,
            'can_insert' => false,
            'can_query' => false,
            'errors' => []
        ];
        
        try {
            // Test database connection
            $this->pdo->query('SELECT 1');
            $results['database_connection'] = true;
            
            // Test table existence
            $this->pdo->query('SELECT 1 FROM product_activity_log LIMIT 1');
            $results['table_exists'] = true;
            
            // Test insert capability with a test record
            $testLog = ActivityChangeLog::createInitialCheck(
                'test_product_' . time(),
                'test_sku_' . time(),
                true,
                'System test',
                'system_test'
            );
            
            $this->logChange($testLog);
            $this->flushBuffer();
            $results['can_insert'] = true;
            
            // Test query capability
            $logs = $this->getChangeLogs(['changed_by' => 'system_test'], ['limit' => 1]);
            $results['can_query'] = !empty($logs);
            
            // Clean up test record
            $sql = "DELETE FROM product_activity_log WHERE changed_by = 'system_test'";
            $this->pdo->exec($sql);
            
        } catch (PDOException $e) {
            $results['errors'][] = $e->getMessage();
        }
        
        return $results;
    }
}