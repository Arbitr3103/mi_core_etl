<?php

namespace Models;

use DateTime;
use InvalidArgumentException;

/**
 * ActivityChangeLog Model
 * 
 * Represents a log entry for product activity status changes.
 * Tracks when products become active/inactive and the reasons for changes.
 */
class ActivityChangeLog
{
    // Change type constants
    public const CHANGE_TYPE_ACTIVATION = 'activation';
    public const CHANGE_TYPE_DEACTIVATION = 'deactivation';
    public const CHANGE_TYPE_INITIAL = 'initial';
    public const CHANGE_TYPE_RECHECK = 'recheck';

    private ?int $id = null;
    private string $productId;
    private string $externalSku;
    private ?bool $previousStatus;
    private bool $newStatus;
    private string $reason;
    private DateTime $changedAt;
    private ?string $changedBy;
    private ?array $metadata;

    public function __construct(
        string $productId,
        string $externalSku,
        ?bool $previousStatus,
        bool $newStatus,
        string $reason,
        ?DateTime $changedAt = null,
        ?string $changedBy = null,
        ?array $metadata = null
    ) {
        $this->setProductId($productId);
        $this->setExternalSku($externalSku);
        $this->setPreviousStatus($previousStatus);
        $this->setNewStatus($newStatus);
        $this->setReason($reason);
        $this->setChangedAt($changedAt ?? new DateTime());
        $this->setChangedBy($changedBy);
        $this->setMetadata($metadata);
    }

    /**
     * Create an activation change log
     */
    public static function createActivation(
        string $productId,
        string $externalSku,
        ?bool $previousStatus,
        string $reason,
        ?string $changedBy = null,
        ?array $metadata = null
    ): self {
        return new self(
            $productId,
            $externalSku,
            $previousStatus,
            true,
            $reason,
            null,
            $changedBy,
            $metadata
        );
    }

    /**
     * Create a deactivation change log
     */
    public static function createDeactivation(
        string $productId,
        string $externalSku,
        ?bool $previousStatus,
        string $reason,
        ?string $changedBy = null,
        ?array $metadata = null
    ): self {
        return new self(
            $productId,
            $externalSku,
            $previousStatus,
            false,
            $reason,
            null,
            $changedBy,
            $metadata
        );
    }

    /**
     * Create an initial check change log
     */
    public static function createInitialCheck(
        string $productId,
        string $externalSku,
        bool $newStatus,
        string $reason,
        ?string $changedBy = null,
        ?array $metadata = null
    ): self {
        return new self(
            $productId,
            $externalSku,
            null, // No previous status for initial check
            $newStatus,
            $reason,
            null,
            $changedBy,
            $metadata
        );
    }

    /**
     * Create a recheck change log (status unchanged)
     */
    public static function createRecheck(
        string $productId,
        string $externalSku,
        bool $status,
        string $reason,
        ?string $changedBy = null,
        ?array $metadata = null
    ): self {
        return new self(
            $productId,
            $externalSku,
            $status, // Previous status same as new status
            $status,
            $reason,
            null,
            $changedBy,
            $metadata
        );
    }

    /**
     * Create from database array
     */
    public static function fromArray(array $data): self
    {
        $instance = new self(
            $data['product_id'],
            $data['external_sku'],
            isset($data['previous_status']) ? (bool)$data['previous_status'] : null,
            (bool)$data['new_status'],
            $data['reason'],
            isset($data['changed_at']) ? new DateTime($data['changed_at']) : new DateTime(),
            $data['changed_by'] ?? null,
            isset($data['metadata']) ? json_decode($data['metadata'], true) : null
        );

        if (isset($data['id'])) {
            $instance->setId((int)$data['id']);
        }

        return $instance;
    }

    /**
     * Convert to database array
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'product_id' => $this->productId,
            'external_sku' => $this->externalSku,
            'previous_status' => $this->previousStatus,
            'new_status' => $this->newStatus,
            'reason' => $this->reason,
            'changed_at' => $this->changedAt->format('Y-m-d H:i:s'),
            'changed_by' => $this->changedBy,
            'metadata' => $this->metadata ? json_encode($this->metadata) : null
        ];
    }

    /**
     * Get change type based on status transition
     */
    public function getChangeType(): string
    {
        if ($this->previousStatus === null) {
            return self::CHANGE_TYPE_INITIAL;
        }

        if ($this->previousStatus === $this->newStatus) {
            return self::CHANGE_TYPE_RECHECK;
        }

        if ($this->previousStatus === false && $this->newStatus === true) {
            return self::CHANGE_TYPE_ACTIVATION;
        }

        if ($this->previousStatus === true && $this->newStatus === false) {
            return self::CHANGE_TYPE_DEACTIVATION;
        }

        return 'unknown';
    }

    /**
     * Check if this represents a status change
     */
    public function isStatusChange(): bool
    {
        return $this->previousStatus !== null && $this->previousStatus !== $this->newStatus;
    }

    /**
     * Check if this is an activation
     */
    public function isActivation(): bool
    {
        return $this->getChangeType() === self::CHANGE_TYPE_ACTIVATION;
    }

    /**
     * Check if this is a deactivation
     */
    public function isDeactivation(): bool
    {
        return $this->getChangeType() === self::CHANGE_TYPE_DEACTIVATION;
    }

    /**
     * Check if this is an initial check
     */
    public function isInitialCheck(): bool
    {
        return $this->getChangeType() === self::CHANGE_TYPE_INITIAL;
    }

    /**
     * Check if this is a recheck
     */
    public function isRecheck(): bool
    {
        return $this->getChangeType() === self::CHANGE_TYPE_RECHECK;
    }

    /**
     * Get human-readable description
     */
    public function getDescription(): string
    {
        $changeType = $this->getChangeType();
        
        switch ($changeType) {
            case self::CHANGE_TYPE_ACTIVATION:
                return "Product '{$this->externalSku}' was activated";
            case self::CHANGE_TYPE_DEACTIVATION:
                return "Product '{$this->externalSku}' was deactivated";
            case self::CHANGE_TYPE_INITIAL:
                $status = $this->newStatus ? 'active' : 'inactive';
                return "Product '{$this->externalSku}' initial check: {$status}";
            case self::CHANGE_TYPE_RECHECK:
                $status = $this->newStatus ? 'active' : 'inactive';
                return "Product '{$this->externalSku}' status confirmed: {$status}";
            default:
                return "Product '{$this->externalSku}' status changed";
        }
    }

    // Getters and Setters

    public function getId(): ?int
    {
        return $this->id;
    }

    public function setId(int $id): self
    {
        $this->id = $id;
        return $this;
    }

    public function getProductId(): string
    {
        return $this->productId;
    }

    public function setProductId(string $productId): self
    {
        if (empty(trim($productId))) {
            throw new InvalidArgumentException('Product ID cannot be empty');
        }
        $this->productId = trim($productId);
        return $this;
    }

    public function getExternalSku(): string
    {
        return $this->externalSku;
    }

    public function setExternalSku(string $externalSku): self
    {
        if (empty(trim($externalSku))) {
            throw new InvalidArgumentException('External SKU cannot be empty');
        }
        $this->externalSku = trim($externalSku);
        return $this;
    }

    public function getPreviousStatus(): ?bool
    {
        return $this->previousStatus;
    }

    public function setPreviousStatus(?bool $previousStatus): self
    {
        $this->previousStatus = $previousStatus;
        return $this;
    }

    public function getNewStatus(): bool
    {
        return $this->newStatus;
    }

    public function setNewStatus(bool $newStatus): self
    {
        $this->newStatus = $newStatus;
        return $this;
    }

    public function getReason(): string
    {
        return $this->reason;
    }

    public function setReason(string $reason): self
    {
        if (empty(trim($reason))) {
            throw new InvalidArgumentException('Reason cannot be empty');
        }
        $this->reason = trim($reason);
        return $this;
    }

    public function getChangedAt(): DateTime
    {
        return $this->changedAt;
    }

    public function setChangedAt(DateTime $changedAt): self
    {
        $this->changedAt = $changedAt;
        return $this;
    }

    public function getChangedBy(): ?string
    {
        return $this->changedBy;
    }

    public function setChangedBy(?string $changedBy): self
    {
        $this->changedBy = $changedBy ? trim($changedBy) : null;
        return $this;
    }

    public function getMetadata(): ?array
    {
        return $this->metadata;
    }

    public function setMetadata(?array $metadata): self
    {
        $this->metadata = $metadata;
        return $this;
    }

    /**
     * Add metadata entry
     */
    public function addMetadata(string $key, $value): self
    {
        if ($this->metadata === null) {
            $this->metadata = [];
        }
        $this->metadata[$key] = $value;
        return $this;
    }

    /**
     * Get metadata entry
     */
    public function getMetadataValue(string $key, $default = null)
    {
        return $this->metadata[$key] ?? $default;
    }

    /**
     * Remove metadata entry
     */
    public function removeMetadata(string $key): self
    {
        if ($this->metadata !== null && isset($this->metadata[$key])) {
            unset($this->metadata[$key]);
        }
        return $this;
    }

    /**
     * Check if metadata exists
     */
    public function hasMetadata(string $key): bool
    {
        return $this->metadata !== null && isset($this->metadata[$key]);
    }

    /**
     * Get formatted changed date
     */
    public function getFormattedChangedAt(string $format = 'Y-m-d H:i:s'): string
    {
        return $this->changedAt->format($format);
    }

    /**
     * Get time since change
     */
    public function getTimeSinceChange(): string
    {
        $now = new DateTime();
        $diff = $now->diff($this->changedAt);

        if ($diff->days > 0) {
            return $diff->days . ' day' . ($diff->days > 1 ? 's' : '') . ' ago';
        } elseif ($diff->h > 0) {
            return $diff->h . ' hour' . ($diff->h > 1 ? 's' : '') . ' ago';
        } elseif ($diff->i > 0) {
            return $diff->i . ' minute' . ($diff->i > 1 ? 's' : '') . ' ago';
        } else {
            return 'Just now';
        }
    }

    /**
     * Validate the change log data
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

        if (empty($this->reason)) {
            $errors[] = 'Reason is required';
        }

        if ($this->changedAt > new DateTime()) {
            $errors[] = 'Changed date cannot be in the future';
        }

        return $errors;
    }

    /**
     * Check if the change log is valid
     */
    public function isValid(): bool
    {
        return empty($this->validate());
    }

    /**
     * String representation
     */
    public function __toString(): string
    {
        return sprintf(
            '[%s] %s: %s -> %s (%s)',
            $this->getFormattedChangedAt(),
            $this->externalSku,
            $this->previousStatus === null ? 'null' : ($this->previousStatus ? 'active' : 'inactive'),
            $this->newStatus ? 'active' : 'inactive',
            $this->reason
        );
    }

    /**
     * JSON serialization
     */
    public function jsonSerialize(): array
    {
        return [
            'id' => $this->id,
            'product_id' => $this->productId,
            'external_sku' => $this->externalSku,
            'previous_status' => $this->previousStatus,
            'new_status' => $this->newStatus,
            'reason' => $this->reason,
            'changed_at' => $this->changedAt->format('c'), // ISO 8601 format
            'changed_by' => $this->changedBy,
            'metadata' => $this->metadata,
            'change_type' => $this->getChangeType(),
            'description' => $this->getDescription(),
            'time_since_change' => $this->getTimeSinceChange()
        ];
    }
}