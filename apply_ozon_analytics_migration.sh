#!/bin/bash

# Safe Migration Script for Ozon Analytics Tables
# This script applies the Ozon analytics database migration safely
# Requirements: 4.1, 4.2

set -e  # Exit on any error

# Configuration
MIGRATION_FILE="migrations/add_ozon_analytics_tables.sql"
BACKUP_DIR="backups"
TIMESTAMP=$(date +"%Y%m%d_%H%M%S")
LOG_FILE="logs/ozon_migration_${TIMESTAMP}.log"

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

# Check if required files exist
check_prerequisites() {
    log "Checking prerequisites..."
    
    if [ ! -f "$MIGRATION_FILE" ]; then
        error_exit "Migration file not found: $MIGRATION_FILE"
    fi
    
    if [ ! -f "config.py" ] && [ ! -f "config_local.py" ]; then
        error_exit "Database configuration file not found (config.py or config_local.py)"
    fi
    
    # Create necessary directories
    mkdir -p "$BACKUP_DIR"
    mkdir -p "logs"
    
    success "Prerequisites check completed"
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

# Test database connection
test_connection() {
    log "Testing database connection..."
    
    if [ -n "$DB_PASS" ]; then
        mysql -h"$DB_HOST" -P"$DB_PORT" -u"$DB_USER" -p"$DB_PASS" -e "SELECT 1;" "$DB_NAME" > /dev/null 2>&1
    else
        mysql -h"$DB_HOST" -P"$DB_PORT" -u"$DB_USER" -e "SELECT 1;" "$DB_NAME" > /dev/null 2>&1
    fi
    
    if [ $? -eq 0 ]; then
        success "Database connection successful"
    else
        error_exit "Cannot connect to database. Please check your credentials and ensure MySQL is running."
    fi
}

# Create backup of existing tables (if they exist)
create_backup() {
    log "Creating backup of existing tables (if any)..."
    
    BACKUP_FILE="${BACKUP_DIR}/ozon_tables_backup_${TIMESTAMP}.sql"
    
    # Check if any of the tables exist and backup if they do
    TABLES_TO_BACKUP=""
    for table in ozon_api_settings ozon_funnel_data ozon_demographics ozon_campaigns; do
        if [ -n "$DB_PASS" ]; then
            TABLE_EXISTS=$(mysql -h"$DB_HOST" -P"$DB_PORT" -u"$DB_USER" -p"$DB_PASS" -e "SHOW TABLES LIKE '$table';" "$DB_NAME" 2>/dev/null | grep -c "$table" || echo "0")
        else
            TABLE_EXISTS=$(mysql -h"$DB_HOST" -P"$DB_PORT" -u"$DB_USER" -e "SHOW TABLES LIKE '$table';" "$DB_NAME" 2>/dev/null | grep -c "$table" || echo "0")
        fi
        
        if [ "$TABLE_EXISTS" -gt 0 ]; then
            TABLES_TO_BACKUP="$TABLES_TO_BACKUP $table"
        fi
    done
    
    if [ -n "$TABLES_TO_BACKUP" ]; then
        warning "Found existing Ozon tables, creating backup..."
        if [ -n "$DB_PASS" ]; then
            mysqldump -h"$DB_HOST" -P"$DB_PORT" -u"$DB_USER" -p"$DB_PASS" "$DB_NAME" $TABLES_TO_BACKUP > "$BACKUP_FILE"
        else
            mysqldump -h"$DB_HOST" -P"$DB_PORT" -u"$DB_USER" "$DB_NAME" $TABLES_TO_BACKUP > "$BACKUP_FILE"
        fi
        success "Backup created: $BACKUP_FILE"
    else
        log "No existing Ozon tables found, skipping backup"
    fi
}

# Apply the migration
apply_migration() {
    log "Applying Ozon analytics migration..."
    
    if [ -n "$DB_PASS" ]; then
        mysql -h"$DB_HOST" -P"$DB_PORT" -u"$DB_USER" -p"$DB_PASS" "$DB_NAME" < "$MIGRATION_FILE"
    else
        mysql -h"$DB_HOST" -P"$DB_PORT" -u"$DB_USER" "$DB_NAME" < "$MIGRATION_FILE"
    fi
    
    if [ $? -eq 0 ]; then
        success "Migration applied successfully"
    else
        error_exit "Migration failed. Check the log for details."
    fi
}

# Verify migration
verify_migration() {
    log "Verifying migration..."
    
    EXPECTED_TABLES=("ozon_api_settings" "ozon_funnel_data" "ozon_demographics" "ozon_campaigns")
    
    for table in "${EXPECTED_TABLES[@]}"; do
        if [ -n "$DB_PASS" ]; then
            TABLE_EXISTS=$(mysql -h"$DB_HOST" -P"$DB_PORT" -u"$DB_USER" -p"$DB_PASS" -e "SHOW TABLES LIKE '$table';" "$DB_NAME" 2>/dev/null | grep -c "$table" || echo "0")
        else
            TABLE_EXISTS=$(mysql -h"$DB_HOST" -P"$DB_PORT" -u"$DB_USER" -e "SHOW TABLES LIKE '$table';" "$DB_NAME" 2>/dev/null | grep -c "$table" || echo "0")
        fi
        
        if [ "$TABLE_EXISTS" -gt 0 ]; then
            success "Table $table created successfully"
        else
            error_exit "Table $table was not created"
        fi
    done
    
    success "All tables verified successfully"
}

# Main execution
main() {
    log "Starting Ozon Analytics Migration - $(date)"
    log "=============================================="
    
    check_prerequisites
    load_db_config
    test_connection
    create_backup
    apply_migration
    verify_migration
    
    log "=============================================="
    success "Ozon Analytics Migration completed successfully!"
    log "Log file: $LOG_FILE"
    
    if [ -f "${BACKUP_DIR}/ozon_tables_backup_${TIMESTAMP}.sql" ]; then
        log "Backup file: ${BACKUP_DIR}/ozon_tables_backup_${TIMESTAMP}.sql"
    fi
}

# Run main function
main "$@"