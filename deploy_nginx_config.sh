#!/bin/bash

# Nginx Configuration Deployment Script
# Deploys nginx configs from the repository to the server

set -e

echo "🚀 Deploying Nginx Configuration..."

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

# Backup existing configs
echo -e "${YELLOW}📦 Backing up existing configs...${NC}"
BACKUP_DIR="/etc/nginx/backups/$(date +%Y%m%d_%H%M%S)"
mkdir -p "$BACKUP_DIR"

if [ -f /etc/nginx/sites-available/default ]; then
    cp /etc/nginx/sites-available/default "$BACKUP_DIR/default"
    echo "✓ Backed up default config"
fi

if [ -f /etc/nginx/sites-available/mi_core_api ]; then
    cp /etc/nginx/sites-available/mi_core_api "$BACKUP_DIR/mi_core_api"
    echo "✓ Backed up mi_core_api config"
fi

# Copy new configs
echo -e "${YELLOW}📝 Copying new configs...${NC}"
cp nginx/default /etc/nginx/sites-available/default
echo "✓ Copied default config"

cp nginx/mi_core_api /etc/nginx/sites-available/mi_core_api
echo "✓ Copied mi_core_api config"

# Ensure symlinks exist
echo -e "${YELLOW}🔗 Ensuring symlinks...${NC}"
if [ ! -L /etc/nginx/sites-enabled/default ]; then
    ln -s /etc/nginx/sites-available/default /etc/nginx/sites-enabled/default
    echo "✓ Created symlink for default"
else
    echo "✓ Symlink for default already exists"
fi

if [ ! -L /etc/nginx/sites-enabled/mi_core_api ]; then
    ln -s /etc/nginx/sites-available/mi_core_api /etc/nginx/sites-enabled/mi_core_api
    echo "✓ Created symlink for mi_core_api"
else
    echo "✓ Symlink for mi_core_api already exists"
fi

# Test nginx configuration
echo -e "${YELLOW}🧪 Testing nginx configuration...${NC}"
if nginx -t; then
    echo -e "${GREEN}✓ Nginx configuration is valid${NC}"
else
    echo -e "${RED}❌ Nginx configuration test failed!${NC}"
    echo -e "${YELLOW}Restoring backup...${NC}"
    cp "$BACKUP_DIR/default" /etc/nginx/sites-available/default
    cp "$BACKUP_DIR/mi_core_api" /etc/nginx/sites-available/mi_core_api
    echo -e "${RED}Backup restored. Please check the configuration.${NC}"
    exit 1
fi

# Reload nginx
echo -e "${YELLOW}🔄 Reloading nginx...${NC}"
systemctl reload nginx
echo -e "${GREEN}✓ Nginx reloaded successfully${NC}"

# Show status
echo ""
echo -e "${GREEN}✅ Deployment complete!${NC}"
echo ""
echo "Backup location: $BACKUP_DIR"
echo ""
echo "Test your deployment:"
echo "  curl http://178.72.129.61/test.php"
echo "  curl http://178.72.129.61/quality_dashboard.php"
echo "  curl http://178.72.129.61/api/quality-metrics.php"
echo ""
echo "If you configured DNS for filter.zavodprostavok.ru:"
echo "  curl http://filter.zavodprostavok.ru/"
