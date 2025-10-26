#!/bin/bash
# Manual Analytics ETL Trigger Script
# Automatically created by setup_analytics_etl_cron.sh

SCRIPT_DIR="/Users/vladimirbragin/CascadeProjects/mi_core_etl"

echo "=== Manual Analytics ETL Execution ==="
echo "Date: $(date)"
echo ""

# Check if ETL is already running
ETL_PROCESSES=$(pgrep -f "warehouse_etl_analytics.php" | wc -l)
if [ "$ETL_PROCESSES" -gt 0 ]; then
    echo "⚠️  Warning: ETL process is already running ($ETL_PROCESSES processes)"
    echo "   Process IDs: $(pgrep -f 'warehouse_etl_analytics.php' | tr '\n' ' ')"
    echo ""
    read -p "Do you want to continue anyway? (y/n) " -n 1 -r
    echo ""
    if [[ ! $REPLY =~ ^[Yy]$ ]]; then
        echo "Manual ETL execution cancelled"
        exit 0
    fi
fi

# Parse command line arguments
DRY_RUN=""
DEBUG=""
LIMIT=""
FORCE="--force"
WAREHOUSE=""

while [[ $# -gt 0 ]]; do
    case $1 in
        --dry-run)
            DRY_RUN="--dry-run"
            shift
            ;;
        --debug)
            DEBUG="--debug"
            shift
            ;;
        --limit=*)
            LIMIT="$1"
            shift
            ;;
        --warehouse=*)
            WAREHOUSE="$1"
            shift
            ;;
        --help)
            echo "Usage: $0 [options]"
            echo "Options:"
            echo "  --dry-run          Run without making database changes"
            echo "  --debug            Enable debug output"
            echo "  --limit=N          Limit number of records to process"
            echo "  --warehouse=NAME   Process only specific warehouse"
            echo "  --help             Show this help"
            exit 0
            ;;
        *)
            echo "Unknown option: $1"
            echo "Use --help for usage information"
            exit 1
            ;;
    esac
done

# Build command
CMD="cd $SCRIPT_DIR && /opt/homebrew/bin/php warehouse_etl_analytics.php $FORCE $DRY_RUN $DEBUG $LIMIT $WAREHOUSE"

echo "🚀 Executing Analytics ETL with command:"
echo "   $CMD"
echo ""

# Execute ETL
eval $CMD

ETL_EXIT_CODE=$?

echo ""
if [ $ETL_EXIT_CODE -eq 0 ]; then
    echo "✅ Manual ETL execution completed successfully"
else
    echo "❌ Manual ETL execution failed with exit code: $ETL_EXIT_CODE"
fi

echo "📊 Check status with: ./check_analytics_etl_status.sh"
echo ""
