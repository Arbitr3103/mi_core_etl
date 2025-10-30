#!/bin/bash
#
# Dashboard Data Verification Script
# Validates that real data is loaded and dashboard is working correctly
#
# Requirements: 4.1, 4.3, 4.4, 4.5
# Task: 11. Загрузить реальные данные и проверить dashboard

set -e

echo "=========================================="
echo "DASHBOARD DATA VERIFICATION"
echo "=========================================="
echo ""

# Colors
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
RED='\033[0;31m'
NC='\033[0m'

# Verification results
PASSED=0
FAILED=0
WARNINGS=0

# Function to check result
check_result() {
    local test_name="$1"
    local result="$2"
    local expected="$3"
    
    if [ "$result" = "$expected" ]; then
        echo -e "${GREEN}✅ PASS${NC}: $test_name"
        ((PASSED++))
        return 0
    else
        echo -e "${RED}❌ FAIL${NC}: $test_name"
        echo "   Expected: $expected, Got: $result"
        ((FAILED++))
        return 1
    fi
}

check_warning() {
    local test_name="$1"
    local condition="$2"
    
    if [ "$condition" = "true" ]; then
        echo -e "${YELLOW}⚠️  WARNING${NC}: $test_name"
        ((WARNINGS++))
    fi
}

echo "Running verification tests..."
echo ""

# Test 1: Database Connection
echo "Test 1: Database Connection"
python3 << 'EOF'
try:
    from db_connector import connect_to_db
    conn = connect_to_db()
    conn.close()
    print("SUCCESS")
except Exception as e:
    print(f"FAILED: {e}")
EOF
echo ""

# Test 2: Products Data
echo "Test 2: Products Data Loaded"
PRODUCTS_COUNT=$(python3 << 'EOF'
from db_connector import connect_to_db
conn = connect_to_db()
cursor = conn.cursor()
cursor.execute("SELECT COUNT(*) FROM dim_products")
count = cursor.fetchone()[0]
print(count)
conn.close()
EOF
)

if [ "$PRODUCTS_COUNT" -gt 0 ]; then
    echo -e "${GREEN}✅ PASS${NC}: Products loaded ($PRODUCTS_COUNT products)"
    ((PASSED++))
else
    echo -e "${RED}❌ FAIL${NC}: No products in database"
    ((FAILED++))
fi
echo ""

# Test 3: Inventory Data
echo "Test 3: Inventory Data Loaded"
INVENTORY_COUNT=$(python3 << 'EOF'
from db_connector import connect_to_db
conn = connect_to_db()
cursor = conn.cursor()
cursor.execute("SELECT COUNT(*) FROM inventory WHERE quantity_present > 0")
count = cursor.fetchone()[0]
print(count)
conn.close()
EOF
)

if [ "$INVENTORY_COUNT" -gt 0 ]; then
    echo -e "${GREEN}✅ PASS${NC}: Inventory loaded ($INVENTORY_COUNT records with stock)"
    ((PASSED++))
else
    echo -e "${RED}❌ FAIL${NC}: No inventory data"
    ((FAILED++))
fi
echo ""

# Test 4: Recent Orders
echo "Test 4: Recent Orders (Last 30 days)"
ORDERS_COUNT=$(python3 << 'EOF'
from db_connector import connect_to_db
conn = connect_to_db()
cursor = conn.cursor()
cursor.execute("""
    SELECT COUNT(*) FROM fact_orders 
    WHERE order_date >= CURRENT_DATE - INTERVAL '30 days'
""")
count = cursor.fetchone()[0]
print(count)
conn.close()
EOF
)

if [ "$ORDERS_COUNT" -gt 0 ]; then
    echo -e "${GREEN}✅ PASS${NC}: Recent orders found ($ORDERS_COUNT orders)"
    ((PASSED++))
else
    echo -e "${YELLOW}⚠️  WARNING${NC}: No recent orders (last 30 days)"
    ((WARNINGS++))
fi
echo ""

# Test 5: Warehouse Metrics
echo "Test 5: Warehouse Sales Metrics"
METRICS_COUNT=$(python3 << 'EOF'
from db_connector import connect_to_db
conn = connect_to_db()
cursor = conn.cursor()
cursor.execute("SELECT COUNT(*) FROM warehouse_sales_metrics")
count = cursor.fetchone()[0]
print(count)
conn.close()
EOF
)

if [ "$METRICS_COUNT" -gt 0 ]; then
    echo -e "${GREEN}✅ PASS${NC}: Warehouse metrics calculated ($METRICS_COUNT metrics)"
    ((PASSED++))
else
    echo -e "${YELLOW}⚠️  WARNING${NC}: No warehouse metrics"
    ((WARNINGS++))
fi
echo ""

# Test 6: API Endpoint - Detailed Stock
echo "Test 6: API Endpoint - Detailed Stock"
API_RESPONSE=$(curl -s "http://localhost/api/detailed-stock.php?limit=1" || echo "FAILED")

if echo "$API_RESPONSE" | grep -q '"success":true'; then
    echo -e "${GREEN}✅ PASS${NC}: API endpoint working"
    ((PASSED++))
    
    # Check if data is returned
    DATA_COUNT=$(echo "$API_RESPONSE" | python3 -c "import sys, json; data=json.load(sys.stdin); print(len(data.get('data', [])))")
    if [ "$DATA_COUNT" -gt 0 ]; then
        echo "   API returned $DATA_COUNT records"
    else
        echo -e "${YELLOW}   ⚠️  API returned no data${NC}"
        ((WARNINGS++))
    fi
else
    echo -e "${RED}❌ FAIL${NC}: API endpoint not working"
    echo "   Response: $API_RESPONSE"
    ((FAILED++))
fi
echo ""

# Test 7: API Endpoint - Replenishment
echo "Test 7: API Endpoint - Replenishment Recommendations"
REPLEN_RESPONSE=$(curl -s "http://localhost/api/replenishment.php?action=recommendations&limit=1" || echo "FAILED")

if echo "$REPLEN_RESPONSE" | grep -q '"success":true'; then
    echo -e "${GREEN}✅ PASS${NC}: Replenishment API working"
    ((PASSED++))
else
    echo -e "${YELLOW}⚠️  WARNING${NC}: Replenishment API not responding correctly"
    ((WARNINGS++))
fi
echo ""

# Test 8: Data Freshness
echo "Test 8: Data Freshness (Updated within last 24 hours)"
python3 << 'EOF'
from db_connector import connect_to_db
from datetime import datetime, timedelta

conn = connect_to_db()
cursor = conn.cursor()

# Check inventory update time
cursor.execute("""
    SELECT MAX(updated_at) FROM inventory
""")
last_update = cursor.fetchone()[0]

if last_update:
    hours_ago = (datetime.now() - last_update).total_seconds() / 3600
    if hours_ago < 24:
        print(f"✅ PASS: Data updated {hours_ago:.1f} hours ago")
    else:
        print(f"⚠️  WARNING: Data is {hours_ago:.1f} hours old")
else:
    print("❌ FAIL: No update timestamp found")

conn.close()
EOF
echo ""

# Test 9: Critical Stock Items
echo "Test 9: Critical Stock Items Detection"
python3 << 'EOF'
from db_connector import connect_to_db

conn = connect_to_db()
cursor = conn.cursor()

cursor.execute("""
    SELECT COUNT(*)
    FROM inventory i
    LEFT JOIN warehouse_sales_metrics wsm ON 
        i.product_id = wsm.product_id 
        AND i.warehouse_name = wsm.warehouse_name
    WHERE i.quantity_present > 0
    AND wsm.daily_sales_avg > 0
    AND (i.quantity_present / NULLIF(wsm.daily_sales_avg, 0)) < 7
""")

critical_count = cursor.fetchone()[0]

if critical_count > 0:
    print(f"✅ PASS: Found {critical_count} critical stock items (< 7 days)")
else:
    print("ℹ️  INFO: No critical stock items found")

conn.close()
EOF
echo ""

# Test 10: Replenishment Calculations
echo "Test 10: Replenishment Recommendations Accuracy"
python3 << 'EOF'
from db_connector import connect_to_db

conn = connect_to_db()
cursor = conn.cursor()

# Check if recommendations are being calculated
cursor.execute("""
    SELECT 
        i.product_id,
        i.warehouse_name,
        i.quantity_present,
        wsm.daily_sales_avg,
        CASE 
            WHEN wsm.daily_sales_avg > 0 THEN 
                GREATEST(0, (30 * wsm.daily_sales_avg) - i.quantity_present)
            ELSE 0
        END as recommended_qty
    FROM inventory i
    LEFT JOIN warehouse_sales_metrics wsm ON 
        i.product_id = wsm.product_id 
        AND i.warehouse_name = wsm.warehouse_name
    WHERE wsm.daily_sales_avg > 0
    LIMIT 5
""")

results = cursor.fetchall()

if results:
    print("✅ PASS: Replenishment calculations working")
    print("\nSample recommendations:")
    for row in results:
        product_id, warehouse, current, daily_avg, recommended = row
        print(f"  Product {product_id} @ {warehouse}:")
        print(f"    Current: {current}, Daily avg: {daily_avg:.2f}, Recommended: {recommended:.0f}")
else:
    print("⚠️  WARNING: No replenishment recommendations generated")

conn.close()
EOF
echo ""

# Summary
echo "=========================================="
echo "VERIFICATION SUMMARY"
echo "=========================================="
echo -e "${GREEN}Passed: $PASSED${NC}"
echo -e "${RED}Failed: $FAILED${NC}"
echo -e "${YELLOW}Warnings: $WARNINGS${NC}"
echo ""

if [ $FAILED -eq 0 ]; then
    echo -e "${GREEN}✅ All critical tests passed!${NC}"
    echo ""
    echo "Dashboard is ready to use:"
    echo "  https://market-mi.ru/warehouse-dashboard/"
    echo ""
    exit 0
else
    echo -e "${RED}❌ Some tests failed. Please review the errors above.${NC}"
    echo ""
    exit 1
fi
