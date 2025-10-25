# AnalyticsETL Service Documentation

## Overview

AnalyticsETL is the main orchestrator service for the warehouse multi-source integration system. It implements a complete ETL (Extract, Transform, Load) process that:

1. **Extracts** data from the Analytics API using AnalyticsApiClient
2. **Transforms** data through validation (DataValidator) and normalization (WarehouseNormalizer)
3. **Loads** processed data into the PostgreSQL database with comprehensive audit logging

## Requirements Coverage

-   **Requirement 2.1**: ETL process for Analytics API integration
-   **Requirement 2.2**: Data validation and quality assurance
-   **Requirement 2.3**: Warehouse name normalization and mapping

## Architecture

```
┌─────────────────┐    ┌──────────────────┐    ┌─────────────────┐
│  Analytics API  │───▶│  AnalyticsETL    │───▶│  PostgreSQL DB  │
└─────────────────┘    │   (Orchestrator) │    └─────────────────┘
                       └──────────────────┘
                              │
                              ▼
                    ┌─────────────────────┐
                    │   Service Layer     │
                    │ ┌─────────────────┐ │
                    │ │ AnalyticsApiClient│ │
                    │ │ DataValidator   │ │
                    │ │ WarehouseNormalizer│ │
                    │ └─────────────────┘ │
                    └─────────────────────┘
```

## Class Structure

### AnalyticsETL

Main orchestrator class that coordinates the entire ETL process.

#### Constructor

```php
public function __construct(
    AnalyticsApiClient $apiClient,
    DataValidator $validator,
    WarehouseNormalizer $normalizer,
    ?PDO $pdo = null,
    array $config = []
)
```

**Parameters:**

-   `$apiClient`: Analytics API client for data extraction
-   `$validator`: Data validation service
-   `$normalizer`: Warehouse name normalization service
-   `$pdo`: Database connection (optional for validation-only mode)
-   `$config`: ETL configuration options

#### Main Methods

##### executeETL()

Executes the complete ETL process.

```php
public function executeETL(
    string $etlType = self::TYPE_INCREMENTAL_SYNC,
    array $options = []
): ETLResult
```

**Parameters:**

-   `$etlType`: Type of ETL process (see ETL Types section)
-   `$options`: ETL execution options

**Returns:** `ETLResult` object with execution details

##### getETLStatus()

Returns current ETL process status.

```php
public function getETLStatus(): array
```

**Returns:** Array with current status information

##### getETLStatistics()

Returns ETL execution statistics for specified period.

```php
public function getETLStatistics(int $days = 7): array
```

**Parameters:**

-   `$days`: Number of days to look back

**Returns:** Array with statistical data

## ETL Types

### TYPE_FULL_SYNC

Complete synchronization of all data from Analytics API.

-   Processes all available records
-   Suitable for initial data load or complete refresh
-   Higher resource usage and execution time

### TYPE_INCREMENTAL_SYNC

Synchronizes only changed data since last sync.

-   Default ETL type
-   Optimized for regular scheduled runs
-   Lower resource usage

### TYPE_MANUAL_SYNC

User-triggered synchronization with custom parameters.

-   Allows custom filters and options
-   Suitable for ad-hoc data updates
-   Full control over sync scope

### TYPE_VALIDATION_ONLY

Validates data without loading to database.

-   Useful for data quality assessment
-   No database modifications
-   Quick execution for quality checks

## Configuration Options

### Default Configuration

```php
[
    'load_batch_size' => 1000,           // Records per batch during load
    'max_memory_records' => 10000,       // Max records in memory
    'min_quality_score' => 80.0,         // Minimum acceptable quality score
    'enable_audit_logging' => true,      // Enable database audit logging
    'retry_failed_batches' => true,      // Retry failed operations
    'max_retries' => 3,                  // Maximum retry attempts
    'retry_delay' => 5                   // Delay between retries (seconds)
]
```

### Performance Tuning

#### Batch Size Optimization

-   **Small batches (100-500)**: Lower memory usage, more database round-trips
-   **Medium batches (1000-2000)**: Balanced performance and memory usage
-   **Large batches (5000+)**: Higher memory usage, fewer database operations

#### Memory Management

-   Monitor `max_memory_records` to prevent out-of-memory errors
-   Adjust based on available system RAM
-   Consider data record size when setting limits

#### Quality Thresholds

-   `min_quality_score`: Balance between data quality and processing speed
-   Higher thresholds = stricter quality requirements
-   Lower thresholds = more permissive processing

## ETL Process Phases

### 1. Extract Phase

**Purpose:** Retrieve data from Analytics API

**Process:**

1. Initialize API client with authentication
2. Fetch data using pagination for memory efficiency
3. Add extraction metadata to each record
4. Monitor extraction metrics and progress

**Key Features:**

-   Memory-efficient streaming using generators
-   Batch processing for large datasets
-   Comprehensive error handling and retry logic
-   Progress tracking and logging

### 2. Transform Phase

**Purpose:** Validate and normalize extracted data

**Process:**

1. **Data Validation:**

    - Quality score calculation
    - Anomaly detection
    - Business rule validation
    - Error and warning collection

2. **Warehouse Normalization:**
    - Fuzzy matching for warehouse names
    - Confidence scoring
    - Manual rule application
    - Learning from corrections

**Quality Metrics:**

-   Completeness: Percentage of non-null required fields
-   Accuracy: Validation rule compliance
-   Consistency: Cross-field validation results

### 3. Load Phase

**Purpose:** Save processed data to PostgreSQL database

**Process:**

1. Begin database transaction
2. Process data in configurable batches
3. Execute UPSERT operations (INSERT with ON CONFLICT UPDATE)
4. Track insertion/update statistics
5. Commit transaction or rollback on error

**Database Operations:**

-   Atomic transactions for data consistency
-   Conflict resolution using UPSERT patterns
-   Comprehensive audit trail logging
-   Performance optimization through batch processing

## ETL Result Object

### ETLResult Class

Encapsulates ETL execution results and metrics.

#### Properties

```php
class ETLResult {
    private string $batchId;           // Unique batch identifier
    private string $status;            // Execution status
    private array $metrics;            // Detailed metrics
    private int $executionTime;        // Total execution time (ms)
    private ?string $errorMessage;     // Error message if failed
}
```

#### Key Methods

```php
public function isSuccessful(): bool                    // Check if ETL succeeded
public function getTotalRecordsProcessed(): int         // Total records processed
public function getRecordsInserted(): int               // New records inserted
public function getRecordsUpdated(): int                // Existing records updated
public function getRecordsErrors(): int                 // Records with errors
public function getQualityScore(): float                // Overall data quality score
public function getExecutionTime(): int                 // Execution time in milliseconds
```

## Error Handling

### ETLException Class

Specialized exception for ETL-specific errors.

```php
class ETLException extends Exception {
    private string $phase;  // ETL phase where error occurred

    public function getPhase(): string  // Get error phase
}
```

### Error Recovery Strategies

1. **Retry Logic:**

    - Automatic retry for transient failures
    - Exponential backoff for rate limiting
    - Maximum retry limits to prevent infinite loops

2. **Partial Success Handling:**

    - Continue processing after non-critical errors
    - Track error counts and quality metrics
    - Provide detailed error reporting

3. **Transaction Management:**
    - Atomic operations for data consistency
    - Automatic rollback on critical failures
    - Checkpoint creation for large datasets

## Monitoring and Logging

### Audit Logging

ETL operations are logged to the `analytics_etl_log` table:

```sql
CREATE TABLE analytics_etl_log (
    id SERIAL PRIMARY KEY,
    batch_id VARCHAR(255) NOT NULL,
    etl_type VARCHAR(50) NOT NULL,
    started_at TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP,
    completed_at TIMESTAMP WITH TIME ZONE,
    status VARCHAR(20) NOT NULL,
    records_processed INTEGER DEFAULT 0,
    execution_time_ms INTEGER,
    data_source VARCHAR(50) DEFAULT 'analytics_api',
    error_message TEXT
);
```

### Metrics Collection

The ETL process collects comprehensive metrics:

-   **Extract Metrics:** Records extracted, batches processed, API performance
-   **Transform Metrics:** Validation scores, normalization confidence, error counts
-   **Load Metrics:** Records inserted/updated, database performance, error rates
-   **Overall Metrics:** Total execution time, success rates, throughput

### Performance Monitoring

Key performance indicators to monitor:

1. **Throughput:** Records processed per minute
2. **Latency:** Average execution time per batch
3. **Error Rate:** Percentage of failed operations
4. **Quality Score:** Average data quality metrics
5. **Resource Usage:** Memory and CPU utilization

## Usage Examples

### Basic ETL Execution

```php
// Initialize services
$apiClient = new AnalyticsApiClient($apiConfig);
$validator = new DataValidator($validationConfig);
$normalizer = new WarehouseNormalizer($pdo, $normalizerConfig);

// Create ETL orchestrator
$etl = new AnalyticsETL($apiClient, $validator, $normalizer, $pdo);

// Execute incremental sync
$result = $etl->executeETL(AnalyticsETL::TYPE_INCREMENTAL_SYNC);

if ($result->isSuccessful()) {
    echo "ETL completed: {$result->getTotalRecordsProcessed()} records processed\n";
} else {
    echo "ETL failed: {$result->getErrorMessage()}\n";
}
```

### Custom ETL with Filters

```php
$options = [
    'filters' => [
        'warehouse_names' => ['Москва РФЦ', 'СПб МРФЦ'],
        'date_from' => '2024-01-01',
        'categories' => ['electronics']
    ]
];

$result = $etl->executeETL(AnalyticsETL::TYPE_MANUAL_SYNC, $options);
```

### Status Monitoring

```php
// Get current status
$status = $etl->getETLStatus();
echo "Current status: {$status['status']}\n";

// Get statistics
$stats = $etl->getETLStatistics(30); // Last 30 days
echo "Success rate: " . ($stats['successful_runs'] / $stats['total_runs'] * 100) . "%\n";
```

## Best Practices

### 1. Configuration Management

-   Use environment-specific configurations
-   Monitor and adjust batch sizes based on performance
-   Set appropriate quality thresholds for your use case

### 2. Error Handling

-   Implement comprehensive logging for troubleshooting
-   Set up alerts for critical ETL failures
-   Plan for data recovery scenarios

### 3. Performance Optimization

-   Schedule ETL runs during low-traffic periods
-   Monitor database performance during large loads
-   Use incremental sync for regular updates

### 4. Data Quality

-   Regularly review validation rules and thresholds
-   Monitor data quality trends over time
-   Implement data quality alerts and notifications

### 5. Monitoring and Maintenance

-   Set up automated ETL scheduling
-   Monitor execution metrics and trends
-   Regularly review and update normalization rules

## Troubleshooting

### Common Issues

1. **Memory Errors:**

    - Reduce `max_memory_records` setting
    - Increase available system memory
    - Process data in smaller batches

2. **Database Timeouts:**

    - Reduce `load_batch_size`
    - Optimize database indexes
    - Check database connection pool settings

3. **API Rate Limiting:**

    - Adjust API client rate limiting settings
    - Implement exponential backoff
    - Contact API provider for rate limit increases

4. **Data Quality Issues:**
    - Review validation rules and thresholds
    - Check source data quality
    - Update normalization rules

### Debugging Steps

1. Check ETL logs for error details
2. Review metrics for performance bottlenecks
3. Validate individual service components
4. Test with smaller data samples
5. Monitor system resources during execution

## Integration Points

### Database Schema

-   Requires `inventory` table with Analytics API columns
-   Uses `analytics_etl_log` table for audit logging
-   Integrates with `warehouse_normalization` table

### External Services

-   Analytics API for data extraction
-   PostgreSQL database for data storage
-   Optional monitoring and alerting systems

### Dependencies

-   AnalyticsApiClient for API communication
-   DataValidator for data quality assurance
-   WarehouseNormalizer for name standardization
-   PDO for database operations
