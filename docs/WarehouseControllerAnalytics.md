# WarehouseController Analytics API Integration

## Overview

The WarehouseController has been enhanced to support Analytics API data integration, providing comprehensive filtering, sorting, and reporting capabilities for multi-source warehouse data. This enhancement maintains full backward compatibility while adding powerful new features for data quality monitoring and freshness tracking.

## Requirements Coverage

-   **Requirement 9.1**: Enhanced dashboard endpoint with Analytics API support
-   **Requirement 9.2**: Data source and quality filtering capabilities
-   **Requirement 9.4**: Freshness metrics and monitoring
-   **Requirement 17.3**: Comprehensive Analytics API data integration

## Enhanced Features

### 1. Data Source Filtering

Filter inventory data by its source:

-   `analytics_api`: Data from Analytics API integration
-   `manual`: Manually entered data
-   `import`: Data from file imports
-   `all`: All data sources (default)

### 2. Quality Score Filtering

Filter by data quality score (0-100):

-   Minimum quality threshold filtering
-   Quality-based sorting and prioritization
-   Quality metrics in response summaries

### 3. Freshness Filtering

Filter by data recency:

-   Hours since last Analytics sync
-   Freshness status indicators
-   Stale data identification

### 4. Enhanced Response Format

Extended response structure includes:

-   Analytics API metadata
-   Data quality metrics
-   Freshness indicators
-   Source attribution
-   Normalization information

## API Endpoints

### Enhanced Dashboard Endpoint

**Endpoint:** `GET /api/warehouse/dashboard`

**New Query Parameters:**

| Parameter         | Type    | Required | Default | Description                                                        |
| ----------------- | ------- | -------- | ------- | ------------------------------------------------------------------ |
| `data_source`     | string  | No       | -       | Filter by data source (`analytics_api`, `manual`, `import`, `all`) |
| `quality_score`   | integer | No       | -       | Minimum quality score (0-100)                                      |
| `freshness_hours` | integer | No       | -       | Maximum hours since last sync                                      |

**Enhanced Sort Fields:**

-   `data_quality_score`: Sort by quality score
-   `last_analytics_sync`: Sort by sync timestamp
-   `data_source`: Sort by data source

**Example Requests:**

```bash
# Get high-quality Analytics API data
GET /api/warehouse/dashboard?data_source=analytics_api&quality_score=90

# Get fresh data (last 6 hours)
GET /api/warehouse/dashboard?freshness_hours=6&sort_by=data_quality_score&sort_order=desc

# Combined filters
GET /api/warehouse/dashboard?data_source=analytics_api&quality_score=80&freshness_hours=24&warehouse=Москва%20РФЦ
```

## Enhanced Response Structure

### Warehouse Items

Each warehouse item now includes Analytics API fields:

```json
{
    "sku": "PROD-001",
    "name": "Test Product",
    "warehouse_name": "Москва РФЦ",
    "available": 150,
    "daily_sales_avg": 5.2,
    "replenishment_need": 200,

    // Analytics API fields (NEW)
    "data_source": "analytics_api",
    "data_quality_score": 95,
    "last_analytics_sync": "2024-01-15 10:30:00",
    "normalized_warehouse_name": "Москва РФЦ",
    "original_warehouse_name": "Moscow RFC",
    "sync_batch_id": "etl_20240115_103000_abc123",
    "hours_since_sync": 2,
    "freshness_status": "fresh"
}
```

### Enhanced Summary Metrics

The summary section includes new Analytics API metrics:

```json
{
    "summary": {
        "total_products": 1500,
        "active_products": 1200,
        "total_replenishment_need": 50000,
        "by_liquidity": {
            "critical": 50,
            "low": 200,
            "normal": 800,
            "excess": 150
        },

        // Analytics API metrics (NEW)
        "data_quality": {
            "avg_quality_score": 87.5
        },
        "by_data_source": {
            "analytics_api": 1200,
            "manual": 250,
            "import": 50
        },
        "freshness": {
            "fresh_count": 1100,
            "stale_count": 100,
            "fresh_percentage": 91.7
        }
    }
}
```

### Enhanced Filters Applied

The `filters_applied` section includes new Analytics filters:

```json
{
    "filters_applied": {
        "warehouse": null,
        "cluster": null,
        "liquidity_status": null,
        "active_only": true,
        "has_replenishment_need": null,

        // Analytics API filters (NEW)
        "data_source": "analytics_api",
        "quality_score": 80,
        "freshness_hours": 24,

        "sort_by": "data_quality_score",
        "sort_order": "desc"
    }
}
```

## Freshness Status Indicators

The system calculates freshness status based on hours since last Analytics sync:

| Status       | Hours Since Sync | Description             |
| ------------ | ---------------- | ----------------------- |
| `fresh`      | ≤ 6 hours        | Recently synchronized   |
| `acceptable` | 6-24 hours       | Reasonably current      |
| `stale`      | 24-72 hours      | Needs attention         |
| `very_stale` | > 72 hours       | Requires immediate sync |
| `unknown`    | No sync data     | Manual or imported data |

## Data Quality Scoring

Quality scores are calculated based on:

-   **Completeness**: Percentage of required fields populated
-   **Accuracy**: Validation rule compliance
-   **Consistency**: Cross-field validation results
-   **Freshness**: Recency of data updates

Quality score ranges:

-   **90-100**: Excellent quality
-   **80-89**: Good quality
-   **70-79**: Acceptable quality
-   **60-69**: Poor quality
-   **< 60**: Very poor quality

## Enhanced Warehouse List

The warehouse list endpoint now includes Analytics API metrics:

**Endpoint:** `GET /api/warehouse/warehouses`

**Enhanced Response:**

```json
{
    "success": true,
    "data": [
        {
            "warehouse_name": "Москва РФЦ",
            "cluster": "Центральный",
            "product_count": 1500,

            // Analytics API metrics (NEW)
            "avg_quality_score": 92.3,
            "analytics_api_count": 1200,
            "last_analytics_sync": "2024-01-15 10:30:00",
            "fresh_count": 1100
        }
    ]
}
```

## Enhanced CSV Export

The CSV export now includes Analytics API columns:

**New Columns:**

-   Источник данных (Data Source)
-   Оценка качества (Quality Score)
-   Последняя синхронизация (Last Sync)
-   Часов с синхронизации (Hours Since Sync)
-   Статус свежести (Freshness Status)

**Example Export:**

```csv
Товар,SKU,Склад,Источник данных,Оценка качества,Статус свежести
Test Product,PROD-001,Москва РФЦ,analytics_api,95,fresh
Another Product,PROD-002,СПб МРФЦ,manual,100,unknown
```

## Backward Compatibility

All existing functionality remains unchanged:

-   Original query parameters work as before
-   Response structure is extended, not modified
-   Existing sort fields continue to work
-   Default behavior unchanged when new parameters not specified

## Usage Examples

### JavaScript Integration

```javascript
// Get Analytics API data with quality filtering
async function getHighQualityAnalyticsData() {
    const params = new URLSearchParams({
        data_source: "analytics_api",
        quality_score: "85",
        freshness_hours: "24",
        sort_by: "data_quality_score",
        sort_order: "desc",
        limit: "100",
    });

    const response = await fetch(`/api/warehouse/dashboard?${params}`);
    const data = await response.json();

    if (data.success) {
        console.log(
            "Analytics data quality:",
            data.data.summary.data_quality.avg_quality_score
        );
        console.log(
            "Fresh data percentage:",
            data.data.summary.freshness.fresh_percentage
        );

        // Process warehouse items
        data.data.warehouses.forEach((warehouse) => {
            warehouse.items.forEach((item) => {
                console.log(
                    `${item.name}: Quality ${item.data_quality_score}%, ${item.freshness_status}`
                );
            });
        });
    }
}

// Monitor data freshness
function checkDataFreshness() {
    fetch("/api/warehouse/dashboard?freshness_hours=6")
        .then((response) => response.json())
        .then((data) => {
            const freshness = data.data.summary.freshness;
            const freshPercentage = freshness.fresh_percentage;

            if (freshPercentage < 80) {
                console.warn(`Data freshness low: ${freshPercentage}%`);
                // Trigger refresh or alert
            }
        });
}

// Filter by data source
function getDataBySource(source) {
    return fetch(`/api/warehouse/dashboard?data_source=${source}&limit=50`)
        .then((response) => response.json())
        .then((data) => {
            if (data.success) {
                return {
                    count: data.data.summary.by_data_source[source] || 0,
                    items: data.data.warehouses.flatMap((w) => w.items),
                };
            }
            throw new Error("Failed to fetch data");
        });
}
```

### PHP Integration

```php
// Get Analytics API data with filters
function getAnalyticsData($filters = []) {
    $controller = new WarehouseController($pdo);

    // Set up filters
    $_GET = array_merge([
        'data_source' => 'analytics_api',
        'quality_score' => '80',
        'sort_by' => 'data_quality_score',
        'sort_order' => 'desc'
    ], $filters);

    ob_start();
    $controller->getDashboard();
    $output = ob_get_clean();

    return json_decode($output, true);
}

// Monitor data quality
function checkDataQuality($minQuality = 80) {
    $data = getAnalyticsData(['quality_score' => $minQuality]);

    if ($data['success']) {
        $summary = $data['data']['summary'];
        $avgQuality = $summary['data_quality']['avg_quality_score'];

        if ($avgQuality < $minQuality) {
            // Alert or log quality issue
            error_log("Data quality below threshold: {$avgQuality}%");
        }

        return [
            'avg_quality' => $avgQuality,
            'total_products' => $summary['total_products'],
            'analytics_count' => $summary['by_data_source']['analytics_api']
        ];
    }

    return null;
}

// Export Analytics data
function exportAnalyticsData($filters = []) {
    $controller = new WarehouseController($pdo);

    $_GET = array_merge([
        'data_source' => 'analytics_api',
        'quality_score' => '70'
    ], $filters);

    ob_start();
    $controller->export();
    $csvContent = ob_get_clean();

    return $csvContent;
}
```

## Error Handling

### Validation Errors

The enhanced controller provides detailed validation error messages:

```json
{
    "success": false,
    "error": "Invalid data_source. Must be one of: analytics_api, manual, import, all"
}
```

```json
{
    "success": false,
    "error": "Invalid quality_score. Must be an integer between 0 and 100"
}
```

```json
{
    "success": false,
    "error": "Invalid freshness_hours. Must be a non-negative integer"
}
```

### Common Error Scenarios

1. **Invalid Data Source**

    - Error: `Invalid data_source`
    - Solution: Use one of: `analytics_api`, `manual`, `import`, `all`

2. **Quality Score Out of Range**

    - Error: `Invalid quality_score`
    - Solution: Use integer between 0 and 100

3. **Negative Freshness Hours**

    - Error: `Invalid freshness_hours`
    - Solution: Use non-negative integer

4. **Invalid Sort Field**
    - Error: `Invalid sort_by`
    - Solution: Use valid sort field including new Analytics fields

## Performance Considerations

### Database Optimization

The enhanced queries include additional WHERE clauses and JOINs:

```sql
-- Example optimized query with Analytics filters
SELECT i.*,
       COALESCE(i.data_quality_score, 100) as data_quality_score,
       TIMESTAMPDIFF(HOUR, i.last_analytics_sync, NOW()) as hours_since_sync
FROM inventory i
WHERE i.data_source = 'analytics_api'
  AND COALESCE(i.data_quality_score, 0) >= 80
  AND COALESCE(i.last_analytics_sync, i.updated_at) >= NOW() - INTERVAL 24 HOUR
ORDER BY COALESCE(i.data_quality_score, 0) DESC;
```

**Recommended Indexes:**

```sql
-- Analytics API performance indexes
CREATE INDEX idx_inventory_data_source ON inventory(data_source);
CREATE INDEX idx_inventory_quality_score ON inventory(data_quality_score);
CREATE INDEX idx_inventory_analytics_sync ON inventory(last_analytics_sync);
CREATE INDEX idx_inventory_analytics_composite ON inventory(data_source, data_quality_score, last_analytics_sync);
```

### Caching Strategy

Consider caching for frequently accessed Analytics data:

```php
// Example caching implementation
class CachedWarehouseController extends WarehouseController {
    private $cache;
    private $cacheTimeout = 300; // 5 minutes

    public function getDashboard() {
        $cacheKey = 'dashboard_' . md5(serialize($_GET));

        if ($cached = $this->cache->get($cacheKey)) {
            echo $cached;
            return;
        }

        ob_start();
        parent::getDashboard();
        $output = ob_get_clean();

        $this->cache->set($cacheKey, $output, $this->cacheTimeout);
        echo $output;
    }
}
```

## Monitoring and Alerting

### Data Quality Monitoring

```javascript
// Monitor data quality trends
function monitorDataQuality() {
    fetch("/api/warehouse/dashboard?data_source=analytics_api")
        .then((response) => response.json())
        .then((data) => {
            const quality = data.data.summary.data_quality.avg_quality_score;
            const freshness = data.data.summary.freshness.fresh_percentage;

            // Send metrics to monitoring system
            sendMetric("warehouse.data_quality.avg_score", quality);
            sendMetric("warehouse.data_freshness.percentage", freshness);

            // Alert on thresholds
            if (quality < 80) {
                sendAlert("Data quality below 80%", { quality });
            }

            if (freshness < 90) {
                sendAlert("Data freshness below 90%", { freshness });
            }
        });
}
```

### Freshness Alerting

```php
// Check for stale data
function checkStaleData() {
    $controller = new WarehouseController($pdo);

    $_GET = ['freshness_hours' => '72']; // 3 days

    ob_start();
    $controller->getDashboard();
    $output = ob_get_clean();

    $data = json_decode($output, true);

    if ($data['success']) {
        $staleCount = $data['data']['summary']['freshness']['stale_count'];

        if ($staleCount > 0) {
            // Send alert
            $message = "Found {$staleCount} stale inventory records";
            sendSlackAlert($message);
        }
    }
}
```

## Migration Guide

### Updating Existing Code

1. **No Breaking Changes**: Existing API calls continue to work unchanged
2. **Optional Enhancements**: Add new parameters as needed
3. **Response Extensions**: New fields are added, existing fields unchanged

### Example Migration

**Before (existing code):**

```javascript
fetch("/api/warehouse/dashboard?warehouse=Москва%20РФЦ&limit=50");
```

**After (enhanced with Analytics filters):**

```javascript
fetch(
    "/api/warehouse/dashboard?warehouse=Москва%20РФЦ&limit=50&data_source=analytics_api&quality_score=80"
);
```

### Testing Compatibility

```php
// Test backward compatibility
function testBackwardCompatibility() {
    $controller = new WarehouseController($pdo);

    // Test original parameters
    $_GET = [
        'warehouse' => 'Test Warehouse',
        'liquidity_status' => 'critical',
        'sort_by' => 'replenishment_need',
        'limit' => '50'
    ];

    ob_start();
    $controller->getDashboard();
    $output = ob_get_clean();

    $data = json_decode($output, true);

    // Verify original functionality works
    assert($data['success'] === true);
    assert(isset($data['data']['warehouses']));
    assert(isset($data['data']['summary']));

    // Verify new fields are present
    assert(isset($data['data']['summary']['by_data_source']));
    assert(isset($data['data']['summary']['data_quality']));
    assert(isset($data['data']['summary']['freshness']));
}
```

## Best Practices

### 1. Filter Optimization

-   Use specific data source filters to reduce query scope
-   Combine quality and freshness filters for targeted results
-   Implement client-side caching for frequently accessed data

### 2. Quality Monitoring

-   Set up automated quality score monitoring
-   Alert on quality degradation trends
-   Regular quality audits and reporting

### 3. Freshness Management

-   Monitor sync frequency and success rates
-   Set up alerts for stale data detection
-   Implement automatic refresh triggers

### 4. Performance Optimization

-   Use appropriate database indexes
-   Implement response caching
-   Monitor query performance with Analytics filters

### 5. Error Handling

-   Validate all Analytics API parameters
-   Provide clear error messages
-   Implement graceful degradation for missing data
