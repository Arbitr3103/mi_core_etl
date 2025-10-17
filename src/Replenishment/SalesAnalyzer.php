<?php

namespace Replenishment;

use PDO;
use Exception;
use DateTime;
use DateInterval;

/**
 * SalesAnalyzer Class
 * 
 * Analyzes sales data and calculates Average Daily Sales (ADS) for products.
 * Excludes days with zero stock from calculations to provide accurate demand metrics.
 */
class SalesAnalyzer
{
    private PDO $pdo;
    private array $config;
    
    public function __construct(PDO $pdo, array $config = [])
    {
        $this->pdo = $pdo;
        $this->config = array_merge([
            'analysis_days' => 30,
            'min_ads_threshold' => 0.1,
            'debug' => false
        ], $config);
    }
    
    /**
     * Calculate Average Daily Sales (ADS) for a product
     * 
     * @param int $productId Product ID
     * @param int $days Number of days to analyze (default from config)
     * @return float ADS value
     * @throws Exception If calculation fails
     */
    public function calculateADS(int $productId, ?int $days = null): float
    {
        $days = $days ?? $this->config['analysis_days'];
        
        if ($days <= 0) {
            throw new Exception("Analysis days must be positive");
        }
        
        $this->log("Calculating ADS for product $productId over $days days");
        
        // Get sales data for the period
        $salesData = $this->getSalesData($productId, $days);
        
        if (empty($salesData)) {
            $this->log("No sales data found for product $productId");
            return 0.0;
        }
        
        // Get valid sales days (excluding zero stock days)
        $validDays = $this->getValidSalesDays($productId, $days);
        
        if (empty($validDays)) {
            $this->log("No valid sales days found for product $productId");
            return 0.0;
        }
        
        // Calculate total sales
        $totalSales = 0;
        foreach ($salesData as $sale) {
            $saleDate = $sale['sale_date'];
            
            // Only count sales on days when stock was available
            if (in_array($saleDate, $validDays)) {
                $totalSales += (int)$sale['quantity_sold'];
            }
        }
        
        $validDaysCount = count($validDays);
        $ads = $validDaysCount > 0 ? $totalSales / $validDaysCount : 0.0;
        
        $this->log("Product $productId: Total sales = $totalSales, Valid days = $validDaysCount, ADS = $ads");
        
        return round($ads, 2);
    }
    
    /**
     * Get valid sales days (days with non-zero stock)
     * 
     * @param int $productId Product ID
     * @param int $days Number of days to check
     * @return array Array of valid dates
     */
    public function getValidSalesDays(int $productId, int $days): array
    {
        $endDate = new DateTime();
        $startDate = (clone $endDate)->sub(new DateInterval("P{$days}D"));
        
        $this->log("Getting valid sales days for product $productId from {$startDate->format('Y-m-d')} to {$endDate->format('Y-m-d')}");
        
        // Query to get days with non-zero stock from inventory_data
        // Use current_stock or available_stock to determine stock availability
        $sql = "
            SELECT DISTINCT DATE(created_at) as stock_date
            FROM inventory_data 
            WHERE product_id = :product_id 
                AND (current_stock > 0 OR available_stock > 0)
                AND DATE(created_at) BETWEEN :start_date AND :end_date
            ORDER BY stock_date
        ";
        
        try {
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute([
                'product_id' => $productId,
                'start_date' => $startDate->format('Y-m-d'),
                'end_date' => $endDate->format('Y-m-d')
            ]);
            
            $validDays = [];
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $validDays[] = $row['stock_date'];
            }
            
            $this->log("Found " . count($validDays) . " valid stock days for product $productId");
            
            return $validDays;
            
        } catch (Exception $e) {
            $this->log("Error getting valid sales days: " . $e->getMessage());
            throw new Exception("Failed to get valid sales days: " . $e->getMessage());
        }
    }
    
    /**
     * Get sales data for a product within specified period
     * 
     * @param int $productId Product ID
     * @param int $days Number of days to look back
     * @return array Sales data
     */
    public function getSalesData(int $productId, int $days): array
    {
        $endDate = new DateTime();
        $startDate = (clone $endDate)->sub(new DateInterval("P{$days}D"));
        
        $this->log("Getting sales data for product $productId from {$startDate->format('Y-m-d')} to {$endDate->format('Y-m-d')}");
        
        // Query to get sales data from fact_orders
        $sql = "
            SELECT 
                DATE(order_date) as sale_date,
                SUM(qty) as quantity_sold,
                COUNT(*) as order_count
            FROM fact_orders 
            WHERE product_id = :product_id 
                AND order_date BETWEEN :start_date AND :end_date
                AND qty > 0
            GROUP BY DATE(order_date)
            ORDER BY sale_date
        ";
        
        try {
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute([
                'product_id' => $productId,
                'start_date' => $startDate->format('Y-m-d'),
                'end_date' => $endDate->format('Y-m-d')
            ]);
            
            $salesData = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            $this->log("Found " . count($salesData) . " sales records for product $productId");
            
            return $salesData;
            
        } catch (Exception $e) {
            $this->log("Error getting sales data: " . $e->getMessage());
            throw new Exception("Failed to get sales data: " . $e->getMessage());
        }
    }
    
    /**
     * Validate sales data quality
     * 
     * @param array $salesData Sales data to validate
     * @return array Validation results
     */
    public function validateSalesData(array $salesData): array
    {
        $validation = [
            'is_valid' => true,
            'warnings' => [],
            'errors' => [],
            'stats' => [
                'total_records' => count($salesData),
                'total_quantity' => 0,
                'date_range' => null,
                'avg_daily_sales' => 0
            ]
        ];
        
        if (empty($salesData)) {
            $validation['is_valid'] = false;
            $validation['errors'][] = 'No sales data provided';
            return $validation;
        }
        
        $totalQuantity = 0;
        $dates = [];
        
        foreach ($salesData as $index => $sale) {
            // Check required fields
            if (!isset($sale['sale_date']) || !isset($sale['quantity_sold'])) {
                $validation['errors'][] = "Missing required fields in record $index";
                $validation['is_valid'] = false;
                continue;
            }
            
            // Validate quantity
            $quantity = (int)$sale['quantity_sold'];
            if ($quantity < 0) {
                $validation['warnings'][] = "Negative quantity in record $index: $quantity";
            }
            
            $totalQuantity += $quantity;
            $dates[] = $sale['sale_date'];
        }
        
        // Calculate stats
        $validation['stats']['total_quantity'] = $totalQuantity;
        
        if (!empty($dates)) {
            sort($dates);
            $validation['stats']['date_range'] = [
                'start' => $dates[0],
                'end' => end($dates)
            ];
            
            $validation['stats']['avg_daily_sales'] = count($salesData) > 0 
                ? $totalQuantity / count($salesData) 
                : 0;
        }
        
        // Quality checks
        if ($totalQuantity === 0) {
            $validation['warnings'][] = 'Total sales quantity is zero';
        }
        
        if (count($salesData) < 7) {
            $validation['warnings'][] = 'Less than 7 days of sales data available';
        }
        
        return $validation;
    }
    
    /**
     * Get product information for analysis
     * 
     * @param int $productId Product ID
     * @return array|null Product information
     */
    public function getProductInfo(int $productId): ?array
    {
        $sql = "
            SELECT 
                id,
                name,
                sku_ozon as sku,
                is_active
            FROM dim_products 
            WHERE id = :product_id
        ";
        
        try {
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute(['product_id' => $productId]);
            
            $product = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$product) {
                $this->log("Product $productId not found");
                return null;
            }
            
            $this->log("Found product: {$product['name']} (SKU: {$product['sku']})");
            
            return $product;
            
        } catch (Exception $e) {
            $this->log("Error getting product info: " . $e->getMessage());
            throw new Exception("Failed to get product info: " . $e->getMessage());
        }
    }
    
    /**
     * Get all active products for analysis
     * 
     * @return array Array of product IDs
     */
    public function getActiveProducts(): array
    {
        $sql = "
            SELECT id, name, sku_ozon as sku
            FROM dim_products 
            WHERE is_active = 1
            ORDER BY name
        ";
        
        try {
            $stmt = $this->pdo->query($sql);
            $products = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            $this->log("Found " . count($products) . " active products");
            
            return $products;
            
        } catch (Exception $e) {
            $this->log("Error getting active products: " . $e->getMessage());
            throw new Exception("Failed to get active products: " . $e->getMessage());
        }
    }
    
    /**
     * Calculate ADS for multiple products
     * 
     * @param array $productIds Array of product IDs
     * @param int $days Number of days to analyze
     * @return array ADS results for each product
     */
    public function calculateBatchADS(array $productIds, ?int $days = null): array
    {
        $days = $days ?? $this->config['analysis_days'];
        $results = [];
        
        $this->log("Calculating batch ADS for " . count($productIds) . " products");
        
        foreach ($productIds as $productId) {
            try {
                $productInfo = $this->getProductInfo($productId);
                if (!$productInfo) {
                    continue;
                }
                
                $ads = $this->calculateADS($productId, $days);
                
                $results[] = [
                    'product_id' => $productId,
                    'product_name' => $productInfo['name'],
                    'sku' => $productInfo['sku'],
                    'ads' => $ads,
                    'is_valid' => $ads >= $this->config['min_ads_threshold'],
                    'calculated_at' => date('Y-m-d H:i:s')
                ];
                
            } catch (Exception $e) {
                $this->log("Error calculating ADS for product $productId: " . $e->getMessage());
                
                $results[] = [
                    'product_id' => $productId,
                    'product_name' => 'Unknown',
                    'sku' => null,
                    'ads' => 0.0,
                    'is_valid' => false,
                    'error' => $e->getMessage(),
                    'calculated_at' => date('Y-m-d H:i:s')
                ];
            }
        }
        
        $this->log("Batch ADS calculation completed. " . count($results) . " results generated");
        
        return $results;
    }
    
    /**
     * Log debug messages
     * 
     * @param string $message Message to log
     */
    private function log(string $message): void
    {
        if ($this->config['debug']) {
            echo "[SalesAnalyzer] " . date('Y-m-d H:i:s') . " - $message\n";
        }
    }
}
?>