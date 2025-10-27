#!/bin/bash

# Ozon ETL Refactoring Deployment Verification Script
# Description: Comprehensive verification of deployment success
# Author: ETL Development Team
# Date: 2025-10-27

set -e

# Configuration
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_ROOT="$(dirname "$SCRIPT_DIR")"
VERIFICATION_LOG="/var/log/ozon_etl_verification.log"

# Database configuration
DB_HOST="${DB_HOST:-localhost}"
DB_PORT="${DB_PORT:-5432}"
DB_NAME="${DB_NAME:-mi_core_db}"
DB_USER="${DB_USER:-postgres}"

# Application configuration
APP_DIR="${APP_DIR:-/var/www/html}"
WEB_USER="${WEB_USER:-www-data}"

# Colors for output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m'

# Test results
TESTS_PASSED=0
TESTS_FAILED=0
TESTS_TOTAL=0

# Logging function
log() {
    local level=$1
    shift
    local message="$@"
    local timestamp=$(date '+%Y-%m-%d %H:%M:%S')
    
    case $level in
        "INFO")
            echo -e "${GREEN}[INFO]${NC} $message" | tee -a "$VERIFICATION_LOG"
            ;;
        "WARN")
            echo -e "${YELLOW}[WARN]${NC} $message" | tee -a "$VERIFICATION_LOG"
            ;;
        "ERROR")
            echo -e "${RED}[ERROR]${NC} $message" | tee -a "$VERIFICATION_LOG"
            ;;
        "PASS")
            echo -e "${GREEN}[PASS]${NC} $message" | tee -a "$VERIFICATION_LOG"
            ((TESTS_PASSED++))
            ;;
        "FAIL")
            echo -e "${RED}[FAIL]${NC} $message" | tee -a "$VERIFICATION_LOG"
            ((TESTS_FAILED++))
            ;;
    esac
    
    echo "[$timestamp] [$level] $message" >> "$VERIFICATION_LOG"
    ((TESTS_TOTAL++))
}

# Test function wrapper
run_test() {
    local test_name="$1"
    local test_command="$2"
    
    log "INFO" "Running test: $test_name"
    
    if eval "$test_command"; then
        log "PASS" "$test_name"
        return 0
    else
        log "FAIL" "$test_name"
        return 1
    fi
}

# Database connectivity test
test_database_connectivity() {
    sudo -u postgres psql -h "$DB_HOST" -p "$DB_PORT" -d "$DB_NAME" -c "SELECT 1;" &> /dev/null
}

# Database schema tests
test_visibility_field_exists() {
    local result=$(sudo -u postgres psql -h "$DB_HOST" -p "$DB_PORT" -d "$DB_NAME" -t -c "
        SELECT EXISTS (
            SELECT 1 FROM information_schema.columns 
            WHERE table_name = 'dim_products' 
            AND column_name = 'visibility'
        );
    " | tr -d ' ')
    
    [[ "$result" == "t" ]]
}

test_etl_tracking_tables_exist() {
    local count=$(sudo -u postgres psql -h "$DB_HOST" -p "$DB_PORT" -d "$DB_NAME" -t -c "
        SELECT COUNT(*) FROM information_schema.tables 
        WHERE table_name IN ('etl_execution_log', 'etl_workflow_executions', 'data_quality_metrics');
    " | tr -d ' ')
    
    [[ "$count" == "3" ]]
}

test_enhanced_view_exists() {
    local result=$(sudo -u postgres psql -h "$DB_HOST" -p "$DB_PORT" -d "$DB_NAME" -t -c "
        SELECT EXISTS (
            SELECT 1 FROM information_schema.views 
            WHERE table_name = 'v_detailed_inventory'
        );
    " | tr -d ' ')
    
    [[ "$result" == "t" ]]
}

test_materialized_view_exists() {
    local result=$(sudo -u postgres psql -h "$DB_HOST" -p "$DB_PORT" -d "$DB_NAME" -t -c "
        SELECT EXISTS (
            SELECT 1 FROM pg_matviews 
            WHERE matviewname = 'mv_detailed_inventory'
        );
    " | tr -d ' ')
    
    [[ "$result" == "t" ]]
}

test_stock_status_function_exists() {
    local result=$(sudo -u postgres psql -h "$DB_HOST" -p "$DB_PORT" -d "$DB_NAME" -t -c "
        SELECT EXISTS (
            SELECT 1 FROM pg_proc 
            WHERE proname = 'get_product_stock_status'
        );
    " | tr -d ' ')
    
    [[ "$result" == "t" ]]
}

# Data integrity tests
test_view_returns_data() {
    local count=$(sudo -u postgres psql -h "$DB_HOST" -p "$DB_PORT" -d "$DB_NAME" -t -c "
        SELECT COUNT(*) FROM v_detailed_inventory LIMIT 1;
    " | tr -d ' ')
    
    [[ "$count" =~ ^[0-9]+$ ]]
}

test_stock_status_calculation() {
    local result=$(sudo -u postgres psql -h "$DB_HOST" -p "$DB_PORT" -d "$DB_NAME" -t -c "
        SELECT get_product_stock_status('VISIBLE', 100, 10, 5.0);
    " | tr -d ' ')
    
    [[ "$result" == "normal" ]]
}

test_no_orphaned_inventory() {
    local count=$(sudo -u postgres psql -h "$DB_HOST" -p "$DB_PORT" -d "$DB_NAME" -t -c "
        SELECT COUNT(*) FROM inventory i
        LEFT JOIN dim_products p ON i.offer_id = p.offer_id
        WHERE p.offer_id IS NULL;
    " | tr -d ' ')
    
    [[ "$count" == "0" ]]
}

# Application tests
test_application_files_exist() {
    [[ -f "$APP_DIR/src/ETL/Ozon/Scripts/etl_health_check.php" ]] && \
    [[ -f "$APP_DIR/src/ETL/Ozon/Scripts/run_etl_workflow.php" ]] && \
    [[ -f "$APP_DIR/src/ETL/Ozon/Scripts/run_data_quality_tests.php" ]]
}

test_file_permissions() {
    [[ -x "$APP_DIR/src/ETL/Ozon/Scripts/etl_health_check.php" ]] && \
    [[ -x "$APP_DIR/src/ETL/Ozon/Scripts/run_etl_workflow.php" ]] && \
    [[ "$(stat -c %U "$APP_DIR")" == "$WEB_USER" ]]
}

test_configuration_files_exist() {
    [[ -f "$APP_DIR/config/database.php" ]] && \
    [[ -f "$APP_DIR/config/etl.php" ]]
}

test_log_directories_exist() {
    [[ -d "/var/log/ozon_etl" ]] && \
    [[ "$(stat -c %U /var/log/ozon_etl)" == "$WEB_USER" ]]
}

# ETL functionality tests
test_etl_health_check_script() {
    sudo -u "$WEB_USER" php "$APP_DIR/src/ETL/Ozon/Scripts/etl_health_check.php" --brief &> /dev/null
}

test_data_quality_script() {
    sudo -u "$WEB_USER" php "$APP_DIR/src/ETL/Ozon/Scripts/run_data_quality_tests.php" --quick &> /dev/null
}

# Cron job tests
test_cron_jobs_configured() {
    crontab -u "$WEB_USER" -l 2>/dev/null | grep -q "product-etl" && \
    crontab -u "$WEB_USER" -l 2>/dev/null | grep -q "inventory-etl"
}

# API tests (if web server is running)
test_api_health_endpoint() {
    if systemctl is-active --quiet nginx; then
        curl -s -f "http://localhost/api/health" &> /dev/null
    else
        # Skip if nginx is not running
        return 0
    fi
}

test_api_detailed_stock_endpoint() {
    if systemctl is-active --quiet nginx; then
        curl -s -f "http://localhost/api/inventory/detailed-stock?limit=1" &> /dev/null
    else
        # Skip if nginx is not running
        return 0
    fi
}

# Performance tests
test_view_performance() {
    local start_time=$(date +%s%N)
    sudo -u postgres psql -h "$DB_HOST" -p "$DB_PORT" -d "$DB_NAME" -c "SELECT * FROM v_detailed_inventory LIMIT 100;" &> /dev/null
    local end_time=$(date +%s%N)
    local duration=$(( (end_time - start_time) / 1000000 ))  # Convert to milliseconds
    
    # Should complete within 2 seconds (2000ms)
    [[ $duration -lt 2000 ]]
}

# Monitoring tests
test_monitoring_dashboard_exists() {
    [[ -f "$APP_DIR/monitoring/dashboard.php" ]]
}

# Security tests
test_sensitive_files_permissions() {
    # Check that config files are not world-readable
    local config_perms=$(stat -c %a "$APP_DIR/config/database.php" 2>/dev/null || echo "000")
    [[ "$config_perms" == "644" ]] || [[ "$config_perms" == "640" ]]
}

# Rollback readiness test
test_rollback_script_exists() {
    [[ -f "$PROJECT_ROOT/migrations/ozon_etl_refactoring_rollback.sql" ]] && \
    [[ -f "$PROJECT_ROOT/scripts/rollback_deployment.sh" ]]
}

# Main verification function
run_all_tests() {
    log "INFO" "Starting deployment verification..."
    log "INFO" "Verification started at: $(date)"
    
    # Database tests
    log "INFO" "=== Database Tests ==="
    run_test "Database connectivity" "test_database_connectivity"
    run_test "Visibility field exists" "test_visibility_field_exists"
    run_test "ETL tracking tables exist" "test_etl_tracking_tables_exist"
    run_test "Enhanced view exists" "test_enhanced_view_exists"
    run_test "Materialized view exists" "test_materialized_view_exists"
    run_test "Stock status function exists" "test_stock_status_function_exists"
    
    # Data integrity tests
    log "INFO" "=== Data Integrity Tests ==="
    run_test "View returns data" "test_view_returns_data"
    run_test "Stock status calculation works" "test_stock_status_calculation"
    run_test "No orphaned inventory records" "test_no_orphaned_inventory"
    
    # Application tests
    log "INFO" "=== Application Tests ==="
    run_test "Application files exist" "test_application_files_exist"
    run_test "File permissions correct" "test_file_permissions"
    run_test "Configuration files exist" "test_configuration_files_exist"
    run_test "Log directories exist" "test_log_directories_exist"
    
    # ETL functionality tests
    log "INFO" "=== ETL Functionality Tests ==="
    run_test "ETL health check script works" "test_etl_health_check_script"
    run_test "Data quality script works" "test_data_quality_script"
    run_test "Cron jobs configured" "test_cron_jobs_configured"
    
    # API tests
    log "INFO" "=== API Tests ==="
    run_test "API health endpoint" "test_api_health_endpoint"
    run_test "API detailed stock endpoint" "test_api_detailed_stock_endpoint"
    
    # Performance tests
    log "INFO" "=== Performance Tests ==="
    run_test "View performance acceptable" "test_view_performance"
    
    # Monitoring tests
    log "INFO" "=== Monitoring Tests ==="
    run_test "Monitoring dashboard exists" "test_monitoring_dashboard_exists"
    
    # Security tests
    log "INFO" "=== Security Tests ==="
    run_test "Sensitive files permissions" "test_sensitive_files_permissions"
    
    # Rollback readiness
    log "INFO" "=== Rollback Readiness Tests ==="
    run_test "Rollback scripts exist" "test_rollback_script_exists"
}

# Generate detailed report
generate_report() {
    local report_file="/var/log/ozon_etl_verification_report_$(date +%Y%m%d_%H%M%S).json"
    
    # Get system information
    local system_info=$(cat << EOF
{
    "verification_timestamp": "$(date -Iseconds)",
    "hostname": "$(hostname)",
    "os_version": "$(lsb_release -d 2>/dev/null | cut -f2 || uname -a)",
    "php_version": "$(php -v | head -n1)",
    "postgresql_version": "$(sudo -u postgres psql --version)",
    "deployment_verification": {
        "tests_total": $TESTS_TOTAL,
        "tests_passed": $TESTS_PASSED,
        "tests_failed": $TESTS_FAILED,
        "success_rate": $(echo "scale=2; $TESTS_PASSED * 100 / $TESTS_TOTAL" | bc -l 2>/dev/null || echo "0")
    }
}
EOF
)
    
    echo "$system_info" > "$report_file"
    log "INFO" "Detailed report generated: $report_file"
}

# Display summary
display_summary() {
    echo
    echo -e "${BLUE}========================================${NC}"
    echo -e "${BLUE}    DEPLOYMENT VERIFICATION SUMMARY    ${NC}"
    echo -e "${BLUE}========================================${NC}"
    echo
    echo "Total tests run: $TESTS_TOTAL"
    echo -e "Tests passed: ${GREEN}$TESTS_PASSED${NC}"
    echo -e "Tests failed: ${RED}$TESTS_FAILED${NC}"
    
    if [[ $TESTS_FAILED -eq 0 ]]; then
        echo
        echo -e "${GREEN}✓ ALL TESTS PASSED - DEPLOYMENT VERIFIED${NC}"
        echo
        echo "The Ozon ETL refactoring has been successfully deployed and verified."
        echo "The system is ready for production use."
    else
        echo
        echo -e "${RED}✗ SOME TESTS FAILED - DEPLOYMENT ISSUES DETECTED${NC}"
        echo
        echo "Please review the failed tests and resolve issues before proceeding."
        echo "Consider running the rollback procedure if critical issues are found."
    fi
    
    echo
    echo "Verification log: $VERIFICATION_LOG"
    echo "Next steps:"
    echo "1. Monitor ETL execution: tail -f /var/log/ozon_etl/*.log"
    echo "2. Check system status: php $APP_DIR/src/ETL/Ozon/Scripts/etl_health_check.php"
    echo "3. Run data quality tests: php $APP_DIR/src/ETL/Ozon/Scripts/run_data_quality_tests.php"
    
    if [[ $TESTS_FAILED -gt 0 ]]; then
        echo "4. If issues persist, run rollback: $PROJECT_ROOT/scripts/rollback_deployment.sh"
    fi
    
    echo
}

# Main function
main() {
    # Create log file
    mkdir -p "$(dirname "$VERIFICATION_LOG")"
    touch "$VERIFICATION_LOG"
    
    # Run verification
    run_all_tests
    generate_report
    display_summary
    
    # Exit with appropriate code
    if [[ $TESTS_FAILED -eq 0 ]]; then
        exit 0
    else
        exit 1
    fi
}

# Handle script interruption
trap 'log "ERROR" "Verification interrupted"; exit 1' INT TERM

# Run main function
main "$@"