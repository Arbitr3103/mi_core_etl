<?php
/**
 * AnalyticsETLController - API контроллер для управления Analytics ETL процессами
 * 
 * Предоставляет REST API endpoints для:
 * - Мониторинга статуса ETL процессов
 * - Принудительного запуска ETL синхронизации
 * - Получения метрик качества данных
 * - Просмотра истории ETL выполнений
 * 
 * Requirements: 8.1, 8.2, 8.3, 8.4
 * Task: 5.1 Создать AnalyticsETLController
 * 
 * @version 1.0
 * @author Warehouse Multi-Source Integration System
 */

require_once __DIR__ . '/../../Services/AnalyticsETL.php';
require_once __DIR__ . '/../../Services/AnalyticsApiClient.php';
require_once __DIR__ . '/../../Services/DataValidator.php';
require_once __DIR__ . '/../../Services/WarehouseNormalizer.php';
require_once __DIR__ . '/../../config/database.php';

class AnalyticsETLController {
    private ?PDO $pdo;
    private ?AnalyticsETL $etl;
    private array $config;
    private string $logFile;
    
    /**
     * Constructor
     * 
     * @param array $config Controller configuration
     */
    public function __construct(array $config = []) {
        $this->config = array_merge($this->getDefaultConfig(), $config);
        $this->logFile = $this->config['log_file'];
        
        try {
            $this->initializeDatabase();
            $this->initializeETL();
        } catch (Exception $e) {
            $this->logError("Failed to initialize AnalyticsETLController: " . $e->getMessage());
            $this->pdo = null;
            $this->etl = null;
        }
    }
    
    /**
     * Handle HTTP requests
     * 
     * @param string $method HTTP method
     * @param string $endpoint Endpoint path
     * @param array $params Request parameters
     * @return array API response
     */
    public function handleRequest(string $method, string $endpoint, array $params = []): array {
        try {
            // Set CORS headers
            $this->setCorsHeaders();
            
            // Route request to appropriate handler
            switch ($method) {
                case 'GET':
                    return $this->handleGetRequest($endpoint, $params);
                case 'POST':
                    return $this->handlePostRequest($endpoint, $params);
                default:
                    return $this->errorResponse('Method not allowed', 405);
            }
            
        } catch (Exception $e) {
            $this->logError("Request handling error: " . $e->getMessage());
            return $this->errorResponse('Internal server error: ' . $e->getMessage(), 500);
        }
    }
    
    /**
     * Handle GET requests
     * 
     * @param string $endpoint Endpoint path
     * @param array $params Query parameters
     * @return array API response
     */
    private function handleGetRequest(string $endpoint, array $params): array {
        switch ($endpoint) {
            case '/api/warehouse/analytics-status':
                return $this->getAnalyticsStatus($params);
                
            case '/api/warehouse/data-quality':
                return $this->getDataQualityMetrics($params);
                
            case '/api/warehouse/etl-history':
                return $this->getETLHistory($params);
                
            case '/api/warehouse/etl-monitoring':
                return $this->getETLMonitoring($params);
                
            default:
                return $this->errorResponse('Endpoint not found', 404);
        }
    }
    
    /**
     * Handle POST requests
     * 
     * @param string $endpoint Endpoint path
     * @param array $params Request body parameters
     * @return array API response
     */
    private function handlePostRequest(string $endpoint, array $params): array {
        switch ($endpoint) {
            case '/api/warehouse/trigger-analytics-etl':
                return $this->triggerAnalyticsETL($params);
                
            default:
                return $this->errorResponse('Endpoint not found', 404);
        }
    }
    
    /**
     * GET /api/warehouse/analytics-status
     * Получить текущий статус Analytics ETL процессов
     * 
     * @param array $params Query parameters
     * @return array API response
     */
    public function getAnalyticsStatus(array $params = []): array {
        try {
            if (!$this->etl) {
                return $this->errorResponse('ETL service not available', 503);
            }
            
            // Get current ETL status
            $status = $this->etl->getETLStatus();
            
            // Get additional system information
            $systemInfo = $this->getSystemInfo();
            
            // Get recent activity summary
            $recentActivity = $this->getRecentActivity();
            
            $response = [
                'status' => 'success',
                'data' => [
                    'etl_status' => $status,
                    'system_info' => $systemInfo,
                    'recent_activity' => $recentActivity,
                    'last_updated' => date('Y-m-d H:i:s')
                ]
            ];
            
            $this->logInfo("Analytics status requested successfully");
            return $response;
            
        } catch (Exception $e) {
            $this->logError("Failed to get analytics status: " . $e->getMessage());
            return $this->errorResponse('Failed to get analytics status: ' . $e->getMessage(), 500);
        }
    }
    
    /**
     * POST /api/warehouse/trigger-analytics-etl
     * Принудительно запустить Analytics ETL процесс
     * 
     * @param array $params Request parameters
     * @return array API response
     */
    public function triggerAnalyticsETL(array $params = []): array {
        try {
            if (!$this->etl) {
                return $this->errorResponse('ETL service not available', 503);
            }
            
            // Validate request parameters
            $etlType = $params['etl_type'] ?? AnalyticsETL::TYPE_MANUAL_SYNC;
            $options = $params['options'] ?? [];
            
            // Validate ETL type
            $validTypes = [
                AnalyticsETL::TYPE_FULL_SYNC,
                AnalyticsETL::TYPE_INCREMENTAL_SYNC,
                AnalyticsETL::TYPE_MANUAL_SYNC,
                AnalyticsETL::TYPE_VALIDATION_ONLY
            ];
            
            if (!in_array($etlType, $validTypes)) {
                return $this->errorResponse('Invalid ETL type', 400);
            }
            
            // Check if ETL is already running
            $currentStatus = $this->etl->getETLStatus();
            if ($currentStatus['status'] === AnalyticsETL::STATUS_RUNNING) {
                return $this->errorResponse('ETL process is already running', 409);
            }
            
            $this->logInfo("Triggering ETL process: type={$etlType}");
            
            // Execute ETL process
            $result = $this->etl->executeETL($etlType, $options);
            
            $response = [
                'status' => 'success',
                'data' => [
                    'etl_result' => [
                        'batch_id' => $result->getBatchId(),
                        'status' => $result->getStatus(),
                        'execution_time_ms' => $result->getExecutionTime(),
                        'records_processed' => $result->getTotalRecordsProcessed(),
                        'records_inserted' => $result->getRecordsInserted(),
                        'records_updated' => $result->getRecordsUpdated(),
                        'records_errors' => $result->getRecordsErrors(),
                        'quality_score' => $result->getQualityScore(),
                        'is_successful' => $result->isSuccessful(),
                        'error_message' => $result->getErrorMessage()
                    ],
                    'triggered_at' => date('Y-m-d H:i:s')
                ]
            ];
            
            if ($result->isSuccessful()) {
                $this->logInfo("ETL process completed successfully: batch_id={$result->getBatchId()}");
            } else {
                $this->logError("ETL process failed: {$result->getErrorMessage()}");
            }
            
            return $response;
            
        } catch (Exception $e) {
            $this->logError("Failed to trigger ETL: " . $e->getMessage());
            return $this->errorResponse('Failed to trigger ETL: ' . $e->getMessage(), 500);
        }
    }
    
    /**
     * GET /api/warehouse/data-quality
     * Получить метрики качества данных
     * 
     * @param array $params Query parameters
     * @return array API response
     */
    public function getDataQualityMetrics(array $params = []): array {
        try {
            if (!$this->pdo) {
                return $this->errorResponse('Database not available', 503);
            }
            
            $timeframe = $params['timeframe'] ?? '7d';
            $source = $params['source'] ?? 'all';
            
            // Get quality metrics from database
            $qualityMetrics = $this->calculateQualityMetrics($timeframe, $source);
            
            // Get data freshness metrics
            $freshnessMetrics = $this->calculateFreshnessMetrics($source);
            
            // Get completeness metrics
            $completenessMetrics = $this->calculateCompletenessMetrics($source);
            
            // Get validation statistics
            $validationStats = $this->getValidationStatistics($timeframe);
            
            $response = [
                'status' => 'success',
                'data' => [
                    'quality_metrics' => $qualityMetrics,
                    'freshness_metrics' => $freshnessMetrics,
                    'completeness_metrics' => $completenessMetrics,
                    'validation_statistics' => $validationStats,
                    'timeframe' => $timeframe,
                    'source_filter' => $source,
                    'generated_at' => date('Y-m-d H:i:s')
                ]
            ];
            
            $this->logInfo("Data quality metrics requested: timeframe={$timeframe}, source={$source}");
            return $response;
            
        } catch (Exception $e) {
            $this->logError("Failed to get data quality metrics: " . $e->getMessage());
            return $this->errorResponse('Failed to get data quality metrics: ' . $e->getMessage(), 500);
        }
    }
    
    /**
     * GET /api/warehouse/etl-history
     * Получить историю выполнения ETL процессов
     * 
     * @param array $params Query parameters
     * @return array API response
     */
    public function getETLHistory(array $params = []): array {
        try {
            if (!$this->pdo) {
                return $this->errorResponse('Database not available', 503);
            }
            
            $limit = min((int)($params['limit'] ?? 50), 100); // Max 100 records
            $offset = max((int)($params['offset'] ?? 0), 0);
            $status = $params['status'] ?? null;
            $etlType = $params['etl_type'] ?? null;
            $days = min((int)($params['days'] ?? 30), 90); // Max 90 days
            
            // Build query conditions
            $conditions = ['started_at >= DATE_SUB(NOW(), INTERVAL ? DAY)'];
            $params_array = [$days];
            
            if ($status) {
                $conditions[] = 'status = ?';
                $params_array[] = $status;
            }
            
            if ($etlType) {
                $conditions[] = 'etl_type = ?';
                $params_array[] = $etlType;
            }
            
            $whereClause = implode(' AND ', $conditions);
            
            // Get ETL history records
            $stmt = $this->pdo->prepare("
                SELECT 
                    id,
                    batch_id,
                    etl_type,
                    started_at,
                    completed_at,
                    status,
                    records_processed,
                    execution_time_ms,
                    data_source,
                    error_message,
                    CASE 
                        WHEN completed_at IS NOT NULL 
                        THEN TIMESTAMPDIFF(SECOND, started_at, completed_at)
                        ELSE NULL 
                    END as duration_seconds
                FROM analytics_etl_log 
                WHERE {$whereClause}
                ORDER BY started_at DESC 
                LIMIT ? OFFSET ?
            ");
            
            $params_array[] = $limit;
            $params_array[] = $offset;
            $stmt->execute($params_array);
            $history = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Get total count for pagination
            $countStmt = $this->pdo->prepare("
                SELECT COUNT(*) as total 
                FROM analytics_etl_log 
                WHERE {$whereClause}
            ");
            $countStmt->execute(array_slice($params_array, 0, -2)); // Remove limit and offset
            $totalCount = $countStmt->fetchColumn();
            
            // Get summary statistics
            $summaryStats = $this->getETLSummaryStats($days);
            
            $response = [
                'status' => 'success',
                'data' => [
                    'history' => $history,
                    'pagination' => [
                        'total' => (int)$totalCount,
                        'limit' => $limit,
                        'offset' => $offset,
                        'has_more' => ($offset + $limit) < $totalCount
                    ],
                    'summary_stats' => $summaryStats,
                    'filters' => [
                        'days' => $days,
                        'status' => $status,
                        'etl_type' => $etlType
                    ],
                    'generated_at' => date('Y-m-d H:i:s')
                ]
            ];
            
            $this->logInfo("ETL history requested: limit={$limit}, offset={$offset}, days={$days}");
            return $response;
            
        } catch (Exception $e) {
            $this->logError("Failed to get ETL history: " . $e->getMessage());
            return $this->errorResponse('Failed to get ETL history: ' . $e->getMessage(), 500);
        }
    }
    
    /**
     * Initialize database connection
     */
    private function initializeDatabase(): void {
        $dbConfig = require __DIR__ . '/../../config/database.php';
        
        $this->pdo = new PDO(
            $dbConfig['dsn'],
            $dbConfig['username'],
            $dbConfig['password'],
            [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false
            ]
        );
    }
    
    /**
     * Initialize ETL service
     */
    private function initializeETL(): void {
        if (!$this->pdo) {
            throw new Exception("Database connection required for ETL initialization");
        }
        
        // Initialize ETL dependencies
        $apiClient = new AnalyticsApiClient($this->config['analytics_api']);
        $validator = new DataValidator($this->config['data_validator']);
        $normalizer = new WarehouseNormalizer($this->pdo, $this->config['warehouse_normalizer']);
        
        // Create ETL orchestrator
        $this->etl = new AnalyticsETL(
            $apiClient,
            $validator,
            $normalizer,
            $this->pdo,
            $this->config['analytics_etl']
        );
    }
    
    /**
     * Get system information
     * 
     * @return array System information
     */
    private function getSystemInfo(): array {
        return [
            'php_version' => PHP_VERSION,
            'memory_usage' => [
                'current' => memory_get_usage(true),
                'peak' => memory_get_peak_usage(true),
                'limit' => ini_get('memory_limit')
            ],
            'database_status' => $this->pdo ? 'connected' : 'disconnected',
            'etl_service_status' => $this->etl ? 'available' : 'unavailable',
            'server_time' => date('Y-m-d H:i:s'),
            'timezone' => date_default_timezone_get()
        ];
    }
    
    /**
     * Get recent activity summary
     * 
     * @return array Recent activity data
     */
    private function getRecentActivity(): array {
        if (!$this->pdo) {
            return [];
        }
        
        try {
            $stmt = $this->pdo->prepare("
                SELECT 
                    COUNT(*) as total_runs,
                    SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as successful_runs,
                    SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) as failed_runs,
                    MAX(started_at) as last_run_at,
                    AVG(execution_time_ms) as avg_execution_time_ms,
                    SUM(records_processed) as total_records_processed
                FROM analytics_etl_log 
                WHERE started_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)
            ");
            $stmt->execute();
            
            return $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
            
        } catch (Exception $e) {
            $this->logError("Failed to get recent activity: " . $e->getMessage());
            return [];
        }
    }
    
    /**
     * Calculate quality metrics
     * 
     * @param string $timeframe Time period
     * @param string $source Data source filter
     * @return array Quality metrics
     */
    private function calculateQualityMetrics(string $timeframe, string $source): array {
        $days = $this->parseTimeframe($timeframe);
        
        try {
            $conditions = ['updated_at >= DATE_SUB(NOW(), INTERVAL ? DAY)'];
            $params = [$days];
            
            if ($source !== 'all') {
                $conditions[] = 'data_source = ?';
                $params[] = $source;
            }
            
            $whereClause = implode(' AND ', $conditions);
            
            $stmt = $this->pdo->prepare("
                SELECT 
                    AVG(data_quality_score) as avg_quality_score,
                    MIN(data_quality_score) as min_quality_score,
                    MAX(data_quality_score) as max_quality_score,
                    COUNT(*) as total_records,
                    SUM(CASE WHEN data_quality_score >= 90 THEN 1 ELSE 0 END) as high_quality_records,
                    SUM(CASE WHEN data_quality_score < 70 THEN 1 ELSE 0 END) as low_quality_records
                FROM inventory 
                WHERE {$whereClause}
            ");
            $stmt->execute($params);
            
            return $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
            
        } catch (Exception $e) {
            $this->logError("Failed to calculate quality metrics: " . $e->getMessage());
            return [];
        }
    }
    
    /**
     * Calculate freshness metrics
     * 
     * @param string $source Data source filter
     * @return array Freshness metrics
     */
    private function calculateFreshnessMetrics(string $source): array {
        try {
            $conditions = [];
            $params = [];
            
            if ($source !== 'all') {
                $conditions[] = 'data_source = ?';
                $params[] = $source;
            }
            
            $whereClause = !empty($conditions) ? 'WHERE ' . implode(' AND ', $conditions) : '';
            
            $stmt = $this->pdo->prepare("
                SELECT 
                    AVG(TIMESTAMPDIFF(HOUR, last_analytics_sync, NOW())) as avg_hours_since_sync,
                    MAX(TIMESTAMPDIFF(HOUR, last_analytics_sync, NOW())) as max_hours_since_sync,
                    MIN(TIMESTAMPDIFF(HOUR, last_analytics_sync, NOW())) as min_hours_since_sync,
                    COUNT(*) as total_records,
                    SUM(CASE WHEN last_analytics_sync >= DATE_SUB(NOW(), INTERVAL 6 HOUR) THEN 1 ELSE 0 END) as fresh_records,
                    SUM(CASE WHEN last_analytics_sync < DATE_SUB(NOW(), INTERVAL 24 HOUR) THEN 1 ELSE 0 END) as stale_records
                FROM inventory 
                {$whereClause}
            ");
            $stmt->execute($params);
            
            return $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
            
        } catch (Exception $e) {
            $this->logError("Failed to calculate freshness metrics: " . $e->getMessage());
            return [];
        }
    }
    
    /**
     * Calculate completeness metrics
     * 
     * @param string $source Data source filter
     * @return array Completeness metrics
     */
    private function calculateCompletenessMetrics(string $source): array {
        try {
            $conditions = [];
            $params = [];
            
            if ($source !== 'all') {
                $conditions[] = 'data_source = ?';
                $params[] = $source;
            }
            
            $whereClause = !empty($conditions) ? 'WHERE ' . implode(' AND ', $conditions) : '';
            
            $stmt = $this->pdo->prepare("
                SELECT 
                    COUNT(*) as total_records,
                    SUM(CASE WHEN sku IS NOT NULL AND sku != '' THEN 1 ELSE 0 END) as sku_complete,
                    SUM(CASE WHEN warehouse_name IS NOT NULL AND warehouse_name != '' THEN 1 ELSE 0 END) as warehouse_complete,
                    SUM(CASE WHEN product_name IS NOT NULL AND product_name != '' THEN 1 ELSE 0 END) as product_name_complete,
                    SUM(CASE WHEN normalized_warehouse_name IS NOT NULL AND normalized_warehouse_name != '' THEN 1 ELSE 0 END) as normalized_warehouse_complete,
                    SUM(CASE WHEN price > 0 THEN 1 ELSE 0 END) as price_complete,
                    SUM(CASE WHEN category IS NOT NULL AND category != '' THEN 1 ELSE 0 END) as category_complete
                FROM inventory 
                {$whereClause}
            ");
            $stmt->execute($params);
            
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($result && $result['total_records'] > 0) {
                $total = $result['total_records'];
                $result['completeness_percentages'] = [
                    'sku' => round(($result['sku_complete'] / $total) * 100, 2),
                    'warehouse_name' => round(($result['warehouse_complete'] / $total) * 100, 2),
                    'product_name' => round(($result['product_name_complete'] / $total) * 100, 2),
                    'normalized_warehouse_name' => round(($result['normalized_warehouse_complete'] / $total) * 100, 2),
                    'price' => round(($result['price_complete'] / $total) * 100, 2),
                    'category' => round(($result['category_complete'] / $total) * 100, 2)
                ];
            }
            
            return $result ?: [];
            
        } catch (Exception $e) {
            $this->logError("Failed to calculate completeness metrics: " . $e->getMessage());
            return [];
        }
    }
    
    /**
     * Get validation statistics
     * 
     * @param string $timeframe Time period
     * @return array Validation statistics
     */
    private function getValidationStatistics(string $timeframe): array {
        $days = $this->parseTimeframe($timeframe);
        
        if (!$this->etl) {
            return [];
        }
        
        try {
            return $this->etl->getETLStatistics($days);
        } catch (Exception $e) {
            $this->logError("Failed to get validation statistics: " . $e->getMessage());
            return [];
        }
    }
    
    /**
     * Get ETL summary statistics
     * 
     * @param int $days Number of days
     * @return array Summary statistics
     */
    private function getETLSummaryStats(int $days): array {
        try {
            $stmt = $this->pdo->prepare("
                SELECT 
                    COUNT(*) as total_runs,
                    SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as successful_runs,
                    SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) as failed_runs,
                    SUM(CASE WHEN status = 'partial_success' THEN 1 ELSE 0 END) as partial_success_runs,
                    AVG(execution_time_ms) as avg_execution_time_ms,
                    SUM(records_processed) as total_records_processed,
                    AVG(records_processed) as avg_records_per_run
                FROM analytics_etl_log 
                WHERE started_at >= DATE_SUB(NOW(), INTERVAL ? DAY)
            ");
            $stmt->execute([$days]);
            
            $stats = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($stats && $stats['total_runs'] > 0) {
                $stats['success_rate'] = round(($stats['successful_runs'] / $stats['total_runs']) * 100, 2);
                $stats['failure_rate'] = round(($stats['failed_runs'] / $stats['total_runs']) * 100, 2);
            }
            
            return $stats ?: [];
            
        } catch (Exception $e) {
            $this->logError("Failed to get ETL summary stats: " . $e->getMessage());
            return [];
        }
    }
    
    /**
     * Parse timeframe string to days
     * 
     * @param string $timeframe Timeframe string (e.g., '7d', '1w', '1m')
     * @return int Number of days
     */
    private function parseTimeframe(string $timeframe): int {
        $timeframe = strtolower($timeframe);
        
        if (preg_match('/^(\d+)([dwmy])$/', $timeframe, $matches)) {
            $value = (int)$matches[1];
            $unit = $matches[2];
            
            switch ($unit) {
                case 'd': return $value;
                case 'w': return $value * 7;
                case 'm': return $value * 30;
                case 'y': return $value * 365;
            }
        }
        
        return 7; // Default to 7 days
    }
    
    /**
     * Set CORS headers
     */
    private function setCorsHeaders(): void {
        header('Access-Control-Allow-Origin: *');
        header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
        header('Access-Control-Allow-Headers: Content-Type, Authorization');
        header('Content-Type: application/json');
    }
    
    /**
     * Create success response
     * 
     * @param mixed $data Response data
     * @param int $code HTTP status code
     * @return array Response array
     */
    private function successResponse($data, int $code = 200): array {
        http_response_code($code);
        return [
            'status' => 'success',
            'data' => $data,
            'timestamp' => date('Y-m-d H:i:s')
        ];
    }
    
    /**
     * Create error response
     * 
     * @param string $message Error message
     * @param int $code HTTP status code
     * @return array Response array
     */
    private function errorResponse(string $message, int $code = 400): array {
        http_response_code($code);
        return [
            'status' => 'error',
            'message' => $message,
            'code' => $code,
            'timestamp' => date('Y-m-d H:i:s')
        ];
    }
    
    /**
     * Log info message
     * 
     * @param string $message Log message
     */
    private function logInfo(string $message): void {
        $this->writeLog('INFO', $message);
    }
    
    /**
     * Log error message
     * 
     * @param string $message Error message
     */
    private function logError(string $message): void {
        $this->writeLog('ERROR', $message);
    }
    
    /**
     * Write log message
     * 
     * @param string $level Log level
     * @param string $message Log message
     */
    private function writeLog(string $level, string $message): void {
        $timestamp = date('Y-m-d H:i:s');
        $logMessage = "[{$timestamp}] [{$level}] {$message}\n";
        
        file_put_contents($this->logFile, $logMessage, FILE_APPEND | LOCK_EX);
    }
    
    /**
     * Get default configuration
     * 
     * @return array Default configuration
     */
    private function getDefaultConfig(): array {
        return [
            'log_file' => __DIR__ . '/../../logs/analytics_etl_controller.log',
            'analytics_api' => [
                'base_url' => 'https://api.analytics.example.com',
                'timeout' => 30,
                'rate_limit' => 100
            ],
            'data_validator' => [
                'enable_anomaly_detection' => true,
                'quality_thresholds' => [
                    'completeness' => 0.95,
                    'accuracy' => 0.90,
                    'consistency' => 0.85
                ]
            ],
            'warehouse_normalizer' => [
                'fuzzy_threshold' => 0.8,
                'enable_learning' => true
            ],
            'analytics_etl' => [
                'load_batch_size' => 1000,
                'min_quality_score' => 80.0,
                'enable_audit_logging' => true
            ]
        ];
    }
    
    /**
     * GET /api/warehouse/etl-monitoring
     * Получить результаты мониторинга Analytics ETL процессов
     * 
     * @param array $params Query parameters
     * @return array API response
     */
    public function getETLMonitoring(array $params = []): array {
        try {
            // Include the monitoring service
            require_once __DIR__ . '/../../Services/AnalyticsETLMonitor.php';
            
            // Initialize monitor with custom config if provided
            $monitorConfig = [];
            if (isset($params['detailed_logging'])) {
                $monitorConfig['detailed_logging'] = filter_var($params['detailed_logging'], FILTER_VALIDATE_BOOLEAN);
            }
            if (isset($params['enable_alerts'])) {
                $monitorConfig['enable_alerts'] = filter_var($params['enable_alerts'], FILTER_VALIDATE_BOOLEAN);
            }
            
            $monitor = new AnalyticsETLMonitor($monitorConfig);
            $result = $monitor->performMonitoring();
            
            // Filter response based on request parameters
            $responseData = $result;
            
            if (isset($params['alerts_only']) && filter_var($params['alerts_only'], FILTER_VALIDATE_BOOLEAN)) {
                $responseData = [
                    'status' => $result['status'],
                    'timestamp' => $result['timestamp'],
                    'alerts' => $result['alerts'],
                    'alerts_count' => count($result['alerts'])
                ];
            }
            
            if (isset($params['health_score_only']) && filter_var($params['health_score_only'], FILTER_VALIDATE_BOOLEAN)) {
                $responseData = [
                    'status' => $result['status'],
                    'timestamp' => $result['timestamp'],
                    'overall_health_score' => $result['overall_health_score'],
                    'health_score_breakdown' => $result['metrics']['health_score_breakdown'] ?? null
                ];
            }
            
            if (isset($params['sla_only']) && filter_var($params['sla_only'], FILTER_VALIDATE_BOOLEAN)) {
                $responseData = [
                    'status' => $result['status'],
                    'timestamp' => $result['timestamp'],
                    'sla_compliance' => $result['sla_compliance']
                ];
            }
            
            $response = [
                'status' => 'success',
                'data' => $responseData
            ];
            
            $this->logInfo("ETL monitoring data requested successfully", [
                'health_score' => $result['overall_health_score'],
                'alerts_count' => count($result['alerts']),
                'execution_time_ms' => $result['execution_time_ms']
            ]);
            
            return $response;
            
        } catch (Exception $e) {
            $this->logError("Failed to get ETL monitoring data: " . $e->getMessage());
            return $this->errorResponse('Failed to get ETL monitoring data: ' . $e->getMessage(), 500);
        }
    }
}

// Handle direct API requests if this file is accessed directly
if (basename($_SERVER['PHP_SELF']) === basename(__FILE__)) {
    try {
        $controller = new AnalyticsETLController();
        
        $method = $_SERVER['REQUEST_METHOD'];
        $endpoint = $_SERVER['REQUEST_URI'];
        
        // Parse request parameters
        $params = [];
        if ($method === 'GET') {
            $params = $_GET;
        } elseif ($method === 'POST') {
            $input = file_get_contents('php://input');
            $params = json_decode($input, true) ?: $_POST;
        }
        
        // Handle request
        $response = $controller->handleRequest($method, $endpoint, $params);
        
        // Output JSON response
        echo json_encode($response, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode([
            'status' => 'error',
            'message' => 'Internal server error: ' . $e->getMessage(),
            'timestamp' => date('Y-m-d H:i:s')
        ], JSON_PRETTY_PRINT);
    }
}