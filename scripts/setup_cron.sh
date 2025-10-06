#!/bin/bash

# Setup Cron Jobs for Inventory Sync System
# Usage: ./setup_cron.sh [high-frequency|low-resource|standard]

set -e

# Configuration
PROJECT_PATH="/path/to/project"
LOG_DIR="/var/log/inventory_sync"
CRON_DIR="$(dirname "$0")/../cron_examples"

# Colors for output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
NC='\033[0m' # No Color

# Functions
log_info() {
    echo -e "${GREEN}[INFO]${NC} $1"
}

log_warn() {
    echo -e "${YELLOW}[WARN]${NC} $1"
}

log_error() {
    echo -e "${RED}[ERROR]${NC} $1"
}

# Check if running as correct user
check_user() {
    if [ "$EUID" -eq 0 ]; then
        log_error "Do not run this script as root. Run as the user who will execute the cron jobs."
        exit 1
    fi
}

# Create log directory
setup_log_directory() {
    log_info "Setting up log directory..."
    
    if [ ! -d "$LOG_DIR" ]; then
        sudo mkdir -p "$LOG_DIR"
        sudo chown "$USER:$USER" "$LOG_DIR"
        log_info "Created log directory: $LOG_DIR"
    else
        log_info "Log directory already exists: $LOG_DIR"
    fi
    
    # Set proper permissions
    sudo chmod 755 "$LOG_DIR"
}

# Update project path in cron files
update_project_path() {
    local cron_file="$1"
    local temp_file=$(mktemp)
    
    # Get actual project path
    local actual_path=$(cd "$(dirname "$0")/.." && pwd)
    
    log_info "Updating project path to: $actual_path"
    
    # Replace placeholder with actual path
    sed "s|/path/to/project|$actual_path|g" "$cron_file" > "$temp_file"
    
    echo "$temp_file"
}

# Install cron jobs
install_cron() {
    local cron_type="$1"
    local cron_file=""
    
    case "$cron_type" in
        "high-frequency")
            cron_file="$CRON_DIR/high_frequency_crontab.txt"
            log_info "Installing high-frequency cron configuration..."
            ;;
        "low-resource")
            cron_file="$CRON_DIR/low_resource_crontab.txt"
            log_info "Installing low-resource cron configuration..."
            ;;
        "standard"|*)
            cron_file="$CRON_DIR/inventory_sync_crontab.txt"
            log_info "Installing standard cron configuration..."
            ;;
    esac
    
    if [ ! -f "$cron_file" ]; then
        log_error "Cron file not found: $cron_file"
        exit 1
    fi
    
    # Update paths in cron file
    local updated_cron_file=$(update_project_path "$cron_file")
    
    # Backup existing crontab
    if crontab -l > /dev/null 2>&1; then
        log_info "Backing up existing crontab..."
        crontab -l > "crontab_backup_$(date +%Y%m%d_%H%M%S).txt"
    fi
    
    # Install new crontab
    crontab "$updated_cron_file"
    
    # Cleanup temp file
    rm "$updated_cron_file"
    
    log_info "Cron jobs installed successfully!"
}

# Verify cron installation
verify_cron() {
    log_info "Verifying cron installation..."
    
    # Check if cron service is running
    if ! systemctl is-active --quiet cron; then
        log_warn "Cron service is not running. Starting it..."
        sudo systemctl start cron
        sudo systemctl enable cron
    fi
    
    # Display installed cron jobs
    log_info "Installed cron jobs:"
    crontab -l | grep -v "^#" | grep -v "^$" || log_warn "No cron jobs found"
}

# Setup log rotation
setup_logrotate() {
    log_info "Setting up log rotation..."
    
    local logrotate_config="/etc/logrotate.d/inventory_sync"
    
    if [ ! -f "$logrotate_config" ]; then
        sudo tee "$logrotate_config" > /dev/null << EOF
$LOG_DIR/*.log {
    daily
    rotate 30
    compress
    delaycompress
    missingok
    notifempty
    create 644 $USER $USER
    postrotate
        /usr/bin/systemctl reload rsyslog > /dev/null 2>&1 || true
    endscript
}
EOF
        log_info "Log rotation configured: $logrotate_config"
    else
        log_info "Log rotation already configured"
    fi
}

# Create helper scripts
create_helper_scripts() {
    log_info "Creating helper scripts..."
    
    local scripts_dir="$(dirname "$0")"
    
    # Create log monitoring script
    cat > "$scripts_dir/check_log_size.sh" << 'EOF'
#!/bin/bash
# Check and alert if log files are too large

LOG_DIR="/var/log/inventory_sync"
MAX_SIZE_MB=100
ALERT_EMAIL="${ALERT_EMAIL:-admin@yourcompany.com}"

for log_file in "$LOG_DIR"/*.log; do
    if [ -f "$log_file" ]; then
        size_mb=$(du -m "$log_file" | cut -f1)
        if [ "$size_mb" -gt "$MAX_SIZE_MB" ]; then
            echo "WARNING: Log file $log_file is ${size_mb}MB (exceeds ${MAX_SIZE_MB}MB limit)"
            if command -v mail >/dev/null 2>&1; then
                echo "Log file $log_file has grown to ${size_mb}MB" | mail -s "Large Log File Alert" "$ALERT_EMAIL"
            fi
        fi
    fi
done
EOF
    
    chmod +x "$scripts_dir/check_log_size.sh"
    
    # Create backup script
    cat > "$scripts_dir/backup_inventory_data.sh" << 'EOF'
#!/bin/bash
# Backup inventory data

source "$(dirname "$0")/../.env" 2>/dev/null || true

BACKUP_DIR="${BACKUP_DIR:-/backup/inventory_sync}"
DATE=$(date +%Y%m%d_%H%M%S)

mkdir -p "$BACKUP_DIR"

if [ -n "$DB_PASSWORD" ]; then
    mysqldump -u "${DB_USER:-inventory_sync_user}" -p"$DB_PASSWORD" "${DB_NAME:-replenishment_db}" inventory_data sync_logs > "$BACKUP_DIR/inventory_backup_$DATE.sql"
    
    # Compress backup
    gzip "$BACKUP_DIR/inventory_backup_$DATE.sql"
    
    # Remove old backups (older than 7 days)
    find "$BACKUP_DIR" -name "*.sql.gz" -mtime +7 -delete
    
    echo "Backup completed: $BACKUP_DIR/inventory_backup_$DATE.sql.gz"
else
    echo "ERROR: Database credentials not found in .env file"
    exit 1
fi
EOF
    
    chmod +x "$scripts_dir/backup_inventory_data.sh"
    
    log_info "Helper scripts created in $scripts_dir"
}

# Main function
main() {
    local cron_type="${1:-standard}"
    
    log_info "Setting up Inventory Sync cron jobs..."
    log_info "Configuration type: $cron_type"
    
    check_user
    setup_log_directory
    install_cron "$cron_type"
    verify_cron
    setup_logrotate
    create_helper_scripts
    
    log_info "Setup completed successfully!"
    log_info ""
    log_info "Next steps:"
    log_info "1. Update the project path in the installed cron jobs if needed"
    log_info "2. Verify that .env file contains correct database credentials"
    log_info "3. Test the sync manually: python inventory_sync_service.py --test"
    log_info "4. Monitor logs in: $LOG_DIR"
    log_info ""
    log_info "To view installed cron jobs: crontab -l"
    log_info "To edit cron jobs: crontab -e"
}

# Show usage if help requested
if [ "$1" = "-h" ] || [ "$1" = "--help" ]; then
    echo "Usage: $0 [high-frequency|low-resource|standard]"
    echo ""
    echo "Options:"
    echo "  standard       - Sync every 6 hours (default)"
    echo "  high-frequency - Sync every 2 hours"
    echo "  low-resource   - Sync twice daily"
    echo ""
    echo "Examples:"
    echo "  $0                    # Install standard configuration"
    echo "  $0 high-frequency     # Install high-frequency configuration"
    echo "  $0 low-resource       # Install low-resource configuration"
    exit 0
fi

# Run main function
main "$@"