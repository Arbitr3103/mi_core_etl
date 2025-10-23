#!/bin/bash

# Production Verification Script for Warehouse Dashboard
# This script performs comprehensive verification of the production deployment

PRODUCTION_URL="https://www.market-mi.ru/warehouse-dashboard"
TIMESTAMP=$(date +"%Y-%m-%d_%H-%M-%S")
REPORT_FILE="production_verification_report_${TIMESTAMP}.json"

echo "=========================================="
echo "Production Verification - Warehouse Dashboard"
echo "URL: $PRODUCTION_URL"
echo "Timestamp: $TIMESTAMP"
echo "=========================================="
echo ""

# Initialize report
cat > "$REPORT_FILE" << 'EOF'
{
  "timestamp": "",
  "url": "",
  "visual_verification": {},
  "functional_testing": {},
  "performance": {},
  "overall_status": "pending"
}
EOF

# Update timestamp and URL
sed -i.bak "s|\"timestamp\": \"\"|\"timestamp\": \"$TIMESTAMP\"|g" "$REPORT_FILE"
sed -i.bak "s|\"url\": \"\"|\"url\": \"$PRODUCTION_URL\"|g" "$REPORT_FILE"
rm -f "${REPORT_FILE}.bak"

echo "1. VISUAL VERIFICATION CHECKLIST"
echo "=================================="
echo ""

# Check if page loads
echo "Checking if page loads..."
HTTP_STATUS=$(curl -s -o /dev/null -w "%{http_code}" "$PRODUCTION_URL")

if [ "$HTTP_STATUS" = "200" ]; then
    echo "✓ Page loads successfully (HTTP $HTTP_STATUS)"
    PAGE_LOADS="true"
else
    echo "✗ Page failed to load (HTTP $HTTP_STATUS)"
    PAGE_LOADS="false"
fi
echo ""

# Fetch page content
echo "Fetching page content..."
PAGE_CONTENT=$(curl -s "$PRODUCTION_URL")

# Check for key HTML elements
echo "Checking for key HTML elements..."
echo ""

# Header check
if echo "$PAGE_CONTENT" | grep -q "header\|Header"; then
    echo "✓ Header element found"
    HEADER_PRESENT="true"
else
    echo "✗ Header element not found"
    HEADER_PRESENT="false"
fi

# Navigation check
if echo "$PAGE_CONTENT" | grep -q "nav\|Navigation"; then
    echo "✓ Navigation element found"
    NAV_PRESENT="true"
else
    echo "✗ Navigation element not found"
    NAV_PRESENT="false"
fi

# Main content check
if echo "$PAGE_CONTENT" | grep -q "main\|dashboard"; then
    echo "✓ Main content area found"
    MAIN_PRESENT="true"
else
    echo "✗ Main content area not found"
    MAIN_PRESENT="false"
fi

# Table check
if echo "$PAGE_CONTENT" | grep -q "table\|Table"; then
    echo "✓ Table element found"
    TABLE_PRESENT="true"
else
    echo "✗ Table element not found"
    TABLE_PRESENT="false"
fi

echo ""

# Check for CSS files
echo "Checking for CSS files..."
CSS_FILES=$(echo "$PAGE_CONTENT" | grep -o 'href="[^"]*\.css"' | wc -l)
echo "Found $CSS_FILES CSS file references"

if [ "$CSS_FILES" -gt 0 ]; then
    echo "✓ CSS files are referenced"
    CSS_LOADED="true"
else
    echo "✗ No CSS files found"
    CSS_LOADED="false"
fi
echo ""

# Check for JavaScript files
echo "Checking for JavaScript files..."
JS_FILES=$(echo "$PAGE_CONTENT" | grep -o 'src="[^"]*\.js"' | wc -l)
echo "Found $JS_FILES JavaScript file references"

if [ "$JS_FILES" -gt 0 ]; then
    echo "✓ JavaScript files are referenced"
    JS_LOADED="true"
else
    echo "✗ No JavaScript files found"
    JS_LOADED="false"
fi
echo ""

# Check for React root
if echo "$PAGE_CONTENT" | grep -q 'id="root"'; then
    echo "✓ React root element found"
    REACT_ROOT="true"
else
    echo "✗ React root element not found"
    REACT_ROOT="false"
fi
echo ""

echo "2. API ENDPOINT VERIFICATION"
echo "============================="
echo ""

# Check API endpoint
API_URL="https://www.market-mi.ru/api/warehouse-dashboard.php"
echo "Checking API endpoint: $API_URL"

API_STATUS=$(curl -s -o /dev/null -w "%{http_code}" "$API_URL")

if [ "$API_STATUS" = "200" ]; then
    echo "✓ API endpoint responds (HTTP $API_STATUS)"
    API_WORKS="true"
    
    # Check API response
    API_RESPONSE=$(curl -s "$API_URL")
    
    if echo "$API_RESPONSE" | grep -q "warehouses\|data"; then
        echo "✓ API returns expected data structure"
        API_DATA_VALID="true"
    else
        echo "✗ API response format unexpected"
        API_DATA_VALID="false"
    fi
else
    echo "✗ API endpoint failed (HTTP $API_STATUS)"
    API_WORKS="false"
    API_DATA_VALID="false"
fi
echo ""

echo "3. PERFORMANCE CHECKS"
echo "====================="
echo ""

# Measure page load time
echo "Measuring page load time..."
LOAD_TIME=$(curl -s -o /dev/null -w "%{time_total}" "$PRODUCTION_URL")
echo "Page load time: ${LOAD_TIME}s"

# Check if under 3 seconds
if (( $(echo "$LOAD_TIME < 3.0" | bc -l) )); then
    echo "✓ Page loads in under 3 seconds"
    LOAD_TIME_OK="true"
else
    echo "✗ Page load time exceeds 3 seconds"
    LOAD_TIME_OK="false"
fi
echo ""

# Check page size
echo "Checking page size..."
PAGE_SIZE=$(curl -s "$PRODUCTION_URL" | wc -c)
PAGE_SIZE_KB=$((PAGE_SIZE / 1024))
echo "Page size: ${PAGE_SIZE_KB}KB"

if [ "$PAGE_SIZE_KB" -gt 0 ] && [ "$PAGE_SIZE_KB" -lt 5000 ]; then
    echo "✓ Page size is reasonable"
    PAGE_SIZE_OK="true"
else
    echo "⚠ Page size may be too large or too small"
    PAGE_SIZE_OK="false"
fi
echo ""

echo "4. RESOURCE VERIFICATION"
echo "========================"
echo ""

# Extract and check CSS files
echo "Verifying CSS resources..."
CSS_URLS=$(echo "$PAGE_CONTENT" | grep -o 'href="[^"]*\.css"' | sed 's/href="//g' | sed 's/"//g')

CSS_OK_COUNT=0
CSS_FAIL_COUNT=0

for CSS_URL in $CSS_URLS; do
    # Handle relative URLs
    if [[ "$CSS_URL" != http* ]]; then
        CSS_URL="https://www.market-mi.ru${CSS_URL}"
    fi
    
    CSS_STATUS=$(curl -s -o /dev/null -w "%{http_code}" "$CSS_URL")
    
    if [ "$CSS_STATUS" = "200" ]; then
        echo "✓ CSS file loads: $CSS_URL"
        CSS_OK_COUNT=$((CSS_OK_COUNT + 1))
    else
        echo "✗ CSS file failed: $CSS_URL (HTTP $CSS_STATUS)"
        CSS_FAIL_COUNT=$((CSS_FAIL_COUNT + 1))
    fi
done

if [ "$CSS_FAIL_COUNT" -eq 0 ] && [ "$CSS_OK_COUNT" -gt 0 ]; then
    ALL_CSS_OK="true"
else
    ALL_CSS_OK="false"
fi
echo ""

# Extract and check JS files
echo "Verifying JavaScript resources..."
JS_URLS=$(echo "$PAGE_CONTENT" | grep -o 'src="[^"]*\.js"' | sed 's/src="//g' | sed 's/"//g')

JS_OK_COUNT=0
JS_FAIL_COUNT=0

for JS_URL in $JS_URLS; do
    # Handle relative URLs
    if [[ "$JS_URL" != http* ]]; then
        JS_URL="https://www.market-mi.ru${JS_URL}"
    fi
    
    JS_STATUS=$(curl -s -o /dev/null -w "%{http_code}" "$JS_URL")
    
    if [ "$JS_STATUS" = "200" ]; then
        echo "✓ JS file loads: $JS_URL"
        JS_OK_COUNT=$((JS_OK_COUNT + 1))
    else
        echo "✗ JS file failed: $JS_URL (HTTP $JS_STATUS)"
        JS_FAIL_COUNT=$((JS_FAIL_COUNT + 1))
    fi
done

if [ "$JS_FAIL_COUNT" -eq 0 ] && [ "$JS_OK_COUNT" -gt 0 ]; then
    ALL_JS_OK="true"
else
    ALL_JS_OK="false"
fi
echo ""

echo "5. SUMMARY"
echo "=========="
echo ""

# Calculate overall status
TOTAL_CHECKS=0
PASSED_CHECKS=0

check_result() {
    TOTAL_CHECKS=$((TOTAL_CHECKS + 1))
    if [ "$1" = "true" ]; then
        PASSED_CHECKS=$((PASSED_CHECKS + 1))
        return 0
    else
        return 1
    fi
}

check_result "$PAGE_LOADS" && echo "✓ Page loads" || echo "✗ Page loads"
check_result "$HEADER_PRESENT" && echo "✓ Header present" || echo "✗ Header present"
check_result "$NAV_PRESENT" && echo "✓ Navigation present" || echo "✗ Navigation present"
check_result "$MAIN_PRESENT" && echo "✓ Main content present" || echo "✗ Main content present"
check_result "$TABLE_PRESENT" && echo "✓ Table present" || echo "✗ Table present"
check_result "$CSS_LOADED" && echo "✓ CSS loaded" || echo "✗ CSS loaded"
check_result "$JS_LOADED" && echo "✓ JavaScript loaded" || echo "✗ JavaScript loaded"
check_result "$REACT_ROOT" && echo "✓ React root present" || echo "✗ React root present"
check_result "$API_WORKS" && echo "✓ API works" || echo "✗ API works"
check_result "$API_DATA_VALID" && echo "✓ API data valid" || echo "✗ API data valid"
check_result "$LOAD_TIME_OK" && echo "✓ Load time OK" || echo "✗ Load time OK"
check_result "$ALL_CSS_OK" && echo "✓ All CSS OK" || echo "✗ All CSS OK"
check_result "$ALL_JS_OK" && echo "✓ All JS OK" || echo "✗ All JS OK"

echo ""
echo "Results: $PASSED_CHECKS/$TOTAL_CHECKS checks passed"
echo ""

# Determine overall status
if [ "$PASSED_CHECKS" -eq "$TOTAL_CHECKS" ]; then
    OVERALL_STATUS="success"
    echo "✓ ALL CHECKS PASSED - Production deployment verified!"
elif [ "$PASSED_CHECKS" -ge $((TOTAL_CHECKS * 70 / 100)) ]; then
    OVERALL_STATUS="warning"
    echo "⚠ MOST CHECKS PASSED - Some issues detected"
else
    OVERALL_STATUS="failure"
    echo "✗ VERIFICATION FAILED - Critical issues detected"
fi

# Generate JSON report
cat > "$REPORT_FILE" << EOF
{
  "timestamp": "$TIMESTAMP",
  "url": "$PRODUCTION_URL",
  "visual_verification": {
    "page_loads": $PAGE_LOADS,
    "header_present": $HEADER_PRESENT,
    "navigation_present": $NAV_PRESENT,
    "main_content_present": $MAIN_PRESENT,
    "table_present": $TABLE_PRESENT,
    "react_root_present": $REACT_ROOT
  },
  "resources": {
    "css_files_referenced": $CSS_FILES,
    "css_files_loaded": $CSS_LOADED,
    "all_css_ok": $ALL_CSS_OK,
    "js_files_referenced": $JS_FILES,
    "js_files_loaded": $JS_LOADED,
    "all_js_ok": $ALL_JS_OK
  },
  "api_verification": {
    "api_responds": $API_WORKS,
    "api_data_valid": $API_DATA_VALID
  },
  "performance": {
    "load_time_seconds": $LOAD_TIME,
    "load_time_ok": $LOAD_TIME_OK,
    "page_size_kb": $PAGE_SIZE_KB,
    "page_size_ok": $PAGE_SIZE_OK
  },
  "summary": {
    "total_checks": $TOTAL_CHECKS,
    "passed_checks": $PASSED_CHECKS,
    "pass_rate": "$(echo "scale=2; $PASSED_CHECKS * 100 / $TOTAL_CHECKS" | bc)%"
  },
  "overall_status": "$OVERALL_STATUS"
}
EOF

echo ""
echo "Report saved to: $REPORT_FILE"
echo ""

# Exit with appropriate code
if [ "$OVERALL_STATUS" = "success" ]; then
    exit 0
elif [ "$OVERALL_STATUS" = "warning" ]; then
    exit 1
else
    exit 2
fi
