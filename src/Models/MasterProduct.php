<?php

namespace MDM\Models;

use DateTime;
use InvalidArgumentException;
use JsonException;

/**
 * Master Product Model
 * 
 * Represents a canonical product in the MDM system with standardized attributes.
 * This is the single source of truth for product information.
 */
class MasterProduct
{
    private string $masterId;
    private string $canonicalName;
    private ?string $canonicalBrand;
    private ?string $canonicalCategory;
    private ?string $description;
    private ?array $attributes;
    private ?string $barcode;
    private ?int $weightGrams;
    private ?array $dimensions;
    private DateTime $createdAt;
    private DateTime $updatedAt;
    private string $status;
    private ?string $createdBy;
    private ?string $updatedBy;

    // Valid status values
    public const STATUS_ACTIVE = 'active';
    public const STATUS_INACTIVE = 'inactive';
    public const STATUS_PENDING_REVIEW = 'pending_review';
    public const STATUS_MERGED = 'merged';

    public const VALID_STATUSES = [
        self::STATUS_ACTIVE,
        self::STATUS_INACTIVE,
        self::STATUS_PENDING_REVIEW,
        self::STATUS_MERGED
    ];

    /**
     * Constructor
     */
    public function __construct(
        string $masterId,
        string $canonicalName,
        ?string $canonicalBrand = null,
        ?string $canonicalCategory = null,
        ?string $description = null,
        ?array $attributes = null,
        ?string $barcode = null,
        ?int $weightGrams = null,
        ?array $dimensions = null,
        string $status = self::STATUS_ACTIVE,
        ?string $createdBy = null,
        ?string $updatedBy = null,
        ?DateTime $createdAt = null,
        ?DateTime $updatedAt = null
    ) {
        $this->setMasterId($masterId);
        $this->setCanonicalName($canonicalName);
        $this->setCanonicalBrand($canonicalBrand);
        $this->setCanonicalCategory($canonicalCategory);
        $this->setDescription($description);
        $this->setAttributes($attributes);
        $this->setBarcode($barcode);
        $this->setWeightGrams($weightGrams);
        $this->setDimensions($dimensions);
        $this->setStatus($status);
        $this->setCreatedBy($createdBy);
        $this->setUpdatedBy($updatedBy);
        $this->createdAt = $createdAt ?? new DateTime();
        $this->updatedAt = $updatedAt ?? new DateTime();
    }

    /**
     * Getters
     */
    public function getMasterId(): string
    {
        return $this->masterId;
    }

    public function getCanonicalName(): string
    {
        return $this->canonicalName;
    }

    public function getCanonicalBrand(): ?string
    {
        return $this->canonicalBrand;
    }

    public function getCanonicalCategory(): ?string
    {
        return $this->canonicalCategory;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function getAttributes(): ?array
    {
        return $this->attributes;
    }

    public function getBarcode(): ?string
    {
        return $this->barcode;
    }

    public function getWeightGrams(): ?int
    {
        return $this->weightGrams;
    }

    public function getDimensions(): ?array
    {
        return $this->dimensions;
    }

    public function getCreatedAt(): DateTime
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): DateTime
    {
        return $this->updatedAt;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function getCreatedBy(): ?string
    {
        return $this->createdBy;
    }

    public function getUpdatedBy(): ?string
    {
        return $this->updatedBy;
    }

    /**
     * Setters with validation
     */
    public function setMasterId(string $masterId): void
    {
        if (empty(trim($masterId))) {
            throw new InvalidArgumentException('Master ID cannot be empty');
        }

        if (!$this->isValidMasterIdFormat($masterId)) {
            throw new InvalidArgumentException('Master ID must follow format: MASTER_XXXXXXXX or PROD_XXXXXXXX');
        }

        $this->masterId = $masterId;
    }

    public function setCanonicalName(string $canonicalName): void
    {
        $trimmed = trim($canonicalName);
        if (empty($trimmed)) {
            throw new InvalidArgumentException('Canonical name cannot be empty');
        }

        if (strlen($trimmed) < 3 || strlen($trimmed) > 500) {
            throw new InvalidArgumentException('Canonical name must be between 3 and 500 characters');
        }

        $this->canonicalName = $trimmed;
        $this->touch();
    }

    public function setCanonicalBrand(?string $canonicalBrand): void
    {
        if ($canonicalBrand !== null) {
            $trimmed = trim($canonicalBrand);
            if (strlen($trimmed) > 200) {
                throw new InvalidArgumentException('Canonical brand cannot exceed 200 characters');
            }
            $this->canonicalBrand = empty($trimmed) ? null : $trimmed;
        } else {
            $this->canonicalBrand = null;
        }
        $this->touch();
    }

    public function setCanonicalCategory(?string $canonicalCategory): void
    {
        if ($canonicalCategory !== null) {
            $trimmed = trim($canonicalCategory);
            if (strlen($trimmed) > 200) {
                throw new InvalidArgumentException('Canonical category cannot exceed 200 characters');
            }
            $this->canonicalCategory = empty($trimmed) ? null : $trimmed;
        } else {
            $this->canonicalCategory = null;
        }
        $this->touch();
    }

    public function setDescription(?string $description): void
    {
        $this->description = $description !== null ? trim($description) : null;
        $this->touch();
    }

    public function setAttributes(?array $attributes): void
    {
        $this->attributes = $attributes;
        $this->touch();
    }

    public function setBarcode(?string $barcode): void
    {
        if ($barcode !== null) {
            $trimmed = trim($barcode);
            if (!empty($trimmed) && !$this->isValidBarcodeFormat($trimmed)) {
                throw new InvalidArgumentException('Barcode must be 8-14 digits');
            }
            $this->barcode = empty($trimmed) ? null : $trimmed;
        } else {
            $this->barcode = null;
        }
        $this->touch();
    }

    public function setWeightGrams(?int $weightGrams): void
    {
        if ($weightGrams !== null && $weightGrams <= 0) {
            throw new InvalidArgumentException('Weight must be positive');
        }
        $this->weightGrams = $weightGrams;
        $this->touch();
    }

    public function setDimensions(?array $dimensions): void
    {
        if ($dimensions !== null) {
            $this->validateDimensions($dimensions);
        }
        $this->dimensions = $dimensions;
        $this->touch();
    }

    public function setStatus(string $status): void
    {
        if (!in_array($status, self::VALID_STATUSES)) {
            throw new InvalidArgumentException('Invalid status. Must be one of: ' . implode(', ', self::VALID_STATUSES));
        }
        $this->status = $status;
        $this->touch();
    }

    public function setCreatedBy(?string $createdBy): void
    {
        $this->createdBy = $createdBy;
    }

    public function setUpdatedBy(?string $updatedBy): void
    {
        $this->updatedBy = $updatedBy;
        $this->touch();
    }

    /**
     * Business logic methods
     */
    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }

    public function isComplete(): bool
    {
        return !empty($this->canonicalName) &&
               !empty($this->canonicalBrand) &&
               !empty($this->canonicalCategory);
    }

    public function getCompleteness(): float
    {
        $totalFields = 7; // name, brand, category, description, barcode, weight, dimensions
        $filledFields = 1; // name is always required

        if (!empty($this->canonicalBrand)) $filledFields++;
        if (!empty($this->canonicalCategory)) $filledFields++;
        if (!empty($this->description)) $filledFields++;
        if (!empty($this->barcode)) $filledFields++;
        if ($this->weightGrams !== null) $filledFields++;
        if (!empty($this->dimensions)) $filledFields++;

        return $filledFields / $totalFields;
    }

    public function addAttribute(string $key, $value): void
    {
        if ($this->attributes === null) {
            $this->attributes = [];
        }
        $this->attributes[$key] = $value;
        $this->touch();
    }

    public function removeAttribute(string $key): void
    {
        if ($this->attributes !== null && isset($this->attributes[$key])) {
            unset($this->attributes[$key]);
            $this->touch();
        }
    }

    public function getAttribute(string $key, $default = null)
    {
        return $this->attributes[$key] ?? $default;
    }

    public function hasAttribute(string $key): bool
    {
        return isset($this->attributes[$key]);
    }

    /**
     * Validation methods
     */
    private function isValidMasterIdFormat(string $masterId): bool
    {
        return preg_match('/^(MASTER_|PROD_)[0-9A-Z]{6,}$/', $masterId) === 1;
    }

    private function isValidBarcodeFormat(string $barcode): bool
    {
        return preg_match('/^[0-9]{8,14}$/', $barcode) === 1;
    }

    private function validateDimensions(array $dimensions): void
    {
        $requiredKeys = ['length', 'width', 'height'];
        foreach ($requiredKeys as $key) {
            if (!isset($dimensions[$key]) || !is_numeric($dimensions[$key]) || $dimensions[$key] <= 0) {
                throw new InvalidArgumentException("Dimensions must include positive numeric values for: " . implode(', ', $requiredKeys));
            }
        }
    }

    /**
     * Update the updatedAt timestamp
     */
    private function touch(): void
    {
        $this->updatedAt = new DateTime();
    }

    /**
     * Convert to array for database storage
     */
    public function toArray(): array
    {
        return [
            'master_id' => $this->masterId,
            'canonical_name' => $this->canonicalName,
            'canonical_brand' => $this->canonicalBrand,
            'canonical_category' => $this->canonicalCategory,
            'description' => $this->description,
            'attributes' => $this->attributes ? json_encode($this->attributes) : null,
            'barcode' => $this->barcode,
            'weight_grams' => $this->weightGrams,
            'dimensions_json' => $this->dimensions ? json_encode($this->dimensions) : null,
            'created_at' => $this->createdAt->format('Y-m-d H:i:s'),
            'updated_at' => $this->updatedAt->format('Y-m-d H:i:s'),
            'status' => $this->status,
            'created_by' => $this->createdBy,
            'updated_by' => $this->updatedBy
        ];
    }

    /**
     * Create from database array
     */
    public static function fromArray(array $data): self
    {
        $attributes = null;
        if (!empty($data['attributes'])) {
            try {
                $attributes = json_decode($data['attributes'], true, 512, JSON_THROW_ON_ERROR);
            } catch (JsonException $e) {
                $attributes = null;
            }
        }

        $dimensions = null;
        if (!empty($data['dimensions_json'])) {
            try {
                $dimensions = json_decode($data['dimensions_json'], true, 512, JSON_THROW_ON_ERROR);
            } catch (JsonException $e) {
                $dimensions = null;
            }
        }

        return new self(
            masterId: $data['master_id'],
            canonicalName: $data['canonical_name'],
            canonicalBrand: $data['canonical_brand'] ?? null,
            canonicalCategory: $data['canonical_category'] ?? null,
            description: $data['description'] ?? null,
            attributes: $attributes,
            barcode: $data['barcode'] ?? null,
            weightGrams: $data['weight_grams'] ?? null,
            dimensions: $dimensions,
            status: $data['status'] ?? self::STATUS_ACTIVE,
            createdBy: $data['created_by'] ?? null,
            updatedBy: $data['updated_by'] ?? null,
            createdAt: isset($data['created_at']) ? new DateTime($data['created_at']) : null,
            updatedAt: isset($data['updated_at']) ? new DateTime($data['updated_at']) : null
        );
    }

    /**
     * Generate a new Master ID
     */
    public static function generateMasterId(string $prefix = 'MASTER'): string
    {
        return $prefix . '_' . strtoupper(uniqid() . bin2hex(random_bytes(4)));
    }

    /**
     * String representation
     */
    public function __toString(): string
    {
        return sprintf(
            'MasterProduct[%s]: %s (%s - %s)',
            $this->masterId,
            $this->canonicalName,
            $this->canonicalBrand ?? 'Unknown Brand',
            $this->canonicalCategory ?? 'Unknown Category'
        );
    }
}