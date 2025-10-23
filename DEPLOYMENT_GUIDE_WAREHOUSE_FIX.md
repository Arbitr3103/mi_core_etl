# Warehouse Dashboard Fix - Deployment Guide

**Date:** October 23, 2024  
**Task:** 9.3 Deploy to production  
**Production URL:** https://www.market-mi.ru/warehouse-dashboard

## Prerequisites

✅ Build completed successfully (Task 9.1)  
✅ Local testing passed (Task 9.2)  
✅ All assets verified (CSS: 22.67 KB, JS bundles generated)

## Deployment Options

### Option 1: Automated Deployment (Recommended)

Run the automated deployment script:

```bash
./scripts/deploy_warehouse_dashboard_fix.sh
```

This script will:

1. Create a deployment package from `frontend/dist`
2. Upload to production server
3. Backup existing files
4. Extract and deploy new files
5. Set correct permissions
6. Verify deployment

### Option 2: Manual Deployment

#### Step 1: Create Deployment Package

```bash
cd frontend/dist
tar -czf /tmp/warehouse-dashboard-fix.tar.gz .
cd ../..
```

#### Step 2: Upload to Server

```bash
scp /tmp/warehouse-dashboard-fix.tar.gz root@www.market-mi.ru:/tmp/
```

#### Step 3: Deploy on Server

SSH into the production server:

```bash
ssh root@www.market-mi.ru
```

Then run these commands on the server:

```bash
# Create backup
BACKUP_DIR="/tmp/warehouse-dashboard-backup-$(date +%Y%m%d_%H%M%S)"
mkdir -p "$BACKUP_DIR"
cp -r /var/www/market-mi.ru/warehouse-dashboard "$BACKUP_DIR/"

# Deploy new files
cd /var/www/market-mi.ru/warehouse-dashboard
tar -xzf /tmp/warehouse-dashboard-fix.tar.gz

# Set permissions
chown -R www-data:www-data /var/www/market-mi.ru/warehouse-dashboard
# OR if using Apache:
# chown -R apache:apache /var/www/market-mi.ru/warehouse-dashboard

chmod -R 755 /var/www/market-mi.ru/warehouse-dashboard

# Verify files
ls -lh /var/www/market-mi.ru/warehouse-dashboard/
ls -lh /var/www/market-mi.ru/warehouse-dashboard/assets/css/
ls -lh /var/www/market-mi.ru/warehouse-dashboard/assets/js/

# Test endpoint
curl -I http://localhost/warehouse-dashboard/

# Cleanup
rm /tmp/warehouse-dashboard-fix.tar.gz
```

## Verification Checklist

After deployment, verify the following:

### On Server

-   [ ] Files deployed to `/var/www/market-mi.ru/warehouse-dashboard/`
-   [ ] `index.html` exists
-   [ ] `assets/css/` directory exists with CSS file
-   [ ] `assets/js/` directory exists with JS bundles
-   [ ] Permissions set to 755
-   [ ] Ownership set to www-data or apache
-   [ ] Endpoint responds: `curl http://localhost/warehouse-dashboard/`

### File Structure on Server

```
/var/www/market-mi.ru/warehouse-dashboard/
├── index.html
├── vite.svg
└── assets/
    ├── css/
    │   └── index-BnGjtDq2.css (22.67 KB)
    └── js/
        ├── index-MPEEFBkx.js
        ├── ui-CRQylcxt.js
        ├── WarehouseDashboardPage-CyQaM3TT.js
        ├── vendor-CqFCj2_q.js
        └── react-vendor-Dm4r2cAM.js
```

## Rollback Procedure

If deployment fails or causes issues:

```bash
# On server
BACKUP_DIR="/tmp/warehouse-dashboard-backup-YYYYMMDD_HHMMSS"  # Use actual backup dir
rm -rf /var/www/market-mi.ru/warehouse-dashboard
cp -r "$BACKUP_DIR/warehouse-dashboard" /var/www/market-mi.ru/
```

## Next Steps

After successful deployment:

1. **Clear browser cache** (Task 9.4)
2. **Test in browser** (Task 9.4)
3. **Verify layout** (Task 10.1)
4. **Functional testing** (Task 10.2)

## Troubleshooting

### Issue: 404 errors for assets

**Solution:** Check that assets are in the correct location:

```bash
ls -R /var/www/market-mi.ru/warehouse-dashboard/assets/
```

### Issue: Permission denied

**Solution:** Set correct permissions:

```bash
chmod -R 755 /var/www/market-mi.ru/warehouse-dashboard
chown -R www-data:www-data /var/www/market-mi.ru/warehouse-dashboard
```

### Issue: Old version still showing

**Solution:** Clear browser cache or test in incognito mode

### Issue: CSS not loading

**Solution:** Verify CSS file exists and has content:

```bash
ls -lh /var/www/market-mi.ru/warehouse-dashboard/assets/css/
cat /var/www/market-mi.ru/warehouse-dashboard/assets/css/index-BnGjtDq2.css | head -20
```

## Support

If you encounter issues:

1. Check server logs: `/var/log/nginx/error.log` or `/var/log/apache2/error.log`
2. Check browser console for errors (F12)
3. Verify file permissions and ownership
4. Ensure web server is running: `systemctl status nginx` or `systemctl status apache2`

## Deployment Summary

-   **Build Size:** ~330 KB (uncompressed), ~100 KB (gzipped)
-   **CSS File:** 22.67 KB
-   **JS Bundles:** 5 files totaling ~307 KB
-   **Deployment Time:** ~2-5 minutes
-   **Downtime:** Minimal (< 30 seconds)
