<?php

namespace Replenishment;

use PDO;
use Exception;

/**
 * StockCalculator Class
 * 
 * Calculates target stock levels and replenishment recommendations based on ADS
 * and configurable parameters like replenishment days and safety stock.
 */
class StockCalculator
{
    private PDO $pdo;
    private array $config;
    private array $parameterCache = [];
    
    public function __construct(PDO $pdo, array $config = [])
    {
        $this->pdo = $pdo;
        $this->config = array_merge([
            'replenishment_days' => 14,
            'safety_days' => 7,
            'cache_ttl' => 300, // 5 minutes cache TTL
            'debug' => false
        ], $config);
    }
    
    /**
     * Calculate target stock level based on ADS and total days
     * Formula: ADS × (replenishment_days + safety_days)
     * 
     * @param float $ads Average Daily Sales
     * @param int|null $replenishmentDays Days needed for replenishment (optional, uses config)
     * @param int|null $safetyDays Safety stock days (optional, uses config)
     * @return float Target stock level
     * @throws Exception If parameters are invalid
     */
    public function calculateTargetStock(float $ads, ?int $replenishmentDays = null, ?int $safetyDays = null): float
    {
        // Validate ADS parameter
        if ($ads < 0) {
            throw new Exception("ADS cannot be negative: $ads");
        }
        
        // Get parameters from config or database if not provided
        $params = $this->getReplenishmentParameters();
        $replenishmentDays = $replenishmentDays ?? $params['replenishment_days'];
        $safetyDays = $safetyDays ?? $params['safety_days'];
        
        // Validate parameters
        if ($replenishmentDays < 0) {
            throw new Exception("Replenishment days cannot be negative: $replenishmentDays");
        }
        
        if ($safetyDays < 0) {
            throw new Exception("Safety days cannot be negative: $safetyDays");
        }
        
        $totalDays = $replenishmentDays + $safetyDays;
        $targetStock = $ads * $totalDays;
        
        $this->log("Target stock calculation: ADS=$ads × ($replenishmentDays + $safetyDays) = $targetStock");
        
        return round($targetStock, 2);
    }
    
    /**
     * Calculate replenishment recommendation
     * Formula: max(0, target_stock - current_stock)
     * 
     * @param float $targetStock Target stock level
     * @param int $currentStock Current stock level
     * @return int Recommended quantity to replenish (0 if stock is sufficient)
     * @throws Exception If parameters are invalid
     */
    public function calculateReplenishmentRecommendation(float $targetStock, int $currentStock): int
    {
        // Validate parameters
        if ($targetStock < 0) {
            throw new Exception("Target stock cannot be negative: $targetStock");
        }
        
        if ($currentStock < 0) {
            throw new Exception("Current stock cannot be negative: $currentStock");
        }
        
        $recommendation = max(0, $targetStock - $currentStock);
        $recommendationInt = (int)ceil($recommendation);
        
        $this->log("Replenishment calculation: max(0, $targetStock - $currentStock) = $recommendationInt");
        
        return $recommendationInt;
    }
    
    /**
     * Get current FBO stock for a product
     * 
     * @param int $productId Product ID
     * @return int Current FBO stock level
     * @throws Exception If product not found or query fails
     */
    public function getCurrentStock(int $productId): int
    {
        if ($productId <= 0) {
            throw new Exception("Invalid product ID: $productId");
        }
        
        $this->log("Getting current stock for product $productId");
        
        // Query to get the most recent stock from inventory_data
        // Prioritize available_stock, then current_stock as fallback
        $sql = "
            SELECT 
                COALESCE(available_stock, current_stock, 0) as current_stock
            FROM inventory_data 
            WHERE product_id = :product_id 
            ORDER BY created_at DESC 
            LIMIT 1
        ";
        
        try {
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute(['product_id' => $productId]);
            
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$result) {
                $this->log("No inventory data found for product $productId, assuming zero stock");
                return 0;
            }
            
            $currentStock = max(0, (int)$result['current_stock']);
            
            $this->log("Current stock for product $productId: $currentStock");
            
            return $currentStock;
            
        } catch (Exception $e) {
            $this->log("Error getting current stock: " . $e->getMessage());
            throw new Exception("Failed to get current stock for product $productId: " . $e->getMessage());
        }
    }
    
    /**
     * Get replenishment parameters from database with caching
     * 
     * @return array Configuration parameters
     * @throws Exception If parameters cannot be loaded
     */
    public function getReplenishmentParameters(): array
    {
        $cacheKey = 'replenishment_parameters';
        $currentTime = time();
        
        // Check cache first
        if (isset($this->parameterCache[$cacheKey]) && 
            ($currentTime - $this->parameterCache[$cacheKey]['timestamp']) < $this->config['cache_ttl']) {
            
            $this->log("Using cached replenishment parameters");
            return $this->parameterCache[$cacheKey]['data'];
        }
        
        $this->log("Loading replenishment parameters from database");
        
        // Load parameters from database
        $sql = "
            SELECT parameter_name, parameter_value 
            FROM replenishment_config 
            WHERE parameter_name IN ('replenishment_days', 'safety_days', 'analysis_days', 'min_ads_threshold')
        ";
        
        try {
            $stmt = $this->pdo->query($sql);
            $dbParams = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
            
            // Merge with defaults and validate
            $parameters = $this->validateAndMergeParameters($dbParams);
            
            // Cache the parameters
            $this->parameterCache[$cacheKey] = [
                'data' => $parameters,
                'timestamp' => $currentTime
            ];
            
            $this->log("Loaded parameters: " . json_encode($parameters));
            
            return $parameters;
            
        } catch (Exception $e) {
            $this->log("Error loading parameters from database: " . $e->getMessage());
            
            // Fall back to config defaults if database fails
            $this->log("Using fallback configuration parameters");
            return $this->getDefaultParameters();
        }
    }
    
    /**
     * Update replenishment parameters in database
     * 
     * @param array $parameters Parameters to update
     * @return bool Success status
     * @throws Exception If update fails
     */
    public function updateReplenishmentParameters(array $parameters): bool
    {
        $this->log("Updating replenishment parameters: " . json_encode($parameters));
        
        // Validate parameters before updating
        $validatedParams = $this->validateParameters($parameters);
        
        try {
            $this->pdo->beginTransaction();
            
            $sql = "
                INSERT INTO replenishment_config (parameter_name, parameter_value, description) 
                VALUES (:name, :value, :description)
                ON DUPLICATE KEY UPDATE 
                    parameter_value = VALUES(parameter_value),
                    updated_at = CURRENT_TIMESTAMP
            ";
            
            $stmt = $this->pdo->prepare($sql);
            
            $descriptions = [
                'replenishment_days' => 'Период пополнения в днях',
                'safety_days' => 'Страховой запас в днях',
                'analysis_days' => 'Период анализа продаж в днях',
                'min_ads_threshold' => 'Минимальный ADS для включения в рекомендации'
            ];
            
            foreach ($validatedParams as $name => $value) {
                $stmt->execute([
                    'name' => $name,
                    'value' => $value,
                    'description' => $descriptions[$name] ?? 'Параметр системы пополнения'
                ]);
            }
            
            $this->pdo->commit();
            
            // Clear cache after update
            $this->clearParameterCache();
            
            $this->log("Parameters updated successfully");
            
            return true;
            
        } catch (Exception $e) {
            $this->pdo->rollBack();
            $this->log("Error updating parameters: " . $e->getMessage());
            throw new Exception("Failed to update replenishment parameters: " . $e->getMessage());
        }
    }
    
    /**
     * Calculate complete replenishment recommendation for a product
     * 
     * @param int $productId Product ID
     * @param float $ads Average Daily Sales
     * @return array Complete recommendation data
     * @throws Exception If calculation fails
     */
    public function calculateCompleteRecommendation(int $productId, float $ads): array
    {
        $this->log("Calculating complete recommendation for product $productId with ADS $ads");
        
        try {
            // Get current stock
            $currentStock = $this->getCurrentStock($productId);
            
            // Calculate target stock
            $targetStock = $this->calculateTargetStock($ads);
            
            // Calculate recommendation
            $recommendedQuantity = $this->calculateReplenishmentRecommendation($targetStock, $currentStock);
            
            // Get parameters for reference
            $parameters = $this->getReplenishmentParameters();
            
            $recommendation = [
                'product_id' => $productId,
                'ads' => $ads,
                'current_stock' => $currentStock,
                'target_stock' => $targetStock,
                'recommended_quantity' => $recommendedQuantity,
                'replenishment_days' => $parameters['replenishment_days'],
                'safety_days' => $parameters['safety_days'],
                'is_sufficient' => $recommendedQuantity === 0,
                'calculated_at' => date('Y-m-d H:i:s')
            ];
            
            $this->log("Complete recommendation calculated: " . json_encode($recommendation));
            
            return $recommendation;
            
        } catch (Exception $e) {
            $this->log("Error calculating complete recommendation: " . $e->getMessage());
            throw new Exception("Failed to calculate recommendation for product $productId: " . $e->getMessage());
        }
    }
    
    /**
     * Validate and merge parameters with defaults
     * 
     * @param array $dbParams Parameters from database
     * @return array Validated and merged parameters
     */
    private function validateAndMergeParameters(array $dbParams): array
    {
        $defaults = $this->getDefaultParameters();
        
        // Convert string values to appropriate types and validate
        $parameters = [];
        
        foreach ($defaults as $key => $defaultValue) {
            if (isset($dbParams[$key])) {
                $value = $dbParams[$key];
                
                // Convert to appropriate type based on default
                if (is_int($defaultValue)) {
                    $parameters[$key] = (int)$value;
                } elseif (is_float($defaultValue)) {
                    $parameters[$key] = (float)$value;
                } else {
                    $parameters[$key] = $value;
                }
            } else {
                $parameters[$key] = $defaultValue;
            }
        }
        
        // Validate the merged parameters
        return $this->validateParameters($parameters);
    }
    
    /**
     * Validate parameters
     * 
     * @param array $parameters Parameters to validate
     * @return array Validated parameters
     * @throws Exception If validation fails
     */
    private function validateParameters(array $parameters): array
    {
        $errors = [];
        
        // Validate replenishment_days
        if (isset($parameters['replenishment_days'])) {
            $value = $parameters['replenishment_days'];
            if (!is_numeric($value) || $value < 1 || $value > 365) {
                $errors[] = "replenishment_days must be between 1 and 365 days, got: $value";
            }
        }
        
        // Validate safety_days
        if (isset($parameters['safety_days'])) {
            $value = $parameters['safety_days'];
            if (!is_numeric($value) || $value < 0 || $value > 90) {
                $errors[] = "safety_days must be between 0 and 90 days, got: $value";
            }
        }
        
        // Validate analysis_days
        if (isset($parameters['analysis_days'])) {
            $value = $parameters['analysis_days'];
            if (!is_numeric($value) || $value < 7 || $value > 365) {
                $errors[] = "analysis_days must be between 7 and 365 days, got: $value";
            }
        }
        
        // Validate min_ads_threshold
        if (isset($parameters['min_ads_threshold'])) {
            $value = $parameters['min_ads_threshold'];
            if (!is_numeric($value) || $value < 0 || $value > 100) {
                $errors[] = "min_ads_threshold must be between 0 and 100, got: $value";
            }
        }
        
        if (!empty($errors)) {
            throw new Exception("Parameter validation failed: " . implode(', ', $errors));
        }
        
        return $parameters;
    }
    
    /**
     * Get default parameters
     * 
     * @return array Default parameters
     */
    private function getDefaultParameters(): array
    {
        return [
            'replenishment_days' => $this->config['replenishment_days'],
            'safety_days' => $this->config['safety_days'],
            'analysis_days' => 30,
            'min_ads_threshold' => 0.1
        ];
    }
    
    /**
     * Clear parameter cache
     */
    private function clearParameterCache(): void
    {
        $this->parameterCache = [];
        $this->log("Parameter cache cleared");
    }
    
    /**
     * Log debug messages
     * 
     * @param string $message Message to log
     */
    private function log(string $message): void
    {
        if ($this->config['debug']) {
            echo "[StockCalculator] " . date('Y-m-d H:i:s') . " - $message\n";
        }
    }
}
?>