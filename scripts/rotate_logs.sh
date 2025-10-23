#!/bin/bash

# Log Rotation Script for MI Core ETL
# Rotates and compresses log files to manage disk space

set -e

PROJECT_ROOT="/var/www/mi_core_etl"
LOG_DIR="$PROJECT_ROOT/storage/logs"
ARCHIVE_DIR="$LOG_DIR/archive"
TIMESTAMP=$(date +%Y%m%d_%H%M%S)

# Colors
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
NC='\033[0m'

log() {
    echo -e "${GREEN}[$(date '+%Y-%m-%d %H:%M:%S')]${NC} $1"
}

# Create archive directory if it doesn't exist
mkdir -p "$ARCHIVE_DIR"

log "Starting log rotation..."

# Find all log files larger than 10MB or older than 7 days
find "$LOG_DIR" -maxdepth 1 -name "*.log" -type f \( -size +10M -o -mtime +7 \) | while read -r logfile; do
    if [ -f "$logfile" ]; then
        filename=$(basename "$logfile")
        log "Rotating $filename..."
        
        # Copy and compress the log file
        cp "$logfile" "$ARCHIVE_DIR/${filename%.log}_${TIMESTAMP}.log"
        gzip "$ARCHIVE_DIR/${filename%.log}_${TIMESTAMP}.log"
        
        # Truncate the original log file (keep it for active writing)
        > "$logfile"
        
        log "Rotated and compressed: ${filename%.log}_${TIMESTAMP}.log.gz"
    fi
done

# Remove archived logs older than 30 days
log "Cleaning old archived logs..."
find "$ARCHIVE_DIR" -name "*.log.gz" -mtime +30 -delete

# Calculate disk usage
DISK_USAGE=$(du -sh "$LOG_DIR" | cut -f1)
log "Log rotation completed. Total log directory size: $DISK_USAGE"

# Count files
ACTIVE_LOGS=$(find "$LOG_DIR" -maxdepth 1 -name "*.log" -type f | wc -l)
ARCHIVED_LOGS=$(find "$ARCHIVE_DIR" -name "*.log.gz" -type f | wc -l)

log "Active logs: $ACTIVE_LOGS, Archived logs: $ARCHIVED_LOGS"
