#!/bin/bash

# Cron Setup Script for MI Core ETL
# Installs and configures all cron jobs

set -e

PROJECT_ROOT="/var/www/mi_core_etl"
CRON_CONFIG="$PROJECT_ROOT/deployment/configs/crontab.txt"
TEMP_CRON=$(mktemp)

# Colors
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
RED='\033[0;31m'
NC='\033[0m'

log() {
    echo -e "${GREEN}[$(date '+%Y-%m-%d %H:%M:%S')]${NC} $1"
}

warning() {
    echo -e "${YELLOW}[$(date '+%Y-%m-%d %H:%M:%S')] WARNING:${NC} $1"
}

error() {
    echo -e "${RED}[$(date '+%Y-%m-%d %H:%M:%S')] ERROR:${NC} $1"
    exit 1
}

# Check if cron config exists
if [ ! -f "$CRON_CONFIG" ]; then
    error "Cron configuration file not found: $CRON_CONFIG"
fi

log "Setting up cron jobs for MI Core ETL..."

# Backup existing crontab
log "Backing up existing crontab..."
crontab -l > "$TEMP_CRON" 2>/dev/null || echo "# No existing crontab" > "$TEMP_CRON"
cp "$TEMP_CRON" "$PROJECT_ROOT/storage/backups/crontab_backup_$(date +%Y%m%d_%H%M%S).txt"

# Check if MI Core ETL cron jobs already exist
if grep -q "MI Core ETL" "$TEMP_CRON" 2>/dev/null; then
    warning "MI Core ETL cron jobs already exist in crontab"
    read -p "Do you want to replace them? (yes/no): " confirm
    
    if [ "$confirm" != "yes" ]; then
        log "Cron setup cancelled"
        rm "$TEMP_CRON"
        exit 0
    fi
    
    # Remove existing MI Core ETL cron jobs
    log "Removing existing MI Core ETL cron jobs..."
    sed -i '/MI Core ETL/,/^$/d' "$TEMP_CRON"
fi

# Append new cron jobs
log "Adding MI Core ETL cron jobs..."
cat "$CRON_CONFIG" >> "$TEMP_CRON"

# Install new crontab
log "Installing new crontab..."
crontab "$TEMP_CRON"

# Verify installation
log "Verifying cron installation..."
if crontab -l | grep -q "MI Core ETL"; then
    log "Cron jobs installed successfully!"
else
    error "Failed to install cron jobs"
fi

# Cleanup
rm "$TEMP_CRON"

# Display installed cron jobs
echo ""
echo "========================================="
echo "Installed Cron Jobs:"
echo "========================================="
crontab -l | grep -A 1 "MI Core ETL" | grep -v "^#" | grep -v "^$" || true
echo "========================================="

log "Cron setup completed!"
log "View all cron jobs: crontab -l"
log "Edit cron jobs: crontab -e"
log "Remove all cron jobs: crontab -r"
