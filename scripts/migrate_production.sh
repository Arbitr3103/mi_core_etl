#!/bin/bash

# Production Migration Script for Inventory Sync System
# Version: 1.0
# Usage: ./migrate_production.sh [apply|rollback|validate|dry-run]

set -e

# Configuration
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_DIR="$(dirname "$SCRIPT_DIR")"
MIGRATIONS_DIR="$PROJECT_DIR/migrations"
BACKUP_DIR="/backup/inventory_sync_migration"
LOG_FILE="/var/log/inventory_sync/migration.log"

# Load environment variables
if [ -f "$PROJECT_DIR/.env" ]; then
    source "$PROJECT_DIR/.env"
else
    echo "ERROR: .env file not found in $PROJECT_DIR"
    exit 1
fi

# Colors for output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m' # No Color

# Logging function
log() {
    local level="$1"
    shift
    local message="$*"
    local timestamp=$(date '+%Y-%m-%d %H:%M:%S')
    
    echo -e "${timestamp} [${level}] ${message}" | tee -a "$LOG_FILE"
    
    case "$level" in
        "ERROR")
            echo -e "${RED}[ERROR]${NC} $message" >&2
            ;;
        "WARN")
            echo -e "${YELLOW}[WARN]${NC} $message"
            ;;
        "INFO")
            echo -e "${GREEN}[INFO]${NC} $message"
            ;;
        "DEBUG")
            echo -e "${BLUE}[DEBUG]${NC} $message"
            ;;
    esac
}

# Check prerequisites
check_prerequisites() {
    log "INFO" "Checking prerequisites..."
    
    # Check if MySQL client is available
    if ! command -v mysql >/dev/null 2>&1; then
        log "ERROR" "MySQL client not found. Please install mysql-client."
        exit 1
    fi
    
    # Check database connection
    if ! mysql -h"${DB_HOST:-localhost}" -P"${DB_PORT:-3306}" -u"${DB_USER}" -p"${DB_PASSWORD}" -e "SELECT 1;" "${DB_NAME}" >/dev/null 2>&1; then
        log "ERROR" "Cannot connect to database. Please check credentials in .env file."
        exit 1
    fi
    
    # Check if migration files exist
    local required_files=(
        "$MIGRATIONS_DIR/production_migration.sql"
        "$MIGRATIONS_DIR/rollback_production_migration.sql"
        "$MIGRATIONS_DIR/validate_migration.sql"
    )
    
    for file in "${required_files[@]}"; do
        if [ ! -f "$file" ]; then
            log "ERROR" "Required migration file not found: $file"
            exit 1
        fi
    done
    
    # Create backup directory
    mkdir -p "$BACKUP_DIR"
    
    # Create log directory
    mkdir -p "$(dirname "$LOG_FILE")"
    
    log "INFO" "Prerequisites check passed."
}

# Create database backup
create_backup() {
    log "INFO" "Creating database backup..."
    
    local backup_file="$BACKUP_DIR/pre_migration_backup_$(date +%Y%m%d_%H%M%S).sql"
    
    mysqldump \
        -h"${DB_HOST:-localhost}" \
        -P"${DB_PORT:-3306}" \
        -u"${DB_USER}" \
        -p"${DB_PASSWORD}" \
        --single-transaction \
        --routines \
        --triggers \
        "${DB_NAME}" > "$backup_file"
    
    if [ $? -eq 0 ]; then
        log "INFO" "Backup created successfully: $backup_file"
        gzip "$backup_file"
        log "INFO" "Backup compressed: ${backup_file}.gz"
        echo "$backup_file.gz"
    else
        log "ERROR" "Failed to create backup"
        exit 1
    fi
}

# Execute SQL file
execute_sql() {
    local sql_file="$1"
    local description="$2"
    
    log "INFO" "Executing $description..."
    log "DEBUG" "SQL file: $sql_file"
    
    if [ ! -f "$sql_file" ]; then
        log "ERROR" "SQL file not found: $sql_file"
        return 1
    fi
    
    # Execute SQL and capture output
    local output_file=$(mktemp)
    local error_file=$(mktemp)
    
    mysql \
        -h"${DB_HOST:-localhost}" \
        -P"${DB_PORT:-3306}" \
        -u"${DB_USER}" \
        -p"${DB_PASSWORD}" \
        "${DB_NAME}" \
        < "$sql_file" > "$output_file" 2> "$error_file"
    
    local exit_code=$?
    
    # Log output
    if [ -s "$output_file" ]; then
        log "INFO" "SQL Output:"
        cat "$output_file" | while read line; do
            log "INFO" "  $line"
        done
    fi
    
    # Log errors
    if [ -s "$error_file" ]; then
        log "ERROR" "SQL Errors:"
        cat "$error_file" | while read line; do
            log "ERROR" "  $line"
        done
    fi
    
    # Cleanup temp files
    rm -f "$output_file" "$error_file"
    
    if [ $exit_code -eq 0 ]; then
        log "INFO" "$description completed successfully"
    else
        log "ERROR" "$description failed with exit code $exit_code"
    fi
    
    return $exit_code
}

# Dry run - show what would be executed
dry_run() {
    log "INFO" "=== DRY RUN MODE ==="
    log "INFO" "This will show what would be executed without making changes"
    
    check_prerequisites
    
    log "INFO" "Would create backup in: $BACKUP_DIR"
    log "INFO" "Would execute migration: $MIGRATIONS_DIR/production_migration.sql"
    log "INFO" "Would validate migration: $MIGRATIONS_DIR/validate_migration.sql"
    
    # Show current database state
    log "INFO" "Current database state:"
    mysql \
        -h"${DB_HOST:-localhost}" \
        -P"${DB_PORT:-3306}" \
        -u"${DB_USER}" \
        -p"${DB_PASSWORD}" \
        "${DB_NAME}" \
        -e "
        SELECT 'inventory_data' as table_name, COUNT(*) as record_count FROM inventory_data
        UNION ALL
        SELECT 'sync_logs' as table_name, COUNT(*) as record_count FROM sync_logs;
        " 2>/dev/null || log "INFO" "sync_logs table does not exist yet"
    
    log "INFO" "=== END DRY RUN ==="
}

# Apply migration
apply_migration() {
    log "INFO" "=== APPLYING MIGRATION ==="
    
    check_prerequisites
    
    # Create backup
    local backup_file=$(create_backup)
    log "INFO" "Backup created: $backup_file"
    
    # Execute migration
    if execute_sql "$MIGRATIONS_DIR/production_migration.sql" "Production Migration"; then
        log "INFO" "Migration applied successfully"
        
        # Validate migration
        validate_migration
        
        log "INFO" "=== MIGRATION COMPLETED ==="
        log "INFO" "Backup available at: $backup_file"
        log "INFO" "To rollback: $0 rollback"
    else
        log "ERROR" "Migration failed. Database backup available at: $backup_file"
        log "ERROR" "Please review the errors and consider rollback if necessary"
        exit 1
    fi
}

# Rollback migration
rollback_migration() {
    log "INFO" "=== ROLLING BACK MIGRATION ==="
    
    check_prerequisites
    
    # Confirm rollback
    echo -n "Are you sure you want to rollback the migration? This will remove all changes. (yes/no): "
    read -r confirmation
    
    if [ "$confirmation" != "yes" ]; then
        log "INFO" "Rollback cancelled"
        exit 0
    fi
    
    # Create backup before rollback
    local backup_file=$(create_backup)
    log "INFO" "Pre-rollback backup created: $backup_file"
    
    # Execute rollback
    if execute_sql "$MIGRATIONS_DIR/rollback_production_migration.sql" "Migration Rollback"; then
        log "INFO" "Rollback completed successfully"
        log "INFO" "=== ROLLBACK COMPLETED ==="
    else
        log "ERROR" "Rollback failed. Please check the errors and consider manual intervention"
        exit 1
    fi
}

# Validate migration
validate_migration() {
    log "INFO" "=== VALIDATING MIGRATION ==="
    
    check_prerequisites
    
    if execute_sql "$MIGRATIONS_DIR/validate_migration.sql" "Migration Validation"; then
        log "INFO" "Validation completed"
        
        # Check for any failures in validation
        local validation_result=$(mysql \
            -h"${DB_HOST:-localhost}" \
            -P"${DB_PORT:-3306}" \
            -u"${DB_USER}" \
            -p"${DB_PASSWORD}" \
            "${DB_NAME}" \
            -se "SELECT final_status FROM (
                SELECT 
                    CASE 
                        WHEN (
                            SELECT COUNT(*) FROM information_schema.columns 
                            WHERE table_schema = DATABASE() 
                            AND table_name = 'inventory_data' 
                            AND column_name IN ('warehouse_name', 'stock_type', 'quantity_present', 'quantity_reserved', 'last_sync_at')
                        ) = 5
                        AND (
                            SELECT COUNT(*) FROM information_schema.tables 
                            WHERE table_schema = DATABASE() 
                            AND table_name = 'sync_logs'
                        ) = 1
                        THEN 'MIGRATION_SUCCESSFUL'
                        ELSE 'MIGRATION_FAILED_OR_INCOMPLETE'
                    END as final_status
            ) as validation" 2>/dev/null)
        
        if [ "$validation_result" = "MIGRATION_SUCCESSFUL" ]; then
            log "INFO" "✓ Migration validation PASSED"
        else
            log "ERROR" "✗ Migration validation FAILED: $validation_result"
            return 1
        fi
        
        log "INFO" "=== VALIDATION COMPLETED ==="
    else
        log "ERROR" "Validation failed"
        return 1
    fi
}

# Show migration status
show_status() {
    log "INFO" "=== MIGRATION STATUS ==="
    
    check_prerequisites
    
    # Check if migration has been applied
    local migration_applied=$(mysql \
        -h"${DB_HOST:-localhost}" \
        -P"${DB_PORT:-3306}" \
        -u"${DB_USER}" \
        -p"${DB_PASSWORD}" \
        "${DB_NAME}" \
        -se "SELECT COUNT(*) FROM information_schema.columns 
             WHERE table_schema = DATABASE() 
             AND table_name = 'inventory_data' 
             AND column_name = 'warehouse_name'" 2>/dev/null || echo "0")
    
    if [ "$migration_applied" -gt 0 ]; then
        log "INFO" "Migration status: APPLIED"
        
        # Show migration log if available
        mysql \
            -h"${DB_HOST:-localhost}" \
            -P"${DB_PORT:-3306}" \
            -u"${DB_USER}" \
            -p"${DB_PASSWORD}" \
            "${DB_NAME}" \
            -e "SELECT * FROM migration_log ORDER BY created_at DESC LIMIT 5" 2>/dev/null || log "INFO" "No migration log available"
    else
        log "INFO" "Migration status: NOT APPLIED"
    fi
    
    log "INFO" "=== STATUS CHECK COMPLETED ==="
}

# Main function
main() {
    local action="${1:-help}"
    
    case "$action" in
        "apply")
            apply_migration
            ;;
        "rollback")
            rollback_migration
            ;;
        "validate")
            validate_migration
            ;;
        "dry-run")
            dry_run
            ;;
        "status")
            show_status
            ;;
        "help"|*)
            echo "Usage: $0 [apply|rollback|validate|dry-run|status]"
            echo ""
            echo "Commands:"
            echo "  apply     - Apply the migration to production database"
            echo "  rollback  - Rollback the migration (removes all changes)"
            echo "  validate  - Validate that migration was applied correctly"
            echo "  dry-run   - Show what would be executed without making changes"
            echo "  status    - Show current migration status"
            echo "  help      - Show this help message"
            echo ""
            echo "Examples:"
            echo "  $0 dry-run    # Preview changes"
            echo "  $0 apply      # Apply migration"
            echo "  $0 validate   # Check migration"
            echo "  $0 rollback   # Undo migration"
            echo ""
            echo "Prerequisites:"
            echo "  - .env file with database credentials"
            echo "  - MySQL client installed"
            echo "  - Database backup recommended before applying"
            exit 0
            ;;
    esac
}

# Trap errors and cleanup
trap 'log "ERROR" "Script interrupted or failed at line $LINENO"' ERR

# Run main function
main "$@"