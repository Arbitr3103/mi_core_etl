#!/usr/bin/env php
<?php
/**
 * Warehouse Analytics API ETL Script
 * 
 * Comprehensive ETL script for Ozon Analytics API (/v2/analytics/stock_on_warehouses)
 * Implements: Pagination → Validation → Normalization → Database Loading
 * 
 * Usage:
 *   php warehouse_etl_analytics.php [options]
 * 
 * Options:
 *   --help                Show this help message
 *   --dry-run            Run without making database changes
 *   --debug              Enable debug logging
 *   --limit=N            Limit number of records to process
 *   --batch-size=N       Set batch size for pagination (default: 1000)
 *   --force              Force run even if recent sync exists
 *   --warehouse=NAME     Process only specific warehouse
 *   --validate-only      Only run validation, don't load data
 *   --cleanup-logs       Clean up old log files before running
 * 
 * Requirements: 2.1, 6.1, 7.4
 */

// Set error reporting and timezone
error_reporting(E_ALL);
ini_set('display_errors', 1);
date_default_timezone_set('Europe/Moscow');

// Include configuration
require_once __DIR__ . '/config.php';

/**
 * Analytics ETL Main Class
 */
class WarehouseAnalyticsETL
{
    private PDO $pdo;
    private array $config;
    private array $options;
    private string $batchId;
    private string $logFile;
    private $logHandle;
    private int $totalProcessed = 0;
    private int $totalInserted = 0;
    private int $totalUpdated = 0;
    private int $totalErrors = 0;
    private float $startTime;
    
    // API Configuration
    private string $clientId;
    private string $apiKey;
    private string $baseUrl = 'https://api-seller.ozon.ru';
    private float $lastRequestTime = 0;
    private int $requestDelay = 100000; // 0.1 seconds in microseconds
    
    // ETL Configuration
    private int $batchSize = 1000;
    private int $maxRetries = 3;
    private int $retryDelay = 2; // seconds
    
    public function __construct(array $options = [])
    {
        $this->options = $options;
        $this->startTime = microtime(true);
        $this->batchId = 'analytics_etl_' . date('Ymd_His') . '_' . substr(md5(uniqid()), 0, 8);
        
        $this->initializeConfiguration();
        $this->initializeDatabase();
        $this->initializeLogging();
        $this->validateEnvironment();
        
        $this->log('INFO', 'Analytics ETL initialized', [
            'batch_id' => $this->batchId,
            'options' => $this->options,
            'php_version' => PHP_VERSION,
            'memory_limit' => ini_get('memory_limit')
        ]);
    }
    
    /**
     * Initialize configuration from environment and options
     */
    private function initializeConfiguration(): void
    {
        // Load API credentials
        $this->clientId = $_ENV['OZON_CLIENT_ID'] ?? '';
        $this->apiKey = $_ENV['OZON_API_KEY'] ?? '';
        
        if (empty($this->clientId) || empty($this->apiKey)) {
            throw new Exception('OZON_CLIENT_ID and OZON_API_KEY must be set in environment');
        }
        
        // Set batch size from options
        if (!empty($this->options['batch-size'])) {
            $this->batchSize = max(100, min(1000, (int)$this->options['batch-size']));
        }
        
        $this->config = [
            'client_id' => $this->clientId,
            'api_key' => $this->apiKey,
            'base_url' => $this->baseUrl,
            'batch_size' => $this->batchSize,
            'max_retries' => $this->maxRetries,
            'retry_delay' => $this->retryDelay,
            'request_delay' => $this->requestDelay,
            'dry_run' => !empty($this->options['dry-run']),
            'debug' => !empty($this->options['debug']),
            'force' => !empty($this->options['force']),
            'validate_only' => !empty($this->options['validate-only'])
        ];
    }
    
    /**
     * Initialize database connection
     */
    private function initializeDatabase(): void
    {
        try {
            $this->pdo = getDatabaseConnection();
            $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            
            // Test connection
            $this->pdo->query('SELECT 1');
            
        } catch (Exception $e) {
            throw new Exception('Database connection failed: ' . $e->getMessage());
        }
    }
    
    /**
     * Initialize logging system
     */
    private function initializeLogging(): void
    {
        // Ensure logs directory exists
        $logsDir = __DIR__ . '/logs/analytics_etl';
        if (!is_dir($logsDir)) {
            mkdir($logsDir, 0755, true);
        }
        
        // Clean up old logs if requested
        if (!empty($this->options['cleanup-logs'])) {
            $this->cleanupOldLogs($logsDir);
        }
        
        // Create log file
        $this->logFile = $logsDir . '/etl_' . date('Y-m-d') . '.log';
        $this->logHandle = fopen($this->logFile, 'a');
        
        if (!$this->logHandle) {
            throw new Exception('Cannot create log file: ' . $this->logFile);
        }
        
        // Set log file permissions
        chmod($this->logFile, 0644);
    }
    
    /**
     * Validate environment and prerequisites
     */
    private function validateEnvironment(): void
    {
        // Check required extensions
        $requiredExtensions = ['pdo', 'pdo_pgsql', 'curl', 'json', 'mbstring'];
        foreach ($requiredExtensions as $ext) {
            if (!extension_loaded($ext)) {
                throw new Exception("Required PHP extension not loaded: $ext");
            }
        }
        
        // Check database schema
        $this->validateDatabaseSchema();
        
        // Check API connectivity
        if (!$this->config['dry_run']) {
            $this->validateApiConnectivity();
        }
    }
    
    /**
     * Validate database schema exists
     */
    private function validateDatabaseSchema(): void
    {
        $requiredTables = [
            'inventory',
            'analytics_etl_log',
            'warehouse_normalization'
        ];
        
        foreach ($requiredTables as $table) {
            $stmt = $this->pdo->prepare("
                SELECT EXISTS (
                    SELECT FROM information_schema.tables 
                    WHERE table_schema = 'public' 
                    AND table_name = ?
                )
            ");
            $stmt->execute([$table]);
            
            if (!$stmt->fetchColumn()) {
                throw new Exception("Required table '$table' does not exist. Please run migrations first.");
            }
        }
        
        $this->log('INFO', 'Database schema validation passed');
    }
    
    /**
     * Validate API connectivity
     */
    private function validateApiConnectivity(): void
    {
        try {
            $response = $this->makeApiRequest('POST', '/v2/analytics/stock_on_warehouses', [
                'date_from' => date('Y-m-d', strtotime('-1 day')),
                'date_to' => date('Y-m-d'),
                'metrics' => ['revenue', 'ordered_units'],
                'dimension' => ['sku', 'warehouse'],
                'filters' => [],
                'sort' => [['key' => 'revenue', 'order' => 'DESC']],
                'limit' => 1,
                'offset' => 0
            ]);
            
            if (!isset($response['result'])) {
                throw new Exception('Invalid API response structure');
            }
            
            $this->log('INFO', 'API connectivity validation passed');
            
        } catch (Exception $e) {
            throw new Exception('API connectivity validation failed: ' . $e->getMessage());
        }
    }
    
    /**
     * Main ETL execution method
     */
    public function run(): array
    {
        $this->log('INFO', 'Starting Analytics ETL process');
        
        try {
            // Check if recent sync exists and force is not set
            if (!$this->config['force'] && $this->hasRecentSync()) {
                $this->log('WARNING', 'Recent sync found, skipping. Use --force to override.');
                return $this->buildResult('skipped', 'Recent sync found');
            }
            
            // Create ETL log entry
            $etlLogId = $this->createETLLogEntry();
            
            // Phase 1: Extract data from Analytics API
            $this->log('INFO', 'Phase 1: Extracting data from Analytics API');
            $extractedData = $this->extractAnalyticsData();
            
            // Phase 2: Validate extracted data
            $this->log('INFO', 'Phase 2: Validating extracted data');
            $validationResults = $this->validateExtractedData($extractedData);
            
            if ($this->config['validate_only']) {
                $this->log('INFO', 'Validation-only mode, stopping here');
                $this->updateETLLogEntry($etlLogId, 'completed', $validationResults);
                return $this->buildResult('validated', 'Validation completed', $validationResults);
            }
            
            // Phase 3: Normalize and transform data
            $this->log('INFO', 'Phase 3: Normalizing and transforming data');
            $normalizedData = $this->normalizeData($extractedData);
            
            // Phase 4: Load data into database
            if (!$this->config['dry_run']) {
                $this->log('INFO', 'Phase 4: Loading data into database');
                $loadResults = $this->loadDataToDatabase($normalizedData);
            } else {
                $this->log('INFO', 'Phase 4: Dry run mode - skipping database load');
                $loadResults = ['dry_run' => true, 'would_process' => count($normalizedData)];
            }
            
            // Update ETL log with final results
            $finalResults = array_merge($validationResults, $loadResults);
            $this->updateETLLogEntry($etlLogId, 'completed', $finalResults);
            
            $this->log('INFO', 'Analytics ETL process completed successfully', $finalResults);
            
            return $this->buildResult('success', 'ETL completed successfully', $finalResults);
            
        } catch (Exception $e) {
            $this->log('ERROR', 'ETL process failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            
            if (isset($etlLogId)) {
                $this->updateETLLogEntry($etlLogId, 'failed', ['error' => $e->getMessage()]);
            }
            
            throw $e;
        }
    }
    
    /**
     * Extract data from Analytics API with pagination
     */
    protected function extractAnalyticsData(): array
    {
        $allData = [];
        $offset = 0;
        $hasMore = true;
        $pageCount = 0;
        $maxLimit = !empty($this->options['limit']) ? (int)$this->options['limit'] : PHP_INT_MAX;
        
        // Date range - last 7 days by default
        $dateFrom = date('Y-m-d', strtotime('-7 days'));
        $dateTo = date('Y-m-d');
        
        $this->log('INFO', 'Starting data extraction', [
            'date_from' => $dateFrom,
            'date_to' => $dateTo,
            'batch_size' => $this->batchSize,
            'max_limit' => $maxLimit
        ]);
        
        while ($hasMore && count($allData) < $maxLimit) {
            $pageCount++;
            $currentBatchSize = min($this->batchSize, $maxLimit - count($allData));
            
            $this->log('INFO', "Extracting page $pageCount", [
                'offset' => $offset,
                'limit' => $currentBatchSize,
                'total_so_far' => count($allData)
            ]);
            
            $requestData = [
                'date_from' => $dateFrom,
                'date_to' => $dateTo,
                'metrics' => [
                    'revenue',
                    'ordered_units',
                    'returns',
                    'cancellations'
                ],
                'dimension' => ['sku', 'warehouse'],
                'filters' => $this->buildApiFilters(),
                'sort' => [['key' => 'revenue', 'order' => 'DESC']],
                'limit' => $currentBatchSize,
                'offset' => $offset
            ];
            
            // Add warehouse filter if specified
            if (!empty($this->options['warehouse'])) {
                $requestData['filters'][] = [
                    'key' => 'warehouse',
                    'op' => 'EQ',
                    'value' => $this->options['warehouse']
                ];
            }
            
            try {
                $response = $this->makeApiRequest('POST', '/v2/analytics/stock_on_warehouses', $requestData);
                
                if (!isset($response['result']['data'])) {
                    $this->log('WARNING', 'No data in API response', ['response' => $response]);
                    break;
                }
                
                $pageData = $response['result']['data'];
                $allData = array_merge($allData, $pageData);
                
                // Check if we have more data
                $hasMore = count($pageData) === $currentBatchSize;
                $offset += $currentBatchSize;
                
                $this->log('INFO', "Page $pageCount extracted", [
                    'records_in_page' => count($pageData),
                    'total_records' => count($allData),
                    'has_more' => $hasMore
                ]);
                
                // Rate limiting
                $this->enforceRateLimit();
                
            } catch (Exception $e) {
                $this->log('ERROR', "Failed to extract page $pageCount", [
                    'error' => $e->getMessage(),
                    'offset' => $offset
                ]);
                
                // Retry logic
                $retryCount = 0;
                while ($retryCount < $this->maxRetries) {
                    $retryCount++;
                    $this->log('INFO', "Retrying page $pageCount (attempt $retryCount)");
                    
                    sleep($this->retryDelay * $retryCount);
                    
                    try {
                        $response = $this->makeApiRequest('POST', '/v2/analytics/stock_on_warehouses', $requestData);
                        
                        if (isset($response['result']['data'])) {
                            $pageData = $response['result']['data'];
                            $allData = array_merge($allData, $pageData);
                            $hasMore = count($pageData) === $currentBatchSize;
                            $offset += $currentBatchSize;
                            
                            $this->log('INFO', "Page $pageCount extracted on retry $retryCount");
                            break;
                        }
                        
                    } catch (Exception $retryError) {
                        $this->log('WARNING', "Retry $retryCount failed", [
                            'error' => $retryError->getMessage()
                        ]);
                        
                        if ($retryCount >= $this->maxRetries) {
                            throw new Exception("Failed to extract page $pageCount after $this->maxRetries retries: " . $e->getMessage());
                        }
                    }
                }
            }
        }
        
        $this->log('INFO', 'Data extraction completed', [
            'total_records' => count($allData),
            'pages_processed' => $pageCount,
            'date_range' => "$dateFrom to $dateTo"
        ]);
        
        return $allData;
    }
    
    /**
     * Build API filters based on options
     */
    private function buildApiFilters(): array
    {
        $filters = [];
        
        // Add any additional filters here based on requirements
        // For now, we'll keep it simple and let the API return all data
        
        return $filters;
    }
    
    /**
     * Validate extracted data
     */
    protected function validateExtractedData(array $data): array
    {
        $this->log('INFO', 'Starting data validation', ['record_count' => count($data)]);
        
        $validationResults = [
            'total_records' => count($data),
            'valid_records' => 0,
            'invalid_records' => 0,
            'validation_errors' => [],
            'data_quality_score' => 0,
            'warehouse_coverage' => [],
            'sku_coverage' => []
        ];
        
        $warehouseCounts = [];
        $skuCounts = [];
        
        foreach ($data as $index => $record) {
            $recordErrors = [];
            
            // Validate required fields
            $requiredFields = ['sku', 'warehouse', 'revenue', 'ordered_units'];
            foreach ($requiredFields as $field) {
                if (!isset($record['dimensions'][$field]) && !isset($record['metrics'][$field])) {
                    $recordErrors[] = "Missing required field: $field";
                }
            }
            
            // Validate data types and ranges
            if (isset($record['metrics']['revenue'])) {
                $revenue = $record['metrics']['revenue'];
                if (!is_numeric($revenue) || $revenue < 0) {
                    $recordErrors[] = "Invalid revenue value: $revenue";
                }
            }
            
            if (isset($record['metrics']['ordered_units'])) {
                $units = $record['metrics']['ordered_units'];
                if (!is_numeric($units) || $units < 0) {
                    $recordErrors[] = "Invalid ordered_units value: $units";
                }
            }
            
            // Validate warehouse name
            if (isset($record['dimensions']['warehouse'])) {
                $warehouse = trim($record['dimensions']['warehouse']);
                if (empty($warehouse)) {
                    $recordErrors[] = "Empty warehouse name";
                } else {
                    $warehouseCounts[$warehouse] = ($warehouseCounts[$warehouse] ?? 0) + 1;
                }
            }
            
            // Validate SKU
            if (isset($record['dimensions']['sku'])) {
                $sku = trim($record['dimensions']['sku']);
                if (empty($sku)) {
                    $recordErrors[] = "Empty SKU";
                } else {
                    $skuCounts[$sku] = ($skuCounts[$sku] ?? 0) + 1;
                }
            }
            
            if (empty($recordErrors)) {
                $validationResults['valid_records']++;
            } else {
                $validationResults['invalid_records']++;
                $validationResults['validation_errors'][] = [
                    'record_index' => $index,
                    'errors' => $recordErrors,
                    'record_sample' => array_slice($record, 0, 3) // First 3 fields for debugging
                ];
                
                // Limit error details to prevent memory issues
                if (count($validationResults['validation_errors']) > 100) {
                    $validationResults['validation_errors'][] = [
                        'message' => 'Too many validation errors, truncating...'
                    ];
                    break;
                }
            }
        }
        
        // Calculate data quality score
        $validationResults['data_quality_score'] = $validationResults['total_records'] > 0 
            ? round(($validationResults['valid_records'] / $validationResults['total_records']) * 100, 2)
            : 0;
        
        // Warehouse and SKU coverage
        $validationResults['warehouse_coverage'] = [
            'unique_warehouses' => count($warehouseCounts),
            'top_warehouses' => array_slice(arsort($warehouseCounts) ? $warehouseCounts : [], 0, 10, true)
        ];
        
        $validationResults['sku_coverage'] = [
            'unique_skus' => count($skuCounts),
            'top_skus' => array_slice(arsort($skuCounts) ? $skuCounts : [], 0, 10, true)
        ];
        
        $this->log('INFO', 'Data validation completed', [
            'quality_score' => $validationResults['data_quality_score'],
            'valid_records' => $validationResults['valid_records'],
            'invalid_records' => $validationResults['invalid_records'],
            'unique_warehouses' => $validationResults['warehouse_coverage']['unique_warehouses'],
            'unique_skus' => $validationResults['sku_coverage']['unique_skus']
        ]);
        
        return $validationResults;
    }
    
    /**
     * Normalize and transform data
     */
    protected function normalizeData(array $data): array
    {
        $this->log('INFO', 'Starting data normalization', ['record_count' => count($data)]);
        
        $normalizedData = [];
        $warehouseNormalizer = $this->getWarehouseNormalizer();
        
        foreach ($data as $record) {
            try {
                // Extract dimensions and metrics
                $dimensions = $record['dimensions'] ?? [];
                $metrics = $record['metrics'] ?? [];
                
                // Skip invalid records
                if (empty($dimensions['sku']) || empty($dimensions['warehouse'])) {
                    continue;
                }
                
                // Normalize warehouse name
                $originalWarehouse = trim($dimensions['warehouse']);
                $normalizedWarehouse = $warehouseNormalizer->normalize($originalWarehouse, 'api');
                
                // Create normalized record
                $normalizedRecord = [
                    'sku' => trim($dimensions['sku']),
                    'original_warehouse_name' => $originalWarehouse,
                    'normalized_warehouse_name' => $normalizedWarehouse,
                    'available' => max(0, (int)($metrics['ordered_units'] ?? 0)),
                    'reserved' => 0, // Analytics API doesn't provide reserved stock
                    'preparing_for_sale' => 0,
                    'in_transit' => 0,
                    'revenue' => max(0, (float)($metrics['revenue'] ?? 0)),
                    'returns' => max(0, (int)($metrics['returns'] ?? 0)),
                    'cancellations' => max(0, (int)($metrics['cancellations'] ?? 0)),
                    'data_source' => 'api',
                    'data_quality_score' => 100, // API data gets highest quality score
                    'last_analytics_sync' => date('Y-m-d H:i:s'),
                    'sync_batch_id' => $this->batchId,
                    'raw_data' => json_encode($record, JSON_UNESCAPED_UNICODE)
                ];
                
                $normalizedData[] = $normalizedRecord;
                
            } catch (Exception $e) {
                $this->log('WARNING', 'Failed to normalize record', [
                    'error' => $e->getMessage(),
                    'record_sample' => array_slice($record, 0, 3)
                ]);
                $this->totalErrors++;
            }
        }
        
        $this->log('INFO', 'Data normalization completed', [
            'input_records' => count($data),
            'output_records' => count($normalizedData),
            'normalization_errors' => $this->totalErrors
        ]);
        
        return $normalizedData;
    }
    
    /**
     * Load normalized data into database
     */
    protected function loadDataToDatabase(array $data): array
    {
        $this->log('INFO', 'Starting database load', ['record_count' => count($data)]);
        
        $loadResults = [
            'total_processed' => 0,
            'inserted' => 0,
            'updated' => 0,
            'errors' => 0,
            'error_details' => []
        ];
        
        $this->pdo->beginTransaction();
        
        try {
            // Prepare statements
            $insertStmt = $this->pdo->prepare("
                INSERT INTO inventory (
                    sku, warehouse_name, available, reserved, preparing_for_sale, in_transit,
                    data_source, data_quality_score, last_analytics_sync, 
                    normalized_warehouse_name, original_warehouse_name, sync_batch_id,
                    created_at, updated_at
                ) VALUES (
                    :sku, :warehouse_name, :available, :reserved, :preparing_for_sale, :in_transit,
                    :data_source, :data_quality_score, :last_analytics_sync,
                    :normalized_warehouse_name, :original_warehouse_name, :sync_batch_id,
                    NOW(), NOW()
                )
                ON CONFLICT (sku, warehouse_name) 
                DO UPDATE SET
                    available = EXCLUDED.available,
                    reserved = EXCLUDED.reserved,
                    preparing_for_sale = EXCLUDED.preparing_for_sale,
                    in_transit = EXCLUDED.in_transit,
                    data_source = EXCLUDED.data_source,
                    data_quality_score = EXCLUDED.data_quality_score,
                    last_analytics_sync = EXCLUDED.last_analytics_sync,
                    normalized_warehouse_name = EXCLUDED.normalized_warehouse_name,
                    original_warehouse_name = EXCLUDED.original_warehouse_name,
                    sync_batch_id = EXCLUDED.sync_batch_id,
                    updated_at = NOW()
            ");
            
            // Process records in batches
            $batchSize = 100;
            $batches = array_chunk($data, $batchSize);
            
            foreach ($batches as $batchIndex => $batch) {
                $this->log('INFO', "Processing batch " . ($batchIndex + 1) . "/" . count($batches), [
                    'batch_size' => count($batch)
                ]);
                
                foreach ($batch as $record) {
                    try {
                        $insertStmt->execute([
                            'sku' => $record['sku'],
                            'warehouse_name' => $record['normalized_warehouse_name'],
                            'available' => $record['available'],
                            'reserved' => $record['reserved'],
                            'preparing_for_sale' => $record['preparing_for_sale'],
                            'in_transit' => $record['in_transit'],
                            'data_source' => $record['data_source'],
                            'data_quality_score' => $record['data_quality_score'],
                            'last_analytics_sync' => $record['last_analytics_sync'],
                            'normalized_warehouse_name' => $record['normalized_warehouse_name'],
                            'original_warehouse_name' => $record['original_warehouse_name'],
                            'sync_batch_id' => $record['sync_batch_id']
                        ]);
                        
                        $loadResults['total_processed']++;
                        
                        // Check if it was an insert or update
                        // PostgreSQL doesn't have a direct way to check this with ON CONFLICT
                        // We'll count all as updates for simplicity
                        $loadResults['updated']++;
                        
                    } catch (Exception $e) {
                        $loadResults['errors']++;
                        $loadResults['error_details'][] = [
                            'sku' => $record['sku'] ?? 'unknown',
                            'warehouse' => $record['normalized_warehouse_name'] ?? 'unknown',
                            'error' => $e->getMessage()
                        ];
                        
                        $this->log('WARNING', 'Failed to load record', [
                            'sku' => $record['sku'] ?? 'unknown',
                            'warehouse' => $record['normalized_warehouse_name'] ?? 'unknown',
                            'error' => $e->getMessage()
                        ]);
                        
                        // Limit error details
                        if (count($loadResults['error_details']) > 50) {
                            $loadResults['error_details'][] = [
                                'message' => 'Too many errors, truncating...'
                            ];
                            break 2;
                        }
                    }
                }
            }
            
            $this->pdo->commit();
            
            $this->log('INFO', 'Database load completed', $loadResults);
            
        } catch (Exception $e) {
            $this->pdo->rollBack();
            $this->log('ERROR', 'Database load failed, transaction rolled back', [
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
        
        return $loadResults;
    }
    
    /**
     * Get warehouse normalizer instance
     */
    private function getWarehouseNormalizer(): object
    {
        return new class($this->pdo, $this) {
            private PDO $pdo;
            private object $etl;
            private array $cache = [];
            
            public function __construct(PDO $pdo, object $etl)
            {
                $this->pdo = $pdo;
                $this->etl = $etl;
                $this->loadNormalizationCache();
            }
            
            public function normalize(string $originalName, string $sourceType): string
            {
                $cleanName = trim(mb_strtoupper($originalName));
                
                // Check cache first
                $cacheKey = $sourceType . ':' . $cleanName;
                if (isset($this->cache[$cacheKey])) {
                    return $this->cache[$cacheKey];
                }
                
                // Apply normalization rules
                $normalized = $this->applyNormalizationRules($cleanName);
                
                // Save to database and cache
                $this->saveNormalizationRule($cleanName, $normalized, $sourceType);
                $this->cache[$cacheKey] = $normalized;
                
                return $normalized;
            }
            
            private function loadNormalizationCache(): void
            {
                try {
                    $stmt = $this->pdo->query("
                        SELECT original_name, normalized_name, source_type 
                        FROM warehouse_normalization 
                        WHERE is_active = true
                    ");
                    
                    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                        $cacheKey = $row['source_type'] . ':' . $row['original_name'];
                        $this->cache[$cacheKey] = $row['normalized_name'];
                    }
                    
                } catch (Exception $e) {
                    $this->etl->log('WARNING', 'Failed to load normalization cache', [
                        'error' => $e->getMessage()
                    ]);
                }
            }
            
            private function applyNormalizationRules(string $name): string
            {
                // Standard normalization rules
                $rules = [
                    '/\s+/u' => '_',                    // Replace spaces with underscores (Unicode)
                    '/[^\p{L}\p{N}\-_]/u' => '',       // Remove special characters, keep letters, numbers, hyphens, underscores (Unicode)
                    '/_+/' => '_',                      // Collapse multiple underscores
                    '/^_|_$/' => '',                    // Remove leading/trailing underscores
                ];
                
                foreach ($rules as $pattern => $replacement) {
                    $name = preg_replace($pattern, $replacement, $name);
                }
                
                // Handle specific Russian warehouse patterns
                if (preg_match('/РФЦ$/u', $name) && !preg_match('/_РФЦ$/u', $name)) {
                    $name = preg_replace('/РФЦ$/u', '_РФЦ', $name);
                }
                
                if (preg_match('/МРФЦ$/u', $name) && !preg_match('/_МРФЦ$/u', $name)) {
                    $name = preg_replace('/МРФЦ$/u', '_МРФЦ', $name);
                }
                
                return $name;
            }
            
            private function saveNormalizationRule(string $original, string $normalized, string $sourceType): void
            {
                try {
                    $stmt = $this->pdo->prepare("
                        INSERT INTO warehouse_normalization 
                        (original_name, normalized_name, source_type, confidence_score, created_at, updated_at)
                        VALUES (?, ?, ?, 1.0, NOW(), NOW())
                        ON CONFLICT (original_name, source_type) 
                        DO UPDATE SET 
                            normalized_name = EXCLUDED.normalized_name,
                            updated_at = NOW()
                    ");
                    
                    $stmt->execute([$original, $normalized, $sourceType]);
                    
                } catch (Exception $e) {
                    $this->etl->log('WARNING', 'Failed to save normalization rule', [
                        'original' => $original,
                        'normalized' => $normalized,
                        'source_type' => $sourceType,
                        'error' => $e->getMessage()
                    ]);
                }
            }
        };
    }
    
    /**
     * Make API request with error handling and rate limiting
     */
    private function makeApiRequest(string $method, string $endpoint, array $data = []): array
    {
        $this->enforceRateLimit();
        
        $url = $this->baseUrl . $endpoint;
        
        $headers = [
            'Content-Type: application/json',
            'Client-Id: ' . $this->clientId,
            'Api-Key: ' . $this->apiKey
        ];
        
        $ch = curl_init();
        
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 60,
            CURLOPT_CONNECTTIMEOUT => 30,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_USERAGENT => 'WarehouseETL/1.0'
        ]);
        
        if ($method === 'POST') {
            curl_setopt($ch, CURLOPT_POST, true);
            if (!empty($data)) {
                curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data, JSON_UNESCAPED_UNICODE));
            }
        }
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        
        curl_close($ch);
        
        if ($response === false) {
            throw new Exception('cURL error: ' . $curlError);
        }
        
        $this->handleHttpErrors($httpCode, $response);
        
        $decodedResponse = json_decode($response, true);
        
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new Exception('JSON decode error: ' . json_last_error_msg());
        }
        
        return $decodedResponse;
    }
    
    /**
     * Handle HTTP errors
     */
    private function handleHttpErrors(int $httpCode, string $response): void
    {
        switch ($httpCode) {
            case 200:
            case 201:
                return;
                
            case 401:
                throw new Exception('API authentication failed - check credentials');
                
            case 429:
                throw new Exception('API rate limit exceeded - will retry with backoff');
                
            case 400:
                $errorData = json_decode($response, true);
                $errorMessage = $errorData['message'] ?? 'Bad request';
                throw new Exception('API error: ' . $errorMessage);
                
            case 503:
                throw new Exception('API temporarily unavailable');
                
            default:
                throw new Exception('API HTTP error: ' . $httpCode . ' - ' . substr($response, 0, 200));
        }
    }
    
    /**
     * Enforce rate limiting between API requests
     */
    private function enforceRateLimit(): void
    {
        $currentTime = microtime(true);
        $timeSinceLastRequest = $currentTime - $this->lastRequestTime;
        
        $minDelay = $this->requestDelay / 1000000; // Convert microseconds to seconds
        
        if ($timeSinceLastRequest < $minDelay) {
            $sleepTime = $minDelay - $timeSinceLastRequest;
            usleep($sleepTime * 1000000);
        }
        
        $this->lastRequestTime = microtime(true);
    }
    
    /**
     * Check if recent sync exists
     */
    protected function hasRecentSync(): bool
    {
        try {
            $stmt = $this->pdo->prepare("
                SELECT COUNT(*) 
                FROM analytics_etl_log 
                WHERE status = 'completed' 
                AND created_at > NOW() - INTERVAL '2 hours'
            ");
            $stmt->execute();
            
            return $stmt->fetchColumn() > 0;
            
        } catch (Exception $e) {
            $this->log('WARNING', 'Failed to check recent sync', ['error' => $e->getMessage()]);
            return false;
        }
    }
    
    /**
     * Create ETL log entry
     */
    protected function createETLLogEntry(): int
    {
        try {
            $stmt = $this->pdo->prepare("
                INSERT INTO analytics_etl_log 
                (batch_id, status, started_at, configuration)
                VALUES (?, 'running', NOW(), ?)
            ");
            
            $stmt->execute([
                $this->batchId,
                json_encode($this->config, JSON_UNESCAPED_UNICODE)
            ]);
            
            return $this->pdo->lastInsertId();
            
        } catch (Exception $e) {
            $this->log('WARNING', 'Failed to create ETL log entry', ['error' => $e->getMessage()]);
            return 0;
        }
    }
    
    /**
     * Update ETL log entry
     */
    protected function updateETLLogEntry(int $logId, string $status, array $results = []): void
    {
        if ($logId === 0) {
            return;
        }
        
        try {
            $stmt = $this->pdo->prepare("
                UPDATE analytics_etl_log 
                SET status = ?, 
                    completed_at = NOW(),
                    records_processed = ?,
                    records_inserted = ?,
                    records_updated = ?,
                    records_failed = ?,
                    execution_time = EXTRACT(EPOCH FROM (NOW() - started_at)),
                    results = ?
                WHERE id = ?
            ");
            
            $stmt->execute([
                $status,
                $results['total_processed'] ?? $this->totalProcessed,
                $results['inserted'] ?? $this->totalInserted,
                $results['updated'] ?? $this->totalUpdated,
                $results['errors'] ?? $this->totalErrors,
                json_encode($results, JSON_UNESCAPED_UNICODE),
                $logId
            ]);
            
        } catch (Exception $e) {
            $this->log('WARNING', 'Failed to update ETL log entry', [
                'log_id' => $logId,
                'error' => $e->getMessage()
            ]);
        }
    }
    
    /**
     * Build result array
     */
    private function buildResult(string $status, string $message, array $data = []): array
    {
        $executionTime = microtime(true) - $this->startTime;
        
        return [
            'status' => $status,
            'message' => $message,
            'batch_id' => $this->batchId,
            'execution_time' => round($executionTime, 2),
            'memory_usage' => memory_get_peak_usage(true),
            'timestamp' => date('Y-m-d H:i:s'),
            'data' => $data
        ];
    }
    
    /**
     * Clean up old log files
     */
    private function cleanupOldLogs(string $logsDir): void
    {
        try {
            $files = glob($logsDir . '/etl_*.log');
            $cutoffTime = time() - (30 * 24 * 60 * 60); // 30 days
            
            foreach ($files as $file) {
                if (filemtime($file) < $cutoffTime) {
                    unlink($file);
                    $this->log('INFO', 'Cleaned up old log file', ['file' => basename($file)]);
                }
            }
            
        } catch (Exception $e) {
            $this->log('WARNING', 'Failed to cleanup old logs', ['error' => $e->getMessage()]);
        }
    }
    
    /**
     * Log message to file and console
     */
    public function log(string $level, string $message, array $context = []): void
    {
        $timestamp = date('Y-m-d H:i:s');
        $contextStr = !empty($context) ? ' | ' . json_encode($context, JSON_UNESCAPED_UNICODE) : '';
        $logLine = "[$timestamp] [$level] [ETL] $message$contextStr\n";
        
        // Write to log file
        if ($this->logHandle) {
            fwrite($this->logHandle, $logLine);
            fflush($this->logHandle);
        }
        
        // Write to console
        if (php_sapi_name() === 'cli') {
            $colorCode = match($level) {
                'ERROR' => "\033[31m",   // Red
                'WARNING' => "\033[33m", // Yellow
                'INFO' => "\033[32m",    // Green
                'DEBUG' => "\033[36m",   // Cyan
                default => "\033[0m"     // Default
            };
            
            echo $colorCode . $logLine . "\033[0m";
        }
        
        // Also log errors to PHP error log
        if ($level === 'ERROR') {
            error_log($logLine);
        }
    }
    
    /**
     * Get batch ID for external access
     */
    public function getBatchId(): string
    {
        return $this->batchId;
    }
    
    /**
     * Destructor - cleanup resources
     */
    public function __destruct()
    {
        if ($this->logHandle) {
            fclose($this->logHandle);
        }
    }
}

/**
 * Command line interface
 */
function showHelp(): void
{
    echo "Warehouse Analytics API ETL Script\n";
    echo "==================================\n\n";
    echo "Usage: php warehouse_etl_analytics.php [options]\n\n";
    echo "Options:\n";
    echo "  --help                Show this help message\n";
    echo "  --dry-run            Run without making database changes\n";
    echo "  --debug              Enable debug logging\n";
    echo "  --limit=N            Limit number of records to process\n";
    echo "  --batch-size=N       Set batch size for pagination (default: 1000)\n";
    echo "  --force              Force run even if recent sync exists\n";
    echo "  --warehouse=NAME     Process only specific warehouse\n";
    echo "  --validate-only      Only run validation, don't load data\n";
    echo "  --cleanup-logs       Clean up old log files before running\n\n";
    echo "Examples:\n";
    echo "  php warehouse_etl_analytics.php --dry-run --debug\n";
    echo "  php warehouse_etl_analytics.php --limit=100 --warehouse='РФЦ_МОСКВА'\n";
    echo "  php warehouse_etl_analytics.php --validate-only --cleanup-logs\n\n";
}

function parseCommandLineOptions(): array
{
    $options = [];
    $args = $_SERVER['argv'] ?? [];
    
    for ($i = 1; $i < count($args); $i++) {
        $arg = $args[$i];
        
        if ($arg === '--help') {
            $options['help'] = true;
        } elseif ($arg === '--dry-run') {
            $options['dry-run'] = true;
        } elseif ($arg === '--debug') {
            $options['debug'] = true;
        } elseif ($arg === '--force') {
            $options['force'] = true;
        } elseif ($arg === '--validate-only') {
            $options['validate-only'] = true;
        } elseif ($arg === '--cleanup-logs') {
            $options['cleanup-logs'] = true;
        } elseif (strpos($arg, '--limit=') === 0) {
            $options['limit'] = (int)substr($arg, 8);
        } elseif (strpos($arg, '--batch-size=') === 0) {
            $options['batch-size'] = (int)substr($arg, 13);
        } elseif (strpos($arg, '--warehouse=') === 0) {
            $options['warehouse'] = substr($arg, 12);
        }
    }
    
    return $options;
}

// Main execution
if (php_sapi_name() === 'cli') {
    try {
        $options = parseCommandLineOptions();
        
        if (!empty($options['help'])) {
            showHelp();
            exit(0);
        }
        
        echo "🚀 Starting Warehouse Analytics ETL - " . date('Y-m-d H:i:s') . "\n";
        echo "Configuration: " . json_encode($options, JSON_UNESCAPED_UNICODE) . "\n\n";
        
        $etl = new WarehouseAnalyticsETL($options);
        $result = $etl->run();
        
        echo "\n✅ ETL Process completed successfully!\n";
        echo "Status: " . $result['status'] . "\n";
        echo "Message: " . $result['message'] . "\n";
        echo "Execution time: " . $result['execution_time'] . " seconds\n";
        echo "Memory usage: " . round($result['memory_usage'] / 1024 / 1024, 2) . " MB\n";
        echo "Batch ID: " . $result['batch_id'] . "\n";
        
        if (!empty($result['data'])) {
            echo "\nResults summary:\n";
            foreach ($result['data'] as $key => $value) {
                if (is_array($value)) {
                    echo "  $key: " . json_encode($value, JSON_UNESCAPED_UNICODE) . "\n";
                } else {
                    echo "  $key: $value\n";
                }
            }
        }
        
        exit(0);
        
    } catch (Exception $e) {
        echo "\n❌ ETL Process failed!\n";
        echo "Error: " . $e->getMessage() . "\n";
        
        if (!empty($options['debug'])) {
            echo "Stack trace:\n" . $e->getTraceAsString() . "\n";
        }
        
        exit(1);
    }
} else {
    // Web interface (basic)
    header('Content-Type: application/json');
    
    try {
        $options = $_GET;
        $etl = new WarehouseAnalyticsETL($options);
        $result = $etl->run();
        
        echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode([
            'status' => 'error',
            'message' => $e->getMessage(),
            'timestamp' => date('Y-m-d H:i:s')
        ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    }
}