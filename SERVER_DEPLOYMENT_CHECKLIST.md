# 🚀 Server Deployment Checklist

## Pre-Deployment ✅

- [x] Code committed to Git
- [x] Pushed to GitHub (main branch)
- [x] All tests passing (32/32)
- [x] Documentation complete
- [x] Local testing successful

## Server Deployment Steps

### 1. Connect to Server

```bash
ssh root@185.221.153.28
```

### 2. Navigate to Project

```bash
cd /var/www/mi_core_etl
```

### 3. Backup Current State

```bash
# Create backup
cp -r . ../mi_core_etl_backup_$(date +%Y%m%d_%H%M%S)

# Or just backup database
mysqldump -u root -p mi_core > backup_$(date +%Y%m%d).sql
```

### 4. Pull Latest Code

```bash
git pull origin main
```

Expected output:

```
Updating 6988c4c..5803829
Fast-forward
 80 files changed, 28023 insertions(+), 984 deletions(-)
 ...
```

### 5. Set Permissions

```bash
chmod +x monitor_data_quality.php
chmod +x setup_monitoring_cron.sh
chmod +x sync-real-product-names-v2.php
chmod +x test_monitoring_system.php

# Ensure logs directory is writable
mkdir -p logs
chmod 755 logs
```

### 6. Verify Installation

```bash
php test_monitoring_system.php
```

Expected output:

```
=== MDM Monitoring System Test ===

✓ Config loaded successfully
✓ Database connected
✓ DataQualityMonitor initialized
✓ Metrics collected successfully
...
✅ ALL TESTS PASSED - System ready for deployment!
```

### 7. Setup Cron Jobs

```bash
./setup_monitoring_cron.sh
```

When prompted, answer `y` to install cron jobs.

Verify cron jobs:

```bash
crontab -l | grep monitor_data_quality
```

### 8. Configure Alerts (Optional)

Edit config.php:

```bash
nano config.php
```

Add at the end:

```php
// Alert configuration
define('ALERT_EMAIL', 'your-email@example.com');
define('SLACK_WEBHOOK_URL', 'https://hooks.slack.com/services/YOUR/WEBHOOK/URL');
```

### 9. Run Initial Test

```bash
php monitor_data_quality.php --verbose
```

Check output for any errors.

### 10. Verify Web Access

Open in browser:

- Dashboard: http://185.221.153.28/html/quality_dashboard.php
- API Health: http://185.221.153.28/api/quality-metrics.php?action=health

Or test with curl:

```bash
curl http://185.221.153.28/api/quality-metrics.php?action=health
curl http://185.221.153.28/html/quality_dashboard.php | head -20
```

## Post-Deployment Verification

### Check 1: Monitoring Script Works

```bash
php monitor_data_quality.php --report-only
```

Should show metrics without errors.

### Check 2: Dashboard Accessible

```bash
curl -I http://185.221.153.28/html/quality_dashboard.php
```

Should return `HTTP/1.1 200 OK`

### Check 3: API Responds

```bash
curl http://185.221.153.28/api/quality-metrics.php?action=health | python3 -m json.tool
```

Should return JSON with health status.

### Check 4: Cron Jobs Running

Wait 1 hour, then check:

```bash
cat logs/monitoring_cron.log
```

Should have entries from cron execution.

### Check 5: Logs Being Created

```bash
ls -la logs/
```

Should see:

- monitoring_cron.log
- quality_alerts.log (if alerts triggered)

## Troubleshooting

### Issue: Permission Denied

```bash
chmod +x monitor_data_quality.php
chmod 755 logs
```

### Issue: Database Connection Failed

```bash
php -r "require 'config.php'; var_dump(DB_HOST, DB_NAME, DB_USER);"
```

Check .env file:

```bash
cat .env
```

### Issue: Dashboard Shows Blank Page

Check PHP error log:

```bash
tail -f /var/log/php-fpm/error.log
# or
tail -f /var/log/apache2/error.log
```

### Issue: API Returns 500 Error

Test API directly:

```bash
cd api
php quality-metrics.php
```

### Issue: Cron Jobs Not Running

Check cron service:

```bash
systemctl status cron
# or
service cron status
```

Check cron logs:

```bash
tail -f /var/log/syslog | grep CRON
```

## Rollback Procedure

If something goes wrong:

```bash
# Stop cron jobs
crontab -r

# Restore from backup
cd /var/www
rm -rf mi_core_etl
mv mi_core_etl_backup_YYYYMMDD_HHMMSS mi_core_etl

# Or just revert Git
cd /var/www/mi_core_etl
git reset --hard 6988c4c
```

## Success Criteria

- [ ] Code pulled successfully
- [ ] All tests passing on server
- [ ] Cron jobs installed
- [ ] Dashboard accessible
- [ ] API responding correctly
- [ ] Logs being created
- [ ] No errors in error logs

## Next Steps After Deployment

1. **Monitor for 1 hour** - Check logs and dashboard
2. **Run sync script** - `php sync-real-product-names-v2.php`
3. **Check quality score** - Should improve after sync
4. **Review alerts** - Check if any alerts triggered
5. **Adjust thresholds** - If needed based on actual data

## Support

If issues persist:

- Check: `docs/MDM_TROUBLESHOOTING_GUIDE.md`
- Run: `php tests/test_data_quality_monitoring.php`
- Review: `logs/` directory
- Contact: Development team

---

**Deployment Date**: ******\_******  
**Deployed By**: ******\_******  
**Status**: ******\_******  
**Notes**: ******\_******
