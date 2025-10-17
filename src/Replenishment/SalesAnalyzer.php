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
        
        // Analyze boundary conditions first
        $boundaryAnalysis = $this->analyzeBoundaryConditions($productId, $days);
        
        if (!$boundaryAnalysis['has_sales_data']) {
            $this->logDataQuality("Cannot calculate ADS: no sales data available", $productId, 'warning');
            return 0.0;
        }
        
        if (!$boundaryAnalysis['has_stock_data']) {
            $this->logDataQuality("Cannot calculate ADS: no stock data available", $productId, 'warning');
            return 0.0;
        }
        
        // Get sales data for the period
        $salesData = $this->getSalesData($productId, $days);
        
        // Validate sales data quality
        $validation = $this->validateSalesData($salesData, $productId);
        
        if (!$validation['is_valid']) {
            $errors = implode(', ', $validation['errors']);
            throw new Exception("Sales data validation failed: $errors");
        }
        
        // Filter sales data to exclude zero-stock days
        $filteredSalesData = $this->filterSalesDataByStock($salesData, $productId, $days);
        
        if (empty($filteredSalesData)) {
            $this->logDataQuality("No valid sales data after filtering zero-stock days", $productId, 'warning');
            return 0.0;
        }
        
        // Check if we have sufficient data after filtering
        if (!$boundaryAnalysis['sufficient_data']) {
            $this->logDataQuality("Insufficient data for reliable ADS calculation", $productId, 'warning');
            // Continue with calculation but log the warning
        }
        
        // Calculate total sales from filtered data
        $totalSales = 0;
        foreach ($filteredSalesData as $sale) {
            $quantity = max(0, (int)$sale['quantity_sold']); // Ensure non-negative
            $totalSales += $quantity;
        }
        
        $validDaysCount = count($filteredSalesData);
        $ads = $validDaysCount > 0 ? $totalSales / $validDaysCount : 0.0;
        
        $this->log("Product $productId: Total sales = $totalSales, Valid days = $validDaysCount, ADS = $ads");
        
        // Log data quality summary
        $warningCount = count($validation['warnings']);
        if ($warningCount > 0) {
            $this->logDataQuality("ADS calculated with $warningCount data quality warnings", $productId, 'info');
        }
        
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
     * @param int|null $productId Product ID for additional validation
     * @return array Validation results
     */
    public function validateSalesData(array $salesData, ?int $productId = null): array
    {
        $validation = [
            'is_valid' => true,
            'warnings' => [],
            'errors' => [],
            'stats' => [
                'total_records' => count($salesData),
                'total_quantity' => 0,
                'date_range' => null,
                'avg_daily_sales' => 0,
                'zero_sales_days' => 0,
                'negative_sales_days' => 0,
                'data_gaps' => []
            ]
        ];
        
        // Log validation start
        $this->logDataQuality("Starting sales data validation", $productId, 'info');
        
        // Handle empty data - boundary condition
        if (empty($salesData)) {
            $validation['is_valid'] = false;
            $validation['errors'][] = 'No sales data provided';
            $this->logDataQuality("No sales data provided for validation", $productId, 'error');
            return $validation;
        }
        
        $totalQuantity = 0;
        $dates = [];
        $zeroSalesDays = 0;
        $negativeSalesDays = 0;
        
        foreach ($salesData as $index => $sale) {
            // Check required fields
            if (!isset($sale['sale_date']) || !isset($sale['quantity_sold'])) {
                $validation['errors'][] = "Missing required fields in record $index (sale_date or quantity_sold)";
                $validation['is_valid'] = false;
                $this->logDataQuality("Missing required fields in sales record $index", $productId, 'error');
                continue;
            }
            
            // Validate date format
            if (!$this->isValidDate($sale['sale_date'])) {
                $validation['errors'][] = "Invalid date format in record $index: {$sale['sale_date']}";
                $validation['is_valid'] = false;
                $this->logDataQuality("Invalid date format in record $index: {$sale['sale_date']}", $productId, 'error');
                continue;
            }
            
            // Validate quantity
            $quantity = (int)$sale['quantity_sold'];
            
            if ($quantity < 0) {
                $validation['warnings'][] = "Negative quantity in record $index: $quantity";
                $negativeSalesDays++;
                $this->logDataQuality("Negative sales quantity detected: $quantity on {$sale['sale_date']}", $productId, 'warning');
            } elseif ($quantity === 0) {
                $zeroSalesDays++;
            }
            
            $totalQuantity += max(0, $quantity); // Only count positive quantities in total
            $dates[] = $sale['sale_date'];
        }
        
        // Calculate stats
        $validation['stats']['total_quantity'] = $totalQuantity;
        $validation['stats']['zero_sales_days'] = $zeroSalesDays;
        $validation['stats']['negative_sales_days'] = $negativeSalesDays;
        
        if (!empty($dates)) {
            sort($dates);
            $validation['stats']['date_range'] = [
                'start' => $dates[0],
                'end' => end($dates)
            ];
            
            // Calculate average excluding zero and negative sales days
            $validSalesDays = count($salesData) - $zeroSalesDays - $negativeSalesDays;
            $validation['stats']['avg_daily_sales'] = $validSalesDays > 0 
                ? $totalQuantity / $validSalesDays 
                : 0;
                
            // Check for data gaps
            $validation['stats']['data_gaps'] = $this->detectDataGaps($dates);
        }
        
        // Boundary condition checks
        if ($totalQuantity === 0) {
            $validation['warnings'][] = 'Total sales quantity is zero - no demand detected';
            $this->logDataQuality("Zero total sales detected - no demand for product", $productId, 'warning');
        }
        
        // Insufficient data boundary condition
        if (count($salesData) < 7) {
            $validation['warnings'][] = 'Less than 7 days of sales data available - results may be unreliable';
            $this->logDataQuality("Insufficient sales data: only " . count($salesData) . " days available", $productId, 'warning');
        }
        
        // Check for excessive zero sales days
        $zeroSalesPercentage = count($salesData) > 0 ? ($zeroSalesDays / count($salesData)) * 100 : 0;
        if ($zeroSalesPercentage > 50) {
            $validation['warnings'][] = "High percentage of zero sales days: {$zeroSalesPercentage}%";
            $this->logDataQuality("High zero sales percentage: {$zeroSalesPercentage}%", $productId, 'warning');
        }
        
        // Check for data consistency
        if ($negativeSalesDays > 0) {
            $validation['warnings'][] = "Found $negativeSalesDays days with negative sales - data quality issue";
            $this->logDataQuality("Data quality issue: $negativeSalesDays negative sales days", $productId, 'warning');
        }
        
        // Check for recent data availability
        if (!empty($dates)) {
            $latestDate = end($dates);
            $daysSinceLatest = (new DateTime())->diff(new DateTime($latestDate))->days;
            if ($daysSinceLatest > 7) {
                $validation['warnings'][] = "Latest sales data is $daysSinceLatest days old - may not reflect current demand";
                $this->logDataQuality("Stale sales data: latest record is $daysSinceLatest days old", $productId, 'warning');
            }
        }
        
        // Log validation completion
        $validationStatus = $validation['is_valid'] ? 'passed' : 'failed';
        $warningCount = count($validation['warnings']);
        $errorCount = count($validation['errors']);
        $this->logDataQuality("Sales data validation $validationStatus: $errorCount errors, $warningCount warnings", $productId, 'info');
        
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
     * Filter sales data to exclude zero-stock days
     * 
     * @param array $salesData Raw sales data
     * @param int $productId Product ID
     * @param int $days Analysis period in days
     * @return array Filtered sales data
     */
    public function filterSalesDataByStock(array $salesData, int $productId, int $days): array
    {
        if (empty($salesData)) {
            $this->logDataQuality("No sales data to filter for product $productId", $productId, 'info');
            return [];
        }
        
        $validStockDays = $this->getValidSalesDays($productId, $days);
        
        if (empty($validStockDays)) {
            $this->logDataQuality("No valid stock days found for product $productId", $productId, 'warning');
            return [];
        }
        
        $filteredData = [];
        $excludedDays = 0;
        
        foreach ($salesData as $sale) {
            if (in_array($sale['sale_date'], $validStockDays)) {
                $filteredData[] = $sale;
            } else {
                $excludedDays++;
            }
        }
        
        $this->logDataQuality("Filtered sales data: kept " . count($filteredData) . " days, excluded $excludedDays zero-stock days", $productId, 'info');
        
        return $filteredData;
    }
    
    /**
     * Handle boundary conditions for sales analysis
     * 
     * @param int $productId Product ID
     * @param int $days Analysis period
     * @return array Boundary condition analysis
     */
    public function analyzeBoundaryConditions(int $productId, int $days): array
    {
        $analysis = [
            'has_sales_data' => false,
            'has_stock_data' => false,
            'sufficient_data' => false,
            'recommendations' => [],
            'issues' => []
        ];
        
        // Check for sales data existence
        $salesData = $this->getSalesData($productId, $days);
        $analysis['has_sales_data'] = !empty($salesData);
        
        if (!$analysis['has_sales_data']) {
            $analysis['issues'][] = 'No sales data available';
            $analysis['recommendations'][] = 'Consider extending analysis period or checking product activity';
            $this->logDataQuality("No sales data found for boundary analysis", $productId, 'warning');
        }
        
        // Check for stock data existence
        $validStockDays = $this->getValidSalesDays($productId, $days);
        $analysis['has_stock_data'] = !empty($validStockDays);
        
        if (!$analysis['has_stock_data']) {
            $analysis['issues'][] = 'No stock availability data';
            $analysis['recommendations'][] = 'Check inventory tracking for this product';
            $this->logDataQuality("No stock data found for boundary analysis", $productId, 'warning');
        }
        
        // Check data sufficiency
        $minRequiredDays = max(7, $days * 0.2); // At least 7 days or 20% of analysis period
        $availableDays = count($validStockDays);
        $analysis['sufficient_data'] = $availableDays >= $minRequiredDays;
        
        if (!$analysis['sufficient_data']) {
            $analysis['issues'][] = "Insufficient data: only $availableDays days available (minimum $minRequiredDays required)";
            $analysis['recommendations'][] = 'Extend analysis period or use alternative calculation method';
            $this->logDataQuality("Insufficient data for reliable analysis: $availableDays/$minRequiredDays days", $productId, 'warning');
        }
        
        // Check for recent activity
        if ($analysis['has_sales_data']) {
            $latestSale = max(array_column($salesData, 'sale_date'));
            $daysSinceLatest = (new DateTime())->diff(new DateTime($latestSale))->days;
            
            if ($daysSinceLatest > 14) {
                $analysis['issues'][] = "No recent sales activity (last sale: $daysSinceLatest days ago)";
                $analysis['recommendations'][] = 'Consider product discontinuation or marketing review';
                $this->logDataQuality("No recent sales activity: $daysSinceLatest days since last sale", $productId, 'warning');
            }
        }
        
        return $analysis;
    }
    
    /**
     * Detect gaps in sales data
     * 
     * @param array $dates Array of dates
     * @return array Detected gaps
     */
    private function detectDataGaps(array $dates): array
    {
        if (count($dates) < 2) {
            return [];
        }
        
        sort($dates);
        $gaps = [];
        
        for ($i = 1; $i < count($dates); $i++) {
            $prevDate = new DateTime($dates[$i - 1]);
            $currDate = new DateTime($dates[$i]);
            $daysDiff = $currDate->diff($prevDate)->days;
            
            // Consider gaps of more than 3 days as significant
            if ($daysDiff > 3) {
                $gaps[] = [
                    'start' => $dates[$i - 1],
                    'end' => $dates[$i],
                    'days' => $daysDiff
                ];
            }
        }
        
        return $gaps;
    }
    
    /**
     * Validate date format
     * 
     * @param string $date Date string to validate
     * @return bool True if valid date format
     */
    private function isValidDate(string $date): bool
    {
        $formats = ['Y-m-d', 'Y-m-d H:i:s'];
        
        foreach ($formats as $format) {
            $dateTime = DateTime::createFromFormat($format, $date);
            if ($dateTime && $dateTime->format($format) === $date) {
                return true;
            }
        }
        
        return false;
    }
    
    /**
     * Log data quality issues
     * 
     * @param string $message Log message
     * @param int|null $productId Product ID (optional)
     * @param string $level Log level (info, warning, error)
     */
    private function logDataQuality(string $message, ?int $productId = null, string $level = 'info'): void
    {
        $timestamp = date('Y-m-d H:i:s');
        $productInfo = $productId ? " [Product: $productId]" : "";
        $logMessage = "[$level] $timestamp$productInfo - $message";
        
        // Always log data quality issues regardless of debug setting
        if (in_array($level, ['warning', 'error']) || $this->config['debug']) {
            echo "[SalesAnalyzer:DataQuality] $logMessage\n";
        }
        
        // TODO: In production, this should write to a proper log file
        // For now, we'll use error_log for warnings and errors
        if (in_array($level, ['warning', 'error'])) {
            error_log("[SalesAnalyzer:DataQuality] $logMessage");
        }
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