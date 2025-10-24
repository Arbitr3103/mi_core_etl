<?php
/**
 * Comprehensive Error Handling Example
 * 
 * Demonstrates how to use the new error handling components together:
 * - OzonAPIErrorHandler for robust API error handling
 * - OzonETLLogger for comprehensive logging
 * - OzonETLNotificationManager for error notifications
 * 
 * This example shows integration with existing components like OzonETLRetryManager
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../classes/OzonETLRetryManager.php';
require_once __DIR__ . '/../classes/OzonAPIErrorHandler.php';
require_once __DIR__ . '/../classes/OzonETLLogger.php';
require_once __DIR__ . '/../classes/OzonETLNotificationManager.php';
require_once __DIR__ . '/../classes/OzonAnalyticsAPI.php';

/**
 * Example ETL process with comprehensive error handling
 */
class ComprehensiveErrorHandlingExample {
    
    private $pdo;
    private $retryManager;
    private $apiErrorHandler;
    private $logger;
    private $notificationManager;
    private $ozonAPI;
    private $etlId;
    
    public function __construct() {
        // Initialize database connection
        $this->pdo = new PDO(
            "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4",
            DB_USER,
            DB_PASS,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
        );
        
        // Generate unique ETL ID
        $this->etlId = 'etl_' . date('Y-m-d_H-i-s') . '_' . uniqid();
        
        // Initialize error handling components
        $this->initializeErrorHandling();
        
        // Initialize Ozon API
        $this->ozonAPI = new OzonAnalyticsAPI($this->pdo);
    }
    
    /**
     * Initialize all error handling components
     */
    private function initializeErrorHandling(): void {
        // Initialize retry manager (existing component)
        $this->retryManager = new OzonETLRetryManager($this->pdo, [
            'enable_graceful_degradation' => true,
            'fallback_data_max_age_hours' => 24,
            'auto_recovery_enabled' => true
        ]);
        
        // Initialize comprehensive logger
        $this->logger = new OzonETLLogger($this->pdo, [
            'log_level' => 'INFO',
            'enable_rotation' => true,
            'enable_database' => true,
            'enable_performance_logging' => true
        ]);
        
        // Set ETL context for all logs
        $this->logger->setContext([
            'etl_id' => $this->etlId,
            'process_name' => 'comprehensive_error_handling_example',
            'environment' => 'development'
        ]);
        
        // Initialize API error handler
        $this->apiErrorHandler = new OzonAPIErrorHandler($this->pdo, $this->retryManager, [
            'enable_fallback' => true,
            'fallback_cache_hours' => 24,
            'enable_circuit_breaker' => true,
            'circuit_breaker_threshold' => 5
        ]);
        
        // Initialize notification manager
        $this->notificationManager = new OzonETLNotificationManager($this->pdo, $this->logger, [
            'enable_notifications' => true,
            'enable_escalation' => true,
            'notification_cooldown_minutes' => 15,
            'channels' => [
                'email' => [
                    'enabled' => true,
                    'from_email' => 'etl@manhattan-system.com'
                ],
                'webhook' => [
                    'enabled' => true,
                    'urls' => ['http://localhost:8080/webhook/etl-alerts']
                ]
            ]
        ]);
    }
    
    /**
     * Run example ETL process with comprehensive error handling
     */
    public function runETLProcess(): void {
        $this->logger->info("Starting comprehensive ETL process example", [
            'etl_id' => $this->etlId
        ]);
        
        try {
            // Step 1: Request warehouse stock report with error handling
            $this->requestWarehouseStockReport();
            
            // Step 2: Monitor report status with error handling
            $reportData = $this->monitorReportStatus();
            
            // Step 3: Process report data with error handling
            $this->processReportData($reportData);
            
            // Step 4: Update inventory with error handling
            $this->updateInventoryData($reportData);
            
            $this->logger->info("ETL process completed successfully", [
                'etl_id' => $this->etlId
            ]);
            
        } catch (Exception $e) {
            $this->handleCriticalError($e);
        }
    }
    
    /**
     * Request warehouse stock report with API error handling
     */
    private function requestWarehouseStockReport(): array {
        $this->logger->logETLProcess('INFO', "Requesting warehouse stock report");
        
        $startTime = microtime(true);
        
        try {
            // Use API error handler to execute the request with retry logic
            $result = $this->apiErrorHandler->executeWithErrorHandling(
                function() {
                    // Simulate API call that might fail
                    if (rand(1, 10) <= 3) { // 30% chance of failure for demo
                        throw new Exception("API rate limit exceeded", 429);
                    }
                    
                    return [
                        'report_code' => 'report_' . uniqid(),
                        'status' => 'PROCESSING',
                        'estimated_completion' => date('Y-m-d H:i:s', time() + 300)
                    ];
                },
                '/v1/report/warehouse/stock',
                ['date_from' => date('Y-m-d', strtotime('-1 day'))],
                $this->etlId
            );
            
            $duration = (microtime(true) - $startTime) * 1000;
            
            // Log performance metrics
            $this->logger->logPerformance($this->etlId, 'request_warehouse_stock_report', $duration, [
                'report_code' => $result['report_code'],
                'success' => true
            ]);
            
            // Cache successful response for fallback
            $this->apiErrorHandler->cacheAPIResponse(
                '/v1/report/warehouse/stock',
                ['date_from' => date('Y-m-d', strtotime('-1 day'))],
                $result,
                $this->etlId
            );
            
            $this->logger->logAPICall('INFO', "Warehouse stock report requested successfully", [
                'report_code' => $result['report_code']
            ]);
            
            return $result;
            
        } catch (Exception $e) {
            $duration = (microtime(true) - $startTime) * 1000;
            
            // Log performance metrics for failed operation
            $this->logger->logPerformance($this->etlId, 'request_warehouse_stock_report', $duration, [
                'success' => false,
                'error_message' => $e->getMessage()
            ]);
            
            // Log API error
            $this->logger->logAPICall('ERROR', "Failed to request warehouse stock report", [
                'error' => $e->getMessage(),
                'error_code' => $e->getCode()
            ]);
            
            // Send notification for API failure
            $this->notificationManager->sendCriticalErrorNotification(
                $this->etlId,
                'API_FAILURE',
                'Warehouse Stock Report Request Failed',
                "Failed to request warehouse stock report: " . $e->getMessage(),
                [
                    'endpoint' => '/v1/report/warehouse/stock',
                    'error_code' => $e->getCode(),
                    'retry_attempts' => 3
                ]
            );
            
            throw $e;
        }
    }
    
    /**
     * Monitor report status with comprehensive error handling
     */
    private function monitorReportStatus(): array {
        $this->logger->logETLProcess('INFO', "Monitoring report status");
        
        $maxAttempts = 12; // 12 attempts with 30-second intervals = 6 minutes max
        $attempt = 0;
        
        while ($attempt < $maxAttempts) {
            $attempt++;
            
            try {
                $status = $this->apiErrorHandler->executeWithErrorHandling(
                    function() use ($attempt) {
                        // Simulate status check that might fail
                        if (rand(1, 20) <= 2) { // 10% chance of failure
                            throw new Exception("Network timeout", 0);
                        }
                        
                        // Simulate report completion after several attempts
                        if ($attempt >= 5) {
                            return [
                                'status' => 'SUCCESS',
                                'download_url' => 'https://api.ozon.ru/reports/download/12345',
                                'file_size' => 1024000
                            ];
                        }
                        
                        return [
                            'status' => 'PROCESSING',
                            'progress' => min($attempt * 20, 90)
                        ];
                    },
                    '/v1/report/info',
                    ['report_code' => 'report_12345'],
                    $this->etlId
                );
                
                $this->logger->logAPICall('INFO', "Report status checked", [
                    'attempt' => $attempt,
                    'status' => $status['status'],
                    'progress' => $status['progress'] ?? null
                ]);
                
                if ($status['status'] === 'SUCCESS') {
                    $this->logger->logETLProcess('INFO', "Report completed successfully", [
                        'attempts' => $attempt,
                        'file_size' => $status['file_size']
                    ]);
                    
                    return $status;
                }
                
                // Wait before next attempt
                sleep(30);
                
            } catch (Exception $e) {
                $this->logger->logAPICall('WARNING', "Report status check failed", [
                    'attempt' => $attempt,
                    'error' => $e->getMessage()
                ]);
                
                // Continue trying unless it's the last attempt
                if ($attempt >= $maxAttempts) {
                    throw new Exception("Report monitoring failed after {$maxAttempts} attempts: " . $e->getMessage());
                }
                
                sleep(30);
            }
        }
        
        throw new Exception("Report monitoring timeout after {$maxAttempts} attempts");
    }
    
    /**
     * Process report data with error handling
     */
    private function processReportData(array $reportData): array {
        $this->logger->logDataProcessing('INFO', "Processing report data", [
            'file_size' => $reportData['file_size']
        ]);
        
        $startTime = microtime(true);
        
        try {
            // Simulate data processing that might fail
            if (rand(1, 20) <= 1) { // 5% chance of failure
                throw new Exception("Data corruption detected in CSV file");
            }
            
            // Simulate processing
            $processedRecords = rand(1000, 5000);
            sleep(2); // Simulate processing time
            
            $duration = (microtime(true) - $startTime) * 1000;
            
            // Log performance
            $this->logger->logPerformance($this->etlId, 'process_report_data', $duration, [
                'records_processed' => $processedRecords,
                'success' => true
            ]);
            
            $this->logger->logDataProcessing('INFO', "Report data processed successfully", [
                'records_processed' => $processedRecords,
                'processing_time_ms' => $duration
            ]);
            
            return [
                'records_processed' => $processedRecords,
                'processing_time_ms' => $duration
            ];
            
        } catch (Exception $e) {
            $duration = (microtime(true) - $startTime) * 1000;
            
            // Log performance for failed operation
            $this->logger->logPerformance($this->etlId, 'process_report_data', $duration, [
                'success' => false,
                'error_message' => $e->getMessage()
            ]);
            
            $this->logger->logDataProcessing('ERROR', "Report data processing failed", [
                'error' => $e->getMessage(),
                'processing_time_ms' => $duration
            ]);
            
            // Send notification for data corruption
            $this->notificationManager->sendCriticalErrorNotification(
                $this->etlId,
                'DATA_CORRUPTION',
                'Report Data Processing Failed',
                "Failed to process report data: " . $e->getMessage(),
                [
                    'file_size' => $reportData['file_size'],
                    'processing_stage' => 'csv_parsing'
                ]
            );
            
            throw $e;
        }
    }
    
    /**
     * Update inventory data with error handling
     */
    private function updateInventoryData(array $reportData): void {
        $this->logger->logDataProcessing('INFO', "Updating inventory data");
        
        $startTime = microtime(true);
        
        try {
            // Use retry manager for database operations
            $this->retryManager->executeWithRetry(
                function() {
                    // Simulate database update that might fail
                    if (rand(1, 30) <= 1) { // 3% chance of failure
                        throw new Exception("Database connection lost");
                    }
                    
                    // Simulate database update
                    sleep(1);
                    return true;
                },
                'data_processing',
                $this->etlId,
                ['operation' => 'inventory_update']
            );
            
            $duration = (microtime(true) - $startTime) * 1000;
            
            // Log performance
            $this->logger->logPerformance($this->etlId, 'update_inventory_data', $duration, [
                'success' => true
            ]);
            
            $this->logger->logDataProcessing('INFO', "Inventory data updated successfully", [
                'update_time_ms' => $duration
            ]);
            
        } catch (Exception $e) {
            $duration = (microtime(true) - $startTime) * 1000;
            
            // Log performance for failed operation
            $this->logger->logPerformance($this->etlId, 'update_inventory_data', $duration, [
                'success' => false,
                'error_message' => $e->getMessage()
            ]);
            
            $this->logger->logDataProcessing('ERROR', "Inventory data update failed", [
                'error' => $e->getMessage(),
                'update_time_ms' => $duration
            ]);
            
            // Send notification for database error
            $this->notificationManager->sendCriticalErrorNotification(
                $this->etlId,
                'DATABASE_ERROR',
                'Inventory Update Failed',
                "Failed to update inventory data: " . $e->getMessage(),
                [
                    'operation' => 'inventory_update',
                    'retry_attempts' => 3
                ]
            );
            
            throw $e;
        }
    }
    
    /**
     * Handle critical errors with comprehensive error handling
     */
    private function handleCriticalError(Exception $e): void {
        $this->logger->critical("Critical ETL error occurred", [
            'error' => $e->getMessage(),
            'error_code' => $e->getCode(),
            'file' => $e->getFile(),
            'line' => $e->getLine(),
            'trace' => $e->getTraceAsString()
        ]);
        
        // Send emergency notification
        $this->notificationManager->sendCriticalErrorNotification(
            $this->etlId,
            'SYSTEM_RESOURCE_ERROR',
            'Critical ETL Process Failure',
            "Critical error in ETL process: " . $e->getMessage(),
            [
                'error_code' => $e->getCode(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'stack_trace' => $e->getTraceAsString()
            ],
            ['priority' => OzonETLNotificationManager::PRIORITY_EMERGENCY]
        );
        
        // Log final status
        $this->logger->logETLProcess('ERROR', "ETL process failed with critical error", [
            'final_status' => 'FAILED',
            'error_type' => get_class($e)
        ]);
    }
    
    /**
     * Demonstrate error statistics and monitoring
     */
    public function showErrorStatistics(): void {
        echo "\n=== Error Handling Statistics ===\n";
        
        // API Error Statistics
        $apiStats = $this->apiErrorHandler->getErrorStatistics(24);
        echo "\nAPI Error Statistics (last 24 hours):\n";
        foreach ($apiStats as $stat) {
            echo "- {$stat['api_endpoint']} ({$stat['error_type']}): {$stat['error_count']} errors, avg {$stat['avg_retries']} retries\n";
        }
        
        // Log Statistics
        $logStats = $this->logger->getLogStatistics(24);
        echo "\nLog Statistics (last 24 hours):\n";
        foreach ($logStats as $stat) {
            echo "- {$stat['category']} ({$stat['log_level']}): {$stat['count']} entries\n";
        }
        
        // Notification Statistics
        $notificationStats = $this->notificationManager->getNotificationStatistics(24);
        echo "\nNotification Statistics (last 24 hours):\n";
        foreach ($notificationStats as $stat) {
            echo "- {$stat['error_category']} (Priority {$stat['priority_level']}): {$stat['total_sent']} sent, {$stat['total_failed']} failed\n";
        }
    }
    
    /**
     * Demonstrate log search functionality
     */
    public function searchLogs(): void {
        echo "\n=== Log Search Examples ===\n";
        
        // Search for errors in this ETL process
        $errorLogs = $this->logger->searchLogs([
            'etl_id' => $this->etlId,
            'log_level' => 'ERROR'
        ], 10);
        
        echo "\nError logs for ETL ID {$this->etlId}:\n";
        foreach ($errorLogs as $log) {
            echo "- [{$log['created_at']}] {$log['message']}\n";
        }
        
        // Search for API-related logs
        $apiLogs = $this->logger->searchLogs([
            'category' => 'api_call',
            'date_from' => date('Y-m-d H:i:s', strtotime('-1 hour'))
        ], 5);
        
        echo "\nRecent API call logs:\n";
        foreach ($apiLogs as $log) {
            echo "- [{$log['created_at']}] {$log['message']}\n";
        }
    }
}

// Example usage
if (basename(__FILE__) === basename($_SERVER['SCRIPT_NAME'])) {
    try {
        echo "=== Comprehensive Error Handling Example ===\n";
        
        $example = new ComprehensiveErrorHandlingExample();
        
        // Run the ETL process with error handling
        $example->runETLProcess();
        
        // Show statistics
        $example->showErrorStatistics();
        
        // Demonstrate log search
        $example->searchLogs();
        
        echo "\n=== Example completed successfully ===\n";
        
    } catch (Exception $e) {
        echo "Example failed: " . $e->getMessage() . "\n";
        echo "Stack trace:\n" . $e->getTraceAsString() . "\n";
    }
}