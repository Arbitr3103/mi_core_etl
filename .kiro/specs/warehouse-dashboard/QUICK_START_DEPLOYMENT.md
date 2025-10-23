# Quick Start: Warehouse Dashboard Backend Deployment

## TL;DR - Deploy in 3 Steps

### Step 1: Install sshpass (if needed)

```bash
# macOS
brew install hudochenkov/sshpass/sshpass

# Ubuntu/Debian
sudo apt-get install sshpass
```

### Step 2: Run Deployment Script

```bash
# Full automated deployment
./deployment/deploy_warehouse_dashboard_backend.sh
```

### Step 3: Verify

```bash
# Test API
curl 'http://178.72.129.61:8080/api/warehouse-dashboard.php?action=warehouses'
```

That's it! ✅

---

## What Gets Deployed

The deployment script automatically:

1. ✅ Uploads 6 PHP files to the server

    - `api/warehouse-dashboard.php` (main API endpoint)
    - `api/classes/WarehouseController.php`
    - `api/classes/WarehouseService.php`
    - `api/classes/WarehouseSalesAnalyticsService.php`
    - `api/classes/ReplenishmentCalculator.php`
    - `scripts/refresh_warehouse_metrics.php`

2. ✅ Applies database migrations

    - Extends `inventory` table with 11 new columns
    - Creates `warehouse_sales_metrics` table
    - Creates SQL functions for calculations
    - Adds indexes for performance

3. ✅ Sets up cron job

    - Runs every hour automatically
    - Refreshes warehouse metrics
    - Logs to `/var/www/mi_core_etl_new/logs/`

4. ✅ Runs initial metrics calculation
    - Populates `warehouse_sales_metrics` table
    - Calculates sales analytics
    - Determines liquidity status

---

## Deployment Options

### Dry Run (Recommended First)

See what will happen without making changes:

```bash
./deployment/deploy_warehouse_dashboard_backend.sh --dry-run
```

### Skip Migration

If migration was already applied:

```bash
./deployment/deploy_warehouse_dashboard_backend.sh --skip-migration
```

### Skip Cron Setup

If cron job is already configured:

```bash
./deployment/deploy_warehouse_dashboard_backend.sh --skip-cron
```

### Get Help

```bash
./deployment/deploy_warehouse_dashboard_backend.sh --help
```

---

## Quick Verification

After deployment, run these quick checks:

### 1. Test API Endpoints

```bash
# Get warehouses list
curl 'http://178.72.129.61:8080/api/warehouse-dashboard.php?action=warehouses'

# Get clusters list
curl 'http://178.72.129.61:8080/api/warehouse-dashboard.php?action=clusters'

# Get dashboard data
curl 'http://178.72.129.61:8080/api/warehouse-dashboard.php?action=dashboard&limit=5'
```

### 2. Check Cron Job

```bash
ssh vladimir@178.72.129.61 'crontab -l | grep warehouse'
```

### 3. View Logs

```bash
ssh vladimir@178.72.129.61 'tail -20 /var/www/mi_core_etl_new/logs/warehouse_metrics_refresh.log'
```

---

## Troubleshooting

### Problem: "sshpass: command not found"

**Solution:** Install sshpass (see Step 1 above) or use manual deployment (see DEPLOYMENT_GUIDE.md)

### Problem: "Cannot connect to server"

**Solution:** Check SSH credentials and network connection:

```bash
ssh vladimir@178.72.129.61
# Password: qwert1234
```

### Problem: API returns 500 error

**Solution:** Check PHP-FPM logs:

```bash
ssh vladimir@178.72.129.61 'sudo tail -50 /var/log/php8.1-fpm.log'
```

### Problem: Database connection failed

**Solution:** Test database connection:

```bash
ssh vladimir@178.72.129.61
export PGPASSWORD="MiCore2025SecurePass!"
psql -h localhost -U mi_core_user -d mi_core_db -c "SELECT version();"
```

---

## Next Steps

After successful backend deployment:

1. **Deploy Frontend** (Task 11.2)

    ```bash
    cd frontend
    npm run build
    cd ..
    ./deployment/upload_frontend.sh
    ```

2. **Test in Browser**

    - Open: http://178.72.129.61:8080
    - Navigate to Warehouse Dashboard
    - Test filters and sorting

3. **Monitor for 24 Hours**
    - Check cron logs daily
    - Verify metrics are updating
    - Monitor API response times

---

## Manual Deployment Alternative

If you can't use the automated script, see the full manual deployment instructions in:

📄 **DEPLOYMENT_GUIDE.md**

---

## Support

-   📖 Full deployment guide: `.kiro/specs/warehouse-dashboard/DEPLOYMENT_GUIDE.md`
-   📖 API documentation: `docs/WAREHOUSE_DASHBOARD_API.md`
-   📖 User guide: `docs/WAREHOUSE_DASHBOARD_USER_GUIDE.md`

---

**Estimated Deployment Time**: 5-10 minutes  
**Difficulty**: Easy (automated)  
**Prerequisites**: SSH access, sshpass installed

✅ Ready to deploy!
