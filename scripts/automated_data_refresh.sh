#!/bin/bash

# Automated Data Refresh System for Warehouse Dashboard
# Handles scheduled data updates, ETL processes, and data quality monitoring

set -e

# Configuration
DATA_REFRESH_LOG="${LOG_PATH:-/var/log/warehouse-dashboard}/data_refresh_$(date +%Y%m%d).log"
LOCK_FILE="/tmp/warehouse_dashboard_data_refresh.lock"
MAX_RUNTIME="${MAX_RUNTIME:-3600}"  # 1 hour max runtime
EMAIL_REPORTS="${EMAIL_REPORTS_TO:-}"
SLACK_WEBHOOK="${SLACK_WEBHOOK_URL:-}"
PARALLEL_JOBS="${PARALLEL_JOBS:-4}"
DATA_QUALITY_CHECKS="${DATA_QUALITY_CHECKS:-true}"
INCREMENTAL_REFRESH="${INCREMENTAL_REFRESH:-true}"

# Colors for output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m' # No Color

# Logging function
log() {
    local message="[$(date '+%Y-%m-%d %H:%M:%S')] $1"
    echo -e "${GREEN}${message}${NC}"
    echo "$message" >> "$DATA_REFRESH_LOG"
}

error() {
    local message="[$(date '+%Y-%m-%d %H:%M:%S')] ERROR: $1"
    echo -e "${RED}${message}${NC}" >&2
    echo "$message" >> "$DATA_REFRESH_LOG"
}

warning() {
    local message="[$(date '+%Y-%m-%d %H:%M:%S')] WARNING: $1"
    echo -e "${YELLOW}${message}${NC}"
    echo "$message" >> "$DATA_REFRESH_LOG"
}

info() {
    local message="[$(date '+%Y-%m-%d %H:%M:%S')] INFO: $1"
    echo -e "${BLUE}${message}${NC}"
    echo "$message" >> "$DATA_REFRESH_LOG"
}

# Initialize logging
mkdir -p "$(dirname "$DATA_REFRESH_LOG")"

# Lock management
acquire_lock() {
    if [ -f "$LOCK_FILE" ]; then
        local lock_pid=$(cat "$LOCK_FILE")
        if kill -0 "$lock_pid" 2>/dev/null; then
            error "Data refresh already running (PID: $lock_pid)"
            exit 1
        else
            warning "Removing stale lock file"
            rm -f "$LOCK_FILE"
        fi
    fi
    
    echo $$ > "$LOCK_FILE"
    log "Acquired lock for data refresh process"
}

release_lock() {
    rm -f "$LOCK_FILE"
    log "Released lock for data refresh process"
}

# Cleanup on exit
cleanup() {
    local exit_code=$?
    log "Data refresh process ending with exit code: $exit_code"
    release_lock
    
    # Kill any background jobs
    jobs -p | xargs -r kill 2>/dev/null || true
    
    exit $exit_code
}

trap cleanup EXIT INT TERM

# Timeout handler
timeout_handler() {
    error "Data refresh process timed out after $MAX_RUNTIME seconds"
    cleanup
}

# Set timeout
(sleep "$MAX_RUNTIME" && kill -TERM $$) &
TIMEOUT_PID=$!

# Database connection test
test_database_connection() {
    log "Testing database connection..."
    
    require_once __DIR__ . '/../config/production.php'
    
    if php -r "
        require_once 'config/production.php';
        try {
            \$pdo = new PDO(
                \"pgsql:host={\$_ENV['DB_HOST']};port={\$_ENV['DB_PORT']};dbname={\$_ENV['DB_NAME']}\",
                \$_ENV['DB_USER'],
                \$_ENV['DB_PASSWORD'],
                [PDO::ATTR_TIMEOUT => 10]
            );
            \$pdo->query('SELECT 1');
            echo 'OK';
        } catch (Exception \$e) {
            echo 'FAILED: ' . \$e->getMessage();
            exit(1);
        }
    " >/dev/null 2>&1; then
        log "Database connection successful"
        return 0
    else
        error "Database connection failed"
        return 1
    fi
}

# Get last refresh timestamp
get_last_refresh_timestamp() {
    local table=$1
    local timestamp_column=${2:-"updated_at"}
    
    php -r "
        require_once 'config/production.php';
        try {
            \$pdo = new PDO(
                \"pgsql:host={\$_ENV['DB_HOST']};port={\$_ENV['DB_PORT']};dbname={\$_ENV['DB_NAME']}\",
                \$_ENV['DB_USER'],
                \$_ENV['DB_PASSWORD']
            );
            \$stmt = \$pdo->query(\"SELECT MAX($timestamp_column) FROM $table\");
            \$result = \$stmt->fetchColumn();
            echo \$result ?: '1970-01-01 00:00:00';
        } catch (Exception \$e) {
            echo '1970-01-01 00:00:00';
        }
    "
}

# Refresh Ozon data
refresh_ozon_data() {
    log "Starting Ozon data refresh..."
    
    local start_time=$(date +%s)
    local success=true
    
    # Get last refresh timestamp for incremental updates
    local last_refresh=""
    if [ "$INCREMENTAL_REFRESH" = "true" ]; then
        last_refresh=$(get_last_refresh_timestamp "ozon_products" "last_updated")
        log "Last Ozon refresh: $last_refresh"
    fi
    
    # Run Ozon data import
    if [ -f "scripts/import_ozon_data.php" ]; then
        log "Running Ozon data import..."
        
        if [ -n "$last_refresh" ]; then
            php scripts/import_ozon_data.php --since="$last_refresh" >> "$DATA_REFRESH_LOG" 2>&1
        else
            php scripts/import_ozon_data.php >> "$DATA_REFRESH_LOG" 2>&1
        fi
        
        if [ $? -eq 0 ]; then
            log "Ozon data import completed successfully"
        else
            error "Ozon data import failed"
            success=false
        fi
    else
        warning "Ozon data import script not found"
    fi
    
    # Update Ozon analytics
    if [ -f "scripts/update_ozon_analytics.php" ] && [ "$success" = true ]; then
        log "Updating Ozon analytics..."
        
        php scripts/update_ozon_analytics.php >> "$DATA_REFRESH_LOG" 2>&1
        
        if [ $? -eq 0 ]; then
            log "Ozon analytics updated successfully"
        else
            error "Ozon analytics update failed"
            success=false
        fi
    fi
    
    local end_time=$(date +%s)
    local duration=$((end_time - start_time))
    
    if [ "$success" = true ]; then
        log "Ozon data refresh completed in ${duration}s"
        return 0
    else
        error "Ozon data refresh failed after ${duration}s"
        return 1
    fi
}

# Refresh Wildberries data
refresh_wb_data() {
    log "Starting Wildberries data refresh..."
    
    local start_time=$(date +%s)
    local success=true
    
    # Get last refresh timestamp
    local last_refresh=""
    if [ "$INCREMENTAL_REFRESH" = "true" ]; then
        last_refresh=$(get_last_refresh_timestamp "wb_products" "last_updated")
        log "Last WB refresh: $last_refresh"
    fi
    
    # Run WB data import
    if [ -f "scripts/import_wb_data.php" ]; then
        log "Running Wildberries data import..."
        
        if [ -n "$last_refresh" ]; then
            php scripts/import_wb_data.php --since="$last_refresh" >> "$DATA_REFRESH_LOG" 2>&1
        else
            php scripts/import_wb_data.php >> "$DATA_REFRESH_LOG" 2>&1
        fi
        
        if [ $? -eq 0 ]; then
            log "Wildberries data import completed successfully"
        else
            error "Wildberries data import failed"
            success=false
        fi
    else
        warning "Wildberries data import script not found"
    fi
    
    local end_time=$(date +%s)
    local duration=$((end_time - start_time))
    
    if [ "$success" = true ]; then
        log "Wildberries data refresh completed in ${duration}s"
        return 0
    else
        error "Wildberries data refresh failed after ${duration}s"
        return 1
    fi
}

# Refresh inventory data
refresh_inventory_data() {
    log "Starting inventory data refresh..."
    
    local start_time=$(date +%s)
    local success=true
    
    # Run inventory sync
    if [ -f "scripts/sync_inventory.php" ]; then
        log "Running inventory sync..."
        
        php scripts/sync_inventory.php >> "$DATA_REFRESH_LOG" 2>&1
        
        if [ $? -eq 0 ]; then
            log "Inventory sync completed successfully"
        else
            error "Inventory sync failed"
            success=false
        fi
    else
        warning "Inventory sync script not found"
    fi
    
    # Update warehouse metrics
    if [ -f "scripts/update_warehouse_metrics.php" ] && [ "$success" = true ]; then
        log "Updating warehouse metrics..."
        
        php scripts/update_warehouse_metrics.php >> "$DATA_REFRESH_LOG" 2>&1
        
        if [ $? -eq 0 ]; then
            log "Warehouse metrics updated successfully"
        else
            error "Warehouse metrics update failed"
            success=false
        fi
    fi
    
    local end_time=$(date +%s)
    local duration=$((end_time - start_time))
    
    if [ "$success" = true ]; then
        log "Inventory data refresh completed in ${duration}s"
        return 0
    else
        error "Inventory data refresh failed after ${duration}s"
        return 1
    fi
}

# Refresh analytics data
refresh_analytics_data() {
    log "Starting analytics data refresh..."
    
    local start_time=$(date +%s)
    local success=true
    
    # Update sales analytics
    if [ -f "scripts/update_sales_analytics.php" ]; then
        log "Updating sales analytics..."
        
        php scripts/update_sales_analytics.php >> "$DATA_REFRESH_LOG" 2>&1
        
        if [ $? -eq 0 ]; then
            log "Sales analytics updated successfully"
        else
            error "Sales analytics update failed"
            success=false
        fi
    fi
    
    # Update performance metrics
    if [ -f "scripts/update_performance_metrics.php" ] && [ "$success" = true ]; then
        log "Updating performance metrics..."
        
        php scripts/update_performance_metrics.php >> "$DATA_REFRESH_LOG" 2>&1
        
        if [ $? -eq 0 ]; then
            log "Performance metrics updated successfully"
        else
            error "Performance metrics update failed"
            success=false
        fi
    fi
    
    local end_time=$(date +%s)
    local duration=$((end_time - start_time))
    
    if [ "$success" = true ]; then
        log "Analytics data refresh completed in ${duration}s"
        return 0
    else
        error "Analytics data refresh failed after ${duration}s"
        return 1
    fi
}

# Data quality checks
run_data_quality_checks() {
    if [ "$DATA_QUALITY_CHECKS" != "true" ]; then
        log "Skipping data quality checks (disabled)"
        return 0
    fi
    
    log "Running data quality checks..."
    
    local checks_passed=0
    local checks_failed=0
    
    # Check for duplicate records
    log "Checking for duplicate records..."
    local duplicates=$(php -r "
        require_once 'config/production.php';
        \$pdo = new PDO(
            \"pgsql:host={\$_ENV['DB_HOST']};port={\$_ENV['DB_PORT']};dbname={\$_ENV['DB_NAME']}\",
            \$_ENV['DB_USER'],
            \$_ENV['DB_PASSWORD']
        );
        
        \$tables = ['ozon_products', 'wb_products', 'inventory_items'];
        \$total_duplicates = 0;
        
        foreach (\$tables as \$table) {
            try {
                \$stmt = \$pdo->query(\"SELECT COUNT(*) - COUNT(DISTINCT id) as duplicates FROM \$table\");
                \$duplicates = \$stmt->fetchColumn();
                \$total_duplicates += \$duplicates;
                if (\$duplicates > 0) {
                    echo \"WARNING: \$duplicates duplicate records in \$table\\n\";
                }
            } catch (Exception \$e) {
                echo \"ERROR: Could not check \$table: \" . \$e->getMessage() . \"\\n\";
            }
        }
        
        echo \$total_duplicates;
    ")
    
    if [ "$duplicates" -eq 0 ]; then
        log "✓ No duplicate records found"
        checks_passed=$((checks_passed + 1))
    else
        warning "✗ Found $duplicates duplicate records"
        checks_failed=$((checks_failed + 1))
    fi
    
    # Check data freshness
    log "Checking data freshness..."
    local stale_data=$(php -r "
        require_once 'config/production.php';
        \$pdo = new PDO(
            \"pgsql:host={\$_ENV['DB_HOST']};port={\$_ENV['DB_PORT']};dbname={\$_ENV['DB_NAME']}\",
            \$_ENV['DB_USER'],
            \$_ENV['DB_PASSWORD']
        );
        
        \$checks = [
            'ozon_products' => 'last_updated',
            'wb_products' => 'last_updated',
            'inventory_items' => 'updated_at'
        ];
        
        \$stale_count = 0;
        \$threshold = date('Y-m-d H:i:s', strtotime('-24 hours'));
        
        foreach (\$checks as \$table => \$column) {
            try {
                \$stmt = \$pdo->prepare(\"SELECT MAX(\$column) as last_update FROM \$table\");
                \$stmt->execute();
                \$result = \$stmt->fetch(PDO::FETCH_ASSOC);
                
                if (\$result['last_update'] < \$threshold) {
                    echo \"WARNING: \$table data is stale (last update: {\$result['last_update']})\\n\";
                    \$stale_count++;
                }
            } catch (Exception \$e) {
                echo \"ERROR: Could not check \$table: \" . \$e->getMessage() . \"\\n\";
                \$stale_count++;
            }
        }
        
        echo \$stale_count;
    ")
    
    if [ "$stale_data" -eq 0 ]; then
        log "✓ All data is fresh (updated within 24 hours)"
        checks_passed=$((checks_passed + 1))
    else
        warning "✗ Found $stale_data tables with stale data"
        checks_failed=$((checks_failed + 1))
    fi
    
    # Check record counts
    log "Checking record counts..."
    local record_counts=$(php -r "
        require_once 'config/production.php';
        \$pdo = new PDO(
            \"pgsql:host={\$_ENV['DB_HOST']};port={\$_ENV['DB_PORT']};dbname={\$_ENV['DB_NAME']}\",
            \$_ENV['DB_USER'],
            \$_ENV['DB_PASSWORD']
        );
        
        \$tables = ['ozon_products', 'wb_products', 'inventory_items'];
        \$low_count = 0;
        
        foreach (\$tables as \$table) {
            try {
                \$stmt = \$pdo->query(\"SELECT COUNT(*) as count FROM \$table\");
                \$count = \$stmt->fetchColumn();
                
                if (\$count < 100) {
                    echo \"WARNING: \$table has only \$count records\\n\";
                    \$low_count++;
                } else {
                    echo \"INFO: \$table has \$count records\\n\";
                }
            } catch (Exception \$e) {
                echo \"ERROR: Could not check \$table: \" . \$e->getMessage() . \"\\n\";
                \$low_count++;
            }
        }
        
        echo \$low_count;
    ")
    
    if [ "$record_counts" -eq 0 ]; then
        log "✓ All tables have adequate record counts"
        checks_passed=$((checks_passed + 1))
    else
        warning "✗ Found $record_counts tables with low record counts"
        checks_failed=$((checks_failed + 1))
    fi
    
    log "Data quality checks completed: $checks_passed passed, $checks_failed failed"
    
    if [ $checks_failed -gt 0 ]; then
        return 1
    else
        return 0
    fi
}

# Send notification
send_notification() {
    local status=$1
    local message=$2
    local details=$3
    
    # Email notification
    if [ -n "$EMAIL_REPORTS" ]; then
        local subject="Warehouse Dashboard Data Refresh - $status"
        local body="Data Refresh Status: $status
Message: $message
Time: $(date)
Server: $(hostname)

Details:
$details

Log file: $DATA_REFRESH_LOG
"
        
        echo "$body" | mail -s "$subject" "$EMAIL_REPORTS"
        log "Email notification sent to $EMAIL_REPORTS"
    fi
    
    # Slack notification
    if [ -n "$SLACK_WEBHOOK" ]; then
        local color="good"
        [ "$status" = "FAILED" ] && color="danger"
        [ "$status" = "WARNING" ] && color="warning"
        
        local payload=$(cat <<EOF
{
    "text": "Data Refresh $status",
    "attachments": [
        {
            "color": "$color",
            "fields": [
                {"title": "Status", "value": "$status", "short": true},
                {"title": "Message", "value": "$message", "short": false},
                {"title": "Server", "value": "$(hostname)", "short": true},
                {"title": "Time", "value": "$(date)", "short": true}
            ],
            "footer": "Warehouse Dashboard Data Refresh",
            "ts": $(date +%s)
        }
    ]
}
EOF
        )
        
        curl -X POST -H 'Content-type: application/json' \
             --data "$payload" \
             "$SLACK_WEBHOOK" >/dev/null 2>&1 || warning "Failed to send Slack notification"
    fi
}

# Generate refresh report
generate_refresh_report() {
    local status=$1
    local start_time=$2
    local end_time=$3
    
    local duration=$((end_time - start_time))
    local report_file="${LOG_PATH:-/var/log/warehouse-dashboard}/data_refresh_report_$(date +%Y%m%d_%H%M%S).txt"
    
    cat > "$report_file" << EOF
Warehouse Dashboard Data Refresh Report
======================================
Date: $(date)
Status: $status
Duration: ${duration}s
Server: $(hostname)

Refresh Summary:
$(grep -E "(Starting|completed|failed)" "$DATA_REFRESH_LOG" | tail -20)

Data Quality Checks:
$(grep -E "(✓|✗|WARNING|ERROR)" "$DATA_REFRESH_LOG" | tail -10)

Performance Metrics:
- Total refresh time: ${duration}s
- Log file size: $(du -h "$DATA_REFRESH_LOG" | cut -f1)
- Database connections: $(grep -c "Database connection" "$DATA_REFRESH_LOG")

Recent Errors:
$(grep "ERROR" "$DATA_REFRESH_LOG" | tail -5 || echo "No recent errors")

Full log: $DATA_REFRESH_LOG
EOF
    
    log "Refresh report generated: $report_file"
    echo "$report_file"
}

# Main refresh function
perform_data_refresh() {
    local refresh_type=${1:-"full"}
    
    log "Starting $refresh_type data refresh..."
    local start_time=$(date +%s)
    
    # Acquire lock
    acquire_lock
    
    # Test database connection
    if ! test_database_connection; then
        error "Database connection failed - aborting refresh"
        send_notification "FAILED" "Database connection failed"
        exit 1
    fi
    
    local refresh_success=true
    local refresh_details=""
    
    # Perform refreshes based on type
    case $refresh_type in
        "full")
            log "Performing full data refresh..."
            
            # Run refreshes in parallel if configured
            if [ "$PARALLEL_JOBS" -gt 1 ]; then
                log "Running parallel refresh jobs (max: $PARALLEL_JOBS)"
                
                refresh_ozon_data &
                local ozon_pid=$!
                
                refresh_wb_data &
                local wb_pid=$!
                
                refresh_inventory_data &
                local inventory_pid=$!
                
                # Wait for all jobs to complete
                wait $ozon_pid || refresh_success=false
                wait $wb_pid || refresh_success=false
                wait $inventory_pid || refresh_success=false
                
                # Run analytics after data refreshes
                refresh_analytics_data || refresh_success=false
            else
                log "Running sequential refresh jobs"
                
                refresh_ozon_data || refresh_success=false
                refresh_wb_data || refresh_success=false
                refresh_inventory_data || refresh_success=false
                refresh_analytics_data || refresh_success=false
            fi
            ;;
        "ozon")
            refresh_ozon_data || refresh_success=false
            ;;
        "wb"|"wildberries")
            refresh_wb_data || refresh_success=false
            ;;
        "inventory")
            refresh_inventory_data || refresh_success=false
            ;;
        "analytics")
            refresh_analytics_data || refresh_success=false
            ;;
        *)
            error "Unknown refresh type: $refresh_type"
            exit 1
            ;;
    esac
    
    # Run data quality checks
    if ! run_data_quality_checks; then
        warning "Data quality checks failed"
        refresh_success=false
    fi
    
    local end_time=$(date +%s)
    local total_duration=$((end_time - start_time))
    
    # Generate report
    local report_file=$(generate_refresh_report "$refresh_success" "$start_time" "$end_time")
    refresh_details=$(cat "$report_file")
    
    # Kill timeout process
    kill $TIMEOUT_PID 2>/dev/null || true
    
    # Send notifications
    if [ "$refresh_success" = true ]; then
        log "Data refresh completed successfully in ${total_duration}s"
        send_notification "SUCCESS" "Data refresh completed successfully" "$refresh_details"
    else
        error "Data refresh completed with errors in ${total_duration}s"
        send_notification "FAILED" "Data refresh completed with errors" "$refresh_details"
        exit 1
    fi
}

# Show refresh status
show_refresh_status() {
    log "Data Refresh Status"
    log "==================="
    
    if [ -f "$LOCK_FILE" ]; then
        local lock_pid=$(cat "$LOCK_FILE")
        if kill -0 "$lock_pid" 2>/dev/null; then
            log "Status: RUNNING (PID: $lock_pid)"
        else
            log "Status: STALE LOCK (removing)"
            rm -f "$LOCK_FILE"
        fi
    else
        log "Status: NOT RUNNING"
    fi
    
    # Show recent log entries
    if [ -f "$DATA_REFRESH_LOG" ]; then
        log ""
        log "Recent Log Entries:"
        log "==================="
        tail -20 "$DATA_REFRESH_LOG"
    fi
    
    # Show last refresh times
    log ""
    log "Last Refresh Times:"
    log "==================="
    
    local tables=("ozon_products:last_updated" "wb_products:last_updated" "inventory_items:updated_at")
    
    for table_info in "${tables[@]}"; do
        local table=$(echo "$table_info" | cut -d: -f1)
        local column=$(echo "$table_info" | cut -d: -f2)
        local last_refresh=$(get_last_refresh_timestamp "$table" "$column")
        log "$table: $last_refresh"
    done
}

# Show usage
usage() {
    echo "Usage: $0 [COMMAND] [TYPE]"
    echo ""
    echo "Commands:"
    echo "  refresh [TYPE]    Perform data refresh (default: full)"
    echo "  status            Show refresh status"
    echo "  check             Run data quality checks only"
    echo "  kill              Kill running refresh process"
    echo ""
    echo "Refresh Types:"
    echo "  full              Refresh all data sources (default)"
    echo "  ozon              Refresh Ozon data only"
    echo "  wb                Refresh Wildberries data only"
    echo "  inventory         Refresh inventory data only"
    echo "  analytics         Refresh analytics data only"
    echo ""
    echo "Environment Variables:"
    echo "  MAX_RUNTIME       Maximum runtime in seconds (default: 3600)"
    echo "  PARALLEL_JOBS     Number of parallel jobs (default: 4)"
    echo "  INCREMENTAL_REFRESH  Use incremental refresh (default: true)"
    echo "  DATA_QUALITY_CHECKS  Run data quality checks (default: true)"
    echo ""
    echo "Examples:"
    echo "  $0 refresh full"
    echo "  $0 refresh ozon"
    echo "  $0 status"
    echo "  $0 check"
}

# Main script logic
case "${1:-refresh}" in
    "refresh")
        perform_data_refresh "${2:-full}"
        ;;
    "status")
        show_refresh_status
        ;;
    "check")
        test_database_connection
        run_data_quality_checks
        ;;
    "kill")
        if [ -f "$LOCK_FILE" ]; then
            local lock_pid=$(cat "$LOCK_FILE")
            if kill -0 "$lock_pid" 2>/dev/null; then
                log "Killing refresh process (PID: $lock_pid)"
                kill -TERM "$lock_pid"
                sleep 5
                if kill -0 "$lock_pid" 2>/dev/null; then
                    kill -KILL "$lock_pid"
                fi
                rm -f "$LOCK_FILE"
                log "Refresh process killed"
            else
                log "No running refresh process found"
                rm -f "$LOCK_FILE"
            fi
        else
            log "No lock file found"
        fi
        ;;
    "help"|"-h"|"--help")
        usage
        ;;
    *)
        error "Unknown command: $1"
        usage
        exit 1
        ;;
esac