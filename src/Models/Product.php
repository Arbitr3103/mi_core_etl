<?php
/**
 * Product Model for mi_core_etl
 * Represents product data with validation and serialization
 */

require_once __DIR__ . '/../utils/Logger.php';

class Product {
    private $id;
    private $sku;
    private $name;
    private $currentStock;
    private $availableStock;
    private $reservedStock;
    private $warehouseName;
    private $stockStatus;
    private $lastUpdated;
    private $price;
    private $category;
    private $isActive;
    private $logger;
    
    // Stock status constants
    const STATUS_CRITICAL = 'critical';
    const STATUS_LOW_STOCK = 'low_stock';
    const STATUS_NORMAL = 'normal';
    const STATUS_OVERSTOCK = 'overstock';
    
    // Stock thresholds
    const CRITICAL_THRESHOLD = 5;
    const LOW_STOCK_THRESHOLD = 20;
    const OVERSTOCK_THRESHOLD = 100;
    
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
        $this->sku = $data['sku'] ?? '';
        $this->name = $data['name'] ?? '';
        $this->currentStock = isset($data['current_stock']) ? (int)$data['current_stock'] : 0;
        $this->availableStock = isset($data['available_stock']) ? (int)$data['available_stock'] : 0;
        $this->reservedStock = isset($data['reserved_stock']) ? (int)$data['reserved_stock'] : 0;
        $this->warehouseName = $data['warehouse_name'] ?? '';
        $this->price = isset($data['price']) ? (float)$data['price'] : null;
        $this->category = $data['category'] ?? null;
        $this->isActive = isset($data['is_active']) ? (bool)$data['is_active'] : true;
        
        // Handle date fields
        if (isset($data['last_updated'])) {
            $this->lastUpdated = is_string($data['last_updated']) 
                ? new DateTime($data['last_updated']) 
                : $data['last_updated'];
        } else {
            $this->lastUpdated = new DateTime();
        }
        
        // Calculate stock status if not provided
        $this->stockStatus = $data['stock_status'] ?? $this->calculateStockStatus();
        
        // Validate data after filling
        $this->validate();
    }
    
    /**
     * Validate product data
     */
    public function validate(): array {
        $errors = [];
        
        // Required fields validation
        if (empty($this->sku)) {
            $errors[] = 'SKU is required';
        }
        
        if (empty($this->name)) {
            $errors[] = 'Product name is required';
        }
        
        // SKU format validation (alphanumeric with dashes/underscores)
        if (!empty($this->sku) && !preg_match('/^[A-Za-z0-9_-]+$/', $this->sku)) {
            $errors[] = 'SKU must contain only alphanumeric characters, dashes, and underscores';
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
        
        // Price validation
        if ($this->price !== null && $this->price < 0) {
            $errors[] = 'Price cannot be negative';
        }
        
        // Stock status validation
        $validStatuses = [self::STATUS_CRITICAL, self::STATUS_LOW_STOCK, self::STATUS_NORMAL, self::STATUS_OVERSTOCK];
        if (!in_array($this->stockStatus, $validStatuses)) {
            $errors[] = 'Invalid stock status';
        }
        
        if (!empty($errors)) {
            $this->logger->warning('Product validation failed', [
                'sku' => $this->sku,
                'errors' => $errors
            ]);
        }
        
        return $errors;
    }
    
    /**
     * Check if product data is valid
     */
    public function isValid(): bool {
        return empty($this->validate());
    }
    
    /**
     * Calculate stock status based on current stock
     */
    private function calculateStockStatus(): string {
        if ($this->currentStock <= self::CRITICAL_THRESHOLD) {
            return self::STATUS_CRITICAL;
        }
        
        if ($this->currentStock <= self::LOW_STOCK_THRESHOLD) {
            return self::STATUS_LOW_STOCK;
        }
        
        if ($this->currentStock > self::OVERSTOCK_THRESHOLD) {
            return self::STATUS_OVERSTOCK;
        }
        
        return self::STATUS_NORMAL;
    }
    
    /**
     * Get stock status with automatic recalculation
     */
    public function getStockStatus(): string {
        $this->stockStatus = $this->calculateStockStatus();
        return $this->stockStatus;
    }
    
    /**
     * Check if product is in critical stock
     */
    public function isCritical(): bool {
        return $this->getStockStatus() === self::STATUS_CRITICAL;
    }
    
    /**
     * Check if product has low stock
     */
    public function isLowStock(): bool {
        return $this->getStockStatus() === self::STATUS_LOW_STOCK;
    }
    
    /**
     * Check if product is overstocked
     */
    public function isOverstock(): bool {
        return $this->getStockStatus() === self::STATUS_OVERSTOCK;
    }
    
    /**
     * Get stock level percentage (0-100)
     */
    public function getStockLevelPercentage(): float {
        if ($this->currentStock <= 0) {
            return 0.0;
        }
        
        // Calculate percentage based on thresholds
        if ($this->currentStock <= self::CRITICAL_THRESHOLD) {
            return ($this->currentStock / self::CRITICAL_THRESHOLD) * 25;
        }
        
        if ($this->currentStock <= self::LOW_STOCK_THRESHOLD) {
            return 25 + (($this->currentStock - self::CRITICAL_THRESHOLD) / (self::LOW_STOCK_THRESHOLD - self::CRITICAL_THRESHOLD)) * 50;
        }
        
        if ($this->currentStock <= self::OVERSTOCK_THRESHOLD) {
            return 75 + (($this->currentStock - self::LOW_STOCK_THRESHOLD) / (self::OVERSTOCK_THRESHOLD - self::LOW_STOCK_THRESHOLD)) * 25;
        }
        
        return 100.0;
    }
    
    /**
     * Convert to array for API responses
     */
    public function toArray(): array {
        return [
            'id' => $this->id,
            'sku' => $this->sku,
            'name' => $this->name,
            'current_stock' => $this->currentStock,
            'available_stock' => $this->availableStock,
            'reserved_stock' => $this->reservedStock,
            'warehouse_name' => $this->warehouseName,
            'stock_status' => $this->getStockStatus(),
            'stock_level_percentage' => $this->getStockLevelPercentage(),
            'last_updated' => $this->lastUpdated ? $this->lastUpdated->format('Y-m-d H:i:s') : null,
            'price' => $this->price,
            'category' => $this->category,
            'is_active' => $this->isActive,
            'is_critical' => $this->isCritical(),
            'is_low_stock' => $this->isLowStock(),
            'is_overstock' => $this->isOverstock()
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
    public static function fromDatabase(array $row): Product {
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
    
    /**
     * Update stock levels
     */
    public function updateStock(int $currentStock, ?int $availableStock = null, ?int $reservedStock = null): void {
        $this->currentStock = $currentStock;
        
        if ($availableStock !== null) {
            $this->availableStock = $availableStock;
        }
        
        if ($reservedStock !== null) {
            $this->reservedStock = $reservedStock;
        }
        
        $this->lastUpdated = new DateTime();
        $this->stockStatus = $this->calculateStockStatus();
        
        $this->logger->info('Product stock updated', [
            'sku' => $this->sku,
            'current_stock' => $this->currentStock,
            'available_stock' => $this->availableStock,
            'reserved_stock' => $this->reservedStock,
            'stock_status' => $this->stockStatus
        ]);
    }
    
    /**
     * Get formatted display name
     */
    public function getDisplayName(): string {
        return $this->name . ' (' . $this->sku . ')';
    }
    
    /**
     * Get stock status badge color for UI
     */
    public function getStatusBadgeColor(): string {
        switch ($this->getStockStatus()) {
            case self::STATUS_CRITICAL:
                return 'red';
            case self::STATUS_LOW_STOCK:
                return 'orange';
            case self::STATUS_OVERSTOCK:
                return 'blue';
            case self::STATUS_NORMAL:
            default:
                return 'green';
        }
    }
    
    /**
     * Get stock status display text
     */
    public function getStatusDisplayText(): string {
        switch ($this->getStockStatus()) {
            case self::STATUS_CRITICAL:
                return 'Критический остаток';
            case self::STATUS_LOW_STOCK:
                return 'Низкий остаток';
            case self::STATUS_OVERSTOCK:
                return 'Избыток товара';
            case self::STATUS_NORMAL:
            default:
                return 'Нормальный остаток';
        }
    }
    
    // Getters
    public function getId(): ?int { return $this->id; }
    public function getSku(): string { return $this->sku; }
    public function getName(): string { return $this->name; }
    public function getCurrentStock(): int { return $this->currentStock; }
    public function getAvailableStock(): int { return $this->availableStock; }
    public function getReservedStock(): int { return $this->reservedStock; }
    public function getWarehouseName(): string { return $this->warehouseName; }
    public function getLastUpdated(): ?DateTime { return $this->lastUpdated; }
    public function getPrice(): ?float { return $this->price; }
    public function getCategory(): ?string { return $this->category; }
    public function isActive(): bool { return $this->isActive; }
    
    // Setters
    public function setId(int $id): void { $this->id = $id; }
    public function setSku(string $sku): void { $this->sku = $sku; }
    public function setName(string $name): void { $this->name = $name; }
    public function setWarehouseName(string $warehouseName): void { $this->warehouseName = $warehouseName; }
    public function setPrice(?float $price): void { $this->price = $price; }
    public function setCategory(?string $category): void { $this->category = $category; }
    public function setActive(bool $isActive): void { $this->isActive = $isActive; }
}