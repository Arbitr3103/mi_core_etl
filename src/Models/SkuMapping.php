<?php

namespace MDM\Models;

use DateTime;
use InvalidArgumentException;
use JsonException;

/**
 * SKU Mapping Model
 * 
 * Represents the mapping between external SKUs from different sources
 * and the canonical Master Product IDs in the MDM system.
 */
class SkuMapping
{
    private ?int $id;
    private string $masterId;
    private string $externalSku;
    private string $source;
    private ?string $sourceName;
    private ?string $sourceBrand;
    private ?string $sourceCategory;
    private ?float $sourcePrice;
    private ?array $sourceAttributes;
    private ?float $confidenceScore;
    private string $verificationStatus;
    private ?string $matchMethod;
    private DateTime $createdAt;
    private DateTime $updatedAt;
    private ?string $verifiedBy;
    private ?DateTime $verifiedAt;

    // Valid verification status values
    public const STATUS_AUTO = 'auto';
    public const STATUS_MANUAL = 'manual';
    public const STATUS_PENDING = 'pending';
    public const STATUS_REJECTED = 'rejected';

    public const VALID_STATUSES = [
        self::STATUS_AUTO,
        self::STATUS_MANUAL,
        self::STATUS_PENDING,
        self::STATUS_REJECTED
    ];

    // Valid source values
    public const SOURCE_OZON = 'ozon';
    public const SOURCE_WILDBERRIES = 'wildberries';
    public const SOURCE_INTERNAL = 'internal';
    public const SOURCE_YANDEX_MARKET = 'yandex_market';
    public const SOURCE_AVITO = 'avito';
    public const SOURCE_MANUAL = 'manual';
    public const SOURCE_API_IMPORT = 'api_import';

    public const VALID_SOURCES = [
        self::SOURCE_OZON,
        self::SOURCE_WILDBERRIES,
        self::SOURCE_INTERNAL,
        self::SOURCE_YANDEX_MARKET,
        self::SOURCE_AVITO,
        self::SOURCE_MANUAL,
        self::SOURCE_API_IMPORT
    ];

    // Match methods
    public const MATCH_EXACT = 'exact';
    public const MATCH_FUZZY = 'fuzzy';
    public const MATCH_BARCODE = 'barcode';
    public const MATCH_BRAND_CATEGORY = 'brand_category';
    public const MATCH_MANUAL = 'manual';

    /**
     * Constructor
     */
    public function __construct(
        string $masterId,
        string $externalSku,
        string $source,
        ?string $sourceName = null,
        ?string $sourceBrand = null,
        ?string $sourceCategory = null,
        ?float $sourcePrice = null,
        ?array $sourceAttributes = null,
        ?float $confidenceScore = null,
        string $verificationStatus = self::STATUS_PENDING,
        ?string $matchMethod = null,
        ?string $verifiedBy = null,
        ?DateTime $verifiedAt = null,
        ?DateTime $createdAt = null,
        ?DateTime $updatedAt = null,
        ?int $id = null
    ) {
        $this->setMasterId($masterId);
        $this->setExternalSku($externalSku);
        $this->setSource($source);
        $this->setSourceName($sourceName);
        $this->setSourceBrand($sourceBrand);
        $this->setSourceCategory($sourceCategory);
        $this->setSourcePrice($sourcePrice);
        $this->setSourceAttributes($sourceAttributes);
        $this->setConfidenceScore($confidenceScore);
        $this->setVerificationStatus($verificationStatus);
        $this->setMatchMethod($matchMethod);
        $this->setVerifiedBy($verifiedBy);
        $this->setVerifiedAt($verifiedAt);
        $this->createdAt = $createdAt ?? new DateTime();
        $this->updatedAt = $updatedAt ?? new DateTime();
        $this->id = $id;
    }

    /**
     * Getters
     */
    public function getId(): ?int
    {
        return $this->id;
    }

    public function getMasterId(): string
    {
        return $this->masterId;
    }

    public function getExternalSku(): string
    {
        return $this->externalSku;
    }

    public function getSource(): string
    {
        return $this->source;
    }

    public function getSourceName(): ?string
    {
        return $this->sourceName;
    }

    public function getSourceBrand(): ?string
    {
        return $this->sourceBrand;
    }

    public function getSourceCategory(): ?string
    {
        return $this->sourceCategory;
    }

    public function getSourcePrice(): ?float
    {
        return $this->sourcePrice;
    }

    public function getSourceAttributes(): ?array
    {
        return $this->sourceAttributes;
    }

    public function getConfidenceScore(): ?float
    {
        return $this->confidenceScore;
    }

    public function getVerificationStatus(): string
    {
        return $this->verificationStatus;
    }

    public function getMatchMethod(): ?string
    {
        return $this->matchMethod;
    }

    public function getCreatedAt(): DateTime
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): DateTime
    {
        return $this->updatedAt;
    }

    public function getVerifiedBy(): ?string
    {
        return $this->verifiedBy;
    }

    public function getVerifiedAt(): ?DateTime
    {
        return $this->verifiedAt;
    }

    /**
     * Setters with validation
     */
    public function setId(?int $id): void
    {
        $this->id = $id;
    }

    public function setMasterId(string $masterId): void
    {
        if (empty(trim($masterId))) {
            throw new InvalidArgumentException('Master ID cannot be empty');
        }
        $this->masterId = $masterId;
        $this->touch();
    }

    public function setExternalSku(string $externalSku): void
    {
        $trimmed = trim($externalSku);
        if (empty($trimmed)) {
            throw new InvalidArgumentException('External SKU cannot be empty');
        }
        $this->externalSku = $trimmed;
        $this->touch();
    }

    public function setSource(string $source): void
    {
        if (!in_array($source, self::VALID_SOURCES)) {
            throw new InvalidArgumentException('Invalid source. Must be one of: ' . implode(', ', self::VALID_SOURCES));
        }
        $this->source = $source;
        $this->touch();
    }

    public function setSourceName(?string $sourceName): void
    {
        $this->sourceName = $sourceName !== null ? trim($sourceName) : null;
        $this->touch();
    }

    public function setSourceBrand(?string $sourceBrand): void
    {
        $this->sourceBrand = $sourceBrand !== null ? trim($sourceBrand) : null;
        $this->touch();
    }

    public function setSourceCategory(?string $sourceCategory): void
    {
        $this->sourceCategory = $sourceCategory !== null ? trim($sourceCategory) : null;
        $this->touch();
    }

    public function setSourcePrice(?float $sourcePrice): void
    {
        if ($sourcePrice !== null && $sourcePrice < 0) {
            throw new InvalidArgumentException('Source price cannot be negative');
        }
        $this->sourcePrice = $sourcePrice;
        $this->touch();
    }

    public function setSourceAttributes(?array $sourceAttributes): void
    {
        $this->sourceAttributes = $sourceAttributes;
        $this->touch();
    }

    public function setConfidenceScore(?float $confidenceScore): void
    {
        if ($confidenceScore !== null && ($confidenceScore < 0.0 || $confidenceScore > 1.0)) {
            throw new InvalidArgumentException('Confidence score must be between 0.0 and 1.0');
        }
        $this->confidenceScore = $confidenceScore;
        $this->touch();
    }

    public function setVerificationStatus(string $verificationStatus): void
    {
        if (!in_array($verificationStatus, self::VALID_STATUSES)) {
            throw new InvalidArgumentException('Invalid verification status. Must be one of: ' . implode(', ', self::VALID_STATUSES));
        }
        $this->verificationStatus = $verificationStatus;
        $this->touch();
    }

    public function setMatchMethod(?string $matchMethod): void
    {
        $this->matchMethod = $matchMethod;
        $this->touch();
    }

    public function setVerifiedBy(?string $verifiedBy): void
    {
        $this->verifiedBy = $verifiedBy;
        $this->touch();
    }

    public function setVerifiedAt(?DateTime $verifiedAt): void
    {
        $this->verifiedAt = $verifiedAt;
        $this->touch();
    }

    /**
     * Business logic methods
     */
    public function isVerified(): bool
    {
        return in_array($this->verificationStatus, [self::STATUS_AUTO, self::STATUS_MANUAL]);
    }

    public function isPending(): bool
    {
        return $this->verificationStatus === self::STATUS_PENDING;
    }

    public function isRejected(): bool
    {
        return $this->verificationStatus === self::STATUS_REJECTED;
    }

    public function isHighConfidence(): bool
    {
        return $this->confidenceScore !== null && $this->confidenceScore >= 0.8;
    }

    public function isMediumConfidence(): bool
    {
        return $this->confidenceScore !== null && 
               $this->confidenceScore >= 0.5 && 
               $this->confidenceScore < 0.8;
    }

    public function isLowConfidence(): bool
    {
        return $this->confidenceScore !== null && $this->confidenceScore < 0.5;
    }

    public function approve(string $verifiedBy, ?string $matchMethod = null): void
    {
        $this->setVerificationStatus(self::STATUS_MANUAL);
        $this->setVerifiedBy($verifiedBy);
        $this->setVerifiedAt(new DateTime());
        if ($matchMethod !== null) {
            $this->setMatchMethod($matchMethod);
        }
    }

    public function reject(string $verifiedBy): void
    {
        $this->setVerificationStatus(self::STATUS_REJECTED);
        $this->setVerifiedBy($verifiedBy);
        $this->setVerifiedAt(new DateTime());
    }

    public function autoApprove(float $confidenceScore, string $matchMethod): void
    {
        $this->setVerificationStatus(self::STATUS_AUTO);
        $this->setConfidenceScore($confidenceScore);
        $this->setMatchMethod($matchMethod);
        $this->setVerifiedAt(new DateTime());
    }

    public function addSourceAttribute(string $key, $value): void
    {
        if ($this->sourceAttributes === null) {
            $this->sourceAttributes = [];
        }
        $this->sourceAttributes[$key] = $value;
        $this->touch();
    }

    public function removeSourceAttribute(string $key): void
    {
        if ($this->sourceAttributes !== null && isset($this->sourceAttributes[$key])) {
            unset($this->sourceAttributes[$key]);
            $this->touch();
        }
    }

    public function getSourceAttribute(string $key, $default = null)
    {
        return $this->sourceAttributes[$key] ?? $default;
    }

    public function hasSourceAttribute(string $key): bool
    {
        return isset($this->sourceAttributes[$key]);
    }

    /**
     * Validation methods
     */
    public function validateExternalSkuFormat(): bool
    {
        switch ($this->source) {
            case self::SOURCE_OZON:
            case self::SOURCE_WILDBERRIES:
                // Numeric SKUs for marketplaces
                return preg_match('/^[0-9]{6,}$/', $this->externalSku) === 1;
            
            case self::SOURCE_INTERNAL:
                // Alphanumeric SKUs for internal system
                return preg_match('/^[A-Z0-9_-]{3,}$/', $this->externalSku) === 1;
            
            default:
                // Generic validation for other sources
                return strlen(trim($this->externalSku)) >= 3;
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
            'id' => $this->id,
            'master_id' => $this->masterId,
            'external_sku' => $this->externalSku,
            'source' => $this->source,
            'source_name' => $this->sourceName,
            'source_brand' => $this->sourceBrand,
            'source_category' => $this->sourceCategory,
            'source_price' => $this->sourcePrice,
            'source_attributes' => $this->sourceAttributes ? json_encode($this->sourceAttributes) : null,
            'confidence_score' => $this->confidenceScore,
            'verification_status' => $this->verificationStatus,
            'match_method' => $this->matchMethod,
            'created_at' => $this->createdAt->format('Y-m-d H:i:s'),
            'updated_at' => $this->updatedAt->format('Y-m-d H:i:s'),
            'verified_by' => $this->verifiedBy,
            'verified_at' => $this->verifiedAt?->format('Y-m-d H:i:s')
        ];
    }

    /**
     * Create from database array
     */
    public static function fromArray(array $data): self
    {
        $sourceAttributes = null;
        if (!empty($data['source_attributes'])) {
            try {
                $sourceAttributes = json_decode($data['source_attributes'], true, 512, JSON_THROW_ON_ERROR);
            } catch (JsonException $e) {
                $sourceAttributes = null;
            }
        }

        return new self(
            masterId: $data['master_id'],
            externalSku: $data['external_sku'],
            source: $data['source'],
            sourceName: $data['source_name'] ?? null,
            sourceBrand: $data['source_brand'] ?? null,
            sourceCategory: $data['source_category'] ?? null,
            sourcePrice: $data['source_price'] ?? null,
            sourceAttributes: $sourceAttributes,
            confidenceScore: $data['confidence_score'] ?? null,
            verificationStatus: $data['verification_status'] ?? self::STATUS_PENDING,
            matchMethod: $data['match_method'] ?? null,
            verifiedBy: $data['verified_by'] ?? null,
            verifiedAt: isset($data['verified_at']) ? new DateTime($data['verified_at']) : null,
            createdAt: isset($data['created_at']) ? new DateTime($data['created_at']) : null,
            updatedAt: isset($data['updated_at']) ? new DateTime($data['updated_at']) : null,
            id: $data['id'] ?? null
        );
    }

    /**
     * Create a unique key for this mapping
     */
    public function getUniqueKey(): string
    {
        return $this->source . ':' . $this->externalSku;
    }

    /**
     * String representation
     */
    public function __toString(): string
    {
        return sprintf(
            'SkuMapping[%s]: %s (%s) -> %s [%s]',
            $this->id ?? 'new',
            $this->externalSku,
            $this->source,
            $this->masterId,
            $this->verificationStatus
        );
    }
}