#!/bin/bash
#
# Setup MDM Data Quality Monitoring Cron Jobs
#
# This script sets up automated monitoring for the MDM system
# Requirements: 8.3, 4.3

echo "=== MDM Monitoring Cron Setup ==="
echo ""

# Get the current directory
SCRIPT_DIR="$( cd "$( dirname "${BASH_SOURCE[0]}" )" && pwd )"

echo "Script directory: $SCRIPT_DIR"
echo ""

# Check if monitor script exists
if [ ! -f "$SCRIPT_DIR/monitor_data_quality.php" ]; then
    echo "Error: monitor_data_quality.php not found!"
    exit 1
fi

# Make script executable
chmod +x "$SCRIPT_DIR/monitor_data_quality.php"
echo "✓ Made monitor script executable"

# Create logs directory if not exists
mkdir -p "$SCRIPT_DIR/logs"
echo "✓ Created logs directory"

# Create cron job entries
CRON_FILE="/tmp/mdm_monitoring_cron.txt"

cat > "$CRON_FILE" << EOF
# MDM Data Quality Monitoring Cron Jobs
# Generated on $(date)

# Run quality checks every hour
0 * * * * cd $SCRIPT_DIR && php monitor_data_quality.php >> logs/monitoring_cron.log 2>&1

# Run detailed check with verbose output every 6 hours
0 */6 * * * cd $SCRIPT_DIR && php monitor_data_quality.php --verbose >> logs/monitoring_detailed.log 2>&1

# Generate daily report at 8 AM
0 8 * * * cd $SCRIPT_DIR && php monitor_data_quality.php --verbose --report-only > logs/daily_report_\$(date +\%Y\%m\%d).log 2>&1

# Clean old logs weekly (keep last 30 days)
0 3 * * 0 find $SCRIPT_DIR/logs -name "*.log" -mtime +30 -delete

EOF

echo ""
echo "Cron job entries created in: $CRON_FILE"
echo ""
echo "=== Cron Job Schedule ==="
cat "$CRON_FILE"
echo ""

# Ask user if they want to install cron jobs
read -p "Do you want to install these cron jobs? (y/n) " -n 1 -r
echo ""

if [[ $REPLY =~ ^[Yy]$ ]]; then
    # Backup existing crontab
    crontab -l > /tmp/crontab_backup_$(date +%Y%m%d_%H%M%S).txt 2>/dev/null
    
    # Add new cron jobs (avoiding duplicates)
    (crontab -l 2>/dev/null | grep -v "MDM Data Quality Monitoring"; cat "$CRON_FILE") | crontab -
    
    echo "✓ Cron jobs installed successfully!"
    echo ""
    echo "Current crontab:"
    crontab -l | grep -A 10 "MDM Data Quality Monitoring"
else
    echo "Cron jobs not installed."
    echo "To install manually, run:"
    echo "  crontab -e"
    echo "Then add the contents of: $CRON_FILE"
fi

echo ""
echo "=== Configuration ==="
echo ""
echo "To configure alert thresholds, edit: config.php"
echo "Add these constants:"
echo ""
echo "  define('ALERT_EMAIL', 'admin@example.com');"
echo "  define('SLACK_WEBHOOK_URL', 'https://hooks.slack.com/...');"
echo ""

echo "=== Testing ==="
echo ""
echo "To test the monitoring system:"
echo "  php monitor_data_quality.php --verbose"
echo ""
echo "To view the dashboard:"
echo "  Open: http://your-domain/html/quality_dashboard.php"
echo ""

echo "=== Log Files ==="
echo "  Monitoring logs: $SCRIPT_DIR/logs/monitoring_cron.log"
echo "  Detailed logs: $SCRIPT_DIR/logs/monitoring_detailed.log"
echo "  Alert logs: $SCRIPT_DIR/logs/quality_alerts.log"
echo "  Daily reports: $SCRIPT_DIR/logs/daily_report_*.log"
echo ""

echo "Setup complete!"
