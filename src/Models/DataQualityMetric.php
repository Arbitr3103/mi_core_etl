<?php

namespace MDM\Models;

use DateTime;
use InvalidArgumentException;
use JsonException;

/**
 * Data Quality Metric Model
 * 
 * Represents data quality metrics and monitoring information
 * for the MDM system to track data completeness, accuracy, and consistency.
 */
class DataQualityMetric
{
    private ?int $id;
    private string $metricName;
    private float $metricValue;
    private ?float $metricPercentage;
    private int $totalRecords;
    private int $goodRecords;
    private int $badRecords;
    private ?string $source;
    private ?string $category;
    private DateTime $calculationDate;
    private ?array $details;

    // Valid categories
    public const CATEGORY_COMPLETENESS = 'completeness';
    public const CATEGORY_ACCURACY = 'accuracy';
    public const CATEGORY_CONSISTENCY = 'consistency';
    public const CATEGORY_VALIDITY = 'validity';
    public const CATEGORY_UNIQUENESS = 'uniqueness';
    public const CATEGORY_TIMELINESS = 'timeliness';

    public const VALID_CATEGORIES = [
        self::CATEGORY_COMPLETENESS,
        self::CATEGORY_ACCURACY,
        self::CATEGORY_CONSISTENCY,
        self::CATEGORY_VALIDITY,
        self::CATEGORY_UNIQUENESS,
        self::CATEGORY_TIMELINESS
    ];

    // Common metric names
    public const METRIC_MASTER_PRODUCTS_COMPLETENESS = 'master_products_completeness';
    public const METRIC_SKU_MAPPING_COVERAGE = 'sku_mapping_coverage';
    public const METRIC_AUTO_MATCHING_ACCURACY = 'auto_matching_accuracy';
    public const METRIC_BRAND_STANDARDIZATION = 'brand_standardization';
    public const METRIC_CATEGORY_STANDARDIZATION = 'category_standardization';
    public const METRIC_DUPLICATE_DETECTION = 'duplicate_detection';
    public const METRIC_DATA_FRESHNESS = 'data_freshness';

    /**
     * Constructor
     */
    public function __construct(
        string $metricName,
        float $metricValue,
        int $totalRecords = 0,
        int $goodRecords = 0,
        int $badRecords = 0,
        ?float $metricPercentage = null,
        ?string $source = null,
        ?string $category = null,
        ?array $details = null,
        ?DateTime $calculationDate = null,
        ?int $id = null
    ) {
        $this->setMetricName($metricName);
        $this->setMetricValue($metricValue);
        $this->setTotalRecords($totalRecords);
        $this->setGoodRecords($goodRecords);
        $this->setBadRecords($badRecords);
        $this->setMetricPercentage($metricPercentage);
        $this->setSource($source);
        $this->setCategory($category);
        $this->setDetails($details);
        $this->calculationDate = $calculationDate ?? new DateTime();
        $this->id = $id;
    }

    /**
     * Getters
     */
    public function getId(): ?int
    {
        return $this->id;
    }

    public function getMetricName(): string
    {
        return $this->metricName;
    }

    public function getMetricValue(): float
    {
        return $this->metricValue;
    }

    public function getMetricPercentage(): ?float
    {
        return $this->metricPercentage;
    }

    public function getTotalRecords(): int
    {
        return $this->totalRecords;
    }

    public function getGoodRecords(): int
    {
        return $this->goodRecords;
    }

    public function getBadRecords(): int
    {
        return $this->badRecords;
    }

    public function getSource(): ?string
    {
        return $this->source;
    }

    public function getCategory(): ?string
    {
        return $this->category;
    }

    public function getCalculationDate(): DateTime
    {
        return $this->calculationDate;
    }

    public function getDetails(): ?array
    {
        return $this->details;
    }

    /**
     * Setters with validation
     */
    public function setId(?int $id): void
    {
        $this->id = $id;
    }

    public function setMetricName(string $metricName): void
    {
        $trimmed = trim($metricName);
        if (empty($trimmed)) {
            throw new InvalidArgumentException('Metric name cannot be empty');
        }
        $this->metricName = $trimmed;
    }

    public function setMetricValue(float $metricValue): void
    {
        $this->metricValue = $metricValue;
        
        // Auto-calculate percentage if total records is set
        if ($this->totalRecords > 0) {
            $this->metricPercentage = ($metricValue * 100);
        }
    }

    public function setMetricPercentage(?float $metricPercentage): void
    {
        if ($metricPercentage !== null && ($metricPercentage < 0.0 || $metricPercentage > 100.0)) {
            throw new InvalidArgumentException('Metric percentage must be between 0.0 and 100.0');
        }
        $this->metricPercentage = $metricPercentage;
    }

    public function setTotalRecords(int $totalRecords): void
    {
        if ($totalRecords < 0) {
            throw new InvalidArgumentException('Total records cannot be negative');
        }
        $this->totalRecords = $totalRecords;
        $this->validateRecordConsistency();
    }

    public function setGoodRecords(int $goodRecords): void
    {
        if ($goodRecords < 0) {
            throw new InvalidArgumentException('Good records cannot be negative');
        }
        $this->goodRecords = $goodRecords;
        $this->validateRecordConsistency();
    }

    public function setBadRecords(int $badRecords): void
    {
        if ($badRecords < 0) {
            throw new InvalidArgumentException('Bad records cannot be negative');
        }
        $this->badRecords = $badRecords;
        $this->validateRecordConsistency();
    }

    public function setSource(?string $source): void
    {
        $this->source = $source !== null ? trim($source) : null;
    }

    public function setCategory(?string $category): void
    {
        if ($category !== null && !in_array($category, self::VALID_CATEGORIES)) {
            throw new InvalidArgumentException('Invalid category. Must be one of: ' . implode(', ', self::VALID_CATEGORIES));
        }
        $this->category = $category;
    }

    public function setDetails(?array $details): void
    {
        $this->details = $details;
    }

    public function setCalculationDate(DateTime $calculationDate): void
    {
        $this->calculationDate = $calculationDate;
    }

    /**
     * Business logic methods
     */
    public function isGoodQuality(float $threshold = 0.8): bool
    {
        return $this->metricValue >= $threshold;
    }

    public function getQualityLevel(): string
    {
        if ($this->metricValue >= 0.9) {
            return 'excellent';
        } elseif ($this->metricValue >= 0.8) {
            return 'good';
        } elseif ($this->metricValue >= 0.6) {
            return 'fair';
        } else {
            return 'poor';
        }
    }

    public function getQualityScore(): int
    {
        return (int) round($this->metricValue * 100);
    }

    public function addDetail(string $key, $value): void
    {
        if ($this->details === null) {
            $this->details = [];
        }
        $this->details[$key] = $value;
    }

    public function removeDetail(string $key): void
    {
        if ($this->details !== null && isset($this->details[$key])) {
            unset($this->details[$key]);
        }
    }

    public function getDetail(string $key, $default = null)
    {
        return $this->details[$key] ?? $default;
    }

    public function hasDetail(string $key): bool
    {
        return isset($this->details[$key]);
    }

    /**
     * Calculate metric value from record counts
     */
    public function calculateFromRecords(): void
    {
        if ($this->totalRecords > 0) {
            $this->metricValue = $this->goodRecords / $this->totalRecords;
            $this->metricPercentage = $this->metricValue * 100;
        } else {
            $this->metricValue = 0.0;
            $this->metricPercentage = 0.0;
        }
    }

    /**
     * Update record counts and recalculate metric
     */
    public function updateRecords(int $totalRecords, int $goodRecords, int $badRecords = null): void
    {
        $this->setTotalRecords($totalRecords);
        $this->setGoodRecords($goodRecords);
        
        if ($badRecords !== null) {
            $this->setBadRecords($badRecords);
        } else {
            $this->setBadRecords($totalRecords - $goodRecords);
        }
        
        $this->calculateFromRecords();
    }

    /**
     * Validation methods
     */
    private function validateRecordConsistency(): void
    {
        if ($this->totalRecords !== ($this->goodRecords + $this->badRecords)) {
            // Auto-correct bad records if total and good are set
            if ($this->totalRecords >= $this->goodRecords) {
                $this->badRecords = $this->totalRecords - $this->goodRecords;
            }
        }
    }

    /**
     * Static factory methods for common metrics
     */
    public static function createCompletenessMetric(
        string $source,
        int $totalRecords,
        int $completeRecords,
        ?array $details = null
    ): self {
        return new self(
            metricName: self::METRIC_MASTER_PRODUCTS_COMPLETENESS,
            metricValue: $totalRecords > 0 ? $completeRecords / $totalRecords : 0.0,
            totalRecords: $totalRecords,
            goodRecords: $completeRecords,
            badRecords: $totalRecords - $completeRecords,
            source: $source,
            category: self::CATEGORY_COMPLETENESS,
            details: $details
        );
    }

    public static function createAccuracyMetric(
        string $source,
        int $totalRecords,
        int $accurateRecords,
        ?array $details = null
    ): self {
        return new self(
            metricName: self::METRIC_AUTO_MATCHING_ACCURACY,
            metricValue: $totalRecords > 0 ? $accurateRecords / $totalRecords : 0.0,
            totalRecords: $totalRecords,
            goodRecords: $accurateRecords,
            badRecords: $totalRecords - $accurateRecords,
            source: $source,
            category: self::CATEGORY_ACCURACY,
            details: $details
        );
    }

    public static function createCoverageMetric(
        string $source,
        int $totalRecords,
        int $coveredRecords,
        ?array $details = null
    ): self {
        return new self(
            metricName: self::METRIC_SKU_MAPPING_COVERAGE,
            metricValue: $totalRecords > 0 ? $coveredRecords / $totalRecords : 0.0,
            totalRecords: $totalRecords,
            goodRecords: $coveredRecords,
            badRecords: $totalRecords - $coveredRecords,
            source: $source,
            category: self::CATEGORY_COMPLETENESS,
            details: $details
        );
    }

    /**
     * Convert to array for database storage
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'metric_name' => $this->metricName,
            'metric_value' => $this->metricValue,
            'metric_percentage' => $this->metricPercentage,
            'total_records' => $this->totalRecords,
            'good_records' => $this->goodRecords,
            'bad_records' => $this->badRecords,
            'source' => $this->source,
            'category' => $this->category,
            'calculation_date' => $this->calculationDate->format('Y-m-d H:i:s'),
            'details' => $this->details ? json_encode($this->details) : null
        ];
    }

    /**
     * Create from database array
     */
    public static function fromArray(array $data): self
    {
        $details = null;
        if (!empty($data['details'])) {
            try {
                $details = json_decode($data['details'], true, 512, JSON_THROW_ON_ERROR);
            } catch (JsonException $e) {
                $details = null;
            }
        }

        return new self(
            metricName: $data['metric_name'],
            metricValue: (float) $data['metric_value'],
            totalRecords: (int) ($data['total_records'] ?? 0),
            goodRecords: (int) ($data['good_records'] ?? 0),
            badRecords: (int) ($data['bad_records'] ?? 0),
            metricPercentage: isset($data['metric_percentage']) ? (float) $data['metric_percentage'] : null,
            source: $data['source'] ?? null,
            category: $data['category'] ?? null,
            details: $details,
            calculationDate: isset($data['calculation_date']) ? new DateTime($data['calculation_date']) : null,
            id: $data['id'] ?? null
        );
    }

    /**
     * String representation
     */
    public function __toString(): string
    {
        return sprintf(
            'DataQualityMetric[%s]: %s = %.2f%% (%d/%d) [%s]',
            $this->id ?? 'new',
            $this->metricName,
            $this->metricPercentage ?? ($this->metricValue * 100),
            $this->goodRecords,
            $this->totalRecords,
            $this->getQualityLevel()
        );
    }
}