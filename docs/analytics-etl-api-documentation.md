# Analytics ETL API Documentation

## 📋 Overview

The Analytics ETL API provides comprehensive endpoints for managing warehouse data integration from multiple sources (Ozon Analytics API and UI reports). This system implements a hybrid data loading strategy with automatic fallback mechanisms, data quality validation, and real-time monitoring capabilities.

## 🔗 Base URL

```
Production: https://your-domain.com/api
Development: http://localhost/api
```

## 🔐 Authentication

All API endpoints require authentication using JWT tokens or API keys.

### Headers Required

```http
Authorization: Bearer <jwt_token>
Content-Type: application/json
X-API-Key: <your_api_key>
```

## 📊 Analytics ETL Endpoints

### 1. ETL Process Management

#### Start ETL Process

```http
POST /analytics-etl/start
```

**Description:** Initiates the Analytics ETL process with specified configuration.

**Request Body:**

```json
{
    "etl_type": "full_sync|incremental_sync|manual_sync|validation_only",
    "data_sources": ["api", "ui_report"],
    "warehouse_filters": ["РФЦ Москва", "РФЦ СПб"],
    "date_range": {
        "from": "2025-01-01",
        "to": "2025-01-31"
    },
    "config": {
        "batch_size": 1000,
        "min_quality_score": 80.0,
        "enable_fallback": true,
        "retry_failed_records": true
    }
}
```

**Response:**

```json
{
    "success": true,
    "data": {
        "batch_id": "analytics_20250125_143022_abc123",
        "etl_type": "incremental_sync",
        "status": "running",
        "estimated_duration": "5-10 minutes",
        "progress_url": "/analytics-etl/status/analytics_20250125_143022_abc123"
    },
    "message": "ETL process started successfully"
}
```

#### Get ETL Status

```http
GET /analytics-etl/status/{batch_id}
```

**Description:** Retrieves current status and progress of an ETL process.

**Response:**

```json
{
    "success": true,
    "data": {
        "batch_id": "analytics_20250125_143022_abc123",
        "status": "running|completed|failed|partial_success",
        "progress": {
            "current_phase": "transform",
            "percentage": 65,
            "records_processed": 6500,
            "total_records": 10000,
            "estimated_remaining": "2 minutes"
        },
        "metrics": {
            "extract": {
                "records_extracted": 10000,
                "api_requests_made": 10,
                "cache_hits": 5,
                "extraction_time_ms": 2000
            },
            "transform": {
                "records_processed": 6500,
                "records_normalized": 6500,
                "validation_quality_score": 95.0,
                "anomalies_detected": 2,
                "transformation_time_ms": 1500
            },
            "load": {
                "records_inserted": 5000,
                "records_updated": 1500,
                "records_errors": 0,
                "load_time_ms": 3000
            }
        },
        "quality_metrics": {
            "overall_score": 95.0,
            "completeness": 98.5,
            "accuracy": 100.0,
            "consistency": 92.0,
            "freshness": 95.0
        },
        "started_at": "2025-01-25T14:30:22Z",
        "updated_at": "2025-01-25T14:35:45Z"
    }
}
```

#### Stop ETL Process

```http
POST /analytics-etl/stop/{batch_id}
```

**Description:** Gracefully stops a running ETL process.

**Response:**

```json
{
    "success": true,
    "data": {
        "batch_id": "analytics_20250125_143022_abc123",
        "status": "stopped",
        "records_processed": 6500,
        "partial_results_available": true
    },
    "message": "ETL process stopped successfully"
}
```

### 2. Data Source Management

#### Get Data Sources Status

```http
GET /analytics-etl/data-sources
```

**Description:** Retrieves status and configuration of all data sources.

**Response:**

```json
{
    "success": true,
    "data": {
        "sources": [
            {
                "type": "api",
                "name": "Ozon Analytics API",
                "status": "active",
                "priority": 1,
                "last_sync": "2025-01-25T14:30:22Z",
                "success_rate": 98.5,
                "avg_response_time_ms": 450,
                "rate_limit": {
                    "requests_per_minute": 30,
                    "current_usage": 15,
                    "reset_time": "2025-01-25T14:31:00Z"
                },
                "cache": {
                    "hit_rate": 75.0,
                    "entries": 150,
                    "ttl_seconds": 7200
                }
            },
            {
                "type": "ui_report",
                "name": "UI Reports Parser",
                "status": "active",
                "priority": 2,
                "last_sync": "2025-01-25T13:45:10Z",
                "files_processed": 25,
                "success_rate": 92.0,
                "avg_processing_time_ms": 1200
            }
        ],
        "fallback_strategy": "api_to_ui",
        "hybrid_mode_enabled": true
    }
}
```

#### Update Source Priority

```http
PUT /analytics-etl/data-sources/priority
```

**Description:** Updates priority order of data sources.

**Request Body:**

```json
{
    "priorities": [
        { "source": "api", "priority": 1 },
        { "source": "ui_report", "priority": 2 }
    ]
}
```

**Response:**

```json
{
    "success": true,
    "data": {
        "updated_sources": 2,
        "new_priority_order": ["api", "ui_report"]
    },
    "message": "Source priorities updated successfully"
}
```

### 3. Data Quality Management

#### Get Data Quality Metrics

```http
GET /analytics-etl/data-quality
```

**Query Parameters:**

-   `warehouse_id` (optional): Filter by specific warehouse
-   `date_from` (optional): Start date for metrics (YYYY-MM-DD)
-   `date_to` (optional): End date for metrics (YYYY-MM-DD)
-   `source_type` (optional): Filter by data source (api|ui_report|mixed)

**Response:**

```json
{
    "success": true,
    "data": {
        "overall_metrics": {
            "quality_score": 95.0,
            "completeness": 98.5,
            "accuracy": 100.0,
            "consistency": 92.0,
            "freshness": 95.0,
            "total_records": 50000,
            "valid_records": 47500,
            "invalid_records": 2500
        },
        "by_warehouse": [
            {
                "warehouse_name": "РФЦ Москва",
                "quality_score": 98.0,
                "data_source": "api",
                "last_updated": "2025-01-25T14:30:22Z",
                "records_count": 5000,
                "anomalies_detected": 0
            }
        ],
        "by_source": {
            "api": {
                "quality_score": 100.0,
                "records": 35000,
                "coverage_percentage": 70.0
            },
            "ui_report": {
                "quality_score": 85.0,
                "records": 15000,
                "coverage_percentage": 30.0
            }
        },
        "quality_trends": {
            "last_7_days": [
                { "date": "2025-01-19", "score": 94.0 },
                { "date": "2025-01-20", "score": 95.5 },
                { "date": "2025-01-21", "score": 96.0 },
                { "date": "2025-01-22", "score": 94.5 },
                { "date": "2025-01-23", "score": 95.0 },
                { "date": "2025-01-24", "score": 96.5 },
                { "date": "2025-01-25", "score": 95.0 }
            ]
        }
    }
}
```

#### Get Data Quality Issues

```http
GET /analytics-etl/data-quality/issues
```

**Query Parameters:**

-   `severity` (optional): Filter by severity (critical|warning|info)
-   `status` (optional): Filter by status (open|resolved|ignored)
-   `limit` (optional): Number of results (default: 50)

**Response:**

```json
{
    "success": true,
    "data": {
        "issues": [
            {
                "id": 1234,
                "severity": "warning",
                "type": "data_discrepancy",
                "warehouse_name": "РФЦ СПб",
                "sku": "TEST-SKU-001",
                "description": "Stock level discrepancy between API (100) and UI report (95)",
                "api_value": 100,
                "ui_value": 95,
                "discrepancy_percentage": 5.0,
                "detected_at": "2025-01-25T14:25:10Z",
                "status": "open",
                "resolution_action": null
            },
            {
                "id": 1235,
                "severity": "critical",
                "type": "missing_data",
                "warehouse_name": "МРФЦ Екатеринбург",
                "description": "No data received for warehouse in last 6 hours",
                "last_update": "2025-01-25T08:30:00Z",
                "detected_at": "2025-01-25T14:30:00Z",
                "status": "open",
                "resolution_action": "escalated_to_admin"
            }
        ],
        "summary": {
            "total_issues": 25,
            "critical": 2,
            "warning": 18,
            "info": 5,
            "open": 20,
            "resolved": 5
        }
    }
}
```

### 4. Warehouse Data Management

#### Get Warehouse Data

```http
GET /analytics-etl/warehouses
```

**Query Parameters:**

-   `warehouse_names[]` (optional): Filter by warehouse names
-   `data_source` (optional): Filter by data source (api|ui_report|mixed)
-   `min_quality_score` (optional): Minimum quality score (0-100)
-   `include_metrics` (optional): Include detailed metrics (true|false)

**Response:**

```json
{
    "success": true,
    "data": {
        "warehouses": [
            {
                "warehouse_name": "РФЦ Москва",
                "normalized_name": "РФЦ_МОСКВА",
                "data_source": "api",
                "data_quality_score": 100,
                "last_updated": "2025-01-25T14:30:22Z",
                "data_age_minutes": 5,
                "total_products": 1250,
                "total_stock": 125000,
                "available_stock": 100000,
                "reserved_stock": 25000,
                "metrics": {
                    "avg_stock_per_product": 100.0,
                    "stock_turnover_rate": 2.5,
                    "out_of_stock_products": 15,
                    "low_stock_products": 45
                }
            }
        ],
        "summary": {
            "total_warehouses": 32,
            "api_coverage": 28,
            "ui_coverage": 4,
            "avg_quality_score": 95.0,
            "last_full_sync": "2025-01-25T12:00:00Z"
        }
    }
}
```

#### Sync Warehouse Data

```http
POST /analytics-etl/warehouses/sync
```

**Description:** Triggers immediate synchronization for specific warehouses.

**Request Body:**

```json
{
    "warehouse_names": ["РФЦ Москва", "РФЦ СПб"],
    "force_refresh": true,
    "source_preference": "api"
}
```

**Response:**

```json
{
    "success": true,
    "data": {
        "sync_batch_id": "sync_20250125_143500_xyz789",
        "warehouses_queued": 2,
        "estimated_completion": "2025-01-25T14:40:00Z"
    },
    "message": "Warehouse sync initiated successfully"
}
```

### 5. Analytics and Reporting

#### Get ETL Statistics

```http
GET /analytics-etl/statistics
```

**Query Parameters:**

-   `period` (optional): Time period (1h|24h|7d|30d) - default: 24h
-   `group_by` (optional): Group results by (hour|day|warehouse|source)

**Response:**

```json
{
    "success": true,
    "data": {
        "period": "24h",
        "etl_executions": {
            "total": 48,
            "successful": 46,
            "failed": 2,
            "success_rate": 95.8
        },
        "data_processing": {
            "total_records_processed": 2400000,
            "avg_processing_time_ms": 1250,
            "avg_batch_size": 50000,
            "peak_throughput_per_hour": 150000
        },
        "api_performance": {
            "total_requests": 480,
            "avg_response_time_ms": 450,
            "rate_limit_hits": 5,
            "cache_hit_rate": 75.0
        },
        "data_quality": {
            "avg_quality_score": 95.0,
            "quality_trend": "stable",
            "anomalies_detected": 12,
            "issues_resolved": 10
        },
        "by_warehouse": [
            {
                "warehouse_name": "РФЦ Москва",
                "sync_count": 48,
                "success_rate": 100.0,
                "avg_quality_score": 98.0,
                "avg_processing_time_ms": 850
            }
        ]
    }
}
```

#### Generate ETL Report

```http
POST /analytics-etl/reports/generate
```

**Description:** Generates comprehensive ETL performance and quality report.

**Request Body:**

```json
{
    "report_type": "daily|weekly|monthly|custom",
    "date_range": {
        "from": "2025-01-01",
        "to": "2025-01-31"
    },
    "include_sections": [
        "executive_summary",
        "performance_metrics",
        "quality_analysis",
        "warehouse_breakdown",
        "recommendations"
    ],
    "format": "json|pdf|excel",
    "email_recipients": ["admin@company.com"]
}
```

**Response:**

```json
{
    "success": true,
    "data": {
        "report_id": "report_20250125_143000",
        "status": "generating",
        "estimated_completion": "2025-01-25T14:35:00Z",
        "download_url": "/analytics-etl/reports/download/report_20250125_143000"
    },
    "message": "Report generation started"
}
```

## 🚨 Error Handling

### Standard Error Response Format

```json
{
    "success": false,
    "error": {
        "code": "ETL_VALIDATION_ERROR",
        "message": "Data quality score below minimum threshold",
        "details": {
            "current_score": 75.0,
            "minimum_required": 80.0,
            "affected_warehouses": ["РФЦ Новосибирск"]
        },
        "timestamp": "2025-01-25T14:30:22Z",
        "request_id": "req_abc123def456"
    }
}
```

### Common Error Codes

| Code                       | HTTP Status | Description                         |
| -------------------------- | ----------- | ----------------------------------- |
| `ETL_PROCESS_NOT_FOUND`    | 404         | ETL batch ID not found              |
| `ETL_ALREADY_RUNNING`      | 409         | ETL process already running         |
| `ETL_VALIDATION_ERROR`     | 400         | Data validation failed              |
| `API_RATE_LIMIT_EXCEEDED`  | 429         | Ozon API rate limit exceeded        |
| `DATA_SOURCE_UNAVAILABLE`  | 503         | Data source temporarily unavailable |
| `INSUFFICIENT_PERMISSIONS` | 403         | User lacks required permissions     |
| `INVALID_CONFIGURATION`    | 400         | Invalid ETL configuration           |

## 📈 Rate Limits

| Endpoint Category | Limit        | Window   |
| ----------------- | ------------ | -------- |
| ETL Management    | 10 requests  | 1 minute |
| Data Quality      | 100 requests | 1 minute |
| Warehouse Data    | 200 requests | 1 minute |
| Statistics        | 50 requests  | 1 minute |
| Reports           | 5 requests   | 1 minute |

## 🔄 Webhooks

### ETL Process Completion

```http
POST https://your-webhook-url.com/etl-completed
```

**Payload:**

```json
{
    "event": "etl.completed",
    "batch_id": "analytics_20250125_143022_abc123",
    "status": "completed|failed|partial_success",
    "metrics": {
        "total_records": 50000,
        "processing_time_ms": 300000,
        "quality_score": 95.0
    },
    "timestamp": "2025-01-25T14:35:22Z"
}
```

### Data Quality Alert

```http
POST https://your-webhook-url.com/quality-alert
```

**Payload:**

```json
{
    "event": "data_quality.alert",
    "severity": "critical|warning|info",
    "warehouse_name": "РФЦ Москва",
    "quality_score": 65.0,
    "threshold": 80.0,
    "description": "Data quality below threshold",
    "timestamp": "2025-01-25T14:30:22Z"
}
```

## 📚 SDK Examples

### PHP SDK

```php
<?php
require_once 'vendor/autoload.php';

use AnalyticsETL\Client;

$client = new Client([
    'base_url' => 'https://your-domain.com/api',
    'api_key' => 'your-api-key'
]);

// Start ETL process
$response = $client->etl()->start([
    'etl_type' => 'incremental_sync',
    'warehouse_filters' => ['РФЦ Москва']
]);

$batchId = $response['data']['batch_id'];

// Monitor progress
do {
    sleep(30);
    $status = $client->etl()->getStatus($batchId);
    echo "Progress: " . $status['data']['progress']['percentage'] . "%\n";
} while ($status['data']['status'] === 'running');

echo "ETL completed with status: " . $status['data']['status'] . "\n";
?>
```

### JavaScript SDK

```javascript
import { AnalyticsETLClient } from "@company/analytics-etl-sdk";

const client = new AnalyticsETLClient({
    baseUrl: "https://your-domain.com/api",
    apiKey: "your-api-key",
});

// Start ETL process
const response = await client.etl.start({
    etl_type: "incremental_sync",
    warehouse_filters: ["РФЦ Москва"],
});

const batchId = response.data.batch_id;

// Monitor progress with polling
const status = await client.etl.waitForCompletion(batchId, {
    pollInterval: 30000, // 30 seconds
    onProgress: (progress) => {
        console.log(`Progress: ${progress.percentage}%`);
    },
});

console.log(`ETL completed with status: ${status.status}`);
```

## 🔧 Configuration

### Environment Variables

```bash
# API Configuration
ANALYTICS_ETL_API_URL=https://api-seller.ozon.ru
OZON_CLIENT_ID=your_client_id
OZON_API_KEY=your_api_key

# Database Configuration
DB_HOST=localhost
DB_PORT=5432
DB_NAME=warehouse_analytics
DB_USER=analytics_user
DB_PASSWORD=secure_password

# ETL Configuration
ETL_BATCH_SIZE=1000
ETL_MIN_QUALITY_SCORE=80.0
ETL_CACHE_TTL=7200
ETL_MAX_RETRIES=3
ETL_RATE_LIMIT_PER_MINUTE=30

# Monitoring Configuration
WEBHOOK_URL=https://your-domain.com/webhooks/etl
ALERT_EMAIL=admin@company.com
LOG_LEVEL=INFO
```

---

## 📞 Support

For technical support and questions:

-   **Documentation:** This guide and inline code comments
-   **Email:** support@company.com
-   **Issue Tracker:** https://github.com/company/analytics-etl/issues

---

**Last Updated:** January 25, 2025  
**API Version:** 1.0  
**Documentation Version:** 1.0
