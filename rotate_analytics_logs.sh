#!/bin/bash
# Analytics ETL Log Rotation Script
# Automatically created by setup_analytics_etl_cron.sh

SCRIPT_DIR="$( cd "$( dirname "${BASH_SOURCE[0]}" )" && pwd )"
LOG_DIR="$SCRIPT_DIR/logs"

echo "$(date): Starting log rotation for Analytics ETL"

# Rotate main ETL logs (keep 30 days)
find "$LOG_DIR/analytics_etl" -name "*.log" -mtime +30 -delete
echo "$(date): Cleaned analytics_etl logs older than 30 days"

# Rotate cron logs (keep 14 days)
find "$LOG_DIR/cron" -name "*.log" -mtime +14 -delete
echo "$(date): Cleaned cron logs older than 14 days"

# Compress old logs (older than 7 days)
find "$LOG_DIR/analytics_etl" -name "*.log" -mtime +7 -exec gzip {} \;
find "$LOG_DIR/cron" -name "*.log" -mtime +7 -exec gzip {} \;
echo "$(date): Compressed logs older than 7 days"

# Check log directory size
LOG_SIZE=$(du -sh "$LOG_DIR" | cut -f1)
echo "$(date): Current log directory size: $LOG_SIZE"

# Alert if logs are taking too much space (>1GB)
LOG_SIZE_BYTES=$(du -sb "$LOG_DIR" | cut -f1)
if [ "$LOG_SIZE_BYTES" -gt 1073741824 ]; then
    echo "$(date): WARNING: Log directory size exceeds 1GB ($LOG_SIZE)"
    # You can add email notification here
fi

echo "$(date): Log rotation completed"
