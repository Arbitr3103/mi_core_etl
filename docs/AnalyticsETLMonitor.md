# Analytics ETL Monitor Documentation

## Overview

The Analytics ETL Monitor is a comprehensive monitoring system for Analytics ETL processes that provides real-time monitoring, SLA tracking, and alerting capabilities.

**Task:** 7.1 Создать AnalyticsETLMonitor  
**Requirements:** 7.1, 7.2, 7.3, 17.5

## Features

### 🔍 Monitoring Capabilities

1. **Analytics API Request Success Monitoring**

    - Success rate tracking across multiple time periods
    - Response time monitoring
    - Failed request analysis
    - Rate limiting compliance

2. **Data Quality Monitoring**

    - Average data quality scores
    - Low quality record detection
    - Stale data identification
    - Warehouse coverage analysis

3. **SLA Metrics and Uptime Tracking**

    - Uptime percentage calculation
    - Error rate monitoring
    - Execution time tracking
    - Records processed analysis

4. **ETL Process Performance Monitoring**

    - Consecutive failure detection
    - Performance trend analysis
    - Execution time degradation alerts

5. **System Health Monitoring**
    - Disk space monitoring
    - Memory usage tracking
    - Database connection health
    - Log file size monitoring

## Architecture

```
┌─────────────────────────────────────────────────────────────┐
│                Analytics ETL Monitor                        │
├─────────────────────────────────────────────────────────────┤
│  ┌─────────────────┐  ┌─────────────────┐  ┌──────────────┐ │
│  │   API Success   │  │  Data Quality   │  │ SLA Metrics  │ │
│  │   Monitoring    │  │   Monitoring    │  │  Tracking    │ │
│  └─────────────────┘  └─────────────────┘  └──────────────┘ │
│  ┌─────────────────┐  ┌─────────────────┐  ┌──────────────┐ │
│  │  Performance    │  │ System Health   │  │   Alerting   │ │
│  │   Monitoring    │  │   Monitoring    │  │    System    │ │
│  └─────────────────┘  └─────────────────┘  └──────────────┘ │
├─────────────────────────────────────────────────────────────┤
│                    Database Layer                           │
│  ┌─────────────────┐  ┌─────────────────┐  ┌──────────────┐ │
│  │ analytics_etl_  │  │   inventory     │  │ warehouse_   │ │
│  │      log        │  │                 │  │normalization │ │
│  └─────────────────┘  └─────────────────┘  └──────────────┘ │
└─────────────────────────────────────────────────────────────┘
```

## SLA Thresholds

The monitoring system uses the following SLA thresholds:

| Metric                   | Threshold | Description                             |
| ------------------------ | --------- | --------------------------------------- |
| API Success Rate         | ≥ 95%     | Minimum acceptable API success rate     |
| Data Quality Score       | ≥ 90%     | Minimum acceptable data quality score   |
| Max Execution Time       | ≤ 1800s   | Maximum ETL execution time (30 minutes) |
| Max Hours Since Last Run | ≤ 4h      | Maximum time between successful runs    |
| Min Records Per Run      | ≥ 50      | Minimum records processed per run       |
| Max Consecutive Failures | ≤ 3       | Maximum consecutive failures allowed    |
| Uptime Target            | ≥ 99.5%   | Target system uptime percentage         |
| Response Time Max        | ≤ 30s     | Maximum API response time               |
| Data Freshness Max       | ≤ 6h      | Maximum acceptable data age             |
| Error Rate Max           | ≤ 5%      | Maximum acceptable error rate           |

## Usage

### 1. Programmatic Usage

```php
<?php
require_once 'src/Services/AnalyticsETLMonitor.php';

// Initialize monitor
$monitor = new AnalyticsETLMonitor([
    'detailed_logging' => true,
    'enable_alerts' => true
]);

// Perform monitoring
$result = $monitor->performMonitoring();

// Access results
echo "Health Score: " . $result['overall_health_score'] . "%\n";
echo "Alerts: " . count($result['alerts']) . "\n";

// Access specific metrics
$metrics = $monitor->getMetrics();
$alerts = $monitor->getAlerts();
```

### 2. Command Line Usage

```bash
# Basic monitoring
php run_analytics_etl_monitoring.php

# Verbose output
php run_analytics_etl_monitoring.php --verbose

# JSON output
php run_analytics_etl_monitoring.php --json

# Show only alerts
php run_analytics_etl_monitoring.php --alerts-only

# Show only health score
php run_analytics_etl_monitoring.php --health-score

# SLA compliance report
php run_analytics_etl_monitoring.php --sla-report
```

### 3. API Usage

```bash
# Get monitoring data via API
curl "http://your-domain/api/warehouse/etl-monitoring"

# Get only alerts
curl "http://your-domain/api/warehouse/etl-monitoring?alerts_only=true"

# Get only health score
curl "http://your-domain/api/warehouse/etl-monitoring?health_score_only=true"

# Get SLA compliance
curl "http://your-domain/api/warehouse/etl-monitoring?sla_only=true"
```

## Automated Monitoring Setup

### Install Cron Jobs

```bash
# Run the setup script
./setup_analytics_etl_monitoring_cron.sh
```

This sets up the following monitoring schedule:

-   **Every 15 minutes (8 AM - 8 PM)**: Alert monitoring
-   **Every hour (8 PM - 8 AM)**: Alert monitoring
-   **Every 4 hours**: Full monitoring report
-   **Daily at 9 AM**: SLA compliance report
-   **Weekly on Monday at 10 AM**: Comprehensive report
-   **Weekly on Sunday at 2 AM**: Log cleanup

### Check Monitoring Status

```bash
# Check current monitoring status
./check_analytics_etl_monitoring_status.sh
```

## Response Format

### Monitoring Response

```json
{
    "status": "healthy|alerts|error",
    "timestamp": "2024-01-15 14:30:00",
    "execution_time_ms": 1250.5,
    "overall_health_score": 95.8,
    "metrics": {
        "api_success_rate_last_24_hours": 98.5,
        "data_quality_avg_score": 92.3,
        "sla_uptime_last_24_hours_percent": 99.8,
        "hours_since_last_successful_run": 2.5,
        "consecutive_failures": 0,
        "disk_usage_percent": 45.2,
        "memory_usage_mb": 128.5,
        "database_response_time_ms": 15.3
    },
    "alerts": [
        {
            "level": "WARNING",
            "message": "Data quality score below optimal",
            "context": {
                "current_score": 85.2,
                "threshold": 90.0
            },
            "timestamp": "2024-01-15 14:29:45",
            "component": "AnalyticsETLMonitor"
        }
    ],
    "sla_compliance": {
        "api_success_rate": {
            "target": 95.0,
            "current": 98.5,
            "compliant": true
        },
        "data_quality": {
            "target": 90.0,
            "current": 92.3,
            "compliant": true
        },
        "uptime": {
            "target": 99.5,
            "current": 99.8,
            "compliant": true
        },
        "overall": {
            "compliant_slas": 4,
            "total_slas": 5,
            "compliance_percent": 80.0
        }
    }
}
```

## Alert Levels

| Level        | Description                                       | Action Required                       |
| ------------ | ------------------------------------------------- | ------------------------------------- |
| **CRITICAL** | System failure or SLA breach                      | Immediate action required             |
| **ERROR**    | Significant issues affecting functionality        | Action required within 1 hour         |
| **WARNING**  | Performance degradation or approaching thresholds | Monitor closely, action may be needed |
| **INFO**     | Informational messages                            | No action required                    |

## Health Score Calculation

The overall health score is calculated using weighted metrics:

-   **API Success Rate** (25%): Based on 24-hour success rate
-   **Data Quality Score** (25%): Average data quality score
-   **Uptime Score** (30%): System uptime percentage
-   **Performance Score** (20%): Based on execution time efficiency

### Health Score Ranges

-   **95-100%**: Excellent (Green)
-   **85-94%**: Good (Yellow)
-   **70-84%**: Poor (Red)
-   **Below 70%**: Critical (Bright Red)

## Monitoring Periods

The system tracks metrics across multiple time periods:

-   **Last Hour**: Real-time monitoring
-   **Last 24 Hours**: Daily performance tracking
-   **Last 7 Days**: Weekly trend analysis
-   **Last 30 Days**: Monthly performance review

## Configuration

### Monitor Configuration

```php
$config = [
    'log_file' => '/path/to/monitor.log',
    'enable_alerts' => true,
    'alert_cooldown_minutes' => 60,
    'detailed_logging' => true,
    'performance_tracking' => true,
    'sla_reporting' => true
];

$monitor = new AnalyticsETLMonitor($config);
```

### Environment Variables

```bash
# Database connection (inherited from main config)
DB_HOST=localhost
DB_NAME=warehouse_db
DB_USER=warehouse_user
DB_PASS=warehouse_pass

# Optional: Custom thresholds
MONITOR_API_SUCCESS_RATE_MIN=95
MONITOR_DATA_QUALITY_SCORE_MIN=90
MONITOR_MAX_EXECUTION_TIME=1800
```

## Troubleshooting

### Common Issues

1. **Database Connection Errors**

    ```bash
    # Check database connectivity
    php -r "
    require_once 'config/database.php';
    try {
        \$pdo = getDatabaseConnection();
        echo 'Database connection: OK\n';
    } catch (Exception \$e) {
        echo 'Database error: ' . \$e->getMessage() . '\n';
    }
    "
    ```

2. **Missing Log Directory**

    ```bash
    # Create log directories
    mkdir -p logs/analytics_etl
    mkdir -p logs/cron
    chmod 755 logs/analytics_etl logs/cron
    ```

3. **Cron Jobs Not Running**

    ```bash
    # Check cron service
    sudo service cron status

    # Check cron logs
    tail -f /var/log/cron.log

    # Verify cron jobs
    crontab -l | grep analytics_etl_monitoring
    ```

4. **High Memory Usage**

    ```bash
    # Check PHP memory limit
    php -r "echo 'Memory limit: ' . ini_get('memory_limit') . '\n';"

    # Monitor memory usage during execution
    php run_analytics_etl_monitoring.php --verbose
    ```

### Debug Mode

Enable detailed logging for troubleshooting:

```bash
# Run with verbose output
php run_analytics_etl_monitoring.php --verbose

# Check log files
tail -f logs/analytics_etl/monitor.log
tail -f logs/cron/analytics_etl_monitoring_cron.log
```

## Integration

### With Existing Systems

The monitor integrates with:

-   **Analytics ETL System**: Monitors ETL process health
-   **Database**: Reads from `analytics_etl_log` table
-   **API Controller**: Provides REST endpoints
-   **Cron System**: Automated monitoring execution

### Custom Alerting

Extend the monitor for custom alerting:

```php
class CustomAnalyticsETLMonitor extends AnalyticsETLMonitor {
    protected function addAlert(string $level, string $message, array $context = []): void {
        parent::addAlert($level, $message, $context);

        // Custom alerting logic
        if ($level === 'CRITICAL') {
            $this->sendSlackAlert($message, $context);
            $this->sendEmailAlert($message, $context);
        }
    }

    private function sendSlackAlert(string $message, array $context): void {
        // Implement Slack notification
    }

    private function sendEmailAlert(string $message, array $context): void {
        // Implement email notification
    }
}
```

## Performance Considerations

-   **Database Queries**: Optimized with proper indexes
-   **Memory Usage**: Efficient data processing with streaming
-   **Execution Time**: Typical monitoring cycle: 1-3 seconds
-   **Log File Management**: Automatic cleanup of old logs
-   **Caching**: Metrics cached for performance

## Security

-   **Database Access**: Uses existing secure database connection
-   **Log Files**: Proper file permissions (644 for logs, 755 for directories)
-   **API Endpoints**: Inherits authentication from main API system
-   **Sensitive Data**: No sensitive data logged or exposed

## Maintenance

### Regular Tasks

1. **Weekly**: Review monitoring reports
2. **Monthly**: Analyze performance trends
3. **Quarterly**: Review and adjust SLA thresholds
4. **Annually**: Update monitoring configuration

### Log Management

-   **Automatic Cleanup**: Old logs cleaned weekly
-   **Log Rotation**: Implemented via cron jobs
-   **Archive Policy**: Logs kept for 30 days, reports for 90 days

## Related Documentation

-   [Analytics ETL System](./AnalyticsETL.md)
-   [Analytics API Client](./AnalyticsApiClient.md)
-   [Database Schema](./database_schema.md)
-   [API Documentation](./api_documentation.md)
