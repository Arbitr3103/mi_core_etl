# Quick Deployment Reference

## One-Command Deployment

```bash
cd frontend && ./build.sh && ./deploy.sh
```

## From Project Root

```bash
npm run deploy:frontend
```

## Custom Server

```bash
DEPLOY_USER=myuser \
DEPLOY_HOST=192.168.1.100 \
DEPLOY_PATH=/var/www/app \
cd frontend && ./deploy.sh
```

## First Time Setup

```bash
# 1. Setup Nginx on server
sudo bash deployment/scripts/setup_nginx.sh

# 2. Deploy application
cd frontend && ./deploy.sh
```

## Verify Deployment

```bash
# Check files
ssh root@178.72.129.61 "ls -la /var/www/mi_core_etl/public/dashboard"

# Test application
curl http://178.72.129.61/dashboard/

# Check build info
curl http://178.72.129.61/dashboard/build-info.json
```

## Rollback

```bash
# List backups
ssh root@178.72.129.61 "ls -la /var/www/mi_core_etl/backups/frontend"

# Restore backup
ssh root@178.72.129.61 "cp -r /var/www/mi_core_etl/backups/frontend/frontend_backup_YYYYMMDD_HHMMSS/* /var/www/mi_core_etl/public/dashboard/"

# Reload Nginx
ssh root@178.72.129.61 "sudo systemctl reload nginx"
```

## Troubleshooting

```bash
# Check Nginx
ssh root@178.72.129.61 "sudo nginx -t && sudo systemctl status nginx"

# View logs
ssh root@178.72.129.61 "tail -f /var/log/nginx/react_error.log"

# Restart Nginx
ssh root@178.72.129.61 "sudo systemctl restart nginx"
```

## Build Only (No Deploy)

```bash
cd frontend && ./build.sh
```

## Environment Variables

Create `.env` file in frontend directory:

```env
VITE_API_BASE_URL=/api
VITE_API_TARGET=http://localhost:8080
```

## Performance Check

```bash
# Analyze bundle
npm run build:analyze

# Check build size
du -sh frontend/dist
```
