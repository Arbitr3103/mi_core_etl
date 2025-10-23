# MI Core ETL System

A modern, enterprise-grade inventory management and analytics system with React frontend, PHP backend, and PostgreSQL database. Designed for efficient ETL operations, real-time dashboard visualization, and scalable data processing.

## 🏗️ Architecture

### System Overview

```
┌─────────────────┐    ┌──────────────────┐    ┌─────────────────┐
│   ETL Pipeline  │───▶│  PostgreSQL DB   │───▶│   React App     │
│   (Python/PHP)  │    │  (Optimized)     │    │  (TypeScript)   │
└─────────────────┘    └──────────────────┘    └─────────────────┘
         │                       │                      │
         ▼                       ▼                      ▼
   ┌──────────┐           ┌─────────────┐      ┌──────────────┐
   │Automated │           │ REST API    │      │    Nginx     │
   │Deployment│           │ (Unified)   │      │  (Static)    │
   └──────────┘           └─────────────┘      └──────────────┘
         │                       │                      │
         ▼                       ▼                      ▼
   ┌──────────┐           ┌─────────────┐      ┌──────────────┐
   │Monitoring│           │  Logging    │      │    Build     │
   │& Alerts  │           │  System     │      │  Automation  │
   └──────────┘           └─────────────┘      └──────────────┘
```

### Technology Stack

**Frontend:**

-   React 18+ with TypeScript
-   Vite for build tooling
-   TanStack Query for data fetching
-   Zustand for state management
-   Tailwind CSS for styling
-   React Virtual for list virtualization

**Backend:**

-   PHP 8.1+ with modern OOP patterns
-   RESTful API architecture
-   Middleware for auth, CORS, rate limiting
-   Structured logging and error handling

**Database:**

-   PostgreSQL 15+ (migrated from MySQL)
-   Optimized indexes and materialized views
-   JSONB for flexible data storage
-   Automated backup and recovery

**DevOps:**

-   Nginx for web serving and reverse proxy
-   Automated deployment scripts
-   Health monitoring and alerting
-   Cron-based ETL scheduling

## 📁 Project Structure

```
mi_core_etl/
├── src/                          # Source code
│   ├── api/                      # API controllers and routes
│   │   ├── controllers/          # API controllers
│   │   ├── middleware/           # API middleware
│   │   └── routes/              # Route definitions
│   ├── etl/                     # ETL processes
│   │   ├── extractors/          # Data extractors
│   │   ├── transformers/        # Data transformers
│   │   └── loaders/             # Data loaders
│   ├── models/                  # Data models
│   ├── services/                # Business logic services
│   └── utils/                   # Utility classes
├── config/                      # Configuration files
├── public/                      # Public web files
│   ├── api/                     # API entry point
│   └── build/                   # React build output
├── storage/                     # Storage directories
│   ├── logs/                    # Application logs
│   ├── cache/                   # Cache files
│   └── backups/                 # Backup files
├── deployment/                  # Deployment scripts
│   ├── scripts/                 # Deployment automation
│   └── configs/                 # Server configurations
├── tests/                       # Test files
│   ├── unit/                    # Unit tests
│   └── integration/             # Integration tests
├── docs/                        # Documentation
└── frontend/                    # React application (optional)
```

## 🚀 Quick Start

### Prerequisites

-   **PHP**: 8.1 or higher with extensions: pdo, pdo_pgsql, curl, json, mbstring
-   **PostgreSQL**: 15 or higher
-   **Node.js**: 18 or higher
-   **Composer**: 2.x
-   **Nginx**: 1.18 or higher
-   **Python**: 3.8+ (for ETL scripts)
-   **System**: Ubuntu 20.04+ or similar Linux distribution

### Installation

1. **Clone and setup:**

    ```bash
    git clone <repository-url>
    cd mi_core_etl
    cp .env.example .env
    ```

2. **Configure environment:**

    ```bash
    # Edit .env with your database credentials and API keys
    nano .env
    ```

3. **Install dependencies:**

    ```bash
    npm run install:all
    ```

4. **Build application:**

    ```bash
    npm run build:prod
    ```

5. **Set permissions:**
    ```bash
    chmod -R 755 storage/ public/
    ```

### Development

```bash
# Start development server
npm run dev

# Run tests
npm run test        # Frontend tests
npm run test:php    # Backend tests

# View logs
npm run logs

# Health check
npm run health
```

## 🔧 Configuration

### Environment Variables

Key environment variables in `.env`:

```env
# PostgreSQL Database
PG_HOST=localhost
PG_NAME=mi_core_db
PG_USER=mi_core_user
PG_PASS=your_secure_password
PG_PORT=5432

# API Configuration
API_BASE_URL=http://localhost/api
RATE_LIMIT_RPM=60

# Cache
CACHE_DRIVER=file
CACHE_TTL=300

# Logging
LOG_LEVEL=info
LOG_PATH=/var/www/mi_core_etl/storage/logs

# External APIs
OZON_API_KEY=your_ozon_api_key
WB_API_KEY=your_wildberries_api_key

# Application
APP_ENV=production
APP_DEBUG=false
```

### Database Setup

1. **Install PostgreSQL** (if not already installed):

    ```bash
    sudo apt update
    sudo apt install postgresql postgresql-contrib
    ```

2. **Create database and user**:

    ```bash
    sudo -u postgres psql << EOF
    CREATE DATABASE mi_core_db;
    CREATE USER mi_core_user WITH ENCRYPTED PASSWORD 'your_secure_password';
    GRANT ALL PRIVILEGES ON DATABASE mi_core_db TO mi_core_user;
    \c mi_core_db
    GRANT ALL ON SCHEMA public TO mi_core_user;
    EOF
    ```

3. **Run migrations**:

    ```bash
    psql -h localhost -U mi_core_user -d mi_core_db -f migrations/postgresql_schema.sql
    ```

4. **For MySQL to PostgreSQL migration**, see [PostgreSQL Migration Guide](migrations/README_POSTGRESQL_MIGRATION.md)

## 📊 API Endpoints

### Inventory API

-   `GET /api/inventory/dashboard` - Get dashboard data
-   `GET /api/inventory/product/{sku}` - Get product details
-   `GET /api/health` - Health check

### Warehouse Dashboard API

-   `GET /api/warehouse/dashboard` - Get warehouse dashboard data with replenishment calculations
-   `GET /api/warehouse/export` - Export warehouse data to CSV
-   `GET /api/warehouse/warehouses` - Get list of all warehouses
-   `GET /api/warehouse/clusters` - Get list of warehouse clusters

See [Warehouse Dashboard API Documentation](docs/WAREHOUSE_DASHBOARD_API.md) for detailed endpoint specifications.

### Response Format

```json
{
  "data": {
    "critical_products": {
      "count": 32,
      "items": [...]
    },
    "low_stock_products": {
      "count": 53,
      "items": [...]
    },
    "overstock_products": {
      "count": 28,
      "items": [...]
    }
  },
  "success": true,
  "last_updated": "2025-10-21 10:00:00"
}
```

## 🚀 Deployment

### Automated Deployment

```bash
# Deploy to production
npm run deploy

# Create backup
npm run backup

# Rollback to previous version
npm run rollback
```

### Manual Deployment

1. **Create backup:**

    ```bash
    bash deployment/scripts/backup.sh
    ```

2. **Deploy:**

    ```bash
    bash deployment/scripts/deploy.sh
    ```

3. **Verify deployment:**
    ```bash
    curl http://your-domain/api/health
    ```

## 🧪 Testing

### Backend Tests

```bash
# Run all PHP tests
composer test

# Run specific test
vendor/bin/phpunit tests/unit/InventoryControllerTest.php
```

### Frontend Tests

```bash
# Run React tests
cd frontend && npm test

# Run with coverage
cd frontend && npm run test:coverage
```

## 📝 ETL Operations

### Data Sources

-   **Ozon API**: Product and inventory data
-   **Wildberries API**: Marketplace analytics
-   **Internal Systems**: Cost and margin data

### ETL Schedule

```bash
# View current cron jobs
crontab -l

# Common ETL operations
0 */6 * * * php src/etl/ozon_sync.php          # Every 6 hours
0 2 * * * php src/etl/daily_inventory_sync.php # Daily at 2 AM
0 0 * * 0 php src/etl/weekly_reports.php       # Weekly reports
```

## 🔍 Monitoring

### Health Checks

```bash
# API health
curl http://localhost/api/health

# Database connectivity
php src/utils/check_database.php

# View logs
tail -f storage/logs/app.log
```

### Performance Monitoring

-   **Response Times**: API endpoints < 2 seconds
-   **Cache Hit Rate**: > 80%
-   **Database Queries**: Optimized with indexes
-   **Memory Usage**: Monitored via logs

## 🛠️ Troubleshooting

### Common Issues

1. **Database Connection Failed**

    ```bash
    # Check credentials in .env
    # Verify MySQL service is running
    sudo systemctl status mysql
    ```

2. **API Returns 500 Error**

    ```bash
    # Check PHP error logs
    tail -f /var/log/nginx/error.log
    tail -f storage/logs/error.log
    ```

3. **Frontend Build Fails**
    ```bash
    # Clear cache and reinstall
    cd frontend
    rm -rf node_modules package-lock.json
    npm install
    ```

### Debug Mode

Enable debug mode in `.env`:

```env
APP_DEBUG=true
LOG_LEVEL=debug
```

## 📊 React Frontend

The system includes a modern React-based dashboard for real-time inventory visualization.

### Features

-   **Real-time Data**: Live inventory updates with 5-minute cache
-   **Virtualized Lists**: Efficient rendering of large product lists
-   **View Modes**: Toggle between "Top 10" and "Show All" views
-   **Responsive Design**: Mobile-friendly interface with Tailwind CSS
-   **Type Safety**: Full TypeScript implementation
-   **Performance**: Optimized with React.memo, lazy loading, and code splitting

### Warehouse Dashboard

The Warehouse Dashboard provides comprehensive warehouse inventory management with automated replenishment calculations:

-   **Warehouse-Level Visibility**: View inventory by specific Ozon warehouses (not just clusters)
-   **Replenishment Calculations**: Automatic calculation of stock needs based on 28-day sales history
-   **Liquidity Analysis**: Real-time assessment of stock levels (Critical, Low, Normal, Excess)
-   **Active Product Filtering**: Focus on products with recent sales or current stock
-   **Advanced Filtering**: Filter by warehouse, cluster, liquidity status, and replenishment needs
-   **CSV Export**: Export filtered data for offline analysis
-   **Performance Optimized**: Pagination, virtualization, and hourly metric caching

See [Warehouse Dashboard User Guide](docs/WAREHOUSE_DASHBOARD_USER_GUIDE.md) for detailed usage instructions.

### Frontend Development

```bash
cd frontend

# Install dependencies
npm install

# Start development server
npm run dev

# Run tests
npm test

# Build for production
npm run build

# Preview production build
npm run preview
```

### Frontend Structure

```
frontend/
├── src/
│   ├── components/
│   │   ├── ui/              # Reusable UI components
│   │   ├── inventory/       # Inventory-specific components
│   │   └── layout/          # Layout components
│   ├── hooks/               # Custom React hooks
│   ├── services/            # API services
│   ├── stores/              # State management
│   ├── types/               # TypeScript types
│   └── utils/               # Utility functions
├── public/                  # Static assets
└── dist/                    # Production build output
```

## 📚 Documentation

### Core Documentation

-   [API Documentation](docs/api.md) - Complete API reference
-   [Deployment Guide](docs/deployment.md) - Production deployment instructions
-   [DevOps Guide](docs/devops_guide.md) - Server management and automation
-   [PostgreSQL Migration Guide](migrations/README_POSTGRESQL_MIGRATION.md) - MySQL to PostgreSQL migration
-   [PostgreSQL Backup & Restore](docs/postgresql_backup_restore_guide.md) - Database backup procedures

### Feature-Specific Guides

-   [Frontend README](frontend/README.md) - React application documentation
-   [Warehouse Dashboard User Guide](docs/WAREHOUSE_DASHBOARD_USER_GUIDE.md) - Warehouse inventory management
-   [Warehouse Dashboard API](docs/WAREHOUSE_DASHBOARD_API.md) - API endpoint documentation
-   [Ozon Analytics Guide](docs/OZON_ANALYTICS_USER_GUIDE.md) - Ozon marketplace integration
-   [Marketplace Integration](docs/MARKETPLACE_QUICK_REFERENCE.md) - Multi-marketplace support
-   [Monitoring System](docs/MONITORING_SYSTEM_README.md) - System monitoring and alerts
-   [Automated Testing](docs/AUTOMATED_TESTING_GUIDE.md) - Testing strategies and tools

## 🤝 Contributing

1. Fork the repository
2. Create feature branch: `git checkout -b feature/new-feature`
3. Commit changes: `git commit -am 'Add new feature'`
4. Push to branch: `git push origin feature/new-feature`
5. Submit pull request

## 📄 License

This project is licensed under the MIT License - see the [LICENSE](LICENSE) file for details.

## 🆘 Support

-   **Technical Issues**: Create an issue in the repository
-   **Documentation**: Check the `docs/` directory
-   **Emergency**: Contact system administrator

---

**Version**: 1.0.0  
**Last Updated**: October 2025  
**Maintainer**: MI Core Team
