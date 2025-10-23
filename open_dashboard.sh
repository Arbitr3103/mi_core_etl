#!/bin/bash

echo "🚀 Warehouse Dashboard - Quick Start"
echo "===================================="
echo ""
echo "Servers running:"
echo "📊 Backend API:  http://localhost:8000"
echo "🖥️  Frontend:    http://localhost:3000"
echo ""
echo "📋 Dashboard URL: http://localhost:3000/warehouse-dashboard"
echo ""
echo "🔧 API Endpoints:"
echo "   • Dashboard data: http://localhost:8000/api/warehouse-dashboard.php"
echo "   • Export CSV:     http://localhost:8000/api/warehouse-dashboard.php?export=csv"
echo "   • Warehouses:     http://localhost:8000/api/warehouse-dashboard.php?action=warehouses"
echo "   • Clusters:       http://localhost:8000/api/warehouse-dashboard.php?action=clusters"
echo ""
echo "🧪 Test API:"
curl -s "http://localhost:8000/api/warehouse-dashboard.php" | jq '.success' 2>/dev/null || echo "API response received (jq not available for formatting)"
echo ""
echo "📱 Opening dashboard in browser..."
echo "   If it doesn't open automatically, go to: http://localhost:3000/warehouse-dashboard"
echo ""

# Try to open in browser (macOS)
if command -v open >/dev/null 2>&1; then
    open "http://localhost:3000/warehouse-dashboard"
elif command -v xdg-open >/dev/null 2>&1; then
    xdg-open "http://localhost:3000/warehouse-dashboard"
elif command -v start >/dev/null 2>&1; then
    start "http://localhost:3000/warehouse-dashboard"
else
    echo "⚠️  Could not auto-open browser. Please manually navigate to:"
    echo "   http://localhost:3000/warehouse-dashboard"
fi

echo ""
echo "✅ Dashboard is ready!"
echo ""
echo "📖 Documentation:"
echo "   • User Guide: docs/WAREHOUSE_DASHBOARD_USER_GUIDE.md"
echo "   • API Docs:   docs/WAREHOUSE_DASHBOARD_API.md"
echo ""
echo "🛠️  Testing:"
echo "   • Run tests: bash .kiro/specs/warehouse-dashboard/run_final_tests.sh"
echo "   • Verify:    bash .kiro/specs/warehouse-dashboard/verify_implementation.sh"