<?php
/**
 * Replenishment Calculator Service
 * 
 * Service for calculating replenishment needs and liquidity metrics
 * for warehouse inventory management.
 * 
 * Requirements: 3, 5, 7
 */

class ReplenishmentCalculator {
    
    /**
     * Calculate target stock for specified supply period
     * 
     * Calculates the target stock level needed to maintain supply
     * for the specified number of days based on average daily sales.
     * 
     * Requirements: 3.1
     * 
     * @param float $dailySalesAvg Average daily sales
     * @param int $daysOfSupply Number of days to maintain supply (default: 30)
     * @return int Target stock quantity (rounded up to whole units)
     */
    public function calculateTargetStock($dailySalesAvg, $daysOfSupply = 30) {
        // If no sales, no target stock needed
        if ($dailySalesAvg <= 0) {
            return 0;
        }
        
        // Calculate target stock and round up to ensure sufficient supply
        $targetStock = $dailySalesAvg * $daysOfSupply;
        return (int)ceil($targetStock);
    }
    
    /**
     * Calculate replenishment need
     * 
     * Calculates how many units need to be ordered to reach target stock level,
     * taking into account current available stock, items in transit, and items
     * in supply requests.
     * 
     * Formula: (target_stock) - (available + in_transit + in_supply_requests)
     * 
     * Requirements: 3.1, 3.2, 3.3, 3.4, 3.5
     * 
     * @param int $targetStock Target stock level
     * @param int $available Currently available stock
     * @param int $inTransit Stock in transit to warehouse
     * @param int $inSupplyRequests Stock in supply requests
     * @return int Replenishment need (0 if stock is sufficient)
     */
    public function calculateReplenishmentNeed($targetStock, $available, $inTransit = 0, $inSupplyRequests = 0) {
        // Calculate total stock (current + incoming)
        $totalStock = $available + $inTransit + $inSupplyRequests;
        
        // Calculate need (never negative)
        $need = $targetStock - $totalStock;
        
        return max(0, $need);
    }
    
    /**
     * Calculate days of stock remaining
     * 
     * Calculates how many days the current available stock will last
     * at the current average daily sales rate.
     * 
     * Requirements: 5.1, 5.2
     * 
     * @param int $available Currently available stock
     * @param float $dailySalesAvg Average daily sales
     * @return float|null Days of stock (null if no sales/infinite stock)
     */
    public function calculateDaysOfStock($available, $dailySalesAvg) {
        // If no sales, return null (infinite stock)
        if ($dailySalesAvg <= 0) {
            return null;
        }
        
        // Calculate days of stock
        $daysOfStock = $available / $dailySalesAvg;
        
        return round($daysOfStock, 2);
    }
    
    /**
     * Determine liquidity status based on days of stock
     * 
     * Categorizes inventory liquidity into four levels:
     * - critical: < 7 days (urgent replenishment needed)
     * - low: 7-14 days (replenishment needed soon)
     * - normal: 15-45 days (healthy stock level)
     * - excess: > 45 days (overstocked)
     * 
     * Requirements: 5.3, 5.4, 5.5, 5.6, 7.1, 7.2, 7.3, 7.4, 7.5
     * 
     * @param float|null $daysOfStock Days of stock remaining
     * @return string Liquidity status: 'critical', 'low', 'normal', or 'excess'
     */
    public function determineLiquidityStatus($daysOfStock) {
        // NULL means no sales (infinite stock) - categorize as excess
        if ($daysOfStock === null) {
            return 'excess';
        }
        
        // Categorize based on days of stock thresholds
        if ($daysOfStock < 7) {
            return 'critical';
        } elseif ($daysOfStock < 15) {
            return 'low';
        } elseif ($daysOfStock <= 45) {
            return 'normal';
        } else {
            return 'excess';
        }
    }
    
    /**
     * Get liquidity status display information
     * 
     * Returns display-friendly information for a liquidity status,
     * including label, color, and priority level.
     * 
     * @param string $status Liquidity status
     * @return array Display information
     */
    public function getLiquidityStatusInfo($status) {
        $statusInfo = [
            'critical' => [
                'label' => 'Дефицит',
                'label_en' => 'Critical Deficit',
                'color' => 'red',
                'priority' => 1,
                'description' => 'Критический дефицит - требуется срочное пополнение'
            ],
            'low' => [
                'label' => 'Низкий запас',
                'label_en' => 'Low Stock',
                'color' => 'yellow',
                'priority' => 2,
                'description' => 'Низкий запас - требуется пополнение'
            ],
            'normal' => [
                'label' => 'Норма',
                'label_en' => 'Normal',
                'color' => 'green',
                'priority' => 3,
                'description' => 'Нормальный уровень запаса'
            ],
            'excess' => [
                'label' => 'Избыток',
                'label_en' => 'Excess',
                'color' => 'blue',
                'priority' => 4,
                'description' => 'Избыточный запас'
            ]
        ];
        
        return $statusInfo[$status] ?? $statusInfo['normal'];
    }
    
    /**
     * Check if replenishment is urgent
     * 
     * Determines if replenishment need is urgent based on the percentage
     * of target stock that needs to be ordered.
     * 
     * Requirements: 3.5
     * 
     * @param int $replenishmentNeed Units needed
     * @param int $targetStock Target stock level
     * @return bool True if urgent (> 50% of target stock)
     */
    public function isReplenishmentUrgent($replenishmentNeed, $targetStock) {
        if ($targetStock <= 0) {
            return false;
        }
        
        $percentage = ($replenishmentNeed / $targetStock) * 100;
        return $percentage > 50;
    }
}
?>
