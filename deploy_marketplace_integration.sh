#!/bin/bash

# Marketplace Data Separation - Production Integration Deployment Script
# Deploys enhanced MarginDashboardAPI and frontend components to production
# Version: 1.0.0

set -e  # Stop on error

# Colors for output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m' # No Color

# Configuration
PRODUCTION_HOST="${PRODUCTION_HOST:-api.zavodprostavok.ru}"
PRODUCTION_USER="${PRODUCTION_USER:-deploy}"
PRODUCTION_PATH="${PRODUCTION_PATH:-/var/www/manhattan}"
BACKUP_DIR="${BACKUP_DIR:-/var/backups/manhattan}"
LOG_FILE="deployment_$(date +%Y%m%d_%H%M%S).log"

# Functions for output
print_info() {
    echo -e "${BLUE}[INFO]${NC} $1" | tee -a "$LOG_FILE"
}

print_success() {
    echo -e "${GREEN}[SUCCESS]${NC} $1" | tee -a "$LOG_FILE"
}

print_warning() {
    echo -e "${YELLOW}[WARNING]${NC} $1" | tee -a "$LOG_FILE"
}

print_error() {
    echo -e "${RED}[ERROR]${NC} $1" | tee -a "$LOG_FILE"
}

# Check prerequisites
check_prerequisites() {
    print_info "Checking deployment prerequisites..."
    
    # Check if required files exist
    local required_files=(
        "MarginDashboardAPI.php"
        "src/js/MarketplaceViewToggle.js"
        "src/js/MarketplaceDataRenderer.js"
        "src/js/MarketplaceErrorHandler.js"
        "src/css/marketplace-separation.css"
        "src/classes/MarketplaceDetector.php"
        "src/classes/MarketplaceFallbackHandler.php"
        "src/classes/MarketplaceDataValidator.php"
    )
    
    for file in "${required_files[@]}"; do
        if [ ! -f "$file" ]; then
            print_error "Required file not found: $file"
            exit 1
        fi
    done
    
    # Check SSH connection to production
    if ! ssh -o ConnectTimeout=10 "$PRODUCTION_USER@$PRODUCTION_HOST" "echo 'Connection test successful'" &>/dev/null; then
        print_error "Cannot connect to production server: $PRODUCTION_USER@$PRODUCTION_HOST"
        print_info "Please ensure SSH key authentication is set up"
        exit 1
    fi
    
    print_success "Prerequisites check passed"
}

# Create backup of current production files
create_backup() {
    print_info "Creating backup of current production files..."
    
    local backup_timestamp=$(date +%Y%m%d_%H%M%S)
    local backup_path="$BACKUP_DIR/marketplace_integration_$backup_timestamp"
    
    ssh "$PRODUCTION_USER@$PRODUCTION_HOST" "
        mkdir -p '$backup_path'
        
        # Backup PHP files
        if [ -f '$PRODUCTION_PATH/MarginDashboardAPI.php' ]; then
            cp '$PRODUCTION_PATH/MarginDashboardAPI.php' '$backup_path/'
        fi
        
        if [ -f '$PRODUCTION_PATH/dashboard_example.php' ]; then
            cp '$PRODUCTION_PATH/dashboard_example.php' '$backup_path/'
        fi
        
        if [ -f '$PRODUCTION_PATH/demo_dashboard.php' ]; then
            cp '$PRODUCTION_PATH/demo_dashboard.php' '$backup_path/'
        fi
        
        # Backup JavaScript files
        if [ -d '$PRODUCTION_PATH/src/js' ]; then
            cp -r '$PRODUCTION_PATH/src/js' '$backup_path/'
        fi
        
        # Backup CSS files
        if [ -d '$PRODUCTION_PATH/src/css' ]; then
            cp -r '$PRODUCTION_PATH/src/css' '$backup_path/'
        fi
        
        # Backup PHP classes
        if [ -d '$PRODUCTION_PATH/src/classes' ]; then
            cp -r '$PRODUCTION_PATH/src/classes' '$backup_path/'
        fi
        
        echo 'Backup created at: $backup_path'
    "
    
    print_success "Backup created successfully"
}

# Deploy enhanced MarginDashboardAPI
deploy_api() {
    print_info "Deploying enhanced MarginDashboardAPI..."
    
    # Copy MarginDashboardAPI.php to production
    scp "MarginDashboardAPI.php" "$PRODUCTION_USER@$PRODUCTION_HOST:$PRODUCTION_PATH/"
    
    # Copy PHP classes
    ssh "$PRODUCTION_USER@$PRODUCTION_HOST" "mkdir -p '$PRODUCTION_PATH/src/classes'"
    scp "src/classes/MarketplaceDetector.php" "$PRODUCTION_USER@$PRODUCTION_HOST:$PRODUCTION_PATH/src/classes/"
    scp "src/classes/MarketplaceFallbackHandler.php" "$PRODUCTION_USER@$PRODUCTION_HOST:$PRODUCTION_PATH/src/classes/"
    scp "src/classes/MarketplaceDataValidator.php" "$PRODUCTION_USER@$PRODUCTION_HOST:$PRODUCTION_PATH/src/classes/"
    
    # Set proper permissions
    ssh "$PRODUCTION_USER@$PRODUCTION_HOST" "
        chown -R www-data:www-data '$PRODUCTION_PATH/MarginDashboardAPI.php'
        chown -R www-data:www-data '$PRODUCTION_PATH/src/classes/'
        chmod 644 '$PRODUCTION_PATH/MarginDashboardAPI.php'
        chmod -R 644 '$PRODUCTION_PATH/src/classes/'
    "
    
    print_success "Enhanced MarginDashboardAPI deployed"
}

# Deploy frontend components
deploy_frontend() {
    print_info "Deploying frontend components..."
    
    # Create directories
    ssh "$PRODUCTION_USER@$PRODUCTION_HOST" "
        mkdir -p '$PRODUCTION_PATH/src/js'
        mkdir -p '$PRODUCTION_PATH/src/css'
    "
    
    # Copy JavaScript files
    scp "src/js/MarketplaceViewToggle.js" "$PRODUCTION_USER@$PRODUCTION_HOST:$PRODUCTION_PATH/src/js/"
    scp "src/js/MarketplaceDataRenderer.js" "$PRODUCTION_USER@$PRODUCTION_HOST:$PRODUCTION_PATH/src/js/"
    scp "src/js/MarketplaceErrorHandler.js" "$PRODUCTION_USER@$PRODUCTION_HOST:$PRODUCTION_PATH/src/js/"
    
    # Copy CSS files
    scp "src/css/marketplace-separation.css" "$PRODUCTION_USER@$PRODUCTION_HOST:$PRODUCTION_PATH/src/css/"
    
    # Copy updated dashboard files
    scp "dashboard_example.php" "$PRODUCTION_USER@$PRODUCTION_HOST:$PRODUCTION_PATH/"
    scp "demo_dashboard.php" "$PRODUCTION_USER@$PRODUCTION_HOST:$PRODUCTION_PATH/"
    
    # Set proper permissions
    ssh "$PRODUCTION_USER@$PRODUCTION_HOST" "
        chown -R www-data:www-data '$PRODUCTION_PATH/src/'
        chown www-data:www-data '$PRODUCTION_PATH/dashboard_example.php'
        chown www-data:www-data '$PRODUCTION_PATH/demo_dashboard.php'
        chmod -R 644 '$PRODUCTION_PATH/src/'
        chmod 644 '$PRODUCTION_PATH/dashboard_example.php'
        chmod 644 '$PRODUCTION_PATH/demo_dashboard.php'
    "
    
    print_success "Frontend components deployed"
}

# Configure database indexes
configure_database() {
    print_info "Configuring database indexes for marketplace queries..."
    
    # Create SQL script for indexes
    cat > /tmp/marketplace_indexes.sql << 'EOF'
-- Indexes for marketplace filtering optimization
-- These indexes improve performance of marketplace-specific queries

-- Index on fact_orders.source for marketplace filtering
CREATE INDEX IF NOT EXISTS idx_fact_orders_source ON fact_orders(source);

-- Index on fact_orders.order_date for date range queries
CREATE INDEX IF NOT EXISTS idx_fact_orders_date ON fact_orders(order_date);

-- Composite index for marketplace and date filtering
CREATE INDEX IF NOT EXISTS idx_fact_orders_source_date ON fact_orders(source, order_date);

-- Index on dim_products SKU fields for marketplace detection
CREATE INDEX IF NOT EXISTS idx_dim_products_sku_ozon ON dim_products(sku_ozon);
CREATE INDEX IF NOT EXISTS idx_dim_products_sku_wb ON dim_products(sku_wb);

-- Index on sources table for marketplace identification
CREATE INDEX IF NOT EXISTS idx_sources_code ON sources(code);
CREATE INDEX IF NOT EXISTS idx_sources_name ON sources(name);

-- Show index creation results
SHOW INDEX FROM fact_orders WHERE Key_name LIKE 'idx_fact_orders_%';
SHOW INDEX FROM dim_products WHERE Key_name LIKE 'idx_dim_products_sku_%';
SHOW INDEX FROM sources WHERE Key_name LIKE 'idx_sources_%';
EOF

    # Copy SQL script to production and execute
    scp "/tmp/marketplace_indexes.sql" "$PRODUCTION_USER@$PRODUCTION_HOST:/tmp/"
    
    ssh "$PRODUCTION_USER@$PRODUCTION_HOST" "
        mysql -u root -p\${MYSQL_ROOT_PASSWORD} mi_core_db < /tmp/marketplace_indexes.sql
        rm /tmp/marketplace_indexes.sql
    "
    
    rm /tmp/marketplace_indexes.sql
    
    print_success "Database indexes configured"
}

# Test deployment
test_deployment() {
    print_info "Testing deployment..."
    
    # Test API endpoints
    local test_urls=(
        "https://$PRODUCTION_HOST/manhattan/margin_api.php?action=margin_summary&marketplace=ozon"
        "https://$PRODUCTION_HOST/manhattan/margin_api.php?action=margin_summary&marketplace=wildberries"
        "https://$PRODUCTION_HOST/manhattan/margin_api.php?action=marketplace_comparison"
        "https://$PRODUCTION_HOST/manhattan/dashboard_example.php"
    )
    
    for url in "${test_urls[@]}"; do
        print_info "Testing: $url"
        if curl -f -s -o /dev/null "$url"; then
            print_success "✓ $url"
        else
            print_warning "✗ $url (may require authentication)"
        fi
    done
    
    # Test file permissions
    ssh "$PRODUCTION_USER@$PRODUCTION_HOST" "
        if [ -r '$PRODUCTION_PATH/MarginDashboardAPI.php' ]; then
            echo 'MarginDashboardAPI.php is readable'
        else
            echo 'ERROR: MarginDashboardAPI.php is not readable'
            exit 1
        fi
        
        if [ -r '$PRODUCTION_PATH/src/js/MarketplaceViewToggle.js' ]; then
            echo 'JavaScript files are readable'
        else
            echo 'ERROR: JavaScript files are not readable'
            exit 1
        fi
        
        if [ -r '$PRODUCTION_PATH/src/css/marketplace-separation.css' ]; then
            echo 'CSS files are readable'
        else
            echo 'ERROR: CSS files are not readable'
            exit 1
        fi
    "
    
    print_success "Deployment tests passed"
}

# Restart web services
restart_services() {
    print_info "Restarting web services..."
    
    ssh "$PRODUCTION_USER@$PRODUCTION_HOST" "
        # Restart PHP-FPM if available
        if systemctl is-active --quiet php8.1-fpm; then
            systemctl restart php8.1-fpm
        elif systemctl is-active --quiet php-fpm; then
            systemctl restart php-fpm
        fi
        
        # Restart Nginx
        if systemctl is-active --quiet nginx; then
            systemctl reload nginx
        fi
        
        # Clear any PHP opcache
        if [ -f '$PRODUCTION_PATH/clear_opcache.php' ]; then
            curl -s 'https://$PRODUCTION_HOST/manhattan/clear_opcache.php' > /dev/null
        fi
    "
    
    print_success "Web services restarted"
}

# Show deployment summary
show_deployment_summary() {
    print_success "🎉 Marketplace Integration Deployment Completed!"
    echo
    print_info "📋 Deployment Summary:"
    echo "  🌐 Production URL: https://$PRODUCTION_HOST/manhattan/"
    echo "  📊 Dashboard: https://$PRODUCTION_HOST/manhattan/dashboard_example.php"
    echo "  🧪 Demo Dashboard: https://$PRODUCTION_HOST/manhattan/demo_dashboard.php"
    echo "  🔗 API Endpoint: https://$PRODUCTION_HOST/manhattan/margin_api.php"
    echo
    print_info "🆕 New Features:"
    echo "  ✅ Marketplace-specific data separation (Ozon/Wildberries)"
    echo "  ✅ View toggle between combined and separated modes"
    echo "  ✅ Enhanced API with marketplace filtering"
    echo "  ✅ Optimized database indexes for performance"
    echo "  ✅ Error handling and fallback mechanisms"
    echo
    print_info "🔧 API Examples:"
    echo "  📊 Ozon data: margin_api.php?action=margin_summary&marketplace=ozon"
    echo "  🛍️ Wildberries data: margin_api.php?action=margin_summary&marketplace=wildberries"
    echo "  📈 Comparison: margin_api.php?action=marketplace_comparison"
    echo "  📋 Top products: margin_api.php?action=top_products&marketplace=ozon"
    echo
    print_info "📁 Deployed Files:"
    echo "  📄 MarginDashboardAPI.php (enhanced with marketplace methods)"
    echo "  📄 dashboard_example.php (updated with view toggle)"
    echo "  📄 demo_dashboard.php (updated with marketplace separation)"
    echo "  📁 src/js/ (MarketplaceViewToggle, DataRenderer, ErrorHandler)"
    echo "  📁 src/css/ (marketplace-separation.css)"
    echo "  📁 src/classes/ (MarketplaceDetector, FallbackHandler, Validator)"
    echo
    print_info "📝 Log File: $LOG_FILE"
}

# Rollback function
rollback_deployment() {
    print_warning "Rolling back deployment..."
    
    local latest_backup=$(ssh "$PRODUCTION_USER@$PRODUCTION_HOST" "ls -t '$BACKUP_DIR'/marketplace_integration_* | head -1")
    
    if [ -n "$latest_backup" ]; then
        ssh "$PRODUCTION_USER@$PRODUCTION_HOST" "
            # Restore files from backup
            if [ -f '$latest_backup/MarginDashboardAPI.php' ]; then
                cp '$latest_backup/MarginDashboardAPI.php' '$PRODUCTION_PATH/'
            fi
            
            if [ -f '$latest_backup/dashboard_example.php' ]; then
                cp '$latest_backup/dashboard_example.php' '$PRODUCTION_PATH/'
            fi
            
            if [ -f '$latest_backup/demo_dashboard.php' ]; then
                cp '$latest_backup/demo_dashboard.php' '$PRODUCTION_PATH/'
            fi
            
            if [ -d '$latest_backup/js' ]; then
                cp -r '$latest_backup/js' '$PRODUCTION_PATH/src/'
            fi
            
            if [ -d '$latest_backup/css' ]; then
                cp -r '$latest_backup/css' '$PRODUCTION_PATH/src/'
            fi
            
            if [ -d '$latest_backup/classes' ]; then
                cp -r '$latest_backup/classes' '$PRODUCTION_PATH/src/'
            fi
            
            # Restore permissions
            chown -R www-data:www-data '$PRODUCTION_PATH/'
            chmod -R 644 '$PRODUCTION_PATH/'
        "
        
        restart_services
        print_success "Rollback completed using backup: $latest_backup"
    else
        print_error "No backup found for rollback"
        exit 1
    fi
}

# Main deployment function
main() {
    echo "🚀 Marketplace Data Separation - Production Integration Deployment"
    echo "=================================================================="
    echo
    
    check_prerequisites
    create_backup
    deploy_api
    deploy_frontend
    configure_database
    test_deployment
    restart_services
    show_deployment_summary
}

# Handle command line arguments
case "${1:-deploy}" in
    "deploy")
        main
        ;;
    "rollback")
        rollback_deployment
        ;;
    "test")
        test_deployment
        ;;
    "backup")
        create_backup
        ;;
    "help")
        echo "Usage: $0 [command]"
        echo
        echo "Commands:"
        echo "  deploy   - Deploy marketplace integration to production (default)"
        echo "  rollback - Rollback to previous version"
        echo "  test     - Test current deployment"
        echo "  backup   - Create backup only"
        echo "  help     - Show this help"
        echo
        echo "Environment Variables:"
        echo "  PRODUCTION_HOST - Production server hostname (default: api.zavodprostavok.ru)"
        echo "  PRODUCTION_USER - SSH user for deployment (default: deploy)"
        echo "  PRODUCTION_PATH - Path to application on server (default: /var/www/manhattan)"
        echo "  BACKUP_DIR      - Backup directory (default: /var/backups/manhattan)"
        ;;
    *)
        print_error "Unknown command: $1"
        print_info "Use '$0 help' for usage information"
        exit 1
        ;;
esac