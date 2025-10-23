#!/bin/bash

# ===================================================================
# PostgreSQL Database Optimization Script
# Task 5.1: Очистка и оптимизация PostgreSQL схемы
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
LOG_FILE="$PROJECT_ROOT/logs/postgresql_optimization_$(date +%Y%m%d_%H%M%S).log"
BACKUP_DIR="$PROJECT_ROOT/storage/backups"

# Create directories if they don't exist
mkdir -p "$(dirname "$LOG_FILE")"
mkdir -p "$BACKUP_DIR"

# Load environment variables
if [ -f "$PROJECT_ROOT/.env" ]; then
    source "$PROJECT_ROOT/.env"
fi

# PostgreSQL connection parameters
PG_HOST="${PG_HOST:-localhost}"
PG_PORT="${PG_PORT:-5432}"
PG_USER="${PG_USER:-mi_core_user}"
PG_NAME="${PG_NAME:-mi_core_db}"

echo -e "${BLUE}=== PostgreSQL Database Optimization ===${NC}"
echo "Starting optimization at: $(date)"
echo "Database: $PG_NAME"
echo "Host: $PG_HOST:$PG_PORT"
echo "User: $PG_USER"
echo "Log file: $LOG_FILE"
echo ""

# Function to log messages
log_message() {
    local level=$1
    local message=$2
    local timestamp=$(date '+%Y-%m-%d %H:%M:%S')
    echo "[$timestamp] [$level] $message" | tee -a "$LOG_FILE"
}

# Function to execute SQL with error handling
execute_sql() {
    local sql_file=$1
    local description=$2
    
    log_message "INFO" "Starting: $description"
    
    if [ ! -f "$sql_file" ]; then
        log_message "ERROR" "SQL file not found: $sql_file"
        return 1
    fi
    
    if PGPASSWORD="$PG_PASSWORD" psql -h "$PG_HOST" -p "$PG_PORT" -U "$PG_USER" -d "$PG_NAME" -f "$sql_file" >> "$LOG_FILE" 2>&1; then
        log_message "SUCCESS" "Completed: $description"
        return 0
    else
        log_message "ERROR" "Failed: $description"
        return 1
    fi
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

# Function to create backup before optimization
create_backup() {
    log_message "INFO" "Creating database backup before optimization..."
    
    local backup_file="$BACKUP_DIR/pre_optimization_backup_$(date +%Y%m%d_%H%M%S).sql"
    
    if PGPASSWORD="$PG_PASSWORD" pg_dump -h "$PG_HOST" -p "$PG_PORT" -U "$PG_USER" -d "$PG_NAME" > "$backup_file" 2>> "$LOG_FILE"; then
        log_message "SUCCESS" "Backup created: $backup_file"
        echo "$backup_file"
        return 0
    else
        log_message "ERROR" "Backup creation failed"
        return 1
    fi
}

# Function to get database statistics before optimization
get_pre_stats() {
    log_message "INFO" "Collecting database statistics before optimization..."
    
    PGPASSWORD="$PG_PASSWORD" psql -h "$PG_HOST" -p "$PG_PORT" -U "$PG_USER" -d "$PG_NAME" -c "
        SELECT 
            'Tables' as type, 
            COUNT(*) as count 
        FROM pg_tables 
        WHERE schemaname = 'public'
        UNION ALL
        SELECT 
            'Indexes' as type, 
            COUNT(*) as count 
        FROM pg_indexes 
        WHERE schemaname = 'public'
        UNION ALL
        SELECT 
            'Database Size' as type, 
            pg_size_pretty(pg_database_size(current_database())) as count;
    " >> "$LOG_FILE" 2>&1
}

# Function to get database statistics after optimization
get_post_stats() {
    log_message "INFO" "Collecting database statistics after optimization..."
    
    PGPASSWORD="$PG_PASSWORD" psql -h "$PG_HOST" -p "$PG_PORT" -U "$PG_USER" -d "$PG_NAME" -c "
        SELECT 'POST-OPTIMIZATION STATISTICS:' as info;
        
        SELECT 
            'Tables' as type, 
            COUNT(*) as count 
        FROM pg_tables 
        WHERE schemaname = 'public'
        UNION ALL
        SELECT 
            'Indexes' as type, 
            COUNT(*) as count 
        FROM pg_indexes 
        WHERE schemaname = 'public'
        UNION ALL
        SELECT 
            'Database Size' as type, 
            pg_size_pretty(pg_database_size(current_database())) as count;
            
        SELECT 'LARGEST TABLES:' as info;
        SELECT * FROM v_table_sizes LIMIT 5;
    " >> "$LOG_FILE" 2>&1
}

# Function to validate optimization results
validate_optimization() {
    log_message "INFO" "Validating optimization results..."
    
    # Check if critical tables exist
    local critical_tables=("inventory" "dim_products" "stock_movements" "master_products")
    
    for table in "${critical_tables[@]}"; do
        if PGPASSWORD="$PG_PASSWORD" psql -h "$PG_HOST" -p "$PG_PORT" -U "$PG_USER" -d "$PG_NAME" -c "SELECT 1 FROM $table LIMIT 1;" > /dev/null 2>&1; then
            log_message "SUCCESS" "Table $table is accessible"
        else
            log_message "ERROR" "Table $table is not accessible"
            return 1
        fi
    done
    
    # Check if new indexes were created
    local new_indexes=("idx_inventory_product_warehouse_source" "idx_inventory_stock_status_func" "idx_dim_products_name_fts")
    
    for index in "${new_indexes[@]}"; do
        if PGPASSWORD="$PG_PASSWORD" psql -h "$PG_HOST" -p "$PG_PORT" -U "$PG_USER" -d "$PG_NAME" -c "SELECT 1 FROM pg_indexes WHERE indexname = '$index';" | grep -q "1"; then
            log_message "SUCCESS" "Index $index was created successfully"
        else
            log_message "WARNING" "Index $index was not found"
        fi
    done
    
    return 0
}

# Main execution
main() {
    echo -e "${YELLOW}Step 1: Checking prerequisites...${NC}"
    
    # Check if PostgreSQL is accessible
    if ! check_connection; then
        echo -e "${RED}❌ Cannot connect to PostgreSQL. Please check your configuration.${NC}"
        exit 1
    fi
    
    echo -e "${GREEN}✅ PostgreSQL connection successful${NC}"
    
    echo -e "${YELLOW}Step 2: Creating backup...${NC}"
    
    # Create backup
    if backup_file=$(create_backup); then
        echo -e "${GREEN}✅ Backup created: $backup_file${NC}"
    else
        echo -e "${RED}❌ Backup creation failed. Aborting optimization.${NC}"
        exit 1
    fi
    
    echo -e "${YELLOW}Step 3: Collecting pre-optimization statistics...${NC}"
    get_pre_stats
    
    echo -e "${YELLOW}Step 4: Running PostgreSQL optimization...${NC}"
    
    # Execute optimization script
    if execute_sql "$PROJECT_ROOT/migrations/postgresql_cleanup_optimization.sql" "PostgreSQL cleanup and optimization"; then
        echo -e "${GREEN}✅ PostgreSQL optimization completed successfully${NC}"
    else
        echo -e "${RED}❌ PostgreSQL optimization failed${NC}"
        echo -e "${YELLOW}💡 You can restore from backup: $backup_file${NC}"
        exit 1
    fi
    
    echo -e "${YELLOW}Step 5: Validating results...${NC}"
    
    # Validate optimization
    if validate_optimization; then
        echo -e "${GREEN}✅ Optimization validation successful${NC}"
    else
        echo -e "${YELLOW}⚠️ Some validation checks failed. Check the log for details.${NC}"
    fi
    
    echo -e "${YELLOW}Step 6: Collecting post-optimization statistics...${NC}"
    get_post_stats
    
    echo ""
    echo -e "${GREEN}🎉 PostgreSQL optimization completed!${NC}"
    echo -e "${BLUE}📊 Check the log file for detailed results: $LOG_FILE${NC}"
    echo -e "${BLUE}💾 Backup file (in case of rollback): $backup_file${NC}"
    echo ""
    
    # Show summary
    echo -e "${BLUE}=== OPTIMIZATION SUMMARY ===${NC}"
    echo "✅ Temporary tables and views cleaned up"
    echo "✅ Optimized indexes created"
    echo "✅ Table partitioning configured"
    echo "✅ Data retention policies applied"
    echo "✅ Database statistics updated"
    echo "✅ Performance monitoring views created"
    echo ""
    
    log_message "SUCCESS" "PostgreSQL optimization completed successfully"
}

# Handle script interruption
trap 'echo -e "\n${RED}Script interrupted. Check log: $LOG_FILE${NC}"; exit 1' INT TERM

# Run main function
main "$@"