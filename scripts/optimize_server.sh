#!/bin/bash

# Server Optimization Script for MI Core ETL
# Applies optimized configurations for Nginx, PHP-FPM, and PostgreSQL

set -e

PROJECT_ROOT="/var/www/mi_core_etl"
BACKUP_DIR="$PROJECT_ROOT/storage/backups/config_backup_$(date +%Y%m%d_%H%M%S)"

# Colors
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
RED='\033[0;31m'
BLUE='\033[0;34m'
NC='\033[0m'

log() {
    echo -e "${GREEN}[$(date '+%Y-%m-%d %H:%M:%S')]${NC} $1"
}

warning() {
    echo -e "${YELLOW}[$(date '+%Y-%m-%d %H:%M:%S')] WARNING:${NC} $1"
}

error() {
    echo -e "${RED}[$(date '+%Y-%m-%d %H:%M:%S')] ERROR:${NC} $1"
    exit 1
}

info() {
    echo -e "${BLUE}[$(date '+%Y-%m-%d %H:%M:%S')] INFO:${NC} $1"
}

# Check if running as root
if [ "$EUID" -ne 0 ]; then 
    error "This script must be run as root (use sudo)"
fi

log "Starting server optimization for MI Core ETL..."

# Create backup directory
mkdir -p "$BACKUP_DIR"

# =========================================
# NGINX OPTIMIZATION
# =========================================

log "Optimizing Nginx configuration..."

# Backup existing nginx config
if [ -f /etc/nginx/sites-available/default ]; then
    cp /etc/nginx/sites-available/default "$BACKUP_DIR/nginx_default.conf.bak"
    log "Backed up existing Nginx config"
fi

# Copy optimized nginx config
if [ -f "$PROJECT_ROOT/deployment/configs/nginx-complete.conf" ]; then
    cp "$PROJECT_ROOT/deployment/configs/nginx-complete.conf" /etc/nginx/sites-available/mi_core_etl
    
    # Enable site
    ln -sf /etc/nginx/sites-available/mi_core_etl /etc/nginx/sites-enabled/mi_core_etl
    
    # Test nginx configuration
    if nginx -t 2>&1 | grep -q "successful"; then
        log "Nginx configuration is valid"
    else
        error "Nginx configuration test failed"
    fi
else
    warning "Optimized Nginx config not found, skipping"
fi

# Create cache directory
mkdir -p /var/cache/nginx/api
chown -R www-data:www-data /var/cache/nginx/api

# =========================================
# PHP-FPM OPTIMIZATION
# =========================================

log "Optimizing PHP-FPM configuration..."

# Detect PHP version
PHP_VERSION=$(php -r "echo PHP_MAJOR_VERSION.'.'.PHP_MINOR_VERSION;")
PHP_FPM_DIR="/etc/php/$PHP_VERSION/fpm"

if [ -d "$PHP_FPM_DIR" ]; then
    # Backup existing PHP-FPM config
    if [ -f "$PHP_FPM_DIR/pool.d/www.conf" ]; then
        cp "$PHP_FPM_DIR/pool.d/www.conf" "$BACKUP_DIR/php-fpm_www.conf.bak"
        log "Backed up existing PHP-FPM config"
    fi
    
    # Copy optimized PHP-FPM config
    if [ -f "$PROJECT_ROOT/deployment/configs/php-fpm-optimized.conf" ]; then
        cp "$PROJECT_ROOT/deployment/configs/php-fpm-optimized.conf" "$PHP_FPM_DIR/pool.d/mi_core_etl.conf"
        
        # Test PHP-FPM configuration
        if php-fpm$PHP_VERSION -t 2>&1 | grep -q "successful"; then
            log "PHP-FPM configuration is valid"
        else
            error "PHP-FPM configuration test failed"
        fi
    else
        warning "Optimized PHP-FPM config not found, skipping"
    fi
    
    # Enable OPcache if not already enabled
    if ! php -m | grep -q "Zend OPcache"; then
        warning "OPcache is not enabled. Installing..."
        apt-get install -y php$PHP_VERSION-opcache || true
    fi
else
    warning "PHP-FPM directory not found, skipping PHP optimization"
fi

# =========================================
# POSTGRESQL OPTIMIZATION
# =========================================

log "Optimizing PostgreSQL configuration..."

# Check if PostgreSQL is installed
if command -v psql &> /dev/null; then
    PG_VERSION=$(psql --version | awk '{print $3}' | cut -d. -f1)
    PG_CONF_DIR="/etc/postgresql/$PG_VERSION/main"
    
    if [ -d "$PG_CONF_DIR" ]; then
        # Backup existing PostgreSQL config
        if [ -f "$PG_CONF_DIR/postgresql.conf" ]; then
            cp "$PG_CONF_DIR/postgresql.conf" "$BACKUP_DIR/postgresql.conf.bak"
            log "Backed up existing PostgreSQL config"
        fi
        
        # Create conf.d directory if it doesn't exist
        mkdir -p "$PG_CONF_DIR/conf.d"
        
        # Copy optimized PostgreSQL config
        if [ -f "$PROJECT_ROOT/deployment/configs/postgresql-optimized.conf" ]; then
            cp "$PROJECT_ROOT/deployment/configs/postgresql-optimized.conf" "$PG_CONF_DIR/conf.d/mi_core_etl.conf"
            log "Copied optimized PostgreSQL config"
        else
            warning "Optimized PostgreSQL config not found, skipping"
        fi
    else
        warning "PostgreSQL config directory not found"
    fi
else
    info "PostgreSQL not installed, skipping PostgreSQL optimization"
fi

# =========================================
# SYSTEM OPTIMIZATIONS
# =========================================

log "Applying system optimizations..."

# Increase file descriptor limits
if ! grep -q "mi_core_etl" /etc/security/limits.conf; then
    cat >> /etc/security/limits.conf << EOF

# MI Core ETL optimizations
www-data soft nofile 65536
www-data hard nofile 65536
postgres soft nofile 65536
postgres hard nofile 65536
EOF
    log "Increased file descriptor limits"
fi

# Optimize kernel parameters for web server
if [ -f /etc/sysctl.conf ]; then
    if ! grep -q "mi_core_etl" /etc/sysctl.conf; then
        cat >> /etc/sysctl.conf << EOF

# MI Core ETL kernel optimizations
net.core.somaxconn = 65535
net.ipv4.tcp_max_syn_backlog = 8192
net.ipv4.tcp_tw_reuse = 1
net.ipv4.ip_local_port_range = 10000 65000
vm.swappiness = 10
EOF
        sysctl -p
        log "Applied kernel optimizations"
    fi
fi

# =========================================
# RESTART SERVICES
# =========================================

log "Restarting services..."

# Restart Nginx
if systemctl is-active --quiet nginx; then
    systemctl restart nginx
    log "Nginx restarted"
else
    warning "Nginx is not running"
fi

# Restart PHP-FPM
if systemctl is-active --quiet php$PHP_VERSION-fpm; then
    systemctl restart php$PHP_VERSION-fpm
    log "PHP-FPM restarted"
elif systemctl is-active --quiet php-fpm; then
    systemctl restart php-fpm
    log "PHP-FPM restarted"
else
    warning "PHP-FPM is not running"
fi

# Reload PostgreSQL (don't restart to avoid downtime)
if systemctl is-active --quiet postgresql; then
    systemctl reload postgresql
    log "PostgreSQL configuration reloaded"
else
    info "PostgreSQL is not running"
fi

# =========================================
# VERIFICATION
# =========================================

log "Verifying services..."

# Check Nginx
if systemctl is-active --quiet nginx; then
    info "✓ Nginx is running"
else
    warning "✗ Nginx is not running"
fi

# Check PHP-FPM
if systemctl is-active --quiet php$PHP_VERSION-fpm || systemctl is-active --quiet php-fpm; then
    info "✓ PHP-FPM is running"
else
    warning "✗ PHP-FPM is not running"
fi

# Check PostgreSQL
if systemctl is-active --quiet postgresql; then
    info "✓ PostgreSQL is running"
else
    info "✗ PostgreSQL is not installed or not running"
fi

# =========================================
# SUMMARY
# =========================================

echo ""
echo "========================================="
echo "Server Optimization Summary"
echo "========================================="
echo "Backup location: $BACKUP_DIR"
echo ""
echo "Applied optimizations:"
echo "  ✓ Nginx configuration"
echo "  ✓ PHP-FPM configuration"
echo "  ✓ PostgreSQL configuration (if installed)"
echo "  ✓ System kernel parameters"
echo "  ✓ File descriptor limits"
echo ""
echo "Next steps:"
echo "  1. Monitor server performance"
echo "  2. Check logs for any errors"
echo "  3. Run health check: curl http://localhost/api/health"
echo "  4. Monitor with: bash scripts/system_monitor.sh"
echo "========================================="

log "Server optimization completed!"
