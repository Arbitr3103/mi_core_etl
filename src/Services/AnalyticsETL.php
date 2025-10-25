<?php
/**
 * AnalyticsETL - Основной оркестратор ETL процесса
 * 
 * Реализует полный ETL процесс: Extract (Analytics API) → Transform → Load (PostgreSQL)
 * с логикой обновления существующих записей, batch processing и comprehensive audit logging.
 * 
 * Requirements: 2.1, 2.2, 2.3
 * Task: 4.4 Создать AnalyticsETL сервис (основной оркестратор)
 * 
 * @version 1.0
 * @author Warehouse Multi-Source Integration System
 */

require_once __DIR__ . '/AnalyticsApiClient.php';
require_once __DIR__ . '/DataValidator.php';
require_once __DIR__ . '/WarehouseNormalizer.php';
require_once __DIR__ . '/AlertManager.php';

class AnalyticsETL {
    // ETL process statuses
    const STATUS_NOT_STARTED = 'not_started';
    const STATUS_RUNNING = 'running';
    const STATUS_COMPLETED = 'completed';
    const STATUS_FAILED = 'failed';
    const STATUS_PARTIAL_SUCCESS = 'partial_success';
    
    // ETL process types
    const TYPE_FULL_SYNC = 'full_sync';
    const TYPE_INCREMENTAL_SYNC = 'incremental_sync';
    const TYPE_MANUAL_SYNC = 'manual_sync';
    const TYPE_VALIDATION_ONLY = 'validation_only';
    
    // Batch processing settings
    const DEFAULT_BATCH_SIZE = 1000;
    const MAX_BATCH_SIZE = 5000;
    const MAX_RETRIES = 3;
    const RETRY_DELAY = 5; // seconds
    
    private AnalyticsApiClient $apiClient;
    private DataValidator $validator;
    private WarehouseNormalizer $normalizer;
    private ?PDO $pdo;
    private array $config;
    private string $currentBatchId;
    private array $metrics;
    private ?AlertManager $alertManager = null;
    
    /**
     * Constructor
     * 
     * @param AnalyticsApiClient $apiClient Analytics API client
     * @param DataValidator $validator Data validator service
     * @param WarehouseNormalizer $normalizer Warehouse normalizer service
     * @param PDO|null $pdo Database connection
     * @param array $config ETL configuration
     */
    public function __construct(
        AnalyticsApiClient $apiClient,
        DataValidator $validator,
        WarehouseNormalizer $normalizer,
        ?PDO $pdo = null,
        array $config = []
    ) {
        $this->apiClient = $apiClient;
        $this->validator = $validator;
        $this->normalizer = $normalizer;
        $this->pdo = $pdo;
        $this->config = array_merge($this->getDefaultConfig(), $config);
        $this->metrics = $this->initializeMetrics();
        
        if ($this->pdo) {
            $this->createETLTablesIfNotExist();
        }
        
        // Initialize AlertManager
        $this->initializeAlertManager();
    }    
   
 /**
     * Execute full ETL process: Extract → Transform → Load
     * 
     * @param string $etlType Type of ETL process
     * @param array $options ETL options
     * @return ETLResult ETL execution result
     */
    public function executeETL(string $etlType = self::TYPE_INCREMENTAL_SYNC, array $options = []): ETLResult {
        $this->currentBatchId = $this->generateBatchId($etlType);
        $startTime = microtime(true);
        
        try {
            // Initialize ETL process
            $this->initializeETLProcess($etlType, $options);
            
            // Phase 1: Extract - Get data from Analytics API
            $extractedData = $this->extractPhase($options);
            
            // Phase 2: Transform - Validate and normalize data
            $transformedData = $this->transformPhase($extractedData);
            
            // Phase 3: Load - Save to PostgreSQL database
            $loadResult = $this->loadPhase($transformedData);
            
            // Finalize ETL process
            $executionTime = round((microtime(true) - $startTime) * 1000);
            $result = $this->finalizeETLProcess($loadResult, $executionTime);
            
            return $result;
            
        } catch (Exception $e) {
            $executionTime = round((microtime(true) - $startTime) * 1000);
            return $this->handleETLError($e, $executionTime);
        }
    }
    
    /**
     * Extract phase: Get data from Analytics API
     * 
     * @param array $options Extract options
     * @return array Extracted data
     */
    private function extractPhase(array $options): array {
        $this->logPhase('EXTRACT', 'Starting data extraction from Analytics API');
        
        $extractedData = [];
        $totalRecords = 0;
        $batchCount = 0;
        
        try {
            // Use generator for memory-efficient processing
            foreach ($this->apiClient->getAllStockData($options['filters'] ?? []) as $batch) {
                $batchCount++;
                $batchSize = count($batch['data']);
                $totalRecords += $batchSize;
                
                $this->logPhase('EXTRACT', "Processing batch {$batchCount}: {$batchSize} records");
                
                // Add batch metadata
                foreach ($batch['data'] as &$record) {
                    $record['extract_batch_id'] = $this->currentBatchId;
                    $record['extract_timestamp'] = date('Y-m-d H:i:s');
                    $record['extract_batch_number'] = $batchCount;
                }
                
                $extractedData = array_merge($extractedData, $batch['data']);
                
                // Update metrics
                $this->metrics['extract']['batches_processed']++;
                $this->metrics['extract']['records_extracted'] += $batchSize;
                
                // Memory management for large datasets
                if (count($extractedData) >= $this->config['max_memory_records']) {
                    $this->logPhase('EXTRACT', 'Memory limit reached, processing current batch');
                    break;
                }
            }
            
            $this->metrics['extract']['total_records'] = $totalRecords;
            $this->metrics['extract']['status'] = 'completed';
            
            $this->logPhase('EXTRACT', "Extraction completed: {$totalRecords} records in {$batchCount} batches");
            
            return $extractedData;
            
        } catch (Exception $e) {
            $this->metrics['extract']['status'] = 'failed';
            $this->metrics['extract']['error'] = $e->getMessage();
            
            $this->logPhase('EXTRACT', 'Extraction failed: ' . $e->getMessage(), 'ERROR');
            throw new ETLException("Extract phase failed: " . $e->getMessage(), 0, $e);
        }
    }
    
    /**
     * Transform phase: Validate and normalize data
     * 
     * @param array $extractedData Raw extracted data
     * @return array Transformed data
     */
    private function transformPhase(array $extractedData): array {
        $this->logPhase('TRANSFORM', 'Starting data transformation and validation');
        
        if (empty($extractedData)) {
            $this->logPhase('TRANSFORM', 'No data to transform');
            return [];
        }
        
        try {
            // Step 1: Data Validation
            $validationResult = $this->validator->validateBatch($extractedData, $this->currentBatchId);
            
            $this->metrics['transform']['validation_quality_score'] = $validationResult->getQualityScore();
            $this->metrics['transform']['validation_warnings'] = $validationResult->getResults()['warnings'];
            $this->metrics['transform']['validation_errors'] = $validationResult->getResults()['invalid_records'];
            
            $this->logPhase('TRANSFORM', 
                "Validation completed: Quality score {$validationResult->getQualityScore()}%, " .
                "{$validationResult->getResults()['warnings']} warnings, " .
                "{$validationResult->getResults()['invalid_records']} errors"
            );
            
            // Check if data quality is acceptable
            if ($validationResult->getQualityScore() < $this->config['min_quality_score']) {
                throw new ETLException(
                    "Data quality too low: {$validationResult->getQualityScore()}% " .
                    "(minimum required: {$this->config['min_quality_score']}%)"
                );
            }
            
            // Step 2: Warehouse Name Normalization
            $warehouseNames = array_column($extractedData, 'warehouse_name');
            $normalizationResults = $this->normalizer->normalizeBatch($warehouseNames, WarehouseNormalizer::SOURCE_API);
            
            // Apply normalization results
            $transformedData = [];
            foreach ($extractedData as $index => $record) {
                $normResult = $normalizationResults[$index];
                
                $record['original_warehouse_name'] = $record['warehouse_name'];
                $record['normalized_warehouse_name'] = $normResult->getNormalizedName();
                $record['normalization_confidence'] = $normResult->getConfidence();
                $record['normalization_match_type'] = $normResult->getMatchType();
                
                // Add transform metadata
                $record['transform_timestamp'] = date('Y-m-d H:i:s');
                $record['transform_batch_id'] = $this->currentBatchId;
                
                $transformedData[] = $record;
            }
            
            // Update metrics
            $this->metrics['transform']['records_transformed'] = count($transformedData);
            $this->metrics['transform']['normalization_high_confidence'] = count(array_filter(
                $normalizationResults, 
                fn($result) => $result->isHighConfidence()
            ));
            $this->metrics['transform']['status'] = 'completed';
            
            $this->logPhase('TRANSFORM', 
                "Transformation completed: {$this->metrics['transform']['records_transformed']} records transformed, " .
                "{$this->metrics['transform']['normalization_high_confidence']} high-confidence normalizations"
            );
            
            return $transformedData;
            
        } catch (Exception $e) {
            $this->metrics['transform']['status'] = 'failed';
            $this->metrics['transform']['error'] = $e->getMessage();
            
            $this->logPhase('TRANSFORM', 'Transformation failed: ' . $e->getMessage(), 'ERROR');
            throw new ETLException("Transform phase failed: " . $e->getMessage(), 0, $e);
        }
    }
    
    /**
     * Load phase: Save data to PostgreSQL database
     * 
     * @param array $transformedData Transformed data
     * @return array Load result statistics
     */
    private function loadPhase(array $transformedData): array {
        $this->logPhase('LOAD', 'Starting data load to PostgreSQL database');
        
        if (empty($transformedData)) {
            $this->logPhase('LOAD', 'No data to load');
            return ['inserted' => 0, 'updated' => 0, 'errors' => 0];
        }
        
        if (!$this->pdo) {
            throw new ETLException("Database connection required for load phase");
        }
        
        try {
            $this->pdo->beginTransaction();
            
            $loadStats = [
                'inserted' => 0,
                'updated' => 0,
                'errors' => 0,
                'batches_processed' => 0
            ];
            
            // Process data in batches for efficiency
            $batches = array_chunk($transformedData, $this->config['load_batch_size']);
            
            foreach ($batches as $batchIndex => $batch) {
                $this->logPhase('LOAD', "Processing load batch " . ($batchIndex + 1) . "/" . count($batches));
                
                $batchStats = $this->loadBatch($batch);
                
                $loadStats['inserted'] += $batchStats['inserted'];
                $loadStats['updated'] += $batchStats['updated'];
                $loadStats['errors'] += $batchStats['errors'];
                $loadStats['batches_processed']++;
                
                // Update metrics
                $this->metrics['load']['batches_processed']++;
            }
            
            $this->pdo->commit();
            
            // Update final metrics
            $this->metrics['load']['records_inserted'] = $loadStats['inserted'];
            $this->metrics['load']['records_updated'] = $loadStats['updated'];
            $this->metrics['load']['records_errors'] = $loadStats['errors'];
            $this->metrics['load']['status'] = 'completed';
            
            $this->logPhase('LOAD', 
                "Load completed: {$loadStats['inserted']} inserted, " .
                "{$loadStats['updated']} updated, {$loadStats['errors']} errors"
            );
            
            return $loadStats;
            
        } catch (Exception $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            
            $this->metrics['load']['status'] = 'failed';
            $this->metrics['load']['error'] = $e->getMessage();
            
            $this->logPhase('LOAD', 'Load failed: ' . $e->getMessage(), 'ERROR');
            throw new ETLException("Load phase failed: " . $e->getMessage(), 0, $e);
        }
    }
    
    /**
     * Load a batch of records to database
     * 
     * @param array $batch Batch of records
     * @return array Batch load statistics
     */
    private function loadBatch(array $batch): array {
        $stats = ['inserted' => 0, 'updated' => 0, 'errors' => 0];
        
        // Prepare UPSERT statement for inventory table
        $sql = "
            INSERT INTO inventory (
                sku, warehouse_name, stock_type, quantity_present, quantity_reserved,
                source, data_source, data_quality_score, last_analytics_sync,
                normalized_warehouse_name, original_warehouse_name, sync_batch_id,
                product_name, category, brand, price, currency, updated_at
            ) VALUES (
                :sku, :warehouse_name, :stock_type, :quantity_present, :quantity_reserved,
                :source, :data_source, :data_quality_score, :last_analytics_sync,
                :normalized_warehouse_name, :original_warehouse_name, :sync_batch_id,
                :product_name, :category, :brand, :price, :currency, :updated_at
            )
            ON CONFLICT (sku, warehouse_name, source) 
            DO UPDATE SET
                quantity_present = EXCLUDED.quantity_present,
                quantity_reserved = EXCLUDED.quantity_reserved,
                data_quality_score = EXCLUDED.data_quality_score,
                last_analytics_sync = EXCLUDED.last_analytics_sync,
                normalized_warehouse_name = EXCLUDED.normalized_warehouse_name,
                sync_batch_id = EXCLUDED.sync_batch_id,
                product_name = EXCLUDED.product_name,
                category = EXCLUDED.category,
                brand = EXCLUDED.brand,
                price = EXCLUDED.price,
                currency = EXCLUDED.currency,
                updated_at = EXCLUDED.updated_at
        ";
        
        $stmt = $this->pdo->prepare($sql);
        
        foreach ($batch as $record) {
            try {
                $params = [
                    'sku' => $record['sku'] ?? '',
                    'warehouse_name' => $record['normalized_warehouse_name'] ?? $record['warehouse_name'] ?? '',
                    'stock_type' => 'available', // Default stock type
                    'quantity_present' => max(0, (int)($record['available_stock'] ?? 0)),
                    'quantity_reserved' => max(0, (int)($record['reserved_stock'] ?? 0)),
                    'source' => 'Ozon',
                    'data_source' => 'api',
                    'data_quality_score' => 100, // API data has highest quality
                    'last_analytics_sync' => date('Y-m-d H:i:s'),
                    'normalized_warehouse_name' => $record['normalized_warehouse_name'] ?? $record['warehouse_name'] ?? '',
                    'original_warehouse_name' => $record['original_warehouse_name'] ?? $record['warehouse_name'] ?? '',
                    'sync_batch_id' => $this->currentBatchId,
                    'product_name' => $record['product_name'] ?? '',
                    'category' => $record['category'] ?? '',
                    'brand' => $record['brand'] ?? '',
                    'price' => max(0, (float)($record['price'] ?? 0)),
                    'currency' => $record['currency'] ?? 'RUB',
                    'updated_at' => date('Y-m-d H:i:s')
                ];
                
                $stmt->execute($params);
                
                // Check if it was an insert or update
                if ($stmt->rowCount() > 0) {
                    // Check if record existed before
                    $checkStmt = $this->pdo->prepare("
                        SELECT COUNT(*) FROM inventory 
                        WHERE sku = ? AND warehouse_name = ? AND source = ?
                    ");
                    $checkStmt->execute([$params['sku'], $params['warehouse_name'], $params['source']]);
                    
                    if ($checkStmt->fetchColumn() > 1) {
                        $stats['updated']++;
                    } else {
                        $stats['inserted']++;
                    }
                }
                
            } catch (Exception $e) {
                $stats['errors']++;
                $this->logError("Failed to load record: " . $e->getMessage(), [
                    'sku' => $record['sku'] ?? 'unknown',
                    'warehouse' => $record['warehouse_name'] ?? 'unknown',
                    'error' => $e->getMessage()
                ]);
            }
        }
        
        return $stats;
    }
    
    /**
     * Initialize ETL process
     * 
     * @param string $etlType ETL type
     * @param array $options ETL options
     */
    private function initializeETLProcess(string $etlType, array $options): void {
        $this->logETLStart($etlType, $options);
        
        // Reset metrics
        $this->metrics = $this->initializeMetrics();
        $this->metrics['etl']['batch_id'] = $this->currentBatchId;
        $this->metrics['etl']['type'] = $etlType;
        $this->metrics['etl']['started_at'] = date('Y-m-d H:i:s');
        $this->metrics['etl']['status'] = self::STATUS_RUNNING;
    }
    
    /**
     * Finalize ETL process
     * 
     * @param array $loadResult Load phase results
     * @param int $executionTime Total execution time in milliseconds
     * @return ETLResult Final ETL result
     */
    private function finalizeETLProcess(array $loadResult, int $executionTime): ETLResult {
        $this->metrics['etl']['completed_at'] = date('Y-m-d H:i:s');
        $this->metrics['etl']['execution_time_ms'] = $executionTime;
        $this->metrics['etl']['status'] = self::STATUS_COMPLETED;
        
        // Determine overall status
        $status = self::STATUS_COMPLETED;
        if ($this->metrics['load']['records_errors'] > 0) {
            $status = self::STATUS_PARTIAL_SUCCESS;
        }
        
        $result = new ETLResult(
            $this->currentBatchId,
            $status,
            $this->metrics,
            $executionTime
        );
        
        $this->logETLCompletion($result);
        
        return $result;
    }
    
    /**
     * Handle ETL error
     * 
     * @param Exception $e Exception that occurred
     * @param int $executionTime Execution time before error
     * @return ETLResult Error result
     */
    private function handleETLError(Exception $e, int $executionTime): ETLResult {
        $this->metrics['etl']['completed_at'] = date('Y-m-d H:i:s');
        $this->metrics['etl']['execution_time_ms'] = $executionTime;
        $this->metrics['etl']['status'] = self::STATUS_FAILED;
        $this->metrics['etl']['error'] = $e->getMessage();
        
        $result = new ETLResult(
            $this->currentBatchId,
            self::STATUS_FAILED,
            $this->metrics,
            $executionTime,
            $e->getMessage()
        );
        
        $this->logETLError($e, $result);
        
        // Send ETL failure alert
        $this->sendETLFailureAlert($e->getMessage(), [
            'execution_time_ms' => $executionTime,
            'metrics' => $this->metrics
        ]);
        
        return $result;
    }
    
    /**
     * Get ETL status
     * 
     * @return array Current ETL status
     */
    public function getETLStatus(): array {
        return [
            'current_batch_id' => $this->currentBatchId ?? null,
            'status' => $this->metrics['etl']['status'] ?? self::STATUS_NOT_STARTED,
            'started_at' => $this->metrics['etl']['started_at'] ?? null,
            'completed_at' => $this->metrics['etl']['completed_at'] ?? null,
            'execution_time_ms' => $this->metrics['etl']['execution_time_ms'] ?? null,
            'metrics' => $this->metrics,
            'last_update' => date('Y-m-d H:i:s')
        ];
    }
    
    /**
     * Get ETL statistics
     * 
     * @param int $days Number of days to look back
     * @return array ETL statistics
     */
    public function getETLStatistics(int $days = 7): array {
        if (!$this->pdo) {
            return [];
        }
        
        $stmt = $this->pdo->prepare("
            SELECT 
                COUNT(*) as total_runs,
                SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as successful_runs,
                SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) as failed_runs,
                SUM(CASE WHEN status = 'partial_success' THEN 1 ELSE 0 END) as partial_success_runs,
                AVG(execution_time_ms) as avg_execution_time_ms,
                SUM(records_processed) as total_records_processed,
                AVG(records_processed) as avg_records_per_run,
                MAX(started_at) as last_run_at
            FROM analytics_etl_log 
            WHERE started_at >= DATE_SUB(NOW(), INTERVAL ? DAY)
        ");
        $stmt->execute([$days]);
        
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
    }
    
    /**
     * Generate batch ID
     * 
     * @param string $etlType ETL type
     * @return string Batch ID
     */
    private function generateBatchId(string $etlType): string {
        return 'etl_' . $etlType . '_' . date('Ymd_His') . '_' . substr(md5(uniqid()), 0, 8);
    }
    
    /**
     * Initialize metrics structure
     * 
     * @return array Metrics structure
     */
    private function initializeMetrics(): array {
        return [
            'etl' => [
                'batch_id' => null,
                'type' => null,
                'status' => self::STATUS_NOT_STARTED,
                'started_at' => null,
                'completed_at' => null,
                'execution_time_ms' => null,
                'error' => null
            ],
            'extract' => [
                'status' => 'not_started',
                'batches_processed' => 0,
                'records_extracted' => 0,
                'total_records' => 0,
                'error' => null
            ],
            'transform' => [
                'status' => 'not_started',
                'records_transformed' => 0,
                'validation_quality_score' => null,
                'validation_warnings' => 0,
                'validation_errors' => 0,
                'normalization_high_confidence' => 0,
                'error' => null
            ],
            'load' => [
                'status' => 'not_started',
                'batches_processed' => 0,
                'records_inserted' => 0,
                'records_updated' => 0,
                'records_errors' => 0,
                'error' => null
            ]
        ];
    }
    
    /**
     * Get default configuration
     * 
     * @return array Default configuration
     */
    private function getDefaultConfig(): array {
        return [
            'load_batch_size' => self::DEFAULT_BATCH_SIZE,
            'max_memory_records' => 10000,
            'min_quality_score' => 80.0,
            'enable_audit_logging' => true,
            'retry_failed_batches' => true,
            'max_retries' => self::MAX_RETRIES,
            'retry_delay' => self::RETRY_DELAY
        ];
    }
    
    /**
     * Log ETL phase
     * 
     * @param string $phase Phase name
     * @param string $message Log message
     * @param string $level Log level
     */
    private function logPhase(string $phase, string $message, string $level = 'INFO'): void {
        $logMessage = "[{$this->currentBatchId}] [{$phase}] {$message}";
        
        if ($level === 'ERROR') {
            error_log($logMessage);
        } else {
            error_log($logMessage);
        }
        
        // Also log to database if available
        if ($this->pdo && $this->config['enable_audit_logging']) {
            $this->logToDatabase($phase, $message, $level);
        }
    }
    
    /**
     * Log error with context
     * 
     * @param string $message Error message
     * @param array $context Error context
     */
    private function logError(string $message, array $context = []): void {
        $contextStr = !empty($context) ? ' Context: ' . json_encode($context) : '';
        $this->logPhase('ERROR', $message . $contextStr, 'ERROR');
    }
    
    /**
     * Log ETL start
     * 
     * @param string $etlType ETL type
     * @param array $options ETL options
     */
    private function logETLStart(string $etlType, array $options): void {
        $this->logPhase('ETL', "Starting ETL process: type={$etlType}, batch_id={$this->currentBatchId}");
    }
    
    /**
     * Log ETL completion
     * 
     * @param ETLResult $result ETL result
     */
    private function logETLCompletion(ETLResult $result): void {
        $this->logPhase('ETL', 
            "ETL process completed: status={$result->getStatus()}, " .
            "execution_time={$result->getExecutionTime()}ms, " .
            "records_processed={$result->getTotalRecordsProcessed()}"
        );
    }
    
    /**
     * Log ETL error
     * 
     * @param Exception $e Exception
     * @param ETLResult $result ETL result
     */
    private function logETLError(Exception $e, ETLResult $result): void {
        $this->logPhase('ETL', 
            "ETL process failed: error={$e->getMessage()}, " .
            "execution_time={$result->getExecutionTime()}ms",
            'ERROR'
        );
    }
    
    /**
     * Log to database
     * 
     * @param string $phase Phase name
     * @param string $message Log message
     * @param string $level Log level
     */
    private function logToDatabase(string $phase, string $message, string $level): void {
        try {
            $stmt = $this->pdo->prepare("
                INSERT INTO analytics_etl_log (
                    batch_id, etl_type, status, records_processed, 
                    execution_time_ms, data_source, error_message
                ) VALUES (?, ?, ?, ?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE
                    status = VALUES(status),
                    records_processed = VALUES(records_processed),
                    execution_time_ms = VALUES(execution_time_ms),
                    error_message = VALUES(error_message),
                    completed_at = CURRENT_TIMESTAMP
            ");
            
            $stmt->execute([
                $this->currentBatchId,
                $this->metrics['etl']['type'] ?? 'unknown',
                $this->metrics['etl']['status'],
                $this->metrics['extract']['records_extracted'] + $this->metrics['transform']['records_transformed'],
                $this->metrics['etl']['execution_time_ms'],
                'analytics_api',
                $level === 'ERROR' ? $message : null
            ]);
        } catch (Exception $e) {
            // Don't fail ETL process due to logging errors
            error_log("Failed to log to database: " . $e->getMessage());
        }
    }
    
    /**
     * Create ETL tables if they don't exist
     */
    private function createETLTablesIfNotExist(): void {
        // The analytics_etl_log table should already exist from migration 005
        // But we'll ensure it exists with proper structure
        
        try {
            // This table was created in migration 005, just ensure it exists
            $this->pdo->exec("
                CREATE TABLE IF NOT EXISTS analytics_etl_log (
                    id SERIAL PRIMARY KEY,
                    batch_id VARCHAR(255) NOT NULL,
                    etl_type VARCHAR(50) NOT NULL DEFAULT 'incremental_sync',
                    started_at TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP,
                    completed_at TIMESTAMP WITH TIME ZONE,
                    status VARCHAR(20) NOT NULL DEFAULT 'running',
                    records_processed INTEGER DEFAULT 0,
                    execution_time_ms INTEGER,
                    data_source VARCHAR(50) DEFAULT 'analytics_api',
                    error_message TEXT,
                    
                    CONSTRAINT etl_log_valid_status CHECK (
                        status IN ('running', 'completed', 'failed', 'cancelled', 'partial_success')
                    ),
                    CONSTRAINT etl_log_valid_type CHECK (
                        etl_type IN ('full_sync', 'incremental_sync', 'manual_sync', 'validation_only')
                    )
                )
            ");
            
            // Create indexes if they don't exist
            $this->pdo->exec("CREATE INDEX IF NOT EXISTS idx_analytics_etl_batch_id ON analytics_etl_log(batch_id)");
            $this->pdo->exec("CREATE INDEX IF NOT EXISTS idx_analytics_etl_started_at ON analytics_etl_log(started_at)");
            $this->pdo->exec("CREATE INDEX IF NOT EXISTS idx_analytics_etl_status ON analytics_etl_log(status)");
            
        } catch (Exception $e) {
            error_log("Failed to create ETL tables: " . $e->getMessage());
        }
    }
    
    /**
     * Initialize AlertManager for sending alerts
     */
    private function initializeAlertManager(): void {
        try {
            $alertConfig = [
                'log_file' => $this->config['log_file'] ?? __DIR__ . '/../../logs/analytics_etl/alerts.log',
                'enable_email' => $this->config['enable_alerts'] ?? true,
                'enable_slack' => $this->config['enable_slack_alerts'] ?? false,
                'enable_telegram' => $this->config['enable_telegram_alerts'] ?? false
            ];
            
            $this->alertManager = new AlertManager($alertConfig);
            
        } catch (Exception $e) {
            error_log("Failed to initialize AlertManager: " . $e->getMessage());
            // Continue without AlertManager - ETL will still work
        }
    }
    
    /**
     * Send ETL failure alert
     */
    private function sendETLFailureAlert(string $errorMessage, array $context = []): void {
        if ($this->alertManager) {
            try {
                $this->alertManager->sendETLFailureAlert(
                    $this->currentBatchId,
                    $errorMessage,
                    array_merge($context, [
                        'etl_type' => $this->config['etl_type'] ?? 'unknown',
                        'batch_id' => $this->currentBatchId
                    ])
                );
            } catch (Exception $e) {
                error_log("Failed to send ETL failure alert: " . $e->getMessage());
            }
        }
    }
    
    /**
     * Send API failure alert
     */
    private function sendAPIFailureAlert(float $failureRate, int $totalRequests, int $failedRequests): void {
        if ($this->alertManager) {
            try {
                $this->alertManager->sendAPIFailureAlert($failureRate, $totalRequests, $failedRequests);
            } catch (Exception $e) {
                error_log("Failed to send API failure alert: " . $e->getMessage());
            }
        }
    }
    
    /**
     * Send data quality alert
     */
    private function sendDataQualityAlert(float $qualityScore, int $lowQualityRecords, int $totalRecords): void {
        if ($this->alertManager) {
            try {
                $this->alertManager->sendDataQualityAlert($qualityScore, $lowQualityRecords, $totalRecords);
            } catch (Exception $e) {
                error_log("Failed to send data quality alert: " . $e->getMessage());
            }
        }
    }
}

/**
 * ETL Result class
 */
class ETLResult {
    private string $batchId;
    private string $status;
    private array $metrics;
    private int $executionTime;
    private ?string $errorMessage;
    
    public function __construct(string $batchId, string $status, array $metrics, int $executionTime, ?string $errorMessage = null) {
        $this->batchId = $batchId;
        $this->status = $status;
        $this->metrics = $metrics;
        $this->executionTime = $executionTime;
        $this->errorMessage = $errorMessage;
    }
    
    public function getBatchId(): string { return $this->batchId; }
    public function getStatus(): string { return $this->status; }
    public function getMetrics(): array { return $this->metrics; }
    public function getExecutionTime(): int { return $this->executionTime; }
    public function getErrorMessage(): ?string { return $this->errorMessage; }
    
    public function isSuccessful(): bool {
        return in_array($this->status, [AnalyticsETL::STATUS_COMPLETED, AnalyticsETL::STATUS_PARTIAL_SUCCESS]);
    }
    
    public function getTotalRecordsProcessed(): int {
        return $this->metrics['extract']['records_extracted'] ?? 0;
    }
    
    public function getRecordsInserted(): int {
        return $this->metrics['load']['records_inserted'] ?? 0;
    }
    
    public function getRecordsUpdated(): int {
        return $this->metrics['load']['records_updated'] ?? 0;
    }
    
    public function getRecordsErrors(): int {
        return $this->metrics['load']['records_errors'] ?? 0;
    }
    
    public function getQualityScore(): float {
        return $this->metrics['transform']['validation_quality_score'] ?? 0.0;
    }
}

/**
 * ETL Exception class
 */
class ETLException extends Exception {
    private string $phase;
    
    public function __construct(string $message, int $code = 0, ?Throwable $previous = null, string $phase = 'unknown') {
        parent::__construct($message, $code, $previous);
        $this->phase = $phase;
    }
    
    public function getPhase(): string {
        return $this->phase;
    }
}