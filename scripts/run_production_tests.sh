#!/bin/bash

# Production Testing Script for Warehouse Dashboard
# Runs comprehensive tests for all deployment tasks

set -e

echo "🚀 Starting Warehouse Dashboard Production Testing"
echo "=================================================="
echo ""

# Colors for output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m' # No Color

# Test configuration
DASHBOARD_URL="https://www.market-mi.ru/warehouse-dashboard"
API_URL="https://www.market-mi.ru/api/warehouse-dashboard.php"
TIMEOUT=30
MAX_LOAD_TIME=3

# Test counters
TOTAL_TESTS=0
PASSED_TESTS=0
FAILED_TESTS=0

# Function to run a test and track results
run_test() {
    local test_name="$1"
    local test_command="$2"
    local expected_result="$3"
    
    TOTAL_TESTS=$((TOTAL_TESTS + 1))
    echo -n "Testing $test_name... "
    
    if eval "$test_command" > /dev/null 2>&1; then
        if [ "$expected_result" = "success" ]; then
            echo -e "${GREEN}✅ PASSED${NC}"
            PASSED_TESTS=$((PASSED_TESTS + 1))
            return 0
        else
            echo -e "${RED}❌ FAILED (unexpected success)${NC}"
            FAILED_TESTS=$((FAILED_TESTS + 1))
            return 1
        fi
    else
        if [ "$expected_result" = "failure" ]; then
            echo -e "${GREEN}✅ PASSED (expected failure)${NC}"
            PASSED_TESTS=$((PASSED_TESTS + 1))
            return 0
        else
            echo -e "${RED}❌ FAILED${NC}"
            FAILED_TESTS=$((FAILED_TESTS + 1))
            return 1
        fi
    fi
}

# Function to test HTTP response
test_http_response() {
    local url="$1"
    local expected_code="$2"
    local max_time="$3"
    
    local response=$(curl -s -w "%{http_code}:%{time_total}" -o /dev/null --max-time "$max_time" "$url" 2>/dev/null || echo "000:999")
    local http_code=$(echo "$response" | cut -d: -f1)
    local time_total=$(echo "$response" | cut -d: -f2)
    
    if [ "$http_code" = "$expected_code" ] && [ "$(echo "$time_total < $max_time" | bc -l 2>/dev/null || echo 0)" = "1" ]; then
        return 0
    else
        return 1
    fi
}

# Function to test API endpoint
test_api_endpoint() {
    local endpoint="$1"
    local description="$2"
    
    echo -n "Testing API endpoint ($description)... "
    
    local response=$(curl -s --max-time $TIMEOUT "$API_URL$endpoint" 2>/dev/null)
    local http_code=$(curl -s -w "%{http_code}" -o /dev/null --max-time $TIMEOUT "$API_URL$endpoint" 2>/dev/null)
    
    if [ "$http_code" = "200" ]; then
        # Check if response is valid JSON with success=true
        if echo "$response" | jq -e '.success == true' > /dev/null 2>&1; then
            echo -e "${GREEN}✅ PASSED${NC}"
            PASSED_TESTS=$((PASSED_TESTS + 1))
        else
            echo -e "${RED}❌ FAILED (invalid JSON or error response)${NC}"
            FAILED_TESTS=$((FAILED_TESTS + 1))
        fi
    else
        echo -e "${RED}❌ FAILED (HTTP $http_code)${NC}"
        FAILED_TESTS=$((FAILED_TESTS + 1))
    fi
    
    TOTAL_TESTS=$((TOTAL_TESTS + 1))
}

echo -e "${BLUE}📋 Task 9.1: Testing Dashboard Access${NC}"
echo "----------------------------------------"

# Test basic connectivity
echo -n "Testing basic connectivity... "
if curl -s --max-time $TIMEOUT "$DASHBOARD_URL" > /dev/null 2>&1; then
    echo -e "${GREEN}✅ PASSED${NC}"
    PASSED_TESTS=$((PASSED_TESTS + 1))
else
    echo -e "${RED}❌ FAILED${NC}"
    FAILED_TESTS=$((FAILED_TESTS + 1))
fi
TOTAL_TESTS=$((TOTAL_TESTS + 1))

# Test HTTPS redirect
echo -n "Testing HTTPS redirect... "
HTTP_URL=$(echo "$DASHBOARD_URL" | sed 's/https:/http:/')
REDIRECT_CODE=$(curl -s -w "%{http_code}" -o /dev/null --max-time $TIMEOUT "$HTTP_URL" 2>/dev/null)
if [ "$REDIRECT_CODE" = "301" ] || [ "$REDIRECT_CODE" = "302" ]; then
    echo -e "${GREEN}✅ PASSED${NC}"
    PASSED_TESTS=$((PASSED_TESTS + 1))
else
    echo -e "${RED}❌ FAILED (HTTP $REDIRECT_CODE)${NC}"
    FAILED_TESTS=$((FAILED_TESTS + 1))
fi
TOTAL_TESTS=$((TOTAL_TESTS + 1))

# Test page load time
echo -n "Testing page load time... "
LOAD_TIME=$(curl -s -w "%{time_total}" -o /dev/null --max-time $TIMEOUT "$DASHBOARD_URL" 2>/dev/null)
if [ "$(echo "$LOAD_TIME < $MAX_LOAD_TIME" | bc -l 2>/dev/null || echo 0)" = "1" ]; then
    echo -e "${GREEN}✅ PASSED (${LOAD_TIME}s)${NC}"
    PASSED_TESTS=$((PASSED_TESTS + 1))
else
    echo -e "${RED}❌ FAILED (${LOAD_TIME}s)${NC}"
    FAILED_TESTS=$((FAILED_TESTS + 1))
fi
TOTAL_TESTS=$((TOTAL_TESTS + 1))

# Test SSL certificate
echo -n "Testing SSL certificate... "
if curl -s --max-time $TIMEOUT "$DASHBOARD_URL" > /dev/null 2>&1; then
    echo -e "${GREEN}✅ PASSED${NC}"
    PASSED_TESTS=$((PASSED_TESTS + 1))
else
    echo -e "${RED}❌ FAILED${NC}"
    FAILED_TESTS=$((FAILED_TESTS + 1))
fi
TOTAL_TESTS=$((TOTAL_TESTS + 1))

echo ""
echo -e "${BLUE}⚙️ Task 9.2: Testing Dashboard Features${NC}"
echo "----------------------------------------"

# Test API endpoints
test_api_endpoint "?action=dashboard" "dashboard"
test_api_endpoint "?action=warehouses" "warehouses"
test_api_endpoint "?action=clusters" "clusters"

# Test filters
test_api_endpoint "?action=dashboard&warehouse=1" "warehouse filter"
test_api_endpoint "?action=dashboard&cluster=premium" "cluster filter"
test_api_endpoint "?action=dashboard&liquidity=high" "liquidity filter"

# Test sorting
test_api_endpoint "?action=dashboard&sort=product_name&order=asc" "sorting"

# Test CSV export
echo -n "Testing CSV export... "
CONTENT_TYPE=$(curl -s -I --max-time $TIMEOUT "$API_URL?action=export" | grep -i "content-type" | cut -d: -f2 | tr -d ' \r\n')
if [[ "$CONTENT_TYPE" == *"text/csv"* ]]; then
    echo -e "${GREEN}✅ PASSED${NC}"
    PASSED_TESTS=$((PASSED_TESTS + 1))
else
    echo -e "${RED}❌ FAILED (Content-Type: $CONTENT_TYPE)${NC}"
    FAILED_TESTS=$((FAILED_TESTS + 1))
fi
TOTAL_TESTS=$((TOTAL_TESTS + 1))

# Test pagination
test_api_endpoint "?action=dashboard&page=1&limit=10" "pagination"

echo ""
echo -e "${BLUE}📱 Task 9.3: Testing Mobile Responsiveness${NC}"
echo "-------------------------------------------"

# Test mobile user agents
USER_AGENTS=(
    "Mozilla/5.0 (iPhone; CPU iPhone OS 14_7_1 like Mac OS X) AppleWebKit/605.1.15"
    "Mozilla/5.0 (Linux; Android 11; SM-G991B) AppleWebKit/537.36"
    "Mozilla/5.0 (iPad; CPU OS 14_7_1 like Mac OS X) AppleWebKit/605.1.15"
)

DEVICE_NAMES=("iPhone" "Android" "iPad")

for i in "${!USER_AGENTS[@]}"; do
    echo -n "Testing mobile access (${DEVICE_NAMES[$i]})... "
    HTTP_CODE=$(curl -s -w "%{http_code}" -o /dev/null --max-time $TIMEOUT -H "User-Agent: ${USER_AGENTS[$i]}" "$DASHBOARD_URL" 2>/dev/null)
    if [ "$HTTP_CODE" = "200" ]; then
        echo -e "${GREEN}✅ PASSED${NC}"
        PASSED_TESTS=$((PASSED_TESTS + 1))
    else
        echo -e "${RED}❌ FAILED (HTTP $HTTP_CODE)${NC}"
        FAILED_TESTS=$((FAILED_TESTS + 1))
    fi
    TOTAL_TESTS=$((TOTAL_TESTS + 1))
done

# Test viewport meta tag
echo -n "Testing viewport meta tag... "
RESPONSE=$(curl -s --max-time $TIMEOUT "$DASHBOARD_URL" 2>/dev/null)
if echo "$RESPONSE" | grep -q "viewport"; then
    echo -e "${GREEN}✅ PASSED${NC}"
    PASSED_TESTS=$((PASSED_TESTS + 1))
else
    echo -e "${RED}❌ FAILED${NC}"
    FAILED_TESTS=$((FAILED_TESTS + 1))
fi
TOTAL_TESTS=$((TOTAL_TESTS + 1))

echo ""
echo -e "${BLUE}⚡ Task 9.4: Testing Performance${NC}"
echo "--------------------------------"

# Test API response times
ENDPOINTS=("?action=dashboard" "?action=warehouses" "?action=clusters")
ENDPOINT_NAMES=("dashboard" "warehouses" "clusters")

for i in "${!ENDPOINTS[@]}"; do
    echo -n "Testing API response time (${ENDPOINT_NAMES[$i]})... "
    RESPONSE_TIME=$(curl -s -w "%{time_total}" -o /dev/null --max-time $TIMEOUT "$API_URL${ENDPOINTS[$i]}" 2>/dev/null)
    if [ "$(echo "$RESPONSE_TIME < 2" | bc -l 2>/dev/null || echo 0)" = "1" ]; then
        echo -e "${GREEN}✅ PASSED (${RESPONSE_TIME}s)${NC}"
        PASSED_TESTS=$((PASSED_TESTS + 1))
    else
        echo -e "${RED}❌ FAILED (${RESPONSE_TIME}s)${NC}"
        FAILED_TESTS=$((FAILED_TESTS + 1))
    fi
    TOTAL_TESTS=$((TOTAL_TESTS + 1))
done

# Test large dataset
echo -n "Testing large dataset handling... "
LARGE_RESPONSE_TIME=$(curl -s -w "%{time_total}" -o /dev/null --max-time 60 "$API_URL?action=dashboard&limit=1000" 2>/dev/null)
if [ "$(echo "$LARGE_RESPONSE_TIME < 10" | bc -l 2>/dev/null || echo 0)" = "1" ]; then
    echo -e "${GREEN}✅ PASSED (${LARGE_RESPONSE_TIME}s)${NC}"
    PASSED_TESTS=$((PASSED_TESTS + 1))
else
    echo -e "${RED}❌ FAILED (${LARGE_RESPONSE_TIME}s)${NC}"
    FAILED_TESTS=$((FAILED_TESTS + 1))
fi
TOTAL_TESTS=$((TOTAL_TESTS + 1))

# Test concurrent requests
echo -n "Testing concurrent requests... "
START_TIME=$(date +%s.%N)

# Run concurrent requests in background
curl -s --max-time $TIMEOUT "$API_URL?action=dashboard" > /dev/null 2>&1 &
curl -s --max-time $TIMEOUT "$API_URL?action=warehouses" > /dev/null 2>&1 &
curl -s --max-time $TIMEOUT "$API_URL?action=clusters" > /dev/null 2>&1 &

# Wait for all background jobs to complete
wait

END_TIME=$(date +%s.%N)
CONCURRENT_TIME=$(echo "$END_TIME - $START_TIME" | bc -l 2>/dev/null || echo "999")

if [ "$(echo "$CONCURRENT_TIME < 5" | bc -l 2>/dev/null || echo 0)" = "1" ]; then
    echo -e "${GREEN}✅ PASSED (${CONCURRENT_TIME}s)${NC}"
    PASSED_TESTS=$((PASSED_TESTS + 1))
else
    echo -e "${RED}❌ FAILED (${CONCURRENT_TIME}s)${NC}"
    FAILED_TESTS=$((FAILED_TESTS + 1))
fi
TOTAL_TESTS=$((TOTAL_TESTS + 1))

echo ""
echo "=================================================="
echo -e "${BLUE}📊 PRODUCTION TESTING SUMMARY${NC}"
echo "=================================================="
echo "Total Tests: $TOTAL_TESTS"
echo -e "Passed: ${GREEN}$PASSED_TESTS${NC} ($(( PASSED_TESTS * 100 / TOTAL_TESTS ))%)"
echo -e "Failed: ${RED}$FAILED_TESTS${NC} ($(( FAILED_TESTS * 100 / TOTAL_TESTS ))%)"
echo ""

if [ $FAILED_TESTS -eq 0 ]; then
    echo -e "${GREEN}🎉 ALL TESTS PASSED! Production deployment is successful.${NC}"
    echo ""
    echo "✅ Dashboard is accessible at: $DASHBOARD_URL"
    echo "✅ API is working at: $API_URL"
    echo "✅ All features are functional"
    echo "✅ Performance requirements are met"
    echo "✅ Mobile responsiveness is working"
    exit 0
else
    echo -e "${RED}⚠️ SOME TESTS FAILED. Please review and fix issues before considering deployment complete.${NC}"
    echo ""
    echo "Next steps:"
    echo "1. Review failed tests above"
    echo "2. Fix any configuration or code issues"
    echo "3. Re-run tests to verify fixes"
    echo "4. Check server logs for additional details"
    exit 1
fi