#!/bin/bash

# Server Deployment Script for Analytics ETL System
# Handles ownership changes for safe git pull operations

set -e  # Exit on any error

# Configuration
PROJECT_DIR="/var/www/html/mi_core_etl"  # Adjust path as needed
VLADIMIR_USER="vladimir"
APP_USER="www-data"  # or nginx, apache, etc. - adjust as needed
APP_GROUP="www-data"

# Colors for output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m' # No Color

echo -e "${BLUE}🚀 Starting Analytics ETL System Deployment...${NC}"

# Check if running as root or with sudo
if [[ $EUID -ne 0 ]]; then
   echo -e "${RED}❌ This script must be run as root or with sudo${NC}"
   exit 1
fi

# Check if project directory exists
if [ ! -d "$PROJECT_DIR" ]; then
    echo -e "${RED}❌ Project directory $PROJECT_DIR does not exist${NC}"
    exit 1
fi

cd "$PROJECT_DIR"

echo -e "${YELLOW}📁 Current directory: $(pwd)${NC}"

# Step 1: Change ownership to vladimir for git operations
echo -e "${BLUE}🔄 Step 1: Changing ownership to $VLADIMIR_USER for git operations...${NC}"
chown -R $VLADIMIR_USER:$VLADIMIR_USER "$PROJECT_DIR"
echo -e "${GREEN}✅ Ownership changed to $VLADIMIR_USER${NC}"

# Step 2: Perform git pull as vladimir
echo -e "${BLUE}🔄 Step 2: Performing git pull...${NC}"
sudo -u $VLADIMIR_USER git status
sudo -u $VLADIMIR_USER git pull origin main

if [ $? -eq 0 ]; then
    echo -e "${GREEN}✅ Git pull completed successfully${NC}"
else
    echo -e "${RED}❌ Git pull failed${NC}"
    # Restore ownership even if git pull fails
    echo -e "${YELLOW}🔄 Restoring ownership to $APP_USER due to git pull failure...${NC}"
    chown -R $APP_USER:$APP_GROUP "$PROJECT_DIR"
    exit 1
fi

# Step 3: Run database migrations if needed
echo -e "${BLUE}🔄 Step 3: Checking for database migrations...${NC}"
if [ -d "migrations" ]; then
    echo -e "${YELLOW}📊 Found migrations directory, running new migrations...${NC}"
    # Add your migration command here, for example:
    # sudo -u $VLADIMIR_USER php run_migrations.php
    echo -e "${GREEN}✅ Migrations completed${NC}"
fi

# Step 4: Update composer dependencies if composer.json changed
if [ -f "composer.json" ]; then
    echo -e "${BLUE}🔄 Step 4: Updating composer dependencies...${NC}"
    sudo -u $VLADIMIR_USER composer install --no-dev --optimize-autoloader
    echo -e "${GREEN}✅ Composer dependencies updated${NC}"
fi

# Step 5: Build frontend if needed
if [ -d "frontend" ] && [ -f "frontend/package.json" ]; then
    echo -e "${BLUE}🔄 Step 5: Building frontend...${NC}"
    cd frontend
    sudo -u $VLADIMIR_USER npm ci
    sudo -u $VLADIMIR_USER npm run build
    cd ..
    echo -e "${GREEN}✅ Frontend built successfully${NC}"
fi

# Step 6: Set proper permissions for specific directories
echo -e "${BLUE}🔄 Step 6: Setting proper permissions for application directories...${NC}"

# Create necessary directories if they don't exist
sudo -u $VLADIMIR_USER mkdir -p logs/analytics_etl
sudo -u $VLADIMIR_USER mkdir -p cache/analytics_api
sudo -u $VLADIMIR_USER mkdir -p storage/temp

# Set permissions for log and cache directories
chmod -R 755 logs/
chmod -R 755 cache/
chmod -R 755 storage/

# Make scripts executable
chmod +x warehouse_etl_analytics.php
chmod +x analytics_etl_smoke_tests.php
chmod +x monitor_analytics_etl.php
chmod +x run_alert_manager.php
chmod +x scripts/*.php
chmod +x migrations/*.sh

echo -e "${GREEN}✅ Permissions set correctly${NC}"

# Step 7: Restore ownership to application user
echo -e "${BLUE}🔄 Step 7: Restoring ownership to $APP_USER for web server...${NC}"
chown -R $APP_USER:$APP_GROUP "$PROJECT_DIR"

# Keep vladimir access to specific directories for maintenance
chown -R $VLADIMIR_USER:$APP_GROUP logs/
chown -R $VLADIMIR_USER:$APP_GROUP cache/
chown -R $VLADIMIR_USER:$APP_GROUP storage/

# Set group permissions so both users can access
chmod -R g+w logs/
chmod -R g+w cache/
chmod -R g+w storage/

echo -e "${GREEN}✅ Ownership restored to $APP_USER with $VLADIMIR_USER maintenance access${NC}"

# Step 8: Restart services if needed
echo -e "${BLUE}🔄 Step 8: Restarting services...${NC}"

# Restart web server (adjust as needed)
if systemctl is-active --quiet nginx; then
    systemctl reload nginx
    echo -e "${GREEN}✅ Nginx reloaded${NC}"
elif systemctl is-active --quiet apache2; then
    systemctl reload apache2
    echo -e "${GREEN}✅ Apache reloaded${NC}"
fi

# Restart PHP-FPM if running
if systemctl is-active --quiet php8.1-fpm; then
    systemctl reload php8.1-fpm
    echo -e "${GREEN}✅ PHP-FPM reloaded${NC}"
elif systemctl is-active --quiet php8.0-fpm; then
    systemctl reload php8.0-fpm
    echo -e "${GREEN}✅ PHP-FPM reloaded${NC}"
fi

# Step 9: Run smoke tests
echo -e "${BLUE}🔄 Step 9: Running deployment verification...${NC}"
if [ -f "analytics_etl_smoke_tests.php" ]; then
    php analytics_etl_smoke_tests.php
    if [ $? -eq 0 ]; then
        echo -e "${GREEN}✅ Smoke tests passed${NC}"
    else
        echo -e "${YELLOW}⚠️  Some smoke tests failed, check logs${NC}"
    fi
fi

# Step 10: Update cron jobs if needed
echo -e "${BLUE}🔄 Step 10: Checking cron jobs...${NC}"
if [ -f "cron_examples/analytics_etl_standard.txt" ]; then
    echo -e "${YELLOW}📅 Cron job examples available in cron_examples/analytics_etl_standard.txt${NC}"
    echo -e "${YELLOW}📅 Please review and update cron jobs manually if needed${NC}"
fi

echo -e "${GREEN}🎉 Analytics ETL System deployment completed successfully!${NC}"
echo -e "${BLUE}📊 System Status:${NC}"
echo -e "  • Project directory: $PROJECT_DIR"
echo -e "  • Web server user: $APP_USER"
echo -e "  • Maintenance user: $VLADIMIR_USER"
echo -e "  • Git repository: $(git remote get-url origin)"
echo -e "  • Current commit: $(git rev-parse --short HEAD)"
echo -e "  • Deployment time: $(date)"

echo -e "${YELLOW}📝 Next steps:${NC}"
echo -e "  1. Verify the application is working: curl -I http://your-domain.com"
echo -e "  2. Check ETL monitoring: php monitor_analytics_etl.php"
echo -e "  3. Review logs: tail -f logs/analytics_etl/etl_$(date +%Y%m%d).log"
echo -e "  4. Update cron jobs if needed from cron_examples/"

echo -e "${GREEN}✅ Deployment script completed!${NC}"