<?php

declare(strict_types=1);

namespace MiCore\ETL\Ozon\Core;

use Exception;

/**
 * ETL Factory Class
 * 
 * Factory for creating ETL framework components with proper
 * dependency injection and configuration management
 */
class ETLFactory
{
    private array $config;
    private ?DatabaseConnection $database = null;
    private ?Logger $logger = null;

    public function __construct(array $config = [])
    {
        $this->config = $config;
    }

    /**
     * Create database connection instance
     * 
     * @return DatabaseConnection Database connection instance
     * @throws Exception When database configuration is missing
     */
    public function createDatabase(): DatabaseConnection
    {
        if ($this->database === null) {
            if (!isset($this->config['database'])) {
                throw new Exception('Database configuration is required');
            }

            $this->database = new DatabaseConnection($this->config['database']);
        }

        return $this->database;
    }

    /**
     * Create logger instance
     * 
     * @param string $name Logger name
     * @return Logger Logger instance
     */
    public function createLogger(string $name = 'ozon-etl'): Logger
    {
        if ($this->logger === null) {
            $loggerConfig = $this->config['logging'] ?? [];
            $this->logger = new Logger($name, $loggerConfig);
        }

        return $this->logger;
    }

    /**
     * Create API client instance (placeholder for future implementation)
     * 
     * @return mixed API client instance
     * @throws Exception When API configuration is missing
     */
    public function createApiClient()
    {
        if (!isset($this->config['api'])) {
            throw new Exception('API configuration is required');
        }

        // This will be implemented in task 4
        throw new Exception('OzonApiClient not yet implemented');
    }

    /**
     * Get configuration value
     * 
     * @param string $key Configuration key (dot notation supported)
     * @param mixed $default Default value if key not found
     * @return mixed Configuration value
     */
    public function getConfig(string $key, $default = null)
    {
        $keys = explode('.', $key);
        $value = $this->config;

        foreach ($keys as $k) {
            if (!is_array($value) || !isset($value[$k])) {
                return $default;
            }
            $value = $value[$k];
        }

        return $value;
    }

    /**
     * Set configuration value
     * 
     * @param string $key Configuration key (dot notation supported)
     * @param mixed $value Configuration value
     * @return void
     */
    public function setConfig(string $key, $value): void
    {
        $keys = explode('.', $key);
        $config = &$this->config;

        foreach ($keys as $k) {
            if (!isset($config[$k]) || !is_array($config[$k])) {
                $config[$k] = [];
            }
            $config = &$config[$k];
        }

        $config = $value;
    }

    /**
     * Get all configuration
     * 
     * @return array Complete configuration array
     */
    public function getAllConfig(): array
    {
        return $this->config;
    }
}