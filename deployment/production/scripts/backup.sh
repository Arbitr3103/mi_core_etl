#!/bin/bash

# Automated Backup Script for MDM System

set -e

# Configuration
PROJECT_DIR="/opt/mdm"
BACKUP_DIR="/opt/mdm/backups"
S3_BUCKET="${BACKUP_S3_BUCKET:-mdm-backups}"
RETENTION_DAYS="${BACKUP_RETENTION_DAYS:-30}"
LOG_FILE="/var/log/mdm-backup.log"

# Colors for output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
NC='\033[0m'

# Logging function
log() {
    echo -e "${GREEN}[$(date +'%Y-%m-%d %H:%M:%S')]${NC} $1" | tee -a "$LOG_FILE"
}

error() {
    echo -e "${RED}[$(date +'%Y-%m-%d %H:%M:%S')] ERROR:${NC} $1" | tee -a "$LOG_FILE"
}

# Load environment variables
if [ -f "$PROJECT_DIR/.env" ]; then
    source "$PROJECT_DIR/.env"
else
    error "Environment file not found"
    exit 1
fi

# Create backup directory
mkdir -p "$BACKUP_DIR"

# Generate backup filename
TIMESTAMP=$(date +%Y%m%d_%H%M%S)
BACKUP_FILE="$BACKUP_DIR/mdm_backup_$TIMESTAMP.sql"

# Create database backup
create_database_backup() {
    log "Creating database backup..."
    
    if docker-compose -f "$PROJECT_DIR/docker-compose.prod.yml" exec -T mdm-db-master mysqldump \
        -u root -p"$DB_ROOT_PASSWORD" \
        --single-transaction \
        --routines \
        --triggers \
        --add-drop-table \
        --add-locks \
        --create-options \
        --disable-keys \
        --extended-insert \
        --quick \
        --set-charset \
        "$DB_NAME" > "$BACKUP_FILE"; then
        
        log "Database backup created: $BACKUP_FILE"
        
        # Compress backup
        gzip "$BACKUP_FILE"
        BACKUP_FILE="${BACKUP_FILE}.gz"
        log "Backup compressed: $BACKUP_FILE"
        
        # Calculate backup size
        BACKUP_SIZE=$(du -h "$BACKUP_FILE" | cut -f1)
        log "Backup size: $BACKUP_SIZE"
        
    else
        error "Failed to create database backup"
        exit 1
    fi
}

# Backup application files
backup_application_files() {
    log "Backing up application files..."
    
    APP_BACKUP_FILE="$BACKUP_DIR/mdm_app_files_$TIMESTAMP.tar.gz"
    
    # Backup configuration files, logs, and uploads
    tar -czf "$APP_BACKUP_FILE" \
        -C "$PROJECT_DIR" \
        --exclude='*.log' \
        --exclude='node_modules' \
        --exclude='backups' \
        . 2>/dev/null || true
    
    if [ -f "$APP_BACKUP_FILE" ]; then
        APP_SIZE=$(du -h "$APP_BACKUP_FILE" | cut -f1)
        log "Application files backup created: $APP_BACKUP_FILE ($APP_SIZE)"
    else
        warning "Failed to create application files backup"
    fi
}

# Upload to S3 (if configured)
upload_to_s3() {
    if [ -n "$AWS_ACCESS_KEY_ID" ] && [ -n "$S3_BUCKET" ]; then
        log "Uploading backups to S3..."
        
        # Upload database backup
        if aws s3 cp "$BACKUP_FILE" "s3://$S3_BUCKET/database/" --storage-class STANDARD_IA; then
            log "Database backup uploaded to S3"
        else
            warning "Failed to upload database backup to S3"
        fi
        
        # Upload application files backup
        if [ -f "$APP_BACKUP_FILE" ]; then
            if aws s3 cp "$APP_BACKUP_FILE" "s3://$S3_BUCKET/application/" --storage-class STANDARD_IA; then
                log "Application backup uploaded to S3"
            else
                warning "Failed to upload application backup to S3"
            fi
        fi
        
    else
        log "S3 upload not configured, skipping..."
    fi
}

# Cleanup old backups
cleanup_old_backups() {
    log "Cleaning up old backups..."
    
    # Remove local backups older than retention period
    find "$BACKUP_DIR" -name "*.gz" -mtime +$RETENTION_DAYS -delete
    find "$BACKUP_DIR" -name "*.tar.gz" -mtime +$RETENTION_DAYS -delete
    
    # Cleanup S3 backups (if configured)
    if [ -n "$AWS_ACCESS_KEY_ID" ] && [ -n "$S3_BUCKET" ]; then
        # Set lifecycle policy for automatic cleanup
        aws s3api put-bucket-lifecycle-configuration \
            --bucket "$S3_BUCKET" \
            --lifecycle-configuration '{
                "Rules": [
                    {
                        "ID": "MDMBackupCleanup",
                        "Status": "Enabled",
                        "Filter": {"Prefix": ""},
                        "Transitions": [
                            {
                                "Days": 30,
                                "StorageClass": "GLACIER"
                            },
                            {
                                "Days": 90,
                                "StorageClass": "DEEP_ARCHIVE"
                            }
                        ],
                        "Expiration": {
                            "Days": 365
                        }
                    }
                ]
            }' 2>/dev/null || true
    fi
    
    log "Cleanup completed"
}

# Verify backup integrity
verify_backup() {
    log "Verifying backup integrity..."
    
    # Test gzip integrity
    if gzip -t "$BACKUP_FILE"; then
        log "Backup file integrity verified"
    else
        error "Backup file is corrupted"
        exit 1
    fi
    
    # Test if backup can be restored (dry run)
    if gunzip -c "$BACKUP_FILE" | head -n 10 | grep -q "CREATE TABLE"; then
        log "Backup content verification passed"
    else
        error "Backup content verification failed"
        exit 1
    fi
}

# Send notification
send_notification() {
    local status=$1
    local message=$2
    
    if [ -n "$SLACK_WEBHOOK_URL" ]; then
        curl -X POST -H 'Content-type: application/json' \
            --data "{\"text\":\"$message\"}" \
            "$SLACK_WEBHOOK_URL" > /dev/null 2>&1 || true
    fi
}

# Main backup process
main() {
    log "Starting MDM System backup process..."
    
    create_database_backup
    backup_application_files
    verify_backup
    upload_to_s3
    cleanup_old_backups
    
    BACKUP_SIZE=$(du -h "$BACKUP_FILE" | cut -f1)
    SUCCESS_MESSAGE="✅ MDM System backup completed successfully. Size: $BACKUP_SIZE"
    
    log "$SUCCESS_MESSAGE"
    send_notification "SUCCESS" "$SUCCESS_MESSAGE"
}

# Error handling
trap 'error "Backup process failed"; send_notification "FAILED" "❌ MDM System backup failed"; exit 1' ERR

# Run main function
main "$@"