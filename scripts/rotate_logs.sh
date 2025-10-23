#!/bin/bash

# Log Rotation Script for Warehouse Dashboard
# This script should be run daily via cron to manage log files

set -e

# Configuration
LOG_DIR="${LOG_PATH:-/var/log/warehouse-dashboard}"
MAX_LOG_SIZE="${LOG_MAX_SIZE:-100MB}"
MAX_LOG_FILES="${LOG_MAX_FILES:-30}"
COMPRESS_LOGS="${COMPRESS_LOGS:-true}"

# Colors for output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
NC='\033[0m' # No Color

# Logging function
log() {
    echo -e "${GREEN}[$(date '+%Y-%m-%d %H:%M:%S')]${NC} $1"
}

error() {
    echo -e "${RED}[$(date '+%Y-%m-%d %H:%M:%S')] ERROR:${NC} $1" >&2
}

warning() {
    echo -e "${YELLOW}[$(date '+%Y-%m-%d %H:%M:%S')] WARNING:${NC} $1"
}

# Convert size string to bytes
size_to_bytes() {
    local size=$1
    case $size in
        *KB) echo $((${size%KB} * 1024)) ;;
        *MB) echo $((${size%MB} * 1024 * 1024)) ;;
        *GB) echo $((${size%GB} * 1024 * 1024 * 1024)) ;;
        *) echo $size ;;
    esac
}

# Check if directory exists
if [ ! -d "$LOG_DIR" ]; then
    error "Log directory $LOG_DIR does not exist"
    exit 1
fi

log "Starting log rotation for $LOG_DIR"

# Convert max size to bytes
MAX_SIZE_BYTES=$(size_to_bytes "$MAX_LOG_SIZE")

# Find and rotate large log files
find "$LOG_DIR" -name "*.log" -type f | while read -r logfile; do
    if [ -f "$logfile" ]; then
        file_size=$(stat -f%z "$logfile" 2>/dev/null || stat -c%s "$logfile" 2>/dev/null || echo 0)
        
        if [ "$file_size" -gt "$MAX_SIZE_BYTES" ]; then
            log "Rotating $logfile (size: $(($file_size / 1024 / 1024))MB)"
            
            # Create backup filename with timestamp
            backup_file="${logfile}.$(date +%Y%m%d_%H%M%S)"
            
            # Move current log to backup
            mv "$logfile" "$backup_file"
            
            # Create new empty log file with same permissions
            touch "$logfile"
            chmod 644 "$logfile"
            
            # Compress backup if enabled
            if [ "$COMPRESS_LOGS" = "true" ]; then
                log "Compressing $backup_file"
                gzip "$backup_file"
            fi
        fi
    fi
done

# Clean up old log files
log "Cleaning up old log files (keeping $MAX_LOG_FILES files)"

# Count and remove old compressed logs
find "$LOG_DIR" -name "*.log.*" -type f | sort -r | tail -n +$((MAX_LOG_FILES + 1)) | while read -r old_file; do
    log "Removing old log file: $old_file"
    rm -f "$old_file"
done

# Clean up empty log files older than 7 days
find "$LOG_DIR" -name "*.log" -type f -empty -mtime +7 -delete 2>/dev/null || true

# Generate log rotation report
REPORT_FILE="$LOG_DIR/rotation_report_$(date +%Y%m%d).log"
{
    echo "Log Rotation Report - $(date)"
    echo "=================================="
    echo "Log Directory: $LOG_DIR"
    echo "Max Log Size: $MAX_LOG_SIZE"
    echo "Max Log Files: $MAX_LOG_FILES"
    echo "Compression: $COMPRESS_LOGS"
    echo ""
    echo "Current Log Files:"
    ls -lh "$LOG_DIR"/*.log 2>/dev/null || echo "No .log files found"
    echo ""
    echo "Archived Log Files:"
    ls -lh "$LOG_DIR"/*.log.* 2>/dev/null || echo "No archived files found"
    echo ""
    echo "Disk Usage:"
    du -sh "$LOG_DIR"
} > "$REPORT_FILE"

log "Log rotation completed. Report saved to $REPORT_FILE"

# Optional: Send report via email if configured
if [ -n "$EMAIL_REPORTS_TO" ]; then
    log "Sending rotation report to $EMAIL_REPORTS_TO"
    mail -s "Log Rotation Report - $(date +%Y-%m-%d)" "$EMAIL_REPORTS_TO" < "$REPORT_FILE" || warning "Failed to send email report"
fi

# Set up logrotate configuration if it doesn't exist
LOGROTATE_CONFIG="/etc/logrotate.d/warehouse-dashboard"
if [ ! -f "$LOGROTATE_CONFIG" ] && [ -w "/etc/logrotate.d" ]; then
    log "Creating logrotate configuration"
    cat > "$LOGROTATE_CONFIG" << EOF
$LOG_DIR/*.log {
    daily
    missingok
    rotate $MAX_LOG_FILES
    compress
    delaycompress
    notifempty
    create 644 www-data www-data
    postrotate
        # Reload PHP-FPM if running
        if [ -f /var/run/php/php8.4-fpm.pid ]; then
            /bin/kill -USR1 \$(cat /var/run/php/php8.4-fpm.pid)
        fi
    endscript
}
EOF
    log "Logrotate configuration created at $LOGROTATE_CONFIG"
fi

log "Log rotation script completed successfully"