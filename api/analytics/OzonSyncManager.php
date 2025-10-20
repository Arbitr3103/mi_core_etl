<?php
/**
 * Ozon Data Synchronization Manager
 * 
 * Manages the synchronization of regional sales data from Ozon API.
 * Handles scheduling, incremental updates, conflict resolution, and monitoring.
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/OzonAPIService.php';

class OzonSyncManager {
    
    private $pdo;
    private $ozonApi;
    private $syncLogTable = 'ozon_sync_log';
    
    /**
     * Constructor
     */
    public function __construct() {
        $this->pdo = getAnalyticsDbConnection();
        $this->ozonApi = new OzonAPIService();
        
        // Create sync log table if it doesn't exist
        $this->createSyncLogTable();
        
        logAnalyticsActivity('INFO', 'OzonSyncManager initialized');
    }
    
    /**
     * Perform daily synchronization
     * 
     * @param string|null $date Date to sync (YYYY-MM-DD), defaults to yesterday
     * @return array Sync results
     */
    public function performDailySync($date = null) {
        if (!$date) {
            $date = date('Y-m-d', strtotime('-1 day'));
        }
        
        logAnalyticsActivity('INFO', 'Starting daily sync', ['date' => $date]);
        
        $syncId = $this->createSyncLogEntry('daily', $date, $date);
        
        try {
            // Check if sync already completed for this date
            if ($this->isSyncCompleted($date)) {
                logAnalyticsActivity('INFO', 'Sync already completed for date', ['date' => $date]);
                $this->updateSyncLogEntry($syncId, 'skipped', 'Sync already completed for this date');
                
                return [
                    'success' => true,
                    'status' => 'skipped',
                    'message' => 'Sync already completed for this date',
                    'date' => $date
                ];
            }
            
            // Perform the sync
            $results = $this->ozonApi->syncRegionalData($date, $date);
            
            if ($results['success']) {
                $this->updateSyncLogEntry($syncId, 'completed', 'Daily sync completed successfully', $results);
                $this->markSyncCompleted($date);
                
                logAnalyticsActivity('INFO', 'Daily sync completed successfully', $results);
            } else {
                $this->updateSyncLogEntry($syncId, 'failed', 'Daily sync failed', $results);
                logAnalyticsActivity('ERROR', 'Daily sync failed', $results);
            }
            
            return $results;
            
        } catch (Exception $e) {
            $this->updateSyncLogEntry($syncId, 'error', $e->getMessage());
            
            logAnalyticsActivity('ERROR', 'Daily sync error', [
                'date' => $date,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            
            throw $e;
        }
    }
    
    /**
     * Perform incremental synchronization for a date range
     * 
     * @param string $dateFrom Start date (YYYY-MM-DD)
     * @param string $dateTo End date (YYYY-MM-DD)
     * @param bool $forceResync Force resync even if already completed
     * @return array Sync results
     */
    public function performIncrementalSync($dateFrom, $dateTo, $forceResync = false) {
        logAnalyticsActivity('INFO', 'Starting incremental sync', [
            'date_from' => $dateFrom,
            'date_to' => $dateTo,
            'force_resync' => $forceResync
        ]);
        
        $syncId = $this->createSyncLogEntry('incremental', $dateFrom, $dateTo);
        
        try {
            // Get dates that need syncing
            $datesToSync = $this->getDatesThatNeedSync($dateFrom, $dateTo, $forceResync);
            
            if (empty($datesToSync)) {
                logAnalyticsActivity('INFO', 'No dates need syncing');
                $this->updateSyncLogEntry($syncId, 'skipped', 'No dates need syncing');
                
                return [
                    'success' => true,
                    'status' => 'skipped',
                    'message' => 'No dates need syncing',
                    'dates_processed' => 0
                ];
            }
            
            $totalResults = [
                'success' => true,
                'dates_processed' => 0,
                'records_processed' => 0,
                'records_inserted' => 0,
                'records_updated' => 0,
                'errors' => []
            ];
            
            // Process each date
            foreach ($datesToSync as $date) {
                try {
                    $dateResults = $this->ozonApi->syncRegionalData($date, $date);
                    
                    if ($dateResults['success']) {
                        $totalResults['dates_processed']++;
                        $totalResults['records_processed'] += $dateResults['records_processed'];
                        $totalResults['records_inserted'] += $dateResults['records_inserted'];
                        $totalResults['records_updated'] += $dateResults['records_updated'];
                        
                        $this->markSyncCompleted($date);
                        
                        logAnalyticsActivity('INFO', 'Date sync completed', [
                            'date' => $date,
                            'results' => $dateResults
                        ]);
                    } else {
                        $totalResults['errors'][] = [
                            'date' => $date,
                            'errors' => $dateResults['errors']
                        ];
                        
                        logAnalyticsActivity('ERROR', 'Date sync failed', [
                            'date' => $date,
                            'errors' => $dateResults['errors']
                        ]);
                    }
                    
                    // Add delay between requests to respect API limits
                    usleep(100000); // 0.1 second delay
                    
                } catch (Exception $e) {
                    $totalResults['errors'][] = [
                        'date' => $date,
                        'error' => $e->getMessage()
                    ];
                    
                    logAnalyticsActivity('ERROR', 'Date sync error', [
                        'date' => $date,
                        'error' => $e->getMessage()
                    ]);
                }
            }
            
            // Determine overall success
            $totalResults['success'] = empty($totalResults['errors']);
            $status = $totalResults['success'] ? 'completed' : 'partial';
            
            $this->updateSyncLogEntry($syncId, $status, 'Incremental sync completed', $totalResults);
            
            logAnalyticsActivity('INFO', 'Incremental sync completed', $totalResults);
            
            return $totalResults;
            
        } catch (Exception $e) {
            $this->updateSyncLogEntry($syncId, 'error', $e->getMessage());
            
            logAnalyticsActivity('ERROR', 'Incremental sync error', [
                'date_from' => $dateFrom,
                'date_to' => $dateTo,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            
            throw $e;
        }
    }
    
    /**
     * Perform full resync for a date range (clears existing data first)
     * 
     * @param string $dateFrom Start date (YYYY-MM-DD)
     * @param string $dateTo End date (YYYY-MM-DD)
     * @return array Sync results
     */
    public function performFullResync($dateFrom, $dateTo) {
        logAnalyticsActivity('INFO', 'Starting full resync', [
            'date_from' => $dateFrom,
            'date_to' => $dateTo
        ]);
        
        $syncId = $this->createSyncLogEntry('full_resync', $dateFrom, $dateTo);
        
        try {
            // Clear existing data for the date range
            $this->clearExistingData($dateFrom, $dateTo);
            
            // Clear sync completion markers
            $this->clearSyncCompletionMarkers($dateFrom, $dateTo);
            
            // Perform incremental sync (which will now insert all data as new)
            $results = $this->performIncrementalSync($dateFrom, $dateTo, true);
            
            $results['sync_type'] = 'full_resync';
            
            $this->updateSyncLogEntry($syncId, $results['success'] ? 'completed' : 'failed', 'Full resync completed', $results);
            
            logAnalyticsActivity('INFO', 'Full resync completed', $results);
            
            return $results;
            
        } catch (Exception $e) {
            $this->updateSyncLogEntry($syncId, 'error', $e->getMessage());
            
            logAnalyticsActivity('ERROR', 'Full resync error', [
                'date_from' => $dateFrom,
                'date_to' => $dateTo,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            
            throw $e;
        }
    }
    
    /**
     * Get sync status for a date range
     * 
     * @param string $dateFrom Start date (YYYY-MM-DD)
     * @param string $dateTo End date (YYYY-MM-DD)
     * @return array Sync status information
     */
    public function getSyncStatus($dateFrom, $dateTo) {
        $stmt = $this->pdo->prepare("
            SELECT 
                DATE(date_from) as sync_date,
                COUNT(*) as records_count,
                SUM(sales_amount) as total_revenue,
                COUNT(DISTINCT region_id) as regions_count,
                COUNT(DISTINCT product_id) as products_count,
                MAX(synced_at) as last_synced
            FROM ozon_regional_sales 
            WHERE date_from >= ? AND date_to <= ?
            GROUP BY DATE(date_from)
            ORDER BY sync_date
        ");
        
        $stmt->execute([$dateFrom, $dateTo]);
        $syncedDates = $stmt->fetchAll();
        
        // Get sync log entries
        $stmt = $this->pdo->prepare("
            SELECT sync_type, status, date_from, date_to, started_at, completed_at, message
            FROM {$this->syncLogTable}
            WHERE date_from >= ? AND date_to <= ?
            ORDER BY started_at DESC
            LIMIT 10
        ");
        
        $stmt->execute([$dateFrom, $dateTo]);
        $syncLogs = $stmt->fetchAll();
        
        // Calculate summary
        $totalRecords = array_sum(array_column($syncedDates, 'records_count'));
        $totalRevenue = array_sum(array_column($syncedDates, 'total_revenue'));
        $uniqueRegions = 0;
        $uniqueProducts = 0;
        
        if (!empty($syncedDates)) {
            $stmt = $this->pdo->prepare("
                SELECT 
                    COUNT(DISTINCT region_id) as regions_count,
                    COUNT(DISTINCT product_id) as products_count
                FROM ozon_regional_sales 
                WHERE date_from >= ? AND date_to <= ?
            ");
            
            $stmt->execute([$dateFrom, $dateTo]);
            $summary = $stmt->fetch();
            
            $uniqueRegions = $summary['regions_count'];
            $uniqueProducts = $summary['products_count'];
        }
        
        return [
            'date_from' => $dateFrom,
            'date_to' => $dateTo,
            'synced_dates' => $syncedDates,
            'sync_logs' => $syncLogs,
            'summary' => [
                'total_records' => $totalRecords,
                'total_revenue' => $totalRevenue,
                'unique_regions' => $uniqueRegions,
                'unique_products' => $uniqueProducts,
                'dates_with_data' => count($syncedDates)
            ]
        ];
    }
    
    /**
     * Check if sync is completed for a specific date
     * 
     * @param string $date Date to check (YYYY-MM-DD)
     * @return bool True if sync is completed
     */
    private function isSyncCompleted($date) {
        $stmt = $this->pdo->prepare("
            SELECT COUNT(*) as count
            FROM {$this->syncLogTable}
            WHERE DATE(date_from) = ? AND DATE(date_to) = ? AND status = 'completed'
            ORDER BY completed_at DESC
            LIMIT 1
        ");
        
        $stmt->execute([$date, $date]);
        $result = $stmt->fetch();
        
        return $result['count'] > 0;
    }
    
    /**
     * Mark sync as completed for a date
     * 
     * @param string $date Date to mark (YYYY-MM-DD)
     */
    private function markSyncCompleted($date) {
        // This is handled by updateSyncLogEntry, but we could add additional tracking here
        logAnalyticsActivity('INFO', 'Sync marked as completed', ['date' => $date]);
    }
    
    /**
     * Get dates that need syncing
     * 
     * @param string $dateFrom Start date
     * @param string $dateTo End date
     * @param bool $forceResync Force resync even if completed
     * @return array Array of dates that need syncing
     */
    private function getDatesThatNeedSync($dateFrom, $dateTo, $forceResync = false) {
        $dates = [];
        $currentDate = strtotime($dateFrom);
        $endDate = strtotime($dateTo);
        
        while ($currentDate <= $endDate) {
            $dateStr = date('Y-m-d', $currentDate);
            
            if ($forceResync || !$this->isSyncCompleted($dateStr)) {
                $dates[] = $dateStr;
            }
            
            $currentDate = strtotime('+1 day', $currentDate);
        }
        
        return $dates;
    }
    
    /**
     * Clear existing data for date range
     * 
     * @param string $dateFrom Start date
     * @param string $dateTo End date
     */
    private function clearExistingData($dateFrom, $dateTo) {
        $stmt = $this->pdo->prepare("
            DELETE FROM ozon_regional_sales 
            WHERE date_from >= ? AND date_to <= ?
        ");
        
        $stmt->execute([$dateFrom, $dateTo]);
        $deletedRows = $stmt->rowCount();
        
        logAnalyticsActivity('INFO', 'Cleared existing data for resync', [
            'date_from' => $dateFrom,
            'date_to' => $dateTo,
            'deleted_rows' => $deletedRows
        ]);
    }
    
    /**
     * Clear sync completion markers for date range
     * 
     * @param string $dateFrom Start date
     * @param string $dateTo End date
     */
    private function clearSyncCompletionMarkers($dateFrom, $dateTo) {
        $stmt = $this->pdo->prepare("
            UPDATE {$this->syncLogTable}
            SET status = 'cleared_for_resync'
            WHERE date_from >= ? AND date_to <= ? AND status = 'completed'
        ");
        
        $stmt->execute([$dateFrom, $dateTo]);
        $updatedRows = $stmt->rowCount();
        
        logAnalyticsActivity('INFO', 'Cleared sync completion markers', [
            'date_from' => $dateFrom,
            'date_to' => $dateTo,
            'updated_rows' => $updatedRows
        ]);
    }
    
    /**
     * Create sync log entry
     * 
     * @param string $syncType Type of sync
     * @param string $dateFrom Start date
     * @param string $dateTo End date
     * @return int Sync log ID
     */
    private function createSyncLogEntry($syncType, $dateFrom, $dateTo) {
        $stmt = $this->pdo->prepare("
            INSERT INTO {$this->syncLogTable} (sync_type, date_from, date_to, status, started_at)
            VALUES (?, ?, ?, 'running', NOW())
        ");
        
        $stmt->execute([$syncType, $dateFrom, $dateTo]);
        
        return $this->pdo->lastInsertId();
    }
    
    /**
     * Update sync log entry
     * 
     * @param int $syncId Sync log ID
     * @param string $status Status
     * @param string $message Message
     * @param array|null $results Results data
     */
    private function updateSyncLogEntry($syncId, $status, $message, $results = null) {
        $stmt = $this->pdo->prepare("
            UPDATE {$this->syncLogTable}
            SET status = ?, message = ?, results = ?, completed_at = NOW()
            WHERE id = ?
        ");
        
        $resultsJson = $results ? json_encode($results) : null;
        $stmt->execute([$status, $message, $resultsJson, $syncId]);
    }
    
    /**
     * Create sync log table if it doesn't exist
     */
    private function createSyncLogTable() {
        $sql = "
            CREATE TABLE IF NOT EXISTS {$this->syncLogTable} (
                id INT PRIMARY KEY AUTO_INCREMENT,
                sync_type VARCHAR(50) NOT NULL COMMENT 'Type of sync (daily, incremental, full_resync)',
                date_from DATE NOT NULL COMMENT 'Start date of sync',
                date_to DATE NOT NULL COMMENT 'End date of sync',
                status VARCHAR(50) NOT NULL COMMENT 'Sync status (running, completed, failed, error, skipped)',
                message TEXT NULL COMMENT 'Sync message or error description',
                results JSON NULL COMMENT 'Detailed sync results',
                started_at TIMESTAMP NOT NULL COMMENT 'When sync started',
                completed_at TIMESTAMP NULL COMMENT 'When sync completed',
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                
                INDEX idx_sync_type (sync_type),
                INDEX idx_date_range (date_from, date_to),
                INDEX idx_status (status),
                INDEX idx_started_at (started_at),
                INDEX idx_completed_at (completed_at),
                INDEX idx_type_status (sync_type, status),
                INDEX idx_date_status (date_from, date_to, status)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci 
            COMMENT='Log of Ozon API synchronization operations'
        ";
        
        $this->pdo->exec($sql);
    }
    
    /**
     * Get recent sync activity
     * 
     * @param int $limit Number of entries to return
     * @return array Recent sync log entries
     */
    public function getRecentSyncActivity($limit = 20) {
        $stmt = $this->pdo->prepare("
            SELECT 
                id, sync_type, date_from, date_to, status, message,
                started_at, completed_at,
                TIMESTAMPDIFF(SECOND, started_at, COALESCE(completed_at, NOW())) as duration_seconds
            FROM {$this->syncLogTable}
            ORDER BY started_at DESC
            LIMIT ?
        ");
        
        $stmt->execute([$limit]);
        return $stmt->fetchAll();
    }
    
    /**
     * Get sync statistics
     * 
     * @param int $days Number of days to analyze
     * @return array Sync statistics
     */
    public function getSyncStatistics($days = 30) {
        $dateFrom = date('Y-m-d', strtotime("-$days days"));
        
        $stmt = $this->pdo->prepare("
            SELECT 
                status,
                COUNT(*) as count,
                AVG(TIMESTAMPDIFF(SECOND, started_at, completed_at)) as avg_duration_seconds
            FROM {$this->syncLogTable}
            WHERE started_at >= ?
            GROUP BY status
        ");
        
        $stmt->execute([$dateFrom]);
        $statusStats = $stmt->fetchAll();
        
        $stmt = $this->pdo->prepare("
            SELECT 
                COUNT(*) as total_syncs,
                SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as successful_syncs,
                SUM(CASE WHEN status IN ('failed', 'error') THEN 1 ELSE 0 END) as failed_syncs,
                MIN(started_at) as first_sync,
                MAX(started_at) as last_sync
            FROM {$this->syncLogTable}
            WHERE started_at >= ?
        ");
        
        $stmt->execute([$dateFrom]);
        $summary = $stmt->fetch();
        
        return [
            'period_days' => $days,
            'date_from' => $dateFrom,
            'summary' => $summary,
            'status_breakdown' => $statusStats
        ];
    }
}

?>