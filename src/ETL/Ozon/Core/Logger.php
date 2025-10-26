<?php

declare(strict_types=1);

namespace MiCore\ETL\Ozon\Core;

use Exception;
use Monolog\Logger as MonologLogger;
use Monolog\Handler\StreamHandler;
use Monolog\Handler\RotatingFileHandler;
use Monolog\Formatter\JsonFormatter;
use Monolog\Formatter\LineFormatter;
use Monolog\Processor\ProcessIdProcessor;
use Monolog\Processor\MemoryUsageProcessor;
use Monolog\Processor\IntrospectionProcessor;

/**
 * Logger Class
 * 
 * Provides structured logging in JSON format with log rotation,
 * multiple log levels, and integration with alert systems
 */
class Logger
{
    private MonologLogger $logger;
    private array $config;
    private array $alertHandlers = [];

    public function __construct(string $name = 'ozon-etl', array $config = [])
    {
        $this->config = array_merge($this->getDefaultConfig(), $config);
        $this->logger = new MonologLogger($name);
        $this->setupHandlers();
        $this->setupProcessors();
    }

    /**
     * Get default logger configuration
     * 
     * @return array Default configuration
     */
    private function getDefaultConfig(): array
    {
        return [
            'level' => 'INFO',
            'path' => __DIR__ . '/../Logs',
            'filename' => 'ozon-etl.log',
            'max_files' => 30,
            'format' => 'json',
            'include_context' => true,
            'include_extra' => true,
            'rotation' => true,
            'console_output' => false,
            'alerts' => [
                'enabled' => false,
                'levels' => ['ERROR', 'CRITICAL'],
                'handlers' => []
            ]
        ];
    }

    /**
     * Setup log handlers based on configuration
     * 
     * @return void
     * @throws Exception When handler setup fails
     */
    private function setupHandlers(): void
    {
        // Ensure log directory exists
        if (!is_dir($this->config['path'])) {
            if (!mkdir($this->config['path'], 0755, true)) {
                throw new Exception('Failed to create log directory: ' . $this->config['path']);
            }
        }

        $logLevel = $this->getLogLevel($this->config['level']);
        $logFile = $this->config['path'] . '/' . $this->config['filename'];

        // File handler with rotation
        if ($this->config['rotation']) {
            $fileHandler = new RotatingFileHandler(
                $logFile,
                $this->config['max_files'],
                $logLevel
            );
        } else {
            $fileHandler = new StreamHandler($logFile, $logLevel);
        }

        // Set formatter based on configuration
        if ($this->config['format'] === 'json') {
            $formatter = new JsonFormatter();
        } else {
            $formatter = new LineFormatter(
                "[%datetime%] %channel%.%level_name%: %message% %context% %extra%\n",
                'Y-m-d H:i:s'
            );
        }

        $fileHandler->setFormatter($formatter);
        $this->logger->pushHandler($fileHandler);

        // Console output handler
        if ($this->config['console_output']) {
            $consoleHandler = new StreamHandler('php://stdout', $logLevel);
            $consoleFormatter = new LineFormatter(
                "[%datetime%] %level_name%: %message% %context%\n",
                'H:i:s'
            );
            $consoleHandler->setFormatter($consoleFormatter);
            $this->logger->pushHandler($consoleHandler);
        }
    }

    /**
     * Setup log processors for additional context
     * 
     * @return void
     */
    private function setupProcessors(): void
    {
        // Add process ID
        $this->logger->pushProcessor(new ProcessIdProcessor());
        
        // Add memory usage
        $this->logger->pushProcessor(new MemoryUsageProcessor());
        
        // Add introspection (file, line, class, method)
        $this->logger->pushProcessor(new IntrospectionProcessor(
            MonologLogger::DEBUG,
            ['MiCore\\ETL\\Ozon\\Core\\Logger']
        ));

        // Add custom ETL processor
        $this->logger->pushProcessor([$this, 'addETLContext']);
    }

    /**
     * Add ETL-specific context to log records
     * 
     * @param array $record Log record
     * @return array Modified log record
     */
    public function addETLContext(array $record): array
    {
        $record['extra']['etl_system'] = 'ozon';
        $record['extra']['timestamp_utc'] = gmdate('Y-m-d\TH:i:s\Z');
        $record['extra']['server'] = gethostname();
        
        // Add execution context if available
        if (defined('ETL_EXECUTION_ID')) {
            $record['extra']['execution_id'] = ETL_EXECUTION_ID;
        }

        return $record;
    }

    /**
     * Convert log level string to Monolog constant
     * 
     * @param string $level Log level string
     * @return int Monolog log level constant
     */
    private function getLogLevel(string $level): int
    {
        $levels = [
            'DEBUG' => MonologLogger::DEBUG,
            'INFO' => MonologLogger::INFO,
            'NOTICE' => MonologLogger::NOTICE,
            'WARNING' => MonologLogger::WARNING,
            'ERROR' => MonologLogger::ERROR,
            'CRITICAL' => MonologLogger::CRITICAL,
            'ALERT' => MonologLogger::ALERT,
            'EMERGENCY' => MonologLogger::EMERGENCY,
        ];

        return $levels[strtoupper($level)] ?? MonologLogger::INFO;
    }

    /**
     * Log debug message
     * 
     * @param string $message Log message
     * @param array $context Additional context
     * @return void
     */
    public function debug(string $message, array $context = []): void
    {
        $this->logger->debug($message, $this->prepareContext($context));
    }

    /**
     * Log info message
     * 
     * @param string $message Log message
     * @param array $context Additional context
     * @return void
     */
    public function info(string $message, array $context = []): void
    {
        $this->logger->info($message, $this->prepareContext($context));
    }

    /**
     * Log notice message
     * 
     * @param string $message Log message
     * @param array $context Additional context
     * @return void
     */
    public function notice(string $message, array $context = []): void
    {
        $this->logger->notice($message, $this->prepareContext($context));
    }

    /**
     * Log warning message
     * 
     * @param string $message Log message
     * @param array $context Additional context
     * @return void
     */
    public function warning(string $message, array $context = []): void
    {
        $this->logger->warning($message, $this->prepareContext($context));
    }

    /**
     * Log error message
     * 
     * @param string $message Log message
     * @param array $context Additional context
     * @return void
     */
    public function error(string $message, array $context = []): void
    {
        $context = $this->prepareContext($context);
        $this->logger->error($message, $context);
        $this->handleAlert('ERROR', $message, $context);
    }

    /**
     * Log critical message
     * 
     * @param string $message Log message
     * @param array $context Additional context
     * @return void
     */
    public function critical(string $message, array $context = []): void
    {
        $context = $this->prepareContext($context);
        $this->logger->critical($message, $context);
        $this->handleAlert('CRITICAL', $message, $context);
    }

    /**
     * Log alert message
     * 
     * @param string $message Log message
     * @param array $context Additional context
     * @return void
     */
    public function alert(string $message, array $context = []): void
    {
        $context = $this->prepareContext($context);
        $this->logger->alert($message, $context);
        $this->handleAlert('ALERT', $message, $context);
    }

    /**
     * Log emergency message
     * 
     * @param string $message Log message
     * @param array $context Additional context
     * @return void
     */
    public function emergency(string $message, array $context = []): void
    {
        $context = $this->prepareContext($context);
        $this->logger->emergency($message, $context);
        $this->handleAlert('EMERGENCY', $message, $context);
    }

    /**
     * Prepare context data for logging
     * 
     * @param array $context Context data
     * @return array Prepared context
     */
    private function prepareContext(array $context): array
    {
        // Sanitize sensitive data
        $sanitized = $this->sanitizeContext($context);
        
        // Add timestamp if not present
        if (!isset($sanitized['timestamp'])) {
            $sanitized['timestamp'] = date('Y-m-d H:i:s');
        }

        return $sanitized;
    }

    /**
     * Sanitize context to remove sensitive information
     * 
     * @param array $context Context data
     * @return array Sanitized context
     */
    private function sanitizeContext(array $context): array
    {
        $sensitiveKeys = [
            'password', 'passwd', 'pwd',
            'api_key', 'apikey', 'key',
            'secret', 'token', 'auth',
            'client_secret', 'private_key'
        ];

        array_walk_recursive($context, function (&$value, $key) use ($sensitiveKeys) {
            if (in_array(strtolower($key), $sensitiveKeys)) {
                $value = '***REDACTED***';
            }
        });

        return $context;
    }

    /**
     * Handle alert notifications for critical log levels
     * 
     * @param string $level Log level
     * @param string $message Log message
     * @param array $context Log context
     * @return void
     */
    private function handleAlert(string $level, string $message, array $context): void
    {
        if (!$this->config['alerts']['enabled']) {
            return;
        }

        if (!in_array($level, $this->config['alerts']['levels'])) {
            return;
        }

        $alertData = [
            'level' => $level,
            'message' => $message,
            'context' => $context,
            'timestamp' => date('Y-m-d H:i:s'),
            'system' => 'ozon-etl'
        ];

        foreach ($this->alertHandlers as $handler) {
            try {
                $handler($alertData);
            } catch (Exception $e) {
                // Log alert handler failure but don't throw
                $this->logger->error('Alert handler failed', [
                    'handler' => get_class($handler),
                    'error' => $e->getMessage()
                ]);
            }
        }
    }

    /**
     * Add alert handler
     * 
     * @param callable $handler Alert handler function
     * @return void
     */
    public function addAlertHandler(callable $handler): void
    {
        $this->alertHandlers[] = $handler;
    }

    /**
     * Log ETL metrics
     * 
     * @param string $etlClass ETL class name
     * @param array $metrics Metrics data
     * @return void
     */
    public function logMetrics(string $etlClass, array $metrics): void
    {
        $this->info('ETL Metrics', [
            'etl_class' => $etlClass,
            'metrics' => $metrics,
            'type' => 'metrics'
        ]);
    }

    /**
     * Log ETL performance data
     * 
     * @param string $operation Operation name
     * @param float $duration Duration in seconds
     * @param array $additionalData Additional performance data
     * @return void
     */
    public function logPerformance(string $operation, float $duration, array $additionalData = []): void
    {
        $this->info('Performance Log', array_merge([
            'operation' => $operation,
            'duration_seconds' => $duration,
            'type' => 'performance'
        ], $additionalData));
    }

    /**
     * Create child logger with additional context
     * 
     * @param array $context Additional context for all logs
     * @return self New logger instance with context
     */
    public function withContext(array $context): self
    {
        $childLogger = clone $this;
        $childLogger->logger = $this->logger->withName($this->logger->getName());
        
        // Add context processor
        $childLogger->logger->pushProcessor(function ($record) use ($context) {
            $record['context'] = array_merge($record['context'], $context);
            return $record;
        });

        return $childLogger;
    }

    /**
     * Get log file path
     * 
     * @return string Log file path
     */
    public function getLogFile(): string
    {
        return $this->config['path'] . '/' . $this->config['filename'];
    }

    /**
     * Get log directory path
     * 
     * @return string Log directory path
     */
    public function getLogDirectory(): string
    {
        return $this->config['path'];
    }

    /**
     * Get logger statistics
     * 
     * @return array Logger statistics
     */
    public function getStats(): array
    {
        $logFile = $this->getLogFile();
        
        return [
            'log_file' => $logFile,
            'log_file_exists' => file_exists($logFile),
            'log_file_size' => file_exists($logFile) ? filesize($logFile) : 0,
            'log_level' => $this->config['level'],
            'handlers_count' => count($this->logger->getHandlers()),
            'processors_count' => count($this->logger->getProcessors()),
            'alert_handlers_count' => count($this->alertHandlers)
        ];
    }

    /**
     * Rotate log files manually
     * 
     * @return bool True if rotation was successful
     */
    public function rotateLogs(): bool
    {
        try {
            foreach ($this->logger->getHandlers() as $handler) {
                if ($handler instanceof RotatingFileHandler) {
                    $handler->close();
                }
            }
            return true;
        } catch (Exception $e) {
            $this->error('Log rotation failed', ['error' => $e->getMessage()]);
            return false;
        }
    }
}