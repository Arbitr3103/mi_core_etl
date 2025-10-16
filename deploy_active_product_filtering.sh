#!/bin/bash

# ===================================================================
# OZON ACTIVE PRODUCT FILTERING DEPLOYMENT SCRIPT
# ===================================================================
# Version: 1.0
# Date: 2025-01-16
# Description: Deploy active product filtering system to production
# Requirements: 3.2, 4.2

set -e  # Exit on any error

# Configuration
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
MIGRATION_DIR="$SCRIPT_DIR/migrations"
BACKUP_DIR="$SCRIPT_DIR/backups"
LOG_FILE="$SCRIPT_DIR/logs/deployment_$(date +%Y%m%d_%H%M%S).log"

# Colors for output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
PURPLE='\033[0;35m'
NC='\033[0m' # No Color

# Logging functions
log() {
    local message="[$(date +'%Y-%m-%d %H:%M:%S')] $1"
    echo -e "${BLUE}$message${NC}"
    echo "$message" >> "$LOG_FILE"
}

error() {
    local message="[ERROR] $1"
    echo -e "${RED}$message${NC}" >&2
    echo "$message" >> "$LOG_FILE"
}

success() {
    local message="[SUCCESS] $1"
    echo -e "${GREEN}$message${NC}"
    echo "$message" >> "$LOG_FILE"
}

warning() {
    local message="[WARNING] $1"
    echo -e "${YELLOW}$message${NC}"
    echo "$message" >> "$LOG_FILE"
}

info() {
    local message="[INFO] $1"
    echo -e "${PURPLE}$message${NC}"
    echo "$message" >> "$LOG_FILE"
}

# Create necessary directories
create_directories() {
    log "Creating necessary directories..."
    mkdir -p "$BACKUP_DIR"
    mkdir -p "$(dirname "$LOG_FILE")"
    mkdir -p "$MIGRATION_DIR"
    success "Directories created"
}

# Check prerequisites
check_prerequisites() {
    log "Checking deployment prerequisites..."
    
    # Check if MySQL is available
    if ! command -v mysql &> /dev/null; then
        error "MySQL client is not installed or not in PATH"
        exit 1
    fi
    
    # Check if PHP is available
    if ! command -v php &> /dev/null; then
        error "PHP is not installed or not in PATH"
        exit 1
    fi
    
    # Check if required files exist
    local required_files=(
        "$MIGRATION_DIR/add_product_activity_tracking.sql"
        "$MIGRATION_DIR/validate_product_activity_tracking.sql"
        "$MIGRATION_DIR/rollback_product_activity_tracking.sql"
        "config.php"
    )
    
    for file in "${required_files[@]}"; do
        if [[ ! -f "$file" ]]; then
            error "Required file not found: $file"
            exit 1
        fi
    done
    
    # Test database connection
    if ! php -r "
        require_once 'config.php';
        try {
            \$pdo = getDatabaseConnection();
            echo 'Database connection successful';
        } catch (Exception \$e) {
            echo 'Database connection failed: ' . \$e->getMessage();
            exit(1);
        }
    " > /dev/null 2>&1; then
        error "Cannot connect to database. Please check your configuration."
        exit 1
    fi
    
    success "All prerequisites met"
}

# Create database backup
create_backup() {
    log "Creating database backup..."
    
    local backup_file="$BACKUP_DIR/pre_activity_filtering_backup_$(date +%Y%m%d_%H%M%S).sql"
    
    # Get database name from config
    local db_name=$(php -r "
        require_once 'config.php';
        echo DB_NAME;
    ")
    
    if mysqldump --single-transaction --routines --triggers "$db_name" > "$backup_file" 2>/dev/null; then
        success "Database backup created: $backup_file"
        echo "$backup_file" > "$BACKUP_DIR/latest_backup.txt"
    else
        error "Failed to create database backup"
        exit 1
    fi
}

# Apply database migration
apply_migration() {
    log "Applying product activity tracking migration..."
    
    local db_name=$(php -r "
        require_once 'config.php';
        echo DB_NAME;
    ")
    
    if mysql "$db_name" < "$MIGRATION_DIR/add_product_activity_tracking.sql" 2>/dev/null; then
        success "Database migration applied successfully"
    else
        error "Database migration failed"
        return 1
    fi
}

# Validate migration
validate_migration() {
    log "Validating migration results..."
    
    local db_name=$(php -r "
        require_once 'config.php';
        echo DB_NAME;
    ")
    
    local validation_output=$(mktemp)
    
    if mysql "$db_name" < "$MIGRATION_DIR/validate_product_activity_tracking.sql" > "$validation_output" 2>&1; then
        # Check for any FAIL results
        if grep -q "FAIL" "$validation_output"; then
            error "Migration validation failed:"
            grep "FAIL" "$validation_output" | while read -r line; do
                error "  $line"
            done
            rm -f "$validation_output"
            return 1
        else
            success "All migration validation checks passed"
            
            # Show summary statistics
            info "Migration Summary:"
            grep -A 10 "MIGRATION SUMMARY" "$validation_output" | tail -n +2 | while read -r line; do
                if [[ -n "$line" ]]; then
                    info "  $line"
                fi
            done
        fi
    else
        error "Migration validation script failed"
        rm -f "$validation_output"
        return 1
    fi
    
    rm -f "$validation_output"
}

# Update environment configuration
update_environment_config() {
    log "Updating environment configuration..."
    
    # Check if .env file exists
    if [[ ! -f ".env" ]]; then
        warning ".env file not found, creating from .env.example"
        if [[ -f ".env.example" ]]; then
            cp ".env.example" ".env"
            warning "Please update .env file with your actual configuration"
        else
            error ".env.example file not found"
            return 1
        fi
    fi
    
    # Add new environment variables if they don't exist
    local env_vars=(
        "OZON_FILTER_ACTIVE_ONLY=true"
        "OZON_ACTIVITY_CHECK_INTERVAL=3600"
        "OZON_STOCK_THRESHOLD=0"
        "ACTIVITY_LOG_RETENTION_DAYS=90"
        "ACTIVITY_CHANGE_NOTIFICATIONS=true"
        "BATCH_SIZE_ACTIVITY_CHECK=100"
        "MAX_CONCURRENT_REQUESTS=5"
    )
    
    for var in "${env_vars[@]}"; do
        local key=$(echo "$var" | cut -d'=' -f1)
        if ! grep -q "^$key=" ".env"; then
            echo "$var" >> ".env"
            info "Added environment variable: $key"
        else
            info "Environment variable already exists: $key"
        fi
    done
    
    success "Environment configuration updated"
}

# Update API endpoints
update_api_endpoints() {
    log "Updating API endpoints for active product filtering..."
    
    # Check if inventory-analytics.php exists and has been updated
    if [[ -f "api/inventory-analytics.php" ]]; then
        if grep -q "active_only" "api/inventory-analytics.php"; then
            success "API endpoints already updated for active product filtering"
        else
            warning "API endpoints may need manual update for active product filtering"
            info "Please ensure api/inventory-analytics.php includes active_only parameter support"
        fi
    else
        warning "api/inventory-analytics.php not found - may need to be created"
    fi
}

# Test system functionality
test_system() {
    log "Testing system functionality..."
    
    # Test database connection and basic queries
    php -r "
        require_once 'config.php';
        try {
            \$pdo = getDatabaseConnection();
            
            // Test view
            \$stmt = \$pdo->query('SELECT * FROM v_active_products_stats');
            \$stats = \$stmt->fetch();
            
            echo 'System test results:' . PHP_EOL;
            echo '- Total products: ' . \$stats['total_products'] . PHP_EOL;
            echo '- Active products: ' . \$stats['active_products'] . PHP_EOL;
            echo '- Inactive products: ' . \$stats['inactive_products'] . PHP_EOL;
            echo '- Active percentage: ' . \$stats['active_percentage'] . '%' . PHP_EOL;
            
            // Test stored procedure
            \$pdo->exec('CALL UpdateActivityMonitoringStats()');
            echo '- Stored procedure test: PASSED' . PHP_EOL;
            
        } catch (Exception \$e) {
            echo 'System test failed: ' . \$e->getMessage() . PHP_EOL;
            exit(1);
        }
    "
    
    if [[ $? -eq 0 ]]; then
        success "System functionality test passed"
    else
        error "System functionality test failed"
        return 1
    fi
}

# Rollback migration
rollback_migration() {
    warning "Rolling back migration..."
    
    local db_name=$(php -r "
        require_once 'config.php';
        echo DB_NAME;
    ")
    
    if mysql "$db_name" < "$MIGRATION_DIR/rollback_product_activity_tracking.sql" 2>/dev/null; then
        success "Migration rolled back successfully"
    else
        error "Migration rollback failed"
        
        # Try to restore from backup
        if [[ -f "$BACKUP_DIR/latest_backup.txt" ]]; then
            local backup_file=$(cat "$BACKUP_DIR/latest_backup.txt")
            if [[ -f "$backup_file" ]]; then
                warning "Attempting to restore from backup: $backup_file"
                if mysql "$db_name" < "$backup_file" 2>/dev/null; then
                    success "Database restored from backup"
                else
                    error "Failed to restore from backup"
                fi
            fi
        fi
        
        exit 1
    fi
}

# Show deployment summary
show_summary() {
    log "Deployment Summary"
    log "=================="
    
    info "✅ Database migration applied"
    info "✅ Activity tracking tables created"
    info "✅ Environment configuration updated"
    info "✅ System functionality tested"
    
    log ""
    log "Next Steps:"
    log "1. Update ETL processes to use ProductActivityChecker"
    log "2. Update dashboard queries to filter by is_active = 1"
    log "3. Schedule regular activity checks via cron"
    log "4. Monitor system performance and activity statistics"
    log ""
    log "Configuration:"
    log "- Active product filtering: ENABLED"
    log "- Activity check interval: 3600 seconds (1 hour)"
    log "- Stock threshold: 0"
    log "- Log retention: 90 days"
    log ""
    log "Monitoring:"
    log "- View current stats: SELECT * FROM v_active_products_stats;"
    log "- Update daily stats: CALL UpdateActivityMonitoringStats();"
    log "- Check activity logs: SELECT * FROM product_activity_log ORDER BY changed_at DESC LIMIT 10;"
    log ""
    log "Deployment completed successfully!"
}

# Main deployment function
deploy() {
    log "Starting Ozon Active Product Filtering Deployment"
    log "================================================="
    
    create_directories
    check_prerequisites
    create_backup
    
    if apply_migration; then
        if validate_migration; then
            update_environment_config
            update_api_endpoints
            
            if test_system; then
                show_summary
                success "Deployment completed successfully!"
            else
                error "System test failed after deployment"
                read -p "Do you want to rollback the migration? (y/N): " -n 1 -r
                echo
                if [[ $REPLY =~ ^[Yy]$ ]]; then
                    rollback_migration
                fi
                exit 1
            fi
        else
            error "Migration validation failed"
            read -p "Do you want to rollback the migration? (y/N): " -n 1 -r
            echo
            if [[ $REPLY =~ ^[Yy]$ ]]; then
                rollback_migration
            fi
            exit 1
        fi
    else
        error "Migration failed"
        exit 1
    fi
}

# Show usage
usage() {
    echo "Usage: $0 [OPTIONS]"
    echo ""
    echo "Deploy Ozon Active Product Filtering System"
    echo ""
    echo "Options:"
    echo "  --dry-run     Show what would be done without applying changes"
    echo "  --rollback    Rollback the migration"
    echo "  --validate    Only run validation"
    echo "  --test        Only run system tests"
    echo "  --help        Show this help message"
    echo ""
    echo "Examples:"
    echo "  $0                    # Full deployment"
    echo "  $0 --dry-run         # Preview deployment"
    echo "  $0 --validate        # Validate existing migration"
    echo "  $0 --rollback        # Rollback migration"
    echo ""
}

# Handle command line arguments
case "${1:-}" in
    --dry-run)
        log "DRY RUN MODE - No changes will be applied"
        create_directories
        check_prerequisites
        log "Migration would be applied from: $MIGRATION_DIR/add_product_activity_tracking.sql"
        log "Validation would be run from: $MIGRATION_DIR/validate_product_activity_tracking.sql"
        log "Environment configuration would be updated"
        log "System functionality would be tested"
        ;;
    --rollback)
        log "ROLLBACK MODE"
        create_directories
        check_prerequisites
        rollback_migration
        ;;
    --validate)
        log "VALIDATION MODE"
        create_directories
        check_prerequisites
        validate_migration
        ;;
    --test)
        log "TEST MODE"
        create_directories
        check_prerequisites
        test_system
        ;;
    --help)
        usage
        ;;
    "")
        deploy
        ;;
    *)
        error "Unknown option: $1"
        usage
        exit 1
        ;;
esac