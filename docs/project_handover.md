# MI Core ETL - Project Handover Documentation

**Date**: October 22, 2025  
**Version**: 1.0.0  
**Status**: Production Ready

## Executive Summary

The MI Core ETL system has been successfully refactored and modernized. This document provides all necessary information for project handover, including system overview, operational procedures, and support guidelines.

## Table of Contents

1. [System Overview](#system-overview)
2. [Architecture](#architecture)
3. [Access and Credentials](#access-and-credentials)
4. [Daily Operations](#daily-operations)
5. [Monitoring and Alerts](#monitoring-and-alerts)
6. [Maintenance Procedures](#maintenance-procedures)
7. [Troubleshooting](#troubleshooting)
8. [Support Contacts](#support-contacts)
9. [Training Materials](#training-materials)

## System Overview

### What is MI Core ETL?

MI Core ETL is an inventory management and analytics system that:

-   Extracts data from marketplace APIs (Ozon, Wildberries)
-   Transforms and loads data into PostgreSQL database
-   Provides real-time dashboard for inventory monitoring
-   Generates analytics and recommendations

### Key Features

✅ **Modern React Dashboard**

-   Real-time inventory monitoring
-   Three-category view (Critical, Low Stock, Overstock)
-   Toggle between Top-10 and Show All modes
-   Mobile-responsive design

✅ **Optimized PostgreSQL Database**

-   High-performance queries (< 100ms)
-   Automated backups
-   Materialized views for fast dashboard loading

✅ **RESTful API**

-   Unified endpoints
-   5-minute caching
-   Rate limiting
-   Comprehensive error handling

✅ **Automated Operations**

-   Scheduled ETL processes
-   Automated backups
-   Health monitoring
-   Log rotation

## Architecture

### Technology Stack

```
┌─────────────────────────────────────────────────────────┐
│                    React Frontend                        │
│         (TypeScript, Vite, TanStack Query)              │
└─────────────────────────────────────────────────────────┘
                          ↓
┌─────────────────────────────────────────────────────────┐
│                      Nginx                               │
│            (Static files + API proxy)                    │
└─────────────────────────────────────────────────────────┘
                          ↓
┌─────────────────────────────────────────────────────────┐
│                   PHP Backend API                        │
│              (PHP 8.1+, RESTful API)                    │
└─────────────────────────────────────────────────────────┘
                          ↓
┌─────────────────────────────────────────────────────────┐
│                  PostgreSQL 15+                          │
│         (Optimized indexes, materialized views)         │
└─────────────────────────────────────────────────────────┘
```

### Server Information

-   **Server Name**: Elysia
-   **IP Address**: 178.72.129.61
-   **Operating System**: Linux (Ubuntu/Debian)
-   **Web Server**: Nginx 1.18+
-   **Application Server**: PHP-FPM 8.1+
-   **Database**: PostgreSQL 15+

### Directory Structure

```
/var/www/mi_core_etl/
├── src/                    # Source code
│   ├── api/               # API controllers and routes
│   ├── etl/               # ETL scripts
│   ├── models/            # Data models
│   ├── services/          # Business logic
│   └── utils/             # Utilities
├── frontend/              # React application
│   ├── src/              # React source code
│   └── dist/             # Production build
├── public/               # Public web root
│   ├── api/             # API entry point
│   └── build/           # Frontend build
├── config/              # Configuration files
├── storage/             # Runtime data
│   ├── logs/           # Application logs
│   ├── cache/          # Cache files
│   └── backups/        # Database backups
├── deployment/         # Deployment scripts
├── scripts/           # Utility scripts
├── tests/            # Test files
└── docs/            # Documentation
```

## Access and Credentials

### Web Access

-   **Frontend URL**: http://178.72.129.61/
-   **API Base URL**: http://178.72.129.61/api/
-   **Health Check**: http://178.72.129.61/api/health

### Server Access

```bash
# SSH access
ssh root@178.72.129.61

# Project directory
cd /var/www/mi_core_etl
```

### Database Access

```bash
# PostgreSQL command line
psql -h localhost -U mi_core_user -d mi_core_db

# Connection details (from .env file)
PG_HOST=localhost
PG_PORT=5432
PG_NAME=mi_core_db
PG_USER=mi_core_user
PG_PASSWORD=[stored in .env]
```

### Environment Variables

All sensitive credentials are stored in `/var/www/mi_core_etl/.env`:

```bash
# View environment configuration
cat /var/www/mi_core_etl/.env

# Edit environment variables
nano /var/www/mi_core_etl/.env
```

**Important**: Never commit `.env` file to version control!

## Daily Operations

### Starting Your Day

1. **Check System Health**

    ```bash
    cd /var/www/mi_core_etl
    bash scripts/view_monitoring.sh
    ```

2. **Review Logs**

    ```bash
    # Check error logs
    tail -f storage/logs/error_$(date +%Y-%m-%d).log

    # Check application logs
    tail -f storage/logs/info_$(date +%Y-%m-%d).log
    ```

3. **Verify Services**
    ```bash
    systemctl status nginx
    systemctl status php8.1-fpm
    systemctl status postgresql
    ```

### Regular Tasks

#### Daily

-   Monitor system health dashboard
-   Check error logs for issues
-   Verify backup completion

#### Weekly

-   Review performance metrics
-   Check disk space usage
-   Update dependencies if needed
-   Run database maintenance

#### Monthly

-   Apply security updates
-   Test backup restoration
-   Review and optimize database
-   Update documentation

## Monitoring and Alerts

### Health Monitoring

The system includes automated monitoring that runs every 5 minutes:

```bash
# View current system health
bash scripts/view_monitoring.sh

# Run manual health check
bash scripts/monitor_system.sh
```

### Monitoring Reports

Reports are saved to `storage/monitoring/`:

-   `postgresql_health_*.json` - Database metrics
-   `app_health_*.json` - Application health

### Key Metrics to Watch

| Metric              | Healthy  | Warning   | Critical |
| ------------------- | -------- | --------- | -------- |
| API Response Time   | < 500ms  | < 2s      | > 2s     |
| Database Query Time | < 100ms  | < 500ms   | > 500ms  |
| Disk Usage          | < 80%    | 80-90%    | > 90%    |
| Error Rate          | < 10/day | 10-50/day | > 50/day |
| Cache Hit Rate      | > 80%    | 60-80%    | < 60%    |

### Alert Locations

-   **System Alerts**: `storage/logs/monitoring/alerts.log`
-   **Error Logs**: `storage/logs/error_*.log`
-   **Application Logs**: `storage/logs/info_*.log`

## Maintenance Procedures

### Database Maintenance

#### Daily Backup

Automated backups run daily via cron:

```bash
# Manual backup
bash scripts/postgresql_backup.sh

# View backup history
ls -lh storage/backups/postgresql/
```

#### Weekly Maintenance

```bash
# Run VACUUM ANALYZE
psql -h localhost -U mi_core_user -d mi_core_db -c "VACUUM ANALYZE;"

# Refresh materialized views
psql -h localhost -U mi_core_user -d mi_core_db -c "REFRESH MATERIALIZED VIEW CONCURRENTLY mv_dashboard_inventory;"

# Check database size
psql -h localhost -U mi_core_user -d mi_core_db -c "SELECT pg_size_pretty(pg_database_size(current_database()));"
```

#### Backup Restoration

```bash
# Restore from backup
bash scripts/postgresql_restore.sh /path/to/backup.sql
```

### Application Maintenance

#### Clear Cache

```bash
# Clear application cache
rm -rf storage/cache/*

# Clear OPcache
sudo systemctl reload php8.1-fpm
```

#### Update Dependencies

```bash
# Update PHP dependencies
composer update

# Update frontend dependencies
cd frontend
npm update
npm run build
```

#### Restart Services

```bash
# Restart all services
sudo systemctl restart nginx
sudo systemctl restart php8.1-fpm

# Reload without downtime
sudo systemctl reload nginx
```

### Log Management

```bash
# View log sizes
du -sh storage/logs/*

# Rotate logs manually
find storage/logs -name "*.log" -mtime +30 -delete

# View recent errors
tail -100 storage/logs/error_$(date +%Y-%m-%d).log
```

## Troubleshooting

### Common Issues

#### 1. Dashboard Not Loading

**Symptoms**: Frontend shows blank page or errors

**Solutions**:

```bash
# Check if frontend build exists
ls -la public/build/

# Rebuild frontend
cd frontend
npm run build
cp -r dist/* ../public/build/

# Check Nginx configuration
sudo nginx -t
sudo systemctl reload nginx
```

#### 2. API Errors

**Symptoms**: API returns 500 errors

**Solutions**:

```bash
# Check PHP-FPM logs
tail -f /var/log/php8.1-fpm.log

# Check application logs
tail -f storage/logs/error_$(date +%Y-%m-%d).log

# Restart PHP-FPM
sudo systemctl restart php8.1-fpm
```

#### 3. Database Connection Issues

**Symptoms**: "PostgreSQL connection failed" errors

**Solutions**:

```bash
# Check PostgreSQL status
sudo systemctl status postgresql

# Test connection
psql -h localhost -U mi_core_user -d mi_core_db -c "SELECT 1;"

# Check connection settings
php config/database_postgresql.php

# Restart PostgreSQL
sudo systemctl restart postgresql
```

#### 4. Slow Performance

**Symptoms**: Dashboard loads slowly, API timeouts

**Solutions**:

```bash
# Check database performance
bash scripts/analyze_query_performance.sh

# Clear cache
rm -rf storage/cache/*

# Check disk space
df -h

# Analyze slow queries
psql -h localhost -U mi_core_user -d mi_core_db -c "
SELECT query, calls, total_time, mean_time
FROM pg_stat_statements
ORDER BY mean_time DESC
LIMIT 10;"
```

#### 5. High Disk Usage

**Symptoms**: Disk usage > 90%

**Solutions**:

```bash
# Find large files
du -sh storage/* | sort -h

# Clean old logs
find storage/logs -name "*.log" -mtime +30 -delete

# Clean old backups
find storage/backups -name "*.sql" -mtime +30 -delete

# Clean cache
rm -rf storage/cache/*
```

### Emergency Procedures

#### System Down

1. Check service status:

    ```bash
    systemctl status nginx postgresql php8.1-fpm
    ```

2. Restart services:

    ```bash
    sudo systemctl restart nginx postgresql php8.1-fpm
    ```

3. Check logs for errors:
    ```bash
    journalctl -xe
    tail -100 storage/logs/error_$(date +%Y-%m-%d).log
    ```

#### Database Corruption

1. Stop application:

    ```bash
    sudo systemctl stop nginx php8.1-fpm
    ```

2. Restore from backup:

    ```bash
    bash scripts/postgresql_restore.sh storage/backups/postgresql/latest.sql
    ```

3. Restart services:
    ```bash
    sudo systemctl start nginx php8.1-fpm
    ```

#### Rollback Deployment

```bash
# Use rollback script
bash deployment/scripts/rollback.sh

# Or manual rollback
cd /var/www/mi_core_etl
tar -xzf storage/backups/code_backup_TIMESTAMP.tar.gz
sudo systemctl restart nginx php8.1-fpm
```

## Support Contacts

### Technical Support

-   **Development Team**: MI Core Development Team
-   **Email**: [support email]
-   **Emergency Contact**: [phone number]

### Escalation Path

1. **Level 1**: Check documentation and logs
2. **Level 2**: Contact development team
3. **Level 3**: System administrator
4. **Level 4**: Database administrator

### Documentation Resources

-   **Main README**: `/var/www/mi_core_etl/README.md`
-   **API Documentation**: `/var/www/mi_core_etl/docs/api.md`
-   **Deployment Guide**: `/var/www/mi_core_etl/docs/deployment.md`
-   **Architecture**: `/var/www/mi_core_etl/docs/architecture.md`
-   **Testing Guide**: `/var/www/mi_core_etl/docs/testing_guide.md`

## Training Materials

### For End Users

#### Accessing the Dashboard

1. Open browser and navigate to: http://178.72.129.61/
2. Dashboard loads automatically
3. Use toggle to switch between "Top-10" and "Show All" views

#### Understanding the Dashboard

**Three Categories**:

-   🚨 **Critical Stock**: Items with ≤ 5 units (urgent reorder needed)
-   ⚠️ **Low Stock**: Items with 6-20 units (reorder soon)
-   📈 **Overstock**: Items with > 100 units (reduce orders)

**View Modes**:

-   **Top-10**: Shows top 10 items per category (quick overview)
-   **Show All**: Shows all items with scrolling (detailed analysis)

#### Best Practices

-   Check dashboard daily for critical items
-   Use "Show All" mode for comprehensive analysis
-   Monitor trends over time
-   Act on critical stock alerts immediately

### For Administrators

#### System Administration

1. **Server Access**:

    ```bash
    ssh root@178.72.129.61
    cd /var/www/mi_core_etl
    ```

2. **Check System Health**:

    ```bash
    bash scripts/view_monitoring.sh
    ```

3. **Review Logs**:

    ```bash
    tail -f storage/logs/error_$(date +%Y-%m-%d).log
    ```

4. **Database Management**:
    ```bash
    psql -h localhost -U mi_core_user -d mi_core_db
    ```

#### Deployment Process

1. **Backup**:

    ```bash
    bash deployment/scripts/backup.sh
    ```

2. **Deploy**:

    ```bash
    bash deployment/scripts/production_deploy.sh
    ```

3. **Verify**:

    ```bash
    bash tests/smoke_tests.sh
    ```

4. **Rollback** (if needed):
    ```bash
    bash deployment/scripts/rollback.sh
    ```

### For Developers

#### Development Setup

1. Clone repository
2. Copy `.env.example` to `.env`
3. Install dependencies:
    ```bash
    composer install
    cd frontend && npm install
    ```
4. Run development server:
    ```bash
    cd frontend && npm run dev
    ```

#### Code Structure

-   **Backend**: PHP 8.1+ with OOP patterns
-   **Frontend**: React 18 + TypeScript
-   **Database**: PostgreSQL 15+
-   **Build Tool**: Vite

#### Testing

```bash
# Backend tests
php run_backend_tests.php

# Frontend tests
cd frontend && npm test

# Smoke tests
bash tests/smoke_tests.sh
```

## Handover Checklist

### Pre-Handover

-   [x] System deployed to production
-   [x] All tests passing
-   [x] Documentation completed
-   [x] Monitoring configured
-   [x] Backups automated
-   [x] Training materials prepared

### During Handover

-   [ ] System demonstration completed
-   [ ] Access credentials provided
-   [ ] Documentation reviewed
-   [ ] Training conducted
-   [ ] Questions answered
-   [ ] Support contacts shared

### Post-Handover

-   [ ] Monitor system for 1 week
-   [ ] Address any issues
-   [ ] Provide additional training if needed
-   [ ] Document lessons learned
-   [ ] Transition to maintenance mode

## Appendix

### Useful Commands

```bash
# System health
bash scripts/view_monitoring.sh

# Run smoke tests
bash tests/smoke_tests.sh

# Backup database
bash scripts/postgresql_backup.sh

# Deploy updates
bash deployment/scripts/production_deploy.sh

# View logs
tail -f storage/logs/error_$(date +%Y-%m-%d).log

# Database console
psql -h localhost -U mi_core_user -d mi_core_db

# Restart services
sudo systemctl restart nginx php8.1-fpm
```

### Configuration Files

-   `.env` - Environment variables
-   `config/database_postgresql.php` - Database configuration
-   `config/api.php` - API configuration
-   `deployment/configs/nginx.conf` - Nginx configuration

### Important Paths

-   Project root: `/var/www/mi_core_etl`
-   Logs: `/var/www/mi_core_etl/storage/logs`
-   Backups: `/var/www/mi_core_etl/storage/backups`
-   Frontend: `/var/www/mi_core_etl/public/build`

---

**Document Version**: 1.0.0  
**Last Updated**: October 22, 2025  
**Next Review**: November 22, 2025

**Prepared by**: MI Core Development Team  
**Approved by**: [Approver Name]  
**Status**: ✅ Ready for Handover
