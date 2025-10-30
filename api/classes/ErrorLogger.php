<?php
/**
 * Comprehensive Error Logger
 * 
 * Centralized error logging system with structured logging,
 * log rotation, archiving, and alerting capabilities.
 * 
 * Requirements: 7.1, 7.2, 7.3, 7.4
 */

class ErrorLogger {
    private $log_base_path;
    private $max_log_size;
    private $max_log_files;
    private $alert_config;
    private $log_levels = [
        'debug' => 0,
        'info' => 1,
        'notice' => 2,
        'warning' => 3,
        'error' => 4,
        'critical' => 5,
        'alert' => 6,
        'emergency' => 7
    ];
    private $current_log_level;
    
    /**
     * Initialize error logger
     */
    public function __construct(array $config = []) {
        $this->log_base_path = $config['log_path'] ?? __DIR__ . '/../../logs';
        $this->max_log_size = $this->parseSize($config['max_log_size'] ?? '50MB');
        $this->max_log_files = $config['max_log_files'] ?? 30;
        $this->current_log_level = $config['log_level'] ?? 'info';
        $this->alert_config = $config['alerts'] ?? [];
        
        // Ensure log directories exist
        $this->ensureLogDirectories();
    }
    
    /**
     * Ensure all required log directories exist
     */
    private function ensureLogDirectories(): void {
        $directories = [
            $this->log_base_path,
            $this->log_base_path . '/frontend',
            $this->log_base_path . '/api',
            $this->log_base_path . '/etl',
            $this->log_base_path . '/monitoring',
            $this->log_base_path . '/archive'
        ];
        
        foreach ($directories as $dir) {
            if (!is_dir($dir)) {
                mkdir($dir, 0755, true);
            }
        }
    }
    
    /**
     * Parse size string to bytes
     */
    private function parseSize(string $size): int {
        $units = ['B' => 1, 'KB' => 1024, 'MB' => 1048576, 'GB' => 1073741824];
        $size = strtoupper(trim($size));
        
        foreach ($units as $unit => $multiplier) {
            if (strpos($size, $unit) !== false) {
                return (int)str_replace($unit, '', $size) * $multiplier;
            }
        }
        
        return (int)$size;
    }
    
    /**
     * Log a message with specified level
     */
    public function log(string $level, string $message, array $context = [], string $component = 'general'): bool {
        // Check if we should log this level
        if (!$this->shouldLog($level)) {
            return false;
        }
        
        $timestamp = date('Y-m-d H:i:s');
        $date = date('Y-m-d');
        
        // Build structured log entry
        $log_entry = [
            'timestamp' => $timestamp,
            'level' => strtoupper($level),
            'component' => $component,
            'message' => $message,
            'context' => $context,
            'server' => [
                'request_uri' => $_SERVER['REQUEST_URI'] ?? 'CLI',
                'request_method' => $_SERVER['REQUEST_METHOD'] ?? 'CLI',
                'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? 'CLI',
                'ip_address' => $_SERVER['REMOTE_ADDR'] ?? 'localhost',
                'server_name' => $_SERVER['SERVER_NAME'] ?? gethostname()
            ],
            'runtime' => [
                'memory_usage' => memory_get_usage(true),
                'peak_memory' => memory_get_peak_usage(true),
                'execution_time' => microtime(true) - ($_SERVER['REQUEST_TIME_FLOAT'] ?? microtime(true))
            ],
            'trace_id' => $this->getTraceId()
        ];
        
        // Write to component-specific log file
        $log_file = $this->getLogFilePath($component, $date);
        $success = $this->writeLogEntry($log_file, $log_entry);
        
        // Also write to level-specific log for errors and above
        if ($this->log_levels[$level] >= $this->log_levels['error']) {
            $error_log_file = $this->log_base_path . '/errors-' . $date . '.log';
            $this->writeLogEntry($error_log_file, $log_entry);
        }
        
        // Check if log rotation is needed
        $this->checkAndRotateLog($log_file);
        
        // Send alerts for critical levels
        if ($this->log_levels[$level] >= $this->log_levels['critical']) {
            $this->sendAlert($log_entry);
        }
        
        return $success;
    }
    
    /**
     * Check if we should log this level
     */
    private function shouldLog(string $level): bool {
        $current_level_value = $this->log_levels[$this->current_log_level] ?? 1;
        $message_level_value = $this->log_levels[$level] ?? 1;
        
        return $message_level_value >= $current_level_value;
    }
    
    /**
     * Get or generate trace ID for request tracking
     */
    private function getTraceId(): string {
        static $trace_id = null;
        
        if ($trace_id === null) {
            $trace_id = $_SERVER['HTTP_X_TRACE_ID'] ?? uniqid('trace_', true);
        }
        
        return $trace_id;
    }
    
    /**
     * Get log file path for component and date
     */
    private function getLogFilePath(string $component, string $date): string {
        $component_dir = $this->log_base_path;
        
        // Map components to directories
        $component_map = [
            'frontend' => '/frontend',
            'react' => '/frontend',
            'api' => '/api',
            'etl' => '/etl',
            'importer' => '/etl',
            'monitoring' => '/monitoring'
        ];
        
        foreach ($component_map as $key => $dir) {
            if (stripos($component, $key) !== false) {
                $component_dir .= $dir;
                break;
            }
        }
        
        return $component_dir . '/' . $component . '-' . $date . '.log';
    }
    
    /**
     * Write log entry to file
     */
    private function writeLogEntry(string $log_file, array $log_entry): bool {
        // Format as JSON for structured logging
        $formatted_entry = json_encode($log_entry, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
        
        // Write with file locking
        $result = file_put_contents($log_file, $formatted_entry, FILE_APPEND | LOCK_EX);
        
        return $result !== false;
    }
    
    /**
     * Check and rotate log file if needed
     */
    private function checkAndRotateLog(string $log_file): void {
        if (!file_exists($log_file)) {
            return;
        }
        
        $file_size = filesize($log_file);
        
        if ($file_size > $this->max_log_size) {
            $this->rotateLogFile($log_file);
        }
    }
    
    /**
     * Rotate log file
     */
    private function rotateLogFile(string $log_file): void {
        $timestamp = time();
        $backup_file = $log_file . '.' . $timestamp;
        
        // Rename current log file
        if (rename($log_file, $backup_file)) {
            // Compress the rotated file
            $this->compressLogFile($backup_file);
            
            // Clean up old log files
            $this->cleanupOldLogs(dirname($log_file));
        }
    }
    
    /**
     * Compress log file using gzip
     */
    private function compressLogFile(string $file): void {
        if (!function_exists('gzopen')) {
            return;
        }
        
        $gz_file = $file . '.gz';
        $fp_in = fopen($file, 'rb');
        $fp_out = gzopen($gz_file, 'wb9');
        
        if ($fp_in && $fp_out) {
            while (!feof($fp_in)) {
                $chunk = fread($fp_in, 8192);
                gzwrite($fp_out, $chunk);
            }
            fclose($fp_in);
            gzclose($fp_out);
            
            // Remove original file after compression
            unlink($file);
            
            // Move to archive directory
            $archive_dir = $this->log_base_path . '/archive';
            $archive_file = $archive_dir . '/' . basename($gz_file);
            rename($gz_file, $archive_file);
        }
    }
    
    /**
     * Clean up old log files
     */
    private function cleanupOldLogs(string $directory): void {
        // Get all rotated log files
        $pattern = $directory . '/*.log.*';
        $files = glob($pattern);
        
        if (count($files) > $this->max_log_files) {
            // Sort by modification time (oldest first)
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
    private function sendAlert(array $log_entry): void {
        // Email alerts
        if (!empty($this->alert_config['email'])) {
            $this->sendEmailAlert($log_entry);
        }
        
        // Slack alerts
        if (!empty($this->alert_config['slack_webhook'])) {
            $this->sendSlackAlert($log_entry);
        }
        
        // Telegram alerts
        if (!empty($this->alert_config['telegram'])) {
            $this->sendTelegramAlert($log_entry);
        }
        
        // Write to alert log
        $alert_log = $this->log_base_path . '/alerts-' . date('Y-m-d') . '.log';
        $this->writeLogEntry($alert_log, $log_entry);
    }
    
    /**
     * Send email alert
     */
    private function sendEmailAlert(array $log_entry): void {
        $to = $this->alert_config['email'];
        $subject = sprintf('[%s] %s - Warehouse Dashboard', 
            $log_entry['level'], 
            $log_entry['component']
        );
        
        $message = sprintf(
            "Critical error occurred in Warehouse Dashboard:\n\n" .
            "Time: %s\n" .
            "Level: %s\n" .
            "Component: %s\n" .
            "Message: %s\n" .
            "Trace ID: %s\n" .
            "Server: %s\n" .
            "URI: %s\n" .
            "IP: %s\n\n" .
            "Context:\n%s\n\n" .
            "Runtime Info:\n" .
            "Memory: %.2f MB\n" .
            "Peak Memory: %.2f MB\n" .
            "Execution Time: %.3f seconds",
            $log_entry['timestamp'],
            $log_entry['level'],
            $log_entry['component'],
            $log_entry['message'],
            $log_entry['trace_id'],
            $log_entry['server']['server_name'],
            $log_entry['server']['request_uri'],
            $log_entry['server']['ip_address'],
            json_encode($log_entry['context'], JSON_PRETTY_PRINT),
            $log_entry['runtime']['memory_usage'] / 1024 / 1024,
            $log_entry['runtime']['peak_memory'] / 1024 / 1024,
            $log_entry['runtime']['execution_time']
        );
        
        $headers = [
            'From: noreply@market-mi.ru',
            'X-Priority: 1',
            'X-MSMail-Priority: High',
            'Importance: High'
        ];
        
        mail($to, $subject, $message, implode("\r\n", $headers));
    }
    
    /**
     * Send Slack alert
     */
    private function sendSlackAlert(array $log_entry): void {
        $webhook_url = $this->alert_config['slack_webhook'];
        
        $color_map = [
            'CRITICAL' => 'danger',
            'ALERT' => 'danger',
            'EMERGENCY' => 'danger',
            'ERROR' => 'warning',
            'WARNING' => 'warning'
        ];
        
        $payload = [
            'text' => sprintf('*[%s]* %s', $log_entry['level'], $log_entry['component']),
            'attachments' => [
                [
                    'color' => $color_map[$log_entry['level']] ?? 'danger',
                    'fields' => [
                        [
                            'title' => 'Message',
                            'value' => $log_entry['message'],
                            'short' => false
                        ],
                        [
                            'title' => 'Time',
                            'value' => $log_entry['timestamp'],
                            'short' => true
                        ],
                        [
                            'title' => 'Trace ID',
                            'value' => $log_entry['trace_id'],
                            'short' => true
                        ],
                        [
                            'title' => 'Server',
                            'value' => $log_entry['server']['server_name'],
                            'short' => true
                        ],
                        [
                            'title' => 'URI',
                            'value' => $log_entry['server']['request_uri'],
                            'short' => true
                        ]
                    ],
                    'footer' => 'Warehouse Dashboard',
                    'ts' => time()
                ]
            ]
        ];
        
        $ch = curl_init($webhook_url);
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 5);
        curl_exec($ch);
        curl_close($ch);
    }
    
    /**
     * Send Telegram alert
     */
    private function sendTelegramAlert(array $log_entry): void {
        $bot_token = $this->alert_config['telegram']['bot_token'] ?? '';
        $chat_id = $this->alert_config['telegram']['chat_id'] ?? '';
        
        if (empty($bot_token) || empty($chat_id)) {
            return;
        }
        
        $message = sprintf(
            "🚨 *[%s]* %s\n\n" .
            "*Message:* %s\n" .
            "*Time:* %s\n" .
            "*Server:* %s\n" .
            "*URI:* %s\n" .
            "*Trace ID:* `%s`",
            $log_entry['level'],
            $log_entry['component'],
            $log_entry['message'],
            $log_entry['timestamp'],
            $log_entry['server']['server_name'],
            $log_entry['server']['request_uri'],
            $log_entry['trace_id']
        );
        
        $url = "https://api.telegram.org/bot{$bot_token}/sendMessage";
        $data = [
            'chat_id' => $chat_id,
            'text' => $message,
            'parse_mode' => 'Markdown'
        ];
        
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 5);
        curl_exec($ch);
        curl_close($ch);
    }
    
    /**
     * Convenience methods for different log levels
     */
    public function debug(string $message, array $context = [], string $component = 'general'): bool {
        return $this->log('debug', $message, $context, $component);
    }
    
    public function info(string $message, array $context = [], string $component = 'general'): bool {
        return $this->log('info', $message, $context, $component);
    }
    
    public function notice(string $message, array $context = [], string $component = 'general'): bool {
        return $this->log('notice', $message, $context, $component);
    }
    
    public function warning(string $message, array $context = [], string $component = 'general'): bool {
        return $this->log('warning', $message, $context, $component);
    }
    
    public function error(string $message, array $context = [], string $component = 'general'): bool {
        return $this->log('error', $message, $context, $component);
    }
    
    public function critical(string $message, array $context = [], string $component = 'general'): bool {
        return $this->log('critical', $message, $context, $component);
    }
    
    public function alert(string $message, array $context = [], string $component = 'general'): bool {
        return $this->log('alert', $message, $context, $component);
    }
    
    public function emergency(string $message, array $context = [], string $component = 'general'): bool {
        return $this->log('emergency', $message, $context, $component);
    }
    
    /**
     * Log API call with timing
     */
    public function logApiCall(string $endpoint, string $method, array $params, $response, float $duration): void {
        $this->info('API Call', [
            'endpoint' => $endpoint,
            'method' => $method,
            'params' => $params,
            'response_size' => strlen(json_encode($response)),
            'duration_ms' => round($duration * 1000, 2),
            'status' => isset($response['success']) ? ($response['success'] ? 'success' : 'error') : 'unknown'
        ], 'api');
    }
    
    /**
     * Log slow database query
     */
    public function logSlowQuery(string $query, float $duration, array $params = []): void {
        if ($duration > 1.0) {
            $this->warning('Slow Query', [
                'query' => substr($query, 0, 500) . (strlen($query) > 500 ? '...' : ''),
                'duration_ms' => round($duration * 1000, 2),
                'params' => $params
            ], 'database');
        }
    }
    
    /**
     * Get log statistics
     */
    public function getLogStats(string $component = null, int $days = 7): array {
        $stats = [
            'total_logs' => 0,
            'by_level' => [],
            'by_component' => [],
            'by_day' => [],
            'recent_errors' => []
        ];
        
        // Scan log files for the specified period
        for ($i = 0; $i < $days; $i++) {
            $date = date('Y-m-d', strtotime("-{$i} days"));
            $pattern = $this->log_base_path . '/**/*-' . $date . '.log';
            
            foreach (glob($pattern) as $log_file) {
                $this->processLogFileStats($log_file, $stats);
            }
        }
        
        return $stats;
    }
    
    /**
     * Process log file for statistics
     */
    private function processLogFileStats(string $log_file, array &$stats): void {
        $handle = fopen($log_file, 'r');
        if (!$handle) {
            return;
        }
        
        while (($line = fgets($handle)) !== false) {
            $entry = json_decode($line, true);
            if (!$entry) {
                continue;
            }
            
            $stats['total_logs']++;
            
            // Count by level
            $level = $entry['level'] ?? 'UNKNOWN';
            $stats['by_level'][$level] = ($stats['by_level'][$level] ?? 0) + 1;
            
            // Count by component
            $component = $entry['component'] ?? 'unknown';
            $stats['by_component'][$component] = ($stats['by_component'][$component] ?? 0) + 1;
            
            // Count by day
            $day = substr($entry['timestamp'] ?? '', 0, 10);
            $stats['by_day'][$day] = ($stats['by_day'][$day] ?? 0) + 1;
            
            // Collect recent errors
            if (in_array($level, ['ERROR', 'CRITICAL', 'ALERT', 'EMERGENCY'])) {
                $stats['recent_errors'][] = [
                    'timestamp' => $entry['timestamp'],
                    'level' => $level,
                    'component' => $component,
                    'message' => $entry['message']
                ];
            }
        }
        
        fclose($handle);
        
        // Keep only last 50 errors
        if (count($stats['recent_errors']) > 50) {
            $stats['recent_errors'] = array_slice($stats['recent_errors'], -50);
        }
    }
}
