# WarehouseNormalizer Documentation

## Overview

The `WarehouseNormalizer` service provides comprehensive warehouse name standardization capabilities for the Analytics API integration. It handles normalization of warehouse names from different sources, applies automatic rules for РФЦ/МРФЦ patterns, manages duplicates and typos through fuzzy matching, and logs unrecognized values for manual review.

## Features

### Core Functionality

-   **Automatic Normalization**: Rule-based normalization for common warehouse patterns
-   **РФЦ/МРФЦ Support**: Specialized handling of Russian fulfillment center naming conventions
-   **Fuzzy Matching**: Intelligent matching for variations and typos
-   **Manual Rule Management**: Ability to add and manage custom normalization rules
-   **Batch Processing**: Efficient processing of large datasets
-   **Database Integration**: Persistent storage of normalization rules and statistics
-   **Quality Tracking**: Confidence scoring and quality monitoring

### Requirements Fulfilled

-   **Requirement 14.1**: ✅ Normalization layer for standardizing data from different sources
-   **Requirement 14.2**: ✅ Standardization of warehouse names, SKUs, and units
-   **Requirement 14.3**: ✅ Handling duplicates and typos through synonym dictionary
-   **Requirement 14.4**: ✅ Logging of unrecognized values for manual review

## Installation

```php
require_once 'src/Services/WarehouseNormalizer.php';
```

## Basic Usage

### Initialization

```php
use WarehouseNormalizer;

// Initialize with database connection for persistent rules
$normalizer = new WarehouseNormalizer($pdoConnection);

// Or without database (in-memory only)
$normalizer = new WarehouseNormalizer();
```

### Simple Warehouse Name Normalization

```php
try {
    // Normalize a warehouse name from Analytics API
    $result = $normalizer->normalize('РФЦ Москва', WarehouseNormalizer::SOURCE_API);

    echo "Original: " . $result->getOriginalName() . "\n";
    echo "Normalized: " . $result->getNormalizedName() . "\n";
    echo "Confidence: " . round($result->getConfidence() * 100, 1) . "%\n";
    echo "Match Type: " . $result->getMatchType() . "\n";

    if ($result->isHighConfidence()) {
        echo "✅ High confidence normalization\n";
    }

    if ($result->needsReview()) {
        echo "⚠️  Needs manual review\n";
    }

} catch (Exception $e) {
    echo "Normalization error: " . $e->getMessage() . "\n";
}
```

### Batch Normalization

```php
$warehouseNames = [
    'РФЦ Москва',
    'рфц санкт-петербург',
    'МРФЦ Екатеринбург',
    'Склад Новосибирск'
];

$results = $normalizer->normalizeBatch($warehouseNames, WarehouseNormalizer::SOURCE_API);

foreach ($results as $index => $result) {
    echo "'{$result->getOriginalName()}' → '{$result->getNormalizedName()}'\n";
}
```

## Advanced Usage

### Manual Rule Management

```php
// Add a custom normalization rule
$ruleId = $normalizer->addManualRule(
    'Специальный Склад',
    'СПЕЦИАЛЬНЫЙ_СКЛАД',
    WarehouseNormalizer::SOURCE_MANUAL
);

echo "Added rule ID: {$ruleId}\n";

// Test the manual rule
$result = $normalizer->normalize('Специальный Склад', WarehouseNormalizer::SOURCE_MANUAL);
echo "Result: " . $result->getNormalizedName() . "\n";
```

### Handling Different Source Types

```php
$warehouseName = 'РФЦ Москва';

// Normalize from API source
$apiResult = $normalizer->normalize($warehouseName, WarehouseNormalizer::SOURCE_API);

// Normalize from UI report source
$uiResult = $normalizer->normalize($warehouseName, WarehouseNormalizer::SOURCE_UI_REPORT);

// Results may differ based on source-specific rules
echo "API: " . $apiResult->getNormalizedName() . "\n";
echo "UI: " . $uiResult->getNormalizedName() . "\n";
```

### Statistics and Monitoring

```php
// Get normalization statistics
$stats = $normalizer->getNormalizationStatistics(7); // Last 7 days

echo "Total rules: " . $stats['total_rules'] . "\n";
echo "Active rules: " . $stats['active_rules'] . "\n";

// Usage statistics by match type
foreach ($stats['usage_stats'] as $usage) {
    echo "- {$usage['match_type']}: {$usage['count']} rules\n";
}

// Get unrecognized warehouses that need review
$unrecognized = $normalizer->getUnrecognizedWarehouses(10);
foreach ($unrecognized as $warehouse) {
    echo "Needs review: '{$warehouse['original_name']}'\n";
}
```

## Normalization Rules

### Automatic Rules (РФЦ/МРФЦ Patterns)

The normalizer includes built-in rules for common Russian warehouse patterns:

#### РФЦ (Regional Fulfillment Centers)

-   `РФЦ Москва` → `РФЦ_МОСКВА`
-   `Москва РФЦ` → `РФЦ_МОСКВА`
-   `Региональный Фулфилмент Центр Москва` → `РФЦ_МОСКВА`

#### МРФЦ (Multi-Regional Fulfillment Centers)

-   `МРФЦ Екатеринбург` → `МРФЦ_ЕКАТЕРИНБУРГ`
-   `Мультирегиональный Фулфилмент Центр Екатеринбург` → `МРФЦ_ЕКАТЕРИНБУРГ`

#### General Warehouse Patterns

-   `Склад Новосибирск` → `СКЛАД_НОВОСИБИРСК`
-   `Логистический Центр Казань` → `ЛЦ_КАЗАНЬ`
-   `Распределительный Центр Ростов` → `РЦ_РОСТОВ`

#### City Name Standardization

-   `Санкт-Петербург` / `СПб` → `САНКТ_ПЕТЕРБУРГ`
-   `Ростов-на-Дону` → `РОСТОВ_НА_ДОНУ`
-   `Нижний Новгород` → `НИЖНИЙ_НОВГОРОД`

### Match Types and Confidence Levels

| Match Type      | Confidence | Description                   |
| --------------- | ---------- | ----------------------------- |
| `exact`         | 1.0 (100%) | Exact match found in database |
| `rule_based`    | 0.9 (90%)  | Matched using automatic rules |
| `fuzzy`         | 0.8 (80%)  | Fuzzy string matching         |
| `manual`        | 1.0 (100%) | Manually created rule         |
| `auto_detected` | 0.7 (70%)  | Fallback normalization        |

### Source Types

| Source Type     | Description                     |
| --------------- | ------------------------------- |
| `api`           | Data from Analytics API         |
| `ui_report`     | Data from UI report parsing     |
| `manual`        | Manually entered rules          |
| `auto_detected` | Automatically detected patterns |

## Database Integration

### Required Table Structure

The normalizer uses the `warehouse_normalization` table (created by migration 006):

```sql
CREATE TABLE warehouse_normalization (
    id SERIAL PRIMARY KEY,
    original_name VARCHAR(255) NOT NULL,
    normalized_name VARCHAR(255) NOT NULL,
    source_type VARCHAR(20) NOT NULL,
    confidence_score DECIMAL(3,2) DEFAULT 1.0,
    match_type VARCHAR(20) DEFAULT 'exact',
    created_at TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP,
    created_by VARCHAR(100) DEFAULT 'system',
    cluster_name VARCHAR(100),
    warehouse_code VARCHAR(50),
    region VARCHAR(100),
    is_active BOOLEAN DEFAULT true,
    usage_count INTEGER DEFAULT 0,
    last_used_at TIMESTAMP WITH TIME ZONE,

    UNIQUE(original_name, source_type)
);
```

### Usage Statistics Tracking

The normalizer automatically tracks:

-   **Usage Count**: How many times each rule has been used
-   **Last Used**: When the rule was last applied
-   **Confidence Scores**: Quality metrics for each rule
-   **Source Distribution**: Rules by source type

## API Reference

### WarehouseNormalizer Class

#### Methods

##### `normalize(string $warehouseName, string $sourceType = SOURCE_API): NormalizationResult`

Normalizes a single warehouse name.

**Parameters:**

-   `$warehouseName`: Original warehouse name to normalize
-   `$sourceType`: Source type (api, ui_report, manual, auto_detected)

**Returns:** `NormalizationResult` object with normalization details

##### `normalizeBatch(array $warehouseNames, string $sourceType = SOURCE_API): array`

Normalizes multiple warehouse names efficiently.

**Parameters:**

-   `$warehouseNames`: Array of warehouse names
-   `$sourceType`: Source type for all names

**Returns:** Array of `NormalizationResult` objects

##### `addManualRule(string $originalName, string $normalizedName, string $sourceType = SOURCE_MANUAL): int`

Adds a manual normalization rule.

**Parameters:**

-   `$originalName`: Original warehouse name
-   `$normalizedName`: Desired normalized name
-   `$sourceType`: Source type for the rule

**Returns:** Rule ID

##### `getNormalizationStatistics(int $days = 7): array`

Retrieves normalization statistics for monitoring.

**Parameters:**

-   `$days`: Number of days to look back

**Returns:** Array of statistics including rule counts, usage stats, and confidence distribution

##### `getUnrecognizedWarehouses(int $limit = 50): array`

Gets warehouse names that need manual review.

**Parameters:**

-   `$limit`: Maximum number of results

**Returns:** Array of unrecognized warehouse names with low confidence scores

### NormalizationResult Class

#### Methods

##### `getOriginalName(): string`

Returns the original warehouse name.

##### `getNormalizedName(): string`

Returns the normalized warehouse name.

##### `getMatchType(): string`

Returns the type of match used (exact, rule_based, fuzzy, manual, auto_detected).

##### `getConfidence(): float`

Returns the confidence score (0.0 to 1.0).

##### `getSourceType(): string`

Returns the source type.

##### `getMetadata(): array`

Returns additional metadata about the normalization.

##### `isHighConfidence(): bool`

Returns true if confidence is >= 0.9.

##### `needsReview(): bool`

Returns true if the result needs manual review.

##### `wasAutoCreated(): bool`

Returns true if the rule was automatically created.

## Integration Examples

### With AnalyticsApiClient

```php
// Complete ETL workflow with normalization
$apiClient = new AnalyticsApiClient($clientId, $apiKey, $pdo);
$normalizer = new WarehouseNormalizer($pdo);

// Fetch data from Analytics API
$apiResponse = $apiClient->getStockOnWarehouses(0, 1000);

// Normalize warehouse names
foreach ($apiResponse['data'] as &$record) {
    $normResult = $normalizer->normalize(
        $record['warehouse_name'],
        WarehouseNormalizer::SOURCE_API
    );

    $record['original_warehouse_name'] = $record['warehouse_name'];
    $record['normalized_warehouse_name'] = $normResult->getNormalizedName();
    $record['normalization_confidence'] = $normResult->getConfidence();
}

// Save to database with normalized names
// INSERT INTO inventory (..., normalized_warehouse_name, original_warehouse_name, ...)
```

### With DataValidator

```php
// Validate normalization quality
$validator = new DataValidator($pdo);
$normalizer = new WarehouseNormalizer($pdo);

// Normalize and validate
$results = $normalizer->normalizeBatch($warehouseNames, WarehouseNormalizer::SOURCE_API);

$lowConfidenceCount = 0;
foreach ($results as $result) {
    if (!$result->isHighConfidence()) {
        $lowConfidenceCount++;
    }
}

if ($lowConfidenceCount > count($results) * 0.1) { // More than 10% low confidence
    echo "⚠️  High number of low-confidence normalizations detected\n";
    // Trigger manual review process
}
```

## Performance Considerations

### Caching and Optimization

-   **In-Memory Caching**: Frequently used rules are cached in memory
-   **Fuzzy Match Caching**: Fuzzy matching results are cached to avoid recalculation
-   **Batch Processing**: Optimized for processing large datasets
-   **Database Indexes**: Proper indexing on normalization table for fast lookups

### Best Practices

1. **Use Batch Processing** for large datasets to improve performance
2. **Monitor Confidence Scores** to identify rules that need review
3. **Regular Cleanup** of unused or low-confidence rules
4. **Source-Specific Rules** for better accuracy across different data sources
5. **Manual Review Process** for unrecognized warehouse names

## Monitoring and Maintenance

### Quality Metrics

```php
// Monitor normalization quality
$stats = $normalizer->getNormalizationStatistics(7);

$totalRules = $stats['total_rules'];
$activeRules = $stats['active_rules'];
$activeRatio = ($activeRules / $totalRules) * 100;

if ($activeRatio < 80) {
    echo "⚠️  Rule maintenance needed - only {$activeRatio}% rules are active\n";
}

// Check for rules needing review
$unrecognized = $normalizer->getUnrecognizedWarehouses(10);
if (count($unrecognized) > 5) {
    echo "🚨 " . count($unrecognized) . " warehouse names need manual review\n";
}
```

### Database Maintenance Queries

```sql
-- Most used normalization rules
SELECT original_name, normalized_name, usage_count, confidence_score
FROM warehouse_normalization
WHERE is_active = 1
ORDER BY usage_count DESC
LIMIT 20;

-- Rules with low confidence that need review
SELECT original_name, normalized_name, confidence_score, usage_count
FROM warehouse_normalization
WHERE confidence_score < 0.8 AND is_active = 1
ORDER BY usage_count DESC;

-- Unused rules (candidates for cleanup)
SELECT original_name, normalized_name, created_at
FROM warehouse_normalization
WHERE usage_count = 0 AND created_at < DATE_SUB(NOW(), INTERVAL 30 DAY)
ORDER BY created_at ASC;

-- Source distribution
SELECT source_type, COUNT(*) as rule_count, AVG(confidence_score) as avg_confidence
FROM warehouse_normalization
WHERE is_active = 1
GROUP BY source_type
ORDER BY rule_count DESC;
```

## Troubleshooting

### Common Issues

1. **Low Normalization Confidence**

    - Review automatic rules for completeness
    - Add manual rules for common variations
    - Check for data quality issues in source

2. **Inconsistent Normalization**

    - Verify source type is correctly specified
    - Check for conflicting rules in database
    - Review fuzzy matching thresholds

3. **Performance Issues**

    - Use batch processing for large datasets
    - Check database indexes on normalization table
    - Monitor cache hit rates

4. **High Number of Unrecognized Names**
    - Review and expand automatic rules
    - Implement regular manual review process
    - Check for new warehouse naming patterns

### Debug Mode

```php
// Enable detailed logging for debugging
error_reporting(E_ALL);
ini_set('display_errors', 1);

$normalizer = new WarehouseNormalizer($pdo);
$result = $normalizer->normalize('Debug Warehouse', WarehouseNormalizer::SOURCE_API);

// Examine result details
echo "Original: " . $result->getOriginalName() . "\n";
echo "Normalized: " . $result->getNormalizedName() . "\n";
echo "Match Type: " . $result->getMatchType() . "\n";
echo "Confidence: " . $result->getConfidence() . "\n";
echo "Metadata: " . json_encode($result->getMetadata(), JSON_PRETTY_PRINT) . "\n";
```

## Configuration

### Constants

| Constant                   | Value | Description                       |
| -------------------------- | ----- | --------------------------------- |
| `CONFIDENCE_EXACT`         | 1.0   | Perfect match confidence          |
| `CONFIDENCE_RULE_BASED`    | 0.9   | Rule-based match confidence       |
| `CONFIDENCE_FUZZY`         | 0.8   | Fuzzy match confidence            |
| `CONFIDENCE_MANUAL`        | 1.0   | Manual rule confidence            |
| `CONFIDENCE_AUTO_DETECTED` | 0.7   | Auto-detected fallback confidence |

### Fuzzy Matching Thresholds

-   **Minimum Similarity**: 0.75 (75% similarity required for fuzzy match)
-   **Levenshtein Weight**: 0.4
-   **Jaro-Winkler Weight**: 0.3 (if available)
-   **Substring Weight**: 0.3

## Related Documentation

-   [Analytics API Client Documentation](AnalyticsApiClient.md)
-   [Data Validator Documentation](DataValidator.md)
-   [Warehouse Normalization Migration](../migrations/README_WAREHOUSE_NORMALIZATION_MIGRATION.md)
