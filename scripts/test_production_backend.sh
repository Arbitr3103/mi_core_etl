#!/bin/bash

# Test Production Backend API
# This script tests all warehouse dashboard API endpoints in production

set -e

# Configuration
BASE_URL="https://www.market-mi.ru"
API_BASE="$BASE_URL/api"
TEST_RESULTS_FILE="backend_test_results_$(date +%Y%m%d_%H%M%S).json"

echo "=== Testing Production Backend API ==="
echo "Base URL: $BASE_URL"
echo "API Base: $API_BASE"
echo

# Function to test API endpoint
test_endpoint() {
    local endpoint="$1"
    local description="$2"
    local expected_status="${3:-200}"
    
    echo "Testing: $description"
    echo "URL: $endpoint"
    
    # Make request and capture response
    local response=$(curl -s -w "\n%{http_code}\n%{time_total}" "$endpoint" 2>/dev/null || echo -e "\nERROR\n0")
    
    # Parse response
    local body=$(echo "$response" | head -n -2)
    local status_code=$(echo "$response" | tail -n 2 | head -n 1)
    local response_time=$(echo "$response" | tail -n 1)
    
    # Check status code
    if [ "$status_code" = "$expected_status" ]; then
        echo "✓ Status: $status_code (Expected: $expected_status)"
    else
        echo "✗ Status: $status_code (Expected: $expected_status)"
        return 1
    fi
    
    # Check response time
    if (( $(echo "$response_time < 3.0" | bc -l) )); then
        echo "✓ Response time: ${response_time}s"
    else
        echo "⚠ Response time: ${response_time}s (>3s)"
    fi
    
    # Check if response is valid JSON
    if echo "$body" | jq . >/dev/null 2>&1; then
        echo "✓ Valid JSON response"
        
        # Show response preview
        echo "Response preview:"
        echo "$body" | jq . | head -n 10
        if [ $(echo "$body" | jq . | wc -l) -gt 10 ]; then
            echo "..."
        fi
    else
        echo "✗ Invalid JSON response"
        echo "Response body:"
        echo "$body" | head -n 5
        return 1
    fi
    
    echo
    return 0
}

# Function to test all endpoints
test_all_endpoints() {
    local success_count=0
    local total_count=0
    
    echo "=== API Endpoint Tests ==="
    echo
    
    # Test 1: Warehouses endpoint
    total_count=$((total_count + 1))
    if test_endpoint "$API_BASE/warehouse-dashboard.php?action=warehouses" "Get warehouses list"; then
        success_count=$((success_count + 1))
    fi
    
    # Test 2: Clusters endpoint
    total_count=$((total_count + 1))
    if test_endpoint "$API_BASE/warehouse-dashboard.php?action=clusters" "Get clusters list"; then
        success_count=$((success_count + 1))
    fi
    
    # Test 3: Dashboard endpoint (default)
    total_count=$((total_count + 1))
    if test_endpoint "$API_BASE/warehouse-dashboard.php?action=dashboard" "Get dashboard data"; then
        success_count=$((success_count + 1))
    fi
    
    # Test 4: Dashboard with filters
    total_count=$((total_count + 1))
    if test_endpoint "$API_BASE/warehouse-dashboard.php?action=dashboard&limit=10&active_only=true" "Get dashboard data with filters"; then
        success_count=$((success_count + 1))
    fi
    
    # Test 5: Export endpoint (should return CSV)
    echo "Testing: CSV export endpoint"
    echo "URL: $API_BASE/warehouse-dashboard.php?action=export&limit=5"
    
    local csv_response=$(curl -s "$API_BASE/warehouse-dashboard.php?action=export&limit=5" 2>/dev/null)
    if echo "$csv_response" | head -n 1 | grep -q "product_name\|Product Name"; then
        echo "✓ CSV export working"
        echo "CSV preview:"
        echo "$csv_response" | head -n 3
        success_count=$((success_count + 1))
    else
        echo "✗ CSV export failed"
        echo "Response:"
        echo "$csv_response" | head -n 3
    fi
    total_count=$((total_count + 1))
    echo
    
    # Test 6: Invalid action (should return 400)
    total_count=$((total_count + 1))
    if test_endpoint "$API_BASE/warehouse-dashboard.php?action=invalid" "Invalid action test" "400"; then
        success_count=$((success_count + 1))
    fi
    
    # Test 7: CORS preflight
    echo "Testing: CORS preflight request"
    local cors_response=$(curl -s -X OPTIONS -H "Origin: https://www.market-mi.ru" "$API_BASE/warehouse-dashboard.php" -w "%{http_code}")
    local cors_status=$(echo "$cors_response" | tail -c 4)
    
    if [ "$cors_status" = "200" ]; then
        echo "✓ CORS preflight working"
        success_count=$((success_count + 1))
    else
        echo "✗ CORS preflight failed (Status: $cors_status)"
    fi
    total_count=$((total_count + 1))
    echo
    
    # Summary
    echo "=== Test Summary ==="
    echo "Passed: $success_count/$total_count"
    echo "Success rate: $(echo "scale=1; $success_count * 100 / $total_count" | bc)%"
    
    if [ $success_count -eq $total_count ]; then
        echo "✓ All tests passed!"
        return 0
    else
        echo "⚠ Some tests failed"
        return 1
    fi
}

# Function to test database connectivity
test_database() {
    echo "=== Database Connectivity Test ==="
    
    # This would need to be run on the server
    echo "To test database connectivity on server, run:"
    echo "ssh root@market-mi.ru 'cd /var/www/market-mi.ru && php scripts/test_db_connection.php'"
    echo
}

# Function to test server resources
test_server_resources() {
    echo "=== Server Resource Test ==="
    
    # Test server response headers
    echo "Testing server response headers..."
    local headers=$(curl -s -I "$BASE_URL/api/warehouse-dashboard.php?action=warehouses" 2>/dev/null)
    
    if echo "$headers" | grep -q "HTTP/[12].[01] 200"; then
        echo "✓ Server responding with HTTP 200"
    else
        echo "✗ Server not responding correctly"
        echo "$headers" | head -n 5
    fi
    
    # Check for security headers
    if echo "$headers" | grep -qi "x-content-type-options"; then
        echo "✓ Security headers present"
    else
        echo "⚠ Security headers missing"
    fi
    
    # Check for CORS headers
    if echo "$headers" | grep -qi "access-control-allow-origin"; then
        echo "✓ CORS headers present"
    else
        echo "⚠ CORS headers missing"
    fi
    
    echo
}

# Function to generate test report
generate_report() {
    local success_rate="$1"
    
    cat > "$TEST_RESULTS_FILE" << EOF
{
    "test_date": "$(date -u +%Y-%m-%dT%H:%M:%SZ)",
    "base_url": "$BASE_URL",
    "api_base": "$API_BASE",
    "success_rate": "$success_rate",
    "tests_performed": [
        "warehouses_endpoint",
        "clusters_endpoint", 
        "dashboard_endpoint",
        "dashboard_with_filters",
        "csv_export",
        "invalid_action_handling",
        "cors_preflight"
    ],
    "status": "$([ "$success_rate" = "100.0" ] && echo "PASS" || echo "PARTIAL")"
}
EOF
    
    echo "Test report saved to: $TEST_RESULTS_FILE"
}

# Main testing process
main() {
    echo "Starting backend API tests..."
    echo
    
    test_database
    test_server_resources
    
    if test_all_endpoints; then
        generate_report "100.0"
        echo
        echo "=== Backend Testing Complete - All Tests Passed ==="
    else
        generate_report "$(echo "scale=1; $success_count * 100 / $total_count" | bc)"
        echo
        echo "=== Backend Testing Complete - Some Issues Found ==="
        echo "Check the test output above for details"
        exit 1
    fi
}

# Check dependencies
if ! command -v jq &> /dev/null; then
    echo "Error: jq is required for JSON parsing"
    echo "Install with: brew install jq (macOS) or apt-get install jq (Ubuntu)"
    exit 1
fi

if ! command -v bc &> /dev/null; then
    echo "Error: bc is required for calculations"
    echo "Install with: brew install bc (macOS) or apt-get install bc (Ubuntu)"
    exit 1
fi

# Run tests
main "$@"