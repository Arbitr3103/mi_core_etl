# Nginx Configuration Files

This directory contains nginx configuration files for the MDM system.

## Files

### `default`

Main server configuration that handles:

- All dashboards (quality_dashboard.php, data_quality_dashboard.php, etc.)
- All API endpoints (/api/\*)
- Monitoring interfaces
- Serves from: `/var/www/html/`
- Responds to: `178.72.129.61`, `api.zavodprostavok.ru`, `zavodprostavok.ru`

### `mi_core_api`

Country Filter API configuration:

- Separate subdomain for country filter system
- Serves from: `/var/www/mi_core_api/src/`
- Responds to: `filter.zavodprostavok.ru`

## Deployment

Use the deployment script to apply these configurations:

```bash
./deploy_nginx_config.sh
```

Or manually:

```bash
# Backup existing configs
sudo cp /etc/nginx/sites-available/default /etc/nginx/sites-available/default.backup
sudo cp /etc/nginx/sites-available/mi_core_api /etc/nginx/sites-available/mi_core_api.backup

# Copy new configs
sudo cp nginx/default /etc/nginx/sites-available/default
sudo cp nginx/mi_core_api /etc/nginx/sites-available/mi_core_api

# Test configuration
sudo nginx -t

# Reload nginx
sudo systemctl reload nginx
```

## DNS Configuration

Make sure DNS is configured:

- `api.zavodprostavok.ru` → `178.72.129.61`
- `filter.zavodprostavok.ru` → `178.72.129.61`
- `zavodprostavok.ru` → `178.72.129.61`

## Testing

After deployment:

```bash
# Test main site
curl http://178.72.129.61/quality_dashboard.php

# Test API
curl http://178.72.129.61/api/quality-metrics.php

# Test country filter (if DNS configured)
curl http://filter.zavodprostavok.ru/api/countries.php
```
