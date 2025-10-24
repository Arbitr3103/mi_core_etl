#!/bin/bash

# Production Backup Script for Ozon Warehouse Stock Reports
# Purpose: Automated database backup with retention policies
# Requirements: 5.5

set -e  # Exit on any error

# Configuration
DB_NAME="${DB_NAME:-mi_core_etl}"
DB_USER="${DB_BACKUP_USER:-root}"
DB_PASSWORD="${DB_PASSWORD}"
BACKUP_DIR="${BACKUP_DIR:-/var/backups/mysql}"
RETENTION_DAYS="${RETENTION_DAYS:-30}"
LOG_FILE="${LOG_FILE:-/var/log/ozon_backup.log}"

# Create backup directory if it doesn't exist
mkdir -p "$BACKUP_DIR"

# Function to log messages
log_message() {
    echo "$(date '+%Y-%m-%d %H:%M:%S') - $1" | tee -a "$LOG_FILE"
}

# Function to send notification (placeholder - implement based on your notification system)
send_notification() {
    local subject="$1"
    local message="$2"
    local level="${3:-INFO}"
    
    # Log to file
    log_message "[$level] $subject: $message"
    
    # TODO: Implement actual notification system (email, Slack, etc.)
    # Example: curl -X POST "https://hooks.slack.com/..." -d "{'text': '$subject: $message'}"
}

# Function to create database backup
create_backup() {
    local backup_date=$(date '+%Y%m%d_%H%M%S')
    local backup_filename="ozon_stock_reports_${backup_date}.sql"
    local backup_path="${BACKUP_DIR}/${backup_filename}"
    local compressed_path="${backup_path}.gz"
    
    log_message "Starting backup: $backup_filename"
    
    # Record backup start in database
    mysql -u"$DB_USER" -p"$DB_PASSWORD" "$DB_NAME" -e "
        INSERT INTO backup_history (backup_type, backup_filename, backup_status) 
        VALUES ('DAILY', '$backup_filename', 'STARTED');
    " 2>/dev/null || log_message "Warning: Could not log backup start to database"
    
    # Create backup with specific tables related to ozon stock reports
    if mysqldump -u"$DB_USER" -p"$DB_PASSWORD" \
        --single-transaction \
        --routines \
        --triggers \
        --events \
        --add-drop-table \
        --add-locks \
        --disable-keys \
        --extended-insert \
        "$DB_NAME" \
        ozon_stock_reports \
        stock_report_logs \
        backup_history \
        inventory \
        > "$backup_path"; then
        
        # Compress the backup
        gzip "$backup_path"
        
        # Get compressed file size
        local file_size=$(stat -f%z "$compressed_path" 2>/dev/null || stat -c%s "$compressed_path" 2>/dev/null || echo "0")
        
        log_message "Backup completed successfully: $compressed_path (${file_size} bytes)"
        
        # Record backup completion in database
        mysql -u"$DB_USER" -p"$DB_PASSWORD" "$DB_NAME" -e "
            UPDATE backup_history 
            SET backup_status = 'COMPLETED', 
                backup_end_time = NOW(), 
                backup_size = $file_size 
            WHERE backup_filename = '$backup_filename' 
            AND backup_status = 'STARTED';
        " 2>/dev/null || log_message "Warning: Could not update backup status in database"
        
        send_notification "Backup Success" "Daily backup completed: $backup_filename (${file_size} bytes)" "INFO"
        
        return 0
    else
        log_message "ERROR: Backup failed for $backup_filename"
        
        # Record backup failure in database
        mysql -u"$DB_USER" -p"$DB_PASSWORD" "$DB_NAME" -e "
            UPDATE backup_history 
            SET backup_status = 'FAILED', 
                backup_end_time = NOW(), 
                error_message = 'mysqldump command failed' 
            WHERE backup_filename = '$backup_filename' 
            AND backup_status = 'STARTED';
        " 2>/dev/null || log_message "Warning: Could not update backup failure status in database"
        
        send_notification "Backup Failed" "Daily backup failed: $backup_filename" "ERROR"
        
        return 1
    fi
}

# Function to cleanup old backups
cleanup_old_backups() {
    log_message "Starting cleanup of backups older than $RETENTION_DAYS days"
    
    local deleted_count=0
    
    # Find and delete old backup files
    if command -v find >/dev/null 2>&1; then
        while IFS= read -r -d '' file; do
            log_message "Deleting old backup: $file"
            rm -f "$file"
            ((deleted_count++))
        done < <(find "$BACKUP_DIR" -name "ozon_stock_reports_*.sql.gz" -mtime +$RETENTION_DAYS -print0 2>/dev/null)
    fi
    
    # Cleanup old backup history records
    mysql -u"$DB_USER" -p"$DB_PASSWORD" "$DB_NAME" -e "
        DELETE FROM backup_history 
        WHERE backup_start_time < DATE_SUB(NOW(), INTERVAL $RETENTION_DAYS DAY);
    " 2>/dev/null || log_message "Warning: Could not cleanup old backup history records"
    
    log_message "Cleanup completed. Deleted $deleted_count old backup files"
    
    if [ $deleted_count -gt 0 ]; then
        send_notification "Backup Cleanup" "Deleted $deleted_count old backup files" "INFO"
    fi
}

# Function to verify backup integrity
verify_backup() {
    local latest_backup=$(ls -t "$BACKUP_DIR"/ozon_stock_reports_*.sql.gz 2>/dev/null | head -n1)
    
    if [ -n "$latest_backup" ]; then
        log_message "Verifying backup integrity: $latest_backup"
        
        # Test if the gzip file is valid
        if gzip -t "$latest_backup" 2>/dev/null; then
            log_message "Backup integrity check passed: $latest_backup"
            return 0
        else
            log_message "ERROR: Backup integrity check failed: $latest_backup"
            send_notification "Backup Integrity Failed" "Latest backup file is corrupted: $latest_backup" "ERROR"
            return 1
        fi
    else
        log_message "WARNING: No backup files found for verification"
        send_notification "No Backups Found" "No backup files found in $BACKUP_DIR" "WARNING"
        return 1
    fi
}

# Function to show backup status
show_backup_status() {
    log_message "=== Backup Status Report ==="
    
    # Show disk usage
    local disk_usage=$(du -sh "$BACKUP_DIR" 2>/dev/null | cut -f1 || echo "Unknown")
    log_message "Backup directory size: $disk_usage"
    
    # Show recent backups
    log_message "Recent backup files:"
    ls -lah "$BACKUP_DIR"/ozon_stock_reports_*.sql.gz 2>/dev/null | tail -5 | while read line; do
        log_message "  $line"
    done
    
    # Show backup history from database
    mysql -u"$DB_USER" -p"$DB_PASSWORD" "$DB_NAME" -e "
        SELECT 
            backup_filename,
            backup_status,
            ROUND(backup_size/1024/1024, 2) as size_mb,
            backup_start_time,
            backup_end_time
        FROM backup_history 
        ORDER BY backup_start_time DESC 
        LIMIT 10;
    " 2>/dev/null || log_message "Could not retrieve backup history from database"
}

# Main execution
main() {
    local action="${1:-backup}"
    
    case "$action" in
        "backup")
            log_message "=== Starting Daily Backup Process ==="
            create_backup
            cleanup_old_backups
            verify_backup
            show_backup_status
            log_message "=== Daily Backup Process Completed ==="
            ;;
        "cleanup")
            log_message "=== Starting Backup Cleanup ==="
            cleanup_old_backups
            log_message "=== Backup Cleanup Completed ==="
            ;;
        "verify")
            log_message "=== Starting Backup Verification ==="
            verify_backup
            log_message "=== Backup Verification Completed ==="
            ;;
        "status")
            show_backup_status
            ;;
        *)
            echo "Usage: $0 {backup|cleanup|verify|status}"
            echo "  backup  - Create daily backup and cleanup old files"
            echo "  cleanup - Only cleanup old backup files"
            echo "  verify  - Verify latest backup integrity"
            echo "  status  - Show backup status and history"
            exit 1
            ;;
    esac
}

# Check if required environment variables are set
if [ -z "$DB_PASSWORD" ]; then
    echo "ERROR: DB_PASSWORD environment variable is required"
    exit 1
fi

# Run main function
main "$@"