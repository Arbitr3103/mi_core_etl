# MI Core ETL System Architecture

## Overview

The MI Core ETL system is a modern, enterprise-grade inventory management and analytics platform built with a focus on scalability, maintainability, and performance. The system follows a clean architecture pattern with clear separation of concerns between data extraction, transformation, storage, and presentation layers.

## System Architecture

### High-Level Architecture

```
┌─────────────────────────────────────────────────────────────────┐
│                        External Data Sources                     │
│  ┌──────────┐  ┌──────────────┐  ┌────────────────────────┐   │
│  │ Ozon API │  │ Wildberries  │  │ Internal Systems       │   │
│  └──────────┘  └──────────────┘  └────────────────────────┘   │
└─────────────────────────────────────────────────────────────────┘
                              │
                              ▼
┌─────────────────────────────────────────────────────────────────┐
│                         ETL Pipeline Layer                       │
│  ┌──────────────┐  ┌──────────────┐  ┌──────────────────┐     │
│  │  Extractors  │→ │ Transformers │→ │     Loaders      │     │
│  │  (Python)    │  │  (Python)    │  │   (Python/PHP)   │     │
│  └──────────────┘  └──────────────┘  └──────────────────┘     │
└─────────────────────────────────────────────────────────────────┘
                              │
                              ▼
┌─────────────────────────────────────────────────────────────────┐
│                      Data Storage Layer                          │
│  ┌────────────────────────────────────────────────────────┐    │
│  │              PostgreSQL 15 Database                     │    │
│  │  • Optimized indexes and materialized views            │    │
│  │  • JSONB for flexible data storage                     │    │
│  │  • Automated backup and recovery                       │    │
│  └────────────────────────────────────────────────────────┘    │
└─────────────────────────────────────────────────────────────────┘
                              │
                              ▼
┌─────────────────────────────────────────────────────────────────┐
│                      Application Layer                           │
│  ┌──────────────────────────────────────────────────────┐      │
│  │              PHP 8.1+ Backend API                     │      │
│  │  ┌────────────┐  ┌────────────┐  ┌──────────────┐   │      │
│  │  │Controllers │  │ Middleware │  │   Services   │   │      │
│  │  └────────────┘  └────────────┘  └──────────────┘   │      │
│  │  ┌────────────┐  ┌────────────┐  ┌──────────────┐   │      │
│  │  │   Models   │  │   Cache    │  │   Logging    │   │      │
│  │  └────────────┘  └────────────┘  └──────────────┘   │      │
│  └──────────────────────────────────────────────────────┘      │
└─────────────────────────────────────────────────────────────────┘
                              │
                              ▼
┌─────────────────────────────────────────────────────────────────┐
│                      Presentation Layer                          │
│  ┌──────────────────────────────────────────────────────┐      │
│  │           React 18+ Frontend (TypeScript)            │      │
│  │  ┌────────────┐  ┌────────────┐  ┌──────────────┐   │      │
│  │  │ Components │  │   Hooks    │  │   Services   │   │      │
│  │  └────────────┘  └────────────┘  └──────────────┘   │      │
│  │  ┌────────────┐  ┌────────────┐  ┌──────────────┐   │      │
│  │  │   Stores   │  │   Types    │  │    Utils     │   │      │
│  │  └────────────┘  └────────────┘  └──────────────┘   │      │
│  └──────────────────────────────────────────────────────┘      │
└─────────────────────────────────────────────────────────────────┘
                              │
                              ▼
┌─────────────────────────────────────────────────────────────────┐
│                      Infrastructure Layer                        │
│  ┌──────────┐  ┌──────────┐  ┌──────────┐  ┌──────────────┐   │
│  │  Nginx   │  │ PHP-FPM  │  │   Cron   │  │  Monitoring  │   │
│  └──────────┘  └──────────┘  └──────────┘  └──────────────┘   │
└─────────────────────────────────────────────────────────────────┘
```

## Technology Stack

### Frontend Technologies

| Technology     | Version | Purpose                   |
| -------------- | ------- | ------------------------- |
| React          | 18+     | UI framework              |
| TypeScript     | 5+      | Type safety               |
| Vite           | 5+      | Build tool                |
| TanStack Query | 5+      | Data fetching and caching |
| Zustand        | 4+      | State management          |
| Tailwind CSS   | 3+      | Styling                   |
| React Virtual  | 2+      | List virtualization       |

### Backend Technologies

| Technology | Version | Purpose                   |
| ---------- | ------- | ------------------------- |
| PHP        | 8.1+    | Backend language          |
| PostgreSQL | 15+     | Primary database          |
| Python     | 3.8+    | ETL scripts               |
| Nginx      | 1.18+   | Web server                |
| Composer   | 2+      | PHP dependency management |

### Development Tools

| Tool         | Purpose            |
| ------------ | ------------------ |
| PHPUnit      | PHP unit testing   |
| Vitest       | Frontend testing   |
| PHPStan      | Static analysis    |
| PHP CS Fixer | Code formatting    |
| ESLint       | JavaScript linting |
| Prettier     | Code formatting    |

## Component Architecture

### Backend Components

#### 1. Controllers (`src/api/controllers/`)

Controllers handle HTTP requests and coordinate between services:

-   **InventoryController**: Manages inventory data endpoints
-   **AnalyticsController**: Handles analytics and reporting
-   **HealthController**: System health checks

**Responsibilities:**

-   Request validation
-   Service orchestration
-   Response formatting
-   Error handling

#### 2. Middleware (`src/api/middleware/`)

Middleware processes requests before they reach controllers:

-   **AuthMiddleware**: Authentication and authorization
-   **CorsMiddleware**: Cross-origin resource sharing
-   **RateLimitMiddleware**: Request rate limiting

**Responsibilities:**

-   Request preprocessing
-   Security enforcement
-   Rate limiting
-   CORS handling

#### 3. Models (`src/models/`)

Models represent business entities:

-   **Product**: Product master data
-   **Inventory**: Stock levels and warehouse data
-   **Warehouse**: Warehouse information

**Responsibilities:**

-   Data validation
-   Business logic
-   Data transformation
-   Serialization

#### 4. Services (`src/services/`)

Services contain business logic:

-   **InventoryService**: Inventory operations
-   **CacheService**: Caching operations
-   **LoggingService**: Structured logging

**Responsibilities:**

-   Business logic implementation
-   Data processing
-   External service integration
-   Transaction management

#### 5. Utilities (`src/utils/`)

Utilities provide common functionality:

-   **Database**: Database connection management
-   **Logger**: Structured logging
-   **Config**: Configuration management

### Frontend Components

#### 1. UI Components (`frontend/src/components/ui/`)

Reusable UI components:

-   **Button**: Styled button component
-   **Card**: Container component
-   **VirtualList**: Virtualized list for performance
-   **ErrorMessage**: Error display component

#### 2. Business Components (`frontend/src/components/inventory/`)

Domain-specific components:

-   **InventoryDashboard**: Main dashboard container
-   **ProductList**: Product list with virtualization
-   **ProductCard**: Individual product display
-   **ViewModeToggle**: View mode switcher

#### 3. Layout Components (`frontend/src/components/layout/`)

Layout and navigation:

-   **Layout**: Main layout wrapper
-   **Header**: Application header
-   **Navigation**: Navigation menu

#### 4. Custom Hooks (`frontend/src/hooks/`)

Reusable React hooks:

-   **useInventory**: Inventory data fetching
-   **useLocalStorage**: Persistent local storage
-   **useVirtualization**: List virtualization logic

#### 5. Services (`frontend/src/services/`)

API integration:

-   **api.ts**: Base API client
-   **inventory.ts**: Inventory API methods

## Data Flow

### Request Flow

```
User Action
    │
    ▼
React Component
    │
    ▼
Custom Hook (useInventory)
    │
    ▼
TanStack Query
    │
    ▼
API Service
    │
    ▼
HTTP Request
    │
    ▼
Nginx
    │
    ▼
PHP-FPM
    │
    ▼
Middleware Stack
    │
    ▼
Controller
    │
    ▼
Service Layer
    │
    ▼
Model Layer
    │
    ▼
PostgreSQL Database
    │
    ▼
Response (JSON)
    │
    ▼
Cache Layer
    │
    ▼
React Component Update
```

### ETL Data Flow

```
External API (Ozon/WB)
    │
    ▼
Extractor (Python)
    │
    ▼
Raw Data Validation
    │
    ▼
Transformer (Python)
    │
    ▼
Data Normalization
    │
    ▼
Loader (Python/PHP)
    │
    ▼
PostgreSQL Database
    │
    ▼
Materialized View Refresh
    │
    ▼
Cache Invalidation
    │
    ▼
Frontend Update
```

## Database Schema

### Core Tables

#### products

-   Primary product master data
-   Indexes on: sku, name, is_active
-   Relationships: inventory_data, dim_products

#### inventory_data

-   Current stock levels
-   Indexes on: product_id, warehouse_id, stock_status
-   Relationships: products, warehouses

#### warehouses

-   Warehouse information
-   Indexes on: name, is_active

#### dim_products

-   Product dimensions and attributes
-   JSONB fields for flexible attributes
-   GIN indexes on JSONB columns

#### fact_transactions

-   Transaction history
-   Partitioned by date
-   Indexes on: transaction_date, product_id

### Materialized Views

#### v_dashboard_inventory

-   Pre-aggregated dashboard data
-   Refreshed every 5 minutes
-   Significantly improves query performance

#### v_product_analytics

-   Product performance metrics
-   Refreshed daily
-   Used for analytics endpoints

## Security Architecture

### Authentication & Authorization

1. **IP Whitelisting**: Configured in AuthMiddleware
2. **API Keys**: Optional for external integrations
3. **CORS**: Restricted to allowed origins
4. **Rate Limiting**: 60 requests per minute per IP

### Data Security

1. **Environment Variables**: Sensitive data in .env
2. **Database Encryption**: PostgreSQL SSL connections
3. **Input Validation**: All inputs validated and sanitized
4. **SQL Injection Prevention**: Prepared statements only
5. **XSS Prevention**: Output escaping in frontend

### File Security

```bash
# Secure permissions
find /var/www/mi_core_etl -type f -exec chmod 644 {} \;
find /var/www/mi_core_etl -type d -exec chmod 755 {} \;

# Sensitive files
chmod 600 .env
chmod 600 config/database_postgresql.php
```

## Performance Optimization

### Frontend Optimization

1. **Code Splitting**: Lazy loading with React.lazy
2. **Memoization**: React.memo for expensive components
3. **Virtualization**: react-window for large lists
4. **Bundle Optimization**: Vite tree-shaking and minification
5. **Asset Optimization**: Image compression and lazy loading

### Backend Optimization

1. **Caching**: File-based cache with 5-minute TTL
2. **Database Connection Pooling**: Persistent connections
3. **Query Optimization**: Indexed queries and EXPLAIN analysis
4. **Response Compression**: Gzip compression enabled
5. **Opcode Caching**: OPcache enabled for PHP

### Database Optimization

1. **Indexes**: Strategic indexes on frequently queried columns
2. **Materialized Views**: Pre-computed aggregations
3. **Partitioning**: Date-based partitioning for large tables
4. **VACUUM**: Regular maintenance tasks
5. **Connection Pooling**: PgBouncer for connection management

## Scalability Considerations

### Horizontal Scaling

-   **Load Balancing**: Nginx load balancer for multiple PHP-FPM instances
-   **Database Replication**: PostgreSQL streaming replication
-   **Cache Distribution**: Redis cluster for distributed caching
-   **CDN**: Static assets served via CDN

### Vertical Scaling

-   **Memory**: Increase shared_buffers and work_mem
-   **CPU**: More PHP-FPM workers
-   **Storage**: SSD for database and cache
-   **Network**: Faster network interfaces

## Monitoring & Observability

### Application Monitoring

-   **Health Checks**: `/api/health` endpoint
-   **Structured Logging**: JSON-formatted logs
-   **Error Tracking**: Centralized error logging
-   **Performance Metrics**: Response time tracking

### Database Monitoring

-   **Query Performance**: pg_stat_statements
-   **Connection Monitoring**: pg_stat_activity
-   **Disk Usage**: Regular disk space checks
-   **Slow Query Log**: Queries > 1 second logged

### Infrastructure Monitoring

-   **System Resources**: CPU, memory, disk usage
-   **Service Status**: Nginx, PHP-FPM, PostgreSQL
-   **Log Rotation**: Automated log rotation
-   **Backup Verification**: Daily backup checks

## Deployment Architecture

### Development Environment

```
Local Machine
├── Docker Compose (optional)
├── PostgreSQL (local)
├── PHP-FPM (local)
├── Nginx (local)
└── Vite Dev Server
```

### Production Environment

```
Production Server (178.72.129.61)
├── Nginx (reverse proxy + static files)
├── PHP-FPM (application server)
├── PostgreSQL (database)
├── Cron (scheduled tasks)
└── Monitoring (health checks)
```

### CI/CD Pipeline

```
Git Push
    │
    ▼
GitHub Actions
    │
    ├─→ Lint & Format Check
    ├─→ Unit Tests
    ├─→ Integration Tests
    └─→ Build
        │
        ▼
    Deployment Script
        │
        ├─→ Backup
        ├─→ Deploy Code
        ├─→ Run Migrations
        ├─→ Clear Cache
        ├─→ Restart Services
        └─→ Health Check
```

## Disaster Recovery

### Backup Strategy

1. **Database Backups**: Daily full backups with pg_dump
2. **Incremental Backups**: WAL archiving for point-in-time recovery
3. **Code Backups**: Git repository with tags
4. **Configuration Backups**: Stored in deployment/configs/

### Recovery Procedures

1. **Database Recovery**: Restore from pg_dump backup
2. **Application Recovery**: Deploy from Git tag
3. **Configuration Recovery**: Restore from backup
4. **Rollback**: Automated rollback script available

### High Availability

-   **Database Replication**: Streaming replication to standby
-   **Application Redundancy**: Multiple PHP-FPM instances
-   **Load Balancing**: Nginx load balancer
-   **Failover**: Automatic failover to standby database

## Future Enhancements

### Planned Improvements

1. **Microservices**: Split into smaller services
2. **Message Queue**: RabbitMQ for async processing
3. **Redis Cache**: Distributed caching
4. **Elasticsearch**: Full-text search
5. **GraphQL**: Alternative API interface
6. **Docker**: Containerization for easier deployment
7. **Kubernetes**: Container orchestration
8. **Monitoring**: Prometheus + Grafana

### Technical Debt

1. **Test Coverage**: Increase to 80%+
2. **Documentation**: API documentation with OpenAPI
3. **Type Safety**: Stricter TypeScript configuration
4. **Error Handling**: More granular error types
5. **Logging**: Centralized logging with ELK stack

## Conclusion

The MI Core ETL system is built with modern best practices, focusing on maintainability, scalability, and performance. The architecture supports both current requirements and future growth, with clear separation of concerns and well-defined interfaces between components.

For more detailed information, refer to:

-   [API Documentation](api.md)
-   [Deployment Guide](deployment.md)
-   [PostgreSQL Migration Guide](../migrations/README_POSTGRESQL_MIGRATION.md)
-   [DevOps Guide](devops_guide.md)
