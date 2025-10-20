#!/bin/bash

# Comprehensive Test Runner for Marketplace Data Separation
# Runs all end-to-end tests, performance tests, and validation checks
# Version: 1.0.0

set -e  # Stop on error

# Colors for output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m' # No Color

# Configuration
TEST_DIR="test_results_$(date +%Y%m%d_%H%M%S)"
LOG_FILE="$TEST_DIR/test_execution.log"
SUMMARY_FILE="$TEST_DIR/test_summary.json"

# Database configuration (update with production values)
DB_HOST="${DB_HOST:-localhost}"
DB_NAME="${DB_NAME:-mi_core_db}"
DB_USER="${DB_USER:-mi_core_user}"
DB_PASS="${DB_PASS:-secure_password_123}"

# Functions for output
print_info() {
    echo -e "${BLUE}[INFO]${NC} $1" | tee -a "$LOG_FILE"
}

print_success() {
    echo -e "${GREEN}[SUCCESS]${NC} $1" | tee -a "$LOG_FILE"
}

print_warning() {
    echo -e "${YELLOW}[WARNING]${NC} $1" | tee -a "$LOG_FILE"
}

print_error() {
    echo -e "${RED}[ERROR]${NC} $1" | tee -a "$LOG_FILE"
}

print_header() {
    echo -e "\n${BLUE}$1${NC}" | tee -a "$LOG_FILE"
    echo -e "${BLUE}$(echo "$1" | sed 's/./=/g')${NC}" | tee -a "$LOG_FILE"
}

# Initialize test environment
initialize_test_environment() {
    print_info "Initializing test environment..."
    
    # Create test results directory
    mkdir -p "$TEST_DIR"
    
    # Initialize log file
    echo "Marketplace Integration Test Execution - $(date)" > "$LOG_FILE"
    echo "======================================================" >> "$LOG_FILE"
    
    # Check required files
    local required_files=(
        "test_marketplace_integration_e2e.php"
        "test_marketplace_performance.php"
        "MarginDashboardAPI.php"
        "src/classes/MarketplaceDetector.php"
        "src/classes/MarketplaceFallbackHandler.php"
        "src/classes/MarketplaceDataValidator.php"
    )
    
    for file in "${required_files[@]}"; do
        if [ ! -f "$file" ]; then
            print_error "Required test file not found: $file"
            exit 1
        fi
    done
    
    print_success "Test environment initialized"
}

# Test database connectivity
test_database_connectivity() {
    print_header "Testing Database Connectivity"
    
    # Test MySQL connection
    if command -v mysql &> /dev/null; then
        if mysql -h "$DB_HOST" -u "$DB_USER" -p"$DB_PASS" -e "SELECT 1;" "$DB_NAME" &>/dev/null; then
            print_success "Database connection successful"
            
            # Get database info
            local db_info=$(mysql -h "$DB_HOST" -u "$DB_USER" -p"$DB_PASS" -e "
                SELECT 
                    COUNT(*) as total_orders,
                    MIN(order_date) as earliest_date,
                    MAX(order_date) as latest_date
                FROM fact_orders;" "$DB_NAME" 2>/dev/null | tail -n +2)
            
            print_info "Database info: $db_info"
            
        else
            print_error "Database connection failed"
            print_info "Please check database credentials and connectivity"
            exit 1
        fi
    else
        print_warning "MySQL client not found, skipping direct database test"
    fi
}

# Run end-to-end tests
run_e2e_tests() {
    print_header "Running End-to-End Tests"
    
    local e2e_start_time=$(date +%s)
    
    if php test_marketplace_integration_e2e.php > "$TEST_DIR/e2e_test_output.log" 2>&1; then
        print_success "End-to-end tests completed successfully"
        
        # Extract key metrics from E2E test output
        local e2e_summary=$(tail -20 "$TEST_DIR/e2e_test_output.log" | grep -E "(Total Tests|Passed|Failed|Success Rate)" || echo "Summary not found")
        print_info "E2E Test Summary: $e2e_summary"
        
    else
        print_error "End-to-end tests failed"
        print_info "Check $TEST_DIR/e2e_test_output.log for details"
        
        # Show last few lines of error output
        print_info "Last 10 lines of E2E test output:"
        tail -10 "$TEST_DIR/e2e_test_output.log" | while read line; do
            print_info "  $line"
        done
    fi
    
    local e2e_end_time=$(date +%s)
    local e2e_duration=$((e2e_end_time - e2e_start_time))
    print_info "E2E tests completed in ${e2e_duration}s"
    
    # Copy E2E test artifacts
    cp e2e_test_*.log "$TEST_DIR/" 2>/dev/null || true
    cp e2e_test_report_*.json "$TEST_DIR/" 2>/dev/null || true
}

# Run performance tests
run_performance_tests() {
    print_header "Running Performance Tests"
    
    local perf_start_time=$(date +%s)
    
    if php test_marketplace_performance.php > "$TEST_DIR/performance_test_output.log" 2>&1; then
        print_success "Performance tests completed successfully"
        
        # Extract key metrics from performance test output
        local perf_summary=$(tail -30 "$TEST_DIR/performance_test_output.log" | grep -E "(Average|Success rate|Memory)" || echo "Summary not found")
        print_info "Performance Test Summary: $perf_summary"
        
    else
        print_error "Performance tests failed"
        print_info "Check $TEST_DIR/performance_test_output.log for details"
        
        # Show last few lines of error output
        print_info "Last 10 lines of performance test output:"
        tail -10 "$TEST_DIR/performance_test_output.log" | while read line; do
            print_info "  $line"
        done
    fi
    
    local perf_end_time=$(date +%s)
    local perf_duration=$((perf_end_time - perf_start_time))
    print_info "Performance tests completed in ${perf_duration}s"
    
    # Copy performance test artifacts
    cp performance_test_*.log "$TEST_DIR/" 2>/dev/null || true
    cp performance_report_*.json "$TEST_DIR/" 2>/dev/null || true
}

# Run API validation tests
run_api_validation_tests() {
    print_header "Running API Validation Tests"
    
    local validation_start_time=$(date +%s)
    
    # Test API endpoints directly
    local api_tests=(
        "margin_api.php?action=margin_summary&marketplace=ozon"
        "margin_api.php?action=margin_summary&marketplace=wildberries"
        "margin_api.php?action=marketplace_comparison"
        "margin_api.php?action=top_products&marketplace=ozon&limit=10"
        "margin_api.php?action=daily_chart&marketplace=ozon"
    )
    
    local api_success_count=0
    local api_total_count=${#api_tests[@]}
    
    for api_test in "${api_tests[@]}"; do
        print_info "Testing API endpoint: $api_test"
        
        # Create a simple PHP test script
        cat > "$TEST_DIR/api_test_temp.php" << EOF
<?php
require_once 'MarginDashboardAPI.php';

try {
    \$api = new MarginDashboardAPI('$DB_HOST', '$DB_NAME', '$DB_USER', '$DB_PASS');
    
    // Parse the test URL
    parse_str('$api_test', \$params);
    \$action = \$params['action'];
    \$marketplace = \$params['marketplace'] ?? null;
    \$limit = \$params['limit'] ?? 10;
    
    \$startDate = date('Y-m-d', strtotime('-30 days'));
    \$endDate = date('Y-m-d');
    
    switch (\$action) {
        case 'margin_summary':
            \$result = \$api->getMarginSummaryByMarketplace(\$startDate, \$endDate, \$marketplace);
            break;
        case 'marketplace_comparison':
            \$result = \$api->getMarketplaceComparison(\$startDate, \$endDate);
            break;
        case 'top_products':
            \$result = \$api->getTopProductsByMarketplace(\$marketplace, \$limit, \$startDate, \$endDate);
            break;
        case 'daily_chart':
            \$result = \$api->getDailyMarginChartByMarketplace(\$startDate, \$endDate, \$marketplace);
            break;
        default:
            throw new Exception("Unknown action: \$action");
    }
    
    if (\$result['success']) {
        echo "SUCCESS: API endpoint working correctly\n";
        echo "Has data: " . (\$result['has_data'] ? 'Yes' : 'No') . "\n";
        if (isset(\$result['marketplace'])) {
            echo "Marketplace: " . \$result['marketplace'] . "\n";
        }
        exit(0);
    } else {
        echo "FAILED: " . (\$result['user_message'] ?? 'Unknown error') . "\n";
        exit(1);
    }
    
} catch (Exception \$e) {
    echo "ERROR: " . \$e->getMessage() . "\n";
    exit(1);
}
?>
EOF
        
        if php "$TEST_DIR/api_test_temp.php" > "$TEST_DIR/api_test_${api_success_count}.log" 2>&1; then
            print_success "✅ $api_test"
            ((api_success_count++))
        else
            print_error "❌ $api_test"
            print_info "Error details: $(cat "$TEST_DIR/api_test_${api_success_count}.log")"
        fi
        
        rm -f "$TEST_DIR/api_test_temp.php"
    done
    
    local validation_end_time=$(date +%s)
    local validation_duration=$((validation_end_time - validation_start_time))
    
    print_info "API validation completed in ${validation_duration}s"
    print_info "API tests passed: $api_success_count/$api_total_count"
    
    if [ $api_success_count -eq $api_total_count ]; then
        print_success "All API validation tests passed"
    else
        print_warning "Some API validation tests failed"
    fi
}

# Run data consistency checks
run_data_consistency_checks() {
    print_header "Running Data Consistency Checks"
    
    local consistency_start_time=$(date +%s)
    
    # Create data consistency test script
    cat > "$TEST_DIR/consistency_test.php" << 'EOF'
<?php
require_once 'MarginDashboardAPI.php';

try {
    $api = new MarginDashboardAPI($argv[1], $argv[2], $argv[3], $argv[4]);
    
    $startDate = date('Y-m-d', strtotime('-30 days'));
    $endDate = date('Y-m-d');
    
    echo "Testing data consistency for period: $startDate to $endDate\n";
    
    // Get combined data
    $combined = $api->getMarginSummary($startDate, $endDate);
    
    // Get marketplace-specific data
    $ozon = $api->getMarginSummaryByMarketplace($startDate, $endDate, 'ozon');
    $wildberries = $api->getMarginSummaryByMarketplace($startDate, $endDate, 'wildberries');
    
    echo "Combined data:\n";
    echo "  Revenue: " . ($combined['total_revenue'] ?? 0) . "\n";
    echo "  Orders: " . ($combined['total_orders'] ?? 0) . "\n";
    
    echo "Ozon data:\n";
    echo "  Success: " . ($ozon['success'] ? 'Yes' : 'No') . "\n";
    echo "  Has data: " . ($ozon['has_data'] ? 'Yes' : 'No') . "\n";
    if ($ozon['has_data']) {
        echo "  Revenue: " . $ozon['total_revenue'] . "\n";
        echo "  Orders: " . $ozon['total_orders'] . "\n";
    }
    
    echo "Wildberries data:\n";
    echo "  Success: " . ($wildberries['success'] ? 'Yes' : 'No') . "\n";
    echo "  Has data: " . ($wildberries['has_data'] ? 'Yes' : 'No') . "\n";
    if ($wildberries['has_data']) {
        echo "  Revenue: " . $wildberries['total_revenue'] . "\n";
        echo "  Orders: " . $wildberries['total_orders'] . "\n";
    }
    
    // Check consistency
    $marketplaceRevenue = 0;
    $marketplaceOrders = 0;
    
    if ($ozon['has_data']) {
        $marketplaceRevenue += $ozon['total_revenue'];
        $marketplaceOrders += $ozon['total_orders'];
    }
    
    if ($wildberries['has_data']) {
        $marketplaceRevenue += $wildberries['total_revenue'];
        $marketplaceOrders += $wildberries['total_orders'];
    }
    
    echo "Consistency check:\n";
    echo "  Combined revenue: " . ($combined['total_revenue'] ?? 0) . "\n";
    echo "  Marketplace sum: $marketplaceRevenue\n";
    echo "  Combined orders: " . ($combined['total_orders'] ?? 0) . "\n";
    echo "  Marketplace sum: $marketplaceOrders\n";
    
    // Calculate tolerance (10%)
    $revenueTolerance = ($combined['total_revenue'] ?? 0) * 0.1;
    $ordersTolerance = ($combined['total_orders'] ?? 0) * 0.1;
    
    $revenueConsistent = abs(($combined['total_revenue'] ?? 0) - $marketplaceRevenue) <= $revenueTolerance;
    $ordersConsistent = abs(($combined['total_orders'] ?? 0) - $marketplaceOrders) <= $ordersTolerance;
    
    echo "Consistency results:\n";
    echo "  Revenue consistent: " . ($revenueConsistent ? 'Yes' : 'No') . "\n";
    echo "  Orders consistent: " . ($ordersConsistent ? 'Yes' : 'No') . "\n";
    
    if ($revenueConsistent && $ordersConsistent) {
        echo "SUCCESS: Data consistency check passed\n";
        exit(0);
    } else {
        echo "FAILED: Data consistency issues found\n";
        exit(1);
    }
    
} catch (Exception $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
    exit(1);
}
?>
EOF
    
    if php "$TEST_DIR/consistency_test.php" "$DB_HOST" "$DB_NAME" "$DB_USER" "$DB_PASS" > "$TEST_DIR/consistency_test_output.log" 2>&1; then
        print_success "Data consistency checks passed"
        
        # Show consistency results
        local consistency_results=$(grep -E "(Revenue consistent|Orders consistent|SUCCESS|FAILED)" "$TEST_DIR/consistency_test_output.log" || echo "Results not found")
        print_info "Consistency results: $consistency_results"
        
    else
        print_error "Data consistency checks failed"
        print_info "Check $TEST_DIR/consistency_test_output.log for details"
        
        # Show error details
        print_info "Consistency check output:"
        cat "$TEST_DIR/consistency_test_output.log" | while read line; do
            print_info "  $line"
        done
    fi
    
    local consistency_end_time=$(date +%s)
    local consistency_duration=$((consistency_end_time - consistency_start_time))
    print_info "Data consistency checks completed in ${consistency_duration}s"
    
    rm -f "$TEST_DIR/consistency_test.php"
}

# Generate comprehensive test summary
generate_test_summary() {
    print_header "Generating Test Summary"
    
    local total_end_time=$(date +%s)
    local total_start_time=$(date -d "$(head -1 "$LOG_FILE" | cut -d'-' -f2-)" +%s 2>/dev/null || echo $total_end_time)
    local total_duration=$((total_end_time - total_start_time))
    
    # Count test results
    local total_tests=0
    local passed_tests=0
    local failed_tests=0
    
    # Analyze log file for test results
    if grep -q "All tests passed" "$LOG_FILE"; then
        ((passed_tests++))
    fi
    
    if grep -q "Performance tests completed successfully" "$LOG_FILE"; then
        ((passed_tests++))
    fi
    
    if grep -q "All API validation tests passed" "$LOG_FILE"; then
        ((passed_tests++))
    fi
    
    if grep -q "Data consistency checks passed" "$LOG_FILE"; then
        ((passed_tests++))
    fi
    
    total_tests=$((passed_tests + failed_tests))
    
    # Create summary JSON
    cat > "$SUMMARY_FILE" << EOF
{
    "test_execution": {
        "timestamp": "$(date -Iseconds)",
        "duration_seconds": $total_duration,
        "test_directory": "$TEST_DIR"
    },
    "test_results": {
        "total_test_suites": 4,
        "passed_test_suites": $passed_tests,
        "failed_test_suites": $failed_tests,
        "success_rate": $(echo "scale=2; $passed_tests * 100 / 4" | bc -l 2>/dev/null || echo "0")
    },
    "test_suites": {
        "end_to_end_tests": {
            "status": "$(grep -q "End-to-end tests completed successfully" "$LOG_FILE" && echo "passed" || echo "failed")",
            "artifacts": ["e2e_test_output.log", "e2e_test_*.log", "e2e_test_report_*.json"]
        },
        "performance_tests": {
            "status": "$(grep -q "Performance tests completed successfully" "$LOG_FILE" && echo "passed" || echo "failed")",
            "artifacts": ["performance_test_output.log", "performance_test_*.log", "performance_report_*.json"]
        },
        "api_validation_tests": {
            "status": "$(grep -q "All API validation tests passed" "$LOG_FILE" && echo "passed" || echo "failed")",
            "artifacts": ["api_test_*.log"]
        },
        "data_consistency_checks": {
            "status": "$(grep -q "Data consistency checks passed" "$LOG_FILE" && echo "passed" || echo "failed")",
            "artifacts": ["consistency_test_output.log"]
        }
    },
    "environment": {
        "database_host": "$DB_HOST",
        "database_name": "$DB_NAME",
        "php_version": "$(php -v | head -1)",
        "test_date": "$(date -Iseconds)"
    }
}
EOF
    
    print_success "Test summary generated: $SUMMARY_FILE"
    
    # Display summary
    print_header "TEST EXECUTION SUMMARY"
    print_info "📊 Total Duration: ${total_duration}s"
    print_info "📋 Test Suites: 4"
    print_info "✅ Passed: $passed_tests"
    print_info "❌ Failed: $failed_tests"
    print_info "📈 Success Rate: $(echo "scale=1; $passed_tests * 100 / 4" | bc -l 2>/dev/null || echo "0")%"
    print_info "📁 Results Directory: $TEST_DIR"
    
    # Show recommendations
    if [ $passed_tests -eq 4 ]; then
        print_success "🎉 All test suites passed! Marketplace integration is ready for production."
    else
        print_warning "⚠️ Some test suites failed. Please review results before production deployment."
        print_info "💡 Check individual test logs in $TEST_DIR for detailed error information."
    fi
}

# Cleanup function
cleanup() {
    print_info "Cleaning up temporary files..."
    rm -f "$TEST_DIR"/api_test_temp.php
    rm -f "$TEST_DIR"/consistency_test.php
}

# Main execution function
main() {
    local start_time=$(date +%s)
    
    echo "🧪 Marketplace Integration - Comprehensive Test Suite"
    echo "===================================================="
    echo
    
    # Set up cleanup trap
    trap cleanup EXIT
    
    # Run test sequence
    initialize_test_environment
    test_database_connectivity
    run_e2e_tests
    run_performance_tests
    run_api_validation_tests
    run_data_consistency_checks
    generate_test_summary
    
    local end_time=$(date +%s)
    local total_duration=$((end_time - start_time))
    
    print_success "🏁 All tests completed in ${total_duration}s"
    print_info "📄 Full execution log: $LOG_FILE"
    print_info "📊 Test summary: $SUMMARY_FILE"
}

# Handle command line arguments
case "${1:-run}" in
    "run")
        main
        ;;
    "e2e")
        initialize_test_environment
        test_database_connectivity
        run_e2e_tests
        ;;
    "performance")
        initialize_test_environment
        test_database_connectivity
        run_performance_tests
        ;;
    "api")
        initialize_test_environment
        test_database_connectivity
        run_api_validation_tests
        ;;
    "consistency")
        initialize_test_environment
        test_database_connectivity
        run_data_consistency_checks
        ;;
    "help")
        echo "Usage: $0 [command]"
        echo
        echo "Commands:"
        echo "  run         - Run all test suites (default)"
        echo "  e2e         - Run end-to-end tests only"
        echo "  performance - Run performance tests only"
        echo "  api         - Run API validation tests only"
        echo "  consistency - Run data consistency checks only"
        echo "  help        - Show this help"
        echo
        echo "Environment Variables:"
        echo "  DB_HOST - Database host (default: localhost)"
        echo "  DB_NAME - Database name (default: mi_core_db)"
        echo "  DB_USER - Database user (default: mi_core_user)"
        echo "  DB_PASS - Database password (default: secure_password_123)"
        ;;
    *)
        print_error "Unknown command: $1"
        print_info "Use '$0 help' for usage information"
        exit 1
        ;;
esac