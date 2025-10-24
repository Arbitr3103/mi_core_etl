<?php
/**
 * InventoryDataUpdater Class - Handles inventory data updates from warehouse stock reports
 * 
 * This class manages the updating of inventory data from Ozon warehouse stock reports,
 * implementing upsert logic, transaction management, and data consistency checks.
 * 
 * @version 1.0
 * @author Manhattan System
 */

require_once __DIR__ . '/../utils/Database.php';
require_once __DIR__ . '/../utils/Logger.php';

class InventoryDataUpdater {
    
    private $pdo;
    private $logger;
    private $batchSize;
    private $stats;
    private $progressCallback;
    private $memoryLimit;
    private $memoryThreshold;
    
    public function __construct(?PDO $pdo = null, int $batchSize = 1000) {
        $this->pdo = $pdo ?? Database::getInstance()->getConnection();
        $this->logger = Logger::getInstance();
        $this->batchSize = $batchSize;
        $this->progressCallback = null;
        $this->memoryLimit = $this->parseMemoryLimit(ini_get('memory_limit'));
        $this->memoryThreshold = $this->memoryLimit * 0.8; // 80% threshold
        $this->resetStats();
    }
    
    /**
     * Reset statistics counters
     */
    private function resetStats(): void {
        $this->stats = [
            'updated_count' => 0,
            'inserted_count' => 0,
            'errors_count' => 0,
            'skipped_count' => 0,
            'processed_count' => 0,
            'start_time' => microtime(true),
            'errors' => []
        ];
    }
    
    /**
     * Update inventory from report data with bulk processing
     * 
     * @param array $stockData - Array of stock records from CSV report
     * @param string $reportCode - Report code for tracking
     * @return array Update result with statistics
     */
    public function updateInventoryFromReport(array $stockData, ?string $reportCode = null): array {
        $this->resetStats();
        $this->logger->info('Starting inventory update from report', [
            'record_count' => count($stockData),
            'report_code' => $reportCode,
            'batch_size' => $this->batchSize
        ]);
        
        try {
            $this->pdo->beginTransaction();
            
            // Process data in batches for memory efficiency
            $totalRecords = count($stockData);
            $batches = array_chunk($stockData, $this->batchSize);
            $totalBatches = count($batches);
            
            foreach ($batches as $batchIndex => $batch) {
                $currentBatch = $batchIndex + 1;
                
                $this->logger->debug('Processing batch', [
                    'batch_index' => $currentBatch,
                    'batch_size' => count($batch),
                    'total_batches' => $totalBatches,
                    'progress_percent' => round(($currentBatch / $totalBatches) * 100, 2)
                ]);
                
                // Check memory usage before processing batch
                $this->checkMemoryUsage($currentBatch, $totalBatches);
                
                $this->processBatch($batch, $reportCode);
                
                // Report progress if callback is set
                if ($this->progressCallback) {
                    call_user_func($this->progressCallback, [
                        'current_batch' => $currentBatch,
                        'total_batches' => $totalBatches,
                        'processed_records' => $this->stats['processed_count'],
                        'total_records' => $totalRecords,
                        'progress_percent' => round(($this->stats['processed_count'] / $totalRecords) * 100, 2),
                        'memory_usage' => $this->getMemoryUsage(),
                        'elapsed_time' => microtime(true) - $this->stats['start_time']
                    ]);
                }
                
                // Force garbage collection after each batch to manage memory
                if ($currentBatch % 10 === 0) {
                    gc_collect_cycles();
                }
            }
            
            $this->pdo->commit();
            
            $this->stats['duration'] = microtime(true) - $this->stats['start_time'];
            $this->stats['success'] = true;
            
            $this->logger->info('Inventory update completed successfully', $this->stats);
            
        } catch (Exception $e) {
            $this->pdo->rollback();
            $this->stats['success'] = false;
            $this->stats['error'] = $e->getMessage();
            $this->stats['errors_count']++;
            
            $this->logger->error('Inventory update failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'stats' => $this->stats
            ]);
            
            throw new Exception('Inventory update failed: ' . $e->getMessage());
        }
        
        return $this->stats;
    }
    
    /**
     * Process a batch of stock records
     * 
     * @param array $batch - Batch of stock records
     * @param string $reportCode - Report code for tracking
     */
    private function processBatch(array $batch, ?string $reportCode = null): void {
        foreach ($batch as $stockRecord) {
            try {
                $result = $this->upsertInventoryRecord($stockRecord, $reportCode);
                
                if ($result['action'] === 'inserted') {
                    $this->stats['inserted_count']++;
                } elseif ($result['action'] === 'updated') {
                    $this->stats['updated_count']++;
                } else {
                    $this->stats['skipped_count']++;
                }
                
                $this->stats['processed_count']++;
                
            } catch (Exception $e) {
                $this->stats['errors_count']++;
                $this->stats['errors'][] = [
                    'record' => $stockRecord,
                    'error' => $e->getMessage()
                ];
                
                $this->logger->warning('Failed to process stock record', [
                    'record' => $stockRecord,
                    'error' => $e->getMessage()
                ]);
                
                // Continue processing other records
                continue;
            }
        }
    }
    
    /**
     * Upsert inventory record with proper conflict resolution
     * 
     * @param array $stockRecord - Stock record data
     * @param string $reportCode - Report code for tracking
     * @return array Operation result with action taken
     */
    public function upsertInventoryRecord(array $stockRecord, ?string $reportCode = null): array {
        // Validate required fields
        $this->validateStockRecord($stockRecord);
        
        // Prepare data for upsert
        $data = $this->prepareInventoryData($stockRecord, $reportCode);
        
        try {
            // Use PostgreSQL UPSERT (ON CONFLICT) for atomic operation
            $sql = "
                INSERT INTO inventory (
                    product_id, warehouse_name, source, quantity_present, 
                    quantity_reserved, stock_type, report_source, 
                    last_report_update, report_code, updated_at
                ) VALUES (
                    :product_id, :warehouse_name, :source, :quantity_present,
                    :quantity_reserved, :stock_type, :report_source,
                    :last_report_update, :report_code, :updated_at
                )
                ON CONFLICT (product_id, warehouse_name, source)
                DO UPDATE SET
                    quantity_present = EXCLUDED.quantity_present,
                    quantity_reserved = EXCLUDED.quantity_reserved,
                    stock_type = EXCLUDED.stock_type,
                    report_source = EXCLUDED.report_source,
                    last_report_update = EXCLUDED.last_report_update,
                    report_code = EXCLUDED.report_code,
                    updated_at = EXCLUDED.updated_at
                RETURNING 
                    id,
                    (xmax = 0) AS was_inserted
            ";
            
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($data);
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            
            $action = $result['was_inserted'] ? 'inserted' : 'updated';
            
            $this->logger->debug('Inventory record upserted', [
                'action' => $action,
                'id' => $result['id'],
                'product_id' => $data['product_id'],
                'warehouse_name' => $data['warehouse_name'],
                'source' => $data['source']
            ]);
            
            return [
                'success' => true,
                'action' => $action,
                'id' => $result['id'],
                'data' => $data
            ];
            
        } catch (PDOException $e) {
            $this->logger->error('Upsert operation failed', [
                'data' => $data,
                'error' => $e->getMessage()
            ]);
            
            throw new Exception('Failed to upsert inventory record: ' . $e->getMessage());
        }
    }
    
    /**
     * Validate stock record data with comprehensive integrity checks
     * 
     * @param array $stockRecord - Stock record to validate
     * @throws Exception if validation fails
     */
    private function validateStockRecord(array $stockRecord): void {
        $required = ['product_id', 'warehouse_name', 'source'];
        
        foreach ($required as $field) {
            if (!isset($stockRecord[$field]) || $stockRecord[$field] === '') {
                throw new Exception("Missing required field: {$field}");
            }
        }
        
        // Validate product_id exists in product catalog
        if (!$this->validateProductExists($stockRecord['product_id'])) {
            throw new Exception("Product ID {$stockRecord['product_id']} not found in product catalog");
        }
        
        // Validate numeric fields and check for negative values
        if (isset($stockRecord['quantity_present'])) {
            if (!is_numeric($stockRecord['quantity_present'])) {
                throw new Exception('quantity_present must be numeric');
            }
            if ($stockRecord['quantity_present'] < 0) {
                throw new Exception('quantity_present cannot be negative');
            }
        }
        
        if (isset($stockRecord['quantity_reserved'])) {
            if (!is_numeric($stockRecord['quantity_reserved'])) {
                throw new Exception('quantity_reserved must be numeric');
            }
            if ($stockRecord['quantity_reserved'] < 0) {
                throw new Exception('quantity_reserved cannot be negative');
            }
        }
        
        // Validate that reserved quantity doesn't exceed present quantity
        $present = (int) ($stockRecord['quantity_present'] ?? 0);
        $reserved = (int) ($stockRecord['quantity_reserved'] ?? 0);
        if ($reserved > $present) {
            throw new Exception("Reserved quantity ({$reserved}) cannot exceed present quantity ({$present})");
        }
        
        // Validate source enum
        $validSources = ['Ozon', 'Wildberries'];
        if (!in_array($stockRecord['source'], $validSources)) {
            throw new Exception('Invalid source: ' . $stockRecord['source']);
        }
        
        // Validate warehouse name format
        if (strlen($stockRecord['warehouse_name']) > 255) {
            throw new Exception('Warehouse name too long (max 255 characters)');
        }
        
        // Validate date fields if present
        if (isset($stockRecord['last_updated']) && !$this->validateDateFormat($stockRecord['last_updated'])) {
            throw new Exception('Invalid date format for last_updated');
        }
    }
    
    /**
     * Validate that product exists in the product catalog
     * 
     * @param int $productId - Product ID to validate
     * @return bool True if product exists
     */
    private function validateProductExists(int $productId): bool {
        static $productCache = [];
        
        // Use cache to avoid repeated database queries
        if (isset($productCache[$productId])) {
            return $productCache[$productId];
        }
        
        try {
            $sql = "SELECT 1 FROM dim_products WHERE id = :product_id LIMIT 1";
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute(['product_id' => $productId]);
            
            $exists = $stmt->fetch() !== false;
            $productCache[$productId] = $exists;
            
            return $exists;
            
        } catch (PDOException $e) {
            $this->logger->warning('Failed to validate product existence', [
                'product_id' => $productId,
                'error' => $e->getMessage()
            ]);
            
            // Assume product exists if we can't check (fail open)
            return true;
        }
    }
    
    /**
     * Validate date format
     * 
     * @param string $date - Date string to validate
     * @return bool True if date is valid
     */
    private function validateDateFormat(string $date): bool {
        $formats = [
            'Y-m-d H:i:s',
            'Y-m-d',
            'Y-m-d\TH:i:s',
            'Y-m-d\TH:i:s\Z'
        ];
        
        foreach ($formats as $format) {
            $dateTime = DateTime::createFromFormat($format, $date);
            if ($dateTime && $dateTime->format($format) === $date) {
                return true;
            }
        }
        
        return false;
    }
    
    /**
     * Generate data quality reconciliation report
     * 
     * @param string $reportCode - Report code to analyze
     * @return array Reconciliation report data
     */
    public function generateReconciliationReport(?string $reportCode = null): array {
        try {
            $report = [
                'generated_at' => date('Y-m-d H:i:s'),
                'report_code' => $reportCode,
                'summary' => [],
                'issues' => [],
                'recommendations' => []
            ];
            
            // Get basic inventory statistics
            $report['summary'] = $this->getInventoryUpdateStats();
            
            // Check for data quality issues
            $report['issues'] = $this->detectDataQualityIssues($reportCode);
            
            // Generate recommendations based on issues found
            $report['recommendations'] = $this->generateRecommendations($report['issues']);
            
            $this->logger->info('Generated reconciliation report', [
                'report_code' => $reportCode,
                'issues_found' => count($report['issues']),
                'recommendations' => count($report['recommendations'])
            ]);
            
            return $report;
            
        } catch (Exception $e) {
            $this->logger->error('Failed to generate reconciliation report', [
                'report_code' => $reportCode,
                'error' => $e->getMessage()
            ]);
            
            throw new Exception('Failed to generate reconciliation report: ' . $e->getMessage());
        }
    }
    
    /**
     * Detect data quality issues in inventory data
     * 
     * @param string $reportCode - Report code to analyze
     * @return array List of detected issues
     */
    private function detectDataQualityIssues(?string $reportCode = null): array {
        $issues = [];
        
        try {
            // Check for products with zero stock across all warehouses
            $sql = "
                SELECT 
                    p.id as product_id,
                    p.sku,
                    COUNT(i.id) as warehouse_count,
                    SUM(i.quantity_present) as total_stock
                FROM dim_products p
                LEFT JOIN inventory i ON p.id = i.product_id
                WHERE i.source = 'Ozon'
                " . ($reportCode ? "AND i.report_code = :report_code" : "") . "
                GROUP BY p.id, p.sku
                HAVING total_stock = 0 OR total_stock IS NULL
                ORDER BY p.sku
                LIMIT 100
            ";
            
            $params = $reportCode ? ['report_code' => $reportCode] : [];
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($params);
            $zeroStockProducts = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            if (!empty($zeroStockProducts)) {
                $issues[] = [
                    'type' => 'zero_stock_products',
                    'severity' => 'warning',
                    'count' => count($zeroStockProducts),
                    'description' => 'Products with zero stock across all warehouses',
                    'data' => array_slice($zeroStockProducts, 0, 10) // Limit to first 10 for report
                ];
            }
            
            // Check for products with inconsistent stock data
            $sql = "
                SELECT 
                    product_id,
                    warehouse_name,
                    quantity_present,
                    quantity_reserved,
                    (quantity_present - quantity_reserved) as available
                FROM inventory
                WHERE source = 'Ozon'
                " . ($reportCode ? "AND report_code = :report_code" : "") . "
                AND quantity_reserved > quantity_present
                ORDER BY product_id, warehouse_name
                LIMIT 100
            ";
            
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($params);
            $inconsistentStock = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            if (!empty($inconsistentStock)) {
                $issues[] = [
                    'type' => 'inconsistent_stock',
                    'severity' => 'error',
                    'count' => count($inconsistentStock),
                    'description' => 'Records where reserved quantity exceeds present quantity',
                    'data' => array_slice($inconsistentStock, 0, 10)
                ];
            }
            
            // Check for duplicate records
            $sql = "
                SELECT 
                    product_id,
                    warehouse_name,
                    source,
                    COUNT(*) as duplicate_count
                FROM inventory
                WHERE source = 'Ozon'
                " . ($reportCode ? "AND report_code = :report_code" : "") . "
                GROUP BY product_id, warehouse_name, source
                HAVING COUNT(*) > 1
                ORDER BY duplicate_count DESC
                LIMIT 50
            ";
            
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($params);
            $duplicates = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            if (!empty($duplicates)) {
                $issues[] = [
                    'type' => 'duplicate_records',
                    'severity' => 'error',
                    'count' => count($duplicates),
                    'description' => 'Duplicate inventory records for same product/warehouse/source',
                    'data' => array_slice($duplicates, 0, 10)
                ];
            }
            
            // Check for stale data (not updated in last 48 hours)
            $sql = "
                SELECT 
                    COUNT(*) as stale_count,
                    MIN(updated_at) as oldest_update,
                    MAX(updated_at) as newest_update
                FROM inventory
                WHERE source = 'Ozon'
                AND report_source = 'API_REPORTS'
                AND updated_at < NOW() - INTERVAL '48 hours'
            ";
            
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute();
            $staleData = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($staleData['stale_count'] > 0) {
                $issues[] = [
                    'type' => 'stale_data',
                    'severity' => 'warning',
                    'count' => $staleData['stale_count'],
                    'description' => 'Records not updated in the last 48 hours',
                    'data' => $staleData
                ];
            }
            
        } catch (PDOException $e) {
            $this->logger->error('Failed to detect data quality issues', [
                'error' => $e->getMessage()
            ]);
            
            $issues[] = [
                'type' => 'detection_error',
                'severity' => 'error',
                'count' => 1,
                'description' => 'Failed to run data quality checks',
                'data' => ['error' => $e->getMessage()]
            ];
        }
        
        return $issues;
    }
    
    /**
     * Generate recommendations based on detected issues
     * 
     * @param array $issues - List of detected issues
     * @return array List of recommendations
     */
    private function generateRecommendations(array $issues): array {
        $recommendations = [];
        
        foreach ($issues as $issue) {
            switch ($issue['type']) {
                case 'zero_stock_products':
                    $recommendations[] = [
                        'type' => 'inventory_replenishment',
                        'priority' => 'medium',
                        'description' => 'Review products with zero stock for potential replenishment',
                        'action' => 'Check sales history and demand forecasts for affected products'
                    ];
                    break;
                    
                case 'inconsistent_stock':
                    $recommendations[] = [
                        'type' => 'data_correction',
                        'priority' => 'high',
                        'description' => 'Fix records where reserved > present quantity',
                        'action' => 'Investigate and correct stock allocation logic'
                    ];
                    break;
                    
                case 'duplicate_records':
                    $recommendations[] = [
                        'type' => 'data_cleanup',
                        'priority' => 'high',
                        'description' => 'Remove duplicate inventory records',
                        'action' => 'Run deduplication process and fix unique constraints'
                    ];
                    break;
                    
                case 'stale_data':
                    $recommendations[] = [
                        'type' => 'etl_monitoring',
                        'priority' => 'medium',
                        'description' => 'Investigate why some records are not being updated',
                        'action' => 'Check ETL process logs and API connectivity'
                    ];
                    break;
            }
        }
        
        return $recommendations;
    }
    
    /**
     * Prepare inventory data for database insertion
     * 
     * @param array $stockRecord - Raw stock record
     * @param string $reportCode - Report code for tracking
     * @return array Prepared data for database
     */
    private function prepareInventoryData(array $stockRecord, ?string $reportCode = null): array {
        return [
            'product_id' => (int) $stockRecord['product_id'],
            'warehouse_name' => trim($stockRecord['warehouse_name']),
            'source' => $stockRecord['source'],
            'quantity_present' => max(0, (int) ($stockRecord['quantity_present'] ?? 0)),
            'quantity_reserved' => max(0, (int) ($stockRecord['quantity_reserved'] ?? 0)),
            'stock_type' => $stockRecord['stock_type'] ?? 'fbo',
            'report_source' => 'API_REPORTS',
            'last_report_update' => date('Y-m-d H:i:s'),
            'report_code' => $reportCode,
            'updated_at' => date('Y-m-d H:i:s')
        ];
    }
    
    /**
     * Mark stale records that haven't been updated recently
     * 
     * @param string $source - Data source to check
     * @param DateTime $cutoffTime - Records older than this will be marked stale
     * @return int Number of marked records
     */
    public function markStaleRecords(string $source, DateTime $cutoffTime): int {
        try {
            $sql = "
                UPDATE inventory 
                SET 
                    quantity_present = 0,
                    quantity_reserved = 0,
                    updated_at = NOW()
                WHERE 
                    source = :source 
                    AND report_source = 'API_REPORTS'
                    AND (last_report_update IS NULL OR last_report_update < :cutoff_time)
                    AND (quantity_present > 0 OR quantity_reserved > 0)
            ";
            
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute([
                'source' => $source,
                'cutoff_time' => $cutoffTime->format('Y-m-d H:i:s')
            ]);
            
            $markedCount = $stmt->rowCount();
            
            $this->logger->info('Marked stale inventory records', [
                'source' => $source,
                'cutoff_time' => $cutoffTime->format('Y-m-d H:i:s'),
                'marked_count' => $markedCount
            ]);
            
            return $markedCount;
            
        } catch (PDOException $e) {
            $this->logger->error('Failed to mark stale records', [
                'source' => $source,
                'cutoff_time' => $cutoffTime->format('Y-m-d H:i:s'),
                'error' => $e->getMessage()
            ]);
            
            throw new Exception('Failed to mark stale records: ' . $e->getMessage());
        }
    }
    
    /**
     * Get inventory update statistics
     * 
     * @return array Update statistics
     */
    public function getInventoryUpdateStats(): array {
        try {
            $sql = "
                SELECT 
                    COUNT(*) as total_records,
                    COUNT(CASE WHEN DATE(updated_at) = CURRENT_DATE THEN 1 END) as updated_today,
                    COUNT(CASE WHEN report_source = 'API_REPORTS' THEN 1 END) as from_reports,
                    COUNT(CASE WHEN report_source = 'API_DIRECT' THEN 1 END) as from_direct_api,
                    MAX(updated_at) as last_update,
                    MAX(last_report_update) as last_report_update,
                    COUNT(DISTINCT warehouse_name) as unique_warehouses,
                    COUNT(DISTINCT product_id) as unique_products
                FROM inventory
            ";
            
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute();
            $stats = $stmt->fetch(PDO::FETCH_ASSOC);
            
            // Get source breakdown
            $sourceSql = "
                SELECT 
                    source,
                    COUNT(*) as count,
                    SUM(quantity_present) as total_stock
                FROM inventory 
                GROUP BY source
            ";
            
            $stmt = $this->pdo->prepare($sourceSql);
            $stmt->execute();
            $sourceStats = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            $stats['by_source'] = [];
            foreach ($sourceStats as $sourceStat) {
                $stats['by_source'][$sourceStat['source']] = [
                    'count' => (int) $sourceStat['count'],
                    'total_stock' => (int) $sourceStat['total_stock']
                ];
            }
            
            // Convert numeric strings to integers
            foreach (['total_records', 'updated_today', 'from_reports', 'from_direct_api', 'unique_warehouses', 'unique_products'] as $field) {
                $stats[$field] = (int) $stats[$field];
            }
            
            return $stats;
            
        } catch (PDOException $e) {
            $this->logger->error('Failed to get inventory stats', [
                'error' => $e->getMessage()
            ]);
            
            return [
                'total_records' => 0,
                'updated_today' => 0,
                'last_update' => null,
                'error' => $e->getMessage()
            ];
        }
    }
    
    /**
     * Get current processing statistics
     * 
     * @return array Current processing stats
     */
    public function getCurrentStats(): array {
        return $this->stats;
    }
    
    /**
     * Set batch size for processing
     * 
     * @param int $batchSize - New batch size
     */
    public function setBatchSize(int $batchSize): void {
        $this->batchSize = max(1, $batchSize);
    }
    
    /**
     * Set progress callback for long-running operations
     * 
     * @param callable $callback - Callback function to report progress
     */
    public function setProgressCallback(callable $callback): void {
        $this->progressCallback = $callback;
    }
    
    /**
     * Check memory usage and adjust batch size if needed
     * 
     * @param int $currentBatch - Current batch number
     * @param int $totalBatches - Total number of batches
     */
    private function checkMemoryUsage(int $currentBatch, int $totalBatches): void {
        $memoryUsage = memory_get_usage(true);
        $memoryPercent = ($memoryUsage / $this->memoryLimit) * 100;
        
        if ($memoryUsage > $this->memoryThreshold) {
            $this->logger->warning('High memory usage detected', [
                'memory_usage_mb' => round($memoryUsage / 1024 / 1024, 2),
                'memory_limit_mb' => round($this->memoryLimit / 1024 / 1024, 2),
                'memory_percent' => round($memoryPercent, 2),
                'current_batch' => $currentBatch,
                'total_batches' => $totalBatches
            ]);
            
            // Force garbage collection
            gc_collect_cycles();
            
            // Reduce batch size for remaining batches if memory is still high
            $newMemoryUsage = memory_get_usage(true);
            if ($newMemoryUsage > $this->memoryThreshold && $this->batchSize > 100) {
                $oldBatchSize = $this->batchSize;
                $this->batchSize = max(100, intval($this->batchSize * 0.7));
                
                $this->logger->info('Reduced batch size due to memory pressure', [
                    'old_batch_size' => $oldBatchSize,
                    'new_batch_size' => $this->batchSize,
                    'memory_usage_mb' => round($newMemoryUsage / 1024 / 1024, 2)
                ]);
            }
        }
    }
    
    /**
     * Get current memory usage information
     * 
     * @return array Memory usage statistics
     */
    private function getMemoryUsage(): array {
        $current = memory_get_usage(true);
        $peak = memory_get_peak_usage(true);
        
        return [
            'current_mb' => round($current / 1024 / 1024, 2),
            'peak_mb' => round($peak / 1024 / 1024, 2),
            'limit_mb' => round($this->memoryLimit / 1024 / 1024, 2),
            'usage_percent' => round(($current / $this->memoryLimit) * 100, 2)
        ];
    }
    
    /**
     * Parse memory limit string to bytes
     * 
     * @param string $memoryLimit - Memory limit string (e.g., "128M", "1G")
     * @return int Memory limit in bytes
     */
    private function parseMemoryLimit(string $memoryLimit): int {
        $memoryLimit = trim($memoryLimit);
        $last = strtolower($memoryLimit[strlen($memoryLimit) - 1]);
        $value = (int) $memoryLimit;
        
        switch ($last) {
            case 'g':
                $value *= 1024;
            case 'm':
                $value *= 1024;
            case 'k':
                $value *= 1024;
        }
        
        return $value;
    }
    
    /**
     * Process large datasets with streaming to minimize memory usage
     * 
     * @param string $csvFilePath - Path to CSV file
     * @param string $reportCode - Report code for tracking
     * @return array Processing result
     */
    public function processLargeCSVFile(string $csvFilePath, ?string $reportCode = null): array {
        $this->resetStats();
        
        if (!file_exists($csvFilePath)) {
            throw new Exception("CSV file not found: {$csvFilePath}");
        }
        
        $this->logger->info('Starting large CSV file processing', [
            'file_path' => $csvFilePath,
            'file_size_mb' => round(filesize($csvFilePath) / 1024 / 1024, 2),
            'report_code' => $reportCode,
            'batch_size' => $this->batchSize
        ]);
        
        try {
            $this->pdo->beginTransaction();
            
            $handle = fopen($csvFilePath, 'r');
            if (!$handle) {
                throw new Exception("Cannot open CSV file: {$csvFilePath}");
            }
            
            // Read header row
            $headers = fgetcsv($handle);
            if (!$headers) {
                throw new Exception("Invalid CSV file: no headers found");
            }
            
            $batch = [];
            $lineNumber = 1; // Start from 1 (header is line 0)
            
            while (($row = fgetcsv($handle)) !== false) {
                $lineNumber++;
                
                // Convert row to associative array
                if (count($row) === count($headers)) {
                    $record = array_combine($headers, $row);
                    $batch[] = $record;
                    
                    // Process batch when it reaches the batch size
                    if (count($batch) >= $this->batchSize) {
                        $this->processBatch($batch, $reportCode);
                        $batch = []; // Clear batch
                        
                        // Check memory and report progress
                        $this->checkMemoryUsage($lineNumber, 0);
                        
                        if ($this->progressCallback) {
                            call_user_func($this->progressCallback, [
                                'processed_records' => $this->stats['processed_count'],
                                'current_line' => $lineNumber,
                                'memory_usage' => $this->getMemoryUsage(),
                                'elapsed_time' => microtime(true) - $this->stats['start_time']
                            ]);
                        }
                    }
                } else {
                    $this->logger->warning('Skipping malformed CSV row', [
                        'line_number' => $lineNumber,
                        'expected_columns' => count($headers),
                        'actual_columns' => count($row)
                    ]);
                    $this->stats['skipped_count']++;
                }
            }
            
            // Process remaining records in the last batch
            if (!empty($batch)) {
                $this->processBatch($batch, $reportCode);
            }
            
            fclose($handle);
            $this->pdo->commit();
            
            $this->stats['duration'] = microtime(true) - $this->stats['start_time'];
            $this->stats['success'] = true;
            
            $this->logger->info('Large CSV file processing completed', $this->stats);
            
        } catch (Exception $e) {
            if (isset($handle) && $handle) {
                fclose($handle);
            }
            
            $this->pdo->rollback();
            $this->stats['success'] = false;
            $this->stats['error'] = $e->getMessage();
            
            $this->logger->error('Large CSV file processing failed', [
                'error' => $e->getMessage(),
                'stats' => $this->stats
            ]);
            
            throw $e;
        }
        
        return $this->stats;
    }
}