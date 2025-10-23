# Warehouse Metrics Cron Setup Guide

This guide explains how to set up and monitor the automated warehouse metrics refresh process.

## Overview

The warehouse metrics refresh system consists of:

1. **Refresh Script** (`scripts/refresh_warehouse_metrics.php`) - Recalculates all warehouse metrics
2. **Cron Wrapper** (`scripts/run_warehouse_metrics_refresh.sh`) - Wrapper for cron execution
3. **Setup Script** (`scripts/setup_warehouse_metrics_cron.sh`) - Automated setup
4. **Health Check** (`scripts/check_warehouse_metrics_health.sh`) - Monitoring script

## Quick Setup

### Automatic Setup

Run the setup script to configure everything automatically:

```bash
./scripts/setup_warehouse_metrics_cron.sh
```

This will:

-   ✓ Verify PHP installation
-   ✓ Create necessary directories
-   ✓ Generate wrapper scripts
-   ✓ Create monitoring tools
-   ✓ Optionally add cron job

### Manual Setup

If you prefer manual setup:

1. **Make scripts executable:**

    ```bash
    chmod +x scripts/refresh_warehouse_metrics.php
    chmod +x scripts/setup_warehouse_metrics_cron.sh
    ```

2. **Edit your crontab:**

    ```bash
    crontab -e
    ```

3. **Add the cron job:**
    ```cron
    # Refresh warehouse metrics every hour
    0 * * * * /path/to/project/scripts/run_warehouse_metrics_refresh.sh
    ```

## Refresh Script Usage

### Basic Usage

```bash
# Refresh all metrics
php scripts/refresh_warehouse_metrics.php

# Verbose output
php scripts/refresh_warehouse_metrics.php --verbose

# Refresh specific product
php scripts/refresh_warehouse_metrics.php --product-id=123

# Refresh specific warehouse
php scripts/refresh_warehouse_metrics.php --warehouse="АДЫГЕЙСК_РФЦ"
```

### Options

| Option             | Description                                 |
| ------------------ | ------------------------------------------- |
| `--product-id=ID`  | Refresh metrics for specific product only   |
| `--warehouse=NAME` | Refresh metrics for specific warehouse only |
| `--verbose`        | Enable verbose output                       |
| `--help`           | Show help message                           |

### What It Does

The refresh script:

1. **Connects to database** - Uses configuration from `config/database.php`
2. **Fetches inventory items** - Gets all products and warehouses to process
3. **Calculates sales metrics** for each item:
    - Average daily sales (last 28 days)
    - Total sales (last 28 days)
    - Days with stock
    - Days without sales
4. **Calculates replenishment metrics**:
    - Target stock (30 days supply)
    - Replenishment need
5. **Calculates liquidity metrics**:
    - Days of stock remaining
    - Liquidity status (critical/low/normal/excess)
6. **Updates database** - Inserts/updates `warehouse_sales_metrics` table
7. **Logs results** - Writes to `logs/warehouse_metrics_refresh.log`

## Cron Schedule Options

### Recommended: Every Hour

```cron
0 * * * * /path/to/project/scripts/run_warehouse_metrics_refresh.sh
```

Best for most use cases. Keeps metrics fresh without overloading the system.

### Every 30 Minutes

```cron
*/30 * * * * /path/to/project/scripts/run_warehouse_metrics_refresh.sh
```

For high-traffic systems requiring more frequent updates.

### Every 2 Hours

```cron
0 */2 * * * /path/to/project/scripts/run_warehouse_metrics_refresh.sh
```

For systems with less frequent inventory changes.

### Business Hours Only

```cron
0 8-18 * * 1-5 /path/to/project/scripts/run_warehouse_metrics_refresh.sh
```

Runs every hour from 8 AM to 6 PM, Monday through Friday.

## Monitoring

### Health Check

Run the health check script to verify the system is working:

```bash
./scripts/check_warehouse_metrics_health.sh
```

This checks:

-   ✓ Last successful refresh time
-   ✓ Recent errors
-   ✓ Database connection
-   ✓ Metrics table status
-   ✓ Total metrics count

### View Logs

**Main refresh log:**

```bash
tail -f logs/warehouse_metrics_refresh.log
```

**Cron execution log:**

```bash
tail -f logs/warehouse_metrics_cron.log
```

**Error log:**

```bash
tail -f logs/warehouse_metrics_cron_errors.log
```

### Log Rotation

To prevent logs from growing too large, set up log rotation:

Create `/etc/logrotate.d/warehouse-metrics`:

```
/path/to/project/logs/warehouse_metrics_*.log {
    daily
    rotate 7
    compress
    delaycompress
    missingok
    notifempty
    create 0644 www-data www-data
}
```

## Troubleshooting

### Cron Job Not Running

1. **Check crontab:**

    ```bash
    crontab -l
    ```

2. **Check cron service:**

    ```bash
    # On Linux
    sudo systemctl status cron

    # On macOS
    sudo launchctl list | grep cron
    ```

3. **Check permissions:**
    ```bash
    ls -la scripts/refresh_warehouse_metrics.php
    ls -la scripts/run_warehouse_metrics_refresh.sh
    ```

### Script Errors

1. **Check error log:**

    ```bash
    cat logs/warehouse_metrics_cron_errors.log
    ```

2. **Run script manually with verbose output:**

    ```bash
    php scripts/refresh_warehouse_metrics.php --verbose
    ```

3. **Check database connection:**
    ```bash
    php config/database.php
    ```

### Performance Issues

If the refresh takes too long:

1. **Check batch size** - Edit `refresh_warehouse_metrics.php`:

    ```php
    $config = [
        'batch_size' => 100,  // Increase for better performance
    ];
    ```

2. **Add database indexes** - Ensure these indexes exist:

    ```sql
    CREATE INDEX idx_inventory_product_warehouse ON inventory(product_id, warehouse_name);
    CREATE INDEX idx_stock_movements_product_warehouse ON stock_movements(product_id, warehouse_name, movement_date);
    ```

3. **Run during off-peak hours** - Adjust cron schedule:
    ```cron
    0 2 * * * /path/to/project/scripts/run_warehouse_metrics_refresh.sh
    ```

### Database Issues

1. **Check table exists:**

    ```sql
    SHOW TABLES LIKE 'warehouse_sales_metrics';
    ```

2. **Check table structure:**

    ```sql
    DESCRIBE warehouse_sales_metrics;
    ```

3. **Check recent metrics:**
    ```sql
    SELECT COUNT(*), MAX(calculated_at)
    FROM warehouse_sales_metrics;
    ```

## Maintenance

### Clean Old Metrics

The script automatically removes metrics older than 7 days. To adjust:

Edit `refresh_warehouse_metrics.php`:

```php
// Change retention period
$cleanupSql = "
    DELETE FROM warehouse_sales_metrics
    WHERE calculated_at < DATE_SUB(NOW(), INTERVAL 30 DAY)  -- Keep 30 days
";
```

### Manual Cleanup

```sql
-- Remove all old metrics
DELETE FROM warehouse_sales_metrics
WHERE calculated_at < DATE_SUB(NOW(), INTERVAL 7 DAY);

-- Remove metrics for specific warehouse
DELETE FROM warehouse_sales_metrics
WHERE warehouse_name = 'OLD_WAREHOUSE';

-- Truncate entire table (careful!)
TRUNCATE TABLE warehouse_sales_metrics;
```

### Backup Before Refresh

To backup metrics before refresh:

```bash
# Backup to SQL file
mysqldump -u username -p database_name warehouse_sales_metrics > backup_metrics.sql

# Restore if needed
mysql -u username -p database_name < backup_metrics.sql
```

## Performance Metrics

Expected performance (approximate):

| Items   | Duration | Memory |
| ------- | -------- | ------ |
| 100     | ~5s      | ~10MB  |
| 1,000   | ~30s     | ~20MB  |
| 10,000  | ~5min    | ~50MB  |
| 100,000 | ~45min   | ~200MB |

Actual performance depends on:

-   Database server speed
-   Network latency
-   Number of sales records
-   Server load

## Integration with Dashboard

The dashboard automatically uses cached metrics from `warehouse_sales_metrics` table:

```php
// In WarehouseService.php
$sql = "
    SELECT
        wsm.daily_sales_avg,
        wsm.liquidity_status,
        wsm.replenishment_need
    FROM warehouse_sales_metrics wsm
    WHERE wsm.product_id = :product_id
        AND wsm.warehouse_name = :warehouse_name
";
```

If metrics are stale (> 2 hours old), the dashboard shows a warning.

## Security Considerations

1. **File Permissions:**

    ```bash
    chmod 755 scripts/
    chmod 644 scripts/*.php
    chmod 755 scripts/*.sh
    chmod 600 config/database.php
    ```

2. **Log Permissions:**

    ```bash
    chmod 755 logs/
    chmod 644 logs/*.log
    ```

3. **Cron User:**

    - Run cron as web server user (www-data, nginx, apache)
    - Or ensure cron user has database access

4. **Database Credentials:**
    - Store in `.env` file (not in version control)
    - Use read-only database user if possible

## Support

For issues or questions:

1. Check logs: `logs/warehouse_metrics_refresh.log`
2. Run health check: `./scripts/check_warehouse_metrics_health.sh`
3. Test manually: `php scripts/refresh_warehouse_metrics.php --verbose`
4. Review this documentation

## Related Documentation

-   [Warehouse Dashboard API](../api/README_WAREHOUSE_DASHBOARD.md)
-   [Database Schema](../migrations/warehouse_dashboard_schema.sql)
-   [Requirements](../.kiro/specs/warehouse-dashboard/requirements.md)
-   [Design](../.kiro/specs/warehouse-dashboard/design.md)
