# Active Product Filtering API Documentation

## Overview

The Active Product Filtering API provides endpoints for managing and monitoring product activity status in the Ozon inventory system. This API filters products based on their activity status (visible, processed, in stock) to show only relevant products in dashboards and reports.

**Base URL**: `/api/inventory-analytics.php`

## Authentication

All API endpoints use the same authentication mechanism as the existing inventory analytics API. Ensure proper authentication headers are included in requests.

## Common Parameters

### Global Parameters

| Parameter     | Type    | Default | Description                         |
| ------------- | ------- | ------- | ----------------------------------- |
| `active_only` | boolean | `true`  | Filter to show only active products |
| `format`      | string  | `json`  | Response format (`json`, `xml`)     |
| `limit`       | integer | `100`   | Maximum number of results           |
| `offset`      | integer | `0`     | Number of results to skip           |

## Endpoints

### 1. Activity Statistics

Get overall statistics about product activity status.

**Endpoint**: `GET /api/inventory-analytics.php?action=activity_stats`

#### Parameters

| Parameter   | Type   | Required | Description                            |
| ----------- | ------ | -------- | -------------------------------------- |
| `action`    | string | Yes      | Must be `activity_stats`               |
| `date_from` | date   | No       | Start date for statistics (YYYY-MM-DD) |
| `date_to`   | date   | No       | End date for statistics (YYYY-MM-DD)   |

#### Response

```json
{
  "status": "success",
  "data": {
    "total_products": 176,
    "active_products": 48,
    "inactive_products": 128,
    "active_percentage": 27.27,
    "last_activity_check": "2025-01-16 14:30:00",
    "never_checked": 0,
    "activity_criteria": {
      "visibility": "VISIBLE",
      "state": "processed",
      "stock_threshold": 0,
      "pricing_required": true
    }
  },
  "timestamp": "2025-01-16T14:30:00Z"
}
```

#### Example Request

```bash
curl -X GET "https://your-domain.com/api/inventory-analytics.php?action=activity_stats"
```

### 2. Inactive Products List

Get list of products that are currently inactive.

**Endpoint**: `GET /api/inventory-analytics.php?action=inactive_products`

#### Parameters

| Parameter | Type    | Required | Description                   |
| --------- | ------- | -------- | ----------------------------- |
| `action`  | string  | Yes      | Must be `inactive_products`   |
| `reason`  | string  | No       | Filter by inactivity reason   |
| `limit`   | integer | No       | Maximum results (default: 50) |
| `offset`  | integer | No       | Results offset (default: 0)   |

#### Response

```json
{
  "status": "success",
  "data": {
    "inactive_products": [
      {
        "id": 123,
        "sku_ozon": "SKU123456",
        "product_name": "Product Name",
        "is_active": false,
        "activity_reason": "No stock available",
        "activity_checked_at": "2025-01-16 14:00:00",
        "last_stock_check": {
          "present": 0,
          "reserved": 0
        },
        "visibility": "INVISIBLE",
        "state": "processed"
      }
    ],
    "total_count": 128,
    "pagination": {
      "limit": 50,
      "offset": 0,
      "has_more": true
    }
  },
  "timestamp": "2025-01-16T14:30:00Z"
}
```

#### Example Request

```bash
curl -X GET "https://your-domain.com/api/inventory-analytics.php?action=inactive_products&limit=20&reason=No%20stock%20available"
```

### 3. Activity Changes Log

Get log of recent activity status changes.

**Endpoint**: `GET /api/inventory-analytics.php?action=activity_changes`

#### Parameters

| Parameter     | Type    | Required | Description                       |
| ------------- | ------- | -------- | --------------------------------- |
| `action`      | string  | Yes      | Must be `activity_changes`        |
| `date_from`   | date    | No       | Start date (YYYY-MM-DD)           |
| `date_to`     | date    | No       | End date (YYYY-MM-DD)             |
| `product_id`  | integer | No       | Filter by specific product ID     |
| `change_type` | string  | No       | `activated`, `deactivated`, `all` |

#### Response

```json
{
  "status": "success",
  "data": {
    "activity_changes": [
      {
        "id": 456,
        "product_id": 123,
        "sku_ozon": "SKU123456",
        "product_name": "Product Name",
        "previous_status": true,
        "new_status": false,
        "reason": "Stock depleted",
        "changed_at": "2025-01-16 13:45:00",
        "change_details": {
          "visibility": "VISIBLE",
          "state": "processed",
          "stock_present": 0,
          "has_pricing": true
        }
      }
    ],
    "total_count": 15,
    "summary": {
      "activated_today": 3,
      "deactivated_today": 12,
      "net_change": -9
    }
  },
  "timestamp": "2025-01-16T14:30:00Z"
}
```

#### Example Request

```bash
curl -X GET "https://your-domain.com/api/inventory-analytics.php?action=activity_changes&date_from=2025-01-15&change_type=deactivated"
```

### 4. Enhanced Inventory Endpoints

All existing inventory endpoints now support the `active_only` parameter.

#### Critical Stock (Enhanced)

**Endpoint**: `GET /api/inventory-analytics.php?action=critical_stock`

#### New Parameters

| Parameter     | Type    | Default | Description               |
| ------------- | ------- | ------- | ------------------------- |
| `active_only` | boolean | `true`  | Show only active products |

#### Response Changes

```json
{
    "status": "success",
    "data": {
        "critical_stock_products": [...],
        "summary": {
            "total_products_checked": 48,
            "critical_count": 12,
            "active_products_only": true,
            "filter_applied": "is_active = 1"
        }
    }
}
```

#### Low Stock (Enhanced)

**Endpoint**: `GET /api/inventory-analytics.php?action=low_stock`

Similar parameter and response enhancements as critical stock.

#### Overstock (Enhanced)

**Endpoint**: `GET /api/inventory-analytics.php?action=overstock`

Similar parameter and response enhancements as critical stock.

## Error Handling

### Error Response Format

```json
{
  "status": "error",
  "error": {
    "code": "INVALID_PARAMETER",
    "message": "Invalid action parameter",
    "details": "Action 'invalid_action' is not supported"
  },
  "timestamp": "2025-01-16T14:30:00Z"
}
```

### Common Error Codes

| Code                    | HTTP Status | Description                        |
| ----------------------- | ----------- | ---------------------------------- |
| `INVALID_PARAMETER`     | 400         | Invalid or missing parameter       |
| `UNAUTHORIZED`          | 401         | Authentication required            |
| `FORBIDDEN`             | 403         | Insufficient permissions           |
| `NOT_FOUND`             | 404         | Resource not found                 |
| `DATABASE_ERROR`        | 500         | Database connection or query error |
| `ACTIVITY_CHECK_FAILED` | 500         | Activity status check failed       |

## Rate Limiting

- **Rate Limit**: 100 requests per minute per IP
- **Burst Limit**: 10 requests per second
- **Headers**: Rate limit information included in response headers

```
X-RateLimit-Limit: 100
X-RateLimit-Remaining: 95
X-RateLimit-Reset: 1642348800
```

## Data Models

### Product Activity Model

```json
{
  "id": 123,
  "sku_ozon": "SKU123456",
  "product_name": "Product Name",
  "is_active": true,
  "activity_reason": "All criteria met",
  "activity_checked_at": "2025-01-16 14:00:00",
  "activity_criteria": {
    "visibility": "VISIBLE",
    "state": "processed",
    "stock_present": 5,
    "has_pricing": true
  },
  "created_at": "2025-01-01 10:00:00",
  "updated_at": "2025-01-16 14:00:00"
}
```

### Activity Change Log Model

```json
{
  "id": 456,
  "product_id": 123,
  "sku_ozon": "SKU123456",
  "previous_status": false,
  "new_status": true,
  "reason": "Stock replenished",
  "visibility": "VISIBLE",
  "state": "processed",
  "stock_present": 10,
  "has_pricing": true,
  "changed_at": "2025-01-16 13:30:00"
}
```

## Usage Examples

### JavaScript/AJAX

```javascript
// Get activity statistics
fetch("/api/inventory-analytics.php?action=activity_stats")
  .then((response) => response.json())
  .then((data) => {
    console.log("Active products:", data.data.active_products);
    console.log("Total products:", data.data.total_products);
  });

// Get inactive products
fetch("/api/inventory-analytics.php?action=inactive_products&limit=10")
  .then((response) => response.json())
  .then((data) => {
    data.data.inactive_products.forEach((product) => {
      console.log(`${product.sku_ozon}: ${product.activity_reason}`);
    });
  });
```

### PHP

```php
// Get activity statistics
$url = '/api/inventory-analytics.php?action=activity_stats';
$response = file_get_contents($url);
$data = json_decode($response, true);

if ($data['status'] === 'success') {
    $stats = $data['data'];
    echo "Active products: {$stats['active_products']}/{$stats['total_products']}\n";
    echo "Active percentage: {$stats['active_percentage']}%\n";
}

// Get recent activity changes
$url = '/api/inventory-analytics.php?action=activity_changes&date_from=' . date('Y-m-d', strtotime('-7 days'));
$response = file_get_contents($url);
$data = json_decode($response, true);

foreach ($data['data']['activity_changes'] as $change) {
    $status = $change['new_status'] ? 'activated' : 'deactivated';
    echo "{$change['sku_ozon']} was {$status}: {$change['reason']}\n";
}
```

### Python

```python
import requests
import json

# Get activity statistics
response = requests.get('/api/inventory-analytics.php?action=activity_stats')
data = response.json()

if data['status'] == 'success':
    stats = data['data']
    print(f"Active products: {stats['active_products']}/{stats['total_products']}")
    print(f"Active percentage: {stats['active_percentage']}%")

# Get inactive products
response = requests.get('/api/inventory-analytics.php?action=inactive_products&limit=20')
data = response.json()

for product in data['data']['inactive_products']:
    print(f"{product['sku_ozon']}: {product['activity_reason']}")
```

## Migration Guide

### Updating Existing API Calls

#### Before (Old API)

```javascript
// Old way - gets all 176 products
fetch("/api/inventory-analytics.php?action=critical_stock");
```

#### After (New API)

```javascript
// New way - gets only 48 active products (default behavior)
fetch("/api/inventory-analytics.php?action=critical_stock");

// Explicitly request all products (including inactive)
fetch("/api/inventory-analytics.php?action=critical_stock&active_only=false");
```

### Backward Compatibility

- All existing endpoints continue to work
- `active_only=true` is the new default behavior
- Set `active_only=false` to get old behavior (all products)
- Response format remains the same with additional metadata

## Performance Considerations

### Caching

- Activity statistics are cached for 5 minutes
- Product lists are cached for 2 minutes
- Activity changes are cached for 1 minute

### Database Optimization

- Queries use optimized indexes on `is_active` column
- Activity checks are batched for performance
- Historical data is automatically cleaned up

### Best Practices

1. **Use pagination** for large result sets
2. **Cache responses** on client side when appropriate
3. **Filter by date range** for activity changes to improve performance
4. **Use `active_only=true`** (default) for better performance

---

**API Documentation Version**: 1.0  
**Last Updated**: 2025-01-16  
**Requirements**: 4.3
