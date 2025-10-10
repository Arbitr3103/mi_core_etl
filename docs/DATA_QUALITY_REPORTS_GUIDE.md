# Data Quality Reports System - User Guide

## Overview

The Data Quality Reports System provides comprehensive reporting and analysis of data quality issues in the MDM (Master Data Management) system. It helps identify and track products with missing names, synchronization errors, and items requiring manual review.

## Features

### 1. **Missing Names Report**

Identifies products with:

- Completely missing names
- Placeholder names (e.g., "Товар Ozon ID 123")
- Names marked as "требует обновления" (needs update)
- Empty name fields

### 2. **Sync Errors Report**

Tracks products with synchronization issues:

- Failed sync attempts
- Never synchronized products
- Stale data (not synced in 7+ days)
- Timestamp tracking for last sync

### 3. **Manual Review Report**

Flags products requiring human attention:

- Missing Ozon ID or Inventory ID
- No name data available
- Repeated sync failures
- High stock items without proper names
- Data inconsistencies

### 4. **Summary Statistics**

Provides overall health metrics:

- Data quality health score (0-100%)
- Percentage of products with issues
- Total counts for each issue type
- Trend analysis

### 5. **Export Functionality**

Export reports in multiple formats:

- CSV for spreadsheet analysis
- JSON for programmatic access
- HTML for readable reports

## Usage

### Web Dashboard

Access the dashboard at: `html/data_quality_dashboard.php`

**Features:**

- Real-time summary cards showing key metrics
- Tabbed interface for different report types
- Sortable and paginated tables
- One-click export to CSV or JSON
- Auto-refresh every 30 seconds

**Navigation:**

1. Open the dashboard in your browser
2. View summary cards at the top showing overall health
3. Click tabs to switch between report types
4. Use export buttons to download data
5. Navigate through pages using pagination controls

### API Endpoints

Base URL: `/api/data-quality-reports.php`

#### Get Summary Statistics

```bash
GET /api/data-quality-reports.php?action=summary
```

**Response:**

```json
{
  "success": true,
  "data": {
    "health_score": 85.5,
    "missing_names": 150,
    "missing_names_percent": 5.2,
    "sync_errors": 75,
    "sync_errors_percent": 2.6,
    "manual_review": 200,
    "manual_review_percent": 6.9,
    "total_products": 2890
  }
}
```

#### Get Missing Names Report

```bash
GET /api/data-quality-reports.php?action=missing_names&limit=50&offset=0
```

**Response:**

```json
{
  "success": true,
  "data": {
    "products": [
      {
        "id": 123,
        "inventory_product_id": "456789",
        "ozon_product_id": "987654",
        "sku_ozon": "266215809",
        "cached_name": null,
        "sync_status": "pending",
        "last_api_sync": "2025-10-09 14:30:00",
        "dim_product_name": "Товар Ozon ID 987654",
        "issue_type": "placeholder_name",
        "quantity_present": 150,
        "warehouse_name": "Москва"
      }
    ],
    "total": 150,
    "limit": 50,
    "offset": 0
  }
}
```

#### Get Sync Errors Report

```bash
GET /api/data-quality-reports.php?action=sync_errors&limit=50&offset=0
```

#### Get Manual Review Report

```bash
GET /api/data-quality-reports.php?action=manual_review&limit=50&offset=0
```

#### Export Report

```bash
# Export as CSV
GET /api/data-quality-reports.php?action=export&type=missing_names&format=csv

# Export as JSON
GET /api/data-quality-reports.php?action=export&type=all&format=json
```

**Export Types:**

- `missing_names` - Only products with missing names
- `sync_errors` - Only products with sync errors
- `manual_review` - Only products requiring review
- `all` - Combined report (deduplicated)

### Command Line Interface

Use the CLI tool for automated reporting and integration with scripts.

#### Basic Usage

```bash
# Generate JSON report for all issues
php generate_data_quality_report.php --type=all --format=json

# Generate CSV report for missing names
php generate_data_quality_report.php --type=missing_names --format=csv --output=report.csv

# Generate HTML report with summary
php generate_data_quality_report.php --type=all --format=html --output=report.html

# Get only summary statistics
php generate_data_quality_report.php --summary
```

#### CLI Options

| Option            | Description                                                         | Default |
| ----------------- | ------------------------------------------------------------------- | ------- |
| `--type=TYPE`     | Report type: `missing_names`, `sync_errors`, `manual_review`, `all` | `all`   |
| `--format=FORMAT` | Output format: `json`, `csv`, `html`                                | `json`  |
| `--output=FILE`   | Output file path (optional)                                         | stdout  |
| `--limit=N`       | Maximum number of records                                           | 1000    |
| `--summary`       | Show only summary statistics                                        | false   |

#### Examples

**1. Daily Report Generation**

```bash
#!/bin/bash
# Generate daily report and email to team
php generate_data_quality_report.php \
  --type=all \
  --format=html \
  --output=/tmp/daily_report.html

# Email the report (requires mail setup)
mail -s "Daily Data Quality Report" team@example.com < /tmp/daily_report.html
```

**2. Export High Priority Issues**

```bash
# Export products requiring manual review to CSV
php generate_data_quality_report.php \
  --type=manual_review \
  --format=csv \
  --output=high_priority_$(date +%Y%m%d).csv
```

**3. Monitor Health Score**

```bash
# Get health score and alert if below threshold
HEALTH=$(php generate_data_quality_report.php --summary | jq '.health_score')

if (( $(echo "$HEALTH < 80" | bc -l) )); then
  echo "⚠️  Data quality health score is low: $HEALTH%"
  # Send alert
fi
```

## Report Categories

### Issue Types (Missing Names)

| Type                 | Description                                  |
| -------------------- | -------------------------------------------- |
| `completely_missing` | No name data available at all                |
| `placeholder_name`   | Generic placeholder like "Товар Ozon ID 123" |
| `needs_update`       | Name marked as requiring update              |
| `unknown_issue`      | Other naming issues                          |

### Error Types (Sync Errors)

| Type           | Description                         |
| -------------- | ----------------------------------- |
| `sync_failed`  | Synchronization attempt failed      |
| `never_synced` | Product has never been synchronized |
| `stale_data`   | Last sync was more than 7 days ago  |
| `unknown`      | Other synchronization issues        |

### Review Reasons (Manual Review)

| Reason                 | Description                        | Priority |
| ---------------------- | ---------------------------------- | -------- |
| `missing_ozon_id`      | Ozon product ID is missing         | Medium   |
| `missing_inventory_id` | Inventory ID is missing            | Medium   |
| `no_name_data`         | No name data from any source       | High     |
| `repeated_failures`    | Multiple sync failures             | High     |
| `high_stock_no_name`   | High inventory without proper name | High     |
| `data_inconsistency`   | Data doesn't match across sources  | Low      |

### Priority Levels

- **High**: Quantity > 100 units
- **Medium**: Quantity 10-100 units
- **Low**: Quantity < 10 units

## Integration Examples

### Automated Monitoring

```bash
# Add to crontab for daily monitoring
0 9 * * * /usr/bin/php /path/to/generate_data_quality_report.php --summary >> /var/log/data_quality.log
```

### Slack Notifications

```bash
#!/bin/bash
# Send data quality summary to Slack

SUMMARY=$(php generate_data_quality_report.php --summary)
HEALTH=$(echo $SUMMARY | jq '.health_score')
MISSING=$(echo $SUMMARY | jq '.missing_names')

curl -X POST -H 'Content-type: application/json' \
  --data "{\"text\":\"📊 Data Quality Report\nHealth Score: ${HEALTH}%\nMissing Names: ${MISSING}\"}" \
  YOUR_SLACK_WEBHOOK_URL
```

### Python Integration

```python
import requests
import pandas as pd

# Fetch data quality report
response = requests.get('http://your-server/api/data-quality-reports.php?action=missing_names&limit=1000')
data = response.json()

if data['success']:
    # Convert to pandas DataFrame
    df = pd.DataFrame(data['data']['products'])

    # Analyze and visualize
    print(f"Total issues: {len(df)}")
    print(df['issue_type'].value_counts())

    # Export to Excel
    df.to_excel('data_quality_issues.xlsx', index=False)
```

## Testing

Run the test suite to verify functionality:

```bash
php tests/test_data_quality_reports.php
```

**Test Coverage:**

- Missing names report queries
- Sync errors report queries
- Manual review report queries
- Summary statistics calculation
- Export functionality (CSV, JSON)
- API endpoint responses

## Troubleshooting

### Common Issues

**1. No data returned**

- Check if `product_cross_reference` table exists
- Verify database connection in `config.php`
- Ensure tables have data

**2. Slow query performance**

- Check if indexes exist on `product_cross_reference`
- Run `ANALYZE TABLE product_cross_reference`
- Consider adding more indexes for frequently queried columns

**3. Export fails**

- Check file permissions for output directory
- Verify sufficient disk space
- Check PHP memory limit for large exports

**4. API returns errors**

- Check PHP error logs
- Verify database credentials
- Ensure proper CORS headers if accessing from different domain

### Performance Optimization

For large datasets (>100k products):

1. **Add indexes:**

```sql
CREATE INDEX idx_sync_status ON product_cross_reference(sync_status);
CREATE INDEX idx_last_sync ON product_cross_reference(last_api_sync);
```

2. **Use pagination:**

```bash
# Process in batches
for i in {0..10}; do
  offset=$((i * 1000))
  php generate_data_quality_report.php --limit=1000 --offset=$offset
done
```

3. **Cache summary statistics:**

```sql
-- Create materialized view for summary
CREATE TABLE data_quality_summary_cache AS
SELECT
  COUNT(*) as total_products,
  SUM(CASE WHEN sync_status = 'failed' THEN 1 ELSE 0 END) as failed_syncs,
  NOW() as cached_at
FROM product_cross_reference;

-- Refresh periodically
TRUNCATE data_quality_summary_cache;
INSERT INTO data_quality_summary_cache SELECT ...;
```

## Best Practices

1. **Regular Monitoring**: Check reports daily to catch issues early
2. **Prioritize High-Stock Items**: Focus on products with high inventory first
3. **Track Trends**: Monitor health score over time to measure improvement
4. **Automate Exports**: Schedule regular exports for offline analysis
5. **Set Alerts**: Configure alerts when health score drops below threshold
6. **Document Fixes**: Keep track of manual corrections made

## Support

For issues or questions:

1. Check the troubleshooting section above
2. Review test output: `php tests/test_data_quality_reports.php`
3. Check application logs
4. Review database query performance

## Related Documentation

- [MDM System Design](../design.md)
- [Sync Engine Guide](SYNC_ENGINE_GUIDE.md)
- [API Documentation](../API_EXAMPLES.md)
