# Ozon Analytics API Documentation

## Overview

The Ozon Analytics API provides endpoints for retrieving and exporting Ozon marketplace analytics data including funnel metrics, demographics, and campaign performance.

## Base URL

```
/src/api/ozon-analytics.php
```

## Authentication

The API uses Ozon Client ID and API Key from environment variables. No additional authentication is required for the endpoints.

## Endpoints

### 1. GET /funnel-data

Retrieves sales funnel data (views, cart additions, orders, conversions).

**Parameters:**

- `date_from` (required): Start date in YYYY-MM-DD format
- `date_to` (required): End date in YYYY-MM-DD format
- `product_id` (optional): Filter by specific product ID
- `campaign_id` (optional): Filter by specific campaign ID
- `use_cache` (optional): Use cached data if available (default: true)

**Example:**

```
GET /src/api/ozon-analytics.php?action=funnel-data&date_from=2025-01-01&date_to=2025-01-10
```

**Response:**

```json
{
  "success": true,
  "data": [
    {
      "date_from": "2025-01-01",
      "date_to": "2025-01-10",
      "views": 10000,
      "cart_additions": 1500,
      "orders": 300,
      "conversion_view_to_cart": 15.0,
      "conversion_cart_to_order": 20.0,
      "conversion_overall": 3.0
    }
  ],
  "message": "Данные воронки продаж получены успешно"
}
```

### 2. GET /demographics

Retrieves demographic data (age, gender, region distribution).

**Parameters:**

- `date_from` (required): Start date in YYYY-MM-DD format
- `date_to` (required): End date in YYYY-MM-DD format
- `region` (optional): Filter by specific region
- `age_group` (optional): Filter by age group
- `gender` (optional): Filter by gender (M/F)
- `use_cache` (optional): Use cached data if available (default: true)

**Example:**

```
GET /src/api/ozon-analytics.php?action=demographics&date_from=2025-01-01&date_to=2025-01-10
```

**Response:**

```json
{
  "success": true,
  "data": [
    {
      "age_group": "26-35",
      "gender": "F",
      "region": "Москва",
      "orders_count": 120,
      "revenue": 500000.0
    }
  ],
  "message": "Демографические данные получены успешно"
}
```

### 3. GET /campaigns

Retrieves advertising campaign performance data.

**Parameters:**

- `date_from` (required): Start date in YYYY-MM-DD format
- `date_to` (required): End date in YYYY-MM-DD format
- `campaign_id` (optional): Filter by specific campaign ID

**Example:**

```
GET /src/api/ozon-analytics.php?action=campaigns&date_from=2025-01-01&date_to=2025-01-10
```

**Response:**

```json
{
  "success": true,
  "data": [
    {
      "campaign_id": "123",
      "campaign_name": "Кампания 1",
      "impressions": 50000,
      "clicks": 2500,
      "ctr": 5.0,
      "spend": 25000.0,
      "orders": 100,
      "revenue": 150000.0,
      "roas": 6.0
    }
  ],
  "message": "Данные кампаний получены успешно"
}
```

### 4. POST /export-data

Exports analytics data in CSV or JSON format.

**Request Body:**

```json
{
  "data_type": "funnel|demographics|campaigns",
  "format": "json|csv",
  "date_from": "2025-01-01",
  "date_to": "2025-01-10",
  "product_id": "optional",
  "campaign_id": "optional",
  "region": "optional",
  "age_group": "optional",
  "gender": "optional"
}
```

**Example:**

```bash
curl -X POST /src/api/ozon-analytics.php?action=export-data \
  -H "Content-Type: application/json" \
  -d '{
    "data_type": "funnel",
    "format": "csv",
    "date_from": "2025-01-01",
    "date_to": "2025-01-10"
  }'
```

**Response:**

- For JSON format: Returns JSON data structure
- For CSV format: Returns CSV file with appropriate headers

### 5. GET /health

Health check endpoint for monitoring API status.

**Example:**

```
GET /src/api/ozon-analytics.php?action=health
```

**Response:**

```json
{
  "success": true,
  "data": {
    "database": "connected",
    "ozon_api": "authenticated",
    "timestamp": "2025-01-05 12:00:00"
  },
  "message": "Статус системы проверен"
}
```

## Error Handling

All endpoints return consistent error responses:

```json
{
  "success": false,
  "data": [],
  "message": "Error description",
  "error_type": "validation_error|api_error|server_error"
}
```

**HTTP Status Codes:**

- `200` - Success
- `400` - Bad Request (validation errors)
- `404` - Not Found (invalid endpoint)
- `405` - Method Not Allowed
- `500` - Internal Server Error

## Rate Limiting

The API implements rate limiting to comply with Ozon API restrictions. Requests are automatically throttled to prevent exceeding limits.

## Caching

Data is cached for 1 hour by default to improve performance and reduce API calls. Use `use_cache=false` to force fresh data retrieval.

## Environment Variables

Required environment variables:

- `OZON_CLIENT_ID` - Ozon API Client ID
- `OZON_API_KEY` - Ozon API Key
- `DB_HOST` - Database host
- `DB_NAME` - Database name
- `DB_USER` - Database user
- `DB_PASSWORD` - Database password
