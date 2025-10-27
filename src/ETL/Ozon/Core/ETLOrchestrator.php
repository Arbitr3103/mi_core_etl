<?php

declare(strict_types=1);

namespace MiCore\ETL\Ozon\Core;

use Exception;
use RuntimeException;
use MiCore\ETL\Ozon\Components\ProductETL;
use MiCore\ETL\Ozon\Components\InventoryETL;
use MiCore\ETL\Ozon\Core\DatabaseConnection;
use MiCore\ETL\Ozon\Core\Logger;
use MiCore\ETL\Ozon\Api\OzonApiClient;

/**
 * ETL Orchestrator
 * 
 * Manages the execution sequence of ETL processes with dependency management,
 * retry logic, and comprehensive error handling. Ensures ProductETL runs
 * before InventoryETL and handles failures gracefully.
 * 
 * Requirements addressed:
 * - 5.1: Update cron jobs to run ProductETL before InventoryETL with proper timing
 * - 5.1: Add dependency checks to prevent InventoryETL from running if ProductETL failed
 * - 5.1: Implement retry logic for failed ETL processes
 * - 5.2: Add separate logging for ProductETL visibility processing
 */
class ETLOrchestrator
{
    private DatabaseConnection $db;
    private Logger $logger;
    private OzonApiClient $apiClient;
    private array $config;
    
    private ProductETL $productETL;
    private InventoryETL $inventoryETL;
    
    private int $maxRetries;
    private int $retryDelay;
    private bool $enableDependencyChecks;
    private bool $enableRetryLogic;
    
    // ETL execution status tracking
    private array $executionStatus = [
        'product_etl' => ['status' => 'not_started', 'attempts' => 0, 'last_error' => null],
        'inventory_etl' => ['status' => 'not_started', 'attempts' => 0, 'last_error' => null]
    ];
    
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
        
        // Configuration with defaults
        $this->maxRetries = $config['max_retries'] ?? 3;
        $this->retryDelay = $config['retry_delay'] ?? 300; // 5 minutes
        $this->enableDependencyChecks = $config['enable_dependency_checks'] ?? true;
        $this->enableRetryLogic = $config['enable_retry_logic'] ?? true;
        
        // Validate configuration
        $this->validateOrchestratorConfig();
        
        // Initialize ETL components
        $this->initializeETLComponents();
        
        $this->logger->info('ETL Orchestrator initialized', [
            'max_retries' => $this->maxRetries,
            'retry_delay' => $this->retryDelay,
            'enable_dependency_checks' => $this->enableDependencyChecks,
            'enable_retry_logic' => $this->enableRetryLogic
        ]);
    }
    
    /**
     * Execute complete ETL workflow with proper sequencing
     * 
     * Executes ProductETL followed by InventoryETL with dependency management,
     * retry logic, and comprehensive error handling.
     * 
     * Requirements addressed:
     * - 5.1: Execute ProductETL before InventoryETL with proper timing
     * - 5.1: Add dependency checks to prevent InventoryETL from running if ProductETL failed
     * - 5.1: Implement retry logic for failed ETL processes
     * 
     * @param array $options Execution options
     * @return array Execution results
     * @throws RuntimeException When critical failures occur
     */
    public function executeETLWorkflow(array $options = []): array
    {
        $workflowStartTime = microtime(true);
        $workflowId = $this->generateWorkflowId();
        
        $this->logger->info('Starting ETL workflow execution', [
            'workflow_id' => $workflowId,
            'options' => $options,
            'dependency_checks_enabled' => $this->enableDependencyChecks,
            'retry_logic_enabled' => $this->enableRetryLogic
        ]);
        
        // Reset execution status
        $this->resetExecutionStatus();
        
        try {
            // Step 1: Execute ProductETL with retry logic
            $this->logger->info('Step 1: Executing ProductETL', ['workflow_id' => $workflowId]);
            $productResult = $this->executeProductETLWithRetry($options);
            
            // Step 2: Check ProductETL dependency before proceeding
            if ($this->enableDependencyChecks && !$this->checkProductETLDependency()) {
                throw new RuntimeException('ProductETL dependency check failed - cannot proceed with InventoryETL');
            }
            
            // Step 3: Execute InventoryETL with retry logic
            $this->logger->info('Step 2: Executing InventoryETL', ['workflow_id' => $workflowId]);
            $inventoryResult = $this->executeInventoryETLWithRetry($options);
            
            // Step 4: Validate workflow completion
            $this->validateWorkflowCompletion();
            
            $workflowDuration = microtime(true) - $workflowStartTime;
            
            $workflowResult = [
                'workflow_id' => $workflowId,
                'status' => 'success',
                'duration' => round($workflowDuration, 2),
                'product_etl' => $productResult,
                'inventory_etl' => $inventoryResult,
                'execution_status' => $this->executionStatus
            ];
            
            $this->logger->info('ETL workflow completed successfully', $workflowResult);
            
            // Record successful workflow execution
            $this->recordWorkflowExecution($workflowResult);
            
            return $workflowResult;
            
        } catch (Exception $e) {
            $workflowDuration = microtime(true) - $workflowStartTime;
            
            $workflowResult = [
                'workflow_id' => $workflowId,
                'status' => 'failed',
                'duration' => round($workflowDuration, 2),
                'error' => $e->getMessage(),
                'execution_status' => $this->executionStatus
            ];
            
            $this->logger->error('ETL workflow failed', [
                'workflow_result' => $workflowResult,
                'error_trace' => $e->getTraceAsString()
            ]);
            
            // Record failed workflow execution
            $this->recordWorkflowExecution($workflowResult);
            
            throw new RuntimeException("ETL workflow failed: " . $e->getMessage(), 0, $e);
        }
    }
    
    /**
     * Execute ProductETL with retry logic
     * 
     * Requirements addressed:
     * - 5.1: Implement retry logic for failed ETL processes
     * - 5.2: Add separate logging for ProductETL visibility processing
     * 
     * @param array $options Execution options
     * @return array ProductETL execution result
     * @throws RuntimeException When all retry attempts fail
     */
    private function executeProductETLWithRetry(array $options = []): array
    {
        $componentName = 'product_etl';
        $this->executionStatus[$componentName]['status'] = 'running';
        
        for ($attempt = 1; $attempt <= $this->maxRetries; $attempt++) {
            $this->executionStatus[$componentName]['attempts'] = $attempt;
            
            try {
                $this->logger->info('Executing ProductETL', [
                    'attempt' => $attempt,
                    'max_attempts' => $this->maxRetries,
                    'options' => $options
                ]);
                
                $startTime = microtime(true);
                
                // Execute ProductETL workflow
                $this->productETL->run();
                
                $duration = microtime(true) - $startTime;
                $metrics = $this->productETL->getMetrics();
                
                // Log visibility processing metrics separately
                $this->logVisibilityProcessingMetrics($metrics);
                
                $result = [
                    'status' => 'success',
                    'attempt' => $attempt,
                    'duration' => round($duration, 2),
                    'metrics' => $metrics
                ];
                
                $this->executionStatus[$componentName]['status'] = 'completed';
                $this->executionStatus[$componentName]['last_error'] = null;
                
                $this->logger->info('ProductETL completed successfully', $result);
                
                return $result;
                
            } catch (Exception $e) {
                $this->executionStatus[$componentName]['last_error'] = $e->getMessage();
                
                $this->logger->error('ProductETL execution failed', [
                    'attempt' => $attempt,
                    'max_attempts' => $this->maxRetries,
                    'error' => $e->getMessage(),
                    'will_retry' => $attempt < $this->maxRetries && $this->enableRetryLogic
                ]);
                
                // If this is the last attempt or retry is disabled, throw the exception
                if ($attempt >= $this->maxRetries || !$this->enableRetryLogic) {
                    $this->executionStatus[$componentName]['status'] = 'failed';
                    throw new RuntimeException("ProductETL failed after {$attempt} attempts: " . $e->getMessage(), 0, $e);
                }
                
                // Wait before retry
                if ($attempt < $this->maxRetries) {
                    $this->logger->info('Waiting before ProductETL retry', [
                        'retry_delay' => $this->retryDelay,
                        'next_attempt' => $attempt + 1
                    ]);
                    sleep($this->retryDelay);
                }
            }
        }
        
        // This should never be reached due to the logic above, but included for completeness
        $this->executionStatus[$componentName]['status'] = 'failed';
        throw new RuntimeException("ProductETL failed after {$this->maxRetries} attempts");
    }
    
    /**
     * Execute InventoryETL with retry logic
     * 
     * Requirements addressed:
     * - 5.1: Implement retry logic for failed ETL processes
     * 
     * @param array $options Execution options
     * @return array InventoryETL execution result
     * @throws RuntimeException When all retry attempts fail
     */
    private function executeInventoryETLWithRetry(array $options = []): array
    {
        $componentName = 'inventory_etl';
        $this->executionStatus[$componentName]['status'] = 'running';
        
        for ($attempt = 1; $attempt <= $this->maxRetries; $attempt++) {
            $this->executionStatus[$componentName]['attempts'] = $attempt;
            
            try {
                $this->logger->info('Executing InventoryETL', [
                    'attempt' => $attempt,
                    'max_attempts' => $this->maxRetries,
                    'options' => $options
                ]);
                
                $startTime = microtime(true);
                
                // Execute InventoryETL workflow
                $this->inventoryETL->run();
                
                $duration = microtime(true) - $startTime;
                $metrics = $this->inventoryETL->getMetrics();
                
                $result = [
                    'status' => 'success',
                    'attempt' => $attempt,
                    'duration' => round($duration, 2),
                    'metrics' => $metrics
                ];
                
                $this->executionStatus[$componentName]['status'] = 'completed';
                $this->executionStatus[$componentName]['last_error'] = null;
                
                $this->logger->info('InventoryETL completed successfully', $result);
                
                return $result;
                
            } catch (Exception $e) {
                $this->executionStatus[$componentName]['last_error'] = $e->getMessage();
                
                $this->logger->error('InventoryETL execution failed', [
                    'attempt' => $attempt,
                    'max_attempts' => $this->maxRetries,
                    'error' => $e->getMessage(),
                    'will_retry' => $attempt < $this->maxRetries && $this->enableRetryLogic
                ]);
                
                // If this is the last attempt or retry is disabled, throw the exception
                if ($attempt >= $this->maxRetries || !$this->enableRetryLogic) {
                    $this->executionStatus[$componentName]['status'] = 'failed';
                    throw new RuntimeException("InventoryETL failed after {$attempt} attempts: " . $e->getMessage(), 0, $e);
                }
                
                // Wait before retry
                if ($attempt < $this->maxRetries) {
                    $this->logger->info('Waiting before InventoryETL retry', [
                        'retry_delay' => $this->retryDelay,
                        'next_attempt' => $attempt + 1
                    ]);
                    sleep($this->retryDelay);
                }
            }
        }
        
        // This should never be reached due to the logic above, but included for completeness
        $this->executionStatus[$componentName]['status'] = 'failed';
        throw new RuntimeException("InventoryETL failed after {$this->maxRetries} attempts");
    }
    
    /**
     * Check ProductETL dependency before running InventoryETL
     * 
     * Requirements addressed:
     * - 5.1: Add dependency checks to prevent InventoryETL from running if ProductETL failed
     * 
     * @return bool True if dependency is satisfied
     */
    private function checkProductETLDependency(): bool
    {
        $this->logger->info('Checking ProductETL dependency for InventoryETL');
        
        // Check execution status
        if ($this->executionStatus['product_etl']['status'] !== 'completed') {
            $this->logger->error('ProductETL dependency check failed - ProductETL not completed', [
                'product_etl_status' => $this->executionStatus['product_etl']['status'],
                'last_error' => $this->executionStatus['product_etl']['last_error']
            ]);
            return false;
        }
        
        // Check database state - verify that ProductETL actually updated data
        try {
            $result = $this->db->query("
                SELECT 
                    COUNT(*) as total_products,
                    COUNT(CASE WHEN visibility IS NOT NULL THEN 1 END) as products_with_visibility,
                    MAX(updated_at) as last_update
                FROM dim_products
            ");
            
            if (empty($result)) {
                $this->logger->error('ProductETL dependency check failed - no products found in dim_products');
                return false;
            }
            
            $stats = $result[0];
            
            // Check if we have products with visibility data (new requirement)
            if ($stats['products_with_visibility'] == 0) {
                $this->logger->error('ProductETL dependency check failed - no products with visibility data', [
                    'total_products' => $stats['total_products'],
                    'products_with_visibility' => $stats['products_with_visibility']
                ]);
                return false;
            }
            
            // Check if data is recent (within last 24 hours)
            $lastUpdate = strtotime($stats['last_update']);
            $dayAgo = time() - (24 * 60 * 60);
            
            if ($lastUpdate < $dayAgo) {
                $this->logger->warning('ProductETL dependency check - data may be stale', [
                    'last_update' => $stats['last_update'],
                    'hours_old' => round((time() - $lastUpdate) / 3600, 1)
                ]);
                // Don't fail for stale data, just warn
            }
            
            $this->logger->info('ProductETL dependency check passed', [
                'total_products' => $stats['total_products'],
                'products_with_visibility' => $stats['products_with_visibility'],
                'last_update' => $stats['last_update'],
                'visibility_coverage' => round(($stats['products_with_visibility'] / $stats['total_products']) * 100, 2)
            ]);
            
            return true;
            
        } catch (Exception $e) {
            $this->logger->error('ProductETL dependency check failed - database error', [
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }
    
    /**
     * Log visibility processing metrics separately
     * 
     * Requirements addressed:
     * - 5.2: Add separate logging for ProductETL visibility processing
     * 
     * @param array $metrics ProductETL metrics
     */
    private function logVisibilityProcessingMetrics(array $metrics): void
    {
        // Extract visibility-related metrics
        $visibilityMetrics = [
            'records_extracted' => $metrics['records_extracted'] ?? 0,
            'records_transformed' => $metrics['records_transformed'] ?? 0,
            'records_loaded' => $metrics['records_loaded'] ?? 0,
            'records_inserted' => $metrics['records_inserted'] ?? 0,
            'records_updated' => $metrics['records_updated'] ?? 0,
            'extraction_duration' => $metrics['extraction_duration'] ?? 0,
            'loading_duration' => $metrics['loading_duration'] ?? 0,
            'verification_rate' => $metrics['verification_rate'] ?? 0
        ];
        
        // Calculate visibility processing rates
        if ($visibilityMetrics['records_extracted'] > 0) {
            $visibilityMetrics['transformation_rate'] = round(
                ($visibilityMetrics['records_transformed'] / $visibilityMetrics['records_extracted']) * 100, 2
            );
            $visibilityMetrics['loading_rate'] = round(
                ($visibilityMetrics['records_loaded'] / $visibilityMetrics['records_extracted']) * 100, 2
            );
        }
        
        if ($visibilityMetrics['records_loaded'] > 0) {
            $visibilityMetrics['insert_rate'] = round(
                ($visibilityMetrics['records_inserted'] / $visibilityMetrics['records_loaded']) * 100, 2
            );
            $visibilityMetrics['update_rate'] = round(
                ($visibilityMetrics['records_updated'] / $visibilityMetrics['records_loaded']) * 100, 2
            );
        }
        
        // Log visibility processing metrics separately
        $this->logger->info('ProductETL visibility processing metrics', [
            'component' => 'product_etl_visibility',
            'metrics' => $visibilityMetrics,
            'timestamp' => date('Y-m-d H:i:s')
        ]);
        
        // Also get visibility status distribution from database
        try {
            $visibilityDistribution = $this->getVisibilityStatusDistribution();
            
            $this->logger->info('Visibility status distribution after ProductETL', [
                'component' => 'product_etl_visibility',
                'distribution' => $visibilityDistribution,
                'timestamp' => date('Y-m-d H:i:s')
            ]);
            
        } catch (Exception $e) {
            $this->logger->warning('Failed to get visibility status distribution', [
                'error' => $e->getMessage()
            ]);
        }
    }
    
    /**
     * Get visibility status distribution from database
     * 
     * @return array Visibility status distribution
     */
    private function getVisibilityStatusDistribution(): array
    {
        try {
            $result = $this->db->query("
                SELECT 
                    visibility,
                    COUNT(*) as count,
                    ROUND((COUNT(*) * 100.0 / SUM(COUNT(*)) OVER()), 2) as percentage
                FROM dim_products 
                WHERE visibility IS NOT NULL
                GROUP BY visibility
                ORDER BY count DESC
            ");
            
            $distribution = [];
            foreach ($result as $row) {
                $distribution[$row['visibility']] = [
                    'count' => (int)$row['count'],
                    'percentage' => (float)$row['percentage']
                ];
            }
            
            return $distribution;
            
        } catch (Exception $e) {
            $this->logger->warning('Failed to calculate visibility distribution', [
                'error' => $e->getMessage()
            ]);
            return [];
        }
    }
    
    /**
     * Validate workflow completion
     * 
     * @throws RuntimeException When validation fails
     */
    private function validateWorkflowCompletion(): void
    {
        $this->logger->info('Validating ETL workflow completion');
        
        // Check that both components completed successfully
        if ($this->executionStatus['product_etl']['status'] !== 'completed') {
            throw new RuntimeException('Workflow validation failed - ProductETL not completed');
        }
        
        if ($this->executionStatus['inventory_etl']['status'] !== 'completed') {
            throw new RuntimeException('Workflow validation failed - InventoryETL not completed');
        }
        
        // Validate data consistency between components
        try {
            $consistencyCheck = $this->validateDataConsistency();
            
            if (!$consistencyCheck['valid']) {
                $this->logger->warning('Data consistency validation failed', $consistencyCheck);
                // Don't fail the workflow for consistency issues, just warn
            } else {
                $this->logger->info('Data consistency validation passed', $consistencyCheck);
            }
            
        } catch (Exception $e) {
            $this->logger->warning('Data consistency validation error', [
                'error' => $e->getMessage()
            ]);
        }
        
        $this->logger->info('ETL workflow validation completed successfully');
    }
    
    /**
     * Validate data consistency between ProductETL and InventoryETL
     * 
     * @return array Validation result
     */
    private function validateDataConsistency(): array
    {
        try {
            // Check for inventory items without corresponding products
            $orphanedInventory = $this->db->query("
                SELECT COUNT(*) as count
                FROM inventory i
                LEFT JOIN dim_products p ON i.offer_id = p.offer_id
                WHERE p.offer_id IS NULL
            ");
            
            $orphanedCount = (int)($orphanedInventory[0]['count'] ?? 0);
            
            // Check for products without inventory (this is expected and OK)
            $productsWithoutInventory = $this->db->query("
                SELECT COUNT(*) as count
                FROM dim_products p
                LEFT JOIN inventory i ON p.offer_id = i.offer_id
                WHERE i.offer_id IS NULL
            ");
            
            $productsWithoutInventoryCount = (int)($productsWithoutInventory[0]['count'] ?? 0);
            
            // Get total counts
            $totalProducts = $this->db->query("SELECT COUNT(*) as count FROM dim_products");
            $totalInventory = $this->db->query("SELECT COUNT(*) as count FROM inventory");
            
            $totalProductsCount = (int)($totalProducts[0]['count'] ?? 0);
            $totalInventoryCount = (int)($totalInventory[0]['count'] ?? 0);
            
            $result = [
                'valid' => $orphanedCount === 0, // Only fail if we have orphaned inventory
                'orphaned_inventory_items' => $orphanedCount,
                'products_without_inventory' => $productsWithoutInventoryCount,
                'total_products' => $totalProductsCount,
                'total_inventory_items' => $totalInventoryCount,
                'inventory_coverage' => $totalProductsCount > 0 ? 
                    round((($totalProductsCount - $productsWithoutInventoryCount) / $totalProductsCount) * 100, 2) : 0
            ];
            
            return $result;
            
        } catch (Exception $e) {
            return [
                'valid' => false,
                'error' => $e->getMessage()
            ];
        }
    }
    
    /**
     * Initialize ETL components
     */
    private function initializeETLComponents(): void
    {
        // Initialize ProductETL with configuration
        $productConfig = $this->config['product_etl'] ?? [];
        $this->productETL = new ProductETL($this->db, $this->logger, $this->apiClient, $productConfig);
        
        // Initialize InventoryETL with configuration
        $inventoryConfig = $this->config['inventory_etl'] ?? [];
        $this->inventoryETL = new InventoryETL($this->db, $this->logger, $this->apiClient, $inventoryConfig);
        
        $this->logger->info('ETL components initialized successfully');
    }
    
    /**
     * Reset execution status for new workflow
     */
    private function resetExecutionStatus(): void
    {
        $this->executionStatus = [
            'product_etl' => ['status' => 'not_started', 'attempts' => 0, 'last_error' => null],
            'inventory_etl' => ['status' => 'not_started', 'attempts' => 0, 'last_error' => null]
        ];
    }
    
    /**
     * Generate unique workflow ID
     * 
     * @return string Workflow ID
     */
    private function generateWorkflowId(): string
    {
        return 'etl_workflow_' . date('Y-m-d_H-i-s') . '_' . substr(md5(uniqid()), 0, 8);
    }
    
    /**
     * Record workflow execution in database
     * 
     * @param array $workflowResult Workflow execution result
     */
    private function recordWorkflowExecution(array $workflowResult): void
    {
        try {
            $this->db->execute("
                INSERT INTO etl_workflow_executions 
                (workflow_id, status, duration, product_etl_status, inventory_etl_status, 
                 execution_details, created_at)
                VALUES (?, ?, ?, ?, ?, ?, NOW())
            ", [
                $workflowResult['workflow_id'],
                $workflowResult['status'],
                $workflowResult['duration'],
                $this->executionStatus['product_etl']['status'],
                $this->executionStatus['inventory_etl']['status'],
                json_encode($workflowResult, JSON_UNESCAPED_UNICODE)
            ]);
            
        } catch (Exception $e) {
            $this->logger->warning('Failed to record workflow execution', [
                'workflow_id' => $workflowResult['workflow_id'],
                'error' => $e->getMessage()
            ]);
        }
    }
    
    /**
     * Validate orchestrator configuration
     * 
     * @throws RuntimeException When configuration is invalid
     */
    private function validateOrchestratorConfig(): void
    {
        if ($this->maxRetries < 1 || $this->maxRetries > 10) {
            throw new RuntimeException('max_retries must be between 1 and 10');
        }
        
        if ($this->retryDelay < 0 || $this->retryDelay > 3600) {
            throw new RuntimeException('retry_delay must be between 0 and 3600 seconds');
        }
    }
    
    /**
     * Get orchestrator status and metrics
     * 
     * @return array Status information
     */
    public function getStatus(): array
    {
        return [
            'config' => [
                'max_retries' => $this->maxRetries,
                'retry_delay' => $this->retryDelay,
                'enable_dependency_checks' => $this->enableDependencyChecks,
                'enable_retry_logic' => $this->enableRetryLogic
            ],
            'execution_status' => $this->executionStatus,
            'components' => [
                'product_etl' => [
                    'class' => get_class($this->productETL),
                    'metrics' => $this->productETL->getMetrics()
                ],
                'inventory_etl' => [
                    'class' => get_class($this->inventoryETL),
                    'metrics' => $this->inventoryETL->getMetrics()
                ]
            ]
        ];
    }
}