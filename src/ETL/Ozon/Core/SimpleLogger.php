<?php

declare(strict_types=1);

namespace MiCore\ETL\Ozon\Core;

/**
 * Simple Logger Class
 * 
 * Basic logging implementation without external dependencies
 * for testing and development purposes.
 */
class SimpleLogger
{
    private array $config;
    private string $logFile;
    
    public function __construct(string $name = 'ozon-etl', array $config = [])
    {
        $this->config = array_merge($this->getDefaultConfig(), $config);
        $this->logFile = $this->config['path'] . '/' . $this->config['filename'];
        
        // Ensure log directory exists
        if (!is_dir($this->config['path'])) {
            mkdir($this->config['path'], 0755, true);
        }
    }
    
    private function getDefaultConfig(): array
    {
        return [
            'path' => __DIR__ . '/../Logs',
            'filename' => 'ozon-etl.log',
            'level' => 'INFO'
        ];
    }
    
    public function debug(string $message, array $context = []): void
    {
        $this->log('DEBUG', $message, $context);
    }
    
    public function info(string $message, array $context = []): void
    {
        $this->log('INFO', $message, $context);
    }
    
    public function warning(string $message, array $context = []): void
    {
        $this->log('WARNING', $message, $context);
    }
    
    public function error(string $message, array $context = []): void
    {
        $this->log('ERROR', $message, $context);
    }
    
    public function critical(string $message, array $context = []): void
    {
        $this->log('CRITICAL', $message, $context);
    }
    
    private function log(string $level, string $message, array $context): void
    {
        $timestamp = date('Y-m-d H:i:s');
        $pid = getmypid();
        $contextStr = !empty($context) ? ' ' . json_encode($context) : '';
        
        $logLine = "[{$timestamp}] {$level} (PID:{$pid}): {$message}{$contextStr}\n";
        
        file_put_contents($this->logFile, $logLine, FILE_APPEND | LOCK_EX);
        
        // Also output to console if verbose
        if (isset($_SERVER['argv']) && in_array('--verbose', $_SERVER['argv'])) {
            echo $logLine;
        }
    }
    
    public function getLogDirectory(): string
    {
        return $this->config['path'];
    }
    
    public function getLogFile(): string
    {
        return $this->logFile;
    }
}