# DataValidator Documentation

## Overview

The `DataValidator` service provides comprehensive validation capabilities for Analytics API data, including data integrity checks, quality assessment, anomaly detection, and quality metrics calculation. It ensures that data from the Ozon Analytics API meets quality standards before being processed by the ETL pipeline.

## Features

### Core Functionality

-   **Data Validation**: Comprehensive validation rules for Analytics API data
-   **Anomaly Detection**: Statistical and business logic anomaly detection
-   **Quality Metrics**: Multi-dimensional quality assessment (completeness, accuracy, consistency, freshness, validity)
-   **Batch Processing**: Efficient validation of large datasets
-   **Database Logging**: Comprehensive logging of validation results and quality issues
-   **Statistical Analysis**: Advanced statistical methods for outlier detection

### Requirements Fulfilled

-   **Requirement 4.1**: ✅ Validation of Analytics API data
-   **Requirement 4.2**: ✅ Data integrity and quality checks
-   **Requirement 4.3**: ✅ Anomaly detection in warehouse stock data
-   **Requirement 4.4**: ✅ Quality metrics for Analytics API data

## Installation

```php
require_once 'src/Services/DataValidator.php';
```

## Basic Usage

### Initialization

```php
use DataValidator;

// Initialize with database connection for logging
$validator = new DataValidator($pdoConnection);

// Or without database logging
$validator = new DataValidator();
```

### Simple Data Validation

```php
try {
    // Sample Analytics API data
    $apiData = [
        [
            'sku' => 'PRODUCT_001',
            'warehouse_name' => 'РФЦ Москва',
            'available_stock' => 150,
            'reserved_stock' => 25,
            'total_stock' => 175,
            'product_name' => 'Смартфон Samsung Galaxy',
            'price' => 45999.99,
            'updated_at' => date('Y-m-d H:i:s')
        ]
        // ... more records
    ];

    // Validate the batch
    $result = $validator->validateBatch($apiData, 'batch_001');

    echo "Quality Score: " . $result->getQualityScore() . "%\n";
    echo "Valid Records: " . $result->getResults()['valid_records'] . "\n";
    echo "Invalid Records: " . $result->getResults()['invalid_records'] . "\n";

    if ($result->hasAnomalies()) {
        echo "Anomalies detected: " . count($result->getResults()['anomalies']) . "\n";
    }

} catch (Exception $e) {
    echo "Validation error: " . $e->getMessage() . "\n";
}
```

### Individual Record Validation

```php
$record = [
    'sku' => 'TEST_SKU_001',
    'warehouse_name' => 'РФЦ Москва',
    'available_stock' => 100,
    'reserved_stock' => 20,
    'total_stock' => 120
];

$recordResult = $validator->validateRecord($record, 0, 'validation_batch_001');

if ($recordResult->isValid()) {
    echo "Record is valid\n";
} else {
    echo "Record has issues:\n";
    foreach ($recordResult->getIssues() as $issue) {
        echo "- " . $issue['message'] . "\n";
    }
}
```

## Advanced Usage

### Anomaly Detection

```php
// Detect anomalies in a dataset
$anomalies = $validator->detectAnomalies($apiData);

foreach ($anomalies as $anomaly) {
    echo "Anomaly Type: " . $anomaly['type'] . "\n";
    echo "SKU: " . $anomaly['sku'] . "\n";
    echo "Warehouse: " . $anomaly['warehouse'] . "\n";
    echo "Severity: " . $anomaly['severity'] . "\n";

    if (isset($anomaly['expected_range'])) {
        echo "Expected Range: " . $anomaly['expected_range']['min'] .
             " - " . $anomaly['expected_range']['max'] . "\n";
    }
}
```

### Quality Metrics Calculation

```php
$metrics = $validator->calculateQualityMetrics($apiData);

echo "Quality Metrics:\n";
echo "- Completeness: " . $metrics['completeness'] . "%\n";
echo "- Accuracy: " . $metrics['accuracy'] . "%\n";
echo "- Consistency: " . $metrics['consistency'] . "%\n";
echo "- Freshness: " . $metrics['freshness'] . "%\n";
echo "- Validity: " . $metrics['validity'] . "%\n";
echo "- Overall Score: " . $metrics['overall_score'] . "%\n";

// Interpret quality level
if ($metrics['overall_score'] >= 90) {
    echo "Quality Level: Excellent\n";
} elseif ($metrics['overall_score'] >= 80) {
    echo "Quality Level: Good\n";
} elseif ($metrics['overall_score'] >= 70) {
    echo "Quality Level: Acceptable\n";
} else {
    echo "Quality Level: Poor - Needs attention\n";
}
```

### Integration with AnalyticsApiClient

```php
// Complete ETL validation workflow
$apiClient = new AnalyticsApiClient($clientId, $apiKey, $pdo);
$validator = new DataValidator($pdo);

// Fetch data from Analytics API
$batchId = 'api_batch_' . date('Ymd_His');
$apiResponse = $apiClient->getStockOnWarehouses(0, 1000);

// Validate the fetched data
$validationResult = $validator->validateBatch($apiResponse['data'], $batchId);

// Process based on validation results
if ($validationResult->getQualityScore() >= 80) {
    echo "Data quality acceptable, proceeding with ETL...\n";
    // Process valid data
} else {
    echo "Data quality too low, investigating issues...\n";

    // Log quality issues for investigation
    foreach ($validationResult->getResults()['validation_details'] as $recordResult) {
        if (!$recordResult->isValid()) {
            foreach ($recordResult->getIssues() as $issue) {
                error_log("Data quality issue: " . $issue['message']);
            }
        }
    }
}
```

## Validation Rules

### Required Fields Validation

-   **sku**: Must be non-empty string, max 255 characters
-   **warehouse_name**: Must be non-empty string

### Stock Values Validation

-   **available_stock**: Must be non-negative
-   **reserved_stock**: Must be non-negative
-   **total_stock**: Must be non-negative
-   **Business Logic**: Available + Reserved should not exceed Total by more than 10%

### Price Validation

-   **price**: Must be non-negative
-   **price**: Must not exceed reasonable threshold (1,000,000)

### Data Format Validation

-   **SKU Format**: Valid string format, reasonable length
-   **Warehouse Name**: Non-empty, trimmed string
-   **Numeric Fields**: Proper numeric types and ranges

## Anomaly Detection

### Statistical Anomalies

-   **Stock Outliers**: Using IQR (Interquartile Range) method
-   **Price Outliers**: Statistical deviation from normal ranges
-   **Extreme Values**: Values exceeding reasonable business thresholds

### Business Logic Anomalies

-   **Zero Available with Reserved**: Available stock = 0 but reserved stock > 0
-   **Extreme Stock Levels**: Stock levels exceeding 1,000,000 units
-   **Suspicious Prices**: Prices below 1 RUB or above 500,000 RUB
-   **Inconsistent Stock**: Available or reserved exceeding total stock
-   **Suspicious Product Names**: Names that are too short or only numeric

### Anomaly Severity Levels

-   **Critical**: Requires immediate attention, may block processing
-   **Warning**: Should be reviewed but doesn't block processing
-   **Info**: Informational, logged for analysis

## Quality Metrics

### Completeness (0-100%)

Measures how complete the data is across required and optional fields:

-   **Required Fields Weight**: 70%
-   **Optional Fields Weight**: 30%
-   **Calculation**: (Required Completed / Required Total) × 70 + (Optional Completed / Optional Total) × 30

### Accuracy (0-100%)

For Analytics API data, accuracy is considered 100% as it comes directly from the authoritative source.

### Consistency (0-100%)

Measures internal consistency of data:

-   Stock value relationships (available + reserved ≈ total)
-   Price consistency (non-negative values)
-   Data type consistency

### Freshness (0-100%)

Measures how recent the data is:

-   **≤ 1 hour**: 100%
-   **≤ 6 hours**: 90%
-   **≤ 24 hours**: 80%
-   **≤ 72 hours**: 60%
-   **> 72 hours**: 30%

### Validity (0-100%)

Measures adherence to data format and business rules:

-   Required fields present
-   Correct data types
-   Valid value ranges
-   Business rule compliance

### Overall Score

Weighted average of all metrics:

-   **Completeness**: 25%
-   **Accuracy**: 30%
-   **Consistency**: 20%
-   **Freshness**: 15%
-   **Validity**: 10%

## Database Integration

### Required Tables

The validator creates these tables automatically if they don't exist:

```sql
-- Quality issues log
CREATE TABLE data_quality_log (
    id SERIAL PRIMARY KEY,
    validation_batch_id VARCHAR(255) NOT NULL,
    warehouse_name VARCHAR(255),
    product_id INTEGER,
    sku VARCHAR(255),
    issue_type VARCHAR(100) NOT NULL,
    issue_description TEXT,
    validation_status VARCHAR(50) NOT NULL,
    resolution_action VARCHAR(100),
    quality_score INTEGER CHECK (quality_score >= 0 AND quality_score <= 100),
    validated_at TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP,
    resolved_at TIMESTAMP WITH TIME ZONE,
    created_at TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP
);

-- Validation batches summary
CREATE TABLE validation_batches (
    id SERIAL PRIMARY KEY,
    validation_batch_id VARCHAR(255) UNIQUE NOT NULL,
    source_batch_id VARCHAR(255),
    total_records INTEGER DEFAULT 0,
    valid_records INTEGER DEFAULT 0,
    invalid_records INTEGER DEFAULT 0,
    warnings INTEGER DEFAULT 0,
    anomalies INTEGER DEFAULT 0,
    quality_score DECIMAL(5,2) DEFAULT 0,
    execution_time_ms INTEGER DEFAULT 0,
    status VARCHAR(50) DEFAULT 'completed',
    created_at TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP
);
```

### Validation Statistics

```php
// Get validation statistics for monitoring
$stats = $validator->getValidationStatistics(7); // Last 7 days

echo "Validation Statistics:\n";
echo "- Total batches: " . $stats['total_batches'] . "\n";
echo "- Average quality score: " . round($stats['avg_quality_score'], 2) . "%\n";
echo "- Total records validated: " . $stats['total_records_validated'] . "\n";
echo "- Validation rate: " . round(($stats['total_valid_records'] / $stats['total_records_validated']) * 100, 2) . "%\n";
```

## Configuration

### Validation Thresholds

| Constant                   | Default Value | Description                  |
| -------------------------- | ------------- | ---------------------------- |
| `QUALITY_SCORE_EXCELLENT`  | 100           | Excellent quality threshold  |
| `QUALITY_SCORE_GOOD`       | 90            | Good quality threshold       |
| `QUALITY_SCORE_ACCEPTABLE` | 80            | Acceptable quality threshold |
| `QUALITY_SCORE_POOR`       | 50            | Poor quality threshold       |
| `QUALITY_SCORE_CRITICAL`   | 0             | Critical quality threshold   |

### Anomaly Detection Thresholds

| Constant                    | Default Value | Description                       |
| --------------------------- | ------------- | --------------------------------- |
| `STOCK_ANOMALY_THRESHOLD`   | 1,000,000     | Unrealistic stock level threshold |
| `PRICE_ANOMALY_THRESHOLD`   | 1,000,000     | Unrealistic price threshold       |
| `DISCREPANCY_THRESHOLD`     | 0.10          | 10% discrepancy threshold         |
| `FRESHNESS_THRESHOLD_HOURS` | 24            | Data freshness threshold          |

### Validation Statuses

| Status          | Description               | Action Required     |
| --------------- | ------------------------- | ------------------- |
| `passed`        | Validation successful     | None                |
| `warning`       | Minor issues detected     | Review recommended  |
| `failed`        | Critical issues found     | Fix required        |
| `manual_review` | Requires human review     | Manual intervention |
| `resolved`      | Issues have been resolved | None                |

## API Reference

### DataValidator Class

#### Methods

##### `validateBatch(array $data, string $batchId): ValidationResult`

Validates an entire batch of records.

**Parameters:**

-   `$data`: Array of stock records from Analytics API
-   `$batchId`: Unique identifier for the batch

**Returns:** `ValidationResult` object with comprehensive validation results

##### `validateRecord(array $record, int $index, string $validationBatchId): RecordValidationResult`

Validates a single record.

**Parameters:**

-   `$record`: Individual stock record
-   `$index`: Record index in batch
-   `$validationBatchId`: Validation batch identifier

**Returns:** `RecordValidationResult` object with record-specific validation results

##### `detectAnomalies(array $data): array`

Detects anomalies in the dataset using statistical and business logic methods.

**Parameters:**

-   `$data`: Array of stock records

**Returns:** Array of detected anomalies with details

##### `calculateQualityMetrics(array $data): array`

Calculates comprehensive quality metrics for the dataset.

**Parameters:**

-   `$data`: Array of stock records

**Returns:** Array of quality metrics (completeness, accuracy, consistency, freshness, validity, overall_score)

##### `getValidationStatistics(int $days = 7): array`

Retrieves validation statistics for monitoring purposes.

**Parameters:**

-   `$days`: Number of days to look back (default: 7)

**Returns:** Array of validation statistics

### ValidationResult Class

#### Methods

##### `getValidationBatchId(): string`

Returns the validation batch identifier.

##### `getResults(): array`

Returns detailed validation results including counts and issues.

##### `getQualityScore(): float`

Returns the overall quality score (0-100).

##### `getExecutionTime(): int`

Returns validation execution time in milliseconds.

##### `getQualityMetrics(): array`

Returns quality metrics summary.

##### `isValid(): bool`

Returns true if all records are valid.

##### `hasWarnings(): bool`

Returns true if warnings were detected.

##### `hasAnomalies(): bool`

Returns true if anomalies were detected.

### RecordValidationResult Class

#### Methods

##### `getIndex(): int`

Returns the record index in the batch.

##### `getRecord(): array`

Returns the original record data.

##### `getStatus(): string`

Returns the validation status.

##### `getIssues(): array`

Returns array of validation issues.

##### `getAnomalies(): array`

Returns array of detected anomalies.

##### `getQualityIssues(): array`

Returns array of quality issues.

##### `isValid(): bool`

Returns true if the record is valid.

##### `hasAnomalies(): bool`

Returns true if anomalies were detected.

##### `hasQualityIssues(): bool`

Returns true if quality issues were detected.

## Monitoring and Alerting

### Quality Score Monitoring

```php
// Monitor quality scores and alert on degradation
$result = $validator->validateBatch($data, $batchId);

if ($result->getQualityScore() < 80) {
    // Send alert - quality degradation detected
    $alertMessage = "Data quality degradation detected: " . $result->getQualityScore() . "%";
    error_log($alertMessage);

    // Could integrate with alerting system
    // sendAlert('data_quality', $alertMessage);
}
```

### Anomaly Monitoring

```php
// Monitor for critical anomalies
$anomalies = $validator->detectAnomalies($data);
$criticalAnomalies = array_filter($anomalies, fn($a) => $a['severity'] === 'critical');

if (!empty($criticalAnomalies)) {
    foreach ($criticalAnomalies as $anomaly) {
        error_log("Critical anomaly detected: " . $anomaly['type'] . " for SKU " . $anomaly['sku']);
    }
}
```

### Database Monitoring Queries

```sql
-- Recent validation failures
SELECT validation_batch_id, COUNT(*) as failure_count
FROM data_quality_log
WHERE validation_status = 'failed'
AND created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)
GROUP BY validation_batch_id
ORDER BY failure_count DESC;

-- Quality score trends
SELECT
    DATE(created_at) as date,
    AVG(quality_score) as avg_quality_score,
    COUNT(*) as batch_count
FROM validation_batches
WHERE created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
GROUP BY DATE(created_at)
ORDER BY date DESC;

-- Most common validation issues
SELECT
    issue_type,
    COUNT(*) as occurrence_count,
    AVG(quality_score) as avg_quality_score
FROM data_quality_log
WHERE created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
GROUP BY issue_type
ORDER BY occurrence_count DESC;
```

## Best Practices

1. **Always validate API data** before processing in ETL pipelines
2. **Set quality thresholds** appropriate for your business requirements
3. **Monitor quality trends** to detect data source degradation
4. **Investigate anomalies** promptly to prevent data quality issues
5. **Log validation results** for audit trails and debugging
6. **Use batch validation** for efficiency with large datasets
7. **Implement alerting** for critical quality issues
8. **Regular cleanup** of validation logs to manage database size

## Troubleshooting

### Common Issues

1. **High Memory Usage**

    - Use batch processing for large datasets
    - Consider processing in smaller chunks
    - Monitor memory usage during validation

2. **Slow Validation Performance**

    - Check database indexes on validation tables
    - Consider reducing validation rule complexity
    - Use database connection pooling

3. **False Positive Anomalies**

    - Adjust anomaly detection thresholds
    - Review business logic rules
    - Consider seasonal data patterns

4. **Low Quality Scores**
    - Investigate data source issues
    - Review validation rules for appropriateness
    - Check data freshness and completeness

### Debug Mode

```php
// Enable detailed logging for debugging
error_reporting(E_ALL);
ini_set('display_errors', 1);

$validator = new DataValidator($pdo);
$result = $validator->validateBatch($data, $batchId);

// Examine detailed results
foreach ($result->getResults()['validation_details'] as $index => $recordResult) {
    if (!$recordResult->isValid()) {
        echo "Record {$index} issues:\n";
        foreach ($recordResult->getIssues() as $issue) {
            echo "  - {$issue['rule']}: {$issue['message']}\n";
        }
    }
}
```

## Related Documentation

-   [AnalyticsApiClient Documentation](AnalyticsApiClient.md)
-   [Analytics ETL Log Migration](../migrations/README_ANALYTICS_ETL_LOG_MIGRATION.md)
-   [Warehouse Normalization Migration](../migrations/README_WAREHOUSE_NORMALIZATION_MIGRATION.md)
