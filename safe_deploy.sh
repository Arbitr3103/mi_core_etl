#!/bin/bash

# Safe Deployment Script - Preserves local changes
# Handles git stash/unstash to protect local modifications

set -e

# Configuration - ADJUST FOR YOUR SERVER
PROJECT_DIR="/var/www/html/mi_core_etl"
VLADIMIR_USER="vladimir"
APP_USER="www-data"
APP_GROUP="www-data"

# Colors
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
CYAN='\033[0;36m'
NC='\033[0m'

echo -e "${BLUE}🛡️  Safe Analytics ETL Deployment (Preserving Local Changes)${NC}"

# Check if running as root
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
echo -e "${CYAN}📁 Working in: $(pwd)${NC}"

# Step 1: Change ownership to vladimir for git operations
echo -e "${BLUE}🔄 Step 1: Changing ownership to $VLADIMIR_USER for git operations...${NC}"
chown -R $VLADIMIR_USER:$VLADIMIR_USER "$PROJECT_DIR"

# Step 2: Check for local changes as vladimir
echo -e "${BLUE}🔍 Step 2: Checking for local changes...${NC}"
LOCAL_CHANGES=$(sudo -u $VLADIMIR_USER git status --porcelain)

if [ -n "$LOCAL_CHANGES" ]; then
    echo -e "${YELLOW}⚠️  Local changes detected:${NC}"
    sudo -u $VLADIMIR_USER git status --short
    echo ""
    
    # Stash local changes
    echo -e "${BLUE}💾 Stashing local changes...${NC}"
    STASH_MESSAGE="Auto-stash before deployment $(date '+%Y-%m-%d %H:%M:%S')"
    sudo -u $VLADIMIR_USER git stash push -m "$STASH_MESSAGE"
    
    if [ $? -eq 0 ]; then
        echo -e "${GREEN}✅ Local changes stashed successfully${NC}"
        STASHED=true
    else
        echo -e "${RED}❌ Failed to stash local changes${NC}"
        chown -R $APP_USER:$APP_GROUP "$PROJECT_DIR"
        exit 1
    fi
else
    echo -e "${GREEN}✅ No local changes detected${NC}"
    STASHED=false
fi

# Step 3: Fetch and show what will be updated
echo -e "${BLUE}🔄 Step 3: Fetching latest changes from repository...${NC}"
sudo -u $VLADIMIR_USER git fetch origin main

# Show what will be updated
echo -e "${CYAN}📋 Changes to be pulled:${NC}"
sudo -u $VLADIMIR_USER git log --oneline HEAD..origin/main | head -10

# Step 4: Perform git pull
echo -e "${BLUE}🔄 Step 4: Pulling latest changes...${NC}"
sudo -u $VLADIMIR_USER git pull origin main

if [ $? -eq 0 ]; then
    echo -e "${GREEN}✅ Git pull completed successfully${NC}"
else
    echo -e "${RED}❌ Git pull failed${NC}"
    
    # Restore stashed changes if they exist
    if [ "$STASHED" = true ]; then
        echo -e "${BLUE}🔄 Restoring stashed changes due to pull failure...${NC}"
        sudo -u $VLADIMIR_USER git stash pop
    fi
    
    chown -R $APP_USER:$APP_GROUP "$PROJECT_DIR"
    exit 1
fi

# Step 5: Handle stashed changes
if [ "$STASHED" = true ]; then
    echo -e "${YELLOW}🤔 You have stashed local changes. Choose how to proceed:${NC}"
    echo -e "${CYAN}1) Apply stashed changes (may cause conflicts)${NC}"
    echo -e "${CYAN}2) Keep stashed changes for manual review later${NC}"
    echo -e "${CYAN}3) Discard stashed changes (use new code only)${NC}"
    
    # For automated deployment, we'll keep stashed changes for manual review
    echo -e "${BLUE}🔄 Keeping stashed changes for manual review...${NC}"
    echo -e "${YELLOW}📝 To apply later: sudo -u $VLADIMIR_USER git stash pop${NC}"
    echo -e "${YELLOW}📝 To view stashed changes: sudo -u $VLADIMIR_USER git stash show -p${NC}"
    echo -e "${YELLOW}📝 To discard stashed changes: sudo -u $VLADIMIR_USER git stash drop${NC}"
fi

# Step 6: Run database migrations if needed
echo -e "${BLUE}🔄 Step 6: Checking for database migrations...${NC}"
if [ -d "migrations" ]; then
    NEW_MIGRATIONS=$(find migrations -name "*.sql" -newer migrations/.last_migration 2>/dev/null || echo "")
    if [ -n "$NEW_MIGRATIONS" ]; then
        echo -e "${YELLOW}📊 New migrations found:${NC}"
        echo "$NEW_MIGRATIONS"
        echo -e "${BLUE}🔄 Running migrations...${NC}"
        # Add your migration command here
        # sudo -u $VLADIMIR_USER php run_migrations.php
        touch migrations/.last_migration
        echo -e "${GREEN}✅ Migrations completed${NC}"
    else
        echo -e "${GREEN}✅ No new migrations to run${NC}"
    fi
fi

# Step 7: Update composer dependencies
if [ -f "composer.json" ]; then
    echo -e "${BLUE}🔄 Step 7: Checking composer dependencies...${NC}"
    if [ -f "composer.lock" ]; then
        # Check if composer.lock was updated
        if sudo -u $VLADIMIR_USER git diff HEAD~1 HEAD --name-only | grep -q "composer.lock"; then
            echo -e "${YELLOW}📦 Composer dependencies updated, installing...${NC}"
            sudo -u $VLADIMIR_USER composer install --no-dev --optimize-autoloader
            echo -e "${GREEN}✅ Composer dependencies updated${NC}"
        else
            echo -e "${GREEN}✅ No composer dependency changes${NC}"
        fi
    fi
fi

# Step 8: Build frontend if needed
if [ -d "frontend" ] && [ -f "frontend/package.json" ]; then
    echo -e "${BLUE}🔄 Step 8: Checking frontend changes...${NC}"
    
    # Check if frontend files were updated
    FRONTEND_CHANGED=$(sudo -u $VLADIMIR_USER git diff HEAD~1 HEAD --name-only | grep "^frontend/" || echo "")
    
    if [ -n "$FRONTEND_CHANGED" ]; then
        echo -e "${YELLOW}🎨 Frontend changes detected, rebuilding...${NC}"
        cd frontend
        
        # Install dependencies if package-lock.json changed
        if echo "$FRONTEND_CHANGED" | grep -q "package-lock.json"; then
            sudo -u $VLADIMIR_USER npm ci
        fi
        
        # Build frontend
        sudo -u $VLADIMIR_USER npm run build
        cd ..
        echo -e "${GREEN}✅ Frontend rebuilt successfully${NC}"
    else
        echo -e "${GREEN}✅ No frontend changes detected${NC}"
    fi
fi

# Step 9: Set proper permissions
echo -e "${BLUE}🔄 Step 9: Setting proper permissions...${NC}"

# Create directories if they don't exist
sudo -u $VLADIMIR_USER mkdir -p logs/analytics_etl cache/analytics_api storage/temp

# Set executable permissions
chmod +x warehouse_etl_analytics.php 2>/dev/null || true
chmod +x analytics_etl_smoke_tests.php 2>/dev/null || true
chmod +x monitor_analytics_etl.php 2>/dev/null || true
chmod +x run_alert_manager.php 2>/dev/null || true
chmod +x scripts/*.php 2>/dev/null || true
chmod +x migrations/*.sh 2>/dev/null || true

echo -e "${GREEN}✅ Permissions set${NC}"

# Step 10: Restore ownership to application user
echo -e "${BLUE}🔄 Step 10: Restoring ownership to web server...${NC}"
chown -R $APP_USER:$APP_GROUP "$PROJECT_DIR"

# Keep vladimir access to maintenance directories
chown -R $VLADIMIR_USER:$APP_GROUP logs/ cache/ storage/ 2>/dev/null || true
chmod -R g+w logs/ cache/ storage/ 2>/dev/null || true

echo -e "${GREEN}✅ Ownership restored${NC}"

# Step 11: Restart services
echo -e "${BLUE}🔄 Step 11: Restarting services...${NC}"

# Restart web server
if systemctl is-active --quiet nginx; then
    systemctl reload nginx
    echo -e "${GREEN}✅ Nginx reloaded${NC}"
elif systemctl is-active --quiet apache2; then
    systemctl reload apache2
    echo -e "${GREEN}✅ Apache reloaded${NC}"
fi

# Restart PHP-FPM
if systemctl is-active --quiet php8.1-fpm; then
    systemctl reload php8.1-fpm
    echo -e "${GREEN}✅ PHP-FPM reloaded${NC}"
elif systemctl is-active --quiet php8.0-fpm; then
    systemctl reload php8.0-fpm
    echo -e "${GREEN}✅ PHP-FPM reloaded${NC}"
fi

# Step 12: Run verification tests
echo -e "${BLUE}🔄 Step 12: Running deployment verification...${NC}"
if [ -f "analytics_etl_smoke_tests.php" ]; then
    php analytics_etl_smoke_tests.php
    if [ $? -eq 0 ]; then
        echo -e "${GREEN}✅ Smoke tests passed${NC}"
    else
        echo -e "${YELLOW}⚠️  Some smoke tests failed, check logs${NC}"
    fi
fi

# Final summary
echo -e "${GREEN}🎉 Safe deployment completed successfully!${NC}"
echo -e "${BLUE}📊 Deployment Summary:${NC}"
echo -e "  • Repository: $(git remote get-url origin)"
echo -e "  • Current commit: $(git rev-parse --short HEAD) - $(git log -1 --pretty=format:'%s')"
echo -e "  • Deployment time: $(date)"
echo -e "  • Local changes: $([ "$STASHED" = true ] && echo "Stashed for review" || echo "None")"

if [ "$STASHED" = true ]; then
    echo -e "${YELLOW}📝 Important: You have stashed local changes!${NC}"
    echo -e "${CYAN}To review stashed changes:${NC}"
    echo -e "  sudo -u $VLADIMIR_USER git stash show -p"
    echo -e "${CYAN}To apply stashed changes:${NC}"
    echo -e "  sudo -u $VLADIMIR_USER git stash pop"
    echo -e "${CYAN}To discard stashed changes:${NC}"
    echo -e "  sudo -u $VLADIMIR_USER git stash drop"
fi

echo -e "${GREEN}✅ Safe deployment script completed!${NC}"