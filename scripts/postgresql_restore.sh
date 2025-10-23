#!/bin/bash

# ===================================================================
# PostgreSQL Restore Script
# Task 5.3: Задокументировать процедуры восстановления
# ===================================================================

set -e  # Exit on any error

# Colors for output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m' # No Color

# Configuration
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_ROOT="$(dirname "$SCRIPT_DIR")"
BACKUP_DIR="$PROJECT_ROOT/storage/backups"
LOG_FILE="$PROJECT_ROOT/logs/restore_$(date +%Y%m%d_%H%M%S).log"

# Create log directory if it doesn't exist
mkdir -p "$(dirname "$LOG_FILE")"

# Load environment variables
if [ -f "$PROJECT_ROOT/.env" ]; then
    source "$PROJECT_ROOT/.env"
fi

# PostgreSQL connection parameters
PG_HOST="${PG_HOST:-localhost}"
PG_PORT="${PG_PORT:-5432}"
PG_USER="${PG_USER:-mi_core_user}"
PG_NAME="${PG_NAME:-mi_core_db}"

# Restore parameters
BACKUP_FILE="$1"
RESTORE_TYPE="${2:-full}"  # full, schema, data, selective
TARGET_DB="${3:-$PG_NAME}"
FORCE_RESTORE="${4:-false}"

echo -e "${BLUE}=== PostgreSQL Restore System ===${NC}"
echo "Backup file: $BACKUP_FILE"
echo "Restore type: $RESTORE_TYPE"
echo "Target database: $TARGET_DB"
echo "Host: $PG_HOST:$PG_PORT"
echo "Started at: $(date)"
echo ""

# Function to log messages
log_message() {
    local level=$1
    local message=$2
    local timestamp=$(date '+%Y-%m-%d %H:%M:%S')
    echo "[$timestamp] [$level] $message" | tee -a "$LOG_FILE"
}

# Function to check PostgreSQL connection
check_connection() {
    log_message "INFO" "Checking PostgreSQL connection..."
    
    if PGPASSWORD="$PG_PASSWORD" psql -h "$PG_HOST" -p "$PG_PORT" -U "$PG_USER" -d "postgres" -c "SELECT version();" > /dev/null 2>&1; then
        log_message "SUCCESS" "PostgreSQL connection successful"
        return 0
    else
        log_message "ERROR" "Cannot connect to PostgreSQL server"
        return 1
    fi
}

# Function to check if database exists
database_exists() {
    local db_name=$1
    
    if PGPASSWORD="$PG_PASSWORD" psql -h "$PG_HOST" -p "$PG_PORT" -U "$PG_USER" -d "postgres" -t -c "SELECT 1 FROM pg_database WHERE datname='$db_name';" | grep -q 1; then
        return 0
    else
        return 1
    fi
}

# Function to create database if it doesn't exist
create_database() {
    local db_name=$1
    
    log_message "INFO" "Creating database: $db_name"
    
    if PGPASSWORD="$PG_PASSWORD" psql -h "$PG_HOST" -p "$PG_PORT" -U "$PG_USER" -d "postgres" -c "CREATE DATABASE \"$db_name\" WITH ENCODING 'UTF8';" >> "$LOG_FILE" 2>&1; then
        log_message "SUCCESS" "Database created: $db_name"
        return 0
    else
        log_message "ERROR" "Failed to create database: $db_name"
        return 1
    fi
}

# Function to validate backup file
validate_backup_file() {
    local backup_file=$1
    
    log_message "INFO" "Validating backup file: $(basename "$backup_file")"
    
    if [ ! -f "$backup_file" ] && [ ! -d "$backup_file" ]; then
        log_message "ERROR" "Backup file/directory not found: $backup_file"
        return 1
    fi
    
    # Check file format and validate
    if [[ "$backup_file" == *.dump ]]; then
        # Custom format backup
        if PGPASSWORD="$PG_PASSWORD" pg_restore --list "$backup_file" > /dev/null 2>&1; then
            log_message "SUCCESS" "Custom format backup validated"
            return 0
        else
            log_message "ERROR" "Invalid custom format backup"
            return 1
        fi
    elif [[ "$backup_file" == *.sql ]]; then
        # SQL format backup
        if [ -s "$backup_file" ] && head -n 10 "$backup_file" | grep -q "PostgreSQL"; then
            log_message "SUCCESS" "SQL format backup validated"
            return 0
        else
            log_message "ERROR" "Invalid SQL format backup"
            return 1
        fi
    elif [[ "$backup_file" == *.tar ]] || [[ "$backup_file" == *.tar.gz ]]; then
        # TAR format backup
        if tar -tf "$backup_file" > /dev/null 2>&1; then
            log_message "SUCCESS" "TAR format backup validated"
            return 0
        else
            log_message "ERROR" "Invalid TAR format backup"
            return 1
        fi
    elif [ -d "$backup_file" ]; then
        # Directory format backup
        if [ -f "$backup_file/base.tar" ] || [ -f "$backup_file/base.tar.gz" ] || [ -f "$backup_file/toc.dat" ]; then
            log_message "SUCCESS" "Directory format backup validated"
            return 0
        else
            log_message "ERROR" "Invalid directory format backup"
            return 1
        fi
    else
        log_message "WARNING" "Unknown backup format, proceeding with caution"
        return 0
    fi
}

# Function to get backup metadata
get_backup_metadata() {
    local backup_file=$1
    local metadata_file="${backup_file}.metadata"
    
    if [ -f "$metadata_file" ]; then
        log_message "INFO" "Reading backup metadata..."
        echo -e "${BLUE}=== BACKUP METADATA ===${NC}"
        cat "$metadata_file" | grep -E "^(BACKUP_DATE|BACKUP_SIZE|DATABASE_SIZE|POSTGRESQL_VERSION)" | while IFS='=' read -r key value; do
            echo "$key: $value"
        done
        echo ""
    else
        log_message "WARNING" "No metadata file found for backup"
    fi
}

# Function to create pre-restore backup
create_pre_restore_backup() {
    local target_db=$1
    
    if database_exists "$target_db"; then
        log_message "INFO" "Creating pre-restore backup of existing database..."
        
        local pre_restore_backup="$BACKUP_DIR/pre_restore_backup_${target_db}_$(date +%Y%m%d_%H%M%S).dump"
        
        if PGPASSWORD="$PG_PASSWORD" pg_dump \
            -h "$PG_HOST" \
            -p "$PG_PORT" \
            -U "$PG_USER" \
            -d "$target_db" \
            -Fc \
            -Z 6 \
            --verbose \
            --no-password \
            -f "$pre_restore_backup" 2>> "$LOG_FILE"; then
            
            log_message "SUCCESS" "Pre-restore backup created: $(basename "$pre_restore_backup")"
            echo "$pre_restore_backup"
        else
            log_message "ERROR" "Failed to create pre-restore backup"
            return 1
        fi
    else
        log_message "INFO" "Target database does not exist, skipping pre-restore backup"
    fi
}

# Function to restore from custom format backup
restore_custom_format() {
    local backup_file=$1
    local target_db=$2
    local restore_options=""
    
    log_message "INFO" "Restoring from custom format backup..."
    
    # Set restore options based on restore type
    case $RESTORE_TYPE in
        "schema")
            restore_options="--schema-only"
            ;;
        "data")
            restore_options="--data-only --disable-triggers"
            ;;
        "selective")
            # This would require additional parameters for specific tables/schemas
            restore_options="--verbose"
            ;;
        *)
            restore_options="--clean --if-exists --create --verbose"
            ;;
    esac
    
    if PGPASSWORD="$PG_PASSWORD" pg_restore \
        -h "$PG_HOST" \
        -p "$PG_PORT" \
        -U "$PG_USER" \
        -d "$target_db" \
        $restore_options \
        --no-password \
        --single-transaction \
        "$backup_file" 2>> "$LOG_FILE"; then
        
        log_message "SUCCESS" "Custom format restore completed"
        return 0
    else
        log_message "ERROR" "Custom format restore failed"
        return 1
    fi
}

# Function to restore from SQL format backup
restore_sql_format() {
    local backup_file=$1
    local target_db=$2
    
    log_message "INFO" "Restoring from SQL format backup..."
    
    if PGPASSWORD="$PG_PASSWORD" psql \
        -h "$PG_HOST" \
        -p "$PG_PORT" \
        -U "$PG_USER" \
        -d "$target_db" \
        -v ON_ERROR_STOP=1 \
        -f "$backup_file" 2>> "$LOG_FILE"; then
        
        log_message "SUCCESS" "SQL format restore completed"
        return 0
    else
        log_message "ERROR" "SQL format restore failed"
        return 1
    fi
}

# Function to restore from directory format backup
restore_directory_format() {
    local backup_dir=$1
    local target_db=$2
    
    log_message "INFO" "Restoring from directory format backup..."
    
    if PGPASSWORD="$PG_PASSWORD" pg_restore \
        -h "$PG_HOST" \
        -p "$PG_PORT" \
        -U "$PG_USER" \
        -d "$target_db" \
        --clean \
        --if-exists \
        --create \
        --verbose \
        --no-password \
        --single-transaction \
        "$backup_dir" 2>> "$LOG_FILE"; then
        
        log_message "SUCCESS" "Directory format restore completed"
        return 0
    else
        log_message "ERROR" "Directory format restore failed"
        return 1
    fi
}

# Function to restore from base backup (for incremental restores)
restore_base_backup() {
    local backup_dir=$1
    local target_data_dir=$2
    
    log_message "INFO" "Restoring from base backup (requires PostgreSQL service restart)..."
    log_message "WARNING" "This operation requires stopping PostgreSQL and replacing data directory"
    
    # This is a more complex operation that typically requires:
    # 1. Stopping PostgreSQL service
    # 2. Replacing data directory
    # 3. Applying WAL files
    # 4. Starting PostgreSQL service
    
    log_message "ERROR" "Base backup restore requires manual intervention and service restart"
    log_message "INFO" "Please refer to PostgreSQL documentation for PITR (Point-in-Time Recovery)"
    
    return 1
}

# Function to verify restore
verify_restore() {
    local target_db=$1
    
    log_message "INFO" "Verifying restore..."
    
    # Check if database is accessible
    if ! PGPASSWORD="$PG_PASSWORD" psql -h "$PG_HOST" -p "$PG_PORT" -U "$PG_USER" -d "$target_db" -c "SELECT 1;" > /dev/null 2>&1; then
        log_message "ERROR" "Cannot connect to restored database"
        return 1
    fi
    
    # Get table count
    local table_count=$(PGPASSWORD="$PG_PASSWORD" psql -h "$PG_HOST" -p "$PG_PORT" -U "$PG_USER" -d "$target_db" -t -c "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = 'public';" | xargs)
    
    # Get row count for key tables
    local inventory_count=$(PGPASSWORD="$PG_PASSWORD" psql -h "$PG_HOST" -p "$PG_PORT" -U "$PG_USER" -d "$target_db" -t -c "SELECT COUNT(*) FROM inventory;" 2>/dev/null | xargs || echo "0")
    local products_count=$(PGPASSWORD="$PG_PASSWORD" psql -h "$PG_HOST" -p "$PG_PORT" -U "$PG_USER" -d "$target_db" -t -c "SELECT COUNT(*) FROM dim_products;" 2>/dev/null | xargs || echo "0")
    
    log_message "SUCCESS" "Restore verification completed"
    log_message "INFO" "Tables in database: $table_count"
    log_message "INFO" "Inventory records: $inventory_count"
    log_message "INFO" "Product records: $products_count"
    
    return 0
}

# Function to show available backups
show_available_backups() {
    echo -e "${BLUE}=== AVAILABLE BACKUPS ===${NC}"
    
    if [ ! -d "$BACKUP_DIR" ]; then
        echo "No backup directory found: $BACKUP_DIR"
        return 1
    fi
    
    echo "Backup directory: $BACKUP_DIR"
    echo ""
    
    # List backup files with details
    find "$BACKUP_DIR" -name "*backup*" -type f -printf "%T@ %Tc %s %p\n" 2>/dev/null | sort -nr | head -20 | while IFS= read -r line; do
        local timestamp=$(echo "$line" | cut -d' ' -f2-7)
        local size=$(echo "$line" | cut -d' ' -f8)
        local file=$(echo "$line" | cut -d' ' -f9-)
        local human_size=$(numfmt --to=iec --suffix=B "$size" 2>/dev/null || echo "${size}B")
        
        echo "📁 $(basename "$file")"
        echo "   Date: $timestamp"
        echo "   Size: $human_size"
        echo "   Path: $file"
        
        # Show metadata if available
        if [ -f "${file}.metadata" ]; then
            local backup_type=$(grep "^BACKUP_TYPE=" "${file}.metadata" 2>/dev/null | cut -d'=' -f2 || echo "Unknown")
            echo "   Type: $backup_type"
        fi
        echo ""
    done
}

# Function to perform the restore
perform_restore() {
    local backup_file=$1
    local target_db=$2
    
    # Validate backup file
    if ! validate_backup_file "$backup_file"; then
        return 1
    fi
    
    # Show backup metadata
    get_backup_metadata "$backup_file"
    
    # Check if target database exists and create pre-restore backup
    if [ "$FORCE_RESTORE" != "true" ]; then
        if database_exists "$target_db"; then
            echo -e "${YELLOW}⚠️ Target database '$target_db' already exists${NC}"
            echo -e "${YELLOW}This will overwrite the existing database${NC}"
            read -p "Do you want to continue? (y/N): " -n 1 -r
            echo
            if [[ ! $REPLY =~ ^[Yy]$ ]]; then
                log_message "INFO" "Restore cancelled by user"
                exit 0
            fi
            
            # Create pre-restore backup
            create_pre_restore_backup "$target_db"
        else
            # Create target database if it doesn't exist
            create_database "$target_db"
        fi
    fi
    
    # Perform restore based on backup format
    if [[ "$backup_file" == *.dump ]]; then
        restore_custom_format "$backup_file" "$target_db"
    elif [[ "$backup_file" == *.sql ]]; then
        restore_sql_format "$backup_file" "$target_db"
    elif [ -d "$backup_file" ]; then
        if [ -f "$backup_file/toc.dat" ]; then
            restore_directory_format "$backup_file" "$target_db"
        else
            restore_base_backup "$backup_file" "$target_db"
        fi
    elif [[ "$backup_file" == *.tar ]] || [[ "$backup_file" == *.tar.gz ]]; then
        log_message "ERROR" "TAR format restore not implemented yet"
        return 1
    else
        log_message "ERROR" "Unknown backup format"
        return 1
    fi
    
    # Verify restore
    if verify_restore "$target_db"; then
        log_message "SUCCESS" "Restore completed and verified successfully"
        return 0
    else
        log_message "ERROR" "Restore verification failed"
        return 1
    fi
}

# Main execution
main() {
    # Show usage if no arguments provided
    if [ $# -eq 0 ]; then
        echo "Usage: $0 <backup_file> [restore_type] [target_database] [force]"
        echo ""
        echo "Parameters:"
        echo "  backup_file      - Path to backup file or directory"
        echo "  restore_type     - full|schema|data|selective (default: full)"
        echo "  target_database  - Target database name (default: $PG_NAME)"
        echo "  force           - Skip confirmation prompts (default: false)"
        echo ""
        echo "Examples:"
        echo "  $0 /path/to/backup.dump"
        echo "  $0 /path/to/backup.sql schema test_db"
        echo "  $0 /path/to/backup_dir full restored_db true"
        echo ""
        show_available_backups
        exit 0
    fi
    
    echo -e "${YELLOW}Step 1: Checking prerequisites...${NC}"
    
    # Check PostgreSQL connection
    if ! check_connection; then
        echo -e "${RED}❌ Cannot connect to PostgreSQL${NC}"
        exit 1
    fi
    
    echo -e "${GREEN}✅ PostgreSQL connection successful${NC}"
    
    echo -e "${YELLOW}Step 2: Validating backup file...${NC}"
    
    # Check if backup file exists
    if [ ! -f "$BACKUP_FILE" ] && [ ! -d "$BACKUP_FILE" ]; then
        echo -e "${RED}❌ Backup file not found: $BACKUP_FILE${NC}"
        echo ""
        show_available_backups
        exit 1
    fi
    
    echo -e "${GREEN}✅ Backup file found${NC}"
    
    echo -e "${YELLOW}Step 3: Performing restore...${NC}"
    
    # Perform restore
    if perform_restore "$BACKUP_FILE" "$TARGET_DB"; then
        echo -e "${GREEN}✅ Restore completed successfully${NC}"
    else
        echo -e "${RED}❌ Restore failed${NC}"
        exit 1
    fi
    
    echo ""
    echo -e "${GREEN}🎉 Restore process completed successfully!${NC}"
    echo -e "${BLUE}🗄️ Database: $TARGET_DB${NC}"
    echo -e "${BLUE}📝 Log file: $LOG_FILE${NC}"
    echo ""
    
    log_message "SUCCESS" "Restore process completed successfully"
}

# Handle script interruption
trap 'echo -e "\n${RED}Restore interrupted. Check log: $LOG_FILE${NC}"; exit 1' INT TERM

# Run main function
main "$@"