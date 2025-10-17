<?php

namespace Replenishment;

use PDO;
use Exception;
use DateTime;

/**
 * ReplenishmentRecommender Class
 * 
 * Main orchestrator class for generating inventory replenishment recommendations.
 * Coordinates SalesAnalyzer and StockCalculator to produce actionable recommendations.
 */
class ReplenishmentRecommender
{
    private PDO $pdo;
    private SalesAnalyzer $salesAnalyzer;
    private StockCalculator $stockCalculator;
    private array $config;
    
    public function __construct(PDO $pdo, array $config = [])
    {
        $this->pdo = $pdo;
        $this->config = array_merge([
            'batch_size' => 100,
            'min_ads_threshold' => 0.1,
            'debug' => false,
            'progress_callback' => null,
            'error_recovery' => true,
            'max_retries' => 3
        ], $config);
        
        // Initialize component classes
        $this->salesAnalyzer = new SalesAnalyzer($pdo, $config);
        $this->stockCalculator = new StockCalculator($pdo, $config);
    }
    
    /**
     * Generate recommendations for specified products or all active products
     * 
     * @param array|null $productIds Array of product IDs (null for all active products)
     * @return array Generated recommendations
     * @throws Exception If generation fails
     */
    public function generateRecommendations(?array $productIds = null): array
    {
        $calculationId = $this->startCalculationLog();
        $startTime = microtime(true);
        $startMemory = memory_get_usage(true);
        
        try {
            $this->log("Starting recommendation generation");
            
            // Get products to process
            if ($productIds === null) {
                $products = $this->salesAnalyzer->getActiveProducts();
                $productIds = array_column($products, 'id');
                $this->log("Processing all " . count($productIds) . " active products");
            } else {
                $this->log("Processing " . count($productIds) . " specified products");
            }
            
            if (empty($productIds)) {
                throw new Exception("No products to process");
            }
            
            // Process products in batches
            $allRecommendations = [];
            $totalProducts = count($productIds);
            $processedCount = 0;
            $errorCount = 0;
            
            $batches = array_chunk($productIds, $this->config['batch_size']);
            
            foreach ($batches as $batchIndex => $batch) {
                $this->log("Processing batch " . ($batchIndex + 1) . "/" . count($batches) . " (" . count($batch) . " products)");
                
                try {
                    $batchRecommendations = $this->processBatch($batch);
                    $allRecommendations = array_merge($allRecommendations, $batchRecommendations);
                    $processedCount += count($batch);
                    
                    // Report progress
                    $this->reportProgress($processedCount, $totalProducts, $calculationId);
                    
                } catch (Exception $e) {
                    $errorCount++;
                    $this->log("Batch processing error: " . $e->getMessage());
                    
                    if ($this->config['error_recovery']) {
                        // Try to process individual products in failed batch
                        $recoveredRecommendations = $this->recoverBatch($batch);
                        $allRecommendations = array_merge($allRecommendations, $recoveredRecommendations);
                        $processedCount += count($recoveredRecommendations);
                    } else {
                        throw $e;
                    }
                }
            }
            
            // Save recommendations to database
            $savedCount = $this->saveRecommendations($allRecommendations);
            
            // Calculate execution metrics
            $executionTime = round(microtime(true) - $startTime, 2);
            $memoryUsage = round((memory_get_peak_usage(true) - $startMemory) / 1024 / 1024, 2);
            
            // Complete calculation log
            $this->completeCalculationLog($calculationId, [
                'products_processed' => $processedCount,
                'recommendations_generated' => $savedCount,
                'execution_time_seconds' => $executionTime,
                'memory_usage_mb' => $memoryUsage,
                'error_count' => $errorCount,
                'status' => $errorCount > 0 ? 'partial' : 'success'
            ]);
            
            $this->log("Recommendation generation completed: $savedCount recommendations saved in {$executionTime}s");
            
            return $allRecommendations;
            
        } catch (Exception $e) {
            $executionTime = round(microtime(true) - $startTime, 2);
            $memoryUsage = round((memory_get_peak_usage(true) - $startMemory) / 1024 / 1024, 2);
            
            $this->completeCalculationLog($calculationId, [
                'products_processed' => $processedCount ?? 0,
                'recommendations_generated' => 0,
                'execution_time_seconds' => $executionTime,
                'memory_usage_mb' => $memoryUsage,
                'error_count' => 1,
                'status' => 'error',
                'error_message' => $e->getMessage()
            ]);
            
            $this->log("Recommendation generation failed: " . $e->getMessage());
            throw $e;
        }
    }
    
    /**
     * Generate weekly report for scheduled execution
     * 
     * @return array Report data including recommendations and summary
     * @throws Exception If report generation fails
     */
    public function generateWeeklyReport(): array
    {
        $this->log("Generating weekly replenishment report");
        
        try {
            // Generate fresh recommendations
            $recommendations = $this->generateRecommendations();
            
            // Filter recommendations that need action (recommended_quantity > 0)
            $actionableRecommendations = array_filter($recommendations, function($rec) {
                return $rec['recommended_quantity'] > 0;
            });
            
            // Sort by recommended quantity (descending)
            usort($actionableRecommendations, function($a, $b) {
                return $b['recommended_quantity'] <=> $a['recommended_quantity'];
            });
            
            // Generate summary statistics
            $summary = $this->generateReportSummary($recommendations);
            
            // Create report structure
            $report = [
                'report_date' => date('Y-m-d'),
                'generation_time' => date('Y-m-d H:i:s'),
                'summary' => $summary,
                'actionable_recommendations' => $actionableRecommendations,
                'all_recommendations' => $recommendations
            ];
            
            $this->log("Weekly report generated: " . count($actionableRecommendations) . " actionable recommendations");
            
            return $report;
            
        } catch (Exception $e) {
            $this->log("Weekly report generation failed: " . $e->getMessage());
            throw new Exception("Failed to generate weekly report: " . $e->getMessage());
        }
    }
    
    /**
     * Save recommendations to database with transaction support
     * 
     * @param array $recommendations Array of recommendation data
     * @return int Number of recommendations saved
     * @throws Exception If save operation fails
     */
    public function saveRecommendations(array $recommendations): int
    {
        if (empty($recommendations)) {
            $this->log("No recommendations to save");
            return 0;
        }
        
        $this->log("Saving " . count($recommendations) . " recommendations to database");
        
        try {
            $this->pdo->beginTransaction();
            
            // Prepare insert statement with ON DUPLICATE KEY UPDATE
            $sql = "
                INSERT INTO replenishment_recommendations (
                    product_id, product_name, sku, ads, current_stock, 
                    target_stock, recommended_quantity, calculation_date
                ) VALUES (
                    :product_id, :product_name, :sku, :ads, :current_stock,
                    :target_stock, :recommended_quantity, :calculation_date
                )
                ON DUPLICATE KEY UPDATE
                    product_name = VALUES(product_name),
                    sku = VALUES(sku),
                    ads = VALUES(ads),
                    current_stock = VALUES(current_stock),
                    target_stock = VALUES(target_stock),
                    recommended_quantity = VALUES(recommended_quantity),
                    updated_at = CURRENT_TIMESTAMP
            ";
            
            $stmt = $this->pdo->prepare($sql);
            $savedCount = 0;
            $calculationDate = date('Y-m-d');
            
            foreach ($recommendations as $recommendation) {
                try {
                    $stmt->execute([
                        'product_id' => $recommendation['product_id'],
                        'product_name' => $recommendation['product_name'] ?? 'Unknown',
                        'sku' => $recommendation['sku'] ?? null,
                        'ads' => $recommendation['ads'],
                        'current_stock' => $recommendation['current_stock'],
                        'target_stock' => $recommendation['target_stock'],
                        'recommended_quantity' => $recommendation['recommended_quantity'],
                        'calculation_date' => $calculationDate
                    ]);
                    
                    $savedCount++;
                    
                } catch (Exception $e) {
                    $this->log("Error saving recommendation for product {$recommendation['product_id']}: " . $e->getMessage());
                    // Continue with other recommendations
                }
            }
            
            $this->pdo->commit();
            
            $this->log("Successfully saved $savedCount recommendations");
            
            return $savedCount;
            
        } catch (Exception $e) {
            $this->pdo->rollBack();
            $this->log("Error saving recommendations: " . $e->getMessage());
            throw new Exception("Failed to save recommendations: " . $e->getMessage());
        }
    }
    
    /**
     * Process a batch of products
     * 
     * @param array $productIds Product IDs to process
     * @return array Batch recommendations
     * @throws Exception If batch processing fails
     */
    private function processBatch(array $productIds): array
    {
        $batchRecommendations = [];
        
        foreach ($productIds as $productId) {
            try {
                $recommendation = $this->generateSingleRecommendation($productId);
                if ($recommendation) {
                    $batchRecommendations[] = $recommendation;
                }
            } catch (Exception $e) {
                $this->log("Error processing product $productId: " . $e->getMessage());
                // Continue with next product in batch
            }
        }
        
        return $batchRecommendations;
    }
    
    /**
     * Recover failed batch by processing products individually
     * 
     * @param array $productIds Product IDs to recover
     * @return array Recovered recommendations
     */
    private function recoverBatch(array $productIds): array
    {
        $this->log("Attempting to recover batch with " . count($productIds) . " products");
        
        $recoveredRecommendations = [];
        
        foreach ($productIds as $productId) {
            $retryCount = 0;
            
            while ($retryCount < $this->config['max_retries']) {
                try {
                    $recommendation = $this->generateSingleRecommendation($productId);
                    if ($recommendation) {
                        $recoveredRecommendations[] = $recommendation;
                    }
                    break; // Success, exit retry loop
                    
                } catch (Exception $e) {
                    $retryCount++;
                    $this->log("Retry $retryCount for product $productId failed: " . $e->getMessage());
                    
                    if ($retryCount >= $this->config['max_retries']) {
                        $this->log("Max retries reached for product $productId, skipping");
                    } else {
                        // Brief delay before retry
                        usleep(100000); // 0.1 second
                    }
                }
            }
        }
        
        $this->log("Recovered " . count($recoveredRecommendations) . " recommendations from failed batch");
        
        return $recoveredRecommendations;
    }
    
    /**
     * Generate recommendation for a single product
     * 
     * @param int $productId Product ID
     * @return array|null Recommendation data or null if not applicable
     * @throws Exception If generation fails
     */
    private function generateSingleRecommendation(int $productId): ?array
    {
        // Get product information
        $productInfo = $this->salesAnalyzer->getProductInfo($productId);
        if (!$productInfo) {
            return null;
        }
        
        // Calculate ADS
        $ads = $this->salesAnalyzer->calculateADS($productId);
        
        // Skip products with ADS below threshold
        if ($ads < $this->config['min_ads_threshold']) {
            return null;
        }
        
        // Calculate complete recommendation
        $recommendation = $this->stockCalculator->calculateCompleteRecommendation($productId, $ads);
        
        // Add product information
        $recommendation['product_name'] = $productInfo['name'];
        $recommendation['sku'] = $productInfo['sku'];
        
        return $recommendation;
    }
    
    /**
     * Generate report summary statistics
     * 
     * @param array $recommendations All recommendations
     * @return array Summary statistics
     */
    private function generateReportSummary(array $recommendations): array
    {
        $totalProducts = count($recommendations);
        $actionableCount = 0;
        $totalRecommendedQuantity = 0;
        $averageADS = 0;
        $stockSufficientCount = 0;
        
        foreach ($recommendations as $rec) {
            if ($rec['recommended_quantity'] > 0) {
                $actionableCount++;
                $totalRecommendedQuantity += $rec['recommended_quantity'];
            } else {
                $stockSufficientCount++;
            }
            
            $averageADS += $rec['ads'];
        }
        
        $averageADS = $totalProducts > 0 ? round($averageADS / $totalProducts, 2) : 0;
        
        return [
            'total_products_analyzed' => $totalProducts,
            'actionable_recommendations' => $actionableCount,
            'stock_sufficient_products' => $stockSufficientCount,
            'total_recommended_quantity' => $totalRecommendedQuantity,
            'average_ads' => $averageADS,
            'actionable_percentage' => $totalProducts > 0 ? round(($actionableCount / $totalProducts) * 100, 1) : 0
        ];
    }
    
    /**
     * Start calculation log entry
     * 
     * @return int Calculation ID
     */
    private function startCalculationLog(): int
    {
        $sql = "
            INSERT INTO replenishment_calculations (
                calculation_date, status, started_at
            ) VALUES (
                CURDATE(), 'running', NOW()
            )
        ";
        
        try {
            $this->pdo->exec($sql);
            return $this->pdo->lastInsertId();
        } catch (Exception $e) {
            $this->log("Warning: Could not create calculation log: " . $e->getMessage());
            return 0; // Return 0 if logging fails, but don't stop execution
        }
    }
    
    /**
     * Complete calculation log entry
     * 
     * @param int $calculationId Calculation ID
     * @param array $metrics Execution metrics
     */
    private function completeCalculationLog(int $calculationId, array $metrics): void
    {
        if ($calculationId === 0) {
            return; // Skip if no log entry was created
        }
        
        $sql = "
            UPDATE replenishment_calculations 
            SET products_processed = :products_processed,
                recommendations_generated = :recommendations_generated,
                execution_time_seconds = :execution_time_seconds,
                memory_usage_mb = :memory_usage_mb,
                status = :status,
                error_message = :error_message,
                error_count = :error_count,
                completed_at = NOW()
            WHERE id = :calculation_id
        ";
        
        try {
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute([
                'calculation_id' => $calculationId,
                'products_processed' => $metrics['products_processed'],
                'recommendations_generated' => $metrics['recommendations_generated'],
                'execution_time_seconds' => $metrics['execution_time_seconds'],
                'memory_usage_mb' => $metrics['memory_usage_mb'],
                'status' => $metrics['status'],
                'error_message' => $metrics['error_message'] ?? null,
                'error_count' => $metrics['error_count']
            ]);
        } catch (Exception $e) {
            $this->log("Warning: Could not update calculation log: " . $e->getMessage());
        }
    }
    
    /**
     * Report progress during calculation
     * 
     * @param int $processed Number of products processed
     * @param int $total Total number of products
     * @param int $calculationId Calculation ID
     */
    private function reportProgress(int $processed, int $total, int $calculationId): void
    {
        $percentage = $total > 0 ? round(($processed / $total) * 100, 1) : 0;
        
        $this->log("Progress: $processed/$total products processed ($percentage%)");
        
        // Call progress callback if provided
        if (is_callable($this->config['progress_callback'])) {
            call_user_func($this->config['progress_callback'], $processed, $total, $percentage);
        }
        
        // Update calculation log with progress
        if ($calculationId > 0) {
            try {
                $sql = "UPDATE replenishment_calculations SET products_processed = ? WHERE id = ?";
                $stmt = $this->pdo->prepare($sql);
                $stmt->execute([$processed, $calculationId]);
            } catch (Exception $e) {
                // Ignore progress update errors
            }
        }
    }
    
    /**
     * Get recommendations with filtering and sorting options
     * 
     * @param array $filters Filtering options
     * @return array Filtered and sorted recommendations
     * @throws Exception If query fails
     */
    public function getRecommendations(array $filters = []): array
    {
        $this->log("Getting recommendations with filters: " . json_encode($filters));
        
        // Default filters
        $defaultFilters = [
            'calculation_date' => null,        // Specific date or null for latest
            'min_ads' => null,                 // Minimum ADS threshold
            'min_recommended_quantity' => null, // Minimum recommended quantity
            'product_ids' => null,             // Specific product IDs
            'actionable_only' => false,        // Only recommendations with quantity > 0
            'sort_by' => 'recommended_quantity', // Sort field
            'sort_order' => 'DESC',            // Sort order (ASC/DESC)
            'limit' => null,                   // Limit results
            'offset' => 0                      // Offset for pagination
        ];
        
        $filters = array_merge($defaultFilters, $filters);
        
        try {
            // Build WHERE clause
            $whereConditions = [];
            $params = [];
            
            // Filter by calculation date
            if ($filters['calculation_date']) {
                $whereConditions[] = "calculation_date = :calculation_date";
                $params['calculation_date'] = $filters['calculation_date'];
            } else {
                // Get latest calculation date if not specified
                $latestDate = $this->getLatestCalculationDate();
                if ($latestDate) {
                    $whereConditions[] = "calculation_date = :calculation_date";
                    $params['calculation_date'] = $latestDate;
                }
            }
            
            // Filter by minimum ADS
            if ($filters['min_ads'] !== null) {
                $whereConditions[] = "ads >= :min_ads";
                $params['min_ads'] = $filters['min_ads'];
            }
            
            // Filter by minimum recommended quantity
            if ($filters['min_recommended_quantity'] !== null) {
                $whereConditions[] = "recommended_quantity >= :min_recommended_quantity";
                $params['min_recommended_quantity'] = $filters['min_recommended_quantity'];
            }
            
            // Filter by specific product IDs
            if ($filters['product_ids'] && is_array($filters['product_ids'])) {
                $placeholders = str_repeat('?,', count($filters['product_ids']) - 1) . '?';
                $whereConditions[] = "product_id IN ($placeholders)";
                $params = array_merge($params, $filters['product_ids']);
            }
            
            // Filter actionable recommendations only
            if ($filters['actionable_only']) {
                $whereConditions[] = "recommended_quantity > 0";
            }
            
            // Build ORDER BY clause
            $validSortFields = [
                'recommended_quantity', 'ads', 'current_stock', 'target_stock', 
                'product_name', 'calculation_date', 'created_at'
            ];
            
            $sortBy = in_array($filters['sort_by'], $validSortFields) 
                ? $filters['sort_by'] 
                : 'recommended_quantity';
                
            $sortOrder = strtoupper($filters['sort_order']) === 'ASC' ? 'ASC' : 'DESC';
            
            // Build complete query
            $sql = "
                SELECT 
                    id,
                    product_id,
                    product_name,
                    sku,
                    ads,
                    current_stock,
                    target_stock,
                    recommended_quantity,
                    calculation_date,
                    created_at,
                    updated_at
                FROM replenishment_recommendations
            ";
            
            if (!empty($whereConditions)) {
                $sql .= " WHERE " . implode(' AND ', $whereConditions);
            }
            
            $sql .= " ORDER BY $sortBy $sortOrder";
            
            // Add pagination
            if ($filters['limit']) {
                $sql .= " LIMIT " . (int)$filters['limit'];
                if ($filters['offset']) {
                    $sql .= " OFFSET " . (int)$filters['offset'];
                }
            }
            
            // Execute query
            if (!empty($params)) {
                $stmt = $this->pdo->prepare($sql);
                $stmt->execute($params);
            } else {
                $stmt = $this->pdo->query($sql);
            }
            
            $recommendations = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Add calculated fields
            foreach ($recommendations as &$recommendation) {
                $recommendation['is_actionable'] = $recommendation['recommended_quantity'] > 0;
                $recommendation['stock_status'] = $this->getStockStatus($recommendation);
                $recommendation['priority'] = $this->calculatePriority($recommendation);
            }
            
            $this->log("Retrieved " . count($recommendations) . " recommendations");
            
            return $recommendations;
            
        } catch (Exception $e) {
            $this->log("Error getting recommendations: " . $e->getMessage());
            throw new Exception("Failed to get recommendations: " . $e->getMessage());
        }
    }
    
    /**
     * Get recommendations with pagination support
     * 
     * @param int $page Page number (1-based)
     * @param int $perPage Items per page
     * @param array $filters Additional filters
     * @return array Paginated results with metadata
     * @throws Exception If query fails
     */
    public function getRecommendationsPaginated(int $page = 1, int $perPage = 50, array $filters = []): array
    {
        $page = max(1, $page);
        $perPage = max(1, min(1000, $perPage)); // Limit to reasonable range
        
        $this->log("Getting paginated recommendations: page $page, $perPage per page");
        
        // Get total count first
        $totalCount = $this->getRecommendationsCount($filters);
        
        // Calculate pagination
        $totalPages = ceil($totalCount / $perPage);
        $offset = ($page - 1) * $perPage;
        
        // Add pagination to filters
        $filters['limit'] = $perPage;
        $filters['offset'] = $offset;
        
        // Get recommendations
        $recommendations = $this->getRecommendations($filters);
        
        return [
            'data' => $recommendations,
            'pagination' => [
                'current_page' => $page,
                'per_page' => $perPage,
                'total_items' => $totalCount,
                'total_pages' => $totalPages,
                'has_next_page' => $page < $totalPages,
                'has_prev_page' => $page > 1
            ]
        ];
    }
    
    /**
     * Get count of recommendations matching filters
     * 
     * @param array $filters Filtering options
     * @return int Count of matching recommendations
     * @throws Exception If query fails
     */
    public function getRecommendationsCount(array $filters = []): int
    {
        try {
            // Build WHERE clause (similar to getRecommendations but for COUNT)
            $whereConditions = [];
            $params = [];
            
            // Filter by calculation date
            if (isset($filters['calculation_date']) && $filters['calculation_date']) {
                $whereConditions[] = "calculation_date = :calculation_date";
                $params['calculation_date'] = $filters['calculation_date'];
            } else {
                // Get latest calculation date if not specified
                $latestDate = $this->getLatestCalculationDate();
                if ($latestDate) {
                    $whereConditions[] = "calculation_date = :calculation_date";
                    $params['calculation_date'] = $latestDate;
                }
            }
            
            // Filter by minimum ADS
            if (isset($filters['min_ads']) && $filters['min_ads'] !== null) {
                $whereConditions[] = "ads >= :min_ads";
                $params['min_ads'] = $filters['min_ads'];
            }
            
            // Filter by minimum recommended quantity
            if (isset($filters['min_recommended_quantity']) && $filters['min_recommended_quantity'] !== null) {
                $whereConditions[] = "recommended_quantity >= :min_recommended_quantity";
                $params['min_recommended_quantity'] = $filters['min_recommended_quantity'];
            }
            
            // Filter by specific product IDs
            if (isset($filters['product_ids']) && is_array($filters['product_ids'])) {
                $placeholders = str_repeat('?,', count($filters['product_ids']) - 1) . '?';
                $whereConditions[] = "product_id IN ($placeholders)";
                $params = array_merge($params, $filters['product_ids']);
            }
            
            // Filter actionable recommendations only
            if (isset($filters['actionable_only']) && $filters['actionable_only']) {
                $whereConditions[] = "recommended_quantity > 0";
            }
            
            // Build query
            $sql = "SELECT COUNT(*) FROM replenishment_recommendations";
            
            if (!empty($whereConditions)) {
                $sql .= " WHERE " . implode(' AND ', $whereConditions);
            }
            
            // Execute query
            if (!empty($params)) {
                $stmt = $this->pdo->prepare($sql);
                $stmt->execute($params);
            } else {
                $stmt = $this->pdo->query($sql);
            }
            
            return (int)$stmt->fetchColumn();
            
        } catch (Exception $e) {
            $this->log("Error getting recommendations count: " . $e->getMessage());
            throw new Exception("Failed to get recommendations count: " . $e->getMessage());
        }
    }
    
    /**
     * Get recommendations filtered by minimum ADS threshold
     * 
     * @param float $minADS Minimum ADS threshold
     * @param string $sortOrder Sort order (ASC/DESC)
     * @return array Filtered recommendations
     */
    public function getRecommendationsByADS(float $minADS, string $sortOrder = 'DESC'): array
    {
        return $this->getRecommendations([
            'min_ads' => $minADS,
            'sort_by' => 'ads',
            'sort_order' => $sortOrder
        ]);
    }
    
    /**
     * Get actionable recommendations (recommended_quantity > 0) sorted by priority
     * 
     * @param int|null $limit Limit number of results
     * @return array Actionable recommendations
     */
    public function getActionableRecommendations(?int $limit = null): array
    {
        $filters = [
            'actionable_only' => true,
            'sort_by' => 'recommended_quantity',
            'sort_order' => 'DESC'
        ];
        
        if ($limit) {
            $filters['limit'] = $limit;
        }
        
        return $this->getRecommendations($filters);
    }
    
    /**
     * Get top recommendations by recommended quantity
     * 
     * @param int $limit Number of top recommendations
     * @return array Top recommendations
     */
    public function getTopRecommendations(int $limit = 20): array
    {
        return $this->getRecommendations([
            'actionable_only' => true,
            'sort_by' => 'recommended_quantity',
            'sort_order' => 'DESC',
            'limit' => $limit
        ]);
    }
    
    /**
     * Search recommendations by product name or SKU
     * 
     * @param string $searchTerm Search term
     * @param array $additionalFilters Additional filters
     * @return array Matching recommendations
     * @throws Exception If search fails
     */
    public function searchRecommendations(string $searchTerm, array $additionalFilters = []): array
    {
        $this->log("Searching recommendations for: $searchTerm");
        
        try {
            // Build search WHERE clause
            $searchConditions = [
                "product_name LIKE :search_term",
                "sku LIKE :search_term"
            ];
            
            // Get base filters
            $whereConditions = [];
            $params = [];
            
            // Add calculation date filter
            $latestDate = $this->getLatestCalculationDate();
            if ($latestDate) {
                $whereConditions[] = "calculation_date = :calculation_date";
                $params['calculation_date'] = $latestDate;
            }
            
            // Add search condition
            $whereConditions[] = "(" . implode(' OR ', $searchConditions) . ")";
            $params['search_term'] = "%$searchTerm%";
            
            // Add additional filters
            if (isset($additionalFilters['min_ads'])) {
                $whereConditions[] = "ads >= :min_ads";
                $params['min_ads'] = $additionalFilters['min_ads'];
            }
            
            if (isset($additionalFilters['actionable_only']) && $additionalFilters['actionable_only']) {
                $whereConditions[] = "recommended_quantity > 0";
            }
            
            // Build query
            $sql = "
                SELECT 
                    id, product_id, product_name, sku, ads, current_stock,
                    target_stock, recommended_quantity, calculation_date,
                    created_at, updated_at
                FROM replenishment_recommendations
                WHERE " . implode(' AND ', $whereConditions) . "
                ORDER BY recommended_quantity DESC
            ";
            
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($params);
            
            $recommendations = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Add calculated fields
            foreach ($recommendations as &$recommendation) {
                $recommendation['is_actionable'] = $recommendation['recommended_quantity'] > 0;
                $recommendation['stock_status'] = $this->getStockStatus($recommendation);
                $recommendation['priority'] = $this->calculatePriority($recommendation);
            }
            
            $this->log("Found " . count($recommendations) . " recommendations matching search");
            
            return $recommendations;
            
        } catch (Exception $e) {
            $this->log("Error searching recommendations: " . $e->getMessage());
            throw new Exception("Failed to search recommendations: " . $e->getMessage());
        }
    }
    
    /**
     * Get latest calculation date
     * 
     * @return string|null Latest calculation date
     */
    private function getLatestCalculationDate(): ?string
    {
        try {
            $sql = "SELECT MAX(calculation_date) FROM replenishment_recommendations";
            $stmt = $this->pdo->query($sql);
            return $stmt->fetchColumn() ?: null;
        } catch (Exception $e) {
            $this->log("Error getting latest calculation date: " . $e->getMessage());
            return null;
        }
    }
    
    /**
     * Get stock status description
     * 
     * @param array $recommendation Recommendation data
     * @return string Stock status
     */
    private function getStockStatus(array $recommendation): string
    {
        $currentStock = $recommendation['current_stock'];
        $targetStock = $recommendation['target_stock'];
        $recommendedQuantity = $recommendation['recommended_quantity'];
        
        if ($recommendedQuantity <= 0) {
            return 'sufficient';
        } elseif ($currentStock <= 0) {
            return 'out_of_stock';
        } elseif ($currentStock < ($targetStock * 0.3)) {
            return 'critical';
        } elseif ($currentStock < ($targetStock * 0.6)) {
            return 'low';
        } else {
            return 'moderate';
        }
    }
    
    /**
     * Calculate priority score for recommendation
     * 
     * @param array $recommendation Recommendation data
     * @return int Priority score (1-10, higher is more urgent)
     */
    private function calculatePriority(array $recommendation): int
    {
        $ads = $recommendation['ads'];
        $currentStock = $recommendation['current_stock'];
        $recommendedQuantity = $recommendation['recommended_quantity'];
        
        // Base priority on ADS (higher ADS = higher priority)
        $priority = min(5, ceil($ads / 2));
        
        // Increase priority for out of stock items
        if ($currentStock <= 0) {
            $priority += 3;
        }
        
        // Increase priority for high recommended quantities
        if ($recommendedQuantity > 50) {
            $priority += 2;
        } elseif ($recommendedQuantity > 20) {
            $priority += 1;
        }
        
        return min(10, max(1, $priority));
    }
    
    /**
     * Log debug messages
     * 
     * @param string $message Message to log
     */
    private function log(string $message): void
    {
        if ($this->config['debug']) {
            echo "[ReplenishmentRecommender] " . date('Y-m-d H:i:s') . " - $message\n";
        }
    }
}
?>