# Deployment Guide

## Overview

This guide covers the deployment process for the MI Core ETL system, including initial setup, updates, and rollback procedures.

## Prerequisites

### Server Requirements

-   **OS**: Ubuntu 20.04+ or CentOS 8+
-   **PHP**: 8.1 or higher with extensions: pdo, pdo_pgsql, curl, json, mbstring
-   **PostgreSQL**: 15 or higher
-   **Nginx**: 1.18 or higher
-   **Node.js**: 18.0 or higher
-   **Python**: 3.8+ (for ETL scripts)
-   **Memory**: Minimum 4GB RAM (8GB recommended for production)
-   **Storage**: Minimum 50GB free space (SSD recommended)

### Software Installation

```bash
# Update system
sudo apt update && sudo apt upgrade -y

# Install PHP 8.1 and extensions
sudo apt install php8.1 php8.1-fpm php8.1-pgsql php8.1-curl php8.1-json php8.1-mbstring php8.1-xml

# Install PostgreSQL 15
sudo apt install postgresql-15 postgresql-contrib-15

# Install Python and pip
sudo apt install python3 python3-pip python3-venv

# Install Nginx
sudo apt install nginx

# Install Node.js
curl -fsSL https://deb.nodesource.com/setup_18.x | sudo -E bash -
sudo apt install nodejs

# Install Composer
curl -sS https://getcomposer.org/installer | php
sudo mv composer.phar /usr/local/bin/composer
```

## Initial Deployment

### 1. Project Setup

```bash
# Clone repository
cd /var/www
sudo git clone <repository-url> mi_core_etl
cd mi_core_etl

# Set ownership
sudo chown -R www-data:www-data /var/www/mi_core_etl
sudo chmod -R 755 /var/www/mi_core_etl
```

### 2. Environment Configuration

```bash
# Copy environment file
cp .env.example .env

# Edit configuration
nano .env
```

**Required Environment Variables:**

```env
# PostgreSQL Database
PG_HOST=localhost
PG_NAME=mi_core_db
PG_USER=mi_core_user
PG_PASS=secure_password
PG_PORT=5432

# API Configuration
API_BASE_URL=https://your-domain.com/api
RATE_LIMIT_RPM=60

# Cache
CACHE_DRIVER=file
CACHE_TTL=300

# Logging
LOG_LEVEL=info
LOG_PATH=/var/www/mi_core_etl/storage/logs

# External APIs
OZON_API_KEY=your_ozon_key
WB_API_KEY=your_wb_key

# Application
APP_ENV=production
APP_DEBUG=false
```

### 3. Database Setup

```bash
# Start PostgreSQL service
sudo systemctl start postgresql
sudo systemctl enable postgresql

# Create database and user
sudo -u postgres psql << EOF
CREATE DATABASE mi_core_db;
CREATE USER mi_core_user WITH ENCRYPTED PASSWORD 'secure_password';
GRANT ALL PRIVILEGES ON DATABASE mi_core_db TO mi_core_user;
\c mi_core_db
GRANT ALL ON SCHEMA public TO mi_core_user;
ALTER DATABASE mi_core_db OWNER TO mi_core_user;
EOF

# Run migrations
psql -h localhost -U mi_core_user -d mi_core_db -f migrations/postgresql_schema.sql

# Verify connection
psql -h localhost -U mi_core_user -d mi_core_db -c "SELECT version();"
```

**Note**: For migrating from MySQL to PostgreSQL, see the [PostgreSQL Migration Guide](../migrations/README_POSTGRESQL_MIGRATION.md).

### 4. Install Dependencies

```bash
# Install PHP dependencies
composer install --no-dev --optimize-autoloader

# Install Node.js dependencies and build
npm run install:all
npm run build:prod
```

### 5. Nginx Configuration

Create `/etc/nginx/sites-available/mi_core_etl`:

```nginx
server {
    listen 80;
    server_name your-domain.com;
    root /var/www/mi_core_etl/public;
    index index.php index.html;

    # API routes
    location /api/ {
        try_files $uri $uri/ /api/index.php?$query_string;
    }

    # PHP handling
    location ~ \.php$ {
        fastcgi_pass unix:/var/run/php/php8.1-fpm.sock;
        fastcgi_index index.php;
        fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name;
        include fastcgi_params;
    }

    # Static files
    location /build/ {
        expires 1y;
        add_header Cache-Control "public, immutable";
    }

    # Security headers
    add_header X-Frame-Options "SAMEORIGIN";
    add_header X-Content-Type-Options "nosniff";
}
```

Enable the site:

```bash
sudo ln -s /etc/nginx/sites-available/mi_core_etl /etc/nginx/sites-enabled/
sudo nginx -t
sudo systemctl reload nginx
```

### 6. Set Up Cron Jobs

```bash
# Edit crontab
crontab -e

# Add ETL jobs
0 */6 * * * cd /var/www/mi_core_etl && php src/etl/ozon_sync.php
0 2 * * * cd /var/www/mi_core_etl && php src/etl/daily_inventory_sync.php
0 0 * * 0 cd /var/www/mi_core_etl && php src/etl/weekly_reports.php
```

## Automated Deployment

### Using Deployment Script

```bash
# Make scripts executable
chmod +x deployment/scripts/*.sh

# Run deployment
./deployment/scripts/deploy.sh
```

### Deployment Process

The automated deployment script performs:

1. **Backup Creation**: Creates full system backup
2. **Dependency Installation**: Updates PHP and Node.js dependencies
3. **Frontend Build**: Builds React application
4. **Permission Setting**: Sets correct file permissions
5. **Cache Clearing**: Clears application cache
6. **Service Restart**: Restarts Nginx and PHP-FPM
7. **Health Check**: Verifies deployment success

## Updates and Maintenance

### Regular Updates

```bash
# Pull latest changes
git pull origin main

# Run deployment script
./deployment/scripts/deploy.sh
```

### Manual Update Process

```bash
# 1. Create backup
./deployment/scripts/backup.sh

# 2. Pull changes
git pull origin main

# 3. Update dependencies
composer install --no-dev --optimize-autoloader

# 4. Build frontend (if changed)
cd frontend && npm ci && npm run build
cp -r dist/* ../public/build/

# 5. Clear cache
rm -rf storage/cache/*

# 6. Restart services
sudo systemctl reload nginx
sudo systemctl restart php8.1-fpm
```

## Rollback Procedures

### Automated Rollback

```bash
# Rollback to previous version
./deployment/scripts/rollback.sh
```

### Manual Rollback

```bash
# 1. Find backup
ls -la storage/backups/

# 2. Extract backup
cd storage/backups
tar -xzf mi_core_etl_backup_YYYYMMDD_HHMMSS.tar.gz

# 3. Restore files
cp -r backup_folder/* /var/www/mi_core_etl/

# 4. Restore database
mysql -u mi_core_user -p mi_core_db < backup_folder/database.sql

# 5. Restart services
sudo systemctl reload nginx
sudo systemctl restart php8.1-fpm
```

## Monitoring and Health Checks

### Automated Monitoring

```bash
# Add to crontab for monitoring
*/5 * * * * curl -f http://localhost/api/health || echo "Health check failed" | mail -s "MI Core ETL Alert" admin@example.com
```

### Manual Health Checks

```bash
# API health
curl http://your-domain.com/api/health

# Database connectivity
php src/utils/check_database.php

# Disk space
df -h

# Service status
sudo systemctl status nginx php8.1-fpm mysql
```

## Troubleshooting

### Common Issues

1. **Permission Errors**

    ```bash
    sudo chown -R www-data:www-data /var/www/mi_core_etl
    sudo chmod -R 755 storage/ public/
    ```

2. **Database Connection Issues**

    ```bash
    # Test PostgreSQL connection
    psql -h localhost -U mi_core_user -d mi_core_db -c "SELECT 1;"

    # Check PHP PostgreSQL extension
    php -m | grep pgsql

    # Check PostgreSQL service status
    sudo systemctl status postgresql

    # View PostgreSQL logs
    sudo tail -f /var/log/postgresql/postgresql-15-main.log
    ```

3. **Nginx Configuration Issues**

    ```bash
    # Test configuration
    sudo nginx -t

    # Check error logs
    sudo tail -f /var/log/nginx/error.log
    ```

### Log Locations

-   **Application Logs**: `/var/www/mi_core_etl/storage/logs/`
-   **Nginx Logs**: `/var/log/nginx/`
-   **PHP-FPM Logs**: `/var/log/php8.1-fpm.log`
-   **PostgreSQL Logs**: `/var/log/postgresql/`
-   **ETL Logs**: `/var/www/mi_core_etl/storage/logs/etl/`

## Security Considerations

### File Permissions

```bash
# Set secure permissions
find /var/www/mi_core_etl -type f -exec chmod 644 {} \;
find /var/www/mi_core_etl -type d -exec chmod 755 {} \;
chmod +x deployment/scripts/*.sh
```

### Environment Security

-   Keep `.env` file secure (not in version control)
-   Use strong database passwords
-   Regularly update system packages
-   Monitor access logs

## Performance Optimization

### PHP-FPM Tuning

Edit `/etc/php/8.1/fpm/pool.d/www.conf`:

```ini
pm = dynamic
pm.max_children = 50
pm.start_servers = 5
pm.min_spare_servers = 5
pm.max_spare_servers = 35
```

### PostgreSQL Optimization

Edit `/etc/postgresql/15/main/postgresql.conf`:

```ini
# Memory Settings
shared_buffers = 256MB                  # 25% of RAM
effective_cache_size = 1GB              # 50-75% of RAM
work_mem = 4MB                          # Per operation
maintenance_work_mem = 64MB             # For VACUUM, CREATE INDEX

# Checkpoint Settings
checkpoint_completion_target = 0.9
wal_buffers = 16MB

# Connection Settings
max_connections = 200

# Query Planner
random_page_cost = 1.1                  # For SSD
effective_io_concurrency = 200          # For SSD

# Logging
log_min_duration_statement = 1000       # Log slow queries (>1s)
log_line_prefix = '%t [%p]: [%l-1] user=%u,db=%d,app=%a,client=%h '
```

After editing, restart PostgreSQL:

```bash
sudo systemctl restart postgresql
```

### Nginx Caching

```nginx
# Add to server block
location ~* \.(css|js|png|jpg|jpeg|gif|ico|svg)$ {
    expires 1y;
    add_header Cache-Control "public, immutable";
}
```
