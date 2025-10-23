# React Frontend Deployment Guide

This guide covers the deployment process for the React frontend application.

## Prerequisites

-   Node.js 18+ and npm 9+
-   SSH access to the production server
-   Nginx installed on the server
-   Git (for version tracking)

## Quick Start

### 1. Build the Application

```bash
# From the frontend directory
cd frontend
./build.sh
```

This will:

-   Install dependencies (if needed)
-   Run type checking
-   Run linter
-   Run tests
-   Build for production
-   Generate build statistics

### 2. Deploy to Server

```bash
# From the frontend directory
./deploy.sh
```

Or set custom deployment parameters:

```bash
DEPLOY_USER=root \
DEPLOY_HOST=178.72.129.61 \
DEPLOY_PATH=/var/www/mi_core_etl/public/dashboard \
./deploy.sh
```

## Detailed Deployment Steps

### Step 1: Setup Nginx (First Time Only)

On the server, run:

```bash
cd /var/www/mi_core_etl
sudo bash deployment/scripts/setup_nginx.sh
```

This will:

-   Install Nginx (if not installed)
-   Configure Nginx for the React app
-   Set up API proxying
-   Enable gzip compression
-   Configure caching headers
-   Create required directories

### Step 2: Build Configuration

The build process uses environment variables from `.env.production`:

```env
VITE_API_BASE_URL=/api
VITE_API_TIMEOUT=30000
VITE_ENABLE_API_LOGGING=false
```

### Step 3: Build Optimization

The production build includes:

-   **Code Splitting**: Separate chunks for vendors and features
-   **Minification**: Terser minification with console.log removal
-   **Tree Shaking**: Unused code elimination
-   **Lazy Loading**: Components loaded on demand
-   **Asset Optimization**: Optimized images and fonts
-   **Gzip Compression**: Enabled on server

### Step 4: Deployment Process

The deployment script:

1. Builds the application
2. Creates a backup on the server
3. Uploads files via rsync
4. Sets proper permissions
5. Verifies deployment

### Step 5: Verification

After deployment, verify:

```bash
# Check if files are deployed
ssh root@178.72.129.61 "ls -la /var/www/mi_core_etl/public/dashboard"

# Check Nginx status
ssh root@178.72.129.61 "systemctl status nginx"

# Test the application
curl http://178.72.129.61/dashboard/
```

## Nginx Configuration

The Nginx configuration (`deployment/configs/nginx-react.conf`) includes:

### Static File Serving

```nginx
location / {
    try_files $uri $uri/ /index.html;
}
```

### API Proxying

```nginx
location /api/ {
    proxy_pass http://localhost:8080/api/;
    proxy_set_header Host $host;
    proxy_set_header X-Real-IP $remote_addr;
}
```

### Caching

```nginx
# Cache static assets for 1 year
location ~* \.(js|css|png|jpg|jpeg|gif|ico|svg|woff|woff2|ttf|eot)$ {
    expires 1y;
    add_header Cache-Control "public, immutable";
}

# No cache for index.html
location / {
    add_header Cache-Control "no-cache, no-store, must-revalidate";
}
```

### Compression

```nginx
gzip on;
gzip_types text/plain text/css application/javascript application/json;
```

## Performance Optimization

### Bundle Analysis

Analyze bundle size:

```bash
npm run build:analyze
```

### Performance Monitoring

The application includes performance monitoring utilities:

```typescript
import { logPerformanceMetrics } from "@/utils/performance";

// Log performance metrics in development
if (process.env.NODE_ENV === "development") {
    logPerformanceMetrics();
}
```

### Optimization Features

1. **React.memo**: Prevents unnecessary re-renders
2. **Lazy Loading**: Components loaded on demand
3. **Code Splitting**: Separate chunks for better caching
4. **Virtualization**: Efficient rendering of large lists
5. **API Caching**: 5-minute cache for API responses

## Rollback Procedure

If deployment fails or issues are found:

```bash
# On the server
cd /var/www/mi_core_etl/backups/frontend

# List available backups
ls -la

# Restore a backup
cp -r frontend_backup_YYYYMMDD_HHMMSS/* /var/www/mi_core_etl/public/dashboard/

# Reload Nginx
sudo systemctl reload nginx
```

## Monitoring

### Application Health

Check application health:

```bash
curl http://178.72.129.61/health
```

### Nginx Logs

Monitor Nginx logs:

```bash
# Access logs
tail -f /var/log/nginx/react_access.log

# Error logs
tail -f /var/log/nginx/react_error.log
```

### Build Info

Check build information:

```bash
curl http://178.72.129.61/dashboard/build-info.json
```

## Troubleshooting

### Build Fails

```bash
# Clean and rebuild
rm -rf node_modules dist
npm install
npm run build
```

### Deployment Fails

```bash
# Check SSH connection
ssh root@178.72.129.61 "echo 'Connection OK'"

# Check server disk space
ssh root@178.72.129.61 "df -h"

# Check permissions
ssh root@178.72.129.61 "ls -la /var/www/mi_core_etl/public/dashboard"
```

### Application Not Loading

1. Check Nginx configuration:

    ```bash
    sudo nginx -t
    ```

2. Check Nginx status:

    ```bash
    sudo systemctl status nginx
    ```

3. Check browser console for errors

4. Verify API endpoint is accessible:
    ```bash
    curl http://localhost:8080/api/inventory-v4.php
    ```

### API Requests Failing

1. Check API proxy configuration in Nginx
2. Verify backend is running on port 8080
3. Check CORS headers
4. Review Nginx error logs

## Security Considerations

### Environment Variables

Never commit `.env` files with sensitive data. Use `.env.example` as a template.

### HTTPS Configuration

For production with SSL, update Nginx configuration:

```nginx
server {
    listen 443 ssl http2;
    ssl_certificate /etc/ssl/certs/your-cert.crt;
    ssl_certificate_key /etc/ssl/private/your-key.key;
    # ... rest of configuration
}
```

### Security Headers

The Nginx configuration includes security headers:

```nginx
add_header X-Frame-Options "SAMEORIGIN" always;
add_header X-Content-Type-Options "nosniff" always;
add_header X-XSS-Protection "1; mode=block" always;
```

## Continuous Deployment

### Automated Deployment

Set up automated deployment with Git hooks or CI/CD:

```bash
# .git/hooks/post-receive
#!/bin/bash
cd /var/www/mi_core_etl
git pull origin main
cd frontend
./build.sh
./deploy.sh
```

### CI/CD Integration

Example GitHub Actions workflow:

```yaml
name: Deploy Frontend
on:
    push:
        branches: [main]
jobs:
    deploy:
        runs-on: ubuntu-latest
        steps:
            - uses: actions/checkout@v2
            - name: Setup Node.js
              uses: actions/setup-node@v2
              with:
                  node-version: "18"
            - name: Build
              run: cd frontend && npm install && npm run build
            - name: Deploy
              run: cd frontend && ./deploy.sh
              env:
                  DEPLOY_USER: ${{ secrets.DEPLOY_USER }}
                  DEPLOY_HOST: ${{ secrets.DEPLOY_HOST }}
```

## Best Practices

1. **Always test locally** before deploying
2. **Create backups** before each deployment
3. **Monitor logs** after deployment
4. **Use version tags** for tracking deployments
5. **Document changes** in commit messages
6. **Test rollback procedure** regularly
7. **Keep dependencies updated** for security
8. **Monitor performance** metrics
9. **Use staging environment** for testing
10. **Automate deployment** process

## Support

For issues or questions:

-   Check logs: `/var/log/nginx/react_*.log`
-   Review build output
-   Check server resources
-   Contact development team

## Additional Resources

-   [Vite Documentation](https://vitejs.dev/)
-   [React Documentation](https://react.dev/)
-   [Nginx Documentation](https://nginx.org/en/docs/)
-   [TanStack Query Documentation](https://tanstack.com/query/latest)
