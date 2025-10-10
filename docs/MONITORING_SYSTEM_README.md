# MDM Data Quality Monitoring System

## Overview

The MDM Data Quality Monitoring System provides real-time monitoring, alerting, and reporting for the Master Data Management system. It tracks sync status, data quality metrics, errors, and performance, triggering alerts when thresholds are exceeded.

## Features

- ✅ **Real-time Quality Metrics**: Track sync status, data quality, and errors
- ✅ **Automated Alerts**: Email, Slack, log, and webhook notifications
- ✅ **Quality Dashboard**: Visual dashboard for monitoring system health
- ✅ **Configurable Thresholds**: Customize alert thresholds for your needs
- ✅ **Historical Tracking**: Store and analyze alert history
- ✅ **API Endpoints**: RESTful API for integration with other systems
- ✅ **Cron Integration**: Automated monitoring via scheduled tasks

## Quick Start

### 1. Setup Monitoring

```bash
# Make scripts executable
chmod +x monitor_data_quality.php
chmod +x setup_monitoring_cron.sh

# Run setup script
./setup_monitoring_cron.sh
```

### 2. Configure Alerts

Edit `config.php` and add:

```php
// Email alerts
define('ALERT_EMAIL', 'admin@example.com');

// Slack alerts (optional)
define('SLACK_WEBHOOK_URL', 'https://hooks.slack.com/services/YOUR/WEBHOOK/URL');
```

### 3. Test Monitoring

```bash
# Run manual check
php monitor_data_quality.php --verbose

# View dashboard
open http://localhost/html/quality_dashboard.php
```

## Components

### 1. DataQualityMonitor Class

Main monitoring class that collects metrics and triggers alerts.

**Location**: `src/DataQualityMonitor.php`

**Usage**:

```php
require_once 'src/DataQualityMonitor.php';

$monitor = new DataQualityMonitor($pdo);

// Get metrics
$metrics = $monitor->getQualityMetrics();

// Run checks
$result = $monitor->runQualityChecks();
```

### 2. Alert Handlers

Different notification channels for alerts.

**Location**: `src/AlertHandlers.php`

**Available Handlers**:

- `EmailAlertHandler` - Send email notifications
- `SlackAlertHandler` - Send Slack messages
- `LogAlertHandler` - Write to log files
- `ConsoleAlertHandler` - Print to console
- `WebhookAlertHandler` - POST to custom webhook
- `CompositeAlertHandler` - Combine multiple handlers

**Usage**:

```php
require_once 'src/AlertHandlers.php';

$monitor = new DataQualityMonitor($pdo);

// Add email alerts
$monitor->addAlertHandler(new EmailAlertHandler('admin@example.com'));

// Add Slack alerts
$monitor->addAlertHandler(new SlackAlertHandler('https://hooks.slack.com/...'));

// Add log alerts
$monitor->addAlertHandler(new LogAlertHandler('logs/alerts.log'));
```

### 3. Monitoring Script

Command-line script for running quality checks.

**Location**: `monitor_data_quality.php`

**Usage**:

```bash
# Basic check
php monitor_data_quality.php

# Verbose output
php monitor_data_quality.php --verbose

# Report only (no alerts)
php monitor_data_quality.php --report-only

# Help
php monitor_data_quality.php --help
```

### 4. Quality Dashboard

Web-based dashboard for visualizing metrics.

**Location**: `html/quality_dashboard.php`

**Features**:

- Overall quality score
- Sync status metrics
- Data quality metrics
- Error metrics
- Recent alerts
- Performance metrics
- Auto-refresh every 60 seconds

**Access**: `http://your-domain/html/quality_dashboard.php`

### 5. API Endpoints

RESTful API for accessing metrics programmatically.

**Location**: `api/quality-metrics.php`

**Endpoints**:

```bash
# Get all metrics
GET /api/quality-metrics.php?action=metrics

# Run quality checks
GET /api/quality-metrics.php?action=check

# Get recent alerts
GET /api/quality-metrics.php?action=alerts&limit=10

# Get alert statistics
GET /api/quality-metrics.php?action=alert-stats

# Get sync status
GET /api/quality-metrics.php?action=sync-status

# Health check
GET /api/quality-metrics.php?action=health
```

## Alert Thresholds

Default thresholds (can be customized):

| Metric                  | Threshold  | Alert Level |
| ----------------------- | ---------- | ----------- |
| Failed sync percentage  | > 5%       | Critical    |
| Pending sync percentage | > 10%      | Warning     |
| Real names percentage   | < 80%      | Warning     |
| Sync age                | > 48 hours | Critical    |
| Hourly error count      | > 10       | Critical    |

**Customize Thresholds**:

```php
$monitor->setThresholds([
    'failed_percentage' => 3,      // More strict
    'pending_percentage' => 15,    // More lenient
    'real_names_percentage' => 90, // Higher quality requirement
    'sync_age_hours' => 24,        // More frequent sync required
    'error_count_hourly' => 5      // Lower error tolerance
]);
```

## Cron Schedule

Recommended cron schedule:

```bash
# Hourly quality checks
0 * * * * cd /path/to/project && php monitor_data_quality.php

# Detailed check every 6 hours
0 */6 * * * cd /path/to/project && php monitor_data_quality.php --verbose

# Daily report at 8 AM
0 8 * * * cd /path/to/project && php monitor_data_quality.php --verbose --report-only

# Weekly log cleanup
0 3 * * 0 find /path/to/project/logs -name "*.log" -mtime +30 -delete
```

## Metrics Explained

### Sync Status Metrics

- **Total**: Total number of products in system
- **Synced**: Products successfully synced with API
- **Pending**: Products waiting to be synced
- **Failed**: Products that failed to sync

### Data Quality Metrics

- **Real Names Percentage**: Products with actual names vs placeholders
- **Brands Percentage**: Products with brand information
- **Average Cache Age**: How old cached data is (in days)

### Error Metrics

- **Total Errors (24h)**: Errors in last 24 hours
- **Total Errors (7d)**: Errors in last 7 days
- **Affected Products**: Number of unique products with errors
- **Error Types**: Distribution of error types

### Performance Metrics

- **Products Synced Today**: Number of products synced today
- **Last Sync Time**: Timestamp of last successful sync

### Overall Quality Score

Calculated score (0-100) based on:

- Synced percentage (40% weight)
- Real names percentage (40% weight)
- Brands percentage (20% weight)
- Failed percentage (penalty)

**Score Ranges**:

- 90-100: Excellent
- 70-89: Fair
- 0-69: Poor

## Alert Types

### high_failure_rate

**Trigger**: Failed sync percentage exceeds threshold  
**Level**: Critical  
**Action**: Check sync errors, verify API connectivity

### high_pending_rate

**Trigger**: Pending sync percentage exceeds threshold  
**Level**: Warning  
**Action**: Run sync script, check cron jobs

### low_real_names

**Trigger**: Real names percentage below threshold  
**Level**: Warning  
**Action**: Run product name sync, check API credentials

### stale_sync

**Trigger**: No successful sync within time threshold  
**Level**: Critical  
**Action**: Check cron job status, run manual sync

### high_error_rate

**Trigger**: Error count exceeds hourly threshold  
**Level**: Critical  
**Action**: Check error logs, verify API status

## Troubleshooting

### No Alerts Being Sent

1. Check alert handlers are configured:

```php
$monitor->addAlertHandler(new EmailAlertHandler('admin@example.com'));
```

2. Verify email/Slack configuration in `config.php`

3. Check logs: `logs/quality_alerts.log`

### Dashboard Not Loading

1. Check API endpoint: `curl http://localhost/api/quality-metrics.php?action=metrics`

2. Verify database connection in `config.php`

3. Check browser console for JavaScript errors

### Cron Jobs Not Running

1. Verify cron jobs are installed: `crontab -l`

2. Check cron logs: `logs/monitoring_cron.log`

3. Verify script permissions: `ls -la monitor_data_quality.php`

### High Alert Volume

1. Adjust thresholds to be more lenient

2. Fix underlying issues causing alerts

3. Use `--report-only` mode temporarily

## Testing

Run the test suite:

```bash
php tests/test_data_quality_monitoring.php
```

Tests cover:

- Monitor initialization
- Quality metrics collection
- Alert threshold configuration
- Alert handlers
- Quality checks execution
- Alert logging
- API endpoints

## Integration Examples

### Custom Alert Handler

```php
class CustomAlertHandler {
    public function __invoke($alert) {
        // Your custom logic here
        // e.g., send to monitoring service, update database, etc.
    }
}

$monitor->addAlertHandler(new CustomAlertHandler());
```

### Webhook Integration

```php
$monitor->addAlertHandler(new WebhookAlertHandler(
    'https://your-service.com/webhook',
    ['Authorization: Bearer YOUR_TOKEN']
));
```

### Multiple Email Recipients

```php
$monitor->addAlertHandler(new EmailAlertHandler([
    'admin@example.com',
    'team@example.com',
    'alerts@example.com'
]));
```

## Best Practices

1. **Start with default thresholds** and adjust based on your system
2. **Monitor regularly** - set up cron jobs for automated checks
3. **Review alerts** - don't ignore warnings, they indicate issues
4. **Keep logs** - retain logs for at least 30 days for analysis
5. **Test alerts** - verify alert handlers work before relying on them
6. **Document incidents** - track issues and resolutions
7. **Update thresholds** - adjust as your system evolves

## Requirements

- PHP 7.4+
- MySQL 5.7+
- PDO extension
- cURL extension (for Slack/webhook alerts)
- Mail function configured (for email alerts)

## Files

- `src/DataQualityMonitor.php` - Main monitoring class
- `src/AlertHandlers.php` - Alert notification handlers
- `monitor_data_quality.php` - CLI monitoring script
- `html/quality_dashboard.php` - Web dashboard
- `api/quality-metrics.php` - API endpoints
- `setup_monitoring_cron.sh` - Cron setup script
- `tests/test_data_quality_monitoring.php` - Test suite

## Support

For issues or questions:

1. Check troubleshooting section above
2. Review logs in `logs/` directory
3. Run tests to verify system health
4. Consult other documentation:
   - `MDM_TROUBLESHOOTING_GUIDE.md`
   - `MDM_MONITORING_GUIDE.md`
   - `MDM_CLASSES_USAGE_GUIDE.md`

## References

- Requirements: 8.3, 4.3
- Design Document: `.kiro/specs/mdm-product-system/design.md`
- Requirements Document: `.kiro/specs/mdm-product-system/requirements.md`
