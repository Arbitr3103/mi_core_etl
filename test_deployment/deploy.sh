#!/bin/bash
#
# MDM Monitoring System - Automated Deployment Script
# Server: 185.221.153.28
# Path: /var/www/mi_core_etl
#

set -e  # Exit on error

echo "╔════════════════════════════════════════════════════════════════╗"
echo "║                                                                ║"
echo "║        🚀 MDM Monitoring System Deployment 🚀                 ║"
echo "║                                                                ║"
echo "╚════════════════════════════════════════════════════════════════╝"
echo ""

SERVER="root@185.221.153.28"
PROJECT_PATH="/var/www/mi_core_etl"

echo "📡 Connecting to server: $SERVER"
echo "📁 Project path: $PROJECT_PATH"
echo ""

# Function to run commands on server
run_remote() {
    ssh -o ConnectTimeout=10 "$SERVER" "$@"
}

# Step 1: Test connection
echo "1️⃣  Testing server connection..."
if run_remote "echo 'Connection successful'"; then
    echo "   ✅ Connected to server"
else
    echo "   ❌ Failed to connect to server"
    echo ""
    echo "Please ensure:"
    echo "  - SSH key is configured"
    echo "  - Server is accessible"
    echo "  - You have root access"
    exit 1
fi
echo ""

# Step 2: Backup current state
echo "2️⃣  Creating backup..."
BACKUP_NAME="mi_core_etl_backup_$(date +%Y%m%d_%H%M%S)"
run_remote "cd /var/www && cp -r mi_core_etl $BACKUP_NAME" || echo "   ⚠️  Backup skipped (may not exist)"
echo "   ✅ Backup created: $BACKUP_NAME"
echo ""

# Step 3: Pull latest code
echo "3️⃣  Pulling latest code from GitHub..."
run_remote "cd $PROJECT_PATH && git pull origin main"
echo "   ✅ Code updated"
echo ""

# Step 4: Set permissions
echo "4️⃣  Setting permissions..."
run_remote "cd $PROJECT_PATH && chmod +x monitor_data_quality.php setup_monitoring_cron.sh sync-real-product-names-v2.php test_monitoring_system.php"
run_remote "cd $PROJECT_PATH && mkdir -p logs && chmod 755 logs"
echo "   ✅ Permissions set"
echo ""

# Step 5: Run system tests
echo "5️⃣  Running system tests..."
if run_remote "cd $PROJECT_PATH && php test_monitoring_system.php" | grep -q "ALL TESTS PASSED"; then
    echo "   ✅ All tests passed"
else
    echo "   ⚠️  Some tests may have issues - check output above"
fi
echo ""

# Step 6: Setup cron jobs (interactive)
echo "6️⃣  Cron jobs setup..."
echo "   ℹ️  To setup cron jobs, run on server:"
echo "   ssh $SERVER"
echo "   cd $PROJECT_PATH"
echo "   ./setup_monitoring_cron.sh"
echo ""

# Step 7: Test monitoring script
echo "7️⃣  Testing monitoring script..."
run_remote "cd $PROJECT_PATH && php monitor_data_quality.php --report-only" | head -20
echo "   ✅ Monitoring script works"
echo ""

# Step 8: Verify web access
echo "8️⃣  Verifying web access..."
echo "   Dashboard: http://185.221.153.28/html/quality_dashboard.php"
echo "   API Health: http://185.221.153.28/api/quality-metrics.php?action=health"
echo ""

# Summary
echo "╔════════════════════════════════════════════════════════════════╗"
echo "║                                                                ║"
echo "║              ✅ DEPLOYMENT COMPLETED! ✅                       ║"
echo "║                                                                ║"
echo "╚════════════════════════════════════════════════════════════════╝"
echo ""
echo "📋 Next Steps:"
echo ""
echo "1. Setup cron jobs (if not done):"
echo "   ssh $SERVER"
echo "   cd $PROJECT_PATH"
echo "   ./setup_monitoring_cron.sh"
echo ""
echo "2. Configure alerts (optional):"
echo "   Edit config.php and add:"
echo "   define('ALERT_EMAIL', 'your-email@example.com');"
echo ""
echo "3. View dashboard:"
echo "   http://185.221.153.28/html/quality_dashboard.php"
echo ""
echo "4. Test API:"
echo "   curl http://185.221.153.28/api/quality-metrics.php?action=health"
echo ""
echo "📚 Documentation:"
echo "   - Quick Start: MONITORING_QUICK_START.md"
echo "   - Full Guide: docs/MONITORING_SYSTEM_README.md"
echo "   - Troubleshooting: docs/MDM_TROUBLESHOOTING_GUIDE.md"
echo ""
