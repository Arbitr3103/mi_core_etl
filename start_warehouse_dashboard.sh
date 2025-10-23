#!/bin/bash

echo "🚀 Starting Warehouse Dashboard"
echo "==============================="
echo ""

# Check if servers are already running
BACKEND_RUNNING=$(curl -s "http://localhost:8000/api/warehouse-dashboard.php" 2>/dev/null | grep -c "success")
FRONTEND_RUNNING=$(curl -s "http://localhost:3000" 2>/dev/null | grep -c "root")

if [ "$BACKEND_RUNNING" -gt 0 ]; then
    echo "✅ Backend already running on http://localhost:8000"
else
    echo "🔄 Starting backend server..."
    php -S localhost:8000 -t . > /dev/null 2>&1 &
    BACKEND_PID=$!
    echo "   Backend PID: $BACKEND_PID"
    sleep 2
fi

if [ "$FRONTEND_RUNNING" -gt 0 ]; then
    echo "✅ Frontend already running on http://localhost:3000"
else
    echo "🔄 Starting frontend server..."
    cd frontend
    npm run dev > /dev/null 2>&1 &
    FRONTEND_PID=$!
    echo "   Frontend PID: $FRONTEND_PID"
    cd ..
    sleep 3
fi

echo ""
echo "🧪 Running quick tests..."
bash test_dashboard.sh | grep -E "(✅|❌)"

echo ""
echo "🎉 Warehouse Dashboard is ready!"
echo ""
echo "📊 Access URLs:"
echo "   • Dashboard:  http://localhost:3000/warehouse-dashboard"
echo "   • API:        http://localhost:8000/api/warehouse-dashboard.php"
echo ""
echo "📖 Documentation:"
echo "   • User Guide: docs/WAREHOUSE_DASHBOARD_USER_GUIDE.md"
echo "   • API Docs:   docs/WAREHOUSE_DASHBOARD_API.md"
echo ""
echo "🛠️  Management:"
echo "   • Test:       bash test_dashboard.sh"
echo "   • Verify:     bash .kiro/specs/warehouse-dashboard/verify_implementation.sh"
echo "   • Stop:       pkill -f 'php -S localhost:8000' && pkill -f 'npm run dev'"
echo ""

# Try to open in browser
if command -v open >/dev/null 2>&1; then
    echo "🌐 Opening dashboard in browser..."
    open "http://localhost:3000/warehouse-dashboard"
elif command -v xdg-open >/dev/null 2>&1; then
    xdg-open "http://localhost:3000/warehouse-dashboard"
elif command -v start >/dev/null 2>&1; then
    start "http://localhost:3000/warehouse-dashboard"
else
    echo "⚠️  Please manually open: http://localhost:3000/warehouse-dashboard"
fi

echo ""
echo "✨ Happy analyzing! ✨"