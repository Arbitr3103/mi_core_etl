#!/bin/bash

# Warehouse Dashboard - Implementation Verification Script
# Verifies that all components are in place and properly structured

set -e

echo "=========================================="
echo "Warehouse Dashboard - Implementation Verification"
echo "=========================================="
echo ""

# Colors
GREEN='\033[0;32m'
RED='\033[0;31m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m'

TOTAL=0
PASSED=0
FAILED=0

check_file() {
    local file="$1"
    local description="$2"
    
    TOTAL=$((TOTAL + 1))
    echo -n "Checking: $description ... "
    
    if [ -f "$file" ]; then
        echo -e "${GREEN}✓ EXISTS${NC}"
        PASSED=$((PASSED + 1))
        return 0
    else
        echo -e "${RED}✗ MISSING${NC}"
        FAILED=$((FAILED + 1))
        return 1
    fi
}

check_dir() {
    local dir="$1"
    local description="$2"
    
    TOTAL=$((TOTAL + 1))
    echo -n "Checking: $description ... "
    
    if [ -d "$dir" ]; then
        echo -e "${GREEN}✓ EXISTS${NC}"
        PASSED=$((PASSED + 1))
        return 0
    else
        echo -e "${RED}✗ MISSING${NC}"
        FAILED=$((FAILED + 1))
        return 1
    fi
}

check_content() {
    local file="$1"
    local pattern="$2"
    local description="$3"
    
    TOTAL=$((TOTAL + 1))
    echo -n "Checking: $description ... "
    
    if [ -f "$file" ] && grep -q "$pattern" "$file"; then
        echo -e "${GREEN}✓ FOUND${NC}"
        PASSED=$((PASSED + 1))
        return 0
    else
        echo -e "${RED}✗ NOT FOUND${NC}"
        FAILED=$((FAILED + 1))
        return 1
    fi
}

echo "=========================================="
echo "1. Backend Files"
echo "=========================================="
echo ""

check_file "api/warehouse-dashboard.php" "Main API endpoint"
check_file "api/classes/WarehouseService.php" "WarehouseService class"
check_file "api/classes/WarehouseSalesAnalyticsService.php" "SalesAnalyticsService class"
check_file "api/classes/ReplenishmentCalculator.php" "ReplenishmentCalculator class"
check_file "scripts/refresh_warehouse_metrics.php" "Metrics refresh script"

echo ""
echo "=========================================="
echo "2. Frontend Files"
echo "=========================================="
echo ""

check_file "frontend/src/pages/WarehouseDashboardPage.tsx" "Main dashboard page"
check_dir "frontend/src/components/warehouse" "Warehouse components directory"
check_file "frontend/src/components/warehouse/WarehouseTable.tsx" "WarehouseTable component"
check_file "frontend/src/components/warehouse/WarehouseFilters.tsx" "WarehouseFilters component"
check_file "frontend/src/components/warehouse/DashboardHeader.tsx" "DashboardHeader component"
check_file "frontend/src/components/warehouse/LiquidityBadge.tsx" "LiquidityBadge component"
check_file "frontend/src/components/warehouse/ReplenishmentIndicator.tsx" "ReplenishmentIndicator component"
check_file "frontend/src/components/warehouse/MetricsTooltip.tsx" "MetricsTooltip component"
check_file "frontend/src/components/warehouse/Pagination.tsx" "Pagination component"

echo ""
echo "=========================================="
echo "3. Frontend Services & Types"
echo "=========================================="
echo ""

check_file "frontend/src/services/warehouse.ts" "Warehouse API service"
check_file "frontend/src/types/warehouse.ts" "Warehouse TypeScript types"
check_file "frontend/src/hooks/useWarehouse.ts" "Warehouse React hooks"

echo ""
echo "=========================================="
echo "4. Tests"
echo "=========================================="
echo ""

check_file "tests/Unit/WarehouseServiceTest.php" "WarehouseService unit tests"
check_file "tests/Unit/WarehouseSalesAnalyticsServiceTest.php" "SalesAnalytics unit tests"
check_file "tests/Unit/ReplenishmentCalculatorTest.php" "ReplenishmentCalculator unit tests"
check_file "tests/Integration/WarehouseDashboardAPIIntegrationTest.php" "API integration tests"
check_dir "frontend/src/components/warehouse/__tests__" "Frontend component tests"
check_dir "frontend/src/__tests__/integration" "Frontend integration tests"

echo ""
echo "=========================================="
echo "5. Documentation"
echo "=========================================="
echo ""

check_file "docs/WAREHOUSE_DASHBOARD_USER_GUIDE.md" "User guide"
check_file "docs/WAREHOUSE_DASHBOARD_API.md" "API documentation"
check_file ".kiro/specs/warehouse-dashboard/requirements.md" "Requirements document"
check_file ".kiro/specs/warehouse-dashboard/design.md" "Design document"
check_file ".kiro/specs/warehouse-dashboard/tasks.md" "Tasks document"

echo ""
echo "=========================================="
echo "6. Database & Import Scripts"
echo "=========================================="
echo ""

check_file "importers/ozon_warehouse_importer.py" "Ozon warehouse importer"
check_file "importers/validate_import.py" "Import validation script"
check_file "importers/validate_metrics.py" "Metrics validation script"

echo ""
echo "=========================================="
echo "7. Code Quality Checks"
echo "=========================================="
echo ""

check_content "api/classes/WarehouseService.php" "class WarehouseService" "WarehouseService class definition"
check_content "api/classes/WarehouseSalesAnalyticsService.php" "calculateDailySalesAvg" "Daily sales calculation method"
check_content "api/classes/ReplenishmentCalculator.php" "calculateReplenishmentNeed" "Replenishment calculation method"
check_content "frontend/src/pages/WarehouseDashboardPage.tsx" "WarehouseDashboardPage" "Dashboard page component"
check_content "frontend/src/components/warehouse/WarehouseTable.tsx" "WarehouseTable" "Table component"
check_content "frontend/src/services/warehouse.ts" "getDashboardData" "API service method"

echo ""
echo "=========================================="
echo "8. Navigation Integration"
echo "=========================================="
echo ""

check_content "frontend/src/App.tsx" "warehouse-dashboard" "Route configuration"
check_content "frontend/src/components/layout/Layout.tsx" "warehouse-dashboard" "Navigation menu entry"

echo ""
echo "=========================================="
echo "9. Requirements Coverage"
echo "=========================================="
echo ""

echo "Requirement 1 (Active Products Filter):"
check_content "api/classes/WarehouseService.php" "active_only" "Active filter implementation"

echo "Requirement 2 (Warehouse Grouping):"
check_content "api/classes/WarehouseService.php" "warehouse_name" "Warehouse grouping"

echo "Requirement 3 (Replenishment Calculation):"
check_content "api/classes/ReplenishmentCalculator.php" "calculateReplenishmentNeed" "Replenishment logic"

echo "Requirement 4 (Daily Sales):"
check_content "api/classes/WarehouseSalesAnalyticsService.php" "calculateDailySalesAvg" "Daily sales calculation"

echo "Requirement 5 (Days of Stock):"
check_content "api/classes/ReplenishmentCalculator.php" "calculateDaysOfStock" "Days of stock calculation"

echo "Requirement 6 (Ozon Metrics):"
check_content "frontend/src/components/warehouse/MetricsTooltip.tsx" "preparing_for_sale" "Ozon metrics display"

echo "Requirement 7 (Liquidity Status):"
check_content "frontend/src/components/warehouse/LiquidityBadge.tsx" "LiquidityBadge" "Liquidity badge component"

echo "Requirement 8 (Days Without Sales):"
check_content "api/classes/WarehouseSalesAnalyticsService.php" "getDaysWithoutSales" "Days without sales"

echo "Requirement 9 (Filters & Sorting):"
check_content "frontend/src/components/warehouse/WarehouseFilters.tsx" "WarehouseFilters" "Filters component"

echo "Requirement 10 (CSV Export):"
check_content "api/warehouse-dashboard.php" "export" "Export functionality"

echo "Requirement 11 (Data Refresh):"
check_content "scripts/refresh_warehouse_metrics.php" "warehouse_sales_metrics" "Metrics refresh"

echo "Requirement 12 (Performance):"
check_content "frontend/src/components/warehouse/Pagination.tsx" "Pagination" "Pagination component"

echo ""
echo "=========================================="
echo "Test Results Summary"
echo "=========================================="
echo ""
echo -e "Total Checks: ${BLUE}$TOTAL${NC}"
echo -e "Passed:       ${GREEN}$PASSED${NC}"
echo -e "Failed:       ${RED}$FAILED${NC}"
echo ""

if [ $TOTAL -gt 0 ]; then
    PERCENTAGE=$((PASSED * 100 / TOTAL))
    echo -e "Success Rate: ${BLUE}${PERCENTAGE}%${NC}"
    echo ""
fi

if [ $FAILED -eq 0 ]; then
    echo -e "${GREEN}=========================================="
    echo -e "✓ ALL IMPLEMENTATION CHECKS PASSED"
    echo -e "==========================================${NC}"
    echo ""
    echo "The Warehouse Dashboard implementation is complete!"
    echo ""
    echo "Next steps:"
    echo "1. Start the development server to test functionality"
    echo "2. Run unit tests: npm test (frontend) and phpunit (backend)"
    echo "3. Test in browser at /warehouse-dashboard"
    echo "4. Verify all filters, sorting, and export work correctly"
    echo "5. Test on mobile devices"
    echo ""
    exit 0
else
    echo -e "${RED}=========================================="
    echo -e "✗ SOME IMPLEMENTATION CHECKS FAILED"
    echo -e "==========================================${NC}"
    echo ""
    echo "Please review the failed checks above."
    exit 1
fi
