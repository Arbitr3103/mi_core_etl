# Ozon Active Product Filtering - Deployment Guide

## Overview

This guide provides step-by-step instructions for deploying the Ozon Active Product Filtering system to production. The system optimizes inventory management by filtering only active products (48 instead of 176 total products) based on Ozon API criteria.

## Prerequisites

### System Requirements

- **MySQL 5.7+** or **MariaDB 10.3+**
- **PHP 7.4+** with PDO MySQL extension
- **Linux/Unix environment** with bash shell
- **Database backup capabilities** (mysqldump)
- **Sufficient disk space** for backups and logs

### Access Requirements

- Database administrator access
- Write permissions to application directory
- Ability to modify environment configuration
- Access to application logs directory

### Pre-Deployment Checklist

- [ ] Database connection tested and working
- [ ] Current system backed up
- [ ] All team members notified of deployment
- [ ] Maintenance window scheduled (recommended: 30-60 minutes)
- [ ] Rollback plan reviewed and understood

## Deployment Process

### Step 1: Prepare Deployment Environment

```bash
# Navigate to application directory
cd /path/to/your/application

# Ensure deployment script is executable
chmod +x deploy_active_product_filtering.sh

# Create necessary directories
mkdir -p logs backups migrations
```

### Step 2: Review Configuration

Check your current `.env` file and ensure database credentials are correct:

```bash
# Verify database connection
php -r "
require_once 'config.php';
try {
    \$pdo = getDatabaseConnection();
    echo 'Database connection: OK\n';
} catch (Exception \$e) {
    echo 'Database connection failed: ' . \$e->getMessage() . '\n';
}
"
```

### Step 3: Run Dry Run (Recommended)

Before actual deployment, run a dry run to preview changes:

```bash
./deploy_active_product_filtering.sh --dry-run
```

This will:

- Check all prerequisites
- Validate required files exist
- Show what changes would be made
- **No actual changes applied**

### Step 4: Execute Deployment

Run the full deployment:

```bash
./deploy_active_product_filtering.sh
```

The deployment script will:

1. **Create backup** of current database
2. **Apply database migration** (add activity tracking tables and columns)
3. **Validate migration** (run comprehensive checks)
4. **Update environment configuration** (add new settings)
5. **Test system functionality** (verify everything works)
6. **Display deployment summary**

### Step 5: Verify Deployment

After successful deployment, verify the system:

```bash
# Check database structure
mysql -e "SELECT * FROM v_active_products_stats;" your_database_name

# Test stored procedure
mysql -e "CALL UpdateActivityMonitoringStats();" your_database_name

# Verify API endpoints (if applicable)
curl -s "http://your-domain/api/inventory-analytics.php?action=activity_stats"
```

## Configuration Changes

### New Environment Variables

The deployment adds these environment variables to your `.env` file:

```env
# Active Product Filtering Configuration
OZON_FILTER_ACTIVE_ONLY=true
OZON_ACTIVITY_CHECK_INTERVAL=3600
OZON_STOCK_THRESHOLD=0
ACTIVITY_LOG_RETENTION_DAYS=90
ACTIVITY_CHANGE_NOTIFICATIONS=true

# Performance Configuration
BATCH_SIZE_ACTIVITY_CHECK=100
MAX_CONCURRENT_REQUESTS=5
```

### Configuration Options

| Variable                        | Default | Description                     |
| ------------------------------- | ------- | ------------------------------- |
| `OZON_FILTER_ACTIVE_ONLY`       | `true`  | Enable active product filtering |
| `OZON_ACTIVITY_CHECK_INTERVAL`  | `3600`  | Seconds between activity checks |
| `OZON_STOCK_THRESHOLD`          | `0`     | Minimum stock for active status |
| `ACTIVITY_LOG_RETENTION_DAYS`   | `90`    | Days to keep activity logs      |
| `ACTIVITY_CHANGE_NOTIFICATIONS` | `true`  | Enable change notifications     |
| `BATCH_SIZE_ACTIVITY_CHECK`     | `100`   | Products per batch check        |
| `MAX_CONCURRENT_REQUESTS`       | `5`     | Max concurrent API requests     |

## Database Changes

### New Tables

1. **`product_activity_log`** - Tracks activity status changes
2. **`activity_monitoring_stats`** - Daily activity statistics

### Modified Tables

**`dim_products`** table gets new columns:

- `is_active` (BOOLEAN) - Current activity status
- `activity_checked_at` (TIMESTAMP) - Last check time
- `activity_reason` (VARCHAR) - Reason for current status

### New Database Objects

- **View**: `v_active_products_stats` - Activity statistics
- **Procedure**: `UpdateActivityMonitoringStats()` - Update daily stats
- **Indexes**: Performance optimization indexes

## Post-Deployment Tasks

### 1. Update ETL Processes

Modify your ETL scripts to use the new filtering:

```php
// Example: Update your Ozon data extraction
$extractor = new OzonExtractor($config);
$extractor->setActiveProductsOnly(true); // Enable filtering
$products = $extractor->extractProducts();
```

### 2. Update Dashboard Queries

Modify dashboard queries to filter active products:

```sql
-- Before
SELECT COUNT(*) FROM dim_products WHERE sku_ozon IS NOT NULL;

-- After
SELECT COUNT(*) FROM dim_products WHERE sku_ozon IS NOT NULL AND is_active = 1;
```

### 3. Schedule Activity Monitoring

Add cron job for regular activity monitoring:

```bash
# Add to crontab (runs every hour)
0 * * * * cd /path/to/app && mysql your_db -e "CALL UpdateActivityMonitoringStats();"

# Daily activity report (runs at 6 AM)
0 6 * * * cd /path/to/app && php scripts/generate_activity_report.php
```

### 4. Monitor System Performance

Check system performance after deployment:

```sql
-- Monitor active vs inactive products
SELECT * FROM v_active_products_stats;

-- Check recent activity changes
SELECT * FROM product_activity_log
ORDER BY changed_at DESC
LIMIT 20;

-- Monitor daily statistics
SELECT * FROM activity_monitoring_stats
ORDER BY check_date DESC
LIMIT 7;
```

## Rollback Procedure

If issues occur, you can rollback the deployment:

### Option 1: Automated Rollback

```bash
./deploy_active_product_filtering.sh --rollback
```

This will:

- Remove all activity tracking tables
- Remove activity columns from `dim_products`
- Remove configuration settings
- Restore original database structure

### Option 2: Manual Backup Restore

If automated rollback fails:

```bash
# Find latest backup
ls -la backups/

# Restore from backup
mysql your_database_name < backups/pre_activity_filtering_backup_YYYYMMDD_HHMMSS.sql
```

### Option 3: Selective Rollback

Remove only specific components:

```sql
-- Remove activity tracking columns only
ALTER TABLE dim_products
DROP COLUMN is_active,
DROP COLUMN activity_checked_at,
DROP COLUMN activity_reason;

-- Remove activity tables only
DROP TABLE product_activity_log;
DROP TABLE activity_monitoring_stats;
```

## Troubleshooting

### Common Issues

#### 1. Migration Fails with "Table already exists"

**Cause**: Previous incomplete migration attempt

**Solution**:

```bash
# Check what exists
mysql your_db -e "SHOW TABLES LIKE '%activity%';"

# Clean up and retry
./deploy_active_product_filtering.sh --rollback
./deploy_active_product_filtering.sh
```

#### 2. Foreign Key Constraint Errors

**Cause**: Data integrity issues

**Solution**:

```sql
-- Check for orphaned records
SELECT COUNT(*) FROM dim_products WHERE id NOT IN (
    SELECT DISTINCT product_id FROM product_activity_log WHERE product_id IS NOT NULL
);

-- Clean up if needed
DELETE FROM product_activity_log WHERE product_id NOT IN (
    SELECT id FROM dim_products
);
```

#### 3. Performance Issues After Deployment

**Cause**: Missing indexes or large dataset

**Solution**:

```sql
-- Check index usage
EXPLAIN SELECT * FROM dim_products WHERE is_active = 1;

-- Rebuild indexes if needed
ANALYZE TABLE dim_products;
OPTIMIZE TABLE dim_products;
```

#### 4. API Endpoints Not Filtering

**Cause**: API code not updated

**Solution**:

- Verify `api/inventory-analytics.php` includes `active_only` parameter
- Check that queries include `WHERE is_active = 1`
- Test API endpoints manually

### Validation Commands

```bash
# Validate database structure
./deploy_active_product_filtering.sh --validate

# Test system functionality
./deploy_active_product_filtering.sh --test

# Check configuration
php -r "require_once 'config.php'; printConfigStatus();"
```

## Monitoring and Maintenance

### Daily Monitoring

```sql
-- Check system health
SELECT
    total_products,
    active_products,
    inactive_products,
    active_percentage,
    last_activity_check
FROM v_active_products_stats;
```

### Weekly Maintenance

```sql
-- Clean old activity logs (older than retention period)
DELETE FROM product_activity_log
WHERE changed_at < DATE_SUB(NOW(), INTERVAL 90 DAY);

-- Update activity monitoring stats
CALL UpdateActivityMonitoringStats();
```

### Performance Monitoring

```sql
-- Check query performance
SELECT
    TABLE_NAME,
    INDEX_NAME,
    CARDINALITY
FROM INFORMATION_SCHEMA.STATISTICS
WHERE TABLE_SCHEMA = 'your_database_name'
AND TABLE_NAME = 'dim_products';
```

## Support and Contacts

### Log Files

- **Deployment logs**: `logs/deployment_YYYYMMDD_HHMMSS.log`
- **Application logs**: `logs/application.log`
- **Database logs**: Check MySQL error log

### Key Metrics to Monitor

1. **Active product count** - Should be ~48 products
2. **API response times** - Should improve after filtering
3. **Database query performance** - Monitor slow query log
4. **ETL processing time** - Should decrease with fewer products

### Emergency Contacts

- **Database Administrator**: [Contact Info]
- **Application Developer**: [Contact Info]
- **System Administrator**: [Contact Info]

---

## Appendix

### A. Database Schema Changes

```sql
-- Complete schema changes applied by migration
-- See migrations/add_product_activity_tracking.sql for full details

-- New columns in dim_products
ALTER TABLE dim_products
ADD COLUMN is_active BOOLEAN DEFAULT FALSE,
ADD COLUMN activity_checked_at TIMESTAMP NULL,
ADD COLUMN activity_reason VARCHAR(255) NULL;

-- New indexes
CREATE INDEX idx_dim_products_is_active ON dim_products(is_active);
CREATE INDEX idx_dim_products_activity_checked ON dim_products(activity_checked_at);
CREATE INDEX idx_dim_products_active_updated ON dim_products(is_active, updated_at);
```

### B. Configuration Template

```env
# Copy to .env and customize for your environment

# Database Configuration
DB_HOST=localhost
DB_USER=your_db_user
DB_PASSWORD=your_db_password
DB_NAME=your_database_name
DB_PORT=3306

# Ozon API Configuration
OZON_CLIENT_ID=your_client_id
OZON_API_KEY=your_api_key

# Active Product Filtering
OZON_FILTER_ACTIVE_ONLY=true
OZON_ACTIVITY_CHECK_INTERVAL=3600
OZON_STOCK_THRESHOLD=0
ACTIVITY_LOG_RETENTION_DAYS=90
ACTIVITY_CHANGE_NOTIFICATIONS=true

# Performance Tuning
BATCH_SIZE_ACTIVITY_CHECK=100
MAX_CONCURRENT_REQUESTS=5
REQUEST_TIMEOUT=30
MAX_RETRIES=3
```

### C. Testing Checklist

- [ ] Database migration applied successfully
- [ ] All validation checks pass
- [ ] View returns correct statistics
- [ ] Stored procedure executes without errors
- [ ] API endpoints respond correctly
- [ ] Dashboard shows filtered data (48 vs 176 products)
- [ ] ETL process completes successfully
- [ ] Performance metrics show improvement
- [ ] Backup and rollback procedures tested

---

**Deployment Guide Version**: 1.0  
**Last Updated**: 2025-01-16  
**Requirements**: 3.2, 4.2
