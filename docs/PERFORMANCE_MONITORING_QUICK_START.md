# Performance Monitoring Quick Start

## 5-Minute Setup

### 1. Install Monitoring System

```bash
# Make setup script executable
chmod +x scripts/setup_performance_monitoring_cron.sh

# Run setup (requires sudo for cron installation)
sudo scripts/setup_performance_monitoring_cron.sh
```

### 2. Verify Installation

```bash
# Check cron jobs are installed
crontab -l | grep performance

# Run manual test
php scripts/monitor_system_performance.php
```

### 3. Access Performance Dashboard

Open in your browser:

```
https://your-domain.com/performance-dashboard.html
```

## Quick Commands

### View Current System Status

```bash
php scripts/monitor_system_performance.php
```

### Check for Alerts

```bash
php scripts/check_performance_alerts.php
```

### View Recent Performance Logs

```bash
tail -n 50 logs/performance_tracker.log | jq .
```

### View System Performance Logs

```bash
tail -n 20 logs/system_performance.log | jq .
```

### View Performance Alerts

```bash
tail -n 20 logs/performance_alerts.log | jq .
```

## API Quick Reference

### Get Current Health Status

```bash
curl "https://your-domain.com/api/performance-metrics.php?action=health_check" | jq .
```

### Get Performance Summary (Last 24 Hours)

```bash
curl "https://your-domain.com/api/performance-metrics.php?action=summary&hours=24" | jq .
```

### Get Slow Requests

```bash
curl "https://your-domain.com/api/performance-metrics.php?action=slow_requests&hours=1&limit=10" | jq .
```

### Get Database Performance

```bash
curl "https://your-domain.com/api/performance-metrics.php?action=database_performance&hours=24" | jq .
```

## Understanding the Dashboard

### Health Status Colors

-   🟢 **Green (Healthy)**: All systems normal
-   🟡 **Yellow (Degraded)**: Some metrics elevated
-   🔴 **Red (Critical)**: Immediate attention needed

### Key Metrics

**Response Time**

-   Good: < 500ms
-   Acceptable: 500-2000ms
-   Slow: > 2000ms

**Memory Usage**

-   Normal: < 75%
-   Warning: 75-90%
-   Critical: > 90%

**CPU Load**

-   Normal: < 0.7 per core
-   Warning: 0.7-0.8 per core
-   Critical: > 0.8 per core

**Slow Queries**

-   Good: 0
-   Acceptable: 1-5
-   Warning: > 5

## Common Tasks

### Check Why API is Slow

1. Open performance dashboard
2. Look at "Slowest Requests" section
3. Check "Database Performance" for slow queries
4. Review "API Endpoints Performance" table

### Investigate High Memory Usage

1. Run: `php scripts/monitor_system_performance.php`
2. Check "Memory" section
3. Review recent requests in dashboard
4. Look for memory-intensive operations

### Monitor After Deployment

1. Open performance dashboard
2. Set time range to "Last Hour"
3. Watch for:
    - Increased response times
    - New slow queries
    - Memory usage spikes
    - Performance alerts

## Automated Monitoring

The system automatically:

-   ✅ Monitors performance every 5 minutes
-   ✅ Checks for alerts every 15 minutes
-   ✅ Rotates logs daily
-   ✅ Tracks all API requests
-   ✅ Records database query performance

## Troubleshooting

### Logs Not Being Created

Check permissions:

```bash
chmod 755 logs/
touch logs/performance_tracker.log
chmod 644 logs/performance_tracker.log
```

### Cron Jobs Not Running

Check cron service:

```bash
sudo systemctl status cron
```

View cron logs:

```bash
grep CRON /var/log/syslog | tail -20
```

### Dashboard Not Loading Data

Check API endpoint:

```bash
curl "https://your-domain.com/api/performance-metrics.php?action=current"
```

Check PHP errors:

```bash
tail -f logs/error.log
```

## Performance Thresholds

Default thresholds (configurable in scripts):

```php
$thresholds = [
    'cpu_load_per_core' => 0.8,
    'memory_usage_percent' => 85,
    'disk_usage_percent' => 90,
    'avg_response_time_ms' => 2000,
    'slow_query_count' => 10
];
```

## Next Steps

1. ✅ Set up email/Slack notifications
2. ✅ Review performance trends weekly
3. ✅ Optimize slow queries
4. ✅ Set up capacity planning alerts
5. ✅ Document baseline performance metrics

## Support

-   📖 Full documentation: `docs/PERFORMANCE_MONITORING_GUIDE.md`
-   🔍 Check logs: `logs/performance_*.log`
-   🏥 Health check: `/api/performance-metrics.php?action=health_check`
-   📊 Dashboard: `/performance-dashboard.html`
