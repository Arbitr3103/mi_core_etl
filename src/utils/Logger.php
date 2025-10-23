<?php
/**
 * Logger Utility for mi_core_etl
 * Provides structured logging with multiple channels and formats
 */

class Logger {
    private static $instance = null;
    private $config = [];
    private $logPath = '';
    private $level = 'info';
    private $context = [];
    
    // Log levels (RFC 5424)
    const EMERGENCY = 0;
    const ALERT = 1;
    const CRITICAL = 2;
    const ERROR = 3;
    const WARNING = 4;
    const NOTICE = 5;
    const INFO = 6;
    const DEBUG = 7;
    
    private $levels = [
        'emergency' => self::EMERGENCY,
        'alert' => self::ALERT,
        'critical' => self::CRITICAL,
        'error' => self::ERROR,
        'warning' => self::WARNING,
        'notice' => self::NOTICE,
        'info' => self::INFO,
        'debug' => self::DEBUG,
    ];
    
    private function __construct() {
        $this->loadConfig();
        $this->ensureLogDirectory();
    }
    
    /**
     * Get singleton instance
     */
    public static function getInstance(): Logger {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    /**
     * Load configuration from environment and config file
     */
    private function loadConfig(): void {
        $this->loadEnvFile();
        
        // Load from config file if exists
        $configFile = __DIR__ . '/../../config/logging.php';
        if (file_exists($configFile)) {
            $this->config = require $configFile;
        }
        
        // Set defaults from environment
        $this->logPath = $_ENV['LOG_PATH'] ?? __DIR__ . '/../../storage/logs';
        $this->level = strtolower($_ENV['LOG_LEVEL'] ?? 'info');
        
        // Ensure log path is absolute
        if (!is_dir($this->logPath)) {
            $this->logPath = realpath(__DIR__ . '/../../') . '/storage/logs';
        }
    }
    
    /**
     * Load environment variables from .env file
     */
    private function loadEnvFile(): void {
        $envFile = __DIR__ . '/../../.env';
        
        if (file_exists($envFile)) {
            $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            foreach ($lines as $line) {
                if (strpos($line, '=') !== false && strpos($line, '#') !== 0) {
                    list($key, $value) = explode('=', $line, 2);
                    $key = trim($key);
                    $value = trim($value, '"\'');
                    
                    if (!array_key_exists($key, $_ENV)) {
                        $_ENV[$key] = $value;
                        putenv("$key=$value");
                    }
                }
            }
        }
    }
    
    /**
     * Ensure log directory exists
     */
    private function ensureLogDirectory(): void {
        if (!is_dir($this->logPath)) {
            mkdir($this->logPath, 0755, true);
        }
    }
    
    /**
     * Set global context for all log messages
     */
    public function setContext(array $context): void {
        $this->context = array_merge($this->context, $context);
    }
    
    /**
     * Clear global context
     */
    public function clearContext(): void {
        $this->context = [];
    }
    
    /**
     * Log emergency message
     */
    public function emergency(string $message, array $context = []): void {
        $this->log('emergency', $message, $context);
    }
    
    /**
     * Log alert message
     */
    public function alert(string $message, array $context = []): void {
        $this->log('alert', $message, $context);
    }
    
    /**
     * Log critical message
     */
    public function critical(string $message, array $context = []): void {
        $this->log('critical', $message, $context);
    }
    
    /**
     * Log error message
     */
    public function error(string $message, array $context = []): void {
        $this->log('error', $message, $context);
    }
    
    /**
     * Log warning message
     */
    public function warning(string $message, array $context = []): void {
        $this->log('warning', $message, $context);
    }
    
    /**
     * Log notice message
     */
    public function notice(string $message, array $context = []): void {
        $this->log('notice', $message, $context);
    }
    
    /**
     * Log info message
     */
    public function info(string $message, array $context = []): void {
        $this->log('info', $message, $context);
    }
    
    /**
     * Log debug message
     */
    public function debug(string $message, array $context = []): void {
        $this->log('debug', $message, $context);
    }
    
    /**
     * Log performance metrics
     */
    public function performance(string $operation, float $duration, array $context = []): void {
        $context['operation'] = $operation;
        $context['duration_ms'] = round($duration * 1000, 2);
        $context['memory_usage'] = memory_get_usage(true);
        $context['memory_peak'] = memory_get_peak_usage(true);
        
        $this->info("Performance: {$operation}", $context);
    }
    
    /**
     * Log database query
     */
    public function query(string $sql, array $params = [], ?float $duration = null): void {
        $context = [
            'sql' => $sql,
            'params' => $params,
            'param_count' => count($params)
        ];
        
        if ($duration !== null) {
            $context['duration_ms'] = round($duration * 1000, 2);
        }
        
        $this->debug('Database query executed', $context);
    }
    
    /**
     * Log API request
     */
    public function apiRequest(string $method, string $url, array $headers = [], $body = null, ?int $statusCode = null, ?float $duration = null): void {
        $context = [
            'method' => $method,
            'url' => $url,
            'headers' => $this->sanitizeHeaders($headers),
        ];
        
        if ($body !== null) {
            $context['body_size'] = is_string($body) ? strlen($body) : strlen(json_encode($body));
        }
        
        if ($statusCode !== null) {
            $context['status_code'] = $statusCode;
        }
        
        if ($duration !== null) {
            $context['duration_ms'] = round($duration * 1000, 2);
        }
        
        $level = $statusCode >= 400 ? 'error' : 'info';
        $this->log($level, "API Request: {$method} {$url}", $context);
    }
    
    /**
     * Main logging method
     */
    public function log(string $level, string $message, array $context = []): void {
        $level = strtolower($level);
        
        // Check if level should be logged
        if (!$this->shouldLog($level)) {
            return;
        }
        
        // Merge with global context
        $context = array_merge($this->context, $context);
        
        // Create log entry
        $logEntry = $this->formatLogEntry($level, $message, $context);
        
        // Write to file
        $this->writeToFile($level, $logEntry);
        
        // Write to error log for critical messages
        if ($this->levels[$level] <= self::ERROR) {
            error_log($logEntry);
        }
    }
    
    /**
     * Check if level should be logged
     */
    private function shouldLog(string $level): bool {
        if (!isset($this->levels[$level])) {
            return false;
        }
        
        $currentLevel = $this->levels[$this->level] ?? self::INFO;
        return $this->levels[$level] <= $currentLevel;
    }
    
    /**
     * Format log entry
     */
    private function formatLogEntry(string $level, string $message, array $context): string {
        $timestamp = date('Y-m-d H:i:s');
        $levelUpper = strtoupper($level);
        
        // Basic format
        $entry = "[{$timestamp}] {$levelUpper}: {$message}";
        
        // Add context if present
        if (!empty($context)) {
            $contextJson = json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $entry .= " " . $contextJson;
        }
        
        // Add request ID if available
        if (isset($_SERVER['HTTP_X_REQUEST_ID'])) {
            $entry .= " [request_id:" . $_SERVER['HTTP_X_REQUEST_ID'] . "]";
        }
        
        // Add process ID
        $entry .= " [pid:" . getmypid() . "]";
        
        return $entry;
    }
    
    /**
     * Write log entry to file
     */
    private function writeToFile(string $level, string $entry): void {
        $date = date('Y-m-d');
        $filename = "{$level}_{$date}.log";
        $filepath = $this->logPath . '/' . $filename;
        
        // Ensure directory exists
        $this->ensureLogDirectory();
        
        // Write to file with lock
        file_put_contents($filepath, $entry . PHP_EOL, FILE_APPEND | LOCK_EX);
        
        // Set permissions if file was just created
        if (filesize($filepath) === strlen($entry) + 1) {
            chmod($filepath, 0644);
        }
        
        // Rotate logs if needed
        $this->rotateLogsIfNeeded();
    }
    
    /**
     * Rotate logs if they get too large
     */
    private function rotateLogsIfNeeded(): void {
        $maxSize = 100 * 1024 * 1024; // 100MB
        $maxFiles = 30; // Keep 30 days
        
        $files = glob($this->logPath . '/*.log');
        
        foreach ($files as $file) {
            // Check file size
            if (filesize($file) > $maxSize) {
                $this->rotateFile($file);
            }
        }
        
        // Clean old files
        $this->cleanOldLogs($maxFiles);
    }
    
    /**
     * Rotate a single log file
     */
    private function rotateFile(string $filepath): void {
        $timestamp = date('Y-m-d_H-i-s');
        $rotatedPath = $filepath . '.' . $timestamp;
        
        if (rename($filepath, $rotatedPath)) {
            // Compress if possible
            if (function_exists('gzopen')) {
                $this->compressFile($rotatedPath);
            }
        }
    }
    
    /**
     * Compress log file
     */
    private function compressFile(string $filepath): void {
        $compressedPath = $filepath . '.gz';
        
        $input = fopen($filepath, 'rb');
        $output = gzopen($compressedPath, 'wb9');
        
        if ($input && $output) {
            while (!feof($input)) {
                gzwrite($output, fread($input, 8192));
            }
            
            fclose($input);
            gzclose($output);
            
            // Remove original file
            unlink($filepath);
        }
    }
    
    /**
     * Clean old log files
     */
    private function cleanOldLogs(int $maxFiles): void {
        $files = glob($this->logPath . '/*');
        
        if (count($files) <= $maxFiles) {
            return;
        }
        
        // Sort by modification time
        usort($files, function($a, $b) {
            return filemtime($a) - filemtime($b);
        });
        
        // Remove oldest files
        $filesToRemove = array_slice($files, 0, count($files) - $maxFiles);
        foreach ($filesToRemove as $file) {
            unlink($file);
        }
    }
    
    /**
     * Sanitize headers for logging (remove sensitive data)
     */
    private function sanitizeHeaders(array $headers): array {
        $sensitiveHeaders = ['authorization', 'x-api-key', 'cookie', 'set-cookie'];
        
        $sanitized = [];
        foreach ($headers as $key => $value) {
            $keyLower = strtolower($key);
            if (in_array($keyLower, $sensitiveHeaders)) {
                $sanitized[$key] = '[REDACTED]';
            } else {
                $sanitized[$key] = $value;
            }
        }
        
        return $sanitized;
    }
    
    /**
     * Get log statistics
     */
    public function getStats(): array {
        $stats = [
            'log_path' => $this->logPath,
            'current_level' => $this->level,
            'files' => [],
            'total_size' => 0
        ];
        
        $files = glob($this->logPath . '/*.log');
        foreach ($files as $file) {
            $size = filesize($file);
            $stats['files'][basename($file)] = [
                'size' => $size,
                'size_human' => $this->formatBytes($size),
                'modified' => date('Y-m-d H:i:s', filemtime($file))
            ];
            $stats['total_size'] += $size;
        }
        
        $stats['total_size_human'] = $this->formatBytes($stats['total_size']);
        
        return $stats;
    }
    
    /**
     * Format bytes to human readable format
     */
    private function formatBytes(int $bytes): string {
        $units = ['B', 'KB', 'MB', 'GB'];
        $factor = floor((strlen($bytes) - 1) / 3);
        return sprintf("%.2f %s", $bytes / pow(1024, $factor), $units[$factor]);
    }
    
    /**
     * Prevent cloning
     */
    private function __clone() {}
    
    /**
     * Prevent unserialization
     */
    public function __wakeup() {}
}

/**
 * Helper functions for global access
 */
function logger(): Logger {
    return Logger::getInstance();
}

function logInfo(string $message, array $context = []): void {
    Logger::getInstance()->info($message, $context);
}

function logError(string $message, array $context = []): void {
    Logger::getInstance()->error($message, $context);
}

function logDebug(string $message, array $context = []): void {
    Logger::getInstance()->debug($message, $context);
}

function logPerformance(string $operation, float $duration, array $context = []): void {
    Logger::getInstance()->performance($operation, $duration, $context);
}

function logQuery(string $sql, array $params = [], ?float $duration = null): void {
    Logger::getInstance()->query($sql, $params, $duration);
}

function logApiRequest(string $method, string $url, array $headers = [], $body = null, ?int $statusCode = null, ?float $duration = null): void {
    Logger::getInstance()->apiRequest($method, $url, $headers, $body, $statusCode, $duration);
}