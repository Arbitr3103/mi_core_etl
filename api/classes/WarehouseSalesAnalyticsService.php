<?php
/**
 * Warehouse Sales Analytics Service
 * 
 * Service for calculating sales metrics for warehouse dashboard.
 * Provides methods for analyzing sales patterns, stock availability,
 * and product performance at warehouse level.
 * 
 * Requirements: 4, 8
 */

class WarehouseSalesAnalyticsService {
    
    private $pdo;
    
    /**
     * Constructor
     * @param PDO $pdo Database connection
     */
    public function __construct($pdo) {
        $this->pdo = $pdo;
    }
    
    /**
     * Calculate average daily sales over specified period
     * 
     * Calculates the average daily sales for a product at a specific warehouse
     * over the last N days (default 28). Only counts days when product was in stock.
     * 
     * Requirements: 4.1, 4.2, 4.3, 4.4
     * 
     * @param int $productId Product ID
     * @param string $warehouseName Warehouse name
     * @param int $days Number of days to analyze (default: 28)
     * @return float Average daily sales (rounded to 2 decimal places)
     */
    public function calculateDailySalesAvg($productId, $warehouseName, $days = 28) {
        try {
            // Get total sales (negative quantities in stock_movements represent sales)
            $sql = "
                SELECT COALESCE(SUM(ABS(quantity)), 0) as total_sales
                FROM stock_movements
                WHERE product_id = :product_id
                    AND warehouse_name = :warehouse_name
                    AND movement_date >= CURRENT_DATE - :days
                    AND quantity < 0
                    AND movement_type IN ('sale', 'order')
            ";
            
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute([
                'product_id' => $productId,
                'warehouse_name' => $warehouseName,
                'days' => $days
            ]);
            
            $result = $stmt->fetch();
            $totalSales = (int)$result['total_sales'];
            
            // Get number of days when product had stock
            $daysWithStock = $this->getDaysWithStock($productId, $warehouseName, $days);
            
            // Calculate average (avoid division by zero)
            if ($daysWithStock > 0) {
                return round($totalSales / $daysWithStock, 2);
            }
            
            return 0.00;
            
        } catch (Exception $e) {
            error_log("Error in calculateDailySalesAvg: " . $e->getMessage());
            throw $e;
        }
    }
    
    /**
     * Get number of consecutive days without sales
     * 
     * Calculates how many consecutive days (from today backwards) the product
     * has had no sales at the specified warehouse.
     * 
     * Requirements: 8.1, 8.2, 8.3, 8.4
     * 
     * @param int $productId Product ID
     * @param string $warehouseName Warehouse name
     * @return int Number of consecutive days without sales
     */
    public function getDaysWithoutSales($productId, $warehouseName) {
        try {
            // Get the most recent sale date
            $sql = "
                SELECT MAX(DATE(movement_date)) as last_sale_date
                FROM stock_movements
                WHERE product_id = :product_id
                    AND warehouse_name = :warehouse_name
                    AND quantity < 0
                    AND movement_type IN ('sale', 'order')
            ";
            
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute([
                'product_id' => $productId,
                'warehouse_name' => $warehouseName
            ]);
            
            $result = $stmt->fetch();
            $lastSaleDate = $result['last_sale_date'];
            
            // If no sales found, return 0 (or could return a large number)
            if (!$lastSaleDate) {
                return 0;
            }
            
            // Calculate days since last sale
            $lastSaleTimestamp = strtotime($lastSaleDate);
            $currentTimestamp = strtotime(date('Y-m-d'));
            $daysDiff = ($currentTimestamp - $lastSaleTimestamp) / (24 * 60 * 60);
            
            return (int)$daysDiff;
            
        } catch (Exception $e) {
            error_log("Error in getDaysWithoutSales: " . $e->getMessage());
            throw $e;
        }
    }
    
    /**
     * Get total sales over last 28 days
     * 
     * Returns the total quantity sold for a product at a specific warehouse
     * over the last 28 days.
     * 
     * Requirements: 4.1
     * 
     * @param int $productId Product ID
     * @param string $warehouseName Warehouse name
     * @return int Total quantity sold
     */
    public function getSalesLast28Days($productId, $warehouseName) {
        try {
            $sql = "
                SELECT COALESCE(SUM(ABS(quantity)), 0) as total_sales
                FROM stock_movements
                WHERE product_id = :product_id
                    AND warehouse_name = :warehouse_name
                    AND movement_date >= CURRENT_DATE - 28
                    AND quantity < 0
                    AND movement_type IN ('sale', 'order')
            ";
            
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute([
                'product_id' => $productId,
                'warehouse_name' => $warehouseName
            ]);
            
            $result = $stmt->fetch();
            return (int)$result['total_sales'];
            
        } catch (Exception $e) {
            error_log("Error in getSalesLast28Days: " . $e->getMessage());
            throw $e;
        }
    }
    
    /**
     * Get number of days when product was in stock
     * 
     * Counts the number of distinct days when the product had stock available
     * at the specified warehouse over the last N days.
     * 
     * Requirements: 4.2
     * 
     * @param int $productId Product ID
     * @param string $warehouseName Warehouse name
     * @param int $days Number of days to analyze (default: 28)
     * @return int Number of days with stock
     */
    public function getDaysWithStock($productId, $warehouseName, $days = 28) {
        try {
            // Count distinct days when product had any movement (indicating stock presence)
            // This is a simplified approach - in production, you might want to check
            // actual inventory levels per day
            $sql = "
                SELECT COUNT(DISTINCT DATE(movement_date)) as days_count
                FROM stock_movements
                WHERE product_id = :product_id
                    AND warehouse_name = :warehouse_name
                    AND movement_date >= CURRENT_DATE - :days
            ";
            
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute([
                'product_id' => $productId,
                'warehouse_name' => $warehouseName,
                'days' => $days
            ]);
            
            $result = $stmt->fetch();
            return (int)$result['days_count'];
            
        } catch (Exception $e) {
            error_log("Error in getDaysWithStock: " . $e->getMessage());
            throw $e;
        }
    }
}
?>
