<?php

namespace Repositories;

use PDO;
use Models\ActivityChangeLog;
use Repositories\BaseRepository;

/**
 * ActivityChangeLogRepository - Repository for managing activity change logs
 * 
 * Provides database operations for ActivityChangeLog entities with
 * specialized queries for activity monitoring and reporting.
 */
class ActivityChangeLogRepository extends BaseRepository
{
    public function __construct(PDO $pdo)
    {
        parent::__construct($pdo, 'product_activity_log', 'id');
    }
    
    /**
     * Create ActivityChangeLog entity from database array
     */
    protected function createEntityFromArray(array $data): ActivityChangeLog
    {
        return ActivityChangeLog::fromArray($data);
    }
    
    /**
     * Convert ActivityChangeLog entity to database array
     */
    protected function entityToArray(object $entity): array
    {
        if (!$entity instanceof ActivityChangeLog) {
            throw new \InvalidArgumentException('Entity must be an instance of ActivityChangeLog');
        }
        
        return $entity->toArray();
    }
    
    /**
     * Save an activity change log
     */
    public function save(ActivityChangeLog $changeLog): bool
    {
        if ($changeLog->getId()) {
            return $this->update($changeLog);
        } else {
            return $this->insert($changeLog);
        }
    }
    
    /**
     * Insert a new activity change log
     */
    public function insert(ActivityChangeLog $changeLog): bool
    {
        $sql = "INSERT INTO {$this->tableName} (
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
        $data = $this->entityToArray($changeLog);
        
        $stmt->bindValue(':product_id', $data['product_id']);
        $stmt->bindValue(':external_sku', $data['external_sku']);
        $stmt->bindValue(':previous_status', $data['previous_status'], PDO::PARAM_BOOL);
        $stmt->bindValue(':new_status', $data['new_status'], PDO::PARAM_BOOL);
        $stmt->bindValue(':reason', $data['reason']);
        $stmt->bindValue(':changed_at', $data['changed_at']);
        $stmt->bindValue(':changed_by', $data['changed_by']);
        $stmt->bindValue(':metadata', $data['metadata']);
        
        $result = $stmt->execute();
        
        if ($result) {
            $changeLog->setId((int)$this->getLastInsertId());
        }
        
        return $result;
    }
    
    /**
     * Update an existing activity change log
     */
    public function update(ActivityChangeLog $changeLog): bool
    {
        $sql = "UPDATE {$this->tableName} SET
                    product_id = :product_id,
                    external_sku = :external_sku,
                    previous_status = :previous_status,
                    new_status = :new_status,
                    reason = :reason,
                    changed_at = :changed_at,
                    changed_by = :changed_by,
                    metadata = :metadata
                WHERE id = :id";
        
        $stmt = $this->pdo->prepare($sql);
        $data = $this->entityToArray($changeLog);
        
        $stmt->bindValue(':id', $changeLog->getId());
        $stmt->bindValue(':product_id', $data['product_id']);
        $stmt->bindValue(':external_sku', $data['external_sku']);
        $stmt->bindValue(':previous_status', $data['previous_status'], PDO::PARAM_BOOL);
        $stmt->bindValue(':new_status', $data['new_status'], PDO::PARAM_BOOL);
        $stmt->bindValue(':reason', $data['reason']);
        $stmt->bindValue(':changed_at', $data['changed_at']);
        $stmt->bindValue(':changed_by', $data['changed_by']);
        $stmt->bindValue(':metadata', $data['metadata']);
        
        return $stmt->execute();
    }
    
    /**
     * Find activity changes for a specific product
     */
    public function findByProductId(string $productId, ?int $limit = null): array
    {
        $criteria = ['product_id' => $productId];
        $orderBy = ['changed_at' => 'DESC'];
        
        return $this->findBy($criteria, $orderBy, $limit);
    }
    
    /**
     * Find activity changes by external SKU
     */
    public function findByExternalSku(string $externalSku, ?int $limit = null): array
    {
        $criteria = ['external_sku' => $externalSku];
        $orderBy = ['changed_at' => 'DESC'];
        
        return $this->findBy($criteria, $orderBy, $limit);
    }
    
    /**
     * Find activity changes by change type
     */
    public function findByChangeType(string $changeType, ?int $limit = null): array
    {
        $sql = "SELECT * FROM {$this->tableName} WHERE ";
        
        switch ($changeType) {
            case ActivityChangeLog::CHANGE_TYPE_ACTIVATION:
                $sql .= "previous_status = 0 AND new_status = 1";
                break;
            case ActivityChangeLog::CHANGE_TYPE_DEACTIVATION:
                $sql .= "previous_status = 1 AND new_status = 0";
                break;
            case ActivityChangeLog::CHANGE_TYPE_INITIAL:
                $sql .= "previous_status IS NULL";
                break;
            case ActivityChangeLog::CHANGE_TYPE_RECHECK:
                $sql .= "previous_status = new_status AND previous_status IS NOT NULL";
                break;
            default:
                return [];
        }
        
        $sql .= " ORDER BY changed_at DESC";
        
        if ($limit) {
            $sql .= " LIMIT " . (int)$limit;
        }
        
        $stmt = $this->pdo->query($sql);
        
        $results = [];
        while ($data = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $results[] = $this->createEntityFromArray($data);
        }
        
        return $results;
    }
    
    /**
     * Find activity changes within a date range
     */
    public function findByDateRange(\DateTime $startDate, \DateTime $endDate, ?int $limit = null): array
    {
        $sql = "SELECT * FROM {$this->tableName} 
                WHERE changed_at >= :start_date AND changed_at <= :end_date
                ORDER BY changed_at DESC";
        
        if ($limit) {
            $sql .= " LIMIT " . (int)$limit;
        }
        
        $stmt = $this->pdo->prepare($sql);
        $stmt->bindValue(':start_date', $startDate->format('Y-m-d H:i:s'));
        $stmt->bindValue(':end_date', $endDate->format('Y-m-d H:i:s'));
        $stmt->execute();
        
        $results = [];
        while ($data = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $results[] = $this->createEntityFromArray($data);
        }
        
        return $results;
    }
    
    /**
     * Find recent activity changes
     */
    public function findRecent(int $hours = 24, ?int $limit = 50): array
    {
        $sql = "SELECT * FROM {$this->tableName} 
                WHERE changed_at >= DATE_SUB(NOW(), INTERVAL :hours HOUR)
                ORDER BY changed_at DESC";
        
        if ($limit) {
            $sql .= " LIMIT " . (int)$limit;
        }
        
        $stmt = $this->pdo->prepare($sql);
        $stmt->bindValue(':hours', $hours, PDO::PARAM_INT);
        $stmt->execute();
        
        $results = [];
        while ($data = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $results[] = $this->createEntityFromArray($data);
        }
        
        return $results;
    }
    
    /**
     * Get activity statistics
     */
    public function getActivityStatistics(?\DateTime $startDate = null, ?\DateTime $endDate = null): array
    {
        $sql = "SELECT 
                    COUNT(*) as total_changes,
                    SUM(CASE WHEN previous_status IS NULL THEN 1 ELSE 0 END) as initial_checks,
                    SUM(CASE WHEN previous_status = 0 AND new_status = 1 THEN 1 ELSE 0 END) as activations,
                    SUM(CASE WHEN previous_status = 1 AND new_status = 0 THEN 1 ELSE 0 END) as deactivations,
                    SUM(CASE WHEN previous_status = new_status AND previous_status IS NOT NULL THEN 1 ELSE 0 END) as rechecks,
                    COUNT(DISTINCT product_id) as unique_products,
                    COUNT(DISTINCT changed_by) as unique_users,
                    MIN(changed_at) as first_change,
                    MAX(changed_at) as last_change
                FROM {$this->tableName}";
        
        $params = [];
        
        if ($startDate || $endDate) {
            $conditions = [];
            
            if ($startDate) {
                $conditions[] = "changed_at >= :start_date";
                $params[':start_date'] = $startDate->format('Y-m-d H:i:s');
            }
            
            if ($endDate) {
                $conditions[] = "changed_at <= :end_date";
                $params[':end_date'] = $endDate->format('Y-m-d H:i:s');
            }
            
            $sql .= " WHERE " . implode(' AND ', $conditions);
        }
        
        $stmt = $this->pdo->prepare($sql);
        foreach ($params as $param => $value) {
            $stmt->bindValue($param, $value);
        }
        $stmt->execute();
        
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
    }
    
    /**
     * Get daily activity summary
     */
    public function getDailyActivitySummary(int $days = 30): array
    {
        $sql = "SELECT 
                    DATE(changed_at) as date,
                    COUNT(*) as total_changes,
                    SUM(CASE WHEN previous_status IS NULL THEN 1 ELSE 0 END) as initial_checks,
                    SUM(CASE WHEN previous_status = 0 AND new_status = 1 THEN 1 ELSE 0 END) as activations,
                    SUM(CASE WHEN previous_status = 1 AND new_status = 0 THEN 1 ELSE 0 END) as deactivations,
                    SUM(CASE WHEN previous_status = new_status AND previous_status IS NOT NULL THEN 1 ELSE 0 END) as rechecks,
                    COUNT(DISTINCT product_id) as unique_products
                FROM {$this->tableName}
                WHERE changed_at >= DATE_SUB(CURDATE(), INTERVAL :days DAY)
                GROUP BY DATE(changed_at)
                ORDER BY date DESC";
        
        $stmt = $this->pdo->prepare($sql);
        $stmt->bindValue(':days', $days, PDO::PARAM_INT);
        $stmt->execute();
        
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    /**
     * Get most active products (products with most changes)
     */
    public function getMostActiveProducts(int $limit = 10, int $days = 30): array
    {
        $sql = "SELECT 
                    product_id,
                    external_sku,
                    COUNT(*) as change_count,
                    SUM(CASE WHEN previous_status = 0 AND new_status = 1 THEN 1 ELSE 0 END) as activations,
                    SUM(CASE WHEN previous_status = 1 AND new_status = 0 THEN 1 ELSE 0 END) as deactivations,
                    MAX(changed_at) as last_change
                FROM {$this->tableName}
                WHERE changed_at >= DATE_SUB(NOW(), INTERVAL :days DAY)
                GROUP BY product_id, external_sku
                ORDER BY change_count DESC
                LIMIT :limit";
        
        $stmt = $this->pdo->prepare($sql);
        $stmt->bindValue(':days', $days, PDO::PARAM_INT);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();
        
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    /**
     * Get activity changes by user
     */
    public function getChangesByUser(?string $changedBy = null, int $limit = 50): array
    {
        $sql = "SELECT 
                    changed_by,
                    COUNT(*) as change_count,
                    SUM(CASE WHEN previous_status = 0 AND new_status = 1 THEN 1 ELSE 0 END) as activations,
                    SUM(CASE WHEN previous_status = 1 AND new_status = 0 THEN 1 ELSE 0 END) as deactivations,
                    MIN(changed_at) as first_change,
                    MAX(changed_at) as last_change
                FROM {$this->tableName}";
        
        $params = [];
        
        if ($changedBy !== null) {
            $sql .= " WHERE changed_by = :changed_by";
            $params[':changed_by'] = $changedBy;
        }
        
        $sql .= " GROUP BY changed_by ORDER BY change_count DESC LIMIT :limit";
        
        $stmt = $this->pdo->prepare($sql);
        foreach ($params as $param => $value) {
            $stmt->bindValue($param, $value);
        }
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();
        
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    /**
     * Delete old activity logs
     */
    public function deleteOldLogs(int $retentionDays): int
    {
        $sql = "DELETE FROM {$this->tableName} 
                WHERE changed_at < DATE_SUB(NOW(), INTERVAL :retention_days DAY)";
        
        $stmt = $this->pdo->prepare($sql);
        $stmt->bindValue(':retention_days', $retentionDays, PDO::PARAM_INT);
        $stmt->execute();
        
        return $stmt->rowCount();
    }
    
    /**
     * Archive old logs to archive table
     */
    public function archiveOldLogs(int $archiveDays): array
    {
        $results = [
            'archived_count' => 0,
            'deleted_count' => 0,
            'errors' => []
        ];
        
        try {
            $this->pdo->beginTransaction();
            
            // Create archive table if it doesn't exist
            $this->createArchiveTableIfNotExists();
            
            // Insert old records into archive
            $archiveSql = "INSERT INTO product_activity_log_archive 
                          SELECT *, NOW() as archived_at 
                          FROM {$this->tableName} 
                          WHERE changed_at < DATE_SUB(NOW(), INTERVAL :archive_days DAY)";
            
            $stmt = $this->pdo->prepare($archiveSql);
            $stmt->bindValue(':archive_days', $archiveDays, PDO::PARAM_INT);
            $stmt->execute();
            $results['archived_count'] = $stmt->rowCount();
            
            // Delete archived records from main table
            if ($results['archived_count'] > 0) {
                $results['deleted_count'] = $this->deleteOldLogs($archiveDays);
            }
            
            $this->pdo->commit();
            
        } catch (\PDOException $e) {
            $this->pdo->rollback();
            $results['errors'][] = $e->getMessage();
        }
        
        return $results;
    }
    
    /**
     * Create archive table if it doesn't exist
     */
    private function createArchiveTableIfNotExists(): void
    {
        $sql = "CREATE TABLE IF NOT EXISTS product_activity_log_archive (
                    id INT PRIMARY KEY,
                    product_id VARCHAR(255) NOT NULL,
                    external_sku VARCHAR(255) NOT NULL,
                    previous_status BOOLEAN,
                    new_status BOOLEAN,
                    reason VARCHAR(255),
                    changed_at TIMESTAMP,
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
     * Get table size information
     */
    public function getTableInfo(): array
    {
        $sql = "SELECT 
                    COUNT(*) as total_records,
                    MIN(changed_at) as oldest_record,
                    MAX(changed_at) as newest_record,
                    ROUND(
                        (data_length + index_length) / 1024 / 1024, 2
                    ) AS size_mb
                FROM {$this->tableName}, information_schema.tables 
                WHERE table_schema = DATABASE() 
                AND table_name = '{$this->tableName}'";
        
        $stmt = $this->pdo->query($sql);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
    }
}