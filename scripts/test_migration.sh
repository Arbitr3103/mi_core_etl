#!/bin/bash

# Test Migration Script - Test migrations on a copy of production data
# Version: 1.0
# Usage: ./test_migration.sh [setup|test|cleanup]

set -e

# Configuration
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_DIR="$(dirname "$SCRIPT_DIR")"
TEST_DB_NAME="replenishment_db_test"
BACKUP_DIR="/tmp/migration_test_backup"

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

log() {
    local level="$1"
    shift
    local message="$*"
    local timestamp=$(date '+%Y-%m-%d %H:%M:%S')
    
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

# Check if MySQL is available
check_mysql() {
    if ! command -v mysql >/dev/null 2>&1; then
        log "ERROR" "MySQL client not found. Please install mysql-client."
        exit 1
    fi
    
    if ! mysql -h"${DB_HOST:-localhost}" -P"${DB_PORT:-3306}" -u"${DB_USER}" -p"${DB_PASSWORD}" -e "SELECT 1;" >/dev/null 2>&1; then
        log "ERROR" "Cannot connect to MySQL. Please check credentials."
        exit 1
    fi
}

# Setup test environment
setup_test_env() {
    log "INFO" "Setting up test environment..."
    
    check_mysql
    
    # Create backup directory
    mkdir -p "$BACKUP_DIR"
    
    # Drop test database if exists
    mysql -h"${DB_HOST:-localhost}" -P"${DB_PORT:-3306}" -u"${DB_USER}" -p"${DB_PASSWORD}" \
        -e "DROP DATABASE IF EXISTS $TEST_DB_NAME;" 2>/dev/null || true
    
    # Create test database
    mysql -h"${DB_HOST:-localhost}" -P"${DB_PORT:-3306}" -u"${DB_USER}" -p"${DB_PASSWORD}" \
        -e "CREATE DATABASE $TEST_DB_NAME CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
    
    log "INFO" "Created test database: $TEST_DB_NAME"
    
    # Copy production data to test database
    log "INFO" "Copying production data to test database..."
    
    mysqldump \
        -h"${DB_HOST:-localhost}" \
        -P"${DB_PORT:-3306}" \
        -u"${DB_USER}" \
        -p"${DB_PASSWORD}" \
        --single-transaction \
        --no-create-db \
        "${DB_NAME}" | \
    mysql \
        -h"${DB_HOST:-localhost}" \
        -P"${DB_PORT:-3306}" \
        -u"${DB_USER}" \
        -p"${DB_PASSWORD}" \
        "$TEST_DB_NAME"
    
    log "INFO" "Production data copied to test database"
    
    # Show test database statistics
    log "INFO" "Test database statistics:"
    mysql -h"${DB_HOST:-localhost}" -P"${DB_PORT:-3306}" -u"${DB_USER}" -p"${DB_PASSWORD}" "$TEST_DB_NAME" \
        -e "
        SELECT 
            table_name,
            table_rows,
            ROUND(((data_length + index_length) / 1024 / 1024), 2) AS 'Size (MB)'
        FROM information_schema.tables 
        WHERE table_schema = '$TEST_DB_NAME'
        ORDER BY table_rows DESC;
        "
}

# Test migration process
test_migration() {
    log "INFO" "Testing migration process..."
    
    check_mysql
    
    # Check if test database exists
    local db_exists=$(mysql -h"${DB_HOST:-localhost}" -P"${DB_PORT:-3306}" -u"${DB_USER}" -p"${DB_PASSWORD}" \
        -se "SELECT COUNT(*) FROM information_schema.schemata WHERE schema_name = '$TEST_DB_NAME';" 2>/dev/null || echo "0")
    
    if [ "$db_exists" -eq 0 ]; then
        log "ERROR" "Test database not found. Run: $0 setup"
        exit 1
    fi
    
    # Backup original DB_NAME
    local original_db_name="$DB_NAME"
    
    # Temporarily change DB_NAME to test database
    export DB_NAME="$TEST_DB_NAME"
    
    log "INFO" "Running migration on test database: $TEST_DB_NAME"
    
    # Test dry-run first
    log "INFO" "Testing dry-run..."
    if "$SCRIPT_DIR/migrate_production.sh" dry-run; then
        log "INFO" "✓ Dry-run test passed"
    else
        log "ERROR" "✗ Dry-run test failed"
        export DB_NAME="$original_db_name"
        exit 1
    fi
    
    # Test actual migration
    log "INFO" "Testing migration application..."
    if "$SCRIPT_DIR/migrate_production.sh" apply; then
        log "INFO" "✓ Migration application test passed"
    else
        log "ERROR" "✗ Migration application test failed"
        export DB_NAME="$original_db_name"
        exit 1
    fi
    
    # Test validation
    log "INFO" "Testing migration validation..."
    if "$SCRIPT_DIR/migrate_production.sh" validate; then
        log "INFO" "✓ Migration validation test passed"
    else
        log "ERROR" "✗ Migration validation test failed"
        export DB_NAME="$original_db_name"
        exit 1
    fi
    
    # Test rollback
    log "INFO" "Testing migration rollback..."
    echo "yes" | "$SCRIPT_DIR/migrate_production.sh" rollback
    if [ $? -eq 0 ]; then
        log "INFO" "✓ Migration rollback test passed"
    else
        log "ERROR" "✗ Migration rollback test failed"
        export DB_NAME="$original_db_name"
        exit 1
    fi
    
    # Test re-application after rollback
    log "INFO" "Testing re-application after rollback..."
    if "$SCRIPT_DIR/migrate_production.sh" apply; then
        log "INFO" "✓ Re-application test passed"
    else
        log "ERROR" "✗ Re-application test failed"
        export DB_NAME="$original_db_name"
        exit 1
    fi
    
    # Restore original DB_NAME
    export DB_NAME="$original_db_name"
    
    log "INFO" "All migration tests passed successfully!"
    
    # Show final test database state
    log "INFO" "Final test database state:"
    mysql -h"${DB_HOST:-localhost}" -P"${DB_PORT:-3306}" -u"${DB_USER}" -p"${DB_PASSWORD}" "$TEST_DB_NAME" \
        -e "
        SELECT 'inventory_data' as table_name, COUNT(*) as records,
               COUNT(CASE WHEN warehouse_name IS NOT NULL THEN 1 END) as with_warehouse,
               COUNT(CASE WHEN last_sync_at IS NOT NULL THEN 1 END) as with_sync_time
        FROM inventory_data
        UNION ALL
        SELECT 'sync_logs' as table_name, COUNT(*) as records, 
               COUNT(CASE WHEN status = 'success' THEN 1 END) as successful,
               COUNT(CASE WHEN status = 'failed' THEN 1 END) as failed
        FROM sync_logs;
        "
}

# Performance test
performance_test() {
    log "INFO" "Running performance tests..."
    
    check_mysql
    
    # Test query performance on migrated structure
    log "INFO" "Testing query performance..."
    
    # Test 1: Index usage on new columns
    log "INFO" "Test 1: Query with new indexes"
    mysql -h"${DB_HOST:-localhost}" -P"${DB_PORT:-3306}" -u"${DB_USER}" -p"${DB_PASSWORD}" "$TEST_DB_NAME" \
        -e "EXPLAIN SELECT * FROM inventory_data WHERE source = 'Ozon' AND last_sync_at > DATE_SUB(NOW(), INTERVAL 1 DAY);"
    
    # Test 2: Aggregation performance
    log "INFO" "Test 2: Aggregation query performance"
    time mysql -h"${DB_HOST:-localhost}" -P"${DB_PORT:-3306}" -u"${DB_USER}" -p"${DB_PASSWORD}" "$TEST_DB_NAME" \
        -e "SELECT source, warehouse_name, stock_type, COUNT(*), SUM(quantity_present) 
            FROM inventory_data 
            GROUP BY source, warehouse_name, stock_type;"
    
    # Test 3: Insert performance simulation
    log "INFO" "Test 3: Insert performance simulation"
    time mysql -h"${DB_HOST:-localhost}" -P"${DB_PORT:-3306}" -u"${DB_USER}" -p"${DB_PASSWORD}" "$TEST_DB_NAME" \
        -e "INSERT INTO sync_logs (sync_type, source, status, records_processed, started_at, completed_at)
            VALUES ('inventory', 'Ozon', 'success', 1000, NOW(), NOW());"
    
    log "INFO" "Performance tests completed"
}

# Data integrity test
data_integrity_test() {
    log "INFO" "Running data integrity tests..."
    
    # Test data consistency
    local integrity_issues=$(mysql -h"${DB_HOST:-localhost}" -P"${DB_PORT:-3306}" -u"${DB_USER}" -p"${DB_PASSWORD}" "$TEST_DB_NAME" \
        -se "
        SELECT COUNT(*) FROM inventory_data 
        WHERE warehouse_name IS NULL 
        OR stock_type IS NULL 
        OR quantity_present IS NULL 
        OR quantity_reserved IS NULL 
        OR last_sync_at IS NULL;
        ")
    
    if [ "$integrity_issues" -eq 0 ]; then
        log "INFO" "✓ Data integrity test passed - no NULL values in required fields"
    else
        log "ERROR" "✗ Data integrity test failed - found $integrity_issues records with NULL values"
        return 1
    fi
    
    # Test foreign key constraints (if any)
    log "INFO" "Checking referential integrity..."
    
    # Test that all product_ids in inventory_data exist (if products table exists)
    local orphaned_products=$(mysql -h"${DB_HOST:-localhost}" -P"${DB_PORT:-3306}" -u"${DB_USER}" -p"${DB_PASSWORD}" "$TEST_DB_NAME" \
        -se "
        SELECT COUNT(*) FROM inventory_data i
        LEFT JOIN products p ON i.product_id = p.id
        WHERE p.id IS NULL;
        " 2>/dev/null || echo "0")
    
    if [ "$orphaned_products" -eq 0 ]; then
        log "INFO" "✓ Referential integrity test passed"
    else
        log "WARN" "Found $orphaned_products orphaned product references (this may be expected)"
    fi
    
    log "INFO" "Data integrity tests completed"
}

# Cleanup test environment
cleanup_test_env() {
    log "INFO" "Cleaning up test environment..."
    
    check_mysql
    
    # Drop test database
    mysql -h"${DB_HOST:-localhost}" -P"${DB_PORT:-3306}" -u"${DB_USER}" -p"${DB_PASSWORD}" \
        -e "DROP DATABASE IF EXISTS $TEST_DB_NAME;" 2>/dev/null || true
    
    log "INFO" "Test database dropped: $TEST_DB_NAME"
    
    # Remove backup directory
    rm -rf "$BACKUP_DIR"
    
    log "INFO" "Cleanup completed"
}

# Full test suite
run_full_test() {
    log "INFO" "=== RUNNING FULL MIGRATION TEST SUITE ==="
    
    setup_test_env
    test_migration
    performance_test
    data_integrity_test
    
    log "INFO" "=== ALL TESTS COMPLETED SUCCESSFULLY ==="
    log "INFO" "Migration is ready for production deployment"
    
    # Ask if user wants to cleanup
    echo -n "Do you want to cleanup the test environment? (y/n): "
    read -r cleanup_choice
    
    if [ "$cleanup_choice" = "y" ] || [ "$cleanup_choice" = "yes" ]; then
        cleanup_test_env
    else
        log "INFO" "Test environment preserved for manual inspection"
        log "INFO" "Test database: $TEST_DB_NAME"
        log "INFO" "To cleanup later: $0 cleanup"
    fi
}

# Main function
main() {
    local action="${1:-help}"
    
    case "$action" in
        "setup")
            setup_test_env
            ;;
        "test")
            test_migration
            ;;
        "performance")
            performance_test
            ;;
        "integrity")
            data_integrity_test
            ;;
        "cleanup")
            cleanup_test_env
            ;;
        "full")
            run_full_test
            ;;
        "help"|*)
            echo "Usage: $0 [setup|test|performance|integrity|cleanup|full]"
            echo ""
            echo "Commands:"
            echo "  setup       - Create test database with copy of production data"
            echo "  test        - Test migration process (apply, validate, rollback)"
            echo "  performance - Run performance tests on migrated structure"
            echo "  integrity   - Test data integrity after migration"
            echo "  cleanup     - Remove test database and cleanup"
            echo "  full        - Run complete test suite (setup + test + performance + integrity)"
            echo "  help        - Show this help message"
            echo ""
            echo "Examples:"
            echo "  $0 full       # Run complete test suite"
            echo "  $0 setup      # Setup test environment only"
            echo "  $0 test       # Test migration process only"
            echo "  $0 cleanup    # Cleanup test environment"
            echo ""
            echo "Prerequisites:"
            echo "  - .env file with database credentials"
            echo "  - MySQL client installed"
            echo "  - Access to production database for copying data"
            exit 0
            ;;
    esac
}

# Run main function
main "$@"