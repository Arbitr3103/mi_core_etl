<?php

namespace MDM\Services;

use MDM\Models\MasterProduct;
use MDM\Models\SkuMapping;
use MDM\Models\DataQualityMetric;
use InvalidArgumentException;

/**
 * Validation Service
 * 
 * Provides validation methods for MDM entities and business rules.
 */
class ValidationService
{
    /**
     * Validate Master Product
     */
    public function validateMasterProduct(MasterProduct $product): array
    {
        $errors = [];

        // Required fields validation
        if (empty(trim($product->getCanonicalName()))) {
            $errors[] = 'Canonical name is required';
        }

        if (strlen($product->getCanonicalName()) < 3) {
            $errors[] = 'Canonical name must be at least 3 characters long';
        }

        if (strlen($product->getCanonicalName()) > 500) {
            $errors[] = 'Canonical name cannot exceed 500 characters';
        }

        // Brand validation
        if ($product->getCanonicalBrand() !== null && strlen($product->getCanonicalBrand()) > 200) {
            $errors[] = 'Canonical brand cannot exceed 200 characters';
        }

        // Category validation
        if ($product->getCanonicalCategory() !== null && strlen($product->getCanonicalCategory()) > 200) {
            $errors[] = 'Canonical category cannot exceed 200 characters';
        }

        // Barcode validation
        if ($product->getBarcode() !== null && !$this->isValidBarcode($product->getBarcode())) {
            $errors[] = 'Barcode must be 8-14 digits';
        }

        // Weight validation
        if ($product->getWeightGrams() !== null && $product->getWeightGrams() <= 0) {
            $errors[] = 'Weight must be positive';
        }

        // Dimensions validation
        if ($product->getDimensions() !== null) {
            $dimensionErrors = $this->validateDimensions($product->getDimensions());
            $errors = array_merge($errors, $dimensionErrors);
        }

        // Master ID format validation
        if (!$this->isValidMasterIdFormat($product->getMasterId())) {
            $errors[] = 'Master ID must follow format: MASTER_XXXXXXXX or PROD_XXXXXXXX';
        }

        return $errors;
    }

    /**
     * Validate SKU Mapping
     */
    public function validateSkuMapping(SkuMapping $mapping): array
    {
        $errors = [];

        // Required fields validation
        if (empty(trim($mapping->getExternalSku()))) {
            $errors[] = 'External SKU is required';
        }

        if (empty(trim($mapping->getSource()))) {
            $errors[] = 'Source is required';
        }

        if (empty(trim($mapping->getMasterId()))) {
            $errors[] = 'Master ID is required';
        }

        // Source validation
        if (!in_array($mapping->getSource(), SkuMapping::VALID_SOURCES)) {
            $errors[] = 'Invalid source. Must be one of: ' . implode(', ', SkuMapping::VALID_SOURCES);
        }

        // Verification status validation
        if (!in_array($mapping->getVerificationStatus(), SkuMapping::VALID_STATUSES)) {
            $errors[] = 'Invalid verification status. Must be one of: ' . implode(', ', SkuMapping::VALID_STATUSES);
        }

        // Confidence score validation
        if ($mapping->getConfidenceScore() !== null) {
            if ($mapping->getConfidenceScore() < 0.0 || $mapping->getConfidenceScore() > 1.0) {
                $errors[] = 'Confidence score must be between 0.0 and 1.0';
            }
        }

        // Source price validation
        if ($mapping->getSourcePrice() !== null && $mapping->getSourcePrice() < 0) {
            $errors[] = 'Source price cannot be negative';
        }

        // External SKU format validation by source
        if (!$this->isValidExternalSkuFormat($mapping->getExternalSku(), $mapping->getSource())) {
            $errors[] = 'Invalid external SKU format for source: ' . $mapping->getSource();
        }

        // Business rule: verified mappings should have verified_by and verified_at
        if (in_array($mapping->getVerificationStatus(), [SkuMapping::STATUS_AUTO, SkuMapping::STATUS_MANUAL])) {
            if ($mapping->getVerifiedAt() === null) {
                $errors[] = 'Verified mappings must have verification timestamp';
            }
        }

        return $errors;
    }

    /**
     * Validate Data Quality Metric
     */
    public function validateDataQualityMetric(DataQualityMetric $metric): array
    {
        $errors = [];

        // Required fields validation
        if (empty(trim($metric->getMetricName()))) {
            $errors[] = 'Metric name is required';
        }

        // Record counts validation
        if ($metric->getTotalRecords() < 0) {
            $errors[] = 'Total records cannot be negative';
        }

        if ($metric->getGoodRecords() < 0) {
            $errors[] = 'Good records cannot be negative';
        }

        if ($metric->getBadRecords() < 0) {
            $errors[] = 'Bad records cannot be negative';
        }

        // Record consistency validation
        if ($metric->getTotalRecords() !== ($metric->getGoodRecords() + $metric->getBadRecords())) {
            $errors[] = 'Total records must equal sum of good and bad records';
        }

        // Percentage validation
        if ($metric->getMetricPercentage() !== null) {
            if ($metric->getMetricPercentage() < 0.0 || $metric->getMetricPercentage() > 100.0) {
                $errors[] = 'Metric percentage must be between 0.0 and 100.0';
            }
        }

        // Category validation
        if ($metric->getCategory() !== null && !in_array($metric->getCategory(), DataQualityMetric::VALID_CATEGORIES)) {
            $errors[] = 'Invalid category. Must be one of: ' . implode(', ', DataQualityMetric::VALID_CATEGORIES);
        }

        return $errors;
    }

    /**
     * Validate barcode format
     */
    private function isValidBarcode(string $barcode): bool
    {
        return preg_match('/^[0-9]{8,14}$/', $barcode) === 1;
    }

    /**
     * Validate Master ID format
     */
    private function isValidMasterIdFormat(string $masterId): bool
    {
        return preg_match('/^(MASTER_|PROD_)[0-9A-Z]{6,}$/', $masterId) === 1;
    }

    /**
     * Validate external SKU format based on source
     */
    private function isValidExternalSkuFormat(string $externalSku, string $source): bool
    {
        switch ($source) {
            case SkuMapping::SOURCE_OZON:
            case SkuMapping::SOURCE_WILDBERRIES:
                // Numeric SKUs for marketplaces
                return preg_match('/^[0-9]{6,}$/', $externalSku) === 1;
            
            case SkuMapping::SOURCE_INTERNAL:
                // Alphanumeric SKUs for internal system
                return preg_match('/^[A-Z0-9_-]{3,}$/', $externalSku) === 1;
            
            default:
                // Generic validation for other sources
                return strlen(trim($externalSku)) >= 3;
        }
    }

    /**
     * Validate dimensions array
     */
    private function validateDimensions(array $dimensions): array
    {
        $errors = [];
        $requiredKeys = ['length', 'width', 'height'];
        
        foreach ($requiredKeys as $key) {
            if (!isset($dimensions[$key])) {
                $errors[] = "Dimensions must include {$key}";
            } elseif (!is_numeric($dimensions[$key]) || $dimensions[$key] <= 0) {
                $errors[] = "Dimension {$key} must be a positive number";
            }
        }

        return $errors;
    }

    /**
     * Validate business rules for Master Product
     */
    public function validateMasterProductBusinessRules(MasterProduct $product): array
    {
        $errors = [];

        // Business rule: Active products should have complete information
        if ($product->isActive() && !$product->isComplete()) {
            $errors[] = 'Active products should have brand and category information';
        }

        // Business rule: Products with barcode should be unique
        if ($product->getBarcode() !== null) {
            // This would require repository check in real implementation
            // $errors[] = 'Product with this barcode already exists';
        }

        return $errors;
    }

    /**
     * Validate business rules for SKU Mapping
     */
    public function validateSkuMappingBusinessRules(SkuMapping $mapping): array
    {
        $errors = [];

        // Business rule: High confidence auto mappings should be above threshold
        if ($mapping->getVerificationStatus() === SkuMapping::STATUS_AUTO) {
            if ($mapping->getConfidenceScore() === null || $mapping->getConfidenceScore() < 0.8) {
                $errors[] = 'Auto-verified mappings should have confidence score >= 0.8';
            }
        }

        // Business rule: Manual mappings should have verifier information
        if ($mapping->getVerificationStatus() === SkuMapping::STATUS_MANUAL) {
            if ($mapping->getVerifiedBy() === null) {
                $errors[] = 'Manual mappings must have verifier information';
            }
        }

        return $errors;
    }

    /**
     * Validate data consistency across entities
     */
    public function validateDataConsistency(array $entities): array
    {
        $errors = [];

        $masterProducts = [];
        $skuMappings = [];

        // Separate entities by type
        foreach ($entities as $entity) {
            if ($entity instanceof MasterProduct) {
                $masterProducts[$entity->getMasterId()] = $entity;
            } elseif ($entity instanceof SkuMapping) {
                $skuMappings[] = $entity;
            }
        }

        // Check SKU mappings reference valid master products
        foreach ($skuMappings as $mapping) {
            if (!isset($masterProducts[$mapping->getMasterId()])) {
                $errors[] = "SKU mapping references non-existent master product: {$mapping->getMasterId()}";
            }
        }

        // Check for duplicate external SKUs within same source
        $skuKeys = [];
        foreach ($skuMappings as $mapping) {
            $key = $mapping->getSource() . ':' . $mapping->getExternalSku();
            if (isset($skuKeys[$key])) {
                $errors[] = "Duplicate external SKU found: {$mapping->getExternalSku()} in source {$mapping->getSource()}";
            }
            $skuKeys[$key] = true;
        }

        return $errors;
    }

    /**
     * Validate batch of entities
     */
    public function validateBatch(array $entities): array
    {
        $allErrors = [];

        foreach ($entities as $index => $entity) {
            $errors = [];

            if ($entity instanceof MasterProduct) {
                $errors = array_merge(
                    $this->validateMasterProduct($entity),
                    $this->validateMasterProductBusinessRules($entity)
                );
            } elseif ($entity instanceof SkuMapping) {
                $errors = array_merge(
                    $this->validateSkuMapping($entity),
                    $this->validateSkuMappingBusinessRules($entity)
                );
            } elseif ($entity instanceof DataQualityMetric) {
                $errors = $this->validateDataQualityMetric($entity);
            }

            if (!empty($errors)) {
                $allErrors[$index] = $errors;
            }
        }

        // Add consistency validation
        $consistencyErrors = $this->validateDataConsistency($entities);
        if (!empty($consistencyErrors)) {
            $allErrors['consistency'] = $consistencyErrors;
        }

        return $allErrors;
    }

    /**
     * Check if validation passed (no errors)
     */
    public function isValid(array $validationResult): bool
    {
        return empty($validationResult);
    }

    /**
     * Format validation errors for display
     */
    public function formatErrors(array $validationResult): string
    {
        if (empty($validationResult)) {
            return 'No validation errors';
        }

        $formatted = [];
        foreach ($validationResult as $key => $errors) {
            if (is_array($errors)) {
                $formatted[] = ($key === 'consistency' ? 'Consistency' : "Entity {$key}") . ': ' . implode(', ', $errors);
            }
        }

        return implode("\n", $formatted);
    }
}