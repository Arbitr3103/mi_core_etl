#!/bin/bash

# Ozon ETL Refactoring Smoke Tests
# Description: Quick smoke tests for post-deployment verification
# Author: ETL Development Team
# Date: 2025-10-27

set -e

# Configuration
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_ROOT="$(dirname "$SCRIPT_DIR")"

# Database configuration
DB_HOST="${DB_HOST:-localhost}"
DB_PORT="${DB_PORT:-5432}"
DB_NAME="${DB_NAME:-mi_core_db}"
DB_USER="${DB_USER:-postgres}"

# Application configuration
APP_DIR="${APP_DIR:-/var/www/html}"
API_BASE_URL="${API_BASE_URL:-http://localhost/api}"

# Colors for output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
NC='\033[0m'

# Test results
TESTS_PASSED=0
TESTS_FAILED=0

# Test function
run_test() {
    local test_name="$1"
    local test_command="$2"
    
    echo -n "Testing $test_name... "
    
    if eval "$test_command" &> /dev/null; then
        echo -e "${GREEN}PASS${NC}"
        ((TESTS_PASSED++))
        return 0
    else
        echo -e "${RED}FAIL${NC}"
        ((TESTS_FAILED++))
        return 1
    fi
}

echo "=== Ozon ETL Refactoring Smoke Tests ==="
echo

# Test 1: Database connectivity
run_test "Database connectivity" "
    sudo -u postgres psql -h '$DB_HOST' -p '$DB_PORT' -d '$DB_NAME' -c 'SELECT 1;'
"

# Test 2: Visibility field exists
run_test "Visibility field in dim_products" "
    sudo -u postgres psql -h '$DB_HOST' -p '$DB_PORT' -d '$DB_NAME' -t -c \"
        SELECT EXISTS (
            SELECT 1 FROM information_schema.columns 
            WHERE table_name = 'dim_products' 
            AND column_name = 'visibility'
        );
    \" | grep -q 't'
"

# Test 3: Enhanced view works
run_test "Enhanced v_detailed_inventory view" "
    sudo -u postgres psql -h '$DB_HOST' -p '$DB_PORT' -d '$DB_NAME' -c 'SELECT COUNT(*) FROM v_detailed_inventory LIMIT 1;'
"

# Test 4: Stock status function works
run_test "Stock status function" "
    sudo -u postgres psql -h '$DB_HOST' -p '$DB_PORT' -d '$DB_NAME' -t -c \"
        SELECT get_product_stock_status('VISIBLE', 100, 10, 5.0);
    \" | grep -q 'normal'
"

# Test 5: ETL tracking tables exist
run_test "ETL tracking tables" "
    count=\$(sudo -u postgres psql -h '$DB_HOST' -p '$DB_PORT' -d '$DB_NAME' -t -c \"
        SELECT COUNT(*) FROM information_schema.tables 
        WHERE table_name IN ('etl_execution_log', 'etl_workflow_executions', 'data_quality_metrics');
    \" | tr -d ' ')
    [[ \"\$count\" == \"3\" ]]
"

# Test 6: Application files exist
run_test "ETL script files" "
    [[ -f '$APP_DIR/src/ETL/Ozon/Scripts/etl_health_check.php' ]] && 
    [[ -f '$APP_DIR/src/ETL/Ozon/Scripts/run_etl_workflow.php' ]] &&
    [[ -x '$APP_DIR/src/ETL/Ozon/Scripts/etl_health_check.php' ]]
"

# Test 7: Configuration files exist
run_test "Configuration files" "
    [[ -f '$APP_DIR/config/database.php' ]] && 
    [[ -f '$APP_DIR/config/etl.php' ]]
"

# Test 8: ETL health check script works
run_test "ETL health check script" "
    sudo -u www-data php '$APP_DIR/src/ETL/Ozon/Scripts/etl_health_check.php' --brief
"

# Test 9: Log directory exists
run_test "ETL log directory" "
    [[ -d '/var/log/ozon_etl' ]] && 
    [[ -w '/var/log/ozon_etl' ]]
"

# Test 10: Cron jobs configured
run_test "ETL cron jobs" "
    crontab -u www-data -l 2>/dev/null | grep -q 'product-etl' &&
    crontab -u www-data -l 2>/dev/null | grep -q 'inventory-etl'
"

# Test 11: API health endpoint (if nginx is running)
if systemctl is-active --quiet nginx 2>/dev/null; then
    run_test "API health endpoint" "
        curl -s -f '$API_BASE_URL/health'
    "
    
    run_test "API detailed stock endpoint" "
        curl -s -f '$API_BASE_URL/inventory/detailed-stock?limit=1'
    "
else
    echo "Skipping API tests (nginx not running)"
fi

# Test 12: Materialized view exists
run_test "Materialized view" "
    sudo -u postgres psql -h '$DB_HOST' -p '$DB_PORT' -d '$DB_NAME' -t -c \"
        SELECT EXISTS (
            SELECT 1 FROM pg_matviews 
            WHERE matviewname = 'mv_detailed_inventory'
        );
    \" | grep -q 't'
"

# Test 13: View performance (should complete quickly)
run_test "View performance" "
    timeout 5s sudo -u postgres psql -h '$DB_HOST' -p '$DB_PORT' -d '$DB_NAME' -c 'SELECT * FROM v_detailed_inventory LIMIT 10;'
"

echo
echo "=== Smoke Test Results ==="
echo "Tests passed: $TESTS_PASSED"
echo "Tests failed: $TESTS_FAILED"
echo "Total tests: $((TESTS_PASSED + TESTS_FAILED))"

if [[ $TESTS_FAILED -eq 0 ]]; then
    echo -e "${GREEN}✓ All smoke tests passed!${NC}"
    echo "The deployment appears to be successful."
    exit 0
else
    echo -e "${RED}✗ Some smoke tests failed!${NC}"
    echo "Please investigate the failed tests before proceeding."
    exit 1
fi