<?php

namespace Models;

use DateTime;
use InvalidArgumentException;
use JsonException;

/**
 * ProductActivity Model
 * 
 * Represents the activity status of a product with all criteria and metadata.
 * Used to track whether a product is active in the marketplace.
 */
class ProductActivity
{
    private string $productId;
    private string $externalSku;
    private bool $isActive;
    private string $activityReason;
    private DateTime $checkedAt;
    private array $criteria;
    
    // Individual criteria flags
    private bool $isVisible;
    private bool $isProcessed;
    private bool $hasStock;
    private bool $hasPricing;
    
    // Additional metadata
    private ?array $rawData;
    private ?string $checkedBy;

    /**
     * Constructor
     */
    public function __construct(
        string $productId,
        string $externalSku,
        bool $isActive,
        string $activityReason,
        bool $isVisible = false,
        bool $isProcessed = false,
        bool $hasStock = false,
        bool $hasPricing = false,
        ?array $rawData = null,
        ?string $checkedBy = null,
        ?DateTime $checkedAt = null
    ) {
        $this->setProductId($productId);
        $this->setExternalSku($externalSku);
        $this->setIsActive($isActive);
        $this->setActivityReason($activityReason);
        $this->setIsVisible($isVisible);
        $this->setIsProcessed($isProcessed);
        $this->setHasStock($hasStock);
        $this->setHasPricing($hasPricing);
        $this->setRawData($rawData);
        $this->setCheckedBy($checkedBy);
        $this->checkedAt = $checkedAt ?? new DateTime();
        
        $this->updateCriteria();
    }

    /**
     * Getters
     */
    public function getProductId(): string
    {
        return $this->productId;
    }

    public function getExternalSku(): string
    {
        return $this->externalSku;
    }

    public function isActive(): bool
    {
        return $this->isActive;
    }

    public function getActivityReason(): string
    {
        return $this->activityReason;
    }

    public function getCheckedAt(): DateTime
    {
        return $this->checkedAt;
    }

    public function getCriteria(): array
    {
        return $this->criteria;
    }

    public function isVisible(): bool
    {
        return $this->isVisible;
    }

    public function isProcessed(): bool
    {
        return $this->isProcessed;
    }

    public function hasStock(): bool
    {
        return $this->hasStock;
    }

    public function hasPricing(): bool
    {
        return $this->hasPricing;
    }

    public function getRawData(): ?array
    {
        return $this->rawData;
    }

    public function getCheckedBy(): ?string
    {
        return $this->checkedBy;
    }

    /**
     * Setters with validation
     */
    public function setProductId(string $productId): void
    {
        if (empty(trim($productId))) {
            throw new InvalidArgumentException('Product ID cannot be empty');
        }
        $this->productId = trim($productId);
    }

    public function setExternalSku(string $externalSku): void
    {
        if (empty(trim($externalSku))) {
            throw new InvalidArgumentException('External SKU cannot be empty');
        }
        $this->externalSku = trim($externalSku);
    }

    public function setIsActive(bool $isActive): void
    {
        $this->isActive = $isActive;
        $this->updateCriteria();
    }

    public function setActivityReason(string $activityReason): void
    {
        $this->activityReason = trim($activityReason);
    }

    public function setIsVisible(bool $isVisible): void
    {
        $this->isVisible = $isVisible;
        $this->updateCriteria();
    }

    public function setIsProcessed(bool $isProcessed): void
    {
        $this->isProcessed = $isProcessed;
        $this->updateCriteria();
    }

    public function setHasStock(bool $hasStock): void
    {
        $this->hasStock = $hasStock;
        $this->updateCriteria();
    }

    public function setHasPricing(bool $hasPricing): void
    {
        $this->hasPricing = $hasPricing;
        $this->updateCriteria();
    }

    public function setRawData(?array $rawData): void
    {
        $this->rawData = $rawData;
    }

    public function setCheckedBy(?string $checkedBy): void
    {
        $this->checkedBy = $checkedBy;
    }

    public function setCheckedAt(DateTime $checkedAt): void
    {
        $this->checkedAt = $checkedAt;
    }

    /**
     * Business logic methods
     */
    public function updateActivityStatus(bool $isActive, string $reason): void
    {
        $this->isActive = $isActive;
        $this->activityReason = $reason;
        $this->checkedAt = new DateTime();
        $this->updateCriteria();
    }

    public function updateCriteriaFromData(array $productData, array $stockData, array $priceData): void
    {
        // Update visibility
        $this->isVisible = ($productData['visibility'] ?? '') === 'VISIBLE';
        
        // Update processing status
        $this->isProcessed = ($productData['state'] ?? '') === 'processed';
        
        // Update stock status
        $this->hasStock = ($stockData['present'] ?? 0) > 0;
        
        // Update pricing status
        $price = $priceData['price'] ?? 0;
        $oldPrice = $priceData['old_price'] ?? 0;
        $this->hasPricing = ($price > 0 || $oldPrice > 0);
        
        // Update overall activity status
        $this->isActive = $this->isVisible && $this->isProcessed && $this->hasStock && $this->hasPricing;
        
        $this->updateCriteria();
    }

    public function getFailedCriteria(): array
    {
        $failed = [];
        
        if (!$this->isVisible) {
            $failed[] = 'visibility';
        }
        
        if (!$this->isProcessed) {
            $failed[] = 'processing_state';
        }
        
        if (!$this->hasStock) {
            $failed[] = 'stock_availability';
        }
        
        if (!$this->hasPricing) {
            $failed[] = 'pricing_information';
        }
        
        return $failed;
    }

    public function getPassedCriteria(): array
    {
        $passed = [];
        
        if ($this->isVisible) {
            $passed[] = 'visibility';
        }
        
        if ($this->isProcessed) {
            $passed[] = 'processing_state';
        }
        
        if ($this->hasStock) {
            $passed[] = 'stock_availability';
        }
        
        if ($this->hasPricing) {
            $passed[] = 'pricing_information';
        }
        
        return $passed;
    }

    public function getCriteriaScore(): float
    {
        $total = 4; // Total number of criteria
        $passed = count($this->getPassedCriteria());
        
        return $passed / $total;
    }

    /**
     * Update the criteria array with current status
     */
    private function updateCriteria(): void
    {
        $this->criteria = [
            'visibility_ok' => $this->isVisible,
            'state_ok' => $this->isProcessed,
            'stock_ok' => $this->hasStock,
            'pricing_ok' => $this->hasPricing,
            'overall_active' => $this->isActive,
            'criteria_score' => $this->getCriteriaScore(),
            'failed_criteria' => $this->getFailedCriteria(),
            'passed_criteria' => $this->getPassedCriteria()
        ];
    }

    /**
     * Validation methods
     */
    public function validate(): array
    {
        $errors = [];
        
        if (empty($this->productId)) {
            $errors[] = 'Product ID is required';
        }
        
        if (empty($this->externalSku)) {
            $errors[] = 'External SKU is required';
        }
        
        if (empty($this->activityReason)) {
            $errors[] = 'Activity reason is required';
        }
        
        return $errors;
    }

    public function isValid(): bool
    {
        return empty($this->validate());
    }

    /**
     * Serialization methods
     */
    public function toArray(): array
    {
        return [
            'product_id' => $this->productId,
            'external_sku' => $this->externalSku,
            'is_active' => $this->isActive,
            'activity_reason' => $this->activityReason,
            'checked_at' => $this->checkedAt->format('Y-m-d H:i:s'),
            'criteria' => json_encode($this->criteria),
            'is_visible' => $this->isVisible,
            'is_processed' => $this->isProcessed,
            'has_stock' => $this->hasStock,
            'has_pricing' => $this->hasPricing,
            'raw_data' => $this->rawData ? json_encode($this->rawData) : null,
            'checked_by' => $this->checkedBy
        ];
    }

    public function toJson(): string
    {
        return json_encode($this->toArray(), JSON_THROW_ON_ERROR);
    }

    /**
     * Create from database array
     */
    public static function fromArray(array $data): self
    {
        $criteria = [];
        if (!empty($data['criteria'])) {
            try {
                $criteria = json_decode($data['criteria'], true, 512, JSON_THROW_ON_ERROR);
            } catch (JsonException $e) {
                $criteria = [];
            }
        }

        $rawData = null;
        if (!empty($data['raw_data'])) {
            try {
                $rawData = json_decode($data['raw_data'], true, 512, JSON_THROW_ON_ERROR);
            } catch (JsonException $e) {
                $rawData = null;
            }
        }

        return new self(
            productId: $data['product_id'],
            externalSku: $data['external_sku'],
            isActive: (bool)$data['is_active'],
            activityReason: $data['activity_reason'],
            isVisible: (bool)($data['is_visible'] ?? false),
            isProcessed: (bool)($data['is_processed'] ?? false),
            hasStock: (bool)($data['has_stock'] ?? false),
            hasPricing: (bool)($data['has_pricing'] ?? false),
            rawData: $rawData,
            checkedBy: $data['checked_by'] ?? null,
            checkedAt: isset($data['checked_at']) ? new DateTime($data['checked_at']) : null
        );
    }

    /**
     * Create from JSON string
     */
    public static function fromJson(string $json): self
    {
        try {
            $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
            return self::fromArray($data);
        } catch (JsonException $e) {
            throw new InvalidArgumentException('Invalid JSON provided: ' . $e->getMessage());
        }
    }

    /**
     * String representation
     */
    public function __toString(): string
    {
        $status = $this->isActive ? 'ACTIVE' : 'INACTIVE';
        return sprintf(
            'ProductActivity[%s]: %s - %s (Score: %.2f)',
            $this->productId,
            $this->externalSku,
            $status,
            $this->getCriteriaScore()
        );
    }
}