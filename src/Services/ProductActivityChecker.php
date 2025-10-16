<?php

namespace Services;

/**
 * ProductActivityChecker - Determines product activity status based on combined criteria
 * 
 * This service checks if products are active based on:
 * - visibility = "VISIBLE" 
 * - state = "processed"
 * - present > 0 (stock availability)
 * - pricing information available
 */
class ProductActivityChecker
{
    private array $config;
    
    public function __construct(array $config = [])
    {
        $this->config = array_merge([
            'min_stock_threshold' => 0,
            'required_visibility' => 'VISIBLE',
            'required_state' => 'processed',
            'require_pricing' => true
        ], $config);
    }
    
    /**
     * Determines if a product is active based on combined criteria
     * 
     * @param array $productData Product information from Ozon API
     * @param array $stockData Stock information from Ozon API  
     * @param array $priceData Price information from Ozon API
     * @return bool True if product is active, false otherwise
     */
    public function isProductActive(array $productData, array $stockData = [], array $priceData = []): bool
    {
        // Check visibility criteria
        if (!$this->checkVisibility($productData)) {
            return false;
        }
        
        // Check state criteria
        if (!$this->checkState($productData)) {
            return false;
        }
        
        // Check stock criteria
        if (!$this->checkStock($stockData)) {
            return false;
        }
        
        // Check pricing criteria
        if (!$this->checkPricing($priceData)) {
            return false;
        }
        
        return true;
    }
    
    /**
     * Gets detailed reason why product is active or inactive
     * 
     * @param array $productData Product information
     * @param array $stockData Stock information
     * @param array $priceData Price information
     * @return string Detailed reason for activity status
     */
    public function getActivityReason(array $productData, array $stockData = [], array $priceData = []): string
    {
        $reasons = [];
        
        if (!$this->checkVisibility($productData)) {
            $visibility = $productData['visibility'] ?? 'unknown';
            $reasons[] = "visibility is '{$visibility}' (required: {$this->config['required_visibility']})";
        }
        
        if (!$this->checkState($productData)) {
            $state = $productData['state'] ?? 'unknown';
            $reasons[] = "state is '{$state}' (required: {$this->config['required_state']})";
        }
        
        if (!$this->checkStock($stockData)) {
            $present = $stockData['present'] ?? 0;
            $reasons[] = "stock is {$present} (required: > {$this->config['min_stock_threshold']})";
        }
        
        if (!$this->checkPricing($priceData)) {
            $reasons[] = "pricing information missing or invalid";
        }
        
        if (empty($reasons)) {
            return "Product is active - all criteria met";
        }
        
        return "Product is inactive: " . implode(', ', $reasons);
    }
    
    /**
     * Efficiently checks activity status for multiple products
     * 
     * @param array $products Array of products with their data
     * @return array Array with product IDs as keys and activity status as values
     */
    public function batchCheckActivity(array $products): array
    {
        $results = [];
        
        foreach ($products as $productId => $productInfo) {
            $productData = $productInfo['product'] ?? [];
            $stockData = $productInfo['stock'] ?? [];
            $priceData = $productInfo['price'] ?? [];
            
            $results[$productId] = [
                'is_active' => $this->isProductActive($productData, $stockData, $priceData),
                'reason' => $this->getActivityReason($productData, $stockData, $priceData),
                'checked_at' => date('Y-m-d H:i:s'),
                'criteria' => [
                    'visibility_ok' => $this->checkVisibility($productData),
                    'state_ok' => $this->checkState($productData),
                    'stock_ok' => $this->checkStock($stockData),
                    'pricing_ok' => $this->checkPricing($priceData)
                ]
            ];
        }
        
        return $results;
    }
    
    /**
     * Check if product visibility meets criteria
     */
    private function checkVisibility(array $productData): bool
    {
        $visibility = $productData['visibility'] ?? '';
        return $visibility === $this->config['required_visibility'];
    }
    
    /**
     * Check if product state meets criteria
     */
    private function checkState(array $productData): bool
    {
        $state = $productData['state'] ?? '';
        return $state === $this->config['required_state'];
    }
    
    /**
     * Check if product stock meets criteria
     */
    private function checkStock(array $stockData): bool
    {
        $present = $stockData['present'] ?? 0;
        return $present > $this->config['min_stock_threshold'];
    }
    
    /**
     * Check if product pricing meets criteria
     */
    private function checkPricing(array $priceData): bool
    {
        if (!$this->config['require_pricing']) {
            return true;
        }
        
        // Check if price data exists and has valid price
        if (empty($priceData)) {
            return false;
        }
        
        $price = $priceData['price'] ?? 0;
        $oldPrice = $priceData['old_price'] ?? 0;
        
        // At least one price should be set and greater than 0
        return ($price > 0 || $oldPrice > 0);
    }
    
    /**
     * Get configuration for debugging
     */
    public function getConfig(): array
    {
        return $this->config;
    }
    
    /**
     * Update configuration
     */
    public function updateConfig(array $newConfig): void
    {
        $this->config = array_merge($this->config, $newConfig);
    }
}