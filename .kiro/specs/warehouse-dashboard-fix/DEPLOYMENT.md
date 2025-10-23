# Warehouse Dashboard Layout Fix - Deployment Guide

## Overview

This guide provides complete instructions for building and deploying the Warehouse Dashboard layout fixes to production.

## Prerequisites

-   Node.js 18+ installed
-   npm 9+ installed
-   Access to production server
-   SSH access or FTP access to deployment location

## Build Process

### 1. Navigate to Frontend Directory

```bash
cd frontend
```

### 2. Install Dependencies (if needed)

```bash
npm install
```

### 3. Run Production Build

```bash
npm run build
```

**Expected Output:**

```
vite v4.x.x building for production...
✓ 1234 modules transformed.
dist/index.html                   1.23 kB
dist/assets/index-abc123.js      234.56 kB │ gzip: 78.90 kB
dist/assets/index-def456.css      12.34 kB │ gzip: 3.45 kB
✓ built in 12.34s
```

### 4. Verify Build Output

```bash
ls -lh dist/
```

**Check for:**

-   `index.html` exists
-   `assets/` directory exists
-   CSS files are present and not 0 bytes
-   JS files are present

### 5. Test Build Locally (Optional but Recommended)

```bash
cd dist
python3 -m http.server 8080
```

Then visit: `http://localhost:8080/warehouse-dashboard`

**Verify:**

-   Page loads without errors
-   Layout displays correctly
-   No console errors in browser DevTools
-   All functionality works

Press `Ctrl+C` to stop the local server.

## Deployment Steps

### Option A: Manual Deployment via SSH

#### 1. Create Backup of Current Production

```bash
ssh user@market-mi.ru
cd /var/www/html/warehouse-dashboard
tar -czf backup-$(date +%Y%m%d-%H%M%S).tar.gz *
mv backup-*.tar.gz ~/backups/
```

#### 2. Upload New Build

From your local machine:

```bash
cd frontend/dist
rsync -avz --delete ./ user@market-mi.ru:/var/www/html/warehouse-dashboard/
```

#### 3. Verify File Permissions

```bash
ssh user@market-mi.ru
cd /var/www/html/warehouse-dashboard
chmod -R 755 .
chown -R www-data:www-data .
```

### Option B: Manual Deployment via FTP

#### 1. Connect to Server

Use your FTP client (FileZilla, WinSCP, etc.) and connect to:

-   Host: `market-mi.ru`
-   Port: `21` (or `22` for SFTP)
-   Username: Your FTP username
-   Password: Your FTP password

#### 2. Navigate to Deployment Directory

```
/var/www/html/warehouse-dashboard/
```

#### 3. Backup Current Files

Download all current files to a local backup folder before proceeding.

#### 4. Upload New Files

Upload all files from `frontend/dist/` to the deployment directory.

**Important:** Ensure you upload:

-   `index.html`
-   `assets/` directory (all files)
-   Any other generated files

### Option C: Automated Deployment Script

Create a deployment script `deploy.sh`:

```bash
#!/bin/bash

# Configuration
REMOTE_USER="your-username"
REMOTE_HOST="market-mi.ru"
REMOTE_PATH="/var/www/html/warehouse-dashboard"
LOCAL_BUILD_PATH="frontend/dist"

# Colors for output
GREEN='\033[0;32m'
RED='\033[0;31m'
NC='\033[0m' # No Color

echo "Starting deployment process..."

# Step 1: Build
echo "Building frontend..."
cd frontend
npm run build

if [ $? -ne 0 ]; then
    echo -e "${RED}Build failed!${NC}"
    exit 1
fi

echo -e "${GREEN}Build successful!${NC}"

# Step 2: Create backup on server
echo "Creating backup on server..."
ssh ${REMOTE_USER}@${REMOTE_HOST} "cd ${REMOTE_PATH} && tar -czf ~/backups/backup-\$(date +%Y%m%d-%H%M%S).tar.gz *"

if [ $? -ne 0 ]; then
    echo -e "${RED}Backup failed!${NC}"
    exit 1
fi

echo -e "${GREEN}Backup created!${NC}"

# Step 3: Deploy
echo "Deploying to production..."
rsync -avz --delete ${LOCAL_BUILD_PATH}/ ${REMOTE_USER}@${REMOTE_HOST}:${REMOTE_PATH}/

if [ $? -ne 0 ]; then
    echo -e "${RED}Deployment failed!${NC}"
    exit 1
fi

echo -e "${GREEN}Deployment successful!${NC}"

# Step 4: Set permissions
echo "Setting permissions..."
ssh ${REMOTE_USER}@${REMOTE_HOST} "cd ${REMOTE_PATH} && chmod -R 755 . && chown -R www-data:www-data ."

echo -e "${GREEN}Deployment complete!${NC}"
echo "Please verify at: https://www.market-mi.ru/warehouse-dashboard"
```

Make it executable and run:

```bash
chmod +x deploy.sh
./deploy.sh
```

## Post-Deployment Verification

### 1. Clear Browser Cache

**Hard Refresh:**

-   Chrome/Edge/Firefox: `Ctrl+Shift+R` (Windows/Linux) or `Cmd+Shift+R` (Mac)
-   Safari: `Cmd+Option+R`

**Or use Incognito/Private mode:**

-   Chrome/Edge: `Ctrl+Shift+N`
-   Firefox: `Ctrl+Shift+P`
-   Safari: `Cmd+Shift+N`

### 2. Visual Verification Checklist

Visit: `https://www.market-mi.ru/warehouse-dashboard`

-   [ ] Page loads without errors
-   [ ] Header displays at top without overlap
-   [ ] Navigation displays below header
-   [ ] Dashboard metrics display in grid (4 columns on desktop)
-   [ ] Filters display in organized layout (4 columns on desktop)
-   [ ] Table displays with proper columns
-   [ ] Table scrolls horizontally on small screens
-   [ ] Pagination displays at bottom
-   [ ] No elements overlap
-   [ ] All text is readable

### 3. Functional Testing

-   [ ] Filter controls work (dropdowns, checkboxes)
-   [ ] Table sorting works (click column headers)
-   [ ] Pagination works (next/previous buttons)
-   [ ] CSV export works (download button)
-   [ ] Data refresh works (refresh button)

### 4. Responsive Testing

Test at different screen sizes:

**Desktop (1920px):**

-   [ ] 4-column grid for metrics
-   [ ] 4-column grid for filters
-   [ ] All table columns visible

**Tablet (768px):**

-   [ ] 2-column grid for metrics
-   [ ] 2-column grid for filters
-   [ ] Table scrolls horizontally

**Mobile (375px):**

-   [ ] 1-column layout for metrics
-   [ ] 1-column layout for filters
-   [ ] Table scrolls horizontally
-   [ ] Touch interactions work

### 5. Browser Testing

Test in multiple browsers:

-   [ ] Chrome/Edge (latest)
-   [ ] Firefox (latest)
-   [ ] Safari (latest, if available)
-   [ ] Mobile browsers (iOS Safari, Chrome Android)

### 6. Console Verification

Open browser DevTools (F12) and check:

-   [ ] No JavaScript errors in Console tab
-   [ ] No 404 errors in Network tab
-   [ ] CSS files load successfully (200 status)
-   [ ] JS files load successfully (200 status)

### 7. Performance Check

In DevTools Network tab:

-   [ ] Page load time < 3 seconds
-   [ ] CSS file size reasonable (10-20 KB)
-   [ ] JS file size reasonable (200-300 KB)
-   [ ] No layout shifts (check Lighthouse)

## Rollback Procedure

If issues are discovered after deployment:

### Quick Rollback

```bash
ssh user@market-mi.ru
cd /var/www/html/warehouse-dashboard
rm -rf *
tar -xzf ~/backups/backup-YYYYMMDD-HHMMSS.tar.gz
```

Replace `YYYYMMDD-HHMMSS` with the timestamp of your backup.

### Verify Rollback

1. Clear browser cache
2. Visit the site
3. Verify old version is restored
4. Check functionality

## Troubleshooting

### Issue: Page Shows Old Layout

**Cause:** Browser cache not cleared

**Solution:**

1. Hard refresh: `Ctrl+Shift+R`
2. Clear browser cache completely
3. Try incognito/private mode
4. Check if CDN cache needs clearing

### Issue: CSS Not Loading (Blank Page)

**Cause:** CSS file path incorrect or file missing

**Solution:**

1. Check browser DevTools Network tab for 404 errors
2. Verify CSS files exist in `assets/` directory
3. Check file permissions: `chmod 644 assets/*.css`
4. Verify base URL in `index.html`

### Issue: JavaScript Errors

**Cause:** JS file path incorrect or build incomplete

**Solution:**

1. Check browser Console for specific errors
2. Verify JS files exist in `assets/` directory
3. Rebuild: `npm run build`
4. Redeploy

### Issue: Layout Still Broken

**Cause:** Tailwind CSS not included in build

**Solution:**

1. Verify `tailwind.config.js` exists
2. Verify `postcss.config.js` exists
3. Check `index.css` has Tailwind directives:
    ```css
    @tailwind base;
    @tailwind components;
    @tailwind utilities;
    ```
4. Rebuild: `npm run build`
5. Check CSS file size (should be 10-20 KB, not 0 bytes)

### Issue: 404 Errors for Assets

**Cause:** Base URL configuration incorrect

**Solution:**

1. Check `vite.config.ts` has correct `base` setting:
    ```typescript
    export default defineConfig({
        base: "/warehouse-dashboard/",
        // ...
    });
    ```
2. Rebuild and redeploy

### Issue: Responsive Layout Not Working

**Cause:** Viewport meta tag missing

**Solution:**

1. Check `index.html` has:
    ```html
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    ```
2. Redeploy if missing

### Issue: Slow Performance

**Cause:** Large bundle size or unoptimized build

**Solution:**

1. Check bundle size in build output
2. Verify production build was used (not dev build)
3. Enable gzip compression on server
4. Consider code splitting if bundle > 500 KB

## Server Configuration

### Nginx Configuration

Ensure your Nginx config has proper settings:

```nginx
server {
    listen 80;
    server_name market-mi.ru www.market-mi.ru;

    root /var/www/html;
    index index.html;

    # Warehouse Dashboard
    location /warehouse-dashboard {
        alias /var/www/html/warehouse-dashboard;
        try_files $uri $uri/ /warehouse-dashboard/index.html;

        # Cache static assets
        location ~* \.(js|css|png|jpg|jpeg|gif|ico|svg)$ {
            expires 1y;
            add_header Cache-Control "public, immutable";
        }
    }

    # API endpoints
    location /api {
        # Your PHP-FPM configuration
    }
}
```

### Apache Configuration

If using Apache, ensure `.htaccess` has:

```apache
<IfModule mod_rewrite.c>
    RewriteEngine On
    RewriteBase /warehouse-dashboard/
    RewriteRule ^index\.html$ - [L]
    RewriteCond %{REQUEST_FILENAME} !-f
    RewriteCond %{REQUEST_FILENAME} !-d
    RewriteRule . /warehouse-dashboard/index.html [L]
</IfModule>

# Cache static assets
<IfModule mod_expires.c>
    ExpiresActive On
    ExpiresByType text/css "access plus 1 year"
    ExpiresByType application/javascript "access plus 1 year"
    ExpiresByType image/png "access plus 1 year"
    ExpiresByType image/jpeg "access plus 1 year"
    ExpiresByType image/svg+xml "access plus 1 year"
</IfModule>
```

## Monitoring

### Post-Deployment Monitoring

Monitor for 24-48 hours after deployment:

1. **Error Logs:**

    ```bash
    ssh user@market-mi.ru
    tail -f /var/log/nginx/error.log
    ```

2. **Access Logs:**

    ```bash
    tail -f /var/log/nginx/access.log | grep warehouse-dashboard
    ```

3. **User Feedback:**
    - Check for user reports
    - Monitor support channels
    - Review analytics for unusual patterns

### Health Check Script

Create `health-check.sh`:

```bash
#!/bin/bash

URL="https://www.market-mi.ru/warehouse-dashboard"

# Check if page loads
HTTP_CODE=$(curl -s -o /dev/null -w "%{http_code}" $URL)

if [ $HTTP_CODE -eq 200 ]; then
    echo "✓ Page loads successfully (HTTP $HTTP_CODE)"
else
    echo "✗ Page load failed (HTTP $HTTP_CODE)"
    exit 1
fi

# Check if CSS loads
CSS_URL=$(curl -s $URL | grep -oP 'href="[^"]*\.css"' | head -1 | sed 's/href="//;s/"//')
if [ ! -z "$CSS_URL" ]; then
    CSS_CODE=$(curl -s -o /dev/null -w "%{http_code}" "https://www.market-mi.ru$CSS_URL")
    if [ $CSS_CODE -eq 200 ]; then
        echo "✓ CSS loads successfully (HTTP $CSS_CODE)"
    else
        echo "✗ CSS load failed (HTTP $CSS_CODE)"
        exit 1
    fi
fi

echo "✓ All health checks passed"
```

## Maintenance

### Regular Maintenance Tasks

1. **Weekly:**

    - Check error logs
    - Verify page loads correctly
    - Test core functionality

2. **Monthly:**

    - Update dependencies: `npm update`
    - Rebuild and test
    - Review performance metrics

3. **Quarterly:**
    - Full security audit
    - Update Node.js if needed
    - Review and optimize bundle size

### Backup Strategy

1. **Before Each Deployment:**

    - Create full backup of current production
    - Store in `~/backups/` with timestamp

2. **Weekly Automated Backups:**

    ```bash
    # Add to crontab
    0 2 * * 0 cd /var/www/html/warehouse-dashboard && tar -czf ~/backups/weekly-backup-$(date +\%Y\%m\%d).tar.gz *
    ```

3. **Retention Policy:**
    - Keep last 5 deployment backups
    - Keep last 4 weekly backups
    - Keep last 3 monthly backups

## Support

### Getting Help

If you encounter issues:

1. Check this troubleshooting guide
2. Review browser console for errors
3. Check server logs
4. Review recent changes in git history
5. Contact development team

### Useful Commands

```bash
# Check Node.js version
node --version

# Check npm version
npm --version

# Clean install dependencies
rm -rf node_modules package-lock.json
npm install

# Clean build
rm -rf dist
npm run build

# Check disk space
df -h

# Check file permissions
ls -la /var/www/html/warehouse-dashboard/

# View recent deployments
ls -lt ~/backups/
```

## Changelog

### Version 1.1.0 (Current)

-   Fixed layout overlap issues
-   Improved responsive design
-   Removed diagnostic logging
-   Enhanced table scrolling
-   Updated z-index hierarchy

### Version 1.0.0 (Initial)

-   Initial production deployment
-   Basic dashboard functionality
-   API integration

## Additional Resources

-   [Vite Documentation](https://vitejs.dev/)
-   [Tailwind CSS Documentation](https://tailwindcss.com/)
-   [React Documentation](https://react.dev/)
-   [Nginx Documentation](https://nginx.org/en/docs/)

---

**Last Updated:** 2025-10-23  
**Maintained By:** Development Team  
**Version:** 1.1.0
