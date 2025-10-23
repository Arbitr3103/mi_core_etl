#!/bin/bash

echo "🧪 Testing Warehouse Dashboard"
echo "=============================="
echo ""

# Test backend API
echo "1. Testing Backend API..."
API_RESPONSE=$(curl -s "http://localhost:8000/api/warehouse-dashboard.php")
if echo "$API_RESPONSE" | grep -q '"success":true'; then
    echo "   ✅ Backend API working"
else
    echo "   ❌ Backend API failed"
    echo "   Response: $API_RESPONSE"
fi

# Test frontend proxy
echo ""
echo "2. Testing Frontend Proxy..."
PROXY_RESPONSE=$(curl -s "http://localhost:3000/api/warehouse-dashboard.php")
if echo "$PROXY_RESPONSE" | grep -q '"success":true'; then
    echo "   ✅ Frontend proxy working"
else
    echo "   ❌ Frontend proxy failed"
    echo "   Response: $PROXY_RESPONSE"
fi

# Test frontend page
echo ""
echo "3. Testing Frontend Page..."
FRONTEND_RESPONSE=$(curl -s "http://localhost:3000/warehouse-dashboard")
if echo "$FRONTEND_RESPONSE" | grep -q '<div id="root">'; then
    echo "   ✅ Frontend page loading"
else
    echo "   ❌ Frontend page failed"
fi

# Test specific endpoints
echo ""
echo "4. Testing API Endpoints..."

# Warehouses
WAREHOUSES=$(curl -s "http://localhost:8000/api/warehouse-dashboard.php?action=warehouses")
if echo "$WAREHOUSES" | grep -q '"warehouse_name"'; then
    echo "   ✅ Warehouses endpoint working"
else
    echo "   ❌ Warehouses endpoint failed"
fi

# Clusters
CLUSTERS=$(curl -s "http://localhost:8000/api/warehouse-dashboard.php?action=clusters")
if echo "$CLUSTERS" | grep -q '"cluster"'; then
    echo "   ✅ Clusters endpoint working"
else
    echo "   ❌ Clusters endpoint failed"
fi

# Export (check if it returns CSV headers)
EXPORT=$(curl -s "http://localhost:8000/api/warehouse-dashboard.php?action=export" | head -1)
if echo "$EXPORT" | grep -q "Товар"; then
    echo "   ✅ Export endpoint working"
else
    echo "   ❌ Export endpoint failed"
fi

echo ""
echo "5. Database Check..."
DB_COUNT=$(psql -d mi_core_db -t -c "SELECT COUNT(*) FROM inventory WHERE warehouse_name IS NOT NULL;" 2>/dev/null | tr -d ' ')
if [ "$DB_COUNT" -gt 0 ]; then
    echo "   ✅ Database has $DB_COUNT inventory records"
else
    echo "   ❌ No inventory data found"
fi

METRICS_COUNT=$(psql -d mi_core_db -t -c "SELECT COUNT(*) FROM warehouse_sales_metrics;" 2>/dev/null | tr -d ' ')
if [ "$METRICS_COUNT" -gt 0 ]; then
    echo "   ✅ Database has $METRICS_COUNT metrics records"
else
    echo "   ❌ No metrics data found"
fi

echo ""
echo "📊 Dashboard URLs:"
echo "   • Frontend: http://localhost:3000/warehouse-dashboard"
echo "   • Backend:  http://localhost:8000/api/warehouse-dashboard.php"
echo ""
echo "🔧 Quick fixes if issues:"
echo "   • Restart frontend: npm run dev (in frontend/)"
echo "   • Restart backend:  php -S localhost:8000 -t ."
echo "   • Check logs:       Browser DevTools Console"
echo ""