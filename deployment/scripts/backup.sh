#!/bin/bash

# MI Core ETL Backup Script
# Creates backups of database, files, and configuration

set -e

# Configuration
PROJECT_ROOT="/var/www/mi_core_etl"
BACKUP_DIR="$PROJECT_ROOT/storage/backups"
TIMESTAMP=$(date +%Y%m%d_%H%M%S)
BACKUP_NAME="mi_core_etl_backup_$TIMESTAMP"
BACKUP_PATH="$BACKUP_DIR/$BACKUP_NAME"

# Colors
GREEN='\033[0;32m'
RED='\033[0;31m'
NC='\033[0m'

log() {
    echo -e "${GREEN}[$(date '+%Y-%m-%d %H:%M:%S')]${NC} $1"
}

error() {
    echo -e "${RED}[$(date '+%Y-%m-%d %H:%M:%S')] ERROR:${NC} $1"
    exit 1
}

# Create backup directory
mkdir -p "$BACKUP_PATH"

log "Starting backup: $BACKUP_NAME"

# Backup database
if [ -f "$PROJECT_ROOT/.env" ]; then
    log "Backing up database..."
    
    # Source environment variables
    export $(grep -v '^#' "$PROJECT_ROOT/.env" | xargs)
    
    # Determine database type and backup accordingly
    if [ "${DB_CONNECTION:-mysql}" = "pgsql" ]; then
        log "Backing up PostgreSQL database..."
        PGPASSWORD="$DB_PASS" pg_dump -h "${DB_HOST:-localhost}" -U "$DB_USER" -d "$DB_NAME" -F c -f "$BACKUP_PATH/database.dump" || error "PostgreSQL backup failed"
        log "PostgreSQL backup completed"
    else
        log "Backing up MySQL database..."
        mysqldump -h"${DB_HOST:-localhost}" -u"$DB_USER" -p"$DB_PASS" "$DB_NAME" > "$BACKUP_PATH/database.sql" || error "MySQL backup failed"
        log "MySQL backup completed"
    fi
else
    log "No .env file found, skipping database backup"
fi

# Backup configuration files
log "Backing up configuration files..."
mkdir -p "$BACKUP_PATH/config"
cp -r "$PROJECT_ROOT/config/"* "$BACKUP_PATH/config/" 2>/dev/null || true
cp "$PROJECT_ROOT/.env" "$BACKUP_PATH/" 2>/dev/null || true
cp "$PROJECT_ROOT/composer.json" "$BACKUP_PATH/" 2>/dev/null || true
cp "$PROJECT_ROOT/package.json" "$BACKUP_PATH/" 2>/dev/null || true

# Backup source code
log "Backing up source code..."
mkdir -p "$BACKUP_PATH/src"
cp -r "$PROJECT_ROOT/src/"* "$BACKUP_PATH/src/" 2>/dev/null || true

# Backup public files
log "Backing up public files..."
mkdir -p "$BACKUP_PATH/public"
cp -r "$PROJECT_ROOT/public/"* "$BACKUP_PATH/public/" 2>/dev/null || true

# Backup storage (logs, cache)
log "Backing up storage..."
mkdir -p "$BACKUP_PATH/storage"
cp -r "$PROJECT_ROOT/storage/logs" "$BACKUP_PATH/storage/" 2>/dev/null || true

# Create backup info file
cat > "$BACKUP_PATH/backup_info.txt" << EOF
MI Core ETL Backup Information
==============================
Backup Name: $BACKUP_NAME
Created: $(date)
Project Root: $PROJECT_ROOT
Database: ${DB_NAME:-"Not specified"}
Host: ${DB_HOST:-"Not specified"}

Contents:
- Database dump (database.sql or database.dump for PostgreSQL)
- Configuration files (config/, .env)
- Source code (src/)
- Public files (public/)
- Storage logs (storage/logs/)

Restore Instructions:
1. Extract backup to project directory
2. Import database:
   - MySQL: mysql -u[user] -p[pass] [db_name] < database.sql
   - PostgreSQL: PGPASSWORD=[pass] pg_restore -h [host] -U [user] -d [db_name] database.dump
3. Copy configuration files back to project root
4. Set proper permissions: chmod -R 755 storage/ public/
EOF

# Compress backup
log "Compressing backup..."
cd "$BACKUP_DIR"
tar -czf "${BACKUP_NAME}.tar.gz" "$BACKUP_NAME"
rm -rf "$BACKUP_NAME"

# Clean old backups (keep last 10)
log "Cleaning old backups..."
ls -t mi_core_etl_backup_*.tar.gz 2>/dev/null | tail -n +11 | xargs rm -f 2>/dev/null || true

log "Backup completed: ${BACKUP_PATH}.tar.gz"
echo "Backup size: $(du -h "${BACKUP_PATH}.tar.gz" | cut -f1)"