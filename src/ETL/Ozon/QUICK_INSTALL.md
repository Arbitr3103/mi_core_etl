# Ozon ETL System - Quick Installation Guide

## Prerequisites

-   Linux/macOS/Windows server
-   PHP 8.1+ with extensions: pdo, pdo_pgsql, curl, json, mbstring, xml
-   PostgreSQL 13+
-   Cron service
-   Internet access to Ozon API

## Quick Installation (Linux/macOS)

### 1. Automated Installation

```bash
# Download and run the deployment script
sudo ./src/ETL/Ozon/Scripts/deploy.sh --environment production

# Follow the prompts and configure your .env file
sudo nano /opt/ozon-etl/.env
```

### 2. Manual Installation

#### Step 1: Install Dependencies

```bash
# Ubuntu/Debian
sudo apt update
sudo apt install -y php8.1 php8.1-cli php8.1-pdo php8.1-pgsql php8.1-curl php8.1-json php8.1-mbstring php8.1-xml postgresql-client cron

# CentOS/RHEL
sudo yum install -y php php-cli php-pdo php-pgsql php-curl php-json php-mbstring php-xml postgresql cronie
```

#### Step 2: Create Application Directory

```bash
sudo mkdir -p /opt/ozon-etl
sudo cp -r src/ETL/Ozon/* /opt/ozon-etl/
sudo chown -R www-data:www-data /opt/ozon-etl
sudo chmod -R 755 /opt/ozon-etl
sudo chmod -R 777 /opt/ozon-etl/Logs
```

#### Step 3: Configure Environment

```bash
# Copy configuration template
sudo cp /opt/ozon-etl/Config/.env.production.example /opt/ozon-etl/.env

# Edit configuration
sudo nano /opt/ozon-etl/.env
```

#### Step 4: Setup Database

```bash
# Create database and user
sudo -u postgres psql -c "CREATE DATABASE ozon_etl_prod;"
sudo -u postgres psql -c "CREATE USER ozon_etl_user WITH PASSWORD 'your_password';"
sudo -u postgres psql -c "GRANT ALL PRIVILEGES ON DATABASE ozon_etl_prod TO ozon_etl_user;"

# Run migrations
psql -h localhost -U ozon_etl_user -d ozon_etl_prod -f /opt/ozon-etl/migrations/007_create_ozon_etl_schema.sql
```

#### Step 5: Install Cron Jobs

```bash
# Install scheduler
sudo -u www-data php /opt/ozon-etl/Scripts/install_scheduler.php --environment=production
```

#### Step 6: Test Installation

```bash
# Run system test
sudo -u www-data php /opt/ozon-etl/Tests/SimplifiedE2ETest.php

# Check health
sudo -u www-data php /opt/ozon-etl/Scripts/health_check.php
```

## Configuration

### Required Environment Variables

```bash
# Database
PG_HOST=localhost
PG_PORT=5432
PG_NAME=ozon_etl_prod
PG_USER=ozon_etl_user
PG_PASSWORD=your_secure_password

# Ozon API
OZON_CLIENT_ID=your_client_id
OZON_API_KEY=your_api_key

# Application
APP_ENV=production
ETL_MEMORY_LIMIT=1024M
LOG_LEVEL=INFO
```

## Verification

### Check Services

```bash
# Verify cron jobs
sudo crontab -u www-data -l

# Check logs
tail -f /opt/ozon-etl/Logs/ozon-etl.log

# Test database connection
psql -h localhost -U ozon_etl_user -d ozon_etl_prod -c "SELECT COUNT(*) FROM etl_execution_log;"
```

### Run Initial Sync

```bash
# Sync products
sudo -u www-data php /opt/ozon-etl/Scripts/sync_products.php --verbose

# Sync sales
sudo -u www-data php /opt/ozon-etl/Scripts/sync_sales.php --verbose

# Sync inventory
sudo -u www-data php /opt/ozon-etl/Scripts/sync_inventory.php --verbose
```

## Troubleshooting

### Common Issues

1. **Permission Denied**

    ```bash
    sudo chown -R www-data:www-data /opt/ozon-etl
    sudo chmod -R 755 /opt/ozon-etl
    ```

2. **Database Connection Failed**

    - Check PostgreSQL service: `sudo systemctl status postgresql`
    - Verify credentials in `.env` file
    - Test connection: `psql -h localhost -U ozon_etl_user -d ozon_etl_prod`

3. **API Connection Failed**

    - Verify Ozon API credentials
    - Check internet connectivity
    - Test API: `curl -H "Client-Id: your_id" -H "Api-Key: your_key" https://api-seller.ozon.ru/v2/product/list`

4. **Cron Jobs Not Running**
    - Check cron service: `sudo systemctl status cron`
    - Verify cron jobs: `sudo crontab -u www-data -l`
    - Check cron logs: `sudo tail -f /var/log/cron.log`

### Getting Help

-   Check the full deployment guide: `/opt/ozon-etl/DEPLOYMENT_GUIDE.md`
-   Review application logs: `/opt/ozon-etl/Logs/`
-   Test system health: `php /opt/ozon-etl/Scripts/health_check.php --verbose`

## Next Steps

1. Monitor the system for 24 hours
2. Review and adjust cron schedules if needed
3. Set up monitoring and alerting
4. Configure backups
5. Review performance and optimize as needed

For detailed information, see the complete [Deployment Guide](DEPLOYMENT_GUIDE.md).
