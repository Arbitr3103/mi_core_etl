# Warehouse Dashboard API Documentation

## Overview

The Warehouse Dashboard API provides endpoints for managing warehouse inventory with automated replenishment calculations based on sales history. The API calculates daily sales averages, liquidity status, and replenishment needs for each product at each warehouse location.

## Base URL

```
/api/warehouse
```

## Authentication

All endpoints require authentication using the existing system authentication mechanism. Include authentication credentials in your requests.

## Endpoints

### 1. Get Dashboard Data

Retrieve warehouse inventory data with calculated metrics and replenishment recommendations.

**Endpoint:** `GET /api/warehouse/dashboard`

**Query Parameters:**

| Parameter                | Type    | Required | Default              | Description                                                                                                 |
| ------------------------ | ------- | -------- | -------------------- | ----------------------------------------------------------------------------------------------------------- |
| `warehouse`              | string  | No       | -                    | Filter by specific warehouse name (e.g., "АДЫГЕЙСК_РФЦ")                                                    |
| `cluster`                | string  | No       | -                    | Filter by warehouse cluster (e.g., "Юг", "Урал")                                                            |
| `liquidity_status`       | string  | No       | -                    | Filter by liquidity status: `critical`, `low`, `normal`, `excess`                                           |
| `active_only`            | boolean | No       | `true`               | Show only active products (with sales in last 30 days OR stock > 0)                                         |
| `has_replenishment_need` | boolean | No       | `false`              | Show only products that need replenishment                                                                  |
| `sort_by`                | string  | No       | `replenishment_need` | Sort field: `name`, `warehouse_name`, `available`, `daily_sales_avg`, `days_of_stock`, `replenishment_need` |
| `sort_order`             | string  | No       | `desc`               | Sort direction: `asc` or `desc`                                                                             |
| `page`                   | integer | No       | `1`                  | Page number for pagination                                                                                  |
| `limit`                  | integer | No       | `100`                | Items per page (max: 500)                                                                                   |

**Example Request:**

```bash
GET /api/warehouse/dashboard?warehouse=АДЫГЕЙСК_РФЦ&liquidity_status=critical&active_only=true
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

| Field                      | Type    | Description                                     |
| -------------------------- | ------- | ----------------------------------------------- |
| `product_id`               | integer | Unique product identifier                       |
| `sku`                      | string  | Product SKU                                     |
| `name`                     | string  | Product name                                    |
| `warehouse_name`           | string  | Warehouse location name                         |
| `cluster`                  | string  | Warehouse cluster/region                        |
| `available`                | integer | Units available for sale                        |
| `reserved`                 | integer | Units reserved for orders                       |
| `preparing_for_sale`       | integer | Units being prepared for sale                   |
| `in_supply_requests`       | integer | Units in supply requests                        |
| `in_transit`               | integer | Units in transit to warehouse                   |
| `in_inspection`            | integer | Units undergoing inspection                     |
| `returning_from_customers` | integer | Units being returned                            |
| `expiring_soon`            | integer | Units with expiring shelf life                  |
| `defective`                | integer | Defective units                                 |
| `daily_sales_avg`          | float   | Average daily sales over 28 days                |
| `sales_last_28_days`       | integer | Total sales in last 28 days                     |
| `days_without_sales`       | integer | Consecutive days without sales                  |
| `days_of_stock`            | float   | Days until stock runs out (null if no sales)    |
| `liquidity_status`         | string  | Status: `critical`, `low`, `normal`, `excess`   |
| `target_stock`             | integer | Target stock for 30 days (daily_sales_avg × 30) |
| `replenishment_need`       | integer | Units needed to reach target stock              |
| `last_updated`             | string  | ISO 8601 timestamp of last data update          |

**Liquidity Status Definitions:**

-   **critical**: < 7 days of stock remaining
-   **low**: 7-14 days of stock remaining
-   **normal**: 15-45 days of stock remaining
-   **excess**: > 45 days of stock remaining

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
// Fetch dashboard data
const response = await fetch(
    "/api/warehouse/dashboard?active_only=true&liquidity_status=critical"
);
const data = await response.json();

if (data.success) {
    console.log("Total products:", data.data.summary.total_products);
    console.log("Critical items:", data.data.summary.by_liquidity.critical);
}

// Export to CSV
window.location.href = "/api/warehouse/export?warehouse=АДЫГЕЙСК_РФЦ";
```

### PHP

```php
$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, 'http://localhost/api/warehouse/dashboard?active_only=true');
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
$response = curl_exec($ch);
curl_close($ch);

$data = json_decode($response, true);
if ($data['success']) {
    echo "Total products: " . $data['data']['summary']['total_products'];
}
```

### Python

```python
import requests

response = requests.get('http://localhost/api/warehouse/dashboard', params={
    'active_only': 'true',
    'liquidity_status': 'critical'
})

data = response.json()
if data['success']:
    print(f"Total products: {data['data']['summary']['total_products']}")
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
