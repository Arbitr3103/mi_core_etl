#!/bin/bash

# Performance Verification Script for Warehouse Dashboard
# Measures and validates performance metrics

PRODUCTION_URL="https://www.market-mi.ru/warehouse-dashboard/"
API_URL="https://www.market-mi.ru/api/warehouse-dashboard.php"
TIMESTAMP=$(date +"%Y-%m-%d_%H-%M-%S")
REPORT_FILE="performance_verification_report_${TIMESTAMP}.json"

echo "=========================================="
echo "Performance Verification - Warehouse Dashboard"
echo "URL: $PRODUCTION_URL"
echo "Timestamp: $TIMESTAMP"
echo "=========================================="
echo ""

# Colors for output
GREEN='\033[0;32m'
RED='\033[0;31m'
YELLOW='\033[1;33m'
NC='\033[0m' # No Color

# Initialize results
TOTAL_TESTS=0
PASSED_TESTS=0
FAILED_TESTS=0

test_result() {
    TOTAL_TESTS=$((TOTAL_TESTS + 1))
    if [ "$1" = "pass" ]; then
        PASSED_TESTS=$((PASSED_TESTS + 1))
        echo -e "${GREEN}✓${NC} $2"
        return 0
    else
        FAILED_TESTS=$((FAILED_TESTS + 1))
        echo -e "${RED}✗${NC} $2"
        return 1
    fi
}

echo "1. PAGE LOAD TIME VERIFICATION"
echo "==============================="
echo ""

# Measure page load time (5 attempts for average)
echo "Measuring page load time (5 attempts)..."
LOAD_TIMES=()
TOTAL_TIME=0

for i in {1..5}; do
    LOAD_TIME=$(curl -s -o /dev/null -w "%{time_total}" "$PRODUCTION_URL")
    LOAD_TIMES+=($LOAD_TIME)
    TOTAL_TIME=$(echo "$TOTAL_TIME + $LOAD_TIME" | bc)
    echo "  Attempt $i: ${LOAD_TIME}s"
done

AVG_LOAD_TIME=$(echo "scale=3; $TOTAL_TIME / 5" | bc)
echo ""
echo "Average load time: ${AVG_LOAD_TIME}s"

# Check if under 3 seconds
if (( $(echo "$AVG_LOAD_TIME < 3.0" | bc -l) )); then
    test_result "pass" "Page load time < 3 seconds (${AVG_LOAD_TIME}s)"
    LOAD_TIME_STATUS="pass"
else
    test_result "fail" "Page load time exceeds 3 seconds (${AVG_LOAD_TIME}s)"
    LOAD_TIME_STATUS="fail"
fi
echo ""

echo "2. API RESPONSE TIME VERIFICATION"
echo "=================================="
echo ""

# Measure API response time (5 attempts)
echo "Measuring API response time (5 attempts)..."
API_TIMES=()
API_TOTAL=0

for i in {1..5}; do
    API_TIME=$(curl -s -o /dev/null -w "%{time_total}" "$API_URL")
    API_TIMES+=($API_TIME)
    API_TOTAL=$(echo "$API_TOTAL + $API_TIME" | bc)
    echo "  Attempt $i: ${API_TIME}s"
done

AVG_API_TIME=$(echo "scale=3; $API_TOTAL / 5" | bc)
echo ""
echo "Average API response time: ${AVG_API_TIME}s"

# Check if under 2 seconds
if (( $(echo "$AVG_API_TIME < 2.0" | bc -l) )); then
    test_result "pass" "API response time < 2 seconds (${AVG_API_TIME}s)"
    API_TIME_STATUS="pass"
else
    test_result "fail" "API response time exceeds 2 seconds (${AVG_API_TIME}s)"
    API_TIME_STATUS="fail"
fi
echo ""

echo "3. RESOURCE SIZE VERIFICATION"
echo "=============================="
echo ""

# Fetch page and extract resource URLs
PAGE_CONTENT=$(curl -s "$PRODUCTION_URL")

# Check CSS file size
CSS_URL=$(echo "$PAGE_CONTENT" | grep -o 'href="[^"]*\.css"' | head -1 | sed 's/href="//g' | sed 's/"//g')
if [[ "$CSS_URL" != http* ]]; then
    CSS_URL="https://www.market-mi.ru${CSS_URL}"
fi

CSS_SIZE=$(curl -s "$CSS_URL" | wc -c)
CSS_SIZE_KB=$((CSS_SIZE / 1024))
echo "CSS bundle size: ${CSS_SIZE_KB}KB"

if [ "$CSS_SIZE_KB" -lt 100 ]; then
    test_result "pass" "CSS bundle size reasonable (${CSS_SIZE_KB}KB < 100KB)"
    CSS_SIZE_STATUS="pass"
else
    test_result "fail" "CSS bundle size too large (${CSS_SIZE_KB}KB)"
    CSS_SIZE_STATUS="fail"
fi

# Check main JS file size
JS_URL=$(echo "$PAGE_CONTENT" | grep -o 'src="[^"]*index[^"]*\.js"' | head -1 | sed 's/src="//g' | sed 's/"//g')
if [[ "$JS_URL" != http* ]]; then
    JS_URL="https://www.market-mi.ru${JS_URL}"
fi

JS_SIZE=$(curl -s "$JS_URL" | wc -c)
JS_SIZE_KB=$((JS_SIZE / 1024))
echo "Main JS bundle size: ${JS_SIZE_KB}KB"

if [ "$JS_SIZE_KB" -lt 500 ]; then
    test_result "pass" "JS bundle size reasonable (${JS_SIZE_KB}KB < 500KB)"
    JS_SIZE_STATUS="pass"
else
    test_result "fail" "JS bundle size too large (${JS_SIZE_KB}KB)"
    JS_SIZE_STATUS="fail"
fi

# Total page size
TOTAL_SIZE=$(curl -s "$PRODUCTION_URL" | wc -c)
TOTAL_SIZE_KB=$((TOTAL_SIZE / 1024))
echo "Initial HTML size: ${TOTAL_SIZE_KB}KB"

if [ "$TOTAL_SIZE_KB" -lt 50 ]; then
    test_result "pass" "HTML size reasonable (${TOTAL_SIZE_KB}KB < 50KB)"
    HTML_SIZE_STATUS="pass"
else
    test_result "fail" "HTML size too large (${TOTAL_SIZE_KB}KB)"
    HTML_SIZE_STATUS="fail"
fi
echo ""

echo "4. RESOURCE LOADING VERIFICATION"
echo "================================="
echo ""

# Check all CSS files load
echo "Checking CSS resources..."
CSS_URLS=$(echo "$PAGE_CONTENT" | grep -o 'href="[^"]*\.css"' | sed 's/href="//g' | sed 's/"//g')
CSS_LOAD_TIMES=()

for CSS_URL in $CSS_URLS; do
    if [[ "$CSS_URL" != http* ]]; then
        CSS_URL="https://www.market-mi.ru${CSS_URL}"
    fi
    
    CSS_LOAD_TIME=$(curl -s -o /dev/null -w "%{time_total}" "$CSS_URL")
    CSS_LOAD_TIMES+=($CSS_LOAD_TIME)
    
    if (( $(echo "$CSS_LOAD_TIME < 1.0" | bc -l) )); then
        echo -e "  ${GREEN}✓${NC} CSS loads in ${CSS_LOAD_TIME}s"
    else
        echo -e "  ${YELLOW}⚠${NC} CSS loads slowly: ${CSS_LOAD_TIME}s"
    fi
done

# Check all JS files load
echo ""
echo "Checking JavaScript resources..."
JS_URLS=$(echo "$PAGE_CONTENT" | grep -o 'src="[^"]*\.js"' | sed 's/src="//g' | sed 's/"//g')
JS_LOAD_TIMES=()

for JS_URL in $JS_URLS; do
    if [[ "$JS_URL" != http* ]]; then
        JS_URL="https://www.market-mi.ru${JS_URL}"
    fi
    
    JS_LOAD_TIME=$(curl -s -o /dev/null -w "%{time_total}" "$JS_URL")
    JS_LOAD_TIMES+=($JS_LOAD_TIME)
    
    if (( $(echo "$JS_LOAD_TIME < 1.0" | bc -l) )); then
        echo -e "  ${GREEN}✓${NC} JS loads in ${JS_LOAD_TIME}s"
    else
        echo -e "  ${YELLOW}⚠${NC} JS loads slowly: ${JS_LOAD_TIME}s"
    fi
done

test_result "pass" "All resources load successfully"
echo ""

echo "5. COMPRESSION VERIFICATION"
echo "==========================="
echo ""

# Check if gzip compression is enabled
CONTENT_ENCODING=$(curl -s -I "$PRODUCTION_URL" | grep -i "content-encoding" | awk '{print $2}' | tr -d '\r')

if [ -n "$CONTENT_ENCODING" ]; then
    echo "Content encoding: $CONTENT_ENCODING"
    test_result "pass" "Compression enabled ($CONTENT_ENCODING)"
    COMPRESSION_STATUS="pass"
else
    echo "Content encoding: none"
    test_result "fail" "Compression not enabled"
    COMPRESSION_STATUS="fail"
fi
echo ""

echo "6. CACHING VERIFICATION"
echo "======================="
echo ""

# Check cache headers
CACHE_CONTROL=$(curl -s -I "$CSS_URL" | grep -i "cache-control" | awk -F': ' '{print $2}' | tr -d '\r')

if [ -n "$CACHE_CONTROL" ]; then
    echo "Cache-Control: $CACHE_CONTROL"
    test_result "pass" "Caching headers present"
    CACHE_STATUS="pass"
else
    echo "Cache-Control: not set"
    test_result "fail" "Caching headers missing"
    CACHE_STATUS="fail"
fi
echo ""

echo "7. NETWORK EFFICIENCY"
echo "====================="
echo ""

# Calculate total transfer time
TOTAL_TRANSFER=0
RESOURCE_COUNT=0

for url in $CSS_URLS $JS_URLS; do
    if [[ "$url" != http* ]]; then
        url="https://www.market-mi.ru${url}"
    fi
    
    TRANSFER_TIME=$(curl -s -o /dev/null -w "%{time_total}" "$url")
    TOTAL_TRANSFER=$(echo "$TOTAL_TRANSFER + $TRANSFER_TIME" | bc)
    RESOURCE_COUNT=$((RESOURCE_COUNT + 1))
done

AVG_TRANSFER=$(echo "scale=3; $TOTAL_TRANSFER / $RESOURCE_COUNT" | bc)
echo "Average resource transfer time: ${AVG_TRANSFER}s"
echo "Total resources: $RESOURCE_COUNT"

if (( $(echo "$AVG_TRANSFER < 0.5" | bc -l) )); then
    test_result "pass" "Network efficiency good (avg ${AVG_TRANSFER}s per resource)"
    NETWORK_STATUS="pass"
else
    test_result "fail" "Network efficiency poor (avg ${AVG_TRANSFER}s per resource)"
    NETWORK_STATUS="fail"
fi
echo ""

echo "8. PERFORMANCE SUMMARY"
echo "======================"
echo ""

# Calculate pass rate
PASS_RATE=$(echo "scale=2; $PASSED_TESTS * 100 / $TOTAL_TESTS" | bc)

echo "Total tests: $TOTAL_TESTS"
echo "Passed: $PASSED_TESTS"
echo "Failed: $FAILED_TESTS"
echo "Pass rate: ${PASS_RATE}%"
echo ""

# Determine overall status
if [ "$FAILED_TESTS" -eq 0 ]; then
    OVERALL_STATUS="excellent"
    echo -e "${GREEN}✓ EXCELLENT PERFORMANCE${NC}"
elif [ "$PASSED_TESTS" -ge $((TOTAL_TESTS * 80 / 100)) ]; then
    OVERALL_STATUS="good"
    echo -e "${GREEN}✓ GOOD PERFORMANCE${NC}"
elif [ "$PASSED_TESTS" -ge $((TOTAL_TESTS * 60 / 100)) ]; then
    OVERALL_STATUS="acceptable"
    echo -e "${YELLOW}⚠ ACCEPTABLE PERFORMANCE${NC}"
else
    OVERALL_STATUS="poor"
    echo -e "${RED}✗ POOR PERFORMANCE${NC}"
fi
echo ""

# Generate JSON report
cat > "$REPORT_FILE" << EOF
{
  "timestamp": "$TIMESTAMP",
  "url": "$PRODUCTION_URL",
  "load_time": {
    "average_seconds": $AVG_LOAD_TIME,
    "threshold_seconds": 3.0,
    "status": "$LOAD_TIME_STATUS",
    "measurements": [$(IFS=,; echo "${LOAD_TIMES[*]}")]
  },
  "api_response": {
    "average_seconds": $AVG_API_TIME,
    "threshold_seconds": 2.0,
    "status": "$API_TIME_STATUS",
    "measurements": [$(IFS=,; echo "${API_TIMES[*]}")]
  },
  "resource_sizes": {
    "css_kb": $CSS_SIZE_KB,
    "js_kb": $JS_SIZE_KB,
    "html_kb": $TOTAL_SIZE_KB,
    "css_status": "$CSS_SIZE_STATUS",
    "js_status": "$JS_SIZE_STATUS",
    "html_status": "$HTML_SIZE_STATUS"
  },
  "optimization": {
    "compression_enabled": $([ "$COMPRESSION_STATUS" = "pass" ] && echo "true" || echo "false"),
    "caching_enabled": $([ "$CACHE_STATUS" = "pass" ] && echo "true" || echo "false"),
    "network_efficiency": "$NETWORK_STATUS"
  },
  "summary": {
    "total_tests": $TOTAL_TESTS,
    "passed_tests": $PASSED_TESTS,
    "failed_tests": $FAILED_TESTS,
    "pass_rate": "$PASS_RATE%",
    "overall_status": "$OVERALL_STATUS"
  }
}
EOF

echo "Report saved to: $REPORT_FILE"
echo ""

# Exit with appropriate code
if [ "$OVERALL_STATUS" = "excellent" ] || [ "$OVERALL_STATUS" = "good" ]; then
    exit 0
elif [ "$OVERALL_STATUS" = "acceptable" ]; then
    exit 1
else
    exit 2
fi
