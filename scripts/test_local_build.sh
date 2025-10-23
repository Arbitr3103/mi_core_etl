#!/bin/bash

# Test local build script
# Tests the production build served locally

echo "Testing local build at http://localhost:8080/warehouse-dashboard/"
echo ""

# Test 1: Check if index.html loads
echo "Test 1: Checking if index.html loads..."
HTTP_CODE=$(curl -s -o /dev/null -w "%{http_code}" http://localhost:8080/warehouse-dashboard/)
if [ "$HTTP_CODE" = "200" ]; then
    echo "✓ index.html loads successfully (HTTP $HTTP_CODE)"
else
    echo "✗ index.html failed to load (HTTP $HTTP_CODE)"
fi
echo ""

# Test 2: Check if CSS file loads
echo "Test 2: Checking if CSS file loads..."
CSS_CODE=$(curl -s -o /dev/null -w "%{http_code}" http://localhost:8080/warehouse-dashboard/assets/css/index-BnGjtDq2.css)
if [ "$CSS_CODE" = "200" ]; then
    echo "✓ CSS file loads successfully (HTTP $CSS_CODE)"
    CSS_SIZE=$(curl -s http://localhost:8080/warehouse-dashboard/assets/css/index-BnGjtDq2.css | wc -c)
    echo "  CSS file size: $CSS_SIZE bytes"
else
    echo "✗ CSS file failed to load (HTTP $CSS_CODE)"
fi
echo ""

# Test 3: Check if main JS file loads
echo "Test 3: Checking if main JS file loads..."
JS_CODE=$(curl -s -o /dev/null -w "%{http_code}" http://localhost:8080/warehouse-dashboard/assets/js/index-MPEEFBkx.js)
if [ "$JS_CODE" = "200" ]; then
    echo "✓ Main JS file loads successfully (HTTP $JS_CODE)"
else
    echo "✗ Main JS file failed to load (HTTP $JS_CODE)"
fi
echo ""

# Test 4: Check if React vendor bundle loads
echo "Test 4: Checking if React vendor bundle loads..."
REACT_CODE=$(curl -s -o /dev/null -w "%{http_code}" http://localhost:8080/warehouse-dashboard/assets/js/react-vendor-Dm4r2cAM.js)
if [ "$REACT_CODE" = "200" ]; then
    echo "✓ React vendor bundle loads successfully (HTTP $REACT_CODE)"
else
    echo "✗ React vendor bundle failed to load (HTTP $REACT_CODE)"
fi
echo ""

# Test 5: Check if warehouse dashboard page bundle loads
echo "Test 5: Checking if warehouse dashboard page bundle loads..."
WD_CODE=$(curl -s -o /dev/null -w "%{http_code}" http://localhost:8080/warehouse-dashboard/assets/js/WarehouseDashboardPage-CyQaM3TT.js)
if [ "$WD_CODE" = "200" ]; then
    echo "✓ Warehouse dashboard page bundle loads successfully (HTTP $WD_CODE)"
else
    echo "✗ Warehouse dashboard page bundle failed to load (HTTP $WD_CODE)"
fi
echo ""

# Test 6: Check HTML content
echo "Test 6: Checking HTML content..."
HTML_CONTENT=$(curl -s http://localhost:8080/warehouse-dashboard/)
if echo "$HTML_CONTENT" | grep -q "root"; then
    echo "✓ HTML contains root div"
else
    echo "✗ HTML missing root div"
fi

if echo "$HTML_CONTENT" | grep -q "index-BnGjtDq2.css"; then
    echo "✓ HTML references CSS file"
else
    echo "✗ HTML missing CSS reference"
fi

if echo "$HTML_CONTENT" | grep -q "index-MPEEFBkx.js"; then
    echo "✓ HTML references main JS file"
else
    echo "✗ HTML missing main JS reference"
fi
echo ""

echo "=========================================="
echo "Local build test complete!"
echo "=========================================="
echo ""
echo "To manually test in browser:"
echo "1. Open http://localhost:8080/warehouse-dashboard/"
echo "2. Check browser console for errors (F12)"
echo "3. Verify layout displays correctly"
echo "4. Test responsive design at different screen sizes"
echo ""
echo "Note: API calls will fail without backend running"
