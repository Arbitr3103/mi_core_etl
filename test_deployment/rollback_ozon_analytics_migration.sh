#!/bin/bash

# Rollback Script for Ozon Analytics Migration
# This script safely removes Ozon analytics tables if needed

set -e  # Exit on any error

# Configuration
BACKUP_DIR="backups"
LOG_FILE="logs/ozon_rollback_$(date +"%Y%m%d_%H%M%S").log"

# Colors for output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
NC='\033[0m' # No Color

# Logging function
log() {
    echo -e "$1" | tee -a "$LOG_FILE"
}

# Error handling function
error_exit() {
    log "${RED}ERROR: $1${NC}"
    exit 1
}

# Success function
success() {
    log "${GREEN}SUCCESS: $1${NC}"
}

# Warning function
warning() {
    log "${YELLOW}WARNING: $1${NC}"
}

# Load database configuration
load_db_config() {
    log "Loading database configuration..."
    
    # Check if .env file exists and load database config from it
    if [ -f ".env" ] || [ -f ".env.local" ]; then
        # Try .env.local first (for local development), then .env
        if [ -f ".env.local" ]; then
            ENV_FILE=".env.local"
        else
            ENV_FILE=".env"
        fi
        
        # Load environment variables from file
        export $(grep -v '^#' "$ENV_FILE" | xargs)
        
        DB_HOST=${DB_HOST:-"localhost"}
        DB_USER=${DB_USER:-""}
        DB_PASS=${DB_PASSWORD:-""}
        DB_NAME=${DB_NAME:-"mi_core_db"}
        DB_PORT=${DB_PORT:-"3306"}
        
    else
        # Fallback to default values if no .env file
        DB_HOST="localhost"
        DB_USER=""
        DB_PASS=""
        DB_NAME="mi_core_db"
        DB_PORT="3306"
    fi
    
    if [ -z "$DB_USER" ]; then
        error_exit "DB_USER not found in environment configuration. Please check your .env or .env.local file."
    fi
    
    if [ -z "$DB_NAME" ]; then
        error_exit "DB_NAME not found in environment configuration. Please check your .env or .env.local file."
    fi
    
    success "Database configuration loaded from $ENV_FILE"
    log "  Host: $DB_HOST:$DB_PORT"
    log "  Database: $DB_NAME"
    log "  User: $DB_USER"
}

# Confirm rollback
confirm_rollback() {
    warning "This will DROP all Ozon analytics tables and their data!"
    warning "Tables to be dropped: ozon_api_settings, ozon_funnel_data, ozon_demographics, ozon_campaigns"
    
    read -p "Are you sure you want to proceed? (yes/no): " -r
    if [[ ! $REPLY =~ ^[Yy][Ee][Ss]$ ]]; then
        log "Rollback cancelled by user"
        exit 0
    fi
}

# Drop Ozon tables
drop_tables() {
    log "Dropping Ozon analytics tables..."
    
    TABLES_TO_DROP=("ozon_campaigns" "ozon_demographics" "ozon_funnel_data" "ozon_api_settings")
    
    for table in "${TABLES_TO_DROP[@]}"; do
        log "Dropping table: $table"
        
        if [ -n "$DB_PASS" ]; then
            mysql -h"$DB_HOST" -P"$DB_PORT" -u"$DB_USER" -p"$DB_PASS" -e "DROP TABLE IF EXISTS $table;" "$DB_NAME"
        else
            mysql -h"$DB_HOST" -P"$DB_PORT" -u"$DB_USER" -e "DROP TABLE IF EXISTS $table;" "$DB_NAME"
        fi
        
        if [ $? -eq 0 ]; then
            success "Table $table dropped successfully"
        else
            error_exit "Failed to drop table $table"
        fi
    done
}

# Verify rollback
verify_rollback() {
    log "Verifying rollback..."
    
    TABLES_TO_CHECK=("ozon_api_settings" "ozon_funnel_data" "ozon_demographics" "ozon_campaigns")
    
    for table in "${TABLES_TO_CHECK[@]}"; do
        if [ -n "$DB_PASS" ]; then
            TABLE_EXISTS=$(mysql -h"$DB_HOST" -P"$DB_PORT" -u"$DB_USER" -p"$DB_PASS" -e "SHOW TABLES LIKE '$table';" "$DB_NAME" 2>/dev/null | grep -c "$table" || echo "0")
        else
            TABLE_EXISTS=$(mysql -h"$DB_HOST" -P"$DB_PORT" -u"$DB_USER" -e "SHOW TABLES LIKE '$table';" "$DB_NAME" 2>/dev/null | grep -c "$table" || echo "0")
        fi
        
        if [ "$TABLE_EXISTS" -eq 0 ]; then
            success "Table $table successfully removed"
        else
            error_exit "Table $table still exists"
        fi
    done
    
    success "All Ozon analytics tables removed successfully"
}

# Main execution
main() {
    log "Starting Ozon Analytics Rollback - $(date)"
    log "=============================================="
    
    mkdir -p "logs"
    
    load_db_config
    confirm_rollback
    drop_tables
    verify_rollback
    
    log "=============================================="
    success "Ozon Analytics Rollback completed successfully!"
    log "Log file: $LOG_FILE"
}

# Run main function
main "$@"