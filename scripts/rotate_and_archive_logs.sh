#!/bin/bash
###############################################################################
# Log Rotation and Archiving Script
#
# Automatically rotates and archives old log files to save disk space
# and maintain system performance.
#
# Requirements: 7.2, 7.3
###############################################################################

set -e

# Configuration
LOG_BASE_PATH="${LOG_PATH:-/var/www/market-mi.ru/logs}"
ARCHIVE_PATH="${LOG_BASE_PATH}/archive"
MAX_LOG_AGE_DAYS="${MAX_LOG_AGE_DAYS:-30}"
MAX_ARCHIVE_AGE_DAYS="${MAX_ARCHIVE_AGE_DAYS:-90}"
MAX_LOG_SIZE_MB="${MAX_LOG_SIZE_MB:-50}"

# Colors for output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
NC='\033[0m' # No Color

# Logging functions
log_info() {
    echo -e "${GREEN}[INFO]${NC} $(date '+%Y-%m-%d %H:%M:%S') - $1"
}

log_warning() {
    echo -e "${YELLOW}[WARNING]${NC} $(date '+%Y-%m-%d %H:%M:%S') - $1"
}

log_error() {
    echo -e "${RED}[ERROR]${NC} $(date '+%Y-%m-%d %H:%M:%S') - $1"
}

# Ensure archive directory exists
ensure_archive_directory() {
    if [ ! -d "$ARCHIVE_PATH" ]; then
        log_info "Creating archive directory: $ARCHIVE_PATH"
        mkdir -p "$ARCHIVE_PATH"
    fi
}

# Rotate large log files
rotate_large_logs() {
    log_info "Checking for large log files (>${MAX_LOG_SIZE_MB}MB)..."
    
    local rotated_count=0
    
    # Find log files larger than MAX_LOG_SIZE_MB
    find "$LOG_BASE_PATH" -type f -name "*.log" -size "+${MAX_LOG_SIZE_MB}M" | while read -r log_file; do
        local file_size=$(du -m "$log_file" | cut -f1)
        log_warning "Rotating large log file: $log_file (${file_size}MB)"
        
        # Create backup with timestamp
        local backup_file="${log_file}.$(date +%Y%m%d_%H%M%S)"
        mv "$log_file" "$backup_file"
        
        # Compress the backup
        gzip "$backup_file"
        
        # Move to archive
        mv "${backup_file}.gz" "$ARCHIVE_PATH/"
        
        # Create new empty log file
        touch "$log_file"
        chmod 644 "$log_file"
        
        rotated_count=$((rotated_count + 1))
    done
    
    log_info "Rotated $rotated_count large log files"
}

# Archive old log files
archive_old_logs() {
    log_info "Archiving log files older than ${MAX_LOG_AGE_DAYS} days..."
    
    local archived_count=0
    
    # Find log files older than MAX_LOG_AGE_DAYS
    find "$LOG_BASE_PATH" -type f -name "*.log" -mtime "+${MAX_LOG_AGE_DAYS}" | while read -r log_file; do
        # Skip if already in archive directory
        if [[ "$log_file" == *"/archive/"* ]]; then
            continue
        fi
        
        log_info "Archiving old log file: $log_file"
        
        # Compress and move to archive
        gzip -c "$log_file" > "$ARCHIVE_PATH/$(basename "$log_file").$(date +%Y%m%d).gz"
        
        # Remove original file
        rm "$log_file"
        
        archived_count=$((archived_count + 1))
    done
    
    log_info "Archived $archived_count old log files"
}

# Compress rotated log files
compress_rotated_logs() {
    log_info "Compressing rotated log files..."
    
    local compressed_count=0
    
    # Find uncompressed rotated log files (*.log.*)
    find "$LOG_BASE_PATH" -type f -name "*.log.*" ! -name "*.gz" | while read -r log_file; do
        log_info "Compressing: $log_file"
        
        gzip "$log_file"
        compressed_count=$((compressed_count + 1))
    done
    
    log_info "Compressed $compressed_count rotated log files"
}

# Clean up very old archives
cleanup_old_archives() {
    log_info "Cleaning up archives older than ${MAX_ARCHIVE_AGE_DAYS} days..."
    
    local deleted_count=0
    
    # Find and delete archives older than MAX_ARCHIVE_AGE_DAYS
    find "$ARCHIVE_PATH" -type f -name "*.gz" -mtime "+${MAX_ARCHIVE_AGE_DAYS}" | while read -r archive_file; do
        log_info "Deleting old archive: $archive_file"
        rm "$archive_file"
        deleted_count=$((deleted_count + 1))
    done
    
    log_info "Deleted $deleted_count old archive files"
}

# Generate log rotation report
generate_report() {
    log_info "Generating log rotation report..."
    
    local report_file="${LOG_BASE_PATH}/rotation_report_$(date +%Y%m%d_%H%M%S).txt"
    
    {
        echo "Log Rotation Report"
        echo "==================="
        echo "Date: $(date '+%Y-%m-%d %H:%M:%S')"
        echo ""
        echo "Configuration:"
        echo "  Log Base Path: $LOG_BASE_PATH"
        echo "  Archive Path: $ARCHIVE_PATH"
        echo "  Max Log Age: ${MAX_LOG_AGE_DAYS} days"
        echo "  Max Archive Age: ${MAX_ARCHIVE_AGE_DAYS} days"
        echo "  Max Log Size: ${MAX_LOG_SIZE_MB}MB"
        echo ""
        echo "Current Log Statistics:"
        echo "  Active Logs: $(find "$LOG_BASE_PATH" -type f -name "*.log" ! -path "*/archive/*" | wc -l)"
        echo "  Rotated Logs: $(find "$LOG_BASE_PATH" -type f -name "*.log.*" ! -path "*/archive/*" | wc -l)"
        echo "  Archived Logs: $(find "$ARCHIVE_PATH" -type f -name "*.gz" 2>/dev/null | wc -l)"
        echo ""
        echo "Disk Usage:"
        echo "  Total Log Size: $(du -sh "$LOG_BASE_PATH" 2>/dev/null | cut -f1)"
        echo "  Archive Size: $(du -sh "$ARCHIVE_PATH" 2>/dev/null | cut -f1)"
        echo ""
        echo "Largest Log Files:"
        find "$LOG_BASE_PATH" -type f -name "*.log" ! -path "*/archive/*" -exec du -h {} + 2>/dev/null | sort -rh | head -10
    } > "$report_file"
    
    log_info "Report generated: $report_file"
    
    # Display report summary
    cat "$report_file"
}

# Main execution
main() {
    log_info "Starting log rotation and archiving process..."
    log_info "Log base path: $LOG_BASE_PATH"
    
    # Check if log directory exists
    if [ ! -d "$LOG_BASE_PATH" ]; then
        log_error "Log directory does not exist: $LOG_BASE_PATH"
        exit 1
    fi
    
    # Ensure archive directory exists
    ensure_archive_directory
    
    # Perform rotation and archiving
    rotate_large_logs
    compress_rotated_logs
    archive_old_logs
    cleanup_old_archives
    
    # Generate report
    generate_report
    
    log_info "Log rotation and archiving completed successfully"
}

# Run main function
main "$@"
