#!/bin/bash

# Quick Update Script - Simple git pull with ownership management
# For frequent updates without full deployment process

set -e

# Configuration - ADJUST THESE PATHS FOR YOUR SERVER
PROJECT_DIR="/var/www/html/mi_core_etl"
VLADIMIR_USER="vladimir"
APP_USER="www-data"
APP_GROUP="www-data"

# Colors
GREEN='\033[0;32m'
BLUE='\033[0;34m'
RED='\033[0;31m'
NC='\033[0m'

echo -e "${BLUE}🔄 Quick Analytics ETL Update...${NC}"

# Check if running as root
if [[ $EUID -ne 0 ]]; then
   echo -e "${RED}❌ Run with sudo: sudo ./quick_update.sh${NC}"
   exit 1
fi

cd "$PROJECT_DIR"

# Step 1: Change to vladimir for git
echo -e "${BLUE}🔄 Changing ownership for git pull...${NC}"
chown -R $VLADIMIR_USER:$VLADIMIR_USER "$PROJECT_DIR"

# Step 2: Git pull
echo -e "${BLUE}🔄 Pulling latest changes...${NC}"
sudo -u $VLADIMIR_USER git pull origin main

# Step 3: Restore to app user
echo -e "${BLUE}🔄 Restoring ownership to web server...${NC}"
chown -R $APP_USER:$APP_GROUP "$PROJECT_DIR"

# Keep maintenance access
chown -R $VLADIMIR_USER:$APP_GROUP logs/ cache/ storage/ 2>/dev/null || true
chmod -R g+w logs/ cache/ storage/ 2>/dev/null || true

echo -e "${GREEN}✅ Quick update completed!${NC}"
echo -e "Current commit: $(git rev-parse --short HEAD)"