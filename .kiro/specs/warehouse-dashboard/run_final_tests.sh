#!/bin/bash

# Warehouse Dashboard - Final Testing Script
# This script runs comprehensive tests for all requirements

set -e

echo "=========================================="
echo "Warehouse Dashboard - Final Testing Suite"
echo "=========================================="
echo ""

# Colors for output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m' # No Color

# Test counters
TOTAL_TESTS=0
PASSED_TESTS=0
FAILED_TESTS=0

# Function to run a test
run_test() {
    local test_name="$1"
    local test_command="$2"
    
    TOTAL_TESTS=$((TOTAL_TESTS + 1))
    echo -n "Testing: $test_name ... "
    
    if eval "$test_command" > /dev/null 2>&1; then
        echo -e "${GREEN}✓ PASSED${NC}"
        PASSED_TESTS=$((PASSED_TESTS + 1))
        return 0
    else
        echo -e "${RED}✗ FAILED${NC}"
        FAILED_TESTS=$((FAILED_TESTS + 1))
        return 1
    fi
}

# Function to check API endpoint
check_api() {
    local endpoint="$1"
    local expected_status="${2:-200}"
    
    response=$(curl -s -o /dev/null -w "%{http_code}" "http://localhost:8000${endpoint}" || echo "000")
    [ "$response" = "$expected_status" ]
}

# Function to check API response contains data
check_api_data() {
    local endpoint="$1"
    local field="$2"
    
    response=$(curl -s "http://localhost:8000${endpoint}" || echo "{}")
    echo "$response" | grep -q "\"$field\""
}

echo "=========================================="
echo "1. API Endpoint Tests"
echo "=========================================="
echo ""

run_test "Dashboard API endpoint accessible" \
    "check_api '/api/warehouse-dashboard.php'"

run_test "Dashboard returns JSON with success field" \
    "check_api_data '/api/warehouse-dashboard.php' 'success'"

run_test "Dashboard returns warehouses data" \
    "check_api_data '/api/warehouse-dashboard.php' 'warehouses'"

run_test "Dashboard returns summary data" \
    "check_api_data '/api/warehouse-dashboard.php' 'summary'"

run_test "Dashboard with warehouse filter works" \
    "check_api '/api/warehouse-dashboard.php?warehouse=test'"

run_test "Dashboard with cluster filter works" \
    "check_api '/api/warehouse-dashboard.php?cluster=test'"

run_test "Dashboard with liquidity filter works" \
    "check_api '/api/warehouse-dashboard.php?liquidity_status=critical'"

run_test "Dashboard with active_only filter works" \
    "check_api '/api/warehouse-dashboard.php?active_only=true'"

run_test "Export endpoint accessible" \
    "check_api '/api/warehouse-dashboard.php?export=csv'"

echo ""
echo "=========================================="
echo "2. Backend Unit Tests"
echo "=========================================="
echo ""

if [ -f "vendor/bin/phpunit" ]; then
    run_test "SalesAnalyticsService tests" \
        "vendor/bin/phpunit tests/Unit/WarehouseSalesAnalyticsServiceTest.php --no-coverage"
    
    run_test "ReplenishmentCalculator tests" \
        "vendor/bin/phpunit tests/Unit/ReplenishmentCalculatorTest.php --no-coverage"
    
    run_test "WarehouseService tests" \
        "vendor/bin/phpunit tests/Unit/WarehouseServiceTest.php --no-coverage"
else
    echo -e "${YELLOW}⚠ PHPUnit not found, skipping backend unit tests${NC}"
fi

echo ""
echo "=========================================="
echo "3. Frontend Unit Tests"
echo "=========================================="
echo ""

if [ -d "frontend" ] && [ -f "frontend/package.json" ]; then
    cd frontend
    
    if [ -f "node_modules/.bin/vitest" ]; then
        run_test "Frontend component tests" \
            "npm run test -- --run --reporter=silent"
        
        run_test "Warehouse hooks tests" \
            "npm run test -- src/hooks/__tests__/useWarehouse.test.ts --run --reporter=silent"
        
        run_test "Warehouse filters tests" \
            "npm run test -- src/components/warehouse/__tests__/WarehouseFilters.test.tsx --run --reporter=silent"
        
        run_test "Performance utils tests" \
            "npm run test -- src/utils/__tests__/performance.test.ts --run --reporter=silent"
    else
        echo -e "${YELLOW}⚠ Vitest not found, skipping frontend unit tests${NC}"
    fi
    
    cd ..
else
    echo -e "${YELLOW}⚠ Frontend directory not found, skipping frontend tests${NC}"
fi

echo ""
echo "=========================================="
echo "4. Integration Tests"
echo "=========================================="
echo ""

if [ -f "vendor/bin/phpunit" ]; then
    run_test "API integration tests" \
        "vendor/bin/phpunit tests/Integration/WarehouseDashboardAPIIntegrationTest.php --no-coverage"
else
    echo -e "${YELLOW}⚠ PHPUnit not found, skipping integration tests${NC}"
fi

if [ -d "frontend" ] && [ -f "frontend/node_modules/.bin/vitest" ]; then
    cd frontend
    
    run_test "Frontend integration tests" \
        "npm run test -- src/__tests__/integration/ --run --reporter=silent"
    
    cd ..
fi

echo ""
echo "=========================================="
echo "5. Data Validation Tests"
echo "=========================================="
echo ""

if [ -f "importers/validate_metrics.py" ]; then
    run_test "Metrics calculation validation" \
        "python3 importers/validate_metrics.py"
else
    echo -e "${YELLOW}⚠ Metrics validation script not found${NC}"
fi

if [ -f "importers/validate_import.py" ]; then
    run_test "Import data validation" \
        "python3 importers/validate_import.py"
else
    echo -e "${YELLOW}⚠ Import validation script not found${NC}"
fi

echo ""
echo "=========================================="
echo "6. Performance Tests"
echo "=========================================="
echo ""

# Test API response time
echo -n "Testing: API response time < 2s ... "
start_time=$(date +%s%N)
curl -s "http://localhost:8000/api/warehouse-dashboard.php" > /dev/null
end_time=$(date +%s%N)
duration=$(( (end_time - start_time) / 1000000 ))

if [ $duration -lt 2000 ]; then
    echo -e "${GREEN}✓ PASSED${NC} (${duration}ms)"
    PASSED_TESTS=$((PASSED_TESTS + 1))
else
    echo -e "${RED}✗ FAILED${NC} (${duration}ms)"
    FAILED_TESTS=$((FAILED_TESTS + 1))
fi
TOTAL_TESTS=$((TOTAL_TESTS + 1))

# Test with filters
echo -n "Testing: Filtered API response time < 2s ... "
start_time=$(date +%s%N)
curl -s "http://localhost:8000/api/warehouse-dashboard.php?warehouse=test&active_only=true" > /dev/null
end_time=$(date +%s%N)
duration=$(( (end_time - start_time) / 1000000 ))

if [ $duration -lt 2000 ]; then
    echo -e "${GREEN}✓ PASSED${NC} (${duration}ms)"
    PASSED_TESTS=$((PASSED_TESTS + 1))
else
    echo -e "${RED}✗ FAILED${NC} (${duration}ms)"
    FAILED_TESTS=$((FAILED_TESTS + 1))
fi
TOTAL_TESTS=$((TOTAL_TESTS + 1))

echo ""
echo "=========================================="
echo "7. Database Tests"
echo "=========================================="
echo ""

# Check if tables exist
run_test "warehouse_sales_metrics table exists" \
    "psql -U postgres -d basebuy -c '\dt warehouse_sales_metrics' | grep -q 'warehouse_sales_metrics'"

run_test "inventory table has required columns" \
    "psql -U postgres -d basebuy -c '\d inventory' | grep -q 'cluster'"

# Check if data exists
run_test "warehouse_sales_metrics has data" \
    "psql -U postgres -d basebuy -t -c 'SELECT COUNT(*) FROM warehouse_sales_metrics' | grep -v '^0$'"

run_test "inventory has warehouse data" \
    "psql -U postgres -d basebuy -t -c 'SELECT COUNT(*) FROM inventory WHERE warehouse_name IS NOT NULL' | grep -v '^0$'"

echo ""
echo "=========================================="
echo "8. File Structure Tests"
echo "=========================================="
echo ""

run_test "Backend API file exists" \
    "[ -f 'api/warehouse-dashboard.php' ]"

run_test "WarehouseService class exists" \
    "[ -f 'api/classes/WarehouseService.php' ]"

run_test "Frontend page exists" \
    "[ -f 'frontend/src/pages/WarehouseDashboardPage.tsx' ]"

run_test "Warehouse components exist" \
    "[ -d 'frontend/src/components/warehouse' ]"

run_test "Metrics refresh script exists" \
    "[ -f 'scripts/refresh_warehouse_metrics.php' ]"

run_test "Documentation exists" \
    "[ -f 'docs/WAREHOUSE_DASHBOARD_USER_GUIDE.md' ]"

run_test "API documentation exists" \
    "[ -f 'docs/WAREHOUSE_DASHBOARD_API.md' ]"

echo ""
echo "=========================================="
echo "Test Results Summary"
echo "=========================================="
echo ""
echo -e "Total Tests:  ${BLUE}$TOTAL_TESTS${NC}"
echo -e "Passed:       ${GREEN}$PASSED_TESTS${NC}"
echo -e "Failed:       ${RED}$FAILED_TESTS${NC}"
echo ""

# Calculate percentage
if [ $TOTAL_TESTS -gt 0 ]; then
    PERCENTAGE=$((PASSED_TESTS * 100 / TOTAL_TESTS))
    echo -e "Success Rate: ${BLUE}${PERCENTAGE}%${NC}"
    echo ""
fi

# Overall status
if [ $FAILED_TESTS -eq 0 ]; then
    echo -e "${GREEN}=========================================="
    echo -e "✓ ALL TESTS PASSED"
    echo -e "==========================================${NC}"
    exit 0
else
    echo -e "${RED}=========================================="
    echo -e "✗ SOME TESTS FAILED"
    echo -e "==========================================${NC}"
    echo ""
    echo "Please review the failed tests above and fix any issues."
    exit 1
fi
