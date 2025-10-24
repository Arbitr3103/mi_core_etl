<?php
/**
 * OzonETLLogger Class - Comprehensive logging system for Ozon ETL processes
 * 
 * Implements structured logging with context information, log levels,
 * log rotation, and archival for long-term storage. Extends the existing
 * SimpleLogger functionality with ETL-specific features.
 * 
 * @version 1.0
 * @author Manhattan System
 */

class OzonETLLogger {
    
    private $pdo;
    private $logDir;
    private $logLevel;
    private $enableRotation;
    private $maxFileSize;
    private $maxFiles;
    private $enableDatabase;
    private $context;
    
    // Log levels with numeric values for comparison
    const LOG_LEVELS = [
        'DEBUG' => 0,
        'INFO' => 1,
        'WARNING' => 2,
        'ERROR' => 3,
        'CRITICAL' => 4
    ];
    
    // Log categories for ETL processes
    const CATEGORIES = [
        'ETL_PROCESS' => 'etl_process',
        'API_CALL' => 'api_call',
        'DATA_PROCESSING' => 'data_processing',
        'ERROR_HANDLING' => 'error_handling',
        'NOTIFICATION' => 'notification',
        'PERFORMANCE' => 'performance',
        'SECURITY' => 'security'
    ];
    
    /**
     * Constructor
     * 
     * @param PDO $pdo Database connection
     * @param array $config Logger configuration
     */
    public function __construct(PDO $pdo, array $config = []) {
        $this->pdo = $pdo;
        $this->initializeConfig($config);
        $this->initializeLogDirectory();
        $this->initializeLogTables();
        $this->context = [];
    }
    
    /**
     * Initialize configuration with defaults
     */
    private function initializeConfig(array $config): void {
        $defaults = [
            'log_dir' => sys_get_temp_dir() . '/ozon_etl_logs',
            'log_level' => 'INFO',
            'enable_rotation' => true,
            'max_file_size_mb' => 50,
            'max_files' => 10,
            'enable_database' => true,
            'enable_compression' => true,
            'retention_days' => 90,
            'enable_context_enrichment' => true,
            'enable_performance_logging' => true
        ];
        
        $this->logDir = $config['log_dir'] ?? $defaults['log_dir'];
        $this->logLevel = $config['log_level'] ?? $defaults['log_level'];
        $this->enableRotation = $config['enable_rotation'] ?? $defaults['enable_rotation'];
        $this->maxFileSize = ($config['max_file_size_mb'] ?? $defaults['max_file_size_mb']) * 1024 * 1024;
        $this->maxFiles = $config['max_files'] ?? $defaults['max_files'];
        $this->enableDatabase = $config['enable_database'] ?? $defaults['enable_database'];
        
        $this->config = array_merge($defaults, $config);
    }
    
    /**
     * Initialize log directory
     */
    private function initializeLogDirectory(): void {
        if (!is_dir($this->logDir)) {
            if (!mkdir($this->logDir, 0755, true)) {
                throw new Exception("Failed to create log directory: {$this->logDir}");
            }
        }
        
        // Create subdirectories for different log types
        $subdirs = ['etl', 'api', 'errors', 'performance', 'archived'];
        foreach ($subdirs as $subdir) {
            $path = $this->logDir . '/' . $subdir;
            if (!is_dir($path)) {
                mkdir($path, 0755, true);
            }
        }
    }
    
    /**
     * Initialize database tables for logging
     */
    private function initializeLogTables(): void {
        if (!$this->enableDatabase) {
            return;
        }
        
        try {
            // Enhanced log table with structured data
            $this->pdo->exec("
                CREATE TABLE IF NOT EXISTS ozon_etl_logs (
                    id BIGINT AUTO_INCREMENT PRIMARY KEY,
                    etl_id VARCHAR(255) NULL,
                    log_level ENUM('DEBUG', 'INFO', 'WARNING', 'ERROR', 'CRITICAL') NOT NULL,
                    category VARCHAR(50) NOT NULL DEFAULT 'general',
                    message TEXT NOT NULL,
                    context JSON NULL,
                    file_path VARCHAR(500) NULL,
                    line_number INT NULL,
                    execution_time_ms DECIMAL(10,3) NULL,
                    memory_usage_mb DECIMAL(8,2) NULL,
                    user_id INT NULL,
                    session_id VARCHAR(100) NULL,
                    request_id VARCHAR(100) NULL,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    
                    INDEX idx_etl_id (etl_id),
                    INDEX idx_log_level (log_level),
                    INDEX idx_category (category),
                    INDEX idx_created_at (created_at),
                    INDEX idx_etl_level_created (etl_id, log_level, created_at)
                ) ENGINE=InnoDB
            ");
            
            // Performance metrics table
            $this->pdo->exec("
                CREATE TABLE IF NOT EXISTS ozon_etl_performance_logs (
                    id BIGINT AUTO_INCREMENT PRIMARY KEY,
                    etl_id VARCHAR(255) NOT NULL,
                    operation_name VARCHAR(255) NOT NULL,
                    start_time TIMESTAMP NOT NULL,
                    end_time TIMESTAMP NOT NULL,
                    duration_ms DECIMAL(10,3) NOT NULL,
                    memory_start_mb DECIMAL(8,2) NULL,
                    memory_end_mb DECIMAL(8,2) NULL,
                    memory_peak_mb DECIMAL(8,2) NULL,
                    records_processed INT NULL,
                    success BOOLEAN NOT NULL DEFAULT TRUE,
                    error_message TEXT NULL,
                    metadata JSON NULL,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    
                    INDEX idx_etl_id (etl_id),
                    INDEX idx_operation_name (operation_name),
                    INDEX idx_duration (duration_ms),
                    INDEX idx_created_at (created_at)
                ) ENGINE=InnoDB
            ");
            
            // Log file rotation tracking
            $this->pdo->exec("
                CREATE TABLE IF NOT EXISTS ozon_etl_log_files (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    file_path VARCHAR(500) NOT NULL,
                    file_size_bytes BIGINT NOT NULL,
                    log_count INT NOT NULL DEFAULT 0,
                    start_time TIMESTAMP NOT NULL,
                    end_time TIMESTAMP NULL,
                    is_archived BOOLEAN DEFAULT FALSE,
                    archived_path VARCHAR(500) NULL,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    
                    INDEX idx_file_path (file_path),
                    INDEX idx_is_archived (is_archived),
                    INDEX idx_created_at (created_at)
                ) ENGINE=InnoDB
            ");
            
        } catch (Exception $e) {
            error_log("Failed to initialize log tables: " . $e->getMessage());
        }
    }
    
    /**
     * Set global context for all log entries
     */
    public function setContext(array $context): void {
        $this->context = array_merge($this->context, $context);
    }
    
    /**
     * Add context item
     */
    public function addContext(string $key, mixed $value): void {
        $this->context[$key] = $value;
    }
    
    /**
     * Clear context
     */
    public function clearContext(): void {
        $this->context = [];
    }
    
    /**
     * Debug level logging
     */
    public function debug(string $message, array $context = [], string $category = 'general'): void {
        $this->log('DEBUG', $message, $context, $category);
    }
    
    /**
     * Info level logging
     */
    public function info(string $message, array $context = [], string $category = 'general'): void {
        $this->log('INFO', $message, $context, $category);
    }
    
    /**
     * Warning level logging
     */
    public function warning(string $message, array $context = [], string $category = 'general'): void {
        $this->log('WARNING', $message, $context, $category);
    }
    
    /**
     * Error level logging
     */
    public function error(string $message, array $context = [], string $category = 'general'): void {
        $this->log('ERROR', $message, $context, $category);
    }
    
    /**
     * Critical level logging
     */
    public function critical(string $message, array $context = [], string $category = 'general'): void {
        $this->log('CRITICAL', $message, $context, $category);
    }
    
    /**
     * Log ETL process events
     */
    public function logETLProcess(string $level, string $message, array $context = []): void {
        $this->log($level, $message, $context, self::CATEGORIES['ETL_PROCESS']);
    }
    
    /**
     * Log API call events
     */
    public function logAPICall(string $level, string $message, array $context = []): void {
        $this->log($level, $message, $context, self::CATEGORIES['API_CALL']);
    }
    
    /**
     * Log data processing events
     */
    public function logDataProcessing(string $level, string $message, array $context = []): void {
        $this->log($level, $message, $context, self::CATEGORIES['DATA_PROCESSING']);
    }
    
    /**
     * Log performance metrics
     */
    public function logPerformance(string $etlId, string $operationName, float $durationMs, array $metadata = []): void {
        if (!$this->config['enable_performance_logging']) {
            return;
        }
        
        $context = array_merge([
            'etl_id' => $etlId,
            'operation_name' => $operationName,
            'duration_ms' => $durationMs,
            'memory_usage_mb' => memory_get_usage(true) / 1024 / 1024
        ], $metadata);
        
        $this->log('INFO', "Performance: {$operationName} completed in {$durationMs}ms", $context, self::CATEGORIES['PERFORMANCE']);
        
        // Also log to performance table if database logging is enabled
        if ($this->enableDatabase) {
            $this->logPerformanceToDatabase($etlId, $operationName, $durationMs, $metadata);
        }
    }
    
    /**
     * Main logging method
     */
    private function log(string $level, string $message, array $context = [], string $category = 'general'): void {
        // Check if log level is enabled
        if (self::LOG_LEVELS[$level] < self::LOG_LEVELS[$this->logLevel]) {
            return;
        }
        
        // Merge with global context
        $fullContext = array_merge($this->context, $context);
        
        // Enrich context if enabled
        if ($this->config['enable_context_enrichment']) {
            $fullContext = $this->enrichContext($fullContext);
        }
        
        // Create log entry
        $logEntry = $this->createLogEntry($level, $message, $fullContext, $category);
        
        // Write to file
        $this->writeToFile($logEntry, $category);
        
        // Write to database
        if ($this->enableDatabase) {
            $this->writeToDatabase($level, $message, $fullContext, $category);
        }
        
        // Handle log rotation if needed
        if ($this->enableRotation) {
            $this->checkAndRotateLog($category);
        }
    }
    
    /**
     * Enrich context with additional information
     */
    private function enrichContext(array $context): array {
        $enriched = $context;
        
        // Add timestamp if not present
        if (!isset($enriched['timestamp'])) {
            $enriched['timestamp'] = microtime(true);
        }
        
        // Add memory usage
        if (!isset($enriched['memory_usage_mb'])) {
            $enriched['memory_usage_mb'] = round(memory_get_usage(true) / 1024 / 1024, 2);
        }
        
        // Add peak memory usage
        if (!isset($enriched['memory_peak_mb'])) {
            $enriched['memory_peak_mb'] = round(memory_get_peak_usage(true) / 1024 / 1024, 2);
        }
        
        // Add process ID
        if (!isset($enriched['process_id'])) {
            $enriched['process_id'] = getmypid();
        }
        
        // Add backtrace information for errors
        if (!isset($enriched['file']) && !isset($enriched['line'])) {
            $backtrace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 5);
            foreach ($backtrace as $trace) {
                if (isset($trace['file']) && !strpos($trace['file'], 'OzonETLLogger.php')) {
                    $enriched['file'] = basename($trace['file']);
                    $enriched['line'] = $trace['line'];
                    break;
                }
            }
        }
        
        return $enriched;
    }
    
    /**
     * Create formatted log entry
     */
    private function createLogEntry(string $level, string $message, array $context, string $category): string {
        $timestamp = date('Y-m-d H:i:s.') . substr(microtime(), 2, 3);
        $processId = getmypid();
        
        // Format context for readability
        $contextStr = '';
        if (!empty($context)) {
            $contextStr = ' | ' . json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }
        
        return "[{$timestamp}] [{$level}] [{$category}] [PID:{$processId}] {$message}{$contextStr}\n";
    }
    
    /**
     * Write log entry to file
     */
    private function writeToFile(string $logEntry, string $category): void {
        $filename = $this->getLogFilename($category);
        
        if (!file_put_contents($filename, $logEntry, FILE_APPEND | LOCK_EX)) {
            error_log("Failed to write to log file: {$filename}");
        }
    }
    
    /**
     * Get log filename for category
     */
    private function getLogFilename(string $category): string {
        $date = date('Y-m-d');
        $subdir = $this->getCategorySubdir($category);
        return "{$this->logDir}/{$subdir}/ozon_etl_{$category}_{$date}.log";
    }
    
    /**
     * Get subdirectory for category
     */
    private function getCategorySubdir(string $category): string {
        $mapping = [
            'etl_process' => 'etl',
            'api_call' => 'api',
            'error_handling' => 'errors',
            'performance' => 'performance'
        ];
        
        return $mapping[$category] ?? 'etl';
    }
    
    /**
     * Write log entry to database
     */
    private function writeToDatabase(string $level, string $message, array $context, string $category): void {
        try {
            $sql = "INSERT INTO ozon_etl_logs 
                    (etl_id, log_level, category, message, context, file_path, line_number, 
                     execution_time_ms, memory_usage_mb, session_id, request_id)
                    VALUES (:etl_id, :log_level, :category, :message, :context, :file_path, :line_number,
                            :execution_time_ms, :memory_usage_mb, :session_id, :request_id)";
            
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute([
                'etl_id' => $context['etl_id'] ?? null,
                'log_level' => $level,
                'category' => $category,
                'message' => $message,
                'context' => json_encode($context),
                'file_path' => $context['file'] ?? null,
                'line_number' => $context['line'] ?? null,
                'execution_time_ms' => $context['execution_time_ms'] ?? null,
                'memory_usage_mb' => $context['memory_usage_mb'] ?? null,
                'session_id' => $context['session_id'] ?? null,
                'request_id' => $context['request_id'] ?? null
            ]);
            
        } catch (Exception $e) {
            error_log("Failed to write log to database: " . $e->getMessage());
        }
    }
    
    /**
     * Log performance metrics to database
     */
    private function logPerformanceToDatabase(string $etlId, string $operationName, float $durationMs, array $metadata): void {
        try {
            $sql = "INSERT INTO ozon_etl_performance_logs 
                    (etl_id, operation_name, start_time, end_time, duration_ms, 
                     memory_start_mb, memory_end_mb, memory_peak_mb, records_processed, 
                     success, error_message, metadata)
                    VALUES (:etl_id, :operation_name, :start_time, :end_time, :duration_ms,
                            :memory_start_mb, :memory_end_mb, :memory_peak_mb, :records_processed,
                            :success, :error_message, :metadata)";
            
            $startTime = isset($metadata['start_time']) ? date('Y-m-d H:i:s', $metadata['start_time']) : date('Y-m-d H:i:s', time() - ($durationMs / 1000));
            $endTime = date('Y-m-d H:i:s');
            
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute([
                'etl_id' => $etlId,
                'operation_name' => $operationName,
                'start_time' => $startTime,
                'end_time' => $endTime,
                'duration_ms' => $durationMs,
                'memory_start_mb' => $metadata['memory_start_mb'] ?? null,
                'memory_end_mb' => $metadata['memory_end_mb'] ?? null,
                'memory_peak_mb' => $metadata['memory_peak_mb'] ?? null,
                'records_processed' => $metadata['records_processed'] ?? null,
                'success' => $metadata['success'] ?? true,
                'error_message' => $metadata['error_message'] ?? null,
                'metadata' => json_encode($metadata)
            ]);
            
        } catch (Exception $e) {
            error_log("Failed to log performance to database: " . $e->getMessage());
        }
    }
    
    /**
     * Check and rotate log files if needed
     */
    private function checkAndRotateLog(string $category): void {
        $filename = $this->getLogFilename($category);
        
        if (!file_exists($filename)) {
            return;
        }
        
        $fileSize = filesize($filename);
        
        if ($fileSize >= $this->maxFileSize) {
            $this->rotateLogFile($filename, $category);
        }
    }
    
    /**
     * Rotate log file
     */
    private function rotateLogFile(string $filename, string $category): void {
        $timestamp = date('Y-m-d_H-i-s');
        $rotatedFilename = str_replace('.log', "_{$timestamp}.log", $filename);
        
        // Move current log file
        if (rename($filename, $rotatedFilename)) {
            // Compress if enabled
            if ($this->config['enable_compression']) {
                $this->compressLogFile($rotatedFilename);
            }
            
            // Track in database
            if ($this->enableDatabase) {
                $this->trackRotatedFile($rotatedFilename, filesize($rotatedFilename));
            }
            
            // Clean up old files
            $this->cleanupOldLogFiles($category);
        }
    }
    
    /**
     * Compress log file
     */
    private function compressLogFile(string $filename): void {
        if (!function_exists('gzopen')) {
            return;
        }
        
        $compressedFilename = $filename . '.gz';
        
        $input = fopen($filename, 'rb');
        $output = gzopen($compressedFilename, 'wb9');
        
        if ($input && $output) {
            while (!feof($input)) {
                gzwrite($output, fread($input, 8192));
            }
            
            fclose($input);
            gzclose($output);
            
            // Remove original file
            unlink($filename);
        }
    }
    
    /**
     * Track rotated file in database
     */
    private function trackRotatedFile(string $filename, int $fileSize): void {
        try {
            $sql = "INSERT INTO ozon_etl_log_files 
                    (file_path, file_size_bytes, start_time, end_time)
                    VALUES (:file_path, :file_size_bytes, :start_time, :end_time)";
            
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute([
                'file_path' => $filename,
                'file_size_bytes' => $fileSize,
                'start_time' => date('Y-m-d H:i:s', filemtime($filename)),
                'end_time' => date('Y-m-d H:i:s')
            ]);
            
        } catch (Exception $e) {
            error_log("Failed to track rotated file: " . $e->getMessage());
        }
    }
    
    /**
     * Clean up old log files
     */
    private function cleanupOldLogFiles(string $category): void {
        $subdir = $this->getCategorySubdir($category);
        $logDir = "{$this->logDir}/{$subdir}";
        
        $pattern = "{$logDir}/ozon_etl_{$category}_*.log*";
        $files = glob($pattern);
        
        if (count($files) > $this->maxFiles) {
            // Sort by modification time
            usort($files, function($a, $b) {
                return filemtime($a) - filemtime($b);
            });
            
            // Remove oldest files
            $filesToRemove = array_slice($files, 0, count($files) - $this->maxFiles);
            
            foreach ($filesToRemove as $file) {
                if (unlink($file)) {
                    $this->info("Cleaned up old log file", ['file' => $file], 'maintenance');
                }
            }
        }
    }
    
    /**
     * Archive old logs
     */
    public function archiveOldLogs(int $daysOld = 30): int {
        $archivedCount = 0;
        $cutoffDate = date('Y-m-d', strtotime("-{$daysOld} days"));
        
        try {
            // Archive database logs
            if ($this->enableDatabase) {
                $sql = "INSERT INTO ozon_etl_logs_archive 
                        SELECT * FROM ozon_etl_logs 
                        WHERE DATE(created_at) < :cutoff_date";
                
                $stmt = $this->pdo->prepare($sql);
                $stmt->execute(['cutoff_date' => $cutoffDate]);
                
                $archivedCount += $stmt->rowCount();
                
                // Delete archived logs from main table
                $sql = "DELETE FROM ozon_etl_logs WHERE DATE(created_at) < :cutoff_date";
                $stmt = $this->pdo->prepare($sql);
                $stmt->execute(['cutoff_date' => $cutoffDate]);
            }
            
            // Archive log files
            $this->archiveLogFiles($daysOld);
            
        } catch (Exception $e) {
            $this->error("Failed to archive old logs", ['error' => $e->getMessage()]);
        }
        
        return $archivedCount;
    }
    
    /**
     * Archive log files
     */
    private function archiveLogFiles(int $daysOld): void {
        $archiveDir = $this->logDir . '/archived';
        $cutoffTime = time() - ($daysOld * 24 * 3600);
        
        $subdirs = ['etl', 'api', 'errors', 'performance'];
        
        foreach ($subdirs as $subdir) {
            $logDir = "{$this->logDir}/{$subdir}";
            if (!is_dir($logDir)) continue;
            
            $files = glob("{$logDir}/*.log*");
            
            foreach ($files as $file) {
                if (filemtime($file) < $cutoffTime) {
                    $archiveFile = $archiveDir . '/' . basename($file);
                    
                    if (rename($file, $archiveFile)) {
                        $this->info("Archived log file", [
                            'original' => $file,
                            'archived' => $archiveFile
                        ], 'maintenance');
                    }
                }
            }
        }
    }
    
    /**
     * Get log statistics
     */
    public function getLogStatistics(int $hours = 24): array {
        if (!$this->enableDatabase) {
            return ['error' => 'Database logging not enabled'];
        }
        
        try {
            $sql = "SELECT 
                        log_level,
                        category,
                        COUNT(*) as count,
                        AVG(execution_time_ms) as avg_execution_time,
                        AVG(memory_usage_mb) as avg_memory_usage
                    FROM ozon_etl_logs 
                    WHERE created_at >= DATE_SUB(NOW(), INTERVAL :hours HOUR)
                    GROUP BY log_level, category
                    ORDER BY count DESC";
            
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute(['hours' => $hours]);
            
            return $stmt->fetchAll();
            
        } catch (Exception $e) {
            $this->error("Failed to get log statistics", ['error' => $e->getMessage()]);
            return [];
        }
    }
    
    /**
     * Search logs
     */
    public function searchLogs(array $criteria, int $limit = 100): array {
        if (!$this->enableDatabase) {
            return ['error' => 'Database logging not enabled'];
        }
        
        try {
            $whereConditions = [];
            $params = [];
            
            if (!empty($criteria['etl_id'])) {
                $whereConditions[] = 'etl_id = :etl_id';
                $params['etl_id'] = $criteria['etl_id'];
            }
            
            if (!empty($criteria['log_level'])) {
                $whereConditions[] = 'log_level = :log_level';
                $params['log_level'] = $criteria['log_level'];
            }
            
            if (!empty($criteria['category'])) {
                $whereConditions[] = 'category = :category';
                $params['category'] = $criteria['category'];
            }
            
            if (!empty($criteria['message_contains'])) {
                $whereConditions[] = 'message LIKE :message_contains';
                $params['message_contains'] = '%' . $criteria['message_contains'] . '%';
            }
            
            if (!empty($criteria['date_from'])) {
                $whereConditions[] = 'created_at >= :date_from';
                $params['date_from'] = $criteria['date_from'];
            }
            
            if (!empty($criteria['date_to'])) {
                $whereConditions[] = 'created_at <= :date_to';
                $params['date_to'] = $criteria['date_to'];
            }
            
            $whereClause = !empty($whereConditions) ? 'WHERE ' . implode(' AND ', $whereConditions) : '';
            
            $sql = "SELECT * FROM ozon_etl_logs 
                    {$whereClause}
                    ORDER BY created_at DESC 
                    LIMIT :limit";
            
            $stmt = $this->pdo->prepare($sql);
            
            foreach ($params as $key => $value) {
                $stmt->bindValue(':' . $key, $value);
            }
            $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
            
            $stmt->execute();
            
            return $stmt->fetchAll();
            
        } catch (Exception $e) {
            $this->error("Failed to search logs", ['error' => $e->getMessage()]);
            return [];
        }
    }
}