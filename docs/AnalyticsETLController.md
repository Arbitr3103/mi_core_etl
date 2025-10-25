# AnalyticsETLController API Documentation

## Overview

AnalyticsETLController provides REST API endpoints for managing and monitoring Analytics ETL processes. It serves as the HTTP interface for the warehouse multi-source integration system, allowing external applications and users to interact with ETL operations.

## Requirements Coverage

-   **Requirement 8.1**: Analytics status monitoring endpoint
-   **Requirement 8.2**: ETL process trigger endpoint
-   **Requirement 8.3**: Data quality metrics endpoint
-   **Requirement 8.4**: ETL history and audit endpoint

## Base URL

```
http://your-domain.com/api/warehouse/
```

## Authentication

Currently, the API does not implement authentication. In production environments, you should add appropriate authentication and authorization mechanisms.

## Response Format

All API responses follow a consistent JSON structure:

### Success Response

```json
{
    "status": "success",
    "data": {
        // Response data
    },
    "timestamp": "2024-01-15 10:30:00"
}
```

### Error Response

```json
{
    "status": "error",
    "message": "Error description",
    "code": 400,
    "timestamp": "2024-01-15 10:30:00"
}
```

## API Endpoints

### 1. Get Analytics Status

**Endpoint:** `GET /api/warehouse/analytics-status`

**Description:** Retrieves the current status of Analytics ETL processes and system information.

**Parameters:** None

**Response:**

```json
{
    "status": "success",
    "data": {
        "etl_status": {
            "current_batch_id": "etl_incremental_sync_20240115_103000_abc123",
            "status": "completed",
            "started_at": "2024-01-15 10:30:00",
            "completed_at": "2024-01-15 10:35:00",
            "execution_time_ms": 300000,
            "metrics": {
                "extract": {
                    "status": "completed",
                    "records_extracted": 1500,
                    "batches_processed": 2
                },
                "transform": {
                    "status": "completed",
                    "records_transformed": 1500,
                    "validation_quality_score": 92.5
                },
                "load": {
                    "status": "completed",
                    "records_inserted": 800,
                    "records_updated": 700,
                    "records_errors": 0
                }
            }
        },
        "system_info": {
            "php_version": "8.1.0",
            "memory_usage": {
                "current": 52428800,
                "peak": 67108864,
                "limit": "256M"
            },
            "database_status": "connected",
            "etl_service_status": "available",
            "server_time": "2024-01-15 10:40:00",
            "timezone": "UTC"
        },
        "recent_activity": {
            "total_runs": 5,
            "successful_runs": 4,
            "failed_runs": 1,
            "last_run_at": "2024-01-15 10:30:00",
            "avg_execution_time_ms": 280000,
            "total_records_processed": 7500
        }
    }
}
```

**HTTP Status Codes:**

-   `200 OK`: Success
-   `503 Service Unavailable`: ETL service not available

---

### 2. Trigger Analytics ETL

**Endpoint:** `POST /api/warehouse/trigger-analytics-etl`

**Description:** Manually triggers an Analytics ETL process with specified parameters.

**Request Body:**

```json
{
    "etl_type": "manual_sync",
    "options": {
        "filters": {
            "warehouse_names": ["Москва РФЦ", "СПб МРФЦ"],
            "date_from": "2024-01-01",
            "date_to": "2024-01-15",
            "categories": ["electronics", "clothing"]
        },
        "batch_size": 1000,
        "enable_validation": true,
        "enable_normalization": true
    }
}
```

**Parameters:**

| Parameter  | Type   | Required | Description                               |
| ---------- | ------ | -------- | ----------------------------------------- |
| `etl_type` | string | No       | ETL process type (default: `manual_sync`) |
| `options`  | object | No       | ETL execution options                     |

**ETL Types:**

-   `full_sync`: Complete synchronization of all data
-   `incremental_sync`: Synchronize only changed data
-   `manual_sync`: User-triggered sync with custom parameters
-   `validation_only`: Validate data without loading to database

**Response:**

```json
{
    "status": "success",
    "data": {
        "etl_result": {
            "batch_id": "etl_manual_sync_20240115_104500_def456",
            "status": "completed",
            "execution_time_ms": 245000,
            "records_processed": 1200,
            "records_inserted": 600,
            "records_updated": 580,
            "records_errors": 20,
            "quality_score": 89.5,
            "is_successful": true,
            "error_message": null
        },
        "triggered_at": "2024-01-15 10:45:00"
    }
}
```

**HTTP Status Codes:**

-   `200 OK`: ETL triggered successfully
-   `400 Bad Request`: Invalid ETL type or parameters
-   `409 Conflict`: ETL process already running
-   `503 Service Unavailable`: ETL service not available

---

### 3. Get Data Quality Metrics

**Endpoint:** `GET /api/warehouse/data-quality`

**Description:** Retrieves comprehensive data quality metrics for the specified timeframe and data source.

**Query Parameters:**

| Parameter   | Type   | Required | Default | Description                                                     |
| ----------- | ------ | -------- | ------- | --------------------------------------------------------------- |
| `timeframe` | string | No       | `7d`    | Time period (e.g., `1d`, `7d`, `1w`, `1m`)                      |
| `source`    | string | No       | `all`   | Data source filter (`all`, `analytics_api`, `manual`, `import`) |

**Response:**

```json
{
    "status": "success",
    "data": {
        "quality_metrics": {
            "avg_quality_score": 87.3,
            "min_quality_score": 65.0,
            "max_quality_score": 100.0,
            "total_records": 15000,
            "high_quality_records": 12500,
            "low_quality_records": 800
        },
        "freshness_metrics": {
            "avg_hours_since_sync": 4.2,
            "max_hours_since_sync": 12.0,
            "min_hours_since_sync": 0.5,
            "total_records": 15000,
            "fresh_records": 13500,
            "stale_records": 1500
        },
        "completeness_metrics": {
            "total_records": 15000,
            "sku_complete": 14950,
            "warehouse_complete": 15000,
            "product_name_complete": 14200,
            "normalized_warehouse_complete": 14800,
            "price_complete": 14500,
            "category_complete": 13800,
            "completeness_percentages": {
                "sku": 99.67,
                "warehouse_name": 100.0,
                "product_name": 94.67,
                "normalized_warehouse_name": 98.67,
                "price": 96.67,
                "category": 92.0
            }
        },
        "validation_statistics": {
            "total_runs": 25,
            "successful_runs": 23,
            "failed_runs": 2,
            "avg_execution_time_ms": 275000,
            "total_records_processed": 375000,
            "success_rate": 92.0
        },
        "timeframe": "7d",
        "source_filter": "analytics_api",
        "generated_at": "2024-01-15 11:00:00"
    }
}
```

**HTTP Status Codes:**

-   `200 OK`: Success
-   `503 Service Unavailable`: Database not available

---

### 4. Get ETL History

**Endpoint:** `GET /api/warehouse/etl-history`

**Description:** Retrieves the history of ETL process executions with pagination and filtering options.

**Query Parameters:**

| Parameter  | Type    | Required | Default | Description                                                            |
| ---------- | ------- | -------- | ------- | ---------------------------------------------------------------------- |
| `limit`    | integer | No       | `50`    | Number of records to return (max 100)                                  |
| `offset`   | integer | No       | `0`     | Number of records to skip                                              |
| `days`     | integer | No       | `30`    | Number of days to look back (max 90)                                   |
| `status`   | string  | No       | -       | Filter by status (`completed`, `failed`, `running`, `partial_success`) |
| `etl_type` | string  | No       | -       | Filter by ETL type                                                     |

**Response:**

```json
{
    "status": "success",
    "data": {
        "history": [
            {
                "id": 123,
                "batch_id": "etl_incremental_sync_20240115_103000_abc123",
                "etl_type": "incremental_sync",
                "started_at": "2024-01-15 10:30:00",
                "completed_at": "2024-01-15 10:35:00",
                "status": "completed",
                "records_processed": 1500,
                "execution_time_ms": 300000,
                "data_source": "analytics_api",
                "error_message": null,
                "duration_seconds": 300
            },
            {
                "id": 122,
                "batch_id": "etl_manual_sync_20240115_090000_xyz789",
                "etl_type": "manual_sync",
                "started_at": "2024-01-15 09:00:00",
                "completed_at": "2024-01-15 09:08:00",
                "status": "partial_success",
                "records_processed": 2000,
                "execution_time_ms": 480000,
                "data_source": "analytics_api",
                "error_message": "Some records failed validation",
                "duration_seconds": 480
            }
        ],
        "pagination": {
            "total": 156,
            "limit": 50,
            "offset": 0,
            "has_more": true
        },
        "summary_stats": {
            "total_runs": 156,
            "successful_runs": 142,
            "failed_runs": 8,
            "partial_success_runs": 6,
            "success_rate": 91.03,
            "failure_rate": 5.13,
            "avg_execution_time_ms": 285000,
            "total_records_processed": 234000,
            "avg_records_per_run": 1500
        },
        "filters": {
            "days": 30,
            "status": null,
            "etl_type": null
        },
        "generated_at": "2024-01-15 11:15:00"
    }
}
```

**HTTP Status Codes:**

-   `200 OK`: Success
-   `503 Service Unavailable`: Database not available

## Error Handling

### Common Error Responses

#### 400 Bad Request

```json
{
    "status": "error",
    "message": "Invalid ETL type",
    "code": 400,
    "timestamp": "2024-01-15 10:30:00"
}
```

#### 404 Not Found

```json
{
    "status": "error",
    "message": "Endpoint not found",
    "code": 404,
    "timestamp": "2024-01-15 10:30:00"
}
```

#### 405 Method Not Allowed

```json
{
    "status": "error",
    "message": "Method not allowed",
    "code": 405,
    "timestamp": "2024-01-15 10:30:00"
}
```

#### 409 Conflict

```json
{
    "status": "error",
    "message": "ETL process is already running",
    "code": 409,
    "timestamp": "2024-01-15 10:30:00"
}
```

#### 500 Internal Server Error

```json
{
    "status": "error",
    "message": "Internal server error: Database connection failed",
    "code": 500,
    "timestamp": "2024-01-15 10:30:00"
}
```

#### 503 Service Unavailable

```json
{
    "status": "error",
    "message": "ETL service not available",
    "code": 503,
    "timestamp": "2024-01-15 10:30:00"
}
```

## Usage Examples

### cURL Examples

#### Get Analytics Status

```bash
curl -X GET 'http://your-domain.com/api/warehouse/analytics-status'
```

#### Trigger ETL Process

```bash
curl -X POST 'http://your-domain.com/api/warehouse/trigger-analytics-etl' \
  -H 'Content-Type: application/json' \
  -d '{
    "etl_type": "manual_sync",
    "options": {
      "filters": {
        "warehouse_names": ["Москва РФЦ"],
        "categories": ["electronics"]
      }
    }
  }'
```

#### Get Data Quality Metrics

```bash
curl -X GET 'http://your-domain.com/api/warehouse/data-quality?timeframe=7d&source=analytics_api'
```

#### Get ETL History

```bash
curl -X GET 'http://your-domain.com/api/warehouse/etl-history?limit=20&offset=0&days=30&status=completed'
```

### JavaScript Examples

#### Using Fetch API

```javascript
// Get analytics status
fetch("/api/warehouse/analytics-status")
    .then((response) => response.json())
    .then((data) => {
        if (data.status === "success") {
            console.log("ETL Status:", data.data.etl_status.status);
        }
    });

// Trigger ETL process
fetch("/api/warehouse/trigger-analytics-etl", {
    method: "POST",
    headers: {
        "Content-Type": "application/json",
    },
    body: JSON.stringify({
        etl_type: "incremental_sync",
        options: {},
    }),
})
    .then((response) => response.json())
    .then((data) => {
        if (data.status === "success") {
            console.log("ETL triggered:", data.data.etl_result.batch_id);
        } else {
            console.error("ETL failed:", data.message);
        }
    });

// Get data quality metrics
fetch("/api/warehouse/data-quality?timeframe=7d")
    .then((response) => response.json())
    .then((data) => {
        if (data.status === "success") {
            const quality = data.data.quality_metrics;
            console.log(`Average quality score: ${quality.avg_quality_score}%`);
        }
    });
```

#### Using Axios

```javascript
// Get ETL history with error handling
axios
    .get("/api/warehouse/etl-history", {
        params: {
            limit: 10,
            days: 7,
            status: "completed",
        },
    })
    .then((response) => {
        const data = response.data;
        if (data.status === "success") {
            console.log("ETL History:", data.data.history);
            console.log(
                "Success Rate:",
                data.data.summary_stats.success_rate + "%"
            );
        }
    })
    .catch((error) => {
        if (error.response) {
            console.error("API Error:", error.response.data.message);
        } else {
            console.error("Network Error:", error.message);
        }
    });
```

### PHP Examples

#### Using cURL

```php
// Trigger ETL process
$data = [
    'etl_type' => 'manual_sync',
    'options' => [
        'filters' => [
            'warehouse_names' => ['Москва РФЦ', 'СПб МРФЦ']
        ]
    ]
];

$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, 'http://your-domain.com/api/warehouse/trigger-analytics-etl');
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

$result = json_decode($response, true);
if ($httpCode === 200 && $result['status'] === 'success') {
    echo "ETL triggered successfully: " . $result['data']['etl_result']['batch_id'];
} else {
    echo "ETL failed: " . $result['message'];
}
```

## Configuration

### Controller Configuration

The controller accepts configuration options during initialization:

```php
$config = [
    'log_file' => '/path/to/analytics_etl_controller.log',
    'analytics_api' => [
        'base_url' => 'https://api.analytics.example.com',
        'api_key' => 'your_api_key',
        'timeout' => 30,
        'rate_limit' => 100
    ],
    'data_validator' => [
        'enable_anomaly_detection' => true,
        'quality_thresholds' => [
            'completeness' => 0.95,
            'accuracy' => 0.90,
            'consistency' => 0.85
        ]
    ],
    'warehouse_normalizer' => [
        'fuzzy_threshold' => 0.8,
        'enable_learning' => true
    ],
    'analytics_etl' => [
        'load_batch_size' => 1000,
        'min_quality_score' => 80.0,
        'enable_audit_logging' => true
    ]
];

$controller = new AnalyticsETLController($config);
```

### Web Server Configuration

#### Apache (.htaccess)

```apache
RewriteEngine On
RewriteCond %{REQUEST_FILENAME} !-f
RewriteCond %{REQUEST_FILENAME} !-d
RewriteRule ^api/warehouse/(.*)$ /src/api/controllers/AnalyticsETLController.php [QSA,L]
```

#### Nginx

```nginx
location /api/warehouse/ {
    try_files $uri $uri/ /src/api/controllers/AnalyticsETLController.php?$query_string;
}
```

## Security Considerations

### Authentication

Implement authentication mechanisms such as:

-   API keys
-   JWT tokens
-   OAuth 2.0
-   Basic authentication

### Authorization

Add role-based access control:

-   Read-only access for monitoring
-   Write access for triggering ETL
-   Admin access for all operations

### Input Validation

-   Validate all input parameters
-   Sanitize user input
-   Implement rate limiting
-   Use HTTPS in production

### Example Security Implementation

```php
class SecureAnalyticsETLController extends AnalyticsETLController {
    private function validateApiKey(string $apiKey): bool {
        // Implement API key validation
        return hash_equals($this->config['api_key'], $apiKey);
    }

    private function checkPermissions(string $endpoint, string $method): bool {
        // Implement permission checking
        return true; // Placeholder
    }

    public function handleRequest(string $method, string $endpoint, array $params = []): array {
        // Validate API key
        $apiKey = $_SERVER['HTTP_X_API_KEY'] ?? '';
        if (!$this->validateApiKey($apiKey)) {
            return $this->errorResponse('Invalid API key', 401);
        }

        // Check permissions
        if (!$this->checkPermissions($endpoint, $method)) {
            return $this->errorResponse('Insufficient permissions', 403);
        }

        return parent::handleRequest($method, $endpoint, $params);
    }
}
```

## Monitoring and Logging

### Log Files

The controller logs all operations to a configurable log file:

-   Request/response logging
-   Error logging
-   Performance metrics
-   Security events

### Metrics Collection

Monitor these key metrics:

-   Request rate and response times
-   Error rates by endpoint
-   ETL success/failure rates
-   Data quality trends
-   System resource usage

### Health Checks

Implement health check endpoints:

```php
// GET /api/warehouse/health
{
  "status": "healthy",
  "checks": {
    "database": "connected",
    "etl_service": "available",
    "disk_space": "sufficient",
    "memory_usage": "normal"
  },
  "timestamp": "2024-01-15 12:00:00"
}
```

## Troubleshooting

### Common Issues

1. **503 Service Unavailable**

    - Check database connection
    - Verify ETL service initialization
    - Review configuration settings

2. **409 Conflict (ETL already running)**

    - Wait for current ETL to complete
    - Check ETL status endpoint
    - Consider canceling stuck processes

3. **400 Bad Request**

    - Validate request parameters
    - Check ETL type values
    - Review request body format

4. **500 Internal Server Error**
    - Check server logs
    - Verify file permissions
    - Review database connectivity

### Debug Mode

Enable debug mode for detailed error information:

```php
$config['debug'] = true;
$controller = new AnalyticsETLController($config);
```

### Log Analysis

Monitor log files for patterns:

```bash
# Check error frequency
grep "ERROR" analytics_etl_controller.log | wc -l

# Monitor response times
grep "execution_time" analytics_etl_controller.log | tail -10

# Check recent API calls
tail -f analytics_etl_controller.log
```
