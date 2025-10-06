#!/bin/bash

# Deployment Verification Script
# Version: 1.0
# Usage: ./verify_deployment.sh [quick|full|continuous]

set -e

# Configuration
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_DIR="$(dirname "$SCRIPT_DIR")"
VERIFICATION_LOG="/var/log/inventory_sync/verification.log"

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

# Counters
TESTS_PASSED=0
TESTS_FAILED=0
TESTS_WARNING=0

# Logging function
log() {
    local level="$1"
    shift
    local message="$*"
    local timestamp=$(date '+%Y-%m-%d %H:%M:%S')
    
    # Log to file
    echo "${timestamp} [${level}] ${message}" >> "$VERIFICATION_LOG"
    
    # Log to console with colors
    case "$level" in
        "ERROR")
            echo -e "${RED}[✗ FAIL]${NC} $message"
            ((TESTS_FAILED++))
            ;;
        "WARN")
            echo -e "${YELLOW}[⚠ WARN]${NC} $message"
            ((TESTS_WARNING++))
            ;;
        "INFO")
            echo -e "${GREEN}[✓ PASS]${NC} $message"
            ((TESTS_PASSED++))
            ;;
        "DEBUG")
            echo -e "${BLUE}[ℹ INFO]${NC} $message"
            ;;
    esac
}

# Test function wrapper
run_test() {
    local test_name="$1"
    local test_function="$2"
    
    echo -e "\n${BLUE}Testing: $test_name${NC}"
    
    if $test_function; then
        log "INFO" "$test_name"
    else
        log "ERROR" "$test_name"
        return 1
    fi
}

# Database connectivity test
test_database_connection() {
    mysql -h"${DB_HOST:-localhost}" -P"${DB_PORT:-3306}" -u"${DB_USER}" -p"${DB_PASSWORD}" -e "SELECT 1;" "${DB_NAME}" >/dev/null 2>&1
}

# Migration status test
test_migration_status() {
    local status=$(mysql -h"${DB_HOST:-localhost}" -P"${DB_PORT:-3306}" -u"${DB_USER}" -p"${DB_PASSWORD}" "${DB_NAME}" -se "
        SELECT status FROM migration_log 
        WHERE migration_name = 'inventory_sync_production_migration_v1.0' 
        ORDER BY created_at DESC LIMIT 1
    " 2>/dev/null)
    
    [ "$status" = "completed" ]
}

# Table structure test
test_table_structure() {
    local required_columns=$(mysql -h"${DB_HOST:-localhost}" -P"${DB_PORT:-3306}" -u"${DB_USER}" -p"${DB_PASSWORD}" "${DB_NAME}" -se "
        SELECT COUNT(*) FROM information_schema.columns 
        WHERE table_schema = DATABASE() 
        AND table_name = 'inventory_data' 
        AND column_name IN ('warehouse_name', 'stock_type', 'quantity_present', 'quantity_reserved', 'last_sync_at')
    " 2>/dev/null)
    
    [ "$required_columns" -eq 5 ]
}

# Sync logs table test
test_sync_logs_table() {
    mysql -h"${DB_HOST:-localhost}" -P"${DB_PORT:-3306}" -u"${DB_USER}" -p"${DB_PASSWORD}" "${DB_NAME}" -e "SELECT 1 FROM sync_logs LIMIT 1;" >/dev/null 2>&1
}

# Data integrity test
test_data_integrity() {
    local null_count=$(mysql -h"${DB_HOST:-localhost}" -P"${DB_PORT:-3306}" -u"${DB_USER}" -p"${DB_PASSWORD}" "${DB_NAME}" -se "
        SELECT COUNT(*) FROM inventory_data 
        WHERE warehouse_name IS NULL OR stock_type IS NULL
    " 2>/dev/null)
    
    [ "$null_count" -eq 0 ]
}

# Python modules test
test_python_modules() {
    cd "$PROJECT_DIR"
    python3 -c "
import inventory_sync_service
import sync_monitor
import sync_logger
from replenishment_db_connector import connect_to_replenishment_db
print('All modules imported successfully')
" >/dev/null 2>&1
}

# API credentials test
test_api_credentials() {
    [ -n "$OZON_CLIENT_ID" ] && [ -n "$OZON_API_KEY" ] && [ -n "$WB_API_KEY" ]
}

# Ozon API connectivity test
test_ozon_api() {
    python3 -c "
import requests
import os
headers = {
    'Client-Id': os.getenv('OZON_CLIENT_ID'),
    'Api-Key': os.getenv('OZON_API_KEY')
}
response = requests.get('https://api-seller.ozon.ru/v1/report/info', headers=headers, timeout=10)
exit(0 if response.status_code in [200, 401] else 1)
" >/dev/null 2>&1
}

# Wildberries API connectivity test
test_wb_api() {
    python3 -c "
import requests
import os
headers = {'Authorization': os.getenv('WB_API_KEY')}
response = requests.get('https://suppliers-api.wildberries.ru/api/v1/supplier/incomes', headers=headers, timeout=10)
exit(0 if response.status_code in [200, 401] else 1)
" >/dev/null 2>&1
}

# Cron jobs test
test_cron_jobs() {
    crontab -l 2>/dev/null | grep -q "inventory_sync_service.py"
}

# Cron service test
test_cron_service() {
    systemctl is-active --quiet cron
}

# Log files test
test_log_files() {
    [ -d "$(dirname "$VERIFICATION_LOG")" ] && [ -w "$(dirname "$VERIFICATION_LOG")" ]
}

# Permissions test
test_file_permissions() {
    [ -x "$PROJECT_DIR/inventory_sync_service.py" ] && 
    [ -x "$SCRIPT_DIR/deploy_production.sh" ] && 
    [ -r "$PROJECT_DIR/.env" ]
}

# Disk space test
test_disk_space() {
    local free_space=$(df / | awk 'NR==2 {print $4}')
    [ "$free_space" -gt 524288 ]  # 512MB in KB
}

# Manual sync test
test_manual_sync() {
    cd "$PROJECT_DIR"
    timeout 300 python3 inventory_sync_service.py --test-mode --source=ozon >/dev/null 2>&1
}

# Recent sync data test
test_recent_sync_data() {
    local recent_count=$(mysql -h"${DB_HOST:-localhost}" -P"${DB_PORT:-3306}" -u"${DB_USER}" -p"${DB_PASSWORD}" "${DB_NAME}" -se "
        SELECT COUNT(*) FROM inventory_data 
        WHERE last_sync_at > DATE_SUB(NOW(), INTERVAL 24 HOUR)
    " 2>/dev/null)
    
    [ "$recent_count" -gt 0 ]
}

# Monitoring script test
test_monitoring_script() {
    [ -x "$SCRIPT_DIR/monitor_deployment.sh" ] && 
    timeout 30 "$SCRIPT_DIR/monitor_deployment.sh" >/dev/null 2>&1
}

# Performance test
test_performance() {
    cd "$PROJECT_DIR"
    
    # Test database query performance
    local query_time=$(mysql -h"${DB_HOST:-localhost}" -P"${DB_PORT:-3306}" -u"${DB_USER}" -p"${DB_PASSWORD}" "${DB_NAME}" -e "
        SELECT COUNT(*) FROM inventory_data WHERE source = 'Ozon' AND last_sync_at > DATE_SUB(NOW(), INTERVAL 1 DAY);
    " 2>/dev/null | tail -1)
    
    # Simple performance check - query should complete
    [ -n "$query_time" ]
}

# Email configuration test (if configured)
test_email_config() {
    if [ -n "$SMTP_HOST" ] && [ -n "$SMTP_USER" ]; then
        # Test SMTP connection
        timeout 10 python3 -c "
import smtplib
import os
try:
    server = smtplib.SMTP(os.getenv('SMTP_HOST'), int(os.getenv('SMTP_PORT', 587)))
    server.quit()
    print('SMTP connection successful')
except:
    exit(1)
" >/dev/null 2>&1
    else
        # Email not configured, skip test
        return 0
    fi
}

# Quick verification (essential tests only)
quick_verification() {
    echo -e "${BLUE}=== QUICK VERIFICATION ===${NC}"
    
    run_test "Database Connection" test_database_connection
    run_test "Migration Status" test_migration_status
    run_test "Table Structure" test_table_structure
    run_test "Python Modules" test_python_modules
    run_test "API Credentials" test_api_credentials
    run_test "Cron Jobs" test_cron_jobs
    run_test "File Permissions" test_file_permissions
}

# Full verification (all tests)
full_verification() {
    echo -e "${BLUE}=== FULL VERIFICATION ===${NC}"
    
    # Core functionality tests
    run_test "Database Connection" test_database_connection
    run_test "Migration Status" test_migration_status
    run_test "Table Structure" test_table_structure
    run_test "Sync Logs Table" test_sync_logs_table
    run_test "Data Integrity" test_data_integrity
    
    # Application tests
    run_test "Python Modules" test_python_modules
    run_test "API Credentials" test_api_credentials
    run_test "Ozon API Connectivity" test_ozon_api
    run_test "Wildberries API Connectivity" test_wb_api
    
    # System tests
    run_test "Cron Jobs Configuration" test_cron_jobs
    run_test "Cron Service Status" test_cron_service
    run_test "Log Files Access" test_log_files
    run_test "File Permissions" test_file_permissions
    run_test "Disk Space" test_disk_space
    
    # Functional tests
    run_test "Manual Sync Execution" test_manual_sync
    run_test "Recent Sync Data" test_recent_sync_data
    run_test "Monitoring Script" test_monitoring_script
    run_test "Performance Check" test_performance
    
    # Optional tests
    if run_test "Email Configuration" test_email_config; then
        log "DEBUG" "Email notifications configured and tested"
    else
        log "WARN" "Email configuration test failed (may not be configured)"
    fi
}

# Continuous monitoring
continuous_monitoring() {
    echo -e "${BLUE}=== CONTINUOUS MONITORING ===${NC}"
    echo "Starting continuous monitoring... Press Ctrl+C to stop"
    
    local iteration=1
    
    while true; do
        echo -e "\n${BLUE}--- Monitoring Iteration $iteration ($(date)) ---${NC}"
        
        # Reset counters for this iteration
        TESTS_PASSED=0
        TESTS_FAILED=0
        TESTS_WARNING=0
        
        # Run essential tests
        run_test "Database Connection" test_database_connection
        run_test "Recent Sync Data" test_recent_sync_data
        run_test "Cron Service Status" test_cron_service
        run_test "Disk Space" test_disk_space
        
        # Show iteration summary
        echo -e "\nIteration $iteration Summary: ${GREEN}$TESTS_PASSED passed${NC}, ${RED}$TESTS_FAILED failed${NC}, ${YELLOW}$TESTS_WARNING warnings${NC}"
        
        # Wait 5 minutes before next iteration
        sleep 300
        ((iteration++))
    done
}

# Show system information
show_system_info() {
    echo -e "\n${BLUE}=== SYSTEM INFORMATION ===${NC}"
    
    # Database info
    echo -e "\n${BLUE}Database Information:${NC}"
    mysql -h"${DB_HOST:-localhost}" -P"${DB_PORT:-3306}" -u"${DB_USER}" -p"${DB_PASSWORD}" "${DB_NAME}" -e "
        SELECT 
            'inventory_data' as table_name,
            COUNT(*) as total_records,
            COUNT(CASE WHEN last_sync_at > DATE_SUB(NOW(), INTERVAL 24 HOUR) THEN 1 END) as recent_records,
            MAX(last_sync_at) as last_sync
        FROM inventory_data
        UNION ALL
        SELECT 
            'sync_logs' as table_name,
            COUNT(*) as total_records,
            COUNT(CASE WHEN started_at > DATE_SUB(NOW(), INTERVAL 24 HOUR) THEN 1 END) as recent_records,
            MAX(started_at) as last_sync
        FROM sync_logs;
    " 2>/dev/null || echo "Database query failed"
    
    # System resources
    echo -e "\n${BLUE}System Resources:${NC}"
    echo "Disk Usage: $(df -h / | awk 'NR==2 {print $5}')"
    echo "Memory Usage: $(free -h | awk 'NR==2{printf "%.1f%%", $3/$2*100}')"
    echo "Load Average: $(uptime | awk -F'load average:' '{print $2}')"
    
    # Cron jobs
    echo -e "\n${BLUE}Cron Jobs:${NC}"
    crontab -l 2>/dev/null | grep inventory_sync || echo "No inventory sync cron jobs found"
    
    # Recent logs
    echo -e "\n${BLUE}Recent Log Entries:${NC}"
    if [ -f "$VERIFICATION_LOG" ]; then
        tail -5 "$VERIFICATION_LOG"
    else
        echo "No verification log found"
    fi
}

# Generate verification report
generate_report() {
    local report_file="/tmp/inventory_sync_verification_report_$(date +%Y%m%d_%H%M%S).txt"
    
    {
        echo "Inventory Sync System Verification Report"
        echo "Generated: $(date)"
        echo "========================================"
        echo ""
        
        echo "Test Results Summary:"
        echo "- Tests Passed: $TESTS_PASSED"
        echo "- Tests Failed: $TESTS_FAILED"
        echo "- Warnings: $TESTS_WARNING"
        echo ""
        
        echo "System Information:"
        show_system_info
        
        echo ""
        echo "Recent Verification Log:"
        if [ -f "$VERIFICATION_LOG" ]; then
            tail -20 "$VERIFICATION_LOG"
        fi
        
    } > "$report_file"
    
    echo -e "\n${GREEN}Verification report generated: $report_file${NC}"
}

# Main function
main() {
    local mode="${1:-quick}"
    
    # Create log directory
    mkdir -p "$(dirname "$VERIFICATION_LOG")"
    
    echo -e "${BLUE}Inventory Sync System Verification${NC}"
    echo -e "${BLUE}Mode: $mode${NC}"
    echo -e "${BLUE}Started: $(date)${NC}"
    
    case "$mode" in
        "quick")
            quick_verification
            ;;
        "full")
            full_verification
            ;;
        "continuous")
            continuous_monitoring
            ;;
        "info")
            show_system_info
            exit 0
            ;;
        "report")
            full_verification
            generate_report
            ;;
        *)
            echo "Usage: $0 [quick|full|continuous|info|report]"
            echo ""
            echo "Modes:"
            echo "  quick      - Run essential tests only (default)"
            echo "  full       - Run comprehensive test suite"
            echo "  continuous - Continuous monitoring (Ctrl+C to stop)"
            echo "  info       - Show system information only"
            echo "  report     - Generate full verification report"
            echo ""
            echo "Examples:"
            echo "  $0 quick      # Quick verification"
            echo "  $0 full       # Full test suite"
            echo "  $0 continuous # Continuous monitoring"
            echo "  $0 report     # Generate report"
            exit 0
            ;;
    esac
    
    # Show summary (except for continuous mode)
    if [ "$mode" != "continuous" ]; then
        echo -e "\n${BLUE}=== VERIFICATION SUMMARY ===${NC}"
        echo -e "Tests Passed: ${GREEN}$TESTS_PASSED${NC}"
        echo -e "Tests Failed: ${RED}$TESTS_FAILED${NC}"
        echo -e "Warnings: ${YELLOW}$TESTS_WARNING${NC}"
        
        if [ $TESTS_FAILED -eq 0 ]; then
            echo -e "\n${GREEN}✓ Overall Status: VERIFICATION PASSED${NC}"
            exit 0
        else
            echo -e "\n${RED}✗ Overall Status: VERIFICATION FAILED${NC}"
            echo -e "${YELLOW}Check the failed tests above and resolve issues${NC}"
            exit 1
        fi
    fi
}

# Trap Ctrl+C for continuous monitoring
trap 'echo -e "\n${YELLOW}Monitoring stopped by user${NC}"; exit 0' INT

# Run main function
main "$@"