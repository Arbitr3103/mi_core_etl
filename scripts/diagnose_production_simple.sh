#!/bin/bash

# Simple Production Diagnostic Script for Warehouse Dashboard
# Uses curl to check production deployment

PROD_URL="https://www.market-mi.ru/warehouse-dashboard/"
TIMESTAMP=$(date +"%Y-%m-%d %H:%M:%S")

echo "🔍 Warehouse Dashboard Production Diagnostics"
echo "=============================================="
echo "URL: $PROD_URL"
echo "Time: $TIMESTAMP"
echo ""

# Step 1: Check HTML page
echo "📄 Checking HTML page..."
HTTP_STATUS=$(curl -s -o /tmp/warehouse_dashboard.html -w "%{http_code}" "$PROD_URL")

if [ "$HTTP_STATUS" = "200" ]; then
    echo "✅ HTML loads successfully (HTTP $HTTP_STATUS)"
    HTML_SIZE=$(wc -c < /tmp/warehouse_dashboard.html)
    echo "   Size: $HTML_SIZE bytes"
else
    echo "❌ HTML load failed (HTTP $HTTP_STATUS)"
    exit 1
fi
echo ""

# Step 2: Extract and check CSS files
echo "🎨 Checking CSS files..."
CSS_FILES=$(grep -o 'href="[^"]*\.css[^"]*"' /tmp/warehouse_dashboard.html | sed 's/href="//;s/"//')

if [ -z "$CSS_FILES" ]; then
    echo "⚠️  No CSS files found in HTML"
else
    CSS_COUNT=$(echo "$CSS_FILES" | wc -l | tr -d ' ')
    echo "Found $CSS_COUNT CSS file(s):"
    echo ""
    
    while IFS= read -r css_file; do
        # Build full URL
        if [[ $css_file == http* ]]; then
            CSS_URL="$css_file"
        else
            CSS_URL="https://www.market-mi.ru${css_file}"
        fi
        
        # Check CSS file
        CSS_STATUS=$(curl -s -o /tmp/css_temp.css -w "%{http_code}" "$CSS_URL")
        CSS_SIZE=$(wc -c < /tmp/css_temp.css 2>/dev/null || echo "0")
        
        if [ "$CSS_STATUS" = "200" ]; then
            echo "✅ $css_file"
            echo "   Status: $CSS_STATUS, Size: $CSS_SIZE bytes"
            
            if [ "$CSS_SIZE" = "0" ]; then
                echo "   ⚠️  WARNING: File is empty!"
            else
                # Check for Tailwind classes
                if grep -q "\.flex{" /tmp/css_temp.css || \
                   grep -q "\.grid{" /tmp/css_temp.css || \
                   grep -q "\.bg-gray-" /tmp/css_temp.css; then
                    echo "   ✅ Tailwind CSS classes detected"
                else
                    echo "   ⚠️  Tailwind CSS classes not clearly detected"
                fi
            fi
        else
            echo "❌ $css_file"
            echo "   Status: $CSS_STATUS (FAILED)"
        fi
        echo ""
    done <<< "$CSS_FILES"
fi

# Step 3: Extract and check JavaScript files
echo "📜 Checking JavaScript files..."
JS_FILES=$(grep -o 'src="[^"]*\.js[^"]*"' /tmp/warehouse_dashboard.html | sed 's/src="//;s/"//')

if [ -z "$JS_FILES" ]; then
    echo "⚠️  No JavaScript files found in HTML"
else
    JS_COUNT=$(echo "$JS_FILES" | wc -l | tr -d ' ')
    echo "Found $JS_COUNT JavaScript file(s):"
    echo ""
    
    while IFS= read -r js_file; do
        # Build full URL
        if [[ $js_file == http* ]]; then
            JS_URL="$js_file"
        else
            JS_URL="https://www.market-mi.ru${js_file}"
        fi
        
        # Check JS file
        JS_STATUS=$(curl -s -o /dev/null -w "%{http_code}" "$JS_URL")
        
        if [ "$JS_STATUS" = "200" ]; then
            JS_SIZE=$(curl -s "$JS_URL" | wc -c)
            echo "✅ $js_file"
            echo "   Status: $JS_STATUS, Size: $JS_SIZE bytes"
        else
            echo "❌ $js_file"
            echo "   Status: $JS_STATUS (FAILED)"
        fi
        echo ""
    done <<< "$JS_FILES"
fi

# Step 4: Check for common layout issues in HTML
echo "🔍 Checking HTML structure..."
if grep -q 'class="min-h-screen' /tmp/warehouse_dashboard.html; then
    echo "✅ Root layout class found (min-h-screen)"
else
    echo "⚠️  Root layout class not found"
fi

if grep -q 'class=".*flex' /tmp/warehouse_dashboard.html; then
    echo "✅ Flexbox classes found in HTML"
else
    echo "⚠️  No flexbox classes found in HTML"
fi

if grep -q 'class=".*grid' /tmp/warehouse_dashboard.html; then
    echo "✅ Grid classes found in HTML"
else
    echo "⚠️  No grid classes found in HTML"
fi
echo ""

# Summary
echo "=============================================="
echo "✅ Diagnostic Complete"
echo ""
echo "Next Steps:"
echo "1. Check browser console at: $PROD_URL"
echo "2. Use browser DevTools to inspect element styles"
echo "3. Verify Tailwind classes are applying correctly"
echo "4. Check for z-index conflicts in overlapping elements"
echo ""

# Cleanup
rm -f /tmp/warehouse_dashboard.html /tmp/css_temp.css
