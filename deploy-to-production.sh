#!/bin/bash
# Production Deployment Script for Inventory Table Feature
# Date: 2025-10-27
# Description: Deploys the new inventory table to production server

set -e  # Exit on error

echo "🚀 Starting Production Deployment..."
echo "=================================="

# Configuration
DEPLOY_DIR="/var/www/market-mi.ru"
BACKUP_DIR="/var/www/backups/market-mi-$(date +%Y%m%d-%H%M%S)"
FRONTEND_BUILD_DIR="$DEPLOY_DIR/frontend/dist"

# Step 1: Create backup
echo ""
echo "📦 Step 1: Creating backup..."
sudo mkdir -p /var/www/backups
sudo cp -r $DEPLOY_DIR $BACKUP_DIR
echo "✅ Backup created at: $BACKUP_DIR"

# Step 2: Stash local changes
echo ""
echo "💾 Step 2: Stashing local changes..."
cd $DEPLOY_DIR
sudo git stash save "backup-before-table-deployment-$(date +%Y%m%d-%H%M%S)"
echo "✅ Local changes stashed"

# Step 3: Pull latest code
echo ""
echo "⬇️  Step 3: Pulling latest code from GitHub..."
sudo git fetch origin main
sudo git reset --hard origin/main
echo "✅ Code updated to latest version"

# Step 4: Install frontend dependencies
echo ""
echo "📦 Step 4: Installing frontend dependencies..."
cd $DEPLOY_DIR/frontend
sudo npm install --production
echo "✅ Dependencies installed"

# Step 5: Build frontend
echo ""
echo "🔨 Step 5: Building frontend..."
sudo npm run build
echo "✅ Frontend built successfully"

# Step 6: Set correct permissions
echo ""
echo "🔐 Step 6: Setting permissions..."
sudo chown -R www-data:www-data $DEPLOY_DIR
sudo chmod -R 755 $DEPLOY_DIR
sudo chmod -R 775 $DEPLOY_DIR/frontend/dist
echo "✅ Permissions set"

# Step 7: Clear cache
echo ""
echo "🧹 Step 7: Clearing cache..."
if [ -d "$DEPLOY_DIR/cache" ]; then
    sudo rm -rf $DEPLOY_DIR/cache/*
fi
echo "✅ Cache cleared"

# Step 8: Test API endpoints
echo ""
echo "🧪 Step 8: Testing API endpoints..."
API_TEST=$(curl -s -o /dev/null -w "%{http_code}" http://localhost/api/inventory/detailed-stock?limit=1)
if [ "$API_TEST" = "200" ]; then
    echo "✅ API test passed (HTTP $API_TEST)"
else
    echo "⚠️  API test returned HTTP $API_TEST"
fi

# Step 9: Display deployment info
echo ""
echo "=================================="
echo "✅ Deployment Complete!"
echo "=================================="
echo ""
echo "📊 Deployment Summary:"
echo "  - Backup location: $BACKUP_DIR"
echo "  - Frontend build: $FRONTEND_BUILD_DIR"
echo "  - API endpoint: https://www.market-mi.ru/api/inventory/detailed-stock"
echo "  - Dashboard URL: https://www.market-mi.ru/"
echo ""
echo "🔍 Next Steps:"
echo "  1. Open https://www.market-mi.ru/ in browser"
echo "  2. Check that table displays correctly"
echo "  3. Test filtering and sorting"
echo "  4. Verify data accuracy"
echo ""
echo "🔄 To rollback if needed:"
echo "  sudo cp -r $BACKUP_DIR/* $DEPLOY_DIR/"
echo ""
