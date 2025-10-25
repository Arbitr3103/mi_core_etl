# AnalyticsApiClient Documentation

## Overview

The `AnalyticsApiClient` is a specialized service for interacting with the Ozon Analytics API endpoint `/v2/analytics/stock_on_warehouses`. It provides comprehensive functionality for fetching warehouse stock data with built-in pagination, retry logic with exponential backoff, rate limiting, and caching.

## Features

### Core Functionality

-   **Pagination Support**: Fetch up to 1000 records per request with automatic pagination
-   **Retry Logic**: Exponential backoff retry mechanism for handling transient failures
-   **Rate Limiting**: Built-in rate limiting to respect API quotas (30 requests/minute)
-   **Caching**: Two-tier caching system (memory + database) with configurable TTL
-   **Error Handling**: Comprehensive error handling with specific error types
-   **ETL Integration**: Built-in logging for ETL processes and batch tracking

### Requirements Fulfilled

-   **Requirement 1.1**: ✅ Integration with Ozon Analytics API
-   **Requirement 15.1**: ✅ Pagination with 1000 records per request
-   **Requirement 16.5**: ✅ Retry logic with exponential backoff and rate limiting

## Installation

```php
require_once 'src/Services/AnalyticsApiClient.php';
```

## Basic Usage

### Initialization

```php
use AnalyticsApiClient;

// Initialize with credentials and database connection
$client = new AnalyticsApiClient(
    'your_client_id',
    'your_api_key',
    $pdoConnection // Optional for caching and logging
);
```

### Simple Stock Data Fetch

```php
try {
    // Fetch first 100 records
    $stockData = $client->getStockOnWarehouses(0, 100);

    echo "Fetched " . count($stockData['data']) . " records\n";
    echo "Total available: " . $stockData['total_count'] . "\n";

    foreach ($stockData['data'] as $record) {
        echo "SKU: {$record['sku']}, Warehouse: {$record['warehouse_name']}, Stock: {$record['available_stock']}\n";
    }

} catch (AnalyticsApiException $e) {
    echo "API Error: " . $e->getMessage() . "\n";
    echo "Error Type: " . $e->getErrorType() . "\n";
}
```

### Fetch All Data with Pagination

```php
// Use generator for memory-efficient processing
foreach ($client->getAllStockData() as $batch) {
    echo "Processing batch: offset {$batch['offset']}, size {$batch['batch_size']}\n";

    foreach ($batch['data'] as $record) {
        // Process each record
        processStockRecord($record);
    }

    // Check if there's more data
    if (!$batch['has_more']) {
        break;
    }
}
```

## Advanced Usage

### Using Filters

```php
$filters = [
    'date_from' => '2025-01-01',
    'date_to' => '2025-01-31',
    'warehouse_names' => ['РФЦ Москва', 'РФЦ Санкт-Петербург'],
    'sku_list' => ['SKU001', 'SKU002', 'SKU003']
];

$filteredData = $client->getStockOnWarehouses(0, 500, $filters);
```

### Error Handling

```php
try {
    $data = $client->getStockOnWarehouses(0, 100);
} catch (AnalyticsApiException $e) {
    switch ($e->getErrorType()) {
        case 'AUTHENTICATION_ERROR':
            // Handle authentication issues
            echo "Check your credentials\n";
            break;

        case 'RATE_LIMIT_ERROR':
            // Handle rate limiting
            echo "Too many requests, waiting...\n";
            sleep(60);
            break;

        case 'NETWORK_ERROR':
            // Handle network issues
            echo "Network problem, retrying later...\n";
            break;

        case 'MAX_RETRIES_EXCEEDED':
            // All retries failed
            echo "Service unavailable after retries\n";
            break;
    }
}
```

### Cache Management

```php
// Get client statistics
$stats = $client->getStats();
echo "Cache entries: " . $stats['cache_entries'] . "\n";
echo "Request history: " . $stats['request_history_count'] . "\n";

// Clear expired cache entries
$cleared = $client->clearExpiredCache();
echo "Cleared {$cleared} expired cache entries\n";

// Test connection
$test = $client->testConnection();
if ($test['success']) {
    echo "Connection OK, response time: {$test['response_time']}ms\n";
} else {
    echo "Connection failed: {$test['message']}\n";
}
```

## Configuration

### Constants

| Constant                         | Default Value | Description                              |
| -------------------------------- | ------------- | ---------------------------------------- |
| `DEFAULT_LIMIT`                  | 1000          | Default number of records per request    |
| `MAX_LIMIT`                      | 1000          | Maximum allowed limit per request        |
| `MAX_RETRIES`                    | 3             | Maximum number of retry attempts         |
| `INITIAL_RETRY_DELAY`            | 1             | Initial delay between retries (seconds)  |
| `BACKOFF_MULTIPLIER`             | 2             | Exponential backoff multiplier           |
| `MAX_RETRY_DELAY`                | 30            | Maximum delay between retries (seconds)  |
| `RATE_LIMIT_REQUESTS_PER_MINUTE` | 30            | Maximum requests per minute              |
| `RATE_LIMIT_DELAY`               | 2             | Minimum delay between requests (seconds) |
| `CACHE_TTL`                      | 7200          | Cache time-to-live (2 hours)             |

### Retry Logic

The client implements exponential backoff with the following formula:

```
delay = min(INITIAL_RETRY_DELAY * (BACKOFF_MULTIPLIER ^ attempt), MAX_RETRY_DELAY)
```

Example retry delays:

-   Attempt 1: 1 second
-   Attempt 2: 2 seconds
-   Attempt 3: 4 seconds

### Rate Limiting

-   Maximum 30 requests per minute
-   Minimum 2 seconds between requests
-   Automatic throttling when limits are approached

## Data Structure

### Stock Record Format

```php
[
    'sku' => 'PRODUCT_SKU_123',
    'warehouse_name' => 'РФЦ Москва',
    'available_stock' => 150,
    'reserved_stock' => 25,
    'total_stock' => 175,
    'product_name' => 'Product Name',
    'category' => 'Electronics',
    'brand' => 'Brand Name',
    'price' => 1999.99,
    'currency' => 'RUB',
    'updated_at' => '2025-01-15 10:30:00',
    'data_source' => 'api',
    'data_quality_score' => 100
]
```

### Response Format

```php
[
    'data' => [...], // Array of stock records
    'total_count' => 1500,
    'has_more' => true,
    'processed_at' => '2025-01-15 10:30:00',
    'source' => 'analytics_api'
]
```

## Error Types

| Error Type             | Description                | Retry | Critical |
| ---------------------- | -------------------------- | ----- | -------- |
| `AUTHENTICATION_ERROR` | Invalid credentials        | No    | Yes      |
| `RATE_LIMIT_ERROR`     | API rate limit exceeded    | Yes   | No       |
| `VALIDATION_ERROR`     | Invalid request parameters | No    | No       |
| `NOT_FOUND_ERROR`      | Endpoint not found         | No    | No       |
| `SERVER_ERROR`         | Server-side error          | Yes   | No       |
| `NETWORK_ERROR`        | Network connectivity issue | Yes   | Yes      |
| `MAX_RETRIES_EXCEEDED` | All retry attempts failed  | No    | Yes      |
| `INVALID_RESPONSE`     | Malformed API response     | No    | No       |

## Database Integration

### Required Tables

The client requires these database tables for full functionality:

```sql
-- Cache table
CREATE TABLE analytics_api_cache (
    cache_key VARCHAR(255) PRIMARY KEY,
    data LONGTEXT NOT NULL,
    expires_at TIMESTAMP NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_expires_at (expires_at)
);

-- ETL logging table (from migration 005)
CREATE TABLE analytics_etl_log (
    id SERIAL PRIMARY KEY,
    batch_id UUID NOT NULL,
    etl_type VARCHAR(50) NOT NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'running',
    records_processed INTEGER DEFAULT 0,
    api_requests_made INTEGER DEFAULT 0,
    execution_time_ms INTEGER,
    data_source VARCHAR(50) DEFAULT 'analytics_api',
    error_message TEXT,
    started_at TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP,
    completed_at TIMESTAMP WITH TIME ZONE
);
```

### ETL Integration Example

```php
// Start ETL process
$batchId = 'etl_' . date('Ymd_His') . '_' . substr(md5(uniqid()), 0, 8);
$startTime = microtime(true);

// Log ETL start
$pdo->prepare("
    INSERT INTO analytics_etl_log (batch_id, etl_type, status, data_source)
    VALUES (?, 'api_sync', 'running', 'analytics_api')
")->execute([$batchId]);

$totalRecords = 0;
foreach ($client->getAllStockData() as $batch) {
    $totalRecords += $batch['batch_size'];
    // Process batch...
}

// Log ETL completion
$executionTime = round((microtime(true) - $startTime) * 1000);
$pdo->prepare("
    UPDATE analytics_etl_log
    SET status = 'completed', completed_at = NOW(),
        records_processed = ?, execution_time_ms = ?
    WHERE batch_id = ?
")->execute([$totalRecords, $executionTime, $batchId]);
```

## Performance Considerations

### Memory Usage

-   Use `getAllStockData()` generator for large datasets to avoid memory issues
-   Cache is limited to prevent excessive memory usage
-   Database cache provides persistent storage for large responses

### Network Optimization

-   Built-in rate limiting prevents API quota exhaustion
-   Exponential backoff reduces server load during issues
-   Caching reduces redundant API calls

### Database Performance

-   Cache table has indexes on expiration time
-   ETL log table supports efficient querying by batch_id and status
-   Automatic cleanup of expired cache entries

## Testing

### Unit Tests

```bash
# Run unit tests
phpunit tests/Unit/AnalyticsApiClientTest.php
```

### Integration Testing

```php
// Test with real API (requires valid credentials)
$client = new AnalyticsApiClient($realClientId, $realApiKey, $pdo);
$result = $client->testConnection();

if ($result['success']) {
    echo "Integration test passed\n";
} else {
    echo "Integration test failed: " . $result['message'] . "\n";
}
```

## Monitoring and Debugging

### Enable Debug Logging

```php
// Log all requests for debugging
error_reporting(E_ALL);
ini_set('display_errors', 1);

// The client automatically logs to error_log for retry attempts
```

### Monitor API Usage

```php
$stats = $client->getStats();
echo "Requests in last minute: " . $stats['request_history_count'] . "\n";
echo "Cache hit ratio: " . ($stats['cache_entries'] > 0 ? 'Good' : 'Low') . "\n";
```

### ETL Monitoring Queries

```sql
-- Recent ETL processes
SELECT batch_id, status, records_processed, execution_time_ms, created_at
FROM analytics_etl_log
WHERE data_source = 'analytics_api'
ORDER BY created_at DESC
LIMIT 10;

-- Failed ETL processes
SELECT batch_id, error_message, created_at
FROM analytics_etl_log
WHERE status = 'failed' AND data_source = 'analytics_api'
ORDER BY created_at DESC;

-- ETL performance metrics
SELECT
    AVG(execution_time_ms) as avg_execution_time,
    AVG(records_processed) as avg_records_processed,
    COUNT(*) as total_runs,
    SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as successful_runs
FROM analytics_etl_log
WHERE data_source = 'analytics_api'
AND created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR);
```

## Troubleshooting

### Common Issues

1. **Authentication Errors**

    - Verify Client-Id and Api-Key are correct
    - Check if credentials have necessary permissions
    - Ensure credentials are not expired

2. **Rate Limiting**

    - Reduce request frequency
    - Implement longer delays between requests
    - Use caching to reduce API calls

3. **Network Timeouts**

    - Check network connectivity
    - Increase timeout values if needed
    - Verify firewall settings

4. **Cache Issues**
    - Ensure database connection is working
    - Check cache table exists and is accessible
    - Clear expired cache entries regularly

### Debug Mode

```php
// Enable verbose error reporting
$client = new AnalyticsApiClient($clientId, $apiKey, $pdo);

try {
    $data = $client->getStockOnWarehouses(0, 10);
} catch (AnalyticsApiException $e) {
    echo "Error Details:\n";
    echo "- Type: " . $e->getErrorType() . "\n";
    echo "- Code: " . $e->getCode() . "\n";
    echo "- Message: " . $e->getMessage() . "\n";
    echo "- Critical: " . ($e->isCritical() ? 'Yes' : 'No') . "\n";
    echo "- Trace: " . $e->getTraceAsString() . "\n";
}
```

## Best Practices

1. **Always use try-catch blocks** when making API calls
2. **Implement proper error handling** for different error types
3. **Use generators** for processing large datasets
4. **Monitor cache performance** and clear expired entries regularly
5. **Log ETL processes** for monitoring and debugging
6. **Test connection** before starting large operations
7. **Respect rate limits** to avoid API quota issues
8. **Use filters** to reduce unnecessary data transfer

## Related Documentation

-   [Analytics ETL Log Migration](../migrations/README_ANALYTICS_ETL_LOG_MIGRATION.md)
-   [Warehouse Normalization Migration](../migrations/README_WAREHOUSE_NORMALIZATION_MIGRATION.md)
-   [Analytics API Integration Migration](../migrations/README_ANALYTICS_API_INTEGRATION_MIGRATION.md)
