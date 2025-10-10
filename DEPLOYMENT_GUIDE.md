# MDM Monitoring System - Deployment Guide

## 🚀 Deployment Steps

### 1. Pull Latest Code on Server

```bash
# SSH to server
ssh root@185.221.153.28

# Navigate to project directory
cd /var/www/mi_core_etl

# Pull latest changes
git pull origin main

# Set permissions
chmod +x monitor_data_quality.php
chmod +x setup_monitoring_cron.sh
chmod +x sync-real-product-names-v2.php
```

### 2. Configure Environment

```bash
# Ensure .env file has correct settings
cat .env

# Should contain:
# DB_HOST=localhost
# DB_USER=your_user
# DB_PASSWORD=your_password
# DB_NAME=mi_core
# OZON_CLIENT_ID=your_client_id
# OZON_API_KEY=your_api_key
```

### 3. Setup Monitoring

```bash
# Run setup script
./setup_monitoring_cron.sh

# Answer 'y' when prompted to install cron jobs
```

### 4. Test Monitoring System

```bash
# Run manual test
php monitor_data_quality.php --verbose

# Expected output:
# === MDM Data Quality Monitor ===
# Started at: 2025-10-10 14:30:00
#
# --- Sync Status ---
# Total products: X
# Synced: X (X%)
# ...
```

### 5. Verify Dashboard Access

Open in browser:

```
http://185.221.153.28/html/quality_dashboard.php
```

### 6. Test API Endpoints

```bash
# Test metrics endpoint
curl http://185.221.153.28/api/quality-metrics.php?action=metrics

# Test health endpoint
curl http://185.221.153.28/api/quality-metrics.php?action=health
```

### 7. Configure Alerts (Optional)

Edit `config.php` on server:

```bash
nano config.php

# Add at the end:
define('ALERT_EMAIL', 'admin@example.com');
define('SLACK_WEBHOOK_URL', 'https://hooks.slack.com/services/YOUR/WEBHOOK/URL');
```

### 8. Verify Cron Jobs

```bash
# Check cron jobs are installed
crontab -l | grep monitor_data_quality

# Should show:
# 0 * * * * cd /var/www/mi_core_etl && php monitor_data_quality.php
```

### 9. Check Logs

```bash
# View monitoring logs
tail -f logs/monitoring_cron.log

# View alert logs
tail -f logs/quality_alerts.log
```

## 🔍 Verification Checklist

After deployment, verify:

- [ ] Code pulled successfully
- [ ] Permissions set correctly
- [ ] Manual monitoring test passes
- [ ] Dashboard accessible in browser
- [ ] API endpoints responding
- [ ] Cron jobs installed
- [ ] Logs being created
- [ ] Alerts configured (if needed)

## 🐛 Troubleshooting

### Issue: Permission Denied

```bash
chmod +x monitor_data_quality.php
chmod +x setup_monitoring_cron.sh
```

### Issue: Database Connection Failed

```bash
# Test database connection
php -r "require 'config.php'; echo 'DB: ' . DB_NAME . PHP_EOL;"
```

### Issue: Dashboard Shows Errors

```bash
# Check PHP error log
tail -f /var/log/php-fpm/error.log

# Or Apache error log
tail -f /var/log/apache2/error.log
```

### Issue: API Returns 500 Error

```bash
# Test API directly
php api/quality-metrics.php

# Check for syntax errors
php -l api/quality-metrics.php
```

## 📊 Post-Deployment Testing

### Test 1: Run Monitoring Script

```bash
php monitor_data_quality.php --verbose
```

Expected: No errors, metrics displayed

### Test 2: Access Dashboard

```bash
curl -I http://185.221.153.28/html/quality_dashboard.php
```

Expected: HTTP 200 OK

### Test 3: Test API

```bash
curl http://185.221.153.28/api/quality-metrics.php?action=health
```

Expected: JSON response with health status

### Test 4: Check Cron Execution

```bash
# Wait 1 hour, then check logs
cat logs/monitoring_cron.log
```

Expected: Log entries showing monitoring runs

## 🎯 Next Steps After Deployment

1. **Monitor for 24 hours** - Ensure system runs smoothly
2. **Review first alerts** - Check if thresholds need adjustment
3. **Share dashboard URL** - With team members
4. **Document any issues** - For future reference
5. **Schedule weekly review** - Of monitoring data

## 📞 Support

If issues persist:

1. Check logs in `logs/` directory
2. Run tests: `php tests/test_data_quality_monitoring.php`
3. Review documentation in `docs/` directory
4. Check troubleshooting guide: `docs/MDM_TROUBLESHOOTING_GUIDE.md`

---

**Deployment Date**: 2025-10-10  
**Version**: 1.0.0  
**Status**: Ready for Production ✅
