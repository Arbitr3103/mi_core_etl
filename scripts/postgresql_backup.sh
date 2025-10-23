#!/bin/bash

# ===================================================================
# PostgreSQL Backup Script with pg_dump and WAL Archiving
# Task 5.3: Настроить резервное копирование PostgreSQL
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
LOG_FILE="$PROJECT_ROOT/logs/backup_$(date +%Y%m%d_%H%M%S).log"

# Create directories if they don't exist
mkdir -p "$BACKUP_DIR"
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

# Backup configuration
BACKUP_TYPE="${1:-full}"  # full, schema, data, incremental
RETENTION_DAYS="${BACKUP_RETENTION_DAYS:-30}"
COMPRESS_BACKUPS="${COMPRESS_BACKUPS:-true}"
BACKUP_FORMAT="${BACKUP_FORMAT:-custom}"  # custom, plain, directory, tar

echo -e "${BLUE}=== PostgreSQL Backup System ===${NC}"
echo "Backup type: $BACKUP_TYPE"
echo "Database: $PG_NAME"
echo "Host: $PG_HOST:$PG_PORT"
echo "Backup directory: $BACKUP_DIR"
echo "Retention: $RETENTION_DAYS days"
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
    
    if PGPASSWORD="$PG_PASSWORD" psql -h "$PG_HOST" -p "$PG_PORT" -U "$PG_USER" -d "$PG_NAME" -c "SELECT version();" > /dev/null 2>&1; then
        log_message "SUCCESS" "PostgreSQL connection successful"
        return 0
    else
        log_message "ERROR" "Cannot connect to PostgreSQL database"
        return 1
    fi
}

# Function to get database size
get_database_size() {
    PGPASSWORD="$PG_PASSWORD" psql -h "$PG_HOST" -p "$PG_PORT" -U "$PG_USER" -d "$PG_NAME" -t -c "SELECT pg_size_pretty(pg_database_size(current_database()));" | xargs
}

# Function to create full backup
create_full_backup() {
    local backup_file="$BACKUP_DIR/full_backup_$(date +%Y%m%d_%H%M%S)"
    local db_size=$(get_database_size)
    
    log_message "INFO" "Creating full backup (Database size: $db_size)..."
    
    # Determine file extension based on format
    case $BACKUP_FORMAT in
        "custom")
            backup_file="${backup_file}.dump"
            format_option="-Fc"
            ;;
        "directory")
            backup_file="${backup_file}_dir"
            format_option="-Fd"
            mkdir -p "$backup_file"
            ;;
        "tar")
            backup_file="${backup_file}.tar"
            format_option="-Ft"
            ;;
        *)
            backup_file="${backup_file}.sql"
            format_option="-Fp"
            ;;
    esac
    
    # Add compression if enabled and format supports it
    local compression_option=""
    if [ "$COMPRESS_BACKUPS" = "true" ] && [ "$BACKUP_FORMAT" != "plain" ]; then
        compression_option="-Z 6"  # Compression level 6
    fi
    
    # Create backup with verbose output
    if PGPASSWORD="$PG_PASSWORD" pg_dump \
        -h "$PG_HOST" \
        -p "$PG_PORT" \
        -U "$PG_USER" \
        -d "$PG_NAME" \
        $format_option \
        $compression_option \
        --verbose \
        --no-password \
        --create \
        --clean \
        --if-exists \
        --quote-all-identifiers \
        --no-privileges \
        --no-owner \
        -f "$backup_file" 2>> "$LOG_FILE"; then
        
        # Get backup file size
        if [ -f "$backup_file" ]; then
            local backup_size=$(du -h "$backup_file" | cut -f1)
            log_message "SUCCESS" "Full backup created: $backup_file (Size: $backup_size)"
        elif [ -d "$backup_file" ]; then
            local backup_size=$(du -sh "$backup_file" | cut -f1)
            log_message "SUCCESS" "Full backup created: $backup_file (Size: $backup_size)"
        fi
        
        echo "$backup_file"
        return 0
    else
        log_message "ERROR" "Full backup failed"
        return 1
    fi
}

# Function to create schema-only backup
create_schema_backup() {
    local backup_file="$BACKUP_DIR/schema_backup_$(date +%Y%m%d_%H%M%S).sql"
    
    log_message "INFO" "Creating schema-only backup..."
    
    if PGPASSWORD="$PG_PASSWORD" pg_dump \
        -h "$PG_HOST" \
        -p "$PG_PORT" \
        -U "$PG_USER" \
        -d "$PG_NAME" \
        --schema-only \
        --verbose \
        --no-password \
        --create \
        --clean \
        --if-exists \
        --quote-all-identifiers \
        -f "$backup_file" 2>> "$LOG_FILE"; then
        
        local backup_size=$(du -h "$backup_file" | cut -f1)
        log_message "SUCCESS" "Schema backup created: $backup_file (Size: $backup_size)"
        echo "$backup_file"
        return 0
    else
        log_message "ERROR" "Schema backup failed"
        return 1
    fi
}

# Function to create data-only backup
create_data_backup() {
    local backup_file="$BACKUP_DIR/data_backup_$(date +%Y%m%d_%H%M%S)"
    
    log_message "INFO" "Creating data-only backup..."
    
    # Use custom format for data backups for better compression
    backup_file="${backup_file}.dump"
    
    if PGPASSWORD="$PG_PASSWORD" pg_dump \
        -h "$PG_HOST" \
        -p "$PG_PORT" \
        -U "$PG_USER" \
        -d "$PG_NAME" \
        -Fc \
        -Z 6 \
        --data-only \
        --verbose \
        --no-password \
        --disable-triggers \
        -f "$backup_file" 2>> "$LOG_FILE"; then
        
        local backup_size=$(du -h "$backup_file" | cut -f1)
        log_message "SUCCESS" "Data backup created: $backup_file (Size: $backup_size)"
        echo "$backup_file"
        return 0
    else
        log_message "ERROR" "Data backup failed"
        return 1
    fi
}

# Function to create incremental backup using WAL files
create_incremental_backup() {
    local wal_backup_dir="$BACKUP_DIR/wal_archive"
    local base_backup_dir="$BACKUP_DIR/base_backup_$(date +%Y%m%d_%H%M%S)"
    
    log_message "INFO" "Creating incremental backup with WAL archiving..."
    
    # Create directories
    mkdir -p "$wal_backup_dir"
    mkdir -p "$base_backup_dir"
    
    # Create base backup using pg_basebackup
    if PGPASSWORD="$PG_PASSWORD" pg_basebackup \
        -h "$PG_HOST" \
        -p "$PG_PORT" \
        -U "$PG_USER" \
        -D "$base_backup_dir" \
        -Ft \
        -z \
        -P \
        -v \
        -W 2>> "$LOG_FILE"; then
        
        local backup_size=$(du -sh "$base_backup_dir" | cut -f1)
        log_message "SUCCESS" "Base backup created: $base_backup_dir (Size: $backup_size)"
        
        # Create WAL archive info
        echo "Base backup created at: $(date)" > "$base_backup_dir/backup_info.txt"
        echo "WAL archive directory: $wal_backup_dir" >> "$base_backup_dir/backup_info.txt"
        
        echo "$base_backup_dir"
        return 0
    else
        log_message "ERROR" "Incremental backup failed"
        return 1
    fi
}

# Function to create table-specific backups for large tables
create_table_backups() {
    local tables=("inventory" "stock_movements" "fact_orders" "audit_log")
    local table_backup_dir="$BACKUP_DIR/table_backups_$(date +%Y%m%d_%H%M%S)"
    
    log_message "INFO" "Creating individual table backups..."
    
    mkdir -p "$table_backup_dir"
    
    for table in "${tables[@]}"; do
        local table_file="$table_backup_dir/${table}_$(date +%Y%m%d_%H%M%S).dump"
        
        log_message "INFO" "Backing up table: $table"
        
        if PGPASSWORD="$PG_PASSWORD" pg_dump \
            -h "$PG_HOST" \
            -p "$PG_PORT" \
            -U "$PG_USER" \
            -d "$PG_NAME" \
            -Fc \
            -Z 6 \
            --verbose \
            --no-password \
            --table="$table" \
            -f "$table_file" 2>> "$LOG_FILE"; then
            
            local table_size=$(du -h "$table_file" | cut -f1)
            log_message "SUCCESS" "Table $table backed up: $table_file (Size: $table_size)"
        else
            log_message "ERROR" "Failed to backup table: $table"
        fi
    done
    
    echo "$table_backup_dir"
}

# Function to cleanup old backups
cleanup_old_backups() {
    log_message "INFO" "Cleaning up backups older than $RETENTION_DAYS days..."
    
    local deleted_count=0
    
    # Find and delete old backup files
    while IFS= read -r -d '' file; do
        log_message "INFO" "Deleting old backup: $(basename "$file")"
        rm -rf "$file"
        ((deleted_count++))
    done < <(find "$BACKUP_DIR" -type f -name "*backup*" -mtime +$RETENTION_DAYS -print0 2>/dev/null)
    
    # Find and delete old backup directories
    while IFS= read -r -d '' dir; do
        log_message "INFO" "Deleting old backup directory: $(basename "$dir")"
        rm -rf "$dir"
        ((deleted_count++))
    done < <(find "$BACKUP_DIR" -type d -name "*backup*" -mtime +$RETENTION_DAYS -print0 2>/dev/null)
    
    log_message "SUCCESS" "Cleanup completed. Deleted $deleted_count old backup(s)"
}

# Function to verify backup integrity
verify_backup() {
    local backup_file=$1
    
    if [ ! -f "$backup_file" ] && [ ! -d "$backup_file" ]; then
        log_message "ERROR" "Backup file/directory not found: $backup_file"
        return 1
    fi
    
    log_message "INFO" "Verifying backup integrity: $(basename "$backup_file")"
    
    # For custom format backups, use pg_restore to verify
    if [[ "$backup_file" == *.dump ]]; then
        if PGPASSWORD="$PG_PASSWORD" pg_restore --list "$backup_file" > /dev/null 2>&1; then
            log_message "SUCCESS" "Backup verification successful"
            return 0
        else
            log_message "ERROR" "Backup verification failed"
            return 1
        fi
    # For SQL backups, check if file is readable and has content
    elif [[ "$backup_file" == *.sql ]]; then
        if [ -s "$backup_file" ] && head -n 10 "$backup_file" | grep -q "PostgreSQL"; then
            log_message "SUCCESS" "Backup verification successful"
            return 0
        else
            log_message "ERROR" "Backup verification failed"
            return 1
        fi
    # For directory backups, check if base.tar exists
    elif [ -d "$backup_file" ]; then
        if [ -f "$backup_file/base.tar" ] || [ -f "$backup_file/base.tar.gz" ]; then
            log_message "SUCCESS" "Backup verification successful"
            return 0
        else
            log_message "ERROR" "Backup verification failed"
            return 1
        fi
    fi
    
    log_message "WARNING" "Unknown backup format, skipping verification"
    return 0
}

# Function to create backup metadata
create_backup_metadata() {
    local backup_file=$1
    local metadata_file="${backup_file}.metadata"
    
    log_message "INFO" "Creating backup metadata..."
    
    cat > "$metadata_file" << EOF
# PostgreSQL Backup Metadata
# Generated at: $(date)

BACKUP_FILE=$(basename "$backup_file")
BACKUP_TYPE=$BACKUP_TYPE
BACKUP_FORMAT=$BACKUP_FORMAT
DATABASE_NAME=$PG_NAME
DATABASE_HOST=$PG_HOST
DATABASE_PORT=$PG_PORT
BACKUP_DATE=$(date '+%Y-%m-%d %H:%M:%S')
BACKUP_SIZE=$(if [ -f "$backup_file" ]; then du -h "$backup_file" | cut -f1; elif [ -d "$backup_file" ]; then du -sh "$backup_file" | cut -f1; else echo "Unknown"; fi)
DATABASE_SIZE=$(get_database_size)
POSTGRESQL_VERSION=$(PGPASSWORD="$PG_PASSWORD" psql -h "$PG_HOST" -p "$PG_PORT" -U "$PG_USER" -d "$PG_NAME" -t -c "SELECT version();" | xargs)
COMPRESSION_ENABLED=$COMPRESS_BACKUPS
RETENTION_DAYS=$RETENTION_DAYS

# Restore command example:
# For custom format: pg_restore -h $PG_HOST -p $PG_PORT -U $PG_USER -d $PG_NAME -v "$backup_file"
# For SQL format: psql -h $PG_HOST -p $PG_PORT -U $PG_USER -d $PG_NAME -f "$backup_file"
EOF

    log_message "SUCCESS" "Metadata created: $metadata_file"
}

# Function to send backup notification (placeholder for email/webhook)
send_backup_notification() {
    local status=$1
    local backup_file=$2
    local message=$3
    
    # This is a placeholder for notification system
    # You can implement email, Slack, or webhook notifications here
    
    log_message "INFO" "Backup notification: $status - $message"
    
    # Example: Send to webhook (uncomment and configure as needed)
    # curl -X POST "https://your-webhook-url.com/backup-notification" \
    #      -H "Content-Type: application/json" \
    #      -d "{\"status\":\"$status\",\"backup_file\":\"$backup_file\",\"message\":\"$message\",\"timestamp\":\"$(date)\"}"
}

# Main backup function
perform_backup() {
    local backup_file=""
    
    case $BACKUP_TYPE in
        "full")
            backup_file=$(create_full_backup)
            ;;
        "schema")
            backup_file=$(create_schema_backup)
            ;;
        "data")
            backup_file=$(create_data_backup)
            ;;
        "incremental")
            backup_file=$(create_incremental_backup)
            ;;
        "tables")
            backup_file=$(create_table_backups)
            ;;
        *)
            log_message "ERROR" "Unknown backup type: $BACKUP_TYPE"
            echo "Usage: $0 [full|schema|data|incremental|tables]"
            exit 1
            ;;
    esac
    
    if [ -n "$backup_file" ]; then
        # Verify backup
        if verify_backup "$backup_file"; then
            # Create metadata
            create_backup_metadata "$backup_file"
            
            # Send success notification
            send_backup_notification "SUCCESS" "$backup_file" "Backup completed successfully"
            
            log_message "SUCCESS" "Backup process completed successfully"
            echo "$backup_file"
        else
            send_backup_notification "ERROR" "$backup_file" "Backup verification failed"
            log_message "ERROR" "Backup verification failed"
            exit 1
        fi
    else
        send_backup_notification "ERROR" "" "Backup creation failed"
        log_message "ERROR" "Backup creation failed"
        exit 1
    fi
}

# Function to show backup statistics
show_backup_statistics() {
    log_message "INFO" "Generating backup statistics..."
    
    echo -e "${BLUE}=== BACKUP STATISTICS ===${NC}"
    
    # Count backups by type
    echo "Backup files in $BACKUP_DIR:"
    find "$BACKUP_DIR" -name "*backup*" -type f 2>/dev/null | wc -l | xargs echo "  Total files:"
    
    # Show disk usage
    echo "Disk usage:"
    du -sh "$BACKUP_DIR" 2>/dev/null | xargs echo "  Total size:"
    
    # Show recent backups
    echo "Recent backups (last 5):"
    find "$BACKUP_DIR" -name "*backup*" -type f -printf "%T@ %Tc %p\n" 2>/dev/null | sort -nr | head -5 | while read -r line; do
        echo "  $(echo "$line" | cut -d' ' -f2- | cut -d' ' -f1-6): $(basename "$(echo "$line" | awk '{print $NF}')")"
    done
    
    echo ""
}

# Main execution
main() {
    echo -e "${YELLOW}Step 1: Checking prerequisites...${NC}"
    
    # Check PostgreSQL connection
    if ! check_connection; then
        echo -e "${RED}❌ Cannot connect to PostgreSQL${NC}"
        exit 1
    fi
    
    echo -e "${GREEN}✅ PostgreSQL connection successful${NC}"
    
    echo -e "${YELLOW}Step 2: Performing $BACKUP_TYPE backup...${NC}"
    
    # Perform backup
    backup_file=$(perform_backup)
    
    if [ $? -eq 0 ]; then
        echo -e "${GREEN}✅ Backup completed: $(basename "$backup_file")${NC}"
    else
        echo -e "${RED}❌ Backup failed${NC}"
        exit 1
    fi
    
    echo -e "${YELLOW}Step 3: Cleaning up old backups...${NC}"
    
    # Cleanup old backups
    cleanup_old_backups
    
    echo -e "${GREEN}✅ Cleanup completed${NC}"
    
    echo -e "${YELLOW}Step 4: Generating statistics...${NC}"
    
    # Show statistics
    show_backup_statistics
    
    echo ""
    echo -e "${GREEN}🎉 Backup process completed successfully!${NC}"
    echo -e "${BLUE}📁 Backup file: $backup_file${NC}"
    echo -e "${BLUE}📝 Log file: $LOG_FILE${NC}"
    echo -e "${BLUE}📊 Backup directory: $BACKUP_DIR${NC}"
    echo ""
    
    log_message "SUCCESS" "Backup process completed successfully"
}

# Handle script interruption
trap 'echo -e "\n${RED}Backup interrupted. Check log: $LOG_FILE${NC}"; exit 1' INT TERM

# Show usage if no arguments provided
if [ $# -eq 0 ]; then
    echo "Usage: $0 [full|schema|data|incremental|tables]"
    echo ""
    echo "Backup types:"
    echo "  full        - Complete database backup (default)"
    echo "  schema      - Schema-only backup"
    echo "  data        - Data-only backup"
    echo "  incremental - Base backup with WAL archiving"
    echo "  tables      - Individual table backups"
    echo ""
    exit 0
fi

# Run main function
main "$@"