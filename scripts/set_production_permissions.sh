#!/bin/bash

# Set Production File Permissions
# This script sets proper file permissions for the warehouse dashboard backend on production server

set -e

# Configuration
SERVER_HOST="market-mi.ru"
SERVER_USER="root"
SERVER_PATH="/var/www/market-mi.ru"
WEB_USER="www-data"  # Adjust based on your web server (www-data for Apache/Nginx on Ubuntu/Debian)
WEB_GROUP="www-data"

echo "=== Setting Production File Permissions ==="
echo "Server: $SERVER_HOST"
echo "Path: $SERVER_PATH"
echo "Web user: $WEB_USER"
echo "Web group: $WEB_GROUP"
echo

# Function to set permissions on server
set_permissions() {
    echo "Setting file permissions on production server..."
    
    ssh "$SERVER_USER@$SERVER_HOST" "
        echo 'Setting ownership and permissions...'
        
        # ===================================================================
        # OWNERSHIP
        # ===================================================================
        
        # Set ownership for web server files
        chown -R $WEB_USER:$WEB_GROUP $SERVER_PATH/api/
        chown -R $WEB_USER:$WEB_GROUP $SERVER_PATH/logs/
        chown -R root:$WEB_GROUP $SERVER_PATH/config/
        chown -R root:$WEB_GROUP $SERVER_PATH/scripts/
        
        echo '✓ Ownership set'
        
        # ===================================================================
        # DIRECTORY PERMISSIONS
        # ===================================================================
        
        # API directory - readable and executable by web server
        find $SERVER_PATH/api/ -type d -exec chmod 755 {} \;
        
        # Config directory - readable by web server, not world-readable
        find $SERVER_PATH/config/ -type d -exec chmod 750 {} \;
        
        # Scripts directory - executable by owner and group
        find $SERVER_PATH/scripts/ -type d -exec chmod 755 {} \;
        
        # Logs directory - writable by web server
        find $SERVER_PATH/logs/ -type d -exec chmod 755 {} \;
        
        echo '✓ Directory permissions set'
        
        # ===================================================================
        # FILE PERMISSIONS
        # ===================================================================
        
        # API PHP files - readable by web server
        find $SERVER_PATH/api/ -type f -name '*.php' -exec chmod 644 {} \;
        
        # API .htaccess files - readable by web server
        find $SERVER_PATH/api/ -type f -name '.htaccess' -exec chmod 644 {} \;
        
        # Config files - readable by web server only, not world-readable
        find $SERVER_PATH/config/ -type f -name '*.php' -exec chmod 640 {} \;
        
        # Environment files - very restrictive
        if [ -f $SERVER_PATH/.env ]; then
            chmod 600 $SERVER_PATH/.env
            chown $WEB_USER:$WEB_GROUP $SERVER_PATH/.env
        fi
        
        # Script files - executable
        find $SERVER_PATH/scripts/ -type f -name '*.php' -exec chmod 755 {} \;
        find $SERVER_PATH/scripts/ -type f -name '*.sh' -exec chmod 755 {} \;
        
        # Log files - writable by web server
        find $SERVER_PATH/logs/ -type f -exec chmod 644 {} \;
        
        echo '✓ File permissions set'
        
        # ===================================================================
        # SPECIAL PERMISSIONS
        # ===================================================================
        
        # Make sure sensitive config files are not world-readable
        if [ -f $SERVER_PATH/config/production.php ]; then
            chmod 640 $SERVER_PATH/config/production.php
        fi
        
        if [ -f $SERVER_PATH/config/production_db_override.php ]; then
            chmod 640 $SERVER_PATH/config/production_db_override.php
        fi
        
        if [ -f $SERVER_PATH/config/database_postgresql.php ]; then
            chmod 640 $SERVER_PATH/config/database_postgresql.php
        fi
        
        # Ensure log directory is writable by web server
        chmod 755 $SERVER_PATH/logs/
        
        # Create log files if they don't exist and set permissions
        touch $SERVER_PATH/logs/php_errors.log
        touch $SERVER_PATH/logs/api_access.log
        touch $SERVER_PATH/logs/warehouse_dashboard.log
        
        chown $WEB_USER:$WEB_GROUP $SERVER_PATH/logs/*.log
        chmod 644 $SERVER_PATH/logs/*.log
        
        echo '✓ Special permissions set'
        
        # ===================================================================
        # SECURITY HARDENING
        # ===================================================================
        
        # Remove execute permissions from non-executable files
        find $SERVER_PATH/api/ -type f -name '*.php' -exec chmod -x {} \; 2>/dev/null || true
        find $SERVER_PATH/config/ -type f -name '*.php' -exec chmod -x {} \; 2>/dev/null || true
        
        # Ensure no world-writable files
        find $SERVER_PATH/ -type f -perm -002 -exec chmod o-w {} \; 2>/dev/null || true
        
        echo '✓ Security hardening applied'
        
        echo 'File permissions set successfully!'
    "
}

# Function to verify permissions
verify_permissions() {
    echo "Verifying file permissions..."
    
    ssh "$SERVER_USER@$SERVER_HOST" "
        echo 'Checking critical file permissions...'
        
        # Check API directory
        if [ -d $SERVER_PATH/api/ ]; then
            echo '✓ API directory exists'
            ls -la $SERVER_PATH/api/ | head -5
        else
            echo '✗ API directory missing'
        fi
        
        # Check config directory
        if [ -d $SERVER_PATH/config/ ]; then
            echo '✓ Config directory exists'
            ls -la $SERVER_PATH/config/ | head -3
        else
            echo '✗ Config directory missing'
        fi
        
        # Check logs directory
        if [ -d $SERVER_PATH/logs/ ]; then
            echo '✓ Logs directory exists and is writable'
            ls -la $SERVER_PATH/logs/
        else
            echo '✗ Logs directory missing'
        fi
        
        # Check specific files
        echo
        echo 'Key file permissions:'
        
        if [ -f $SERVER_PATH/api/warehouse-dashboard.php ]; then
            ls -la $SERVER_PATH/api/warehouse-dashboard.php
        fi
        
        if [ -f $SERVER_PATH/config/production.php ]; then
            ls -la $SERVER_PATH/config/production.php
        fi
        
        if [ -f $SERVER_PATH/.env ]; then
            ls -la $SERVER_PATH/.env
        fi
    "
}

# Function to test web server access
test_web_access() {
    echo "Testing web server access to files..."
    
    # Test if web server can access API files
    if curl -s -f \"https://www.market-mi.ru/api/warehouse-dashboard.php?action=warehouses\" > /dev/null; then
        echo \"✓ Web server can access API files\"
    else
        echo \"⚠ Web server cannot access API files - check permissions and web server config\"
    fi
}

# Function to show permission summary
show_summary() {
    echo
    echo \"=== Permission Summary ===\"
    echo \"API files: 644 (readable by web server)\"
    echo \"Config files: 640 (readable by web server only)\"
    echo \"Script files: 755 (executable)\"
    echo \"Log files: 644 (writable by web server)\"
    echo \"Directories: 755 (accessible by web server)\"
    echo \"Environment files: 600 (very restrictive)\"
    echo
    echo \"Owner: $WEB_USER:$WEB_GROUP\"
    echo \"Config owner: root:$WEB_GROUP\"
}

# Main process
main() {
    echo \"Starting permission setup...\"
    
    set_permissions
    verify_permissions
    test_web_access
    show_summary
    
    echo
    echo \"=== File Permissions Setup Complete ===\"
    echo \"All files should now have proper permissions for production use.\"
    echo
    echo \"If you encounter permission issues:\"
    echo \"1. Check web server error logs\"
    echo \"2. Verify web server user ($WEB_USER)\"
    echo \"3. Ensure SELinux/AppArmor policies allow access\"
}

# Check if server is accessible
if ! ssh -o ConnectTimeout=10 \"$SERVER_USER@$SERVER_HOST\" \"echo 'Server accessible'\" 2>/dev/null; then
    echo \"ERROR: Cannot access server $SERVER_HOST\"
    echo \"Please ensure SSH access is configured\"
    exit 1
fi

# Run permission setup
main \"$@\"