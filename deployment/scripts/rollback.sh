#!/bin/bash

# MI Core ETL Rollback Script
# Restores from the most recent backup

set -e

# Configuration
PROJECT_ROOT="/var/www/mi_core_etl"
BACKUP_DIR="$PROJECT_ROOT/storage/backups"

# Colors
GREEN='\033[0;32m'
RED='\033[0;31m'
YELLOW='\033[1;33m'
NC='\033[0m'

log() {
    echo -e "${GREEN}[$(date '+%Y-%m-%d %H:%M:%S')]${NC} $1"
}

error() {
    echo -e "${RED}[$(date '+%Y-%m-%d %H:%M:%S')] ERROR:${NC} $1"
    exit 1
}

warning() {
    echo -e "${YELLOW}[$(date '+%Y-%m-%d %H:%M:%S')] WARNING:${NC} $1"
}

# Find most recent backup
LATEST_BACKUP=$(ls -t "$BACKUP_DIR"/mi_core_etl_backup_*.tar.gz 2>/dev/null | head -n 1)

if [ -z "$LATEST_BACKUP" ]; then
    error "No backup files found in $BACKUP_DIR"
fi

log "Found latest backup: $(basename "$LATEST_BACKUP")"

# Confirm rollback
echo -e "${YELLOW}WARNING: This will restore the system to the state of the backup.${NC}"
echo "Backup file: $(basename "$LATEST_BACKUP")"
echo "Created: $(stat -c %y "$LATEST_BACKUP")"
echo ""
read -p "Are you sure you want to proceed with rollback? (yes/no): " confirm

if [ "$confirm" != "yes" ]; then
    log "Rollback cancelled by user"
    exit 0
fi

# Create emergency backup of current state
log "Creating emergency backup of current state..."
bash "$PROJECT_ROOT/deployment/scripts/backup.sh" || warning "Emergency backup failed"

# Extract backup
log "Extracting backup..."
TEMP_DIR=$(mktemp -d)
cd "$TEMP_DIR"
tar -xzf "$LATEST_BACKUP" || error "Failed to extract backup"

BACKUP_FOLDER=$(ls -d mi_core_etl_backup_* | head -n 1)
cd "$BACKUP_FOLDER"

# Restore database
if [ -f "$PROJECT_ROOT/.env" ]; then
    log "Restoring database..."
    
    # Source environment variables
    export $(grep -v '^#' "$PROJECT_ROOT/.env" | xargs)
    
    # Determine database type and restore accordingly
    if [ "${DB_CONNECTION:-mysql}" = "pgsql" ] && [ -f "database.dump" ]; then
        log "Restoring PostgreSQL database..."
        PGPASSWORD="$DB_PASS" pg_restore -h "${DB_HOST:-localhost}" -U "$DB_USER" -d "$DB_NAME" -c database.dump || error "PostgreSQL restore failed"
        log "PostgreSQL database restored successfully"
    elif [ -f "database.sql" ]; then
        log "Restoring MySQL database..."
        mysql -h"${DB_HOST:-localhost}" -u"$DB_USER" -p"$DB_PASS" "$DB_NAME" < database.sql || error "MySQL restore failed"
        log "MySQL database restored successfully"
    else
        warning "No database backup file found"
    fi
else
    warning "No .env file found, skipping database restore"
fi

# Restore configuration files
log "Restoring configuration files..."
cp -r config/* "$PROJECT_ROOT/config/" 2>/dev/null || true
cp .env "$PROJECT_ROOT/" 2>/dev/null || true
cp composer.json "$PROJECT_ROOT/" 2>/dev/null || true
cp package.json "$PROJECT_ROOT/" 2>/dev/null || true

# Restore source code
log "Restoring source code..."
rm -rf "$PROJECT_ROOT/src"
cp -r src "$PROJECT_ROOT/"

# Restore public files
log "Restoring public files..."
rm -rf "$PROJECT_ROOT/public"
cp -r public "$PROJECT_ROOT/"

# Set permissions
log "Setting permissions..."
chmod -R 755 "$PROJECT_ROOT/storage/"
chmod -R 755 "$PROJECT_ROOT/public/"
chmod +x "$PROJECT_ROOT/deployment/scripts/"*.sh

# Install dependencies
log "Installing dependencies..."
cd "$PROJECT_ROOT"
composer install --no-dev --optimize-autoloader || warning "Composer install failed"

# Restart services
log "Restarting services..."
sudo systemctl reload nginx || warning "Failed to reload nginx"
sudo systemctl restart php8.1-fpm || warning "Failed to restart PHP-FPM"

# Health check
log "Running health check..."
sleep 5
php "$PROJECT_ROOT/src/api/controllers/HealthController.php" || warning "Health check failed"

# Cleanup
rm -rf "$TEMP_DIR"

log "Rollback completed successfully!"
log "System restored from backup: $(basename "$LATEST_BACKUP")"

echo ""
echo "=== Rollback Summary ==="
echo "Restored from: $(basename "$LATEST_BACKUP")"
echo "Completed at: $(date)"
echo "======================="