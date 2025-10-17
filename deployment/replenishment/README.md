# Replenishment System Deployment Guide

## Overview

This directory contains all the necessary files and scripts for deploying the inventory replenishment recommendation system to production.

## Quick Start

### 1. Automated Deployment (Recommended)

```bash
# Clone or update the repository
cd /var/www/mi_core_etl

# Run the deployment script
./deployment/replenishment/deploy_replenishment_system.sh production

# Setup monitoring
./deployment/replenishment/setup_monitoring.sh production
```

### 2. Manual Deployment

If you prefer manual deployment or need to customize the process:

```bash
# 1. Deploy database schema
mysql -u DB_USER -pDB_PASSWORD DB_NAME < deployment/replenishment/migrate_replenishment_system.sql

# 2. Copy configuration files
cp deployment/replenishment/production/config.production.php config.php
cp deployment/replenishment/production/.env.production .env

# 3. Update configuration with your values
nano config.php
nano .env

# 4. Set up cron job
crontab -e
# Add: 0 6 * * 1 cd /var/www/mi_core_etl && php cron_replenishment_weekly.php

# 5. Test the system
php api/replenishment.php?action=health
```

## File Structure

```
deployment/replenishment/
├── README.md                           # This file
├── deploy_replenishment_system.sh      # Main deployment script
├── migrate_replenishment_system.sql    # Database migration
├── rollback_replenishment_system.sql   # Rollback script
├── setup_monitoring.sh                 # Monitoring setup
├── CONFIGURATION_GUIDE.md              # Configuration documentation
├── OPERATIONAL_PROCEDURES.md           # Operations manual
└── production/
    ├── config.production.php           # Production config template
    ├── .env.production                 # Environment variables template
    └── nginx.replenishment.conf        # Nginx configuration
```

## Prerequisites

### System Requirements

- **Operating System**: Linux (Ubuntu 18.04+ or CentOS 7+)
- **PHP**: 7.4 or higher
- **MySQL**: 5.7 or higher (or MariaDB 10.2+)
- **Web Server**: Nginx or Apache
- **Memory**: Minimum 2GB RAM
- **Disk Space**: Minimum 10GB free space

### Required PHP Extensions

```bash
# Install required PHP extensions
sudo apt update
sudo apt install php7.4-cli php7.4-fpm php7.4-mysql php7.4-json php7.4-mbstring php7.4-curl
```

### Database Setup

```sql
-- Create dedicated database user
CREATE USER 'replenishment_user'@'localhost' IDENTIFIED BY 'secure_password';
GRANT SELECT, INSERT, UPDATE, DELETE ON mi_core.replenishment_* TO 'replenishment_user'@'localhost';
GRANT SELECT ON mi_core.fact_orders TO 'replenishment_user'@'localhost';
GRANT SELECT ON mi_core.inventory_data TO 'replenishment_user'@'localhost';
GRANT SELECT ON mi_core.dim_products TO 'replenishment_user'@'localhost';
FLUSH PRIVILEGES;
```

## Deployment Options

### Option 1: Full Automated Deployment

Best for new installations or complete system updates.

```bash
./deployment/replenishment/deploy_replenishment_system.sh production
```

**Features:**

- ✅ Complete database migration
- ✅ Application file deployment
- ✅ Configuration setup
- ✅ Automated testing
- ✅ Backup creation
- ✅ Rollback capability

### Option 2: Dry Run Deployment

Test the deployment process without making changes.

```bash
./deployment/replenishment/deploy_replenishment_system.sh production --dry-run
```

### Option 3: Database Only

Deploy only database changes.

```bash
mysql -u DB_USER -pDB_PASSWORD DB_NAME < deployment/replenishment/migrate_replenishment_system.sql
```

### Option 4: Monitoring Setup Only

Set up monitoring for existing installation.

```bash
./deployment/replenishment/setup_monitoring.sh production
```

## Configuration

### Environment Variables

Copy and customize the environment file:

```bash
cp deployment/replenishment/production/.env.production .env
nano .env
```

Key settings to update:

- `DB_PASSWORD`: Your database password
- `SMTP_USERNAME` and `SMTP_PASSWORD`: Email credentials
- `EMAIL_RECIPIENTS`: Email addresses for reports
- `IP_WHITELIST`: Allowed IP addresses for API access

### Application Configuration

Copy and customize the config file:

```bash
cp deployment/replenishment/production/config.production.php config.php
nano config.php
```

Update the database credentials and other settings as needed.

### Web Server Configuration

For Nginx, use the provided configuration:

```bash
# Copy nginx configuration
sudo cp deployment/replenishment/production/nginx.replenishment.conf /etc/nginx/sites-available/replenishment
sudo ln -s /etc/nginx/sites-available/replenishment /etc/nginx/sites-enabled/
sudo nginx -t
sudo systemctl reload nginx
```

## Post-Deployment Steps

### 1. Verify Installation

```bash
# Check system health
curl "http://your-server/api/replenishment.php?action=health"

# Check database tables
mysql -u DB_USER -pDB_PASSWORD DB_NAME -e "SHOW TABLES LIKE 'replenishment_%';"

# Test configuration
curl "http://your-server/api/replenishment.php?action=config"
```

### 2. Run Initial Calculation

```bash
cd /var/www/mi_core_etl
php cron_replenishment_weekly.php
```

### 3. Setup Cron Jobs

```bash
crontab -e
```

Add the following lines:

```bash
# Weekly replenishment calculation (Monday 6 AM)
0 6 * * 1 cd /var/www/mi_core_etl && php cron_replenishment_weekly.php

# Health check every 5 minutes
*/5 * * * * /var/www/mi_core_etl/scripts/health_check_replenishment.sh --alert

# Performance monitoring every 15 minutes
*/15 * * * * /var/www/mi_core_etl/scripts/performance_monitor_replenishment.sh
```

### 4. Configure Email Alerts

```bash
# Install mail utilities
sudo apt install mailutils

# Test email sending
echo "Test message" | mail -s "Test Subject" admin@company.com
```

### 5. Access Dashboard

Open in browser: `http://your-server/html/replenishment_dashboard.php`

## Monitoring and Maintenance

### Health Monitoring

- **Health Check**: `http://your-server/api/replenishment.php?action=health`
- **Monitoring Dashboard**: `http://your-server/html/monitoring_dashboard.php`
- **Log Files**: `/var/log/replenishment/`

### Regular Maintenance

- **Daily**: Check health status and error logs
- **Weekly**: Review calculation results and performance
- **Monthly**: Clean up old data and optimize database
- **Quarterly**: Review and update configuration parameters

### Performance Optimization

```sql
-- Optimize database tables monthly
OPTIMIZE TABLE replenishment_recommendations;
OPTIMIZE TABLE replenishment_calculations;

-- Clean up old data
CALL CleanupOldRecommendations(90);
```

## Troubleshooting

### Common Issues

1. **Database Connection Error**

   ```bash
   # Check database credentials in config.php
   # Test connection manually
   mysql -u DB_USER -pDB_PASSWORD DB_NAME -e "SELECT 1;"
   ```

2. **API Not Responding**

   ```bash
   # Check web server status
   sudo systemctl status nginx
   sudo systemctl status php7.4-fpm

   # Check error logs
   tail -f /var/log/nginx/error.log
   tail -f /var/log/php7.4-fpm.log
   ```

3. **Calculation Failures**

   ```bash
   # Check calculation logs
   tail -f logs/replenishment/calculation.log

   # Run calculation manually with debug
   php cron_replenishment_weekly.php --debug
   ```

4. **Permission Issues**
   ```bash
   # Fix file permissions
   sudo chown -R www-data:www-data /var/www/mi_core_etl
   sudo chmod -R 644 src/Replenishment/*.php
   sudo chmod +x cron_replenishment_weekly.php
   ```

### Getting Help

1. Check the logs in `/var/log/replenishment/`
2. Review the configuration in `config.php`
3. Test individual components manually
4. Check system resources (CPU, memory, disk space)
5. Verify database connectivity and permissions

## Rollback Procedure

If you need to rollback the deployment:

```bash
# 1. Stop the system
sudo systemctl stop nginx

# 2. Rollback database changes
mysql -u DB_USER -pDB_PASSWORD DB_NAME < deployment/replenishment/rollback_replenishment_system.sql

# 3. Restore files from backup
# (Backup location is shown in deployment log)

# 4. Remove cron jobs
crontab -e
# Remove replenishment-related lines

# 5. Restart services
sudo systemctl start nginx
```

## Security Considerations

### Production Security Checklist

- [ ] Change default database passwords
- [ ] Configure IP whitelist for API access
- [ ] Enable HTTPS with SSL certificates
- [ ] Set up proper file permissions
- [ ] Configure firewall rules
- [ ] Enable audit logging
- [ ] Regular security updates

### Security Best Practices

1. **Database Security**

   - Use dedicated database user with minimal permissions
   - Regular password rotation
   - Enable query logging for audit

2. **API Security**

   - Implement rate limiting
   - Use API keys for authentication
   - Validate all input parameters
   - Log all API requests

3. **File Security**
   - Restrict access to configuration files
   - Set proper file permissions
   - Regular backup of sensitive data

## Performance Tuning

### Database Optimization

```sql
-- Add indexes for better performance
CREATE INDEX idx_fact_orders_replenishment ON fact_orders (product_id, order_date, qty);
CREATE INDEX idx_inventory_replenishment ON inventory_data (product_id, current_stock, created_at);

-- Optimize MySQL settings
SET GLOBAL innodb_buffer_pool_size = 1073741824; -- 1GB
SET GLOBAL query_cache_size = 268435456; -- 256MB
```

### Application Optimization

```php
// In config.php
ini_set('memory_limit', '512M');
ini_set('max_execution_time', 300);

// Enable opcache
ini_set('opcache.enable', 1);
ini_set('opcache.memory_consumption', 128);
```

### Web Server Optimization

```nginx
# In nginx configuration
worker_processes auto;
worker_connections 1024;

# Enable gzip compression
gzip on;
gzip_types text/plain application/json;

# Enable caching for static files
location ~* \.(css|js|png|jpg|jpeg|gif|ico|svg)$ {
    expires 1y;
    add_header Cache-Control "public, immutable";
}
```

## Support and Documentation

### Documentation Files

- **Configuration Guide**: `CONFIGURATION_GUIDE.md`
- **Operational Procedures**: `OPERATIONAL_PROCEDURES.md`
- **Requirements**: `.kiro/specs/inventory-replenishment-recommendations/requirements.md`
- **Design Document**: `.kiro/specs/inventory-replenishment-recommendations/design.md`
- **Task List**: `.kiro/specs/inventory-replenishment-recommendations/tasks.md`

### Log Files

- **Deployment**: `deployment/replenishment/deployment_*.log`
- **Application**: `logs/replenishment/calculation.log`
- **Errors**: `logs/replenishment/error.log`
- **API**: `logs/replenishment/api.log`
- **Performance**: `logs/replenishment/performance.log`

### Useful Commands

```bash
# Check system status
curl "http://localhost/api/replenishment.php?action=health" | jq

# View recent calculations
mysql -u DB_USER -pDB_PASSWORD DB_NAME -e "SELECT * FROM replenishment_calculations ORDER BY started_at DESC LIMIT 5\G"

# Check top recommendations
mysql -u DB_USER -pDB_PASSWORD DB_NAME -e "SELECT product_name, recommended_quantity FROM v_latest_replenishment_recommendations WHERE recommended_quantity > 0 ORDER BY recommended_quantity DESC LIMIT 10;"

# Monitor system resources
htop
df -h
free -h
```

---

**Version**: 1.0.0  
**Last Updated**: 2025-10-17  
**Compatibility**: PHP 7.4+, MySQL 5.7+
