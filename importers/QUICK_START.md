# Ozon Warehouse Importer - Quick Start Guide

## Prerequisites

1. Python 3.7+ installed
2. PostgreSQL database running
3. `.env` file configured with database credentials

## Installation

```bash
# Install Python dependencies
pip install psycopg2-binary python-dotenv
```

## Quick Test (5 minutes)

Run the complete test flow with sample data:

```bash
# 1. Import test data
./importers/test_import.sh

# 2. Calculate metrics
./importers/test_metrics_calculation.sh

# 3. Open Warehouse Dashboard in browser
# Navigate to: http://your-domain/warehouse-dashboard
```

## Production Import

### Step 1: Get Ozon Reports

**Inventory Report:**

1. Login to Ozon Seller → Аналитика → Отчеты
2. Select "Товар-склад"
3. Download CSV → Save as `ozon_inventory.csv`

**Sales Report:**

1. Login to Ozon Seller → Аналитика → Отчеты
2. Select "Отчет о продажах"
3. Choose last 28 days
4. Download CSV → Save as `ozon_sales.csv`

### Step 2: Import Data

```bash
python3 importers/ozon_warehouse_importer.py \
    --both ozon_inventory.csv ozon_sales.csv \
    --start-date 2025-09-25 \
    --end-date 2025-10-22
```

### Step 3: Calculate Metrics

```bash
php scripts/refresh_warehouse_metrics.php --verbose
```

### Step 4: Validate

```bash
python3 importers/validate_import.py
python3 importers/validate_metrics.py
```

## Automation

Add to crontab for automatic updates:

```bash
# Edit crontab
crontab -e

# Add these lines:
# Refresh metrics every hour
0 * * * * cd /path/to/project && php scripts/refresh_warehouse_metrics.php

# Import fresh data daily at 2 AM (if you have automated downloads)
0 2 * * * cd /path/to/project && python3 importers/ozon_warehouse_importer.py --both /path/to/latest_inventory.csv /path/to/latest_sales.csv --start-date $(date -d '28 days ago' +\%Y-\%m-\%d) --end-date $(date +\%Y-\%m-\%d)
```

## Common Commands

```bash
# Import inventory only
python3 importers/ozon_warehouse_importer.py --inventory inventory.csv

# Import sales only
python3 importers/ozon_warehouse_importer.py \
    --sales sales.csv \
    --start-date 2025-09-25 \
    --end-date 2025-10-22

# Refresh metrics for specific warehouse
php scripts/refresh_warehouse_metrics.php --warehouse="Москва_РФЦ" --verbose

# Refresh metrics for specific product
php scripts/refresh_warehouse_metrics.php --product-id=123 --verbose
```

## Troubleshooting

**Import fails with "File not found":**

-   Check file path is correct
-   Use absolute paths if needed

**Database connection error:**

-   Verify `.env` file exists and has correct credentials
-   Check PostgreSQL is running: `pg_isready`

**No data in dashboard:**

-   Ensure import completed successfully
-   Run metrics calculation: `php scripts/refresh_warehouse_metrics.php`
-   Check logs: `tail -f logs/warehouse_metrics_refresh.log`

## Support

For detailed documentation, see:

-   `importers/README_OZON_WAREHOUSE_IMPORTER.md` - Full importer documentation
-   `.kiro/specs/warehouse-dashboard/TASK_10_COMPLETION_SUMMARY.md` - Implementation details
-   `docs/WAREHOUSE_DASHBOARD_API.md` - API documentation
