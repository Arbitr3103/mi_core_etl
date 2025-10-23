# 🚀 Warehouse Dashboard Production Deployment Guide

**Version:** 1.0.0  
**Date:** October 23, 2025  
**Target:** https://www.market-mi.ru/warehouse-dashboard

## 📋 Overview

This guide provides comprehensive instructions for deploying the Warehouse Dashboard to the production server at market-mi.ru. The deployment includes a React frontend, PHP backend API, PostgreSQL database, and Nginx web server configuration.

## 🏗️ Architecture Overview

### Production Environment

-   **Domain:** https://www.market-mi.ru
-   **Frontend:** React SPA served as static files
-   **Backend:** PHP 8.1+ with PostgreSQL
-   **Web Server:** Nginx with SSL/HTTPS
-   **Database:** PostgreSQL 15+

### Directory Structure

```
/var/www/market-mi.ru/
├── warehouse-dashboard/          # Frontend static files
│   ├── index.html               # Main entry point
│   ├── assets/                  # CSS, JS, images
│   └── ...
├── api/                         # Backend API files
│   ├── warehouse-dashboard.php  # Main API endpoint
│   ├── classes/                 # PHP classes
│   └── ...
├── config/                      # Configuration files
├── scripts/                     # Maintenance scripts
└── logs/                        # Application logs
```

## 📋 Prerequisites

### Server Requirements

-   **OS:** Ubuntu 20.04+ or similar Linux distribution
-   **PHP:** 8.1+ with extensions: pdo, pdo_pgsql, curl, json, mbstring
-   **PostgreSQL:** 15+
-   **Nginx:** 1.18+
-   **Node.js:** 18+ (for building frontend)
-   **SSL Certificate:** Valid HTTPS certificate
-   **Memory:** Minimum 4GB RAM
-   **Storage:** Minimum 20GB free space

### Access Requirements

-   SSH access to production server
-   Database credentials
-   Git repository access
-   Domain DNS configuration

### Pre-deployment Checklist

-   [ ] All code committed and pushed to repository
-   [ ] Local testing completed successfully
-   [ ] Production environment variables configured
-   [ ] Database backup created
-   [ ] SSL certificate valid and configured
-   [ ] DNS pointing to correct server

## 🔧 Server Configuration

### 1. System Dependencies

```bash
# Update system packages
sudo apt update && sudo apt upgrade -y

# Install PHP 8.1 and extensions
sudo apt install php8.1 php8.1-fpm php8.1-pgsql php8.1-curl \
    php8.1-json php8.1-mbstring php8.1-xml php8.1-zip

# Install PostgreSQL
sudo apt install postgresql postgresql-contrib

# Install Nginx
sudo apt install nginx

# Install Node.js (for building frontend)
curl -fsSL https://deb.nodesource.com/setup_18.x | sudo -E bash -
sudo apt install nodejs

# Install Git
sudo apt install git
```

### 2. Database Setup

```bash
# Switch to postgres user
sudo -u postgres psql

# Create database and user (adjust credentials as needed)
CREATE DATABASE mi_core_db;
CREATE USER mi_core_user WITH ENCRYPTED PASSWORD 'your_secure_password';
GRANT ALL PRIVILEGES ON DATABASE mi_core_db TO mi_core_user;
\c mi_core_db
GRANT ALL ON SCHEMA public TO mi_core_user;
ALTER DATABASE mi_core_db OWNER TO mi_core_user;
\q
```

### 3. Web Server Configuration

The Nginx configuration should include the warehouse dashboard routing:

```nginx
# /etc/nginx/sites-available/market-mi.ru
server {
    server_name market-mi.ru www.market-mi.ru;
    root /var/www/html;
    index index.html index.php;

    # Warehouse Dashboard - Static Files
    location /warehouse-dashboard {
        alias /var/www/market-mi.ru/warehouse-dashboard;
        try_files $uri $uri/ /warehouse-dashboard/index.html;
        index index.html;

        # Cache static assets
        location ~* \.(js|css|png|jpg|jpeg|gif|ico|svg|woff|woff2|ttf|eot)$ {
            expires 1y;
            add_header Cache-Control "public, immutable";
        }
    }

    # API endpoints
    location /api/ {
        alias /var/www/market-mi.ru/api/;

        # CORS headers
        add_header Access-Control-Allow-Origin "*" always;
        add_header Access-Control-Allow-Methods "GET, POST, OPTIONS" always;
        add_header Access-Control-Allow-Headers "Content-Type, Authorization" always;

        # Handle preflight requests
        if ($request_method = 'OPTIONS') {
            add_header Access-Control-Allow-Origin "*";
            add_header Access-Control-Allow-Methods "GET, POST, OPTIONS";
            add_header Access-Control-Allow-Headers "Content-Type, Authorization";
            add_header Access-Control-Max-Age 86400;
            return 204;
        }

        # PHP processing
        location ~ \.php$ {
            fastcgi_pass unix:/var/run/php/php8.1-fpm.sock;
            fastcgi_index index.php;
            fastcgi_param SCRIPT_FILENAME /var/www/market-mi.ru/api$fastcgi_script_name;
            include fastcgi_params;
            fastcgi_read_timeout 300;
        }
    }

    # SSL configuration (managed by Certbot)
    listen 443 ssl;
    ssl_certificate /etc/letsencrypt/live/market-mi.ru/fullchain.pem;
    ssl_certificate_key /etc/letsencrypt/live/market-mi.ru/privkey.pem;
    include /etc/letsencrypt/options-ssl-nginx.conf;
    ssl_dhparam /etc/letsencrypt/ssl-dhparams.pem;
}

# HTTP to HTTPS redirect
server {
    listen 80;
    server_name market-mi.ru www.market-mi.ru;
    return 301 https://$host$request_uri;
}
```

## 🚀 Deployment Process

### Phase 1: Pre-deployment Preparation

#### 1.1 Create Backup

```bash
# Create comprehensive backup
./scripts/backup_procedures.sh backup full

# Verify backup was created
./scripts/backup_procedures.sh list
```

#### 1.2 Verify Local Build

```bash
# Build frontend locally
cd frontend
npm ci
npm run build

# Test build output
ls -la dist/
```

### Phase 2: Code Deployment

#### 2.1 Access Production Server

```bash
# SSH into production server
ssh user@market-mi.ru

# Navigate to web directory
cd /var/www/market-mi.ru
```

#### 2.2 Pull Latest Code

```bash
# Pull latest changes from repository
git fetch origin
git pull origin main

# Verify latest commit
git log --oneline -5
```

#### 2.3 Install Dependencies

```bash
# Install/update PHP dependencies (if composer.json exists)
composer install --no-dev --optimize-autoloader

# Install Node.js dependencies for building
cd frontend
npm ci --production=false
```

### Phase 3: Frontend Build and Deployment

#### 3.1 Build Production Frontend

```bash
# Build optimized frontend
cd frontend
npm run build

# Verify build output
ls -la dist/
du -sh dist/
```

#### 3.2 Deploy Frontend Files

```bash
# Create warehouse dashboard directory
sudo mkdir -p /var/www/market-mi.ru/warehouse-dashboard

# Copy built files
sudo cp -r frontend/dist/* /var/www/market-mi.ru/warehouse-dashboard/

# Set proper permissions
sudo chown -R www-data:www-data /var/www/market-mi.ru/warehouse-dashboard
sudo chmod -R 755 /var/www/market-mi.ru/warehouse-dashboard
```

#### 3.3 Verify Frontend Deployment

```bash
# Check files are in place
ls -la /var/www/market-mi.ru/warehouse-dashboard/

# Test static file serving
curl -I https://www.market-mi.ru/warehouse-dashboard/
```

### Phase 4: Backend API Deployment

#### 4.1 Deploy API Files

```bash
# Copy API files
sudo cp -r api/* /var/www/market-mi.ru/api/

# Copy configuration files
sudo cp -r config/* /var/www/market-mi.ru/config/

# Copy maintenance scripts
sudo cp -r scripts/* /var/www/market-mi.ru/scripts/
```

#### 4.2 Configure Production Environment

```bash
# Create production configuration
sudo cp config/production.php.example /var/www/market-mi.ru/config/production.php

# Edit configuration with production values
sudo nano /var/www/market-mi.ru/config/production.php
```

**Production Configuration Example:**

```php
<?php
// Production Database Configuration
define('DB_HOST', 'localhost');
define('DB_NAME', 'mi_core_db');
define('DB_USER', 'mi_core_user');
define('DB_PASS', 'your_secure_password');
define('DB_PORT', '5432');

// API Configuration
define('API_BASE_URL', 'https://www.market-mi.ru/api');
define('ENVIRONMENT', 'production');
define('DEBUG_MODE', false);

// Cache Configuration
define('CACHE_ENABLED', true);
define('CACHE_TTL', 3600); // 1 hour

// Logging
define('LOG_LEVEL', 'error');
define('LOG_PATH', '/var/www/market-mi.ru/logs');
?>
```

#### 4.3 Set File Permissions

```bash
# Set proper ownership
sudo chown -R www-data:www-data /var/www/market-mi.ru/api
sudo chown -R www-data:www-data /var/www/market-mi.ru/config
sudo chown -R www-data:www-data /var/www/market-mi.ru/scripts

# Set secure permissions
sudo find /var/www/market-mi.ru/api -type f -exec chmod 644 {} \;
sudo find /var/www/market-mi.ru/api -type d -exec chmod 755 {} \;
sudo chmod 600 /var/www/market-mi.ru/config/production.php
```

### Phase 5: Database Migration

#### 5.1 Apply Database Schema

```bash
# Run warehouse dashboard migrations
PGPASSWORD="your_password" psql -h localhost -U mi_core_user -d mi_core_db \
    -f migrations/warehouse_dashboard_schema.sql

# Verify tables were created
PGPASSWORD="your_password" psql -h localhost -U mi_core_user -d mi_core_db \
    -c "\dt warehouse_*"
```

#### 5.2 Import Initial Data

```bash
# Run data import scripts if needed
php /var/www/market-mi.ru/scripts/import_warehouse_data.php

# Verify data import
PGPASSWORD="your_password" psql -h localhost -U mi_core_user -d mi_core_db \
    -c "SELECT COUNT(*) FROM warehouse_sales_metrics;"
```

### Phase 6: Web Server Configuration

#### 6.1 Update Nginx Configuration

```bash
# Test nginx configuration
sudo nginx -t

# Reload nginx if configuration is valid
sudo systemctl reload nginx
```

#### 6.2 Restart Services

```bash
# Restart PHP-FPM
sudo systemctl restart php8.1-fpm

# Restart Nginx
sudo systemctl restart nginx

# Check service status
sudo systemctl status nginx php8.1-fpm
```

## 🧪 Testing and Verification

### 1. Frontend Testing

```bash
# Test main dashboard page
curl -I https://www.market-mi.ru/warehouse-dashboard/

# Test static assets
curl -I https://www.market-mi.ru/warehouse-dashboard/assets/index.css
curl -I https://www.market-mi.ru/warehouse-dashboard/assets/index.js
```

### 2. API Testing

```bash
# Test main API endpoint
curl -s "https://www.market-mi.ru/api/warehouse-dashboard.php" | jq .

# Test specific endpoints
curl -s "https://www.market-mi.ru/api/warehouse-dashboard.php?action=warehouses" | jq .
curl -s "https://www.market-mi.ru/api/warehouse-dashboard.php?action=clusters" | jq .
```

### 3. Database Connectivity

```bash
# Test database connection
php -r "
require_once '/var/www/market-mi.ru/config/production.php';
try {
    \$pdo = new PDO('pgsql:host='.DB_HOST.';port='.DB_PORT.';dbname='.DB_NAME, DB_USER, DB_PASS);
    echo 'Database connection: OK\n';
} catch (Exception \$e) {
    echo 'Database error: ' . \$e->getMessage() . '\n';
}
"
```

### 4. Performance Testing

```bash
# Test page load time
time curl -s https://www.market-mi.ru/warehouse-dashboard/ > /dev/null

# Test API response time
time curl -s "https://www.market-mi.ru/api/warehouse-dashboard.php" > /dev/null
```

### 5. Browser Testing

-   Open https://www.market-mi.ru/warehouse-dashboard in multiple browsers
-   Test all dashboard features (filters, sorting, export)
-   Verify mobile responsiveness
-   Check browser console for errors

## 📊 Monitoring and Maintenance

### 1. Log Monitoring

```bash
# Create log directories
sudo mkdir -p /var/www/market-mi.ru/logs
sudo chown www-data:www-data /var/www/market-mi.ru/logs

# Monitor application logs
tail -f /var/www/market-mi.ru/logs/warehouse_dashboard.log

# Monitor web server logs
tail -f /var/log/nginx/market-mi-access.log
tail -f /var/log/nginx/market-mi-error.log
```

### 2. Performance Monitoring

```bash
# Set up performance monitoring script
cat > /var/www/market-mi.ru/scripts/monitor_performance.sh << 'EOF'
#!/bin/bash
# Monitor warehouse dashboard performance

LOG_FILE="/var/www/market-mi.ru/logs/performance_$(date +%Y%m%d).log"

# Test API response time
API_TIME=$(curl -w "%{time_total}" -s -o /dev/null "https://www.market-mi.ru/api/warehouse-dashboard.php")
echo "$(date): API response time: ${API_TIME}s" >> "$LOG_FILE"

# Test frontend load time
FRONTEND_TIME=$(curl -w "%{time_total}" -s -o /dev/null "https://www.market-mi.ru/warehouse-dashboard/")
echo "$(date): Frontend load time: ${FRONTEND_TIME}s" >> "$LOG_FILE"

# Check if response times are acceptable
if (( $(echo "$API_TIME > 2.0" | bc -l) )); then
    echo "$(date): WARNING - API response time too slow: ${API_TIME}s" >> "$LOG_FILE"
fi
EOF

chmod +x /var/www/market-mi.ru/scripts/monitor_performance.sh
```

### 3. Automated Health Checks

```bash
# Add to crontab for regular health checks
echo "*/5 * * * * /var/www/market-mi.ru/scripts/monitor_performance.sh" | crontab -

# Set up uptime monitoring
echo "*/1 * * * * curl -f https://www.market-mi.ru/warehouse-dashboard/ > /dev/null 2>&1 || echo 'Dashboard down' | mail -s 'Alert: Warehouse Dashboard Down' admin@company.com" | crontab -
```

## 🚨 Troubleshooting Guide

### Common Issues and Solutions

#### 1. Frontend Not Loading

**Symptoms:** 404 error when accessing /warehouse-dashboard
**Solutions:**

```bash
# Check if files exist
ls -la /var/www/market-mi.ru/warehouse-dashboard/

# Check nginx configuration
sudo nginx -t
sudo systemctl reload nginx

# Check file permissions
sudo chown -R www-data:www-data /var/www/market-mi.ru/warehouse-dashboard
```

#### 2. API Returning 500 Errors

**Symptoms:** API endpoints return HTTP 500
**Solutions:**

```bash
# Check PHP error logs
tail -f /var/log/php8.1-fpm.log

# Check nginx error logs
tail -f /var/log/nginx/market-mi-error.log

# Test PHP configuration
php -m | grep pgsql
php -v
```

#### 3. Database Connection Issues

**Symptoms:** API returns database connection errors
**Solutions:**

```bash
# Check PostgreSQL service
sudo systemctl status postgresql

# Test database connection
PGPASSWORD="password" psql -h localhost -U mi_core_user -d mi_core_db -c "SELECT 1;"

# Check database configuration
cat /var/www/market-mi.ru/config/production.php
```

#### 4. CORS Issues

**Symptoms:** Frontend can't access API due to CORS
**Solutions:**

```bash
# Check nginx CORS configuration
sudo nano /etc/nginx/sites-available/market-mi.ru

# Test CORS headers
curl -H "Origin: https://www.market-mi.ru" \
     -H "Access-Control-Request-Method: GET" \
     -H "Access-Control-Request-Headers: X-Requested-With" \
     -X OPTIONS \
     https://www.market-mi.ru/api/warehouse-dashboard.php
```

#### 5. SSL Certificate Issues

**Symptoms:** HTTPS not working or certificate warnings
**Solutions:**

```bash
# Check certificate status
sudo certbot certificates

# Renew certificate if needed
sudo certbot renew

# Test SSL configuration
openssl s_client -connect market-mi.ru:443 -servername market-mi.ru
```

### Emergency Rollback Procedure

If deployment fails and needs immediate rollback:

```bash
# 1. Enable maintenance mode
echo "Maintenance in progress" > /var/www/market-mi.ru/warehouse-dashboard/index.html

# 2. Restore from backup
./scripts/backup_procedures.sh restore /var/backups/warehouse-dashboard/LATEST_BACKUP

# 3. Restart services
sudo systemctl restart nginx php8.1-fpm

# 4. Verify rollback
curl -I https://www.market-mi.ru/warehouse-dashboard/
```

## 📞 Support and Contacts

### Technical Support

-   **Primary Contact:** System Administrator
-   **Email:** admin@company.com
-   **Emergency Phone:** +X-XXX-XXX-XXXX

### Documentation

-   **API Documentation:** `/docs/WAREHOUSE_DASHBOARD_API.md`
-   **User Guide:** `/docs/WAREHOUSE_DASHBOARD_USER_GUIDE.md`
-   **System Architecture:** `/docs/SYSTEM_ARCHITECTURE.md`

### Monitoring URLs

-   **Dashboard:** https://www.market-mi.ru/warehouse-dashboard
-   **API Health:** https://www.market-mi.ru/api/monitoring.php?action=health
-   **Server Status:** https://www.market-mi.ru/api/monitoring.php?action=status

---

## 📝 Deployment Checklist

### Pre-deployment

-   [ ] Code committed and pushed to repository
-   [ ] Local testing completed
-   [ ] Production configuration prepared
-   [ ] Database backup created
-   [ ] SSL certificate verified

### Deployment

-   [ ] Server dependencies installed
-   [ ] Code pulled to production server
-   [ ] Frontend built and deployed
-   [ ] Backend API deployed
-   [ ] Database migrations applied
-   [ ] Web server configured and restarted

### Post-deployment

-   [ ] Frontend accessibility verified
-   [ ] API endpoints tested
-   [ ] Database connectivity confirmed
-   [ ] Performance benchmarks met
-   [ ] Monitoring and logging configured
-   [ ] Documentation updated

### Final Verification

-   [ ] Dashboard loads at https://www.market-mi.ru/warehouse-dashboard
-   [ ] All features working correctly
-   [ ] No errors in logs
-   [ ] Performance within acceptable limits
-   [ ] Mobile responsiveness verified

**Deployment Status:** ✅ Complete / ⚠️ Issues / ❌ Failed  
**Deployed By:** ******\_\_\_\_******  
**Date:** ******\_\_\_\_******  
**Version:** ******\_\_\_\_******

---

_This guide is part of the Warehouse Dashboard production deployment documentation. For updates and additional information, refer to the project repository._
