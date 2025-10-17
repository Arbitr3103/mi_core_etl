# Replenishment System Operational Procedures

## Overview

This document outlines the operational procedures for managing the inventory replenishment recommendation system in production.

## Daily Operations

### Morning Checklist (9:00 AM)

1. **Check System Health**

   ```bash
   curl "http://your-server/api/replenishment.php?action=health"
   ```

   Expected response: `{"status": "healthy", "timestamp": "...", "checks": {...}}`

2. **Review Overnight Calculations**

   ```sql
   SELECT
       calculation_date,
       status,
       products_processed,
       recommendations_generated,
       execution_time_seconds,
       error_message
   FROM replenishment_calculations
   WHERE calculation_date >= CURDATE() - INTERVAL 1 DAY
   ORDER BY started_at DESC;
   ```

3. **Check for Errors**

   ```bash
   tail -50 logs/replenishment/error.log
   grep -i "error\|warning" logs/replenishment/calculation.log | tail -20
   ```

4. **Verify Latest Recommendations**
   ```sql
   SELECT
       COUNT(*) as total_recommendations,
       SUM(CASE WHEN recommended_quantity > 0 THEN 1 ELSE 0 END) as actionable_recommendations,
       MAX(calculation_date) as latest_calculation
   FROM replenishment_recommendations;
   ```

### Weekly Operations (Monday Morning)

1. **Review Weekly Calculation Results**

   ```sql
   SELECT
       r.product_name,
       r.sku,
       r.ads,
       r.current_stock,
       r.recommended_quantity,
       CASE
           WHEN r.recommended_quantity > 100 THEN 'High Priority'
           WHEN r.recommended_quantity > 50 THEN 'Medium Priority'
           WHEN r.recommended_quantity > 0 THEN 'Low Priority'
           ELSE 'Sufficient Stock'
       END as priority
   FROM v_latest_replenishment_recommendations r
   WHERE r.recommended_quantity > 0
   ORDER BY r.recommended_quantity DESC
   LIMIT 20;
   ```

2. **Generate Weekly Report**

   ```bash
   cd /var/www/mi_core_etl
   php -r "
   require_once 'src/Replenishment/ReplenishmentRecommender.php';
   \$recommender = new ReplenishmentRecommender();
   \$report = \$recommender->generateWeeklyReport();
   echo \$report;
   "
   ```

3. **Export Recommendations for Procurement Team**
   ```bash
   # Export to CSV
   mysql -u DB_USER -pDB_PASSWORD DB_NAME -e "
   SELECT
       product_name as 'Product Name',
       sku as 'SKU',
       ROUND(ads, 2) as 'Average Daily Sales',
       current_stock as 'Current Stock',
       target_stock as 'Target Stock',
       recommended_quantity as 'Recommended Quantity',
       calculation_date as 'Calculation Date'
   FROM v_latest_replenishment_recommendations
   WHERE recommended_quantity > 0
   ORDER BY recommended_quantity DESC;
   " | sed 's/\t/,/g' > reports/replenishment_$(date +%Y%m%d).csv
   ```

## Monthly Operations

### First Monday of Month

1. **Performance Review**

   ```sql
   -- Monthly performance statistics
   SELECT
       DATE_FORMAT(calculation_date, '%Y-%m') as month,
       AVG(execution_time_seconds) as avg_execution_time,
       AVG(products_processed) as avg_products_processed,
       AVG(recommendations_generated) as avg_recommendations,
       COUNT(*) as calculation_count
   FROM replenishment_calculations
   WHERE status = 'success'
       AND calculation_date >= DATE_SUB(CURDATE(), INTERVAL 3 MONTH)
   GROUP BY DATE_FORMAT(calculation_date, '%Y-%m')
   ORDER BY month DESC;
   ```

2. **Data Quality Review**

   ```sql
   -- Check for products with unusual patterns
   SELECT
       product_name,
       sku,
       AVG(ads) as avg_ads,
       AVG(recommended_quantity) as avg_recommendation,
       COUNT(*) as calculation_count
   FROM replenishment_recommendations
   WHERE calculation_date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
   GROUP BY product_id, product_name, sku
   HAVING avg_ads > 50 OR avg_recommendation > 1000
   ORDER BY avg_recommendation DESC;
   ```

3. **Cleanup Old Data**
   ```sql
   CALL CleanupOldRecommendations(90);
   ```

### Configuration Review (Quarterly)

1. **Review and Update Parameters**

   ```sql
   -- Review current parameters
   SELECT
       parameter_name,
       parameter_value,
       description,
       updated_at
   FROM replenishment_config
   WHERE is_active = TRUE
   ORDER BY parameter_name;
   ```

2. **Analyze Parameter Effectiveness**

   ```sql
   -- Check if thresholds are appropriate
   SELECT
       'Products below ADS threshold' as metric,
       COUNT(*) as count
   FROM v_latest_replenishment_recommendations
   WHERE ads < (SELECT parameter_value FROM replenishment_config WHERE parameter_name = 'min_ads_threshold')

   UNION ALL

   SELECT
       'Products above max recommendation',
       COUNT(*)
   FROM v_latest_replenishment_recommendations
   WHERE recommended_quantity > (SELECT parameter_value FROM replenishment_config WHERE parameter_name = 'max_recommendation_quantity');
   ```

## Incident Response Procedures

### System Down / API Not Responding

1. **Immediate Actions**

   ```bash
   # Check system status
   systemctl status nginx
   systemctl status php7.4-fpm
   systemctl status mysql

   # Check disk space
   df -h

   # Check memory usage
   free -h

   # Check process status
   ps aux | grep php
   ```

2. **Restart Services if Needed**

   ```bash
   sudo systemctl restart php7.4-fpm
   sudo systemctl restart nginx

   # Test API after restart
   curl "http://localhost/api/replenishment.php?action=health"
   ```

3. **Check Logs for Root Cause**
   ```bash
   tail -100 /var/log/nginx/error.log
   tail -100 /var/log/php7.4-fpm.log
   tail -100 logs/replenishment/error.log
   ```

### Database Connection Issues

1. **Check Database Status**

   ```bash
   sudo systemctl status mysql

   # Test connection
   mysql -u DB_USER -pDB_PASSWORD -e "SELECT 1;"
   ```

2. **Check Database Locks**

   ```sql
   SHOW PROCESSLIST;
   SHOW ENGINE INNODB STATUS\G
   ```

3. **Check Disk Space**
   ```bash
   df -h /var/lib/mysql
   ```

### Calculation Failures

1. **Check Recent Calculation Logs**

   ```sql
   SELECT
       id,
       calculation_date,
       status,
       error_message,
       products_processed,
       started_at,
       completed_at
   FROM replenishment_calculations
   WHERE status IN ('error', 'partial')
   ORDER BY started_at DESC
   LIMIT 10;
   ```

2. **Check Detailed Error Logs**

   ```sql
   SELECT
       cd.product_id,
       cd.product_name,
       cd.calculation_status,
       cd.error_message,
       cd.processing_time_ms
   FROM replenishment_calculation_details cd
   JOIN replenishment_calculations c ON cd.calculation_id = c.id
   WHERE c.status = 'error'
       AND cd.calculation_status = 'error'
   ORDER BY cd.created_at DESC
   LIMIT 20;
   ```

3. **Manual Retry**
   ```bash
   cd /var/www/mi_core_etl
   php cron_replenishment_weekly.php --force --debug
   ```

### Performance Issues

1. **Check System Resources**

   ```bash
   top -p $(pgrep php)
   iostat -x 1 5
   ```

2. **Analyze Slow Queries**

   ```sql
   -- Enable slow query log temporarily
   SET GLOBAL slow_query_log = 'ON';
   SET GLOBAL long_query_time = 2;

   -- Check slow queries after some time
   SELECT * FROM mysql.slow_log ORDER BY start_time DESC LIMIT 10;
   ```

3. **Optimize Database if Needed**
   ```sql
   ANALYZE TABLE replenishment_recommendations;
   ANALYZE TABLE fact_orders;
   ANALYZE TABLE inventory_data;
   ```

## Backup and Recovery Procedures

### Daily Backup

```bash
#!/bin/bash
# Daily backup script - add to crontab

BACKUP_DIR="/var/backups/replenishment"
DATE=$(date +%Y%m%d)
DB_NAME="mi_core"

# Create backup directory
mkdir -p "$BACKUP_DIR"

# Backup replenishment tables
mysqldump -u DB_USER -pDB_PASSWORD "$DB_NAME" \
    replenishment_recommendations \
    replenishment_config \
    replenishment_calculations \
    replenishment_calculation_details \
    replenishment_statistics \
    > "$BACKUP_DIR/replenishment_$DATE.sql"

# Compress backup
gzip "$BACKUP_DIR/replenishment_$DATE.sql"

# Remove backups older than 30 days
find "$BACKUP_DIR" -name "replenishment_*.sql.gz" -mtime +30 -delete

echo "Backup completed: replenishment_$DATE.sql.gz"
```

### Recovery Procedure

1. **Stop Calculations**

   ```bash
   # Disable cron job temporarily
   crontab -l | grep -v "cron_replenishment_weekly.php" | crontab -
   ```

2. **Restore from Backup**

   ```bash
   # Find latest backup
   ls -la /var/backups/replenishment/

   # Restore (replace DATE with actual date)
   gunzip /var/backups/replenishment/replenishment_DATE.sql.gz
   mysql -u DB_USER -pDB_PASSWORD mi_core < /var/backups/replenishment/replenishment_DATE.sql
   ```

3. **Verify Recovery**

   ```sql
   SELECT COUNT(*) FROM replenishment_recommendations;
   SELECT MAX(calculation_date) FROM replenishment_recommendations;
   ```

4. **Re-enable Calculations**
   ```bash
   # Add cron job back
   crontab -e
   # Add: 0 6 * * 1 cd /var/www/mi_core_etl && php cron_replenishment_weekly.php
   ```

## Monitoring and Alerting

### Key Metrics to Monitor

1. **System Health**

   - API response time < 2 seconds
   - Database connection successful
   - Disk space > 20% free
   - Memory usage < 80%

2. **Calculation Performance**

   - Weekly calculation completes successfully
   - Execution time < 10 minutes for normal dataset
   - Error rate < 5%
   - Recommendations generated > 0

3. **Data Quality**
   - Products processed matches expected count
   - ADS values within reasonable range (0.1 - 100)
   - No products with negative stock
   - Calculation date is current

### Alert Configuration

```bash
# Add to crontab for monitoring
# Check calculation status every Monday at 7 AM
0 7 * * 1 /var/www/mi_core_etl/scripts/check_calculation_status.sh

# Check API health every 5 minutes
*/5 * * * * /var/www/mi_core_etl/scripts/check_api_health.sh
```

### Alert Scripts

**check_calculation_status.sh**

```bash
#!/bin/bash
RESULT=$(mysql -u DB_USER -pDB_PASSWORD mi_core -e "
SELECT status FROM replenishment_calculations
WHERE calculation_date = CURDATE()
ORDER BY started_at DESC LIMIT 1;" | tail -n 1)

if [ "$RESULT" != "success" ]; then
    echo "Replenishment calculation failed or missing for $(date +%Y-%m-%d)" | \
    mail -s "Replenishment Alert" admin@company.com
fi
```

**check_api_health.sh**

```bash
#!/bin/bash
HTTP_CODE=$(curl -s -o /dev/null -w "%{http_code}" "http://localhost/api/replenishment.php?action=health")

if [ "$HTTP_CODE" != "200" ]; then
    echo "Replenishment API health check failed (HTTP $HTTP_CODE)" | \
    mail -s "API Alert" admin@company.com
fi
```

## Maintenance Procedures

### Weekly Maintenance

1. **Update System Packages**

   ```bash
   sudo apt update && sudo apt upgrade -y
   ```

2. **Check Log File Sizes**

   ```bash
   du -sh logs/replenishment/*
   # Rotate logs if needed
   logrotate -f /etc/logrotate.d/replenishment
   ```

3. **Verify Cron Jobs**
   ```bash
   crontab -l | grep replenishment
   ```

### Monthly Maintenance

1. **Database Optimization**

   ```sql
   OPTIMIZE TABLE replenishment_recommendations;
   OPTIMIZE TABLE replenishment_calculations;
   ```

2. **Index Analysis**

   ```sql
   SHOW INDEX FROM replenishment_recommendations;
   -- Check for unused indexes
   ```

3. **Configuration Backup**
   ```bash
   mysqldump -u DB_USER -pDB_PASSWORD mi_core replenishment_config > config_backup_$(date +%Y%m%d).sql
   ```

## Security Procedures

### Access Control

1. **Regular Password Updates**

   ```sql
   -- Update database user password quarterly
   ALTER USER 'replenishment_user'@'localhost' IDENTIFIED BY 'new_secure_password';
   FLUSH PRIVILEGES;
   ```

2. **Review User Access**

   ```sql
   SELECT User, Host FROM mysql.user WHERE User LIKE '%replenishment%';
   SHOW GRANTS FOR 'replenishment_user'@'localhost';
   ```

3. **File Permission Audit**
   ```bash
   find /var/www/mi_core_etl -name "*.php" -type f -exec ls -la {} \;
   ```

### Security Monitoring

1. **Check for Unauthorized Access**

   ```bash
   grep "replenishment" /var/log/nginx/access.log | grep -v "200\|301\|302"
   ```

2. **Monitor Failed Login Attempts**
   ```bash
   grep "Access denied" /var/log/mysql/error.log
   ```

## Documentation Updates

### When to Update Documentation

1. **Configuration Changes**: Update CONFIGURATION_GUIDE.md
2. **New Procedures**: Update this document
3. **System Changes**: Update deployment scripts
4. **Performance Tuning**: Document optimizations

### Documentation Review Schedule

- **Monthly**: Review operational procedures
- **Quarterly**: Update configuration guide
- **Annually**: Complete documentation audit

## Contact Information

### Escalation Procedures

1. **Level 1**: System Administrator
2. **Level 2**: Database Administrator
3. **Level 3**: Development Team
4. **Level 4**: Business Stakeholders

### Emergency Contacts

- **System Admin**: admin@company.com
- **On-call Phone**: +1-XXX-XXX-XXXX
- **Business Owner**: manager@company.com

## Appendix

### Useful Commands Reference

```bash
# Quick system check
curl "http://localhost/api/replenishment.php?action=health" | jq

# Check latest calculation
mysql -u DB_USER -pDB_PASSWORD mi_core -e "SELECT * FROM replenishment_calculations ORDER BY started_at DESC LIMIT 1\G"

# Force recalculation
cd /var/www/mi_core_etl && php cron_replenishment_weekly.php --force

# Check top recommendations
mysql -u DB_USER -pDB_PASSWORD mi_core -e "SELECT product_name, recommended_quantity FROM v_latest_replenishment_recommendations WHERE recommended_quantity > 0 ORDER BY recommended_quantity DESC LIMIT 10;"

# System resource check
df -h && free -h && uptime
```

### Log File Locations

- **Application Logs**: `logs/replenishment/`
- **Nginx Logs**: `/var/log/nginx/`
- **PHP Logs**: `/var/log/php7.4-fpm.log`
- **MySQL Logs**: `/var/log/mysql/`
- **System Logs**: `/var/log/syslog`
