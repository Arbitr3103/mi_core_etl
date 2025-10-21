# Regional Analytics Development Environment Setup

## Overview

This document describes the development environment setup for the Regional Sales Analytics system. The system provides analytics for ЭТОНОВО brand sales across Ozon and Wildberries marketplaces.

## Prerequisites

### System Requirements

- **PHP 7.4+** with extensions:
  - PDO
  - pdo_mysql
  - json
  - curl
- **MySQL 5.7+** or **MariaDB 10.2+**
- **Web server** (Apache, Nginx, or PHP built-in server for development)
- **Git** for version control

### Development Tools (Recommended)

- **Node.js 16+** (for frontend development tools)
- **Composer** (for PHP dependency management)
- **curl** or **Postman** (for API testing)

## Quick Setup

### 1. Automated Setup (Recommended)

Run the automated setup script:

```bash
./setup_regional_analytics_dev.sh
```

This script will:

- Verify directory structure
- Check required files
- Set up environment configuration
- Test database connection
- Run database migrations
- Configure logging
- Test API endpoints

### 2. Manual Setup

If you prefer manual setup or the automated script fails:

#### Step 1: Environment Configuration

1. Copy the environment template:

   ```bash
   cp .env.analytics .env
   ```

2. Update `.env` with your database credentials:
   ```env
   DB_HOST=localhost
   DB_PORT=3306
   DB_NAME=mi_core_db
   DB_USER=your_username
   DB_PASSWORD=your_password
   ```

#### Step 2: Database Setup

1. Run the database migration:

   ```bash
   php apply_regional_analytics_migration.php
   ```

2. Verify the migration:
   ```bash
   php -r "
   require_once 'config.php';
   \$pdo = new PDO('mysql:host=' . DB_HOST . ';dbname=' . DB_NAME, DB_USER, DB_PASSWORD);
   \$stmt = \$pdo->query('SHOW TABLES LIKE \"ozon_regional_sales\"');
   echo (\$stmt->rowCount() > 0) ? 'Migration successful' : 'Migration failed';
   "
   ```

#### Step 3: Web Server Configuration

**Option A: PHP Built-in Server (Development)**

```bash
php -S localhost:8080 -t .
```

**Option B: Apache Virtual Host**

```apache
<VirtualHost *:80>
    ServerName regional-analytics.local
    DocumentRoot /path/to/your/project

    <Directory /path/to/your/project>
        AllowOverride All
        Require all granted
    </Directory>
</VirtualHost>
```

**Option C: Nginx Configuration**

```nginx
server {
    listen 80;
    server_name regional-analytics.local;
    root /path/to/your/project;
    index index.php index.html;

    location ~ \.php$ {
        fastcgi_pass 127.0.0.1:9000;
        fastcgi_index index.php;
        include fastcgi_params;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
    }

    location /api/ {
        try_files $uri $uri/ /api/analytics/index.php?$query_string;
    }
}
```

## Project Structure

```
.
├── api/analytics/                 # Backend API
│   ├── config.php                # API configuration
│   ├── index.php                 # API router
│   └── endpoints/                # API endpoints
│       └── marketplace-comparison.php
├── html/regional-dashboard/       # Frontend dashboard
│   ├── index.html               # Main dashboard page
│   ├── css/
│   │   └── dashboard.css        # Dashboard styles
│   └── js/
│       ├── config.js            # Frontend configuration
│       ├── api.js               # API client
│       ├── charts.js            # Chart management
│       └── dashboard.js         # Main dashboard logic
├── migrations/                   # Database migrations
│   ├── add_regional_analytics_schema.sql
│   └── validate_regional_analytics_schema.sql
├── logs/                        # Application logs
├── .env                         # Environment configuration
└── setup_regional_analytics_dev.sh # Setup script
```

## Development Workflow

### 1. Starting Development

1. **Switch to feature branch:**

   ```bash
   git checkout feature/regional-analytics
   ```

2. **Start development server:**

   ```bash
   php -S localhost:8080 -t .
   ```

3. **Access the application:**
   - Dashboard: http://localhost:8080/html/regional-dashboard/
   - API: http://localhost:8080/api/analytics/

### 2. API Development

#### Testing API Endpoints

**Health Check:**

```bash
curl http://localhost:8080/api/analytics/health
```

**Marketplace Comparison:**

```bash
curl "http://localhost:8080/api/analytics/marketplace-comparison?date_from=2025-09-01&date_to=2025-09-30"
```

**API Documentation:**

```bash
curl http://localhost:8080/api/analytics/
```

#### Adding New Endpoints

1. Create endpoint file in `api/analytics/endpoints/`
2. Add route in `api/analytics/index.php`
3. Update API documentation in `handleApiRoot()` function

### 3. Frontend Development

#### Dashboard Components

- **KPI Cards**: Display key metrics (revenue, orders, regions, avg order)
- **Charts**: Marketplace comparison (pie chart), sales dynamics (line chart)
- **Tables**: Top products with marketplace breakdown
- **Filters**: Date range and marketplace selection

#### JavaScript Architecture

- **config.js**: Configuration and constants
- **api.js**: API client with error handling and retries
- **charts.js**: Chart.js wrapper for data visualization
- **dashboard.js**: Main dashboard controller

### 4. Database Development

#### Schema Changes

1. Create migration SQL file in `migrations/`
2. Update `apply_regional_analytics_migration.php`
3. Test migration on development database
4. Update validation queries

#### Data Access

- Use prepared statements for all queries
- Implement proper error handling
- Add query logging for debugging
- Use database views for complex queries

## Testing

### 1. API Testing

**Manual Testing:**

```bash
# Test all endpoints
curl http://localhost:8080/api/analytics/health
curl http://localhost:8080/api/analytics/marketplace-comparison
curl http://localhost:8080/api/analytics/top-products
curl http://localhost:8080/api/analytics/sales-dynamics
```

**Automated Testing:**

```bash
# Run PHP unit tests (when implemented)
./vendor/bin/phpunit tests/

# Run JavaScript tests (when implemented)
npm test
```

### 2. Frontend Testing

1. **Browser Testing:**

   - Chrome DevTools for debugging
   - Network tab for API calls
   - Console for JavaScript errors

2. **Responsive Testing:**
   - Test on different screen sizes
   - Verify mobile compatibility
   - Check chart responsiveness

### 3. Database Testing

```bash
# Test database connection
php -r "require_once 'config.php'; new PDO('mysql:host=' . DB_HOST . ';dbname=' . DB_NAME, DB_USER, DB_PASSWORD); echo 'OK';"

# Validate schema
mysql -u username -p database_name < migrations/validate_regional_analytics_schema.sql
```

## Troubleshooting

### Common Issues

#### 1. Database Connection Errors

**Error:** `SQLSTATE[HY000] [2002] Connection refused`

**Solutions:**

- Check MySQL service is running
- Verify database credentials in `.env`
- Ensure database exists
- Check firewall settings

#### 2. API 404 Errors

**Error:** `Endpoint not found`

**Solutions:**

- Check web server URL rewriting
- Verify API routing in `index.php`
- Check file permissions
- Review web server configuration

#### 3. JavaScript Console Errors

**Error:** `CORS policy` or `Network Error`

**Solutions:**

- Check CORS headers in API responses
- Verify API base URL in `config.js`
- Test API endpoints directly
- Check browser network tab

#### 4. Chart Display Issues

**Error:** Charts not rendering

**Solutions:**

- Check Chart.js library loading
- Verify canvas elements exist
- Check JavaScript console for errors
- Validate data format

### Debug Mode

Enable debug mode in `.env`:

```env
DEBUG=true
ANALYTICS_LOG_LEVEL=DEBUG
```

This will:

- Enable detailed error logging
- Show SQL queries in logs
- Display API request/response details
- Add performance timing information

### Log Files

- **Application logs:** `logs/analytics.log`
- **Error logs:** `logs/analytics_errors.log`
- **Web server logs:** Check your web server configuration

## Performance Optimization

### Database Optimization

1. **Indexes:** Ensure proper indexes on frequently queried columns
2. **Query optimization:** Use EXPLAIN to analyze query performance
3. **Caching:** Implement query result caching
4. **Connection pooling:** Configure database connection pooling

### Frontend Optimization

1. **Asset minification:** Minify CSS and JavaScript files
2. **Caching:** Implement browser caching headers
3. **Lazy loading:** Load charts and data on demand
4. **Compression:** Enable gzip compression

### API Optimization

1. **Response caching:** Cache expensive API responses
2. **Pagination:** Implement pagination for large datasets
3. **Rate limiting:** Implement API rate limiting
4. **Compression:** Enable response compression

## Security Considerations

### API Security

- Input validation and sanitization
- SQL injection prevention
- Rate limiting
- CORS configuration
- API key authentication (Phase 2)

### Database Security

- Use prepared statements
- Limit database user permissions
- Regular security updates
- Backup encryption

### Frontend Security

- XSS prevention
- Content Security Policy
- HTTPS enforcement
- Secure cookie settings

## Next Steps

After completing the development environment setup:

1. **Implement remaining API endpoints** (top-products, sales-dynamics)
2. **Add comprehensive error handling**
3. **Implement caching layer**
4. **Add unit and integration tests**
5. **Set up CI/CD pipeline**
6. **Prepare for Ozon API integration (Phase 2)**

## Support

For issues or questions:

1. Check this documentation
2. Review log files
3. Test individual components
4. Check requirements and design documents
5. Consult the troubleshooting section

## Requirements Satisfied

This development environment setup satisfies the following requirements:

- **Requirement 5.3:** Database foundation for regional analytics
- **Requirement 6.4:** Development environment configuration
- **Requirement 6.1:** API structure and endpoints
- **Requirement 1.5:** Dashboard interface foundation

The setup provides a solid foundation for implementing the remaining tasks in the Regional Sales Analytics system.
