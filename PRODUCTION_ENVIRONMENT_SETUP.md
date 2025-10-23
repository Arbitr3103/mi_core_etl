# Production Environment Setup Guide

## Overview

This guide provides step-by-step instructions for configuring the production environment for the Warehouse Dashboard deployment on market-mi.ru.

## Task 6.2: Configure Production Environment

This document covers the implementation of task 6.2 from the deployment plan:

-   ✅ Update database credentials in config
-   ✅ Set production API keys
-   ✅ Configure error logging

## Files Created/Modified

### Configuration Files

-   `config/production.php` - Main production configuration
-   `config/error_logging_production.php` - Enhanced error logging
-   `.env.production` - Production environment variables

### Scripts

-   `scripts/setup_production_environment.sh` - Server setup script
-   `scripts/configure_production_env.php` - Environment configuration
-   `scripts/validate_production_deployment.php` - Deployment validation

### API Updates

-   `api/warehouse-dashboard.php` - Updated to use production config

## Production Configuration Details

### Database Credentials

**PostgreSQL (Primary)**

```env
PG_HOST=localhost
PG_USER=mi_core_user
PG_PASSWORD=PostgreSQL_MDM_2025_SecurePass!
PG_NAME=mi_core_db
PG_PORT=5432
```

**MySQL (Legacy Support)**

```env
DB_HOST=localhost
DB_USER=mdm_prod_user
DB_PASSWORD=MDM_Prod_2025_SecurePass!
DB_NAME=mi_core
DB_PORT=3306
```

### API Keys (Production)

```env
OZON_CLIENT_ID=26100
OZON_API_KEY=7e074977-e0db-4ace-ba9e-82903e088b4b
WB_API_KEY=eyJhbGciOiJFUzI1NiIsImtpZCI6IjIwMjUwOTA0djEiLCJ0eXAiOiJKV1QifQ...
```

### Error Logging Configuration

**Log Settings**

```env
LOG_LEVEL=info
LOG_PATH=/var/log/warehouse-dashboard
LOG_MAX_SIZE=100MB
LOG_MAX_FILES=30
LOG_CHANNEL=daily
```

**Features Implemented:**

-   ✅ Structured logging with context
-   ✅ Log rotation and compression
-   ✅ Error level filtering
-   ✅ Performance monitoring
-   ✅ Critical error alerts
-   ✅ Separate error log files
-   ✅ Memory usage tracking

## Deployment Steps

### 1. Configure Environment

```bash
# Run the configuration script
php scripts/configure_production_env.php

# This will:
# - Set all production environment variables
# - Validate database connections
# - Test API keys
# - Create .env.production file
```

### 2. Setup Production Server

```bash
# Run the server setup script (on production server)
sudo ./scripts/setup_production_environment.sh

# This will:
# - Create necessary directories
# - Set file permissions
# - Configure PHP settings
# - Setup log rotation
# - Create monitoring scripts
```

### 3. Validate Deployment

```bash
# Run validation script
php scripts/validate_production_deployment.php

# This will:
# - Test all configurations
# - Validate database connections
# - Check API endpoints
# - Verify logging system
# - Test security settings
```

## Security Features

### Environment Security

-   ✅ Debug mode disabled (`APP_DEBUG=false`)
-   ✅ Error display disabled
-   ✅ Secure credential storage
-   ✅ JWT and encryption keys configured
-   ✅ CORS properly configured

### Error Handling

-   ✅ Production error handlers
-   ✅ Generic error messages for users
-   ✅ Detailed logging for developers
-   ✅ Critical error alerts
-   ✅ Stack trace logging

### File Permissions

-   ✅ Web files: 644 (readable, not writable)
-   ✅ Config files: 600 (owner only)
-   ✅ Log directory: 755 (writable by web server)
-   ✅ Scripts: 755 (executable)

## Monitoring and Logging

### Log Files Location

```
/var/log/warehouse-dashboard/
├── warehouse-dashboard-YYYY-MM-DD.log  # Application logs
├── errors-YYYY-MM-DD.log               # Error logs only
├── php_errors.log                      # PHP errors
└── *.log.gz                           # Compressed old logs
```

### Log Format

```
[2025-10-23 14:30:15] INFO: API Call {"endpoint":"warehouses","method":"GET","duration_ms":45.2}
[2025-10-23 14:30:20] ERROR: Database connection failed {"context":{"host":"localhost","port":"5432"}}
```

### Monitoring Script

```bash
# Run production monitoring
/var/www/market-mi.ru/scripts/monitor_production.sh

# Shows:
# - Disk usage
# - Recent log files
# - API health checks
# - Database status
# - Recent errors
```

## API Configuration

### Production URLs

-   **Dashboard**: https://www.market-mi.ru/warehouse-dashboard
-   **API Base**: https://www.market-mi.ru/api/warehouse-dashboard.php

### CORS Settings

```env
CORS_ENABLED=true
CORS_ALLOWED_ORIGINS=https://www.market-mi.ru,https://market-mi.ru
```

### Rate Limiting

```env
RATE_LIMIT_ENABLED=true
RATE_LIMIT_RPM=60
```

### Caching

```env
API_CACHE_ENABLED=true
API_CACHE_TTL=300
```

## Performance Settings

### PHP Configuration

```ini
memory_limit = 256M
max_execution_time = 60
post_max_size = 50M
upload_max_filesize = 50M
```

### Database Settings

```env
MAX_CONNECTIONS=200
QUERY_TIMEOUT=30
REQUEST_TIMEOUT=30
MAX_RETRIES=3
```

### API Delays

```env
OZON_REQUEST_DELAY=0.1
WB_REQUEST_DELAY=0.5
```

## Troubleshooting

### Common Issues

**Database Connection Failed**

```bash
# Check credentials
php -r "require_once 'config/production.php'; getProductionPgConnection();"

# Check PostgreSQL service
sudo systemctl status postgresql
```

**Log Files Not Created**

```bash
# Check directory permissions
ls -la /var/log/warehouse-dashboard/

# Create directory if missing
sudo mkdir -p /var/log/warehouse-dashboard
sudo chown www-data:www-data /var/log/warehouse-dashboard
```

**API Endpoints Not Working**

```bash
# Test API directly
curl -v https://www.market-mi.ru/api/warehouse-dashboard.php?action=warehouses

# Check web server logs
sudo tail -f /var/log/apache2/error.log
```

### Debug Mode (Development Only)

```env
# NEVER use in production!
APP_DEBUG=true
APP_ENV=development
```

## Validation Checklist

-   [ ] Environment variables configured
-   [ ] Database connections working
-   [ ] API keys validated
-   [ ] Log directory writable
-   [ ] Error logging functional
-   [ ] API endpoints responding
-   [ ] HTTPS enabled
-   [ ] Debug mode disabled
-   [ ] File permissions correct
-   [ ] Monitoring script working

## Next Steps

After completing this configuration:

1. **Deploy Frontend Build**

    ```bash
    cd frontend && npm run build
    # Copy dist/ contents to web server
    ```

2. **Test All Functionality**

    ```bash
    php scripts/validate_production_deployment.php
    ```

3. **Setup Monitoring**

    ```bash
    # Add to crontab for regular monitoring
    */15 * * * * /var/www/market-mi.ru/scripts/monitor_production.sh >> /var/log/warehouse-dashboard/monitoring.log
    ```

4. **Configure Alerts**
    - Set up email alerts for critical errors
    - Configure Slack webhooks if needed
    - Setup uptime monitoring

## Support

For issues with this configuration:

1. Check the validation script output
2. Review log files in `/var/log/warehouse-dashboard/`
3. Run the monitoring script
4. Check database connections
5. Verify API endpoints

---

**Status**: ✅ Task 6.2 Complete - Production environment configured with database credentials, API keys, and error logging.
