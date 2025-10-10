# Quick Start: Data Quality Reports

## 🚀 Get Started in 3 Steps

### Step 1: View the Dashboard

Open in your browser:

```
http://your-server/html/data_quality_dashboard.php
```

You'll see:

- 📊 Health Score (overall data quality)
- 📝 Missing Names count
- ⚠️ Sync Errors count
- 🔍 Items needing Manual Review

### Step 2: Explore Reports

Click the tabs to view different reports:

- **Missing Names** - Products without proper names
- **Sync Errors** - Products with synchronization issues
- **Manual Review** - High-priority items needing attention

### Step 3: Export Data

Click the export buttons to download:

- **CSV** - For Excel/spreadsheet analysis
- **JSON** - For programmatic processing

## 📊 Quick Commands

### Check Health Score

```bash
php generate_data_quality_report.php --summary
```

### Export Missing Names

```bash
php generate_data_quality_report.php \
  --type=missing_names \
  --format=csv \
  --output=missing_names.csv
```

### Generate HTML Report

```bash
php generate_data_quality_report.php \
  --type=all \
  --format=html \
  --output=report.html
```

## 🔧 API Quick Reference

### Get Summary

```bash
curl "http://your-server/api/data-quality-reports.php?action=summary"
```

### Get Missing Names

```bash
curl "http://your-server/api/data-quality-reports.php?action=missing_names&limit=50"
```

### Export to CSV

```bash
curl "http://your-server/api/data-quality-reports.php?action=export&type=all&format=csv" > report.csv
```

## 📈 Understanding the Reports

### Health Score

- **90-100%** 🟢 Excellent - Very few issues
- **70-89%** 🟡 Good - Some issues to address
- **50-69%** 🟠 Fair - Significant issues
- **0-49%** 🔴 Poor - Critical issues

### Priority Levels

- **High** 🔴 - Products with >100 units in stock
- **Medium** 🟡 - Products with 10-100 units
- **Low** 🟢 - Products with <10 units

## 🎯 Common Tasks

### Find High-Stock Items Without Names

1. Open dashboard
2. Click "Missing Names" tab
3. Look for items with high "Остаток" (quantity)
4. Export for fixing

### Identify Sync Failures

1. Click "Sync Errors" tab
2. Look for "sync_failed" error type
3. Check "Часов назад" (hours since sync)
4. Prioritize recent failures

### Review High-Priority Items

1. Click "Manual Review" tab
2. Filter by "HIGH" priority
3. Check "Причина проверки" (review reason)
4. Address items with high stock first

## 🔄 Automation

### Daily Health Check

Add to crontab:

```bash
0 9 * * * php /path/to/generate_data_quality_report.php --summary >> /var/log/health.log
```

### Weekly Report

```bash
0 0 * * 1 php /path/to/generate_data_quality_report.php --type=all --format=html --output=/reports/weekly_$(date +\%Y\%m\%d).html
```

## 📚 More Information

For detailed documentation, see:

- `docs/DATA_QUALITY_REPORTS_GUIDE.md` - Complete guide
- `TASK_4.4_COMPLETION_REPORT.md` - Implementation details

## 🆘 Troubleshooting

**No data showing?**

- Check if `product_cross_reference` table exists
- Verify database connection in `config.php`

**Slow performance?**

- Use pagination (limit parameter)
- Add indexes to frequently queried columns

**Export not working?**

- Check file permissions
- Verify disk space
- Check PHP memory limit

## ✅ Next Steps

1. Review current health score
2. Export high-priority issues
3. Set up daily monitoring
4. Address critical items first
5. Track improvement over time

---

**Need Help?** Check the full documentation in `docs/DATA_QUALITY_REPORTS_GUIDE.md`
