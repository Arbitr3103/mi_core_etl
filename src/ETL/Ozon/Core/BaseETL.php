<?php

declare(strict_types=1);

namespace MiCore\ETL\Ozon\Core;

use Exception;

/**
 * Base ETL Class
 * 
 * Provides common functionality for all ETL components
 */
abstract class BaseETL
{
    protected DatabaseConnection $db;
    protected Logger $logger;
    protected $apiClient;
    protected array $config;
    protected array $metrics = [];
    
    public function __construct(
        DatabaseConnection $db,
        Logger $logger,
        $apiClient,
        array $config = []
    ) {
        $this->db = $db;
        $this->logger = $logger;
        $this->apiClient = $apiClient;
        $this->config = $config;
        
        $this->initializeMetrics();
    }
    
    /**
     * Initialize metrics tracking
     */
    protected function initializeMetrics(): void
    {
        $this->metrics = [
            'records_extracted' => 0,
            'records_transformed' => 0,
            'records_loaded' => 0,
            'records_inserted' => 0,
            'records_updated' => 0,
            'extraction_duration' => 0,
            'transformation_duration' => 0,
            'loading_duration' => 0,
            'total_duration' => 0,
            'errors' => [],
            'warnings' => []
        ];
    }
    
    /**
     * Run the complete ETL process
     */
    public function run(): void
    {
        $startTime = microtime(true);
        
        try {
            $this->logger->info('Starting ETL process', [
                'class' => get_class($this),
                'config' => $this->config
            ]);
            
            // Extract
            $extractedData = $this->extract();
            
            // Transform
            $transformedData = $this->transform($extractedData);
            
            // Load
            $this->load($transformedData);
            
            $this->metrics['total_duration'] = microtime(true) - $startTime;
            
            $this->logger->info('ETL process completed successfully', [
                'class' => get_class($this),
                'metrics' => $this->metrics
            ]);
            
        } catch (Exception $e) {
            $this->metrics['total_duration'] = microtime(true) - $startTime;
            $this->metrics['errors'][] = $e->getMessage();
            
            $this->logger->error('ETL process failed', [
                'class' => get_class($this),
                'error' => $e->getMessage(),
                'metrics' => $this->metrics
            ]);
            
            throw $e;
        }
    }
    
    /**
     * Extract data from source
     */
    abstract public function extract(): array;
    
    /**
     * Transform extracted data
     */
    abstract public function transform(array $data): array;
    
    /**
     * Load transformed data to destination
     */
    abstract public function load(array $data): void;
    
    /**
     * Get ETL metrics
     */
    public function getMetrics(): array
    {
        return $this->metrics;
    }
    
    /**
     * Reset metrics
     */
    public function resetMetrics(): void
    {
        $this->initializeMetrics();
    }
}