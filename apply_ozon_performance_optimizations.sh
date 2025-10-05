#!/bin/bash

# Apply Ozon Performance Optimizations
# This script applies database indexes and performance optimizations for Ozon analytics

set -e

# Colors for output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m' # No Color

# Configuration
DB_HOST="${DB_HOST:-localhost}"
DB_PORT="${DB_PORT:-3306}"
DB_NAME="${DB_NAME:-manhattan_analytics}"
DB_USER="${DB_USER:-root}"
DB_PASS="${DB_PASS:-}"

# Function to print colored output
print_status() {
    echo -e "${BLUE}[INFO]${NC} $1"
}

print_success() {
    echo -e "${GREEN}[SUCCESS]${NC} $1"
}

print_warning() {
    echo -e "${YELLOW}[WARNING]${NC} $1"
}

print_error() {
    echo -e "${RED}[ERROR]${NC} $1"
}

# Function to execute SQL file
execute_sql_file() {
    local file=$1
    local description=$2
    
    print_status "Executing $description..."
    
    if [ ! -f "$file" ]; then
        print_error "SQL file not found: $file"
        return 1
    fi
    
    if [ -n "$DB_PASS" ]; then
        mysql -h"$DB_HOST" -P"$DB_PORT" -u"$DB_USER" -p"$DB_PASS" "$DB_NAME" < "$file"
    else
        mysql -h"$DB_HOST" -P"$DB_PORT" -u"$DB_USER" "$DB_NAME" < "$file"
    fi
    
    if [ $? -eq 0 ]; then
        print_success "$description completed successfully"
    else
        print_error "$description failed"
        return 1
    fi
}

# Function to check if MySQL is available
check_mysql() {
    print_status "Checking MySQL connection..."
    
    if [ -n "$DB_PASS" ]; then
        mysql -h"$DB_HOST" -P"$DB_PORT" -u"$DB_USER" -p"$DB_PASS" -e "SELECT 1;" > /dev/null 2>&1
    else
        mysql -h"$DB_HOST" -P"$DB_PORT" -u"$DB_USER" -e "SELECT 1;" > /dev/null 2>&1
    fi
    
    if [ $? -eq 0 ]; then
        print_success "MySQL connection successful"
    else
        print_error "Cannot connect to MySQL. Please check your connection settings."
        exit 1
    fi
}

# Function to check if database exists
check_database() {
    print_status "Checking if database '$DB_NAME' exists..."
    
    if [ -n "$DB_PASS" ]; then
        DB_EXISTS=$(mysql -h"$DB_HOST" -P"$DB_PORT" -u"$DB_USER" -p"$DB_PASS" -e "SHOW DATABASES LIKE '$DB_NAME';" | grep "$DB_NAME" || true)
    else
        DB_EXISTS=$(mysql -h"$DB_HOST" -P"$DB_PORT" -u"$DB_USER" -e "SHOW DATABASES LIKE '$DB_NAME';" | grep "$DB_NAME" || true)
    fi
    
    if [ -n "$DB_EXISTS" ]; then
        print_success "Database '$DB_NAME' exists"
    else
        print_error "Database '$DB_NAME' does not exist. Please create it first."
        exit 1
    fi
}

# Function to backup database
backup_database() {
    print_status "Creating database backup..."
    
    local backup_file="ozon_analytics_backup_$(date +%Y%m%d_%H%M%S).sql"
    
    if [ -n "$DB_PASS" ]; then
        mysqldump -h"$DB_HOST" -P"$DB_PORT" -u"$DB_USER" -p"$DB_PASS" "$DB_NAME" > "$backup_file"
    else
        mysqldump -h"$DB_HOST" -P"$DB_PORT" -u"$DB_USER" "$DB_NAME" > "$backup_file"
    fi
    
    if [ $? -eq 0 ]; then
        print_success "Database backup created: $backup_file"
    else
        print_warning "Database backup failed, but continuing with optimizations..."
    fi
}

# Function to analyze table performance
analyze_tables() {
    print_status "Analyzing table performance..."
    
    local tables=("ozon_funnel_data" "ozon_demographics" "ozon_campaigns" "ozon_cache")
    
    for table in "${tables[@]}"; do
        print_status "Analyzing table: $table"
        
        if [ -n "$DB_PASS" ]; then
            mysql -h"$DB_HOST" -P"$DB_PORT" -u"$DB_USER" -p"$DB_PASS" "$DB_NAME" -e "ANALYZE TABLE $table;"
        else
            mysql -h"$DB_HOST" -P"$DB_PORT" -u"$DB_USER" "$DB_NAME" -e "ANALYZE TABLE $table;"
        fi
    done
    
    print_success "Table analysis completed"
}

# Function to optimize tables
optimize_tables() {
    print_status "Optimizing tables..."
    
    local tables=("ozon_funnel_data" "ozon_demographics" "ozon_campaigns" "ozon_cache")
    
    for table in "${tables[@]}"; do
        print_status "Optimizing table: $table"
        
        if [ -n "$DB_PASS" ]; then
            mysql -h"$DB_HOST" -P"$DB_PORT" -u"$DB_USER" -p"$DB_PASS" "$DB_NAME" -e "OPTIMIZE TABLE $table;"
        else
            mysql -h"$DB_HOST" -P"$DB_PORT" -u"$DB_USER" "$DB_NAME" -e "OPTIMIZE TABLE $table;"
        fi
    done
    
    print_success "Table optimization completed"
}

# Function to show index usage
show_index_usage() {
    print_status "Checking index usage..."
    
    if [ -n "$DB_PASS" ]; then
        mysql -h"$DB_HOST" -P"$DB_PORT" -u"$DB_USER" -p"$DB_PASS" "$DB_NAME" -e "
        SELECT 
            TABLE_NAME,
            INDEX_NAME,
            CARDINALITY,
            NULLABLE,
            INDEX_TYPE
        FROM information_schema.STATISTICS 
        WHERE TABLE_SCHEMA = '$DB_NAME' 
        AND TABLE_NAME IN ('ozon_funnel_data', 'ozon_demographics', 'ozon_campaigns', 'ozon_cache')
        ORDER BY TABLE_NAME, INDEX_NAME;"
    else
        mysql -h"$DB_HOST" -P"$DB_PORT" -u"$DB_USER" "$DB_NAME" -e "
        SELECT 
            TABLE_NAME,
            INDEX_NAME,
            CARDINALITY,
            NULLABLE,
            INDEX_TYPE
        FROM information_schema.STATISTICS 
        WHERE TABLE_SCHEMA = '$DB_NAME' 
        AND TABLE_NAME IN ('ozon_funnel_data', 'ozon_demographics', 'ozon_campaigns', 'ozon_cache')
        ORDER BY TABLE_NAME, INDEX_NAME;"
    fi
}

# Function to enable query cache (if supported)
enable_query_cache() {
    print_status "Checking query cache configuration..."
    
    if [ -n "$DB_PASS" ]; then
        QUERY_CACHE_SIZE=$(mysql -h"$DB_HOST" -P"$DB_PORT" -u"$DB_USER" -p"$DB_PASS" -e "SHOW VARIABLES LIKE 'query_cache_size';" | grep query_cache_size | awk '{print $2}')
    else
        QUERY_CACHE_SIZE=$(mysql -h"$DB_HOST" -P"$DB_PORT" -u"$DB_USER" -e "SHOW VARIABLES LIKE 'query_cache_size';" | grep query_cache_size | awk '{print $2}')
    fi
    
    if [ "$QUERY_CACHE_SIZE" = "0" ]; then
        print_warning "Query cache is disabled. Consider enabling it for better performance."
        print_status "To enable query cache, add the following to your MySQL configuration:"
        echo "query_cache_type = 1"
        echo "query_cache_size = 64M"
        echo "query_cache_limit = 2M"
    else
        print_success "Query cache is enabled with size: $QUERY_CACHE_SIZE bytes"
    fi
}

# Function to check MySQL version and settings
check_mysql_settings() {
    print_status "Checking MySQL version and settings..."
    
    if [ -n "$DB_PASS" ]; then
        MYSQL_VERSION=$(mysql -h"$DB_HOST" -P"$DB_PORT" -u"$DB_USER" -p"$DB_PASS" -e "SELECT VERSION();" | tail -n 1)
        INNODB_BUFFER_POOL=$(mysql -h"$DB_HOST" -P"$DB_PORT" -u"$DB_USER" -p"$DB_PASS" -e "SHOW VARIABLES LIKE 'innodb_buffer_pool_size';" | grep innodb_buffer_pool_size | awk '{print $2}')
    else
        MYSQL_VERSION=$(mysql -h"$DB_HOST" -P"$DB_PORT" -u"$DB_USER" -e "SELECT VERSION();" | tail -n 1)
        INNODB_BUFFER_POOL=$(mysql -h"$DB_HOST" -P"$DB_PORT" -u"$DB_USER" -e "SHOW VARIABLES LIKE 'innodb_buffer_pool_size';" | grep innodb_buffer_pool_size | awk '{print $2}')
    fi
    
    print_success "MySQL Version: $MYSQL_VERSION"
    print_success "InnoDB Buffer Pool Size: $INNODB_BUFFER_POOL bytes"
    
    # Convert bytes to MB for readability
    BUFFER_POOL_MB=$((INNODB_BUFFER_POOL / 1024 / 1024))
    
    if [ $BUFFER_POOL_MB -lt 128 ]; then
        print_warning "InnoDB buffer pool size is quite small ($BUFFER_POOL_MB MB). Consider increasing it for better performance."
    fi
}

# Main execution
main() {
    echo "=================================================="
    echo "  Ozon Analytics Performance Optimization"
    echo "=================================================="
    echo ""
    
    # Check prerequisites
    check_mysql
    check_database
    
    # Show current settings
    check_mysql_settings
    
    # Create backup
    backup_database
    
    # Apply base analytics tables if they don't exist
    if [ -f "migrations/add_ozon_analytics_tables.sql" ]; then
        execute_sql_file "migrations/add_ozon_analytics_tables.sql" "base Ozon analytics tables"
    else
        print_warning "Base analytics tables migration not found, skipping..."
    fi
    
    # Apply performance indexes
    if [ -f "migrations/add_ozon_performance_indexes.sql" ]; then
        execute_sql_file "migrations/add_ozon_performance_indexes.sql" "performance indexes"
    else
        print_error "Performance indexes migration not found!"
        exit 1
    fi
    
    # Analyze and optimize tables
    analyze_tables
    optimize_tables
    
    # Show index usage
    show_index_usage
    
    # Check query cache
    enable_query_cache
    
    echo ""
    echo "=================================================="
    print_success "Performance optimization completed successfully!"
    echo "=================================================="
    echo ""
    
    print_status "Next steps:"
    echo "1. Monitor query performance using EXPLAIN on slow queries"
    echo "2. Consider enabling MySQL slow query log to identify bottlenecks"
    echo "3. Review and adjust cache TTL settings based on your data update frequency"
    echo "4. Monitor memory usage and adjust buffer pool size if needed"
    echo ""
    
    print_status "Performance monitoring:"
    echo "- Use the OzonPerformanceMonitor JavaScript class to track frontend performance"
    echo "- Check cache hit rates regularly using the OzonDataCache::getStats() method"
    echo "- Monitor database performance using MySQL's performance_schema"
    echo ""
}

# Handle command line arguments
case "${1:-}" in
    --help|-h)
        echo "Usage: $0 [options]"
        echo ""
        echo "Options:"
        echo "  --help, -h          Show this help message"
        echo "  --dry-run          Show what would be executed without making changes"
        echo "  --skip-backup      Skip database backup"
        echo "  --analyze-only     Only analyze tables, don't apply changes"
        echo ""
        echo "Environment variables:"
        echo "  DB_HOST            Database host (default: localhost)"
        echo "  DB_PORT            Database port (default: 3306)"
        echo "  DB_NAME            Database name (default: manhattan_analytics)"
        echo "  DB_USER            Database user (default: root)"
        echo "  DB_PASS            Database password (default: empty)"
        echo ""
        exit 0
        ;;
    --dry-run)
        print_status "DRY RUN MODE - No changes will be made"
        echo "Would execute the following optimizations:"
        echo "1. Create database backup"
        echo "2. Apply performance indexes from migrations/add_ozon_performance_indexes.sql"
        echo "3. Analyze and optimize tables"
        echo "4. Show index usage statistics"
        exit 0
        ;;
    --skip-backup)
        backup_database() {
            print_status "Skipping database backup as requested"
        }
        main
        ;;
    --analyze-only)
        check_mysql
        check_database
        analyze_tables
        show_index_usage
        exit 0
        ;;
    "")
        main
        ;;
    *)
        print_error "Unknown option: $1"
        echo "Use --help for usage information"
        exit 1
        ;;
esac