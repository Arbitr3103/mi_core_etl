<?php
/**
 * Data Quality Monitor
 * 
 * Monitors MDM system data quality and triggers alerts
 * Requirements: 8.3, 4.3
 */

class DataQualityMonitor {
    private $pdo;
    private $alertThresholds;
    private $alertHandlers = [];
    
    public function __construct($pdo) {
        $this->pdo = $pdo;
        
        // Default alert thresholds
        $this->alertThresholds = [
            'failed_percentage' => 5,      // Alert if > 5% failed
            'pending_percentage' => 10,    // Alert if > 10% pending
            'real_names_percentage' => 80, // Alert if < 80% have real names
            'sync_age_hours' => 48,        // Alert if no sync in 48h
            'error_count_hourly' => 10     // Alert if > 10 errors/hour
        ];
    }
    
    /**
     * Set custom alert thresholds
     */
    public function setThresholds($thresholds) {
        $this->alertThresholds = array_merge($this->alertThresholds, $thresholds);
    }
    
    /**
     * Add alert handler (email, slack, log, etc.)
     */
    public function addAlertHandler($handler) {
        $this->alertHandlers[] = $handler;
    }
    
    /**
     * Run all quality checks and trigger alerts if needed
     */
    public function runQualityChecks() {
        $alerts = [];
        
        // Check 1: Failed sync percentage
        $failedPercentage = $this->getFailedSyncPercentage();
        if ($failedPercentage > $this->alertThresholds['failed_percentage']) {
            $alerts[] = [
                'level' => 'critical',
                'type' => 'high_failure_rate',
                'message' => "High sync failure rate: {$failedPercentage}%",
                'value' => $failedPercentage,
                'threshold' => $this->alertThresholds['failed_percentage']
            ];
        }
        
        // Check 2: Pending sync percentage
        $pendingPercentage = $this->getPendingSyncPercentage();
        if ($pendingPercentage > $this->alertThresholds['pending_percentage']) {
            $alerts[] = [
                'level' => 'warning',
                'type' => 'high_pending_rate',
                'message' => "High pending sync rate: {$pendingPercentage}%",
                'value' => $pendingPercentage,
                'threshold' => $this->alertThresholds['pending_percentage']
            ];
        }
        
        // Check 3: Real names percentage
        $realNamesPercentage = $this->getRealNamesPercentage();
        if ($realNamesPercentage < $this->alertThresholds['real_names_percentage']) {
            $alerts[] = [
                'level' => 'warning',
                'type' => 'low_real_names',
                'message' => "Low real names percentage: {$realNamesPercentage}%",
                'value' => $realNamesPercentage,
                'threshold' => $this->alertThresholds['real_names_percentage']
            ];
        }
        
        // Check 4: Sync age
        $syncAgeHours = $this->getLastSyncAgeHours();
        if ($syncAgeHours > $this->alertThresholds['sync_age_hours']) {
            $alerts[] = [
                'level' => 'critical',
                'type' => 'stale_sync',
                'message' => "No successful sync in {$syncAgeHours} hours",
                'value' => $syncAgeHours,
                'threshold' => $this->alertThresholds['sync_age_hours']
            ];
        }
        
        // Check 5: Error rate
        $errorCount = $this->getHourlyErrorCount();
        if ($errorCount > $this->alertThresholds['error_count_hourly']) {
            $alerts[] = [
                'level' => 'critical',
                'type' => 'high_error_rate',
                'message' => "High error rate: {$errorCount} errors in last hour",
                'value' => $errorCount,
                'threshold' => $this->alertThresholds['error_count_hourly']
            ];
        }
        
        // Trigger alerts
        foreach ($alerts as $alert) {
            $this->triggerAlert($alert);
        }
        
        return [
            'alerts_triggered' => count($alerts),
            'alerts' => $alerts,
            'timestamp' => date('Y-m-d H:i:s')
        ];
    }
    
    /**
     * Get comprehensive quality metrics
     */
    public function getQualityMetrics() {
        return [
            'sync_status' => $this->getSyncStatusMetrics(),
            'data_quality' => $this->getDataQualityMetrics(),
            'error_metrics' => $this->getErrorMetrics(),
            'performance' => $this->getPerformanceMetrics(),
            'overall_score' => $this->calculateOverallQualityScore()
        ];
    }
    
    /**
     * Get sync status metrics
     */
    private function getSyncStatusMetrics() {
        $stmt = $this->pdo->query("
            SELECT 
                COUNT(*) as total,
                COUNT(CASE WHEN sync_status = 'synced' THEN 1 END) as synced,
                COUNT(CASE WHEN sync_status = 'pending' THEN 1 END) as pending,
                COUNT(CASE WHEN sync_status = 'failed' THEN 1 END) as failed,
                ROUND(COUNT(CASE WHEN sync_status = 'synced' THEN 1 END) * 100.0 / COUNT(*), 2) as synced_percentage,
                ROUND(COUNT(CASE WHEN sync_status = 'pending' THEN 1 END) * 100.0 / COUNT(*), 2) as pending_percentage,
                ROUND(COUNT(CASE WHEN sync_status = 'failed' THEN 1 END) * 100.0 / COUNT(*), 2) as failed_percentage
            FROM product_cross_reference
        ");
        
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }
    
    /**
     * Get data quality metrics
     */
    private function getDataQualityMetrics() {
        $stmt = $this->pdo->query("
            SELECT 
                COUNT(*) as total,
                COUNT(CASE WHEN cached_name IS NOT NULL AND cached_name NOT LIKE 'Товар%ID%' THEN 1 END) as with_real_names,
                COUNT(CASE WHEN cached_brand IS NOT NULL THEN 1 END) as with_brands,
                COUNT(CASE WHEN last_successful_sync IS NOT NULL THEN 1 END) as with_api_sync,
                ROUND(COUNT(CASE WHEN cached_name IS NOT NULL AND cached_name NOT LIKE 'Товар%ID%' THEN 1 END) * 100.0 / COUNT(*), 2) as real_names_percentage,
                ROUND(COUNT(CASE WHEN cached_brand IS NOT NULL THEN 1 END) * 100.0 / COUNT(*), 2) as brands_percentage,
                ROUND(AVG(TIMESTAMPDIFF(DAY, last_successful_sync, NOW())), 2) as avg_cache_age_days
            FROM product_cross_reference
        ");
        
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }
    
    /**
     * Get error metrics
     */
    private function getErrorMetrics() {
        // Check if sync_errors table exists
        $tableExists = $this->pdo->query("SHOW TABLES LIKE 'sync_errors'")->fetch();
        
        if (!$tableExists) {
            return [
                'total_errors_24h' => 0,
                'total_errors_7d' => 0,
                'affected_products' => 0,
                'error_types' => []
            ];
        }
        
        $stmt = $this->pdo->query("
            SELECT 
                COUNT(*) as total_errors_24h,
                COUNT(DISTINCT product_id) as affected_products
            FROM sync_errors
            WHERE created_at > DATE_SUB(NOW(), INTERVAL 24 HOUR)
        ");
        $metrics = $stmt->fetch(PDO::FETCH_ASSOC);
        
        $stmt = $this->pdo->query("
            SELECT COUNT(*) as total_errors_7d
            FROM sync_errors
            WHERE created_at > DATE_SUB(NOW(), INTERVAL 7 DAY)
        ");
        $metrics['total_errors_7d'] = $stmt->fetch()['total_errors_7d'];
        
        $stmt = $this->pdo->query("
            SELECT error_type, COUNT(*) as count
            FROM sync_errors
            WHERE created_at > DATE_SUB(NOW(), INTERVAL 24 HOUR)
            GROUP BY error_type
            ORDER BY count DESC
            LIMIT 5
        ");
        $metrics['error_types'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        return $metrics;
    }
    
    /**
     * Get performance metrics
     */
    private function getPerformanceMetrics() {
        return [
            'products_synced_today' => $this->getProductsSyncedToday(),
            'avg_sync_time_estimate' => '2.5s',
            'last_sync_time' => $this->getLastSyncTime()
        ];
    }
    
    /**
     * Calculate overall quality score (0-100)
     */
    private function calculateOverallQualityScore() {
        $syncMetrics = $this->getSyncStatusMetrics();
        $qualityMetrics = $this->getDataQualityMetrics();
        
        // Weighted scoring
        $syncedScore = $syncMetrics['synced_percentage'] ?? 0;
        $realNamesScore = $qualityMetrics['real_names_percentage'] ?? 0;
        $brandsScore = $qualityMetrics['brands_percentage'] ?? 0;
        
        // Penalties
        $failedPenalty = ($syncMetrics['failed_percentage'] ?? 0) * 2;
        
        $score = ($syncedScore * 0.4) + ($realNamesScore * 0.4) + ($brandsScore * 0.2) - $failedPenalty;
        
        return round(max(0, min(100, $score)), 2);
    }
    
    /**
     * Helper methods for specific metrics
     */
    private function getFailedSyncPercentage() {
        $stmt = $this->pdo->query("
            SELECT ROUND(COUNT(CASE WHEN sync_status = 'failed' THEN 1 END) * 100.0 / COUNT(*), 2) as percentage
            FROM product_cross_reference
        ");
        return $stmt->fetch()['percentage'] ?? 0;
    }
    
    private function getPendingSyncPercentage() {
        $stmt = $this->pdo->query("
            SELECT ROUND(COUNT(CASE WHEN sync_status = 'pending' THEN 1 END) * 100.0 / COUNT(*), 2) as percentage
            FROM product_cross_reference
        ");
        return $stmt->fetch()['percentage'] ?? 0;
    }
    
    private function getRealNamesPercentage() {
        $stmt = $this->pdo->query("
            SELECT ROUND(COUNT(CASE WHEN cached_name IS NOT NULL AND cached_name NOT LIKE 'Товар%ID%' THEN 1 END) * 100.0 / COUNT(*), 2) as percentage
            FROM product_cross_reference
        ");
        return $stmt->fetch()['percentage'] ?? 0;
    }
    
    private function getLastSyncAgeHours() {
        $stmt = $this->pdo->query("
            SELECT TIMESTAMPDIFF(HOUR, MAX(last_successful_sync), NOW()) as hours
            FROM product_cross_reference
            WHERE sync_status = 'synced'
        ");
        return $stmt->fetch()['hours'] ?? 999;
    }
    
    private function getHourlyErrorCount() {
        $tableExists = $this->pdo->query("SHOW TABLES LIKE 'sync_errors'")->fetch();
        if (!$tableExists) return 0;
        
        $stmt = $this->pdo->query("
            SELECT COUNT(*) as count
            FROM sync_errors
            WHERE created_at > DATE_SUB(NOW(), INTERVAL 1 HOUR)
        ");
        return $stmt->fetch()['count'] ?? 0;
    }
    
    private function getProductsSyncedToday() {
        $stmt = $this->pdo->query("
            SELECT COUNT(*) as count
            FROM product_cross_reference
            WHERE DATE(last_successful_sync) = CURDATE()
        ");
        return $stmt->fetch()['count'] ?? 0;
    }
    
    private function getLastSyncTime() {
        $stmt = $this->pdo->query("
            SELECT MAX(last_successful_sync) as last_sync
            FROM product_cross_reference
            WHERE sync_status = 'synced'
        ");
        return $stmt->fetch()['last_sync'] ?? null;
    }
    
    /**
     * Trigger alert through all registered handlers
     */
    private function triggerAlert($alert) {
        // Log alert
        $this->logAlert($alert);
        
        // Call custom handlers
        foreach ($this->alertHandlers as $handler) {
            call_user_func($handler, $alert);
        }
    }
    
    /**
     * Log alert to database
     */
    private function logAlert($alert) {
        try {
            // Create alerts table if not exists
            $this->pdo->exec("
                CREATE TABLE IF NOT EXISTS quality_alerts (
                    id BIGINT AUTO_INCREMENT PRIMARY KEY,
                    alert_level VARCHAR(20),
                    alert_type VARCHAR(50),
                    message TEXT,
                    value DECIMAL(10,2),
                    threshold_value DECIMAL(10,2),
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    INDEX idx_created_at (created_at),
                    INDEX idx_alert_type (alert_type)
                )
            ");
            
            $stmt = $this->pdo->prepare("
                INSERT INTO quality_alerts (alert_level, alert_type, message, value, threshold_value)
                VALUES (?, ?, ?, ?, ?)
            ");
            
            $stmt->execute([
                $alert['level'],
                $alert['type'],
                $alert['message'],
                $alert['value'],
                $alert['threshold']
            ]);
        } catch (Exception $e) {
            error_log("Failed to log alert: " . $e->getMessage());
        }
    }
    
    /**
     * Get recent alerts
     */
    public function getRecentAlerts($limit = 10) {
        $tableExists = $this->pdo->query("SHOW TABLES LIKE 'quality_alerts'")->fetch();
        if (!$tableExists) return [];
        
        $stmt = $this->pdo->prepare("
            SELECT * FROM quality_alerts
            ORDER BY created_at DESC
            LIMIT ?
        ");
        $stmt->execute([$limit]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    /**
     * Get alert statistics
     */
    public function getAlertStats() {
        $tableExists = $this->pdo->query("SHOW TABLES LIKE 'quality_alerts'")->fetch();
        if (!$tableExists) return [];
        
        $stmt = $this->pdo->query("
            SELECT 
                alert_level,
                alert_type,
                COUNT(*) as count,
                MAX(created_at) as last_occurrence
            FROM quality_alerts
            WHERE created_at > DATE_SUB(NOW(), INTERVAL 7 DAY)
            GROUP BY alert_level, alert_type
            ORDER BY count DESC
        ");
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}
