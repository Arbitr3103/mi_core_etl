#!/bin/bash

# Local Deployment Script
# Deploys files from /var/www/mi_core_api to /var/www/html

set -e

echo "🚀 Deploying MDM files to /var/www/html..."

# Colors
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
RED='\033[0;31m'
NC='\033[0m'

# Check if running as root or with sudo
if [ "$EUID" -ne 0 ]; then 
    echo -e "${RED}❌ Please run with sudo${NC}"
    exit 1
fi

# Step 1: Deploy HTML files
echo -e "${YELLOW}📄 Deploying HTML files...${NC}"
cp /var/www/mi_core_api/html/*.php /var/www/html/
echo "✓ HTML files copied"

# Step 2: Deploy API files
echo -e "${YELLOW}📦 Deploying API files...${NC}"

# Check if /var/www/html/api is a file (wrong) and remove it
if [ -f /var/www/html/api ] && [ ! -d /var/www/html/api ]; then
    echo "⚠️  Found file 'api' instead of directory, removing..."
    rm /var/www/html/api
fi

# Create api directory if it doesn't exist
if [ ! -d /var/www/html/api ]; then
    echo "Creating /var/www/html/api directory..."
    mkdir -p /var/www/html/api
fi

# Copy API files
cp /var/www/mi_core_api/api/*.php /var/www/html/api/
echo "✓ API files copied"

# Step 3: Deploy widgets
echo -e "${YELLOW}🎨 Deploying widgets...${NC}"
if [ -d /var/www/mi_core_api/html/widgets ]; then
    mkdir -p /var/www/html/widgets
    cp /var/www/mi_core_api/html/widgets/*.php /var/www/html/widgets/ 2>/dev/null || true
    echo "✓ Widgets copied"
else
    echo "⚠️  No widgets directory found, skipping"
fi

# Step 4: Set permissions
echo -e "${YELLOW}🔐 Setting permissions...${NC}"
chown -R www-data:www-data /var/www/html/
chmod -R 755 /var/www/html/
chmod 644 /var/www/html/*.php
chmod 644 /var/www/html/api/*.php
echo "✓ Permissions set"

# Step 5: Verify deployment
echo -e "${YELLOW}🔍 Verifying deployment...${NC}"
echo ""
echo "HTML files:"
ls -lh /var/www/html/*.php | grep -E "(quality_dashboard|data_quality_dashboard|sync_monitor)" || echo "  No dashboard files found"
echo ""
echo "API files:"
ls -lh /var/www/html/api/*.php | grep -E "(quality-metrics|sync-monitor|data-quality)" || echo "  No API files found"
echo ""

# Summary
echo -e "${GREEN}✅ Deployment complete!${NC}"
echo ""
echo "Test your deployment:"
echo "  curl -I http://178.72.129.61/quality_dashboard.php"
echo "  curl http://178.72.129.61/api/quality-metrics.php"
echo ""
echo "Open in browser:"
echo "  http://178.72.129.61/quality_dashboard.php"
echo "  http://178.72.129.61/data_quality_dashboard.php"
