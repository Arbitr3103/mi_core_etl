#!/bin/bash

# Configure Production Backend Environment
# This script configures the production environment for the warehouse dashboard backend

set -e

# Configuration
SERVER_HOST="market-mi.ru"
SERVER_USER="root"
SERVER_PATH="/var/www/market-mi.ru"

echo "=== Configuring Production Backend Environment ==="

# Function to configure production environment variables
configure_environment() {
    echo "Configuring production environment..."
    ssh "$SERVER_USER@$SERVER_HOST" "
        # Create environment configuration
        cat > $SERVER_PATH/.env << 'EOF'
APP_ENV=production
APP_DEBUG=false
PG_HOST=localhost
PG_USER=mi_core_user
PG_PASSWORD=PostgreSQL_MDM_2025_SecurePass!
PG_NAME=mi_core_db
PG_PORT=5432
EOF

        # Set secure permissions on .env file
        chmod 600 $SERVER_PATH/.env
        chown www-data:www-data $SERVER_PATH/.env
        
        echo '✓ Environment configured'
    "
}

# Function to configure PHP error logging
configure_error_logging() {
    echo "Configuring error logging..."
    ssh "$SERVER_USER@$SERVER_HOST" "
        # Create PHP error log configuration
        cat > $SERVER_PATH/config/php_error_config.ini << 'EOF'
; PHP Error Configuration for Production
log_errors = On
error_log = $SERVER_PATH/logs/php_errors.log
display_errors = Off
display_startup_errors = Off
error_reporting = E_ALL & ~E_DEPRECATED & ~E_STRICT
EOF

        # Create log files with proper permissions
        touch $SERVER_PATH/logs/php_errors.log
        touch $SERVER_PATH/logs/api_access.log
        touch $SERVER_PATH/logs/warehouse_dashboard.log
        
        chown www-data:www-data $SERVER_PATH/logs/*.log
        chmod 644 $SERVER_PATH/logs/*.log
        
        echo '✓ Error logging configured'
    "
}

# Function to configure database connection test
configure_database_test() {
    echo "Creating database connection test..."
    ssh "$SERVER_USER@$SERVER_HOST" "
        # Create database test script
        cat > $SERVER_PATH/scripts/test_db_connection.php << 'EOF'
<?php
require_once __DIR__ . '/../config/production_db_override.php';

try {
    \$pdo = getProductionDatabaseConnection();
    echo \"✓ Database connection successful\\n\";
    
    // Test basic query
    \$stmt = \$pdo->query(\"SELECT version()\");
    \$version = \$stmt->fetchColumn();
    echo \"PostgreSQL version: \" . \$version . \"\\n\";
    
    // Test warehouse data
    \$stmt = \$pdo->query(\"SELECT COUNT(*) FROM warehouse_sales_metrics LIMIT 1\");
    if (\$stmt) {
        echo \"✓ Warehouse data table accessible\\n\";
    }
    
} catch (Exception \$e) {
    echo \"✗ Database connection failed: \" . \$e->getMessage() . \"\\n\";
    exit(1);
}
EOF

        chmod 755 $SERVER_PATH/scripts/test_db_connection.php
        
        echo '✓ Database test script created'
    "
}

# Function to configure web server settings
configure_web_server() {
    echo "Configuring web server settings..."
    ssh "$SERVER_USER@$SERVER_HOST" "
        # Create .htaccess for API directory
        cat > $SERVER_PATH/api/.htaccess << 'EOF'
# API Directory Configuration
RewriteEngine On

# Enable CORS for API requests
Header always set Access-Control-Allow-Origin \"*\"
Header always set Access-Control-Allow-Methods \"GET, POST, OPTIONS\"
Header always set Access-Control-Allow-Headers \"Content-Type, X-API-Key\"

# Handle OPTIONS requests
RewriteCond %{REQUEST_METHOD} OPTIONS
RewriteRule ^(.*)$ \$1 [R=200,L]

# Security headers
Header always set X-Content-Type-Options nosniff
Header always set X-Frame-Options DENY
Header always set X-XSS-Protection \"1; mode=block\"

# PHP settings
php_value log_errors On
php_value error_log $SERVER_PATH/logs/php_errors.log
php_value display_errors Off
EOF

        echo '✓ Web server configuration created'
    "
}

# Function to test configuration
test_configuration() {
    echo "Testing configuration..."
    
    # Test database connection
    echo "Testing database connection..."
    ssh "$SERVER_USER@$SERVER_HOST" "cd $SERVER_PATH && php scripts/test_db_connection.php"
    
    # Test API endpoint
    echo "Testing API endpoint..."
    sleep 2
    
    if curl -s -f "https://www.market-mi.ru/api/warehouse-dashboard.php?action=warehouses" > /dev/null; then
        echo "✓ API endpoint accessible"
    else
        echo "⚠ API endpoint not accessible - check web server configuration"
    fi
}

# Main configuration process
main() {
    echo "Starting backend configuration..."
    
    configure_environment
    configure_error_logging
    configure_database_test
    configure_web_server
    test_configuration
    
    echo
    echo "=== Backend Configuration Complete ==="
    echo
    echo "Configuration files created:"
    echo "- $SERVER_PATH/.env"
    echo "- $SERVER_PATH/config/php_error_config.ini"
    echo "- $SERVER_PATH/api/.htaccess"
    echo "- $SERVER_PATH/scripts/test_db_connection.php"
    echo
    echo "Log files:"
    echo "- $SERVER_PATH/logs/php_errors.log"
    echo "- $SERVER_PATH/logs/api_access.log"
    echo "- $SERVER_PATH/logs/warehouse_dashboard.log"
    echo
    echo "Test the configuration with:"
    echo "ssh $SERVER_USER@$SERVER_HOST 'cd $SERVER_PATH && php scripts/test_db_connection.php'"
}

# Run configuration
main "$@"