# Ozon ETL Scripts

This directory contains executable CLI scripts for the Ozon ETL system. Each script provides a command-line interface for running specific ETL components and system maintenance tasks.

## Available Scripts

### 1. sync_products.php

Synchronizes product catalog data from Ozon API to the local database.

**Usage:**

```bash
php sync_products.php [options]
```

**Key Features:**

-   Batch processing with configurable batch size
-   Progress tracking and verbose output
-   Dry-run mode for testing
-   Process locking to prevent concurrent execution
-   Comprehensive error handling and logging

**Example:**

```bash
# Basic synchronization
php sync_products.php

# Verbose mode with custom batch size
php sync_products.php --batch-size=500 --verbose

# Dry run to test without database changes
php sync_products.php --dry-run --max-products=1000
```

### 2. sync_sales.php

Extracts sales history data from Ozon API for the specified date range.

**Usage:**

```bash
php sync_sales.php [options]
```

**Key Features:**

-   Flexible date range configuration
-   Incremental loading to avoid duplicates
-   Support for custom time periods
-   Automatic date validation and formatting

**Examples:**

```bash
# Sync last 30 days (default)
php sync_sales.php

# Sync last 7 days with verbose output
php sync_sales.php --days=7 --verbose

# Sync specific date range
php sync_sales.php --since=2024-01-01 --to=2024-01-31

# Dry run with custom batch size
php sync_sales.php --dry-run --batch-size=500
```

### 3. sync_inventory.php

Generates and downloads warehouse stock reports from Ozon API.

**Usage:**

```bash
php sync_inventory.php [options]
```

**Key Features:**

-   Automated report generation and polling
-   Configurable timeout and polling intervals
-   Support for different report languages
-   Full table refresh for data accuracy
-   Progress tracking for long-running operations

**Examples:**

```bash
# Basic inventory sync
php sync_inventory.php

# Verbose mode with extended timeout
php sync_inventory.php --verbose --timeout=3600

# Dry run with custom polling interval
php sync_inventory.php --dry-run --poll-interval=30

# Russian language report
php sync_inventory.php --language=RU
```

### 4. health_check.php

Comprehensive system health monitoring and diagnostics.

**Usage:**

```bash
php health_check.php [options]
```

**Key Features:**

-   Database connectivity and performance testing
-   Ozon API authentication and response time checks
-   System resource monitoring
-   ETL process status verification
-   Multiple output formats (text, JSON, XML)

**Examples:**

```bash
# Basic health check
php health_check.php

# JSON output for monitoring systems
php health_check.php --format=json --quiet

# Verbose text output
php health_check.php --verbose --timeout=60
```

## Common Options

All scripts support these common options:

-   `--config=FILE` - Path to custom configuration file
-   `--dry-run` - Run without making database changes
-   `--verbose` - Enable detailed output
-   `--help` - Show help message

## Process Locking

All ETL scripts implement process locking to prevent concurrent execution:

-   Lock files are created in the configured locks directory
-   Stale locks are automatically cleaned up
-   Process IDs are validated to ensure locks are active

## Error Handling

Scripts provide comprehensive error handling:

-   Structured logging with timestamps
-   Graceful error recovery where possible
-   Detailed error messages and stack traces
-   Appropriate exit codes for monitoring systems

## Exit Codes

-   `0` - Success
-   `1` - Critical error or failure
-   `2` - Warning conditions (health_check.php only)

## Configuration

Scripts use the main ETL system configuration loaded via `../autoload.php`. Custom configuration files can be specified using the `--config` option.

## Scheduling

These scripts are designed to be run via cron or other scheduling systems:

```bash
# Example crontab entries
0 2 * * * /usr/bin/php /path/to/sync_products.php >/dev/null 2>&1
0 3 * * * /usr/bin/php /path/to/sync_sales.php >/dev/null 2>&1
0 4 * * * /usr/bin/php /path/to/sync_inventory.php >/dev/null 2>&1
*/15 * * * * /usr/bin/php /path/to/health_check.php --format=json --quiet
```

## Monitoring Integration

The health_check.php script is designed for integration with monitoring systems:

-   JSON output format for easy parsing
-   Structured response times and metrics
-   Clear status indicators (ok, warning, critical)
-   Detailed component-level diagnostics

## Troubleshooting

1. **Permission Issues**: Ensure scripts are executable (`chmod +x *.php`)
2. **Configuration Errors**: Verify environment variables and config files
3. **Lock File Issues**: Check locks directory permissions and cleanup stale locks
4. **Memory Issues**: Adjust PHP memory_limit for large datasets
5. **Timeout Issues**: Increase timeout values for slow API responses

For detailed logs, check the configured logging directory or run scripts with `--verbose` flag.
