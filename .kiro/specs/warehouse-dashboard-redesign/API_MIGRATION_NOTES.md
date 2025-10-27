# API Migration Notes - Warehouse Dashboard Redesign

## Overview

This document provides technical details about the new API endpoints introduced for the warehouse dashboard redesign. It includes endpoint specifications, migration guidance, and compatibility notes.

**Task:** 6.4 Create deployment documentation  
**Requirements:** 6.1, 6.2, 6.3, 6.4, 6.5

## Table of Contents

1. [New API Endpoints](#new-api-endpoints)
2. [Database Changes](#database-changes)
3. [Breaking Changes](#breaking-changes)
4. [Migration Guide](#migration-guide)
5. [Performance Considerations](#performance-considerations)
6. [Caching Strategy](#caching-strategy)
7. [Error Handling](#error-handling)
8. [Backward Compatibility](#backward-compatibility)

## New API Endpoints

### 1. Get Detailed Inventory Stock

**Endpoint:** `GET /api/inventory/detailed-stock`

**Description:** Returns detailed product-warehouse level inventory data with calculated metrics, filtering, and sorting capabilities.

**Request Parameters:**

| Parameter                | Type    | Required | Description                                |
| ------------------------ | ------- | -------- | ------------------------------------------ |
| `warehouses[]`           | array   | No       | Filter by warehouse names                  |
| `warehouse`              | string  | No       | Filter by single warehouse                 |
| `statuses[]`             | array   | No       | Filter by status levels                    |
| `status`                 | string  | No       | Filter by single status                    |
| `search`                 | string  | No       | Search products by name/SKU                |
| `sort_by`                | string  | No       | Column to sort by (default: urgency_score) |
| `sort_order`             | string  | No       | Sort direction: asc/desc (default: desc)   |
| `limit`                  | integer | No       | Results per page (default: 100, max: 1000) |
| `offset`                 | integer | No       | Pagination offset (default: 0)             |
| `min_days_of_stock`      | float   | No       | Minimum days of stock filter               |
| `max_days_of_stock`      | float   | No       | Maximum days of stock filter               |
| `min_urgency_score`      | integer | No       | Minimum urgency score (0-100)              |
| `has_replenishment_need` | boolean | No       | Only products needing replenishment        |
| `active_only`            | boolean | No       | Only products with stock or recent sales   |

**Response Format:**

```json
{
    "success": true,
    "data": [
        {
            "productId": 12345,
            "productName": "Brake Pad Set Front",
            "sku": "BP-001",
            "skuOzon": "OZ-BP-001",
            "skuWb": "WB-BP-001",
            "skuInternal": "INT-BP-001",
            "warehouseName": "Коледино",
            "cluster": "Moscow",
            "marketplaceSource": "ozon",
            "currentStock": 45,
            "availableStock": 40,
            "dailySales": 3.2,
            "sales28d": 90,
            "daysOfStock": 14.1,
            "status": "low",
            "recommendedQty": 147,
            "recommendedValue": 14700.0,
            "urgencyScore": 75,
            "stockoutRisk": 65,
            "costPrice": 100.0,
            "currentStockValue": 4500.0,
            "turnoverRate": 25.9,
            "salesTrend": "stable",
            "lastUpdated": "2025-10-27T12:00:00+00:00",
            "lastSaleDate": "2025-10-26"
        }
    ],
    "metadata": {
        "totalCount": 1234,
        "filteredCount": 1,
        "timestamp": "2025-10-27T12:00:00+00:00",
        "processingTime": 45.23,
        "filters": {
            "warehouses": ["Коледино"],
            "statuses": ["low"]
        },
        "cached": false
    }
}
```

**Status Codes:**

-   `200 OK`: Success
-   `400 Bad Request`: Invalid parameters
-   `500 Internal Server Error`: Server error

**Example Requests:**

```bash
# Get all critical and low stock items
curl "https://api.example.com/api/inventory/detailed-stock?statuses[]=critical&statuses[]=low"

# Search for specific product
curl "https://api.example.com/api/inventory/detailed-stock?search=brake+pad"

# Get items for specific warehouse
curl "https://api.example.com/api/inventory/detailed-stock?warehouse=Коледино&limit=50"

# Get items needing replenishment
curl "https://api.example.com/api/inventory/detailed-stock?has_replenishment_need=true&sort_by=urgency_score&sort_order=desc"
```

### 2. Get Warehouse List

**Endpoint:** `GET /api/inventory/detailed-stock?action=warehouses`

**Description:** Returns list of all warehouses with summary statistics.

**Response Format:**

```json
{
    "success": true,
    "data": [
        {
            "warehouse_name": "Коледино",
            "product_count": 450,
            "total_stock": 12500,
            "critical_count": 23,
            "low_count": 67,
            "replenishment_needed_count": 90
        }
    ]
}
```

**Example Request:**

```bash
curl "https://api.example.com/api/inventory/detailed-stock?action=warehouses"
```

### 3. Get Summary Statistics

**Endpoint:** `GET /api/inventory/detailed-stock?action=summary`

**Description:** Returns overall inventory summary statistics.

**Response Format:**

```json
{
    "success": true,
    "data": {
        "totalProducts": 1234,
        "totalWarehouses": 8,
        "totalStock": 45678,
        "totalStockValue": 4567890.0,
        "statusCounts": {
            "critical": 45,
            "low": 123,
            "normal": 890,
            "excess": 156,
            "outOfStock": 20
        },
        "replenishmentNeededCount": 168,
        "totalReplenishmentValue": 1234567.0,
        "avgUrgencyScore": 42.5
    }
}
```

**Example Request:**

```bash
curl "https://api.example.com/api/inventory/detailed-stock?action=summary"
```

### 4. Get Cache Statistics

**Endpoint:** `GET /api/inventory/detailed-stock?action=cache-stats`

**Description:** Returns cache performance statistics (admin only).

**Response Format:**

```json
{
    "success": true,
    "data": {
        "hit_count": 1234,
        "miss_count": 456,
        "hit_ratio": 73.02,
        "total_size": "45.6 MB",
        "item_count": 234,
        "ttl_settings": {
            "inventory_short": 180,
            "inventory_medium": 600,
            "inventory_long": 1800
        }
    }
}
```

### 5. Clear Cache

**Endpoint:** `POST /api/inventory/detailed-stock?action=clear-cache`

**Description:** Clears all inventory cache (admin only).

**Response Format:**

```json
{
    "success": true,
    "message": "Cache cleared successfully"
}
```

## Database Changes

### New Database View: `v_detailed_inventory`

**Purpose:** Provides pre-calculated inventory metrics for efficient querying.

**Columns:**

| Column                | Type      | Description                 |
| --------------------- | --------- | --------------------------- |
| `product_id`          | integer   | Product identifier          |
| `product_name`        | varchar   | Product name                |
| `sku`                 | varchar   | Primary SKU                 |
| `sku_ozon`            | varchar   | Ozon marketplace SKU        |
| `sku_wb`              | varchar   | Wildberries marketplace SKU |
| `sku_internal`        | varchar   | Internal SKU                |
| `warehouse_name`      | varchar   | Warehouse name              |
| `cluster`             | varchar   | Warehouse cluster/region    |
| `marketplace_source`  | varchar   | Source marketplace          |
| `current_stock`       | integer   | Total current stock         |
| `available_stock`     | integer   | Available for sale          |
| `daily_sales_avg`     | numeric   | Average daily sales         |
| `sales_last_28_days`  | integer   | Sales in last 28 days       |
| `days_of_stock`       | numeric   | Days until stockout         |
| `stock_status`        | varchar   | Status level                |
| `recommended_qty`     | integer   | Recommended replenishment   |
| `recommended_value`   | numeric   | Value of recommendation     |
| `urgency_score`       | integer   | Priority score (0-100)      |
| `stockout_risk`       | integer   | Risk score (0-100)          |
| `cost_price`          | numeric   | Unit cost                   |
| `current_stock_value` | numeric   | Total stock value           |
| `turnover_rate`       | numeric   | Annual turnover rate        |
| `sales_trend`         | varchar   | Trend indicator             |
| `last_updated`        | timestamp | Last update time            |
| `last_sale_date`      | date      | Last sale date              |

**View Definition:**

```sql
CREATE OR REPLACE VIEW v_detailed_inventory AS
WITH inventory_with_stock AS (
    SELECT
        i.product_id,
        i.warehouse_name,
        (COALESCE(i.quantity_present, 0) +
         COALESCE(i.quantity_reserved, 0) +
         COALESCE(i.preparing_for_sale, 0)) as current_stock,
        COALESCE(i.quantity_present, 0) as available_stock,
        i.last_updated
    FROM inventory i
    WHERE (COALESCE(i.quantity_present, 0) +
           COALESCE(i.quantity_reserved, 0) +
           COALESCE(i.preparing_for_sale, 0)) > 0
       OR EXISTS (
           SELECT 1 FROM warehouse_sales_metrics wsm
           WHERE wsm.product_id = i.product_id
             AND wsm.warehouse_name = i.warehouse_name
             AND wsm.sales_last_28_days > 0
       )
),
sales_metrics AS (
    SELECT
        product_id,
        warehouse_name,
        daily_sales_avg,
        sales_last_7_days,
        sales_last_28_days,
        sales_trend
    FROM warehouse_sales_metrics
    WHERE daily_sales_avg > 0 OR sales_last_28_days > 0
)
SELECT
    p.id as product_id,
    p.product_name,
    p.sku,
    p.sku_ozon,
    p.sku_wb,
    p.sku_internal,
    iws.warehouse_name,
    w.cluster,
    p.marketplace_source,
    iws.current_stock,
    iws.available_stock,
    COALESCE(sm.daily_sales_avg, 0) as daily_sales_avg,
    COALESCE(sm.sales_last_28_days, 0) as sales_last_28_days,
    CASE
        WHEN sm.daily_sales_avg > 0 THEN
            ROUND(iws.current_stock / sm.daily_sales_avg, 1)
        ELSE NULL
    END as days_of_stock,
    CASE
        WHEN sm.daily_sales_avg = 0 OR sm.daily_sales_avg IS NULL THEN 'no_sales'
        WHEN (iws.current_stock / sm.daily_sales_avg) < 14 THEN 'critical'
        WHEN (iws.current_stock / sm.daily_sales_avg) < 30 THEN 'low'
        WHEN (iws.current_stock / sm.daily_sales_avg) < 60 THEN 'normal'
        ELSE 'excess'
    END as stock_status,
    GREATEST(0, ROUND(sm.daily_sales_avg * 60, 0) - iws.current_stock) as recommended_qty,
    GREATEST(0, ROUND(sm.daily_sales_avg * 60, 0) - iws.current_stock) * COALESCE(p.cost_price, 0) as recommended_value,
    CASE
        WHEN sm.daily_sales_avg = 0 OR sm.daily_sales_avg IS NULL THEN 0
        WHEN (iws.current_stock / sm.daily_sales_avg) < 7 THEN 100
        WHEN (iws.current_stock / sm.daily_sales_avg) < 14 THEN 80
        WHEN (iws.current_stock / sm.daily_sales_avg) < 21 THEN 60
        WHEN (iws.current_stock / sm.daily_sales_avg) < 30 THEN 40
        ELSE 20
    END as urgency_score,
    CASE
        WHEN sm.daily_sales_avg = 0 OR sm.daily_sales_avg IS NULL THEN 0
        WHEN (iws.current_stock / sm.daily_sales_avg) < 7 THEN 90
        WHEN (iws.current_stock / sm.daily_sales_avg) < 14 THEN 70
        WHEN (iws.current_stock / sm.daily_sales_avg) < 21 THEN 50
        WHEN (iws.current_stock / sm.daily_sales_avg) < 30 THEN 30
        ELSE 10
    END as stockout_risk,
    p.cost_price,
    iws.current_stock * COALESCE(p.cost_price, 0) as current_stock_value,
    CASE
        WHEN sm.daily_sales_avg > 0 THEN
            ROUND((sm.daily_sales_avg * 365) / NULLIF(iws.current_stock, 0), 2)
        ELSE 0
    END as turnover_rate,
    COALESCE(sm.sales_trend, 'stable') as sales_trend,
    iws.last_updated,
    (SELECT MAX(sale_date) FROM sales WHERE product_id = p.id) as last_sale_date
FROM inventory_with_stock iws
JOIN products p ON iws.product_id = p.id
LEFT JOIN sales_metrics sm ON iws.product_id = sm.product_id
    AND iws.warehouse_name = sm.warehouse_name
LEFT JOIN warehouses w ON iws.warehouse_name = w.name;
```

### New Indexes

**Purpose:** Optimize query performance for filtering and sorting.

```sql
-- Index on warehouse_name for warehouse filtering
CREATE INDEX IF NOT EXISTS idx_inventory_warehouse_name
ON inventory(warehouse_name);

-- Index on product_id for joins
CREATE INDEX IF NOT EXISTS idx_inventory_product_id
ON inventory(product_id);

-- Composite index for common queries
CREATE INDEX IF NOT EXISTS idx_inventory_warehouse_product
ON inventory(warehouse_name, product_id);

-- Index on sales metrics for performance
CREATE INDEX IF NOT EXISTS idx_warehouse_sales_metrics_product_warehouse
ON warehouse_sales_metrics(product_id, warehouse_name);

-- Index on daily_sales_avg for filtering
CREATE INDEX IF NOT EXISTS idx_warehouse_sales_metrics_daily_sales
ON warehouse_sales_metrics(daily_sales_avg);
```

## Breaking Changes

### None

The new API endpoints are **additive only**. No existing endpoints are modified or removed, ensuring full backward compatibility.

### Deprecation Notice

The following endpoints are **not deprecated** but may be in future versions:

-   `/api/inventory/warehouse-summary` - Consider migrating to new detailed endpoint
-   `/api/inventory/critical-products` - Use detailed endpoint with status filter instead

## Migration Guide

### For Frontend Developers

#### Old API Pattern

```javascript
// Old way - warehouse-level aggregation
const response = await fetch("/api/inventory/warehouse-summary");
const data = await response.json();

// Returns warehouse-level data
data.warehouses.forEach((warehouse) => {
    console.log(warehouse.name, warehouse.critical_count);
});
```

#### New API Pattern

```javascript
// New way - product-level detail
const response = await fetch(
    "/api/inventory/detailed-stock?statuses[]=critical"
);
const data = await response.json();

// Returns product-warehouse pairs
data.data.forEach((item) => {
    console.log(item.productName, item.warehouseName, item.daysOfStock);
});
```

### For Backend Developers

#### Integrating New Endpoint

```php
<?php
// Include the new service
require_once __DIR__ . '/api/classes/DetailedInventoryService.php';
require_once __DIR__ . '/api/classes/EnhancedCacheService.php';

// Initialize services
$pdo = getDatabaseConnection();
$cache = new EnhancedCacheService($pdo);
$service = new DetailedInventoryService($pdo, $cache);

// Get detailed inventory with filters
$filters = [
    'warehouses' => ['Коледино', 'Тверь'],
    'statuses' => ['critical', 'low'],
    'limit' => 100
];

$result = $service->getDetailedInventory($filters);

// Use the data
foreach ($result['data'] as $item) {
    // Process each product-warehouse pair
    processReplenishment($item);
}
?>
```

### Migration Checklist

-   [ ] Review new API documentation
-   [ ] Test new endpoints in development
-   [ ] Update frontend to use new endpoints
-   [ ] Verify data accuracy
-   [ ] Test performance with production data volume
-   [ ] Update monitoring and alerts
-   [ ] Train users on new features
-   [ ] Plan deprecation of old endpoints (future)

## Performance Considerations

### Query Performance

**Optimizations Implemented:**

1. **Database View:** Pre-calculated metrics reduce computation
2. **Indexes:** Strategic indexes on filter columns
3. **Pagination:** Limit results to prevent large responses
4. **Caching:** Intelligent caching with TTL

**Performance Targets:**

-   API response time: < 500ms (p95)
-   Database query time: < 200ms
-   Cache hit ratio: > 70%
-   Concurrent requests: 100+ per second

### Caching Strategy

**Cache Layers:**

1. **Application Cache:** File-based or Redis
2. **Database Cache:** PostgreSQL query cache
3. **CDN Cache:** Static assets (frontend)

**Cache TTL:**

-   Inventory data (simple queries): 3 minutes
-   Inventory data (complex queries): 10 minutes
-   Warehouse list: 2 hours
-   Summary statistics: 5 minutes

**Cache Invalidation:**

-   Automatic: Based on TTL
-   Manual: Clear cache endpoint
-   Event-based: On data updates (future)

### Scaling Considerations

**Horizontal Scaling:**

-   API servers: Load balanced
-   Database: Read replicas for queries
-   Cache: Redis cluster (optional)

**Vertical Scaling:**

-   Database: Increase resources for view materialization
-   API servers: Increase memory for caching

## Error Handling

### Error Response Format

```json
{
  "success": false,
  "error": {
    "code": "INVALID_PARAMETER",
    "message": "Invalid sort_by parameter",
    "details": {
      "parameter": "sort_by",
      "value": "invalid_column",
      "allowed_values": ["product_name", "warehouse_name", ...]
    }
  },
  "timestamp": "2025-10-27T12:00:00+00:00"
}
```

### Error Codes

| Code                | HTTP Status | Description                |
| ------------------- | ----------- | -------------------------- |
| `INVALID_PARAMETER` | 400         | Invalid request parameter  |
| `MISSING_PARAMETER` | 400         | Required parameter missing |
| `DATABASE_ERROR`    | 500         | Database query failed      |
| `CACHE_ERROR`       | 500         | Cache operation failed     |
| `INTERNAL_ERROR`    | 500         | Unexpected server error    |

### Error Handling Best Practices

```javascript
// Frontend error handling
async function fetchInventory(filters) {
    try {
        const response = await fetch("/api/inventory/detailed-stock", {
            method: "GET",
            headers: { "Content-Type": "application/json" },
        });

        if (!response.ok) {
            const error = await response.json();
            throw new Error(error.error.message);
        }

        return await response.json();
    } catch (error) {
        console.error("Failed to fetch inventory:", error);
        // Show user-friendly error message
        showErrorNotification(
            "Failed to load inventory data. Please try again."
        );
        return null;
    }
}
```

## Backward Compatibility

### Compatibility Matrix

| Old Endpoint                       | New Endpoint                                        | Status     |
| ---------------------------------- | --------------------------------------------------- | ---------- |
| `/api/inventory/warehouse-summary` | `/api/inventory/detailed-stock?action=summary`      | Compatible |
| `/api/inventory/critical-products` | `/api/inventory/detailed-stock?statuses[]=critical` | Compatible |
| `/api/inventory/warehouse-details` | `/api/inventory/detailed-stock?warehouse=X`         | Compatible |

### Transition Period

-   **Phase 1 (Current):** Both old and new endpoints available
-   **Phase 2 (3 months):** Deprecation warnings added to old endpoints
-   **Phase 3 (6 months):** Old endpoints marked as deprecated
-   **Phase 4 (12 months):** Old endpoints removed (tentative)

### Version Header

All API responses include version information:

```http
X-API-Version: 2.0
X-API-Deprecated: false
```

## Support and Resources

### Documentation

-   **API Reference:** `/docs/api/inventory`
-   **User Guide:** See USER_GUIDE.md
-   **Deployment Guide:** See DEPLOYMENT_GUIDE.md

### Support Channels

-   **Technical Support:** [email]
-   **API Issues:** [GitHub Issues]
-   **Feature Requests:** [feedback form]

### Changelog

**Version 2.0.0** (2025-10-27)

-   Added `/api/inventory/detailed-stock` endpoint
-   Added `v_detailed_inventory` database view
-   Added performance indexes
-   Added caching layer
-   Added feature flag support

---

**Last Updated:** October 27, 2025  
**API Version:** 2.0.0  
**Document Owner:** API Team
