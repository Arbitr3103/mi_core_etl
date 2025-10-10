# MDM Monitoring System - Quick Start Guide

## 🚀 Get Started in 5 Minutes

### Step 1: Configure Alerts (Optional)

Edit `config.php` and add your alert settings:

```php
// Email alerts
define('ALERT_EMAIL', 'admin@example.com');

// Slack alerts (optional)
define('SLACK_WEBHOOK_URL', 'https://hooks.slack.com/services/YOUR/WEBHOOK/URL');
```

### Step 2: Setup Automated Monitoring

```bash
# Run the setup script
./setup_monitoring_cron.sh

# Follow the prompts to install cron jobs
```

### Step 3: Test the System

```bash
# Run a manual check
php monitor_data_quality.php --verbose
```

You should see output like:

```
=== MDM Data Quality Monitor ===
Started at: 2025-10-10 14:30:00

--- Sync Status ---
Total products: 1000
Synced: 950 (95.0%)
Pending: 30 (3.0%)
Failed: 20 (2.0%)

--- Data Quality ---
Products with real names: 900 (90.0%)
Products with brands: 850 (85.0%)
Average cache age: 2.5 days

--- Overall Quality Score ---
✓ 92/100
Status: Good - System healthy

✓ All quality checks passed
```

### Step 4: View the Dashboard

Open in your browser:

```
http://your-domain/html/quality_dashboard.php
```

## 📊 What Gets Monitored

### Sync Status

- ✅ Synced products percentage
- ⏳ Pending products percentage
- ❌ Failed products percentage

### Data Quality

- 📝 Products with real names
- 🏷️ Products with brand information
- 📅 Cache freshness

### Errors

- 🔴 Errors in last 24 hours
- 📈 Error trends
- 🎯 Most common error types

### Performance

- ⚡ Products synced today
- 🕐 Last sync time

## 🔔 Alert Thresholds

Default thresholds (can be customized):

| Metric        | Threshold  | Alert Level |
| ------------- | ---------- | ----------- |
| Failed syncs  | > 5%       | 🔴 Critical |
| Pending syncs | > 10%      | ⚠️ Warning  |
| Real names    | < 80%      | ⚠️ Warning  |
| Sync age      | > 48 hours | 🔴 Critical |
| Hourly errors | > 10       | 🔴 Critical |

## 🛠️ Common Commands

```bash
# Run basic check
php monitor_data_quality.php

# Run with detailed output
php monitor_data_quality.php --verbose

# Generate report without alerts
php monitor_data_quality.php --report-only

# View help
php monitor_data_quality.php --help
```

## 📡 API Endpoints

```bash
# Get all metrics
curl http://localhost/api/quality-metrics.php?action=metrics

# Quick health check
curl http://localhost/api/quality-metrics.php?action=health

# Get recent alerts
curl http://localhost/api/quality-metrics.php?action=alerts&limit=10

# Get sync status
curl http://localhost/api/quality-metrics.php?action=sync-status
```

## 🔧 Customizing Thresholds

Edit your monitoring script or create a custom configuration:

```php
$monitor = new DataQualityMonitor($pdo);

$monitor->setThresholds([
    'failed_percentage' => 3,      // More strict
    'pending_percentage' => 15,    // More lenient
    'real_names_percentage' => 90, // Higher quality
    'sync_age_hours' => 24,        // More frequent
    'error_count_hourly' => 5      // Lower tolerance
]);
```

## 📧 Setting Up Email Alerts

1. Configure in `config.php`:

```php
define('ALERT_EMAIL', 'admin@example.com');
```

2. Ensure PHP mail() function is configured on your server

3. Test:

```bash
php -r "mail('test@example.com', 'Test', 'Test message');"
```

## 💬 Setting Up Slack Alerts

1. Create a Slack webhook:

   - Go to https://api.slack.com/apps
   - Create new app
   - Add "Incoming Webhooks"
   - Copy webhook URL

2. Configure in `config.php`:

```php
define('SLACK_WEBHOOK_URL', 'https://hooks.slack.com/services/YOUR/WEBHOOK/URL');
```

3. Test:

```bash
php monitor_data_quality.php --verbose
```

## 📋 Cron Schedule

After running `setup_monitoring_cron.sh`, these jobs will be active:

```bash
# Hourly checks
0 * * * * php monitor_data_quality.php

# Detailed check every 6 hours
0 */6 * * * php monitor_data_quality.php --verbose

# Daily report at 8 AM
0 8 * * * php monitor_data_quality.php --verbose --report-only

# Weekly log cleanup
0 3 * * 0 find logs -name "*.log" -mtime +30 -delete
```

View your cron jobs:

```bash
crontab -l
```

## 📁 Log Files

Check these files for monitoring history:

```bash
# Monitoring logs
tail -f logs/monitoring_cron.log

# Alert logs
tail -f logs/quality_alerts.log

# Detailed logs
tail -f logs/monitoring_detailed.log

# Daily reports
ls -la logs/daily_report_*.log
```

## 🐛 Troubleshooting

### No Alerts Being Sent

1. Check alert configuration in `config.php`
2. Verify email/Slack settings
3. Check logs: `logs/quality_alerts.log`

### Dashboard Not Loading

1. Test API: `curl http://localhost/api/quality-metrics.php?action=health`
2. Check database connection
3. View browser console for errors

### Cron Jobs Not Running

1. Verify installation: `crontab -l`
2. Check logs: `logs/monitoring_cron.log`
3. Verify script permissions: `ls -la monitor_data_quality.php`

## 📚 Documentation

For more detailed information:

- **Complete Guide**: `docs/MONITORING_SYSTEM_README.md`
- **Troubleshooting**: `docs/MDM_TROUBLESHOOTING_GUIDE.md`
- **API Reference**: `docs/MDM_MONITORING_GUIDE.md`
- **Class Usage**: `docs/MDM_CLASSES_USAGE_GUIDE.md`

## ✅ Verification Checklist

- [ ] Configuration file updated with alert settings
- [ ] Cron jobs installed and running
- [ ] Manual test completed successfully
- [ ] Dashboard accessible in browser
- [ ] API endpoints responding
- [ ] Alerts being received (email/Slack)
- [ ] Logs being created
- [ ] Team members notified

## 🎯 Next Steps

1. **Monitor Daily**: Check dashboard each morning
2. **Review Alerts**: Act on critical alerts immediately
3. **Adjust Thresholds**: Fine-tune based on your system
4. **Weekly Reports**: Review trends and patterns
5. **Document Issues**: Track problems and solutions

## 💡 Tips

- Start with default thresholds and adjust gradually
- Don't ignore warnings - they indicate potential issues
- Keep logs for at least 30 days for analysis
- Test alert handlers before relying on them
- Review the dashboard regularly, not just when alerts fire

## 🆘 Getting Help

If you encounter issues:

1. Check the troubleshooting guide
2. Review log files
3. Run tests: `php tests/test_data_quality_monitoring.php`
4. Consult documentation in `docs/` directory

---

**Ready to go!** Your monitoring system is now set up and running. 🎉

For questions or issues, refer to the complete documentation in the `docs/` directory.
