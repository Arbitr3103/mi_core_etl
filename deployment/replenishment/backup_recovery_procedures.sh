#!/bin/bash

# ============================================================================
# REPLENISHMENT SYSTEM BACKUP AND RECOVERY PROCEDURES
# ============================================================================
# Description: Comprehensive backup and recovery scripts for replenishment system
# Version: 1.0.0
# Date: 2025-10-17
# Usage: ./backup_recovery_procedures.sh [backup|restore|schedule] [options]
# ============================================================================

set -e

# Configuration
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_ROOT="$(cd "$SCRIPT_DIR/../.." && pwd)"
BACKUP_BASE_DIR="/var/backups/replenishment"
LOG_FILE="$BACKUP_BASE_DIR/backup.log"

# Default settings
DEFAULT_RETENTION_DAYS=30
DEFAULT_COMPRESSION=true

# Colors
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m'

# ============================================================================
# LOGGING FUNCTIONS
# ============================================================================

log() {
    echo -e "${GREEN}[$(date +'%Y-%m-%d %H:%M:%S')]${NC} $1" | tee -a "$LOG_FILE"
}

error() {
    echo -e "${RED}[$(date +'%Y-%m-%d %H:%M:%S')] ERROR:${NC} $1" | tee -a "$LOG_FILE"
}

warning() {
    echo -e "${YELLOW}[$(date +'%Y-%m-%d %H:%M:%S')] WARNING:${NC} $1" | tee -a "$LOG_FILE"
}

info() {
    echo -e "${BLUE}[$(date +'%Y-%m-%d %H:%M:%S')] INFO:${NC} $1" | tee -a "$LOG_FILE"
}

# ============================================================================
# UTILITY FUNCTIONS
# ============================================================================

get_db_credentials() {
    if [ -f "$PROJECT_ROOT/config.php" ]; then
        php -r "
        require_once '$PROJECT_ROOT/config.php';
        echo DB_HOST . '|' . DB_USER . '|' . DB_PASSWORD . '|' . DB_NAME;
        " 2>/dev/null
    else
        error "config.php not found"
        exit 1
    fi
}

create_backup_dir() {
    local backup_dir="$1"
    mkdir -p "$backup_dir"
    chmod 755 "$backup_dir"
    log "Created backup directory: $backup_dir"
}

compress_file() {
    local file="$1"
    if [ "$DEFAULT_COMPRESSION" = true ] && [ -f "$file" ]; then
        gzip "$file"
        log "Compressed: $file"
        echo "${file}.gz"
    else
        echo "$file"
    fi
}

# ============================================================================
# BACKUP FUNCTIONS
# ============================================================================

backup_database() {
    local backup_dir="$1"
    local timestamp="$2"
    
    log "Starting database backup..."
    
    # Get database credentials
    local db_config=$(get_db_credentials)
    IFS='|' read -r DB_HOST DB_USER DB_PASSWORD DB_NAME <<< "$db_config"
    
    # Backup replenishment tables
    local replenishment_backup="$backup_dir/replenishment_tables_$timestamp.sql"
    
    # Get list of replenishment tables
    local tables=$(mysql -h"$DB_HOST" -u"$DB_USER" -p"$DB_PASSWORD" "$DB_NAME" -e "SHOW TABLES LIKE 'replenishment_%';" | tail -n +2 | tr '\n' ' ')
    
    if [ -n "$tables" ]; then
        mysqldump -h"$DB_HOST" -u"$DB_USER" -p"$DB_PASSWORD" \
            --single-transaction \
            --routines \
            --triggers \
            --events \
            --add-drop-table \
            --create-options \
            --disable-keys \
            --extended-insert \
            "$DB_NAME" $tables > "$replenishment_backup"
        
        local compressed_file=$(compress_file "$replenishment_backup")
        log "✓ Database backup completed: $(basename "$compressed_file")"
        
        # Backup configuration table separately for quick restore
        local config_backup="$backup_dir/replenishment_config_$timestamp.sql"
        mysqldump -h"$DB_HOST" -u"$DB_USER" -p"$DB_PASSWORD" \
            --single-transaction \
            "$DB_NAME" replenishment_config > "$config_backup"
        
        compress_file "$config_backup"
        log "✓ Configuration backup completed"
    else
        warning "No replenishment tables found to backup"
    fi
    
    # Backup related data (fact_orders, inventory_data samples)
    local related_backup="$backup_dir/related_data_sample_$timestamp.sql"
    mysql -h"$DB_HOST" -u"$DB_USER" -p"$DB_PASSWORD" "$DB_NAME" -e "
    SELECT 'Sample of fact_orders for last 30 days' as info;
    " > "$related_backup"
    
    mysqldump -h"$DB_HOST" -u"$DB_USER" -p"$DB_PASSWORD" \
        --single-transaction \
        --where="order_date >= DATE_SUB(NOW(), INTERVAL 30 DAY)" \
        "$DB_NAME" fact_orders >> "$related_backup" 2>/dev/null || true
    
    compress_file "$related_backup"
    log "✓ Related data sample backup completed"
}

backup_application_files() {
    local backup_dir="$1"
    local timestamp="$2"
    
    log "Starting application files backup..."
    
    # Backup replenishment source code
    local src_backup="$backup_dir/replenishment_src_$timestamp.tar"
    if [ -d "$PROJECT_ROOT/src/Replenishment" ]; then
        tar -cf "$src_backup" -C "$PROJECT_ROOT" src/Replenishment/
        compress_file "$src_backup"
        log "✓ Source code backup completed"
    fi
    
    # Backup API files
    local api_backup="$backup_dir/replenishment_api_$timestamp.tar"
    if [ -f "$PROJECT_ROOT/api/replenishment.php" ]; then
        tar -cf "$api_backup" -C "$PROJECT_ROOT" api/replenishment.php
        compress_file "$api_backup"
        log "✓ API files backup completed"
    fi
    
    # Backup dashboard files
    local dashboard_backup="$backup_dir/replenishment_dashboard_$timestamp.tar"
    if [ -f "$PROJECT_ROOT/html/replenishment_dashboard.php" ]; then
        tar -cf "$dashboard_backup" -C "$PROJECT_ROOT" \
            html/replenishment_dashboard.php \
            html/monitoring_dashboard.php 2>/dev/null || true
        compress_file "$dashboard_backup"
        log "✓ Dashboard files backup completed"
    fi
    
    # Backup cron and script files
    local scripts_backup="$backup_dir/replenishment_scripts_$timestamp.tar"
    tar -cf "$scripts_backup" -C "$PROJECT_ROOT" \
        cron_replenishment_weekly.php \
        scripts/health_check_replenishment.sh \
        scripts/performance_monitor_replenishment.sh \
        scripts/send_alert_email.sh \
        scripts/send_slack_alert.sh 2>/dev/null || true
    compress_file "$scripts_backup"
    log "✓ Scripts backup completed"
}

backup_configuration() {
    local backup_dir="$1"
    local timestamp="$2"
    
    log "Starting configuration backup..."
    
    # Backup configuration files
    local config_backup="$backup_dir/replenishment_config_files_$timestamp.tar"
    tar -cf "$config_backup" -C "$PROJECT_ROOT" \
        config.php \
        .env 2>/dev/null || true
    
    # Add deployment configuration
    if [ -d "$PROJECT_ROOT/deployment/replenishment" ]; then
        tar -rf "$config_backup" -C "$PROJECT_ROOT" deployment/replenishment/ 2>/dev/null || true
    fi
    
    compress_file "$config_backup"
    log "✓ Configuration backup completed"
    
    # Backup crontab
    local cron_backup="$backup_dir/crontab_$timestamp.txt"
    crontab -l > "$cron_backup" 2>/dev/null || echo "# No crontab found" > "$cron_backup"
    compress_file "$cron_backup"
    log "✓ Crontab backup completed"
}

backup_logs() {
    local backup_dir="$1"
    local timestamp="$2"
    
    log "Starting logs backup..."
    
    # Backup application logs
    if [ -d "$PROJECT_ROOT/logs/replenishment" ]; then
        local logs_backup="$backup_dir/replenishment_logs_$timestamp.tar"
        tar -cf "$logs_backup" -C "$PROJECT_ROOT" logs/replenishment/
        compress_file "$logs_backup"
        log "✓ Application logs backup completed"
    fi
    
    # Backup system logs (if accessible)
    if [ -d "/var/log/replenishment" ]; then
        local system_logs_backup="$backup_dir/system_logs_$timestamp.tar"
        tar -cf "$system_logs_backup" -C "/" var/log/replenishment/ 2>/dev/null || true
        compress_file "$system_logs_backup"
        log "✓ System logs backup completed"
    fi
}

create_backup_manifest() {
    local backup_dir="$1"
    local timestamp="$2"
    
    log "Creating backup manifest..."
    
    local manifest_file="$backup_dir/backup_manifest_$timestamp.txt"
    
    cat > "$manifest_file" << EOF
# Replenishment System Backup Manifest
# Created: $(date)
# Backup Directory: $backup_dir
# Timestamp: $timestamp

## System Information
Hostname: $(hostname)
OS: $(uname -a)
PHP Version: $(php -v | head -n1)
MySQL Version: $(mysql --version)

## Backup Contents
EOF

    # List all backup files with sizes
    find "$backup_dir" -name "*_$timestamp.*" -type f -exec ls -lh {} \; >> "$manifest_file"
    
    # Add checksums
    echo "" >> "$manifest_file"
    echo "## File Checksums (SHA256)" >> "$manifest_file"
    find "$backup_dir" -name "*_$timestamp.*" -type f -exec sha256sum {} \; >> "$manifest_file"
    
    log "✓ Backup manifest created: $(basename "$manifest_file")"
}

# ============================================================================
# MAIN BACKUP FUNCTION
# ============================================================================

perform_full_backup() {
    local backup_type="${1:-full}"
    local custom_dir="$2"
    
    log "Starting $backup_type backup..."
    
    # Create timestamp
    local timestamp=$(date +%Y%m%d_%H%M%S)
    
    # Determine backup directory
    local backup_dir
    if [ -n "$custom_dir" ]; then
        backup_dir="$custom_dir/backup_$timestamp"
    else
        backup_dir="$BACKUP_BASE_DIR/backup_$timestamp"
    fi
    
    create_backup_dir "$backup_dir"
    
    # Ensure log directory exists
    mkdir -p "$(dirname "$LOG_FILE")"
    
    # Perform backup components
    case "$backup_type" in
        "full")
            backup_database "$backup_dir" "$timestamp"
            backup_application_files "$backup_dir" "$timestamp"
            backup_configuration "$backup_dir" "$timestamp"
            backup_logs "$backup_dir" "$timestamp"
            ;;
        "database")
            backup_database "$backup_dir" "$timestamp"
            ;;
        "files")
            backup_application_files "$backup_dir" "$timestamp"
            backup_configuration "$backup_dir" "$timestamp"
            ;;
        "config")
            backup_configuration "$backup_dir" "$timestamp"
            ;;
        *)
            error "Unknown backup type: $backup_type"
            exit 1
            ;;
    esac
    
    # Create manifest
    create_backup_manifest "$backup_dir" "$timestamp"
    
    # Set permissions
    chmod -R 640 "$backup_dir"
    
    log "✅ Backup completed successfully: $backup_dir"
    
    # Return backup directory for use by calling scripts
    echo "$backup_dir"
}

# ============================================================================
# RESTORE FUNCTIONS
# ============================================================================

list_available_backups() {
    log "Available backups:"
    
    if [ -d "$BACKUP_BASE_DIR" ]; then
        find "$BACKUP_BASE_DIR" -name "backup_*" -type d | sort -r | head -10 | while read -r backup_dir; do
            local timestamp=$(basename "$backup_dir" | sed 's/backup_//')
            local size=$(du -sh "$backup_dir" | cut -f1)
            local date_formatted=$(date -d "${timestamp:0:8} ${timestamp:9:2}:${timestamp:11:2}:${timestamp:13:2}" '+%Y-%m-%d %H:%M:%S' 2>/dev/null || echo "$timestamp")
            
            echo "  $backup_dir ($size) - $date_formatted"
            
            # Show manifest if available
            local manifest=$(find "$backup_dir" -name "backup_manifest_*.txt" | head -1)
            if [ -f "$manifest" ]; then
                echo "    Manifest: $(basename "$manifest")"
            fi
        done
    else
        warning "No backup directory found: $BACKUP_BASE_DIR"
    fi
}

restore_database() {
    local backup_dir="$1"
    local force="$2"
    
    log "Starting database restore from: $backup_dir"
    
    # Find database backup file
    local db_backup=$(find "$backup_dir" -name "replenishment_tables_*.sql*" | head -1)
    
    if [ -z "$db_backup" ]; then
        error "No database backup found in: $backup_dir"
        return 1
    fi
    
    # Get database credentials
    local db_config=$(get_db_credentials)
    IFS='|' read -r DB_HOST DB_USER DB_PASSWORD DB_NAME <<< "$db_config"
    
    # Confirm restore (unless forced)
    if [ "$force" != "--force" ]; then
        echo -n "This will overwrite existing replenishment tables. Continue? (y/N): "
        read -r confirm
        if [ "$confirm" != "y" ] && [ "$confirm" != "Y" ]; then
            log "Database restore cancelled by user"
            return 1
        fi
    fi
    
    # Decompress if needed
    local restore_file="$db_backup"
    if [[ "$db_backup" == *.gz ]]; then
        restore_file="${db_backup%.gz}"
        gunzip -c "$db_backup" > "$restore_file"
    fi
    
    # Restore database
    mysql -h"$DB_HOST" -u"$DB_USER" -p"$DB_PASSWORD" "$DB_NAME" < "$restore_file"
    
    # Clean up temporary file
    if [[ "$db_backup" == *.gz ]]; then
        rm -f "$restore_file"
    fi
    
    log "✓ Database restore completed"
}

restore_application_files() {
    local backup_dir="$1"
    local force="$2"
    
    log "Starting application files restore from: $backup_dir"
    
    # Confirm restore (unless forced)
    if [ "$force" != "--force" ]; then
        echo -n "This will overwrite existing application files. Continue? (y/N): "
        read -r confirm
        if [ "$confirm" != "y" ] && [ "$confirm" != "Y" ]; then
            log "Application files restore cancelled by user"
            return 1
        fi
    fi
    
    # Restore source code
    local src_backup=$(find "$backup_dir" -name "replenishment_src_*.tar*" | head -1)
    if [ -n "$src_backup" ]; then
        if [[ "$src_backup" == *.gz ]]; then
            gunzip -c "$src_backup" | tar -xf - -C "$PROJECT_ROOT"
        else
            tar -xf "$src_backup" -C "$PROJECT_ROOT"
        fi
        log "✓ Source code restored"
    fi
    
    # Restore API files
    local api_backup=$(find "$backup_dir" -name "replenishment_api_*.tar*" | head -1)
    if [ -n "$api_backup" ]; then
        if [[ "$api_backup" == *.gz ]]; then
            gunzip -c "$api_backup" | tar -xf - -C "$PROJECT_ROOT"
        else
            tar -xf "$api_backup" -C "$PROJECT_ROOT"
        fi
        log "✓ API files restored"
    fi
    
    # Restore dashboard files
    local dashboard_backup=$(find "$backup_dir" -name "replenishment_dashboard_*.tar*" | head -1)
    if [ -n "$dashboard_backup" ]; then
        if [[ "$dashboard_backup" == *.gz ]]; then
            gunzip -c "$dashboard_backup" | tar -xf - -C "$PROJECT_ROOT"
        else
            tar -xf "$dashboard_backup" -C "$PROJECT_ROOT"
        fi
        log "✓ Dashboard files restored"
    fi
    
    # Restore scripts
    local scripts_backup=$(find "$backup_dir" -name "replenishment_scripts_*.tar*" | head -1)
    if [ -n "$scripts_backup" ]; then
        if [[ "$scripts_backup" == *.gz ]]; then
            gunzip -c "$scripts_backup" | tar -xf - -C "$PROJECT_ROOT"
        else
            tar -xf "$scripts_backup" -C "$PROJECT_ROOT"
        fi
        chmod +x "$PROJECT_ROOT/cron_replenishment_weekly.php"
        chmod +x "$PROJECT_ROOT/scripts/"*.sh 2>/dev/null || true
        log "✓ Scripts restored"
    fi
    
    log "✓ Application files restore completed"
}

restore_configuration() {
    local backup_dir="$1"
    local force="$2"
    
    log "Starting configuration restore from: $backup_dir"
    
    # Find configuration backup
    local config_backup=$(find "$backup_dir" -name "replenishment_config_files_*.tar*" | head -1)
    
    if [ -z "$config_backup" ]; then
        warning "No configuration backup found in: $backup_dir"
        return 1
    fi
    
    # Confirm restore (unless forced)
    if [ "$force" != "--force" ]; then
        echo -n "This will overwrite existing configuration files. Continue? (y/N): "
        read -r confirm
        if [ "$confirm" != "y" ] && [ "$confirm" != "Y" ]; then
            log "Configuration restore cancelled by user"
            return 1
        fi
    fi
    
    # Backup current configuration
    if [ -f "$PROJECT_ROOT/config.php" ]; then
        cp "$PROJECT_ROOT/config.php" "$PROJECT_ROOT/config.php.backup.$(date +%Y%m%d_%H%M%S)"
    fi
    
    # Restore configuration
    if [[ "$config_backup" == *.gz ]]; then
        gunzip -c "$config_backup" | tar -xf - -C "$PROJECT_ROOT"
    else
        tar -xf "$config_backup" -C "$PROJECT_ROOT"
    fi
    
    log "✓ Configuration restore completed"
}

# ============================================================================
# CLEANUP FUNCTIONS
# ============================================================================

cleanup_old_backups() {
    local retention_days="${1:-$DEFAULT_RETENTION_DAYS}"
    
    log "Cleaning up backups older than $retention_days days..."
    
    if [ -d "$BACKUP_BASE_DIR" ]; then
        local deleted_count=0
        
        find "$BACKUP_BASE_DIR" -name "backup_*" -type d -mtime +$retention_days | while read -r old_backup; do
            log "Removing old backup: $(basename "$old_backup")"
            rm -rf "$old_backup"
            ((deleted_count++))
        done
        
        log "✓ Cleanup completed. Removed $deleted_count old backups"
    else
        warning "Backup directory not found: $BACKUP_BASE_DIR"
    fi
}

# ============================================================================
# SCHEDULING FUNCTIONS
# ============================================================================

setup_backup_schedule() {
    log "Setting up backup schedule..."
    
    # Create backup script
    local backup_script="/usr/local/bin/replenishment_backup.sh"
    
    cat > "$backup_script" << EOF
#!/bin/bash
# Automated replenishment system backup
cd "$SCRIPT_DIR"
./backup_recovery_procedures.sh backup full
./backup_recovery_procedures.sh cleanup 30
EOF
    
    chmod +x "$backup_script"
    
    # Add to crontab
    local cron_entry="0 2 * * * $backup_script >> $LOG_FILE 2>&1"
    
    # Check if entry already exists
    if ! crontab -l 2>/dev/null | grep -q "$backup_script"; then
        (crontab -l 2>/dev/null; echo "$cron_entry") | crontab -
        log "✓ Backup schedule added to crontab (daily at 2 AM)"
    else
        log "✓ Backup schedule already exists in crontab"
    fi
    
    info "Backup script created: $backup_script"
    info "Log file: $LOG_FILE"
}

# ============================================================================
# MAIN FUNCTION
# ============================================================================

show_usage() {
    echo "Usage: $0 [command] [options]"
    echo ""
    echo "Commands:"
    echo "  backup [type] [directory]  - Create backup (types: full, database, files, config)"
    echo "  restore [backup_dir]       - Restore from backup directory"
    echo "  list                       - List available backups"
    echo "  cleanup [days]             - Remove backups older than specified days"
    echo "  schedule                   - Setup automated backup schedule"
    echo ""
    echo "Options:"
    echo "  --force                    - Skip confirmation prompts"
    echo "  --no-compression           - Disable compression"
    echo ""
    echo "Examples:"
    echo "  $0 backup full"
    echo "  $0 backup database /tmp/my_backup"
    echo "  $0 restore /var/backups/replenishment/backup_20231017_120000"
    echo "  $0 cleanup 30"
    echo "  $0 schedule"
}

main() {
    local command="$1"
    shift || true
    
    # Parse options
    while [[ $# -gt 0 ]]; do
        case $1 in
            --force)
                FORCE_MODE="--force"
                shift
                ;;
            --no-compression)
                DEFAULT_COMPRESSION=false
                shift
                ;;
            *)
                break
                ;;
        esac
    done
    
    case "$command" in
        "backup")
            local backup_type="${1:-full}"
            local backup_dir="$2"
            perform_full_backup "$backup_type" "$backup_dir"
            ;;
        "restore")
            local backup_dir="$1"
            if [ -z "$backup_dir" ]; then
                error "Please specify backup directory"
                list_available_backups
                exit 1
            fi
            
            if [ ! -d "$backup_dir" ]; then
                error "Backup directory not found: $backup_dir"
                exit 1
            fi
            
            restore_database "$backup_dir" "$FORCE_MODE"
            restore_application_files "$backup_dir" "$FORCE_MODE"
            restore_configuration "$backup_dir" "$FORCE_MODE"
            log "✅ Restore completed successfully"
            ;;
        "list")
            list_available_backups
            ;;
        "cleanup")
            local retention_days="${1:-$DEFAULT_RETENTION_DAYS}"
            cleanup_old_backups "$retention_days"
            ;;
        "schedule")
            setup_backup_schedule
            ;;
        *)
            show_usage
            exit 1
            ;;
    esac
}

# ============================================================================
# SCRIPT EXECUTION
# ============================================================================

# Ensure backup directory exists
mkdir -p "$BACKUP_BASE_DIR"

# Run main function
main "$@"