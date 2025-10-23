<?php
/**
 * Inventory Model for mi_core_etl
 * Manages inventory data and operations
 */

require_once __DIR__ . '/../utils/Logger.php';
require_once __DIR__ . '/Product.php';

class Inventory {
    private $id;
    private $productId;
    private $warehouseId;
    private $currentStock;
    private $availableStock;
    private $reservedStock;
    private $incomingStock;
    private $lastUpdated;
    private $lastMovement;
    private $minStock;
    private $maxStock;
    private $reorderPoint;
    private $logger;
    
    public function __construct(array $data = []) {
        $this->logger = Logger::getInstance();
        
        if (!empty($data)) {
            $this->fillFromArray($data);
        }
    }
    
    /**
     * Fill model from array data
     */
    public function fillFromArray(array $data): void {
        $this->id = isset($data['id']) ? (int)$data['id'] : null;
        $this->productId = isset($data['product_id']) ? (int)$data['product_id'] : null;
        $this->warehouseId = isset($data['warehouse_id']) ? (int)$data['warehouse_id'] : null;
        $this->currentStock = isset($data['current_stock']) ? (int)$data['current_stock'] : 0;
        $this->availableStock = isset($data['available_stock']) ? (int)$data['available_stock'] : 0;
        $this->reservedStock = isset($data['reserved_stock']) ? (int)$data['reserved_stock'] : 0;
        $this->incomingStock = isset($data['incoming_stock']) ? (int)$data['incoming_stock'] : 0;
        $this->minStock = isset($data['min_stock']) ? (int)$data['min_stock'] : 0;
        $this->maxStock = isset($data['max_stock']) ? (int)$data['max_stock'] : 0;
        $this->reorderPoint = isset($data['reorder_point']) ? (int)$data['reorder_point'] : 0;
        
        // Handle date fields
        if (isset($data['last_updated'])) {
            $this->lastUpdated = is_string($data['last_updated']) 
                ? new DateTime($data['last_updated']) 
                : $data['last_updated'];
        } else {
            $this->lastUpdated = new DateTime();
        }
        
        if (isset($data['last_movement'])) {
            $this->lastMovement = is_string($data['last_movement']) 
                ? new DateTime($data['last_movement']) 
                : $data['last_movement'];
        }
        
        // Validate data after filling
        $this->validate();
    }
    
    /**
     * Validate inventory data
     */
    public function validate(): array {
        $errors = [];
        
        // Required fields validation
        if ($this->productId === null) {
            $errors[] = 'Product ID is required';
        }
        
        if ($this->warehouseId === null) {
            $errors[] = 'Warehouse ID is required';
        }
        
        // Stock validation
        if ($this->currentStock < 0) {
            $errors[] = 'Current stock cannot be negative';
        }
        
        if ($this->availableStock < 0) {
            $errors[] = 'Available stock cannot be negative';
        }
        
        if ($this->reservedStock < 0) {
            $errors[] = 'Reserved stock cannot be negative';
        }
        
        if ($this->incomingStock < 0) {
            $errors[] = 'Incoming stock cannot be negative';
        }
        
        // Stock consistency validation
        if ($this->availableStock + $this->reservedStock > $this->currentStock) {
            $errors[] = 'Available + Reserved stock cannot exceed current stock';
        }
        
        // Min/Max stock validation
        if ($this->minStock < 0) {
            $errors[] = 'Minimum stock cannot be negative';
        }
        
        if ($this->maxStock < 0) {
            $errors[] = 'Maximum stock cannot be negative';
        }
        
        if ($this->maxStock > 0 && $this->minStock > $this->maxStock) {
            $errors[] = 'Minimum stock cannot exceed maximum stock';
        }
        
        if ($this->reorderPoint < 0) {
            $errors[] = 'Reorder point cannot be negative';
        }
        
        if (!empty($errors)) {
            $this->logger->warning('Inventory validation failed', [
                'product_id' => $this->productId,
                'warehouse_id' => $this->warehouseId,
                'errors' => $errors
            ]);
        }
        
        return $errors;
    }
    
    /**
     * Check if inventory data is valid
     */
    public function isValid(): bool {
        return empty($this->validate());
    }
    
    /**
     * Calculate stock status based on thresholds
     */
    public function getStockStatus(): string {
        if ($this->currentStock <= Product::CRITICAL_THRESHOLD) {
            return Product::STATUS_CRITICAL;
        }
        
        if ($this->currentStock <= Product::LOW_STOCK_THRESHOLD) {
            return Product::STATUS_LOW_STOCK;
        }
        
        if ($this->currentStock > Product::OVERSTOCK_THRESHOLD) {
            return Product::STATUS_OVERSTOCK;
        }
        
        return Product::STATUS_NORMAL;
    }
    
    /**
     * Check if reorder is needed
     */
    public function needsReorder(): bool {
        if ($this->reorderPoint <= 0) {
            return false;
        }
        
        return $this->currentStock <= $this->reorderPoint;
    }
    
    /**
     * Calculate suggested reorder quantity
     */
    public function getSuggestedReorderQuantity(): int {
        if (!$this->needsReorder() || $this->maxStock <= 0) {
            return 0;
        }
        
        // Calculate quantity to reach max stock level
        $suggestedQuantity = $this->maxStock - $this->currentStock - $this->incomingStock;
        
        return max(0, $suggestedQuantity);
    }
    
    /**
     * Get stock turnover rate (if movement data is available)
     */
    public function getStockTurnoverDays(): ?int {
        if (!$this->lastMovement || $this->currentStock <= 0) {
            return null;
        }
        
        $daysSinceLastMovement = (new DateTime())->diff($this->lastMovement)->days;
        
        // Simple estimation - this could be improved with actual movement history
        return $daysSinceLastMovement;
    }
    
    /**
     * Update stock levels with movement tracking
     */
    public function updateStock(int $currentStock, ?int $availableStock = null, ?int $reservedStock = null, ?int $incomingStock = null): void {
        $oldStock = $this->currentStock;
        
        $this->currentStock = $currentStock;
        
        if ($availableStock !== null) {
            $this->availableStock = $availableStock;
        }
        
        if ($reservedStock !== null) {
            $this->reservedStock = $reservedStock;
        }
        
        if ($incomingStock !== null) {
            $this->incomingStock = $incomingStock;
        }
        
        // Update timestamps
        $this->lastUpdated = new DateTime();
        
        // If stock changed, update last movement
        if ($oldStock !== $this->currentStock) {
            $this->lastMovement = new DateTime();
        }
        
        $this->logger->info('Inventory stock updated', [
            'product_id' => $this->productId,
            'warehouse_id' => $this->warehouseId,
            'old_stock' => $oldStock,
            'new_stock' => $this->currentStock,
            'available_stock' => $this->availableStock,
            'reserved_stock' => $this->reservedStock,
            'incoming_stock' => $this->incomingStock,
            'stock_status' => $this->getStockStatus()
        ]);
    }
    
    /**
     * Reserve stock for orders
     */
    public function reserveStock(int $quantity): bool {
        if ($quantity <= 0) {
            return false;
        }
        
        if ($this->availableStock < $quantity) {
            $this->logger->warning('Insufficient available stock for reservation', [
                'product_id' => $this->productId,
                'warehouse_id' => $this->warehouseId,
                'requested' => $quantity,
                'available' => $this->availableStock
            ]);
            return false;
        }
        
        $this->availableStock -= $quantity;
        $this->reservedStock += $quantity;
        $this->lastUpdated = new DateTime();
        
        $this->logger->info('Stock reserved', [
            'product_id' => $this->productId,
            'warehouse_id' => $this->warehouseId,
            'quantity' => $quantity,
            'available_stock' => $this->availableStock,
            'reserved_stock' => $this->reservedStock
        ]);
        
        return true;
    }
    
    /**
     * Release reserved stock
     */
    public function releaseReservedStock(int $quantity): bool {
        if ($quantity <= 0) {
            return false;
        }
        
        if ($this->reservedStock < $quantity) {
            $this->logger->warning('Insufficient reserved stock to release', [
                'product_id' => $this->productId,
                'warehouse_id' => $this->warehouseId,
                'requested' => $quantity,
                'reserved' => $this->reservedStock
            ]);
            return false;
        }
        
        $this->reservedStock -= $quantity;
        $this->availableStock += $quantity;
        $this->lastUpdated = new DateTime();
        
        $this->logger->info('Reserved stock released', [
            'product_id' => $this->productId,
            'warehouse_id' => $this->warehouseId,
            'quantity' => $quantity,
            'available_stock' => $this->availableStock,
            'reserved_stock' => $this->reservedStock
        ]);
        
        return true;
    }
    
    /**
     * Fulfill order (remove from reserved and current stock)
     */
    public function fulfillOrder(int $quantity): bool {
        if ($quantity <= 0) {
            return false;
        }
        
        if ($this->reservedStock < $quantity) {
            $this->logger->warning('Insufficient reserved stock to fulfill order', [
                'product_id' => $this->productId,
                'warehouse_id' => $this->warehouseId,
                'requested' => $quantity,
                'reserved' => $this->reservedStock
            ]);
            return false;
        }
        
        $this->reservedStock -= $quantity;
        $this->currentStock -= $quantity;
        $this->lastUpdated = new DateTime();
        $this->lastMovement = new DateTime();
        
        $this->logger->info('Order fulfilled', [
            'product_id' => $this->productId,
            'warehouse_id' => $this->warehouseId,
            'quantity' => $quantity,
            'current_stock' => $this->currentStock,
            'reserved_stock' => $this->reservedStock
        ]);
        
        return true;
    }
    
    /**
     * Add incoming stock
     */
    public function receiveStock(int $quantity): void {
        if ($quantity <= 0) {
            return;
        }
        
        $this->currentStock += $quantity;
        $this->availableStock += $quantity;
        
        // Reduce incoming stock if it was tracked
        if ($this->incomingStock >= $quantity) {
            $this->incomingStock -= $quantity;
        }
        
        $this->lastUpdated = new DateTime();
        $this->lastMovement = new DateTime();
        
        $this->logger->info('Stock received', [
            'product_id' => $this->productId,
            'warehouse_id' => $this->warehouseId,
            'quantity' => $quantity,
            'current_stock' => $this->currentStock,
            'available_stock' => $this->availableStock,
            'incoming_stock' => $this->incomingStock
        ]);
    }
    
    /**
     * Convert to array for API responses
     */
    public function toArray(): array {
        return [
            'id' => $this->id,
            'product_id' => $this->productId,
            'warehouse_id' => $this->warehouseId,
            'current_stock' => $this->currentStock,
            'available_stock' => $this->availableStock,
            'reserved_stock' => $this->reservedStock,
            'incoming_stock' => $this->incomingStock,
            'min_stock' => $this->minStock,
            'max_stock' => $this->maxStock,
            'reorder_point' => $this->reorderPoint,
            'stock_status' => $this->getStockStatus(),
            'needs_reorder' => $this->needsReorder(),
            'suggested_reorder_quantity' => $this->getSuggestedReorderQuantity(),
            'stock_turnover_days' => $this->getStockTurnoverDays(),
            'last_updated' => $this->lastUpdated ? $this->lastUpdated->format('Y-m-d H:i:s') : null,
            'last_movement' => $this->lastMovement ? $this->lastMovement->format('Y-m-d H:i:s') : null
        ];
    }
    
    /**
     * Convert to JSON
     */
    public function toJson(): string {
        return json_encode($this->toArray(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
    
    /**
     * Create from database row
     */
    public static function fromDatabase(array $row): Inventory {
        return new self($row);
    }
    
    /**
     * Create collection from database rows
     */
    public static function collectionFromDatabase(array $rows): array {
        return array_map(function($row) {
            return self::fromDatabase($row);
        }, $rows);
    }
    
    // Getters
    public function getId(): ?int { return $this->id; }
    public function getProductId(): ?int { return $this->productId; }
    public function getWarehouseId(): ?int { return $this->warehouseId; }
    public function getCurrentStock(): int { return $this->currentStock; }
    public function getAvailableStock(): int { return $this->availableStock; }
    public function getReservedStock(): int { return $this->reservedStock; }
    public function getIncomingStock(): int { return $this->incomingStock; }
    public function getMinStock(): int { return $this->minStock; }
    public function getMaxStock(): int { return $this->maxStock; }
    public function getReorderPoint(): int { return $this->reorderPoint; }
    public function getLastUpdated(): ?DateTime { return $this->lastUpdated; }
    public function getLastMovement(): ?DateTime { return $this->lastMovement; }
    
    // Setters
    public function setId(int $id): void { $this->id = $id; }
    public function setProductId(int $productId): void { $this->productId = $productId; }
    public function setWarehouseId(int $warehouseId): void { $this->warehouseId = $warehouseId; }
    public function setMinStock(int $minStock): void { $this->minStock = $minStock; }
    public function setMaxStock(int $maxStock): void { $this->maxStock = $maxStock; }
    public function setReorderPoint(int $reorderPoint): void { $this->reorderPoint = $reorderPoint; }
}