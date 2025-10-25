# Analytics ETL Infrastructure

## Overview

This document describes the infrastructure setup for the Analytics API ETL system.

## Directory Structure

```
├── logs/analytics_etl/          # ETL process logs
│   ├── etl_process.log         # Main ETL execution logs
│   ├── api_requests.log        # Analytics API request/response logs
│   ├── data_quality.log        # Data validation and quality logs
│   └── errors.log              # Error and exception logs
│
├── cache/analytics_api/         # API response cache
│   ├── api_responses/          # Cached API responses (TTL: 30min)
│   ├── warehouse_mappings/     # Warehouse normalization cache (TTL: 24h)
│   └── validation_rules/       # Validation rules cache (TTL: 1h)
│
├── config/
│   ├── analytics_etl_logging.php    # Logging configuration
│   └── analytics_api_cache.php      # Cache configuration
│
└── scripts/
    └── cleanup_analytics_cache.php  # Cache cleanup utility
```

## Configuration

### Logging Configuration

Located in `config/analytics_etl_logging.php`:

-   **Log Level**: INFO (configurable)
-   **Max File Size**: 100MB
-   **Retention**: 30 days
-   **Rotation**: Daily with compression

### Cache Configuration

Located in `config/analytics_api_cache.php`:

-   **API Responses**: 30 minutes TTL, 50MB max
-   **Warehouse Mappings**: 24 hours TTL, 10MB max
-   **Validation Rules**: 1 hour TTL, 5MB max
-   **Total Cache Limit**: 100MB

## Maintenance

### Cache Cleanup

Run the cache cleanup script:

```bash
# Dry run to see what would be cleaned
php scripts/cleanup_analytics_cache.php --dry-run

# Clean expired cache files
php scripts/cleanup_analytics_cache.php

# Force clean all cache files
php scripts/cleanup_analytics_cache.php --force
```

### Log Rotation

Logs are automatically rotated daily. Manual cleanup:

```bash
# Remove logs older than 30 days
find logs/analytics_etl/ -name "*.log*" -mtime +30 -delete

# Compress old logs
gzip logs/analytics_etl/*.log.1
```

## Monitoring

### Disk Usage

Monitor cache and log directory sizes:

```bash
# Check cache size
du -sh cache/analytics_api/

# Check log size
du -sh logs/analytics_etl/

# Check individual cache types
du -sh cache/analytics_api/*/
```

### Log Analysis

Common log analysis commands:

```bash
# Check recent ETL runs
tail -f logs/analytics_etl/etl_process.log

# Count API requests today
grep "$(date +%Y-%m-%d)" logs/analytics_etl/api_requests.log | wc -l

# Check for errors
grep "ERROR\|CRITICAL" logs/analytics_etl/errors.log
```

## Security

### File Permissions

-   Log directories: 755 (rwxr-xr-x)
-   Cache directories: 755 (rwxr-xr-x)
-   Log files: 644 (rw-r--r--)
-   Cache files: 644 (rw-r--r--)

### Access Control

-   Only ETL processes should write to these directories
-   Web server should not have access to logs directory
-   Cache directory can be read by web processes if needed

## Troubleshooting

### Common Issues

1. **Permission Denied**

    ```bash
    chmod 755 logs/analytics_etl cache/analytics_api
    ```

2. **Disk Space Full**

    ```bash
    php scripts/cleanup_analytics_cache.php --force
    find logs/analytics_etl/ -name "*.log*" -mtime +7 -delete
    ```

3. **Cache Not Working**
    - Check cache directory permissions
    - Verify cache configuration in `config/analytics_api_cache.php`
    - Check disk space availability

### Health Check

Quick infrastructure health check:

```bash
# Check if directories exist and are writable
test -w logs/analytics_etl && echo "Logs directory OK" || echo "Logs directory FAIL"
test -w cache/analytics_api && echo "Cache directory OK" || echo "Cache directory FAIL"

# Check configuration files
test -f config/analytics_etl_logging.php && echo "Logging config OK" || echo "Logging config MISSING"
test -f config/analytics_api_cache.php && echo "Cache config OK" || echo "Cache config MISSING"
```
