#!/bin/bash

# Directory Structure Preparation Script
# Run this script on the production server to prepare directories

set -e

echo "📁 Preparing Directory Structure"
echo "==============================="

# Configuration
WEB_ROOT="/var/www/market-mi.ru"
LOG_DIR="/var/log/warehouse-dashboard"

echo "📋 Creating web directories..."

# Create main web directories
echo "[INFO] Creating $WEB_ROOT/api/"
mkdir -p "$WEB_ROOT/api"

echo "[INFO] Creating $WEB_ROOT/warehouse-dashboard/"
mkdir -p "$WEB_ROOT/warehouse-dashboard"

# Create subdirectories for API
echo "[INFO] Creating API subdirectories..."
mkdir -p "$WEB_ROOT/api/classes"
mkdir -p "$WEB_ROOT/api/config"
mkdir -p "$WEB_ROOT/api/endpoints"

# Create log directory
echo "[INFO] Creating log directory: $LOG_DIR"
mkdir -p "$LOG_DIR"

echo "🔒 Setting permissions..."

# Set ownership (try both www-data and apache)
if id "www-data" &>/dev/null; then
    echo "[INFO] Setting ownership to www-data..."
    chown -R www-data:www-data "$WEB_ROOT/api" "$WEB_ROOT/warehouse-dashboard" "$LOG_DIR"
elif id "apache" &>/dev/null; then
    echo "[INFO] Setting ownership to apache..."
    chown -R apache:apache "$WEB_ROOT/api" "$WEB_ROOT/warehouse-dashboard" "$LOG_DIR"
else
    echo "[WARNING] Neither www-data nor apache user found, skipping ownership..."
fi

# Set permissions
echo "[INFO] Setting directory permissions..."
chmod -R 755 "$WEB_ROOT/api" "$WEB_ROOT/warehouse-dashboard"
chmod -R 755 "$LOG_DIR"

# Create .htaccess for API security (if Apache)
if command -v apache2 &> /dev/null || command -v httpd &> /dev/null; then
    echo "[INFO] Creating .htaccess for API security..."
    cat > "$WEB_ROOT/api/.htaccess" << 'EOF'
# API Security Configuration
<Files "*.php">
    Order allow,deny
    Allow from all
</Files>

<Files "config/*">
    Order deny,allow
    Deny from all
</Files>

# Enable CORS for API
Header always set Access-Control-Allow-Origin "*"
Header always set Access-Control-Allow-Methods "GET, POST, OPTIONS"
Header always set Access-Control-Allow-Headers "Content-Type, Authorization"
EOF
fi

echo "📊 Directory structure created:"
echo "├── $WEB_ROOT/"
echo "│   ├── api/"
echo "│   │   ├── classes/"
echo "│   │   ├── config/"
echo "│   │   └── endpoints/"
echo "│   └── warehouse-dashboard/"
echo "└── $LOG_DIR/"

echo "✅ Directory preparation completed!"
echo ""
echo "Next steps:"
echo "1. Deploy backend files to $WEB_ROOT/api/"
echo "2. Deploy frontend files to $WEB_ROOT/warehouse-dashboard/"
echo "3. Configure web server"
echo "4. Test access permissions"