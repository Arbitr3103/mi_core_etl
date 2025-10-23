# Warehouse Dashboard API - Quick Start Guide

## Quick Reference

### Base URL

```
/api/warehouse-dashboard.php
```

### Available Actions

| Action       | Description        | Returns                   |
| ------------ | ------------------ | ------------------------- |
| `dashboard`  | Get dashboard data | JSON with warehouse items |
| `export`     | Export to CSV      | CSV file download         |
| `warehouses` | List warehouses    | JSON with warehouse list  |
| `clusters`   | List clusters      | JSON with cluster list    |

## Common Use Cases

### 1. Get All Active Products

```bash
curl "http://localhost/api/warehouse-dashboard.php?action=dashboard&active_only=true"
```

### 2. Get Critical Stock Items

```bash
curl "http://localhost/api/warehouse-dashboard.php?action=dashboard&liquidity_status=critical&sort_by=days_of_stock&sort_order=asc"
```

### 3. Get Items Needing Replenishment

```bash
curl "http://localhost/api/warehouse-dashboard.php?action=dashboard&has_replenishment_need=true&sort_by=replenishment_need&sort_order=desc"
```

### 4. Filter by Warehouse

```bash
curl "http://localhost/api/warehouse-dashboard.php?action=dashboard&warehouse=АДЫГЕЙСК_РФЦ"
```

### 5. Filter by Cluster

```bash
curl "http://localhost/api/warehouse-dashboard.php?action=dashboard&cluster=Юг"
```

### 6. Export to CSV

```bash
curl "http://localhost/api/warehouse-dashboard.php?action=export&active_only=true" -o warehouse_data.csv
```

### 7. Get Top Sellers

```bash
curl "http://localhost/api/warehouse-dashboard.php?action=dashboard&sort_by=daily_sales_avg&sort_order=desc&limit=20"
```

### 8. Get Warehouse List

```bash
curl "http://localhost/api/warehouse-dashboard.php?action=warehouses"
```

### 9. Get Cluster List

```bash
curl "http://localhost/api/warehouse-dashboard.php?action=clusters"
```

## Filter Parameters

### Basic Filters

```bash
# Active products only (default: true)
?active_only=true

# Specific warehouse
?warehouse=АДЫГЕЙСК_РФЦ

# Specific cluster
?cluster=Юг

# Liquidity status (critical, low, normal, excess)
?liquidity_status=critical

# Only items needing replenishment
?has_replenishment_need=true
```

### Sorting

```bash
# Sort by field (default: replenishment_need)
?sort_by=daily_sales_avg

# Sort direction (default: desc)
?sort_order=asc

# Available sort fields:
# - product_name
# - warehouse_name
# - available
# - daily_sales_avg
# - days_of_stock
# - replenishment_need
# - days_without_sales
```

### Pagination

```bash
# Limit results (default: 100, max: 1000)
?limit=50

# Offset for pagination (default: 0)
?offset=100
```

## Combining Filters

You can combine multiple filters:

```bash
curl "http://localhost/api/warehouse-dashboard.php?action=dashboard&warehouse=АДЫГЕЙСК_РФЦ&liquidity_status=critical&active_only=true&sort_by=replenishment_need&sort_order=desc&limit=20"
```

## Response Format

### Success Response

```json
{
  "success": true,
  "data": {
    "warehouses": [...],
    "summary": {...},
    "filters_applied": {...},
    "pagination": {...},
    "last_updated": "2025-10-22T12:00:00Z"
  }
}
```

### Error Response

```json
{
    "success": false,
    "error": "Error message here"
}
```

## Status Codes

| Code | Meaning                                 |
| ---- | --------------------------------------- |
| 200  | Success                                 |
| 400  | Bad Request (invalid parameters)        |
| 405  | Method Not Allowed (only GET supported) |
| 500  | Internal Server Error                   |

## Liquidity Status Values

| Status     | Days of Stock | Color  | Priority |
| ---------- | ------------- | ------ | -------- |
| `critical` | < 7 days      | Red    | Urgent   |
| `low`      | 7-14 days     | Yellow | High     |
| `normal`   | 15-45 days    | Green  | Normal   |
| `excess`   | > 45 days     | Blue   | Low      |

## Testing with curl

### Basic Test

```bash
# Test if API is working
curl "http://localhost/api/warehouse-dashboard.php?action=dashboard&limit=1"
```

### Pretty Print JSON

```bash
# Use jq for pretty printing
curl "http://localhost/api/warehouse-dashboard.php?action=dashboard&limit=5" | jq '.'
```

### Save Response to File

```bash
# Save JSON response
curl "http://localhost/api/warehouse-dashboard.php?action=dashboard" -o response.json

# Save CSV export
curl "http://localhost/api/warehouse-dashboard.php?action=export" -o export.csv
```

### Check Response Headers

```bash
curl -I "http://localhost/api/warehouse-dashboard.php?action=dashboard"
```

## JavaScript/Fetch Example

```javascript
// Get dashboard data
async function getDashboardData() {
    const response = await fetch(
        "/api/warehouse-dashboard.php?action=dashboard&active_only=true&limit=50"
    );
    const data = await response.json();

    if (data.success) {
        console.log("Warehouses:", data.data.warehouses);
        console.log("Summary:", data.data.summary);
    } else {
        console.error("Error:", data.error);
    }
}

// Export to CSV
function exportToCSV() {
    window.location.href =
        "/api/warehouse-dashboard.php?action=export&active_only=true";
}

// Get warehouses
async function getWarehouses() {
    const response = await fetch(
        "/api/warehouse-dashboard.php?action=warehouses"
    );
    const data = await response.json();
    return data.data;
}
```

## PHP Example

```php
<?php
// Get dashboard data
$url = 'http://localhost/api/warehouse-dashboard.php?action=dashboard&active_only=true';
$response = file_get_contents($url);
$data = json_decode($response, true);

if ($data['success']) {
    foreach ($data['data']['warehouses'] as $warehouse) {
        echo "Warehouse: " . $warehouse['warehouse_name'] . "\n";
        echo "Items: " . count($warehouse['items']) . "\n";
    }
}
?>
```

## Python Example

```python
import requests

# Get dashboard data
response = requests.get(
    'http://localhost/api/warehouse-dashboard.php',
    params={
        'action': 'dashboard',
        'active_only': 'true',
        'liquidity_status': 'critical',
        'limit': 50
    }
)

data = response.json()

if data['success']:
    for warehouse in data['data']['warehouses']:
        print(f"Warehouse: {warehouse['warehouse_name']}")
        print(f"Items: {len(warehouse['items'])}")
```

## Troubleshooting

### Issue: Empty Response

**Solution:** Check if data exists in the database and metrics are calculated.

```bash
# Check if warehouses exist
curl "http://localhost/api/warehouse-dashboard.php?action=warehouses"
```

### Issue: 500 Error

**Solution:** Check PHP error logs and database connection.

```bash
# Check PHP error log
tail -f /var/log/php/error.log

# Test database connection
php config/database_postgresql.php
```

### Issue: Invalid Parameters

**Solution:** Verify parameter names and values match the documentation.

```bash
# This will fail (invalid status)
curl "http://localhost/api/warehouse-dashboard.php?action=dashboard&liquidity_status=invalid"

# This will work
curl "http://localhost/api/warehouse-dashboard.php?action=dashboard&liquidity_status=critical"
```

## Performance Tips

1. **Use Pagination:** Don't request all items at once

    ```bash
    ?limit=100&offset=0
    ```

2. **Filter Early:** Apply filters to reduce result set

    ```bash
    ?active_only=true&warehouse=АДЫГЕЙСК_РФЦ
    ```

3. **Cache Results:** Cache responses on the client side for 5 minutes

4. **Use Specific Queries:** Request only what you need
    ```bash
    # Instead of getting all and filtering client-side
    ?liquidity_status=critical
    ```

## Next Steps

1. Review the full API documentation: `api/README_WAREHOUSE_DASHBOARD.md`
2. Test the endpoints with your data
3. Integrate with the frontend React application
4. Set up automated metrics refresh (Task 4)

## Support

For issues or questions:

1. Check the full documentation: `api/README_WAREHOUSE_DASHBOARD.md`
2. Review the design document: `.kiro/specs/warehouse-dashboard/design.md`
3. Check the requirements: `.kiro/specs/warehouse-dashboard/requirements.md`
