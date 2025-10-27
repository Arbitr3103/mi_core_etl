#!/bin/bash

# Warehouse Dashboard Rollback Script
# Emergency rollback procedure for warehouse dashboard deployment

set -e  # Exit on error

# Colors for output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
CYAN='\033[0;36m'
NC='\033[0m' # No Color

echo -e "${RED}=========================================="
echo "Warehouse Dashboard Rollback Procedure"
echo -e "==========================================${NC}"
echo ""

# Configuration
SERVER_USER="${DEPLOY_USER:-root}"
SERVER_HOST="${DEPLOY_HOST:-market-mi.ru}"
SERVER_PATH="${DEPLOY_PATH:-/var/www/market-mi.ru}"
BACKUP_DIR="${BACKUP_DIR:-/var/www/market-mi.ru/backups}"

# Function to print colored output
print_status() {
    echo -e "${GREEN}[✓]${NC} $1"
}

print_error() {
    echo -e "${RED}[✗]${NC} $1"
}

print_warning() {
    echo -e "${YELLOW}[!]${NC} $1"
}

print_info() {
    echo -e "${BLUE}[i]${NC} $1"
}

# Warning message
echo -e "${RED}⚠️  WARNING: This will rollback the warehouse dashboard deployment${NC}"
echo -e "${YELLOW}This action should only be performed in case of critical issues.${NC}"
echo ""
echo "This script will:"
echo "  1. Disable the new dashboard feature flag"
echo "  2. Redirect all users to the old dashboard"
echo "  3. Optionally restore previous backend files"
echo "  4. Optionally remove database changes"
echo ""

read -p "Are you sure you want to proceed? (yes/no): " confirm
if [ "$confirm" != "yes" ]; then
    print_info "Rollback cancelled"
    exit 0
fi

# Check SSH connection
print_info "Checking SSH connection to $SERVER_USER@$SERVER_HOST..."
if ! ssh -o ConnectTimeout=5 "$SERVER_USER@$SERVER_HOST" "echo 'Connection successful'" > /dev/null 2>&1; then
    print_error "Cannot connect to server. Please check your SSH configuration."
    exit 1
fi
print_status "SSH connection successful"

# Step 1: Disable feature flag
print_info "Step 1: Disabling new dashboard feature flag..."
ssh "$SERVER_USER@$SERVER_HOST" "
    if [ -f '$SERVER_PATH/warehouse-dashboard/config.js' ]; then
        # Disable the feature flag
        sed -i 's/enabled: true/enabled: false/' '$SERVER_PATH/warehouse-dashboard/config.js'
        sed -i 's/rolloutPercentage: [0-9]*/rolloutPercentage: 0/' '$SERVER_PATH/warehouse-dashboard/config.js'
        echo 'Feature flag disabled'
    else
        echo 'Config file not found, skipping'
    fi
"
print_status "Feature flag disabled - all users redirected to old dashboard"

# Step 2: Verify old dashboard is accessible
print_info "Step 2: Verifying old dashboard accessibility..."
RESPONSE=$(curl -s -w "%{http_code}" "https://$SERVER_HOST/warehouse_dashboard.html")
HTTP_CODE="${RESPONSE: -3}"

if [ "$HTTP_CODE" = "200" ]; then
    print_status "Old dashboard is accessible"
else
    print_error "Old dashboard returned HTTP $HTTP_CODE"
    print_warning "Users may not be able to access any dashboard!"
fi

# Step 3: Ask about backend rollback
echo ""
print_warning "Do you want to rollback backend API changes?"
echo "  - This will restore previous API files from backup"
echo "  - The new API endpoint will be removed"
echo "  - Old API endpoints will continue to work"
echo ""
read -p "Rollback backend? (yes/no): " rollback_backend

if [ "$rollback_backend" = "yes" ]; then
    print_info "Step 3: Rolling back backend files..."
    
    # List available backups
    ssh "$SERVER_USER@$SERVER_HOST" "
        if [ -d '$BACKUP_DIR' ]; then
            echo 'Available backups:'
            ls -lt '$BACKUP_DIR' | grep '^d' | head -5
        else
            echo 'No backup directory found'
            exit 1
        fi
    "
    
    echo ""
    read -p "Enter backup name to restore (or 'latest' for most recent): " backup_name
    
    if [ "$backup_name" = "latest" ]; then
        backup_name=$(ssh "$SERVER_USER@$SERVER_HOST" "ls -t '$BACKUP_DIR' | head -1")
    fi
    
    print_info "Restoring from backup: $backup_name"
    
    ssh "$SERVER_USER@$SERVER_HOST" "
        if [ -d '$BACKUP_DIR/$backup_name' ]; then
            # Restore API files
            if [ -d '$BACKUP_DIR/$backup_name/api' ]; then
                cp -r '$BACKUP_DIR/$backup_name/api'/* '$SERVER_PATH/api/'
                echo 'API files restored'
            fi
            
            # Restore SQL files
            if [ -d '$BACKUP_DIR/$backup_name/sql' ]; then
                cp -r '$BACKUP_DIR/$backup_name/sql'/* '$SERVER_PATH/sql/'
                echo 'SQL files restored'
            fi
            
            # Set permissions
            chown -R www-data:www-data '$SERVER_PATH/api'
            chmod -R 755 '$SERVER_PATH/api'
            
            echo 'Backend files restored successfully'
        else
            echo 'Backup not found: $backup_name'
            exit 1
        fi
    "
    
    print_status "Backend files restored"
else
    print_info "Skipping backend rollback - new API will remain available"
fi

# Step 4: Ask about database rollback
echo ""
print_warning "Do you want to rollback database changes?"
echo "  - This will drop the v_detailed_inventory view"
echo "  - Performance indexes will be removed"
echo "  - No data will be lost (only views and indexes)"
echo ""
read -p "Rollback database? (yes/no): " rollback_database

if [ "$rollback_database" = "yes" ]; then
    print_info "Step 4: Rolling back database changes..."
    
    ssh "$SERVER_USER@$SERVER_HOST" "
        cd '$SERVER_PATH'
        
        # Drop the view
        PGPASSWORD=\$PG_PASSWORD psql -h \$PG_HOST -U \$PG_USER -d \$PG_NAME -c 'DROP VIEW IF EXISTS v_detailed_inventory;'
        
        if [ \$? -eq 0 ]; then
            echo 'Database view dropped'
        else
            echo 'Error dropping view'
        fi
        
        # Drop indexes (optional, they don't hurt)
        PGPASSWORD=\$PG_PASSWORD psql -h \$PG_HOST -U \$PG_USER -d \$PG_NAME <<EOF
DROP INDEX IF EXISTS idx_inventory_warehouse_name;
DROP INDEX IF EXISTS idx_inventory_product_id;
DROP INDEX IF EXISTS idx_inventory_warehouse_product;
DROP INDEX IF EXISTS idx_warehouse_sales_metrics_product_warehouse;
DROP INDEX IF EXISTS idx_warehouse_sales_metrics_daily_sales;
EOF
        
        if [ \$? -eq 0 ]; then
            echo 'Indexes dropped'
        else
            echo 'Error dropping indexes'
        fi
    "
    
    print_status "Database changes rolled back"
else
    print_info "Skipping database rollback - view and indexes will remain"
fi

# Step 5: Clear cache
print_info "Step 5: Clearing cache..."
ssh "$SERVER_USER@$SERVER_HOST" "
    if [ -d '$SERVER_PATH/storage/cache/inventory' ]; then
        rm -rf '$SERVER_PATH/storage/cache/inventory'/*
        echo 'Cache cleared'
    fi
"
print_status "Cache cleared"

# Step 6: Restart services
print_info "Step 6: Restarting web services..."
ssh "$SERVER_USER@$SERVER_HOST" "
    # Restart web server
    if systemctl is-active --quiet nginx; then
        systemctl reload nginx
        echo 'Nginx reloaded'
    elif systemctl is-active --quiet apache2; then
        systemctl reload apache2
        echo 'Apache reloaded'
    fi
    
    # Restart PHP-FPM
    if systemctl is-active --quiet php8.1-fpm; then
        systemctl reload php8.1-fpm
        echo 'PHP-FPM reloaded'
    elif systemctl is-active --quiet php8.0-fpm; then
        systemctl reload php8.0-fpm
        echo 'PHP-FPM reloaded'
    fi
"
print_status "Services restarted"

# Step 7: Verify rollback
print_info "Step 7: Verifying rollback..."

# Check that users are redirected to old dashboard
RESPONSE=$(curl -s -L "https://$SERVER_HOST/warehouse-dashboard-switcher.html")
if echo "$RESPONSE" | grep -q "warehouse_dashboard.html"; then
    print_status "Users are being redirected to old dashboard"
else
    print_warning "Redirect verification inconclusive"
fi

# Check old dashboard is working
RESPONSE=$(curl -s -w "%{http_code}" "https://$SERVER_HOST/warehouse_dashboard.html")
HTTP_CODE="${RESPONSE: -3}"

if [ "$HTTP_CODE" = "200" ]; then
    print_status "Old dashboard is accessible and working"
else
    print_error "Old dashboard returned HTTP $HTTP_CODE"
fi

# Display rollback summary
echo ""
echo -e "${GREEN}=========================================="
echo "Rollback Summary"
echo -e "==========================================${NC}"
echo "Server: $SERVER_USER@$SERVER_HOST"
echo "Rollback Date: $(date)"
echo ""
print_status "Rollback completed"
echo ""
echo -e "${CYAN}Actions Taken:${NC}"
echo "  ✓ Feature flag disabled"
echo "  ✓ Users redirected to old dashboard"
if [ "$rollback_backend" = "yes" ]; then
    echo "  ✓ Backend files restored from backup"
else
    echo "  - Backend files not rolled back"
fi
if [ "$rollback_database" = "yes" ]; then
    echo "  ✓ Database changes rolled back"
else
    echo "  - Database changes not rolled back"
fi
echo "  ✓ Cache cleared"
echo "  ✓ Services restarted"
echo ""
echo -e "${YELLOW}Post-Rollback Actions:${NC}"
echo "  1. Verify old dashboard is working for all users"
echo "  2. Investigate root cause of issues"
echo "  3. Document lessons learned"
echo "  4. Plan fixes before re-deployment"
echo "  5. Communicate status to stakeholders"
echo ""
echo -e "${CYAN}Monitoring:${NC}"
echo "  • Old dashboard: https://$SERVER_HOST/warehouse_dashboard.html"
echo "  • Error logs: ssh $SERVER_USER@$SERVER_HOST 'tail -f $SERVER_PATH/logs/error.log'"
echo "  • Access logs: ssh $SERVER_USER@$SERVER_HOST 'tail -f /var/log/nginx/access.log'"
echo ""
echo -e "${GREEN}========================================${NC}"
