# Warehouse Dashboard API Documentation

## Overview

The Warehouse Dashboard API provides endpoints for managing warehouse inventory with automated replenishment calculations based on sales history. The API calculates daily sales averages, liquidity status, and replenishment needs for each product at each warehouse location.

## Base URL

```
/api/warehouse
```

## Authentication

All endpoints require authentication using the existing system authentication mechanism. Include authentication credentials in your requests.

## Default Filtering Behavior

**Important:** By default, the API returns only products that are truly "in sale" - meaning products that are visible in the marketplace and have available stock. This ensures accurate inventory management and prevents confusion from archived or out-of-stock items.

### What Gets Filtered Out by Default

The API automatically excludes:

-   **Archived or Hidden Products**: Products with `stock_status = 'archived_or_hidden'` (products not visible in marketplace)
-   **Out of Stock Products**: Products with `stock_status = 'out_of_stock'` (products with zero available stock)

### Including Hidden Products

To see all products including hidden and out-of-stock items, use the `include_hidden=true` parameter:

```bash
GET /api/inventory/detailed-stock?include_hidden=true
```

This is useful for:

-   Auditing complete inventory
-   Reviewing archived products
-   Analyzing historical product data

## Endpoints

### 1. Get Detailed Inventory Stock

Retrieve detailed inventory data with calculated metrics, stock status, and visibility information.

**Endpoint:** `GET /api/inventory/detailed-stock`

**Query Parameters:**

| Parameter                | Type    | Required | Default              | Description                                                                                                 |
| ------------------------ | ------- | -------- | -------------------- | ----------------------------------------------------------------------------------------------------------- |
| `warehouse`              | string  | No       | -                    | Filter by specific warehouse name (e.g., "АДЫГЕЙСК_РФЦ")                                                    |
| `cluster`                | string  | No       | -                    | Filter by warehouse cluster (e.g., "Юг", "Урал")                                                            |
| `liquidity_status`       | string  | No       | -                    | Filter by liquidity status: `critical`, `low`, `normal`, `excess`                                           |
| `active_only`            | boolean | No       | `true`               | Show only active products (with sales in last 30 days OR stock > 0)                                         |
| `has_replenishment_need` | boolean | No       | `false`              | Show only products that need replenishment                                                                  |
| `include_hidden`         | boolean | No       | `false`              | **NEW:** Include archived/hidden and out-of-stock products (default excludes them)                          |
| `visibility`             | string  | No       | -                    | **NEW:** Filter by visibility status: `VISIBLE`, `HIDDEN`, `MODERATION`, `UNKNOWN`                          |
| `sort_by`                | string  | No       | `replenishment_need` | Sort field: `name`, `warehouse_name`, `available`, `daily_sales_avg`, `days_of_stock`, `replenishment_need` |
| `sort_order`             | string  | No       | `desc`               | Sort direction: `asc` or `desc`                                                                             |
| `page`                   | integer | No       | `1`                  | Page number for pagination                                                                                  |
| `limit`                  | integer | No       | `100`                | Items per page (max: 500)                                                                                   |

**Example Requests:**

```bash
# Default behavior - only visible products with stock
GET /api/inventory/detailed-stock?warehouse=АДЫГЕЙСК_РФЦ&liquidity_status=critical&active_only=true

# Include hidden and out-of-stock products
GET /api/inventory/detailed-stock?warehouse=АДЫГЕЙСК_РФЦ&include_hidden=true

# Filter by specific visibility status
GET /api/inventory/detailed-stock?visibility=VISIBLE

# Get only hidden products
GET /api/inventory/detailed-stock?visibility=HIDDEN&include_hidden=true
```

**Response:**

```json
{
    "success": true,
    "data": {
        "warehouses": [
            {
                "warehouse_name": "АДЫГЕЙСК_РФЦ",
                "cluster": "Юг",
                "items": [
                    {
                        "product_id": 123,
                        "sku": "SKU-001",
                        "name": "Product Name",
                        "warehouse_name": "АДЫГЕЙСК_РФЦ",
                        "cluster": "Юг",
                        "available": 50,
                        "reserved": 5,
                        "preparing_for_sale": 10,
                        "in_supply_requests": 0,
                        "in_transit": 20,
                        "in_inspection": 0,
                        "returning_from_customers": 2,
                        "expiring_soon": 0,
                        "defective": 0,
                        "excess_from_supply": 0,
                        "awaiting_upd": 0,
                        "preparing_for_removal": 0,
                        "daily_sales_avg": 3.5,
                        "sales_last_28_days": 98,
                        "days_without_sales": 0,
                        "days_of_stock": 14.29,
                        "liquidity_status": "low",
                        "target_stock": 105,
                        "replenishment_need": 35,
                        "last_updated": "2025-10-22T12:00:00Z"
                    }
                ],
                "totals": {
                    "total_items": 50,
                    "total_available": 1000,
                    "total_replenishment_need": 500
                }
            }
        ],
        "summary": {
            "total_products": 271,
            "active_products": 180,
            "total_replenishment_need": 5000,
            "by_liquidity": {
                "critical": 15,
                "low": 30,
                "normal": 120,
                "excess": 15
            }
        },
        "filters_applied": {
            "warehouse": "АДЫГЕЙСК_РФЦ",
            "liquidity_status": "critical",
            "active_only": true
        },
        "pagination": {
            "current_page": 1,
            "total_pages": 5,
            "total_items": 180,
            "items_per_page": 100
        },
        "last_updated": "2025-10-22T12:00:00Z"
    }
}
```

**Response Fields:**

| Field                      | Type    | Description                                                                                               |
| -------------------------- | ------- | --------------------------------------------------------------------------------------------------------- |
| `product_id`               | integer | Unique product identifier                                                                                 |
| `sku`                      | string  | Product SKU                                                                                               |
| `name`                     | string  | Product name                                                                                              |
| `warehouse_name`           | string  | Warehouse location name                                                                                   |
| `cluster`                  | string  | Warehouse cluster/region                                                                                  |
| `visibility`               | string  | **NEW:** Product visibility status: `VISIBLE`, `HIDDEN`, `MODERATION`, `UNKNOWN`                          |
| `available`                | integer | Units available for sale                                                                                  |
| `reserved`                 | integer | Units reserved for orders                                                                                 |
| `preparing_for_sale`       | integer | Units being prepared for sale                                                                             |
| `in_supply_requests`       | integer | Units in supply requests                                                                                  |
| `in_transit`               | integer | Units in transit to warehouse                                                                             |
| `in_inspection`            | integer | Units undergoing inspection                                                                               |
| `returning_from_customers` | integer | Units being returned                                                                                      |
| `expiring_soon`            | integer | Units with expiring shelf life                                                                            |
| `defective`                | integer | Defective units                                                                                           |
| `daily_sales_avg`          | float   | Average daily sales over 28 days                                                                          |
| `sales_last_28_days`       | integer | Total sales in last 28 days                                                                               |
| `days_without_sales`       | integer | Consecutive days without sales                                                                            |
| `days_of_stock`            | float   | Days until stock runs out (null if no sales)                                                              |
| `stock_status`             | string  | **UPDATED:** Combined status: `archived_or_hidden`, `out_of_stock`, `critical`, `low`, `normal`, `excess` |
| `target_stock`             | integer | Target stock for 30 days (daily_sales_avg × 30)                                                           |
| `replenishment_need`       | integer | Units needed to reach target stock                                                                        |
| `last_updated`             | string  | ISO 8601 timestamp of last data update                                                                    |

**Stock Status Definitions:**

The `stock_status` field combines visibility and availability information:

-   **archived_or_hidden**: Product is not visible in marketplace (visibility != VISIBLE)
-   **out_of_stock**: Product is visible but has zero available stock
-   **critical**: < 14 days of stock remaining (visible product with low stock)
-   **low**: 14-30 days of stock remaining
-   **normal**: 30-60 days of stock remaining
-   **excess**: > 60 days of stock remaining

**Visibility Status Values:**

-   **VISIBLE**: Product is active and visible in marketplace
-   **HIDDEN**: Product is archived or hidden from marketplace
-   **MODERATION**: Product is under moderation review
-   **UNKNOWN**: Visibility status is not determined

**Error Responses:**

```json
{
    "success": false,
    "error": "Invalid liquidity_status parameter",
    "code": 400
}
```

---

### 2. Export to CSV

Export warehouse dashboard data to CSV format with the same filtering options.

**Endpoint:** `GET /api/warehouse/export`

**Query Parameters:**

Same as `/api/warehouse/dashboard` endpoint.

**Example Request:**

```bash
GET /api/warehouse/export?warehouse=АДЫГЕЙСК_РФЦ&liquidity_status=critical
```

**Response:**

CSV file download with filename: `warehouse_dashboard_YYYY-MM-DD_HH-MM-SS.csv`

**CSV Columns:**

```
Product ID,SKU,Product Name,Warehouse,Cluster,Available,Reserved,In Transit,Daily Sales Avg,Sales Last 28 Days,Days of Stock,Liquidity Status,Target Stock,Replenishment Need,Last Updated
```

**Example CSV Content:**

```csv
Product ID,SKU,Product Name,Warehouse,Cluster,Available,Reserved,In Transit,Daily Sales Avg,Sales Last 28 Days,Days of Stock,Liquidity Status,Target Stock,Replenishment Need,Last Updated
123,SKU-001,Product Name,АДЫГЕЙСК_РФЦ,Юг,50,5,20,3.5,98,14.29,low,105,35,2025-10-22 12:00:00
```

---

### 3. Get Warehouses List

Retrieve a list of all available warehouses.

**Endpoint:** `GET /api/warehouse/warehouses`

**Query Parameters:** None

**Example Request:**

```bash
GET /api/warehouse/warehouses
```

**Response:**

```json
{
    "success": true,
    "data": [
        {
            "name": "АДЫГЕЙСК_РФЦ",
            "cluster": "Юг",
            "product_count": 45
        },
        {
            "name": "Ростов-на-Дону_РФЦ",
            "cluster": "Юг",
            "product_count": 38
        },
        {
            "name": "Екатеринбург_РФЦ",
            "cluster": "Урал",
            "product_count": 52
        }
    ]
}
```

---

### 4. Get Clusters List

Retrieve a list of all warehouse clusters.

**Endpoint:** `GET /api/warehouse/clusters`

**Query Parameters:** None

**Example Request:**

```bash
GET /api/warehouse/clusters
```

**Response:**

```json
{
    "success": true,
    "data": [
        {
            "name": "Юг",
            "warehouse_count": 5,
            "product_count": 120
        },
        {
            "name": "Урал",
            "warehouse_count": 3,
            "product_count": 85
        },
        {
            "name": "Центр",
            "warehouse_count": 8,
            "product_count": 200
        }
    ]
}
```

---

## Filtering Behavior Details

### Default Filtering Logic

By default, the API implements smart filtering to show only products that are truly "in sale":

```sql
-- Default WHERE clause (when include_hidden is not set or false)
WHERE stock_status NOT IN ('archived_or_hidden', 'out_of_stock')
```

This means:

1. **Only visible products** are returned (products with visibility = 'VISIBLE')
2. **Only products with available stock** are returned (available_stock > 0)

### Use Cases

#### 1. Standard Inventory Management (Default)

```bash
GET /api/inventory/detailed-stock
```

Returns only products that are:

-   Visible in marketplace
-   Have available stock
-   Are truly "in sale"

**Use for:** Daily inventory management, replenishment planning, sales analysis

#### 2. Complete Inventory Audit

```bash
GET /api/inventory/detailed-stock?include_hidden=true
```

Returns all products including:

-   Hidden/archived products
-   Out of stock products
-   Products under moderation

**Use for:** Complete inventory audits, historical analysis, product lifecycle management

#### 3. Specific Visibility Filtering

```bash
GET /api/inventory/detailed-stock?visibility=HIDDEN&include_hidden=true
```

Returns only hidden products.

**Use for:** Reviewing archived products, cleanup operations, reactivation planning

#### 4. Out of Stock Analysis

```bash
GET /api/inventory/detailed-stock?statuses[]=out_of_stock&include_hidden=true
```

Returns only out-of-stock products.

**Use for:** Stockout analysis, urgent replenishment identification

### Backward Compatibility

Existing API consumers will automatically benefit from the new filtering:

-   Default behavior excludes hidden and out-of-stock items
-   Response format remains unchanged (new fields added, existing fields preserved)
-   All existing query parameters continue to work

To maintain old behavior (show all products), add `include_hidden=true` to requests.

## Calculation Formulas

### Daily Sales Average

```
daily_sales_avg = total_sales_last_28_days / days_with_stock
```

Where `days_with_stock` is the number of days the product had stock > 0 in the last 28 days.

### Target Stock

```
target_stock = daily_sales_avg × 30
```

Target stock represents the amount needed for 30 days of sales.

### Replenishment Need

```
replenishment_need = max(0, target_stock - (available + in_transit + in_supply_requests))
```

Replenishment need is the difference between target stock and total available/incoming stock.

### Days of Stock

```
days_of_stock = available / daily_sales_avg
```

If `daily_sales_avg = 0`, then `days_of_stock = null` (infinite).

### Liquidity Status

```
if days_of_stock < 7:
    liquidity_status = "critical"
elif days_of_stock < 15:
    liquidity_status = "low"
elif days_of_stock <= 45:
    liquidity_status = "normal"
else:
    liquidity_status = "excess"
```

---

## Data Refresh

Warehouse metrics are calculated and cached hourly via a cron job. The `last_updated` timestamp indicates when the metrics were last calculated.

To manually refresh metrics, run:

```bash
php scripts/refresh_warehouse_metrics.php
```

See [Warehouse Metrics Cron Setup](WAREHOUSE_METRICS_CRON_SETUP.md) for automated refresh configuration.

---

## Rate Limiting

API endpoints are rate-limited to prevent abuse:

-   **Rate Limit**: 60 requests per minute per IP
-   **Burst Limit**: 10 requests per second

Rate limit headers are included in responses:

```
X-RateLimit-Limit: 60
X-RateLimit-Remaining: 45
X-RateLimit-Reset: 1634567890
```

---

## Error Codes

| Code | Description                             |
| ---- | --------------------------------------- |
| 200  | Success                                 |
| 400  | Bad Request - Invalid parameters        |
| 401  | Unauthorized - Authentication required  |
| 403  | Forbidden - Insufficient permissions    |
| 404  | Not Found - Resource not found          |
| 429  | Too Many Requests - Rate limit exceeded |
| 500  | Internal Server Error                   |

---

## Example Usage

### JavaScript/TypeScript

```typescript
// Fetch dashboard data (default - only visible products with stock)
const response = await fetch(
    "/api/inventory/detailed-stock?active_only=true&liquidity_status=critical"
);
const data = await response.json();

if (data.success) {
    console.log("Total products:", data.data.metadata.totalCount);
    console.log("Filtered count:", data.data.metadata.filteredCount);

    // Access visibility information
    data.data.data.forEach((item) => {
        console.log(`${item.productName}: ${item.visibility} - ${item.status}`);
    });
}

// Fetch all products including hidden ones
const allProductsResponse = await fetch(
    "/api/inventory/detailed-stock?include_hidden=true"
);
const allData = await allProductsResponse.json();

// Filter by specific visibility
const hiddenProductsResponse = await fetch(
    "/api/inventory/detailed-stock?visibility=HIDDEN&include_hidden=true"
);
const hiddenData = await hiddenProductsResponse.json();

// Export to CSV
window.location.href = "/api/warehouse/export?warehouse=АДЫГЕЙСК_РФЦ";
```

### PHP

```php
// Default behavior - only visible products with stock
$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, 'http://localhost/api/inventory/detailed-stock?active_only=true');
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
$response = curl_exec($ch);
curl_close($ch);

$data = json_decode($response, true);
if ($data['success']) {
    echo "Total products: " . $data['data']['metadata']['totalCount'] . "\n";

    // Check visibility status
    foreach ($data['data']['data'] as $item) {
        echo "{$item['productName']}: {$item['visibility']} - {$item['status']}\n";
    }
}

// Include hidden products
$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, 'http://localhost/api/inventory/detailed-stock?include_hidden=true');
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
$response = curl_exec($ch);
curl_close($ch);

$allData = json_decode($response, true);
if ($allData['success']) {
    echo "All products (including hidden): " . $allData['data']['metadata']['totalCount'] . "\n";
}
```

### Python

```python
import requests

# Default behavior - only visible products with stock
response = requests.get('http://localhost/api/inventory/detailed-stock', params={
    'active_only': 'true',
    'liquidity_status': 'critical'
})

data = response.json()
if data['success']:
    print(f"Total products: {data['data']['metadata']['totalCount']}")

    # Check visibility status
    for item in data['data']['data']:
        print(f"{item['productName']}: {item['visibility']} - {item['status']}")

# Include hidden products
all_response = requests.get('http://localhost/api/inventory/detailed-stock', params={
    'include_hidden': 'true'
})

all_data = all_response.json()
if all_data['success']:
    print(f"All products (including hidden): {all_data['data']['metadata']['totalCount']}")

# Get only hidden products
hidden_response = requests.get('http://localhost/api/inventory/detailed-stock', params={
    'visibility': 'HIDDEN',
    'include_hidden': 'true'
})

hidden_data = hidden_response.json()
if hidden_data['success']:
    print(f"Hidden products: {hidden_data['data']['metadata']['totalCount']}")
```

---

## Performance Considerations

-   **Caching**: Metrics are cached for 1 hour to improve performance
-   **Pagination**: Use pagination for large datasets (default: 100 items per page)
-   **Filtering**: Apply filters to reduce response size
-   **Indexes**: Database queries are optimized with appropriate indexes

---

## Support

For technical issues or questions:

-   Check the [Warehouse Dashboard User Guide](WAREHOUSE_DASHBOARD_USER_GUIDE.md)
-   Review the [Troubleshooting Guide](../TROUBLESHOOTING_GUIDE.md)
-   Contact system administrator

---

**Version**: 1.0.0  
**Last Updated**: October 2025
