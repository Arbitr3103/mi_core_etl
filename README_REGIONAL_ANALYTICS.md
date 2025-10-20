# Regional Sales Analytics System

## Overview

The Regional Sales Analytics System provides comprehensive analysis of ЭТОНОВО brand sales across different marketplaces (Ozon, Wildberries) with regional breakdown capabilities. This system enables data-driven decision making for regional expansion and marketplace optimization.

## Project Structure

```
├── api/analytics/                 # Backend API services
│   ├── config.php                # API configuration and utilities
│   ├── index.php                 # Main API router
│   └── endpoints/                # API endpoint implementations
│       ├── marketplace-comparison.php
│       ├── top-products.php
│       ├── sales-dynamics.php
│       └── regions.php
│
├── html/regional-dashboard/       # Frontend dashboard
│   ├── index.html                # Main dashboard page
│   ├── css/
│   │   └── dashboard.css         # Custom dashboard styles
│   └── js/
│       ├── config.js             # Dashboard configuration
│       ├── api.js                # API communication layer
│       ├── charts.js             # Chart rendering logic
│       └── dashboard.js          # Main dashboard logic
│
├── migrations/                    # Database migrations
│   ├── add_regional_analytics_schema.sql
│   ├── rollback_regional_analytics_schema.sql
│   ├── validate_regional_analytics_schema.sql
│   └── README_REGIONAL_ANALYTICS_MIGRATION.md
│
└── .kiro/specs/regional-sales-analytics/  # Project specifications
    ├── requirements.md
    ├── design.md
    └── tasks.md
```

## Features

### 🎯 Core Analytics Features

- **Marketplace Comparison**: Compare sales performance between Ozon and Wildberries
- **Regional Analysis**: Breakdown sales by Russian federal districts and regions
- **Product Performance**: Identify top-performing products across marketplaces
- **Sales Dynamics**: Track sales trends and growth patterns over time

### 📊 Dashboard Features

- **Interactive Charts**: Pie charts, line charts, and bar charts for data visualization
- **Real-time KPIs**: Total revenue, sales volume, active regions, average order value
- **Responsive Design**: Works on desktop, tablet, and mobile devices
- **Date Range Filtering**: Flexible date range selection for analysis periods

### 🔧 Technical Features

- **RESTful API**: Clean API endpoints for data access
- **Database Optimization**: Comprehensive indexing for fast queries
- **Caching System**: Performance optimization through intelligent caching
- **Security**: Input validation, CORS protection, and secure credential storage

## Database Schema

### Tables Created

1. **ozon_regional_sales** - Regional sales data from Ozon API
2. **regions** - Regional reference data and federal districts
3. **regional_analytics_cache** - Performance optimization cache

### Views Created

1. **v_regional_sales_summary** - Aggregated regional sales metrics
2. **v_marketplace_comparison** - Marketplace performance comparison

## API Endpoints

### Base URL: `/api/analytics`

| Endpoint                  | Method | Description                     | Parameters                                      |
| ------------------------- | ------ | ------------------------------- | ----------------------------------------------- |
| `/`                       | GET    | API information and health      | -                                               |
| `/marketplace-comparison` | GET    | Compare marketplace performance | `date_from`, `date_to`, `marketplace`           |
| `/top-products`           | GET    | Get top performing products     | `date_from`, `date_to`, `marketplace`, `limit`  |
| `/sales-dynamics`         | GET    | Get sales trends over time      | `date_from`, `date_to`, `marketplace`, `period` |
| `/regions`                | GET    | Get regional sales data         | `date_from`, `date_to`, `region_code`           |
| `/health`                 | GET    | API health check                | -                                               |

## Installation

### Prerequisites

- PHP 7.4+ with PDO MySQL extension
- MySQL 5.7+ or MariaDB 10.2+
- Web server (Apache/Nginx)
- Modern web browser with JavaScript enabled

### Database Setup

1. **Run the migration**:

   ```bash
   php apply_regional_analytics_migration.php
   ```

2. **Verify installation**:
   ```bash
   php -r "
   require_once 'config.php';
   \$pdo = getAnalyticsDbConnection();
   echo 'Database connection successful!' . PHP_EOL;
   "
   ```

### Web Server Configuration

#### Apache (.htaccess)

```apache
RewriteEngine On
RewriteRule ^api/analytics/(.*)$ api/analytics/index.php [QSA,L]
```

#### Nginx

```nginx
location /api/analytics/ {
    try_files $uri $uri/ /api/analytics/index.php?$query_string;
}
```

### Environment Configuration

Ensure your `.env` file contains:

```env
# Database settings
DB_HOST=localhost
DB_USER=your_username
DB_PASSWORD=your_password
DB_NAME=mi_core

# API credentials
OZON_CLIENT_ID=your_ozon_client_id
OZON_API_KEY=your_ozon_api_key
```

## Development

### Git Workflow

This project uses the `feature/regional-analytics` branch for development:

```bash
# Switch to development branch
git checkout feature/regional-analytics

# Make changes and commit
git add .
git commit -m "Add new feature"

# Push changes
git push origin feature/regional-analytics
```

### Local Development

1. **Start local server**:

   ```bash
   php -S localhost:8000
   ```

2. **Access dashboard**:

   ```
   http://localhost:8000/html/regional-dashboard/
   ```

3. **Test API**:
   ```
   http://localhost:8000/api/analytics/health
   ```

### Testing

#### API Testing

```bash
# Test health endpoint
curl http://localhost:8000/api/analytics/health

# Test marketplace comparison
curl "http://localhost:8000/api/analytics/marketplace-comparison?date_from=2024-01-01&date_to=2024-12-31"
```

#### Database Testing

```bash
# Validate migration
mysql -u username -p database_name < migrations/validate_regional_analytics_schema.sql
```

## Integration with Existing Dashboard

### Adding to market-mi.ru

1. **Copy files to production**:

   ```bash
   # Copy API files
   cp -r api/analytics/ /path/to/market-mi.ru/api/

   # Copy dashboard files
   cp -r html/regional-dashboard/ /path/to/market-mi.ru/html/
   ```

2. **Update navigation** in existing dashboard:

   ```html
   <li class="nav-item">
     <a class="nav-link" href="/html/regional-dashboard/">
       <i class="fas fa-map-marked-alt"></i>
       Региональная аналитика
     </a>
   </li>
   ```

3. **Run production migration**:
   ```bash
   php apply_regional_analytics_migration.php
   ```

## Configuration

### API Configuration (`api/analytics/config.php`)

- Database connection settings
- API rate limiting
- Cache configuration
- Security settings
- Logging configuration

### Dashboard Configuration (`html/regional-dashboard/js/config.js`)

- API endpoints
- Chart styling
- Date range limits
- UI behavior settings

## Monitoring and Maintenance

### Health Monitoring

- Use `/api/analytics/health` endpoint for health checks
- Monitor database performance
- Check log files for errors

### Cache Management

- Cache expires automatically based on TTL settings
- Manual cache cleanup: `DELETE FROM regional_analytics_cache WHERE expires_at < NOW()`

### Log Files

- API logs: `logs/analytics.log`
- Error logs: `logs/analytics_errors.log`

## Security Considerations

### Input Validation

- All API parameters are validated using regex patterns
- SQL injection prevention through prepared statements
- XSS protection through proper output encoding

### CORS Configuration

- Restricted to allowed origins
- Proper headers for cross-origin requests

### Credential Security

- API keys stored in environment variables
- Database credentials in secure configuration

## Performance Optimization

### Database Indexes

- Comprehensive indexing on frequently queried columns
- Composite indexes for complex queries
- Regular index optimization

### Caching Strategy

- API response caching
- Database query result caching
- Browser caching for static assets

### Query Optimization

- Efficient SQL queries with proper JOINs
- Pagination for large result sets
- Query timeout settings

## Troubleshooting

### Common Issues

1. **Database Connection Failed**

   - Check database credentials in `.env`
   - Verify database server is running
   - Check network connectivity

2. **API Returns 404**

   - Verify web server rewrite rules
   - Check file permissions
   - Ensure API files are in correct location

3. **Charts Not Loading**

   - Check browser console for JavaScript errors
   - Verify Chart.js library is loaded
   - Check API endpoint responses

4. **No Data in Dashboard**
   - Verify database has sample data
   - Check date range filters
   - Test API endpoints directly

### Debug Mode

Enable debug mode by adding to config:

```php
define('ANALYTICS_DEBUG', true);
```

## Contributing

### Code Standards

- Follow PSR-12 coding standards for PHP
- Use ESLint for JavaScript code quality
- Write meaningful commit messages
- Add comments for complex logic

### Testing Requirements

- Test all API endpoints
- Validate database queries
- Check responsive design
- Verify cross-browser compatibility

## License

This project is part of the ЭТОНОВО brand analytics system and is proprietary software.

## Support

For technical support or questions:

- Check the troubleshooting section
- Review log files for errors
- Contact the development team

## Changelog

### Version 1.0.0 (Current)

- Initial implementation
- Database schema creation
- Basic API endpoints
- Dashboard frontend
- Integration with existing system
