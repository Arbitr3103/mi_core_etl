<?php

namespace Services;

use PDO;
use PDOException;
use InvalidArgumentException;
use Models\ProductActivity;
use Models\ActivityChangeLog;
use DateTime;

/**
 * ActivityUpdateTransaction - Handles transactional updates for product activity status
 * 
 * This service provides transactional operations for updating product activity status
 * with proper rollback functionality and comprehensive logging.
 */
class ActivityUpdateTransaction
{
    private PDO $pdo;
    private array $config;
    private array $transactionLog = [];
    
    public function __construct(PDO $pdo, array $config = [])
    {
        $this->pdo = $pdo;
        $this->config = array_merge([
            'batch_size' => 100,
            'enable_logging' => true,
            'log_all_changes' => true,
            'log_unchanged_status' => false,
            'timeout_seconds' => 300
        ], $config);
    }
    
    /**
     * Update activity status for a single product with transaction safety
     * 
     * @param ProductActivity $productActivity The product activity data to update
     * @param string|null $updatedBy User who initiated the update
     * @return bool True if update was successful
     * @throws PDOException If database operation fails
     */
    public function updateSingleProductActivity(ProductActivity $productActivity, ?string $updatedBy = null): bool
    {
        $this->pdo->beginTransaction();
        
        try {
            // Get current status from database
            $currentStatus = $this->getCurrentProductStatus($productActivity->getProductId());
            
            // Update product activity status
            $this->updateProductActivityStatus($productActivity);
            
            // Log the change if enabled
            if ($this->config['enable_logging']) {
                $this->logActivityChange($productActivity, $currentStatus, $updatedBy);
            }
            
            $this->pdo->commit();
            
            $this->transactionLog[] = [
                'type' => 'single_update',
                'product_id' => $productActivity->getProductId(),
                'status' => 'success',
                'timestamp' => new DateTime()
            ];
            
            return true;
            
        } catch (PDOException $e) {
            $this->pdo->rollback();
            
            $this->transactionLog[] = [
                'type' => 'single_update',
                'product_id' => $productActivity->getProductId(),
                'status' => 'failed',
                'error' => $e->getMessage(),
                'timestamp' => new DateTime()
            ];
            
            throw new PDOException("Failed to update product activity: " . $e->getMessage(), 0, $e);
        }
    }
    
    /**
     * Update activity status for multiple products in batches with transaction safety
     * 
     * @param array $productActivities Array of ProductActivity objects
     * @param string|null $updatedBy User who initiated the update
     * @return array Results with success/failure counts and details
     * @throws PDOException If critical database operation fails
     */
    public function updateBatchProductActivity(array $productActivities, ?string $updatedBy = null): array
    {
        $results = [
            'total' => count($productActivities),
            'successful' => 0,
            'failed' => 0,
            'errors' => [],
            'processed_batches' => 0,
            'start_time' => new DateTime(),
            'end_time' => null
        ];
        
        if (empty($productActivities)) {
            $results['end_time'] = new DateTime();
            return $results;
        }
        
        // Process in batches to avoid memory issues and long-running transactions
        $batches = array_chunk($productActivities, $this->config['batch_size']);
        
        foreach ($batches as $batchIndex => $batch) {
            $batchResult = $this->processBatch($batch, $updatedBy, $batchIndex);
            
            $results['successful'] += $batchResult['successful'];
            $results['failed'] += $batchResult['failed'];
            $results['errors'] = array_merge($results['errors'], $batchResult['errors']);
            $results['processed_batches']++;
            
            // Check for timeout
            if ($this->isTimeoutReached($results['start_time'])) {
                $results['timeout_reached'] = true;
                break;
            }
        }
        
        $results['end_time'] = new DateTime();
        
        $this->transactionLog[] = [
            'type' => 'batch_update',
            'total_products' => $results['total'],
            'successful' => $results['successful'],
            'failed' => $results['failed'],
            'batches_processed' => $results['processed_batches'],
            'status' => $results['failed'] > 0 ? 'partial_success' : 'success',
            'timestamp' => $results['end_time']
        ];
        
        return $results;
    }
    
    /**
     * Process a single batch of product activities
     */
    private function processBatch(array $batch, ?string $updatedBy, int $batchIndex): array
    {
        $batchResult = [
            'successful' => 0,
            'failed' => 0,
            'errors' => []
        ];
        
        $this->pdo->beginTransaction();
        
        try {
            foreach ($batch as $productActivity) {
                if (!$productActivity instanceof ProductActivity) {
                    $batchResult['failed']++;
                    $batchResult['errors'][] = [
                        'product_id' => 'unknown',
                        'error' => 'Invalid ProductActivity object provided'
                    ];
                    continue;
                }
                
                try {
                    // Get current status
                    $currentStatus = $this->getCurrentProductStatus($productActivity->getProductId());
                    
                    // Update product activity status
                    $this->updateProductActivityStatus($productActivity);
                    
                    // Log the change if enabled
                    if ($this->config['enable_logging']) {
                        $this->logActivityChange($productActivity, $currentStatus, $updatedBy);
                    }
                    
                    $batchResult['successful']++;
                    
                } catch (PDOException $e) {
                    $batchResult['failed']++;
                    $batchResult['errors'][] = [
                        'product_id' => $productActivity->getProductId(),
                        'error' => $e->getMessage()
                    ];
                }
            }
            
            $this->pdo->commit();
            
        } catch (PDOException $e) {
            $this->pdo->rollback();
            
            // Mark all items in this batch as failed
            $batchResult['failed'] = count($batch);
            $batchResult['successful'] = 0;
            $batchResult['errors'] = [[
                'batch_index' => $batchIndex,
                'error' => 'Batch transaction failed: ' . $e->getMessage()
            ]];
        }
        
        return $batchResult;
    }
    
    /**
     * Get current product activity status from database
     */
    private function getCurrentProductStatus(string $productId): ?array
    {
        $sql = "SELECT is_active, activity_checked_at, activity_reason 
                FROM products 
                WHERE id = :product_id OR external_sku = :product_id 
                LIMIT 1";
        
        $stmt = $this->pdo->prepare($sql);
        $stmt->bindValue(':product_id', $productId);
        $stmt->execute();
        
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($result) {
            return [
                'is_active' => (bool)$result['is_active'],
                'activity_checked_at' => $result['activity_checked_at'],
                'activity_reason' => $result['activity_reason']
            ];
        }
        
        return null;
    }
    
    /**
     * Update product activity status in database
     */
    private function updateProductActivityStatus(ProductActivity $productActivity): void
    {
        // First, try to update existing product
        $sql = "UPDATE products 
                SET is_active = :is_active,
                    activity_checked_at = :checked_at,
                    activity_reason = :reason,
                    updated_at = CURRENT_TIMESTAMP
                WHERE id = :product_id OR external_sku = :product_id";
        
        $stmt = $this->pdo->prepare($sql);
        $stmt->bindValue(':is_active', $productActivity->isActive(), PDO::PARAM_BOOL);
        $stmt->bindValue(':checked_at', $productActivity->getCheckedAt()->format('Y-m-d H:i:s'));
        $stmt->bindValue(':reason', $productActivity->getActivityReason());
        $stmt->bindValue(':product_id', $productActivity->getProductId());
        
        $stmt->execute();
        
        // Check if any rows were affected
        if ($stmt->rowCount() === 0) {
            // Product doesn't exist, create a basic record
            $this->createProductRecord($productActivity);
        }
    }
    
    /**
     * Create a basic product record if it doesn't exist
     */
    private function createProductRecord(ProductActivity $productActivity): void
    {
        $sql = "INSERT INTO products (
                    id, 
                    external_sku, 
                    is_active, 
                    activity_checked_at, 
                    activity_reason,
                    created_at,
                    updated_at
                ) VALUES (
                    :id,
                    :external_sku,
                    :is_active,
                    :checked_at,
                    :reason,
                    CURRENT_TIMESTAMP,
                    CURRENT_TIMESTAMP
                )";
        
        $stmt = $this->pdo->prepare($sql);
        $stmt->bindValue(':id', $productActivity->getProductId());
        $stmt->bindValue(':external_sku', $productActivity->getExternalSku());
        $stmt->bindValue(':is_active', $productActivity->isActive(), PDO::PARAM_BOOL);
        $stmt->bindValue(':checked_at', $productActivity->getCheckedAt()->format('Y-m-d H:i:s'));
        $stmt->bindValue(':reason', $productActivity->getActivityReason());
        
        $stmt->execute();
    }
    
    /**
     * Log activity change to the activity log table
     */
    private function logActivityChange(ProductActivity $productActivity, ?array $currentStatus, ?string $updatedBy): void
    {
        $previousStatus = $currentStatus ? $currentStatus['is_active'] : null;
        $newStatus = $productActivity->isActive();
        
        // Skip logging if status hasn't changed and log_unchanged_status is false
        if (!$this->config['log_unchanged_status'] && $previousStatus === $newStatus) {
            return;
        }
        
        $changeLog = new ActivityChangeLog(
            productId: $productActivity->getProductId(),
            externalSku: $productActivity->getExternalSku(),
            previousStatus: $previousStatus,
            newStatus: $newStatus,
            reason: $productActivity->getActivityReason(),
            changedBy: $updatedBy,
            metadata: [
                'criteria' => $productActivity->getCriteria(),
                'checked_by' => $productActivity->getCheckedBy(),
                'transaction_id' => $this->generateTransactionId()
            ]
        );
        
        $this->insertActivityChangeLog($changeLog);
    }
    
    /**
     * Insert activity change log into database
     */
    private function insertActivityChangeLog(ActivityChangeLog $changeLog): void
    {
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
        $stmt->bindValue(':product_id', $changeLog->getProductId());
        $stmt->bindValue(':external_sku', $changeLog->getExternalSku());
        $stmt->bindValue(':previous_status', $changeLog->getPreviousStatus(), PDO::PARAM_BOOL);
        $stmt->bindValue(':new_status', $changeLog->getNewStatus(), PDO::PARAM_BOOL);
        $stmt->bindValue(':reason', $changeLog->getReason());
        $stmt->bindValue(':changed_at', $changeLog->getChangedAt()->format('Y-m-d H:i:s'));
        $stmt->bindValue(':changed_by', $changeLog->getChangedBy());
        $stmt->bindValue(':metadata', $changeLog->getMetadata() ? json_encode($changeLog->getMetadata()) : null);
        
        $stmt->execute();
    }
    
    /**
     * Check if timeout has been reached
     */
    private function isTimeoutReached(DateTime $startTime): bool
    {
        $elapsed = time() - $startTime->getTimestamp();
        return $elapsed >= $this->config['timeout_seconds'];
    }
    
    /**
     * Generate unique transaction ID for tracking
     */
    private function generateTransactionId(): string
    {
        return 'txn_' . date('YmdHis') . '_' . substr(md5(uniqid()), 0, 8);
    }
    
    /**
     * Get transaction log for debugging and monitoring
     */
    public function getTransactionLog(): array
    {
        return $this->transactionLog;
    }
    
    /**
     * Clear transaction log
     */
    public function clearTransactionLog(): void
    {
        $this->transactionLog = [];
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
     * Test database connection and table existence
     */
    public function testDatabaseConnection(): array
    {
        $results = [
            'connection' => false,
            'products_table' => false,
            'activity_log_table' => false,
            'errors' => []
        ];
        
        try {
            // Test connection
            $this->pdo->query('SELECT 1');
            $results['connection'] = true;
            
            // Test products table
            $this->pdo->query('SELECT 1 FROM products LIMIT 1');
            $results['products_table'] = true;
            
            // Test activity log table
            $this->pdo->query('SELECT 1 FROM product_activity_log LIMIT 1');
            $results['activity_log_table'] = true;
            
        } catch (PDOException $e) {
            $results['errors'][] = $e->getMessage();
        }
        
        return $results;
    }
    
    /**
     * Get activity statistics from database
     */
    public function getActivityStatistics(): array
    {
        try {
            $sql = "SELECT 
                        COUNT(*) as total_products,
                        SUM(CASE WHEN is_active = 1 THEN 1 ELSE 0 END) as active_products,
                        SUM(CASE WHEN is_active = 0 THEN 1 ELSE 0 END) as inactive_products,
                        COUNT(CASE WHEN activity_checked_at IS NOT NULL THEN 1 END) as checked_products,
                        MAX(activity_checked_at) as last_check_time
                    FROM products";
            
            $stmt = $this->pdo->query($sql);
            $stats = $stmt->fetch(PDO::FETCH_ASSOC);
            
            // Get recent activity changes
            $sql = "SELECT 
                        COUNT(*) as total_changes,
                        SUM(CASE WHEN new_status = 1 AND previous_status = 0 THEN 1 ELSE 0 END) as activations,
                        SUM(CASE WHEN new_status = 0 AND previous_status = 1 THEN 1 ELSE 0 END) as deactivations,
                        MAX(changed_at) as last_change_time
                    FROM product_activity_log 
                    WHERE changed_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)";
            
            $stmt = $this->pdo->query($sql);
            $recentChanges = $stmt->fetch(PDO::FETCH_ASSOC);
            
            return [
                'products' => $stats,
                'recent_changes' => $recentChanges,
                'generated_at' => new DateTime()
            ];
            
        } catch (PDOException $e) {
            return [
                'error' => 'Failed to get statistics: ' . $e->getMessage(),
                'generated_at' => new DateTime()
            ];
        }
    }
}