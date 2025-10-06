#!/bin/bash

# Production Deployment Script for Inventory Sync System
# Version: 1.0
# Usage: ./deploy_production.sh [deploy|rollback|status|health-check]

set -e

# Configuration
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_DIR="$(dirname "$SCRIPT_DIR")"
DEPLOYMENT_LOG="logs/inventory_sync/deployment.log"
BACKUP_DIR="backup/inventory_sync_deployment"
SERVICE_NAME="inventory_sync"

# Load environment variables
if [ -f "$PROJECT_DIR/.env" ]; then
    source "$PROJECT_DIR/.env"
else
    echo "ERROR: .env file not found in $PROJECT_DIR"
    exit 1
fi

# Colors for output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m' # No Color

# Logging function
log() {
    local level="$1"
    shift
    local message="$*"
    local timestamp=$(date '+%Y-%m-%d %H:%M:%S')
    
    # Log to file
    echo "${timestamp} [${level}] ${message}" >> "$DEPLOYMENT_LOG"
    
    # Log to console with colors
    case "$level" in
        "ERROR")
            echo -e "${RED}[ERROR]${NC} $message" >&2
            ;;
        "WARN")
            echo -e "${YELLOW}[WARN]${NC} $message"
            ;;
        "INFO")
            echo -e "${GREEN}[INFO]${NC} $message"
            ;;
        "DEBUG")
            echo -e "${BLUE}[DEBUG]${NC} $message"
            ;;
    esac
}

# Check if running as correct user
check_user() {
    if [ "$EUID" -eq 0 ]; then
        log "ERROR" "Do not run this script as root. Run as the application user."
        exit 1
    fi
}

# Create necessary directories
setup_directories() {
    log "INFO" "Setting up directories..."
    
    # Create log directory
    sudo mkdir -p "$(dirname "$DEPLOYMENT_LOG")"
    sudo chown "$USER:$USER" "$(dirname "$DEPLOYMENT_LOG")"
    
    # Create backup directory
    sudo mkdir -p "$BACKUP_DIR"
    sudo chown "$USER:$USER" "$BACKUP_DIR"
    
    # Create PID directory for services
    sudo mkdir -p /var/run/inventory_sync
    sudo chown "$USER:$USER" /var/run/inventory_sync
    
    log "INFO" "Directories created successfully"
}

# Check prerequisites
check_prerequisites() {
    log "INFO" "Checking deployment prerequisites..."
    
    # Check Python version
    if ! python3 --version | grep -E "3\.[8-9]|3\.1[0-9]" >/dev/null; then
        log "ERROR" "Python 3.8+ required"
        exit 1
    fi
    
    # Check MySQL client
    if ! command -v mysql >/dev/null 2>&1; then
        log "ERROR" "MySQL client not found"
        exit 1
    fi
    
    # Check required Python packages
    if ! python3 -c "import requests, mysql.connector, schedule" 2>/dev/null; then
        log "ERROR" "Required Python packages not installed. Run: pip install -r requirements.txt"
        exit 1
    fi
    
    # Check database connection
    if ! mysql -h"${DB_HOST:-localhost}" -P"${DB_PORT:-3306}" -u"${DB_USER}" -p"${DB_PASSWORD}" -e "SELECT 1;" "${DB_NAME}" >/dev/null 2>&1; then
        log "ERROR" "Cannot connect to database"
        exit 1
    fi
    
    # Check API credentials
    if [ -z "$OZON_CLIENT_ID" ] || [ -z "$OZON_API_KEY" ] || [ -z "$WB_API_KEY" ]; then
        log "ERROR" "API credentials not configured in .env file"
        exit 1
    fi
    
    # Check disk space (minimum 1GB free)
    local free_space=$(df / | awk 'NR==2 {print $4}')
    if [ "$free_space" -lt 1048576 ]; then  # 1GB in KB
        log "ERROR" "Insufficient disk space. At least 1GB required."
        exit 1
    fi
    
    log "INFO" "Prerequisites check passed"
}

# Test API connections
test_api_connections() {
    log "INFO" "Testing API connections..."
    
    # Test Ozon API
    if python3 -c "
import requests
import os
headers = {
    'Client-Id': os.getenv('OZON_CLIENT_ID'),
    'Api-Key': os.getenv('OZON_API_KEY')
}
response = requests.get('https://api-seller.ozon.ru/v1/report/info', headers=headers, timeout=10)
print('Ozon API:', 'OK' if response.status_code in [200, 401] else 'FAIL')
" 2>/dev/null; then
        log "INFO" "✓ Ozon API connection test passed"
    else
        log "ERROR" "✗ Ozon API connection test failed"
        exit 1
    fi
    
    # Test Wildberries API
    if python3 -c "
import requests
import os
headers = {'Authorization': os.getenv('WB_API_KEY')}
response = requests.get('https://suppliers-api.wildberries.ru/api/v1/supplier/incomes', headers=headers, timeout=10)
print('WB API:', 'OK' if response.status_code in [200, 401] else 'FAIL')
" 2>/dev/null; then
        log "INFO" "✓ Wildberries API connection test passed"
    else
        log "ERROR" "✗ Wildberries API connection test failed"
        exit 1
    fi
}

# Deploy database migration
deploy_database() {
    log "INFO" "Deploying database migration..."
    
    # Run migration script
    if "$SCRIPT_DIR/migrate_production.sh" apply; then
        log "INFO" "✓ Database migration completed successfully"
    else
        log "ERROR" "✗ Database migration failed"
        exit 1
    fi
    
    # Validate migration
    if "$SCRIPT_DIR/migrate_production.sh" validate; then
        log "INFO" "✓ Database migration validation passed"
    else
        log "ERROR" "✗ Database migration validation failed"
        exit 1
    fi
}

# Deploy application code
deploy_application() {
    log "INFO" "Deploying application code..."
    
    # Create backup of current code
    if [ -d "$PROJECT_DIR" ]; then
        local backup_name="inventory_sync_backup_$(date +%Y%m%d_%H%M%S)"
        cp -r "$PROJECT_DIR" "$BACKUP_DIR/$backup_name"
        log "INFO" "Code backup created: $BACKUP_DIR/$backup_name"
    fi
    
    # Set proper permissions
    chmod +x "$PROJECT_DIR"/*.py 2>/dev/null || true
    chmod +x "$SCRIPT_DIR"/*.sh
    chmod 600 "$PROJECT_DIR/.env"
    
    # Test import of main modules
    if python3 -c "
import sys
sys.path.append('$PROJECT_DIR')
import inventory_sync_service
import sync_monitor
import sync_logger
print('All modules imported successfully')
" 2>/dev/null; then
        log "INFO" "✓ Application modules validation passed"
    else
        log "ERROR" "✗ Application modules validation failed"
        exit 1
    fi
}

# Setup cron jobs
setup_cron_jobs() {
    log "INFO" "Setting up cron jobs..."
    
    # Backup existing crontab
    if crontab -l > /dev/null 2>&1; then
        crontab -l > "$BACKUP_DIR/crontab_backup_$(date +%Y%m%d_%H%M%S).txt"
        log "INFO" "Existing crontab backed up"
    fi
    
    # Install new cron jobs
    if "$SCRIPT_DIR/setup_cron.sh" standard; then
        log "INFO" "✓ Cron jobs installed successfully"
    else
        log "ERROR" "✗ Cron jobs installation failed"
        exit 1
    fi
    
    # Verify cron service is running
    if ! systemctl is-active --quiet cron; then
        sudo systemctl start cron
        sudo systemctl enable cron
        log "INFO" "Cron service started and enabled"
    fi
}

# Setup monitoring
setup_monitoring() {
    log "INFO" "Setting up monitoring..."
    
    # Create monitoring script
    cat > "$SCRIPT_DIR/monitor_deployment.sh" << 'EOF'
#!/bin/bash
# Deployment monitoring script

LOG_FILE="/var/log/inventory_sync/monitor.log"
ALERT_EMAIL="${ALERT_EMAIL:-admin@yourcompany.com}"

# Check if sync service is running
check_sync_service() {
    local last_sync=$(mysql -h"${DB_HOST:-localhost}" -P"${DB_PORT:-3306}" -u"${DB_USER}" -p"${DB_PASSWORD}" "${DB_NAME}" -se "
        SELECT MAX(started_at) FROM sync_logs WHERE status = 'success' AND started_at > DATE_SUB(NOW(), INTERVAL 24 HOUR)
    " 2>/dev/null)
    
    if [ -z "$last_sync" ]; then
        echo "$(date): ERROR - No successful sync in last 24 hours" >> "$LOG_FILE"
        if command -v mail >/dev/null 2>&1; then
            echo "No successful inventory sync in last 24 hours" | mail -s "Inventory Sync Alert" "$ALERT_EMAIL"
        fi
        return 1
    fi
    
    echo "$(date): INFO - Last successful sync: $last_sync" >> "$LOG_FILE"
    return 0
}

# Check database health
check_database_health() {
    local product_count=$(mysql -h"${DB_HOST:-localhost}" -P"${DB_PORT:-3306}" -u"${DB_USER}" -p"${DB_PASSWORD}" "${DB_NAME}" -se "
        SELECT COUNT(*) FROM inventory_data WHERE last_sync_at > DATE_SUB(NOW(), INTERVAL 24 HOUR)
    " 2>/dev/null)
    
    if [ "$product_count" -lt 10 ]; then
        echo "$(date): WARNING - Low product count: $product_count" >> "$LOG_FILE"
        if command -v mail >/dev/null 2>&1; then
            echo "Low product count in inventory: $product_count" | mail -s "Inventory Count Alert" "$ALERT_EMAIL"
        fi
        return 1
    fi
    
    echo "$(date): INFO - Product count: $product_count" >> "$LOG_FILE"
    return 0
}

# Main monitoring function
main() {
    source "$(dirname "$0")/../.env" 2>/dev/null || true
    
    check_sync_service
    check_database_health
    
    echo "$(date): Monitoring check completed" >> "$LOG_FILE"
}

main "$@"
EOF
    
    chmod +x "$SCRIPT_DIR/monitor_deployment.sh"
    
    # Add monitoring to cron (every hour)
    (crontab -l 2>/dev/null; echo "0 * * * * $SCRIPT_DIR/monitor_deployment.sh") | crontab -
    
    log "INFO" "✓ Monitoring setup completed"
}

# Run deployment tests
run_deployment_tests() {
    log "INFO" "Running deployment tests..."
    
    # Test 1: Manual sync execution
    log "INFO" "Test 1: Manual sync execution"
    if timeout 300 python3 "$PROJECT_DIR/inventory_sync_service.py" --test-mode --source=ozon; then
        log "INFO" "✓ Manual sync test passed"
    else
        log "ERROR" "✗ Manual sync test failed"
        exit 1
    fi
    
    # Test 2: Monitoring script
    log "INFO" "Test 2: Monitoring script"
    if "$SCRIPT_DIR/monitor_deployment.sh"; then
        log "INFO" "✓ Monitoring test passed"
    else
        log "WARN" "⚠ Monitoring test failed (may be expected for new deployment)"
    fi
    
    # Test 3: Database connectivity
    log "INFO" "Test 3: Database connectivity"
    if python3 -c "
import sys
sys.path.append('$PROJECT_DIR')
from replenishment_db_connector import connect_to_replenishment_db
conn = connect_to_replenishment_db()
cursor = conn.cursor()
cursor.execute('SELECT COUNT(*) FROM inventory_data')
count = cursor.fetchone()[0]
print(f'Database test passed: {count} records in inventory_data')
conn.close()
"; then
        log "INFO" "✓ Database connectivity test passed"
    else
        log "ERROR" "✗ Database connectivity test failed"
        exit 1
    fi
    
    log "INFO" "All deployment tests passed"
}

# Health check
health_check() {
    log "INFO" "Performing health check..."
    
    local health_status=0
    
    # Check database connection
    if mysql -h"${DB_HOST:-localhost}" -P"${DB_PORT:-3306}" -u"${DB_USER}" -p"${DB_PASSWORD}" -e "SELECT 1;" "${DB_NAME}" >/dev/null 2>&1; then
        log "INFO" "✓ Database connection: OK"
    else
        log "ERROR" "✗ Database connection: FAILED"
        health_status=1
    fi
    
    # Check migration status
    local migration_status=$(mysql -h"${DB_HOST:-localhost}" -P"${DB_PORT:-3306}" -u"${DB_USER}" -p"${DB_PASSWORD}" "${DB_NAME}" -se "
        SELECT status FROM migration_log 
        WHERE migration_name = 'inventory_sync_production_migration_v1.0' 
        ORDER BY created_at DESC LIMIT 1
    " 2>/dev/null || echo "not_found")
    
    if [ "$migration_status" = "completed" ]; then
        log "INFO" "✓ Database migration: OK"
    else
        log "ERROR" "✗ Database migration: $migration_status"
        health_status=1
    fi
    
    # Check cron jobs
    if crontab -l | grep -q "inventory_sync_service.py"; then
        log "INFO" "✓ Cron jobs: OK"
    else
        log "ERROR" "✗ Cron jobs: NOT CONFIGURED"
        health_status=1
    fi
    
    # Check log files
    if [ -f "$DEPLOYMENT_LOG" ] && [ -w "$DEPLOYMENT_LOG" ]; then
        log "INFO" "✓ Log files: OK"
    else
        log "ERROR" "✗ Log files: NOT ACCESSIBLE"
        health_status=1
    fi
    
    # Check Python modules
    if python3 -c "import inventory_sync_service, sync_monitor, sync_logger" 2>/dev/null; then
        log "INFO" "✓ Python modules: OK"
    else
        log "ERROR" "✗ Python modules: IMPORT FAILED"
        health_status=1
    fi
    
    if [ $health_status -eq 0 ]; then
        log "INFO" "✓ Overall health check: PASSED"
    else
        log "ERROR" "✗ Overall health check: FAILED"
    fi
    
    return $health_status
}

# Show deployment status
show_status() {
    log "INFO" "=== DEPLOYMENT STATUS ==="
    
    # Database migration status
    local migration_status=$(mysql -h"${DB_HOST:-localhost}" -P"${DB_PORT:-3306}" -u"${DB_USER}" -p"${DB_PASSWORD}" "${DB_NAME}" -se "
        SELECT CONCAT(migration_name, ': ', status, ' (', 
               COALESCE(completed_at, 'in progress'), ')') 
        FROM migration_log 
        ORDER BY created_at DESC LIMIT 3
    " 2>/dev/null || echo "Migration log not available")
    
    log "INFO" "Migration status: $migration_status"
    
    # Cron jobs status
    local cron_count=$(crontab -l 2>/dev/null | grep -c "inventory_sync" || echo "0")
    log "INFO" "Cron jobs configured: $cron_count"
    
    # Last sync status
    local last_sync=$(mysql -h"${DB_HOST:-localhost}" -P"${DB_PORT:-3306}" -u"${DB_USER}" -p"${DB_PASSWORD}" "${DB_NAME}" -se "
        SELECT CONCAT(source, ': ', status, ' at ', started_at) 
        FROM sync_logs 
        ORDER BY started_at DESC LIMIT 3
    " 2>/dev/null || echo "No sync logs available")
    
    log "INFO" "Recent syncs: $last_sync"
    
    # System resources
    local disk_usage=$(df -h / | awk 'NR==2 {print $5}')
    local memory_usage=$(free -h | awk 'NR==2{printf "%.1f%%", $3/$2*100}')
    
    log "INFO" "System resources: Disk: $disk_usage, Memory: $memory_usage"
    
    log "INFO" "=== STATUS CHECK COMPLETED ==="
}

# Rollback deployment
rollback_deployment() {
    log "INFO" "=== ROLLING BACK DEPLOYMENT ==="
    
    # Confirm rollback
    echo -n "Are you sure you want to rollback the deployment? (yes/no): "
    read -r confirmation
    
    if [ "$confirmation" != "yes" ]; then
        log "INFO" "Rollback cancelled"
        exit 0
    fi
    
    # Rollback database
    log "INFO" "Rolling back database migration..."
    echo "yes" | "$SCRIPT_DIR/migrate_production.sh" rollback
    
    # Restore crontab
    local latest_crontab_backup=$(ls -t "$BACKUP_DIR"/crontab_backup_*.txt 2>/dev/null | head -1)
    if [ -n "$latest_crontab_backup" ]; then
        crontab "$latest_crontab_backup"
        log "INFO" "Crontab restored from: $latest_crontab_backup"
    fi
    
    # Restore code (if backup exists)
    local latest_code_backup=$(ls -td "$BACKUP_DIR"/inventory_sync_backup_* 2>/dev/null | head -1)
    if [ -n "$latest_code_backup" ]; then
        log "INFO" "Code backup available at: $latest_code_backup"
        log "INFO" "Manual code restoration may be required"
    fi
    
    log "INFO" "=== ROLLBACK COMPLETED ==="
    log "INFO" "Please verify system status and restart services if needed"
}

# Full deployment process
full_deployment() {
    log "INFO" "=== STARTING FULL DEPLOYMENT ==="
    
    check_user
    setup_directories
    check_prerequisites
    test_api_connections
    deploy_database
    deploy_application
    setup_cron_jobs
    setup_monitoring
    run_deployment_tests
    
    log "INFO" "=== DEPLOYMENT COMPLETED SUCCESSFULLY ==="
    log "INFO" ""
    log "INFO" "Next steps:"
    log "INFO" "1. Monitor logs: tail -f $DEPLOYMENT_LOG"
    log "INFO" "2. Check sync status in 6 hours: $0 status"
    log "INFO" "3. Verify data in dashboard"
    log "INFO" "4. Set up additional monitoring if needed"
    log "INFO" ""
    log "INFO" "To check health: $0 health-check"
    log "INFO" "To rollback: $0 rollback"
}

# Main function
main() {
    local action="${1:-help}"
    
    case "$action" in
        "deploy")
            full_deployment
            ;;
        "rollback")
            rollback_deployment
            ;;
        "status")
            show_status
            ;;
        "health-check")
            health_check
            ;;
        "help"|*)
            echo "Usage: $0 [deploy|rollback|status|health-check]"
            echo ""
            echo "Commands:"
            echo "  deploy       - Perform full production deployment"
            echo "  rollback     - Rollback the deployment"
            echo "  status       - Show deployment status"
            echo "  health-check - Perform system health check"
            echo "  help         - Show this help message"
            echo ""
            echo "Examples:"
            echo "  $0 deploy        # Deploy to production"
            echo "  $0 health-check  # Check system health"
            echo "  $0 status        # Show current status"
            echo "  $0 rollback      # Rollback deployment"
            echo ""
            echo "Prerequisites:"
            echo "  - .env file configured with production credentials"
            echo "  - Database accessible and credentials valid"
            echo "  - Python 3.8+ and required packages installed"
            echo "  - Sufficient disk space and permissions"
            exit 0
            ;;
    esac
}

# Trap errors
trap 'log "ERROR" "Deployment script failed at line $LINENO"' ERR

# Run main function
main "$@"