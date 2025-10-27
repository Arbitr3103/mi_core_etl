# Ozon ETL API Documentation

## Overview

This document describes the enhanced API endpoints and functionality introduced by the Ozon ETL refactoring. The API now provides comprehensive access to inventory data with advanced filtering capabilities based on product visibility and stock status.

## Base URL

```
Production: https://your-domain.com/api
Development: http://localhost/api
```

## Authentication

Same authentication methods as the main API:

-   IP Whitelisting
-   API Keys (optional)
-   CORS configuration

## Enhanced Endpoints

### 1. Detailed Stock Inventory

#### Get Detailed Stock Data

```http
GET /api/inventory/detailed-stock
```

**New Parameters:**

| Parameter           | Type    | Description                              | Default                    |
| ------------------- | ------- | ---------------------------------------- | -------------------------- |
| `stock_status`      | string  | Filter by stock status (comma-separated) | All visible products       |
| `visibility`        | string  | Filter by visibility status              | `VISIBLE,ACTIVE,продаётся` |
| `include_hidden`    | boolean | Include hidden/archived products         | `false`                    |
| `warehouse`         | string  | Filter by warehouse name                 | All warehouses             |
| `min_stock`         | integer | Minimum available stock                  | 0                          |
| `max_stock`         | integer | Maximum available stock                  | No limit                   |
| `days_of_stock_min` | float   | Minimum days of stock                    | No limit                   |
| `days_of_stock_max` | float   | Maximum days of stock                    | No limit                   |
| `limit`             | integer | Number of records to return              | 100                        |
| `offset`            | integer | Number of records to skip                | 0                          |

**Stock Status Values:**

-   `critical` - Less than 14 days of stock
-   `low` - 14-30 days of stock
-   `normal` - 30-60 days of stock
-   `excess` - More than 60 days of stock
-   `out_of_stock` - No available stock
-   `archived_or_hidden` - Product not visible in catalog

**Example Requests:**

```bash
# Get critical and low stock products
curl "https://api.example.com/api/inventory/detailed-stock?stock_status=critical,low"

# Get products with high stock levels
curl "https://api.example.com/api/inventory/detailed-stock?stock_status=excess&min_stock=100"

# Get all products including hidden ones
curl "https://api.example.com/api/inventory/detailed-stock?include_hidden=true"

# Get products from specific warehouse
curl "https://api.example.com/api/inventory/detailed-stock?warehouse=МОСКВА_РФЦ"

# Get products with specific days of stock range
curl "https://api.example.com/api/inventory/detailed-stock?days_of_stock_min=7&days_of_stock_max=30"
```

**Enhanced Response Format:**

```json
{
    "data": [
        {
            "product_id": 123456789,
            "product_name": "Автомобильный аккумулятор 60Ah",
            "offer_id": "BATTERY_60AH_001",
            "sku": "SKU123456",
            "visibility": "VISIBLE",
            "present": 45,
            "reserved": 5,
            "available_stock": 40,
            "stock_status": "normal",
            "warehouse_name": "МОСКВА_РФЦ",
            "daily_sales_avg": 2.3,
            "days_of_stock": 17.4,
            "recommended_qty": 75,
            "urgency_score": 3,
            "last_updated": "2025-10-27T10:30:00Z"
        }
    ],
    "meta": {
        "total_count": 15420,
        "filtered_count": 234,
        "page": 1,
        "per_page": 100,
        "total_pages": 3,
        "filters_applied": {
            "stock_status": ["critical", "low"],
            "visibility": ["VISIBLE", "ACTIVE"],
            "include_hidden": false,
            "warehouse": null,
            "min_stock": 0
        },
        "summary": {
            "critical_count": 45,
            "low_count": 189,
            "normal_count": 8920,
            "excess_count": 6266,
            "out_of_stock_count": 0,
            "hidden_count": 0
        }
    },
    "success": true,
    "timestamp": "2025-10-27T10:30:15Z"
}
```

### 2. Product Visibility Status

#### Get Product Visibility Information

```http
GET /api/inventory/product/{offer_id}/visibility
```

**Response:**

```json
{
    "data": {
        "offer_id": "BATTERY_60AH_001",
        "product_id": 123456789,
        "product_name": "Автомобильный аккумулятор 60Ah",
        "visibility": "VISIBLE",
        "visibility_updated": "2025-10-27T06:00:00Z",
        "status_history": [
            {
                "status": "VISIBLE",
                "changed_at": "2025-10-27T06:00:00Z"
            },
            {
                "status": "MODERATION",
                "changed_at": "2025-10-26T14:30:00Z"
            }
        ]
    },
    "success": true
}
```

### 3. Stock Status Analytics

#### Get Stock Status Distribution

```http
GET /api/inventory/analytics/stock-status
```

**Parameters:**

| Parameter   | Type   | Description                | Default        |
| ----------- | ------ | -------------------------- | -------------- |
| `warehouse` | string | Filter by warehouse        | All warehouses |
| `category`  | string | Filter by product category | All categories |
| `date_from` | date   | Start date for analysis    | 30 days ago    |
| `date_to`   | date   | End date for analysis      | Today          |

**Response:**

```json
{
    "data": {
        "distribution": {
            "critical": {
                "count": 45,
                "percentage": 2.9,
                "total_value": 125000.5
            },
            "low": {
                "count": 189,
                "percentage": 12.3,
                "total_value": 450000.75
            },
            "normal": {
                "count": 892,
                "percentage": 57.8,
                "total_value": 2100000.25
            },
            "excess": {
                "count": 394,
                "percentage": 25.5,
                "total_value": 980000.0
            },
            "out_of_stock": {
                "count": 23,
                "percentage": 1.5,
                "total_value": 0.0
            }
        },
        "trends": {
            "critical_trend": "+12%",
            "low_trend": "-5%",
            "normal_trend": "+2%",
            "excess_trend": "-8%"
        },
        "warehouses": [
            {
                "warehouse_name": "МОСКВА_РФЦ",
                "total_products": 456,
                "critical_count": 12,
                "low_count": 45
            }
        ]
    },
    "success": true
}
```

### 4. ETL Status and Health

#### Get ETL System Status

```http
GET /api/etl/status
```

**Response:**

```json
{
    "data": {
        "overall_status": "healthy",
        "components": {
            "product_etl": {
                "status": "healthy",
                "last_run": "2025-10-27T06:00:00Z",
                "next_run": "2025-10-27T18:00:00Z",
                "duration_seconds": 245,
                "records_processed": 15432,
                "success_rate": 99.8,
                "last_error": null
            },
            "inventory_etl": {
                "status": "healthy",
                "last_run": "2025-10-27T06:30:00Z",
                "next_run": "2025-10-27T18:30:00Z",
                "duration_seconds": 180,
                "records_processed": 45680,
                "success_rate": 99.9,
                "last_error": null
            }
        },
        "data_quality": {
            "products_with_visibility": 15420,
            "products_without_visibility": 12,
            "inventory_records": 45680,
            "orphaned_inventory": 3,
            "data_freshness_hours": 2.5
        },
        "performance_metrics": {
            "avg_api_response_time_ms": 145,
            "cache_hit_rate": 87.3,
            "database_query_time_ms": 23
        }
    },
    "success": true,
    "timestamp": "2025-10-27T10:30:00Z"
}
```

#### Trigger ETL Process

```http
POST /api/etl/trigger
```

**Request Body:**

```json
{
    "component": "product_etl", // or "inventory_etl" or "both"
    "force": false, // Skip dependency checks
    "notify": true // Send completion notifications
}
```

**Response:**

```json
{
    "data": {
        "job_id": "etl_job_20251027_103000",
        "component": "product_etl",
        "status": "started",
        "estimated_duration_minutes": 5,
        "started_at": "2025-10-27T10:30:00Z"
    },
    "success": true
}
```

### 5. Data Validation and Quality

#### Get Data Quality Report

```http
GET /api/inventory/data-quality
```

**Response:**

```json
{
    "data": {
        "overall_score": 98.5,
        "checks": {
            "visibility_completeness": {
                "score": 99.2,
                "total_products": 15432,
                "products_with_visibility": 15420,
                "products_without_visibility": 12,
                "status": "good"
            },
            "inventory_consistency": {
                "score": 99.8,
                "total_inventory_records": 45680,
                "orphaned_records": 3,
                "negative_stock_records": 0,
                "status": "excellent"
            },
            "data_freshness": {
                "score": 95.0,
                "last_product_update": "2025-10-27T06:00:00Z",
                "last_inventory_update": "2025-10-27T06:30:00Z",
                "hours_since_update": 4.5,
                "status": "good"
            },
            "api_performance": {
                "score": 98.0,
                "avg_response_time_ms": 145,
                "error_rate_percent": 0.2,
                "cache_hit_rate": 87.3,
                "status": "excellent"
            }
        },
        "recommendations": [
            "Update visibility data for 12 products without visibility status",
            "Investigate 3 orphaned inventory records",
            "Consider increasing cache TTL to improve performance"
        ]
    },
    "success": true
}
```

## Error Handling

### Enhanced Error Responses

```json
{
    "error": "Invalid stock_status filter",
    "message": "Stock status 'invalid_status' is not supported. Valid values are: critical, low, normal, excess, out_of_stock, archived_or_hidden",
    "code": 400,
    "details": {
        "parameter": "stock_status",
        "provided_value": "invalid_status",
        "valid_values": [
            "critical",
            "low",
            "normal",
            "excess",
            "out_of_stock",
            "archived_or_hidden"
        ]
    },
    "timestamp": "2025-10-27T10:30:00Z",
    "request_id": "req_20251027_103000_abc123"
}
```

### Error Codes

| Code | Description           | Common Causes                           |
| ---- | --------------------- | --------------------------------------- |
| 400  | Bad Request           | Invalid parameters, malformed filters   |
| 401  | Unauthorized          | Missing or invalid API key              |
| 404  | Not Found             | Product/offer not found                 |
| 422  | Unprocessable Entity  | Valid syntax but invalid data           |
| 429  | Rate Limited          | Too many requests                       |
| 500  | Internal Server Error | Database connection, ETL process errors |
| 503  | Service Unavailable   | ETL process running, maintenance mode   |

## Rate Limiting

Enhanced rate limiting with different limits for different endpoints:

| Endpoint Category | Limit       | Window     |
| ----------------- | ----------- | ---------- |
| Detailed Stock    | 30 requests | per minute |
| Analytics         | 10 requests | per minute |
| ETL Operations    | 5 requests  | per minute |
| General API       | 60 requests | per minute |

## Caching

### Cache Headers

All responses include appropriate cache headers:

```http
Cache-Control: public, max-age=300
ETag: "abc123def456"
Last-Modified: Mon, 27 Oct 2025 10:30:00 GMT
X-Cache-Status: HIT
```

### Cache TTL by Endpoint

| Endpoint          | TTL        | Reason                                    |
| ----------------- | ---------- | ----------------------------------------- |
| `/detailed-stock` | 5 minutes  | Balance between freshness and performance |
| `/analytics/*`    | 15 minutes | Analytical data changes less frequently   |
| `/etl/status`     | 1 minute   | ETL status should be relatively fresh     |
| `/data-quality`   | 10 minutes | Quality metrics don't change rapidly      |

## Performance Considerations

### Optimization Tips

1. **Use appropriate filters** to reduce dataset size
2. **Leverage pagination** for large result sets
3. **Respect cache headers** to avoid unnecessary requests
4. **Use specific stock_status filters** instead of fetching all data
5. **Batch multiple requests** when possible

### Response Time Targets

| Endpoint                     | Target  | With Cache |
| ---------------------------- | ------- | ---------- |
| Detailed Stock (100 records) | < 2s    | < 200ms    |
| Analytics                    | < 3s    | < 500ms    |
| ETL Status                   | < 500ms | < 100ms    |
| Data Quality                 | < 1s    | < 200ms    |

## WebSocket Support (Future Enhancement)

Real-time updates for ETL status and inventory changes:

```javascript
// Connect to WebSocket
const ws = new WebSocket("wss://api.example.com/ws/inventory");

// Subscribe to updates
ws.send(
    JSON.stringify({
        action: "subscribe",
        channels: ["etl_status", "inventory_updates"],
        filters: {
            warehouse: "МОСКВА_РФЦ",
            stock_status: ["critical", "low"],
        },
    })
);

// Receive real-time updates
ws.onmessage = (event) => {
    const data = JSON.parse(event.data);
    console.log("Inventory update:", data);
};
```

## SDK and Client Libraries

### JavaScript/Node.js

```javascript
import { OzonETLClient } from "@company/ozon-etl-client";

const client = new OzonETLClient({
    baseUrl: "https://api.example.com",
    apiKey: "your-api-key",
});

// Get detailed stock with filters
const stock = await client.getDetailedStock({
    stockStatus: ["critical", "low"],
    warehouse: "МОСКВА_РФЦ",
    limit: 50,
});

// Get analytics
const analytics = await client.getStockAnalytics({
    warehouse: "МОСКВА_РФЦ",
    dateFrom: "2025-10-01",
    dateTo: "2025-10-27",
});
```

### PHP

```php
use Company\OzonETL\Client;

$client = new Client([
    'base_url' => 'https://api.example.com',
    'api_key' => 'your-api-key'
]);

// Get detailed stock
$stock = $client->getDetailedStock([
    'stock_status' => 'critical,low',
    'warehouse' => 'МОСКВА_РФЦ',
    'limit' => 50
]);

// Trigger ETL process
$job = $client->triggerETL([
    'component' => 'product_etl',
    'notify' => true
]);
```

## Migration from Legacy API

### Breaking Changes

1. **New required parameters**: Some endpoints now require `visibility` parameter
2. **Changed response format**: Additional fields in response objects
3. **New stock status values**: Updated enumeration of stock statuses
4. **Enhanced filtering**: More granular filtering options

### Migration Guide

```javascript
// Legacy API call
const response = await fetch("/api/inventory/dashboard");

// New API call with equivalent functionality
const response = await fetch(
    "/api/inventory/detailed-stock?stock_status=critical,low,normal"
);
```

### Backward Compatibility

The API maintains backward compatibility for:

-   Existing endpoint URLs (with deprecation warnings)
-   Core response fields
-   Authentication methods
-   Rate limiting behavior

Deprecated endpoints will be supported for 6 months with appropriate warnings in response headers:

```http
X-Deprecated-Endpoint: true
X-Deprecation-Date: 2026-04-27
X-Migration-Guide: https://docs.example.com/api/migration
```

## Changelog

### Version 2.0.0 (October 2025)

**New Features:**

-   ✅ Enhanced detailed stock endpoint with advanced filtering
-   ✅ Product visibility status tracking
-   ✅ Stock status analytics and distribution
-   ✅ ETL system status and health monitoring
-   ✅ Data quality reporting and validation
-   ✅ Real-time ETL process triggering
-   ✅ Comprehensive error handling with detailed messages
-   ✅ Performance optimizations with improved caching

**Breaking Changes:**

-   Stock status enumeration updated
-   Response format enhanced with additional fields
-   New required parameters for some endpoints

**Deprecations:**

-   Legacy dashboard endpoint (use detailed-stock instead)
-   Old stock status values (6-month deprecation period)

## Support and Resources

-   **API Documentation**: This document
-   **Architecture Guide**: [Ozon ETL Architecture](ozon_etl_refactoring_architecture.md)
-   **Troubleshooting**: [ETL Troubleshooting Guide](ozon_etl_troubleshooting_guide.md)
-   **Deployment**: [Production Deployment Guide](ozon_etl_production_deployment_guide.md)
-   **Testing**: [API Testing Guide](ozon_etl_testing_guide.md)
