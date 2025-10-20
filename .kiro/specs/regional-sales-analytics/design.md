# Design Document

## Overview

Система региональной аналитики продаж представляет собой веб-приложение с API для анализа продаж бренда ЭТОНОВО через маркетплейсы. Система реализуется в два этапа: сначала аналитика по существующим данным из базы mi_core_db, затем интеграция с Ozon API для получения детальных региональных данных.

### Key Metrics (Current Data)

- **Ozon**: 4,710 заказов, выручка 2,165,502 руб (средний чек 460 руб)
- **Wildberries**: 2,756 заказов, выручка 1,213,679 руб (средний чек 705 руб)
- **Топ-товар**: Овсяные хлопья 700г (2,271 заказ, 1,184,139 руб выручки)
- **Период данных**: Сентябрь 2025

## Architecture

### System Components

```
┌─────────────────┐    ┌─────────────────┐    ┌─────────────────┐
│   Frontend      │    │   Backend API   │    │   Database      │
│   Dashboard     │◄──►│   (PHP/Node.js) │◄──►│   mi_core_db    │
└─────────────────┘    └─────────────────┘    └─────────────────┘
                                │
                                ▼
                       ┌─────────────────┐
                       │   Ozon API      │
                       │   Integration   │
                       └─────────────────┘
```

### Data Flow

1. **Phase 1**: Existing data analysis

   - Read from `fact_orders`, `dim_products`, `clients`, `sources`
   - Aggregate by marketplace, product, time period
   - Serve via REST API to dashboard

2. **Phase 2**: Ozon API integration
   - Fetch regional data from Ozon Seller API
   - Store in new table `ozon_regional_sales`
   - Enhance analytics with regional breakdown

## Components and Interfaces

### 1. Database Layer

#### Existing Tables (Phase 1)

```sql
-- Core sales data
fact_orders (id, product_id, order_id, transaction_type, sku, qty, price, order_date, client_id, source_id)
dim_products (id, sku_ozon, product_name, brand, category, cost_price)
clients (id, name) -- ТД Манхэттен
sources (id, code, name) -- OZON, WB, WEBSITE

-- Key relationships
fact_orders.product_id → dim_products.id
fact_orders.client_id → clients.id
fact_orders.source_id → sources.id
```

#### New Tables (Phase 2)

```sql
-- Regional data from Ozon API
CREATE TABLE ozon_regional_sales (
    id INT PRIMARY KEY AUTO_INCREMENT,
    date_from DATE NOT NULL,
    date_to DATE NOT NULL,
    region_id VARCHAR(50),
    federal_district VARCHAR(100),
    offer_id VARCHAR(255),
    product_id INT,
    sales_qty INT DEFAULT 0,
    sales_amount DECIMAL(10,2) DEFAULT 0,
    marketplace VARCHAR(50) DEFAULT 'OZON',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_date_region (date_from, region_id),
    INDEX idx_product (product_id),
    FOREIGN KEY (product_id) REFERENCES dim_products(id)
);

-- Regional reference data
CREATE TABLE regions (
    id INT PRIMARY KEY AUTO_INCREMENT,
    region_code VARCHAR(50) UNIQUE,
    region_name VARCHAR(200) NOT NULL,
    federal_district VARCHAR(100),
    is_active BOOLEAN DEFAULT TRUE
);
```

### 2. Backend API Layer

#### Core Analytics Service

```php
class SalesAnalyticsService {
    // Phase 1: Marketplace analytics
    public function getMarketplaceComparison($dateFrom, $dateTo, $clientId = 1)
    public function getTopProductsByMarketplace($marketplace, $limit = 10)
    public function getSalesDynamics($period = 'month')
    public function getMarketplaceShare()

    // Phase 2: Regional analytics
    public function getRegionalSales($dateFrom, $dateTo)
    public function getTopRegions($limit = 10)
    public function getRegionalProductPreferences($regionId)
}
```

#### Ozon API Integration Service

```php
class OzonAPIService {
    private $clientId;
    private $apiKey;
    private $baseUrl = 'https://api-seller.ozon.ru';

    public function fetchAnalyticsData($dateFrom, $dateTo, $filters = [])
    public function getRegionalSalesData($brand = 'ЭТОНОВО')
    public function syncRegionalData() // Daily sync job
}
```

#### REST API Endpoints

```
GET /api/analytics/marketplace-comparison
    ?date_from=2025-09-01&date_to=2025-09-30

GET /api/analytics/top-products
    ?marketplace=ozon&limit=10

GET /api/analytics/sales-dynamics
    ?period=month&marketplace=all

GET /api/analytics/regional-sales  // Phase 2
    ?date_from=2025-09-01&date_to=2025-09-30&region_id=77

POST /api/ozon/sync-regional-data  // Phase 2
```

### 3. Frontend Dashboard

#### Main Dashboard Components

```javascript
// React/Vue.js components
- MarketplaceComparisonChart
- TopProductsTable
- SalesDynamicsChart
- RegionalHeatMap (Phase 2)
- ProductPerformanceGrid
- FilterControls (date, marketplace, region)
```

#### Dashboard Layout

```
┌─────────────────────────────────────────────────────┐
│ Header: Regional Sales Analytics - ЭТОНОВО          │
├─────────────────────────────────────────────────────┤
│ Filters: [Date Range] [Marketplace] [Region]        │
├─────────────────────────────────────────────────────┤
│ KPI Cards: Total Revenue | Orders | Avg Check      │
├─────────────────────────────────────────────────────┤
│ ┌─────────────────┐ ┌─────────────────────────────┐ │
│ │ Marketplace     │ │ Sales Dynamics Chart        │ │
│ │ Comparison      │ │                             │ │
│ │ (Pie Chart)     │ │                             │ │
│ └─────────────────┘ └─────────────────────────────┘ │
├─────────────────────────────────────────────────────┤
│ ┌─────────────────────────────────────────────────┐ │
│ │ Top Products Table                              │ │
│ │ Product | Ozon Sales | WB Sales | Total        │ │
│ └─────────────────────────────────────────────────┘ │
└─────────────────────────────────────────────────────┘
```

## Data Models

### Sales Analytics Model

```php
class SalesAnalytics {
    public $marketplace;      // 'ozon', 'wildberries'
    public $totalOrders;      // int
    public $totalRevenue;     // decimal
    public $averageCheck;     // decimal
    public $topProducts;      // array
    public $period;          // date range
}
```

### Product Performance Model

```php
class ProductPerformance {
    public $productId;
    public $productName;
    public $sku;
    public $ozonSales;       // {orders, revenue}
    public $wbSales;         // {orders, revenue}
    public $totalSales;      // {orders, revenue}
    public $marketplaceShare; // percentage breakdown
}
```

### Regional Sales Model (Phase 2)

```php
class RegionalSales {
    public $regionId;
    public $regionName;
    public $federalDistrict;
    public $totalSales;
    public $marketplaceBreakdown; // ozon vs wb
    public $topProducts;
    public $growthRate;
}
```

## Error Handling

### API Error Responses

```json
{
  "success": false,
  "error": {
    "code": "INVALID_DATE_RANGE",
    "message": "Date range cannot exceed 12 months",
    "details": {
      "max_range_days": 365,
      "provided_range_days": 400
    }
  }
}
```

### Database Error Handling

- Connection failures: Retry with exponential backoff
- Query timeouts: Implement query optimization and caching
- Data integrity: Validate foreign key relationships

### Ozon API Error Handling

- Rate limiting: Implement request queuing and retry logic
- Authentication errors: Refresh tokens automatically
- Data validation: Verify API response structure before processing

## Testing Strategy

### Unit Tests

- SalesAnalyticsService methods
- OzonAPIService integration
- Data model validation
- API endpoint responses

### Integration Tests

- Database queries with real data
- Ozon API integration (sandbox environment)
- End-to-end dashboard functionality

### Performance Tests

- Large dataset queries (100k+ orders)
- Concurrent API requests
- Dashboard loading times

### Test Data

```sql
-- Sample test data for development
INSERT INTO fact_orders (product_id, order_id, transaction_type, sku, qty, price, order_date, client_id, source_id)
VALUES
(122, 'TEST-001', 'продажа', 'Хлопья овсяные 700г', 1, 264.00, '2025-09-01', 1, 2),
(105, 'TEST-002', 'продажа', 'Сахарозаменитель_коричн500г', 1, 632.00, '2025-09-01', 1, 3);
```

## Security Considerations

### API Security

- Authentication via API keys
- Rate limiting per client
- Input validation and sanitization
- SQL injection prevention

### Ozon API Integration

- Secure storage of API credentials
- HTTPS-only communication
- Token refresh mechanism
- API usage monitoring

### Data Privacy

- No personal customer data storage
- Aggregated analytics only
- Audit logging for data access

## Performance Optimization

### Database Optimization

- Indexes on frequently queried columns (date, product_id, source_id)
- Query result caching (Redis)
- Database connection pooling

### API Performance

- Response caching for static data
- Pagination for large datasets
- Asynchronous processing for heavy operations

### Frontend Optimization

- Lazy loading of dashboard components
- Data visualization optimization
- Progressive loading of charts

## Deployment Architecture

### Development Environment

```
Local MySQL + PHP/Node.js + React/Vue.js
Docker containers for consistent environment
```

### Production Environment

```
Load Balancer → Web Servers (PHP-FPM/Node.js) → MySQL Master/Slave
Redis Cache → Background Jobs (Ozon API sync)
```

### Monitoring

- Application performance monitoring
- Database query performance
- API response times
- Error rate tracking
