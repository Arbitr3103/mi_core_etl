<?php

namespace MDM\Services;

/**
 * Report Update Service
 * Handles automatic report updates and cache invalidation when master data changes
 */
class ReportUpdateService {
    private $pdo;
    private $cacheService;
    private $notificationService;
    
    public function __construct($pdo, $cacheService = null, $notificationService = null) {
        $this->pdo = $pdo;
        $this->cacheService = $cacheService;
        $this->notificationService = $notificationService;
    }
    
    /**
     * Handle master product update
     */
    public function onMasterProductUpdate($masterId, $changes = []) {
        try {
            // Log the update
            $this->logDataChange('master_product_update', $masterId, $changes);
            
            // Invalidate related caches
            $this->invalidateRelatedCaches('master_product', $masterId);
            
            // Update dependent reports
            $this->updateDependentReports('master_product', $masterId);
            
            // Queue async updates
            $this->queueAsyncUpdates('master_product_update', $masterId, $changes);
            
            // Send notifications if significant changes
            if ($this->isSignificantChange($changes)) {
                $this->sendUpdateNotification('master_product', $masterId, $changes);
            }
            
            return true;
            
        } catch (Exception $e) {
            error_log("Error in onMasterProductUpdate: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Handle SKU mapping update
     */
    public function onSkuMappingUpdate($mappingId, $masterId, $externalSku, $changes = []) {
        try {
            // Log the update
            $this->logDataChange('sku_mapping_update', $mappingId, array_merge($changes, [
                'master_id' => $masterId,
                'external_sku' => $externalSku
            ]));
            
            // Invalidate related caches
            $this->invalidateRelatedCaches('sku_mapping', $masterId);
            $this->invalidateRelatedCaches('external_sku', $externalSku);
            
            // Update dependent reports
            $this->updateDependentReports('sku_mapping', $masterId);
            
            // Recalculate data quality metrics
            $this->recalculateDataQualityMetrics();
            
            // Queue async updates
            $this->queueAsyncUpdates('sku_mapping_update', $mappingId, $changes);
            
            return true;
            
        } catch (Exception $e) {
            error_log("Error in onSkuMappingUpdate: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Handle inventory data update
     */
    public function onInventoryUpdate($sku, $changes = []) {
        try {
            // Find related master data
            $masterId = $this->findMasterIdBySku($sku);
            
            // Log the update
            $this->logDataChange('inventory_update', $sku, array_merge($changes, [
                'master_id' => $masterId
            ]));
            
            // Invalidate related caches
            if ($masterId) {
                $this->invalidateRelatedCaches('master_product', $masterId);
            }
            $this->invalidateRelatedCaches('external_sku', $sku);
            
            // Update dependent reports
            $this->updateInventoryReports($sku, $masterId);
            
            // Check for critical stock alerts
            $this->checkCriticalStockAlerts($sku, $changes);
            
            return true;
            
        } catch (Exception $e) {
            error_log("Error in onInventoryUpdate: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Invalidate related caches
     */
    private function invalidateRelatedCaches($type, $identifier) {
        if (!$this->cacheService) {
            return;
        }
        
        $cacheKeys = [];
        
        switch ($type) {
            case 'master_product':
                $cacheKeys = [
                    "master_product_{$identifier}",
                    "product_details_{$identifier}",
                    "brand_analytics",
                    "category_analytics",
                    "dashboard_summary",
                    "data_quality_metrics"
                ];
                
                // Get brand and category to invalidate specific caches
                $product = $this->getMasterProduct($identifier);
                if ($product) {
                    $cacheKeys[] = "brand_analytics_{$product['canonical_brand']}";
                    $cacheKeys[] = "category_analytics_{$product['canonical_category']}";
                }
                break;
                
            case 'sku_mapping':
                $cacheKeys = [
                    "sku_mapping_{$identifier}",
                    "master_product_{$identifier}",
                    "mapping_coverage",
                    "data_quality_metrics"
                ];
                break;
                
            case 'external_sku':
                $cacheKeys = [
                    "inventory_{$identifier}",
                    "product_by_sku_{$identifier}",
                    "critical_stock",
                    "inventory_summary"
                ];
                break;
        }
        
        foreach ($cacheKeys as $key) {
            $this->cacheService->delete($key);
        }
        
        // Also invalidate pattern-based caches
        $this->cacheService->deletePattern("dashboard_*");
        $this->cacheService->deletePattern("analytics_*");
    }
    
    /**
     * Update dependent reports
     */
    private function updateDependentReports($type, $identifier) {
        switch ($type) {
            case 'master_product':
                $this->updateBrandCategoryReports($identifier);
                $this->updateDataQualityReports();
                break;
                
            case 'sku_mapping':
                $this->updateMappingCoverageReports();
                $this->updateDataQualityReports();
                break;
        }
    }
    
    /**
     * Update brand and category reports
     */
    private function updateBrandCategoryReports($masterId) {
        try {
            // Get the master product to determine affected brand/category
            $product = $this->getMasterProduct($masterId);
            if (!$product) {
                return;
            }
            
            // Update brand analytics
            if ($product['canonical_brand']) {
                $this->recalculateBrandAnalytics($product['canonical_brand']);
            }
            
            // Update category analytics
            if ($product['canonical_category']) {
                $this->recalculateCategoryAnalytics($product['canonical_category']);
            }
            
        } catch (Exception $e) {
            error_log("Error updating brand/category reports: " . $e->getMessage());
        }
    }
    
    /**
     * Update inventory reports
     */
    private function updateInventoryReports($sku, $masterId = null) {
        try {
            // Update inventory summary statistics
            $this->recalculateInventorySummary();
            
            // Update critical stock alerts if applicable
            $this->updateCriticalStockAlerts($sku);
            
            // Update performance metrics if master data exists
            if ($masterId) {
                $this->updatePerformanceMetrics($masterId);
            }
            
        } catch (Exception $e) {
            error_log("Error updating inventory reports: " . $e->getMessage());
        }
    }
    
    /**
     * Recalculate data quality metrics
     */
    public function recalculateDataQualityMetrics() {
        try {
            $metrics = [
                'master_data_coverage' => $this->calculateMasterDataCoverage(),
                'brand_completeness' => $this->calculateBrandCompleteness(),
                'category_completeness' => $this->calculateCategoryCompleteness(),
                'description_completeness' => $this->calculateDescriptionCompleteness(),
                'verification_completeness' => $this->calculateVerificationCompleteness()
            ];
            
            foreach ($metrics as $metricName => $metricData) {
                $this->saveDataQualityMetric($metricName, $metricData);
            }
            
            return $metrics;
            
        } catch (Exception $e) {
            error_log("Error recalculating data quality metrics: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Queue async updates for heavy operations
     */
    private function queueAsyncUpdates($updateType, $identifier, $changes) {
        try {
            $task = [
                'type' => 'report_update',
                'update_type' => $updateType,
                'identifier' => $identifier,
                'changes' => $changes,
                'created_at' => date('Y-m-d H:i:s'),
                'priority' => $this->getUpdatePriority($updateType, $changes)
            ];
            
            // Insert into async task queue
            $stmt = $this->pdo->prepare("
                INSERT INTO async_update_queue 
                (task_type, task_data, priority, status, created_at)
                VALUES (?, ?, ?, 'pending', NOW())
            ");
            
            $stmt->execute([
                'report_update',
                json_encode($task),
                $task['priority']
            ]);
            
        } catch (Exception $e) {
            error_log("Error queuing async update: " . $e->getMessage());
        }
    }
    
    /**
     * Process async update queue
     */
    public function processAsyncUpdateQueue($limit = 10) {
        try {
            $stmt = $this->pdo->prepare("
                SELECT id, task_data, created_at
                FROM async_update_queue
                WHERE status = 'pending'
                ORDER BY priority ASC, created_at ASC
                LIMIT ?
            ");
            
            $stmt->execute([$limit]);
            $tasks = $stmt->fetchAll(\PDO::FETCH_ASSOC);
            
            foreach ($tasks as $task) {
                $this->processAsyncTask($task);
            }
            
            return count($tasks);
            
        } catch (Exception $e) {
            error_log("Error processing async update queue: " . $e->getMessage());
            return 0;
        }
    }
    
    /**
     * Process individual async task
     */
    private function processAsyncTask($task) {
        try {
            // Mark task as processing
            $this->updateTaskStatus($task['id'], 'processing');
            
            $taskData = json_decode($task['task_data'], true);
            
            switch ($taskData['update_type']) {
                case 'master_product_update':
                    $this->processAsyncMasterProductUpdate($taskData);
                    break;
                    
                case 'sku_mapping_update':
                    $this->processAsyncSkuMappingUpdate($taskData);
                    break;
                    
                case 'inventory_update':
                    $this->processAsyncInventoryUpdate($taskData);
                    break;
            }
            
            // Mark task as completed
            $this->updateTaskStatus($task['id'], 'completed');
            
        } catch (Exception $e) {
            error_log("Error processing async task {$task['id']}: " . $e->getMessage());
            $this->updateTaskStatus($task['id'], 'failed', $e->getMessage());
        }
    }
    
    /**
     * Check for critical stock alerts
     */
    private function checkCriticalStockAlerts($sku, $changes) {
        try {
            if (!isset($changes['current_stock'])) {
                return;
            }
            
            $currentStock = (int)$changes['current_stock'];
            $reservedStock = (int)($changes['reserved_stock'] ?? 0);
            
            // Check if this is a critical stock situation
            if ($currentStock <= 5 || ($currentStock <= 10 && $reservedStock > 0)) {
                $this->createCriticalStockAlert($sku, $currentStock, $reservedStock);
            }
            
        } catch (Exception $e) {
            error_log("Error checking critical stock alerts: " . $e->getMessage());
        }
    }
    
    /**
     * Send update notifications
     */
    private function sendUpdateNotification($type, $identifier, $changes) {
        if (!$this->notificationService) {
            return;
        }
        
        try {
            $message = $this->formatUpdateNotification($type, $identifier, $changes);
            $this->notificationService->send($message);
            
        } catch (Exception $e) {
            error_log("Error sending update notification: " . $e->getMessage());
        }
    }
    
    /**
     * Log data changes for audit trail
     */
    private function logDataChange($changeType, $identifier, $changes) {
        try {
            $stmt = $this->pdo->prepare("
                INSERT INTO data_change_log 
                (change_type, entity_id, changes, created_at)
                VALUES (?, ?, ?, NOW())
            ");
            
            $stmt->execute([
                $changeType,
                $identifier,
                json_encode($changes)
            ]);
            
        } catch (Exception $e) {
            error_log("Error logging data change: " . $e->getMessage());
        }
    }
    
    /**
     * Helper methods
     */
    private function getMasterProduct($masterId) {
        $stmt = $this->pdo->prepare("SELECT * FROM master_products WHERE master_id = ?");
        $stmt->execute([$masterId]);
        return $stmt->fetch(\PDO::FETCH_ASSOC);
    }
    
    private function findMasterIdBySku($sku) {
        $stmt = $this->pdo->prepare("
            SELECT mp.master_id 
            FROM master_products mp
            INNER JOIN sku_mapping sm ON mp.master_id = sm.master_id
            WHERE sm.external_sku = ?
        ");
        $stmt->execute([$sku]);
        $result = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $result ? $result['master_id'] : null;
    }
    
    private function isSignificantChange($changes) {
        $significantFields = ['canonical_name', 'canonical_brand', 'canonical_category', 'status'];
        
        foreach ($significantFields as $field) {
            if (isset($changes[$field])) {
                return true;
            }
        }
        
        return false;
    }
    
    private function getUpdatePriority($updateType, $changes) {
        // Higher priority (lower number) for critical updates
        if ($updateType === 'inventory_update' && isset($changes['current_stock']) && $changes['current_stock'] <= 5) {
            return 1; // Critical stock
        }
        
        if ($updateType === 'master_product_update' && $this->isSignificantChange($changes)) {
            return 2; // Significant master data change
        }
        
        return 3; // Normal priority
    }
    
    private function updateTaskStatus($taskId, $status, $errorMessage = null) {
        $stmt = $this->pdo->prepare("
            UPDATE async_update_queue 
            SET status = ?, error_message = ?, updated_at = NOW()
            WHERE id = ?
        ");
        $stmt->execute([$status, $errorMessage, $taskId]);
    }
    
    private function saveDataQualityMetric($metricName, $metricData) {
        $stmt = $this->pdo->prepare("
            INSERT INTO data_quality_metrics 
            (metric_name, metric_value, total_records, good_records, calculation_date)
            VALUES (?, ?, ?, ?, NOW())
        ");
        
        $stmt->execute([
            $metricName,
            $metricData['percentage'],
            $metricData['total'],
            $metricData['good']
        ]);
    }
    
    // Data quality calculation methods
    private function calculateMasterDataCoverage() {
        $stmt = $this->pdo->query("
            SELECT 
                COUNT(DISTINCT i.sku) as total,
                COUNT(DISTINCT CASE WHEN mp.master_id IS NOT NULL THEN i.sku END) as good
            FROM inventory_data i
            LEFT JOIN sku_mapping sm ON i.sku = sm.external_sku
            LEFT JOIN master_products mp ON sm.master_id = mp.master_id AND mp.status = 'active'
        ");
        
        $result = $stmt->fetch(\PDO::FETCH_ASSOC);
        return [
            'total' => (int)$result['total'],
            'good' => (int)$result['good'],
            'percentage' => $result['total'] > 0 ? round(($result['good'] / $result['total']) * 100, 2) : 0
        ];
    }
    
    private function calculateBrandCompleteness() {
        $stmt = $this->pdo->query("
            SELECT 
                COUNT(*) as total,
                COUNT(CASE WHEN canonical_brand IS NOT NULL AND canonical_brand != 'Неизвестный бренд' THEN 1 END) as good
            FROM master_products
            WHERE status = 'active'
        ");
        
        $result = $stmt->fetch(\PDO::FETCH_ASSOC);
        return [
            'total' => (int)$result['total'],
            'good' => (int)$result['good'],
            'percentage' => $result['total'] > 0 ? round(($result['good'] / $result['total']) * 100, 2) : 0
        ];
    }
    
    private function calculateCategoryCompleteness() {
        $stmt = $this->pdo->query("
            SELECT 
                COUNT(*) as total,
                COUNT(CASE WHEN canonical_category IS NOT NULL AND canonical_category != 'Без категории' THEN 1 END) as good
            FROM master_products
            WHERE status = 'active'
        ");
        
        $result = $stmt->fetch(\PDO::FETCH_ASSOC);
        return [
            'total' => (int)$result['total'],
            'good' => (int)$result['good'],
            'percentage' => $result['total'] > 0 ? round(($result['good'] / $result['total']) * 100, 2) : 0
        ];
    }
    
    private function calculateDescriptionCompleteness() {
        $stmt = $this->pdo->query("
            SELECT 
                COUNT(*) as total,
                COUNT(CASE WHEN description IS NOT NULL AND description != '' THEN 1 END) as good
            FROM master_products
            WHERE status = 'active'
        ");
        
        $result = $stmt->fetch(\PDO::FETCH_ASSOC);
        return [
            'total' => (int)$result['total'],
            'good' => (int)$result['good'],
            'percentage' => $result['total'] > 0 ? round(($result['good'] / $result['total']) * 100, 2) : 0
        ];
    }
    
    private function calculateVerificationCompleteness() {
        $stmt = $this->pdo->query("
            SELECT 
                COUNT(*) as total,
                COUNT(CASE WHEN verification_status IN ('auto', 'manual') THEN 1 END) as good
            FROM sku_mapping
        ");
        
        $result = $stmt->fetch(\PDO::FETCH_ASSOC);
        return [
            'total' => (int)$result['total'],
            'good' => (int)$result['good'],
            'percentage' => $result['total'] > 0 ? round(($result['good'] / $result['total']) * 100, 2) : 0
        ];
    }
    
    // Placeholder methods for specific report updates
    private function recalculateBrandAnalytics($brand) {
        // Implementation for brand-specific analytics recalculation
    }
    
    private function recalculateCategoryAnalytics($category) {
        // Implementation for category-specific analytics recalculation
    }
    
    private function recalculateInventorySummary() {
        // Implementation for inventory summary recalculation
    }
    
    private function updateCriticalStockAlerts($sku) {
        // Implementation for critical stock alerts update
    }
    
    private function updatePerformanceMetrics($masterId) {
        // Implementation for performance metrics update
    }
    
    private function updateMappingCoverageReports() {
        // Implementation for mapping coverage reports update
    }
    
    private function updateDataQualityReports() {
        // Implementation for data quality reports update
    }
    
    private function createCriticalStockAlert($sku, $currentStock, $reservedStock) {
        // Implementation for creating critical stock alerts
    }
    
    private function processAsyncMasterProductUpdate($taskData) {
        // Implementation for async master product update processing
    }
    
    private function processAsyncSkuMappingUpdate($taskData) {
        // Implementation for async SKU mapping update processing
    }
    
    private function processAsyncInventoryUpdate($taskData) {
        // Implementation for async inventory update processing
    }
    
    private function formatUpdateNotification($type, $identifier, $changes) {
        return "MDM Update: {$type} {$identifier} changed - " . json_encode($changes);
    }
}
?>