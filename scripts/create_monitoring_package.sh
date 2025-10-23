#!/bin/bash

# Create Monitoring Package for Manual Upload
# Creates a zip archive with all monitoring files

set -e

LOCAL_PATH="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
PACKAGE_DIR="monitoring_package"
ARCHIVE_NAME="warehouse_monitoring_system.tar.gz"

# Colors for output
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m' # No Color

echo -e "${BLUE}📦 Creating monitoring package...${NC}"

# Clean up previous package
rm -rf "$PACKAGE_DIR"
rm -f "$ARCHIVE_NAME"

# Create package directory structure
mkdir -p "$PACKAGE_DIR/api"
mkdir -p "$PACKAGE_DIR/config"
mkdir -p "$PACKAGE_DIR/scripts"
mkdir -p "$PACKAGE_DIR/tests/Production"

# Copy monitoring files
echo -e "${YELLOW}📋 Copying files...${NC}"

# API files
cp "$LOCAL_PATH/api/monitoring.php" "$PACKAGE_DIR/api/" 2>/dev/null && echo "✓ api/monitoring.php"
cp "$LOCAL_PATH/api/monitoring-status.php" "$PACKAGE_DIR/api/" 2>/dev/null && echo "✓ api/monitoring-status.php"
cp "$LOCAL_PATH/api/server_diagnostics.php" "$PACKAGE_DIR/api/" 2>/dev/null && echo "✓ api/server_diagnostics.php"

# Config files
cp "$LOCAL_PATH/config/monitoring.php" "$PACKAGE_DIR/config/" 2>/dev/null && echo "✓ config/monitoring.php"

# Scripts
cp "$LOCAL_PATH/scripts/uptime_monitor.php" "$PACKAGE_DIR/scripts/" 2>/dev/null && echo "✓ scripts/uptime_monitor.php"
cp "$LOCAL_PATH/scripts/alert_manager.php" "$PACKAGE_DIR/scripts/" 2>/dev/null && echo "✓ scripts/alert_manager.php"
cp "$LOCAL_PATH/scripts/setup_monitoring_cron.sh" "$PACKAGE_DIR/scripts/" 2>/dev/null && echo "✓ scripts/setup_monitoring_cron.sh"

# Dashboard and docs
cp "$LOCAL_PATH/monitoring.html" "$PACKAGE_DIR/" 2>/dev/null && echo "✓ monitoring.html"
cp "$LOCAL_PATH/MONITORING_SETUP_GUIDE.md" "$PACKAGE_DIR/" 2>/dev/null && echo "✓ MONITORING_SETUP_GUIDE.md"

# Test files
cp "$LOCAL_PATH/tests/Production/WarehouseDashboardProductionTest.php" "$PACKAGE_DIR/tests/Production/" 2>/dev/null && echo "✓ tests/Production/WarehouseDashboardProductionTest.php"
cp "$LOCAL_PATH/run_production_tests.php" "$PACKAGE_DIR/" 2>/dev/null && echo "✓ run_production_tests.php"

# Create installation script
cat > "$PACKAGE_DIR/install.sh" << 'EOF'
#!/bin/bash

# Monitoring System Installation Script
# Run this script on the production server

set -e

INSTALL_PATH="/var/www/market-mi.ru"
LOG_PATH="/var/log/warehouse-dashboard"

echo "🚀 Installing Warehouse Dashboard Monitoring System..."

# Create log directory
echo "📁 Creating log directory..."
mkdir -p "$LOG_PATH"
chown www-data:www-data "$LOG_PATH"
chmod 755 "$LOG_PATH"

# Copy files to installation directory
echo "📋 Copying files..."
cp -r api/* "$INSTALL_PATH/api/"
cp -r config/* "$INSTALL_PATH/config/"
cp -r scripts/* "$INSTALL_PATH/scripts/"
cp -r tests/* "$INSTALL_PATH/tests/" 2>/dev/null || mkdir -p "$INSTALL_PATH/tests"
cp monitoring.html "$INSTALL_PATH/"
cp MONITORING_SETUP_GUIDE.md "$INSTALL_PATH/"
cp run_production_tests.php "$INSTALL_PATH/" 2>/dev/null || true

# Set permissions
echo "🔐 Setting permissions..."
chmod +x "$INSTALL_PATH/scripts"/*.sh
chmod +x "$INSTALL_PATH/scripts"/*.php
chmod 644 "$INSTALL_PATH/api"/monitoring*.php
chmod 644 "$INSTALL_PATH/config/monitoring.php"
chmod 644 "$INSTALL_PATH/monitoring.html"

# Set ownership
chown -R www-data:www-data "$INSTALL_PATH/api/"
chown -R www-data:www-data "$INSTALL_PATH/config/"
chown www-data:www-data "$INSTALL_PATH/monitoring.html"

echo "✅ Installation completed!"
echo ""
echo "📊 Access monitoring dashboard at:"
echo "   https://www.market-mi.ru/monitoring.html"
echo ""
echo "🔧 Test monitoring:"
echo "   php $INSTALL_PATH/api/monitoring.php"
echo ""
echo "⏰ Setup cron jobs:"
echo "   bash $INSTALL_PATH/scripts/setup_monitoring_cron.sh"
EOF

chmod +x "$PACKAGE_DIR/install.sh"

# Create README
cat > "$PACKAGE_DIR/README.md" << 'EOF'
# Warehouse Dashboard Monitoring System

## Installation Instructions

1. Upload this package to your server
2. Extract the archive
3. Run the installation script as root:
   ```bash
   sudo bash install.sh
   ```

## Files Included

- `api/` - Monitoring API endpoints
- `config/` - Configuration files
- `scripts/` - Monitoring scripts and utilities
- `monitoring.html` - Web dashboard
- `install.sh` - Installation script
- `MONITORING_SETUP_GUIDE.md` - Detailed setup guide

## Quick Test

After installation, test the monitoring system:

```bash
# Test monitoring API
php /var/www/market-mi.ru/api/monitoring.php

# Test uptime monitor
php /var/www/market-mi.ru/scripts/uptime_monitor.php

# Access dashboard
https://www.market-mi.ru/monitoring.html
```

## Setup Cron Jobs

```bash
bash /var/www/market-mi.ru/scripts/setup_monitoring_cron.sh
```

This will set up automated monitoring tasks.
EOF

# Create archive
echo -e "${YELLOW}📦 Creating archive...${NC}"
tar -czf "$ARCHIVE_NAME" "$PACKAGE_DIR"

# Clean up
rm -rf "$PACKAGE_DIR"

echo ""
echo -e "${GREEN}✅ Package created successfully!${NC}"
echo ""
echo -e "${BLUE}📦 Archive: $ARCHIVE_NAME${NC}"
echo -e "${BLUE}📏 Size: $(du -h "$ARCHIVE_NAME" | cut -f1)${NC}"
echo ""
echo "📋 To deploy:"
echo "   1. Upload $ARCHIVE_NAME to your server"
echo "   2. Extract: tar -xzf $ARCHIVE_NAME"
echo "   3. Run: sudo bash monitoring_package/install.sh"
echo ""