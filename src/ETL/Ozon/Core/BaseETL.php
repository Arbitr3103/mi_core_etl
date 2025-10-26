<?php

declare(strict_types=1);

namespace MiCore\ETL\Ozon\Core;

use Exception;
use MiCore\ETL\Ozon\Core\DatabaseConnection;
use MiCore\ETL\Ozon\Core\Logger;
use MiCore\ETL\Ozon\Api\OzonApiClient;

/**
 * Abstract Base ETL Class
 * 
 * Provides the foundation for all ETL processes in the Ozon system.
 * Implements the ETL pattern with extract, transform, load methods
 * and includes comprehensive error handling, logging, and monitoring.
 */
abstract class BaseETL
{
    protected DatabaseConnection $db;
    protected Logger $logger;
    protected OzonApiClient $apiClient;
    protected array $config;
    protected array $metrics;

    public function __construct(
        DatabaseConnection $db,
        Logger $logger,
        OzonApiClient $apiClient,
        array $config = []
    ) {
        $this->db = $db;
        $this->logger = $logger;
        $this->apiClient = $apiClient;
        $this->config = $config;
        $this->metrics = [
            'records_extracted' => 0,
            'records_transformed' => 0,
            'records_loaded' => 0,
            'errors_count' => 0,
            'start_time' => null,
            'end_time' => null,
            'duration' => 0
        ];
    }

    /**
     * Extract data from source system
     * 
     * @return array Raw data from the source
     * @throws Exception When extraction fails
     */
    abstract public function extract(): array;

    /**
     * Transform raw data into target format
     * 
     * @param array $data Raw data to transform
     * @return array Transformed data ready for loading
     * @throws Exception When transformation fails
     */
    abstract public function transform(array $data): array;

    /**
     * Load transformed data into target system
     * 
     * @param array $data Transformed data to load
     * @return void
     * @throws Exception When loading fails
     */
    abstract public function load(array $data): void;

    /**
     * Execute the complete ETL process
     * 
     * Orchestrates the extract, transform, and load operations
     * with comprehensive error handling and monitoring
     * 
     * @return ETLResult Result of the ETL execution
     * @throws Exception When ETL process fails
     */
    public function execute(): ETLResult
    {
        $this->metrics['start_time'] = microtime(true);
        $etlClass = get_class($this);

        try {
            $this->logger->info('Starting ETL process', [
                'class' => $etlClass,
                'config' => $this->sanitizeConfig($this->config)
            ]);

            // Log ETL execution start
            $executionId = $this->logETLExecution('started');

            // Extract phase
            $this->logger->info('Starting extraction phase', ['class' => $etlClass]);
            $rawData = $this->extract();
            $this->metrics['records_extracted'] = count($rawData);
            
            $this->logger->info('Extraction completed', [
                'class' => $etlClass,
                'records_extracted' => $this->metrics['records_extracted']
            ]);

            // Transform phase
            $this->logger->info('Starting transformation phase', ['class' => $etlClass]);
            $transformedData = $this->transform($rawData);
            $this->metrics['records_transformed'] = count($transformedData);
            
            $this->logger->info('Transformation completed', [
                'class' => $etlClass,
                'records_transformed' => $this->metrics['records_transformed']
            ]);

            // Load phase
            $this->logger->info('Starting load phase', ['class' => $etlClass]);
            $this->load($transformedData);
            $this->metrics['records_loaded'] = $this->metrics['records_transformed'];
            
            $this->logger->info('Load completed', [
                'class' => $etlClass,
                'records_loaded' => $this->metrics['records_loaded']
            ]);

            // Calculate final metrics
            $this->metrics['end_time'] = microtime(true);
            $this->metrics['duration'] = $this->metrics['end_time'] - $this->metrics['start_time'];

            $this->logger->info('ETL process completed successfully', [
                'class' => $etlClass,
                'metrics' => $this->metrics
            ]);

            // Update ETL execution log
            $this->updateETLExecution($executionId, 'completed', $this->metrics);

            return new ETLResult(
                true,
                $this->metrics['records_loaded'],
                $this->metrics['duration'],
                $this->metrics
            );

        } catch (Exception $e) {
            $this->metrics['errors_count']++;
            $this->metrics['end_time'] = microtime(true);
            $this->metrics['duration'] = $this->metrics['end_time'] - $this->metrics['start_time'];

            $this->logger->error('ETL process failed', [
                'class' => $etlClass,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'metrics' => $this->metrics
            ]);

            // Update ETL execution log with error
            if (isset($executionId)) {
                $this->updateETLExecution($executionId, 'failed', $this->metrics, $e->getMessage());
            }

            // Send alert for failed ETL
            $this->sendAlert($etlClass, $e);

            throw $e;
        }
    }

    /**
     * Get current execution metrics
     * 
     * @return array Current metrics data
     */
    public function getMetrics(): array
    {
        return $this->metrics;
    }

    /**
     * Reset metrics for new execution
     * 
     * @return void
     */
    protected function resetMetrics(): void
    {
        $this->metrics = [
            'records_extracted' => 0,
            'records_transformed' => 0,
            'records_loaded' => 0,
            'errors_count' => 0,
            'start_time' => null,
            'end_time' => null,
            'duration' => 0
        ];
    }

    /**
     * Log ETL execution start in database
     * 
     * @param string $status Initial status
     * @return int Execution ID
     */
    protected function logETLExecution(string $status): int
    {
        $sql = "
            INSERT INTO etl_execution_log (
                etl_class, status, started_at
            ) VALUES (?, ?, NOW())
            RETURNING id
        ";

        $result = $this->db->query($sql, [get_class($this), $status]);
        return $result[0]['id'];
    }

    /**
     * Update ETL execution log with completion data
     * 
     * @param int $executionId Execution ID
     * @param string $status Final status
     * @param array $metrics Execution metrics
     * @param string|null $errorMessage Error message if failed
     * @return void
     */
    protected function updateETLExecution(
        int $executionId,
        string $status,
        array $metrics,
        ?string $errorMessage = null
    ): void {
        $sql = "
            UPDATE etl_execution_log 
            SET status = ?, 
                records_processed = ?, 
                duration_seconds = ?, 
                error_message = ?,
                completed_at = NOW()
            WHERE id = ?
        ";

        $this->db->execute($sql, [
            $status,
            $metrics['records_loaded'] ?? 0,
            $metrics['duration'] ?? 0,
            $errorMessage,
            $executionId
        ]);
    }

    /**
     * Send alert notification for failed ETL
     * 
     * @param string $etlClass ETL class name
     * @param Exception $exception Exception that occurred
     * @return void
     */
    protected function sendAlert(string $etlClass, Exception $exception): void
    {
        // Implementation depends on alert system configuration
        // This could be email, Slack, webhook, etc.
        $alertConfig = $this->config['alerts'] ?? [];
        
        if (empty($alertConfig['enabled'])) {
            return;
        }

        $message = sprintf(
            "ETL Process Failed: %s\nError: %s\nTime: %s",
            $etlClass,
            $exception->getMessage(),
            date('Y-m-d H:i:s')
        );

        $this->logger->critical('ETL Alert sent', [
            'class' => $etlClass,
            'message' => $message
        ]);

        // TODO: Implement actual alert sending based on configuration
        // This could integrate with existing alert handlers
    }

    /**
     * Sanitize configuration for logging (remove sensitive data)
     * 
     * @param array $config Configuration array
     * @return array Sanitized configuration
     */
    protected function sanitizeConfig(array $config): array
    {
        $sanitized = $config;
        $sensitiveKeys = ['password', 'api_key', 'secret', 'token'];

        array_walk_recursive($sanitized, function (&$value, $key) use ($sensitiveKeys) {
            if (in_array(strtolower($key), $sensitiveKeys)) {
                $value = '***REDACTED***';
            }
        });

        return $sanitized;
    }

    /**
     * Validate required configuration parameters
     * 
     * @param array $requiredKeys Required configuration keys
     * @throws Exception When required configuration is missing
     */
    protected function validateConfig(array $requiredKeys): void
    {
        $missingKeys = [];
        
        foreach ($requiredKeys as $key) {
            if (!isset($this->config[$key])) {
                $missingKeys[] = $key;
            }
        }

        if (!empty($missingKeys)) {
            throw new Exception(
                'Missing required configuration keys: ' . implode(', ', $missingKeys)
            );
        }
    }

    /**
     * Get ETL class name for logging
     * 
     * @return string Short class name
     */
    protected function getETLClassName(): string
    {
        $className = get_class($this);
        return substr($className, strrpos($className, '\\') + 1);
    }
}