# 🚀 Warehouse Dashboard - Production Deployment Guide

## Quick Start

**Automated Deployment:**

```bash
bash deploy_to_production.sh
```

**Target URL:** https://www.market-mi.ru/warehouse-dashboard

---

## 📋 Deployment Plan Overview

### Phase 1: Preparation (Local)

-   ✅ Code quality verification
-   ✅ Git commit and push
-   ✅ Production build creation
-   ✅ Deployment package preparation

### Phase 2: Server Deployment

-   🔄 Upload to production server
-   🔄 Database migration
-   🔄 Web server configuration
-   🔄 SSL/HTTPS setup

### Phase 3: Testing & Go-Live

-   🔄 Functionality testing
-   🔄 Performance verification
-   🔄 Monitoring setup
-   🔄 Documentation update

---

## 🛠️ Manual Deployment Steps

### 1. Run Automated Preparation

```bash
# This will handle git, build, and package creation
bash deploy_to_production.sh
```

### 2. Upload to Server

```bash
# Upload deployment package (update username)
scp -r /tmp/warehouse-dashboard-deploy-* username@market-mi.ru:/tmp/

# SSH to server
ssh username@market-mi.ru
```

### 3. Deploy on Server

```bash
# Run deployment script on server
sudo bash /tmp/warehouse-dashboard-deploy-*/deploy_on_server.sh
```

### 4. Configure Database

```bash
# Update database credentials in production config
sudo nano /var/www/market-mi.ru/config/database_postgresql.php

# Run database migrations
cd /var/www/market-mi.ru
php scripts/refresh_warehouse_metrics.php
```

### 5. Configure Web Server

**For Nginx:**

```bash
sudo cp production_configs/nginx_warehouse_dashboard.conf /etc/nginx/sites-available/warehouse-dashboard
sudo ln -s /etc/nginx/sites-available/warehouse-dashboard /etc/nginx/sites-enabled/
sudo nginx -t
sudo systemctl reload nginx
```

**For Apache:**

```bash
sudo cp production_configs/apache_warehouse_dashboard.conf /etc/apache2/sites-available/warehouse-dashboard.conf
sudo a2ensite warehouse-dashboard
sudo a2enmod rewrite ssl headers deflate
sudo apache2ctl configtest
sudo systemctl reload apache2
```

### 6. Test Deployment

```bash
# Test API
curl https://www.market-mi.ru/api/warehouse-dashboard.php

# Test dashboard
curl https://www.market-mi.ru/warehouse-dashboard
```

---

## 📁 File Structure on Server

```
/var/www/market-mi.ru/
├── public/                          # Web root (DocumentRoot)
│   ├── index.html                   # React app entry point
│   ├── assets/                      # Built CSS/JS/images
│   │   ├── index-[hash].js         # Main JS bundle
│   │   ├── index-[hash].css        # Main CSS bundle
│   │   └── ...                     # Other assets
│   └── api/                        # Backend API files
│       ├── warehouse-dashboard.php  # Main API endpoint
│       └── classes/                # PHP classes
├── config/                         # Configuration files
│   ├── database_postgresql.php     # DB config
│   └── ...                        # Other configs
├── scripts/                       # Maintenance scripts
│   ├── refresh_warehouse_metrics.php
│   └── ...
├── migrations/                    # Database migrations
├── importers/                     # Data import scripts
└── logs/                         # Application logs
```

---

## 🔧 Configuration Files

### Database Configuration

Update `/var/www/market-mi.ru/config/database_postgresql.php`:

```php
// Production database settings
define('DB_HOST', 'localhost');
define('DB_USER', 'production_user');
define('DB_PASSWORD', 'secure_password');
define('DB_NAME', 'production_db');
define('DB_PORT', '5432');
```

### Environment Variables

Create `/var/www/market-mi.ru/.env`:

```bash
# Production environment
PG_HOST=localhost
PG_USER=production_user
PG_PASSWORD=secure_password
PG_NAME=production_db
PG_PORT=5432

# API Keys
OZON_CLIENT_ID=your_client_id
OZON_API_KEY=your_api_key

# Debug (disable in production)
DEBUG=false
LOG_LEVEL=ERROR
```

---

## 🧪 Testing Checklist

### API Testing

```bash
# Test main dashboard endpoint
curl "https://www.market-mi.ru/api/warehouse-dashboard.php"

# Test warehouses list
curl "https://www.market-mi.ru/api/warehouse-dashboard.php?action=warehouses"

# Test clusters list
curl "https://www.market-mi.ru/api/warehouse-dashboard.php?action=clusters"

# Test CSV export
curl "https://www.market-mi.ru/api/warehouse-dashboard.php?action=export" -o test.csv
```

### Frontend Testing

-   ✅ Dashboard loads at https://www.market-mi.ru/warehouse-dashboard
-   ✅ All filters work (warehouse, cluster, liquidity)
-   ✅ Sorting functions correctly
-   ✅ CSV export downloads
-   ✅ Mobile responsive design
-   ✅ Page loads under 3 seconds

### Performance Testing

```bash
# Test page load time
curl -w "@-" -o /dev/null -s "https://www.market-mi.ru/warehouse-dashboard" <<'EOF'
     time_namelookup:  %{time_namelookup}\n
        time_connect:  %{time_connect}\n
     time_appconnect:  %{time_appconnect}\n
    time_pretransfer:  %{time_pretransfer}\n
       time_redirect:  %{time_redirect}\n
  time_starttransfer:  %{time_starttransfer}\n
                     ----------\n
          time_total:  %{time_total}\n
EOF
```

---

## 📊 Monitoring & Maintenance

### Log Files

-   **Nginx:** `/var/log/nginx/market-mi.ru.*.log`
-   **Apache:** `/var/log/apache2/market-mi.ru.*.log`
-   **PHP:** `/var/www/market-mi.ru/logs/`
-   **Application:** Check error logs for API issues

### Automated Tasks

Set up cron job for data refresh:

```bash
# Add to crontab
0 */6 * * * cd /var/www/market-mi.ru && php scripts/refresh_warehouse_metrics.php
```

### Backup Strategy

```bash
# Database backup
pg_dump production_db > backup_$(date +%Y%m%d).sql

# Files backup
tar -czf backup_files_$(date +%Y%m%d).tar.gz /var/www/market-mi.ru/
```

---

## 🚨 Troubleshooting

### Common Issues

**1. API returns 404:**

-   Check web server configuration
-   Verify API files are in correct location
-   Check file permissions

**2. Database connection errors:**

-   Verify database credentials
-   Check PostgreSQL service status
-   Verify database exists and user has permissions

**3. Frontend shows blank page:**

-   Check browser console for errors
-   Verify index.html is in web root
-   Check CORS configuration

**4. Slow performance:**

-   Check database indexes
-   Verify caching is enabled
-   Monitor server resources

### Debug Commands

```bash
# Check web server status
sudo systemctl status nginx  # or apache2

# Check PHP-FPM status
sudo systemctl status php8.1-fpm

# Check database connection
psql -h localhost -U production_user -d production_db -c "SELECT 1;"

# Check file permissions
ls -la /var/www/market-mi.ru/

# Check logs
tail -f /var/log/nginx/market-mi.ru.error.log
```

---

## 📞 Support

### Documentation

-   **User Guide:** `/docs/WAREHOUSE_DASHBOARD_USER_GUIDE.md`
-   **API Documentation:** `/docs/WAREHOUSE_DASHBOARD_API.md`
-   **Technical Specs:** `/.kiro/specs/warehouse-dashboard/`

### Key Files

-   **Main API:** `/api/warehouse-dashboard.php`
-   **Frontend:** `/public/index.html`
-   **Configuration:** `/config/database_postgresql.php`
-   **Deployment Script:** `/deploy_to_production.sh`

---

## ✅ Deployment Checklist

**Pre-deployment:**

-   [ ] All tests pass locally
-   [ ] Code committed and pushed to git
-   [ ] Production build created and tested
-   [ ] Deployment package prepared

**Server Setup:**

-   [ ] Production server access verified
-   [ ] Database configured and migrated
-   [ ] Web server configured (Nginx/Apache)
-   [ ] SSL certificate installed and configured
-   [ ] File permissions set correctly

**Deployment:**

-   [ ] Backend files uploaded and configured
-   [ ] Frontend build uploaded to web root
-   [ ] Environment variables configured
-   [ ] Database connection tested

**Testing:**

-   [ ] Dashboard accessible at https://www.market-mi.ru/warehouse-dashboard
-   [ ] All API endpoints working
-   [ ] All dashboard features functional
-   [ ] Mobile responsiveness verified
-   [ ] Performance meets requirements (<3s load time)

**Post-deployment:**

-   [ ] Monitoring and logging configured
-   [ ] Backup procedures established
-   [ ] Documentation updated
-   [ ] Team notified of go-live

---

## 🎉 Success!

Once all steps are completed, your Warehouse Dashboard will be live at:

**🌐 https://www.market-mi.ru/warehouse-dashboard**

The dashboard provides:

-   ✅ Real-time warehouse inventory tracking
-   ✅ Automated replenishment calculations
-   ✅ Sales analytics and liquidity status
-   ✅ Multi-dimensional filtering
-   ✅ CSV export functionality
-   ✅ Mobile-responsive design
-   ✅ Performance optimized for large datasets

**Happy analyzing! 📊✨**
