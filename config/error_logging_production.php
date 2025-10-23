<?php
/**
 * Production Error Logging Configuration
 * 
 * Enhanced error logging and monitoring for production environment
 */

// Load production configuration
require_once __DIR__ . '/production.php';

// ===================================================================
// ERROR LOGGING CONFIGURATION
// ===================================================================

class ProductionErrorLogger {
    private $log_path;
    private $max_log_size;
    private $max_log_files;
    
    public function __construct() {
        $this->log_path = $_ENV['LOG_PATH'] ?? '/var/log/warehouse-dashboard';
        $this->max_log_size = $this->parseSize($_ENV['LOG_MAX_SIZE'] ?? '100MB');
        $this->max_log_files = (int)($_ENV['LOG_MAX_FILES'] ?? 30);
        
        // Ensure log directory exists
        if (!is_dir($this->log_path)) {
            mkdir($this->log_path, 0755, true);
        }
    }
    
    /**
     * Parse size string to bytes
     */
    private function parseSize($size) {
        $units = ['B' => 1, 'KB' => 1024, 'MB' => 1048576, 'GB' => 1073741824];
        $size = strtoupper($size);
        
        foreach ($units as $unit => $multiplier) {
            if (strpos($size, $unit) !== false) {
                return (int)str_replace($unit, '', $size) * $multiplier;
            }
        }
        
        return (int)$size;
    }
    
    /**
     * Log error with context and rotation
     */
    public function logError($level, $message, $context = [], $file = null, $line = null) {
        $timestamp = date('Y-m-d H:i:s');
        $date = date('Y-m-d');
        
        // Prepare log entry
        $log_entry = [
            'timestamp' => $timestamp,
            'level' => strtoupper($level),
            'message' => $message,
            'context' => $context,
            'file' => $file,
            'line' => $line,
            'request_uri' => $_SERVER['REQUEST_URI'] ?? 'CLI',
            'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? 'CLI',
            'ip_address' => $_SERVER['REMOTE_ADDR'] ?? 'localhost',
            'memory_usage' => memory_get_usage(true),
            'peak_memory' => memory_get_peak_usage(true),
        ];
        
        // Format log entry
        $formatted_entry = sprintf(
            "[%s] %s: %s %s\n",
            $log_entry['timestamp'],
            $log_entry['level'],
            $log_entry['message'],
            json_encode(array_filter([
                'context' => $log_entry['context'],
                'file' => $log_entry['file'],
                'line' => $log_entry['line'],
                'uri' => $log_entry['request_uri'],
                'ip' => $log_entry['ip_address'],
                'memory' => round($log_entry['memory_usage'] / 1024 / 1024, 2) . 'MB'
            ]))
        );
        
        // Write to appropriate log file
        $log_file = $this->log_path . '/warehouse-dashboard-' . $date . '.log';
        file_put_contents($log_file, $formatted_entry, FILE_APPEND | LOCK_EX);
        
        // Write critical errors to separate file
        if (in_array($level, ['error', 'critical', 'emergency'])) {
            $error_file = $this->log_path . '/errors-' . $date . '.log';
            file_put_contents($error_file, $formatted_entry, FILE_APPEND | LOCK_EX);
        }
        
        // Rotate logs if necessary
        $this->rotateLogs($log_file);
        
        // Send alerts for critical errors
        if (in_array($level, ['critical', 'emergency'])) {
            $this->sendAlert($log_entry);
        }
    }
    
    /**
     * Rotate log files if they exceed max size
     */
    private function rotateLogs($log_file) {
        if (!file_exists($log_file)) {
            return;
        }
        
        if (filesize($log_file) > $this->max_log_size) {
            $backup_file = $log_file . '.' . time();
            rename($log_file, $backup_file);
            
            // Compress old log file
            if (function_exists('gzopen')) {
                $this->compressLogFile($backup_file);
            }
            
            // Clean up old log files
            $this->cleanupOldLogs();
        }
    }
    
    /**
     * Compress log file
     */
    private function compressLogFile($file) {
        $gz_file = $file . '.gz';
        $fp_in = fopen($file, 'rb');
        $fp_out = gzopen($gz_file, 'wb9');
        
        if ($fp_in && $fp_out) {
            while (!feof($fp_in)) {
                gzwrite($fp_out, fread($fp_in, 8192));
            }
            fclose($fp_in);
            gzclose($fp_out);
            unlink($file);
        }
    }
    
    /**
     * Clean up old log files
     */
    private function cleanupOldLogs() {
        $files = glob($this->log_path . '/*.log.*');
        if (count($files) > $this->max_log_files) {
            // Sort by modification time
            usort($files, function($a, $b) {
                return filemtime($a) - filemtime($b);
            });
            
            // Remove oldest files
            $files_to_remove = array_slice($files, 0, count($files) - $this->max_log_files);
            foreach ($files_to_remove as $file) {
                unlink($file);
            }
        }
    }
    
    /**
     * Send alert for critical errors
     */
    private function sendAlert($log_entry) {
        // Email alert (if configured)
        if (!empty($_ENV['EMAIL_ALERTS_TO'])) {
            $subject = 'Critical Error - Warehouse Dashboard';
            $message = sprintf(
                "Critical error occurred in Warehouse Dashboard:\n\n" .
                "Time: %s\n" .
                "Level: %s\n" .
                "Message: %s\n" .
                "File: %s:%s\n" .
                "URI: %s\n" .
                "IP: %s\n\n" .
                "Context: %s",
                $log_entry['timestamp'],
                $log_entry['level'],
                $log_entry['message'],
                $log_entry['file'],
                $log_entry['line'],
                $log_entry['request_uri'],
                $log_entry['ip_address'],
                json_encode($log_entry['context'], JSON_PRETTY_PRINT)
            );
            
            mail($_ENV['EMAIL_ALERTS_TO'], $subject, $message);
        }
        
        // Slack webhook (if configured)
        if (!empty($_ENV['SLACK_WEBHOOK_URL'])) {
            $payload = [
                'text' => 'Critical Error - Warehouse Dashboard',
                'attachments' => [
                    [
                        'color' => 'danger',
                        'fields' => [
                            ['title' => 'Level', 'value' => $log_entry['level'], 'short' => true],
                            ['title' => 'Message', 'value' => $log_entry['message'], 'short' => false],
                            ['title' => 'File', 'value' => $log_entry['file'] . ':' . $log_entry['line'], 'short' => true],
                            ['title' => 'URI', 'value' => $log_entry['request_uri'], 'short' => true],
                        ]
                    ]
                ]
            ];
            
            $ch = curl_init($_ENV['SLACK_WEBHOOK_URL']);
            curl_setopt($ch, CURLOPT_POST, 1);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
            curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_exec($ch);
            curl_close($ch);
        }
    }
    
    /**
     * Log API request/response for debugging
     */
    public function logApiCall($endpoint, $method, $params, $response, $duration) {
        $this->logError('info', 'API Call', [
            'endpoint' => $endpoint,
            'method' => $method,
            'params' => $params,
            'response_size' => strlen(json_encode($response)),
            'duration_ms' => round($duration * 1000, 2),
            'status' => isset($response['success']) ? ($response['success'] ? 'success' : 'error') : 'unknown'
        ]);
    }
    
    /**
     * Log database query performance
     */
    public function logSlowQuery($query, $duration, $params = []) {
        if ($duration > 1.0) { // Log queries slower than 1 second
            $this->logError('warning', 'Slow Query', [
                'query' => substr($query, 0, 500) . (strlen($query) > 500 ? '...' : ''),
                'duration_ms' => round($duration * 1000, 2),
                'params' => $params
            ]);
        }
    }
}

// ===================================================================
// GLOBAL ERROR HANDLERS
// ===================================================================

$error_logger = new ProductionErrorLogger();

// Custom error handler
function productionErrorHandler($errno, $errstr, $errfile, $errline) {
    global $error_logger;
    
    $error_types = [
        E_ERROR => 'error',
        E_WARNING => 'warning',
        E_PARSE => 'error',
        E_NOTICE => 'info',
        E_CORE_ERROR => 'critical',
        E_CORE_WARNING => 'warning',
        E_COMPILE_ERROR => 'critical',
        E_COMPILE_WARNING => 'warning',
        E_USER_ERROR => 'error',
        E_USER_WARNING => 'warning',
        E_USER_NOTICE => 'info',
        E_STRICT => 'info',
        E_RECOVERABLE_ERROR => 'error',
        E_DEPRECATED => 'info',
        E_USER_DEPRECATED => 'info',
    ];
    
    $level = $error_types[$errno] ?? 'error';
    $error_logger->logError($level, $errstr, [], $errfile, $errline);
    
    // Don't show errors to users in production
    return true;
}

// Custom exception handler
function productionExceptionHandler($exception) {
    global $error_logger;
    
    $error_logger->logError('critical', $exception->getMessage(), [
        'exception_class' => get_class($exception),
        'trace' => $exception->getTraceAsString()
    ], $exception->getFile(), $exception->getLine());
    
    // Show generic error message to users
    if (php_sapi_name() !== 'cli') {
        http_response_code(500);
        header('Content-Type: application/json');
        echo json_encode([
            'success' => false,
            'error' => 'Internal server error',
            'error_id' => uniqid('err_')
        ]);
        exit;
    }
}

// Set error handlers
set_error_handler('productionErrorHandler');
set_exception_handler('productionExceptionHandler');

// Configure PHP error reporting for production
error_reporting(E_ALL & ~E_DEPRECATED & ~E_STRICT);
ini_set('display_errors', 0);
ini_set('display_startup_errors', 0);
ini_set('log_errors', 1);
ini_set('error_log', $_ENV['LOG_PATH'] . '/php_errors.log');

// ===================================================================
// PERFORMANCE MONITORING
// ===================================================================

class PerformanceMonitor {
    private $start_time;
    private $start_memory;
    private $error_logger;
    
    public function __construct($error_logger) {
        $this->start_time = microtime(true);
        $this->start_memory = memory_get_usage(true);
        $this->error_logger = $error_logger;
    }
    
    public function logRequestEnd() {
        $duration = microtime(true) - $this->start_time;
        $memory_used = memory_get_usage(true) - $this->start_memory;
        $peak_memory = memory_get_peak_usage(true);
        
        // Log slow requests
        if ($duration > 5.0) {
            $this->error_logger->logError('warning', 'Slow Request', [
                'duration_ms' => round($duration * 1000, 2),
                'memory_used_mb' => round($memory_used / 1024 / 1024, 2),
                'peak_memory_mb' => round($peak_memory / 1024 / 1024, 2),
                'uri' => $_SERVER['REQUEST_URI'] ?? 'CLI'
            ]);
        }
        
        // Log high memory usage
        if ($peak_memory > 100 * 1024 * 1024) { // 100MB
            $this->error_logger->logError('warning', 'High Memory Usage', [
                'peak_memory_mb' => round($peak_memory / 1024 / 1024, 2),
                'uri' => $_SERVER['REQUEST_URI'] ?? 'CLI'
            ]);
        }
    }
}

// Start performance monitoring
$performance_monitor = new PerformanceMonitor($error_logger);

// Register shutdown function to log request end
register_shutdown_function(function() use ($performance_monitor) {
    $performance_monitor->logRequestEnd();
});

// Make error logger globally available
$GLOBALS['error_logger'] = $error_logger;

return $error_logger;