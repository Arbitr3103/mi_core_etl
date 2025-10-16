# Active Product Filtering - Troubleshooting Guide

## Overview

This guide helps diagnose and resolve common issues with the Ozon Active Product Filtering system. The system filters products based on activity criteria (visibility, state, stock, pricing) to show only relevant products in dashboards.

## Quick Diagnostics

### System Health Check

Run this quick diagnostic to check overall system health:

```bash
# Check database connection and basic functionality
php -r "
require_once 'config.php';
try {
    \$pdo = getDatabaseConnection();
    \$stmt = \$pdo->query('SELECT * FROM v_active_products_stats');
    \$stats = \$stmt->fetch();
    echo 'System Status: OK\n';
    echo 'Total products: ' . \$stats['total_products'] . '\n';
    echo 'Active products: ' . \$stats['active_products'] . '\n';
    echo 'Last check: ' . \$stats['last_activity_check'] . '\n';
} catch (Exception \$e) {
    echo 'System Status: ERROR - ' . \$e->getMessage() . '\n';
}
"
```

### Database Structure Validation

```sql
-- Check if all required tables exist
SELECT
    TABLE_NAME,
    CASE
        WHEN TABLE_NAME IN ('dim_products', 'product_activity_log', 'activity_monitoring_stats')
        THEN 'REQUIRED'
        ELSE 'OPTIONAL'
    END as status
FROM INFORMATION_SCHEMA.TABLES
WHERE TABLE_SCHEMA = DATABASE()
AND TABLE_NAME LIKE '%product%' OR TABLE_NAME LIKE '%activity%';

-- Check if required columns exist in dim_products
DESCRIBE dim_products;
```

## Common Issues and Solutions

### 1. No Products Showing as Active

**Symptoms:**

- Dashboard shows 0 active products
- `v_active_products_stats` shows `active_products = 0`
- All products marked as inactive

**Possible Causes:**

- Activity check never ran
- All products fail activity criteria
- Database migration incomplete

**Diagnosis:**

```sql
-- Check if activity check ever ran
SELECT COUNT(*) as products_never_checked
FROM dim_products
WHERE activity_checked_at IS NULL;

-- Check activity reasons
SELECT activity_reason, COUNT(*) as count
FROM dim_products
WHERE sku_ozon IS NOT NULL
GROUP BY activity_reason;

-- Check system settings
SELECT * FROM system_settings
WHERE setting_key LIKE '%ozon%' OR setting_key LIKE '%activity%';
```

**Solutions:**

1. **Run manual activity check:**

```bash
# If you have ETL scripts
php etl_cli.php run ozon --check-activity-only

# Or update via SQL (temporary fix)
UPDATE dim_products
SET is_active = TRUE,
    activity_reason = 'Manual activation',
    activity_checked_at = NOW()
WHERE sku_ozon IS NOT NULL
AND cost_price > 0;
```

2. **Check Ozon API connectivity:**

```php
// Test API connection
require_once 'config.php';
$client_id = OZON_CLIENT_ID;
$api_key = OZON_API_KEY;

if (empty($client_id) || empty($api_key)) {
    echo "ERROR: Ozon API credentials not configured\n";
} else {
    echo "Ozon API credentials configured\n";
}
```

3. **Verify activity criteria:**

```sql
-- Check current activity criteria settings
SELECT setting_key, setting_value
FROM system_settings
WHERE setting_key IN (
    'ozon_filter_active_only',
    'ozon_stock_threshold',
    'ozon_activity_check_interval'
);
```

### 2. Dashboard Still Shows All Products (176 instead of 48)

**Symptoms:**

- Dashboard counts show 176 products
- Filtering not applied in API responses
- `active_only` parameter ignored

**Possible Causes:**

- API endpoints not updated
- Database queries not modified
- Caching issues

**Diagnosis:**

```bash
# Test API endpoint directly
curl -s "http://your-domain/api/inventory-analytics.php?action=activity_stats" | jq .

# Check if API file has been updated
grep -n "active_only" api/inventory-analytics.php

# Check database query in API
grep -n "is_active" api/inventory-analytics.php
```

**Solutions:**

1. **Update API queries manually:**

```php
// In api/inventory-analytics.php, modify queries like this:

// Before
$sql = "SELECT COUNT(*) FROM dim_products WHERE sku_ozon IS NOT NULL";

// After
$active_only = $_GET['active_only'] ?? 'true';
$active_filter = ($active_only === 'true') ? " AND is_active = 1" : "";
$sql = "SELECT COUNT(*) FROM dim_products WHERE sku_ozon IS NOT NULL" . $active_filter;
```

2. **Clear application cache:**

```bash
# Clear any application caches
rm -rf cache/*
rm -rf tmp/*

# Restart web server if needed
sudo systemctl restart apache2  # or nginx
```

3. **Verify API response:**

```bash
# Test with active_only=false to see all products
curl -s "http://your-domain/api/inventory-analytics.php?action=critical_stock&active_only=false"

# Test with active_only=true (default) to see filtered products
curl -s "http://your-domain/api/inventory-analytics.php?action=critical_stock&active_only=true"
```

### 3. Activity Status Not Updating

**Symptoms:**

- Products remain inactive despite meeting criteria
- `activity_checked_at` timestamp not updating
- Activity log shows no recent changes

**Possible Causes:**

- ETL process not running
- Activity checker not integrated
- API rate limiting or errors

**Diagnosis:**

```sql
-- Check when products were last checked
SELECT
    MAX(activity_checked_at) as last_check,
    COUNT(*) as total_products,
    COUNT(CASE WHEN activity_checked_at IS NULL THEN 1 END) as never_checked
FROM dim_products
WHERE sku_ozon IS NOT NULL;

-- Check recent activity log entries
SELECT * FROM product_activity_log
ORDER BY changed_at DESC
LIMIT 10;

-- Check ETL job runs
SELECT * FROM job_runs
WHERE job_name LIKE '%ozon%'
ORDER BY started_at DESC
LIMIT 5;
```

**Solutions:**

1. **Run manual ETL process:**

```bash
# Run Ozon ETL with activity checking
php etl_cli.php run ozon --force-activity-check

# Or run specific activity check
php scripts/check_product_activity.php
```

2. **Check ETL configuration:**

```sql
-- Verify ETL is configured for activity checking
SELECT * FROM system_settings
WHERE setting_key = 'ozon_filter_active_only';

-- Should return 'true'
```

3. **Monitor API errors:**

```bash
# Check application logs for API errors
tail -f logs/application.log | grep -i "ozon\|activity\|error"

# Check for rate limiting
grep -i "rate limit\|429\|too many requests" logs/*.log
```

### 4. Performance Issues After Deployment

**Symptoms:**

- Dashboard loads slowly
- Database queries timeout
- High CPU/memory usage

**Possible Causes:**

- Missing database indexes
- Inefficient queries
- Large dataset processing

**Diagnosis:**

```sql
-- Check query performance
EXPLAIN SELECT * FROM dim_products WHERE is_active = 1;

-- Check index usage
SHOW INDEX FROM dim_products;

-- Check slow queries
SHOW PROCESSLIST;

-- Check table sizes
SELECT
    TABLE_NAME,
    TABLE_ROWS,
    DATA_LENGTH,
    INDEX_LENGTH
FROM INFORMATION_SCHEMA.TABLES
WHERE TABLE_SCHEMA = DATABASE()
AND TABLE_NAME LIKE '%product%';
```

**Solutions:**

1. **Optimize database indexes:**

```sql
-- Ensure required indexes exist
CREATE INDEX IF NOT EXISTS idx_dim_products_is_active ON dim_products(is_active);
CREATE INDEX IF NOT EXISTS idx_dim_products_active_updated ON dim_products(is_active, updated_at);

-- Analyze tables for better query planning
ANALYZE TABLE dim_products;
ANALYZE TABLE product_activity_log;
```

2. **Optimize queries:**

```sql
-- Use covering indexes where possible
CREATE INDEX idx_products_active_cover ON dim_products(is_active, sku_ozon, product_name, cost_price);

-- Add query hints if needed
SELECT /*+ USE_INDEX(dim_products, idx_dim_products_is_active) */
* FROM dim_products WHERE is_active = 1;
```

3. **Implement query caching:**

```php
// Add caching to frequently used queries
$cache_key = 'active_products_stats';
$stats = $cache->get($cache_key);

if (!$stats) {
    $stmt = $pdo->query('SELECT * FROM v_active_products_stats');
    $stats = $stmt->fetch();
    $cache->set($cache_key, $stats, 300); // Cache for 5 minutes
}
```

### 5. Foreign Key Constraint Errors

**Symptoms:**

- Migration fails with constraint errors
- Cannot insert/update activity log records
- Database integrity errors

**Possible Causes:**

- Orphaned records in database
- Incorrect foreign key relationships
- Data corruption

**Diagnosis:**

```sql
-- Check for orphaned activity log records
SELECT COUNT(*) as orphaned_records
FROM product_activity_log pal
LEFT JOIN dim_products dp ON pal.product_id = dp.id
WHERE dp.id IS NULL;

-- Check foreign key constraints
SELECT
    CONSTRAINT_NAME,
    TABLE_NAME,
    COLUMN_NAME,
    REFERENCED_TABLE_NAME,
    REFERENCED_COLUMN_NAME
FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE
WHERE CONSTRAINT_SCHEMA = DATABASE()
AND REFERENCED_TABLE_NAME IS NOT NULL;
```

**Solutions:**

1. **Clean up orphaned records:**

```sql
-- Remove orphaned activity log records
DELETE FROM product_activity_log
WHERE product_id NOT IN (SELECT id FROM dim_products);

-- Remove orphaned monitoring stats (if any)
DELETE FROM activity_monitoring_stats
WHERE check_date < DATE_SUB(NOW(), INTERVAL 1 YEAR);
```

2. **Recreate foreign key constraints:**

```sql
-- Drop and recreate foreign key constraint
ALTER TABLE product_activity_log
DROP FOREIGN KEY fk_product_activity_log_product;

ALTER TABLE product_activity_log
ADD CONSTRAINT fk_product_activity_log_product
FOREIGN KEY (product_id) REFERENCES dim_products(id)
ON DELETE CASCADE ON UPDATE CASCADE;
```

### 6. API Endpoints Return Errors

**Symptoms:**

- API returns 500 errors
- JSON parsing errors
- Authentication failures

**Possible Causes:**

- PHP syntax errors
- Database connection issues
- Missing dependencies

**Diagnosis:**

```bash
# Check PHP syntax
php -l api/inventory-analytics.php

# Test API endpoint directly
curl -v "http://your-domain/api/inventory-analytics.php?action=activity_stats"

# Check web server error logs
tail -f /var/log/apache2/error.log  # or nginx error log
```

**Solutions:**

1. **Fix PHP errors:**

```bash
# Check for syntax errors
php -l api/inventory-analytics.php

# Check for missing includes
grep -n "require\|include" api/inventory-analytics.php
```

2. **Test database connection:**

```php
// Add to top of API file for debugging
error_reporting(E_ALL);
ini_set('display_errors', 1);

try {
    require_once '../config.php';
    $pdo = getDatabaseConnection();
    echo "Database connection: OK\n";
} catch (Exception $e) {
    echo "Database error: " . $e->getMessage() . "\n";
    exit;
}
```

3. **Check API permissions:**

```bash
# Ensure API files have correct permissions
chmod 644 api/inventory-analytics.php
chown www-data:www-data api/inventory-analytics.php
```

## Monitoring and Alerts

### Set Up Monitoring

1. **Database monitoring:**

```sql
-- Create monitoring view
CREATE OR REPLACE VIEW v_system_health AS
SELECT
    (SELECT COUNT(*) FROM dim_products WHERE sku_ozon IS NOT NULL) as total_products,
    (SELECT COUNT(*) FROM dim_products WHERE is_active = 1) as active_products,
    (SELECT MAX(activity_checked_at) FROM dim_products) as last_activity_check,
    (SELECT COUNT(*) FROM product_activity_log WHERE DATE(changed_at) = CURDATE()) as changes_today,
    NOW() as check_time;
```

2. **Application monitoring:**

```bash
# Create monitoring script
cat > monitor_active_filtering.sh << 'EOF'
#!/bin/bash
HEALTH=$(mysql -sN -e "SELECT active_products FROM v_system_health;")
if [ "$HEALTH" -lt 40 ]; then
    echo "ALERT: Only $HEALTH active products (expected ~48)"
    # Send notification
fi
EOF

chmod +x monitor_active_filtering.sh

# Add to crontab
echo "*/15 * * * * /path/to/monitor_active_filtering.sh" | crontab -
```

### Log Analysis

```bash
# Monitor for common issues
grep -i "error\|warning\|fail" logs/application.log | tail -20

# Check for API rate limiting
grep -i "rate limit\|429" logs/application.log

# Monitor activity changes
grep -i "activity.*changed" logs/application.log | tail -10
```

## Recovery Procedures

### Emergency Rollback

If the system is completely broken:

```bash
# Quick rollback
./deploy_active_product_filtering.sh --rollback

# Or restore from backup
mysql your_database < backups/latest_backup.sql
```

### Partial Recovery

If only specific components are broken:

```sql
-- Reset all products to active (emergency fix)
UPDATE dim_products
SET is_active = TRUE,
    activity_reason = 'Emergency activation',
    activity_checked_at = NOW()
WHERE sku_ozon IS NOT NULL;

-- Clear activity log if corrupted
TRUNCATE TABLE product_activity_log;

-- Reset monitoring stats
TRUNCATE TABLE activity_monitoring_stats;
```

### Data Consistency Check

```sql
-- Comprehensive data consistency check
SELECT
    'Products with NULL activity status' as issue,
    COUNT(*) as count
FROM dim_products
WHERE sku_ozon IS NOT NULL AND is_active IS NULL

UNION ALL

SELECT
    'Activity log entries without products' as issue,
    COUNT(*) as count
FROM product_activity_log pal
LEFT JOIN dim_products dp ON pal.product_id = dp.id
WHERE dp.id IS NULL

UNION ALL

SELECT
    'Products never checked' as issue,
    COUNT(*) as count
FROM dim_products
WHERE sku_ozon IS NOT NULL AND activity_checked_at IS NULL;
```

## Getting Help

### Log Files to Check

1. **Application logs**: `logs/application.log`
2. **ETL logs**: `logs/etl_*.log`
3. **Database logs**: MySQL error log
4. **Web server logs**: Apache/Nginx error logs
5. **Deployment logs**: `logs/deployment_*.log`

### Information to Collect

When reporting issues, include:

1. **System information:**

```bash
php --version
mysql --version
cat /etc/os-release
```

2. **Database statistics:**

```sql
SELECT * FROM v_active_products_stats;
SELECT * FROM v_system_health;
```

3. **Recent logs:**

```bash
tail -50 logs/application.log
tail -20 logs/deployment_*.log
```

4. **Configuration:**

```bash
php -r "require_once 'config.php'; printConfigStatus();"
```

### Support Contacts

- **Database Issues**: Database Administrator
- **API Issues**: Backend Developer
- **Performance Issues**: System Administrator
- **Business Logic**: Product Owner

---

**Troubleshooting Guide Version**: 1.0  
**Last Updated**: 2025-01-16  
**Requirements**: 4.3
