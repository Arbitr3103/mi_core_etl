#!/bin/bash
# Analytics ETL Status Check Script
# Automatically created by setup_analytics_etl_cron.sh

SCRIPT_DIR="/Users/vladimirbragin/CascadeProjects/mi_core_etl"

echo "=== Analytics ETL Status Check ==="
echo "Date: $(date)"
echo ""

# Check if ETL process is currently running
ETL_PROCESSES=$(pgrep -f "warehouse_etl_analytics.php" | wc -l)
if [ "$ETL_PROCESSES" -gt 0 ]; then
    echo "🔄 ETL processes currently running: $ETL_PROCESSES"
    pgrep -f "warehouse_etl_analytics.php" | xargs ps -p
else
    echo "✅ No ETL processes currently running"
fi

echo ""

# Check recent ETL runs from database
echo "📊 Recent ETL runs (last 24 hours):"
/opt/homebrew/bin/php -r "
require_once '/Users/vladimirbragin/CascadeProjects/mi_core_etl/config.php';
try {
    $pdo = getDatabaseConnection();
    $stmt = $pdo->query(\"
        SELECT 
            batch_id,
            status,
            records_processed,
            execution_time,
            started_at,
            completed_at
        FROM analytics_etl_log 
        WHERE started_at > NOW() - INTERVAL '24 hours'
        ORDER BY started_at DESC 
        LIMIT 10
    \");
    
    $runs = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    if (empty($runs)) {
        echo \"No ETL runs found in the last 24 hours\n\";
    } else {
        printf(\"%-20s %-12s %-10s %-12s %-20s\n\", 'Batch ID', 'Status', 'Records', 'Time (s)', 'Started At');
        echo str_repeat('-', 80) . \"\n\";
        
        foreach ($runs as $run) {
            printf(\"%-20s %-12s %-10s %-12s %-20s\n\",
                substr($run['batch_id'], -20),
                $run['status'],
                $run['records_processed'] ?? 'N/A',
                $run['execution_time'] ?? 'N/A',
                $run['started_at']
            );
        }
    }
} catch (Exception $e) {
    echo 'Database error: ' . $e->getMessage() . \"\n\";
}
"

echo ""

# Check log file sizes
echo "📁 Log file sizes:"
if [ -d "$SCRIPT_DIR/logs/analytics_etl" ]; then
    du -sh "$SCRIPT_DIR/logs/analytics_etl"/* 2>/dev/null | tail -10
else
    echo "No analytics ETL logs found"
fi

echo ""

# Check for recent errors
echo "🚨 Recent errors (last 100 lines):"
if [ -f "$SCRIPT_DIR/logs/cron/analytics_etl_main.log" ]; then
    tail -100 "$SCRIPT_DIR/logs/cron/analytics_etl_main.log" | grep -i "error\|failed\|exception" | tail -5
else
    echo "No main ETL log file found"
fi

echo ""
echo "=== Status Check Complete ==="
