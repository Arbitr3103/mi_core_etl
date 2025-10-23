# API Documentation

## Overview

The MI Core ETL API provides RESTful endpoints for accessing inventory data, analytics, and system health information. The API is built with PHP 8.1+ and uses PostgreSQL as the database backend.

## Base URL

```
Production: https://your-domain.com/api
Development: http://localhost/api
```

## Authentication

The API uses middleware-based authentication with the following methods:

-   **IP Whitelisting**: Configured in `src/api/middleware/AuthMiddleware.php`
-   **API Keys** (optional): Can be enabled for external integrations
-   **CORS**: Configured to allow specific origins

### Authentication Headers

```http
X-API-Key: your_api_key_here
```

## Rate Limiting

-   **Limit**: 60 requests per minute per IP address
-   **Headers**: Rate limit information is included in response headers
-   **Middleware**: Implemented in `src/api/middleware/RateLimitMiddleware.php`

### Rate Limit Headers

```http
X-RateLimit-Limit: 60
X-RateLimit-Remaining: 45
X-RateLimit-Reset: 1634567890
```

## Endpoints

### Inventory

#### Get Dashboard Data

```http
GET /api/inventory/dashboard
```

**Parameters:**

-   `limit` (optional): Number of items per category (default: 10)

**Response:**

```json
{
  "data": {
    "critical_products": {
      "count": 32,
      "items": [
        {
          "id": 1,
          "sku": "SKU001",
          "name": "Product Name",
          "current_stock": 3,
          "available_stock": 3,
          "reserved_stock": 0,
          "warehouse_name": "Main Warehouse",
          "stock_status": "critical",
          "last_updated": "2025-10-21 10:00:00"
        }
      ]
    },
    "low_stock_products": { ... },
    "overstock_products": { ... }
  },
  "success": true,
  "last_updated": "2025-10-21 10:00:00"
}
```

#### Get Product Details

```http
GET /api/inventory/product/{sku}
```

**Parameters:**

-   `sku`: Product SKU (required)

**Response:**

```json
{
    "data": {
        "id": 1,
        "sku": "SKU001",
        "name": "Product Name",
        "current_stock": 3,
        "available_stock": 3,
        "reserved_stock": 0,
        "warehouse_name": "Main Warehouse",
        "stock_status": "critical",
        "last_updated": "2025-10-21 10:00:00",
        "price": 1500.0,
        "category": "Electronics"
    },
    "success": true
}
```

### System

#### Health Check

```http
GET /api/health
```

**Response:**

```json
{
    "status": "healthy",
    "timestamp": "2025-10-21 10:00:00",
    "checks": {
        "database": "connected",
        "cache": "operational",
        "disk_space": "sufficient"
    },
    "version": "1.0.0"
}
```

## Error Handling

### Error Response Format

```json
{
    "error": "Error message",
    "message": "Detailed error description",
    "code": 400,
    "timestamp": "2025-10-21 10:00:00"
}
```

### HTTP Status Codes

-   `200` - Success
-   `400` - Bad Request
-   `401` - Unauthorized
-   `404` - Not Found
-   `429` - Rate Limited
-   `500` - Internal Server Error

## Caching

-   **Dashboard Data**: Cached for 5 minutes
-   **Product Details**: Cached for 10 minutes
-   **Cache Headers**: `Cache-Control` and `ETag` headers included

## Examples

### cURL Examples

```bash
# Get dashboard data
curl -X GET "http://localhost/api/inventory/dashboard?limit=20"

# Get product details
curl -X GET "http://localhost/api/inventory/product/SKU001"

# Health check
curl -X GET "http://localhost/api/health"
```

### JavaScript Examples

```javascript
// Fetch dashboard data
const response = await fetch("/api/inventory/dashboard");
const data = await response.json();

// Fetch product details
const product = await fetch("/api/inventory/product/SKU001");
const productData = await product.json();
```

## Changelog

### Version 1.0.0

-   Initial API release
-   Inventory dashboard endpoints
-   Health check endpoint
-   Rate limiting implementation

## Performance Considerations

### Caching Strategy

-   **Dashboard Data**: Cached for 5 minutes (300 seconds)
-   **Product Details**: Cached for 10 minutes (600 seconds)
-   **Cache Backend**: File-based cache (configurable to Redis)
-   **Cache Headers**: `Cache-Control`, `ETag`, and `Last-Modified` headers included

### Optimization Tips

1. **Use the limit parameter** to reduce payload size
2. **Leverage caching** by respecting cache headers
3. **Batch requests** when possible to reduce API calls
4. **Monitor rate limits** to avoid throttling

### Response Times

-   **Dashboard endpoint**: < 2 seconds (with cache: < 100ms)
-   **Product details**: < 500ms (with cache: < 50ms)
-   **Health check**: < 100ms

## Database Schema

The API interacts with the following main PostgreSQL tables:

-   `products` - Product master data
-   `inventory_data` - Current stock levels
-   `warehouses` - Warehouse information
-   `dim_products` - Product dimensions and attributes
-   `fact_transactions` - Transaction history

## Versioning

The API follows semantic versioning (SemVer):

-   **Current Version**: 1.0.0
-   **API Path**: `/api/v1/` (optional, currently using `/api/`)
-   **Deprecation Policy**: 6 months notice for breaking changes

## Changelog

### Version 1.0.0 (October 2025)

-   ✅ Initial API release with PostgreSQL backend
-   ✅ Inventory dashboard endpoints with full/limited views
-   ✅ Product details endpoint with SKU lookup
-   ✅ Health check endpoint with database connectivity
-   ✅ Rate limiting middleware (60 req/min)
-   ✅ CORS middleware for cross-origin requests
-   ✅ Authentication middleware with IP whitelisting
-   ✅ Structured logging and error handling
-   ✅ File-based caching with configurable TTL
-   ✅ Comprehensive error responses with timestamps

## Support and Contact

-   **Technical Issues**: Create an issue in the repository
-   **API Questions**: Check the documentation or contact the development team
-   **Emergency**: Contact system administrator

## Related Documentation

-   [Deployment Guide](deployment.md) - How to deploy the API
-   [PostgreSQL Migration Guide](../migrations/README_POSTGRESQL_MIGRATION.md) - Database migration
-   [Frontend Integration](../frontend/README.md) - Using the API with React
-   [DevOps Guide](devops_guide.md) - Server configuration and monitoring
