# MDM API Documentation

## Overview

The MDM (Master Data Management) API provides unified access to product master data, enabling consistent product information across all systems. It includes backward compatibility with existing APIs while providing enhanced functionality through master data integration.

## Base URL

```
/api/
```

## Authentication

Currently, no authentication is required. All endpoints are publicly accessible.

## Response Format

All responses follow a consistent JSON format:

```json
{
  "success": boolean,
  "data": object|array,
  "metadata": object (optional),
  "error": string (only on failure)
}
```

## Endpoints

### 1. MDM API Gateway (`/api/mdm-api.php`)

Main API gateway for all MDM operations.

#### Get Products with Master Data

```http
GET /api/mdm-api.php?action=products
```

**Parameters:**

- `limit` (int, optional): Maximum number of products to return (default: 50, max: 1000)
- `offset` (int, optional): Number of products to skip (default: 0)
- `source` (string, optional): Filter by data source (e.g., 'ozon', 'wildberries')
- `brand` (string, optional): Filter by canonical brand name
- `category` (string, optional): Filter by canonical category
- `search` (string, optional): Search in product names and SKUs

**Response:**

```json
{
  "success": true,
  "data": {
    "products": [
      {
        "master_id": "PROD_001",
        "name": "Canonical Product Name",
        "brand": "Canonical Brand",
        "category": "Product Category",
        "description": "Product description",
        "attributes": {...},
        "status": "active",
        "mapped_skus_count": 3,
        "sources": ["ozon", "wildberries"],
        "external_skus": ["SKU1", "SKU2", "SKU3"],
        "created_at": "2024-01-01 12:00:00",
        "updated_at": "2024-01-01 12:00:00"
      }
    ],
    "pagination": {
      "total": 1500,
      "limit": 50,
      "offset": 0,
      "has_more": true
    }
  }
}
```

#### Get Product Details

```http
GET /api/mdm-api.php?action=product-details&master_id=PROD_001
GET /api/mdm-api.php?action=product-details&external_sku=SKU123
```

**Parameters:**

- `master_id` (string): Master product ID
- `external_sku` (string): External SKU to lookup

**Response:**

```json
{
  "success": true,
  "data": {
    "master_id": "PROD_001",
    "canonical_name": "Product Name",
    "canonical_brand": "Brand Name",
    "canonical_category": "Category",
    "description": "Description",
    "attributes": {...},
    "status": "active",
    "mappings": [
      {
        "source": "ozon",
        "external_sku": "SKU123",
        "source_name": "Original Product Name"
      }
    ],
    "created_at": "2024-01-01 12:00:00",
    "updated_at": "2024-01-01 12:00:00"
  }
}
```

#### Get Brands from Master Data

```http
GET /api/mdm-api.php?action=brands
```

**Response:**

```json
{
  "success": true,
  "data": {
    "brands": [
      {
        "name": "Brand Name",
        "products_count": 150
      }
    ]
  }
}
```

#### Get Categories from Master Data

```http
GET /api/mdm-api.php?action=categories
```

#### Search Products

```http
GET /api/mdm-api.php?action=search&q=search_term&limit=20
```

#### Get System Statistics

```http
GET /api/mdm-api.php?action=stats
```

**Response:**

```json
{
  "success": true,
  "data": {
    "master_products": {
      "total_master_products": 1500,
      "active_products": 1450,
      "products_with_brand": 1200,
      "products_with_category": 1100,
      "unique_brands": 85,
      "unique_categories": 45
    },
    "sku_mappings": {
      "total_mappings": 4500,
      "mapped_master_products": 1400,
      "data_sources": 3,
      "auto_verified": 3200,
      "manually_verified": 1000,
      "pending_verification": 300
    },
    "data_quality": {
      "brand_completeness": 85.5,
      "category_completeness": 78.2,
      "description_completeness": 65.8
    },
    "coverage_percentage": 93.33
  }
}
```

#### Master Data CRUD Operations

**Create Master Product:**

```http
POST /api/mdm-api.php?action=master-data
Content-Type: application/json

{
  "canonical_name": "Product Name",
  "canonical_brand": "Brand Name",
  "canonical_category": "Category",
  "description": "Description",
  "attributes": {...},
  "status": "active"
}
```

**Update Master Product:**

```http
PUT /api/mdm-api.php?action=master-data&id=PROD_001
Content-Type: application/json

{
  "canonical_name": "Updated Product Name",
  "canonical_brand": "Updated Brand"
}
```

**Get Master Product:**

```http
GET /api/mdm-api.php?action=master-data&id=PROD_001
```

#### SKU Mapping Operations

**Get Mappings:**

```http
GET /api/mdm-api.php?action=mapping&master_id=PROD_001
GET /api/mdm-api.php?action=mapping&external_sku=SKU123
```

**Create Mapping:**

```http
POST /api/mdm-api.php?action=mapping
Content-Type: application/json

{
  "master_id": "PROD_001",
  "external_sku": "SKU123",
  "source": "ozon",
  "source_name": "Original Product Name",
  "confidence_score": 0.95,
  "verification_status": "manual"
}
```

### 2. Enhanced Products API (`/api/products-mdm.php`)

Backward-compatible products API with MDM integration.

```http
GET /api/products-mdm.php?brand_id=1&model_id=2&year=2020&use_master_data=true
```

**Parameters:**

- All original parameters from `/api/products.php`
- `use_master_data` (boolean, optional): Enable MDM integration (default: true if available)

**Response includes:**

- All original fields for backward compatibility
- Enhanced fields from master data:
  - `master_id`
  - `canonical_brand`
  - `canonical_category`
  - `product_description`
  - `data_quality_level`
  - `data_source_description`

### 3. Enhanced Brands API (`/api/brands-mdm.php`)

Enhanced brands API using canonical brand names from master data.

```http
GET /api/brands-mdm.php?use_master_data=true&include_stats=true
```

**Parameters:**

- `use_master_data` (boolean, optional): Use master data (default: true if available)
- `include_stats` (boolean, optional): Include detailed statistics (default: false)

**Response with stats:**

```json
{
  "success": true,
  "data": [
    {
      "name": "Canonical Brand Name",
      "master_products_count": 150,
      "mapped_skus_count": 450,
      "data_sources": ["ozon", "wildberries"],
      "data_sources_count": 2,
      "quality_metrics": {
        "category_coverage": 85.5,
        "description_coverage": 72.3
      },
      "verification_status": {
        "auto_verified": 320,
        "manually_verified": 100,
        "pending_verification": 30
      },
      "original_variations": ["Brand Name", "BRAND NAME", "brand-name"]
    }
  ],
  "metadata": {
    "mdm_enabled": true,
    "total_canonical_brands": 85,
    "total_original_brands": 247,
    "data_source": "master_data",
    "overall_stats": {...}
  }
}
```

## Error Handling

### Error Response Format

```json
{
  "success": false,
  "error": "Error message",
  "error_type": "validation_error|data_error|system_error" (optional)
}
```

### HTTP Status Codes

- `200 OK`: Successful request
- `400 Bad Request`: Invalid parameters or validation errors
- `404 Not Found`: Resource not found
- `405 Method Not Allowed`: HTTP method not supported
- `500 Internal Server Error`: Server error

## Data Quality Levels

The API provides data quality indicators:

- `master_data`: Product has complete master data
- `mapped`: Product is mapped to master data but may have incomplete information
- `raw_data`: Product uses original data without master data enhancement

## Backward Compatibility

All enhanced APIs maintain backward compatibility:

1. **Existing Parameters**: All original parameters are supported
2. **Response Structure**: Original response fields are preserved
3. **Fallback Behavior**: If MDM is unavailable, APIs fall back to original behavior
4. **Optional Enhancement**: MDM features can be disabled via parameters

## Migration Guide

### For Existing API Consumers

1. **No Changes Required**: Existing integrations continue to work
2. **Gradual Enhancement**: Add `use_master_data=true` parameter to enable MDM features
3. **New Fields**: Start using canonical names and enhanced metadata
4. **Quality Indicators**: Use data quality levels to improve user experience

### Recommended Migration Steps

1. **Phase 1**: Test existing endpoints with `use_master_data=true`
2. **Phase 2**: Update UI to display canonical names and brands
3. **Phase 3**: Implement data quality indicators
4. **Phase 4**: Switch to new MDM-specific endpoints for new features

## Performance Considerations

- **Caching**: Responses are optimized for caching
- **Pagination**: Use appropriate limit/offset for large datasets
- **Filtering**: Use specific filters to reduce response size
- **Batch Operations**: Use bulk endpoints for multiple operations

## Support

For API support and questions, please refer to the MDM system documentation or contact the development team.
